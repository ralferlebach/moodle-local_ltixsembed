<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_ltixsembed\local;

/**
 * Fetches the response headers of this site's own login page.
 *
 * The configuration audit needs the headers the web server really emits - after any reverse proxy
 * has had its say - because $CFG settings alone do not tell what a browser sees. The request is
 * made with Moodle's cURL wrapper and its security helper stays ACTIVE: hosts and ports that the
 * administrator blocked are not contacted, and this class never relaxes that. The target is always
 * $CFG->wwwroot; there is no parameter through which a user could name another URL (no SSRF).
 *
 * The request carries no cookies and no credentials, so the answer describes what an anonymous
 * visitor receives and cannot leak the administrator's session.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class header_fetcher {
    /** @var int Redirects followed at most (each hop is checked against the blocklist before it is requested). */
    private const MAX_REDIRECTS = 3;

    /**
     * Fetch the headers.
     *
     * Every URL is checked against Moodle's cURL security (core helper and the helpers plugins add through
     * the curl_security_helper callback) BEFORE it is requested. A blocked URL is therefore never handed to
     * \curl, whose own refusal would trigger the core url_blocked event; that event always calls debugging(),
     * which turns the page into an error page under developer debugging (release gate finding F6).
     * Redirects are followed by this class, not by \curl, so that each hop gets the same pre-check, and only
     * within this site's own origin.
     *
     * @return array{ok: bool, status: int, lines: string[], error: string} The raw header lines of the final
     *               response; "error" is a short code ("blocked", "curl_<errno>", "status_0", "redirect_offsite",
     *               "redirect_loop") when ok is false.
     */
    public static function fetch_own_headers(): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $url = $CFG->wwwroot . '/login/index.php';
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (self::is_blocked($url)) {
                return ['ok' => false, 'status' => 0, 'lines' => [], 'error' => 'blocked'];
            }
            $curl = new \curl(['cache' => false]);
            $curl->head($url, [
                'CURLOPT_TIMEOUT' => 5,
                'CURLOPT_CONNECTTIMEOUT' => 3,
                'CURLOPT_FOLLOWLOCATION' => 0,
                'CURLOPT_USERAGENT' => 'Moodle local_ltixsembed configuration audit',
            ]);
            $info = $curl->get_info();
            $status = (int) ($info['http_code'] ?? 0);
            $errno = (int) $curl->get_errno();
            if ($errno) {
                return ['ok' => false, 'status' => $status, 'lines' => [], 'error' => 'curl_' . $errno];
            }
            if ($status === 0) {
                return ['ok' => false, 'status' => 0, 'lines' => [], 'error' => !empty($curl->error) ? 'blocked' : 'status_0'];
            }
            $lines = array_map('strval', $curl->get_raw_response());
            $next = in_array($status, [301, 302, 303, 307, 308], true) ? self::redirect_target($url, $lines) : null;
            if ($next === null) {
                return ['ok' => true, 'status' => $status, 'lines' => $lines, 'error' => ''];
            }
            if (!self::same_origin($next, $CFG->wwwroot)) {
                return ['ok' => false, 'status' => $status, 'lines' => [], 'error' => 'redirect_offsite'];
            }
            $url = $next;
        }
        return ['ok' => false, 'status' => 0, 'lines' => [], 'error' => 'redirect_loop'];
    }

    /**
     * Whether Moodle's cURL security would refuse a URL - asked without making the request.
     *
     * Mirrors \curl::check_securityhelper_blocklist(): the helpers of plugins first, then core's helper.
     *
     * @param string $url The URL.
     * @return bool
     */
    public static function is_blocked(string $url): bool {
        foreach (get_plugins_with_function('curl_security_helper') as $plugins) {
            foreach ($plugins as $pluginfunction) {
                $helper = $pluginfunction();
                if ($helper instanceof \core\files\curl_security_helper_base && $helper->url_is_blocked($url)) {
                    return true;
                }
            }
        }
        return (new \core\files\curl_security_helper())->url_is_blocked($url);
    }

    /**
     * The absolute target of a redirect response, or null when it has no usable Location header.
     *
     * @param string $base The URL that was requested.
     * @param string[] $lines Raw header lines of the response.
     * @return string|null
     */
    public static function redirect_target(string $base, array $lines): ?string {
        $location = null;
        foreach ($lines as $line) {
            if (stripos(ltrim($line), 'location:') === 0) {
                $location = trim(substr(ltrim($line), 9));
            }
        }
        if ($location === null || $location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';
        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    /**
     * Whether two URLs share scheme, host and port.
     *
     * @param string $a First URL.
     * @param string $b Second URL.
     * @return bool
     */
    public static function same_origin(string $a, string $b): bool {
        $origin = static function (string $url): ?string {
            $p = parse_url($url);
            if (empty($p['scheme']) || empty($p['host'])) {
                return null;
            }
            $scheme = strtolower($p['scheme']);
            $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
            return $scheme . '://' . strtolower($p['host']) . ':' . $port;
        };
        $oa = $origin($a);
        return $oa !== null && $oa === $origin($b);
    }
}
