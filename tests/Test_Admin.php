<?php
/**
 * Integration tests for WP_User_Teams_Admin form handlers.
 *
 * Exercises handle_save / handle_delete / save_user_field by populating
 * $_POST and $_GET the way WordPress would on a real form submission.
 */
class Test_Admin extends WP_UnitTestCase {

	/** @var WP_User_Teams_Admin */
	private $admin;

	private $admin_user_id;

	public function set_up() {
		parent::set_up();
		WP_User_Teams::flush_all_caches();

		$this->admin = WP_User_Teams_Admin::instance();

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $this->admin_user_id );
		wp_set_current_user( $this->admin_user_id );

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * handle_save
	 * ---------------------------------------------------------------- */

	public function test_handle_save_creates_team() {
		$this->post_form( array(
			'_wpnonce' => wp_create_nonce( WP_User_Teams_Admin::NONCE_ACTION ),
			'name'     => 'Docs Team',
			'slug'     => 'docs',
			'role'     => 'editor',
		) );

		$this->run_handler_expecting_redirect( array( $this->admin, 'handle_save' ) );

		$team = WP_User_Teams::get_team_by_slug( 'docs' );
		$this->assertNotNull( $team );
		$this->assertSame( 'Docs Team', $team['name'] );
		$this->assertSame( 'editor', $team['role'] );
	}

	public function test_handle_save_rejects_without_nonce() {
		$this->post_form( array( 'name' => 'Nope' ) );

		$this->expect_wp_die( function () {
			$this->admin->handle_save();
		} );

		$this->assertNull( WP_User_Teams::get_team_by_slug( 'nope' ) );
	}

	public function test_handle_add_site_adds_grant() {

		$team_id = WP_User_Teams::create_team( 'AddSite', 'addsite', '' );
		$blog2   = self::factory()->blog->create();

		$this->post_form( array(
			'_wpnonce' => wp_create_nonce( WP_User_Teams_Admin::NONCE_ACTION ),
			'team_id'  => (string) $team_id,
			'blog_id'  => (string) $blog2,
			'role'     => 'author',
		) );

		$this->run_handler_expecting_redirect( array( $this->admin, 'handle_add_site' ) );

		$this->assertSame( array( $blog2 => 'author' ), WP_User_Teams::get_team_site_roles( $team_id ) );
	}

	public function test_handle_add_site_with_empty_role_stores_inherit() {

		$team_id = WP_User_Teams::create_team( 'AddInherit', 'add-inherit', 'editor' );
		$blog2   = self::factory()->blog->create();

		$this->post_form( array(
			'_wpnonce' => wp_create_nonce( WP_User_Teams_Admin::NONCE_ACTION ),
			'team_id'  => (string) $team_id,
			'blog_id'  => (string) $blog2,
			'role'     => '',
		) );

		$this->run_handler_expecting_redirect( array( $this->admin, 'handle_add_site' ) );

		$this->assertSame( array( $blog2 => '' ), WP_User_Teams::get_team_site_roles( $team_id ) );
	}

	/* ------------------------------------------------------------------
	 * handle_attach_team_to_site permissions
	 * ---------------------------------------------------------------- */

	public function test_site_admin_without_network_rights_can_attach_team_to_own_site() {

		$team_id = WP_User_Teams::create_team( 'AttachByAdmin', 'attach-admin', 'editor' );
		$blog2   = self::factory()->blog->create();

		// Site admin on $blog2, not a super admin.
		$site_admin_id = self::factory()->user->create();
		add_user_to_blog( $blog2, $site_admin_id, 'administrator' );
		wp_set_current_user( $site_admin_id );
		switch_to_blog( $blog2 );

		$this->post_form( array(
			'_wpnonce' => wp_create_nonce( WP_User_Teams_Admin::NONCE_ACTION ),
			'team_id'  => (string) $team_id,
			'blog_id'  => (string) $blog2,
			'role'     => 'author',
		) );

		$this->run_handler_expecting_redirect( array( $this->admin, 'handle_attach_team_to_site' ) );

		restore_current_blog();
		wp_set_current_user( $this->admin_user_id );

		$roles = WP_User_Teams::get_team_site_roles( $team_id );
		$this->assertSame( 'author', $roles[ $blog2 ] ?? null, 'site admin must be able to attach a team to their own site' );
	}

