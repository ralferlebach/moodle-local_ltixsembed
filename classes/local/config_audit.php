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
 * Read-only audit of the settings and response headers that decide whether a site can be embedded.
 *
 * The audit reports; it never changes a setting, never asks anyone to relax browser protection and
 * never recommends a blanket SameSite=None. Collecting facts and judging them are separate steps so
 * that the judgement can be tested with plain arrays.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_audit {
    /** @var string Finding level: everything fine. */
    public const OK = 'ok';

    /** @var string Finding level: informational. */
    public const INFO = 'info';

    /** @var string Finding level: embedding is likely to be impaired. */
    public const WARNING = 'warning';

    /** @var string Finding level: contradictory or insecure configuration. */
    public const ERROR = 'error';

    /**
     * Collect the facts.
     *
     * @param callable|null $fetch Returns the result of header_fetcher::fetch_own_headers(); null uses the real fetcher.
     * @return array The facts, all scalars or lists of scalars.
     */
    public static function collect(?callable $fetch = null): array {
        global $CFG;
        require_once($CFG->libdir . '/sessionlib.php');

        $facts = [
            'wwwroot_https' => strpos((string) $CFG->wwwroot, 'https://') === 0,
            'allowframembedding' => !empty($CFG->allowframembedding),
            'cookiesecure_setting' => !empty($CFG->cookiesecure),
            'cookiesecure_effective' => (bool) is_moodle_cookie_secure(),
            'cookiehttponly' => !empty($CFG->cookiehttponly),
            'authlti_enabled' => is_enabled_auth('lti'),
            'enrollti_enabled' => array_key_exists('lti', enrol_get_plugins(true)),
            'core_cookie_helper' => class_exists(\core\session\utility\cookie_helper::class)
                && method_exists(\core\session\utility\cookie_helper::class, 'add_attributes_to_cookie_response_header'),
            'authlti_partitioning' => class_exists(\auth_lti\local\ltiadvantage\utility\cookie_helper::class)
                && defined(\auth_lti\local\ltiadvantage\utility\cookie_helper::class . '::COOKIE_METHOD_EXPLICIT_PARTITIONING'),
            'origins_configured' => count(settings::allowed_origins()),
            'consumer_enabled' => settings::consumer_enabled(),
            'provider_enabled' => settings::provider_enabled(),
            'fetch_ok' => false,
            'fetch_error' => '',
            'fetch_status' => 0,
            'xframeoptions' => null,
            'frameancestors' => null,
            'sessioncookie' => null,
        ];

        $fetched = $fetch ? $fetch() : header_fetcher::fetch_own_headers();
        $facts['fetch_ok'] = !empty($fetched['ok']);
        $facts['fetch_error'] = (string) ($fetched['error'] ?? '');
        $facts['fetch_status'] = (int) ($fetched['status'] ?? 0);
        if ($facts['fetch_ok']) {
            $parsed = self::parse_raw_headers($fetched['lines']);
            $facts['xframeoptions'] = isset($parsed['headers']['x-frame-options'])
                ? strtolower(trim(end($parsed['headers']['x-frame-options']))) : null;
            $facts['frameancestors'] = self::frame_ancestors($parsed['headers']['content-security-policy'] ?? []);
            $facts['sessioncookie'] = null;
            $name = 'MoodleSession' . ($CFG->sessioncookie ?? '');
            foreach ($parsed['headers']['set-cookie'] ?? [] as $line) {
                $cookie = self::parse_set_cookie($line);
                if ($cookie['name'] === $name) {
                    $facts['sessioncookie'] = $cookie;
                }
            }
        }
        return $facts;
    }

    /**
     * Split raw header lines into a status and a lower-cased header map.
     *
     * When the lines of several responses are present (a redirect chain), only the last one counts.
     *
     * @param string[] $lines Raw header lines, with or without trailing line breaks.
     * @return array{status: int, headers: array<string, string[]>}
     */
    public static function parse_raw_headers(array $lines): array {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $headers[strtolower(trim(substr($line, 0, $pos)))][] = trim(substr($line, $pos + 1));
        }
        return ['status' => $status, 'headers' => $headers];
    }

    /**
     * Parse one Set-Cookie header value.
     *
     * @param string $line The header value, e.g. "MoodleSession=abc; path=/; secure; SameSite=Lax".
     * @return array{name: string, secure: bool, httponly: bool, partitioned: bool, samesite: string} The value itself
     *               is deliberately not kept.
     */
    public static function parse_set_cookie(string $line): array {
        $segments = array_map('trim', explode(';', $line));
        $first = array_shift($segments);
        $name = trim(explode('=', $first, 2)[0]);
        $result = ['name' => $name, 'secure' => false, 'httponly' => false, 'partitioned' => false, 'samesite' => ''];
        foreach ($segments as $segment) {
            $pair = array_map('trim', explode('=', $segment, 2));
            switch (strtolower($pair[0])) {
                case 'secure':
                    $result['secure'] = true;
                    break;
                case 'httponly':
                    $result['httponly'] = true;
                    break;
                case 'partitioned':
                    $result['partitioned'] = true;
                    break;
                case 'samesite':
                    $result['samesite'] = strtolower($pair[1] ?? '');
                    break;
            }
        }
        return $result;
    }

    /**
     * Extract the frame-ancestors source list from Content-Security-Policy header values.
     *
     * @param string[] $values All Content-Security-Policy header values of the response.
     * @return string[]|null The source expressions of the first frame-ancestors directive, or null if there is none.
     */
    public static function frame_ancestors(array $values): ?array {
        foreach ($values as $value) {
            foreach (explode(';', $value) as $directive) {
                $tokens = preg_split('/\s+/', trim($directive));
                if ($tokens && strtolower($tokens[0]) === 'frame-ancestors') {
                    return array_values(array_filter(array_slice($tokens, 1), static fn($t) => $t !== ''));
                }
            }
        }
        return null;
    }

    /**
     * Judge the facts.
     *
     * @param array $facts Result of collect().
     * @param string[] $origins Configured peer origins, used to check CSP frame-ancestors coverage.
     * @return array[] Findings: ['id' => string, 'level' => string, 'a' => mixed] ('a' is the string parameter).
     */
    public static function evaluate(array $facts, array $origins = []): array {
        $f = [];
        $add = static function (string $id, string $level, $a = null) use (&$f): void {
            $f[] = ['id' => $id, 'level' => $level, 'a' => $a];
        };

        $add($facts['wwwroot_https'] ? 'https_ok' : 'https_missing', $facts['wwwroot_https'] ? self::OK : self::WARNING);
        if ($facts['wwwroot_https']) {
            $add(
                $facts['cookiesecure_setting'] ? 'cookiesecure_ok' : 'cookiesecure_off',
                $facts['cookiesecure_setting'] ? self::OK : self::WARNING
            );
        }
        $add($facts['cookiehttponly'] ? 'httponly_ok' : 'httponly_off', $facts['cookiehttponly'] ? self::OK : self::WARNING);

        if ($facts['allowframembedding']) {
            $add('framing_allowed', self::INFO);
        } else {
            $add('framing_blocked', $facts['provider_enabled'] ? self::WARNING : self::INFO);
        }
        $add(
            $facts['authlti_partitioning'] && $facts['core_cookie_helper'] ? 'chips_core_ok' : 'chips_core_missing',
            $facts['authlti_partitioning'] && $facts['core_cookie_helper'] ? self::OK : self::WARNING
        );
        if ($facts['provider_enabled'] && !$facts['authlti_enabled']) {
            $add('authlti_disabled', self::INFO);
        }
        if (!$facts['origins_configured']) {
            $add('origins_none', self::WARNING);
        }

        if (!$facts['fetch_ok']) {
            if ($facts['fetch_error'] === 'blocked') {
                // The site's own address is on the cURL block list: say so, do not suggest relaxing it.
                $add('fetch_blocked', self::INFO);
            } else {
                $add('fetch_failed', self::INFO, $facts['fetch_error'] ?: 'status_' . $facts['fetch_status']);
            }
            return $f;
        }

        $xfo = $facts['xframeoptions'];
        if ($xfo !== null && $facts['allowframembedding']) {
            $add('xfo_conflict', self::ERROR, $xfo);
        } else if ($xfo !== null) {
            $add('xfo_present', self::INFO, $xfo);
        } else {
            $add('xfo_absent', self::OK);
        }

        $ancestors = $facts['frameancestors'];
        if ($ancestors === null) {
            $add('csp_none', self::OK);
        } else {
            $lower = array_map('strtolower', $ancestors);
            if (in_array("'none'", $lower, true)) {
                $add('csp_none_blocks', self::WARNING);
            } else if (in_array('*', $lower, true)) {
                $add('csp_wildcard', self::WARNING);
            } else {
                $missing = array_values(array_diff($origins, $ancestors));
                if ($origins && $missing) {
                    $add('csp_partial', self::WARNING, implode(' ', $missing));
                } else {
                    $add('csp_ok', self::OK, implode(' ', $ancestors));
                }
            }
        }

        $cookie = $facts['sessioncookie'];
        if ($cookie === null) {
            $add('cookie_not_seen', self::INFO);
        } else if ($cookie['samesite'] === 'none' && !$cookie['secure']) {
            $add('cookie_none_insecure', self::ERROR);
        } else if ($cookie['samesite'] === 'none') {
            $add('cookie_none_global', self::WARNING, $cookie['partitioned'] ? 'partitioned' : 'unpartitioned');
        } else {
            $add('cookie_lax', self::OK, $cookie['samesite'] ?: 'default');
        }
        if ($cookie !== null && !$cookie['httponly']) {
            $add('cookie_httponly_missing', self::WARNING);
        }
        return $f;
    }

    /**
     * Template context of the audit page (used by audit.php; separate so that it can be tested).
     *
     * @param array $facts Result of collect().
     * @param string[] $origins Configured peer origins.
     * @return array{findings: array, facts: array}
     */
    public static function page_context(array $facts, array $origins): array {
        $findings = [];
        foreach (self::evaluate($facts, $origins) as $finding) {
            $findings[] = [
                'id' => $finding['id'],
                'level' => $finding['level'],
                'levellabel' => get_string('audit_level_' . $finding['level'], 'local_ltixsembed'),
                'is' . $finding['level'] => true,
                'text' => get_string('audit_' . $finding['id'], 'local_ltixsembed', $finding['a']),
            ];
        }
        $yes = get_string('value_yes', 'local_ltixsembed');
        $no = get_string('value_no', 'local_ltixsembed');
        $factrows = [];
        foreach (
            ['wwwroot_https', 'allowframembedding', 'cookiesecure_setting', 'cookiesecure_effective', 'cookiehttponly',
                'authlti_enabled', 'enrollti_enabled', 'core_cookie_helper', 'authlti_partitioning'] as $key
        ) {
            $factrows[] = ['name' => $key, 'value' => $facts[$key] ? $yes : $no];
        }
        $factrows[] = ['name' => 'origins_configured', 'value' => (string) $facts['origins_configured']];
        $factrows[] = ['name' => 'x-frame-options', 'value' => s($facts['xframeoptions'] ?? '-')];
        $ancestors = $facts['frameancestors'] === null ? '-' : implode(' ', $facts['frameancestors']);
        $factrows[] = ['name' => 'csp frame-ancestors', 'value' => s($ancestors)];
        return ['findings' => $findings, 'facts' => $factrows];
    }
}
