<?php
/**
 * Team CRUD — create / read / update / delete operations against the
 * `wp_users` row that backs each team.
 */

namespace dd32\WordPress\UserTeams\Traits;

use WP_Error;
use WP_User;

trait Crud {

	/**
	 * @return array<int, array{id:int,name:string,slug:string,role:string,sites:array<int,string>}>
	 */
	public static function get_all_teams() {
		$team_users = get_users( array(
			'blog_id'                => 0, // network-wide: teams aren't bound to a single blog
			'meta_key'               => self::IS_TEAM_META_KEY,
			'meta_value'             => '1',
			'number'                 => -1,
			'orderby'                => 'display_name',
			'order'                  => 'ASC',
			self::QUERY_INCLUDE_FLAG => true,
		) );

		$teams = array();
		foreach ( $team_users as $u ) {
			$teams[ (int) $u->ID ] = self::user_to_team( $u );
		}
		return $teams;
	}

	public static function get_team( $team_id ) {
		$team_id = (int) $team_id;
		if ( $team_id <= 0 ) {
			return null;
		}
		$u = get_userdata( $team_id );
		if ( ! $u instanceof WP_User ) {
			return null;
		}
		if ( '1' !== (string) get_user_meta( $team_id, self::IS_TEAM_META_KEY, true ) ) {
			return null;
		}
		return self::user_to_team( $u );
	}

	public static function get_team_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}
		$users = get_users( array(
			'blog_id'                => 0,
			'meta_query'             => array(
				'relation' => 'AND',
				array( 'key' => self::IS_TEAM_META_KEY, 'value' => '1' ),
				array( 'key' => self::SLUG_META_KEY, 'value' => $slug ),
			),
			'number'                 => 1,
			self::QUERY_INCLUDE_FLAG => true,
		) );
		if ( empty( $users ) ) {
			return null;
		}
		return self::user_to_team( $users[0] );
	}

	public static function create_team( $name, $slug = '', $role = '' ) {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-teams' ) );
		}

		$slug = $slug ? sanitize_title( $slug ) : sanitize_title( $name );
		if ( '' === $slug ) {
			$slug = 'team';
		}
		$slug = self::unique_slug( $slug );

		$role = sanitize_key( $role );
		if ( $role && ! wp_roles()->is_role( $role ) ) {
			$role = '';
		}

		$login = self::LOGIN_PREFIX . $slug;
		// Collisions on user_login go through the same unique_slug path —
		// if someone created a literal `_team_foo` user, bump the slug.
		while ( username_exists( $login ) ) {
			$slug  = self::unique_slug( $slug );
			$login = self::LOGIN_PREFIX . $slug;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 64, true, true ),
			'user_email'   => $login . '@teams.internal',
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => '', // No role on the registering (main) site by default.
		) );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::IS_TEAM_META_KEY, '1' );
		update_user_meta( $user_id, self::SLUG_META_KEY, $slug );
		update_user_meta( $user_id, self::GLOBAL_ROLE_META, $role );

		// `wp_insert_user` with `role => ''` still writes an empty
		// `wp_{current_blog}_capabilities` meta row on multisite (via
		// `WP_User::set_role`), which would make the team account appear
		// as a member of the creating blog. Strip it so the team starts
		// with no blog attachments.
		global $wpdb;
		delete_user_meta( $user_id, $wpdb->get_blog_prefix() . 'capabilities' );
		delete_user_meta( $user_id, $wpdb->get_blog_prefix() . 'user_level' );

		return (int) $user_id;
	}

	public static function update_team( $team_id, array $data ) {
		$team_id = (int) $team_id;
		if ( ! self::get_team( $team_id ) ) {
			return new WP_Error( 'not_found', __( 'Team not found.', 'wp-user-teams' ) );
		}

		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'missing_name', __( 'A name is required.', 'wp-user-teams' ) );
			}
			wp_update_user( array(
				'ID'           => $team_id,
				'display_name' => $name,
				'first_name'   => $name,
			) );
		}

		if ( isset( $data['slug'] ) ) {
			$slug = sanitize_title( $data['slug'] );
			if ( '' === $slug ) {
				$slug = 'team';
			}
			$existing = self::get_team_by_slug( $slug );
			if ( $existing && (int) $existing['id'] !== $team_id ) {
				return new WP_Error( 'duplicate_slug', __( 'That slug is already in use.', 'wp-user-teams' ) );
			}
			update_user_meta( $team_id, self::SLUG_META_KEY, $slug );
		}

		if ( isset( $data['role'] ) ) {
			$role = sanitize_key( $data['role'] );
			if ( $role && ! wp_roles()->is_role( $role ) ) {
				$role = '';
			}
			update_user_meta( $team_id, self::GLOBAL_ROLE_META, $role );
		}

		return true;
	}

	public static function delete_team( $team_id ) {
		$team_id = (int) $team_id;
		if ( ! self::get_team( $team_id ) ) {
			return false;
		}

		// Drop this team from every member's list first, then remove the
		// team account itself. `deleted_user` fallback handles any races.
		self::remove_team_from_all_users( $team_id );

		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $team_id );

		return true;
	}

	private static function user_to_team( WP_User $u ) {
		$slug = (string) get_user_meta( $u->ID, self::SLUG_META_KEY, true );
		if ( '' === $slug ) {
			$slug = sanitize_title( preg_replace( '/^' . preg_quote( self::LOGIN_PREFIX, '/' ) . '/', '', $u->user_login ) );
		}
		return array(
			'id'    => (int) $u->ID,
			'name'  => $u->display_name ?: $u->user_login,
			'slug'  => $slug,
			'role'  => (string) get_user_meta( $u->ID, self::GLOBAL_ROLE_META, true ),
			'sites' => self::get_team_site_roles( $u->ID ),
		);
	}

	private static function unique_slug( $slug ) {
		$base = $slug;
		$i    = 2;
		while ( self::get_team_by_slug( $slug ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}
		return $slug;
	}
}
