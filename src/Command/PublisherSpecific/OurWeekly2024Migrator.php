<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use \NewspackCustomContentMigrator\Utils\Logger;
use \Symfony\Component\DomCrawler\Crawler;

/**
 * Custom migration scripts for Our Weekly (in 2024 from Ghost CMS).
 */
class OurWeekly2024Migrator implements InterfaceCommand {

	private static $instance = null;

	// Logic
	private $attachments_logic;
	private $crawler;
	private $logger;
	private $posts_logic;
	private $redirection_logic;

	// Logging and Output
	private $log;
	private $dry_run;
	private $report = [];

	// Vars
	private $json;
	private $tags_to_categories;

	
	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments_logic   = new AttachmentsLogic();
		$this->crawler             = new Crawler();
		$this->logger              = new Logger();
		$this->posts_logic         = new PostsLogic();
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
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {

		WP_CLI::add_command(
			'newspack-content-migrator ourweekly2024-categories',
			[ $this, 'cmd_ourweekly2024_categories' ],
			[
				'shortdesc' => 'Fix category mixup.',
				'synopsis'  => array(
					'synopsis'  => array(
						array(
							'type'        => 'assoc',
							'name'        => 'json-file',
							'description' => 'Path to Ghost JSON export file.',
							'optional'    => false,
							'repeating'   => false,
						),
						array(
							'type'        => 'flag',
							'name'        => 'dry-run',
							'description' => 'No updates.',
							'optional'    => true,
							'repeating'   => false,
						),
					),
				),
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator ourweekly2024-post-content',
			[ $this, 'cmd_ourweekly2024_post_content' ],
			[
				'shortdesc' => 'Fetch and replace media in post content.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator ourweekly2024-redirects',
			[ $this, 'cmd_ourweekly2024_redirects' ],
			[
				'shortdesc' => 'Set redirects as needed.',
			]
		);

	}



	/**
	 * CATEGORIES
	 * 
	 */

	public function cmd_ourweekly2024_categories( $pos_args, $assoc_args ) {
		
		global $wpdb;

		// --dry-run

		$this->dry_run = ( isset( $assoc_args['dry-run'] ) ) ? true : false;

		// --json-file.

		if ( ! isset( $assoc_args['json-file'] ) || ! file_exists( $assoc_args['json-file'] ) ) {
			WP_CLI::error( 'JSON file not found.' );
		}

		$this->json = json_decode( file_get_contents( $assoc_args['json-file'] ), null, 2147483647 );

		if ( 0 != json_last_error() || 'No error' != json_last_error_msg() ) {
			WP_CLI::error( 'JSON file could not be parsed.' );
		}

		if ( empty( $this->json->db[0]->data->posts ) ) {
			WP_CLI::error( 'JSON file contained no posts.' );
		}

		// Start processing.
		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';
		
		$this->logger->log( $this->log, 'Starting.' );
		$this->logger->log( $this->log, '--json-file: ' . $assoc_args['json-file'] );
		
		if( $this->dry_run ) $this->logger->log( $this->log, '--dry-run' );
		
		// Posts that need their categories reset
		$results = $wpdb->get_results("
			select distinct pm.post_id, pm.meta_value
			from wp_postmeta pm
			join wp_term_relationships tr on tr.object_id = pm.post_id
			join wp_term_taxonomy tt on tt.term_taxonomy_id = tr.term_taxonomy_id and tt.taxonomy = 'category'
			join wp_terms t on t.term_id = tt.term_id and t.slug in( 'politics', 'government', 'local-ow', 'local', 'our-opinion', 'opinion' )
			where pm.meta_key = 'newspack_ghostcms_id'
		");

		foreach( $results as $row ) {

			$this->set_post_tags_to_categories( $row->post_id, $row->meta_value );
		
		}

		$this->logger->log( $this->log, print_r( $this->report, true ) );

		$this->logger->log( $this->log, 'Done', $this->logger::SUCCESS );

	}

	private function get_json_tag_by_id( $json_tag_id ) {

		if ( empty( $this->json->db[0]->data->tags ) ) {
			return null;
		}

		foreach ( $this->json->db[0]->data->tags as $json_tag ) {

			if ( $json_tag->id == $json_tag_id ) {
				return $json_tag;
			}       
		} 

		return null;
	}

	private function get_json_tag_as_category( $json_tag ) {

		// Must have visibility property with value of 'public'.
		if ( empty( $json_tag->visibility ) || 'public' != $json_tag->visibility ) {

			$this->logger->log( $this->log, 'JSON tag not visible. Could not be inserted.', $this->logger::WARNING );

			return 0;

		} 

		// Check if category exists in db.
		// Logic from https://github.com/WordPress/wordpress-importer/blob/71bdd41a2aa2c6a0967995ee48021037b39a1097/src/class-wp-import.php#L784-L801 .
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.term_exists_term_exists
		$term_arr = term_exists( $json_tag->slug, 'category' );

		// Category does not exist.
		if ( ! $term_arr || ! isset( $term_arr['term_id'] ) ) {

			$this->logger->log( $this->log, 'WP Category not found (' . $json_tag->slug . ') ', $this->logger::WARNING );
			return 0;

		}

		$this->logger->log( $this->log, 'Existing WP Category found for slug: ' . $json_tag->slug );

		return $term_arr['term_id'];

	}

	private function set_post_tags_to_categories( $wp_post_id, $json_post_id ) {

		if ( empty( $this->json->db[0]->data->posts_tags ) ) {

			$this->logger->log( $this->log, 'JSON has no post tags (category) relationships.', $this->logger::WARNING );

			return null;

		}

		$category_ids = [];

		// Each posts_tags relationship.
		foreach ( $this->json->db[0]->data->posts_tags as $json_post_tag ) {

			// Skip if post id does not match relationship.
			if ( $json_post_tag->post_id != $json_post_id ) {
				continue;
			}

			$this->logger->log( $this->log, 'Relationship found for tag: ' . $json_post_tag->tag_id );

			// If tag_id wasn't already processed.
			if ( ! isset( $this->tags_to_categories[ $json_post_tag->tag_id ] ) ) {

				// Get the json tag object.
				$json_tag = $this->get_json_tag_by_id( $json_post_tag->tag_id );

				// Verify related tag was found in json.
				if ( empty( $json_tag ) ) {

					$this->logger->log( $this->log, 'JSON tag not found: ' . $json_post_tag->tag_id, $this->logger::WARNING );

					continue;

				}

				// Attempt insert and save return value into lookup.
				$this->tags_to_categories[ $json_post_tag->tag_id ] = $this->get_json_tag_as_category( $json_tag );

			}

			// Verify lookup value > 0
			// A value of 0 means json tag did not have visibility of public.
			// In that case, don't add to return array.
			if ( $this->tags_to_categories[ $json_post_tag->tag_id ] > 0 ) {
				$category_ids[] = $this->tags_to_categories[ $json_post_tag->tag_id ];
			}       

		} // foreach post_tag relationship

		if ( empty( $category_ids ) ) {

			$this->logger->log( $this->log, 'No categories.' );

			return null;

		}

		$this->logger->log( $this->log, print_r( $category_ids, true ) );

		array_walk( $category_ids, array($this, 'report') );

		if( ! $this->dry_run ) {
			
			wp_set_post_categories( $wp_post_id, $category_ids );
			$this->logger->log( $this->log, 'Set post categories. Count: ' . count( $category_ids ) );
		
		}


	}













	public function cmd_ourweekly2024_post_content( $pos_args, $assoc_args ) {
		
		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->mylog( 'Starting ...' );

		$args = array(
			// Must be newly imported post
			'meta_key'    => 'newspack_ghostcms_id',
		);

		// get all posts that have the ghost id postmeta
		$this->posts_logic->throttled_posts_loop( $args, function( $post ) {
			
			$this->logger->log( $this->log, '-------- post id: ' . $post->ID );
			$this->logger->log( $this->log, 'ghost id: ' . get_post_meta( $post->ID, 'newspack_ghostcms_id', true ) );

			// HTML nodes
			$this->crawler->clear();
			$this->crawler->add( $post->post_content );

			// log these for by-hand review
			foreach ( $this->crawler->filterXPath( '//a/@href' ) as $node ) {
				$this->logger->log( $this->log . '-links-a.log', $node->value, false );
			} 

			// log these for by-hand review
			foreach ( $this->crawler->filterXPath( '//iframe/@src' ) as $node ) {
				$this->logger->log( $this->log . '-links-iframe.log', $node->value, false );
			}

			// src and srcset
			$links = $this->attachments_logic->get_images_sources_from_content( $post->post_content );

			if( 0 == count( $links ) ) {
				$this->logger->log( $this->log, 'No links found.' );
				return;
			}

			// log links to a file for review
			$this->logger->log( $this->log . '-links-img.log', implode( "\n", $links ), false );

			// filter by domain
			$links = array_filter( $links, function( $link ) {		
				if( preg_match( '#^https://www.ourweekly.com/#i', $link ) ) return true;
			});

			if( 0 == count( $links ) ) {
				$this->logger->log( $this->log, 'No domain links found.' );
				return;
			}

			// uniques
			$links = array_unique( $links );

			// sort by length so longest is replaced first incase "string in string".
			usort( $links, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );

			// Fetch and replace links in content.
			$new_post_content = $post->post_content;			
			foreach( $links as $link ) {
				$new_post_content = $this->fetch_and_replace_link_in_content( $link, $new_post_content );
			}
			
			// Error if replacments were not successful ( new == old ).
			if ( $new_post_content == $post->post_content ) {

				$this->logger->log(
					$this->log,
					'New content is the same as old content.',
					$this->logger::ERROR
				);

				return;

			}
				
			// Update post.
			$this->logger->log( $this->log, 'WP update post.' );

			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $new_post_content,
				)
			);
			
		}); // throttled posts

		$this->logger->log( $this->log, 'Done', $this->logger::SUCCESS );

	}

	public function cmd_ourweekly2024_redirects( $pos_args, $assoc_args ) {
		
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->mylog( 'Starting ...' );

		// -- POSTS:

		$args = array(
			// Must be newly imported post
			'meta_key'    => 'newspack_ghostcms_slug',
		);

		// get all posts that have the ghost id postmeta
		$this->posts_logic->throttled_posts_loop( $args, function( $post ) {
			
			$this->logger->log( $this->log, '-------- post id: ' . $post->ID );
			$this->logger->log( $this->log, 'ghost id: ' . get_post_meta( $post->ID, 'newspack_ghostcms_id', true ) );

			$ghost_slug = get_post_meta( $post->ID, 'newspack_ghostcms_slug', true );

			if( $ghost_slug == $post->post_name ) {
				$this->logger->log( $this->log, 'ghost slug is same as post name' );
				return;
			}

			// old format:
			$url_from = preg_replace( '/^(\d{4})-(\d{2})-(\d{2}).*/', '/${1}/${2}/${3}/', $post->post_date ) . $ghost_slug;

			// shortcut to new url:
			$url_to = '/?name=' . $post->post_name;

			$this->logger->log( $this->log, 'url_from: ' . $url_from );
			$this->logger->log( $this->log, 'url_to: ' . $url_to );

			$this->set_redirect( $url_from, $url_to, 'posts', true );

		});

		// -- Tags to Categories

/*
select *
from wp_termmeta tm
join wp_terms t on t.term_id = tm.term_id
where tm.meta_key = 'newspack_ghostcms_slug'
order by meta_value
*/

		// -- WP USERS

/*
select *
from wp_usermeta um
join wp_users u on u.ID = um.user_id
where um.meta_key = 'newspack_ghostcms_slug'
and um.meta_value <> u.user_nicename
order by meta_value
*/

		// -- CAP GAs

/*
select distinct pm.meta_value, p.post_name, pm2.meta_value
from wp_postmeta pm
join wp_posts p on p.ID = pm.post_id and p.post_type = 'guest-author'
join wp_postmeta pm2 on pm2.post_id = p.ID and pm2.meta_key = 'cap-user_login'
where pm.meta_key = 'newspack_ghostcms_slug'
;

*/
		
		$this->logger->log( $this->log, 'Done', $this->logger::SUCCESS );

	}

	/**
	 * REDIRECTION FUNCTIONS
	 */

	 private function set_redirect( $url_from, $url_to, $batch, $verbose = false ) {

		global $wpdb;

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		
		// The function (get_for_matched_url) returns strange "regex" matches that aren't matches!
		// $matches = \Red_Item::get_for_matched_url( $url_from );
		// instead, do directly.
		$exists = $wpdb->get_var( $wpdb->prepare( "
			SELECT 1
			FROM {$wpdb->prefix}redirection_items
			WHERE match_url = %s
			AND status='enabled'
			LIMIT 1",
			$url_from
		));

		if( ! empty( $exists  ) ) {

			if( $verbose ) $this->mylog( 'Skipping redirect (exists): ' . $url_from . ' to ' . $url_to );
			return;

		}

		if( $verbose ) $this->mylog( 'Adding (' . $batch . ') redirect: ' . $url_from . ' to ' . $url_to );
		
		$this->redirection_logic->create_redirection_rule(
			'Old site (' . $batch . ')',
			$url_from,
			$url_to
		);

		return;

	}


	/**
	 * LOGGING AND REPORTING
	 */

	private function mylog( $key, $value = '', $level = 'line' ) {
		
		$str = ( empty( $value ) ) ? $key : $key . ': ' . $value;

		$this->logger->log( $this->log, $str, $level );
		$this->report_add( $this->report, $key );

	}

	private function report_add( &$report, $key ) {
		if( empty( $report[$key] ) ) $report[$key] = 0;
		$report[$key]++;
	}
	

	private function get_or_import_url( $path, $title, $caption = null, $description = null, $alt = null ) {

		global $wpdb;

		// have to check if alredy exists so that multiple calls dont download() files already inserted
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment' and post_title = %s
		", $title ));

		if( is_numeric( $attachment_id ) && $attachment_id > 0 ) {
			$this->logger->log( $this->log, 'Found existing image.' );
			return $attachment_id;
		}

		// this function will check if existing, but only after re-downloading
		return $this->attachments_logic->import_external_file(  $path, $title, $caption, $description, $alt );

	}

	private function fetch_and_replace_link_in_content( $link, $post_content ) {

		$this->logger->log( $this->log, 'Replacing link: ' . $link );

		$attachment_id = $this->get_or_import_url( $link, $link );

		if( ! is_numeric( $attachment_id ) || ! ( $attachment_id > 0 ) ) {
	
			$this->logger->log( $this->log, 'Import external file failed.  Did not replace.', $this->logger::WARNING );
			return $post_content;

		}
			
		$post_content = str_replace( $link, wp_get_attachment_url( $attachment_id ), $post_content );
	
		$this->logger->log( $this->log, 'Link replaced.' );
	
		return $post_content;

	}





	/**
	 * LOGGGING
	 */



	private function report( $key ) {

		if ( ! isset( $this->report[ $key ] ) ) {
			$this->report[ $key ] = 0;
		}

		$this->report[ $key ]++;

	}







}

