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
use local_ltixsembed\local\bridge_config;
use local_ltixsembed\local\diagnostics_handover;
use local_ltixsembed\local\settings;

/**
 * Record an embedding problem detected in the browser as an event (diagnostics hand-over, CE-04).
 *
 * Accepts only a fixed vocabulary, a correlation id of a fixed form, and only from a signed-in user who may view the
 * activity (platform side) or from a signed-in user on a site whose tool side is enabled (tool side). Reports are
 * deduplicated and rate-limited per session. Nothing that the browser sends is stored except the validated code and
 * correlation id.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_state extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'role' => new external_value(PARAM_ALPHA, 'consumer or provider'),
            'cmid' => new external_value(PARAM_INT, 'Course module id of the LTI activity (platform side), 0 on the tool side'),
            'code' => new external_value(PARAM_ALPHANUMEXT, 'Detected state or event code'),
            'correlationid' => new external_value(PARAM_ALPHANUMEXT, 'Correlation id of the launch attempt (32 hex)'),
            'peer' => new external_value(PARAM_ALPHA, 'bridge, silent or none'),
        ]);
    }

    /**
     * Record the report.
     *
     * @param string $role Role of the reporting side.
     * @param int $cmid Course module id (platform side).
     * @param string $code Code.
     * @param string $correlationid Correlation id.
     * @param string $peer What the reporting side knows about its peer.
     * @return array logged (bool) and reason ('' or why it was not logged).
     */
    public static function execute(string $role, int $cmid, string $code, string $correlationid, string $peer): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'role' => $role, 'cmid' => $cmid, 'code' => $code, 'correlationid' => $correlationid, 'peer' => $peer,
        ]);
        $role = $params['role'];
        if (!diagnostics_handover::is_valid_code($role, $params['code'])) {
            throw new \invalid_parameter_exception('Unknown role or code');
        }
        if (diagnostics_handover::clean_correlation_id($params['correlationid']) === '') {
            throw new \invalid_parameter_exception('Malformed correlation id');
        }
        if ($role === bridge_config::ROLE_CONSUMER) {
            [, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'lti');
            $context = \context_module::instance($cm->id);
            self::validate_context($context);
            require_capability('mod/lti:view', $context);
            if (!settings::consumer_enabled()) {
                return ['logged' => false, 'reason' => 'disabled'];
            }
        } else {
            $context = \context_system::instance();
            self::validate_context($context);
            if (!isloggedin() || isguestuser()) {
                throw new \require_login_exception('Reports need a signed-in user');
            }
            if (!settings::provider_enabled()) {
                return ['logged' => false, 'reason' => 'disabled'];
            }
        }

        $signature = implode('|', [$role, (int) $params['cmid'], $params['code'], $params['correlationid']]);
        $refused = diagnostics_handover::admit($signature);
        if ($refused !== '') {
            return ['logged' => false, 'reason' => $refused];
        }
        \local_ltixsembed\event\embed_state_reported::create([
            'context' => $context,
            'other' => diagnostics_handover::other($params['code'], $params['correlationid'], $role, $params['peer']),
        ])->trigger();
        return ['logged' => true, 'reason' => ''];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'logged' => new external_value(PARAM_BOOL, 'Whether an event was triggered'),
            'reason' => new external_value(PARAM_ALPHA, 'Why nothing was logged: disabled, duplicate, ratelimited'),
        ]);
    }
}
