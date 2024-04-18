<?php

namespace NewspackCustomContentMigrator\Command\General;

use NewspackContentConverter\Config;
use NewspackContentConverter\Installer;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\GutenbergBlockTransformer;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;
use WP_CLI\ExitException;

class BlockTransformerCommand implements InterfaceCommand {

	private Logger $logger;

	private function __construct() {
		$this->logger = new Logger();
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

		WP_CLI::log( sprintf( 'Nudged %d posts between (and including) %d and %d ID', $posts_nudged, $low, $high ) );

		if ( $also_kick_ncc ) {
			WP_CLI::log( PHP_EOL );
			$this->reset_the_ncc_for_posts( $post_range );
		}
	}


	public function cmd_blocks_decode( array $pos_args, array $assoc_args ): void {
		$logfile = sprintf( '%s-%s.log', __FUNCTION__, date( 'Y-m-d-H-i-s' ) );

		$block_transformer = GutenbergBlockTransformer::get_instance();

		$post_id_range   = $this->get_post_id_range( $assoc_args );
		$post_ids_format = implode( ', ', array_fill( 0, count( $post_id_range ), '%d' ) );

		global $wpdb;
		$sql             = $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
    				WHERE ID IN ($post_ids_format)
					AND post_content LIKE %s",
			[ ...$post_id_range, '%' . $wpdb->esc_like( '[BLOCK-TRANSFORMER:' ) . '%' ]
		);
		$posts_to_decode = $wpdb->get_results( $sql );

		$num_posts_found = count( $posts_to_decode );
		$this->logger->log( $logfile, sprintf( 'Found %d posts to decode', $num_posts_found ), Logger::INFO );

		$decoded_posts_counter = 0;
		foreach ( $posts_to_decode as $post ) {
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
			$this->logger->log( $logfile, sprintf( 'Decoded blocks in ID %d %s', $post->ID, get_permalink( $post->ID ) ), Logger::SUCCESS );

			++$decoded_posts_counter;
			if ( $decoded_posts_counter % 25 === 0 ) {
				$spacer = str_repeat( ' ', 10 );
				WP_CLI::log( sprintf( '%s ==== Decoded %d of %d posts. %d remaining ==== %s', $spacer, $decoded_posts_counter, $num_posts_found,
					( $num_posts_found - $decoded_posts_counter ), $spacer ) );
			}
		}

