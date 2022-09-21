<?php

namespace NewspackCustomContentMigrator;

class JetPackContactInfoGutenbergBlock extends AbstractGutenbergBlock {

	protected ?JetPackEmailGutenbergBlock $email;

	protected ?JetPackPhoneGutenbergBlock $phone;

	protected ?JetPackAddressGutenbergBlock $address;

	public function set_email( string $email ) {
		$this->email = new JetPackEmailGutenbergBlock( [ 'email' => $email ] );
	}

	public function get_email(): ?string {
		if ( isset( $this->email ) ) {
			return $this->email->get_email();
		}

		return '';
	}

	public function set_phone( string $phone ) {
		$this->phone = new JetPackPhoneGutenbergBlock( [ 'phone' => $phone ] );
	}

	public function get_phone(): ?string {
		if ( isset( $this->phone ) ) {
			return $this->phone->get_phone();
		}

		return '';
	}

	public function set_address( string $address_line_1 = '', string $address_line_2 = '', string $city = '', string $region = '', string $postal = '', bool $link_to_google_maps = true ) {
		$this->address = new JetPackAddressGutenbergBlock(
			[
				'address_line_1' => $address_line_1,
				'address_line_2' => $address_line_2,
				'city' => $city,
				'region' => $region,
				'postal' => $postal,
				'link_to_google_maps' => $link_to_google_maps,
			]
		);
	}

	public function get_address(): array {
		if ( isset( $this->address ) ) {
			return $this->address->jsonSerialize();
		}

		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		$email = '';

		if ( isset( $this->email ) ) {
			$email = $this->email->__toString();
		}

		$phone = '';

		if ( isset( $this->phone ) ) {
			$phone = $this->phone->__toString();
		}

		$address = '';

		if ( isset( $this->address ) ) {
			$address = $this->address->__toString();
		}

		return '<!-- wp:jetpack/contact-info -->'
		        . '<div class="wp-block-jetpack-contact-info">'
					. $email
		            . $phone
		            . $address
		        . '</div>'
			. '<!-- /wp:jetpack/contact-info -->';
	}
}