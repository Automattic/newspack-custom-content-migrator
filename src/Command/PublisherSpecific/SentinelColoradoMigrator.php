<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use \WP_CLI;

/**
 * Custom migration scripts for Spheres of Influence.
 */
class SentinelColoradoMigrator implements InterfaceCommand {
	// Logs.
	const GALLERY_LOGS = 'sentinelColorradoGalleryMigration.log';

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

    /**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
        $this->posts_logic                       = new PostsLogic();
	}

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
			'newspack-content-migrator sentinel-colorado-migrate-gallery',
			array( $this, 'sentinel_colorado_migrate_gallery' ),
			array(
				'shortdesc' => 'Migrate Gallery shortcode.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Bath to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator sentinel-colorado-migrate-gallery`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function sentinel_colorado_migrate_gallery( $args, $assoc_args ) {
		WP_CLI::confirm( 'Confirm that you run the content conversion to blocks before migrating the galleries.' );

		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migrated_gallery',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
                // 'p'              => 583033,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 583033,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
            $post_blocks = array_map(
                function( $block ) {
					if ( array_key_exists( 'innerHTML', $block ) && str_contains( $block['innerHTML'], '[gallery' ) ) {
						$matches_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( 'gallery', $block['innerHTML'] );
						if ( empty( $matches_shortcodes[0] ) ) {
                            return $block;
						}

						foreach ( $matches_shortcodes as $match_shortcode ) {
                            $shortcode_html = $match_shortcode[0];
							$gallery_ids    = $this->squarebracketselement_manipulator->get_attribute_value( 'ids', $shortcode_html );
							if ( empty( $gallery_ids ) ) {
								continue;
							}

							$gallery_ids = explode( ',', $gallery_ids );

                            if ( empty( $gallery_ids ) ) {
                                return $block;
                            }

                            return current( parse_blocks( $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( $gallery_ids ) ) );
						}

                        return $block;
					}

                    return $block;
				},
                parse_blocks( $post->post_content )
            );

            $new_content = serialize_blocks( $post_blocks );
            if ( $new_content !== $post->post_content ) {
                $result = wp_update_post(
                    [
						'ID'           => $post->ID,
						'post_content' => $new_content,
					],
                    true
                );
				if ( is_wp_error( $result ) ) {
					$this->log( self::GALLERY_LOGS, 'Failed to update post: ' . $post->ID );
				} else {
                    update_post_meta( $post->ID, '_newspack_migrated_gallery', true );
					$this->log( self::GALLERY_LOGS, 'Updated post: ' . $post->ID );
				}
            }
		}

        wp_cache_flush();
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
