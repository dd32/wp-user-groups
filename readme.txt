=== User Teams ===
Contributors: dd32
Tags: users, roles, multisite, team, permissions
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unix-style user groups for WordPress multisite. Create a team, give it a role, drop users in — access lights up across the network.

== Description ==

On a large multisite network, granting a group of people the same role across many sites usually means adding each user to each site by hand — and remembering to remove them everywhere when they leave. User Teams turns that into:

1. Create a team, pick a role.
2. Add the user to the team.
3. They have that role everywhere the team applies.
4. Remove them from the team → access is gone everywhere.

User Teams is multisite-only by design — on a single-site install, native WordPress roles and capabilities already cover the whole feature set, so WordPress won't offer it for activation there.

= Features =

* **Teams with roles.** Each team carries a WordPress role (editor, administrator, a custom role, or none).
* **Runtime capability grants.** Team access is added via the `user_has_cap` filter, so removing a user from a team drops their access on the next request — no stale capabilities usermeta to clean up.
* **Multisite-native.** Team definitions are real `wp_users` rows ("team accounts"), so `WP_Users_List_Table`, `is_user_member_of_blog()`, and every column-adding plugin "just work".
* **Site scoping.** A team can apply network-wide (including sites added later) or to a specific list of sites, each with its own role.
* **Admin UI.** Network Admin → Users → Teams. Per-user checkboxes on the Edit User screen. Team rows with "— Team" markers on the Users list table.
* **Cleans up after itself.** Deleting a team removes its memberships. Deleting a user drops their memberships. Deleting a site removes its grants from any team that targeted it.

= How access is granted =

Team-derived capabilities are merged in at runtime, never written into the member user's own meta:

* `user_has_cap` — adds the team role's capabilities to the user's effective caps, plus `role-{slug}` for plugins that check by role.
* `get_blogs_of_user` — adds team-linked sites to the user's "My Sites" navigation.
* `get_user_metadata` — returns an empty-array `{prefix}{blog_id}_capabilities` for team members so `is_user_member_of_blog()` returns true without stamping real capabilities on the user. Workaround for [Core #65096](https://core.trac.wordpress.org/ticket/65096).

The user's actual `{prefix}capabilities` usermeta is never modified. That's what makes team-based access instantly revocable.

= Capability requirements =

* Managing teams (create / edit / delete) requires `manage_network_users` — super admin only.
* Attaching an existing team to the current site uses `promote_users`, the same cap as inviting an individual user there.

Team management is restricted to super admins so a compromised single-site admin can't grant themselves access across the network.

= Extension filters =

* `wput_team_caps_for_user( $caps, $user_id, $blog_id )` — modify the capability map computed from a user's teams.
* `wput_team_applies_to_site( $applies, $team_id, $blog_id, $team )` — gate coverage (e.g. pause a team during a freeze).
* `wput_team_save_data( $data, $team_id_or_null, $op )` — filter sanitised input on create/update.

== Installation ==

1. Drop the `wp-user-teams` directory into `wp-content/plugins/`.
2. Network-activate it. The plugin is marked `Network: true` and won't offer activation on single-site installs.

== Frequently Asked Questions ==

= Does this work on a single-site install? =

No. On a single-site install, native roles and capabilities already cover the whole feature set, so the plugin is marked `Network: true` and WordPress won't offer it for activation.

= What happens to a user's role when they're removed from a team? =

Nothing is persisted on the user, so removing them from the team drops the team-derived capabilities on the next request. The user's own native role on each site is untouched.

= Where are teams stored? =

Each team is a real `wp_users` row (a "team account") marked with `wput_is_team` meta. Per-site role grants use native `wp_{blog_id}_capabilities` meta on that row. Memberships are on the member user's own `wp_user_teams` meta. No custom tables.

= How do I create a team programmatically? =

`
use dd32\WordPress\UserTeams\Plugin;

$id = Plugin::create_team( 'Meta Team', 'meta-team', 'editor' );
Plugin::set_team_sites( $id, array( 1 => 'editor', 4 => 'author' ) );
Plugin::add_user_to_team( $user_id, $id );
`

Checking access uses the normal cap system — team-derived caps participate via `user_has_cap`:

`
if ( user_can( $user_id, 'edit_others_posts' ) ) {
    // ...
}
`

= Where's development happening? =

On GitHub at [dd32/wp-user-teams](https://github.com/dd32/wp-user-teams). That's where the source, issues, pull requests, and release tags live.

= I have a bug report or feature request =

File it on [GitHub](https://github.com/dd32/wp-user-teams/issues). General support questions belong in the [WordPress.org Support Forums](https://wordpress.org/support/plugin/user-teams/).

== Changelog ==

= 0.1.0 =
* Initial release.
