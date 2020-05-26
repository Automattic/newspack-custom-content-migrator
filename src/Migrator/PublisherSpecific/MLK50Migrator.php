<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for MLK50.
 */
class MLK50Migrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
			'newspack-content-migrator mlk50-images',
			[ $this, 'cmd_mlk50_images' ],
			[
				'shortdesc' => 'Grabs images from the content of posts and loads them into WP.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'Post IDs to migrate.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Loop through posts, grabbing images and loading into the media library.
	 */
	public function cmd_mlk50_images( $args, $assoc_args ) {

		if ( ! isset( $assoc_args[ 'post-ids' ] ) ) {
			$post_ids = get_posts( [
				'post_type'      => 'post',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			] );
		} else {
			$post_ids = explode( ',', $assoc_args['post-ids'] );
		}

		WP_CLI::line( sprintf( 'Checking %d posts.', count( $post_ids ) ) );

		$started = time();

		foreach ( $post_ids as $id ) {

			WP_CLI::line( \sprintf( 'Checking %d', $id ) );

			// Find the image.
			$regex    = '/.*src="(http\S+)".*/';
			$content  = get_post_field( 'post_content', $id );
			$search   = preg_match_all( $regex, $content, $matches );
			if ( ! $search ) { // No images, or an error.
				WP_CLI::line( '-- No images found' );
				continue;
			}

			for ( $i = 0; $i < count( $matches[1] ); $i++ ) {

				// Break up the URL so we can replace the host.
				$img_url = wp_parse_url( $matches[1][$i] );

				// We'll pick up non-WP images and embeds so we need to skip those.
				$valid_hosts = [
					'i0.wp.com',
					'i1.wp.com',
					'i2.wp.com',
					'mlk50mediumimport.files.wordpress.com',
				];
				if ( ! in_array( $img_url['host'], $valid_hosts, true ) ) {
					continue;
				}

				/*
				We found a photon URL, which will look something like this:
				https://i0.wp.com/cdn-images-1.medium.com/max/800/1*9kKdtUdt1Kq6s-u81zBlkw.jpeg?w=960&amp;ssl=1
				so we need to replace that with the path, which has the Medium URL in.
				Let's also remove the query params which are not needed.
				 */
				if ( false !== strpos( $img_url['host'], '.wp.com' ) ) {
					$img_url['host'] = \substr( $img_url['path'], 1 );
					unset( $img_url['path'], $img_url['query'] );
					$fetch_url = $img_url['scheme'] . '://' . $img_url['host'];
				} else {
					// Other URLs just need the query string removed.
					$fetch_url = $img_url['scheme'] . '://' . $img_url['host'] . $img_url['path'];
				}

				// Get the image and attach to this post.
				$img = media_sideload_image( $fetch_url, $id, null, 'id' );
				if ( is_wp_error( $img ) ) {
					WP_CLI::warning( sprintf( '-- Sideload failed with %s', $img->get_error_message() ) );
					continue;
				}

				// Assume the first image in a post is the featured image.
				if ( 0 === $i ) {
					WP_CLI::line( \sprintf( '-- Setting featured image to %d.', $img ) );
					$set = set_post_thumbnail( $id, $img );
					if ( ! $set ) {
						WP_CLI::warning( sprintf( '-- Failed to set featured image for %d', $id ) );
						continue;
					}
				}

				// Find the image in-post so we can add the thumbnail ID.
				$json_regex = '/<!-- wp:image ({.*}) -->/';
				$json_search = preg_match_all( $json_regex, $content, $json_matches );
				if ( ! $json_search ) {
					WP_CLI::warning( '-- Somehow failed to find the image JSON.' );
					continue;
				}

				// Check there isn't already an ID (there shouldn't be).
				$image_json = \json_decode( $json_matches['1'][ $i ] );
				if ( property_exists( $image_json, 'id' ) ) {
					WP_CLI::warning( \sprintf( '-- The image JSON already has an ID of %d.', $image_json->id ) );
					continue;
				}

				// Add the ID of the featured image and re-insert.
				$image_json->id = $img;
				WP_CLI::line( \sprintf( '-- Adding image ID %d to JSON', $img ) );
				$replaced = str_replace( $json_matches[1][ $i ], \json_encode( $image_json ), $content );

				// Also replace the URL to the image.
				$replaced = str_replace( $matches[1][$i], wp_get_attachment_url( $img ), $replaced );

				// Check the content is different and update.
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

		}

		WP_CLI::line( sprintf(
			'Finished processing %d records in %d seconds',
			count( $post_ids ),
			time() - $started
		) );

	}

	private function strposa( $haystack, $needles = [], $offset = 0 ) {
		$chr = [];

		foreach ( $needles as $needle ) {
			$res = strpos( $haystack, $needle, $offset );
			if ( $res !== false ) $chr[ $needle ] = $res;
		}

		if ( empty( $chr ) ) {
			return false;
		}

		return min( $chr );
	}

}
