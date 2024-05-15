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

	private $attachments_logic;
	private $crawler;
	private $logger;
	private $posts_logic;
	private $redirection_logic;

	private $log;
	private $report = [];
	
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

		// WP_CLI::add_command(
		// 	'newspack-content-migrator ourweekly2024-categories',
		// 	[ $this, 'cmd_ourweekly2024_categories' ],
		// 	[
		// 		'shortdesc' => 'Fix category mixup.',
		// 	]
		// );


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

		$args = array(
			// Must be newly imported post
			'meta_key'    => 'newspack_ghostcms_slug',
		);

		// get all posts that have the ghost id postmeta
		$this->posts_logic->throttled_posts_loop( $args, function( $post ) {
			
			$ghost_slug = get_post_meta( $post->ID, 'newspack_ghostcms_slug', true );

			if( $ghost_slug == $post->post_name ) return;

			$date_string = preg_replace( '/^(\d{4})-(\d{2})-(\d{2}).*/', '/${1}/${2}/${3}/', $post->post_date );
			$old = $date_string . $ghost_slug;
			$new = '/?name=' . $post->post_name;

			$this->logger->log( $this->log, '-------- post id: ' . $post->ID );
			$this->logger->log( $this->log, 'old: ' . $old );
			$this->logger->log( $this->log, 'new: ' . $new );

			// $this->set_redirect( $url_from, $url_to, $batch, true );


		});

		// $old_permalink = '/reporting/' . $columns['Slug'] . '/';
		// $new_permalink = str_replace( get_site_url() , '', get_permalink( $post_id ) );

		// if( $old_permalink != $new_permalink ) {
		// 	$this->set_redirect( $old_permalink, $new_permalink, 'posts' );
		// }

		// if( $cat_slug != $term->slug ) $this->set_redirect( '/category/' . $cat_slug, '/category/' . $term->slug, 'categories' );
		
		$this->logger->log( $this->log, 'Done', $this->logger::SUCCESS );

	}

	/**
	 * REDIRECTION FUNCTIONS
	 */

	 private function set_redirect( $url_from, $url_to, $batch, $verbose = false ) {

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		
		if( ! empty( \Red_Item::get_for_matched_url( $url_from ) ) ) {

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

}

