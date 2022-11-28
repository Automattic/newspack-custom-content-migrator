<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

class FixMissingMedia implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
		WP_CLI::add_command( 'newspack-content-migrator fix-missing-media', array( $this, 'cmd_fix_missing_media' ), [
			'shortdesc' => 'Seeks to fix the missing media on a Newspack staging site.',
			'synopsis'  => [
				[
					'type'        => 'positional',
					'name'        => 'from_url',
					'description' => 'Provide the full domain URL where images should be downloaded from. E.g. https://newspack.blog',
					'optional'    => false,

				],
				[
					'type'        => 'positional',
					'name'        => 'attachment',
					'description' => 'Provide a specific attachment ID to check a single attachment.',
					'optional'    => true,

				],
				[
					'type'        => 'assoc',
					'name'        => 'limit',
					'description' => 'How many media items to check in each batch. Default: 100',
					'optional'    => true,
					'repeating'   => false,
					'default'     => 100,
				],
				[
					'type'        => 'assoc',
					'name'        => 'batches',
					'description' => 'How many batches to run. Default: 1',
					'optional'    => true,
					'repeating'   => false,
					'default'     => 1,
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
	public function cmd_fix_missing_media( $args, $assoc_args ) {
		WP_CLI::line( 'Fixing media...' );

		$limit   = $assoc_args['limit'] ?? 100;
		$batches = $assoc_args['batches'] ?? 1;

		if ( filter_var( $args[0], FILTER_VALIDATE_URL ) ) {
			$from_url = $args[0];
		} else {
			WP_CLI::error( __( 'Invalid URL.', 'fix-missing-media' ) );
		}

		$limit = min( $limit, 100 );

		if ( isset( $args[1] ) && intval( $args[1] ) ) {

			WP_CLI::line( sprintf( 'Checking for specific attachment %d...', $args[1] ) );

			$image_url = $this->get_missing_media( $from_url, $args[1] );

		} else {

			WP_CLI::line( sprintf( 'Checking %d batches of %d items', $batches, $limit ) );

			for ( $i = 1; $i <= $batches; $i++ ) {

				WP_CLI::line( sprintf( 'Checking batch %d', $i ) );

				$posts = get_posts( [
					'post_type'      => 'attachment',
					'posts_per_page' => $limit,
					'post_status'    => 'any',
					'meta_query'     => [ [
						'key'     => 'fmm_processed',
						'compare' => 'NOT EXISTS',
					] ],
				] );

				if ( ! empty( $posts ) ) :

					foreach ( $posts as $post ) {

						$this->get_missing_media( $from_url, $post->ID );

					}

				else:

					WP_CLI::success( 'All the missing attachments have been found!' );
					break; // No need to keep querying then.

				endif;

				// Flush the cache because it seems the query is getting cached
				// resulting already processed items being checked again.
				wp_cache_flush();

			}

		}
	}

	private function get_missing_media( $from_url, $attachment_id ) {

		// Grab the image URL.
		/**
		 * Filter the image attachment URL.
		 *
		 * Some publishers have weird formats on their current site so we need to
		 * massage the URLs a bit before we can check if they already exist locally.
		 *
		 * @var string $image_url URL to the attachment image.
		 */
		$image_url = apply_filters( 'fmm_wp_get_attachment_url', wp_get_attachment_url( $attachment_id ) );

		// Check if the image is actually present.
		$image_request = wp_remote_head( $image_url );
		if ( is_wp_error( $image_request ) ) {
				WP_CLI::warning( sprintf(
					'Local image ID %d (%s) returned an error: %s',
					$attachment_id,
					$image_url,
					$image_request->get_error_message()
				) );
				return;
		}

		if ( 200 == $image_request['response']['code'] ) {

			WP_CLI::line( sprintf( 'Attachment %d is working fine at %s', $attachment_id, esc_url( $image_url ) ) );

			// Mark it as checked/sorted.
			add_post_meta( $attachment_id, 'fmm_processed', 1 );

		// Image isn't present, so let's grab it from the production domain.
		} else {

			// Scheme and host of the staging site that we want to replace.
			$staging_domain = wp_parse_url( $image_url, PHP_URL_SCHEME ) . '://' . wp_parse_url( $image_url, PHP_URL_HOST );

			// Ignore `.bmp` files, which we don't allow on wpcom.
			$pathinfo = pathinfo( basename( $image_url ) );
			if ( in_array( $pathinfo['extension'], [ 'bmp', 'psd' ] ) ) {
				WP_CLI::warning( sprintf( 'Skipping disallowed filetype, %s.', $pathinfo['extension'] ) );
				add_post_meta( $attachment_id, 'fmm_processed', 1 );
				return;
			}

			// Replace the staging domain with the production domain.
			$prod_image_url = str_replace(
				$staging_domain,
				$from_url,
				$image_url
			);

			/**
			 * Filters the production URL of the image, before retrieving.
			 *
			 * Some publishers have weird formats on their current site so we need to
			 * massage the URLs a bit before we can try and retrieve the image.
			 *
			 * @var string $prod_image_url URL to the production image.
			 */
			$prod_image_url = apply_filters( 'fmm_prod_image_url', $prod_image_url );

			WP_CLI::line( sprintf( 'Attachment %d needs grabbing from %s', $attachment_id, esc_url( $prod_image_url ) ) );

			$temp_file = download_url( $prod_image_url, 5 );
			if ( ! is_wp_error( $temp_file ) ) {

				$file = [
					'name'     => basename( $prod_image_url ),
					'tmp_name' => $temp_file,
					'error'    => 0,
					'size'     => filesize( $temp_file ),
				];

				$overrides = [
					'test_form'   => false,
					'test_size'   => true,
					'test_upload' => true,
					'unique_filename_callback' => [ $this, 'unique_filename_callback' ],
				];

				global $fmm_prod_image_url;
				$fmm_prod_image_url = $prod_image_url;

				add_filter( 'upload_dir', [ $this, 'upload_dir' ] );

				$results = wp_handle_sideload( $file, $overrides );

				remove_filter( 'upload_dir', [ $this, 'upload_dir' ] );

				if ( ! empty( $results['error'] ) ) {
					WP_CLI::warning( 'Failed: '. $results['error'] );
				} else {
					WP_CLI::success( sprintf( 'Downloaded image for %d to %s', $attachment_id, $results['file'] ) );
				}

				// Mark it as checked/sorted.
				add_post_meta( $attachment_id, 'fmm_processed', 1 );

			} else {
				$msg = sprintf( 'Failed to download %s. The error message was: %s', esc_url_raw( $prod_image_url ), esc_html( $temp_file->get_error_message() ) );
				WP_CLI::warning( $msg );
				add_post_meta( $attachment_id, 'fmm_processed', $msg );
			}

		}

	}

	public function upload_dir( $dir ) {

		global $fmm_prod_image_url;

		// Get the directories from within the uploads path.
		$parsed = wp_parse_url( $fmm_prod_image_url );

		$path = str_replace(
			'/wp-content',
			'',
			str_replace( '/' . basename( $fmm_prod_image_url ), '', $parsed['path'] )
		);

		// Replace the current year/month dir with the one needed for the missing file.
		$new_dir = [
			'path'   => str_replace( $dir['subdir'], $path, $dir['path'] ),
			'url'    => str_replace( $dir['subdir'], $path, $dir['url'] ),
			'subdir' => str_replace( $dir['subdir'], $path, $dir['subdir'] ),
		];

		$new_dir = [
				'path'   => str_replace( 'uploads/uploads', 'uploads', $new_dir['path'] ),
				'url'    => str_replace( 'uploads/uploads', 'uploads', $new_dir['url'] ),
				'subdir' => str_replace( 'uploads/uploads', 'uploads', $new_dir['subdir'] ),
		];

		$dir = array_merge( $dir, $new_dir );

		return $dir;

	}

	/**
	 * Stops WordPress doing the incremental integer thing on filenames.
	 *
	 * Passed as a callable to wp_handle_sideload() when appropriate.
	 *
	 * @param  string $dir  Absolute directory the file is being stored in.
	 * @param  string $name Full filename, with extension.
	 * @param  string $ext  Extension, with preceeding dot.
	 * @return string       Filename to use to save the file.
	 */
	public function unique_filename_callback( $dir, $name, $ext ) {
		return $name;
	}

}
