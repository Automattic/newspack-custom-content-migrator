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
class CCMMigrator implements InterfaceCommand {
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
			'newspack-content-migrator ccm-set-posts-as-archive',
			[ $this, 'cmd_set_posts_as_archive' ],
			[
				'shortdesc' => 'Set migrated posts as archive.',
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

		WP_CLI::add_command(
			'newspack-content-migrator ccm-set-post-brands-and-primary-brand',
			[ $this, 'cmd_set_post_brands_and_primary_brand' ],
			[
				'shortdesc' => 'Migrate post Brands and primary brand from meta.',
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
	 * Callable for `newspack-content-migrator ccm-set-post-brands-and-primary-brand`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_set_post_brands_and_primary_brand( $args, $assoc_args ) {
		$log_file        = 'ccm_brands_migration.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migration_set_as_archive',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => 'ccm_brands',
				'compare' => 'EXISTS',
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

			$raw_brands = get_post_meta( $post->ID, 'ccm_brands', true );
			if ( $raw_brands ) {
				$brands = json_decode( $raw_brands, true );
				// Create brands under the `Brand` taxonomy if they don't exist, and set them to the post.
				$brand_ids = [];
				foreach ( $brands as $brand ) {
					// Check if the brand exists.
					$term = get_term_by( 'name', $brand, 'brand' );
					if ( $term ) {
						$brand_ids[] = $term->term_id;
					} else {
						// Create the brand.
						$term = wp_insert_term( $brand, 'brand' );
						if ( ! is_wp_error( $term ) ) {
							$brand_ids[] = $term['term_id'];
						}
					}
				}

				// Set the brands to the post.
				if ( ! empty( $brand_ids ) ) {
					wp_set_post_terms( $post->ID, $brand_ids, 'brand' );
					$this->logger->log( $log_file, sprintf( 'Setting brands for the post %d: %s', $post->ID, implode( ', ', $brands ) ) );
				}

				// Setting primary brand if it exists.
				$primary_brand = get_post_meta( $post->ID, 'ccm_primary_brand', true );
				if ( $primary_brand ) {
					$term = get_term_by( 'name', $primary_brand, 'brand' );
					if ( $term ) {
						update_post_meta( $post->ID, '_primary_brand', $term->term_id );
						$this->logger->log( $log_file, sprintf( 'Setting primary brand for the post %d: %s', $post->ID, $primary_brand ) );
					}
				}
			}

			update_post_meta( $post->ID, '_newspack_migration_gallery_migrated_', true );
		}
	}

	/**
	 * Callable for `newspack-content-migrator ccm-set-posts-as-archive`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_set_posts_as_archive( $args, $assoc_args ) {
		$log_file        = 'ccm_posts_as_archive.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migration_set_as_archive',
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
			WP_CLI::line( sprintf( 'Post %d/%d (%d)', $index + 1, $total_posts, $post->ID ) );

			// Add "Archive" tag.
			wp_set_post_tags( $post->ID, 'Archive', true );

			// Add "Archive" postmeta.
			update_post_meta( $post->ID, '_newspack_migration_is_archive', true );

			update_post_meta( $post->ID, '_newspack_migration_set_as_archive', true );
		}
	}
}
