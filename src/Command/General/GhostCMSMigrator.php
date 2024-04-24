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
use WP_CLI;

/**
 * Custom migration scripts for Ghost CMS.
 */
class GhostCMSMigrator implements InterfaceCommand {

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
		$json = json_decode( $contents, null, 2147483647 );
        
        if( 0 != json_last_error() || 'No error' != json_last_error_msg() ) {
			WP_CLI::error( 'JSON file could not be parsed.' );
		}
		
		if( empty( $json->db[0]->data->posts ) ) {
			WP_CLI::error( 'JSON file contained no posts.' );
		}
	
		// Insert category/terms.
		$tags_to_categories = empty( $json->db[0]->data->tags ) ? array() : $this->insert_tags_to_categories( $json->db[0]->data->tags );
		$users_to_authors   = empty( $json->db[0]->data->users ) ? array() : $this->insert_users_to_authors( $json->db[0]->data->users );
		
		// Insert posts.
		foreach( $json->db[0]->data->posts as $json_post ) {

			// Skip if not post or not published or not visible
			if( 'post' != $json_post->type || 'published' != $json_post->status || 'public' != $json_post->visibility ) {
				continue;
			}

			// Post.
			$postarr = array(
				'title' => $json_post->title,
				'post_content' => $json_post->html,
				'post_date' => $json_post->published_at,
				'post_excerpt' => $json_post->custom_excerpt,
				'post_status' => 'publish',
			);

			$post_id = wp_insert_post( $postarr );

			if( ! ( $post_id > 0 ) ) {
				$this->logger->log( $this->log, 'Could not insert post for json id: ' . $json_post->id, $this->logger::WARNING );
				continue;
			}

			// Meta.
			update_post_meta( $post_id, 'newspack_ghostcms_json_id', $json_post->id );
			update_post_meta( $post_id, 'newspack_ghostcms_json_uuid', $json_post->uuid );
			update_post_meta( $post_id, 'newspack_ghostcms_json_slug', $json_post->slug );

			// Add tags as categories.
			if( ! empty( $json->db[0]->data->posts_tags ) ) {
				$post_categories = array();
				foreach( $json->db[0]->data->posts_tags as $json_post_tag ) {
					if( $json_post_tag->post_id == $json_post->id && isset( $tags_to_categories[ $json_post_tag->tag_id ] ) ) {
						$post_categories[] = $tags_to_categories[ $json_post_tag->tag_id ];
					}
				}
				if( count( $post_categories ) > 0 ) {
					wp_set_post_categories( $post_id, $post_categories );
				}
			}

			// Authors/CAP GAs.
			if( ! empty( $json->db[0]->data->posts_authors ) ) {
				$post_authors = array();
				foreach( $json->db[0]->data->posts_authors as $json_post_author ) {
					if( $json_post_author->post_id == $json_post->id && isset( $authors[ $json_post_author->author_id ] ) ) {
						$post_authors[] = $authors[ $json_post_author->author_id ];
					}
				}
				if( count( $post_authors ) > 0 ) {
					// add authors to post.
				}
			}

			// Fetch "feature_image": "__GHOST_URL__/content/images/wp-content/uploads/2022/10/chaka-khan.jpg",
			// set _thumbnail_id
			
			// Fetch images in content.

			// Set Yoast primary if needed?

			// Set slug redirects if needed?

		}

        
        $this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
        
	}

	private function insert_tags_to_categories( $tags ) {

		$out = [];

		foreach( $tags as $tag ) {
			
			if( 'public' != $tag->visibility ) continue;

			$term_arr = wp_insert_term( $tag->name, 'category' );

			if( ! is_array( $term_arr ) || empty( $term_arr['term_id'] ) ) {
				$this->logger->log( $this->log, 'Insert term failed for json tag name: ' . $tag->name, $this->logger::WARNING );
			}
			
			$term = get_term( $term_arr['term_id'] );

			if( $term->slug != $tag->slug ) {
				// TODO: add category redirect
			}

			$out[ $tag->id ] = $term_arr['term_id'];

		}

		return $out;

	}

	private function insert_users_to_authors( $users ) {

		$out = [];

		foreach( $users as $user ) {
			
			if( 'public' != $user->visibility ) continue;

			// Look up CAP GA or WP User.
			// "id": "6387a43e354f5f003ddbe55f",
			// "name": "Our Weekly Staff",
			// "slug": "adminnewspack",

			// Todo: bio info:
			// "profile_image": "https://secure.gravatar.com/avatar/bbc27e5236a192d24f7dd18d94b2415c?s=3000&d=mm&r=g",
			// "cover_image": null,
			// "bio": null,
			// "website": "https://ourweekly.com",
			// "location": null,
			// "facebook": null,
			// "twitter": null,

			// insert GA

			// TODO: add slug redirect if different

			// $out[ $user->id ] = new GA id here...

		}

		return $out;

	}

}
