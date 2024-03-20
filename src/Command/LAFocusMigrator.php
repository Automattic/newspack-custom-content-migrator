<?php
/**
 * Migration tasks for LA Focus.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use WP_CLI;

/**
 * Custom migration scripts for LA Focus.
 */
class LAFocusMigrator implements InterfaceCommand {

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
			[ $this, 'cmd_lafocus_set_featured_image_from_first_image_in_post_content' ],
			[
				'shortdesc' => 'Searches for the first image in Post Content and uses is a Featured Image in full size.',
			]
		);          
    }

    /** 
     * Searches for the first image in Post Content and uses it as a Featured Image in full size.
     */
    public function cmd_lafocus_set_featured_image_from_first_image_in_post_content( array $args, array $assoc_args ): void {
        WP_CLI::runcommand( 'newspack-content-migrator set-first-image-from-content-as-featured-image' );
    }

}
