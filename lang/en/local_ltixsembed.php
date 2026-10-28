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

/**
 * English strings for local_ltixsembed.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['audit_authlti_disabled'] = 'The authentication plugin LTI is not enabled; this site cannot provision users for embedded launches.';
$string['audit_chips_core_missing'] = 'The core cookie helper for partitioned LTI cookies was not found; launches in third-party frames cannot be supported.';
$string['audit_chips_core_ok'] = 'Core provides the SameSite=None; Secure; Partitioned handling that LTI launches use (auth_lti cookie helper).';
$string['audit_cookie_httponly_missing'] = 'The session cookie is sent without HttpOnly.';
$string['audit_cookie_lax'] = 'The session cookie uses SameSite={$a}, as Moodle sets it for ordinary requests. LTI launches upgrade it on their own.';
$string['audit_cookie_none_global'] = 'The session cookie is sent with SameSite=None ({$a}) on an ordinary page. Core does this only for LTI launches; something rewrites session cookies site-wide. A blanket SameSite=None weakens CSRF protection.';
$string['audit_cookie_none_insecure'] = 'The session cookie is sent with SameSite=None but without Secure. Browsers reject it; fix the proxy or plugin that rewrites it.';
$string['audit_cookie_not_seen'] = 'No session cookie was set on the anonymous response, so its attributes could not be checked.';
$string['audit_cookiesecure_off'] = 'The site uses https but "Use secure cookies only" (cookiesecure) is off. Turn it on in Site administration > Security > HTTP security.';
$string['audit_cookiesecure_ok'] = 'Secure cookies are enabled (cookiesecure).';
$string['audit_csp_none'] = 'No Content-Security-Policy frame-ancestors directive is sent.';
$string['audit_csp_none_blocks'] = 'Content-Security-Policy frame-ancestors is \'none\': no site may embed this one.';
$string['audit_csp_ok'] = 'Content-Security-Policy frame-ancestors covers the configured origins: {$a}';
$string['audit_csp_partial'] = 'Content-Security-Policy frame-ancestors does not list these configured origins: {$a}';
$string['audit_csp_wildcard'] = 'Content-Security-Policy frame-ancestors contains *: every site may embed this one.';
$string['audit_facts'] = 'Effective settings';
$string['audit_fetch_blocked'] = 'The header self-check was not run: this site\'s own address is blocked by Moodle\'s cURL security (Site administration > General > Security > HTTP security). Nothing was contacted. Leave the block list as it is if it is intended; check the X-Frame-Options and Content-Security-Policy headers in the browser\'s developer tools instead.';
$string['audit_fetch_failed'] = 'The response headers could not be read ({$a}). Header checks were skipped.';
$string['audit_fetchnote'] = 'The headers were read from this site\'s own login page with an anonymous request. Moodle\'s cURL security settings apply; if they block the request the header checks are skipped.';
$string['audit_findings'] = 'Findings';
$string['audit_framing_allowed'] = '"Allow frame embedding" is on: pages of this site may be shown inside other sites. Needed on a site that acts as an LTI tool.';
$string['audit_framing_blocked'] = '"Allow frame embedding" is off: this site cannot act as an embedded LTI tool. Enable it only on a site that is meant to be embedded.';
$string['audit_httponly_off'] = 'Session cookies are not HttpOnly (cookiehttponly). Scripts could read them.';
$string['audit_httponly_ok'] = 'Session cookies are HttpOnly.';
$string['audit_https_missing'] = 'The site address does not use https. Embedding across sites needs Secure cookies and therefore https.';
$string['audit_https_ok'] = 'The site address uses https.';
$string['audit_intro'] = 'Read-only report of the settings and response headers that decide whether this site can be embedded in, or can embed, another site. Nothing here changes a setting.';
$string['audit_level_error'] = 'Problem';
$string['audit_level_info'] = 'Info';
$string['audit_level_ok'] = 'OK';
$string['audit_level_warning'] = 'Warning';
$string['audit_origins_none'] = 'No trusted peer origins are configured, so the message bridge stays inactive. The new-window option still works.';
$string['audit_title'] = 'Embedding configuration audit';
$string['audit_xfo_absent'] = 'The response carries no X-Frame-Options header.';
$string['audit_xfo_conflict'] = 'The response carries X-Frame-Options "{$a}" although "Allow frame embedding" is on. A proxy or web server setting overrides Moodle and prevents embedding.';
$string['audit_xfo_present'] = 'The response carries X-Frame-Options "{$a}": the site refuses to be framed by other sites.';
$string['cachedef_consumed'] = 'Consumed one-time tokens of the new-window option (hashes only)';
$string['cachedef_reports'] = 'Rate limit of the embedding problem reports (per session)';
$string['consumer_whattodo'] = 'You do not have to change any browser setting. Use the button below to open the tool in its own window; your work there counts as usual.';
$string['diag_feature'] = 'Check';
$string['diag_intro'] = 'This page tests, in the browser you are using right now, whether a page can keep a session when it is shown inside another site. Open it directly for the top-level result; the result inside a frame appears on LTI pages that carry the bridge.';
$string['diag_mode'] = 'Role of this site';
$string['diag_mode_help'] = 'Consumer-only, provider-only and cooperative operation follow from these switches: a platform enables the platform side, a tool enables the tool side, and two sites cooperate when both sides are enabled on the respective site. Each side works on its own; together they never add a second redirect, sign-in or window.';
$string['diag_noscript'] = 'JavaScript is required for this test.';
$string['diag_recommend_none'] = 'No action needed.';
$string['diag_recommend_open'] = 'Offer the new-window option.';
$string['diag_recommendation'] = 'Recommendation';
$string['diag_requestaccess'] = 'Request storage access (browser prompt)';
$string['diag_requestaccess_help'] = 'Diagnostic only. The new-window option is the supported route; the plugin does not depend on this prompt.';
$string['diag_result'] = 'Result';
$string['diag_title'] = 'Browser capability test';
$string['diag_value'] = 'Value';
$string['error_nonce'] = 'The request for a new window is no longer valid. Reload the activity page and try again.';
$string['error_origin_invalid'] = 'Not a valid origin: {$a}. Use https://host or https://host:port without a path.';
$string['error_origin_toomany'] = 'At most {$a} origins are allowed.';
$string['error_postrequired'] = 'This action must be sent as a form (POST).';
$string['event_embed_state_reported'] = 'Embedding problem reported by the browser';
$string['event_fallback_launched'] = 'LTI activity opened in a top-level window';
$string['fallback_backtoactivity'] = 'Back to the activity';
$string['fallbackmode_auto'] = 'Try automatically, keep the suggestion as backup';
$string['fallbackmode_suggest'] = 'Suggest only';
$string['feat_cookie'] = 'Cookie write and read-back';
$string['feat_cookie_partitioned'] = 'Partitioned cookie (CHIPS) round-trip';
$string['feat_framed'] = 'Shown inside a frame';
$string['feat_hassa'] = 'Storage Access API: document.hasStorageAccess()';
$string['feat_localstorage'] = 'Local storage usable';
$string['feat_platformstorage'] = 'LTI platform storage (postMessage)';
$string['feat_requestsa'] = 'Storage Access API: document.requestStorageAccess()';
$string['feat_session'] = 'Session hint';
$string['ltixsembed:viewdiagnostics'] = 'View the LTI embedding diagnostics and configuration audit';
$string['manualfallback_button'] = 'Open the tool in a new window';
$string['manualfallback_help'] = 'The tool opens in a new window and starts its ordinary secure sign-in there. You do not have to change any browser setting.';
$string['manualfallback_summary'] = 'Trouble loading the tool? Open it in a new window';
$string['mode_both'] = 'Platform side and tool side enabled';
$string['mode_consumer'] = 'Platform side only (consumer-only)';
$string['mode_inactive'] = 'Inactive: nothing is added to any page';
$string['mode_provider'] = 'Tool side only (provider-only)';
$string['page_audit'] = 'Embedding configuration audit';
$string['page_diagnostics'] = 'Browser capability test';
$string['peerbridge_auto'] = 'Not required: a silent tool is normal';
$string['peerbridge_required'] = 'Required: a silent tool means a blocked frame';
$string['pluginname'] = 'LTI Cross-Site Embed';
$string['privacy:metadata'] = 'The LTI Cross-Site Embed plugin keeps no personal data in storage of its own. Its browser checks run in the page; one-time tokens are kept only as a hash without any user reference. When an embedded tool cannot be used, the plugin triggers an event that the site\'s log stores record (user, a status word and a random attempt number); those log stores export and delete it.';

$string['privacynote'] = 'This check runs in your browser. Only a short status word is exchanged between this page and the embedded tool. If the tool cannot be used here, that status word and a random attempt number are recorded in the site log so that support can see what happened; nothing about your browser or browsing is recorded.';
$string['provider_hint'] = 'If this page stays empty or reports a problem, go back to your course and open the activity again; if your course page offers "Open the tool in a new window", use that option.';
$string['provider_hint_coop'] = 'The page that contains this tool knows about the problem and offers to open the tool in its own window.';
$string['setting_allowedorigins'] = 'Trusted peer origins';
$string['setting_allowedorigins_desc'] = 'One origin per line, for example <code>https://tool.example.org</code>. On a platform these are the origins of the embedded tools, on a tool the origins of the embedding platforms. Exact matches only: no wildcards, no paths, https only (http only on development sites that themselves use http). Messages from any other origin are ignored.';
$string['setting_consumerenabled'] = 'Platform side (mod_lti view page)';
$string['setting_consumerenabled_desc'] = 'Add the bridge and the "open in a new window" option to LTI activity pages that show the tool in an iframe.';
$string['setting_fallbackmode'] = 'Fallback behaviour';
$string['setting_fallbackmode_desc'] = 'Suggest: show a notice with a button. Automatic: additionally try to open the new window at once; browsers may block that, the notice stays as the backup.';
$string['setting_handshaketimeout'] = 'Tool response timeout (seconds)';
$string['setting_handshaketimeout_desc'] = 'How long the platform page waits for the embedded tool to answer before it suggests the new-window option.';
$string['setting_noncelifetime'] = 'One-time token lifetime (seconds)';
$string['setting_noncelifetime_desc'] = 'How long the token that authorises the new-window fallback stays valid. Between 30 and 3600.';
$string['setting_peerbridge'] = 'Bridge on the tool side';
$string['setting_peerbridge_desc'] = 'Platform side only. Not required (default): the embedded tool may not run this plugin, or may stay silent after a successful launch, so the platform draws no conclusion from silence and suggests nothing; the manual new-window option stays available (consumer-only operation). Required: choose only when every tool listed under trusted peer origins runs this plugin with "Tool side: pages of LTI users" switched on; then silence until the timeout is reported as a blocked frame.';
$string['setting_providerenabled'] = 'Tool side (LTI launch pages)';
$string['setting_providerenabled_desc'] = 'Add the bridge to the pages of the LTI launch flow (auth_lti, enrol_lti) when they are shown inside a frame.';
$string['setting_providerltipages'] = 'Tool side: pages of LTI users';
$string['setting_providerltipages_desc'] = 'Also add the bridge to ordinary pages of users who signed in through LTI. Off by default; switch it on to see the "embedded and working" state after a successful launch.';
$string['setting_storageaccess'] = 'Offer a permission button (Storage Access API)';
$string['setting_storageaccess_desc'] = 'Where the browser supports it and the embedded tool cannot keep a session, show a button in the tool. The browser asks the user for permission; after a grant the platform restarts the launch. Nothing is switched off, and browsers that lack the API only get the new-window option.';
$string['settings_heading'] = 'LTI Cross-Site Embed settings';
$string['settings_intro'] = 'Controls where the bridge between an embedding platform and an embedded tool is active. The plugin never changes Moodle security settings and never asks users to change their browser protection.';

$string['state_blocked_frame'] = 'Frame blocked or tool not responding';
$string['state_blocked_frame_msg'] = 'The embedded tool did not answer. Usually the tool refuses to be shown inside another site (a security setting of the tool), or a network problem stopped it.';
$string['state_embedded'] = 'Embedded and working';
$string['state_needs_user_intervention'] = 'User permission needed';
$string['state_needs_user_intervention_msg'] = 'Your browser keeps embedded sites separate from each other. The tool can only keep you signed in inside this page if you allow it in the tool area below.';
$string['state_no_peer'] = 'Tool without bridge (normal when the tool does not run this plugin)';
$string['state_session_lost'] = 'Session lost';
$string['state_session_lost_msg'] = 'The embedded tool has signed you out, for example because its session expired.';
$string['state_storage_unavailable'] = 'Storage unavailable';
$string['state_storage_unavailable_msg'] = 'Your browser does not let embedded sites keep you signed in (cookies of embedded sites are blocked). This protects your privacy and does not need to be changed.';

$string['state_top_level'] = 'Top-level window';
$string['storageaccess_button'] = 'Allow this tool to keep your session';
$string['storageaccess_denied'] = 'Your browser did not grant permission. Use the option to open the tool in a new window instead.';
$string['storageaccess_granted'] = 'Permission granted. The tool is being started again.';
$string['storageaccess_granted_standalone'] = 'Permission granted. Go back to your course and open the activity again.';
$string['storageaccess_help'] = 'Your browser will ask for permission. It is only used so that this tool can keep you signed in inside this page; no browser protection is switched off.';

$string['value_available'] = 'available';
$string['value_lost'] = 'lost';
$string['value_na'] = 'not applicable';
$string['value_no'] = 'no';
$string['value_ok'] = 'ok';
$string['value_pending'] = 'testing...';
$string['value_unavailable'] = 'not available';
$string['value_unknown'] = 'unknown';
$string['value_yes'] = 'yes';
