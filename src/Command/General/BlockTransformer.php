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
		$this->posts_logic = new Posts();
		$this->block_generator = new GutenbergBlockGenerator();

	}

	public static function get_instance() {
		static $instance;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	public function register_commands() {
		$generic_args = [
			'synopsis' => '[--post-id=<post-id>] [--dry-run] [--num-items=<num-items>] [--min-post-id=<post-id>]',
//			'before_invoke' => [ $this, 'check_requirements' ], TODO? Maybe, maybe not.
		];
		WP_CLI::add_command( 'newspack-content-migrator transform-blocks-encode',
			[ $this, 'cmd_blocks_encode' ],
			[
				'shortdesc' => '"Obfuscate" blocks in posts by encoding them as base64.', //TODO
				...$generic_args,
			]
		);
		WP_CLI::add_command( 'newspack-content-migrator transform-blocks-decode',
			[ $this, 'cmd_blocks_decode' ],
			[
				'shortdesc' => '"Un-obfuscate" blocks in posts by decoding them.', //TODO
				...$generic_args,
			]
		);
	}

	public function cmd_blocks_decode( array $pos_args, array $assoc_args ): void {
		$all_posts = $this->get_all_wp_posts( [ 'publish' ], $assoc_args );
		foreach ( $all_posts as $post ) {
			$content = $post->post_content;
			if ( ! str_contains( $content, '[BT:' ) ) {
				continue;
			}
			preg_match_all( '/\\[BT:([A-Za-z0-9+\\/=]+)\\]/', $content, $matches );
			if ( empty( $matches[0] ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$decoded = $this->decode_block( $match );
				$content = str_replace( '[BT:' . $match . ']', $decoded, $content );
			}
			wp_update_post( [ 'ID' => $post->ID, 'post_content' => $content ] );
		}
	}

	public function cmd_blocks_encode( array $pos_args, array $assoc_args ): void {
		$all_posts = $this->get_all_wp_posts( [ 'publish' ], $assoc_args );
		foreach ( $all_posts as $post ) {
			$content       = $post->post_content;
			$blocks        = parse_blocks( $content );
			$actual_blocks = array_filter( $blocks, fn( $block ) => ! empty( $block['blockName'] ) );
			if ( empty( $actual_blocks ) ) {
				continue;
			}
			$encoded_blocks = array_map( fn( $block ) => $this->get_dud_block( $this->encode_block( $block ) ), $actual_blocks );
			foreach ( $encoded_blocks as $idx => $encoded ) {
				$blocks[ $idx ] = $encoded;
			}
			$content = serialize_blocks( $blocks );
			wp_update_post( [ 'ID' => $post->ID, 'post_content' => $content ] );
		}
	}

	private function encode_block( array $block ): string {
		$as_string = serialize_block( $block );

		return '[BT:' . base64_encode( $as_string ) . ']';
	}

	public function decode_block( string $encoded_block ): string {
		$base64 = substr( $encoded_block, 4, - 1 );

		return "\n" . base64_decode( $base64, true ) . "\n";
	}

	private function get_dud_block( string $content ) {
		return [
			'blockName'    => null,
			'attrs'        => [],
			'innerHTML'    => $content,
			'innerContent' => [ $content ],
		];
	}

	private function get_all_wp_posts( array $post_statuses = [ 'publish' ], array $args = [], bool $log_progress = true ): iterable {
		if ( ! empty( $args['post-id'] ) ) {
			$all_ids = [ $args['post-id'] ];
		} else {
			$all_ids = $this->posts_logic->get_all_posts_ids( 'post', $post_statuses );
			if ( ! empty( $args['num-posts'] ) ) {
				$all_ids = array_slice( $all_ids, 0, $args['num-posts'] );
			}
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