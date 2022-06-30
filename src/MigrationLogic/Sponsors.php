<?php

namespace NewspackCustomContentMigrator\MigrationLogic;

use \NewspackPostImageDownloader\Downloader;
use \WP_CLI;

class Sponsors {
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
	 * Newspack Sponsor Post Type.
     *
	 * @var string Sponsor Post Type.
	 */
	const SPONSORS_POST_TYPE = 'newspack_spnsrs_cpt';

	/**
	 * Insert a sponsor to the database.
	 *
	 * @param string          $title Sponsor Title.
	 * @param string          $slug Sponsor Slug.
	 * @param string          $description Sponsor Description.
	 * @param int|string|null $thumbnail Sponsor Thumbnail, can be the ID of the attachment, or a URL to download the attaachment from.
	 * @param string          $url Sponsor URL.
	 * @param string|false    $post_date Post creation date.
	 * @param string|false    $post_modified Last post modification date.
	 * @param string          $flag Sponsor Flag.
	 * @param string          $disclaimer Sponsor Disclaimer.
	 * @param string          $byline Sponsor Byline.
	 * @param string          $scope Sponsor Scope (empty or underwritten).
	 * @param boolean         $direct_only Show on posts only if direct sponsor.
	 * @return int
	 */
	public function insert_sponsor( $title, $slug = '', $description = '', $thumbnail = null, $url = '', $post_date = null, $post_modified = null, $flag = '', $disclaimer = '', $byline = '', $scope = '', $direct_only = false ) {
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
				'post_type'      => self::SPONSORS_POST_TYPE,
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

        // PostMeta.
        add_post_meta( $post_id, 'newspack_sponsor_url', esc_url( $url ) );
        add_post_meta( $post_id, 'newspack_sponsor_flag_override', $flag );
        add_post_meta( $post_id, '`newspack_sponsor_disclaimer_override`', wp_kses_post( $disclaimer ) );
        add_post_meta( $post_id, 'newspack_sponsor_byline_prefix', $byline );
        add_post_meta( $post_id, 'newspack_sponsor_sponsorship_scope', $scope );
        add_post_meta( $post_id, 'newspack_sponsor_only_direct', $direct_only );

		return $post_id;
	}
}
