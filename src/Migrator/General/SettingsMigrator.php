<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\Migrator\General\PostsMigrator;
use \WP_CLI;

class SettingsMigrator implements InterfaceMigrator {

	/**
	 * @var string Page settings exported data filename.
	 */
	const PAGES_SETTINGS_FILENAME = 'newspack-settings-pages.json';

	/**
	 * @var string Site identity posts export filename.
	 */
	const SITE_IDENTITY_POSTS_FILENAME = 'newspack-site-identity-posts.xml';

	/**
	 * @var string Exported options for site identity.
	 */
	const SITE_IDENTITY_EXPORTED_OPTIONS_FILENAME = 'newspack-site-identity-exported-options.json';

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

		WP_CLI::add_command( 'newspack-content-migrator export-customize-site-identity-settings', array( $this, 'cmd_export_customize_site_identity_settings' ), [
			'shortdesc' => 'Exports Customizer site identity settings.',
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

		WP_CLI::add_command( 'newspack-content-migrator import-customize-site-identity-settings', array( $this, 'cmd_import_customize_site_identity_settings' ), [
			'shortdesc' => 'Imports Customizer site identity settings from the Staging site.',
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

		WP_CLI::add_command( 'newspack-content-migrator update-seo-settings', array( $this, 'cmd_update_seo_settings' ), [
			'shortdesc' => 'Checks and sets SEO settings.',
		] );

	}

	/**
	 * Callable for export-customize-site-identity-settings command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_customize_site_identity_settings( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting site identity settings...' ) );

		$result = $this->export_current_theme_site_identity_posts( $output_dir );
		if ( true === $result ) {
			exit(0);
		} else {
			exit(1);
		}

		WP_CLI::success( 'Done.' );
	}

	private function export_current_theme_site_identity_posts( $output_dir ) {
		wp_cache_flush();

		// Get theme mods with IDs.
		$export_mods = array();

		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( false !== $custom_logo_id ) {
			$export_mods[ 'custom_logo' ] = $custom_logo_id;
		}
		$newspack_footer_logo_id = get_theme_mod( 'newspack_footer_logo' );
		if ( false !== $newspack_footer_logo_id ) {
			$export_mods[ 'newspack_footer_logo' ] = $newspack_footer_logo_id;
		}

		$ids = array_values( $export_mods );
		if ( empty( $ids ) ) {
			return false;
		}

		// Write JSON file for reference to what was exported.
		$written = file_put_contents( $output_dir . '/' . self::SITE_IDENTITY_EXPORTED_OPTIONS_FILENAME, json_encode( $export_mods ) );
		if ( false === $written ) {
			return false;
		}

		// Export the attachment post types.
		return PostsMigrator::get_instance()->migrator_export_posts( $ids, $output_dir, self::SITE_IDENTITY_POSTS_FILENAME );
	}

	/**
	 * Callable for import-customize-site-identity-settings.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_customize_site_identity_settings( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$posts_import_file   = $input_dir . '/' . self::SITE_IDENTITY_POSTS_FILENAME;
		$options_import_file = $input_dir . '/' . self::SITE_IDENTITY_EXPORTED_OPTIONS_FILENAME;
		if ( ! is_file( $posts_import_file ) ) {
			WP_CLI::error( sprintf( 'Can not find %s.', $posts_import_file ) );
		}
		if ( ! is_file( $options_import_file ) ) {
			WP_CLI::error( sprintf( 'Can not find %s.', $options_import_file ) );
		}

		WP_CLI::line( 'Importing site identity settings...' );

		PostsMigrator::get_instance()->import_posts( $posts_import_file );
		$imported_mods = json_decode( file_get_contents( $options_import_file ), true );
		$this->update_theme_mod_site_identity_post_ids( $imported_mods );

		WP_CLI::success( 'Done.' );
	}

	private function update_theme_mod_site_identity_post_ids( $imported_mods ) {
		wp_cache_flush();

		// Update values/IDs for these mod names only.
		$mods_with_post_id = array(
			'custom_logo',
			'newspack_footer_logo',
		);

		$posts_migrator = PostsMigrator::get_instance();
		foreach ( $imported_mods as $mod_name => $original_post_id ) {
			if ( ! in_array( $mod_name, $mods_with_post_id ) ) {
				continue;
			}

			$new_post_id = $posts_migrator->get_current_post_id_from_original_post_id( $original_post_id );
			if ( $new_post_id ) {
				set_theme_mod( $mod_name, $new_post_id );
			}
		}

		wp_cache_flush();
	}

	public function cmd_export_pages_settings( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( 'Exporting default pages settings...' );

		$file = $output_dir . '/' . self::PAGES_SETTINGS_FILENAME;
		$data = array(
			// This is the radio button setting on Customize > Homepage settings > "Your homepage displays".
			'show_on_front' => get_option( 'show_on_front' ),
			// Homepage post ID.
			'page_on_front' => get_option( 'page_on_front' ),
			// Posts page ID.
			'page_for_posts' => get_option( 'page_for_posts' ),
			// Donation page ID.
			'newspack_donation_page_id' => get_option( 'newspack_donation_page_id' ),
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

		// Copy over these as they are.
		$option_names = array( 'show_on_front' );
		foreach ( $option_names as $option_name ) {
			update_option( $option_name, $options[ $option_name ] );
		}

		// Update IDs for these Pages saved as option values, by referring to the PostsMigrator::META_KEY_ORIGINAL_ID meta.
		$option_names = array( 'page_on_front', 'page_for_posts', 'newspack_donation_page_id' );
		foreach ( $option_names as $option_name ) {
			$original_id = isset( $options[ $option_name ] ) && ! empty( $options[ $option_name ] ) ? $options[ $option_name ] : null;
			if ( null !== $original_id && 0 != $original_id) {
				$current_id = $posts_migrator->get_current_post_id_from_original_post_id( $original_id );
				update_option( $option_name, $current_id );
			}
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for update-seo-settings.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_update_seo_settings( $args, $assoc_args ) {
		WP_CLI::success( 'Disabling Yoast XML sitemaps...' );
		$this->turn_off_yoast_xml_sitemap();

		WP_CLI::success( 'Unchecking the `Discourage search engines from indexing this site` option...' );
		$this->uncheck_discourage_search_engines();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Disables Yoast's XML sitemap.
	 */
	private function turn_off_yoast_xml_sitemap() {
		$option_name = 'wpseo';
		$option_param = 'enable_xml_sitemap';

		$option = get_option( $option_name );
		if ( ! $option || ! isset( $option[ $option_param ] ) ) {
			return;
		}

		$option[ $option_param ] = false;

		update_option( $option_name, $option );
	}

	/**
	 * Updates the `blog_public` option to true, which in turn unchecks the `Discourage search engines from indexing this site`
	 * WP Settings > Reading option.
	 */
	private function uncheck_discourage_search_engines() {
		update_option( 'blog_public', 1 );
	}
}
