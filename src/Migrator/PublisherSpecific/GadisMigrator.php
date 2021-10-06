<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Gadis.co.id.
 */
class GadisMigrator implements InterfaceMigrator {

	// Logs.
	const LOG_POST_SKIPPED                = 'GADIS_postSkipped.log';
	const LOG_POST_NO_CONTENT             = 'GADIS_postNoContent.log';
	const LOG_INSERT_POST_ERROR           = 'GADIS_insertPostError.log';
	const LOG_USER_ERROR                  = 'GADIS_userError.log';
	const LOG_CAT_CREATE_ERROR            = 'GADIS_ERRCatsCreate.log';
	const LOG_CAT_SET_ERROR               = 'GADIS_ERRCatsSet.log';
	const LOG_TAG_SET_ERROR               = 'GADIS_ERRTagsSet.log';
	const LOG_GALLERY_IMAGE_IMPORT_ERROR  = 'GADIS_ERRGalleryImageImport.log';
	const LOG_FEATURED_IMAGE_IMPORT_ERROR = 'GADIS_ERRFeaturedImageImport.log';
	const LOG_ERR_FEATURED_IMAGE_SET      = 'GADIS_ERRFeaturedImageSet.log';

	// CDN URI.
	const CDN_URI = 'https://cdn.gadis.co.id/bucket-gadis-production';
	
	// Categories map.
	const CATEGORIES_MAP = [
		'fashion'   => 'Fashion',
		'beauty'    => 'Beauty',
		'event'     => 'Event',
		'lifeguide' => 'Life Guide',
		'voice'     => 'Your Voice',
		'celeb'     => 'Celeb and Entertainment',
		'magz'      => 'Gadis on MAGZ',
		'diy'       => 'DIY',
		'win'       => 'WIN',
		'alumni'    => 'Alumni', // Exists on categories table but not on articles table.
	];

