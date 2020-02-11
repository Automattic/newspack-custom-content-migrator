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
