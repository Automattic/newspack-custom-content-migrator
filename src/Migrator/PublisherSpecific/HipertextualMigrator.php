<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;

/**
 * Custom migration scripts for Hipertextual.
 */
class HipertextualMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachments_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
		// Bit of a hack doing it this way but ¯\_(ツ)_/¯.

		// Convert Markdown.
		add_filter( 'np_meta_to_content_value', [ $this, 'convert_markdown_headings' ], 10, 3 );
		add_filter( 'np_meta_to_content_value', [ $this, 'convert_markdown_bold' ], 10, 3 );
		add_filter( 'np_meta_to_content_value', [ $this, 'convert_markdown_italics' ], 11, 3 ); // Do after bold.

		// Add missing headings to some sections.
		add_filter( 'np_meta_to_content_value', [ $this, 'add_section_headings' ], 10, 3 );

		// Bulleted lists for Pros and Cons. Run before columns transformation.
		add_filter( 'np_meta_to_content_value', [ $this, 'pros_cons_bullets' ], 9, 3 );

		// Add columns for the Pros and Cons section.
		add_filter( 'np_meta_to_content_value', [ $this, 'pros_cons_columns' ], 10, 3 );

		// Convert markdown URLs.
		WP_CLI::add_command(
			'newspack-content-migrator convert-markdown-urls',
			[ $this, 'convert_markdown_urls' ],
			[
				'shortdesc' => 'Detect markdown URLs and turn them into HTML.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run instead of making any actual changes.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'limit',
						'description' => 'How many items to check in each batch.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'How many batches to run.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 1,
					],
				],
			]
		);

		// Convert markdown URLs.
		WP_CLI::add_command(
			'newspack-content-migrator convert-missed-markdown-bold-italics',
			[ $this, 'convert_missed_markdown' ],
			[
				'shortdesc' => 'Detect markdown and turn it into HTML.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Do a dry run instead of making any actual changes.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'limit',
						'description' => 'How many items to check in each batch.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'How many batches to run.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 1,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator hipertextual-compare-uploads-and-s3-contents-from-log-files',
			[ $this, 'convert_compare_uploads_w_s3' ],
		);

	}

	public function convert_compare_uploads_w_s3( $args1, $args2 ) {
		$path_to_logs = '/Users/ivanuravic/repositories/awscli/berkeleyside_s3_images_eddie/';
		$local_log_sprintf = "%d_local.txt";
		$s3_log_sprintf = "%d_s3.txt";
		$notfound_log = "notfound.txt";
		$years = [
			2002,
			2003,
			2004,
			2005,
			2006,
			2007,
			2008,
			2009,
			2010,
			2011,
			2012,
			2013,
			2014,
			2015,
			2016,
			2017,
			2018,
			2019,
			2020,
			2021,
			2022,
			2024,
		];

		foreach ( $years as $year ) {

			$local_log_path = sprintf( $path_to_logs . $local_log_sprintf, $year );
			$local_log_content = file_get_contents( $local_log_path );
			if ( false === $local_log_content ) {
				WP_CLI::error( $local_log_path . ' not found.' );
			}
			$s3_log_path = sprintf( $path_to_logs . $s3_log_sprintf, $year );
			$s3_log_content = file_get_contents( $s3_log_path );
			if ( false === $s3_log_content ) {
				WP_CLI::error( $s3_log_path . ' not found.' );
			}

			$local_lines = explode( "\n", $local_log_content );
			$s3_lines = explode( "\n", $s3_log_content );

			if ( empty( $s3_lines ) || empty( $local_lines ) ) {
				WP_CLI::error( $year . 'logs missing' );
			}

			foreach ( $s3_lines as $s3_line ) {
				$pos_start = strpos( $s3_line, 'wp-content/uploads/' );
				$s3_keys[ substr( $s3_line, $pos_start + strlen( 'wp-content/uploads/' ) ) ] = 1;
			}

			foreach ( $local_lines as $key_local_line => $local_line ) {

				WP_CLI::log( sprintf( "%d (%d)/(%d)", $year, $key_local_line + 1, count( $local_lines ) ) );

				if ( isset( $s3_keys[$local_line] ) ) {
					unset( $s3_keys[$local_line] );
				} else {
					WP_CLI::log( sprintf( "NOTFOUND %s", $local_line ) );
					file_put_contents( $path_to_logs . $notfound_log, $local_line . "\n", FILE_APPEND );
				}
			}
		}
	}

	/**
	 * Convert markdown headings
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_headings( $value, $key, $post_id ) {

		// Look for markdown headings, skip if there aren't any.
		$find = \preg_match_all( '/(#+\S+.+)\n/', $value, $matches );
		if ( ! $find || 0 === $find ) {
			return $value;
		}

		// Markdown to look for and HTML tags to replace them with.
		$replacements = [
			'#####' => 'h5',
			'####'  => 'h4',
			'###'   => 'h3',
			'##'    => 'h2',
		];

		foreach ( $matches[0] as $match ) {

			// Loop through each markdown replacement needed.
			foreach( $replacements as $search => $replace ) {
				if ( false !== \strpos( $match, $search ) ) {

					// Remove the markdown from the heading, leaving just the text.
					$title = \str_replace( $search, '', $match );

					// Wrap the heading with the relevant HTML tags.
					$title = sprintf( '<%1$s>%2$s</%1$s>', $replace, trim( $title ) );

					// Replace the original string in the meta value with our new HTML string.
					$value = str_replace( $match, $title, $value );
				}
			}

		}

		return $value;
	}

	/**
	 * Convert markdown bold
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_bold( $value, $key, $post_id ) {

		// Look for markdown bold, skip if there aren't any.
		$find = \preg_match_all( '/\*\*[\w\sáéíóúñü,\-\s\(\)\.]+\*\*/', $value, $matches );
		if ( ! $find || 0 === $find ) {
			return $value;
		}

		foreach ( $matches[0] as $match ) {
			$new_text = \str_replace( '*', '', $match );
			$new_text = sprintf( '<strong>%s</strong>', $new_text );
			$value    = \str_replace( $match, $new_text, $value );
		}

		return $value;

	}

	/**
	 * Convert markdown italics
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_italics( $value, $key, $post_id ) {

		// Look for markdown bold, skip if there aren't any.
		$find = \preg_match_all( '/\*[\w\sáéíóúñü,\-\s\(\)\.]+\*/', $value, $matches );
		if ( ! $find || 0 === $find ) {
			return $value;
		}

		foreach ( $matches[0] as $match ) {
			$new_text = \str_replace( '*', '', $match );
			$new_text = sprintf( '<em>%s</em>', $new_text );
			$value    = \str_replace( $match, $new_text, $value );
		}

		return $value;

	}

	/**
	 * Convert missed markdown
	 */
	public function convert_missed_markdown( $args, $assoc_args ) {

		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : false;
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;
		$limit   = min( $assoc_args['limit'], 100 );
		$batches = min( $assoc_args['batches'], 10 );

		// Run minimal actions.
		if ( ! $dry_run ) {
			$this->updating();
		}

		// Grab the posts to convert then.
		if ( $post_id ) {
			$this->fix_markdown_in_post( get_post( $post_id ), $dry_run );
			WP_CLI::success( 'Finished processing.' );
			return;
		}

		for ( $i = 0; $i < $assoc_args['batches']; $i++ ) {

			WP_CLI::line( sprintf( 'Checking batch %d', $i ) );

			$posts = get_posts( [
				'posts_per_page' => $limit,
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'meta_query'     => [
					[
						'key'     => '_np_markdown_fix',
						'compare' => 'NOT EXISTS',
					]
				]
			] );

			// Don't try to convert nothing, 'cause that won't work.
			if ( empty( $posts ) ) {
				WP_CLI::error( 'There are no posts to convert!' );
			}

			WP_CLI::line( sprintf( 'Checking %d posts', count( $posts ) ) );

			// Convert the posts!
			foreach ( $posts as $post ) {
				$this->fix_markdown_in_post( $post, $dry_run );
			}

		}

		// All work and no cache clearance make Homer something something...
		$this->stop_the_insanity();

		WP_CLI::success( 'Finished processing.' );

	}

	/**
	 * Convert markdown URLs
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_urls( $args, $assoc_args ) {

		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : false;
		$dry_run = isset( $assoc_args['dry-run'] ) ? true : false;
		$limit   = min( $assoc_args['limit'], 100 );
		$batches = min( $assoc_args['batches'], 10 );

		// Run minimal actions.
		if ( ! $dry_run ) {
			$this->updating();
		}

		// Grab the posts to convert then.
		if ( $post_id ) {
			$this->fix_markdown_urls_in_post( get_post( $post_id ), $dry_run );
			WP_CLI::success( 'Finished processing.' );
			return;
		}

		for ( $i = 0; $i < $assoc_args['batches']; $i++ ) {

			WP_CLI::line( sprintf( 'Checking batch %d', $i ) );

			$posts = get_posts( [
				'posts_per_page' => $limit,
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'meta_query'     => [
					[
						'key'     => '_np_markdown_urls',
						'compare' => 'NOT EXISTS',
					]
				]
			] );

			// Don't try to convert nothing, 'cause that won't work.
			if ( empty( $posts ) ) {
				WP_CLI::error( 'There are no posts to convert!' );
			}

			WP_CLI::line( sprintf( 'Checking %s posts', count( $posts ) ) );

			// Convert the posts!
			foreach ( $posts as $post ) {
				$this->fix_markdown_urls_in_post( $post, $dry_run );
			}

		}

		// All work and no cache clearance make Homer something something...
		$this->stop_the_insanity();

		WP_CLI::success( 'Finished processing.' );

	}

	/**
	 * Fix missed markdown in a specific post.
	 */
	private function fix_markdown_in_post( $post, $dry_run ) {

		$post_content = $post->post_content;

		$post_content = $this->convert_markdown_bold( $post_content, '', $post->ID );
		$post_content = $this->convert_markdown_italics( $post_content, '', $post->ID );

		if ( $post_content !== $post->post_content ) {
			$update = ( $dry_run ) ? true : wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $post_content,
			] );
			if ( is_wp_error( $update ) ) {
				WP_CLI::warning( sprintf(
					'Failed to update post %d because %s',
					$post->ID,
					$update->get_error_message()
				) );
			}
		}

		if ( ! $dry_run ) {
			add_post_meta( $post->ID, '_np_markdown_fix', date('c') );
		}

	}

	/**
	 *
	 */
	private function fix_markdown_urls_in_post( $post, $dry_run ) {

		$post_content = $post->post_content;

		// Look for markdown URLs, skip if there aren't any.
		$find = \preg_match_all( '/\[([\w\ssáéíóúñü]+)\]\(([\S]+)\)/', $post_content, $matches );
		if ( ! $find || 0 === $find ) {
			if ( ! $dry_run ) {
				add_post_meta( $post->ID, '_np_markdown_urls', date('c') );
			}
			return;
		}

		WP_CLI::line( sprintf( 'Found %d matches in post %d', count( $matches[0] ), $post->ID ) );

		for ( $i = 0; $i < count( $matches[0] ); $i++ ) {
			$replace = sprintf(
				'<a href="%s">%s</a>',
				$matches[2][$i],
				$matches[1][$i]
			);
			$post_content = \str_replace( $matches[0][$i], $replace, $post_content );
		}

		if ( $post_content !== $post->post_content ) {
			$update = ( $dry_run ) ? true : wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $post_content,
			] );
			if ( is_wp_error( $update ) ) {
				WP_CLI::warning( sprintf(
					'Failed to update post %d because %s',
					$post->ID,
					$update->get_error_message()
				) );
			} else {
				WP_CLI::line( sprintf( 'Updated post %d', $post->ID ) );
			}
		}

		if ( ! $dry_run ) {
			add_post_meta( $post->ID, '_np_markdown_urls', date('c') );
		}

	}

	/**
	 * Add section headings to some sections.
	 *
	 * @return string Converted content.
	 */
	public function add_section_headings( $value, $key, $post_id ) {

		// Things
		$headings = [
			'conclusion' => '<h2>Conclusión</h2>',
		];

		if ( ! in_array( $key, array_keys( $headings ) ) ) {
			return $value;
		}

		foreach ( $headings as $meta_key => $heading ) {
			if ( $meta_key === $key ) {
				// Append the relevant heading on to the content.
				$value = $heading . $value;
			}
		}

		return $value;

	}

	/**
	 * Bulleted lists for Pros and Cons.
	 *
	 * @return string Modified content.
	 */
	public function pros_cons_bullets( $value, $key, $post_id ) {

		if ( ! in_array( $key, [ 'pros', 'contras' ] ) ) {
			return $value;
		}

		$items = explode( '-', $value );
		$list = '';
		foreach ( $items as $item ) {
			if ( empty( $item ) ) continue;
			$list .= sprintf( '<li>%s</li>', trim( $item ) );
		}

		$value = sprintf( '<ul>%s</ul>', $list );

		return $value;

	}

	/**
	 * Add columns for the Pros and Cons section.
	 *
	 * @return string Modified content.
	 */
	public function pros_cons_columns( $value, $key, $post_id ) {

		if ( 'pros' == $key ) {
			$value = sprintf(
				'<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2  class="pros">Pros</h2>
<!-- /wp:heading --><!-- wp:paragraph -->%s<!-- /wp:paragraph --></div>
<!-- /wp:column -->',
				$value
			);
		}

		if ( 'contras' == $key ) {
			$value = sprintf(
				'<!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading -->
<h2 class="contras">Contras</h2>
<!-- /wp:heading --><!-- wp:paragraph -->%s<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->',
				$value
			);
		}

		return $value;

	}

	/**
	 * Clear some caches while running bulk update tasks.
	 */
	protected function stop_the_insanity() {
		$this->reset_local_object_cache();
		$this->reset_db_query_log();
	}

	/**
	 * Performance enhancement for when we're running bulk update operations.
	 */
	protected function updating() {
		define( 'WP_IMPORTING', true );
	}

	/**
	 * Clear the local object cache to free up memory.
	 *
	 * This only cleans the local cache in WP_Object_Cache, without affecting
	 * memcache. Borrowed from our friends at VIP:
	 * https://github.com/Automattic/vip-go-mu-plugins/blob/7bf6b099f7a1a218a9716b0dad93a66e388982ff/vip-helpers/vip-caching.php#L743
	 */
	private function reset_local_object_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		$wp_object_cache->group_ops = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache = array();

		if ( is_callable( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset(); // important
		}
	}

	/**
	 * Reset the WordPress DB query log.
	 *
	 * Borrowed from our friends at VIP
	 * https://github.com/Automattic/vip-go-mu-plugins/blob/7bf6b099f7a1a218a9716b0dad93a66e388982ff/vip-helpers/vip-caching.php#L743
	 */
	private function reset_db_query_log() {
		global $wpdb;
		$wpdb->queries = array();
	}

}
