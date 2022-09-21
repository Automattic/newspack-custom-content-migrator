<?php

namespace NewspackCustomContentMigrator;

use JsonSerializable;

class Coordinates implements JsonSerializable {

	protected float $longitude;

	protected float $latitude;

	public function __construct( float $longitude = 0.0, float $latitude = 0.0 ) {
		$this->set_longitude( $longitude );
		$this->set_latitude( $latitude );
	}

	public function set_longitude( float $longitude ) {
		$this->longitude = $longitude;
	}

	public function set_latitude( float $latitude ) {
		$this->latitude = $latitude;
	}

	public function get_longitude(): float {
		return $this->longitude;
	}

	public function get_latitude(): float {
		return $this->latitude;
	}

	public function __toString(): string {
		return json_encode( $this->jsonSerialize() );
	}

	public function jsonSerialize(): array {
		return [
			'longitude' => $this->get_longitude(),
			'latitude'  => $this->get_latitude(),
		];
	}
}