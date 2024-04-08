<?php
/**
 * Migration tasks for Arkansas Times.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
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
	 * Check if Issues category exist and creates if it doesn't.
	 * Returns the Category Issues term_id.
	 */
	private function maybe_create_issues_category(): ?int {
		return term_exists( 'Issues', 'category' )
			? get_cat_ID( 'Issues' )
			: wp_create_category( 'Issues' );
	}
}
