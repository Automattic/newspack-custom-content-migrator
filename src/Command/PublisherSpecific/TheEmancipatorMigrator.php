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

		$synopsis = '[--post-id=<post-id>] [--dry-run] [--num-posts=<num-posts>]';

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-taxonomy',
			[ $this, 'cmd_taxonomy' ],
			[
				'shortdesc' => 'Remove unneeded categories.',
				'synopsis'  => $synopsis,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-authors',
			[ $this, 'cmd_post_authors' ],
			[
				'shortdesc' => 'Migrates post authors/owners from the API content.',
				'synopsis'  => $synopsis,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-bylines',
			[ $this, 'cmd_post_bylines' ],
			[
				'shortdesc' => 'Migrates bylines from the API content as Co-Authors.',
				'synopsis'  => $synopsis,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-post-subtitles',
			[ $this, 'cmd_post_subtitles' ],
			[
				'shortdesc' => 'Add post subtitles',
				'synopsis'  => $synopsis,
			]
		);

		// TODO. Not sure this is needed.
//		WP_CLI::add_command(
//			'newspack-content-migrator emancipator-redirects',
//			[ $this, 'cmd_redirects' ],
//			[
//				'shortdesc' => 'Create redirects for articles that are just redirects.',
//				'synopsis'  => $synopsis,
//			]
//		);
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
	 * Find the user that owns the post in the serialized API content and assign it as the post author.
	 * If the user doesn't exist, create it and assign the author role.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return void
	 * @throws WP_CLI\ExitException
	 */
	public function cmd_post_authors( $args, $assoc_args ): void {
		WP_CLI::log( 'Processing post authors' );
		$dry_run = $assoc_args['dry-run'] ?? false;

		foreach ( $this->posts_logic->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			$real_author_email = $api_content['revision']['user_id'] ?? false;
			if ( ! $real_author_email ) {
				continue;
			}
			if ( ! $dry_run ) {
				$maybe_existing_user = get_user_by( 'email', $real_author_email );
				$author_id           = $maybe_existing_user->ID ?? false;
				if ( ! $author_id ) {
					$username  = stristr( $real_author_email, '@', true );
					$password  = wp_generate_password( 16, false );
					$author_id = wp_create_user( $username, $password, $real_author_email );
					if ( ! is_wp_error( $author_id ) ) {
						$wp_user = get_userdata( $author_id );
						$wp_user->set_role( 'author' );
					}
				}
				if ( $author_id !== $post->post_author ) {
					$post_data = array(
						'ID'          => $post->ID,
						'post_author' => $author_id,
					);
					$updated   = wp_update_post( $post_data );
					if ( is_wp_error( $updated ) ) {
						WP_CLI::error( sprintf( 'Failed to assign author to post with ID %d', $post->ID ) );
					}
				}
			}
		}
	}

	/**
	 * Add bylines (co-authors) for posts.
	 * Also delete the "credit paragraphs" at the bottom of the post content if possible.
	 */
	public function cmd_post_bylines( $args, $assoc_args ): void {

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		WP_CLI::log( 'Processing bylines' );
		$counter = 0;
		$dry_run = $assoc_args['dry-run'] ?? false;
		$posts   = $this->posts_logic->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		foreach ( $posts as $post ) {
			$counter ++;
			$credits     = [];
			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			if ( ! empty( $api_content['credits']['by'] ) ) {
				foreach ( $api_content['credits']['by'] as $credit ) {
					$credits[] = empty( $credit['name'] ) ? $credit : $credit['name'];
				}

				if ( $dry_run ) {
					continue;
				}

				$co_author_ids = [];

				$replace_in_content = false;

				foreach ( $credits as $co_author ) {
					$maybe_co_author = $this->coauthorsplus_logic->get_guest_author_by_display_name( $co_author );
					$co_author_id    = 0;
					if ( empty( $maybe_co_author ) ) {
						$author_description = $this->find_byine_credit( $co_author, $post->post_content );

						$co_author_id = $this->coauthorsplus_logic->create_guest_author( [
							'display_name' => $co_author,
							'description'  => wp_strip_all_tags( $author_description ),
						] );
						if ( ! empty( $author_description ) ) {
							$to_replace         = sprintf( '<!-- wp:paragraph -->%s<!-- /wp:paragraph -->',
								$author_description );
							$post->post_content = str_replace( $to_replace, '', $post->post_content );
							$replace_in_content = true;
						}

					} elseif ( is_object( $maybe_co_author ) ) {
						$co_author_id = $maybe_co_author->ID;
					}

					if ( ! empty( $co_author_id ) ) {
						// Link the co-author created with the WP User with the same name if it exists.
						$co_author_wp_user = get_user_by( 'login', $co_author );
						if ( $co_author_wp_user ) {
							$this->coauthorsplus_logic->link_guest_author_to_wp_user( $co_author_id,
								$co_author_wp_user );
						}
						$co_author_ids[] = $co_author_id;
					}

				}
				if ( ! empty ( $co_author_ids ) ) {
					$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_author_ids, $post->ID );
				}

				if ( $replace_in_content ) {
					$post_data = [
						'ID'           => $post->ID,
						'post_content' => $post->post_content,
					];
					wp_update_post( $post_data );
				}
			}
		}

		WP_CLI::success( sprintf( 'Finished processing %s posts for bylines', $counter ) );
	}

	/**
	 * Posts have "credit paragraphs" at the bottom of the content. They typically
	 * start with "$name is ..." and are italicized. This tries to find the string.
	 *
	 * @param string $name
	 * @param string $content
	 *
	 * @return string
	 */
	private function find_byine_credit( $name, $content ): string {
		// We're looking for a paragraph that starts with "$name is ...".
		$looking_for = sprintf( '<p><i>%s is', $name );
		// Loop backwards through the blocks array because the "credits paragraphs" are at the bottom.
		foreach ( array_reverse( parse_blocks( $content ) ) as $idx => $block ) {
			// Only deal with paragraphs. And only look in the bottom 10 blocks.
			if ( $idx > 10 || 'core/paragraph' !== $block['blockName'] ) {
				continue;
			}

			if ( str_starts_with( $block['innerHTML'], $looking_for ) ) {
				return $block['innerHTML'];
			}
		}

		return '';
	}

}
