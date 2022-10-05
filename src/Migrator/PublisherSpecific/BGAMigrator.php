<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;

/**
 * Custom migration scripts for East Mojo.
 */
class BGAMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

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
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator bga-import-multiauthors',
			[ $this, 'cmd_import_multiauthors' ],
			[
				'shortdesc' => 'Imports json file with multiauthor info and assigns guest authors where needed.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'file',
						'description' => 'JSON file.',
						'optional'    => false,
						'repeating'   => false,
					],
				],

			]
		);
	}

	public function cmd_import_multiauthors( $args, $assoc_args ) {
		global $wpdb;

		$file = $assoc_args['file'];

		$file_contents = file_get_contents( $file );
		if ( ! $file_contents ) {
			WP_CLI::error( "Failed to get data from file: " . $file );
		}

		$author_data = json_decode( $file_contents, true );
		foreach ( $author_data as $original_url => $multiauthors ) {
			WP_CLI::log( "Processing: " . $original_url . " with authors: " . implode( ', ', $multiauthors ) );
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid = %s", $original_url ) );
			if ( ! $post_id ) {
				WP_CLI::warning( "Failed to find post: " . $original_url );
				continue;
			}

			$existing_guest_authors = $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id );
			$existing_author_names = wp_list_pluck( $existing_guest_authors, 'display_name' );
			if ( empty( array_diff( $multiauthors, $existing_author_names ) ) ) {
				WP_CLI::warning( "Post " . $post_id . " already has all guest authors. Skipping." );
				continue;
			}

			$guest_author_ids = [];
			foreach ( $multiauthors as $guest_author ) {
				$guest_author_name = $guest_author;
				$guest_author_login = sanitize_title( $guest_author );

				// Find guest author if already created.
				$guest_author    = $this->coauthorsplus_logic->get_guest_author_by_user_login( $guest_author_login );
				$guest_author_id = $guest_author ? $guest_author->ID : 0;

				// Create guest author if not found.
				if ( ! $guest_author_id ) {
					$guest_author_data = [
						'display_name' => $guest_author_name,
						'user_login'   => $guest_author_login,
					];
					WP_CLI::warning( "Creating guest author: " . json_encode( $guest_author_data ) );
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author( $guest_author_data );
				}

				$guest_author_ids[] = $guest_author_id;
			}

			$existing_guest_author_ids = wp_list_pluck( $existing_guest_authors, 'ID' );
			if ( empty( array_diff( $guest_author_ids, $existing_guest_author_ids ) ) ) {
				WP_CLI::warning( "Post " . $post_id . " already has all guest authors. Skipping." );
				continue;
			}

			$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id );
			WP_CLI::warning( "Updated post " . $post_id );
		}
	}
}
