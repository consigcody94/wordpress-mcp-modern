<?php
declare(strict_types=1);

namespace WPMCP\Modern\Admin;

/**
 * Network-admin screen (multisite only): a network-wide kill switch stored as
 * a site option. When off, SettingsStore::is_enabled() returns false on every
 * site regardless of per-site settings, so no MCP surface is exposed anywhere
 * on the network.
 */
final class NetworkSettingsPage {

	public const OPTION      = 'wpmcp_network_enabled';
	private const SLUG       = 'wordpress-mcp-modern-network';
	private const SAVE_ACTION = 'wpmcp_save_network_settings';

	public static function register(): void {
		add_action( 'network_admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'network_admin_edit_' . self::SAVE_ACTION, array( self::class, 'handle_save' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'settings.php',
			__( 'WordPress MCP', 'wordpress-mcp-modern' ),
			__( 'WordPress MCP', 'wordpress-mcp-modern' ),
			'manage_network_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		$enabled = (bool) get_site_option( self::OPTION, true );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'WordPress MCP (Network)', 'wordpress-mcp-modern' ) . '</h1>';
		echo '<p>' . esc_html__( 'Network-wide kill switch. When disabled, MCP is off on every site, regardless of per-site settings.', 'wordpress-mcp-modern' ) . '</p>';
		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=' . self::SAVE_ACTION ) ) . '">';
		wp_nonce_field( self::SAVE_ACTION );
		echo '<label><input type="checkbox" name="network_enabled" value="1" ' . ( $enabled ? 'checked' : '' ) . ' /> ';
		echo esc_html__( 'Allow MCP on this network', 'wordpress-mcp-modern' ) . '</label>';
		submit_button( __( 'Save changes', 'wordpress-mcp-modern' ) );
		echo '</form></div>';
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wordpress-mcp-modern' ) );
		}
		check_admin_referer( self::SAVE_ACTION );

		update_site_option( self::OPTION, isset( $_POST['network_enabled'] ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'updated' => 'true',
				),
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}
}
