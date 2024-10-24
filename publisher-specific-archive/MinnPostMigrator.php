<?php
/**
 * Migrator for MinnPost
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\Logger;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use stdClass;
use \WP_CLI;
use \WP_Query;
use \WP_User_Query;

/**
 * Custom migration scripts for MinnPost.
 */
class MinnPostMigrator implements InterfaceCommand {

	const OLD_SITE_URL = 'https://www.minnpost.com/';
	
	const CATEGORIES_TO_TAGS_CONVERT = array( 
		'arts-culture' => 'Arts & Culture',
		'news' => 'News', 
		'opinion' => 'Opinion', 
	);

	private $byline_known_names = null;
	private $byline_known_suffixes = null;

	/**
	 * Instance of MinnPostMigrator.
	 *
	 * @var null|InterfaceCommand
	 */
	private static $instance = null;

	/**
	 * Instance of Posts.
	 *
	 * @var Posts $posts Instance of Posts.
	 */
	private $posts;

	/**
	 * Instance of Logger.
	 *
	 * @var Logger $logger Instance of Logger.
	 */
	private $logger;

	/**
	 * Instance of CoAuthorsPlus.
	 * 
	 * @var CoAuthorPlus $coauthorsplus Instance of CoAuthorsPlus
	 */
	private $coauthorsplus;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus = new CoAuthorPlus();
		$this->posts = new Posts();
		$this->logger = new Logger();
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
			'newspack-content-migrator minnpost-convert-categories-to-tags',
			[ $this, 'cmd_convert_categories_to_tags' ],
			[
				'shortdesc' => 'Convert selected categories to tags.  Update post terms.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator minnpost-set-authors-by-subtitle-byline',
			[ $this, 'cmd_set_authors_by_subtitle_byline' ],
			[
				'shortdesc' => 'Convert old post meta to CAP GAs (or matching WP User) and assign to Post.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'txt-names',
						'description' => 'Known names TXT file. --txt-names="path/names.txt"',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'txt-suffixes',
						'description' => 'Known suffixes TXT file. --txt-suffixes="path/suffixes.txt"',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'test-regex',
						'description' => 'If used, all bylines from postmeta will be run though regex, without db updates.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator minnpost-set-featured-images',
			[ $this, 'cmd_set_featured_images' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator minnpost-set-primary-category',
			[ $this, 'cmd_set_primary_category' ],
		);

	}

	public function cmd_convert_categories_to_tags( $pos_args, $assoc_args ) {

		// each category to convert.
		foreach( array_keys( self::CATEGORIES_TO_TAGS_CONVERT ) as $category_slug ) {
			
			// get category.
			$category = get_category_by_slug( $category_slug );
			if( empty( $category->term_id ) ) {
				$this->logger->log( 
					'minnpost_convert_categories_to_tags.txt', 
					sprintf( 'Skipping %s category. Not found.', $category_slug ),
					$this->logger::WARNING
				);
				continue;
			}

			// get posts in category
			$post_ids = $this->posts->get_all_posts_ids_in_category( $category->term_id, 'post', [ 'publish' ] );
			$post_ids_count = count( $post_ids );

			$this->logger->log( 
				'minnpost_convert_categories_to_tags.txt', 
				sprintf( 'Processing %s category: %d post count.', $category_slug, $post_ids_count )
			);

			foreach( $post_ids as $key_post_id => $post_id ) {

				$this->logger->log( 
					'minnpost_convert_categories_to_tags.txt', 
					sprintf( '%d (%d of %d)', $post_id, $key_post_id + 1, $post_ids_count )
				);

				// save removed categtory incase needed for future reference
				// might be multiple categories
				add_post_meta( $post_id, 'newspack_minnpost_removed_category', $category_slug );

				// add tag
				wp_set_post_tags( $post_id, self::CATEGORIES_TO_TAGS_CONVERT[$category_slug], true );

				// remove category and let WordPress/Yoast just pick a new url even if that means "uncategorized".
				// original live url that contains primary category will be saved via the yoast primary category cli
				// specify $category->term_id int type else term might match a category based on string match instead
				wp_remove_object_terms( $post_id, (int) $category->term_id, 'category' );

				$this->logger->log( 
					'minnpost_convert_categories_to_tags.txt', 
					sprintf( 'Converted post %d', $post_id ),
					$this->logger::SUCCESS
				);

			} // foreach post id

			wp_cache_flush();

			$this->logger->log( 
				'minnpost_convert_categories_to_tags.txt', 
				sprintf( 'Category processed: %s', $category_slug ),
				$this->logger::SUCCESS
			);

		} // foreach category

	}

	public function cmd_set_authors_by_subtitle_byline( $pos_args, $assoc_args ) {

		// load csv file		
		$this->byline_known_names = $this->load_from_txt( $assoc_args[ 'txt-names' ] );
		if( empty( $this->byline_known_names ) ) {
			WP_CLI::error( 'TXT Names is empty.' );
		}

		// load csv file		
		$this->byline_known_suffixes = $this->load_from_txt( $assoc_args[ 'txt-suffixes' ] );
		if( empty( $this->byline_known_suffixes ) ) {
			WP_CLI::error( 'TXT Suffixes is empty.' );
		}

		// test only if set
		if( isset( $assoc_args['test-regex']) ) {
			return $this->byline_test_regex();
		}

		// needs coauthors plus plugin
		if ( ! $this->coauthorsplus->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		// cli vars
		$log_file = 'minnpost_set_authors_by_subtitle_byline.txt';
		
		// enum for meta values
		$result_types = new stdClass();
		$result_types->assigned_to_existing_ga = 'assigned_to_existing_ga';
		$result_types->assigned_to_existing_wp_user = 'assigned_to_existing_wp_user';
		$result_types->assigned_to_new_ga = 'assigned_to_new_ga';
		$result_types->skip_error = 'skip_error';
		
		// reporting
		$report = array();
		$report_add = function( $key ) use( &$report ) {
			if( empty( $report[$key] ) ) $report[$key] = 0;
			$report[$key]++;
		};

		// allowed wp users to assign posts to 
		$allowed_wp_users = ( function() {
			$wp_user_query = new WP_User_Query( [
				'role__in' => array( 'administrator', 'editor', 'author', 'contributor', 'staff' ),
			]);
			$users = [];
			foreach( $wp_user_query->get_results() as $user ) {
				$users[$user->ID] = $user->display_name;
			}
			return $users;
		})(); // run function
	
		// start
		$this->logger->log( $log_file, 'Setting authors by subtitle byline.' );

		// do while rows exist (ie: return value is true)
		while( $this->set_authors_by_subtitle_byline( $log_file, $result_types, $report_add, $allowed_wp_users ) ) {
			$this->logger->log( $log_file, print_r( $report, true ) );
		}

		$this->logger->log( $log_file, 'Done.', $this->logger::SUCCESS );

	}

	public function cmd_set_featured_images( $pos_args, $assoc_args ) {
	
		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish' ] );

		foreach ( $post_ids as $key_post_id => $post_id ) {
			
			WP_CLI::line( sprintf( '%d (%d of %d)', $post_id, $key_post_id + 1, count( $post_ids ) ) );

			// Attempt to set the main image.
			if( $this->set_featured_image( $post_id, '_mp_post_main_image_id' ) ) continue;

			// Otherwise, attempt to set the thumbnail image.
			if( $this->set_featured_image( $post_id, '_mp_post_thumbnail_image_id' ) ) continue;

			// Worst case, just log unable to update post.
			$this->logger->log( 'minnpost_err.txt', sprintf( 
				'Unable to set featured image for post %d', 
				$post_id
			), $this->logger::WARNING );

		}

		wp_cache_flush();
	}

	public function cmd_set_primary_category( $pos_args, $assoc_args ) {
			
		$excluded_primary_cats = array_keys( self::CATEGORIES_TO_TAGS_CONVERT );

		$remote_failures_in_a_row = 0;

		// select posts where yoast not already set
		$query = new WP_Query ( [
			'posts_per_page' => -1,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => '_yoast_wpseo_primary_category',
					'compare' => 'NOT EXISTS',
				],
			]
		]);

		$post_ids_count = $query->post_count;

		$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
			'Processing post count: %d', 
			$post_ids_count
		));

		foreach ( $query->posts as $key_post_id => $post_id ) {
			
			$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
				'%d ( %d of %d )',
				$post_id, $key_post_id + 1, $post_ids_count
			));

			// get else set the remote url
			$live_url = get_post_meta( $post_id, 'newspack_minnpost_live_site_url', true );

			if( empty ( $live_url ) ) {

				$live_url = $this->get_remote_url_by_rest_api( $post_id );

				if( empty( $live_url ) ) {

					$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
						'Remote get error. Possibly IP blocked. Try again later. Post id: %d', 
						$post_id
					), $this->logger::WARNING );
					
					// stop if remote keeps failing
					$remote_failures_in_a_row++;
					if( $remote_failures_in_a_row >= 10 ) return;

					continue;

				}

				$remote_failures_in_a_row = 0;

				update_post_meta( $post_id, 'newspack_minnpost_live_site_url', $live_url );

			}

			// parse out category slug
			$url_parts = explode( '/', parse_url( $live_url, PHP_URL_PATH ) );
			if( ! isset( $url_parts[1] ) ) {
				
				$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
					'Live url not parseable. Post id: %d url: %s', 
					$post_id, $live_url
				), $this->logger::ERROR, true );

			}
			
			// make sure it's a local category
			$category = get_category_by_slug( $url_parts[1] );
			if( ! isset( $category->term_id ) ) {
				
				$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
					'Parsed url not category. Post id: %d url: %s', 
					$post_id, $live_url
				), $this->logger::WARNING );
				
				continue;

			}

			// make sure it's a local category
			if( in_array( $category->slug, $excluded_primary_cats ) ) {
				
				$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
					'Parsed url is in an excluded category. Post id: %d url: %s', 
					$post_id, $live_url
				), $this->logger::WARNING );
				
				continue;

			}

			// make sure this category is already assigned to the post
			if( ! has_category( $category->term_id, $post_id ) ) {

				$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
					'Primary category is not on post %d category_id %d', 
					$post_id, $category->term_id
				), $this->logger::WARNING );

				continue;

			}

			// set yoast
			update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category->term_id );
			
		}

		wp_cache_flush();
		
	}

	/**
	 * PERMALINK FUNCTIONS
	 */

	private function get_old_permalink_category( $post_id ) {

		// _category_permalink is unreliable
		return null;

		$post_categories = wp_get_post_categories( $post_id );

		// if there is only 1 category for the post, return it now
		if( ! empty( $post_categories ) && is_array( $post_categories ) && 1 === count( $post_categories ) ) {
			return get_category( (int) $post_categories[0] );
		}
		
		// get the old post meta
		$old_category_permalink = get_post_meta( $post_id, '_category_permalink', true );
		$old_category_id = 0;

		// db value like: a:1:{s:8:"category";s:5:"55567";}
		if( isset( $old_category_permalink['category'] ) ) $old_category_id = $old_category_permalink['category'];
		// just an integer like: 12345
		else if( preg_match( '/^\d+$/', $old_category_permalink ) ) $old_category_id = $old_category_permalink;

		// if it matches to a real category, return it
		$old_category = get_category( (int) $old_category_id );
		if( isset( $old_category->term_id ) ) {
			return $old_category;
		}

		// at this point, the old category is not found and the post has multiple categories...
		// maybe the old site had some additional logic like a look up / priority table or just alphabetical?
		
		// log error
		$this->logger->log( 'minnpost_set_primary_category.txt', sprintf( 
			'Old category not found for post %d', 
			$post_id
		), $this->logger::ERROR, true );

		return null;

	 }



	/**
	 * FEATURED IMAGE FUNCTIONS
	 */

	private function set_featured_image( $post_id, $meta_key ) {

		global $wpdb;

		// Attempt to get the image id by meta_key.
		$thumb_id = $wpdb->get_var( $wpdb->prepare( 
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", 
			$post_id, $meta_key
		));

		if( ! is_numeric( $thumb_id ) ) return false;

		// Verify the image is actually an attachment in the Media Libary.
		$image_exists = $wpdb->get_var( $wpdb->prepare( 
			"SELECT 'yes' FROM {$wpdb->posts} WHERE ID = %d and post_type = 'attachment'",
			$thumb_id
		));

		if ( 'yes' !== $image_exists ) {
			
			// Log these cases where id was found, but didn't match to a real attachment.
			$this->logger->log( 'minnpost_err.txt', sprintf( 
				'Invalid image_id %d for post %d and size %s', 
				$thumb_id, $post_id, $meta_key 
			), $this->logger::WARNING );
			
			return false;

		}

		// Set the thumbnail.

		update_post_meta( $post_id, '_thumbnail_id', $thumb_id );

		$this->logger->log( 'minnpost_success.txt', sprintf( 
			'post_id %d thumb_id %d size %s', 
			$post_id, $thumb_id, $meta_key
		), $this->logger::SUCCESS );

		return true;

	}


	/**
	 * BYLINE FUNCTIONS
	 */

	private function set_authors_by_subtitle_byline( $log_file, $result_types, $report_add, $allowed_wp_users ) {

		// meta keys for main query and reporting		
		$meta_key_byline = '_mp_subtitle_settings_byline';
		$meta_key_result = 'newspack_minnpost_subtitle_byline_result';

		// start
		$this->logger->log( $log_file, '---- New batch.' );

		// select posts with byline subtitle meta, and not already processed
		$query = new WP_Query ( [
			'posts_per_page' => 1000,
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => $meta_key_byline,
					'compare' => 'EXISTS',
				],
				[
					'key'     => $meta_key_result,
					'compare' => 'NOT EXISTS',
				],
			]
		]);

		$this->logger->log( $log_file, 'Post count: ' . $query->post_count  );

		if( 0 == $query->post_count ) return false;

		foreach ( $query->posts as $post_id ) {
			
			$this->logger->log( $log_file, '-- Post ID: ' . $post_id  );

			// get byline
			$byline_uncleaned_from_db = get_post_meta( $post_id, $meta_key_byline, true );

			$this->logger->log( $log_file, 'Byline (raw db value): ' . $byline_uncleaned_from_db  );

			// clean the db value
			// I left in the case to "error if unicode is found". so we don't end up with unicode in the new GA author names.
			// possibly need to refactor this into a warning in the log file with a "continue" instead...
			// but...if error: need to add replacement character to function: $this->byline_replace_hidden_chars
			$byline_cleaned = $this->byline_cleaner( $byline_uncleaned_from_db, true );
			
			// cleaned byline
			$this->logger->log( $log_file, 'Byline (cleaned): ' . $byline_cleaned  );

			// get the matches
			// return will be string|array|null
			$found_bylines = $this->byline_regex( $byline_cleaned, $byline_uncleaned_from_db );

			// if empty value, skip this post
			if( empty( $found_bylines ) ) {
				add_post_meta( $post_id, $meta_key_result, $result_types->skip_error );
				$this->logger->log( $log_file, $result_types->skip_error );
				$report_add( $result_types->skip_error );
				continue;

			}

			// make single string value into array for the loop
			if( is_string( $found_bylines ) ) $found_bylines = array( $found_bylines );

			// loop through each name set with an $i counter
			for( $i = 0; $i < count( $found_bylines ); $i++ ) {

				// set the first author to clear any existing authors when CAP assigns author to the post
				if( 0 == $i) $append_to_existing_users = false;
				// append additional authors $i > 0 to the post
				else $append_to_existing_users = true;

				// do the DB update
				$result_type = $this->set_authors_by_subtitle_byline_in_db( $result_types, $allowed_wp_users, $post_id, $found_bylines[$i], $append_to_existing_users );
	
				// log the result in postmeta
				// allow multiple post metas incase multiple authors per post_id
				add_post_meta( $post_id, $meta_key_result, $result_type );
				$this->logger->log( $log_file, $result_type );
				$report_add( $result_type );

			} // for loop bylines
			
		} // foreach query post

		return true;

	}

	private function set_authors_by_subtitle_byline_in_db( $result_types, $allowed_wp_users, $post_id, $byline, $append_to_existing_users ) {

		global $wpdb;

		// check if an there is an existing GA by display name
		// limit to the first match found incase multiple matching display names
		$ga_id = $wpdb->get_var( $wpdb->prepare("
			SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = 'guest-author'
			AND post_title = %s
			LIMIT 1
			",  
			$byline,
		));

		// if ga ID exists, then assign to post
		if( is_numeric( $ga_id ) && $ga_id > 0 ) {
			$this->coauthorsplus->assign_guest_authors_to_post( array( $ga_id ), $post_id, $append_to_existing_users );
			return $result_types->assigned_to_existing_ga;
		}

		// try get to an existing wp user
		$wp_user_id = array_search( $byline, $allowed_wp_users );
		if( is_numeric( $wp_user_id ) && $wp_user_id > 0 ) {
			$this->coauthorsplus->assign_authors_to_post( array( get_user_by( 'id', $wp_user_id ) ), $post_id, $append_to_existing_users );
			return $result_types->assigned_to_existing_wp_user;
		}

		// if no matches above, create a new guest author and assign to post
		$coauthor_id = $this->coauthorsplus->create_guest_author( array( 'display_name' => $byline ) );
		$this->coauthorsplus->assign_guest_authors_to_post( array( $coauthor_id ), $post_id, $append_to_existing_users );
		return $result_types->assigned_to_new_ga;

	}

	private function byline_test_regex() {

		$csv_out_file = fopen( 'minnpost_byline_test_regex_report.csv', 'w' );

		$report = [
			'posts_fixed' => 0,
			'posts_failed' => 0,
		];

		global $wpdb;

		// get bylines group by number of posts byline affects
		// group by case sensitive values. Known_names.txt is case senstive (at least at this point in time...)
		$results = $wpdb->get_results("
			select distinct binary(meta_value) as case_senstive_meta_value, meta_value as byline, count(*) as post_count
			from {$wpdb->postmeta}
			where meta_key = '_mp_subtitle_settings_byline'
			group by case_senstive_meta_value, meta_value
			order by post_count desc
		");

		foreach ( $results as $result ) {

			// set the db value as "uncleaned"
			$byline_uncleaned_from_db = $result->byline;

			// clean the db value and error if unicode issues
			$byline_cleaned = $this->byline_cleaner( $byline_uncleaned_from_db, true );
				
			// get the matches
			// return will be string|array|null
			$found_bylines = $this->byline_regex( $byline_cleaned, $byline_uncleaned_from_db );

			// if no match this is an error byline, set match count to 0
			if( empty( $found_bylines ) ) {

				// set all these posts as failed
				$report['posts_failed'] += $result->post_count;

				// write output to csv
				\WP_CLI\Utils\write_csv( $csv_out_file, array( array( 
					$result->post_count,
					'ERROR_NO_MATCHES',
					$byline_uncleaned_from_db,
					$byline_cleaned,
				) ) ); // array within array

			}
			// matches exist
			else {

				// set all these posts as fixed
				$report['posts_fixed'] += $result->post_count;

				// make single string into array
				if( is_string( $found_bylines ) ) $found_bylines = array( $found_bylines );

				foreach( $found_bylines as $found_byline ) {

					// how could this case happen?  a blank value or only 1 character?
					// - fix when it was a split of 5...but leave this block here incase it comes back...
					if( empty( $found_byline) || strlen(trim( $found_byline ) ) < 2 ) {
						WP_CLI::line( $byline_uncleaned_from_db );
						WP_CLI::line( $byline_cleaned );
						print_r($found_bylines);
						exit();
					}

					// write output to csv
					\WP_CLI\Utils\write_csv( $csv_out_file, array( array( 
						$result->post_count,
						$found_byline,
						$byline_uncleaned_from_db,
						$byline_cleaned,
					) ) ); // array within array

				} // foreach
		
			} // else

		} // foreach

		print_r( $report );
		
		WP_CLI::success( 'Done' );

	}

	private function byline_error_if_unknown_chars( $byline ) {
		
		// known characters: unicode letters, ’ (U+2019), ” (U+201D), “ (U+201C), ascii letters and digits, special characters, ("u" modifier)
		$byline_cleaned = trim( preg_replace( '/[\p{L}\x{2019}\x{201D}\x{201C}\w\/.,\'"-:;\|`]/u', '', $byline ) );

		if( ! empty( $byline_cleaned ) ) {
			WP_CLI::line( $byline );
			WP_CLI::line( $byline_cleaned );
			WP_CLI::error( 'Byline has unknown characters. Either add to known list or replace with non-unicode.' );
		}

	}

	private function byline_replace_hidden_chars( $byline ) {

		// Replace UTF-8 encoded line breaks, left-to-right, with nothing so they are trimmed, ("u" modifier)
		$byline = trim( preg_replace( '/\x{2028}|\x{200E}/u', '', $byline ) );

		// Replace UTF-8 encoded spaces with normal space, ("u" modifier)
		$byline = trim( preg_replace( '/\x{00A0}|\x{200B}|\x{202F}/u', ' ', $byline ) );

		return $byline;

	}

	private function byline_cleaner( $byline, $error_on_unknown_char = false ) {

		// remove/replace hidden chars
		$byline = $this->byline_replace_hidden_chars( $byline );

		// if error mode true, test for new hidden/unknown chars
		if( $error_on_unknown_char ) $this->byline_error_if_unknown_chars( $byline );

		// remove starting "By" with any spaces (case insensitive)
		$byline = trim( preg_replace( '/^By[:]?\s+/i', '', $byline ) );

		// remove other "by" in text
		$byline = trim( preg_replace( '/\b(Analysis|Commentary|Designed|Editorial|Embedded|Interview|Satire|Text|Videos)\s+by\b/i', '', $byline ) );

		// clean up ands
		$byline = trim( preg_replace( '/ and and /i', ' and ', $byline ) );
		$byline = trim( preg_replace( '/, and /i', ' and ', $byline ) );
		$byline = trim( preg_replace( '/, with /i', ' with ', $byline ) );

		// replace special chars with comma
		$byline = trim( preg_replace( '/[\|\/;]/i', ',', $byline ) );
		
		// standardize commas with one space
		$byline = trim( preg_replace( '/\s*,\s*/', ', ', $byline ) );

		// trim and replace multiple spaces with single space
		$byline = trim( preg_replace( '/\s{2,}/', ' ', $byline ) );

		return $byline;

	}

	// return: string|array|null
	private function byline_regex( $byline, $byline_uncleaned_from_db ) {

		// Add to this list to specify the return values for a byline
		// the key value should be the "clean byline" but if the key is set to the postmeta/database byline, 
		// then try to make sure not to copy hidden Unicode characters into the code below (see byline_replace_hidden_chars )
		// if you want a byline to return null (ie: don't do any replacements), then set it's value to an empty array() or '' string
		$overrides = [
			'a correspondent, Christian Science Monitor' => 'A Correspondent at Christian Science Monitor',
			'Albert Turner Goins, Sr.' => 'Albert Turner Goins, Sr.',
			'Amy Klobuchar, Mitch Pearlstein, et al' => array( 'Amy Klobuchar', 'Mitch Pearlstein' ),
			'B w, Burnsville' => 'B W, Burnsville',
			'becky Bock, Carlos' => array(),
			'Becky Lourey, Lynnell Mickelsen' => array( 'Becky Lourey', 'Lynnell Mickelsen'),
			'Bennet Goldstein, Wisconsin Watch, Sarah Whites-Koditschek and Dennis Pillion, AL.com' => array( 'Bennet Goldstein', 'Sarah Whites-Koditschek', 'Dennis Pillion'),
			'Bianca Virnig and five others' => array(),
			'Daniel B. Wood, Gloria Goodale' => array( 'Daniel B. Wood', 'Gloria Goodale'),
			'Dennis Schulstad, Harry Sieben, Jr. and Bertrand Weber' => array( 'Dennis Schulstad', 'Harry Sieben, Jr.', 'Bertrand Weber' ),
			'Derek Wallbank`' => 'Derek Wallbank',
			'Dr. Edward P. Ehlinger Dr. Janelle Palacios and Dr. Magda Peck' => array( 'Dr. Edward P. Ehlinger', 'Dr. Janelle Palacios', 'Dr. Magda Peck' ),
			'dou' => '',
			'Eloisa James, The Barnes & Noble Review' => 'Eloisa James',
			'Ely, Cook, Tower Timberjay' => array(),
			'Eric Black but really by Brent Cunningham' => array(),
			'Erik Hare, Friday, Dec. 10, 2010' => 'Erik Hare',
			'Friends and colleagues of Babak Armajani' => 'Friends and colleagues of Babak Armajani',
			'G.R. Anderson, Jr.' => 'G.R. Anderson, Jr.',
			'Gaa-ozhibii’ang Cynthia Boyd, Gaa-anishinaabewisidood Anton Treuer' => array( 'Cynthia Boyd', 'Anton Treuer'),
			'Girish Gupta and the Global Post News Desk' => array( 'Girish Gupta', 'The Global Post News Desk' ),
			'Global' => '',
			'Heather Silsbee, Kristen Ingle, et al.' => array( 'Heather Silsbee', 'Kristen Ingle' ),
			'Hugh Macleod and a reporter in Damascus' => array( 'Hugh Macleod', 'A reporter in Damascus' ),
			'Hugh Macleod and a reporter in Syria' => array( 'Hugh Macleod', 'A reporter in Syria' ),
			'Ian MacDougall for ProPublica' => 'Ian MacDougall',
			'Jack Kelly for Wisconsin Watch' => 'Jack Kelly',
			'James Forest and The Conversation' => array( 'James Forest', 'The Conversation' ),
			'Jamie Millard, Meghan Murphy' => array( 'Jamie Millard', 'Meghan Murphy'),
			'Jay Hancock, Kaiser Health News, and Beth Schwartzapfel, The Marshall Project' => array( 'Jay Hancock', 'Beth Schwartzapfel' ),
			'jay' => '',
			'John Keefe and Louise Ma, WNYC, Chris Amico, Glass Eye Media and Steve Melendez, Alan Palazzolo' => array( 'John Keefe', 'Louise Ma', 'Chris Amico', 'Steve Melendez', 'Alan Palazzolo'),
			'Justin Elliott, Joshua Kaplan, Alex Mierjeski, ProPublica' => array( 'Justin Elliott', 'Joshua Kaplan', 'Alex Mierjeski' ),
			'Kadra Abdi et al' => 'Kadra Abdi',
			'Kadra Abdi, Ayantu Ayana, Ramla Bile, Mohamed H. Mohamed, Julia Nekessa Opoti' => array( 'Kadra Abdi', 'Ayantu Ayana', 'Ramla Bile', 'Mohamed H. Mohamed', 'Julia Nekessa Opoti' ),
			'Kay kessel, Richfield' => 'Kay Kessel',
			'Kenneth Kaplan Staff writer, Mark Guarino Staff writer' => array( 'Kenneth Kaplan', 'Mark Guarino'),
			'Kyle Stokes and Greta Kaul, MinnPost, Melody Hoffmann and Charlie Rybak, Minneapolis Voices' => array( 'Kyle Stokes', 'Greta Kaul', 'Melody Hoffmann', 'Charlie Rybak'),
			'Lindsey Dyer, Librarian with Dakota County Library' => 'Lindsey Dyer',
			'Liz Marlantes DCDecoder' => 'Liz Marlantes',
			'Logan Jaffe, Mary Hudetz and Ash Ngu, ProPublica and Graham Lee Brewer, NBC News' => array( 'Logan Jaffe', 'Mary Hudetz', 'Ash Ngu', 'Graham Lee Brewer' ),
			'Magda Munteanu, Kristina Ozimec, Gabriela Delova, Alisa Mysliu' => array( 'Magda Munteanu', 'Kristina Ozimec', 'Gabriela Delova', 'Alisa Mysliu' ),
			'Marty Hobe, TMJ4 News, and Bram Sable-Smith, Wisconsin Watch, WPR' => array( 'Marty Hobe', 'Bram Sable-Smith'),			
			'Mary Harris, Fred Mogul, Louise Ma, Jenny Ye and John Keefe, WNYC' => array( 'Mary Harris', 'Fred Mogul', 'Louise Ma', 'Jenny Ye', 'John Keefe' ),
			'Maureen Scallen Failor and four others' => array(),
			'Minneapolis, St. Paul Business Journal' => 'Minneapolis/St. Paul Business Journal',
			'Minnesota State Colleges & Universities Magazine' => 'Minnesota State Colleges & Universities Magazine',
			'Minnesota State Colleges and Universities' => 'Minnesota State Colleges and Universities',
			'MinnPost and Minneapolis Voices' => array( 'MinnPost', 'Minneapolis Voices' ),
			'Neal Kielar, Tuesday, June 8, 2010' => 'Neal Kielar',
			'Olga Pierce, Jeff Larson and Lois Beckett ProPublica' => array( 'Olga Pierce', 'Jeff Larson', 'Lois Beckett' ),
			'Rachel Widome and 8 co-authors' => array(),
			'Reuben Saltzman:' => 'Reuben Saltzman',
			'Robert "Again" Carney, Jr.' => 'Robert "Again" Carney, Jr.',
			'Ryan Allen, Jack DeWaard, Erika Lee, Christopher Levesque and 3 others' => array(),
			'Sara Miller Llana Staff writer and Dheepthi Namasivayam' => array( 'Sara Miller Llana', 'Dheepthi Namasivayam' ),
			'Sara Miller Llana, Ben Arnoldy' => array( 'Sara Miller Llana', 'Ben Arnoldy'),
			'Sharon Schmickle, David Brauer' => array( 'Sharon Schmickle', 'David Brauer'),
			'State Sen. Tom Bakk, State Rep. Morrie Lanning, State Sen. Julie Rosen, State Rep. Loren Solberg' => array( 'State Sen. Tom Bakk', 'State Rep. Morrie Lanning', 'State Sen. Julie Rosen', 'State Rep. Loren Solberg' ),
			'Steven Melendez, Dave Smith, Louise Ma, John Keefe, WNYC, Alan Palazzolo' => array( 'Steven Melendez', 'Dave Smith', 'Louise Ma', 'John Keefe', 'Alan Palazzolo'),
			'Sydney Lupkin, Kaiser Health News and Anna Maria Barry-Jester' => array( 'Sydney Lupkin,', 'Anna Maria Barry-Jester' ),
			'T. Christian Miller and Ryan Gabrielson, ProPublica and Ramon Antonio Vargas and John Simerman, The New Orleans Advocate' => array( 'T. Christian Miller', 'Ryan Gabrielson', 'Ramon Antonio Vargas', 'John Simerman' ),	
			'Test your knowledge of the official State MN Symbols' => '',
			'The Forum of Fargo, Moorhead' => 'The Forum of Fargo/Moorhead',
			'Timothy Brennan et al.' => array(),
			'Tower, Ely, Cook Timberjay' => array(),
			'various authors' => '',
		];

		// check cleaned byline first
		if( isset( $overrides[$byline] ) ) return $overrides[$byline];
		
		// check if key was copied directly form the postmeta db
		if( isset( $overrides[$byline_uncleaned_from_db] ) ) return $overrides[$byline_uncleaned_from_db];

		// skip: Stephanie Hemphill (multiple rows)
		// there is a bug in CAP plugin is failing on asisgning GA to post due to "+" in GA's postmeta email
		// todo: do by hand later
		if( preg_match( '/Stephanie Hemphill/i', $byline ) ) return null;
		
		// skip for now: remaining special chars and "by" (not already cleaned)
		if( preg_match( '/([:]|\bby\b)/i', $byline ) ) {
			$this->logger->log( 'minnpost_regex_warnings.txt', 'SPECIAL CHARS: ' . $byline, $this->logger::WARNING );
			return null;	
		}

		// parse "and"/"with"/etc case
		if( preg_match( '/\band\b|&|\bwith\b/i', $byline ) ) {
			return $this->bylines_do_and_case( $byline );
		}

		// parse commas now that "and with commas" above have been captured
		if( preg_match( '/,/', $byline ) ) {
			return $this->bylines_do_comma_case( $byline );
		}

		// simple string cases
		return $this->bylines_do_simple_string_case( $byline );

	}

	private function bylines_do_and_case( $byline ) {

		$splits = array_map( 'trim', preg_split( '/\band\b|&|\bwith\b|,/i', $byline, -1, PREG_SPLIT_NO_EMPTY ) );
				
		// first person only has first name
		if( false === strpos( $splits[0], ' ' ) ) {
			
			// try to remove suffix
			if( 3 == count( $splits ) ) {

				// if third is not suffix, error
				if( ! $this->byline_is_suffix( $splits[2] ) ) {

					$this->logger->log( 'minnpost_regex_warnings.txt', 'AND FIRSTNAME 3: ' . $byline, $this->logger::WARNING );
					return null;

				}

				// remove suffix
				unset( $splits[2] );
			}

			// add last name to first person
			$splits[0] = $splits[0] . preg_replace( '/^.*?\s/', ' ', $splits[1] );

			// check both names are in the known names and NOT in the suffix list
			if( 2 == count( $splits ) 
				&& $this->byline_is_name_and_not_suffix( $splits[0] )
				&& $this->byline_is_name_and_not_suffix( $splits[1] )
			){
				return $splits;
			}

			$this->logger->log( 'minnpost_regex_warnings.txt', 'AND FIRSTNAME 2: ' . $byline, $this->logger::WARNING );
			return null;
		}

		// try all matches to names
		$name_matches = 0;
		foreach( $splits as $split ) {
			if( $this->byline_is_name_and_not_suffix( $split ) ) $name_matches++;
		}

		if( $name_matches == count( $splits ) ) {
			return $splits;
		}
		
		// two names
		if( 2 == count( $splits ) ) {

			if( $this->byline_is_name( $splits[0] ) && $this->byline_is_name_and_not_suffix( $splits[1] ) ){
				return $splits;
			}

			$this->logger->log( 'minnpost_regex_warnings.txt', 'AND 2: ' . $byline, $this->logger::WARNING );
			return null;

		}

		// if 3 values
		if( 3 == count( $splits ) ) {

			// 3 names
			if( $this->byline_is_name( $splits[0] ) && $this->byline_is_name_and_not_suffix( $splits[1] ) && $this->byline_is_name_and_not_suffix( $splits[2] ) ){
				return $splits;
			} 

			// 2 names, one suffix
			if( $this->byline_is_name( $splits[0] ) && $this->byline_is_name_and_not_suffix( $splits[1] ) && $this->byline_is_suffix( $splits[2] ) ){
				return array( $splits[0], $splits[1] );
			} 

			// 1 name, 2 suffix
			if( $this->byline_is_name( $splits[0] ) && $this->byline_is_suffix( $splits[1] ) && $this->byline_is_suffix( $splits[2] ) ){
				return $splits[0];
			} 

			$this->logger->log( 'minnpost_regex_warnings.txt', 'AND 3: ' . $byline, $this->logger::WARNING );
			return null;

		}

		if( 4 == count( $splits ) ) {

			if( $this->byline_is_name( $splits[0] )
				&& $this->byline_is_name_and_not_suffix( $splits[1] ) && $this->byline_is_name_and_not_suffix( $splits[2])
				&& $this->byline_is_suffix( $splits[3])
			) {
				return [ $splits[0], $splits[1], $splits[2] ];
			}

			if( $this->byline_is_name( $splits[0] )
				&& $this->byline_is_suffix( $splits[1] ) && $this->byline_is_name_and_not_suffix( $splits[2])
				&& $this->byline_is_suffix( $splits[3])
			) {
				return [ $splits[0], $splits[2] ];
			}

			$this->logger->log( 'minnpost_regex_warnings.txt', 'AND 4: ' . $byline, $this->logger::WARNING );
			return null;
				
		}

		if( 5 == count( $splits ) ) {

			if( $this->byline_is_name( $splits[0] )
				&& $this->byline_is_name_and_not_suffix( $splits[1] ) && $this->byline_is_name_and_not_suffix( $splits[2] ) && $this->byline_is_name_and_not_suffix( $splits[3] )
				&& $this->byline_is_suffix( $splits[4] )
			) {
				return [ $splits[0], $splits[1], $splits[2], $splits[3] ];
			}

			$this->logger->log( 'minnpost_regex_warnings.txt', 'AND 5: ' . $byline, $this->logger::WARNING );
			return null;
				
		}

		$this->logger->log( 'minnpost_regex_warnings.txt', 'AND: ' . $byline, $this->logger::WARNING );
		return null;

	}

	private function bylines_do_comma_case( $byline ) {

		$splits = array_map( 'trim', preg_split( '/,/', $byline, -1, PREG_SPLIT_NO_EMPTY ) );

		// name, publication/suffix:
		if( 2 == count( $splits ) ) {
		
			// name and suffix
			if( $this->byline_is_name( $splits[0] ) && $this->byline_is_suffix( $splits[1] ) ) {
				return $splits[0];
			}

			$this->logger->log( 'minnpost_regex_warnings.txt', 'COMMA 2: ' . $byline, $this->logger::WARNING );
			return null;

		}

		// name, publication/suffix that might have a comma
		if( 3 == count( $splits ) ) {
			
			// if it's a multiple value suffix to remove
			if( $this->byline_is_name( $splits[0] ) && $this->byline_is_suffix( $splits[1] . ', ' . $splits[2] ) ) {
				return $splits[0];
			}
			
			// if both should be removed
			if( $this->byline_is_name( $splits[0] ) && $this->byline_is_suffix( $splits[1] ) &&  $this->byline_is_suffix( $splits[2] ) ) {
				return $splits[0];
			}

			$this->logger->log( 'minnpost_regex_warnings.txt', 'COMMA 3: ' . $byline, $this->logger::WARNING );
			return null;

		}

		$this->logger->log( 'minnpost_regex_warnings.txt', 'COMMA: ' . $byline, $this->logger::WARNING );
		return null;

	}

	private function bylines_do_simple_string_case( $byline ) {

		// normal byline - don't filter out suffixes since these don't have commas
		if( $this->byline_is_name( $byline ) ) {
			return $byline;
		}

		$this->logger->log( 'minnpost_regex_warnings.txt', 'STRING: ' . $byline, $this->logger::WARNING );
		return null;

	}

	private function byline_is_name( $name ) {

		// make sure it's in known names
		if( in_array( $name, $this->byline_known_names ) ) {
			return true;
		}

		return false;
	}

	private function byline_is_name_and_not_suffix( $name ) {

		// make sure its in Known names, and not in Suffixes
		if( $this->byline_is_name( $name ) && ! $this->byline_is_suffix( $name ) ) {
			return true;
		}

		return false;
	}

	private function byline_is_suffix( $suffix ) {

		// check if it's a suffix (publication name, date, etc)
		if( in_array( $suffix, $this->byline_known_suffixes ) ) {
			return true;
		}

		return false;
	}


	/**
	 * REQUEST FUNCTIONS
	 */

	private function get_remote_url_by_redirect_location( $post_id ) {

		$response = wp_remote_request( self::OLD_SITE_URL . '?p=' . $post_id, [ 'method' => 'HEAD' ] );
		if ( is_wp_error( $response ) ) return null;
		
		$headers = wp_remote_retrieve_headers( $response );
		if( ! isset( $headers['location'] ) ) return null;

		return $headers['location'];
	
	}

	private function get_remote_url_by_rest_api( $post_id ) {

		$response = wp_remote_request( self::OLD_SITE_URL . 'wp-json/wp/v2/posts/' . $post_id );
		if ( is_wp_error( $response ) ) return null;

		$body = wp_remote_retrieve_body( $response );
		if( empty( $body ) ) return null;

		$json = @json_decode( $body );
		if( empty( $json->link ) ) return null;

		return $json->link;

	}


	/**
	 * FILE FUNCTIONS
	 */

	 private function load_from_csv( $csv_path, $column_count = 1, $format = '' ) {

		// set path to file
		if( ! is_file( $csv_path ) ) {
			WP_CLI::error( 'Could not find CSV at path: ' . $csv_path );
		}
		
		// read
		$handle = fopen( $csv_path, 'r' );
		if ( $handle == FALSE ) {
			WP_CLI::error( 'Could not fopen CSV at path: ' . $csv_path );
		}

		$output = array();

		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {

			// csv data integrity
			if( $column_count != count( $row ) ) {
				WP_CLI::error( 'Error row column count mismatch: ' . print_r( $row, true ) );
			}

			// put data into a lookup based on first column
			if( 'lookup_column_1' == $format ) {
				$output[$row[0]] = array_slice( $row, 1 ); 
			}
			else if( 'lookup_column_1_multiple' == $format ) {
				if( empty( $output[$row[0]] ) ) $output[$row[0]] = array();
				$output[$row[0]][] = array_slice( $row, 1 ); 
			}
			else if( 'lookup_column_4' == $format ) {
				$output[$row[3]] = array_slice( $row, 0, 3 ); 
			}
			else if( 'max_datetime_3' == $format ) {
				if( empty( $output['max'] ) ) $output['max'] = 0;
				if( strtotime( $row[2] ) > $output['max'] ) $output['max'] = strtotime( $row[2] );
			}
			// default case: simple lookup list
			else {
				$output[] = $row[0];
			}

		}

		// close
		fclose($handle);

		return $output;

	}

	private function load_from_txt( $path ) {

		if( ! is_file( $path ) ) {
			WP_CLI::error( 'Could not find file at path: ' . $path );
		}

		return array_map( 'trim', file( $path ) );
		
	}
}
