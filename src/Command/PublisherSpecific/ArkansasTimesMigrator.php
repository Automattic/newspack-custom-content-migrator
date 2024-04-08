<?php
/**
 * Migration tasks for Arkansas Times.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Arkansas Times.
 */
class ArkansasTimesMigrator implements InterfaceCommand {
	/**
	 * Logger.
	 */
	private Logger $logger;

	/**
	 * Posts Logic.
	 */
	private Posts $posts_logic;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->logger      = new Logger();
		$this->posts_logic = new Posts();
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

		$this->logger->log( $log, sprintf( 'Start processing posts %s', date( 'Y-m-d H:I:s' ) ) );

		// Migrate Issues to Posts with Issues Category.
		foreach ( $issues_source_ids as $index => $issue_source_id ) {
			$this->logger->log( $log, sprintf( 'Processing Post #%d', $issue_source_id ) );

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
				// 1.1. Post already exists.
				$this->logger->log( $log, 'Post already exists. Skipping...' );

				$status = 'EXISTS';

				$post_id = $issue_posts[0]->ID;
			} else {
				// 1.2. Post doesn't exist.
				$this->logger->log( $log, 'Post doesn\'t exist. Trying to insert...' );

				$issue_source_post = get_post( $issue_source_id );

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

							'_thumbnail_id'                => get_post_meta( $issue_source_id, 'cover_image', true ),
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

		$this->logger->log( $log, sprintf( 'Start processing attachments %s', date( 'Y-m-d H:I:s' ) ) );

		// Migrate Issues to Posts with Issues Category.
		foreach ( $attachments_ids as $index => $attachment_id ) {
			$this->logger->log( $log, sprintf( 'Processing Attachment #%d', $attachment_id ) );

			if ( get_post_meta( $attachment_id, 'newspack_metas_updated_media_credits', true ) === 'yes' ) {
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
	 * Check if Issues category exist and creates if it doesn't.
	 * Returns the Category Issues term_id.
	 */
	private function maybe_create_issues_category(): ?int {
		return term_exists( 'Issues', 'category' )
			? get_cat_ID( 'Issues' )
			: wp_create_category( 'Issues' );
	}
}
