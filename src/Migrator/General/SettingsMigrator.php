<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class SettingsMigrator implements InterfaceMigrator {

	/**
	 * @var string Export file name.
	 */
	const PAGES_SETTINGS_FILENAME = 'newspack-settings-pages.json';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
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
		WP_CLI::add_command( 'newspack-content-migrator export-pages-settings', array( $this, 'cmd_export_pages_settings' ), [
			'shortdesc' => 'Exports settings for default Site Pages.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'output-dir',
					'description' => 'Output directory full path (no ending slash).',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );

		WP_CLI::add_command( 'newspack-content-migrator import-pages-settings', array( $this, 'cmd_import_pages_settings' ), [
			'shortdesc' => 'Imports custom CSS from the export XML file.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'input-dir',
					'description' => 'Input directory full path (no ending slash).',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for export-pages-settings command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_pages_settings( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( 'Exporting default pages settings...' );

		$file = $output_dir . '/' . self::PAGES_SETTINGS_FILENAME;
		$data = array(
			// Homepage post ID.
			'page_on_front' => get_option( 'page_on_front' ),
			// Posts page ID.
			'page_for_posts' => get_option( 'page_for_posts' ),
		);
		$written = file_put_contents( $file, json_encode( $data ) );
		if ( false === $written ) {
			exit(1);
		}

		WP_CLI::success( 'Done.' );
		exit(0);
	}

	/**
	 * Callable for import-pages-settings command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_pages_settings( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$import_file = $input_dir . '/' . self::PAGES_SETTINGS_FILENAME;
		if ( ! is_file( $import_file ) ) {
			WP_CLI::error( sprintf( 'Can not find %s.', $import_file ) );
		}

		WP_CLI::line( 'Importing default pages settings...' );

		$contents = file_get_contents( $import_file );
		if ( false === $contents ) {
			WP_CLI::error( 'Options contents empty.' );
		}

		$options = json_decode( $contents, true );
		$posts_migrator = PostsMigrator::get_instance();

		$option_names = array( 'page_on_front', 'page_for_posts' );
		foreach ( $option_names as $option_name ) {
			$original_id = isset( $options[ $option_name ] ) && ! empty( $options[ $option_name ] ) ? $options[ $option_name ] : null;
			if ( null !== $original_id && 0 != $original_id) {
				$current_id = $posts_migrator->get_current_post_id_from_original_post_id( $original_id );
				update_option( $option_name, $current_id );
			}
		}

		WP_CLI::success( 'Done.' );
	}
}
