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
use NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Ghost CMS.
 */
class GhostCMSMigrator implements InterfaceCommand {

	/**
	 * AttachmentsLogic
	 * 
	 * @var AttachmentsLogic 
	 */
	private $attachments_logic;

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
	 * Ghost URL for image downloads.
	 *
	 * @var string ghost_url
	 */
	private $ghost_url;

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
		$this->attachments_logic   = new AttachmentsLogic();
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
					array(
						'type'        => 'assoc',
						'name'        => 'ghost-url',
						'description' => 'Public URL of current/live Ghost Website for fetching images. Format: https://mywebsite.com',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'default-user-id',
						'description' => 'User ID for default "post_author" for wp_insert_post(). Integer.',
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

		global $wpdb;
		
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		if( ! isset( $assoc_args['json-file'] ) || ! file_exists( $assoc_args['json-file'] ) ) {
			WP_CLI::error( 'JSON file not found.' );
		}

		if( ! isset( $assoc_args['ghost-url'] ) || ! preg_match( '#^https?://[^/]+/?$#i', $assoc_args['ghost-url'] ) ) {
			WP_CLI::error( 'Ghost URL does not match regex: ^https?://[^/]+/?$' );
		}
		
		$this->ghost_url = preg_replace( '#/$#', '', $assoc_args['ghost-url'] );

		if( ! isset( $assoc_args['default-user-id'] ) || ! is_numeric( $assoc_args['default-user-id'] ) ) {
			WP_CLI::error( 'Default user id must be integer.' );
		}

		$default_user = get_user_by( 'ID', $assoc_args['default-user-id'] );

		if( ! is_a( $default_user, 'WP_User') ) {
			WP_CLI::error( 'Default user id does not match a wp user.' );
		}
		
		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Doing migration.' );
		$this->logger->log( $this->log, '--json-file: ' . $assoc_args['json-file'] );
		$this->logger->log( $this->log, '--ghost-url: ' . $this->ghost_url );
		$this->logger->log( $this->log, '--default-user-id: ' . $default_user->ID );
		
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
				
				// Save to file, but do not write to console.
				$this->logger->log( $this->log . '-skips.log', print_r( $json_post, true ), false );
				continue;

			}

			// Skip if required value(s) are empty
			if( empty( $json_post->html ) || empty( $json_post->published_at ) || empty( $json_post->title ) ) {
				
				// Save to file, but do not write to console.
				$this->logger->log( $this->log . '-empty.log', print_r( $json_post, true ), false );
				continue;

			}

			// Skip if already imported.
			if( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $wpdb->postmeta WHERE meta_key = 'newspack_ghostcms_json_id' AND meta_value = %s", $json_post->id ) ) ) {
				
				$this->logger->log( $this->log, 'JSON post already imported: ' . $json_post->id );
				continue;

			}

			
			// TODO: Fetch images in content.
			
