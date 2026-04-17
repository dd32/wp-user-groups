<?php
/**
 * Tests for multisite-specific behaviour.
 *
 * Run with:  npm run test:multisite
 */
class Test_Multisite extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is not enabled.' );
		}

		Access_Groups::flush_all_caches();
	}

	/* ----- Site scoping ----- */

	public function test_group_with_no_sites_applies_everywhere() {
		$gid = Access_Groups::create_group( 'Global', 'global', 'editor' );
		Access_Groups::set_group_sites( $gid, array() ); // empty = all sites

		$blog2 = self::factory()->blog->create();

		$this->assertTrue( Access_Groups::group_applies_to_site( $gid, get_main_site_id() ) );
		$this->assertTrue( Access_Groups::group_applies_to_site( $gid, $blog2 ) );
	}

	public function test_group_with_selected_sites() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$gid = Access_Groups::create_group( 'Selective', 'selective', 'editor' );
		Access_Groups::set_group_sites( $gid, array( $blog2 ) );

		$this->assertFalse( Access_Groups::group_applies_to_site( $gid, get_main_site_id() ) );
		$this->assertTrue( Access_Groups::group_applies_to_site( $gid, $blog2 ) );
		$this->assertFalse( Access_Groups::group_applies_to_site( $gid, $blog3 ) );
	}

	public function test_set_group_sites_replaces_previous() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$gid = Access_Groups::create_group( 'Swap', 'swap', 'editor' );
		Access_Groups::set_group_sites( $gid, array( $blog2 ) );
		Access_Groups::set_group_sites( $gid, array( $blog3 ) );

		$sites = Access_Groups::get_group_sites( $gid );
		$this->assertContains( $blog3, $sites );
		$this->assertNotContains( $blog2, $sites );
	}

	/* ----- Capabilities scoped by site ----- */

	public function test_caps_granted_on_applicable_site_only() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$gid = Access_Groups::create_group( 'Team', 'team', 'editor' );
		Access_Groups::set_group_sites( $gid, array( $blog2 ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Access_Groups::add_user_to_group( $uid, $gid );

		$caps_main  = Access_Groups::get_capabilities_from_groups( $uid, get_main_site_id() );
		$caps_blog2 = Access_Groups::get_capabilities_from_groups( $uid, $blog2 );
		$caps_blog3 = Access_Groups::get_capabilities_from_groups( $uid, $blog3 );

		$this->assertEmpty( $caps_main );
		$this->assertArrayHasKey( 'edit_others_posts', $caps_blog2 );
		$this->assertEmpty( $caps_blog3 );
	}

	/* ----- is_user_member_of_blog filter ----- */

	public function test_is_member_of_blog_via_group() {
		$blog2 = self::factory()->blog->create();

		$gid = Access_Groups::create_group( 'Team', 'team-blog', 'editor' );
		Access_Groups::set_group_sites( $gid, array( $blog2 ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Access_Groups::add_user_to_group( $uid, $gid );

		$this->assertTrue( is_user_member_of_blog( $uid, $blog2 ) );
	}

	public function test_not_member_of_non_scoped_site() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$gid = Access_Groups::create_group( 'Team', 'team-scope', 'editor' );
		Access_Groups::set_group_sites( $gid, array( $blog2 ) );

		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Access_Groups::add_user_to_group( $uid, $gid );

		$this->assertFalse( is_user_member_of_blog( $uid, $blog3 ) );
	}

	/* ----- get_blogs_of_user filter ----- */

	public function test_blogs_of_user_includes_group_sites() {
		$blog2 = self::factory()->blog->create();

		$gid = Access_Groups::create_group( 'Team', 'team-blogs', 'editor' );
		Access_Groups::set_group_sites( $gid, array( $blog2 ) );

		$uid = self::factory()->user->create();
		Access_Groups::add_user_to_group( $uid, $gid );

		$blogs = get_blogs_of_user( $uid );
		$this->assertArrayHasKey( $blog2, $blogs );
	}

	/* ----- Site deletion ----- */

	public function test_deleting_site_removes_from_group_sites() {
		$blog2 = self::factory()->blog->create();
		$blog3 = self::factory()->blog->create();

		$gid = Access_Groups::create_group( 'Multi', 'multi', 'editor' );
		Access_Groups::set_group_sites( $gid, array( $blog2, $blog3 ) );

		wp_delete_site( $blog2 );
		Access_Groups::flush_all_caches();

		$sites = Access_Groups::get_group_sites( $gid );
		$this->assertNotContains( $blog2, $sites );
		$this->assertContains( $blog3, $sites );
	}
}
