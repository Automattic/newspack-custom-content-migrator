<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Options;
use NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;

/**
 * Custom migration scripts for Michigan Daily.
 */
class MichiganDailyMigrator implements InterfaceMigrator {

	const META_OLD_NODE_ID = '_fgd2wp_old_node_id';

	/**
	 * Error log file names -- grouped by error types to make reviews easier.
	 */
	const LOG_FILE_ERR_META_ORIG_ID_NOT_FOUND    = 'michigandaily__metaorigid_not_found.log';
	const LOG_FILE_ERR_FIELD_DATA_BODY_ROW_EMPTY = 'michigandaily__field_data_body_row_empty.log';
	const LOG_FILE_ERR_POST_CONTENT_EMPTY        = 'michigandaily__postcontentempty.log';

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
	 * Constructor.
	 */
	private function __construct() {
		$this->dom = new Dom();
		$this->dom->setOptions( ( new Options() )->setCleanupInput( false ) );

		$this->posts_logic = new PostsLogic();
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
			'newspack-content-migrator michigan-daily-fix-drupal-content-after-conversion',
			[ $this, 'cmd_fix_drupal_content_after_conversion' ],
			[
				'shortdesc' => 'Fills in the gaps left by the Drupal importer, by getting and patching data right from the original Drupal DB tables.',
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator michigan-daily-fix-drupal-content-after-conversion`.
	 */
	public function cmd_fix_drupal_content_after_conversion( $args, $assoc_args ) {
		global $wpdb;

		$time_start = microtime( true );

		// Clean up helper log files before this run.
		@unlink( self::LOG_FILE_ERR_META_ORIG_ID_NOT_FOUND );
		@unlink( self::LOG_FILE_ERR_FIELD_DATA_BODY_ROW_EMPTY );
		@unlink( self::LOG_FILE_ERR_POST_CONTENT_EMPTY );

		WP_CLI::line( 'Fetching posts...' );

		$posts = $this->posts_logic->get_all_posts();

// TEMP DEV debug, do a single post.
// $posts = [ get_post( 99444 ) ];

// TEMP DEV debug, do specific IDs post.
// $posts = [];
// $ids = [ 97216, 2428, 98421, 3755, 2172, 3075, 3076, 96961, 4181, 98967, 75165, 2847, 2849, 81106, 81151, 98822, 98636, 99455, 80001, 4387, 98883, 81148, 2632, 80589, 81651, 80475, 106143, 106191, 106190, 106189, 106160, 106161, 106157, 106155, 106159, 106156, 106158, 106154, 106204, 106174, 106172, 106202, 106170, 106153, 106200, 106201, 106195, 106194, 106196, 106197, 106187, 106152, 106149, 106150, 106151, 106188, 106186, 106198, 106199, 106193, 106185, 106183, 106184, 106148, 106144, 106145, 106146, 106192, 106147, 88628, 88629, 106120, 88616, 88545, 88559, 88541, 88538, 88542, 88543, 88544, 88499, 88482, 88481, 15459, 78833, 95393, 105839, 88347, 78800, 95353, 88272, 15169, 95315, 15008, 95257, 78740, 78711, 78692, 78682, 95140, 105338, 14504, 95014, 105159, 105158, 94907, 87570, 94891, 87543, 94863, 104951, 104922, 13857, 87309, 13739, 13738, 13721, 94710, 87212, 87152, 87129, 94645, 87068, 78362, 78361, 13351, 13310, 13309, 87102, 13210, 13197, 86892, 13092, 94489, 12919, 12915, 86714, 104091, 104056, 12854, 12853, 12852, 12730, 12729, 12642, 94318, 78184, 86481, 86482, 86508, 94208, 12348, 86342, 86738, 86293, 103681, 12003, 12002, 86255, 11892, 103610, 103600, 86227, 77950, 11801, 93981, 93980, 93979, 11772, 77924, 86167, 86218, 11710, 93897, 86087, 103473, 93877, 11552, 77859, 77857, 11553, 11536, 86033, 86035, 86034, 103403, 85990, 11423, 11389, 11393, 93777, 78581, 93762, 93752, 85996, 85869, 11177, 77751, 85792, 93585, 93525, 93526, 102914, 85481, 85480, 102784, 77534, 102764, 85381, 77471, 102666, 102656, 102641, 102638, 102631, 102607, 77431, 102570, 102558, 102557, 102556, 94470, 102549, 102550, 102513, 102510, 93299, 102490, 102479, 85116, 85115, 85054, 93236, 77302, 102368, 77292, 93197, 93191, 102341, 84939, 84940, 77263, 93169, 84924, 77244, 9395, 77230, 84800, 102227, 84703, 102175, 102117, 102084, 93019, 102074, 102014, 92977, 8934, 77062, 84405, 84356, 84336, 8425, 84135, 84100, 8216, 76944, 7886, 76849, 83863, 76819, 7698, 7688, 101320, 76789, 101291, 76776, 101258, 101243, 101225, 101235, 101236, 101232, 101239, 101238, 101233, 76762, 83233, 76529, 76521, 100674, 92140, 99684, 91545, 99021, 91510, 81881, 99199, 81692, 97223, 82241, 96773, 96695, 1305, 75603, 79528, 96179, 96174, 75427, 96108, 96101, 79237, 95914, 95912, 79117, 75173 ];
// foreach ( $ids as $id ) {
// 	$posts[] = get_post( $id );
// }

// TEMP DEV debug, do a specific number of posts.
// $posts = get_posts( [
// 	'posts_per_page' => -1,
// 	'post_type'      => 'post',
// 	'post_status'    => [ 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash' ],
// 	'posts_per_page' => 500,
// ] );

		foreach ( $posts as $i => $post ) {
			WP_CLI::line( sprintf( '- (%d/%d) ID %d ...', $i + 1, count( $posts ), $post->ID ) );

			$post_data = [];

			// Get original Drupal node ID and node row from the DB.
			$node_id = get_post_meta( $post->ID, self::META_OLD_NODE_ID, true );
			if ( ! $node_id || ! is_numeric( $node_id ) ) {
				$this->log( self::LOG_FILE_ERR_META_ORIG_ID_NOT_FOUND, $post->ID );
				continue;
			}
			$node_id = (int) $node_id;
			$drupal_node_row = $this->get_drupal_node_by_id( $node_id );

			// Basic stuff: status, date, title.
			$post_data[ 'post_status' ] = ( 1 == $drupal_node_row[ 'status' ] ) ? 'publish' : 'draft';
			$post_data[ 'post_date' ]   = gmdate( 'Y-m-d H:i:s', $drupal_node_row[ 'created' ] );
			$post_data[ 'post_title' ]  = $drupal_node_row[ 'title' ] ?? $post->post_title;

			// Get post content and excerpt.
			$drupal_field_data_body_row = $this->get_drupal_field_data_body( $node_id );
			if ( empty( $drupal_field_data_body_row ) && 'draft' === $post_data[ 'post_status' ] ) {
				// If it's a draft with no content, just trash it, actually.
				$post_data[ 'post_status' ] = 'trash';
				continue;
			}
			if ( empty( $drupal_field_data_body_row ) )  {
				// Update just the basic info, skip the rest.
				$wpdb->update( $wpdb->prefix . 'posts', $post_data[ 'post_status' ], [ 'ID' => $post->ID ] );
				$this->log( self::LOG_FILE_ERR_FIELD_DATA_BODY_ROW_EMPTY, sprintf( 'ID %d node_id %d.', $post->ID, $node_id ) );
				continue;
			}
			$post_data[ 'post_content' ] = $this->get_post_content_from_node_body_raw( $drupal_field_data_body_row[ 'body_value' ] );
			// If scraping the div.main from the body_value didn't work, and there's still some content in the `body_value`, use that.
			if ( ! $post_data[ 'post_content' ] && ! empty( $drupal_field_data_body_row[ 'body_value' ] ) ) {
				$post_data[ 'post_content' ] = $drupal_field_data_body_row[ 'body_value' ];
			}
			if ( ! $post_data[ 'post_content' ] ) {
				$this->log( self::LOG_FILE_ERR_POST_CONTENT_EMPTY, sprintf( 'ID %d node_id %d.', $post->ID, $node_id ) );
				$post_data[ 'post_content' ] = $post->post_content;
			}
			$post_data[ 'post_excerpt' ] = $drupal_field_data_body_row[ 'body_summary' ];
			if ( ! $post_data[ 'post_excerpt' ] ) {
				$post_data[ 'post_excerpt' ] = $post->post_excerpt;
			}

			// // TODO, load or create Guest User.
			// $drupal_user_id = $drupal_node_row[ 'uid' ];
			// $drupal_user_row = $this->get_drupal_user_row( $drupal_user_id );
			// $user_login = $drupal_user_row[ 'name' ];
			// $user_full_name;
			// $user_email;

			// TODO Tags

			// TODO Categories

			// Refresh imported post data.
			$wpdb->update(
				$wpdb->prefix . 'posts',
				$post_data,
				[ 'ID' => $post->ID ]
			);
		}

		// Let the $wpdb->update() sink in.
		wp_cache_flush();

		WP_CLI::line( sprintf( 'Done in %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );

		if ( file_exists( self::LOG_FILE_ERR_META_ORIG_ID_NOT_FOUND ) ||
		     file_exists( self::LOG_FILE_ERR_FIELD_DATA_BODY_ROW_EMPTY ) ||
		     file_exists( self::LOG_FILE_ERR_POST_CONTENT_EMPTY )
		) {
			WP_CLI::warning( sprintf( 'Some content exceptions occurred, please check the fresh `.log` files.' ) );
		}

		// Now download images from content again.

	}

	private function get_drupal_node_by_id( $nid ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM node WHERE nid = %d', $nid ),
			ARRAY_A
		);
	}
	private function get_drupal_user_row( $uid ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'select * from users where uid = %d', $uid ),
			ARRAY_A
		);
	}
	private function get_drupal_field_data_body( $entity_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare( 'select * from field_data_body where entity_id = %d', $entity_id ),
			ARRAY_A
		);
	}
	private function get_post_content_from_node_body_raw( $body_value ) {
		if ( ! $body_value ) {
			return null;
		}

		$post_content = '';

		$this->dom->loadStr( $body_value );
		$collection = $this->dom->find( 'div.main');
		if ( ! $collection->count() ) {
			return $post_content;
		}

		$post_content = $collection[0]->innerHtml;

		return $post_content;
	}
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
