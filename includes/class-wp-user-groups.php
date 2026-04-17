<?php
/**
 * Core User Groups logic: taxonomy registration, capability injection,
 * and multisite access filters.
 */

defined( 'ABSPATH' ) || exit;

class WP_User_Groups {

	const TAXONOMY       = 'user_group';
	const ROLE_META_KEY  = 'wp_user_group_role';
	const SITES_META_KEY = 'wp_user_group_sites';

	private static $instance;

	/**
	 * Per-request caches keyed by user ID / user ID + blog ID.
	 *
	 * @var array
	 */
	private static $user_groups_cache = array();
	private static $group_caps_cache  = array();

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_taxonomy' ), 0 );
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_action( 'deleted_user', array( $this, 'clear_user_memberships' ) );

		if ( is_multisite() ) {
			add_filter( 'get_blogs_of_user', array( $this, 'filter_get_blogs_of_user' ), 10, 3 );
			add_filter( 'is_user_member_of_blog', array( $this, 'filter_is_user_member_of_blog' ), 10, 3 );
		}
	}

	/**
	 * Register the `user_group` taxonomy against the `user` object type.
	 *
	 * The taxonomy is deliberately not publicly queryable: groups are an
	 * internal access-control primitive, not content.
	 */
	public function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			'user',
			array(
				'public'             => false,
				'publicly_queryable' => false,
				'hierarchical'       => false,
				'show_ui'            => false,
				'show_in_rest'       => false,
				'rewrite'            => false,
				'query_var'          => false,
				'labels'             => array(
					'name'          => __( 'User Groups', 'wp-user-groups' ),
					'singular_name' => __( 'User Group', 'wp-user-groups' ),
				),
				'capabilities'       => array(
					'manage_terms' => 'promote_users',
					'edit_terms'   => 'promote_users',
					'delete_terms' => 'promote_users',
					'assign_terms' => 'promote_users',
				),
			)
		);
	}

	/**
	 * On multisite, all group terms live on the main site so IDs are stable
	 * across the network. Callers must wrap any term / relationship work
	 * in switch_to_groups_site() / restore_groups_site().
	 */
	public static function switch_to_groups_site() {
		if ( is_multisite() && (int) get_current_blog_id() !== (int) get_main_site_id() ) {
			switch_to_blog( get_main_site_id() );
			return true;
		}
		return false;
	}

	public static function restore_groups_site( $switched ) {
		if ( $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Groups a user belongs to.
	 *
	 * @param int $user_id
	 * @return WP_Term[]
	 */
	public static function get_user_groups( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return array();
		}

		if ( isset( self::$user_groups_cache[ $user_id ] ) ) {
			return self::$user_groups_cache[ $user_id ];
		}

		$switched = self::switch_to_groups_site();
		$terms    = wp_get_object_terms( $user_id, self::TAXONOMY );
		self::restore_groups_site( $switched );

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		self::$user_groups_cache[ $user_id ] = $terms;
		return $terms;
	}

	public static function set_user_groups( $user_id, array $term_ids ) {
		$user_id   = (int) $user_id;
		$term_ids  = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
		$switched  = self::switch_to_groups_site();
		$result    = wp_set_object_terms( $user_id, $term_ids, self::TAXONOMY, false );
		self::restore_groups_site( $switched );

		unset( self::$user_groups_cache[ $user_id ] );
		foreach ( array_keys( self::$group_caps_cache ) as $key ) {
			if ( strpos( $key, $user_id . ':' ) === 0 ) {
				unset( self::$group_caps_cache[ $key ] );
			}
		}

		return $result;
	}

	/**
	 * @return WP_Term[]
	 */
	public static function get_all_groups() {
		$switched = self::switch_to_groups_site();
		$terms    = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		self::restore_groups_site( $switched );

		return is_wp_error( $terms ) ? array() : $terms;
	}

	public static function get_group( $term_id ) {
		$switched = self::switch_to_groups_site();
		$term     = get_term( (int) $term_id, self::TAXONOMY );
		self::restore_groups_site( $switched );

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		return $term;
	}

	public static function create_group( $name, $slug = '' ) {
		$args = array();
		if ( $slug ) {
			$args['slug'] = sanitize_title( $slug );
		}
		$switched = self::switch_to_groups_site();
		$result   = wp_insert_term( wp_strip_all_tags( $name ), self::TAXONOMY, $args );
		self::restore_groups_site( $switched );
		return $result;
	}

	public static function update_group( $term_id, $name, $slug = '' ) {
		$args = array( 'name' => wp_strip_all_tags( $name ) );
		if ( $slug ) {
			$args['slug'] = sanitize_title( $slug );
		}
		$switched = self::switch_to_groups_site();
		$result   = wp_update_term( (int) $term_id, self::TAXONOMY, $args );
		self::restore_groups_site( $switched );
		return $result;
	}

	public static function delete_group( $term_id ) {
		$switched = self::switch_to_groups_site();
		$result   = wp_delete_term( (int) $term_id, self::TAXONOMY );
		self::restore_groups_site( $switched );
		self::$user_groups_cache = array();
		self::$group_caps_cache  = array();
		return $result;
	}

	public static function get_group_role( $term_id ) {
		$switched = self::switch_to_groups_site();
		$role     = get_term_meta( (int) $term_id, self::ROLE_META_KEY, true );
		self::restore_groups_site( $switched );
		return is_string( $role ) ? $role : '';
	}

	public static function set_group_role( $term_id, $role ) {
		$switched = self::switch_to_groups_site();
		$result   = update_term_meta( (int) $term_id, self::ROLE_META_KEY, sanitize_key( $role ) );
		self::restore_groups_site( $switched );
		self::$group_caps_cache = array();
		return $result;
	}

	public static function get_group_sites( $term_id ) {
		$switched = self::switch_to_groups_site();
		$sites    = get_term_meta( (int) $term_id, self::SITES_META_KEY, true );
		self::restore_groups_site( $switched );

		if ( ! is_array( $sites ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $sites ) ) );
	}

	public static function set_group_sites( $term_id, array $site_ids ) {
		$sanitised = array_values( array_unique( array_filter( array_map( 'intval', $site_ids ) ) ) );
		$switched  = self::switch_to_groups_site();
		$result    = update_term_meta( (int) $term_id, self::SITES_META_KEY, $sanitised );
		self::restore_groups_site( $switched );
		self::$group_caps_cache = array();
		return $result;
	}

	/**
	 * Whether a group grants access to a given blog. On multisite an empty
	 * site list means "all sites on the network, including future ones".
	 */
	public static function group_applies_to_site( $term_id, $blog_id = null ) {
		if ( ! is_multisite() ) {
			return true;
		}

		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		$sites   = self::get_group_sites( $term_id );

		if ( empty( $sites ) ) {
			return true;
		}

		return in_array( $blog_id, $sites, true );
	}

	/**
	 * Capabilities granted to a user by their group memberships on a site.
	 *
	 * @return array<string,bool>
	 */
	public static function get_capabilities_from_groups( $user_id, $blog_id = null ) {
		$user_id = (int) $user_id;
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		$key     = $user_id . ':' . $blog_id;

		if ( isset( self::$group_caps_cache[ $key ] ) ) {
			return self::$group_caps_cache[ $key ];
		}

		$caps   = array();
		$groups = self::get_user_groups( $user_id );

		foreach ( $groups as $group ) {
			if ( ! self::group_applies_to_site( $group->term_id, $blog_id ) ) {
				continue;
			}

			$role_slug = self::get_group_role( $group->term_id );
			if ( ! $role_slug ) {
				continue;
			}

			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			foreach ( $role->capabilities as $cap => $granted ) {
				if ( $granted ) {
					$caps[ $cap ] = true;
				}
			}

			$caps[ 'role-' . $role_slug ] = true;
		}

		self::$group_caps_cache[ $key ] = $caps;
		return $caps;
	}

	/**
	 * Merge group-derived caps into the user's effective capabilities.
	 */
	public function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}

		$group_caps = self::get_capabilities_from_groups( $user->ID );
		if ( empty( $group_caps ) ) {
			return $allcaps;
		}

		return array_merge( $allcaps, $group_caps );
	}

	/**
	 * Multisite: expose group-linked sites through get_blogs_of_user so the
	 * user's "My Sites" menu and admin navigation reflect their group access.
	 */
	public function filter_get_blogs_of_user( $blogs, $user_id, $all ) {
		$groups = self::get_user_groups( $user_id );
		if ( empty( $groups ) ) {
			return $blogs;
		}

		$site_ids = array();
		foreach ( $groups as $group ) {
			if ( ! self::get_group_role( $group->term_id ) ) {
				continue;
			}
			$sites = self::get_group_sites( $group->term_id );
			if ( empty( $sites ) ) {
				$ids = get_sites(
					array(
						'number' => 0,
						'fields' => 'ids',
					)
				);
				foreach ( $ids as $id ) {
					$site_ids[ (int) $id ] = true;
				}
				continue;
			}
			foreach ( $sites as $id ) {
				$site_ids[ (int) $id ] = true;
			}
		}

		foreach ( array_keys( $site_ids ) as $site_id ) {
			if ( isset( $blogs[ $site_id ] ) ) {
				continue;
			}
			$details = get_site( $site_id );
			if ( ! $details ) {
				continue;
			}
			if ( ! $all && ( $details->archived || $details->spam || $details->deleted ) ) {
				continue;
			}
			$blogs[ $site_id ] = (object) array(
				'userblog_id' => (int) $details->blog_id,
				'blogname'    => $details->blogname,
				'domain'      => $details->domain,
				'path'        => $details->path,
				'site_id'     => (int) $details->site_id,
				'siteurl'     => $details->siteurl,
				'archived'    => $details->archived,
				'mature'      => $details->mature,
				'spam'        => $details->spam,
				'deleted'     => $details->deleted,
			);
		}

		return $blogs;
	}

	public function filter_is_user_member_of_blog( $is_member, $user_id, $blog_id ) {
		if ( $is_member ) {
			return $is_member;
		}

		$groups = self::get_user_groups( $user_id );
		foreach ( $groups as $group ) {
			if ( ! self::get_group_role( $group->term_id ) ) {
				continue;
			}
			if ( self::group_applies_to_site( $group->term_id, $blog_id ) ) {
				return true;
			}
		}

		return $is_member;
	}

	/**
	 * Remove a deleted user from every group they belonged to.
	 */
	public function clear_user_memberships( $user_id ) {
		$switched = self::switch_to_groups_site();
		wp_delete_object_term_relationships( (int) $user_id, self::TAXONOMY );
		self::restore_groups_site( $switched );
		unset( self::$user_groups_cache[ (int) $user_id ] );
	}
}
