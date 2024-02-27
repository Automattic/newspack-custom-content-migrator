<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Underscore News.
 */
class UnderscoreNewsMigrator implements InterfaceCommand {

	private static $instance = null;

	private $attachments_logic;
	private $coauthorsplus_logic;
	private $logger;
	private $redirection_logic;

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
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {

		WP_CLI::add_command(
			'newspack-content-migrator underscorenews-import-categories',
			[ $this, 'cmd_import_categories' ],
			[
				'shortdesc' => 'Imports Categories from CSV.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-categories',
						'description' => 'CSV file: --csv-categories="path/categories.csv"',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator underscorenews-import-posts',
			[ $this, 'cmd_import_posts' ],
			[
				'shortdesc' => 'Imports Posts from CSV using 3 CSVs.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-posts',
						'description' => 'CSV file: --csv-posts="path/posts.csv"',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'csv-team',
						'description' => 'CSV file: --csv-team="path/team.csv"',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

	}

	public function cmd_import_categories( $pos_args, $assoc_args ) {

		$csv_categories = $this->get_csv_handle( $assoc_args[ 'csv-categories' ], 10 );

		global $wpdb;

		$log = 'underscorenews_' . __FUNCTION__ . '.txt';
        $this->logger->log( $log , 'Starting ...' );

		// import categories
		$header = true;
		while( false !== ( $row = fgetcsv( $csv_categories ) ) ) {
			
			// skip header
			if( $header ) {
				$header = false;
				continue;
			}

			// create the category based on old name and slug
			$inserted_term = wp_insert_term( $row[0], 'category', array( 'slug' => $row[1] ) );

			// Check for errors
			if ( is_wp_error( $inserted_term ) ) {
				
				$this->logger->log( $log, $inserted_term->get_error_code() . ': ' . $row[0], $this->logger::WARNING );
				continue;

			}

			break;

		}

		WP_CLI::success( "Done." );

	}

	public function cmd_import_posts( $pos_args, $assoc_args ) {

		// needs coauthors plus plugin
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		// make sure Redirects plugin is active
		// if( ! class_exists ( '\Red_Item' ) ) {
		// 	WP_CLI::error( 'Redirection plugin must be active.' );
		// }

		// load csvs
		$csv_posts = $this->get_csv_handle( $assoc_args[ 'csv-posts' ], 22 );
		$csv_team = $this->get_csv_handle( $assoc_args[ 'csv-team' ], 12 );
		$team = $this->csv_to_hash( $csv_team, 1, true );

		global $wpdb;

		$log = 'underscorenews_' . __FUNCTION__ . '.txt';
        $this->logger->log( $log , 'Starting ...' );

		$report = [];

		// import categories
		$header = null;
		while( false !== ( $row = fgetcsv( $csv_posts ) ) ) {

			// store header
			if( null == $header ) {
				$header = $row;
				continue;
			}

			// convert row to columns
			$columns = [];
			for( $i = 0; $i < count( $header ); $i++ ) {
				$columns[$header[$i]] = $row[$i];
			}

			$this->logger->log( $log, 'Processing old site id: ' . $columns['Item ID'] );
			$this->report_add( $report, 'Processing old site id' );

			// check if post already exists
			$post_id = $wpdb->get_var( $wpdb->prepare( "
				SELECT post_id FROM {$wpdb->postmeta} where meta_key = 'newspack_underscore_old_site_id' and meta_value = %s LIMIT 1
			", $columns['Item ID'] ));

			// use existing post
			if( is_numeric( $post_id ) && $post_id > 0 ) {

				$this->logger->log( $log, 'Post exists: ' . $post_id );
				$this->report_add( $report, 'Post exists.' );
	
			}
			// create post
			else {

				$post_id = wp_insert_post( array(
					'post_author'   => 1,
					'post_content'  => $columns['Post Body'],
					'post_date_gmt' => trim( preg_replace( '(Coordinated Universal Time)', '', $columns['Publish Date'] ) ),
					'post_status'   => 'publish',
					'post_title'    => $columns['Name'],
					'post_type'     => 'post',
				));
			
				if( ! is_numeric( $post_id ) || ! ( $post_id > 0 ) ) {

					$this->logger->log( $log, 'SKIP: post insert failed for old site id: ' . $columns['Item ID'], $this->logger::WARNING );
					$this->report_add( $report, 'SKIP: post insert failed for old site id' );
					continue;

				}

				update_post_meta( $post_id, 'newspack_underscore_old_site_id', $columns['Item ID'] );

				$this->logger->log( $log, 'Post inserted: ' . $post_id );
				$this->report_add( $report, 'Post inserted.' );

			} // create post

			$post = get_post( $post_id );

			// parse and update images and files (PDF) in body content
			preg_match_all( '/<(a|img) [^>]*?>/i', $post->post_content, $elements );
			// foreach( $elements[0] as $element ) {
			// 	if( preg_match( '/^<a /', $element ) ) $this->media_parse_element( $element, 'href', $post_id, $log, $report );
			// 	else if( preg_match( '/^<img /', $element ) ) $this->media_parse_element( $element, 'src', $post_id, $log, $report );
			// }
	
			// parse post_content was updated via DB updates, so update post object
			// wp_cache_flush();
			// $post = get_post( $post_id );
			
			// Attach featured image
			// if( preg_match( '/^https/', $columns['Main Image'] ) ) {
				
			// 	// get existing or upload new
			// 	$featured_image_id = $this->attachments_logic->import_external_file( 
			// 		$columns['Main Image'], $columns['Main Image'], 
			// 		$columns['Main image alt text'], $columns['Main image alt text'], $columns['Main image alt text'], 
			// 		$post_id
			// 	);

			// 	if( is_numeric( $featured_image_id ) && $featured_image_id > 0 ) {
			
			// 		$this->logger->log( $log, 'Featured image: ' . $featured_image_id );
			// 		$this->report_add( $report, 'Featured image.' );

			// 		update_post_meta( $post_id, '_thumbnail_id', $featured_image_id );
			
			// 	}

			// }

			// set CAP GAs
			if( ! empty( $columns['Author'] ) ) {
				
				print_r( $team[$columns['Author']] );
	
			}

			exit();








// redirect:
// 'post_name'     => $columns['Slug'],
// /reporting/, /team/, /work/


		
			// Relationships:
			$post['categories'] = $columns['Category']; // these match to the Category csv
		
			// Post Meta:
			$post['sub-title'] = $columns['Sub-title'];
			$post['old_id'] = $columns['Item ID'];
			$post['old_url'] = '/reporting/' . $columns['Slug'];
			
			// Ignore:
			// Collection ID - same for all, assumed to be Site/Publiser ID
			// Created On - use Publish Date instead
			// Updated On - use Publish Date instead
			// Published On - use Publish Date instead
			// Latest? - ignore - looks like a homepage section display boolean or add as Tag
			// Facebook Share Link - ignore
			// Twitter Share Link - ignore
			
			// Possibly Convert to Tags:
			// Sovereign Justice DDRP Series (https://www.underscore.news/work/special-series-sovereign-justice) - series linking is already in post content
			// Featured Special Report - looks like one of the other "work"s. - convert to category/tag
			// Series Order - this seems to control the "work" article order - in wordpress this will probably just the publish date
		
			// Unknown:
			// Featured?
			// Redirect URL => these don't look to be redirecting out, nor capturing incoming traffic
		
			
		}

		WP_CLI::success( "Done." );

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


	/**
	 * REPORTING
	 */

	private function report_add( &$report, $key ) {
		if( empty( $report[$key] ) ) $report[$key] = 0;
		$report[$key]++;
	}
	

	/**
	 * FILE FUNCTIONS
	 */

	private function get_csv_handle( $csv_path, $column_count = null ) {

		if( ! is_file( $csv_path ) ) {
			WP_CLI::error( 'Could not find CSV at path: ' . $csv_path );
		}
		
		$handle = fopen( $csv_path, 'r' );

		if ( $handle == FALSE ) {
			WP_CLI::error( 'Could not fopen CSV at path: ' . $csv_path );
		}

		if( $column_count > 0 ) {

			while( false !== ( $row = fgetcsv( $handle ) ) ) {

				if( $column_count != count( $row ) ) {
					WP_CLI::error( 'Error row column count mismatch: ' . $csv_path );
				}
			}

			rewind( $handle );

		}

		return $handle;
		
	}

	private function csv_to_hash( $handle, $format = 0, $has_header = false ) {

		$with_header = ( function( $header, $row ) {
			$output = [];
			for( $i = 0; $i < count( $header ); $i++ ) {
				$output[$header[$i]] = $row[$i];
			}
			return $output;
		});
		
		$output = array();
		$header = null;
		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {

			if( $has_header && null == $header ) {
				$header = $row;
				continue;
			}

			// formats
			if( is_numeric( $format ) ) {
				$output[$row[$format]] = ( $has_header ) ? $with_header( $header, $row ) : $row;
			}
			
		}

		return $output;

	}


	/**
	 * MEDIA PARSING
	 */

	private function media_parse_element( $element, $attr, $post_id, $log, &$report ) {

		global $wpdb;

		$link = $this->media_parse_link( $element, $attr, $log, $report );
		if( empty( $link ) ) return;

		$this->logger->log( $log, 'Link found in element: ' . $link );
		$this->report_add( $report, $attr . '_element_to_fix' );

		// get existing or upload new
		$attachment_id = $this->attachments_logic->import_external_file( 
			$link, $link,
			null, null, null, 
			$post_id
		);

		if( !is_numeric( $attachment_id ) || ! ( $attachment_id > 0 ) ) {
	
			$this->logger->log( $log, 'Import element link failed: ' . $link, $this->logger::WARNING );
			$this->report_add( $report, $attr . '_element_import_failed' );
			return;

		}
		
		// replace post content
		$wpdb->query( $wpdb->prepare( "
			UPDATE {$wpdb->posts} 
			SET post_content = REPLACE(post_content, %s, %s)
			WHERE ID = %d
		", $link, wp_get_attachment_url( $attachment_id ), $post_id ) );

		$this->logger->log( $log, 'Link replaced in element: ' . $link );
		$this->report_add( $report, $attr . '_element_replaced' );

	}

	private function media_parse_link( $element, $attr, $log, &$report ) {

		// test (and temporarily fix) ill formatted elements
		$had_line_break = false;
		if( preg_match( '/\n/', $element ) ) {
			$element = preg_replace('/\n/', '', $element );
			$had_line_break = true;
		}

		// parse URL from the element
		if( ! preg_match( '/' . $attr . '=[\'"](.+?)[\'"]/', $element, $url_matches ) ) {
			// $this->logger->log( $log, 'Null link found in element:' . $element, $this->logger::WARNING );
			$this->report_add( $report, $attr . '_element_null_link' );
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

		// must start with http(s)://
		if( ! preg_match( '/^https?:\/\//', $url ) ) {
			// $this->logger->log( $log, 'Non http link not found in element:' . $element, $this->logger::WARNING );
			$this->report_add( $report, $attr . '_element_non_http_link' );
			return;
		}

		// we're only looking for media (must have an extension), else skip
		if( ! preg_match( '/\.([A-Za-z0-9]{3,4})$/', $url, $ext_matches ) ) return;

		// ignore certain extensions that are not media files
		if( in_array( $ext_matches[1], array( 'asp', 'aspx', 'com', 'edu', 'htm', 'html', 'net', 'org', 'php' ) ) ) return;

		// only match certain domains
		$keep_domains = [
			'uploads-ssl.webflow.com',
		];

		if( ! preg_match('/^https?:\/\/(' . implode( '|', $keep_domains ) . ')/', $url ) ) {
			// $this->logger->log( $log, 'Domain link not found in element:' . $element, $this->logger::WARNING );
			$this->report_add( $report, $attr . '_element_non_domain_link' );
			return;
		}

		// todo: handle issues previously bypassed
		if( $had_line_break || $had_leading_whitespace || $had_trailing_whitespace ) {
			// $this->logger->log( $log, 'Whitespace link issue found in element:' . $element, $this->logger::WARNING );
			$this->report_add( $report, $attr . '_element_whitspace_link' );
			return;
		}

		return $url;

	}

}

