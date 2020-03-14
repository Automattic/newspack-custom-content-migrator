<?php

namespace NewspackCustomContentMigrator;

use \WP_CLI;

/**
 * PluginInstaller class.
 */
class PluginInstaller {

	/**
	 * @var null|PluginInstaller Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return PluginInstaller|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::require_dependencies_from_core();
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * Ensures dependencies from WP are available.
	 */
	private static function require_dependencies_from_core() {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-includes/pluggable.php';
	}

	/**
	 * Checks whether a plugin is installed.
	 *
	 * @param string $plugin_slug Plugin slug.
	 *
	 * @return bool Is the plugin installed.
	 */
	public static function is_installed( $plugin_slug ) {
		$plugins = array_reduce( array_keys( get_plugins() ), array( __CLASS__, 'reduce_plugin_info' ) );

		return array_key_exists( $plugin_slug, $plugins );
	}

	/**
	 * Checks whether a plugin is active.
	 *
	 * @param string $plugin_slug Plugin slug.
	 *
	 * @return bool Is the plugin active.
	 */
	public static function is_active( $plugin_slug ) {
		$is_active = false;
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( $plugin, $plugin_slug . '.php' ) ) {
				$is_active = true;
			}
		}

		return $is_active;
	}

	/**
	 * Activates a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 *
	 * @throws \Exception Thrown if an error occurs.
	 */
	public function activate( $plugin_slug ) {
		$plugins = array_reduce( array_keys( get_plugins() ), array( __CLASS__, 'reduce_plugin_info' ) );
		$activated = activate_plugin( $plugins[ $plugin_slug ] );
		if ( is_wp_error( $activated ) ) {
			throw new \Exception( $activated->get_error_message() );
		}
	}

	/**
	 * Installs a plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 *
	 * @throws \Exception Thrown if an error occurs.
	 */
	public function install( $plugin_slug ) {
		$plugin_info = plugins_api(
			'plugin_information',
			[
				'slug'   => $plugin_slug,
				'fields' => [
					'short_description' => false,
					'sections'          => false,
					'requires'          => false,
					'rating'            => false,
					'ratings'           => false,
					'downloaded'        => false,
					'last_updated'      => false,
					'added'             => false,
					'tags'              => false,
					'compatibility'     => false,
					'homepage'          => false,
					'donate_link'       => false,
				],
			]
		);

		if ( is_wp_error( $plugin_info ) ) {
			throw new \Exception( "plugin_api error, " . $plugin_info->get_error_message() );
		}

		self::install_from_url( $plugin_info->download_link );
	}

	/**
	 * Downloads and installs a plugin from URL.
	 *
	 * @param string $plugin_url Plugin URL.
	 *
	 * @throws \Exception Thrown if an error occurs.
	 */
	private static function install_from_url( $plugin_url ) {
		WP_Filesystem();

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \WP_Upgrader( $skin );
		$upgrader->init();

		$download = $upgrader->download_package( $plugin_url );
		if ( is_wp_error( $download ) ) {
			throw new \Exception( $download->get_error_message() );
		}

		// GitHub appends random strings to the end of its downloads.
		// If we asked for foo.zip, make sure the downloaded file is called foo.tmp.
		if ( stripos( $plugin_url, 'github' ) ) {
			$plugin_url_parts  = explode( '/', $plugin_url );
			$desired_file_name = str_replace( '.zip', '', end( $plugin_url_parts ) );
			$new_file_name     = preg_replace( '#(' . $desired_file_name . '.*).tmp#', $desired_file_name . '.tmp', $download );
			rename( $download, $new_file_name ); // phpcs:ignore
			$download = $new_file_name;
		}

		$working_dir = $upgrader->unpack_package( $download );
		if ( is_wp_error( $working_dir ) ) {
			throw new \Exception( $working_dir->get_error_message() );
		}

		$result = $upgrader->install_package(
			[
				'source'        => $working_dir,
				'destination'   => WP_PLUGIN_DIR,
				'clear_working' => true,
				'hook_extra'    => [
					'type'   => 'plugin',
					'action' => 'install',
				],
			]
		);
		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		wp_clean_plugins_cache();
	}

	/**
	 * Reduces get_plugins() info to form 'folder => file'.
	 *
	 * @param array  $plugins Associative array of plugin files to paths.
	 * @param string $key     Plugin relative path. Example: newspack/newspack.php.
	 *
	 * @return array
	 */
	private static function reduce_plugin_info( $plugins, $key ) {
		$path   = explode( '/', $key );
		$folder = current( $path );

		$plugins[ $folder ] = $key;
		return $plugins;
	}
}
