<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator;
use \NewspackPostImageDownloader\Downloader;
use \WP_CLI;

/**
 * Custom migration scripts for Photo Albums Plugins.
 */
class PhotoAlbumsMigrator implements InterfaceMigrator {
	const ALBUM_MIGRATION_LOG = 'ALBUM_MIGRATION.log';

	/**
	 * @var PostsLogic.
	 */
	private $posts_migrator_logic;

	/**
	 * @var SquareBracketsElementManipulator.
	 */
	private $squarebracketselement_manipulator;

	/**
	 * @var Downloader.
	 */
	private $downloader;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_migrator_logic              = new PostsLogic();
		$this->squarebracketselement_manipulator = new SquareBracketsElementManipulator();
		$this->downloader                        = new Downloader();
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
			'newspack-content-migrator photo-albums-migration',
			array( $this, 'photo_albums_migration' ),
			array(
				'shortdesc' => 'Migrate photo albums from the Photo Album Pro plugin to Jetpack slideshow Block.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator photo-albums-migration`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function photo_albums_migration( $args, $assoc_args ) {
		global $wpdb;

		$wppa_tables     = array( 'wppa_albums', 'wppa_photos' );
		$wppa_source_dir = WP_CONTENT_DIR . '/uploads/wppa/';

		$this->log( self::ALBUM_MIGRATION_LOG, sprintf( 'Checking if WPPA plugin tables exists (%s)', join( ', ', $wppa_tables ) ) );
		$valid_tables = $this->validate_db_tables_exist( $wppa_tables );

		if ( ! $valid_tables ) {
			$this->log( self::ALBUM_MIGRATION_LOG, 'WPPA tables are missing' );
			die();
		}

		$this->log( self::ALBUM_MIGRATION_LOG, 'Starting the migration ...' );

		$this->posts_migrator_logic->throttled_posts_loop(
			array(
				'post_type'   => array( 'page' ),
				'post_status' => array( 'publish' ),
			),
			function( $post ) use ( $wpdb, $wppa_source_dir, &$missing_album_photos ) {
				if ( strpos( strtolower( $post->post_content ), strtolower( '[wppa' ) ) !== false ) {
					$matches_shortcodes = $this->squarebracketselement_manipulator->match_elements_with_closing_tags( 'wppa', $post->post_content );

					if ( empty( $matches_shortcodes[0] ) ) {
						$this->log( self::ALBUM_MIGRATION_LOG, sprintf( "Can't parse the album shortcode in the post %d", $post->ID ) );
						return;
					}

					$post_content_updated = $post->post_content;

					foreach ( $matches_shortcodes[0] as $match_shortcode ) {
						$shortcode_html       = $match_shortcode[0];
						$album_id             = $this->squarebracketselement_manipulator->get_attribute_value( 'album', $shortcode_html );
						$missing_album_photos = array();

						// Get album photos.
						$album_photos = $wpdb->get_results(
							$wpdb->prepare(
								"select id, ext, name, description from {$wpdb->prefix}wppa_photos
                                    where album = %d",
								$album_id
							),
							ARRAY_A
						);

						// Generate photo filenames.
						$photos = array_map(
							function( $photo ) use ( $wppa_source_dir, &$missing_album_photos ) {
								$photo_file_name = $wppa_source_dir . "/{$photo['id']}.{$photo['ext']}";

								if ( ! file_exists( $photo_file_name ) ) {
									$missing_album_photos[] = $photo;
								}

								return array(
									'filename'    => $photo_file_name,
									'name'        => $photo['name'],
									'description' => $photo['description'],
								);
							},
							$album_photos
						);

						if ( ! empty( $missing_album_photos ) ) {
							if ( WP_CLI::confirm( sprintf( 'The album %d is missing %d image(s), would you like to skip it?', $album_id, count( $missing_album_photos ) ) ) ) {
								$this->log( self::ALBUM_MIGRATION_LOG, sprintf( 'Skipping the album %d.', $album_id ) );
								return;
							}
						}

						// Import photos as media files.
						$photo_media_ids = array_map(
							function( $photo ) use ( $post ) {
								return $this->downloader->import_external_file(
									$photo['filename'],
									$photo['name'],
									$photo['description'],
									$post->ID,
									$photo['name']
								);
							},
							$photos
						);

						// Generate Jetpack slide gallery block.
						$gallery_block        = $this->posts_migrator_logic->generate_jetpack_slideshow_block_from_media_posts( $photo_media_ids );
						$post_content_updated = str_replace( $shortcode_html, $gallery_block, $post_content_updated );
					}

					if ( $post->post_content !== $post_content_updated ) {
						$updated = $wpdb->update( $wpdb->posts, array( 'post_content' => $post_content_updated ), array( 'ID' => $post->ID ) );
						if ( 1 !== $updated || false === $updated ) {
							return new \WP_Error( 103, sprintf( 'ERR could not update post ID %d', $post->ID ) );
						}

						if ( ( $updated > 0 ) && ( false !== $updated ) ) {
							$this->log( self::ALBUM_MIGRATION_LOG, sprintf( 'Album %d was migrated in the post %d', $album_id, $post->ID ) );
						} else {
							$this->log( self::ALBUM_MIGRATION_LOG, sprintf( '! Album %d was not migrated in the post %d', $album_id, $post->ID ) );
						}
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
