<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \WP_CLI;

class Attachments {
	/**
	 * Imports a media object from file and returns the ID.
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
}
