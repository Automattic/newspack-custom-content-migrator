<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class PBNMigrator implements InterfaceMigrator {

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
		WP_CLI::add_command( 'newspack-content-migrator pbn-fix-broken-images', array( $this, 'cmd_pbn_fix_broken_images' ), [
			'shortdesc' => 'Fixes the images with incorrect references in PBN.',
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
	public function cmd_pbn_fix_broken_images( $args, $assoc_args ) {

		if ( ! isset( $assoc_args[ 'post-ids' ] ) ) {
			$post_ids = get_posts( [
				'post_type'      => 'post',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] );
		}

		$post_ids = [ 219167 ]; // Test.

		WP_CLI::line( sprintf( 'Checking %d posts.', count( $post_ids ) ) );

		$started = time();

		foreach ( $post_ids as $id ) {
			$thumbnail_id = get_post_thumbnail_id( $id );
			if ( ! $thumbnail_id ) {
				WP_CLI::warning( sprintf( 'No thumbnail found for #%d', $id ) );
				continue;
			}

			$regex    = '#\s*<figure>\[caption\]<img src="[a-zA-Z0-9-_\/\.]+">(.*)\[\/caption\]<\/figure>#isU';
			$content  = get_post_field( 'post_content', $id );
			$found = preg_match( $regex, $content, $matches );
			if ( 0 === $found ) {
				WP_CLI::warning( sprintf( 'No broken image found for #%d', $id ) );
				continue;
			}

			// Retrieve the caption and prepare to remove the broken image from content.
			$caption = $matches[1];
			$remove = $matches[0];

			// Update the "excerpt" for the attachment post because apparently
			// we live in bizarro world where excerpt === caption.
			$excerpt = get_the_excerpt( $thumbnail_id );
			if ( ! empty( $excerpt ) ) {
				WP_CLI::warning( sprintf(
					'Not updating caption for attachment %d because one exists already.',
					$thumbnail_id
				) );
			} else {
				$result = wp_update_post( [
					'ID' => $thumbnail_id,
					'post_excerpt' => $caption,
				] );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf(
						'Failed to update caption on #%d because %s',
						$thumbnail_id,
						$result->get_error_messages()
					) );
				} else {
					WP_CLI::success( sprintf( 'Updated caption on #%d', $thumbnail_id ) );
				}
			}

			// Remove the broken image and update the content.
			$replaced = str_replace( $remove, '', $content );
			if ( $content != $replaced ) {
				$updated = [
					'ID'           => $id,
					'post_content' => $replaced,
				];
				$result = wp_update_post( $updated );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf(
						'Failed to update post #%d because %s',
						$id,
						$result->get_error_messages()
					) );
				} else {
					WP_CLI::success( sprintf( 'Removed broken image on #%d', $id ) );
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
