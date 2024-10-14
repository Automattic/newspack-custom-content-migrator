<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use Exception;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use Newspack\MigrationTools\Logic\GutenbergBlockGenerator;
use Newspack\MigrationTools\Util\MigrationMeta;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use NewspackCustomContentMigrator\Utils\JsonIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

class HighCountryNewsMigrator implements InterfaceCommand {

	/**
	 * HighCountryNewsMigrator Instance.
	 *
	 * @var HighCountryNewsMigrator
	 */
	private static $instance;

	/**
	 * @var CoAuthorsPlusHelper $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Redirection $redirection
	 */
	private $redirection;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * @var JsonIterator
	 */
	private $json_iterator;

	/**
	 * @var RedirectionLogic
	 */
	private $redirection_logic;

	/**
	 * Instance of Attachments Login
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * Get Instance.
	 *
	 * @return HighCountryNewsMigrator
	 */
	public static function get_instance(): HighCountryNewsMigrator {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance                            = new $class();
			self::$instance->coauthorsplus_logic       = new CoAuthorsPlusHelper();
			self::$instance->redirection               = new Redirection();
			self::$instance->logger                    = new Logger();
			self::$instance->gutenberg_block_generator = new GutenbergBlockGenerator();
			self::$instance->attachments               = new Attachments();
			self::$instance->json_iterator             = new JsonIterator();
			self::$instance->redirection_logic         = new RedirectionLogic();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		$articles_json_arg = [
			'type'        => 'assoc',
			'name'        => 'articles-json',
			'description' => 'Path to the articles JSON file.',
			'optional'    => false,
		];
		$issues_json_arg   = [
			'type'        => 'assoc',
			'name'        => 'issues-json',
			'description' => 'Path to the Issues JSON file.',
			'optional'    => false,
		];


		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-authors-from-scrape',
			[ $this, 'cmd_migrate_authors_from_scrape' ],
			[
				'shortdesc' => 'Authors will not be properly linked after importing XMLs. This script will set authors based on saved postmeta.',
			]
		);

