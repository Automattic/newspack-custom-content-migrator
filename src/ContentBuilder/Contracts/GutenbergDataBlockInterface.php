<?php

namespace NewspackCustomContentMigrator;

use JsonSerializable;

interface GutenbergDataBlockInterface extends GutenbergBlockInterface, JsonSerializable {
	/**
	 * This function should return any class properties that could represent
	 * the object as a json string.
	 *
	 * @return string
	 */
	public function get_json(): string;
}