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
 * Unit tests of the typed settings access.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\settings
 */
final class settings_test extends \advanced_testcase {
    /**
     * Defaults when nothing is stored: bridge on, user pages off, no origins, suggest-only.
     */
    public function test_defaults(): void {
        $this->resetAfterTest();
        unset_config('consumerenabled', 'local_ltixsembed');
        unset_config('providerenabled', 'local_ltixsembed');
        unset_config('providerltipages', 'local_ltixsembed');
        unset_config('allowedorigins', 'local_ltixsembed');
        unset_config('noncelifetime', 'local_ltixsembed');
        unset_config('handshaketimeout', 'local_ltixsembed');
        unset_config('fallbackmode', 'local_ltixsembed');
        unset_config('storageaccess', 'local_ltixsembed');
        unset_config('peerbridge', 'local_ltixsembed');

        $this->assertTrue(settings::consumer_enabled());
        $this->assertTrue(settings::provider_enabled());
        $this->assertFalse(settings::provider_lti_user_pages());
        $this->assertSame([], settings::allowed_origins());
        $this->assertSame(settings::NONCE_DEFAULT, settings::nonce_lifetime());
        $this->assertSame(8, settings::handshake_timeout());
        $this->assertSame(settings::FALLBACK_SUGGEST, settings::fallback_mode());
        $this->assertTrue(settings::storage_access_recovery());
        $this->assertSame(settings::PEER_AUTO, settings::peer_bridge());
        $this->assertSame(settings::MODE_BOTH, settings::site_mode());
    }

    /**
     * The peer-bridge setting accepts exactly "required"; anything else is the safe default.
     */
    public function test_peer_bridge(): void {
        $this->resetAfterTest();
        set_config('peerbridge', 'required', 'local_ltixsembed');
        $this->assertSame(settings::PEER_REQUIRED, settings::peer_bridge());
        foreach (['auto', 'REQUIRED', '1', '', 'yes'] as $value) {
            set_config('peerbridge', $value, 'local_ltixsembed');
            $this->assertSame(settings::PEER_AUTO, settings::peer_bridge(), $value);
        }
    }

    /**
     * The operating mode follows from the two role switches.
     *
     * @dataProvider mode_provider
     * @param int $consumer consumerenabled.
     * @param int $provider providerenabled.
     * @param string $expected Expected mode.
     */
    public function test_site_mode(int $consumer, int $provider, string $expected): void {
        $this->resetAfterTest();
        set_config('consumerenabled', $consumer, 'local_ltixsembed');
        set_config('providerenabled', $provider, 'local_ltixsembed');
        $this->assertSame($expected, settings::site_mode());
    }

    /**
     * Switch combinations.
     *
     * @return array
     */
    public static function mode_provider(): array {
        return [
            'inactive' => [0, 0, settings::MODE_INACTIVE],
            'consumer-only' => [1, 0, settings::MODE_CONSUMER],
            'provider-only' => [0, 1, settings::MODE_PROVIDER],
            'both' => [1, 1, settings::MODE_BOTH],
        ];
    }

    /**
     * Stored values are honoured, numbers are clamped and unknown choices fall back.
     */
    public function test_values_and_clamping(): void {
        $this->resetAfterTest();
        set_config('consumerenabled', 0, 'local_ltixsembed');
        set_config('providerltipages', 1, 'local_ltixsembed');
        set_config('allowedorigins', "https://a.example.org\ninvalid line\nhttps://b.example.org", 'local_ltixsembed');
        $this->assertFalse(settings::consumer_enabled());
        $this->assertTrue(settings::provider_lti_user_pages());
        $this->assertSame(['https://a.example.org', 'https://b.example.org'], settings::allowed_origins());

        set_config('noncelifetime', 5, 'local_ltixsembed');
        $this->assertSame(settings::NONCE_MIN, settings::nonce_lifetime());
        set_config('noncelifetime', 999999, 'local_ltixsembed');
        $this->assertSame(settings::NONCE_MAX, settings::nonce_lifetime());
        set_config('noncelifetime', -3, 'local_ltixsembed');
        $this->assertSame(settings::NONCE_DEFAULT, settings::nonce_lifetime());

        set_config('handshaketimeout', 1, 'local_ltixsembed');
        $this->assertSame(2, settings::handshake_timeout());
        set_config('handshaketimeout', 500, 'local_ltixsembed');
        $this->assertSame(60, settings::handshake_timeout());

        set_config('storageaccess', 0, 'local_ltixsembed');
        $this->assertFalse(settings::storage_access_recovery());
        set_config('storageaccess', 1, 'local_ltixsembed');
        $this->assertTrue(settings::storage_access_recovery());

        set_config('fallbackmode', 'auto', 'local_ltixsembed');
        $this->assertSame(settings::FALLBACK_AUTO, settings::fallback_mode());
        set_config('fallbackmode', 'redirect-everything', 'local_ltixsembed');
        $this->assertSame(settings::FALLBACK_SUGGEST, settings::fallback_mode());
    }
}