		// Need to import Authors/Users
		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-users-from-json',
			[ $this, 'cmd_migrate_users_from_json' ],
			[
				'shortdesc' => 'Migrate users from JSON data.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		// Then images
		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-images-from-json',
			[ $this, 'cmd_migrate_images_from_json' ],
			[
				'shortdesc' => 'Migrate images from JSON data.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-issues-from-json',
			[ $this, 'cmd_migrate_issues_from_json' ],
			[
				'shortdesc' => 'Migrate issues from JSON data.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		// Then tags, Topics?
		// Then posts

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-migrate-articles-from-json',
			[ $this, 'cmd_migrate_articles_from_json' ],
			[
				'shortdesc' => 'Migrate JSON data from the old site.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-fix-weird-chars',
			[ $this, 'cmd_fix_weird_chars' ],
			[
				'shortdesc' => 'Fix weird chars in post content.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-set-issues-as-categories',
			[ $this, 'add_issues_as_categories' ],
			[
				'shortdesc' => 'Process article json and set Issue categories.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start',
						'description' => 'Start row (default: 0)',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end',
						'description' => 'End row (default: PHP_INT_MAX)',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-fix-related-link-text',
			[ $this, 'fix_related_link_text' ],
			[
				'shortdesc' => 'Copies ACF subtitles to post_excerpt and .',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'Path to the JSON file.',
						'optional'    => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-categories',
			array( $this, 'hcn_fix_categories' ),
			array(
				'shortdesc' => 'Fix posts categories and tags.',
				'synopsis'  => array(
					[
						$articles_json_arg,
					],
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-generate-redirects-csv',
			array( $this, 'hcn_generate_redirects_csv' ),
			array(
				'shortdesc' => 'Generate redirects CSV file.',
				'synopsis'  => array(
					[
						$articles_json_arg,
					],
					[
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Path to the output directory.',
						'optional'    => false,
					],
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-generate-redirects',
			array( $this, 'hcn_generate_redirects' ),
			array(
				'shortdesc' => 'Generate redirects.',
				'synopsis'  => array(
					[
						$articles_json_arg,
						],
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-headlines',
			array( $this, 'hcn_migrate_headlines' ),
			array(
				'shortdesc' => 'Migrate Headlines.',
				'synopsis'  => array(
					[
						$articles_json_arg,
					],
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-related-stories',
			array( $this, 'hcn_migrate_related_stories' ),
			array(
				'shortdesc' => 'Migrate related stories.',
				'synopsis'  => array(
					[
						$articles_json_arg,
					],
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-set-featured-image-postition-for-featured-posts',
			array( $this, 'hcn_set_featured_image_postition_for_featured_posts' ),
			array(
				'shortdesc' => 'Set featured image position for featured posts.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'feature-category-id',
						'description' => 'Feature category ID.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-turn-on-images-captions-and-credits',
			array( $this, 'hcn_turn_on_images_captions_and_credits' ),
			array(
				'shortdesc' => 'Turn on images captions and credits.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-copy-subhead-to-excerpt',
			array( $this, 'hcn_copy_subhead_to_excerpt' ),
			array(
				'shortdesc' => 'Migrate Headlines.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-issues-meta-data',
			array( $this, 'hcn_migrate_issues_meta_data' ),
			array(
				'shortdesc' => 'Migrate Issues meta data.',
				'synopsis'  => array(
					$issues_json_arg,
					[
						'type'        => 'assoc',
						'name'        => 'blobs-folder-path',
						'description' => 'Path to the blobs folder.',
						'optional'    => false,
					],
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-issues-categories-slug',
			array( $this, 'hcn_fix_issues_categories_slug' ),
			array(
				'shortdesc' => 'Fix Issues categories slug.',
				'synopsis'  => [
						$issues_json_arg,
				],
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-delete-subscribers',
			array( $this, 'hcn_delete_subscriber' ),
			array(
				'shortdesc' => 'Deletes all users.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Bath to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'users-per-batch',
						'description' => 'users to import per batch',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-delete-weird-categories',
			array( $this, 'delete_weird_categories' ),
			array(
				'shortdesc' => 'Deletes categories that were really articles.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				],
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-import-issues-as-pages',
			[ $this, 'import_issues_as_pages' ],
			[
				'synopsis' => [
					$issues_json_arg,
					$articles_json_arg,
					[
						'type'        => 'assoc',
						'name'        => 'blobs-folder-path',
						'description' => 'Path to the blobs folder.',
						'optional'    => false,
					],
				],
				'shortdesc' => 'Import issues as pages and clean up issue categories.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator highcountrynews-delete-download-entire-issue-posts',
			[ $this, 'delete_download_entire_issue_posts' ],
			[
				'synopsis'  => [
					$articles_json_arg,
				],
				'shortdesc' => 'Delete all the "Download entire issue" posts that are obsolete because we now have pages for issues that include the download.',
			]
		);

	}

	/**
	 * There were a bunch of posts that are now obsolete because we have pages for issues that include the download.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return void
	 * @throws WP_CLI\ExitException
	 */
	public function delete_download_entire_issue_posts( array $args, array $assoc_args ): void {
		$uids_to_delete          = [];
		$articles_json_file_path = $assoc_args['articles-json'];
		$jq_query                = <<<QUERY
cat $articles_json_file_path | jq '. [] | select(."id"|test("download-entire-issue")) | .UID' | jq -rs @csv
QUERY;
		exec( $jq_query, $output_array );
		if ( ! empty( $output_array[0] ) ) {
			$uids_to_delete = str_getcsv( $output_array[0] );
		}
		if ( empty( $uids_to_delete ) ) {
			WP_CLI::error( 'No posts to delete.' );
		}

		$counter = 0;
		$total   = count( $uids_to_delete );
		foreach ( $uids_to_delete as $uid ) {
			WP_CLI::log( sprintf( 'Processing (%d/%d): %s', ++ $counter, $total, $uid ) );

			$post_id = $this->get_post_id_from_uid( $uid );
			if ( empty( $post_id ) ) {
				WP_CLI::warning( sprintf( 'Could not find post for %s', $uid ) );
				continue;
			}
			wp_delete_post( $post_id );
		}
	}

	/**
	 * Get articles that have a pdf url.
	 *
	 * @param string $articles_json_file_path Path to the articles JSON file.
	 *
	 * @return array Array with UID as key and pdf url as value.
	 */
	private function get_pdf_urls_from_json( string $articles_json_file_path ): array {
		$pdfurls_array = [];
		$jq_query      = <<<QUERY
cat $articles_json_file_path | jq '. [] | select(."pdfurl"|test("pdf$")) | {UID: .parent.UID, pdfurl}' | jq -s 
QUERY;
		exec( $jq_query, $output_array );
		if ( ! empty( $output_array[0] ) ) {
			$json = json_decode( implode( '', $output_array ) );
			foreach ( (array) $json as $arr ) {
				$pdfurls_array[ $arr->UID ] = $arr->pdfurl;
			}
		}

		return $pdfurls_array;
	}

	/**
	 * Import issues into pages and update issue categories.
	 *
	 * Note that this command can be run over and over. Will only process issues not yet processed. It
	 * can also be run again from scratch with no duplicates – just update the $command_meta_version number.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @return void
	 * @throws WP_CLI\ExitException
	 * @throws \stringEncode\Exception
	 */
	public function import_issues_as_pages( array $args, array $assoc_args ): void {

		$command_meta_key     = 'import_issues_as_pages';
		$command_meta_version = 2;
		$log_file             = "{$command_meta_key}_{$command_meta_version}.log";

		$pdfurls_array = $this->get_pdf_urls_from_json( $assoc_args['articles-json'] );
		if ( empty( $pdfurls_array ) ) {
			WP_CLI::error( sprintf( 'Could not find any PDF urls in %s', $assoc_args['articles-json'] ) );
		}

		global $wpdb;
		$blobs_folder               = rtrim( $assoc_args['blobs-folder-path'], '/' ) . '/';
		$issues_category_id         = $this->get_or_create_category( 'Issues' );
		$issues_parent_page_post_id = 180504; // The page that all issues will have as parent.
		$default_author             = 223746; // User ID of default author.
		$the_magazine_tag_id        = 7640; // All issues will have this tag to create a neat looking page at /topic/the-magazine.

		$total_issues = $this->json_iterator->count_json_array_entries( $assoc_args['issues-json'] );
		$counter      = 0;

		foreach ( $this->json_iterator->items( $assoc_args['issues-json'] ) as $issue ) {
			WP_CLI::log( sprintf( 'Processing issue (%d of %d): %s', ++ $counter, $total_issues, $issue->{'@id'} ) );

			$slug       = substr( $issue->{'@id'}, strrpos( $issue->{'@id'}, '/' ) + 1 );
			$post_date  = new DateTime( ( $issue->effective ?? $issue->created ), new DateTimeZone( 'America/Denver' ) );
			$issue_name = $post_date->format( 'F j, Y' ) . ': ' . $issue->title;

			$cat = get_category_by_slug( $slug );
			if ( ! $cat ) {
				$id = wp_insert_category( [
					'cat_name'          => $slug,
					'category_nicename' => $issue_name,
					'category_parent'   => $issues_category_id
				] );

				$cat = get_term( $id, 'category' );
			}
			if ( ! $cat instanceof \WP_Term ) {
				$this->logger->log( $log_file, sprintf( 'Could not find or create category for %s', $issue->{'@id'} ), Logger::ERROR );
				continue;
			}
			update_term_meta( $cat->term_id, 'plone_issue_UID', $issue->id );


			if ( MigrationMeta::get( $cat->term_id, $command_meta_key, 'term' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_term_link( $cat->term_id ), $command_meta_version ) );
				continue;
			}
			$post_date_formatted = $post_date->format( 'Y-m-d H:i:s' );

			$page_data = [
				'post_title'    => $issue_name,
				'post_name'     => $slug,
				'post_status'   => 'publish',
				'post_author'   => $default_author, // We don't bother finding an author – it's not displayed on the current live site either.
				'post_type'     => 'page',
				'post_parent'   => $issues_parent_page_post_id,
				'post_category' => [ $cat->term_id, $issues_category_id ],
				'post_date'     => $post_date_formatted,
				'meta_input'    => [
					'plone_issue_page_UID' => $issue->UID,
				]
			];

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_issue_page_UID' AND meta_value = %s",
					$issue->UID,
				)
			);
			if ( ! $post_id ) {
				$post_id = wp_insert_post( $page_data );
				$this->logger->log( $log_file, sprintf( 'Created page for issue %s', $issue->{'@id'} ), Logger::SUCCESS );
			}

			$pdf_id = $this->get_issue_pdf_attachment_id( $post_id, $issue, $pdfurls_array );
			if ( ! $pdf_id ) {
				$this->logger->log( $log_file, sprintf( 'Could not find a PDF for %s', $issue->{'@id'} ), Logger::WARNING );
			}

			$image_id = 0;
			if ( ! empty( $issue->image ) ) {
				$blob_file_path      = trailingslashit( realpath( $blobs_folder ) ) . $issue->image->blob_path;
				$image_attachment_id = $this->attachments->import_attachment_for_post(
					$post_id,
					$blob_file_path,
					'Magazine cover: ' . $issue_name,
					[],
					$issue->image->filename
				);

				if ( ! is_wp_error( $image_attachment_id ) ) {
					$image_id                                                    = $image_attachment_id;
					$page_data['meta_input']['_thumbnail_id']                    = $image_id;
					$page_data['meta_input']['newspack_featured_image_position'] = 'hidden';
				} else {
					$this->logger->log( $log_file, sprintf( 'Could not find an image for %s', $issue->{'@id'} ), Logger::WARNING );
				}
			}

			$page_data['ID']           = $post_id;
			$page_data['post_content'] = $this->get_issue_page_content_for_category( $cat->term_id, $issue->description ?? '', $image_id, $pdf_id );
			$page_data['post_excerpt'] = $issue->description ?? '';

			wp_update_post( $page_data );
			wp_set_post_tags( $post_id, [ $the_magazine_tag_id ], true );
			update_post_meta( $page_data['ID'], 'plone_issue_page_UID', $issue->UID );
			$this->logger->log( $log_file, sprintf( 'Updated issue page: %s', get_permalink( $page_data['ID'] ) ), Logger::SUCCESS );

			wp_update_term(
				$cat->term_id,
				'category',
				[
					'name'        => $issue_name,
					'slug'        => $slug,
					'description' => '' // Remove the HTML if it was already added earlier on issues.
				] );
			update_term_meta( $cat->term_id, 'plone_issue_UID', $issue->id );
			MigrationMeta::update( $cat->term_id, $command_meta_key, 'term', $command_meta_version );
			$this->logger->log( $log_file, sprintf( 'Updated issue category: %s', get_term_link( $cat->term_id ) ), Logger::SUCCESS );
		}

	}

	/**
	 * Builds block content for an issue page.
	 *
	 * @param int $category_id
	 * @param string $description
	 * @param int $image_attachment_id
	 * @param int $pdf_id
	 *
	 * @return string
	 */
	private function get_issue_page_content_for_category( int $category_id, string $description, int $image_attachment_id, int $pdf_id ): string {
		$left_column_blocks  = [ $this->gutenberg_block_generator->get_paragraph( $description, '', '', 'small' ) ];
		$right_column_blocks = [];
		if ( 0 !== $image_attachment_id ) {
			$img_post              = get_post( $image_attachment_id );
			$right_column_blocks[] = $this->gutenberg_block_generator->get_image( $img_post, 'full', false );
		}
		if ( 0 !== $pdf_id ) {
			$link = wp_get_attachment_url( $pdf_id );
			$right_column_blocks[] = $this->gutenberg_block_generator->get_paragraph( '<a href="' . $link . '">Download the Digital Issue</a>' );
		}
		$left_column      = $this->gutenberg_block_generator->get_column( $left_column_blocks );
		$right_column     = $this->gutenberg_block_generator->get_column( $right_column_blocks );
		$content_blocks[] = $this->gutenberg_block_generator->get_columns( [ $left_column, $right_column ] );
		$content_blocks[] = $this->gutenberg_block_generator->get_separator( 'is-style-wide' );

		$content_blocks[] = $this->gutenberg_block_generator->get_homepage_articles_for_category(
			[ $category_id ],
			[
				'moreButton'     => true,
				'moreButtonText' => 'More from this issue',
				'showAvatar'     => false,
				'postsToShow'    => 60,
				'mediaPosition'  => 'left',
			]
		);

		return array_reduce(
			$content_blocks,
			fn( string $carry, array $item ): string => $carry . serialize_block( $item ),
			''
		);
	}

	/**
	 * Tries various tricks to get the PDF attachment ID for an issue.
	 *
	 * @param int $post_id The post ID to attach the PDF to.
	 * @param object $issue The issue object.
	 * @param array $pdfurls array of UID => pdfurl. See get_pdf_urls_from_json().
	 *
	 * @return int The attachment ID or 0 if not found.
	 * @throws Exception
	 */
	private function get_issue_pdf_attachment_id( int $post_id, object $issue, array $pdfurls ): int {
		if ( array_key_exists( $issue->UID ?? '', $pdfurls ) ) {
			$pdf_url          = 'https://s3.amazonaws.com/hcn-media/archive-pdf/' . $pdfurls[ $issue->UID ];
			$pdf_attachment_id = $this->attachments->import_attachment_for_post(
				$post_id,
				$pdf_url,
			);
			if ( ! is_wp_error( $pdf_attachment_id ) ) {
				return $pdf_attachment_id;
			}
		}

		// The pagesuite urls all don't work, so no need to try to download them if it's the pagesuite url.
		if ( ! empty( $issue->digitalEditionURL ) && ! str_starts_with( $issue->digitalEditionURL, 'http://edition.pagesuite-professional' ) ) {
			$filename = basename( $issue->digitalEditionURL );
			// Create a filename for the ones that don't have a .pdf extension.
			if ( ! str_ends_with( $filename, '.pdf' ) ) {
				$effective_date = new DateTime( $issue->effective, new DateTimeZone( 'America/Denver' ) );
				$filename       = 'issue-' . $effective_date->format( 'Y_m_d' ) . '.pdf';
			}
			$pdf_attachment_id = $this->attachments->import_attachment_for_post(
				$post_id,
				$issue->digitalEditionURL,
				'',
				[],
				$filename
			);
			if ( ! is_wp_error( $pdf_attachment_id ) ) {
				return $pdf_attachment_id;
			}
		}

		return 0;
	}


	public function cmd_migrate_authors_from_scrape() {
		$last_processed_post_id = PHP_INT_MAX;

		if ( file_exists( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt' ) ) {
			$last_processed_post_id = (int) file_get_contents( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt' );
		}

		global $wpdb;

		$posts_and_authors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = 'plone_author' AND post_id < %d ORDER BY post_id DESC",
				$last_processed_post_id
			)
		);

		foreach ( $posts_and_authors as $record ) {
			WP_CLI::log( "Processing post ID {$record->post_id} ($record->meta_value)..." );
			$author_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->users WHERE display_name = %s",
					$record->meta_value
				)
			);

			if ( $author_id ) {
				WP_CLI::log( "Author ID: $author_id" );
				$wpdb->update(
					$wpdb->posts,
					[ 'post_author' => $author_id ],
					[ 'ID' => $record->post_id ]
				);
			} else {
				WP_CLI::log( 'Author not found.' );
			}

			file_put_contents( '/tmp/highcountrynews-last-processed-post-id-authors-update.txt', $record->post_id );
		}
	}

	/**
	 * Function to process users from a Plone JSON users file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_users_from_json( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
											   ->set_start( $start )
											   ->set_end( $end )
											   ->getIterator();

		foreach ( $iterator as $row_number => $row ) {
			WP_CLI::log( 'Row Number: ' . $row_number . ' - ' . $row['username'] );

			$date_created = new DateTime( 'now', new DateTimeZone( 'America/Denver' ) );

			if ( ! empty( $row['date_created'] ) ) {
				$date_created = DateTime::createFromFormat( 'm-d-Y_H:i', $row['date_created'], new DateTimeZone( 'America/Denver' ) );
			}

			$result = wp_insert_user(
				[
					'user_login'      => $row['username'],
					'user_pass'       => wp_generate_password(),
					'user_email'      => $row['email'],
					'display_name'    => $row['fullname'],
					'first_name'      => $row['first_name'],
					'last_name'       => $row['last_name'],
					'user_registered' => $date_created->format( 'Y-m-d H:i:s' ),
					'role'            => 'subscriber',
				]
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::log( $result->get_error_message() );
			} else {
				WP_CLI::success( "User {$row['email']} created." );
			}
		}
	}

	/**
	 * Function to process images from a Plone JSON image file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_images_from_json( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
											   ->set_start( $start )
											   ->set_end( $end )
											   ->getIterator();

		$creators = [];

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( 'Handling Row Number: ' . "%b$row_number%n" . ' - ' . $row['@id'] ) . "\n";

			$post_author = 0;

			WP_CLI::log( 'Looking for user: ' . $row['creators'][0] );
			if ( array_key_exists( $row['creators'][0], $creators ) ) {
				$post_author = $creators[ $row['creators'][0] ];
				echo WP_CLI::colorize( '%yFound user in array... ' . $post_author . '%n' ) . "\n";
			} else {
				$user = get_user_by( 'login', $row['creators'][0] );

				if ( ! $user ) {
					echo WP_CLI::colorize( '%rUser not found in DB...' ) . "\n";
				} else {
					echo WP_CLI::colorize( '%YUser found in DB, updating role... ' . $row['creators'][0] . ' => ' . $user->ID . '%n' ) . "\n";
					$user->set_role( 'author' );
					$creators[ $row['creators'][0] ] = $user->ID;
					$post_author                     = $user->ID;
				}
			}

			$created_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row['created'], new DateTimeZone( 'America/Denver' ) );
			$updated_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row['modified'], new DateTimeZone( 'America/Denver' ) );

			$caption = '';

			if ( ! empty( $row['description'] ) ) {
				$caption = $row['description'];
			}

			if ( ! empty( $row['credit'] ) ) {
				if ( ! empty( $caption ) ) {
					$caption .= '<br />';
				}

				$caption .= 'Credit: ' . $row['credit'];
			}

			// check image param, if not empty, it is a blob
			if ( ! empty( $row['image'] ) ) {
				echo WP_CLI::colorize( '%wHandling blob...' ) . "\n";
				$filename              = $row['image']['filename'];
				$destination_file_path = WP_CONTENT_DIR . '/uploads/' . $filename;
				$file_blob_path        = WP_CONTENT_DIR . '/high_country_news/blobs/' . $row['image']['blob_path'];
				file_put_contents( $destination_file_path, file_get_contents( $file_blob_path ) );

				$result = media_handle_sideload(
					[
						'name'     => $filename,
						'tmp_name' => $destination_file_path,
					],
					0,
					$row['description'],
					[
						'post_title'        => $row['id'] ?? '',
						'post_author'       => $post_author,
						'post_excerpt'      => $caption,
						'post_content'      => $row['description'] ?? '',
						'post_date'         => $created_at->format( 'Y-m-d H:i:s' ),
						'post_date_gmt'     => $created_at->format( 'Y-m-d H:i:s' ),
						'post_modified'     => $updated_at->format( 'Y-m-d H:i:s' ),
						'post_modified_gmt' => $updated_at->format( 'Y-m-d H:i:s' ),
					]
				);

				if ( is_wp_error( $result ) ) {
					echo WP_CLI::colorize( '%r' . $result->get_error_message() . '%n' ) . "\n";
				} else {
					echo WP_CLI::colorize( "%gImage {$row['id']} created.%n" ) . "\n";
					update_post_meta( $result, 'UID', $row['UID'] );
				}
			} elseif ( ! empty( $row['legacyPath'] ) ) {
				echo WP_CLI::colorize( '%wHandling legacyPath...' ) . "\n";
				// download image and upload it
				$attachment_id = media_sideload_image( $row['@id'] );

				if ( is_wp_error( $attachment_id ) ) {
					echo WP_CLI::colorize( '%r' . $attachment_id->get_error_message() . '%n' ) . "\n";
				} else {
					echo WP_CLI::colorize( "%gImage {$row['id']} created.%n" ) . "\n";
					wp_update_post(
						[
							'ID'                => $attachment_id,
							'post_author'       => $post_author,
							'post_excerpt'      => $caption,
							'post_content'      => $row['description'] ?? '',
							'post_date'         => $created_at->format( 'Y-m-d H:i:s' ),
							'post_date_gmt'     => $created_at->format( 'Y-m-d H:i:s' ),
							'post_modified'     => $updated_at->format( 'Y-m-d H:i:s' ),
							'post_modified_gmt' => $updated_at->format( 'Y-m-d H:i:s' ),
						]
					);

					update_post_meta( $attachment_id, 'UID', $row['UID'] );
				}
			} else {
				echo WP_CLI::colorize( '%rNo image found for this row...' ) . "\n";
			}
		}
	}

	/**
	 * Migrate publication issues from JSON file.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_issues_from_json( $args, $assoc_args ) {
		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
											   ->set_start( $start )
											   ->set_end( $end )
											   ->getIterator();

		$parent_category_id = wp_create_category( 'Issues' );

		foreach ( $iterator as $row_number => $row ) {
			$description = '';

			if ( ! empty( $row['title'] ) ) {
				$description .= $row['title'] . "\n\n";
			}

			$description .= $row['description'] . "\n\n";
			$description .= 'Volume: ' . $row['publicationVolume'] . "\n";
			$description .= 'Issue: ' . $row['publicationIssue'] . "\n";

			$publication_date = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $row['publicationDate'] );

			if ( $publication_date instanceof DateTime ) {
				$description .= 'Date: ' . $publication_date->format( 'l, F jS, Y' ) . "\n";
			}

			wp_insert_category(
				[
					'taxonomy'             => 'category',
					'cat_name'             => $row['id'],
					'category_description' => $description,
					'category_nicename'    => $row['title'],
					'category_parent'      => $parent_category_id,
				]
			);
		}
	}

	public function cmd_fix_weird_chars( $args, $assoc_args ) {
		global $wpdb;

		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
											   ->set_start( $start )
											   ->set_end( $end )
											   ->getIterator();

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( "Handling Row Number: %B$row_number%n\n" );
			$post_content = '';

			if ( ! empty( $row['intro'] ) ) {
				$intro = htmlspecialchars( $row['intro'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$intro = $this->replace_weird_chars( $intro );
				$intro = utf8_decode( $intro );
				$intro = html_entity_decode( $intro, ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );

				$dom           = new DOMDocument();
				$dom->encoding = 'utf-8';
				@$dom->loadHTML( $intro );
				// var_dump([$dom->childNodes, $dom->firstChild, $dom->firstChild->childNodes, $dom->lastChild]);die();
				foreach ( $dom->lastChild->firstChild->childNodes as $child ) {
					if ( $child instanceof DOMElement ) {
						$this->remove_attributes( $child );
					}
				}
				$post_content .= $this->inner_html( $dom->lastChild->firstChild );
			}

			if ( ! empty( $row['text'] ) ) {
				$text = htmlspecialchars( $row['text'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$text = $this->replace_weird_chars( $text );
				$text = utf8_decode( $text );
				$text = html_entity_decode( $text, ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$text = trim( $text );

				if ( ! empty( $text ) ) {
					$dom           = new DOMDocument();
					$dom->encoding = 'utf-8';
					@$dom->loadHTML( $text );
					foreach ( $dom->lastChild->firstChild->childNodes as $child ) {
						if ( $child instanceof DOMElement ) {
							$this->remove_attributes( $child );
						}
					}

					$script_tags = $dom->getElementsByTagName( 'script' );

					foreach ( $script_tags as $script_tag ) {
						// var_dump( [ 'tag' => $script_tag->ownerDocument->saveHTML( $script_tag), 'name' => $script_tag->nodeName, 'parent' => $script_tag->parentNode->nodeName ] );
						$script_tag->nodeValue = '';
					}

					$img_tags = $dom->getElementsByTagName( 'img' );

					foreach ( $img_tags as $img_tag ) {
						/*
						 @var DOMElement $img_tag */
						// $src should look like this: resolveuid/191b2acc464b44f592c547229b393b4e.
						$src         = $img_tag->getAttribute( 'src' );
						$uid         = str_replace( 'resolveuid/', '', $src );
						$first_slash = strpos( $uid, '/' );
						if ( is_numeric( $first_slash ) ) {
							$uid = substr( $uid, 0, $first_slash );
						}
						echo WP_CLI::colorize( "%BImage SRC: $src - UID: $uid%n\n" );
						$attachment_id = $wpdb->get_var( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'UID' AND meta_value = '$uid'" );

						if ( $attachment_id ) {
							$attachment_url = wp_get_attachment_url( $attachment_id );
							echo WP_CLI::colorize( "%wImage found, URL: $attachment_url%n\n" );
							$img_tag->setAttribute( 'src', $attachment_url );
						} else {
							$filename = trim( basename( $src ) );
							echo WP_CLI::colorize( "%BImage filename: $filename%n\n" );
							$attachment_id = $wpdb->get_var(
								"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = '%$filename'"
							);

							if ( $attachment_id ) {
								$attachment_url = wp_get_attachment_url( $attachment_id );
								echo WP_CLI::colorize( "%wImage found, URL: $attachment_url%n\n" );
								$img_tag->setAttribute( 'src', $attachment_url );
							} else {
								echo WP_CLI::colorize( "%yImage not found...%n\n" );
							}
						}
					}

					$post_content .= $this->inner_html( $dom->lastChild->firstChild );
				}
			}

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'UID' AND meta_value = %s",
					$row['UID']
				)
			);

			echo WP_CLI::colorize( "%GUpdating Post ID: {$post_id}%n\n" );
			$wpdb->update(
				$wpdb->posts,
				[
					'post_content' => $post_content,
				],
				[
					'ID' => $post_id,
				]
			);
		}
	}

	public function fix_related_link_text( $args, $assoc_args ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_content FROM $wpdb->posts WHERE post_type = 'post' AND post_content LIKE '%[RELATED:%'"
		);

		$articles = json_decode( file_get_contents( $args[0] ), true );

		$article_link_and_uid = [];
		foreach ( $articles as $article ) {
			$article_link_and_uid[ $article['@id'] ] = $article['UID'];
		}

		foreach ( $posts as $post ) {
			echo WP_CLI::colorize( "Main Post ID: %B{$post->ID}%n\n" );
			preg_match_all( '/\[RELATED:(.*?)\]/', $post->post_content, $matches, PREG_SET_ORDER );

			$update       = false;
			$post_content = $post->post_content;
			foreach ( $matches as $match ) {
				echo WP_CLI::colorize( "%w{$match[1]}%n\n" );

				if ( array_key_exists( $match[1], $article_link_and_uid ) ) {
					echo WP_CLI::colorize( "%wFound link in articles file...%n\n" );
					$uid     = $article_link_and_uid[ $match[1] ];
					$post_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'UID' AND meta_value = %s",
							$uid
						)
					);

					if ( $post_id ) {
						echo WP_CLI::colorize( "%gFound related post ID: {$post_id}%n\n" );
						$update       = true;
						$permalink    = get_permalink( $post_id );
						$post_title   = get_the_title( $post_id );
						$replacement  = "<p><strong>RELATED:</strong> <a href='{$permalink}' target='_blank'>{$post_title}</a></p>";
						$post_content = str_replace( $match[0], $replacement, $post_content );
					} else {
						echo WP_CLI::colorize( "%rNo post ID found%n\n" );
					}
				}
			}

			if ( $update ) {
				$result = $wpdb->update(
					$wpdb->posts,
					[
						'post_content' => $post_content,
					],
					[
						'ID' => $post->ID,
					]
				);

				if ( $result ) {
					echo WP_CLI::colorize( "%gUpdated post content.%n\n" );
				} else {
					echo WP_CLI::colorize( "%rFailed to update post content.%n\n" );
				}
			} else {
				echo WP_CLI::colorize( "%rNo matching link(s) found in articles file...%n\n" );
			}
		}
	}

	/**
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function cmd_migrate_articles_from_json( $args, $assoc_args ) {
		global $wpdb;

		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
											   ->set_start( $start )
											   ->set_end( $end )
											   ->getIterator();

		// Need to create some additional parent categories based off of live site.
		$main_categories = [
			'Features',
			'Public Lands',
			'Indigenous Affairs',
			'Water',
			'Climate Change',
			'Wildfire',
			'Arts & Culture',
		];

		foreach ( $main_categories as $category ) {
			wp_create_category( $category );
		}

		foreach ( $iterator as $row_number => $row ) {
			echo WP_CLI::colorize( 'Handling Row Number: ' . "%b$row_number%n" . ' - ' . $row['@id'] ) . "\n";

			$post_date_string     = $row['effective'] ?? $row['created'] ?? '1970-01-01T00:00:00+00:00';
			$post_modified_string = $row['modified'] ?? '1970-01-01T00:00:00+00:00';
			$post_date            = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_date_string, new DateTimeZone( 'America/Denver' ) );
			$post_modified        = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_modified_string, new DateTimeZone( 'America/Denver' ) );

			$post_data                = [
				'post_category' => [],
				'meta_input'    => [],
			];
			$post_data['post_title']  = $row['title'];
			$post_data['post_status'] = 'public' === $row['review_state'] ? 'publish' : 'draft';
			$post_data['post_date']   = $post_date->format( 'Y-m-d H:i:s' );
			$post_date->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_data['post_date_gmt'] = $post_date->format( 'Y-m-d H:i:s' );
			$post_data['post_modified'] = $post_modified->format( 'Y-m-d H:i:s' );
			$post_modified->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_data['post_modified_gmt'] = $post_modified->format( 'Y-m-d H:i:s' );

			if ( str_contains( $row['@id'], '/issues/' ) ) {
				$issue_category_id = get_cat_ID( 'Issues' );

				if ( 0 !== $issue_category_id ) {
					$post_data['post_category'][] = $issue_category_id;
				}

				$issues_position = strpos( $row['@id'], '/issues/' ) + 8;
				$issue_number    = substr( $row['@id'], $issues_position, strpos( $row['@id'], '/', $issues_position ) - $issues_position );

				$issue_number_category_id = get_cat_ID( $issue_number );

				if ( 0 !== $issue_number_category_id ) {
					$post_data['post_category'][] = $issue_number_category_id;
				}
			} else {
				$main_categories      = array_intersect( $row['subjects'], $main_categories );
				$remaining_categories = array_diff( $row['subjects'], $main_categories );

				$main_category_id = 0;

				if ( count( $main_categories ) >= 1 ) {
					$main_category_id             = wp_create_category( array_shift( $main_categories ) );
					$post_data['post_category'][] = $main_category_id;
				}

				foreach ( $remaining_categories as $category ) {
					$category_id                  = wp_create_category( $category, $main_category_id );
					$post_data['post_category'][] = $category_id;
				}
			}

			// Author Section.
			$author_id = 0;

			if ( ! empty( $row['creators'] ) ) {
				$author_by_login = get_user_by( 'login', $row['creators'][0] );

				if ( $author_by_login instanceof WP_User ) {
					$author_id = $author_by_login->ID;
				}
			}

			$post_data['post_author'] = $author_id;

			$post_data['meta_input']['newspack_post_subtitle'] = $row['subheadline'];
			$post_data['meta_input']['UID']                    = $row['UID'];

			$post_content = '';

			if ( ! empty( $row['intro'] ) ) {
				$intro = htmlspecialchars( $row['intro'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$intro = $this->replace_weird_chars( $intro );
				$intro = utf8_decode( $intro );
				$intro = html_entity_decode( $intro, ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );

				$dom           = new DOMDocument();
				$dom->encoding = 'utf-8';
				@$dom->loadHTML( $intro );
				// var_dump([$dom->childNodes, $dom->firstChild, $dom->firstChild->childNodes, $dom->lastChild]);die();
				foreach ( $dom->lastChild->firstChild->childNodes as $child ) {
					if ( $child instanceof DOMElement ) {
						$this->remove_attributes( $child );
					}
				}
				$post_content .= $this->inner_html( $dom->lastChild->firstChild );
			}

			if ( ! empty( $row['text'] ) ) {
				$text = htmlspecialchars( $row['text'], ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$text = $this->replace_weird_chars( $text );
				$text = utf8_decode( $text );
				$text = html_entity_decode( $text, ENT_QUOTES | ENT_DISALLOWED | ENT_HTML5, 'UTF-8' );
				$text = trim( $text );

				if ( ! empty( $text ) ) {
					$dom           = new DOMDocument();
					$dom->encoding = 'utf-8';
					@$dom->loadHTML( $text );
					foreach ( $dom->lastChild->firstChild->childNodes as $child ) {
						if ( $child instanceof DOMElement ) {
							$this->remove_attributes( $child );
						}
					}

					$script_tags = $dom->getElementsByTagName( 'script' );

					foreach ( $script_tags as $script_tag ) {
						// var_dump( [ 'tag' => $script_tag->ownerDocument->saveHTML( $script_tag), 'name' => $script_tag->nodeName, 'parent' => $script_tag->parentNode->nodeName ] );
						$script_tag->nodeValue = '';
					}

					$img_tags = $dom->getElementsByTagName( 'img' );

					foreach ( $img_tags as $img_tag ) {
						/*
						 @var DOMElement $img_tag */
						// $src should look like this: resolveuid/191b2acc464b44f592c547229b393b4e.
						$src         = $img_tag->getAttribute( 'src' );
						$uid         = str_replace( 'resolveuid/', '', $src );
						$first_slash = strpos( $uid, '/' );
						if ( is_numeric( $first_slash ) ) {
							$uid = substr( $uid, 0, $first_slash );
						}
						echo WP_CLI::colorize( "%BImage SRC: $src - UID: $uid%n\n" );
						$attachment_id = $wpdb->get_var( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'UID' AND meta_value = '$uid'" );

						if ( $attachment_id ) {
							$attachment_url = wp_get_attachment_url( $attachment_id );
							echo WP_CLI::colorize( "%wImage found, URL: $attachment_url%n\n" );
							$img_tag->setAttribute( 'src', $attachment_url );
						} else {
							$filename = trim( basename( $src ) );
							echo WP_CLI::colorize( "%BImage filename: $filename%n\n" );
							$attachment_id = $wpdb->get_var(
								"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = '%$filename'"
							);

							if ( $attachment_id ) {
								$attachment_url = wp_get_attachment_url( $attachment_id );
								echo WP_CLI::colorize( "%wImage found, URL: $attachment_url%n\n" );
								$img_tag->setAttribute( 'src', $attachment_url );
							} else {
								echo WP_CLI::colorize( "%yImage not found...%n\n" );
							}
						}
					}

					$post_content .= $this->inner_html( $dom->lastChild->firstChild );
				}
			}

			$post_data['post_content'] = $post_content;

			// handle featured image.
			if ( ! empty( $row['image'] ) ) {
				$attachment_id = $wpdb->get_var(
					"SELECT post_id
					FROM $wpdb->postmeta
					WHERE meta_key = '_wp_attached_file'
					  AND meta_value LIKE '%{$row['image']['filename']}'
					LIMIT 1"
				);

				if ( $attachment_id ) {
					$post_data['meta_input']['_thumbnail_id'] = $attachment_id;
				}
			} else {
				echo WP_CLI::colorize( "%yNo featured image...%n\n" );
			}

			$result = wp_insert_post( $post_data );

			if ( ! is_wp_error( $result ) ) {
				// handle redirects.
				echo WP_CLI::colorize( "%gPost successfully created, Post ID: $result%n\n" );

				foreach ( $row['aliases'] as $alias ) {
					$old_url = str_replace( '/hcn/hcn/', 'https://hcn.org/', $alias );
					$new_url = get_post_permalink( $result );
					$this->redirection->create_redirection_rule(
						"$result-{$row['id']}",
						$old_url,
						$new_url
					);
				}

				if ( ! empty( $row['author'] ) ) {
					$guest_author_names      = explode( ' ', $row['author'] );
					$guest_author_last_name  = array_pop( $guest_author_names );
					$guest_author_first_name = implode( ' ', $guest_author_names );
					$guest_author_id         = $this->coauthorsplus_logic->create_guest_author(
						[
							'display_name' => $row['author'],
							'first_name'   => $guest_author_first_name,
							'last_name'    => $guest_author_last_name,
						]
					);

					if ( ! is_array( $guest_author_id ) ) {
						$guest_author_id = [ intval( $guest_author_id ) ];
					}

					$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_id, $result );
				}
			} else {
				echo WP_CLI::colorize( "%rError creating post: {$result->get_error_message()}%n\n" );
			}
		}
	}

	/**
	 * HCN has magazines which they publish and identify online with 'issues' in the URL.
	 * For any URL containing 'issue' we want to find the corresponding issue number
	 * as a category and set both the main Issues category as well as the
	 * issue number category to the post. Any other category that may
	 * have been set is removed.
	 *
	 * @param $args
	 * @param $assoc_args
	 *
	 * @return void
	 * @throws Exception
	 */
	public function add_issues_as_categories( $args, $assoc_args ) {
		global $wpdb;

		$file_path = $args[0];
		$start     = $assoc_args['start'] ?? 0;
		$end       = $assoc_args['end'] ?? PHP_INT_MAX;

		$iterator = ( new FileImportFactory() )->get_file( $file_path )
											   ->set_start( $start )
											   ->set_end( $end )
											   ->getIterator();

		foreach ( $iterator as $row ) {
			if ( str_contains( $row['@id'], '/issues/' ) ) {
				$post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_article_UID' AND meta_value = %s",
						$row['UID']
					)
				);

				$issue_category_id = get_cat_ID( 'Issues' );
				$category_ids      = [];

				if ( 0 !== $issue_category_id ) {
					$category_ids[] = $issue_category_id;
				}

				$issues_position = strpos( $row['@id'], '/issues/' ) + 8;
				$issue_number    = substr( $row['@id'], $issues_position, strpos( $row['@id'], '/', $issues_position ) - $issues_position );

				$issue_number_category_id = get_cat_ID( $issue_number );

				if ( 0 !== $issue_number_category_id ) {
					$category_ids[] = $issue_number_category_id;
				}

				wp_set_post_categories( $post_id, $category_ids, false );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator hcn-fix-categories`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_fix_categories( $args, $assoc_args ) {
		global $wpdb;

		$articles_list   = json_decode( file_get_contents( $assoc_args['articles-json'] ), true );
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		// The main categories are Articles, Issues, and Departments.
		$articles_category_id    = $this->get_or_create_category( 'Articles' );
		$issues_category_id      = $this->get_or_create_category( 'Issues' );
		$departments_category_id = $this->get_or_create_category( 'Departments' );

		// Switch all the categories to tags except the main categories, and the categories with main categories as parent.
		$categories = get_categories(
			[
				'hide_empty' => false,
			]
		);

		$categories_to_update_ids = [];
		foreach ( $categories as $category ) {
			if ( in_array( $category->cat_ID, [ $articles_category_id, $issues_category_id, $departments_category_id ], true ) ) {
				continue;
			}

			if ( 0 !== $category->category_parent ) {
				$parent_category = get_category( $category->category_parent );

				if ( in_array( $parent_category->cat_ID, [ $articles_category_id, $issues_category_id, $departments_category_id ], true ) ) {
					continue;
				}
			}

			$categories_to_update_ids[] = $category->cat_ID;
			$this->logger->log( 'taxonomies_fix.log', sprintf( 'Switched category %s(%d) to tag', $category->name, $category->cat_ID ), Logger::SUCCESS );
		}

		if ( ! empty( $categories_to_update_ids ) ) {
			$wpdb->query( "UPDATE $wpdb->term_taxonomy SET taxonomy = 'post_tag' WHERE term_id IN (" . implode( ',', $categories_to_update_ids ) . ')' );
		}

		$meta_query = [
			[
				'key'     => '_newspack_fixed_taxonomies',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$plone_article_uid      = get_post_meta( $post->ID, 'plone_article_UID', true );
			$original_article_index = array_search( $plone_article_uid, array_column( $articles_list, 'UID' ) );

			if ( false === $original_article_index ) {
				WP_CLI::warning( "Article UID $plone_article_uid not found in articles list: " . $post->ID );
				continue;
			}

			$original_article = $articles_list[ $original_article_index ];

			$is_issue =
				array_key_exists( 'parent', $original_article )
				&& is_array( $original_article['parent'] )
				&& array_key_exists( '@type', $original_article['parent'] )
				&& 'Issue' === $original_article['parent']['@type'];

			if ( $is_issue ) {
				wp_set_post_categories( $post->ID, [ $issues_category_id, $departments_category_id ], false );

				// Setting the department as category if it exists.
				if ( array_key_exists( 'department', $original_article ) ) {
					$department_category_id = $this->get_or_create_category( $original_article['department'], $departments_category_id );
					wp_set_post_categories( $post->ID, [ $department_category_id ], true );
				}

				// Setting the issue number as category if it exists.
				// The issue number is found in the parent @id attribute: https://www.hcn.org/issues/49.16
				if ( array_key_exists( '@id', $original_article['parent'] ) ) {
					$issue_number             = substr( $original_article['parent']['@id'], strrpos( $original_article['parent']['@id'], '/' ) + 1 );
					$issue_number_category_id = $this->get_or_create_category( $issue_number, $issues_category_id );
					wp_set_post_categories( $post->ID, [ $issue_number_category_id ], true );
				}
			} else {
				wp_set_post_categories( $post->ID, [ $articles_category_id ], false );
			}

			update_post_meta( $post->ID, '_newspack_fixed_taxonomies', true );
		}

		// update meta key from _yoast_wpseo_primary_category to _yoast_wpseo_primary_post_tag.
		$wpdb->query( "UPDATE $wpdb->postmeta SET meta_key = '_yoast_wpseo_primary_post_tag' WHERE meta_key = '_yoast_wpseo_primary_category'" );

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-migrate-headlines`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_migrate_headlines( $args, $assoc_args ) {
		$articles_list   = json_decode( file_get_contents( $assoc_args['articles-json'] ), true );
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migrated_headline',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$plone_article_uid      = get_post_meta( $post->ID, 'plone_article_UID', true );
			$original_article_index = array_search( $plone_article_uid, array_column( $articles_list, 'UID' ) );

			if ( false === $original_article_index ) {
				WP_CLI::warning( "Article UID $plone_article_uid not found in articles list: " . $post->ID );
				continue;
			}

			$original_article = $articles_list[ $original_article_index ];

			if ( array_key_exists( 'subheadline', $original_article ) && ! empty( $original_article['subheadline'] ) ) {
				if ( $post->post_title !== $original_article['subheadline'] ) {
					$subheadline        = rtrim( trim( $original_article['subheadline'] ) );
					$headline_text      = 'This article appeared in the print edition of the magazine with the headline <strong>' . $subheadline . '</strong>';
					$headline_last_char = substr( $subheadline, -1 );

					if ( ! in_array( $headline_last_char, [ '.', '?', '!' ] ) ) {
						$headline_text .= '.';
					}
					$updated_post_content = $post->post_content . serialize_block(
						$this->gutenberg_block_generator->get_paragraph( $headline_text )
					);

					wp_update_post(
						[
							'ID'           => $post->ID,
							'post_content' => $updated_post_content,
						]
					);


					$this->logger->log( 'migrate_headlines.log', sprintf( 'Updated post content for post %d', $post->ID ), Logger::SUCCESS );
				}
			}

			update_post_meta( $post->ID, '_newspack_migrated_headline', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-migrate-related-stories`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_migrate_related_stories( $args, $assoc_args ) {
		global $wpdb;
		$articles_list   = json_decode( file_get_contents( $assoc_args['articles-json'] ), true );
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_migrated_related_posts',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 94456,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 94456,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$plone_article_uid      = get_post_meta( $post->ID, 'plone_article_UID', true );
			$original_article_index = array_search( $plone_article_uid, array_column( $articles_list, 'UID' ) );

			if ( false === $original_article_index ) {
				WP_CLI::warning( "Article UID $plone_article_uid not found in articles list: " . $post->ID );
				continue;
			}

			$original_article = $articles_list[ $original_article_index ];

			if ( array_key_exists( 'references', $original_article ) && array_key_exists( 'relatesTo', $original_article['references'] ) && ! empty( $original_article['references']['relatesTo'] ) ) {
				$related_posts = [];

				foreach ( $original_article['references']['relatesTo'] as $related_to_id ) {
					// find WP post ID by the meta plone_article_UID
					$related_wp_post_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_article_UID' AND meta_value = %s",
							$related_to_id
						)
					);

					if ( ! $related_wp_post_id ) {
						WP_CLI::warning( "Related Article UID $plone_article_uid not found in WP: " . $post->ID );
						continue;
					}

					$related_posts[] = [
						'title'     => get_the_title( $related_wp_post_id ),
						'permalink' => get_permalink( $related_wp_post_id ),
					];
				}

				if ( ! empty( $related_posts ) ) {
					$updated_post_content = $post->post_content;

					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_paragraph( 'Read More:' )
					);

					$updated_post_content .= serialize_block(
						$this->gutenberg_block_generator->get_list(
							array_map(
								function( $related_post ) {
										return '<a href="' . $related_post['permalink'] . '">' . $related_post['title'] . '</a>';
								},
								$related_posts
							)
						)
					);

					wp_update_post(
						[
							'ID'           => $post->ID,
							'post_content' => $updated_post_content,
						]
					);

					$this->logger->log( 'migrate_headlines.log', sprintf( 'Updated post content for post %d', $post->ID ), Logger::SUCCESS );
				}
			}

			update_post_meta( $post->ID, '_newspack_migrated_related_posts', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-set-featured-image-postition-for-featured-posts`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_set_featured_image_postition_for_featured_posts( $args, $assoc_args ) {
		$posts_per_batch     = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch               = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;
		$feature_category_id = intval( $assoc_args['feature-category-id'] );

		$meta_query = [
			[
				'key'     => '_newspack_set_featured_image_position',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 94456,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'cat'            => $feature_category_id,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 94456,
				'post_status'    => 'any',
				'cat'            => $feature_category_id,
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			update_post_meta( $post->ID, 'newspack_featured_image_position', 'behind' );
			$this->logger->log( 'set_featured_image_position.log', sprintf( 'Updated post %d', $post->ID ), Logger::SUCCESS );
			update_post_meta( $post->ID, '_newspack_set_featured_image_position', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-turn-on-images-captions-and-credits`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_turn_on_images_captions_and_credits( $args, $assoc_args ) {
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_with_media_captions_and_credits_on',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 120731,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 120731,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			$post_content = $post->post_content;

			$updated_post_content = serialize_blocks(
				array_map(
					function( $block ) use ( $post ) {
						if ( 'core/image' === $block['blockName'] ) {
							// get media URL from innerHTML attribute in the format wp-content/uploads/2021/05/nouveau-web1.jpg.
							preg_match( '/wp-content\/uploads\/(\d{4}\/\d{2}\/([^?"]+))[^"]*"/', $block['innerHTML'], $matches );

							if ( ! isset( $matches[1] ) ) {
								return $block;
							}

							$media_url = $matches[1];

							// get media ID from media URL.
							$media_id = $this->get_attachment_id_from_url( $media_url );

							if ( $media_id ) {
								$this->logger->log( 'turn_on_images_captions_and_credits.log', sprintf( 'Updated image %d', $media_id ), Logger::SUCCESS );
							} else {
								$this->logger->log( 'turn_on_images_captions_and_credits.log', sprintf( 'Image of the post %d not found for URL %s', $post->ID, $media_url ), Logger::WARNING );
							}

							return $media_id ? $this->gutenberg_block_generator->get_image( get_post( $media_id ) ) : $block;
						}

						return $block;
					},
					parse_blocks( $post_content )
				)
			);

			if ( $post_content !== $updated_post_content ) {
				wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $updated_post_content,
					]
				);

				$this->logger->log( 'turn_on_images_captions_and_credits.log', sprintf( 'Updated post content for post %d', $post->ID ), Logger::SUCCESS );
			}

			update_post_meta( $post->ID, '_newspack_with_media_captions_and_credits_on', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-copy-subhead-to-excerpt`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_copy_subhead_to_excerpt( $args, $assoc_args ) {
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$meta_query = [
			[
				'key'     => '_newspack_subhead_copied_to_excerpt',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts = $query->get_posts();

		foreach ( $posts as $post ) {
			// copy post meta newspack_post_subtitle to the post excerpt.
			$post_subtitle = get_post_meta( $post->ID, 'newspack_post_subtitle', true );

			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_excerpt' => $post_subtitle,
				]
			);

			$this->logger->log( 'copy_subhead_to_excerpt.log', sprintf( 'Updated post excerpt for post %d', $post->ID ), Logger::SUCCESS );

			update_post_meta( $post->ID, '_newspack_subhead_copied_to_excerpt', true );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-migrate-issues-meta-data`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_migrate_issues_meta_data( $args, $assoc_args ) {
		global $wpdb;

		$issues_list                = json_decode( file_get_contents( $assoc_args['issues-json'] ), true );
		$blobs_folder               = rtrim( $assoc_args['blobs-folder-path'], '/' ) . '/';
		$issues_category_id         = $this->get_or_create_category( 'Issues' );
		$already_processed_ids_file = 'already_processed_ids.log';
		// read already processed ids from file.
		$already_processed_ids = [];
		if ( file_exists( $already_processed_ids_file ) ) {
			// read lines to array.
			$already_processed_ids = file( $already_processed_ids_file, FILE_IGNORE_NEW_LINES );
		}

		foreach ( $issues_list as $issue ) {
			// skip already processed issues.
			if ( in_array( $issue['@id'], $already_processed_ids, true ) ) {
				continue;
			}
			// to-delete.
			// if ( 'https://www.hcn.org/issues/47.1' !== $issue['@id'] ) {
			// continue;
			// }
			// to-delete.
			// if ( empty( $issue['digitalEditionURL'] ) ) {
			// continue;
			// }
			// to-delete.
			// $issue['image']['blob_path'] = '0x03eea5b01bc001dd.blob';

			// Category name is in the @id field as https://www.hcn.org/issues/category_name.
			$category_name = substr( $issue['@id'], strrpos( $issue['@id'], '/' ) + 1 );

			$category_id           = $this->get_or_create_category( $category_name, $issues_category_id );
			$issue_date            = gmdate( 'F j, Y', strtotime( $issue['effective'] ) );
			$issue_raw_description = $issue['description'];

			$issue_thumbnail_id = $this->get_attachment_id_from_issue_image( $issue['image'], $blobs_folder );

			if ( is_wp_error( $issue_thumbnail_id ) ) {
				$this->logger->log( 'issues-meta.log', sprintf( 'Error getting attachment ID for issue %s: %s', $issue['@id'], $issue_thumbnail_id->get_error_message() ), Logger::WARNING );
				$issue_thumbnail = '';
			} else {
				$issue_thumbnail_url = wp_get_attachment_url( $issue_thumbnail_id );
				$issue_thumbnail     = '<img class="size-medium wp-image-' . $issue_thumbnail_id . '" src="' . $issue_thumbnail_url . '" alt="" width="248" height="300" />';
			}

			$digital_issue_link = '';
			if ( ! empty( $issue['digitalEditionURL'] ) ) {
				$digital_issue_id = $this->attachments->import_external_file( $issue['digitalEditionURL'] );

				if ( is_wp_error( $digital_issue_id ) ) {
					$this->logger->log( 'issues-meta.log', sprintf( 'Error getting digital edition for issue %s: %s', $issue['@id'], $digital_issue_id->get_error_message() ), Logger::WARNING );
					$digital_issue_link = '';
					// to-delete.
					// continue;
				} else {
					$digital_issue_url  = wp_get_attachment_url( $digital_issue_id );
					$digital_issue_link = '<a href="' . $digital_issue_url . '"><b>Read the digital Issue</b></a>';
				}
			}

			$category_description = '<h1><strong>Magazine - </strong>' . $issue_date . '</h1>
			' . $issue_thumbnail . '

			' . $issue_raw_description . '

			' . $digital_issue_link;

			// update category description and title to the issue date.
			$wpdb->update( $wpdb->term_taxonomy, [ 'description' => $category_description ], [ 'term_id' => $category_id ] );
			// $wpdb->update( $wpdb->terms, [ 'name' => $issue_date ], [ 'term_id' => $category_id ] );

			$this->logger->log( 'issues-meta.log', sprintf( 'Updated category %s(%d) with description and title', $category_name, $category_id ), Logger::SUCCESS );
			// die();

			// write to the processed issues log file.
			file_put_contents( $already_processed_ids_file, $issue['@id'] . PHP_EOL, FILE_APPEND );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-fix-issues-categories-slug`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_fix_issues_categories_slug( $args, $assoc_args ) {
		$issues_list                = json_decode( file_get_contents( $assoc_args['issues-json'] ), true );
		$issues_category_id         = $this->get_or_create_category( 'Issues' );
		$already_processed_ids_file = 'already_processed_issues_ids.log';
		// read already processed ids from file.
		$already_processed_ids = [];
		if ( file_exists( $already_processed_ids_file ) ) {
			// read lines to array.
			$already_processed_ids = file( $already_processed_ids_file, FILE_IGNORE_NEW_LINES );
		}

		foreach ( $issues_list as $issue ) {
			// skip already processed issues.
			if ( in_array( $issue['@id'], $already_processed_ids, true ) ) {
				continue;
			}

			// Category name is in the @id field as https://www.hcn.org/issues/category_name.
			$category_name = substr( $issue['@id'], strrpos( $issue['@id'], '/' ) + 1 );
			$category_id   = $this->get_or_create_category( $category_name, $issues_category_id );

			wp_update_term( $category_id, 'category', [ 'slug' => $category_name ] );

			$this->logger->log( 'issues-slug.log', sprintf( 'Updated category %s(%d) slug', $category_name, $category_id ), Logger::SUCCESS );

			// write to the processed issues log file.
			file_put_contents( $already_processed_ids_file, $issue['@id'] . PHP_EOL, FILE_APPEND );
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator hcn-delete-subscribers`.
	 *
	 * @param array $positional_args Positional arguments.
	 * @param array $assoc_args      Associative arguments.
	 * @return void
	 */
	public function hcn_delete_subscriber( $positional_args, $assoc_args ) {
		$log_file        = 'hcn_delete_subscribers.log';
		$users_per_batch = isset( $assoc_args['users-per-batch'] ) ? intval( $assoc_args['users-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$users = get_users(
			[
				'role'   => 'subscriber',
				'number' => $users_per_batch,
				'offset' => ( $batch - 1 ) * $users_per_batch,
			]
		);

		foreach ( $users as $user ) {
			if ( 0 < intval( count_user_posts( $user->ID ) ) ) {
				$this->logger->log( $log_file, 'Skipping user ' . $user->ID . ' ' . $user->user_email . ' with posts', Logger::WARNING );
				continue;
			}

			$co_author = $this->coauthorsplus_logic->get_guest_author_by_display_name( $user->display_name );
			if ( $co_author ) {
				$this->logger->log( $log_file, 'Skipping user ' . $user->ID . ' ' . $user->user_email . ' with co-author', Logger::WARNING );
				continue;
			}

			$this->logger->log( $log_file, 'Deleting user ' . $user->ID . ' ' . $user->user_email, Logger::SUCCESS );
			wp_delete_user( $user->ID );
		}

		wp_cache_flush();
	}
	// There were a bunch of categories under issues that were not issues, but looked
	// more like articles. This function deletes them.
	public function delete_weird_categories( array $args, array $assoc_args ): void {
		$dry_run = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$issue_term_id = 385;
		global $wpdb;
		// Issues slugs should be a number (e.g. 35 or 9.21). The query gets the issues that
		// don't have number slugs and don't have any posts associated with it.
		$sql = $wpdb->prepare( "SELECT wt.term_id
			FROM {$wpdb->terms} wt
				LEFT JOIN {$wpdb->term_relationships} wtr ON wtr.term_taxonomy_id = wt.term_id
			WHERE wtr.term_taxonomy_id IS NULL
				AND wt.term_id IN (SELECT term_id FROM {$wpdb->term_taxonomy} WHERE parent =  %d)
				AND wt.slug REGEXP '[a-z]'", [ $issue_term_id ] );

		$category_ids = $wpdb->get_col( $sql );
		$num_cats     = count( $category_ids );

		$progress = WP_CLI\Utils\make_progress_bar(
			sprintf( 'Deleting %d weird issue catergories', $num_cats ),
			$num_cats
		);

		foreach ( $category_ids as $cat_id ) {
			if ( ! $dry_run && is_wp_error( wp_delete_term( $cat_id, 'category' ) ) ) {
				WP_CLI::error( 'Deleting issue category with %d triggered an error', $cat_id );
			}
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success( 'Deleted weird issue categories' );
	}

	/**
	 * Callback for the command hcn-generate-redirects.
	 */
	public function hcn_generate_redirects( array $args, array $assoc_args ): void {
		$log_file = 'hcn_generate_redirects.log';
		$home_url = home_url();

		$categories = get_categories( [
			'fields' => 'slugs',
		] );

		global $wpdb;
		foreach ( $this->json_iterator->items( $assoc_args['articles-json'] ) as $article ) {
			if ( empty( $article->aliases ) ) {
				continue;
			}

			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'plone_article_UID' and meta_value = %s",
				$article->UID ) );
			if ( ! $post_id ) {
				$this->logger->log( $log_file, sprintf( 'Post with plone_article_UID %s not found', $article->UID ), Logger::WARNING );
				continue;
			}

			foreach ( $article->aliases as $alias ) {
				$existing_redirects = $this->redirection_logic->get_redirects_by_exact_from_url( $alias );
				if ( ! empty( $existing_redirects ) ) {
					foreach ( $existing_redirects as $existing_redirect ) {
						$existing_redirect->delete();
					}
				}
				// We will use a regex redirect for the /hcn/hcn/ prefix, so remove that.
				$no_prefix          = str_replace( '/hcn/hcn', '', $alias );
				$existing_redirects = $this->redirection_logic->get_redirects_by_exact_from_url( $no_prefix );
				if ( ! empty( $existing_redirects ) ) {
					foreach ( $existing_redirects as $existing_redirect ) {
						$existing_redirect->delete();
					}
				}

				$alias_parts = explode( '/', trim( $no_prefix, '/' ) );
				// If the alias is the category we would already have that because of " wp option get permalink_structure %category%/%postname%/".
				// If there are more than 2 parts, e.g. /issues/123/post-name, then it's OK to create the alias.
				if ( ! in_array( $alias_parts[0], $categories, true ) || count( $alias_parts ) > 2 ) {
					$this->redirection_logic->create_redirection_rule(
						'Plone ID ' . $article->UID,
						$no_prefix,
						"/?p=$post_id"
					);
					$this->logger->log( $log_file, sprintf( 'Created redirect on post ID %d for %s', $post_id, $home_url . $no_prefix ), Logger::SUCCESS );
				}
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator hcn-generate-redirects-csv`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function hcn_generate_redirects_csv( $args, $assoc_args ) {
		$articles_list    = json_decode( file_get_contents( $assoc_args['articles-json'] ), true );
		$posts_per_batch  = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 1000;
		$batch            = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;
		$output_file_path = rtrim( $assoc_args['output-dir'], '/' ) . "/redirects-$batch.csv";

		$meta_query = [
			[
				'key'     => '_newspack_migrated_aliases',
				'compare' => 'NOT EXISTS',
			],
		];

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				// 'p'              => 118921,
				'post_status'    => 'any',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
				'meta_query'     => $meta_query, //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			]
		);

		$posts        = $query->get_posts();
		$redirections = [];

		foreach ( $posts as $post ) {
			$plone_article_uid      = get_post_meta( $post->ID, 'plone_article_UID', true );
			$original_article_index = array_search( $plone_article_uid, array_column( $articles_list, 'UID' ) );

			if ( false === $original_article_index ) {
				WP_CLI::warning( "Article UID $plone_article_uid not found in articles list: " . $post->ID );
				continue;
			}

			$original_article = $articles_list[ $original_article_index ];

			if ( array_key_exists( 'aliases', $original_article ) ) {
				foreach ( $original_article['aliases'] as $alias ) {
					$redirections[] = [
						'old_url' => $alias,
						'new_url' => str_replace( home_url(), '', get_permalink( $post->ID ) ),
					];
				}
			}

			update_post_meta( $post->ID, '_newspack_migrated_aliases', true );
		}

		if ( ! empty( $redirections ) ) {
			$fp = fopen( $output_file_path, 'w' );
			foreach ( $redirections as $redirection ) {
				fputcsv( $fp, $redirection );
			}
			fclose( $fp );

			WP_CLI::success( "Redirects CSV file generated: $output_file_path" );
		}
	}

	/**
	 * Helper to get post ID from the Plone ID.
	 *
	 * @param string $uid Plone ID
	 *
	 * @return int Post ID or 0 if we couldn't find it.
	 */
	private function get_post_id_from_uid( string $uid ): int {
		global $wpdb;
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_article_UID' AND meta_value = %s",
				$uid
			)
		);
		if ( ! empty( $post_id ) ) {
			return (int) $post_id;
		}

		return 0;
	}

	/**
	 * Get category ID if it exists, otherwise create it.
	 *
	 * @param string $category_name Category name to get/create.
	 * @param int    $parent_id     Parent category ID.
	 * @return int  Category ID.
	 */
	private function get_or_create_category( $category_name, $parent_id = 0 ) {
		$category_id = get_cat_ID( $category_name );

		if ( ! $category_id ) {
			// Add category.
			$category_id = wp_insert_category(
				[
					'cat_name'        => $category_name,
					'taxonomy'        => 'category',
					'category_parent' => $parent_id,
				]
			);
		}

		return $category_id;
	}

	/**
	 * Get attachment ID from issue image.
	 *
	 * @param array  $image_data Image data ['blob_path', 'filename'].
	 * @param string $blob_path Blob path.
	 * @return int|\WP_error attachment ID.
	 */
	private function get_attachment_id_from_issue_image( $image_data, $blob_path ) {
		$filename                  = $image_data['filename'];
		$tmp_destination_file_path = WP_CONTENT_DIR . '/uploads/' . $filename;
		$file_blob_path            = $blob_path . $image_data['blob_path'];
		if ( ! file_exists( $file_blob_path ) ) {
			return new \WP_Error( 'file_not_found', sprintf( 'File %s not found', $file_blob_path ) );
		}

		file_put_contents( $tmp_destination_file_path, file_get_contents( $file_blob_path ) );

		$attachment_id = $this->attachments->import_external_file( $tmp_destination_file_path, $image_data['filename'] );

		wp_delete_file( $tmp_destination_file_path );
		return $attachment_id;
	}

	private function replace_weird_chars( $string ): string {
		return strtr(
			$string,
			[
				'“' => '"',
				'”' => '"',
				'‘' => "'",
				'’' => "'",
				'…' => '...',
				'―' => '-',
				'—' => '-',
				'–' => '-',
				' ' => ' ',
			]
		);
	}

	private function remove_attributes( DOMElement $element, $level = "\t" ) {
		// echo "{$level}Removing attributes from $element->nodeName\n";
		if ( 'blockquote' === $element->nodeName ) {
			$class = $element->getAttribute( 'class' );
			if ( str_contains( $class, 'instagram-media' ) ) {
				return;
			}
		}

		$attribute_names = [];
		foreach ( $element->attributes as $attribute ) {
			$attribute_names[] = $attribute->name;
		}

		foreach ( $attribute_names as $attribute_name ) {
			if ( ! in_array( $attribute_name, [ 'src', 'href', 'title', 'alt', 'target' ] ) ) {
				$element->removeAttribute( $attribute_name );
			}
		}

		foreach ( $element->childNodes as $child ) {
			$level .= "\t";
			// echo "{$level}Child: $child->nodeName\n";
			if ( $child instanceof DOMElement ) {
				$this->remove_attributes( $child, $level );
			}
		}
	}

	private function inner_html( DOMElement $element ) {
		$inner_html = '';

		$doc = $element->ownerDocument;

		foreach ( $element->childNodes as $node ) {

			if ( $node instanceof DOMElement ) {
				if ( $node->childNodes->length > 1 && ! in_array( $node->nodeName, [ 'a', 'em', 'strong' ] ) ) {
					$inner_html .= $this->inner_html( $node );
				} elseif ( 'a' === $node->nodeName ) {
					$html = $doc->saveHTML( $node );

					if ( $node->previousSibling && '#text' === $node->previousSibling->nodeName ) {
						$html = " $html";
					}

					if ( $node->nextSibling && '#text' === $node->nextSibling->nodeName ) {
						$text_content    = trim( $node->nextSibling->textContent );
						$first_character = substr( $text_content, 0, 1 );

						if ( ! in_array( $first_character, [ '.', ':' ] ) ) {
							$html = "$html ";
						}
					}

					$inner_html .= $html;
				} else {
					$inner_html .= $doc->saveHTML( $node );
				}
			} else {

				if ( '#text' === $node->nodeName ) {
					$text_content = $node->textContent;

					if ( $node->previousSibling && 'a' == $node->previousSibling->nodeName ) {
						$text_content = ltrim( $text_content );
					}

					if ( $node->nextSibling && 'a' == $node->nextSibling->nodeName ) {
						$text_content = rtrim( $text_content );
					}

					// If this text is surrounded on both ends by links, probably doesn't need any page breaks in between text
					// Also removing page breaks if the parent element is a <p> tag
					if (
						( $node->previousSibling && $node->nextSibling && 'a' == $node->previousSibling->nodeName && 'a' == $node->nextSibling->nodeName ) ||
						'p' === $element->nodeName
					) {
						$text_content = preg_replace( '/\s+/', ' ', $text_content );
					}

					$inner_html .= $text_content;
				} else {
					$inner_html .= $doc->saveHTML( $node );
				}
			}
		}

		if ( 'p' === $element->nodeName && ! empty( $inner_html ) ) {
			if ( $element->hasAttributes() && 'post-aside' === $element->getAttribute( 'class' ) ) {
				return '<p class="post-aside">' . $inner_html . '</p>';
			}

			return '<p>' . $inner_html . '</p>';
		}

		return $inner_html;
	}

	/**
	 * Get attachment ID from media URL.
	 *
	 * @param string $url Media URL.
	 * @return int|false attachment ID.
	 */
	private function get_attachment_id_from_url( $url ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
				'%' . $url . '%'
			)
		);

		return $attachment_id;
	}
}
