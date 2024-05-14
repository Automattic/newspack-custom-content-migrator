<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Our Weekly (in 2024 from Ghost CMS).
 */
class OurWeekly2024Migrator implements InterfaceCommand {

	private static $instance = null;

	private $attachments_logic;
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


			// loop and replace media
			$post_content = $this->media_parse_content( $post->post_content );

		});

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
	
	/**
	 * MEDIA PARSING
	 */

	private function media_parse_content( $content ) {

		// parse and import images and files body content <img src/srcset, <a href=...PDF, <iframe src
		preg_match_all( '/<(a|iframe|img) ([^>]+)>/i', $content, $elements, PREG_SET_ORDER );

		// foreach( $elements as $element ) {
		// 	$this->logger->log( $this->log . '-html-' . $element[1], $element[2] );
		// }
		// return;

		// a href= https://www.ourweekly.com/

		// need to list img urls and srcsets
		
		// exit();



		foreach( $elements as $element ) {

			if( preg_match( '/^<a /', $element[0] ) ) {
				$content = $this->media_parse_element( $element[0], 'href', $content );
			}
			else if( preg_match( '/^<img /', $element[0] ) ) {
				$content = $this->media_parse_element( $element[0], 'src', $content );
				// get srcsets??
				// $content = $this->media_parse_element( $element[0], 'srcset', $content );
			}
			else if( preg_match( '/^<iframe /', $element[0] ) ) {
				$content = $this->media_parse_element( $element[0], 'src', $content );
			}

		}

		return $content;

	}

	private function media_parse_element( $element, $attr, $content ) {

		$link = $this->media_parse_link( $element, $attr );
		if( empty( $link ) ) return $content;

		$this->mylog( $attr . ' link found in element', $link );

		// get existing or upload new
		$attachment_id = $this->get_or_import_url( $link, $link );

		if( ! is_numeric( $attachment_id ) || ! ( $attachment_id > 0 ) ) {
	
			$this->mylog( $attr . ' import external file failed', $link, $this->logger::WARNING );
			return $content;

		}
		
		$content = str_replace( $link, wp_get_attachment_url( $attachment_id ), $content );

		$this->mylog( $attr . ' link replaced in element', $link );

		return $content;


	}

	private function media_parse_link( $element, $attr ) {

		// test (and temporarily fix) ill formatted elements
		$had_line_break = false;
		if( preg_match( '/\n/', $element ) ) {
			$element = preg_replace('/\n/', '', $element );
			$had_line_break = true;
		}

		// parse URL from the element
		if( ! preg_match( '/' . $attr . '=[\'"](.+?)[\'"]/', $element, $url_matches ) ) {
			$this->mylog( $attr . ' null link found in element', $element, $this->logger::WARNING );
			return;
		}

		// set easy to use variable
		$url = $url_matches[1];

		// test (and temporarily fix) ill formatted links
		$had_leading_whitespace = false;
		if( preg_match( '/^\s+/', $url ) ) {
			$url = preg_replace('/^\s+/', '', $url );
			$had_leading_whitespace = true;
		}

		// test (and temporarily fix) ill formatted links
		$had_trailing_whitespace = false;
		if( preg_match( '/\s+$/', $url ) ) {
			$url = preg_replace('/\s+$/', '', $url );
			$had_trailing_whitespace = true;
		}

		// skip known off-site urls and other anomalies
		$skips = array(
			'https?:\/\/(docs.google.com|player.vimeo.com|w.soundcloud.com|www.youtube.com)',
			'mailto',
		);
		
		if( preg_match( '/^(' . implode( '|', $skips ) . ')/', $url ) ) return;

		// we're only looking for media (must have an extension), else skip
		if( ! preg_match( '/\.([A-Za-z0-9]{3,4})$/', $url, $ext_matches ) ) return;

		// ignore certain extensions that are not media files
		if( in_array( $ext_matches[1], array( 'asp', 'aspx', 'com', 'edu', 'htm', 'html', 'net', 'news', 'org', 'php' ) ) ) return;

		// must start with http(s)://
		if( ! preg_match( '/^https?:\/\//', $url ) ) {
			$this->mylog( $attr . ' non-https link found in element', $element, $this->logger::WARNING );
			return;
		}
		
		// only match certain domains
		$keep_domains = [
			'uploads-ssl.webflow.com',
		];

		if( ! preg_match('/^https?:\/\/(' . implode( '|', $keep_domains ) . ')/', $url ) ) {
			// $this->mylog( $attr . ' off domain link found in element', $element, $this->logger::WARNING );
			return;
		}

		// todo: handle issues previously bypassed
		if( $had_line_break || $had_leading_whitespace || $had_trailing_whitespace ) {
			$this->mylog( $attr . ' whitespace found in element', $element, $this->logger::WARNING );
			return;
		}

		return $url;

	}

	private function get_or_import_url( $path, $title, $caption = null, $description = null, $alt = null ) {

		global $wpdb;

		// have to check if alredy exists so that multiple calls dont download() files already inserted
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT ID
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment' and post_title = %s
		", $title ));

		if( is_numeric( $attachment_id ) && $attachment_id > 0 ) return $attachment_id;

		// this function will check if existing, but only after re-downloading
		return $this->attachments_logic->import_external_file(  $path, $title, $caption, $description, $alt );

	}

}

