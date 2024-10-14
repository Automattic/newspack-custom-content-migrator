<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Newspack\MigrationTools\Logic\GutenbergBlockGenerator;
use NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

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
				'shortdesc' => 'Migrate Photonic Galleries to Jetpack Tilled Galleries and Slideshows.',
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
			'newspack-content-migrator saporta-report-migrate-authors',
			[ $this, 'cmd_migrate_authors' ],
			[
				'shortdesc' => 'Migrate authors to Co-Authors Plus.',
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
				// 'key'     => '_newspack_migration_gallery_migrated',
				'key'     => '_newspack_migration_gallery_migrated_',
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

			update_post_meta( $post->ID, '_newspack_migration_gallery_migrated_', true );
		}
	}

	/**
	 * Callable for `newspack-content-migrator saporta-report-migrate-authors`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_authors( $args, $assoc_args ) {
		$log_file        = 'saporta_report_migrate_authors.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 2000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			$this->logger->log( $log_file, 'Co-Authors Plus plugin not found. Install and activate it before using this command.', Logger::ERROR );
			return;
		}

		$meta_query = [
			[
				'key'     => '_newspack_migration_authors_migrated_',
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
				'fields'         => 'ids',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post_id ) {
			$author_type_meta = get_post_meta( $post_id, 'BS_author_type', true );
			if ( 'BS_author_is_guest' === $author_type_meta ) {
				$author_type_meta        = get_post_meta( $post_id, 'BS_guest_author_name', true );
				$author_name_meta        = get_post_meta( $post_id, 'BS_guest_author_name', true );
				$author_description_meta = $this->get_author_meta_by_author_name( $author_name_meta, 'BS_guest_author_description' );
				$author_image_id_meta    = $this->get_author_meta_by_author_name( $author_name_meta, 'BS_guest_author_image_id' );
				$author_url_meta         = $this->get_author_meta_by_author_name( $author_name_meta, 'BS_guest_author_url' );

				try {
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
						[
							'display_name' => $author_name_meta,
							'website'      => $author_url_meta,
							'description'  => $author_description_meta,
							'avatar'       => $author_image_id_meta,
						]
					);

					if ( is_wp_error( $guest_author_id ) ) {
						$this->logger->log( $log_file, sprintf( "Could not create GA full name '%s' (from the post %d): %s", $author_name_meta, $post_id, $guest_author_id->get_error_message() ), Logger::WARNING );
						continue;
					}

					// Set original ID.
					$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post_id );
					$this->logger->log( $log_file, sprintf( 'Assigning the post %d a new co-author: %s', $post_id, $author_name_meta ), Logger::SUCCESS );
				} catch ( \Exception $e ) {
					$this->logger->log( $log_file, sprintf( "Could not create GA full name '%s' (from the post %d): %s", $author_name_meta, $post_id, $e->getMessage() ), Logger::WARNING );
				}
			}

			update_post_meta( $post_id, '_newspack_migration_authors_migrated_', true );
		}
	}

	/**
	 * Try to find filled author meta value based on author's name.
	 *
	 * @param string $author_name_meta Author display name.
	 * @param string $meta_key Author meta to get.
	 * @return string
	 */
	private function get_author_meta_by_author_name( $author_name_meta, $meta_key ) {
		global $wpdb;
		// Get all author's posts to get the meta from one of them.
		$raw_author_post_ids = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT(post_id) FROM {$wpdb->postmeta} WHERE meta_value = %s", $author_name_meta ), \ARRAY_A );
		$author_post_ids     = array_map( 'intval', array_map( 'current', array_values( $raw_author_post_ids ) ) );

		// Get the meta value when filled.
		$post_id_placeholders = implode( ', ', array_fill( 0, count( $author_post_ids ), '%d' ) );
		$meta_values          = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT(meta_value) FROM {$wpdb->postmeta} WHERE post_id IN ($post_id_placeholders) AND meta_key = %s", array_merge( $author_post_ids, [ $meta_key ] ) ) ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $meta_values as $meta ) {
			if ( ! empty( $meta->meta_value ) ) {
				return $meta->meta_value;
			}
		}

		return '';
	}
}
