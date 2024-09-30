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
	 * PIJF IDs to categories mapping.
	 *
	 * @var array
	 */
	private $pijf_category_mapping = [
		'pijf-0011' => 'PIJF: The Detail',
		'pijf_0011' => 'PIJF: The Detail',
		'pijf-0033' => 'PIJF: South PIJF',
		'pijf-0053' => 'PIJF: Maori Ed PIJF',
		'pijf-0058' => 'PIJF: PIJF Training',
		'pijf-0070' => 'PIJF: Investigates 2022',
		'pijf-0077' => 'PIJF: Climate Change PIJF series',
		'pijf-0090' => 'PIJF PIJF: Video Content Prod',
		'pijf-0093' => 'PIJF: Tova Today FM Project',
	];

	/**
	 * Category ID for the parent "PIJF" category.
	 *
	 * @var int
	 */
	private $pijf_parent_category_id;

	/**
	 * PIJF category IDs.
	 *
	 * @var array
	 */
	private $pijf_category_ids = [];

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic      = new PostsLogic();
		$this->cap_logic        = new CoAuthorPlusLogic();
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
			self::$instance = new $class();
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
		WP_CLI::add_command(
			'newspack-content-migrator newsroomconz-migrate-subtitles',
			[ $this, 'cmd_migrate_subtitles' ],
			[
				'shortdesc' => 'Migrate subtitles from post content to meta.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Whether to do a dry run.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator newsroomconz-handle-pijf',
			[ $this, 'cmd_handle_pijf' ],
			[
				'shortdesc' => 'Convert PIJF tags to category assignments.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Whether to do a dry run.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
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

			WP_CLI::line( sprintf( '(%d)/(%d) %d', $key_post + 1, count( $posts ), $post->ID ) );

			// Get all scraper meta.
			$author_bio        = get_post_meta( $post->ID, 'newspackscraper_authorbio', true );
			$author_avatar_src = get_post_meta( $post->ID, 'newspackscraper_authoravatarsrc', true );
			$feat_img_caption  = get_post_meta( $post->ID, 'newspackscraper_featimgcaption', true );
			$original_url      = get_post_meta( $post->ID, 'newspackscraper_url', true );

			// Create GA with bio and avatar, link w/ WP User Author, and assign to Post.
			if ( $author_bio || $author_avatar_src ) {
				$author_name    = get_the_author_meta( 'display_name', $post->post_author );
				$author_wp_user = get_user_by( 'id', $post->post_author );
				$ga_existing    = $this->cap_logic->get_guest_author_by_user_login( $author_wp_user->user_nicename );
				if ( $ga_existing ) {
					$ga_id = $ga_existing->ID;
				} else {
					$avatar_att_id = $this->attachment_logic->import_external_file( $author_avatar_src );
					$ga_id         = $this->cap_logic->create_guest_author(
						[
							'display_name' => $author_name,
							'description'  => $author_bio,
							'avatar'       => $avatar_att_id,
						]
					);
				}
				$this->cap_logic->link_guest_author_to_wp_user( $ga_id, $author_wp_user );
				$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post->ID );
			} else {
				// TODO debug
				$d = 1;
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
				'newsroom.co.nz/news'            => 'News',
				'newsroom.co.nz/politics'        => 'Politics',
				'newsroom.co.nz/covid-19'        => 'Covid-19',
				'newsroom.co.nz/environment'     => 'Environment',
				'newsroom.co.nz/business'        => 'Business',
				'newsroom.co.nz/comment'         => 'Comment',
				'newsroom.co.nz/technology'      => 'Technology',
				'newsroom.co.nz/new-auckland'    => 'Auckland',
				'newsroom.co.nz/health--science' => 'Health & Science',
				'newsroom.co.nz/podcasts'        => 'Podcasts',
			];
			foreach ( $cat_urls_to_cat_names as $cat_url => $cat_name ) {
				if ( true === str_contains( $original_url, $cat_url ) ) {
					$parent_category_name      = $cat_name;
					$current_categories        = wp_get_post_categories( $post->ID );
					$current_child_category_id = $current_categories[0];
					$current_child_category    = get_category( $current_child_category_id );
					$child_category_name       = $current_child_category->name;

					$parent_category_id = wp_create_category( $parent_category_name, 0 );
					$child_category_id  = wp_create_category( $child_category_name, $parent_category_id );

					wp_set_post_categories( $post->ID, [ $parent_category_id, $child_category_id ], false );
				}
			}

			// Update permalink.
			$original_url_parsed = parse_url( $original_url );
			$path                = $original_url_parsed['path'];
			$path                = ltrim( $path, '/' );
			$no_slashes          = substr_count( $original_url_parsed['path'], '/' );
			if ( 2 == $no_slashes ) {
				$path_exploded = explode( '/', $path );
				$slug          = $path_exploded[1];
				$wpdb->update(
					$wpdb->posts,
					[ 'post_name' => $slug ],
					[ 'ID' => $post->ID ]
				);
			} else {
				// TODO debug
				$d = 1;
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
		$dry_run  = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$posts_without_primary = $wpdb->get_col(
			$wpdb->prepare( "select ID from $wpdb->posts where post_status = 'publish' and ID NOT IN( SELECT post_id from wp_postmeta where meta_key = %s )", $meta_key )
		);

		WP_CLI::log( count( $posts_without_primary ) . ' posts without primary category.' );
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

	/**
	 * Migrate the subtitles.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function cmd_migrate_subtitles( $args, $assoc_args ) {
		$is_dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		if ( $is_dry_run ) {
			\WP_CLI::log( "\n===================\n=     Dry Run     =\n===================\n" );
		}

		\WP_CLI::log( "Migrating subtitles from post content...\n\n" );

		// Add a filter to query posts by post_content.
		\add_filter( 'posts_where', [ $this, 'filter_posts_where' ] );

		$post_ids = \get_posts(
			[
				'post_type'        => 'post',
				'posts_per_page'   => -1,
				'post_status'      => 'any',
				'suppress_filters' => false,
				'fields'           => 'ids',
			]
		);

		if ( empty( $post_ids ) ) {
			\WP_CLI::success( 'Done! No posts found.' );
		}

		$processed    = 0;
		$memory_usage = memory_get_usage( false );
		foreach ( $post_ids as $post_id ) {
			$memory_usage = memory_get_usage( false );
			if ( $memory_usage > 966367641 ) { // 0.9 GB, since the limit on Atomic is 1GB.
				\WP_CLI::warning( 'Exit due to memory usage.' );
				exit( 1 );
			}

			$migrated = $this->migrate_subtitle( $post_id, $is_dry_run );
			if ( $migrated ) {
				$processed ++;
			}
		}

		\WP_CLI::success( sprintf( 'Done! %d post%s processed.', $processed, 1 < $processed ? 's' : '' ) );
	}

	/**
	 * Only query posts that have a bolded first paragraph.
	 *
	 * @param string $where The WHERE clause of the query.
	 *
	 * @return string The modified WHERE clause.
	 */
	public function filter_posts_where( $where ) {
		global $wpdb;

		$where .= " AND post_content REGEXP '^[[:space:]]*<!--[[:space:]]*wp:paragraph[[:space:]]*-->[[:space:]]*<p>(<strong><em>|<em><strong>|<b><em>|<em><b>|<b><i>|<i><b>|<strong><i>|<i><strong>)'";

		// Only run once.
		\remove_filter( 'posts_where', [ $this, 'filter_posts_where' ] );

		return $where;
	}

	/**
	 * Migrate the subtitle for a post. Only migrate if the post doesn't already have a subtitle meta value.
	 *
	 * @param int  $post_id The post ID.
	 * @param bool $is_dry_run Whether to do a dry run.
	 * @return bool Whether the subtitle was migrated.
	 */
	public function migrate_subtitle( $post_id, $is_dry_run = false ) {
		$existing_subtitle = \get_post_meta( $post_id, 'newspack_post_subtitle', true );
		$migrated          = false;

		if ( ! empty( $existing_subtitle ) ) {
			\WP_CLI::warning( sprintf( 'Post %d already has a subtitle: %s. Skipping.', $post_id, $existing_subtitle ) );
			return $migrated;
		}

		$blocks         = \parse_blocks( \get_post( $post_id )->post_content );
		$subtitle_block = array_shift( $blocks );
		$subtitle       = \wp_strip_all_tags( $subtitle_block['innerHTML'] );
		$migrated       = ! $is_dry_run ? \update_post_meta( $post_id, 'newspack_post_subtitle', $subtitle ) : true;

		// Remove the subtitle block from the post content.
		if ( $migrated && ! $is_dry_run ) {
			$new_content = \serialize_blocks( $blocks );
			\wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $new_content,
				]
			);
		}

		// Error.
		if ( ! $migrated && ! $is_dry_run ) {
			\WP_CLI::warning( sprintf( 'Subtitle not migrated for post %d.', $post_id ) );
			return $migrated;
		}

		\WP_CLI::log( sprintf( 'Subtitle %smigrated for post %d with subtitle: "%s"' . "\n", $is_dry_run ? 'would be ' : '', $post_id, $subtitle ) );

		return $migrated;
	}

	/**
	 * Find posts with a PIJF tag in migration data and assign a corresponding category.
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function cmd_handle_pijf( $args, $assoc_args ) {
		$is_dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		if ( $is_dry_run ) {
			\WP_CLI::log( "\n===================\n=     Dry Run     =\n===================\n" );
		}

		\WP_CLI::log( "Fetching posts with PIJF tags...\n\n" );

		$post_ids = \get_posts(
			[
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'OR',
					[
						'key'     => 'newspack_nnz_import_data',
						'value'   => 'pijf_00',
						'compare' => 'LIKE',
					],
					[
						'key'     => 'newspack_nnz_import_data',
						'value'   => 'pijf-00',
						'compare' => 'LIKE',
					],
				],
			]
		);

		if ( empty( $post_ids ) ) {
			return \WP_CLI::success( 'Done! No posts found.' );
		}

		$processed    = 0;
		$memory_usage = memory_get_usage( false );
		foreach ( $post_ids as $post_id ) {
			$memory_usage = memory_get_usage( false );
			if ( $memory_usage > 966367641 / 2 ) { // 0.45 GB, since the limit on Atomic is 512 MB.
				\WP_CLI::warning( 'Exit due to memory usage.' );
				exit( 1 );
			}

			$migrated = $this->convert_pijf( $post_id, $is_dry_run );
			if ( $migrated ) {
				$processed ++;
			}
		}

		\WP_CLI::success( sprintf( 'Done! %d post%s processed.', $processed, 1 < $processed ? 's' : '' ) );
	}

	/**
	 * Convert PIJF tag to corresponding category assignment.
	 *
	 * @param int  $post_id The post ID.
	 * @param bool $is_dry_run Whether to do a dry run.
	 * @return bool Whether the subtitle was migrated.
	 */
	public function convert_pijf( $post_id, $is_dry_run = false ) {
		// Cache term ID of PIJF parent category.
		if ( empty( $this->pijf_parent_category_id ) ) {
			$pijf_parent_category = \get_term_by( 'name', 'PIJF', 'category' );

			if ( ! empty( $pijf_parent_category->term_id ) ) {
				$this->pijf_parent_category_id = $pijf_parent_category->term_id;
			}
		}

		// Cache term IDs for PIJF categories.
		if ( empty( $this->pijf_category_ids ) ) {
			foreach ( $this->pijf_category_mapping as $pijf_id => $category_name ) {
				$pijf_category = \get_term_by( 'name', $category_name, 'category' );

				if ( ! empty( $pijf_category->term_id ) ) {
					$this->pijf_category_ids[ $pijf_id ] = $pijf_category->term_id;
				}
			}
		}

		// Get PIJF tag from post meta.
		$import_data = \get_post_meta( $post_id, 'newspack_nnz_import_data', true );
		$migrated    = false;
		$pijf_id     = null;
		$term_id     = null;

		if ( isset( $import_data['extended_attribs']['pijf'] ) ) {
			$pijf_id = $import_data['extended_attribs']['pijf'];

			if ( empty( $pijf_id ) ) {
				return $migrated;
			}

			if ( ! empty( $this->pijf_category_ids[ $pijf_id ] ) ) {
				$term_id = $this->pijf_category_ids[ $pijf_id ];
			}

			if ( empty( $term_id ) ) {
				\WP_CLI::warning( sprintf( 'Post ID %d not processed. Category not found for %s.', $post_id, $pijf_id ) );
				return $migrated;
			}

			$migrated = ! $is_dry_run ? \wp_set_post_categories( $post_id, [ $this->pijf_parent_category_id, $term_id ], true ) : true;
		} else {
			return $migrated;
		}

		// Error.
		if ( ! $migrated ) {
			\WP_CLI::warning( sprintf( 'Could not process post with ID %d.', $post_id ) );
			return $migrated;
		}

		\WP_CLI::log( sprintf( 'Category "%s" %sapplied to post %d.' . "\n", $this->pijf_category_mapping[ $pijf_id ], $is_dry_run ? 'would be ' : '', $post_id ) );

		return $migrated;
	}
}
