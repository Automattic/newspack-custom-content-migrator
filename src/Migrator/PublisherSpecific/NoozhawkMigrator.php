<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Migrator\General\SubtitleMigrator;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use \WP_Error;
use \DOMDocument;

/**
 * Custom migration scripts for Noozhawk.
 */
class NoozhawkMigrator implements InterfaceMigrator {
	// Logs.
	const AUTHORS_LOGS = 'NH_authors.log';
	const EXCERPT_LOGS = 'NH_authors.log';

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
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
						'name'        => 'events-csv-output-path',
						'description' => 'CSV output path.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
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

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d co-authors took %d mins.', count( $co_authors_added ), floor( ( microtime( true ) - $time_start ) / 60 ) ) );
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
		$events_csv_output_path = $assoc_args['events-csv-output-path'] ?? null;

		if ( ! file_exists( $events_xml_path ) ) {
			WP_CLI::error( sprintf( 'Events XML export %s not found.', $events_xml_path ) );
		}

		$raw_events = $this->parse_XML_events( $events_xml_path );

		$events = array_map(
			function( $event ) {
				preg_match( '/\$\s*(?<price>\d+)/', self::get_event_meta( $event['postmeta'], 'event_price' ), $price_matches );
				$has_price = array_key_exists( 'price', $price_matches );

				return [
					$event['post_title'],
					self::get_event_meta( $event['postmeta'], 'event_intro' ),
					$event['Event Start Date'],
					date( 'H:i:s', strtotime( self::get_event_meta( $event['postmeta'], 'event_start' ) ) ),
					// $event['Event End Date'],
					// $event['Event End Time'],
					// $event['Timezone'],
					$event['post_content'],
					$has_price ? $price_matches['price'] : self::get_event_meta( $event['postmeta'], 'event_price' ),
					$has_price ? '$' : '',
					self::get_event_meta( $event['postmeta'], 'event_location' ),
					$event['attachment_url'],
					self::get_event_meta( $event['postmeta'], 'event_url' ),
					self::get_event_meta( $event['postmeta'], 'event_sponsors' ),
				];
			},
			$raw_events
		);

		$csv_output_file = fopen( $events_csv_output_path, 'w' );
		foreach ( $events as $event ) {
			fputcsv( $csv_output_file, $event );
		}

		fclose( $csv_output_file );

		WP_CLI::line( sprintf( 'The XML content was successfully migrated to a CSV file: %s', $events_csv_output_path ) );
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
