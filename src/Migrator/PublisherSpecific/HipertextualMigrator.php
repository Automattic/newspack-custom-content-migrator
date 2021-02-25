<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;

/**
 * Custom migration scripts for Hipertextual.
 */
class HipertextualMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachments_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
		// Bit of a hack doing it this way but ¯\_(ツ)_/¯.

		// Convert Markdown.
		add_filter( 'np_meta_to_content_value', [ $this, 'convert_markdown_headings' ], 10, 3 );
		add_filter( 'np_meta_to_content_value', [ $this, 'convert_markdown_bold' ], 10, 3 );
		add_filter( 'np_meta_to_content_value', [ $this, 'convert_markdown_italics' ], 11, 3 ); // Do after bold.

		// Add missing headings to some sections.
		add_filter( 'np_meta_to_content_value', [ $this, 'add_section_headings' ], 10, 3 );

		// Bulleted lists for Pros and Cons. Run before columns transformation.
		add_filter( 'np_meta_to_content_value', [ $this, 'pros_cons_bullets' ], 9, 3 );

		// Add columns for the Pros and Cons section.
		add_filter( 'np_meta_to_content_value', [ $this, 'pros_cons_columns' ], 10, 3 );
	}

	/**
	 * Convert markdown headings
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_headings( $value, $key, $post_id ) {

		// Look for markdown headings, skip if there aren't any.
		$find = \preg_match_all( '/(#+\S+.+)\n/', $value, $matches );
		if ( ! $find || 0 === $find ) {
			return $value;
		}

		// Markdown to look for and HTML tags to replace them with.
		$replacements = [
			'#####' => 'h5',
			'####'  => 'h4',
			'###'   => 'h3',
			'##'    => 'h2',
		];

		foreach ( $matches[0] as $match ) {

			// Loop through each markdown replacement needed.
			foreach( $replacements as $search => $replace ) {
				if ( false !== \strpos( $match, $search ) ) {

					// Remove the markdown from the heading, leaving just the text.
					$title = \str_replace( $search, '', $match );

					// Wrap the heading with the relevant HTML tags.
					$title = sprintf( '<%1$s>%2$s</%1$s>', $replace, trim( $title ) );

					// Replace the original string in the meta value with our new HTML string.
					$value = str_replace( $match, $title, $value );
				}
			}

		}

		return $value;
	}

	/**
	 * Convert markdown bold
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_bold( $value, $key, $post_id ) {

		// Look for markdown bold, skip if there aren't any.
		$find = \preg_match_all( '/\*\*[A-z0-9\sáéíóúñü]+\*\*/', $value, $matches );
		if ( ! $find || 0 === $find ) {
			return $value;
		}

		foreach ( $matches[0] as $match ) {
			$new_text = \str_replace( '*', '', $match );
			$new_text = sprintf( '<strong>%s</strong>', $new_text );
			$value    = \str_replace( $match, $new_text, $value );
		}

		return $value;

	}

	/**
	 * Convert markdown italics
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_italics( $value, $key, $post_id ) {

		// Look for markdown bold, skip if there aren't any.
		$find = \preg_match_all( '/\*[A-z0-9\sáéíóúñü]+\*/', $value, $matches );
		if ( ! $find || 0 === $find ) {
			return $value;
		}

		foreach ( $matches[0] as $match ) {
			$new_text = \str_replace( '*', '', $match );
			$new_text = sprintf( '<em>%s</em>', $new_text );
			$value    = \str_replace( $match, $new_text, $value );
		}

		return $value;

	}

	/**
	 * Add section headings to some sections.
	 *
	 * @return string Converted content.
	 */
	public function add_section_headings( $value, $key, $post_id ) {

		// Things
		$headings = [
			'conclusion' => '<h2>Conclusión</h2>',
		];

		if ( ! in_array( $key, array_keys( $headings ) ) ) {
			return $value;
		}

		foreach ( $headings as $meta_key => $heading ) {
			if ( $meta_key === $key ) {
				// Append the relevant heading on to the content.
				$value = $heading . $value;
			}
		}

		return $value;

	}

	/**
	 * Bulleted lists for Pros and Cons.
	 *
	 * @return string Modified content.
	 */
	public function pros_cons_bullets( $value, $key, $post_id ) {

		if ( ! in_array( $key, [ 'pros', 'contras' ] ) ) {
			return $value;
		}

		$items = explode( '-', $value );
		$list = '';
		foreach ( $items as $item ) {
			if ( empty( $item ) ) continue;
			$list .= sprintf( '<li>%s</li>', trim( $item ) );
		}

		$value = sprintf( '<ul>%s</ul>', $list );

		return $value;

	}

	/**
	 * Add columns for the Pros and Cons section.
	 *
	 * @return string Modified content.
	 */
	public function pros_cons_columns( $value, $key, $post_id ) {

		if ( 'pros' == $key ) {
			$value = sprintf(
				'<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2  class="pros">Pros</h2>
<!-- /wp:heading --><!-- wp:paragraph -->%s<!-- /wp:paragraph --></div>
<!-- /wp:column -->',
				$value
			);
		}

		if ( 'contras' == $key ) {
			$value = sprintf(
				'<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2 class="contras">Contras</h2>
<!-- /wp:heading --><!-- wp:paragraph -->%s<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->',
				$value
			);
		}

		return $value;

	}

}
