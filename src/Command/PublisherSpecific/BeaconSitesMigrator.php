<?php
/**
 * Migrator for Beacon Sites.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for BeaconSites.
 */
class BeaconSitesMigrator implements InterfaceCommand {

	/**
	 * Instance of BeaconSitesMigrator.
	 *
	 * @var null|InterfaceCommand
	 */
	private static $instance = null;

	/**
	 * Posts.
	 * 
	 * @var Posts
	 */
	private $posts;
	
	/**
	 * Logger.
	 * 
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts  = new Posts();
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
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator beacon-sites-set-brands-on-posts',
			[ $this, 'cmd_set_brands_on_posts' ],
			[
				'shortdesc' => 'Sets a brand to all the posts.',
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'brand-name',
						'optional'  => false,
						'repeating' => false,
					],
				],
			]
		);
	}

	/**
	 * Set brand to all posts.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_set_brands_on_posts( $pos_args, $assoc_args ) {
		$brand_name = $assoc_args['brand-name'];
		
		$log_file = 'beacon-sites-set-brands-on-posts.log';

		if ( ! taxonomy_exists( 'brand' ) ) {
			WP_CLI::error( 'Brand taxonomy does not exist. Best to temporariliy install and activate newspack-multibranded-site so that this command can run.' );
		}

		// Get brand ID.
		$brand_term = get_term_by( 'name', $brand_name, 'brand' );
		if ( ! $brand_term ) {
			$brand_term = wp_insert_term( $brand_name, 'brand' );
			if ( is_wp_error( $brand_term ) ) {
				WP_CLI::error( sprintf( 'Error getting/creating brand `%s`, err: %s', $brand_name, $brand_term->get_error_message() ) );
			}
		}
		$brand_ids = [
			$brand_term->term_id,
		];

		$post_ids = $this->posts->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( '%d/%d %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			$set = wp_set_post_terms( $post_id, $brand_ids, 'brand' );
			if ( ! $set || is_wp_error( $set ) ) {
				$this->logger->log( $log_file, sprintf( 'Error setting brand %s to post %d, err.msg: %s', $brand_name, $post_id, is_wp_error( $set ) ? $set->get_error_message() : 'n/a' ), 'error', true );
			}
			$this->logger->log( $log_file, sprintf( 'Setting brand %s for post %d', $brand_name, $post_id ) );
		}

		WP_CLI::success( 'Done.' );
	}
}
