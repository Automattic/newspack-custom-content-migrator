<?php

namespace NewspackCustomContentMigrator\Command\General;

use Newspack_Scraper_Migrator_CC_Scraper;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

class CreativeCircleMigrator implements InterfaceCommand {

	public static $scrape_args = [
		[
			'type'        => 'assoc',
			'name'        => 'subdomain',
			'description' => 'Subdomain of the CC site to scrape.',
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'xajaxr-param',
			'description' => 'xajaxr parameter.',
			'optional'    => false,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'mediasiteq-cookie',
			'description' => 'Cookie set to scrape archive.',
			'optional'    => true,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'page-from',
			'description' => 'Page to start from.',
			'optional'    => true,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'page-to',
			'description' => 'Page to end on.',
			'optional'    => true,
			'repeating'   => false,
		],
	];

	private $batch_args = [
		[
			'type'        => 'assoc',
			'name'        => 'batch',
			'description' => 'Batch to start from.',
			'optional'    => true,
			'repeating'   => false,
		],
		[
			'type'        => 'assoc',
			'name'        => 'posts-per-batch',
			'description' => 'Posts to import per batch',
			'optional'    => true,
			'repeating'   => false,
		],
	];

	private Logger $logger;
	private GutenbergBlockGenerator $gutenberg_block_generator;
	private AttachmentsLogic $attachments_logic;

	private function __construct() {
		$this->logger                    = new Logger();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
		$this->attachments_logic         = new AttachmentsLogic();
	}

	/**
	 * Get Instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @throws \Exception
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator scrape-cc',
			[ $this, 'cmd_scrape' ],
			[
				'shortdesc' => 'Scrape a CC site\'s content.',
				'synopsis'  => [
					...self::$scrape_args,
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator cc-migrate-galleries-and-featured-image',
			[ $this, 'cmd_migrate_galleries_and_featured_image' ],
			[
				'shortdesc' => 'Migrate galleries and featured images from meta.',
				'synopsis'  => [
					...$this->batch_args,
				],
			]
		);

		// See CCMMigrator for more commands that could be generalized and added here.
	}

	public function cmd_scrape( array $args, array $assoc_args ): void {
		$mediasiteq_cookie = $assoc_args['mediasiteq-cookie'] ?? '';
		$subdomain         = $assoc_args['subdomain'];
		$xajaxr_param      = $assoc_args['xajaxr-param'];
		$page_from         = intval( $assoc_args['page-from'] ?? 1 );
		$page_to           = isset( $assoc_args['page-to'] ) ? intval( $assoc_args['page-to'] ) : null;

		Newspack_Scraper_Migrator_CC_Scraper::get_instance()->process( $subdomain, $xajaxr_param, $mediasiteq_cookie, $page_from, $page_to );
		WP_CLI::success( 'Completed.' );
	}

	public function cmd_migrate_galleries_and_featured_image( array $pos_args, array $assoc_args ): void {
		$images_log_file              = 'cc_image_migration.log';
		$featured_image_log_file      = 'cc_featured_image_migration.log';
		$meta_key_for_processed_posts = '_newspack_migration_migrate_gallery_and_thumbnail';

		$post_ids       = $this->batch_query_without_meta_key( $meta_key_for_processed_posts, $assoc_args );
		$posts_this_run = count( $post_ids );
		$counter        = 0;
		foreach ( $this->batch_query_without_meta_key( $meta_key_for_processed_posts, $assoc_args ) as $post_id ) {
			WP_CLI::line( sprintf( 'Post %d/%d', ++$counter, $posts_this_run ) );

			// Import featured image.
			$featured_image_id  = 0;
			$featured_image_url = get_post_meta( $post_id, 'cc_featured_image', true );
			if ( ! empty( $featured_image_url ) ) {
				$featured_image_id = $this->attachments_logic->import_attachment_for_post( $post_id, $featured_image_url );
				if ( is_wp_error( $featured_image_id ) ) {
					$this->logger->log( $featured_image_log_file, sprintf( "Can't import featured image %s: %s", $featured_image_url, $featured_image_id->get_error_message() ),
						Logger::WARNING );
				} else {
					set_post_thumbnail( $post_id, $featured_image_id );
					$this->logger->log( $featured_image_log_file, sprintf( 'Featured image for post %d is set: %s', $post_id, $featured_image_id ), Logger::SUCCESS );
				}
			}

			// Import images.
			$image_urls = json_decode( get_post_meta( $post_id, 'cc_images', true ), true ) ?? [];
			$image_ids  = [];
			foreach ( $image_urls as $image_url ) {
				$image_id = $this->attachments_logic->import_attachment_for_post( $post_id, $image_url );
				if ( is_wp_error( $image_id ) ) {
					$this->logger->log( $images_log_file, sprintf( "Can't import image %s: %s", $image_url, $image_id->get_error_message() ), Logger::WARNING );
				} else {
					$image_ids[] = $image_id;
				}
			}

			if ( empty( $image_ids ) ) {
				update_post_meta( $post_id, $meta_key_for_processed_posts, true );
				continue;
			}

			$post = get_post( $post_id );

			if ( empty( $featured_image_id ) ) {
				// Set the first image as featured image if we didn't fine one already.
				set_post_thumbnail( $post_id, $image_ids[0] );
				$this->logger->log( $featured_image_log_file, sprintf( 'Featured image for post %d is set: %s', $post->ID, $featured_image_id ), Logger::SUCCESS );
			}

			// If there is more than more image, create a slideshow.
			if ( count( $image_ids ) > 1 ) {
				$image_content = $this->gutenberg_block_generator->get_jetpack_slideshow( $image_ids );
				$type_created  = 'Gallery';
			} else {
				$attachment_post = get_post( $image_ids[0] );
				$image_content   = $this->gutenberg_block_generator->get_image( $attachment_post, '', false, 'cc-content-image-top' );
				$type_created    = 'Top image';
			}

			// Update post content by adding the img/slideshow block on the top of the content.
			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => serialize_block( $image_content ) . $post->post_content,
				]
			);

			$this->logger->log( $images_log_file, sprintf( '%s for post %d is created: %s', $type_created, $post->ID, implode( ',', $image_ids ) ), Logger::SUCCESS );

			update_post_meta( $post->ID, $meta_key_for_processed_posts, true );
		}
	}

	private function batch_query_without_meta_key( string $meta_key, array $assoc_args ) {
		$posts_per_batch = $assoc_args['posts-per-batch'] ?? 1000;
		$batch           = ( $assoc_args['batch'] ?? 1 ) - 1;

		global $wpdb;
		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts 
				WHERE ID NOT IN ( SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '%s'	) 
				AND post_type = 'post'",
			$meta_key
		) );

		$total_posts    = count( $post_ids );
		$post_ids       = array_slice( $post_ids, ( $batch * $posts_per_batch ), $posts_per_batch );
		$posts_this_run = count( $post_ids );
		WP_CLI::line( sprintf( 'Will process %d posts this run. There are %d in total', $posts_this_run, $total_posts ) );

		return $post_ids;
	}

}