			/*
			// <figure class="kg-card kg-image-card"><img src="__GHOST_URL__/content/images/2023/02/image.jpeg" class="kg-image" alt loading="lazy" width="1023" height="678" srcset="__GHOST_URL__/content/images/size/w600/2023/02/image.jpeg 600w, __GHOST_URL__/content/images/size/w1000/2023/02/image.jpeg 1000w, __GHOST_URL__/content/images/2023/02/image.jpeg 1023w" sizes="(min-width: 720px) 720px"></figure>
			// <a href="https://ourweekly.com/marietta-retta-sirleaf-9709/" border="0" itemprop="url">
			// <img data-attachment-id="409709" data-orig-file="https://ourweekly.com/wp-content/uploads/2013/05/Marietta_Sirleaf_Retta.jpg" 
			// <img data-attachment-id="409709" data-orig-file="https://ourweekly.com/wp-content/uploads/2013/05/Marietta_Sirleaf_Retta.jpg" data-orig-size="500,351" data-comments-opened="" data-image-meta="{&quot;aperture&quot;:&quot;0&quot;,&quot;credit&quot;:&quot;&quot;,&quot;camera&quot;:&quot;&quot;,&quot;caption&quot;:&quot;&quot;,&quot;created_timestamp&quot;:&quot;0&quot;,&quot;copyright&quot;:&quot;&quot;,&quot;focal_length&quot;:&quot;0&quot;,&quot;iso&quot;:&quot;0&quot;,&quot;shutter_speed&quot;:&quot;0&quot;,&quot;title&quot;:&quot;&quot;,&quot;orientation&quot;:&quot;0&quot;}" data-image-title="Marietta “Retta” Sirleaf (9709)" data-image-description=" " data-medium-file="https://i0.wp.com/ourweekly.com/wp-content/uploads/2013/05/Marietta_Sirleaf_Retta.jpg?fit=300%2C211&amp;ssl=1" data-large-file="https://i0.wp.com/ourweekly.com/wp-content/uploads/2013/05/Marietta_Sirleaf_Retta.jpg?fit=500%2C351&amp;ssl=1" src="https://i0.wp.com/ourweekly.com/wp-content/uploads/2013/05/Marietta_Sirleaf_Retta.jpg?w=173&amp;h=122&amp;ssl=1" width="173" height="122" data-original-width="173" data-original-height="122" itemprop="http://schema.org/image" title="Marietta &quot;Retta&quot; Sirleaf (9709)" alt="Marietta &quot;Retta&quot; Sirleaf (9709)" style="width: 173px; height: 122px;">
			// <figure class="wp-block-video"><video controls="" poster="/content/images/wp-content/uploads/2022/02/voicemails_thumbnails_driving-scaled.jpg" src="__GHOST_URL__/content/media/wp-content/uploads/2022/02/branded-myriad-originals-_-voicemails-part-1-bts-with-javon-johnson.mp4" style="width: 100%;"></video>

			// what about hrefs to files or images on the same domain we we need to fetch?
			// <a href="__GHOST_URL__/content/files/de/04/76fbf27f4f9eb607f2cef48792f9/complaint.pdf">
			// <a href="__GHOST_URL__/content/files/files/2022/05/analysis-of-pm2.5-related-health-burdens-under-current-and-alternative-naaqs.pdf">

			// what about links:
			// <a href="__GHOST_URL__/author/merdies-hayes/">
			// <a href="https://ourweekly.com/michelle-thornhill-9708/" 

			// <p><em>http://www.ourweekly.com/los-angeles/protesters-decry-officer%E2%80%99s-release</em></p>
			// <p><em>http://www.ourweekly.com/los-angeles/starbucks-share-wealth-urban-league-abyssinian-corp</em></p>
			// <p><em>http://ourweekly.com/features/black-men-their-moms</em></p>				
				

			// iframe?

			*/


			// todo: if post exists but postmeta below wasn't added (ie: the script stopped during import).

			// todo: wp user doesn't have permissions to insert?

			// Post.
			$postarr = array(
				'post_author' => $default_user->ID,
				'post_content' => $json_post->html,
				'post_date' => $json_post->published_at,
				'post_excerpt' => $json_post->custom_excerpt ?? '',
				'post_status' => 'publish',
				'post_title' => $json_post->title,
			);

			$wp_post_id = wp_insert_post( $postarr );

			if( ! ( $wp_post_id > 0 ) ) {
				$this->logger->log( $this->log, 'Could not insert post for json id: ' . $json_post->id, $this->logger::WARNING );
				continue;
			}

			$this->logger->log( $this->log, 'Inserted json id: ' . $json_post->id . ' post id: ' . $wp_post_id );
			
			return;

			// Post authors to WP Users/CAP GAs.
			$this->set_post_authors( $wp_post_id, $json_post->id );

			// Post tags to categories.
			$this->set_post_tags_to_categories( $wp_post_id, $json_post->id );

			// Featured image (with alt and caption).
			// Note: json value does not contain a "d": feature(d)_image
			if( ! empty( $json_post->feature_image ) ) $this->set_post_featured_image( $wp_post_id, $json_post->id, $json_post->feature_image );

