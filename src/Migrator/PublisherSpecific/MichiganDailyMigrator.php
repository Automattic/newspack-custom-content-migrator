<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Redirection as RedirectionLogic;
use \WP_CLI;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Custom migration scripts for Michigan Daily.
 */
class MichiganDailyMigrator implements InterfaceMigrator {

	const META_OLD_NODE_ID            = '_fgd2wp_old_node_id';
	/**
	 * If this meta is set, means that the Drupal article header was already set on this Post.
	 */
	const META_ARTICLE_HEADER_UPDATED       = '_article_header_updated';
	/**
	 * If one of these two metas is set, means that the Drupal URL alias was already set up for this Post (either by updating the
	 * post_name, or by creating a Redirection rule).
	 */
	const META_SLUG_UPDATED_TO_DRUPAL_ALIAS            = '_slug_updated_to_drupal_alias';
	const META_SLUG_REDIRECTION_RULE_FROM_DRUPAL_ALIAS = '_slug_redirection_rule_from_drupal_alias';

	/**
	 * Used by the `newspack-content-migrator michigan-daily-set-wp-user-authors-from-guest-authors` commands, set on Posts which
	 * we've updated by assigning their author to be a new WP User created from Guest Authors. It's complicated, we're aware, but
	 * this whole migration was :)
	 */
	const META_AUTHOR_WP_USER_SET_FROM_GUEST_AUTHOR = '_newspack-wp_user_set_from_ga';

	/**
	 * Error log file names -- grouped by error types to make reviews easier.
	 */
	const LOG_FILE_ERR_POST_CONTENT_EMPTY           = 'michigandaily__postcontentempty.log';
	const LOG_FILE_ERR_UID_NOT_FOUND                = 'michigandaily__uidnotfound.log';
	const LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND  = 'michigandaily__cmdupdategas_uidnotfound.log';
	const LOG_FILE_ERR                              = 'michigandaily__err.log';
	const LOG_HEADER_UPDATE_POST_WITH_NID_NOT_FOUND = 'michigandaily__header_update_nid_not_found.log';
	const LOG_GALLERY_IMAGE_DOWNLOAD_FAILED         = 'michigandaily__gallery_image_download_failed.log';
	const LOG_GALLERY_IMAGE_NO_URI                  = 'michigandaily__gallery_image_no_uri.log';
	const LOG_GALLERY_ERR                           = 'michigandaily__gallery_err.log';
	const LOG_DISPLAY_IMAGE_DOWNLOAD_FAILED         = 'michigandaily__display_image_download_failed.log';
	const LOG_DISPLAY_IMAGE_NO_URI                  = 'michigandaily__display_image_no_uri.log';
	const LOG_DISPLAY_ERR                           = 'michigandaily__display_err.log';
	const LOG_USER_CREATE_ERR                       = 'michigandaily__user_create_err.log';
	const LOG_USER_CREATED                          = 'michigandaily__user_create.log';
	const LOG_USERS_LINKED_GA_TO_WP_USER            = 'michigandaily__user_linked.log';
	const LOG_USERS_POST_AUTHOR_UPDATED             = 'michigandaily__post_author_update.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var Crawler
	 */
	private $dom_crawler;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachments_logic;

	/**
	 * @var CoAuthorPlusLogic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var RedirectionLogic
	 */
	private $redirection_logic;

	/**
	 * @var SquareBracketsElementManipulator
	 */
	private $square_brackets_element_manipulator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->dom_crawler = new Crawler();

		$this->posts_logic         = new PostsLogic();
		$this->attachments_logic   = new AttachmentsLogic();
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->redirection_logic   = new RedirectionLogic();

