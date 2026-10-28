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

/**
 * Integration tests of the hook: real hook manager, real renderer, real mod_lti activity.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\hook_callbacks
 */
final class hook_callbacks_test extends \advanced_testcase {
    /**
     * Prepare the global page for the LTI view page.
     *
     * @return \stdClass The student, signed in.
     */
    private function prepare_view_page(): \stdClass {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $course->id,
            'toolurl' => 'https://tool.example.org/launch',
            'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cm = get_coursemodule_from_instance('lti', $lti->id);
        $this->setUser($student);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/mod/lti/view.php', ['id' => $cm->id]);
        $PAGE->set_context(\context_module::instance($cm->id));
        $PAGE->set_cm($cm, $course);
        $PAGE->set_pagelayout('incourse');
        return $student;
    }

    /**
     * Run the hook through the real hook manager and return what it added.
     *
     * @return string The HTML added to the top of the body.
     */
    private function dispatch(): string {
        global $PAGE;
        $hook = new before_standard_top_of_body_html_generation($PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL));
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        return $hook->get_output();
    }

    /**
     * The callback is registered for the hook (db/hooks.php) in this Moodle version.
     */
    public function test_callback_is_registered_with_the_hook_manager(): void {
        $manager = \core\di::get(\core\hook\manager::class);
        $callbacks = $manager->get_callbacks_for_hook(before_standard_top_of_body_html_generation::class);
        $found = array_filter($callbacks, static fn($cb) => ($cb['component'] ?? '') === 'local_ltixsembed');
        $this->assertNotEmpty($found, 'the plugin must be among the callbacks of the hook');
    }

    /**
     * On the LTI view page the notice markup is added and the AMD bridge is started.
     */
    public function test_view_page_gets_the_bridge(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->prepare_view_page();

        $html = $this->dispatch();

        $this->assertStringContainsString('data-region="local_ltixsembed-notice"', $html);
        $this->assertStringContainsString('data-role="consumer"', $html);
        $this->assertStringContainsString('/local/ltixsembed/fallback.php', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringNotContainsString('?id=', $html, 'no query string in the fallback form action');
        $end = $PAGE->requires->get_end_code();
        $this->assertStringContainsString('local_ltixsembed/bridge', $end);
        $this->assertStringContainsString('"role":"consumer"', $end);
    }

    /**
     * The fallback form contains the token but the action URL carries nothing sensitive.
     */
    public function test_form_action_has_no_token_or_session_data(): void {
        $this->resetAfterTest();
        $student = $this->prepare_view_page();
        $html = $this->dispatch();
        preg_match('/<form[^>]*action="([^"]*)"/', $html, $m);
        $this->assertSame(1, preg_match('#/local/ltixsembed/fallback\.php$#', $m[1]));
        $this->assertSame(1, preg_match('/name="nonce" value="(v1\.[^"]+)"/', $html, $n));
        $this->assertNotSame('', $n[1]);
        $this->assertStringNotContainsString('MoodleSession', $html);
        $this->assertGreaterThan(0, $student->id);
    }

    /**
     * Ordinary pages receive nothing.
     */
    public function test_other_pages_get_nothing(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE = new \moodle_page();
        $PAGE->set_url('/course/view.php', ['id' => 2]);
        $PAGE->set_context(\context_system::instance());
        $this->assertSame('', $this->dispatch());
        $this->assertStringNotContainsString('local_ltixsembed', $PAGE->requires->get_end_code());
    }

    /**
     * Tool-side launch pages get the provider notice.
     */
    public function test_provider_launch_page_gets_the_bridge(): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config('allowedorigins', 'https://platform.example.org', 'local_ltixsembed');
        $this->setUser(0);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/enrol/lti/login.php');
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('popup');

        $html = $this->dispatch();

        $this->assertStringContainsString('data-role="provider"', $html);
        $this->assertStringContainsString('"role":"provider"', $PAGE->requires->get_end_code());
        $this->assertStringContainsString('"allowedorigins":["https:\/\/platform.example.org"]', $PAGE->requires->get_end_code());
    }

    /**
     * The tool's login page carries the provider bridge with the "login page" signal for a visitor who is not signed in
     * (session expiry inside a frame, CE-03); for a signed-in user the signal is off.
     */
    public function test_login_page_signals_a_lost_session(): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config('allowedorigins', 'https://platform.example.org', 'local_ltixsembed');
        $this->setUser(0);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/login/index.php');
        $PAGE->set_context(\context_system::instance());
        $this->assertStringContainsString('data-role="provider"', $this->dispatch());
        $this->assertStringContainsString('"loginpage":true', $PAGE->requires->get_end_code());

        $this->setUser($this->getDataGenerator()->create_user());
        $PAGE = new \moodle_page();
        $PAGE->set_url('/login/index.php');
        $PAGE->set_context(\context_system::instance());
        $this->dispatch();
        $this->assertStringContainsString('"loginpage":false', $PAGE->requires->get_end_code());
    }

    /**
     * Inactive switch (CE-02): with both sides off neither the platform page nor a tool launch page gets anything from
     * the plugin - no markup, no script, no token - so the site behaves as if the plugin were not there.
     */
    public function test_inactive_site_adds_nothing(): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config('consumerenabled', 0, 'local_ltixsembed');
        set_config('providerenabled', 0, 'local_ltixsembed');
        set_config('providerltipages', 1, 'local_ltixsembed');
        set_config('allowedorigins', 'https://peer.example.org', 'local_ltixsembed');

        $this->prepare_view_page();
        $this->assertSame('', $this->dispatch());
        $this->assertStringNotContainsString('local_ltixsembed', $PAGE->requires->get_end_code());

        foreach (['/enrol/lti/login.php', '/enrol/lti/launch.php', '/auth/lti/login.php'] as $path) {
            $PAGE = new \moodle_page();
            $PAGE->set_url($path);
            $PAGE->set_context(\context_system::instance());
            $this->assertSame('', $this->dispatch(), $path);
            $this->assertStringNotContainsString('local_ltixsembed', $PAGE->requires->get_end_code(), $path);
        }
    }

    /**
     * Consumer-only and provider-only: each side is added independently of the other switch.
     */
    public function test_each_side_works_without_the_other(): void {
        global $PAGE;
        $this->resetAfterTest();
        set_config('allowedorigins', 'https://peer.example.org', 'local_ltixsembed');

        set_config('consumerenabled', 1, 'local_ltixsembed');
        set_config('providerenabled', 0, 'local_ltixsembed');
        $this->prepare_view_page();
        $this->assertStringContainsString('data-role="consumer"', $this->dispatch());
        $PAGE = new \moodle_page();
        $PAGE->set_url('/enrol/lti/launch.php');
        $PAGE->set_context(\context_system::instance());
        $this->assertSame('', $this->dispatch(), 'consumer-only: tool pages unchanged');

        set_config('consumerenabled', 0, 'local_ltixsembed');
        set_config('providerenabled', 1, 'local_ltixsembed');
        $this->assertStringContainsString('data-role="provider"', $this->dispatch());
        $this->prepare_view_page();
        $this->assertSame('', $this->dispatch(), 'provider-only: platform pages unchanged');
    }

    /**
     * A failure inside the bridge never breaks the page: it is reported to developers and swallowed.
     */
    public function test_errors_never_break_the_page(): void {
        $this->resetAfterTest();
        $this->prepare_view_page();
        $renderer = $this->getMockBuilder(\core_renderer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['render_from_template'])
            ->getMock();
        $renderer->method('render_from_template')->willThrowException(new \Exception('boom'));
        $hook = new before_standard_top_of_body_html_generation($renderer);

        hook_callbacks::before_standard_top_of_body_html_generation($hook);

        $this->assertSame('', $hook->get_output());
        $this->assertDebuggingCalled('local_ltixsembed: bridge not added: boom', DEBUG_DEVELOPER);
    }

    /**
     * The real renderer path (standard_top_of_body_html) includes the notice for the view page.
     */
    public function test_renderer_path_includes_the_notice(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->prepare_view_page();
        $renderer = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);
        $this->assertStringContainsString('data-region="local_ltixsembed-notice"', $renderer->standard_top_of_body_html());
    }
}
