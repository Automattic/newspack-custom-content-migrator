<?php

WP_CLI::add_command( 'newspack-live-migrate asiatimes-writers', 'cmd_asiatimes_writers', [
	'shortdesc' => 'Migrates the Asia Times "Writers" taxonomy to CAP guest authors',
	'synopsis'  => [
		[
			'type'        => 'assoc',
			'name'        => 'writer',
			'description' => 'The ID, or slug, of a Writer term to migrate.',
			'optional'    => true,
		],
		[
			'type'        => 'assoc',
			'name'        => 'post',
			'description' => 'The ID of a post to migrate from Writers to CAP Guest Authors.',
			'optional'    => true,
		],
	],
] );

function cmd_asiatimes_writers( $args, $assoc_args ) {

	// Get terms from Writers taxonomy.
	$writers = get_terms( [ 'taxonomy' => 'writers', 'hide_empty' => false, ] );
	if ( is_wp_error( $writers ) ) {
		WP_CLI::error( sprintf( 'Failed to get terms: %s', $writers->get_error_message() ) );
	}

	// Loop through terms from Writers taxonomy.
	foreach ( $writers as $writer ) {

		// Check if this writer already has a CAP Guest Author.
		$coauthor = get_coauthor_by( 'slug', $writer->slug );

		// Create a new guest author if we need to.
		if ( false === $coauthor ) {
			
		}

	}

		// Create a CAP guest author for each one that doesn't exist

		// Loop through all posts with this term

			// Assign to new CAP guest author.

}

WP_CLI::add_command( 'newspack-live-migrate asiatimes-topics', 'cmd_asiatimes_topics', [
	'shortdesc' => 'Migrates the Asia Times "Topics" taxonomy to terms in "Tag" taxonomy',
	'synopsis'  => [],
] );

/**
 * Create tags where needed from the 'topics' taxonomy and assign posts to them.
 */
function cmd_asiatimes_topics() {
	// Temporarily register the taxonomy if it's not registered any more, otherwise term functions won't work.
	if ( ! taxonomy_exists( 'topics' ) ) {
		register_taxonomy( 'topics', 'post' );
	}

	$topics = get_terms( [
		'taxonomy' => 'topics',
		'hide_empty' => false,
	] );

	if ( is_wp_error( $topics ) ) {
		var_dump( $topics );
		WP_CLI::error( 'Error retrieving topics. Info is above.' );
	}

	foreach ( $topics as $topic ) {

		// Find or create the mapped tag.
		$tag = get_term_by( 'slug', $topic->slug, 'post_tag' );
		if ( ! $tag ) {
			$tag_info = wp_insert_term(
				$topic->name,
				'post_tag',
				[
					'slug'        => $topic->slug,
					'description' => $topic->description,
				]
			);

			if ( is_wp_error( $tag_info ) ) {
				var_dump( $tag_info );
				WP_CLI::error( 'Error creating tag from topic. Info is above.' );
			}

			$tag = get_term( $tag_info['term_id'], 'post_tag' );
		}

		// Get all posts in the topic.
		$posts = get_posts( [
			'posts_per_page' => -1,
			'tax_query' => [
				[
					'taxonomy' => 'topics',
					'field' => 'term_id',
					'terms' => $topic->term_id,
				]
			],
		] );

		// Assign posts from the topic to the tag.
		foreach ( $posts as $post ) {
			wp_set_post_terms( $post->ID, $tag->slug, 'post_tag', true );
			WP_CLI::line( sprintf( 'Updated post %d with tag %s.', $post->ID, $tag->slug ) );
		}
	}

	WP_CLI::line( 'Completed topic to tag migration.' );
	wp_cache_flush();
}

WP_CLI::add_command( 'newspack-live-migrate asiatimes-excerpts', 'cmd_asiatimes_excerpts', [
	'shortdesc' => 'Migrates the Asia Times excerpts to post subtitles',
	'synopsis'  => [],
] );

/**
 * Copy the post excerpt to the subtitle field and delete the excerpt.
 */
function cmd_asiatimes_excerpts() {
	global $wpdb;

	$data = $wpdb->get_results( "SELECT ID, post_excerpt FROM {$wpdb->prefix}posts WHERE post_type='post' AND post_excerpt != ''", ARRAY_A );

	foreach ( $data as $post_data ) {
		update_post_meta( $post_data['ID'], 'newspack_post_subtitle', $post_data['post_excerpt'] );
		wp_update_post( [
			'ID'           => $post_data['ID'],
			'post_excerpt' => '',
		] );

		WP_CLI::line( sprintf( 'Moved excerpt to subtitle for post %d.', $post_data['ID'] ) );
	}

	WP_CLI::line( 'Completed excerpt to subtitle migration.' );
	wp_cache_flush();
}
