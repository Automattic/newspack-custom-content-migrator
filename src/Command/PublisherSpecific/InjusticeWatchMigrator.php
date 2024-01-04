<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\GutenbergBlockManipulator;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use NewspackCustomContentMigrator\Utils\CsvIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;
use WP_Post;

class InjusticeWatchMigrator implements InterfaceCommand {
	private array $csv_input_file = [
		'type'        => 'assoc',
		'name'        => 'csv-input-file',
		'description' => 'Path to CSV input file.',
		'optional'    => false,
	];

	private CsvIterator $csv_iterator;
	private Redirection $redirection;
	private GutenbergBlockGenerator $block_generator;

	private Logger $logger;

	private function __construct() {
		$this->csv_iterator    = new CsvIterator();
		$this->redirection     = new Redirection();
		$this->logger          = new Logger();
		$this->block_generator = new GutenbergBlockGenerator();
	}

	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @throws Exception
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator ijw-adjust-tags-and-categories',
			[ $this, 'cmd_adjust_tags_and_categories' ],
			[
				'shortdesc' => 'Adjust categories and tags.',
				'synopsis'  => [
					$this->csv_input_file,
					...BatchLogic::get_batch_args(),
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator ijw-fix-permalinks',
			[ $this, 'cmd_fix_permalinks' ],
			[
				'shortdesc' => 'Fix permalinks after adjusting tags and categories.',
				'synopsis'  => [
					$this->csv_input_file,
					...BatchLogic::get_batch_args(),
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator ijw-transform-readmore',
			[ $this, 'cmd_transform_readmore' ],
			[
				'shortdesc' => 'Transform readmore shortcodes.',
				'synopsis'  => [
					BatchLogic::$num_items,
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'Specific post id to process',
						'optional'    => true,
					],
				],
			]
		);

		$this->register_test_commands();
	}

	public function cmd_transform_readmore( array $pos_args, array $assoc_args ): void {
		$post_id = $assoc_args['post-id'] ?? false;
		$log_file = 'readmore-blocks.log';

		$homepage_block_default_args = [
			'showReadMore'  => false,
			'showDate'      => false,
			'showAuthor'    => false,
			'specificMode'  => true,
			'typeScale'     => 3,
			'columns'       => 2,
			'sectionHeader' => 'Read More',
			'postsToShow'   => 1,
			'showExcerpt'   => false,
			'imageShape'    => 'uncropped',
		];

		if ( ! $post_id ) {
			$num_items = $assoc_args['num-items'] ?? PHP_INT_MAX;
			global $wpdb;
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_content LIKE '%[readmore%' ORDER BY ID DESC LIMIT %d",
					[ $num_items ]
				)
			);
		} else {
			$post_ids = [ $post_id ];
		}
		$total_posts = count( $post_ids );
		$counter     = 0;

		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( '-- %d/%d --', ++ $counter, $total_posts ) );
			$post           = get_post( $post_id );
			$post_permalink = get_permalink( $post );

			// Parse the post content into blocks.
			$post_blocks      = parse_blocks( $post->post_content );
			$shortcode_blocks = array_filter(
				$post_blocks,
				fn( $block ) => 'core/shortcode' === ( $block['blockName'] ?? '' ) && str_contains( $block['innerHTML'], '[readmore' )
			);
			if ( empty( $shortcode_blocks ) ) {
				$this->logger->log( $log_file, sprintf( 'Post has shortcode outside of block: %s', $post_permalink ), Logger::ERROR );
				continue;
			}

			// Array filter keeps the indices, so grab $idx here, so we can replace blocks.
			foreach ( $shortcode_blocks as $idx => $block ) {
				// The shortcode is a bit of a mess with quotes, newlines, and all kinds of inconsistencies
				$parsed_attrs = array_map(
					fn( $attr ) => trim( $attr, "\n“”" ),
					shortcode_parse_atts( html_entity_decode( $block['innerHTML'] ) )
				);
				if ( empty( $parsed_attrs['id'] ) ) {
					$this->logger->log( $log_file, sprintf( 'Could not parse attributes on %s', $post_permalink ), Logger::ERROR );
					continue;
				}

				// There can be one post only, or multiple, separated by commas.
				$referenced_post_ids = str_contains( $parsed_attrs['id'], ',' ) ? explode( ',', $parsed_attrs['id'] ) : [ $parsed_attrs['id'] ];
				// They are pretty probably wrapped in all kinds of fluff that is not a post id.
				$referenced_post_ids = array_map( fn( $ref_post_id ) => preg_replace( '/[^0-9]/', '', $ref_post_id ), $referenced_post_ids );
				// And we want to make sure that we actually have posts with those ids.
				$referenced_post_ids = array_filter(
					$referenced_post_ids,
					fn( $ref_post_id ) => get_post( $ref_post_id ) instanceof WP_Post
				);
				if ( empty( $referenced_post_ids ) ) {
					$this->logger->log( $log_file, sprintf( 'No valid post id references found for %s', $post_permalink ), Logger::ERROR );
					continue;
				}

				$group_classes = [ 'ijw-readmore-inline' ];
				$align         = 'left';
				if ( ! empty( $parsed_attrs['float'] ) && str_contains( $parsed_attrs['float'], 'right' ) ) {
					$align = 'right';
				}
				$group_classes[] = 'align' . $align;

				$block_args                = $homepage_block_default_args;
				$block_args['showExcerpt'] = ! empty( $parsed_attrs['excerpt'] ) && str_contains( $parsed_attrs['excerpt'], 'true' );

				$homepage_block = $this->block_generator->get_homepage_articles_for_specific_posts( $referenced_post_ids, $block_args );

				$post_blocks[ $idx ] = $this->block_generator->get_group_constrained( [ $homepage_block ], $group_classes );
			}
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => serialize_blocks( $post_blocks ),
				]
			);
			$this->logger->log(
				$log_file,
				sprintf(
					"Added read more block to:\n\t%s\n\t%s",
					$post_permalink, 'https://injusticewatch.org' . parse_url( $post_permalink, PHP_URL_PATH )
				),
				Logger::SUCCESS
			);
		}
		WP_CLI::success( 'Done transforming readmores. Log file in ' . $log_file );
	}

	/**
	 * @throws Exception
	 */
	private function register_test_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator ijw-test-permalinks',
			[ $this, 'test_permalinks' ],
			[
				'shortdesc' => 'Test that all permalinks in CSV respond properly.',
				'synopsis'  => [
					$this->csv_input_file,
				],
			]
		);
	}

	/**
	 * @throws Exception
	 */
	public function test_permalinks( array $pos_args, array $assoc_args ): void {
		$csv_file_path = $assoc_args[ $this->csv_input_file['name'] ];

		$home_url = untrailingslashit( parse_url( home_url(), PHP_URL_SCHEME | PHP_URL_HOST ) );

		foreach ( $this->csv_iterator->items( $csv_file_path, ',' ) as $row ) {
			if ( empty( $row['Categories-NEW'] ) ) { // Permalink should not have changed.
				continue;
			}

			if ( empty( $row['Permalink'] ) || preg_match( '/\?p=\d+$/', $row['Permalink'] ) ) {
				// If the link is empty or is a default canonical url, skip it.
				continue;
			}
			if ( ! wp_http_validate_url( $row['Permalink'] ) ) {
				$this->logger->log( 'invalid_permalinks.log', sprintf( 'Invalid permalink: %s', $row['Permalink'] ), Logger::ERROR );
				continue;
			}

			$live_url = parse_url( $row['Permalink'], PHP_URL_SCHEME | PHP_URL_HOST );
			$post_url = str_replace( $live_url, $home_url, $row['Permalink'] );
			$path     = untrailingslashit( parse_url( $row['Permalink'], PHP_URL_PATH ) );

			if ( ! $this->redirection->redirect_from_exists( $path ) ) {
				$req = wp_remote_head( $post_url, [ 'sslverify' => false ] );
				if ( is_wp_error( $req ) || 404 === $req['response']['code'] ) {
					$this->logger->log( 'missing_redirects.log', sprintf( 'URL not redirecting: %s', $row['Permalink'] ), Logger::ERROR );
					continue;
				}
				WP_CLI::log( sprintf( 'Url responds with 200 OK: %s', $post_url ) );
			} else {
				WP_CLI::log( sprintf( 'Redirect works! %s', $post_url ) );
			}


		}
	}

	/**
	 * @throws Exception
	 */
	public function cmd_adjust_tags_and_categories( array $pos_args, array $assoc_args ): void {
		$csv_file_path = $assoc_args[ $this->csv_input_file['name'] ];

		$batch_args = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );
		$counter    = 0;
		foreach ( $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] ) as $row ) {
			++ $counter;
			$post_id   = (int) $row['id'];
			$post_path = get_permalink( $post_id );
			WP_CLI::log( sprintf( 'Processing row %d of %d:  %s', $counter, $batch_args['total'], $post_path ) );

			if ( ! empty( $row['Tags-NEW'] ) ) {
				$new_tag_names = array_map( function ( $tag_name ) {
					if ( ! term_exists( $tag_name, 'post_tag' ) ) {
						wp_create_tag( $tag_name );
					}

					return $tag_name;

				}, array_map( 'trim', explode( '|', $row['Tags-NEW'] ) ) );
				$new_tag_ids   = array_map( fn( $tag_name ) => get_term_by( 'name', $tag_name, 'post_tag' )->term_id, $new_tag_names );
				wp_set_post_tags( $post_id, $new_tag_ids );
				$this->logger->log( 'tag_reshuffle.log', sprintf( 'Updated tags on %s to %s', get_category( $post_id ), implode( ',', $new_tag_names ) ), Logger::SUCCESS );
			}

			if ( ! empty( $row['Categories-NEW'] ) ) {
				$new_cats = [];
				foreach ( array_map( 'trim', explode( '|', $row['Categories-NEW'] ) ) as $cat ) {
					WP_CLI::log( $cat );
					$cat_parent = 0;
					foreach ( array_map( 'trim', explode( '>', $cat ) ) as $cat_name ) {
						$category = get_term_by( 'name', $cat_name, 'category' );
						if ( ! $category ) {
							$category = get_category( wp_create_category( $cat_name, $cat_parent ) );
						}
						if ( $category->parent !== $cat_parent ) {
							wp_update_term( $category->term_id, 'category', [
								'parent' => $cat_parent
							] );

						}
						$cat_parent = $category->term_id;
						$new_cats[] = $category;
					}

				}

				$new_cat_ids   = array_map( fn( $category ) => $category->term_id, $new_cats );
				$new_cat_names = array_map( fn( $category ) => $category->name, $new_cats );

				wp_set_post_categories( $post_id, $new_cat_ids );
				// Use the first of the categories as the primary category. TODO. We still don't know how they's like to denote the primary category, so come back to this.
				update_post_meta( $post_id, '_yoast_wpseo_primary_category', $new_cat_ids[0] );
				$this->logger->log( 'category_reshuffle.log', sprintf( 'Updated categories on %s to %s', get_category( $post_id ), implode( ',', $new_cat_names ) ),
					Logger::SUCCESS );
			}
		}
	}

	/**
	 * @throws Exception
	 */
	public function cmd_fix_permalinks( array $pos_args, array $assoc_args ): void {
		$csv_file_path = $assoc_args[ $this->csv_input_file['name'] ];

		$batch_args = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );
		$counter    = 0;
		foreach ( $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] ) as $row ) {
			++ $counter;
			$post_id       = (int) $row['id'];
			$csv_permalink = trim( parse_url( $row['Permalink'], PHP_URL_PATH ), '/' );
			$post_path     = trim( parse_url( get_permalink( $post_id ), PHP_URL_PATH ), '/' );

			WP_CLI::log( sprintf( 'Processing row %d of %d:  %s', $counter, $batch_args['total'], $post_path ) );

			// Do we need a redirect?
			if ( $post_path === $csv_permalink || $this->redirection->redirect_from_exists( '/' . $csv_permalink ) ) {
				continue;
			}

			$this->redirection->create_redirection_rule(
				'Redirect for changed category',
				$csv_permalink,
				"/?p=$post_id",
			);
			$this->logger->log( 'category_redirects.log', sprintf( 'Created a redirect from %s to %s', $post_path, get_permalink( $post_id ) ), Logger::SUCCESS );
		}
	}

}
