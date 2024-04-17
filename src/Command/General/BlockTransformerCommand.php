<?php

namespace NewspackCustomContentMigrator\Command\General;

use NewspackContentConverter\Config;
use NewspackContentConverter\Installer;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\GutenbergBlockTransformer;
use NewspackCustomContentMigrator\Logic\Posts;
use WP_CLI;

class BlockTransformerCommand implements InterfaceCommand {

	private Posts $posts_logic;

	private function __construct() {
		$this->posts_logic = new Posts();
	}

	public static function get_instance(): self {
		static $instance;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * @throws \Exception
	 */
	public function register_commands(): void {
		$generic_args = [
			'post-id'     => [
				'type'        => 'assoc',
				'name'        => 'post-id',
				'description' => 'The ID of the post to process.',
				'optional'    => true,
				'repeating'   => false,
			],
			'num-items'   => [
				'type'        => 'assoc',
				'name'        => 'num-items',
				'description' => 'The number of posts to process.',
				'optional'    => true,
				'repeating'   => false,
			],
			'min-post-id' => [
				'type'        => 'assoc',
				'name'        => 'min-post-id',
				'description' => 'The minimum post ID to process.',
				'optional'    => true,
				'repeating'   => false,
			],
			'max-post-id' => [
				'type'        => 'assoc',
				'name'        => 'max-post-id',
				'description' => 'The maximum post ID to process.',
				'optional'    => true,
				'repeating'   => false,
			],
			[
				'type'        => 'assoc',
				'name'        => 'post-types',
				'description' => 'Comma-separated list of post types to process. Default is "post".',
				'optional'    => true,
				'repeating'   => false,
			],
		];

		$reset_flag = [
			'type'        => 'flag',
			'name'        => 'reset-ncc-too',
			'description' => 'Pass this flag to reset NCC to only work on the post range passed to this command.',
			'optional'    => true,
			'repeating'   => false,
		];

		WP_CLI::add_command(
			'newspack-content-migrator transform-blocks-encode',
			[ $this, 'cmd_blocks_encode' ],
			[
				'shortdesc' => '"Obfuscate" blocks in posts by encoding them as base64.',
				'synopsis'  => [
					...$generic_args,
					$reset_flag,
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator transform-blocks-decode',
			[ $this, 'cmd_blocks_decode' ],
			[
				'shortdesc' => '"Un-obfuscate" blocks in posts by decoding them.',
				'synopsis'  => [
					...$generic_args,
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator transform-blocks-nudge',
			[ $this, 'cmd_blocks_nudge' ],
			[
				'shortdesc' => '"Nudge" posts so NCC picks them up',
				'synopsis'  => [
					...$generic_args,
					$reset_flag,
				],
			]
		);
	}

	private function get_post_id_range( array $assoc_args ): array {
		$post_types = [ 'post' ];
		if ( ! empty( $assoc_args['post-types'] ) ) {
			$post_types = explode( ',', $assoc_args['post-types'] );
		}
		global $wpdb;

		if ( ! ( $assoc_args['post-id'] ?? false ) ) {
			$num_items   = $assoc_args['num-items'] ?? PHP_INT_MAX;
			$min_post_id = $assoc_args['min-post-id'] ?? 0;
			$max_post_id = $assoc_args['max-post-id'] ?? PHP_INT_MAX;

			$post_types_format = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

			$post_range = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts 
          					WHERE post_type IN ($post_types_format) 
          					  AND post_status = 'publish'
          					  AND ID >= %d
          					  AND ID <= %d
                        ORDER BY ID DESC 
                        LIMIT %d",
					implode( ',', $post_types ),
					$min_post_id,
					$max_post_id,
					$num_items,
				)
			);
		} else {
			$post_range = [ $assoc_args['post-id'] ];
		}

		return $post_range;
	}

	public function cmd_blocks_nudge( array $pos_args, array $assoc_args ): void {
		$also_kick_ncc = WP_CLI\Utils\get_flag_value( $assoc_args, 'reset-ncc-too' );
		if ( $also_kick_ncc && ! $this->is_ncc_installed() ) {
			WP_CLI::error( 'NCC is not active. Please activate it before running this command if you use the reset flag.' );
		}

		$post_range = $this->get_post_id_range( $assoc_args );

		$post_ids_format = implode( ', ', array_fill( 0, count( $post_range ), '%d' ) );
		global $wpdb;

		// Nudge the posts in the range that might need it.
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->posts} SET post_content = CONCAT(%s, post_content)
    				WHERE ID IN ($post_ids_format)
					AND post_content LIKE '<!-- %'",
			PHP_EOL,
			...$post_range
		);

		$posts_nudged = $wpdb->query( $sql );
		$high         = max( $post_range );
		$low          = min( $post_range );

		WP_CLI::log( sprintf( 'Nudged %d posts between (and including) %d and %d', $posts_nudged, $low, $high ) );

		if ( $also_kick_ncc ) {
			WP_CLI::log( PHP_EOL );
			$this->reset_the_ncc_for_range( $low, $high );
		}
	}


	public function cmd_blocks_decode( array $pos_args, array $assoc_args ): void {
		$all_posts         = $this->get_all_wp_posts( [ 'publish' ], $assoc_args );
		$block_transformer = GutenbergBlockTransformer::get_instance();

		foreach ( $all_posts as $post ) {
			$content = $block_transformer->decode_post_content( $post->post_content );
			if ( $content === $post->post_content ) {
				// No changes - no more to do here.
				continue;
			}

			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $content,
				]
			);
			WP_CLI::log( sprintf( 'Decoded blocks in %s', get_permalink( $post->ID ) ) );
		}
	}

	public function cmd_blocks_encode( array $pos_args, array $assoc_args ): void {
		$also_kick_ncc = WP_CLI\Utils\get_flag_value( $assoc_args, 'reset-ncc-too' );
		if ( $also_kick_ncc && ! $this->is_ncc_installed() ) {
			WP_CLI::error( 'NCC is not active. Please activate it before running this command if you use the reset flag.' );
		}

		$block_transformer = GutenbergBlockTransformer::get_instance();

		$post_id_range         = $this->get_post_id_range( $assoc_args );
		$encoded_posts_counter = 0;
		foreach ( $post_id_range as $post_id ) {
			$post    = get_post( $post_id );
			$content = $block_transformer->encode_post_content( $post->post_content );
			if ( $content === $post->post_content ) {
				// No changes - no more to do here.
				continue;
			}
			++$encoded_posts_counter;
			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $content,
				]
			);
			WP_CLI::log( sprintf( 'Encoded blocks in %s', get_permalink( $post->ID ) ) );
		}

		$high = max( $post_id_range );
		$low  = min( $post_id_range );
		WP_CLI::log( sprintf( '%d posts between (and including) %d and %d needed encoding', $encoded_posts_counter, $low, $high ) );

		if ( $also_kick_ncc ) {
			WP_CLI::log( PHP_EOL );
			$this->reset_the_ncc_for_range( $low, $high );
		}
	}

	private function reset_the_ncc_for_range( int $low, int $high ): void {
		if ( ! $this->is_ncc_installed() ) {
			WP_CLI::error( "NCC is not active. It's needed to run the reset_the_ncc_for_range function." );
		}

		Installer::uninstall_plugin( true );
		$ncc_table_name = Config::get_instance()->get( 'table_name' );
		// This is more than a little unholy, but it will trick the NCC to only work on a specific range of posts.
		add_filter(
			'query',
			function ( $query ) use ( $ncc_table_name, $low, $high ) {
				if ( ! str_starts_with( $query, "INSERT INTO $ncc_table_name" ) ) {
					return $query;
				}

				return str_replace( ';', sprintf( ' AND ID BETWEEN %d AND %d;', $low, $high ), $query );
			}
		);
		Installer::install_plugin();
		WP_CLI::log( sprintf( 'NCC was reset to only work on posts between (and including) %d and %d', $low, $high ) );
		WP_CLI::log( sprintf( 'Go to %s and run the NCC while you can remember the range.', admin_url( 'admin.php?page=newspack-content-converter' ) ) );
	}

	private function is_ncc_installed(): bool {
		return is_plugin_active( 'newspack-content-converter/newspack-content-converter.php' );
	}

	private function get_all_wp_posts( array $post_statuses, array $assoc_args = [], bool $log_progress = true ): iterable {
		if ( ! empty( $assoc_args['post-id'] ) ) {
			$all_ids = [ $assoc_args['post-id'] ];
		} elseif ( ! empty( $assoc_args['min-post-id'] ) || ! empty( $assoc_args['max-post-id'] ) ) {
			$low     = $assoc_args['min-post-id'] ?? 0;
			$high    = $assoc_args['max-post-id'] ?? PHP_INT_MAX;
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
					WP_CLI::log( sprintf( 'Processing post %d/%d: %s', ++$counter, $total_posts, "${home_url}?p=${post_id}" ) );
				}
				yield $post;
			}
		}
	}
}
