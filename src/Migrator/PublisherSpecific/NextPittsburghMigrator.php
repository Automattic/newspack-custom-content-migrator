<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for LkldNow.
 */
class NextPittsburghMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator next-pittsburgh-replace-listings-shortcodes',
			[ $this, 'cmd_next_pittsburgh_replace_listings_shortcodes' ],
			[
				'shortdesc' => 'Replace all Job Listings shortcodes with Newspack Listings block.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Replace all Job Listings shortcodes with Newspack Listings block.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_next_pittsburgh_replace_listings_shortcodes( $pos_args, $assoc_args ) {
		global $wpdb;

		$block_args = array(
			'isSelected'   => true,
			'showNumbers'  => false,
			'queryMode'    => true,
			'queryOptions' => array(
				'type'     => 'newspack_lst_mktplce',
				'maxItems' => 50,
				'sortBy'   => 'date',
				'order'    => 'DESC',
			),
		);

		$json_block_args = wp_json_encode( $block_args );

		$shortcode = '[nx_paid_listings_all]';

		$shortcode_with_block = <<<HTML
<!-- wp:shortcode -->
[nx_paid_listings_all]
<!-- /wp:shortcode -->
HTML;

		$listings_block = <<<HTML
<!-- wp:newspack-listings/curated-list %s -->
<!-- wp:newspack-listings/list-container /-->
<!-- /wp:newspack-listings/curated-list -->		
HTML;

		$listings_block_with_args = sprintf( $listings_block, $json_block_args );

		$query = new WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				's'              => $shortcode,
				'posts_per_page' => -1,
			),
		);

		foreach ( $query->posts as $post ) {
			$new_post_content = str_replace(
				array(
					$shortcode_with_block,
					$shortcode,
				),
				array(
					$listings_block_with_args,
					$listings_block_with_args,
				),
				$post->post_content,
			);

			$wpdb->update(
				$wpdb->prefix . 'posts',
				array(
					'post_content' => $new_post_content,
				),
				array(
					'ID' => $post->ID,
				),
			);

			WP_CLI::log( sprintf( 'Replaced the content of post #%s', $post->ID ) );
		}
	}
}
