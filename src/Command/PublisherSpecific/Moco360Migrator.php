<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
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
	 * @var CoAuthorPlus $coauthorsplus_logic
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
		$this->coauthorsplus_logic = new CoAuthorPlus();
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
		// Load GAs.
		// Load posts GAs and WP User authors.

		// Recreate GAs.

		// Find posts by original ID and assign GAs.

	}
}
