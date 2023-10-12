<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for The City.
 */
class TheCityMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * PostsLogic instance.
	 *
	 * @var PostsLogic PostsLogic instance.
	 */
	private $posts;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts = new PostsLogic();
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
			self::$instance->coauthorsplus_logic = new CoAuthorPlusLogic();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator thecity-transform-blocks-wpciviliframe-to-newspackiframe',
			[ $this, 'cmd_wpciviliframe_to_newspackiframe' ],
		);

	}

	public function cmd_wpciviliframe_to_newspackiframe( array $pos_args, array $assoc_args ): void {
		
	}
}
