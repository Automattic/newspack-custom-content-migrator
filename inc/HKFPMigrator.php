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
	 */
	public function cmd_hkfp_getty_embeds_conversion() {
		$posts = get_posts(array(
			"numberposts" => -1,
		));
		$has_found = false;

		$getty_embed_predicate_string = 'embed-cdn.gettyimages.com/widgets.js';

		foreach ( $posts as $post ) {
			$post_content = $post->post_content;

			// Check if there's a Getty JS embed in there
			if (strpos($post_content, $getty_embed_predicate_string) !== false) {
				// The JS embed is not eclosed in an element, but is a sequence of siblings:
				// - anchor tag with a fallback link
				// - inline script for initialisation and providing data
				// - script that fetches the SDK

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
					$has_found = true;
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
								}
							}
						} else {
							WP_CLI::warning( "Load script not found adjacent to anchor tag. The embed might be malformated. Skipping this embed." );
						}
					}
				}
			}
		}

		if ($has_found) {
			WP_CLI::success( 'Completed Getty embeds conversion.' );
		} else {
			WP_CLI::log( 'No JS Getty embeds found.' );
		}
		wp_cache_flush();
	}
}
