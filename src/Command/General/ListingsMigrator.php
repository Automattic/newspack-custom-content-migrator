<?php

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use \WP_CLI;

class ListingsMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	/**
	 * @var string Listings entries.
	 */
	const LISTINGS_EXPORT_FILE = 'newspack-listings.xml';

	/**
	 * Custom post types created by Listings.
	 */
	const LISTINGS_CUSTOM_POST_TYPES = [
		'newspack_lst_event',
		'newspack_lst_mktplce',
		'newspack_lst_generic',
		'newspack_lst_place',
	];

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command( 'newspack-content-migrator export-listings',
			self::get_command_closure( 'cmd_export_listings' ),
			[
				'shortdesc' => 'Exports Listings.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Output directory full path (no ending slash).',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command( 'newspack-content-migrator import-listings',
			self::get_command_closure( 'cmd_import_listings' ),
			[
				'shortdesc' => 'Imports Listings.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'input-dir',
						'description' => 'Input directory full path (no ending slash).',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for `export-listings` command. Exits with code 0 on success or 1 otherwise.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_listings( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting Listings post types...' ) );

		$result = $this->export_listings( $output_dir, self::LISTINGS_EXPORT_FILE );
		if ( true === $result ) {
			WP_CLI::success( 'Done.' );
			exit(0);
		} else {
			WP_CLI::warning( 'Done with warnings.' );
			exit(1);
		}
	}

	/**
	 * Exports Listings.
	 *
	 * @param string $output_dir
	 * @param string $file_output_listings
	 *
	 * @return bool Success.
	 */
	public function export_listings( $output_dir, $file_output_listings ) {
		wp_cache_flush();

		$posts = get_posts( [
			'posts_per_page' => -1,
			'post_type'      => self::LISTINGS_CUSTOM_POST_TYPES,
			'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ],
		] );
		if ( empty( $posts ) ) {
			WP_CLI::warning( sprintf( 'No Listings found.' ) );
			return false;
		}

		$post_ids = [];
		foreach ( $posts as $post ) {
			$post_ids[] = $post->ID;
		}

		return PostsMigrator::get_instance()->migrator_export_posts( $post_ids, $output_dir, $file_output_listings );
	}

	/**
	 * Callable for import-listings command.
	 *
	 * @param string $args
	 * @param string $assoc_args
	 */
	public function cmd_import_listings( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$import_file = $input_dir . '/' . self::LISTINGS_EXPORT_FILE;
		if ( ! is_file( $import_file ) ) {
			WP_CLI::warning( sprintf( 'Listings file not found %s.', $import_file ) );
			exit(1);
		}

		WP_CLI::line( 'Importing Listings from ' . $import_file . ' ...' );

		$this->delete_all_existing_listings();
		PostsMigrator::get_instance()->import_posts( $import_file );

		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Deletes all existing Listings post types.
	 */
	private function delete_all_existing_listings() {
		wp_cache_flush();

		$query = new \WP_Query( [
			'posts_per_page' => -1,
			'post_type'      => self::LISTINGS_CUSTOM_POST_TYPES,
			'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ],
		] );
		if ( ! $query->have_posts() ) {
			return false;
		}

		foreach ( $query->get_posts() as $post ) {
			wp_delete_post( $post->ID );
		}

		wp_cache_flush();
	}
}
