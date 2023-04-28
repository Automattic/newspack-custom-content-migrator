<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Utils\Logger;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\Posts;
use WP_CLI;

/**
 * Custom migration scripts for Soccer America.
 */
class SoccerAmericaMigrator implements InterfaceCommand {

	public const META_PREFIX = 'newspack_sa_';

	/**
	 * Instance of RetroReportMigrator
	 *
	 * @var null|InterfaceCommand
	 */
	private static $instance = null;

	/**
	 * @var Logger.
	 */
	private $logger;

	/**
	 * Attachments logic.
	 *
	 * @var null|Attachments
	 */
	private $attachments;

	/**
	 * Dry run mode - set to true to prevent changes.
	 *
	 * @var bool
	 */
	private $dryrun;

	/**
	 * Mapping of core post fields to CSV fields.
	 *
	 * @var array
	 */
	private $core_fields_mapping;

	/**
	 * Mapping of post meta fields to CSV fields.
	 *
	 * @var array
	 */
	private $meta_fields_mapping;

	/**
	 * Mapping of comment fields to CSV fields.
	 *
	 * @var array
	 */
	private $comment_fields_mapping;

	/**
	 * List of callable functions for each imported field.
	 *
	 * @var array
	 */
	private $content_formatters;

	/**
	 * List of CSV headers.
	 *
	 * @var array
	 */
	private $csv_headers;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();

		$this->attachments = new Attachments();

		// Define where each CSV field should import to (articles).
		$this->core_fields_mapping = [
			'article_slug' => 'post_name',
			'publish_date' => 'post_date_gmt',
			'headline'     => 'post_title',
			'subtitle'     => 'post_excerpt',
			'content'      => 'post_content',
		];

		// Define where each CSV field should import to (articles meta).
		$this->meta_fields_mapping = [
			'short_social' => '_yoast_wpseo_title',
		];

		// Define where each CSV field should import to (comments).
		$this->comment_fields_mapping = [
			'user_name'   => 'comment_author',
			'user_email'  => 'comment_author_email',
			'comment'     => 'comment_content',
			'submit_date' => 'comment_date_gmt',
		];

