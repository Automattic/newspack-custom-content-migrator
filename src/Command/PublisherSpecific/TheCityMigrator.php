<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\Logger;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
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
	 * WpBlockManipulator instance.
	 *
	 * @var WpBlockManipulator WpBlockManipulator instance.
	 */
	private $wpblockmanipulator;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator GutenbergBlockGenerator instance.
	 */
	private $gutenberg_blocks;

	/**
	 * Logger instance.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts = new PostsLogic();
		$this->wpblockmanipulator = new WpBlockManipulator();
		$this->gutenberg_blocks = new GutenbergBlockGenerator();
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
			'newspack-content-migrator thecity-transform-blocks-wpciviliframe-to-newspackiframe',
			[ $this, 'cmd_wpciviliframe_to_newspackiframe' ],
		);

	}

	public function cmd_wpciviliframe_to_newspackiframe( array $pos_args, array $assoc_args ): void {
		global $wpdb;

		$logs_path_before_after = '0_thecity_iframereplacement_before_afters';
		// Check if folder exists.
		if ( ! file_exists( $logs_path_before_after ) ) {
			mkdir( $logs_path_before_after );
		}

		// Get all posts.
		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish', 'future', 'pending', 'private' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::line( sprintf( "%d/%d %d", $key_post_id + 1, count( $post_ids ), $post_id ) );
			$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );

			// Skip posts which do not have wp:civil/iframe.
			$civil_iframe_matches = $this->wpblockmanipulator->match_wp_block_selfclosing( 'wp:civil/iframe', $post_content );
			if ( ! $civil_iframe_matches || ! isset( $civil_iframe_matches[0] ) || empty( $civil_iframe_matches[0] ) ) {
				continue;
			}

			// Replace blocks.
			$post_content_updated = $post_content;
			foreach ( $civil_iframe_matches[0] as $civil_iframe_match ) {
				$civil_iframe_block_html = $civil_iframe_match[0];
				$src = $this->wpblockmanipulator->get_attribute( $civil_iframe_block_html, 'src' );

				$newspack_iframe_block = $this->gutenberg_blocks->get_iframe( $src );
				$newspack_iframe_block_html = serialize_blocks( [ $newspack_iframe_block ] );

				$post_content_updated = str_replace( $civil_iframe_block_html, $newspack_iframe_block_html, $post_content_updated );
			}

			// Save.
			if ( $post_content != $post_content_updated ) {
				// Save before/after for easy QA.
				file_put_contents( $logs_path_before_after . '/' . $post_id . '.before.html', $post_content );
				file_put_contents( $logs_path_before_after . '/' . $post_id . '.after.html', $post_content_updated );

				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] );

				WP_CLI::success( "Updated" );
			}
		}

		wp_cache_flush();
	}
}
