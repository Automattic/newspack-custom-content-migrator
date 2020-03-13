<?php
/**
 * Plugin Name: Newspack Custom Content Migrator
 * Author:	  Automattic
 * Author URI:  https://automattic.com
 * Version:	 0.1.1
 *
 * @package	 Newspack_Custom_Content_Migrator
 */

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require 'vendor/autoload.php';
require_once(ABSPATH . 'wp-settings.php');

setup_wordpress_importer();

// Register migrators and their commands.
$migrator_classes = array(
	\NewspackCustomContentMigrator\CssMigrator::class,
	\NewspackCustomContentMigrator\InlineFeaturedImageMigrator::class,
	\NewspackCustomContentMigrator\PostsMigrator::class,
	\NewspackCustomContentMigrator\MenusMigrator::class,
	\NewspackCustomContentMigrator\AsiaTimesMigrator::class,
);
foreach ( $migrator_classes as $migrator_class ) {
	$migrator_class::get_instance()->register_commands();
}

/**
 * Checks whether wordpress-importer is active and valid, and if not, installs and activates it.
 */
function setup_wordpress_importer() {
	$plugin_installer = \NewspackCustomContentMigrator\PluginInstaller::get_instance();
	$plugin_slug      = 'wordpress-importer';
	$is_installed     = $plugin_installer->is_installed( $plugin_slug );
	$is_active        = $plugin_installer->is_active( $plugin_slug );

	if ( $is_installed && ! $is_active ) {
		WP_CLI::line( sprintf( 'Activating the %s plugin now...', $plugin_slug ) );
		try {
			$plugin_installer->activate( $plugin_slug );
		} catch ( \Exception $e ) {
			WP_CLI::error( 'WP Importer Plugin activation error: ' . $e->getMessage() );
		}
	} elseif ( ! $is_installed ) {
		WP_CLI::line( sprintf( 'Installing and activating the %s plugin now...', $plugin_slug ) );
		try {
			$plugin_installer->install( $plugin_slug );
			$plugin_installer->activate( $plugin_slug );
		} catch ( \Exception $e ) {
			WP_CLI::error( 'WP Importer Plugin installation error: ' . $e->getMessage() );
		}
	}
}
