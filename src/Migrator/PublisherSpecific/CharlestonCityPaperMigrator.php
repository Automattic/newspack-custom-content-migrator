<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Charleston City Paper.
 */
class CharlestonCityPaperMigrator implements InterfaceMigrator {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-uploadcare-checkfilesinfolders',
			[ $this, 'cmd_uploadcare_checkfilesinfolders' ],
			[
			'shortdesc' => 'A helper command which checks Upload Care contents.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'uploadcare-path',
						'description' => "Full path to location of all Upload Care folders and files.",
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-uploadcare-fix',
			[ $this, 'cmd_uploadcare_fix' ],
			[
			'shortdesc' => 'Fixes uploadcare images to local path.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'uploadcare-path',
						'description' => 'Full path to location of all Upload Care folders and files.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator charlestoncitypaper-uploads-subfolders-fix',
			[ $this, 'cmd_uploads_subfolders_fix' ],
			[
			'shortdesc' => 'Fixes upload subfolders by moving files out of these one level below.',
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-uploads-subfolders-fix`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_uploads_subfolders_fix( $args, $assoc_args ) {
		$time_start = microtime( true );

		$uploads_dir = wp_get_upload_dir()[ 'basedir' ] ?? null;
		if ( is_null( $uploads_dir ) ) {
			WP_CLI::error( 'Could not get upload dir.' );
		}

		$uploads_subdirs = glob( $uploads_dir . '/*', GLOB_ONLYDIR );
		foreach ( $uploads_subdirs as $key_uploads_subdirs => $uploads_subdir ) {
			// Work only with valid `yyyy` subdirs.
			$yyyy_name = pathinfo( $uploads_subdir )['basename'] ?? null;
			if ( is_null( $yyyy_name ) ) {
				continue;
			}
			if ( ! is_numeric( $yyyy_name ) ) {
				continue;
			}
			$yyyy_numeric = (int) $yyyy_name;
			if ( $yyyy_numeric < 2000 || $yyyy_numeric > 2022 ) {
				continue;
			}

			WP_CLI::line( sprintf( '(%d/%d) yyyy %s', $key_uploads_subdirs + 1, count( $uploads_subdirs ), $yyyy_name ) );

			// Work through `mm` folders.
			$mms = glob( $uploads_dir . '/' . $yyyy_name . '/*', GLOB_ONLYDIR );
			foreach ( $mms as $key_mms => $mm_dir ) {
				$mm_name = pathinfo( $mm_dir )[ 'basename' ] ?? null;
				if ( is_null( $mm_name ) ) {
					continue;
				}
				if ( ! is_numeric( $mm_name ) ) {
					continue;
				}
				$mm_numeric = (int) $mm_name;
				if ( $mm_numeric < 0 || $mm_numeric > 12 ) {
					continue;
				}

				WP_CLI::line( sprintf( '  [%d/%d] mm %s', $key_mms + 1, count( $mms ), $mm_name ) );

				// Work through the subfolders.
				$mm_full_path = $uploads_dir . '/' . $yyyy_name . '/' . $mm_name;
				$mm_subdirs = glob( $mm_full_path . '/*', GLOB_ONLYDIR );
				$progress = \WP_CLI\Utils\make_progress_bar( 'Moving...', count( $mm_subdirs ) );
				foreach ( $mm_subdirs as $mm_subdir ) {
					$progress->tick();

					$subdir_files = array_diff( scandir( $mm_subdir ), [ '.', '..' ] );
					if ( 0 == count( $subdir_files ) ) {
						continue;
					}

					$msg = '{SUBDIRPATH}' . $mm_subdir;
					$this->log( 'ccp_subfoldersMove', $msg );
					foreach ( $subdir_files as $subdir_file ) {
						$this->log( 'ccp_subfoldersMove', $subdir_file );
						$old_file = $mm_subdir . '/' . $subdir_file;
						$new_file = $mm_full_path . '/' . $subdir_file;
						$renamed = rename( $old_file, $new_file );
						if ( false === $renamed ) {
							$this->log( 'ccp_subfoldersMoveError', $old_file . ' ' . $new_file );
						}
					}
				}
				$progress->finish();
			}
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-uploadcare-fix`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_uploadcare_fix( $args, $assoc_args ) {
		$uploadcare_path = $assoc_args[ 'uploadcare-path' ] ?? null;
		if ( ! file_exists( $uploadcare_path ) ) {
			WP_CLI::error( sprintf( 'Location %s not found.', $uploadcare_path ) );
		}

		$time_start = microtime( true );

		// Clear option value upload_url_path.
		$res_cleared_upload_url_path = update_option( 'upload_url_path', '' );

		$images = get_posts( [
			'post_type' => 'attachment',
			'post_mime_type' => 'image',
			'numberposts' => -1,
		] );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Images', count( $images ) );

		foreach ( $images as $image ) {
			$progress->tick();

			// Skip non-uploadcare images.
			if ( false === strpos( $image->guid, '//ucarecdn.com/' ) ) {
				WP_CLI::line( sprintf( 'Skipping %d ', $image->ID ) );
				continue;
			}

			$relative_path = str_replace( 'https://ucarecdn.com/', '', $image->guid );
			if ( empty( $relative_path ) ) {
				$this->log( 'ccp_ucImagePathWrong', $image->ID . ' ' . $image->guid );
				WP_CLI::warning( sprintf( 'UC image wrong path %d %s', $image->ID, $image->guid ) );
				continue;
			}
			$url_old = wp_get_attachment_url( $image->ID );
			$path = $uploadcare_path . '/' . $relative_path;

			$res_updated = update_attached_file( $image->ID, $path );

			$url_new = wp_get_attachment_url( $image->ID );
			$this->log( 'ccp_ucImageUpdated', sprintf( '%d %s %s', $image->ID, $url_old, $url_new ) );
			WP_CLI::line( sprintf( 'Updated %d ', $image->ID ) );

			if ( false === $res_updated ) {
				$this->log( 'ccp_ucImageUpdateError', $image->ID );
				WP_CLI::warning( sprintf( 'Update error %d ', $image->ID ) );
			}
		}
		$progress->finish();

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}
	/**
	 * Callable for `newspack-content-migrator charlestoncitypaper-uploadcare-checkfilesinfolders`.
	 */
	public function cmd_uploadcare_checkfilesinfolders( $args, $assoc_args ) {
		$uploadcare_path = $assoc_args[ 'uploadcare-path' ] ?? null;
		if ( ! file_exists( $uploadcare_path ) ) {
			WP_CLI::error( sprintf( 'Location %s not found.', $uploadcare_path ) );
		}

		$time_start = microtime( true );

		$uploadcare_subdirs = glob( $uploadcare_path . '/*', GLOB_ONLYDIR );

		$one_file_per_folder = true;
		$subfolders_not_having_1_file = [];

		foreach ( $uploadcare_subdirs as $uploadcare_subdir ) {
			$files = array_diff( scandir( $uploadcare_subdir ), [ '.', '..' ] );
			if ( count( $files ) > 1 || count( $files ) < 1 ) {
				$one_file_per_folder = false;
				$subfolders_not_having_1_file[] = $uploadcare_subdir;
			}
		}

		if ( false === $one_file_per_folder ) {
			WP_CLI::error( 'Some folders do not have exactly one file in them.' );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		file_put_contents( $file, $message . "\n", FILE_APPEND );
	}
}
