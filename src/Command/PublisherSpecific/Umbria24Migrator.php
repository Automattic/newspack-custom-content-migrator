<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator;
use Symfony\Component\DomCrawler\Crawler as Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Washington Monthly.
 */
class Umbria24Migrator implements InterfaceCommand {
	const VIDEO_YOUTUBE_LOG   = 'video_youtube.log';
	const VIDEO_IFRAME_LOG    = 'video_iframe.log';
	const VIDEO_VIMEO_LOG     = 'video_vimeo.log';
	const MEDIALAB_IFRAME_LOG = 'medialab_iframe.log';
	const FOTOGALLERY_LOG     = 'fotogallery.log';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsLogic.
	 */
	private $posts_migrator_logic;

	/**
	 * @var WpBlockManipulator.
	 */
	private $block_manipulator;

	/**
	 * @var Crawler.
	 */
	private $crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_migrator_logic = new PostsLogic();
		$this->block_manipulator = new WpBlockManipulator();
		$this->crawler = new Crawler();
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
			'newspack-content-migrator umbria24-convert-acf-video',
			[ $this, 'cmd_convert_acf_video' ],
			[
				'shortdesc' => 'Convert Video ACF posts to normal posts under `video` category',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-convert-acf-medialab',
			[ $this, 'cmd_convert_acf_medialab' ],
			[
				'shortdesc' => 'Convert Video ACF posts to normal posts under `video` category',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-convert-acf-fotogallery',
			[ $this, 'cmd_convert_acf_fotogallery' ],
			[
				'shortdesc' => 'Convert Video ACF posts to normal posts under `video` category',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-replace-youtube-iframes-with-youtube-blocks',
			[ $this, 'cmd_replace_yt_iframes_with_yt_blocks' ],
		);

		WP_CLI::add_command(
			'newspack-content-migrator umbria24-update-feat-img-ids-from-logs',
			[ $this, 'cmd_update_feat_imgs_ids_from_logs' ],
			[
				'shortdesc' => 'Updates featured imgages ids according to old2new attachment ids from content refresh logs.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'attachment-ids-changes-php-file',
						'description' => "File which returns an array of old attachment ids as keys and new attachment ids as values.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types-csv',
						'description' => "CSV of post types to process. Default is `post`.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-from',
						'description' => 'Optional. Post ID range minimum.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id-to',
						'description' => 'Optional. Post ID range maximum.',
						'optional'    => false,
						'repeating'   => false,
					],
				]
			]
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
			[
				'cat_name'             => 'Video',
				'category_description' => 'Imported Video ACF',
			],
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
						[
							'ID'            => $video_post->ID,
							// Add YouTube block in top of the post content.
							'post_content'  => '<!-- wp:embed {"url":"' . $video_url . '","providerNameSlug":"youtube","responsive":true} --><figure class="wp-block-embed is-provider-youtube wp-block-embed-youtube"><div class="wp-block-embed__wrapper">' . $video_url . '</div></figure><!-- /wp:embed -->' . $video_post->post_content,
							'post_type'     => 'post',
							'post_category' => array_merge(
								[ $video_cat_id ],
								wp_get_post_categories( $video_post->ID, [ 'fields' => 'ids' ] )
							),
						]
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
						[
							'ID'            => $video_post->ID,
							// Add Iframe code in top of the post content.
							'post_content'  => $this->posts_migrator_logic->embed_iframe_block_from_html( $video_iframe, $video_post->ID ) . $video_post->post_content,
							'post_type'     => 'post',
							'post_category' => array_merge(
								[ $video_cat_id ],
								wp_get_post_categories( $video_post->ID, [ 'fields' => 'ids' ] )
							),
						]
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
						[
							'ID'            => $video_post->ID,
							// Add Vimeo block in top of the post content.
							'post_content'  => '<!-- wp:embed {"url":"' . $video_src . '","type":"video","providerNameSlug":"vimeo","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} --><figure class="wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . $video_src . '</div></figure><!-- /wp:embed -->' . $video_post->post_content,
							'post_type'     => 'post',
							'post_category' => array_merge(
								[ $video_cat_id ],
								wp_get_post_categories( $video_post->ID, [ 'fields' => 'ids' ] )
							),
						]
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
			[
				'cat_name'             => 'Medialab',
				'category_description' => 'Imported Medialab ACF',
			],
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
				[
					'ID'            => $medialab_post->ID,
					// Add Metalab iframe code in top of the post content.
					'post_content'  => $iframe_code . $medialab_post->post_content,
					'post_type'     => 'post',
					'post_category' => array_merge(
						[ $medialab_cat_id ],
						wp_get_post_categories( $medialab_post->ID, [ 'fields' => 'ids' ] )
					),
				]
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
			[
				'cat_name'             => 'Fotogallery',
				'category_description' => 'Imported Fotogallery ACF',
			],
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
					[
						'ID'            => $fotogallery_post->ID,
						// Add Metalab iframe code in top of the post content.
						'post_content'  => $gallery_code . $fotogallery_post->post_content,
						'post_type'     => 'post',
						'post_category' => array_merge(
							[ $fotogallery_cat_id ],
							wp_get_post_categories( $fotogallery_post->ID, [ 'fields' => 'ids' ] )
						),
					]
				);

