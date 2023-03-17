<?php

namespace NewspackCustomContentMigrator\Command\General;

use \CoAuthors_Guest_Authors;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts;
use \WP_CLI;

class ProfilePress implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Posts $posts_logic
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->posts_logic         = new Posts();
	}

	/**
	 * Sets up Co-Authors Plus plugin dependencies.
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
			'newspack-content-migrator profilepress-authors-to-guest-authors',
			[ $this, 'cmd_pp_authors_to_gas' ],
			[
				'shortdesc' => 'Converts Profile Press authors to CAP GAs.',
			]
		);
	}

	/**
	 * Convert PP Authors to GAs.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_pp_authors_to_gas( $pos_args, $assoc_args ) {
		// $email              = isset( $assoc_args['email'] ) ? $assoc_args['email'] : null;
		echo 123;

		// Example of user with meta
		/**
		 * Dennis Wagner
		 * https://thecoronadonews.com/wp-admin/term.php?taxonomy=author&tag_ID=98&post_type=post&wp_http_referer=%2Fwp-admin%2Fedit-tags.php%3Ftaxonomy%3Dauthor
		 *
		 */
		// + mandatory fields
		// + extra meta fields
		//      no metas https://thecoronadonews.com/wp-admin/term.php?taxonomy=author&tag_ID=106&post_type=post&wp_http_referer=%2Fwp-admin%2Fedit-tags.php%3Ftaxonomy%3Dauthor
		// + avatar
		//      no avatar
		// + MAPPED User
		//      no mapped user https://thecoronadonews.com/wp-admin/term.php?taxonomy=author&tag_ID=106&post_type=post&wp_http_referer=%2Fwp-admin%2Fedit-tags.php%3Ftaxonomy%3Dauthor

		// Get example posts
		// regular user assignment
		//      https://thecoronadonews.com/wp-admin/edit.php?ppma_author=craig-harris
		//      https://thecoronadonews.com/wp-admin/post.php?post=17959&action=edit
		// no mapped user, metas, avatar
		//      https://thecoronadonews.com/wp-admin/edit.php?ppma_author=defense-visual-information-distribution-service
		//      https://thecoronadonews.com/wp-admin/post.php?post=20146&action=edit

		// ------------------

		$this->posts_logic->get_all_posts_ids();

		// Find assigned author and all metas
			// regular user assignment -- https://thecoronadonews.com/wp-admin/post.php?post=17959&action=edit
			// no mapped user, metas, avatar -- https://thecoronadonews.com/wp-admin/post.php?post=20146&action=edit

		// Get existing GA or create it.

		// Link to WP User.

		// Assign to Post.
	}
}
