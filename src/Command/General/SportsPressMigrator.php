<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Command\General\PostsMigrator;
use \WP_CLI;

class SportsPressMigrator implements InterfaceCommand {

	/**
	 * @var string SportsPress content export file name.
	 */
	const SPORTSPRESS_EXPORT_FILE = 'newspack-sportspress.xml';

	/**
	 * @var array SportsPress' custom post_types.
	 */
	const SPORTSPRESS_POST_TYPES = [
		'sp_calendar',
		'sp_column',
		'sp_event',
		'sp_list',
		'sp_metric',
		'sp_outcome',
		'sp_performance',
		'sp_player',
		'sp_result',
		'sp_staff',
	];

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsMigrator logic.
	 */
	private $posts_logic = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = PostsMigrator::get_instance();
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
		WP_CLI::add_command( 'newspack-content-migrator export-sportspress-content', [ $this, 'cmd_export_sportspress_contents' ], [
			'shortdesc' => 'Exports SporsPress plugin contents.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'output-dir',
					'description' => 'Output directory, no ending slash.',
					'optional'    => false,
					'repeating'   => false,
				]
			],
		] );

		WP_CLI::add_command( 'newspack-content-migrator import-sportspress-content', [ $this, 'cmd_import_sportspress_content' ], [
			'shortdesc' => 'Imports custom SportsPress posts from the XML.',
			'synopsis'  => [
				[
					'type'        => 'assoc',
					'name'        => 'input-dir',
					'description' => 'Full path to the directory where the XML file is located, no ending slash.',
					'optional'    => false,
					'repeating'   => false,
				],
			],
		] );
	}

	/**
	 * Callable for `export-sportspress-content command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_export_sportspress_contents( $args, $assoc_args ) {

		WP_CLI::error( 'The SportsPressMigrator commands presently do not migrate over all the Plugin data; more work is needed on these commands.' );

		$output_dir        = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting SportsPress contents to %s...', $output_dir . '/' . self::SPORTSPRESS_EXPORT_FILE ) );

		$exported = $this->export_sportspress_contents( $output_dir, self::SPORTSPRESS_EXPORT_FILE );
		if ( false === $exported ) {
			// Exit with non-null code.
			exit(1);
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Exports SportPress plugin's contents, and sets a meta with the original IDs when it was exported.
	 *
	 * @param string $output_dir  Output dir.
	 * @param string $output_file Output file.
	 *
	 * @return bool
	 */
	public function export_sportspress_contents( $output_dir, $output_file ) {
		// The \NewspackCustomContentMigrator\Command\General\PostsMigrator::export_posts automatically sets the PostsMigrator::META_KEY_ORIGINAL_ID meta.
		return $this->posts_logic->migrator_export_posts(
			$this->get_all_sportspress_posts_ids(),
			$output_dir,
			$output_file
		);
	}

	/**
	 * Fetches Post IDs of all the SportsPress custom post types.
	 *
	 * @return int[]|\WP_Post[]
	 */
	public function get_all_sportspress_posts_ids() {
		return get_posts( [
			'posts_per_page' => -1,
			'post_type'      => self::SPORTSPRESS_POST_TYPES,
			// `'post_status' => 'any'` doesn't work as expected.
			'post_status'    => [ 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit', 'trash' ],
			'fields'         => 'ids',
		] );
	}

	/**
	 * Callable for `import-sportspress-content` command.
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function cmd_import_sportspress_content( $args, $assoc_args ) {

		WP_CLI::error( 'The SportsPressMigrator commands presently do not migrate over all the Plugin data; more work is needed on these commands.' );

		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! file_exists( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		WP_CLI::line( 'First deleting existing SportsPress contents...' );
		$this->delete_all_sportspress_content();

		WP_CLI::line( 'Importing SportsPress contents from the XML file (uses `wp import` and might take a bit longer) ...' );
		$this->posts_logic->import_posts( $input_dir . '/' . self::SPORTSPRESS_EXPORT_FILE );

		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Deletes all the SportsPress contents.
	 */
	public function delete_all_sportspress_content() {
		WP_CLI::line( 'Deleting all SportsPress content...' );

		$post_ids = $this->get_all_sportspress_posts_ids();
		if ( empty( $post_ids) ) {
			return;
		}

		foreach ( $post_ids as $id ) {
			wp_delete_post( $id );
		}
	}
}
