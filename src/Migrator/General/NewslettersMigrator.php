<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\Migrator\General\PostsMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Newsletters;
use \WP_CLI;

class NewslettersMigrator implements InterfaceMigrator {

	/**
	 * @var string Newsletters.
	 */
	const NEWSLETTERS_EXPORT_FILE = 'newspack-newsletters.xml';

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var Newsletters
	 */
	private $newsletters_logic = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->newsletters_logic = new Newsletters();
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
		WP_CLI::add_command( 'newspack-content-migrator export-newsletters', array( $this, 'cmd_export_newsletters' ), [
			'shortdesc' => 'Exports Newspack Newsletters.',
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

		WP_CLI::add_command( 'newspack-content-migrator import-newsletters', array( $this, 'cmd_import_newsletters' ), [
			'shortdesc' => 'Imports Newspack Newsletters.',
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
	 * Callable for export-newsletters command. Exits with code 0 on success or 1 otherwise.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_newsletters( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting Newsletters...' ) );

		$result = $this->export_newsletters( $output_dir, self::NEWSLETTERS_EXPORT_FILE );
		if ( true === $result ) {
			WP_CLI::success( 'Done.' );
			exit(0);
		} else {
			WP_CLI::warning( 'Done with warnings.' );
			exit(1);
		}
	}

	/**
	 * Exports Newsletters.
	 *
	 * @param $output_dir
	 * @param $file_output_newsletters
	 *
	 * @return bool Success.
	 */
	public function export_newsletters( $output_dir, $file_output_newsletters ) {
		wp_cache_flush();

		$posts = $this->newsletters_logic->get_all_newsletters();
		if ( empty( $posts ) ) {
			WP_CLI::warning( sprintf( 'No Newsletters found.' ) );
			return false;
		}

		$post_ids = [];
		foreach ( $posts as $post ) {
			$post_ids[] = $post->ID;
		}

		return PostsMigrator::get_instance()->migrator_export_posts( $post_ids, $output_dir, $file_output_newsletters );
	}

	/**
	 * Callable for import-newsletters command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_newsletters( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$import_file = $input_dir . '/' . self::NEWSLETTERS_EXPORT_FILE;
		if ( ! is_file( $import_file ) ) {
			WP_CLI::error( sprintf( 'Newsletters file not found %s.', $import_file ) );
		}

		WP_CLI::line( 'Importing Newsletters from ' . $import_file . ' ...' );

		$this->import_newsletterss( $import_file );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Imports Newspack Newsletters.
	 *
	 * @param string $import_file XML file to import.
	 */
	private function import_newsletterss( $import_file ) {
		wp_cache_flush();

		$this->delete_all_existing_newsletters();

		register_post_type( $this->newsletters_logic::NEWSLETTER_POST_TYPE );

		PostsMigrator::get_instance()->import_posts( $import_file );
	}

	/**
	 * Deletes all existing Newsletters.
	 */
	private function delete_all_existing_newsletters() {
		wp_cache_flush();

		$posts = $this->newsletters_logic->get_all_newsletters();
		if ( empty( $posts ) ) {
			return;
		}

		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID );
		}
	}
}
