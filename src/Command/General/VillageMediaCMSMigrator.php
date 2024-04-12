<?php

namespace NewspackCustomContentMigrator\Command\General;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
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
class VillageMediaCMSMigrator implements InterfaceCommand {

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static ?InterfaceCommand $instance = null;

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
	 * @var CoAuthorPlus
	 */
	private CoAuthorPlus $cap;
	
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
	 * Singleton constructor.
	 */
	private function __construct() {
		$this->attachments     = new Attachments();
		$this->block_generator = new GutenbergBlockGenerator();
		$this->cap             = new CoAuthorPlus();
		$this->posts           = new Posts();
		$this->logger          = new Logger();
	}

	/**
	 * Register commands.
	 *
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-migrate-xmls',
			[ $this, 'cmd_migrate_xmls' ],
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
			'newspack-content-migrator village-cms-fix-authors',
			[ $this, 'cmd_fix_authors' ],
			[
				'shortdesc' => 'A helper command, re-sets authors on all already imported posts according to this rule: if <attributes> byline exists use that for author, and if it does not exist then use <author> node for author.',
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
						'name'        => 'bylines-special-cases-php-file',
						'description' => 'Path to a PHP file which returns an array with bylines that are manually split/parsed. The PHP file array being returned should cointain bylines as keys, and split author names as values.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dev-only-update-bylines',
						'description' => 'Dev helper flag. If it is set, will only update coauthors where byline attribute is set, not where author node is set.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-consolidate-authors--list-all-wp-users',
			[ $this, 'cmd_consolidate_authors_list_all_users' ],
			[
				'shortdesc' => 'User consolidation helper command no.1. Produces a list of all WP_Users, sorted by display name, and with their emails.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-consolidate-authors--merge-selected-wp-users',
			[ $this, 'cmd_consolidate_authors_merge_users' ],
			[
				'shortdesc' => 'User consolidation helper command no.2. Takes a CSV which notes which users are being deleted and replaced by other existing users.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-consolidated-users',
						'description' => 'Path to CSV file which defines how users should be consolidated.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-dev-helper',
			[ $this, 'cmd_dev_helper_scripts' ],
			[
				'shortdesc' => 'Temporary dev scripts.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-dev-helper-get-consolidated-users',
			[ $this, 'cmd_dev_helper_get_consolidated_users' ],
			[
				'shortdesc' => 'Composes a usable data file for VillageMedia consolidated users based on a spreadsheet.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator village-cms-dev-helper-get-consolidated-data-file',
			[ $this, 'cmd_dev_helper_get_consolidated_data_file' ],
			[
				'shortdesc' => 'Composes a usable data file for VillageMedia, containing relevant XML and WP post data, which can be run directly on Atomic (XML memory overflows).',
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

		WP_CLI::warning( 'NOTE -- make sure to run `newspack-content-migrator village-cms-fix-authors` to properly assign all authors.' );
	}

	/**
	 * Callable for `newspack-content-migrator village-cms-consolidate-authors--list-all-wp-users`.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cmd_consolidate_authors_list_all_users( $pos_args, $assoc_args ) {
		
		// Fetch all WP_Users: ID, display_name, email.
		$users = get_users( [ 'fields' => [ 'ID', 'display_name', 'user_email' ] ] );
		// Convert to array.
		$users = array_map(
			function ( $user ) {
				return (array) $user;
			},
			$users
		);

		// Sort Users by display_name.
		usort(
			$users,
			function ( $a, $b ) {
				return strcmp( $a['display_name'], $b['display_name'] );
			}
		);

		// Export as CSV.
		$log_authors = 'authors.csv';
		$fp_csv      = fopen( $log_authors, 'w' );
		fputcsv( $fp_csv, [ 'ID', 'display_name', 'user_email' ] );
		foreach ( $users as $user ) {
			fputcsv( $fp_csv, [ $user['ID'], $user['display_name'], $user['user_email'] ] );
		}
		fclose( $fp_csv );
		WP_CLI::success( sprintf( 'Logged %s', $log_authors ) );
		
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for `newspack-content-migrator village-cms-consolidate-authors--merge-selected-wp-users`.
	 *
	 * @param array $pos_args  Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cmd_consolidate_authors_merge_users( $pos_args, $assoc_args ) {
		global $wpdb;
		
		$list_consolidated = $assoc_args['csv-consolidated-users'] ?? null;
		if ( ! file_exists( $list_consolidated ) ) {
			WP_CLI::error( 'CSV file does not exist.' );
		}

		/**
		 * Parse CSV.
		 */

