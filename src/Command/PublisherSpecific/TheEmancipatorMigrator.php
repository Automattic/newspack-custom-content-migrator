<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
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
	 * CoAuthorPlus logic.
	 *
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
	}

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

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		$posts_count = 0;
		$posts_total = array_sum( (array) wp_count_posts( 'post' ) );
		$num_posts   = $assoc_args['num_posts'] ?? - 1;
		$dry_run     = ! empty( $assoc_args['dry_run'] );

		$posts_query_args = [
			'posts_per_page' => $num_posts,
			'post_type'      => 'post',
			'post_status'    => 'publish', // TODO. There are a lot of other states. Not sure if we migrate all.
			'paged'          => 1,
			'fields'         => 'ids,post_author',
		];
		if ( ! empty( $assoc_args['post_id'] ) ) {
			$posts_query_args['p'] = $assoc_args['post_id'];
		}

		WP_CLI::line( sprintf( 'dry run mode: %s', $dry_run ? 'on' : 'off' ) );

		$posts = get_posts( $posts_query_args );

		foreach ( $posts as $post ) {

			$author_id   = get_post_field( 'post_author', $post->ID );
			$author_name = get_the_author_meta( 'display_name', $author_id );
			$credits     = [];

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] ); // TODO: Are there ever more than one entries in that array??

			if ( ! empty( $api_content['credits']['by'] ) ) {
				foreach ( $api_content['credits']['by'] as $credit ) {
					$names = [];
					if ( ! empty( $credit['name'] ) ) {
						$names = $this->maybe_spit_author_names( $credit['name'] );
					} else {
						$names = $this->maybe_spit_author_names( $credit );
					}

					$credits = array_merge( $credits, $names );
				}

				if ( ! $dry_run ) {
					foreach ( $credits as $co_author ) {
						$maybe_co_author = $this->coauthorsplus_logic->get_guest_author_by_display_name( $co_author );
						if ( empty( $maybe_co_author ) ) {
							$co_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $co_author ] );
						} elseif ( is_object( $maybe_co_author ) ) {
							$co_author_id = $maybe_co_author->ID;
						} else {
							continue;
							// TODO: Figure out what to do with an array here.
						}

						$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $co_author_id ], $post->ID );

						// Link the co-author created with the WP User with the same name if it exists.
						$co_author_wp_user = $this->get_wp_user_by_name( $co_author );
						if ( $co_author_wp_user ) {
							$this->coauthorsplus_logic->link_guest_author_to_wp_user( $co_author_id,
								$co_author_wp_user );
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

	/**
	 * Retrieve WP user by name.
	 *
	 * @param string $user_name Name to look for.
	 *
	 * @return (WP_User|false) WP_User object on success, false on failure.
	 */
	private
	function get_wp_user_by_name(
		$user_name
	) {
		$user_query = new \WP_User_Query(
			[
				'search'        => $user_name,
				'search_fields' => array( 'user_login', 'user_nicename', 'display_name' ),
			]
		);

		// If we do have an existing WP User, we link the post to them.
		if ( ! empty( $user_query->results ) ) {
			return current( $user_query->results );
		}

		return false;
	}

	/**
	 * Low-tech byline splitting.
	 *
	 * @param string $name
	 *
	 * @return array
	 */
	private
	function maybe_spit_author_names(
		$name
	): array {
		if ( strpos( ' and ', $name ) ) {
			return explode( ' and ', $name );
		}

		return [ $name ];
	}

}
