<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \CoAuthors_Guest_Authors;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Bethesda Mag.
 */
class BethesdaMagMigrator implements InterfaceMigrator {
	const DELETE_LOGS = 'bethesda_duplicate_posts_delete.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_migrator_logic;

	/**
	 * @var CoAuthorPlusLogic $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var CoAuthors_Guest_Authors $coauthors_guest_authors
	 */
	private $coauthors_guest_authors;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_migrator_logic = new PostsLogic();
		$this->coauthorsplus_logic  = new CoAuthorPlusLogic();
		$this->coauthors_guest_authors = new CoAuthors_Guest_Authors();
	}

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

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
			'newspack-content-migrator bethesda-remove-duplicated-posts',
			array( $this, 'bethesda_remove_duplicated_posts' ),
			array(
				'shortdesc' => 'Remove duplicated posts.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-migrate-co-authors-from-meta',
			array( $this, 'bethesda_migrate_co_authors_from_meta' ),
			array(
				'shortdesc' => 'Remove duplicated posts.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator bethesda-guest-author-audit',
			array( $this, 'cmd_guest_author_audit' ),
			array(
				'shortdesc' => 'Replaces Guest Authors based on a mapping provided by the publisher',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator bethesda-remove-duplicated-posts`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function bethesda_remove_duplicated_posts( $args, $assoc_args ) {
		global $wpdb;

		$post_ids_to_delete = array();

		$posts_table = $wpdb->prefix . 'posts';

		$sql = "SELECT post_title, post_date, GROUP_CONCAT(ID ORDER BY ID) AS duplicate_ids, COUNT(*)
		FROM {$posts_table}
		where post_status = 'publish' and post_type in ('post', 'page')
		GROUP BY post_title, post_content, post_date
		HAVING COUNT(*) > 1 ;";
		// phpcs:ignore -- false positive, all params are fully sanitized.
		$results = $wpdb->get_results( $sql );

		foreach ( $results as $result ) {
			$ids = explode( ',', $result->duplicate_ids );
			if ( 2 === count( $ids ) ) {
				$post_ids_to_delete[ $ids[0] ] = array( $ids[1] ); // Deleting the last one imported.
			} else {
				// Some posts are duplicated more than once.
				// We need to make sure that we're deleting the right duplicate.
				$original_post = get_post( $ids[0] );
				foreach ( $ids as $index => $id ) {
					// skip original post.
					if ( 0 === $index ) {
						continue;
					}

					$post = get_post( $id );
					if ( $original_post->post_content === $post->post_content ) {
						if ( ! isset( $post_ids_to_delete[ $ids[0] ] ) ) {
							$post_ids_to_delete[ $ids[0] ] = array();
						}

						$post_ids_to_delete[ $ids[0] ][] = $id;
					}
				}
			}
		}

		foreach ( $post_ids_to_delete as $original_id => $ids ) {
			foreach ( $ids as $post_id_to_delete ) {
				$this->log( self::DELETE_LOGS, sprintf( "Deleting post #%d as it's a duplicate of #%d", $post_id_to_delete, $original_id ) );
				wp_delete_post( $post_id_to_delete );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Callable for `newspack-content-migrator bethesda-migrate-co-authors-from-meta`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function bethesda_migrate_co_authors_from_meta( $args, $assoc_args ) {
		$this->posts_migrator_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			),
			function( $post ) {
				$co_authors_ids       = array();
				$author_meta          = get_post_meta( $post->ID, 'bm_author', true );
				$authors_to_not_split = array(
					'By Alicia Klaffky, Kensington',
					'By Robert Karn, Boyds',
					'By Carole Sugarman, @CaroleSugarman',
				);

				$co_authors_to_add = array();

				if ( ! empty( $author_meta ) ) {
					$cleaned_author_name = $this->clean_author_name( trim( wp_strip_all_tags( $author_meta ) ) );

					// Skip splitting authors with 'School' as they contain only one author name and the name of the high school.
					// Skip splitting specific author names.
					// Skip splitting author names that starts with or ends with given words.
					if (
						! $this->str_contains( $cleaned_author_name, 'school' )
						&& ! $this->str_contains( $cleaned_author_name, 'Adult Short Story Winner' )
						&& ! $this->str_contains( $cleaned_author_name, 'Washington, D.C.' )
						&& ! $this->str_contains( $cleaned_author_name, 'Academie de Cuisine' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Potomac' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Rockville' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Chevy Chase' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', MD' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Gaithersburg' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Bethesda' )
						&& ! $this->str_ends_with( $cleaned_author_name, ', Arlington, VA' )
						&& ! $this->str_starts_with( 'Story and photos by', $author_meta )
						&& ! $this->str_starts_with( 'Text and photos by', $author_meta )
						&& ! in_array( $author_meta, $authors_to_not_split, true )
						&& ( $this->str_contains( $cleaned_author_name, ' and ' ) || $this->str_contains( $cleaned_author_name, ', ' ) || $this->str_contains( $cleaned_author_name, 'Follow @' ) )
					) {
						$co_authors_names = preg_split( '/(, | and | & |Follow @[^\s]+)/', $cleaned_author_name );
						foreach ( $co_authors_names as $ca ) {
							$co_authors_to_add[] = $ca;
						}
					} else {
						$co_authors_to_add = array( $cleaned_author_name );
					}
				}

				if ( ! empty( $co_authors_to_add ) ) {
					// add co-authors and link them to the post.
					foreach ( $co_authors_to_add as $co_author_to_add ) {
						$co_authors_ids[] = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $co_author_to_add ) );
					}

					// Assign co-atuhors to the post in question.
					$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_authors_ids, $post->ID );
					WP_CLI::line( sprintf( 'Adding co-authors to the post %d: %s', $post->ID, join( ', ', $co_authors_to_add ) ) );
				}
			}
		);

		wp_cache_flush();
	}

	public function cmd_guest_author_audit() {
		$path = get_home_path() . 'guest_author_audit.csv';
		$handle = fopen( $path, 'r' );

		$header = fgetcsv( $handle, 0 );

		while ( ! feof( $handle ) ) {
			$row = array_combine( $header, fgetcsv( $handle, 0 ) );

			$destination_guest_author_id = $this->get_or_create_guest_author( $row['new_name_1'] );

			$original_guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'post_name', sanitize_title( $row['existing_name'] ) );

			if ( false === $original_guest_author ) {
				WP_CLI::log( "GUEST AUTHOR NOT FOUND: {$row['existing_name']}" );
				continue;
			}

			WP_CLI::log( "EXISTING AUTHOR NAME: {$row['existing_name']}\t$original_guest_author->ID" );
			$post_ids = $this->coauthorsplus_logic->get_all_posts_for_guest_author( $original_guest_author->ID );

			$additional_guest_author_id = null;

			if ( ! empty( $row['new_name_2'] ) ) {
				$additional_guest_author_id = $this->get_or_create_guest_author( $row['new_name_2'] );
			}

			foreach ( $post_ids as $post_id ) {
				$guest_authors = [ $destination_guest_author_id ];

				if ( ! is_null( $additional_guest_author_id ) ) {
					$guest_authors[] = $additional_guest_author_id;
				}

				WP_CLI::log( 'REASSIGNING: ' . implode( ',', $guest_authors ) . ' Post ID: ' . $post_id );
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_authors, $post_id );
			}

			$this->coauthors_guest_authors->delete( $original_guest_author->ID );
		}
	}

	/**
	 * @param string $full_name
	 *
	 * @return int
	 */
	private function get_or_create_guest_author( string $full_name ) {
		WP_CLI::log( "GUEST AUTHOR NAME: $full_name" );
		$guest_author = $this->coauthors_guest_authors->get_guest_author_by( 'post_name', sanitize_title( $full_name ) );

		if ( false !== $guest_author ) {
			WP_CLI::log( "EXISTS! $guest_author->ID" );
			return $guest_author->ID;
		}

		$exploded = explode( ' ', $full_name );
		$last_name = array_pop( $exploded );
		$first_name = implode( ' ', $exploded );

		WP_CLI::log( 'CREATING' );
		return $this->coauthorsplus_logic->create_guest_author(
			[
				'display_name' => $full_name,
				'first_name' => $first_name,
				'last_name' => $last_name,
			]
		);
	}

	/**
	 * Clean author name from prefixes.
	 *
	 * @param string $author Author name to clean.
	 * @return string
	 */
	private function clean_author_name( $author ) {
		$prefixes = array(
			'By ',
			'By: ',
			'From Bethesda Now - By ',
			'Compiled By ',
		);

		foreach ( $prefixes as $prefix ) {
			if ( $this->str_starts_with( $author, $prefix ) ) {
				return preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/i', '', $author );
			}
		}

		return $author;
	}

	/**
	 * Checks if a string starts with a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_starts_with( $haystack, $needle ) {
		return substr( strtolower( $haystack ), 0, strlen( strtolower( $needle ) ) ) === strtolower( $needle );
	}

	/**
	 * Checks if a string ends with a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( ! $length ) {
			return true;
		}
		return substr( strtolower( $haystack ), -$length ) === strtolower( $needle );
	}

	/**
	 * Determine if a string contains a given substring
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle The substring to search for in the haystack.
	 * @return boolean
	 */
	private function str_contains( $haystack, $needle ) {
		return strpos( strtolower( $haystack ), strtolower( $needle ) ) !== false;
	}

	/**
	 * Simple file logging.
	 *
	 * @param string  $file    File name or path.
	 * @param string  $message Log message.
	 * @param boolean $to_cli Display the logged message in CLI.
	 */
	private function log( $file, $message, $to_cli = true ) {
		$message .= "\n";
		if ( $to_cli ) {
			WP_CLI::line( $message );
		}
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
