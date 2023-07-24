<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
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
	 * @var RedirectionLogic $redirection_logic
	 */
	private RedirectionLogic $redirection_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->redirection_logic   = new RedirectionLogic();
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
				'shortdesc' => 'Migrates authors from the API content as Co-Authors.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator emancipator-redirects',
			[ $this, 'cmd_redirects' ],
			[
				'shortdesc' => 'Looks at redirects.', // TODO
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator emancipator-post-subtitles',
			[ $this, 'cmd_post_subtitles' ],
			[
				'shortdesc' => 'Add post subtitles', // TODO
				'synopsis'  => [],
			]
		);
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

		$data     = $this->get_data( $args, $assoc_args );
		$progress = WP_CLI\Utils\make_progress_bar( 'Processing post subtitles', $data['posts_total'] );

		foreach ( $data['posts'] as $post ) {
			WP_CLI::log(
				sprintf(
					'Post ID: %s, %s',
					$post->ID,
					$post->guid
				)
			);

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );
			$subtitle    = $api_content['subheadlines']['basic'] ?? false;
			if ( ! $data['dry_run'] && $subtitle ) {
				update_post_meta( $post->ID, 'newspack_post_subtitle', $subtitle );
			}
			$progress->tick();
		}

		$progress->finish();
	}

	public function cmd_redirects( $args, $assoc_args ) {

		if ( ! class_exists( \Red_Item::class ) ) {
			WP_CLI::error( 'Redirection plugin not found. Install, activate, and configure it before using this command.' );
		}

		$data = $this->get_data( $args, $assoc_args );

		$progress = WP_CLI\Utils\make_progress_bar( 'Processing redirects', $data['posts_total'] );

		foreach ( $data['posts'] as $post ) {
			WP_CLI::log(
				sprintf(
					'Post ID: %s, %s',
					$post->ID,
					$post->guid
				)
			);

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			$redirect_to = $api_content['related_content']['redirect'][0]['redirect_url'] ?? false;
			if ( $redirect_to ) {
				$redirect_from = get_permalink( $post->ID );
				if ( ! $data['dry_run'] && empty( \Red_Item::get_for_url( $redirect_from ) ) ) {
					$this->redirection_logic->create_redirection_rule(
						$post->ID . '-redirect',// TODO? WHat should that be?
						$redirect_from,
						$redirect_to
					);
					WP_CLI::log( sprintf( 'Added a redirect for post with ID %d to %s', $post->ID, $redirect_to ) );
				}
			}
			$progress->tick();
		}

		$progress->finish();
	}

	/**
	 * Process byline data.
	 */
	public function cmd_authors( $args, $assoc_args ): void {

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$data        = $this->get_data( $args, $assoc_args );
		$progress = WP_CLI\Utils\make_progress_bar( 'Processing bylines', $data['posts_total'] );

		foreach ( $data['posts'] as $post ) {
			WP_CLI::log(
				sprintf(
					'Post ID: %s, %s',
					$post->ID,
					$post->guid
				)
			);

			$credits = [];

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			// TODO. THe real author/owner? Should we use that instead?
			$real_author = $api_content['revision']['user_id'] ?? false;
			WP_CLI::log( sprintf( 'Real author? %s', $real_author ) );

			if ( ! empty( $api_content['credits']['by'] ) ) {
				foreach ( $api_content['credits']['by'] as $credit ) {
					$credits[] = empty( $credit['name'] ) ? $credit : $credit['name'];
				}

				if ( ! $data['dry_run'] ) {
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
					WP_CLI::log( '----' );
				}
			}
			$progress->tick();
		}

		$progress->finish();
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

	/**
	 * Helper to get data and args.
	 *
	 * Returned array will have the following keys:
	 * - num_posts: how many posts the user wants to process.
	 * - dry_run: well, just that.
	 * - posts: the WP_Post objects.
	 * - posts_total: how many posts were found.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return array
	 */
	private function get_data( $args, $assoc_args ): array {
		$data              = [];
		$data['num_posts'] = $assoc_args['num_posts'] ?? - 1;
		$data['dry_run']   = $assoc_args['dry_run'] ?? false;
		$posts_query_args  = [
			'posts_per_page' => $data['num_posts'],
			'post_type'      => 'post',
			'post_status'    => 'publish', // TODO. There are a lot of other states. Not sure if we migrate all.
			'paged'          => 1,
		];
		if ( ! empty( $assoc_args['post_id'] ) ) {
			$posts_query_args['p'] = $assoc_args['post_id'];
		}
		$data['posts']       = get_posts( $posts_query_args );
		$data['posts_total'] = count( $data['posts'] );

		return $data;
	}

}
