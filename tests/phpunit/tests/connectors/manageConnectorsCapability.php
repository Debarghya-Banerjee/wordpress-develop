<?php

/**
 * Tests for the `manage_connectors` meta capability.
 *
 * @group connectors
 * @group capabilities
 * @covers ::map_meta_cap
 */
class Tests_Connectors_ManageConnectorsCapability extends WP_UnitTestCase {

	/**
	 * A user with the administrator role.
	 *
	 * @var int
	 */
	private static $administrator;

	/**
	 * A second user with the administrator role.
	 *
	 * @var int
	 */
	private static $second_administrator;

	/**
	 * A user with the editor role.
	 *
	 * @var int
	 */
	private static $editor;

	/**
	 * A user with the administrator role who is also a super admin.
	 *
	 * @var int
	 */
	private static $super_admin;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$administrator        = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$second_administrator = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor               = $factory->user->create( array( 'role' => 'editor' ) );
		self::$super_admin          = $factory->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( self::$super_admin );
		}
	}

	public function tear_down(): void {
		delete_site_option( 'allow_connectors' );

		parent::tear_down();
	}

	/**
	 * @ticket 65803
	 */
	public function test_maps_to_manage_options(): void {
		$this->assertSame(
			array( 'manage_options' ),
			map_meta_cap( 'manage_connectors', self::$administrator )
		);
	}

	/**
	 * Administrators manage connectors on single site, matching the pre-existing behavior.
	 *
	 * @ticket 65803
	 * @group ms-excluded
	 */
	public function test_administrator_can_manage_connectors_on_single_site(): void {
		$this->assertTrue( user_can( self::$administrator, 'manage_connectors' ) );
	}

	/**
	 * The network setting is a Multisite concept and must not leak into single site.
	 *
	 * @ticket 65803
	 * @group ms-excluded
	 */
	public function test_single_site_is_unaffected_by_the_network_setting(): void {
		update_site_option( 'allow_connectors', '0' );

		$this->assertTrue( user_can( self::$administrator, 'manage_connectors' ) );
	}

	/**
	 * @ticket 65803
	 */
	public function test_editor_cannot_manage_connectors(): void {
		$this->assertFalse( user_can( self::$editor, 'manage_connectors' ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_super_admin_can_manage_connectors_when_restricted(): void {
		update_site_option( 'allow_connectors', '0' );

		$this->assertTrue( user_can( self::$super_admin, 'manage_connectors' ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_site_administrator_can_manage_connectors_when_allowed(): void {
		update_site_option( 'allow_connectors', '1' );

		$this->assertTrue( user_can( self::$administrator, 'manage_connectors' ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_site_administrator_cannot_manage_connectors_when_restricted(): void {
		update_site_option( 'allow_connectors', '0' );

		$this->assertFalse( user_can( self::$administrator, 'manage_connectors' ) );
	}

	/**
	 * Every administrator on a site is restricted, not just the one who stored the credentials.
	 *
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_all_site_administrators_are_restricted(): void {
		update_site_option( 'allow_connectors', '0' );

		$this->assertFalse( user_can( self::$administrator, 'manage_connectors' ), 'The first administrator was not restricted.' );
		$this->assertFalse( user_can( self::$second_administrator, 'manage_connectors' ), 'The second administrator was not restricted.' );
	}

	/**
	 * Networks created before this setting existed must keep working as they did.
	 *
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_site_administrator_can_manage_connectors_when_setting_is_missing(): void {
		delete_site_option( 'allow_connectors' );

		$this->assertTrue( user_can( self::$administrator, 'manage_connectors' ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_restriction_requires_manage_network_options(): void {
		update_site_option( 'allow_connectors', '0' );

		$this->assertSame(
			array( 'manage_options', 'manage_network_options' ),
			map_meta_cap( 'manage_connectors', self::$administrator )
		);
	}

	/**
	 * Disabling the Plugins menu for site administrators is a separate setting and must
	 * not restrict connector management on its own.
	 *
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_disabling_the_plugins_menu_does_not_restrict_connectors(): void {
		update_site_option( 'menu_items', array() );

		$this->assertFalse( user_can( self::$administrator, 'activate_plugins' ), 'The administrator could activate plugins.' );
		$this->assertTrue( user_can( self::$administrator, 'manage_connectors' ), 'The administrator could not manage connectors.' );
	}

	/**
	 * Connectors are not exclusively AI providers, so the capability does not track AI support.
	 *
	 * @ticket 65803
	 */
	public function test_capability_is_independent_of_ai_support(): void {
		add_filter( 'wp_supports_ai', '__return_false' );

		$this->assertFalse( wp_supports_ai(), 'AI support was not disabled.' );
		$this->assertTrue( user_can( self::$administrator, 'manage_connectors' ), 'The administrator could not manage connectors.' );
	}
}
