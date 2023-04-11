<?php

namespace NewspackCustomContentMigrator\Command\General;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Command\General\PostsMigrator;
use \NewspackCustomContentMigrator\Logic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\Utils\Logger as Logger;
use \WP_CLI;

/**
 * Reusable Blocks Migrator.
 */
class ReusableBlocksMigrator implements InterfaceCommand {

	/**
	 * Reusable Blocks export file.
	 *
	 * @var string Reusable Blocks export file.
	 */
	const REUSABLE_BLOCKS_FILE = 'newspack-custom-reusable-blocks.xml';

	/**
	 * Matches a self-closing block element -- which is one that does NOT have both an opening tag `<!-- wp:__ -->` and a closing
	 * tag `<!-- /wp:__ -->`, but rather has just one "self-closing tag", e.g. `<!-- wp:__ /-->`.
	 */
	const PATTERN_WP_BLOCK_ELEMENT_SELFCLOSING = '|
		\<\!--        # beginning of the block element
		\s            # followed by a space
		%s            # element name/designation, should be substituted by using sprintf()
		.*?           # anything in the middle
		\/--\>        # ends with a self-closing tag
		|xims';

	/**
	 * Posts logic.
	 *
	 * @var PostsLogic $post_logic Posts logic.
	 */
	private $post_logic;

	/**
	 * Logger.
	 *
	 * @var Logger $logger Logger.
	 */
	private $logger;