		// Load contents of CSV file.
		$consolidated = array_map( 'str_getcsv', file( $list_consolidated ) );
		
		// Get and validate headers.
		$headers          = array_shift( $consolidated );
		$expected_columns = [ 'ID', 'display_name', 'replaced_by_id', 'new_display_name', 'user_email' ];
		foreach ( $expected_columns as $expected_column ) {
			if ( ! in_array( $expected_column, $headers ) ) {
				WP_CLI::error( sprintf( 'CSV file is missing header %s', $expected_column ) );
			}   
		}

		// Get column indexes.
		$index_id               = array_search( 'ID', $headers );
		$index_replaced_by_id   = array_search( 'replaced_by_id', $headers );
		$index_new_display_name = array_search( 'new_display_name', $headers );

		// Loop through CSV and populate data arrays.
		$wp_user_substitutions       = [];
		$wp_user_displayname_updates = [];
		foreach ( $consolidated as $row ) {
			$current_user_id    = $row[ $index_id ];
			$replace_by_user_id = $row[ $index_replaced_by_id ];
			$new_display_name   = $row[ $index_new_display_name ];

			if ( ! empty( $replace_by_user_id ) ) {
				$wp_user_substitutions[ $current_user_id ] = $replace_by_user_id;
			}
			if ( ! empty( $new_display_name ) ) {
				$wp_user_displayname_updates[ $current_user_id ] = $new_display_name;
			}
		}
		// Basic validation of input data.
		foreach ( $wp_user_substitutions as $wp_user_old => $wp_user_new ) {
			if ( $wp_user_old == $wp_user_new ) {
				WP_CLI::error( sprintf( '$wp_user_substitutions from %s to %s', $wp_user_old, $wp_user_new ) );
			}
			if ( ! $wp_user_new ) {
				WP_CLI::error( sprintf( '$wp_user_substitutions ! $wp_user_new %s', $wp_user_new ) );
			}
		}


