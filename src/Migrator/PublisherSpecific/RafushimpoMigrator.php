<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Posts as PostsLogic;
use \NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;
use Symfony\Component\DomCrawler\Crawler as Crawler;
use \WP_CLI;
use \WP_Query;
use \WP_CLI\Utils;

/**
 * Custom migration scripts for Rafu Shimpo.
 */
class RafushimpoMigrator implements InterfaceMigrator {

	/**
	 * Post Meta telling us what the old Post ID was.
	 */
	CONST META_OLD_ID = '_newspack_old_post_id';

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
		WP_CLI::add_command(
			'newspack-content-migrator rafushimpo-output-sql-to-add-meta-with-current-id-to-posts-and-pages',
			[ $this, 'cmd_output_sql_to_add_meta_with_current_id_to_posts_and_pages' ],
			[
				'shortdesc' => 'Outputs on screen the SQL query which adds meta to all Posts and Pages containing the current ID as its value.',
				'synopsis'  => [
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
		$file = $assoc_args[ 'file-slugs' ] ?? null;
		if ( ! file_exists( $file ) ) {
			WP_CLI::error( sprintf( 'File %s not found.', $file ) );
		}

		global $wpdb;
		$time_start = microtime( true );
		$pages_all = explode( "\n", file_get_contents( $file ) );
		$progress = Utils\make_progress_bar( 'Examining links...', count( $pages_all ) );
		$pages_converted = [];
		$pages_converted_error = [];
		$links_not_found = [];

		$handle = fopen( $file, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				$progress->tick();

				$line = str_replace( [ "\n", "\r" ], '', $line );
				$line = trim( $line, ' ' );
				$line = rtrim( $line, '/' );

				// Using 0 as unset for $page_id, because that's what `url_to_postid()` returns on no match. Using null for all other custom vars.
				$page_id = 0;
				$original_page_id = null;

				// First we should try and get the original Post/Page ID from the URL, and only use WP's functions to get the ID if the old ID isn't present in the URL.
				// $pages_id is always 0 here, just making an obvious logical point... :)
				if ( 0 == $page_id ) {

					// Get original ID from query params.
					$parsed_line = parse_url( $line );
					$query_params = [];
					parse_str( $parsed_line[ 'query' ] ?? '', $query_params );
					if ( isset( $parsed_line[ 'query' ] ) && isset( $query_params[ 'p' ] ) && ! empty( $query_params[ 'p' ] ) ) {
						$original_page_id = $query_params[ 'p' ];
					}
					if ( isset( $parsed_line[ 'query' ] ) && isset( $query_params[ 'post' ] ) && ! empty( $query_params[ 'post' ] ) ) {
						$original_page_id = $query_params[ 'post' ];
					}
					if ( isset( $parsed_line[ 'query' ] ) && isset( $query_params[ 'page_id' ] ) && ! empty( $query_params[ 'page_id' ] ) ) {
						$original_page_id = $query_params[ 'page_id' ];
					}

					// Also try and get the original ID from their previous permalink format which ended in the numeric ID.
					if ( null == $original_page_id ) {
						$line_trimmed = rtrim( $line, '/' );
						$line_exploded = explode( '/', $line_trimmed );
						if ( is_numeric( $line_exploded[ count( $line_exploded ) - 1 ] ) ) {
							$original_page_id = $line_exploded[ count( $line_exploded ) - 1 ];
						}
					}

					// If we matched the old Post ID, now let's get the current Post ID from the meta.
					if ( ! is_null( $original_page_id ) ) {
						$res_original_post_id = $wpdb->get_row( $wpdb->prepare( "select post_id from {$wpdb->postmeta} where meta_key = %s and meta_value = %d ;", self::META_OLD_ID, $original_page_id ), ARRAY_A );
						if ( isset( $res_original_post_id[ 'post_id' ] ) ) {
							$page_id = $res_original_post_id[ 'post_id' ];
						}
					}
				}

				// Only if the ID is still not found by using the manual ways, try the native ways to get the current ID from the URL.
				if ( 0 == $page_id ) {
					$page_id = url_to_postid( $line );
				}

				// If all else fails, try and get the Post from the last URL segment as the Post title.
				if ( 0 == $page_id ) {
					$line_exploded = explode( '/', $line );
					$last_segment = $line_exploded[ count( $line_exploded ) - 1 ];
					if ( ! empty( $last_segment ) ) {
						$last_segment_with_spaces = str_replace( '-', ' ', $last_segment );
						$row = $wpdb->get_row( $wpdb->prepare( "select ID from {$wpdb->posts} where post_title = %s ;", $last_segment_with_spaces ), ARRAY_A );
						if ( isset( $row[ 'ID' ] ) ) {
							$page_id = $row[ 'ID' ];
						}
					}
				}

				if ( 0 == $page_id ) {
					$links_not_found[] = $line;
				} else {
					$pages_converted[ $page_id ] = $line;
				}
			}

			fclose($handle);
			$progress->finish();
		} else {
			WP_CLI::error( sprintf( 'Error opening file %s.', $file ) );
		}


		// Convert obituaries to Posts in the Obituaries category.
		WP_CLI::line( sprintf( 'Now converting %d Pages to Posts in the `Obituaries` Category...', count( $pages_converted ) ) );
		$obituaries_category_id = wp_create_category( 'Obituaries' );
		$key_pages_converted = 0;
		foreach ( $pages_converted as $page_id => $link ) {

			$key_pages_converted++;
			WP_CLI::line( sprintf( '(%d/%d) updating %s %s', $key_pages_converted, count( $pages_converted ), $page_id, $link ) );

			$page = get_post( $page_id );
			if ( null == $page ) {
				WP_CLI::warning( sprintf( 'Error updating ID %d link %s', $page_id, $link ) );
				unset( $pages_converted[ $page_id ] );
				$pages_converted_error[ $page_id ] = $link;
				continue;
			}

			$wpdb->update( $wpdb->prefix . 'posts', [ 'post_type' => 'post' ], [ 'ID' => $page->ID ] );
			wp_set_post_categories( $page->ID, $obituaries_category_id, true );

		}


		// Log and report.
		if ( ! empty( $links_not_found ) ) {
			$file_links_not_found = substr( $file, 0, strpos( $file, '.' ) ) . '___notFound' . substr( $file, strpos( $file, '.' ) );
			file_put_contents( $file_links_not_found, implode( "\n", $links_not_found ) );
			WP_CLI::warning( sprintf( '%d/%d links were not found from the list you provided -- logged to %s', count( $links_not_found ), count( $pages_all ), $file_links_not_found ) );
		}

		$file_pages_converted = substr( $file, 0, strpos( $file, '.' ) ) . '___pagesConverted' . substr( $file, strpos( $file, '.' ) );
		$pages_converted_imploded = '';
		foreach ( $pages_converted as $page_id => $link ) {
			$pages_converted_imploded .= ! empty( $pages_converted ) ? "\n" : '';
			$pages_converted_imploded .= sprintf( '%d %s', $page_id, $link );
		}
		file_put_contents( $file_pages_converted, $pages_converted_imploded );
		WP_CLI::warning( sprintf( '%d/%d pages converted -- logged to %s', count( $pages_converted ), count( $pages_all ), $file_pages_converted ) );

		$file_pages_converted_error = substr( $file, 0, strpos( $file, '.' ) ) . '___convertedError' . substr( $file, strpos( $file, '.' ) );
		$pages_converted_error_imploded = '';
		foreach ( $pages_converted_error as $page_id => $link ) {
			$pages_converted_error_imploded .= empty( $pages_converted_error ) ? '' : "\n";
			$pages_converted_error_imploded .= sprintf( '%d %s', $page_id, $link );
		}
		file_put_contents( $file_pages_converted_error, $pages_converted_error_imploded );
		WP_CLI::warning( sprintf( '%d/%d pages not updated successfully -- logged to %s', count( $pages_converted_error ), count( $pages_all ), $file_pages_converted_error ) );


		wp_cache_flush();

		WP_CLI::line( sprintf( 'All done! 🙌 Took %d mins.', floor( ( microtime( true ) - $time_start ) / 60 ) ) );
	}

	/**
	 * Callable for the `newspack-content-migrator rafushimpo-output-sql-to-add-meta-with-current-id-to-posts-and-pages` command.
	 *
	 * @param array $args       CLI arguments.
	 * @param array $assoc_args CLI associative arguments.
	 */
	public function cmd_output_sql_to_add_meta_with_current_id_to_posts_and_pages( $args, $assoc_args ) {
		$meta_key = self::META_OLD_ID;
		$query = <<<SQL
INSERT INTO wp_postmeta ( post_id, meta_key, meta_value )
SELECT ID, '{$meta_key}', ID
FROM wp_posts
LEFT JOIN wp_postmeta ON (
    wp_postmeta.post_id = wp_posts.ID
    AND wp_postmeta.meta_key = '{$meta_key}'
)
WHERE wp_posts.post_type IN ( 'post', 'page' )
AND wp_postmeta.meta_key IS NULL;
SQL;
		WP_CLI::line( $query );
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
