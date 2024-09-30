<?php
/**
 * AttachmentsMigrator class.
 *
 * @package newspack-custom-content-converter
 */

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use \NewspackCustomContentMigrator\Utils\Logger;
use simplehtmldom\HtmlDocument;
use \WP_CLI;

/**
 * Attachments general Migrator command class.
 */
class AttachmentsMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;
	// Logs.
	const S3_ATTACHMENTS_URLS_LOG = 'S3_AHTTACHMENTS_URLS.log';
	const DELETING_MEDIA_LOGS     = 'DELETING_MEDIA_LOGS.log';

	const ATTACHMENT_POST_TO_DELETE = '_newspack_attachment_to_delete';
	const ATTACHMENT_FILE_OLD_PATH  = '_newspack_attachment_old_path';
	const ATTACHMENT_TRASH_FOLDER   = 'newspack_media_trash';

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachment_logic;

	/**
	 * Logger.
	 *
	 * @var Logger $logger Logger instance.
	 */
	private $logger;

	/**
	 * @var GutenbergBlockGenerator
	 */
	private GutenbergBlockGenerator $block_generator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachment_logic = new AttachmentsLogic();
		$this->logger           = new Logger();
		$this->block_generator = new GutenbergBlockGenerator();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator attachments-get-ids-by-years',
			self::get_command_closure( 'cmd_get_atts_by_years' ),
		);

		WP_CLI::add_command(
			'newspack-content-migrator attachments-switch-local-images-urls-to-s3-urls',
			self::get_command_closure( 'cmd_switch_local_images_urls_to_s3_urls' ),
			[
				'shortdesc' => 'In all post_content it updates images URLs from local URLs to S3 bucket based URLs.',
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
			'newspack-content-migrator attachments-delete-posts-attachments',
			self::get_command_closure( 'cmd_attachment_delete_posts_attachments' ),
			[
				'shortdesc' => "This command deletes only posts' attachments (just those attachments which belong to posts), and it works in two steps. "
					. 'First we should run this command without the --confirm-deletion flag, and it will move the attachment files to a temporary folder. This is to double check and make sure we are not about to delete attachments that are still in use, and lets us QA the results first. '
					. 'Secondly, after we do a manual QA, we run this command a second time with the --confirm-deletion flag, at which point it will actually delete the attachments. '
					. 'In case we wanted to restore the attachments after the QA, we can use the --restore-attachments  flag.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run simulation and don\'t actually delete attachments.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'confirm-deletion',
						'description' => 'Delete the attachments marked to be deleted, this should be run after running the command a first time without this flag to mark the attachments to be deleted, and after QA\'ing the results.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'restore-attachments',
						'description' => 'A list of attachments IDs to be restored.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-from',
						'description' => 'Skip the attachment uploaded from this date. Format should be yyyy-mm-dd (e.g. 2022-11-17)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'skip-to',
						'description' => 'Skip the attachment uploaded to this date. Format should be yyyy-mm-dd (e.g. 2022-11-17)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator attachments-check-broken-images',
			self::get_command_closure( 'cmd_check_broken_images' ),
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

		WP_CLI::add_command(
			'newspack-content-migrator attachments-regenerate-media-thumbnails',
			self::get_command_closure( 'cmd_regenerate_media_thumbnails' ),
			[
				'shortdesc' => 'Regenerate media thumbnails in batches.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
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
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator attachments-get-hosts-from-post-content',
			self::get_command_closure( 'cmd_get_hosts_post_content' ),
			[
				'shortdesc' => 'Check images with broken URLs in-story.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator attachments-repair-img-blocks-w-no-id',
			self::get_command_closure( 'cmd_repair_img_blocks_w_no_id' ),
			[
				'shortdesc' => 'Repair image blocks with local images that have no ID and therefore are harder to edit for the user.',
				'synopsis'  => [
					BatchLogic::$num_items,
				],
			],
		);
	}

	public function cmd_repair_img_blocks_w_no_id( array $pos_args, array $assoc_args ): void {
		$logfile = __FUNCTION__ . '.log';

		global $wpdb;
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content FROM wp_posts WHERE post_content LIKE %s AND post_status = 'publish' AND post_type = 'post' ORDER BY ID LIMIT %d",
				'%' . $wpdb->esc_like( '<!-- wp:image' ) . '%',
				$assoc_args[ BatchLogic::$num_items['name'] ] ?? PHP_INT_MAX
			)
		);

		$total = count( $posts );
		$this->logger->log( $logfile, sprintf( 'Found %d posts with image blocks', $total ), Logger::INFO );
		$counter = 0;
		foreach ( $posts as $post ) {
			WP_CLI::log( sprintf( 'Processing post ID %d (%d/%d) ', $post->ID, ++$counter, $total ) );
			$blocks = parse_blocks( $post->post_content );
			// Target only image blocks with no id and a local image. "Local" is a bit un-scientific here,
			// but it's fast.
			$target_blocks = array_filter( $blocks, function ( $block ) {
				return 'core/image' === $block['blockName'] && empty( $block['attrs']['id'] ) && str_contains( $block['innerHTML'], '/wp-content/uploads/' );
			} );
			if ( empty( $target_blocks ) ) {
				continue;
			}

			foreach ( $target_blocks as $idx => $block ) {
				$doc = new HtmlDocument( $block['innerHTML'] );
				$img = $doc->find( 'img' );
				if ( empty( $img[0] ) ) {
					continue; // Not much we can do.
				}
				$src           = $img[0]->getAttribute( 'src' );
				$relative_url  = strstr( wp_parse_url( $src, PHP_URL_PATH ), '/wp-content' );
				$url           = get_site_url() . $relative_url;
				$attachment_id = attachment_url_to_postid( $url );
				if ( ! $attachment_id ) {
					// If the attachment doesn't exist, import it.
					$attachment_id = $this->attachment_logic->import_attachment_for_post( $post->ID, $src );

					if ( is_wp_error( $attachment_id ) ) {
						$this->logger->log( $logfile, sprintf( 'Failed to import attachment for post %d: %s', $post->ID, $attachment_id->get_error_message() ), Logger::ERROR );
						continue;
					}
				}

				$attachment_post = get_post( $attachment_id );
				if ( ! empty( $doc->find( 'figcaption' )[0] ) ) {
					// Temporarily set excerpt on the attachment post so the blocks generator
					// is tricked into using that as caption.
					$attachment_post->post_excerpt = $doc->find( 'figcaption' )[0]->innertext();
				}
				$repaired_block = $this->block_generator->get_image(
					$attachment_post,
					'full',
					false,
					'',
					$block['attrs']['align'] ?? 'center'
				);
				$blocks[ $idx ] = $repaired_block;
			}

			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => serialize_blocks( $blocks ),
				]
			);
			$this->logger->log( $logfile, sprintf( 'Updated post %s', get_permalink( $post->ID ) ), Logger::SUCCESS );
		}
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
		\Newspack\MigrationTools\Command\AttachmentsMigrator::get_instance()->cmd_get_atts_by_years( $pos_args, $assoc_args );
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
	 * Callable for `newspack-content-migrator attachments-delete-posts-attachments`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_attachment_delete_posts_attachments( $args, $assoc_args ) {
		$dry_run             = isset( $assoc_args['dry-run'] ) ? true : false;
		$confirm_deletion    = isset( $assoc_args['confirm-deletion'] ) ? true : false;
		$restore_attachments = isset( $assoc_args['restore-attachments'] ) ? explode( ',', $assoc_args['restore-attachments'] ) : false;
		$skip_from           = isset( $assoc_args['skip-from'] ) ? $assoc_args['skip-from'] : null;
		$skip_to             = isset( $assoc_args['skip-to'] ) ? $assoc_args['skip-to'] : null;

		if ( $confirm_deletion && $restore_attachments ) {
			WP_CLI::error( 'Only one of the two options `confirm-deletion` and `restore-attachments` can be chosed!' );
		}

		if ( $restore_attachments ) {
			$total_attachments = count( $restore_attachments );
			foreach ( $restore_attachments as $index => $attachment_to_restore ) {
				$attachment_file = get_post_meta( $attachment_to_restore, '_wp_attached_file', true );
				if ( ! $attachment_file ) {
					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Skipping restoring media (%d/%d): %d Can\'t locate its attachment in the database.', $index + 1, $total_attachments, $attachment_to_restore ) );
					continue;
				}

				$media_path = $this->get_trash_folder() . '/' . $attachment_file;

				if ( file_exists( $media_path ) ) {
					$new_file_path = $this->get_uploads_dir() . '/' . $attachment_file;
					$new_file_dir  = dirname( $new_file_path );

					if ( ! is_dir( $new_file_dir ) ) {
						mkdir( $new_file_dir, 0777, true );
					}

					rename( $media_path, $new_file_path );

					// Delete atatchment from database.
					delete_post_meta( $attachment_to_restore, self::ATTACHMENT_POST_TO_DELETE );
					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Restoring media (%d/%d): %d (%s)', $index + 1, $total_attachments, $attachment_to_restore, $media_path ) );
				} else {
					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'File not exists, media file not in trash folder (%d/%d): %d (%s)', $index + 1, $total_attachments, $attachment_to_restore, $media_path ) );
				}
			}
		} elseif ( $confirm_deletion ) {
			$attachment_args = [
				'posts_per_page' => -1,
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => self::ATTACHMENT_POST_TO_DELETE,
						'value'   => true,
						'compare' => '=',
					],
				],
			];

			if ( $skip_from && $skip_to ) {
				$attachment_args['date_query'] = [
					[ 'before' => $skip_from ],
					[ 'after' => $skip_to ],
					'relation' => 'OR',
				];
			}

			$attachment_posts_to_be_deleted = get_posts( $attachment_args );
			$total_attachment_posts         = count( $attachment_posts_to_be_deleted );

			foreach ( $attachment_posts_to_be_deleted as $index => $attachment_post_to_be_deleted ) {
				// Delete atatchment from filesystem.
				$attachment_file = get_post_meta( $attachment_post_to_be_deleted->ID, '_wp_attached_file', true );

				if ( ! $attachment_file ) {
					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Skipping deleting media (%d/%d): %d Can\'t locate its file.', $index + 1, $total_attachment_posts, $attachment_post_to_be_deleted->ID ) );
					continue;
				}

				$media_path = $this->get_trash_folder() . '/' . $attachment_file;
				if ( file_exists( $media_path ) ) {
					unlink( $media_path );
					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Deleting media (%d/%d): %d (%s)', $index + 1, $total_attachment_posts, $attachment_post_to_be_deleted->ID, $media_path ) );
				} else {
					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'File not exists, media file not in trash folder (%d/%d): %d (%s)', $index + 1, $total_attachment_posts, $attachment_post_to_be_deleted->ID, $media_path ) );
				}

				// Delete atatchment from database.
				wp_delete_attachment( $attachment_post_to_be_deleted->ID );
			}
		} else {
			// This is the first execution, we need to mark the attachments to be deleted and wait for the QA to be done.
			// Get attachments to not delete.
			$raw_image_urls_to_not_delete = array_merge(
				$this->get_all_non_posts_images_urls(),
				$this->get_all_widgets_images_urls(),
				$this->get_all_themes_mods_logos_urls(),
				$this->get_all_custom_css_urls(),
				$this->get_all_co_authors_avatars_urls(),
				$this->get_all_simple_local_avatars_urls(),
				$this->get_yoast_default_featured_image_urls()
			);

			$image_urls_to_not_delete = array_map(
				function( $url ) {
					return $this->clean_images_url( $url );
				},
				$raw_image_urls_to_not_delete
			);

			// Get filtered by date attachments.
			$attachment_args = [
				'posts_per_page' => -1,
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'meta_query'     => [
					[
						'key'     => self::ATTACHMENT_POST_TO_DELETE,
						'compare' => 'NOT EXISTS',
					],
				],
			];

			if ( $skip_from && $skip_to ) {
				$attachment_args['date_query'] = [
					[ 'before' => $skip_from ],
					[ 'after' => $skip_to ],
					'relation' => 'OR',
				];
			}

			$attachment_posts       = get_posts( $attachment_args );
			$total_attachment_posts = count( $attachment_posts );

			foreach ( $attachment_posts as $index => $attachment_post ) {
				$attachment_url      = wp_get_attachment_url( $attachment_post->ID );
				$relative_media_path = $this->clean_images_url( $attachment_url );

				if ( in_array( $relative_media_path, $image_urls_to_not_delete, true ) ) {
					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Skipping media(%d/%d): %d', $index + 1, $total_attachment_posts, $attachment_post->ID ) );
				} else {
					if ( ! $dry_run ) {
						$media_path = get_attached_file( $attachment_post->ID );
						// Mark the media post to be deleted and move the media file to another path for QA, before a definitive delete operation.
						update_post_meta( $attachment_post->ID, self::ATTACHMENT_POST_TO_DELETE, true );
						update_post_meta( $attachment_post->ID, self::ATTACHMENT_FILE_OLD_PATH, $media_path );

						// Move the media file to trash, before deleting it after QA.
						if ( file_exists( $media_path ) ) {
							$attachment_file = get_post_meta( $attachment_post->ID, '_wp_attached_file', true );
							if ( ! $attachment_file ) {
								$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Skipping media (%d/%d): %d Can\'t locate its file.)', $index + 1, $total_attachment_posts, $attachment_post->ID ) );
								continue;
							}

							$new_file_path = $this->get_trash_folder() . '/' . $attachment_file;
							$new_file_dir  = dirname( $new_file_path );

							if ( ! is_dir( $new_file_dir ) ) {
								mkdir( $new_file_dir, 0777, true );
							}

							rename( $media_path, $new_file_path );
						} else {
							$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Warning: Media file not located (%d/%d): %d (%s)', $index + 1, $total_attachment_posts, $attachment_post->ID, $media_path ) );
						}
					}

					$this->log( self::DELETING_MEDIA_LOGS, sprintf( 'Marking media to be deleted (%d/%d): %d (%s)', $index + 1, $total_attachment_posts, $attachment_post->ID, $media_path ) );
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
		$posts_per_batch = $assoc_args['posts_per_batch'] ?? null;
		$batch           = $assoc_args['batch'] ?? null;
		$index           = $assoc_args['index'] ?? null;
		$log_file_prefix = $assoc_args['log-file-prefix'] ?? 'broken_media_urls_batch';

		$this->attachment_logic->get_broken_attachment_urls_from_posts(
			[],
			$is_using_s3,
			$posts_per_batch,
			$batch,
			$index,
			function( $post_id, $broken_url ) use ( $batch, $log_file_prefix ) {
				$this->log( sprintf( '%s_%s.log', $log_file_prefix, $batch ), sprintf( '%d,%s', $post_id, $broken_url ) );
			}
		);
	}

	/**
	 * Callable for `newspack-content-migrator attachments-regenerate-media-thumbnails`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_regenerate_media_thumbnails( $args, $assoc_args ) {
		$posts_per_batch = $assoc_args['posts-per-batch'] ?? -1;
		$batch           = $assoc_args['batch'] ?? 1;
		$log_file_prefix = 'regenerated_media_thumnails.sql';

		$meta_query = [
			[
				'key'     => '_newspack_regenerated_thumnails',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'attachment',
				// 'p'              => 1028943,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'attachment',
				// 'p'              => 1028943,
				'fields'         => 'ids',
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $index => $post_id ) {
			$fullsizepath = get_attached_file( $post_id );

			if ( false !== $fullsizepath && file_exists( $fullsizepath ) ) {
				$metadata = wp_generate_attachment_metadata( $post_id, $fullsizepath );
				wp_update_attachment_metadata( $post_id, $metadata );

				$this->logger->log( $log_file_prefix, sprintf( '(%d/%d) Thumnails regenerated for media %d', $index, $posts_per_batch, $post_id ) );
			}

			update_post_meta( $post_id, '_newspack_regenerated_thumnails', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator attachments-get-hosts-from-post-content`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_get_hosts_post_content( $args, $assoc_args ) {
		$posts_per_batch = $assoc_args['posts-per-batch'] ?? 10000;
		$batch           = $assoc_args['batch'] ?? 1;
		$log_file_prefix = 'broken_media_urls_batch';

		$posts = get_posts(
			[
				'posts_per_page' => $posts_per_batch,
				'post_type'      => 'post',
				'post_status'    => array( 'publish' ),
				'paged'          => $batch,
				// 'p'              => 1088482,
			]
		);

		$urls = [];
		foreach ( $posts as $index => $post ) {
			$post_content = $post->post_content;
			// get domain from URLs in the format domain.tld/wp-content/uploads/...
			preg_match_all( '/"(?<url>[^"]+)\/uploads\//', $post_content, $image_sources_match );

			foreach ( $image_sources_match['url'] as $url ) {
				if ( ! in_array( $url, $urls ) ) {
					$urls[] = $url;
				}
			}
		}

		print_r( $urls );
	}

	/**
	 * Get all non posts images URLs.
	 *
	 * @return string[]
	 */
	private function get_all_non_posts_images_urls() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$non_posts = $wpdb->get_results( "SELECT post_content FROM {$wpdb->posts} pm WHERE post_type NOT IN ('post', 'attachment', 'revision');" );

		return array_unique(
			array_reduce(
				$non_posts,
				function( $carry, $post ) {
					return array_merge( $carry, $this->attachment_logic->get_images_sources_from_content( $post->post_content ) );
				},
				[]
			)
		);
	}

	/**
	 * Get all non posts images URLs.
	 *
	 * @return string[]
	 */
	private function get_all_widgets_images_urls() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$widgets         = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'widget_text';" );
		$widgets_content = unserialize( $widgets[0]->option_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize

		return array_unique(
			array_reduce(
				$widgets_content,
				function( $carry, $widget ) use ( $widgets_content ) {
					if ( ! is_array( $widget ) || ! array_key_exists( 'text', $widget ) ) {
						return $carry;
					}

					return array_merge( $carry, $this->attachment_logic->get_images_sources_from_content( $widget['text'] ) );
				},
				[]
			)
		);
	}

	/**
	 * Get logo URLs from all themes mods.
	 *
	 * @return string[]
	 */
	private function get_all_themes_mods_logos_urls() {
		global $wpdb;

		$urls = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_themes_mods = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'theme_mods_%';" );
		foreach ( $all_themes_mods as $theme_mod ) {
			$theme_mod_array = unserialize( $theme_mod->option_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			foreach ( [ 'newspack_footer_logo', 'custom_logo' ] as $logo_mod ) {
				if ( array_key_exists( $logo_mod, $theme_mod_array ) ) {
					$mod_logo = wp_get_attachment_url( $theme_mod_array[ $logo_mod ] );

					if ( ! in_array( $mod_logo, $urls, true ) ) {
						$urls[] = $mod_logo;
					}
				}
			}
		}

		return $urls;
	}

	/**
	 * Get media URLs from custom theme CSS.
	 *
	 * @return string[]
	 */
	private function get_all_custom_css_urls() {
		global $wpdb;

		$image_urls = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_custom_css = $wpdb->get_results( "SELECT post_content FROM {$wpdb->posts} WHERE post_type = 'custom_css';" );
		foreach ( $all_custom_css as $custom_css ) {
			// Get URLs from src attribute from a CSS content.
			preg_match_all( '/url\(\s*[\'|"]?(.*)[\'|"]?\s*\)/i', $custom_css->post_content, $image_sources_match );

			if ( array_key_exists( 1, $image_sources_match ) ) {
				$image_urls = $image_sources_match[1];
			}
		}

		return array_map(
			function( $url ) {
				return str_starts_with( $url, '/wp-content/uploads' )
				? get_site_url() . $url
				: $url;
			},
			$image_urls
		);
	}

	/**
	 * Get Co-Authors avatars URLS from co-authors plus plugin.
	 *
	 * @return string[]
	 */
	private function get_all_co_authors_avatars_urls() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$avatar_ids = $wpdb->get_results( "SELECT meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE post_type = 'guest-author' AND meta_key = '_thumbnail_id';" );

		return array_map(
			function( $avatar_id ) {
				return wp_get_attachment_url( $avatar_id->meta_value );
			},
			$avatar_ids
		);
	}

	/**
	 * Get avatars URLS from simple local avatars plugin.
	 *
	 * @return string[]
	 */
	private function get_all_simple_local_avatars_urls() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$avatars = $wpdb->get_results( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'simple_local_avatar';" );

		return array_map(
			function( $avatar ) {
				$avatar_details = unserialize( $avatar->meta_value );
				return wp_get_attachment_url( $avatar_details['media_id'] );
			},
			$avatars
		);
	}

	/**
	 * Get image URLS from YOAST default featured image.
	 *
	 * @return string[]
	 */
	private function get_yoast_default_featured_image_urls() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$default_featured_images = $wpdb->get_results( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpseo_social';" );

		return array_filter(
			array_map(
				function( $default_featured_image ) {
					$default_featured_image_details = unserialize( $default_featured_image->option_value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
					return empty( $default_featured_image_details['og_default_image_id'] ) ? '' : wp_get_attachment_url( $default_featured_image_details['og_default_image_id'] );
				},
				$default_featured_images
			)
		);
	}

	/**
	 * Clean images URLs from query parameters and return their relative paths.
	 *
	 * @param string $url
	 * @return string
	 */
	private function clean_images_url( $url ) {
		$url_without_query_param = current( explode( '?', $url ) );
		$uploads_dir             = wp_make_link_relative( wp_upload_dir()['baseurl'] );

		// Match the relative path of the attachment (e.g. /wp-content/uploads/2019/01/image.jpeg).
		preg_match( '~(?P<url>' . $uploads_dir . '/.*$)~', $url_without_query_param, $url_match );
		// Remove attachment size from filename.
		return array_key_exists( 'url', $url_match ) ? preg_replace( '/-\d+x\d+\.(jpe?g|png|gif)$/', '.$1', $url_match['url'] ) : $url;
	}

	/**
	 * Get attachments trash folder path
	 *
	 * @return string
	 */
	private function get_trash_folder() {
		return wp_upload_dir()['basedir'] . '/../' . self::ATTACHMENT_TRASH_FOLDER;
	}

	/**
	 * Get WP uploads dir
	 *
	 * @return string
	 */
	private function get_uploads_dir() {
		return wp_upload_dir()['basedir'];
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
