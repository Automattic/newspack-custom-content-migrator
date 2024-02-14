<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Big Bend Sentinel.
 */
class BigBendSentinelMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
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
				'shortdesc' => 'Convert People CPT to Co-Authors Plus.',
			]
		);

	}

	public function cmd_convert_people_cpt( $pos_args, $assoc_args ) {

        WP_CLI::line( "Doing convert people cpt." );

        WP_CLI::success( "Done." );

	}

}
