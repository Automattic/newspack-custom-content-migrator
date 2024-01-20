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
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator minnpost-set-featured-images',
			[ $this, 'cmd_set_featured_images' ],
		);

	}

	public function cmd_set_authors_by_subtitle_byline( $pos_args, $assoc_args ) {

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
		global $wpdb;

		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '%d (%d/%d)', $post_id, $key_post_id + 1, count( $post_ids ) ) );

			// Get and validate current thumb ID.
			$thumb_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_mp_post_thumbnail_image_id'", $post_id ) );
			if ( ! $thumb_id ) {
				continue;
			}
			$valid_thumb_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d and post_type = 'attachment'", $thumb_id ) );
			if ( ! $valid_thumb_id ) {
				$this->logger->log( 'minnpost_err.txt', sprintf( 'Invalid _mp_post_thumbnail_image_id %d for post %d', $thumb_id, $post_id ), $this->logger::WARNING );
				continue;
			}

			// Set thumb ID as featured image.
			$thumb_exists = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d and meta_key = '_thumbnail_id'", $post_id ) );
			if ( $thumb_exists ) {
				$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $thumb_id ], [ 'post_id' => $post_id, 'meta_key' => '_thumbnail_id' ] );
			} else {
				$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $post_id, 'meta_key' => '_thumbnail_id', 'meta_value' => $thumb_id ] );
			}

			$this->logger->log( 'minnpost_success.txt', sprintf( 'post_id %d thumb_id %d', $post_id, $thumb_id ), $this->logger::SUCCESS );
		}

		wp_cache_flush();
	}

	private function set_authors_by_subtitle_byline( $log_file, $result_types, $report_add, $allowed_wp_users ) {

		global $wpdb;

		// meta keys for main query and reporting		
		$meta_key_byline = '_mp_subtitle_settings_byline';
		$meta_key_result = 'newspack_minnpost_subtitle_byline_result';

		// start
		$this->logger->log( $log_file, '---- New batch.' );

		// select posts with byline subtitle meta, and not already processed
		$query = new WP_Query ( [
			// 'p' => 2069483, // test: Stephanie Hemphill (contains "+" in CAP GA email/user-login)
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

			// remove starting "By "
			$byline = preg_replace( '/^By\s+/', '', $byline );
			// trim and replace multiple spaces with single space
			$byline = preg_replace( '/\s{2,}/', ' ', trim( $byline ) );

			$skip_for_now = false;

			// skip for now: commas, "and", &, etc.
			if( preg_match( '/( and )|&|,/', $byline ) ) $skip_for_now = true;

			// skip for now: Stephanie Hemphill (bug in CAP plugin is failing on asisgning to post due to "+" in email (?))
			if( preg_match( '/Stephanie Hemphill/', $byline ) ) $skip_for_now = true;
			
			// skip for now: if it's not a normal firstname lastname
			if( ! preg_match( '/^([A-Za-z]+) ([A-Za-z]+)$/', $byline, $name_parts ) ) $skip_for_now = true;
			
			if( $skip_for_now ) {
				update_post_meta( $post_id, $meta_key_result, $result_types->skipping_non_first_last );
				$this->logger->log( $log_file, $result_types->skipping_non_first_last, $this->logger::WARNING );
				$report_add( $result_types->skipping_non_first_last );
				continue;
			}

			// cleaned byline
			$this->logger->log( $log_file, 'Byline (cleaned): ' . $byline  );

			$report_add('normal name');

			// check if author already exists on this post
			$exists = ( function () use ( $post_id, $byline, $name_parts ) {
				foreach( $this->coauthorsplus->get_all_authors_for_post( $post_id ) as $author ) {
					// exact match return yes
					if( $byline === $author->display_name ) return 'yes';
					// not exact, but byline is within display name?  "Beth Smith": "Beth Smith, Phd" or "Beth J. Smith"
					if( false !== strpos( $author->display_name, $name_parts[1] ) 
						&& false !== strpos( $author->display_name, $name_parts[2] ) 
					) return 'maybe';
				}
			})(); // run function

			if( 'yes' === $exists ) {
				update_post_meta( $post_id, $meta_key_result, $result_types->already_exists_on_post );
				$this->logger->log( $log_file, $result_types->already_exists_on_post, $this->logger::SUCCESS );
				$report_add( $result_types->already_exists_on_post );
				continue;
			}

			if( 'maybe' === $exists ) {
				update_post_meta( $post_id, $meta_key_result, $result_types->maybe_exists_on_post );
				$this->logger->log( $log_file, $result_types->maybe_exists_on_post, $this->logger::WARNING );
				$report_add( $result_types->maybe_exists_on_post );
				continue;
			}

			// check if an there is an existing GA by display name
			$ga_exists = ( function() use( $wpdb, $byline, $name_parts ) {

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

				if( ! ( count( $gas ) > 0 ) ) return 'no';

				// if an exact match was in the query
				foreach( $gas as $ga ) {
					if( $ga->display_name === $byline ) return (int) $ga->ID;
				}

				// something was returned from the query, just not an exact match
				return 'maybe';

			})(); // run function

			if( 'maybe' === $ga_exists ) {
				update_post_meta( $post_id, $meta_key_result, $result_types->maybe_ga_exists );
				$this->logger->log( $log_file, $result_types->maybe_ga_exists, $this->logger::WARNING );
				$report_add( $result_types->maybe_ga_exists );
				continue;
			}

			// if ga ID, then assign to post
			if( is_int( $ga_exists ) && $ga_exists > 0 ) {
				$this->coauthorsplus->assign_guest_authors_to_post( array( $ga_exists ), $post_id );
				update_post_meta( $post_id, $meta_key_result, $result_types->assigned_to_existing_ga );
				$this->logger->log( $log_file, $result_types->assigned_to_existing_ga, $this->logger::SUCCESS );
				$report_add( $result_types->assigned_to_existing_ga );
				continue;
			}

			// get user ID by exact match
			// doing "maybe" matches at this point does not make sense because all allowed_users at this time are only "First Last"
			// see print_r( $allowed_wp_users );
			$wp_user_exists = array_search( $byline, $allowed_wp_users );
			if( is_int( $wp_user_exists ) && $wp_user_exists > 0 ) {
				$this->coauthorsplus->assign_authors_to_post( array( get_user_by('id', $wp_user_exists ) ), $post_id );
				update_post_meta( $post_id, $meta_key_result, $result_types->assigned_to_existing_wp_user );
				$this->logger->log( $log_file, $result_types->assigned_to_existing_wp_user, $this->logger::SUCCESS );
				$report_add( $result_types->assigned_to_existing_wp_user );
				continue;
			}

			// create a guest author and assign to post
			$coauthor_id = $this->coauthorsplus->create_guest_author( array( 'display_name' => $byline ) );
			$this->coauthorsplus->assign_guest_authors_to_post( array( $coauthor_id ), $post_id );
			update_post_meta( $post_id, $meta_key_result, $result_types->assigned_to_new_ga );
			$this->logger->log( $log_file, $result_types->assigned_to_new_ga, $this->logger::SUCCESS );
			$report_add( $result_types->assigned_to_new_ga );

		} // foreach

		wp_cache_flush();

		return true;

	}

}
