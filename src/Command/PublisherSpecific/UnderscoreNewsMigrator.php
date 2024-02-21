<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Underscore News.
 */
class UnderscoreNewsMigrator implements InterfaceCommand {

	private static $instance = null;

	private $coauthorsplus_logic;
	private $logger;
	private $redirection_logic;

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
				'shortdesc' => 'Imports Posts from CSV.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-posts',
						'description' => 'CSV file: --csv-posts="path/posts.csv"',
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
		// if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
		// 	WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		// }

		// make sure Redirects plugin is active
		// if( ! class_exists ( '\Red_Item' ) ) {
		// 	WP_CLI::error( 'Redirection plugin must be active.' );
		// }

		// load csvs
		$csv_posts = $this->get_csv_handle( $assoc_args[ 'csv-posts' ], 22 );

		global $wpdb;

		$log = 'underscorenews_' . __FUNCTION__ . '.txt';
        $this->logger->log( $log , 'Starting ...' );

		// import categories
		$header = null;
		while( false !== ( $row = fgetcsv( $csv_posts ) ) ) {

			// store header
			if( null == $header ) {
				$header = $row;
				continue;
			}

			// convert row to columns
			$column = [];
			for( $i = 0; $i < count( $header ); $i++ ) {
				$column[$header[$i]] = $row[$i];
			}

			
			use wxr so body images will be imported...


			// create post
			$post_data = array(
				'post_title'    => $column['Name'],
				'post_content'  => $column['Post Body'],
				'post_date_gmt' => trim( preg_replace( '(Coordinated Universal Time)', '', $column['Publish Date'] ) ),
				'post_name'     => $column['Slug'],
				'post_status'   => 'publish', // Set the status of the new post.
				'post_author'   => 1, // The user ID number of the author. (Adjust as necessary)
				'post_type'     => 'post', // Could be 'post' or 'page' or any custom post type.
				'post_category' => array(1), // Default category. The ID of the category for the post.
			);
		
			// Insert the post into the database
			$post_id = wp_insert_post($post_data);
		
			// Check if post was successfully inserted
			if (!is_wp_error($post_id)) {
				echo "Post created successfully with ID: $post_id";
			} else {
				echo "Error in post creation: " . $post_id->get_error_message();
			}			
		
			// Image:
			$post['featured-image'] = $column['Main Image'];
			$post['featured-image-alt'] = $column['Main image alt text'];
		
			// Relationships:
			$post['post_author'] = $column['Author']; // these match to the Team csv
			$post['categories'] = $column['Category']; // these match to the Category csv
		
			// Post Meta:
			$post['sub-title'] = $column['Sub-title'];
			$post['old_id'] = $column['Item ID'];
			$post['old_url'] = '/reporting/' . $column['Slug'];
			
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

		// Check if exists
		if( ! empty( \Red_Item::get_for_matched_url( $url_from ) ) ) {

			if( $verbose ) WP_CLI::warning( 'Skipping redirect (exists): ' . $url_from . ' to ' . $url_to );
			return;

		}

		// Add new
		if( $verbose ) WP_CLI::line( 'Adding (' . $batch . ') redirect: ' . $url_from . ' to ' . $url_to );
		
		$this->redirection_logic->create_redirection_rule(
			'Old site (' . $batch . '): ' . $url_from,
			$url_from,
			$url_to
		);

		return;

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

}
