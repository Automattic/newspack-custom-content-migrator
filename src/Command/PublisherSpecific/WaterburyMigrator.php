<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

/**
 * Custom migration scripts for Washington Monthly.
 */
class WaterburyMigrator implements InterfaceCommand {
	const FEATURED_IMAGES_LOG = 'waterbury_featured_images.log';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator waterbury-migrate-featured-images-from-content',
			[ $this, 'waterbury_migrate_featured_images_from_content' ],
			[
				'shortdesc' => 'Migrate featured images from post content to post featured images.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Bath to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator waterbury-migrate-featured-images-from-content`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function waterbury_migrate_featured_images_from_content( $args, $assoc_args ) {
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migrated_featured_image',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 11,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 11,
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$post_blocks = parse_blocks( $post->post_content );

			if ( ! empty( $post_blocks ) && array_key_exists( 'blockName', $post_blocks[0] ) && 'core/image' === $post_blocks[0]['blockName'] ) {
				preg_match( '/src\s*=\s*"(?P<src>.+?)"/', $post_blocks[0]['innerHTML'], $image_src_match );
				if ( array_key_exists( 'src', $image_src_match ) ) {
					$featured_image_id = attachment_url_to_postid( $image_src_match['src'] );
					if ( $featured_image_id ) {
						set_post_thumbnail( $post->ID, $featured_image_id );
						$this->log( self::FEATURED_IMAGES_LOG, sprintf( 'Setting #%d as featured image for the post %d', $featured_image_id, $post->ID ) );
						$post_blocks[0] = null;
					}
				}
			} else {
				$this->log( self::FEATURED_IMAGES_LOG, sprintf( 'Featured image not found for the post %d', $post->ID ) );
			}

            $new_content = serialize_blocks( array_filter( $post_blocks ) );
            if ( $new_content !== $post->post_content ) {
                $result = wp_update_post(
                    [
						'ID'           => $post->ID,
						'post_content' => $new_content,
					],
                    true
                );
				if ( is_wp_error( $result ) ) {
					$this->log( self::FEATURED_IMAGES_LOG, 'Failed to update post: ' . $post->ID );
				} else {
                    update_post_meta( $post->ID, '_newspack_migrated_featured_image', true );
					$this->log( self::FEATURED_IMAGES_LOG, 'Updated post: ' . $post->ID );
				}
            }
		}

        wp_cache_flush();
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message, $to_cli = true ) {
		if ( $to_cli ) {
			\WP_CLI::line( $message );
		}

		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