	// Default user slug.
	const DEFAULT_USER_SLUG = 'admin';

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	private function __construct() {
		$this->posts_logic       = new PostsLogic();
		$this->attachments_logic = new AttachmentsLogic();
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
			'newspack-content-migrator gadis-import-posts',
			[ $this, 'cmd_import_posts' ],
			[
				'shortdesc' => 'Import Gadis articles',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator gadis-import-tv',
			[ $this, 'cmd_import_tv' ],
			[
				'shortdesc' => 'Import Gadis TV',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator gadis-import-events',
			[ $this, 'cmd_import_events' ],
			[
				'shortdesc' => 'Import Gadis Events',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator gadis-remove-uncategorized-cat',
			[ $this, 'cmd_remove_uncategorized' ],
			[
				'shortdesc' => 'Removes Uncategorized Category from Posts where another Category is set.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Fully renders a Core Gallery Block from attachment IDs.
	 * Presently hard-coded attributes use ampCarousel and ampLightbox.
	 * 
	 * Borrowed from MichiganDailyMigrator.
	 *
	 * @param array $ids Attachment IDs.
	 *
	 * @return string Gallery block HTML.
	 */
	private function render_gallery_block( $ids ) {
		// Compose the HTML with all the <li><figure><img/></figure></li> image pieces.
		$images_li_html = '';
		foreach ( $ids as $id ) {
			$img_url       = wp_get_attachment_url( $id );
			$img_caption   = wp_get_attachment_caption( $id );
			$img_alt       = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$img_data_link = $img_url;

			$img_element        = sprintf(
				'<img src="%s" alt="%s" data-id="%d" data-full-url="%s" data-link="%s" class="%s"/>',
				$img_url,
				$img_alt,
				$id,
				$img_url,
				$img_data_link,
				'wp-image-' . $id
			);
			$figcaption_element = ! empty( $img_caption )
				? sprintf( '<figcaption class="blocks-gallery-item__caption">%s</figcaption>', esc_attr( $img_caption ) )
				: '';
			$images_li_html    .= '<li class="blocks-gallery-item">'
				. '<figure>'
				. $img_element
				. $figcaption_element
				. '</figure>'
				. '</li>';
		}

		// The inner HTML of the gallery block.
		$inner_html    = '<figure class="wp-block-gallery columns-3 is-cropped">'
			. '<ul class="blocks-gallery-grid">'
			. $images_li_html
			. '</ul>'
			. '</figure>';
		$block_gallery = [
			'blockName'    => 'core/gallery',
			'attrs'        => [
				'ids'         => $ids,
				'linkTo'      => 'none',
				'ampCarousel' => true,
				'ampLightbox' => true,
			],
			'innerBlocks'  => [],
			'innerHTML'    => $inner_html,
			'innerContent' => [ $inner_html ],
		];

		// Fully rendered gallery block.
		$block_gallery_rendered = '<!-- wp:gallery {"ids":[' . esc_attr( implode( ',', $ids ) ) . '],"linkTo":"none","ampCarousel":true,"ampLightbox":true} -->'
			. "\n"
			. render_block( $block_gallery )
			. "\n"
			. '<!-- /wp:gallery -->';

		return $block_gallery_rendered;
	}

	/**
	 * Set multiple post meta from array.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $post_meta Post meta.
	 */
	private function set_post_meta( $post_id, $post_meta ) {
		foreach ( $post_meta as $meta_key => $meta_value ) {
			if ( ! empty( $meta_value ) ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}
	}

	/**
	 * Get post content from Gadis articles pages and gallery images.
	 * 
	 * @param array $pages   Articles pages.
	 * @param array $gallery Gallery images.
	 * 
	 * @return string Post content.
	 */
	private function get_post_content( $pages, $gallery ) {
		$content    = '';
		$page_count = count( $pages );
		foreach ( $pages as $page ) {
			$content .= $page['content'];
			if ( --$page_count <= 0 ) {
				break;
			}
			$content .= "\n<!--nextpage-->\n";
		}

		if ( ! empty( $gallery ) ) {
			$ids = [];
			foreach ( $gallery as $image ) {
				$url      = self::CDN_URI . $image['image'];
				$image_id = $this->attachments_logic->import_external_file( $url );
				if ( is_wp_error( $image_id ) ) {
					$this->log( self::LOG_GALLERY_IMAGE_IMPORT_ERROR, sprintf( '%s %s', $url, $image_id->get_error_message() ) );
					WP_CLI::warning( sprintf( 'Error importing gallery image %s -- %s', $url, $image_id->get_error_message() ) );
					return;
				}
				$ids[] = $image_id;
			}
			$content .= $this->render_gallery_block( $ids );
		}
		return $content;
	}

	/**
	 * Set post featured image from remote URL.
	 *
	 * @param int    $post_id     Post ID.
	 * @param array  $url         Remote URL.
	 * @param string $title       Optional. Attachment title.
	 * @param string $caption     Optional. Attachment caption.
	 * @param string $description Optional. Attachment description.
	 * @param string $alt         Optional. Image Attachment `alt` attribute.
	 */
	private function set_featured_image_from_remote_url( $post_id, $url, $title = null, $caption = null, $description = null, $alt = null ) {
		$image_id = $this->attachments_logic->import_external_file( $url, $title, $caption, $description, $alt, $post_id );
		if ( is_wp_error( $image_id ) ) {
			$this->log( self::LOG_FEATURED_IMAGE_IMPORT_ERROR, sprintf( '%d %s %s', $post_id, $url, $image_id->get_error_message() ) );
			WP_CLI::warning( sprintf( 'Error importing featured image %s -- %s', $url, $image_id->get_error_message() ) );
			return;
		}
		$image_set = set_post_thumbnail( $post_id, $image_id );
		if ( false == $image_set ) {
			$this->log( self::LOG_ERR_FEATURED_IMAGE_SET, sprintf( '%d %s %s', $post_id, $url, $image_id ) );
			WP_CLI::warning( sprintf( 'Error setting featured image %s -- %s', $url, $image_id ) );
			return;
		}
	}

	/**
	 * Set post featured image from Gadis article "cover".
	 *
	 * @param int   $post_id Post ID.
	 * @param array $article Gadis article object.
	 */
	private function set_featured_image( $post_id, $article ) {
		if ( ! empty( $article['cover'] ) ) {
			$url = self::CDN_URI . '/' . $article['cover'];
			$this->set_featured_image_from_remote_url( $post_id, $url );
		}
	}

	/**
	 * Set post categories from Gadis article "categories" and "subcategories".
	 * 
	 * @param array $sub_categories List of existing subcategories.
	 * @param int   $post_id        Post ID.
	 * @param array $article        Gadis article object.
	 */
	private function set_categories( $sub_categories, $post_id, $article ) {
		if ( empty( $article['category'] ) ) {
			return;
		}
		$post_cats = [];
		
		$category = get_category_by_slug( $article['category'] );
		if ( ! $category ) {
			$insert_term_res = wp_insert_term(
				self::CATEGORIES_MAP[ $article['category'] ],
				'category',
				[ 'slug' => $article['category'] ]
			);
			if ( is_wp_error( $insert_term_res ) ) {
				$this->log( self::LOG_CAT_CREATE_ERROR, sprintf( '%d %s %s', $post_id, $article['category'], $insert_term_res->get_error_message() ) );
				WP_CLI::warning( sprintf( 'Error creating category %s -- %s', $article['category'], $insert_term_res->get_error_message() ) );
				return;
			}
			$term_id = $insert_term_res['term_id'];
		} else {
			$term_id = $category->term_id;
		}
		$post_cats[] = $term_id;

		// Subcategory.
		if ( ! empty( $article['subcategory_id'] ) ) {
			$subcategory      = array_values(
				array_filter(
					$sub_categories,
					function( $cat ) use ( $article ) {
						return $cat['id'] === $article['subcategory_id'];
					}
				)
			);
			$subcategory_slug = $article['category'] . '-' . sanitize_title( $subcategory[0]['name'] );
			$subcategory_term = get_category_by_slug( $subcategory_slug );
			if ( ! $subcategory_term ) {
				$insert_term_res = wp_insert_term(
					$subcategory[0]['name'],
					'category',
					[
						'slug'   => $subcategory_slug,
						'parent' => $term_id,
					]
				);
				if ( is_wp_error( $term_id ) ) {
					$this->log( self::LOG_CAT_CREATE_ERROR, sprintf( '%d %s %s', $post_id, $subcategory[0]['name'], $insert_term_res->get_error_message() ) );
					WP_CLI::warning( sprintf( 'Error creating subcategory %s -- %s', $subcategory[0]['name'], $insert_term_res->get_error_message() ) );
					return;
				}
				$subcategory_term_id = $insert_term_res['term_id'];
			} else {
				$subcategory_term_id = $subcategory_term->term_id;
			}
			$post_cats[] = $subcategory_term_id;
		}

		$result = wp_set_post_categories( $post_id, $post_cats, true );
		if ( is_wp_error( $result ) ) {
			$this->log( self::LOG_CAT_SET_ERROR, sprintf( '%d %s %s', $post_id, json_encode( $result ), $result->get_error_message() ) );
			WP_CLI::warning( sprintf( 'Error setting post categories %s -- %s', json_encode( $result ), $result->get_error_message() ) );
			return;
		}
	}

	/**
	 * Set post tags from article "tags".
	 *
	 * @param array $tags     List of existing tags.
	 * @param int   $post_id  Post ID.
	 * @param int   $gadis_id Gadis article ID.
	 */
	private function set_tags( $tags, $post_id, $gadis_id ) {
		$post_tags = array_values(
			array_filter(
				$tags,
				function( $tag ) use ( $gadis_id ) {
					return $tag['article_id'] === $gadis_id && ! empty( $tag['tag'] );
				}
			)
		);
		if ( ! empty( $post_tags ) ) {
			$result = wp_set_post_tags(
				$post_id,
				array_map(
					function( $tag ) {
						return $tag['tag'];
					},
					$post_tags
				)
			);
			if ( is_wp_error( $result ) ) {
				$this->log( self::LOG_TAG_SET_ERROR, sprintf( '%d %s %s', $post_id, json_encode( $result ), $result->get_error_message() ) );
				WP_CLI::warning( sprintf( 'Error setting post tags %s -- %s', json_encode( $result ), $result->get_error_message() ) );
				return;
			}
		}
	}

	/**
	 * Upsert user from Gadis user ID.
	 *
	 * @param array   $users    List of existing users.
	 * @param int     $gadis_id Gadis user ID.
	 * @param boolean $update   Whether to update existing user.
	 * 
	 * @return int|null User ID.
	 */
	private function upsert_user( $users, $gadis_id, $update = false ) {
		global $wpdb;

		$user = array_values(
			array_filter(
				$users,
				function( $user ) use ( $gadis_id ) {
					return $user['id'] === $gadis_id;
				}
			)
		);

		if ( empty( $user ) ) {
			$this->log( self::LOG_USER_ERROR, sprintf( '%d user not found', $gadis_id ) );
			WP_CLI::warning( sprintf( '%d user not found', $gadis_id ) );
			return;
		}

		$user = $user[0];

		$profile = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM user_profiles WHERE user_id = %d;',
				(int) $gadis_id
			),
			ARRAY_A
		);

		$role_map = [
			1 => 'administrator',
			4 => 'editor',
			6 => 'administrator',
			8 => 'subscriber',
		];

		$meta_keys = [
			'born_date',
			'gender',
			'school',
			'class',
			'country',
			'province',
			'city',
			'address',
			'twitter',
			'instagram',
			'facebook',
			'youtube',
			'pinterest',
			'line',
		];

		$user_data = [
			'user_login'    => $profile['username'] ?? $user['email'],
			'user_nicename' => sanitize_title( $user['fullname'] ), // Force a nicename so it doesn't expose user's email.
			'user_email'    => $user['email'],
			'description'   => $profile['biography'],
			'nickname'      => $user['fullname'],
			'display_name'  => $user['fullname'],
			'role'          => $role_map[ $user['role_id'] ],
		];
		$user_meta = [
			'gadis_id' => $gadis_id,
			'phone'    => $user['phone'] ?? '',
		];
		foreach ( $meta_keys as $key ) {
			if ( ! empty( $profile[ $key ] ) ) {
				$user_meta[ $key ] = $profile[ $key ];
			}
		}

		$result_meta = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s and meta_value = %d;",
				'gadis_id',
				(int) $gadis_id
			),
			ARRAY_A
		);

		if ( isset( $result_meta['user_id'] ) ) {
			$user_id = $result_meta['user_id'];
			if ( true === $update ) {
				$user_id = wp_update_user( $user_data );
				if ( is_wp_error( $user_id ) ) {
					$this->log( self::LOG_USER_ERROR, sprintf( '%d %s', $gadis_id, $user_id->get_error_message() ) );
					WP_CLI::warning( sprintf( 'Error updating user %d -- %s', $gadis_id, $user_id->get_error_message() ) );
					return;
				}
			}
		} else {
			$user_id = wp_insert_user( $user_data );
			if ( is_wp_error( $user_id ) ) {
				$this->log( self::LOG_USER_ERROR, sprintf( '%d %s', $gadis_id, $user_id->get_error_message() ) );
				WP_CLI::warning( sprintf( 'Error creating user %d -- %s', $gadis_id, $user_id->get_error_message() ) );
				return;
			}
		}
		foreach ( $user_meta as $meta_key => $meta_value ) {
			update_user_meta( $user_id, $meta_key, $meta_value );
		}
		return $user_id;
	}

	/**
	 * Import Gadis articles.
	 *
	 * @param array   $articles     Gadis articles.
	 * @param boolean $force_import Whether to update already imported articles.
	 */
	private function import_articles( $articles, $force_import = false ) {

		global $wpdb;

		$article_pages  = $wpdb->get_results( 'SELECT * FROM article_pages;', ARRAY_A );
		$tags           = $wpdb->get_results( "SELECT * FROM article_tags WHERE tag != '';", ARRAY_A );
		$galleries      = $wpdb->get_results( 'SELECT * FROM article_galleries;', ARRAY_A );
		$sub_categories = $wpdb->get_results( 'SELECT * FROM sub_categories;', ARRAY_A );
		$users          = $wpdb->get_results( 'SELECT u.id, u.email, u.fullname, r.role_id FROM users AS u LEFT JOIN role_users AS r ON r.user_id = u.id WHERE r.role_id IN (1, 4, 6);', ARRAY_A );

		foreach ( $articles as $article_key => $article ) {
			$gadis_id = $article['id'];

			WP_CLI::line( sprintf( '(%d/%d) ID %d', $article_key + 1, count( $articles ), $gadis_id ) );

			$ID          = null;
			$result_meta = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d;',
					'gadis_id',
					(int) $gadis_id
				),
				ARRAY_A
			);
			if ( isset( $result_meta['post_id'] ) ) {
				if ( false === $force_import ) {
					WP_CLI::warning( $gadis_id . ' already imported. Skipping.' );
					$this->log( self::LOG_POST_SKIPPED, $gadis_id . ' already imported. Skipping.' );
					continue;
				}
				$ID = $result_meta['post_id'];
			}

			$pages = array_values(
				array_filter(
					$article_pages,
					function( $article_content ) use ( $article ) {
						return $article_content['article_id'] === $article['id'];
					}
				)
			);

			if ( empty( $pages ) ) {
				WP_CLI::warning( $gadis_id . ' does not have content.' );
				$this->log( self::LOG_POST_NO_CONTENT, $gadis_id . '  does not have content.' );
				continue;
			}

			$gallery = array_values(
				array_filter(
					$galleries,
					function( $image ) use ( $article ) {
						return $image['article_id'] === $article['id'];
					}
				)
			);

			$user_id      = $this->upsert_user( $users, $article['user_id'] );
			$post_content = $this->get_post_content( $pages, $gallery );

			$post_data = [
				'ID'            => $ID,
				'post_type'     => 'post',
				'post_title'    => $article['title'],
				'post_content'  => $post_content,
				'post_excerpt'  => $article['description'] ? $article['description'] : '',
				'post_status'   => 1 === (int) $article['is_publish'] ? 'publish' : 'draft',
				'post_author'   => $user_id,
				'post_date'     => $pages[0]['created_at'],
				'post_modified' => $pages[0]['updated_at'],
				'post_name'     => $article['slug'],
			];
			$post_meta = [
				'gadis_id'         => $gadis_id,
				'presented_by'     => $article['presented_by'],
				'is_paywall'       => $article['is_paywall'],
				'sponsor_text'     => $article['sponsor_text'],
				'url_sponsor'      => $article['url_sponsor'],
				'challenge_status' => $article['challenge_status'],
				'view_counter'     => $article['view_counter'],
			];

			// Events meta.
			$post_meta = array_merge(
				$post_meta,
				[
					'meeting_id' => $article['meeting_id'],
					'topic'      => $article['topic'],
					'zoom_link'  => $article['zoom_link'],
					'start_date' => $article['start_date'],
					'end_date'   => $article['end_date'],
				]
			);

			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning(
					sprintf(
						'Insert post error, %d - %s',
						$gadis_id,
						$post_id->get_error_message()
					) 
				);
				$this->log( self::LOG_INSERT_POST_ERROR, sprintf( '%d %s', $gadis_id, $post_id->get_error_message() ) );
				continue;
			}

			$this->set_post_meta( $post_id, $post_meta );
			$this->set_featured_image( $post_id, $article );
			$this->set_categories( $sub_categories, $post_id, $article );
			$this->set_tags( $tags, $post_id, $gadis_id );
		}
	}

	/**
	 * Transform provider name to slug. (kebab case)
	 * 
	 * @param string $provider_name Provider name.
	 * 
	 * @return string Provider slug.
	 */
	private function get_video_provider_slug( $provider_name ) {
		return strtolower( str_replace( ' ', '-', $provider_name ) );
	}

	/**
	 * Import GadisTV videos.
	 *
	 * @param array   $videos       GadisTV videos.
	 * @param boolean $force_import Whether to update already imported articles.
	 */
	private function import_videos( $videos, $force_import = false ) {

		global $wpdb, $wp_embed;

		// Setup default user
		$user    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT u.id, u.email, u.fullname, r.role_id FROM users AS u LEFT JOIN role_users AS r ON r.user_id = u.id LEFT JOIN user_profiles AS p ON p.user_id = u.id WHERE p.username = %s;',
				self::DEFAULT_USER_SLUG
			),
			ARRAY_A
		);
		$user_id = $this->upsert_user( [ $user ], $user['id'] ); 

		// Setup default category
		$category = get_category_by_slug( 'gadistv' );
		if ( ! $category ) {
			$insert_term_res = wp_insert_term(
				'GadisTV',
				'category',
				[ 'slug' => 'gadistv' ]
			);
			if ( is_wp_error( $insert_term_res ) ) {
				$this->log( self::LOG_CAT_CREATE_ERROR, sprintf( 'GadisTV %s', $insert_term_res->get_error_message() ) );
				WP_CLI::warning( sprintf( 'Error creating category GadisTV -- %s', $insert_term_res->get_error_message() ) );
				return;
			}
			$term_id = $insert_term_res['term_id'];
		} else {
			$term_id = $category->term_id;
		}

		foreach ( $videos as $video_key => $video ) {
			$gadis_id = 'gadistv_' . $video['id'];

			WP_CLI::line( sprintf( '(%d/%d) ID %d', $video_key + 1, count( $videos ), $video['id'] ) );

			$post_id     = null;
			$result_meta = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s;",
					'gadis_id',
					$gadis_id
				),
				ARRAY_A
			);
			if ( isset( $result_meta['post_id'] ) ) {
				if ( false === $force_import ) {
					WP_CLI::warning( $gadis_id . ' already imported. Skipping.' );
					$this->log( self::LOG_POST_SKIPPED, $gadis_id . ' already imported. Skipping.' );
					continue;
				}
				$post_id = $result_meta['post_id'];
			}

			$url = $video['url'];

			// Ensure we have a valid YouTube URL.
			preg_match( '/^.*((youtu.be\/)|(v\/)|(\/u\/\w\/)|(embed\/)|(watch\?))\??v?=?([^#&?]*).*/', $video['url'], $matches );
			if ( isset( $matches[7] ) && $matches[7] ) {
				$youtube_id = $matches[7];
				$url        = 'https://www.youtube.com/watch?v=' . $youtube_id;
			}

			$oembed      = _wp_oembed_get_object();
			$oembed_data = $oembed->get_data( $url );

			// Thumbnail.
			$thumbnail_url = false;
			if ( $oembed_data && ! empty( $oembed_data->thumbnail_url ) ) {
				$thumbnail_url = $oembed_data->thumbnail_url;
			}

			$block      = [
				'url'              => $url,
				'type'             => $oembed_data && ! empty( $oembed_data->type ) ? $oembed_data->type : 'rich',
				'providerNameSlug' => $oembed_data && ! empty( $oembed_data->provider_name ) ? $this->get_video_provider_slug( $oembed_data->provider_name ) : 'embed-handler',
				'responsive'       => true,
				'className'        => [],
			];
			$classnames = [
				'wp-block-embed',
				'wp-block-embed-' . $block['providerNameSlug'],
				'is-type-' . $block['type'],
				'is-provider-' . $block['providerNameSlug'],
			];

			$post_content  = '<!-- wp:embed ' . wp_json_encode( $block ) . ' -->';
			$post_content .= "\n";
			$post_content .= '<figure class="' . esc_attr( implode( ' ', $classnames + $block['className'] ) ) . '">';
			$post_content .= "\n";
			$post_content .= '<div class="wp-block-embed__wrapper">' . "\n" . $url . "\n" . '</div>';
			$post_content .= "\n";
			$post_content .= '</figure>';
			$post_content .= "\n";
			$post_content .= '<!-- /wp:embed -->';

			$post_meta = [
				'gadis_id'        => $gadis_id,
				'gadis_video_url' => $url,
			];

			$post_data = [
				'ID'            => $post_id,
				'post_type'     => 'post',
				'post_title'    => $video['title'],
				'post_author'   => $user_id,
				'post_status'   => 'publish',
				'post_category' => [ $term_id ],
				'post_content'  => $post_content,
			];

			$post_id = wp_insert_post( $post_data );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning(
					sprintf(
						'Insert post error, %d - %s',
						$gadis_id,
						$post_id->get_error_message()
					)
				);
				$this->log( self::LOG_INSERT_POST_ERROR, sprintf( '%d %s', $video['id'], $post_id->get_error_message() ) );
				continue;
			}

			$this->set_post_meta( $post_id, $post_meta );
			if ( $thumbnail_url ) {
				$this->set_featured_image_from_remote_url( $post_id, $thumbnail_url, $video['title'] );
			}

			// Trigger caching of embed block.
			$wp_embed->cache_oembed( $post_id );
		}

	}

	/**
	 * Import GadisTV Events.
	 *
	 * @param array $articles Gadis articles.
	 */
	private function import_events( $articles ) {
		return;
	}

	/**
	 * Callable for the `newspack-content-migrator gadis-import-posts` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_import_posts( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );
		// Select every article that is not an event.
		$articles = $wpdb->get_results( "SELECT * FROM articles WHERE category NOT IN ('event');", ARRAY_A );
		$this->import_articles( $articles );
		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for the `newspack-content-migrator gadis-import-tv` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_import_tv( $args, $assoc_args ) {
		global $wpdb;
		$time_start = microtime( true );
		$items      = $wpdb->get_results( 'SELECT * FROM gadistvs ORDER BY id ASC;', ARRAY_A );
		$this->import_videos( $items );
		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for the `newspack-content-migrator gadis-import-events` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_import_events( $args, $assoc_args ) {
		if ( ! function_exists( 'tribe_create_event') ) {
			WP_CLI::warning( 'The Events Calendar plugin is not installed' );
			return;
		}
		global $wpdb;
		$time_start = microtime( true );
		// Select every article that is not an event.
		$articles = $wpdb->get_results( "SELECT * FROM articles WHERE category IN ('event');", ARRAY_A );
		$this->import_events( $articles );
		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for the `newspack-content-migrator gadis-remove-uncategorized-cat` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_remove_uncategorized( $args, $assoc_args ) {
		$post_ids          = $this->posts_logic->get_all_posts_ids();
		$cat_uncategorized = get_category_by_slug( 'uncategorized' );
		foreach ( $post_ids as $key => $post_id ) {
			WP_CLI::line( sprintf( '(%d/%d) ID %d', $key + 1, count( $post_ids ), $post_id ) );

			$cat_ids         = wp_get_post_categories( $post_id );
			$cat_ids_updated = $cat_ids;
			foreach ( $cat_ids_updated as $key_cat => $cat_id ) {
				if ( $cat_uncategorized->term_id == $cat_id ) {
					unset( $cat_ids_updated[ $key_cat ] );
				}
			}
			if ( $cat_ids_updated != $cat_ids && ! empty( $cat_ids_updated ) ) {
				$cat_ids_updated = array_values( $cat_ids_updated );
				wp_set_post_categories( $post_id, $cat_ids_updated, false );
			}
		}
	}
	
	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message = date( DATE_ATOM ) . ' - ' . $message . "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
