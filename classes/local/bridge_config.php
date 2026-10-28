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
 * Decides where the bridge is added to a page and what it is told.
 *
 * The decision is made from the page's local path, never from user input. The bridge is NOT added to
 * "every page": on a platform only to mod_lti's view page, on a tool only to the launch pages of
 * auth_lti / enrol_lti (and, when switched on, to the pages of users who signed in through LTI).
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bridge_config {
    /** @var string Role: this site is the LTI platform (consumer) that embeds a tool. */
    public const ROLE_CONSUMER = 'consumer';

    /** @var string Role: this site is the LTI tool (provider) that is embedded. */
    public const ROLE_PROVIDER = 'provider';

    /** @var string[] Local paths of the platform page that hosts the iframe. */
    public const CONSUMER_PATHS = ['/mod/lti/view.php'];

    /**
     * @var string[] Local paths of the tool-side launch pages that render output, plus the login page: an embedded tool
     * whose session expired lands there, and only the bridge on that page can tell the platform (state session_lost).
     * The script does nothing in a top-level window.
     */
    public const PROVIDER_PATHS = [
        '/login/index.php',
        '/auth/lti/login.php',
        '/enrol/lti/login.php',
        '/enrol/lti/launch.php',
        '/enrol/lti/launch_deeplink.php',
        '/enrol/lti/tool.php',
    ];

    /**
     * The local path of the current page, without query string.
     *
     * @param \moodle_page $page The page.
     * @return string|null Path such as "/mod/lti/view.php", or null if the page has no usable URL yet.
     */
    public static function local_path(\moodle_page $page): ?string {
        if (!$page->has_set_url()) {
            return null;
        }
        try {
            $local = $page->url->out_as_local_url(false);
        } catch (\Throwable $e) {
            return null;
        }
        $path = parse_url($local, PHP_URL_PATH);
        return is_string($path) ? $path : null;
    }

    /**
     * Which role (if any) applies to a path.
     *
     * @param string|null $path Local path of the page.
     * @param \stdClass $user The current user.
     * @param bool $loggedin Whether the user is logged in (not a guest, not anonymous).
     * @return string|null A ROLE_* constant or null when the bridge does not belong on this page.
     */
    public static function role_for(?string $path, \stdClass $user, bool $loggedin): ?string {
        if ($path === null) {
            return null;
        }
        if (in_array($path, self::CONSUMER_PATHS, true)) {
            return settings::consumer_enabled() ? self::ROLE_CONSUMER : null;
        }
        if (!settings::provider_enabled()) {
            return null;
        }
        if (in_array($path, self::PROVIDER_PATHS, true)) {
            return self::ROLE_PROVIDER;
        }
        if (settings::provider_lti_user_pages() && $loggedin && ($user->auth ?? '') === 'lti') {
            return self::ROLE_PROVIDER;
        }
        return null;
    }

    /**
     * Whether an activity launches its tool inside an iframe on the view page.
     *
     * Mirrors mod/lti/view.php: the default container resolves through the tool configuration.
     *
     * @param \stdClass $lti The lti instance record.
     * @return bool
     */
    public static function launches_in_iframe(\stdClass $lti): bool {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $typeid = $lti->typeid;
        if (empty($typeid) && ($tool = lti_get_tool_by_url_match($lti->toolurl))) {
            $typeid = $tool->id;
        }
        $toolconfig = $typeid ? lti_get_type_config($typeid) : [];
        // The container comes from the database as a string; compare as integers.
        $container = (int) lti_get_launch_container(clone $lti, $toolconfig);
        return in_array($container, [LTI_LAUNCH_CONTAINER_EMBED, LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS], true);
    }

    /**
     * Build the notice template and the JavaScript configuration for a page.
     *
     * @param \moodle_page $page The page being rendered.
     * @param \stdClass $user The current user.
     * @return array|null Null when nothing is to be added, otherwise
     *                    ['template' => name, 'templatecontext' => array, 'js' => array].
     */
    public static function build(\moodle_page $page, \stdClass $user): ?array {
        global $DB;
        $role = self::role_for(self::local_path($page), $user, isloggedin() && !isguestuser());
        if ($role === null) {
            return null;
        }
        $origins = settings::allowed_origins();

        if ($role === self::ROLE_PROVIDER) {
            $loggedin = isloggedin() && !isguestuser();
            return [
                'template' => 'local_ltixsembed/notice_provider',
                'templatecontext' => ['storageaccess' => settings::storage_access_recovery()],
                'js' => [
                    'role' => $role,
                    'storageaccess' => settings::storage_access_recovery(),
                    'allowedorigins' => $origins,
                    'loggedin' => $loggedin,
                    // The login page rendered for a visitor who is not signed in: inside a frame this means the
                    // embedded tool lost its session (see capability_core.detectSessionLost).
                    'loginpage' => !$loggedin && self::local_path($page) === '/login/index.php',
                    'protocol' => diagnostics_handover::PROTOCOL_VERSION,
                ],
            ];
        }

        $cm = $page->cm;
        if (!$cm || $cm->modname !== 'lti') {
            return null;
        }
        $lti = $DB->get_record('lti', ['id' => $cm->instance]);
        if (!$lti || !self::launches_in_iframe($lti)) {
            return null;
        }
        $context = fallback::context_for((int) $cm->id);
        return [
            'template' => 'local_ltixsembed/notice_consumer',
            'templatecontext' => [
                'formaction' => (new \moodle_url('/local/ltixsembed/fallback.php'))->out(false),
                'sesskey' => sesskey(),
                'cmid' => (int) $cm->id,
                'nonce' => nonce::issue(nonce::PURPOSE_FALLBACK, $context, (int) $user->id),
                'peerbridge' => settings::peer_bridge(),
            ],
            'js' => [
                'role' => $role,
                'ctx' => $context,
                'cmid' => (int) $cm->id,
                'allowedorigins' => $origins,
                'handshaketimeout' => settings::handshake_timeout() * 1000,
                'fallbackmode' => settings::fallback_mode(),
                'peerbridge' => settings::peer_bridge(),
                'protocol' => diagnostics_handover::PROTOCOL_VERSION,
            ],
        ];
    }
}
