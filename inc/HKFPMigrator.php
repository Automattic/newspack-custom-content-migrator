<?php

namespace NewspackCustomContentMigrator;

use \NewspackCustomContentMigrator\InterfaceMigrator;
use \WP_CLI;
use PHPHtmlParser\Dom;

/**
 * Custom migration scripts for Hong Kong Free Press.
 */
class HKFPMigrator implements InterfaceMigrator {

	/**
	 * @var null|PostsMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton get_instance().
	 *
	 * @return PostsMigrator|null
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
			'newspack-live-migrate hkfp-getty-embeds',
			[ $this, 'cmd_hkfp_getty_embeds_conversion' ],
			[
				'shortdesc' => 'Migrates JS Getty embeds (not AMP-compatible) to legacy embeds (using a simple iframe).',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-live-migrate hkfp-in-pictures-template',
			[ $this, 'cmd_hkfp_in_pictures_template' ],
			[
				'shortdesc' => 'Makes sure all "In Pictures" posts are using the "One Column Wide" post template.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-live-migrate hkfp-lens-template',
			[ $this, 'cmd_hkfp_lens_template' ],
			[
				'shortdesc' => 'Makes sure all "Lens" posts are using the "One Column Wide" post template.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-live-migrate hkfp-pages-featured-images',
			[ $this, 'cmd_hkfp_pages_featured_images' ],
			[
				'shortdesc' => 'Makes sure pages do not use a featured image but set FB and Twitter images.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-live-migrate hkfp-accordions',
			[ $this, 'cmd_hkfp_accordions_conversion' ],
			[
				'shortdesc' => 'Migrates mks_accordion shortcode blocks to Atomic blocks Accordion blocks.',
				'synopsis'  => [],
			]
		);

  }

	/**
	 * Run through all posts and make sure In Pictures ones are set to the Wide template.
	 */
	public function cmd_hkfp_in_pictures_template() {

		$posts = get_posts( [
			'posts_per_page' => -1,
		] );

		foreach ( $posts as $post ) {

			// Only operate on Posts in the "In Pictures" section.
			if ( 0 === stripos( $post->post_title, 'In pictures:' ) ) {
				WP_CLI::line( sprintf(
					'Editing #%d: "%s"',
					$post->ID,
					$post->post_title
				) );

				WP_CLI::line( sprintf(
					'Adding tag #%d',
					$post->ID
				) );
				wp_set_object_terms( $post->ID, 'in-pictures', 'post_tag', true );

				if ( 'single-wide.php' !== get_post_meta( $post->ID, '_wp_page_template', true ) ) {

					WP_CLI::line( sprintf(
						'Updating template on #%d',
						$post->ID
					) );
					update_post_meta( $post->ID, '_wp_page_template', 'single-wide.php' );

				}

			}

		}

	}

	/**
	 * Run through all posts and make sure In Pictures ones are set to the Wide template.
	 */
	public function cmd_hkfp_lens_template() {

		$posts = get_posts( [
			'posts_per_page' => -1,
			'category_name'  => 'hkfp-lens',
		] );

		foreach ( $posts as $post ) {

			if ( 'single-wide.php' !== get_post_meta( $post->ID, '_wp_page_template', true ) ) {

				WP_CLI::line( sprintf(
					'Updating template on #%d',
					$post->ID
				) );
				update_post_meta( $post->ID, '_wp_page_template', 'single-wide.php' );
			}

		}

	}

