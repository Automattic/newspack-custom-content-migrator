<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class InlineFeaturedImageMigrator implements InterfaceMigrator {

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
		WP_CLI::add_command( 'newspack-content-migrator de-dupe-featured-images', array( $this, 'cmd_de_dupe_featured_images' ), [
			'shortdesc' => 'Moves featured images from the top of content to only the featured image meta.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'post-ids',
					'description' => 'Post IDs to migrate.',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for de-dupe-featured-images command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_de_dupe_featured_images( $args, $assoc_args ) {

		if ( ! isset( $assoc_args[ 'post-ids' ] ) ) {
			$post_ids = get_posts( [
				'post_type'      => 'post',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] );
		}

		WP_CLI::line( sprintf( 'Checking %d posts.', count( $post_ids ) ) );

		$started = time();

		foreach ( $post_ids as $id ) {
			$thumbnail_id = get_post_thumbnail_id( $id );
			if ( ! $thumbnail_id ) {
				continue;
			}

			$regex    = '#\s*(<!-- wp:image[^{]*{[^}]*"id":' . absint( $thumbnail_id ) . '.*\/wp:image -->)#sU';
			$content  = get_post_field( 'post_content', $id );
			$replaced = preg_replace( $regex, '', $content, 1 );

			if ( $content != $replaced ) {
				$updated = [
					'ID'           => $id,
					'post_content' => $replaced
				];
				$result = wp_update_post( $updated );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf(
						'Failed to update post #%d because %s',
						$id,
						$result->get_error_messages()
					) );
				} else {
					WP_CLI::success( sprintf( 'Updated #%d', $id ) );
				}
			}
		}

		WP_CLI::line( sprintf(
			'Finished processing %d records in %d seconds',
			count( $post_ids ),
			time() - $started
		) );

	}

}
