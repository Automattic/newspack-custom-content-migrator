<?php
/**
 * AttachmentsMigrator class.
 *
 * @package newspack-custom-content-converter
 */

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use \WP_CLI;

/**
 * Attachments general Migrator command class.
 */
class AttachmentsMigrator implements InterfaceMigrator {
	// Logs.
	const S3_ATTACHMENTS_URLS_LOG      = 'S3_ATTACHMENTS_URLS.log';
	const MISSING_ATTACHMENTS_URLS_LOG = 'MISSING_ATTACHMENTS_URLS.log';

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachment_logic;

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachment_logic = new AttachmentsLogic();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator attachments-get-ids-by-years',
			[ $this, 'cmd_get_atts_by_years' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator attachments-switch-local-images-urls-to-s3-urls',
			[ $this, 'cmd_switch_local_images_urls_to_s3_urls' ],
			[
				'shortdesc' => 'Switch images URLs from local URLs to S3 bucket based URLs.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually edit the posts content.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post_ids',
						'description' => 'IDs of posts and pages to remove shortcodes from their content separated by a comma (e.g. 123,456)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator attachments-check-broken-images',
			[ $this, 'cmd_check_broken_images' ],
			[
				'shortdesc' => 'Check images with broken URLs in-story.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'is-using-s3',
						'description' => 'If the in-story images are hosted on Amazon S3. The S3-uploads plugin should be enabled if this flag is set.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts_per_batch',
						'description' => 'Posts per batch, if we\'re planning to run this in batches.',
						'optional'    => true,
						'default'     => -1,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch number, if we\'re planning to run this in batches.',
						'optional'    => true,
						'default'     => 1,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'index',
						'description' => 'Index to start from, in case the command is killed check the last index and start from it.',
						'optional'    => true,
						'default'     => 0,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Gets a list of attachment IDs by years for those attachments which have files on local in (/wp-content/uploads).
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative Arguments.
	 *
	 * @return void
	 */
	public function cmd_get_atts_by_years( $pos_args, $assoc_args ) {
		global $wpdb;
		$ids_years  = [];
		$ids_failed = [];

		// phpcs:ignore
		$att_ids = $wpdb->get_results( "select ID from {$wpdb->posts} where post_type = 'attachment' ; ", ARRAY_A );
		foreach ( $att_ids as $key_att_id => $att_id_row ) {
			$att_id = $att_id_row['ID'];
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_att_id + 1, count( $att_ids ), $att_id ) );

			// Check if this attachment is in local wp-content/uploads.
			$url                        = wp_get_attachment_url( $att_id );
			$url_pathinfo               = pathinfo( $url );
			$dirname                    = $url_pathinfo['dirname'];
			$pathmarket                 = '/wp-content/uploads/';
			$pos_pathmarker             = strpos( $dirname, $pathmarket );
			$dirname_remainder          = substr( $dirname, $pos_pathmarker + strlen( $pathmarket ) );
			$dirname_remainder_exploded = explode( '/', $dirname_remainder );

			// Group by years folders.
			$year = isset( $dirname_remainder_exploded[0] ) && is_numeric( $dirname_remainder_exploded[0] ) && ( 4 === strlen( $dirname_remainder_exploded[0] ) ) ? (int) $dirname_remainder_exploded[0] : null;
			if ( is_null( $year ) ) {
				$ids_failed[ $att_id ] = $url;
			} else {
				$ids_years[ $year ][] = $att_id;
			}
		}

		// Save {$year}.txt file.
		foreach ( array_keys( $ids_years ) as $year ) {
			$att_ids = $ids_years[ $year ];
			$file    = $year . '.txt';
			file_put_contents( $file, implode( ' ', $att_ids ) . ' ' );
		}

		// Save 0_failed_ids.txt file for files which may not be on local.
		foreach ( $ids_failed as $att_id => $url ) {
			$file = '0_failed_ids.txt';
			file_put_contents( $file, $att_id . ' ' . $url . "\n", FILE_APPEND );
		}

		WP_CLI::log( sprintf( "> created {year}.txt's and %s", $file ) );
	}

	/**
	 * Switch images URLs from local URLs to S3 bucket based URLs.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative Arguments.
	 *
	 * @return void
	 */
	public function cmd_switch_local_images_urls_to_s3_urls( $pos_args, $assoc_args ) {
		$dry_run  = isset( $assoc_args['dry-run'] ) ? true : false;
		$post_ids = isset( $assoc_args['post_ids'] ) ? explode( ',', $assoc_args['post_ids'] ) : null;

		$posts = get_posts(
            [
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'post__in'       => $post_ids,
			]
        );

		$total_posts = count( $posts );
		foreach ( $posts as $index => $post ) {
			$post_content = $post->post_content;
			$this->log(
                self::S3_ATTACHMENTS_URLS_LOG,
                sprintf( 'Checking Post(%d/%d): %d', $index + 1, $total_posts, $post->ID )
            );

			preg_match_all( '/<img[^>]+(?:src|data-orig-file)="([^">]+)"/', $post->post_content, $image_sources_match );
			foreach ( $image_sources_match[1] as $image_source_match ) {
				if ( str_contains( $image_source_match, 's3.amazonaws.com' ) ) {
					$this->log(
						self::S3_ATTACHMENTS_URLS_LOG,
						sprintf(
							'Skipping image (%s).',
							$image_source_match
						)
					);

					continue;
				}
				if ( class_exists( \S3_Uploads\Plugin::class ) ) {
					$bucket       = \S3_Uploads\Plugin::get_instance()->get_s3_bucket();
					$exploded_url = explode( '/', $image_source_match );
					$filename     = end( $exploded_url );
					$month        = prev( $exploded_url );
					$year         = prev( $exploded_url );
					$s3_url       = 'https://' . $bucket . ".s3.amazonaws.com/wp-content/uploads/$year/$month/$filename";

					$image_request_from_s3 = wp_remote_head( $s3_url, [ 'redirection' => 5 ] );

					if ( is_wp_error( $image_request_from_s3 ) ) {
						$this->log(
                            self::S3_ATTACHMENTS_URLS_LOG,
							sprintf(
								'Skipping image (%s). S3 returned an error: %s',
								$s3_url,
								$image_request_from_s3->get_error_message()
							)
						);

						continue;
					}

					if ( 200 !== $image_request_from_s3['response']['code'] ) {
						$this->log(
                            self::S3_ATTACHMENTS_URLS_LOG,
							sprintf(
								'Skipping image (%s). Image not found on the bucket.',
								$s3_url
							)
						);

						continue;
					}

					// Image exists, do the change.
					$this->log(
						self::S3_ATTACHMENTS_URLS_LOG,
						sprintf(
							'Updating image from %s to %s.',
							$image_source_match,
							$s3_url
						)
					);
					$post_content = str_replace( $image_source_match, $s3_url, $post_content );
				}
			}

			if ( $post_content !== $post->post_content ) {
				if ( ! $dry_run ) {
					wp_update_post(
						array(
							'ID'           => $post->ID,
							'post_content' => $post_content,
						)
					);
				}
			}
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator attachments-check-broken-images`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_check_broken_images( $args, $assoc_args ) {
		$is_using_s3     = isset( $assoc_args['is-using-s3'] ) ? true : false;
		$posts_per_batch = $assoc_args['posts_per_batch'];
		$batch           = $assoc_args['batch'];
		$index           = $assoc_args['index'];

		$this->attachment_logic->get_broken_attachment_urls_from_posts(
            [],
            $is_using_s3,
            $posts_per_batch,
            $batch,
            $index,
            function( $post_id, $broken_url ) use ( $batch ) {
				$this->log( "broken_media_urls_batch_$batch.log", sprintf( '%d,%s', $post_id, $broken_url ) );
			}
        );
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
