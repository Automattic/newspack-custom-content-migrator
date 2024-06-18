<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use WP_CLI;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments as AttachmentsLogic;
use NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use NewspackCustomContentMigrator\Utils\Logger;

/**
 * Custom migration scripts for East Mojo.
 */
class EastMojoMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachments_logic;

	/**
	 * Logger.
	 */
	private Logger $logger;

	/**
	 * @var PostsLogic
	 */
	private $posts_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger            = new Logger();
		$this->attachments_logic = new AttachmentsLogic();
		$this->posts_logic       = new PostsLogic();
	}

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
			'newspack-content-migrator eastmojo-import-images',
			[ $this, 'cmd_import_images' ],
			[
				'shortdesc' => 'Scans all content for images, and tries to import them from a local folder.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Perform a dry run, making no changes.',
						'optional'    => true,
					],
				],

			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator eastmojo-cleanup-broken-images-in-post-content',
			[ $this, 'cmd_cleanup_broken_images_in_post_content' ],
			[
				'shortdesc' => 'Scans all content for broken images, and tries to remove the whole block around them.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Perform a dry run, making no changes.',
						'optional'    => true,
					],
				],

			]
		);
	}

	/**
	 * Checks whether the Newspack Content Converter plugin is active and loaded.
	 *
	 * @return bool
	 */
	private function is_converter_plugin_active() {
		if ( ! is_plugin_active( 'newspack-content-converter/newspack-content-converter.php' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Callable for the `newspack-content-migrator eastmojo-import-images` command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_images( $args, $assoc_args ) {
		if ( ! $this->is_converter_plugin_active() ) {
			WP_CLI::error( '🤭  The Newspack Content Converter plugin is required for this command to work. Please install and activate it first.' );
		}

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;
		$post_id = isset( $assoc_args['post-id'] ) ? (int) $assoc_args['post-id'] : null;

		global $wpdb;
		$time_start = microtime( true );

		// EM specific variables.
		$img_host                 = 'gumlet.assettype.com';
		$img_path                 = 'eastmojo';
		$public_img_location      = 'wp-content/prod-qt-images';
		$public_img_full_location = '/srv/www/eastmojo/public_html/wp-content/prod-qt-images';
		$path_existing_images     = $this->get_site_public_path() . '/' . $public_img_location;
		if ( ! file_exists( $path_existing_images ) ) {
			WP_CLI::error( sprintf( 'Path with existing S3 hosted images not found: %s', $path_existing_images ) );
		}

		// Get single Post or all Posts.
		if ( $post_id ) {
			$posts = [ get_post( $post_id ) ];
			if ( 0 == count( $posts ) ) {
				WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
			}
		} else {
			// Loop through posts detecting images hosted in the AWS bucket.
			$query_public_posts = new \WP_Query(
				[
					'posts_per_page' => -1,
					'post_type'      => [ 'post', 'page' ],
					'post_status'    => 'publish',
					's'              => sprintf( '://%s/%s', $img_host, $img_path ),
				] 
			);
			if ( ! $query_public_posts->have_posts() ) {
				WP_CLI::line( 'No Posts with images found.' );
				exit;
			}

			$posts = $query_public_posts->get_posts();
		}

		foreach ( $posts as $i => $post ) {

			$matches = $this->match_attribute_with_hostname( 'src', $post->post_content, $img_host );
			if ( ! $matches ) {
				$error_message = sprintf( 'WARNING no img matches in Post ID %d', $post->ID );
				$errors[]      = $error_message;
				WP_CLI::warning( $error_message );
				continue;
			}

			WP_CLI::line( sprintf( '✓ (%d/%d) Post ID %d, importing %d image(s)...', $i + 1, count( $posts ), $post->ID, count( $matches[1] ) ) );
			$errors               = [];
			$post_content_updated = $post->post_content;

			foreach ( $matches[1] as $key => $img_url ) {

				// Get path to the image from the URL, and strip the $img_path from the beginning.
				$img_url_path = $this->get_path_from_url( $img_url );
				$img_url_path = ltrim( $img_url_path, '/' . $img_path . '/' );

				// Get the local file.
				$img_filename   = $this->get_filename_from_path( $img_url );
				$img_local_path = $img_filename
					? $public_img_full_location . '/' . $img_url_path
					: null;
				if ( ! file_exists( $img_local_path ) ) {
					$error_message = sprintf( 'ERROR file does not exist, Post ID %d image %s', $post->ID, $img_local_path );
					$errors[]      = $error_message;
					WP_CLI::warning( $error_message );
					continue;
				}

				// Import the attachment and get the new URL.
				$att_id = ! $dry_run
					? $this->attachments_logic->import_external_file( $img_local_path, null, null, null, null, $post->ID )
					: null;
				if ( is_wp_error( $att_id ) ) {
					$error_message = sprintf( 'ERROR could not save image from Post ID %d with URL %s because: %s', $post->ID, $img_local_path, $att_id->get_error_message() );
					$errors[]      = $error_message;
					WP_CLI::warning( $error_message );
					continue;
				}
				$img_url_new = ! $dry_run
					? wp_get_attachment_url( $att_id )
					: null;

				// Replace the URL with the new one.
				$post_content_updated = str_replace( $img_url, $img_url_new, $post_content_updated );
			}

			// Update the Post content.
			if ( ! $dry_run && $post_content_updated != $post->post_content ) {
				$wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
			}
		}

		// Required for the $wpdb->update() sink in.
		wp_cache_flush();

		if ( count( $errors ) > 0 ) {
			// Repeat error messages.
			WP_CLI::warning(
				sprintf( 'Finished with %d errors:', count( $errors ) )
				. "\n"
				. implode( "\n", $errors )
			);

			WP_CLI::line( '(writing errors to debug.log, too)' );
			error_log( print_r( $errors, true ) );
		}

		WP_CLI::line( sprintf( 'All done!  🙌  Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Scans all content for broken images, and tries to remove the whole block around them.
	 */
	public function cmd_cleanup_broken_images_in_post_content( array $args, array $assoc_args ): void {
		global $wpdb;

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		// Logs.
		$log = sprintf( 'eastmojo-cleanup-broken-images-from-post-content-%s.txt', date( 'Y-m-d H-i-s' ) );

		// CSV
		$csv              = sprintf( 'eastmojo-broken-images-cleanup-%s.csv', date( 'Y-m-d H-i-s' ) );
		$csv_file_pointer = fopen( $csv, 'w' );
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Post ID',
				'Status',
				'Post Title',
				'Slug',
				'Live URL',
				'Staging URL',
			] 
		);

		$posts_ids    = $this->posts_logic->get_all_posts_ids( 'post' );
		$posts_chunks = array_chunk( $posts_ids, 500 );

		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Iterating Posts', count( $posts_ids ), 1 );

		$index = 0;

		foreach ( $posts_chunks as $chunk_index => $posts_chunk ) {
			$this->logger->log( $log, sprintf( 'Start Processing Chunk %d / %d', $chunk_index + 1, count( $posts_chunks ) ), false );

			$posts = get_posts(
				[
					'post_type'              => 'post',
					'post_status'            => 'any',
					'include'                => $posts_chunk,
					'cache_results'          => false,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				] 
			);

			foreach ( $posts as $post ) {
				++$index;
				$status = 'NONE';

				$this->logger->log( $log, sprintf( 'Start Processing Post %d / %d', $index, count( $posts_ids ) ), false );

				// 1. Process Post with broken images.
				if ( stripos( $post->post_content, 'gumlet.assettype.com' ) !== false ) {
					$this->logger->log( $log, 'Found broken image', false );

					$replaced_post_content = preg_replace( '~<figure>.?<img src\="https?:\/\/gumlet\.assettype\.com.+\".+<\/figure>~U', '', $post->post_content );
					$replaced_post_content = preg_replace( '~<img src\="https?:\/\/gumlet\.assettype\.com.+\"\s?\/?>~', '', $replaced_post_content );
				
					file_put_contents( 'migration/' . $post->ID . '-before.txt', $post->post_content );
					file_put_contents( 'migration/' . $post->ID . '-after.txt', $replaced_post_content );

					if ( ! $dry_run ) {
						$updated_result = $wpdb->update(
							$wpdb->prefix . 'posts',
							[
								'post_content' => $replaced_post_content,
							],
							[
								'ID' => $post->ID,
							]
						);

						if ( $updated_result === false ) {
							$status = 'ERROR';

							$this->logger->log( $log, sprintf( 'Couldn\'t update post #%d. Error: %s', $post->ID, $wpdb->last_error ), Logger::ERROR );
						} else {
							$status = 'UPDATED';

							$this->logger->log( $log, sprintf( 'Successfully updated post #%d', $post->ID ), false );
						}
					}
				}

				// 2. Populate CSV.
				if ( $status !== 'NONE' ) {
					$this->logger->log( $log, 'Populating CSV', false );
	
					fputcsv(
						$csv_file_pointer,
						[
							$index, // #
							$post->ID, // ID.
							$status, // Status.
							$post->post_title, // Post Title.
							$post->post_name, // Post Slug.
							str_replace( home_url(), 'https://eastmojo.com', get_permalink( $post->ID ) ), // Live URL.
							get_permalink( $post->ID ), // Staging URL.
						]
					);
				}

				$progress_bar->tick( 1, sprintf( '[Memory: %s] Cleaning up broken images %d / %d', size_format( memory_get_usage( true ) ), $index, count( $posts_ids ) ) );
			}
		}
		
		$progress_bar->finish();

		fclose( $csv_file_pointer );

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );

		if ( $dry_run ) {
			WP_CLI::warning( 'Dry mode, no changes have been made!' );
		}
	}

	/**
	 * Gets filename from a URL or a path.
	 *
	 * @param string $path URL or path.
	 *
	 * @return string|null Filename.
	 */
	private function get_filename_from_path( $path ) {
		$pathinfo = pathinfo( $path );
		return ( isset( $pathinfo['filename'] ) && isset( $pathinfo['extension'] ) )
			? $pathinfo['filename'] . '.' . $pathinfo['extension']
			: null;
	}

	/**
	 * Removes schema and hostname from URL, returns the path with the filename, slashes at the beginning.
	 *
	 * @param string $url
	 *
	 * @return string|null
	 */
	private function get_path_from_url( $url ) {
		$url_parse = wp_parse_url( $url );

		return $url_parse['path'];
	}

	/**
	 * Matches an attribute, e.g. `src="https://hostname/file"` with a specified hostname by using preg_match_all().
	 *
	 * @param string $attribute Attribute, e.g. 'src' or 'href'.
	 * @param string $source    HTML/blocks source.
	 * @param string $hostname  Specific hostname the images contain
	 *
	 * @return array|null If matches found, returns $matches as set by the preg_match_all(), otherwise null.
	 */
	private function match_attribute_with_hostname( $attribute, $source, $hostname ) {
		$pattern               = sprintf(
			'|
				%s="        # attribute opening
				(https?://  # start full image URL match with http or https
				%s          # hostname
				/.*?)       # end full image URL match
				"           # attribute closing
			|xims',
			$attribute,
			$hostname
		);
		$matches               = [];
		$preg_match_all_result = preg_match_all( $pattern, $source, $matches );

		return ( 0 === $preg_match_all_result || false === $preg_match_all_result )
			? null
			: $matches;
	}

	/**
	 * Gets site's public folder path (htdocs), without trailing slash.
	 * Considers Atomic setup variables first.
	 *
	 * @return string
	 */
	private function get_site_public_path() {
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			return realpath( WP_CONTENT_DIR . '/..' );
		}

		return rtrim( get_home_path(), '/' );
	}
}
