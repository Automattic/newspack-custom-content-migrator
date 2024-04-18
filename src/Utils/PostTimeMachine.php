<?php

namespace NewspackCustomContentMigrator\Utils;

use Exception;
use WP_CLI;

class PostTimeMachine {

	/**
	 * Helper function to get the latest version ID of a post.
	 *
	 * @param int $post_id
	 *
	 * @return int
	 */
	private static function get_current_version_id( int $post_id ): int {
		$revision_data = wp_get_latest_revision_id_and_total_count( $post_id );
		if ( empty( $revision_data['latest_id'] ) ) {
			return $post_id;
		}

		return $revision_data['latest_id'];
	}

	/**
	 * Saves the version ID of a post to a snapshot file.
	 *
	 * @param string $snapshot_file_name The name of the snapshot file.
	 * @param int    $post_id The id of the post.
	 * @param int    $version_id The version id of the version of the post.
	 *
	 * @return void
	 * @throws Exception If there is an error opening the file.
	 *
	 */
	public static function snapshot_post_version( string $snapshot_file_name, int $post_id, int $version_id ): void {
		// Open the file for appending
		$csv_resource = fopen( $snapshot_file_name, 'a' );

		if ( $csv_resource === false ) {
			throw new Exception( "PostTimeMachine: Unable to open file: $snapshot_file_name" );
		}

		// Do some things only once.
		static $header_written, $revision_url_placeholder, $edit_url_placeholder = null;
		if ( empty( $header_written[ $snapshot_file_name ] ) ) {
			// Write headers.
			fputcsv( $csv_resource, [ 'ID', 'version_id', 'action_url' ] );
			$header_written[ $snapshot_file_name ] = true;

			// Get some url placeholders ready.
			$revision_url_placeholder = admin_url( 'revision.php?revision=%s' );
			$edit_url_placeholder     = admin_url( 'post.php?post=%s&action=edit' );
		}

		$url_placeholder = ( $post_id === $version_id ) ? $edit_url_placeholder : $revision_url_placeholder;

		fputcsv(
			$csv_resource,
			[
				$post_id,
				$version_id,
				sprintf( $url_placeholder, $version_id )
			]
		);
		fclose( $csv_resource );
	}

	/**
	 * Write a post to the snapshot file.
	 *
	 * @param string $snapshot_file_name File name of the snapshot file.
	 * @param int    $post_id ID of the post to snapshot.
	 *
	 * @return void
	 * @throws Exception
	 */
	public static function snapshot_post( string $snapshot_file_name, int $post_id ): void {
		$version_id = self::get_current_version_id( $post_id );

		self::snapshot_post_version( $snapshot_file_name, $post_id, $version_id );
	}


	/**
	 * Restore all files in a snapshot file to the versions specified in the file.
	 *
	 * @param string $snapshot_file_name
	 *
	 * @return void
	 * @throws Exception If anything goes wrong.
	 */
	public static function restore_snapshot( string $snapshot_file_name ): void {
		// TODO. add a param to bail on restore if there are revisions between the current and the one in the snapshot.
		$csv_iterator = new CsvIterator();
		foreach ( $csv_iterator->items( $snapshot_file_name, ',' ) as $item ) {
			if ( $item['version_id'] === $item['ID'] ) {
				$revision = array_key_last( wp_get_post_revisions( $item['ID'] ) );
			} else {
				$revision = $item['version_id'];
			}
			if ( wp_restore_post_revision( $revision ) ) {
				WP_CLI::success( sprintf( 'Restored post %d to revision %d: %s', $item['ID'], $revision, get_permalink( $item['ID'] ) ) );
			}
		}
	}

	/**
	 * Helper to get a consistent file name for dated snapshots.
	 *
	 * @return string A csv file name with the command name and a date.
	 */
	public static function get_dated_snapshot_file_name(): string {
		global $argv;
		$command = 'timemachine-' . $argv[2] ?? 'some-command';

		return sprintf( '%s-%s.csv', $command, date( 'Y-m-d-H-i-s' ) );
	}

}
