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

/**
 * Settings and admin pages of local_ltixsembed.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category(
        'local_ltixsembed_category',
        new lang_string('pluginname', 'local_ltixsembed')
    ));

    $settings = new admin_settingpage(
        'local_ltixsembed',
        new lang_string('settings_heading', 'local_ltixsembed'),
        'moodle/site:config'
    );

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_ltixsembed/intro',
            '',
            new lang_string('settings_intro', 'local_ltixsembed')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_ltixsembed/consumerenabled',
            new lang_string('setting_consumerenabled', 'local_ltixsembed'),
            new lang_string('setting_consumerenabled_desc', 'local_ltixsembed'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_ltixsembed/providerenabled',
            new lang_string('setting_providerenabled', 'local_ltixsembed'),
            new lang_string('setting_providerenabled_desc', 'local_ltixsembed'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_ltixsembed/providerltipages',
            new lang_string('setting_providerltipages', 'local_ltixsembed'),
            new lang_string('setting_providerltipages_desc', 'local_ltixsembed'),
            0
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_ltixsembed/storageaccess',
            new lang_string('setting_storageaccess', 'local_ltixsembed'),
            new lang_string('setting_storageaccess_desc', 'local_ltixsembed'),
            1
        ));

        $settings->add(new \local_ltixsembed\admin\setting_origins(
            'local_ltixsembed/allowedorigins',
            new lang_string('setting_allowedorigins', 'local_ltixsembed'),
            new lang_string('setting_allowedorigins_desc', 'local_ltixsembed'),
            '',
            PARAM_RAW,
            '60',
            '5'
        ));

        $settings->add(new admin_setting_configtext(
            'local_ltixsembed/noncelifetime',
            new lang_string('setting_noncelifetime', 'local_ltixsembed'),
            new lang_string('setting_noncelifetime_desc', 'local_ltixsembed'),
            \local_ltixsembed\local\settings::NONCE_DEFAULT,
            PARAM_INT,
            6
        ));

        $settings->add(new admin_setting_configtext(
            'local_ltixsembed/handshaketimeout',
            new lang_string('setting_handshaketimeout', 'local_ltixsembed'),
            new lang_string('setting_handshaketimeout_desc', 'local_ltixsembed'),
            8,
            PARAM_INT,
            4
        ));

        $settings->add(new admin_setting_configselect(
            'local_ltixsembed/peerbridge',
            new lang_string('setting_peerbridge', 'local_ltixsembed'),
            new lang_string('setting_peerbridge_desc', 'local_ltixsembed'),
            \local_ltixsembed\local\settings::PEER_AUTO,
            [
                \local_ltixsembed\local\settings::PEER_AUTO => new lang_string('peerbridge_auto', 'local_ltixsembed'),
                \local_ltixsembed\local\settings::PEER_REQUIRED => new lang_string('peerbridge_required', 'local_ltixsembed'),
            ]
        ));

        $settings->add(new admin_setting_configselect(
            'local_ltixsembed/fallbackmode',
            new lang_string('setting_fallbackmode', 'local_ltixsembed'),
            new lang_string('setting_fallbackmode_desc', 'local_ltixsembed'),
            \local_ltixsembed\local\settings::FALLBACK_SUGGEST,
            [
                \local_ltixsembed\local\settings::FALLBACK_SUGGEST => new lang_string(
                    'fallbackmode_suggest',
                    'local_ltixsembed'
                ),
                \local_ltixsembed\local\settings::FALLBACK_AUTO => new lang_string(
                    'fallbackmode_auto',
                    'local_ltixsembed'
                ),
            ]
        ));
    }
    $ADMIN->add('local_ltixsembed_category', $settings);

    $ADMIN->add('local_ltixsembed_category', new admin_externalpage(
        'local_ltixsembed_diagnostics',
        new lang_string('page_diagnostics', 'local_ltixsembed'),
        new moodle_url('/local/ltixsembed/test.php'),
        'local/ltixsembed:viewdiagnostics'
    ));

    $ADMIN->add('local_ltixsembed_category', new admin_externalpage(
        'local_ltixsembed_audit',
        new lang_string('page_audit', 'local_ltixsembed'),
        new moodle_url('/local/ltixsembed/audit.php'),
        'local/ltixsembed:viewdiagnostics'
    ));
}
