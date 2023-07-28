<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \WP_CLI;

/**
 * Custom migration scripts for Lookout Local.
 */
class LookoutLocalMigrator implements InterfaceCommand {

	const CUSTOM_ENTRIES_TABLE = 'newspack_entries';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments Instance.
	 */
	private $attachments;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments = new Attachments();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-get-posts-from-data-export-table',
			[ $this, 'cmd_get_posts_from_data_export_table' ],
			[
				'shortdesc' => 'Extracts all posts JSONs from the huge `Record` table into a new custom table called self::CUSTOM_ENTRIES_TABLE.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-import-posts',
			[ $this, 'cmd_import_posts' ],
			[
				'shortdesc' => 'Imports posts from JSONs in  self::CUSTOM_ENTRIES_TABLE.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator lookoutlocal-get-posts-from-data-export-table`.
	 *
	 * @param array $pos_args   Array of positional arguments.
	 * @param array $assoc_args Array of associative arguments.
	 *
	 * @return void
	 */
	public function cmd_get_posts_from_data_export_table( $pos_args, $assoc_args ) {
		global $wpdb;

		// Table we got from the client.
		$record_table   = 'Record';

		// Two helper tables.
		// First table filters out just the posts and has a `slug` colum (removes requests, logs, dumps and all garbage).
		$filtered_data_table = 'newspack_filter';
		// Second table picks only the newst post and should be converted to posts.
		$entries_table    = 'newspack_entries';

		// Remove second table.
		// Rename filter to self::CUSTOM_ENTRIES_TABLE.
		// Do a second pass grouping by slugs and leaving just the most recent entry.

		$this->create_custom_table( $filtered_data_table, $truncate = true );
		$this->create_custom_table( $entries_table, $truncate = true );

		$offset = 0;
		$batchSize = 1000;
		$total_rows = $wpdb->get_var( "SELECT count(*) FROM {$record_table}" );
		$total_batches = ceil( $total_rows / $batchSize );
		while ( true ) {

			WP_CLI::line( sprintf( "%d/%d getting posts from %s into %s ...", $offset, $total_rows, $record_table, $filtered_data_table ) );

			// Query in batches.
			$sql = "SELECT * FROM {$record_table} ORDER BY id, typeId ASC LIMIT $batchSize OFFSET $offset";
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( count( $rows ) > 0 ) {
				foreach ( $rows as $row ) {

					// Get JSON data. It might be readily decodable, or double backslashes may have to be removed up to two times.
					$data_result = $row[ 'data' ];
					$data = json_decode( $data_result, true );
					if ( ! $data ) {
						$data_result = str_replace( "\\\\", "\\", $data_result ); // Replace double escapes with just one escape.
						$data = json_decode( $data_result, true );
						if ( ! $data ) {
							$data_result = str_replace( "\\\\", "\\", $data_result ); // Replace double escapes with just one escape.
							$data = json_decode( $data_result, true );
						}
					}

					// Check if this is a post.
					$slug = $data['sluggable.slug'] ?? null;
					$title = $data['headline'] ?? null;
					$post_content = $data['body'] ?? null;
					$is_a_post = $slug && $title && $post_content;

					if ( $is_a_post ) {
						$wpdb->insert( $filtered_data_table, [ 'slug' => $slug, 'data' => json_encode( $data ) ] );
					}
				}

				$offset += $batchSize;
			} else {
				break;
			}
		}

		WP_CLI::line( 'Done' );
	}

	public function cmd_import_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		$data_jsons = $wpdb->get_col( "SELECT data from %s", self::CUSTOM_ENTRIES_TABLE );
		foreach ( $data_jsons as $data_json ) {
			$data = json_encode( $data_json, true );

			// Get post data.
			$slug = $data['sluggable.slug'];
			$title = $data['headline'];
			$subheadline = $data['subHeadline'];
			$post_content = $data['body'];
			$post_date = $this->convert_epoch_timestamp_to_wp_format( $data['cms.content.publishDate'] );

			// Create post.
			$post_args = [
				'post_title' => $title,
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_type' => 'post',
				'post_name' => $slug,
				'post_date' => $post_date,
			];
			$post_id = wp_insert_post( $post_args );

			// Postmeta.
			$postmeta = [
				"newspack_commentable.enableCommenting" => $data["commentable.enableCommenting"],
			];
			if ( $subheadline ) {
				$postmeta['newspack_post_subtitle'] = $subheadline;
			}

			// Additional post update data.
			$post_modified = $this->convert_epoch_timestamp_to_wp_format( $data['publicUpdateDate'] );
			$post_update_data = [
				'post_modified'	=> $post_modified,
			];

			// Post URL.

			// Categories.
			// TODO -- is this a taxonomy?
			$data["sectionable.section"];
			// Can be single entry:
			//      "_ref": "00000180-62d1-d0a2-adbe-76d9f9e7002e",
			//      "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			$data["sectionable.secondarySections"];
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "00000175-7fd0-dffc-a7fd-7ffd9e6a0000",
			//          "_type": "ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"
			//      }
			// ]

			// Authors.
			$data["authorable.authors"];
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "0000017e-5a2e-d675-ad7e-5e2fd5a00000",
			//          "_type": "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
			//      }
			// ]
			$data["authorable.oneOffAuthors"];
			// TODO - search where not empty and see how it's used.
			$data["authorable.primaryAuthorBioOverride"];
			// ? TODO - search where not empty and see how it's used.
			$data["hasSource.source"];
			// Can be single entry:
			//      "_ref": "00000175-66c8-d1f7-a775-eeedf7280000",
			//      "_type": "289d6a55-9c3a-324b-9772-9c6f94cf4f88"


			// Featured image.
			$data['lead'];
			// These two fields:
			//     "_id": "00000184-6982-da20-afed-7da6f7680000",
			//     "_type": "52f00ba5-1f41-3845-91f1-1ad72e863ccb"
			$data['lead'][ 'leadImage' ];
			// Can be single entry:
			//      "_ref": "0000017b-75b6-dd26-af7b-7df6582f0000",
            //      "_type": "4da1a812-2b2b-36a7-a321-fea9c9594cb9"
			$caption = $data['lead'][ 'caption' ];
			$hide_caption = $data['lead'][ 'hideCaption' ];
			$credit = $data['lead'][ 'credit' ];
			$alt = $data['lead'][ 'altText' ];
			// TODO -- find url and download image.
			$url;
			$attachment_id = $this->attachments->import_external_file( $url, $title = null, ( $hide_caption ? $caption : null ), $description = null, $alt, $post_id, $args = [] );
			set_post_thumbnail( $post_id, $attachment_id );

			// Tags.
			$data["taggable.tags"];
			// TODO -- find associated tags?
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "00000175-ecb8-dadf-adf7-fdfe01520000",
			//          "_type": "90602a54-e7fb-3b69-8e25-236e50f8f7f5"
			//      }
			// ]
		}

	}

	public function convert_epoch_timestamp_to_wp_format( $timestamp ) {
		$timestamp_seconds = intval( $timestamp ) / 1000;
		$readable = date('Y-m-d H:i:s', $timestamp_seconds);

		return $readable;
	}

	public function create_custom_table( $table_name, $truncate = false ) {
		global $wpdb;

		$wpdb->get_results(
			"CREATE TABLE IF NOT EXISTS {$table_name} (
				`id` INT unsigned NOT NULL AUTO_INCREMENT,
				`slug` TEXT,
				`data` TEXT,
				PRIMARY KEY (`id`)
			) ENGINE=InnoDB;"
		);

		if ( true === $truncate ) {
			$wpdb->get_results( "TRUNCATE TABLE {$table_name};" );
		}
	}
}