	public function test_user_without_blog_role_cannot_attach_team() {

		$team_id = WP_User_Teams::create_team( 'NoRights', 'no-rights', 'editor' );
		$blog2   = self::factory()->blog->create();

		// Random subscriber on the main site, no role on $blog2.
		$sub_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$this->post_form( array(
			'_wpnonce' => wp_create_nonce( WP_User_Teams_Admin::NONCE_ACTION ),
			'team_id'  => (string) $team_id,
			'blog_id'  => (string) $blog2,
			'role'     => 'author',
		) );

		$this->expect_wp_die( function () {
			$this->admin->handle_attach_team_to_site();
		} );

		wp_set_current_user( $this->admin_user_id );

		$this->assertSame( array(), WP_User_Teams::get_team_site_roles( $team_id ) );
	}

	public function test_handle_remove_site_removes_grant() {

		$blog2   = self::factory()->blog->create();
		$team_id = WP_User_Teams::create_team( 'RmSite', 'rmsite', '' );
		WP_User_Teams::set_team_sites( $team_id, array( $blog2 => 'editor' ) );

		$this->get_query( array(
			'team_id'  => (string) $team_id,
			'blog_id'  => (string) $blog2,
			'_wpnonce' => wp_create_nonce( WP_User_Teams_Admin::NONCE_ACTION ),
		) );

		$this->run_handler_expecting_redirect( array( $this->admin, 'handle_remove_site' ) );

		$this->assertSame( array(), WP_User_Teams::get_team_site_roles( $team_id ) );
	}

	/* ------------------------------------------------------------------
	 * handle_delete
	 * ---------------------------------------------------------------- */

	public function test_handle_delete_removes_team() {
		$team_id = WP_User_Teams::create_team( 'Gone', 'gone', 'editor' );

		$this->get_query( array(
			'team_id'  => (string) $team_id,
			'_wpnonce' => wp_create_nonce( WP_User_Teams_Admin::NONCE_ACTION ),
		) );

		$this->run_handler_expecting_redirect( array( $this->admin, 'handle_delete' ) );

		$this->assertNull( WP_User_Teams::get_team( $team_id ) );
	}

	/* ------------------------------------------------------------------
	 * save_user_field
	 * ---------------------------------------------------------------- */

	public function test_save_user_field_sets_team_membership() {
		$team_id = WP_User_Teams::create_team( 'Contributors', 'contrib', 'contributor' );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->post_form( array(
			'wput_user_nonce' => wp_create_nonce( WP_User_Teams_Admin::USER_NONCE ),
			'wput_teams'      => array( (string) $team_id ),
		) );

		$this->admin->save_user_field( $user_id );

		$ids = WP_User_Teams::get_user_team_ids( $user_id );
		$this->assertContains( $team_id, $ids );
	}

	public function test_save_user_field_with_no_selection_clears_memberships() {
		$team_id = WP_User_Teams::create_team( 'Drop', 'drop', 'editor' );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_User_Teams::add_user_to_team( $user_id, $team_id );

		$this->post_form( array(
			'wput_user_nonce' => wp_create_nonce( WP_User_Teams_Admin::USER_NONCE ),
			// wput_teams intentionally omitted.
		) );

		$this->admin->save_user_field( $user_id );

		$this->assertSame( array(), WP_User_Teams::get_user_team_ids( $user_id ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	/**
	 * Populate $_POST and mirror into $_REQUEST the way PHP does on a real request.
	 * WordPress's nonce + request helpers read from $_REQUEST, so keeping the two in
	 * sync is essential in PHPUnit where PHP's auto-merging is not available.
	 */
	private function post_form( array $fields ) {
		$_POST    = $fields;
		$_REQUEST = array_merge( $_REQUEST, $fields );
	}

	/**
	 * Populate $_GET and mirror into $_REQUEST.
	 */
	private function get_query( array $fields ) {
		$_GET     = $fields;
		$_REQUEST = array_merge( $_REQUEST, $fields );
	}

	/**
	 * handle_save / handle_delete end with wp_safe_redirect + exit.
	 * In PHPUnit those are routed through wp_die, which we catch here.
	 */
	private function run_handler_expecting_redirect( callable $handler ) {
		$this->expect_wp_die( $handler );
	}

	private function expect_wp_die( callable $fn ) {
		add_filter( 'wp_redirect', array( $this, 'short_circuit_redirect' ), 10, 1 );
		try {
			$fn();
			$this->fail( 'Handler did not redirect / wp_die as expected.' );
		} catch ( WPDieException $e ) {
			// Expected path for nonce / permission failures.
		} catch ( RedirectException $e ) {
			// Expected path for the success redirect.
		} finally {
			remove_filter( 'wp_redirect', array( $this, 'short_circuit_redirect' ), 10 );
		}
	}

	public function short_circuit_redirect( $location ) {
		throw new RedirectException( $location );
	}
}

if ( ! class_exists( 'RedirectException' ) ) {
	class RedirectException extends Exception {}
}
