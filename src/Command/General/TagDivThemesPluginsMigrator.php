<?php
/**
 * Newspack Custom Content Migrator: TagDiv (company) Themes and Plugins Migrator.
 * 
 * Commands related to migrating Themes and Plugins developed by TagDiv company.
 * 
 * @link: https://tagdiv.com/
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use NewspackCustomContentMigrator\Utils\Logger;
use stdClass;
use WP_CLI;

/**
 * Custom migration scripts for TagDiv (company) Themes and Plugins.
 */
class TagDivThemesPluginsMigrator implements InterfaceCommand {

	const TD_POST_THEME_SETTINGS             = 'td_post_theme_settings';
	const TD_POST_THEME_SETTINGS_SOURCE      = 'td_source';
	const TD_POST_THEME_SETTINGS_PRIMARY_CAT = 'td_primary_cat';

	/**
	 * CoAuthorPlusLogic
	 * 
	 * @var CoAuthorPlusLogic 
	 */
	private $coauthorsplus_logic;

	/**
	 * Log (file path)
	 *
	 * @var string $log
	 */
	private $log;

	/**
	 * Logger
	 * 
	 * @var Logger
	 */
	private $logger;

	/**
	 * PostsLogic
	 * 
	 * @var PostsLogic
	 */
	private $posts_logic = null;

	/**
	 * Instance
	 * 
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->logger              = new Logger();
		$this->posts_logic         = new PostsLogic();
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
			'newspack-content-migrator migrate-tagdiv-authors-to-gas',
			[ $this, 'cmd_migrate_tagdiv_authors_to_gas' ],
			[
				'shortdesc' => 'Migrate TagDiv authors to GAs.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'overwrite-post-gas',
						'description' => 'Overwrite GAs if they already exist on the post.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator migrate-tagdiv-primary-categories',
			[ $this, 'cmd_migrate_tagdiv_primary_categories' ],
			[
				'shortdesc' => 'Migrate TagDiv Primary Categories to Yoast.',
			]
		);

	}

	/**
	 * Migrate TagDiv Authors to CAP GAs.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_tagdiv_authors_to_gas( $pos_args, $assoc_args ) {

		WP_CLI::line( 'This command does NOT break apart multi-author strings (ex: "Jill Doe, Mary Parker, and Jim Smith").' );
		WP_CLI::line( 'The full string will be used as the display name to create a single GA.' );
		
		WP_CLI::confirm( 'Continue?' );

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Doing migration.' );

		$overwrite_post_gas = ( isset( $assoc_args['overwrite-post-gas'] ) ) ? true : false;

		if ( $overwrite_post_gas ) {
			$this->logger->log( $this->log, 'With --overwrite-post-gas.' );
		}

		$args = array(

			'post_type'   => 'post',
			'post_status' => array( 'publish' ),
			'fields'      => 'ids',
			
			// Must have required postmeta value.
			'meta_query'  => array(
				array(
					'key'     => self::TD_POST_THEME_SETTINGS,
					'value'   => '"' . self::TD_POST_THEME_SETTINGS_SOURCE . '"',
					'compare' => 'LIKE',
				),
			),
			
			// Order by date desc for better logging in order.
			'orderby'     => 'date',
			'order'       => 'DESC',

		);

		$this->posts_logic->throttled_posts_loop( 
			$args,
			function ( $post_id ) use( $overwrite_post_gas ) {

				$this->logger->log( $this->log, 'Post id: ' . $post_id );

				$settings = get_post_meta( $post_id, self::TD_POST_THEME_SETTINGS, true );

				if ( empty( $settings[ self::TD_POST_THEME_SETTINGS_SOURCE ] ) ) {

					$this->logger->log( $this->log, 'Empty post meta source.', $this->logger::WARNING );
					return;

				}

				$source = $this->sanitize_source( $settings[ self::TD_POST_THEME_SETTINGS_SOURCE ] );

				if ( empty( $source ) ) {
					
					$this->logger->log( $this->log, 'Empty sanitized source.', $this->logger::WARNING );
					return;
				}

				// Get existing GA if exists.
				// As of 2024-03-19 the use of 'coauthorsplus_logic->create_guest_author()' to return existing match
				// may return an error. WP Error occures if existing database GA is "Jon A. Doe" but new GA is "Jon A Doe".
				// New GA will not match on display name, but will fail on create when existing sanitized slug is found.
				// Use a more direct approach here.
				$ga = $this->coauthorsplus_logic->get_guest_author_by_user_login( sanitize_title( urldecode( $source ) ) );
				
				// Create.
				if ( false === $ga ) {
					
					$created_ga_id = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $source ) );

					if ( is_wp_error( $created_ga_id ) || ! is_numeric( $created_ga_id ) || ! ( $created_ga_id > 0 ) ) {
						$this->logger->log( $this->log, 'GA create failed: ' . $source, $this->logger::ERROR, true );
					}

					$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $created_ga_id );

				}

				$this->logger->log( $this->log, 'GA ID: ' . $ga->ID );

				// Assign to post.

				$existing_post_gas = $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id );
				$this->logger->log( $this->log, 'Existing post GAs count:' . count( $existing_post_gas ) );

				// If no GAs exist on post, or "overwrite" is true, then set gas to post. 
				if ( 0 == count( $existing_post_gas ) || $overwrite_post_gas ) {
					$this->coauthorsplus_logic->assign_guest_authors_to_post( array( $ga->ID ), $post_id );
					$this->logger->log( $this->log, 'Assigned GAs to post.' );
				} else {
					$this->logger->log( $this->log, 'Post GAs unchanged.' );
				}
			},
			1,
			100
		);

		wp_cache_flush();

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Migrate TagDiv Primary Categories to Yoast.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_tagdiv_primary_categories( $pos_args, $assoc_args ) {

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Doing migration.' );

// option: 'Auto select primary category'

/*
report: Array
(
    [total] => 2033
    [first-y, yoast-y] => 42
    [first-y, yoast-n] => 32
    [first-y] => 770
    [theme-y, first-n, yoast-n] => 773
    [theme-y, first-y, yoast-n] => 8
    [theme-y, first-n] => 39
    [theme-y, first-y, yoast-y] => 283
    [theme-y, first-n, yoast-y] => 63
    [theme-y, first-y] => 14
)
*/


