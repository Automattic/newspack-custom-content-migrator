<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for New Naratif.
 */
class NewNaratifMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator newnaratif-contributors',
			[ $this, 'cmd_newnaratif_contributors' ],
			[
				'shortdesc' => 'Adds contributor info to the start of each article.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'CSV post/page IDs to process.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually make any changes.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator newnaratif-research-styling',
			[ $this, 'cmd_newnaratif_research_styling' ],
			[
				'shortdesc' => 'Adds styling to the references/bibliography section at the end of research articles.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'CSV post/page IDs to process.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually make any changes.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator newnaratif-video-podcast-widths',
			[ $this, 'cmd_newnaratif_video_podcast_widths' ],
			[
				'shortdesc' => 'Fixes the width of migrated video and podcast embeds.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'CSV post/page IDs to process.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually make any changes.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator newnaratif-import-ip-addresses',
			[ $this, 'cmd_newnaratif_import_ip_addresses' ],
			[
				'shortdesc' => 'Imports the list of orgnisations and IP addresses to get content access.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator newnaratif-add-missing-coauthors',
			[ $this, 'cmd_newnaratif_add_missing_coauthors' ],
			[
				'shortdesc' => 'Fixes the width of migrated video and podcast embeds.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'CSV post/page IDs to process.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually make any changes.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

	}

	/**
	 * Callable for `newspack-content-migrator newnaratif-members`.
	 */
	public function cmd_newnaratif_contributors( $args, $assoc_args ) {
		$dry_run  = isset( $assoc_args[ 'dry-run' ] ) ? true : false;
		$post_ids = isset( $assoc_args[ 'post-ids' ] ) ? $assoc_args[ 'post-ids' ] : null;

		//  Set up the query args.
		$get_posts_args = [
			'posts_per_page' => -1,
			'post_type'      => 'post',
			'post_status'    => 'publish',
			// Exclude all posts in the "Announcements" category.
			'tax_query'      => [
				[
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => 'announcements',
					'operator' => 'NOT IN',
				],
			],
			// Don't process posts more than once.
			'meta_query' => [
				[
					'key' => '_newspack_contributors_migrated',
					'value' => '?',
					'compare' => 'NOT EXISTS'
				]
			],
		];
		if ( $post_ids ) {
			$get_posts_args['include'] = explode( ',', $post_ids );
		}

		// Grab all the posts.
		$posts = get_posts( $get_posts_args );
		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts to process.' ); // Exits.
		}

		WP_CLI::line( sprintf( 'Processing %d posts.', count( $posts ) ) );
		$updates = 0; // Let's keep count.

		// Set the template for the group block that shows the contributors.
		$section_template = '<!-- wp:group {"className":"entry-byline"} --><div class="wp-block-group entry-byline"><div class="wp-block-group__inner-container"><!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph --></div></div><!-- /wp:group -->';

		// This is the template for individual contributors, with the label preceding the name.
		$contributor_template = '%s: <strong>%s</strong> ';

		foreach ( $posts as $post ) {

			WP_CLI::line( sprintf( 'Checking post %d', $post->ID ) );

			// Get each contributor (there are no more than 4).
			$contributors = [];
			for ( $i = 0; $i <= 4; $i++ ) {
				// Get the user ID and the label for each contributor.
				$contributor_user = get_post_meta( $post->ID, "additional_authors_{$i}_user", true );
				$contributor_label = get_post_meta( $post->ID, "additional_authors_{$i}_label", true );

				if ( ! empty( $contributor_user ) ) {
					$contributors[ $i ]['user'] = $contributor_user;
				}

				if ( ! empty( $contributor_label ) && isset( $contributors[ $i ] ) ) {
					$contributors[ $i ]['label'] = $contributor_label;
				}
			}

			// Skip posts with no contributors.
			if ( empty( $contributors ) ) {
				WP_CLI::warning( sprintf( '-- No contributors found for post %d. Skipping.', $post->ID ) );
				continue;
			}

			WP_CLI::line( sprintf( '-- Assembling content for %d contributors...', count( $contributors ) ) );

			// Run through the contributors to assemble the content.
			$contributor_content = '';
			foreach ( $contributors as $contributor ) {

				// We need the user's name.
				$user = get_user_by( 'id', $contributor['user'] );
				if ( ! is_a( $user, 'WP_User' ) ) {
					WP_CLI::warning( sprintf( '-- Could not find user %d. Skipping this post.', $contributor['user'] ) );
					continue 2;
				}

				$contributor_content .= sprintf(
					$contributor_template,
					$contributor['label'],
					$user->display_name
				);
			}

			// Assemble the whole thing.
			$new_content = sprintf( $section_template, $contributor_content );

			WP_CLI::line( '-- Updating content...' );

			// Update the post with the new content.
			$update = ( $dry_run ) ? true : wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $new_content . $post->post_content,
			] );
			if ( is_wp_error( $update ) ) {
				WP_CLI::warning( sprintf( '-- Failed to update post %d because %s.', $post->ID, $update->get_error_message() ) );
			} else {
				WP_CLI::line( '-- Successfully updated!' );
			}

			// Leave an audit trail behind.
			if ( ! $dry_run ) {
				add_post_meta( $post->ID, '_newspack_contributors_migrated', 1 );
				$updates++;
			}

		}

		WP_CLI::success( sprintf( 'Processed %d posts and made %d updates.', count( $posts ), $updates ) );

	}

	public function cmd_newnaratif_research_styling( $args, $assoc_args ) {
		$dry_run  = isset( $assoc_args[ 'dry-run' ] ) ? true : false;
		$post_ids = isset( $assoc_args[ 'post-ids' ] ) ? $assoc_args[ 'post-ids' ] : null;

		// Set up the query args.
		$get_posts_args = [
			'posts_per_page' => -1,
			'post_type'      => 'post',
			'post_status'    => 'publish',
			// Only include posts in the "Research" category.
			'tax_query'      => [
				[
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => 'research',
				],
			],
			// Don't process posts more than once.
			'meta_query' => [
				[
					'key' => '_newspack_research_styled',
					'value' => '?',
					'compare' => 'NOT EXISTS'
				]
			],
		];
		if ( $post_ids ) {
			$get_posts_args['include'] = explode( ',', $post_ids );
		}

		// Grab all the posts.
		$posts = get_posts( $get_posts_args );
		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts to process.' ); // Exits.
		}

		WP_CLI::line( sprintf( 'Processing %d posts.', count( $posts ) ) );
		$updates = 0; // Let's keep count.

		// Set the new content to insert.
		$group_start = '<!-- wp:group {"className":"ref-biblio"} --><div class="wp-block-group ref-biblio"><div class="wp-block-group__inner-container">';
		$group_end   = '</div></div><!-- /wp:group -->';

		foreach ( $posts as $post ) {

			WP_CLI::line( sprintf( 'Checking post %d', $post->ID ) );

			// Look for the Bibliography or References headings.
			$bibliography = strpos( $post->post_content, "<!-- wp:heading -->\n<h2>Bibliography</h2>" );
			$references   = strpos( $post->post_content, "<!-- wp:heading -->\n<h2>References</h2>" );

			if ( false === $bibliography && false === $references ) {
				WP_CLI::warning( '-- No bibliography or references found. Skipping.' );
				continue;
			}

			// Find the position we should insert the group block based on which heading appears first.
			$start = min( $bibliography, $references );
			if ( ! $start ) {
				$start = ( ! $bibliography ) ? $references : $bibliography;
			}

			// Add the start of the group block to the beginning of the ref/bib section.
			$new_content = substr_replace( $post->post_content, $group_start, $start, 0 );
			$new_content .= $group_end;

			WP_CLI::line( '-- Updating content...' );

			// Update the post with the new content.
			$update = ( $dry_run ) ? true : wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $new_content,
			] );
			if ( is_wp_error( $update ) ) {
				WP_CLI::warning( sprintf( '-- Failed to update post %d because %s.', $post->ID, $update->get_error_message() ) );
			} else {
				WP_CLI::line( '-- Successfully updated!' );
			}

			// Leave an audit trail behind.
			if ( ! $dry_run ) {
				add_post_meta( $post->ID, '_newspack_research_styled', 1 );
				$updates++;
			}

		}

		WP_CLI::success( sprintf( 'Processed %d posts and made %d updates.', count( $posts ), $updates ) );

	}

	public function cmd_newnaratif_video_podcast_widths( $args, $assoc_args ) {
		$dry_run  = isset( $assoc_args[ 'dry-run' ] ) ? true : false;
		$post_ids = isset( $assoc_args[ 'post-ids' ] ) ? $assoc_args[ 'post-ids' ] : null;

		// Set up the query args.
		$get_posts_args = [
			'posts_per_page' => -1,
			'post_type'      => 'post',
			'post_status'    => 'publish',
			// Don't process posts more than once.
			'meta_query' => [
				[
					'key' => '_newspack_podcasts_width_fixed',
					'value' => '?',
					'compare' => 'NOT EXISTS'
				]
			],
		];
		if ( $post_ids ) {
			$get_posts_args['include'] = explode( ',', $post_ids );
		}

		// Grab all the posts.
		$posts = get_posts( $get_posts_args );
		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts to process.' ); // Exits.
		}

		WP_CLI::line( sprintf( 'Processing %d posts.', count( $posts ) ) );
		$updates = 0; // Let's keep count.

		foreach ( $posts as $post ) {

			WP_CLI::line( sprintf( 'Checking post %d', $post->ID ) );

			$replacements_youtube = $this->get_youtube_replacements( $post->post_content );
			$replacements_podcast = $this->get_podcast_replacements( $post->post_content );

			$searches     = \array_merge( $replacements_youtube[0], $replacements_podcast[0] );
			$replacements = \array_merge( $replacements_youtube[1], $replacements_podcast[1] );

			WP_CLI::line( sprintf( '-- Performing %d replacements', count( $replacements ) ) );
			if ( empty( $searches ) ) {
				if ( ! $dry_run ) add_post_meta( $post->ID, '_newspack_podcasts_width_fixed', 1 );
				continue; // Don't attempt no replacements.
			}

			$new_content = str_replace( $searches, $replacements, $post->post_content );

			if ( ( $new_content === $post->post_content ) || empty( $new_content ) ) {
				WP_CLI::warning( 'New content is identical or empty. Skipping.' );
				continue;
			}

			$updated = ( $dry_run ) ? true : wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $new_content,
			] );
			if ( is_wp_error( $updated ) ) {
				WP_CLI::warning( sprintf(
					'Failed to update post %d because "%s".',
					$post->ID,
					$updated->get_error_message()
				) );
				continue;
			}

			if ( ! $dry_run ) add_post_meta( $post->ID, '_newspack_podcasts_width_fixed', 1 );
			WP_CLI::success( 'Updated!' );

		}

		wp_cache_flush();

	}

	public function cmd_newnaratif_import_ip_addresses( $args, $assoc_args ) {
		global $wpdb;

		// Probably unnecessary but what the hey.
		$dry_run  = isset( $assoc_args[ 'dry-run' ] ) ? true : false;

		// Collect all the data.
		$ips_and_orgs = [];

		// Get all the options from the old DB then deal with them.
		$results = $wpdb->get_results( "SELECT * FROM `live_wp_options` WHERE `option_name` LIKE '%allowed_ip_addresses_%'" );
		foreach ( $results as $result ) {

			// Keys starting with an underscore are just ACF's field references, so ignore them.
			if ( 0 === strpos( $result->option_name, '_' ) ) {
				continue;
			}

			// This will put the integer in index 4 and label/ip in 5, like so:
			// array (
			// 		0 => 'options',
			// 		1 => 'allowed',
			// 		2 => 'ip',
			// 		3 => 'addresses',
			// 		4 => '0',
			// 		5 => 'label||ip',
			// 		{6 => 'address',}
			// )
			$key_parts = explode( '_', $result->option_name );
			if ( ! is_numeric( $key_parts[4] ) ) { // ...or not.
				continue;
			}

			// Set up the data store for this IP if it doesn't already exist.
			if ( ! \array_key_exists( $key_parts[4], $ips_and_orgs ) ) {
				$ips_and_orgs[ $key_parts[4] ] = [
					'label'        => '',
					'ip_addresses' => '',
				];
			}

			// Add the data we're dealing with.
			switch ( count( $key_parts ) ) {
				case 6: // Label.
					$ips_and_orgs[ $key_parts[4] ]['label'] = $result->option_value;
					break;
				case 7: // IP address.
					$ips_and_orgs[ $key_parts[4] ]['ip_addresses'] = $result->option_value;
			}

		}

		// Now we have a complete set of data, add it to the DB.
		add_option( 'nn_all_allowed_ips', $ips_and_orgs );

	}

	/**
	 * cmd_newnaratif_add_missing_coauthors
	 *
	 * Make sure that the additional authors stored by ACF in the old site are all
	 * added as co-authors in the new site.
	 *
	 * @param array $args       Arguments.
	 * @param array $assoc_args Associated argument.
	 */
	public function cmd_newnaratif_add_missing_coauthors( $args, $assoc_args ) {
		$post_ids = isset( $assoc_args[ 'post-ids' ] ) ? $assoc_args[ 'post-ids' ] : null;

		// Set up the query args.
		$get_posts_args = [
			'posts_per_page' => -1,
			'post_type'      => 'post',
			'post_status'    => 'publish',
			// Don't process posts more than once.
			'meta_query' => [
				[
					'key' => '_newspack_contributors_migrated',
					'compare' => 'EXISTS'
				]
			],
		];
		if ( $post_ids ) {
			$get_posts_args['include'] = explode( ',', $post_ids );
			unset( $get_posts_args['meta_query'] );
		}

		// Grab all the posts.
		$posts = get_posts( $get_posts_args );
		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts to process.' ); // Exits.
		}

		$coauthorsplus_logic = new CoAuthorPlusLogic();

		WP_CLI::line( sprintf( 'Processing %d posts.', count( $posts ) ) );
		$updates = 0; // Let's keep count.

		foreach ( $posts as $post ) {

			WP_CLI::line( sprintf( 'Checking post %d', $post->ID ) );

			// Get the co-authors for this post.
			$coauthors = $coauthorsplus_logic->get_guest_authors_for_post( $post->ID );
			if ( ! empty( $coauthors ) ) {
				// Grab just the IDs.
				$coauthors = \wp_list_pluck( $coauthors, 'ID' );
			}

			// Get each contributor (there are no more than 4).
			$contributors = [];
			for ( $i = 0; $i <= 4; $i++ ) {
				// Get the user ID for each contributor.
				$contributor_user = get_post_meta( $post->ID, "additional_authors_{$i}_user", true );
				if ( ! empty( $contributor_user ) ) {
					$contributors[] = $contributor_user;
				}

			}

			// Skip posts with no contributors.
			if ( empty( $contributors ) ) {
				WP_CLI::warning( sprintf( '-- No contributors found for post %d. Skipping.', $post->ID ) );
				continue;
			}

			// Make sure there is a co-author for each contributor user, and get their IDs.
			$coauthor_ids = [];
			foreach ( $contributors as $contributor ) {
				WP_CLI::line( sprintf( 'Creating new coauthor for user %d', $contributor ) );
				$coauthor_ids[] = $coauthorsplus_logic->create_guest_author_from_wp_user( $contributor );
			}

			// Now make sure each contributor is also a co-author on the post.
			$coauthorsplus_logic->assign_guest_authors_to_post( $coauthor_ids, $post->ID );
			WP_CLI::success( \sprintf(
				'Added %d contributors to post %d with ids %s',
				count( $contributors ),
				$post->ID,
				implode( ',', $contributors )
			) );

		}

		wp_cache_flush();

	}

	private function get_youtube_replacements( $post_content ) {
		$find   = '/<!-- wp:html -->
<figure><iframe src="(.*)" width="\d+" height="\d+" frameborder="0" allowfullscreen="allowfullscreen"><\/iframe><\/figure>
<!-- \/wp:html -->/';
		$search = \preg_match_all( $find, $post_content, $matches, PREG_PATTERN_ORDER );

		WP_CLI::line( sprintf( '-- Found %d YouTube videos', count( $matches[1] ) ) );
		if ( empty( count( $matches[1] ) ) ) {
			return [ [], [] ]; // Return an empty array so array_merge does nothing.
		}

		for ( $i = 0; $i < count( $matches[1] ); $i++ ) {
			$yt_url = $matches[1][ $i ];
			$matches[1][ $i ] = sprintf(
				'<!-- wp:embed {"url":"%1$s","type":"rich","providerNameSlug":"embed-handler","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-rich is-provider-embed-handler wp-block-embed-embed-handler wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
%1$s
</div></figure>
<!-- /wp:embed -->',
				$yt_url
			);
		}

		return $matches;
	}

	private function get_podcast_replacements( $post_content ) {
		$find   = '/<!-- wp:html -->
<figure><iframe src="(.*)" width="(\d+px)" height="\d+px" frameborder="0" scrolling="no"><\/iframe><\/figure>
<!-- \/wp:html -->/';
		$search = \preg_match_all( $find, $post_content, $matches, PREG_PATTERN_ORDER );

		WP_CLI::line( sprintf( '-- Found %d podcasts', count( $matches[1] ) ) );
		if ( empty( count( $matches[1] ) ) ) {
			return [ [], [] ]; // Return an empty array so array_merge does nothing.
		}

		$replacements = [];

		for ( $i = 0; $i < count( $matches[0] ); $i++ ) {

			// Build our replacement string.
			$replacement = $matches[0][ $i ];

			// Replace the pixel width with 100%.
			$replacement = str_replace( $matches[2][ $i ], '100%', $replacement );

			// Remove the figure element.
			$replacement = str_replace( '<figure>', '', $replacement );
			$replacement = str_replace( '</figure>', '', $replacement );

			$replacements[0][ $i ] = $matches[0][ $i ]; // Original.
			$replacements[1][ $i ] = $replacement;      // Replacement.
		}

		return $replacements;

	}

}
