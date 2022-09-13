<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;

/**
 * Custom migration scripts for Mustang News.
 */
class MustangNewsMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
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
			'newspack-content-migrator mustangnews-migrate-bylines',
			[ $this, 'cmd_migrate_bylines' ],
			[
				'shortdesc' => 'Migrate bylines to Co-Authors Plus.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator mustangnews-migrate-php-snippets',
			[ $this, 'cmd_migrate_snippets' ],
			[
				'shortdesc' => 'Migrate php-snippet shortcodes.',
			]
		);
	}

	/**
	 * Migrate bylines from custom 'byline' taxonomy to Co-Authors Plus.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_bylines( $args, $assoc_args ) {
		// Temporarily register bylines taxonomy.
		if ( ! taxonomy_exists( 'byline' ) ) {
			$args = [
				'hierarchical' => false,
			];
			register_taxonomy( 'byline', 'post', $args );
		}

		$post_ids = get_posts( [
			'posts_per_page' => -1,
			'fields' => 'ids',
		] );
		foreach ( $post_ids as $post_id ) {
			$byline_terms = wp_get_post_terms( $post_id, 'byline' );
			if ( empty( $byline_terms ) ) {
				continue;
			}

			$guest_author_ids = [];

			foreach ( $byline_terms as $byline_term ) {
				$guest_author_name = $byline_term->name;
				$guest_author_login = $byline_term->slug;

				// Find guest author if already created.
				$guest_author    = $this->coauthorsplus_logic->get_guest_author_by_user_login( $guest_author_login );
				$guest_author_id = $guest_author ? $guest_author->ID : 0;

				// Create guest author if not found.
				if ( ! $guest_author_id ) {
					$guest_author_data = [
						'display_name' => $guest_author_name,
						'user_login'   => $guest_author_login,
					];
					WP_CLI::warning( "Creating guest author: " . json_encode( $guest_author_data ) );
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author( $guest_author_data );
				}

				$guest_author_ids[] = $guest_author_id;
			}

			$existing_guest_authors = $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id );
			$existing_guest_author_ids = wp_list_pluck( $existing_guest_authors, 'ID' );
			if ( empty( array_diff( $guest_author_ids, $existing_guest_author_ids ) ) ) {
				WP_CLI::warning( "Post " . $post_id . " already has all guest authors. Skipping." );
				continue;
			}

			$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id );
			WP_CLI::warning( "Updated post " . $post_id );
		}
	}

	/**
	 * Migrate php-snippet-based shortcodes to blocks.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_snippets( $args, $assoc_args ) {
		global $wpdb;

		$posts = get_posts( [
			'posts_per_page' => -1,
			's' => 'xyz-ips',
		] );

		$snippet_shortcode_regex = '#\[xyz-ips snippet=\"([^\"]*)\"[^\]]*\]#isU';

		foreach ( $posts as $post ) {
			$has_snippets = preg_match_all( $snippet_shortcode_regex, $post->post_content, $snippet_matches );
			if ( ! $has_snippets ) {
				WP_CLI::warning( "Post " . $post->ID . " doesn't appear to have snippets. Skipping." );
				continue;
			}

			$updated_content = $post->post_content;

			foreach ( $snippet_matches[0] as $index => $full_snippet ) {
				$snippet_type = $snippet_matches[1][ $index ];

				if ( 'longformpostinfo' === $snippet_type ) {
					WP_CLI::warning( "Byline snippet found on post " . $post->ID );
					$replacement = $this->get_byline_snippet( $post->ID );
					$updated_content = str_replace( $full_snippet, $replacement, $updated_content );
				} else if ( 'longformsocial' === $snippet_type ) {
					WP_CLI::warning( "Social snippet found on post " . $post->ID );
					$replacement = $this->get_social_block_snippet( $post->ID );
					$updated_content = str_replace( $full_snippet, $replacement, $updated_content );
				} else {
					WP_CLI::error( "Unknown snippet type encountered: " . $snippet_type . " on post " . $post->ID );
				}
			}

			if ( $updated_content !== $post->post_content ) {
				$result = $wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $updated_content ], [ 'ID' => $post->ID ] );
				if ( ! $result ) {
					WP_CLI::line( 'Error updating post ' . $post->ID );
				} else {
					WP_CLI::warning( 'Updated post ' . $post->ID );
				}
			} else {
				WP_CLI::warning( "Nothing found to update on post " . $post->ID );
			}
		}
	}

	/**
	 * Get markup for a social icons block.
	 *
	 * @param int $post_id Post ID.
	 * @return string Block markup.
	 */
	private function get_social_block_snippet( $post_id ) {

		$url = get_permalink( $post_id );

		ob_start();
		?>
<!-- wp:social-links -->
<ul class="wp-block-social-links"><!-- wp:social-link {"url":"http://www.facebook.com/sharer.php?u=<?php echo urlencode( $url ); ?>","service":"facebook"} /-->

<!-- wp:social-link {"url":"https://twitter.com/intent/tweet?text=<?php echo urlencode( get_the_title( $post_id ) ); ?>\u0026url=<?php echo urlencode( $url ); ?>\u0026via=CPMustangNews","service":"twitter"} /--></ul>
<!-- /wp:social-links -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Get markup for an author byline "block".
	 *
	 * @param int $post_id Post ID.
	 * @return string Block markup.
	 */
	private function get_byline_snippet( $post_id ) {

		$byline = '';
		if ( function_exists( 'get_coauthors' ) && ! empty( get_coauthors( $post_id ) ) ) {
			$authors = get_coauthors( $post_id );
			$byline = implode( ', ', wp_list_pluck( $authors, 'display_name' ) ) . ' - ';
		}

		$byline .= get_the_date( '', $post_id );
		ob_start();
		?>
<!-- wp:paragraph -->
<p><strong><?php echo $byline; ?></strong></p>
<!-- /wp:paragraph -->
		<?php
		return ob_get_clean();
	}
}
