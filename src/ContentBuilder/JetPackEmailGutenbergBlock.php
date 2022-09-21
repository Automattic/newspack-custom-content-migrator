<?php

namespace NewspackCustomContentMigrator;

/**
 * @method string get_email()
 * @method void set_email(string $email)
 */
class JetPackEmailGutenbergBlock extends AbstractGutenbergDataBlock {

	protected string $email;


	/**
	 * @inheritDoc
	 */
	public function jsonSerialize() {
		return [
			'email' => $this->email,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		return '<!-- wp:jetpack/email ' . $this->get_json() . ' -->'
			. '<div class="wp-block-jetpack-email">'
				. '<a href="mailto:' . $this->get_email() . '">' . $this->get_email() . '</a>'
			. '</div>'
			. '<!-- /wp:jetpack/email -->';
	}
}