<?php

namespace NewspackCustomContentMigrator;

use DOMNode;

interface BlockContainerInterface extends GutenbergBlockInterface {

	public function has_children(): bool;

	public function get_children(): array;

	/**
	 * @param GutenbergBlockInterface|DOMNode $item Item to be added to block tree.
	 *
	 * @return BlockContainerInterface
	 */
	public function add( $item ): BlockContainerInterface;
}