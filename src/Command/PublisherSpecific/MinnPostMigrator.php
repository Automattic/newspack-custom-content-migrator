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
		);

		WP_CLI::add_command(
			'newspack-content-migrator minnpost-set-featured-images',
			[ $this, 'cmd_set_featured_images' ],
		);

	}

	public function cmd_set_authors_by_subtitle_byline( $pos_args, $assoc_args ) {

		global $wpdb;

		$log_file = 'minnpost_set_authors_by_subtitle_byline.txt';
		$byline_meta_key = '_mp_subtitle_settings_byline';
		$result_meta_key = 'newspack_minnpost_subtitle_byline_result';
		$allowed_wp_user_roles = array( 'administrator', 'editor', 'author', 'contributor', 'staff' );

		$this->logger->log( $log_file, 'Setting authors by subtitle byline.' );
		
		// select posts with byline subtitle meta
		$query = new WP_Query ( [
			'posts_per_page' => 1,
			// 'p'			=> 78790, // multiple authors without match
			// 'p' 			=> 38325, // single author with match
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => $byline_meta_key,
					'compare' => 'EXISTS',
				],
				[
					'key'     => $result_meta_key,
					'compare' => 'NOT EXISTS',
				],
			]
		]);

		$this->logger->log( $log_file, 'Post count: ' . $query->post_count  );

		foreach ( $query->posts as $post_id ) {
			
			$this->logger->log( $log_file, '-- Post ID: ' . $post_id  );

			$byline = get_post_meta( $post_id, $byline_meta_key, true );
			$this->logger->log( $log_file, 'Byline (raw): ' . $byline  );

			// clean byline
			$byline = preg_replace( '/^By\s{1}/', '', $byline );
			$this->logger->log( $log_file, 'Byline (cleaned): ' . $byline  );

			// check if author already exists
			$exists = ( function () use ( $post_id, $byline, $log_file ) {
				foreach( $this->coauthorsplus->get_all_authors_for_post( $post_id ) as $author ) {
					$this->logger->log( $log_file, 'Author type: ' . get_class( $author ) );
					$this->logger->log( $log_file, 'Author display name: ' . $author->display_name );
					if( $byline === $author->display_name ) return true;
				}
			} )(); // call function

			if( $exists ) {
				$this->logger->log( $log_file, 'Author already exists on post.', $this->logger::SUCCESS );
				update_post_meta( $post_id, $result_meta_key, 'aleady_exists_on_post' );
				continue;
			}

			// check if an there is an existing GA by display name
			$guest_author = $this->coauthorsplus->get_guest_author_by_display_name( $byline );

			// multiple GAs found?
			if( is_array( $guest_author ) && count( $guest_author ) > 1 ) {
				$this->logger->log( $log_file, 'Mutliple GAs were found for display name?', $this->logger::WARNING );
				continue;
			}

			// if single object (not array), then assign to post
			if( is_object( $guest_author ) ) {

				$this->coauthorsplus->assign_guest_authors_to_post( array( $guest_author->ID ), $post_id );
				update_post_meta( $post_id, $result_meta_key, 'assigned_existing_guest_author' );

				$this->logger->log( $log_file, 'Assigned existing guest author to post.', $this->logger::SUCCESS );
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
				$this->logger->log( $log_file, 'Mutliple WP_Users were found for display name?', $this->logger::WARNING );
				continue;
			} 

			// if single user, then set as author
			if( 1 === $wp_user_query->get_total() ) {

				$wp_user = $wp_user_query->get_results()[0]; 

				// sanity check incase wp_user_query "search" wasn't exact match, perform exact match here
				if( 0 !== strcmp( $wp_user->display_name, $byline ) ) {
					$this->logger->log( $log_file, 'Found WP_User not exact display name?', $this->logger::WARNING );
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
				exit();
				
	
			}

			// create a guest author and assign to post?


		} // foreach

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
