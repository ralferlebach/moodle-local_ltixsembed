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
 * Integration tests of the server side of the top-level window fallback: real course, real mod_lti
 * activity, real capability checks, real event.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\fallback
 * @covers     \local_ltixsembed\event\fallback_launched
 */
final class fallback_test extends \advanced_testcase {
    /**
     * Fresh consumed-token cache for every test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \cache_helper::purge_by_definition('local_ltixsembed', 'consumed');
    }

    /**
     * Create a course, an embedded LTI activity and an enrolled student.
     *
     * @return array [course, cm, student]
     */
    private function setup_activity(): array {
        $course = $this->getDataGenerator()->create_course();
        $lti = $this->getDataGenerator()->create_module('lti', [
            'course' => $course->id,
            'toolurl' => 'https://tool.example.org/launch',
            'launchcontainer' => LTI_LAUNCH_CONTAINER_EMBED,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [$course, get_coursemodule_from_instance('lti', $lti->id), $student];
    }

    /**
     * A valid request leads to mod_lti's own launch script with nothing but the course module id.
     */
    public function test_prepare_returns_the_fixed_launch_url(): void {
        global $CFG;
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id);

        $url = fallback::prepare((int) $cm->id, $token, 'storage_unavailable');

        $this->assertSame($CFG->wwwroot . '/mod/lti/launch.php?id=' . $cm->id, $url->out(false));
        $this->assertStringNotContainsString($token, $url->out(false));
        $this->assertStringNotContainsString('sesskey', $url->out(false));
    }

    /**
     * The use of the fallback is logged with the reason and the diagnostics fields only.
     */
    public function test_event_is_triggered(): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $sink = $this->redirectEvents();
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id);
        fallback::prepare((int) $cm->id, $token, 'blocked_frame');
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(\local_ltixsembed\event\fallback_launched::class, $event);
        $this->assertSame((int) $student->id, (int) $event->userid);
        $this->assertSame((int) $cm->id, (int) $event->objectid);
        // The 0.2.0 key "reason" plus the family contract of tool_ltidiagnostics (no correlation id was sent here).
        $this->assertSame([
            'reason' => 'blocked_frame', 'correlationid' => '', 'code' => 'blocked_frame', 'version' => 1,
            'role' => 'consumer', 'peer' => 'none',
        ], $event->other);
        $this->assertNotEmpty($event->get_name());
        $this->assertStringContainsString('blocked_frame', $event->get_description());
        $this->assertStringEndsWith('/mod/lti/view.php?id=' . $cm->id, $event->get_url()->out(false));
    }

    /**
     * An unknown reason is stored as "other", never as free text.
     */
    public function test_reason_is_reduced_to_the_known_list(): void {
        $this->assertSame('manual', fallback::clean_reason('manual'));
        $this->assertSame('session_lost', fallback::clean_reason('session_lost'));
        $this->assertSame('other', fallback::clean_reason('<script>alert(1)</script>'));
        $this->assertSame('other', fallback::clean_reason(''));
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $sink = $this->redirectEvents();
        fallback::prepare((int) $cm->id, nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id), 'x"y');
        $other = $sink->get_events()[0]->other;
        $this->assertSame('other', $other['reason']);
        $this->assertSame('other', $other['code']);
        $sink->close();
    }

    /**
     * A token works once; a replay is refused and logs nothing.
     */
    public function test_replay_is_refused(): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id);
        fallback::prepare((int) $cm->id, $token, 'manual');

        $sink = $this->redirectEvents();
        try {
            fallback::prepare((int) $cm->id, $token, 'manual');
            $this->fail('A replayed token must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_nonce', $e->errorcode);
        }
        $this->assertCount(0, $sink->get_events());
        $sink->close();
    }

    /**
     * Tokens of other users, other activities, other purposes, expired or forged tokens are refused.
     *
     * @dataProvider bad_token_provider
     * @param string $case Which kind of bad token to build.
     */
    public function test_bad_tokens_are_refused(string $case): void {
        [$course, $cm, $student] = $this->setup_activity();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $lti2 = $this->getDataGenerator()->create_module('lti', ['course' => $course->id]);
        $cm2 = get_coursemodule_from_instance('lti', $lti2->id);
        $this->setUser($student);

        $tokens = [
            'other user' => nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $other->id),
            'other activity' => nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm2->id, (int) $student->id),
            'other purpose' => nonce::issue('somethingelse', 'cm:' . $cm->id, (int) $student->id),
            'expired' => nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id, 60, time() - 3600),
            'garbage' => 'v1.garbage',
            'empty' => '',
            'sesskey instead' => sesskey(),
        ];
        try {
            fallback::prepare((int) $cm->id, $tokens[$case], 'manual');
            $this->fail("Token case '$case' must be refused.");
        } catch (\moodle_exception $e) {
            $this->assertSame('error_nonce', $e->errorcode, $case);
        }
    }

    /**
     * Names of the bad token cases.
     *
     * @return array
     */
    public static function bad_token_provider(): array {
        return array_map(static fn($c) => [$c], [
            'other user', 'other activity', 'other purpose', 'expired', 'garbage', 'empty', 'sesskey instead',
        ]);
    }

    /**
     * A user who may not view the activity is refused before the token is looked at.
     */
    public function test_capability_is_required(): void {
        [, $cm] = $this->setup_activity();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $outsider->id);
        $this->expectException(\required_capability_exception::class);
        fallback::prepare((int) $cm->id, $token, 'manual');
    }

    /**
     * The capability check does not consume the token of a refused user.
     */
    public function test_refused_user_does_not_burn_a_token(): void {
        [, $cm, $student] = $this->setup_activity();
        $outsider = $this->getDataGenerator()->create_user();
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $outsider->id);
        $this->setUser($outsider);
        try {
            fallback::prepare((int) $cm->id, $token, 'manual');
        } catch (\required_capability_exception $e) {
            $this->assertNotNull($e);
        }
        $this->assertTrue(nonce::verify($token, nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $outsider->id));
        $this->assertGreaterThan(0, $student->id);
    }

    /**
     * Only LTI activities can be launched this way.
     */
    public function test_other_modules_are_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id);
        $this->expectException(\moodle_exception::class);
        fallback::prepare((int) $cm->id, $token, 'manual');
    }

    /**
     * A missing activity is an error, not a redirect.
     */
    public function test_unknown_activity_is_refused(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        fallback::prepare(987654, nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:987654', (int) $student->id), 'manual');
    }

    /**
     * Correlation id and peer from the request are cleaned before they reach the log.
     */
    public function test_diagnostics_values_are_cleaned(): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $sink = $this->redirectEvents();
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id);
        fallback::prepare((int) $cm->id, $token, 'manual', 'https://evil.example/', 'javascript');
        $other = $sink->get_events()[0]->other;
        $sink->close();
        $this->assertSame('', $other['correlationid']);
        $this->assertSame('none', $other['peer']);
        $this->assertSame('manual', $other['code']);
    }

    /**
     * The method signature leaves no room for a caller-chosen URL.
     */
    public function test_no_url_parameter_exists(): void {
        $method = new \ReflectionMethod(fallback::class, 'prepare');
        $names = array_map(static fn($p) => $p->getName(), $method->getParameters());
        // The diagnostics values (correlation id, peer) are validated and only logged; none of them is a URL.
        $this->assertSame(['cmid', 'token', 'reason', 'correlationid', 'peer'], $names);
        $this->assertSame(\moodle_url::class, $method->getReturnType()->getName());
    }
}