		$this->logger->log( $logfile, sprintf( '%d posts have been decoded', count( $posts_to_decode ) ), Logger::SUCCESS );
		wp_cache_flush();
	}

	/**
	 * Obfuscate blocks in posts and optionally reset NCC to only work on the posts in the range.
	 *
	 * @param array $pos_args The positional arguments passed to the command.
	 * @param array $assoc_args The associative arguments passed to the command.
	 *
	 * @return void
	 * @throws ExitException
	 */
	public function cmd_blocks_encode( array $pos_args, array $assoc_args ): void {
		$logfile = sprintf( '%s-%s.log', __FUNCTION__, date( 'Y-m-d-H-i-s' ) );

		$also_kick_ncc = WP_CLI\Utils\get_flag_value( $assoc_args, 'reset-ncc-too' );
		if ( $also_kick_ncc && ! $this->is_ncc_installed() ) {
			WP_CLI::error( 'NCC is not active. Please activate it before running this command if you use the reset flag.' );
		}

		$block_transformer = GutenbergBlockTransformer::get_instance();

		$post_id_range   = $this->get_post_id_range( $assoc_args );
		$post_ids_format = implode( ', ', array_fill( 0, count( $post_id_range ), '%d' ) );

		global $wpdb;
		$sql             = $wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts}
    				WHERE ID IN ($post_ids_format)
					AND post_content LIKE %s",
			[ ...$post_id_range, $wpdb->esc_like( '<!-- ' ) . '%' ]
		);
		$posts_to_encode = $wpdb->get_results( $sql );
		$num_posts_found = count( $posts_to_encode );
		$this->logger->log( $logfile, sprintf( 'Found %d posts to encode', $num_posts_found ), Logger::INFO );

		$encoded_posts_counter = 0;
		foreach ( $posts_to_encode as $post ) {
			$content = $block_transformer->encode_post_content( $post->post_content );
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
			$this->logger->log( $logfile, sprintf( 'Encoded blocks in post ID %d  %s', $post->ID, get_permalink( $post->ID ) ), Logger::SUCCESS );

			++$encoded_posts_counter;
			if ( $encoded_posts_counter % 25 === 0 ) {
				$spacer = str_repeat( ' ', 10 );
				WP_CLI::log( sprintf( '%s ==== Encoded %d of %d posts. %d remaining ==== %s', $spacer, $encoded_posts_counter, $num_posts_found,
					( $num_posts_found - $encoded_posts_counter ), $spacer ) );
			}
		}

		if ( ( $assoc_args['post-id'] ?? false ) ) {
			$decode_command = sprintf( 'wp newspack-content-migrator transform-blocks-decode --post-id=%s', $assoc_args['post-id'] );
		} else {
			$high           = max( $post_id_range );
			$low            = min( $post_id_range );
			$decode_command = sprintf( 'To decode the blocks AFTER running the NCC, run this:%s wp newspack-content-migrator transform-blocks-decode --min-post-id=%d --max-post-id=%d',
				PHP_EOL,
				$low, $high );
		}
		if ( ( $assoc_args['post-types'] ?? false ) ) {
			$decode_command .= ' --post-types=' . $assoc_args['post-types'];
		}
		$this->logger->log( $logfile, sprintf( '%d posts needed encoding', $encoded_posts_counter ), Logger::SUCCESS );
		$this->logger->log( $logfile, sprintf( 'To decode the blocks AFTER running the NCC, run this:%s %s', PHP_EOL, $decode_command ), Logger::INFO );

		if ( $also_kick_ncc ) {
			WP_CLI::log( PHP_EOL );
			$this->reset_the_ncc_for_posts( $post_id_range );
		}
		wp_cache_flush();
	}


	/**
	 * Get posts within the range specified in the arguments for the commands in this class.
	 *
	 * @param array $assoc_args The associative arguments passed to the command.
	 *
	 * @return array of post IDs.
	 */
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
					[
						...$post_types,
						$min_post_id,
						$max_post_id,
						$num_items,
					]
				)
			);
		} else {
			$post_range = explode( ',', $assoc_args['post-id'] );
		}

		return $post_range;
	}

	/**
	 * Reset the NCC to only work on a specific range of posts.
	 *
	 * Note that this is rather destructive because it will blow away the "backup" content for posts.
	 *
	 * @param array $post_ids The post IDs to work on.
	 *
	 * @throws ExitException
	 */
	private function reset_the_ncc_for_posts( array $post_ids ): void {
		if ( ! $this->is_ncc_installed() ) {
			WP_CLI::error( "NCC is not active. It's needed to run the reset_the_ncc_for_range function." );
		}

		Installer::uninstall_plugin( true );
		$ncc_table_name = Config::get_instance()->get( 'table_name' );
		// This is more than a little unholy, but it will trick the NCC to only work on a specific range of posts.
		add_filter(
			'query',
			function ( $query ) use ( $ncc_table_name, $post_ids ) {
				if ( ! str_starts_with( $query, "INSERT INTO $ncc_table_name" ) ) {
					return $query;
				}

				return str_replace( ';', sprintf( ' AND ID IN(%s);', implode( ',', $post_ids ) ), $query );
			}
		);
		Installer::install_plugin();
		WP_CLI::log( sprintf( 'NCC was reset to only work on %d posts', count( $post_ids ) ) );
		WP_CLI::log( sprintf( 'Go to %s and run the NCC now.', admin_url( 'admin.php?page=newspack-content-converter' ) ) );
	}

	/**
	 * Is NNC installed and active?
	 *
	 * @return bool true if NCC is installed and active.
	 */
	private function is_ncc_installed(): bool {
		return is_plugin_active( 'newspack-content-converter/newspack-content-converter.php' );
	}

}
