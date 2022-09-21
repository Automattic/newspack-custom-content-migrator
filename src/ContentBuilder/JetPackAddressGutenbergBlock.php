<?php

namespace NewspackCustomContentMigrator;

/**
 * @method string get_address_line_1()
 * @method string get_address_line_2()
 * @method string get_city()
 * @method string get_region()
 * @method string get_country()
 * @method string get_postal()
 * @method bool get_link_to_google_maps()
 * @method void set_address_line_1(string $address_line_1)
 * @method void set_address_line_2(string $address_line_2)
 * @method void set_city(string $city)
 * @method void set_region(string $region)
 * @method void set_country(string $country)
 * @method void set_postal(string $postal)
 * @method void set_link_to_google_maps(bool $link_to_google_maps)
 */
class JetPackAddressGutenbergBlock extends AbstractGutenbergDataBlock {

	protected string $address_line_1;

	protected string $address_line_2;

	protected string $city;

	protected string $region;

	protected string $country;

	protected string $postal;

	protected bool $link_to_google_maps = true;

	private function get_google_maps_search_url(): string {
		$url = 'https://www.google.com/maps/search/';
		$components = [];

		if ( ! empty( $this->get_address_line_1() ) ) {
			$components[] = str_replace( ' ', '+', $this->get_address_line_1() );
		}

		if ( ! empty( $this->get_address_line_2() ) ) {
			$components[] = str_replace( ' ', '+', $this->get_address_line_2() );
		}

		if ( ! empty( $this->get_city() ) ) {
			$components[] = '+' . str_replace( ' ', '+', $this->get_city() );
		}

		if ( ! empty( $this->get_region() ) ) {
			$components[] = '+' . str_replace( ' ', '+', $this->get_region() );
		}

		if ( ! empty( $this->get_country() ) ) {
			$components[] = '+' . str_replace( ' ', '+', $this->get_country() );
		}

		if ( ! empty( $this->get_postal() ) ) {
			$components[] = '+' . str_replace( ' ', '+', $this->get_postal() );
		}

		return $url . implode( ',', $components );
	}

	/**
	 * @inheritDoc
	 */
	public function jsonSerialize() {
		$properties = [];

		if ( isset( $this->address_line_1 ) ) {
			$properties['address'] = $this->address_line_1;
			$properties['linkToGoogleMaps'] = $this->link_to_google_maps;
		}

		if ( isset( $this->address_line_2 ) ) {
			$properties['addressLine2'] = $this->address_line_2;
		}

		if ( isset( $this->city ) ) {
			$properties['city'] = $this->city;
		}

		if ( isset( $this->region ) ) {
			$properties['region'] = $this->region;
		}

		if ( isset( $this->postal ) ) {
			$properties['postal'] = $this->postal;
		}

		if ( isset( $this->country ) ) {
			$properties['country'] = $this->country;
		}

		return $properties;
	}

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		$address_line_1 = $address_line_2 = $city = $region = $postal = '';

		if ( ! empty( $this->get_address_line_1() ) ) {
			$address_line_1 = '<div class="jetpack-address__address jetpack-address__address1">'
			                  . $this->get_address_line_1()
			                  . '</div>';
		}

		if ( ! empty( $this->get_address_line_2() ) ) {
			$address_line_2 = '<div class="jetpack-address__address jetpack-address__address2">'
			                  . $this->get_address_line_2()
			                  . '</div>';
		}

		$regional_section = '';

		if ( ! empty( $this->get_city() ) ) {
			$regional_section .= '<span class="jetpack-address__city">' . $this->get_city() . '</span>';
		}

		if ( ! empty( $this->get_region() ) ) {
			if ( ! empty( $regional_section ) ) {
				$regional_section .= ', ';
			}

			$regional_section .= '<span class="jetpack-address__region">' . $this->get_region() . '</span>';
		}

		if ( ! empty( $this->get_postal() ) ) {
			$regional_section .= '<span class="jetpack-address__postal">' . $this->get_postal() . '</span>';
		}

		if ( ! empty( $this->get_country() ) ) {
			$regional_section .= '<span class="jetpack-address__country">' . $this->get_country() . '</span>';
		}

		if ( ! empty( $regional_section ) ) {
			$regional_section = '<div>' . $regional_section . '</div>';
		}

		return '<!-- wp:jetpack/address ' . $this->get_json() . ' -->'
			. '<div class="wp-block-jetpack-address">'
		       . '<a '
		        . 'href="' . $this->get_google_maps_search_url() . '" '
		        . 'target="_blank" '
				. 'rel="noopener noreferrer" '
				. 'title="Open address in Google Maps"> '
					. $address_line_1
					. $address_line_2
		            . $regional_section
		       . '</a>'
			. '</div>'
			. '<!-- /wp:jetpack/address -->';
	}
}