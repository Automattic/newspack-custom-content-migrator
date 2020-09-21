<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use PHPHtmlParser\Dom as Dom;

/**
 * Custom migration scripts for On The Wight.
 */
class OnTheWightMigrator implements InterfaceMigrator {

	/**
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
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator onthewight-tags-to-pages',
			[ $this, 'cmd_tags_to_pages' ],
			[
				'shortdesc' => 'Migrates On The Wight Tags containing HTML description to Pages, and does redirect corrections.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => __('Perform a dry run, making no changes.'),
						'optional'    => true,
					],
				],
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator cmd_tags_to_pages command`.
	 */
	public function cmd_tags_to_pages( $args, $assoc_args ) {
		$dry_run = $assoc_args['dry-run'] ? true : false;

		if ( ! class_exists( \Red_Item::class ) ) {
			WP_CLI::error( '🤭 The johngodley/redirection plugin is required for this command to work. Please first install and activate it.' );
		}

		WP_CLI::confirm( "❗ Warning/info ❗ Only run this command once since re-running it would create duplicate Pages and redirection rules. There's also the `--dry-run` flag you can use. Continue?" );

		$tags = get_tags();
		if ( ! $tags ) {
			WP_CLI::error( 'No tags were found. Most unusual... 🤔' );
		}

		// Check the parent Page for Pages we're about to create.
		$parent_page_slug = 'about-us';
		$parent_page      = get_page_by_path( 'about-us' );
		if ( ! $parent_page ) {
			WP_CLI::error( sprintf(
				"Could not find parent Page with slug '%s'... 🤭 Please edit this Migrator, update the hard-coded parent Page slug, and then give it another spin.",
				$parent_page_slug
			) );
		}

		if ( ! $dry_run ) {
			// Update Tag Base URL and rewrite rules to use `/tag/{TAG_SLUG}` URL schema for Tags.
			$this->update_wp_tag_base_and_existing_rewrite_rules( 'about/', 'tag/' );
		}

		// His name is Dom. Probably short for Dominic. (who says we can't have fun while migrating content... :) )
		$dom_parser = new Dom;

		foreach ( $tags as $tag ) {

			$is_tag_converted_to_page = false;

			// Don't create Pages for Tags without description.
			if ( ! empty( $tag->description ) ) {

				$dom_parser->loadStr( $tag->description );
				$h1_node = $dom_parser->find( 'h1', 0 );
				if ( ! $h1_node ) {
					continue;
				}

				// Get the rest of the description without the heading part.
				$heading_html                = $h1_node->outerHtml();
				$description_without_heading = trim( substr(
					$tag->description,
					strpos( $tag->description, $heading_html ) + strlen( $heading_html )
				) );

				// If there's some more HTML in the description, create a Page for the Tag.
				if ( $this->has_string_html( $description_without_heading ) ) {

					if ( $dry_run ) {
						WP_CLI::line( sprintf( '👍 creating Page from Tag %s', $tag->slug ) );
						continue;
					}

					// Create a Page.
					$post_details = array(
						'post_title'   => $h1_node->text,
						'post_content' => $description_without_heading,
						'post_parent'  => $parent_page->ID,
						'post_author'  => 1,
						'post_type'    => 'page',
						'post_status'  => 'publish',
					);
					$new_page_id  = wp_insert_post( $post_details );
					if ( 0 === $new_page_id || is_wp_error( $new_page_id ) ) {
						WP_CLI::error( sprintf(
							"Something went wrong when trying to create a Page from Tag term_id = %d. 🥺 So sorry about that...",
							$tag->term_id
						) );
					}

					// Add meta to the new page to indicate which tag it came from.
					add_post_meta( $new_page_id, '_migrated_from_tag', $tag->slug );

					// Create a redirect rule to redirect this Tag's legacy URL to the new Page.
					$this->create_redirection_rule(
						'Archive Tag to Page -- ' . $tag->slug,
						'/tag/' . $tag->slug . '[/]?',
						get_the_permalink( $new_page_id )
					);

					$is_tag_converted_to_page = true;
					WP_CLI::line( sprintf( '👍 created Page ID %d from Tag %s', $new_page_id, $tag->slug ) );
				}
			}

			if ( ! $is_tag_converted_to_page ) {
				WP_CLI::line( sprintf( '✓ creating redirection rule for updated Tag URL %s', $tag->slug ) );

				if ( $dry_run ) {
					continue;
				}

				// Redirect config: if we didn't create a Page, redirect this Tag's old URL `/about/{TAG_SLUG}` to the new `/tag/{TAG_SLUG}` URL.
				$this->create_redirection_rule(
					'Archive Tag to new URL -- ' . $tag->slug,
					'/about/' . $tag->slug . '[/]?',
					'/tag/' . $tag->slug
				);
			}
		}

		WP_CLI::line( "All done! 🙌 Oh, and you'll probably want to run `wp newspack-content-converter reset` next, and run the conversion for these new pages, too." );
	}

	/**
	 * Checks whether the given string contains HTML.
	 *
	 * @param $string
	 *
	 * @return bool
	 */
	private function has_string_html( $string ) {
		$dom_parser = new Dom;
		$dom_parser->loadStr( $string );

		$children_nodes = $dom_parser->getChildren();
		if ( ! $children_nodes ) {
			return false;
		}

		foreach ( $children_nodes as $node ) {
			if ( $node instanceof \PHPHtmlParser\Dom\HtmlNode ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Updates WP's Tag URL schema config, and updates existing rewrite rules too.
	 *
	 * @param string $old_tag_base E.g. 'about/'.
	 * @param string $new_tag_base E.g. 'tag/'.
	 */
	private function update_wp_tag_base_and_existing_rewrite_rules( $old_tag_base, $new_tag_base ) {

		// 1/2 Update the Tag base; if the Tag Base" option is left empty, WP will use the `/tag/{TAG_SLUG}` schema by default, so let's do that!
		update_option( 'tag_base', '' );

		// 2/2 Update existing WP rewrite rules from `/about/{TAG_SLUG}` to `/tag/{TAG_SLUG}`.
		$rewrite_rules         = get_option( 'rewrite_rules' );
		$updated_rewrite_rules = [];

		foreach ( $rewrite_rules as $pattern => $url ) {
			if ( 0 === strpos( $pattern, $old_tag_base ) ) {
				$updated_pattern                           = $new_tag_base . substr( $pattern, strlen( $old_tag_base ) );
				$updated_rewrite_rules[ $updated_pattern ] = $url;
			} else {
				$updated_rewrite_rules[ $pattern ] = $url;
			}
		}

		if ( $rewrite_rules != $updated_rewrite_rules ) {
			update_option( 'rewrite_rules', $updated_rewrite_rules );
		}
	}

	/**
	 * Creates a redirection rule with the johngodley/redirection plugin.
	 *
	 * @param string $title    Title for this redirect rule.
	 * @param string $url_from A regex flavored URL, param such as is used by Red_Item::create().
	 * @param string $url_to   An absolute URL to redirect to.
	 */
	private function create_redirection_rule( $title, $url_from, $url_to ) {
		\Red_Item::create( [
			'action_code' => 301,
			'action_data' => [
				'url' => $url_to,
			],
			'action_type' => 'url',
			'group_id'    => 1,
			'match_data'  => [
				'source' => [
					'flag_case'     => false,
					'flag_query'    => 'exact',
					'flag_regex'    => true,
					'flag_trailing' => false,
				],
			],
			'match_type' => 'url',
			'position' => 1,
			'title' => $title,
			'url' => $url_from,
		] );
	}
}