		// Set up the content formatters to process each field.
		$this->set_content_formatters();
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

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-import-articles',
			[ $this, 'cmd_import_articles' ],
			[
				'shortdesc' => 'Import articles from a Soccer America article CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-import-comments',
			[ $this, 'cmd_import_comments' ],
			[
				'shortdesc' => 'Import comments from a Soccer America comment CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-import-article-types',
			[ $this, 'cmd_import_article_types' ],
			[
				'shortdesc' => 'Import article types from a Soccer America article types CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'subtypes',
						'optional'    => true,
						'description' => 'Whether we are importing subtypes.',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-import-tags',
			[ $this, 'cmd_import_tags' ],
			[
				'shortdesc' => 'Import tags from a Soccer America tags CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-assign-tags',
			[ $this, 'cmd_assign_tags' ],
			[
				'shortdesc' => 'Assign tags to articles from a Soccer America tags CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-import-users',
			[ $this, 'cmd_import_users' ],
			[
				'shortdesc' => 'Import users from a Soccer America tags CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-assign-users',
			[ $this, 'cmd_assign_users' ],
			[
				'shortdesc' => 'Assign users to posts from a Soccer America tags CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-import-images',
			[ $this, 'cmd_import_images' ],
			[
				'shortdesc' => 'Import images from a Soccer America tags CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		\WP_CLI::add_command(
			'newspack-content-migrator soccer-america-assign-images',
			[ $this, 'cmd_assign_images' ],
			[
				'shortdesc' => 'Assign images to posts from a Soccer America tags CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator soccer-america-missing-articles',
			[ $this, 'cmd_missing_articles' ],
			[
				'shortdesc' => 'Import articles from a Soccer America tags CSV export.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'csv',
						'optional'    => false,
						'description' => 'The CSV export file location',
					],
					[
						'type'        => 'positional',
						'name'        => 'output',
						'optional'    => false,
						'description' => 'File to print missing articles to.',
					],
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator soccer-america-fix-missing-content',
			[ $this, 'cmd_fix_missing_content' ],
			[
				'shortdesc' => 'Fixes posts where content is missing.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'optional'    => true,
						'description' => 'Whether to do a dry-run without making updates.',
					],
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator soccer-america-helper-update-exisitng-user-displaynames',
			[ $this, 'cmd_helper__updateexisting_user_displaynames' ],
			[
				'shortdesc' => 'Updates existing Users and sets their display_name to be first name + last name.',
			]
		);

	}

	/**
	 * @param $args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_helper__updateexisting_user_displaynames( $args, $assoc_args ) {

		$log = 'socceram__updateexisting_user_displaynames.log';

		// Get all users.
		$users = get_users();
		foreach ( $users as $user ) {
			// Get user meta.
			$first_name = get_user_meta( $user->ID, 'first_name', true );
			$last_name  = get_user_meta( $user->ID, 'last_name', true );
			// If first name and last name are set, update display name.
			if ( $first_name && $last_name ) {
				$display_name = $first_name . ' ' . $last_name;
				$updated = wp_update_user(
					[
						'ID'           => $user->ID,
						'display_name' => $display_name,
					]
				);
				if ( is_wp_error( $updated ) ) {
					$this->logger->log( $log, 'Error updating user ' . $user->ID . ' ' . $user->data->user_login . ': ' . $updated->get_error_message(), 'warning' );
				}
			} else {
				$this->logger->log( $log, 'User ' . $user->ID . ' does not have first name and last name set.', 'warning' );
			}
		}

		wp_cache_flush();
		WP_CLI::success( 'Done!' );
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-missing-articles`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_missing_articles( $args, $assoc_args ) {
		$this->log( 'Checking for missing Soccer America articles...', 'line' );

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Finding missing articles', $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		global $wpdb;

		// Loop through the CSV rows.
		while ( $row = fgetcsv( $csv, null, "\t" ) ) {
			$progress->tick();

			$article_id = $this->get_field_from_row( 'article_id', $row );

			// Check if the article exists.
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '%s' AND meta_value = %s",
					self::META_PREFIX . 'article_id',
					$article_id
				)
			);

			// Only report missing articles in a way we can easily re-use.
			if ( is_null( $post_id ) ) {
				file_put_contents( $args[1], implode( "\t", $row ) . PHP_EOL, FILE_APPEND );
			}
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-import-articles`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_articles( $args, $assoc_args ) {
		$this->log( 'Importing Soccer America articles...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d articles', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {

			$progress->tick();

			// Don't attempt to re-import anything.
			$post_exists = $this->post_exists( $this->get_field_from_row( 'article_id', $row ) );
			if ( $post_exists ) {
				$this->log(
					sprintf(
						'Post with article_id %s already exists',
						$this->get_field_from_row( 'article_id', $row )
					)
				);
				continue;
			}

			// Import the post.
			$post_id = $this->import_post( $row );

			// Add the original data to the post as meta.
			if ( ! $this->dryrun ) {
				add_post_meta( $post_id, self::META_PREFIX . 'import_data', $row );
			}
		}

		$progress->finish();

	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-import-comments`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_comments( $args, $assoc_args ) {
		$this->log( 'Importing Soccer America comments...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d comments', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {

			$progress->tick();

			// There is no comment ID, so create our own.
			$original_comment_id = md5( json_encode( $row ) );

			$comment_exists = $this->comment_exists( $original_comment_id );
			if ( $comment_exists ) {
				$this->log(
					sprintf(
						'Comment article_id %s and user_id %s already exists',
						$this->get_field_from_row( 'article_id', $row ),
						$this->get_field_from_row( 'user_id', $row )
					)
				);
				continue;
			}

			// Import the comment.
			$this->import_comment( $row );
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-import-article-types`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_article_types( $args, $assoc_args ) {
		$this->log( 'Importing Soccer America article types...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Are we dealing with article types or SUB article types?
		// The misspellings here match the actual data.
		$type_header = ( array_key_exists( 'subtypes', $assoc_args ) ) ? 'articlet_subype_id' : 'article_type_id';
		$meta_key    = ( array_key_exists( 'subtypes', $assoc_args ) ) ? 'subtype_id' : 'type_id';

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d article types', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {

			$progress->tick();

			// Check if we've already imported this as a category.
			$category = ( $this->dryrun ) ? 1 : $this->get_or_create_category( $this->get_field_from_row( 'name', $row ) );
			if ( ! $category ) {
				$this->log(
					sprintf(
						'Failed to add type "%s" as a category',
						$this->get_field_from_row( 'name', $row )
					)
				);
				continue;
			}

			// Add the category to the posts that need its.
			$this->add_category_to_posts(
				$this->get_field_from_row( $type_header, $row ),
				$this->get_field_from_row( 'name', $row ),
				$category,
				self::META_PREFIX . $meta_key
			);

			// Try to improve memory usage.
			$this->stop_the_insanity();
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-import-tags`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_tags( $args, $assoc_args ) {
		$this->log( 'Importing Soccer America tags...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d tags', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {
			$progress->tick();

			// Check if we've already imported this as a category.
			$tag = ( $this->dryrun ) ? 1 : $this->get_or_create_tag(
				$this->get_field_from_row( 'name', $row ),
				$this->get_field_from_row( 'tag_id', $row )
			);
			if ( ! $tag ) {
				$this->log(
					sprintf(
						'Failed to add "%s" as a tag',
						$this->get_field_from_row( 'name', $row )
					)
				);
				continue;
			}
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-assign-tags`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_assign_tags( $args, $assoc_args ) {
		$this->log( 'Assigning Soccer America tags...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Convert the CSV to an associate array where keys are post IDs and values are arrays of tag IDs.
		$articles = $this->csv_to_assoc_array( $csv );

		// Start the progress bar.
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Assigning tags to %d articles', count( $articles ) ), count( $articles ) );

		// Run through each article and assign the tags.
		foreach ( $articles as $article_id => $tag_ids ) {
			$progress->tick();

			$post_id = $this->post_exists( $article_id );
			if ( ! $post_id ) {
				$this->log(
					sprintf(
						'Could not find post with ID %s',
						$article_id
					)
				);
				continue;
			}

			$this->log(
				sprintf(
					'Attempting to assign %d tags to post %d',
					count( $tag_ids ),
					$post_id
				)
			);

			// Get the tag IDs.
			$tag_ids = array_map(
				function( $tag_id ) {
					$tag = $this->get_tag_by_original_id( $tag_id );
					return ( $tag ) ? $tag : 0;
				},
				$tag_ids
			);

			// Get the existing tags.
			$existing_tags = wp_get_post_tags( $post_id, [ 'fields' => 'ids' ] );

			// Merge the existing tags with the new ones.
			$tags = array_unique( array_merge( $existing_tags, $tag_ids ) );

			// Assign the tags.
			$assigned = ( $this->dryrun ) ? true : wp_set_post_tags( $post_id, $tags );

			if ( is_wp_error( $assigned ) ) {
				$this->log(
					sprintf(
						'Failed to assign tags to post %s',
						$article_id
					)
				);
				continue;
			}

			$this->log(
				sprintf(
					'Assigned %d tags to post %s',
					count( $tags ),
					$post_id
				)
			);
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-import-users`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_users( $args, $assoc_args ) {
		$this->log( 'Assigning Soccer America users...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d users', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {

			$progress->tick();

			// Don't attempt to re-import anything.
			$user_exists = $this->user_exists( $this->get_field_from_row( 'author_id', $row ) );
			if ( $user_exists ) {
				$this->log(
					sprintf(
						'User with author_id %s already exists',
						$this->get_field_from_row( 'author_id', $row )
					)
				);
				continue;
			}

			// Import the user.
			$user_id = $this->import_user( $row );

			// Add the original data to the post as meta.
			if ( ! $this->dryrun ) {
				update_user_meta( $user_id, self::META_PREFIX . 'import_data', $row );
				update_user_meta( $user_id, self::META_PREFIX . 'author_id', $this->get_field_from_row( 'author_id', $row ) );
			}

			$this->log(
				sprintf(
					'Imported user with author_id %s',
					$this->get_field_from_row( 'author_id', $row )
				)
			);
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-assign-users`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_assign_users( $args, $assoc_args ) {
		$this->log( 'Assigning Soccer America tags...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Assigning %d users', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {

			$progress->tick();

			// Make sure the user exists.
			$user_id = $this->user_exists( $this->get_field_from_row( 'author_id', $row ) );
			if ( ! $user_id ) {
				$this->log(
					sprintf(
						'User with author_id %s does not exist',
						$this->get_field_from_row( 'author_id', $row )
					)
				);
				continue;
			}

			// Make sure the post exists.
			$post_id = $this->post_exists( $this->get_field_from_row( 'article_id', $row ) );
			if ( ! $post_id ) {
				$this->log(
					sprintf(
						'Post with article_id %s does not exist',
						$this->get_field_from_row( 'article_id', $row )
					)
				);
				continue;
			}

			// Assign the user to the post.
			$update = ( $this->dryrun ) ? 1 : wp_update_post(
				[
					'ID'          => $post_id,
					'post_author' => $user_id,
				]
			);
			if ( is_wp_error( $update ) || 0 === $update ) {
				$this->log(
					sprintf(
						'Could not assign user %d to post %d',
						$user_id,
						$post_id
					)
				);
				continue;
			}

			$this->log(
				sprintf(
					'Assigned user %d to post %d',
					$user_id,
					$post_id
				)
			);
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-import-images`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_images( $args, $assoc_args ) {
		$this->log( 'Importing Soccer America images...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Importing %d images', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {

			$progress->tick();

			// Don't attempt to re-import anything.
			$image_exists = $this->image_exists( $this->get_field_from_row( 'articleimage_id', $row ) );
			if ( $image_exists ) {
				$this->log(
					sprintf(
						'Attachment with articleimage_id %s already exists',
						$this->get_field_from_row( 'articleimage_id', $row )
					)
				);
				continue;
			}

			// Import the image.
			$attachment_id = ( $this->dryrun ) ? 1 : $this->import_image( $row );
			if ( is_wp_error( $attachment_id ) ) {
				$this->log(
					sprintf(
						'Failed to import image with articleimage_id %s.',
						$this->get_field_from_row( 'articleimage_id', $row )
					)
				);
				continue;
			}

			// Add the original data to the post as meta.
			if ( ! $this->dryrun ) {
				add_post_meta( $attachment_id, self::META_PREFIX . 'import_data', $row );
				add_post_meta( $attachment_id, self::META_PREFIX . 'articleimage_id', $this->get_field_from_row( 'articleimage_id', $row ) );
			}

			$this->log(
				sprintf(
					'Imported image %s',
					$this->get_field_from_row( 'image_file', $row )
				)
			);
		}

		$progress->finish();

	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-assign-images`
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_assign_images( $args, $assoc_args ) {
		$this->log( 'Assigning Soccer America images...', 'line' );

		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Make sure there is a path to CSV provided.
		if ( ! isset( $args[0] ) || empty( $args[0] ) ) {
			$this->log( 'Please provide a path to an CSV file.', 'error' );
		}

		// Open the CSV file.
		$csv = fopen( $args[0], 'r' );
		if ( false === $csv ) {
			$this->log( 'Could not open CSV file.', 'error' );
		}

		// Start the progress bar.
		$count = 0;
		exec( ' wc -l ' . escapeshellarg( $args[0] ), $count );
		$progress = \WP_CLI\Utils\make_progress_bar( sprintf( 'Assigning %d images', $count[0] ), $count[0] );

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Run through the CSV and import each row.
		while ( ( $row = fgetcsv( $csv, null, "\t" ) ) !== false ) {

			$progress->tick();

			// Make sure the attachment has imported.
			$attachment_id = $this->user_exists( $this->get_field_from_row( 'image_id', $row ) );
			if ( ! $attachment_id ) {
				$this->log(
					sprintf(
						'Attachment with image_id %s does not exist',
						$this->get_field_from_row( 'image_id', $row )
					)
				);
				continue;
			}

			// Make sure the post exists.
			$post_id = $this->post_exists( $this->get_field_from_row( 'article_id', $row ) );
			if ( ! $post_id ) {
				$this->log(
					sprintf(
						'Post with article_id %s does not exist',
						$this->get_field_from_row( 'article_id', $row )
					)
				);
				continue;
			}

			// Assign the image to the post.
			$update = ( $this->dryrun ) ? 1 : update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
			if ( ! $update ) {
				$this->log(
					sprintf(
						'Could not assign attachment %d to post %d',
						$attachment_id,
						$post_id
					)
				);
				continue;
			}

			$this->log(
				sprintf(
					'Assigned attachment %d to post %d',
					$attachment_id,
					$post_id
				)
			);
		}

		$progress->finish();
	}

	/**
	 * Callable for `newspack-content-migrator soccer-america-fix-missing-content`
	 */
	public function cmd_fix_missing_content( $args, $assoc_args ) {
		global $wpdb;

		// Set dry-run.
		if ( array_key_exists( 'dry-run', $assoc_args ) ) {
			$this->dryrun = true;
			$this->log( 'Performing a dry-run. No changes will be made.', 'line' );
		}

		// Get all the posts with no content.
		$posts = $wpdb->get_results(
			"SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_content = '' AND post_status = 'publish'"
		);

		// Start the CLI progress bar.
		$progress = \WP_CLI\Utils\make_progress_bar(
			sprintf( 'Fixing %d posts', count( $posts ) ),
			count( $posts )
		);

		// Loop through the posts and update the content.
		foreach ( $posts as $post ) {

			// Increment the progress bar.
			$progress->tick();

			// Get the content from meta.
			$content = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = %s",
					$post->ID,
					self::META_PREFIX . 'body'
				)
			);
			if ( ! $content ) {
				$this->log( sprintf( 'No content found for post %d', $post->ID ) );
				continue;
			}

			// Update the post.
			$updated = ( $this->dryrun ) ? true : $wpdb->update(
				$wpdb->posts,
				[
					'post_content' => $content,
				],
				[
					'ID' => $post->ID,
				]
			);
			if ( ! $updated ) {
				$this->log( sprintf( 'Failed to update post %d', $post->ID ) );
			}
		}

		// Finish the progress bar.
		$progress->finish();
	}

	/**
	 * Import the post!
	 */
	private function import_post( $fields ) {

		// Set up the post args array with defaults.
		$post_args = [
			'post_type'   => 'post',
			'post_status' => 'publish',
			'meta_input'  => [],
		];

		// Process each field in turn, adding to the post args array.
		foreach ( $this->csv_headers as $field ) {

			// Get the value for this field.
			$value = $this->get_field_from_row( $field, $fields );

			// Don't try to process empty values.
			if ( empty( $value ) ) {
				continue;
			}

			// Get the formatter for this field.
			$formatter_function = $this->get_content_formatter( $field );

			if ( $this->is_post_field( $field ) ) {

				// Get the post field that we need to assign this field to.
				$post_field = $this->core_fields_mapping[ $field ];

				// Add the formatted value to the post args array.
				$post_args[ $post_field ] = call_user_func( $formatter_function, $value );

			} else {

				// Set the meta key using our special prefix, or a pre-defined key.
				$meta_key = ( isset( $this->meta_fields_mapping[ $field ] ) ) ?
					$this->meta_fields_mapping[ $field ] :
					self::META_PREFIX . $field;

				// Add the formatted value to the meta input array.
				$post_args['meta_input'][ $meta_key ] = call_user_func( $formatter_function, $value );

			}
		}

		$post = ( $this->dryrun ) ? true : wp_insert_post( $post_args, true );
		if ( is_wp_error( $post ) ) {
			$this->log(
				sprintf(
					'Failed to create post for guid %s',
					$this->get_field_from_row( 'article_id', $fields )
				)
			);
		} else {
			$this->log(
				sprintf(
					'Successfully imported "%s" as ID %d',
					$this->get_field_from_row( 'headline', $fields ),
					$post
				)
			);
		}

		return $post;

	}

	/**
	 * Import the comment!
	 */
	private function import_comment( $fields ) {

		// Set up the comment args array with defaults.
		$comment_args = [
			'comment_approved' => 1,
			'comment_type'     => 'comment',
			'comment_meta'     => [],
		];

		$post_id = $this->post_exists( $this->get_field_from_row( 'article_id', $fields ) );
		if ( ! $post_id ) {
			$this->log(
				sprintf(
					'Could not find post for article_id %s to add comment',
					$this->get_field_from_row( 'article_id', $fields )
				)
			);
			return false;
		} else {
			$comment_args['comment_post_ID'] = $post_id;
		}

		// Process each field in turn, adding to the comment args array.
		foreach ( $this->csv_headers as $field ) {

			// Get the value for this field.
			$value = $this->get_field_from_row( $field, $fields );

			// Don't try to process empty values.
			if ( empty( $value ) ) {
				continue;
			}

			// Get the formatter for this field.
			$formatter_function = $this->get_content_formatter( $field );

			// Get the comment field that we need to assign this field to.
			if ( array_key_exists( $field, $this->comment_fields_mapping ) ) {
				$comment_field = $this->comment_fields_mapping[ $field ];
			} else {
				continue;
			}

			// Add the formatted value to the comment args array.
			$value = call_user_func( $formatter_function, $value );

			$comment_args[ $comment_field ] = $value;
		}

		// Add the original data and comment ID as meta.
		$comment_args['comment_meta'][ self::META_PREFIX . 'import_data' ] = $fields;
		$comment_args['comment_meta'][ self::META_PREFIX . 'comment_id' ] = md5( json_encode( $fields ) );

		$comment = ( $this->dryrun ) ? true : wp_insert_comment( $comment_args, true );
		if ( is_wp_error( $comment ) ) {
			$this->log(
				sprintf(
					'Failed to create comment for article_id %s and user_id %s',
					$this->get_field_from_row( 'article_id', $fields ),
					$this->get_field_from_row( 'user_id', $fields )
				)
			);
		} else {
			$this->log(
				sprintf(
					'Successfully imported comment for article_id %s and user_id %s as ID %d',
					$this->get_field_from_row( 'article_id', $fields ),
					$this->get_field_from_row( 'user_id', $fields ),
					$comment
				)
			);
		}

		return $comment;
	}

	/**
	 * Import a user.
	 *
	 * @param array $data User data from the CSV file.
	 *
	 * @return int|WP_Error The user ID on success, or a WP_Error object on failure.
	 */
	private function import_user( $data ) {
		$author_id  = $this->get_field_from_row( 'author_id', $data );
		$user_id    = $this->get_field_from_row( 'user_id', $data );
		$first_name = $this->get_field_from_row( 'first_name', $data );
		$last_name  = $this->get_field_from_row( 'last_name', $data );
		$email      = $this->get_field_from_row( 'email', $data );
		$bio        = $this->get_field_from_row( 'bio', $data );
		$slug       = $this->get_field_from_row( 'slug', $data );

		// Create the user.
		$user_id = ( $this->dryrun ) ? 1 : wp_create_user(
			$slug,
			wp_generate_password(),
			$email
		);
		if ( is_wp_error( $user_id ) ) {
			$this->log(
				sprintf(
					'Failed to create user for author_id %s',
					$author_id
				),
				'warning'
			);
			return $user_id;
		}

		// Add metadata.
		if ( ! $this->dryrun ) {
			// Add the user's first and last name.
			update_user_meta( $user_id, 'first_name', $first_name );
			update_user_meta( $user_id, 'last_name', $last_name );

			// Add the user's bio
			update_user_meta( $user_id, 'description', $bio );
		}

		return $user_id;
	}

	/**
	 * Import an image.
	 */
	private function import_image( $data ) {
		$attachment_id = $this->attachments->import_external_file(
			$this->get_field_from_row( 'image_file', $data )
		);
		if ( is_wp_error( $attachment_id ) ) {
			$this->log(
				sprintf(
					'Failed to import image URL %s',
					$this->get_field_from_row( 'image_file', $data )
				)
			);
		}

		return $attachment_id;
	}

	/**
	 * Determine which formatter to use for each field.
	 */
	private function set_content_formatters() {
		$this->content_formatters = [
			// Article fields
			'article_id'   => 'intval',
			'article_slug' => 'sanitize_title',
			'publish_date' => 'esc_html',
			'type_id'      => 'intval',
			'subtype_id'   => 'intval',
			'shortlink'    => [ $this, 'return_value' ],
			'headline'     => 'esc_html',
			'subtitle'     => 'esc_html',
			'short_social' => 'esc_html',
			'short'        => 'esc_html',
			'body'         => [ $this, 'return_value' ],

			// Comment fields (except article_id)
			'user_id'      => 'intval',
			'user_name'    => 'esc_html',
			'user_email'   => 'sanitize_email',
			'comment'      => 'wp_kses_post',
			'submit_date'  => 'esc_html',
		];
	}

	/**
	 * Get the callable function that will be used to format a field value.
	 *
	 * @param string $name Name of the field we need to format.
	 *
	 * @return callable The callable formatter function.
	 */
	private function get_content_formatter( $name ) {
		return isset( $this->content_formatters[ $name ] ) ? $this->content_formatters[ $name ] : null;;
	}

	/**
	 * Return the value.
	 *
	 * @param mixed $value The value to return.
	 *
	 * @return mixed The unmodified value.
	 */
	private function return_value( $value ) {
		return $value;
	}

	/**
	 * Get the value of a field from a row.
	 *
	 * @param string $field The field to get the value of.
	 * @param array  $row   The row to get the field from.
	 *
	 * @return string The value of the field.
	 */
	private function get_field_from_row( $field, $row ) {
		return $row[ array_search( $field, $this->csv_headers ) ];
	}

	/**
	 * Check if a post has been imported already by checking the article ID.
	 *
	 * @param string $article_id Article as provided by the CSV export.
	 *
	 * @return int|null Post ID if it exists, null otherwise.
	 */
	private function post_exists( $article_id ) {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
				self::META_PREFIX . 'article_id',
				$article_id
			)
		);
	}

	/**
	 * Check if a image has been imported already by checking the article image ID.
	 *
	 * @param string $articleimage_id Article as provided by the CSV export.
	 *
	 * @return int|bool Attachment ID if it exists, false otherwise.
	 */
	private function image_exists( $articleimage_id ) {

		$query_args = [
			'post_type'  => 'attachment',
			'meta_query' => [
				[
					'key'   => self::META_PREFIX . 'articleimage_id',
					'value' => $articleimage_id,
				],
			],
		];
		$posts = get_posts( $query_args );
		return ( ! empty( $posts ) && is_a( $posts[0], 'WP_Post' ) ) ? $posts[0]->ID : false;
	}

	/**
	 * Check if a user has been imported already by checking the article ID.
	 *
	 * @param string $author_id Author ID as provided by the CSV export.
	 *
	 * @return int|null User ID if it exists, null otherwise.
	 */
	private function user_exists( $author_id ) {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s AND meta_value = %d",
				self::META_PREFIX . 'author_id',
				$author_id
			)
		);
	}

	/**
	 * Check if a comment has been imported already by checking the comment ID.
	 *
	 * @param int $id The comment ID.
	 *
	 * @return int|null Comment ID if it exists, null otherwise.
	 */
	private function comment_exists( $id ) {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = %s AND meta_value = %s",
				self::META_PREFIX . 'comment_id',
				$id
			)
		);
	}

	/**
	 * Check if a field is to be imported as core field.
	 *
	 * @param string $name The name of the field.
	 *
	 * @return bool True if it's a core post field, false if not.
	 */
	private function is_post_field( $name ) {
		return array_key_exists( $name, $this->core_fields_mapping );
	}

	/**
	 * Takes the import data and filters to those we need to specially import.
	 *
	 * @param array $fields The full import data.
	 *
	 * @return array Modified array containing only the data we need to process.
	 */
	private function filter_import_fields( $fields ) {

		// Make an array of field names that need processing.
		$field_names_to_import = array_merge(
			array_keys( $this->core_fields_mapping ),
			array_keys( $this->meta_fields_mapping )
		);

		// Create and return the filtered array.
		return array_filter(
			(array) $fields,
			function ( $field_name ) use ( $field_names_to_import ) {
				return in_array( $field_name, $field_names_to_import );
			},
			ARRAY_FILTER_USE_KEY
		);

	}

	/**
	 * Simple file logging.
	 *
	 * @param string         $message Log message.
	 * @param string|boolean $level Whether to output the message to the CLI. Default to `line` CLI level.
	 */
	private function log( $message, $level = false ) {
		$this->logger->log( 'soccer-america', $message, $level );
	}

	/**
	 * Get a category ID from it's name, creating if it doesn't exist.
	 *
	 * @param string $name Full textual name of the category.
	 *
	 * @return int|false ID of the found category, false if not found/failed to create.
	 */
	private function get_or_create_category( $name ) {

		// Check if the category already exists.
		$category_id = get_cat_ID( $name );

		// If not, create it.
		if ( 0 === $category_id ) {
			// Create the category, under it's parent if required.
			$category_id = ( $this->dryrun ) ? false : wp_create_category( $name );
			if ( is_wp_error( $category_id ) ) {
				$this->log( sprintf( 'Failed to create %s category', $name ) );
				$category_id = false;
			}

		}

		return $category_id;
	}

	/**
	 * Get a tag ID from it's name, creating if it doesn't exist.
	 *
	 * @param string $name   Full textual name of the tag.
	 * @param int    $original_tag_id Original ID of the tag from the CSV.
	 *
	 * @return int|false ID of the found tag, false if not found/failed to create.
	 */
	private function get_or_create_tag( $name, $original_tag_id ) {

		// Check if the tag already exists.
		$tag_id = get_term_by( 'name', $name );

		// If not, create it.
		if ( ! is_a( $tag_id, 'WP_Term' ) ) {
			$this->log( sprintf( 'Tag %s not found. Creating it....', $name ) );

			// Create the tag.
			$tag_id = ( $this->dryrun ) ? false : wp_create_term( $name, 'post_tag' );
			if ( is_wp_error( $tag_id ) ) {
				$this->log( sprintf( 'Failed to create %s tag', $name ) );
				$tag_id = false;
			} else {
				// Add the original tag ID as meta so we can find it later.
				add_term_meta( $tag_id['term_id'], self::META_PREFIX . 'tag_id', $original_tag_id );
			}

		}

		return $tag_id;
	}

	/**
	 * Get a tag by the original ID from the import.
	 *
	 * @param int $original_id Original ID for the tag, taken from the CSV.
	 *
	 * @return WP_Term|false The tag object, or false if not found.
	 */
	private function get_tag_by_original_id( $original_id ) {
		global $wpdb;
		$terms = $wpdb->get_results( $wpdb->prepare(
			"SELECT term_id FROM $wpdb->termmeta WHERE meta_key = %s AND meta_value = %d",
			self::META_PREFIX . 'tag_id',
			$original_id
		) );

		if ( is_null( $terms ) || empty( $terms ) ) {
			return false;
		}

		return intval( $terms[0]->term_id) ;
	}

	/**
	 * Add a category to all posts with a given article type.
	 *
	 * @param int    $type_id   The article type ID.
	 * @param string $name      The article type name.
	 * @param int    $category  The category ID.
	 * @param string $meta_key  The meta key to use for the article type.
	 *
	 * @return void
	 */
	private function add_category_to_posts( $type_id, $name, $category, $meta_key = null ) {
		// Use the meta key provided, if there is one.
		$meta_key = ( $meta_key ) ? $meta_key : self::META_PREFIX . 'type_id';

		// Update any posts with this article type.
		$posts = get_posts(
			[
				'posts_per_page' => -1,
				'post_type' 	 => 'post',
				'meta_query' => [
					[
						'key' => $meta_key,
						'value' => $type_id,
					]
				]
			]
		);
		if ( ! $posts ) {
			$this->log(
				sprintf(
					'No posts found with type "%s"',
					$name
				),
				'warning'
			);
			return;
		}

		$this->log(
			sprintf(
				'Found %d posts with type "%s"',
				count( $posts ),
				$name
			)
		);

		foreach ( $posts as $post ) {
			$this->log(
				sprintf(
					'Adding category "%s" to post "%s"',
					$name,
					$post->post_title
				)
			);
			$set = ( $this->dryrun ) ? true : wp_set_post_categories( $post->ID, $category, true );
			if ( ! $set || is_wp_error( $set ) ) {
				$this->log(
					sprintf(
						'Failed to add category "%s" to post "%s"',
						$name,
						$post->post_title
					),
					'warning'
				);
			} else {
				$this->log(
					sprintf(
						'Added category "%s" to post "%s"',
						$name,
						$post->post_title
					)
				);

				// Remove the meta key so we don't do this again.
				delete_post_meta( $post->ID, $meta_key, $type_id );
			}
		}
	}

	/**
	 * Convert a CSV showing one-to-one relationships to an association array.
	 *
	 * @param string $csv File pointer to the CSV.
	 *
	 * @return array The converted CSV.
	 */
	private function csv_to_assoc_array( $csv ) {

		// Holding array.
		$data = [];

		// Get the first row of the CSV, which should be the column headers.
		$this->csv_headers = fgetcsv( $csv, null, "\t" );

		// Read the CSV.
		while ( ( $line = fgetcsv( $csv, null, "\t" ) ) !== false) {

			// Extract the article_id and data_id values.
			$article_id = $line[0];
			$data_id     = $line[1];

			// If the article_id is not yet in the associative array, create a new index for it.
			if ( ! isset( $data[ $article_id ] ) ) {
				$data[ $article_id ] = [];
			}

			// Add the data_id to the array of data_ids for this article_id.
			$data[ $article_id ][] = $data_id;
		}

		return $data;
	}

	private function stop_the_insanity() {
		self::reset_local_object_cache();
		self::reset_db_query_log();
	}

	/**
	 * Reset the local WordPress object cache
	 *
	 * This only cleans the local cache in WP_Object_Cache, without
	 * affecting memcache (copied from VIP Go MU Plugins)
	 */
	private function reset_local_object_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops      = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache          = array();

		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset(); // important
		}
	}

	/**
	 * Reset the WordPress DB query log
	 *
	 * (copied from VIP Go MU Plugins)
	 */
	private function reset_db_query_log() {
		global $wpdb;

		$wpdb->queries = array();
	}

}
