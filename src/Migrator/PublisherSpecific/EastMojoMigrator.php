<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;

/**
 * Custom migration scripts for East Mojo.
 */
class EastMojoMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachments_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments_logic = new AttachmentsLogic();
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
			'newspack-content-migrator eastmojo-import-authors',
			[ $this, 'cmd_import_authors' ],
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
	 * Callable for the `newspack-content-migrator eastmojo-import-authors` command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_authors( $args, $assoc_args ) {

		// Check our arguments.
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : null;

		// Cater for checking specific posts.
		if ( $post_id ) {
			$posts = [ get_post( $post_id ) ];
			if ( 0 == count( $posts ) ) {
				WP_CLI::error( sprintf( 'Post %d not found.', $post_id ) );
			}
		} else {
			// Aaaaallll the posts!
			$posts = get_posts( [
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'post_status'    => 'any',
				'meta_query'     => [
					'key'     => 'em_author_data',
					'compare' => 'EXISTS',
				],
			] );
		}

		// Start processing.
		foreach ( $posts as $post ) {
			// Get the original author data, adding during RSS import.
			$author_data = get_post_meta( $post->ID, 'em_author_data', true );

			// Check if the user has already been imported.
			$user = $this->get_user_by(
				'meta_value',
				[
					'key'   => 'em_migrated_author_from_id',
					'value' => $author_data['author_id'],
				]
			);
			if ( ! is_a( $user, 'WP_User' ) ) {
				// No existing user, so create a new user.
				$new_user = wp_insert_user( [
					'user_login'   => $author_data['author_login'],
					'user_pass'    => wp_generate_password(21),
					'display_name' => $author_data['author_display_name'],
					'role'         => 'contributor',
				] );
				if ( is_wp_error( $user ) ) {
					// Oopsie.
					WP_CLI::warning( sprintf(
						'Failed to create user for author ID %d because %s',
						$author_data['author_id'],
						$user->get_error_message()
					) );
					continue;
				}

				// Make sure we can find the migrated user again.
				add_user_meta( $new_user, 'em_migrated_author_from_id', $author_data['author_id'] );
				$user = get_user_by( 'ID', $new_user );
			}

			// Assign the post to the new user.
			$update = wp_update_post( [
				'ID'          => $post->ID,
				'post_author' => $user->ID,
			] );
			if ( is_wp_error( $update ) ) {
				// Oopsie daisy.
				WP_CLI::warning( sprintf(
					'Failed to update post %d because %s',
					$post->ID,
					$update->get_error_message()
				) );
				continue;
			}

			// Hallelujah!
			WP_CLI::success( sprintf(
				'Updated post %d with author %d',
				$post->ID,
				$user->ID
			) );
		}

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
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : null;

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
			$query_public_posts = new \WP_Query( [
				'posts_per_page' => -1,
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				's'              => sprintf( '://%s/%s', $img_host, $img_path ),
			] );
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
	 * Gets filename from a URL or a path.
	 *
	 * @param string $path URL or path.
	 *
	 * @return string|null Filename.
	 */
	private function get_filename_from_path( $path ) {
		$pathinfo = pathinfo( $path );
		return ( isset( $pathinfo[ 'filename' ] ) && isset( $pathinfo[ 'extension' ] ) )
			? $pathinfo[ 'filename' ] . '.' . $pathinfo[ 'extension' ]
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

		return $url_parse[ 'path' ];
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
		$pattern = sprintf(
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
		$matches = [];
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
		if ( defined ( 'WP_CONTENT_DIR' ) ) {
			return realpath( WP_CONTENT_DIR . "/.." );
		}

		return rtrim( get_home_path(), '/' );
	}

	/**
	 * Get a user by meta or something else.
	 *
	 * Extends core's own get_user_by() to allow getting users by meta.
	 *
	 * @param  string           $field The field to retrieve the user with. id | ID | slug | email | login | meta_value.
	 * @param  int|string|array $value A value for $field. A user ID, slug, email address, login name, or array containing `key` and `value` for meta lookup.
	 * @return WP_User|false           WP_User object on success, false on failure.
	 */
	private function get_user_by( $field, $value ){
		if ( 'meta_value' === $field ) {
			$users = get_users( [
				'meta_key'   => $value['key'],
				'meta_value' => $value['value'],
			] );
			if ( empty( $users ) ) {
				return false;
			}

			return $users[0];
		}

		return get_user_by( $field, $value );
	}
}
