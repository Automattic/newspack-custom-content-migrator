<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

/**
 * Profile Press reusable commands.
 */
class ProfilePress implements InterfaceCommand {

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * CoAuthorPlus logic.
	 *
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * Posts logic.
	 *
	 * @var Posts $posts_logic
	 */
	private $posts_logic;

	/**
	 * Logger.
	 *
	 * @var Logger $logger.
	 */
	private $logger;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->posts_logic         = new Posts();
		$this->logger              = new Logger();
	}

	/**
	 * Sets up Co-Authors Plus plugin dependencies.
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
		WP_CLI::add_command(
			'newspack-content-migrator profilepress-authors-to-guest-authors',
			[ $this, 'cmd_pp_authors_to_gas' ],
			[
				'shortdesc' => 'Converts Profile Press authors to CAP GAs.',
			]
		);
	}

	/**
	 * Convert PP Authors to GAs.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @throws \RuntimeException If author term wasn't successfully converted to GA.
	 */
	public function cmd_pp_authors_to_gas( $pos_args, $assoc_args ) {
		global $wpdb;

		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Reset GAs for this post.
			$ga_ids = [];

			// Convert terms to GAs and get those GA IDs.
			$term_taxonomy_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select term_taxonomy_id from $wpdb->term_relationships where object_id = %d order by term_order ;",
					$post_id
				)
			);
			foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {

				// Get Author term.
				$term_id = $wpdb->get_var(
					$wpdb->prepare(
						"select term_id from $wpdb->term_taxonomy where taxonomy = 'author' and term_taxonomy_id = %d; ",
						$term_taxonomy_id
					)
				);
				if ( ! $term_id ) {
					continue;
				}

				$full_name = $wpdb->get_var( $wpdb->prepare( "select name from $wpdb->terms where term_id = %d; ", $term_id ) );

				// Get or create GA.
				$ga_id       = null;
				$existing_ga = $this->coauthorsplus_logic->get_guest_author_by_display_name( $full_name );
				if ( $existing_ga ) {
					// Existing GA.
					$ga_id = $existing_ga->ID;
					$this->logger->log(
						'coronado_authors__found_existing_ga.log',
						sprintf( 'FOUND_EXISTING_GA ga_id=%d post_id=%d term_id=%d full_name=%s', $ga_id, $post_id, $term_id, $full_name )
					);
				} else {
					// New GA.
					$ga_arg = [];

					// Display name.
					$ga_arg['display_name'] = $full_name;

					// Get Author info/meta.
					$termmetas = $wpdb->get_results( $wpdb->prepare( "select * from $wpdb->termmeta where term_id = %d; ", $term_id ), ARRAY_A );
					foreach ( $termmetas as $termmeta ) {
						$mapping_metas_profilepress_to_cap = [
							'first_name'  => 'first_name',
							'last_name'   => 'last_name',
							'user_email'  => 'user_email',
							'user_url'    => 'website',
							'description' => 'description',
							'avatar'      => 'avatar',
						];
						$pp_meta_key                       = $termmeta['meta_key'];
						$pp_meta_value                     = $termmeta['meta_value'];
						if ( array_key_exists( $pp_meta_key, $mapping_metas_profilepress_to_cap ) && ! empty( $pp_meta_value ) ) {
							$ga_arg[ $mapping_metas_profilepress_to_cap[ $pp_meta_key ] ] = $pp_meta_value;
						}
					}
					$ga_id = $this->coauthorsplus_logic->create_guest_author( $ga_arg );

					// Link to WP User.
					$wp_user                    = null;
					$mapped_wp_user_id_termmeta = array_filter(
						$termmetas,
						function ( $termmeta ) {
							if ( 'user_id' == $termmeta['meta_key'] && ! empty( $termmeta['meta_value'] ) ) {
								return $termmeta['meta_value'];
							}
						}
					);
					$mapped_wp_user_id          = $mapped_wp_user_id_termmeta[0]['meta_value'] ?? null;
					if ( $mapped_wp_user_id ) {
						$wp_user = get_user_by( 'ID', $mapped_wp_user_id );
						$this->coauthorsplus_logic->link_guest_author_to_wp_user( $ga_id, $wp_user );
					}

					$this->logger->log(
						'coronado_authors__created_ga.log',
						sprintf( 'CREATED_GA ga_id=%d post_id=%d term_id=%d full_name=%s linked_wp_user=%d', $ga_id, $post_id, $term_id, $full_name, $wp_user->ID ?? '/' )
					);
				}

				// Add this GA ID.
				$ga_ids[] = $ga_id;

				if ( is_null( $ga_id ) ) {
					throw new \RuntimeException( sprintf( 'Not created author from term_id %d', $term_id ) );
				}
			}

			// Assign to Post.
			if ( ! empty( $ga_ids ) ) {
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $ga_ids, $post_id );
				$this->logger->log(
					'coronado_authors__assigned_gas_to_post.log',
					sprintf( 'ASSIGNED_GAS_TO_POST post_id=%d ga_ids=%s', $post_id, implode( ',', $ga_ids ) )
				);
			} else {
				$this->logger->log(
					'coronado_authors__no_authors_assigned_to_post.log',
					sprintf( 'NO_AUTHORS_ASSIGNED_TO_POST post_id=%d', $post_id )
				);
			}
		}

		echo 'Done.';
	}
}
