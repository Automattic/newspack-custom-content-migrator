<?php

namespace NewspackCustomContentMigrator\Command\General;

use \CoAuthors_Guest_Authors;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Posts;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

class ProfilePress implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var CoAuthorPlus $coauthorsplus_logic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Posts $posts_logic
	 */
	private $posts_logic;

	/**
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
	 */
	public function cmd_pp_authors_to_gas( $pos_args, $assoc_args ) {
		// $email              = isset( $assoc_args['email'] ) ? $assoc_args['email'] : null;

		// Example of user with meta
		/**
		 * Dennis Wagner
		 * https://thecoronadonews.com/wp-admin/term.php?taxonomy=author&tag_ID=98&post_type=post&wp_http_referer=%2Fwp-admin%2Fedit-tags.php%3Ftaxonomy%3Dauthor
		 *
		 */
		// + mandatory fields
		// + extra meta fields
		//      no metas https://thecoronadonews.com/wp-admin/term.php?taxonomy=author&tag_ID=106&post_type=post&wp_http_referer=%2Fwp-admin%2Fedit-tags.php%3Ftaxonomy%3Dauthor
		// + avatar
		//      no avatar
		// + MAPPED User
		//      no mapped user https://thecoronadonews.com/wp-admin/term.php?taxonomy=author&tag_ID=106&post_type=post&wp_http_referer=%2Fwp-admin%2Fedit-tags.php%3Ftaxonomy%3Dauthor

		// Get example posts
		// regular user assignment
		//      https://thecoronadonews.com/wp-admin/edit.php?ppma_author=craig-harris
		//      https://thecoronadonews.com/wp-admin/post.php?post=14373&action=edit
		// no mapped user, metas, avatar
		//      https://thecoronadonews.com/wp-admin/edit.php?ppma_author=defense-visual-information-distribution-service
		//      https://thecoronadonews.com/wp-admin/post.php?post=13631&action=edit

		// ------------------

		global $wpdb;

		$logs = [

		];

		// $post_ids = $this->posts_logic->get_all_posts_ids();
$post_ids = [ 14373, 13631 ];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$ga_arg = [];

			$term_taxonomy_ids = "select term_taxonomy_id from wp_term_relationships where object_id = 14373 order by term_order ;";
			foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
				// Reset GAs for this post.
				$ga_ids = [
					'coronado_authors__created_ga.txt',
					'coronado_authors__found_existing_ga.txt'
				];

				// Get Author terms.
				$term_ids = $wpdb->get_col( $wpdb->prepare(
					"select term_id from wp_term_taxonomy where taxonomy = 'author' and term_taxonomy_id = %d; ",
					$term_taxonomy_id
				) );
				// Loop through Author terms.
				foreach ( $term_ids as $term_id ) {
					$full_name = $wpdb->get_var( $wpdb->prepare( "select name from wp_terms where term_id = %d; ", $term_id ) );

					// Get or create GA
					$ga_id = null;
					$existing_ga = $this->coauthorsplus_logic->get_guest_author_by_display_name( $full_name );
					if ( $existing_ga ) {
						$ga_id = $existing_ga->ID;
						$this->logger->log(
							$logs['coronado_authors__found_existing_ga.txt'],
							sprintf( "FOUND_EXISTING_GA ga_id=%d post_id=%d term_id=%d full_name=%s", $ga_id, $post_id, $term_id, $full_name )
						);

					} else {
						$termmetas = $wpdb->get_results( $wpdb->prepare( "select * from wp_termmeta where term_id = %d; ", $term_id ) );

						$ga_arg = [
							'display_name' => $full_name,
							'user_login'   => isset( $termmetas['user_login'] )  && ! empty( $termmetas['user_login'] )  ? $termmetas['user_login']  : null,
							'first_name'   => isset( $termmetas['first_name'] )  && ! empty( $termmetas['first_name'] )  ? $termmetas['first_name']  : null,
							'last_name'    => isset( $termmetas['last_name'] )   && ! empty( $termmetas['last_name'] )   ? $termmetas['last_name']   : null,
							'user_email'   => isset( $termmetas['user_email'] )  && ! empty( $termmetas['user_email'] )  ? $termmetas['user_email']  : null,
							'website'      => isset( $termmetas['user_url'] )    && ! empty( $termmetas['user_url'] )    ? $termmetas['user_url']    : null,
							'description'  => isset( $termmetas['description'] ) && ! empty( $termmetas['description'] ) ? $termmetas['description'] : null,
							'avatar'       => isset( $termmetas['avatar'] )      && ! empty( $termmetas['avatar'] )      ? $termmetas['avatar']      : null,
						];
						$ga_id = $this->coauthorsplus_logic->create_guest_author( $ga_arg );

						// Link to WP User.
						$mapped_wp_user_id = isset( $termmetas['user_id'] ) && ! empty( $termmetas['user_id'] ) ? $termmetas['user_id'] : null;
						$wp_user = null;
						if ( $mapped_wp_user_id ) {
							$wp_user = get_user_by( 'ID', $mapped_wp_user_id );
							$this->coauthorsplus_logic->link_guest_author_to_wp_user( $ga_id, $wp_user );
						}

						$this->logger->log(
							$logs['coronado_authors__created_ga.txt'],
							sprintf( "CREATED_GA ga_id=%d post_id=%d term_id=%d full_name=%s linked_wp_user=%d", $ga_id, $post_id, $term_id, $full_name, $wp_user->ID ?? '/' )
						);
					}

					// Add this GA ID.
					if ( is_null( $ga_id ) ) {
						throw new \RuntimeException( sprintf( "Not created author from term_id %d", $term_id ) );
					}
					$ga_ids[] = $ga_id;
				}

				// Assign to Post.
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $ga_ids, $post_id );
				$this->logger->log(
					$logs['coronado_authors__assigned_gas_to_post.txt'],
					sprintf( "ASSIGNED_GAS_TO_POST ga_ids=%s post_id=%d", implode( ',', $ga_ids ), $post_id )
				);
			}
		}

		echo "Done.";
	}
}
