<?php
/**
 * This is a class to output colored text to the console.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Utils;

use cli\Colors;

/**
 * This is a class to output colored text to the console.
 *
 * @package NewspackCustomContentMigrator
 *
 * @method static ConsoleColor yellow( string $string )
 * @method static ConsoleColor green( string $string )
 * @method static ConsoleColor blue( string $string )
 * @method static ConsoleColor red( string $string )
 * @method static ConsoleColor magenta( string $string )
 * @method static ConsoleColor cyan( string $string )
 * @method static ConsoleColor white( string $string )
 * @method static ConsoleColor black( string $string )
 * @method static ConsoleColor yellow_with_green_background( string $string )
 * @method static ConsoleColor yellow_with_blue_background( string $string )
 * @method static ConsoleColor yellow_with_red_background( string $string )
 * @method static ConsoleColor yellow_with_magenta_background( string $string )
 * @method static ConsoleColor yellow_with_cyan_background( string $string )
 * @method static ConsoleColor yellow_with_white_background( string $string )
 * @method static ConsoleColor yellow_with_black_background( string $string )
 * @method static ConsoleColor green_with_yellow_background( string $string )
 * @method static ConsoleColor green_with_blue_background( string $string )
 * @method static ConsoleColor green_with_red_background( string $string )
 * @method static ConsoleColor green_with_magenta_background( string $string )
 * @method static ConsoleColor green_with_cyan_background( string $string )
 * @method static ConsoleColor green_with_white_background( string $string )
 * @method static ConsoleColor green_with_black_background( string $string )
 * @method static ConsoleColor blue_with_yellow_background( string $string )
 * @method static ConsoleColor blue_with_green_background( string $string )
 * @method static ConsoleColor blue_with_red_background( string $string )
 * @method static ConsoleColor blue_with_magenta_background( string $string )
 * @method static ConsoleColor blue_with_cyan_background( string $string )
 * @method static ConsoleColor blue_with_white_background( string $string )
 * @method static ConsoleColor blue_with_black_background( string $string )
 * @method static ConsoleColor red_with_yellow_background( string $string )
 * @method static ConsoleColor red_with_green_background( string $string )
 * @method static ConsoleColor red_with_blue_background( string $string )
 * @method static ConsoleColor red_with_magenta_background( string $string )
 * @method static ConsoleColor red_with_cyan_background( string $string )
 * @method static ConsoleColor red_with_white_background( string $string )
 * @method static ConsoleColor red_with_black_background( string $string )
 * @method static ConsoleColor magenta_with_yellow_background( string $string )
 * @method static ConsoleColor magenta_with_green_background( string $string )
 * @method static ConsoleColor magenta_with_blue_background( string $string )
 * @method static ConsoleColor magenta_with_red_background( string $string )
 * @method static ConsoleColor magenta_with_cyan_background( string $string )
 * @method static ConsoleColor magenta_with_white_background( string $string )
 * @method static ConsoleColor magenta_with_black_background( string $string )
 * @method static ConsoleColor cyan_with_yellow_background( string $string )
 * @method static ConsoleColor cyan_with_green_background( string $string )
 * @method static ConsoleColor cyan_with_blue_background( string $string )
 * @method static ConsoleColor cyan_with_red_background( string $string )
 * @method static ConsoleColor cyan_with_magenta_background( string $string )
 * @method static ConsoleColor cyan_with_white_background( string $string )
 * @method static ConsoleColor cyan_with_black_background( string $string )
 * @method static ConsoleColor white_with_yellow_background( string $string )
 * @method static ConsoleColor white_with_green_background( string $string )
 * @method static ConsoleColor white_with_blue_background( string $string )
 * @method static ConsoleColor white_with_red_background( string $string )
 * @method static ConsoleColor white_with_magenta_background( string $string )
 * @method static ConsoleColor white_with_cyan_background( string $string )
 * @method static ConsoleColor white_with_black_background( string $string )
 * @method static ConsoleColor black_with_yellow_background( string $string )
 * @method static ConsoleColor black_with_green_background( string $string )
 * @method static ConsoleColor black_with_blue_background( string $string )
 * @method static ConsoleColor black_with_red_background( string $string )
 * @method static ConsoleColor black_with_magenta_background( string $string )
 * @method static ConsoleColor black_with_cyan_background( string $string )
 * @method static ConsoleColor black_with_white_background( string $string )
 * @method static ConsoleColor bright_yellow( string $string )
 * @method static ConsoleColor bright_green( string $string )
 * @method static ConsoleColor bright_blue( string $string )
 * @method static ConsoleColor bright_red( string $string )
 * @method static ConsoleColor bright_magenta( string $string )
 * @method static ConsoleColor bright_cyan( string $string )
 * @method static ConsoleColor bright_white( string $string )
 * @method static ConsoleColor bright_black( string $string )
 * @method static ConsoleColor bright_yellow_with_green_background( string $string )
 * @method static ConsoleColor bright_yellow_with_blue_background( string $string )
 * @method static ConsoleColor bright_yellow_with_red_background( string $string )
 * @method static ConsoleColor bright_yellow_with_magenta_background( string $string )
 * @method static ConsoleColor bright_yellow_with_cyan_background( string $string )
 * @method static ConsoleColor bright_yellow_with_white_background( string $string )
 * @method static ConsoleColor bright_yellow_with_black_background( string $string )
 * @method static ConsoleColor bright_green_with_yellow_background( string $string )
 * @method static ConsoleColor bright_green_with_blue_background( string $string )
 * @method static ConsoleColor bright_green_with_red_background( string $string )
 * @method static ConsoleColor bright_green_with_magenta_background( string $string )
 * @method static ConsoleColor bright_green_with_cyan_background( string $string )
 * @method static ConsoleColor bright_green_with_white_background( string $string )
 * @method static ConsoleColor bright_green_with_black_background( string $string )
 * @method static ConsoleColor bright_blue_with_yellow_background( string $string )
 * @method static ConsoleColor bright_blue_with_green_background( string $string )
 * @method static ConsoleColor bright_blue_with_red_background( string $string )
 * @method static ConsoleColor bright_blue_with_magenta_background( string $string )
 * @method static ConsoleColor bright_blue_with_cyan_background( string $string )
 * @method static ConsoleColor bright_blue_with_white_background( string $string )
 * @method static ConsoleColor bright_blue_with_black_background( string $string )
 * @method static ConsoleColor bright_red_with_yellow_background( string $string )
 * @method static ConsoleColor bright_red_with_green_background( string $string )
 * @method static ConsoleColor bright_red_with_blue_background( string $string )
 * @method static ConsoleColor bright_red_with_magenta_background( string $string )
 * @method static ConsoleColor bright_red_with_cyan_background( string $string )
 * @method static ConsoleColor bright_red_with_white_background( string $string )
 * @method static ConsoleColor bright_red_with_black_background( string $string )
 * @method static ConsoleColor bright_magenta_with_yellow_background( string $string )
 * @method static ConsoleColor bright_magenta_with_green_background( string $string )
 * @method static ConsoleColor bright_magenta_with_blue_background( string $string )
 * @method static ConsoleColor bright_magenta_with_red_background( string $string )
 * @method static ConsoleColor bright_magenta_with_cyan_background( string $string )
 * @method static ConsoleColor bright_magenta_with_white_background( string $string )
 * @method static ConsoleColor bright_magenta_with_black_background( string $string )
 * @method static ConsoleColor bright_cyan_with_yellow_background( string $string )
 * @method static ConsoleColor bright_cyan_with_green_background( string $string )
 * @method static ConsoleColor bright_cyan_with_blue_background( string $string )
 * @method static ConsoleColor bright_cyan_with_red_background( string $string )
 * @method static ConsoleColor bright_cyan_with_magenta_background( string $string )
 * @method static ConsoleColor bright_cyan_with_white_background( string $string )
 * @method static ConsoleColor bright_cyan_with_black_background( string $string )
 * @method static ConsoleColor bright_white_with_yellow_background( string $string )
 * @method static ConsoleColor bright_white_with_green_background( string $string )
 * @method static ConsoleColor bright_white_with_blue_background( string $string )
 * @method static ConsoleColor bright_white_with_red_background( string $string )
 * @method static ConsoleColor bright_white_with_magenta_background( string $string )
 * @method static ConsoleColor bright_white_with_cyan_background( string $string )
 * @method static ConsoleColor bright_white_with_black_background( string $string )
 * @method static ConsoleColor bright_black_with_yellow_background( string $string )
 * @method static ConsoleColor bright_black_with_green_background( string $string )
 * @method static ConsoleColor bright_black_with_blue_background( string $string )
 * @method static ConsoleColor bright_black_with_red_background( string $string )
 * @method static ConsoleColor bright_black_with_magenta_background( string $string )
 * @method static ConsoleColor bright_black_with_cyan_background( string $string )
 * @method static ConsoleColor bright_black_with_white_background( string $string )
 * @method static ConsoleColor underlined( string $string )
 * @method static ConsoleColor underlined_yellow( string $string )
 * @method static ConsoleColor underlined_green( string $string )
 * @method static ConsoleColor underlined_blue( string $string )
 * @method static ConsoleColor underlined_red( string $string )
 * @method static ConsoleColor underlined_magenta( string $string )
 * @method static ConsoleColor underlined_cyan( string $string )
 * @method static ConsoleColor underlined_white( string $string )
 * @method static ConsoleColor underlined_black( string $string )
 * @method static ConsoleColor underlined_bright_yellow( string $string )
 * @method static ConsoleColor underlined_bright_green( string $string )
 * @method static ConsoleColor underlined_bright_blue( string $string )
 * @method static ConsoleColor underlined_bright_red( string $string )
 * @method static ConsoleColor underlined_bright_magenta( string $string )
 * @method static ConsoleColor underlined_bright_cyan( string $string )
 * @method static ConsoleColor underlined_bright_white( string $string )
 * @method static ConsoleColor underlined_bright_black( string $string )
 * @method static ConsoleColor underlined_yellow_with_green_background( string $string )
 * @method static ConsoleColor underlined_yellow_with_blue_background( string $string )
 * @method static ConsoleColor underlined_yellow_with_red_background( string $string )
 * @method static ConsoleColor underlined_yellow_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_yellow_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_yellow_with_white_background( string $string )
 * @method static ConsoleColor underlined_yellow_with_black_background( string $string )
 * @method static ConsoleColor underlined_green_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_green_with_blue_background( string $string )
 * @method static ConsoleColor underlined_green_with_red_background( string $string )
 * @method static ConsoleColor underlined_green_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_green_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_green_with_white_background( string $string )
 * @method static ConsoleColor underlined_green_with_black_background( string $string )
 * @method static ConsoleColor underlined_blue_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_blue_with_green_background( string $string )
 * @method static ConsoleColor underlined_blue_with_red_background( string $string )
 * @method static ConsoleColor underlined_blue_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_blue_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_blue_with_white_background( string $string )
 * @method static ConsoleColor underlined_blue_with_black_background( string $string )
 * @method static ConsoleColor underlined_red_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_red_with_green_background( string $string )
 * @method static ConsoleColor underlined_red_with_blue_background( string $string )
 * @method static ConsoleColor underlined_red_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_red_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_red_with_white_background( string $string )
 * @method static ConsoleColor underlined_red_with_black_background( string $string )
 * @method static ConsoleColor underlined_magenta_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_magenta_with_green_background( string $string )
 * @method static ConsoleColor underlined_magenta_with_blue_background( string $string )
 * @method static ConsoleColor underlined_magenta_with_red_background( string $string )
 * @method static ConsoleColor underlined_magenta_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_magenta_with_white_background( string $string )
 * @method static ConsoleColor underlined_magenta_with_black_background( string $string )
 * @method static ConsoleColor underlined_cyan_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_cyan_with_green_background( string $string )
 * @method static ConsoleColor underlined_cyan_with_blue_background( string $string )
 * @method static ConsoleColor underlined_cyan_with_red_background( string $string )
 * @method static ConsoleColor underlined_cyan_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_cyan_with_white_background( string $string )
 * @method static ConsoleColor underlined_cyan_with_black_background( string $string )
 * @method static ConsoleColor underlined_white_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_white_with_green_background( string $string )
 * @method static ConsoleColor underlined_white_with_blue_background( string $string )
 * @method static ConsoleColor underlined_white_with_red_background( string $string )
 * @method static ConsoleColor underlined_white_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_white_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_white_with_black_background( string $string )
 * @method static ConsoleColor underlined_black_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_black_with_green_background( string $string )
 * @method static ConsoleColor underlined_black_with_blue_background( string $string )
 * @method static ConsoleColor underlined_black_with_red_background( string $string )
 * @method static ConsoleColor underlined_black_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_black_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_black_with_white_background( string $string )
 * @method static ConsoleColor underlined_bright_yellow_with_green_background( string $string )
 * @method static ConsoleColor underlined_bright_yellow_with_blue_background( string $string )
 * @method static ConsoleColor underlined_bright_yellow_with_red_background( string $string )
 * @method static ConsoleColor underlined_bright_yellow_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_bright_yellow_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_bright_yellow_with_white_background( string $string )
 * @method static ConsoleColor underlined_bright_yellow_with_black_background( string $string )
 * @method static ConsoleColor underlined_bright_green_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_bright_green_with_blue_background( string $string )
 * @method static ConsoleColor underlined_bright_green_with_red_background( string $string )
 * @method static ConsoleColor underlined_bright_green_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_bright_green_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_bright_green_with_white_background( string $string )
 * @method static ConsoleColor underlined_bright_green_with_black_background( string $string )
 * @method static ConsoleColor underlined_bright_blue_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_bright_blue_with_green_background( string $string )
 * @method static ConsoleColor underlined_bright_blue_with_red_background( string $string )
 * @method static ConsoleColor underlined_bright_blue_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_bright_blue_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_bright_blue_with_white_background( string $string )
 * @method static ConsoleColor underlined_bright_blue_with_black_background( string $string )
 * @method static ConsoleColor underlined_bright_red_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_bright_red_with_green_background( string $string )
 * @method static ConsoleColor underlined_bright_red_with_blue_background( string $string )
 * @method static ConsoleColor underlined_bright_red_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_bright_red_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_bright_red_with_white_background( string $string )
 * @method static ConsoleColor underlined_bright_red_with_black_background( string $string )
 * @method static ConsoleColor underlined_bright_magenta_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_bright_magenta_with_green_background( string $string )
 * @method static ConsoleColor underlined_bright_magenta_with_blue_background( string $string )
 * @method static ConsoleColor underlined_bright_magenta_with_red_background( string $string )
 * @method static ConsoleColor underlined_bright_magenta_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_bright_magenta_with_white_background( string $string )
 * @method static ConsoleColor underlined_bright_magenta_with_black_background( string $string )
 * @method static ConsoleColor underlined_bright_cyan_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_bright_cyan_with_green_background( string $string )
 * @method static ConsoleColor underlined_bright_cyan_with_blue_background( string $string )
 * @method static ConsoleColor underlined_bright_cyan_with_red_background( string $string )
 * @method static ConsoleColor underlined_bright_cyan_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_bright_cyan_with_white_background( string $string )
 * @method static ConsoleColor underlined_bright_cyan_with_black_background( string $string )
 * @method static ConsoleColor underlined_bright_white_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_bright_white_with_green_background( string $string )
 * @method static ConsoleColor underlined_bright_white_with_blue_background( string $string )
 * @method static ConsoleColor underlined_bright_white_with_red_background( string $string )
 * @method static ConsoleColor underlined_bright_white_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_bright_white_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_bright_white_with_black_background( string $string )
 * @method static ConsoleColor underlined_bright_black_with_yellow_background( string $string )
 * @method static ConsoleColor underlined_bright_black_with_green_background( string $string )
 * @method static ConsoleColor underlined_bright_black_with_blue_background( string $string )
 * @method static ConsoleColor underlined_bright_black_with_red_background( string $string )
 * @method static ConsoleColor underlined_bright_black_with_magenta_background( string $string )
 * @method static ConsoleColor underlined_bright_black_with_cyan_background( string $string )
 * @method static ConsoleColor underlined_bright_black_with_white_background( string $string )
 */
