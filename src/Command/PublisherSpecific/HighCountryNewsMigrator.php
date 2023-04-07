<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
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

		// Need to import Authors/Users
		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-users-from-json',
			[ $this, 'cmd_migrate_users_from_json' ],
			[
				'shortdesc' => 'Migrate users from JSON data.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
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

	/**
	 * Function to process users from a Plone JSON users file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_users_from_json( $args, $assoc_args ) {
		$file_path = $args[0];
		$start = $assoc_args['start'] ?? 0;
		$end = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
		                                       ->set_start( $start )
		                                       ->set_end( $end )
		                                       ->getIterator();

		foreach ( $iterator as $row_number => $row ) {
			WP_CLI::log( 'Row Number: ' . $row_number . ' - ' . $row['username'] );

			$date_created = new DateTime( 'now', new DateTimeZone( 'America/Denver' ) );

			if ( ! empty( $row['date_created'] ) ) {
				$date_created = DateTime::createFromFormat( 'm-d-Y_H:i', $row['date_created'], new DateTimeZone( 'America/Denver' ) );
			}

			$result = wp_insert_user(
				[
					'user_login'      => $row['username'],
					'user_pass'       => wp_generate_password(),
					'user_email'      => $row['email'],
					'display_name'    => $row['fullname'],
					'first_name'      => $row['first_name'],
					'last_name'       => $row['last_name'],
					'user_registered' => $date_created->format( 'Y-m-d H:i:s' ),
					'role'            => 'subscriber',
				]
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::log( $result->get_error_message() );
			} else {
				WP_CLI::success( "User {$row['email']} created." );
			}
		}
	}
