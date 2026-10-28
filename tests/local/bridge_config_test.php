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
 * Tests of the decision where the bridge is added, with real mod_lti activities.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\bridge_config
 */
final class bridge_config_test extends \advanced_testcase {
    /**
     * Which role applies to which page and user.
     *
     * @dataProvider role_provider
     * @param string|null $path Local path.
     * @param string $auth Auth plugin of the user.
     * @param array $config Settings to apply.
     * @param string|null $expected Expected role.
     */
    public function test_role_for(?string $path, string $auth, array $config, ?string $expected): void {
        $this->resetAfterTest();
        foreach ($config as $name => $value) {
            set_config($name, $value, 'local_ltixsembed');
        }
        $user = (object) ['id' => 5, 'auth' => $auth];
        $this->assertSame($expected, bridge_config::role_for($path, $user, true));
    }

    /**
     * Role samples.
     *
     * @return array
     */
    public static function role_provider(): array {
        return [
            'lti view page' => ['/mod/lti/view.php', 'manual', [], 'consumer'],
            'lti view page, platform side off' => ['/mod/lti/view.php', 'manual', ['consumerenabled' => 0], null],
            'enrol_lti login' => ['/enrol/lti/login.php', 'manual', [], 'provider'],
            'auth_lti login' => ['/auth/lti/login.php', 'manual', [], 'provider'],
            'enrol_lti launch' => ['/enrol/lti/launch.php', 'lti', [], 'provider'],
            'deep link launch' => ['/enrol/lti/launch_deeplink.php', 'lti', [], 'provider'],
            'tool side off' => ['/enrol/lti/login.php', 'manual', ['providerenabled' => 0], null],
            'course page of an lti user, default' => ['/course/view.php', 'lti', [], null],
            'course page of an lti user, switched on' => ['/course/view.php', 'lti', ['providerltipages' => 1], 'provider'],
            'course page of a manual user, switched on' => ['/course/view.php', 'manual', ['providerltipages' => 1], null],
            'other lti page, not the view page' => ['/mod/lti/mod_form.php', 'manual', [], null],
            'front page' => ['/', 'manual', [], null],
            'login page (expired embedded session lands here)' => ['/login/index.php', 'manual', [], 'provider'],
            'login page, tool side off' => ['/login/index.php', 'manual', ['providerenabled' => 0], null],
            'no url' => [null, 'lti', ['providerltipages' => 1], null],
            'path trickery' => ['/mod/lti/view.php/../../course/view.php', 'manual', [], null],
            'similar path' => ['/mod/lti/view.php.bak', 'manual', [], null],
        ];
    }

    /**
     * Pages of anonymous visitors never count as LTI user pages.
     */
    public function test_user_pages_need_a_signed_in_user(): void {
        $this->resetAfterTest();
        set_config('providerltipages', 1, 'local_ltixsembed');
        $this->assertNull(bridge_config::role_for('/course/view.php', (object) ['id' => 0, 'auth' => 'lti'], false));
    }

    /**
     * The local path is taken from the page URL without the query string.
     */
    public function test_local_path(): void {
        $page = new \moodle_page();
        $this->assertNull(bridge_config::local_path($page), 'no URL yet');
        $page->set_url('/mod/lti/view.php', ['id' => 12]);
        $this->assertSame('/mod/lti/view.php', bridge_config::local_path($page));
    }

