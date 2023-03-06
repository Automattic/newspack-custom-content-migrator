<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Saporta News.
 */
class SaportaReportMigrator implements InterfaceCommand {
	const GALLERIES_MIGRATOR_LOG = 'saporta_report_galleries_migrator.log';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic               = new CoAuthorPlusLogic();
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
		$this->gutenberg_block_generator         = new GutenbergBlockGenerator();
		$this->logger                            = new Logger();
	}

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
			'newspack-content-migrator saporta-report-migrate-galleries',
			[ $this, 'cmd_migrate_galleries' ],
			[
				'shortdesc' => 'Migrate Photonic Galleries to Co-Authors Plus.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
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
	 * Callable for `newspack-content-migrator saporta-report-migrate-galleries`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_galleries( $args, $assoc_args ) {
		$log_file        = 'saporta_report_migrate_galleries.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migration_gallery_migrated',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts       = $query->get_posts();
		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			WP_CLI::line( sprintf( 'Post %d/%d', $index + 1, $total_posts ) );

			$need_manual_fix = false;
			$content_blocks  = array_map(
                function( $block ) use ( &$need_manual_fix ) {
					if ( 'core/shortcode' === $block['blockName'] && str_contains( $block['innerHTML'], '[photos ids' ) ) {
						$shortcode      = str_replace( [ '’', '′' ], '"', mb_convert_encoding( $block['innerHTML'], 'UTF-8', 'HTML-ENTITIES' ) );
						$raw_images_ids = $this->squarebracketselement_manipulator->get_attribute_value( 'ids', $shortcode );
						$images_ids     = explode( ',', $raw_images_ids );
						$style          = $this->squarebracketselement_manipulator->get_attribute_value( 'style', $shortcode );
						if ( 'mosaic' === $style ) {
							$need_manual_fix = true;
							return $this->gutenberg_block_generator->get_jetpack_tiled_gallery( $images_ids );
						} elseif ( 'thumbs' === $style ) {
							return $this->gutenberg_block_generator->get_jetpack_slideshow( $images_ids );
						}
					}

					return $block;
				},
                parse_blocks( $post->post_content )
            );

			$migrated_content = serialize_blocks( $content_blocks );
			if ( $migrated_content !== $post->post_content ) {
				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $migrated_content,
					)
				);

				$this->logger->log( $log_file, sprintf( 'Gallery migrated for the post %d', $post->ID ), Logger::SUCCESS );
				if ( $need_manual_fix ) {
					$this->logger->log( $log_file, sprintf( 'Please edit the post and fix manually the gallery block by clicking on `Attempt Block Recovery`: %spost.php?post=%d&action=edit"', get_admin_url(), $post->ID ), Logger::WARNING );
				}
			}

			update_post_meta( $post->ID, '_newspack_migration_gallery_migrated', true );
		}
	}
}
