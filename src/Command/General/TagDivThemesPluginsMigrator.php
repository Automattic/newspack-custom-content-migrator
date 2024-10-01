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

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper as CoAuthorPlusLogic;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
useNewspack\MigrationTools\Util\Logger;
use WP_CLI;

/**
 * Custom migration scripts for TagDiv (company) Themes and Plugins.
 */
class TagDivThemesPluginsMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	const TD_POST_THEME_SETTINGS             = 'td_post_theme_settings';
	const TD_POST_THEME_SETTINGS_SOURCE      = 'td_source';
	const TD_POST_THEME_SETTINGS_PRIMARY_CAT = 'td_primary_cat';

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
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->logger              = new Logger();
		$this->posts_logic         = new PostsLogic();
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator migrate-tagdiv-authors-to-gas',
			self::get_command_closure( 'cmd_migrate_tagdiv_authors_to_gas' ),
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

		WP_CLI::add_command(
			'newspack-content-migrator migrate-tagdiv-primary-categories',
			self::get_command_closure( 'cmd_migrate_tagdiv_primary_categories' ),
			[
				'shortdesc' => 'Migrate TagDiv Primary Categories to Yoast.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'No updates.',
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

		$overwrite_post_gas = ( isset( $assoc_args['overwrite-post-gas'] ) ) ? true : false;

		if ( $overwrite_post_gas ) {
			$this->logger->log( $this->log, 'With --overwrite-post-gas.' );
		}

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
			
			// Order by date desc for better logging in order.
			'orderby'     => 'date',
			'order'       => 'DESC',

		);

		$this->posts_logic->throttled_posts_loop( 
			$args,
			function ( $post_id ) use( $overwrite_post_gas ) {

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

				$existing_post_gas = $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id );
				$this->logger->log( $this->log, 'Existing post GAs count:' . count( $existing_post_gas ) );

				// If no GAs exist on post, or "overwrite" is true, then set gas to post. 
				if ( 0 == count( $existing_post_gas ) || $overwrite_post_gas ) {
					$this->coauthorsplus_logic->assign_guest_authors_to_post( array( $ga->ID ), $post_id );
					$this->logger->log( $this->log, 'Assigned GAs to post.' );
				} else {
					$this->logger->log( $this->log, 'Post GAs unchanged.' );
				}
			},
			1,
			100
		);

		wp_cache_flush();

		$this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
	}

	/**
	 * Migrate TagDiv Primary Categories to Yoast.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_tagdiv_primary_categories( $pos_args, $assoc_args ) {

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Doing migration.' );

		$dry_run = false;
		if ( isset( $assoc_args['dry-run'] ) ) {
			$dry_run = true;
			$this->logger->log( $this->log, '--dry-run.' );
		}

		// Reusable function.
		$set_yoast_primary_function = function ( $post_id, $category_id ) use ( $dry_run ) {
			if ( $dry_run ) {
				$this->logger->log( $this->log, '(dry-run) Update yoast primary: ' . $category_id );
			} else {
				$this->logger->log( $this->log, 'Update yoast primary: ' . $category_id );
				update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category_id );
			}
		};

		$args = array(
			'post_type'   => 'post',
			'post_status' => array( 'publish' ),
			'fields'      => 'ids',
			// Order by date for better logging in order.
			'orderby'     => 'date',
			'order'       => 'DESC',
		);

		$this->posts_logic->throttled_posts_loop( 
			$args,
			function ( $post_id ) use ( $set_yoast_primary_function ) {

				$this->logger->log( $this->log, '---- Post id: ' . $post_id );

				// Get postmeta: 'td_post_theme_settings' => 'a:3:{s:14:\"td_primary_cat\";s:2:\"36\";...}'.
				$postmeta = get_post_meta( $post_id, self::TD_POST_THEME_SETTINGS, true );

				// If primary category key ('td_primary_cat') exists in postmeta array (unserialized).
				if ( isset( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] ) 
					&& is_numeric( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] ) 
					&& ( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] > 0 )
				) {

					// Verify category id is real category.
					$category = get_category( $postmeta[ self::TD_POST_THEME_SETTINGS_PRIMARY_CAT ] );

					// If exists, set it to yoast.
					if ( ! is_wp_error( $category ) && is_object( $category ) && property_exists( $category, 'term_id' ) ) {

						$this->logger->log( $this->log, 'Using postmeta value.' );

						// Before setting yoast postmeta, add category to post.
						// note: wp will skip if category/post relationship alredy exists.
						// append: true.
						wp_set_post_categories( $post_id, $category->term_id, true );

						return $set_yoast_primary_function( $post_id, $category->term_id );

					} // if category exists.

				} // postmeta array key exists.

				// If postmeta didn't work, then TagDiv's "Auto select primary category" will default to the first category.
				$categories = wp_get_post_categories( $post_id );

				if ( is_array( $categories ) && ! empty( $categories[0] ) ) {

					$this->logger->log( $this->log, 'Using first category.' );

					return $set_yoast_primary_function( $post_id, $categories[0] );

				} // first post category.
			} // callback function.
		); // loop.

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
