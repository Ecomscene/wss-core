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

	private $hub_client;

	public function __construct( WSS_Hub_Client $hub_client ) {
		$this->hub_client = $hub_client;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_wss_force_sync', array( $this, 'handle_force_sync' ) );
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

	public function handle_force_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		check_admin_referer( 'wss_force_sync' );
		$this->hub_client->force_resync();
		wp_safe_redirect( admin_url( 'index.php?page=' . self::MENU_SLUG . '&synced=1' ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status = $this->hub_client->status();
		$badge_colors = array(
			'approved'     => '#46b450',
			'pending'      => '#ffb900',
			'error'        => '#dc3232',
			'unregistered' => '#a0a5aa',
		);
		$badge = $badge_colors[ $status['status'] ] ?? '#a0a5aa';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Webshopschool', 'wss-core' ); ?></h1>
			<p><?php esc_html_e( 'Welkom bij de Webshopschool Plug-in', 'wss-core' ); ?></p>

			<?php if ( ! empty( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Hub sync forced.', 'wss-core' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Hub status', 'wss-core' ); ?></h2>
			<table class="widefat striped" style="max-width:700px;">
				<tbody>
					<tr>
						<th style="width:180px;"><?php esc_html_e( 'Hub URL', 'wss-core' ); ?></th>
						<td><code><?php echo esc_html( $status['hub_url'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Site UUID', 'wss-core' ); ?></th>
						<td><code><?php echo esc_html( $status['uuid'] ? $status['uuid'] : '(not registered yet)' ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Status', 'wss-core' ); ?></th>
						<td>
							<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;background:<?php echo esc_attr( $badge ); ?>;">
								<?php echo esc_html( $status['status'] ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last sync', 'wss-core' ); ?></th>
						<td><?php echo $status['last_sync'] ? esc_html( human_time_diff( $status['last_sync'] ) . ' ago' ) : '—'; ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active snippets', 'wss-core' ); ?></th>
						<td><?php echo (int) count( array_filter( $status['snippets'], static function ( $s ) { return ! empty( $s['active'] ); } ) ); ?> / <?php echo (int) count( $status['snippets'] ); ?></td>
					</tr>
					<?php if ( ! empty( $status['last_error'] ) ) : ?>
					<tr>
						<th><?php esc_html_e( 'Last error', 'wss-core' ); ?></th>
						<td><code style="color:#dc3232;"><?php echo esc_html( $status['last_error'] ); ?></code></td>
					</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Plugin version', 'wss-core' ); ?></th>
						<td><?php echo esc_html( WSS_CORE_VERSION ); ?></td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top:1em;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'wss_force_sync' ); ?>
					<input type="hidden" name="action" value="wss_force_sync" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Force sync now', 'wss-core' ); ?></button>
				</form>
			</p>

			<?php if ( ! empty( $status['snippets'] ) ) : ?>
				<h2 style="margin-top:2em;"><?php esc_html_e( 'Assigned snippets', 'wss-core' ); ?></h2>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'wss-core' ); ?></th>
							<th><?php esc_html_e( 'Name', 'wss-core' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wss-core' ); ?></th>
							<th><?php esc_html_e( 'Active', 'wss-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $status['snippets'] as $s ) : ?>
						<tr>
							<td><?php echo esc_html( $s['id'] ); ?></td>
							<td><?php echo esc_html( $s['name'] ); ?></td>
							<td><code><?php echo esc_html( $s['type'] ); ?></code></td>
							<td><?php echo ! empty( $s['active'] ) ? '✅' : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
