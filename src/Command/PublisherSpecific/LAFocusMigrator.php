<?php
/**
 * Migration tasks for LA Focus.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for LA Focus.
 */
class LAFocusMigrator implements InterfaceCommand {

	const POSTS_WITH_EXTERNAL_IMAGES_IN_CONTENT_USED_AS_FEATURED_IMAGES = [
		19956,
		19885,
		19765,
		19760,
		19758,
		19752,
		19750,
		19748,
		19742,
		19739,
		18648,
		4926,
		4828,
		4238,
		3499,
	];

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
	 * @return LAFocusMigrator
	 */
	public static function get_instance(): self {
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
			'newspack-content-migrator lafocus-set-featured-image-from-first-image-in-post-content',
			[ $this, 'cmd_set_featured_image_from_first_image_in_post_content' ],
			[
				'shortdesc' => 'Searches for the first image in Post Content and uses is a Featured Image in full size.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator lafocus-hide-featured-image-if-used-in-post-content',
			[ $this, 'cmd_hide_featured_image_if_used_in_post_content' ],
			[
				'shortdesc' => 'Hides the Featured Image if it\'s being used in post content',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator lafocus-hide-featured-images-on-posts-with-external-image-in-post-content',
			[ $this, 'cmd_hide_featured_image_on_posts_with_external_image_in_content' ],
			[
				'shortdesc' => 'Hides the Featured Image on Posts where image is used externally in post content',
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
	 * Searches for the first image in Post Content and uses it as a Featured Image in full size.
	 */
	public function cmd_set_featured_image_from_first_image_in_post_content( array $args, array $assoc_args ): void {
		WP_CLI::runcommand(
			'newspack-content-migrator set-first-image-from-content-as-featured-image',
			[
				'launch' => false,
			] 
		);
	}

	/** 
	 * Hides the Featured Image if it\'s being used in post content.
	 * 
	 * Alias for `newspack-content-migrator hide-featured-image-if-used-in-post-content --anywhere-in-post-content`
	 */
	public function cmd_hide_featured_image_if_used_in_post_content( array $args, array $assoc_args ): void {
		WP_CLI::runcommand(
			'newspack-content-migrator hide-featured-image-if-used-in-post-content --anywhere-in-post-content',
			[
				'launch' => false,
			] 
		);
	}

	/** 
	 * Hides the Featured Image on Posts where image is used externally in post content.
	 */
	public function cmd_hide_featured_image_on_posts_with_external_image_in_content( array $args, array $assoc_args ): void {
		$log = 'lafocus-hide-featured-image-used-externally-in-post-content.log';

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;

		$this->logger->log( $log, sprintf( 'Start processing posts %s', date( 'Y-m-d H:I:s' ) ) );

		foreach ( self::POSTS_WITH_EXTERNAL_IMAGES_IN_CONTENT_USED_AS_FEATURED_IMAGES as $post_id ) {
			if ( ! $dry_run ) {
				update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
			}

			$this->logger->log( $log, sprintf( 'Post ID %d -- featured image hidden', $post_id ), $this->logger::SUCCESS );
		}

		WP_CLI::success( sprintf( 'Done. See %s', $log ) );
		if ( $dry_run ) {
			WP_CLI::warning( 'This was a dry run. No changes were made.' );
		}
	}
}
