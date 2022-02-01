<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackPostImageDownloader\Downloader;
use \Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for New Jersey Urban News.
 */
class NewJerseyUrbanNewsMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var Crawler.
	 */
	private $crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->crawler = new Crawler();
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
		WP_CLI::add_command(
			'newspack-content-migrator njurbannews-fix-image-blocks',
			[ $this, 'cmd_fix_image_blocks' ],
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_fix_image_blocks( $args, $assoc_args ) {
		global $wpdb;

		$posts = $this->posts_logic->get_all_posts();
		foreach ( $posts as $key_post => $post ) {
			echo sprintf( "%d/%d %d\n", $key_post+1, count( $posts ), $post->ID );

			$post_content_updated = $post->post_content;

			// Detect all img blocks.
			$matches = $this->match_wp_block( 'wp:image', $post->post_content );
			if ( ! $matches ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$img_block = $match[0];

				// Get img src.
				$this->crawler->clear();
				$this->crawler->add( $img_block );
				$img_crawler = $this->crawler->filter( 'img' );
				if ( 0 == $img_crawler->count() ) {
					$d=1;
				}
				foreach ( $img_crawler->getIterator() as $node ) {
					$img_src = $node->getAttribute( 'src' );
					break;
				}

				// Replace njurbannews.com img src to use the local hostname.
				$img_src_parsed = parse_url( $img_src );
				if ( 'njurbannews.com' != $img_src_parsed[ 'host' ] && 'www.njurbannews.com' != $img_src_parsed[ 'host' ] ) {
					continue;
				}

				// Get src w/ local hostname.
				$this_hostname = parse_url( get_site_url() )[ 'host' ];
				$img_src_this_hostname = $img_src_parsed[ 'scheme' ] . '://' . $this_hostname . $img_src_parsed[ 'path' ] . ( $img_src_parsed[ 'query' ] ?? '' );
				echo sprintf( "  - replacing %s >> %s\n", $img_src, $img_src_this_hostname );
				$post_content_updated = str_replace( $img_src, $img_src_this_hostname, $post_content_updated );
			}


			// Update Post.
			if ( $post->post_content != $post_content_updated ) {
				$updated = $wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post->ID ] );
				echo "  + saved\n";
			}
		}
	}

	public function match_wp_block( $block_name, $subject ) {
		$pattern = '|
		\<\!--      # beginning of the block element
		\s          # followed by a space
		%1$s        # element name/designation, should be substituted by using sprintf(), eg. sprintf( $this_pattern, \'wp:video\' );
		.*?         # anything in the middle
		--\>        # end of opening tag
		.*?         # anything in the middle
		(\<\!--     # beginning of the closing tag
		\s          # followed by a space
		/           # one forward slash
		%1$s        # element name/designation, should be substituted by using sprintf(), eg. sprintf( $this_pattern, \'wp:video\' );
		\s          # followed by a space
		--\>)       # end of block
				    # "s" modifier also needed here to match accross multi-lines
		|xims';


		$pattern = sprintf( $pattern, $block_name );

		$preg_match_all_result = preg_match_all( $pattern, $subject, $matches, PREG_OFFSET_CAPTURE );
		return ( false === $preg_match_all_result || 0 === $preg_match_all_result ) ? null : $matches;
	}

}
