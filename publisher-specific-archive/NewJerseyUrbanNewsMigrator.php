<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for New Jersey Urban News.
 */
class NewJerseyUrbanNewsMigrator implements InterfaceCommand {

	CONST PARENT_ISSUES_CATEGORY = 'Magazine';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
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
			'newspack-content-migrator njurbannews-fix-image-blocks',
			[ $this, 'cmd_fix_image_blocks' ],
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_fix_image_blocks( $args, $assoc_args ) {

		// detect all img blocks
		// get img src
		// remove size from img
		// find img in db, get URL, get id
		// replace src
		// replace ids
		// update post

echo 123;
	}
}
