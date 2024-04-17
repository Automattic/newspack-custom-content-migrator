<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Medium;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use NewspackCustomContentMigrator\Utils\Logger;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Migrates Medium archive.
 */
class MediumMigrator implements InterfaceCommand {
	const ORIGINAL_ID_META_KEY = '_medium_original_id';

	/**
	 * Filename of where to save the log.
	 *
	 * @var string $log_file Log file name.
	 */
	private static $log_file = 'medium-migrator.log';

	/**
	 * Migrator instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Medium logic instance.
	 *
	 * @var Medium
	 */
	private $medium_logic = null;

	/**
	 * Instance of Attachments Login
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * Instance of SimpleLocalAvatars.
	 *
	 * @var null|SimpleLocalAvatars
	 */
	private $simple_local_avatars_logic;

	/**
	 * Logger instance.
	 *
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->medium_logic               = new Medium();
		$this->attachments                = new Attachments();
		$this->simple_local_avatars_logic = new SimpleLocalAvatars();
		$this->logger                     = new Logger();
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
			'newspack-content-migrator migrate-medium-archive',
			array( $this, 'cmd_medium_archive' ),
			[
				'shortdesc' => 'Migrates Medium archive.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'zip-archive',
						'description' => 'Medium archive zip file full path (no ending slash).',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-content',
						'description' => 'If set, it will refresh the existing content, and import the new one.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'ignore-broken-images',
						'description' => 'Only pass this flag if you are OK with getting images imported wrong.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for "newspack-content-migrator migrate-medium-archive".
	 *
	 * @param array $args array Command arguments.
	 * @param array $assoc_args array Command associative arguments.
	 */
	public function cmd_medium_archive( $args, $assoc_args ) {
		if ( ! $this->simple_local_avatars_logic->is_sla_plugin_active() ) {
			WP_CLI::error( 'Simple Local Avatars not found. Install and activate it before using this command.' );
		}

		if ( ! WP_CLI\Utils\get_flag_value($assoc_args, 'ignore-broken-images', false ) ) {
			WP_CLI::error( 'You must pass the --ignore-broken-images flag to use this command. Ideally the image importer should be fixed before running. See TODO comment in import_post_images().' );
		}

		$articles_csv_file_path = $assoc_args['zip-archive'];
		$refresh_content        = isset( $assoc_args['refresh-content'] ) ? true : false;

		$this->logger->log( self::$log_file, sprintf( 'Migrating Medium archive: %s', $articles_csv_file_path ), Logger::LINE );

		$result                = $this->medium_logic->process_file( $articles_csv_file_path );
		$existing_original_ids = $this->get_existing_original_ids();

		if ( is_wp_error( $result ) ) {
			$this->logger->log( self::$log_file, 'Error: ' . $result->get_error_message(), Logger::LINE );

			return;
		}

		foreach ( $this->medium_logic->get_items() as $article ) {
			$this->logger->log( self::$log_file, 'Processing article: ' . $article['title'], Logger::LINE );

			// Check if post exists.
			if ( ! $refresh_content && in_array( $article['original_id'], $existing_original_ids ) ) {
				$this->logger->log( self::$log_file, ' -- Article already exists: ' . $article['title'], Logger::LINE );
				continue;
			}

			$this->process_post( $article, $refresh_content );
		}
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

	/**
	 * Get or insert author.
	 *
	 * @param array $author Author data.
	 *
	 * @return int|bool Author ID or false on error.
	 */
	private function get_or_insert_author( $author ) {
		$author_id = username_exists( $author['user_login'] );
		if ( ! $author_id ) {
			$author_id = wp_insert_user(
				[
					'user_login'   => $author['user_login'],
					'user_pass'    => wp_generate_password(),
					'user_email'   => $author['email'],
					'display_name' => $author['display_name'],
					'role'         => 'author',
				]
			);

			if ( is_wp_error( $author_id ) ) {
				$this->logger->log( self::$log_file, ' -- Error creating author: ' . $author_id->get_error_message(), Logger::WARNING );

				return false;
			}

			if ( ! empty( $author['avatar'] ) ) {
				$avatar_id = $this->attachments->import_external_file( $author['avatar'], $author['display_name'] );
				if ( is_wp_error( $avatar_id ) ) {
					$this->logger->log( self::$log_file, ' -- Error importing author avatar: ' . $avatar_id->get_error_message(), Logger::WARNING );
				} else {
					$this->simple_local_avatars_logic->assign_avatar( $author_id, $avatar_id );
				}
			}

			$this->logger->log( self::$log_file, ' -- Author created with ID: ' . $author_id, Logger::SUCCESS );
		}

		return $author_id;
	}

	/**
	 * Import images from the Post content.
	 *
	 * @param string $post_content Post content.
	 * @param int    $post_id Post ID.
	 *
	 * @return string Post content with updated image URIs.
	 */
	private function import_post_images( $post_content, $post_id ) {
		// Extract attributes from all the `<img>`s.
		$img_data = ( new Crawler( $post_content ) )->filterXpath( '//img' )->extract( array( 'src', 'title', 'alt' ) );

		if ( empty( $img_data ) ) {
			return $post_content;
		}

		$post_content_updated = $post_content;
		foreach ( $img_data as $img_datum ) {
			$src   = trim( $img_datum[0] );
			$title = $img_datum[1];
			$alt   = $img_datum[2];

			// Check if the local image file exists, which will decide whether the image will be imported form file or downloaded.
			$is_src_absolute = ( 0 === strpos( strtolower( $src ), 'http' ) );

			if ( ! $is_src_absolute ) {
				WP_CLI::warning( sprintf( '❗ Image src is not absolute: %s', $src ) );
				continue;
			}

			// If the `<img>` `title` and `alt` are still empty, let's use the image file name without the extension.
			$filename_wo_extension = str_replace( '.' . pathinfo( $src, PATHINFO_EXTENSION ), '', pathinfo( $src, PATHINFO_FILENAME ) );
			$title                 = empty( $title ) ? $filename_wo_extension : $title;
			$alt                   = empty( $alt ) ? $filename_wo_extension : $alt;

			// Download or import the image file.
			WP_CLI::line( sprintf( '✓ importing %s ...', $src ) );
			$attachment_id = $this->attachments->import_external_file( $src, $title, null, null, $alt, $post_id );

			// Replace the URI in Post content with the new one.
			$img_uri_new          = wp_get_attachment_url( $attachment_id );
			// TODO. We need to do more than update the src. If the image has height and width attributes, we need to
			// remove those - or even better: use an image block.
			$post_content_updated = str_replace( array( esc_attr( $src ), $src ), $img_uri_new, $post_content_updated );
		}

		return $post_content_updated;
	}

	/**
	 * Inserts an article into the database.
	 *
	 * @param array $article Article data.
	 * @param bool  $refresh_content If set, it will refresh the existing content, and import the new one.
	 */
	private function process_post( $article, $refresh_content ) {
		$author = $this->medium_logic->get_author();
		if ( empty( $author ) && defined( 'NCCM_MEDIUM_FALLBACK_AUTHOR_USERNAME' ) ) {
			$author = [ 'user_login' => NCCM_MEDIUM_FALLBACK_AUTHOR_USERNAME ];
		}
		// Get/add author.
		if ( empty( $author ) ) {
			$this->logger->log( self::$log_file, ' -- Error: Author not found: ' . $article['author'], Logger::WARNING );

			return;
		}

		$author_id = $this->get_or_insert_author( $author );

		if ( ! $author_id ) {
			return;
		}

		$existing_post_id = $refresh_content ? $this->get_post_id_by_meta( $article['original_id'] ) : null;

		$data = 	[
			'post_title'     => $article['title'],
			'post_name'      => $article['original_slug'] ?? '',
			'post_content'   => $article['content'],
			'post_status'    => $article['status'],
			'post_type'      => $article['post_type'],
			'post_date_gmt'  => $article['post_date_gmt'],
			'comment_status' => $article['comment_status'],
			'ping_status'    => $article['ping_status'],
			'post_author'    => $author_id,
		];
		if ( $existing_post_id ) {
			$post_id = $existing_post_id;
			$data['ID'] = $post_id;
			wp_update_post( $data );
		} else {
			$post_id = wp_insert_post( $data );
		}
		$verb = $existing_post_id ? 'updated' : 'inserted';

		// Import images from the Post content.
		$post_content = $this->import_post_images( $article['content'], $post_id );

		if ( $post_content !== $article['content'] ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $post_content,
				]
			);
		}

