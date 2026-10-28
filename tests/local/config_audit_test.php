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
 * Tests of the configuration audit: header parsing, judgement and the SSRF-safe fetcher.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_ltixsembed\local\config_audit
 * @covers     \local_ltixsembed\local\header_fetcher
 */
final class config_audit_test extends \advanced_testcase {
    /**
     * Facts of a healthy tool site, overridable per test.
     *
     * @param array $override Values to replace.
     * @return array
     */
    private static function facts(array $override = []): array {
        return array_merge([
            'wwwroot_https' => true,
            'allowframembedding' => true,
            'cookiesecure_setting' => true,
            'cookiesecure_effective' => true,
            'cookiehttponly' => true,
            'authlti_enabled' => true,
            'enrollti_enabled' => true,
            'core_cookie_helper' => true,
            'authlti_partitioning' => true,
            'origins_configured' => 1,
            'consumer_enabled' => true,
            'provider_enabled' => true,
            'fetch_ok' => true,
            'fetch_error' => '',
            'fetch_status' => 200,
            'xframeoptions' => null,
            'frameancestors' => null,
            'sessioncookie' => ['name' => 'MoodleSession', 'secure' => true, 'httponly' => true, 'partitioned' => false,
                'samesite' => 'lax'],
        ], $override);
    }

    /**
     * Finding ids and levels of an evaluation, as "id:level".
     *
     * @param array $facts Facts.
     * @param string[] $origins Configured origins.
     * @return string[]
     */
    private static function ids(array $facts, array $origins = []): array {
        return array_map(static fn($f) => $f['id'] . ':' . $f['level'], config_audit::evaluate($facts, $origins));
    }

    /**
     * Raw header lines are split per response; only the last response of a redirect chain counts.
     */
    public function test_parse_raw_headers(): void {
        $lines = [
            "HTTP/1.1 302 Found\r\n", "Location: https://x.example.org/\r\n", "Set-Cookie: old=1\r\n", "\r\n",
            "HTTP/2 200\r\n", "X-Frame-Options: SAMEORIGIN\r\n",
            "Set-Cookie: MoodleSession=abc; path=/; secure; HttpOnly; SameSite=Lax\r\n",
            "Set-Cookie: other=1\r\n", "content-security-policy: frame-ancestors 'self'\r\n", "garbage line without colon\r\n",
        ];
        $parsed = config_audit::parse_raw_headers($lines);
        $this->assertSame(200, $parsed['status']);
        $this->assertSame(['SAMEORIGIN'], $parsed['headers']['x-frame-options']);
        $this->assertCount(2, $parsed['headers']['set-cookie']);
        $this->assertArrayNotHasKey('location', $parsed['headers']);
    }

    /**
     * Set-Cookie attributes are parsed and the cookie value is never kept.
     *
     * @dataProvider cookie_provider
     * @param string $line Header value.
     * @param array $expected Expected parse result.
     */
    public function test_parse_set_cookie(string $line, array $expected): void {
        $parsed = config_audit::parse_set_cookie($line);
        $this->assertSame($expected, $parsed);
        $this->assertStringNotContainsString('secretvalue', json_encode($parsed));
    }

    /**
     * Cookie samples.
     *
     * @return array
     */
    public static function cookie_provider(): array {
        return [
            'lax' => ['MoodleSession=secretvalue; path=/; secure; HttpOnly; SameSite=Lax',
                ['name' => 'MoodleSession', 'secure' => true, 'httponly' => true, 'partitioned' => false, 'samesite' => 'lax']],
            'none partitioned' => ['MoodleSession=secretvalue; path=/; Secure; HttpOnly; SameSite=None; Partitioned',
                ['name' => 'MoodleSession', 'secure' => true, 'httponly' => true, 'partitioned' => true, 'samesite' => 'none']],
            'bare' => ['a=secretvalue', ['name' => 'a', 'secure' => false, 'httponly' => false, 'partitioned' => false,
                'samesite' => '']],
            'odd case' => ['X=secretvalue; SECURE; samesite=NONE',
                ['name' => 'X', 'secure' => true, 'httponly' => false, 'partitioned' => false, 'samesite' => 'none']],
        ];
    }

    /**
     * The frame-ancestors directive is found among several directives and headers.
     */
    public function test_frame_ancestors(): void {
        $this->assertNull(config_audit::frame_ancestors([]));
        $this->assertNull(config_audit::frame_ancestors(["default-src 'self'"]));
        $this->assertSame(
            ["'self'", 'https://a.example.org'],
            config_audit::frame_ancestors(["default-src 'self'; frame-ancestors 'self' https://a.example.org; img-src *"])
        );
        $this->assertSame(["'none'"], config_audit::frame_ancestors(["default-src 'self'", "FRAME-ANCESTORS 'none'"]));
    }

