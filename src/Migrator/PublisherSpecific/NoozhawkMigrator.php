<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use NewspackCustomContentMigrator\Migrator\General\SubtitleMigrator;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;
use \WP_Error;
use \DOMDocument;
use \CoAuthors_Guest_Authors;
use \CoAuthors_Plus;

/**
 * Custom migration scripts for Noozhawk.
 */
class NoozhawkMigrator implements InterfaceMigrator {
	// Logs.
	const AUTHORS_LOGS    = 'NH_authors.log';
	const EXCERPT_LOGS    = 'NH_authors.log';
	const CO_AUTHORS_LOGS = 'NH_co_authors.log';
	// Output filenames.
	const VENUES_CSV_FILENAME     = 'nh-venues.csv';
	const ORGANIZERS_CSV_FILENAME = 'nh-organizers.csv';
	const EVENTS_CSV_FILENAME     = 'nh-events.csv';

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachment_logic;

	/**
	 * @var null|CoAuthors_Guest_Authors
	 */
	public $coauthors_guest_authors;

	/**
	 * @var null|CoAuthors_Plus
	 */
	public $coauthors_plus;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var Crawler
	 */
	private $dom_crawler;

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic     = new CoAuthorPlusLogic();
		$this->attachment_logic        = new AttachmentsLogic();
		$this->coauthors_guest_authors = new CoAuthors_Guest_Authors();
		$this->coauthors_plus          = new CoAuthors_Plus();
		$this->dom_crawler             = new Crawler();
		$this->posts_logic             = new PostsLogic();
	}

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
			'newspack-content-migrator noozhawk-co-authors-from-csv',
			array( $this, 'cmd_nh_import_co_authors' ),
			array(
				'shortdesc' => 'Import co-authors from CSV',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'authors-csv-path',
						'description' => 'CSV file path that contains the co-authors to import.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-clean-authors-categories-from-csv',
			array( $this, 'cmd_nh_import_clean_authors_categories' ),
			array(
				'shortdesc' => 'Clean imported co-authors categories from CSV',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'authors-csv-path',
						'description' => 'CSV file path that contains the co-authors categories to clean.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-copy-excerpt-from-subhead',
			array( $this, 'cmd_nh_copy_excerpt_from_subhead' ),
			array(
				'shortdesc' => 'Import co-authors from CSV',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-generate-the-events-calendar-csv-import-from-xml',
			array( $this, 'cmd_nh_convert_events_xml_to_csv' ),
			array(
				'shortdesc' => 'Import co-authors from CSV',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'events-xml-path',
						'description' => 'XML file path that contains the events to export to CSV.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'csv-output-folder-path',
						'description' => 'CSV output folder path.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-import-co-authors-from-alias-meta',
			array( $this, 'cmd_nh_import_co_authors_from_alias_meta' ),
			array(
				'shortdesc' => 'Import co-authors from CSV',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-clean-co-authors-import',
			array( $this, 'cmd_nh_clean_co_authors_import' ),
			array(
				'shortdesc' => 'Remove imported co-authors from all posts.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-set-primary-category-from-meta',
			array( $this, 'cmd_nh_set_primary_category_meta' ),
			array(
				'shortdesc' => 'Set Post primary category',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-set-coauthors',
			array( $this, 'cmd_nh_set_coauthors' ),
			array(
				'shortdesc' => 'Set Post co-authors.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-import-featured-image',
			array( $this, 'cmd_nh_import_featured_image' ),
			array(
				'shortdesc' => 'Set Post featured image.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-migrate-galleries',
			array( $this, 'cmd_nh_migrate_galleries' ),
			array(
				'shortdesc' => 'Migrate posts galleries.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'disable-popups',
						'description' => 'Disable popups on the posts with galleries due to a campaigns\' bug',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-copy-media-caption-from-titles',
			array( $this, 'cmd_nh_copy_media_caption_from_titles' ),
			array( 'shortdesc' => 'Migrate media captions from media titles.', )
		);
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-co-authors-from-csv`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_import_co_authors( $args, $assoc_args ) {
		$authors_json_path = $assoc_args['authors-csv-path'] ?? null;
		if ( ! file_exists( $authors_json_path ) ) {
			WP_CLI::error( sprintf( 'Author export %s not found.', $authors_json_path ) );
		}

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			$this->log( self::AUTHORS_LOGS, 'Co-Authors Plus plugin not found. Install and activate it before using this command.', false );
			return;
		}

		$co_authors_added = array();

		$time_start = microtime( true );
		if ( ( $h = fopen( $authors_json_path, 'r' ) ) !== false ) {
			while ( ( $author = fgetcsv( $h, 1000, ',' ) ) !== false ) {
				try {
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
						array(
							'display_name' => sanitize_user( $author[0], false ),
						)
					);
					if ( is_wp_error( $guest_author_id ) ) {
						WP_CLI::warning( sprintf( "Could not create GA full name '%s': %s", $author['name'], $guest_author_id->get_error_message() ) );
						$this->log( self::AUTHORS_LOGS, sprintf( "Could not create GA full name '%s': %s", $author['name'], $guest_author_id->get_error_message() ) );
						continue;
					}

					// Set original ID.
					$co_authors_added[] = $author;
					update_post_meta( $guest_author_id, 'imported_from_categories', true );
					$this->log( self::AUTHORS_LOGS, sprintf( '- %s', $author[0] ) );

					// Set co-author to the category' posts.
					$author_category = get_category_by_slug( $author[1] );
					if ( ! $author_category ) {
						$this->log( self::AUTHORS_LOGS, sprintf( 'There is no category for this author: %s!', $author[1] ) );
						continue;
					}

					$posts = get_posts(
						array(
							'numberposts' => -1,
							'category'    => $author_category->term_id,
							'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
						)
					);

					foreach ( $posts as $post ) {
						$this->coauthorsplus_logic->assign_guest_authors_to_post( array( $guest_author_id ), $post->ID );
						$this->log( self::AUTHORS_LOGS, sprintf( '    - %s was added as post co-author for the post %d.', $author[0], $post->ID ) );
					}
				} catch ( \Exception $e ) {
					WP_CLI::warning( sprintf( "Could not create GA full name '%s': %s", $author['name'], $e->getMessage() ) );
					$this->log( self::AUTHORS_LOGS, sprintf( "Could not create GA full name '%s': %s", $author['name'], $e->getMessage() ) );
				}
			}

			// Close the file.
			fclose( $h );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d co-authors took %d mins.', count( $co_authors_added ), floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-clean-authors-categories-from-csv`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_import_clean_authors_categories( $args, $assoc_args ) {
		$authors_json_path = $assoc_args['authors-csv-path'] ?? null;
		if ( ! file_exists( $authors_json_path ) ) {
			WP_CLI::error( sprintf( 'Author export %s not found.', $authors_json_path ) );
		}

		$time_start = microtime( true );
		if ( ( $h = fopen( $authors_json_path, 'r' ) ) !== false ) {
			while ( ( $author = fgetcsv( $h, 1000, ',' ) ) !== false ) {
				$category_id = get_cat_ID( $author[0] );

				if ( ! $category_id ) {
					$this->log( self::AUTHORS_LOGS, sprintf( 'Category "%s" was not found!', $author[0] ), false );
					WP_CLI::warning( sprintf( 'Category "%s" was not found!', $author[0] ) );
					continue;
				}

				wp_delete_category( $category_id );
				$this->log( self::AUTHORS_LOGS, sprintf( 'Category "%s" was deleted!', $author[0] ) );
			}

			// Close the file.
			fclose( $h );
		}

		WP_CLI::line( 'All done! 🙌' );
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-copy-excerpt-from-subhead`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_copy_excerpt_from_subhead( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'meta_key'    => SubtitleMigrator::NEWSPACK_SUBTITLE_META_FIELD,
			)
		);

		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			$subhead = get_post_meta( $post->ID, SubtitleMigrator::NEWSPACK_SUBTITLE_META_FIELD, true );
			if ( ! empty( $subhead ) && $post->post_excerpt !== $subhead ) {
				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_excerpt' => $subhead,
					)
				);

				$this->log( self::EXCERPT_LOGS, sprintf( '(%d/%d) Excerpt updated for the post: %d', $index, $total_posts, $post->ID ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-generate-the-events-calendar-csv-import-from-xml`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_convert_events_xml_to_csv( $args, $assoc_args ) {
		$events_xml_path        = $assoc_args['events-xml-path'] ?? null;
		$events_csv_output_path = $assoc_args['csv-output-folder-path'] ?? null;

		if ( ! file_exists( $events_xml_path ) ) {
			WP_CLI::error( sprintf( 'Events XML export %s not found.', $events_xml_path ) );
		}

		$raw_events = $this->parse_XML_events( $events_xml_path );

		$organizers = [];
		$venues     = [];
		$events     = [];

		foreach ( $raw_events as $event ) {
			preg_match( '/\$\s*(?<price>\d+)/', self::get_event_meta( $event['postmeta'], 'event_price' ), $price_matches );
			$has_price = array_key_exists( 'price', $price_matches );
			$event_day = ( new \DateTime( $event['post_date_gmt'] ) );

			// Event Content.
			$this->dom_crawler->clear();
			$this->dom_crawler->add( $event['post_content'] );
			$event_content_dom = $this->dom_crawler->filter( '.profileRow.float-right' );
			$event_content     = 1 === $event_content_dom->count() ? trim( $event_content_dom->getNode( 0 )->textContent ) : self::get_event_meta( $event['postmeta'], 'event_intro' );

			// Event Image.
			$image_media_id = $this->attachment_logic->import_external_file( $event['attachment_url'], $event['post_title'] );
			$featured_image = wp_get_attachment_url( $image_media_id );

			WP_CLI::line( sprintf( 'Converted event: %s', $event['post_title'] ) );

			$venue     = self::get_event_meta( $event['postmeta'], 'event_location' );
			$organizer = self::get_event_meta( $event['postmeta'], 'event_sponsors' );

			if ( ! empty( $organizer ) && ! in_array( $organizer, $organizers ) ) {
				$organizers[] = $organizer;
			}

			if ( ! empty( $venue ) && ! in_array( $venue, $venues ) ) {
				$venues[] = $venue;
			}

			$events[] = [
				'EVENT NAME'            => $event['post_title'],
				'EVENT VENUE NAME'      => $venue,
				'EVENT ORGANIZER NAME'  => $organizer,
				'EVENT START DATE'      => $event_day->format( 'Y-m-d' ),
				'EVENT START TIME'      => gmdate( 'H:i:s', strtotime( self::get_event_meta( $event['postmeta'], 'event_start' ) ) ),
				'EVENT END DATE'        => $event_day->format( 'Y-m-d' ),
				'EVENT END TIME'        => gmdate( 'H:i:s', strtotime( self::get_event_meta( $event['postmeta'], 'event_end' ) ) ),
				'ALL DAY EVENT'         => false,
				'TIMEZONE'              => $event_day->getTimezone()->getName(),
				'EVENT COST'            => $has_price ? $price_matches['price'] : self::get_event_meta( $event['postmeta'], 'event_price' ),
				'EVENT CURRENCY SYMBOL' => $has_price ? '$' : '',
				'EVENT FEATURED IMAGE'  => $featured_image,
				'EVENT WEBSITE'         => self::get_event_meta( $event['postmeta'], 'event_url' ),
				'EVENT DESCRIPTION'     => $event_content,
			];
		}

		if ( ! empty( $events ) ) {
			$this->save_CSV(
				$events_csv_output_path . self::VENUES_CSV_FILENAME,
				array_map(
					function( $d ) {
						return [ 'Venue Name' => $d ];
					},
					$venues
				)
			);
			$this->save_CSV(
				$events_csv_output_path . self::ORGANIZERS_CSV_FILENAME,
				array_map(
					function( $d ) {
						return [ 'Organizer Name' => $d ];
					},
					$organizers
				)
			);
			$this->save_CSV( $events_csv_output_path . self::EVENTS_CSV_FILENAME, $events );

			WP_CLI::line( sprintf( 'The XML content was successfully migrated to the folder: %s', $events_csv_output_path ) );
		} else {
			WP_CLI::line( 'There are no events to import!' );
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-import-co-authors-from-alias-meta`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_import_co_authors_from_alias_meta( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'meta_key'    => 'created_by_alias',
			)
		);

		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			$co_author_alias_meta = get_post_meta( $post->ID, 'created_by_alias', true );

			if ( ! empty( $co_author_alias_meta ) ) {
				$co_authors_names = array_filter(
					// Split co-authors meta by '&' and ',', and trim white space.
					array_map(
						function( $co_author_name ) {
							return trim( $co_author_name );
						},
						preg_split( '/[&|,]/', $co_author_alias_meta, -1, PREG_SPLIT_NO_EMPTY )
					),
					// Filter co-authors with HTML tags, as they are  not real co-authors, but post statuses.
					function( $co_author_name ) {
						return wp_strip_all_tags( $co_author_name ) === $co_author_name;
					}
				);

				$guest_authors_ids = [];
				foreach ( $co_authors_names as $co_author_name ) {
					try {
						$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
							array(
								'display_name' => sanitize_user( $co_author_name, false ),
							)
						);
						if ( is_wp_error( $guest_author_id ) ) {
							WP_CLI::warning( sprintf( "Could not create GA '%s': %s", $co_author_name, $guest_author_id->get_error_message() ) );
							$this->log( self::CO_AUTHORS_LOGS, sprintf( "Could not create GA '%s': %s", $co_author_name, $guest_author_id->get_error_message() ) );
							continue;
						}

						$guest_authors_ids[] = $guest_author_id;

						// Set original ID.
						update_post_meta( $guest_author_id, 'imported_from_alias_meta', true );
						$this->log( self::CO_AUTHORS_LOGS, sprintf( '- %s', $co_author_name ) );

						// Link WP_User if existing.
						$existing_wp_users = ( new \WP_User_Query(
							[
								'search'        => $co_author_name,
								'search_fields' => array( 'user_login', 'user_nicename', 'display_name' ),
							]
						) )->get_results();
						if ( ! empty( $existing_wp_users ) ) {
							$this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author_id, $existing_wp_users[0] );
							$this->log( self::CO_AUTHORS_LOGS, sprintf( '- Guest author %s linked to WP_User', $co_author_name, $existing_wp_users[0]->display_name ) );
						}
					} catch ( \Exception $e ) {
						WP_CLI::warning( sprintf( "Could not create GA '%s': %s", $co_author_name, $e->getMessage() ) );
						$this->log( self::CO_AUTHORS_LOGS, sprintf( "Could not create GA '%s': %s", $co_author_name, $e->getMessage() ) );
					}
				}

				// Fix post_author = 0.
				if ( 0 === intval( $post->post_author ) ) {
					wp_update_post(
						[
							'ID'          => $post->ID,
							'post_author' => 4, // Michelle Nelson user ID.
						]
					);
					$this->log( self::CO_AUTHORS_LOGS, sprintf( 'The author of the post %d was updated to Michelle nelson.', $post->ID ) );
				}

				$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_authors_ids, $post->ID );
				$this->log( self::CO_AUTHORS_LOGS, sprintf( '(%d/%d) co-authors imported for the post: %d', $index, $total_posts, $post->ID ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-clean-co-authors-import`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_clean_co_authors_import( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'meta_key'    => 'created_by_alias',
				// 'post__in'    => [ 216322 ],
			)
		);

		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			$co_author_alias_meta = get_post_meta( $post->ID, 'created_by_alias', true );

			if ( ! empty( $co_author_alias_meta ) ) {
				// $co_authors_names = array_filter(
				// Split co-authors meta by '&' and ',', and trim white space.
				// array_map(
				// function( $co_author_name ) {
				// return trim( $co_author_name );
				// },
				// preg_split( '/[&|,]/', $co_author_alias_meta, -1, PREG_SPLIT_NO_EMPTY )
				// ),
				// Filter co-authors with HTML tags, as they are  not real co-authors, but post statuses.
				// function( $co_author_name ) {
				// return wp_strip_all_tags( $co_author_name ) === $co_author_name;
				// }
				// );

				// $to_clean = false;
				// foreach ( $co_authors_names as $co_author_name ) {
				// $display_name    = sanitize_user( $co_author_name, false );
				// $guest_author_id = $this->coauthorsplus_logic->create_guest_author(
				// array(
				// 'display_name' => $display_name,
				// )
				// );
				// if ( $guest_author_id ) {
				// $to_clean = substr( $display_name, 0, 1 ) === '#';
				// }

				// if ( $to_clean ) {
				// break;
				// }
				// }

				// if ( $to_clean ) {
				// foreach ( $co_authors_names as $co_author_name ) {
				// $guest_author_id = $this->coauthorsplus_logic->create_guest_author(
				// array(
				// 'display_name' => $display_name,
				// )
				// );

				// if ( $guest_author_id ) {
				// $this->coauthors_guest_authors->delete( $guest_author_id );
				// WP_CLI::line( sprintf( 'Co-author "%s" was deleted!', $co_author_name ) );
				// }
				// }

				// Add the right co-author.
				// $guest_authors_ids = [];
				// $co_author_name    = html_entity_decode( $co_author_alias_meta );
				// $co_authors        = explode( '|', $co_author_name );
				// foreach ( $co_authors as $co_author_raw ) {
				// $co_author = trim( $co_author_raw );

				// try {
				// $guest_author_id = $this->coauthorsplus_logic->create_guest_author(
				// array(
				// 'display_name' => $co_author,
				// )
				// );
				// if ( is_wp_error( $guest_author_id ) ) {
				// WP_CLI::warning( sprintf( "Could not create GA '%s': %s", $co_author_name, $guest_author_id->get_error_message() ) );
				// $this->log( self::CO_AUTHORS_LOGS, sprintf( "Could not create GA '%s': %s", $co_author_name, $guest_author_id->get_error_message() ) );
				// continue;
				// }

				// $guest_authors_ids[] = $guest_author_id;

				// Set original ID.
				// update_post_meta( $guest_author_id, 'imported_from_alias_meta', true );
				// $this->log( self::CO_AUTHORS_LOGS, sprintf( '- %s', $co_author_name ) );

				// Link WP_User if existing.
				// $existing_wp_users = ( new \WP_User_Query(
				// [
				// 'search'        => $co_author_name,
				// 'search_fields' => array( 'user_login', 'user_nicename', 'display_name' ),
				// ]
				// ) )->get_results();
				// if ( ! empty( $existing_wp_users ) ) {
				// $this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author_id, $existing_wp_users[0] );
				// $this->log( self::CO_AUTHORS_LOGS, sprintf( '- Guest author %s linked to WP_User', $co_author_name, $existing_wp_users[0]->display_name ) );
				// }
				// } catch ( \Exception $e ) {
				// WP_CLI::warning( sprintf( "Could not create GA '%s': %s", $co_author_name, $e->getMessage() ) );
				// $this->log( self::CO_AUTHORS_LOGS, sprintf( "Could not create GA '%s': %s", $co_author_name, $e->getMessage() ) );
				// }
				// }

				// if ( ! empty( $guest_authors_ids ) ) {
				// $this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_authors_ids, $post->ID );
				// $this->log( self::CO_AUTHORS_LOGS, sprintf( '(%d/%d) co-authors imported for the post: %d', $index, $total_posts, $post->ID ) );
				// }
				// }

				// Fix badly splited co-author name.
				$post_co_authors = $this->coauthorsplus_logic->get_guest_authors_for_post( $post->ID );

				foreach ( $post_co_authors as $post_co_author ) {
					foreach ( $post_co_authors as $post_co_author_to_check ) {
						if ( ( $post_co_author->display_name !== $post_co_author_to_check->display_name ) && ( strpos( $post_co_author->display_name, $post_co_author_to_check->display_name ) !== false ) ) {
							$this->coauthors_guest_authors->delete( $post_co_author_to_check->ID );
							WP_CLI::line( sprintf( "Deleting '%s' (%d) from '%s'.", $post_co_author_to_check->display_name, $post_co_author_to_check->ID, $post_co_author->display_name ) );
						}
					}

					// Remove {update} x:xx p.m. co-authors.
					if ( substr( $post_co_author->display_name, 0, 8 ) === '{update}' || substr( strtolower( $post_co_author->display_name ), 0, 7 ) === 'updated' ) {
						$this->coauthors_guest_authors->delete( $post_co_author->ID );
						WP_CLI::line( sprintf( "Deleting '%s'.", $post_co_author->display_name ) );
					}

					if ( preg_match( '/^[0-9]{1,2}:[0-9]{1,2}\s?(a|p)\.m\.$/', $post_co_author->display_name ) ) {
						$this->coauthors_guest_authors->delete( $post_co_author->ID );
						WP_CLI::line( sprintf( "Deleting '%s'.", $post_co_author->display_name ) );
					}
				}

				// Fix Noozhawk Staff Writer suffix.
				if ( 2 === count( $post_co_authors ) ) {
					$suffix = 'Noozhawk Staff Writer';

					$co_author_to_keep = false;
					if ( $suffix === $post_co_authors[0]->display_name ) {
						$co_author_to_keep = $post_co_authors[1];
					} elseif ( $suffix === $post_co_authors[1]->display_name ) {
						$co_author_to_keep = $post_co_authors[0];
					}

					if ( $co_author_to_keep ) {
						if ( substr_compare( $co_author_to_keep->display_name, $suffix, -strlen( $suffix ) ) !== 0 ) {
							$co_author_to_keep->display_name = $co_author_to_keep->display_name . ', ' . $suffix;
						}

						$this->coauthors_plus->update_author_term( $co_author_to_keep );

						wp_update_post(
							array(
								'ID'         => $co_author_to_keep->ID,
								'post_title' => $co_author_to_keep->display_name,
							)
						);

						update_post_meta( $co_author_to_keep->ID, 'cap-display_name', $co_author_to_keep->display_name );

						// $this->coauthors_guest_authors->delete_guest_author_cache( $co_author_to_keep->ID );
						$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $co_author_to_keep->ID ], $post->ID );
						WP_CLI::line( sprintf( "Setting '%s' as co-author for the post %d", $co_author_to_keep->display_name, $post->ID ) );
					}
				}
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-set-primary-category-from-meta`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_set_primary_category_meta( $args, $assoc_args ) {
		$query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'meta_key'       => '_newspack_primary_category',
			]
		);

		$posts = $query->get_posts();
		foreach ( $posts as $post ) {
			$primary_category = get_post_meta( $post->ID, '_newspack_primary_category', true );
			$terms            = get_terms(
				[
					'taxonomy'   => 'category',
					'name'       => $primary_category,
					'hide_empty' => false,
				]
			);

			if ( count( $terms ) !== 1 ) {
				WP_CLI::warning( sprintf( "Can't find the category %s", $primary_category ) );
			}

			$category = $terms[0];

			update_post_meta( $post->ID, '_yoast_wpseo_primary_category', $category->term_id );
			WP_CLI::success( sprintf( 'Primary category for the post %d is set to: %s', $post->ID, $primary_category ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-set-coauthors`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_set_coauthors( $args, $assoc_args ) {
		$query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'meta_key'       => '_newspack_co_authors',
			]
		);

		$posts = $query->get_posts();
		foreach ( $posts as $post ) {
			$co_authors       = [];
			$co_authors_names = json_decode( get_post_meta( $post->ID, '_newspack_co_authors', true ), true );
			if ( ! $co_authors_names ) {
				WP_CLI::warning( sprintf( 'Post meta `_newspack_co_authors` is not in JSON format and should be fixed for the post %d.', $post->ID ) );
				continue;
			}
			foreach ( $co_authors_names as $co_author_name ) {
				$co_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $co_author_name ] );
				if ( is_wp_error( $co_author_id ) ) {
					WP_CLI::warning( sprintf( "Can't create co-author %s: %s", $co_author_name, $co_author_id ) );
					continue;
				}

				$co_authors[] = $co_author_id;
			}

			if ( 0 < count( $co_authors ) ) {
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_authors, $post->ID );
				WP_CLI::success( sprintf( 'Setting post %s co-authors: %s', $post->ID, implode( ', ', $co_authors_names ) ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-import-featured-image`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_import_featured_image( $args, $assoc_args ) {
		$query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'meta_key'       => '_newspack_thumbnail',
			]
		);

		$posts = $query->get_posts();
		foreach ( $posts as $post ) {
			$featured_image = json_decode( get_post_meta( $post->ID, '_newspack_thumbnail', true ), true );
			if ( ! $featured_image ) {
				WP_CLI::warning( sprintf( 'Post meta `_newspack_thumbnail` is not in JSON format and should be fixed for the post %d.', $post->ID ) );
				continue;
			}

			$existing_featured_image = $this->get_post_by_meta( '_newspack_imported_from_url', $featured_image['url'], 'attachment' );

			$featured_image_id = $existing_featured_image ? $existing_featured_image->ID : $this->attachment_logic->import_external_file(
				$featured_image['url'],
                array_key_exists( 'title', $featured_image ) ? $featured_image['title'] : null,
                array_key_exists( 'caption', $featured_image ) ? $featured_image['caption'] : null,
                null,
                array_key_exists( 'alt', $featured_image ) ? $featured_image['alt'] : null,
                $post->ID
			);

			if ( is_wp_error( $featured_image_id ) ) {
				WP_CLI::warning( sprintf( "Can't download %d post featured image from %s: %s", $post->ID, $featured_image['url'], $featured_image_id ) );
				continue;
			}

			set_post_thumbnail( $post->ID, $featured_image_id );
			update_post_meta( $featured_image_id, '_newspack_imported_from_url', $featured_image['url'] );
			WP_CLI::success( sprintf( 'Setting post %s featured image: %d', $post->ID, $featured_image_id ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-migrate-galleries`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_migrate_galleries( $args, $assoc_args ) {
		$disable_popups = isset( $assoc_args['disable-popups'] ) ? true : false;

		$query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'meta_key'       => '_newspack_slideshow_images',
			]
		);

		$posts = array_filter(
            $query->get_posts(),
            function( $post ) {
				$gallery_already_migrated = get_post_meta( $post->ID, '_newspack_gallery_migrated', true );
				if ( $gallery_already_migrated ) {
					WP_CLI::warning( sprintf( 'Gallery migration for the post %d is already done, skipped.', $post->ID ) );
				}
				return ! $gallery_already_migrated;
			}
        );

		foreach ( $posts as $post ) {
			$gallery_images = json_decode( get_post_meta( $post->ID, '_newspack_slideshow_images', true ), true );
			if ( ! $gallery_images ) {
				WP_CLI::warning( sprintf( 'Post meta `_newspack_slideshow_images` is not in JSON format and should be fixed for the post %d.', $post->ID ) );
				continue;
			}

			$images = [];
			foreach ( $gallery_images as $gallery_image ) {
				$existing_gallery_image = $this->get_post_by_meta( '_newspack_imported_from_url', $gallery_image['url'], 'attachment' );
				$gallery_image_id       = $existing_gallery_image ? $existing_gallery_image->ID : $this->attachment_logic->import_external_file(
                    $gallery_image['url'],
                    array_key_exists( 'title', $gallery_image ) ? $gallery_image['title'] : null,
                    array_key_exists( 'caption', $gallery_image ) ? $gallery_image['caption'] : null,
                    null,
                    array_key_exists( 'alt', $gallery_image ) ? $gallery_image['alt'] : null,
                    $post->ID
				);

				if ( is_wp_error( $gallery_image_id ) ) {
					WP_CLI::warning( sprintf( "Can't download %d post featured image from %s: %s", $post->ID, $gallery_image['url'], $featured_image_id ) );
					continue;
				}

				$images[] = $gallery_image_id;
				update_post_meta( $gallery_image_id, '_newspack_imported_from_url', $gallery_image['url'] );
			}

			if ( 0 < count( $images ) ) {
				$gallery_block = $this->posts_logic->generate_jetpack_slideshow_block_from_media_posts( $images );

				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $gallery_block . $post->post_content,
					)
				);

				update_post_meta( $post->ID, '_newspack_gallery_migrated', true );

				if ( $disable_popups ) {
					update_post_meta( $post->ID, 'newspack_popups_has_disabled_popups', true );
				}
				WP_CLI::success( sprintf( 'Post %d galleries were migrated!', $post->ID ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-copy-media-caption-from-titles`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_copy_media_caption_from_titles( $args, $assoc_args ) {
		global $wpdb;

		$query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'attachment',
				'post_status'    => 'any',
			]
		);

		$media_posts = $query->get_posts();

		foreach ( $media_posts as $media ) {
			$media_alt = get_post_meta( $media->ID, '_wp_attachment_image_alt', true );
			if ( empty( $media->post_excerpt ) && ! empty( $media_alt ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$wpdb->prefix . 'posts',
					array( 'post_excerpt' => $media_alt ),
					array( 'ID' => $media->ID )
				);

				WP_CLI::line( sprintf( 'Updated media: %d', $media->ID ) );
			}
		}
	}

	/**
	 * Parse WXR XML to get posts
	 * A small fork of https://raw.githubusercontent.com/WordPress/wordpress-importer/b4b11945c5735868671b060b65ebd8978b15e9c4/src/parsers/class-wxr-parser-simplexml.php
	 *
	 * @param string $xml_file_path XML filepath.
	 * @return string[][]
	 */
	private function parse_XML_events( $xml_file_path ) {
		$authors = array();
		$posts   = array();

		$internal_errors = libxml_use_internal_errors( true );
		$dom             = new DOMDocument();
		$old_value       = null;

		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$old_value = libxml_disable_entity_loader( true );
		}
		$success = $dom->loadXML( file_get_contents( $xml_file_path ) );
		if ( ! is_null( $old_value ) ) {
			libxml_disable_entity_loader( $old_value );
		}
		if ( ! $success ) {
			return new WP_Error( 'SimpleXML_parse_error', __( 'There was an error when reading this WXR file', 'wordpress-importer' ), libxml_get_errors() );
		}
		$xml = simplexml_import_dom( $dom );
		unset( $dom );

		// halt if loading produces an error.

		if ( ! $xml ) {
			return new WP_Error( 'SimpleXML_parse_error', __( 'There was an error when reading this WXR file', 'wordpress-importer' ), libxml_get_errors() );
		}

		$wxr_version = $xml->xpath( '/rss/channel/wp:wxr_version' );
		if ( ! $wxr_version ) {
			return new WP_Error( 'WXR_parse_error', __( 'This does not appear to be a WXR file, missing/invalid WXR version number', 'wordpress-importer' ) );
		}

		$wxr_version = (string) trim( $wxr_version[0] );
		// confirm that we are dealing with the correct file format.
		if ( ! preg_match( '/^\d+\.\d+$/', $wxr_version ) ) {
			return new WP_Error( 'WXR_parse_error', __( 'This does not appear to be a WXR file, missing/invalid WXR version number', 'wordpress-importer' ) );
		}

		$namespaces = $xml->getDocNamespaces();
		if ( ! isset( $namespaces['wp'] ) ) {
			$namespaces['wp'] = 'http://wordpress.org/export/1.1/';
		}
		if ( ! isset( $namespaces['excerpt'] ) ) {
			$namespaces['excerpt'] = 'http://wordpress.org/export/1.1/excerpt/';
		}

		// grab authors.
		foreach ( $xml->xpath( '/rss/channel/wp:author' ) as $author_arr ) {
			$a                 = $author_arr->children( $namespaces['wp'] );
			$login             = (string) $a->author_login;
			$authors[ $login ] = array(
				'author_id'           => (int) $a->author_id,
				'author_login'        => $login,
				'author_email'        => (string) $a->author_email,
				'author_display_name' => (string) $a->author_display_name,
				'author_first_name'   => (string) $a->author_first_name,
				'author_last_name'    => (string) $a->author_last_name,
			);
		}

		// grab posts.
		foreach ( $xml->channel->item as $item ) {
			$post = array(
				'post_title' => (string) $item->title,
				'guid'       => (string) $item->guid,
			);

			$dc                  = $item->children( 'http://purl.org/dc/elements/1.1/' );
			$post['post_author'] = (string) $dc->creator;

			$content              = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
			$excerpt              = $item->children( $namespaces['excerpt'] );
			$post['post_content'] = (string) $content->encoded;
			$post['post_excerpt'] = (string) $excerpt->encoded;

			$wp                     = $item->children( $namespaces['wp'] );
			$post['post_id']        = (int) $wp->post_id;
			$post['post_date']      = (string) $wp->post_date;
			$post['post_date_gmt']  = (string) $wp->post_date_gmt;
			$post['comment_status'] = (string) $wp->comment_status;
			$post['ping_status']    = (string) $wp->ping_status;
			$post['post_name']      = (string) $wp->post_name;
			$post['status']         = (string) $wp->status;
			$post['post_parent']    = (int) $wp->post_parent;
			$post['menu_order']     = (int) $wp->menu_order;
			$post['post_type']      = (string) $wp->post_type;
			$post['post_password']  = (string) $wp->post_password;
			$post['is_sticky']      = (int) $wp->is_sticky;

			if ( isset( $wp->attachment_url ) ) {
				$post['attachment_url'] = (string) $wp->attachment_url;
			}

			foreach ( $wp->postmeta as $meta ) {
				$post['postmeta'][] = array(
					'key'   => (string) $meta->meta_key,
					'value' => (string) $meta->meta_value,
				);
			}

			$posts[] = $post;
		}

		return $posts;
	}

	/**
	 * Get meta value from an array of meta
	 *
	 * @param string[] $meta_data Array of meta to look into.
	 * @param string   $meta_key Meta key to find its value.
	 * @return mixed The meta value if it exists, otherwise false.
	 */
	private static function get_event_meta( $meta_data, $meta_key ) {
		$meta_id = array_search( $meta_key, array_column( $meta_data, 'key' ) );
		if ( false === $meta_id ) {
			return false;
		}

		return $meta_data[ $meta_id ]['value'];
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
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
