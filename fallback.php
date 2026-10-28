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
 * Top-level window fallback: re-initiates the trusted LTI launch of one activity.
 *
 * Accepts a POST form only. The target of the redirect is fixed to mod_lti's own launch script; no URL,
 * token or session id is read from or written to the query string.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$PAGE->set_url(new moodle_url('/local/ltixsembed/fallback.php'));
$PAGE->set_context(context_system::instance());

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // A plain GET (reload, back button, bookmark) is refused with a normal page instead of an exception, so that
    // it never shows a developer error page. Nothing is launched; the only link leads back to the activity page,
    // which does its own access checks.
    http_response_code(405);
    $cmid = optional_param('cmid', 0, PARAM_INT);
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(get_string('pluginname', 'local_ltixsembed'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('error_postrequired', 'local_ltixsembed'), \core\output\notification::NOTIFY_WARNING);
    if ($cmid > 0) {
        echo $OUTPUT->single_button(
            new moodle_url('/mod/lti/view.php', ['id' => $cmid]),
            get_string('fallback_backtoactivity', 'local_ltixsembed'),
            'get'
        );
    }
    echo $OUTPUT->footer();
    die();
}
require_sesskey();

$cmid = required_param('cmid', PARAM_INT);
$token = required_param('nonce', PARAM_RAW_TRIMMED);
$reason = optional_param('reason', '', PARAM_ALPHANUMEXT);
$correlationid = optional_param('cid', '', PARAM_ALPHANUMEXT);
$peer = optional_param('peer', 'none', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'lti');
require_login($course, false, $cm);

redirect(\local_ltixsembed\local\fallback::prepare($cmid, $token, $reason, $correlationid, $peer));
