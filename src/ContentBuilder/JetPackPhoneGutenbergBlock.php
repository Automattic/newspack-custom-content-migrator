<?php

namespace NewspackCustomContentMigrator;

/**
 * @method string get_phone()
 */
class JetPackPhoneGutenbergBlock extends AbstractGutenbergDataBlock {

	protected string $phone;

	private string $phone_numbers_only;

	public function set_phone( string $phone ) {
		$this->phone = $phone;
		$this->phone_numbers_only = preg_replace( '/[\D]/', '', $phone );
	}

	private function get_phone_numbers_only(): string {
		return $this->phone_numbers_only;
	}

	/**
	 * @inheritDoc
	 */
	public function jsonSerialize() {
		return [
			'phone' => $this->phone
		];
	}

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		return '<!-- wp:jetpack/phone ' . $this->get_json() . ' -->'
			. '<div class="wp-block-jetpack-phone">'
				. '<a href="tel:' . $this->get_phone_numbers_only() . '">' . $this->get_phone() . '</a>'
			. '</div>'
			. '<!-- /wp:jetpack/email -->';
	}
}