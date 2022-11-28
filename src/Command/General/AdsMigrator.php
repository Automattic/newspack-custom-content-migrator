<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Command\General\PostsMigrator;
use \NewspackCustomContentMigrator\Logic\Ads;
use \WP_CLI;

class AdsMigrator implements InterfaceCommand {

	/**
	 * @var string Ad Units.
	 */
	const AD_UNITS_EXPORT_FILE = 'newspack-ad-units.xml';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Ads
	 */
	private $ads_logic = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->ads_logic = new Ads();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator export-ads', array( $this, 'cmd_export_ads' ), [
			'shortdesc' => 'Exports Newspack Ads configuration.',
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

		WP_CLI::add_command( 'newspack-content-migrator import-ads', array( $this, 'cmd_import_ads' ), [
			'shortdesc' => 'Imports Newspack Ads.',
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
	 * Callable for export-ads command. Exits with code 0 on success or 1 otherwise.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_ads( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting Newspack Ads...' ) );

		$result = $this->export_ads( $output_dir, self::AD_UNITS_EXPORT_FILE );
		if ( true === $result ) {
			WP_CLI::success( 'Done.' );
			exit(0);
		} else {
			WP_CLI::warning( 'Done with warnings.' );
			exit(1);
		}
	}

	/**
	 * Exports Newspack Ads config.
	 *
	 * @param $output_dir
	 * @param $file_output_ads
	 *
	 * @return bool Success.
	 */
	public function export_ads( $output_dir, $file_output_ads ) {
		wp_cache_flush();

		$posts = $this->ads_logic->get_all_ad_units();
		if ( empty( $posts ) ) {
			WP_CLI::warning( sprintf( 'No Ad Units found.' ) );
			return false;
		}

		$post_ids = [];
		foreach ( $posts as $post ) {
			$post_ids[] = $post->ID;
		}

		return PostsMigrator::get_instance()->migrator_export_posts( $post_ids, $output_dir, $file_output_ads );
	}

	/**
	 * Callable for import-ads command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_ads( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$import_file = $input_dir . '/' . self::AD_UNITS_EXPORT_FILE;
		if ( ! is_file( $import_file ) ) {
			WP_CLI::warning( sprintf( 'Ads file not found %s.', $import_file ) );
			exit(1);
		}

		WP_CLI::line( 'Importing Newspack Ads from ' . $import_file . ' ...' );

		$this->import_ads( $import_file );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Imports Newspack Ads.
	 *
	 * @param string $import_file XML file to import.
	 */
	private function import_ads( $import_file ) {
		wp_cache_flush();

		$this->delete_all_existing_ad_units();

		register_post_type( $this->ads_logic::ADS_POST_TYPE );

		PostsMigrator::get_instance()->import_posts( $import_file );
	}

	/**
	 * Deletes all existing Ad Units.
	 */
	private function delete_all_existing_ad_units() {
		wp_cache_flush();

		$posts = $this->ads_logic->get_all_ad_units();
		if ( empty( $posts ) ) {
			return;
		}

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID );
		}
	}
}
