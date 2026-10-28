local_ltixsembed
===================

[![Moodle Plugin CI](https://github.com/ralferlebach/moodle-local_ltixsembed/actions/workflows/moodle-plugin-ci-main.yml/badge.svg?branch=main)](https://github.com/ralferlebach/moodle-local_ltixsembed/actions?query=branch%3Amain)

Diagnosis and fallback for LTI 1.3 tools (typically a second Moodle) embedded in an iframe of another Moodle when the browser restricts third-party cookies: it detects the situation, tells the learner plainly, offers a Storage Access API permission button where the browser supports it, and otherwise a new top-level window that starts a fresh trusted launch. It does NOT make the embedded session independent of cookies (see below).

Status: **alpha, release 0.3.0** (version 2026102700). See docs/ACCEPTANCE.md for what is proven and what is not.

It works in three independent operating modes: **consumer-only** (installed and active on the embedding platform only),
**provider-only** (on the embedded tool only) and **cooperative** (on both; the two sides coordinate and never add a
redirect, sign-in, cookie or window that one side alone would not need).


Requirements
------------

Moodle 4.5 or 5.3 (`$plugin->requires = 2024100700`, supported 405 and 503), PHP 8.1+.


Motivation for this plugin
--------------------------

An LTI tool embedded cross-site loses its session cookie in browsers that block or partition third-party storage. Moodle core offers no extension point to move the OIDC state or nonce out of cookies and session (gate CE-G1) and no per-context control over session cookie attributes (CE-G2). A local plugin therefore cannot make the full iframe launch independent of such cookies. What it can do, and what this plugin does, is detect the situation by feature detection, tell the learner plainly, and offer a safe launch in a top-level window. docs/DOCUMENTATION.md has the evidence.


Branch policy
-------------

* `development` is the work branch: all development, fixes, refactoring and test additions happen there, and every
  acceptance statement refers to an exact `development` commit and its own CI run.
* `main` is the release branch. It is updated only by a deliberate pull request from `development` after functional
  acceptance by the owner and a green `development` CI; the full release CI then runs again on the exact `main` commit.
* No direct commits to `main`. Releases are tagged from `main`. Plugin versions are independent of the other family
  plugins (no lockstep).


Installation
------------

Install the plugin to `local/ltixsembed` and run the upgrade. See http://docs.moodle.org/en/Installing_plugins.
The plugin has no dependency on any other plugin. Install it on the platform site (consumer, where mod_lti embeds the tool), on the tool site (provider, where auth_lti or enrol_lti serves the launch), or on both.

**Upgrade from 0.2.0** (version 2026102502): replace the directory and run the upgrade. The upgrade step writes the role
switches explicitly (the operating mode does not change) and adds the setting "Bridge on the tool side" with the value
"Not required". Behaviour change: a platform whose tool never answers no longer reports a blocked frame (choose
"Required" to keep the 0.2.0 reading). On GitHub, `development` previously held only the uninitialised plugin
template; this implementation replaces it, there is nothing to migrate from the template.

**Renamed in 0.2.0, no upgrade path.** Release 0.2.0 is the first release under the name `local_ltixsembed`; the 0.1.0 alpha was
published under a different component name (see [docs/CHANGELOG.md](docs/CHANGELOG.md)). Moodle treats the two as different
plugins and cannot upgrade one into the other. On a site that runs the 0.1.0 alpha: uninstall the old plugin first (Site
administration -> Plugins -> Plugins overview -> Uninstall, or `php admin/cli/uninstall_plugins.php --plugins=<old component> --run`),
which deletes its settings, remove its directory, then install this plugin as a new plugin. Nothing is carried over.


Usage & Settings
----------------

After installing, both sides are switched on (each only acts on its own page types). Go to Site administration -> Plugins -> Local plugins -> LTI Cross-Site Embed:

* Platform side (consumer): bridge and notice on mod_lti view pages of activities shown in an iframe.
* Tool side (provider): bridge on auth_lti and enrol_lti launch pages and the login page (and, optionally, on all pages of LTI users).
* Both off: the plugin is inactive and adds nothing to any page (use this instead of uninstalling to switch a site off).
* Bridge on the tool side (platform setting): "Not required" (default, a silent tool is normal - consumer-only operation) or "Required" (silence means a blocked frame).
* Allowed origins: exact origins (scheme, host, port) of the partner site. No wildcards.
* Nonce lifetime, handshake timeout, fallback mode (suggest automatically or manual link only).
* Permission button (Storage Access API) on the tool side, on by default.

Two read-only administration pages: the browser capability test and the configuration audit. The audit reports, but never changes, `allowframembedding`, `cookiesecure`, the core cookie helper's partitioning support and the X-Frame-Options, CSP frame-ancestors and Set-Cookie attributes your site really emits (fetched from your own site with the SSRF-safe curl).


Capabilities
------------

* **local/ltixsembed:viewdiagnostics** - open the test and audit pages (RISK_CONFIG, manager by default).


Scheduled Tasks
---------------

This plugin does not add any additional scheduled tasks.


How this plugin works / Pitfalls
--------------------------------

* Operating modes: see docs/DOCUMENTATION.md, "Operating modes". The platform alone cannot see inside a tool that does not run the plugin; it then only offers the manual new-window option and never claims a problem.
* Capability detection (states embedded, storage_unavailable, session_lost, blocked_frame, needs_user_intervention) uses feature detection only: a cookie round trip, a Partitioned cookie round trip, localStorage, the Storage Access API presence and an LTI platform-storage probe. No fingerprinting, no data leaves the browser except the coarse state.
* The postMessage bridge checks origin (exact allow-list), source, envelope, type, channel nonce, context, expiry and replay on every message. Forged messages are ignored.
* The fallback is a POST (sesskey, capability, one-time token) that redirects to the fixed target `/mod/lti/launch.php?id=<cmid>`. No token or session id appears in a URL.
* Storage Access API recovery: in the state `needs_user_intervention` the tool-side notice offers a button; after the user's grant the platform restarts the launch (max. twice per page load). Verified here only with an emulated browser; real Firefox/Safari/iOS prompts are not verified.
* Boundary (CE-01): the session lifecycle of the embedded tool relies on the cookies core sets. In Chromium with real third-party cookie blocking these are partitioned (CHIPS) and the embedded launch works (tested). For browsers that give the frame no storage at all, no local plugin can replace core's OIDC state and session storage; the new-window fallback is the supported answer.
* The plugin never sets `SameSite=None` broadly, never rewrites session cookies and never asks anyone to change browser protection.
* A and B on the same registrable domain are same-site: browser cross-site blocking does not apply to them, so test with truly different sites.


Theme support
-------------

The plugin acts behind the scenes and should work with all themes. It is developed and tested on Boost.


Plugin repositories
-------------------

This plugin is not published in the Moodle plugins repository.


Family of LTI plugins
---------------------

This plugin belongs to a family. Each plugin is independently installable and they talk to each other only through Moodle events and read-only services:

* local_ltiidentity
* ltisource_ltipseudonym
* local_ltiaccountmerge
* local_ltixsembed (this plugin)
* tool_ltidiagnostics - reads this plugin's events (`fallback_launched`, `embed_state_reported`) through its `local_lti*` family prefix: correlation id, code, version
* local_lticompletionsync
* tool_ltigroupautoenrol (existing, separate)

None of them is a dependency; without tool_ltidiagnostics the events simply stay in the site log. Names: see docs/NAMING.md.


Bug and problem reports / Support requests
------------------------------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/ralferlebach/moodle-local_ltixsembed/issues

We will do our best to solve your problems, but please note that due to limited resources we can't always provide per-case support.


Feature proposals
-----------------

Due to limited resources, the functionality of this plugin is primarily implemented for our own local needs and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/ralferlebach/moodle-local_ltixsembed/issues

Please create pull requests on Github:
https://github.com/ralferlebach/moodle-local_ltixsembed/pulls

We are always interested to read about your feature proposals or even get a pull request from you, but please accept that we can handle your issues only as feature _proposals_ and not as feature _requests_.


Moodle release support
----------------------

Due to limited resources, this plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

This plugin is designed to be compatible with all currently supported versions of Moodle, leveraging its latest APIs. However, if you are using a legacy version of Moodle, we kindly advise against installing or using this plugin. Instead, we strongly recommend updating your Moodle instance to a supported version to ensure security and compliance with current technological standards. Thank you for your understanding.


Translating this plugin
-----------------------

This Moodle plugin is provided with English and German language packs only. Translations into other languages must be managed through AMOS (https://lang.moodle.org), where they will become part of Moodle's official language pack.

As the plugin creator, we continue to maintain the German translation. For all other languages, we kindly ask you to contribute your translations directly in AMOS. These contributions will be reviewed by Moodle's official language pack maintainers before being included in the official repository.

Thank you for supporting the global Moodle community!


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Maintainers
-----------

The plugin is maintained by\
Ralf Erlebach (or any other given author or institution)


Copyright
---------

The copyright of this plugin is held by\
Ralf Erlebach (or any other given author or institution)

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.
