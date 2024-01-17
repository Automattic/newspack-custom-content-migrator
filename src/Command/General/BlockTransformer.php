<?php

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\Posts;
use WP_CLI;

class BlockTransformer implements InterfaceCommand {


	private Posts $posts_logic;
	private GutenbergBlockGenerator $block_generator;

	private function __construct() {
		$this->posts_logic     = new Posts();
		$this->block_generator = new GutenbergBlockGenerator();
	}

	public static function get_instance(): self {
		static $instance;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	public function register_commands(): void {
		$generic_args = [
			'synopsis' => '[--post-id=<post-id>] [--dry-run] [--num-items=<num-items>] [--min-post-id=<post-id>] [--max-post-id=<post-id>]',
		];

		WP_CLI::add_command(
			'newspack-content-migrator transform-blocks-encode',
			[ $this, 'cmd_blocks_encode' ],
			[
				'shortdesc' => '"Obfuscate" blocks in posts by encoding them as base64.',
				...$generic_args,
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator transform-blocks-decode',
			[ $this, 'cmd_blocks_decode' ],
			[
				'shortdesc' => '"Un-obfuscate" blocks in posts by decoding them.',
				...$generic_args,
			]
		);
	}

	public function cmd_blocks_decode( array $pos_args, array $assoc_args ): void {
		$all_posts = $this->get_all_wp_posts( [ 'publish' ], $assoc_args );
		foreach ( $all_posts as $post ) {
			$content        = $post->post_content;
			$blocks         = parse_blocks( $content );
			$encoded_blocks = array_filter( $blocks, fn( $block ) => str_contains( $block['innerHTML'], '[BLOCK-TRANSFORMER:' ) );

			if ( empty( $encoded_blocks ) ) {
				continue;
			}
			foreach ( $encoded_blocks as $idx => $encoded ) {
				$decoded = $this->decode_block( $encoded['innerHTML'] );
				if ( ! empty( $decoded ) ) {
					$blocks[ $idx ] = $decoded;
				} else {
					WP_CLI::log( sprintf( 'Failed to decode block %d in post %d', $idx, $post->ID ) );
				}
			}

			$content = serialize_blocks( $blocks );
			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $content,
				]
			);
			WP_CLI::log( sprintf( 'Decoded %d blocks in post %d', count( $encoded_blocks ), $post->ID ) );
		}
	}

	public function cmd_blocks_encode( array $pos_args, array $assoc_args ): void {
		$all_posts = $this->get_all_wp_posts( [ 'publish' ], $assoc_args );
		foreach ( $all_posts as $post ) {
			$content       = $post->post_content;
			$blocks        = parse_blocks( $content );
			$actual_blocks = array_filter( $blocks, fn( $block ) => ! empty( $block['blockName'] ) && ! str_contains( $block['innerHTML'], '[BLOCK-TRANSFORMER:' ) );
			if ( empty( $actual_blocks ) ) {
				continue;
			}
			$encoded_blocks = array_map( fn( $block ) => $this->encode_block( $block ), $actual_blocks );
			foreach ( $encoded_blocks as $idx => $encoded ) {
				$blocks[ $idx ] = $encoded;
			}
			$content = serialize_blocks( $blocks );
			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $content,
				]
			);
		}
	}

	private function encode_block( array $block ): array {
		$as_string = serialize_block( $block );

		$anchor = '[BLOCK-TRANSFORMER:' . base64_encode( $as_string ) . ']';

		return $this->block_generator->get_paragraph( $anchor );// Can't use class names - NCC strips them.
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

	private function get_all_wp_posts( array $post_statuses = [ 'publish' ], array $assoc_args = [], bool $log_progress = true ): iterable {
		if ( ! empty( $assoc_args['post-id'] ) ) {
			$all_ids = [ $assoc_args['post-id'] ];
		} elseif ( ! empty( $assoc_args['min-post-id'] ) || ! empty( $assoc_args['max-post-id'] ) ) {
			$low = $assoc_args['min-post-id'] ?? 0;
			$high = $assoc_args['max-post-id'] ?? PHP_INT_MAX;
			$all_ids = $this->posts_logic->get_post_ids_in_range( $low, $high, [ 'post' ], $post_statuses );
		} else {
			$all_ids = $this->posts_logic->get_all_posts_ids( 'post', $post_statuses );
		}
		if ( ! empty( $assoc_args['num-items'] ) ) {
			$all_ids = array_slice( $all_ids, 0, $assoc_args['num-items'] );
		}

		$total_posts = count( $all_ids );
		$home_url    = home_url();
		$counter     = 0;
		if ( $log_progress ) {
			WP_CLI::log( sprintf( 'Processing %d posts', count( $all_ids ) ) );
		}

		foreach ( $all_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				if ( $log_progress ) {
					WP_CLI::log( sprintf( 'Processing post %d/%d: %s', ++ $counter, $total_posts, "${home_url}?p=${post_id}" ) );
				}
				yield $post;
			}
		}
	}
}
