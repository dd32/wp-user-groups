# User Groups

[![Tests](https://github.com/dd32/wp-user-groups/actions/workflows/test.yml/badge.svg?branch=trunk)](https://github.com/dd32/wp-user-groups/actions/workflows/test.yml)

Unix-style user groups for WordPress. Define a group, attach a role, add users to it — members inherit the role everywhere the group applies.

Works on single-site WordPress and multisite networks. On multisite a single group can span selected sites or the entire network, so a "Meta Team" or "Playground Contributors" group doesn't need per-site setup.

## Why

WordPress roles and capabilities are per-site. On a large multisite network, granting a team access across 50 sites usually means adding each user to each site by hand — and remembering to remove them everywhere when they leave. This plugin turns that into:

1. Create a group, pick a role.
2. Add the user to the group.
3. They have that role everywhere the group applies.
4. Remove them from the group → access is gone everywhere.

## Features

- **Groups with roles.** Each group is associated with a WordPress role (editor, administrator, a custom role, or nothing).
- **Runtime capability grants.** Access is granted through the `user_has_cap` filter, so removing a user from a group drops their access on the next request — no stale `{prefix}capabilities` usermeta to clean up.
- **Multisite-native.** Group definitions live in a single network-level site option; memberships live in `wp_usermeta`, which is global on multisite. No `switch_to_blog` juggling.
- **Site scoping.** A group can apply to every site on the network (including sites added later) or a curated list of sites.
- **Admin UI.** Users → Groups (single site) or Network Admin → Users → Groups (multisite). Per-user checkboxes on the Edit User screen. A "Groups" column on the Users list table.
- **Cleans up after itself.** Deleting a group removes its memberships. Deleting a user drops their memberships. Deleting a site removes it from any group that targeted it.

## Data model

Three WordPress primitives, all served by the object cache or metadata cache:

| Storage | Purpose |
|---|---|
| `wp_user_groups` site option | Map of group ID → `{ id, name, slug, role, sites }`. `get_site_option()` is per-network on multisite, per-install on single site. |
| `{base_prefix}user_groups` user meta | Array of group IDs the user belongs to (authoritative). `wp_usermeta` is global on multisite, so a user's memberships follow them across sites. |
| `{base_prefix}user_group_{id}` user meta | Presence marker, one row per (user, group). Gives `"who is in group N?"` an indexed `meta_key` lookup with no `LIKE` and no PHP-side filtering. |

"Which groups is user X in?" is a single `get_user_meta()` call — served from the metadata cache after the first hit. "Which users are in group Y?" is an indexed lookup on the per-group marker key. Both keys use `$wpdb->base_prefix` so they line up with WordPress' own conventions on custom-prefix installs.

## How access is granted

Group-derived capabilities are merged in at runtime through three filters:

| Filter | Effect |
|---|---|
| `user_has_cap` | Adds the group role's capabilities to the user's effective caps, plus `role-{slug}` for plugins that check by role. |
| `is_user_member_of_blog` | Returns `true` when a user is in a group that applies to the given site. |
| `get_blogs_of_user` | Adds group-linked sites to the user's "My Sites" navigation. |

The user's actual `{prefix}capabilities` usermeta is never modified. This is what makes group-based access instantly revocable.

## Installation

1. Drop the `wp-user-groups` directory into `wp-content/plugins/`.
2. Activate it.
   - On multisite, **network-activate** the plugin (it's marked `Network: true`).

No schema changes are required. Group definitions live in a site option and memberships in `wp_usermeta`.

## Usage

### Creating a group

**Users → Groups → Add New** (on single site) or **Network Admin → Users → Groups → Add New** (on multisite):

- **Name** — display name, e.g. "WordPress Meta Team".
- **Slug** — optional; auto-generated if blank.
- **Role granted** — any registered WordPress role. Leaving it blank means the group tracks membership but grants no extra capabilities.
- **Sites** (multisite only) — *All sites* or a specific list.

### Adding users to groups

Edit a user's profile. The **User Groups** section lists every defined group with a checkbox.

### Programmatic API

```php
// Create a group.
$id = WP_User_Groups::create_group( 'Meta Team', 'meta-team', 'editor' );

// Scope it to specific sites on multisite (empty array = all sites).
WP_User_Groups::set_group_sites( $id, array( 1, 4, 9 ) );

// Add / remove members.
WP_User_Groups::add_user_to_group( $user_id, $id );
WP_User_Groups::remove_user_from_group( $user_id, $id );
WP_User_Groups::set_user_groups( $user_id, array( $id, $other_id ) );

// Inspect.
WP_User_Groups::get_user_groups( $user_id );        // full group records, keyed by ID
WP_User_Groups::get_user_group_ids( $user_id );     // just the IDs
WP_User_Groups::get_group_members( $id );           // user IDs in this group
WP_User_Groups::count_members_per_group();          // [ group_id => count ]
```

### Checking access

Nothing special — group-derived caps participate in the normal cap system:

```php
if ( user_can( $user_id, 'edit_others_posts' ) ) {
    // ...
}
```

## Capability requirements

| Action | Single site | Multisite |
|---|---|---|
| Manage groups (create / edit / delete) | `promote_users` | `manage_network_users` (super admin) |
| Assign groups to a user | same as above | same as above |

On multisite, group management is restricted to super admins so that a compromised single-site administrator can't grant themselves access across the network.

## Development

This repository is set up with [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) for a local WordPress environment and PHPUnit for tests.

### Prerequisites

- Docker (for wp-env)
- Node.js 18+

### Getting started

```bash
npm install
npm run start
```

That spins up a WordPress instance at `http://localhost:8888` with this plugin active.

### Running tests

```bash
npm run test            # single-site suite
npm run test:multisite  # multisite suite (exercises site-scoping)
```

The test suite lives in [`tests/`](./tests) and covers:

- `test-groups.php` — group CRUD, slug uniqueness, validation.
- `test-membership.php` — add/remove/set memberships, idempotency, cascading deletes when groups or users go away.
- `test-capabilities.php` — the `user_has_cap` filter grants and revokes caps correctly; multi-group merging; role changes propagate.
- `test-multisite.php` — site-scoping, `is_user_member_of_blog`, `get_blogs_of_user`, cleanup on site deletion.

## Requirements

- WordPress 6.0+
- PHP 7.4+

## License

GPL-2.0-or-later.
