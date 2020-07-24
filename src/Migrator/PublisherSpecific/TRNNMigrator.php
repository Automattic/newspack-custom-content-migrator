<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use \WP_Error;

/**
 * Custom migration scripts for The Real News Network.
 */
class TRNNMigrator implements InterfaceMigrator {

	/**
	 * @var null|PostsMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|CoAuthors_Guest_Authors
	 */
	private $coauthors_guest_authors;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Validates whether Co-Author Plus plugin's dependencies were successfully set.
	 *
	 * @return bool Is everything set up OK.
	 */
	private function validate_co_authors_plus_dependencies() {
		if (
			( ! is_a( $this->coauthors_plus, CoAuthors_Plus ) ) ||
			( ! is_a( $this->coauthors_guest_authors, CoAuthors_Guest_Authors ) )
		) {
			return false;
		}

		if ( false === $this->is_coauthors_active() ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks whether Co-authors Plus is installed and active.
	 *
	 * @return bool Is active.
	 */
	public function is_coauthors_active() {
		$active = false;
		foreach ( wp_get_active_and_valid_plugins() as $plugin ) {
			if ( false !== strrpos( $plugin, 'co-authors-plus.php' ) ) {
				$active = true;
			}
		}

		return $active;
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;

			// Set Co-Authors Plus dependencies.
			global $coauthors_plus;

			$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

			$file_1 = $plugin_path . '/co-authors-plus/co-authors-plus.php';
			$file_2 = $plugin_path . '/co-authors-plus/php/class-coauthors-guest-authors.php';
			$included_1 = is_file( $file_1 ) && include_once $file_1;
			$included_2 = is_file( $file_2 ) && include_once $file_2;

			if (
				is_null( $coauthors_plus ) ||
				( false === $included_1 ) ||
				( false === $included_2 ) ||
				( ! is_a( $coauthors_plus, 'CoAuthors_Plus' ) )
			) {
				return self::$instance;
			}

			self::$instance->coauthors_plus          = $coauthors_plus;
			self::$instance->coauthors_guest_authors = new \CoAuthors_Guest_Authors();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator trnn-migrate-video-content',
			[ $this, 'cmd_trnn_migrate_videos' ],
			[
				'shortdesc' => 'Migrate video content from meta into regular post content.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'post_id',
						'description' => __('ID of a specific post to process'),
						'optional'    => true,
					],
					[
						'type'        => 'assoc',
						'name'        => 'dry-run',
						'description' => __('Perform a dry run, making no changes.'),
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator trnn-migrate-authors',
			[ $this, 'cmd_trnn_migrate_authors' ],
			[
				'shortdesc' => 'Migrate the old CPT-based authors to CPA Guest Authors we\'ve already created',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually migrate any authors.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Migrate video content from meta into regular post content.
	 */
	public function cmd_trnn_migrate_videos( $args, $assoc_args ) {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		if ( empty( $args ) ) {
			$posts = get_posts(
				[
					'post_type'      => 'post',
					'posts_per_page' => -1,
					'meta_query'     => [
						[
							'key'     => '_cpt_converted_from',
							'value'   => 'trnn_story',
							'compare' => '=',
						],
					],
				]
			);
		} else {
			$post_id = $args[0];
			$posts = [
				get_post( $post_id )
			];
		}

		if ( empty( $posts ) ) {
			WP_CLI::error( __( 'No posts found.' ) );
		} else {
			WP_CLI::line( sprintf(
				__( 'Found %d posts to migrate.' ),
				count( $posts )
			) );
		}

		foreach ( $posts as $post ) {
			WP_CLI::line( sprintf( __( 'Checking post %d' ), $post->ID ) );
			if ( get_post_meta( $post->ID, 'ncc_trnn_migrated', true ) ) {
				WP_CLI::line( sprintf( __( 'Post %d has already been migrated. Skipping.' ), $post->ID ) );
				continue;
			}

			$updates = [
				'post_content' => '',
			];

			$video      = $this->get_video( $post->ID );
			$synopses   = $this->get_synopses( $post->ID );
			$transcript = $this->get_transcript( $post->ID );

			if ( $synopses ) {
				$updates['post_excerpt'] = $post->post_content;
				$updates['post_content'] = $synopses;
			} else {
				$updates['post_content'] = $post->post_content;
			}

			if ( $video ) {
				$updates['post_content'] = $video . "\n" . $updates['post_content'];
			}

			if ( $transcript ) {
				$updates['post_content'] .= "\n" . $transcript;
			}

			if ( $post->post_content === $updates['post_content'] ) {
				WP_CLI::line( sprintf( __( 'No update made for post %d' ), $post->ID ) );
				continue;
			}

			if ( $dry_run ) {
				$result = true;
			} else {
				$result = $wpdb->update( $wpdb->prefix . 'posts', $updates, [ 'ID' => $post->ID ] );
			}

			if ( ! $result ) {
				WP_CLI::line( sprintf( __( 'Error updating post %d.' ), $post->ID ) );
			} else {
				if ( ! $dry_run ) {
					update_post_meta( $post->ID, 'ncc_trnn_migrated', 1 );
				}
				WP_CLI::line( sprintf( __( 'Updated post %d' ), $post->ID ) );
			}
		}

		wp_cache_flush();
		WP_CLI::line( __( 'Completed' ) );
	}

	public function cmd_trnn_migrate_authors( $args, $assoc_args ) {

		if ( false === $this->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			exit;
		}

		$dry_run = isset( $assoc_args[ 'dry-run' ] ) ? true : false;
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : false;

		// Grab the posts to convert then.
		if ( $post_id ) {
			$posts = [ get_post( $post_id ) ];
		} else {
			$posts = get_posts( [
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'meta_query'     => [ [
					'key'     => 'bios',
					'compare' => 'EXISTS',
				] ],
			] );
		}

		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts found.' );
		}

		foreach ( $posts as $post ) {
			WP_CLI::line( sprintf( 'Processing post %d', $post->ID ) );
			$old_author_posts = get_post_meta( $post->ID, 'bios', true );
			if ( empty( $old_author_posts ) ) {
				WP_CLI::warning( \sprintf( 'No author found in post %d', $post->ID ) );
				continue; // Skip it, 'cause there's no data to use.
			}

			// Get the new guest author using the original post IDs.
			$new_guest_authors = $this->get_new_ga_by_original_id( $old_author_posts );
			$coauthors = [];
			foreach ( $new_guest_authors as $new_guest_author ) {
				WP_CLI::line( sprintf( 'Retrieving guest author from ID %d', $new_guest_author ) );
				$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'id', $new_guest_author );
				WP_CLI::line( sprintf( 'Found author "%s"', $guest_author->user_nicename ) );
				$coauthors[]  = $guest_author->user_nicename;
			}
			WP_CLI::line( 'Assigning GAs to post.' );
			if ( true !== $dry_run ) {
				$this->coauthors_plus->add_coauthors( $post->ID, $coauthors, $append_to_existing_users = false );
			}
		}

		WP_CLI::success( sprintf( 'Finished processing %d posts', count( $posts ) ) );

	}

	protected function get_new_ga_by_original_id( $old_author_posts ) {

		WP_CLI::line( 'Searching for new authors using original IDs...' );

		$new_guest_authors = [];

		// Loop through each old author post, finding the new GA for that author.
		foreach ( $old_author_posts as $old_author_post ) {
			$search = get_posts( [
				'post_type'      => 'guest-author',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_query'     => [ [
					'key'   => '_post_migrated_from',
					'value' => $old_author_post,
				] ],
			] );
			if ( ! empty( $search ) ) {
				$new_guest_authors[] = $search[0]->ID;
				WP_CLI::line( sprintf( 'Found new guest author %d from original ID %d', $search[0]->ID, $old_author_post ) );
			} else {
				WP_CLI::line( 'No new guest authors found.' );
			}
		}
WP_CLI::line(\sprintf('New GAs: %s',var_export($new_guest_authors,true)));

		return $new_guest_authors;
	}

	/**
	 * Get synopses for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string The synopses content.
	 */
	protected function get_synopses( $post_id ) {
		$synopses = '';

		$synopses_ids = get_post_meta( $post_id, 'synopsis', true );
		if ( ! $synopses_ids ) {
			$synopses_ids = [];
		}

		WP_CLI::line( sprintf( __( '%d synopses found for post %d' ), count( $synopses_ids ), $post_id ) );

		foreach ( $synopses_ids as $synopsis_id ) {
			$synopsis_post = get_post( $synopsis_id );
			if ( ! $synopsis_post ) {
				continue;
			}
			$synopses .= $synopsis_post->post_content;
		}

		return $synopses;
	}

	/**
	 * Get video for a post. This is designed for the WP auto-embed handling
	 * in which video URLs on their own line get automatically converted into embeds.
	 *
	 * @param int $post_id Post ID.
	 * @return string The video embed.
	 */
	protected function get_video( $post_id ) {
		$video_id = get_post_meta( $post_id, 'trnn_youtubeurl', true );

		WP_CLI::line( sprintf( __( 'Video found for post %d: %s' ), $post_id, $video_id ? $video_id : 'None' ) );

		if ( ! $video_id ) {
			return '';
		}

		return "\nhttps://www.youtube.com/watch?v=" . $video_id . "\n";
	}

	/**
	 * Get transcript for a post, with heading and separator.
	 *
	 * @param int $post_id Post ID.
	 * @return string The transcript.
	 */
	protected function get_transcript( $post_id ) {
		$transcript = get_post_meta( $post_id, 'trnn_transcript', true );
		if ( ! $transcript ) {
			WP_CLI::line( sprintf( __( 'No transcript found for post %d' ), $post_id ) );
			return '';
		}

		WP_CLI::line( sprintf( __( 'Transcript found for post %d' ), $post_id ) );

		return "\n<hr />\n\n<h2>Story Transcript</h2>\n\n" . $transcript . "\n";
	}
}
