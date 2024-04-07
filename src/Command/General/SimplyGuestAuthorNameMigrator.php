<?php
/**
 * Newspack Custom Content Migrator (Simply) Guest Author Name plugin authorship to CAP.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for (Simply) Guest Author Name plugin.
 */
class SimplyGuestAuthorNameMigrator implements InterfaceCommand {

	/**
	 * CoAuthorPlusLogic
	 * 
	 * @var CoAuthorPlusLogic 
	 */
	private $coauthorsplus_logic;

	/**
	 * Logger
	 * 
	 * @var Logger
	 */
	private $logger;

	/**
	 * PostsLogic
	 * 
	 * @var PostsLogic
	 */
	private $posts_logic = null;

	/**
	 * Instance
	 * 
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
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'overwrite-post-gas',
						'description' => 'Overwrite GAs if they already exist on the post.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Migrate Simply Guest Author Names to CoAuthorsPlus.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_simply_guest_author_names( $pos_args, $assoc_args ) {
		
		WP_CLI::line( 'This command does NOT break apart multi-author strings (ex: "Jill Doe, Mary Parker, and Jim Smith").' );
		WP_CLI::line( 'The full string will be used as the display name to create a single GA.' );
		
		WP_CLI::confirm( 'Continue?' );

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}
		
		$log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $log, 'Starting migration.' );

		$overwrite_post_gas = ( isset( $assoc_args['overwrite-post-gas'] ) ) ? true : false;

		if( $overwrite_post_gas ) $this->logger->log( $log, 'With --overwrite-post-gas.' );

		// Must have required postmeta value.
		$meta_query = array(
			array(
				'key'     => 'sfly_guest_author_names',
				'value'   => '',
				'compare' => '!=',
			),
		);              

		$this->posts_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
				'fields'      => 'ids',
				'meta_query'  => $meta_query,
				// Order by date desc so newest GAs will have newest Bios.
				'orderby'     => 'date',
				'order'       => 'DESC',
			),
			function ( $post_id ) use ( $log, $overwrite_post_gas ) {

				$this->logger->log( $log, 'Post id: ' . $post_id );

				$sfly_names = get_post_meta( $post_id, 'sfly_guest_author_names', true );

				// Remove unicode line breaks and left-to-right ("u" modifier).
				$sfly_names = trim( preg_replace( '/\x{2028}|\x{200E}/u', '', $sfly_names ) );

				// Replace unicode spaces with normal space, ("u" modifier).
				$sfly_names = trim( preg_replace( '/\x{00A0}|\x{200B}|\x{202F}|\x{FEFF}/u', ' ', $sfly_names ) );

				// Replace multiple spaces with single space.
				$sfly_names = trim( preg_replace( '/\s{2,}/', ' ', $sfly_names ) );

				// Remove leading "by" (case insensitive).
				$sfly_names = trim( preg_replace( '/^by\s+/i', '', $sfly_names ) );
				
				if ( empty( $sfly_names ) ) {
					$this->logger->log( $log, 'Skip: no simply guest author name.' );
					return;
				}

				// Get existing GA if exists.
				// As of 2024-03-19 the use of 'coauthorsplus_logic->create_guest_author()' to return existing match
				// may return an error. WP Error occures if existing database GA is "Jon A. Doe" but new GA is "Jon A Doe".
				// New GA will not match on display name, but will fail on create when existing sanitized slug is found.
				// Use a more direct approach here.
				$ga = $this->coauthorsplus_logic->get_guest_author_by_user_login( sanitize_title( urldecode( $sfly_names ) ) );
				
				// Create.
				if ( false === $ga ) {
					
					$created_ga_id = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $sfly_names ) );

					if ( is_wp_error( $created_ga_id ) || ! is_numeric( $created_ga_id ) || ! ( $created_ga_id > 0 ) ) {
						$this->logger->log( $log, 'GA create failed: ' . $sfly_names, $this->logger::ERROR, true );
					}

					$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $created_ga_id );

				}

				$this->logger->log( $log, 'GA ID: ' . $ga->ID );

				// Assign to post.

				$existing_post_gas = $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id );
				$this->logger->log( $log, 'Existing post GAs count:' . count( $existing_post_gas) );

				// If no GAs exist on post, or "overwrite" is true, then set gas to post. 
				if( 0 == count( $existing_post_gas) || $overwrite_post_gas ) {
					$this->coauthorsplus_logic->assign_guest_authors_to_post( array( $ga->ID ), $post_id );
					$this->logger->log( $log, 'Assigned GAs to post.' );
				}
				else {
					$this->logger->log( $log, 'Post GAs unchanged.' );
				}

				// Skip Bio creation if already set.
				if ( ! empty( trim( $ga->description ) ) ) {
					$this->logger->log( $log, 'GA bio exists.' );
					return;
				}
				
				$sfly_description = trim( get_post_meta( $post_id, 'sfly_guest_author_description', true ) );
				$sfly_link        = trim( get_post_meta( $post_id, 'sfly_guest_link', true ) );
				// TODO: incorporate 'sfly_guest_author_email' into GA profile/bio.

				$new_desc = array();

				if ( ! empty( $sfly_description ) ) {
					$new_desc[] = '<p>' . sanitize_textarea_field( $sfly_description ) . '</p>';
				}
				if ( ! empty( $sfly_link ) ) {
					$new_desc[] = '<p><a href="' . sanitize_url( $sfly_link ) . '">Link</a></p>';
				}

				if ( empty( $new_desc ) ) {
					$this->logger->log( $log, 'No new bio info.' );
					return;
				}
				
				$this->logger->log( $log, 'New Description: ' . implode( '', $new_desc ) );

				// Add bio to GA.
				// As of 2024-03-20, "coauthorsplus_logic->update_guest_author" for "description" field will replace
				// line breaks with just the letter "n" when updating the database.
				// Use a direct update here.
				update_post_meta( $ga->ID, 'cap-description', implode( PHP_EOL, $new_desc ) );
			}
		);

		wp_cache_flush();

		$this->logger->log( $log, 'Done.', $this->logger::SUCCESS );
	}
}
