<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use Symfony\Component\DomCrawler\Crawler as Crawler;
use \WP_CLI;
use \WP_Query;

/**
 * Custom migration scripts for Rafu Shimpo.
 */
class RafushimpoMigrator implements InterfaceMigrator {

	/**
	 * @var PostsLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	/**
	 * @var AttachmentsLogic.
	 */
	private $attachments_logic;

	/**
	 * @var Crawler.
	 */
	private $crawler;

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->posts_logic = new PostsLogic();
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
		$this->attachments_logic = new AttachmentsLogic();
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
			'newspack-content-migrator rafushimpo-get-obituaries-slugs-from-html-file',
			[ $this, 'cmd_get_obituaries_slugs_from_html_file' ],
			[
				'shortdesc' => 'A helper command which takes a custom made HTML file which contains all the scraped obituaries links, and special header lines beginning with `==` which signify the page with obituary links in the current month where the obituaries were scraped from, and extracts the pure URLs into a formatted file.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'file-html',
						'description' => "Full path to file containing the custom Obituaries HTML file.",
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator rafushimpo-convert-obituaries-from-posts-to-pages',
			[ $this, 'cmd_convert_obituaries_from_posts_to_pages' ],
			[
				'shortdesc' => 'Converts Rafu Shimpo obituaries from Posts to Pages.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'file-slugs',
						'description' => "Full path to file containing Obituaries Posts' slugs to convert to Pages with Obituaries category. Slugs are one on each line, and the slugs begin with a forward slash, no host name, e.g. /slug-1 .",
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator rafushimpo-get-obituaries-slugs-from-html-file` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_get_obituaries_slugs_from_html_file( $args, $assoc_args ) {
		$file = $assoc_args[ 'file-html' ] ?? null;
		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file ) );
		}

		$obituaries_per_months = [];

		$handle = fopen( $file, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				$line = str_replace( [ "\n", "\r" ], '', $line );

				// Is this the header line with the URL of the obituaries for the current month.
				if ( 0 === strpos( $line, '==' ) ) {
					$line = str_replace( [ '==', ' ' ], '', $line );
					$current_month_page = $line;
				} else {

					$this->crawler->clear();
					$this->crawler->add( $line );
					$hrefs = $this->crawler->filterXpath( '//a' )->extract( [ 'href' ] );
					foreach ( $hrefs as $href ) {
						$obituaries_per_months[ $current_month_page ][] = $href;
					}
				}
			}

			fclose($handle);
		} else {
			WP_CLI::error( sprintf( 'Error opening file %s.', $file ) );
		}

		$obituaries_srcs_only = [];
		foreach ( $obituaries_per_months as $page_month => $obituaries_srcs ) {
			foreach ( $obituaries_srcs as $obituary_src ) {
				$obituary_src_saved = $obituary_src;

				$obituary_src_saved = trim( $obituary_src_saved, ' ' );
				if ( 0 === strpos( $obituary_src_saved, 'www.' ) ) {
					$obituary_src_saved = 'https://' . $obituary_src_saved;
				}
				if ( 0 === strpos( $obituary_src_saved, '../' ) ) {
					$obituary_src_saved = 'https://www.rafu.com' . substr( $obituary_src_saved, 2);
				}

				$parsed = parse_url( $obituary_src_saved );

				if ( ! isset( $parsed[ 'path' ] ) || empty( $parsed[ 'path' ] ) ) {
					WP_CLI::warning( sprintf( 'invalid href %s', $obituary_src_saved ) );
					$debug = 1;
					continue;
				}

				if ( empty( $parsed[ 'scheme' ] ) || empty( $parsed[ 'host' ] ) ) {
					WP_CLI::warning( sprintf( 'invalid href %s', $obituary_src_saved ) );
					$debug = 1;
					continue;
				}

				// Remove scheme and host.
				$scheme_and_host = $parsed[ 'scheme' ] . '://' . $parsed[ 'host' ];
				$obituary_src_saved = str_replace( $scheme_and_host, '', $obituary_src_saved );

				$obituaries_srcs_only[] = $obituary_src_saved;
			}
		}

		file_put_contents( 'obituaries_srcs.txt', implode( "\n", $obituaries_srcs_only) );
		WP_CLI::success( sprintf( 'Created file obituaries_srcs.txt' ) );
	}

	/**
	 * Callable for the `newspack-content-migrator rafushimpo-convert-obituaries-from-posts-to-pages` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_convert_obituaries_from_posts_to_pages( $args, $assoc_args ) {
		$time_start = microtime( true );

		$file = $assoc_args[ 'file-slugs' ] ?? null;
		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file ) );
		}


// 		$line = '/2016/12/frances-tsuyuko-nishibayashi';
// 		$page = get_page_by_path( $line, OBJECT, 'page' );
// exit;
// 		$line = '/2017/04/村上園';
// 		$line = '/?page_id=2722';

// WILL '/?page_id=2722' still be the same ID ???

		$pages_all = explode( "\n", file_get_contents( $file ) );
		$pages_not_found = [];

		$handle = fopen( $file, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				$line = str_replace( [ "\n", "\r" ], '', $line );
				$line = rtrim( $line, '/' );
// $line = $line . 'asdfsa';

				// $page = get_page_by_path( $line, OBJECT, 'page' );
				// if ( null == $page ) {
				$page_id = url_to_postid( $line );
				if ( 0 == $page_id ) {
					$pages_not_found[] = $line;
				}
$d=1;
			}

			fclose($handle);
		} else {
			WP_CLI::error( sprintf( 'Error opening file %s.', $file ) );
		}

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	private function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
