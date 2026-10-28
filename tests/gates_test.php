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

use Packback\Lti1p3\Interfaces\ICookie;
use Packback\Lti1p3\LtiOidcLogin;

/**
 * Evidence tests for the feasibility gates CE-G1 to CE-G3 of the specification.
 *
 * Each test states a fact about the Moodle core in the version under test and proves it - by running
 * the real code where that is possible in PHPUnit, by inspecting the core source where the fact
 * is an absence (no hook, no setting, no ordering). A failing test here does not mean the plugin is
 * broken: it means core has changed and the gate must be re-evaluated, which is the point.
 *
 * Gate CE-G4 (real browsers, Chrome/Firefox/Safari/iOS with unpartitioned cookies off) cannot be
 * decided in PHPUnit; it belongs to the Playwright suite and is reported in docs/ACCEPTANCE.md.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class gates_test extends \advanced_testcase {
    /**
     * Read a core source file.
     *
     * @param string $relative Path below the web root of the Moodle tree.
     * @return string The file content.
     */
    private function core_source(string $relative): string {
        global $CFG;
        $path = $CFG->dirroot . '/' . $relative;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Position of a needle in a source text, failing the test when it is missing.
     *
     * @param string $source The text.
     * @param string $needle What to find.
     * @return int Byte offset.
     */
    private function position(string $source, string $needle): int {
        $pos = strpos($source, $needle);
        $this->assertNotFalse($pos, "Expected to find '$needle' in the core source.");
        return $pos;
    }

    // CE-G1: is there a no-fork extension point for OIDC state and nonce storage?

    /**
     * CE-G1 (a): the LTI components publish no hook classes at all, and core has no hook about LTI or OIDC.
     */
    public function test_g1_no_hook_exists_for_oidc_state_or_nonce(): void {
        foreach (['auth_lti', 'enrol_lti', 'mod_lti'] as $component) {
            $this->assertSame(
                [],
                \core_component::get_component_classes_in_namespace($component, 'hook'),
                "$component must not define hook classes; if it does, CE-G1 needs re-evaluation."
            );
        }
        $corehooks = array_merge(
            array_keys(\core_component::get_component_classes_in_namespace('core', 'hook')),
            array_keys(\core_component::get_component_classes_in_namespace('core', 'hook\\output'))
        );
        $this->assertNotEmpty($corehooks, 'the hook discovery itself must work');
        foreach ($corehooks as $class) {
            $this->assertDoesNotMatchRegularExpression('/lti|oidc|nonce|openid/i', $class, "Core hook $class concerns LTI/OIDC.");
        }
    }

    /**
     * CE-G1 (b): the tool-side login endpoint creates its state/nonce storage itself, without any hook.
     */
    public function test_g1_login_endpoint_hardwires_its_storage(): void {
        $source = $this->core_source('enrol/lti/login.php');
        $this->assertStringContainsString('new launch_cache_session()', $source);
        $this->assertStringContainsString('new lti_cookie()', $source);
        $this->assertStringNotContainsString('hook', strtolower($source));
        $this->assertStringNotContainsString('component_callback', $source);
        $this->assertStringNotContainsString('get_plugins_with_function', $source);
    }

    /**
     * CE-G1 (c): the real OIDC login flow. The state goes into a COOKIE, the nonce into the PHP SESSION.
     *
     * Both therefore need a cookie that survives the cross-site frame. Platform storage (postMessage) is
     * not consulted anywhere. This runs the real library flow with the real session cache; only the
     * cookie writer is replaced, to see what is written.
     */
    public function test_g1_oidc_login_binds_state_to_cookie_and_nonce_to_session(): void {
        global $SESSION;
        $this->resetAfterTest();
        $appregrepo = new \enrol_lti\local\ltiadvantage\repository\application_registration_repository();
        $registration = \enrol_lti\local\ltiadvantage\entity\application_registration::create(
            'Embedding platform',
            'a2c94a2c94',
            new \moodle_url('https://platform.example.org'),
            'client-1',
            new \moodle_url('https://platform.example.org/auth'),
            new \moodle_url('https://platform.example.org/jwks'),
            new \moodle_url('https://platform.example.org/token')
        );
        $appregrepo->save($registration);
        $cookie = new class implements ICookie {
            /** @var array Cookies the flow tried to write. */
            public array $written = [];

            // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
            /**
             * Read a cookie.
             *
             * @param string $name Name.
             * @return string|null
             */
            public function getCookie(string $name): ?string {
                return $this->written[$name]['value'] ?? null;
            }

            /**
             * Record a cookie write.
             *
             * @param string $name Name.
             * @param string $value Value.
             * @param int $exp Lifetime.
             * @param array $options Options.
             */
            public function setCookie(string $name, string $value, int $exp = 3600, array $options = []): void {
                $this->written[$name] = ['value' => $value, 'exp' => $exp];
            }
            // phpcs:enable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
        };

        $issuerdb = new \enrol_lti\local\ltiadvantage\lib\issuer_database(
            $appregrepo,
            new \enrol_lti\local\ltiadvantage\repository\deployment_repository()
        );
        $login = LtiOidcLogin::new($issuerdb, new \enrol_lti\local\ltiadvantage\lib\launch_cache_session(), $cookie);
        $url = $login->getRedirectUrl((new \moodle_url('/enrol/lti/launch.php'))->out(false), [
            'iss' => 'https://platform.example.org', 'client_id' => 'client-1', 'login_hint' => 'hint',
        ]);

        // The library returns the URL HTML-escaped (&amp;); Moodle's redirect() unescapes it.
        parse_str((string) parse_url(html_entity_decode($url), PHP_URL_QUERY), $params);
        $this->assertArrayHasKey('state', $params, 'redirect url: ' . $url);
        $this->assertNotEmpty($params['state']);
        $this->assertNotEmpty($params['nonce']);
        // State: stored only as a short-lived browser cookie named after the state.
        $this->assertArrayHasKey('lti1p3_' . $params['state'], $cookie->written);
        $this->assertSame(60, $cookie->written['lti1p3_' . $params['state']]['exp']);
        // Nonce: stored only in the PHP session, mapped to the state.
        $this->assertSame($params['state'], $SESSION->enrol_lti_launch_nonce[$params['nonce']]);
        $this->assertCount(1, $cookie->written, 'nothing else, in particular no server-side store that survives without cookies');
        $this->assertNotEmpty($url);
    }

    /**
     * CE-G1 (d): the Moodle platform does not implement LTI platform storage, so a tool in a frame cannot
     * ask the platform to hold its state.
     */
    public function test_g1_core_platform_does_not_implement_platform_storage(): void {
        global $CFG;
        $needles = ['lti.get_data', 'lti.put_data', 'lti.capabilities', 'lti_storage_target', 'lti.get_data.response'];
        $root = $CFG->dirroot . '/mod/lti';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $scanned = 0;
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (!preg_match('/\.(php|js|mustache)$/', $path) || strpos($path, '/tests/') !== false) {
                continue;
            }
            $scanned++;
            $content = (string) file_get_contents($path);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $content, "$needle found in $path: CE-G1 must be re-evaluated.");
            }
        }
        $this->assertGreaterThan(50, $scanned, 'the scan must have covered mod_lti');
    }

    // CE-G2: can the PHP session cookie attributes be controlled per context before the headers?

    /**
     * CE-G2 (a): the session is started before any hook that a plugin can use is dispatched.
     */
    public function test_g2_session_starts_before_the_first_plugin_hook(): void {
        $setup = $this->core_source('lib/setup.php');
        $sessionstart = $this->position($setup, '\core\session\manager::start();');
        $afterconfig = $this->position($setup, 'new \core\hook\after_config()');
        $this->assertLessThan(
            $afterconfig,
            $sessionstart,
            'The session cookie parameters are fixed before after_config, the earliest hook for plugins.'
        );
    }

    /**
     * CE-G2 (b): the SameSite attribute of the session cookie is hard-coded and has no setting.
     */
    public function test_g2_session_cookie_samesite_is_hardcoded(): void {
        $manager = $this->core_source('lib/classes/session/manager.php');
        $this->assertStringContainsString("\$sessionoptions['samesite'] = 'Lax';", $manager);
        $this->assertStringNotContainsString('cookiesamesite', $manager);
        $this->assertDoesNotMatchRegularExpression("/samesite'\\]\s*=\s*'(?!Lax|None)/", $manager);
        // The only exception core makes is the Moodle mobile app.
        $this->assertStringContainsString('is_moodle_app()', $manager);
    }

    /**
     * CE-G2 (c): what IS possible - core's own header rewriting primitive, usable after the session has started.
     *
     * This is what auth_lti uses for LTI launches. The plugin proves the primitive works but does NOT apply
     * it site-wide (CE-P03).
     */
    public function test_g2_core_can_rewrite_a_named_session_cookie_header(): void {
        $headers = [
            'Set-Cookie: MoodleSessionabc=xyz; path=/; secure; HttpOnly; SameSite=Lax',
            'Set-Cookie: other=1; path=/',
            'X-Test: 1',
        ];
        $rewritten = \core\session\utility\cookie_helper::cookie_response_headers_add_attributes(
            $headers,
            ['MoodleSessionabc'],
            ['Partitioned']
        );
        $this->assertStringContainsString('Partitioned', $rewritten[0]);
        $this->assertSame($headers[1], $rewritten[1], 'only the named cookie is touched');
        $this->assertSame($headers[2], $rewritten[2]);
        $this->assertTrue(method_exists(\core\session\utility\cookie_helper::class, 'add_attributes_to_cookie_response_header'));
    }

    /**
     * CE-G2 (d): core already handles CHIPS for LTI launches: cookie check, SameSite=None, Secure, Partitioned.
     */
    public function test_g2_core_already_partitions_the_session_cookie_for_lti_launches(): void {
        $helper = \auth_lti\local\ltiadvantage\utility\cookie_helper::class;
        $this->assertTrue(defined($helper . '::COOKIE_METHOD_EXPLICIT_PARTITIONING'));
        $this->assertTrue(defined($helper . '::COOKIE_METHOD_NO_PARTITIONING'));
        $this->assertTrue(defined($helper . '::COOKIE_METHOD_NOT_SUPPORTED'));
        foreach (['do_cookie_check', 'cookies_supported', 'get_cookies_supported_method', 'setup_session_cookie'] as $method) {
            $this->assertTrue(method_exists($helper, $method), $method);
        }
        $source = $this->core_source('auth/lti/classes/local/ltiadvantage/utility/cookie_helper.php');
        $this->assertStringContainsString("'SameSite=None', 'Secure'", $source);
        $this->assertStringContainsString("'Partitioned'", $source);
        $state = $this->core_source('enrol/lti/classes/local/ltiadvantage/lib/lti_cookie.php');
        $this->assertStringContainsString("'samesite' => 'None'", $state);
        $this->assertStringContainsString("['Partitioned']", $state);
    }

    /**
     * CE-G2 (e): the cookie check reports "not supported" without a session flag.
     */
    public function test_g2_cookie_check_state_comes_from_the_session(): void {
        global $SESSION;
        $this->resetAfterTest();
        $helper = \auth_lti\local\ltiadvantage\utility\cookie_helper::class;
        unset($SESSION->auth_lti_cookie_method);
        $this->assertFalse($helper::cookies_supported());
        $SESSION->auth_lti_cookie_method = $helper::COOKIE_METHOD_EXPLICIT_PARTITIONING;
        $this->assertTrue($helper::cookies_supported());
        $this->assertSame($helper::COOKIE_METHOD_EXPLICIT_PARTITIONING, $helper::get_cookies_supported_method());
    }

    // CE-G3: CSP and X-Frame-Options without a core fork.

    /**
     * CE-G3 (a): X-Frame-Options is controlled by one site setting and sent by core after the plugin hook.
     */
    public function test_g3_x_frame_options_source_of_truth(): void {
        $weblib = $this->core_source('lib/weblib.php');
        $this->assertStringContainsString("@header('X-Frame-Options: sameorigin');", $weblib);
        $pattern = '/if \(empty\(\$CFG->allowframembedding\)[^{]*\{\s*@header\(.X-Frame-Options: sameorigin.\);/';
        $this->assertMatchesRegularExpression($pattern, $weblib);
    }

    /**
     * CE-G3 (b): core sends no Content-Security-Policy and has no setting for one.
     */
    public function test_g3_core_sends_no_csp(): void {
        $this->assertStringNotContainsString('Content-Security-Policy', $this->core_source('lib/weblib.php'));
        $this->assertStringNotContainsString('Content-Security-Policy', $this->core_source('lib/classes/output/core_renderer.php'));
        $this->assertStringNotContainsString('frame-ancestors', $this->core_source('lib/weblib.php'));
    }

    /**
     * CE-G3 (c): the order inside core_renderer::header(): before_http_headers, send_headers, after_http_headers.
     */
    public function test_g3_header_hooks_surround_send_headers(): void {
        $renderer = $this->core_source('lib/classes/output/core_renderer.php');
        $before = $this->position($renderer, 'new before_http_headers($this)');
        $send = $this->position($renderer, 'send_headers($this->contenttype, $this->page->cacheable);');
        $after = $this->position($renderer, 'new after_http_headers(');
        $this->assertLessThan($send, $before);
        $this->assertLessThan($after, $send);
    }

    /**
     * CE-G3 (d): both header hooks really fire, in that order, when a page renders its header.
     */
    public function test_g3_header_hooks_fire_in_order(): void {
        global $PAGE, $OUTPUT;
        $this->resetAfterTest();
        $this->setAdminUser();
        $calls = [];
        $manager = \core\di::get(\core\hook\manager::class);
        $manager->phpunit_redirect_hook(\core\hook\output\before_http_headers::class, static function () use (&$calls) {
            $calls[] = 'before';
        });
        $manager->phpunit_redirect_hook(\core\hook\output\after_http_headers::class, static function () use (&$calls) {
            $calls[] = 'after';
        });
        $PAGE = new \moodle_page();
        $PAGE->set_url('/local/ltixsembed/test.php');
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_pagelayout('base');
        $renderer = $PAGE->get_renderer('core', null, RENDERER_TARGET_GENERAL);
        $renderer->header();
        $manager->phpunit_stop_redirections();
        $this->assertSame(['before', 'after'], $calls);
        $this->assertNotNull($OUTPUT);
    }

    /**
     * CE-G3 (e): the plugin's own hook is among those the manager dispatches, so a plugin can act both before
     * the headers are sent (to add a header) and after (to inspect the page) - but cannot remove core's
     * X-Frame-Options before it is sent. The plugin does neither.
     */
    public function test_g3_plugin_adds_no_headers(): void {
        $files = array_merge(
            glob(__DIR__ . '/../classes/*.php'),
            glob(__DIR__ . '/../classes/*/*.php'),
            glob(__DIR__ . '/../*.php')
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![>:$\w])header\s*\(|header_remove\s*\(|setcookie\s*\(/',
                $content,
                basename($file) . ' must not emit headers or cookies.'
            );
        }
    }
}
