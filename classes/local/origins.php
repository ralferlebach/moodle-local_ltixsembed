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
 * Parsing, normalising and matching of web origins.
 *
 * An origin is scheme, host and (non-default) port - nothing else. Matching is by exact string
 * comparison of canonical origins. There are no wildcards, no suffix matching and no "same site"
 * shortcuts: those are the classic ways an allow-list is bypassed (evil-example.com,
 * example.com.evil.test, user@host tricks).
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class origins {
    /** @var int Maximum number of origins accepted in the setting. */
    public const MAX_ENTRIES = 50;

    /**
     * Whether plain http origins may be configured.
     *
     * Only on development sites that themselves run on http; a production platform must not trust a
     * non-TLS peer, because SameSite=None cookies require Secure anyway.
     *
     * @return bool
     */
    private static function http_allowed(): bool {
        global $CFG;
        return strpos((string) $CFG->wwwroot, 'http://') === 0;
    }

    /**
     * Turn a candidate string into a canonical origin.
     *
     * @param string $candidate For example "https://tool.example.org" or "HTTPS://Tool.Example.org:443".
     * @return string|null The canonical origin, or null when the candidate is not a bare origin.
     */
    public static function normalize(string $candidate): ?string {
        $candidate = trim($candidate);
        if ($candidate === '' || preg_match('/[\s\x00-\x1f\x7f\\\\*]/', $candidate)) {
            return null;
        }
        $parts = parse_url($candidate);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && self::http_allowed())) {
            return null;
        }
        $host = strtolower($parts['host']);
        $isipv4 = (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        if (!$isipv4 && !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $host)) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }
        $default = $scheme === 'https' ? 443 : 80;
        $origin = $scheme . '://' . $host;
        if ($port !== null && $port !== $default) {
            $origin .= ':' . $port;
        }
        return $origin;
    }

    /**
     * Parse a multi-line setting value.
     *
     * @param string $raw One origin per line; blank lines are ignored.
     * @return array{valid: string[], invalid: string[]} Canonical unique origins and the rejected lines.
     */
    public static function parse(string $raw): array {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $origin = self::normalize($line);
            if ($origin === null) {
                $invalid[] = $line;
            } else if (!in_array($origin, $valid, true)) {
                $valid[] = $origin;
            }
        }
        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /**
     * Validate a multi-line setting value for the admin form.
     *
     * @param string $raw The submitted value.
     * @return true|string True when acceptable, otherwise the error message.
     */
    public static function validate(string $raw) {
        $parsed = self::parse($raw);
        if ($parsed['invalid']) {
            $shown = s(implode(', ', array_slice($parsed['invalid'], 0, 3)));
            return get_string('error_origin_invalid', 'local_ltixsembed', $shown);
        }
        if (count($parsed['valid']) > self::MAX_ENTRIES) {
            return get_string('error_origin_toomany', 'local_ltixsembed', self::MAX_ENTRIES);
        }
        return true;
    }

    /**
     * Extract the canonical origin of a URL.
     *
     * @param string $url A full URL; any path, query or fragment is dropped.
     * @return string|null The origin, or null when the URL has no acceptable scheme and host.
     */
    public static function from_url(string $url): ?string {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return self::normalize($origin);
    }

    /**
     * Exact allow-list match.
     *
     * @param string $origin An origin as reported by the browser (event.origin).
     * @param string[] $allowed Canonical origins.
     * @return bool
     */
    public static function is_allowed(string $origin, array $allowed): bool {
        return in_array($origin, $allowed, true);
    }
}
