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
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
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
			'newspack-content-migrator trnn-migrate-video-content',
			[ $this, 'cmd_trnn_migrate' ],
			[
				'shortdesc' => 'Migrate video content from meta into regular post content.',
				'synopsis' => [
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
	}

	/**
	 * Migrate video content from meta into regular post content.
	 */
	public function cmd_trnn_migrate( $args, $assoc_args ) {
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