    /**
     * Healthy tool site: no warning and no error.
     */
    public function test_healthy_site(): void {
        $levels = array_column(config_audit::evaluate(self::facts(), ['https://a.example.org']), 'level');
        $this->assertNotContains(config_audit::WARNING, $levels);
        $this->assertNotContains(config_audit::ERROR, $levels);
    }

    /**
     * The judgement rules.
     *
     * @dataProvider rules_provider
     * @param array $override Fact overrides.
     * @param array $origins Configured origins.
     * @param string $expected "id:level" that must be among the findings.
     */
    public function test_rules(array $override, array $origins, string $expected): void {
        $this->assertContains($expected, self::ids(self::facts($override), $origins));
    }

    /**
     * Rule samples.
     *
     * @return array
     */
    public static function rules_provider(): array {
        return [
            'http site' => [['wwwroot_https' => false], [], 'https_missing:warning'],
            'secure cookies off on https' => [['cookiesecure_setting' => false], [], 'cookiesecure_off:warning'],
            'httponly off' => [['cookiehttponly' => false], [], 'httponly_off:warning'],
            'tool site forbids framing' => [['allowframembedding' => false], [], 'framing_blocked:warning'],
            'platform only site may forbid framing' => [['allowframembedding' => false, 'provider_enabled' => false], [],
                'framing_blocked:info'],
            'core helper missing' => [['authlti_partitioning' => false], [], 'chips_core_missing:warning'],
            'auth_lti disabled' => [['authlti_enabled' => false], [], 'authlti_disabled:info'],
            'no origins' => [['origins_configured' => 0], [], 'origins_none:warning'],
            'proxy overrides framing' => [['xframeoptions' => 'sameorigin'], [], 'xfo_conflict:error'],
            'xfo consistent' => [['xframeoptions' => 'deny', 'allowframembedding' => false], [], 'xfo_present:info'],
            'xfo absent' => [[], [], 'xfo_absent:ok'],
            'csp none' => [['frameancestors' => ["'none'"]], [], 'csp_none_blocks:warning'],
            'csp wildcard' => [['frameancestors' => ['*']], [], 'csp_wildcard:warning'],
            'csp partial' => [['frameancestors' => ["'self'", 'https://a.example.org']],
                ['https://a.example.org', 'https://b.example.org'], 'csp_partial:warning'],
            'csp covers' => [['frameancestors' => ["'self'", 'https://a.example.org']], ['https://a.example.org'], 'csp_ok:ok'],
            'csp absent' => [[], [], 'csp_none:ok'],
            'cookie lax' => [[], [], 'cookie_lax:ok'],
            'cookie none without secure' => [['sessioncookie' => ['name' => 'MoodleSession', 'secure' => false,
                'httponly' => true, 'partitioned' => false, 'samesite' => 'none']], [], 'cookie_none_insecure:error'],
            'cookie none globally' => [['sessioncookie' => ['name' => 'MoodleSession', 'secure' => true,
                'httponly' => true, 'partitioned' => false, 'samesite' => 'none']], [], 'cookie_none_global:warning'],
            'cookie without httponly' => [['sessioncookie' => ['name' => 'MoodleSession', 'secure' => true,
                'httponly' => false, 'partitioned' => false, 'samesite' => 'lax']], [], 'cookie_httponly_missing:warning'],
            'no cookie seen' => [['sessioncookie' => null], [], 'cookie_not_seen:info'],
        ];
    }

    /**
     * When the headers cannot be fetched the header rules are skipped and the reason is reported.
     */
    public function test_failed_fetch_skips_header_rules(): void {
        $ids = self::ids(self::facts(['fetch_ok' => false, 'fetch_error' => 'curl_7', 'xframeoptions' => 'sameorigin']));
        $this->assertContains('fetch_failed:info', $ids);
        $this->assertNotContains('xfo_conflict:error', $ids);
        $found = array_values(array_filter(
            config_audit::evaluate(self::facts(['fetch_ok' => false, 'fetch_error' => 'curl_7'])),
            static fn($f) => $f['id'] === 'fetch_failed'
        ));
        $this->assertSame('curl_7', $found[0]['a']);
    }

