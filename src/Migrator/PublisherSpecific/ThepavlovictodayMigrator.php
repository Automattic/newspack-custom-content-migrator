<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use Symfony\Component\DomCrawler\Crawler as Crawler;
use \WP_CLI;
use \WP_Query;

/**
 * Custom migration scripts for Thepavlovictoday.
 */
class ThepavlovictodayMigrator implements InterfaceMigrator {

	// Logs.
	const LOG_POST_INSERTED = 'TPT_postInserted.log';
	const LOG_POST_SKIPPED = 'TPT_postSkipped.log';
	const LOG_INSERT_POST_ERROR = 'TPT_ERRInsertPost.log';
	const LOG_GUEST_AUTHOR_CREATED = 'TPT_authorCreated.log';
	const LOG_GUEST_AUTHOR_HANDLING_ERROR = 'TPT_ERRAuthorHandling.log';
	const LOG_GUEST_AUTHOR_AVATAR_ERROR = 'TPT_ERRAuthorAvatar.log';
	const LOG_GUEST_AUTHOR_ASSIGNED = 'TPT_authorAssigned.log';
	const LOG_CAT_CREATED = 'TPT_catCreated.log';
	const LOG_CAT_ASSIGNED = 'TPT_catAssigned.log';
	const LOG_CAT_CREATE_ERROR = 'TPT_ERRCatsCreate.log';
	const LOG_CAT_SET_ERROR = 'TPT_ERRCatsSet.log';
	const LOG_TAG_SET_ERROR = 'TPT_ERRTagsSet.log';
	const LOG_TAG_ASSIGNED = 'TPT_tagsAssigned.log';
	const LOG_FEATURED_IMAGE_IMPORT_ERROR = 'TPT_ERRFeaturedImageImport.log';
	const LOG_ERR_FEATURED_IMAGE_SET = 'TPT_ERRFeaturedImageSet.log';
	const LOG_FEATURED_IMAGE_SET = 'TPT_FeaturedImageSet.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

