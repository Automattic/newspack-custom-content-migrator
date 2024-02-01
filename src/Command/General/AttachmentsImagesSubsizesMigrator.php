<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\Posts;
use \WP_CLI;

class AttachmentsImagesSubsizesMigrator implements InterfaceCommand {

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Posts instance.
	 *
	 * @var Posts Posts instance.
	 */
	private $posts;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts = new Posts();
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
			'newspack-content-migrator attachments-images-sanitize-filenames-for-subsizes-compatibility',
			[ $this, 'cmd_sanitize_filenames_for_subsizes_compatibility' ],
			[
				'shortdesc' => '',
			]
		);
	}

	/**
	 * Migrates postmeta dek to Newspack Subtitle.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_sanitize_filenames_for_subsizes_compatibility( array $pos_args, array $assoc_args ) {
		global $wpdb;

		// Store before and after of modified post_content.
		$beforeafter_logs_dir = 'post_content_image_filenames_replacements_before_after';
		if ( ! file_exists( $beforeafter_logs_dir ) ) {
			// phpcs:ignore -- Allow writing dev logs.
			mkdir( $beforeafter_logs_dir, 0755, true );
		}


		// Get all attachment IDs.
		$attachment_ids = $this->posts->get_all_posts_ids( 'attachment' );

		// Find attachments which end in {STRING}-\d+x\d+.{EXTENSION} which makes them incompatible with Subsizes.
		$attachments_w_faulty_filenames = [];
		foreach ( $attachment_ids as $key_attachment_id => $attachment_id ) {

			// Get attachment filename.
			$filename              = basename( get_attached_file( $attachment_id ) );
			$filename_no_extension = pathinfo( $filename, PATHINFO_FILENAME );
	
			// Check if the file name ends with the specified suffix pattern.
			if ( preg_match( '/-\d+x\d+$/', $filename_no_extension ) ) {
				$attachments_w_faulty_filenames[ $attachment_id ] = $filename;
			}
		}
		if ( empty( $attachments_w_faulty_filenames ) ) {
			WP_CLI::success( 'No attachments with faulty filenames found.' );
			return;
		}

		// Loop through attachments with faulty filenames and rename them.
		$url_changes = [];
		$i           = 0;
		foreach ( $attachments_w_faulty_filenames as $attachment_id => $filename ) {
			$i++;
			WP_CLI::line( sprintf( '%d/%d Renaming attachment ID %d', $i, count( $attachments_w_faulty_filenames ), $attachment_id ) );
			
			// Pt. 1. Find unique filename for attachment by appending "-\d+" to filename. Subsizes does not allow filenames ending in "-\d+x\d+", and it appends a -\d to the filename, so we must do the same.
			$max_suffixes = 1000;
			$new_filename = $this->find_unique_filename( $filename, $max_suffixes );
			if ( null == $new_filename ) {
				WP_CLI::error( sprintf( "Could not find a unique filename for attachment ID %d '%s' even after %d iterations/suffixes.", $attachment_id, $filename, $max_suffixes ) );
				continue;
			}
			
			
			// Pt. 2. Rename attachment and file.
			/**
			 * Rename attachment in DB
			 */
			$attachment_url = wp_get_attachment_url( $attachment_id );

			// Get old and new file paths exists on disk.
			$file_path_old = get_attached_file( $attachment_id );
			if ( ! file_exists( $file_path_old ) ) {
				WP_CLI::error( sprintf( 'File %s does not exist on disk for attachment ID %d.', $file_path_old, $attachment_id ) );
			}
			// Generate the new file path with the new filename.
			$directory_path = dirname( $file_path_old );
			$file_path_new  = $directory_path . '/' . $new_filename;
			
			
			// Remove extension from new post_name.			
			$new_post_title = pathinfo( $new_filename, PATHINFO_FILENAME );
			// Update the filename in the database.
			$updated = $wpdb->update(
				$wpdb->posts,
				[
					'post_name' => $new_post_title,
				],
				[ 'ID' => $attachment_id ],
			);
			if ( ! $updated ) {
				WP_CLI::error( sprintf( "Error updating attachment ID %d post_name and post_title in DB to '%s'", $attachment_id, $new_post_title ) );
			}
			// Update the attachment metadata.
			$updated_attached_file = update_attached_file( $attachment_id, $file_path_new );
			if ( ! $updated_attached_file ) {
				WP_CLI::error( sprintf( "Error updating update_attached_file attachment ID %d to '%s'", $attachment_id, $new_post_title ) );
			}
			WP_CLI::success( sprintf( "Updated attachment %d in DB from '%s' to '%s'", $attachment_id, $filename, $file_path_new ) );
			// Store URL changes.
			$attachment_url_new             = wp_get_attachment_url( $attachment_id );
			$url_changes[ $attachment_url ] = $attachment_url_new;

			/**
			 * Rename file on disk.
			 */
			// Rename the file on the disk.
			if ( ! rename( $file_path_old, $file_path_new ) ) {
				WP_CLI::error( sprintf( 'Error renaming file %s to %s for attachment ID %d.', $file_path_old, $file_path_new, $attachment_id ) );
			}
			WP_CLI::success( sprintf( "Updated attachment %d renamed file on disk from '%s' to '%s'", $attachment_id, $file_path_old, $file_path_new ) );
		}


		// Pt. 3. Search and replace the old filename in post_content.
		$post_ids = $this->posts->get_all_posts_ids();
		foreach ( $post_ids as $post_id ) {
			$post_content = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			
			// Run all URL changes on this post_content.
			$post_content_new = $post_content;
			if ( ! $post_content_new ) {
				continue;
			}
			foreach ( $url_changes as $attachment_url => $attachment_url_new ) {

				// Need to replace URLs starting with "/wp-content/uploads/" because hostname can change because of Photon CDN (local or Photon).
				$pos = strpos( $attachment_url, '/wp-content/uploads/' );
				if ( false === $pos ) {
					WP_CLI::error( sprintf( "Could not find '/wp-content/uploads/' in attachment URL %s.", $attachment_url ) );
				}
				$attachment_url_search  = substr( $attachment_url, $pos );
				$attachment_url_replace = substr( $attachment_url_new, strpos( $attachment_url_new, '/wp-content/uploads/' ) );

				$post_content_new = str_replace( $attachment_url_search, $attachment_url_replace, $post_content_new );
			}
			
			// If post_content was modified, update it in the DB, and log before/after.
			if ( $post_content_new != $post_content ) {
				
				// Store before and after of modified post_content.
				$before_file = $beforeafter_logs_dir . '/' . $post_id . '_before.txt';
				$after_file  = $beforeafter_logs_dir . '/' . $post_id . '_after.txt';
				file_put_contents( $before_file, $post_content );
				file_put_contents( $after_file, $post_content_new );
				
				// Update the post content.
				$wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $post_content_new ],
					[ 'ID' => $post_id ],
				);
				WP_CLI::success( sprintf( 'Updated post ID %d post_content.', $post_id ) );
			}
		}

		wp_cache_flush();
	}

	/**
	 * Find unique filename for attachment by appending "-\d+" to filename.
	 *
	 * @param string $filename     Attachment filename.
	 * @param string $max_suffixes Max numeric suffixes to try.
	 * 
	 * @return string|null Unique filename or null if not found.
	 */
	public function find_unique_filename( $filename, $max_suffixes ): ?string {
		
		global $wpdb;

		// Get attachment filename w/o extension and extension.
		$filename_no_extension = pathinfo( $filename, PATHINFO_FILENAME );
		$extension             = pathinfo( $filename, PATHINFO_EXTENSION );
		
		for ( $i = 1; $i <= $max_suffixes; $i++ ) {
			$new_filename = $filename_no_extension . '-' . $i . '.' . $extension;

			// Check if the new filename already exists and continue if it's unique.
			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = %s", $new_filename ) );
			if ( ! $existing_id ) {
				return $new_filename;
			}
		}

		return null;
	}

}
