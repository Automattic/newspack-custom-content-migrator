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
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Run the code without making any changes to the database.',
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

	}

	public function cmd_set_authors_by_subtitle_byline( $pos_args, $assoc_args ) {

		global $wpdb;

		$dry_run = ( isset( $assoc_args['dry-run'] ) );
		$log_file = 'minnpost_set_authors_by_subtitle_byline.txt';
		
		$meta_key_byline = '_mp_subtitle_settings_byline';
		$meta_key_result = 'newspack_minnpost_subtitle_byline_result';
		
		$allowed_wp_user_roles = array( 'administrator', 'editor', 'author', 'contributor', 'staff' );

		$result_types = new stdClass();
		$result_types->already_exists_on_post = 'already_exists_on_post';
		$result_types->assigned_existing_ga_to_post = 'assigned_existing_ga_to_post';

		$report = array();
		$report_add = function( $key ) use( &$report ) {
			if( empty( $report[$key] ) ) $report[$key] = 0;
			$report[$key]++;
		};

		// start
		$this->logger->log( $log_file, 'Setting authors by subtitle byline.' );

		// select posts with byline subtitle meta, and not already processed
		$query = new WP_Query ( [
			'posts_per_page' => -1,
			// 'p'			=> 78790, // multiple authors without match
			// 'p' 			=> 38325, // single author with match
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

		foreach ( $query->posts as $post_id ) {
			
			$this->logger->log( $log_file, '-- Post ID: ' . $post_id  );

			// get byline
			$byline = get_post_meta( $post_id, $meta_key_byline, true );
			$this->logger->log( $log_file, 'Byline (raw): ' . $byline  );

			// remove starting "By "
			$byline = preg_replace( '/^By\s+/', '', $byline );
			$byline = trim( $byline );

			// skip for now: commas, "and", &, etc.
			$byline_arr = preg_split( '/(,|&|and)/', $byline, -1, PREG_SPLIT_DELIM_CAPTURE );
			if( 1 !== count( $byline_arr ) ) {
				$this->logger->log( $log_file, 'Skipping difficult bylines for now.', $this->logger::WARNING );
				$report_add('skipping difficult');
				continue;
			}

			// skip for now: if it's not normal firstname lastname
			if( ! preg_match( '/^[A-Za-z]+ [A-Za-z]+$/', $byline ) ) {
				$this->logger->log( $log_file, 'Skipping non first last for now.', $this->logger::WARNING );
				$report_add('skipping non first last for now');
				continue;
			}

			// cleaned byline
			$this->logger->log( $log_file, 'Byline (cleaned): ' . $byline  );

			$report_add('normal name');

			// check if author already exists on this post
			$exists = ( function () use ( $post_id, $byline, $log_file ) {
				foreach( $this->coauthorsplus->get_all_authors_for_post( $post_id ) as $author ) {
					// $this->logger->log( $log_file, 'Author type: ' . get_class( $author ) );
					// $this->logger->log( $log_file, 'Author display name: ' . $author->display_name );
					if( $byline === $author->display_name ) return true;
				}
			} )(); // call function

//todo: what if existing author on post is already "Beth Smith, Phd" but the byline is "Beth Smith"?

			if( $exists ) {
				$this->logger->log( $log_file, 'Author already exists on post.', $this->logger::SUCCESS );
				if( ! $dry_run ) {
					update_post_meta( $post_id, $meta_key_result, $result_types->already_exists_on_post );
				}
				$report_add('author already exists on post');
				continue;
			}

			// check if an there is an existing GA by display name
			$guest_author = $this->coauthorsplus->get_guest_author_by_display_name( $byline );

			// multiple GAs found?
			if( is_array( $guest_author ) && count( $guest_author ) > 1 ) {
				$this->logger->log( $log_file, 'Mutliple GAs were found for display name?', $this->logger::WARNING );
				$report_add('multiple gas');
				continue;
			}

			// if single object (not array), then assign to post
			if( is_object( $guest_author ) ) {
				$this->logger->log( $log_file, 'Assigned existing GA to post.', $this->logger::SUCCESS );
				if( ! $dry_run ) {
					$this->coauthorsplus->assign_guest_authors_to_post( array( $guest_author->ID ), $post_id );
					update_post_meta( $post_id, $meta_key_result, $result_types->assigned_existing_ga_to_post );
				}
				$report_add('assigned existing ga to post');
				continue;
			}

			// attempt to assign to a wp user
			$wp_user_query = new WP_User_Query([
				'role__in' => $allowed_wp_user_roles,
				'search' => $byline,
				'search_columns' => array( 'display_name' ),
			]);
			
			// multiple matching wp_users?
			if( $wp_user_query->get_total() > 1 ) {
				$this->logger->log( $log_file, 'Mutliple WP_Users matched display name?', $this->logger::WARNING );
				$report_add('mutliple wp users');
				continue;
			} 

			// if single user, then set as author
			if( 1 === $wp_user_query->get_total() ) {

				$wp_user = $wp_user_query->get_results()[0]; 

				// sanity check incase wp_user_query "search" wasn't exact match, perform exact match here
				if( 0 !== strcmp( $wp_user->display_name, $byline ) ) {
					$this->logger->log( $log_file, 'Found WP_User not exact match display name?', $this->logger::WARNING );
					$report_add('found wp_user does not match name');
					continue;	
				}

				echo "HERE: need to test user roles.";
				print_r( $wp_user );
				exit();

				// sanity check just to abolutely make sure user is in an allowed role
				// if(  ) {
				// 	$this->logger->log( $log_file, 'Found WP_User not exact display name?', $this->logger::WARNING );
				// 	continue;	
				// }

				echo "need to assign wp user to post";
				echo "is ! dry run";
				exit();
				
	
			}

			echo "are you sure the wp user didn't return any results?????";

			// create a guest author and assign to post
			$this->logger->log( $log_file, 'create a guest author and assign to post' );
			$report_add('todo: create guest ga and assign');

		} // foreach

		print_r($report);

		wp_cache_flush();

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

}
