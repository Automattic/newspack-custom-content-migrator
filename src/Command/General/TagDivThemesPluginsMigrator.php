<?php
/**
 * Newspack Custom Content Migrator: TagDiv (company) Themes and Plugins Migrator.
 * 
 * Commands related to migrating Themes and Plugins developed by TagDiv company.
 * 
 * @link: https://tagdiv.com/
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
 * Custom migration scripts for TagDiv (company) Themes and Plugins.
 */
class TagDivThemesPluginsMigrator implements InterfaceCommand {

	const TD_POST_THEME_SETTINGS = 'td_post_theme_settings';

	const TD_POST_THEME_SETTINGS_SOURCE = 'td_source';
	
	/**
	 * CoAuthorPlusLogic
	 * 
	 * @var CoAuthorPlusLogic 
	 */
	private $coauthorsplus_logic;

	/**
	 * Log (file path)
	 *
	 * @var string $log
	 */
	private $log;

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
			'newspack-content-migrator migrate-tagdiv-authors-to-gas',
			[ $this, 'cmd_migrate_tagdiv_authors_to_gas' ],
			[
				'shortdesc' => 'Migrate TagDiv authors to GAs.',
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
	 * Migrate TagDiv Authors to CAP GAs.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_tagdiv_authors_to_gas( $pos_args, $assoc_args ) {

		WP_CLI::line( 'This command does NOT break apart multi-author strings (ex: "Jill Doe, Mary Parker, and Jim Smith").' );
		WP_CLI::line( 'The full string will be used as the display name to create a single GA.' );
		
		WP_CLI::confirm( 'Continue?' );

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin not found. Install and activate it before using this command.' );
		}

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Doing migration.' );

		$args = array(

			'post_type'   => 'post',
			'post_status' => array( 'publish' ),
			'fields'      => 'ids',
			
			// Must have required postmeta value.
			'meta_query'  => array(
				array(
					'key'     => self::TD_POST_THEME_SETTINGS,
					'value'   => '"' . self::TD_POST_THEME_SETTINGS_SOURCE . '"',
					'compare' => 'LIKE',
				),
			),
			
			// Default: only process posts where GA does not already exist.
			'tax_query'   => array(
				array(
					'taxonomy' => 'author',
					'operator' => 'NOT EXISTS', 
				),
			),
			
			// Default: keep results to first page because as each GA get's set the tax_query will only get the remaing posts without need for paging.
			'paged'       => 1,

			// Order by date desc for better logging in order.
			'orderby'     => 'date',
			'order'       => 'DESC',

		);

		// Optional: overwrite existing GAs on posts.
		if ( isset( $assoc_args['overwrite-post-gas'] ) ) {

			// Remove the tax_query "not exists" exclusion so that rows where authors do exist will be re-processed.
			unset( $args['tax_query'] );

			// Allow throttled posts loop set the paging so all rows will be processed (with or without an existing author).
			unset( $args['paged'] );

		}

		$this->posts_logic->throttled_posts_loop( 
			$args,
			function ( $post_id ) {

				$this->logger->log( $this->log, 'Post id: ' . $post_id );

				$settings = get_post_meta( $post_id, self::TD_POST_THEME_SETTINGS, true );

				if ( empty( $settings[ self::TD_POST_THEME_SETTINGS_SOURCE ] ) ) {

					$this->logger->log( $this->log, 'Empty post meta source.', $this->logger::WARNING );
					return;

				}

				$source = $this->sanitize_source( $settings[ self::TD_POST_THEME_SETTINGS_SOURCE ] );

				if ( empty( $source ) ) {
					
					$this->logger->log( $this->log, 'Empty sanitized source.', $this->logger::WARNING );
					return;
				}

				// Get existing GA if exists.
				// As of 2024-03-19 the use of 'coauthorsplus_logic->create_guest_author()' to return existing match
				// may return an error. WP Error occures if existing database GA is "Jon A. Doe" but new GA is "Jon A Doe".
				// New GA will not match on display name, but will fail on create when existing sanitized slug is found.
				// Use a more direct approach here.
				$ga = $this->coauthorsplus_logic->get_guest_author_by_user_login( sanitize_title( urldecode( $source ) ) );
				
				// Create.
				if ( false === $ga ) {
					
					$created_ga_id = $this->coauthorsplus_logic->create_guest_author( array( 'display_name' => $source ) );

					if ( is_wp_error( $created_ga_id ) || ! is_numeric( $created_ga_id ) || ! ( $created_ga_id > 0 ) ) {
						$this->logger->log( $this->log, 'GA create failed: ' . $source, $this->logger::ERROR, true );
					}

					$ga = $this->coauthorsplus_logic->get_guest_author_by_id( $created_ga_id );

				}

				$this->logger->log( $this->log, 'GA ID: ' . $ga->ID );

				// Assign to post.
				$this->coauthorsplus_logic->assign_guest_authors_to_post( array( $ga->ID ), $post_id );
			},
			1,
			100
		);

		wp_cache_flush();

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Sanitize DB post meta author source names.
	 *
	 * @param string $source String from post meta.
	 * @return string $source
	 */
	private function sanitize_source( $source ) {

		// Remove unicode line breaks and left-to-right ("u" modifier).
		$source = trim( preg_replace( '/\x{2028}|\x{200E}/u', '', $source ) );

		// Replace unicode spaces with normal space, ("u" modifier).
		$source = trim( preg_replace( '/\x{00A0}|\x{200B}|\x{202F}|\x{FEFF}/u', ' ', $source ) );

		// Replace multiple spaces with single space.
		$source = trim( preg_replace( '/\s{2,}/', ' ', $source ) );

		// Remove leading "by" (case insensitive).
		$source = trim( preg_replace( '/^by\s+/i', '', $source ) );

		return $source;
	}
}
