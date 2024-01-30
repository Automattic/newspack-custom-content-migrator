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
						'name'        => 'csv-names',
						'description' => 'Known names CSV file. Relative to uploads folder: --csv-file="2024/01/names.csv"',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'csv-suffixes',
						'description' => 'Known suffixes CSV file. Relative to uploads folder: --csv-file="2024/01/suffixes.csv"',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'test-regex',
						'description' => 'If used, all bylines from postmeta will be run though regex. (No db updates).',
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
		$this->byline_known_names = $this->load_from_csv( wp_upload_dir()['basedir'] . '/' . $assoc_args[ 'csv-names' ] );
		if( empty( $this->byline_known_names ) ) {
			WP_CLI::error( 'CSV Names is empty.' );
		}

		// load csv file		
		$this->byline_known_suffixes = $this->load_from_csv( wp_upload_dir()['basedir'] . '/' . $assoc_args[ 'csv-suffixes' ] );
		if( empty( $this->byline_known_suffixes ) ) {
			WP_CLI::error( 'CSV Suffixes is empty.' );
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
		$result_types->already_exists_on_post = 'already_exists_on_post';
		$result_types->assigned_to_existing_ga = 'assigned_to_existing_ga';
		$result_types->assigned_to_existing_wp_user = 'assigned_to_existing_wp_user';
		$result_types->assigned_to_new_ga = 'assigned_to_new_ga';
		$result_types->maybe_exists_on_post = 'maybe_exists_on_post';
		$result_types->maybe_ga_exists = 'maybe_ga_exists';
		$result_types->skipping_non_first_last = 'skipping_non_first_last';

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
			// 'p' => 2069483, // test: cap author contains "+" in CAP GA email/user-login
			// 'p' => 32178, // test: single word byline
			// 'p' => 30933, // test: first last 
			// 'p' => 95265, // test: comma
			// 'p' => 95332, // test 1, 2 and 3
			// 'p' => 94141, // test fist last, publication, and first last, publication
			'posts_per_page' => 100,
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
			$byline = get_post_meta( $post_id, $meta_key_byline, true );
			$this->logger->log( $log_file, 'Byline (raw): ' . $byline  );

			// store all sets that need to be run into an array, this will make it easier for "and" authors
			$name_sets = [];

			// skip for now, unless regex is favorable
			$skip_for_now = true;

			// convert utf8 spaces to normal spaces
			$byline = trim( str_replace("\xc2\xa0", "\x20", $byline) );

			// trim and replace multiple spaces with single space
			$byline = trim( preg_replace( '/\s{2,}/', ' ', $byline ) );

			// remove starting "By "
			$byline = trim( preg_replace( '/^By\s+/', '', $byline ) );

			// // skip for now: Stephanie Hemphill (bug in CAP plugin is failing on asisgning to post due to "+" in email (?))
			if( preg_match( '/Stephanie Hemphill/', $byline ) ) $skip_for_now = true;

			// assess "and", &
			else if( preg_match( '/ and |,and |&/', $byline ) ) {

				$skip_for_now = false;
				foreach( preg_split( '/,| and |&/', $byline ) as $temp_name ) {
					$temp_name = trim( $temp_name );
					if( preg_match( '/^([A-Za-z]+) ([A-Za-z]+)$/', $temp_name, $name_parts ) ) {
						$name_sets[] = array( 'byline' => $temp_name, 'name_parts' => $name_parts);
					}
					// if any match isn't perfect, don't run for now
					else {
						$skip_for_now = true;
						break;
					}
				}				
			}
			
			// assess commas
			else if( preg_match( '/^([^,]+),(.*)/', $byline, $comma_parts ) ) {
				$this->logger->log( 'minnpost_set_authors_by_subtitle_byline_comma_parts.txt', implode( "\t", $comma_parts ) );
				if( preg_match( '/^([A-Za-z]+) ([A-Za-z]+)$/', $comma_parts[1], $name_parts ) ) {
					$skip_for_now = false;
					$name_sets[] = array( 'byline' => $comma_parts[1], 'name_parts' => $name_parts);
				}
			}

			// keep: test if it's a single word byline with no spaces
			else if( false === strpos( $byline, ' ') ) {
				$skip_for_now = false;
				$name_sets[] = array( 'byline' => $byline, 'name_parts' => array( '', $byline, '' ) );
			}
			
			//  if it a normal firstname lastname
			else if( preg_match( '/^([A-Za-z]+) ([A-Za-z]+)$/', $byline, $name_parts ) ) {
				$skip_for_now = false;
				$name_sets[] = array( 'byline' => $byline, 'name_parts' => $name_parts);
			}
			
			if( $skip_for_now ) {
				update_post_meta( $post_id, $meta_key_result, $result_types->skipping_non_first_last );
				$this->logger->log( $log_file, $result_types->skipping_non_first_last );
				$report_add( $result_types->skipping_non_first_last );
				continue;
			}

			// cleaned byline
			$this->logger->log( $log_file, 'Byline (cleaned): ' . $byline  );

			// loop through each name set with an $i counter
			for( $i = 0; $i < count( $name_sets ); $i++ ) {

				// set the first author to clear the existing authors
				if( 0 == $i) $append_to_existing_users = false;
				// append additional authors $i > 0 to the post
				else $append_to_existing_users = true;

				// set author using first last
				$result_type = $this->set_authors_by_subtitle_byline_first_last( 
					$log_file, $result_types, $report_add, $allowed_wp_users,
					$meta_key_result,
					$post_id, $name_sets[$i]['byline'], $name_sets[$i]['name_parts'],
					$append_to_existing_users
				);
	
				// allow multiple post metas incase multiple authors per post_id
				add_post_meta( $post_id, $meta_key_result, $result_type );
				$this->logger->log( $log_file, $result_type );
				$report_add( $result_type );

			} // for loop
			
		} // foreach

		return true;

	}

	private function set_authors_by_subtitle_byline_first_last(		
		$log_file, $result_types, $report_add, $allowed_wp_users,
		$meta_key_result,
		$post_id, $byline, $name_parts,
		$append_to_existing_users
	) {

		// check if author already exists on this post
		$exists = ( function () use ( $post_id, $byline, $name_parts ) {
			foreach( $this->coauthorsplus->get_all_authors_for_post( $post_id ) as $author ) {
				$exists = $this->yes_or_maybe_byline_match( $byline, $author->display_name, $name_parts );
				if( ! empty( $exists ) ) return $exists;
			}
			return null;
		})(); // run function

		if( 'yes' === $exists ) {
			return $result_types->already_exists_on_post;
		}

		if( 'maybe' === $exists ) {
			return $result_types->maybe_exists_on_post;
		}

		// check if an there is an existing GA by display name
		$ga_exists = ( function() use( $byline, $name_parts ) {
			
			global $wpdb;

			$gas = $wpdb->get_results( $wpdb->prepare("
				SELECT ID, post_title as display_name
				FROM {$wpdb->posts}
				WHERE post_type = 'guest-author'
				AND post_title LIKE %s
				AND post_title LIKE %s
				",  
				'%' . $wpdb->esc_like( $name_parts[1] ) . '%',
				'%' . $wpdb->esc_like( $name_parts[2] ) . '%',
			));

			if( ! ( count( $gas ) > 0 ) ) return null;

			// if an exact match was in the query
			foreach( $gas as $ga ) {
				if( $ga->display_name === $byline ) return (int) $ga->ID;
			}

			// something was returned from the query, just not an exact match
			return 'maybe';

		})(); // run function

		if( 'maybe' === $ga_exists ) {
			return $result_types->maybe_ga_exists;
		}

		// if ga ID, then assign to post
		if( is_int( $ga_exists ) && $ga_exists > 0 ) {
			$this->coauthorsplus->assign_guest_authors_to_post( array( $ga_exists ), $post_id, $append_to_existing_users );
			return $result_types->assigned_to_existing_ga;
		}

		// get user ID by exact match
		// doing "maybe" matches at this point does not make sense because all allowed_users at this time are only "First Last"
		// see print_r( $allowed_wp_users );
		$wp_user_exists = array_search( $byline, $allowed_wp_users );
		if( is_int( $wp_user_exists ) && $wp_user_exists > 0 ) {
			$this->coauthorsplus->assign_authors_to_post( array( get_user_by('id', $wp_user_exists ) ), $post_id, $append_to_existing_users );
			return $result_types->assigned_to_existing_wp_user;
		}

		// create a guest author and assign to post
		$coauthor_id = $this->coauthorsplus->create_guest_author( array( 'display_name' => $byline ) );
		$this->coauthorsplus->assign_guest_authors_to_post( array( $coauthor_id ), $post_id, $append_to_existing_users );
		return $result_types->assigned_to_new_ga;

	}

	private function yes_or_maybe_byline_match( $byline, $display_name, $name_parts ) {

		// exact match return yes
		if( $byline === $display_name ) return 'yes';

		// maybe byline (name parts) are within display name?  "Beth Smith": "Beth Smith, Phd" or "Beth J. Smith"

		// check first name (or single name for single word byline)
		$maybe = ( false !== strpos( $display_name, $name_parts[1] ) );
		
		// if first name was match, check the second name (assuming it's not a one word byline)
		if( $maybe && ! empty( $name_parts[2]) ) $maybe = ( false !== strpos( $display_name, $name_parts[2] ) );
		
		// if maybe is true, return
		if( $maybe ) return 'maybe';

		return null;
		
	}

	// return: string|array|null
	private function byline_regex( $byline, $error_on_unknown_char = false ) {

		// -- REPLACEMENTS ---------------------------------------------------------------

		// remove/replace hidden chars
		$byline = $this->byline_replace_hidden_chars( $byline );

		// if error mode true, test for new hidden/unknown chars
		if( $error_on_unknown_char ) $this->byline_error_if_unknown_chars( $byline );

		// remove starting "By" with any spaces (case insensitive)
		$byline = trim( preg_replace( '/^By[:]?\s+/i', '', $byline ) );

		// remove other "by" in text
		$byline = trim( preg_replace( '/\b(Analysis|Commentary|Designed|Editorial|Embedded|Interview|Satire|Text|Videos)\s+by\b/i', '', $byline ) );

		// remove string match job titles
		// $byline = trim( preg_replace( '/\bLibrarian with Dakota County Library\b/i', '', $byline ) );
		// $byline = trim( preg_replace( '/\bStaff writer\b/i', '', $byline ) );

		// remove comma on Jr/Sr at end of byline (todo: fix for "and" cases?)
		// $byline = trim( preg_replace( '/, (Jr|Sr)[\.]$/i', " $1", $byline ) );

		// standardize commas with one space
		$byline = trim( preg_replace( '/\s*,\s*/', ', ', $byline ) );

		// trim and replace multiple spaces with single space
		$byline = trim( preg_replace( '/\s{2,}/', ' ', $byline ) );

		// -- MATCHES ----------------------------------------------------------------------

		// skip for now: Stephanie Hemphill (bug in CAP plugin is failing on asisgning to post due to "+" in email (?))
		// if( preg_match( '/Stephanie Hemphill/i', $byline ) ) return null;
		// if( preg_match( '/Hugh Macleod and a reporter in Syria/i', $byline ) ) return null;
		
		// skip for now: anything with "|", ";", "by" (not already stripped)
		// if( preg_match( '/([\|]|;|\bby\b)/i', $byline ) ) return null;

		// parse "and" / "with" case
		if( preg_match( '/\band\b|&|\bwith\b/i', $byline ) ) {

			return $this->bylines_do_and_case( $byline );
		}

		return 'bypass';



		// parse commas now that "and with commas" above have been captured
		if( preg_match( '/,/', $byline ) ) {
			
			$splits = array_map( 'trim', preg_split( '/,/', $byline, -1, PREG_SPLIT_NO_EMPTY ) );

			// name, publication/suffix:
			if( 2 == count( $splits ) ) {
				
				// special cases
				if( preg_match( '/^becky Bock, Carlos$/i', $byline ) ) return null;

				// if it's a suffix to remove
				if( $this->byline_is_suffix_to_trim( $splits[1] ) ) {
					return $splits[0];
				}
				
			}

			// name, publication/suffix:
			if( 3 == count( $splits ) ) {
				
				// if it's a multiple value suffix to remove
				if( $this->byline_is_suffix_to_trim( $splits[1] . ', ' . $splits[2] ) ) {
					return $splits[0];
				}

				// if both should be removed
				if( $this->byline_is_suffix_to_trim( $splits[1] ) &&  $this->byline_is_suffix_to_trim( $splits[2] ) ) {
					return $splits[0];
				}

			}

			// remaining cases
			$splits_count = count( $splits );
			for( $i=0; $i < $splits_count; $i++ ) {
				
				// remove any publications, suffixes, etc
				if( $this->byline_is_suffix_to_trim( $splits[$i] ) ) {
					unset( $splits[$i] );
					continue;
				}

				// if not in allowed names, save for review and return null
				if( ! in_array( $splits[$i], $this->byline_known_names ) ) {
					return null;
				}

			}

			// return clean and ordered names
			return array_values( $splits );

		}

		// normal byline
		return $byline;

	}

	private function byline_test_regex() {

		$csv_out_file = fopen( 'minnpost_byline_test_regex_report.csv', 'w' );

		$report = [
			'posts_fixed' => 0,
			'posts_failed' => 0,
		];

		global $wpdb;

		// get bylines group by number of posts byline affects
		$results = $wpdb->get_results("
			select meta_value as byline, count(*) as post_count
			from {$wpdb->postmeta}
			where meta_key = '_mp_subtitle_settings_byline'
			group by meta_value
			order by post_count desc
		");

		$max = 0;

		foreach ( $results as $result ) {

			// set short var name for less confusion
			$byline = $result->byline;

			// setup CSV output line
			$csv_line = array( 
				$result->post_count,
				$byline
			);

			// string|array|null
			$bylines = $this->byline_regex( $byline, true );

			if( 'bypass' == $bylines ) continue;

			// if no match this is an error byline, set match count to 0
			if( empty( $bylines ) ) {
				
				$this->logger->log( 'minnpost_regex_warnings.txt', $byline, $this->logger::WARNING );

				$csv_line[] = 0;
				$csv_line[] = 'ERROR_NO_MATCHES';
				
				// set all these posts as failed
				$report['posts_failed'] += $result->post_count;

			}
			// matches exist
			else {
				
				if( ! is_array( $bylines ) ) $bylines = array( $bylines );

				// store match count
				$csv_line[] = count( $bylines );
				
				// and each match byline
				array_push( $csv_line, ...$bylines );

				// set all these posts as fixed
				$report['posts_fixed'] += $result->post_count;

			}
			
			// write output to csv
			\WP_CLI\Utils\write_csv( $csv_out_file, array( $csv_line ) ); // array within array

			
			$max++;
			
			// if( $max >= 4319 ) return;

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

	private function bylines_do_and_case( $byline ) {

		// split into elements
		$splits = array_map( 'trim', preg_split( '/\band\b|&|\bwith\b|,/i', $byline, -1, PREG_SPLIT_NO_EMPTY ) );
				
		// first person only has first name
		if( false === strpos( $splits[0], ' ' ) ) {
			
			if( $byline == 'Friends and colleagues of Babak Armajani' ) return $byline;
			if( $byline == 'MinnPost and Minneapolis Voices' ) return array( 'MinnPost', 'Minneapolis Voices' );

			// try to remove suffix
			if( 3 == count( $splits ) && $this->byline_is_suffix( $splits[2] ) ) {
				unset( $splits[2] );
			}

			// check both names
			if( 2 == count( $splits ) 
				&& $this->byline_is_name_ok( $splits[0] . preg_replace( '/^.*?\s/', ' ', $splits[1] ) )
				&& $this->byline_is_name_ok( $splits[1] )
			){
				return $splits;
			}

			return null;
		}

		// if 3 values and last one is suffix
		if( 3 == count( $splits ) && $this->byline_is_suffix( $splits[2] ) 
				&& $this->byline_is_name_ok( $splits[0] )
				&& $this->byline_is_name_ok( $splits[1] )
			) {
				return $splits;
			} else return null;
		

		if( 2 == count( $splits ) 
				&& $this->byline_is_name_ok( $splits[0] )
				&& $this->byline_is_name_ok( $splits[1] )
		) {
			return $splits;
		} else return null;
		


		return null;
		return 'bypass';

		// check names
		$found = 0;
		foreach( $splits as $split ) {
			if( in_array( $split, $this->byline_known_names ) 
				&& in_array( $split, $this->byline_known_suffixes ) ) $found++;
		}
		if( $found == count( $splits ) ) return $splits;
		

		return null;
		return 'bypass';

		// special cases
		// if( preg_match( '/^(Laurie|Joel) and (Laurie|Joel) Kramer$/i', $byline ) ) return array( 'Laurie Kramer', 'Joel Kramer' );



	/*
		By Jenny Gold and Kate Steadman, Kaiser Health News
		By Mike Faulk and and Sara Miller Llana
		By Lindsey Dyer, Librarian with Dakota County Library

		By Phil Schewe and Devin Powell, Inside Science News Service

		By Mike Lucibella and Eric Betz, Inside Science News Service

		By Shashank Bengali and Mohammed al Dulaimy, McClatchy Newspapers
		By Jonathan S. Landay and Saeed Shah, McClatchy newspapers
		By Sebastian Jones and Marcus Stern, ProPublica
		By Shashank Bengali and Mohammed El Dulaimy, McClatchy Newspapers

		By Bianca Virnig and five others


		By Jesse Eisinger and Paul Kiel, ProPublica
	*/

		// todo:
		// capture no comma with and then a comma
		// if( preg_match( '/^([^,]+) and (.*?),(.*?)$/', $byline, $matches ) ) {

		// todo:
		// capture comma and then a comma
		// if( preg_match( '/^(.*?),(.*?) and (.*?),(.*?)$/', $byline, $matches ) ) {


		return $splits;

	}

	private function byline_is_name_ok( $str ) {

		if( in_array( $str, $this->byline_known_names ) 
			&& ! in_array( $str, $this->byline_known_suffixes ) 
		) {
			return true;
		}

		return false;
	}


	private function byline_is_suffix( $str ) {

		if( in_array( $str, $this->byline_known_suffixes ) ) {
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

}
