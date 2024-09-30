<?php

namespace NewspackCustomContentMigrator\Command\General;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;
use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\Logger;
use stdClass;
use WP_User;
use WP_CLI;

/**
 * Class VillageMediaCMSMigrator.
 * General purpose importer for Village Media CMS XML files.
 *
 * @package NewspackCustomContentMigrator\Command\General
 */
class VillageMediaCMSMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments|null Attachments instance.
	 */
	protected ?Attachments $attachments;

	/**
	 * Gutenberg block generator.
	 *
	 * @var GutenbergBlockGenerator|null
	 */
	protected ?GutenbergBlockGenerator $block_generator;
	
	/**
	 * CoAuthorsPlus.
	 *
	 * @var CoAuthorsPlusHelper
	 */
	private CoAuthorsPlusHelper $cap;
	
	/**
	 * Posts.
	 *
	 * @var Posts
	 */
	private Posts $posts;
	
	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Singleton constructor.
	 */
	private function __construct() {
		$this->attachments     = new Attachments();
		$this->block_generator = new GutenbergBlockGenerator();
		$this->cap             = new CoAuthorsPlusHelper();
		$this->posts           = new Posts();
		$this->logger          = new Logger();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-migrate-xmls',
			self::get_command_closure( 'cmd_migrate_xmls' ),
			[
				'shortdesc' => 'Migrates XML files from Chula Vista.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file-path',
						'description' => 'Path to XML file.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start-at-row-number',
						'description' => 'Row number to start at.',
						'optional'    => true,
						'default'     => 0,
					],
					[
						'type'        => 'assoc',
						'name'        => 'timezone',
						'description' => 'Timezone to use for dates.',
						'optional'    => true,
						'default'     => 'America/New_York',
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator village-cms-dev-helper-get-consolidated-users',
			self::get_command_closure( 'cmd_dev_helper_get_consolidated_users' ),
			[
				'shortdesc' => 'Composes a usable data file for VillageMedia consolidated users based on a spreadsheet.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-dev-helper-get-consolidated-data-file',
			self::get_command_closure( 'cmd_dev_helper_get_consolidated_data_file' ),
			[
				'shortdesc' => 'Composes a custom data file for VillageMedia which contains all relevant XML and WP post data to update authorships. As opposed to feeding an XML file, this file can be run directly on Atomic (XML memory overflows).',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'data-xml',
						'description' => 'Path to original Village Media CMS XML data file.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'additional-consolidated-users',
						'description' => "Optional, used for a specific work flow. Path to PHP file which returns an array of additionally consolidated user display names. Consolidated means that some user names are 'cleaned up' and a new user name now gets to replace the old, e.g. 'John Doe' will be used instead of old name 'John Doe / Contributor'. Keys are old names, values are new names.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'bylines-special-cases-php-file',
						'description' => 'Optional. Path to PHP file which returns an array of specifically/manually split byline strings into individual author names.',
						'optional'    => true,
						'repeating'   => false,
					],
				],

			]
			);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-dev-helper-fix-consolidated-users',
			self::get_command_closure( 'cmd_dev_helper_fix_consolidated_users' ),
			[
				'shortdesc' => 'Fixes Post authors on all already imported posts according to this rule: if <attributes> byline exists use that for author, otherwise if <byline> node exists use that, and lastly if previous do not exist use <author> node for author. Run command village-cms-dev-helper-get-consolidated-data-file first which produces a compact authorship data file which can run directly on Atomic.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'data-csv',
						'description' => 'Path to consolidated data CSV file created by cmd_dev_helper_get_consolidated_data_file.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'additional-consolidated-users',
						'description' => "Optional, used for a specific work flow. Path to PHP file which returns an array of additionally consolidated user display names. Consolidated means that some user names are 'cleaned up' and a new user name now gets to replace the old, e.g. 'John Doe' will be used instead of old name 'John Doe / Contributor'. Keys are old names, values are new names.",
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-dev-helper-validate-all-authorship',
			self::get_command_closure( 'cmd_dev_helper_validate_all_authorship' ),
			[
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'data-xml',
						'description' => 'Path to original XML file.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'additional-consolidated-users',
						'description' => "Optional, used for a specific work flow. Path to PHP file which returns an array of additionally consolidated user display names. Consolidated means that some user names are 'cleaned up' and a new user name now gets to replace the old, e.g. 'John Doe' will be used instead of old name 'John Doe / Contributor'. Keys are old names, values are new names.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'ignore-post-ids-csv',
						'description' => 'Optional list of Post IDs to ignore during validation.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Migrates XML files from Village Media Export.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws Exception When the XML file cannot be loaded, or timezone is invalid.
	 */
	public function cmd_migrate_xmls( $args, $assoc_args ) {
		global $wpdb;

		$file_path = $args[0];

		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $file_path ), LIBXML_PARSEHUGE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents         = $dom->getElementsByTagName( 'content' );
		$row_number_start = $assoc_args['start-at-row-number'];
		$gmt_timezone     = new DateTimeZone( 'GMT' );

		foreach ( $contents as $row_number => $content ) {
			/* @var DOMElement $content */

			if ( $row_number < $row_number_start ) {
				continue;
			}

			echo WP_CLI::colorize( "Row number: %B{$row_number}%n\n" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			$post_data = [
				'post_author'       => 0,
				'post_date'         => '',
				'post_date_gmt'     => '',
				'post_content'      => '',
				'post_title'        => '',
				'post_excerpt'      => '',
				'post_status'       => '',
				'post_type'         => 'post',
				'post_name'         => '',
				'post_modified'     => '',
				'post_modified_gmt' => '',
				'post_category'     => [],
				'tags_input'        => [],
				'meta_input'        => [],
			];

			$images      = [];
			$has_gallery = false;

			foreach ( $content->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '#text' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}

				switch ( $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					case 'id':
						$post_data['meta_input']['original_article_id'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_imported                                  = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT post_id 
								FROM $wpdb->postmeta 
								WHERE meta_key = 'original_article_id' 
								  AND meta_value = %s",
								$node->nodeValue // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							)
						); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

						if ( ! is_null( $post_imported ) ) {
							WP_CLI::log( 'Post already imported, skipping...' );
							continue 3;
						}
						break;
					case 'title':
						$post_data['post_title'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						WP_CLI::log( 'Post Title: ' . $node->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'slug':
						$post_data['post_name'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'dateupdated':
						$date                       = $this->get_date_time( $node->nodeValue, $assoc_args['timezone'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_data['post_modified'] = $date->format( 'Y-m-d H:i:s' );
						$date->setTimezone( $gmt_timezone );
						$post_data['post_modified_gmt'] = $date->format( 'Y-m-d H:i:s' );
						break;
					case 'datepublish':
						$date                   = $this->get_date_time( $node->nodeValue, $assoc_args['timezone'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_data['post_date'] = $date->format( 'Y-m-d H:i:s' );
						$date->setTimezone( $gmt_timezone );
						$post_data['post_date_gmt'] = $date->format( 'Y-m-d H:i:s' );
						$post_data['post_status']   = 'publish';
						break;
					case 'intro':
						$post_data['post_excerpt']                         = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$post_data['meta_input']['newspack_post_subtitle'] = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'description':
						$post_data['post_content'] = '<!-- wp:html -->' . $node->nodeValue . '<!-- /wp:html -->'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'author':
						$author = $this->handle_author( $node );

						if ( ! is_null( $author ) ) {
							$post_data['post_author'] = $author->ID;
						}

						break;
					case 'tags':
						foreach ( $node->childNodes as $tag ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							if ( '#text' === $tag->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
								continue;
							}
							$term = $this->handle_tag( $tag );

							if ( 'category' === $term['type'] ) {
								$post_data['post_category'][] = $term['term_id'];
							} elseif ( 'post_tag' === $term['type'] ) {
								$post_data['tags_input'][] = $term['term_id'];
							}
						}

						break;
					case 'medias':
						foreach ( $node->childNodes as $media ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							if ( '#text' === $media->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
								continue;
							}

							$images[] = $media;
						}

						break;
					case 'gallery':
						$has_gallery = true;
						break;
				}
			}

			$post_id = wp_insert_post( $post_data, true );

			if ( ! is_wp_error( $post_id ) ) {

				$attachment_ids = [];
				foreach ( $images as $image ) {
					$attachment = $this->handle_media( $image, $post_id, $assoc_args['timezone'] );

					if ( $attachment['is_gallery_item'] && ! is_null( $attachment ) ) {
						$attachment_ids[] = $attachment['attachment_id'];
					}
				}

				if ( $has_gallery && ! empty( $attachment_ids ) ) {
					$post_data['ID']            = $post_id;
					$post_data['post_content'] .= serialize_blocks(
						[
							$this->block_generator->get_gallery(
								$attachment_ids,
								3,
								'full',
								'none',
								true
							),
						]
					);
					wp_update_post( $post_data );
				}
			}
		}

		WP_CLI::warning( 'NOTE -- make sure to run `newspack-content-migrator village-cms-dev-helper-fix-consolidated-users` which assigns and fixes authors based on following rule -- if <attributes byline> string exists in <content> use it for authorship ; otherwise if <byline> node exists in <content> use that for authorship ; lastly if none of the previous exist use <author> node for authorship. If not deciding to run these author fixing commands, double check wp_posts.post_author = 0 entries which if linger will make CAP-set authorship invalid.' );
	}

	/**
	 * Get data from CSV file.
	 *
	 * @param string $file_path Path to CSV file.
	 * @return array
	 */
	public function get_csv_data_TEMP( string $file_path ): array {
		$data        = [];
		$file_handle = fopen( $file_path, 'r' );
		$key = 0;
		while ( ( $row = fgetcsv( $file_handle ) ) !== false ) {
			if ( 0 === $key ) {
				$key++;
				continue;
			}
			$data[] = $row;
		}
		fclose( $file_handle );

		return $data;
	}

	public function save_array_to_csv( $data, $file_path ) {
		
		// Validate if every subarray has the same keys/headers
		$columns = array_keys( reset( $data ) );
		foreach ( $data as $row ) {
			if ( array_keys( $row ) !== $columns ) {
				return false;
			}
		}
	
		$handle = fopen( $file_path, 'w' );
		if ( false === $handle ) {
			return false;
		}

		// Write CSV data.
		fputcsv( $handle, $columns );
		foreach ( $data as $row ) {
			fputcsv( $handle, $row );
		}

		fclose( $handle );

		return true;
	}

	public function read_csv_file( $file_path ) {
	
		$rows = [];
		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			$headers = fgetcsv( $handle );
	
			// Remaining rows.
			while ( ( $data = fgetcsv( $handle ) ) !== false ) {
				// Combine headers with data to create associative array.
				$row = array_combine( $headers, $data );
				$rows[] = $row;
			}
	
			fclose( $handle );
		}
	
		return $rows;
	}

	public function cmd_dev_helper_get_consolidated_data_file( $pos_args, $assoc_args ) {
		
		global $wpdb;

		// VillageMedia XML file.
		$xml_file = $assoc_args['data-xml'];
		// Path to file output by cmd_dev_helper_get_consolidated_users.
		$consolidated_user_display_names = [];
		if ( isset( $pos_args['additional-consolidated-users'] ) ) {
			$consolidated_user_display_names = include $pos_args['additional-consolidated-users'];
		}

		// You can provide some specific bylines and how they should be split in a "manual" fashion (for those completely irregular bylines).
		$bylines_special_cases = [];
		if ( isset( $assoc_args['bylines-special-cases-php-file'] ) && file_exists( $assoc_args['bylines-special-cases-php-file'] ) ) {
			$bylines_special_cases = include $assoc_args['bylines-special-cases-php-file'];
		}


		// Loop through content nodes and compose data.
		$data = [];
		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $xml_file ), LIBXML_PARSEHUGE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = $dom->getElementsByTagName( 'content' );
		$not_found_original_article_id = [];
		foreach ( $contents as $key_content => $content ) {
			WP_CLI::line( sprintf( '%d/%d', $key_content + 1, $contents->length ) );

			// Get original id, author node and byline attribute.
			$original_article_id = null;
			$byline              = null;
			$byline_attribute    = null;
			$author_node         = null;
			foreach ( $content->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '#text' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}
				switch ( $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					case 'id':
						$original_article_id = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'author':
						$author_node = $node;
						break;
					case 'byline':
						$byline_node = $node; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'attributes':
						$attributes = json_decode( $node->nodeValue, true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( isset( $attributes['byline'] ) && ! empty( $attributes['byline'] ) ) {
							$byline_attribute = $attributes['byline'];
						}
						break;
				}
			}
			if ( ! $original_article_id ) {
				WP_CLI::error( sprintf( 'ERROR original_article_id not found in key_content %d', $key_content ) );
				continue;
			}
			if ( ! $byline && ! $author_node ) {
				WP_CLI::error( sprintf( 'ERROR neither byline nor author_node found in key_content %d', $key_content ) );
				continue;
			}

			// Collect all XML sourced data in $data_row array.
			$data_row = [
				'original_article_id' => $original_article_id,
			];
			$author_data = $this->get_author_data_from_author_node( $author_node );
			$author_display_name = $author_data['first_name'] . ' '. $author_data['last_name'];
			$data_row['author'] = $author_display_name;
			$data_row['author_consolidated'] = isset( $consolidated_user_display_names[ $author_display_name ] ) ? $consolidated_user_display_names[ $author_display_name ] : $author_display_name;
			$data_row['author_consolidated'] = isset( $consolidated_user_display_names[ trim( $author_display_name ) ] ) ? $consolidated_user_display_names[ trim( $author_display_name ) ] : $author_display_name;
			$byline_names_split = [];
			// Is there a byline attribute?
			if ( $byline_attribute ) {
				if ( isset( $bylines_special_cases[ $byline_attribute ] ) ) {
					$byline_names_split = $bylines_special_cases[ $byline_attribute ];
				} elseif ( isset( $bylines_special_cases[ trim( $byline_attribute ) ] ) ) {
					$byline_names_split = $bylines_special_cases[ trim( $byline_attribute ) ];
				} else {
					// Split the byline into multiple author names.
					$byline_names_split = $this->split_byline( $byline_attribute );
				}
			} elseif ( $byline_node ) {
				// Is there a byline node?
				$author_data = $this->get_author_data_from_author_node( $byline_node );
				$author_display_name = $author_data['first_name'] . ' '. $author_data['last_name'];
				if ( ! empty( trim( $author_display_name ) ) ) {
					$author_display_name = isset( $consolidated_user_display_names[ $author_display_name ] ) ? $consolidated_user_display_names[ $author_display_name ] : $author_display_name;
					$author_display_name = isset( $consolidated_user_display_names[ trim( $author_display_name ) ] ) ? $consolidated_user_display_names[ trim( $author_display_name ) ] : $author_display_name;
					$byline_names_split = [ $author_display_name ];
				}
			}
			$data_row['byline'] = $byline;
			$data_row['byline_count'] = count( $byline_names_split );
			$data_row['byline_split_csv'] = implode( ',', $byline_names_split );
			$data_row['byline_split_consolidated_csv'] = implode(
				',',
				array_map(
					function( $display_name ) use ( $consolidated_user_display_names ) {
						$display_name = isset( $consolidated_user_display_names[ $display_name ] ) ? $consolidated_user_display_names[ $display_name ] : $display_name;
						$display_name = isset( $consolidated_user_display_names[ trim( $display_name ) ] ) ? $consolidated_user_display_names[ trim( $display_name ) ] : $display_name;
						return $display_name;
					},
					$byline_names_split
				)
			);

			// Get post ID.
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT wpm.post_id FROM {$wpdb->postmeta} wpm JOIN {$wpdb->posts} wp ON wp.ID = wpm.post_id WHERE wpm.meta_key = 'original_article_id' AND wpm.meta_value = %s AND wp.post_type = 'post'", $original_article_id ) );
			if ( ! $post_id ) {
				WP_CLI::warning( sprintf( 'ERROR Post not found for original_article_id %s', $original_article_id ) );
				$not_found_original_article_id[] = $original_article_id;
				continue;
			}
			$data_row['post_id'] = $post_id;
			$post_author = $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
			$data_row['post_author'] = $post_author;
			$post_author_display_name = null;
			if ( $post_author ) {
				$post_author_display_name = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$wpdb->users} WHERE ID = %d", $post_author ) );
			}
			$data_row['post_author_display_name'] = $post_author_display_name;
			$data_row['post_author_display_name_consolidated'] = isset( $consolidated_user_display_names[ $post_author_display_name ] ) ? $consolidated_user_display_names[ $post_author_display_name ] : $post_author_display_name;
			$data_row['post_author_display_name_consolidated'] = ( ! empty( $post_author_display_name ) && $post_author_display_name ) && isset( $consolidated_user_display_names[ trim( $post_author_display_name ) ] ) ? $consolidated_user_display_names[ trim( $post_author_display_name ) ] : $post_author_display_name;
			$current_authors = $this->cap->get_all_authors_for_post( $post_id );
			$current_ga_names_csv = implode( ',', array_map( fn( $author ) => $author->display_name, $current_authors ) );
			$data_row['current_ga_names_csv'] = $current_ga_names_csv;
			$current_ga_names_consolidated_csv = implode(
				',',
				array_map(
					function ( $current_author ) use ( $consolidated_user_display_names ) {
						$display_name_consolidated = isset( $consolidated_user_display_names[ $current_author->display_name ] ) ? $consolidated_user_display_names[ $current_author->display_name ] : $current_author->display_name;
						$display_name_consolidated = isset( $consolidated_user_display_names[ trim( $current_author->display_name ) ] ) ? $consolidated_user_display_names[ trim( $current_author->display_name ) ] : $current_author->display_name;
						return $display_name_consolidated;
					},
					$current_authors
				)
			);
			$data_row['current_ga_names_consolidated_csv'] = $current_ga_names_consolidated_csv;
			$current_ga_names_ids = implode( ',', array_map( fn( $author ) => $author->ID, $current_authors ) );
			$data_row['current_ga_ids_csv'] = $current_ga_names_ids;

			$data[] = $data_row;

		}

		$path = dirname( $xml_file ) . '/data.csv';
		if ( false === $this->save_array_to_csv( $data, $path ) ) {
			WP_CLI::warning( sprintf( 'ERROR saving %s', $path ) );
		} {
			WP_CLI::success( sprintf( 'Saved %s', $path ) );
		}
		
		
		if ( ! empty( $not_found_original_article_id ) ) {
			WP_CLI::warning( sprintf( '$not_found_original_article_id %s', count( $not_found_original_article_id ) ) );
			$path_not_found_original_article_id = dirname( $xml_file ) . '/not_found_original_article_id.csv';
			if ( false === $this->save_array_to_csv( $not_found_original_article_id, $path_not_found_original_article_id ) ) {
				WP_CLI::warning( sprintf( 'ERROR saving %s', $path_not_found_original_article_id ) );
			} {
				WP_CLI::success( sprintf( 'Saved %s', $path_not_found_original_article_id ) );
			}
		}
		
		WP_CLI::warning( sprintf( 'Total content nodes %s, data elements %s', $key_content + 1, count( $data ) ) );
	}

	public function cmd_dev_helper_get_consolidated_users( $pos_args, $assoc_args ) {
		
		// Consolidated users sheet, columns: ID,display_name,replaced_by_id,new_display_name
		$csv_authors_consolidated_path = $pos_args[0];
		$authors_data = $this->read_csv_file( $csv_authors_consolidated_path );
		
		// Create an array of consolidated user names, key old name, value new name.
		$authors_names_consolidated = [];

		// First map kept users: id => new name.
		$authors_ids_names = [];
		foreach ( $authors_data as $key_row => $row ) {
			$id               = $row['ID'];
			$display_name     = $row['display_name'];
			$replaced_by_id   = $row['replaced_by_id'];
			$new_display_name = $row['new_display_name'];
			if ( 'kept' == $replaced_by_id ) {
				if ( ! empty( $new_display_name ) ) {
					$authors_ids_names[ $id ] = $new_display_name;
				} else {
					$authors_ids_names[ $id ] = $display_name;
				}
			}
		}

		// Then loop through merged authors, and map them to new ones: id => new id.
		foreach ( $authors_data as $key_row => $row ) {
			$id               = $row['ID'];
			$display_name     = $row['display_name'];
			$replaced_by_id   = $row['replaced_by_id'];
			$new_display_name = $row['new_display_name'];
			if ( 'kept' != $replaced_by_id ) {
				$authors_ids_names[ $id ] = $authors_ids_names[ $replaced_by_id ];
			}
		}

		// Then create an array of consolidated names: old name => new name.
		foreach ( $authors_data as $key_row => $row ) {
			$id               = $row['ID'];
			$display_name     = $row['display_name'];
			if ( $display_name != $authors_ids_names[ $id ] ) {
				$authors_names_consolidated[ $display_name ] = $authors_ids_names[ $id ];
			}
		}

		// Save the resulting array to a .php file which can be simply included.
		$path = dirname( $csv_authors_consolidated_path ) . '/authors_names_consolidated.php';
		file_put_contents( $path ,  "<?php\nreturn " . var_export( $authors_names_consolidated, true ) . ";\n" );
		WP_CLI::success( sprintf( 'Saved %s', $path ) );
	}

	public function cmd_dev_helper_fix_consolidated_users( $pos_args, $assoc_args ) {
		global $wpdb;
		$log = 'cmd_dev_helper_fix_consolidated_users.log';

		/**
		 * Columns
		 * original_article_id
		 * author
		 * author_consolidated
		 * byline
		 * byline_count
		 * byline_split_csv
		 * byline_split_consolidated_csv
		 * post_id
		 * post_author
		 * post_author_display_name
		 * post_author_display_name_consolidated
		 * current_ga_names_csv
		 * current_ga_names_consolidated_csv
		 * current_ga_ids_csv@param [type] $pos_args
		 */
		$data = $this->read_csv_file( $assoc_args['data-csv'] );
		$additional_consolidated_names = $assoc_args['additional-consolidated-users'] ? include $assoc_args['additional-consolidated-users'] : [];

		/**
		 * If there is no byline 
		 * 		use <author> node for authorship, no CAP taxonomy
		 * 		set wp_posts.post_author to <author> node user
		 * 		unset all GAs from post
		 * elseif there's only one author in byline
		 * 		use single byline user for authorship, no CAP taxonomy
		 * 		set wp_posts.post_author to single byline user
		 * 		unset all GAs from post
		 * elseif there are multiple authors in byline
		 * 		use CAP taxonomy for multiple authors from byline
		 * 		set wp_posts.post_author to first byline user
		 *		set byline users as GAs using CAP
		 */
		foreach ( $data as $key_row => $row ) {
			WP_CLI::line( sprintf( '%d/%d post_ID %d original_article_id', $key_row + 1, count( $data ), $row['post_id'], $row['original_article_id'] ) );

			// Get fresh post_author (Embarcadero folks have been updating in the mean time).
			$row['post_author'] = $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d", $row['post_id'] ) );
			
			// There is no byline.
			if ( $row['byline_count'] == 0 ) {
				/**
				 * There is no byline, <author> node is used
				 * - wp_posts.post_author is used for authorship and set to <author>
				 * - CAP taxonomy is not used
				 */ 
				
				// Unset all GAs from post.
				$this->cap->unassign_all_guest_authors_from_post( $row['post_id'] );
				
				// Check if post_author needs to be updated.
				$display_name = isset( $additional_consolidated_names[ $row['author_consolidated'] ] ) ? $additional_consolidated_names[ $row['author_consolidated'] ] : $row['author_consolidated'];
				$display_name = isset( $additional_consolidated_names[ trim( $row['author_consolidated'] ) ] ) ? $additional_consolidated_names[ trim( $row['author_consolidated'] ) ] : $row['author_consolidated'];

				// Check if post_author needs to be updated.
				$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", $display_name ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				if ( ! $author_id ) {
					$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", trim( $display_name ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				}
				if ( ! $author_id ) {
					$this->logger->log( $log, sprintf( "author_node_is_author WARNING, post ID %d original_article_id %s, author node to post_author '%s' WP_User not found, about to create", $row['post_id'], $row['original_article_id'], $display_name ) );
					// Create WP_User.
					$author_id = $this->create_wp_user_from_display_name( trim( $display_name ), $log );
					if ( ! $author_id || is_wp_error( $author_id ) ) {
						$this->logger->log( $log, sprintf( "author_node_is_author ERROR, post ID %d original_article_id %s, could not create_wp_user_from_display_name('%s'), err.msg.: %s", $row['post_id'], $row['original_article_id'], $display_name, is_wp_error( $author_id ) ? $author_id->get_error_message() : 'n/a' ) );
						continue;
					}
					$this->logger->log( $log, sprintf( "author_node_is_author CREATED NEW USER, post ID %d original_article_id %s, name '%s'", $row['post_id'], $row['original_article_id'], trim( $display_name ) ) );
				}
				if ( $row['post_author'] != $author_id ) {
					// Set wp_posts.post_author to <author> node user.
					$updated = $wpdb->update(
						$wpdb->posts,
						[ 'post_author' => $author_id ],
						[ 'ID' => $row['post_id'] ]
					);
					if ( false === $updated || 0 === $updated ) {
						$this->logger->log( $log, sprintf( "author_node_is_author ERROR, post ID %d original_article_id %s, error updating wp_posts.post_author %s, updated='%s'", $row['post_id'], $row['original_article_id'], $author_id, json_encode( $updated ) ) );
						continue;
					}
					
					$this->logger->log( $log, sprintf( "author_node_is_author UPDATED, post ID %d original_article_id %s, post_author_old %s post_author_new %s", $row['post_id'], $row['original_article_id'], $row['post_author'], $author_id ) );
				} else {
					$this->logger->log( $log, sprintf( "author_node_is_author SKIPPING, post ID %d, post_author %s is correct", $row['post_id'], $row['post_author'] ) );
				}

			} elseif ( 1 == $row['byline_count'] ) {
				/**
				 * There's a single byline author:
				 * - wp_posts.post_author is used for authorship and set to the byline author
				 * - CAP taxonomy is not used
				 */

				// Unassign all GAs from post.
				$this->cap->unassign_all_guest_authors_from_post( $row['post_id'] );
				
				// Get byline name -- just first element.
				$byline_names = explode( ',', $row['byline_split_consolidated_csv'] );
				$display_name = isset( $additional_consolidated_names[ $byline_names[0] ] ) ? $additional_consolidated_names[ $byline_names[0] ] : $byline_names[0];
				$display_name = isset( $additional_consolidated_names[ trim( $byline_names[0] ) ] ) ? $additional_consolidated_names[ trim( $byline_names[0] ) ] : $byline_names[0];

				// Check if post_author needs to be updated.
				$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", $display_name ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				if ( ! $author_id ) {
					$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", trim( $display_name ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				}
				if ( ! $author_id ) {
					$this->logger->log( $log, sprintf( "byline_one_author WARNING, post ID %d original_article_id %s, byline single user to post_author '%s' WP_User not found, about to create", $row['post_id'], $row['original_article_id'], $display_name ) );
					// Create WP_User.
					$author_id = $this->create_wp_user_from_display_name( trim( $display_name ), $log );
					if ( ! $author_id || is_wp_error( $author_id ) ) {
						$this->logger->log( $log, sprintf( "byline_one_author ERROR, post ID %d original_article_id %s, could not create_wp_user_from_display_name('%s'), err.msg.: %s", $row['post_id'], $row['original_article_id'], $display_name, is_wp_error( $author_id ) ? $author_id->get_error_message() : 'n/a' ) );
						continue;
					}
					$this->logger->log( $log, sprintf( "byline_one_author CREATED NEW USER, post ID %d original_article_id %s, '%s'", $row['post_id'], $row['original_article_id'], trim( $display_name ) ) );
				}
				if ( $row['post_author'] != $author_id ) {
					// Set wp_posts.post_author to single byline user.
					$updated = $wpdb->update(
						$wpdb->posts,
						[ 'post_author' => $author_id ],
						[ 'ID' => $row['post_id'] ]
					);
					if ( false === $updated || 0 === $updated ) {
						$this->logger->log( $log, sprintf( "byline_one_author ERROR, post ID %d original_article_id %s, error updating wp_posts.post_author %s, updated='%s'", $row['post_id'], $row['original_article_id'], $author_id, json_encode( $updated ) ) );
						continue;
					}

					$this->logger->log( $log, sprintf( "byline_one_author UPDATED, post ID %d original_article_id %s, post_author_old %s post_author_new %s", $row['post_id'], $row['original_article_id'], $row['post_author'], $author_id ) );
				} else {
					$this->logger->log( $log, sprintf( "byline_one_author SKIPPING, post ID %d original_article_id %s, post_author %s is correct", $row['post_id'], $row['original_article_id'], $row['post_author'] ) );
				}

			} elseif ( $row['byline_count'] > 1 ) {
				/**
				 * There are multiple byline authors
				 * - wp_posts.post_author is set to first byline author, but not used for authorship
				 * - CAP GAs are used for authorship
				 */

				// Get byline names.
				$byline_names = explode( ',', $row['byline_split_consolidated_csv'] );
				$display_name_first = isset( $additional_consolidated_names[ $byline_names[0] ] ) ? $additional_consolidated_names[ $byline_names[0] ] : $byline_names[0];
				$display_name_first = isset( $additional_consolidated_names[ trim( $byline_names[0] ) ] ) ? $additional_consolidated_names[ trim( $byline_names[0] ) ] : $byline_names[0];

				// Check if post_author needs to be updated.
				$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", $display_name_first ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				if ( ! $author_id ) {
					$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", trim( $display_name_first ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				}
				if ( ! $author_id ) {
					$this->logger->log( $log, sprintf( "byline_multiple_authors WARNING, post ID %d original_article_id %s, byline single user to post_author '%s' WP_User not found, about to create", $row['post_id'], $row['original_article_id'], $display_name_first ) );
					// Create WP_User.
					$author_id = $this->create_wp_user_from_display_name( trim( $display_name_first ), $log );
					if ( ! $author_id || is_wp_error( $author_id ) ) {
						$this->logger->log( $log, sprintf( "byline_multiple_authors ERROR, post ID %d original_article_id %s, could not create_wp_user_from_display_name('%s'), err.msg.: %s", $row['post_id'], $row['original_article_id'], $display_name_first, is_wp_error( $author_id ) ? $author_id->get_error_message() : 'n/a' ) );
						continue;
					}
					$this->logger->log( $log, sprintf( "byline_multiple_authors CREATED NEW USER, post ID %d original_article_id %s, '%s'", $row['post_id'], $row['original_article_id'], trim( $display_name_first ) ) );
				}
				if ( $row['post_author'] != $author_id ) {
					// Set wp_posts.post_author to first byline user.
					$updated = $wpdb->update(
						$wpdb->posts,
						[ 'post_author' => $author_id ],
						[ 'ID' => $row['post_id'] ]
					);
					if ( false === $updated || 0 === $updated ) {
						$this->logger->log( $log, sprintf( "byline_multiple_authors ERROR, post ID %d original_article_id %s, error updating wp_posts.post_author %s, updated='%s'", $row['post_id'], $row['original_article_id'], $author_id, json_encode( $updated ) ) );
						continue;
					}
					
					$this->logger->log( $log, sprintf( "byline_multiple_authors UPDATED, post ID %d original_article_id %s, post_author_old %s post_author_new %s", $row['post_id'], $row['original_article_id'], $row['post_author'], $author_id ) );
				} else {
					$this->logger->log( $log, sprintf( "byline_multiple_authors SKIPPING, post ID %d original_article_id %s, post_author %s is correct", $row['post_id'], $row['original_article_id'], $row['post_author'] ) );
				}
				
				// Set multiple byline users as GAs using CAP.
				$authors = [];
				foreach ( $byline_names as $byline_name ) {
					$byline_name = isset( $additional_consolidated_names[ $byline_name ] ) ? $additional_consolidated_names[ $byline_name ] : $byline_name;
					$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", $byline_name ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
					if ( ! $author_id ) {
						$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", trim( $byline_name ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
					}
					if ( ! $author_id ) {
						$this->logger->log( $log, sprintf( "byline_multiple_authors WARNING, post ID %d original_article_id %s, one of GAs '%s' WP_User not found, about to create", $row['post_id'], $row['original_article_id'], $byline_name ) );
						// Create WP_User.
						$author_id = $this->create_wp_user_from_display_name( trim( $byline_name ), $log );
						if ( ! $author_id || is_wp_error( $author_id ) ) {
							$this->logger->log( $log, sprintf( "byline_multiple_authors ERROR, post ID %d original_article_id %s, could not create_wp_user_from_display_name('%s'), err.msg.: %s", $row['post_id'], $row['original_article_id'], $byline_name, is_wp_error( $author_id ) ? $author_id->get_error_message() : 'n/a' ) );
							continue;
						}
						$this->logger->log( $log, sprintf( "byline_multiple_authors CREATED NEW USER, post ID %d original_article_id %s, '%s'", $row['post_id'], $row['original_article_id'], trim( $byline_name ) ) );
					}
					$author = get_user_by( 'ID', $author_id );
					$authors[] = $author;
				}
				$this->cap->assign_authors_to_post( $authors, $row['post_id'], false );
				
				$this->logger->log( $log, sprintf( "byline_multiple_authors UPDATED, post ID %d original_article_id %s, assigned total %s GAs", $row['post_id'], $row['original_article_id'], count( $authors ) ) );
			}
		}

		wp_cache_flush();
	}

	public function cmd_dev_helper_validate_all_authorship( $pos_args, $assoc_args ) {

		global $wpdb;
		$log = 'cmd_dev_helper_validate_all_authorship.log';
		
		// Arguments.
		$xml_file = $assoc_args['data-xml'];
		$additional_consolidated_names = isset( $assoc_args['additional-consolidated-users'] ) ? include $assoc_args['additional-consolidated-users'] : [];
		$ignore_post_ids = isset( $assoc_args['ignore-post-ids-csv'] ) ? explode( ',', $assoc_args['ignore-post-ids-csv'] ) : [];

		// Loop through content nodes and compose data.
		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $xml_file ), LIBXML_PARSEHUGE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = $dom->getElementsByTagName( 'content' );
		foreach ( $contents as $key_content => $content ) {
			WP_CLI::line( sprintf( '%d/%d', $key_content + 1, $contents->length ) );

			// Get original id, author node and byline attribute.
			$original_article_id = null;
			$byline              = null;
			$byline_attribute    = null;
			$author_node         = null;
			foreach ( $content->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( '#text' === $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					continue;
				}
				switch ( $node->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					case 'id':
						$original_article_id = $node->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'author':
						$author_node = $node;
						break;
					case 'byline':
						$byline_node = $node; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						break;
					case 'attributes':
						$attributes = json_decode( $node->nodeValue, true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( isset( $attributes['byline'] ) && ! empty( $attributes['byline'] ) ) {
							$byline_attribute = $attributes['byline'];
						}
						break;
				}
			}
			if ( ! $original_article_id ) {
				WP_CLI::error( sprintf( 'ERROR original_article_id not found in key_content %d', $key_content ) );
				continue;
			}
			if ( ! $byline && ! $author_node ) {
				WP_CLI::error( sprintf( 'ERROR neither byline nor author_node found in key_content %d', $key_content ) );
				continue;
			}

			// Get author data.
			$author_data = $this->get_author_data_from_author_node( $author_node );
			$author_node_display_name = $author_data['first_name'] . ' '. $author_data['last_name'];
			$author_node_display_name_consolidated = isset( $additional_consolidated_names[ $author_node_display_name ] ) ? $additional_consolidated_names[ $author_node_display_name ] : $author_node_display_name;
			$author_node_display_name_consolidated = isset( $additional_consolidated_names[ trim( $author_node_display_name ) ] ) ? $additional_consolidated_names[ trim( $author_node_display_name ) ] : $author_node_display_name;
			
			// Get bylines split into individual author names.
			$byline_names_split = [];
			// There's a byline attribute.
			if ( $byline_attribute ) {
				// Get one or multiple author names from byline.
				$byline_names_split = $this->split_byline( $byline_attribute );
			} elseif ( $byline_node ) {
				// Get byline node author name.
				$byline_node_data = $this->get_author_data_from_author_node( $byline_node );
				$byline_node_display_name = $byline_node_data['first_name'] . ' '. $byline_node_data['last_name'];
				$is_empty_byline_node_display_name = empty( trim( $byline_node_display_name ) );
				if ( ! $is_empty_byline_node_display_name ) {
					$byline_names_split = [ $byline_node_display_name ];
				}
			}
			
			// Get bylines' consolidated names.
			$byline_names_consolidated = [];
			foreach ( $byline_names_split as $byline_name ) {
				$byline_name_consolidated = isset( $additional_consolidated_names[ $byline_name ] ) ? $additional_consolidated_names[ $byline_name ] : $byline_name;
				$byline_name_consolidated = isset( $additional_consolidated_names[ trim( $byline_name ) ] ) ? $additional_consolidated_names[ trim( $byline_name ) ] : $byline_name;
				$byline_names_consolidated[] = $byline_name_consolidated;
			}


			// Get post data.
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT wpm.post_id FROM {$wpdb->postmeta} wpm JOIN {$wpdb->posts} wp ON wp.ID = wpm.post_id WHERE wpm.meta_key = 'original_article_id' AND wpm.meta_value = %s AND wp.post_type = 'post'", $original_article_id ) );
			if ( ! $post_id ) {
				$this->logger->log( $log, sprintf( 'ERROR Post not found for original_article_id %s', $original_article_id ), $this->logger::ERROR, false );
				continue;
			}
			if ( in_array( $post_id, $ignore_post_ids ) ) {
				$this->logger->log( $log, 'Skipping.', $this->logger::LINE, false );
				continue;
			}
			
			// Get post author data.
			$post_author_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
			$post_author_display_name = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$wpdb->users} WHERE ID = %d", $post_author_id ) );

			// Get post CAP taxonomy data.
			$cap_taxonomy_results = $wpdb->get_results( $wpdb->prepare( "select wtr.object_id, wtr.term_taxonomy_id from $wpdb->term_relationships wtr join $wpdb->term_taxonomy wtt on wtt.term_taxonomy_id = wtr.term_taxonomy_id and wtt.taxonomy = 'author' where wtr.object_id = %s;", $post_id ), ARRAY_A );


			/** 
			 * Validation
			 */
			
			// If there is no byline, author node is used.
			if ( 0 === count( $byline_names_consolidated ) ) {
				
				// Validate that wp_posts.post_author is set to <author>.
				if ( $author_node_display_name_consolidated != $post_author_display_name ) {
					$this->logger->log( $log, sprintf( "byline_one_author, post ID %d original_article_id %s, ERROR current post_author_display_name '%s' (ID %s) != author_node_display_name_consolidated '%s'", $post_id, $original_article_id, $post_author_display_name, $post_author_id, $author_node_display_name_consolidated ), 'line', false );
					continue;
				}
				
				// Validate that there are no GAs assigned to post.
				if ( ! empty( $cap_taxonomy_results ) ) {
					$this->logger->log( $log, sprintf( "byline_one_author, post ID %d original_article_id %s, ERROR some CAP GAs found on post object_id,wtr.term_taxonomy_id: '%s'", $post_id, $original_article_id, json_encode( $cap_taxonomy_results ) ), 'line', false );
					continue;
				}
				
			} elseif ( 1 === count( $byline_names_consolidated ) ) {
				// There's just one byline author.
				
				// Validate that wp_posts.post_author is set to byline single author.
				if ( $byline_names_consolidated[0] != $post_author_display_name ) {
					$this->logger->log( $log, sprintf( "byline_multiple_authors, post ID %d original_article_id %s, ERROR current post_author_display_name '%s' (ID %s) != byline_split_consolidated[0] '%s'", $post_id, $original_article_id, $post_author_display_name, $post_author_id, $byline_names_consolidated[0] ), 'line', false );
					continue;
				}
	
				// Validate that there are no GAs assigned to post.
				if ( ! empty( $cap_taxonomy_results ) ) {
					$this->logger->log( $log, sprintf( "byline_multiple_authors, post ID %d original_article_id %s, ERROR some GAs found on post object_id,wtr.term_taxonomy_id: '%s'", $post_id, $original_article_id, json_encode( $cap_taxonomy_results ) ), 'line', false );
					continue;
				}
				
			} elseif ( count( $byline_names_consolidated ) > 1 ) {
				// If there are multiple byline authors.
	
				// Validate that wp_posts.post_author is set to first byline.
				if ( $byline_names_consolidated[0] != $post_author_display_name ) {
					$this->logger->log( $log, sprintf( "byline_multiple_authors, post ID %d original_article_id %s, ERROR current post_author_display_name '%s' (ID %s) != byline_split_consolidated[0] '%s'", $post_id, $original_article_id, $post_author_display_name, $post_author_id, $byline_names_consolidated[0] ), 'line', false );
					continue;
				}
	
				// Validate that GAs are assigned to post.
				$cap_authors = $this->cap->get_all_authors_for_post( $post_id );
				$cap_authors_names = array_map( function ( $author ) { return $author->display_name; }, $cap_authors );
				foreach ( $cap_authors as $key_author => $cap_author ) {
					if ( $byline_names_consolidated[ $key_author ] != $cap_author->display_name ) {
						$this->logger->log( $log, sprintf( "byline_multiple_authors, post ID %d original_article_id %s, ERROR wrong CAP author key %d byline_name '%s' != CAP author name '%s', byline_names_consolidated '%s', cap_author_names '%s'", $post_id, $original_article_id, $key_author, $byline_names_consolidated[ $key_author ], $cap_author->display_name, implode( ',', $byline_names_consolidated ), implode( ',', $cap_authors_names ) ), 'line', false );
					}
				}
	
			}

		}
	}

	/**
	 * Convenience function to extract author data from an <author> node.
	 * 
	 * @param DOMElement $author Author node.
	 * 
	 * @return array $author_data {
	 *     Array with author data as required by \wp_insert_user().
	 *
	 *     @type string $user_login           The user's login username.
	 *     @type string $user_pass            User password for new users.
	 *     @type string $user_email           The user email address.
	 *     @type string $first_name           The user's first name. For new users, will be used
	 *                                        to build the first part of the user's display name
	 *                                        if `$display_name` is not specified.
	 *     @type string $last_name            The user's last name. For new users, will be used
	 *                                        to build the second part of the user's display name
	 *                                        if `$display_name` is not specified.
	 *     @type string $user_nicename        The URL-friendly user name.
	 *     @type string $role                 User's role.
	 *     @type array  $meta_input           Array of custom user meta values keyed by meta key.
	 * }
	 */
	public function get_author_data_from_author_node( DOMElement $author ): array {
		$author_data = [
			'user_login' => '',
			'user_pass'  => wp_generate_password( 12 ),
			'user_email' => '',
			'first_name' => '',
			'last_name'  => '',
			'role'       => 'author',
			'meta_input' => [],
		];

		foreach ( $author->attributes as $attribute ) {
			switch ( $attribute->nodeName ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				case 'id':
					$author_data['meta_input']['original_author_id'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'email':
					$author_data['user_email'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'firstname':
					$author_data['first_name'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'lastname':
					$author_data['last_name'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
				case 'username':
					$author_data['user_login'] = $attribute->nodeValue; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					break;
			}
		}

		return $author_data;
	}

	/**
	 * Convenience function to handle Author nodes. It will attempt to find the author first, and if none is found,
	 * it will create a new one.
	 *
	 * @param DOMElement $author Author node.
	 *
	 * @return WP_User|stdClass
	 */
	protected function handle_author( DOMElement $author ) {

		$author_data = $this->get_author_data_from_author_node( $author );

		WP_CLI::log( 'Attempting to create Author: ' . $author_data['user_login'] . ' (' . $author_data['user_email'] . ')' );

		$user = get_user_by( 'email', $author_data['user_email'] );

		if ( $user ) {
			WP_CLI::log( 'Found existing user with email: ' . $author_data['user_email'] );

			return $user;
		}

		$user = get_user_by( 'login', $author_data['user_login'] );

		if ( $user ) {
			WP_CLI::log( 'Found existing user with login: ' . $author_data['user_login'] );

			return $user;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		$user = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 
       				u.* 
				FROM $wpdb->users u INNER JOIN $wpdb->usermeta um ON u.ID = um.user_id 
				WHERE um.meta_key = 'original_author_id' 
				  AND um.meta_value = %s",
				$author_data['meta_input']['original_author_id']
			)
		);
		// phpcs:enable

		if ( $user ) {
			WP_CLI::log( 'Found existing user with original_author_id: ' . $author_data['meta_input']['original_author_id'] . ' (' . $user->ID . ')' );

			return $user;
		}

		$user_id = wp_insert_user( $author_data );
		WP_CLI::log( 'Created user: ' . $user_id );

		return get_user_by( 'id', $user_id );
	}

	/**
	 * Convenience function to handle Tag nodes and category creation.
	 *
	 * @param DOMElement $tag XML Tag node.
	 *
	 * @return array|void
	 */
	protected function handle_tag( DOMElement $tag ) {
		$tag_type  = $tag->getAttribute( 'type' );
		$tag_label = $tag->getAttribute( 'label' );

		WP_CLI::log( 'Handling tag - Type: ' . $tag_type . ' | Label: ' . $tag_label );

		if ( 'Category' === $tag->getAttribute( 'type' ) ) {
			return [
				'type'    => 'category',
				'term_id' => wp_create_category( $tag->getAttribute( 'label' ) ),
			];
		} elseif ( 'Tag' === $tag->getAttribute( 'type' ) ) {
			$post_tag = wp_create_tag( $tag->getAttribute( 'label' ) );

			if ( is_wp_error( $post_tag ) ) {
				WP_CLI::warning( 'Error creating tag: ' . $post_tag->get_error_message() );
			} else {
				return [
					'type'    => 'post_tag',
					'term_id' => $post_tag['term_id'],
				];
			}
		}

		WP_CLI::warning( 'Unknown tag type: ' . $tag_type );
	}

	/**
	 * Convenience function to handle Media nodes and image attachment creation.
	 *
	 * @param DOMElement $media XML Media node.
	 * @param int        $post_id Post ID to attach the media to.
	 * @param string     $timezone Timezone to use for the media date.
	 *
	 * @return array|null
	 * @throws Exception If the media file cannot be downloaded.
	 */
	protected function handle_media( DOMElement $media, int $post_id = 0, string $timezone = 'America/New_York' ) {
		$name = $media->getAttribute( 'name' );
		// $filename    = $media->getAttribute( 'filename' );
		$url         = $media->getAttribute( 'url' );
		$description = $media->getAttribute( 'description' );
		// $mime_type   = $media->getAttribute( 'mimetype' );
		$date = $this->get_date_time( $media->getAttribute( 'added' ), $timezone );

		$post_date = $date->format( 'Y-m-d H:i:s' );
		$date->setTimezone( new DateTimeZone( 'GMT' ) );
		$post_date_gmt = $date->format( 'Y-m-d H:i:s' );

		$attribution = $media->getAttribute( 'attribution' );

		if ( ! empty( $attribution ) ) {
			$attribution = "by $attribution";
		}

		$original_id       = $media->getAttribute( 'id' );
		$is_featured_image = (bool) intval( $media->getElementsByTagName( 'isfeatured' )->item( 0 )->nodeValue );
		$is_gallery_item   = (bool) intval( $media->getElementsByTagName( 'isgalleryitem' )->item( 0 )->nodeValue );

		$attachment_id = $this->attachments->import_external_file(
			$url,
			sanitize_title( $name ),
			$attribution,
			$description,
			null,
			$post_id,
			[
				'meta_input' => [
					'original_post_id' => $original_id,
				],
			]
		);

		if ( is_numeric( $attachment_id ) ) {
			WP_CLI::log( 'Created attachment: ' . $attachment_id );
			wp_update_post(
				[
					'ID'            => $attachment_id,
					'post_date'     => $post_date,
					'post_date_gmt' => $post_date_gmt,
				]
			);

			if ( $is_featured_image ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}

			return [
				'attachment_id'   => $attachment_id,
				'is_featured'     => $is_featured_image,
				'is_gallery_item' => $is_gallery_item,
			];
		}

		return null;
	}

	/**
	 * Attempts to parse a date time string into a DateTime object.
	 *
	 * @param string $date_time Date time string.
	 * @param string $timezone Timezone to use for the date.
	 *
	 * @return DateTime
	 * @throws Exception If the timezone cannot be parsed.
	 */
	private function get_date_time( string $date_time, string $timezone = 'America/New_York' ): DateTime {
		$date = DateTime::createFromFormat(
			'Y-m-d\TH:i:s.u',
			$date_time,
			new DateTimeZone( $timezone )
		);

		if ( is_bool( $date ) ) {
			$date = DateTime::createFromFormat(
				'Y-m-d\TH:i:s',
				$date_time,
				new DateTimeZone( $timezone )
			);

			if ( is_bool( $date ) ) {
				WP_CLI::warning( 'Unable to parse date: ' . $date_time );
				$date = new DateTime( 'now', new DateTimeZone( $timezone ) );
			}
		}

		return $date;
	}

	/**
	 * Splits a byline string into multiple author names.
	 *
	 * @param string $byline Byline string.
	 * @return array Array of author names.
	 */
	public function split_byline( string $byline ): array {

		$author_names = [];
			
		// Replace multiple separators with a common separator.
		$separators       = [ ', and', ',', ' and ', ' with ', ' & ', ' y ' ];
		$common_separator = '&&';
		$byline_replaced  = str_replace( $separators, $common_separator, $byline );

		// Explode using the common separator.
		$author_names = explode( $common_separator, $byline_replaced );
		
		// Trim.
		$author_names = array_map( 'trim', $author_names );
		$author_names = array_map(
			function ( $string ) {
				// Remove.
				$string = str_replace( 'By ', '', $string );
				$string = str_replace( 'by ', '', $string );
				// Remove double spaces.
				$string = str_replace( '   ', ' ', $string );
				$string = str_replace( '  ', ' ', $string );
				// Make sure '/' is always surrounded by one space.
				$string = str_replace( ' /', '/', $string );
				$string = str_replace( '/ ', '/', $string );
				$string = str_replace( '/', ' / ', $string );

				return $string;
			},
			$author_names
		);

		return $author_names;
	}

	/**
	 * Creates a WP_User from a display name.
	 *
	 * @param string $display_name Display name.
	 * @param string $log          Path to log file.
	 * @return int|string|WP_Error WP_User ID if successful, WP_Error if not.
	 */
	public function create_wp_user_from_display_name( string $display_name, string $log ) {
		global $wpdb;
		
		/**
		 * Get an existing WP_User by:
		 *      1) display name
		 *      2) user_login
		 */
		$display_name = $display_name;
		$slug         = sanitize_title( $display_name );
		$wp_user_id   = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", apply_filters( 'pre_user_display_name', $display_name ) ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		if ( ! $wp_user_id ) {
			$wp_user_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", $slug ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
		}

		// Return user if found.
		if ( $wp_user_id ) {
			return $wp_user_id;
		}

		/**
		 * Create a WP_User.
		 */
		
		// Trim to 50 chars: display_name, user_nicename, user_login.
		$display_name_untrimmed = $display_name;
		if ( strlen( $display_name ) > 50 ) {
			$display_name = substr( $display_name, 0, 50 );
		}
		$slug_untrimmed = $slug;
		if ( strlen( $slug ) > 50 ) {
			$slug = substr( $slug, 0, 50 );
		}

		// Insert WP_User.
		$author_after_args = [
			'display_name'  => $display_name,
			'user_nicename' => $slug,
			'user_login'    => $slug,
			'user_pass'     => wp_generate_password( 12 ),
			'role'          => 'author',
		];
		$wp_user_id        = wp_insert_user( $author_after_args );
		if ( ! $wp_user_id || is_wp_error( $wp_user_id ) ) {
			$err_msg = is_wp_error( $wp_user_id ) ? $wp_user_id->get_error_message() : 'n/a';
			$this->logger->log( $log, sprintf( "ERROR Could not create WP_User with display_name '%s', err: %s", $display_name, $err_msg ), $this->logger::ERROR, false );

			// If creation error is "Sorry, that username already exists", then fetch that username, and label it in logs for double-checking.
			if ( false !== strpos( $err_msg, 'Sorry, that username already exists' ) ) {
				$wp_user_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s", $slug ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				if ( $wp_user_id ) {
					$this->logger->log( $log, sprintf( "ERROR/WARNING Now fetched and using WP_User with SAME user_login '%s' exist ing WP_User ID: %d", $slug, $wp_user_id ), $this->logger::ERROR, false );
					
					return $wp_user_id;
				}
			}
		}

		// Log if trimmed display_name or user_login.
		if ( $display_name_untrimmed != $display_name ) {
			$this->logger->log( $log, sprintf( "ERROR Trimmed display_name WP_User %d from '%s' to '%s'", $wp_user_id, $display_name_untrimmed, $display_name ), $this->logger::ERROR, false );
		}
		if ( $slug_untrimmed != $slug ) {
			$this->logger->log( $log, sprintf( "ERROR Trimmed user_login and user_nicename WP_User %d from '%s' to '%s'", $wp_user_id, $slug_untrimmed, $slug ), $this->logger::ERROR, false );
		}

		return $wp_user_id;
	}
}
