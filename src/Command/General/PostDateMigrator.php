<?php
/**
 * Commands to manipulate post dates.
 *
 * @package newspack-custom-content-migrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use DateTime;
use DateTimeZone;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use WP_CLI;

/**
 * Class PostDateMigrator.
 */
class PostDateMigrator implements InterfaceCommand {

	/**
	 * MySQL datetime format - the one WP uses for posts.
	 */
	const MYSQL_DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Constructor is private on purpose.
	 */
	private function __construct() {
	}

	/**
	 * Get Instance.
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
	 * {@inheritDoc}
	 *
	 * @throws Exception If the registration fails.
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator change-posts-timezone',
			[ $this, 'cmd_change_posts_timezone' ],
			[
				'shortdesc' => 'Change post dates from one timezone to another',
				'synopsis'  => [
					BatchLogic::$num_items,
					[
						'type'        => 'assoc',
						'name'        => 'from-timezone',
						'description' => 'Current timezone of the posts. Eg. --from-timezone="UTC"',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'target-timezone',
						'description' => 'Desired timezone of the posts. Eg. --target-timezone="America/New_York"',
						'optional'    => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'min-post-id',
						'description' => 'When selecting or processing wp posts any post with an id less than this will be skipped',
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Convert the date on posts from one timezone to another.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 *
	 * @throws Exception If the operation fails.
	 */
	public function cmd_change_posts_timezone( array $pos_args, array $assoc_args ): void {
		$metadata_key = '_np_timezone_fix';

		$num_items       = $assoc_args['num-items'] ?? PHP_INT_MAX;
		$from_timezone   = new DateTimeZone( $assoc_args['from-timezone'] );
		$target_timezone = new DateTimeZone( $assoc_args['target-timezone'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM $wpdb->posts p
					        LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = %s
					        WHERE p.post_status = 'publish' AND pm.meta_key IS NULL
					        ORDER BY p.ID DESC LIMIT %d",
				[ $metadata_key, $num_items ]
			)
		);

		$total_posts = count( $post_ids );
		foreach ( $post_ids as $row_no => $post_id ) {
			WP_CLI::log( sprintf( 'Processing (%d/%d) post id: %d', ( $row_no + 1 ), $total_posts, $post_id ) );
			$post = get_post( $post_id );

			$new_post_date     = $this->convert_date_to_timezone( $post->post_date, $from_timezone, $target_timezone );
			$new_modified_date = $this->convert_date_to_timezone( $post->post_modified, $from_timezone, $target_timezone );

			$result = wp_update_post(
				[
					'ID'                => $post_id,
					'post_date'         => $new_post_date,
					'post_date_gmt'     => get_gmt_from_date( $new_post_date ),
					'post_modified'     => $new_modified_date,
					'post_modified_gmt' => get_gmt_from_date( $new_modified_date ),
					'meta_input'        => [ $metadata_key => 1 ],
				]
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( sprintf( 'Failed to update post id: %d', $post_id ) );
				continue;
			}
			WP_CLI::log( sprintf( 'Updated time from %s to %s on post id %d', $post->post_date, $new_post_date, $post_id ) );
		}

		WP_CLI::log( sprintf( 'Updated time on %d posts', $total_posts ) );
	}

	/**
	 * Convert the date from one timezone to another.
	 *
	 * @param string       $post_date The date to convert.
	 * @param DateTimeZone $from_timezone The timezone to convert from.
	 * @param DateTimeZone $target_timezone The timezone to convert to.
	 *
	 * @return string The date in the target timezone.
	 */
	private function convert_date_to_timezone( string $post_date, DateTimeZone $from_timezone, DateTimeZone $target_timezone ): string {
		$date = DateTime::createFromFormat( self::MYSQL_DATETIME_FORMAT, $post_date, $from_timezone );
		$date->setTimezone( $target_timezone );

		return $date->format( self::MYSQL_DATETIME_FORMAT );
	}
}