class ConsoleColor {

	/**
	 * The colors available to use.
	 *
	 * @var string[] $colors An array of color codes.
	 */
	private array $colors = [
		'yellow'  => '%y',
		'green'   => '%g',
		'blue'    => '%b',
		'red'     => '%r',
		'magenta' => '%p',
		'cyan'    => '%c',
		'white'   => '%w',
		'black'   => '%k',
	];

	/**
	 * The background colors available to use.
	 *
	 * @var string[] $backgrounds An array of background color codes.
	 */
	private array $backgrounds = [
		'yellow'  => '%3',
		'green'   => '%2',
		'blue'    => '%4',
		'red'     => '%1',
		'magenta' => '%5',
		'cyan'    => '%6',
		'white'   => '%7',
		'black'   => '%0',
	];

	/**
	 * The styles available to use.
	 *
	 * @var string[] $styles An array of style codes.
	 */
	private array $styles = [
		'bright'    => '%9',
		'underline' => '%U',
	];

	/**
	 * The replacements to make to the method names.
	 *
	 * @var string[] $replacements An array of replacements to make to the method names.
	 */
	private array $replacements = [
		'underlined' => 'underline',
		'_with_'     => '_', // This is a special case.
	];

	/**
	 * An array of strings with color, style, or background applied.
	 *
	 * @var array $strings An array of strings with color, style, or background applied.
	 */
	private array $strings = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * This magic method dynamically calls functions which apply color, style, or background to a string.
	 *
	 * @param string $name The name of the method being called.
	 * @param array  $arguments The arguments passed to the method.
	 *
	 * @return string|ConsoleColor
	 */
	public function __call( $name, $arguments ) {
		$name   = strtr( $name, $this->replacements );
		$string = $arguments[0];

		$particles = explode( '_', $name );

		$background_found = array_search( 'background', $particles, true );

		if ( false !== $background_found ) {
			// $background_found corresponds to the array index of the background text.
			$string = $this->background( $particles[ $background_found - 1 ], $string );
			unset( $particles[ $background_found ] );
			unset( $particles[ $background_found - 1 ] );
		}

		// All remaining particles are either foreground or style.
		foreach ( $particles as $particle ) {
			if ( array_key_exists( $particle, $this->colors ) ) {
				$string = $this->foreground( $particle, $string );
			} elseif ( array_key_exists( $particle, $this->styles ) ) {
				$string = $this->style( $particle, $string );
			}
		}

		$this->strings[] = $string;

		return $this;
	}

