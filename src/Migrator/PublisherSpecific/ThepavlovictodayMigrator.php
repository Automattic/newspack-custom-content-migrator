<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
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

		// DEV TEST POSTS:

		// cats
		// https://www.thepavlovictoday.com/barbarian/index.php?control=101&place=article&action=inc_clanak_edit&id=13032
		// https://www.thepavlovictoday.com/barbarian/index.php?control=101&place=article&action=inc_clanak_edit&id=13034
		// https://www.thepavlovictoday.com/barbarian/index.php?control=101&place=article&action=inc_clanak_edit&id=13036

		// tags
		// https://www.thepavlovictoday.com/barbarian/index.php?control=101&place=article&action=inc_clanak_edit&id=13032

		// featured image
		// https://www.thepavlovictoday.com/barbarian/index.php?control=101&place=article&action=inc_clanak_edit&id=13032

		// $b_pages = $wpdb->get_results( "select * from barbarian_page where barbarianpage_pagecode = 'common' ;" );

// author -- https://www.thepavlovictoday.com/barbarian/index.php?control=101&place=article&action=inc_clanak_edit&id=13032
$b_pages = $wpdb->get_results( "select * from barbarian_page where barbarianpage_pagecode = 'common' and barbarianpage_id = 13032 ;", ARRAY_A );
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

			$post_data = [];
			$post_meta = [];

			// Basic content.
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

			// Guest Author.
			$author = $this->filter_array( $b_users, 'id', $b_page[ 'barbarianpage_author' ] );
			if ( null !== $author ) {
				try {
					$ga_data = [
						'display_name' => $author[ 'barbarian_name' ],
						'user_login' => $author[ 'barbarian_username' ],
						'user_email' => $author[ 'barbarianuser_email' ],
						'website' => $author[ 'barbarianuser_url' ],
						'description' => $author[ 'barbarianuser_desc' ],
					];

					if ( $author[ 'barbarianuser_image' ] ) {
						$avatar_attachment_id = $this->attachments_logic->import_external_file( $site_url . $author[ 'barbarianuser_image' ] );
						if ( is_wp_error( $avatar_attachment_id) ) {
							$this->log( self::LOG_GUEST_AUTHOR_AVATAR_ERROR, sprintf( '%s %s %s', $author[ 'barbarian_name' ], $author[ 'barbarianuser_image' ], $avatar_attachment_id->get_error_message() ) );
							WP_CLI::warning( sprintf( '   - error setting GA avatar %s', $avatar_attachment_id->get_error_message() ) );

							continue;
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
					$this->log( self::LOG_GUEST_AUTHOR_HANDLING_ERROR, sprintf( '%S %s', $e->getMessage(), json_encode( $ga_data ) ) );
				}
			}

			// Categories.
			if ( ! empty( $b_page[ 'barbarianpage_navigation_kat' ] ) ) {
				$post_cats = [];

				$b_cat = $this->filter_array( $b_cats, 'barbariancategory_id', $b_page[ 'barbarianpage_navigation_kat' ] );
				if ( null === $b_cat ) {
					continue;
				}

				$term_insert_result = wp_insert_term( $b_cat[ 'barbariancategory_name' ], 'category', [ 'slug' => $b_cat[ 'barbariancategory_link' ], ] );
				if ( is_wp_error( $term_insert_result ) ) {
					$this->log( self::LOG_CAT_CREATE_ERROR, sprintf( '%d %s %s', $post_id, $b_cat[ 'barbariancategory_name' ], $term_insert_result->get_error_message() ) );
					WP_CLI::warning( sprintf( '   - error creating category %s -- %s', $b_cat[ 'barbariancategory_name' ], $term_insert_result->get_error_message() ) );

					continue;
				}

				$cat_id = $term_insert_result[ 'term_id' ];
				WP_CLI::success( sprintf( '   + created Cat %d %s', $cat_id, $b_cat[ 'barbariancategory_name' ] ) );
				$this->log( self::LOG_CAT_CREATED, $cat_id, $b_cat[ 'barbariancategory_name' ] );

				$post_cats[] = $cat_id;

				if ( ! empty( $b_page[ 'barbarianpage_navigation_sub' ] ) ) {
					$b_sub_cat = $this->filter_array( $b_cats, 'barbariancategory_id', $b_page[ 'barbarianpage_navigation_sub' ] );
					if ( null === $b_sub_cat ) {
						continue;
					}

					$term_insert_result = wp_insert_term( $b_sub_cat[ 'barbariancategory_name' ], 'category', [ 'slug' => $b_sub_cat[ 'barbariancategory_link' ], ] );
					if ( is_wp_error( $term_insert_result ) ) {
						$this->log( self::LOG_CAT_CREATE_ERROR, sprintf( '%d %s %s', $post_id, $b_sub_cat[ 'barbariancategory_name' ], $term_insert_result->get_error_message() ) );
						WP_CLI::warning( sprintf( '   - error creating Subcategory %s -- %s', $b_sub_cat[ 'barbariancategory_name' ], $term_insert_result->get_error_message() ) );

						continue;
					}

					$sub_cat_id = $term_insert_result[ 'term_id' ];
					WP_CLI::success( sprintf( '   + created Subcat %d %s', $cat_id, $b_sub_cat[ 'barbariancategory_name' ] ) );
					$this->log( self::LOG_CAT_CREATED, $cat_id, $b_sub_cat[ 'barbariancategory_name' ] );

					$post_cats[] = $sub_cat_id;
				}

				$cats_set = wp_set_post_categories( $post_id, $post_cats, true );
				if ( is_wp_error( $cats_set ) ) {
					$this->log( self::LOG_CAT_SET_ERROR, sprintf( '%d %s %s', $post_id, json_encode( $cats_set ), $cats_set->get_error_message() ) );
					WP_CLI::warning( sprintf( '   - error setting categories %s -- %s', json_encode( $cats_set ), $cats_set->get_error_message() ) );

					continue;
				}

				WP_CLI::success( sprintf( '   + assigned cats %s', implode( ',', $cats_set ) ) );
				$this->log( self::LOG_CAT_ASSIGNED, $post_id, implode( ',', $cats_set ) );
			}

			// Tags.
			if ( ! empty( $b_page[ 'barbarianpage_metakeywords' ] ) ) {
				$b_tags = explode( ',', $b_page[ 'barbarianpage_metakeywords' ] );
				if ( ! empty( $b_tags ) ) {
					$tags_set_result = wp_set_post_tags( $post_id, $b_tags );
					if ( is_wp_error( $tags_set_result ) ) {
						$this->log( self::LOG_TAG_SET_ERROR, sprintf( '%d %s %s', $post_id, json_encode( $tags_set_result ), $tags_set_result->get_error_message() ) );
						WP_CLI::warning( sprintf( '   - error setting tags %s -- %s', json_encode( $tags_set_result ), $tags_set_result->get_error_message() ) );

						continue;
					}

					WP_CLI::success( sprintf( '   + assigned tags %s', implode( ',', $tags_set_result ) ) );
					$this->log( self::LOG_TAG_ASSIGNED, $post_id, implode( ',', $tags_set_result ) );
				}
			}

			// Featured Image.
			if ( ! empty( $b_page[ 'barbarianpage_img' ] ) ) {
				$fetured_image_id = $this->attachments_logic->import_external_file(
					$site_url . $b_page[ 'barbarianpage_img' ],
					$b_page[ 'barbarianpage_imgsignature' ] ?? null
				);
				if ( is_wp_error( $fetured_image_id ) ) {
					$this->log( self::LOG_FEATURED_IMAGE_IMPORT_ERROR, sprintf( '%d %s %s', $post_id, $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id->get_error_message() ) );
					WP_CLI::warning( sprintf( '   - error importing featured image %s -- %s', $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id->get_error_message() ) );

					continue;
				}

				$featured_image_set = set_post_thumbnail( $post_id, $fetured_image_id );
				if ( false == $featured_image_set ) {
					$this->log( self::LOG_ERR_FEATURED_IMAGE_SET, sprintf( '%d %s %s', $post_id, $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id ) );
					WP_CLI::warning( sprintf( '   - error setting featured image %s -- %s', $site_url . $b_page[ 'barbarianpage_img' ], $fetured_image_id ) );

					continue;
				}
				$this->log( self::LOG_FEATURED_IMAGE_SET, sprintf( '%d %d', $post_id, $fetured_image_id ) );
				WP_CLI::success( sprintf( '   + set featured image %d', $fetured_image_id ) );
			}

			// Save Meta.
			$post_meta[ 'barbarianpage_id' ] = $b_page[ 'barbarianpage_id' ];
			foreach ( $post_meta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value);
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
		WP_CLI::warning( 'Remember to:' );
		WP_CLI::warning( '- create the redirect from https://www.thepavlovictoday.com/en/* to https://www.thepavlovictoday.com/*' );
		WP_CLI::warning( '- run the image downloader' );
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
