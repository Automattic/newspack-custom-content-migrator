<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use \WP_CLI;

/**
 * Custom migration scripts for Spheres of Influence.
 */
class SentinelColoradoMigrator implements InterfaceCommand {
	// Logs.
	const GALLERY_LOGS    = 'sentinelColoradoGalleryMigration.log';
	const CO_AUTHORS_LOGS = 'sentinelColoradoCoAuthorsMigration.log';
	const ACCORDION_LOGS  = 'sentinelColoradoAccordionMigration.log';

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var GutenbergBlockGenerator $gutenberg_block_generator
	 */
	private $gutenberg_block_generator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
		$this->posts_logic                       = new PostsLogic();
		$this->coauthorsplus_logic               = new CoAuthorPlusLogic();
		$this->gutenberg_block_generator         = new GutenbergBlockGenerator();
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
						'description' => 'Batch to start from.',
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

		WP_CLI::add_command(
			'newspack-content-migrator sentinel-colorado-migrate-authors',
			array( $this, 'sentinel_colorado_migrate_authors' ),
			array(
				'shortdesc' => 'Migrate Authors custom fields.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
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

		WP_CLI::add_command(
			'newspack-content-migrator sentinel-colorado-migrate-bylines',
			array( $this, 'sentinel_colorado_migrate_bylines' ),
			array(
				'shortdesc' => 'Migrate Bylines custom fields.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
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

		WP_CLI::add_command(
			'newspack-content-migrator sentinel-colorado-migrate-accordion',
			array( $this, 'sentinel_colorado_migrate_accordion' ),
			array(
				'shortdesc' => 'Migrate accordion custom fields.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
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
	 * Callable for `newspack-content-migrator sentinel-colorado-migrate-authors`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function sentinel_colorado_migrate_authors( $args, $assoc_args ) {
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migrated_co_authors',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 380384,
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
				// 'p'              => 380384,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$co_authors_ids  = [];
			$co_author_names = get_post_meta( $post->ID, 'sfly_guest_author_names', true );
			// $co_author_link        = urldecode( get_post_meta( $post->ID, 'sfly_guest_link', true ) );
			$co_author_description = get_post_meta( $post->ID, 'sfly_guest_author_description', true );
			$co_author_email       = get_post_meta( $post->ID, 'sfly_guest_author_email', true );

			// Split names.
			$co_author_names_list_fixed = [];
			$co_author_names_list       = explode( ' and ', $co_author_names );

			foreach ( $co_author_names_list as $co_author_name ) {
				$lower_cased_co_author_name = strtolower( $co_author_name );
				if (
					str_contains( $co_author_name, ',' )
					&& ! str_contains( $lower_cased_co_author_name, 'associated press' )
					&& ! str_contains( $lower_cased_co_author_name, 'writer' )
					) {
						$co_author_names_list_fixed = array_merge( $co_author_names_list_fixed, explode( ',', $co_author_name ) );
				} else {
					$co_author_names_list_fixed[] = $co_author_name;
				}
			}

			$co_author_names_list_fixed = array_filter( array_map( 'trim', $co_author_names_list_fixed ) );

			foreach ( $co_author_names_list_fixed as $co_author_name ) {
				$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
					[
						'display_name' => $co_author_name,
						'user_email'   => $co_author_email,
						'description'  => $co_author_description,
					]
				);
				if ( is_wp_error( $guest_author_id ) ) {
					$this->log( self::CO_AUTHORS_LOGS, sprintf( 'Could not create GA for post %d with display name: %s', $post->ID, $co_author_name ) );
				} else {
					$co_authors_ids[] = $guest_author_id;
				}
			}

			// Assign co-atuhors to the post in question.
			if ( ! empty( $co_authors_ids ) ) {
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_authors_ids, $post->ID );
				$this->log( self::CO_AUTHORS_LOGS, sprintf( 'Adding co-authors to the post %d: %s', $post->ID, join( ', ', $co_author_names_list_fixed ) ) );
			}

			update_post_meta( $post->ID, '_newspack_migrated_co_authors', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator sentinel-colorado-migrate-bylines`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function sentinel_colorado_migrate_bylines( $args, $assoc_args ) {
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migrated_bylines',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 380384,
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
				// 'p'              => 380384,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$byline = get_post_meta( $post->ID, 'author_byline_field', true );

			if ( empty( $byline ) ) {
				continue;
			}

			// Clean byline.
			$byline = trim( ltrim( $byline, 'BY' ) );

			$guest_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $byline ] );
			if ( is_wp_error( $guest_author_id ) ) {
				$this->log( self::CO_AUTHORS_LOGS, sprintf( 'Could not create GA for post %d with display name: %s', $post->ID, $byline ) );
				continue;
			}

			// Assign co-atuhors to the post in question.
			if ( ! empty( $guest_author_id ) ) {
				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post->ID );
				$this->log( self::CO_AUTHORS_LOGS, sprintf( 'Adding co-authors to the post %d: %s', $post->ID, $byline ) );
			}

			update_post_meta( $post->ID, '_newspack_migrated_bylines', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator sentinel-colorado-migrate-accordion`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function sentinel_colorado_migrate_accordion( $args, $assoc_args ) {
		global $wpdb;

		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migrated_accordion',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 570892,
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
				// 'p'              => 570892,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$content_blocks          = parse_blocks( $post->post_content );
			$migrated_content_blocks = [];

			// die();
			foreach ( $content_blocks as $block ) {
				if ( 'core/shortcode' === $block['blockName'] && str_contains( $block['innerHTML'], '[accordions' ) ) {
					$matches_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( 'accordions', $block['innerHTML'] );
					if ( empty( $matches_shortcodes[0] ) ) {
						continue;
					}

					foreach ( $matches_shortcodes as $match_shortcode ) {
						$shortcode_html = str_replace( [ '&#8221;', '&#8243;' ], '"', $match_shortcode[0] );
						$accordion_id   = $this->squarebracketselement_manipulator->get_attribute_value( 'id', $shortcode_html );
						if ( empty( $accordion_id ) ) {
							continue;
						}

						$accordion_options = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $accordion_id, 'accordions_options' ) );

						$accordion_options = preg_replace_callback(
							'!s:\d+:"(.*?)";!s',
							function( $m ) {
								return 's:' . strlen( $m[1] ) . ':"' . $m[1] . '";';
							},
							$accordion_options
						);

						$accordion_options = unserialize( $accordion_options );

						if ( ! $accordion_options ) {
							continue;
						}

						foreach ( $accordion_options['content'] as $accordion_content ) {
							$migrated_content_blocks[] = $this->gutenberg_block_generator->get_accordion( $accordion_content['header'], nl2br( $accordion_content['body'] ), true );
						}
					}
				} else {
					$migrated_content_blocks[] = $block;
				}
			}

			$new_content = serialize_blocks( $migrated_content_blocks );

			if ( $new_content !== $post->post_content ) {
				$result = wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $new_content,
					],
					true
				);
				if ( is_wp_error( $result ) ) {
					$this->log( self::ACCORDION_LOGS, 'Failed to update post: ' . $post->ID );
				} else {
					update_post_meta( $post->ID, '_newspack_migrated_accordion', true );
					$this->log( self::ACCORDION_LOGS, 'Updated post: ' . $post->ID );
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
