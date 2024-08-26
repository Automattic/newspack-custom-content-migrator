<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \WP_CLI;

/**
 * Custom migration scripts for Moco360.
 */
class Moco360Migrator implements InterfaceCommand {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorsPlusHelper $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var PostsLogic $posts_logic
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorsPlusHelper();
		$this->posts_logic = new PostsLogic();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
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
		WP_CLI::add_command(
			'newspack-content-migrator moco360-bethesda-get-gas-and-their-posts',
			[ $this, 'cmd_bethesda_get_gas_and_their_posts' ]
		);
		WP_CLI::add_command(
			'newspack-content-migrator moco360-recreate-gas',
			[ $this, 'cmd_moco360_recreate_gas' ]
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_bethesda_get_gas_and_their_posts( $args, $assoc_args ) {
		// Get all GAs.
		WP_CLI::log( 'Getting all GAs...' );
		$gas = $this->coauthorsplus_logic->get_all_gas();
		$gas_arr = [];
		foreach ( $gas as $ga ) {
			$gas_arr[] = (array) $ga;
		}
		// If GA is linked to WP User account is stored in 'linked_account'.

		// Save GA authors info file.
		$gas_file = 'bethesda_gas.json';
		file_put_contents( $gas_file, json_encode( $gas_arr) );
		WP_CLI::success( sprintf( 'Saved %s', $gas_file ) );

		// Get all Posts' GAs and WP User authors.
		WP_CLI::log( 'Getting Post IDs...' );
		$post_ids = $this->posts_logic->get_all_posts_ids();
		$post_ids_ga_ids = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );
			$ga_ids = $this->coauthorsplus_logic->get_posts_existing_ga_ids( $post_id );
			if ( ! empty( $ga_ids ) ) {
				$post_ids_ga_ids[] = [
					'post_id' => $post_id,
					'ga_ids' => $ga_ids,
				];
			}
		}

		// Save Post IDs' GAs.
		$postids_gaids_file = 'bethesda_postids_gaids.json';
		file_put_contents( $postids_gaids_file, json_encode( $post_ids_ga_ids) );
		WP_CLI::success( sprintf( 'Saved %s', $postids_gaids_file ) );
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_moco360_recreate_gas( $args, $assoc_args ) {
		// Get GAs and posts' GAs from Bethesda site.
		$gas_file = 'bethesda_gas.json';
		if ( ! file_exists( $gas_file) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $gas_file ) );
		}
		$gas = json_decode( file_get_contents( $gas_file ), true );

		$postids_gaids_file = 'bethesda_postids_gaids.json';
		if ( ! file_exists( $postids_gaids_file) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $postids_gaids_file ) );
		}
		$postids_gaids = json_decode( file_get_contents( $postids_gaids_file ), true );

		// Recreate GAs.
		$new_gas_mappings = [];
		foreach ( $gas as $ga ) {
			$existing_ga = $this->coauthorsplus_logic->get_guest_author_by_display_name( $ga['display_name'] );
			if ( false == $existing_ga ) {
				$new_gaid = $this->coauthorsplus_logic->create_guest_author( $ga );
				WP_CLI::success( sprintf( 'Created new GA ID %d from old GA ID %s', $new_gaid, $ga['ID'] ) );
				$new_gas_mappings[ $ga['ID'] ] = $new_gaid;
			}
		}
		if ( ! empty( $new_gas_mappings ) ) {
			WP_CLI::success( sprintf( 'Recreated these GA IDs:' ) );
			WP_CLI::log( json_encode( $new_gas_mappings ) );
		}

		// Find posts by original ID and assign GAs.
		$not_found_old_post_ids = [];
		$updated_post_ids = [];
		foreach ( $postids_gaids as $key_postid_gaids => $postid_gaids ) {
			$old_post_id = $postid_gaids['post_id'];
			$gas_ids = $postid_gaids['ga_ids'];

			// Get new post ID.
			$res = $this->posts_logic->get_posts_with_meta_key_and_value( 'newspackcontentdiff_live_id', $old_post_id );
			$new_post_id = $res[0] ?? null;
			if ( is_null( $new_post_id ) ) {
				WP_CLI::warning( sprintf( 'Old Post ID %d not found!', $old_post_id ) );
				$not_found_old_post_ids[] = $old_post_id;
				continue;
			}

			WP_CLI::log( sprintf( '(%d)/(%d) oldID %d newID %d', $key_postid_gaids + 1, count( $postids_gaids ), $old_post_id, $new_post_id ) );

			// Get new GAs IDs.
			$new_gas_ids = [];
			foreach ( $gas_ids as $old_ga_id ) {
				$new_gas_ids[] = isset( $new_gas_mappings[$old_ga_id] )
					? $new_gas_mappings[$old_ga_id]
					: $old_ga_id;
			}

			$this->coauthorsplus_logic->assign_guest_authors_to_post( $new_gas_ids, $new_post_id, false );
			$updated_post_ids[] = $new_post_id;
		}

		if ( ! empty( $not_found_old_post_ids ) ) {
			$notfound_old_post_ids_log = 'moco_notfoundoldpostids.json';
			file_put_contents( $notfound_old_post_ids_log, json_encode( $not_found_old_post_ids) );
			WP_CLI::warning( sprintf( 'Not found %d old Post IDs, saved to %s.', count( $not_found_old_post_ids ), $notfound_old_post_ids_log ) );
		}
		if ( ! empty( $updated_post_ids ) ) {
			$updated_postids_gas_log = 'moco_updatedpostidsgas.json';
			file_put_contents( $updated_postids_gas_log, json_encode( $updated_post_ids) );
			WP_CLI::success( sprintf( 'Updated %d Post IDs, saved to %s.', count( $updated_post_ids ), $updated_postids_gas_log ) );
		}
	}
}