	/**
	 * Instance.
	 *
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->post_logic = new PostsLogic();
		$this->logger     = new Logger();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator export-reusable-blocks',
			array( $this, 'cmd_export_reusable_blocks' ),
			[
				'shortdesc' => 'Exports Reusable Blocks. Exits with code 0 on success or 1 otherwise.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'output-dir',
						'description' => 'Output directory full path (no ending slash).',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator import-reusable-blocks',
			array( $this, 'cmd_import_reusable_blocks_file' ),
			[
				'shortdesc' => 'Imports Reusable Blocks which were exported from the Staging site.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'input-dir',
						'description' => 'Input directory full path (no ending slash).',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator update-reusable-blocks-id',
			array( $this, 'cmd_update_reusable_blocks_id' ),
			[
				'shortdesc' => 'Updates a Reusable Block ID in content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'id-old',
						'description' => 'Old ID.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'id-new',
						'description' => 'New ID.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator delete-reusable-blocks-from-content',
			array( $this, 'cmd_delete_reusable_blocks_from_content' ),
			[
				'shortdesc' => 'Goes through all --post-types-csv and searches for reusable blocks with given IDs --reusable-block-ids-csv and removes their usage from post_content.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'reusable-block-ids-csv',
						'description' => 'CSV of reusable block IDs to delete from post_content.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-types-csv',
						'description' => 'CSV of post types to scan and delete reusable blocks from.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for export-reusable-blocks. Exits with code 0 on success or 1 otherwise.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_delete_reusable_blocks_from_content( $pos_args, $assoc_args ) {
		global $wpdb;

		$reusable_block_ids = explode( ',', $assoc_args['reusable-block-ids-csv'] );
		$post_types         = isset( $assoc_args['post-types-csv'] ) ? explode( ',', $assoc_args['post-types-csv'] ) : [ 'post', 'page' ];
		if ( empty( $reusable_block_ids ) ) {
			WP_CLI::error( 'No reusable block IDs provided.' );
		}

		$log_dir = 'logs_deleted_reusable_blocks';

		WP_CLI::log( 'Getting all post IDs...' );
		$post_ids = [];
		foreach ( $post_types as $post_type ) {
			$post_ids = array_merge( $post_ids, $this->post_logic->get_all_posts_ids( $post_type ) );
		}

		// Remove reusable blocks from content.
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Get post_content.
			$post_content         = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM $wpdb->posts WHERE ID = %d", $post_id ) );
			$post_content_updated = $post_content;

			// Search and remove reusable blocks from post_content.
			foreach ( $reusable_block_ids as $reusable_block_id ) {
				$reusable_block_html = $this->get_reusable_block_html( $reusable_block_id );

				/**
				 * Instead of just deleting the $reusable_block_html, we should also clean up surrounding line breaks.
				 * Here are examples of how that could look like in post_content, and we'll search and replace them in this order:
				 *
				 * 1. reusable block is in the middle of content, surrounded by line breaks:
				 *    "
				 *    a
				 *
				 *    <!-- wp:block {"ref":401522} /-->
				 *
				 *    a
				 *    "
				 *    => we replace (line break + reusable block + line break) with (line break).
				 *
				 * 2. reusable block is at the beginning of content, something follows:
				 *    "
				 *    <!-- wp:block {"ref":401522} /-->
				 *
				 *    a
				 *    "
				 *    => we replace (reusable block + line break) with nothing.
				 *
				 * 3. reusable block is at the end of content, something precedes:
				 *    "
				 *    a
				 *
				 *    <!-- wp:block {"ref":401522} /-->
				 *    "
				 *    => we replace (line break + reusable block) with nothing.
				 *
				 * 4. reusable block is the only thing in content, or line breaks are not properly formatted:
				 *    "
				 *    <!-- wp:block {"ref":401522} /-->
				 *    "
				 *    => we remove the (reusable block).
				 */
				$post_content_updated = str_replace( "\n" . $reusable_block_html . "\n", "\n", $post_content_updated );
				$post_content_updated = str_replace( "\n" . $reusable_block_html, '', $post_content_updated );
				$post_content_updated = str_replace( $reusable_block_html . "\n", '', $post_content_updated );
				$post_content_updated = str_replace( $reusable_block_html, '', $post_content_updated );
			}

			if ( $post_content_updated != $post_content ) {
				// Update.
				$wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $post_content_updated ], [ 'ID' => $post_id ] );

				// Log.
				if ( ! is_dir( $log_dir ) ) {
					// phpcs:ignore
					mkdir( $log_dir );
				}
				$this->logger->log( sprintf( '%s/%s_before.txt', $log_dir, $post_id ), $post_content, false );
				$this->logger->log( sprintf( '%s/%s_after.txt', $log_dir, $post_id ), $post_content_updated, false );
				WP_CLI::log( 'Updated' );
			}
		}

		// Let $wpdb->update() sink in.
		wp_cache_flush();

		if ( is_dir( $log_dir ) ) {
			WP_CLI::log( sprintf( 'Done, see logs in %s', $log_dir ) );
		} else {
			WP_CLI::log( 'Done.' );
		}
	}

	/**
	 * Callable for export-reusable-blocks. Exits with code 0 on success or 1 otherwise.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_export_reusable_blocks( $pos_args, $assoc_args ) {
		$output_dir = isset( $assoc_args['output-dir'] ) ? $assoc_args['output-dir'] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		$posts = $this->get_reusable_blocks();
		if ( empty( $posts ) ) {
			WP_CLI::warning( 'No Reusable Blocks found.' );
			exit( 1 );
		}

		WP_CLI::line( sprintf( 'Exporting Reusable Blocks...' ) );
		$ids = [];
		foreach ( $posts as $post ) {
			$ids[] = $post->ID;
		}

		// The migrator_export_posts() function exports by also setting the \NewspackCustomContentMigrator\Command\General\PostsMigrator::META_KEY_ORIGINAL_ID meta on these Posts.
		PostsMigrator::get_instance()->migrator_export_posts( $ids, $output_dir, self::REUSABLE_BLOCKS_FILE );

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Gets HTML of Reusable Block
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string HTML of Reusable Block.
	 */
	private function get_reusable_block_html( int $post_id ): string {
		return sprintf( '<!-- wp:block {"ref":%d} /-->', $post_id );
	}

	/**
	 * Gets all Reusable Blocks.
	 *
	 * @param string[] $post_status     `post_status` argument for \WP_Query::construct().
	 * @param int      $posts_per_page  `posts_per_page` argument for \WP_Query::construct().
	 *
	 * @return array Array of Posts.
	 */
	private function get_reusable_blocks(
		$post_status = [ 'publish', 'pending', 'draft', 'future', 'private', 'inherit', 'trash' ],
		$posts_per_page = -1
	) {
		$posts                 = [];
		$query_reusable_blocks = new \WP_Query(
			[
				'posts_per_page' => $posts_per_page,
				'post_type'      => 'wp_block',
				'post_status'    => $post_status,
			]
		);
		if ( ! $query_reusable_blocks->have_posts() ) {
			return $posts;
		}

		$posts = $query_reusable_blocks->get_posts();

		return $posts;
	}

	/**
	 * Callable for import-reusable-blocks command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_reusable_blocks_file( $pos_args, $assoc_args ) {
		$input_dir = isset( $assoc_args['input-dir'] ) ? $assoc_args['input-dir'] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$import_file = $input_dir . '/' . self::REUSABLE_BLOCKS_FILE;
		if ( ! is_file( $import_file ) ) {
			WP_CLI::warning( sprintf( 'Reusable blocks file not found %s.', $import_file ) );
			exit( 1 );
		}

		WP_CLI::line( 'Importing Reusable Blocks from ' . $import_file . ' ...' );

		PostsMigrator::get_instance()->import_posts( $import_file );

		$this->update_reusable_blocks_ids();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for update-reusable-blocks-id command.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_update_reusable_blocks_id( $pos_args, $assoc_args ) {
		$id_old = $assoc_args['id-old'] ?? null;
		$id_new = $assoc_args['id-new'] ?? null;
		if ( is_null( $id_old ) || ! is_null( $id_new ) ) {
			WP_CLI::error( 'Params --id-old and --id-new are required.' );
		}

		global $wpdb;
		$blocks_id_changes[ $id_old ] = $id_new;

		// Get Public Posts and Pages which contain Reusable Blocks.
		$query_public_posts = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
			// The search param.doesn't work as expected, so commenting it out for now (it's just a small optimization, anyway).
			// 's'              => '<!-- wp:block'          // .
			]
		);
		if ( ! $query_public_posts->have_posts() ) {
			return;
		}

		$posts = $query_public_posts->get_posts();
		foreach ( $posts as $key_posts => $post ) {
			WP_CLI::line( sprintf( '(%d/%d) ID %d', $key_posts + 1, count( $posts ), $post->ID ) );

			// Replace Block IDs.
			$post_content_updated = $this->update_block_ids( $post->post_content, $blocks_id_changes );

			// Update the Post content.
			if ( $post->post_content != $post_content_updated ) {
				$wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $post_content_updated ], [ 'ID' => $post->ID ] );
				WP_CLI::success( 'Updated ID.' );
			}
		}

		// Let the $wpdb->update() sink in.
		wp_cache_flush();
	}

	/**
	 * Updates all the newly imported Reusable Blocks' IDs with their new IDs.
	 */
	private function update_reusable_blocks_ids() {
		$blocks = $this->get_reusable_blocks();
		if ( empty( $blocks ) ) {
			// This shouldn't happen, but let's handle it anyways.
			return;
		}

		global $wpdb;

		// Get a list of all ID changes for Reusable Block after import.
		/**
		 * Blocks_id_changes.
		 *
		 * @param array $blocks_id_changes An array containing old Post IDs for keys, and new Post IDs for values.
		 */
		$blocks_id_changes = [];
		foreach ( $blocks as $block ) {
			$original_block_id = get_post_meta( $block->ID, PostsMigrator::META_KEY_ORIGINAL_ID, true );
			if ( $original_block_id && $original_block_id != $block->ID ) {
				$blocks_id_changes[ (int) $original_block_id ] = $block->ID;
			}
		}
		if ( empty( $blocks_id_changes ) ) {
			return;
		}

		// Get Public Posts and Pages which contain Reusable Blocks.
		$query_public_posts = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
			// The search param.doesn't work as expected, so commenting it out for now (it's just a small optimization, anyway).
			// 's'              => '<!-- wp:block'                   // .
			]
		);
		if ( ! $query_public_posts->have_posts() ) {
			return;
		}

		foreach ( $query_public_posts->get_posts() as $post ) {
			// Replace Block IDs.
			$post_content_updated = $this->update_block_ids( $post->post_content, $blocks_id_changes );

			// Update the Post content.
			if ( $post->post_content != $post_content_updated ) {
				$wpdb->update(
					$wpdb->prefix . 'posts',
					[ 'post_content' => $post_content_updated ],
					[ 'ID' => $post->ID ]
				);
			}
		}

		// Let the $wpdb->update() sink in.
		wp_cache_flush();
	}

	/**
	 * Updates/changes the Reusable Blocks IDs in source.
	 *
	 * @param string $content           Post content containing Reusable Blocks to get their IDs updated.
	 * @param array  $blocks_id_changes An array of ID updates -- has old IDs for keys, new IDs for values.
	 *
	 * @return string Updated content.
	 */
	private function update_block_ids( $content, $blocks_id_changes ) {

		// Get all Reusable Blocks in content (here we get the `$matches` array from a preg_match_all as return).
		$matches = $this->match_wp_block( 'wp:block', $content );
		if ( ! $matches || ! isset( $matches[0] ) || empty( $matches[0] ) ) {
			return $content;
		}

		// Loop through matched Reusable Blocks, and then update their IDs.
		$post_content_updated = $content;
		foreach ( $matches[0] as $match ) {

			$block_source = $match[0];

			// Get this Block ID.
			$parsed = parse_blocks( $block_source );
			if ( ! isset( $parsed[0]['attrs']['ref'] ) ) {
				continue;
			}
			$id = $parsed[0]['attrs']['ref'];

			// Check if this ID needs to be updated.
			$new_id = $blocks_id_changes[ $id ] ?? null;
			if ( ! $new_id ) {
				continue;
			}

			// Update the ID.
			$updated_block        = $this->update_wp_block_id( $block_source, $id, $new_id );
			$post_content_updated = str_replace( $block_source, $updated_block, $post_content_updated );
		}

		return $post_content_updated;
	}

	/**
	 * Updates the ID in a Reusable Block.
	 *
	 * @param string $content Reusable Block source.
	 * @param int    $id      Current ID.
	 * @param int    $new_id  New ID.
	 *
	 * @return string|null Updated source, or null.
	 */
	private function update_wp_block_id( $content, $id, $new_id ) {
		$id_ref_old = sprintf( '"ref":%d', $id );
		$id_ref_new = sprintf( '"ref":%d', $new_id );

		$pos_id_ref = strpos( $content, $id_ref_old );
		if ( false === $pos_id_ref ) {
			return null;
		}

		$block_updated = str_replace( $id_ref_old, $id_ref_new, $content );

		return $block_updated;
	}

	/**
	 * Searches and matches blocks in given source.
	 *
	 * Uses preg_match_all() with the PREG_OFFSET_CAPTURE option, and returns its $match.
	 *
	 * @param string $block_name Block name/designation to search for.
	 * @param string $subject    The Block source in which to search for the block occurences.
	 *
	 * @return array|null The `$matches` array as set by preg_match_all() with the PREG_OFFSET_CAPTURE option, or null if no matches found.
	 */
	public function match_wp_block( $block_name, $subject ) {

		$pattern               = sprintf( self::PATTERN_WP_BLOCK_ELEMENT_SELFCLOSING, $block_name );
		$preg_match_all_result = preg_match_all( $pattern, $subject, $matches, PREG_OFFSET_CAPTURE );

		return ( false === $preg_match_all_result || 0 === $preg_match_all_result ) ? null : $matches;
	}
}
