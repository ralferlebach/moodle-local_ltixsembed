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
 * Browser capability test page.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_ltixsembed_diagnostics');
require_capability('local/ltixsembed:viewdiagnostics', context_system::instance());

$features = ['framed', 'cookie', 'cookie_partitioned', 'localstorage', 'hassa', 'requestsa', 'platformstorage', 'session'];
$rows = [];
foreach ($features as $feature) {
    $rows[] = [
        'key' => $feature,
        'label' => get_string('feat_' . $feature, 'local_ltixsembed'),
        'value' => get_string('value_pending', 'local_ltixsembed'),
    ];
}

$PAGE->requires->js_call_amd('local_ltixsembed/diagnostics', 'init', [[
    'allowedorigins' => \local_ltixsembed\local\settings::allowed_origins(),
]]);

echo $OUTPUT->header();
$mode = \local_ltixsembed\local\settings::site_mode();
echo $OUTPUT->render_from_template('local_ltixsembed/diagnostics', [
    'mode' => $mode,
    'modelabel' => get_string('mode_' . $mode, 'local_ltixsembed'),
    'rows' => $rows,
    'auditurl' => (new moodle_url('/local/ltixsembed/audit.php'))->out(false),
]);
echo $OUTPUT->footer();
