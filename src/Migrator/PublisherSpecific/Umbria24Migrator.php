<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\Migrator\General\PostsMigrator as PostsMigratorLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Washington Monthly.
 */
class Umbria24Migrator implements InterfaceMigrator {

	const VIDEO_YOUTUBE_LOG   = 'video_youtube.log';
	const VIDEO_IFRAME_LOG    = 'video_iframe.log';
	const VIDEO_VIMEO_LOG     = 'video_vimeo.log';
	const MEDIALAB_IFRAME_LOG = 'medialab_iframe.log';
	const FOTOGALLERY_LOG     = 'fotogallery.log';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsMigrator logic.
	 */
	private $posts_migrator_logic = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_migrator_logic = PostsMigratorLogic::get_instance();
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
			'newspack-content-migrator umbria24-convert-acf-video',
			array( $this, 'cmd_convert_acf_video' ),
			array(
				'shortdesc' => 'Convert Video ACF posts to normal posts under `video` category',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-convert-acf-medialab',
			array( $this, 'cmd_convert_acf_medialab' ),
			array(
				'shortdesc' => 'Convert Video ACF posts to normal posts under `video` category',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-convert-acf-fotogallery',
			array( $this, 'cmd_convert_acf_fotogallery' ),
			array(
				'shortdesc' => 'Convert Video ACF posts to normal posts under `video` category',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-fix-empty-jetpack-slideshows',
			array( $this, 'cmd_fix_empty_jetpack_slideshows' ),
			array(
				'shortdesc' => 'Fix migrated emtpy Jetpack slideshows',
				'synopsis'  => array(
					array(
						'type'      => 'assoc',
						'name'      => 'id_matcher_filepath',
						'optional'  => false,
						'repeating' => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-revert-fix-empty-jetpack-slideshows',
			array( $this, 'cmd_revert_fix_empty_jetpack_slideshows' ),
			array(
				'shortdesc' => 'Fix migrated emtpy Jetpack slideshows',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator umbria24-convert-acf-video`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_convert_acf_video( $args, $assoc_args ) {
		$video_posts  = $this->get_all_posts_by_post_type( 'video' );
		$video_cat    = get_category_by_slug( 'video' );
		$video_cat_id = $video_cat ? $video_cat->term_id : wp_insert_category(
			array(
				'cat_name'             => 'Video',
				'category_description' => 'Imported Video ACF',
			),
			true
		);

		foreach ( $video_posts as $video_post ) {
			// If post body contains already the video, skip it.
			if (
				strpos( $video_post->post_content, 'youtube.com/' ) !== false
				|| strpos( $video_post->post_content, 'facebook.com/plugins/video.php' ) !== false
			) {
				$this->log( self::VIDEO_YOUTUBE_LOG, sprintf( 'Skipping post #%d as its body contains already the embed video.', $video_post->ID ), true );
				continue;
			}

			// 3 meta_value for `video_type` meta_key: youtube, iframe, vimeo.
			$meta = get_post_custom( $video_post->ID );

			switch ( $meta['video_type'][0] ) {
				case 'youtube':
					// Get full URL.
					$video_src = strpos( $meta['video'][0], 'youtube.com/' ) !== false ? $meta['video'][0] : $meta['link_old_youtube'][0];
					$video_id  = $meta['id_old_youtube'][0];

					if ( empty( $video_src ) && empty( $video_id ) ) {
						\WP_CLI::warning( sprintf( 'Skipping post #%d as video source can\'t be found.', $video_post->ID ) );
						$this->log( self::VIDEO_YOUTUBE_LOG, sprintf( 'Skipping post #%d as video source can\'t be found.', $video_post->ID ) );
						continue 2;
					}

					$video_url = '';
					// Get video URL from raw URL.
					if ( ! empty( $video_src ) ) {
						$video_url = $video_src;
					} elseif ( ! empty( $video_id ) ) {
						// Or from video ID.
						$video_url = "https://www.youtube.com/watch?v=$video_id";
					}

					// Update the post into the database.
					wp_update_post(
						array(
							'ID'            => $video_post->ID,
							// Add YouTube block in top of the post content.
							'post_content'  => '<!-- wp:embed {"url":"' . $video_url . '","providerNameSlug":"youtube","responsive":true} --><figure class="wp-block-embed is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . $video_url . '</div></figure><!-- /wp:embed -->' . $video_post->post_content,
							'post_type'     => 'post',
							'post_category' => array_merge(
								array( $video_cat_id ),
								wp_get_post_categories( $video_post->ID, array( 'fields' => 'ids' ) )
							),
						)
					);

					$this->log( self::VIDEO_YOUTUBE_LOG, sprintf( 'YouTube video post #%d updated.', $video_post->ID ), true );
					break;
				case 'iframe':
					$video_iframe = $meta['iframe'][0];
					if ( empty( $video_iframe ) ) {
						\WP_CLI::warning( sprintf( 'Skipping post #%d as video source can\'t be found.', $video_post->ID ) );
						$this->log( self::VIDEO_IFRAME_LOG, sprintf( 'Skipping post #%d as video source can\'t be found.', $video_post->ID ) );
						continue 2;
					}

					// Update the post into the database.
					wp_update_post(
						array(
							'ID'            => $video_post->ID,
							// Add Iframe code in top of the post content.
							'post_content'  => $this->posts_migrator_logic->embed_iframe_block_from_html( $video_iframe, $video_post->ID ) . $video_post->post_content,
							'post_type'     => 'post',
							'post_category' => array_merge(
								array( $video_cat_id ),
								wp_get_post_categories( $video_post->ID, array( 'fields' => 'ids' ) )
							),
						)
					);

					$this->log( self::VIDEO_IFRAME_LOG, sprintf( 'Video iframe post #%d updated.', $video_post->ID ), true );
					break;
				case 'vimeo':
					// Get full URL.
					$video_src = $meta['video'][0];
					if ( strpos( $meta['video'][0], 'vimeo.com/' ) === false ) {
						\WP_CLI::warning( sprintf( 'Skipping post #%d as video source can\'t be found.', $video_post->ID ) );
						$this->log( self::VIDEO_VIMEO_LOG, sprintf( 'Skipping post #%d as video source can\'t be found.', $video_post->ID ) );
						continue 2;
					}

					// Update the post into the database.
					wp_update_post(
						array(
							'ID'            => $video_post->ID,
							// Add Vimeo block in top of the post content.
							'post_content'  => '<!-- wp:embed {"url":"' . $video_src . '","type":"video","providerNameSlug":"vimeo","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} --><figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . $video_src . '</div></figure><!-- /wp:embed -->' . $video_post->post_content,
							'post_type'     => 'post',
							'post_category' => array_merge(
								array( $video_cat_id ),
								wp_get_post_categories( $video_post->ID, array( 'fields' => 'ids' ) )
							),
						)
					);

					$this->log( self::VIDEO_VIMEO_LOG, sprintf( 'Vimeo video post #%d updated.', $video_post->ID ), true );
					break;
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator umbria24-convert-acf-medialab`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_convert_acf_medialab( $args, $assoc_args ) {
		$medialab_posts  = $this->get_all_posts_by_post_type( 'medialab' );
		$medialab_cat    = get_category_by_slug( 'medialab' );
		$medialab_cat_id = $medialab_cat ? $medialab_cat->term_id : wp_insert_category(
			array(
				'cat_name'             => 'Medialab',
				'category_description' => 'Imported Medialab ACF',
			),
			true
		);

		foreach ( $medialab_posts as $medialab_post ) {
			$meta                   = get_post_custom( $medialab_post->ID );
			$iframe_meta            = $meta['iframe'][0];
			$sottotitolo_meta       = $meta['sottotitolo'][0];
			$link_old_timeline_meta = $meta['link_old_timeline'][0];
			$link_old_webdoc_meta   = $meta['link_old_webdoc'][0];

			// Skip iList elements.
			if ( strpos( $sottotitolo_meta, 'qcld-ilist' ) !== false ) {
				$this->log( self::MEDIALAB_IFRAME_LOG, sprintf( 'Skipping post #%d as it contains a short code for iList.', $medialab_post->ID ), true );
				continue;
			}

			if (
				( empty( $iframe_meta ) || strpos( $iframe_meta, 'field_' ) !== false )
				&& ( empty( $sottotitolo_meta ) || strpos( $sottotitolo_meta, '<iframe' ) === false )
				&& empty( $link_old_timeline_meta )
				&& empty( $link_old_webdoc_meta )
			) {
				$this->log( self::MEDIALAB_IFRAME_LOG, sprintf( 'Skipping post #%d as we can\'t get its content', $medialab_post->ID ), true );
				continue;
			}

			$iframe_code = '';
			if ( ! empty( $iframe_meta ) ) {
				$iframe_code = $this->posts_migrator_logic->embed_iframe_block_from_html( $iframe_meta, $medialab_post->ID );
			} elseif ( ! empty( $sottotitolo_meta ) && strpos( $sottotitolo_meta, '<iframe' ) !== false ) {
				$iframe_code = $this->posts_migrator_logic->embed_iframe_block_from_html( $sottotitolo_meta, $medialab_post->ID );
			} elseif ( ! empty( $link_old_timeline_meta ) ) {
				$iframe_code = $this->posts_migrator_logic->embed_iframe_block_from_src( $link_old_timeline_meta );
			} elseif ( ! empty( $link_old_webdoc_meta ) ) {
				$iframe_code = $this->posts_migrator_logic->embed_iframe_block_from_src( $link_old_webdoc_meta );
			}

			// Update the post into the database.
			wp_update_post(
				array(
					'ID'            => $medialab_post->ID,
					// Add Metalab iframe code in top of the post content.
					'post_content'  => $iframe_code . $medialab_post->post_content,
					'post_type'     => 'post',
					'post_category' => array_merge(
						array( $medialab_cat_id ),
						wp_get_post_categories( $medialab_post->ID, array( 'fields' => 'ids' ) )
					),
				)
			);

			$this->log( self::MEDIALAB_IFRAME_LOG, sprintf( 'Medialab iframe post #%d updated.', $medialab_post->ID ), true );
		}
	}

	/**
	 * Callable for `newspack-content-migrator umbria24-convert-acf-fotogallery`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_convert_acf_fotogallery( $args, $assoc_args ) {
		$fotogallery_posts  = $this->get_all_posts_by_post_type( 'fotogallery' );
		$fotogallery_cat    = get_category_by_slug( 'fotogallery' );
		$fotogallery_cat_id = $fotogallery_cat ? $fotogallery_cat->term_id : wp_insert_category(
			array(
				'cat_name'             => 'Fotogallery',
				'category_description' => 'Imported Fotogallery ACF',
			),
			true
		);

		foreach ( $fotogallery_posts as $fotogallery_post ) {
			$meta         = get_post_custom( $fotogallery_post->ID );
			$gallery_meta = $meta['gallery'][0];

			$gallery_code = '';

			if ( ! empty( $gallery_meta ) ) {
				$gallery_posts = unserialize( $gallery_meta );
				$gallery_code  = $this->posts_migrator_logic->generate_jetpack_slideshow_block_from_media_posts( $gallery_posts );
			}

			if ( ! empty( $gallery_code ) ) {
				// Update the post into the database.
				wp_update_post(
					array(
						'ID'            => $fotogallery_post->ID,
						// Add Metalab iframe code in top of the post content.
						'post_content'  => $gallery_code . $fotogallery_post->post_content,
						'post_type'     => 'post',
						'post_category' => array_merge(
							array( $fotogallery_cat_id ),
							wp_get_post_categories( $fotogallery_post->ID, array( 'fields' => 'ids' ) )
						),
					)
				);

				$this->log( self::FOTOGALLERY_LOG, sprintf( 'Fotogallery post #%d updated.', $fotogallery_post->ID ), true );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator umbria24-fix-empty-jetpack-slideshows`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_fix_empty_jetpack_slideshows( $args, $assoc_args ) {
		global $wpdb;

		$id_matcher_filepath = json_decode( file_get_contents( $assoc_args['id_matcher_filepath'] ), true );

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM wp_posts WHERE post_status = %s AND post_content LIKE %s',
				// 'SELECT * FROM wp_posts WHERE post_status = %s AND post_content LIKE %s AND ID = 923899',
				'publish',
				'%jetpack-slideshow_image wp-image-" data-id="" src=""%'
			)
		);

		var_dump( count( $posts ) );
		die();
		foreach ( $posts as $post ) {
			$content_blocks = parse_blocks( $post->post_content );

			foreach ( $content_blocks as $index => $block ) {
				if ( 'jetpack/slideshow' === $block['blockName'] && str_contains( $block['innerHTML'], 'src=""' ) ) {
					$media_ids = array_map(
						function( $old_id ) use ( $id_matcher_filepath, $post ) {
							$id_matcher_found = null;
							foreach ( $id_matcher_filepath as $id_matcher ) {
								if ( $old_id === $id_matcher['id_old'] ) {
									$id_matcher_found = $id_matcher;
									break;
								}
							}

							if ( ! $id_matcher_found ) {
								return $old_id;
							}

							return $id_matcher_found['id_new'];
						},
						$block['attrs']['ids']
					);

					$gallery_code             = $this->posts_migrator_logic->generate_jetpack_slideshow_block_from_media_posts( $media_ids );
					$content_blocks[ $index ] = current( parse_blocks( $gallery_code ) );
					$this->log( self::FOTOGALLERY_LOG, sprintf( 'Gallery fixed for the post %d fixed', $post->ID ), true );
				}
			}

			$updated_content = serialize_blocks( $content_blocks );

			if ( $updated_content !== $post->post_content ) {
				update_post_meta( $post->ID, 'newspack_old_post_content', $post->post_content );

				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_content' => $updated_content,
					),
					true
				);
			}

			$this->log( self::FOTOGALLERY_LOG, sprintf( 'Post %d fixed', $post->ID ), true );
		}
		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator umbria24-revert-fix-empty-jetpack-slideshows`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_revert_fix_empty_jetpack_slideshows( $args, $assoc_args ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			'select p.ID, p.post_content, po.post_content as old_content from wp_posts p inner join wp_posts_old po on po.ID = p.ID where p.ID in (473618,491545,504124,530916,655429,668297,849209,859690,901953,919222,919253,919260,919261,919276,919290,919311,922726,922757,922790,922791,922800,922825,922835,922840,922853,922862,922899,922925,922928,922943,922980,922983,923005,923007,923025,923058,923066,923087,923117,923131,923143,923244,923269,923273,923314,923320,923352,923411,923415,923416,923418,923435,923438,923448,923461,923483,923492,923493,923497,923505,923511,923522,923541,923584,923597,923633,923662,923692,923724,923731,923735,923746,923749,923752,923756,923764,923765,923794,923797,923819,923864,923877,923888,923899,923904);'
		);

		foreach ( $posts as $post ) {
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $post->old_content,
				),
				true
			);

			$this->log( self::FOTOGALLERY_LOG, sprintf( 'Post %d fixed', $post->ID ), true );
		}
		wp_cache_flush();
	}

	/**
	 * Fetches Posts by post type.
	 *
	 * @param string $post_type Post type to filter with the selected posts.
	 * @return \WP_Post[]
	 */
	public function get_all_posts_by_post_type( $post_type ) {
		return get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
			)
		);
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message, $to_cli = false ) {
		if ( $to_cli ) {
			\WP_CLI::line( $message );
		}

		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
