<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Search Light New Mexico.
 */
class BGAMigrator implements InterfaceMigrator {
	// Logs.
	const EVENTS_MIGRATION_LOG = 'bga-events-migration.log';

	// Output filenames.
	const VENUES_CSV_FILENAME = 'bga-venues.csv';
	const EVENTS_CSV_FILENAME = 'bga-events.csv';

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachment_logic;

	/**
	 * @var Crawler
	 */
	private $crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachment_logic = new AttachmentsLogic();
		$this->crawler          = new Crawler();
	}

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator bga-generate-events-csv',
			[ $this, 'bga_generate_events_csv' ],
			[
				'shortdesc' => 'Generate Events CSV from an events array exported from Drupal to TEC.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'events-php-array-filepath',
						'description' => 'PHP file that contains $bga_raw_events variable with array events exported from the old Drupal backend.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-event-ids-filepath',
						'description' => 'File containing event IDs to skip, ID per line.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator bga-generate-events-csv`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function bga_generate_events_csv( $args, $assoc_args ) {
		$events_php_array_file = $assoc_args['events-php-array-filepath'] ?? null;
		$skip_event_ids_file   = $assoc_args['skip-event-ids-filepath'] ?? null;

		if ( ! file_exists( $events_php_array_file ) ) {
			WP_CLI::error( sprintf( 'Events PHP file export %s not found.', $events_php_array_file ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$event_ids_to_skip = $skip_event_ids_file && file_exists( $skip_event_ids_file ) ? file( $skip_event_ids_file, FILE_IGNORE_NEW_LINES ) : [];

		require_once $events_php_array_file;

		if ( ! isset( $bga_raw_events ) ) {
			WP_CLI::error( sprintf( 'Please set the $bga_raw_events array in the events PHP file %s.', $events_php_array_file ) );
		}

		if ( ! isset( $bga_events_categories ) ) {
			WP_CLI::error( sprintf( 'Please set the $bga_events_categories array in the events PHP file %s.', $events_php_array_file ) );
		}

		$venues = [];
		$events = [];

		$data = [];
		foreach ( $bga_raw_events as $event ) {
			if ( in_array( $event->nid, $event_ids_to_skip ) ) {
				$this->log( self::EVENTS_MIGRATION_LOG, sprintf( 'Skipping migrating event %d, as it was already migrated.', $bga_raw_event->nid ) );
				continue;
			}

			// Venue.
			$venue = $this->get_event_venue( $event->field_event_location );

			if ( ! empty( $venue ) && ! in_array( $venue, $venues, true ) ) {
				$venues[] = $venue;
			}

			// if ( empty( $event->field_event_categories ) ) {
			// continue;
			// }

			// $data[] = $event->nid;
			// $data[] = $this->get_event_categories( $event->field_event_categories, $bga_events_categories );

			// Set events data.
			$events[] = [
				'EVENT NAME'           => $event->title,
				'EVENT VENUE NAME'     => $venue,
				'EVENT START DATE'     => gmdate( 'Y-m-d', strtotime( rtrim( $event->field_event_date['und'][0]['value'], ' 00:00:00' ) ) ),
				'EVENT START TIME'     => $event->field_time['und'][0]['value'],
				'ALL DAY EVENT'        => false,
				'TIMEZONE'             => $event->field_event_date['und'][0]['timezone_db'],
				'EVENT FEATURED IMAGE' => $this->get_event_featured_image( $event->field_event_image ),
				'EVENT WEBSITE'        => empty( $event->field_event_link ) ? '' : $event->field_event_link['und'][0]['url'],
				'EVENT DESCRIPTION'    => $this->get_event_body( $event->body ),
				'EVENT EXCERPT'        => empty( $event->field_teaser ) ? '' : $event->field_teaser['und'][0]['value'],
				'EVENT CATEGORY'       => $this->get_event_categories( $event->field_event_categories, $bga_events_categories ),
			];

			if ( $skip_event_ids_file ) {
				// phpcs:ignore: WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				file_put_contents( $skip_event_ids_file, $bga_raw_event->nid . PHP_EOL, FILE_APPEND );
			}
		}

		if ( ! empty( $events ) ) {
			$this->save_CSV(
				self::VENUES_CSV_FILENAME,
				array_map(
					function( $d ) {
						return [ 'Venue Name' => $d ];
					},
					$venues
				)
			);

			$this->save_CSV( self::EVENTS_CSV_FILENAME, $events );

			$this->log( self::EVENTS_MIGRATION_LOG, sprintf( 'The XML content was successfully migrated to the files %s and %s', self::VENUES_CSV_FILENAME, self::EVENTS_CSV_FILENAME ) );
		} else {
			$this->log( self::EVENTS_MIGRATION_LOG, 'There are no events to import!' );
		}
	}

	/**
	 * Get venue address from event data.
	 *
	 * @param mixed[] $event Event's data.
	 * @return string
	 */
	private function get_event_venue( $field_event_location ) {
		$data = [
			$field_event_location['und'][0]['organisation_name'],
			$field_event_location['und'][0]['thoroughfare'],
			$field_event_location['und'][0]['locality'],
			$field_event_location['und'][0]['postal_code'],
		];

		return join( ', ', array_filter( $data ) );
	}

	/**
	 * Import body images as attachments
	 *
	 * @param mixed $event_body Event body details.
	 * @return string
	 */
	private function get_event_body( $event_body ) {
		$raw_content = empty( $event_body ) ? '' : $event_body['und'][0]['value'];

		// Set all post images from the post body as attachments.
		$content = $raw_content;
		$this->crawler->clear();
		$this->crawler->add( $raw_content );
		$images = $this->crawler->filterXpath( '//img' )->extract( array( 'src', 'title', 'alt' ) );

		foreach ( $images as $image ) {
			$img_src   = $image[0] ?? null;
			$img_title = $image[1] ?? null;
			$img_alt   = $image[2] ?? null;
			if ( $img_src ) {
				// Check if there's already an attachment with this image.
				// Sometimes the src is something like: /sites/default/files/articles/2021/07/Miracle.png.
				$img_url = str_starts_with( $img_src, 'http' ) ? $img_src : 'https://www.bettergov.org/' . $img_src;

				$this->log( self::EVENTS_MIGRATION_LOG, sprintf( 'Importing event attachment: %s', $img_url ) );
				$attachment_id = $this->attachment_logic->import_external_file( $img_url, $img_title, $img_title, $img_alt );
				if ( $attachment_id ) {
					$content = str_replace( $img_src, wp_get_attachment_url( $attachment_id ), $content );
				}
			}
		}

		return $content;
	}

	/**
	 * Get categories titles in a comma separated string.
	 *
	 * @param mixed $category_ids List of category IDs.
	 * @param array $categories Mapped categories ID => Title.
	 * @return string
	 */
	private function get_event_categories( $category_ids, $categories ) {
		if ( ! array_key_exists( 'und', $category_ids ) ) {
			return '';
		}
		$categories = array_map(
            function( $category_id ) use ( $categories ) {
				return array_key_exists( $category_id['tid'], $categories ) ? $categories[ $category_id['tid'] ] : '';
			},
			$category_ids['und']
        );

		return join( ',', $categories );
	}

	/**
	 * Import and generate featured event image if exists.
	 *
	 * @param mixed[] $image Featured event image data.
	 * @return string|null Featured attachment ID if imported, null otherwise.
	 */
	private function get_event_featured_image( $image ) {
		if ( isset( $image['und'] ) && isset( $image['und'][0] ) && isset( $image['und'][0]['uri'] ) ) {
			$image_url               = str_replace( 'public://event-images', 'https://www.bettergov.org/sites/default/files/event-images', $image['und'][0]['uri'] );
			$existing_featured_image = $this->get_post_by_meta( '_newspack_imported_from_url', $image_url, 'attachment' );
			$image_media_id          = $existing_featured_image ? $existing_featured_image->ID : $this->attachment_logic->import_external_file( $image_url );

			update_post_meta( $image_media_id, '_newspack_imported_from_url', $image_url );
			return wp_get_attachment_url( $image_media_id );
		}

		return null;
	}

	/**
	 * Get one post by meta value.
	 *
	 * @param string      $meta_key Meta Key.
	 * @param string      $meta_value Meta value.
	 * @param string|null $post_type Post_type.
	 * @return WP_Post|null
	 */
	private function get_post_by_meta( $meta_key, $meta_value, $post_type = 'post' ) {
		$query = new \WP_Query(
            [
				'post_type'   => $post_type,
				'post_status' => [ 'publish', 'inherit' ],
				'meta_query'  => [ ['key' => $meta_key, 'value' => $meta_value, 'compare' => '='] ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
        );
		$posts = $query->get_posts();
		return ( count( $posts ) > 0 ) ? current( $posts ) : null;
	}

	/**
	 * Save array of data to a CSV file.
	 *
	 * @param string $output_file Filepath where the save the CSV.
	 * @param mixed  $data Data to save as a CSV.
	 * @return void
	 */
	private function save_CSV( $output_file, $data ) {
		$csv_output_file = fopen( $output_file, 'w' );
		fputcsv( $csv_output_file, array_keys( $data[0] ) );
		foreach ( $data as $datum ) {
			fputcsv( $csv_output_file, $datum );
		}

		fclose( $csv_output_file );
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
