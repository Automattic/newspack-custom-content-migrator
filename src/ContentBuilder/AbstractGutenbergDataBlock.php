<?php

namespace NewspackCustomContentMigrator;

/**
 * Abstract class that establishes the need to return a JSON data object
 * for inclusion in a Gutenberg Block.
 */
abstract class AbstractGutenbergDataBlock extends AbstractGutenbergBlock implements GutenbergDataBlockInterface {

	public function get_json(): string {
		return wp_json_encode( $this->jsonSerialize() );
	}
}