<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

class HighCountryNewsMigrator implements InterfaceCommand {

	/**
	 * HighCountryNewsMigrator Instance.
	 *
	 * @var HighCountryNewsMigrator
	 */
	private static $instance;

	/**
	 * Get Instance.
	 *
	 * @return HighCountryNewsMigrator
	 */
	public static function get_instance(): HighCountryNewsMigrator {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-authors-from-scrape',
			[ $this, 'cmd_migrate_authors_from_scrape' ],
			[
				'shortdesc' => 'Authors will not be properly linked after importing XMLs. This script will set authors based on saved postmeta.',
			]
		);
	}

	public function cmd_migrate_authors_from_scrape() {
		$last_processed_post_id = PHP_INT_MAX;

		if ( file_exists( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt' ) ) {
			$last_processed_post_id = (int) file_get_contents( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt' );
		}

		global $wpdb;

		$posts_and_authors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = 'plone_author' AND post_id < %d ORDER BY post_id DESC",
				$last_processed_post_id
			)
		);

		foreach ( $posts_and_authors as $record ) {
			WP_CLI::log( "Processing post ID {$record->post_id} ($record->meta_value)..." );
			$author_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->users WHERE display_name = %s",
					$record->meta_value
				)
			);

			if ( $author_id ) {
				WP_CLI::log( "Author ID: $author_id" );
				$wpdb->update(
					$wpdb->posts,
					[ 'post_author' => $author_id ],
					[ 'ID' => $record->post_id ]
				);
			} else {
				WP_CLI::log( "Author not found." );
			}

			file_put_contents( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt', $record->post_id );
		}
	}
}