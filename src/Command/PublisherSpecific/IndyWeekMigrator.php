<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \DirectoryIterator;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use \WP_CLI;

/**
 * Custom migration scripts for LkldNow.
 */
class IndyWeekMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Instance of Attachments Login
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments = new Attachments();
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
            'newspack-content-migrator indyweek-import-prints',
            [ $this, 'cmd_indyweek_import_prints' ],
            [
				'shortdesc' => 'Import the prints of Indy Week from a JSON file to Generic Listings.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'json-file-path',
						'description' => 'JSON file path containing the prints.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
            ]
		);

		WP_CLI::add_command(
            'newspack-content-migrator indyweek-fix-prints-featured-image',
            [ $this, 'cmd_indyweek_fix_prints_featured_image' ],
            [
				'shortdesc' => 'Fix print editions featured images.',
				'synopsis'  => [],
            ]
		);

		WP_CLI::add_command(
            'newspack-content-migrator indyweek-fix-puzzles-links',
            [ $this, 'cmd_indyweek_fix_puzzles_links' ],
            [
				'shortdesc' => 'Fix Puzzles media links.',
				'synopsis'  => [],
            ]
		);

		WP_CLI::add_command(
            'newspack-content-migrator indyweek-fix-inline-images',
            [ $this, 'cmd_indyweek_fix_inline_images' ],
            [
				'shortdesc' => 'Fix inline images inside post content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'content-folder-path',
						'description' => 'Path of all content folder. The folder is an export from the publisher containing content in JSON format, media files...',
						'optional'    => false,
						'repeating'   => false,
					],
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
				],
            ]
		);
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-import-prints`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_import_prints( $args, $assoc_args ) {
		$json_file = $assoc_args['json-file-path'];

		if ( ! file_exists( $json_file ) ) {
			WP_CLI::error( 'The provided file does not exist.' );
		}

		$file_content = file_get_contents( $json_file );

		$json_data = json_decode( $file_content );

		if ( ! $json_data ) {
			WP_CLI::error( 'The JSON file is invalid.' );
		}

		$prints = $json_data->items;

		$category_id = get_terms(
            array(
				'fields'     => 'ids',
				'taxonomy'   => 'category',
				'name'       => 'Print Edition',
				'hide_empty' => false,
            )
		)[0];

		$base_url = 'https://issuu.com/indyweeknc/docs/';

		$print_content = <<<HTML
<!-- wp:paragraph -->
<p><a href="%s" target="_blank" rel="noreferrer noopener">Click here to access</a></p>
<!-- /wp:paragraph -->
HTML;

		foreach ( $prints as $print ) {
			WP_CLI::log( 'Adding print ' . $print->title );
			$post_args = array(
				'post_title'    => $print->title,
				'post_date'     => $print->publishDate,
				'post_content'  => sprintf( $print_content, $base_url . $print->uri ),
				'post_type'     => 'newspack_lst_generic',
				'post_category' => array( $category_id ),
				'post_status'   => 'publish',
			);

			$post_id = wp_insert_post( $post_args, true );

			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( 'Could not add print ' . $print->title );
				continue;
			}

			WP_CLI::log( 'Downloading thumbnail...' );
			$thumbnail_id = $this->attachments->import_external_file( $print->coverUrl );

			set_post_thumbnail( $post_id, $thumbnail_id );
			WP_CLI::log( 'Print ' . $print->title . ' has been added.' );
		}

		WP_CLI::success( 'Done!' );
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-fix-prints-featured-image`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_fix_prints_featured_image( $args, $assoc_args ) {
		$query = new \WP_Query(
            [
				'post_type'      => 'newspack_lst_generic',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'cat'            => 8,
				'orderby'        => 'ID',
				'order'          => 'ASC',
            ]
		);

		$posts = $query->get_posts();
		array_shift( $posts );

		foreach ( $posts as $index => $post ) {
			$fixed_index = $index + 3;
			if ( isset( $posts[ $fixed_index ] ) ) {
				set_post_thumbnail( $post->ID, get_post_thumbnail_id( $posts[ $fixed_index ]->ID ) );
				WP_CLI::success( sprintf( 'Post fixed: %d', $post->ID ) );
			} else {
				WP_CLI::warning( sprintf( 'Post to be fixed manually: %d', $post->ID ) );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-fix-puzzles-links`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_fix_puzzles_links( $args, $assoc_args ) {
		$page = get_post( 162921 );
		preg_match_all( '|(?P<url>https://indyweek\.com/downloads/\d+/download/(?P<attachment_name>.*?))"|', $page->post_content, $download_url_matches );

		$updated_content = $page->post_content;

		foreach ( $download_url_matches['url'] as $index => $download_url ) {
			if ( isset( $download_url_matches['attachment_name'][ $index ] ) ) {
				$original_name   = $download_url_matches['attachment_name'][ $index ];
				$attachment_name = urldecode( $original_name );
				$attachment_name = explode( '?', $attachment_name )[0];
				$attachment_name = str_replace( [ '.jpg', '.pdf' ], '', $attachment_name );
				$attachment      = $this->get_attachment_id_by_filename( $attachment_name );

				if ( ! $attachment ) {
					$attachment_name = str_replace( ' ', '-', urldecode( $attachment_name ) );
					$attachment      = $this->get_attachment_id_by_filename( $attachment_name );

					if ( ! $attachment ) {
						$attachment_name = str_replace( [ '[1].', '-(1).' ], '.', $attachment_name );
						$attachment      = $this->get_attachment_id_by_filename( $attachment_name );

						if ( ! $attachment ) {
							print_r( "No attachment for $attachment_name: $original_name \n" );
							continue;
						}
					}
				}

				$updated_content = str_replace( $download_url, wp_get_attachment_url( $attachment ), $updated_content );
				wp_update_post(
                    array(
						'ID'           => $page->ID,
						'post_content' => $updated_content,
                    )
				);

				wp_cache_flush();
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator indyweek-fix-inline-images`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_indyweek_fix_inline_images( $args, $assoc_args ) {
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$export_folder_path  = $assoc_args['content-folder-path'];
		$content_folder_path = $export_folder_path . '/content';
		$files_folder_path   = $export_folder_path . '/files';

		$post_slots = []; // ['title' => [slot_media_file_path_1, slot_media_file_path_2, ...]].

		$dir = new DirectoryIterator( $content_folder_path );
		foreach ( $dir as $file ) {
			if ( 'json' === $file->getExtension() ) {
				$raw_data = file_get_contents( $file->getPathname() );
				$data     = json_decode( $raw_data, true );

				if ( ! $data ) {
					var_dump( $file->getPathname() );
				}
				if ( ! array_key_exists( 'title', $data ) || ! array_key_exists( 'content', $data ) ) {
					continue;
				}

				if ( 'Ralph Northam Should Resign. Maybe These 11 North Carolina Politicians Should, Too.' !== $data['title'] ) {
					continue;
				}

				preg_match_all( '/<slot id="(?<slot_ids>[^"]+)">(.*?)/m', $data['content'], $slot_matches );

				if ( 1 < count( $slot_matches['slot_ids'] ) ) {
					$post_slots[ $data['title'] ] = $this->get_post_inline_images_files( $file, $export_folder_path, $slot_matches['slot_ids'] );
				}
			}
		}

		$query = new \WP_Query(
            [
				// 'p'              => 164729,
				'post_type'      => 'post',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
            ]
		);

		$posts                          = $query->get_posts();
		$posts_with_more_than_one_image = 0;

		foreach ( $posts as $post ) {
			$content_blocks                    = parse_blocks( $post->post_content );
			$image_block_count                 = 0;
			$non_duplicated_images_block_count = 0;
			$image_ids                         = [];
			foreach ( $content_blocks as $content_block ) {
				if ( 'core/image' === $content_block['blockName'] ) {
					if ( is_array( $content_block['attrs'] ) && ! array_key_exists( 'id', $content_block['attrs'] ) ) {
						continue;
					}

					if ( $content_block['attrs'] && ! in_array( $content_block['attrs']['id'], $image_ids ) ) {
						$image_ids[] = $content_block['attrs']['id'];
						$non_duplicated_images_block_count++;
					}
					$image_block_count++;
				}
			}

			if ( $image_block_count > 1 && $image_block_count > $non_duplicated_images_block_count ) {
				$posts_with_more_than_one_image++;
				if ( array_key_exists( $post->post_title, $post_slots ) ) {
					if ( count( $post_slots[ $post->post_title ] ) !== $image_block_count ) {
						WP_CLI::warning( sprintf( "Post '%s' (%d) have different images counts.", $post->post_title, $post->ID ) );
						continue;
					}

					$image_block_index = 0;
					foreach ( $content_blocks as $block_index => $content_block ) {
						if ( 'core/image' === $content_block['blockName'] ) {
							if ( $content_block['attrs'] && ! array_key_exists( 'id', $content_block['attrs'] ) ) {
								continue;
							}

							// existing media details.
							$media_meta     = wp_get_attachment_metadata( $content_block['attrs']['id'] );
							$media_filename = basename( $media_meta['file'] );

							// original media details.
							$slot_id              = $post_slots[ $post->post_title ][ $image_block_index ];
							$media_data_file_path = $files_folder_path . "/$slot_id.json";
							if ( ! is_file( $media_data_file_path ) ) {
								WP_CLI::warning( sprintf( 'Missing media data for the media %s from the post %d', $slot_id, $post->ID ) );
								continue;
							}

							$raw_media_data      = file_get_contents( $media_data_file_path );
							$original_media_data = json_decode( $raw_media_data, true );

							if ( $media_filename !== $original_media_data['filename'] ) {
								$existing_media_id = $this->get_attachment_id_by_filename( pathinfo( $original_media_data['filename'], PATHINFO_FILENAME ) );
								if ( ! $existing_media_id ) {
									$attachment_name   = str_replace( ' ', '-', pathinfo( $original_media_data['filename'], PATHINFO_FILENAME ) );
									$existing_media_id = $this->get_attachment_id_by_filename( $attachment_name );
									if ( ! $existing_media_id ) {
										WP_CLI::warning( 'missing media for the post ' . $post->ID . ' (' . pathinfo( $original_media_data['filename'], PATHINFO_FILENAME ) . "): $media_data_file_path\n" );
									}
								}

								WP_CLI::success( 'media ' . $existing_media_id . ' fixed for the post: ' . $post->ID . "\n" );
								// fixing image block.
								$content_blocks[ $block_index ] = $this->get_image( get_post( $existing_media_id ) );
							}
							$image_block_index++;
						}
					}

					$fixed_content = serialize_blocks( $content_blocks );
					if ( $fixed_content !== $post->post_content ) {
						wp_update_post(
                            [
								'ID'           => $post->ID,
								'post_content' => $fixed_content,
							]
                        );

						WP_CLI::success( 'Post successfully updated: ' . $post->ID . "\n" );
					}
				}
			}
		}
		print_r( "$posts_with_more_than_one_image posts with more than one image in their content.\n" );
	}

	/**
     * Generate a List Block item.
     *
     * @param \WP_Post $attachment_post Image Post.
     * @param string   $size Image size, full by default.
     *
     * @return array to be used in the serialize_blocks function to get the raw content of a Gutenberg Block.
     */
    private function get_image( $attachment_post, $size = 'full' ) {
        $caption_tag = ! empty( $attachment_post->post_excerpt ) ? '<figcaption class="wp-element-caption">' . $attachment_post->post_excerpt . '</figcaption>' : '';
        $image_alt   = get_post_meta( $attachment_post->ID, '_wp_attachment_image_alt', true );

        $attrs = [
            'sizeSlug' => $size,
        ];

        $content = '<figure class="wp-block-image size-' . $size . '"><img src="' . wp_get_attachment_url( $attachment_post->ID ) . '" alt="' . $image_alt . '"/>' . $caption_tag . '</figure>';

        return [
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $content,
			'innerContent' => [ $content ],
		];
    }

	/**
	 * Get media file path from slot ID.
	 *
	 * @param DirectoryIterator $file post JSON file.
	 * @param string            $export_folder_path Directory path that contain all content.
	 * @param string[]          $slot_ids Slot IDs.
	 * @return string|false     wImage file path, false otherwise.
	 */
	private function get_post_inline_images_files( $file, $export_folder_path, $slot_ids ) {
		$post_id         = basename( $file->getFilename(), '.json' );
		$media_file_path = "$export_folder_path/content/$post_id/media.json";

		if ( ! is_file( $media_file_path ) ) {
			WP_CLI::warning( sprintf( 'Media folder not found for the post: %s', $post_id ) );
			return false;
		}

		$raw_data = file_get_contents( $media_file_path );
		$data     = json_decode( $raw_data, true );

		return array_values(
			array_filter(
				array_map(
					function( $slot_id ) use ( $data, $file ) {
						$possible_slot_data_index = array_search( $slot_id, array_column( $data, 'slot_uuid' ) );
						return false !== $possible_slot_data_index && array_key_exists( 'image_uuid', $data[ $possible_slot_data_index ] )
						 ? $data[ $possible_slot_data_index ]['image_uuid'] : null;
					},
					$slot_ids
				)
			)
        );
	}

	/**
	 * Get attachment ID by it's filename
	 *
	 * @param string $filename attachment filename.
	 * @return int|false
	 */
	private function get_attachment_id_by_filename( $filename ) {
		global $wpdb;
		$sql         = $wpdb->prepare( "SELECT * FROM  $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value like %s", "%$filename%" );
		$attachments = $wpdb->get_results( $sql );
		return $attachments[0]->post_id ?? false;
	}
}
