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
 * Server side of the top-level window fallback.
 *
 * The fallback re-initiates the ordinary, trusted LTI launch of one activity in a new top-level
 * window: it hands back the URL of mod_lti's own launch.php for that activity. Nothing else is
 * accepted from the request - in particular no URL - so the redirect target is fixed by the code
 * and cannot be steered to another host. No session id and no token travels in the URL; the one-time
 * token is checked in the POST body and is not forwarded.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fallback {
    /** @var string[] Reasons the browser side may report. Anything else is stored as "other". */
    public const REASONS = [
        'manual', 'auto', 'storage_unavailable', 'session_lost', 'blocked_frame', 'needs_user_intervention',
    ];

    /**
     * The context string the one-time token is bound to.
     *
     * @param int $cmid Course module id.
     * @return string
     */
    public static function context_for(int $cmid): string {
        return 'cm:' . $cmid;
    }

    /**
     * Reduce a reported reason to the known list.
     *
     * @param string $reason Reason reported by the browser.
     * @return string A member of REASONS, or "other".
     */
    public static function clean_reason(string $reason): string {
        return in_array($reason, self::REASONS, true) ? $reason : 'other';
    }

    /**
     * Validate a fallback request and return where to send the browser.
     *
     * The caller must already have done require_login() and require_sesskey().
     *
     * @param int $cmid Course module id of a mod_lti activity.
     * @param string $token The one-time token from the request.
     * @param string $reason The reason reported by the page.
     * @param string $correlationid Correlation id of the launch attempt (diagnostics hand-over); '' when unknown.
     * @param string $peer What the page knew about the tool's bridge: bridge, silent or none.
     * @return \moodle_url Local URL of mod_lti's launch script.
     * @throws \moodle_exception When the token is not valid.
     * @throws \required_capability_exception When the user may not view the activity.
     */
    public static function prepare(
        int $cmid,
        string $token,
        string $reason,
        string $correlationid = '',
        string $peer = 'none'
    ): \moodle_url {
        global $USER;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'lti');
        $context = \context_module::instance($cm->id);
        require_capability('mod/lti:view', $context);

        if (!nonce::consume($token, nonce::PURPOSE_FALLBACK, self::context_for($cm->id), (int) $USER->id)) {
            throw new \moodle_exception('error_nonce', 'local_ltixsembed');
        }

        \local_ltixsembed\event\fallback_launched::create([
            'context' => $context,
            'objectid' => $cm->id,
            'other' => ['reason' => self::clean_reason($reason)] + diagnostics_handover::other(
                self::clean_reason($reason),
                $correlationid,
                bridge_config::ROLE_CONSUMER,
                $peer
            ),
        ])->trigger();

        return new \moodle_url('/mod/lti/launch.php', ['id' => $cm->id]);
    }
}
