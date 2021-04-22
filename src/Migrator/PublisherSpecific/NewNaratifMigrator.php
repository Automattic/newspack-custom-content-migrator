<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
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
			'tax_query'      => [
				[
					'taxonomy' => 'category',
					'field'    => 'slug',
					'terms'    => 'announcements',
					'operator' => 'NOT IN',
				],
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

		}

	}

}