	/**
	 * Run through all posts and make sure In Pictures ones are set to the Wide template.
	 */
	public function cmd_hkfp_pages_featured_images() {

		$pages = get_posts( [
			'post_type'      => 'page',
			'posts_per_page' => -1,
			'meta_query'     => [ [
				'key'     => '_thumbnail_id',
				'compare' => 'EXISTS',
			] ],
		] );

		$backup_thumbnail_id = 249452;
		$backup_thumbnail_url = 'https://hongkongfp-launch.newspackstaging.com/wp-content/uploads/2020/03/Desktop-wallpaper-1080x1920px.jpg';

		WP_CLI::line( sprintf( 'Checking %d pages', count( $pages ) ) );
		$update_count = 0;

		foreach ( $pages as $page ) {

			$thumbnail_id = get_post_meta( $page->ID, '_thumbnail_id', true );
			$thumbnail_url = wp_get_attachment_url( $thumbnail_id );
			if ( empty( $thumbnail_id ) || ! $thumbnail_url ) {
				$thumbnail_id = $backup_thumbnail_id;
				$thumbnail_url = $backup_thumbnail_url;
			}

			$fb_img = get_post_meta( $page->ID, '_yoast_wpseo_opengraph-image-id', true );
			if ( empty( $fb_img ) ) {
				WP_CLI::line( sprintf( 'Updating FB URL on page %d', $page->ID ) );
				update_post_meta( $page->ID, '_yoast_wpseo_opengraph', $thumbnail_id );
				update_post_meta( $page->ID, '_yoast_wpseo_opengraph-image', $thumbnail_url );
				$update_count++;
			}

			$tw_img = get_post_meta( $page->ID, '_yoast_wpseo_twitter-image-id', true );
			if ( empty( $tw_img ) ) {
				WP_CLI::line( sprintf( 'Updating Twitter URL on page %d', $page->ID ) );
				update_post_meta( $page->ID, '_yoast_wpseo_twitter-image-id', $thumbnail_id );
				update_post_meta( $page->ID, '_yoast_wpseo_twitter-image', $thumbnail_url );
				$update_count++;
			}

			// Backup and delete the original thumbnail ID.
			update_post_meta( $page->ID, '_thumbnail_id_backup', $thumbnail_id );
			delete_post_meta( $page->ID, '_thumbnail_id' );

		}

		WP_CLI::success( sprintf( 'Made %d updates', $update_count ) );

	}

	/**
	 * Create legacy Getty embed markup.
	 *
	 * @param string $anchor_href Link to the embed on Getty images.
	 * @param object $embed_data Embed data passed to the JS script in JS embed.
	 */
	public function create_getty_legacy_embed_markup($anchor_href, $embed_data) {
		$items = $embed_data->items;
		$width = $embed_data->w;
		$w = intval($width);
		$h = intval($embed_data->h);

		$query_data = array(
			'et' => $embed_data->id,
			'tld' => $embed_data->tld,
			'sig' => $embed_data->sig,
			'caption' => $embed_data->caption ? 'true' : 'false',
			'ver' => '1',
		);

		$wrapper_div_padding = $h / $w * 100;

		$embed_url_assets_fragment = "/$items?";
		// Embed URL is different for slideshows. If there are multiple assets, it's a slideshow.
		if (strpos($items, ',') !== false) {
			$embed_url_assets_fragment = "?assets=$items&";
		}

		return "
		<div class=\"getty embed image\" style=\"background-color:#fff;display:inline-block;font-family:Roboto,sans-serif;color:#a7a7a7;font-size:11px;width:100%;max-width:" . $width . ";\">
			<div style=\"padding:0;margin:0;text-align:left;\">
				<a
					href=\"$anchor_href\"
					target=\"_blank\"
					style=\"color:#a7a7a7;text-decoration:none;font-weight:normal !important;border:none;display:inline-block;\"
				>Embed from Getty Images</a>
			</div>
			<div style=\"overflow:hidden;position:relative;height:0;padding:" . $wrapper_div_padding . "% 0 0 0;width:100%;\">
				<iframe
					src=\"//embed.gettyimages.com/embed" . $embed_url_assets_fragment . http_build_query($query_data) . "\"
					scrolling=\"no\"
					frameborder=\"0\"
					width=\"$w\"
					height=\"$h\"
					style=\"display:inline-block;position:absolute;top:0;left:0;width:100%;height:100%;margin:0;\">
				</iframe>
			</div>
		</div>
		";
	}


