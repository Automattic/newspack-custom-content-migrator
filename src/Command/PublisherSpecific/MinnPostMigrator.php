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
			'newspack-content-migrator minnpost-set-authors-by-subtitle-byline',
			[ $this, 'cmd_set_authors_by_subtitle_byline' ],
			[
				'shortdesc' => 'Convert old post meta to CAP GAs (or matching WP User) and assign to Post.',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'test-regex',
						'description' => 'If used, all bylines from postmeta will be run though regex. (No db updates).',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator minnpost-set-featured-images',
			[ $this, 'cmd_set_featured_images' ],
		);

	}

	public function cmd_set_authors_by_subtitle_byline( $pos_args, $assoc_args ) {

		// test only if set
		if( isset( $assoc_args['test-regex']) ) {
			return $this->byline_test_regex();
		}

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

	private function byline_regex( $byline ) {

		// convert utf8 spaces to normal spaces
		$byline = trim( str_replace("\xc2\xa0", "\x20", $byline) );

		// trim and replace multiple spaces with single space
		$byline = trim( preg_replace( '/\s{2,}/', ' ', $byline ) );

		// remove starting "By "
		$byline = trim( preg_replace( '/^By\s+/', '', $byline ) );

		// skip for now: 
		// Stephanie Hemphill (bug in CAP plugin is failing on asisgning to post due to "+" in email (?))
		// Abdulrahman Bindamnan - utf8 character
		if( preg_match( '/(Stephanie Hemphill|Abdulrahman Bindamnan)/', $byline ) ) return null;

		// skip for now: anything with ";" (6 rows) - do by hand
		if( preg_match( '/;/', $byline ) ) return null;

		// assess "and", &
		if( preg_match( '/ and |&/', $byline ) ) {

			// todo:
			// capture no comma with and then a comma
			// if( preg_match( '/^([^,]+) and (.*?),(.*?)$/', $byline, $matches ) ) {

			// todo:
			// capture comma and then a comma
			// if( preg_match( '/^(.*?),(.*?) and (.*?),(.*?)$/', $byline, $matches ) ) {

			return array_map( 'trim', preg_split( '/, and | and | & |,/', $byline ) );

		}

		// assess commas now that "and" have been captured above
		if( preg_match( '/^([^,]+),(.*)/', $byline, $comma_parts ) ) {
			return array( $comma_parts[1] );
		}

		return array( $byline );

	}

	private function byline_test_regex(  ) {

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

		foreach ( $results as $result ) {

			// set short var name for less confusion
			$byline = $result->byline;

			// setup CSV output line
			$csv_line = array( 
				$result->post_count,
				$byline
			);

			// test regex for resulting byline(s) ( "and" cases product multiple ) ( single byline will be an array of one array)
			$bylines = $this->byline_regex( $byline );

			// if no match this is an error byline, set match count to 0
			if( empty( $bylines ) ) {
				
				$csv_line[] = 0;
				$csv_line[] = 'ERROR_NO_MATCHES';
				
				// set all these posts as failed
				$report['posts_failed'] += $result->post_count;

			}
			// matches exist
			else {
				
				// store match count
				$csv_line[] = count( $bylines );
				
				// and each match byline
				array_push( $csv_line, ...$bylines );

				// set all these posts as fixed
				$report['posts_fixed'] += $result->post_count;

			}
			
			// write output to csv
			\WP_CLI\Utils\write_csv( $csv_out_file, array( $csv_line ) ); // array within array

		} // foreach

		print_r( $report );
		
		WP_CLI::success( 'Done' );

	}


}
