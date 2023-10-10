<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Logic\Taxonomy;
use \NewspackCustomContentMigrator\Utils\Logger;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Chorus CMS.
 */
class ChorusCmsMigrator implements InterfaceCommand {

	/**
	 * Meta key for Chorus CMS' original ID.
	 */
	const CHORUS_META_KEY_ORIGINAL_ENTRY_UID      = 'newspack_chorus_entry_uid';
	const CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL = 'newspack_original_image_url';
	const CHORUS_META_KEY_ATTACHMENT_ORIGINAL_UID = 'newspack_chorus_asset_uid';

	/**
	 * Chorus components to Gutenberg blocks converters.
	 *
	 * The way this works is once a Chorus component is found in a post, the 'method' is called with the 'arguments' and it returns Gutenberg blocks.
	 *
	 * @param array {
	 *      Config array which defines which components are converted and how.
	 *
	 *      @type array {
	 *          Key is the name of chorus component.
	 *
	 *          @type ?string $method    Name of method in this class which is called to convert component to Gutenberg blocks. null means the component will not be converted.
	 *          @type ?string $arguments Names of variables passed to the conversion method.
	 * }
	 */
	const COMPONENT_CONVERTERS = [
		/**
		 * These few components with nulls will not be converted.
		 */
		'EntryBodyNewsletter'      => [
			'method'    => null,
			'arguments' => null,
		],
		'EntryBodyTable'           => [
			'method'    => null,
			'arguments' => null,
		],
		'EntryBodyVideo'           => [
			'method'    => null,
			'arguments' => null,
		],
		'EntryBodyActionbox'       => [
			'method'    => null,
			'arguments' => null,
		],
		'EntryBodyBlockquote'      => [
			'method'    => null,
			'arguments' => null,
		],
		'EntryBodyPoll'            => [
			'method'    => null,
			'arguments' => null,
		],
		'EntryBodyImageComparison' => [
			'method'    => null,
			'arguments' => null,
		],

		/**
		 * Components conversion definitions.
		 */
		'EntryBodyParagraph'       => [
			'method'    => 'component_paragraph_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodyImage'           => [
			'method'    => 'component_image_to_block',
			'arguments' => [
				'component',
				'post_id',
				'refresh_attachment_data',
			],
		],
		'EntryBodyHeading'         => [
			'method'    => 'component_heading_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodyHTML'            => [
			'method'    => 'component_html_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodyList'            => [
			'method'    => 'component_list_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodyEmbed'           => [
			'method'    => 'component_embed_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodyPullquote'       => [
			'method'    => 'component_pullquote_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodyHorizontalRule'  => [
			'method'    => 'component_horizontal_rule_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodyGallery'         => [
			'method'    => 'component_gallery_to_block',
			'arguments' => [
				'component',
				'post_id',
			],
		],
		'EntryBodyPymEmbed'        => [
			'method'    => 'component_pymembed_to_block',
			'arguments' => [
				'component',
			],
		],
		'EntryBodySidebar'         => [
			'method'    => 'component_sidebar_to_block',
			'arguments' => [
				'component',
				'post_id',
				'refresh_attachment_data',
			],
		],
		'EntryBodyRelatedList'     => [
			'method'    => 'component_related_list_to_block',
			'arguments' => [
				'component',
			],
		],
	];

	/**
	 * Mapping from Chorus' featured image position to Newspack's.
	 */
	const FEATURED_IMAGE_POSITION_MAPPING = [
		'HEADLINE_OVERLAY'     => 'behind',
		'HEADLINE_BELOW'       => 'above',
		'SPLIT_LEFT'           => 'beside',
		'SPLIT_RIGHT'          => 'beside',
		'STANDARD'             => 'large',
		'HEADLINE_BELOW_SHORT' => 'above',
		'HEADLINE_ABOVE'       => 'large',
	];

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * CoAuthors Plus instance.
	 *
	 * @var CoAuthorPlus CoAuthors Plus instance.
	 */
	private $coauthors_plus;

	/**
	 * Logger instance.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments Attachments instance.
	 */
	private $attachments;

	/**
	 * Posts instance.
	 *
	 * @var Posts Posts instance.
	 */
	private $posts;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator GutenbergBlockGenerator instance.
	 */
	private $gutenberg_blocks;

	/**
	 * Crawler instance.
	 *
	 * @var Crawler Crawler instance.
	 */
	private $crawler;

	/**
	 * Taxonomy instance.
	 *
	 * @var Taxonomy Taxonomy instance.
	 */
	private $taxonomy;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthors_plus   = new CoAuthorPlus();
		$this->logger           = new Logger();
		$this->attachments      = new Attachments();
		$this->posts            = new Posts();
		$this->gutenberg_blocks = new GutenbergBlockGenerator();
		$this->crawler          = new Crawler();
		$this->taxonomy         = new Taxonomy();
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
			'newspack-content-migrator chorus-cms-import-authors-and-posts',
			[ $this, 'cmd_import_authors_and_posts' ],
			[
				'shortdesc' => 'Migrates authors and entries (posts) to WordPress.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-export',
						'description' => "Path to where 'author/' and 'entry/' folders with JSONs are located.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'timezone-string',
						'description' => "PHP timezone string (e.g. 'America/New_York', 'Europe/Berlin', 'UTC', ...). It is assumed that Chorus' timestamps are in UTC, so we need to specify Publisher's local timezone to convert them properly. For full list of available timezone strings (then click region for list of strings, e.g. https://www.php.net/manual/en/timezones.america.php).",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start-index',
						'description' => 'If used, will start importing from this index.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-authors',
						'description' => 'If used, will refresh all author data from JSONs, even if author exists.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-posts',
						'description' => "If used, will refresh all posts or 'entries' data from JSONs, even if post exists.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-attachment-data',
						'description' => 'If used, will refresh attachment data (caption, title, ...), even if they exist.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator chorus-cms-import-assets',
			[ $this, 'cmd_import_assets' ],
			[
				'shortdesc' => 'Imports the entirety of assets JSONs, even if they re not used in posts.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-export',
						'description' => "Path to where 'asset/' folder is located with asset JSONs.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'timezone-string',
						'description' => "PHP timezone string (e.g. 'America/New_York', 'Europe/Berlin', 'UTC', ...). It is assumed that Chorus' timestamps are in UTC, so we need to specify Publisher's local timezone to convert them properly. For full list of available timezone strings (then click region for list of strings, e.g. https://www.php.net/manual/en/timezones.america.php).",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-assets',
						'description' => 'If used, will refresh data from asset JSONs even for existing media library items.',
						'optional'    => true,
						'repeating'   => false,
					],

				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator chorus-cms-temp-dev-helper-scripts',
			[ $this, 'cmd_temp_dev_helper_scripts' ],
		);
	}

	public function cmd_temp_dev_helper_scripts( $pos_args, $assoc_args ) {

		global $wpdb;


		/**
		 * Update all dates/timestamps to correct NYC timezone.
		 */
		// Correct all dates to this timezone.
		$timezone_string = 'America/New_York';
		// Updated/processed post IDs.
		$post_ids = [];
		// Get JSONs from two data exports.
		$entries_path_1 = '/tmp/setup/initial_export_archive/export_test/entry';
		$entries_path_2 = '/tmp/second_content_migration/content-export/entry';
		$entries_jsons  = glob( $entries_path_1 . '/*.json' );
		$entries_jsons  = array_merge( $entries_jsons, glob( $entries_path_2 . '/*.json' ) );
		// Logs.
		$missing_uids  = [];
		$missing_jsons = [];
		foreach ( $entries_jsons as $key_entry_json => $entry_json ) {
			// Load data.
			$data_entry = json_decode( file_get_contents( $entry_json ), true );
			$entry      = $this->get_entry_from_data_entry( $data_entry, $entry_json );
			// Get imported post ID.
			$uid     = $entry['uid'];
			$post_id = $wpdb->get_var( $wpdb->prepare( "select post_id from {$wpdb->postmeta} where meta_key = %s and meta_value = %s;", self::CHORUS_META_KEY_ORIGINAL_ENTRY_UID, $uid ) );
			if ( ! $post_id ) {
				$missing_uids[]  = $uid;
				$missing_jsons[] = $entry_json;
			}
			WP_CLI::line( sprintf( '%d/%d ID %d UID %s', $key_entry_json + 1, count( $entries_jsons ), $post_id, $uid ) );
			// Will update post data from this array.
			$post_update = [];
			// Get corrected dates.
			$post_date                    = $this->format_chorus_date( $entry['publishDate'], $timezone_string );
			$post_update['post_date']     = $post_date;
			$post_update['post_date_gmt'] = $post_date;
			if ( $entry['updatedAt'] ) {
				$post_modified                    = $this->format_chorus_date( $entry['updatedAt'], $timezone_string );
				$post_update['post_modified']     = $post_modified;
				$post_update['post_modified_gmt'] = $post_modified;
			}
			// Save.
			if ( ! empty( $post_update ) ) {
				$wpdb->update( $wpdb->posts, $post_update, [ 'ID' => $post_id ] );
				WP_CLI::success( "Updated $post_id." );
			}
		}
		// Log if UIDs not found in DB.
		if ( count( $missing_jsons ) > 0 ) {
			$file_uids  = 'missing_uids.txt';
			$file_jsons = 'missing_jsons.txt';
			file_put_contents( $file_uids, implode( "\n", $missing_uids ) );
			file_put_contents( $file_jsons, implode( "\n", $missing_jsons ) );
			WP_CLI::warning( "Missing UID(s) in DB. List of JSON files and UIDs saved to files $file_uids , $file_jsons" );
		}
		return;



		/**
		 * Copy excerpts to "newspack_post_subtitle" postmeta, as well.
		 */
		// $post_ids = $this->posts->get_all_posts_ids();
		// foreach ( $post_ids as $key_post_id => $post_id ) {
		// $excerpt = get_the_excerpt( $post_id );
		// if ( ! $excerpt ) {
		// continue;
		// }
		//
		// WP_CLI::line( sprintf( "%d/%d %d", $key_post_id + 1, count( $post_ids ), $post_id ) );
		// update_post_meta( $post_id, 'newspack_post_subtitle', $excerpt );
		// WP_CLI::success( "Updated ID $post_id." );
		// }
		// WP_CLI::line( "Done." );
	}

	/**
	 * Callable for `newspack-content-migrator chorus-cms-import-assets`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_import_assets( array $pos_args, array $assoc_args ) {
		$path       = rtrim( $assoc_args['path-to-export'], '/' );
		$asset_path = $path . '/asset';
		if ( ! file_exists( $asset_path ) ) {
			WP_CLI::error( 'Content not found in path.' );
		}
		$timezone_string = rtrim( $assoc_args['timezone-string'], '/' );
		$refresh_assets  = isset( $assoc_args['refresh-assets'] ) ? true : false;

		WP_CLI::line( 'Importing assets...' );
		$this->import_assets( $asset_path, $timezone_string, $refresh_assets );

		WP_CLI::success( 'Done. Check *.log files.' );
	}

	/**
	 * @param string $asset_path
	 * @param string $timezone_string
	 * @param bool   $refresh_assets
	 *
	 * @return void
	 */
	public function import_assets( string $asset_path, string $timezone_string, bool $refresh_assets ) {
		global $wpdb;

		// Get all existing attachment IDs.
		$existing_attachment_ids = $wpdb->get_col( "select ID from {$wpdb->posts} where post_type = 'attachment';" );

		// Loop through entries and import them.
		// $assets_jsons = glob( $asset_path . '/*.json' );
		$assets_jsons = [ '/Users/ivanuravic/www/thecity/app/setup3_launch/export_old_the-city_export_8-9-2023/asset/Asset:testmock.json' ];
		foreach ( $assets_jsons as $key_asset_json => $asset_json ) {

			WP_CLI::line( sprintf( '%d/%d', $key_asset_json + 1, count( $assets_jsons ) ) );

			// Get asset data from JSON.
			$data_asset   = json_decode( file_get_contents( $asset_json ), true );
			$uid          = $data_asset['uid'];
			$url          = $data_asset['url'];
			$credit       = $data_asset['credit']['html'] ?? null;
			$title        = $data_asset['title'] ?? null;
			$caption      = $data_asset['sourceCaption'] ?? null;
			$usage_rights = $data_asset['usageRights'] ?? null;
			$created      = isset( $data_asset['createdAt'] ) && ! is_null( $data_asset['createdAt'] )
				? $this->format_chorus_date( $data_asset['createdAt'], $timezone_string )
				: null;
			$type         = $data_asset['type'] ?? null;
			// Not sure if we can use this one, can be: 'URL', 'MEMBER_UPLOAD', 'UPLOAD'.
			$source = $data_asset['source'] ?? null;

			/**
			 * Check if already imported. We'll have to make this complex and unperformant at this pint because of historical reasons how we've gradually been importing different data from a specific publisher :(
			 *  1. check by uid
			 *  2. check by original URL
			 *  3. also check if `$this->attachments->import_external_file( $url );` returning an existing URL by:
			 *      - fetching all attachment IDs
			 *      - calling `$this->attachments->import_external_file( $url );`
			 *      - checking if it returned one of the existing IDs
			 */
			$existing_att_id = null;
			$att_id          = null;
			// Check by uid.
			$existing_att_id = $wpdb->get_var(
				$wpdb->prepare(
					"select post_id from {$wpdb->postmeta} where meta_key = %s and meta_value = %s;",
					self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_UID,
					$uid
				) 
			);
			// Check by original URL.
			if ( ! $existing_att_id ) {
				$existing_att_id = $wpdb->get_var(
					$wpdb->prepare(
						"select post_id from {$wpdb->postmeta} where meta_key = %s and meta_value = %s;",
						self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL,
						$url
					) 
				);
			}
			// Get existing or import new attachment ID.
			if ( ! $existing_att_id ) {
				$att_id = $this->attachments->import_external_file( $url );
				if ( is_wp_error( $att_id ) ) {
					$err_msg = $att_id->get_error_message() ?? '/na';
					$this->logger->log( 'chorus_assets_errors.log', sprintf( 'Error importing URL: %s ErrMsg: %s', $url, $err_msg ) );

					continue;
				}

				// Flag that the resulting $att_id is existing.
				if ( in_array( $att_id, $existing_attachment_ids ) ) {
					$existing_att_id = $att_id;
				}
			}

			// Skip if already imported and not explicitly refreshing existing assets.
			if ( $existing_att_id && ! $refresh_assets ) {
				continue;
			}

			// Log newly imported attachment.
			if ( ! $existing_att_id ) {
				$this->logger->log( 'chorus_new_assets.log', sprintf( 'Imported attachment ID %d URL %s', $att_id, $url ) );
			}

			// Set the att ID variable (in the code above it was either fetched from import_external_file(), or uid, or URL).
			$att_id = $att_id ?? $existing_att_id;

			// Get wp_post data for updating.
			$update_post = [];
			$credit      = $data_asset['credit']['html'] ?? null;
			if ( $credit ) {
				$update_postmeta['_media_credit'] = $credit;
			}
			$usage_rights = $data_asset['usageRights'] ?? null;
			if ( ! $usage_rights ) {
				$update_postmeta['_navis_media_can_distribute'] = true;
			}
			$created = isset( $data_asset['createdAt'] ) && ! is_null( $data_asset['createdAt'] )
				? $this->format_chorus_date( $data_asset['createdAt'], $timezone_string )
				: null;
			if ( $created ) {
				$update_post['post_date']     = $created;
				$update_post['post_date_gmt'] = $created;
			}

			// Get wp_postmeta data for updating.
			$update_postmeta = [];
			$title           = $data_asset['title'] ?? null;
			if ( $title ) {
				$update_post['post_title'] = $title;
			}
			$caption = $data_asset['sourceCaption'] ?? null;
			if ( $caption ) {
				$update_post['post_excerpt'] = $title;
			}
			$update_postmeta[ self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_UID ] = $uid;
			$update_postmeta[ self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL ] = $url;

			// Update.
			if ( ! empty( $update_post ) ) {
				$wpdb->update( $wpdb->posts, $update_post, [ 'ID' => $att_id ] );
			}
			if ( ! empty( $update_postmeta ) ) {
				$wpdb->update( $wpdb->postmeta, $update_postmeta, [ 'ID' => $att_id ] );
			}
		}

		$d = 1;

	}

	/**
	 * Callable to `newspack-content-migrator chorus-cms-import-authors-and-posts`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_authors_and_posts( $pos_args, $assoc_args ) {

		/**
		 * CLI Params.
		 */
		// Not yet implemented $refresh_authors.
		$refresh_authors = $assoc_args['refresh-authors'] ?? null;
		// Not yet implemented $refresh_posts.
		$refresh_posts           = $assoc_args['refresh-posts'] ?? null;
		$refresh_attachment_data = $assoc_args['refresh-attachment-data'] ?? false;
		$start_index             = $assoc_args['start-index'] ?? 0;
		$path                    = rtrim( $assoc_args['path-to-export'], '/' );
		$authors_path            = $path . '/author';
		$entries_path            = $path . '/entry';
		if ( ! file_exists( $authors_path ) || ! file_exists( $entries_path ) ) {
			WP_CLI::error( 'Content not found in path.' );
		}
		$timezone_string = rtrim( $assoc_args['timezone-string'], '/' );

		WP_CLI::line( 'Checking whether this script knows how to convert all Chorus content components...' );
		$this->validate_component_types( $entries_path );

		WP_CLI::line( 'Importing authors...' );
		$this->import_authors( $authors_path, $refresh_authors );

		WP_CLI::line( 'Importing posts...' );
		$this->import_entries( $entries_path, $timezone_string, $start_index, $refresh_posts, $refresh_authors, $refresh_attachment_data );

		WP_CLI::success( 'Done. Check *.log files.' );
	}

	/**
	 * Loops through entries JSONs and just checks if all of those are known by this command.
	 *
	 * @param $entries_path Path to "entries/" folder (path should include the "entries" folder ;) ).
	 *
	 * @return void
	 */
	public function validate_component_types( $entries_path ) {
		// Loop through entries and import them.
		$entries_jsons = glob( $entries_path . '/*.json' );
		foreach ( $entries_jsons as $entry_json ) {
			$data_entry = json_decode( file_get_contents( $entry_json ), true );

			$entry = $this->get_entry_from_data_entry( $data_entry, $entry_json );

			// Loop through components.
			foreach ( $entry['body']['components'] as $component ) {
				if ( ! isset( self::COMPONENT_CONVERTERS[ $component['__typename'] ] ) ) {
					WP_CLI::error( sprintf( "Unknown component type '%s', need to create a converter first.", $component['__typename'] ) );
				}
			}
		}
	}

	/**
	 * Parses string with additional reporters and returns array of author names.
	 *
	 * @param string $contributor_field
	 *
	 * @return string[]
	 */
	public function get_author_names_from_additional_contributors_field( $contributor_field ) {

		$contributor_field = trim( $contributor_field );
		$contributor_field = str_replace( 'Additional Reporting By ', '', $contributor_field );
		$contributor_field = str_replace( 'Additional Reporting by ', '', $contributor_field );
		$contributor_field = str_replace( 'ADDITIONAL REPORTING BY ', '', $contributor_field );
		$contributor_field = str_replace( 'With Additional Reporting by ', '', $contributor_field );
		$contributor_field = str_replace( 'Additional reporting by ', '', $contributor_field );
		$contributor_field = str_replace( ' and ', ', ', $contributor_field );
		$contributor_field = str_replace( ' AND ', ', ', $contributor_field );
		$contributor_field = str_replace( ', ', ',', $contributor_field );

		$author_names = explode( ',', $contributor_field );

		return $author_names;
	}

	/**
	 * Imports posts.
	 *
	 * @param string $entries_path Path to "entries/" folder (path should include the "entries" folder ;) ).
	 * @param string $timezone_string PHP timezone string.
	 * @param int    $start_index Start importing from this index.
	 * @param bool   $refresh_posts Not yet implemented.
	 * @param bool   $refresh_authors Not yet implemented.
	 * @param bool   $refresh_attachment_data Refresh attachments metadata.
	 *
	 * @throws \RuntimeException If argument is missing.
	 * @return void
	 */
	public function import_entries( $entries_path, $timezone_string, $start_index, $refresh_posts, $refresh_authors, $refresh_attachment_data ) {
		global $wpdb;

		// Get already imported posts original IDs.
		$imported_original_ids = $this->get_posts_meta_values_by_key( self::CHORUS_META_KEY_ORIGINAL_ENTRY_UID );

		// Loop through entries and import them.
		$entries_jsons = glob( $entries_path . '/*.json' );
		foreach ( $entries_jsons as $key_entry_json => $entry_json ) {
			if ( $key_entry_json + 1 < $start_index ) {
				continue;
			}

			WP_CLI::line( sprintf( '%d/%d', $key_entry_json + 1, count( $entries_jsons ) ) );

			$data_entry = json_decode( file_get_contents( $entry_json ), true );
			$entry      = $this->get_entry_from_data_entry( $data_entry, $entry_json );

			/**
			 * Skip entry if it's already imported and the $refresh_posts flag is not set.
			 */
			if ( ! $refresh_posts && in_array( $entry['uid'], $imported_original_ids, true ) ) {
				$this->logger->log(
					'chorus-cms-import-authors-and-posts__info__skip_entry.log',
					sprintf( 'Skipping entry %s because it\'s already imported.', $entry['uid'] ),
					$this->logger::WARNING
				);

				continue;
			}

			/**
			 * Import only published entries of type STORY.
			 */
			if ( 'PUBLISHED' != $entry['publishStatus'] ) {
				continue;
			}
			if ( 'Entry' != $entry['__typename'] ) {
				continue;
			}
			if ( 'STORY' != $entry['type'] ) {
				continue;
			}

			/**
			 * Post creation arguments.
			 */
			$post_create_args = [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => $entry['title'],
			];

			/**
			 * Excerpt.
			 */
			if ( isset( $entry['dek']['html'] ) && ! empty( $entry['dek']['html'] ) ) {
				$post_create_args['post_excerpt'] = $entry['dek']['html'];
			}

			/**
			 * Post date.
			 */
			$publish_date = $this->format_chorus_date( $entry['publishDate'], $timezone_string );
			if ( ! $publish_date ) {
				$publish_date = date( 'Y-m-d H:i:s' );
			}
			$post_create_args['post_date'] = $publish_date;

			/**
			 * Slug.
			 */
			$url_parsed                    = parse_url( $entry['url'] );
			$path_exploded                 = explode( '/', $url_parsed['path'] );
			$slug                          = $path_exploded[ count( $path_exploded ) - 1 ];
			$post_create_args['post_name'] = $slug;

			/**
			 * Insert or fetch post.
			 */
			$post_id = $this->get_post_id_by_meta( self::CHORUS_META_KEY_ORIGINAL_ENTRY_UID, $entry['uid'] );
			// If the post exists and we don't want to refresh it, skip it.
			if ( ! $refresh_posts && $post_id ) {
				$this->logger->log(
					'chorus-cms-import-authors-and-posts__info__skip_entry.log',
					sprintf( 'Skipping entry %s because it\'s already imported.', $entry['uid'] ),
					$this->logger::WARNING
				);

				continue;
			}

			$post_created = false;
			if ( ! $post_id ) {
				$post_id      = wp_insert_post( $post_create_args );
				$post_created = true;
			}

			if ( is_wp_error( $post_id ) ) {
				$err = $post_id->get_error_message();
				$this->logger->log( 'chorus__error__insert_post.log', "uid: {$entry['uid']} errorInserting: " . $err );
				continue;
			}

			if ( ! $post_id ) {
				$this->logger->log( 'chorus__error__insert_post.log', "uid: {$entry['uid']} errorGetting." );
				continue;
			}

			WP_CLI::success(
				$post_created
				? "Created post ID $post_id for {$entry['url']}"
				: "Fetched post ID $post_id for {$entry['url']}"
			);

			/**
			 * Convert all Chorus entry's "components" to Gutenberg blocks.
			 * (Needs to happen after post creation because some blocks need the post ID.)
			 */
			$blocks = [];
			foreach ( $entry['body']['components'] as $component ) {

				// Skip "Think Locally, Act Locally" campaign.
				if (
					'EntryBodyHeading' === $component['__typename']
					&& array_key_exists( 'html', $component['contents'] )
					&& 'Think Locally, Act Locally' === trim( $component['contents']['html'] )
					) {
						$this->logger->log(
							'chorus-cms-import-authors-and-posts__info__skip_think_locally_campaign.log',
							sprintf( 'Entry %s (wp ID: %d) contains a "Think Locally, Act Locally" campaign.', $entry['uid'], $post_id ),
							$this->logger::WARNING
						);
					break;
				}

				// Skip embeded Google Docs.
				if (
					'EntryBodyEmbed' === $component['__typename']
					&& isset( $component['embed']['provider']['name'] )
					&& 'Google Docs' === $component['embed']['provider']['name']
					) {
						$this->logger->log(
							'chorus-cms-import-authors-and-posts__info__skip_embeded_google_docs.log',
							sprintf( 'Entry %s (wp ID: %d) contains an embeded Google Doc.', $entry['uid'], $post_id ),
							$this->logger::WARNING
						);
					break;
				}

				// Get conversion method name.
				$method = self::COMPONENT_CONVERTERS[ $component['__typename'] ]['method'];
				if ( is_null( $method ) ) {
					continue;
				}

				// Get arguments.
				$arguments = [];
				foreach ( self::COMPONENT_CONVERTERS[ $component['__typename'] ]['arguments'] as $key_argument => $argument ) {
					if ( ! isset( $$argument ) ) {
						throw new \RuntimeException( sprintf( "Argument $%s not set in context and can't be passed to method %s() as argument number %d.", $argument, $method, $key_argument ) );
					}
					$arguments[] = $$argument;
				}

				// Call the method and get resulting blocks.
				$blocks = array_merge( $blocks, call_user_func_array( 'self::' . $method, $arguments ) );
			}

			// Update post data all at once.
			$post_update_data = [];

			/**
			 * Get post_content.
			 */
			$post_content                     = serialize_blocks( $blocks );
			$post_update_data['post_content'] = $post_content;

			/**
			 * Import featured image.
			 */
			if ( isset( $entry['leadImage']['asset'] ) && ! empty( $entry['leadImage']['asset'] ) ) {
				if ( 'IMAGE' != $entry['leadImage']['asset']['type'] ) {
					continue;
				}
				$url     = $entry['leadImage']['asset']['url'];
				$credit  = $entry['leadImage']['asset']['credit']['html'] ?? null;
				$title   = $entry['leadImage']['asset']['title'] ?? null;
				$caption = $this->get_caption_from_asset( $entry['leadImage'] );

				// If the image is already imported return its ID.
				$attachment_id = $this->get_post_id_by_meta( self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

				if ( ! $attachment_id ) {
					// Download featured image.
					WP_CLI::line( "Downloading featured image {$url} ..." );
					$attachment_id = $this->attachments->import_external_file( $url, $title, $caption, null, null, $post_id );
				}

				if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
					$this->logger->log( 'chorus__error__import_featured_image.log', "url: {$url} errorInserting: " . ( is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ) );
					break;
				}

				// Save the original URL in the meta.
				update_post_meta( $attachment_id, self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

				// Set is as featured image.
				set_post_thumbnail( $post_id, $attachment_id );

				// Save credit as Newspack credit.
				update_post_meta( $attachment_id, '_media_credit', $credit );

				// Set Newspack featured image position.
				if ( $entry['layoutTemplate'] ) {
					if ( isset( self::FEATURED_IMAGE_POSITION_MAPPING[ $entry['layoutTemplate'] ] ) ) {
						update_post_meta( $post_id, 'newspack_featured_image_position', self::FEATURED_IMAGE_POSITION_MAPPING[ $entry['layoutTemplate'] ] );
					} else {
						$this->logger->log(
							'chorus-cms-import-authors-and-posts__warning__layout_template.log',
							sprintf( "Undefined featured image mapping in self::FEATURED_IMAGE_POSITION_MAPPING for layout template '%s'.", $entry['layoutTemplate'] ),
							$this->logger::WARNING
						);
					}
				}

				// Set distribution details.
				if ( ! isset( $entry['leadImage']['asset']['usageRights'] ) || ! $entry['leadImage']['asset']['usageRights'] ) {
					update_post_meta( $post_id, '_navis_media_can_distribute', true );
				}

				if ( $refresh_attachment_data ) {
					// Update attachment metadata.
					$attachment_data = [
						'ID'           => $attachment_id,
						'post_title'   => $title,
						'post_excerpt' => $caption,
					];
					wp_update_post( $attachment_data );
				}
			}

			/**
			 * Authors.
			 * There's only one author per entry in Chorus. Remaining co-authors are "contributors" and "additional contributors".
			 */
			$ga_ids                           = [];
			$ga_id                            = $this->get_or_create_ga_from_author_data(
				$entry['author']['firstName'],
				$entry['author']['lastName'],
				$this->get_author_display_name( $entry['authorProfile'] ),
				$entry['author']['uid'],
				$entry['author']['username'],
				$short_bio                    = null,
				$author_profile__social_links = null,
				$refresh_authors
			);

			if ( $ga_id ) {
				$ga_ids[] = $ga_id;
			} else {
				$this->logger->log(
					'chorus-cms-import-authors-and-posts__err__assign_author.log',
					sprintf( 'Could not assign Author to post ID %d, url %s, firstName: %s, lastName: %s, uid: %s, username: %s', $post_id, $entry['url'], $entry['author']['firstName'], $entry['author']['lastName'], $entry['author']['uid'], $entry['author']['username'] ),
					$this->logger::WARNING
				);
			}

			/**
			 * "Contributors".
			 * These are regular GAs. They go AFTER the author.
			 */
			if ( $entry['contributors'] && ! empty( $entry['contributors'] ) ) {
				foreach ( $entry['contributors'] as $contributor ) {
					if ( $contributor['authorProfile'] ) {
						$ga_id = $this->get_or_create_ga_from_author_data(
							$contributor['authorProfile']['user']['firstName'],
							$contributor['authorProfile']['user']['lastName'],
							$this->get_author_display_name( $contributor['authorProfile'] ),
							$contributor['authorProfile']['user']['uid'],
							$contributor['authorProfile']['user']['username'],
							$contributor['authorProfile']['shortBio'],
							$contributor['authorProfile']['socialLinks'],
							$refresh_authors
						);
						if ( ! is_wp_error( $ga_id ) ) {
							$ga_ids[] = $ga_id;
						} else {
							$this->logger->log(
								'chorus-cms-import-authors-and-posts__err__assign_author.log',
								sprintf( 'Could not assign Contributor to post ID %d, url %s, firstName: %s lastName: %s display_name: %s uid: %s username: %s short_bio: %s social_links: %s', $post_id, $entry['url'], $contributor['authorProfile']['user']['firstName'], $contributor['authorProfile']['user']['lastName'], $contributor['authorProfile']['name'], $contributor['authorProfile']['user']['uid'], $contributor['authorProfile']['user']['username'], $contributor['authorProfile']['shortBio'], printf( $contributor['authorProfile']['socialLinks'], true ) ),
								$this->logger::WARNING
							);
						}
					}
				}
			}

			/**
			 * "Additional contributors".
			 * These are also GAs, but they should be getting "additional reporting by" in front of their name. For now just saving postmeta until we figure out how we're going to be displaying this label.
			 */
			$ga_ids_additional_contributors = [];
			if ( $entry['additionalContributors'] && ! empty( trim( $entry['additionalContributors']['plaintext'] ) ) ) {
				$author_names = $this->get_author_names_from_additional_contributors_field( $entry['additionalContributors']['plaintext'] );
				foreach ( $author_names as $author_name ) {
					if ( ! $author_name || empty( $author_name ) ) {
						continue;
					}
					// Additional contributors go only by name, so no need for $author_args.
					$ga    = $this->coauthors_plus->get_guest_author_by_display_name( $author_name );
					$ga_id = $ga ? $ga->ID : null;
					if ( ! $ga_id ) {
						$ga_id = $this->coauthors_plus->create_guest_author( [ 'display_name' => $author_name ] );
					}
					if ( $ga_id ) {
						$ga_ids[]                         = $ga_id;
						$ga_ids_additional_contributors[] = $ga_id;
					} else {
						$this->logger->log(
							'chorus-cms-import-authors-and-posts__err__assign_author.log',
							sprintf( "Could not assign Additional Contributor to post ID %d, url %s, entry['additionalContributors']: %s, this extracted author name: %s", $post_id, $entry['url'], $entry['additionalContributors']['plaintext'], $author_name ),
							$this->logger::WARNING
						);
					}
				}
			}
			// Save meta for additional contributors.
			foreach ( $ga_ids_additional_contributors as $ga_id ) {
				add_post_meta( $post_id, 'newspack_chorus_additional_contributor_ga_id', $ga_id );
			}

			// Assign all co-authors.
			$this->coauthors_plus->assign_guest_authors_to_post( $ga_ids, $post_id );

			/**
			 * Categories.
			 */
			$category_ids = [];

			// Set primary.
			if ( $entry['primaryCommunityGroup'] ) {
				$category_name_primary = $entry['primaryCommunityGroup']['name'];
				if ( 'front page' !== strtolower( $category_name_primary ) ) {
					$category_primary_term_id = $this->taxonomy->get_or_create_category_by_name_and_parent_id( $category_name_primary, 0 );
					update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category_primary_term_id );
					$category_ids[] = $category_primary_term_id;
				}
			}

			// Set other categories.
			foreach ( $entry['communityGroups'] as $community_group ) {
				if ( ! isset( $community_group['name'] ) ) {
					continue;
				}
				$category_name = $community_group['name'];

				if ( 'front page' !== strtolower( $category_name ) ) {
					$category_term_id = $this->taxonomy->get_or_create_category_by_name_and_parent_id( $category_name, 0 );
					$category_ids[]   = $category_term_id;
				}
			}

			// Set post categories.
			wp_set_post_categories( $post_id, $category_ids );

			/**
			 * Updated date.
			 */
			$updated_date = $this->format_chorus_date( $entry['updatedAt'], $timezone_string );
			if ( $updated_date ) {
				$post_update_data['post_modified']     = $updated_date;
				$post_update_data['post_modified_gmt'] = $updated_date;
			}

			/**
			 * Update all remaining post data.
			 */
			$wpdb->update( $wpdb->posts, $post_update_data, [ 'ID' => $post_id ] );

			/**
			 * Set post meta.
			 */
			$meta = [
				self::CHORUS_META_KEY_ORIGINAL_ENTRY_UID => $entry['uid'],
				'newspack_chorus_entry_url'              => $entry['url'],
			];
			if ( $entry['layoutTemplate'] ) {
				$meta['newspack_chorus_entry_layout_template'] = $entry['layoutTemplate'];
			}
			if ( $post_create_args['post_excerpt'] ) {
				$meta['newspack_post_subtitle'] = $post_create_args['post_excerpt'];
			}
			foreach ( $meta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}

			/**
			 * Set SEO data.
			 */
			if ( $entry['seoHeadline'] ) {
				update_post_meta( $post_id, '_yoast_wpseo_title', $entry['seoHeadline'] . ' %%page%% %%sep%% %%sitename%%' );
			}

			if ( isset( $entry['seoDescription'] ) && $entry['seoDescription'] ) {
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $entry['seoDescription'] );
			}

			if ( isset( $entry['socialHeadline'] ) && $entry['socialHeadline'] ) {
				update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $entry['socialHeadline'] );
				update_post_meta( $post_id, '_yoast_wpseo_twitter-title', $entry['socialHeadline'] );
			}

			if ( isset( $entry['socialDescription']['html'] ) && $entry['socialDescription']['html'] ) {
				update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $entry['socialDescription']['html'] );
				update_post_meta( $post_id, '_yoast_wpseo_twitter-title', $entry['socialDescription']['html'] );
			}

			// TODO: Refactor downloading images from Chorus to a separate method.
			if ( isset( $entry['seoImage']['asset']['url'] ) && $entry['seoImage']['asset']['url'] ) {
				if ( 'IMAGE' != $entry['leadImage']['asset']['type'] ) {
					continue;
				}
				$url     = $entry['seoImage']['asset']['url'];
				$credit  = $entry['seoImage']['asset']['credit']['html'] ?? null;
				$title   = $entry['seoImage']['asset']['title'] ?? null;
				$caption = $this->get_caption_from_asset( $entry['seoImage'] );

				// If the image is already imported return its ID.
				$attachment_id = $this->get_post_id_by_meta( self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

				if ( ! $attachment_id ) {
					// Download seo image.
					WP_CLI::line( "Downloading seo image {$url} ..." );
					$attachment_id = $this->attachments->import_external_file( $url, $title, $caption, null, null, $post_id );
				}

				if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
					$this->logger->log( 'chorus__error__import_seo_image.log', "url: {$url} errorInserting: " . ( is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ) );
					break;
				}

				// Save the original URL in the meta.
				update_post_meta( $attachment_id, self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

				// Save credit as Newspack credit.
				update_post_meta( $attachment_id, '_media_credit', $credit );

				// Set Social image.
				update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', wp_get_attachment_url( $attachment_id ) );
				update_post_meta( $post_id, '_yoast_wpseo_twitter-image', wp_get_attachment_url( $attachment_id ) );
				update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id );
				update_post_meta( $post_id, '_yoast_wpseo_twitter-image-id', $attachment_id );
			}
		}
	}

	/**
	 * Format date from Chorus CMS to WordPress.
	 * It is assumed that Chorus' timestamps are in UTC timezone.
	 *
	 * @param string  $chorus_date     Date in Chorus format, e.g. '2023-06-13T21:05:36.000Z'.
	 * @param ?string $timezone_string Optional, default is "UTC". Chorus uses the following format for timestamps, e.g. "2022-03-24T15:43:10.364Z".
	 *                                 Expected timezone of this timestamp is UTC. Provide a specific PHP timezone string to convert to that one.
	 *                                 See https://www.php.net/manual/en/timezones.php for list of timezone strings (then click
	 *                                 region for list of strings, e.g. https://www.php.net/manual/en/timezones.america.php).
	 *
	 * @return array|string|string[]|null
	 */
	public function format_chorus_date( $chorus_timestamp, $timezone_string = 'UTC' ) {

		$date_time = \DateTime::createFromFormat( 'Y-m-d\TH:i:s.u\Z', $chorus_timestamp );

		// Get WP timestamp in specific TZ.
		$date_time->setTimezone( new \DateTimeZone( $timezone_string ) );
		$wp_timestamp = $date_time->format( 'Y-m-d H:i:s' );

		return $wp_timestamp;
	}

	/**
	 * Imports authors/ JSONs to GAs.
	 *
	 * @param string $authors_path    Path to authors/ JSONs.
	 * @param bool   $refresh_authors Not yet implemented.
	 *
	 * @return void
	 */
	public function import_authors( $authors_path, $refresh_authors ) {
		$authors_jsons = glob( $authors_path . '/*.json' );
		foreach ( $authors_jsons as $author_json ) {
			$author = json_decode( file_get_contents( $author_json ), true );

			$ga_id = $this->get_or_create_ga_from_author_data(
				$author['user']['firstName'],
				$author['user']['lastName'],
				$this->get_author_display_name( $author ),
				$author['uid'],
				$author['user']['username'],
				$author['shortBio'],
				$author['socialLinks'],
				false
			);
			$d     = 'check: ' . $author['name'];
			WP_CLI::success( sprintf( "Created GA %d for author '%s'.", $ga_id, $author['name'] ) );

			// Save $author['uid'] as postmeta.
			if ( $author['uid'] ) {
				update_post_meta( $ga_id, 'newspack_chorus_author_uid', $author['uid'] );
			}
		}
	}

	/**
	 * Chorus JSONs with data have author data in multiple places, and different author data is found in different places.
	 * This works with all those places and gets or creates a GA like this:
	 *      - first tries to get existing GA by its $uid,
	 *      - then tries to get existing GA by $display_name,
	 *      - then tries to get existing GA by its full name (first + last),
	 *      - and finally if no author si found, it creates one using available info.
	 *
	 * @param bool   $refresh_author If set, will update existing GA with user data provided here by these arguments (names, bio, social links, ...), won't just return the existing GA.
	 * @param string $uid
	 * @param string $display_name
	 * @param string $username
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $short_bio
	 * @param array  $author_profile__social_links
	 *
	 * @return int GA ID.
	 */
	public function get_or_create_ga_from_author_data(
		$first_name,
		$last_name,
		$display_name = null,
		$uid = null,
		$username = null,
		$short_bio = null,
		$author_profile__social_links = [],
		$refresh_author = false
	) {
		global $wpdb;

		if ( ! $display_name ) {
			$display_name = $first_name . ' ' . $last_name;
		}

		// Get GA creation/update params.
		$ga_args = [
			'display_name' => $display_name,
			'first_name'   => $first_name,
			'last_name'    => $last_name,
		];

		if ( $username ) {
			$ga_args['user_login'] = $username;
		}

		// Apparently shortBio is always empty (at least in test data), but perhaps it will pop up later on.
		if ( $short_bio ) {
			$ga_args['description'] = $short_bio;
		}

		if ( ! empty( $author_profile__social_links ) ) {

			// Compose bio from different social data.
			$links_bio = '';
			foreach ( $author_profile__social_links as $social_link ) {
				/**
				 * Available $social_link types: PROFILE, TWITTER, RSS, EMAIL, INSTAGRAM.
				 */
				if ( $social_link['type'] ) {
					if ( 'PROFILE' === $social_link['type'] ) {
						// Local site author page URL.
					} elseif ( 'TWITTER' === $social_link['type'] ) {
						// If doesn't end with dot, add dot.
						$links_bio .= ( ! empty( $links_bio ) && '.' != substr( $links_bio, -1 ) ) ? '.' : '';
						// If doesn't end with space, add space.
						$links_bio .= ( ! empty( $links_bio ) && ' ' != substr( $links_bio, -1 ) ) ? ' ' : '';
						// Get handle from URL.
						$handle = rtrim( $social_link['url'], '/' );
						$handle = substr( $handle, strrpos( $handle, '/' ) + 1 );
						// Add Twitter link.
						$links_bio .= sprintf( '<a href="%s" target="_blank">Follow @%s on Twitter</a>.', $social_link['url'], $handle );
					} elseif ( 'RSS' === $social_link['type'] ) {
						// RSS feed URL.
					} elseif ( 'EMAIL' === $social_link['type'] ) {
						$ga_args['user_email'] = $social_link['url'];
					} elseif ( 'INSTAGRAM' === $social_link['type'] ) {
						// If doesn't end with dot, add dot.
						$links_bio .= ( ! empty( $links_bio ) && '.' != substr( $links_bio, -1 ) ) ? '.' : '';
						// If doesn't end with space, add space.
						$links_bio .= ( ! empty( $links_bio ) && ' ' != substr( $links_bio, -1 ) ) ? ' ' : '';
						// Get handle from URL.
						$handle = rtrim( $social_link['url'], '/' );
						$handle = substr( $handle, strrpos( $handle, '/' ) + 1 );
						// Add Twitter link.
						$links_bio .= sprintf( '<a href="%s" target="_blank">Follow @%s on Instagram</a>.', $social_link['url'], $handle );
					}
				}

				// Not used key in JSONs: $social_link['label'].
			}

			// Append social links to GA bio.
			if ( ! empty( $links_bio ) ) {
				// Start with bio.
				$bio_updated = isset( $ga_args['description'] ) && ! empty( $ga_args['description'] ) ? $ga_args['description'] : '';
				// If doesn't end with dot, add dot.
				$bio_updated .= ( ! empty( $bio_updated ) && '.' != substr( $bio_updated, -1 ) ) ? '.' : '';
				// If doesn't end with space, add space.
				$bio_updated .= ( ! empty( $bio_updated ) && ' ' != substr( $bio_updated, -1 ) ) ? ' ' : '';
				// Add links bio.
				$bio_updated .= $links_bio;

				// Update bio.
				$ga_args['description'] = $bio_updated;
			}
		}

		// Get existing GA by $uid.
		$ga_id = null;
		if ( $uid ) {
			$ga_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_chorus_author_uid' and meta_value = %s", $uid ) );
		}
		// Get existing GA by display_name.
		if ( is_null( $ga_id ) && ! empty( $display_name ) ) {
			$ga    = $this->coauthors_plus->get_guest_author_by_display_name( $display_name );
			$ga_id = $ga ? $ga->ID : null;
		}
		// Get existing GA by full name.
		if ( is_null( $ga_id ) && ! empty( $first_name ) && ! empty( $last_name ) ) {
			$ga    = $this->coauthors_plus->get_guest_author_by_display_name( $first_name . ' ' . $last_name );
			$ga_id = $ga ? $ga->ID : null;
		}

		// If GA exists...
		if ( $ga_id ) {

			// ... and not refreshing (i.e. updating existing GA's data), then return this GA.
			if ( ! $refresh_author ) {
				return $ga_id;
			}

			// ... or if refreshing, then update the GA and return it.
			// Don't attempt to update user_login -- presently not supported.
			unset( $ga_args['user_login'] );
			$this->coauthors_plus->update_guest_author( $ga_id, $ga_args );
			WP_CLI::success( sprintf( 'Updated existing user GA %d for author %s.', $ga_id, $display_name ) );
			return $ga_id;
		}

		// Create GA.
		try {
			$ga_id = $this->coauthors_plus->create_guest_author( $ga_args );
		} catch ( \Exception $e ) {
			$this->logger->log(
				'chorus-cms-import-authors-and-posts__err__create_ga.log',
				sprintf( "Err creating GA error message '%s', GA data = %s ", $e->getMessage(), print_r( $ga_args, true ) ),
				$this->logger::WARNING
			);
		}

		return $ga_id;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one paragraph block.
	 */
	public function component_paragraph_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_paragraph( $component['contents']['html'] );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one heading block.
	 */
	public function component_heading_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_heading( $component['contents']['html'] );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component           Component data.
	 * @param bool  $strip_ending_breaks Should strip line breaks or spaces from ending of HTML.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one HTML block.
	 */
	public function component_html_to_block( $component, $strip_ending_breaks = true ) {
		$blocks = [];

		$html = $component['rawHtml'];
		if ( $strip_ending_breaks ) {
			$html = rtrim( $html );
		}
		$blocks[] = $this->gutenberg_blocks->get_html( $html );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component           Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one list block.
	 */
	public function component_list_to_block( $component ) {
		$blocks = [];

		$elements = [];
		foreach ( $component['items'] as $item ) {
			$elements[] = $item['line']['html'];
		}

		$blocks[] = $this->gutenberg_blocks->get_list( $elements );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component           Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one block.
	 */
	public function component_embed_to_block( $component ) {

		$blocks = [];

		$html = $component['embed']['embedHtml'];
		switch ( $component['embed']['provider']['name'] ) {
			case 'YouTube':
				// We expect an iframe with src attribute.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//iframe/@src' );

				// Validate that we have exactly one iframe with src attribute.
				if ( 1 !== $src_crawler->count() ) {
					$this->logger->log(
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( 'Err importing embed YT component, HTML =  ', $html ),
						$this->logger::WARNING
					);
					return [];
				}

				// We're not going to validate much more, Chorus should have this right.
				$src = trim( $src_crawler->getNode( 0 )->textContent );

				// Remove GET params from $src, otherwise the embed might not work.
				$src_parsed  = wp_parse_url( $src );
				$src_cleaned = $src_parsed['scheme'] . '://' . $src_parsed['host'] . $src_parsed['path'];

				$blocks[] = $this->gutenberg_blocks->get_youtube( $src_cleaned );

				break;

			case 'Vimeo':
				// We expect an iframe with src attribute.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//iframe/@src' );

				// Validate that we have exactly one iframe with src attribute.
				if ( 1 !== $src_crawler->count() ) {
					$this->logger->log(
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( 'Err importing embed Vimeo component, HTML =  ', $html ),
						$this->logger::WARNING
					);
					return [];
				}

				// We're not going to validate much more, Chorus should have this right.
				$src = trim( $src_crawler->getNode( 0 )->textContent );

				$blocks[] = $this->gutenberg_blocks->get_vimeo( $src );

				break;

			case 'Twitter':
				// Get all <a>s' srcs.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//a/@href' );

				$src = null;
				// Find src which has "twitter.com" and "/status/".
				foreach ( $src_crawler as $src_crawler_node ) {
					$src_this_node = trim( $src_crawler_node->textContent );
					if ( false !== strpos( $src_this_node, 'twitter.com' ) && false !== strpos( $src_this_node, '/status/' ) ) {
						$src = $src_this_node;
					}
				}

				// Validate.
				if ( is_null( $src ) ) {
					$this->logger->log(
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( 'Err importing embed Twitter component, HTML =  ', $html ),
						$this->logger::WARNING
					);
					return [];
				}

				$blocks[] = $this->gutenberg_blocks->get_twitter( $src );

				break;

			case 'Facebook':
				// Get all <a>s' srcs.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//a/@href' );

				$src = null;
				// Find src which has "facebook.com".
				foreach ( $src_crawler as $src_crawler_node ) {
					$src_this_node = trim( $src_crawler_node->textContent );
					if ( false !== strpos( $src_this_node, 'facebook.com' ) ) {
						$src = $src_this_node;
					}
				}

				// Validate.
				if ( is_null( $src ) ) {
					$this->logger->log(
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( 'Err importing embed Facebook component, HTML =  ', $html ),
						$this->logger::WARNING
					);
					return [];
				}

				$blocks[] = $this->gutenberg_blocks->get_facebook( $src );

				break;

			case 'Tableau Software':
				// This works as Classic Editor shortcode.
				$blocks[] = $this->gutenberg_blocks->get_html( $component['embed']['embedHtml'] );

				break;

			default:
				// For all other types, use the HTML block.
				$blocks[] = $this->gutenberg_blocks->get_html( $html );

				break;
		}

		// Log that nothing happened.
		if ( empty( $blocks ) ) {
			$this->logger->log(
				'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
				sprintf( 'Err importing embed component, no known component type found, HTML =  ', $html ),
				$this->logger::WARNING
			);
		}

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component           Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one iframe block.
	 */
	public function component_pymembed_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_html( $component['format']['html'] );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one quote block.
	 */
	public function component_pullquote_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_quote( $component['quote']['html'] );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one separator block.
	 */
	public function component_horizontal_rule_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_separator( 'is-style-wide' );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one image block.
	 */
	public function component_image_to_block( $component, $post_id, $refresh_attachment_data ) {
		$blocks = [];

		$url     = $component['image']['url'];
		$credit  = $component['image']['asset']['credit']['html'] ?? null;
		$title   = isset( $component['image']['asset']['title'] ) && ! empty( $component['image']['asset']['title'] ) ? $component['image']['asset']['title'] : null;
		$caption = isset( $component['image']['caption']['plaintext'] ) && ! empty( $component['image']['caption']['plaintext'] ) ? $component['image']['caption']['plaintext'] : null;

		// If the image is already imported return its ID.
		$attachment_id = $this->get_post_id_by_meta( self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

		if ( ! $attachment_id ) {
			// Import image.
			WP_CLI::line( sprintf( 'Downloading image %s ...', $url ) );
			$attachment_id = $this->attachments->import_external_file( $url, $title, $caption, null, null, $post_id );
		}

		// Logg errors.
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->logger->log(
				'chorus-cms-import-authors-and-posts__err__component_image_to_block.log',
				sprintf( 'Err importing image URL %s error: %s', $url, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ),
				$this->logger::WARNING
			);
		}

		if ( $refresh_attachment_data ) {
			// Update attachment metadata.
			$attachment_data = [
				'ID'           => $attachment_id,
				'post_title'   => $title,
				'post_excerpt' => $caption,
			];
			wp_update_post( $attachment_data );
		}

		$attachment_post = get_post( $attachment_id );

		// Save the original URL in the meta.
		update_post_meta( $attachment_id, self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

		// Save credit as Newspack credit.
		update_post_meta( $attachment_id, '_media_credit', $credit );

		// Set distribution details.
		if ( ! isset( $component['image']['asset']['usageRights'] ) || ! $component['image']['asset']['usageRights'] ) {
			update_post_meta( $post_id, '_navis_media_can_distribute', true );
		}

		// Setting size and alignment.
		$image_size  = 'full';
		$image_align = null;

		if ( isset( $component['placement']['alignment'] ) && $component['placement']['alignment'] ) {
			if ( 'FLOAT_LEFT' === $component['placement']['alignment'] ) {
				$image_align = 'left';
				$image_size  = 'medium';
			} elseif ( 'FLOAT_RIGHT' === $component['placement']['alignment'] ) {
				$image_align = 'right';
				$image_size  = 'medium';
			}
		}

		$blocks[] = $this->gutenberg_blocks->get_image( $attachment_post, $image_size, true, null, $image_align );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one Jetpack slideshow gallery block.
	 */
	public function component_gallery_to_block( $component ) {
		$blocks = [];

		$attachment_ids = [];
		foreach ( $component['gallery']['images'] as $key_image => $image ) {
			$title   = $image['asset']['title'] ?? null;
			$caption = $image['caption']['html'] ?? null;
			$url     = $image['url'];

			// If the image is already imported return its ID.
			$attachment_id = $this->get_post_id_by_meta( self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

			if ( ! $attachment_id ) {
				// Import image.
				WP_CLI::line( sprintf( 'Downloading gallery image %d/%d %s ...', $key_image + 1, count( $component['gallery']['images'] ), $url ) );
				$attachment_id = $this->attachments->import_external_file( $url, $title, $caption, $description = null, $alt = null, $post_id = 0, $args = [] );

				// Set distribution details.
				if ( ! isset( $component['image']['asset']['usageRights'] ) || ! $component['image']['asset']['usageRights'] ) {
					update_post_meta( $post_id, '_navis_media_can_distribute', true );
				}
			}

			// Save the original URL in the meta.
			update_post_meta( $attachment_id, self::CHORUS_META_KEY_ATTACHMENT_ORIGINAL_URL, $url );

			// Log errors.
			if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
				$this->logger->log(
					'chorus-cms-import-authors-and-posts__err__component_image_to_block.log',
					sprintf( 'Err importing image URL %s error: %s', $url, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ),
					$this->logger::WARNING
				);

				continue;
			}

			$attachment_ids[] = $attachment_id;
		}

		$blocks[] = $this->gutenberg_blocks->get_jetpack_slideshow( $attachment_ids );

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $sidebar_component Component data.
	 * @param int   $post_id           Post ID.
	 * @param bool  $refresh_attachment_data Whether to refresh attachment data.
	 *
	 * @throws \RuntimeException If argument is not set in context.
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns _________________.
	 */
	public function component_sidebar_to_block( $sidebar_component, $post_id, $refresh_attachment_data ) {
		$blocks = [];

		$inner_blocks = [];
		foreach ( $sidebar_component['sidebar']['body'] as $component ) {
			// Get method name and arguments.
			$method    = self::COMPONENT_CONVERTERS[ $component['__typename'] ]['method'];
			$arguments = [];
			foreach ( self::COMPONENT_CONVERTERS[ $component['__typename'] ]['arguments'] as $key_argument => $argument ) {
				if ( ! isset( $$argument ) ) {
					throw new \RuntimeException( sprintf( "Argument $%s not set in context and can't be passed to method %s() as argument number %d.", $argument, $method, $key_argument ) );
				}
				$arguments[] = $$argument;
			}

			// Call the method and merge resulting converted block.
			$inner_blocks = array_merge( $inner_blocks, call_user_func_array( 'self::' . $method, $arguments ) );
		}

		$group_classes = [ 'group-sidebar' ];

		if ( array_key_exists( 'placement', $sidebar_component ) && array_key_exists( 'alignment', $sidebar_component['placement'] ) && $sidebar_component['placement']['alignment'] ) {
			$group_classes[] = 'group-sidebar-align-' . strtolower( $sidebar_component['placement']['alignment'] );
		}

		$group_block = $this->gutenberg_blocks->get_group_constrained( $inner_blocks, $group_classes );
		$blocks[]    = $group_block;

		return $blocks;
	}

	/**
	 * Converts Chorus content component to Gutenberg block(s).
	 *
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks().
	 */
	public function component_related_list_to_block( $component ) {
		if ( ! array_key_exists( 'items', $component ) ) {
			return [];
		}

		$blocks = [];

		$li_elements = [];
		foreach ( $component['items'] as $item ) {
			$li_elements[] = sprintf( "<a href='%s'><strong>%s</strong></a>", $item['url'], $item['title'] );
		}

		if ( ! empty( $li_elements ) ) {
			$blocks[] = $this->gutenberg_blocks->get_separator( 'is-style-wide' );
			$blocks[] = $this->gutenberg_blocks->get_paragraph( '<strong>Related:</strong>' );
			$blocks[] = $this->gutenberg_blocks->get_list( $li_elements, true );
			$blocks[] = $this->gutenberg_blocks->get_separator( 'is-style-wide' );
		}

		return $blocks;
	}

	/**
	 * Get entry data from data entry.
	 *
	 * @param array  $data_entry      Data entry.
	 * @param string $entry_file_path Entry file path.
	 * @return array Entry data.
	 */
	private function get_entry_from_data_entry( $data_entry, $entry_file_path ) {
		if ( array_key_exists( '__typename', $data_entry ) ) {
			return $data_entry;
		}

		if ( ! array_key_exists( 'data', $data_entry ) || ! array_key_exists( 'entry', $data_entry['data'] ) ) {
			WP_CLI::error( 'No entry found in data entry: ' . $entry_file_path );
		}

		return $data_entry['data']['entry'];
	}

	/**
	 * Get imported posts original IDs.
	 *
	 * @param string $meta_key Meta key to search for.
	 *
	 * @return array
	 */
	private function get_posts_meta_values_by_key( $meta_key ) {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s",
				$meta_key
			)
		);
	}

	/**
	 * Get post ID by meta.
	 *
	 * @param string $meta_name Meta name.
	 * @param string $meta_value Meta value.
	 * @return int|null
	 */
	private function get_post_id_by_meta( $meta_name, $meta_value ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
				$meta_name,
				$meta_value
			)
		);
	}

	/**
	 * Get post ID by meta.
	 *
	 * @param array $lead_image Lead image data.
	 * @return string|null Caption.
	 */
	private function get_caption_from_asset( $lead_image ) {
		$caption = null;

		if ( isset( $lead_image['caption']['html'] ) ) {
			return $lead_image['caption']['html'];
		} elseif ( isset( $lead_image['asset']['sourceCaption'] ) ) {
			return $lead_image['asset']['sourceCaption'];
		} elseif ( isset( $lead_image['asset']['captionHtml'] ) ) {
			return $lead_image['asset']['captionHtml'];
		} elseif ( isset( $lead_image['asset']['title'] ) ) {
			return $lead_image['asset']['title'];
		}

		return $caption;
	}

	/**
	 * Get author display name.
	 *
	 * @param array $author_profile Author profile.
	 * @return string Author display name.
	 */
	private function get_author_display_name( $author_profile ) {
		$display_name = $author_profile['name'];

		if ( ! empty( $author_profile['title'] ) ) {
			$display_name .= ', ' . $author_profile['title'];
		}

		return $display_name;
	}
}
