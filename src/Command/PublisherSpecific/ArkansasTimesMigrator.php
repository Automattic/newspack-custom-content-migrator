<?php
/**
 * Migration tasks for Arkansas Times.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Logic\ContentDiffMigrator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Arkansas Times.
 */
class ArkansasTimesMigrator implements InterfaceCommand {
	/**
	 * CoAuthorPlus.
	 */
	private ContentDiffMigrator $content_diff_migrator;

	/**
	 * Logger.
	 */
	private Logger $logger;

	/**
	 * Posts Logic.
	 */
	private Posts $posts_logic;

	/**
	 * Gutenberg Block Generator Logic.
	 */
	private GutenbergBlockGenerator $gutenberg_block_generator;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->content_diff_migrator     = new ContentDiffMigrator( $wpdb );
		$this->logger                    = new Logger();
		$this->posts_logic               = new Posts();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
	}

	/**
	 * Singleton.
	 *
	 * @return ArkansasTimesMigrator
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Register WP CLI commands.
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator arkansastimes-migrate-issues-from-cpt-to-posts',
			[ $this, 'cmd_migrate_issues_from_cpt_to_posts' ],
			[
				'shortdesc' => 'Migrates Issues from CPT to Regular Posts with Issues Category',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator arkansastimes-migrate-attachments-media-credits',
			[ $this, 'cmd_migrate_attachments_media_credits' ],
			[
				'shortdesc' => 'Migrates Attachments media credits from ACF to Newspack Plugin',
			]
		);

		/**
		 * Content Refresh Fixes
		 */
		WP_CLI::add_command(
			'newspack-content-migrator arkansastimes-content-refresh-cap-terms',
			[ $this, 'cmd_content_refresh_cap_terms' ],
			[
				'shortdesc' => 'Runs a Content Refresh for Co-Authors Plus plugin',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator arkansastimes-content-refresh-cap-posts',
			[ $this, 'cmd_content_refresh_cap_posts' ],
			[
				'shortdesc' => 'Runs a Content Refresh for Posts and Co-Authors Plus plugin',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator arkansastimes-content-refresh-fix-attachments-reference-to-live-db',
			[ $this, 'cmd_content_refresh_fix_attachments_reference_to_live_db' ],
			[
				'shortdesc' => 'Runs a fix for Attachments Reference to live DB after content refresh',
			]
		);
	}

	/** 
	 * Migrates Issues from CPT to Regular Posts with Issues Category.
	 */
	public function cmd_migrate_issues_from_cpt_to_posts(): void {
		// Logs.
		$log = 'arkansastimes-issues-cpt-to-posts.log';
	  
		// Preparations.
		$default_wp_author_user    = get_user_by( 'login', 'adminnewspack' );
		$default_wp_author_user_id = $default_wp_author_user->ID;

		$issue_category_id = $this->maybe_create_issues_category();
		$issues_source_ids = $this->posts_logic->get_all_posts_ids( 'issue' );
		
		// CSV.
		$csv              = sprintf( 'arkansastimes-issues-%s.csv', date( 'Y-m-d H-i-s' ) );
		$csv_file_pointer = fopen( $csv, 'w' );
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Status',
				'Issue Source ID',
				'Issue Source URL',
				'Issue Title',
				'Issue Post ID',
				'Issue URL',
			] 
		);

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Migrating Issues', count( $issues_source_ids ), 1 );

		$this->logger->log( $log, sprintf( 'Start processing posts %s', date( 'Y-m-d H:i:s' ) ) );

		// Migrate Issues to Posts with Issues Category.
		foreach ( $issues_source_ids as $index => $issue_source_id ) {
			$this->logger->log( $log, sprintf( 'Processing Post #%d', $issue_source_id ) );

			$issue_source_post = get_post( $issue_source_id );

			$status = '';

			// 1. Search for a Post with the meta.
			$issue_posts = get_posts(
				[
					'post_type'      => 'post',
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => '_newspack_issue_source_id',
					'meta_value'     => $issue_source_id,
				] 
			);

			if ( count( $issue_posts ) > 0 ) {
				$issue_post = $issue_posts[0];
				$post_id = $issue_post->ID;

				if ( empty( $issue_post->post_content ) ) {
					// 1.1. Post already exists, but content needs population.
					$this->logger->log( $log, 'Post already exists. Updating content...' );

					$status = 'UPDATED';

					$issue_post_content_updated = $this->get_generated_issue_post_content( [
						'title' => get_post_meta( $issue_source_id, 'title', true ),
						'release_date' => get_post_meta( $issue_source_id, 'release_date', true ),
						'volume' => get_post_meta( $issue_source_id, 'issue_volume', true ),
						'number' => get_post_meta( $issue_source_id, 'issue_number', true ),
						'url' => get_post_meta( $issue_source_id, 'digital_edition_url', true ),
					] );

					wp_update_post( [
						'ID' => $post_id,
						'post_content' => $issue_post_content_updated,
					] );
				} else {
					// 1.2. Post already exists.
					$this->logger->log( $log, 'Post already exists. Skipping...' );

					$status = 'EXISTS';
				}
			} else {
				// 1.3. Post doesn't exist.
				$this->logger->log( $log, 'Post doesn\'t exist. Trying to insert...' );

				$post_id = wp_insert_post(
					[
						'post_status'   => 'publish',
						'post_author'   => $default_wp_author_user_id,
						'post_title'    => $issue_source_post->post_title,
						'post_content'  => $issue_source_post->post_content,
						'post_category' => [ $issue_category_id ],
						'meta_input'    => [
							'_newspack_issue_source_id'    => $issue_source_id,
							'_newspack_issue_title'        => get_post_meta( $issue_source_id, 'title', true ),
							'_newspack_issue_release_date' => get_post_meta( $issue_source_id, 'release_date', true ),
							'_newspack_issue_volume'       => get_post_meta( $issue_source_id, 'issue_volume', true ),
							'_newspack_issue_number'       => get_post_meta( $issue_source_id, 'issue_number', true ),
							'_newspack_issue_digital_url'  => get_post_meta( $issue_source_id, 'digital_edition_url', true ),

							'_thumbnail_id'                => get_post_meta( $issue_source_id, '_thumbnail_id', true ),
						],
					],
					true 
				);

				if ( is_wp_error( $post_id ) ) {
					$this->logger->log( $log, sprintf( 'Couldn\'t insert post. Error: %s', $post_id->get_error_message() ) );

					$status = 'ERROR';
				} else {
					$this->logger->log( $log, 'Post inserted' );

					$status = 'CREATED';
				}
			}

			// 2. Populate CSV.
			$this->logger->log( $log, 'Populating CSV' );

			fputcsv(
				$csv_file_pointer,
				[
					$index + 1, // #
					$status, // Status.
					$issue_source_id, // Issue Source ID.
					sprintf( 'https://arktimes.com/wp-admin/post.php?post=%d&action=edit', $issue_source_id ), // Issue Source Edit URL.
					$issue_source_post->post_title, // Issue Title.
					$post_id, // Issue Post ID.
					get_permalink( $post_id ), // Issue New Post ID.
				] 
			);

			$progress_bar->tick( 1, sprintf( '[Memory: %s]', size_format( memory_get_usage( true ) ) ) );
		}

		$progress_bar->finish();

		// Close CSV.
		fclose( $csv_file_pointer );

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );
	}

	/**
	 * Migrates Attachments media credits from ACF to Newspack Plugin
	 */
	public function cmd_migrate_attachments_media_credits() {
		// Logs.
		$log = 'arkansastimes-migrate-attachments-media-credits.log';

		$attachments_ids = $this->posts_logic->get_all_posts_ids( 'attachment' );

		// CSV.
		$csv              = sprintf( 'arkansastimes-attachments-%s.csv', date( 'Y-m-d H-i-s' ) );
		$csv_file_pointer = fopen( $csv, 'w' );
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Attachment ID',
				'Status',
				'Attachment Admin URL',
				'Media Credit 1',
				'Media Credit 1 URL',
				'Media Credit 2',
				'Media Credit 2 URL',
				'Media Credit 3',
				'Media Credit 3 URL',
				'Media Credit 4',
				'Media Credit 4 URL',
				'Media Credit 5',
				'Media Credit 5 URL',
				'Media Credits Value',
				'Media Credits URL Value',
			] 
		);

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Migrating Attachments Media Credits', count( $attachments_ids ), 1 );

		$this->logger->log( $log, sprintf( 'Start processing attachments %s', date( 'Y-m-d H:i:s' ) ) );

		// Migrate Issues to Posts with Issues Category.
		foreach ( $attachments_ids as $index => $attachment_id ) {
			$this->logger->log( $log, sprintf( 'Processing Attachment #%d', $attachment_id ) );

			if ( get_post_meta( $attachment_id, 'newspack_metas_updated_media_credits', true ) === 'yes' ) {
				$progress_bar->tick( 1, sprintf( '[Memory: %s]', size_format( memory_get_usage( true ) ) ) );

				continue;
			}

			$attachment_media_credits_count = get_post_meta( $attachment_id, 'media_credit', true );

			if ( empty( $attachment_media_credits_count ) ) {
				$status = 'Missing Media Credits';

				$this->logger->log( $log, sprintf( 'Attachment doesn\'t have media credits #%d', $attachment_id ) );
			} else {
				$status = 'Updated';

				$this->logger->log( $log, sprintf( 'Attachment has media credits #%d', $attachment_id ) );
			}

			$media_credits      = [];
			$media_credits_urls = [];

			// Replace old meta keys with custom prefix.
			$replacement_map = [
				'media_credit'  => 'acf_media_credit',
				'_media_credit' => '_acf_media_credit',
			];

			// Update replacements map.
			for ( $i = 0; $i < $attachment_media_credits_count; $i++ ) {
				$replacement_map[ 'media_credit_' . $i . '_credit' ]       = 'acf_media_credit_' . $i . '_credit';
				$replacement_map[ '_media_credit_' . $i . '_credit' ]      = '_acf_media_credit_' . $i . '_credit';
				$replacement_map[ 'media_credit_' . $i . '_credit_link' ]  = 'acf_media_credit_' . $i . '_credit_link';
				$replacement_map[ '_media_credit_' . $i . '_credit_link' ] = '_acf_media_credit_' . $i . '_credit_link';

				$media_credits[]      = get_post_meta( $attachment_id, 'media_credit_' . $i . '_credit', true );
				$media_credits_urls[] = get_post_meta( $attachment_id, 'media_credit_' . $i . '_credit_link', true );
			}

			// Update metas.
			foreach ( $replacement_map as $old_meta_key => $new_meta_key ) {
				// Add meta with the updated key.
				add_post_meta( $attachment_id, $new_meta_key, get_post_meta( $attachment_id, $old_meta_key, true ) );

				// Delete the old meta.
				delete_post_meta( $attachment_id, $old_meta_key );
			}

			// Set flag for replaced metas.
			add_post_meta( $attachment_id, 'newspack_metas_replaced', 'yes' );
			
			$this->logger->log( $log, sprintf( 'Store meta keys as legacy #%d', $attachment_id ) );

			// Store the Media Credits.
			if ( $new_media_credits = implode( ' | ', array_unique( array_filter( $media_credits ) ) ) ) {
				add_post_meta( $attachment_id, '_media_credit', $new_media_credits );

				$this->logger->log( $log, sprintf( 'Store media credits #%d', $attachment_id ) );
			}

			if ( $new_media_credits_urls = implode( ' | ', array_unique( array_filter( $media_credits_urls ) ) ) ) {
				add_post_meta( $attachment_id, '_media_credit_url', $new_media_credits_urls );

				$this->logger->log( $log, sprintf( 'Store media credits urls #%d', $attachment_id ) );
			}

			// Set flag for updated Newspack Plugin Media Credits fields.
			add_post_meta( $attachment_id, 'newspack_metas_updated_media_credits', 'yes' );
			
			// 2. Populate CSV.
			$this->logger->log( $log, 'Populating CSV' );
			
			fputcsv(
				$csv_file_pointer,
				[
					$index + 1, // #
					$attachment_id, // Attachment ID.
					$status, // Status.
					admin_url( sprintf( 'upload.php?item=%d', $attachment_id ) ), // Attachment Admin URL.
					get_post_meta( $attachment_id, 'acf_media_credit_0_credit', true ), // Media Credit 1.
					get_post_meta( $attachment_id, 'acf_media_credit_0_credit_link', true ), // Media Credit 1 URL.
					get_post_meta( $attachment_id, 'acf_media_credit_1_credit', true ), // Media Credit 2.
					get_post_meta( $attachment_id, 'acf_media_credit_1_credit_link', true ), // Media Credit 2 URL.
					get_post_meta( $attachment_id, 'acf_media_credit_2_credit', true ), // Media Credit 3.
					get_post_meta( $attachment_id, 'acf_media_credit_2_credit_link', true ), // Media Credit 3 URL.
					get_post_meta( $attachment_id, 'acf_media_credit_3_credit', true ), // Media Credit 4.
					get_post_meta( $attachment_id, 'acf_media_credit_3_credit_link', true ), // Media Credit 4 URL.
					get_post_meta( $attachment_id, 'acf_media_credit_4_credit', true ), // Media Credit 5.
					get_post_meta( $attachment_id, 'acf_media_credit_4_credit_link', true ), // Media Credit 5 URL.
					get_post_meta( $attachment_id, '_media_credit', true ), // Media Credits Value.
					get_post_meta( $attachment_id, '_media_credit_url', true ), // Media Credits URL Value.
				] 
			);

			$progress_bar->tick( 1, sprintf( '[Memory: %s]', size_format( memory_get_usage( true ) ) ) );
		}

		$progress_bar->finish();

		// Close CSV.
		fclose( $csv_file_pointer );

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );
	}

	/**
	 * Content Refreshes the CAP Terms.
	 * 
	 * !IMPORTANT! This command doesn't support Guest Authors, yet..
	 */
	public function cmd_content_refresh_cap_terms( array $args, array $assoc_args ): void {
		global $wpdb;

		$live_table_prefix = $assoc_args['live-table-prefix'];
		$dry_run = $assoc_args['dry-run'] ?? false;

		// Logs.
		$log = sprintf( 'arkansastimes-content-refresh-coauthors-terms-%s.log', date( 'Y-m-d H-i-s' ) );

		// CSV.
		$csv              = sprintf( 'arkansastimes-content-refresh-coauthors-terms-%s.csv', date( 'Y-m-d H-i-s' ) );
		$csv_file_pointer = fopen( $csv, 'w' );
		$index            = 0;
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Name',
				'Slug',
				'Description',
				'Original Term ID',
				'Original Term Taxonomy ID',
				'New Term ID',
				'New Term Taxonomy ID',
			] 
		);

		$this->logger->log( $log, sprintf( '[%s] Start processing Co-Authors', date( 'Y-m-d H:i:s' ) ), false );

		$local_authors = get_terms( [
			'taxonomy' => 'author',
			'hide_empty' => false,
		] );

		$this->logger->log( $log, sprintf( '[%s] Found %d local Co-Authors', date( 'Y-m-d H:i:s' ), count( $local_authors ) ), false );

		$live_authors = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %1$i.`term_id`, `term_taxonomy_id`, `name`, `description`, `slug`, `term_group`, `count`
				FROM %1$i
				INNER JOIN %2$i
				ON %1$i.`term_id` = %2$i.`term_id`
				WHERE %1$i.`taxonomy` = "author"',
				"{$live_table_prefix}term_taxonomy",
				"{$live_table_prefix}terms"
			)
		);

		$this->logger->log( $log, sprintf( '[%s] Found %d live Co-Authors', date( 'Y-m-d H:i:s' ), count( $live_authors ) ), false );

		$progress_bar = WP_CLI\Utils\make_progress_bar( sprintf( '[Memory: %s] Processing Co-Authors', size_format( memory_get_usage( true ) ) ), count( $live_authors ), 1 );

		foreach ( $live_authors as $live_author ) {
			$progress_bar->tick( 1, sprintf( '[Memory: %s] Processing Co-Authors', size_format( memory_get_usage( true ) ) ) );

			foreach ( $local_authors as $local_author ) {
				if (
					$local_author->name === $live_author->name
					&& $local_author->description === $live_author->description
					&& $local_author->slug === $live_author->slug
				) {
					continue 2;
				}
			}

			$this->logger->log( $log, sprintf( '[%s] Found new Co-Author with Name %s', date( 'Y-m-d H:i:s' ), $live_author->name ), false );

			$index++;

			$new_author_local_term = null;

			if ( ! $dry_run ) {
				$new_author_local_term = wp_insert_term(
					$live_author->name,
					'author',
					[
						'description' => $live_author->description,
						'slug' => $live_author->slug,
					]
				);

				add_term_meta( $new_author_local_term['term_id'], 'newspack_source_term_id', $live_author->term_id );
			}

			fputcsv(
				$csv_file_pointer,
				[
					$index,
					$live_author->name,
					$live_author->slug,
					$live_author->description,
					$live_author->term_id,
					$live_author->term_taxonomy_id,
					$new_author_local_term['term_id'] ?? '',
					$new_author_local_term['term_taxonomy_id'] ?? ''
				] 
			);
		}

		$progress_bar->finish();

		// Close CSV.
		fclose( $csv_file_pointer );

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );
	}

	/**
	 * Content Refreshes the CAP data for Posts.
	 * 
	 * !IMPORTANT! This command doesn't support Guest Authors, yet..
	 */
	public function cmd_content_refresh_cap_posts( array $args, array $assoc_args ): void {
		global $wpdb;

		$live_table_prefix = $assoc_args['live-table-prefix'];
		$dry_run = $assoc_args['dry-run'] ?? false;

		$local_authors = get_terms( [
			'taxonomy' => 'author',
			'hide_empty' => false,
		] );

		$local_authors_by_term_id = array_reduce( $local_authors, function ( $carry, $term ) {
			$carry[ $term->term_id ] = $term;

			return $carry;
		}, [] );

		$authors_map = $this->get_cap_authors_live_local_map( $live_table_prefix );

		// Logs.
		$log = sprintf( 'arkansastimes-content-refresh-coauthors-posts-%s.log', date( 'Y-m-d H-i-s' ) );

		// CSV.
		$csv              = sprintf( 'arkansastimes-content-refresh-coauthors-posts-%s.csv', date( 'Y-m-d H-i-s' ) );
		$csv_file_pointer = fopen( $csv, 'w' );
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Post Date',
				'Live Post ID',
				'Local Post ID',
				'Post Title',
				'Live Site URL',
				'Staging Site URL',
				'Live Authors',
				'Local Authors',
				'Missing Authors',
				'Extra Authors',
			] 
		);

		// Get Posts Map (live post id => local post id).
		$posts_map = array_reduce( $wpdb->get_results(
			$wpdb->prepare(
				'SELECT `post_id`, `meta_value` as `live_post_id`
				FROM %1$i
				INNER JOIN %2$i
				ON %1$i.`post_id` = %2$i.`ID`
				WHERE `meta_key` = "newspackcontentdiff_live_id"
				AND `post_type` = "post"
				',
				$wpdb->postmeta,
				$wpdb->posts
			)
		), function ( $carry, $item ) {
			$carry[ $item->live_post_id ] = $item->post_id;

			return $carry;
		}, [] );

		// Get Post to Author Terms Map.
		$live_posts_to_authors_terms_map = $this->get_posts_to_authors_terms_map( $live_table_prefix, array_keys( $posts_map ) );
		$local_posts_to_authors_terms_map = $this->get_posts_to_authors_terms_map( $wpdb->prefix, array_values( $posts_map ) );

		$progress_bar = WP_CLI\Utils\make_progress_bar( sprintf( '[Memory: %s] Processing Posts', size_format( memory_get_usage( true ) ) ), count( array_keys( $posts_map ) ), 1 );

		$index = 0;

		foreach ( $posts_map as $live_post_id => $local_post_id ) {
			$progress_bar->tick( 1, sprintf( '[Memory: %s] Processing Posts', size_format( memory_get_usage( true ) ) ) );

			$live_post_to_local_authors_map = array_map( fn ( $term_id ) => (int) $authors_map[ $term_id ], $live_posts_to_authors_terms_map[ $live_post_id ] );

			$local_post_extra_authors = array_diff( $local_posts_to_authors_terms_map[ $local_post_id ], $live_post_to_local_authors_map );
			$local_post_missing_authors = array_diff( $live_post_to_local_authors_map, $local_posts_to_authors_terms_map[ $local_post_id ] );

			if ( ( count( $local_post_extra_authors ) === 0 ) && ( count( $local_post_missing_authors ) ) === 0 ) {
				continue;
			}

			$index++;

			fputcsv(
				$csv_file_pointer,
				[
					$index,
					get_post_field( 'post_date', $local_post_id ),
					$live_post_id,
					$local_post_id,
					get_the_title( $local_post_id ),
					str_replace( home_url( '/' ), 'https://arktimes.com/', get_permalink( $local_post_id ) ),
					get_permalink( $local_post_id ),
					implode( ',', $live_post_to_local_authors_map ) . ';' . implode( ',', array_map( fn ( $term_id ) => $local_authors_by_term_id[ $term_id ]->name, $live_post_to_local_authors_map ) ),
					implode( ',', $local_posts_to_authors_terms_map[ $local_post_id ] ) . ';' . implode( ',', array_map( fn ( $term_id ) => $local_authors_by_term_id[ $term_id ]->name, $local_posts_to_authors_terms_map[ $local_post_id ] ) ),
					implode( ',', $local_post_missing_authors ) . ';' . implode( ',', array_map( fn ( $term_id ) => $local_authors_by_term_id[ $term_id ]->name, $local_post_missing_authors ) ),
					implode( ',', $local_post_extra_authors ) . ';' . implode( ',', array_map( fn ( $term_id ) => $local_authors_by_term_id[ $term_id ]->name, $local_post_extra_authors ) )
				]
			);

			if ( ! $dry_run ) {
				wp_set_post_terms( $local_post_id, $live_post_to_local_authors_map, 'author' );
			}
		}

		$progress_bar->finish();

		// Close CSV.
		fclose( $csv_file_pointer );

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );
	}

	/**
	 * Fix Attachment referencing to live DB after Content Refresh.
	 */
	public function cmd_content_refresh_fix_attachments_reference_to_live_db( array $args, array $assoc_args ): void {
		$content_refresh_dir = $assoc_args['content-refresh-dir'];

		// Logs.
		$log = sprintf( 'arkansastimes-content-refresh-fix-attachments-%s.log', date( 'Y-m-d H-i-s' ) );

		// CSV.
		$csv              = sprintf( 'arkansastimes-content-refresh-fix-attachments-%s.csv', date( 'Y-m-d H-i-s' ) );
		$csv_file_pointer = fopen( $csv, 'w' );
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Post Date',
				'Live Post ID',
				'Local Post ID',
				'Post Title',
				'Live Site URL',
				'Staging Site URL',
			] 
		);

		$imported_posts_data = $this->get_data_from_log(
			$content_refresh_dir . '/content-diff__imported-post-ids.log',
			[ 'post_type', 'id_old', 'id_new' ]
		) ?? [];

		$posts = array_reduce( $imported_posts_data, function ( $carry, $item ) {
			if ( $item['post_type'] === 'post' ) {
				$carry[ $item['id_old'] ] = $item['id_new'];
			}

			return $carry;
		}, [] );

		$attachments = array_reduce( $imported_posts_data, function ( $carry, $item ) {
			if ( $item['post_type'] === 'attachment' ) {
				$carry[ $item['id_old'] ] = $item['id_new'];
			}

			return $carry;
		}, [] );

		$site_host = parse_url( home_url() );
		$site_host = $site_host['host'];

		$progress_bar = WP_CLI\Utils\make_progress_bar( sprintf( '[Memory: %s] Processing Posts', size_format( memory_get_usage( true ) ) ), count( $posts ), 1 );

		foreach ( $posts as $live_post_id => $post_id ) {
			$progress_bar->tick( 1, sprintf( '[Memory: %s] Processing Posts', size_format( memory_get_usage( true ) ) ) );

			$this->logger->log( $log, sprintf( '[%s] Processing Post %s', date( 'Y-m-d H:i:s' ), $post_id ) );

			$post_content_before = get_post_field( 'post_content', $post_id );
			$post_content_after  = $post_content_before;	

			if ( has_blocks( $post_content_before ) ) {
				$this->logger->log( $log, sprintf( '[%s] Post Contains blocks', date( 'Y-m-d H:i:s' ) ), false );

				$this->content_diff_migrator->update_blocks_ids(
					[
						$post_id
					],
					$attachments,
					[],
					$log
				);

				clean_post_cache( $post_id );

				$post_content_after = get_post_field( 'post_content', $post_id );

				$post_content_blocks = parse_blocks( $post_content_after );

				// Manually fix galleries
				foreach ( $post_content_blocks as $block_index => $block ) {
					if ( $block['blockName'] === 'core/gallery' ) {
						$attachment_ids = array_map( function ( $innerBlock ) {
							return $innerBlock['attrs']['id'];
						}, $block['innerBlocks'] );
					
						$post_content_blocks[ $block_index ] = $this->gutenberg_block_generator->get_gallery(
							$attachment_ids,
							3,
							'full',
							'none',
							true
						);

						$this->logger->log( $log, sprintf( '[%s] Updated Post Gallery Block', date( 'Y-m-d H:i:s' ) ), false );
					} else if ( $block['blockName'] === 'core/image' ) {
						if ( isset( $block['attrs']['id'] ) ) {
							$attachment_id = $block['attrs']['id'];
						} else {
							$this->logger->log( $log, sprintf( '[%s] Issue with Image Block ID. Check manually!!!', date( 'Y-m-d H:i:s' ) ) );

							continue;
						}

						$attachment_post = get_post( $attachment_id );

						if ( ! $attachment_post ) {
							$this->logger->log( $log, sprintf( '[%s] Missing Attachment! Check manually!', date( 'Y-m-d H:i:s' ) ) );

							continue;
						}

						$post_content_blocks[ $block_index ] = $this->gutenberg_block_generator->get_image(
							$attachment_post,
							'full',
							false,
							$block['attrs']['className'] ?? null,
							$block['attrs']['align'] ?? null
						);

						$this->logger->log( $log, sprintf( '[%s] Updated Post Image Block', date( 'Y-m-d H:i:s' ) ), false );
					}
				}

				$post_content_after = serialize_blocks( $post_content_blocks );
			} else if ( strpos( $post_content_before, '[caption' ) !== false ) {
				$this->logger->log( $log, sprintf( '[%s] Post Contains [caption] shortcode', date( 'Y-m-d H:i:s' ) ), false );

				preg_match_all( '~' . get_shortcode_regex( array( 'caption' ) ) . '~', $post_content_before, $caption_shortcode_matches, PREG_SET_ORDER );

				foreach ( $caption_shortcode_matches as $caption_shortcode_match ) {
					preg_match( '~wp\-image\-(\d+)~', $caption_shortcode_match[0], $attachment_id );
					preg_match( '~src=\"(.+)\"~', $caption_shortcode_match[0], $attachment_id );

					$attachment_id = $attachment_id[1];

					$new_attachment_id = isset( $attachments[ $attachment_id ] ) ? $attachments[ $attachment_id ] : $attachment_id;

					$post_content_after = str_replace( 'wp-image-' . $attachment_id, 'wp-image-' . $new_attachment_id, $post_content_after );
					$post_content_after = str_replace( 'attachment_' . $attachment_id, 'attachment_' . $new_attachment_id, $post_content_after );
				}

				$post_content_after = str_replace( 'arktimes.com', $site_host, $post_content_after );

				$this->logger->log( $log, sprintf( '[%s] Updated Post [caption] shortcode src', date( 'Y-m-d H:i:s' ) ), false );
			}

			if ( $post_content_before !== $post_content_after ) {
				wp_update_post( [
					'ID' => $post_id,
					'post_content' => $post_content_after,
				] );
	
				fputcsv(
					$csv_file_pointer,
					[
						$post_id,
						get_post_field( 'post_date', $post_id ),
						$live_post_id,
						$post_id,
						get_the_title( $post_id ),
						'https://arktimes.com/?p=' . $live_post_id,
						get_permalink( $post_id )
					] 
				);
	
				file_put_contents( $post_id . '-before.txt', $post_content_before );
				file_put_contents( $post_id . '-after.txt', $post_content_after );

				$this->logger->log( $log, sprintf( '[%s] Post Updated', date( 'Y-m-d H:i:s' ) ), false );

				$this->logger->log( $log, sprintf( '[%s] Content Before: %s', date( 'Y-m-d H:i:s' ), $post_content_before ), false );
				$this->logger->log( $log, sprintf( '[%s] Content After: %s', date( 'Y-m-d H:i:s' ), $post_content_after ), false );
			}
		}

		// Close CSV.
		fclose( $csv_file_pointer );

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );
	}

	/** 
	 * Check if Issues category exist and creates if it doesn't.
	 * Returns the Category Issues term_id.
	 */
	private function maybe_create_issues_category(): ?int {
		return term_exists( 'Issues', 'category' )
			? get_cat_ID( 'Issues' )
			: wp_create_category( 'Issues' );
	}

	/**
	 * Get a generated Block Pattern for Issue Post Content.
	 */
	private function get_generated_issue_post_content( array $args ): string {
		$issue_sequence = [];

		if ( ! empty( $args['volume'] ) ) {
			$issue_sequence[] = sprintf( 'Volume %s', $args['volume'] );
		}

		if ( ! empty( $args['number'] ) ) {
			$issue_sequence[] = sprintf( 'No %s', $args['number'] );
		}

		ob_start();
		?>

		<!-- wp:group {"layout":{"type":"flex","orientation":"vertical","justifyContent":"center"}} -->
		<div class="wp-block-group">
			<?php if ( $args['title'] ) : ?>
				<!-- wp:heading {"textAlign":"center","level":4} -->
				<h4 class="wp-block-heading has-text-align-center"><?php echo esc_html( $args['title'] ); ?></h4>
				<!-- /wp:heading -->
			<?php endif; ?>
		
			<?php if ( $args['release_date'] ) : ?>
				<!-- wp:paragraph {"align":"center"} -->
				<p class="has-text-align-center"><?php echo date( 'F j, Y', strtotime( $args['release_date'] ) ); ?></p>
				<!-- /wp:paragraph -->
			<?php endif; ?>
		
			<!-- wp:separator -->
			<hr class="wp-block-separator has-alpha-channel-opacity"/>
			<!-- /wp:separator -->
		
			<?php if ( ! empty( $issue_sequence ) ) : ?>
				<!-- wp:heading {"textAlign":"center","level":5} -->
				<h5 class="wp-block-heading has-text-align-center"><?php echo implode( ' » ', $issue_sequence ); ?></h5>
				<!-- /wp:heading -->
			<?php endif; ?>
		
			<?php if ( ! empty( $args['url'] ) ) : ?>
				<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
				<div class="wp-block-buttons">
					<!-- wp:button {"textAlign":"center","backgroundColor":"light-gray","textColor":"medium-gray","style":{"spacing":{"padding":{"left":"16px","right":"16px","top":"8px","bottom":"8px"}},"elements":{"link":{"color":{"text":"var:preset|color|medium-gray"}}}}} -->
					<div class="wp-block-button">
						<a class="wp-block-button__link has-medium-gray-color has-light-gray-background-color has-text-color has-background has-link-color has-text-align-center wp-element-button" href="<?php echo esc_url( $args['url'] ); ?>" target="_blank" style="padding-top:8px;padding-right:16px;padding-bottom:8px;padding-left:16px">Read the print version</a>
					</div>
					<!-- /wp:button -->
				</div>
				<!-- /wp:buttons -->
			<?php endif; ?>
		</div>
		<!-- /wp:group -->

		<?php
		return ob_get_clean();
	}

	/**
	 * Get Map of Live DB CAP Authors Terms and Local DB CAP Authors Terms
	 */
	private function get_cap_authors_live_local_map( $live_table_prefix ): array {
		global $wpdb;

		$authors_map = [];

		$live_authors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT %i.`term_id`, `term_taxonomy_id`, `name`, `description`, `slug`, `term_group`, `count`
				FROM %i
				INNER JOIN %i
				ON %i.`term_id` = %i.`term_id`
				WHERE %i.`taxonomy` = 'author'",
				"{$live_table_prefix}term_taxonomy",
				"{$live_table_prefix}term_taxonomy",
				"{$live_table_prefix}terms",
				"{$live_table_prefix}term_taxonomy",
				"{$live_table_prefix}terms",
				"{$live_table_prefix}term_taxonomy"
			)
		);

		$local_authors = get_terms( [
			'taxonomy' => 'author',
			'hide_empty' => false,
		] );

		foreach ( $live_authors as $live_author ) {
			$authors_map[ $live_author->term_id ] = null;

			foreach ( $local_authors as $local_author ) {
				if (
					$local_author->name === $live_author->name
					&& $local_author->description === $live_author->description
					&& $local_author->slug === $live_author->slug
				) {
					$authors_map[ $live_author->term_id ] = $local_author->term_id;

					continue 2;
				}
			}
		}

		return $authors_map;
	}

	private function get_posts_to_authors_terms_map( $table_prefix, $posts_ids ) {
		global $wpdb;

		if ( empty( $posts_ids ) ) {
			return [];
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT %i.`term_id`, `object_id`
				FROM %i
				INNER JOIN %i
				ON %i.`term_taxonomy_id` = %i.`term_taxonomy_id`
				WHERE `taxonomy` = 'author'
				AND `object_id` IN (" . implode( ',', $posts_ids ) . ")",
				"{$table_prefix}term_taxonomy",
				"{$table_prefix}term_relationships",
				"{$table_prefix}term_taxonomy",
				"{$table_prefix}term_relationships",
				"{$table_prefix}term_taxonomy"
			)
		);

		$map = array_reduce( $results, function ( $carry, $item ) {
			if ( ! isset( $carry[ $item->object_id ] ) ) {
				$carry[ $item->object_id ] = [];
			}

			$carry[ $item->object_id ][] = $item->term_id;

			return $carry;
		}, [] );

		foreach ( $posts_ids as $post_id ) {
			if ( ! isset( $map[ $post_id ] ) ) {
				$map[$post_id] = [];
			}
		}

		return $map;
	}

	/**
	 * Duplicate from ContentDiffMigrator@get_data_from_log
	 */
	/**
	 * Gets data from logs which contain JSON encoded arrays per line.
	 *
	 * @param string $log       Path to log.
	 * @param array  $json_keys Keys to fetch from log lines.
	 *
	 * @return array|null Array with subarray elements with $json_keys keys and values pulled from the log, or null if file can't be found.
	 */
	private function get_data_from_log( $log, $json_keys ) {
		$data = [];

		// Read line by line.
		$handle = fopen( $log, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				// Skip if not JSON data on line.
				$line_decoded = json_decode( $line, true );
				if ( ! is_array( $line_decoded ) ) {
					continue;
				}

				// Get data if line contains these JSON keys.
				$data_key          = count( $data );
				$data[ $data_key ] = [];
				foreach ( $json_keys as $json_key ) {
					if ( isset( $line_decoded[ $json_key ] ) ) {
						$data[ $data_key ] = array_merge( $data[ $data_key ], [ $json_key => $line_decoded[ $json_key ] ] );
					}
				}
			}

			fclose( $handle );
		} else {
			return null;
		}

		return $data;
	}
}
