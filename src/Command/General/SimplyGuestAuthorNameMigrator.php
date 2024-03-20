<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

class SimplyGuestAuthorNameMigrator implements InterfaceCommand {

	/**
	 * @var CoAuthorPlusLogic
	 */
	private $coauthorsplus_logic;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic = null;

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->logger              = new Logger();
		$this->posts_logic         = new PostsLogic();
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

		WP_CLI::add_command(
			'newspack-content-migrator migrate-simply-guest-author-names',
			[ $this, 'cmd_migrate_simply_guest_author_names' ],
			[
				'shortdesc' => 'Migrate Simply Guest Author Names to CoAuthorsPlus.',
			]
		);

	}

	/**
	 * Migrate Simply Guest Author Names to CoAuthorsPlus.
	 */
	public function cmd_migrate_simply_guest_author_names( $args, $assoc_args ) {

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}
		
		$log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $log, 'Starting migration.' );

		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
				'fields'      => 'ids',
				// Order by date desc so newest GAs will have newest Bios.
				'orderby'     => 'date',
				'order'       => 'DESC',
			),
			function ( $post_id ) use ( $log ) {

				$this->logger->log( $log, 'Post id: ' . $post_id );

				$sfly_names = get_post_meta( $post_id, 'sfly_guest_author_names', true );

				// Remove unicode line breaks and left-to-right ("u" modifier)
				$sfly_names = trim( preg_replace( '/\x{2028}|\x{200E}/u', '', $sfly_names ) );

				// Replace unicode spaces with normal space, ("u" modifier)
				$sfly_names = trim( preg_replace( '/\x{00A0}|\x{200B}|\x{202F}|\x{FEFF}/u', ' ', $sfly_names ) );

				// Replace multiple spaces with single space
				$sfly_names = trim( preg_replace( '/\s{2,}/', ' ', $sfly_names ) );

				// Remove leading "by" (case insensitive)
				$sfly_names = trim( preg_replace( '/^by\s+/i', '', $sfly_names ) );
				
				if( empty( $sfly_names ) ) {
					$this->logger->log( $log, 'Skip: no simply guest author name.' );
					return;
				}

				// Get existing GA if exists.
				$ga = $this->coauthorsplus_logic->get_guest_author_by_user_login( sanitize_title( urldecode( $sfly_names ) ) );
				
				// Create.
				if( false === $ga  ) {
					
					$created_ga_id = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $sfly_names ) );

					if( is_wp_error( $created_ga_id ) || ! is_numeric( $created_ga_id ) || ! ( $created_ga_id > 0 ) ) {
						$this->logger->log( $log, 'GA create failed: ' . $sfly_names, $this->logger::WARNING );		
						return;
					}

					$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $created_ga_id );

				}

				$this->logger->log( $log, 'GA ID: ' . $ga->ID );

				// Assign to post.
				$this->coauthorsplus_logic->assign_guest_authors_to_post( array ( $ga->ID ), $post_id );

				// Skip Bio creation if already set.
				if( ! empty( trim( $ga->description ) ) ) return;
				
				$sfly_description = trim( get_post_meta( $post_id, 'sfly_guest_author_description', true ) );
				$sfly_link        = trim( get_post_meta( $post_id, 'sfly_guest_link', true ) );

				$new_desc = array();

				if( ! empty( $sfly_description ) ) $new_desc[] = '<p>' . sanitize_textarea_field( $sfly_description ) . '</p>';
				if( ! empty( $sfly_link ) )        $new_desc[] = '<p><a href="' . sanitize_url( $sfly_link ) . '">Link</a></p>';

				if( empty( $new_desc ) ) return;
				
				$this->logger->log( $log, 'New Description: ' . implode( "", $new_desc ) );

				$this->coauthorsplus_logic->update_guest_author( $ga->ID, array( 'description' => implode( "\n", $new_desc ) ) );
		
			} // callback function

		); // throttled posts

		wp_cache_flush();

		$this->logger->log( $log, 'Done.', $this->logger::SUCCESS );

	}

}
