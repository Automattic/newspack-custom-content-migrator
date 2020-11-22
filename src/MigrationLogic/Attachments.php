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
	 * @param string $path    Media file full URL or full local path, or URL to the media file.
	 * @param string $caption Caption, optional.
	 *
	 * @return int|WP_Error Attachment ID.
	 */
	public function import_external_file( $path, $caption = null ) {
		$is_http = 'http' == substr( $path, 0, 4 );
		$file_array = [
			'name' => wp_basename( $path ),
			'tmp_name' => $is_http ? download_url( $path ) : $path,
		];
		if ( $is_http && is_wp_error( $file_array[ 'tmp_name' ] ) ) {
			return $file_array[ 'tmp_name' ];
		}

		$att_id = media_handle_sideload( $file_array, 0 );
		if ( is_wp_error( $att_id ) && $is_http ) {
			@unlink( $file_array[ 'tmp_name' ] );
		}

		if ( $caption ) {
			wp_update_post( [
				'ID' => $att_id,
				'post_excerpt' => $caption,
			] );
		}

		return $att_id;
	}
}
