<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
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
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

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
		$this->attachments_logic                 = new AttachmentsLogic();
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

		WP_CLI::add_command(
			'newspack-content-migrator ccm-migrate-primary-category',
			[ $this, 'cmd_migrate_primary_category' ],
			[
				'shortdesc' => 'Migrate primary category from meta.',
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
			'newspack-content-migrator ccm-migrate-galleries-and-featured-image',
			[ $this, 'cmd_migrate_galleries_and_featured_image' ],
			[
				'shortdesc' => 'Migrate galleries and featured images from meta.',
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
			'newspack-content-migrator ccm-migrate-co-authors',
			[ $this, 'cmd_migrate_co_authors' ],
			[
				'shortdesc' => 'Migrate co-authors from meta.',
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
		$grouped_brands  = [
			'Brighton Standard Blade'        => 'Digital North',
			'Westminster Window'             => 'Digital North',
			'Northglenn/Thornton Sentinel'   => 'Digital North',
			'Fort Lupton Press'              => 'Digital North',
			'Commerce City Sentinel Express' => 'Digital North',

			'Castle Rock News Press'         => 'Digital South',
			'Castle Pines News Press'        => 'Digital South',
			'Highlands Ranch Herald'         => 'Digital South',
			'Douglas County News Press'      => 'Digital South',
			'Littleton Independent'          => 'Digital South',
			'Englewood Herald'               => 'Digital South',
			'Centennial Citizen'             => 'Digital South',
			'Lone Tree Voice'                => 'Digital South',

			'Elbert County News'             => 'Digital East',
			'Parker Chronicle'               => 'Digital East',

			'Arvada Press'                   => 'Digital West',
			'Clear Creek Courant'            => 'Digital West',
			'Wheat Ridge Transcript'         => 'Digital West',
			'Lakewood Sentinel'              => 'Digital West',
			'Golden Transcript'              => 'Digital West',
			'Jeffco Transcript'              => 'Digital West',
			'Canyon Courier'                 => 'Digital West',

			'Washington Park Profile'        => 'Digital Denver',
			'Life on Capitol Hill'           => 'Digital Denver',
			'Denver Herald Dispatch'         => 'Digital Denver',

			'Colorado Community Media'       => '',
			'285 Hustler'                    => '',
			'South Platte Independent'       => '',
		];
		$meta_query      = [
			[
				'key'     => '_newspack_mgration_brand_migrated',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => 'ccm_brands',
				'compare' => 'EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				// 'p'              => 5,
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
				// 'p'              => 5,
				'post_type'      => 'post',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$raw_brands = get_post_meta( $post->ID, 'ccm_brands', true );
			// If the post has no brands, we don't need to do anything.
			$post_handled = true;

			if ( '[]' === $raw_brands ) {
				$this->logger->log( $log_file, sprintf( 'Post %d doesn\'t belong to any brand', $post->ID ) );
			}

			if ( $raw_brands ) {
				$brands = json_decode( $raw_brands, true );
				// Create brands under the `Brand` taxonomy if they don't exist, and set them to the post.
				$brand_ids    = [];
				$brand_titles = [];
				foreach ( $brands as $brand ) {
					// We need to have only brands from the grouped list.
					if ( ! array_key_exists( $brand, $grouped_brands ) ) {
						// If the brand is not on the list, we need to re-treat the post next time.
						$post_handled = false;
						continue;
					}

					$main_brand = $grouped_brands[ $brand ];

					// Check if the main brand exists.
					if ( '' === $main_brand ) {
						// If it's the main brand "Colorado Community Media", we need to re-treat the post next time.
						continue;
					}

					// Set the brand.
					$term = get_term_by( 'name', $brand, 'brand' );
					if ( $term ) {
						$brand_ids[]    = $term->term_id;
						$brand_titles[] = $brand;
					} else {
						// Create the brand.
						$term = wp_insert_term( $brand, 'brand' );
						if ( ! is_wp_error( $term ) ) {
							$brand_ids[]    = $term['term_id'];
							$brand_titles[] = $brand;
						} else {
							$this->logger->log( $log_file, sprintf( 'Error adding the brand `%s` for the post %d: %s', $brand, $post->ID, $term->get_error_message() ) );
						}
					}

					// Set the main brand.
					$main_term = get_term_by( 'name', $main_brand, 'brand' );
					if ( $main_term ) {
						$brand_ids[]    = $main_term->term_id;
						$brand_titles[] = $main_brand;
					} else {
						// Create the brand.
						$main_term = wp_insert_term( $main_brand, 'brand' );
						if ( ! is_wp_error( $main_term ) ) {
							$brand_ids[]    = $main_term['term_id'];
							$brand_titles[] = $main_brand;
						} else {
							$this->logger->log( $log_file, sprintf( 'Error adding the brand `%s` for the post %d: %s', $main_brand, $post->ID, $main_term->get_error_message() ) );
						}
					}
				}

				// Set the brands to the post.
				if ( ! empty( $brand_ids ) ) {
					wp_set_post_terms( $post->ID, $brand_ids, 'brand' );
					$this->logger->log( $log_file, sprintf( 'Setting brands for the post %d: %s', $post->ID, implode( ', ', array_unique( $brand_titles ) ) ) );

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
			}

			if ( $post_handled ) {
				update_post_meta( $post->ID, '_newspack_mgration_brand_migrated', true );
			} else {
				$this->logger->log( $log_file, sprintf( 'Post %d brands are not in the list: %s', $post->ID, implode( ', ', $brands ) ) );
			}
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

	/**
	 * Callable for `newspack-content-migrator ccm-migrate-primary-category`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_primary_category( $args, $assoc_args ) {
		$log_file        = 'ccm_primary_category_migration.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migration_migrate_primary_category',
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

			$primary_category = get_post_meta( $post->ID, 'ccm_primary_category', true );

			if ( $primary_category ) {
				$terms = get_terms(
					[
						'taxonomy'   => 'category',
						'name'       => $primary_category,
						'hide_empty' => false,
					]
				);

				if ( count( $terms ) !== 1 ) {
					$this->logger->log( $log_file, sprintf( "Can't find the category %s", $primary_category ), Logger::WARNING );
				}

				$category = $terms[0];

				update_post_meta( $post->ID, '_yoast_wpseo_primary_category', $category->term_id );
				$this->logger->log( $log_file, sprintf( 'Primary category for the post %d is set to: %s', $post->ID, $primary_category ), Logger::SUCCESS );
			}

			update_post_meta( $post->ID, '_newspack_migration_migrate_primary_category', true );
		}
	}

	/**
	 * Callable for `newspack-content-migrator ccm-migrate-galleries-and-featured-image`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_galleries_and_featured_image( $args, $assoc_args ) {
		$galleries_log_file      = 'ccm_galleries_migration.log';
		$featured_image_log_file = 'ccm_featured_image_migration.log';
		$posts_per_batch         = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch                   = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migration_migrate_gallery_and_thumbnmail',
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

			$image_urls = json_decode( get_post_meta( $post->ID, 'ccm_images', true ), true );

			if ( ! empty( $image_urls ) ) {
				// Import images, and create Jetpack Slideshow galleries.
				$image_ids = [];
				foreach ( $image_urls as $image_url ) {
					$image_id = $this->attachments_logic->import_external_file( $image_url, null, null, null, null, $post->ID );
					if ( is_wp_error( $image_id ) ) {
						$this->logger->log( $galleries_log_file, sprintf( "Can't import image %s: %s", $image_url, $image_id->get_error_message() ), Logger::WARNING );
					} else {
						$image_ids[] = $image_id;
					}
				}

				if ( ! empty( $image_ids ) ) {
					// Create Jetpack Slideshow gallery.
					$slideshow_block = $this->gutenberg_block_generator->get_jetpack_slideshow( $image_ids );

					// Update post content by adding the slideshow block on the top of the content.
					wp_update_post(
						[
							'ID'           => $post->ID,
							'post_content' => serialize_block( $slideshow_block ) . $post->post_content,
						]
					);

					$this->logger->log( $galleries_log_file, sprintf( 'Gallery for post %d is created: %s', $post->ID, implode( ',', $image_ids ) ), Logger::SUCCESS );

					// Set the first image as featured image.
					$featured_image_id = $image_ids[0];
					$set_featured      = set_post_thumbnail( $post->ID, $featured_image_id );
					if ( ! $set_featured ) {
						$this->logger->log( $featured_image_log_file, sprintf( "Can't set featured image for post %d", $post->ID ), Logger::WARNING );
					} else {
						$this->logger->log( $featured_image_log_file, sprintf( 'Featured image for post %d is set: %s', $post->ID, $featured_image_id ), Logger::SUCCESS );
					}
				}
			}

			update_post_meta( $post->ID, '_newspack_migration_migrate_gallery_and_thumbnmail', true );
		}
	}

	/**
	 * Callable for `newspack-content-migrator ccm-migrate-co-authors`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_co_authors( $args, $assoc_args ) {
		$log_file        = 'ccm_cp_authors_migration.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$meta_query = [
			[
				'key'     => '_newspack_migration_migrate_co_authors',
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

			$raw_co_authors = get_post_meta( $post->ID, 'ccm_coauthors', true );

			// Author names come in many formats:
			// - "Alex K.W. Schultz<br>
			// Special to Colorado Community Media"
			// - "<a href="http://coloradocommunitymedia.com/detail.html?sub_id=928c8e2931">Tayler Shaw</a> <br>tshaw@coloradocommunitymedia.com"
			// - "Staff Report"
			// - "Bruce Goldberg<br>
			// Special to Colorado Community Media"
			// - "Olivia Prentzel<br>
			// The Colorado Sun"
			// - "By Jonathan Maness"
			// - "<a href="http://coloradocommunitymedia.com/detail.html?sub_id=b32cff5255">Ellis Arnold</a> and Haley Lena <br>earnold@coloradocommunitymedia.com"
			// - "Chancy J. Gatlin-Anderson  Special to Colorado Community Media"
			// - "Sandra Fish, Jesse Paul and Delaney Nelson - The Colorado Sun"
			// - "Olivia Prentzel  and  Marvis Gutierrez<br>
			// The Colorado Sun"
			// - "Column by Judy Allison"
			// - "Brandon Davis/special to Colorado Community Media"
			// - "Photo by Mark Harden"
			// - "Guest column by Elicia Hesselgrave"
			// - "By By: Hames O'Hern"
			// - "By By: Henry F Bohne &amp; William Mattocks"

			$cleaned_co_authors = array_filter(
				array_map(
					function( $co_author ) {
						$co_author = trim( $co_author );
						// Remove "By By:" prefix.
						$co_author = preg_replace( '/^By By:\s*/', '', $co_author );
						// Remove "By" prefix.
						$co_author = trim( preg_replace( '/^By\s*/', '', $co_author ), ':' );
						// Remove "Column by", "Guest column by", "Special to Colorado Community Media", "The Colorado Sun", and "Photo by" prefixes and suffixes.
						$co_author = preg_replace( '/\s*(Column|Guest column|Special to Colorado Community Media|The Colorado Sun|Photo)\s*(by)?\s*/i', '', $co_author );
						// Remove email addresses.
						$co_author = preg_replace( '/\S+@\S+\.\S+/', '', $co_author );

						return empty( $co_author ) ? null : $co_author;
					},
					preg_split(
						'/<br>|\s+and\s+|,|\n|\/|-|&amp;/',
						wp_kses( preg_replace( '/\s+/', ' ', $raw_co_authors ), [ 'br' => [] ] )
					)
				)
			);

			if ( empty( $cleaned_co_authors ) ) {
				// Set default Staff author.
				$default_co_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => 'Staff' ] );
				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $default_co_author_id ], $post->ID );
				update_post_meta( $post->ID, '_newspack_post_with_default_author', true );

				$this->logger->log( $log_file, sprintf( 'Setting post %d default author: %s', $post->ID, 'Staff' ), Logger::SUCCESS );
			} else {
				$co_author_ids = [];
				foreach ( $cleaned_co_authors as $cleaned_co_author ) {
					$co_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $cleaned_co_author ] );
					if ( is_wp_error( $co_author_id ) ) {
						$this->logger->log( $log_file, sprintf( 'Error adding the co-author `%s` for the post %d: %s', $cleaned_co_author, $post->ID, $co_author_id->get_error_message() ) );
					} else {
						$co_author_ids[] = $co_author_id;
					}
				}

				$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_author_ids, $post->ID );
				$this->logger->log( $log_file, sprintf( 'Setting post %d co-author(s): %s', $post->ID, join( ', ', $cleaned_co_authors ) ), Logger::SUCCESS );
			}

			update_post_meta( $post->ID, '_newspack_migration_migrate_co_authors', true );
		}
	}
}
