<?php

namespace NewspackCustomContentMigrator\Importer\PublisherSpecific;

// @TODO: This needs to only run when needed, otherwise it stops this plugin working.
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

		if ( preg_match( '|<media:title.*><!\[CDATA\[(.*?)\]\]><\/media:title>|is', $post, $featured_image_caption ) ) {
			$featured_image_caption = str_replace( [ '<![CDATA[', ']]>' ], '', esc_sql( trim( $featured_image_caption[1] ) ) );
			$data['featured_image_caption'] = $featured_image_caption;
		}

	}

	return $data;

}
add_filter( 'newspack_rss_import_data', __NAMESPACE__ . '\em_featured_image_data', 10, 2 );

function em_featured_image_import( $post_id, $post ) {

	if ( ! isset( $post['featured_image_url'] ) ) {
		return;
	}

	// Grab the image URL.
	$data = [
		'featured_image_url' => $post['featured_image_url'],
	];

	// Add the caption, if there is one.
	if ( isset( $post['featured_image_caption'] ) ) {
		$data['featured_image_caption'] = $post['featured_image_caption'];
	}

	add_post_meta( $post_id, 'em_featured_image_data', $data );

}
add_action( 'newspack_rss_import_after_post_save', __NAMESPACE__ . '\em_featured_image_import', 10, 2 );

function em_author_data( $data, $post ) {

	// Is there even an author.
	if ( preg_match( '|<atom:author>(.*?)</atom:author>|is', $post ) ) {

		// Get the author ID.
		preg_match( '|<atom:uri>(.*?)</atom:uri>|is', $post, $author_id );
		$data['author_id'] = intval( str_replace( '/api/author/', '', esc_sql( trim( $author_id[1] ) ) ) );

		// Get the author name.
		preg_match( '|<atom:name>(.*?)</atom:name>|is', $post, $author_name );
		$data['author_login']        = sanitize_title( $author_name[1] );
		$data['author_display_name'] = esc_sql( $author_name[1] );

	}

	return $data;

}
add_filter( 'newspack_rss_import_data', __NAMESPACE__ . '\em_author_data', 10, 2 );

function em_author_import( $post_id, $post ) {

	if ( ! isset( $post['author_id'] ) ) {
		return;
	}

	add_post_meta(
		$post_id,
		'em_author_data',
		[
			'author_id'           => $post['author_id'],
			'author_login'        => $post['author_login'],
			'author_display_name' => $post['author_display_name'],
		]
	);

}
add_action( 'newspack_rss_import_after_post_save', __NAMESPACE__ . '\em_author_import', 10, 2 );
