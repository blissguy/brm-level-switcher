=== BRM Level Switcher ===
Contributors: mixbusmarketing
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Switch the current user's BricksMembers level straight from the WordPress admin bar.

== Description ==

BRM Level Switcher is a developer/testing utility for sites running BricksMembers. It adds a "BRM Level" item to the admin bar showing your current level. Open the dropdown, pick any defined level (or "No level"), and the page reloads with that level applied to your account.

Switching uses the official BricksMembers public API (`brm_get_all_levels()`, `brm_get_user_levels()`, `brm_set_user_levels()`), so it stays in sync with the plugin's own data and caching.

Behaviour:

* Replace mode — the level you pick becomes your only level (others are cleared).
* Admins only — visible to users with `manage_options` by default (filter `brm_ls_required_cap`).
* Current user only — it never changes another account.
* Safe — every action is nonce-verified, and it does nothing if BricksMembers is inactive or no levels exist.

== Installation ==

1. Upload the `brm-level-switcher` folder to `/wp-content/plugins/`, or install the release ZIP via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the Plugins screen.
3. Make sure BricksMembers is active and has at least one level defined.
4. Look for "BRM Level" in the admin bar (front end and wp-admin).

== Frequently Asked Questions ==

= Does this change other users? =

No. It only ever changes the level of the currently logged-in user.

= Who can use it? =

By default only administrators (`manage_options`). Use the `brm_ls_required_cap` filter to change the required capability.

== Changelog ==

= 1.1.1 =
* Added a one-time cache-busting marker to the post-switch redirect so full-page caches serve a fresh render for the newly selected level. The marker is stripped from the address bar on load. Note: BricksMembers recommends not full-page caching membership pages / excluding logged-in users — this is a safety net, not a substitute for correct cache configuration.

= 1.1.0 =
* Added a settings page under the BricksMembers menu (falls back to Settings if that menu is unavailable).
* New option: multi-select mode — hold several levels at once, toggling each on/off (off by default = single-level replace).
* New option: choose which levels appear in the switcher dropdown.
* New option: choose which user roles may use the switcher (administrators always allowed; on-page warning for non-admin roles).
* Added a Settings action link on the Plugins screen.

= 1.0.0 =
* Initial release.
* Admin bar dropdown to switch the current user's BricksMembers level.
* Replace-mode switching via the BricksMembers public API.
* Capability gating (admins only by default), nonce verification, and graceful fallback when BricksMembers is inactive.
