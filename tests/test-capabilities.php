<?php
/**
 * Tests for the user_has_cap filter: group-derived capabilities.
 */
class Test_Capabilities extends WP_UnitTestCase {

	private $group_id;
	private $user_id;

	public function set_up() {
		parent::set_up();
		Access_Groups::flush_all_caches();

		$this->group_id = Access_Groups::create_group( 'Editors', 'editors', 'editor' );
		$this->user_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_subscriber_lacks_edit_posts_by_default() {
		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_posts' ) );
	}

	public function test_group_grants_role_capabilities() {
		Access_Groups::add_user_to_group( $this->user_id, $this->group_id );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_posts' ) );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) );
		$this->assertTrue( $user->has_cap( 'publish_posts' ) );
	}

	public function test_removing_from_group_drops_caps() {
		Access_Groups::add_user_to_group( $this->user_id, $this->group_id );
		Access_Groups::remove_user_from_group( $this->user_id, $this->group_id );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}

	public function test_user_retains_own_caps_alongside_group() {
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_group = Access_Groups::create_group( 'Admins', 'admins', 'administrator' );

		Access_Groups::add_user_to_group( $user_id, $admin_group );

		$user = new WP_User( $user_id );
		$this->assertTrue( $user->has_cap( 'manage_options' ) ); // from admin group
		$this->assertTrue( $user->has_cap( 'edit_posts' ) );     // from author + admin
	}

	public function test_multiple_groups_merge_caps() {
		$author_group = Access_Groups::create_group( 'Authors', 'authors', 'author' );

		Access_Groups::add_user_to_group( $this->user_id, $this->group_id );  // editor
		Access_Groups::add_user_to_group( $this->user_id, $author_group );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) ); // editor cap
		$this->assertTrue( $user->has_cap( 'upload_files' ) );       // author cap
	}

	public function test_group_without_role_grants_nothing() {
		$empty = Access_Groups::create_group( 'No Role', 'no-role', '' );
		Access_Groups::add_user_to_group( $this->user_id, $empty );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_posts' ) );
	}

	public function test_deleting_group_drops_caps() {
		Access_Groups::add_user_to_group( $this->user_id, $this->group_id );
		Access_Groups::delete_group( $this->group_id );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}

	public function test_changing_group_role_changes_caps() {
		Access_Groups::add_user_to_group( $this->user_id, $this->group_id );

		$user = new WP_User( $this->user_id );
		$this->assertTrue( $user->has_cap( 'edit_others_posts' ) );

		Access_Groups::update_group( $this->group_id, array( 'role' => 'subscriber' ) );

		$user = new WP_User( $this->user_id );
		$this->assertFalse( $user->has_cap( 'edit_others_posts' ) );
	}
}
