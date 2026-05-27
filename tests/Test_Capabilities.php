<?php
use dd32\WordPress\UserTeams\Plugin;
use dd32\WordPress\UserTeams\Admin;
/**
 * Tests for the user_has_cap filter: team-derived capabilities.
 */
class Test_Capabilities extends WP_UnitTestCase {

	private $team_id;
	private $user_id;

	public function set_up() {
		parent::set_up();
		Plugin::flush_all_caches();

		$this->team_id = Plugin::create_team( 'Editors', 'editors', 'editor' );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_subscriber_lacks_edit_posts_by_default() {
		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_posts' ) );
	}

	public function test_team_grants_role_capabilities() {
		Plugin::add_user_to_team( $this->user_id, $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_posts' ) );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) );
		$this->assertTrue( $user->has_cap( 'publish_posts' ) );
	}

	public function test_removing_from_team_drops_caps() {
		Plugin::add_user_to_team( $this->user_id, $this->team_id );
		Plugin::remove_user_from_team( $this->user_id, $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}

	public function test_user_retains_own_caps_alongside_team() {
		$user_id    = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_team = Plugin::create_team( 'Admins', 'admins', 'administrator' );

		Plugin::add_user_to_team( $user_id, $admin_team );

		$user = new WP_User( $user_id );
		$this->assertTrue( $user->has_cap( 'manage_options' ) ); // from admin team
		$this->assertTrue( $user->has_cap( 'edit_posts' ) );     // from author + admin
	}

	public function test_multiple_teams_merge_caps() {
		$author_team = Plugin::create_team( 'Authors', 'authors', 'author' );

		Plugin::add_user_to_team( $this->user_id, $this->team_id );  // editor
		Plugin::add_user_to_team( $this->user_id, $author_team );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) ); // editor cap
		$this->assertTrue( $user->has_cap( 'upload_files' ) );       // author cap
	}

	public function test_team_without_role_grants_nothing() {
		$empty = Plugin::create_team( 'No Role', 'no-role', '' );
		Plugin::add_user_to_team( $this->user_id, $empty );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_posts' ) );
	}

	public function test_deleting_team_drops_caps() {
		Plugin::add_user_to_team( $this->user_id, $this->team_id );
		Plugin::delete_team( $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}

	public function test_changing_team_role_changes_caps() {
		Plugin::add_user_to_team( $this->user_id, $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) );

		Plugin::update_team( $this->team_id, array( 'role' => 'subscriber' ) );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}

	/**
	 * Tests that application passwords are unavailable for team accounts.
	 *
	 * @return void
	 */
	public function test_application_passwords_are_unavailable_for_team_accounts() {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		try {
			$this->assertFalse( wp_is_application_passwords_available_for_user( $this->team_id ) );
			$this->assertTrue( wp_is_application_passwords_available_for_user( $this->user_id ) );
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		}
	}

	/**
	 * Tests that application password authentication is blocked for team accounts.
	 *
	 * @return void
	 */
	public function test_application_password_authentication_is_blocked_for_team_accounts() {
		$error = new WP_Error();

		do_action(
			'wp_authenticate_application_password_errors',
			$error,
			new WP_User( $this->team_id ),
			array(),
			'password'
		);

		$this->assertTrue( $error->has_errors() );
		$this->assertContains( 'team_user_application_password', $error->get_error_codes() );
	}
}
