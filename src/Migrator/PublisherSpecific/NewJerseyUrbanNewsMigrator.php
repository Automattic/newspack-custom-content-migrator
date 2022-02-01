<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator as WpBlockManipulator;
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
	 * @var WpBlockManipulator.
	 */
	private $blockmanipulator_logic;

	/**
	 * @var Crawler.
	 */
	private $crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->blockmanipulator_logic = new WpBlockManipulator();
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

			// detect all img blocks
			$matches = $this->blockmanipulator_logic->match_wp_block( 'wp:image', $post->post_content );
			if ( ! $matches ) {
				continue;
			}

			foreach ( $matches[0] as $match ) {
				$img_block = $match[0];

				// get img src
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

				// Replace njurbannews.com img srcs to use local hostname.
				$img_src_parsed = parse_url( $img_src );
				if ( 'njurbannews.com' != $img_src_parsed[ 'host' ] && 'www.njurbannews.com' != $img_src_parsed[ 'host' ] ) {
					continue;
				}

				// Get img_src using this local hostname.
				$this_hostname = parse_url( get_site_url() )[ 'host' ];
				$img_src_this_hostname = $img_src_parsed[ 'scheme' ] . '://' . $this_hostname . $img_src_parsed[ 'path' ] . ( $img_src_parsed[ 'query' ] ?? '' );
				echo sprintf( "  - replacing %s >> %s\n", $img_src, $img_src_this_hostname );
				$post_content_updated = str_replace( $img_src, $img_src_this_hostname, $post_content_updated );
			}


			// update post
			if ( $post->post_content != $post_content_updated ) {
				$updated = $wpdb->update( $wpdb->posts, [ 'post_content' => $post_content_updated ], [ 'ID' => $post->ID ] );
				echo "  + saved\n";
			}
		}
	}

}