    /**
     * Every finding id has a string in both languages, and evaluate() never produces an id without one.
     */
    public function test_every_finding_has_strings(): void {
        $ids = [];
        foreach (self::rules_provider() as [$override, $origins]) {
            foreach (config_audit::evaluate(self::facts($override), $origins) as $finding) {
                $ids[$finding['id']] = true;
            }
        }
        $ids['fetch_failed'] = true;
        $ids['fetch_blocked'] = true;
        $this->assertNotEmpty($ids);
        foreach (array_keys($ids) as $id) {
            foreach (['en', 'de'] as $lang) {
                $this->assertTrue(get_string_manager()->string_exists('audit_' . $id, 'local_ltixsembed'), $id);
                $strings = $this->load_lang($lang);
                $this->assertArrayHasKey('audit_' . $id, $strings, "$lang lacks audit_$id");
            }
        }
    }

    /**
     * Load a language file of the plugin.
     *
     * @param string $lang Language code.
     * @return array
     */
    private function load_lang(string $lang): array {
        $string = [];
        include(__DIR__ . "/../../lang/{$lang}/local_ltixsembed.php");
        return $string;
    }

    /**
     * English and German define exactly the same string identifiers.
     */
    public function test_language_files_have_identical_keys(): void {
        $en = array_keys($this->load_lang('en'));
        $de = array_keys($this->load_lang('de'));
        $this->assertSame([], array_values(array_diff($en, $de)), 'missing in de');
        $this->assertSame([], array_values(array_diff($de, $en)), 'missing in en');
    }

    /**
     * Collecting facts with an injected fetcher parses the headers and drops cookie values.
     */
    public function test_collect_with_fake_fetcher(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->allowframembedding = 1;
        $facts = config_audit::collect(static function () use ($CFG) {
            return ['ok' => true, 'status' => 200, 'error' => '', 'lines' => [
                'HTTP/1.1 200 OK',
                'X-Frame-Options: SAMEORIGIN',
                "Content-Security-Policy: frame-ancestors 'self'",
                'Set-Cookie: MoodleSession' . ($CFG->sessioncookie ?? '') . '=topsecret; path=/; secure; HttpOnly; SameSite=Lax',
            ]];
        });
        $this->assertTrue($facts['fetch_ok']);
        $this->assertSame('sameorigin', $facts['xframeoptions']);
        $this->assertSame(["'self'"], $facts['frameancestors']);
        $this->assertSame('lax', $facts['sessioncookie']['samesite']);
        $this->assertStringNotContainsString('topsecret', json_encode($facts));
        $this->assertTrue($facts['allowframembedding']);
        $this->assertTrue($facts['core_cookie_helper']);
        $this->assertTrue($facts['authlti_partitioning']);
        $this->assertSame(
            'xfo_conflict:error',
            array_values(array_filter(self::ids($facts), static fn($i) => strpos($i, 'xfo_') === 0))[0]
        );
    }

    /**
     * A failing fetcher result is carried into the facts.
     */
    public function test_collect_with_failed_fetch(): void {
        $facts = config_audit::collect(static fn() => ['ok' => false, 'status' => 0, 'error' => 'blocked', 'lines' => []]);
        $this->assertFalse($facts['fetch_ok']);
        $this->assertSame('blocked', $facts['fetch_error']);
        $this->assertNull($facts['xframeoptions']);
    }

    /**
     * The real fetcher respects Moodle's cURL security: a blocked host is not contacted, and it is not even
     * handed to \curl, so core's url_blocked event (which always calls debugging()) is never triggered.
     * Negative control for release gate finding F6: with the 0.2.0-rc fetcher this test fails on
     * assertDebuggingNotCalled().
     */
    public function test_real_fetcher_honours_the_curl_blocklist(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->wwwroot = 'http://blocked.example.org';
        set_config('curlsecurityblockedhosts', 'blocked.example.org');
        $this->assertTrue(header_fetcher::is_blocked($CFG->wwwroot . '/login/index.php'));
        $result = header_fetcher::fetch_own_headers();
        $this->assertFalse($result['ok']);
        $this->assertSame('blocked', $result['error']);
        $this->assertSame([], $result['lines']);
        $this->assertDebuggingNotCalled();
    }

