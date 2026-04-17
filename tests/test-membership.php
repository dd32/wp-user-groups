<?php
/**
 * Tests for user ↔ group membership.
 */
class Test_Membership extends WP_UnitTestCase {

	private $group_id;
	private $user_id;

	public function set_up() {
		parent::set_up();
		WP_User_Groups::flush_all_caches();

		$this->group_id = WP_User_Groups::create_group( 'Test Group', 'test', 'editor' );
		$this->user_id  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function test_add_user_to_group() {
		$result = WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		$this->assertTrue( $result );

		$ids = WP_User_Groups::get_user_group_ids( $this->user_id );
		$this->assertContains( $this->group_id, $ids );
	}

	public function test_add_user_to_nonexistent_group() {
		$result = WP_User_Groups::add_user_to_group( $this->user_id, 99999 );
		$this->assertFalse( $result );
	}

	public function test_add_user_twice_is_idempotent() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );

		$ids = WP_User_Groups::get_user_group_ids( $this->user_id );
		$this->assertCount( 1, $ids );
	}

	public function test_remove_user_from_group() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		WP_User_Groups::remove_user_from_group( $this->user_id, $this->group_id );

		$ids = WP_User_Groups::get_user_group_ids( $this->user_id );
		$this->assertNotContains( $this->group_id, $ids );
	}

	public function test_set_user_groups() {
		$g2 = WP_User_Groups::create_group( 'Second', 'second', 'author' );
		$g3 = WP_User_Groups::create_group( 'Third', 'third', 'contributor' );

		WP_User_Groups::set_user_groups( $this->user_id, array( $this->group_id, $g2, $g3 ) );
		$ids = WP_User_Groups::get_user_group_ids( $this->user_id );

		$this->assertCount( 3, $ids );
		$this->assertContains( $this->group_id, $ids );
		$this->assertContains( $g2, $ids );
		$this->assertContains( $g3, $ids );
	}

	public function test_set_user_groups_replaces_previous() {
		$g2 = WP_User_Groups::create_group( 'Other', 'other' );

		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		WP_User_Groups::set_user_groups( $this->user_id, array( $g2 ) );

		$ids = WP_User_Groups::get_user_group_ids( $this->user_id );
		$this->assertNotContains( $this->group_id, $ids );
		$this->assertContains( $g2, $ids );
	}

	public function test_set_user_groups_ignores_invalid_ids() {
		WP_User_Groups::set_user_groups( $this->user_id, array( 99999, $this->group_id ) );

		$ids = WP_User_Groups::get_user_group_ids( $this->user_id );
		$this->assertCount( 1, $ids );
		$this->assertContains( $this->group_id, $ids );
	}

	public function test_get_user_groups_returns_full_records() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );

		$groups = WP_User_Groups::get_user_groups( $this->user_id );
		$this->assertArrayHasKey( $this->group_id, $groups );
		$this->assertSame( 'Test Group', $groups[ $this->group_id ]['name'] );
	}

	public function test_get_group_members() {
		$u2 = self::factory()->user->create();
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		WP_User_Groups::add_user_to_group( $u2, $this->group_id );

		$members = WP_User_Groups::get_group_members( $this->group_id );
		$this->assertCount( 2, $members );
		$this->assertContains( $this->user_id, $members );
		$this->assertContains( $u2, $members );
	}

	public function test_count_members_per_group() {
		$g2 = WP_User_Groups::create_group( 'Other', 'other', 'author' );
		$u2 = self::factory()->user->create();

		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		WP_User_Groups::add_user_to_group( $u2, $this->group_id );
		WP_User_Groups::add_user_to_group( $this->user_id, $g2 );

		$counts = WP_User_Groups::count_members_per_group();
		$this->assertSame( 2, $counts[ $this->group_id ] );
		$this->assertSame( 1, $counts[ $g2 ] );
	}

	public function test_deleting_group_removes_memberships() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		WP_User_Groups::delete_group( $this->group_id );

		$ids = WP_User_Groups::get_user_group_ids( $this->user_id );
		$this->assertNotContains( $this->group_id, $ids );
	}

	public function test_deleting_user_clears_memberships() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );

		wp_delete_user( $this->user_id );
		WP_User_Groups::flush_all_caches();

		$members = WP_User_Groups::get_group_members( $this->group_id );
		$this->assertNotContains( $this->user_id, $members );
	}

	/* ----- Per-group marker index ----- */

	public function test_meta_keys_use_base_prefix() {
		global $wpdb;
		$this->assertSame( $wpdb->base_prefix . 'user_groups', WP_User_Groups::user_meta_key() );
		$this->assertSame( $wpdb->base_prefix . 'user_group_7', WP_User_Groups::group_meta_key( 7 ) );
	}

	public function test_adding_user_sets_marker_row() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );

		$marker = get_user_meta( $this->user_id, WP_User_Groups::group_meta_key( $this->group_id ), true );
		$this->assertSame( '1', (string) $marker );
	}

	public function test_removing_user_deletes_marker_row() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );
		WP_User_Groups::remove_user_from_group( $this->user_id, $this->group_id );

		$marker = get_user_meta( $this->user_id, WP_User_Groups::group_meta_key( $this->group_id ), true );
		$this->assertSame( '', (string) $marker );
	}

	public function test_set_user_groups_syncs_markers() {
		$g2 = WP_User_Groups::create_group( 'Second', 'second', 'author' );

		WP_User_Groups::set_user_groups( $this->user_id, array( $this->group_id ) );
		WP_User_Groups::set_user_groups( $this->user_id, array( $g2 ) );

		$this->assertSame( '', (string) get_user_meta( $this->user_id, WP_User_Groups::group_meta_key( $this->group_id ), true ) );
		$this->assertSame( '1', (string) get_user_meta( $this->user_id, WP_User_Groups::group_meta_key( $g2 ), true ) );
	}

	public function test_get_group_members_uses_marker_index() {
		// Write the primary array directly without markers, simulating legacy data.
		update_user_meta( $this->user_id, WP_User_Groups::user_meta_key(), array( $this->group_id ) );

		// With no marker yet, the indexed lookup sees nobody.
		$members = WP_User_Groups::get_group_members( $this->group_id );
		$this->assertEmpty( $members );

		// Rebuilding the index backfills the marker and the member appears.
		WP_User_Groups::rebuild_membership_index();

		$members = WP_User_Groups::get_group_members( $this->group_id );
		$this->assertContains( $this->user_id, $members );
	}

	public function test_rebuild_is_idempotent() {
		WP_User_Groups::add_user_to_group( $this->user_id, $this->group_id );

		WP_User_Groups::rebuild_membership_index();
		WP_User_Groups::rebuild_membership_index();

		$members = WP_User_Groups::get_group_members( $this->group_id );
		$this->assertCount( 1, $members );
		$this->assertContains( $this->user_id, $members );
	}
}
