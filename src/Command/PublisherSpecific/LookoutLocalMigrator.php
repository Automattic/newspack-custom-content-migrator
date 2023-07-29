<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Utils\PHP as PHP_Utils;
use \WP_CLI;

/**
 * Custom migration scripts for Lookout Local.
 */
class LookoutLocalMigrator implements InterfaceCommand {

	const DATA_EXPORT_TABLE = 'Record';
	const CUSTOM_ENTRIES_TABLE = 'newspack_entries';
	const LOOKOUT_S3_SCHEMA_AND_HOSTNAME = 'https://lookout-local-brightspot.s3.amazonaws.com';

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
		WP_CLI::add_command(
			'newspack-content-migrator lookoutlocal-dev',
			[ $this, 'cmd_dev' ],
			[
				'shortdesc' => 'Temp dev command for various research snippets.',
				'synopsis'  => [],
			]
		);
	}

	public function cmd_dev( $pos_args, $assoc_args ) {
		global $wpdb;

		/**
		 * Locate "authorable.authors".
		 */
		// take example post from live with known author "Thomas Sawano"
		$json = $wpdb->get_var( "select data from newspack_entries where slug = 'university-of-california-academic-workers-uaw-strike-update';" );
		$data = json_decode( $json, true );
		/**
		 * Has:
		 * authorable.authors = {array[1]}
		 * 0 = {array[2]}
		 * _ref = "00000182-b2df-d6aa-a783-b6dfd7b50000"
		 * _type = "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
		 */
		foreach ( $data["authorable.authors"] as $data_author ) {
			$authorable_author_id = $data_author['_ref'];
			$authorable_author_type = $data_author['_type'];
			$id_like = sprintf( '"_id":"%s"', $authorable_author_id );
			$type_like = sprintf( '"_type":"%s"', $authorable_author_type );
			// Find author in DB.
			$author_json = $wpdb->get_var( "select data from Record where data like '{\"cms.site.owner\"%' and data like '%{$id_like}%' and data like '%{$type_like}%';" );
// Dev test:
// $author_json = <<<JSON
// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.directory.paths":["00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano"],"cms.directory.pathTypes":{"00000175-41f4-d1f7-a775-edfd1bd00000:00000175-8091-dffc-a7fd-ecbd1d2d0000/thomas-sawano":"PERMALINK"},"cms.content.publishDate":1660858690827,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660927400870,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","features.disabledFeatures":[],"shared.content.rootId":null,"shared.content.sourceId":null,"shared.content.version":null,"canonical.canonicalUrl":null,"promotable.hideFromDynamicResults":false,"catimes.seo.suppressSeoSiteDisplayName":false,"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"cms.seo.keywords":[],"cms.seo.robots":[],"commentable.enableCommenting":false,"feed.disableFeed":false,"feed.renderFullContent":false,"feed.enabledFeedItemTypes":[],"image":{"_ref":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"cover":{"_ref":"00000182-b2de-d6aa-a783-b6dff3bf0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"},"section":{"_ref":"00000175-7fd0-dffc-a7fd-7ffd9e6a0000","_type":"ba7d9749-a9b7-3050-86ad-15e1b9f4be7d"},"name":"Thomas Sawano","firstName":"Thomas","lastName":"Sawano","title":"Newsroom Intern","email":"thomas@lookoutlocal.com","fullBiography":"Thomas Sawano joins the Lookout team after two-and-a-half years at City on a Hill Press, the student-run newspaper at UCSC. While there, he reported on the university, arts and culture events, and the city of Santa Cruz. Thomas is deeply interested in local politics and feels fortunate to have begun his journalistic career in this town.<br/><br/>Thomas graduated in 2022 with degrees in Cognitive Science and Philosophy. Though hailing from Los Angeles, he has vowed to never live there again on account of traffic and a lack of actual weather. Thomas loves traveling, going to music festivals, and watching documentaries about the outdoors. He has recently picked up rock climbing, and hopes the sport won’t damage his typing hands <i>too </i>badly.<br/><br/>","shortBiography":"","affiliation":"Lookout Santa Cruz","isExternal":false,"theme.lookout-local.:core:page:Page.hbs._template":null,"theme.lookout-local.:core:promo:Promo.hbs.breaking":false,"theme.lookout-local.:core:promo:Promo.hbs.imageDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.descriptionDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.categoryDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.timestampDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.moreCoverageLinksDisplay":null,"theme.lookout-local.:core:promo:Promo.hbs.promoAlignment":null,"theme.lookout-local.:core:promo:Promo.hbs._template":null,"theme.lookout-local.:core:promo:Promo.amp.hbs._template":null,"cms.directory.pathsMode":"MANUAL","_id":"00000182-b2df-d6aa-a783-b6dfd7b50000","_type":"7f0435e9-b5f5-3286-9fe0-e839ddd16058"}
// JSON;
			$author = json_decode( $author_json, true );
			// Also exist ['cover']['_ref'] and ['section']['_ref'].
			$full_name = $author['name'];
			$first_name = $author['firstName'];
			$last_name = $author['lastName'];
			$email = $author['email'];
			$bio = $author['fullBiography'];
			$short_bio = $author['shortBiography'];
			// E.g. "Newsroom Intern"
			$title = $author['title'];
			// E.g. "Lookout Santa Cruz"
			$affiliation = $author['affiliation'];
			// External to their publication.
			$is_external = $author['isExternal'];

			// Avatar image.
			$image_ref = $author['image']['_ref'];
			$image_type = $author['image']['_type'];
			$sql = "select data from Record where data like '{\"cms.site.owner\"%' and data like '%\"_id\":\"{$image_ref}\"%' and data like '%\"_type\":\"{$image_type}\"%' ;";
			$image_json = $wpdb->get_var( $sql );
// Dev test:
// $image_json = <<<JSON
// {"cms.site.owner":{"_ref":"00000175-41f4-d1f7-a775-edfd1bd00000","_type":"ae3387cc-b875-31b7-b82d-63fd8d758c20"},"watcher.watchers":[{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"}],"cms.content.publishDate":1660858629241,"cms.content.publishUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"cms.content.updateDate":1660858674492,"cms.content.updateUser":{"_ref":"0000017d-a0bb-d2a9-affd-febb7bc60000","_type":"6aa69ae1-35be-30dc-87e9-410da9e1cdcc"},"l10n.locale":"en-US","shared.content.version":"00000182-b2e4-daa2-a5fe-b2ed30fe0000","taggable.tags":[],"hasSource.source":{"_ref":"00000175-66c8-d1f7-a775-eeedf7280000","_type":"289d6a55-9c3a-324b-9772-9c6f94cf4f88"},"type":{"_ref":"a95896f6-e74f-3667-a305-b6a50d72056a","_type":"982a8b2a-7600-3bb0-ae68-740f77cd85d3"},"titleFallbackDisabled":false,"file":{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/thomas-sawano-white.png","contentType":"image/png","metadata":{"cms.edits":{},"originalFilename":"Thomas Sawano white.png","http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["1074663"],"Content-Type":["image/png"]},"resizes":[{"storage":"s3","path":"5b/22/5bc8405647bb99efdd5473aba858/resizes/500/thomas-sawano-white.png","contentType":"image/png","metadata":{"width":500,"height":500,"http.headers":{"Cache-Control":["public, max-age=31536000"],"Content-Length":["349214"],"Content-Type":["image/png"]}}}],"width":1080,"File Type":{"Detected File Type Long Name":"Portable Network Graphics","Detected File Type Name":"PNG","Detected MIME Type":"image/png","Expected File Name Extension":"png"},"PNG-IHDR":{"Filter Method":"Adaptive","Interlace Method":"No Interlace","Compression Type":"Deflate","Image Height":"1080","Color Type":"True Color with Alpha","Image Width":"1080","Bits Per Sample":"8"},"PNG-pHYs":{"Pixels Per Unit X":"3780","Pixels Per Unit Y":"3780","Unit Specifier":"Metres"},"PNG-tEXt":{"Textual Data":"Comment: xr:d:DAE5wFeyjSQ:518,j:33207655899,t:22081821"},"height":1080,"cms.crops":{},"cms.focus":{"x":0.4397042465484525,"y":0.2428842504743833}}},"keywords":[],"keywordsFallbackDisabled":false,"dateUploaded":1660858629241,"caption":"","captionFallbackDisabled":false,"credit":"","creditFallbackDisabled":false,"altText":"Thomas Sawano","bylineFallbackDisabled":false,"instructionsFallbackDisabled":false,"sourceFallbackDisabled":false,"copyrightNoticeFallbackDisabled":false,"headlineFallbackDisabled":false,"categoryFallbackDisabled":false,"supplementalCategory":[],"supplementalCategoryFallbackDisabled":false,"writerFallbackDisabled":false,"countryFallbackDisabled":false,"countryCodeFallbackDisabled":false,"origTransRefFallbackDisabled":false,"metadataStateFallbackDisabled":false,"cityFallbackDisabled":false,"width":1080,"height":1080,"_id":"00000182-b2e2-d6aa-a783-b6f3f19d0000","_type":"4da1a812-2b2b-36a7-a321-fea9c9594cb9"}
// JSON;
			$image = json_decode( $image_json, true );
			if ( 's3' != $image['file']['storage'] ) {
				// Debug this.
				$d=1;
			}
			$image_url = self::LOOKOUT_S3_SCHEMA_AND_HOSTNAME . '/' . $image['file']['path'];
			$image_title = $image['file']['metadata']['originalFilename'];
			$image_alt = $image['altText'];
		}
		$authorable_author_id = $data["authorable.authors"]['_ref'];
		// ,"_type":"7f0435e9-b5f5-3286-9fe0-e839ddd16058"

		return;


		/**
		 * Get post data from newspack_entries
		 */
		$json = $wpdb->get_var( "SELECT data FROM newspack_entries where slug = 'first-image-from-nasas-james-webb-space-telescope-reveals-thousands-of-galaxies-in-stunning-detail';" );
		$data = json_decode( $json, true );
		return;


		/**
		 * Decode JSONs from file
		 */
		$lines = explode( "\n", file_get_contents( '/Users/ivanuravic/www/lookoutlocal/app/public/0_examine_DB_export/search/authorable_oneoff.log' ) );
		$jsons = [];
		foreach ( $lines as $line ) {
			$data = json_decode( $line, true );
			if ( ! $data ) {
				$line = str_replace( "\\\\", "\\", $line ); // Replace double escapes with just one escape.
				$data = json_decode( $line, true );
				if ( ! $data ) {
					$line = str_replace( "\\\\", "\\", $line ); // Replace double escapes with just one escape.
					$data = json_decode( $line, true );
					if ( $data ) { $jsons[] = $data; }
				} else { $jsons[] = $data; }
			} else { $jsons[] = $data; }
		}
		$d=1;
		$jsons_long = json_encode( $jsons );
		return;

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

		// Table names.
		$record_table = self::DATA_EXPORT_TABLE;
		$custom_table = self::CUSTOM_ENTRIES_TABLE;

		// Check if Record table is here.
		$count_record_table = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_NAME = %s;", $record_table ) );
		if ( 1 != $count_record_table ) {
			WP_CLI::error( sprintf( 'Table %s not found.', $record_table ) );
		}

		$continue = PHP_Utils::readline( sprintf( "Continuing will truncate the existing %s table. Continue? [y/n] ", $record_table ) );
		if ( 'y' !== $continue ) {
			WP_CLI::error( 'Aborting.' );
		}

		// Create/truncate custom table.
		$this->create_custom_table( $custom_table, $truncate = true );

		// Read from $record_table and write just posts entries to $custom_table.
		$offset = 0;
		$batchSize = 1000;
		$total_rows = $wpdb->get_var( "SELECT count(*) FROM {$record_table}" );
		$total_batches = ceil( $total_rows / $batchSize );
		while ( true ) {

			WP_CLI::line( sprintf( "%d/%d getting posts from %s into %s ...", $offset, $total_rows, $record_table, $custom_table ) );

			// Query in batches.
			$sql = "SELECT * FROM {$record_table} ORDER BY id, typeId ASC LIMIT $batchSize OFFSET $offset";
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( count( $rows ) > 0 ) {
				foreach ( $rows as $row ) {

					// Get row JSON data. It might be readily decodable, or double backslashes may have to be removed up to two times.
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
					if ( ! $is_a_post ) {
						continue;
					}

					// Insert to custom table
					$wpdb->insert( $custom_table, [ 'slug' => $slug, 'data' => json_encode( $data ) ] );
				}

				$offset += $batchSize;
			} else {
				break;
			}
		}

		// Group by slugs and leave just the most recent entry.

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


			// Get more postmeta.
			$postmeta = [
				"newspack_commentable.enableCommenting" => $data["commentable.enableCommenting"],
			];
			if ( $subheadline ) {
				$postmeta['newspack_post_subtitle'] = $subheadline;
			}


			// Get more post data to update all at once.
			$post_modified = $this->convert_epoch_timestamp_to_wp_format( $data['publicUpdateDate'] );
			$post_update_data = [
				'post_modified'	=> $post_modified,
			];


			// Post URL.
			// TODO -- find post URL for redirect purposes and store as meta. Looks like it's stored as "canonicalURL" in some related entries.
			// ? "paths" data ?

			// Post excerpt.
			// TODO -- find excerpt.


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


			// Authors.
			// TODO - search these two fields. Find bios, avatars, etc by checking staff pages at https://lookout.co/santacruz/about .
			$data["authorable.authors"];
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "0000017e-5a2e-d675-ad7e-5e2fd5a00000",
			//          "_type": "7f0435e9-b5f5-3286-9fe0-e839ddd16058"
			//      }
			// ]
			$data["authorable.oneOffAuthors"];
			// Can be multiple entries:
			// [
			// 	{
			// 		"name":"Corinne Purtill",
			// 		"_id":"d6ce0bcd-d952-3539-87b9-71bdb93e98c7",
			// 		"_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// 	},
			// 	{
			// 		"name":"Sumeet Kulkarni",
			// 		"_id":"434ebcb2-e65c-32a6-8159-fb606c93ee0b",
			// 		"_type":"6d79db11-1e28-338b-986c-1ff580f1986a"
			// 	}
			// ]

			$data["authorable.primaryAuthorBioOverride"];
			// ? TODO - search where not empty and see how it's used.
			$data["hasSource.source"];
			// Can be single entry:
			//      "_ref": "00000175-66c8-d1f7-a775-eeedf7280000",
			//      "_type": "289d6a55-9c3a-324b-9772-9c6f94cf4f88"


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


			// Tags.
			$data["taggable.tags"];
			// TODO -- find tags
			// Can be multiple entries:
			// [
			//      {
			//          "_ref": "00000175-ecb8-dadf-adf7-fdfe01520000",
			//          "_type": "90602a54-e7fb-3b69-8e25-236e50f8f7f5"
			//      }
			// ]


			// Save postmeta.
			foreach ( $postmeta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}


			// Update post data.
			if ( ! empty( $post_update_data ) ) {
				$wpdb->update( $wpdb->posts, $post_update_data, [ 'ID' => $post_id ] );
			}
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