    /**
     * Create a course with an LTI activity.
     *
     * @param array $record Activity overrides.
     * @return array [course, cm, student]
     */
    private function lti_setup(array $record = []): array {
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', array_merge(
            [
                'course' => $course->id,
                'toolurl' => 'https://tool.example.org/launch',
                'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED,
            ],
            $record
        ));
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, get_coursemodule_from_instance('lti', $lti->id), $student];
    }

    /**
     * A page object for the LTI view page of a course module.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $cm Course module.
     * @return \moodle_page
     */
    private function view_page(\stdClass $course, \stdClass $cm): \moodle_page {
        $page = new \moodle_page();
        $page->set_url('/mod/lti/view.php', ['id' => $cm->id]);
        $page->set_context(\context_module::instance($cm->id));
        $page->set_cm($cm, $course);
        return $page;
    }

    /**
     * An iframe-launched LTI activity gets the consumer notice, a token bound to the user and the activity,
     * and the configured origins.
     */
    public function test_build_for_embedded_activity(): void {
        $this->resetAfterTest();
        set_config('allowedorigins', "https://tool.example.org\nhttps://other.example.org", 'local_ltixsembed');
        [$course, $cm, $student] = $this->lti_setup();
        $this->setUser($student);

        $built = bridge_config::build($this->view_page($course, $cm), $student);

        $this->assertNotNull($built);
        $this->assertSame('local_ltixsembed/notice_consumer', $built['template']);
        $this->assertSame('consumer', $built['js']['role']);
        $this->assertSame('cm:' . $cm->id, $built['js']['ctx']);
        $this->assertSame((int) $cm->id, $built['js']['cmid']);
        $this->assertSame(['https://tool.example.org', 'https://other.example.org'], $built['js']['allowedorigins']);
        $this->assertSame(8000, $built['js']['handshaketimeout']);
        $this->assertSame('suggest', $built['js']['fallbackmode']);
        $this->assertSame('auto', $built['js']['peerbridge'], 'consumer-only safe default: silence is no error');
        $this->assertSame(1, $built['js']['protocol']);

        $context = $built['templatecontext'];
        $this->assertSame((int) $cm->id, $context['cmid']);
        $this->assertSame(sesskey(), $context['sesskey']);
        $this->assertStringEndsWith('/local/ltixsembed/fallback.php', $context['formaction']);
        $this->assertTrue(nonce::verify($context['nonce'], nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id));
        $this->assertFalse(nonce::verify($context['nonce'], nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id + 1));
        $this->assertStringNotContainsString($context['nonce'], json_encode($built['js']), 'the token is for the form only');
    }

    /**
     * An activity that opens in a new window or replaces the page has no iframe, so no bridge.
     *
     * @dataProvider containers_provider
     * @param int $container Launch container.
     * @param bool $expected Whether the bridge is expected.
     */
    public function test_build_depends_on_the_launch_container(int $container, bool $expected): void {
        $this->resetAfterTest();
        [$course, $cm, $student] = $this->lti_setup(['launchcontainer' => $container]);
        $this->setUser($student);
        $built = bridge_config::build($this->view_page($course, $cm), $student);
        $this->assertSame($expected, $built !== null);
    }

    /**
     * Launch container samples.
     *
     * @return array
     */
    public static function containers_provider(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        return [
            'embed' => [LTI_LAUNCH_CONTAINER_EMBED, true],
            'embed without blocks' => [LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS, true],
            'default resolves to embed' => [LTI_LAUNCH_CONTAINER_DEFAULT, true],
            'new window' => [LTI_LAUNCH_CONTAINER_WINDOW, false],
            'replace page' => [LTI_LAUNCH_CONTAINER_REPLACE_MOODLE_WINDOW, false],
        ];
    }

    /**
     * The consumer side can be switched off.
     */
    public function test_build_respects_the_consumer_switch(): void {
        $this->resetAfterTest();
        set_config('consumerenabled', 0, 'local_ltixsembed');
        [$course, $cm, $student] = $this->lti_setup();
        $this->setUser($student);
        $this->assertNull(bridge_config::build($this->view_page($course, $cm), $student));
    }

    /**
     * A page whose course module is not an LTI activity gets nothing even on the LTI view path.
     */
    public function test_build_ignores_other_modules(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $this->assertNull(bridge_config::build($this->view_page($course, $cm), $student));
    }

    /**
     * Tool-side pages get the provider notice and no token at all.
     */
    public function test_build_for_provider_pages(): void {
        $this->resetAfterTest();
        set_config('allowedorigins', 'https://platform.example.org', 'local_ltixsembed');
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $page = new \moodle_page();
        $page->set_url('/enrol/lti/login.php');

        $built = bridge_config::build($page, $user);

        $this->assertSame('local_ltixsembed/notice_provider', $built['template']);
        $this->assertSame('provider', $built['js']['role']);
        $this->assertSame(['https://platform.example.org'], $built['js']['allowedorigins']);
        $this->assertTrue($built['js']['loggedin']);
        $this->assertSame(['storageaccess' => true], $built['templatecontext']);
        $this->assertTrue($built['js']['storageaccess']);
        $this->assertArrayNotHasKey('nonce', $built['js']);
    }

    /**
     * The Storage Access API button follows the switch, in the JavaScript configuration and in the markup.
     */
    public function test_storage_access_button_follows_the_setting(): void {
        global $PAGE;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $page = new \moodle_page();
        $page->set_url('/enrol/lti/login.php');
        $output = $PAGE->get_renderer('core');

        $on = bridge_config::build($page, $user);
        $html = $output->render_from_template($on['template'], $on['templatecontext']);
        $this->assertStringContainsString('data-action="request-storage-access"', $html);

        set_config('storageaccess', 0, 'local_ltixsembed');
        $off = bridge_config::build($page, $user);
        $this->assertFalse($off['js']['storageaccess']);
        $html = $output->render_from_template($off['template'], $off['templatecontext']);
        $this->assertStringNotContainsString('request-storage-access', $html);
        $this->assertStringContainsString('data-region="state-message"', $html, 'the notice itself stays');
    }

    /**
     * The provider bridge reports "not logged in" for guests and visitors.
     */
    public function test_provider_page_reports_anonymous_visitors(): void {
        $this->resetAfterTest();
        $this->setUser(0);
        $page = new \moodle_page();
        $page->set_url('/enrol/lti/login.php');
        $built = bridge_config::build($page, (object) ['id' => 0, 'auth' => 'manual']);
        $this->assertFalse($built['js']['loggedin']);
        $this->setGuestUser();
        $built = bridge_config::build($page, (object) ['id' => 1, 'auth' => 'manual']);
        $this->assertFalse($built['js']['loggedin']);
    }
}
