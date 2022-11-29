<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use WP_Query;
use WP_CLI;

/**
 * Custom migration scripts for Black Wall Street Times.
 */
class BlackWallStreetMigrator implements InterfaceCommand {

	/**
	 * Singleton instance.
	 *
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator convert-twitter-embeds-to-blocks',
			[ $this, 'cmd_convert_twitter_embeds_to_blocks' ],
			[
				'shortdesc' => 'Migrates the Twitter embeds from wp:quote to wp:embed.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator convert-twitter-embeds-to-blocks`.
	 *
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_convert_twitter_embeds_to_blocks( $pos_args, $assoc_args ) {
		$regex = '/<!-- wp:quote -->.*?\).*?href="(https:\/\/twitter.com.*?)\?.*?<!-- \/wp:quote -->/s';

		$block_template = <<<HTML
<!-- wp:embed {"url":"%s","type":"rich","providerNameSlug":"twitter","responsive":true} -->
<figure class="wp-block-embed is-type-rich is-provider-twitter wp-block-embed-twitter"><div class="wp-block-embed__wrapper">
%s
</div></figure>
<!-- /wp:embed -->
HTML;

		$twitter_js_embed = <<<HTML
<!-- wp:paragraph -->
<p><script async="" src="https://platform.twitter.com/widgets.js" charset="utf-8"></script></p>
<!-- /wp:paragraph -->
HTML;

		$args = array(
			'posts_per_page' => -1,
		);

		$query = new WP_Query( $args );

		foreach ( $query->posts as $post ) {
			$found = preg_match_all( $regex, $post->post_content, $matches );
			if ( ! $found ) {
				continue;
			}

			WP_CLI::log( sprintf( 'Updating post %s', $post->ID ) );
		
			$originals     = $matches[0];
			$twitter_links = $matches[1];

			$replaces = array();

			foreach ( $twitter_links as $link ) {
				$replaces[] = sprintf( $block_template, $link, $link );
			}

			$originals[] = $twitter_js_embed;
			$replaces[] = '';

			$new_content = str_replace( $originals, $replaces, $post->post_content );
			
			$result = wp_update_post(
				array(
					'ID' => $post->ID,
					'post_content' => $new_content,
				),
			);
		}

		WP_CLI::success( 'Done.' );
	}
}
