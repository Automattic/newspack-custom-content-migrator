<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\Migrator\General\PostsMigrator;
use \WP_CLI;

class ReusableBlocksMigrator implements InterfaceMigrator {

	/**
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
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command( 'newspack-content-migrator export-reusable-blocks', array( $this, 'cmd_export_reusable_blocks' ), [
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
		] );

		WP_CLI::add_command( 'newspack-content-migrator import-reusable-blocks', array( $this, 'cmd_import_reusable_blocks_file' ), [
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
		] );

		WP_CLI::add_command( 'newspack-content-migrator update-reusable-blocks-id', array( $this, 'cmd_update_reusable_blocks_id' ), [
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
		] );
	}

	/**
	 * Callable for export-reusable-blocks. Exits with code 0 on success or 1 otherwise.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_export_reusable_blocks( $args, $assoc_args ) {
		$output_dir = isset( $assoc_args[ 'output-dir' ] ) ? $assoc_args[ 'output-dir' ] : null;
		if ( is_null( $output_dir ) || ! is_dir( $output_dir ) ) {
			WP_CLI::error( 'Invalid output dir.' );
		}

		$posts = $this->get_reusable_blocks();
		if ( empty( $posts ) ) {
			WP_CLI::line( 'No Reusable Blocks found.' );
			exit(1);
		}

		WP_CLI::line( sprintf( 'Exporting Reusable Blocks...' ) );
		$ids = [];
		foreach ( $posts as $post ) {
			$ids[] = $post->ID;
		}

		// The migrator_export_posts() function exports by also setting the \NewspackCustomContentMigrator\Migrator\General\PostsMigrator::META_KEY_ORIGINAL_ID meta on these Posts.
		PostsMigrator::get_instance()->migrator_export_posts( $ids, $output_dir, self::REUSABLE_BLOCKS_FILE );

		WP_CLI::success( 'Done.' );
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
		$post_status    = [ 'publish', 'pending', 'draft', 'future', 'private', 'inherit', 'trash' ],
		$posts_per_page = -1
	) {
		$posts                 = [];
		$query_reusable_blocks = new \WP_Query( [
			'posts_per_page' => $posts_per_page,
			'post_type'      => 'wp_block',
			'post_status'    => $post_status,
		] );
		if ( ! $query_reusable_blocks->have_posts() ) {
			return $posts;
		}

		$posts = $query_reusable_blocks->get_posts();

		return $posts;
	}

	/**
	 * Callable for import-reusable-blocks command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_import_reusable_blocks_file( $args, $assoc_args ) {
		$input_dir = isset( $assoc_args[ 'input-dir' ] ) ? $assoc_args[ 'input-dir' ] : null;
		if ( is_null( $input_dir ) || ! is_dir( $input_dir ) ) {
			WP_CLI::error( 'Invalid input dir.' );
		}

		$import_file = $input_dir . '/' . self::REUSABLE_BLOCKS_FILE;
		if ( ! is_file( $import_file ) ) {
			WP_CLI::error( sprintf( 'Can not find %s.', $import_file ) );
		}

		WP_CLI::line( 'Importing Reusable Blocks...' );

		PostsMigrator::get_instance()->import_posts( $import_file );

		$this->update_reusable_blocks_ids();

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Callable for update-reusable-blocks-id command.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_update_reusable_blocks_id( $args, $assoc_args ) {
		$id_old = $assoc_args[ 'id-old' ] ?? null;
		$id_new = $assoc_args[ 'id-new' ] ?? null;
		if ( is_null( $id_old ) || ! is_null( $id_new ) ) {
			WP_CLI::error( 'Params --id-old and --id-new are required.' );
		}

		global $wpdb;
		$blocks_id_changes[ $id_old ] = $id_new;

		// Get Public Posts and Pages which contain Reusable Blocks.
		$query_public_posts = new \WP_Query( [
			'posts_per_page' => -1,
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			// The search param.doesn't work as expected, so commenting it out for now (it's just a small optimization, anyway).
			// 's'              => '<!-- wp:block'
		] );
		if ( ! $query_public_posts->have_posts() ) {
			return;
		}

		$posts = $query_public_posts->get_posts();
		foreach ( $posts as $key_posts => $post ) {
			WP_CLI::line( sprintf( '(%d%d) ID %d', $key_posts + 1, count( $posts ), $post->ID ) );

			// Replace Block IDs.
			$post_content_updated = $this->update_block_ids( $post->post_content, $blocks_id_changes );

			// Update the Post content.
			if ( $post->post_content != $post_content_updated ) {
				$wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $post_content_updated ], [ 'ID' => $post->ID ] );
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
		$query_public_posts = new \WP_Query( [
			'posts_per_page' => -1,
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			// The search param.doesn't work as expected, so commenting it out for now (it's just a small optimization, anyway).
			// 's'              => '<!-- wp:block'
		] );
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

		$pattern = sprintf( self::PATTERN_WP_BLOCK_ELEMENT_SELFCLOSING, $block_name );
		$preg_match_all_result = preg_match_all( $pattern, $subject, $matches, PREG_OFFSET_CAPTURE );

		return ( false === $preg_match_all_result || 0 === $preg_match_all_result ) ? null : $matches;
	}
}
