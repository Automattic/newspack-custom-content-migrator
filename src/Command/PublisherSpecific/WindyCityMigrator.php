<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \WP_User;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\BatchLogic;
use \NewspackCustomContentMigrator\Utils\CsvIterator;
use \NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for Saporta News.
 */
class WindyCityMigrator implements InterfaceCommand {
	const LOG_FILE             = 'windy-city-migrator.log';
	const ORIGINAL_ID_META_KEY = '_newspack_original_id';

	/**
	 * CSV input file.
	 *
	 * @var array $csv_input_file CSV input file.
	 */
	private array $csv_input_file = [
		'type'        => 'assoc',
		'name'        => 'csv-input-file',
		'description' => 'Path to CSV input file.',
		'optional'    => false,
	];

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * @var CsvIterator.
	 */
	private $csv_iterator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments_logic         = new AttachmentsLogic();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
		$this->logger                    = new Logger();
		$this->csv_iterator              = new CsvIterator();
	}

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
			'newspack-content-migrator windy-city-migrator',
			[ $this, 'cmd_windy_city_migrator' ],
			[
				'shortdesc' => 'Custom migration scripts for Windy City.',
				'synopsis'  => [
					$this->csv_input_file,
					...BatchLogic::get_batch_args(),
					[
						'type'        => 'assoc',
						'name'        => 'default-author-display-name',
						'description' => 'Default author display name.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'default-author-email',
						'description' => 'Default author email.',
						'optional'    => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-content',
						'description' => 'Refresh the content of the posts that were already imported.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator windy-city-migrator`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_windy_city_migrator( $args, $assoc_args ) {
		$csv_file_path               = $assoc_args[ $this->csv_input_file['name'] ];
		$default_author_display_name = $assoc_args['default-author-display-name'];
		$default_author_email        = $assoc_args['default-author-email'];
		$refresh_content             = isset( $assoc_args['refresh-content'] ) ? true : false;
		$batch_args                  = $this->csv_iterator->validate_and_get_batch_args_for_file( $csv_file_path, $assoc_args, ',' );
		$total_entries               = $this->csv_iterator->count_csv_file_entries( $csv_file_path, ',' );
		$entries                     = $this->csv_iterator->batched_items( $csv_file_path, ',', $batch_args['start'], $batch_args['end'] );
		$existing_original_ids       = $this->get_existing_original_ids();

		$this->logger->log( self::LOG_FILE, sprintf( 'Migrating %d entries.', $total_entries ) );

		foreach ( $entries as $index => $entry ) {
			$this->logger->log( self::LOG_FILE, sprintf( 'Migrating entry %d/%d.', $index + 1, $batch_args['end'] ), Logger::LINE );

			// Check if post exists.
			if ( ! $refresh_content && in_array( $entry['GUID'], $existing_original_ids ) ) {
				$this->logger->log( self::LOG_FILE, ' -- Article already exists: ' . $entry['TITLE'], Logger::WARNING );
				continue;
			}

			// Post Author.
			$author_id = $this->get_create_author( $entry['AUTHOR'], $default_author_display_name, $default_author_email );
			if ( false === $author_id ) {
				continue;
			}

			// Create post.
			$post_data = [
				'post_title'     => $entry['TITLE'],
				'post_content'   => $entry['BODY'],
				'post_excerpt'   => $entry['SUMMARY'],
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'post_author'    => $author_id,
				'comment_status' => 'yes' === $entry['ACOMMENTS'] ? 'open' : 'closed',
			];

			// Dates are in Central Time and should be converted to UTC.
			$gmt_date = new \DateTime( $entry['DATE'], new \DateTimeZone( 'America/Chicago' ) );
			$gmt_date->setTimezone( new \DateTimeZone( 'UTC' ) );
			$post_data['post_date_gmt'] = $gmt_date->format( 'Y-m-d H:i:s' );

			if ( ! empty( $entry['CANONICALURL'] ) ) {
				// Canonical URL is in the format: https://www.windycitymediagroup.com/lgbt/{post-slug}/69796.html
				// We need to extract the post slug from the URL.
				$canonical_url = $entry['CANONICALURL'];
				preg_match( '/\/lgbt\/(?<post_slug>[^\/]+)\/\d+\.html/', $canonical_url, $matches );
				if ( ! empty( $matches['post_slug'] ) ) {
					$post_slug              = $matches['post_slug'];
					$post_data['post_name'] = $post_slug;
				} else {
					$this->logger->log( self::LOG_FILE, ' -- Error extracting post slug from canonical URL: ' . $canonical_url, Logger::WARNING );
				}
			}

			// Create or get the post.
			$post_id = $this->get_create_post( $entry['GUID'], $post_data );


			// Migrate post content.
			$post_content = $entry['BODY'];

			// Galleries.
			$gallery_content = $entry['MORE_IMAGES'];
			// Gallery data is in the entry attributes GALLERY1...GALLERY8.
			for ( $i = 1; $i <= 8; $i++ ) {
				if ( ! empty( $entry[ 'GALLERY' . $i ] ) ) {
					$gallery_content .= $entry[ 'GALLERY' . $i ];
				}
			}

			$post_content = $this->migrate_gallery( $post_id, $post_content, $gallery_content );

			// If the post contains a gallery, we need to hide the featured image.
			if ( ! empty( $gallery_content ) ) {
				update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
			}

			// Embeds.
			if ( ! empty( $entry['EMBED'] ) ) {
				$post_content = $this->migrate_embed( $post_content, $entry['EMBED'] );
			}

			// Update post content.
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $post_content,
				]
			);

			// Post Tags.
			$post_tags = explode( ';', $entry['TAGS'] );
			$post_tags = array_map( 'trim', $post_tags );
			wp_set_post_tags( $post_id, $post_tags );

			// Post Categories.
			$post_categories = explode( ';', $entry['CATEGORY'] );
			$catgories_ids   = [];
			foreach ( $post_categories as $category_index => $post_category ) {
				$post_category = trim( $post_category );

				if ( 0 !== $category_index ) {
					$parent_category_id = get_cat_ID( $post_categories[ $category_index - 1 ] );
					$category_id        = wp_create_category( $post_category, $parent_category_id );
				} else {
					$category_id = wp_create_category( $post_category );
				}

				if ( ! is_wp_error( $category_id ) ) {
					$catgories_ids[] = $category_id;
				}
			}
			wp_set_post_categories( $post_id, $catgories_ids );

			// Featured Image.
			if ( ! empty( $entry['FEATURED'] ) ) {
				$attachment_id = $this->attachments_logic->import_external_file( $entry['FEATURED'], $entry['TITLE'], $entry['FEATURED_CAPTION'], null, null, $post_id );

				if ( is_wp_error( $attachment_id ) ) {
					$this->logger->log( self::LOG_FILE, ' -- Error importing attachment: ' . $attachment_id->get_error_message(), Logger::WARNING );
				} else {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			} elseif ( ! empty( $gallery_content ) ) {
				// If the post contains a gallery, we'll set the first image as the featured image.
				$gallery_images = explode( ';', $gallery_content );
				$first_image    = trim( current( $gallery_images ) );
				$first_image    = current( explode( '|', $first_image ) );

				$attachment_id = $this->attachments_logic->import_external_file( $first_image, $entry['TITLE'], null, null, null, $post_id );

				if ( is_wp_error( $attachment_id ) ) {
					$this->logger->log( self::LOG_FILE, ' -- Error importing attachment: ' . $attachment_id->get_error_message(), Logger::WARNING );
				} else {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			}

			// A few meta fields.
			update_post_meta( $post_id, self::ORIGINAL_ID_META_KEY, $entry['GUID'] );
			update_post_meta( $post_id, 'newspack_post_subtitle', $entry['SUBTITLE'] );

			$this->logger->log( self::LOG_FILE, ' -- Article migrated with ID: ' . $post_id, Logger::SUCCESS );
		}
	}

	/**
	 * Get or create post.
	 * If post exists, return its ID.
	 * If post does not exist, create it and return its ID.
	 *
	 * @param string $original_id Original ID.
	 * @param array  $post_data Post data.
	 *
	 * @return int|bool Post ID or false on failure.
	 */
	private function get_create_post( $original_id, $post_data ) {
		global $wpdb;

		$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s", self::ORIGINAL_ID_META_KEY, $original_id ) );

		if ( $post_id ) {
			// Post exists.
			$this->logger->log( self::LOG_FILE, ' -- Post already exists with ID: ' . $post_id, Logger::LINE );
			return $post_id;
		}

		// Post does not exist.
		// Create post.
		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			$this->logger->log( self::LOG_FILE, ' -- Error creating post: ' . $post_id->get_error_message(), Logger::WARNING );
			return false;
		}

		$this->logger->log( self::LOG_FILE, ' -- Post created with ID: ' . $post_id, Logger::LINE );
		return $post_id;
	}

	/**
	 * Get or create author.
	 *
	 * @param string $display_name Author name.
	 * @param string $default_author_display_name Default author display name.
	 * @param string $default_author_email Default author email.
	 *
	 * @return int|bool Author ID or false on failure.
	 */
	private function get_create_author( $display_name, $default_author_display_name, $default_author_email ) {
		if ( empty( $display_name ) ) {
			$username = sanitize_user( $default_author_display_name, true );
			$author   = get_user_by( 'login', $username );

			if ( $author instanceof WP_User ) {
				return $author->ID;
			}

			$author_id = wp_insert_user(
				[
					'display_name' => $default_author_display_name,
					'user_login'   => $username,
					'user_email'   => $default_author_email,
					'user_pass'    => wp_generate_password(),
					'role'         => 'author',
				]
			);

			if ( is_wp_error( $author_id ) ) {
				$this->logger->log( self::LOG_FILE, ' -- Error creating author: ' . $author_id->get_error_message(), Logger::WARNING );
				return false;
			}

			$this->logger->log( self::LOG_FILE, ' -- Author (' . $default_author_display_name . ') created with ID: ' . $author_id, Logger::SUCCESS );

			return $author_id;
		}

		// check if the display name is an email.
		if ( str_contains( $display_name, '@' ) ) {
			$display_name = explode( '@', $display_name )[0];
		}

		// Author name is not empty.
		$username = sanitize_user( $display_name, true );
		// check if username is longer than 60 chars.
		if ( strlen( $username ) > 60 ) {
			$username = substr( $username, 0, 60 );
		}
		$author = get_user_by( 'login', $username );

		if ( $author instanceof WP_User ) {
			return $author->ID;
		}

		$author_id = wp_insert_user(
			[
				'display_name' => $display_name,
				'user_login'   => $username,
				'user_pass'    => wp_generate_password(),
				'role'         => 'author',
			]
		);

		if ( is_wp_error( $author_id ) ) {
			$this->logger->log( self::LOG_FILE, ' -- Error creating author: ' . $author_id->get_error_message(), Logger::WARNING );
			return false;
		}

		$this->logger->log( self::LOG_FILE, ' -- Author (' . $display_name . ') created with ID: ' . $author_id, Logger::SUCCESS );

		return $author_id;
	}

	/**
	 * Migrate gallery.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_content Post content.
	 * @param string $gallery Gallery.
	 * @return string Post content.
	 */
	private function migrate_gallery( $post_id, $post_content, $gallery ) {
		if ( empty( $gallery ) ) {
			return $post_content;
		}

		$gallery_images = explode( ';', $gallery );
		$gallery_images = array_map( 'trim', $gallery_images );

		$gallery_image_ids = [];
		foreach ( $gallery_images as $gallery_image ) {
			if ( empty( $gallery_image ) ) {
				continue;
			}

			// $gallery_image is in the format: https://www.windycitymediagroup.com/images/publications/wct/2019-11-06/cover.jpg|Caption
			$gallery_image = explode( '|', $gallery_image );
			$gallery_image = array_map( 'trim', $gallery_image );
			$image_url     = $gallery_image[0];
			$caption       = $gallery_image[1] ?? null;
			$attachment_id = $this->attachments_logic->import_external_file( $image_url, null, $caption, null, null, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( self::LOG_FILE, ' -- Error importing attachment: ' . $attachment_id->get_error_message(), Logger::WARNING );
				continue;
			}

			$this->logger->log( self::LOG_FILE, ' -- Imported attachment with ID: ' . $attachment_id, Logger::LINE );
			$gallery_image_ids[] = $attachment_id;
		}

		$gallery_content = empty( $gallery_image_ids ) ? '' : serialize_block(
			$this->gutenberg_block_generator->get_jetpack_slideshow( $gallery_image_ids )
		);

		if ( ! empty( $gallery_content ) ) {
			$this->logger->log( self::LOG_FILE, ' -- With gallery. ' );
		}

		return $gallery_content . $post_content;
	}

	/**
	 * Migrate embed.
	 *
	 * @param string $post_content Post content.
	 * @param string $embed Embed.
	 * @return string Post content.
	 */
	private function migrate_embed( $post_content, $embed ) {
		if ( empty( $embed ) ) {
			return $post_content;
		}

		$embed = preg_replace( '/\\\\/', '', $embed );

		// Youtube Embed.
		if ( str_contains( $embed, 'youtube.com' ) || str_contains( $embed, 'youtu.be' ) ) {
			preg_match( '/^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?(?P<id>[^#&?"\']*).*/', $embed, $video_id_matcher );
			$youtube_id = array_key_exists( 'id', $video_id_matcher ) ? $video_id_matcher['id'] : null;

			if ( ! $youtube_id ) {
				$this->logger->log( self::LOG_FILE, ' -- Error extracting youtube ID from embed: ' . $embed, Logger::WARNING );
				return $post_content;
			}

			$media_content = serialize_block(
				$this->gutenberg_block_generator->get_youtube( $youtube_id )
			);

			return $post_content . $media_content;
		}

		// Get iframe src.
		preg_match( '/src="(?P<iframe_src>[^"]+)"/', $embed, $iframe_src_matcher );
		$iframe_src = array_key_exists( 'iframe_src', $iframe_src_matcher ) ? $iframe_src_matcher['iframe_src'] : null;

		if ( ! $iframe_src ) {
			$this->logger->log( self::LOG_FILE, ' -- Error extracting iframe src from embed: ' . $embed, Logger::WARNING );
			return $post_content;
		}

		return $post_content . serialize_block(
			$this->gutenberg_block_generator->get_iframe( $iframe_src )
		);
	}

	/**
	 * Get existing original IDs.
	 *
	 * @return array Existing original IDs.
	 */
	private function get_existing_original_ids() {
		global $wpdb;

		$existing_original_ids = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = %s", self::ORIGINAL_ID_META_KEY ) );

		return $existing_original_ids;
	}
}
