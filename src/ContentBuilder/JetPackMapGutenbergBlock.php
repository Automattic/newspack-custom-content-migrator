<?php

namespace NewspackCustomContentMigrator;

use Exception;

/**
 * JetPack Map Gutenberg Block.
 *
 * @method string      get_title()
 * @method string      get_place_title()
 * @method string      get_caption()
 * @method string      get_id()
 * @method int         get_zoom()
 * @method array       get_data_map_styles()
 * @method string      get_data_map_style()
 * @method bool        get_data_map_details()
 * @method string      get_data_marker_color()
 * @method string      get_data_marker_colors()
 * @method bool        get_data_show_fullscreen_button()
 * @method void        set_place_title(string $title)
 * @method void        set_caption(string $caption)
 * @method void        set_id(string $id)
 * @method void        set_zoom(int $zoom)
 * @method void        set_data_map_details(bool $data_map_details)
 * @method void        set_data_show_fullscreen_button(bool $data_show_fullscreen_button)
 * @method void        set_coordinates( Coordinates $coordinates )
 * @method void        set_coordinates( float $longitude, float $latitude )
 * @method Coordinates get_coordinates()
 */
class JetPackMapGutenbergBlock extends AbstractGutenbergDataBlock {
	protected ?string $title;

	protected ?string $place_title;

	protected ?string $caption;

	protected ?string $id;

	protected ?int $zoom;

	protected string $data_map_style = 'default';

	protected array $data_map_styles = [
		'default',
		'fullsize'
	];

	protected bool $data_map_details = true;

	protected string $data_marker_color = 'red';

	protected array $data_marker_colors = [
		'red',
	];

	protected bool $data_show_fullscreen_button = true;

	protected Coordinates $coordinates;

	private string $access_token;

	private $mapbox_url_template = 'https://api.mapbox.com/geocoding/v5/mapbox.places/{longitude},{latitude}.json?types=poi&access_token={access_token}';

	public function __construct() {
		$this->coordinates = new Coordinates();
	}

	// Could have magic getters and setters
	// If setter/getter function exists, override

	public function set_title( string $title ) {
		$this->title = $title;

		if ( ! isset( $this->place_title ) ) {
			$this->place_title = $title;
		}
	}

	/**
	 * Setter for data-map-style attribute.
	 *
	 * @param string $style data-map-style style.
	 *
	 * @throws Exception
	 */
	public function set_data_map_style( string $style ) {
		if ( ! in_array( $style, $this->get_data_map_styles() ) ) {
			throw new Exception( "Unsupported data-map-style value: $style" );
		}

		$this->data_map_style = $style;
	}

	/**
	 * @param string $color
	 *
	 * @throws Exception
	 */
	public function set_data_marker_colors( string $color ) {
		if ( ! in_array( $color, $this->get_data_marker_colors() ) ) {
			throw new \Exception( "Unsupported data-marker-color value: $color" );
		}

		$this->data_marker_color = $color;
	}

	public function set_access_token( string $access_token ) {
		$this->access_token = $access_token;
	}

	/**
	 * @return string|null
	 * @throws Exception
	 */
	public function get_id() {
		if ( ! isset( $this->id ) ) {
			if ( ! isset( $this->access_token ) ) {
				throw new \Exception( 'The Mapbox Access Token has not been set. Cannot generate JetPack Map Block.' );
			}

			$endpoint = strtr(
				$this->mapbox_url_template,
				[
					'{longitude}' => $this->get_coordinates()->get_longitude(),
					'{latitude}' => $this->get_coordinates()->get_latitude(),
					'{access_token}' => $this->access_token,
				]
			);

			$response = wp_remote_get( $endpoint );
			$body = wp_remote_retrieve_body( $response );
			$json = json_decode( $body, true );
			$this->id = $json['features'][0]['id'] ?? 'UNABLE_TO_RETRIEVE_ID';
		}

		return $this->id;
	}

	public function get_data_map_center():string {
		return $this->get_abbreviated_coordinates()->__toString();
	}

