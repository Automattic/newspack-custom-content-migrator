<?php
/**
 * Plugin Name: Newspack Custom Content Migrator
 * Author:      Automattic
 * Author URI:  https://automattic.com
 * Version:     0.1.0
 *
 * @package     Newspack_Custom_Content_Migrator
 */

// Don't do anything outside WP CLI.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once( 'inc/utils.php' );
require_once( 'inc/posts-migrator.php' );
require_once( 'inc/css-migrator.php' );
require_once( 'inc/publisher-asiatimes.php' );
