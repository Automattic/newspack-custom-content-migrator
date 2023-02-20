<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

/**
 * Custom migration scripts for VTDigger.
 */
class VTDiggerMigrator implements InterfaceCommand {

	const NEWS_BRIEFS_CAT_NAME = 'News Briefs';
	const LIVEBLOGS_CAT_NAME = 'Liveblogs';

	// Tells us which CPT this post was originally, e.g. 'liveblog'.
	const META_VTD_CPT = 'newspack_vtd_cpt';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
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
			'newspack-content-migrator vtdigger-migrate-newsbriefs',
			[ $this, 'cmd_newsbriefs' ],
			[
				'shortdesc' => 'Migrates the News Briefs CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-liveblogs',
			[ $this, 'cmd_liveblogs' ],
			[
				'shortdesc' => 'Migrates the Liveblog CPT to regular posts with category.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-newsbrief`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_newsbriefs( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_newsbriefs.log';

		// Get News Briefs category ID.
		$newsbriefs_cat_id = get_cat_ID( self::NEWS_BRIEFS_CAT_NAME );
		if ( ! $newsbriefs_cat_id ) {
			$newsbriefs_cat_id = wp_insert_category( [ 'cat_name' => self::NEWS_BRIEFS_CAT_NAME ] );
		}

		$newsbriefs_ids = $wpdb->get_col( "select ID from {$wpdb->posts} where post_type='news-brief';" );

		// Convert to 'post' type.
		foreach ( $newsbriefs_ids as $key_newsbrief_id => $newsbrief_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_newsbrief_id + 1, count( $newsbriefs_ids ), $newsbrief_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $newsbrief_id ) );

			// Set meta 'newspack_vtd_cpt' = 'news-brief';
			update_post_meta( $newsbrief_id, self::META_VTD_CPT, 'news-brief' );
		}

		$this->logger->log( $log, implode( ',', $newsbriefs_ids ), false );
		wp_cache_flush();

		// Assign category 'News Briefs'.
		WP_CLI::log( sprintf( "Assigning News Briefs cat ID %d ...", $newsbriefs_cat_id ) );
		foreach ( $newsbriefs_ids as $key_newsbrief_id => $newsbrief_id ) {
			wp_set_post_categories( $newsbrief_id, [ $newsbriefs_cat_id ] );
		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-liveblogs`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_liveblogs( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_liveblog.log';

		// Get Liveblogs category ID.
		$liveblogs_cat_id = get_cat_ID( self::LIVEBLOGS_CAT_NAME );
		if ( ! $liveblogs_cat_id ) {
			$liveblogs_cat_id = wp_insert_category( [ 'cat_name' => self::LIVEBLOGS_CAT_NAME ] );
		}

		$liveblogs_ids = $wpdb->get_col( "select ID from {$wpdb->posts} where post_type='liveblog';" );

		// Convert to 'post' type.
		foreach ( $liveblogs_ids as $key_liveblog_id => $liveblog_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_liveblog_id + 1, count( $liveblogs_ids ), $liveblog_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $liveblog_id ) );

			// Set meta 'newspack_vtd_cpt' = 'liveblog';
			update_post_meta( $liveblog_id, self::META_VTD_CPT, 'liveblog' );
		}

		$this->logger->log( $log, implode( ',', $liveblogs_ids ), false );
		wp_cache_flush();

		// Assign category 'Liveblogs'.
		WP_CLI::log( sprintf( "Assigning %s cat ID %d ...", self::LIVEBLOGS_CAT_NAME, $liveblogs_cat_id ) );
		foreach ( $liveblogs_ids as $key_liveblog_id => $liveblog_id ) {
			wp_set_post_categories( $liveblog_id, [ $liveblogs_cat_id ] );
		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}
}