	/**
	 * @var Crawler.
	 */
	private $crawler;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->attachments_logic = new AttachmentsLogic();
		$this->crawler = new Crawler();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator thepavlovictoday-import-posts',
			[ $this, 'cmd_import_posts' ],
			[
				'shortdesc' => 'Imports thepavlovictoday custom converter.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator thepavlovictoday-remove-uncategorized-cat',
			[ $this, 'cmd_remove_uncategorized' ],
			[
				'shortdesc' => 'Removes Uncategorized Category from Posts where another Category is set.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator thepavlovictoday-fix-attachments-set-titles-to-captions',
			[ $this, 'cmd_attachments_set_titles_to_captions' ],
			[
				'shortdesc' => 'Fixes existing featured images captions.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator thepavlovictoday-helper-list-iframes',
			[ $this, 'cmd_helper_list_iframes' ],
			[
				'shortdesc' => 'Lists srcs found in iframes in Posts.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator thepavlovictoday-import-posts` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_import_posts( $args, $assoc_args ) {
		$time_start = microtime( true );

		global $wpdb;

		$site_url = 'https://www.thepavlovictoday.com';

		$b_pages = $wpdb->get_results( "select * from barbarian_page where barbarianpage_pagecode = 'common' ;", ARRAY_A );
		$b_users = $wpdb->get_results( "select * from barbarian_panel ;", ARRAY_A );
		$b_cats = $wpdb->get_results( "select * from barbarian_category ;", ARRAY_A );

		foreach ( $b_pages as $b_page_key => $b_page ) {

			WP_CLI::line( sprintf( '(%d/%d) ID %d', $b_page_key + 1, count( $b_pages ), $b_page[ 'barbarianpage_id' ] ) );

			// Skip if post exists.
			$query = new WP_Query( [
				'meta_key' => 'custom-meta-key',
				'meta_query' => [
					[
						'value' => $b_page[ 'barbarianpage_id' ],
						'key' => 'barbarianpage_id',
						'compare' => '=',
					]
				]
			] );
			if ( $query->have_posts() ) {
				WP_CLI::warning( $b_page[ 'barbarianpage_id' ] . ' already imported. Skipping.' );
				$this->log( self::LOG_POST_SKIPPED, $b_page[ 'barbarianpage_id' ] . ' already imported. Skipping.' );

				continue;
			}

			// Basic content.
			$post_meta = [];
			$post_data = [
				'post_type' => 'post',
				'post_title' => $b_page[ 'barbarianpage_title' ],
				'post_content' => $b_page[ 'barbarianpage_text' ],
				'post_excerpt' => $b_page[ 'barbarianpage_subtopic' ],
				'post_status' => ( 1 == $b_page[ 'barbarianpage_vrstaunosa' ] ? 'publish' : 'draft' ),
				'post_date' => $b_page[ 'barbarianpage_date'],
				'post_name' => $b_page[ 'barbarianpage_link'],
			];

			$post_id = wp_insert_post( $post_data );
			if ( is_wp_error( $post_id ) ) {
				WP_CLI::warning( sprintf(
					'Insert post error, %id - %s',
					$b_page[ 'barbarianpage_id' ],
					$post_id->get_error_message()
				) );
				$this->log( self::LOG_INSERT_POST_ERROR, sprintf( '%d %s', $b_page[ 'barbarianpage_id' ], $post_id->get_error_message() ) );
				WP_CLI::warning( sprintf( '   - error inserting post %d -- %s', $b_page[ 'barbarianpage_id' ], $post_id->get_error_message() ) );

				continue;
			}

			WP_CLI::success( sprintf( '   + created Post ID %d', $post_id ) );
			$this->log( self::LOG_POST_INSERTED, $post_id );

			$this->set_guest_author( $site_url, $b_users, $b_page, $post_id );

			$this->set_categories( $b_cats, $post_id, $b_page );

			$this->set_tags( $post_id, $b_page );

			$this->set_featured_image( $site_url, $post_id, $b_page );

			$post_meta[ 'barbarianpage_id' ] = $b_page[ 'barbarianpage_id' ];
			$this->set_post_meta( $post_id, $post_meta );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		WP_CLI::warning( 'Remember to:' );
		WP_CLI::warning( '- create the redirect from https://www.thepavlovictoday.com/en/* to https://www.thepavlovictoday.com/*' );
		WP_CLI::warning( '- run the image downloader' );
	}

	/**
	 * Callable for the `newspack-content-migrator thepavlovictoday-remove-uncategorized-cat` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_remove_uncategorized( $args, $assoc_args ) {
		$post_ids = $this->posts_logic->get_all_posts_ids();
		$cat_uncategorized = get_category_by_slug( 'uncategorized' );
		foreach ( $post_ids as $kety_post_ids => $post_id ) {
			WP_CLI::line( sprintf( '(%d/%d) ID %d', $kety_post_ids + 1, count( $post_ids ), $post_id ) );

			$cat_ids = wp_get_post_categories( $post_id );
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
	 * Callable for the `newspack-content-migrator thepavlovictoday-fix-attachments-set-titles-to-captions` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_attachments_set_titles_to_captions( $args, $assoc_args ) {
		$time_start = microtime( true );

		$query_images = new WP_Query( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => - 1,
		] );

		foreach ( $query_images->posts as $key_images => $image ) {
			WP_CLI::line( sprintf( '(%d/%d) ID %d', $key_images + 1, count( $query_images->posts ), $image->ID ) );

			/**
			 * Reminder:
			 *  image title -- $image->post_title
			 *  image caption -- $image->post_excerpt
			 *  image description -- $image->post_content
			 *  image alt -- $image's meta '_wp_attachment_image_alt'
			 */

			// If alt is set, and caption is the same as title, update the caption to alt's value.
			$image_title = $image->post_title;
			$image_alt = get_post_meta( $image->ID, '_wp_attachment_image_alt', TRUE );
			$image_caption = $image->post_excerpt;
			if ( ! empty( $image_alt ) && ( $image_caption == $image_title ) ) {
				$image->post_excerpt = $image_alt;
				wp_update_post( $image );
			}

			// If caption is set but title is empty, update title to caption's value.
			if ( empty( $image_caption ) && ! empty( $image_title ) ) {
				$image->post_excerpt = $image_title;
				wp_update_post( $image );
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for the `newspack-content-migrator thepavlovictoday-helper-list-iframes` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_helper_list_iframes( $args, $assoc_args ) {
		$time_start = microtime( true );

		$log_iframe_not_located = 'tpt_iframeNotLocated.log';
		$log_iframe_updated = 'tpt_iframeUpdated.log';

		WP_CLI::line( 'Fetching posts...' );
		$posts = $this->posts_logic->get_all_posts();
		foreach ( $posts as $key_posts => $post ) {

			WP_CLI::line( sprintf( '(%d/%d) ID %d', $key_posts + 1, count( $posts ), $post->ID ) );

			$content = $post->post_content;
			$content_decoded = html_entity_decode( $content );

			$this->crawler->clear();
			$this->crawler->add( $content_decoded );
			$iframes = $this->crawler->filter( 'iframe' );
			if ( $iframes->count() < 1 ) {
				continue;
			}

			foreach ( $iframes->getIterator() as $iframe ) {
				// This variable is not used because this returns a formatted HTML, not the original node HTML, but here's how one can get a node's HTML using the DOMCrawler.
				// $iframe_html = $iframe->ownerDocument->saveHTML( $iframe );

				$pos_start = strpos( $content_decoded, '<iframe' );
				$pos_end = strpos( $content_decoded, '</iframe>' );
				$iframe_html_unformatted = substr( $content_decoded, $pos_start, $pos_end + strlen( '</iframe>' ) - $pos_start );
				$src = $iframe->getAttribute( 'src' );
				$this->log( 'TPD_srcs.log', $src . "\n" . $post->ID );
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	private function get_block_embed( $src ) {
		return <<<BLOCK
<!-- wp:embed {"url":"$src","type":"rich","providerNameSlug":"embed-handler","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-rich is-provider-embed-handler wp-block-embed-embed-handler wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
$src
</div></figure>
<!-- /wp:embed -->
BLOCK;
	}

	private function set_post_meta( $post_id, $post_meta ) {
		foreach ( $post_meta as $meta_key => $meta_value ) {
			update_post_meta( $post_id, $meta_key, $meta_value);
		}
	}

	private function set_featured_image( $site_url, $post_id, $b_page ) {
		if ( ! empty( $b_page[ 'barbarianpage_img' ] ) ) {
			$fetured_image_id = $this->attachments_logic->import_external_file(
				$site_url . $b_page[ 'barbarianpage_img' ],
				$b_page[ 'barbarianpage_imgsignature' ] ?? null,
				$b_page[ 'barbarianpage_imgsignature' ] ?? null
			);
			if ( is_wp_error( $fetured_image_id ) ) {
				$this->log( self::LOG_FEATURED_IMAGE_IMPORT_ERROR, sprintf( '%d %s %s', $post_id, $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id->get_error_message() ) );
				WP_CLI::warning( sprintf( '   - error importing featured image %s -- %s', $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id->get_error_message() ) );

				return;
			}

			$featured_image_set = set_post_thumbnail( $post_id, $fetured_image_id );
			if ( false == $featured_image_set ) {
				$this->log( self::LOG_ERR_FEATURED_IMAGE_SET, sprintf( '%d %s %s', $post_id, $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id ) );
				WP_CLI::warning( sprintf( '   - error setting featured image %s -- %s', $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id ) );

				return;
			}
			$this->log( self::LOG_FEATURED_IMAGE_SET, sprintf( '%d %d', $post_id, $fetured_image_id ) );
			WP_CLI::success( sprintf( '   + set featured image %d', $fetured_image_id ) );
		}
	}

	private function set_tags( $post_id, $b_page ) {
		if ( ! empty( $b_page[ 'barbarianpage_metakeywords' ] ) ) {
			$b_tags = explode( ',', $b_page[ 'barbarianpage_metakeywords' ] );
			if ( ! empty( $b_tags ) ) {
				$tags_set_result = wp_set_post_tags( $post_id, $b_tags );
				if ( is_wp_error( $tags_set_result ) ) {
					$this->log( self::LOG_TAG_SET_ERROR, sprintf( '%d %s %s', $post_id, json_encode( $tags_set_result ), $tags_set_result->get_error_message() ) );
					WP_CLI::warning( sprintf( '   - error setting tags %s -- %s', json_encode( $tags_set_result ), $tags_set_result->get_error_message() ) );

					return;
				}

				WP_CLI::success( sprintf( '   + assigned tags %s', implode( ',', $tags_set_result ) ) );
				$this->log( self::LOG_TAG_ASSIGNED, $post_id, implode( ',', $tags_set_result ) );
			}
		}
	}

	private function set_categories( $b_cats, $post_id, $b_page ) {

		if ( ! empty( $b_page[ 'barbarianpage_navigation_kat' ] ) ) {
			$post_cats = [];

			// Parent Category.
			$b_cat = $this->filter_array( $b_cats, 'barbariancategory_id', $b_page[ 'barbarianpage_navigation_kat' ] );
			if ( null === $b_cat ) {
				return;
			}

			$cat = get_category_by_slug( $b_cat[ 'barbariancategory_link' ] );
			if ( ! $cat ) {
				$insert_term_result = wp_insert_term( $b_cat[ 'barbariancategory_name' ], 'category', [ 'slug' => $b_cat[ 'barbariancategory_link' ], ] );
				if ( is_wp_error( $insert_term_result ) ) {
					$this->log( self::LOG_CAT_CREATE_ERROR, sprintf( '%d %s %s', $post_id, $b_cat[ 'barbariancategory_name' ], $insert_term_result->get_error_message() ) );
					WP_CLI::warning( sprintf( '   - error creating Subcategory %s -- %s', $b_cat[ 'barbariancategory_name' ], $insert_term_result->get_error_message() ) );

					return;
				}
				$cat_id = $insert_term_result[ 'term_id' ];
			} else {
				$cat_id = $cat->term_id;
			}

			WP_CLI::success( sprintf( '   + created Cat %d %s', $cat_id, $b_cat[ 'barbariancategory_name' ] ) );
			$this->log( self::LOG_CAT_CREATED, $cat_id, $b_cat[ 'barbariancategory_name' ] );

			$post_cats[] = $cat_id;

			// Subcategory.
			if ( ! empty( $b_page[ 'barbarianpage_navigation_sub' ] ) ) {
				$b_sub_cat = $this->filter_array( $b_cats, 'barbariancategory_id', $b_page[ 'barbarianpage_navigation_sub' ] );
				if ( null === $b_sub_cat ) {
					return;
				}

				$subcat = get_category_by_slug( $b_sub_cat[ 'barbariancategory_link' ] );
				if ( ! $subcat ) {
					$insert_term_result = wp_insert_term( $b_sub_cat[ 'barbariancategory_name' ], 'category', [ 'parent' => $cat_id, 'slug' => $b_sub_cat[ 'barbariancategory_link' ], ] );
					if ( is_wp_error( $insert_term_result ) ) {
						$this->log( self::LOG_CAT_CREATE_ERROR, sprintf( '%d %s %s', $post_id, $b_sub_cat[ 'barbariancategory_name' ], $insert_term_result->get_error_message() ) );
						WP_CLI::warning( sprintf( '   - error creating Subcategory %s -- %s', $b_sub_cat[ 'barbariancategory_name' ], $insert_term_result->get_error_message() ) );

						return;
					}
					$sub_cat_id = $insert_term_result[ 'term_id' ];
				} else {
					$sub_cat_id = $cat->term_id;
				}

				WP_CLI::success( sprintf( '   + created Subcat %d %s', $sub_cat_id, $b_sub_cat[ 'barbariancategory_name' ] ) );
				$this->log( self::LOG_CAT_CREATED, $sub_cat_id, $b_sub_cat[ 'barbariancategory_name' ] );

				$post_cats[] = $sub_cat_id;
			}

			$cats_set = wp_set_post_categories( $post_id, $post_cats, true );
			if ( is_wp_error( $cats_set ) ) {
				$this->log( self::LOG_CAT_SET_ERROR, sprintf( '%d %s %s', $post_id, json_encode( $cats_set ), $cats_set->get_error_message() ) );
				WP_CLI::warning( sprintf( '   - error setting categories %s -- %s', json_encode( $cats_set ), $cats_set->get_error_message() ) );

				return;
			}

			WP_CLI::success( sprintf( '   + assigned cats %s', implode( ',', $cats_set ) ) );
			$this->log( self::LOG_CAT_ASSIGNED, $post_id, implode( ',', $cats_set ) );
		}
	}

	private function set_guest_author( $site_url, $b_users, $b_page, $post_id ) {
		$author = $this->filter_array( $b_users, 'id', $b_page[ 'barbarianpage_author' ] );
		if ( null !== $author ) {
			try {
				// Some usernames are invalid in WP, and for these create new usernames from full names.
				if ( '0' == $author[ 'barbarian_username' ] ) {
					$user_login = sanitize_user( strtolower( str_replace( ' ', '_', $author[ 'barbarian_name' ] ) ) );
				} else {
					$user_login = $author[ 'barbarian_username' ];
				}

				$ga_data = [
					'display_name' => $author[ 'barbarian_name' ],
					'user_login' => $user_login,
					'user_email' => $author[ 'barbarianuser_email' ],
					'website' => $author[ 'barbarianuser_url' ],
					'description' => $author[ 'barbarianuser_desc' ],
				];

				if ( $author[ 'barbarianuser_image' ] ) {
					$avatar_attachment_id = $this->attachments_logic->import_external_file( $site_url . $author[ 'barbarianuser_image' ] );
					if ( is_wp_error( $avatar_attachment_id) ) {
						$this->log( self::LOG_GUEST_AUTHOR_AVATAR_ERROR, sprintf( '%s %s %s', $author[ 'barbarian_name' ], $author[ 'barbarianuser_image' ], $avatar_attachment_id->get_error_message() ) );
						WP_CLI::warning( sprintf( '   - error setting GA avatar %s', $avatar_attachment_id->get_error_message() ) );

						return;
					}

					$ga_data[ 'avatar' ] = $avatar_attachment_id;
				}

				$guest_author_id = $this->coauthorsplus_logic->create_guest_author( $ga_data );
				$this->log( self::LOG_GUEST_AUTHOR_CREATED, sprintf( '%d %s', $guest_author_id, json_encode( $ga_data ) ) );
				WP_CLI::success( sprintf( '   + created GA %d', $guest_author_id ) );

				$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post_id );
				$this->log( self::LOG_GUEST_AUTHOR_ASSIGNED, sprintf( '%d %d', $post_id, $guest_author_id ) );
				WP_CLI::success( sprintf( '   + assigned GA %d', $guest_author_id ) );
			} catch ( \Exception $e ) {
				$this->log( self::LOG_GUEST_AUTHOR_HANDLING_ERROR, sprintf( '%s %s', $e->getMessage(), json_encode( $ga_data ) ) );
				WP_CLI::warning( sprintf( '%s %s', $e->getMessage(), json_encode( $ga_data ) ) );

				return;
			}
		}
	}

	/**
	 * Returns haystack array's element with $key and $value.
	 *
	 * @param array $haystack Array to search.
	 * @param mixed $key      Key.
	 * @param mixed $value    Value
	 *
	 * @return array|null Found element or null.
	 *
	 */
	private function filter_array( $haystack, $key, $value ) {
		foreach ( $haystack as $element ) {
			if ( isset( $element[ $key ] ) && $value == $element[ $key ]  ) {
				return $element;
			}
		}

		return null;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
