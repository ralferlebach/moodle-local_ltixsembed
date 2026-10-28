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

use local_ltixsembed\local\diagnostics_handover;

/**
 * Tests of the diagnostics report endpoint (CE-04): real course, real mod_lti activity, real capability checks,
 * real event; fixed vocabulary, malformed input refused, rate limit and duplicates.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\external\report_state
 * @covers     \local_ltixsembed\local\diagnostics_handover
 * @covers     \local_ltixsembed\event\embed_state_reported
 */
final class report_state_test extends \advanced_testcase {
    /** @var string A well-formed correlation id. */
    private const CID = '0123456789abcdef0123456789abcdef';

    /**
     * Fresh rate-limit cache for every test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \cache_helper::purge_by_definition('local_ltixsembed', 'reports');
    }

    /**
     * Course, embedded LTI activity, enrolled student.
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
     * Call the function through the external API layer (parameter and return validation included).
     *
     * @param array $args Arguments.
     * @return array The cleaned result.
     */
    private function call(array $args): array {
        $result = report_state::execute(...array_values(array_merge(
            ['role' => 'consumer', 'cmid' => 0, 'code' => 'storage_unavailable', 'correlationid' => self::CID, 'peer' => 'bridge'],
            $args
        )));
        return \core_external\external_api::clean_returnvalue(report_state::execute_returns(), $result);
    }

    /**
     * A platform-side report triggers one event in the module context whose "other" follows the family contract.
     */
    public function test_consumer_report_triggers_the_event(): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $sink = $this->redirectEvents();

        $result = $this->call(['cmid' => (int) $cm->id, 'code' => 'needs_user_intervention']);

