<?php

namespace NewspackCustomContentMigrator;

interface GutenbergBlockInterface {

	/**
	 * This function should return the HTML representation of a Gutenberg Block.
	 *
	 * @return string
	 */
	public function __toString(): string;
}