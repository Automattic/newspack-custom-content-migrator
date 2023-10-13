<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
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
	 * WpBlockManipulator instance.
	 *
	 * @var WpBlockManipulator WpBlockManipulator instance.
	 */
	private $blocks_manipulator;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator GutenbergBlockGenerator instance.
	 */
	private $blocks;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts              = new Posts();
		$this->blocks_manipulator = new WpBlockManipulator();
		$this->blocks             = new GutenbergBlockGenerator();
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
		WP_CLI::add_command(
			'newspack-content-migrator blockclubchicago-migrate-flourish-embeds',
			[ $this, 'cmd_migrate_flourish' ],
			[
				'shortdesc' => 'Refactors Flourish usage to a corresponding Gutenberg block.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * @param $pos_args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_migrate_flourish( $pos_args, $assoc_args ) {
		global $wpdb;

		$log = 'flourishes_post_ids.log';

		$post_ids = $this->posts->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			// Get post_content.
			$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id ) );

			$matches = $this->blocks_manipulator->match_wp_block_selfclosing( 'wp:lede-common/flourish', $post_content );
			if ( ! $matches || empty( $matches ) ) {
				continue;
			}

			WP_CLI::line( $post_id );

			$post_content_updated = $post_content;
			foreach ( $matches[0] as $match ) {
				$flourish_html = $match[0];
				$url = $this->blocks_manipulator->get_attribute( $flourish_html, 'url' );

				$iframe_block = $this->blocks->get_iframe( $url );
				$iframe_html = serialize_block( $iframe_block );

				$post_content_updated = str_replace( $flourish_html, $iframe_html, $post_content_updated );

				$wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post_id ]
				);

				file_put_contents( $log, $post_id . "\n", FILE_APPEND );
			}
		}

		WP_CLI::line( "Done. See log " . $log );
		wp_cache_flush();
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
