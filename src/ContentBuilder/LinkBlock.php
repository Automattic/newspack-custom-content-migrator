<?php

namespace NewspackCustomContentMigrator;

class LinkBlock extends AbstractGutenbergBlock {

	protected string $href;

	protected string $target = '';

	protected array $targets = [
		'_blank'
	];

	protected array $rel = [];

	protected string $title = '';

	protected array $inner_blocks = [];

	/**
	 * @inheritDoc
	 */
	public function __toString(): string {
		// TODO: Implement __toString() method.
	}
}