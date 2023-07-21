<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

/**
 * Custom migration scripts for The Emancipator.
 */
class TheEmancipatorMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-authors',
			[ $this, 'cmd_authors' ],
			[
				'shortdesc' => 'This is an initial version of the command, it will probably migrate authors from end of post_content into actual authors.',
				'synopsis'  => [],
			]
		);


	}

	/**
	 * Process byline data.
	 */
	public function cmd_authors( $args, $assoc_args ) {

		$posts_count      = 0;
		$posts_total      = array_sum( (array) wp_count_posts( 'post' ) );
		$num_posts = $assoc_args['num_posts'] ?? - 1;

		$posts_query_args = [
			'posts_per_page' => $num_posts,
			'post_type'      => 'post',
			'post_status'    => 'publish', // TODO. There are a lot of other states. Not sure if we migrate all.
			'paged'          => 1,
			'fields'         => 'ids,post_author',
		];
		if ( $assoc_args['post_id'] ) {
			$posts_query_args['p'] = $assoc_args['post_id'];
		}

		WP_CLI::line( sprintf( 'dry run mode: %s', ! empty( $assoc_args['dry_run'] ) ? 'on' : 'off' ) );

		$posts = get_posts( $posts_query_args );

		foreach ( $posts as $post ) {

			$author_id   = get_post_field( 'post_author', $post->ID );
			$author_name = get_the_author_meta( 'display_name', $author_id );
			$credits     = [];

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] ); // TODO: Are there ever more than one entries in that array??

			if ( ! empty( $api_content['credits']['by'] ) ) {
				foreach ( $api_content['credits']['by'] as $credit ) {
					if ( ! empty( $credit['name'] ) ) {
						if ( is_array( $credit ) ) {
							$credits[] = $credit['name'];
						}
						else {
							$credits[] = $credit;
						}
					}
				}
			}

			WP_CLI::line( sprintf( 'Start processing post with ID: %s (Post: %d of %d)', $post->ID, ++ $posts_count,
				$posts_total ) );

			// TODO. Probably shouldn't go in this function, but I found that some posts
			// are straight up redirects to other sites. We should maybe handle them not as posts?
			if ( ! empty( $api_content['related_content']['redirect'] ) ) {
				WP_CLI::line( sprintf( 'Has a redirect -> %s',
					$api_content['related_content']['redirect'][0]['redirect_url'] ) );
			}

			WP_CLI::line( sprintf( 'WP author: %s', $author_name ) );
			WP_CLI::line( sprintf( 'API content credit(s): %s', implode( ', ', $credits ) ) );
			WP_CLI::line( sprintf( 'Existing url: %s', 'https://bostonglobe.com' . $api_content['canonical_url'] ) );
			WP_CLI::line( sprintf( 'Local url: %s', get_permalink( $post->ID ) ) );
			WP_CLI::line( '--------' );
		}

	}

}
