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
 * CLI seed for the single-site Playwright suite of local_ltixsembed.
 *
 * Creates a course with an LTI activity that launches in an iframe, a student, an outsider (a user who
 * is not enrolled) and prints shell "export" lines for the specs. Disposable test sites only.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

$generator = new testing_data_generator();
$suffix = substr(uniqid('', true), -6);
$password = 'Lxs-Pass!234';

$course = $generator->create_course(['fullname' => 'Embedding course', 'shortname' => 'LXS' . $suffix]);
$student = $generator->create_user(['username' => 'lxsstudent' . $suffix, 'password' => $password]);
$outsider = $generator->create_user(['username' => 'lxsoutsider' . $suffix, 'password' => $password]);
$generator->enrol_user($student->id, $course->id, 'student');
$lti = $generator->create_module('lti', [
    'course' => $course->id,
    'name' => 'Embedded tool',
    'toolurl' => 'https://tool.example.org/launch',
    'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED,
]);
$cm = get_coursemodule_from_instance('lti', $lti->id);

set_config('allowedorigins', 'https://tool.example.org', 'local_ltixsembed');

echo "export LXS_BASE_URL='" . $CFG->wwwroot . "'\n";
echo "export LXS_ADMIN_USER='admin'\n";
echo "export LXS_ADMIN_PASS='Admin!23'\n";
echo "export LXS_STUDENT_USER='" . $student->username . "'\n";
echo "export LXS_STUDENT_PASS='" . $password . "'\n";
echo "export LXS_OUTSIDER_USER='" . $outsider->username . "'\n";
echo "export LXS_OUTSIDER_PASS='" . $password . "'\n";
echo "export LXS_COURSE_ID='" . $course->id . "'\n";
echo "export LXS_CMID='" . $cm->id . "'\n";
