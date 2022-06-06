<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use \WP_CLI;

/**
 * Custom migration scripts for Ithaca Voice.
 */
class IthacaVoiceMigrator implements InterfaceMigrator {
	const GALLERIES_MIGRATION_LOGS = 'ithaca_galleries_migration.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_migrator_logic;

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_migrator_logic              = new PostsLogic();
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
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
			'newspack-content-migrator ithaca-migrate-best-wordpress-gallery',
			array( $this, 'ithaca_migrate_best_wordpress_gallery' ),
			array(
				'shortdesc' => 'Migrate Best Wordpress Gallery plugin galleries to Jetpack Slideshow blocks.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator ithaca-migrate-best-wordpress-gallery`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function ithaca_migrate_best_wordpress_gallery( $args, $assoc_args ) {
		global $wpdb;

		$bwg_tables = array( 'bwg_shortcode', 'bwg_gallery', 'bwg_image' );

		$this->log( self::GALLERIES_MIGRATION_LOGS, sprintf( 'Checking if Best WordPress Gallery plugin tables exists (%s)', join( ', ', $bwg_tables ) ) );
		$valid_tables = $this->validate_db_tables_exist( $bwg_tables );

		if ( ! $valid_tables ) {
			$this->log( self::GALLERIES_MIGRATION_LOGS, 'Best WordPress Gallery tables are missing' );
			die();
		}

		$this->log( self::GALLERIES_MIGRATION_LOGS, 'Starting the migration ...' );

		$this->posts_migrator_logic->throttled_posts_loop(
			array(
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			),
			function( $post ) use ( $wpdb ) {
				if ( strpos( strtolower( $post->post_content ), strtolower( '[Best_Wordpress_Gallery' ) ) !== false ) {
					$matches_shortcodes = $this->squarebracketselement_manipulator->match_shortcode_designations( 'Best_Wordpress_Gallery', $post->post_content );

					if ( empty( $matches_shortcodes[0] ) ) {
						$this->log( self::GALLERIES_MIGRATION_LOGS, sprintf( "Can't parse the gallery shortcode in the post %d", $post->ID ) );
						return;
					}

					$post_content_updated = $post->post_content;

					foreach ( $matches_shortcodes[0] as $shortcode_match ) {
						$shortcode_html           = str_replace( '”', '"', $shortcode_match );
						$shortcode_id             = $this->squarebracketselement_manipulator->get_attribute_value( 'id', $shortcode_html );
						$missing_gallery_pictures = array();

						// Get gallery.
						$gallery_tag_text = $wpdb->get_col(
							$wpdb->prepare(
								"select tagtext from {$wpdb->prefix}bwg_shortcode where id = %d",
								$shortcode_id
							)
						);

						preg_match( '/gallery_id="(?<gallery_id>[\d]+)"/', $gallery_tag_text[0], $gallery_id_match );
						if ( ! array_key_exists( 'gallery_id', $gallery_id_match ) ) {
							$this->log( self::GALLERIES_MIGRATION_LOGS, sprintf( "Can't get the gallery ID from the shortcode: %s", $shortcode_html ) );
							continue;
						}

						// Get gallery images.
						$gallery_images = $wpdb->get_results(
							$wpdb->prepare(
								"select image_url, alt, description, filetype from {$wpdb->prefix}bwg_image
                                    where gallery_id = %d",
								$gallery_id_match['gallery_id']
							),
							ARRAY_A
						);

						// Generate image filenames.
						$images = array_map(
							function( $image ) use ( &$missing_gallery_pictures ) {
								$image_file_name = "https://ithacavoice.s3.amazonaws.com/ithacavoice/wp-content/uploads/photo-gallery{$image['image_url']}";

								$image_request = wp_remote_head( $image_file_name );
								if ( is_wp_error( $image_request ) || 200 !== $image_request['response']['code'] ) {
									$missing_gallery_pictures[] = $image['image_url'];
									return null;
								}

								return array(
									'filename'    => $image_file_name,
									'name'        => $image['alt'],
									'filetype'    => $image['filetype'],
									'description' => $image['description'],
								);
							},
							$gallery_images
						);

						if ( ! empty( $missing_gallery_pictures ) ) {
							$this->log( self::GALLERIES_MIGRATION_LOGS, sprintf( 'The gallery %d is missing %d image(s).', $gallery_id_match['gallery_id'], count( $missing_gallery_pictures ) ) );
						}

						// Filter not found images.
						$images = array_filter( $images );

						if ( empty( $images ) ) {
							continue;
						}

						// Generate Jetpack slide gallery block.
						$gallery_block = $this->posts_migrator_logic->generate_jetpack_slideshow_block_from_pictures( $images );
						// Remove HTML Block tags if exists.
						$post_content_updated = str_replace( "<!-- wp:html -->\n$shortcode_match\n<!-- /wp:html -->", $gallery_block, $post_content_updated );
						$post_content_updated = str_replace( "<!-- wp:paragraph -->\n<p>$shortcode_match</p>\n<!-- /wp:paragraph -->", $gallery_block, $post_content_updated );
						$post_content_updated = str_replace( $shortcode_match, $gallery_block, $post_content_updated );
					}

					if ( $post->post_content !== $post_content_updated ) {
						$updated = $wpdb->update( $wpdb->posts, array( 'post_content' => $post_content_updated ), array( 'ID' => $post->ID ) );
						if ( 1 !== $updated || false === $updated ) {
							return new \WP_Error( 103, sprintf( 'ERR could not update post ID %d', $post->ID ) );
						}

						if ( ( $updated > 0 ) && ( false !== $updated ) ) {
							$this->log( self::GALLERIES_MIGRATION_LOGS, sprintf( 'Gallery %d was migrated in the post %d', $gallery_id_match['gallery_id'], $post->ID ) );
						} else {
							$this->log( self::GALLERIES_MIGRATION_LOGS, sprintf( '! Gallery %d was not migrated in the post %d', $gallery_id_match['gallery_id'], $post->ID ) );
						}
					} else {
						$this->log( self::GALLERIES_MIGRATION_LOGS, sprintf( '! Gallery %d was not migrated in the post %d (content not updated).', $gallery_id_match['gallery_id'], $post->ID ) );
					}
				}
			}
		);

		wp_cache_flush();
	}

	/**
	 * Checks if DB tables exist locally.
	 *
	 * @param array $tables Tables to check.
	 *
	 * @return bool
	 */
	private function validate_db_tables_exist( $tables ) {
		global $wpdb;

		foreach ( $tables as $table ) {
			$prefixed_table = $wpdb->prefix . $table;
			$row            = $wpdb->get_row( $wpdb->prepare( 'select * from information_schema.tables where table_schema = %s AND table_name = %s limit 1;', DB_NAME, $prefixed_table ), ARRAY_A );
			if ( is_null( $row ) || empty( $row ) ) {
				return false;
			}
		}

		return true;
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
