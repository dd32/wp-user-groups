# User Teams

[![Tests](https://github.com/dd32/wp-user-teams/actions/workflows/test.yml/badge.svg?branch=trunk)](https://github.com/dd32/wp-user-teams/actions/workflows/test.yml)

Unix-style user groups for WordPress multisite, surfaced in the admin as "Teams". Define a team, attach a role, add users to it — members inherit the role everywhere the team applies.

**Multisite-only by design.** A single team can span selected sites or the entire network, so a "Meta Team" or "Playground Contributors" team doesn't need per-site setup. On a single-site install, native roles and capabilities already cover the whole feature set, so the plugin is marked `Network: true` and WordPress won't offer it for activation.

## Why

WordPress roles and capabilities are per-site. On a large multisite network, granting a team access across 50 sites usually means adding each user to each site by hand — and remembering to remove them everywhere when they leave. This plugin turns that into:

1. Create a team, pick a role.
2. Add the user to the team.
3. They have that role everywhere the team applies.
4. Remove them from the team → access is gone everywhere.

## Features

- **Teams with roles.** Each team is associated with a WordPress role (editor, administrator, a custom role, or nothing).
- **Runtime capability grants.** Access is granted through the `user_has_cap` filter, so removing a user from a team drops their access on the next request — no stale `{prefix}capabilities` usermeta to clean up.
- **Multisite-native.** Teams are real `wp_users` rows ("team accounts"), so `WP_Users_List_Table`, `is_user_member_of_blog()`, and column-adding plugins "just work". Memberships live in `wp_usermeta`, which is global on multisite.
- **Site scoping.** A team can apply to every site on the network (including sites added later) or a curated list of sites.
- **Admin UI.** Network Admin → Users → Teams. Per-user checkboxes on the Edit User screen. A "Teams" column on the Network Admin Users list.
- **Cleans up after itself.** Deleting a team removes its memberships. Deleting a user drops their memberships. Deleting a site removes it from any team that targeted it.

## Data model

Teams are stored natively, no custom tables:

| Storage | Purpose |
|---|---|
| `wp_users` row (team account) | One row per team. `display_name` = team name, `user_login` = `_team_{slug}`, marked by `wput_is_team = '1'` meta. Login and password reset are blocked. |
| `wput_slug`, `wput_global_role` user meta | Per-team slug and the role granted network-wide (if any). |
| `wp_{blog_id}_capabilities` on the team row | Per-site role grants. Storing them natively on the team account means `is_user_member_of_blog()` and `WP_Users_List_Table` recognise team coverage without extra plumbing. |
| `wp_user_teams` meta on the member user | Array of team IDs the user belongs to. `wp_usermeta` is global on multisite, so a user's memberships follow them across sites. |

"Which teams is user X in?" is a single `get_user_meta()` call. "Which users are in team Y?" uses the indexed `meta_key` lookup on `wp_usermeta`.

## How access is granted

Team-derived capabilities are merged in at runtime through three filters:

| Filter | Effect |
|---|---|
| `user_has_cap` | Adds the team role's capabilities to the user's effective caps, plus `role-{slug}` for plugins that check by role. |
| `get_blogs_of_user` | Adds team-linked sites to the user's "My Sites" navigation. |
| `get_user_metadata` | Short-circuits the `{prefix}{blog_id}_capabilities` read with an empty array so `is_user_member_of_blog()` returns `true` for team members without stamping real capabilities onto the user. This is a workaround for [Core #65096](https://core.trac.wordpress.org/ticket/65096) — `is_user_member_of_blog()` has no filter. |

The user's actual `{prefix}capabilities` usermeta is never modified. This is what makes team-based access instantly revocable.

## Installation

1. Drop the `wp-user-teams` directory into `wp-content/plugins/`.
2. Activate it.
   - On multisite, **network-activate** the plugin (it's marked `Network: true`).

## Usage

### Creating a team

**Network Admin → Users → Teams → Add New**:

- **Name** — display name, e.g. "WordPress Meta Team".
- **Slug** — optional; auto-generated if blank.
- **Role granted** — any registered WordPress role. Leaving it blank means the team tracks membership but grants no extra capabilities.
- **Sites** (multisite only) — *All sites* or a specific list.

### Adding users to teams

Edit a user's profile. The **User Teams** section lists every defined team with a checkbox.

### Programmatic API

```php
use dd32\WordPress\UserTeams\Plugin;

// Create a team.
$id = Plugin::create_team( 'Meta Team', 'meta-team', 'editor' );

// Scope it to specific sites, optionally with per-site role overrides.
Plugin::set_team_sites( $id, array( 1 => 'editor', 4 => 'author' ) );

// Add / remove members.
Plugin::add_user_to_team( $user_id, $id );
Plugin::remove_user_from_team( $user_id, $id );
Plugin::set_user_teams( $user_id, array( $id, $other_id ) );

// Inspect.
Plugin::get_user_teams( $user_id );        // full team records, keyed by ID
Plugin::get_user_team_ids( $user_id );     // just the IDs
Plugin::get_team_members( $id );           // user IDs in this team
Plugin::count_members_per_team();          // [ team_id => count ]
```

### Extension filters

| Filter | Use |
|---|---|
| `wput_team_caps_for_user( $caps, $user_id, $blog_id )` | Modify the capability map computed from a user's teams. |
| `wput_team_applies_to_site( $applies, $team_id, $blog_id, $team )` | Gate coverage (e.g. pause a team during a freeze). |
| `wput_team_save_data( $data, $team_id_or_null, $op )` | Filter sanitised input on create/update. |

### Checking access

Nothing special — team-derived caps participate in the normal cap system:

```php
if ( user_can( $user_id, 'edit_others_posts' ) ) {
    // ...
}
```

## Capability requirements

| Action | Required cap |
|---|---|
| Manage teams (create / edit / delete) | `manage_network_users` (super admin) |
| Attach a team to a specific site | `promote_users` on that site |

Team management is restricted to super admins so that a compromised single-site administrator can't grant themselves access across the network. Attaching an existing team to the current site, on the other hand, is as trusted an action as inviting an individual user there, and uses the same capability (`promote_users`).

## Development

This repository is set up with [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) for a local WordPress environment and PHPUnit for tests.

### Prerequisites

- Docker (for wp-env)
- Node.js 18+
- Composer (installed automatically inside the wp-env container)

### Getting started

```bash
npm install
npm run start
```

That spins up a WordPress instance with this plugin active.

### Running tests

```bash
npm run test
```

All tests execute inside the wp-env `tests-cli` container against a real WordPress install, using [Yoast PHPUnit Polyfills](https://github.com/Yoast/PHPUnit-Polyfills) for cross-version assertion compatibility.

The test suite lives in [`tests/`](./tests) and covers:

- `Test_Teams.php` — team CRUD, slug uniqueness, validation.
- `Test_Membership.php` — add/remove/set memberships, idempotency, cascading deletes when teams or users go away.
- `Test_Capabilities.php` — the `user_has_cap` filter grants and revokes caps correctly; multi-team merging; role changes propagate.
- `Test_Multisite.php` — site-scoping, `get_blogs_of_user`, cleanup on site deletion.
- `Test_Admin.php` — integration tests that drive the admin form handlers end-to-end via `$_POST`/`$_REQUEST`.
- `Test_User_Query.php` — the `pre_user_query` / `views_users` / `get_role_list` hooks that surface teams and team members on `wp-admin/users.php`.

## Requirements

- WordPress 6.9+
- PHP 7.4+

## License

GPL-2.0-or-later.
