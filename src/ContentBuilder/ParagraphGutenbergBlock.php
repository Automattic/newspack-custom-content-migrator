<?php

namespace NewspackCustomContentMigrator;

/**
 * @method string get_content()
 */
class ParagraphGutenbergBlock extends AbstractGutenbergBlock {

	/**
	 * Paragraph contents.
	 *
	 * @var string $content
	 */
	protected string $content = '';

	/**
	 * Setter for paragraph contents.
	 *
	 * @param string $content Paragraph contents.
	 */
	public function set_content( string $content ) {
		$this->content = $content;

		if ( str_starts_with( $this->content, '<p>' ) ) {
			$this->content = substr( $this->content, 3 );
		}

		if ( str_ends_with( $this->content, '</p>' ) ) {
			$this->content = substr( $this->content, -4 );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		return '<!-- wp:paragraph -->'
			. '<p>' . $this->get_content()
			. '</p>'
			. '<!-- /wp:paragraph -->';
	}
}