<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\HtmlElementManipulator;
use \WP_CLI;

/**
 * Temp dev test.
 */
class DemoHTMLCrawlingAndReplacement implements InterfaceCommand {

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var
	 */
	private $html_manipulator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->html_manipulator = new HtmlElementManipulator();
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
			self::$instance->coauthorsplus_logic = new CoAuthorPlusLogic();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator html-demo-crawl-and-replace-element',
			[ $this, 'cmd_demo_crawl' ],
		);
	}

	public function cmd_demo_crawl( $args, $assoc_args ) {

		$html_not_working = <<<HTML
foo
<p>bar </p>
<div class="main">
	<div   class="target" >
		foo
	</div>
</div>

foo

<p>bar </p>

<div  class="target"> foo3</div>
HTML;

		$html_working = <<<HTML
foo
<p>bar </p>
<div class="main">
</div>
<div   class="target" >
	foo
</div>

foo

<p>bar </p>

<div  class="target"> foo3</div>
HTML;


		// Will make changes to $html_updated.
		$html_updated =  $html_working;

		// Match all `div.target` elements.
		$search_element = 'div';
		$search_class   = 'target';
		$matches = $this->html_manipulator->match_elements_with_closing_tags( $search_element, $html_updated );

		// Check if results are found.
		if ( isset( $matches[0] ) && ! empty( isset( $matches[0] ) ) ) {

			// Foreach result...
			foreach ( $matches[0] as $match ) {

				// ... get HTML (and strpos index if needed).
				$match_html = $match[0];
				$match_position = $match[1];

				// Check if desired class attribute is present (class="can have multiple target classes" so we use strpos, not `==`).
				$target_class_is_set = in_array( $search_class, explode( ' ', $this->html_manipulator->get_attribute_value( 'class', $match_html ) ) );

				// Do some replacement
				if ( $target_class_is_set ) {
					$replacement_html = '<p>replace with this</p>';
					$html_updated = str_replace( $match_html, $replacement_html, $html_updated );
				}
			}
		}

		// If changes were made, save.
		if ( $html_working != $html_updated ) {
			// Persist.
		}

	}
}
