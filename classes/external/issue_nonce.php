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

namespace local_ltixsembed\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_ltixsembed\local\fallback;
use local_ltixsembed\local\nonce;
use local_ltixsembed\local\settings;

/**
 * Issue a fresh one-time token for the top-level window fallback of one activity.
 *
 * The page calls this right before it submits the fallback form, so the token cannot have expired
 * while the learner was reading the notice.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issue_nonce extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the LTI activity'),
        ]);
    }

    /**
     * Issue the token.
     *
     * @param int $cmid Course module id.
     * @return array The token and its expiry.
     */
    public static function execute(int $cmid): array {
        global $USER;
        ['cmid' => $cmid] = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [, $cm] = get_course_and_cm_from_cmid($cmid, 'lti');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/lti:view', $context);

        $lifetime = settings::nonce_lifetime();
        return [
            'nonce' => nonce::issue(nonce::PURPOSE_FALLBACK, fallback::context_for($cm->id), (int) $USER->id, $lifetime),
            'expires' => time() + $lifetime,
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'nonce' => new external_value(PARAM_RAW, 'One-time token'),
            'expires' => new external_value(PARAM_INT, 'Unix time at which the token expires'),
        ]);
    }
}