        $events = $sink->get_events();
        $sink->close();
        $this->assertSame(['logged' => true, 'reason' => ''], $result);
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(\local_ltixsembed\event\embed_state_reported::class, $event);
        $this->assertSame('local_ltixsembed', $event->component);
        $this->assertSame(CONTEXT_MODULE, (int) $event->contextlevel);
        $this->assertSame((int) $cm->id, (int) $event->contextinstanceid);
        $this->assertSame([
            'correlationid' => self::CID,
            'code' => 'needs_user_intervention',
            'version' => diagnostics_handover::PROTOCOL_VERSION,
            'role' => 'consumer',
            'peer' => 'bridge',
        ], $event->other);
        $this->assertStringContainsString('needs_user_intervention', $event->get_description());
        $this->assertStringEndsWith('/mod/lti/view.php?id=' . $cm->id, $event->get_url()->out(false));
        $this->assertNotEmpty($event::get_name());
    }

    /**
     * The tool side reports in the system context for a signed-in user.
     */
    public function test_provider_report_for_a_signed_in_user(): void {
        $user = $this->getDataGenerator()->create_user(['auth' => 'lti']);
        $this->setUser($user);
        $sink = $this->redirectEvents();
        $result = $this->call(['role' => 'provider', 'code' => 'storage_access_granted', 'peer' => 'silent']);
        $events = $sink->get_events();
        $sink->close();
        $this->assertTrue($result['logged']);
        $this->assertCount(1, $events);
        $this->assertSame(CONTEXT_SYSTEM, (int) $events[0]->contextlevel);
        $this->assertSame('provider', $events[0]->other['role']);
        $this->assertSame('silent', $events[0]->other['peer']);
        $this->assertNull($events[0]->get_url());
    }

    /**
     * Unknown codes, codes of the other role and unknown roles are refused before anything else happens.
     *
     * @dataProvider bad_codes_provider
     * @param string $role Role.
     * @param string $code Code.
     */
    public function test_unknown_codes_are_refused(string $role, string $code): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $this->expectException(\invalid_parameter_exception::class);
        $this->call(['role' => $role, 'cmid' => (int) $cm->id, 'code' => $code]);
    }

    /**
     * Refused role/code pairs.
     *
     * @return array
     */
    public static function bad_codes_provider(): array {
        return [
            'free text' => ['consumer', 'hello_world'],
            'embedded is no problem' => ['consumer', 'embedded'],
            'no_peer is local only' => ['consumer', 'no_peer'],
            'provider code on the platform' => ['consumer', 'storage_access_granted'],
            'tool cannot see its blocked frame' => ['provider', 'blocked_frame'],
            'unknown role' => ['attacker', 'storage_unavailable'],
        ];
    }

    /**
     * A correlation id that is not exactly 32 lower-case hex characters is refused.
     *
     * @dataProvider bad_cids_provider
     * @param string $cid Correlation id.
     */
    public function test_malformed_correlation_ids_are_refused(string $cid): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $this->expectException(\invalid_parameter_exception::class);
        $this->call(['cmid' => (int) $cm->id, 'correlationid' => $cid]);
    }

    /**
     * Malformed correlation ids.
     *
     * @return array
     */
    public static function bad_cids_provider(): array {
        return [
            'empty' => [''],
            'too short' => ['abc'],
            'upper case' => [strtoupper(self::CID)],
            'too long' => [self::CID . '00'],
            'not hex' => [str_repeat('z', 32)],
            'email-like' => ['student_example_com_0123456789ab'],
        ];
    }

    /**
     * A user who may not view the activity cannot report on it.
     */
    public function test_capability_is_required(): void {
        [, $cm] = $this->setup_activity();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\require_login_exception::class);
        $this->call(['cmid' => (int) $cm->id]);
    }

    /**
     * Guests cannot report on the tool side.
     */
    public function test_guest_cannot_report_on_the_tool_side(): void {
        $this->setGuestUser();
        $this->expectException(\require_login_exception::class);
        $this->call(['role' => 'provider', 'code' => 'storage_unavailable']);
    }

    /**
     * A disabled side logs nothing.
     */
    public function test_disabled_side_logs_nothing(): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        set_config('consumerenabled', 0, 'local_ltixsembed');
        $sink = $this->redirectEvents();
        $this->assertSame(['logged' => false, 'reason' => 'disabled'], $this->call(['cmid' => (int) $cm->id]));
        $this->assertCount(0, $sink->get_events());
        $sink->close();
    }

    /**
     * The same report twice is logged once; more than MAX_REPORTS different reports in the window are refused.
     */
    public function test_duplicates_and_rate_limit(): void {
        [, $cm, $student] = $this->setup_activity();
        $this->setUser($student);
        $sink = $this->redirectEvents();
        $this->assertTrue($this->call(['cmid' => (int) $cm->id])['logged']);
        $this->assertSame(['logged' => false, 'reason' => 'duplicate'], $this->call(['cmid' => (int) $cm->id]));
        for ($i = 1; $i < diagnostics_handover::MAX_REPORTS; $i++) {
            $cid = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
            $this->assertTrue($this->call(['cmid' => (int) $cm->id, 'correlationid' => $cid])['logged'], "report {$i}");
        }
        $refused = $this->call(['cmid' => (int) $cm->id, 'correlationid' => str_repeat('f', 32)]);
        $this->assertSame(['logged' => false, 'reason' => 'ratelimited'], $refused);
        $this->assertCount(diagnostics_handover::MAX_REPORTS, $sink->get_events());
        $sink->close();
    }

    /**
     * The window slides: reports older than WINDOW seconds no longer count.
     */
    public function test_rate_limit_window_slides(): void {
        $now = 1000000;
        for ($i = 0; $i < diagnostics_handover::MAX_REPORTS; $i++) {
            $this->assertSame('', diagnostics_handover::admit("sig{$i}", $now));
        }
        $this->assertSame('ratelimited', diagnostics_handover::admit('late', $now + 1));
        $this->assertSame('', diagnostics_handover::admit('late', $now + diagnostics_handover::WINDOW + 1));
    }

    /**
     * The event refuses incomplete data and a wrong context level.
     */
    public function test_event_validation(): void {
        try {
            \local_ltixsembed\event\embed_state_reported::create([
                'context' => \context_system::instance(),
                'other' => ['code' => 'x'],
            ]);
            $this->fail('incomplete other must be refused');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('correlationid', $e->getMessage());
        }
        $course = $this->getDataGenerator()->create_course();
        $this->expectException(\coding_exception::class);
        \local_ltixsembed\event\embed_state_reported::create([
            'context' => \context_course::instance($course->id),
            'other' => diagnostics_handover::other('storage_unavailable', self::CID, 'consumer', 'bridge'),
        ]);
    }
}