		$args = array(

			'post_type'   => 'post',
			'post_status' => array( 'publish' ),
			'fields'      => 'ids',
			
// What about setting if there is no theme settings????
// Must have required postmeta value.
// 'meta_query'  => array(
// 	array(
// 		'key'     => self::TD_POST_THEME_SETTINGS,
// 		'value'   => '"' . self::TD_POST_THEME_SETTINGS_PRIMARY_CAT . '"',
// 		'compare' => 'LIKE',
// 	),
// ),
			
			// Order by date for better logging in order.
			'orderby'     => 'date',
			'order'       => 'DESC',

		);

		$report = array(
			'total' => 0,
		);

		$this->posts_logic->throttled_posts_loop( 
			$args,
			function ( $post_id ) use ( &$report ) {

				$report['total']++;

				$this->logger->log( $this->log, "\n" );
				$this->logger->log( $this->log, '---- Post: ' . $post_id );
			
				


				// Theme Cat.
				$postmeta = get_post_meta( $post_id, self::TD_POST_THEME_SETTINGS, true );

				$theme_cat = null;
				if ( isset( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] ) 
					&& is_numeric( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] ) 
					&& ( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] > 0 )
				) {
					$theme_cat = get_category( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] );
					if( is_object( $theme_cat ) && ! is_wp_error( $theme_cat ) ) {
						$this->logger->log( $this->log, 'Theme cat: ' . $theme_cat->name . ' / ' . $theme_cat->slug );
					} else {
						$theme_cat = null;
					}
				}

				// Yoast.
				$postmeta = get_post_meta( $post_id, '_yoast_wpseo_primary_category', true );

				$yoast_cat = null;
				if ( is_numeric( $postmeta ) && $postmeta > 0 ) {
					$yoast_cat = get_category( $postmeta );
					if( is_object( $yoast_cat ) && ! is_wp_error( $yoast_cat ) ) {
						$this->logger->log( $this->log, 'Yoast cat: ' . $yoast_cat->name . ' / ' . $yoast_cat->slug );
					} else {
						$yoast_cat = null;
					}
				}

				// Post cats.
				$post_cats = wp_get_post_categories( $post_id, [ 'fields' => 'all' ] );
				$first_post_cat = null;
				foreach( $post_cats as $cat ) {
					$this->logger->log( $this->log, 'Post cat: ' . $cat->name . ' / ' . $cat->slug );
					if( empty( $first_post_cat ) ) $first_post_cat = $cat;
				}
				
				




				
				// HTML.

				$file_get_contents = $this->cache_or_fetch( $post_id, '.html', 'https://mountainexpressmagazine.com/?p=' );

				preg_match_all( '#entry-crumb" href="https://mountainexpressmagazine.com/category/([^"]+)">([^<]+)<#', $file_get_contents, $matches, PREG_SET_ORDER );

				if( empty( $matches ) ) {
					$this->logger->log( $this->log, 'HTML preg_match_all fail. USE POST CATEGORY.', $this->logger::WARNING );
					return;
				}

				$last_match = $matches[ count($matches) - 1 ]; // heirarchial cats

				if( 3 != count( $last_match ) ) {
					$this->logger->log( $this->log, 'HTML last_match fail: ' . print_r( $matches, true ), $this->logger::ERROR, true );
				}

				preg_match( '#([^/]+)/$#', $last_match[1], $last_match_slug_matches );

				if( 2 != count( $last_match_slug_matches ) ) {
					$this->logger->log( $this->log, 'last_match_slug_matches: ' . print_r( $last_match_slug_matches, true ), $this->logger::ERROR, true );
				}

				$body_cat_slug = $last_match_slug_matches[1];
				$body_cat_name = $last_match[2];
				$body_cat_name = str_replace( '&#039;', "'", $body_cat_name );
				$this->logger->log( $this->log, 'HTML: ' . $body_cat_name . ', ' . $body_cat_slug );

				// JSON.

				$file_get_contents = $this->cache_or_fetch( $post_id, '.json', 'https://mountainexpressmagazine.com/wp-json/wp/v2/posts/' );

				$json = json_decode( $file_get_contents, null, 2147483647 );
				$json_last_error = json_last_error();
				$json_last_error_msg = json_last_error_msg();

				if( 0 != $json_last_error || 'No error' != $json_last_error_msg ) {
					$this->logger->log( $this->log, 'JSON error: ' . $json_last_error . ' - ' . $json_last_error_msg, $this->logger::ERROR, true );	
				}

				$json_cat_name = '';
				
				if( empty( $json->yoast_head_json->schema->{"@graph"}[0]->articleSection[0] ) ) {
					// $this->logger->log( $this->log, 'JSON section fail', $this->logger::WARNING );
				} else {
					$json_cat_name = $json->yoast_head_json->schema->{"@graph"}[0]->articleSection[0];
				}

				$this->logger->log( $this->log, 'Json: ' . $json_cat_name );	
				
				// Tests.
				// if( $json_cat_name != $body_cat_name ) {
				// 	$this->logger->log( $this->log, 'cat mismatch', $this->logger::WARNING );
				// }






				$loop_report = array();
				
				if( !empty( $theme_cat ) ) {
					if( $body_cat_name == $theme_cat->name && $body_cat_slug == $theme_cat->slug ) $loop_report[] = 'theme-y';
					else $loop_report[] = 'theme-n';
				}
				
				if( !empty( $first_post_cat ) ) {
					if( $body_cat_name == $first_post_cat->name && $body_cat_slug == $first_post_cat->slug ) $loop_report[] = 'first-y';
					else $loop_report[] = 'first-n';
				}
				
				if( !empty( $yoast_cat ) ) {
					if( $body_cat_name == $yoast_cat->name && $body_cat_slug == $yoast_cat->slug ) $loop_report[] = 'yoast-y';
					else $loop_report[] = 'yoast-n';
				}
				

				if( 0 == count( $loop_report ) ) {
					$this->logger->log( $this->log, 'loop report empty', $this->logger::ERROR, true );
				}

				$loop_report_str = implode( ', ', $loop_report );
				$this->logger->log( $this->log, 'loop_report_str: ' . $loop_report_str );

				if( ! isset( $report[$loop_report_str] ) ) $report[$loop_report_str] = 0;
				$report[$loop_report_str]++;

				// update post meta
				$this->logger->log( $this->log, 'report: ' . print_r( $report, true ) );


			},
			1,
			500
		);

		
		wp_cache_flush();

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Sanitize DB post meta author source names.
	 *
	 * @param string $source String from post meta.
	 * @return string $source
	 */
	private function sanitize_source( $source ) {

		// Remove unicode line breaks and left-to-right ("u" modifier).
		$source = trim( preg_replace( '/\x{2028}|\x{200E}/u', '', $source ) );

		// Replace unicode spaces with normal space, ("u" modifier).
		$source = trim( preg_replace( '/\x{00A0}|\x{200B}|\x{202F}|\x{FEFF}/u', ' ', $source ) );

		// Replace multiple spaces with single space.
		$source = trim( preg_replace( '/\s{2,}/', ' ', $source ) );

		// Remove leading "by" (case insensitive).
		$source = trim( preg_replace( '/^by\s+/i', '', $source ) );

		return $source;
	}

	private function cache_or_fetch( $post_id, $file_suffix, $url_prefix ) {

		$file_key = 'posts/' . $post_id . $file_suffix;
				
		if( file_exists( $file_key ) ) {
			$this->logger->log( $this->log, 'Using cached file: ' . $file_key );
			$file_get_contents = file_get_contents( $file_key );
		} else {
			$url = $url_prefix . $post_id;
			$this->logger->log( $this->log, 'Fetching url: ' . $url );
			$file_get_contents = file_get_contents( $url );
			file_put_contents( $file_key, $file_get_contents );
		}

		if( 0 == strlen( trim( $file_get_contents ) ) ) {
			$this->logger->log( $this->log, 'File get content is empty. USE POST CAT.', $this->logger::WARNING, true );	
		}


		return $file_get_contents;

	}

}
