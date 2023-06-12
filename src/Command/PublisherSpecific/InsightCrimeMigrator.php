<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

/**
 * Custom migration scripts for Ithaca Voice.
 */
class InsightCrimeMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * CoAuthorPlus logic.
	 *
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * Logger.
	 *
	 * @var Logger $logger Logger instance.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic       = new CoAuthorPlus();
		$this->logger                    = new Logger();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
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
			'newspack-content-migrator insight-crime-migrate-acf-by-lines',
			[ $this, 'cmd_migrate_acf_bylines' ],
			[
				'shortdesc' => 'Migrate Bylines added via ACF into users and guest authors',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator insight-crime-events',
			[ $this, 'cmd_insight_crime_events' ],
			[
				'shortdesc' => 'Migrates Insight Crime events from topics to tags.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'output-folder-path',
						'description' => 'Output folder path.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator insight-crime-migrate-acf-modified-date',
			[ $this, 'cmd_migrate_acf_modified_date' ],
			[
				'shortdesc' => 'Migrate modified date added via ACF.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'force',
						'description' => 'Force running the changes.',
						'optional'    => true,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator insight-crime-migrate-investigation-content',
			[ $this, 'cmd_migrate_investigation_content' ],
			[
				'shortdesc' => 'Migrate investigation content added via ACF.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'investigations-category-id',
						'description' => 'Investigations category ID.',
						'optional'    => false,
					],
				],
			]
		);
	}

	/**
	 * Migrate Bylines added via ACF into users and guest authors
	 *
	 * @param array $args CLI arguments.
	 * @param array $assoc_args CLI assoc arguments.
	 * @return void
	 */
	public function cmd_migrate_acf_bylines( $args, $assoc_args ) {
		global $wpdb, $coauthors_plus;

		$dry_run = isset( $assoc_args['dry-run'] ) && $assoc_args['dry-run'];

		if ( ! $dry_run ) {
			WP_CLI::line( 'This command will modify the database.' );
			WP_CLI::line( 'Consider running it with --dry-run first to see what it will do.' );
			WP_CLI::confirm( 'Are you sure you want to continue?', $assoc_args );
		}

		if ( ! $this->coauthorsplus_logic->is_coauthors_active() ) {
			WP_CLI::error( 'Co-Authors Plus plugin is not active.' );
		}

		$query = "select post_id, meta_value from $wpdb->postmeta where meta_key = '_created_by_alias' and meta_value <> '' and meta_value NOT LIKE '--%' and post_id IN ( SELECT ID FROM $wpdb->posts where post_type = 'post' and post_status = 'publish' )";

		$metas = $wpdb->get_results( $query );

		$author_names = [];

		$replacements = [
			'*'      => '',
			', and ' => '===',
			', '     => '===',
			'--'     => '',
			' Y '    => '===',
			' AND '  => '===',
			' And '  => '===',
			' and '  => '===',
			' y '    => '===',
		];

		foreach ( $metas as $meta ) {
			if ( get_post_meta( $meta->post_id, '_created_by_alias_migrated', true ) ) {
				continue;
			}

			WP_CLI::line( 'POST ID: ' . $meta->post_id );
			WP_CLI::line( 'ACF field value: ' . $meta->meta_value );
			$names = $meta->meta_value;
			foreach ( $replacements as $search => $replace ) {
				$names = str_replace( $search, $replace, $names );
			}

			$names = explode( '===', $names );
			$names = array_map(
				function( $n ) {
					return trim( $n );
				},
				$names
			);

			$author_names = array_merge( $author_names, $names );

			$coauthors = [];

			foreach ( $names as $name ) {
				WP_CLI::line( '- Processing name: ' . $name );
				$user_nicename = $wpdb->get_var( $wpdb->prepare( "SELECT user_nicename FROM $wpdb->users WHERE display_name = LOWER(%s) LIMIT 1", strtolower( trim( $name ) ) ) );
				if ( $user_nicename ) {
					WP_CLI::line( '-- Found existing user: ' . $user_nicename );
					$coauthors[] = $user_nicename;
				} else {
					$nicename = sanitize_title( $name );
					if ( $dry_run ) {
						WP_CLI::line( '-- Will create/look for Guest author: ' . $nicename );
						$coauthors[] = $nicename;
						continue;
					}
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
						[
							'display_name' => $name,
							'user_login'   => $nicename,
						]
					);
					if ( is_wp_error( $guest_author_id ) ) {
						WP_CLI::line( '-- Error creating Guest author: ' . $nicename . ' - ' . $guest_author_id->get_error_message() );
						continue;
					}
					$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );
					if ( is_object( $guest_author ) && ! empty( $guest_author->user_nicename ) ) {
						WP_CLI::line( '-- Found/Created Guest author: ' . $guest_author->user_nicename . ' (ID: ' . $guest_author->ID . ')' );
						$coauthors[] = $guest_author->user_nicename;
					}
				}
			}

			if ( ! $dry_run ) {
				$coauthors_plus->add_coauthors( $meta->post_id, $coauthors );
				update_post_meta( $meta->post_id, '_created_by_alias_migrated', 1 );
			}
		}

	}

	/**
	 * Callable for `newspack-content-migrator insight-crime-events`.
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function cmd_insight_crime_events( $args, $assoc_args ) {
		global $wpdb;
		$output_folder_path = rtrim( $assoc_args['output-folder-path'], '/' ) . '/';

		$meta_query = [
			[
				'key'     => '_newspack_migrated_event',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page'   => -1,
				'post_type'        => 'events',
				'suppress_filters' => true,
				// 'p'              => 570892,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'meta_query'       => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'        => 'events',
				'suppress_filters' => true,
				// 'p'              => 199581,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'meta_query'       => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts      = $query->get_posts();
		$venues     = [];
		$events     = [];
		$organizers = [];

		foreach ( $posts as $post ) {
			$description          = get_post_meta( $post->ID, 'description', true );
			$start_date           = get_post_meta( $post->ID, 'date', true );
			$start_time           = get_post_meta( $post->ID, 'time', true );
			$end_time             = get_post_meta( $post->ID, 'end_time', true );
			$timezone             = get_post_meta( $post->ID, 'timezone', true );
			$place                = get_post_meta( $post->ID, 'place', true );
			$external_url         = get_post_meta( $post->ID, 'external_url', true );
			$agenda_description   = trim( get_post_meta( $post->ID, 'agenda_description', true ) );
			$time_frames          = intval( get_post_meta( $post->ID, 'time_frames', true ) );
			$speakers_description = get_post_meta( $post->ID, 'speakers_description', true );
			$speakers             = intval( get_post_meta( $post->ID, 'speakers', true ) );
			// There are no sponsors for the current events.
			// $sponsor_title        = get_post_meta( $post->ID, 'sponsor_title', true );
			// $sponsors             = intval( get_post_meta( $post->ID, 'sponsors', true ) );

			// Get post language from WPML using wpdb.
			$language = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_id = %d AND element_type = %s",
					$post->ID,
					'post_events'
				)
			) ?? 'en';

			// venue.
			if ( ! empty( $place ) && ! in_array( $place, $venues, true ) ) {
				$venues[] = [ 'Venue Name' => $place ];
			}

			// Organizers/Speakers.
			if ( 0 < $speakers ) {
				for ( $i = 0; $i < $speakers; $i++ ) {
					$speaker_full_name   = get_post_meta( $post->ID, 'speakers_' . $i . '_speaker_full_name', true );
					$speaker_description = get_post_meta( $post->ID, 'speakers_' . $i . '_speaker_description', true );
					$speaker_url         = get_post_meta( $post->ID, 'speakers_' . $i . '_speaker_url', true );
					$speaker_photo       = get_post_meta( $post->ID, 'speakers_' . $i . '_speaker_photo', true );

					$organizers[] = [
						'Organizer Name'           => $speaker_full_name,
						'Organizer Description'    => $speaker_description,
						'Organizer Website'        => $speaker_url,
						'Organizer Featured Image' => $speaker_photo,
					];
				}
			}

			// dates.
			$start_date = \DateTime::createFromFormat( 'Ymd H:i:s', "$start_date $start_time" );

			// event.
			$event = [
				'EVENT NAME'       => $post->post_title,
				'EVENT EXCERPT'    => $post->post_excerpt,
				'TIMEZONE'         => $timezone,
				'EVENT VENUE NAME' => $place,
				'EVENT ORGANIZERS' => implode( ',', array_column( $organizers, 'Organizer Name' ) ),
				'EVENT START DATE' => $start_date->format( 'Y-m-d' ),
				'EVENT START TIME' => $start_date->format( 'H:i:s' ),
				'ALL DAY EVENT'    => ! $end_time,
			];

			$event['EVENT END TIME'] = $end_time;

			// content.
			$content = $description;

			// Register Button.
			if ( ! empty( $external_url ) ) {
				$content .= sprintf( '<p><a href="%s">' . ( 'es' === $language ? 'Registrar me' : 'Register' ) . '</a></p>', $external_url );
			}

			// Schedule.
			if ( ! empty( $agenda_description ) || 0 < $time_frames ) {
				$content .= sprintf( '<h3>' . ( 'es' === $language ? 'Programa' : 'Schedule' ) . '</h3><p>%s</p>', $agenda_description );
				if ( 0 < $time_frames ) {
					$content .= '<ul>';
					for ( $i = 0; $i < $time_frames; $i++ ) {
						$time_frame_label       = get_post_meta( $post->ID, 'time_frames_' . $i . '_label', true );
						$time_frame_description = get_post_meta( $post->ID, 'time_frames_' . $i . '_time_description', true );
						$content               .= sprintf( '<li><strong>%s</strong> – %s</li>', $time_frame_label, $time_frame_description );
					}
					$content .= '</ul>';
				}
			}

			$event['EVENT DESCRIPTION'] = $content;

			// featured image.
			$event['EVENT FEATURED IMAGE'] = get_post_thumbnail_id( $post->ID );

			// tags.
			$post_tags = wp_get_post_tags( $post->ID );
			if ( ! empty( $post_tags ) ) {
				$event['EVENT TAGS'] = implode( ',', wp_list_pluck( $post_tags, 'name' ) );
			}

			$events[] = $event;

			update_post_meta( $post->ID, '_newspack_migrated_event', true );
		}

		if ( ! empty( $events ) ) {
			$this->save_CSV( $output_folder_path . 'venues-' . strtotime( 'now' ) . '.csv', $venues );
			$this->save_CSV( $output_folder_path . 'events-' . strtotime( 'now' ) . '.csv', $events );
			$this->save_CSV( $output_folder_path . 'organizers-' . strtotime( 'now' ) . '.csv', $organizers );

			WP_CLI::line( sprintf( 'The XML content was successfully migrated to the folder: %s', $output_folder_path ) );
		} else {
			WP_CLI::line( 'There are no events to import!' );
		}
	}

	/**
	 * Callable for `newspack-content-migrator insight-crime-migrate-acf-modified-date`.
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function cmd_migrate_acf_modified_date( $args, $assoc_args ) {
		global $wpdb;

		$force          = isset( $assoc_args['force'] );
		$spanish_months = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];

		$meta_query = [
			[
				'key'     => '_newspack_migrated_modified_date',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page'   => -1,
				'post_type'        => 'post',
				'suppress_filters' => true,
				// 'p'              => 11,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'meta_query'       => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'        => 'post',
				'suppress_filters' => true,
				// 'p'              => 11,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'meta_query'       => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			if ( in_array( $post->ID, [ 198697, 179947, 179954, 179938, 179943, 179923, 179916, 179911, 179891, 2199, 7038, 1576, 7021, 4863, 2236, 6357, 127151, 126989, 117737, 114134, 88516, 83323, 79836, 79997, 79860 ] ) ) {
				update_post_meta( $post->ID, '_newspack_migrated_modified_date', true );
				continue;
			}

			$modified_date_meta = get_post_meta( $post->ID, 'modified_date', true );
			if ( ! empty( $modified_date_meta ) ) {
				$modified_date = gmdate( 'Y-m-d', strtotime( $modified_date_meta ) );
				if ( '1970-01-01' === $modified_date ) {
					// check if the date is in Spanish in this format 14 de febrero, 2023.
					preg_match( '/(?<day>\d{1,2}) de (?<month>[a-z]+), (?<year>\d{4})/i', $modified_date_meta, $matches );
					if ( ! empty( $matches ) ) {

						$modified_date = gmdate( 'Y-m-d', strtotime( $matches['year'] . '-' . ( array_search( $matches['month'], $spanish_months ) + 1 ) . '-' . $matches['day'] ) );
					}
				}

				if ( $force ) {
					$wpdb->update(
						$wpdb->posts,
						[
							'post_date'     => $modified_date,
							'post_date_gmt' => $modified_date,
						],
						[ 'ID' => $post->ID ]
					);
					$this->logger->log( 'post_date_migration.log', sprintf( 'Post ID %d modified date updated from %s to %s', $post->ID, $post->post_date, $modified_date ) );
				} else {
					WP_CLI::line( sprintf( '%d =====> %s =====> %s', $post->ID, $modified_date_meta, $modified_date ) );
				}
			}

			if ( $force ) {
				update_post_meta( $post->ID, '_newspack_migrated_modified_date', true );
			}
		}
		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator insight-crime-migrate-investigation-content`.
	 *
	 * @param array $args       Command arguments.
	 * @param array $assoc_args Command associative arguments.
	 */
	public function cmd_migrate_investigation_content( $args, $assoc_args ) {
		global $wpdb;

		$investigations_category_id = intval( $assoc_args['investigations-category-id'] );

		$meta_query = [
			[
				'key'     => '_newspack_migrated_investigations_content',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page'   => -1,
				'post_type'        => 'post',
				'cat'              => $investigations_category_id,
				'suppress_filters' => true,
				// 'p'              => 11,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'meta_query'       => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'        => 'post',
				'cat'              => $investigations_category_id,
				'suppress_filters' => true,
				// 'p'              => 11,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'meta_query'       => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$chapter_pdf_id = get_post_meta( $post->ID, 'chapter_pdf', true );

			if ( $chapter_pdf_id ) {
				$chapter_pdf_url = wp_get_attachment_url( $chapter_pdf_id );

				if ( $chapter_pdf_url ) {
					$button_content = serialize_block( $this->gutenberg_block_generator->get_button( 'Download PDF', $chapter_pdf_url ) );

					$new_content = $post->post_content . $button_content;

					wp_update_post(
						[
							'ID'           => $post->ID,
							'post_content' => $new_content,
						]
					);

					$this->logger->log( 'investigations_content_migration.log', sprintf( 'Post ID %d content updated', $post->ID ), Logger::SUCCESS );
				}
			}

			update_post_meta( $post->ID, '_newspack_migrated_investigations_content', true );
		}

		wp_cache_flush();
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
}
