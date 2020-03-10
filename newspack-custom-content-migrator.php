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
	$installed = false;
	foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
		if ( false !== strrpos( $plugin, 'wordpress-importer.php' ) ) {
			$installed = true;
		}
	}

	if ( ! $installed ) {
		WP_CLI::runcommand( "plugin install wordpress-importer --activate" );
	}
}
