<?php

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use \NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use \WP_CLI;

class ReaderRevenueMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	/**
	 * @var string Reader Revenue Products.
	 */
	const READER_REVENUE_PRODUCTS_EXPORT_FILE = 'newspack-reader-revenue-products.xml';

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {
		WP_CLI::add_command( 'newspack-content-migrator export-reader-revenue', self::get_command_closure( 'cmd_export_reader_revenue' ), [
			'shortdesc' => 'Exports Reader Revenue `product` post types.',
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

		WP_CLI::add_command( 'newspack-content-migrator import-reader-revenue', self::get_command_closure( 'cmd_import_reader_revenue' ), [
			'shortdesc' => 'Imports Reader Revenue `product` post types.',
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
	 * Callable for export-reader-revenue command. Exits with code 0 on success or 1 otherwise.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_reader_revenue( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		WP_CLI::line( sprintf( 'Exporting Reader Revenue `product` post types...' ) );

		$result = $this->export_reader_revenue( $output_dir, self::READER_REVENUE_PRODUCTS_EXPORT_FILE );
		if ( true === $result ) {
			WP_CLI::success( 'Done.' );
			exit(0);
		} else {
			WP_CLI::warning( 'Done with warnings.' );
			exit(1);
		}
	}

	/**
	 * Exports Reader Revenue `product` post types.
	 *
	 * @param $output_dir
	 * @param $file_output_reader_revenue_products
	 *
	 * @return bool Success.
	 */
	public function export_reader_revenue( $output_dir, $file_output_reader_revenue_products ) {
		wp_cache_flush();

		$posts = get_posts( [
			'posts_per_page' => -1,
			// Target all post_types.
			'post_type'      => [ 'product' ],
		] );
		if ( empty( $posts ) ) {
			WP_CLI::warning( sprintf( 'No Donation Products found.' ) );
			return false;
		}

		$post_ids = [];
		foreach ( $posts as $post ) {
			$post_ids[] = $post->ID;
		}

		return PostsMigrator::get_instance()->migrator_export_posts( $post_ids, $output_dir, $file_output_reader_revenue_products );
	}

	/**
	 * Callable for import-reader-revenue command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_reader_revenue( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$import_file = $input_dir . '/' . self::READER_REVENUE_PRODUCTS_EXPORT_FILE;
		if ( ! is_file( $import_file ) ) {
			WP_CLI::warning( sprintf( 'Reader Revenue file not found %s.', $import_file ) );
			exit(1);
		}

		WP_CLI::line( 'Importing Reader Revenue Products from ' . $import_file . ' ...' );

		$this->delete_all_existing_products();
		PostsMigrator::get_instance()->import_posts( $import_file );

		$product_id_old = get_option( 'newspack_donation_product_id' );
		if ( false === $product_id_old ) {
			WP_CLI::error( 'newspack_donation_product_id `option_name` not found.' );
			return false;
		}
		$product_id_new = PostsMigrator::get_instance()->get_current_post_id_from_original_post_id( $product_id_old );
		update_option( 'newspack_donation_product_id', $product_id_new );

		wp_cache_flush();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Deletes all existing `product` post types.
	 */
	private function delete_all_existing_products() {
		wp_cache_flush();

		$args = array(
			'posts_per_page' => -1,
			'post_type'      => 'product',
		);
		$query = new \WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return false;
		}

		foreach ( $query->get_posts() as $post ) {
			wp_delete_post( $post->ID );
		}

		wp_cache_flush();
	}
}
