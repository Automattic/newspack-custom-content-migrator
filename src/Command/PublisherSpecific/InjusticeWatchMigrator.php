<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use NewspackCustomContentMigrator\Utils\CsvIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;
use WP_CLI\ExitException;

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
			'newspack-content-migrator ijw-adjust-categories',
			[ $this, 'cmd_adjust_categories' ],
			[
				'shortdesc' => 'Adjust categories.',
				'synopsis'  => [
					$this->csv_input_file,
					...BatchLogic::get_batch_args(),
				],
			]
		);
	}


	/**
	 * @throws Exception
	 */
	public function cmd_adjust_categories( array $pos_args, array $assoc_args ): void { // TODO. And tags
		$csv_file_path = $assoc_args[ $this->csv_input_file['name'] ];

		$batch_args = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );
		$counter    = 0;
		foreach ( $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] ) as $row ) {
			++ $counter;
			WP_CLI::log( sprintf( 'Processing row %d of %d with post ID %s', $counter, $batch_args['total'], $row['id'] ) );
			$post_id = (int) $row['id'];

			if ( ! empty( $row['Tags-NEW'] ) ) {
				$new_tag_names = array_map( function ( $tag_name ) {
					if ( ! term_exists( $tag_name, 'category' ) ) {
						wp_create_category( $tag_name );
					}

					return $tag_name;

				}, array_map( 'trim', explode( '|', $row['Categories-NEW'] ) ) );
				$new_tag_ids   = array_map( fn( $tag_name ) => get_term_by( 'name', $tag_name, 'post_tag' )->term_id, $new_tag_names );
				wp_set_post_tags( $post_id, $new_tag_ids );
			}

			if ( ! empty( $row['Categories-NEW'] ) ) {
				// Get path for redirect before we start tampering with the category.
				$current_path = trim( parse_url( get_permalink( $post_id ), PHP_URL_PATH ), '/' );

				$cat_parent = 0;
				// TODO. De kan have flere kategorier, så vi skal først splitte på | og så splitte på > og så oprette dem alle sammen.
				$new_cat_names = array_map( function ( $cat_name ) use ($cat_parent) {
					if ( ! term_exists( $cat_name, 'category' ) ) {
						$cat_parent = wp_create_category( $cat_name, $cat_parent );
					}

					return $cat_name;

				}, array_map( 'trim', explode( '>', $row['Categories-NEW'] ) ) );
				$new_cat_ids   = array_map( fn( $cat_name ) => get_term_by( 'name', $cat_name, 'category' )->term_id, $new_cat_names );

				wp_set_post_categories( $post_id, $new_cat_ids );
				// Use the first of the categories as the primary category.
				update_post_meta( $post_id, '_yoast_wpseo_primary_category', $new_cat_ids[0] );
				$this->logger->log( 'category_reshuffle.log', sprintf( 'Updated categories to %s', implode( ',', $new_cat_names ) ), Logger::SUCCESS );

				// Do we need a redirect?
				if ( str_starts_with( $current_path, mb_strtolower( $new_cat_names[0] ) ) || str_contains( $current_path, "?p=$post_id" ) ) {
					continue;
				}

				// Create redirect for the old category path if it doesn't exist.
				if ( ! $this->redirection->redirect_from_exists( '/' . $current_path ) ) {
					$this->redirection->create_redirection_rule(
						'Redirect for changed category',
						$current_path,
						"/?p=$post_id",
					);
					$this->logger->log( 'category_redirects.log', sprintf( 'Created a redirect from %s to %s', $current_path, get_permalink( $post_id ) ), Logger::SUCCESS );
				}
			}

		}
	}


}
