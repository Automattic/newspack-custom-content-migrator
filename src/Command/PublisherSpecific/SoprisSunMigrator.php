<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Sopris Sun.
 */
class SoprisSunMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 *  @var CoAuthorPlusLogic $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
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
			'newspack-content-migrator soprissun-assign-posts-from-letters-to-specific-cap',
			[ $this, 'cmd_assign_posts_from_letters' ],
		);
	}

	public function cmd_assign_posts_from_letters( $args, $assoc_args ) {
		/**
		 * Hardcoded category ID 10 and GA ID 52161.
		 */
		$args = array(
			'post_type' => 'post',
			'cat'       => 10,  // Category ID
			'fields'    => 'ids',
			'posts_per_page' => -1,
		);
		$query = new \WP_Query($args);
		$post_ids = $query->posts;
		foreach ( $post_ids as $post_id ) {
			$this->coauthorsplus_logic->assign_guest_authors_to_post( [ 52161 ], $post_id, $append_to_existing_users = false );
			\WP_CLI::success( $post_id );
		}
	}
}
