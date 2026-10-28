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
 * Typed access to the plugin settings with safe defaults.
 *
 * Every consumer of a setting goes through this class so that a missing or tampered value can never
 * widen what the plugin does: out-of-range numbers are clamped, unknown choices fall back to the
 * conservative default.
 *
 * @package    local_ltixsembed
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings {
    /** @var string Suggest the top-level window and let the user decide (default). */
    public const FALLBACK_SUGGEST = 'suggest';

    /** @var string Try to open the top-level window automatically; the suggestion stays as backup. */
    public const FALLBACK_AUTO = 'auto';

    /** @var string Silence of the embedded tool is normal (it may not run this plugin). Default. */
    public const PEER_AUTO = 'auto';

    /** @var string Every embedded tool runs the bridge: silence means the frame was blocked. */
    public const PEER_REQUIRED = 'required';

    /** @var string Neither role is enabled: the plugin adds nothing to any page. */
    public const MODE_INACTIVE = 'inactive';

    /** @var string Only the platform side (mod_lti view pages) is enabled. */
    public const MODE_CONSUMER = 'consumer';

    /** @var string Only the tool side (LTI launch pages) is enabled. */
    public const MODE_PROVIDER = 'provider';

    /** @var string Both sides are enabled on this site. */
    public const MODE_BOTH = 'both';

    /** @var int Shortest lifetime of a one-time token in seconds. */
    public const NONCE_MIN = 30;

    /** @var int Longest lifetime of a one-time token in seconds (must stay below the cache ttl). */
    public const NONCE_MAX = 3600;

    /** @var int Default lifetime of a one-time token in seconds. */
    public const NONCE_DEFAULT = 600;

    /**
     * Read a plugin setting.
     *
     * @param string $name Setting name.
     * @return mixed The stored value or false when unset.
     */
    private static function raw(string $name) {
        return get_config('local_ltixsembed', $name);
    }

    /**
     * Whether the bridge is added to mod_lti view pages (this site is the platform).
     *
     * @return bool
     */
    public static function consumer_enabled(): bool {
        $value = self::raw('consumerenabled');
        return $value === false ? true : !empty($value);
    }

    /**
     * Whether the bridge is added to the auth_lti / enrol_lti launch pages (this site is the tool).
     *
     * @return bool
     */
    public static function provider_enabled(): bool {
        $value = self::raw('providerenabled');
        return $value === false ? true : !empty($value);
    }

    /**
     * Whether, on the tool side, the bridge is also added to ordinary pages of users who signed in through LTI.
     *
     * Off by default: the specification asks for injection on the launch pages only. Turning it on is
     * what makes the "embedded and working" state observable after a successful launch.
     *
     * @return bool
     */
    public static function provider_lti_user_pages(): bool {
        return !empty(self::raw('providerltipages'));
    }

    /**
     * The configured peer origins (tool origins on a platform, platform origins on a tool).
     *
     * Invalid lines are dropped silently here; the settings form reports them when saving.
     *
     * @return string[] Canonical origins, unique, in configured order.
     */
    public static function allowed_origins(): array {
        $raw = self::raw('allowedorigins');
        return origins::parse(is_string($raw) ? $raw : '')['valid'];
    }

    /**
     * Lifetime of a one-time token in seconds.
     *
     * @return int
     */
    public static function nonce_lifetime(): int {
        $value = (int) self::raw('noncelifetime');
        if ($value <= 0) {
            return self::NONCE_DEFAULT;
        }
        return max(self::NONCE_MIN, min(self::NONCE_MAX, $value));
    }

    /**
     * Seconds the platform page waits for the embedded tool to answer before it reports a blocked frame.
     *
     * @return int
     */
    public static function handshake_timeout(): int {
        $value = (int) self::raw('handshaketimeout');
        if ($value <= 0) {
            return 8;
        }
        return max(2, min(60, $value));
    }

    /**
     * Whether the tool side offers the Storage Access API button when the browser would allow a user grant.
     *
     * On by default: the button only appears in the state needs_user_intervention, the browser itself asks the
     * user, and nothing is switched off. Administrators can turn it off.
     *
     * @return bool
     */
    public static function storage_access_recovery(): bool {
        $value = self::raw('storageaccess');
        return $value === false ? true : !empty($value);
    }

    /**
     * The fallback mode.
     *
     * @return string One of the FALLBACK_* constants.
     */
    public static function fallback_mode(): string {
        return self::raw('fallbackmode') === self::FALLBACK_AUTO ? self::FALLBACK_AUTO : self::FALLBACK_SUGGEST;
    }

    /**
     * What a platform concludes when the embedded tool never speaks the bridge protocol.
     *
     * PEER_AUTO (default): silence is normal - the tool may simply not run this plugin (consumer-only operation), or
     * may only say hello on its launch pages and stay quiet after a successful launch. Nothing is suggested and
     * nothing is opened; the manual new-window option stays available.
     * PEER_REQUIRED: the administrator declares that every configured tool runs this plugin with the bridge on all
     * pages of LTI users ("providerltipages"), so silence means the frame was blocked.
     *
     * @return string One of the PEER_* constants.
     */
    public static function peer_bridge(): string {
        return self::raw('peerbridge') === self::PEER_REQUIRED ? self::PEER_REQUIRED : self::PEER_AUTO;
    }

    /**
     * The operating mode of this site, derived from the two role switches.
     *
     * Consumer-only, provider-only and cooperative operation are not separate settings: a site is a platform, a tool,
     * or both, and two sites cooperate when the platform runs the consumer side and the tool the provider side.
     * "inactive" means the plugin adds nothing to any page.
     *
     * @return string One of the MODE_* constants.
     */
    public static function site_mode(): string {
        $consumer = self::consumer_enabled();
        $provider = self::provider_enabled();
        if ($consumer && $provider) {
            return self::MODE_BOTH;
        }
        if ($consumer) {
            return self::MODE_CONSUMER;
        }
        return $provider ? self::MODE_PROVIDER : self::MODE_INACTIVE;
    }
}