				$this->log( self::FOTOGALLERY_LOG, sprintf( 'Fotogallery post #%d updated.', $fotogallery_post->ID ), true );
			}
		}
	}

	/**
	 * Fetches Posts by post type.
	 *
	 * @param string $post_type Post type to filter with the selected posts.
	 * @return \WP_Post[]
	 */
	public function get_all_posts_by_post_type( $post_type ) {
		return get_posts(
			[
				'posts_per_page' => -1,
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator umbria24-replace-youtube-iframes-with-youtube-blocks`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_replace_yt_iframes_with_yt_blocks( $args, $assoc_args ) {
		global $wpdb;

		// Keeping it simple.
		$ytblock_sprintf = <<<BLOCK
<!-- wp:embed {"url":"%s","type":"rich","providerNameSlug":"embed-handler","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-rich is-provider-embed-handler wp-block-embed-embed-handler wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
%s
</div></figure>
<!-- /wp:embed -->
BLOCK;

		$ids = $this->posts_migrator_logic->get_all_posts_ids( ['post','video','fotogallery','medialab','page'], ['publish','draft'] );
		foreach ( $ids as $key_id => $id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_id + 1, count($ids), $id ) );

			$post_content = $wpdb->get_var( $wpdb->prepare( "select post_content from {$wpdb->posts} where ID=%d", $id ));
			$post_content_updated = $post_content;

			$matches = $this->block_manipulator->match_wp_block_selfclosing( 'wp:newspack-blocks/iframe', $post_content );
			if ( is_null( $matches ) || empty( $matches[0] ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$iframe_block = $match[0] ?? null;
				if ( is_null( $iframe_block ) || empty( $iframe_block ) ) {
					$this->log( 'iframe2ytblock_err_matchesEmpty.log', $id );
					WP_CLI::warning( 'matches empty' );
					continue;
				}

				/**
				 * E.g. block:
				 * <!-- wp:newspack-blocks/iframe {"src":"https://umbria24-newspack.newspackstaging.com/wp-content/uploads/2023/02/newspack_iframes/iframe-965141-gJZOmoxY/","archiveFolder":"/2023/02/newspack_iframes/iframe-965141-gJZOmoxY"} /-->
				 */
				$src = $this->block_manipulator->get_attribute( $iframe_block, 'src' );
				/**
				 * Transform this
				 *      https://umbria24-newspack.newspackstaging.com/wp-content/uploads/2023/02/newspack_iframes/iframe-965141-gJZOmoxY/
				 * to this
				 *      {WP_CONTENT_DIR}/uploads/2023/02/newspack_iframes/iframe-965141-gJZOmoxY/index.html
				 */
				$pos_uploads = strpos( $src, '/uploads/' );
				if ( false === $pos_uploads ) {
					continue;
				}
				// The src might have a full file path, or might be missing index.html at the end.
				$src_file = WP_CONTENT_DIR . substr( $src, $pos_uploads );
				if ( is_dir( $src_file ) ) {
					$src_file .= 'index.html';
				}
				if ( ! file_exists( $src_file ) ) {
					$msg = sprintf( "%d %s", $id, $src_file );
					$this->log( 'iframe2ytblock_err_iframeFileNotFound.log', $msg );
					WP_CLI::log( $msg );
					continue;
				}

				$iframe_code = file_get_contents( $src_file );
				$this->crawler->clear();
				$this->crawler->add( $iframe_code );
				$iframe_data = $this->crawler->filterXpath( '//iframe' )->extract( [ 'src' ] );
				if ( empty( $iframe_data ) ) {
					// Does not contain iframe.
					continue;
				}
				$iframe_src  = $iframe_data[0] ?? null;
				if ( is_null( $iframe_src ) ) {
					$msg = sprintf( "%d %s iframe_src is NULL", $id, $src_file );
					$this->log( 'iframe2ytblock_err_iframeSrcIsNull.log', $msg );
					WP_CLI::log( $msg );
					continue;
				}

				$iframe_src_parsed = parse_url( $iframe_src );
				if ( ! str_contains($iframe_src_parsed['host'], 'youtube.com') ) {
					$msg = sprintf( "%d notYoutubeCom? %s", $id, $iframe_src );
					$this->log( 'iframe2ytblock_err_srcNotYoutube.log', $msg );
					WP_CLI::log( $msg );
					continue;
				}

				$yt_block = sprintf( $ytblock_sprintf, $iframe_src, $iframe_src );
				$post_content_updated = str_replace( $iframe_block, $yt_block, $post_content_updated );
				$updated = $wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $id ]
				);
				if ( 1 !== $updated ) {
					WP_CLI::warning( 'Not updated!' );
					$this->log( 'iframe2ytblock_err_notUpdatedID.log', $id );
					continue;
				}
				WP_CLI::success( 'Updated' );
				$this->log( 'iframe2ytblock_updatedIds.log', $id );
				$this->log( sprintf( 'iframe2ytblock_%s_1before.log', $id ), $post_content );
				$this->log( sprintf( 'iframe2ytblock_%s_2after.log', $id ), $post_content_updated );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator umbria24-update-feat-img-ids-from-logs`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_update_feat_imgs_ids_from_logs( $args, $assoc_args ) {
		$attachment_ids_changes_file = $assoc_args[ 'attachment-ids-changes-php-file' ] ?? null;
		$post_types = $assoc_args[ 'post-types-csv' ] ? explode( ',', $assoc_args[ 'post-types-csv' ] ) : [ 'post' ];
		$post_id_from = $assoc_args[ 'post-id-from' ] ?? null;
		$post_id_to = $assoc_args[ 'post-id-to' ] ?? null;

		$attachment_ids_changes = include( $assoc_args[ 'attachment-ids-changes-php-file' ] );

		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"select ID
					from $wpdb->posts
					where post_type in ( 'post', 'fotogallery', 'video', 'medialab' )
					and post_status in ( 'publish', 'draft' ) 
					and ID >= %d
					and ID <= %d
					order by ID desc",
				$post_id_from,
				$post_id_to
			)
		);

		foreach ( $ids as $key_id => $id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_id + 1, count( $ids ), $id ) );
			$attachment_id = get_post_thumbnail_id( $id );
			if ( isset( $attachment_ids_changes[$attachment_id] ) ) {
				update_post_meta( $id, '_thumbnail_id', $attachment_ids_changes[$attachment_id] );
				$this->log( 'featured_img_ids_updates.log', sprintf( "postID=%d oldAttId=%d newAttId=%d", $id, $attachment_id, $attachment_ids_changes[$attachment_id] ) );
			}
		}
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
