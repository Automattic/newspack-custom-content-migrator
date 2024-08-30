<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Spheres of Influence.
 */
class RevealMigrator implements InterfaceCommand {
	// Logs.
	const CREDITS_LOGS = 'reveal_posts_credit.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Class constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
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
			'newspack-content-migrator reveal-migrate-posts-credit-from-meta',
			array( $this, 'reveal_migrate_posts_credit_from_meta' ),
			array(
				'shortdesc' => 'Migrate posts credit from meta to the end of the article.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator reveal-migrate-posts-credit-from-meta`.
	 */
	public function reveal_migrate_posts_credit_from_meta() {
		global $wpdb;

		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			),
			function ( $post ) use ( $wpdb ) {
				$credits = get_post_meta( $post->ID, 'item_credits', true );
				if ( ! $credits ) {
					$this->log( self::CREDITS_LOGS, sprintf( "The post #%d doesn't have credits meta.", $post->ID ) );
					return;
				}

				$credits_block = preg_replace( '/<p>(.*?)<\/p>/', '<!-- wp:paragraph --><p><em>$1</em></p><!-- /wp:paragraph -->', $credits );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$wpdb->prefix . 'posts',
					array( 'post_content' => $post->post_content . $credits_block ),
					array( 'ID' => $post->ID )
				);

				$this->log( self::CREDITS_LOGS, sprintf( 'Updated post: %d', $post->ID ) );
			}
		);
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
