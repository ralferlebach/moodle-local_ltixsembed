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
 * Cache definitions of local_ltixsembed.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Markers of already consumed one-time tokens (replay protection). A marker holds only a hash of
    // the token and its expiry - no user id, no course id. The ttl must exceed the longest token
    // lifetime the settings page allows (3600 seconds).
    'consumed' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'staticacceleration' => false,
        'ttl' => 7200,
    ],
    // Rate limit of the diagnostics reports sent by the browser (classes/local/diagnostics_handover.php): per session,
    // hashes of recent report signatures and their time. Session data only; nothing outlives the session.
    'reports' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'simpledata' => true,
    ],
];
