<?php
/**
 * Self-hosted updates from GitHub Releases.
 *
 * @package PayWithXara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves plugin updates from the latest GitHub release of a repository.
 */
class Xara_WC_Updater {

	const CACHE_KEY = 'xara_wc_latest_release';

	/**
	 * Plugin basename, e.g. pay-with-xara/pay-with-xara.php.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin directory slug, e.g. pay-with-xara.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * GitHub repository, e.g. Wharley01/pay-with-xara.
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Installed version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $repo        GitHub owner/name.
	 * @param string $version     Installed version.
	 */
	public function __construct( $plugin_file, $repo, $version ) {
		$this->basename = plugin_basename( $plugin_file );
		$this->slug     = dirname( $this->basename );
		$this->repo     = $repo;
		$this->version  = $version;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * @return array|null
	 */
	private function get_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'PayWithXara-WooCommerce/' . $this->version,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, '', 6 * HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, '', 6 * HOUR_IN_SECONDS );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * @param array $release
	 * @return string
	 */
	private function release_version( array $release ) {
		return isset( $release['tag_name'] ) ? ltrim( (string) $release['tag_name'], 'vV' ) : '';
	}

	/**
	 * Prefer an uploaded .zip asset (correct folder structure) over the zipball.
	 *
	 * @param array $release
	 * @return string
	 */
	private function package_url( array $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		return isset( $release['zipball_url'] ) ? $release['zipball_url'] : '';
	}

	/**
	 * @param object $transient
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest = $this->release_version( $release );
		if ( ! $latest || version_compare( $latest, $this->version, '<=' ) ) {
			return $transient;
		}

		$package = $this->package_url( $release );
		if ( ! $package ) {
			return $transient;
		}

		$transient->response[ $this->basename ] = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $latest,
			'package'     => $package,
			'url'         => 'https://github.com/' . $this->repo,
		);

		return $transient;
	}

	/**
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Pay with Xara',
			'slug'          => $this->slug,
			'version'       => $this->release_version( $release ),
			'author'        => '<a href="https://usexara.ai">Xara</a>',
			'homepage'      => 'https://github.com/' . $this->repo,
			'download_link' => $this->package_url( $release ),
			'sections'      => array(
				'changelog' => ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : '',
			),
		);
	}

	/**
	 * Rename the extracted folder to the plugin slug when installing a zipball.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param object $upgrader
	 * @param array  $hook_extra
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		if ( basename( untrailingslashit( $source ) ) === $this->slug ) {
			return $source;
		}

		global $wp_filesystem;
		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}
}
