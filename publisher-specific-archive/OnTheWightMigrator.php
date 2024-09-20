<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;
use PHPHtmlParser\Dom as Dom;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator as WpBlockManipulator;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator as SquareBracketsElementManipulator;
use \NewspackCustomContentMigrator\Command\PublisherSpecific\Exceptions\Onthewight_No_Wpshortode_Blocks_Found_In_Post;

/**
 * Custom migration scripts for On The Wight.
 */
class OnTheWightMigrator implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var WpBlockManipulator
	 */
	private $block_manipulator;

	/**
	 * @var SquareBracketsElementManipulator
	 */
	private $square_brackets_element_manipulator;

	/**
	 * Retrieved su_fms_desc content. Stored as a private variable for caching puposes.
	 *
	 * @var string $su_fms_desc_content
	 */
	private $su_fms_desc_content;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->block_manipulator                   = class_exists( WpBlockManipulator::class) ? new WpBlockManipulator : null;
		$this->square_brackets_element_manipulator = class_exists( SquareBracketsElementManipulator::class) ? new SquareBracketsElementManipulator : null;
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
		WP_CLI::add_command(
			'newspack-content-migrator onthewight-categories-to-pages',
			[ $this, 'cmd_categories_to_pages' ],
			[
				'shortdesc' => 'Migrates On The Wight categories containing HTML description to Pages, and does redirect corrections.',
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
		WP_CLI::add_command(
			'newspack-content-migrator onthewight-helper-analyze-used-shortcodes',
			[ $this, 'cmd_helper_analyze_used_shortcodes' ],
			[
				'shortdesc' => 'Helper command, scans all content for used shortcodes, outputs the shortcode designations with Post count or exact Post IDs where they were used.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator onthewight-shortcodes-convert',
			[ $this, 'cmd_shortcodes_convert' ],
			[
				'shortdesc' => 'Migrates On The Wight custom shortcodes to regular blocks.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => __('Perform a dry run, making no changes.'),
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator onthewight-download-images-from-s3',
			[ $this, 'cmd_download_images_from_s3' ],
			[
				'shortdesc' => 'Imports to local all images hosted on the S3 and updates source references to the local file, but if an image already exists in a predefined local path, use the local file instead of downloading it from the S3.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => __('Perform a dry run, making no changes.'),
						'optional'    => true,
					],
				],

			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator onthewight-download-audio-from-s3',
			[ $this, 'cmd_download_audio_from_s3' ],
			[
				'shortdesc' => 'Imports to local all audio hosted on the S3 and updates source references to the local file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
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
	 * Checks whether the Newspack Content Converter plugin is active and loaded.
	 *
	 * @return bool
	 */
	private function is_converter_plugin_active() {
		if (
			! is_plugin_active( 'newspack-content-converter/newspack-content-converter.php' ) ||
			! $this->block_manipulator ||
			! $this->square_brackets_element_manipulator
		) {
			return false;
		}

		return true;
	}

	/**
	 * Callable for the `newspack-content-migrator onthewight-shortcodes-convert`.
	 */
	public function cmd_shortcodes_convert( $args, $assoc_args ) {
		if ( ! $this->is_converter_plugin_active() ) {
			WP_CLI::error( '🤭 The Newspack Content Converter plugin is required for this command to work. Please install and activate it first.' );
		}

		global $wpdb;
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : null;

		// Convert just one specific Post.
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				WP_CLI::error( sprintf( 'Post with ID %d not found.', $post_id ) );
			}

			try {
				$this->convert_post_custom_blocks( $post_id, $dry_run );
			} catch ( Onthewight_No_Wpshortode_Blocks_Found_In_Post $e_no_shortcodes )  {
				// Catch -- it might have been already converted, so it's not an error.
				WP_CLI::line( sprintf( 'No shortcodes found for Post ID %d.', $post_id ) );
			}

			exit;
		}

		// Convert all Posts.
		WP_CLI::line( 'Fetching Posts...' );
		$results = $wpdb->get_results( $wpdb->prepare( sprintf ("SELECT ID FROM %s WHERE post_status = 'publish' and post_type = 'post';", $wpdb->prefix . 'posts' ) ) );
		if ( ! $results ) {
			WP_CLI::error( 'No public Posts found 🤭 Highly dubious!' );
		}

		foreach ( $results as $result ) {
			$post_id = (int) $result->ID;
			try {
				$this->convert_post_custom_blocks( $post_id, $dry_run );
			} catch ( Onthewight_No_Wpshortode_Blocks_Found_In_Post $e_no_shortcodes )  {
				// Skip this post if no `wp:shortode`s found.
				continue;
			}
		}
	}

	/**
	 * Converts a single Post's custom blocks to standard blocks.
	 *
	 * @param int $post_id Post ID.
	 */
	private function convert_post_custom_blocks( $post_id, $dry_run = false ) {
		$post    = get_post( $post_id );
		$content = $post->post_content;

		// Get WP Shortcode blocks.
		$shortcode_block_matches = $this->block_manipulator->match_wp_block( 'wp:shortcode', $content );
		if ( null === $shortcode_block_matches ) {
			throw new Onthewight_No_Wpshortode_Blocks_Found_In_Post();
		}

		// Go through the preg_match_all results containing the wp:shortcode blocks matches.
		$content_updated = $content;
		foreach ( $shortcode_block_matches[0] as $key => $match ) {
			$wp_shortcode_block = $shortcode_block_matches[0][ $key ][0];

			// Do all the custom conversions here, making changes to $content_updated.

			$converted_shortcode_block = $this->convert_su_accordion_with_su_spoiler_to_reusable_accordion_block( $wp_shortcode_block, $dry_run );
			if ( $converted_shortcode_block && $wp_shortcode_block != $converted_shortcode_block ) {
				$content_updated = str_replace( $wp_shortcode_block, $converted_shortcode_block, $content_updated );
			}

			$converted_shortcode_block = $this->convert_su_box_to_group_block( $wp_shortcode_block, $dry_run );
			if ( $converted_shortcode_block && $wp_shortcode_block != $converted_shortcode_block ) {
				$content_updated = str_replace( $wp_shortcode_block, $converted_shortcode_block, $content_updated );
			}

			$converted_shortcode_block = $this->convert_su_fms_desc_to_reusable_accordion_block( $wp_shortcode_block, $dry_run );
			if ( $converted_shortcode_block && $wp_shortcode_block != $converted_shortcode_block ) {
				$content_updated = str_replace( $wp_shortcode_block, $converted_shortcode_block, $content_updated );
			}
		}

		// Save Post.
		if ( $content_updated != $content ) {
			WP_CLI::line( sprintf( '👉 converted blocks in Post ID %d', $post_id ) );

			if ( ! $dry_run ) {
				$post->post_content = $content_updated;
				wp_update_post( $post );
			}
		}
	}

	/**
	 * Retrieves all Reusable Blocks
	 *
	 * @param int $numberposts `numberposts` argument for \WP_Query::construct().
	 *
	 * @return array Array of Posts.
	 */
	private function get_reusable_blocks(
		$numberposts = -1,
		$post_status = [ 'publish', 'pending', 'draft', 'future', 'private', 'inherit', 'trash' ]
	) {
		$posts                 = [];

		$query_reusable_blocks = new \WP_Query( [
			'numberposts' => $numberposts,
			'post_type'   => 'wp_block',
			'post_status' => $post_status,
		] );
		if ( ! $query_reusable_blocks->have_posts() ) {
			return $posts;
		}

		$posts = $query_reusable_blocks->get_posts();

		return $posts;
	}

	/**
	 * Converts a wp:shortcode block which contains an 'su_accordion' and an 'su_spoiler' shortcode into a wp:atomic-blocks/ab-accordion block,
	 * and then saves it as a Reusable Block.
	 *
	 * @param string $wp_shortcode_block
	 * @param bool   $dry_run
	 *
	 * @return string|null Reusable Block content.
	 */
	private function convert_su_accordion_with_su_spoiler_to_reusable_accordion_block( $wp_shortcode_block, $dry_run = false ) {

		// Check whether the wp:shortcode block contains an 'su_accordion' and an 'su_spoiler' shortcode.
		$shortcode_designations_matches = $this->match_all_shortcode_designations( $wp_shortcode_block );
		if ( ! isset( $shortcode_designations_matches[1] ) || $shortcode_designations_matches[1] !== [ 'su_accordion', 'su_spoiler' ] ) {
			return null;
		}

		// Get su_spoiler `title` param.
		$title = $this->get_shortcode_attribute(
			html_entity_decode( $shortcode_designations_matches[0][1] ),
			'title'
		);
		$title = $this->trim_all_quotes( $title );

		// Get the whole su_spoiler shortcode element.
		$su_spoiler_shortcode_element = $this->get_shortcode_element( 'su_spoiler', $wp_shortcode_block );

		// Get su_spoiler content.
		$content = $this->get_shortcode_contents( $su_spoiler_shortcode_element, [ 'su_spoiler' ] );
		$content = html_entity_decode( $content );

		$converted_block = <<<BLOCK
<!-- wp:atomic-blocks/ab-accordion -->
<div class="wp-block-atomic-blocks-ab-accordion ab-block-accordion"><details><summary class="ab-accordion-title">$title</summary><div class="ab-accordion-text">
<!-- wp:html -->
<p>$content</p>
<!-- /wp:html --></div></details></div>
<!-- /wp:atomic-blocks/ab-accordion -->
BLOCK;

		$reusable_block_content = $this->save_as_reusable_block( $converted_block, $title, $dry_run );

		return $reusable_block_content;
	}

	/**
	 * Converts a wp:shortcode block which contains an 'su_fms_desc' shortcode into a wp:atomic-blocks/ab-accordion block,
	 * and then saves it as a Reusable Block.
	 *
	 * @param string $wp_shortcode_block
	 * @param bool   $dry_run
	 *
	 * @return string|null Reusable Block content.
	 */
	private function convert_su_fms_desc_to_reusable_accordion_block( $wp_shortcode_block, $dry_run = false ) {

		// Check whether the wp:shortcode block contains an 'su_accordion' and an 'su_spoiler' shortcode.
		$shortcode_designations_matches = $this->match_all_shortcode_designations( $wp_shortcode_block );
		if ( ! isset( $shortcode_designations_matches[0][0] ) || $shortcode_designations_matches[0][0] !== '[su_fms_desc]' ) {
			return null;
		}

		// Get the `su_fms_desc` content.
		$su_fms_desc_content = $this->get_fms_desc_content();
		if ( ! $su_fms_desc_content ) {
			return $wp_shortcode_block;
		}

		// It's expected for this block to contain a combo of nested `su_accordion > su_spoiler` shortcodes, so finally convert those to a Reusable Block.
		$reusable_block_content = $this->convert_su_accordion_with_su_spoiler_to_reusable_accordion_block( $su_fms_desc_content, $dry_run );

		return $reusable_block_content;
	}

	/**
	 * Gets the `su_fms_desc` shortcode content stored in meta.
	 *
	 * @return string|false
	 */
	private function get_fms_desc_content() {
		if ( isset( $this->su_fms_desc_content ) ) {
			return $this->su_fms_desc_content;
		}

		$fms_desc_post_object = $this->get_su_shortcode_post_object( 'fms_desc' );

		$content_encoded           = get_post_meta( $fms_desc_post_object->ID, 'sumk_code', true );
		$this->su_fms_desc_content = base64_decode( $content_encoded );

		return $this->su_fms_desc_content;
	}

	/**
	 * Gets an `su_shortcode` Post object.
	 *
	 * @param string $sumk_slug The value of meta key 'sumk_slug`, which identifies the specific `su_*` Post.
	 *
	 * @return \WP_Post|null
	 */
	private function get_su_shortcode_post_object( $sumk_slug ) {
		$query_post = new \WP_Query( [
			'numberposts' => 1,
			'post_type'   => [ 'shortcodesultimate' ],
			'post_status' => 'publish',
			'meta_query'  => [
				[
					'key'   => 'sumk_slug',
					'value' => $sumk_slug
				]
			]
		] );

		return $query_post->have_posts() ? $query_post->get_posts()[0] : null;
	}


	/**
	 * Converts a wp:shortcode block which contains an 'su_box' shortcode into a wp:group block, and then saves it as a Reusable Block.
	 *
	 * @param string $wp_shortcode_block
	 * @param bool   $dry_run
	 *
	 * @return string|null Reusable Block content.
	 */
	private function convert_su_box_to_group_block( $wp_shortcode_block, $dry_run = false ) {

		// Check whether the wp:shortcode block contains an 'su_box' shortcode.
		$shortcode_designations_matches = $this->match_all_shortcode_designations( $wp_shortcode_block );
		if ( ! isset( $shortcode_designations_matches[1] ) || $shortcode_designations_matches[1] !== [ 'su_box' ] ) {
			return null;
		}

		// Get su_spoiler `title` param.
		$title = $this->get_shortcode_attribute(
			html_entity_decode( $shortcode_designations_matches[0][0] ),
			'title'
		);
		$title = $this->trim_all_quotes( $title );

		// Get the whole su_box shortcode element.
		$su_spoiler_shortcode_element = $this->get_shortcode_element( 'su_box', $wp_shortcode_block );

		// Get su_spoiler content.
		$content = $this->get_shortcode_contents( $su_spoiler_shortcode_element, [ 'su_box' ] );
		$content = html_entity_decode( $content );

		$converted_block = <<<BLOCK
<!-- wp:group {"backgroundColor":"light-gray"} -->
<div class="wp-block-group has-light-gray-background-color has-background"><div class="wp-block-group__inner-container"><!-- wp:heading {"level":4,"textColor":"dark-gray"} -->
<h4 class="has-dark-gray-color has-text-color"><strong>$title</strong></h4>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>$content</p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:group -->
BLOCK;

		$reusable_block_content = $this->save_as_reusable_block( $converted_block, $title, $dry_run );

		return $reusable_block_content;
	}

	/**
	 * Saves the $wp_block source as a Reusable Block, and returns the saved Reusable Block content.
	 *
	 * If a Reusable Block with this identical content already exists, it returns the existing Reusable Block's content.
	 *
	 * @param string $wp_block WP Block content.
	 * @param string $title    Title for the Reusable Block.
	 * @param book $dry_run    Dry run flag.
	 *
	 * @return string Saved Reusable Block content.
	 */
	private function save_as_reusable_block( $wp_block, $title, $dry_run ) {
		$reusable_blocks = $this->get_reusable_blocks();

		// Check if this Reusable Block already exists.
		$reusable_block_id = null;
		if ( ! empty( $reusable_blocks ) && ! $dry_run ) {
			foreach ( $reusable_blocks as $reusable_block ) {
				if ( $wp_block == $reusable_block->post_content ) {
					$reusable_block_id = $reusable_block->ID;
					break;
				}
			}
		}

		// Create a Reusable Block if it doesn't exist.
		if ( ! $reusable_block_id && ! $dry_run ) {
			$reusable_block_id  = wp_insert_post( [
				'post_title'   => $title,
				'post_content' => $wp_block,
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
			] );
			if ( ! $reusable_block_id ) {
				WP_CLI::error( 'Could not save Reusable Block Post.' );
			}
		}

		// Voilà, our reusable block.
		$reusable_block_content = sprintf( '<!-- wp:block {"ref":%d} /-->', $reusable_block_id );

		return $reusable_block_content;
	}


	/**
	 * Extracts a shortcode attribute.
	 *
	 * @param string $shortcode
	 * @param string $attribute_name
	 *
	 * @return string|null
	 */
	private function get_shortcode_attribute( $shortcode, $attribute_name ) {
		$attributes_values = shortcode_parse_atts( $shortcode );
		if ( empty( $attributes_values ) || ! $attributes_values ) {
			return null;
		}

		// The WP's shortcode_parse_atts() explodes the attributes' values using spaces as delimiters, so let's combine the whole attribute values from the result.
		$previous_key = null;
		foreach ( $attributes_values as $key => $value ) {
			if ( $previous_key && is_numeric( $key ) ) {
				$attributes_values[ $previous_key ] .= ' ' . $value;
				unset( $attributes_values[ $key ] );
				continue;
			}
			$previous_key = $key;
		}

		return isset( $attributes_values[ $attribute_name ] ) ? $attributes_values[ $attribute_name ] : null;
	}

	/**
	 * Gets a full shortcode element.
	 *
	 * @param string $shortcode_designation
	 * @param string $html
	 *
	 * @return mixed|null
	 */
	private function get_shortcode_element( $shortcode_designation, $html ) {
		$preg_all_match = $this->square_brackets_element_manipulator->match_elements_with_closing_tags(
			$shortcode_designation,
			$this->remove_line_breaks( $html )
		);

		return isset( $preg_all_match[0][0][0] ) ? $preg_all_match[0][0][0] : null;
	}

	/**
	 * Removes line breaks from a string.
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	private function remove_line_breaks( $string ) {
		return str_replace( [ "\n\r", "\n", "\r" ], '', $string );
	}

	/**
	 * Strips beginning and ending quotes from a string.
	 *
	 * @param string $string
	 *
	 * @return string string
	 */
	private function trim_all_quotes( $string ) {
		$string = html_entity_decode( $string );
		$string = trim( $string, '"' );
		$string = trim( $string, '”' );
		$string = trim( $string, '“' );
		$string = trim( $string, "'" );
		return $string;
	}

	/**
	 * Returns the content inside shortcodes.
	 *
	 * @param string      $shortcode Shortcode name.
	 * @param null|string $tagnames  Optional array of shortcode names, as defined by the get_shortcode_contents() function.
	 *
	 * @return string|null
	 */
	private function get_shortcode_contents( $shortcode, $tagnames = null ) {
		$pattern = get_shortcode_regex( $tagnames );
		$matches = [];
		preg_match( "/$pattern/s", $shortcode, $matches );

		return isset( $matches[5] ) ? $matches[5] : null;
	}

	/**
	 * Callable for the `newspack-content-migrator onthewight-helper-analyze-used-shortcodes`.
	 */
	public function cmd_helper_analyze_used_shortcodes( $args, $assoc_args ) {
		if ( ! $this->is_converter_plugin_active() ) {
			WP_CLI::error( '🤭 The Newspack Content Converter plugin is required for this command to work. Please install and activate it first.' );
		}

		global $wpdb;
		$shortcodes = [];

		WP_CLI::line( 'Fetching Posts...' );
		$results = $wpdb->get_results( $wpdb->prepare( sprintf ("SELECT ID FROM %s WHERE post_status = 'publish' and post_type = 'post';", $wpdb->prefix . 'posts' ) ) );
		if ( ! $results ) {
			WP_CLI::error( 'No public Posts found 🤭 Highly dubious!' );
		}

		foreach ( $results as $k => $result ) {

			$post_id = (int) $result->ID;
			$post    = get_post( $post_id );
			$content = $post->post_content;

			// Get WP Shortcode blocks.
			$shortcode_block_matches = $this->block_manipulator->match_wp_block( 'wp:shortcode', $content );
			if ( null === $shortcode_block_matches ) {
				continue;
			}

			// Loop through the preg_match_all result with Shortcode Blocks matches.
			foreach ( $shortcode_block_matches[0] as $key => $match ) {

				$shortcode_block = $shortcode_block_matches[0][ $key ][0];

				// Now get the Shortcodes inside this block.
				$shortcode_designations_matches = $this->match_all_shortcode_designations( $shortcode_block );
				if ( ! isset( $shortcode_designations_matches[1][0] ) || empty( $shortcode_designations_matches[1][0] ) ) {
					continue;
				}

				// Check if this designation was saved to the $shortcodes before.
				$key_existing = null;
				foreach ( $shortcodes as $k => $shortcodes_found_element ) {
					if ( $shortcode_designations_matches[1] === $shortcodes_found_element[ 'shortcode_matches' ] ) {
						$key_existing = $k;
						break;
					}
				}

				// Add to list of shortcodes, and the Post ID too.
				if ( ! is_null( $key_existing ) ) {
					$shortcodes[ $key_existing ][ 'ids' ][] = $post_id;
				} else {
					$shortcodes[] = [
						'shortcode_matches' => $shortcode_designations_matches[1],
						'ids' => [ $post_id ]
					];
				}
			}
		}

		// Output found shortcodes ordered ascending by number of Posts they're used in.
		$results_shortcodes_by_usage = [];
		foreach ( $shortcodes as $shortcode ) {
			$results_shortcodes_by_usage[ count( $shortcode['ids'] ) ] .=
				( isset( $results_shortcodes_by_usage[ count( $shortcode['ids'] ) ] ) ? "\n" : '' ) .
				sprintf(
					'👉 %s',
					implode( $shortcode['shortcode_matches'], ' > ' )
				) .
				"\n" .
				'total IDs ' . count( $shortcode['ids'] ) . ': ' . implode( $shortcode['ids'], ',' );
		}
		ksort( $results_shortcodes_by_usage );
		WP_CLI::line( implode( "\n", $results_shortcodes_by_usage ) );
	}

	/**
	 * Result of preg_match_all matching all shortcode designations.
	 *
	 * @param string $content
	 *
	 * @return mixed
	 */
	private function match_all_shortcode_designations( $content ) {
		$pattern_shortcode_designation = '|
			\[          # shortcode opening bracket
			([^\s/\]]+) # match the shortcode designation string (which is anything except space, forward slash, and closing bracket)
			[^\]]+      # zero or more of any char except closing bracket
			\]          # closing bracket
		|xim';
		preg_match_all( $pattern_shortcode_designation, $content, $matches );

		return $matches;
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
		$parent_page_slug = 'about';
		$parent_page      = get_page_by_path( $parent_page_slug );
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

				// Default content.
				$heading                     = $tag->name;
				$description_without_heading = $tag->description;

				$dom_parser->loadStr( $tag->description );
				$h1_node = $dom_parser->find( 'h1', 0 );
				if ( $h1_node ) {
					// Get the rest of the description without the heading part.
					$heading_html                = $h1_node->outerHtml();
					$heading                     = $h1_node->text;
					$description_without_heading = trim( substr(
						$tag->description,
						strpos( $tag->description, $heading_html ) + strlen( $heading_html )
						) );
				}

				if ( $dry_run ) {
					WP_CLI::line( sprintf( '👍 creating Page from Tag %s', $tag->slug ) );
					WP_CLI::line( sprintf( "-> adding post_meta to the new Page: '%s' = '%s'", '_migrated_from_tag', $tag->slug ) );
				} else {
					// Fix broken image URLs in the tag descriptions.
					$regex = '#wp-content\/([0-9]{4})\/([0-9]{2})\/#';
					$description_without_heading = preg_replace( $regex, "wp-content/uploads/$1/$2/", $description_without_heading );

					// Create a Page.
					$post_details = array(
						'post_title'   => $heading,
						'post_content' => $this->generate_page_content( $description_without_heading, $tag->term_id, 'tag' ),
						'post_parent'  => $parent_page->ID,
						'post_name'    => $tag->slug,
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

					WP_CLI::line( sprintf( '👍 created Page ID %d from Tag %s', $new_page_id, $tag->slug ) );
				}

				// Create a redirect rule to redirect this Tag's legacy URL to the new Page.
				$url_from = '/tag/' . $tag->slug . '[/]?';
				if ( $dry_run ) {
					WP_CLI::line( sprintf( '-> creating Redirect Rule from `%s` to the new Page', $url_from ) );
				} else {
					$this->create_redirection_rule(
						'Archive Tag to Page -- ' . $tag->slug,
						$url_from,
						get_the_permalink( $new_page_id )
					);

					WP_CLI::line( sprintf( '-> created Redirect Rule from `%s` to %s', $url_from, get_the_permalink( $new_page_id ) ) );
				}

				$is_tag_converted_to_page = true;
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
	 * Callable for the `newspack-content-migrator cmd_tags_to_pages command`.
	 */
	public function cmd_categories_to_pages( $args, $assoc_args ) {
		$dry_run = $assoc_args['dry-run'] ? true : false;

		if ( ! class_exists( \Red_Item::class ) ) {
			WP_CLI::error( '🤭 The johngodley/redirection plugin is required for this command to work. Please first install and activate it.' );
		}

		WP_CLI::confirm( "❗ Warning/info ❗ Only run this command once since re-running it would create duplicate Pages and redirection rules. There's also the `--dry-run` flag you can use. Continue?" );

		$categories = get_categories(
			[
				'hide_empty' => false,
				'meta_query' => [
					[
						'key'     => '_otw_migrated_to_page',
						'compare' => 'NOT EXISTS',
					]
				],
			]
		);
		if ( ! $categories ) {
			WP_CLI::error( 'No categories were found. Most unusual... 🤔' );
		}

		if ( ! $dry_run ) {
			// Update category Base URL and rewrite rules to use `/category/{category_slug}` URL schema for categories.
			$this->update_wp_category_base_and_existing_rewrite_rules( 'topic/', 'category/' );
		}

		// His name is Dom. Probably short for Dominic. (who says we can't have fun while migrating content... :) )
		$dom_parser = new Dom;

		foreach ( $categories as $category ) {

			$is_category_converted_to_page = false;

			// Default category URL base, for top-level categories;
			$category_base = '/category/';

			// Don't create Pages for Categories without description.
			if ( ! empty( $category->description ) ) {

				// Default content.
				$heading                     = $category->name;
				$description_without_heading = $category->description;

				$dom_parser->loadStr( $category->description );
				$h1_node = $dom_parser->find( 'h1', 0 );
				if ( $h1_node ) {
					// Get the rest of the description without the heading part.
					$heading_html                = $h1_node->outerHtml();
					$heading                     = $h1_node->text;
					$description_without_heading = trim( substr(
						$category->description,
						strpos( $category->description, $heading_html ) + strlen( $heading_html )
					) );
				}

				if ( $dry_run ) {
					WP_CLI::line( sprintf( '👍 creating Page from Category %s', $category->slug ) );
					WP_CLI::line( sprintf( "-> adding post_meta to the new Page: '%s' = '%s'", '_otw_migrated_from_category', $category->slug ) );
				} else {
					// Fix broken image URLs in the category descriptions.
					$regex                       = '#wp-content\/([0-9]{4})\/([0-9]{2})\/#';
					$description_without_heading = preg_replace( $regex, "wp-content/uploads/$1/$2/", $description_without_heading );

					// Create a Page.
					$post_details = array(
						'post_title'   => $heading,
						'post_content' => $this->generate_page_content( $description_without_heading, $category->term_id, 'category' ),
						'post_name'    => $category->slug,
						'post_author'  => 1,
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_parent'  => get_page_by_path('about')->ID,
					);

					// Check if the category has a parent.
					if ( ! empty( $category->category_parent ) ) {
						// Get the parent category deatils.
						$parent_category = get_category( $category->category_parent );

						// Get the page created for the parent category.
						$parent_page = get_posts( [
							'post_type'  => 'page',
							'meta_query' => [ [
								'key'   => '_otw_migrated_from_category',
								'value' => $parent_category->slug,
							] ]
						] );
						if ( empty( $parent_page ) ) {
							// Looks like there is no parent page. It might not have been created yet.
							// Skip this category so we can come back to it later.
							WP_CLI::warning( sprintf(
								'Skipping converting category %d because it has a parent category (%d) that we can\'t find a page for.',
								$category->term_id,
								$category->category_parent
							) );
							continue;
						}

						// Set the parent page.
						$post_details['post_parent'] = $parent_page[0]->ID;

						// Make sure we include the parent category in the redirect.
						$category_base = '/category/' . $parent_category->slug . '/';
					}

					$new_page_id  = wp_insert_post( $post_details );
					if ( 0 === $new_page_id || is_wp_error( $new_page_id ) ) {
						WP_CLI::error( sprintf(
							"Something went wrong when trying to create a Page from Category id = %d. 🥺 So sorry about that...",
							$category->term_id
						) );
					}

					// Add meta to the new page to indicate which category it came from.
					add_post_meta( $new_page_id, '_otw_migrated_from_category', $category->slug );

					// Add meta to the category so we can re-run without dupes.
					add_term_meta( $category->term_id, '_otw_migrated_to_page', $new_page_id, true );

					WP_CLI::line( sprintf( '👍 created Page ID %d from Category %s', $new_page_id, $category->slug ) );
				}

				// Create a redirect rule to redirect this Category's legacy URL to the new Page.
				$url_from = $category_base . $category->slug . '[/]?';
				if ( $dry_run ) {
					WP_CLI::line( sprintf( '-> creating Redirect Rule from `%s` to the new Page', $url_from ) );
				} else {
					$this->create_redirection_rule(
						'Archive Category to Page -- ' . $category->slug,
						$url_from,
						get_the_permalink( $new_page_id )
					);

					WP_CLI::line( sprintf( '-> created Redirect Rule from `%s` to %s', $url_from, get_the_permalink( $new_page_id ) ) );
				}

				$is_category_converted_to_page = true;
			}

			if ( ! $is_category_converted_to_page ) {
				WP_CLI::line( sprintf( '✓ creating redirection rule for updated Category URL %s', $category->slug ) );

				if ( ! $dry_run ) {
					// Redirect config: if we didn't create a Page, redirect this Category's old URL `/{CATEGORY_SLUG}` to the new `/category/{CATEGORY_SLUG}` URL.
					$this->create_redirection_rule(
						'Archive Tag to new URL -- ' . $category->slug,
						'/' . $category->slug . '/',
						'/category/' . $category->slug,
						false // Not a regex.
					);
				}

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
	 * Updates WP's categories URL schema config, and updates existing rewrite rules too.
	 *
	 * @param string $old_category_base E.g. '/'.
	 * @param string $new_category_base E.g. 'category/'.
	 */
	private function update_wp_category_base_and_existing_rewrite_rules( $old_category_base, $new_category_base ) {

		// 1/2 Update the Category base; if the Category Base" option is left empty, WP will use the `/category/{CATEGORY_SLUG}` schema by default, so let's do that!
		update_option( 'category_base', '' );

		// 2/2 Update existing WP rewrite rules from `/topic/{CATEGORY_SLUG}` to `/category/{CATEGORY_SLUG}`.
		$rewrite_rules         = get_option( 'rewrite_rules' );
		$updated_rewrite_rules = [];

		foreach ( $rewrite_rules as $pattern => $url ) {
			if ( 0 === strpos( $pattern, $old_category_base ) ) {
				$updated_pattern                           = $new_category_base . substr( $pattern, strlen( $old_category_base ) );
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
	private function create_redirection_rule( $title, $url_from, $url_to, $regex = true ) {
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
					'flag_regex'    => $regex,
					'flag_trailing' => false,
				],
			],
			'match_type' => 'url',
			'position' => 1,
			'title' => $title,
			'url' => $url_from,
		] );
	}

	/**
	 * Generates the content for the new tag/category page.
	 */
	private function generate_page_content( $description, $term, $taxonomy ) {

		// Longer descriptions should get an accordion.
		if ( strlen( $description ) > 105 ) {
			$description = sprintf(
				'<!-- wp:atomic-blocks/ab-accordion -->
		<div class="wp-block-atomic-blocks-ab-accordion ab-block-accordion">
			<details>
				<summary class="ab-accordion-title">See details</summary>
				<div class="ab-accordion-text">
					<!-- wp:freeform -->%1$s<!-- /wp:freeform -->
				</div>
			</details>
		</div>
		<!-- /wp:atomic-blocks/ab-accordion -->',
			$description
			);
		}

		// Construct the category/tag filter as required for the hompage posts block.
		switch ( $taxonomy ) {
			case 'category':
				$tax_filter = '"categories":["%s"]';
				break;
			case 'tag':
				$tax_filter = '"tags":["%s"]';
				break;
		}
		$tax_filter_string = sprintf( $tax_filter, $term );

		// The template for the page content. The description goes into a
		// Classic block in order to maintain the HTML.
		$content = '%1$s

<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-default","showAvatar":false,"postsToShow":1,"typeScale":4,%2$s} /-->

<!-- wp:newspack-blocks/homepage-articles {"className":"is-style-borders","showExcerpt":false,"moreButton":true,"showAvatar":false,"postsToShow":15,"typeScale":3,"mediaPosition":"left",%2$s,"imageScale":1} /-->';

		$blocks = sprintf(
			$content, // The content template.
			$description, // The current term description.
			$tax_filter_string // The taxonomy filter for the homepage posts blocks.
		);

		return $blocks;
	}

	/**
	 * Callable for the `newspack-content-migrator onthewight-download-images-from-s3` command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_download_images_from_s3( $args, $assoc_args ) {
		if ( ! $this->is_converter_plugin_active() ) {
			WP_CLI::error( '🤭 The Newspack Content Converter plugin is required for this command to work. Please install and activate it first.' );
		}

		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : null;

		global $wpdb;

		// OTW specific variabloes.
		$host_s3              = 'otwstatgraf.s3.amazonaws.com';
		$public_img_location  = 'wp-content/copy_images';
		$path_existing_images = $this->get_site_public_path() . '/' . $public_img_location;
		if ( ! file_exists( $path_existing_images ) ) {
			WP_CLI::error( sprintf( 'Path with existing S3 hosted images not found: %s', $path_existing_images ) );
		}

		// Get single Post or all Posts.
		if ( $post_id ) {
			$posts = [ get_post( $post_id ) ];
		} else {
			// Loop through posts detecting images hosted in the AWS bucket.
			$query_public_posts = new \WP_Query( [
				'posts_per_page' => -1,
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				's'              => sprintf( '://%s/', $host_s3 ),
			] );
			if ( ! $query_public_posts->have_posts() ) {
				WP_CLI::line( 'No Posts with S3 hosted images found.' );
				exit;
			}

			$posts = $query_public_posts->get_posts();
		}

		WP_CLI::line( sprintf( 'Found S3 hosted images in %d posts.', count( $posts ) ) );
		if ( 0 == count( $posts ) ) {
			return;
		}

		foreach ( $posts as $post ) {

			// Match images src attribute with S3 sources in Post content.
			$matches = $this->match_attribute_with_hostname( 'src', $post->post_content, $host_s3 );
			if ( ! $matches ) {
				continue;
			}

			WP_CLI::line( sprintf( '--- updating %d image(s) in POST ID %d', count( $matches[1] ), $post->ID ) );
			$errors               = [];
			$post_content_updated = $post->post_content;
			foreach ( $matches[1] as $key => $img_url_s3 ) {

				$img_src_s3     = $matches[0][ $key ];
				$img_filename   = $this->get_filename_from_path( $img_url_s3 );
				$img_local_path = $img_filename
					? $path_existing_images . '/' . $img_filename
					: null;

				// If this image exists locally, use the local file instead.
				$img_local_url = ( $img_local_path && file_exists( $img_local_path ) )
					? get_site_url() . '/' . $public_img_location . '/' . $img_filename
					: null;
				if ( $img_local_url ) {
					WP_CLI::line( sprintf( 'Using local image %s', $img_local_path ) );
					$img_import_url_from = $img_local_url;
				} else {
					WP_CLI::line( sprintf( 'Downloading from S3 %s', $img_url_s3 ) );
					$img_import_url_from = $img_url_s3;
				}

				// Import image.
				$img_url_new = ! $dry_run ? media_sideload_image( $img_import_url_from, $post->ID, null, $return = 'src' ) : '...';
				if ( is_wp_error( $img_url_new ) ) {
					$error_message = sprintf( 'ERROR could not save Post ID %s image URL %s because: %s', $post->ID, $img_import_url_from, $img_url_new->get_error_message() );
					$errors[]      = $error_message;
					WP_CLI::warning( $error_message );
					continue;
				}

				$post_content_updated = str_replace(
					$img_src_s3,
					sprintf( 'src="%s"', $img_url_new ),
					$post_content_updated
				);

				// Delete the local image file once it's been imported.
				if ( $img_local_url ) {
					WP_CLI::line( sprintf( 'Deleting imported local file %s', $img_local_path ) );
					if ( ! $dry_run ) {
						unlink( $img_local_path );
					}
				}
			}

			// Update the Post content.
			if ( ! $dry_run && $post_content_updated != $post->post_content ) {
				$wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
				WP_CLI::line( '✓ updated image URLs in post content' );
			}
		}

		// Required for the $wpdb->update() sink in.
		wp_cache_flush();

		if ( count( $errors ) > 0 ) {
			// Repeat error messages.
			WP_CLI::warning(
				sprintf( 'Finished with %d errors:', count( $errors ) )
				. "\n"
				. implode( "\n", $errors )
			);
		}
	}

	/**
	 * Gets site's public folder path (htdocs), without trailing slash.
	 * Considers Atomic setup variables first.
	 *
	 * @return string
	 */
	private function get_site_public_path() {
		if ( defined ( 'WP_CONTENT_DIR' ) ) {
			return realpath( WP_CONTENT_DIR . "/.." );
		}

		return rtrim( get_home_path(), '/' );
	}

	/**
	 * Gets filename from a URL or a path.
	 *
	 * @param string $path URL or path.
	 *
	 * @return string|null Filename.
	 */
	private function get_filename_from_path( $path ) {
		$pathinfo = pathinfo( $path );
		return ( isset( $pathinfo[ 'filename' ] ) && isset( $pathinfo[ 'extension' ] ) )
			? $pathinfo[ 'filename' ] . '.' . $pathinfo[ 'extension' ]
			: null;
	}

	/**
	 * Matches an attribute, e.g. `src="https://hostname/file"` with a specified hostname by using preg_match_all().
	 *
	 * @param string $attribute Attribute, e.g. 'src' or 'href'.
	 * @param string $source    HTML/blocks source.
	 * @param string $hostname  Specific hostname the images contain
	 *
	 * @return array|null If matches found, returns $matches as set by the preg_match_all(), otherwise null.
	 */
	private function match_attribute_with_hostname( $attribute, $source, $hostname ) {
		$pattern = sprintf(
			'|
				%s="        # attribute opening
				(https?://  # start full image URL match with http or https
				%s          # hostname
				/.*?)       # end full image URL match
				"           # attribute closing
			|xims',
			$attribute,
			$hostname
		);
		$matches = [];
		$preg_match_all_result = preg_match_all( $pattern, $source, $matches );

		return ( 0 === $preg_match_all_result || false === $preg_match_all_result )
			? null
			: $matches;
	}


	/**
	 * Callable for the `newspack-content-migrator onthewight-download-audio-from-s3` command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_download_audio_from_s3( $args, $assoc_args ) {
		if ( ! $this->is_converter_plugin_active() ) {
			WP_CLI::error( '🤭 The Newspack Content Converter plugin is required for this command to work. Please install and activate it first.' );
		}

		$dry_run = isset( $assoc_args[ 'dry-run' ] ) ? true : false;
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args[ 'post-id' ] : null;

		global $wpdb;

		// OTW specific variables.
		$host_s3 = 'otw-audio.s3.amazonaws.com';

		// Get single Post or all Posts.
		if ( $post_id ) {
			$posts = [ get_post( $post_id ) ];
		} else {
			// Loop through posts detecting audio files hosted in the AWS bucket.
			$query_public_posts = new \WP_Query( [
				'posts_per_page' => -1,
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				's'              => sprintf( '://%s/', $host_s3 ),
			] );
			if ( ! $query_public_posts->have_posts() ) {
				WP_CLI::line( 'No Posts with S3 hosted audio files found.' );
				exit;
			}

			$posts = $query_public_posts->get_posts();
		}

		WP_CLI::line( sprintf( 'Found S3 hosted audio files in %d posts.', count( $posts ) ) );
		if ( 0 == count( $posts ) ) {
			return;
		}

		foreach ( $posts as $post ) {

			// Match audio files' href attribute with S3 sources in Post content.
			$matches = $this->match_attribute_with_hostname( 'href', $post->post_content, $host_s3 );
			if ( ! $matches ) {
				continue;
			}

			WP_CLI::line( sprintf( '--- updating %d audio file(s) in POST ID %d', count( $matches[1] ), $post->ID ) );
			$errors               = [];
			$post_content_updated = $post->post_content;
			foreach ( $matches[1] as $key => $file_url_s3 ) {

				$file_href_s3 = $matches[0][ $key ];

				WP_CLI::line( sprintf( 'Downloading from S3 %s', $file_url_s3 ) );

				// Import audio file.
				$attachment_id = ! $dry_run ? $this->import_file_to_attachment( $file_url_s3, $post->ID ) : '...';
				if ( is_wp_error( $attachment_id ) ) {
					$error_message = sprintf( 'ERROR could not save Post ID %s audio file URL %s because: %s', $post->ID, $file_url_s3, $attachment_id->get_error_message() );
					$errors[]      = $error_message;
					WP_CLI::warning( $error_message );
					continue;
				}

				// Replace Post content with the imported file's URL.
				$post_content_updated = str_replace(
					$file_url_s3,
					wp_get_attachment_url( $attachment_id ),
					$post_content_updated
				);
			}

			// Update the Post content.
			if ( ! $dry_run && $post_content_updated != $post->post_content ) {
				$wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
				WP_CLI::line( '✓ updated audio URLs in post content' );
			}
		}

		// Required for the $wpdb->update() sink in.
		wp_cache_flush();

		if ( count( $errors ) > 0 ) {
			// Repeat error messages.
			WP_CLI::warning(
				sprintf( 'Finished with %d errors:', count( $errors ) )
				. "\n"
				. implode( "\n", $errors )
			);
		}
	}

	/**
	 * Imports an externally hosted media file to a local attachment.
	 *
	 * @param string $file_url       File URL.
	 * @param int    $parent_post_id Parent Post ID.
	 *
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	private function import_file_to_attachment( $file_url, $parent_post_id ) {

		// Download file from URL.
		$temp_file = download_url( $file_url );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$file_array = [
			'name'     => $this->get_filename_from_path( $file_url ),
			'tmp_name' => $temp_file,
		];
		$attachment_id = media_handle_sideload( $file_array , $parent_post_id, null );

		// Cleanup if import wasn't successful.
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $file_array[ 'tmp_name' ] );
		}

		return $attachment_id;
	}
}
