<?php
/**
 * Migration tasks for Dallas Voice.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Dallas Voice.
 */
class DallasVoiceMigrator implements InterfaceCommand {
	const DEFAULT_USERNAME = 'adminnewspack';

	/**
	 * Logger.
	 */
	private Logger $logger;


	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * Singleton.
	 *
	 * @return DallasVoiceMigrator
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @throws Exception
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator dallasvoice-hide-featured-image-if-used-in-post-content --post-ids-csv=1000366485',
			[ $this, 'cmd_hide_featured_image_if_used_in_post_content' ],
			[
				'shortdesc' => 'Hides the Featured Image if it\'s being used in post content',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator dallasvoice-migrate-galleries',
			[ $this, 'cmd_migrate_galleries_from_bwg_to_wp_gallery' ],
			[
				'shortdesc' => 'Migrates the Galleries from Best WordPress Gallery (Photo Gallery plugin) to WordPress Gallery block',
			]
		);
	}

	/** 
	 * Hides the Featured Image if it\'s being used in post content.
	 * 
	 * Alias for `newspack-content-migrator hide-featured-image-if-used-in-post-content --anywhere-in-post-content`
	 */
	public function cmd_hide_featured_image_if_used_in_post_content( array $args, array $assoc_args ): void {
		WP_CLI::runcommand(
			'newspack-content-migrator hide-featured-image-if-used-in-post-content --anywhere-in-post-content',
			[
				'launch' => false,
			] 
		);
	}

	/** 
	 * Migrates the Galleries from Best WordPress Gallery (Photo Gallery plugin) to WordPress Gallery block.
	 */
	public function cmd_migrate_galleries_from_bwg_to_wp_gallery( array $args, array $assoc_args ): void {
		global $wpdb;

		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		$default_author = get_user_by( 'login', self::DEFAULT_USERNAME );

		$uploads_dir = wp_upload_dir();

		$migration_datetime = date( 'Y-m-d H-i-s' );
		$migration_name = 'dallasvoice-migrate-galleries';

		// Logs.
		$log = $migration_datetime . '-' . $migration_name . '.log';

		// CSV.
		$csv              = $migration_datetime . '-' . $migration_name . '.csv';
		$csv_file_pointer = fopen( $csv, 'w' );
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Post ID',
				'Old Content',
				'New Content',
				'Gallery',
				'Local URL',
				'Staging URL',
				'Live URL',
			]
		);

		$posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT `ID` FROM `$wpdb->posts` WHERE `post_type` IN ('post', 'page') AND `post_content` LIKE '%Best_Wordpress_Gallery%'"
			)
		);

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Migrating Galleries', count( $posts ), 1 );

		foreach ( $posts as $post_index => $post_id ) {
			$old_content = get_post_field( 'post_content', $post_id );
			$new_content = $old_content;
			
			$count_matches = preg_match_all( '~' . get_shortcode_regex( [ 'Best_Wordpress_Gallery' ] ) . '~', $old_content, $matches );

			for ( $i = 0; $i < $count_matches; $i++ ) {
				$shortcode_atts = shortcode_parse_atts( $matches[0][ $i ] );
				$gallery_settings = $this->bwg_get_gallery_settings( $shortcode_atts['id'] );

				$bwg_gallery_id = $gallery_settings['gallery_id'];

				$gallery_images_ids = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT *
						FROM `{$wpdb->prefix}bwg_image`
						WHERE `gallery_id` = %d
						ORDER BY `order` ASC",
						$bwg_gallery_id
					)
				);

				$gallery_attachments_ids = [];

				foreach ( $gallery_images_ids as $gallery_image_data ) {
					copy(
						trailingslashit( $uploads_dir['basedir'] ) . 'photo-gallery' . $gallery_image_data->image_url,
						getcwd() . '/' . $gallery_image_data->image_url,
					);

					$attachment_id = media_handle_sideload(
						[
							'name' => str_replace( '/', '', $gallery_image_data->image_url ),
							'tmp_name' => getcwd() . '/' . $gallery_image_data->image_url,
						],
						0,
						$gallery_image_data->alt ?? $gallery_image_data->filename,
						[
							'post_author' => $default_author->ID,
							'post_content' => $gallery_image_data->description,
							'post_excerpt' => $gallery_image_data->description,
						]
					);

					if ( is_wp_error( $attachment_id ) ) {
						var_dump( 'WP Error',  $attachment_id );
						exit;
					}

					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $gallery_image_data->alt );
					update_post_meta( $attachment_id, 'newspack_bwg_image_id', $gallery_image_data->id );
					update_post_meta( $attachment_id, 'newspack_bwg_gallery_id', $bwg_gallery_id );

					$gallery_attachments_ids[] = $attachment_id;
				}

				$gallery_shortcode = '[gallery ids="' . implode( ',', $gallery_attachments_ids ) . '" columns="3" size="large" link="file"]';

				$new_content = str_replace( $matches[0][ $i ], $gallery_shortcode, $new_content );
			}

			wp_update_post( [
				'ID' => $post_id,
				'post_content' => $new_content,
			] );

			update_post_meta( $post_id, 'newspack_bwg_migration_post_updated', $migration_datetime );

			fputcsv(
				$csv_file_pointer,
				[
					$post_index + 1, // #
					$post_id, // Post ID
					$old_content, // Old Content
					$new_content, // New Content
					implode( ' ', $matches[0] ), // Shortcodes
					get_permalink( $post_id ), // Local URL
					str_replace( home_url( '/' ), 'https://dallasvoice-newspack.newspackstaging.com/', get_permalink( $post_id ) ), // Staging URL
					str_replace( home_url( '/' ), 'https://dallasvoice.com/', get_permalink( $post_id ) ), // Live URL
				]
			);

			$progress_bar->tick( 1, sprintf( '[Memory: %s]', size_format( memory_get_usage( true ) ) ) );
		}

		$progress_bar->finish();

		// Close CSV.
		fclose( $csv_file_pointer );

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );
	}

	/**
	 * Helper function to get Gallery Settings based on Shortcode ID.
	 * 
	 * @return array
	 */
	private function bwg_get_gallery_settings( string $id ): array {
		global $wpdb;

		$gallery_settings = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `tagtext`
				FROM `{$wpdb->prefix}bwg_shortcode`
				WHERE `id` = %d",
				$id
			)
		);

		$settings = explode( '" ', $gallery_settings );

		foreach ( $settings as $index => $setting ) {
			$parsed_setting = explode( '=', trim( $setting ) );

			unset( $settings[ $index ] );
			$settings[ $parsed_setting[0] ] = str_replace( '"', '', $parsed_setting[1] );
		}

		return $settings;
	}
}