    /**
     * F6: the audit page under developer debugging, with this site's own host on the cURL block list, degrades to an
     * explanatory notice - no debugging output, no exception, the other findings stay.
     */
    public function test_audit_page_with_blocked_own_host_under_developer_debugging(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_debugging(DEBUG_DEVELOPER, true);
        $host = parse_url($CFG->wwwroot, PHP_URL_HOST);
        set_config('curlsecurityblockedhosts', $host);
        $sink = $this->redirectEvents();

        $context = config_audit::page_context(config_audit::collect(), settings::allowed_origins());
        $PAGE->set_url('/local/ltixsembed/audit.php');
        $PAGE->set_context(\context_system::instance());
        $html = $PAGE->get_renderer('core')->render_from_template('local_ltixsembed/audit', $context);

        $this->assertDebuggingNotCalled();
        $blocked = array_filter($sink->get_events(), static fn($e) => $e instanceof \core\event\url_blocked);
        $this->assertSame([], $blocked, 'the blocked URL must not be handed to curl at all');
        $ids = array_column($context['findings'], 'id');
        $this->assertContains('fetch_blocked', $ids);
        $this->assertNotContains('fetch_failed', $ids);
        $this->assertStringContainsString('data-finding="fetch_blocked"', $html);
        $this->assertStringContainsString('The header self-check was not run', $html);
        $this->assertContains('origins_none', $ids, 'the configuration findings are still reported');
    }

    /**
     * Redirect targets are resolved against the requested URL; only same-origin hops are followed.
     *
     * @dataProvider redirect_provider
     * @param string $base Requested URL.
     * @param string[] $lines Raw header lines.
     * @param string|null $expected Resolved target.
     */
    public function test_redirect_target(string $base, array $lines, ?string $expected): void {
        $this->assertSame($expected, header_fetcher::redirect_target($base, $lines));
    }

    /**
     * Cases for test_redirect_target.
     *
     * @return array
     */
    public static function redirect_provider(): array {
        $base = 'https://moodle.example.org/sub/login/index.php';
        return [
            'absolute' => [$base, ['HTTP/1.1 303 See Other', 'Location: https://moodle.example.org/sub/my/'],
                'https://moodle.example.org/sub/my/'],
            'root relative' => [$base, ["HTTP/1.1 302 Found\r\n", "location: /sub/login/index.php?x=1\r\n"],
                'https://moodle.example.org/sub/login/index.php?x=1'],
            'path relative' => [$base, ['HTTP/1.1 302 Found', 'Location: other.php'],
                'https://moodle.example.org/sub/login/other.php'],
            'no location' => [$base, ['HTTP/1.1 302 Found'], null],
            'empty location' => [$base, ['HTTP/1.1 302 Found', 'Location: '], null],
        ];
    }

    /**
     * Origin comparison used to refuse off-site redirects.
     */
    public function test_same_origin(): void {
        $this->assertTrue(header_fetcher::same_origin('https://a.example.org/x', 'https://a.example.org'));
        $this->assertTrue(header_fetcher::same_origin('https://A.example.org:443/x', 'https://a.example.org/moodle'));
        $this->assertTrue(header_fetcher::same_origin('http://a.example.org/x', 'http://a.example.org:80'));
        $this->assertFalse(header_fetcher::same_origin('http://a.example.org/x', 'https://a.example.org'));
        $this->assertFalse(header_fetcher::same_origin('https://evil.example.net/', 'https://a.example.org'));
        $this->assertFalse(header_fetcher::same_origin('https://a.example.org:8443/', 'https://a.example.org'));
        $this->assertFalse(header_fetcher::same_origin('/relative', 'https://a.example.org'));
    }

    /**
     * The blocked finding is an explanation, never an instruction to relax security, and replaces the generic one.
     */
    public function test_blocked_fetch_has_its_own_finding(): void {
        $f = config_audit::evaluate(self::facts(['fetch_ok' => false, 'fetch_error' => 'blocked']), []);
        $ids = array_column($f, 'id');
        $this->assertContains('fetch_blocked', $ids);
        $this->assertNotContains('fetch_failed', $ids);
        $this->assertSame('info', $f[array_search('fetch_blocked', $ids)]['level']);
        foreach (['en', 'de'] as $lang) {
            $text = $this->load_lang($lang)['audit_fetch_blocked'];
            $this->assertDoesNotMatchRegularExpression('/disable|deaktivier|remove .* block|entfernen/i', $text);
        }
    }

    /**
     * The fetcher has no way to take a URL from the caller (SSRF by construction).
     */
    public function test_fetcher_takes_no_url_parameter(): void {
        $method = new \ReflectionMethod(header_fetcher::class, 'fetch_own_headers');
        $this->assertSame(0, $method->getNumberOfParameters());
        $this->assertTrue($method->isStatic());
    }
}
