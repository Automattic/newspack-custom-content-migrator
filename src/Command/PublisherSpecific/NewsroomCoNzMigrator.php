<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;

/**
 * Custom migration scripts for newsroom.co.nz.
 */
class NewsroomCoNzMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $cap_logic;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachment_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->cap_logic = new CoAuthorPlusLogic();
		$this->attachment_logic = new AttachmentsLogic();
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

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator newsroomconz-import-scraper-meta',
			[ $this, 'cmd_import_scraper_meta' ],
			[
				'shortdesc' => 'Imports postmeta by the Scraper plugin.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator newsroomconz-populate-primary-category',
			[ $this, 'cmd_populate_primary_category' ],
			[
				'shortdesc' => 'Populates Yoast Primary category for posts missing it.',
			]
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_scraper_meta( $args, $assoc_args ) {
		if ( ! is_plugin_active( 'co-authors-plus/co-authors-plus.php' ) ) {
			WP_CLI::error( 'The CoAuthorsPlus plugin is required for this command to work. Please install and activate it first.' );
		}

		global $wpdb;

		$posts = $this->posts_logic->get_all_posts( 'post', [ 'publish' ] );
		foreach ( $posts as $key_post => $post ) {

			WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_post + 1, count( $posts ), $post->ID ) );

			// Get all scraper meta.
			$author_bio = get_post_meta( $post->ID, 'newspackscraper_authorbio', true );
			$author_avatar_src = get_post_meta( $post->ID, 'newspackscraper_authoravatarsrc', true );
			$feat_img_caption = get_post_meta( $post->ID, 'newspackscraper_featimgcaption', true );
			$original_url = get_post_meta( $post->ID, 'newspackscraper_url', true );

			// Create GA with bio and avatar, link w/ WP User Author, and assign to Post.
			if ( $author_bio || $author_avatar_src ) {
				$author_name = get_the_author_meta( 'display_name', $post->post_author );
				$author_wp_user = get_user_by( 'id', $post->post_author );
				$ga_existing = $this->cap_logic->get_guest_author_by_user_login( $author_wp_user->user_nicename );
				if ( $ga_existing ) {
					$ga_id = $ga_existing->ID;
				} else {
					$avatar_att_id = $this->attachment_logic->import_external_file( $author_avatar_src );
					$ga_id = $this->cap_logic->create_guest_author( [
						'display_name' => $author_name,
						'description' => $author_bio,
						'avatar' => $avatar_att_id,
					] );
				}
				$this->cap_logic->link_guest_author_to_wp_user( $ga_id, $author_wp_user );
				$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post->ID );
			} else {
				// TODO debug
				$d=1;
			}

			// Feat img caption.
			if ( $feat_img_caption ) {
				$post_thumbnail_id = get_post_thumbnail_id( $post );
				$wpdb->update(
					$wpdb->posts,
					[ 'post_excerpt' => $feat_img_caption ],
					[ 'ID' => $post_thumbnail_id ]
				);
			}

			// Update categories.
			$cat_urls_to_cat_names = [
				'newsroom.co.nz/news' => 'News',
				'newsroom.co.nz/politics' => 'Politics',
				'newsroom.co.nz/covid-19' => 'Covid-19',
				'newsroom.co.nz/environment' => 'Environment',
				'newsroom.co.nz/business' => 'Business',
				'newsroom.co.nz/comment' => 'Comment',
				'newsroom.co.nz/technology' => 'Technology',
				'newsroom.co.nz/new-auckland' => 'Auckland',
				'newsroom.co.nz/health--science' => 'Health & Science',
				'newsroom.co.nz/podcasts' => 'Podcasts',
			];
			foreach ( $cat_urls_to_cat_names as $cat_url => $cat_name ) {
				if ( true === str_contains( $original_url, $cat_url ) ) {
					$parent_category_name = $cat_name;
					$current_categories = wp_get_post_categories( $post->ID );
					$current_child_category_id = $current_categories[0];
					$current_child_category = get_category( $current_child_category_id );
					$child_category_name = $current_child_category->name;

					$parent_category_id = wp_create_category( $parent_category_name, 0 );
					$child_category_id = wp_create_category( $child_category_name, $parent_category_id );

					wp_set_post_categories( $post->ID, [ $parent_category_id, $child_category_id ], false );
				}
			}

			// Update permalink.
			$original_url_parsed = parse_url( $original_url );
			$path = $original_url_parsed[ 'path' ];
			$path = ltrim( $path, '/' );
			$no_slashes = substr_count( $original_url_parsed[ 'path' ], '/' );
			if ( 2 == $no_slashes ) {
				$path_exploded = explode( '/', $path );
				$slug = $path_exploded[1];
				$wpdb->update(
					$wpdb->posts,
					[ 'post_name' => $slug ],
					[ 'ID' => $post->ID ]
				);
			} else {
				// TODO debug
				$d=1;
			}

			// Delete the scraper postmeta.
			// delete_post_meta( $post->ID, 'newspackscraper_authorbio' );
			// delete_post_meta( $post->ID, 'newspackscraper_authoravatarsrc' );
			// delete_post_meta( $post->ID, 'newspackscraper_featimgcaption' );
			// delete_post_meta( $post->ID, 'newspackscraper_url' );
		}

		// Clean up empty categories.

		// Required for the $wpdb->update() sink in.
		wp_cache_flush();
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_populate_primary_category( $args, $assoc_args ) {
		global $wpdb;
		$meta_key = '_yoast_wpseo_primary_category';
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$posts_without_primary = $wpdb->get_col(
			$wpdb->prepare( "select ID from $wpdb->posts where post_status = 'publish' and ID NOT IN( SELECT post_id from wp_postmeta where meta_key = %s )", $meta_key )
		);

		WP_CLI::log( count($posts_without_primary) . ' posts without primary category.' );
		$count = 0;

		foreach ( $posts_without_primary as $post_id ) {
			$categories = get_the_category( $post_id );
				
			// This only applies for posts with more than one category.
			if ( count( $categories ) < 2 ) {
				WP_CLI::log( 'Skipping post ' . $post_id . ' with ' . count( $categories ) . ' categories.' );
				continue;
			}

			WP_CLI::log( ' ==== Processing post ' . $post_id . ' ====' );

			$primary_category = 999999999;

			// Let's set the category with the lowest ID as the primary.
			foreach ( $categories as $cat ) {
				WP_CLI::log( 'Found category ' . $cat->term_id );
				if ( $cat->term_id < $primary_category ) {
					$primary_category = $cat->term_id;
				}
			}

			WP_CLI::log( 'Chosen primary category: ' . $primary_category );
			if ( ! $dry_run ) {
				update_post_meta( $post_id, $meta_key, $primary_category );
				WP_CLI::success( 'Updated post ' . $post_id . ' with primary category ' . $primary_category );
			}
			$count ++;
		}
		if ( ! $dry_run ) {
			WP_CLI::success( 'Done!. Updated ' . $count . ' posts.' );
		} else {
			WP_CLI::success( 'Done!. Would have updated ' . $count . ' posts.' );
		}
	}
}