	public function get_map_url(): string {
		return 'https://www.google.com/maps/search/?api=1&query='
		       . $this->get_coordinates()->get_latitude()
		       . ','
		       . $this->get_coordinates()->get_longitude();
	}

	public function get_abbreviated_coordinates(): ?AbbreviatedCoordinates {
		return new AbbreviatedCoordinates( $this->get_coordinates()->get_longitude(), $this->get_coordinates()->get_latitude() );
	}

	/**
	 * Should return string representation of JSON object.
	 *
	 * @return array
	 */
	public function jsonSerialize(): array {
		$properties = [];

		if ( ! is_null( $this->get_title() ) ) {
			$properties['title'] = $this->get_title();
		}

		if ( ! is_null( $this->get_place_title() ) ) {
			$properties['placeTitle'] = $this->get_place_title();
		}

		if ( ! is_null( $this->get_caption() ) ) {
			$properties['caption'] = $this->get_caption();
		}

		if ( ! is_null( $this->get_id() ) ) {
			$properties['id'] = $this->get_id();
		}

		if ( ! is_null( $this->get_zoom() ) ) {
			$properties['zoom'] = $this->get_zoom();
		}

		if ( ! is_null( $this->get_coordinates() ) ) {
			$properties['coordinates'] = $this->get_coordinates();
			$properties['mapCenter'] = $this->get_abbreviated_coordinates();
		}

		return $properties;
	}

	public function __toString(): string {
		$data_map_details            = $this->get_data_map_details() ? 'true' : 'false';
		$data_show_fullscreen_button = $this->get_data_show_fullscreen_button() ? 'true' : 'false';

		$data_zoom = '';
		if ( ! is_null( $this->get_zoom() ) ) {
			$data_zoom = 'data-zoom="' . $this->get_zoom() . '" ';
		}

		$points = $this->get_json();
		$data_points = $this->jsonSerialize();
		$map_center = $this->get_abbreviated_coordinates();
		$data_points = wp_json_encode( $data_points );

		return '<!-- wp:jetpack/map {"points":[' . $points . '],"mapCenter":' . $map_center . '} -->'
			   . '<div class="wp-block-jetpack-map" '
				. 'data-map-style="' . $this->get_data_map_style() . '" '
				. 'data-map-details="' . $data_map_details . '" '
				. 'data-points="[' . htmlentities( $data_points, ENT_QUOTES ) . ']" '
				. $data_zoom
				. 'data-map-center="' . htmlentities( $this->get_data_map_center(), ENT_QUOTES ) . '" '
				. 'data-marker-color="' . $this->get_data_marker_color() . '" '
				. 'data-show-fullscreen-button="' . $data_show_fullscreen_button . '">'
					. '<ul>'
						. '<li>'
							. '<a '
							 . 'href="' . htmlspecialchars( $this->get_map_url() ). '">'
								. $this->get_title()
							. '</a>'
						. '</li>'
					. '</ul>'
			   . '</div>'
			. '<!-- /wp:jetpack/map -->';
	}

	public function __call( string $name, array $arguments ) {
		switch ( $name ) {
			case 'set_coordinates':
				if ( count( $arguments ) > 1 ) {
					$arguments = array_filter( $arguments, fn( $argument) => is_float( $argument ) );

					$this->set_coordinate_individual( $arguments[0], $arguments[1] );
				} else {
					if ( is_array( $arguments[0] ) ) {
						$this->set_coordinate_individual( $arguments[0]['longitude'], $arguments[0]['latitude'] );
					} else {
						$this->set_coordinates_object( $arguments[0] );
					}
				}
				break;
			default:
				return parent::__call( $name, $arguments );
		}
	}

	private function set_coordinates_object( Coordinates $coordinates ) {
		$this->coordinates = $coordinates;
	}

	private function set_coordinate_individual( float $longitude, float $latitude ) {
		$this->coordinates = new Coordinates( $longitude, $latitude );
	}
}
