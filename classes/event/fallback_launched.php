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
 * A learner used the top-level window fallback for an embedded LTI activity.
 *
 * Only the reason label is recorded - never a URL, token or browser detail.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fallback_launched extends \core\event\base {
    /**
     * Initialise the event.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'course_modules';
    }

    /**
     * Localised name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_fallback_launched', 'local_ltixsembed');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' opened the course module with id '{$this->objectid}' in a top-level " .
            "window after the embedded launch reported '{$this->other['reason']}'.";
    }

    /**
     * Link to the activity.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/lti/view.php', ['id' => $this->objectid]);
    }

    /**
     * Extra validation.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The \'reason\' value must be set in other.');
        }
        if ($this->contextlevel != CONTEXT_MODULE) {
            throw new \coding_exception('Context level must be CONTEXT_MODULE.');
        }
    }

    /**
     * Mapping for backup/restore of logs: none, the objectid is a course module id.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'course_modules', 'restore' => 'course_module'];
    }

    /**
     * No other-value mapping.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
