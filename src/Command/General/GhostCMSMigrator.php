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
use NewspackCustomContentMigrator\Utils\Logger;
use stdClass;
use WP_CLI;

/**
 * Custom migration scripts for Ghost CMS.
 */
class GhostCMSMigrator implements InterfaceCommand {

	/**
	 * JSON from file
	 *
	 * @var object $json
	 */
	private $json;

	/**
	 * Lookup to convert json tags to wp categories.
	 * 
	 * Note: tag_id key may exist, but if tag visibility was not public, value will be 0
	 *
	 * @var array $tags_to_categories
	 */
	private $tags_to_categories;

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
	 * Instance
	 * 
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger              = new Logger();
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

		global $wpdb;

		if( ! isset( $assoc_args['json-file'] ) || ! file_exists( $assoc_args['json-file'] ) ) {
			WP_CLI::error( 'JSON file not found.' );
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
	
		// $users_to_authors   = empty( $json->db[0]->data->users ) ? array() : $this->insert_users_to_authors( $json->db[0]->data->users );
		// return;
		
		// Insert posts.
		foreach( $this->json->db[0]->data->posts as $json_post ) {

			// Skip if not post or not published or not visible
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

			// $post_id = wp_insert_post( $postarr );

			// if( ! ( $post_id > 0 ) ) {
			// 	$this->logger->log( $this->log, 'Could not insert post for json id: ' . $json_post->id, $this->logger::WARNING );
			// 	continue;
			// }

			// Meta.
			// update_post_meta( $post_id, 'newspack_ghostcms_json_id', $json_post->id );
			// update_post_meta( $post_id, 'newspack_ghostcms_json_uuid', $json_post->uuid );
			// update_post_meta( $post_id, 'newspack_ghostcms_json_slug', $json_post->slug );

			// Tags to categories.
			$category_ids = $this->post_tags_to_categories( $json_post->id );
			continue;

			if( ! empty( $category_ids ) ) wp_set_post_categories( $post_id, $category_ids );

						
			if( ! empty( $json->db[0]->data->posts_tags ) ) {
				$tags_to_postmeta = array();
				foreach( $json->db[0]->data->posts_tags as $json_post_tag ) {
					if( $json_post_tag->post_id == $json_post->id ) {
						foreach( $json->db[0]->data->tags as $json_tag ) {
							$tags_to_postmeta[] = array( $json_tag->name, $json_tag->slug );
						}
					}
				}
				update_post_meta( $post_id, 'newspack_ghostcms_json_tags', $tags_to_postmeta );
			}

			// Save authors to postmeta.
			// this code will re-scan the JSON posts_authors and users in an effor to not
			// add more data to memory.  We could implement a hash table for quicker
			// lookups if memory usage isn't already overloaded. 
			if( ! empty( $json->db[0]->data->posts_authors ) ) {
				$authors_to_postmeta = array();
				foreach( $json->db[0]->data->posts_authors as $json_post_author ) {
					if( $json_post_author->post_id == $json_post->id ) {
						foreach( $json->db[0]->data->users as $json_user ) {
							// $authors_to_postmeta[] = array( $json_user->name, $json_use;
						}
					}
				}
				update_post_meta( $post_id, 'newspack_ghostcms_json_authors', $authors_to_postmeta );
			}


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

        
        $this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
        
	}

	private function post_tags_to_categories( $json_post_id ) {

		if( empty( $this->json->db[0]->data->posts_tags ) ) return null;

		$out = [];

		// Each posts_tags relationship.
		foreach( $this->json->db[0]->data->posts_tags as $json_post_tag ) {
			
			// Continue if post id does not match relationship.
			if( $json_post_tag->post_id != $json_post_id ) continue;

			$this->logger->log( $this->log, print_r( $json_post_tag, true ) );

			// Check if tag_id key already exists in lookup.
			if( isset( $this->tags_to_categories[ $json_post_tag->tag_id ] ) ) {

				$this->logger->log( $this->log, 'tag_to_category exists: ' . $this->tags_to_categories[ $json_post_tag->tag_id ] );

				// Verify lookup value > 0
				// A value of 0 means json tag did not have visibility of public.
				// In that case, don't add to return array.
				if( $this->tags_to_categories[ $json_post_tag->tag_id ] > 0 ) {
					$out[] = $this->tags_to_categories[ $json_post_tag->tag_id ];
				}

				// Look for next post_tag relationship.
				continue;
			}

			// Get the tag object.
			$json_tag = $this->get_json_tag_by_id( $json_post_tag->tag_id );

			$this->logger->log( $this->log, 'tag object' . print_r( $json_tag, true ) );

			if( empty( $json_tag ) ) continue;

			// Attempt insert.
			$category_id = $this->insert_tag_as_category( $json_tag );

			$this->logger->log( $this->log, 'cat id: ' . $category_id );

			// If any issues with insert (ie: json_tag visibility not public, set category_id to 0)
			if( ! is_numeric( $category_id ) ) $category_id = 0;

			// Save into lookup.
			$this->tags_to_categories[ $json_post_tag->tag_id ] = $category_id;

			// Add to output if > 0
			// A value of 0 means json tag did not have visibility of public.
			// In that case, don't add to return array.
			if( $category_id > 0 ) $out[] = $category_id;
							
		} // foreach post_tag relationship

		return $out;

	}

	private function get_json_tag_by_id ( $json_tag_id ) {

		if( empty( $this->json->db[0]->data->tags ) ) return null;

		foreach( $this->json->db[0]->data->tags as $json_tag ) {

			if( $json_tag->id == $json_tag_id ) return $json_tag;

		} 

		return null;

	}

	private function insert_tag_as_category( $json_tag ) {

		if( 'public' != $json_tag->visibility ) return 0;

		$this->logger->log( $this->log, '---- Tag: ' . $json_tag->name . ' / ' . $json_tag->slug . ' / ' . $json_tag->id );
		
		// Check if category exists in db.
		$term_arr = term_exists( $json_tag->name, 'category' );

		// Category does not exist.  Insert it.
		if( ! is_array( $term_arr ) || empty( $term_arr['term_id'] ) ) {

			$this->logger->log( $this->log, 'Inserting new term: ' . $json_tag->name );

			$term_arr = wp_insert_term( $json_tag->name, 'category' );

			if( ! is_array( $term_arr ) || empty( $term_arr['term_id'] ) ) {

				$this->logger->log( $this->log, 'Insert term failed: ' . $json_tag->name, $this->logger::WARNING );

				return 0;

			}

		}
		
		// Get category object from db.
		$term = get_term( $term_arr['term_id'] );

		// Add redirect if needed.
		if( $term->slug != $json_tag->slug ) {

			$this->logger->log( $this->log, 'TODO: term redirect needed.', $this->logger::WARNING );
			$this->logger->log( $this->log, 'Json term slug: ' . $json_tag->slug );
			$this->logger->log( $this->log, 'WP term slug: ' . $term->slug );

		}

		return $term_arr['term_id'];

	}

	private function insert_users_to_authors( $users ) {

		$out = [];

		foreach( $users as $user ) {
			
			if( 'public' != $user->visibility ) continue;

			$this->logger->log( $this->log, '---- User: ' . $user->name . ' / ' . $user->slug . ' / ' . $user->email );

			// Must have posts.
			// and published....
			// let's story this info in postmeta, then do these after.
			// otherwise we have to scan to much of the json...
			// we already are overloaded in memory with the JSON so try to avoid additional variables in memory



			// Look up CAP GA or WP User.

			// don't insert if they have no posts?

				// ---- User: Ghost Concierge / ghost-user / concierge@ghost.org

			// look for wp user:

				// by email:
					// ---- User: Our Weekly LLC / our / bnorwood@ourweekly.com
						//  - email exists as admin, but nicename is different and no posts in staging

					// ---- User: Marcellus Cole / mcole / mcole@ourweekly.com
						// - email match, but no posts in staging

					// ---- User: Caleb Pugh / caleb-pugh / cpugh@ourweekly.com
				
				// by slug / name:

					// ---- User: Gregg Reese / gregg-reese
					// ---- User: Lisa Fitch / lfitch / lfitch@example.com
					// ---- User: Brandon Norwood / brandon-norwood
					// ---- User: Our Weekly Staff / adminnewspack
					// ---- User: Our Weekly LA / our-weekly-la

			// insert GA

			// TODO: add slug redirect if different

			// $out[ $user->id ] = new GA id here...

		}

		return $out;

	}

}
