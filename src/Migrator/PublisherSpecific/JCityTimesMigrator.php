<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Washington Monthly.
 */
class JCityTimesMigrator implements InterfaceMigrator {
	const ARCHIVE_POSTS_LOG = 'archive_posts.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator jcitytimes-migrate-archive-posts-to-private',
			[ $this, 'cmd_migrate_archive_posts_to_private' ],
			[
				'shortdesc' => 'Migrate archive posts to private.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator jcitytimes-migrate-archive-posts-to-private`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_migrate_archive_posts_to_private( $args, $assoc_args ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$archive_posts = $wpdb->get_results( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'archive'" );

		foreach ( $archive_posts as $archive_post ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $wpdb->posts, [ 'post_status' => 'private' ], [ 'ID' => $archive_post->ID ] );
			$this->log( self::ARCHIVE_POSTS_LOG, sprintf( 'Post #%s set to private.', $archive_post->ID ) );
		}
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message, $to_cli = true ) {
		if ( $to_cli ) {
			\WP_CLI::line( $message );
		}

		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
