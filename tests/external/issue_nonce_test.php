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

use local_ltixsembed\local\nonce;

/**
 * Tests of the web service that issues a fresh fallback token.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\external\issue_nonce
 */
final class issue_nonce_test extends \advanced_testcase {
    /**
     * Create a course with an LTI activity.
     *
     * @return array [course, cm, student]
     */
    private function setup_activity(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', ['course' => $course->id, 'toolurl' => 'https://tool.example.org/']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, get_coursemodule_from_instance('lti', $lti->id), $student];
    }

    /**
     * An enrolled user gets a token bound to herself and the activity.
     */
    public function test_issues_a_bound_token(): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $result = \core_external\external_api::clean_returnvalue(
            issue_nonce::execute_returns(),
            issue_nonce::execute((int) $cm->id)
        );
        $this->assertTrue(nonce::verify($result['nonce'], nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id));
        $this->assertFalse(nonce::verify($result['nonce'], nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id + 1));
        $this->assertGreaterThan(time(), $result['expires']);
    }

    /**
     * The web service is declared for AJAX and requires login.
     */
    public function test_service_declaration(): void {
        $functions = [];
        include(__DIR__ . '/../../db/services.php');
        $this->assertArrayHasKey('local_ltixsembed_issue_nonce', $functions);
        $this->assertTrue($functions['local_ltixsembed_issue_nonce']['ajax']);
        $this->assertTrue($functions['local_ltixsembed_issue_nonce']['loginrequired']);
        $this->assertSame(issue_nonce::class, $functions['local_ltixsembed_issue_nonce']['classname']);
    }

    /**
     * Users without access to the activity are refused.
     */
    public function test_refuses_users_without_access(): void {
        [, $cm] = $this->setup_activity();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        issue_nonce::execute((int) $cm->id);
    }

    /**
     * Activities that are not LTI are refused.
     */
    public function test_refuses_other_modules(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->expectException(\moodle_exception::class);
        issue_nonce::execute((int) get_coursemodule_from_instance('forum', $forum->id)->id);
    }

    /**
     * The token lifetime follows the setting.
     */
    public function test_lifetime(): void {
        [, $cm, $student] = $this->setup_activity();
        set_config('noncelifetime', 90, 'local_ltixsembed');
        $this->setUser($student);
        $before = time();
        $result = issue_nonce::execute((int) $cm->id);
        $this->assertGreaterThanOrEqual($before + 90, $result['expires']);
        $this->assertLessThanOrEqual(time() + 90, $result['expires']);
    }
}
