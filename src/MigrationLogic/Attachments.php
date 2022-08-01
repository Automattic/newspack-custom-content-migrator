<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \WP_CLI;

class Attachments {
	/**
	 * Imports a media object from file and returns the ID.
	 *
	 * @deprecated Warning: this function is quite slow, and the new function `import_external_file` here should be used instead.
	 *
	 * @param string $file Media file full path.
	 *
	 * @return mixed ID of the imported media file.
	 */
	public function import_media_from_path( $file ) {
		$options = [ 'return' => true, ];
		$id      = WP_CLI::runcommand( "media import $file --title='favicon' --porcelain", $options );

		return $id;
	}

	/**
	 * Imports/downloads external media file to the Media Library, either from a URL or from a local path.
	 *
	 * To untangle the terminology, the optional params Title, Caption, Description and Alt are the params we see in the
	 * Attachment edit form in WP Admin.
	 *
	 * @param string $path        Media file full URL or full local path, or URL to the media file.
	 * @param string $title       Optional. Attachment title.
	 * @param string $caption     Optional. Attachment caption.
	 * @param string $description Optional. Attachment description.
	 * @param string $alt         Optional. Image Attachment `alt` attribute.
	 * @param int    $post_id     Optional.  Post ID the media is associated with; this will ensure it gets uploaded to the same
	 *                            `yyyy/mm` folder.
	 * @param array  $args        Optional. Attachment creation argument to override used by the \media_handle_sideload(), used
	 *                            internally by the \wp_insert_attachment(), and even more internally by the \wp_insert_post().
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public function import_external_file( $path, $title = null, $caption = null, $description = null, $alt = null, $post_id = 0, $args = [] ) {
		// Fetch remote or local file.
		$is_http = 'http' == substr( $path, 0, 4 );
		if ( $is_http ) {
			$tmpfname = download_url( $path );
			if ( is_wp_error( $tmpfname ) ) {
				return $tmpfname;
			}
		} else {
			// The `media_handle_sideload()` function deletes the local file after import, so to preserve the local path, we're
			// first saving it to a temp location, in exactly the same way the WP's own `\download_url()` function above does.
			$tmpfname = wp_tempnam( $path );
			copy( $path, $tmpfname );
		}
		$file_array = [
			'name'     => wp_basename( $path ),
			'tmp_name' => $tmpfname,
		];

		if ( $title ) {
			$args['post_title'] = $title;
		}
		if ( $caption ) {
			$args['post_excerpt'] = $caption;
		}
		if ( $description ) {
			$args['post_content'] = $description;
		}
		$att_id = media_handle_sideload( $file_array, $post_id, $title, $args );

		// If this was a download and there was an error then clean up the temp file.
		if ( is_wp_error( $att_id ) && $is_http ) {
			@unlink( $file_array['tmp_name'] );
		}

		if ( $alt ) {
			update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
		}

		return $att_id;
	}
}
