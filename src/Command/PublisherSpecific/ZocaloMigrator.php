<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use Newspack\MigrationTools\Command\WpCliCommandTrait;
use Newspack\MigrationTools\Log\FileLogger;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use Newspack\MigrationTools\Util\MigrationMeta;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;
use WP_Post;

class ZocaloMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

	private int $default_author_id;

	private CoAuthorsPlusHelper $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorsPlusHelper();
	}

	/**
	 * @throws Exception
	 */
	public static function register_commands(): void {
		$generic_args = [
			'synopsis' => '[--post-id=<post-id>] [--dry-run] [--num-items=<num-items>] [--refresh-existing]',
		];

		WP_CLI::add_command(
			'newspack-content-migrator zps-import-post-authors',
			self::get_command_closure( 'cmd_import_post_authors' ),
			[
				...$generic_args,
				'shortdesc' => 'Import authors from ACF data on posts.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator zps-import-sub-titles',
			self::get_command_closure( 'cmd_import_sub_titles' ),
			[
				...$generic_args,
				'shortdesc' => 'Import post sub-titles.',
			]
		);
	}

	public function cmd_import_sub_titles( array $pos_args, array $assoc_args ): void {
		$migration_meta = [
			'version' => 1,
			'key'     => 'import_sub_titles',
		];

		$site_url = trailingslashit( get_site_url() );
		$meta_key = 'sub_title';

		foreach ( $this->get_published_posts_with_meta_key( $meta_key, $assoc_args, $migration_meta ) as $post ) {
			$sub_title = trim( get_post_meta( $post->ID, $meta_key, true ) );
			if ( empty( $sub_title ) ) {
				continue;
			}
			FileLogger::log( 'sub_titles.log', sprintf( 'Updated sub title on post: %s', "$site_url?p=p={$post->ID}" ), Logger::SUCCESS );

			update_post_meta( $post->ID, 'newspack_post_subtitle', $sub_title );
			MigrationMeta::update( $post->ID, $migration_meta['key'], 'post', $migration_meta['version'] );
		}
	}

	public function cmd_import_post_authors( array $pos_args, array $assoc_args ): void {

		$this->default_author_id = $this->coauthorsplus_logic->create_guest_author(
			[
				'display_name' => 'Zócalo Public Square',
				'user_login'   => 'zocalo-public-square',
			]
		);

		$migration_meta = [
			'version' => 1,
			'key'     => 'import_post_authors',
		];

		$site_url = trailingslashit( get_site_url() );
		$meta_key = 'by_line';

		foreach ( $this->get_published_posts_with_meta_key( $meta_key, $assoc_args, $migration_meta ) as $post ) {
			$authors_to_assign = [];

			$byline = get_post_meta( $post->ID, $meta_key );
			if ( empty( $byline ) ) {
				continue;
			}
			if ( ! is_array( $byline ) ) {
				$authors_to_assign[] = $this->process_single_author( $byline, $post );
			} else {
				$author_strings = [];
				foreach ( $byline as $author ) {
					$author_strings = [
						...$author_strings,
						...$this->parse_author_string( wp_strip_all_tags( $author ) ),
					];
				}
				foreach ( array_unique( $author_strings ) as $author ) {
					$authors_to_assign[] = $this->process_single_author( $author, $post );
				}
			}
			$authors_to_assign = array_filter( $authors_to_assign );
			if ( ! empty( $authors_to_assign ) ) {
				$this->coauthorsplus_logic->assign_guest_authors_to_post( $authors_to_assign, $post->ID );
				FileLogger::log( 'post_authors.log',
					sprintf( 'Assigned author(s): "%s" on post "%s"', implode( ',', $authors_to_assign ), "$site_url?p={$post->ID}" ), Logger::SUCCESS );
			}

			MigrationMeta::update( $post->ID, $migration_meta['key'], 'post', $migration_meta['version'] );
		}
	}

	private function process_single_author( string $author_name, WP_Post $post ): int {
		$guest_author_id = 0;
		$author_args     = [];
		// Remove "by" prefix on author name.
		$author_args['display_name'] = preg_replace( '/^by /i', '', trim( $author_name ) );

		if ( empty( $author_args['display_name'] ) ) {
			$guest_author_id = $this->default_author_id;
		} else {
			$author_credit = get_post_meta( $post->ID, 'author_credit', true );
			if ( $author_credit ) {
				$author_args['description'] = trim( wp_strip_all_tags( $author_credit ) );
			}
			$guest_author_id = $this->coauthorsplus_logic->create_guest_author( $author_args );
			if ( is_wp_error( $guest_author_id ) ) {
				$guest_author_id = 0;
				FileLogger::log(
					'post_authors.log',
					sprintf( 'Could not create guest author with display name "%s" for post ID %d', $author_args['display_name'], $post->ID ),
					Logger::ERROR
				);
			}
		}

		return $guest_author_id;
	}

	private function parse_author_string( string $authors ): array {
		$strip    = [
			'translated by',
			'Translated by',
			'Interview by',
			'interview by',
			'as told to',
			'Updated by',
		];
		$replaced = str_replace( '\n', '', $authors );

		$good = [];
		// Split by , and &.
		foreach ( preg_split( '/(,\s|\s&\s|(\sand\s))/', $replaced ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( empty( $candidate ) || is_numeric( $candidate ) ) {
				continue;
			}
			foreach ( $strip as $strip_candidate ) {
				$candidate = str_replace( $strip_candidate, '', $candidate );
			}
			if ( ! empty( $candidate ) ) {
				$good[] = trim( trim( $candidate, '.' ) );
			}
		}

		return $good;
	}

	private function get_published_posts_with_meta_key( string $meta_key, array $assoc_args, array $migration_meta ): iterable {
		$post_id          = $assoc_args['post-id'] ?? false;
		$refresh_existing = $assoc_args['refresh-existing'] ?? false;
		$num_items        = $assoc_args['num-items'] ?? PHP_INT_MAX;

		if ( ! $post_id ) {
			global $wpdb;
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID 
						FROM $wpdb->posts
						LEFT JOIN $wpdb->postmeta 
							ON (ID = post_id AND meta_key = %s) 
						WHERE post_type = 'post'
						AND post_status = 'publish'
						AND meta_key = %s
						AND meta_value IS NOT NULL
						ORDER BY ID DESC",
					[ $meta_key, $meta_key ]
				)
			);
		} else {
			$ids = [ $post_id ];
		}

		$counter = 0;
		foreach ( $ids as $id ) {
			if ( ! $refresh_existing && MigrationMeta::get( $id, $migration_meta['key'], 'post' ) >= $migration_meta['version'] ) {
				continue;
			}
			if ( ++$counter > $num_items ) {
				break;
			}

			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				yield $post;
			}
		}
	}
}
