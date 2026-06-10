<?php
declare(strict_types=1);

namespace WPMCP\Modern\Admin;

use WPMCP\Modern\Abilities\AbilityRegistrar;
use WPMCP\Modern\Auth\JwtManager;
use WPMCP\Modern\Mcp\ServerProvider;

/**
 * Admin screen under Settings -> WordPress MCP. Renders a server-side form
 * (master/CRUD/REST-CRUD toggles, per-tool table, JWT management) that a React
 * app — built on WordPress's bundled wp-element/wp-components, no build step —
 * progressively replaces when JavaScript is available. The React app talks to
 * the wpmcp/v1 settings routes and the jwt-auth/v1 token routes.
 */
final class SettingsPage {

	private const SLUG         = 'wordpress-mcp-modern';
	private const SAVE_ACTION  = 'wpmcp_save_settings';
	private const TOKEN_ACTION = 'wpmcp_generate_token';
	private const REVOKE_ACTION = 'wpmcp_revoke_token';
	private const NOTICE_TRANSIENT = 'wpmcp_admin_notice';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_app' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( self::class, 'handle_save' ) );
		add_action( 'admin_post_' . self::TOKEN_ACTION, array( self::class, 'handle_generate_token' ) );
		add_action( 'admin_post_' . self::REVOKE_ACTION, array( self::class, 'handle_revoke_token' ) );
	}

	/**
	 * Enqueue the React settings app (WordPress-bundled React — no build step).
	 */
	public static function enqueue_app( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_script(
			'wpmcp-settings-app',
			WPMCP_MODERN_URL . 'assets/js/settings-app.js',
			array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			WPMCP_MODERN_VERSION,
			true
		);
		wp_add_inline_script(
			'wpmcp-settings-app',
			'window.wpmcpSettingsBoot = ' . (string) wp_json_encode(
				array(
					'endpoint'      => self::endpoint_url(),
					'settingsPath'  => '/' . SettingsRestRoutes::NS . '/settings',
					'tokensBase'    => '/' . \WPMCP\Modern\Auth\JwtRestRoutes::NS,
					'maxExpiration' => JwtManager::max_expiration(),
				)
			) . ';',
			'before'
		);
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'WordPress MCP', 'wordpress-mcp-modern' ),
			__( 'WordPress MCP', 'wordpress-mcp-modern' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function endpoint_url(): string {
		return rest_url( ServerProvider::NAMESPACE . '/' . ServerProvider::ROUTE );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = SettingsStore::all();
		$action   = admin_url( 'admin-post.php' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'WordPress MCP', 'wordpress-mcp-modern' ) . '</h1>';

		// React mount point; the legacy form below stays as the no-JS fallback
		// and is hidden by the app when it mounts.
		echo '<div id="wpmcp-settings-app"></div>';
		echo '<div id="wpmcp-legacy-settings">';

		$notice = get_transient( self::NOTICE_TRANSIENT );
		if ( $notice ) {
			delete_transient( self::NOTICE_TRANSIENT );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'MCP endpoint:', 'wordpress-mcp-modern' ) . ' <code>' . esc_html( self::endpoint_url() ) . '</code></p>';

		// --- Settings + per-tool form ---
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';

		echo '<h2>' . esc_html__( 'General', 'wordpress-mcp-modern' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( 'enabled', __( 'Enable MCP', 'wordpress-mcp-modern' ), $settings['enabled'] );
		self::checkbox_row( 'enable_create_tools', __( 'Enable create tools', 'wordpress-mcp-modern' ), $settings['enable_create_tools'] );
		self::checkbox_row( 'enable_update_tools', __( 'Enable update tools', 'wordpress-mcp-modern' ), $settings['enable_update_tools'] );
		self::checkbox_row( 'enable_delete_tools', __( 'Enable delete tools (destructive)', 'wordpress-mcp-modern' ), $settings['enable_delete_tools'] );
		self::checkbox_row( 'enable_rest_api_crud_tools', __( 'Experimental: REST-CRUD mode (replaces curated tools)', 'wordpress-mcp-modern' ), $settings['enable_rest_api_crud_tools'] );
		self::checkbox_row( 'enable_audit_log', __( 'Audit log (record tool calls)', 'wordpress-mcp-modern' ), $settings['enable_audit_log'] );
		self::checkbox_row( 'enable_rate_limiting', __( 'Rate limiting (tool calls per minute)', 'wordpress-mcp-modern' ), $settings['enable_rate_limiting'] );
		self::checkbox_row( 'enable_oauth', __( 'Experimental: OAuth 2.1 authorization (PKCE + dynamic client registration)', 'wordpress-mcp-modern' ), $settings['enable_oauth'] );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Tools', 'wordpress-mcp-modern' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Individually disable tools. Tools are also gated by the create/update/delete settings above.', 'wordpress-mcp-modern' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Enabled', 'wordpress-mcp-modern' ) . '</th><th>' . esc_html__( 'Tool', 'wordpress-mcp-modern' ) . '</th><th>' . esc_html__( 'Type', 'wordpress-mcp-modern' ) . '</th></tr></thead><tbody>';
		foreach ( AbilityRegistrar::tool_catalog() as $entry ) {
			$tool    = $entry['tool'];
			$checked = SettingsStore::is_tool_enabled( $tool ) ? 'checked' : '';
			echo '<tr>';
			echo '<td><input type="checkbox" name="tool_states[' . esc_attr( $tool ) . ']" value="1" ' . esc_attr( $checked ) . ' /></td>';
			echo '<td><code>' . esc_html( $tool ) . '</code></td>';
			echo '<td>' . esc_html( $entry['type'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p><input type="hidden" name="tools_present" value="1" />';
		submit_button( __( 'Save changes', 'wordpress-mcp-modern' ) );
		echo '</p></form>';

		self::render_token_panel( $action );

		echo '</div>'; // #wpmcp-legacy-settings
		echo '</div>';
	}

	private static function checkbox_row( string $key, string $label, bool $checked ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label><input type="checkbox" name="settings[' . esc_attr( $key ) . ']" value="1" ' . ( $checked ? 'checked' : '' ) . ' /> ';
		echo esc_html__( 'Enabled', 'wordpress-mcp-modern' ) . '</label></td></tr>';
	}

	private static function render_token_panel( string $action ): void {
		echo '<h2>' . esc_html__( 'Authentication tokens (JWT)', 'wordpress-mcp-modern' ) . '</h2>';

		$new_token = get_transient( 'wpmcp_new_token_' . get_current_user_id() );
		if ( $new_token ) {
			delete_transient( 'wpmcp_new_token_' . get_current_user_id() );
			echo '<div class="notice notice-info"><p>' . esc_html__( 'New token (copy now, it will not be shown again):', 'wordpress-mcp-modern' ) . '</p>';
			echo '<p><textarea readonly rows="3" style="width:100%;">' . esc_textarea( $new_token ) . '</textarea></p></div>';
		}

		echo '<form method="post" action="' . esc_url( $action ) . '" style="margin-bottom:1em;">';
		wp_nonce_field( self::TOKEN_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::TOKEN_ACTION ) . '" />';
		echo '<label>' . esc_html__( 'Expires in:', 'wordpress-mcp-modern' ) . ' ';
		echo '<select name="expires_in">';
		foreach ( array( 3600 => '1 hour', 21600 => '6 hours', 86400 => '1 day', 604800 => '7 days', 2592000 => '30 days' ) as $secs => $human ) {
			echo '<option value="' . esc_attr( (string) $secs ) . '">' . esc_html( $human ) . '</option>';
		}
		echo '</select></label> ';
		submit_button( __( 'Generate token', 'wordpress-mcp-modern' ), 'secondary', 'submit', false );
		echo '</form>';

		$tokens = JwtManager::list_tokens();
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'User', 'wordpress-mcp-modern' ) . '</th><th>' . esc_html__( 'Issued', 'wordpress-mcp-modern' ) . '</th><th>' . esc_html__( 'Expires', 'wordpress-mcp-modern' ) . '</th><th>' . esc_html__( 'Status', 'wordpress-mcp-modern' ) . '</th><th></th></tr></thead><tbody>';
		if ( empty( $tokens ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No active tokens.', 'wordpress-mcp-modern' ) . '</td></tr>';
		}
		foreach ( $tokens as $token ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $token['user_id'] ) . '</td>';
			echo '<td>' . esc_html( gmdate( 'Y-m-d H:i', $token['issued_at'] ) ) . '</td>';
			echo '<td>' . esc_html( gmdate( 'Y-m-d H:i', $token['expires_at'] ) ) . '</td>';
			echo '<td>' . ( $token['revoked'] ? esc_html__( 'revoked', 'wordpress-mcp-modern' ) : esc_html__( 'active', 'wordpress-mcp-modern' ) ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( $action ) . '">';
			wp_nonce_field( self::REVOKE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::REVOKE_ACTION ) . '" />';
			echo '<input type="hidden" name="jti" value="' . esc_attr( $token['jti'] ) . '" />';
			submit_button( __( 'Revoke', 'wordpress-mcp-modern' ), 'delete small', 'submit', false );
			echo '</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public static function handle_save(): void {
		self::guard( self::SAVE_ACTION );

		$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
		SettingsStore::update_settings( $raw_settings );

		if ( isset( $_POST['tools_present'] ) ) {
			$states = isset( $_POST['tool_states'] ) && is_array( $_POST['tool_states'] ) ? wp_unslash( $_POST['tool_states'] ) : array();
			foreach ( AbilityRegistrar::tool_catalog() as $entry ) {
				SettingsStore::set_tool_state( $entry['tool'], isset( $states[ $entry['tool'] ] ) );
			}
		}

		self::redirect_with_notice( __( 'Settings saved.', 'wordpress-mcp-modern' ) );
	}

	public static function handle_generate_token(): void {
		self::guard( self::TOKEN_ACTION );
		$expires_in = isset( $_POST['expires_in'] ) ? (int) $_POST['expires_in'] : JwtManager::DEFAULT_EXP;
		$issued     = JwtManager::generate( get_current_user_id(), $expires_in );
		set_transient( 'wpmcp_new_token_' . get_current_user_id(), $issued['token'], 60 );
		self::redirect_with_notice( __( 'Token generated.', 'wordpress-mcp-modern' ) );
	}

	public static function handle_revoke_token(): void {
		self::guard( self::REVOKE_ACTION );
		$jti = isset( $_POST['jti'] ) ? sanitize_text_field( wp_unslash( $_POST['jti'] ) ) : '';
		if ( '' !== $jti ) {
			JwtManager::revoke( $jti );
		}
		self::redirect_with_notice( __( 'Token revoked.', 'wordpress-mcp-modern' ) );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wordpress-mcp-modern' ) );
		}
		check_admin_referer( $action );
	}

	private static function redirect_with_notice( string $message ): void {
		set_transient( self::NOTICE_TRANSIENT, $message, 30 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}
}
