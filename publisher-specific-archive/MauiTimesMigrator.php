<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

class MauiTimesMigrator implements InterfaceCommand {

	/**
	 * MauiTimesMigrator Instance.
	 *
	 * @var MauiTimesMigrator
	 */
	private static $instance;

	/**
	 * Get Instance.
	 *
	 * @return MauiTimesMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator maui-times-fix-posts-with-dupe-featured-images',
			[ $this, 'cmd_fix_posts_with_dupe_featured_images' ],
			[
				'shortdesc' => 'Will attempt to tie featured images to posts.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator maui-times-fix-subtitles',
			[ $this, 'cmd_fix_subtitles' ],
			[
				'shortdesc' => 'Copies ACF subtitles to post_excerpt and newspack_post_subtitle metadata.',
				'synopsis'  => [],
			]
		);
	}

	public function cmd_fix_posts_with_dupe_featured_images() {
		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT p.ID, p.post_content, GROUP_CONCAT( pm.meta_value ) as _thumbnail_id, COUNT( pm.meta_value ) as counter 
			FROM $wpdb->posts p INNER JOIN (
			    SELECT post_id, meta_key, meta_value 
			    FROM $wpdb->postmeta 
			    WHERE meta_key = '_thumbnail_id' 
			    GROUP BY post_id, meta_value
			) pm ON p.ID = pm.post_id
			WHERE p.post_type = 'post' AND p.post_status = 'publish'
			GROUP BY pm.post_id"
		);

		foreach ( $posts as $post ) {
			WP_CLI::log( 'Post ID: ' . $post->ID );

			if ( $post->counter > 1 ) {
				echo WP_CLI::colorize( '%rMore than one file path found.%n' ) . "\n";

				// Check if beginning of post_content has an image
				if ( str_starts_with( $post->post_content, '<!-- wp:image' ) || str_starts_with( $post->post_content, '<figure' ) ) {
					echo WP_CLI::colorize( '%cThere is an image at the beginning of post.%n' ) . "\n";
					$string = substr( $post->post_content, 0, 100 );
					echo WP_CLI::colorize( "%y$string%n" ) . "\n";
//					$wpdb->delete( $wpdb->postmeta, [ 'post_id' => $post->ID, 'meta_key' => '_thumbnail_id' ] );
					$wpdb->get_var( "DELETE FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND post_id = $post->ID AND meta_value IN ($post->_thumbnail_id)" );
				}

				continue;
			}

			$partial_file_path = $wpdb->get_results( "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = $post->_thumbnail_id AND meta_key = '_wp_attached_file'" );

			$count_of_results = count( $partial_file_path );
			if ( 0 === $count_of_results ) {
				echo WP_CLI::colorize( '%rNo file path found.%n' ) . "\n";
				continue;
			}

			$partial_file_path = $partial_file_path[0]->meta_value;

			WP_CLI::log( 'Partial file path: ' . $partial_file_path );
			$base_dir = wp_upload_dir()['basedir'];

			if ( ! file_exists( $base_dir . '/' . $partial_file_path ) ) {
				echo WP_CLI::colorize( '%rFile does not exist.%n' ) . "\n";
				continue;
			}

			$filename = basename( $partial_file_path );
			$filename_without_extension = pathinfo( $filename, PATHINFO_FILENAME );
			$file_extension = pathinfo( $filename, PATHINFO_EXTENSION );
			$first_thousand_characters = substr( $post->post_content, 0, 1000 );
			$full_filename_included_in_first_thousand_characters = str_contains( $first_thousand_characters, $filename );
			$filename_without_extension_included_in_first_thousand_characters = str_contains( $first_thousand_characters, $filename_without_extension );

			if ( $full_filename_included_in_first_thousand_characters ) {
				echo WP_CLI::colorize( '%pFile is already in use.%n' ) . "\n";
				// DELETE FROM wp_postmeta WHERE post_id = 123 AND meta_key = '_thumbnail_id';
				 $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $post->ID, 'meta_key' => '_thumbnail_id' ] );
			} else if ( $filename_without_extension_included_in_first_thousand_characters ) {
				$index_of_filename = strpos( $first_thousand_characters, $filename_without_extension );
				$start_of_partial_match_string = substr( $first_thousand_characters, $index_of_filename + strlen( $index_of_filename ), strlen( $filename_without_extension ) + 15 );
				$partial_match_string = substr( $first_thousand_characters, $index_of_filename - 5, strlen( $filename_without_extension ) + 20 );

				echo WP_CLI::colorize( '%pPartial match.%n' ) . "\n";
				echo WP_CLI::colorize( "%yFilename: $filename %n" ) . "\n";
				echo WP_CLI::colorize( "%yFilename in post_content: $partial_match_string%n" ) . "\n";
				if ( str_contains( $start_of_partial_match_string, $file_extension ) ) {
					echo WP_CLI::colorize( '%pFile is already in use.%n' ) . "\n";
					 $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $post->ID, 'meta_key' => '_thumbnail_id' ] );
				} else {
					if ( str_starts_with( $first_thousand_characters, '<!-- wp:image' ) || str_starts_with( $first_thousand_characters, '<figure' ) ) {
						echo WP_CLI::colorize( '%cThere is an image at the beginning of post.%n' ) . "\n";
						$string = substr( $first_thousand_characters, 0, 100 );
						echo WP_CLI::colorize( "%y$string%n" ) . "\n";
						 $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $post->ID, 'meta_key' => '_thumbnail_id' ] );
					} else {
						echo WP_CLI::colorize( '%gFeatured image not in beginning of post..%n' ) . "\n";
					}
				}
			} else {
				echo WP_CLI::colorize( '%gFeatured image not in beginning of post..%n' ) . "\n";
			}
		}
	}

	public function cmd_fix_subtitles(  ) {
		global $wpdb;

		$subtitle_rows = $wpdb->get_results(
			"SELECT * FROM $wpdb->postmeta WHERE meta_key = 'acf_subtitle' AND meta_value <> ''"
		);

		foreach ( $subtitle_rows as $subtitle_row ) {
			add_post_meta( $subtitle_row->post_id, 'newspack_post_subtitle', $subtitle_row->meta_value );
		}
	}
}