	/**
	 * Search for posts which contain the JS embed code and replace those with legacy embeds.
	 *
	 * <post_ids>
	 * : Ids of posts to process. If not set, will process all posts.
	 *
	 */
	public function cmd_hkfp_getty_embeds_conversion( $args ) {
		if ( isset( $args[0] ) ) {
			WP_CLI::log( "Specified posts ids: " . implode(', ', $args) );
			$posts = get_posts(array(
				"include" => $args,
			));
		} else {
			$posts = get_posts(array(
				"numberposts" => -1,
			));
		}

		$processed_amount = 0;

		$getty_embed_predicate_string = 'embed-cdn.gettyimages.com/widgets.js';

		$tiny_mcs_intruder = '<span style="display: inline-block; width: 0px; overflow: hidden; line-height: 0;" data-mce-type="bookmark" class="mce_SELRES_start">﻿</span>';

		foreach ( $posts as $post ) {
			$post_content = $post->post_content;

			// Check if there's a Getty JS embed in there
			if (strpos($post_content, $getty_embed_predicate_string) !== false) {
				// The JS embed is not eclosed in an element, but is a sequence of siblings:
				// - anchor tag with a fallback link
				// - inline script for initialisation and providing data
				// - script that fetches the SDK

				// An old TinyMCE bug inserted a span in post content.
				// https://generatepress.com/forums/topic/4-9-anyone-else-having-tinymce-issues/
				if (strpos($post_content, $tiny_mcs_intruder) !== false) {
					WP_CLI::log( "Detected TinyMCE span, removing from post content." );
					$post_content = str_replace(
						$tiny_mcs_intruder,
						'',
						$post_content
					);
				}

				$post_dom = new Dom;
				$post_dom->load(
					$post_content,
					['removeScripts' => false, 'removeSmartyScripts' => false, 'removeStyles' => false]
				);
				$embed_anchors_nodes = $post_dom->find('a[href^="http://www.gettyimages"]');

				$embeds_count = substr_count(
					$post_content,
					$getty_embed_predicate_string
				);
				if ($embeds_count) {
					WP_CLI::log( "Detected $embeds_count Getty JS embed(s) in post '$post->post_title' ($post->ID)." );
				}

				foreach($embed_anchors_nodes as $anchor_node) {
					$classname = $anchor_node->getAttribute('class');
					// (PHPHtmlParser does not handle multiple attributes selector)
					if (strpos($classname, 'gie-') === 0) {
						$anchor_href = $anchor_node->getAttribute('href');
						WP_CLI::log( "Processing embed with href: $anchor_href" );

						$inline_script_node = $anchor_node->nextSibling();

						// Find `widgets.load` JS call, which passes the embed data as argument.
						$matches = [];
						preg_match(
							'/widgets\.load\((.*)\)}\);/',
							$inline_script_node->text,
							$matches
						);
						if (isset($matches[1])) {
							$embed_data = json_decode(preg_replace(
								'/(\w*):/',
								'"${1}":',
								preg_replace(
									'/\'/',
									"\"",
									$matches[1]
								)
							));
							WP_CLI::log( "Found embed with id $embed_data->id" );

							// Replace original embed markup with legacy embed in post content.
							$sdk_script_node = $inline_script_node->nextSibling();
							$post_content = str_replace(
								$anchor_node->outerHtml . $inline_script_node->outerHtml . $sdk_script_node->outerHtml,
								self::create_getty_legacy_embed_markup($anchor_href, $embed_data),
								$post_content
							);

							if (strpos($post_content, $anchor_node->outerHtml) !== false) {
								WP_CLI::warning( "Post #$post_id content not updated successfully." );
							} else {
								// Save updated post content.
								$post_id = wp_update_post(array(
									'ID' => $post->ID,
									'post_content' => $post_content,
								));
								if ( is_wp_error( $post_id ) ) {
									WP_CLI::error( $post_id->get_error_message() );
								} else {
									WP_CLI::success( "Updated post #$post_id." );
									$processed_amount = $processed_amount + 1;
								}
							}
						} else {
							WP_CLI::warning( "Load script not found adjacent to anchor tag. The embed might be malformated. Skipping this embed." );
						}
					}
				}
			}
		}

		if ($processed_amount > 0) {
			WP_CLI::success( "Completed $processed_amount Getty embeds conversion." );
		} else {
			WP_CLI::log( 'No JS Getty embeds found.' );
		}
		wp_cache_flush();
	}

