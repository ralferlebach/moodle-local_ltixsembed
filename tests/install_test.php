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
 * Fresh installation of release 0.3.0: setting defaults and consistent release information.
 *
 * The upgrade from 0.2.0 is tested in upgrade_test.php.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\settings
 */
final class install_test extends \advanced_testcase {
    /**
     * A fresh install has both sides on, storage access recovery on and the safe peer-bridge default "auto".
     */
    public function test_fresh_install_defaults(): void {
        $this->assertSame('1', get_config('local_ltixsembed', 'storageaccess'));
        $this->assertSame('1', get_config('local_ltixsembed', 'consumerenabled'));
        $this->assertSame('1', get_config('local_ltixsembed', 'providerenabled'));
        $this->assertSame('auto', get_config('local_ltixsembed', 'peerbridge'));
        $this->assertTrue(local\settings::storage_access_recovery());
        $this->assertSame(local\settings::PEER_AUTO, local\settings::peer_bridge());
        $this->assertSame(local\settings::MODE_BOTH, local\settings::site_mode());
    }

    /**
     * The declared release and version (negative control for version drift) and the change log entry.
     */
    public function test_version_file_declares_release_0_3_0(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $this->assertSame('0.3.0', $plugin->release);
        $this->assertSame(2026102700, $plugin->version);
        $this->assertSame('local_ltixsembed', $plugin->component);
        $this->assertSame(2024100700, $plugin->requires);
        $this->assertSame([405, 503], $plugin->supported);
        $this->assertSame([], $plugin->dependencies, 'no hard dependency on another family plugin');
        $changelog = file_get_contents(__DIR__ . '/../docs/CHANGELOG.md');
        $this->assertStringContainsString('## 0.3.0', $changelog);
        $this->assertStringContainsString('no upgrade path from 0.1.0 alpha', $changelog);
    }
}
