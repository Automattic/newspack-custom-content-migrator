<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for LkldNow.
 */
class BillyPennMigrator implements InterfaceCommand {

	/**
	 * Instance of BillyPennMigrator
	 * 
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
			'newspack-content-migrator billypenn-create-taxonomies',
			[ $this, 'cmd_billypenn_create_taxonomies' ],
			[
				'shortdesc' => 'Create taxonomies from Stories, Clusters, People etc.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator billypenn-create-taxonomies`.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_billypenn_create_taxonomies( $args, $assoc_args ) {
		$this->convert_posts_to_taxonomies( 'pedestal_story', 'category' );
		$this->convert_posts_to_taxonomies( 'pedestal_topic', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_place', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_person', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_org', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_locality', 'post_tag' );

		WP_CLI::success( 'Done!' );
	}

	/**
	 * Convert a custom post type to a taxonomy.
	 * 
	 * @param string $post_type The custom post type.
	 * @param string $taxonomy The taxonomy (category, post_tag etc.).
	 */
	public function convert_posts_to_taxonomies( $post_type, $taxonomy ) {
		global $wpdb;

		if ( 'category' != $taxonomy && 'post_tag' != $taxonomy ) {
			return false;
		}

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
		);

		$query = new WP_Query( $args );
		$terms = $query->posts;

		foreach ( $terms as $term ) {
			$posts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p2p_from FROM {$wpdb->prefix}p2p WHERE p2p_to = %d",
					$term->ID,
				),
			);

			WP_CLI::log( sprintf( 'Creating the term %s', $term->post_title ) );
			
			$new_term = wp_insert_term(
				$term->post_title,
				$taxonomy,
				array(
					'description' => $term->post_content,
				),
			);

			if ( is_wp_error( $new_term ) ) {
				WP_CLI::warning( 'Could not create term. Skipping...' );
				continue;
			}

			foreach ( $posts as $post_id ) {
				WP_CLI::log( sprintf( 'Adding term to post #%d', $post_id ) );
				wp_set_post_terms( $post_id, array( $new_term['term_id'] ), $taxonomy, true );
			}
		}
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	public function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
