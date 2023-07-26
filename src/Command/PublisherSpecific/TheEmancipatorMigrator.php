<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use WP_CLI;

/**
 * Custom migration scripts for The Emancipator.
 */
class TheEmancipatorMigrator implements InterfaceCommand {

	const CATEGORY_ID_OPINION = 8;
	const CATEGORY_ID_THE_EMANCIPATOR = 9;

	/**
	 * @var null | TheEmancipatorMigrator
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;


	/**
	 * @var RedirectionLogic $redirection_logic
	 */
	private $redirection_logic;

	/**
	 * @var Posts $posts_logic
	 */
	private Posts $posts_logic;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->redirection_logic   = new RedirectionLogic();
		$this->posts_logic         = new Posts();
	}

	/**
	 * Singleton.
	 *
	 * @return TheEmancipatorMigrator
	 */
	public static function get_instance(): TheEmancipatorMigrator {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 *
	 * @throws \Exception
	 */
	public function register_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-authors',
			[ $this, 'cmd_authors' ],
			[
				'shortdesc' => 'Migrates authors from the API content as Co-Authors.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator emancipator-redirects',
			[ $this, 'cmd_redirects' ],
			[
				'shortdesc' => 'Create redirects for articles that are just redirects.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-post-subtitles',
			[ $this, 'cmd_post_subtitles' ],
			[
				'shortdesc' => 'Add post subtitles',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator emancipator-taxonomy',
			[ $this, 'cmd_taxonomy' ],
			[
				'shortdesc' => 'Remove unneeded categories.',
				'synopsis'  => [],
			]
		);
	}

	public function cmd_taxonomy( $args, $assoc_args ): void {

		WP_CLI::log( 'Removing the superfluous "Opinion" and "The Emancipator".' );

		$dry_run = $assoc_args['dry-run'] ?? false;

		// Remove the categories "opinion" and "the emancipator" from all posts.
		foreach ( $this->posts_logic->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			if ( ! $dry_run ) {
				wp_remove_object_terms( $post->ID, self::CATEGORY_ID_OPINION, 'category' );
				wp_remove_object_terms( $post->ID, self::CATEGORY_ID_THE_EMANCIPATOR, 'category' );
			}
		}

		// Now unnest the categories under opinion -> the emancipator.
		$children = get_categories(
			[
				'parent' => self::CATEGORY_ID_THE_EMANCIPATOR,
			]
		);
		foreach ( $children as $child ) {
			if ( ! $dry_run ) {
				wp_update_term(
					$child->term_id,
					'category',
					[
						'parent' => 0,
					]
				);
			}
		}
		// And finally delete the two categories.
		if ( ! $dry_run ) {
			wp_delete_term( self::CATEGORY_ID_OPINION, 'category' );
			wp_delete_term( self::CATEGORY_ID_THE_EMANCIPATOR, 'category' );
		}
	}

	/**
	 * Process post subtitles.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_post_subtitles( $args, $assoc_args ): void {

		WP_CLI::log( 'Processing post subtitles' );
		$counter = 0;
		$dry_run = $assoc_args['dry-run'] ?? false;
		$posts   = $this->posts_logic->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		foreach ( $posts as $post ) {
			WP_CLI::log(
				sprintf(
					'Post ID: %s, %s',
					$post->ID,
					$post->guid
				)
			);
			$counter ++;

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );
			$subtitle    = $api_content['subheadlines']['basic'] ?? false;
			if ( ! $dry_run && $subtitle ) {
				update_post_meta( $post->ID, 'newspack_post_subtitle', $subtitle );
			}
		}

		WP_CLI::success( sprintf( 'Finished processing %s post subtitles', $counter ) );
	}

	public function cmd_redirects( $args, $assoc_args ): void {

		if ( ! $this->redirection_logic->plugin_is_activated() ) {
			WP_CLI::error( 'Redirection plugin not found. Install, activate, and configure it before using this command.' );
		}

		WP_CLI::log( 'Processing redirects' );
		$counter = 0;
		$dry_run = $assoc_args['dry-run'] ?? false;
		$posts   = $this->posts_logic->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		foreach ( $posts as $post ) {
			WP_CLI::log(
				sprintf(
					'Post ID: %s, %s',
					$post->ID,
					$post->guid
				)
			);
			$counter ++;

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			$redirect_to = $api_content['related_content']['redirect'][0]['redirect_url'] ?? false;
			if ( $redirect_to ) {
				$redirect_from = get_permalink( $post->ID );
				if ( ! $dry_run && ! $this->redirection_logic->has_redirect_from( $redirect_from ) ) {
					$this->redirection_logic->create_redirection_rule(
						$post->ID . '-redirect',
						$redirect_from,
						$redirect_to
					);
					WP_CLI::log( sprintf( 'Added a redirect for post with ID %d to %s', $post->ID, $redirect_to ) );
				}
			}
		}
		WP_CLI::success( sprintf( 'Finished processing %s posts for redirects', $counter ) );
	}

	/**
	 * Process byline data.
	 */
	public function cmd_authors( $args, $assoc_args ): void {

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		WP_CLI::log( 'Processing bylines' );
		$counter = 0;
		$dry_run = $assoc_args['dry-run'] ?? false;
		$posts   = $this->posts_logic->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		foreach ( $posts as $post ) {
			WP_CLI::log(
				sprintf(
					'Post ID: %s, %s',
					$post->ID,
					$post->guid
				)
			);
			$counter ++;

			$credits     = [];
			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			// TODO. THe real author/owner? Should we use that instead?
			$real_author = $api_content['revision']['user_id'] ?? false;
			WP_CLI::log( sprintf( 'Real author? %s', $real_author ) );

			if ( ! empty( $api_content['credits']['by'] ) ) {
				foreach ( $api_content['credits']['by'] as $credit ) {
					$credits[] = empty( $credit['name'] ) ? $credit : $credit['name'];
				}

				if ( $dry_run ) {
					continue;
				}

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

		WP_CLI::success( sprintf( 'Finished processing %s posts for bylines', $counter ) );
	}

	/**
	 * Retrieve WP user by looking in login, nicename, and display name.
	 *
	 * @param string $user_name Name to look for.
	 *
	 * @return (\WP_User|false) WP_User object on success, false on failure.
	 */
	private function get_wp_user_by_name( $user_name ) {
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

}