			// Set slug redirects if needed.
			$wp_post_slug = get_post_field( 'post_name', $wp_post_id );
			if( $json_post->slug != $wp_post_slug ) {
				$this->logger->log( $this->log, 'TODO: post redirect needed: ' . $json_post->slug . ' => ' . $wp_post_slug, $this->logger::WARNING );
				// $this->set_redirect( '/people/' . $person->slug, '/author/' . $ga_obj->user_login, 'people' );
			}

			// Post meta.
			update_post_meta( $wp_post_id, 'newspack_ghostcms_checksum', md5( json_encode( $json_post ) ) );
			update_post_meta( $wp_post_id, 'newspack_ghostcms_json_uuid', $json_post->uuid );
			update_post_meta( $wp_post_id, 'newspack_ghostcms_json_slug', $json_post->slug );

			// Insert this one last since it's our key to check if already imported.
			update_post_meta( $wp_post_id, 'newspack_ghostcms_json_id', $json_post->id );

		}

        $this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
        
	}

	private function set_post_featured_image( $wp_post_id, $json_post_id, $old_image_url ) {

		// The old image url may already contain the domain name ( https://mywebsite.com/.../image.jpg )
		// But if not, replace the placeholder ( __GHOST_URL__/.../image.jpg )
		$old_image_url = preg_replace( '#^__GHOST_URL__#', $this->ghost_url, $old_image_url );

		// Get alt and caption if exists in json meta node.
		$json_meta = $this->get_json_post_meta ( $json_post_id );

		$old_image_alt = $json_meta->feature_image_alt ?? '';
		$old_image_caption = $json_meta->feature_image_caption ?? '';

		// get existing or upload new
		$featured_image_id = $this->get_or_import_url( $old_image_url, $old_image_url, $old_image_caption, $old_image_caption, $old_image_alt );

		if( ! is_numeric( $featured_image_id ) || ! ( $featured_image_id > 0 ) ) {
			
			$this->logger->log( $this->log, 'Featured image import failed for: ' . $old_image_url, $this->logger::WARNING );

			if( is_wp_error( $featured_image_id ) ) {

				$this->logger->log( $this->log, 'Featured image import wp error: ' . $featured_image_id->get_error_message(), $this->logger::WARNING );

			}
			
			return;
		}

		update_post_meta( $wp_post_id, '_thumbnail_id', $featured_image_id );

	}

	private function set_post_authors( $wp_post_id, $json_post_id ) {

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

	private function set_post_tags_to_categories( $wp_post_id, $json_post_id ) {

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

	private function get_json_author_user_by_id ( $json_author_user_id ) {

		if( empty( $this->json->db[0]->data->users ) ) return null;

		foreach( $this->json->db[0]->data->users as $json_author_user ) {

			if( $json_author_user->id == $json_author_user_id ) return $json_author_user;

		} 

		return null;

	}

	private function get_json_post_meta ( $json_post_id ) {

		if( empty( $this->json->db[0]->data->posts_meta ) ) return null;

		foreach( $this->json->db[0]->data->posts_meta as $json_post_meta ) {

			if( $json_post_meta->post_id == $json_post_id ) return $json_post_meta;

		} 

		return null;

	}

	private function get_json_tag_by_id ( $json_tag_id ) {

		if( empty( $this->json->db[0]->data->tags ) ) return null;

		foreach( $this->json->db[0]->data->tags as $json_tag ) {

			if( $json_tag->id == $json_tag_id ) return $json_tag;

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

	private function get_or_import_url( $path, $title, $caption = null, $description = null, $alt = null ) {

		global $wpdb;

		// have to check if alredy exists so that multiple calls do not download() files already inserted
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment' and post_title = %s
		", $title ));

		if( is_numeric( $attachment_id ) && $attachment_id > 0 ) return $attachment_id;

		// this function will check if existing, but only after re-downloading
		return $this->attachments_logic->import_external_file(  $path, $title, $caption, $description, $alt );

	}




	private function set_redirect( $url_from, $url_to, $batch, $verbose = false ) {

		// todo, change logging.


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


