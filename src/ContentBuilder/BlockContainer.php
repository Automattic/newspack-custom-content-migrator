<?php

namespace NewspackCustomContentMigrator;

use DOMNode;

class BlockContainer implements BlockContainerInterface {

	protected array $children = [];

	public function has_children(): bool {
		return ! empty( $this->children );
	}

	public function get_children(): array {
		return $this->children;
	}

	/**
	 * @param GutenbergBlockInterface|DOMNode $item
	 *
	 * @return $this
	 * @throws Exception
	 */
	public function add( $item ) {
		if ( ! ( $item instanceof GutenbergBlockInterface ) || ! ( $item instanceof DOMNode ) ) {
			throw new Exception( '$item is not of type ' . GutenbergBlockInterface::class . ' nor ' . DOMNode::class  );
		}

		return $this;
	}

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		foreach ( $this->children as $child ) {
			if ( $child->has_children() ) {

			}
		}
	}
}