<?php
/**
 * Admin page registration.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_Admin {

	const MENU_SLUG = 'wss-core';

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu() {
		add_dashboard_page(
			__( 'Webshopschool', 'wss-core' ),
			__( 'Webshopschool', 'wss-core' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Webshopschool', 'wss-core' ); ?></h1>
			<p><?php esc_html_e( 'Welkom bij de Webshopschool Plug-in', 'wss-core' ); ?></p>
			<p style="color:#666;font-size:12px;">
				<?php
				printf(
					/* translators: %s: plugin version */
					esc_html__( 'WSS Core versie %s', 'wss-core' ),
					esc_html( WSS_CORE_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}
}