		/**
		 * Rename WP_Users.
		 */
		$renamed_users = [];
		foreach ( $wp_user_displayname_updates as $wp_user_id => $new_display_name ) {
			$old_display_name = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$wpdb->users} WHERE ID = %d", $wp_user_id ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
			$wpdb->update(
				$wpdb->users, // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				[ 'display_name' => $new_display_name ],
				[ 'ID' => $wp_user_id ]
			);
			$renamed_users[ $wp_user_id ] = [
				'old_display_name' => $old_display_name,
				'new_display_name' => $new_display_name,
			];
			WP_CLI::success( sprintf( 'Renamed %d from `%s` to `%s`', $wp_user_id, $old_display_name, $new_display_name ) );
		}


		/**
		 * Merge/replace WP_Users with other existing WP_Users.
		 */
		$post_coauthor_updates = [];
		$post_author_updates   = [];
		$post_ids              = $this->posts->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) Post ID %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			/**
			 * If authorship is set by CAP, update the WP_User coauthors.
			 */
			$coauthors = $this->cap->get_all_authors_for_post( $post_id );
			if ( $coauthors && ! empty( $coauthors ) ) {
				// Replace with new.
				$new_coauthors = $coauthors;
				foreach ( $coauthors as $key_coauthor => $coauthor ) {
					if ( isset( $wp_user_substitutions[ $coauthor->ID ] ) ) {
						$new_coauthor_id                = $wp_user_substitutions[ $coauthor->ID ];
						$new_coauthor                   = get_user_by( 'ID', $new_coauthor_id );
						$new_coauthors[ $key_coauthor ] = $new_coauthor;
					}
				}

				if ( $new_coauthors != $coauthors ) {
					// Update post with new coauthors.
					$this->cap->assign_authors_to_post( $new_coauthors, $post_id, false );

					// Log.
					WP_CLI::success( sprintf( 'Updated coauthors for post ID %d', $post_id ) );
					// phpcs:disable Convenient log one-liners.
					$post_coauthor_updates[ $post_id ] = [
						'old_ids'           => array_map(
							function ( $coauthor ) {
								return $coauthor->ID; },
							$coauthors 
						),
						'new_ids'           => array_map(
							function ( $new_coauthor ) {
								return $new_coauthor->ID; },
							$new_coauthors 
						),
						'old_display_names' => array_map(
							function ( $coauthor ) {
								return $coauthor->display_name; },
							$coauthors 
						),
						'new_display_names' => array_map(
							function ( $new_coauthor ) {
								return $new_coauthor->display_name; },
							$new_coauthors 
						),
					];
					// phpcs:enable
				}
			}
			
			/**
			 * Update `wp_posts`.`post_author`.
			 */
			$post_author_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
			if ( isset( $wp_user_substitutions[ $post_author_id ] ) ) {
				$wpdb->update(
					$wpdb->posts,
					[ 'post_author' => $wp_user_substitutions[ $post_author_id ] ],
					[ 'ID' => $post_id ]
				);

				WP_CLI::success( sprintf( 'Updated post_author for post ID %d from %s to %s', $post_id, $post_author_id, $wp_user_substitutions[ $post_author_id ] ) );
				$post_author_updates[ $post_id ] = [
					'old_id' => $post_author_id,
					'new_id' => $wp_user_substitutions[ $post_author_id ],
				];
			}
		}


		/**
		 * Save logs.
		 */
		$log_updated_posts = 'updated_posts.csv';

		/** 
		 * For Publisher's QC convenience:
		 *  1. log post's coauthors update
		 *  2. only if coauthors is not used on post, log post_author update
		 * Since coauthors supersedes post_author, no need to log 2nd if 1st has been made.
		 */
		$post_author_updates_log = $post_coauthor_updates;
		foreach ( $post_author_updates as $post_id => $post_update ) {
			$old_user_id      = $post_update['old_id'];
			$new_user_id      = $post_update['new_id'];
			$old_display_name = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$wpdb->users} WHERE ID = %d", $old_user_id ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
			$new_display_name = $wpdb->get_var( $wpdb->prepare( "SELECT display_name FROM {$wpdb->users} WHERE ID = %d", $new_user_id ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
			if ( ! isset( $post_coauthor_updates[ $post_id ] ) ) {
				$post_author_updates_log[] = [
					'post_id'           => $post_id,
					'old_ids'           => $old_user_id,
					'new_ids'           => $new_user_id,
					'old_display_names' => $old_display_name,
					'new_display_names' => $new_display_name,
				];
			}
		}
		// Put headers.
		$fp_csv      = fopen( $log_updated_posts, 'w' );
		$csv_headers = [ 'post_id', 'old_ids', 'new_ids', 'old_display_names', 'new_display_names' ];
		fputcsv( $fp_csv, $csv_headers );
		// Put rows.
		foreach ( $post_coauthor_updates as $post_id => $log_post_author_update ) {
			$csv_row = [
				$post_id,
				implode( ';', $log_post_author_update['old_ids'] ),
				implode( ';', $log_post_author_update['new_ids'] ),
				implode( ';', $log_post_author_update['old_display_names'] ),
				implode( ';', $log_post_author_update['new_display_names'] ),
			];
			fputcsv( $fp_csv, $csv_row );
		}
		WP_CLI::success( sprintf( 'Logged %s', $log_updated_posts ) );


		/**
		 * Log user renamings.
		 */
		$log_renamed_users = 'renamed_users.csv';
		// Put headers.
		$fp_csv      = fopen( $log_renamed_users, 'w' );
		$csv_headers = [ 'user_id', 'old_display_name', 'new_display_name' ];
		fputcsv( $fp_csv, $csv_headers );
		// Put rows.
		foreach ( $renamed_users as $user_id => $renamed_user ) {
			$csv_row = [
				$user_id,
				$renamed_user['old_display_name'],
				$renamed_user['new_display_name'],
			];
			fputcsv( $fp_csv, $csv_row );
		}
		WP_CLI::success( sprintf( 'Logged %s', $log_updated_posts ) );
		
		WP_CLI::warning( sprintf( 'Ready for deletion -- users replaced in %s', $log_updated_posts ) );
		
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Get data from CSV file.
	 *
	 * @param string $file_path Path to CSV file.
	 * @return array
	 */
	public function get_csv_data( string $file_path ): array {
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

	public function parse_csv_file( $file_path ) {
	
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

	/**
	 * Save array to CSV file.
	 *
	 * @param array  $data          Array to save.
	 * @param string $csv_file_path Path to CSV file.
	 * @return void
	 * @throws \UnexpectedValueException When all array elements columns are not the same.
	 */
	public function save_array_to_csv_file( $data, $csv_file_path ) {
		// Validate if all array elements columns are the same.
		$columns = null;
		foreach ( $data as $row ) {
			if ( null === $columns ) {
				$columns = array_keys( $row );
			} elseif ( array_keys( $row ) !== $columns ) {
					throw new \UnexpectedValueException( 'All array elements must have the same columns' );
			}
		}
	
		// Write CSV file.
		$file = fopen( $csv_file_path, 'w' );
		fputcsv( $file, $columns );
		foreach ( $data as $row ) {
			fputcsv( $file, $row );
		}
		fclose( $file );
	}

	public function cmd_dev_helper_get_consolidated_data_file( $pos_args, $assoc_args ) {
		
		global $wpdb;
		$xml_file = $pos_args[0];

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
			WP_CLI::line( sprintf( '%d/%d', $key_content, $contents->length ) );

			// Get original id, author node and byline attribute.
			$original_article_id = null;
			$byline              = null;
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
					case 'attributes':
						$attributes = json_decode( $node->nodeValue, true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( isset( $attributes['byline'] ) && ! empty( $attributes['byline'] ) ) {
							$byline = $attributes['byline'];
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

			// Collect all data in $data_row array.
			$data_row = [
				'original_article_id' => $original_article_id,
			];
			$author_data = $this->get_author_data_from_author_node( $author_node );
			$data_row['author'] = $author_data['first_name'] . ' '. $author_data['last_name'];
			$byline_names_split = [];
			if ( $byline ) {
				if ( isset( $bylines_special_cases[ $byline ] ) ) {
					$byline_names_split = $bylines_special_cases[ $byline ];
				} else {
					// Split the byline into multiple author names.
					$byline_names_split = $this->split_byline( $byline );
				}
			}
			$data_row['byline'] = $byline;
			$data_row['byline_split_csv'] = implode( ',', $byline_names_split );

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

			$current_authors = $this->cap->get_all_authors_for_post( $post_id );
			$current_ga_names_csv = implode( ',', array_map( fn( $author ) => $author->display_name, $current_authors ) );
			$data_row['current_ga_names_csv'] = $current_ga_names_csv;
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
		$authors_data = $this->parse_csv_file( $csv_authors_consolidated_path );
		
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

	/**
	 * Helper dev scripts.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cmd_dev_helper_scripts( $pos_args, $assoc_args ) {

		/**
		 * Helper script.
		 * ----------------------
		 * Set authors from CSV.
		 * ----------------------
		 */
		$csv_file = $pos_args[0];
		$ids_csv  = $pos_args[1];
		if ( ! file_exists( $csv_file ) ) {
			WP_CLI::error( 'CSV file does not exist.' );
		}
		$ids = explode( ',', $ids_csv );
		if ( empty( $ids ) ) {
			WP_CLI::error( 'No IDs provided.' );
		}
		
		global $wpdb;
		$rows = $this->get_csv_data( $csv_file );
		
		// Columns: original_article_id,post_id,link,author_before_id,author_before_displayname,byline,author_after_id,author_after_displayname.
		foreach ( $rows as $key_row => $row ) {
			$post_id                   = $row[1];
			$author_after_displaynames = explode( "\n", $row[7] );

			if ( ! in_array( $post_id, $ids ) ) {
				continue;
			}

			// Get authors to set.
			$authors = [];
			foreach ( $author_after_displaynames as $author_after_displayname ) {
				$author_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", $author_after_displayname ) ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.user_meta__wpdb__users
				$author    = get_user_by( 'ID', $author_id );
				if ( ! $author ) {
					WP_CLI::warning( sprintf( 'ERROR WP_User not found: %s', $author_after_displayname ) );
				} 
				$authors[] = $author;
			}

			// First clear previous.
			$this->cap->unassign_all_guest_authors_from_post( $post_id );

			// Set as post_author.
			if ( 1 === count( $authors ) ) {
				$current_post_author_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_author FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
				$current_post_author    = get_user_by( 'ID', $current_post_author_id );
				$new_post_author        = get_user_by( 'ID', $authors[0]->ID );
				
				if ( $current_post_author_id != $authors[0]->ID ) {
					$updated = $wpdb->update(
						$wpdb->posts,
						[ 'post_author' => $authors[0]->ID ],
						[ 'ID' => $post_id ]
					);
					WP_CLI::success( sprintf( "Updated post ID %d from post_author ID %d '%s' to post_author %d '%s'", $post_id, $current_post_author_id, $current_post_author->display_name, $authors[0]->ID, $new_post_author->display_name ) );
				} else {
					WP_CLI::warning( sprintf( "No change for post ID %d, from post_author ID %d '%s' to post_author ID %d '%s'", $post_id, $current_post_author_id, $current_post_author->display_name, $new_post_author->ID, $new_post_author->display_name ) );
				}
			} elseif ( count( $authors ) > 1 ) {
				// Set as CAP GAs.

				$new_author_ids               = array_map(
					function ( $author ) {
						return $author->ID;
					},
					$authors 
				);
				$new_author_display_names     = array_map(
					function ( $author ) {
						return $author->display_name;
					},
					$authors 
				);
				$current_authors              = $this->cap->get_all_authors_for_post( $post_id );
				$current_author_ids           = array_map(
					function ( $author ) {
						return $author->ID;
					},
					$current_authors 
				);
				$current_author_display_names = array_map(
					function ( $author ) {
						return $author->display_name;
					},
					$current_authors 
				);
				
				if ( array_diff( $current_author_ids, $new_author_ids ) || array_diff( $new_author_ids, $current_author_ids ) ) {
					$this->cap->assign_authors_to_post( $authors, $post_id, false );
					WP_CLI::success( sprintf( "Updated post ID %d, multiple, from current post_author IDs %s '%s' to post_author IDs %s %s", $post_id, implode( ',', $current_author_ids ), implode( ',', $current_author_display_names ), implode( ',', $new_author_ids ), implode( ',', $new_author_display_names ) ) );
				} else {
					WP_CLI::warning( sprintf( "No change for post ID %d, multiple, from current post_author IDs %s '%s' to post_author IDs %s %s", $post_id, implode( ',', $current_author_ids ), implode( ',', $current_author_display_names ), implode( ',', $new_author_ids ), implode( ',', $new_author_display_names ) ) );
				}
			} else {
				WP_CLI::warning( sprintf( 'ERROR authors count %s for post ID %d', count( $authors ), $post_id ) );
			}
		}

		return;





		/**
		 * Helper script.
		 * ----------------------
		 * Validate which post authors need to be fixed.
		 * Also QA new method for validating authors.
		 * ----------------------
		 */
		// Columns: original_article_id,post_id,link,author_before_id,author_before_displayname,byline,author_after_id,author_after_displayname.
		$path                  = $pos_args[0];
		$csv_path_consolidated = $path . '/li-spreadsheet-1--consolidated.csv';
		// $csv_path_consolidated = $path . '/rwp-spreadsheet-1--consolidated.csv';
		
		$rwp_data_consolidated = $this->get_csv_data( $csv_path_consolidated );

		// Loop through posts and validate and fix authors.
		$wrong_post_ids          = [];
		$wrong_post_ids_validate = [];
		foreach ( $rwp_data_consolidated as $key_row => $row ) {
			if ( 0 == $key_row ) {
				continue;
			}

			WP_CLI::line( sprintf( '%d/%d', $key_row + 1, count( $rwp_data_consolidated ) ) );
			
			// Get CSV data.
			$post_id                   = is_numeric( $row[1] ) ? (int) $row[1] : null;
			$authors_after_id          = explode( "\n", $row[6] );
			$authors_after_displayname = explode( "\n", $row[7] );

			// Get current authors.
			$current_authors = $this->cap->get_all_authors_for_post( $post_id );
			if ( ! $current_authors ) {
				$wp_post         = get_post( $post_id );
				$current_authors = [ get_userdata( $wp_post->post_author ) ];
			}
			
			// Compare author names.
			foreach ( $current_authors as $key_current_author => $current_author ) {
				if ( $authors_after_displayname[ $key_current_author ] != $current_author->display_name ) {
					$wrong_post_ids[] = $post_id;
				}
			}

			// Use validate method to compare author names.
			$res_validate = $this->cap->validate_authors_for_post( $post_id, $authors_after_displayname );
			if ( is_wp_error( $res_validate ) ) {
				$wrong_post_ids_validate[ $post_id ] = $res_validate->get_error_message();
			}
		}
		
		// Save wrong post IDs.
		file_put_contents( 'wrong_post_ids.php', "<?php\nreturn " . var_export( $wrong_post_ids, true ) . ";\n" );
		file_put_contents( 'wrong_post_ids_validate.php', "<?php\nreturn " . var_export( $wrong_post_ids_validate, true ) . ";\n" );

		if ( array_keys( $wrong_post_ids_validate ) == $wrong_post_ids ) {
			WP_CLI::success( '$wrong_post_ids_validate and $wrong_post_ids are the same' );
		} else {
			WP_CLI::warning( '$wrong_post_ids_validate and $wrong_post_ids are not the same' );
		}
		return;
	}

	/**
	 * Callable for `newspack-content-migrator village-cms-fix-authors` command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_fix_authors( $pos_args, $assoc_args ) {
		global $wpdb;

		// Args.
		$xml_file = $pos_args[0];


		/**
		 * Logs.
		 */
		$log     = 'village-cms-fix-authors.log';
		$log_csv = 'village-cms-fix-authors.csv';
		// Timestamp $log.
		$this->logger->log( $log, sprintf( 'Starting %s', gmdate( 'Y-m-d H:I:s' ) ) );
		// Delete file $log_csv if it exists.
		if ( file_exists( $log_csv ) ) {
			unlink( $log_csv );
		}
		// We'll log detailed before&after changes to a CSV.
		$fp_csv      = fopen( $log_csv, 'w' );
		$csv_headers = [
			'original_article_id',
			'post_id',
			'author_before_id',
			'author_before_displayname',
			'byline',
			'author_after_id',
			'author_after_displayname',
		];
		fputcsv( $fp_csv, $csv_headers );
		

		// You can provide some specific bylines and how they should be split in a "manual" fashion (for those completely irregular bylines).
		$bylines_special_cases = [];
		if ( isset( $assoc_args['bylines-special-cases-php-file'] ) && file_exists( $assoc_args['bylines-special-cases-php-file'] ) ) {
			$bylines_special_cases = include $assoc_args['bylines-special-cases-php-file'];
		}
		$dev_only_update_bylines = $assoc_args['dev-only-update-bylines'] ?? null;
		

		// Loop through content nodes and fix authors.
		$dom = new DOMDocument();
		$dom->loadXML( file_get_contents( $xml_file ), LIBXML_PARSEHUGE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = $dom->getElementsByTagName( 'content' );
		foreach ( $contents as $key_content => $content ) {

			/**
			 * Get id, author node and attributes.
			 */
			$original_article_id = null;
			$byline              = null;
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
					case 'attributes':
						$attributes = json_decode( $node->nodeValue, true ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						if ( isset( $attributes['byline'] ) && ! empty( $attributes['byline'] ) ) {
							$byline = $attributes['byline'];
						}
						break;
				}
			}
			if ( ! $original_article_id ) {
				$this->logger->log( $log, sprintf( 'ERROR original_article_id not found in key_content %d', $key_content ), $this->logger::ERROR, false );
				continue;
			}
			if ( ! $byline && ! $author_node ) {
				$this->logger->log( $log, sprintf( 'ERROR neither byline nor author_node found in key_content %d', $key_content ), $this->logger::ERROR, false );
				continue;
			}


			// Progress.
			$this->logger->log( $log, sprintf( '(%d)/(%d) original_article_id %s', $key_content + 1, count( $contents ), $original_article_id ) );
			

			// Get post ID.
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT wpm.post_id FROM {$wpdb->postmeta} wpm JOIN {$wpdb->posts} wp ON wp.ID = wpm.post_id WHERE wpm.meta_key = 'original_article_id' AND wpm.meta_value = %s AND wp.post_type = 'post'", $original_article_id ) );
			if ( ! $post_id ) {
				$this->logger->log( $log, sprintf( 'ERROR Post not found for original_article_id %s', $original_article_id ), $this->logger::ERROR, false );
				continue;
			}
			$this->logger->log( $log, sprintf( 'Found post ID %d', $post_id ) );

			// Get the before author data.
			$post             = get_post( $post_id );
			$author_before_id = $post->post_author;

			
			/**
			 * If byline attribute exists, use that for author.
			 * If byline attribute does not exist, use <author> for author.
			 */
			$author_after_display_name = null;
			$author_after_id           = null;
			$wp_user_ids_after         = [];
			
			if ( $byline ) {

				/**
				 * Get author names from byline.
				 */

				if ( isset( $bylines_special_cases[ $byline ] ) ) {
					$author_names = $bylines_special_cases[ $byline ];
				} else {
					// Split the byline into multiple author names.
					$author_names = $this->split_byline( $byline );
				}

				// Create WP_Users.
				$this->logger->log( $log, sprintf( "Creating WP_Users for Post ID %d from byline '%s' ...", $post_id, $byline ) );
				foreach ( $author_names as $author_name ) {
					$wp_user_id = $this->create_wp_user_from_display_name( $author_name, $log );
					if ( ! $wp_user_id || is_wp_error( $wp_user_id ) ) {
						$this->logger->log( $log, sprintf( "ERROR Could not assign WP_User to Post ID %d, author name '%s', entire byline '%s', err: %s", $post_id, $author_name, $byline, is_wp_error( $wp_user_id ) ? $wp_user_id->get_error_message() : 'n/a' ), $this->logger::ERROR, false );
						continue;
					}
					
					$wp_user_ids_after[] = $wp_user_id;
					$this->logger->log( $log, sprintf( "Created/fetched WP_User %d from author_name '%s'", $wp_user_id, $author_name ) );
				}

				// Log if not users were created.
				if ( empty( $wp_user_ids_after ) ) {
					$this->logger->log( $log, sprintf( "ERROR Could not assign ANY AUTHORS to Post ID %d, entire byline '%s'", $post_id, $byline ), $this->logger::ERROR, false );
					continue;
				}

				// Continue setting authors.
				// Set the first of the authors as the `post_author`.
				$author_after_id = $wp_user_ids_after[0];
				
				// If there are multiple authors, assign them all to post using CAP.
				if ( count( $wp_user_ids_after ) > 1 ) {
					$wp_users = [];
					foreach ( $wp_user_ids_after as $wp_user_id ) {
						$wp_users[] = get_user_by( 'ID', $wp_user_id );
					}
					$this->cap->assign_authors_to_post( $wp_users, $post_id, false );
					$authors_set = $this->cap->validate_authors_for_post( $post_id, $wp_users, $strict_order_or_authors = false );
					if ( is_wp_error( $authors_set ) ) {
						$wp_users_data = array_map(
							fn( $user ) => [
								'ID'           => $user->ID,
								'display_name' => $user->display_name,
							],
							$wp_users 
						);
						$this->logger->log( $log, sprintf( "ERROR Could not assign WP_Users to Post ID %d, authors '%s', err: %s", $post_id, implode( ';', $wp_users_data ), $authors_set->get_error_message() ), $this->logger::ERROR, false );
						continue;
					}
					$this->logger->log( $log, sprintf( 'Assigned CAP WP_User IDs %s to post_ID %d', implode( ',', $wp_user_ids_after ), $post_id ) );
				} else {
					// If no multiple GAs are used, unassign possible previously set coauthors.
					$this->cap->unassign_all_guest_authors_from_post( $post_id );
					$this->logger->log( $log, sprintf( 'Unassigned all CAP users from post_ID %d', $post_id ) );
				}
			}
			
			if ( ! $dev_only_update_bylines ) {

				/**
				 * Use <author> as author.
				 */

				// Since <author> is just one person (go GAs), unassign possible previously set coauthors.
				$this->cap->unassign_all_guest_authors_from_post( $post_id );
				$this->logger->log( $log, sprintf( 'Unassigned all CAP users from post_ID %d', $post_id ) );

				$after_author = $this->handle_author( $author_node );
				if ( ! $after_author || is_wp_error( $after_author ) ) {
					$this->logger->log( $log, sprintf( "ERROR Could not get/create WP_User by handle_author() from author_node '%s', err: %s", json_encode( $author_node ), is_wp_error( $after_author ) ? $after_author->get_error_message() : 'n/a' ), $this->logger::ERROR, false );
					continue;
				}
				$author_after_id           = $after_author->ID;
				$author_after_display_name = $after_author->display_name;

				// Validate.
				if ( ! $author_after_display_name || ! $author_after_id ) {
					$this->logger->log( $log, sprintf( "ERROR original_article_id:%d post_id:%d handling <author>, no author_after_id:'%s' or author_after_display_name:'%s' from author node:%s", $original_article_id, $post_id, $author_after_id, $author_after_display_name, json_encode( $author_node ) ), $this->logger::ERROR, false );
					continue;
				}
				$this->logger->log( $log, sprintf( "Found <author> WP_User %d display_name '%s'", $author_after_id, $author_after_display_name ) );
			}
			
			
			// Skip if author was not changed.
			if ( $author_before_id == $author_after_id ) {
				$this->logger->log( $log, 'No change in author. Skipping.' );
				continue;
			}


			// Persist.
			$post_updated = $wpdb->update(
				$wpdb->posts,
				[ 'post_author' => $author_after_id ],
				[ 'ID' => $post_id ]
			);
			if ( ! $post_updated || is_wp_error( $post_updated ) ) {
				$this->logger->log( $log, sprintf( 'ERROR Could not update post %s, err.msg: %s', json_encode( $post_data ), is_wp_error( $post_updated ) ? $post_updated->get_error_message() : 'n/a' ), $this->logger::ERROR, false );
				continue;
			}
			$this->logger->log( $log, sprintf( 'Updated post ID %d post_author to %s', $post_id, $author_after_id ), $this->logger::SUCCESS );


			/**
			 * Log CSV row.
			 */
			// Output all IDs and names if byline was used.
			if ( ! is_null( $byline ) ) {
				$author_after_display_name = '';
				$author_after_id           = '';
				foreach ( $wp_user_ids_after as $wp_user_id ) {
					$wp_user = get_user_by( 'ID', $wp_user_id );
					
					$author_after_display_name .= ! empty( $author_after_display_name ) ? "\n" : '';
					$author_after_display_name .= $wp_user->display_name;
					
					$author_after_id .= ! empty( $author_after_id ) ? "\n" : '';
					$author_after_id .= $wp_user_id;
				}
			}
			$author_before_display_name = get_the_author_meta( 'display_name', $author_before_id );
			$csv_row                    = [
				$original_article_id,
				$post_id,
				$author_before_id,
				$author_before_display_name,
				$byline,
				$author_after_id,
				$author_after_display_name,
			];

			fputcsv( $fp_csv, $csv_row );

		}

		WP_CLI::success( 'Done.' );
		fclose( $fp_csv );
		wp_cache_flush();
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
