<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts;
use \WP_CLI;

/**
 * Custom migration scripts for Block Club Chicago.
 */
class BlockClubChicagoMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Posts instance.
	 *
	 * @var Posts Posts instance.
	 */
	private $posts;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts = new Posts();
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
			'newspack-content-migrator blockclubchicago-migrate-subtitles',
			[ $this, 'cmd_migrate_subtitles' ],
			[
				'shortdesc' => 'Populate Newspack subtitles.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for 'newspack-content-migrator blockclubchicago-migrate-subtitles'.
	 *
	 * @param $pos_args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_migrate_subtitles( $pos_args, $assoc_args ) {
		$post_ids = $this->posts->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '%d/%d %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			$subtitle = get_post_meta( $post_id, 'dek', true );
			if ( ! empty( $subtitle ) ) {
				update_post_meta( $post_id, 'newspack_post_subtitle', $subtitle );
			}
		}

		wp_cache_flush();
		WP_CLI::line( "Done." );
	}
}
