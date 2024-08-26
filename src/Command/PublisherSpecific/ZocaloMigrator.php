<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use Newspack\MigrationTools\Logic\CoAuthorsPlusHelper;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Post;

class ZocaloMigrator implements InterfaceCommand {

	private int $default_author_id;

	private CoAuthorsPlusHelper $coauthorsplus_logic;
	private Logger $logger;

	private function __construct() {
		// Nothing.
	}

	/**
	 * Get Singleton.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Do some quick sanity checks and setup before running the commands.
	 *
	 * @throws ExitException
	 */
	public function preflight(): void {
		static $has_run = false;
		if ( $has_run ) {
			// It looks like this gets called at least more than once pr. run, so bail if we already ran.
			return;
		}

		$this->coauthorsplus_logic = new CoAuthorsPlusHelper();
		$this->logger              = new Logger();

		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( '"Co-Authors Plus" plugin not found. Install and activate it before using the migration commands.' );
		}

		$has_run = true;
	}

	/**
	 * @throws Exception
	 */
	public function register_commands(): void {
		$generic_args = [
			'synopsis'      => '[--post-id=<post-id>] [--dry-run] [--num-items=<num-items>] [--refresh-existing]',
			'before_invoke' => [ $this, 'preflight' ],
		];

		WP_CLI::add_command(
			'newspack-content-migrator zps-import-post-authors',
			[ $this, 'cmd_import_post_authors' ],
			[
				...$generic_args,
				'shortdesc' => 'Import authors from ACF data on posts.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator zps-import-sub-titles',
			[ $this, 'cmd_import_sub_titles' ],
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

		$meta_key = 'sub_title';

		foreach ( $this->get_published_posts_with_meta_key( $meta_key, $assoc_args, $migration_meta ) as $post ) {
			$sub_title = trim( get_post_meta( $post->ID, $meta_key, true ) );
			if ( empty( $sub_title ) ) {
				continue;
			}
			$this->logger->log( 'sub_titles.log', sprintf( 'Updated sub title on post: %s', get_permalink( $post->ID ) ), Logger::SUCCESS );

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

		$meta_key = 'by_line';

		foreach ( $this->get_published_posts_with_meta_key( $meta_key, $assoc_args, $migration_meta ) as $post ) {

			$byline = get_post_meta( $post->ID, $meta_key );
			if ( empty( $byline ) ) {
				continue;
			}
			if ( ! is_array( $byline ) ) {
				$this->process_single_author( $byline, $post );
			} else {
				$authors = [];
				foreach ( $byline as $author ) {
					$authors = [
						...$authors,
						...$this->parse_author_string( wp_strip_all_tags( $author ) ),
					];
				}
				foreach ( array_unique( $authors ) as $author ) {
					$this->process_single_author( $author, $post );
				}
			}

			MigrationMeta::update( $post->ID, $migration_meta['key'], 'post', $migration_meta['version'] );
		}
	}

	private function process_single_author( string $author_name, WP_Post $post ): void {
		$author_args = [];
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
		}
		if ( ! is_wp_error( $guest_author_id ) ) {
			$this->coauthorsplus_logic->assign_guest_authors_to_post( [ $guest_author_id ], $post->ID, true );
			$this->logger->log( 'post_authors.log', sprintf( 'Assigned author: "%s" on post "%s"', $author_args['display_name'], get_permalink( $post->ID ) ), Logger::SUCCESS );
		}
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