		if ( is_wp_error( $post_id ) ) {
			$this->logger->log( self::$log_file, 'Error: ' . $post_id->get_error_message(), Logger::LINE );

			return;
		}

		$this->logger->log( self::$log_file, ' -- Article ' . $verb . ' with ID: ' . $post_id, Logger::LINE );

		// Set the featured image.
		if ( ! empty( $article['featured_image'] ) ) {
			$featured_image_id = $this->attachments->import_external_file( $article['featured_image']['url'], $article['title'], $article['featured_image']['caption'], null, null,
				$post_id );
			if ( is_wp_error( $featured_image_id ) ) {
				$this->logger->log( 'featured-images-import-fail.log', ' -- Error ' . $verb . ' featured image: ' . $featured_image_id->get_error_message(), Logger::WARNING );
			} else {
				set_post_thumbnail( $post_id, $featured_image_id );
			}
		}

		// Set article taxonomies.
		foreach ( $article['post_taxonomies'] as $term ) {
			$created_term = term_exists( $term['name'], $term['domain'] );

			if ( ! $created_term ) {
				$created_term = wp_insert_term( $term['name'], $term['domain'], [ 'slug' => $term['slug'] ] );

				if ( is_wp_error( $created_term ) ) {
					$this->logger->log( self::$log_file, ' -- Error creating term: ' . $created_term->get_error_message(), Logger::WARNING );
					continue;
				}
			}

			wp_set_post_terms( $post_id, [ intval( $created_term['term_id'] ) ], $term['domain'], true );

			$this->logger->log( self::$log_file, ' -- Article taxonomy set: ' . $term['domain'] . ' - ' . $term['name'], Logger::LINE );
		}

		// Set a few meta fields.
		update_post_meta( $post_id, self::ORIGINAL_ID_META_KEY, $article['original_id'] );
		update_post_meta( $post_id, '_medium_post_url', $article['post_url'] );
		update_post_meta( $post_id, 'newspack_post_subtitle', $article['subtitle'] );
	}

	/**
	 * Get post ID by meta.
	 *
	 * @param string $original_id Article original ID.
	 *
	 * @return int|null
	 */
	private function get_post_id_by_meta( $original_id ) {
		global $wpdb;

		if ( empty( $original_id ) ) {
			return null;
		}

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
				self::ORIGINAL_ID_META_KEY,
				$original_id
			)
		);
	}
}
