<?php
/**
 * Newspack Custom Content Migrator: Ghost CMS Migrator.
 * 
 * Commands related to migrating Ghost CMS.
 * 
 * @link: https://ghost.org/
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Ghost CMS.
 */
class GhostCMSMigrator implements InterfaceCommand {

	/**
	 * Lookup to convert json authors to wp objects (WP Users and/or CAP GAs).
	 * 
	 * Note: json author_id key may exist, but if json author (user) visibility was not public, value will be 0
	 *
	 * @var array $authors_to_wp_objects
	 */
	private $authors_to_wp_objects;

	/**
	 * CoAuthorPlusLogic
	 * 
	 * @var CoAuthorPlusLogic 
	 */
	private $coauthorsplus_logic;

	/**
	 * Instance
	 * 
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * JSON from file
	 *
	 * @var object $json
	 */
	private $json;

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
	 * RedirectionLogic
	 * 
	 * @var RedirectionLogic 
	 */
	private $redirection_logic;

	/**
	 * Lookup to convert json tags to wp categories.
	 * 
	 * Note: json tag_id key may exist, but if tag visibility was not public, value will be 0
	 *
	 * @var array $tags_to_categories
	 */
	private $tags_to_categories;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->logger              = new Logger();
		$this->redirection_logic   = new RedirectionLogic();
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
			'newspack-content-migrator migrate-ghost-cms-content',
			[ $this, 'cmd_migrate_ghost_cms_content' ],
			[
				'shortdesc' => 'Migrate Ghost CMS Content using a Ghost JSON export.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'json-file',
						'description' => 'Path to Ghost JSON export file.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			]
		);
	}

	/**
	 * Migrate Ghost CMS Content.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_ghost_cms_content( $pos_args, $assoc_args ) {

		if( ! isset( $assoc_args['json-file'] ) || ! file_exists( $assoc_args['json-file'] ) ) {
			WP_CLI::error( 'JSON file not found.' );
		}

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		
		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Doing migration.' );
		$this->logger->log( $this->log, '--json-file: ' . $assoc_args['json-file'] );
		
        $contents = file_get_contents( $assoc_args['json-file'] );
		$this->json = json_decode( $contents, null, 2147483647 );
        
        if( 0 != json_last_error() || 'No error' != json_last_error_msg() ) {
			WP_CLI::error( 'JSON file could not be parsed.' );
		}
		
		if( empty( $this->json->db[0]->data->posts ) ) {
			WP_CLI::error( 'JSON file contained no posts.' );
		}
			
		// Insert posts.
		foreach( $this->json->db[0]->data->posts as $json_post ) {

			// Skip if not post, or not published, or not visible
			if( 'post' != $json_post->type || 'published' != $json_post->status || 'public' != $json_post->visibility ) {
				$this->logger->log( $this->log . '-skips.log', print_r( $json_post, true ) );
				continue;
			}

			// todo: if post exists???

			// Post.
			// $postarr = array(
			// 	'title' => $json_post->title,
			// 	'post_content' => $json_post->html,
			// 	'post_date' => $json_post->published_at,
			// 	'post_excerpt' => $json_post->custom_excerpt,
			// 	'post_status' => 'publish',
			// );

			// $wp_post_id = wp_insert_post( $postarr );

			// if( ! ( $wp_post_id > 0 ) ) {
			// 	$this->logger->log( $this->log, 'Could not insert post for json id: ' . $json_post->id, $this->logger::WARNING );
			// 	continue;
			// }

			// Meta.
			// update_post_meta( $wp_post_id, 'newspack_ghostcms_json_id', $json_post->id );
			// update_post_meta( $wp_post_id, 'newspack_ghostcms_json_uuid', $json_post->uuid );
			// update_post_meta( $wp_post_id, 'newspack_ghostcms_json_slug', $json_post->slug );

			// Post authors to WP Users/CAP GAs.
			// $this->post_authors( $wp_post_id, $json_post->id );

			// Post tags to categories.
			// $this->post_tags_to_categories( $wp_post_id, $json_post->id );


			// Fetch "feature_image": "__GHOST_URL__/content/images/wp-content/uploads/2022/10/chaka-khan.jpg",
			// 		'feature_image_alt'		=> ( $image_id !== 0 && $image_alt ) ? substr( $image_alt, 0, 125 ) : null,
			// 		'feature_image_caption'	
			// $image = wp_get_attachment_image_src( $image_id, 'full' );
			// 		$image_alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			// 		$image_caption = wp_get_attachment_caption( $image_id );
			// set meta: _thumbnail_id

			
			// Fetch images in content.

			// Set Yoast primary if needed?

			// Set slug redirects if needed?

		}

        print_r($this->authors_to_wp_objects);

        $this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
        
	}

	private function post_authors( $wp_post_id, $json_post_id ) {

		if( empty( $this->json->db[0]->data->posts_authors ) ) return null;

		$wp_objects = [];

		// Each posts_authors relationship.
		foreach( $this->json->db[0]->data->posts_authors as $json_post_author ) {
			
			// Skip if post id does not match relationship.
			if( $json_post_author->post_id != $json_post_id ) continue;

			// If author_id wasn't already processed.
			if( ! isset( $this->authors_to_wp_objects[ $json_post_author->author_id ] ) ) {

				// Get the json author (user) object.
				$json_author_user = $this->get_json_author_user_by_id( $json_post_author->author_id );

				// Verify related author (user) was found in json.
				if( empty( $json_author_user ) ) continue;

				// Attempt insert and save return value into lookup.
				$this->authors_to_wp_objects[ $json_post_author->author_id ] = $this->insert_json_author_user( $json_author_user );

			}

			// Verify lookup value is an object
			// A value of 0 means json author (user) did not have visibility of public.
			// In that case, don't add to return array.
			if( is_object( $this->authors_to_wp_objects[ $json_post_author->author_id ] ) ) {
				$wp_objects[] = $this->authors_to_wp_objects[ $json_post_author->author_id ];
			}
							
		} // foreach relationship

		if( ! empty( $wp_objects ) ) {
			// WP Users and/or CAP GAs
			$this->coauthorsplus_logic->assign_authors_to_post( $wp_objects, $wp_post_id );
		}

	}

	private function post_tags_to_categories( $wp_post_id, $json_post_id ) {

		if( empty( $this->json->db[0]->data->posts_tags ) ) return null;

		$category_ids = [];

		// Each posts_tags relationship.
		foreach( $this->json->db[0]->data->posts_tags as $json_post_tag ) {
			
			// Skip if post id does not match relationship.
			if( $json_post_tag->post_id != $json_post_id ) continue;

			// If tag_id wasn't already processed.
			if( ! isset( $this->tags_to_categories[ $json_post_tag->tag_id ] ) ) {

				// Get the json tag object.
				$json_tag = $this->get_json_tag_by_id( $json_post_tag->tag_id );

				// Verify related tag was found in json.
				if( empty( $json_tag ) ) continue;

				// Attempt insert and save return value into lookup.
				$this->tags_to_categories[ $json_post_tag->tag_id ] = $this->insert_json_tag_as_category( $json_tag );

			}

			// Verify lookup value > 0
			// A value of 0 means json tag did not have visibility of public.
			// In that case, don't add to return array.
			if( $this->tags_to_categories[ $json_post_tag->tag_id ] > 0 ) {
				$category_ids[] = $this->tags_to_categories[ $json_post_tag->tag_id ];
			}
							
		} // foreach post_tag relationship

		if( ! empty( $category_ids ) ) wp_set_post_categories( $wp_post_id, $category_ids );

	}

	private function get_json_tag_by_id ( $json_tag_id ) {

		if( empty( $this->json->db[0]->data->tags ) ) return null;

		foreach( $this->json->db[0]->data->tags as $json_tag ) {

			if( $json_tag->id == $json_tag_id ) return $json_tag;

		} 

		return null;

	}

	private function get_json_author_user_by_id ( $json_author_user_id ) {

		if( empty( $this->json->db[0]->data->users ) ) return null;

		foreach( $this->json->db[0]->data->users as $json_author_user ) {

			if( $json_author_user->id == $json_author_user_id ) return $json_author_user;

		} 

		return null;

	}

	private function insert_json_tag_as_category( $json_tag ) {

		// Must have visibility property with value of 'public'.
		if( empty( $json_tag->visibility ) || 'public' != $json_tag->visibility ) return 0;
		
		// Check if category exists in db.
		$term_arr = term_exists( $json_tag->name, 'category' );

		// Category does not exist.
		if( ! is_array( $term_arr ) || empty( $term_arr['term_id'] ) ) {

			// Insert it.
			$term_arr = wp_insert_term( $json_tag->name, 'category' );

			// Log and return 0 if insert failed.
			if( is_wp_error( $term_arr ) || ! is_array( $term_arr ) || empty( $term_arr['term_id'] ) ) {
				$this->logger->log( $this->log, 'WP insert term failed: ' . $json_tag->name, $this->logger::WARNING );
				return 0;
			}

		}
		
		// Get category object from db.
		$term = get_term( $term_arr['term_id'] );

		// Add redirect if needed.
		if( $term->slug != $json_tag->slug ) {
			$this->logger->log( $this->log, 'TODO: term redirect needed: ' . $json_tag->slug . ' => ' . $term->slug, $this->logger::WARNING );
			// $this->set_redirect( '/people/' . $person->slug, '/author/' . $ga_obj->user_login, 'people' );
		}

		return $term_arr['term_id'];

	}

	private function insert_json_author_user( $json_author_user ) {

		// Must have visibility property with value of 'public'.
		if( empty( $json_author_user->visibility ) || 'public' != $json_author_user->visibility ) return 0;
		
		// Get existing GA if exists.
		// As of 2024-03-19 the use of 'coauthorsplus_logic->create_guest_author()' to return existing match
		// may return an error. WP Error occures if existing database GA is "Jon A. Doe" but new GA is "Jon A Doe".
		// New GA will not match on display name, but will fail on create when existing sanitized slug is found.
		// Use a more direct approach here.
		$user_login = sanitize_title( urldecode( $json_author_user->name ) );
		$ga = $this->coauthorsplus_logic->get_guest_author_by_user_login( $user_login );

		// GA Exists.
		if( is_object( $ga ) ) {

			// Create redirect if needed
			if( $ga->user_login != $json_author_user->slug ) {
				$this->logger->log( $this->log, 'Need GA redirect', $this->logger::WARNING );
				// $this->set_redirect( '/people/' . $person->slug, '/author/' . $ga_obj->user_login, 'people' );
			}

			return $ga;
		
		}

		// Check for WP user
		$user_query = new \WP_User_Query( array( 
			'login'    => $user_login,
			'role__in' => array( 'Administrator', 'Editor', 'Author', 'Contributor' ),
		));

		foreach ( $user_query->get_results() as $wp_user ) {

			// Create redirect if needed
			if( $wp_user->user_nicename != $json_author_user->slug ) {
				$this->logger->log( $this->log, 'Need WP USER redirect', $this->logger::WARNING );
				// $this->set_redirect( '/people/' . $person->slug, '/author/' . $ga_obj->user_login, 'people' );
			}

			// Return the first user found.
			return $wp_user;

		}

		// Create a GA.
		$ga_id = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $json_author_user->name ) );

		if ( is_wp_error( $ga_id ) || ! is_numeric( $ga_id ) || ! ( $ga_id > 0 ) ) {

			$this->logger->log( $this->log, 'GA create failed: ' . $json_author_user->name, $this->logger::WARNING );
			return 0;

		}

		$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );
	
		if( $ga->user_login != $json_author_user->slug ) {
			$this->logger->log( $this->log, 'Need new GA redirect', $this->logger::WARNING );
			// $this->set_redirect( '/people/' . $person->slug, '/author/' . $ga_obj->user_login, 'people' );
		}

		return $ga;

	}

	private function set_redirect( $url_from, $url_to, $batch, $verbose = false ) {

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		
		if( ! empty( \Red_Item::get_for_matched_url( $url_from ) ) ) {

			if( $verbose ) WP_CLI::warning( 'Skipping redirect (exists): ' . $url_from . ' to ' . $url_to );
			return;

		}

		if( $verbose ) WP_CLI::line( 'Adding (' . $batch . ') redirect: ' . $url_from . ' to ' . $url_to );
		
		$this->redirection_logic->create_redirection_rule(
			'Old site (' . $batch . ')',
			$url_from,
			$url_to
		);

		return;

	}



}