	/**
	 * This magic method dynamically calls functions which apply color, style, or background to a string, from
	 * a static context.
	 *
	 * @param string $name The name of the method being called.
	 * @param array  $arguments The arguments passed to the method.
	 *
	 * @return string|ConsoleColor
	 */
	public static function __callStatic( $name, $arguments ) {
		$self = new self();

		return $self->__call( $name, $arguments );
	}

	/**
	 * Returns the final string/text with color, style, or background applied.
	 *
	 * @return string
	 */
	public function get(): string {
		$text          = Colors::colorize( implode( ' ', $this->strings ) );
		$this->strings = [];

		return $text;
	}

	/**
	 * Displays the final string/text with color, style, or background applied to console.
	 *
	 * @return void
	 */
	public function output() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->get() . "\n";
	}

	/**
	 * This function applies a color to a string.
	 *
	 * @param string $color The color to apply.
	 * @param string $text The string to apply the color to.
	 *
	 * @return string
	 */
	protected function foreground( string $color, string $text ): string {
		return $this->apply( 'colors', $color, $text );
	}

	/**
	 * This function applies a style to a string.
	 *
	 * @param string $style The style to apply.
	 * @param string $text The string to apply the style to.
	 *
	 * @return string
	 */
	protected function style( string $style, string $text ): string {
		return $this->apply( 'styles', $style, $text );
	}

	/**
	 * This function applies a background color to a string.
	 *
	 * @param string $background The background color to apply.
	 * @param string $text The string to apply the background color to.
	 *
	 * @return string
	 */
	protected function background( string $background, string $text ): string {
		return $this->apply( 'backgrounds', $background, $text );
	}

	/**
	 * This function applies a color, style, or background to a string.
	 *
	 * @param string $type Whether to apply a color, style, or background.
	 * @param string $property The color, style, or background to apply.
	 * @param string $text The string to apply the color, style, or background to.
	 *
	 * @return string
	 */
	private function apply( string $type, string $property, string $text ): string {
		$text_sanitized = wp_kses( $text, wp_kses_allowed_html( 'post' ) );

		if ( array_key_exists( $property, $this->$type ) ) {
			$text_sanitized = $this->$type[ $property ] . $text_sanitized . '%n';
		}

		return $text_sanitized;
	}
}
