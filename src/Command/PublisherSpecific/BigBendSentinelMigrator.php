<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Big Bend Sentinel.
 */
class BigBendSentinelMigrator implements InterfaceCommand {

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
			'newspack-content-migrator bigbendsentinel-convert-people-cpt',
			[ $this, 'cmd_convert_people_cpt' ],
			[
				'shortdesc' => 'Convert People CPT to Co-Authors Plus and add Redirects.',
			]
		);

	}

	public function cmd_convert_people_cpt( $pos_args, $assoc_args ) {

		// needs coauthors plus plugin
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		// log file
		$log = 'bigbendsentinel_' . __FUNCTION__ . '.txt';

        $this->logger->log( $log , 'Starting conversion of People CPT to GAs.' );

		global $wpdb;

		$people = $wpdb->get_results("
			SELECT t.name, t.slug
			FROM {$wpdb->term_taxonomy} tt
			join {$wpdb->terms} t on t.term_id = tt.term_id
			where tt.taxonomy in( 'people' )
		");

        $this->logger->log( $log, 'Found: ' . count( $people ) );

		foreach( $people as $person ) {

			$this->logger->log( $log, 'Converting: ' . $person->name . '; ' . $person->slug );

			// maybe GA is already doing this...No, it's only checking login, it's not checking URL
			// skip if a wp user is already using preferred url: /author/user_nicename
			if( get_user_by( 'slug', $person->slug ) ) {
				$this->logger->log( $log, 'Skip: WP User slug already exists.', $this->logger::WARNING );
				continue;
			}

			// get or create GA
			$ga_id = $this->coauthorsplus_logic->create_guest_author( [ 'display_name' => $person->name, 'user_login' => $person->slug ] );

			// get the ga object
			$ga_obj = $this->coauthorsplus_logic->get_guest_author_by_id( $ga_id );

			// maybe GA is already doing this...No, it's only checking login, it's not checking URL
			// skip if a wp user is already using preferred url: /author/user_nicename
			if( get_user_by( 'slug', $ga_obj->user_login ) ) {
				$this->logger->log( $log, 'Skip: WP User slug already exists.', $this->logger::WARNING );
				continue;
			}

			// create a Redirect
			$this->set_redirect( '/people/' . $person->slug, '/author/' . $ga_obj->user_login, 'people' );			
			
			// assign posts

			// get any remaining usermeta and/or ACF data into GA object
			
		}

        WP_CLI::success( "Done." );

	}

	/**
	 * REDIRECTION FUNCTIONS
	 */

	private function set_redirect( $url_from, $url_to, $batch, $verbose = false ) {

		if( ! empty( \Red_Item::get_for_matched_url( $url_from ) ) ) {

			if( $verbose ) WP_CLI::warning( 'Skipping redirect (exists): ' . $url_from . ' to ' . $url_to );
			return;

		}

		if( $verbose ) WP_CLI::line( 'Adding (' . $batch . ') redirect: ' . $url_from . ' to ' . $url_to );
		
		$this->redirection_logic->create_redirection_rule(
			'Old site (' . $batch . '): ' . $url_from,
			$url_from,
			$url_to
		);

		return;

	}

}
