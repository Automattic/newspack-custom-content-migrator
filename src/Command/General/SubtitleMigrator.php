<?php

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use \WP_CLI;

class SubtitleMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	/**
	 * Meta field subtitle is stored in.
	 *
	 * @var string
	 */
	const NEWSPACK_SUBTITLE_META_FIELD = 'newspack_post_subtitle';

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator migrate-excerpt-to-subtitle',
			self::get_command_closure( 'cmd_migrate_excerpt_to_subtitle' ),
			[
				'shortdesc' => 'Convert all post excerpts into post subtitles.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'keep-excerpts-content',
						'description' => 'If this flag is set, the command will copy the excerpt content to the post subtitle without removing it from the post excerpt.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Migrate all post excerpts into the subtitle field.
	 */
	public function cmd_migrate_excerpt_to_subtitle( $args, $assoc_args ) {
		global $wpdb;

		$keep_excerpts_content = isset( $assoc_args['keep-excerpts-content'] ) ? true : false;

		$data = $wpdb->get_results( "SELECT ID, post_excerpt FROM $wpdb->posts WHERE post_type='post' AND post_excerpt != ''", ARRAY_A );

		foreach ( $data as $post_data ) {
			if ( empty( trim( $post_data['post_excerpt'] ) ) ) {
				continue;
			}

			update_post_meta( $post_data['ID'], self::NEWSPACK_SUBTITLE_META_FIELD, $post_data['post_excerpt'] );
			WP_CLI::line( 'Updated: ' . $post_data['ID'] );
		}

		if ( ! $keep_excerpts_content ) {
			$wpdb->query( "UPDATE $wpdb->posts SET post_excerpt = '' WHERE post_type = 'post' AND ID = {$post_data['ID']}" );
		}

		wp_cache_flush();
		WP_CLI::success( 'Done.' );
	}
}
