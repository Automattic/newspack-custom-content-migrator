<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Migrator\General\SubtitleMigrator;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Noozhawk.
 */
class NoozhawkMigrator implements InterfaceMigrator {
	// Logs.
	const AUTHORS_LOGS = 'NH_authors.log';
	const EXCERPT_LOGS = 'NH_authors.log';

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-co-authors-from-csv',
			array( $this, 'cmd_nh_import_co_authors' ),
			array(
				'shortdesc' => 'Import co-authors from CSV',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'authors-csv-path',
						'description' => 'CSV file path that contains the co-authors to import.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-clean-authors-categories-from-csv',
			array( $this, 'cmd_nh_import_clean_authors_categories' ),
			array(
				'shortdesc' => 'Clean imported co-authors categories from CSV',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'authors-csv-path',
						'description' => 'CSV file path that contains the co-authors categories to clean.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator noozhawk-copy-excerpt-from-subhead',
			array( $this, 'cmd_nh_copy_excerpt_from_subhead' ),
			array(
				'shortdesc' => 'Import co-authors from CSV',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-co-authors-from-csv`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_import_co_authors( $args, $assoc_args ) {
		$authors_json_path = $assoc_args['authors-csv-path'] ?? null;
		if ( ! file_exists( $authors_json_path ) ) {
			WP_CLI::error( sprintf( 'Author export %s not found.', $authors_json_path ) );
		}

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::warning( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
			$this->log( self::AUTHORS_LOGS, 'Co-Authors Plus plugin not found. Install and activate it before using this command.', false );
			return;
		}

		$co_authors_added = array();

		$time_start = microtime( true );
		if ( ( $h = fopen( $authors_json_path, 'r' ) ) !== false ) {
			while ( ( $author = fgetcsv( $h, 1000, ',' ) ) !== false ) {
				try {
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
						array(
							'display_name' => sanitize_user( $author[0], false ),
						)
					);
					if ( is_wp_error( $guest_author_id ) ) {
						WP_CLI::warning( sprintf( "Could not create GA full name '%s': %s", $author['name'], $guest_author_id->get_error_message() ) );
						$this->log( self::AUTHORS_LOGS, sprintf( "Could not create GA full name '%s': %s", $author['name'], $guest_author_id->get_error_message() ) );
						continue;
					}

					// Set original ID.
					$co_authors_added[] = $author;
					update_post_meta( $guest_author_id, 'imported_from_categories', true );
					$this->log( self::AUTHORS_LOGS, sprintf( '- %s', $author[0] ) );

					// Set co-author to the category' posts.
					$author_category = get_category_by_slug( $author[1] );
					if ( ! $author_category ) {
						$this->log( self::AUTHORS_LOGS, sprintf( 'There is no category for this author: %s!', $author[1] ) );
						continue;
					}

					$posts = get_posts(
						array(
							'numberposts' => -1,
							'category'    => $author_category->term_id,
							'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
						)
					);

					foreach ( $posts as $post ) {
						$this->coauthorsplus_logic->assign_guest_authors_to_post( array( $guest_author_id ), $post->ID );
						$this->log( self::AUTHORS_LOGS, sprintf( '    - %s was added as post co-author for the post %d.', $author[0], $post->ID ) );
					}
				} catch ( \Exception $e ) {
					WP_CLI::warning( sprintf( "Could not create GA full name '%s': %s", $author['name'], $e->getMessage() ) );
					$this->log( self::AUTHORS_LOGS, sprintf( "Could not create GA full name '%s': %s", $author['name'], $e->getMessage() ) );
				}
			}

			// Close the file.
			fclose( $h );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d co-authors took %d mins.', count( $co_authors_added ), floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-clean-authors-categories-from-csv`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_import_clean_authors_categories( $args, $assoc_args ) {
		$authors_json_path = $assoc_args['authors-csv-path'] ?? null;
		if ( ! file_exists( $authors_json_path ) ) {
			WP_CLI::error( sprintf( 'Author export %s not found.', $authors_json_path ) );
		}

		$time_start = microtime( true );
		if ( ( $h = fopen( $authors_json_path, 'r' ) ) !== false ) {
			while ( ( $author = fgetcsv( $h, 1000, ',' ) ) !== false ) {
				$category_id = get_cat_ID( $author[0] );

				if ( ! $category_id ) {
					$this->log( self::AUTHORS_LOGS, sprintf( 'Category "%s" was not found!', $author[0] ), false );
					WP_CLI::warning( sprintf( 'Category "%s" was not found!', $author[0] ) );
					continue;
				}

				wp_delete_category( $category_id );
				$this->log( self::AUTHORS_LOGS, sprintf( 'Category "%s" was deleted!', $author[0] ) );
			}

			// Close the file.
			fclose( $h );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Importing %d co-authors took %d mins.', count( $co_authors_added ), floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator noozhawk-copy-excerpt-from-subhead`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_nh_copy_excerpt_from_subhead( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ),
				'meta_key'    => SubtitleMigrator::NEWSPACK_SUBTITLE_META_FIELD,
			)
		);

		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			$subhead = get_post_meta( $post->ID, SubtitleMigrator::NEWSPACK_SUBTITLE_META_FIELD, true );
			if ( ! empty( $subhead ) && $post->post_excerpt !== $subhead ) {
				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_excerpt' => $subhead,
					)
				);

				$this->log( self::EXCERPT_LOGS, sprintf( '(%d/%d) Excerpt updated for the post: %d', $index, $total_posts, $post->ID ) );
			}
		}
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
