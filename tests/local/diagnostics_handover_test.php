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
 * Diagnostics hand-over (CE-04): the events of this plugin satisfy the LTI family contract that tool_ltidiagnostics
 * reads (component prefix "local_lti", other: correlationid / code / version), they reach the standard log store, and -
 * where tool_ltidiagnostics is installed in the same tree - its own log-store reader returns them with the
 * correlation id and code. Where it is not installed, the test proves the contract against this plugin's copy of the
 * rules and records that the reader itself was absent (no skip).
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\diagnostics_handover
 * @covers     \local_ltixsembed\event\embed_state_reported
 * @covers     \local_ltixsembed\event\fallback_launched
 */
final class diagnostics_handover_test extends \advanced_testcase {
    /** @var string Correlation id as the browser computes it. */
    private const CID = 'aa11bb22cc33dd44ee55ff6600778899';

    /**
     * The contract of tool_ltidiagnostics, restated: family component, sanitised keys, nothing else used.
     *
     * @param string $component Component of the event.
     * @param array $other The event's other data.
     */
    private function assert_family_contract(string $component, array $other): void {
        $this->assertStringStartsWith('local_lti', $component);
        $this->assertGreaterThan(strlen('local_lti'), strlen($component));
        $this->assertSame($other['correlationid'], clean_param($other['correlationid'], PARAM_ALPHANUMEXT));
        $this->assertLessThanOrEqual(64, strlen($other['correlationid']));
        $this->assertSame($other['code'], clean_param($other['code'], PARAM_ALPHANUMEXT));
        $this->assertLessThanOrEqual(32, strlen($other['code']));
        $this->assertIsInt($other['version']);
        foreach ($other as $key => $value) {
            $this->assertIsScalar($value, $key);
            $this->assertDoesNotMatchRegularExpression('~(https?:|@|/|sesskey|MoodleSession|eyJ)~i', (string) $value, $key);
        }
    }

    /**
     * Course, embedded LTI activity, enrolled student, signed in.
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
        $this->setUser($student);
        return [$course, get_coursemodule_from_instance('lti', $lti->id), $student];
    }

    /**
     * Enable the standard log store without buffer.
     */
    private function enable_standard_logstore(): void {
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * The other data builder: clean values, malformed correlation ids dropped, unknown role/peer reduced.
     */
    public function test_other_is_clean(): void {
        $this->assertSame([
            'correlationid' => self::CID, 'code' => 'session_lost', 'version' => 1, 'role' => 'consumer', 'peer' => 'bridge',
        ], diagnostics_handover::other('session_lost', self::CID, 'consumer', 'bridge'));
        $other = diagnostics_handover::other('manual', 'https://evil.example/?x=1', 'admin', '<b>');
        $this->assertSame('', $other['correlationid']);
        $this->assertSame('other', $other['role']);
        $this->assertSame('none', $other['peer']);
        $this->assertTrue(diagnostics_handover::is_valid_code('consumer', 'blocked_frame'));
        $this->assertFalse(diagnostics_handover::is_valid_code('provider', 'blocked_frame'));
        $this->assertFalse(diagnostics_handover::is_valid_code('', 'blocked_frame'));
    }

    /**
     * Both events of the plugin satisfy the family contract, and the fallback event carries the correlation id.
     */
    public function test_events_satisfy_the_family_contract(): void {
        $this->resetAfterTest();
        \cache_helper::purge_by_definition('local_ltixsembed', 'consumed');
        [, $cm, $student] = $this->setup_activity();
        $sink = $this->redirectEvents();
        $token = nonce::issue(nonce::PURPOSE_FALLBACK, 'cm:' . $cm->id, (int) $student->id);
        fallback::prepare((int) $cm->id, $token, 'storage_unavailable', self::CID, 'bridge');
        \local_ltixsembed\event\embed_state_reported::create([
            'context' => \context_module::instance($cm->id),
            'other' => diagnostics_handover::other('blocked_frame', self::CID, 'consumer', 'silent'),
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assert_family_contract($event->component, $event->other);
            $this->assertSame(self::CID, $event->other['correlationid']);
        }
        $this->assertSame('storage_unavailable', $events[0]->other['code']);
        $this->assertSame('storage_unavailable', $events[0]->other['reason'], 'the 0.2.0 key stays for existing reports');
    }

    /**
     * The events reach the standard log store and are read back by tool_ltidiagnostics' reader when it is installed.
     */
    public function test_diagnostics_reader_gets_the_events(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_standard_logstore();
        [, $cm] = $this->setup_activity();
        \local_ltixsembed\event\embed_state_reported::create([
            'context' => \context_module::instance($cm->id),
            'other' => diagnostics_handover::other('needs_user_intervention', self::CID, 'consumer', 'bridge'),
        ])->trigger();

        $this->assertSame(1, $DB->count_records('logstore_standard_log', ['component' => 'local_ltixsembed']));
        $reader = get_log_manager()->get_readers('\\core\\log\\sql_reader')['logstore_standard'];
        $stored = array_values($reader->get_events_select('component = :c', ['c' => 'local_ltixsembed'], 'timecreated DESC', 0, 5));
        $this->assertCount(1, $stored);
        $this->assertInstanceOf(\local_ltixsembed\event\embed_state_reported::class, $stored[0]);
        $other = $stored[0]->other;
        $this->assert_family_contract($stored[0]->component, $other);

        $installed = \core_component::get_component_directory('tool_ltidiagnostics') !== null
            && class_exists('\\tool_ltidiagnostics\\local\\logstore_source');
        if (!$installed) {
            // Recorded, not skipped: the contract above is the evidence in a tree without the diagnostics tool.
            $this->assertFalse(class_exists('\\tool_ltidiagnostics\\local\\family_events'));
            return;
        }
        $this->assertTrue(\tool_ltidiagnostics\local\family_events::is_family_component('local_ltixsembed'));
        $this->assertSame(
            ['correlationid' => self::CID, 'connectionid' => 0, 'version' => 1, 'code' => 'needs_user_intervention'],
            \tool_ltidiagnostics\local\family_events::sanitise_other($other)
        );
        $this->setAdminUser();
        $result = (new \tool_ltidiagnostics\local\logstore_source())->fetch(\context_system::instance(), ['family' => true]);
        $this->assertTrue($result['available']);
        $ours = array_values(array_filter($result['rows'], function ($r) {
            return $r['component'] === 'local_ltixsembed';
        }));
        $this->assertCount(1, $ours);
        $this->assertTrue($ours[0]['family']);
        $this->assertSame(self::CID, $ours[0]['correlationid']);
        $this->assertSame('needs_user_intervention', $ours[0]['code']);
        $this->assertSame(1, $ours[0]['version']);
    }
}
