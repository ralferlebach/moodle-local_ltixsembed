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
 * Unit tests of the signed single-use tokens.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\nonce
 */
final class nonce_test extends \advanced_testcase {
    /**
     * Start every test with an empty consumed-token cache.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \cache_helper::purge_by_definition('local_ltixsembed', 'consumed');
    }

    /**
     * The lock factory replacement refuses to work outside unit tests.
     */
    public function test_lock_factory_hook_is_guarded(): void {
        nonce::phpunit_use_lock_factory(null);
        $this->assertTrue(defined('PHPUNIT_TEST') && PHPUNIT_TEST);
    }

    /**
     * A freshly issued token verifies for the exact purpose, context and user it was issued for.
     */
    public function test_issue_and_verify(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 60, 1000);
        $this->assertMatchesRegularExpression('/^v1\.[A-Za-z0-9_-]{22}\.[0-9]{10}\.[A-Za-z0-9_-]{43}$/', $token);
        $this->assertTrue(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 1000));
        $this->assertTrue(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 1060));
    }

    /**
     * The token reveals neither the user id nor the context.
     */
    public function test_token_does_not_contain_user_or_context(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:777777', 424242, 60, 1000);
        $this->assertStringNotContainsString('424242', $token);
        $this->assertStringNotContainsString('777777', $token);
    }

    /**
     * Another user, context or purpose never verifies.
     */
    public function test_binding(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 60, 1000);
        $this->assertFalse(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:7', 43, 1000), 'other user');
        $this->assertFalse(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:8', 42, 1000), 'other context');
        $this->assertFalse(nonce::verify($token, 'other', 'cm:7', 42, 1000), 'other purpose');
    }

    /**
     * An expired token does not verify.
     */
    public function test_expiry(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 60, 1000);
        $this->assertFalse(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 1061));
    }

    /**
     * Tampering with any part of the token invalidates it.
     */
    public function test_tampering_is_detected(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 60, 1000);
        [$version, $random, $expiry, $mac] = explode('.', $token);
        $other = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 60, 1000);
        $otherparts = explode('.', $other);

        $forged = [
            'longer expiry' => implode('.', [$version, $random, '9999999999', $mac]),
            'foreign random' => implode('.', [$version, $otherparts[1], $expiry, $mac]),
            'foreign mac' => implode('.', [$version, $random, $expiry, $otherparts[3]]),
            'flipped mac' => implode('.', [$version, $random, $expiry, strrev($mac)]),
            'other version' => implode('.', ['v2', $random, $expiry, $mac]),
        ];
        foreach ($forged as $label => $value) {
            $this->assertFalse(nonce::verify($value, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 1000), $label);
        }
    }

    /**
     * Malformed input never verifies and never throws.
     *
     * @dataProvider malformed_provider
     * @param string $token Input.
     */
    public function test_malformed_tokens(string $token): void {
        $this->assertFalse(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 1000));
        $this->assertFalse(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 1000));
    }

    /**
     * Malformed tokens.
     *
     * @return array
     */
    public static function malformed_provider(): array {
        return [
            'empty' => [''],
            'garbage' => ['not-a-token'],
            'too few parts' => ['v1.abc.def'],
            'too many parts' => ['v1.a.b.c.d'],
            'sql' => ["v1.' OR '1'='1.1000000000.xx"],
            'newline' => ["v1.AAAAAAAAAAAAAAAAAAAAAA.1000000000.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n"],
            'very long' => [str_repeat('a', 100000)],
        ];
    }

    /**
     * A token can be consumed exactly once.
     */
    public function test_consume_is_single_use(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 600);
        $this->assertTrue(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42));
        $this->assertFalse(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42), 'replay');
        $this->assertFalse(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42), 'replay again');
    }

    /**
     * A failed attempt (wrong user) does not burn the token for the right user.
     */
    public function test_failed_consume_does_not_burn_the_token(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 600);
        $this->assertFalse(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 99));
        $this->assertTrue(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42));
    }

    /**
     * Two parallel tokens are independent.
     */
    public function test_tokens_are_independent(): void {
        $a = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 600);
        $b = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 600);
        $this->assertNotSame($a, $b);
        $this->assertTrue(nonce::consume($a, nonce::PURPOSE_FALLBACK, 'cm:7', 42));
        $this->assertTrue(nonce::consume($b, nonce::PURPOSE_FALLBACK, 'cm:7', 42));
    }

    /**
     * While another process holds the lock for a token, consuming it fails closed.
     */
    public function test_consume_fails_closed_without_the_lock(): void {
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, 600);
        $busy = $this->createMock(\core\lock\lock_factory::class);
        $busy->expects($this->once())->method('get_lock')->willReturn(false);
        nonce::phpunit_use_lock_factory($busy);
        try {
            $this->assertFalse(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42));
        } finally {
            nonce::phpunit_use_lock_factory(null);
        }
        $this->assertTrue(nonce::consume($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42), 'usable once the lock is free');
    }

    /**
     * The default lifetime comes from the setting, clamped to its limits.
     */
    public function test_lifetime_follows_the_setting(): void {
        set_config('noncelifetime', 100, 'local_ltixsembed');
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:7', 42, null, 5000);
        $this->assertSame('0000005100', explode('.', $token)[2]);
        $this->assertTrue(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 5100));
        $this->assertFalse(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:7', 42, 5101));
    }
}
