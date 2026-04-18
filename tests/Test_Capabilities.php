<?php
/**
 * Tests for the user_has_cap filter: team-derived capabilities.
 */
class Test_Capabilities extends WP_UnitTestCase {

	private $team_id;
	private $user_id;

	public function set_up() {
		parent::set_up();
		WP_User_Teams::flush_all_caches();

		$this->team_id = WP_User_Teams::create_team( 'Editors', 'editors', 'editor' );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_subscriber_lacks_edit_posts_by_default() {
		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_posts' ) );
	}

	public function test_team_grants_role_capabilities() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_posts' ) );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) );
		$this->assertTrue( $user->has_cap( 'publish_posts' ) );
	}

	public function test_removing_from_team_drops_caps() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::remove_user_from_team( $this->user_id, $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}

	public function test_user_retains_own_caps_alongside_team() {
		$user_id    = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_team = WP_User_Teams::create_team( 'Admins', 'admins', 'administrator' );

		WP_User_Teams::add_user_to_team( $user_id, $admin_team );

		$user = new WP_User( $user_id );
		$this->assertTrue( $user->has_cap( 'manage_options' ) ); // from admin team
		$this->assertTrue( $user->has_cap( 'edit_posts' ) );     // from author + admin
	}

	public function test_multiple_teams_merge_caps() {
		$author_team = WP_User_Teams::create_team( 'Authors', 'authors', 'author' );

		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );  // editor
		WP_User_Teams::add_user_to_team( $this->user_id, $author_team );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) ); // editor cap
		$this->assertTrue( $user->has_cap( 'upload_files' ) );       // author cap
	}

	public function test_team_without_role_grants_nothing() {
		$empty = WP_User_Teams::create_team( 'No Role', 'no-role', '' );
		WP_User_Teams::add_user_to_team( $this->user_id, $empty );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_posts' ) );
	}

	public function test_deleting_team_drops_caps() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );
		WP_User_Teams::delete_team( $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}

	public function test_changing_team_role_changes_caps() {
		WP_User_Teams::add_user_to_team( $this->user_id, $this->team_id );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) );

		WP_User_Teams::update_team( $this->team_id, array( 'role' => 'subscriber' ) );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}
}
