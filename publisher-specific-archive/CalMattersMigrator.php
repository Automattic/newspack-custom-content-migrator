<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for CalMatters.
 */
class CalMattersMigrator implements InterfaceCommand {
	// Logs.
	const IMAGES_LOGS = 'CALMATTERS_images.log';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator calmatters-missing-images-fixer',
			array( $this, 'calmatters_missing_images_fixer' ),
			array(
				'shortdesc' => 'Import missing featured images from local.',
				'synopsis'  => array(),
			)
		);
	}

	/**
	 * Callable for `newspack-content-migrator calmatters-missing-images-fixer`.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function calmatters_missing_images_fixer( $args, $assoc_args ) {
		$posts = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'post',
				'post_status' => array( 'publish' ),
			)
		);

		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			WP_CLI::line( sprintf( '%d/%d', $index, $total_posts ) );

			$attachment_id       = get_post_thumbnail_id( $post->ID );
			$featured_image      = wp_get_attachment_image_src( $attachment_id );
			$live_featured_image = str_replace( get_site_url( null, '', 'https' ), 'https://calmatters.org', $featured_image[0] );
			$image_request       = wp_remote_head( $live_featured_image );

			if ( 0 === $attachment_id ) {
				WP_CLI::warning( sprintf( 'Post without featured image: %d', $post->ID ) );
				continue;
			}

			if ( is_wp_error( $image_request ) ) {
				WP_CLI::warning(
					sprintf(
						'Local image ID %d (%s) returned an error: %s',
						$attachment_id,
						$live_featured_image,
						$image_request->get_error_message()
					)
				);
				continue;
			}

			if ( 200 !== $image_request['response']['code'] ) {
				$file_path  = get_attached_file( $attachment_id );
				$filename   = basename( $file_path );
				$time       = rtrim( str_replace( array( WP_CONTENT_DIR . '/uploads/', $filename ), '', $file_path ), '/' );
				$local_file = WP_CONTENT_DIR . "/missing_images/$time/$filename";

				if ( file_exists( $local_file ) ) {
					$this->log( self::IMAGES_LOGS, sprintf( 'Importing image #%d (%s) file from the archive for the post #%d.', $attachment_id, $filename, $post->ID ) );

					$file = array(
						'name'     => $filename,
						'tmp_name' => $local_file,
						'error'    => 0,
						'size'     => filesize( $file_path ),
					);

					$overrides = array(
						'test_form'   => false,
						'test_size'   => true,
						'test_upload' => true,
					);

					$results = wp_handle_sideload( $file, $overrides, $time );
					if ( ! empty( $results['error'] ) ) {
						$this->log( self::IMAGES_LOGS, 'Failed: ' . $results['error'] );
					} else {
						$this->log( self::IMAGES_LOGS, sprintf( 'Downloaded image for %d to %s', $attachment_id, $results['file'] ) );
					}
				} else {
					$this->log( self::IMAGES_LOGS, sprintf( '! Missing image #%d (%s) file from the archive for the post #%d.', $attachment_id, $filename, $post->ID ) );
				}
			}
		}
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
