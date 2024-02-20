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
			'newspack-content-migrator underscorenews-',
			[ $this, 'cmd_' ],
			[
				'shortdesc' => '',
			]
		);

	}

	public function cmd_( $pos_args, $assoc_args ) {

		// needs coauthors plus plugin
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}

		global $wpdb;

		$log = 'underscorenews_' . __FUNCTION__ . '.txt';
        $this->logger->log( $log , 'Starting ...' );

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

}
