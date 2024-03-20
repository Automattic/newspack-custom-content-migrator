<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

class SimplyGuestAuthorNameMigrator implements InterfaceCommand {

	private $log_file;

	/**
	 * @var CoAuthorPlus
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic = null;

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->logger              = new Logger();
		$this->posts_logic         = new PostsLogic();
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
			'newspack-content-migrator migrate-simply-guest-author-names',
			[ $this, 'cmd_migrate_simply_guest_author_names' ],
			[
				'shortdesc' => 'Migrate Simply Guest Author Names to CoAuthorsPlus.',
			]
		);

	}

	/**
	 * Migrate Simply Guest Author Names to CoAuthorsPlus.
	 */
	public function cmd_migrate_simply_guest_author_names( $args, $assoc_args ) {

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$this->log_file = str_replace(__NAMESPACE__ . '\\', '', __CLASS__) . '_' . __FUNCTION__ . '.log';

		$report = [
			'wp-user-missing' => 0,
			'sfly-mismatch' => 0,
			'sfly-exists' => 0,
			'gas-exist' => 0,
			'each' => [],
		];

		$this->logger->log( $this->log_file, 'Starting migration...' );

		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
				// Order by date desc so newest GAs created will have newest Bios.
				'orderby'     => 'date',
				'order'       => 'DESC',
				// Limit data payload from DB.
				'fields'      => 'ids',
			),
			function( $post_id ) use ( &$report ) {

				$single_report = '-';

				$this->logger->log( $this->log_file, 'Post id: ' . $post_id );
				
				// Post Author (WP User).
				$wp_user = get_userdata( get_post_field( 'post_author', $post_id ) );

				if( is_a( $wp_user, 'WP_user' ) ) {
					$this->logger->log( $this->log_file, 'WP User: ' . $wp_user->ID . ' / ' . $wp_user->display_name );
				}
				else {
					$this->logger->log( $this->log_file, 'WP User: (not exists)', $this->logger::WARNING );
					$report['wp-user-missing']++;
					$single_report .= 'wp-user-missing';
				}

				// Existing GAs.
				$existing_gas = array_map( function( $ga ) {
					return $ga->display_name;
				}, $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id ));
				
				if( ! empty( $existing_gas ) ) {
					$this->logger->log( $this->log_file, 'Existing GAs: ' . implode( ', ', $existing_gas ), $this->logger::WARNING );					
					$report['gas-exist']++;
					$single_report .= 'gas-exist';

				}

				// Simply meta.
				$sfly = array(
					'desc'  => trim( get_post_meta( $post_id, 'sfly_guest_author_description', true ) ),
					'email' => trim( get_post_meta( $post_id, 'sfly_guest_author_email', true ) ),
					'names' => trim( get_post_meta( $post_id, 'sfly_guest_author_names', true ) ),
					'link'  => trim( get_post_meta( $post_id, 'sfly_guest_link', true ) ),
				);
				
				$sfly['names'] = preg_replace( '/^by\s+/i', '', $sfly['names'] );
				
				if( ! empty( $sfly['names'] ) ) {

					$this->logger->log( $this->log_file, 'Simply Names: ' . $sfly['names'] );
					$report['sfly-exists']++;
					$single_report .= 'sfly-exists';

				}
				else if( ! empty( $sfly['desc'] ) || ! empty( $sfly['email'] )  || ! empty( $sfly['link'] ) ) {

					$this->logger->log( $this->log_file, 'Simply Names Mismatch: ' . print_r( $sfly, true ), $this->logger::WARNING );
					$report['sfly-mismatch']++;

				}

				// Reporting.
				$this->logger->log( $this->log_file, 'Report: ' . $single_report );

				if( ! isset( $report['each'][$single_report] ) ) $report['each'][$single_report] = 0;
				$report['each'][$single_report]++;

				// Skip if no Simply Name(s).
				if( empty( $sfly['names'] ) ) return;

				$this->logger->log( $this->log_file, 'Doing Updates.' );

				// Get existing GA if exists.
				// As of 2024-03-19 the use of 'coauthorsplus_logic->create_guest_author()' to return existing match
				// can not be trusted. WP Error occures if existing database GA is "Jon A. Doe / cap-jon-a-doe " but
				// new GA is "Jon A Doe". New GA will not match on display name, but will fail on create when existing
				// sanitized slug is found.
				$ga = $this->coauthorsplus_logic->get_guest_author_by_display_name( $sfly['names'] );

// this could be a single object, array of objects or null

				if( null == $ga ) {
					
					// Create.
					$ga_id = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $sfly['names'] ) );

					if( is_wp_error( $ga_id ) || ! is_numeric( $ga_id ) || ! ( $ga_id > 0 ) ) {
						$this->logger->log( $this->log_file, 'GA create failed.', $this->logger::WARNING );		
						return;
					}

					$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );

				}

				$this->logger->log( $this->log_file, 'GA ID: ' . $ga->ID );

				// Assign to post.
				$this->coauthorsplus_logic->assign_guest_authors_to_post( array ( $ga->ID ), $post_id );

				$this->logger->log( $this->log_file, 'Assigned GA to post.' );

				// Update Bio if not already set.
				if( ! empty( trim( $ga->description ) ) ) return;

				$this->logger->log( $this->log_file, 'GA Desc: ' . $ga->description );

				$new_desc = array();
				if( ! empty( $sfly['desc'] ) ) $new_desc[] = '<p>' . sanitize_textarea_field( $sfly['desc'] ) . '</p>';
				if( ! empty( $sfly['link'] ) ) $new_desc[] = '<p><a href="' . sanitize_url( $sfly['link'] ) . '">Link</a></p>';

				if( empty( $new_desc ) ) return;
				
				$this->logger->log( $this->log_file, 'New Desc: ' . implode( "", $new_desc ) );
				$this->coauthorsplus_logic->update_guest_author( $ga_id, array( 'description' => implode( "\n", $new_desc ) ) );
		
			},
			0
		);

		wp_cache_flush();

		$this->logger->log( $this->log_file, print_r( $report, true ) );

		$this->logger->log( $this->log_file, 'Done.', $this->logger::SUCCESS );

	}

}
