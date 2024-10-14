<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Newspack\MigrationTools\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for BenitoLink.
 */
class BenitoLinkMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Posts Posts instance.
	 */
	private $posts;

	/**
	 * @var Attachments Attachments instance.
	 */
	private $attachments;

	/**
	 * @var GutenbergBlockGenerator GutenbergBlockGenerator instance.
	 */
	private $block_generator;

	/**
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts = new Posts();
		$this->attachments = new Attachments();
		$this->block_generator = new GutenbergBlockGenerator();
		$this->logger = new Logger();
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
			'newspack-content-migrator benitolink-migrate-galleries',
			[ $this, 'cmd_migrate_galleries' ],
		);
	}

	/**
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_migrate_galleries( $pos_args, $assoc_args ) {

		global $wpdb;
		$local_host_w_schema = get_site_url( null, '', 'https' );
		$cdiff_postmeta_table = 'cdiff_postmeta';
		$path_log_content = getcwd() . '/galleries_logs';
		if ( ! file_exists( $path_log_content ) ) {
			mkdir( $path_log_content, 0777, true );
		}

		$post_ids = $this->posts->get_all_posts_ids( 'post', [ 'publish', 'future', 'pending', 'private' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			// wp_postmeta, meta_key='wpcf-summary', meta_value is used as subtitle ("The Run to the Wall highlights the nation’s loss in the Vietnam War.") — this should become postmeta newspack_post_subtitle
			$subtitle = get_post_meta( $post_id, 'wpcf-summary', true );
			if ( ! empty( $subtitle ) ) {
				update_post_meta( $post_id, 'newspack_post_subtitle', $subtitle );
				$this->logger->log( 'benitolink-migrate-galleries__subtitleUpdated.log', sprintf( '%d subtitle updated', $post_id ) );
			}

			// wp_postmeta, meta_key='wpcf-additional_images', multiple meta_values are image URLs
			$images_results = get_post_meta( $post_id, 'wpcf-additional_images', false );
			$images = [];
			foreach ( $images_results as $images_result ) {
				if ( ! empty( $images_result ) ) {
					$images[] = $images_result;
				}
			}
			if ( ( false === $images ) || ( empty( $images ) ) ) {
				WP_CLI::line( 'No images, skipping.' );
				continue;
			}

			// wp_postmeta '_wpcf-additional_images-sort-order' (e.g. meta_value = 'a:3:{i:0;i:781127;i:1;i:781128;i:2;i:781129;}') contains oder of images, and the values are old live postmeta meta_ids. So we gotta get those from the old live postmeta table.
			$cdiff_postmeta_metaids_sorted_results = get_post_meta( $post_id, '_wpcf-additional_images-sort-order', false );
			$cdiff_postmeta_metaids_sorted = [];
			foreach ( $cdiff_postmeta_metaids_sorted_results as $cdiff_postmeta_metaids_sorted_result ) {
				if ( ! empty( $cdiff_postmeta_metaids_sorted_result[0] ) ) {
					$cdiff_postmeta_metaids_sorted[] = $cdiff_postmeta_metaids_sorted_result[0];
				}
			}

			// Will get attachment IDs here.
			$att_ids = [];

			// Use $cdiff_postmeta_metaids_sorted if available, otherwise use $images.
			if ( ! empty( $cdiff_postmeta_metaids_sorted ) ) {

				// - get old live postmeta meta_ids from the old live postmeta table
				$cdiff_postmeta_metaids_sorted_placeholders = implode( ',', array_fill( 0, count( $cdiff_postmeta_metaids_sorted ), '%d' ) );
				$cdiff_postmeta_results = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM {$cdiff_postmeta_table} WHERE meta_id in ( {$cdiff_postmeta_metaids_sorted_placeholders} ) AND meta_key = 'wpcf-additional_images' ",
						$cdiff_postmeta_metaids_sorted
					),
					ARRAY_A
				);
				// - get sorted live image URLs.
				$images_sorted = [];
				foreach ( $cdiff_postmeta_results as $cdiff_postmeta_result ) {
					if ( ! empty( $cdiff_postmeta_result['meta_value'] ) ) {
						$images_sorted[] = $cdiff_postmeta_result['meta_value'];
					}
				}

				// Okay. Now validate if '_wpcf-additional_images-sort-order' and 'wpcf-additional_images' have the same images.
				$s_images_sorted = $images_sorted;
				sort( $s_images_sorted );
				$s_images = $images;
				sort( $s_images );
				if ( empty( $images_sorted ) || ( $s_images_sorted != $s_images ) ) {
					// Sorted images and images not same.
					$debug = 1;

					// There's an example which uses just $images with 5 elements, but not $images_sorted with 1 element.
					// Let's join these two lists and get the unique values.
					$images_sorted = array_values( array_unique( array_merge( $images_sorted, $images ) ) );

					if ( empty( $images_sorted ) ) {
						$debug = 1;
						continue;
					}
				}

				// we can use attachment_url_to_postid() by substituting URL's hostname to local Staging hostname to get the new Staging attachment ID (that same attachment ID had a different/old ID on Live)
				$att_ids = [];
				foreach ( $images_sorted as $image ) {
					$image_parsed = parse_url( $image );
					$image_local  = sprintf( "%s%s", $local_host_w_schema, $image_parsed['path'] );

					$att_id = attachment_url_to_postid( $image_local );
					if ( 0 == $att_id || ! is_numeric( $att_id ) ) {

						WP_CLI::line( sprintf( 'Downloading %s ...', $image ) );
						$att_id = $this->attachments->import_external_file( $image, $title = null, $caption = null, $description = null, $alt = null, $post_id );
						if ( is_wp_error( $att_id ) || ! $att_id ) {
							// Try again without the local hostname.
							if ( 0 === strpos( $image, 'https://benitolink.local/' ) ) {
								$image = str_replace( 'https://benitolink.local/', 'https://benitolink.com/', $image );
								$att_id = $this->attachments->import_external_file( $image, $title = null, $caption = null, $description = null, $alt = null, $post_id );
							}
							if ( is_wp_error( $att_id ) || ! $att_id ) {
								$this->logger->log( 'benitolink-migrate-galleries__errDownloading.log', sprintf( 'Err downloading -- PostID %d image %s : %s', $post_id, $image, $att_id->get_error_message() ), $this->logger::WARNING );
								continue;
							}
						}

						$this->logger->log( 'benitolink-migrate-galleries__downloadedAtt.log', sprintf( 'Att downloaded -- PostID %d AttID %d -- from %s', $post_id, $att_id, $image ) );
					}

					$att_ids[] = $att_id;
				}

			} else {

				// Sorted is empty, use $images.
				$debug = 1;

				// we can use attachment_url_to_postid() by substituting URL's hostname to local Staging hostname to get the new Staging attachment ID (that same attachment ID had a different/old ID on Live)
				$att_ids = [];
				foreach ( $images as $image ) {
					$image_parsed = parse_url( $image );
					$image_local  = sprintf( "%s%s", $local_host_w_schema, $image_parsed['path'] );

					$att_id = attachment_url_to_postid( $image_local );
					if ( 0 == $att_id || ! is_numeric( $att_id ) ) {
						WP_CLI::line( sprintf( 'Downloading %s ...', $image ) );
						$att_id = $this->attachments->import_external_file( $image, $title = null, $caption = null, $description = null, $alt = null, $post_id );
						if ( is_wp_error( $att_id ) || ! $att_id ) {
							// Try again without the local hostname.
							if ( 0 === strpos( $image, 'https://benitolink.local/' ) ) {
								$image = str_replace( 'https://benitolink.local/', 'https://benitolink.com/', $image );
								$att_id = $this->attachments->import_external_file( $image, $title = null, $caption = null, $description = null, $alt = null, $post_id );
							}
							if ( is_wp_error( $att_id ) || ! $att_id ) {
								$this->logger->log( 'benitolink-migrate-galleries__errDownloading.log', sprintf( 'Err downloading -- PostID %d image %s : %s', $post_id, $image, $att_id->get_error_message() ), $this->logger::WARNING );
								continue;
							}
						}

						$this->logger->log( 'benitolink-migrate-galleries__downloadedAtt.log', sprintf( 'Att downloaded -- PostID %d AttID %d -- from %s', $post_id, $att_id, $image ) );
					}

					$att_ids[] = $att_id;
				}
			}

			// Save att IDs as postmeta.
			update_post_meta( $post_id, 'newspack_gallerymigration_attachment_is', $att_ids );

			// JP Tiled Gallery doesn't support captions, so we'll use the regular JP Gallery block.
			$jp_gallery_block = $this->block_generator->get_gallery( $att_ids, 4, 'full', $image_link_to = 'attachment' );
			$jp_gallery       = serialize_block( $jp_gallery_block );

			// Check before update.
			if ( empty( $att_ids ) || empty( $jp_gallery ) ) {
				$debug = 1;
				continue;
			}

			// Get post_content and update wp_posts by prepending gallery block.
			$post_content = $wpdb->get_var( "SELECT post_content FROM {$wpdb->posts} WHERE ID = {$post_id}" );
			$post_content_updated = $jp_gallery . "\n" . $post_content;
			$wpdb->update(
				$wpdb->posts,
				[ 'post_content' => $post_content_updated, ],
				[ 'ID' => $post_id, ]
			);

			// Save att IDs as postmeta.
			update_post_meta( $post_id, 'newspack_gallerymigration_prepended', true );

			// Log before and after.
			file_put_contents( $path_log_content . '/' . sprintf( "%d_1_before.log", $post_id ), $post_content );
			file_put_contents( $path_log_content . '/' . sprintf( "%d_2_after.log", $post_id ), $post_content_updated );
			$this->logger->log( 'benitolink-migrate-galleries__contentUpdated.log', sprintf( '%d content updated', $post_id ) );
		}

		/**
		 * we could have also trace attachment data via old (live) attachment IDs like this,
		 *      - benitolink-newspack.newspackstaging.com:/tmp/launch/content-diff__imported-post-ids.log contains a list of "old att. IDs => new att IDs", and we can find the old Live att. ID there
		 *      - local table cdiff_postmeta, meta_key='_wp_attachment_image_alt', meta_value contains alt as well
		 *      - local table cdiff_posts contains old live attachment post object where ID is old attachment ID
		 */
	}
}
