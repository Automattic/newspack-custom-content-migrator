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
use simplehtmldom\HtmlDocument;

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
			'newspack-content-migrator dallasvoice-hide-featured-image-if-used-in-post-content',
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

		WP_CLI::add_command(
			'newspack-content-migrator dallasvoice-fix-image-blocks-wrong-attachment-id-reference',
			[ $this, 'cmd_fix_image_blocks_wrong_attachment_id_reference' ],
			[
				'shortdesc' => 'Fix wrong attachment ID reference in Image Blocks',
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

					$attachment_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT `post_id`
							FROM `$wpdb->postmeta`
							WHERE `meta_key` = 'newspack_bwg_image_id'
							AND `meta_value` = %d",
							$gallery_image_data->id
						)
					);

					$filename = pathinfo( str_replace( '/', '', $gallery_image_data->image_url ) );
					$filename = substr( $filename['filename'], 0, 150 ) . '.' . $filename['extension'];

					if ( ! $attachment_id ) {
						$attachment_id = media_handle_sideload(
							[
								'name' => $filename,
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
					}

					if ( is_wp_error( $attachment_id ) ) {
						echo 'Post ID: ' . $post_id . "\r\n";
						echo 'Gallery Image ID: ' . $gallery_image_data->id . "\r\n";
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
	 * Migrates the Galleries from Best WordPress Gallery (Photo Gallery plugin) to WordPress Gallery block.
	 */
	public function cmd_fix_image_blocks_wrong_attachment_id_reference( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] ) ? (bool) $assoc_args['dry-run'] : false;
		$post_id_from = ! empty( $assoc_args['post-id-from'] ) ? (int) $assoc_args['post-id-from'] : 0;

		$migration_datetime = date( 'Y-m-d H-i-s' );
		$migration_name = 'dallasvoice-fix-image-blocks' . ($dry_run ? '-dry-run' : '');

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
				'Post Title',
				'Old Content',
				'New Content',
				'Local URL',
				'Staging URL',
				'Live URL',
			]
		);

		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT `ID` FROM `$wpdb->posts` WHERE `post_type` IN ('post', 'page') AND `ID` > $post_id_from AND `post_content` LIKE '%<!-- wp:image%'"
			)
		);

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Fix Image Blocks', count( $post_ids ), 1 );

		foreach ( $post_ids as $post_index => $post_id ) {
			$progress_bar->tick(
				1,
				sprintf(
					'DallasVoice: Fix Image Blocks [Post #%d: %d/%d] [Memory: %s]',
					$post_id,
					$post_index + 1,
					count( $post_ids ),
					size_format( memory_get_usage( true ) )
				)
			);

			$old_content = get_post_field( 'post_content', $post_id );
			$new_content = $old_content;

			$blocks = parse_blocks( $old_content );

			$new_blocks = $this->fix_image_blocks_recursively( $blocks );
			$new_content = serialize_blocks( $new_blocks );

			if ( $new_content === $old_content ) {
				continue;
			}

			if ( ! $dry_run ) {
				wp_save_post_revision( $post_id );
				
				wp_update_post( [
					'ID' => $post_id,
					'post_content' => $new_content,
				] );
			}

			fputcsv(
				$csv_file_pointer,
				[
					$post_index + 1, // #
					$post_id, // Post ID
					get_the_title( $post_id ), // Post Title
					$old_content, // Old Content
					$new_content, // New Content
					get_permalink( $post_id ), // Local URL
					str_replace( home_url( '/' ), 'https://dallasvoice-newspack.newspackstaging.com/', get_permalink( $post_id ) ), // Staging URL
					str_replace( home_url( '/' ), 'https://dallasvoice.com/', get_permalink( $post_id ) ), // Live URL
				]
			);
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

	/**
	 * Fix Image Blocks recursively.
	 * 
	 * @return array
	 */
	private function fix_image_blocks_recursively( array &$blocks ): array {
		foreach ( $blocks as $block_index => &$block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->fix_image_blocks_recursively( $block['innerBlocks'] );

				continue;
			}

			if ( $block['blockName'] === 'core/image' ) {
				$block = $this->get_fixed_image_block( $block );
			}
		}

		return $blocks;
	}

	/**
	 * Helper function to get Image block with fixed attributes.
	 * 
	 * For some reason some Image Blocks contain reference to Attachment ID which doesn't exist (e.g. 16226)
	 * However, if you add 1000000000 (1 billion) to the Attachment ID, you might get the real image ID.
	 * 
	 * @return array
	 */
	private function get_fixed_image_block( array $image_block ): array {
		if ( ! empty( $image_block['attrs']['id'] ) ) {
			$block_doc = new HtmlDocument( $image_block['innerHTML'] );
			$img_doc = $block_doc->find( 'img' );

			if ( empty( $img_doc ) ) {
				return $image_block;
			}

			$img_src = $img_doc[0]->getAttribute( 'src' );
			$img_class = $img_doc[0]->getAttribute( 'class' );
			preg_match( '~wp\-image\-([\d]+)~', $img_class, $img_class_id );

			$attachment_id = attachment_url_to_postid( $img_src );

			if ( empty( $attachment_id ) ) {
				// Attachment ID is missing
				// Not sure what to do next...
				// Example Posts: 1342

				return $image_block;
			}

			if ( $attachment_id === $img_class_id[1] + 1000000000 ) {
				$image_block['attrs']['id'] = $img_class_id[1] + 1000000000;
				$image_block['innerHTML'] = preg_replace( 
					'~wp\-image\-' . $img_class_id[1] . '~',
					'wp-image-' . $img_class_id[1] + 1000000000,
					$image_block['innerHTML']
				);
				$image_block['innerContent'][0] = preg_replace( 
					'~wp\-image\-' . $img_class_id[1] . '~',
					'wp-image-' . $img_class_id[1] + 1000000000,
					$image_block['innerContent'][0]
				);
				// echo 'File: ' . __FILE__ . "\r\n";
				// echo 'Line: ' . __LINE__ . "\r\n";
				// var_dump( $image_block, $image_block['attrs'], $img_src, $img_class, $attachment_id, $img_class_id );
				// exit;
			}
		}

		return $image_block;
	}
}
