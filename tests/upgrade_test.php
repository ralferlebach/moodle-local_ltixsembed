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

namespace local_ltixsembed;

/**
 * Upgrade step 2026102700 (0.2.0 -> 0.3.0), run against settings in the state 0.2.0 left them.
 *
 * 0.2.0 stored no "peerbridge" and could leave the role switches unset (read as "on"). The step must write the role
 * switches explicitly without changing the effective mode, add "peerbridge" = auto, keep every value an administrator
 * chose, and be idempotent.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_local_ltixsembed_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Load the upgrade function.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once(__DIR__ . '/../db/upgrade.php');
    }

    /**
     * Put the settings into the state a 0.2.0 site may have: no peerbridge, role switches unset, other values chosen.
     *
     * @param array $roles Role switches to store (name => value); missing ones stay unset.
     */
    private function simulate_020(array $roles = []): void {
        foreach (['peerbridge', 'consumerenabled', 'providerenabled'] as $name) {
            unset_config($name, 'local_ltixsembed');
        }
        foreach ($roles as $name => $value) {
            set_config($name, $value, 'local_ltixsembed');
        }
        set_config('allowedorigins', 'https://tool.example.org', 'local_ltixsembed');
        set_config('fallbackmode', 'auto', 'local_ltixsembed');
        set_config('storageaccess', 0, 'local_ltixsembed');
        set_config('version', 2026102502, 'local_ltixsembed');
    }

    /**
     * From 0.2.0 with unset switches: switches written as "on" (same effective mode), peerbridge = auto, rest untouched.
     */
    public function test_upgrade_from_020_with_unset_switches(): void {
        $this->simulate_020();
        $this->assertSame(local\settings::MODE_BOTH, local\settings::site_mode(), '0.2.0 read unset switches as on');

        $this->assertTrue(xmldb_local_ltixsembed_upgrade(2026102502));

        $this->assertSame('1', get_config('local_ltixsembed', 'consumerenabled'));
        $this->assertSame('1', get_config('local_ltixsembed', 'providerenabled'));
        $this->assertSame('auto', get_config('local_ltixsembed', 'peerbridge'));
        $this->assertSame(local\settings::MODE_BOTH, local\settings::site_mode());
        $this->assertSame('https://tool.example.org', get_config('local_ltixsembed', 'allowedorigins'));
        $this->assertSame('auto', get_config('local_ltixsembed', 'fallbackmode'));
        $this->assertSame('0', get_config('local_ltixsembed', 'storageaccess'));
        $this->assertSame('2026102700', (string) get_config('local_ltixsembed', 'version'), 'savepoint reached');
    }

    /**
     * Choices of the administrator survive: a provider-only site stays provider-only, "required" stays "required".
     */
    public function test_upgrade_keeps_chosen_values(): void {
        $this->simulate_020(['consumerenabled' => 0, 'providerenabled' => 1]);
        set_config('peerbridge', 'required', 'local_ltixsembed');

        $this->assertTrue(xmldb_local_ltixsembed_upgrade(2026102502));

        $this->assertSame('0', get_config('local_ltixsembed', 'consumerenabled'));
        $this->assertSame('1', get_config('local_ltixsembed', 'providerenabled'));
        $this->assertSame('required', get_config('local_ltixsembed', 'peerbridge'));
        $this->assertSame(local\settings::MODE_PROVIDER, local\settings::site_mode());
    }

    /**
     * An inactive 0.2.0 site stays inactive.
     */
    public function test_upgrade_keeps_an_inactive_site_inactive(): void {
        $this->simulate_020(['consumerenabled' => 0, 'providerenabled' => 0]);
        $this->assertTrue(xmldb_local_ltixsembed_upgrade(2026102502));
        $this->assertSame(local\settings::MODE_INACTIVE, local\settings::site_mode());
    }

    /**
     * Running the step again (an upgrade interrupted before its savepoint) changes nothing; a site already at 0.3.0 is
     * not touched.
     */
    public function test_upgrade_is_idempotent_and_skips_current_sites(): void {
        $this->simulate_020();
        $this->assertTrue(xmldb_local_ltixsembed_upgrade(2026102502));
        set_config('peerbridge', 'required', 'local_ltixsembed');
        set_config('version', 2026102502, 'local_ltixsembed');
        $this->assertTrue(xmldb_local_ltixsembed_upgrade(2026102502));
        $this->assertSame('required', get_config('local_ltixsembed', 'peerbridge'));

        unset_config('peerbridge', 'local_ltixsembed');
        $this->assertTrue(xmldb_local_ltixsembed_upgrade(2026102700));
        $this->assertFalse(get_config('local_ltixsembed', 'peerbridge'), 'no step runs for the current version');
    }
}
