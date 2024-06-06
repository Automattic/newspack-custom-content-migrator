<?php
/**
 * Migration tasks for The Fifth Estate.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Feet in 2 Worlds.
 */
class FeetIn2WorldsMigrator implements InterfaceCommand {

	/**
	 * Logger.
	 *
	 * @var Logger $logger Logger instance.
	 */
	private $logger;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * Singleton.
	 *
	 * @return FeetIn2WorldsMigrator
	 */
	public static function get_instance(): FeetIn2WorldsMigrator {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @throws Exception
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator fi2w_populate_newspack_podcasts_file_meta',
			[ $this, 'cmd_populate_newspack_podcasts_file_meta' ],
			[
				'shortdesc' => 'Searches for posts with the audio_embed meta and extracts the src in a newspack_podcasts_podcast_file',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Searches for posts with the audio_embed meta and extracts the src in a newspack_podcasts_podcast_file.
	 */
	public function cmd_populate_newspack_podcasts_file_meta( array $args, array $assoc_args ): void {
		// Default Arguments
		$assoc_args = wp_parse_args(
			$assoc_args,
			[
				'dry-run' => false,
			] 
		);

		$start_time = microtime( true );
		$log        = sprintf( 'fi2w-populate-newspack-podcasts-file-meta-%s.log', date( 'Y-m-d H-i-s' ) );

		// CSV.
		$csv              = sprintf( 'fi2w-populate-newspack-podcasts-file-meta-%s.csv', date( 'Y-m-d H-i-s' ) );
		$csv_file_pointer = fopen( $csv, 'w' );
		fputcsv(
			$csv_file_pointer,
			[
				'#',
				'Post ID',
				'Status',
				'newspack_podcasts_podcast_file',
				'audio_embed',
			] 
		);
		
		if ( $assoc_args['dry-run'] ) {
			$this->logger->log( $log, '⚠️ Dry Run: No changes will be made.', Logger::WARNING );
		}

		$podcasts_posts = $this->get_podcasts_posts();

		$this->logger->log( $log, sprintf( 'Start processing %d Posts', count( $podcasts_posts ) ) );
		$progress_bar = WP_CLI\Utils\make_progress_bar( 'Start processing Posts', count( $podcasts_posts ), 1 );

		foreach ( $podcasts_posts as $index => $podcast_post_id ) {
			$this->logger->log(
				$log,
				sprintf(
					'[Memory: %s » Time: %s] Processing Post %s',
					size_format( memory_get_usage( true ) ),
					human_time_diff( $start_time, microtime( true ) ),
					$podcast_post_id
				),
				false
			);

			// Process meta.
			$status             = '';
			$embed_code         = get_post_meta( $podcast_post_id, 'audio_embed', true );
			$podcast_meta_value = get_post_meta( $podcast_post_id, 'newspack_podcasts_podcast_file', true );
			$podcast_file_src   = $this->parse_src_from_embed( $embed_code );

			if ( empty( $podcast_file_src ) ) {
				$status = 'NOT PARSED';

				$this->logger->log(
					$log,
					'Could not extract podcast file src',
					false
				);
			} elseif ( empty( $podcast_meta_value ) || $podcast_meta_value !== $podcast_file_src ) {
				$status = 'NEEDS UPDATE';

				$this->logger->log(
					$log,
					'Meta `newspack_podcasts_podcast_file` needs to be updated.',
					false
				);

				if ( ! $assoc_args['dry-run'] ) {
					if ( update_post_meta( $podcast_post_id, 'newspack_podcasts_podcast_file', $podcast_file_src ) ) {
						$status = 'UPDATED';

						$this->logger->log(
							$log,
							'Meta `newspack_podcasts_podcast_file` successfully updated.',
							false
						);
					} else {
						$status = 'ERROR';

						$this->logger->log(
							$log,
							'Error: Meta `newspack_podcasts_podcast_file` could not be updated.',
							false
						);
					}
				}
			} else {
				$status = 'UNCHANGED';

				$this->logger->log(
					$log,
					'Meta `newspack_podcasts_podcast_file` already exists and is up to date.',
					false
				);
			}

			fputcsv(
				$csv_file_pointer,
				[
					$index + 1,
					$podcast_post_id,
					$status,
					$podcast_file_src,
					$embed_code,
				]
			);

			$progress_bar->tick(
				1,
				sprintf(
					'[Memory: %s » Time: %s] Processing Post %s',
					size_format( memory_get_usage( true ) ),
					human_time_diff( $start_time, microtime( true ) ),
					$podcast_post_id
				)
			);
		}

		$progress_bar->finish();

		// CSV.
		fclose( $csv_file_pointer );

		if ( $assoc_args['dry-run'] ) {
			$this->logger->log( $log, '⚠️ Dry Run: No changes have been made.', Logger::SUCCESS );
		} else {
			$this->logger->log( $log, '✅ Successfully extracted and migrated src attributes!', Logger::SUCCESS );
		}
	}

	/**
	 * Get Podcasts Posts.
	 */
	private function get_podcasts_posts(): array {
		return get_posts(
			[
				'posts_per_page'         => -1,
				'post_type'              => 'post',
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'meta_query'             => [
					[
						'key'     => 'audio_embed',
						'compare' => '!=',
						'value'   => '',
					],
				],
				'cache_results'          => false,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			] 
		);
	}

	/**
	 * Extract the src attribute from embed string.
	 */
	private function parse_src_from_embed( string $embed_code ): ?string {
		if ( preg_match( '~src="([^"]+)"~', $embed_code, $match ) ) {
			return $match[1];
		} elseif ( preg_match( '~url="([^"]+)"~', $embed_code, $match ) ) {
			return $match[1];
		} elseif ( preg_match( '~^https?:\/\/.+~', $embed_code ) ) {
			return $embed_code;
		} elseif ( preg_match( '~^\[embed\](https?:\/\/.+)\[\/embed]$~', $embed_code, $match ) ) {
			return $match[1];
		}

		return null;
	}
}
