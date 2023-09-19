<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
use WP_CLI;

class DownloadMissingImages implements InterfaceCommand {

	/**
	 * @var null|self
	 */
	private static $instance = null;

	private $command_meta_key = 'download_missing_images';
	private $command_meta_version;
	private $log_file;

	/**
	 * @var Attachments
	 */
	private $attachmentsLogic;

	/**
	 * @var Posts
	 */
	private $postsLogic;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachmentsLogic = new Attachments();
		$this->postsLogic       = new Posts();
		$this->logger           = new Logger();

		$this->command_meta_version = date( 'Y-m-d-H:i' );
		$this->log_file             = "{$this->command_meta_key}_{$this->command_meta_version}.log";
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


	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator download-missing-images',
			[ $this, 'cmd_download_missing_images' ],
			[
				'shortdesc' => 'Try to find and download missing images',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'media-dir',
						'description' => 'Location of media files on disk',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types',
						'description' => 'Post types to process',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-range',
						'description' => 'Post ID range to process - separated by a dash, e.g. 1-1000',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'Only run on the given post ID',
						'optional'    => true,
						'repeating'   => false,
					],
				]
			]
		);
	}

	public function parse_range_args( array $assoc_args ) {
		$range = [];
		if ( ! empty( $assoc_args['post-id-range'] ) ) {
			$vals  = explode( '-', $assoc_args['post-id-range'] );
			$range = [
				'min' => (int) $vals[0],
				'max' => (int) $vals[1],
			];
		}
		if ( ! empty( $assoc_args['post-id'] ) ) {
			$range = [
				'post-id' => $assoc_args['post-id'],
			];
		}
		return $range;
	}

	// You can use this command raw from CLI, but you might want to implement
	// the filters "newspack_content_migrator_download_images_sanctioned_hosts" and
	// "newspack_content_migrator_download_images_path_translations" to have it do
	// more.
	public function cmd_download_missing_images( $args, $assoc_args ): void {
		$this->download_missing_images(
			$assoc_args['media-dir'],
			$assoc_args['post-types'] ?? '',
			$assoc_args
		);
	}

	public function download_missing_images( string $media_location, string $post_types, array $range_args = [] ): void {
		$range = $this->parse_range_args( $range_args );
		if ( ! path_is_absolute( $media_location ) ) {
			$media_location = realpath( $media_location );
			if ( ! $media_location ) {
				WP_CLI::error( "Media location $media_location' does not exist." );
			}
			$media_location = trailingslashit( $media_location );
		}

		$path_translations = apply_filters( 'newspack_content_migrator_download_images_path_translations', [
			'relative' => [],
			'hosts'    => [],
		] );

		$sanctioned_hosts = apply_filters( 'newspack_content_migrator_download_images_sanctioned_hosts',
			array_keys( $path_translations['hosts'] )
		);

		$post_types = $post_types ? explode( ',', $post_types ) : [ 'post' ];
		$data_arr = self::get_posts_and_image_urls( $post_types, $range );
		foreach ( $data_arr as $data ) {
			WP_CLI::log( sprintf( 'Processing post ID %d: %s', $data['post']->ID, get_permalink( $data['post']->ID ) ) );

			$urls_to_replace = [];
			$urls_not_found  = [];
			foreach ( $data['image_urls'] as $url ) {
				WP_CLI::out( '.' );
				$sanctioned_url = false;
				if ( wp_http_validate_url( $url ) ) {
					$sanctioned_url = in_array( parse_url( $url, PHP_URL_HOST ), $sanctioned_hosts );
				} elseif ( str_starts_with( $url, '/' ) ) {
					$sanctioned_url = true;
				}
				if ( ! $sanctioned_url ) {
					// Don't process images from sites other than the ones we have sanctioned in either
					// the path_translations or the current site.
					continue;
				}

				$id = $this->process_url( $url, $data['post'], $media_location, $path_translations );
				if ( $id ) {
					$urls_to_replace[ $url ] = wp_get_attachment_url( $id );
				} else {
					$urls_not_found[ $url ] = $url;
				}
			}
			WP_CLI::line();

			if ( ! empty( $urls_to_replace ) ) {
				foreach ( $urls_to_replace as $from => $to ) {
					$data['post']->post_content = str_replace( $from, $to, $data['post']->post_content );
				}
				wp_update_post( $data['post'] );
			}

			if ( ! empty( $urls_not_found ) ) {
				$this->logger->log( $this->log_file,
					sprintf( "Could not find images for these images for post with ID %d: %s\n\t", $data['post']->ID, implode( "\n\t", $urls_not_found ) ),
					Logger::WARNING );
			}

			MigrationMeta::update( $data['post']->ID, $this->command_meta_key, 'post', $this->command_meta_version );
		}
	}

	public function process_url( string $url, \WP_Post $post, string $media_location, array $path_translations ): int|false {

		$url_host            = parse_url( $url, PHP_URL_HOST );
		$url_path            = parse_url( $url, PHP_URL_PATH );
		$url_is_current_site = parse_url( home_url(), PHP_URL_HOST ) === $url_host;

		if ( $url_is_current_site ) {
			return $this->process_current_site_url( $url_path, $post, $media_location );
		}

		// Is it in the media_folder?
		$media_import_path = $this->get_media_import_path_if_file_exists( $url_path, $media_location );
		if ( $media_import_path ) {
			$attachment_id = $this->attachmentsLogic->import_external_file( $media_import_path, false, false, false,
				false, $post->ID );
			if ( ! is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		if ( ! wp_http_validate_url( $url ) ) {
			$valid_path = false;
			foreach ( $path_translations['relative'] as $path => $full_url ) {
				if ( str_starts_with( $url, $path ) ) {
					$url        = $full_url . $url;
					$valid_path = true;
				}
			}
			if ( ! $valid_path ) {
				$this->logger->log( $this->log_file, sprintf( 'Invalid url %s on post with ID %d', $url, $post->ID ), Logger::WARNING );

				return false;
			}
		}

		if ( in_array( $url_host, array_keys( $path_translations['hosts'] ) ) ) {
			$url           = $path_translations['hosts'][ $url_host ] . $url_path;
			$attachment_id = $this->attachmentsLogic->import_external_file( $url, false, false, false, false,
				$post->ID );
			if ( ! is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
		}


		return false;
	}

	public function process_current_site_url( string $url_path, \WP_Post $post, string $media_location ): int|false {
		$path_in_uploads_dir = untrailingslashit( ABSPATH ) . $url_path;
		$attachment_id       = attachment_url_to_postid( $path_in_uploads_dir );

		if ( $attachment_id ) {
			if ( file_exists( $path_in_uploads_dir ) ) {
				// We have the attachment in the database, and the file in the uploads folder. All is well.
				return $attachment_id;
			}

			// The DB thinks we have the file. Let's see if it's in the media import folder by any chance and move it.
			$import_path = $this->get_media_import_path_if_file_exists( str_replace( '/wp-content/uploads', '',
				$url_path ),
				$media_location );
			if ( $import_path ) {
				$success = $this->move_local_file( $import_path, get_attached_file( $attachment_id ) );

				if ( is_wp_error( $success ) ) {
					return false;
				}
				$this->logger->log( $this->log_file, "Moved file from $url_path to " . get_attached_file( $attachment_id ), Logger::LINE );
				return $attachment_id;
			}
			$this->logger->log( $this->log_file, "Attachment {$attachment_id} exists in the database, but the file $url_path is missing.", Logger::WARNING );

			return false;
		}

		if ( file_exists( $path_in_uploads_dir ) ) {
			$local_path = untrailingslashit( ABSPATH ) . $url_path;
			// The file is where it should be, but the DB does not know about it. Let's import it.
			$attachment_id = $this->attachmentsLogic->import_external_file(
				$local_path,
				false,
				false,
				false,
				false,
				$post->ID );

			if ( is_wp_error( $attachment_id ) ) {
				return false;
			}

			return $attachment_id;

		}

		if ( file_exists( trailingslashit( $media_location) . $url_path ) ) {
			$local_path = trailingslashit( $media_location) . $url_path;
			// The file is where it should be, but the DB does not know about it. Let's import it.
			$attachment_id = $this->attachmentsLogic->import_external_file(
				$local_path,
				false,
				false,
				false,
				false,
				$post->ID );

			if ( is_wp_error( $attachment_id ) ) {
				return false;
			}

			return $attachment_id;
		}

		return false;
	}


	/**
	 * If a file exists in the "media" import folder (typically /tmp/media or similar), return
	 * the file path. Otherwise, return false.
	 *
	 * @param string $url_path
	 * @param string $media_location
	 *
	 * @return string|false
	 */
	private function get_media_import_path_if_file_exists( string $url_path, string $media_location ): string|false {
		// Remove leading slash if any.
		$url_path = ltrim( $url_path, '/\\' );
		// Filter so we can use paths other than the root of the media folder provided - e.g. images/ or similar.
		$locations = apply_filters(
			'newspack_content_migrator_media_content_subpaths',
			[
				basename( $url_path ),
				$url_path,
			],
			$media_location
		);

		foreach ( $locations as $location ) {
			if ( file_exists( $media_location . $location ) ) {
				return $media_location . $location;
			}
		}

		return false;
	}

	public function move_local_file( string $from, string $to ): bool {
		// Make sure the destination folder exists.
		wp_mkdir_p( dirname( $to ) );

		return copy( $from, $to );
	}

	/**
	 * @param string $post_types comma separated list of post types.
	 * @param array $range Just process this post ID.
	 *
	 * @return iterable
	 */
	private function get_posts_and_image_urls( array $post_types, array $range ): iterable {
		$ids = [];
		if ( ! empty( $range['post-id'] ) ) {
			if ( in_array( get_post_type( $range['post-id'] ), $post_types ) ) {
				$ids = [ (int) $range['post-id'] ];
			}
		} elseif ( isset( $range['min'] ) && isset( $range['max'] ) ) {
			$ids = $this->postsLogic->get_post_ids_in_range( $range['min'], $range['max'], $post_types );
		} else {
			$ids = $this->postsLogic->get_all_posts_ids( $post_types );
		}

		foreach ( $ids as $post_id ) {
			if ( MigrationMeta::get( $post_id, $this->command_meta_key, 'post' ) >= $this->command_meta_key ) {
				WP_CLI::log(
					sprintf(
						'Images already downloaded for post ID %d. Skipping.',
						$post_id
					)
				);
				continue;
			}

			$post       = get_post( $post_id );
			$image_urls = array_unique( $this->attachmentsLogic->get_images_sources_from_content( $post->post_content ) );
			if ( empty( $image_urls ) ) {
				continue;
			}

			yield [
				'post'       => $post,
				'image_urls' => $image_urls,
			];
		}
	}

}
