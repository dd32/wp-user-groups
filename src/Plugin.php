<?php
/**
 * Core plugin class — composed from topic traits so each area of behaviour
 * lives in its own file. All trait methods share `self::` against this
 * class, so cross-trait calls like `self::get_team( $id )` work the same
 * as they did when everything lived in one monolithic class.
 *
 * Each team is backed by a real `wp_users` row (a "team account"):
 *
 *   - display_name   = team name
 *   - user_login     = `_team_{slug}` (prefixed to avoid collisions)
 *   - user_pass      = random / unknown (login is blocked anyway)
 *   - wput_is_team   = '1'            (marker meta)
 *   - wput_slug      = slug           (human-friendly identifier)
 *   - wput_global_role = role_slug    (role granted network-wide)
 *   - wp_{blog_id}_capabilities       (per-site role grants, native)
 */

namespace dd32\WordPress\UserTeams;

defined( 'ABSPATH' ) || exit;

class Plugin {

	use Traits\Crud;
	use Traits\SiteScope;
	use Traits\Membership;
	use Traits\Capabilities;
	use Traits\Hiding;
	use Traits\BlogsFilter;

	const IS_TEAM_META_KEY   = 'wput_is_team';
	const SLUG_META_KEY      = 'wput_slug';
	const GLOBAL_ROLE_META   = 'wput_global_role';
	const USER_META_KEY      = 'wp_user_teams';
	const QUERY_INCLUDE_FLAG = 'wput_include_teams';
	const LOGIN_PREFIX       = '_team_';

	private static $instance;

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_action( 'deleted_user', array( $this, 'on_user_deleted' ) );

		// Team accounts are real users but should be invisible to most
		// user-facing systems. The filters short-circuit during
		// `WP_INSTALLING` so WP's own install flow isn't disrupted.
		add_action( 'pre_user_query', array( $this, 'exclude_team_users_by_default' ) );
		add_filter( 'rest_user_query', array( $this, 'exclude_team_users_from_rest' ) );
		add_filter( 'authenticate', array( $this, 'block_team_user_login' ), 100, 3 );
		add_filter( 'allow_password_reset', array( $this, 'block_team_user_password_reset' ), 10, 2 );

		add_filter( 'get_blogs_of_user', array( $this, 'filter_get_blogs_of_user' ), 10, 3 );
		add_action( 'wp_delete_site', array( $this, 'on_site_deleted' ) );

		// Fake `wp_{blog}_capabilities` meta for team members on blogs the
		// team covers. An empty-array result satisfies the membership
		// check used by `is_user_member_of_blog()` without granting any
		// caps (fan-out happens via `user_has_cap`). Real caps meta, if
		// present, is left untouched.
		add_filter( 'get_user_metadata', array( $this, 'fake_member_blog_capabilities' ), 10, 4 );
	}

	/** Test helper kept for API compatibility — nothing to flush in this model. */
	public static function flush_all_caches() {
		// No-op: team data lives in `wp_users` / `wp_usermeta` which
		// WP_UnitTestCase manages between tests.
	}
}