		if ( ! class_exists( \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator::class ) ) {
			WP_CLI::error( 'This command requires the Newspack Content Converter plugin to be installed and activated.');
		}
		$this->square_brackets_element_manipulator = new \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-import-drupal-content',
			[ $this, 'cmd_import_drupal_content' ],
			[
				'shortdesc' => 'Imports Michigan Daily articles from original Drupal tables.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'reimport-all-posts',
						'description' => 'If this flag is set, all the nodes/posts will be reimported, otherwise will just incrementally import new ones.',
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-convert-drupal-shortcodes',
			[ $this, 'cmd_update_drupal_convert_shortcodes' ],
			[
				'shortdesc' => 'Converts shortcodes found in posts.'
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-update-drupal-node-header-content',
			[ $this, 'cmd_update_drupal_node_header_content' ],
			[
				'shortdesc' => 'Reads through node header HTML, gets featured images from those and assigns them to posts.'
			]
		);
		// This logic is now outdated -- use carefully.
		// WP_CLI::add_command(
		// 	'newspack-content-migrator michigan-daily-update-posts-guest-authors',
		// 	[ $this, 'cmd_update_post_guest_authors' ],
		// 	[
		// 		'shortdesc' => 'Helper DEV command -- this logic is already a part of the `michigan-daily-import-drupal-content` command, where it is even more advanced than here. This updates Guest Authors for all existing posts and sets them from the known DB relations.'
		// 	]
		// );
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-set-wp-user-authors-from-guest-authors',
			[ $this, 'cmd_set_wp_user_authors_from_guest_authors' ],
			[
				'shortdesc' => 'Goes through all the Posts which have author ID 0, gets their Guest Author, converts it to a WP User and links the new User to the GA, then assigns the WP User as the Post author too.'
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-unset-wp-user-authors-assigned-from-guest-authors',
			[ $this, 'cmd_unset_wp_user_authors_assigned_from_guest_authors' ],
			[
				'shortdesc' => 'Undos the `newspack-content-migrator michigan-daily-set-wp-user-authors-from-guest-authors` command, and sets the those Posts\' authors to 0.'
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-unset-wp-user-authors-where-guest-authors-exist',
			[ $this, 'cmd_unset_wp_user_authors_where_guest_authors_exist' ],
			[
				'shortdesc' => 'Sets posts\' author to 0 for all the posts which gave Guest Authors and also have the Post WP User Author assigned too.'
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-update-authors-and-dates-from-field-data-field-byline',
			[ $this, 'cmd_update_authors_from_field_data_field_byline' ],
			[
				'shortdesc' => 'Helper DEV command -- additional alternative formatting of how bylines and dates are stored handled here.'
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-update-drupal-post-slugs',
			[ $this, 'cmd_update_drupal_post_slugs' ],
			[
				'shortdesc' => 'Helper DEV command -- updates Drupal posts slugs.'
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-delete-imported-custom-post-type',
			[ $this, 'cmd_delete_imported_custom_post_type' ],
			[
				'shortdesc' => 'The Drupal importer plugin created `michigan_daily_artic` post types, and this command deletes those.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator michigan-daily-update-featured-image-from-meta-for-posts-which-do-not-have-one',
			[ $this, 'cmd_update_featured_image_for_posts_from_meta_which_do_not_have_one' ],
			[
				'shortdesc' => 'Using the `_thumbnail_id` meta, sets featured image to Posts which do not yet have a featured image.',
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator michigan-daily-import-drupal-content`.
	 */
	public function cmd_import_drupal_content( $args, $assoc_args ) {
		$reimport_all_posts = isset( $assoc_args['reimport-all-posts'] ) ? true : false;

		global $wpdb;
		$time_start = microtime( true );

		// Flush the log files.
		@unlink( self::LOG_FILE_ERR_POST_CONTENT_EMPTY );
		@unlink( self::LOG_FILE_ERR_UID_NOT_FOUND );
		@unlink( self::LOG_FILE_ERR );

		// Prefetch Drupal data for speed.
		WP_CLI::line( 'Fetching data from Drupal tables...' );
		$taxonomy_term_data_all_rows = $wpdb->get_results( "select * from taxonomy_term_data;", ARRAY_A );
		$field_data_field_byline_all_rows = $wpdb->get_results( "select entity_id, field_byline_value from field_data_field_byline where entity_type = 'node';" );
		$field_full_name_value_all_rows = $wpdb->get_results(
			"select fn.entity_id, fn.field_full_name_value
			from node n
			join users u on u.uid = n.uid
			join field_data_field_full_name fn on fn.entity_id = n.uid
			where n.type in ( 'article', 'michigan_daily_article' )
			group by fn.entity_id;",
			ARRAY_A
		);
		$field_data_field_first_name_and_last_name_all_rows = $wpdb->get_results(
			"select
				n.uid,
				concat( fn.field_first_name_value, ' ', ln.field_last_name_value ) as full_name
			from node n
			join users u on u.uid = n.uid
			join field_data_field_last_name ln on ln.entity_id = n.uid
			join field_data_field_first_name fn on fn.entity_id = n.uid
			where n.type in ( 'article', 'michigan_daily_article' )
			group by n.uid;",
			ARRAY_A
		);
		$field_data_field_twitter_all_rows = $wpdb->get_results(
			"select t.entity_id, t.field_twitter_value
			from field_data_field_twitter t
			join node n on n.uid = t.entity_id
			where t.entity_type = 'user'
			group by n.uid;",
			ARRAY_A
		);
		$url_alias_all_rows = $wpdb->get_results( "select source, alias from url_alias;", ARRAY_A );

		$post_ids_already_imported = [];
		if ( ! $reimport_all_posts ) {
			$post_ids_already_imported = $this->get_existing_nid_id_map();
		}

		// `type` 'article' is legacy (they imported it over from a previous system to Drupal, and will not have Taxonomy here
		// in Drupal), and `type` 'michigan_daily_article' is their regular Post node type.
		$nodes = $this->get_drupal_all_nodes_by_type( [ 'michigan_daily_article', 'article' ] );

		// Get the node headers.
		$field_data_field_article_header_all_rows = $this->get_article_header_rows( $nodes );
		$article_headers_rows_for_update = [];

// TODO -- remove temp DEV:
// $nodes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM node WHERE nid IN ( 150434, 150188, 150176 )" ), ARRAY_A );

		foreach ( $nodes as $i => $node ) {
			$nid = $node['nid'];

			WP_CLI::line( sprintf( '- (%d/%d) importing nid %d ...', $i + 1, count( $nodes ), $node['nid'] ) );
			// If not reimporting existing posts, continue.
			if ( ( false === $reimport_all_posts ) && isset( $post_ids_already_imported[ $node['nid'] ] ) ) {
				WP_CLI::line( '✓ post already imported, skipping.' );
				continue;
			}

			// Get the Post if it already exists.
			$post_ids = $this->posts_logic->get_posts_with_meta_key_and_value( self::META_OLD_NODE_ID, $node['nid'] );
			$post_id  = isset( $post_ids[0] ) ? $post_ids[0] : null;
			$post     = get_post( $post_id );

			// Reset data in the loop.
			$post_data = [
				'post_type'    => 'post',
				'post_status'  => 'publish',
			];

			// Basic data -- status, date, title.
			$post_data[ 'post_status' ] = ( 1 == $node[ 'status' ] ) ? 'publish' : 'draft';
			$post_data[ 'post_title' ]  = $node[ 'title' ] ?? ( $post->post_title ?? null );

			// Get the Post content.
			$drupal_field_data_body_row  = $this->get_drupal_field_data_body( $node['nid'] );
			$post_data[ 'post_content' ] = $this->get_post_content_from_node_body_raw( $drupal_field_data_body_row[ 'body_value' ] ?? '' );

			// Get the p.info element and scrape author and date from it, if available.
			$post_info_scraped = $this->get_post_p_info_contents( $drupal_field_data_body_row[ 'body_value' ] ?? '' );
			$date_scraped      = null;
			$author_scraped    = null;
			if ( $post_info_scraped ) {
				$date_scraped   = $this->extract_date_from_p_info( $post_info_scraped );
				$author_scraped = $this->extract_author_from_p_info( $post_info_scraped );
				// There are several invalid bylines (e.g. nid 204836), this takes care of those.
				if ( strlen( $author_scraped ) < 4 ) {
					$author_scraped = null;
				}
			}

			// Set published date, first trying to use the scraped p.info contents, or use the node.created date.
			$post_data[ 'post_date' ] = $date_scraped
				? $date_scraped
				: gmdate( 'Y-m-d H:i:s', $node[ 'created' ] );

			// If there was no content when scraping the 'div.main' from the `body_value` column , but there's still some content in the `body_value`, use that.
			if ( ! $post_data[ 'post_content' ]
				&& (
					isset( $drupal_field_data_body_row[ 'body_value' ] )
					&& ! empty( trim( $drupal_field_data_body_row[ 'body_value' ] ) )
				)
			) {
				$post_data[ 'post_content' ] = $drupal_field_data_body_row[ 'body_value' ];
			}

			// If post_content is still empty, skip importing this Post, or trash it if it was already imported by the Drupal converter plugin.
			if ( empty( trim ( $post_data[ 'post_content' ] ) ) ) {
				if ( $post ) {
					$wpdb->update( $wpdb->prefix . 'posts', [ 'post_status' => 'trash' ], [ 'ID' => $post->ID ] );
				}
				$this->log( self::LOG_FILE_ERR_POST_CONTENT_EMPTY, sprintf( 'node_id %d ID %d', $node['nid'], $post->ID ?? '/' ) );
				continue;
			}

			// Get the excerpt.
			$post_data[ 'post_excerpt' ] = $drupal_field_data_body_row[ 'body_summary' ] ?? ( $post->post_excerpt ?? '' ) ;

			// Convert Drupal Taxonomies into WP Categories.
			$nodes_taxonomy_names      = $this->get_drupal_taxonomy_names_by_node_id( $node['nid'], $taxonomy_term_data_all_rows );
			$post_data[ 'post_category' ] = [];
			if ( ! empty( $nodes_taxonomy_names ) ) {
				foreach ( $nodes_taxonomy_names as $nodes_taxonomy_name ) {
					$category                       = $this->get_or_create_category( $nodes_taxonomy_name );
					$post_data[ 'post_category' ][] = $category->term_id;
				}
			}

			/**
			 * Skipped importing original URL slugs, because I believe complex slugs with extra path levels can't be imported,
			 * e.g. a random node from their DB:
			 *      nid: 225662
			 *      original slug: 'section/film/magic-mike-xxl-gives-women-what-they-want'
			 * However, I'm sharing and noting here how the original slugs can be fetched:
			 * ```
			 *      select url_alias.alias
			 *      from url_alias
			 *      join node
			 *              on url_alias.source = concat( "node/", node.nid )
			 *              and ( node.type = "article" or node.type = "michigan_daily_article" )
			 *      where node.nid = 225662;
			 * ```
			 */

			// Create a new post.
			$post_id = null;
			if ( ! $post ) {
				$post_id  = wp_insert_post( $post_data );
				if ( 0 === $post_id || is_wp_error( $post_id ) ) {
					$msg = sprintf( "Could not save new Post from nid = %d", $node['nid'] );
					WP_CLI::warning( $msg );
					$this->log( self::LOG_FILE_ERR, sprintf( 'node_id %d ID %d', $node['nid'], $post->ID ?? '/' ) );

					continue;
				}

				// Set orig `nid` meta.
				add_post_meta( $post_id, self::META_OLD_NODE_ID, $node['nid'] );

				WP_CLI::line( sprintf( '✓ created new Post ID %d...', $post_id ) );
			} else {
				// Or update this existing post.
				$post_categories = isset( $post_data['post_category'] ) && ! empty( $post_data['post_category'] )
					? $post_data['post_category']
					: null;
				unset( $post_data['post_category'] );

				$res = $wpdb->update(
					$wpdb->prefix . 'posts',
					$post_data,
					[ 'ID' => $post->ID ]
				);
				if ( false === $res ) {
					$msg = sprintf( 'Could not update existing post ID %d with $post_data: %s', $post->ID, json_encode( $post_data ) );
					WP_CLI::warning( $msg );
					$this->log( self::LOG_FILE_ERR, $msg );

					continue;
				}

				// Set categories.
				if ( $post_categories ) {
					wp_set_post_categories( $post->ID, $post_categories, false );
				}

				WP_CLI::line( sprintf( '✓ updated existing Post ID %d...', $post->ID ) );

				$post_id = $post->ID;
			}

			// Use author name scraped from the p.info, or fetch it from one of the known DB relations.
			$full_name = $author_scraped
				? $author_scraped
				: $this->get_drupal_user_full_name(
					$node['uid'],
					$field_full_name_value_all_rows,
					$field_data_field_first_name_and_last_name_all_rows,
					$field_data_field_twitter_all_rows
				);

			// Assign the Guest Author to the Post.
			if ( $full_name ) {
				$guest_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $full_name ] );
				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post_id );
			} else {
				$this->log( self::LOG_FILE_ERR_UID_NOT_FOUND, sprintf( 'uid %d, nid %d, ID %d', $node['uid'], $node['nid'], $post->ID ) );
			}

			$header_for_this_article = $this->search_array_by_key_and_value( $field_data_field_article_header_all_rows, [ 'entity_id' => $node['nid'] ] );
			if ( null !== $header_for_this_article ) {
				$article_headers_rows_for_update[] = $header_for_this_article;
			}
		}

		// Additionally prepend Drupal article headers to Post content for those posts/nids which were just now updated.
		if ( ! empty( $article_headers_rows_for_update ) ) {
			$this->update_posts_with_drupal_node_header_contents( $article_headers_rows_for_update );
		}

		// Additionally update some more authors and dates coming from a different kind of formatted node HTML output.
		$this->update_authors_from_field_data_field_byline(
			$nodes,
			$field_data_field_article_header_all_rows,
			$field_data_field_byline_all_rows,
			$field_full_name_value_all_rows
		);

		// Run the command which substitutes Drupal shortcodes.
		$this->cmd_update_drupal_convert_shortcodes();

		// Update Posts' slugs.
		$this->update_post_slugs_with_drupal_url_alias( $url_alias_all_rows );

		// Let the $wpdb->update() sink in.
		wp_cache_flush();


		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		if ( file_exists( self::LOG_FILE_ERR_UID_NOT_FOUND ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for `nid`s with no matched `uid`s -- default WP user was used as author for these.', self::LOG_FILE_ERR_UID_NOT_FOUND  ) );
		}
		if ( file_exists( self::LOG_FILE_ERR_POST_CONTENT_EMPTY ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for `nid`s with empty content -- these nodes were not imported.', self::LOG_FILE_ERR_POST_CONTENT_EMPTY  ) );
		}
		if ( file_exists( self::LOG_FILE_ERR ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for mixed errors.', self::LOG_FILE_ERR  ) );
		}

		WP_CLI::warning( 'Be sure to run the Image Downloader Plugin, because this process might have brought in some new images, too.' );
	}

	/**
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_update_drupal_convert_shortcodes( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );

		@unlink( self::LOG_GALLERY_ERR );
		@unlink( self::LOG_GALLERY_IMAGE_NO_URI );
		@unlink( self::LOG_GALLERY_IMAGE_DOWNLOAD_FAILED );
		@unlink( self::LOG_DISPLAY_ERR );
		@unlink( self::LOG_DISPLAY_IMAGE_NO_URI );
		@unlink( self::LOG_DISPLAY_IMAGE_DOWNLOAD_FAILED );


		// Convert [gallery].
		// --- get all the posts with [gallery] shortcodes (possible multiple).
		$posts = get_posts( [
			'posts_per_page' => -1,
			's' => '[gallery:',
		] );
		foreach ( $posts as $k => $post ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) downloading galleries for post ID %d ...', $k + 1, count( $posts ), $post->ID ) );

			// --- match the [gallery] shortcode.
			$matches_gallery = $this->square_brackets_element_manipulator->match_shortcode_designations( 'gallery', $post->post_content );
			if ( empty( $matches_gallery[0] ) ) {
				continue;
			}

			$post_content_updated = $post->post_content;

			foreach ( $matches_gallery[0] as $gallery_shortcode ) {

				// --- match the drupal gallery id.
				$pos_colon = strpos( $gallery_shortcode, ':' );
				$pos_closing_bracket = strpos( $gallery_shortcode, ']' );
				$gallery_id = substr( $gallery_shortcode, $pos_colon + 1, $pos_closing_bracket - $pos_colon - 1 );
				if ( false === is_numeric( $gallery_id ) ) {
					$msg = sprintf( '❗ Could not get [gallery] id in Post ID %d.', (int) $post->ID );
					WP_CLI::warning( $msg );
					$this->log( self::LOG_GALLERY_ERR, $msg );
					continue;
				}
				$gallery_id = (int) $gallery_id;

				// --- get drupal images from gallery, their URLs and captions.
				$drupal_gallery_images = $this->get_drupal_gallery_images( $gallery_id );
				$attachment_ids = [];
				foreach ( $drupal_gallery_images as $i => $gallery_image ) {
					// --- download the images and import them as attachments, set their captions, get the new attachment ids.
					if ( ! $gallery_image['uri_public'] ) {
						$msg = sprintf( '❗ No Drupal image URI for img fid %d in Post ID %d.', (int) $gallery_image['fid'], (int) $post->ID );
						WP_CLI::warning( $msg );
						$this->log( self::LOG_GALLERY_IMAGE_NO_URI, $msg );
						continue;
					}

					WP_CLI::line( sprintf( '- (%d/%d) downloading %s ...', $i + 1, count( $drupal_gallery_images ),$gallery_image['uri_public'] ) );
					$att_id = $this->attachments_logic->import_external_file( $gallery_image['uri_public'], null, $gallery_image['caption'], null, $gallery_image['caption'], $post->ID );
					if ( is_wp_error( $att_id ) ) {
						$msg = sprintf( '❗ Could not download image URL %s, fid %d in Post ID %d.', $gallery_image['uri_public'], (int) $gallery_image['fid'], (int) $post->ID );
						WP_CLI::warning( $msg );
						$this->log( self::LOG_GALLERY_IMAGE_DOWNLOAD_FAILED, $msg );
						continue;
					}

					$attachment_ids[] = $att_id;
				}

				if ( ! empty( $attachment_ids ) ) {
					// --- generate the gutenberg gallery block.
					$gallery_block_html = $this->render_gallery_block( $attachment_ids );

					// --- substitute the drupal shortcode with the generated gallery.
					$post_content_updated = str_replace( $gallery_shortcode, "\n\n" . $gallery_block_html . "\n\n", $post_content_updated );
				}
			}

			if ( $post->post_content != $post_content_updated ) {
				$res = $wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
				if ( false === $res ) {
					WP_CLI::warning( sprintf( 'Could not post ID %d.', (int) $post->ID ) );
					continue;
				}
			}

			WP_CLI::line( sprintf( '✓ post ID %d', $post->ID ) );
		}

		// Let the $wpdb->update() sink in.
		wp_cache_flush();


		// Convert [display].
		// --- get all the posts with [display] shortcodes.
		$posts = get_posts( [
			'posts_per_page' => -1,
			's' => '[display:',
		] );
		foreach ( $posts as $k => $post ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) downloading [display] images for post ID %d ...', $k + 1, count( $posts ), $post->ID ) );

			// --- match the [display] shortcodes and the drupal image files' ids.
			$matches_display = $this->square_brackets_element_manipulator->match_shortcode_designations( 'display', $post->post_content );
			if ( empty( $matches_display[0] ) ) {
				continue;
			}

			$post_content_updated = $post->post_content;

			foreach ( $matches_display[0] as $i => $display_shortcode ) {
				$pos_colon = strpos( $display_shortcode, ':' );
				$pos_closing_bracket = strpos( $display_shortcode, ']' );
				$image_file_id = substr( $display_shortcode, $pos_colon + 1, $pos_closing_bracket - $pos_colon - 1 );
				if ( false === is_numeric( $image_file_id ) ) {
					$msg = sprintf( '❗ Could not get [display] id in Post ID %d.', (int) $post->ID );
					WP_CLI::warning( $msg );
					$this->log( self::LOG_DISPLAY_ERR, $msg );
					continue;
				}
				$image_file_id = (int) $image_file_id;

				// --- get drupal image, its URL and caption.
				$drupal_image = $this->get_drupal_image( $image_file_id );

				// --- download the image and import as attachment, set the caption, get the new attachment id.
				if ( ! $drupal_image['uri_public'] ) {
					$msg = sprintf( '❗ No Drupal image URI for img fid %d in Post ID %d.', (int) $drupal_image['fid'], (int) $post->ID );
					WP_CLI::warning( $msg );
					$this->log( self::LOG_DISPLAY_IMAGE_NO_URI, $msg );
					continue;
				}

				WP_CLI::line( sprintf( '- (%d/%d) image %s ...', $i + 1, count( $matches_display[0] ), $drupal_image['uri_public'] ) );
				$att_id = $this->attachments_logic->import_external_file( $drupal_image['uri_public'], null, $drupal_image['caption'], null, $drupal_image['caption'], $post->ID );
				if ( is_wp_error( $att_id ) ) {
					$msg = sprintf( '❗ Could not download image URL %s, fid %d in Post ID %d.', $drupal_image['uri_public'], (int) $drupal_image['fid'], (int) $post->ID );
					WP_CLI::warning( $msg );
					$this->log( self::LOG_DISPLAY_IMAGE_DOWNLOAD_FAILED, $msg );
					continue;
				}

				// --- generate the new image html.
				$image_html = $this->render_image_html_element( $att_id );

				// --- substitute the drupal shortcode with the image.
				$post_content_updated = str_replace( $display_shortcode, $image_html, $post_content_updated );
			}

			if ( $post->post_content != $post_content_updated ) {
				$res = $wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
				if ( false === $res ) {
					WP_CLI::warning( sprintf( 'Could not post ID %d.', (int) $post->ID ) );
					continue;
				}
			}

			WP_CLI::line( sprintf( '✓ post ID %d', $post->ID ) );
		}

		wp_cache_flush();


		// Convert [video].
		// --- get all the posts with [video] shortcodes.
		$posts = get_posts( [
			'posts_per_page' => -1,
			's' => '[video:',
		] );
		foreach ( $posts as $k => $post ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) replacing [video] shortcodes post ID %d ...', $k + 1, count( $posts ), $post->ID ) );

			// --- match the [video] shortcode, match the video URL.
			$matches_display = $this->square_brackets_element_manipulator->match_shortcode_designations( 'video', $post->post_content );
			if ( empty( $matches_display[0] ) ) {
				continue;
			}

			$post_content_updated = $post->post_content;

			foreach ( $matches_display[0] as $i => $video_shortcode ) {

				// Clean up the shortcodes -- these can contain single or multiple or incomplete (no opening or no closing tag),
				//      e.g. `<span>`, and/or `</span>`, and/or `<span ...>`, and/or `</font>`, ...
				// And the shortcode itself is case insensitive.
				$stuff_to_clean = true;
				while ( $stuff_to_clean ) {
					// Check if angle brackets exist.
					$pos_angle_open = strpos( $video_shortcode, '<' );
					$pos_angle_close = false !== $pos_angle_open
						? strpos( $video_shortcode, '>', $pos_angle_open )
						: false;

					if ( ( false === $pos_angle_open ) || ( false === $pos_angle_close ) ) {
						$stuff_to_clean = false;
					} else {
						$video_shortcode = substr( $video_shortcode, 0, $pos_angle_open ) . substr( $video_shortcode, $pos_angle_close + 1 );
					}
				}

				// More clean up; removing spaces and blanks.
				$more_things_to_remove = [
					'&nbsp;',
					' ',
				];
				foreach ( $more_things_to_remove as $thing ) {
					$video_shortcode = str_replace( $thing, '', $video_shortcode );
				}

				// Get the URL.
				$pos_colon = strpos( $video_shortcode, ':' );
				$pos_closing_bracket = strpos( $video_shortcode, ']' );
				$video_url = substr( $video_shortcode, $pos_colon + 1, $pos_closing_bracket - $pos_colon - 1 );
				$video_url = trim( $video_url );

				// Clean up the URL.
				// -- doesn't include protocol.
				if ( 0 === strpos( $video_url, 'www.' ) ) {
					$video_url = 'https://' . $video_url;
				}
				// -- url starts with "YouTubehttps://"
				$video_shortcode = str_replace( "YouTubehttps://", 'https://', $video_shortcode );

				// --- generate the new video block.
				$video_block = $this->render_video_block( $video_url );

				// --- substitute the drupal shortcode with the video block.
				$post_content_updated = str_replace( $video_shortcode, $video_block, $post_content_updated );
			}

			if ( $post->post_content != $post_content_updated ) {
				$res = $wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
				if ( false === $res ) {
					WP_CLI::warning( sprintf( 'Could not post ID %d.', (int) $post->ID ) );
					continue;
				}
			}

			WP_CLI::line( sprintf( '✓ post ID %d', $post->ID ) );
		}

		wp_cache_flush();


		// Convert [magnify].
		// --- get all the posts with [magnify] shortcodes.
		$posts = get_posts( [
			'posts_per_page' => -1,
			's' => '[magnify:',
		] );
		foreach ( $posts as $k => $post ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) replacing [magnify] shortcodes in post ID %d ...', $k + 1, count( $posts ), $post->ID ) );

			// --- match the [magnify] shortcode.
			$matches_display = $this->square_brackets_element_manipulator->match_shortcode_designations( 'magnify', $post->post_content );
			if ( empty( $matches_display[0] ) ) {
				continue;
			}

			$post_content_updated = $post->post_content;

			foreach ( $matches_display[0] as $i => $magnify_shortcode ) {

				// --- if `[magnify:donate,...`, remove.
				if ( 0 === strpos( $magnify_shortcode, '[magnify:donate,' ) ) {
					$post_content_updated = str_replace( $magnify_shortcode, '', $post_content_updated );
					continue;
				}

				// Magnify is an iframe linking to http://magnify.michigandaily.us/ followed by the first part.
				// The second part is the height element on the iframe.
				// Examples     [magnify:fec_2019_rankings,730]
				//              [magnify:highway_to_hail,330]
				//              [magnify:kennedy_front_page,500]

				// --- get the shortcode params
				$pos_colon = strpos( $magnify_shortcode, ':' );
				$pos_closing_bracket = strpos( $magnify_shortcode, ']' );
				$magnify_params = substr( $magnify_shortcode, $pos_colon + 1, $pos_closing_bracket - $pos_colon - 1 );
				$magnify_params = trim( $magnify_params );
				$magnify_params_arr = explode( ',', $magnify_params );
				$url_path = $magnify_params_arr[0];
				$iframe_height = $magnify_params_arr[1];

				// --- generate the new iframe html.
				$magnify_html = $this->render_magnify_iframe( $url_path, $iframe_height );

				// --- substitute the drupal shortcode with the iframe.
				$post_content_updated = str_replace( $magnify_shortcode, $magnify_html, $post_content_updated );
			}

			if ( $post->post_content != $post_content_updated ) {
				$res = $wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
				if ( false === $res ) {
					WP_CLI::warning( sprintf( 'Could not post ID %d.', (int) $post->ID ) );
					continue;
				}
			}

			WP_CLI::line( sprintf( '✓ post ID %d', $post->ID ) );
		}


		// Convert [twitter].
		// --- get all the posts with [twitter] shortcodes.
		$posts = get_posts( [
			'posts_per_page' => -1,
			's' => '[twitter:',
		] );
		foreach ( $posts as $k => $post ) {
			WP_CLI::line( sprintf( '👉 (%d/%d) replacing [twitter] shortcodes in post ID %d ...', $k + 1, count( $posts ), $post->ID ) );

			// --- match the [magnify] shortcode.
			$matches_display = $this->square_brackets_element_manipulator->match_shortcode_designations( 'twitter', $post->post_content );
			if ( empty( $matches_display[0] ) ) {
				WP_CLI::line( sprintf( 'x skipping, no [twitter] shortcodes found ...', $k + 1, count( $posts ), $post->ID ) );
				continue;
			}

			$post_content_updated = $post->post_content;

			foreach ( $matches_display[0] as $i => $twitter_shortcode ) {

				// --- get the URL
				$pos_colon = strpos( $twitter_shortcode, ':' );
				$pos_closing_bracket = strpos( $twitter_shortcode, ']' );
				$tweet_url = substr( $twitter_shortcode, $pos_colon + 1, $pos_closing_bracket - $pos_colon - 1 );

				// --- generate the twitter block.
				$twitter_html = "\n" . $this->render_twitter_block( $tweet_url ) . "\n";

				// --- substitute the drupal shortcode with the iframe.
				$post_content_updated = str_replace( $twitter_shortcode, $twitter_html, $post_content_updated );
			}

			if ( $post->post_content != $post_content_updated ) {
				$res = $wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
				if ( false === $res ) {
					WP_CLI::warning( sprintf( 'Could not post ID %d.', (int) $post->ID ) );
					continue;
				}
			}

			WP_CLI::line( sprintf( '✓ post ID %d', $post->ID ) );
		}

		wp_cache_flush();


		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );

		if ( file_exists( self::LOG_GALLERY_ERR ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for gallery shortcode mixed errors.', self::LOG_GALLERY_ERR ) );
		}
		if ( file_exists( self::LOG_GALLERY_IMAGE_NO_URI ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for gallery shortcode images without valid URIs.', self::LOG_GALLERY_IMAGE_NO_URI ) );
		}
		if ( file_exists( self::LOG_GALLERY_IMAGE_DOWNLOAD_FAILED ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for gallery shortcode download fails.', self::LOG_GALLERY_IMAGE_DOWNLOAD_FAILED ) );
		}
		if ( file_exists( self::LOG_DISPLAY_ERR ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` display shortcode for mixed errors.', self::LOG_DISPLAY_ERR ) );
		}
		if ( file_exists( self::LOG_DISPLAY_IMAGE_NO_URI ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` display shortcode images without valid URIs..', self::LOG_DISPLAY_IMAGE_NO_URI ) );
		}
		if ( file_exists( self::LOG_DISPLAY_IMAGE_DOWNLOAD_FAILED ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` display shortcode download fails.', self::LOG_DISPLAY_IMAGE_DOWNLOAD_FAILED ) );
		}
	}

	/**
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_update_drupal_node_header_content( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );

		WP_CLI::line( '' );
		WP_CLI::confirm( 'This command should not be run more than once, and it already gets run at the end of the `michigan-daily-import-drupal-content` command. Are you sure you want to proceed?' );

		// Prefetch Drupal data for speed.
		WP_CLI::line( 'Fetching data from Drupal tables...' );
		// Get all the nodes.
		$nodes = $this->get_drupal_all_nodes_by_type( [ 'michigan_daily_article', 'article' ] );

		// Get the node headers.
		$field_data_field_article_header_all_rows = $this->get_article_header_rows( $nodes );

		$this->update_posts_with_drupal_node_header_contents( $field_data_field_article_header_all_rows );

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Gets `field_data_field_article_header` rows for given nodes. Does some cleanup on the header values.
	 *
	 * @param array $nodes
	 *
	 * @return array|null Array with 'entity_id' and 'field_article_header_value' keys and values.
	 */
	private function get_article_header_rows( $nodes ) {
		global $wpdb;

		$nids = [];
		foreach ( $nodes as $node ) {
			$nids[] = $node['nid'];
		}
		$nid_placeholders = implode( ',', array_fill( 0, count( $nids ), '%d' ) );
		$field_data_field_article_header_all_rows = $wpdb->get_results(
			$wpdb->prepare(
				"select entity_id, field_article_header_value
				from field_data_field_article_header
				where entity_id IN ( $nid_placeholders ) ;",
				$nids
			),
			ARRAY_A
		);

		// Clean up node headers.
		foreach ( $field_data_field_article_header_all_rows as $k => $field_data_field_article_header_row ) {
			$header = $field_data_field_article_header_row['field_article_header_value'];

			// Remove some known blanks.
			$blanks = [
				'<p>&nbsp;</p><div>&nbsp;</div>',
				'<p>&nbsp;</p><p>&nbsp;</p>',
				'<br />',
				'<p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;">&nbsp;</p><p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;">&nbsp;</p><div>&nbsp;</div>',
				'<p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;">&nbsp;</p><div>&nbsp;</div>',
				'<p><br /><br /><br />&nbsp;</p>',
				'<div class="lf-quote-float-left"><div class="quote">&nbsp;</div></div>',
				'<p dir="ltr" style="line-height:1.656;margin-top:0pt;margin-bottom:0pt;">&nbsp;</p><div>&nbsp;</div>',
				'<p><font face="Times New Roman"><span style="font-size: 14.666666984558105px; white-space: pre-wrap;">&nbsp;</span></font></p>',
				'<blockquote><p>&nbsp;</p></blockquote>',
				'<p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;">&nbsp;</p><p><br />&nbsp;</p>',
				'<p>dfasdfasdf</p>',
				'<p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;">&nbsp;</p><div>&nbsp;</div>',
				'<p><strong>&nbsp;</strong></p>',
				'',
			];
			foreach ( $blanks as $blank ) {
				if ( $blank == $header ) {
					unset( $field_data_field_article_header_all_rows[ $k ] );
					continue;
				}
			}

			// Remove inline CSS.
			$css_opening = '<style type="text/css">';
			$css_closing = '</style>';
			$pos_css_opening = strpos( $header, $css_opening );
			$pos_css_closing = strpos( $header, $css_closing );
			if ( ( false !== $pos_css_opening ) && ( false !== $pos_css_closing ) ) {
				$header = substr( $header, 0, $pos_css_opening ) . substr( $header, $pos_css_closing + strlen( $css_closing ) );
			}

			// One final blank check.
			$header = trim( $header );
			if ( empty( $header ) ) {
				unset( $field_data_field_article_header_all_rows[ $k ] );
				continue;
			}
		}

		return $field_data_field_article_header_all_rows;
	}

	/**
	 * @param int $gallery_id Drupal gallery ID.
	 *
	 * @return array {
	 *      Drupal gallery images in gallery.
	 *
	 *      @type array {
	 *          @type string fid        Drupal image file id.
	 *          @type string uri        URI found in the table, e.g. "public://galleryies/5.30.20_BLMrally.0655-2.jpg".
	 *          @type string uri_public Actual publicly available URL with the actual hostname.
	 *          @type string caption    Image caption.
	 *      }
	 * }
	 */
	private function get_drupal_gallery_images( $gallery_id ) {
		$images = [];

		global $wpdb;

		// Joins 3 Drupal tables:
		//      field_data_field_images -- with list of image files in a gallery
		//      field_data_field_photo_credit -- with image captions
		//      file_managed -- with file names and their URLs
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"select fim.field_images_fid fid, credit.field_photo_credit_value credit, fileman.uri uri
				from field_data_field_images fim
				left join field_data_field_photo_credit credit on credit.entity_id = fim.field_images_fid
				left join file_managed fileman on fileman.fid = fim.field_images_fid
				-- gallery id:
				where fim.entity_id = %d
				and fim.bundle = 'photo_gallery';",
				$gallery_id
			),
			ARRAY_A
		);

		// Custom return array.
		foreach ( $rows as $row ) {
			$uri_public = ! empty( $row['uri'] )
				? str_replace( 'public://', 'https://www.michigandaily.com/sites/default/files/styles/gallery/public/', $row['uri'] )
				: null;
			$images[] = [
				'fid' => $row['fid'],
				'uri' => $row['uri'],
				'uri_public' => $uri_public,
				'caption' => $row['caption'],
			];
		}

		return $images;
	}

	/**
	 * @param int $fid Drupal image file ID.
	 *
	 * @return array {
	 *      Drupal image data.
	 *
	 *      @type array {
	 *          @type string fid        Drupal image file id.
	 *          @type string uri        URI found in the table, e.g. "public://galleryies/5.30.20_BLMrally.0655-2.jpg".
	 *          @type string uri_public Actual publicly available URL with the actual hostname.
	 *          @type string caption    Image caption.
	 *      }
	 * }
	 */
	private function get_drupal_image( $fid ) {
		$images = [];

		global $wpdb;

		// Joins 3 Drupal tables:
		//      field_data_field_images -- with list of image files in a gallery
		//      field_data_field_photo_credit -- with image captions
		//      file_managed -- with file names and their URLs
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"select fileman.fid fid, credit.field_photo_credit_value credit, fileman.uri uri
				from file_managed fileman
				left join field_data_field_photo_credit credit on credit.entity_id = fileman.fid
				where fileman.fid = %d
				and fileman.filemime like 'image/%';",
				$fid
			),
			ARRAY_A
		);

		// Custom return array.
		foreach ( $rows as $row ) {
			$uri_public = ! empty( $row['uri'] )
				? str_replace( 'public://', 'https://www.michigandaily.com/sites/default/files/styles/large/public/', $row['uri'] )
				: null;
			$images[] = [
				'fid' => $row['fid'],
				'uri' => $row['uri'],
				'uri_public' => $uri_public,
				'caption' => $row['caption'],
			];
		}

		return $images[0];
	}

	/**
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_update_drupal_post_slugs( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );

		// Get Drupal data.
		$url_alias_all_rows = $wpdb->get_results( "select source, alias from url_alias;", ARRAY_A );

		$this->update_post_slugs_with_drupal_url_alias( $url_alias_all_rows );

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Updates existing posts' URL slugs to old Drupal URL aliases.
	 *
	 * @param $url_alias_all_rows
	 */
	private function update_post_slugs_with_drupal_url_alias( $url_alias_all_rows ) {
		if ( ! class_exists( \Red_Item::class ) ) {
			WP_CLI::error( sprintf( 'Class %s not found.', \Red_Item::class ) );
		}

		global $wpdb;
		$posts = $this->posts_logic->get_all_posts( [ 'post' ], [ 'publish' ] );
// TODO DEV REMOVE:
// $posts = [
// 	// get_post( 32 ),
// 	// get_post( 35 ),
// 	// get_post( 39 ),
// ];

		foreach ( $posts as $k => $post ) {
			WP_CLI::line( sprintf( '- (%d/%d) post ID %d ...', $k + 1, count( $posts ), $post->ID ) );

			$original_nid = get_post_meta( $post->ID, self::META_OLD_NODE_ID, true );
			if ( ! $original_nid ) {
				WP_CLI::warning( 'x skipping, original nid not found' );
				continue;
			}

			// Skip if already done.
			$slug_updated             = get_post_meta( $post->ID, self::META_SLUG_UPDATED_TO_DRUPAL_ALIAS, true );
			$redirection_rule_created = get_post_meta( $post->ID, self::META_SLUG_REDIRECTION_RULE_FROM_DRUPAL_ALIAS, true );
			if ( ! empty( $slug_updated ) || ! empty( $redirection_rule_created ) ) {
				WP_CLI::warning( 'x skipping, URL already updated' );
				continue;
			}

			// Get Drupal URL alias.
			$alias_found = false;
			foreach ( $url_alias_all_rows as $url_alias_row ) {
				if ( 'node/' . $original_nid == $url_alias_row[ 'source' ] ) {
					$alias_found = true;
					break;
				}
			}
			if ( true !== $alias_found ) {
				WP_CLI::warning( 'x skipping, Drupal URL alias not found' );
				$this->log( 'url_updates.log', sprintf( "skipped ; %d ; %s ; %s", $post->ID, $original_nid, 'Drupal URL alias not found' ) );
				continue;
			}

			// Get post slug.
			$url_alias_exploded = explode( '/', $url_alias_row[ 'alias' ] );
			if ( count( $url_alias_exploded ) < 1 ) {
				WP_CLI::warning( sptintf( 'x skipping, alias `%s` does not contain multiple segments', $url_alias_row[ 'alias' ] ) );
				$this->log( 'url_updates.log', sprintf( "skipped ; %d ; %s ; %s", $post->ID, $original_nid, sprintf( 'alias `%s` does not contain multiple segments', $url_alias_row[ 'alias' ] ) ) );
				continue;
			}

			// Update post name/slug.
			$previous_post_slug = $post->post_name;
			$new_post_slug = $url_alias_exploded[ count( $url_alias_exploded ) - 1 ];

			// Test if the new slug is valid (contains valid characters) and can be used as the new slug.
			$is_new_post_slug_valid = filter_var( 'https://test-host.com/' . $new_post_slug, FILTER_VALIDATE_URL );

			// Try and update slug to the new one.
			$new_slug_updated = (bool) $is_new_post_slug_valid
				? $wpdb->update( $wpdb->prefix . 'posts', [ 'post_name' => $new_post_slug ], [ 'ID' => $post->ID ] )
				: false;

			if ( $new_slug_updated ) {
				// Save old post name.
				update_post_meta( $post->ID, self::META_SLUG_UPDATED_TO_DRUPAL_ALIAS, $previous_post_slug );

				$this->log( 'url_updates.log', sprintf( "slug_update ; %d ; %s ; %s ; %s", $post->ID, $original_nid, $previous_post_slug, $new_post_slug ) );
				WP_CLI::line( sprintf( '+ slug updated from `%s` to `%s`', $previous_post_slug, $new_post_slug ) );
			} else {
				$rule_title = $post->ID . ' - ' . $post->post_title;
				$redirect_from = '/' . $url_alias_row[ 'alias' ];

				// Remove /section URL prefix from Drupal's original alias, because we already have a redirection rule for that.
				$section_prefix = '/section';
				if ( 0 === strpos( $redirect_from, $section_prefix . '/' ) ) {
					$redirect_from = substr( $redirect_from, strlen( $section_prefix ) );
				}

				$redirect_to = get_permalink( $post->ID );
				$this->redirection_logic->create_redirection_rule(
					$rule_title,
					$redirect_from,
					$redirect_to
				);

				// Save rule title.
				update_post_meta( $post->ID, self::META_SLUG_REDIRECTION_RULE_FROM_DRUPAL_ALIAS, $rule_title );

				$this->log( 'url_updates.log', sprintf( "redirection_rule ; %d ; %s ; %s ; %s", $post->ID, $original_nid, $redirect_from, $redirect_to ) );
				WP_CLI::line( sprintf( '+ redirection created from `%s` to `%s`', $redirect_from, $redirect_to ) );
			}
		}

		wp_cache_flush();
	}

	public function cmd_update_authors_from_field_data_field_byline( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );

		// Get Drupal data.
		$nodes = $this->get_drupal_all_nodes_by_type( [ 'michigan_daily_article', 'article' ] );
		$field_data_field_article_header_all_rows = $this->get_article_header_rows( $nodes );
		$field_data_field_byline_all_rows = $wpdb->get_results(
			"select entity_id, field_byline_value from field_data_field_byline where entity_type = 'node';",
			ARRAY_A
		);
		$field_data_field_full_name_all_rows = $wpdb->get_results(
			"select entity_id, field_full_name_value from field_data_field_full_name;",
			ARRAY_A
		);

		$this->update_authors_from_field_data_field_byline(
			$nodes,
			$field_data_field_article_header_all_rows,
			$field_data_field_byline_all_rows,
			$field_data_field_full_name_all_rows
		);

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		// if ( file_exists( self::LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND ) ) {
		// 	WP_CLI::warning( sprintf( 'Check `%s` for `nid`s with no matched `uid`s -- default WP user was used as author for these.', self::LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND  ) );
		// }
	}

	/**
	 * Authors are very tricky on this site in general, since they're kept in different sources, and output differently.
	 * This handles authors for nodes such as:
	 *      https://www.michigandaily.com/section/sports/sources-baseball-gymnastics-practices-partially-shut-down-covid-19-cases
	 *      https://www.michigandaily.com/section/multimedia/thats-hot-rise-influencer
	 *      https://www.michigandaily.com/section/arts/and-coming-teen-drama-you-haven%E2%80%99t-heard-about-yet
	 *      ...
	 *
	 * Loops through all the posts, and if author and date were not already scraped from the matching node headers, looks for
	 * the author data in `field_data_field_byline`.
	 *
	 * @param $field_data_field_article_header_all_rows
	 * @param $field_data_field_byline_all_rows
	 * @param $field_data_field_full_name_all_rows
	 */
	private function update_authors_from_field_data_field_byline(
		$nodes,
		$field_data_field_article_header_all_rows,
		$field_data_field_byline_all_rows,
		$field_data_field_full_name_all_rows
	) {
		global $wpdb;

		$posts = $this->posts_logic->get_all_posts( [ 'post' ], [ 'publish' ] );

// TODO -- remove temp DEV:
// $posts = [
// // 	get_post( 459 ), // nid 252962
// // 	get_post( 2266 ), // invalid byline full name with value "."
// ];
// $posts = get_posts([ 'posts_per_page' => -1, 'post__in' => [ ] ]);

		foreach ( $posts as $k => $post ) {
			$original_nid = get_post_meta( $post->ID, self::META_OLD_NODE_ID, true );
			if ( ! $original_nid ) {
				WP_CLI::warning( sprintf( '- (%d/%d) skipping ID %d -- no original nid', $k + 1, count( $posts ), $post->ID ) );
				continue;
			}

			$data_update = [];

			// Check if author & date were already scraped from the node HTML, which is the fist source we should try.
			$should_update_author = false;
			$field_data_field_article_header_row = $this->search_array_by_key_and_value( $field_data_field_article_header_all_rows, [ 'entity_id' => $original_nid ] );
			$date_scraped = $author_scraped = null;
			if ( ! $field_data_field_article_header_row ) {
				// Some nodes don't have headers, so authors weren't set by scraping the names for those.
				$should_update_author = true;
			} else {
				// Check if author and date info was already scraped.
				$drupal_field_data_body_row  = $this->get_drupal_field_data_body( $original_nid );
				$post_info_scraped = $this->get_post_p_info_contents( $drupal_field_data_body_row[ 'body_value' ] ?? null );
				if ( $post_info_scraped ) {
					$date_scraped   = $this->extract_date_from_p_info( $post_info_scraped );
					$author_scraped = $this->extract_author_from_p_info( $post_info_scraped );
					// There are several invalid bylines (e.g. nid 204836), this takes care of those.
					if ( strlen( $author_scraped ) < 4 ) {
						$author_scraped = null;
					}
				}
				// Not scraped yet, so let's update this one.
				if ( null === $date_scraped  || null === $author_scraped ) {
					$should_update_author = true;
				}
			}

			// Stop if already scraped.
			if ( true !== $should_update_author ) {
				WP_CLI::line( sprintf( '- (%d/%d) skipping ID %d -- author and date already scraped', $k + 1, count( $posts ), $post->ID ) );
				continue;
			}

			// Get the author's full name.
			$field_data_field_byline_row = $this->search_array_by_key_and_value( $field_data_field_byline_all_rows, [ 'entity_id' => $original_nid ] );
			if ( ! $field_data_field_byline_row ) {
				continue;
			}
			// The author's full name.
			$field_full_name_id = $field_data_field_byline_row[ 'field_byline_value' ];
			$field_data_field_full_name_row = $this->search_array_by_key_and_value( $field_data_field_full_name_all_rows, [ 'entity_id' => $field_full_name_id ] );
			$full_name = $field_data_field_full_name_row[ 'field_full_name_value' ] ?? null;

			// Get the date.
			$node_row = $this->search_array_by_key_and_value( $nodes, [ 'nid' => $original_nid ] );
			$date_wp_formatted = gmdate( 'Y-m-d H:i:s', $node_row[ 'created' ] );
			if ( $date_wp_formatted ) {
				$data_update[ 'post_date' ] = $date_wp_formatted;
				$data_update[ 'post_date_gmt' ] = $date_wp_formatted;
			}

			WP_CLI::line( sprintf( '- (%d/%d) updating ID %d ...', $k + 1, count( $posts ), $post->ID ) );

			// Update the post date.
			if ( ! empty( $data_update ) ) {
				$wpdb->update( $wpdb->prefix . 'posts',
					$data_update,
					[ 'ID' => $post->ID ]
				);
				WP_CLI::success( '+ updated date' );
			}

			// Update the post author.
			if ( $full_name ) {
				$guest_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $full_name ] );
				if ( ! is_wp_error( $guest_author_id ) ) {
					$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post->ID );
					WP_CLI::success( '+ updated author' );
				} else {
					WP_CLI::warning( sprintf( '- error updating author with full name `%s`', $full_name ) );
				}
			}
		}

		wp_cache_flush();
	}

