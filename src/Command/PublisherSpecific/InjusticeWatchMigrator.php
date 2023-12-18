<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use NewspackCustomContentMigrator\Utils\CsvIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

class InjusticeWatchMigrator implements InterfaceCommand {
	private array $csv_input_file = [
		'type'        => 'assoc',
		'name'        => 'csv-input-file',
		'description' => 'Path to CSV input file.',
		'optional'    => false,
	];

	private CsvIterator $csv_iterator;
	private Redirection $redirection;

	private Logger $logger;

	private function __construct() {
		$this->csv_iterator = new CsvIterator();
		$this->redirection  = new Redirection();
		$this->logger       = new Logger();
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

		$this->register_test_commands();
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
			$path = untrailingslashit( parse_url( $row['Permalink'], PHP_URL_PATH ) );

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
		$iterator = ( new FileImportFactory() )->get_file( $csv_file_path )
		                                       ->set_start( $batch_args['start'] )
		                                       ->set_end( $batch_args['end'] )
		                                       ->getIterator();
		$counter = 0;
		foreach ( $iterator as $row ) {
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
