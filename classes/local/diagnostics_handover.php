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
 * Hand-over of embedding outcomes to tool_ltidiagnostics (CE-04), without depending on it.
 *
 * tool_ltidiagnostics reads the events of every component that starts with "local_lti" from the log stores and takes over
 * exactly four keys of "other": correlationid (PARAM_ALPHANUMEXT, at most 64 characters), connectionid (int), version (int)
 * and code (PARAM_ALPHANUMEXT, at most 32 characters). Everything else in "other" is ignored there. This class builds
 * "other" so that it satisfies that contract and carries no personal data, no URL, no token and no browser detail:
 *
 *  - correlationid: 32 hexadecimal characters computed in the browser from the channel nonce of one launch attempt
 *    (SHA-256, so the nonce cannot be recovered); platform and tool compute the same value, which lets the diagnostics
 *    tool put the events of both sites of one attempt side by side;
 *  - code: the detected state or the fallback reason (fixed vocabulary);
 *  - version: the bridge protocol version;
 *  - role and peer: for this plugin's own log description only.
 *
 * Reports from the browser are rate-limited per session (session cache, nothing persistent).
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostics_handover {
    /** @var int Version of the browser bridge protocol (the "lxs" envelope field). */
    public const PROTOCOL_VERSION = 1;

    /** @var string[] Codes the platform side may report. */
    public const CONSUMER_CODES = [
        'storage_unavailable', 'session_lost', 'blocked_frame', 'needs_user_intervention', 'storage_access_retry',
    ];

    /** @var string[] Codes the tool side may report (only when its user is signed in). */
    public const PROVIDER_CODES = [
        'storage_unavailable', 'session_lost', 'needs_user_intervention', 'storage_access_granted', 'storage_access_denied',
    ];

    /** @var string[] What the reporting side knows about its peer. */
    public const PEERS = ['bridge', 'silent', 'none'];

    /** @var int Reports accepted per session within WINDOW seconds. */
    public const MAX_REPORTS = 20;

    /** @var int Rate-limit window in seconds. */
    public const WINDOW = 600;

    /**
     * A correlation id as the browser computes it, or '' when the value does not have that exact form.
     *
     * @param string $value Value from the request.
     * @return string 32 lower-case hexadecimal characters, or ''.
     */
    public static function clean_correlation_id(string $value): string {
        return preg_match('/^[a-f0-9]{32}$/', $value) ? $value : '';
    }

    /**
     * Whether a code may be reported by a role.
     *
     * @param string $role 'consumer' or 'provider'.
     * @param string $code The code.
     * @return bool
     */
    public static function is_valid_code(string $role, string $code): bool {
        if ($role === bridge_config::ROLE_CONSUMER) {
            return in_array($code, self::CONSUMER_CODES, true);
        }
        if ($role === bridge_config::ROLE_PROVIDER) {
            return in_array($code, self::PROVIDER_CODES, true);
        }
        return false;
    }

    /**
     * The "other" data of an event of this plugin.
     *
     * @param string $code Code from a fixed vocabulary (validated by the caller).
     * @param string $correlationid Correlation id (cleaned here).
     * @param string $role Role of the reporting side.
     * @param string $peer What the reporting side knows about its peer.
     * @return array
     */
    public static function other(string $code, string $correlationid, string $role, string $peer): array {
        return [
            'correlationid' => self::clean_correlation_id($correlationid),
            'code' => $code,
            'version' => self::PROTOCOL_VERSION,
            'role' => in_array($role, [bridge_config::ROLE_CONSUMER, bridge_config::ROLE_PROVIDER], true) ? $role : 'other',
            'peer' => in_array($peer, self::PEERS, true) ? $peer : 'none',
        ];
    }

    /**
     * Admit a report: refuse duplicates and more than MAX_REPORTS per WINDOW in this session.
     *
     * @param string $signature Identity of the report (role, activity, code, correlation id).
     * @param int|null $now Current time (tests).
     * @return string '' when admitted, otherwise 'duplicate' or 'ratelimited'.
     */
    public static function admit(string $signature, ?int $now = null): string {
        $now = $now ?? time();
        $cache = \cache::make('local_ltixsembed', 'reports');
        $log = $cache->get('log');
        $log = is_array($log) ? $log : [];
        $log = array_filter($log, function ($time) use ($now) {
            return is_int($time) && $time > $now - self::WINDOW;
        });
        $key = sha1($signature);
        if (isset($log[$key])) {
            return 'duplicate';
        }
        if (count($log) >= self::MAX_REPORTS) {
            $cache->set('log', $log);
            return 'ratelimited';
        }
        $log[$key] = $now;
        $cache->set('log', $log);
        return '';
    }
}