	/**
	 * Gets the `field_data_field_article_header` HTML and prepends it to the post content. Also cleans up the HTML a bit.
	 *
	 * @param array $field_data_field_article_header_all_rows
	 */
	public function update_posts_with_drupal_node_header_contents( $field_data_field_article_header_all_rows ) {
		global $wpdb;

		// Flush the log files.
		@unlink( self::LOG_HEADER_UPDATE_POST_WITH_NID_NOT_FOUND );

		// Clean up node headers.
		foreach ( $field_data_field_article_header_all_rows as $k => $field_data_field_article_header_row ) {
			$nid    = $field_data_field_article_header_row['entity_id'];
			$header = $field_data_field_article_header_row['field_article_header_value'];

			// Update the post content, and prepend the header to it.
			$post_ids = $this->posts_logic->get_posts_with_meta_key_and_value( self::META_OLD_NODE_ID, $nid );
			$post_id  = isset( $post_ids[0] ) ? $post_ids[0] : null;
			$post     = get_post( $post_id );
			if ( ! $post ) {
				WP_CLI::warning( sprintf( 'Post with nid %s not found.', (int) $nid ) );
				$this->log( self::LOG_HEADER_UPDATE_POST_WITH_NID_NOT_FOUND, $nid );
				continue;
			}

			$meta_header_already_updated = get_post_meta( $post->ID, self::META_ARTICLE_HEADER_UPDATED, true );
			if ( $meta_header_already_updated ) {
				WP_CLI::warning( sprintf( '- (%d/%d) skipping -- headers already updated for post ID %d (nid %d)', $k + 1, count( $field_data_field_article_header_all_rows ), (int) $post->ID, (int) $nid ) );
				continue;
			}

			WP_CLI::line( sprintf( '- (%d/%d) updating headers for post ID %d (nid %d) ...', $k + 1, count( $field_data_field_article_header_all_rows ), (int) $post->ID, (int) $nid ) );

			$post_content_with_header = $header . "<br><!-- _end_header_prepend -->" . $post->post_content;
			$res = $wpdb->update(
				$wpdb->prefix . 'posts',
				[ 'post_content' => $post_content_with_header ],
				[ 'ID' => $post->ID ]
			);
			if ( false === $res ) {
				WP_CLI::warning( sprintf( 'Could not update existing post ID %d , node nid %d', (int) $post->ID, (int) $nid ) );
				continue;
			}

			update_post_meta( $post_id, self::META_ARTICLE_HEADER_UPDATED, $nid );
		}

		// Let the $wpdb->update() sink in.
		wp_cache_flush();

		if ( file_exists( self::LOG_HEADER_UPDATE_POST_WITH_NID_NOT_FOUND ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for `nid`s with no matched `uid`s -- default WP user was used as author for these.', self::LOG_HEADER_UPDATE_POST_WITH_NID_NOT_FOUND  ) );
		}
	}

	/**
	 * Callable for the `newspack-content-migrator michigan-daily-set-wp-user-authors-from-guest-authors`.
	 */
	public function cmd_set_wp_user_authors_from_guest_authors( $args, $assoc_args ) {
		$time_start = microtime( true );

		@unlink( self::LOG_USER_CREATE_ERR );
		@unlink( self::LOG_USER_CREATED );
		@unlink( self::LOG_USERS_LINKED_GA_TO_WP_USER );
		@unlink( self::LOG_USERS_POST_AUTHOR_UPDATED );

		// - get all the existing posts with an author of 0
		$posts = get_posts( [
			'author' =>  0,
			'post_type' => 'post',
			'post_status' => [ 'publish', 'draft' ],
			'posts_per_page' => -1,
		] );
// // TODO -- remove temp DEV test cases:
// $posts = [
// 	// get_post( 24584 ),
// 	// get_post( 24578 ),
// 	// get_post( 24576 ),
// 	// get_post( 24490 ),
// 	// get_post( 24420 ),
//
// 	// get_post( 163259 ),
// 	// get_post( 175198 ),
//
// 	// GA 114738 MEN'S BASKETBALL BEAT
// 	get_post( 162327 ),
// 	get_post( 162175 ),
//
// 	// GA 160132 "Sierra Élise Hansen"
// 	get_post( 173634 ),
// 	get_post( 173071 ),
//
// 	// GA 160573 "Jim Wilson "
// 	get_post( 1756 ),
// 	get_post( 1790 ),
// ];
		foreach ( $posts as $i => $post ) {

			WP_CLI::line( sprintf( '- (%d/%d) updating Author for post ID %d...', $i + 1, count( $posts ), $post->ID ) );

			// - get the Post's Guest Author
			$guest_authors = $this->coauthorsplus_logic->get_guest_authors_for_post( $post->ID );
			if ( ! $guest_authors ) {
				WP_CLI::line( ' x skipping, no GAs.' );
				continue;
			}

			$wp_user_author_assigned = false;
			foreach ( $guest_authors as $guest_author ) {
				// - get or create a WP User with that Guest Author's name
				$guest_author_display_name = trim( $guest_author->display_name );
				$wp_user = $this->get_wp_user_by_full_name( $guest_author_display_name );

				// Create a User wo/ an email.
				if ( ! $wp_user ) {
					$username = trim( sanitize_user( $guest_author_display_name ) );
					$wp_user_id = wp_create_user( $username, wp_generate_password() );

					if ( is_wp_error( $wp_user_id ) && (
						isset( $wp_user_id->errors[ 'existing_user_login' ] ) ||
						isset( $wp_user_id->errors[ 'user_login_too_long' ] )
					) ) {
						// Some existing Users have different names, but keep the same username,
						// e.g. "Justin OBeirne" vs "Justin O’Beirne".
						// Repeat user creation with a unique login.
						$username = $this->get_unique_username( $username );
						$wp_user_id = wp_create_user( $username, wp_generate_password() );
					} else if ( is_wp_error( $wp_user_id ) ) {
						$msg = sprintf( 'Error creating User from GA ID %d and display_name %s for post ID %d: %s', $guest_author->ID, $guest_author_display_name, $post->ID, $wp_user_id->get_error_message() );
						WP_CLI::warning( $msg );
						$this->log( self::LOG_USER_CREATE_ERR, $msg );
						continue;
					}

					$wp_user = get_user_by( 'id', $wp_user_id );
					WP_CLI::line( sprintf( ' + created User ID %d from GA ID %d - %s ...', $wp_user_id, $guest_author->ID, $guest_author_display_name ) );
					$this->log( self::LOG_USER_CREATED, sprintf( 'post ID %d, GA ID %d, GA %s, WP User ID %d', $post->ID, $guest_author->ID, $guest_author_display_name, $wp_user->ID ) );
				}

				// - map the new WP User to the Guest Author
				$this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author->ID, $wp_user );
				$this->log( self::LOG_USERS_LINKED_GA_TO_WP_USER, sprintf( 'GA ID %d, GA %s, WP User ID %d', $guest_author->ID, $guest_author_display_name, $wp_user->ID ) );

				// - assign the WP User as the Post's author
				if ( true === $wp_user_author_assigned ) {
					WP_CLI::line( ' x skipping, author already assigned.' );
					continue;
				}
				wp_update_post( [
					'ID' => $post->ID,
					'post_author' => $wp_user->ID,
				] );
				update_post_meta( $post->ID, self::META_AUTHOR_WP_USER_SET_FROM_GUEST_AUTHOR, $guest_author->ID );
				WP_CLI::line( sprintf( ' + Assigned User %d to post.', $wp_user->ID ) );
				$this->log( self::LOG_USERS_POST_AUTHOR_UPDATED, sprintf( 'Post ID %d, Author WP User ID %d', $post->ID, $wp_user->ID ) );

				$wp_user_author_assigned = true;
			}
		}

		WP_CLI::success( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		if ( file_exists( self::LOG_USER_CREATE_ERR ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for errors.', self::LOG_USER_CREATE_ERR ) );
		}
		if ( file_exists( self::LOG_USER_CREATED ) ) {
			WP_CLI::warning( sprintf( 'Log available %s.', self::LOG_USER_CREATED ) );
		}
		if ( file_exists( self::LOG_USERS_LINKED_GA_TO_WP_USER ) ) {
			WP_CLI::warning( sprintf( 'Log available %s.', self::LOG_USERS_LINKED_GA_TO_WP_USER ) );
		}
		if ( file_exists( self::LOG_USERS_POST_AUTHOR_UPDATED ) ) {
			WP_CLI::warning( sprintf( 'Log available %s.', self::LOG_USERS_POST_AUTHOR_UPDATED ) );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for the `newspack-content-migrator michigan-daily-unset-wp-user-authors-assigned-from-guest-authors`.
	 */
	public function cmd_unset_wp_user_authors_assigned_from_guest_authors( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );

		$posts = get_posts( [
			'meta_key'       => self::META_AUTHOR_WP_USER_SET_FROM_GUEST_AUTHOR,
			'post_status'    => 'any',
			'posts_per_page' => -1
		] );
		WP_CLI::line( sprintf( 'Setting post_author=0 for %d posts...', count( $posts ) ) );
		foreach ( $posts as $post ) {
			$wpdb->update( $wpdb->posts, [ 'post_author' => 0 ], [ 'ID' => $post->ID ] );
		}

		WP_CLI::success( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );

		wp_cache_flush();
	}

	/**
	 * Callable for the `newspack-content-migrator michigan-daily-unset-wp-user-authors-where-guest-authors-exist`.
	 */
	public function cmd_unset_wp_user_authors_where_guest_authors_exist( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );

		WP_CLI::line( 'Fetching all Posts with `post_author` != 0 ...' );
		$results = $wpdb->get_results(
			"select ID, post_author
			from {$wpdb->posts}
			where post_author <> 0
			and post_type = 'post'
			and post_status in ( 'publish', 'draft', 'trash' );",
			ARRAY_A
		);
		foreach ( $results as $i => $result ) {
			WP_CLI::line( sprintf( '(%d/%d) ID %d ...', $i + 1, count( $results ), $result[ 'ID' ] ) );
			$post = get_post( $result[ 'ID' ] );

			// - get the Post's Guest Author
			$guest_authors = $this->coauthorsplus_logic->get_guest_authors_for_post( $post->ID );
			if ( ! $guest_authors ) {
				WP_CLI::line( ' x skipping, no GAs.' );
				continue;
			}

			$wpdb->update( $wpdb->posts, [ 'post_author' => 0 ], [ 'ID' => $post->ID ] );
			WP_CLI::line( ' +++ `post_author` set to 0' );
		}

		WP_CLI::success( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );

		wp_cache_flush();
	}

		/**
	 * Creates an unique username by appending uniqid(), and minds the max length 60 chars.
	 *
	 * @param string $username Username.
	 *
	 * @return string Unique username.
	 */
	private function get_unique_username( $username ) {
		// Max username length is 60, so abbreviate it if it's too long.
		$strlen_max_username = 60;
		$strlen_uniqid       = 13;
		$strlen_username     = strlen( $username );
		if ( $strlen_username > ( $strlen_max_username - $strlen_uniqid ) ) {
			$username = substr( $username, 0, $strlen_max_username - $strlen_uniqid );
		}
		$username = substr( $username . '_' . uniqid(), 0, 60 );

		return $username;
	}

	/**
	 * Gets an existing WP user by their display_name or user_login.
	 *
	 * @param string $full_name User full name.
	 *
	 * @return mixed|null User object or null.
	 */
	public function get_wp_user_by_full_name( $full_name ) {
		// Check both strictly sanitized ana not strictly sanitized login.
		$display_name_sanitized_strict     = sanitize_user( $full_name, true );
		$display_name_sanitized_not_strict = sanitize_user( $full_name, false );

		$users = get_users();
		foreach ( $users as $user ) {
			if (
				( $display_name_sanitized_strict == $user->data->display_name )
				|| ( $display_name_sanitized_strict == $user->data->user_login )
				|| ( $display_name_sanitized_not_strict == $user->data->display_name )
				|| ( $display_name_sanitized_not_strict == $user->data->user_login )
			) {
				return $user;
			}
		}

		return null;
	}

	/**
	 * Callable for the `newspack-content-migrator michigan-daily-update-posts-guest-authors`.
	 */
	public function cmd_update_post_guest_authors( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );

		// Flush the log files.
		@unlink( self::LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND );

		// Prefetch Drupal data for speed.
		WP_CLI::line( 'Fetching data from Drupal tables...' );
		$field_full_name_value_all_rows = $wpdb->get_results(
			"select fn.entity_id, fn.field_full_name_value
			from node n
			join users u on u.uid = n.uid
			join field_data_field_full_name fn on fn.entity_id = n.uid
			where n.type in ( 'article', 'michigan_daily_article' )
			group by fn.entity_id;",
			ARRAY_A
		);
		$field_data_field_first_name_and_last_name_all_rows = $wpdb->get_results(
			"select
				n.uid,
				concat( fn.field_first_name_value, ' ', ln.field_last_name_value ) as full_name
			from node n
			join users u on u.uid = n.uid
			join field_data_field_last_name ln on ln.entity_id = n.uid
			join field_data_field_first_name fn on fn.entity_id = n.uid
			where n.type in ( 'article', 'michigan_daily_article' )
			group by n.uid;",
			ARRAY_A
		);
		$field_data_field_twitter_all_rows = $wpdb->get_results(
			"select t.entity_id, t.field_twitter_value
			from field_data_field_twitter t
			join node n on n.uid = t.entity_id
			where t.entity_type = 'user'
			group by n.uid;",
			ARRAY_A
		);
		$post_ids_already_imported = $this->get_existing_nid_id_map();
		$nodes                     = $this->get_drupal_all_nodes_by_type( [ 'michigan_daily_article', 'article' ] );

		$i = 0;
		foreach ( $post_ids_already_imported as $nid => $post_id ) {

			WP_CLI::line( sprintf( '- (%d/%d) updating Guest Authors for nid %d ID %d ...', $i + 1, count( $post_ids_already_imported ), $nid, $post_id ) );
			$node = $this->search_array_by_key_and_value( $nodes, [ 'nid' => $nid ] );

			// Assign Guest Author, to post.
			$full_name = $this->get_drupal_user_full_name(
				$node['uid'],
				$field_full_name_value_all_rows,
				$field_data_field_first_name_and_last_name_all_rows,
				$field_data_field_twitter_all_rows
			);
			if ( $full_name ) {
				$guest_author_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $full_name ] );
				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post_id );
			} else {
				$this->log( self::LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND, sprintf( 'uid %d, nid %d, ID %d', $node['uid'], $node['nid'], $post_id ) );
			}

