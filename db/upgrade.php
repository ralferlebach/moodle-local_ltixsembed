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
 * Upgrade steps for local_ltixsembed.
 *
 * Release 0.2.0 (2026102502) is the first release under this component name (renamed, see docs/CHANGELOG.md); there is no
 * upgrade path from the 0.1.0 alpha of the old component. The plugin has no tables; upgrade steps migrate stored settings.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the local_ltixsembed plugin.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_ltixsembed_upgrade($oldversion) {
    if ($oldversion < 2026102700) {
        // Release 0.3.0 (CE-02): consumer-only, provider-only and cooperative operation.
        // Step 1: the role switches are written explicitly. 0.2.0 read a missing value as "on"; a stored value makes
        // the operating mode of the site visible and keeps it stable whatever later defaults are.
        foreach (['consumerenabled', 'providerenabled'] as $name) {
            if (get_config('local_ltixsembed', $name) === false) {
                set_config($name, 1, 'local_ltixsembed');
            }
        }
        // Step 2: new setting "peerbridge". 0.2.0 treated a silent tool as a blocked frame, which made a platform that
        // embeds a tool WITHOUT this plugin suggest the new window after every successful launch. The safe default
        // "auto" treats silence as normal. Sites that relied on the old behaviour choose "required" (CHANGELOG 0.3.0).
        if (get_config('local_ltixsembed', 'peerbridge') === false) {
            set_config('peerbridge', 'auto', 'local_ltixsembed');
        }
        upgrade_plugin_savepoint(true, 2026102700, 'local', 'ltixsembed');
    }
    return true;
}
