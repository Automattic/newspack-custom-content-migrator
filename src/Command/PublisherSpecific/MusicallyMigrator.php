<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\Logger;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;

/**
 * Custom migration scripts for Musically.
 */
class MusicallyMigrator implements InterfaceCommand {
	const REPORTS_MIGRATOR_LOG = 'musically_reports_migrator.log';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->gutenberg_block_generator         = new GutenbergBlockGenerator();
		$this->logger                            = new Logger();
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
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
            'newspack-content-migrator musically-migrate-reports',
            [ $this, 'cmd_migrate_reports' ],
            [
				'shortdesc' => 'Migrate reports.',
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
            'newspack-content-migrator musically-migrate-pdfs',
            [ $this, 'cmd_migrate_pdfs' ],
            [
				'shortdesc' => 'Migrate PDFs.',
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
            'newspack-content-migrator musically-export-events-to-EC-csv',
            [ $this, 'cmd_export_events_to_EC_csv' ],
            [
				'shortdesc' => 'Export events to EC csv file',
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
	}

	/**
	 * Callable for `newspack-content-migrator musically-migrate-reports`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_reports( $args, $assoc_args ) {
		$log_file        = 'musically_migrate_reports.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'value' => 'template-single-post-report.php',
			],
			[
				'key'     => '_newspack_migration_report_migrated',
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
				// 'p'              => 113663,
				'fields'         => 'ids',
				'post_type'      => 'post',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            ]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post_id ) {
			$content_blocks = [];

			// Description.
			$description = get_post_meta( $post_id, 'report_description', true );
			if ( $description ) {
				$content_blocks[] = $this->gutenberg_block_generator->get_paragraph( $description );
			}
			$sections_count = get_post_meta( $post_id, 'section', true );
			if ( is_numeric( $sections_count ) ) {
				// sections titles + featured image.
				$sections = $this->get_report_sections( $post_id );

				$content_blocks[] = $this->gutenberg_block_generator->get_columns(
                    [
						// Sections summary.
						$this->gutenberg_block_generator->get_column(
							[
								$this->gutenberg_block_generator->get_list(
									array_map(
										function( $section_index, $section ) {
												return '<a href="#report-section-' . ( $section_index + 1 ) . '">' . $section['title'] . '</a>';
										},
										array_keys( $sections ),
										$sections
									),
									true
								),
							]
						),
						// featured image.
						$this->gutenberg_block_generator->get_column(
							[ $this->gutenberg_block_generator->get_image( get_post( get_post_thumbnail_id( $post_id ) ) ) ]
						),
                    ]
				);
				// Sections.
				foreach ( $sections as $section_index => $section ) {
					$content_blocks[] = $this->gutenberg_block_generator->get_heading( $section['title'], 'h2', 'report-section-' . ( $section_index + 1 ) );
					foreach ( $section['rows'] as $row ) {
						$content_blocks[] = $row;
					}
				}
			}

			// CEO word.
			$content_blocks[] = $this->gutenberg_block_generator->get_columns(
				[
					$this->gutenberg_block_generator->get_column(
						[
							$this->gutenberg_block_generator->get_site_logo( 275 ),
						],
						'33.33%'
					),
					$this->gutenberg_block_generator->get_column(
						[
							$this->gutenberg_block_generator->get_quote( "I came into this industry without any prior knowledge or connections. I learned so much from Music Ally's articles and the Sandbox magazines - so I owe Music Ally for that.", 'Sung Cho — Founder and CEO, Chartmetric' ),
						],
						'66.66%'
					),
				]
			);
			// The team.
			$team_members_count = intval( get_option( 'options_the_team', 0 ) );
			if ( is_numeric( $team_members_count ) ) {
				$content_blocks[]     = $this->gutenberg_block_generator->get_heading( 'The Team' );
				$team_members_columns = [];
				$members_count        = 0;
				$row_index            = 0;

				for ( $i = 0; $i < $team_members_count; $i++ ) {
					$team_member_email = get_option( "options_the_team_{$i}_email", false );
					if ( $team_member_email ) {
						$user = get_user_by( 'email', $team_member_email );
						if ( $user ) {
							if ( ! isset( $team_members_columns[ $row_index ] ) ) {
								$team_members_columns[ $row_index ] = [];
							}
							$team_members_columns[ $row_index ][] = $this->gutenberg_block_generator->get_column(
								[
									$this->gutenberg_block_generator->get_author_profile( $user->ID, true, true, true ),
								]
							);

							if ( 1 === $members_count % 2 ) {
								$row_index++;
							}

							$members_count++;
						}
					}
				}

				if ( ! empty( $team_members_columns ) ) {
					foreach ( $team_members_columns as $team_members_column ) {
						$content_blocks[] = $this->gutenberg_block_generator->get_columns( $team_members_column );
					}
				}
			}

			// Clients.
			$team_members_count = intval( get_option( 'options_clients', 0 ) );
			if ( is_numeric( $team_members_count ) ) {
				global $wpdb;

				$raw_clients = $wpdb->get_results(
					$wpdb->remove_placeholder_escape(
						$wpdb->prepare(
							"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
							'options_clients_%_logo'
						)
					)
				);

				$client_attachment_ids = array_map(
					function( $client ) {
						return intval( $client->option_value );
					},
					$raw_clients
				);

				$content_blocks[] = $this->gutenberg_block_generator->get_gallery( $client_attachment_ids, 6 );
			}

			$migrated_content = serialize_blocks( $content_blocks );
			if ( $migrated_content ) {
				// Update post content .
				wp_update_post(
                    array(
						'ID'           => $post_id,
						'post_content' => $migrated_content,
                    )
				);

				// Gate content by adding the `Premium` tag.
				wp_set_post_tags( $post_id, 'Premium', true );

				// Templating.
				update_post_meta( $post_id, '_wp_page_template', 'single-wide.php' );
				update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );

				$this->logger->log( $log_file, sprintf( 'Report migrated for the post %d', $post_id ), Logger::SUCCESS );
			}

			update_post_meta( $post_id, '_newspack_migration_report_migrated', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator musically-migrate-pdfs`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_pdfs( $args, $assoc_args ) {
		$log_file        = 'musically_migrate_pdfs.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migration_pdf_migrated',
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
				// 'p'              => 111047,
				'post_type'      => 'post',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            ]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$content_blocks = array_map(
                function( $block ) use ( $post, $log_file ) {
					if ( str_contains( $block['innerHTML'], '[embeddoc' ) ) {
						$pdf_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( 'embeddoc', $block['innerHTML'] );
						foreach ( $pdf_shortcodes[0] as $pdf_shortcode ) {
							$pdf_url = $this->squarebracketselement_manipulator->get_attribute_value( 'url', $pdf_shortcode );
							if ( $pdf_url ) {
								$attachment_url = $this->get_image_id_by_url( $pdf_url );
								if ( $attachment_url ) {
									$pdf_block                = $this->gutenberg_block_generator->get_pdf( $this->get_image_id_by_url( $pdf_url ), $pdf_url );
									$pdf_block_html           = serialize_block( $pdf_block );
									$block['innerHTML']       = str_replace( $pdf_shortcode, $pdf_block_html, $block['innerHTML'] );
									$block['innerContent'][0] = str_replace( $pdf_shortcode, $pdf_block_html, $block['innerContent'][0] );
								} else {
									$this->logger->log( $log_file, sprintf( "Can't detect PDF attchment ID from attachment URL %s in the post %d", $pdf_url, $post->ID ), Logger::WARNING );
								}
							} else {
								$this->logger->log( $log_file, sprintf( "Can't detect PDF URL from shortcode %s in the post %d", $pdf_shortcode, $post->ID ), Logger::WARNING );
							}
						}
					}
					return $block;
				},
                parse_blocks( $post->post_content )
            );

			$migrated_content = serialize_blocks( $content_blocks );
			if ( $migrated_content !== $post->post_content ) {
				// Update post content .
				wp_update_post(
                    array(
						'ID'           => $post->ID,
						'post_content' => $migrated_content,
                    )
				);

				$this->logger->log( $log_file, sprintf( 'PDF migrated for the post %d', $post->ID ), Logger::SUCCESS );
			}

			update_post_meta( $post->ID, '_newspack_migration_pdf_migrated', true );
		}

		wp_cache_flush();
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
	 * Callable for `newspack-content-migrator musically-export-events-to-EC-csv`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_export_events_to_EC_csv( $args, $assoc_args ) {
		$output_folder_path = $assoc_args['output-folder-path'];

		$meta_query = [
			[
				'key'     => '_newspack_migration_exported_event',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
            [
				'posts_per_page' => -1,
				'post_type'      => 'musically_events',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            ]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
            [
				'post_type'      => 'musically_events',
				'posts_per_page' => -1,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            ]
		);

		$posts = $query->get_posts();

		$venues = [];
		$events = [];

		register_taxonomy(
            'event_categories',
            'musically_events',
            array(
				'rewrite' => array( 'slug' => 'event_categories/musically_events' ),
            )
        );

		foreach ( $posts as $post ) {
			$start_date                     = get_post_meta( $post->ID, 'start_date', true );
			$end_date                       = get_post_meta( $post->ID, 'end_date', true );
			$location                       = get_post_meta( $post->ID, 'location', true );
			$eventbrite_embed               = get_post_meta( $post->ID, 'eventbrite_embed', true );
			$eventbrite_embed_2             = get_post_meta( $post->ID, 'eventbrite_embed_2', true );
			$additional_sidebar_image_count = intval( get_post_meta( $post->ID, 'additional_sidebar_images', true ) );

			// venue.
			if ( ! empty( $location ) && ! in_array( $location, $venues, true ) ) {
				$venues[] = $location;
			}

			// dates.
			$start_date = $start_date ? new \DateTime( $start_date ) : new \DateTime( $post->post_date );
			$end_date   = $end_date ? new \DateTime( $end_date ) : null;

			// event.
			$event = [
				'EVENT NAME'       => $post->post_title,
				'EVENT VENUE NAME' => $location,
				'EVENT START DATE' => $start_date->format( 'Y-m-d' ),
				'EVENT START TIME' => $start_date->format( 'H:i:s' ),
				'ALL DAY EVENT'    => ! $end_date,
			];

			if ( $end_date ) {
				$event['EVENT END DATE'] = $end_date->format( 'Y-m-d' );
				$event['EVENT END TIME'] = $end_date->format( 'H:i:s' );
			}

			// content.
			$content = $post->post_content;
			if ( $additional_sidebar_image_count > 1 ) {
				$additional_image_id = get_post_meta( $post->ID, 'additional_sidebar_images_1_additional_sidebar_image', true );
				$image_url           = wp_get_attachment_url( $additional_image_id );
				if ( $image_url ) {
					$content .= '<img src="' . $image_url . '" alt="' . wp_get_attachment_caption( $additional_image_id ) . '" />';
				}
			}
			if ( $eventbrite_embed ) {
				$content .= $eventbrite_embed;
			}
			if ( $eventbrite_embed_2 ) {
				$content .= $eventbrite_embed_2;
			}

			$event['EVENT DESCRIPTION'] = $content;

			// featured image.
			if ( $additional_sidebar_image_count > 0 ) {
				$featured_image_id  = get_post_meta( $post->ID, 'additional_sidebar_images_0_additional_sidebar_image', true );
				$featured_image_url = wp_get_attachment_url( $featured_image_id );
				if ( $featured_image_url ) {
					$event['EVENT FEATURED IMAGE'] = $featured_image_id;
				}
			}

			// categories.
			$event_cats = wp_get_post_terms( $post->ID, 'event_categories' );
			if ( $event_cats ) {
				$event['EVENT CATEGORY'] = join(
                    ',',
					array_map(
                        function( $c ) {
                            return $c->name;
                        },
                        $event_cats
                    )
                );
			}

			$events[] = $event;

			update_post_meta( $post->ID, '_newspack_migration_exported_event', true );
		}

		if ( ! empty( $events ) ) {
			$this->save_CSV(
				$output_folder_path . 'venues-' . strtotime( 'now' ) . '.csv',
				array_map(
					function( $d ) {
						return [ 'Venue Name' => $d ];
					},
					$venues
				)
			);

			$this->save_CSV( $output_folder_path . 'events-' . strtotime( 'now' ) . '.csv', $events );

			WP_CLI::line( sprintf( 'The XML content was successfully migrated to the folder: %s', $output_folder_path ) );
		} else {
			WP_CLI::line( 'There are no events to import!' );
		}
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
	 * Get a given post' sections with their content .
     *
     * @param int    $post_id Post ID .
     * @return array
     * /
	private function get_report_sections( $post_id ) {
		global $wpdb;

		$sections     = [];
		$raw_sections = $wpdb->get_results(
            $wpdb->remove_placeholder_escape(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
                    $post_id,
                    'section_%_content_builder'
                )
            )
		);

		foreach ( $raw_sections as $section_index => $section ) {
			$section_title = get_post_meta( $post_id, "section_{$section_index}_section_title", true );
			$row_layouts   = maybe_unserialize( $section->meta_value );

			$sections[] = [
				'title' => $section_title,
				'rows'  => array_map(
                    function( $row_index, $row_layout ) use ( $post_id, $section_index ) {
                        return $this->get_section_row_content( $post_id, $section_index, $row_index, $row_layout );
                    },
                    array_keys( $row_layouts ),
                    $row_layouts
                ),
			];
		}

		return $sections;
	}

	/**
	 * Get a row content based on it's layout.
	 *
	 * @param int    $post_id Post ID where the section's row is in, used to retreive its content.
	 * @param int    $section_index Section index, used to retreive its content.
	 * @param int    $row_index Row Index, used to retreive its content.
	 * @param string $row_layout Row layout.
	 * @return array
	 */
	private function get_section_row_content( $post_id, $section_index, $row_index, $row_layout ) {
		global $wpdb;

		switch ( $row_layout ) {
			case 'one_column':
				$content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_content", true );

                return $this->gutenberg_block_generator->get_html( nl2br( $content ) );
			case 'two_columns_1_1':
				$left_content  = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_left_column", true );
				$right_content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_right_column", true );

                return $this->gutenberg_block_generator->get_columns(
                    [
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $left_content ),
                            ]
						),
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $right_content ),
                            ]
						),
					]
                );
			case 'two_columns_1_2':
				$left_content  = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_left_column", true );
				$right_content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_right_column", true );

                return $this->gutenberg_block_generator->get_columns(
                    [
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $left_content ),
                            ],
                            '33.33%'
						),
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $right_content ),
                            ],
                            '66.66%'
						),
					]
                );
			case 'two_columns_2_1':
				$left_content  = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_left_column", true );
				$right_content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_right_column", true );

                return $this->gutenberg_block_generator->get_columns(
                    [
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $left_content ),
                            ],
                            '66.66%'
						),
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $right_content ),
                            ],
                            '33.33%'
						),
					]
                );
			case 'three_columns':
				$column_1_content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_column_1", true );
				$column_2_content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_column_2", true );
				$column_3_content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_column_3", true );

                return $this->gutenberg_block_generator->get_columns(
                    [
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $column_1_content ),
                            ]
						),
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $column_2_content ),
                            ]
						),
						$this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $column_3_content ),
                            ]
						),
					]
                );
			case 'four_columns':
				$columns_content = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
						$post_id,
						"section_{$section_index}_content_builder_{$row_index}_columns_%_content"
					)
				);
				$columns         = array_map(
                    function( $column ) {
						return $this->gutenberg_block_generator->get_column(
                            [
								$this->gutenberg_block_generator->get_html( $column->meta_value ),
                            ]
						);
					},
                    $columns_content
                );
				return $this->gutenberg_block_generator->get_columns( $columns );
			case 'masonry':
				$image_ids_meta = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
						$post_id,
						"section_{$section_index}_content_builder_{$row_index}_columns_%_image"
					)
				);

				$image_ids = array_map(
                    function( $image_id_meta ) {
                        return intval( $image_id_meta->meta_value );
                    },
                    $image_ids_meta
                );

				return $this->gutenberg_block_generator->get_jetpack_tiled_gallery( $image_ids );
			case 'heading':
				$content = get_post_meta( $post_id, "section_{$section_index}_content_builder_{$row_index}_title", true );
				return $this->gutenberg_block_generator->get_heading( $content );
			case 'separating_dots':
				return $this->gutenberg_block_generator->get_separator( 'is-style-dots' );
			default:
				echo '404';
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
		$raw_author_post_ids = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT( post_id ) FROM {$wpdb->postmeta} WHERE meta_value = % s", $author_name_meta ), \ARRAY_A );
		$author_post_ids     = array_map( 'intval', array_map( 'current', array_values( $raw_author_post_ids ) ) );

		// Get the meta value when filled.
		$post_id_placeholders = implode( ', ', array_fill( 0, count( $author_post_ids ), '%d' ) );
		$meta_values          = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT( meta_value ) FROM {$wpdb->postmeta} WHERE post_id IN( $post_id_placeholders ) and meta_key = % s", array_merge( $author_post_ids, [ $meta_key ] ) ) ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $meta_values as $meta ) {
			if ( ! empty( $meta->meta_value ) ) {
				return $meta->meta_value;
			}
		}

		return '';
	}

	/**
	 * Get image ID by URL.
	 *
	 * @param string $url Media URL.
	 * @return int|false
	 */
	private function get_image_id_by_url( $url ) {
		global $wpdb;
		$image = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid=%s;", $url ) );

		if ( ! empty( $image ) ) {
			return intval( $image[0] );
		}

		return false;
	}
}