	/**
	 * Convert all mks_accordion shortcodes into accordion blocks.
	 */
	public function cmd_hkfp_accordions_conversion() {
		$posts = get_posts( [
			'posts_per_page' => -1,
			's'              => 'mks_accordion',
			'post_type'      => [ 'post', 'page' ],
		] );

		$accordion_regex      = '#<!-- wp:shortcode -->\s*\[mks_accordion\](.*)\[\/mks_accordion\]\s*<!-- \/wp:shortcode -->#isU';
		$accordion_item_regex = '#\[mks_accordion_item title=(.*)\](.*)\[\/mks_accordion_item\]#isU';

		foreach ( $posts as $post ) {
			$num_accordion_shortcode_matches = preg_match_all( $accordion_regex, $post->post_content, $accordion_shortcode_matches, PREG_OFFSET_CAPTURE );
			if ( ! $num_accordion_shortcode_matches ) {
				continue;
			}

			$replacements = [];
			foreach ( $accordion_shortcode_matches[0] as $full_shortcode_match ) {
				$replacements[] = $full_shortcode_match[0];
			}

			$accordion_blocks = [];
			foreach ( $accordion_shortcode_matches[1] as $accordion_shortcode_match ) {
				$accordion_block            = '';
				$full_shortcode             = $accordion_shortcode_match[0];
				$num_shortcode_item_matches = preg_match_all( $accordion_item_regex, $full_shortcode, $shortcode_item_matches, PREG_SET_ORDER );
				if ( ! $num_shortcode_item_matches ) {
					$accordion_blocks[] = '';
					continue;
				}

				foreach ( $shortcode_item_matches as $shortcode_item_match ) {
					$accordion_block .= self::get_accordion_block_markup( $shortcode_item_match[1], $shortcode_item_match[2] );
				}
				$accordion_blocks[] = $accordion_block;
			}

			$updated_content = str_replace( $replacements, $accordion_blocks, $post->post_content );
			if ( $post->post_content !== $updated_content ) {
				$result = wp_update_post( [
					'ID'           => $post->ID,
					'post_content' => $updated_content,
				], true );
				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( 'Failed to update post: ' . $post->ID );
				} else {
					WP_CLI::line( "Updated post: " . $post->ID );
					ob_flush();
				}
			}
		}
		WP_CLI::line( 'Completed migration' );
	}

	/**
	 * Get markup for an accordion block.
	 *
	 * @param string $title Title of the accordion banner.
	 * @param string $content HTML content to put inside the accordion.
	 * @return string Raw block markup.
	 */
	public static function get_accordion_block_markup( $title, $content ) {
		$title   = str_replace( [ '"', '”', '\'', '“' ], '', trim( wp_strip_all_tags( $title ) ) );
		$content = wpautop( trim( $content ) );
		ob_start();
		?>
		<!-- wp:atomic-blocks/ab-accordion -->
		<div class="wp-block-atomic-blocks-ab-accordion ab-block-accordion">
			<details>
				<summary class="ab-accordion-title"><?php echo $title; ?></summary>
				<div class="ab-accordion-text">
					<!-- wp:html -->
					<?php echo $content; ?>
					<!-- /wp:html -->
				</div>
			</details>
		</div>
		<!-- /wp:atomic-blocks/ab-accordion -->
		<?php
		return ob_get_clean();
	}
}
