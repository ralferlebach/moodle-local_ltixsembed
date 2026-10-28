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

namespace local_ltixsembed\event;

/**
 * The embedded LTI tool could not be used as intended, as detected in the browser (diagnostics hand-over, CE-04).
 *
 * "other" follows the LTI family contract read by tool_ltidiagnostics (correlationid, code, version) plus the role of the
 * reporting side and what it knew about its peer. It never holds a URL, token, browser detail or personal data.
 *
 * On the platform the context is the LTI activity; on the tool it is the system context.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embed_state_reported extends \core\event\base {
    /**
     * Initialise the event.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_embed_state_reported', 'local_ltixsembed');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        $role = $this->other['role'] ?? '';
        $code = $this->other['code'] ?? '';
        $peer = $this->other['peer'] ?? '';
        return "The browser of the user with id '{$this->userid}' reported '{$code}' on the {$role} side of an embedded LTI " .
            "launch (peer bridge: {$peer}).";
    }

    /**
     * Link to the activity on the platform side.
     *
     * @return \moodle_url|null
     */
    public function get_url() {
        if ($this->contextlevel == CONTEXT_MODULE) {
            return new \moodle_url('/mod/lti/view.php', ['id' => $this->contextinstanceid]);
        }
        return null;
    }

    /**
     * Extra validation.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['correlationid', 'code', 'version', 'role', 'peer'] as $key) {
            if (!array_key_exists($key, $this->other ?? [])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
        if (!in_array($this->contextlevel, [CONTEXT_MODULE, CONTEXT_SYSTEM])) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE or CONTEXT_SYSTEM.');
        }
    }

    /**
     * No object id.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }

    /**
     * No other-value mapping: "other" holds no ids.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
