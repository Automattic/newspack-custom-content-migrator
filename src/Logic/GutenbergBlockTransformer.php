<?php

namespace NewspackCustomContentMigrator\Logic;

use WP_CLI;

class GutenbergBlockTransformer {

	private GutenbergBlockGenerator $block_generator;

	private function __construct() {
		$this->block_generator = new GutenbergBlockGenerator();
	}

	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	public function encode_post_content( string $post_content ): string {
		$blocks        = parse_blocks( $post_content );
		$actual_blocks = array_filter( $blocks, fn( $block ) => ! empty( $block['blockName'] ) && ! str_contains( $block['innerHTML'], '[BLOCK-TRANSFORMER:' ) );

		if ( empty( $actual_blocks ) ) {
			return $post_content;
		}

		$encoded_blocks = array_map( fn( $block ) => $this->encode_block( $block ), $actual_blocks );
		foreach ( $encoded_blocks as $idx => $encoded ) {
			$blocks[ $idx ] = $encoded;
		}

		return serialize_blocks( $blocks );
	}

	public function encode_block( array $block ): array {
		$as_string = serialize_block( $block );

		$anchor = '[BLOCK-TRANSFORMER:' . base64_encode( $as_string ) . ']';

		return $this->block_generator->get_paragraph( $anchor );// Can't use class names - NCC strips them.
	}

	public function decode_post_content( string $post_content ): string {
		$blocks         = parse_blocks( $post_content );
		$encoded_blocks = array_filter( $blocks, fn( $block ) => str_contains( $block['innerHTML'], '[BLOCK-TRANSFORMER:' ) );

		if ( empty( $encoded_blocks ) ) {
			return $post_content;
		}
		foreach ( $encoded_blocks as $idx => $encoded ) {
			$decoded = $this->decode_block( $encoded['innerHTML'] );
			if ( ! empty( $decoded ) ) {
				$blocks[ $idx ] = $decoded;
			} else {
				WP_CLI::log( sprintf( 'Failed to decode block %d', $idx ) );
			}
		}

		return serialize_blocks( $blocks );
	}

	public function decode_block( string $encoded_block ): array {
		// See https://base64.guru/learn/base64-characters for chars in base64.
		preg_match( '/\[BLOCK-TRANSFORMER:([A-Za-z0-9+\\/=]+)\]/', $encoded_block, $matches );
		if ( empty( $matches[1] ) ) {
			return [];
		}

		$parsed = parse_blocks( base64_decode( $matches[1], true ) );
		if ( ! empty( $parsed[0]['blockName'] ) ) {
			return $parsed[0];
		}

		return [];
	}
}
