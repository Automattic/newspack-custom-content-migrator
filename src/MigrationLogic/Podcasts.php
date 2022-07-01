<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \NewspackPostImageDownloader\Downloader;
use \WP_CLI;

class Podcasts {
	/**
	 * Downloader helper to download thumbnails.
     *
	 * @var Downloader.
	 */
	private $downloader;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->downloader = new Downloader();
	}

	/**
	 * Newspack Podcast Post Type.
     *
	 * @var string Podcast Post Type.
	 */
	const PODCAST_POST_TYPE = 'episodes';

	/**
	 * Insert a podcast to the database.
	 *
	 * @param string          $title Podcast Title.
	 * @param string          $slug Podcast Slug.
	 * @param string          $description Podcast Description.
	 * @param int|string|null $thumbnail Podcast Thumbnail, can be the ID of the attachment, or a URL to download the attaachment from.
	 * @param int|string|null $track Podcast URL.
	 * @param string|false    $post_date Post creation date.
	 * @param string|false    $post_modified Last post modification date.
	 * @param string          $flag Podcast Flag.
	 * @param string          $disclaimer Podcast Disclaimer.
	 * @param string          $byline Podcast Byline.
	 * @param string          $scope Podcast Scope (empty or underwritten).
	 * @param boolean         $direct_only Show on posts only if direct podcast.
	 * @return int
	 */
	public function insert_podcast( $title, $slug = '', $description = '', $thumbnail = null, $track = null, $post_date = null, $post_modified = null, $flag = '', $disclaimer = '', $byline = '', $scope = '', $direct_only = false ) {
		$post_id = wp_insert_post(
            array(
				'post_title'     => $title,
				'post_content'   => $description,
				'post_status'    => 'publish',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_name'      => empty( $slug ) ? sanitize_title( $title ) : $slug,
				'post_date'      => $post_date,
				'post_modified'  => $post_modified,
				'post_type'      => self::PODCAST_POST_TYPE,
            )
        );

		// Thumbnail.
		if ( $thumbnail ) {
			if ( is_int( $thumbnail ) ) {
				set_post_thumbnail( $post_id, $thumbnail );
			} else {
				$thumbnail_id = $this->downloader->import_external_file( $thumbnail );
				WP_CLI::warning( sprintf( 'Attachment downloaded %d', $thumbnail_id ) );

				if ( ! is_wp_error( $thumbnail_id ) ) {
					set_post_thumbnail( $post_id, $thumbnail_id );
				}
			}
		}

		// Track.
		if ( $track ) {
			if ( is_int( $track ) ) {
				$track_url = wp_get_attachment_url( $track );
				add_post_meta( $post_id, 'newspack_podcasts_podcast_file', $track_url );
			} else {
				$track_id = $this->downloader->import_external_file( $track, null, null, null, null, 0, array(), 'mp3' );
				WP_CLI::warning( sprintf( 'Attachment downloaded %d', $track_id ) );

				if ( ! is_wp_error( $track_id ) ) {
					$track_url = wp_get_attachment_url( $track_id );
					add_post_meta( $post_id, 'newspack_podcasts_podcast_file', $track_url );
				}
			}
		}

		return $post_id;
	}
}
