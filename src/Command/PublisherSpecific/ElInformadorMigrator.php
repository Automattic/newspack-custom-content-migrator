<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

class ElInformadorMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

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
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator el-informador-migrate-youtube-videos',
			[ $this, 'cmd_migrate_youtube_videos' ],
			[
				'shortdesc' => 'Migrates YouTube videos from postmeta table to posts.',
			]
		);
	}

	public function cmd_migrate_youtube_videos() {
		global $wpdb;

		$youtube_meta = $wpdb->get_results( "SELECT * FROM $wpdb->postmeta WHERE meta_key = 'td_last_set_video' AND meta_value <> ''" );

		$youtube_embed_template = '<!-- wp:embed {"url":"{url}","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
{url}
</div></figure>
<!-- /wp:embed -->';

		foreach ( $youtube_meta as $meta ) {
			WP_CLI::log( 'Processing post ' . $meta->post_id );
			$video_id = '';

			if ( str_contains( $meta->meta_value, 'youtu.be/' ) ) {
				$video_id = substr( $meta->meta_value, strrpos( $meta->meta_value, 'youtu.be/' ) + 9 );

				if ( str_contains( $video_id, '?' ) ) {
					$video_id = substr( $video_id, 0, strpos( $video_id, '?' ) );
				}
			} else {
				// regex for extracting youtube video id from url
				$pattern = '/(?<=v=)[^&#]+/';
				preg_match( $pattern, $meta->meta_value, $matches );
				$video_id = $matches[0];
			}

			if ( $video_id ) {
				$youtube_embed = strtr( $youtube_embed_template, [ '{url}' => "https://youtu.be/$video_id" ] );
				$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $meta->post_id ) );
				$post_content = $youtube_embed . '<br>' . $post_content;
				$wpdb->update( $wpdb->posts, [ 'post_content' => $post_content ], [ 'ID' => $meta->post_id ] );
				update_post_meta( $meta->post_id, 'newspack_featured_image_position', 'hidden' );
				WP_CLI::log( 'Updated post ' . $meta->post_id );
			}
		}
	}
}
