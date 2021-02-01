<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Options;
use Symfony\Component\DomCrawler\Crawler;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;

/**
 * Custom migration scripts for Michigan Daily.
 */
class MichiganDailyMigrator implements InterfaceMigrator {

	const META_OLD_NODE_ID = '_fgd2wp_old_node_id';

	/**
	 * Error log file names -- grouped by error types to make reviews easier.
	 */
	const LOG_FILE_ERR_POST_CONTENT_EMPTY          = 'michigandaily__postcontentempty.log';
	const LOG_FILE_ERR_UID_NOT_FOUND               = 'michigandaily__uidnotfound.log';
	const LOG_FILE_ERR_CMD_UPDATEGAS_UID_NOT_FOUND = 'michigandaily__cmdupdategas_uidnotfound.log';
	const LOG_FILE_ERR                             = 'michigandaily__err.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var Dom
	 */
	private $dom;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->dom = new Dom();
		$this->dom->setOptions( ( new Options() )->setCleanupInput( false ) );

		$this->posts_logic         = new PostsLogic();
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
			'newspack-content-migrator michigan-daily-update-posts-guest-authors',
			[ $this, 'cmd_update_post_guest_authors' ],
			[
				'shortdesc' => 'Helper command, updates Guest Authors for existing posts.'
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
			'newspack-content-migrator michigan-daily-update-featured-image-for-posts-which-do-not-have-one',
			[ $this, 'cmd_update_featured_image_for_posts_which_do_not_have_one' ],
			[
				'shortdesc' => 'Sets featured image to Posts which do not yet have a featured image.',
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

		$post_ids_already_imported = [];
		if ( ! $reimport_all_posts ) {
			$post_ids_already_imported = $this->get_existing_nid_id_map();
		}

		// `type` 'article' is legacy (they imported it over from a previous system to Drupal, and will not have Taxonomy here
		// in Drupal), and `type` 'michigan_daily_article' is their regular Post node type.
		$nodes = $this->get_drupal_all_nodes_by_type( [ 'michigan_daily_article', 'article' ] );

// TODO, DEV remove
// $n=217246; // broken <a>
// $nodes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM node WHERE nid = 252886" ), ARRAY_A );
// $nodes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM node WHERE nid IN ( 231007, 234452 )" ), ARRAY_A );

		foreach ( $nodes as $i => $node ) {

			WP_CLI::line( sprintf( '- (%d/%d) importing nid %d ...', $i + 1, count( $nodes ), $node['nid'] ) );
			// If not reimporting existing posts, continue.
			if ( ( false === $reimport_all_posts ) && isset( $post_ids_already_imported[ $node['nid'] ] ) ) {
				WP_CLI::line( sprintf( '✓ ID %d already imported, skipping.', $post_id ) );
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
			$post_data[ 'post_date' ]   = gmdate( 'Y-m-d H:i:s', $node[ 'created' ] );
			$post_data[ 'post_title' ]  = $node[ 'title' ] ?? ( $post->post_title ?? null );

			// Get the Post content.
			$drupal_field_data_body_row  = $this->get_drupal_field_data_body( $node['nid'] );
			$post_data[ 'post_content' ] = $this->get_post_content_from_node_body_raw( $drupal_field_data_body_row[ 'body_value' ] ?? '' );
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
				$this->log( self::LOG_FILE_ERR_UID_NOT_FOUND, sprintf( 'uid %d, nid %d, ID %d', $node['uid'], $node['nid'], $post->ID ) );
			}
		}


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
	public function cmd_update_featured_image_for_posts_which_do_not_have_one($args, $assoc_args ) {
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
	 * Scrapes the 'div.main' content from the HTML $body_value.
	 *
	 * @param string $body_value
	 *
	 * @return string|null
	 */
	private function get_post_content_from_node_body_raw( $body_value ) {
		if ( ! $body_value ) {
			return null;
		}

		// // The PHPHtmlParser\Dom is producing some seemingly broken HTML (nid 217246), so switching to \Symfony\Component\DomCrawler\Crawler instead.
		// $this->dom->loadStr( $body_value );
		// $collection = $this->dom->find( 'div.main');
		// if ( ! $collection->count() ) {
		// 	return null;
		// }
		// $post_content = $collection[0]->innerHtml;

		$crawler = ( new Crawler( $body_value ) )->filter('div.main');
		if ( 0 == $crawler->count() ) {
			return null;
		}
		$post_content = $crawler->html();

		return $post_content;
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
