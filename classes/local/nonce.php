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
 * Stateless, signed, single-use tokens.
 *
 * A token proves that this site issued it, to this user, for this purpose and context, and that it
 * has not expired. The proof is an HMAC, so nothing has to be stored to verify it. Replay protection
 * stores only a hash of the token (with its expiry) in a cache once it has been consumed.
 *
 * Token layout: "v1.<random>.<expiry>.<mac>" - all URL-safe characters. The user id and the context
 * are part of the MAC input but never of the token, so a token leaks neither.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class nonce {
    /** @var string Purpose of the tokens that authorise the top-level window fallback. */
    public const PURPOSE_FALLBACK = 'fallback';

    /** @var string Pattern every well-formed token matches. */
    private const PATTERN = '/^v1\.[A-Za-z0-9_-]{22}\.[0-9]{10}\.[A-Za-z0-9_-]{43}$/';

    /** @var \core\lock\lock_factory|null Replacement lock factory, only ever set by unit tests. */
    private static $testlockfactory = null;

    /**
     * Replace the lock factory (unit tests only).
     *
     * Needed because the database locks of some drivers (PostgreSQL advisory locks, MariaDB GET_LOCK) are
     * re-entrant within one connection, so lock contention cannot be produced inside one test process.
     *
     * @param \core\lock\lock_factory|null $factory The replacement, or null to restore the default.
     * @throws \coding_exception Outside of unit tests.
     */
    public static function phpunit_use_lock_factory(?\core\lock\lock_factory $factory): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('phpunit_use_lock_factory() is only available in unit tests.');
        }
        self::$testlockfactory = $factory;
    }

    /**
     * Issue a token.
     *
     * @param string $purpose What the token authorises (a PURPOSE_* constant).
     * @param string $context The object it applies to, for example "cm:42".
     * @param int $userid The user it is bound to.
     * @param int|null $lifetime Lifetime in seconds; null uses the setting.
     * @param int|null $now Current time (for tests).
     * @return string The token.
     */
    public static function issue(string $purpose, string $context, int $userid, ?int $lifetime = null, ?int $now = null): string {
        $now = $now ?? time();
        $lifetime = $lifetime ?? settings::nonce_lifetime();
        $expiry = str_pad((string) ($now + $lifetime), 10, '0', STR_PAD_LEFT);
        $random = self::b64url(random_bytes(16));
        return 'v1.' . $random . '.' . $expiry . '.' . self::mac($purpose, $context, $userid, $random, $expiry);
    }

    /**
     * Check a token without consuming it.
     *
     * @param string $token The token to check.
     * @param string $purpose Expected purpose.
     * @param string $context Expected context.
     * @param int $userid Expected user.
     * @param int|null $now Current time (for tests).
     * @return bool True when authentic, bound to these values and not expired.
     */
    public static function verify(string $token, string $purpose, string $context, int $userid, ?int $now = null): bool {
        $parsed = self::parse($token);
        if ($parsed === null) {
            return false;
        }
        [$random, $expiry, $mac] = $parsed;
        if ((int) $expiry < ($now ?? time())) {
            return false;
        }
        return hash_equals(self::mac($purpose, $context, $userid, $random, $expiry), $mac);
    }

    /**
     * Verify a token and mark it as used. A second call with the same token fails.
     *
     * Fails closed: if the lock cannot be obtained the token is not accepted.
     *
     * @param string $token The token.
     * @param string $purpose Expected purpose.
     * @param string $context Expected context.
     * @param int $userid Expected user.
     * @param int|null $now Current time (for tests).
     * @return bool True exactly once per valid token.
     */
    public static function consume(string $token, string $purpose, string $context, int $userid, ?int $now = null): bool {
        if (!self::verify($token, $purpose, $context, $userid, $now)) {
            return false;
        }
        $id = hash('sha256', $token);
        $factory = self::$testlockfactory ?? \core\lock\lock_config::get_lock_factory('local_ltixsembed_nonce');
        $lock = $factory->get_lock($id, 5);
        if (!$lock) {
            return false;
        }
        try {
            $cache = \cache::make('local_ltixsembed', 'consumed');
            if ($cache->has($id)) {
                return false;
            }
            $cache->set($id, (int) self::parse($token)[1]);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Split a token into its parts.
     *
     * @param string $token The token.
     * @return string[]|null [random, expiry, mac] or null when malformed.
     */
    private static function parse(string $token): ?array {
        if (!preg_match(self::PATTERN, $token)) {
            return null;
        }
        $parts = explode('.', $token);
        return [$parts[1], $parts[2], $parts[3]];
    }

    /**
     * Compute the MAC.
     *
     * @param string $purpose Purpose.
     * @param string $context Context.
     * @param int $userid User id.
     * @param string $random Random part of the token.
     * @param string $expiry Zero-padded expiry.
     * @return string URL-safe MAC.
     */
    private static function mac(string $purpose, string $context, int $userid, string $random, string $expiry): string {
        $data = implode('|', ['lxs1', $purpose, $context, $userid, $random, $expiry]);
        return self::b64url(hash_hmac('sha256', $data, self::key(), true));
    }

    /**
     * The signing key, derived from the site's secret key file.
     *
     * The key lives in dataroot (created by core on demand) and never in the database. When no key
     * file can be used the site identifier and password salt serve as a weaker but still secret
     * fallback.
     *
     * @return string Binary key material.
     */
    private static function key(): string {
        global $CFG;
        $material = '';
        try {
            if (!\core\encryption::key_exists()) {
                \core\encryption::create_key();
            }
            $material = (string) @file_get_contents(\core\encryption::get_key_file());
        } catch (\Throwable $e) {
            $material = '';
        }
        if ($material === '') {
            $material = get_site_identifier() . '|' . ($CFG->passwordsaltmain ?? '');
        }
        return hash_hmac('sha256', 'local_ltixsembed|nonce', $material, true);
    }

    /**
     * URL-safe base64 without padding.
     *
     * @param string $binary Raw bytes.
     * @return string
     */
    private static function b64url(string $binary): string {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
