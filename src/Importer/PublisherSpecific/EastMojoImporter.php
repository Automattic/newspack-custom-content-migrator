<?php

namespace NewspackCustomContentMigrator\Importer\PublisherSpecific;

$plugin_installer = \NewspackCustomContentMigrator\PluginInstaller::get_instance();
$is_installed     = $plugin_installer->is_installed( 'newspack-rss-importer' );
if ( ! $is_installed ) {
	wp_die( 'Please install the Newspack RSS Importer plugin.' );
}

$is_active = $plugin_installer->is_active( 'newspack-rss-importer' );
if ( ! $is_active ) {
	try {
		$plugin_installer->activate( 'newspack-rss-importer' );
	} catch ( \Exception $e ) {
		wp_die( 'Plugin activation error: ' . $e->getMessage() );
	}
}

function em_featured_image_data( $data, $post ) {

	$has_featured_image = preg_match( '|<media:content(.*?)</media:content>|is', $post );
	if ( 0 < $has_featured_image ) {
		if ( preg_match( '|<media:content .*url="(.*?)".*</media:content>|is', $post, $featured_image_url ) ) {
			$data['featured_image_url'] = $featured_image_url[1];
		}

		if ( preg_match( '|<media:title>(.*?)</media:title>|is', $post, $featured_image_caption ) ) {
			$featured_image_caption = str_replace( [ '<![CDATA[', ']]>' ], '', $wpdb->escape( trim( $featured_image_caption[1] ) ) );
			$data['featured_image_caption'] = $featured_image_caption;
		}

	}

	return $data;

}
add_filter( 'newspack_rss_import_data', 'em_featured_image_data', 10, 2 );

function em_featured_image_import( $post_id, $post ) {

	

}
add_action( 'newspack_rss_import_after_post_save', 'em_featured_image_import', 10, 2 );

function em_author_data( $data, $post ) {

	// Is there even an author.
	if ( preg_match( '|<atom:author>(.*?)</atom:author>|is', $post ) ) {

		preg_match( '|<atom:uri>(.*?)</atom:uri>|is', $post, $author_id );
		$author_id = str_replace( [ '/api/author/', '', $wpdb->escape( trim( $author_id[1] ) ) ] );

		// Check if the author has already been imported.
		$users = get_users( [ 'meta_key' => '_imported_from_id', 'meta_value' => $author_id ] );
		if ( ! empty( $users ) ) {
			// Use the already imported user ID and move to the next post.
			$data['author'] = $users[0]->ID;
			return $data;
		}

		// Get the author name.
		preg_match( '|<atom:name>(.*?)</atom:name>|is', $post, $author_name );

		// Create a new user.
		$user_id = wp_create_user(
			sanitize_title( $author_name ),
			wp_generate_password( 24 )
		);
		if ( ! $user_id ) {
			// Failed for some reason but just proceed with import.
			return $data;
		} else {
			$data['author'] = $user_id;
		}

	}

	return $data;

}
add_filter( 'newspack_rss_import_data', 'em_author_data', 10, 2 );
