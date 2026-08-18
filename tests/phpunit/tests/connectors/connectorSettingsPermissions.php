<?php

/**
 * Tests that connector credentials cannot be written without the `manage_connectors` capability.
 *
 * @group connectors
 * @group restapi
 * @covers ::_wp_connectors_rest_prevent_setting_update
 * @covers ::_wp_connectors_option_page_capability
 * @covers ::_wp_connectors_get_credential_setting_names
 */
class Tests_Connectors_ConnectorSettingsPermissions extends WP_UnitTestCase {

	const CONNECTOR_ID = 'wp_test_permissions_connector';
	const SETTING_NAME = 'connectors_test_permissions_api_key';

	/**
	 * A user with the administrator role.
	 *
	 * @var int
	 */
	private static $administrator;

	/**
	 * A user with the editor role.
	 *
	 * @var int
	 */
	private static $editor;

	/**
	 * Snapshot of registered settings before each test.
	 *
	 * @var array
	 */
	private array $original_registered_settings = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$administrator = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor        = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up(): void {
		parent::set_up();

		global $wp_registered_settings, $wp_rest_server;
		$this->original_registered_settings = is_array( $wp_registered_settings ) ? $wp_registered_settings : array();

		WP_Connector_Registry::get_instance()->register(
			self::CONNECTOR_ID,
			array(
				'name'           => 'Test Permissions Connector',
				'description'    => '',
				'type'           => 'spam_filtering',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => self::SETTING_NAME,
				),
				'plugin'         => array(
					'file'      => 'test/test.php',
					'is_active' => '__return_true',
				),
			)
		);

		_wp_register_default_connector_settings();

		$wp_rest_server = new Spy_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		$registry = WP_Connector_Registry::get_instance();
		if ( null !== $registry && $registry->is_registered( self::CONNECTOR_ID ) ) {
			$registry->unregister( self::CONNECTOR_ID );
		}

		global $wp_registered_settings, $wp_rest_server;
		$wp_registered_settings = $this->original_registered_settings;
		$wp_rest_server         = null;

		delete_site_option( 'allow_connectors' );

		parent::tear_down();
	}

	/**
	 * Sends a settings update for the test connector's API key.
	 *
	 * @param string $api_key The API key to submit.
	 * @return WP_REST_Response The response object.
	 */
	private function update_api_key_via_rest( string $api_key ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/settings' );
		$request->set_param( self::SETTING_NAME, $api_key );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @ticket 65803
	 */
	public function test_credential_setting_names_are_collected(): void {
		$this->assertContains( self::SETTING_NAME, _wp_connectors_get_credential_setting_names() );
	}

	/**
	 * @ticket 65803
	 */
	public function test_connectors_option_group_requires_manage_connectors(): void {
		$this->assertSame(
			'manage_connectors',
			apply_filters( 'option_page_capability_connectors', 'manage_options' )
		);
	}

	/**
	 * @ticket 65803
	 */
	public function test_rest_update_is_blocked_without_the_capability(): void {
		wp_set_current_user( self::$editor );

		$this->assertTrue( _wp_connectors_rest_prevent_setting_update( false, self::SETTING_NAME ) );
	}

	/**
	 * @ticket 65803
	 */
	public function test_rest_update_is_allowed_with_the_capability(): void {
		wp_set_current_user( self::$administrator );

		$this->assertFalse( _wp_connectors_rest_prevent_setting_update( false, self::SETTING_NAME ) );
	}

	/**
	 * Unrelated settings must keep working for users who cannot manage connectors.
	 *
	 * @ticket 65803
	 */
	public function test_unrelated_settings_are_not_blocked(): void {
		wp_set_current_user( self::$editor );

		$this->assertFalse( _wp_connectors_rest_prevent_setting_update( false, 'blogname' ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-excluded
	 */
	public function test_administrator_can_save_an_api_key_on_single_site(): void {
		wp_set_current_user( self::$administrator );

		$response = $this->update_api_key_via_rest( 'single-site-key' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'single-site-key', get_option( self::SETTING_NAME ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_site_administrator_can_save_an_api_key_when_allowed(): void {
		update_site_option( 'allow_connectors', '1' );
		wp_set_current_user( self::$administrator );

		$response = $this->update_api_key_via_rest( 'allowed-key' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'allowed-key', get_option( self::SETTING_NAME ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_site_administrator_cannot_save_an_api_key_when_restricted(): void {
		update_site_option( 'allow_connectors', '0' );
		wp_set_current_user( self::$administrator );

		$this->update_api_key_via_rest( 'restricted-key' );

		$this->assertSame( '', get_option( self::SETTING_NAME ) );
	}

	/**
	 * A restricted administrator must not be able to overwrite a key set by someone else.
	 *
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_restricted_site_administrator_cannot_replace_an_existing_api_key(): void {
		update_option( self::SETTING_NAME, 'existing-key' );
		update_site_option( 'allow_connectors', '0' );
		wp_set_current_user( self::$administrator );

		$this->update_api_key_via_rest( 'replacement-key' );

		$this->assertSame( 'existing-key', get_option( self::SETTING_NAME ) );
	}

	/**
	 * @ticket 65803
	 * @group ms-required
	 */
	public function test_restricted_site_administrator_cannot_remove_an_existing_api_key(): void {
		update_option( self::SETTING_NAME, 'existing-key' );
		update_site_option( 'allow_connectors', '0' );
		wp_set_current_user( self::$administrator );

		$this->update_api_key_via_rest( '' );

		$this->assertSame( 'existing-key', get_option( self::SETTING_NAME ) );
	}
}
