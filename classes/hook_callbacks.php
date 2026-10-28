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

namespace local_ltixsembed;

use core\hook\output\before_standard_top_of_body_html_generation;
use local_ltixsembed\local\bridge_config;

/**
 * Callbacks for core hooks.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Add the notice markup and start the bridge on the pages that need it.
     *
     * A failure here must never break the page that is being rendered, so errors are reported to
     * developers only and otherwise swallowed.
     *
     * @param before_standard_top_of_body_html_generation $hook The hook.
     * @return void
     */
    public static function before_standard_top_of_body_html_generation(before_standard_top_of_body_html_generation $hook): void {
        global $PAGE, $USER;
        try {
            $built = bridge_config::build($PAGE, $USER);
            if ($built === null) {
                return;
            }
            $hook->add_html($hook->renderer->render_from_template($built['template'], $built['templatecontext']));
            $PAGE->requires->js_call_amd('local_ltixsembed/bridge', 'init', [$built['js']]);
        } catch (\Throwable $e) {
            debugging('local_ltixsembed: bridge not added: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
