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
 * Web service functions of local_ltixsembed.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_ltixsembed_issue_nonce' => [
        'classname' => \local_ltixsembed\external\issue_nonce::class,
        'description' => 'Issue a short-lived one-time token that authorises the top-level window fallback of one activity.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_ltixsembed_report_state' => [
        'classname' => \local_ltixsembed\external\report_state::class,
        'description' => 'Record an embedding problem detected in the browser as an event for the diagnostics tool.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
