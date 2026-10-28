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
 * Unit tests of the origin allow-list helpers.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\origins
 */
final class origins_test extends \advanced_testcase {
    /**
     * Valid candidates are canonicalised.
     *
     * @dataProvider valid_provider
     * @param string $candidate Input.
     * @param string $expected Canonical origin.
     */
    public function test_normalize_accepts_bare_https_origins(string $candidate, string $expected): void {
        $this->assertSame($expected, origins::normalize($candidate));
    }

    /**
     * Valid candidates.
     *
     * @return array
     */
    public static function valid_provider(): array {
        return [
            'plain' => ['https://tool.example.org', 'https://tool.example.org'],
            'upper case' => ['HTTPS://Tool.Example.ORG', 'https://tool.example.org'],
            'default port dropped' => ['https://tool.example.org:443', 'https://tool.example.org'],
            'custom port kept' => ['https://tool.example.org:8443', 'https://tool.example.org:8443'],
            'trailing slash' => ['https://tool.example.org/', 'https://tool.example.org'],
            'surrounding space' => ["  https://tool.example.org\t", 'https://tool.example.org'],
            'ipv4' => ['https://192.0.2.10:8443', 'https://192.0.2.10:8443'],
            'single label' => ['https://tool', 'https://tool'],
        ];
    }

    /**
     * Anything that is not a bare origin is refused.
     *
     * @dataProvider invalid_provider
     * @param string $candidate Input.
     */
    public function test_normalize_rejects_everything_else(string $candidate): void {
        $this->assertNull(origins::normalize($candidate));
    }

    /**
     * Invalid candidates.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'empty' => [''],
            'no scheme' => ['tool.example.org'],
            'http on a https site' => ['http://tool.example.org'],
            'ftp' => ['ftp://tool.example.org'],
            'javascript' => ['javascript:alert(1)'],
            'path' => ['https://tool.example.org/lti'],
            'query' => ['https://tool.example.org?x=1'],
            'fragment' => ['https://tool.example.org#x'],
            'userinfo' => ['https://user@tool.example.org'],
            'userinfo password' => ['https://user:pw@tool.example.org'],
            'wildcard' => ['https://*.example.org'],
            'inner space' => ['https://tool .example.org'],
            'backslash' => ['https://tool.example.org\\@evil.net'],
            'bad port' => ['https://tool.example.org:70000'],
            'underscore host' => ['https://too_l.example.org'],
            'control character' => ["https://tool.example.org\x01"],
            'ipv6 not supported' => ['https://[::1]'],
        ];
    }

    /**
     * Plain http is accepted only on sites that run on http themselves (development).
     */
    public function test_http_is_only_accepted_on_http_sites(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->wwwroot = 'https://moodle.example.org';
        $this->assertNull(origins::normalize('http://tool.example.org'));
        $CFG->wwwroot = 'http://moodle.test';
        $this->assertSame('http://tool.example.org', origins::normalize('http://tool.example.org'));
        $this->assertSame('http://tool.example.org:8080', origins::normalize('http://tool.example.org:8080'));
    }

    /**
     * Multi-line settings are parsed into unique canonical origins plus the rejected lines.
     */
    public function test_parse_collects_valid_and_invalid_lines(): void {
        $raw = "https://a.example.org\r\n\r\nhttps://A.example.org:443\nnot an origin\n" .
            "https://b.example.org/path\nhttps://c.example.org";
        $result = origins::parse($raw);
        $this->assertSame(['https://a.example.org', 'https://c.example.org'], $result['valid']);
        $this->assertSame(['not an origin', 'https://b.example.org/path'], $result['invalid']);
    }

    /**
     * The admin validation reports invalid lines and too many entries.
     */
    public function test_validate(): void {
        $this->assertTrue(origins::validate(''));
        $this->assertTrue(origins::validate("https://a.example.org\nhttps://b.example.org"));
        $message = origins::validate("https://a.example.org\nhttps://*.example.org");
        $this->assertIsString($message);
        $this->assertStringContainsString('*.example.org', $message);

        $many = implode("\n", array_map(static fn($i) => "https://h{$i}.example.org", range(1, origins::MAX_ENTRIES + 1)));
        $this->assertIsString(origins::validate($many));
    }

    /**
     * The origin of a full URL.
     */
    public function test_from_url(): void {
        $this->assertSame('https://tool.example.org', origins::from_url('https://tool.example.org/launch?x=1#f'));
        $this->assertSame('https://tool.example.org:8443', origins::from_url('https://tool.example.org:8443/a'));
        $this->assertSame('https://tool.example.org', origins::from_url('https://evil@tool.example.org/'));
        $this->assertNull(origins::from_url('/relative/path'));
        $this->assertNull(origins::from_url('not a url'));
        $this->assertNull(origins::from_url('ftp://tool.example.org/'));
    }

    /**
     * Matching is exact: look-alike origins never match.
     */
    public function test_is_allowed_is_exact(): void {
        $allowed = ['https://tool.example.org', 'https://other.example.org:8443'];
        $this->assertTrue(origins::is_allowed('https://tool.example.org', $allowed));
        $this->assertTrue(origins::is_allowed('https://other.example.org:8443', $allowed));
        foreach (
            [
            'https://tool.example.org.evil.net', 'https://evil-tool.example.org', 'http://tool.example.org',
            'https://tool.example.org:8443', 'https://TOOL.example.org', 'https://tool.example.org/', 'null', '',
            ] as $forged
        ) {
            $this->assertFalse(origins::is_allowed($forged, $allowed), $forged);
        }
        $this->assertFalse(origins::is_allowed('https://tool.example.org', []));
    }
}
