<?php

namespace NewspackCustomContentMigrator;

class AbbreviatedCoordinates extends Coordinates {
	
	public function jsonSerialize(): array {
		return [
			'lng' => $this->get_longitude(),
			'lat' => $this->get_latitude(),
		];
	}
}