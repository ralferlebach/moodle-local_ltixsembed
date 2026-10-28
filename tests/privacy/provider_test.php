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

namespace local_ltixsembed\privacy;

/**
 * Evidence that the null privacy provider is justified: the plugin stores nothing about a person.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\privacy\provider
 */
final class provider_test extends \advanced_testcase {
    /**
     * The provider is a null provider with an existing reason string.
     */
    public function test_null_provider_with_reason(): void {
        $this->assertTrue(is_subclass_of(provider::class, \core_privacy\local\metadata\null_provider::class));
        $this->assertSame('privacy:metadata', provider::get_reason());
        foreach (['en', 'de'] as $lang) {
            $string = [];
            include(__DIR__ . "/../../lang/{$lang}/local_ltixsembed.php");
            $this->assertArrayHasKey('privacy:metadata', $string);
        }
    }

    /**
     * The plugin owns no table and no per-user preference.
     */
    public function test_no_tables_and_no_preferences(): void {
        global $DB;
        $this->assertFileDoesNotExist(__DIR__ . '/../../db/install.xml');
        $manager = $DB->get_manager();
        $this->assertFalse($manager->table_exists('local_ltixsembed'));
        $this->assertSame([], array_filter($DB->get_tables(false), static fn($t) => strpos($t, 'local_ltixsembed') === 0));
        $this->assertFileDoesNotExist(__DIR__ . '/../../lib.php', 'no user preference callbacks');
    }

    /**
     * Consuming a token leaves only the expiry in the cache: no user id, no context, no token text.
     */
    public function test_consumed_marker_holds_no_personal_data(): void {
        $this->resetAfterTest();
        \cache_helper::purge_by_definition('local_ltixsembed', 'consumed');
        $user = $this->getDataGenerator()->create_user();
        $token = \local_ltixsembed\local\nonce::issue('fallback', 'cm:123', (int) $user->id, 600);
        $this->assertTrue(\local_ltixsembed\local\nonce::consume($token, 'fallback', 'cm:123', (int) $user->id));

        $cache = \cache::make('local_ltixsembed', 'consumed');
        $marker = $cache->get(hash('sha256', $token));
        $this->assertIsInt($marker);
        $this->assertNotEquals((int) $user->id, $marker);
    }

    /**
     * The rate-limit cache of the diagnostics reports is session data and holds only hashes and times.
     */
    public function test_report_rate_limit_holds_no_identifiers(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $definitions = [];
        require(__DIR__ . '/../../db/caches.php');
        $this->assertSame(\cache_store::MODE_SESSION, $definitions['reports']['mode']);
        $signature = 'consumer|5|session_lost|' . str_repeat('a', 32);
        $this->assertSame('', \local_ltixsembed\local\diagnostics_handover::admit($signature));
        $log = \cache::make('local_ltixsembed', 'reports')->get('log');
        $this->assertCount(1, $log);
        foreach ($log as $key => $time) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (string) $key);
            $this->assertIsInt($time);
        }
        $this->assertStringNotContainsString((string) $user->id . '|', json_encode($log));
    }

    /**
     * The settings hold configuration only, never anything about a person.
     */
    public function test_settings_hold_no_personal_data(): void {
        $this->resetAfterTest();
        $names = array_keys((array) get_config('local_ltixsembed'));
        $allowed = ['version', 'consumerenabled', 'providerenabled', 'providerltipages', 'allowedorigins', 'noncelifetime',
            'handshaketimeout', 'fallbackmode', 'storageaccess', 'peerbridge'];
        $this->assertSame([], array_values(array_diff($names, $allowed)));
    }
}
