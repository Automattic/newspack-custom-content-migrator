<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;

class PostsMigrator implements InterfaceMigrator {

	/**
	 * `meta_key` assigned to exported posts, contain the original post ID. It's also used to mark that the post was migrated.
	 */
	CONST POST_META_ORIGINAL_ID_KEY = 'newspack_custom_content_migrator-original_post_id';

	/**
	 * @var null|PostsMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
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
		WP_CLI::add_command( 'newspack-custom-content-migrator export-posts', array( $this, 'cmd_export_posts' ), [
			'shortdesc' => 'Exports elements of the staging site.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'output-dir',
					'description' => 'Output directory, no ending slash.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'output-xml-file',
					'description' => 'Output XML file name.',
					'optional'    => false,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'post-ids',
					'description' => 'CSV post/page IDs to migrate.',
					'optional'    => true,
					'repeating'   => false,
				],
				[
					'type'        => 'assoc',
					'name'        => 'created-from',
					'description' => 'Export posts and pages created from and including this date, format YYYY-MM-DD',
					'optional'    => true,
					'repeating'   => false,
				],
			],
		] );

		WP_CLI::add_command( 'newspack-custom-content-migrator delete-export-postmeta', array( $this, 'cmd_delete_export_postmeta' ), [
			'shortdesc' => 'Removes the postmeta set on all exported posts/pages (meta contains the original ID, and prevents duplicating in export).',
		] );

		WP_CLI::add_command( 'newspack-custom-content-migrator import-posts', array( $this, 'cmd_import_posts' ), [
			'shortdesc' => 'Imports custom posts from the export XML file.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'file',
					'description' => 'Full path of XML file for import.',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for export-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_export_posts( $args, $assoc_args ) {
		$output_dir        = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		$output_file       = isset( $assoc_args[ 'output-xml-file' ] ) ? $assoc_args[ 'output-xml-file' ] : null;
		$created_from      = isset( $assoc_args[ 'created-from' ] ) ? $assoc_args[ 'created-from' ] : null;
		$post_ids_csv      = isset( $assoc_args[ 'post-ids' ] ) ? $assoc_args[ 'post-ids' ] : null;
		$date_created_from = $this->get_datetime_from_string( $created_from );

		if ( is_null( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}
		if ( is_null( $output_file ) ) {
			WP_CLI::error( 'Invalid output file.' );
		}
		// One of the following arguments is mandatory: post-ids, created-from.
		if ( is_null( $post_ids_csv ) && is_null( $created_from ) ) {
			WP_CLI::error( 'One of these is mandatory: post-ids or created-from' );
		}
		if ( is_null( $post_ids_csv ) && is_null( $date_created_from ) ) {
			WP_CLI::error( 'Invalid created from date (expected format YYYY-MM-DD).' );
		}

		$post_ids = $post_ids_csv ?
			explode( ',', $post_ids_csv ) :
			$this->get_posts_and_pages_from_date( $date_created_from );

		WP_CLI::line( sprintf( 'Exporting to %s post IDs %s...', $output_dir . '/' . $output_file, implode( ',', $post_ids ) ) );
		$this->migrator_export_posts( $post_ids, $output_dir, $output_file );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Gets IDs of all posts and pages created starting from and including the specified date.
	 *
	 * @param \DateTime $from Created from, inclusive.
	 *
	 * @return array Post IDs.
	 */
	public function get_posts_and_pages_from_date( $from ) {
		$ids = array();

		wp_reset_postdata();

		$args = array(
			'numberposts' => -1,
			'post_type' => array('post', 'page'),
			'post_status' => 'publish',
			'date_query' => array(
				'after' => array(
					'year'  => $from->format( 'Y' ),
					'month' => $from->format( 'm' ),
					'day'   => $from->format( 'd' ),
				),
				'inclusive' => true,
			)
		);
		$query = new \WP_Query( $args );
		$posts = $query->get_posts();
		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$ids[] = $post->ID;
			}
		}

		wp_reset_postdata();

		return $ids;
	}

	/**
	 * Exports posts by also setting a postmeta with the original ID. It doesn't export posts which already have this meta,
	 * which ensures that posts aren't duplicated by multiple exports and imports (e.g. menu items need exporting, too).
	 *
	 * @param array  $post_ids    Post IDs.
	 * @param string $output_dir  Output dir.
	 * @param string $output_file Output file.
	 */
	public function migrator_export_posts( $post_ids, $output_dir, $output_file ) {
		foreach ( $post_ids as $key => $post_id ) {
			$meta = get_post_meta( $post_id, self::POST_META_ORIGINAL_ID_KEY );
			if ( empty( $meta ) ) {
				update_post_meta( $post_id, self::POST_META_ORIGINAL_ID_KEY, $post_id );
			} else {
				WP_CLI::line( sprintf( 'Post ID %s already exported, skipping.', $post_id ) );
				unset( $post_ids[ $key ] );
			}
		}

		$post_ids = array_values( $post_ids );
		if ( ! empty( $post_ids ) ) {
			$this->export_posts( $post_ids, $output_dir, $output_file );
		} else {
			WP_CLI::warning( 'No posts to export.' );
		}

		wp_cache_flush();
	}

	/**
	 * Actual exporting of posts to file.
	 *
	 * @param array  $post_ids    Post IDs.
	 * @param string $output_dir  Output dir.
	 * @param string $output_file Output file.
	 */
	private function export_posts( $post_ids, $output_dir, $output_file ) {
		$post_ids_csv = implode( ',', $post_ids );
		WP_CLI::runcommand( "export --post__in=$post_ids_csv --dir=$output_dir --filename_format=$output_file --with_attachments" );
	}

	/**
	 * Callable for remove-export-postmeta command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_delete_export_postmeta() {
		WP_CLI::line( sprintf( 'Deleting all %s postmeta from all posts and pages...', self::POST_META_ORIGINAL_ID_KEY ) );

		$args = array(
			'post_type' => array( 'post', 'page' ),
			'post_status' => 'publish',
			'meta_query' => array(
				array(
					'key' => self::POST_META_ORIGINAL_ID_KEY,
				)
			)
		);
		$query = new \WP_Query( $args );
		$posts = $query->posts;

		foreach ( $posts as $post ) {
			delete_post_meta( $post->ID, self::POST_META_ORIGINAL_ID_KEY );
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for import-posts command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_posts( $args, $assoc_args ) {
		$file = isset( $assoc_args[ 'file' ] ) ? $assoc_args[ 'file' ] : null;

		if ( is_null( $file ) || ! file_exists( $file ) ) {
			WP_CLI::error( 'Invalid file.' );
		}

		WP_CLI::line( 'Importing posts...' );
		$output = $this->import_posts( $file );

		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * @param string $file File for Import.
	 *
	 * @return mixed
	 */
	public function import_posts( $file ) {
		$options = [
			'return' => true,
		];
		$output = WP_CLI::runcommand( "import $file --authors=create", $options );

		return $output;
	}

	/**
	 * Safely creates a \DateTime object from a formatted string.
	 *
	 * The \DateTime::createFromFormat would creates an object even with overflowing time parts (eg. date 32, month 13),
	 * and this function makes sure that doesn't happen.
	 *
	 * @param string $created_from Format YYYY-MM-DD.
	 *
	 * @return \DateTime|null
	 */
	private function get_datetime_from_string( $created_from ) {
		$date_created_from = null;

		if ( null !== $created_from && ( 10 === strlen( $created_from ) ) ) {
			// Argument in format YYYY-MM-DD.
			$year  = substr( $created_from, 0, 4 );
			$month = substr( $created_from, 5, 2 );
			$date  = substr( $created_from, 8, 2 );

			if ( checkdate( $month, $date, $year ) ) {
				$date_created_from = \DateTime::createFromFormat( 'Y-m-d', $created_from );
			}
		}

		return $date_created_from ? $date_created_from : null;
	}
}