			$i++;
		}

		wp_cache_flush();

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		if ( file_exists( self::LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND ) ) {
			WP_CLI::warning( sprintf( 'Check `%s` for `nid`s with no matched `uid`s -- default WP user was used as author for these.', self::LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND  ) );
		}

	}

	/**
	 * Callable for `newspack-content-migrator michigan-daily-delete-imported-custom-post-type`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_delete_imported_custom_post_type($args, $assoc_args ) {
		$time_start = microtime( true );

		$post_type = 'michigan_daily_artic';

		WP_CLI::line( 'Fetching posts...' );
		$post_ids = $this->posts_logic->get_all_posts_ids( $post_type );
		if ( empty( $post_ids ) ) {
			WP_CLI::success( sprintf( 'No post types `%s` found.', $post_type ) );
			exit;
		}

		foreach ( $post_ids as $i => $post_id ) {
			WP_CLI::line( sprintf( '- (%d/%d) deleting ID %d...', $i + 1, count( $post_ids ), $post_id ) );
			wp_delete_post( $post_id );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator michigan-daily-set-featured-image-in-posts-which-do-not-have-one`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_update_featured_image_for_posts_from_meta_which_do_not_have_one($args, $assoc_args ) {
		global $wpdb;

		$time_start = microtime( true );

		// Get all posts without a featured image.
		$results = $wpdb->get_results(
			"select ID from {$wpdb->prefix}posts p
			left join {$wpdb->prefix}postmeta pm on pm.post_id = p.ID and pm.meta_key = '_thumbnail_id'
			where pm.post_id is null
			and p.post_type = 'post';",
			ARRAY_A
		);
		foreach ( $results as $i => $result ) {
			$post_id = (int) $result[ 'ID' ];

			// Get all post attachments.
			$attachments = get_posts( [
				'post_type'   => 'attachment',
				'post_parent' => $post_id,
				'fields'      => 'ids',
			] );
			if ( empty( $attachments ) ) {
				WP_CLI::line( sprintf( '- (%d/%d) ID %d - no attachments found', $i + 1, count( $results ), $post_id ) );
				continue;
			}

			// Set the featured image to the first uploaded attachment.
			$featured_image_id = min( $attachments );
			update_post_meta( $post_id, '_thumbnail_id', $featured_image_id );
			WP_CLI::line( sprintf( '- (%d/%d) ID %d - updated featured image %d', $i + 1, count( $results ), $post_id, $featured_image_id ) );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Gets all `node` rows.
	 *
	 * @param array $types
	 *
	 * @return array|null
	 */
	private function get_drupal_all_nodes_by_type( array $types ) {
		if ( empty( $types ) ) {
			return [];
		}

		global $wpdb;

		$string_placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$query               = $wpdb->prepare( "SELECT * FROM node WHERE type IN ( $string_placeholders ) order by nid desc", $types );

		return $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Gets an array map of already imported `nid`s.
	 *
	 * @return array `nid`s as keys, `ID` as values.
	 */
	private function get_existing_nid_id_map() {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"select meta_value, post_id from {$wpdb->prefix}postmeta where meta_key = %s",
				self::META_OLD_NODE_ID
			),
			ARRAY_A
		);

		if ( empty( $results ) ) {
			return [];
		}
		$nids_ids = [];
		foreach ( $results as $result ) {
			$nids_ids[ $result[ 'meta_value' ] ] = $result[ 'post_id' ];
		}

		return $nids_ids;
	}

	/**
	 * Array search function. Searches all $haystack's subarray elements, and matches whether they contain all the key=>value
	 * pairs specified in the $needle_keys_and_values search param.
	 *
	 * @param array $haystack               Array being searched.
	 * @param array $needle_keys_and_values Search paraeters, i.e. key-value pairs.
	 *
	 * @return array|null Matched $haystack[ $i ] element.
	 */
	private function search_array_by_key_and_value( array $haystack, array $needle_keys_and_values ) {
		foreach ( $haystack as $haystack_element ) {
			$matched_criteria = 0;
			foreach ( $needle_keys_and_values as $needle_key => $needle_value ) {
				if ( isset( $haystack_element[ $needle_key ] ) && $needle_value == $haystack_element[ $needle_key ] ) {
					$matched_criteria++;
				}
			}

			if ( $matched_criteria == count( $needle_keys_and_values ) ) {
				return $haystack_element;
			}
		}

		return null;
	}

	/**
	 * Searches for Drupal node's user's full name in all the known places.
	 *
	 * @param int   $uid
	 * @param array $field_full_name_value_all_rows                     DB results from the `field_full_name_value` table.
	 * @param array $field_data_field_first_name_and_last_name_all_rows DB results from the `field_data_field_first_name` and the
	 *                                                                  `field_data_field_last_name` tables.
	 * @param array $field_data_field_twitter_all_rows                  DB results from the `field_data_field_twitter` table.
	 *
	 * @return string|null
	 */
	private function get_drupal_user_full_name( $uid, $field_full_name_value_all_rows, $field_data_field_first_name_and_last_name_all_rows, $field_data_field_twitter_all_rows ){
		$full_name = null;

		// Get user name, option 1.) 219 uids are matched in `field_data_field_full_name`.
		$full_name_option1 = $this->search_array_by_key_and_value( $field_full_name_value_all_rows, [ 'entity_id' => $uid ] );
		$full_name         = $full_name_option1[ 'field_full_name_value' ] ?? null;

		// Get user name, option 2.) 7 more users not matched above are matched to field_data_field_last_name and field_data_field_first_name
		if ( ! $full_name ) {
			$full_name_option2 = $this->search_array_by_key_and_value( $field_data_field_first_name_and_last_name_all_rows, [ 'uid' => $uid ] );
			$full_name = $full_name_option2[ 'full_name' ] ?? null;
		}

		// Get user name, option 3.) 5 found in field_data_field_twitter, with a twitter user designation field_twitter_value
		if ( ! $full_name ) {
			$full_name_option3 = $this->search_array_by_key_and_value( $field_data_field_twitter_all_rows, [ 'entity_id' => $uid ] );
			$full_name = $full_name_option3[ 'field_twitter_value' ] ?? null;
			// One Twitter designation begins with the full URL, so remove the prefix.
			$full_name = ltrim( $full_name, 'https://twitter.com/' );
			// Let's keep it `null` consitently where $full_name not found.
			$full_name = empty( $full_name ) ? null : $full_name;
		}

		return $full_name;
	}

	/**
	 * Fetches an existing or creates a new Category by name.
	 *
	 * @param string $category_name
	 *
	 * @return object|\WP_Error|null
	 */
	private function get_or_create_category( $category_name ) {
		$categories = get_categories([
			'name'                     => $category_name,
			'hide_empty'               => FALSE,
			'hierarchical'             => 1,
			'taxonomy'                 => 'category',
		]);
		if ( ! empty( $categories ) ) {
			$category = $categories[0];
		} else {
			$category_id = wp_insert_category( [
				'cat_name' => $category_name,
			] );
			$category = get_category( $category_id );
		}

		return $category;
	}

	/**
	 * Fetches Drupal Taxonomy names for nid.
	 *
	 * @param int   $node_id
	 * @param array $taxonomy_term_data_all_rows All the `taxonomy_term_data` rows.
	 *
	 * @return array Array the associated Taxonomy names.
	 */
	private function get_drupal_taxonomy_names_by_node_id( $node_id, $taxonomy_term_data_all_rows ) {
		$taxonomy_names = [];

		global $wpdb;

		// Get `tid`s associated to this `nid`.
		$node_tids = [];
		$drupal_taxonomy_entity_index_rows = $wpdb->get_results(
			$wpdb->prepare( "select * from taxonomy_entity_index where entity_id = %d;", $node_id ),
			ARRAY_A
		);
		foreach ( $drupal_taxonomy_entity_index_rows as $taxonomy_entity_index_row ) {
			$node_tids[] = (int) $taxonomy_entity_index_row[ 'tid' ];
		}
		if ( empty( $node_tids ) ) {
			return $taxonomy_names;
		}

		// Get taxonomy names.
		$taxonomy_names = [];
		foreach ( $node_tids as $node_tid ) {
			$taxonomy_names[] = $this->filter_drupal_taxonomy_name_by_tid( $taxonomy_term_data_all_rows, $node_tid );
		}

		// Get unique ones, and prettify array keys if needed.
		$taxonomy_names = array_values( array_unique( $taxonomy_names ) );

		return $taxonomy_names;
	}

	/**
	 * This super simple function filters through the imput `taxonomy_term_data` array, and returns the one which matches a given $tid.
	 *
	 * @param array $taxonomy_term_data_all_rows
	 * @param int   $tid
	 *
	 * @return string|null
	 */
	private function filter_drupal_taxonomy_name_by_tid( $taxonomy_term_data_all_rows, $tid ) {
		foreach ( $taxonomy_term_data_all_rows as $taxonomy_term_data_all_row ) {
			if ( $tid == $taxonomy_term_data_all_row[ 'tid' ] ) {
				return $taxonomy_term_data_all_row[ 'name' ];
			}
		}

		return null;
	}

	/**
	 * @param int $entity_id
	 *
	 * @return array|null
	 */
	private function get_drupal_field_data_body( $entity_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'select * from field_data_body where entity_id = %d', $entity_id ),
			ARRAY_A
		);
	}

	/**
	 * Scrapes the 'p.info' content from the HTML $body_value.
	 *
	 * @param string $body_value
	 *
	 * @return string|null Contents of p.info, where available, or null.
	 */
	private function get_post_p_info_contents( $body_value ) {
		if ( ! $body_value ) {
			return null;
		}

		$this->dom_crawler->clear();
		$this->dom_crawler->add( $body_value );
		$sub_sitemaps = $this->dom_crawler->filter( 'p.info' );

		// If $body_value does not contain the div.main, use the whole HTML.
		if ( 0 == $sub_sitemaps->count() ) {
			return null;
		}

		$post_content = $sub_sitemaps->html();

		return $post_content;

	}

	/**
	 * Extracts date from the p.info element in content.
	 *
	 * @param string $line Inner HTML of the p.info element.
	 *
	 * @return string|null Date string in 'Y-m-j' format.
	 */
	private function extract_date_from_p_info( $line ) {

		// Match this: "Published May 28, 2015".
		$pattern = '|Published\s((\w+)\s\d{1,2},\s\d{4})|';
		$matches = [];
		$matched = preg_match( $pattern, $line, $matches );
		if ( 1 !== $matched ) {
			return null;
		}

		$date_text = $matches[1];
		$datetime = \DateTime::createFromFormat ( 'F j, Y', $date_text );
		$wp_date_format = $datetime->format( 'Y-m-j 00:00:00' );

		return $wp_date_format;
	}

	/**
	 * Extracts autor name from the p.info element in content.
	 *
	 * @param string $line Inner HTML of the p.info element.
	 *
	 * @return string|null Author name or null.
	 */
	private function extract_author_from_p_info( $line ) {
		// Search for author position starting with 'BY ' or 'By '.
		$pos_by1 = strpos( $line, 'BY ' );
		$pos_by2 = strpos( $line, 'By ' );
		$pos_by = ( false !== $pos_by1 )
			? $pos_by1
			: (
				( false !== $pos_by2 )
					? $pos_by2
					: false
			);

		if ( false === $pos_by ) {
			return null;
		}

		// Author ends either with a `<br>` or a line end.
		$author_scraped = '';
		$pos_break = strpos( $line, '<br>', $pos_by + 3 );
		if ( false !== $pos_break ) {
			$author_scraped = substr( $line, $pos_by + 3, $pos_break - $pos_by - 3 );
		} else {
			$author_scraped = substr( $line, $pos_by + 3 );
		}
		// Strip all tags.
		$author_scraped = wp_kses( $author_scraped, [] );
		$author_scraped = trim( $author_scraped);

		return $author_scraped;
	}

	/**
	 * Scrapes the 'div.main' content from the HTML $body_value.
	 *
	 * @param string $body_value
	 *
	 * @return string Only contents of div.main, if available, or the entry HTML string.
	 */
	private function get_post_content_from_node_body_raw( $body_value ) {
		if ( ! $body_value ) {
			return null;
		}

		$this->dom_crawler->clear();
		$this->dom_crawler->add( $body_value );
		$sub_sitemaps = $this->dom_crawler->filter( 'div.main' );

		// If $body_value does not contain the div.main, use the whole HTML.
		if ( 0 == $sub_sitemaps->count() ) {
			return $body_value;
		}

		$post_content = $sub_sitemaps->html();

		return $post_content;
	}

	/**
	 * Fully renders a Core Gallery Block from attachment IDs.
	 * Presently hard-coded attributes use ampCarousel and ampLightbox.
	 *
	 * @param array $ids Attachment IDs.
	 *
	 * @return string Gallery block HTML.
	 */
	public function render_gallery_block( $ids ) {
		// Compose the HTML with all the <li><figure><img/></figure></li> image pieces.
		$images_li_html = '';
		foreach ( $ids as $id ) {
			$img_url = wp_get_attachment_url( $id );
			$img_caption = wp_get_attachment_caption( $id );
			$img_alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$img_data_link = $img_url;
			$img_element = sprintf(
				'<img src="%s" alt="%s" data-id="%d" data-full-url="%s" data-link="%s" class="%s"/>',
				$img_url,
				$img_alt,
				$id,
				$img_url,
				$img_data_link,
				'wp-image-' . $id
			);
			$figcaption_element = ! empty( $img_caption )
				? sprintf( '<figcaption class="blocks-gallery-item__caption">%s</figcaption>', esc_attr( $img_caption ) )
				: '';
			$images_li_html .= '<li class="blocks-gallery-item">'
				. '<figure>'
				. $img_element
				. $figcaption_element
				. '</figure>'
				. '</li>';
		}

		// The inner HTML of the gallery block.
		$inner_html = '<figure class="wp-block-gallery columns-3 is-cropped">'
			. '<ul class="blocks-gallery-grid">'
			. $images_li_html
			. '</ul>'
			. '</figure>';
		$block_gallery = [
			'blockName' => 'core/gallery',
			'attrs' => [
				'ids' => $ids,
				'linkTo' => 'none',
				'ampCarousel' => true,
				'ampLightbox' => true,
			],
			'innerBlocks' => [],
			'innerHTML' => $inner_html,
			'innerContent' => [ $inner_html ],
		];

		// Fully rendered gallery block.
		$block_gallery_rendered = '<!-- wp:gallery {"ids":[' . esc_attr( implode( ',', $ids ) ) . '],"linkTo":"none","ampCarousel":true,"ampLightbox":true} -->'
            . "\n"
			. render_block( $block_gallery )
            . "\n"
			. '<!-- /wp:gallery -->';

		return $block_gallery_rendered;
	}

	/**
	 * @param int $id Attachment ID
	 *
	 * @return string Image HTML element.
	 */
	public function render_image_html_element( $id ) {
		$img_url       = wp_get_attachment_url( $id );
		$img_caption   = wp_get_attachment_caption( $id );
		$img_alt       = get_post_meta( $id, '_wp_attachment_image_alt', true );
		$img_data_link = $img_url;
		$img_element   = sprintf(
			'<img src="%s" alt="%s" data-id="%d" data-full-url="%s" data-link="%s" class="%s"/>',
			$img_url,
			$img_alt,
			$id,
			$img_url,
			$img_data_link,
			'wp-image-' . $id
		);

		if ( $img_caption ) {
			$img_element = '<figure>'
				. $img_element
				. '<figcaption>' . esc_html( $img_caption ) . '</figcaption>'
			    .'</figure>';
		}

		return $img_element;
	}

	/**
	 * @param string $url Video URL
	 *
	 * @return string Video block.
	 */
	public function render_video_block( $url ) {
		$video_block = '';

		if (
			( false !== strpos( $url, 'youtube.com' ) )
		     || ( false !== strpos( $url, 'youtu.be' ) )
		) {
			$video_block = sprintf(
				'<!-- wp:embed {"url":"%s","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-4-3 wp-has-aspect-ratio"} -->'
				. "\n" . '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-4-3 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">'
				. "\n" . '%s'
				. "\n" . '</div></figure>'
				. "\n" . '<!-- /wp:embed -->',
				$url,
				$url
			);
		} else if ( strpos( $url, 'vimeo.com' ) ) {
			$video_block = sprintf(
				'<!-- wp:embed {"url":"%s","type":"video","providerNameSlug":"vimeo","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->'
				. "\n" . '<figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">'
				. "\n" . '%s'
				. "\n" . '</div></figure>'
				. "\n" . '<!-- /wp:embed -->',
				$url,
				$url
			);
		} else {
			$video_block = sprintf(
				'<!-- wp:video -->'
				. "\n" . '<figure class="wp-block-video"><video controls src="%s"></video></figure>'
				. "\n" . '<!-- /wp:video -->',
				$url
			);
		}

		return $video_block;
	}

	/**
	 * @param string $url_path      Custom path to the TMD's Magnify shortcode's public source.
	 * @param int    $iframe_height iframe height attr.
	 *
	 * @return string HTML.
	 */
	public function render_magnify_iframe( $url_path, $iframe_height ) {
		$html = '<div class="magnify"><iframe src="https://magnify.michigandaily.us/' . $url_path . '" frameborder="0" width="100%" height="' . $iframe_height . 'px"></iframe></div>"';

		return $html;
	}

	/**
	 * @param string $tweet_url URL.
	 *
	 * @return string HTML.
	 */
	public function render_twitter_block( $tweet_url ) {
		$html = <<<HTML
<!-- wp:embed {"url":"$tweet_url","type":"rich","providerNameSlug":"twitter","responsive":true,"className":""} -->
<figure class="wp-block-embed is-type-rich is-provider-twitter wp-block-embed-twitter"><div class="wp-block-embed__wrapper">
$tweet_url
</div></figure>
<!-- /wp:embed -->
HTML;

		return $html;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
