# User Teams

[![Tests](https://github.com/dd32/wp-user-teams/actions/workflows/test.yml/badge.svg?branch=trunk)](https://github.com/dd32/wp-user-teams/actions/workflows/test.yml)

Unix-style user groups for WordPress, surfaced in the admin as "Teams". Define a team, attach a role, add users to it — members inherit the role everywhere the team applies.

Works on single-site WordPress and multisite networks. On multisite a single team can span selected sites or the entire network, so a "Meta Team" or "Playground Contributors" team doesn't need per-site setup.

## Why

WordPress roles and capabilities are per-site. On a large multisite network, granting a team access across 50 sites usually means adding each user to each site by hand — and remembering to remove them everywhere when they leave. This plugin turns that into:

1. Create a team, pick a role.
2. Add the user to the team.
3. They have that role everywhere the team applies.
4. Remove them from the team → access is gone everywhere.

## Features

- **Teams with roles.** Each team is associated with a WordPress role (editor, administrator, a custom role, or nothing).
- **Runtime capability grants.** Access is granted through the `user_has_cap` filter, so removing a user from a team drops their access on the next request — no stale `{prefix}capabilities` usermeta to clean up.
- **Multisite-native.** Team definitions live in a single network-level site option; memberships live in `wp_usermeta`, which is global on multisite. No `switch_to_blog` juggling.
- **Site scoping.** A team can apply to every site on the network (including sites added later) or a curated list of sites.
- **Admin UI.** Users → Teams (single site) or Network Admin → Users → Teams (multisite). Per-user checkboxes on the Edit User screen. A "Teams" column on the Users list table.
- **Cleans up after itself.** Deleting a team removes its memberships. Deleting a user drops their memberships. Deleting a site removes it from any team that targeted it.

## Data model

Two WordPress primitives, both cached by the object cache:

| Storage | Purpose |
|---|---|
| `wp_user_teams` site option | Map of team ID → `{ id, name, slug, role, sites }`. `get_site_option()` is per-network on multisite, per-install on single site. |
| `wp_user_teams` user meta | Array of team IDs the user belongs to. `wp_usermeta` is global on multisite, so a user's memberships follow them across sites. |

"Which teams is user X in?" is a single `get_user_meta()` call — served from the metadata cache after the first hit. "Which users are in team Y?" uses the indexed `meta_key` lookup on `wp_usermeta`.

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

**Users → Teams → Add New** (on single site) or **Network Admin → Users → Teams → Add New** (on multisite):

- **Name** — display name, e.g. "WordPress Meta Team".
- **Slug** — optional; auto-generated if blank.
- **Role granted** — any registered WordPress role. Leaving it blank means the team tracks membership but grants no extra capabilities.
- **Sites** (multisite only) — *All sites* or a specific list.

### Adding users to teams

Edit a user's profile. The **User Teams** section lists every defined team with a checkbox.

### Programmatic API

```php
// Create a team.
$id = WP_User_Teams::create_team( 'Meta Team', 'meta-team', 'editor' );

// Scope it to specific sites on multisite (empty array = all sites).
WP_User_Teams::set_team_sites( $id, array( 1, 4, 9 ) );

// Add / remove members.
WP_User_Teams::add_user_to_team( $user_id, $id );
WP_User_Teams::remove_user_from_team( $user_id, $id );
WP_User_Teams::set_user_teams( $user_id, array( $id, $other_id ) );

// Inspect.
WP_User_Teams::get_user_teams( $user_id );        // full team records, keyed by ID
WP_User_Teams::get_user_team_ids( $user_id );     // just the IDs
WP_User_Teams::get_team_members( $id );           // user IDs in this team
WP_User_Teams::count_members_per_team();          // [ team_id => count ]
```

### Checking access

Nothing special — team-derived caps participate in the normal cap system:

```php
if ( user_can( $user_id, 'edit_others_posts' ) ) {
    // ...
}
```

## Capability requirements

| Action | Single site | Multisite |
|---|---|---|
| Manage teams (create / edit / delete) | `promote_users` | `manage_network_users` (super admin) |
| Assign teams to a user | same as above | same as above |

On multisite, team management is restricted to super admins so that a compromised single-site administrator can't grant themselves access across the network.

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
npm run test            # single-site suite
npm run test:multisite  # multisite suite (exercises site-scoping)
```

All tests execute inside the wp-env `tests-cli` container against a real WordPress install, using [Yoast PHPUnit Polyfills](https://github.com/Yoast/PHPUnit-Polyfills) for cross-version assertion compatibility.

The test suite lives in [`tests/`](./tests) and covers:

- `Test_Teams.php` — team CRUD, slug uniqueness, validation.
- `Test_Membership.php` — add/remove/set memberships, idempotency, cascading deletes when teams or users go away.
- `Test_Capabilities.php` — the `user_has_cap` filter grants and revokes caps correctly; multi-team merging; role changes propagate.
- `Test_Multisite.php` — site-scoping, `get_blogs_of_user`, cleanup on site deletion.
- `Test_Admin.php` — integration tests that drive the admin form handlers end-to-end via `$_POST`/`$_REQUEST`.

## Requirements

- WordPress 6.9+
- PHP 7.4+

## License

GPL-2.0-or-later.
