<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;


use DateTime;
use DateTimeZone;
use DOMDocument;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Logic\Redirection as RedirectionLogic;
use NewspackCustomContentMigrator\Logic\Taxonomy;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use NewspackCustomContentMigrator\Utils\JsonIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
use WP_CLI;
use WP_User;

class HighCountryNewsMigrator2 implements InterfaceCommand {

	/**
	 * HighCountryNewsMigrator Instance.
	 *
	 * @var HighCountryNewsMigrator
	 */
	private static $instance;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private CoAuthorPlus $coauthorsplus_logic;

	/**
	 * @var Redirection $redirection
	 */
	private RedirectionLogic $redirection;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * @var GutenbergBlockGenerator.
	 */
	private GutenbergBlockGenerator $gutenberg_block_generator;

	/**
	 * @var JsonIterator
	 */
	private JsonIterator $json_iterator;

	/**
	 * @var RedirectionLogic
	 */
	private RedirectionLogic $redirection_logic;

	/**
	 * @var Taxonomy
	 */
	private Taxonomy $taxonomy_logic;

	/**
	 * Instance of Attachments Login
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	private $articles_json_arg = [
		'type'        => 'assoc',
		'name'        => 'articles-json',
		'description' => 'Path to the articles JSON file.',
		'optional'    => false,
	];

	private array $images_json_arg = [
		'type'        => 'assoc',
		'name'        => 'images-json',
		'description' => 'Path to the images JSON file.',
		'optional'    => false,
	];

	private array $issues_json_arg = [
		'type'        => 'assoc',
		'name'        => 'issues-json',
		'description' => 'Path to the issues JSON file.',
		'optional'    => false,
	];

	private array $blobs_path_arg = [
		'type'        => 'assoc',
		'name'        => 'blobs-path',
		'description' => 'Path to the blobs directory.',
		'optional'    => false,
	];

	private array $users_json_arg = [
		'type'        => 'assoc',
		'name'        => 'users-json',
		'description' => 'Path to the users JSON file.',
		'optional'    => false,
	];

	private array $num_items_arg = [
		'type'        => 'assoc',
		'name'        => 'num-items',
		'description' => 'Number of items to process',
		'optional'    => true,
	];

	const MAX_POST_ID_FROM_STAGING = 185173; // SELECT max(ID) FROM wp_posts on staging.

	const PARENT_PAGE_FOR_ISSUES = 180504; // The page that all issues will have as parent.
	const DEFAULT_AUTHOR_ID = 223746; // User ID of default author.
	const TAG_ID_THE_MAGAZINE = 7640; // All issues will have this tag to create a neat looking page at /topic/the-magazine.
	const CATEGORY_ID_ISSUES = 385;

	private DateTimeZone $site_timezone;

	private function __construct() {
		// Do nothing.
	}

	/**
	 * Get Instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {

		if ( null === self::$instance ) {
			self::$instance                            = new self();
			self::$instance->coauthorsplus_logic       = new CoAuthorPlus();
			self::$instance->redirection               = new Redirection();
			self::$instance->logger                    = new Logger();
			self::$instance->gutenberg_block_generator = new GutenbergBlockGenerator();
			self::$instance->attachments               = new Attachments();
			self::$instance->json_iterator             = new JsonIterator();
			self::$instance->redirection_logic         = new RedirectionLogic();
			self::$instance->taxonomy_logic            = new Taxonomy();
			self::$instance->site_timezone             = new DateTimeZone( 'America/Denver' );
		}

		return self::$instance;
	}

	public function register_commands(): void {
		$this->set_fixes_on_existing_command();
		$this->set_importer_commands();
		$this->set_fixes_command();
	}

	/**
	 * Commands here need to be run on the already migrated content on staging.
	 */
	private function set_fixes_on_existing_command(): void {

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-wp-related-links',
			[ $this, 'fix_wp_related_links' ],
			[
				'shortdesc' => 'Fixes existing related link paths.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-urls',
			[ $this, 'fix_post_urls' ],
			[
				'shortdesc' => 'Fix post names (slugs).',
				'synopsis'  => [
					$this->articles_json_arg,
					$this->num_items_arg,
					...BatchLogic::get_batch_args(),
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-redirects',
			array( $this, 'fix_redirects' ),
			array(
				'shortdesc' => 'Fix existing redirects.',
				'synopsis'  => array(
					[
						$this->articles_json_arg,
						...BatchLogic::get_batch_args(),
					],
				),
			)
		);
	}

	/**
	 * Import new data.
	 */
	private function set_importer_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-users-from-json',
			[ $this, 'import_users_from_json' ],
			[
				'shortdesc' => 'Migrate users from JSON data.',
				'synopsis'  => [
					$this->users_json_arg,
					...BatchLogic::get_batch_args(),
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-images-from-json',
			[ $this, 'migrate_images_from_json' ],
			[
				'shortdesc' => 'Migrate images from JSON data.',
				'synopsis'  => [
					$this->images_json_arg,
					$this->blobs_path_arg,
					...BatchLogic::get_batch_args(),
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-import-issues-as-pages',
			[ $this, 'import_issues_as_pages' ],
			[
				'synopsis'  => [
					$this->issues_json_arg,
					$this->articles_json_arg,
					$this->blobs_path_arg,
					...BatchLogic::get_batch_args(),
				],
				'shortdesc' => 'Import issues as pages and clean up issue categories.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hcn-migrate-articles-from-json',
			[ $this, 'migrate_articles_from_json' ],
			[
				'shortdesc' => 'Migrate articles from JSON data.',
				'synopsis'  => [
					$this->articles_json_arg,
					...BatchLogic::get_batch_args(),
				],
			]
		);
	}

	/**
	 * These need to be run after a new import.
	 */
	private function set_fixes_command(): void {

		WP_CLI::add_command(
			'newspack-content-migrator hcn-fix-related-links-from-json',
			[ $this, 'fix_related_links_from_json' ],
			[
				'shortdesc' => 'Massages related links coming from the JSON',
				'synopsis'  => [
					$this->articles_json_arg,
				],
			]
		);
	}

	/**
	 * Callback for the command hcn-generate-redirects.
	 */
	public function fix_redirects( array $args, array $assoc_args ): void {
		$log_file = __FUNCTION__ . '.log';
		$home_url = home_url();
		$articles_json_file   = $assoc_args[ $this->articles_json_arg['name'] ];

		$categories = get_categories( [
			'fields' => 'slugs',
		] );

		global $wpdb;


		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $articles_json_file, $assoc_args );
		$counter     = 0;
		foreach ( $this->json_iterator->batched_items( $articles_json_file, $batch_args['start'], $batch_args['end'] ) as $article ) {
			WP_CLI::log( sprintf( 'Processing article (%d of %d)', $counter++, $batch_args['total'] ) );

			if ( empty( $article->aliases ) ) {
				continue;
			}

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE ID <= %d AND meta_key = 'plone_article_UID' and meta_value = %s",
					self::MAX_POST_ID_FROM_STAGING,
					$article->UID
				)
			);
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
						wp_get_shortlink( $post_id, 'post', false ),
					);
					$this->logger->log( $log_file, sprintf( 'Created redirect on post ID %d for %s', $post_id, $home_url . $no_prefix ), Logger::SUCCESS );
				}
			}
		}
	}

	public function fix_post_urls( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";
		$articles_json_file   = $assoc_args[ $this->articles_json_arg['name'] ];

		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $articles_json_file, $assoc_args );
		$counter     = 0;
		foreach ( $this->json_iterator->batched_items( $articles_json_file, $batch_args['start'], $batch_args['end'] ) as $article ) {
			WP_CLI::log( sprintf( 'Processing article (%d of %d)', $counter++, $batch_args['total'] ) );

			$post_id = $this->get_post_id_from_uid( $article->UID );
			if ( ! empty( $post_id ) && MigrationMeta::get( $post_id, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post_id ), $command_meta_version ) );
				continue;
			}

			if ( $post_id > self::MAX_POST_ID_FROM_STAGING ) {
				$this->logger->log( sprintf( "Post ID %d is greater MAX ID (%d), skipping", $post_id, self::MAX_POST_ID_FROM_STAGING ), Logger::WARNING );
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				$this->logger->log( sprintf( "Didn't find a post for: %s", $article->{'@id'} ), Logger::WARNING );
				continue;
			}
			if ( $post->post_status !== 'publish' ) {
				MigrationMeta::update( $post_id, $command_meta_key, 'post', $command_meta_version );
				continue;
			}

			$current_path = trim( parse_url( get_permalink( $post->ID ), PHP_URL_PATH ), '/' );

			$original_path = trim( parse_url( $article->{'@id'}, PHP_URL_PATH ), '/' );
			if ( 0 !== substr_count( $original_path, '/' ) ) {
				$this->set_categories_on_post_from_path( $post, $original_path );
			}

			$correct_post_name = $this->get_post_name( $article->{'@id'} );
			if ( $correct_post_name !== $post->post_name ) {
				wp_update_post( [
					'ID'        => $post_id,
					'post_name' => $correct_post_name
				] );
			}

			MigrationMeta::update( $post_id, $command_meta_key, 'post', $command_meta_version );

			$original_path  = trim( parse_url( $article->{'@id'}, PHP_URL_PATH ), '/' );
			$post_permalink = get_permalink( $post_id );
			$wp_path        = trim( parse_url( $post_permalink, PHP_URL_PATH ), '/' );
			if ( $wp_path !== mb_strtolower( $original_path ) ) {
				$this->logger->log(
					$log_file,
					sprintf(
						"Changed post path from:\n %s \n %s\n on %s ",
						$current_path,
						$wp_path,
						wp_get_shortlink( $post_id, 'post', false ),
					),
					Logger::SUCCESS
				);
			} else {
				$this->logger->log( $log_file, sprintf( 'Postname was fine on: %s, not updating it', $post_permalink ) );
			}
		}
	}

	public function fix_related_links_from_json( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";
		$articles_json_file   = $assoc_args[ $this->articles_json_arg['name'] ];


		$article_url_and_uid = [];
		foreach ( json_decode( file_get_contents( $articles_json_file ), true ) as $article ) {
			$article_url_and_uid[ parse_url( $article['@id'], PHP_URL_PATH ) ] = $article['UID'];
		}

		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_content FROM $wpdb->posts WHERE post_type = 'post' AND post_content LIKE '%[RELATED:%'"
		);

		foreach ( $posts as $post ) {
			if ( ! empty( $post_id ) && MigrationMeta::get( $post_id, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post_id ), $command_meta_version ) );
				continue;
			}

			preg_match_all( '/\[RELATED:(.*?)\]/', $post->post_content, $matches, PREG_SET_ORDER );

			$update       = false;
			$post_content = $post->post_content;
			foreach ( $matches as $match ) {
				$path = parse_url( $match[1], PHP_URL_PATH );
				if ( empty( $article_url_and_uid[ $path ] ) ) {
					$this->logger->log( $log_file, sprintf( 'Could not find UID for %s', $match[1] ), Logger::WARNING );
					continue;
				}

				$linked_post_id = $this->get_post_id_from_uid( $article_url_and_uid[ $path ] );
				if ( ! $linked_post_id ) {
					$this->logger->log( $log_file, sprintf( 'Could not find post from UID for %s', $match[1] ), Logger::WARNING );
					continue;
				}


				$update      = true;
				$replacement = $this->get_related_link_markup( $linked_post_id );

				$post_content = str_replace( $match[0], $replacement, $post_content );

			}
			if ( $update ) {
				$result = wp_update_post( [
					'ID'           => $post->ID,
					'post_content' => $post_content,
				] );

				if ( $result ) {
					$this->logger->log( $log_file, sprintf( 'Updated wp links in "RELATED" on %s', get_permalink( $post->ID ) ), Logger::SUCCESS );
				}
			}
			MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
		}
	}


	/**
	 * This one fixes the related links that were already changed to wp permalinks with paths that were not working.
	 */
	public function fix_wp_related_links( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;
		$log_file             = "{$command_meta_key}_$command_meta_version.log";

		global $wpdb;

		$post_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID <= %d AND post_type = 'post' AND post_content LIKE '%<strong>RELATED:%'",
				self::MAX_POST_ID_FROM_STAGING
			)
		);

		$total_posts = count( $post_ids );
		$counter     = 0;

		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( 'Processing %s of %s with post id: %d', $counter++, $total_posts, $post_id ) );

			if ( ! empty( $post_id ) && MigrationMeta::get( $post_id, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::warning( sprintf( '%s is at MigrationMeta version %s, skipping', get_permalink( $post_id ), $command_meta_version ) );
				continue;
			}

			$post         = get_post( $post_id );
			$update       = false;
			$post_content = $post->post_content;
			preg_match_all( '@<p><strong>RELATED:</strong>.*href=[\'"]([^ ]*)[\'"].*</p>@', $post_content, $matches, PREG_SET_ORDER );

			if ( empty( $matches ) ) {
				$this->logger->log( $log_file, sprintf( 'Post seems to have wrong format related link %s', get_permalink( $post_id ) ), Logger::ERROR );
			}

			foreach ( $matches as $match ) {
				$linked_url_post_name = trim( basename( $match[1] ), '/' );
				$post_linked_to       = get_page_by_path( $linked_url_post_name, OBJECT, 'post' );
				if ( ! $post_linked_to ) {
					$this->logger->log( $log_file, sprintf( 'Could not find post for %s', $match[1] ), Logger::WARNING );
					continue;
				}

				$update      = true;
				$replacement = $this->get_related_link_markup( $post_linked_to->ID );

				$post_content = str_replace( $match[0], $replacement, $post_content );
			}

			if ( $update ) {
				$result = wp_update_post( [
					'ID'           => $post_id,
					'post_content' => $post_content,
				] );

				if ( $result ) {
					$this->logger->log( $log_file, sprintf( 'Updated wp links in "RELATED" on %s', get_permalink( $post_id ) ), Logger::SUCCESS );
				}
			}

			MigrationMeta::update( $post_id, $command_meta_key, 'post', $command_meta_version );
		}
	}

	/**
	 * Import users
	 */
	public function import_users_from_json( array $args, array $assoc_args ): void {
		$file_path  = $assoc_args[ $this->users_json_arg['name'] ];
		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $file_path, $assoc_args );

		$row_number = 0;
		foreach ( $this->json_iterator->batched_items( $file_path, $batch_args['start'], $batch_args['end'] ) as $row ) {
			$row_number ++;
			WP_CLI::log( sprintf( 'Processing row %d of %d: %s', $row_number, $batch_args['total'], $row->username ) );

			if ( empty( $row->email ) ) {
				continue; // Nope. No email, no user.
			}

			$date_created = new DateTime( 'now', $this->site_timezone );

			if ( ! empty( $row->date_created ) ) {
				$date_created = DateTime::createFromFormat( 'm-d-Y_H:i', $row->date_created, $this->site_timezone );
			}

			$nicename = $row->fullname ?? ( $row->first_name ?? '' . ' ' . $row->last_name ?? '' );

			$result = wp_insert_user(
				[
					'user_login'      => $row->username,
					'user_pass'       => wp_generate_password(),
					'user_email'      => $row->email,
					'display_name'    => $row->fullname,
					'first_name'      => $row->first_name,
					'last_name'       => $row->last_name,
					'user_registered' => $date_created->format( 'Y-m-d H:i:s' ),
					'role'            => 'subscriber', // Set this role on all users. We change it to author later if they have content.
					'user_nicename'   => $nicename,
				]
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::log( $result->get_error_message() );
			} else {
				WP_CLI::success( "User $row->email created." );
			}
		}
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
	 * @throws Exception
	 */
	public function import_issues_as_pages( array $args, array $assoc_args ): void {

		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 2;
		$log_file             = "{$command_meta_key}_{$command_meta_version}.log";
		$articles_json        = $assoc_args[ $this->articles_json_arg['name'] ];
		$issues_json          = $assoc_args[ $this->issues_json_arg['name'] ];
		$blobs_folder         = trailingslashit( realpath( $assoc_args[ $this->blobs_path_arg['name'] ] ) );

		$pdfurls_array = $this->get_pdf_urls_from_json( $articles_json );
		if ( empty( $pdfurls_array ) ) {
			WP_CLI::error( sprintf( 'Could not find any PDF urls in %s', $articles_json ) );
		}

		global $wpdb;

		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $issues_json, $assoc_args );
		$row_number = 0;
		foreach ( $this->json_iterator->batched_items( $issues_json, $batch_args['start'], $batch_args['end'] ) as $issue ) {
			WP_CLI::log( sprintf( 'Processing issue (%d of %d): %s', ++ $row_number, $batch_args['total'], $issue->{'@id'} ) );

			$slug       = substr( $issue->{'@id'}, strrpos( $issue->{'@id'}, '/' ) + 1 );
			$post_date  = new DateTime( ( $issue->effective ?? $issue->created ), $this->site_timezone );
			$issue_name = $post_date->format( 'F j, Y' ) . ': ' . $issue->title;

			$cat = get_category_by_slug( $slug );
			if ( ! $cat ) {
				$id = wp_insert_category( [
					'cat_name'          => $slug,
					'category_nicename' => $issue_name,
					'category_parent'   => self::CATEGORY_ID_ISSUES
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
				'post_author'   => self::DEFAULT_AUTHOR_ID, // We don't bother finding an author – it's not displayed on the current live site either.
				'post_type'     => 'page',
				'post_parent'   => self::PARENT_PAGE_FOR_ISSUES,
				'post_category' => [ $cat->term_id, self::CATEGORY_ID_ISSUES ],
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
				$blob_file_path      = $blobs_folder . $issue->image->blob_path;
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
			wp_set_post_tags( $post_id, [ self::TAG_ID_THE_MAGAZINE ], true );
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

	public function migrate_images_from_json( array $args, array $assoc_args ): void {
		$log_file   = __FUNCTION__ . '.log';
		$file_path  = $assoc_args[ $this->images_json_arg['name'] ];
		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $file_path, $assoc_args );
		$blobs_path = untrailingslashit( $assoc_args[ $this->blobs_path_arg['name'] ]);

		global $wpdb;
		$media_lib_search_url = home_url() . '/wp-admin/upload.php?search=%s';
		$row_number           = 0;
		foreach ( $this->json_iterator->batched_items( $file_path, $batch_args['start'], $batch_args['end'] ) as $row ) {
			WP_CLI::log( sprintf( 'Processing row %d of %d: %s', $row_number ++, $batch_args['total'], $row->{'@id'} ) );

			// TODO. We can maybe exclude images ending in '-thumb.jpg' if we have the original. No need to import scaled images.
			if ( empty( $row->image->filename ) ) {
				// TODO. There are some "legacyPath" images. They seem to point at empty urls, but let's get back to this.
				continue;
			}

			$existing_id = $this->get_attachment_id_by_uid( $row->UID );
			if ( $existing_id ) {
				$this->logger->log( $log_file, sprintf( 'Image already imported to %s)', get_permalink( $existing_id ) ), Logger::WARNING );
				continue;
			}

			$post_author = self::DEFAULT_AUTHOR_ID;

			if ( ! empty( $row->creators[0] ) ) {
				$user = get_user_by( 'login', $row->creators[0] );

				if ( $user ) {
					$this->upgrade_user_to_author( $user->ID );
					$post_author = $user->ID;
				}
			}

			$created_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row->created, $this->site_timezone );
			$updated_at = DateTime::createFromFormat( 'Y-m-d\TH:m:sP', $row->modified, $this->site_timezone );

			$img_post_data = [
				'post_author'   => $post_author,
				'post_date'     => $created_at->format( 'Y-m-d H:i:s' ),
				'post_modified' => $updated_at->format( 'Y-m-d H:i:s' ),
				'meta_input'    => [
					'plone_image_UID'   => $row->UID,
					'_media_credit'     => $row->credit ?? '',
					'_media_credit_url' => $row->creditUrl ?? '',
				],
			];

			$attachment_id = $this->attachments->import_external_file(
				$blobs_path . '/' . $row->image->blob_path,
				$row->image->filename,
				$row->description ?? '',
				$row->description ?? '',
				$row->description ?? '',
				0,
				$img_post_data,
				$row->image->filename,
			);

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log( $log_file, $attachment_id->get_error_message(), Logger::ERROR );
			} else {
				$media_lib_url = sprintf(
					$media_lib_search_url,
					$row->image->filename
				);
				$this->logger->log(
					$log_file,
					sprintf( 'Image imported to %s (%s)', $media_lib_url, $row->{'@id'} ),
					Logger::SUCCESS );
			}
		}
	}

	/**
	 * Migrate articles from JSON.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws Exception
	 */
	public function migrate_articles_from_json( array $args, array $assoc_args ): void {
		$log_file   = __FUNCTION__ . '.log';
		$file_path  = $assoc_args[ $this->articles_json_arg['name'] ];
		$batch_args = $this->json_iterator->validate_and_get_batch_args_for_json_file( $file_path, $assoc_args );

		$row_number = 0;
		/**
		 * @var object $row
		 */
		foreach ( $this->json_iterator->batched_items( $file_path, $batch_args['start'], $batch_args['end'] ) as $row ) {
			WP_CLI::log( sprintf( 'Article %d of %d: %s', $row_number ++, $batch_args['total'], $row->{'@id'} ) );
			if ( empty( $row->text ) ) {
				$this->logger->log( $log_file, sprintf( 'Article %s has no text. Skipping', $row->{'@id'} ), Logger::WARNING );
			}

			$post_date_string     = $row->effective ?? $row->created;
			$post_modified_string = $row->modified ?? $post_date_string;
			$post_date            = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_date_string, $this->site_timezone );
			$post_modified        = DateTime::createFromFormat( 'Y-m-d\TH:i:sP', $post_modified_string, $this->site_timezone );

			$post_data = [
				'post_title'    => $row->title,
				'post_status'   => ( 'public' === $row->review_state ) ? 'publish' : 'draft',
				'post_date'     => $post_date->format( 'Y-m-d H:i:s' ),
				'post_modified' => $post_modified->format( 'Y-m-d H:i:s' ),
				'meta_input'    => [
					'plone_article_UID'      => $row->UID,
					'newspack_post_subtitle' => $row->subheadline ?? '',
				],
			];

			// TODO. Set categories when we have the post id.
			// TODO. Don't import the "Download entire issue articles".
			// TODO. Hardcode some stuff: see hcn_migrate_headlines()
			$path = parse_url( $row->{'@id'}, PHP_URL_PATH );
			// Author Section.
			$author_id = self::DEFAULT_AUTHOR_ID;

			if ( ! empty( $row->creators ) ) {
				$author_by_login = get_user_by( 'login', $row->creators[0] );

				if ( $author_by_login instanceof WP_User ) {
					$author_id = $author_by_login->ID;
				}
			}
			$post_data['post_author'] = $author_id;

			$content = $row->text;
			$article_layout = $row->layout ?? '';

			// Featured image.
			if ( ! empty( $row->image ) ) {
				$filename  = $row->image->filename;
				$path_info = pathinfo( $filename );
				if ( str_ends_with( $path_info['filename'], '-thumb' ) ) {
					$filename_without_extension = str_replace( '-thumb', '', $path_info['filename'] );
					$filename                   = $filename_without_extension . '.' . $path_info['extension'];
				}
				$attachment_id = $this->attachments->get_attachment_by_filename( $filename );

				if ( $attachment_id ) {
					$post_data['meta_input']['_thumbnail_id'] = $attachment_id;
				} else {
					// TODO: Maybe just grab the image provided
				}

				$featured_image_position = 'fullwidth_article_view' === $article_layout ? 'above' : 'hidden';
				$post_data['meta_input']['newspack_featured_image_position'] = $featured_image_position;
			}
			// Gallery: Test with https://www.hcn.org/issues/52.2/military-only-in-death-do-some-deported-veterans-return-home

			$content = $this->replace_img_tags_with_img_blocks( $content );
			$content = wp_kses_post( $row->intro ) . $content;
		}
	}

	private function inject_slideshow( string $content, object $row ): string {

	}

	private function replace_img_tags_with_img_blocks( string $content ): string {
		$dom = new DOMDocument( '1.0', 'utf-8' );
		@$dom->loadHTML( $content );

		$img_tags = $dom->getElementsByTagName( 'img' );

		foreach ( $img_tags as $img_tag ) {
			$src = trim( $img_tag->getAttribute( 'src' ) );
			// src looks something like this: resolveuid/0d12bbf8d16acc061685083060b81610/@@images/image/mini
			if ( ! preg_match( '@^resolveuid/([0-9a-z]+)/?@', $src, $matches ) ) {
				continue;
			}
			$attachment_id = $this->get_attachment_id_by_uid( $matches[1] );
			if ( ! $attachment_id ) {
				continue;
			}

			$img_post = get_post( $attachment_id );
			if ( ! $img_post ) {
				continue;
			}
			$original_img_html = $dom->saveHTML( $img_tag );
			$img_block         = serialize_block( $this->gutenberg_block_generator->get_image( $img_post ) );
			$content           = str_replace( $original_img_html, $img_block, $content );
		}

		return $content;
	}

	private function get_post_name( string $url ): string {
		$path_parts = explode( '/', trim( parse_url( $url, PHP_URL_PATH ), '/' ) );
		$post_name  = array_pop( $path_parts );

		return sanitize_title( $post_name );
	}

	private function set_categories_on_post_from_path( \WP_Post $post, string $original_path ): void {
		$path_parts = explode( '/', $original_path );
		// Pop the last part, which is the post name, so the path is only categories.
		array_pop( $path_parts );
		if ( empty( $path_parts ) ) {
			// This post only has one part to the url. E.g. site.com/about.
			return;
		}

		if ( $path_parts[0] === 'articles' ) {
			$path_parts = array_slice( $path_parts, 0, 1 );
		} elseif ( count( $path_parts ) > 2 ) {
			// We pop a part off if there are more than 2 parts to the path (after having popped of the post name). For example:
			// issues/43.10/how-developers-and-businessmen-cash-in-on-grand-canyon-overflights/park-service-finally-drafts-a-solution-to-conflicts-over-canyon-flights
			// we remove the part after "43.10".
			$path_parts = array_slice( $path_parts, 0, 2 );
		}

		$post_categories      = array_map( fn( $cat ) => $cat->term_id, get_the_category( $post->ID ) );
		$categories_from_path = [];
		$parent               = 0;
		foreach ( $path_parts as $part ) {
			$cat_id = false;
			$cat    = get_category_by_slug( $part );
			if ( $cat ) {
				$cat_id = $cat->term_id;
			}
			if ( ! $cat_id ) {
				$cat_id = wp_insert_category(
					[
						'cat_name'          => ucfirst( str_replace( [ '-', '_' ], ' ', $part ) ),
						'category_nicename' => $part,
						'category_parent'   => $parent,
					]
				);
			}
			$categories_from_path[] = $cat_id;
			$parent                 = $cat_id;
		}

		if ( $post_categories !== $categories_from_path ) {
			wp_set_post_categories( $post->ID, $categories_from_path );
		}
		if ( count( $categories_from_path ) > 1 ) {
			// Set the last item in the path as primary to keep the url structure.
			update_post_meta( $post->ID, '_yoast_wpseo_primary_category', end( $categories_from_path ) );
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
		if ( ! file_exists( $articles_json_file_path ) ) {
			throw new Exception( sprintf( 'The file supplied is either not a file or does not exist: %s', $articles_json_file_path ) );
		}

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
	 * Tries various tricks to get the PDF attachment ID for an issue.
	 *
	 * @param int    $post_id The post ID to attach the PDF to.
	 * @param object $issue The issue object.
	 * @param array  $pdfurls array of UID => pdfurl. See get_pdf_urls_from_json().
	 *
	 * @return int The attachment ID or 0 if not found.
	 * @throws \Exception
	 */
	private function get_issue_pdf_attachment_id( int $post_id, object $issue, array $pdfurls ): int {
		if ( array_key_exists( $issue->UID ?? '', $pdfurls ) ) {
			$pdf_url           = 'https://s3.amazonaws.com/hcn-media/archive-pdf/' . $pdfurls[ $issue->UID ];
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
				$effective_date = new DateTime( $issue->effective, $this->site_timezone );
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

	/**
	 * Builds block content for an issue page.
	 *
	 * @param int    $category_id
	 * @param string $description
	 * @param int    $image_attachment_id
	 * @param int    $pdf_id
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
			$link                  = wp_get_attachment_url( $pdf_id );
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

	private function upgrade_user_to_author( $user_id ): bool {
		$user = get_userdata( $user_id );
		$user->set_role( 'author' );

		return ! is_wp_error( wp_update_user( $user ) );
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

	private function get_attachment_id_by_uid( string $uid ): int {
		global $wpdb;
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'plone_image_UID' AND meta_value = %s",
				$uid
			)
		);

		return empty( $attachment_id ) ? 0 : (int) $attachment_id;
	}

	private function get_related_link_markup( int $post_id ): string {
		$permalink  = wp_get_shortlink( $post_id, 'post', false );
		$post_title = get_the_title( $post_id );

		return <<<HTML
<p class="hcn-related"><span class="hcn-related__label">Related:</span> <a href="$permalink" class="hcn-related__link" target="_blank">$post_title</a></p>
HTML;
	}

	/**
	 * Quick lo-tech author parser.
	 *
	 * @return array
	 */
	private function parse_author_string( string $authors ) {
		$bad     = [
			'MD'
		];
		$strip   = [
			'with additional reporting by',
			'based on information provided by High Country News',
			'Updated by'
		];
		$authors = str_replace( '\n', '', $authors );

		$good = [];
		// Split by , and &.
		foreach ( preg_split( '/(,|&|(and))/', $authors ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( empty( $candidate ) || is_numeric( $candidate ) || in_array( $candidate, $bad ) ) {
				continue;
			}
			foreach ( $strip as $strip_candidate ) {
				$candidate = str_replace( $strip_candidate, '', $candidate );
			}
			if ( ! empty( $candidate ) ) {
				$good[] = trim( trim( $candidate, '.' ) );
			}
		}

		return $good;
	}
}
