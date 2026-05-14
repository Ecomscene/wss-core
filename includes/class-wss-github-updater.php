<?php
/**
 * GitHub release-based updater for WSS Core.
 *
 * @package WSS_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_GitHub_Updater {

	const TRANSIENT_KEY = 'wss_core_github_release';
	const CACHE_TTL     = 6 * HOUR_IN_SECONDS;
	const ASSET_NAME    = 'wss-core.zip';

	private $owner;
	private $repo;
	private $file;
	private $version;
	private $basename;
	private $slug;

	public function __construct( array $config ) {
		$this->owner    = $config['owner'];
		$this->repo     = $config['repo'];
		$this->file     = $config['file'];
		$this->version  = $config['version'];
		$this->basename = $config['basename'];
		$this->slug     = $config['slug'];
	}

	public function register() {
		add_filter( 'site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_update' ), 10, 2 );
	}

	public function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( ! isset( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = $this->normalize_version( $release['tag_name'] );
		if ( ! $latest_version ) {
			return $transient;
		}

		if ( version_compare( $latest_version, $this->version, '>' ) ) {
			$package = $this->get_package_url( $release );

			$update = (object) array(
				'id'            => $this->basename,
				'slug'          => $this->slug,
				'plugin'        => $this->basename,
				'new_version'   => $latest_version,
				'url'           => $this->repo_url(),
				'package'       => $package,
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '7.4',
				'compatibility' => new stdClass(),
			);

			$transient->response[ $this->basename ] = $update;
		} else {
			$no_update = (object) array(
				'id'            => $this->basename,
				'slug'          => $this->slug,
				'plugin'        => $this->basename,
				'new_version'   => $this->version,
				'url'           => $this->repo_url(),
				'package'       => '',
				'icons'         => array(),
				'banners'       => array(),
				'banners_rtl'   => array(),
				'tested'        => '',
				'requires_php'  => '7.4',
				'compatibility' => new stdClass(),
			);
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->basename ] = $no_update;
		}

		return $transient;
	}

	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$latest_version = $this->normalize_version( $release['tag_name'] );

		$info = (object) array(
			'name'           => 'WSS Core',
			'slug'           => $this->slug,
			'version'        => $latest_version ? $latest_version : $this->version,
			'author'         => '<a href="https://webshopschool.nl">Webshopschool by Joey</a>',
			'homepage'       => $this->repo_url(),
			'requires'       => '5.8',
			'tested'         => '',
			'requires_php'   => '7.4',
			'download_link'  => $this->get_package_url( $release ),
			'last_updated'   => isset( $release['published_at'] ) ? $release['published_at'] : '',
			'sections'       => array(
				'description' => '<p>' . esc_html__( 'Lightweight base plugin for Webshopschool client websites. Provides a stable foundation with GitHub-based auto-updates.', 'wss-core' ) . '</p>',
				'changelog'   => $this->format_changelog( $release ),
			),
			'banners'        => array(),
			'icons'          => array(),
		);

		return $info;
	}

	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_string( $source ) || ! is_dir( $source ) ) {
			return $source;
		}

		$plugin_basename = isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '';
		if ( $plugin_basename !== $this->basename ) {
			return $source;
		}

		$expected_dirname = $this->slug;
		$current_dirname  = basename( untrailingslashit( $source ) );

		if ( $current_dirname === $expected_dirname ) {
			return $source;
		}

		$parent_dir = trailingslashit( dirname( untrailingslashit( $source ) ) );
		$new_source = $parent_dir . $expected_dirname;

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $new_source ), true ) ) {
			return trailingslashit( $new_source );
		}

		return $source;
	}

	public function clear_cache_after_update( $upgrader, $options ) {
		if ( ! is_array( $options ) ) {
			return;
		}
		if ( ( $options['action'] ?? '' ) !== 'update' ) {
			return;
		}
		if ( ( $options['type'] ?? '' ) !== 'plugin' ) {
			return;
		}
		$plugins = $options['plugins'] ?? array();
		if ( in_array( $this->basename, (array) $plugins, true ) ) {
			delete_transient( self::TRANSIENT_KEY );
		}
	}

	private function get_latest_release() {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = wp_remote_get( $url, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WSS-Core-Updater',
			),
		) );

		if ( is_wp_error( $response ) ) {
			set_transient( self::TRANSIENT_KEY, array( 'error' => true ), MINUTE_IN_SECONDS * 15 );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			set_transient( self::TRANSIENT_KEY, array( 'error' => true ), MINUTE_IN_SECONDS * 15 );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::TRANSIENT_KEY, array( 'error' => true ), MINUTE_IN_SECONDS * 15 );
			return false;
		}

		set_transient( self::TRANSIENT_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	private function get_package_url( $release ) {
		if ( ! is_array( $release ) ) {
			return '';
		}

		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && self::ASSET_NAME === $asset['name'] ) {
					return $asset['browser_download_url'];
				}
			}
		}

		if ( ! empty( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}

		return '';
	}

	private function normalize_version( $tag ) {
		if ( ! is_string( $tag ) || '' === $tag ) {
			return '';
		}
		return ltrim( trim( $tag ), 'vV' );
	}

	private function repo_url() {
		return sprintf( 'https://github.com/%s/%s', $this->owner, $this->repo );
	}

	private function format_changelog( $release ) {
		$tag  = isset( $release['tag_name'] ) ? $release['tag_name'] : '';
		$body = isset( $release['body'] ) ? $release['body'] : '';

		$html  = '<h4>' . esc_html( $tag ) . '</h4>';
		$html .= '<pre style="white-space:pre-wrap;">' . esc_html( $body ) . '</pre>';
		return $html;
	}
}
