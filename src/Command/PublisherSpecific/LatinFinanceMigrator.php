<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Utils\Logger;
use \Newspack_WXR_Exporter;
use \PDO, \PDOException;
use \stdClass;
use stringEncode\Encode;
use \WP_CLI;
use \WP_Query;

/**
 * Custom migration scripts for Latin Finance (Umbraco / .NET / MSSQL / SQLServer).
 */
class LatinFinanceMigrator implements InterfaceCommand {

	private $site_title  = 'LatinFinance';
	private $site_url    = 'https://www.latinfinance.com';
	private $export_path = \WP_CONTENT_DIR;

	private $pdo = null;
	private $authors = array();
	private $tags = array();
	private $post_slugs = array();
	private $prev_checksums = array();
	private $prev_categories = array();
	private $out_files = [];

	private $logger;
	private $coauthorsplus_logic = null;

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-check-redirects',
			[ $this, 'cmd_check_redirects' ],
			[
				'shortdesc' => 'Check all old site urls against current site. Only run after Yoast primary categories and permlinks are set.  Yoast and Redirection plugins must be active.  CSVs will be exported.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-check-redirects-digital-editions',
			[ $this, 'cmd_check_redirects_digital_editions' ],
			[
				'shortdesc' => 'Gets existing read-digital editions redirects. Redirection plugins must be active. CSV will be exported.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-check-redirects-magazine-issues',
			[ $this, 'cmd_check_redirects_magazine_issues' ],
			[
				'shortdesc' => 'Check all old site Magazine Issues against current site. Only run after latinfinance-check-redirects CSVs are uploaded. Redirection plugins must be active.  CSV will be exported.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-delete-duplicate-meta',
			[ $this, 'cmd_delete_duplicate_meta' ],
			[
				'shortdesc' => 'Deletes duplicate postmeta that was added from imports that ran twice.  CSV report will be exported.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'query-limit',
						'description' => 'SQL LIMIT for group by query. (Integer)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 1000,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-delete-error-imports',
			[ $this, 'cmd_delete_error_imports' ],
			[
				'shortdesc' => 'Deletes posts that were merged incorrectly by wp import. Must be run after latinfinance-delete-duplicate-meta.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-export-from-mailchimp',
			[ $this, 'cmd_export_from_mailchimp' ],
			[
				'shortdesc' => 'Exports content from Mailchimp backups.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'import-batch',
						'description' => 'The import batch to run: newsletters (default) | missing-newsletters(-[0-9]+?)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'newsletters',
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-export-from-mssql',
			[ $this, 'cmd_export_from_mssql' ],
			[
				'shortdesc' => 'Exports content from MS SQL DB to WXR files.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-fix-duplicate-categories',
			[ $this, 'cmd_fix_duplicate_categories' ],
			[
				'shortdesc' => 'Resets duplicate categories that wp importer merged.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-fix-images-in-content',
			[ $this, 'cmd_fix_images_in_content' ],
			[
				'shortdesc' => 'Fixes images in-conent that we not imported properly by the wp importer.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-set-coauthors-plus',
			[ $this, 'cmd_set_coauthors_plus' ],
			[
				'shortdesc' => 'Uses postmeta values to create Guest Authors (if not already set). (Co-Authors Plus plugin must be installed).',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to process per batch. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'Batches to process per run. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => -1,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-set-coauthors-plus-launch',
			[ $this, 'cmd_set_coauthors_plus_launch' ],
			[
				'shortdesc' => 'LAUNCH VERSION OF PREVIOUS COMMAND',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to process per batch. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'Batches to process per run. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => -1,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-set-primary-categories',
			[ $this, 'cmd_set_primary_categories' ],
			[
				'shortdesc' => 'Sets Yoast primary category from old content type.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to process per batch. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 100,
					],
					[
						'type'        => 'assoc',
						'name'        => 'batches',
						'description' => 'Batches to process per run. (Integer) (-1 => all)',
						'optional'    => true,
						'repeating'   => false,
						'default'     => -1,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator latinfinance-set-subtitles',
			[ $this, 'cmd_set_subtitles' ],
			[
				'shortdesc' => 'Sets subtitles from excerpts.',
				'synopsis'  => [],
			]
		);

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-check-redirects'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_check_redirects( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing latinfinance-check-redirects..." );

		// todo: make sure Redirects plugin is active
		// todo: make sure Yoast is on for get_permalink() to work. ... or just redirect to /?p=ID
		// todo: make sure permalink is set too ... or just redirect to /?p=ID

		$report = array(
			'daily-brief-ascii'        => 0,
			'daily-brief-unicode'      => 0,
			'needs-redirect'           => 0,
			'redirect-exists'          => 0,
			'skip-differing'           => 0,
			'url-to-post-id-matched'   => 0,
			'url-to-post-id-mis-match' => 0,
			'url-to-post-id-mis-match-ids' => array(),
		);

		// get and export old redirects
		$old_redirects = $this->hack_get_old_redirects();

		$new_redirects = array_filter( $this->hack_get_old_redirects_launch(), function( $v, $k ) use( $old_redirects ) {
			if( isset( $old_redirects[$k] ) ) return false;
			return true;
		}, ARRAY_FILTER_USE_BOTH );

		// $this->log_to_csv( $old_redirects, $this->export_path  . '/latinfinance-redirects-1.csv', 'single-with-keys' );
		$this->log_to_csv( $new_redirects, $this->export_path  . '/latinfinance-redirects-1.csv', 'single-with-keys' );

		// get all old urls
		$args = [
			'posts_per_page' => -1,
			'post_type'     => 'post',
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => 'newspack_lf_url',
					'compare' => 'EXISTS',
				],
				'relation' => 'AND',
				[
					'key'     => 'newspack_lf_import_batch',
					'value'		=> 'missing',
					'compare' => '=',
				],
			],
		];
		$query = new WP_Query ( $args );
		WP_CLI::line( sprintf( 'Found %s posts.', $query->post_count ) );

		// set array for new redirects
		$redirects = [];
		
		foreach ($query->posts as $post_id ) {

			// get old url
			$meta = get_post_meta( $post_id, 'newspack_lf_url');
						
			// we still have issues with duplicate postmetas...
			if( 2 < count( $meta ) ) {
				WP_CLI::error('Post ' . $post_id . ' has more than 2 rows for old url.' );
			}
			// if there are 2 metas, and they are different, skip
			else if( 2 == count( $meta ) && $meta[0] != $meta[1]) {
				// WP_CLI::warning( 'Skipping differing old urls for post ' . $post_id );
				$report['skip-differing']++;
				continue;
			}

			// set variable
			$url = $meta[0];

			// Since the Redirection plugin will run before checking against the posts table, check redirects first
			// if found, no new redirect needed
			if( isset( $old_redirects[ $url ] ) ) {
				$report['redirect-exists']++;
				continue;
			}

			// Test if the old url returns it's same post_id
			$found_post_id = url_to_postid( $url );

			// if old url returns the same post id, no redirect needed
			if( $found_post_id == $post_id ) {
				$report['url-to-post-id-matched']++;
				continue;
			}

			// if something was found, but it wasn't the same id, something is wrong...
			if( $found_post_id > 0 ) {
				// WP_CLI::line( sprintf( 'Checking post %d with url: %s', $post_id, $url ) );
				// WP_CLI::error( 'Different post id was found?' );
				$report['url-to-post-id-mis-match']++;
				$report['url-to-post-id-mis-match-ids'][] = $post_id;
				continue;
			}

			// The remaining urls may need a redirct

			// for Daily Briefs urls, they have /year/mon/day/ in them
			//   so Wordpress may be able to find a 301 using date and slug
			if( preg_match( '/^\/daily-briefs/', $url ) ) {
				
				// $post = get_post( $post_id );
				
				// the following could (?) still be an issue if multiple slugs for same day
				// ...in a different category?

				// from: wp load/init => _find_post_by_old_slug
				// SELECT post_id
				// FROM wp_postmeta, wp_posts
				// WHERE ID = post_id AND post_type = 'post'
				// AND meta_key = '_wp_old_slug'
				// AND meta_value = 'gafisa-names-new-ceo'
				// AND YEAR(post_date) = 2023 AND MONTH(post_date) = 2 AND DAYOFMONTH(post_date) = 1

				// from: wp load/init => redirect_guess_404_permalink()
				// 	SELECT ID
				// 	FROM wp_posts 
				// 	WHERE post_name LIKE 'gafisa-names-new-ceo%' 
				// 	AND post_type IN ('post') AND YEAR(post_date) = 2023 AND MONTH(post_date) = 2 
				// 	AND DAYOFMONTH(post_date) = 1 AND post_status IN ('publish')

				// but for unicode, a urlencoded _wp_old_slug must be set
				if ( strlen( $url ) != mb_strlen( $url ) ) {
					$report['daily-brief-unicode']++;
					// update_post_meta( $post_id, '_wp_old_slug', urlencode( get_post_meta( $post_id, 'newspack_lf_slug', true ) ) );
				}
				else $report['daily-brief-ascii']++;

				// for now, let's just add a redirect for all of them.
				// continue;

			}

			$report['needs-redirect']++;

			$redirects[] = [ 
				'source URL' => $url, 
				'target URL' => str_replace( home_url(), '', get_permalink ( $post_id ) ),
			];

			// // WP_CLI::line( sprintf( 'Checking post %d with url: %s', $post_id, $url ) );
			// WP_CLI::line( sprintf( "'%s','%s'", $url, get_permalink ( $post_id ) ) );
			
			// need redirects for these as they don't have full /year/mon/day/ in urls
			//  they will match a "category" rewrite rule
			// if( !preg_match( '/^\/magazine/', $url )
			// 	&& !preg_match( '/^\/web-/', $url )
			// 	&& !preg_match( '/^\/awards/', $url )
			// 	&& !preg_match( '/^\/archive/', $url )
			//  ) { echo $url; exit(); }
			
			print_r($report);

		}

		// todo: change this to use the Redirection API?
		// @link: https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Logic/Redirection.php#L20
		$this->log_to_csv( $redirects, $this->export_path  . '/latinfinance-redirects-2.csv');

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-check-redirects-digital-editions'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_check_redirects_digital_editions( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing latinfinance-check-redirects-digital-editions..." );

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		// make sure previous redirects are already set
		if( count( \Red_Item::get_all() ) < 8000 ) {
			WP_CLI::error( 'Previous redirects not found.' );
		} 

		global $wpdb;

		$results = $wpdb->get_results("
			select meta_value, ID
			from wp_postmeta 
			join wp_posts on wp_posts.ID = wp_postmeta.post_id
			where meta_key = 'newspack_lf_url' and meta_value like '%read-digital%';
		");

		$redirects = [];

		foreach( $results as $row ) {
			
			// from url is
			$from = str_replace( home_url(), '', get_permalink ( $row->ID ) );

			// to url is
			$redirect = \Red_Item::get_for_matched_url( $row->meta_value );

			// if no "to" url, then skip
			if( empty( $redirect[0]->match->url ) ) {
				WP_CLI::warning( 'Skipping ' . print_r( $row ) );
				continue;
			}

			$redirects[$from] = $redirect[0]->match->url;

		}

		ksort( $redirects );
		$this->log_to_csv( $redirects, $this->export_path  . '/latinfinance-redirects-4-read-digital.csv', 'single-with-keys' );

		WP_CLI::success( 'Done. CSV exported to WP_CONTENT_DIR.' );


	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-check-redirects-magazine-issues'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_check_redirects_magazine_issues( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing latinfinance-check-redirects-magazine-issues..." );

		// make sure Redirects plugin is active
		if( ! class_exists ( '\Red_Item' ) ) {
			WP_CLI::error( 'Redirection plugin must be active.' );
		}
		// make sure previous redirects are already set
		if( count( \Red_Item::get_all() ) < 8000 ) {
			WP_CLI::error( 'Previous redirects not found.' );
		} 

		// Setup MSSQL DB connection
		$this->set_pdo();

		// Get posts for the content types
		$result = $this->pdo->prepare("
			SELECT cmsDocument.nodeId, cmsDocument.expireDate, cmsContentXML.xml
			FROM cmsDocument
			JOIN cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			JOIN cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			JOIN cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias in('magazineIssue')
			WHERE cmsDocument.published = 1	
			ORDER BY cmsDocument.nodeId
		");
		$result->execute();

		// set array for new redirects
		$redirects = [];

		// save old data for CPT archive headers?
		// $issues = [];

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			// load xml column
			$xml = simplexml_load_string( $row['xml'] );

			// Test expireDate
			if( null !== $row['expireDate'] ) {
				WP_CLI::error( 'Post expireDate exists "' . $row['expireDate'] .'" for node ' . $row['nodeId']);
			}

			$slug = (string) $xml['urlName'];
			$url = $this->get_url_from_path( (string) $xml['path'] );
			$display_date_timestamp = strtotime( (string) $xml->displayDate );

			// set slug and old site url if we want to setup a CPT for archive headers
			// $issue = [

				// titles
				// 'text_row' => $row['text'],
				// 'name_xml' => (string) $xml['nodeName'],

				// dates
				// 'display_date_xml' => date('Y-m-d', $display_date_timestamp ),
				
				// urls
				// 'slug' => $slug,
				// 'url' => $url,

				// image
				// 'featured_image_id' => (string) $xml->image,
				// 'featured_image_url' => '',

				// content
				// 'snippet' => (string) $xml->snippet,

			// ];

			// if( ! empty ( $xml->image ) ) {

			// 	// must be single integer
			// 	if( ! preg_match('/^[0-9]+$/', (string) $xml->image ) ) {
			// 		WP_CLI::error( 'Featured image is not single integer ' . (string) $xml->image .' for node ' . $row['nodeId']);
			// 	}
				
			// 	$featured_image = $this->get_featured_image( (string) $xml->image );

			// 	if( null !== $featured_image ) {

			// 		$issue['featured_image_url'] = $featured_image['url'];
				
			// 	} // null featured image

			// } // xml->image

			// WP_CLI::line( print_r( $issue, true ) );
		
			// $issues[] = $issue;

			// test for existing redirect
			if( ! empty( \Red_Item::get_for_matched_url( $url ) ) ) {
				WP_CLI::warning( 'Skipping existing redirect for ' . $url );
				continue;
			}

			// fix some that don't fit the pattern
			$fixes = [

				'10th-anniversary-1998' 					=> 'july-1998',
				
				'august-2001-latin-banking-guide-directory' => 'august-2001',
				'august-2002-latin-banking-guide-directory' => 'august-2001',
				'august-2003-latin-banking-guide-directory' => 'august-2003',
				
				'latin-banking-guide-directory-2004' 		=> 'july-2004',
				'latin-banking-guide-directory-2005' 		=> 'july-2005',
				
				'december-2005' 				=> 'november-2005',
				'february-2005' 				=> 'january-2005',
				'july-2005' 					=> 'june-2005',
				'june-2005' 					=> 'may-2005',
				'november-2005' 				=> 'october-2005',
				'october-2005' 					=> 'september-2005',
				
				'august-2006' 					=> 'july-2006',
				'december-2006' 				=> 'november-2006',
				'february-2006' 				=> 'january-2006',
				'july-2006' 					=> 'june-2006',
				'june-2006' 					=> 'may-2006',
				'november-2006' 				=> 'october-2006',
				'october-2006' 					=> 'september-2006',
				'september-2006' 				=> 'august-2006',
				
				'april-2007' 					=> 'march-2007',
				'december-2007' 				=> 'november-2007',
				'february-2007' 				=> 'january-2007',
				'july-august-2007' 				=> 'june-2007',
				'june-2007' 					=> 'may-2007',
				'march-2007' 					=> 'february-2007',
				'may-2007' 						=> 'april-2007',
				'november-2007' 				=> 'october-2007',
				'october-2007' 					=> 'september-2007',
				
				'june-2008' 					=> 'may-2008',
				'march-2008' 					=> 'february-2008',

				'february-2009' 				=> 'january-2009',
				
				'july-august-2013' 				=> 'june-2013',
				
				'july-august-2018' 				=> 'august-2018',
				'may-june-2018' 				=> 'june-2018',
				'september-october-2018' 		=> 'october-2018',
				
				'25th-anniversary-articles' 	=> 'september-2013',
			
				// when in doubt, set to the "read digital edition" publish date
				'q2-when-the-wind-blows' 		=> 'march-2023',

			];
			$slug = ( $fixes[$slug] ) ?? $slug;

			$timestamp = false;

			// try old style slugs (month-month-YYYY)
			preg_match( '/([a-z]+)-([a-z]+)-(\d{2,4})$/', $slug, $matches );
			if( 4 == count( $matches ) ) {
				$timestamp = strtotime( $matches[1] . '-' . $matches[3] ); // returns false if no date
			} 

			// try old style slugs (month-YYYY)
			if( ! $timestamp ) {
				$timestamp = strtotime( $slug ); // returns false if no date
			}

			// try newer quarterly slugs (using display date) if timestamp is false
			if( ! $timestamp ) {
				$timestamp = $display_date_timestamp;
			}

			$new_url = '/magazine/' . date('Y/m/', $timestamp);

			// add domains for testing
			// $url = 'https://www.latinfinance.com' . $url;
			// $new_url = 'https://latinfinance-newspack.newspackstaging.com' . $new_url;

			$redirects[$url] =  $new_url;

		} // while

		// todo: change this to use the Redirection API?
		// @link: https://github.com/Automattic/newspack-custom-content-migrator/blob/master/src/Logic/Redirection.php#L20

		ksort( $redirects );
		$this->log_to_csv( $redirects, $this->export_path  . '/latinfinance-redirects-3.csv', 'single-with-keys' );

		WP_CLI::success( 'Done. CSV exported to WP_CONTENT_DIR.' );


	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-delete-duplicate-meta'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_delete_duplicate_meta( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing latinfinance-delete-duplicate-meta..." );

		$query_limit = isset( $assoc_args[ 'query-limit' ] ) ? (int) $assoc_args['query-limit'] : 1000;

		if( 1 > $query_limit ) {
			WP_CLI::error( "Query limit argument must be 1 or greater." );
		}

		$report = array();

		// do multiple queries until no more results.
		do {
			
			$count = $this->delete_duplicate_meta( $query_limit , $report );
			WP_CLI::line ( 'Deleted ' . $count . '.' );

		} while ( $count > 0 );

		$this->log_to_csv( $report, $this->export_path  . '/latinfinance-delete-duplicate-meta-report.csv', 'single-with-keys' );
	
		WP_CLI::success( 'Done. Report exported to WP_CONTENT_DIR.' );

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-delete-error-imports'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_delete_error_imports( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing latinfinance-delete-error-imports..." );

		global $wpdb;

		// select rows that had duplicate meta_keys with differing values
		$ids = $wpdb->get_col("
			SELECT DISTINCT pm1.post_id
			FROM wp_postmeta pm1
			JOIN wp_postmeta pm2 on pm2.post_id = pm1.post_id 
				and pm2.meta_key = pm1.meta_key 
				and pm2.meta_value <> pm1.meta_value
		");
		
		// make sure it's the same set of posts indentified locally
		if ( count( $ids ) != 48 ) {
			WP_CLI::error( 'ID result set does not match  48 rows.');
		}

		// Setup MSSQL DB connection
		$this->set_pdo();

		// set output
		$csv = array();

		// get posts
		$args = array(
			'posts_per_page' => -1,
			'post__in' => $ids,
			'orderby' => array( 'date' => 'ASC', 'title' => 'ASC' ),
		);
		$query = new WP_Query ( $args );
		
		foreach ($query->posts as $post ) {
			
			$node_ids = get_post_meta( $post->ID, 'newspack_lf_node_id' );

			$output = array( 
				'WP_URL' => 'https://latinfinance-newspack.newspackstaging.com/?p=' . $post->ID,
				'WP_ID' => $post->ID, 
				'WP_post_date' => $post->post_date,
				'WP_post_title' => $post->post_title,
				// 'post_name' => $post->post_name,
				// 'old_node_ids' => implode( ", ", $node_ids ),
			);

			// Get old content
			$result = $this->pdo->prepare("
				SELECT cmsDocument.nodeId, cmsContentXML.xml
				FROM cmsDocument
				JOIN cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
				WHERE cmsDocument.published = 1	
				AND cmsDocument.nodeId in(" . implode(',', array_fill(0, count( $node_ids ), '?')) . ")
				ORDER BY cmsDocument.nodeId
			");
			$result->execute( $node_ids );

			$node_index = 0;

			while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

				$node_index++;

				// load xml column
				$xml = simplexml_load_string( $row['xml'] );

				$output['old_node_id_' . $node_index] = $row['nodeId'];
				$output['old_node_name_' . $node_index] = (string) $xml['nodeName'];
				$output['old_node_date_' . $node_index] = (string) $xml->displayDate;
				$output['old_node_url_' . $node_index] = 'https://www.latinfinance.com' . $this->get_url_from_path( (string) $xml['path'] );

			} // while nodes
		
			// $urls = get_post_meta( $post->ID, 'newspack_lf_url');
			// for( $i = 0; $i < count ( $urls) ; $i++ ) {
			// 	$output['old_url_' . ($i + 1)] = 'https://www.latinfinance.com' . $urls[$i];
			// }

			$csv[] = $output;

		}
		
		$this->log_to_csv( $csv, $this->export_path . '/latinfinance-error-imports.csv' );

		WP_CLI::success( 'Done.  Report was exported to WP_CONTENT_DIR.  Nothing was deleted, do by hand using WP_URL column in export.' );

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-export-from-mailchimp'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_export_from_mailchimp( $pos_args, $assoc_args ) {
		
		$import_batch = isset( $assoc_args[ 'import-batch' ] ) ? $assoc_args[ 'import-batch' ] : 'newsletters';
		
		if( 'newsletters' != $import_batch && ! preg_match( '/^missing-newsletters((-[0-9]+)?)/', $import_batch ) ) {
			WP_CLI::error( "Import batch type not allowed." );
		}

		WP_CLI::line( "Doing latinfinance-export-from-mailchimp for import-batch: " . $import_batch );

		global $wpdb;

		$csv_path = './sql-staging/newsletters/campaigns.csv';
		if( ! is_file( $csv_path ) ) {
			WP_CLI::error( 'Could not find CSV at path: ' . $csv_path );
		}
		
		// read
		$handle = fopen( $csv_path, 'r' );
		if ( $handle == FALSE ) {
			WP_CLI::error( 'Could not fopen CSV at path: ' . $csv_path );
		}

		$titles = array();
		$row_counter = -1;
		while ( ( $row = fgetcsv( $handle ) ) !== FALSE ) {
			
			$row_counter++;
			
			// skip header row
			if( $row_counter == 0 ) continue;

			// csv data integrity
			if( 40 != count( $row ) ) {
				WP_CLI::error( 'Error row column count mismatch: ' . print_r( $row, true ) );
			}

			// 0 = Title
			// 1 = Subject
			// 2 = Audience
			// 3 = Send Date
			// 4 = Send Weekday
			// 5 = Total Recipients

			$post_name = $row[0];
			$post_name = preg_replace('/(&|\')/', '-', $post_name );
			$post_name = preg_replace('/[^\x00-\x7F]/u', '-', $post_name ); // ’|ú|ã|É
			$post_name = sanitize_title( $post_name );

			if( empty( $titles[$post_name]) ) $titles[$post_name] = array();
			$titles[$post_name][] = array( $row[0], $row[3], $row[5] );

		}

		if( 'missing-newsletters' == $import_batch ) {
			// remove first element
			array_shift( $titles['bbva-m-xico-preps-sustainable-bond-brazil-s-bustling-short-term-debt-market-cresud-lines-up-bond'] );
			array_shift( $titles['ruta-la-mar-reaches-financial-close-minerva-aes-receive-early-tenders-ccr-polo-raise-reais'] );
			array_shift( $titles['argentina-shoots-for-imf-deal-by-year-end-fibra-educa-eyes-follow-on-green-bonds-mam-raises-stakes-for-alliar'] );
		}

		$this->mylog( 'newsletters-titles-from-csv', $titles );

		// print_r($titles);
		// exit();

		// close
		fclose($handle);

		$report = [
			'dates' => [],
		];

		$hack_data_mailchimp_dates = null;
		if( 'missing-newsletters' == $import_batch ) {
			$hack_data_mailchimp_dates = $this->hack_data_mailchimp_dates();	
		}
		else if( 'missing-newsletters-2' == $import_batch ) {
			$hack_data_mailchimp_dates = $this->hack_data_mailchimp_dates_2023_09_19();	
		}
		
		$path = "./sql-staging/newsletters/campaigns_content/";

		$files = glob( $path . '*.html' );
		foreach( $files as $filepath ) {

			if( preg_match( '/-(daily-brief-test|do-not-send).html$/', $filepath ) ) continue;
			if( preg_match( '/\/[0-9]+_test.html$/', $filepath ) ) continue;
			if( preg_match( '/\/[0-9]+_test-?[0-9]+.html$/', $filepath ) ) continue;
			
			// WP_CLI::line( $filepath );

			$contents = file_get_contents( $filepath );

			if( ! preg_match( '/>Daily Brief</', $contents ) ) continue;

			// echo $contents;

			// formats: Nov 1, 2017 | Nov, 1 2017 | 1 November 2017
			if( ! preg_match( '/>([A-Za-z]+,? [0-9]{1,2},? [0-9]{4}|[0-9]{1,2} [A-Za-z]+ [0-9]{4})</', $contents, $date_matches ) ) continue;

			if( empty( $date_matches[1] ) ) {
				$this->mylog('newsletters-no-date-found-in-file', $filepath );
				continue;
			}

			$dt = strtotime( str_replace(',', '', $date_matches[1] ) );
			if( false == $dt ) {
				WP_CLI::error( 'Strtotime issue.' );
			}

			$date = date("Y-m-d", $dt );

			if( preg_match( '/^missing-newsletters/', $import_batch ) && in_array( $date, $hack_data_mailchimp_dates ) ) continue;

			if( empty( $report['dates'][$date] ) ) $report['dates'][$date] = array();
			$report['dates'][$date][] = $filepath;
				
			// exit();

		} // each file

		if( 'missing-newsletters' == $import_batch ) {
			
			$this->mylog('newsletters-orphaned-date-file-pre', $report['dates'] );

			// full
			$orphan_date = '2017-11-01';
			$this->mylog('newsletters-orphaned-date-file', array( 'full', $orphan_date, $report['dates'][$orphan_date] ) );
			unset( $report['dates'][$orphan_date] );

			// keep first
			$keep_first = [ '2018-02-27', '2018-10-02', '2019-03-26', '2019-04-23', '2019-10-24', '2019-10-25', '2019-11-06', '2019-11-12', '2020-04-01', '2021-04-29', '2022-02-02', '2022-06-07', '2023-06-08', '2023-06-15', '2023-06-16', '2023-06-21', '2023-07-14', '2023-08-09', '2023-08-10', '2023-08-14', '2023-08-18', '2023-08-28' ];
			foreach( $keep_first as $orphan_date ) {
				$orphan_date_count = count( $report['dates'][$orphan_date] );
				for( $i = 1; $i < $orphan_date_count; $i++ ) {
					$this->mylog('newsletters-orphaned-date-file', array( 'keep-first', $orphan_date, $report['dates'][$orphan_date][$i] ) );
					unset( $report['dates'][$orphan_date][$i] );
				}
			}

			// keep last
			$keep_last = [ '2017-11-20', '2017-11-21', '2017-12-15', '2018-01-17', '2018-04-09', '2018-05-29', '2018-12-10', '2019-10-08', '2021-08-09', '2021-11-22', '2022-07-07', '2022-09-29', '2022-11-01', '2022-11-15', '2023-01-06', '2023-04-18' ];
			foreach( $keep_last as $orphan_date ) {
				$orphan_date_count = count( $report['dates'][$orphan_date] );
				for( $i = 0; $i < $orphan_date_count - 1; $i++ ) {
					$this->mylog('newsletters-orphaned-date-file', array( 'keep-last', $orphan_date, $report['dates'][$orphan_date][$i] ) );
					unset( $report['dates'][$orphan_date][$i] );
				}
				$report['dates'][$orphan_date] = array_values( $report['dates'][$orphan_date] );
			}

			$this->mylog('newsletters-orphaned-date-file-post', $report['dates'] );

		}

		// print_r( $report );

		// filter out any dates that have more than 1 file
		// todo: handle these by hand?
		$singles = array_filter( $report['dates'], function( $v, $n ) {
			
			if( 1 == count( $v ) ) return true;
			
			$this->mylog('newsletters-multiple-files-per-day', array( $n, $v ) );
			
			return false;

		}, ARRAY_FILTER_USE_BOTH );

		$to_process = array();

		// make sure each file has a title associated with it from the CSV
		foreach( $singles as $date => $file_arr ) {
			
			$file = $file_arr[0];
			$file = preg_replace( '#./sql-staging/newsletters/campaigns_content/[0-9]+_\-?#', '', $file );
			$file = preg_replace( '/\-?.html$/', '', $file );
			
			// make sure there is a title
			if( empty( $titles[$file] ) ) {
				
				$this->mylog( 'newsletters-no-title-found', array( $date, $file, $file_arr ) );
				continue;
			}

			// make sure just 1 title row was found in CSV
			if( 1 != count( $titles[$file] ) ) {
				$this->mylog( 'newsletters-found-muliple-title-rows', array( $date, $file, $file_arr, $titles[$file] ) );
				continue;
			}

			// check if postname exists
			$post_id = $wpdb->get_var( $wpdb->prepare("
				select ID
				from wp_posts
				where post_name = %s
			", array( $file ) ) );

			// post already exists
			if( $post_id > 0 ) {
				$this->mylog( 'newsletters-post-name-exists', array( $date, $file, $file_arr ) );
				continue;
			}

			// make sure csv row has proper values
			$title_row = $titles[$file][0];
			
			// good amount of send count
			if( $title_row[2] < 250 ) {
				$this->mylog( 'newsletters-send-count-too-low', array( $date, $file, $file_arr, $title_row ) );
				continue;
			}

			// make sure send date matches file parse date
			if( 'missing-newsletters' == $import_batch ) {

				if( '2015-07-24' == $date && 'invex-local-notes-isa-bonds-boc-looks-to-peru-mexican-pharma-gets-ifc-idb-funding' == $file ) {
					$date = '2018-07-25';
				}							
				else if( '2015-07-26' == $date && 'aleatica-lines-up-4g-loan-engie-sells-infra-bonds-in-brazil-santander-names-new-latam-ecm-head' == $file ) {
					$date = '2018-07-26';
				}							

			}

			if( ! preg_match( '/^' . date('M d, Y', strtotime( $date ) ) . '/', $title_row[1] ) ) {
				$this->mylog( 'newsletters-send-date-does-not-match', array( $date, $file, $file_arr, $title_row ) );
				continue;
			}


			$title_to_item = $title_row[0];
			$slug = $file;

			if( 'missing-newsletters' == $import_batch ) {
				if( '2018-12-10' == $date && 'copy-01' == $file ) {
					$title_to_item = 'Mexcat bondholders make demands - Credicorp raises equity fund - Global Bank closes syndicated loan';
					$slug = sanitize_title( $title_to_item );
				}				
			}

			$to_process[] = [
				'date' => $date,
				'file' => $file_arr[0],
				'title' => $title_to_item,
				'slug' => $slug,
			];

			// WP_CLI::line( $date . ' ' . $file . ' ' . print_r( $titles[$file], true ) );

		}
		
		// print_r($to_process);
		// exit();


		// process newest first
		$to_process = array_reverse( $to_process );

		$batch_size = 20;
		for ($i = 0; $i < count( $to_process ); $i += $batch_size) {
			$this->cmd_export_from_mailchimp_to_wxr( array_slice( $to_process, $i, $batch_size ), $i );
		}
		
		WP_CLI::success( 'Done' );

	
	}

	private function cmd_export_from_mailchimp_to_wxr( $to_process, $start_index ) {
		
		global $wpdb;

		// Setup data array WXR for post content
		$wxr_data = [
			'site_title'  => $this->site_title,
			'site_url'    => $this->site_url,
			'export_file' => '',
			'posts'       => [],
		];

				
		/*
		Item Array
			(
				[date] => 2023-08-28
				[file] => ./sql-staging/newsletters/campaigns_content/293481_brazil-plans-slb-debut-by-early-2024-unacem-buys-us-cement-plant-s-p-says-third-guacolda-debt-swap-possible.txt
				[title] => Brazil plans SLB debut by early 2024 - Unacem buys US cement plant - S&P says third Guacolda debt swap possible
				[slug] => brazil-plans-slb-debut-by-early-2024-unacem-buys-us-cement-plant-s-p-says-third-guacolda-debt-swap-possible
			)
		*/
		foreach( $to_process as $item ) {
			
			// print_r($item);

			// parse the file
			$content = trim( file_get_contents( $item['file'] ) );

			$content = trim( preg_replace( '/^.*?<body/s', '', $content ) );
			$content = trim( preg_replace( '/^.*?>/', '', $content ) );

			$content = trim( preg_replace( '/<\/html>$/', '', $content ) );
			$content = trim( preg_replace( '/<\/body>$/', '', $content ) );

			$content = trim( preg_replace( '/<tr>.*?Not displaying correctly.*?<\/tr>/s', '', $content ) );
			
		// 	<tr>
		// 	<td class="align-center" colspan="2" style="word-break: break-word;vertical-align: top;text-align: center;font-family: Helvetica, Arial, sans-serif;box-sizing: border-box;">
		// 		Not displaying correctly? <a href="*|ARCHIVE|*" style="color: #0886b6;text-decoration: none;font-family: Helvetica, Arial, sans-serif;box-sizing: border-box;">View this email online</a><br><br><br>
		// 		<img class="email-masthead_logo" src="https://www.latinfinance.com/images/lf-logo.png" style="font-family: Helvetica, Arial, sans-serif;box-sizing: border-box;">
		// 	</td>
		// </tr>



			if( ! preg_match( '/^<div class="aspNetHidden"/', $content ) ) {
				$this->mylog( 'newsletters-html-starting-issues', $item );
				continue;
			}

			if( ! preg_match( '/<\/div>$/', $content ) ) {
				$this->mylog( 'newsletters-html-ending-issues', $item );
				continue;
			}


			// get links to replace
			preg_match_all( '/<h2.*?href="([^"]+)".*?>([^>]+)<.*?\n.*?>([^<]+)<\/p>/', $content, $matches, PREG_SET_ORDER );

			if( count( $matches ) < 5 ) {
				$this->mylog( 'newsletters-html-link-count-issue', array( $item, $matches ) );
				continue;
			}

			// 	[0] => <h2 style="margin-top: 0;color: #0886b6;font-size: 18px;font-weight: bold;margin-bottom: 5px;font-family: Calibri Light, Calibri, Helvetica, Arial !important;box-sizing: border-box;"><a href="https://www.latinfinance.com/daily-briefs/2023/8/25/citi-buys-into-peru-fx-fintech" style="color: #0886b6;text-decoration: none;font-family: Helvetica, Arial, sans-serif;box-sizing: border-box;">Citi buys into Peru FX fintech</a></h2>
			// 																		<p style="line-height: 1.5em;text-align: left;margin-top: 0;font-family: Helvetica, Arial, sans-serif;box-sizing: border-box;">US lender invests in currency exchange platform Rextie</p>
			// 	[1] => https://www.latinfinance.com/daily-briefs/2023/8/25/citi-buys-into-peru-fx-fintech
			// 	[2] => Citi buys into Peru FX fintech
			// 	[3] => US lender invests in currency exchange platform Rextie

			// print_r($matches); 
			

			// $this->mylog( 'newsletters-content-before', $content );


			foreach( $matches as $match ) {

				// get post based on url
				$url_path = parse_url( $match[1], PHP_URL_PATH );
				// WP_CLI::line( $url_path );

				$post_id = url_to_postid( $url_path );
				
				if( ! ( $post_id > 0 ) ) {
					// try to replace the end of the url with a newly sanitized title
					$url_path_2 = preg_replace( '/[^\/]+$/', sanitize_title( $match[2] ), preg_replace( '/\/$/', '', $url_path ) );
					// WP_CLI::line($url_path_2);
					$post_id = url_to_postid( $url_path_2 );
				}

				if( ! ( $post_id > 0 ) ) {
					$sql = $wpdb->prepare("
						select ID from wp_posts 
						where post_name like %s
						and DATE_FORMAT(post_date, %s) = %s
					", array( sanitize_title( $match[2] ) . '%', '%Y-%m-%d', $item['date'] ) );
					// echo $sql;
					$post_id = $wpdb->get_var( $sql );
					// echo $post_id;
					// exit();
				}

				if( ! ( $post_id > 0 ) ) {
					// WP_CLI::warning( 'No post id based on match url: ' . $url_path );
					$content = str_replace( $match[0], '<!--newspack_lf_newsletters_no_post_id-->' . $match[0] . '<!--/newspack_lf_newsletters_no_post_id-->', $content );
					continue;
				}

				// replace with a shortcode
				$replacement = str_replace( '>' . $match[3] . '<', '><!--' . $match[3] . '--></p>[newsletters_lf_insert_post_content id=' . $post_id . ']<p><', $match[0] );
				$content = str_replace( $match[0], $replacement, $content );


			} // each h2 match

			// prepend an identifiter just incase
			$content = '<!--newspack_lf_newsletters_mailchimp_import-->
				<style>
				table.email-wrapper {
					font-size: var(--newspack-theme-font-size-md);
				}
			  	table td {
			  		border: none;
				}
				</style>' . $content;

			// Add values to a single post array
			$wxr_data['posts'][] = [
				
				'title'   => $item['title'],
				'url'    => $item['slug'],
				'content' => $content,
				'excerpt' => '',
				'author'  => 'LatinFinance',
				'date'    => $item['date'],
				'meta'    => [
					
					// 'background_color' => '#efefef',
					'is_public' => '1',
					// 'newsletter_sent' => '1',
					// 'senderName' => 'LatinFinance',
					// 'template_id' => '69981',

					'newspack_lf_import_item' => json_encode( $item ),
					'newspack_lf_import_batch' => 'newsletters',
					'newspack_lf_slug' => $item['slug'],

				],

			]; // post

			// sanity log
			$this->mylog( 'newsletters-wxr-sanity', json_encode( $item ) );

		} // each item


		$fileslug = $this->export_path  . '/latinfinance-newsletters-' . $start_index;
		$wxr_data['export_file'] = $fileslug . '.xml';

		// Create WXR file
		Newspack_WXR_Exporter::generate_export( $wxr_data );
		WP_CLI::success( sprintf( "\n" . 'Posts exported to file %s ...', $wxr_data['export_file'] ) );

	}





	/**
	 * Callable for 'newspack-content-migrator latinfinance-export-from-mssql'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_export_from_mssql( $pos_args, $assoc_args ) {
		
		WP_CLI::line( "Doing latinfinance-export-from-mssql..." );
		
		// Setup MSSQL DB connection
		$this->set_pdo();

		// Load all Authors and Tags (we'll convert to Categories) from the MSSQL DB
		$this->set_authors();
		$this->set_tags();
		$this->set_tags_parent_slugs();

		// Set previous checksums lookup array (if file exists)
		$this->set_previous_checksums();
		$this->prev_categories = $this->hack_prev_category_urls();
				
		// Setup query vars for MSSQL DB for content types
		$limit = 100; // 100; row limit per batch
		$start_id = 1; // 1; rows greater than or equal to this ID value
		$max_id = 2147483647; // useful if upper range is needed. max Int for SQL Server = 2147483647

		// Export posts while return value isn't null
		// ...and set the new start_id equal to the returned id (last id processed) plus 1
		while( null !== ( $start_id = $this->export_posts( $limit, $start_id, $max_id ) ) ) {
			$start_id += 1;
		}
		
		// Check for duplicate post slugs
		// let WordPress handle this and test by hand
		// foreach ( $this->post_slugs as $slug => $urls ) {
		// 	if( count( $urls ) > 1 ) {
		// 		WP_CLI::warning( 'Duplicate content "' . $slug .'" for urls: ' . print_r( $urls, true ) );
		// 	}
		// }

		// clean up duplicate authors after import: $this->check_author_emails();
		$this->log_to_csv( $this->authors, $this->export_path  . '/latinfinance-authors.csv');

		// clean up duplicate tags after import: $this->check_tags_slugs();
		// tag descriptions can be recreated by hand if desired
		$this->log_to_csv( $this->tags, $this->export_path  . '/latinfinance-tags.csv');

		// export categories for first time only. For Launch import, do by hand using log file
		if( 0 == count( $this->prev_checksums ) ) $this->export_categories();

		// log checksum match report
		foreach( $this->prev_checksums as $k => $v ) {
			if( 0 == $v[2] ) {
				$this->mylog( 'checksum-deleted', array( $k, $v ) );
			}
			else if( $v[2] > 1 ) {
				$this->mylog( 'checksum-multiple', array( $k, $v ) );
			}
		}
	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-fix-duplicate-categories'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_duplicate_categories( $pos_args, $assoc_args ) {

		// display "do by hand" notice
		WP_CLI::line( "\n" . 'You may need to do the following by hand if not already done:' );
		WP_CLI::line( '- change existing category "Energy > Hydro" slug to "energy-hydro"' );
		WP_CLI::line( '- create new category "Wind" with parent "Energy" and slug "energy-wind"' );
		WP_CLI::line( '- create new category "Solar" with parent "Energy" and slug "energy-solar"' );
		WP_CLI::line( '- create new category "Hydro" with parent "ESG" and slug "hydro"' . "\n");

		// try to proceed
		$topics_id = $this->get_wp_category_id_else_die( 'topics', 0 );
		$energy_id = $this->get_wp_category_id_else_die( 'energy', $topics_id );
		$esg_id = $this->get_wp_category_id_else_die( 'esg', $topics_id );

		$cats = [
			[
				'id' => $this->get_wp_category_id_else_die( 'energy-hydro', $energy_id ),
				'old_path' => '/topics/energy/hydro',
			],
			[
				'id' => $this->get_wp_category_id_else_die( 'energy-solar', $energy_id ),
				'old_path' => '/topics/energy/solar',
			],
			[
				'id' => $this->get_wp_category_id_else_die( 'energy-wind', $energy_id ),
				'old_path' => '/topics/energy/wind',
			],
			[
				'id' => $this->get_wp_category_id_else_die( 'hydro', $esg_id ),
				'old_path' => '/topics/esg/hydro',
			],
			[
				'id' => $this->get_wp_category_id_else_die( 'solar', $esg_id ),
				'old_path' => '/topics/esg/solar',
			],
			[
				'id' => $this->get_wp_category_id_else_die( 'wind', $esg_id ),
				'old_path' => '/topics/esg/wind',
			],
		];

		WP_CLI::line( 'All categories found!  Proceeding...' );

		foreach( $cats as $cat ) {
			$this->set_correct_wp_category( $cat['id'], $cat['old_path'] );
		}

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-fix-images-in-content'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_fix_images_in_content( $pos_args, $assoc_args ) {

		WP_CLI::line( "Doing latinfinance-fix-images-in-content..." );

		global $wpdb;

		$report = [
			'posts-with-images' 		=> 0,
			'img-base64-skipped'		=> 0,
			'img-old-assets-removed' 	=> 0,
			// 'img-old-assets-skipped' 	=> 0,
			'img-off-site-skipped'	 	=> 0,
			'img-off-site-urls'	 		=> [],
			'img-staging-skipped'	 	=> 0,
			'fixed'						=> 0,
			'fix-by-hand'				=> [],
		];

		// select all post content with images in the content
		$results = $wpdb->get_results( "
			SELECT p.ID, p.post_content
			FROM {$wpdb->posts} p
			JOIN {$wpdb->postmeta} pm 
				on pm.post_id = p.ID
				and pm.meta_key = 'newspack_lf_import_batch'
				and pm.meta_value = 'missing'
			WHERE p.post_content like '%<img%'		
		");
	
		$report['posts-with-images'] = count( $results );			
		WP_CLI::line( 'Found ' . $report['posts-with-images'] . ' posts with images.' );

		foreach( $results as $row ) {
			
			// get full img element
			preg_match_all( '/<img[^<]*?src=[^>]*>/i', $row->post_content, $matches );

			// die on error
			if( empty( $matches ) || count( $matches[0] ) == 0 ) {
				WP_CLI::error( 'Preg match all returned no results for post ' . $row->ID );
			}

			$this->set_images_in_content( $row->ID, $matches[0], $report );

		}

		print_r( $report );

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-set-coauthors-plus'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_set_coauthors_plus( $pos_args, $assoc_args ) {

		$posts_per_batch = isset( $assoc_args[ 'posts-per-batch' ] ) ? (int) $assoc_args['posts-per-batch'] : 100;
		$batches = isset( $assoc_args[ 'batches' ] ) ? (int) $assoc_args['batches'] : -1;

		if( -1 > $posts_per_batch ) {
			WP_CLI::error( "Posts per batch argument must be -1 or greater." );
		}

		if( -1 > $batches ) {
			WP_CLI::error( "Batches argument must be -1 or greater." );
		}

		WP_CLI::line( "Doing latinfinance-set-coauthors-plus..." );

		$this->coauthorsplus_logic = new CoAuthorPlus();

		// Install and activate the CAP plugin if missing.
		if ( false === $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin must be activated. Run: wp plugin install co-authors-plus --activate' );
		}

		// clear blank authors meta (json [] empty array) that may have been inserted during import
		delete_metadata( 'post', null, 'newspack_lf_authors', '[]', true );

		// do each batch
		for( $i = $batches; $i > 0 || $batches == -1;  $i-- ) {

			$count = $this->set_coauthors_plus( $posts_per_batch );

			if( 0 === $count ) {
				WP_CLI::line('No more rows to process.');
				break; // stop if no more rows
			}

			WP_CLI::line('Processed ' . $count . ' rows...');

		}

		WP_CLI::success( 'Done' );

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-set-coauthors-plus-launch'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_set_coauthors_plus_launch( $pos_args, $assoc_args ) {

		$posts_per_batch = isset( $assoc_args[ 'posts-per-batch' ] ) ? (int) $assoc_args['posts-per-batch'] : 100;
		$batches = isset( $assoc_args[ 'batches' ] ) ? (int) $assoc_args['batches'] : -1;

		if( -1 > $posts_per_batch ) {
			WP_CLI::error( "Posts per batch argument must be -1 or greater." );
		}

		if( -1 > $batches ) {
			WP_CLI::error( "Batches argument must be -1 or greater." );
		}

		WP_CLI::line( "Doing latinfinance-set-coauthors-plus-launch..." );

		$this->coauthorsplus_logic = new CoAuthorPlus();

		// Install and activate the CAP plugin if missing.
		if ( false === $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( 'Co-Authors Plus plugin must be activated. Run: wp plugin install co-authors-plus --activate' );
		}

		// clear blank authors meta (json [] empty array) that may have been inserted during import
		delete_metadata( 'post', null, 'newspack_lf_authors', '[]', true );

		// do each batch
		for( $i = $batches; $i > 0 || $batches == -1;  $i-- ) {

			$count = $this->set_coauthors_plus_launch( $posts_per_batch );

			if( 0 === $count ) {
				WP_CLI::line('No more rows to process.');
				break; // stop if no more rows
			}

			WP_CLI::line('Processed ' . $count . ' rows...');

		}

		WP_CLI::success( 'Done' );

	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-set-primary-categories'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_set_primary_categories( $pos_args, $assoc_args ) {

		$posts_per_batch = isset( $assoc_args[ 'posts-per-batch' ] ) ? (int) $assoc_args['posts-per-batch'] : 100;
		$batches = isset( $assoc_args[ 'batches' ] ) ? (int) $assoc_args['batches'] : -1;

		if( -1 > $posts_per_batch ) {
			WP_CLI::error( "Posts per batch argument must be -1 or greater." );
		}

		if( -1 > $batches ) {
			WP_CLI::error( "Batches argument must be -1 or greater." );
		}

		WP_CLI::line( "Doing latinfinance-set-primary-categories..." );

		// do each batch
		for( $i = $batches; $i > 0 || $batches == -1;  $i-- ) {

			$count = $this->set_primary_categories( $posts_per_batch );

			if( 0 === $count ) {
				WP_CLI::line('No more rows to process.');
				break; // stop if no more rows
			}

			WP_CLI::line('Processed ' . $count . ' rows...');

		}

		WP_CLI::success( 'Done' );
	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-set-subtitles'.
	 *
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_set_subtitles( $pos_args, $assoc_args ) {

		global $wpdb;

		WP_CLI::line( "Doing latinfinance-set-subtitles..." );

		$data = $wpdb->get_results( "
			select p.ID, p.post_excerpt, pm2.meta_value
			from $wpdb->posts p
			join $wpdb->postmeta pm1 on pm1.post_id = p.ID and pm1.meta_key = 'newspack_lf_import_batch' and pm1.meta_value = 'missing'
			left outer join $wpdb->postmeta pm2 on pm2.post_id = p.ID and pm2.meta_key = 'newspack_post_subtitle'
			where p.post_type='post' AND p.post_status = 'publish' AND p.post_excerpt != ''
			HAVING pm2.meta_value is null
		", ARRAY_A );

		foreach ( $data as $post_data ) {
		
			if ( empty( trim( $post_data['post_excerpt'] ) ) ) {
				WP_CLI::warning( 'Skipping blank trimmed excerpt' );
				continue;
			}

			update_post_meta( $post_data['ID'], 'newspack_post_subtitle', $post_data['post_excerpt'] );

			WP_CLI::line( 'Updated: ' . $post_data['ID'] );
		
		}

		wp_cache_flush();

		WP_CLI::success( 'Done.' );

	}


	/**
	 * Exports
	 * 
	 */

	private function export_categories() {

		$terms = $this->get_tags_as_terms();

		if( empty( $terms ) ) {
			WP_CLI::warning( 'No terms to export' );
			return;
		}

		// Append Categories as terms to WXR <channel>
		$data = [
			'site_title'  => $this->site_title,
			'site_url'    => $this->site_url,
			'export_file' => $this->export_path  . '/latinfinance-categories.xml',
			'posts'       => [],
			'terms'       => $terms,
		];
		
		// requires Newspack_WXR_Exporter: line 52 => // die( "Missing data to generate export file" );
		Newspack_WXR_Exporter::generate_export( $data );
		WP_CLI::success( sprintf( "\n" . 'Categories exported to file %s ...', $data[ 'export_file' ] ) );

	}

	// returns null or the last id (integer) of nodeId that was processed
	private function export_posts( $limit, $start_id, $max_id ) {

		die('need to fix category daily-briefs renamed to daily-brief');

		$newspack_lf_import_batch = ( count( $this->prev_checksums ) > 0 ) ? 'launch' : 'initial';

		// Setup data array WXR for post content
		$data = [
			'site_title'  => $this->site_title,
			'site_url'    => $this->site_url,
			'export_file' => '',
			'posts'       => [],
		];

		// Get published posts for the content types
		$result = $this->pdo->prepare("
			SELECT TOP " . intval( $limit ) . "
				cmsDocument.nodeId, cmsDocument.versionId, cmsDocument.expireDate,
				cmsContentXML.xml
			FROM cmsDocument
			JOIN cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			JOIN cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			JOIN cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias in('dailyBriefArticle', 'magazineArticle', 'webArticle')
			WHERE cmsDocument.published = 1	
			AND cmsDocument.nodeId >= " . intval( $start_id ) . "
			AND cmsDocument.nodeId <= " . intval( $max_id ) . "
			ORDER BY cmsDocument.nodeId
		");
		$result->execute();

		// updated:
		// AND cmsDocument.nodeId in(67966, 70586, 70616, 70648, 70670, 70777, 70858, 70992, 71008, 71031, 71046, 71069)
		// $newspack_lf_import_batch = 'updated';

		// Missing:
		// AND cmsDocument.nodeId in(44823, 36551, 49257, 40958, 6671, 5562, 6667, 6046, 44741, 44743, 45262, 36175, 44745, 46123, 46125, 44750, 44752, 45495, 45496, 44809, 6785, 35875, 36023, 44893, 44761, 44762, 5678, 6064, 9850, 36259, 35334, 46148, 46149, 47079, 5513, 44764, 44765, 44766, 44767, 44794, 45509, 45510, 44768, 44769, 44770, 36115, 45091, 46681, 44772, 44773, 6646)
		// $newspack_lf_import_batch = 'missing';


		// keep track of last row processed
		$last_id = null;

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			// load xml column
			$xml = simplexml_load_string( $row['xml'] );
			
			// handle existing checksums
			$checksum = md5( serialize( $row ) );
			if( 'launch' == $newspack_lf_import_batch && $this->has_existing_checksum( (string) $row['nodeId'], (string) $row['versionId'], $checksum, $row ) ) {
				WP_CLI::line( 'Exists: ' . $row['nodeId'] );
				$last_id = (int) $row['nodeId'];
				continue;
			}

			WP_CLI::line( 'New: ' . $row['nodeId'] );
						
			// get info for this post
			$authors = $this->get_authors_and_increment( (string) $xml->authors );
			$categories = $this->get_cats_and_increment( (string) $xml->tags );

			// set slug and old site url
			$slug = (string) $xml['urlName'];
			$url_from_path = $this->get_url_from_path( (string) $xml['path'] );

			// Track dublicate content slugs
			if( isset( $this->post_slugs[$slug] ) ) {
				$this->post_slugs[$slug][] = $url_from_path;
			}
			else {
				$this->post_slugs[$slug] = array( $url_from_path );
			}
			
			// Test expireDate
			if( null !== $row['expireDate'] ) {
				WP_CLI::warning( 'Post expireDate exists "' . $row['expireDate'] .'" for node ' . $row['nodeId']);
			}

			// Logs for Launch that could help debugging WXR uploads
			if( count( $this->prev_checksums ) > 0 ) {
				$this->log_launch_helpers( $categories, (string) $xml['nodeName'], (string) $xml->displayDate, $slug, $row );				
			}
			
			// Add values to a single post array
			$post = [

				'title'   => (string) $xml['nodeName'],
				'url'    => $slug,
				'content' => (string) $xml->body,
				'excerpt' => (string) $xml->snippet,
				'categories' => $categories['basic'],

				// WXR <wp:author><wp:author_login> will create user accounts
				// but <item><dc:creator> doesn't support multiple authors so no point in creating user accounts...
				// do this post migration using: postmeta.newspack_lf_authors
				'author'  => 'LF Import User', // $authors['basic']

				// just use one date value, ignore createDate
				'date'    => (string) $xml->displayDate,

				// Convert to tags: <metaKeywords><![CDATA[Arcos Dorados, McDonald's, Argentina]]></metaKeywords>
				'tags' => preg_split( '/,\s*/', trim( (string) $xml->metaKeywords ), -1, PREG_SPLIT_NO_EMPTY ),

				'meta'    => [

					// these will be converted to Guest Authors in CoAuthorsPlus
					'newspack_lf_authors' => json_encode( $authors['full'] ),
					
					// could be used to clean up an duplicate category issues
					'newspack_lf_categories' => json_encode( $categories['full'] ),

					// this will be used for yoast primary category
					'newspack_lf_content_type' => (string) $xml['nodeTypeAlias'],

					// helpful to catch changes in future imports
					'newspack_lf_node_id' => (string) $row['nodeId'],
					'newspack_lf_version_id' => (string) $row['versionId'],
					'newspack_lf_checksum' => $checksum,
					'newspack_lf_import_batch' => $newspack_lf_import_batch,

					// helpful for redirects if needed
					'newspack_lf_slug' => $slug,
					'newspack_lf_url' => $url_from_path,

				],
							
			]; // post

			// Add "content type" category
			switch( (string) $xml['nodeTypeAlias'] ) {
				case 'dailyBriefArticle': $post['categories'][] = 'Daily Briefs'; break;
				case 'magazineArticle': $post['categories'][] = 'Magazine'; break;
				case 'webArticle': $post['categories'][] = 'Web Articles'; break;
			}
			
			// Add additional category
			if ( (int) $xml->isFree === 1 ) {
				$post['categories'][] = 'Free Content';
			}

			// Featured image: <image><![CDATA[64047]]></image>
			if( ! empty ( $xml->image ) ) {

				// must be single integer
				if( ! preg_match('/^[0-9]+$/', (string) $xml->image ) ) {
					WP_CLI::error( 'Featured image is not single integer ' . (string) $xml->image .' for node ' . $row['nodeId']);
				}
				
				$featured_image = $this->get_featured_image( (string) $xml->image );

				if( null !== $featured_image ) {

					$post['featured_image'] = $featured_image['url'];
					$post['meta']['newspack_lf_featured_image'] = $featured_image['url'];
					$post['meta']['newspack_lf_featured_image_node'] = json_encode( $featured_image );
				
				} // null featured image

			} // xml->image
			
			// Append to data posts
			$data['posts'][] = $post;

			// increment last id processed
			$last_id = (int) $row['nodeId'];

		} // while content

		// return from function at this point if no rows were processed
		// todo: set a return line above the while loop if PDO->rowCount() could return a consistant "0 results" row count...
		if( $last_id === null ) return null;

		// return if the entire post data array is empty...this could happen with checksum matches
		if( 0 == count( $data['posts'] ) ) {
			return $last_id;	
		}

		$fileslug = $this->export_path  . '/latinfinance-posts-' . $start_id . '-' . $last_id;
		$data['export_file'] = $fileslug . '.xml';

		// Create WXR file
		// $this->log_to_dump( $data['posts'], $fileslug . '-dump.txt');
		Newspack_WXR_Exporter::generate_export( $data );
		WP_CLI::success( sprintf( "\n" . 'Posts exported to file %s ...', $data['export_file'] ) );

		return $last_id;

	}


	/**
	 * Checks
	 *
	 */
	
	private function check_author_emails() {

		$emails = array();

		foreach( $this->authors as $id => $node ) {
			
			// must have post content
			if( $node['post_count'] === 0 ) continue;

			// email to test
			$email = $node['email'];

			if( empty($email) ) continue;

			// test if already exists (can't have duplicate email addresses)
			if( isset( $emails[$email] ) ) {
				$emails[$email]++;
				WP_CLI::warning( 'Duplicate email "' . $email .'" for node: ' . print_r( $node , true) );				
			}
			else {
				$emails[$email] = 1;
			}
		
		}
	}

	private function check_tags_slugs() {

		$slugs = array();

		foreach( $this->tags as $id => $node ) {
			
			// must have post content
			if( $node['post_count'] === 0 ) continue;

			// slug to test
			$slug = $node['slug'];

			// test if already exists (can't have duplicate tag ("category") slugs)
			if( isset( $slugs[$slug] ) ) {
				$slugs[$slug]++;
				WP_CLI::warning( 'Duplicate tag (category) "' . $slug .'" for node: ' . print_r( $node , true) );				
			}
			else {
				$slugs[$slug] = 1;
			}
		
		}
	}

	private function has_existing_checksum( $id, $version, $checksum, $log_data ) {

		if( isset( $this->prev_checksums[$id] ) ) {

			// count matches
			$this->prev_checksums[$id][2]++;
			
			// log any mismatches
			if( $version != $this->prev_checksums[$id][0] ) {
				$this->mylog( 'checksum-version-mismatch', $log_data );
			}

			if( $checksum != $this->prev_checksums[$id][1] ) {
				$this->mylog( 'checksum-value-mismatch', $log_data );
			}

			return true;
		}

		return false;
	}


	/**
	 * Deletes
	 * 
	 */

	 private function delete_duplicate_meta( $limit, &$report ) {
		
		global $wpdb;

		// get postmeta where meta key is duplicated for the same post
		$results = $wpdb->get_results( $wpdb->prepare("
			SELECT post_id, meta_key, meta_value,
				min(meta_id) as min_id, max(meta_id) max_id, count(*) as count
			FROM {$wpdb->postmeta}
			GROUP BY post_id, meta_key, meta_value
			HAVING min_id <> max_id
			LIMIT %d",
			$limit
		));

		foreach ( $results as $row ) {

			if( $row->min_id == $row->max_id ) {
				WP_CLI::error( 'Error deleting where min-max are equal' . print_r( $row, true ) );
			}
			if( 2 != $row->count ) {
				WP_CLI::error( 'Error deleting where count is not 2 ' . print_r( $row, true ) );
			}

			// delete one of the meta_ids...just do the max id...
			$wpdb->query( $wpdb->prepare( "
				DELETE FROM {$wpdb->postmeta} WHERE meta_id = %d",
				$row->max_id
			));
		
			if( isset( $report[$row->post_id] ) ) {
				$report[$row->post_id]++;
			}
			else {
				$report[$row->post_id] = 1;
			}
		}

		return count( $results );

	}


	/**
	 * Getters
	 *
	 */

	// null
	// single id
	// id,id,id
	private function get_authors_and_increment( $node ) {

		$basic = array();
		$full = array();

		if( ! empty( $node ) ) {

			$ids = explode(',', $node );
			foreach( $ids as $id ) {

				// if the author doesn't exist, we can't do anything...just continue
				if( empty( $this->authors[$id] ) ) continue;

				// only add matching key/values to each output
				$basic[] = array_intersect_key( $this->authors[$id], array( 'name' => 1, 'email' => 1) );
				$full[] = array_intersect_key( $this->authors[$id], array( 'id' => 1, 'name' => 1, 'email' => 1, 'slug' => 1) );
				$this->authors[$id]['post_count']++;

			}

		} // not empty

		return [
			'basic' => $basic,
			'full' => $full,
		];

	}

	// null
	// single id
	// id,id,id
	private function get_cats_and_increment( $node ) {

		$basic = array();
		$full = array();

		if( ! empty( $node ) ) {

			$ids = explode(',', $node );		
			foreach( $ids as $id ) {
				$basic[] = array_intersect_key( $this->tags[$id], array( 'name' => 1, 'slug' => 1) );
				$full[] = array_intersect_key( $this->tags[$id], array( 'id' => 1, 'name' => 1, 'slug' => 1, 'parent' => 1, 'parent_slug' => 1, 'url' => 1) );
				$this->tags[$id]['post_count']++;
			}

		} // not empty

		return [
			'basic' => $basic,
			'full' => $full,
		];

	}

	/*
		<Image id="1333" key="d0c96cf0-bb9d-44aa-8122-bd1fee73617c" parentID="1156" level="3" creatorID="0" sortOrder="3" createDate="2017-09-05T13:30:33" updateDate="2018-11-13T15:00:15" nodeName="2013Oscars_Hagenbuch_Academy.jpg" urlName="2013oscars_hagenbuch_academyjpg" path="-1,53377,1156,1333" isDoc="" nodeType="1032" writerName="bgilbert@w3trends.com" writerID="0" version="cd8c6c5e-fd3d-4968-9b4f-12bd60d91302" template="0" nodeTypeAlias="Image"><umbracoFile><![CDATA[{src: '/media/1004/2013oscars_hagenbuch_academy.jpg', crops: []}]]></umbracoFile><umbracoWidth><![CDATA[1826]]></umbracoWidth><umbracoHeight><![CDATA[1323]]></umbracoHeight><umbracoBytes><![CDATA[190068]]></umbracoBytes><umbracoExtension><![CDATA[jpg]]></umbracoExtension></Image>

		<Image id="68831" key="45555c24-8d66-46fa-bcea-13c153aca843" parentID="68829" level="4" creatorID="32" sortOrder="1" createDate="2022-10-04T04:31:20" updateDate="2022-10-05T16:35:42" nodeName="Cover-Q4 500px LF.com.jpg" urlName="cover-q4-500px-lfcomjpg" path="-1,50126,65838,68829,68831" isDoc="" nodeType="1032" writerName="taimur.ahmad@latinfinance.com" writerID="32" version="257e4df4-caf7-462f-9536-73842c72a993" template="0" nodeTypeAlias="Image"><umbracoFile><![CDATA[{
			"src": "/media/5556/cover-q4-500px-lfcom.jpg",
			"focalPoint": {
				"left": 0.5,
				"top": 0.5
			}
			}]]></umbracoFile><umbracoWidth><![CDATA[786]]></umbracoWidth><umbracoHeight><![CDATA[1056]]></umbracoHeight><umbracoBytes><![CDATA[291127]]></umbracoBytes><umbracoExtension><![CDATA[jpg]]></umbracoExtension></Image>

		<File id="57037" key="7380a79f-94c2-497e-9715-4c685e2e9931" parentID="3210" level="2" creatorID="20" sortOrder="189" createDate="2019-09-26T20:19:10" updateDate="2019-09-26T20:19:10" nodeName="Argentina - map image.jfif (1)" urlName="argentina-map-imagejfif-1" path="-1,3210,57037" isDoc="" nodeType="1033" writerName="daniel.bases@latinfinance.com" writerID="20" version="e3930c55-3628-4fdc-a838-3c9fcb9eb6a0" template="0" nodeTypeAlias="File"><umbracoFile><![CDATA[/media/2177/argentina-map-image.jfif]]></umbracoFile><umbracoExtension><![CDATA[jfif]]></umbracoExtension><umbracoBytes><![CDATA[6500]]></umbracoBytes></File>


		<umbracoFile><![CDATA[{src: '/media/6252/casa-dos-ventos-rio-do-vento.jpg', crops: []}]]>
		https://www.latinfinance.com/media/6252/casa-dos-ventos-rio-do-vento.jpg
		
		<umbracoFile><![CDATA[{src: '/media/6296/iberdrola-mexico-cogeneración-bajío-power-plant.png', crops: []}]]></umbracoFile>
		https://www.latinfinance.com/media/6296/iberdrola-mexico-cogeneraci%C3%B3n-baj%C3%ADo-power-plant.png

		<umbracoFile><![CDATA[{src: '/media/2133/avianca_767-200_at_el_dorado.jpg', crops: []}]]></umbracoFile>
		https://www.latinfinance.com/media/2133/avianca_767-200_at_el_dorado.jpg
	*/
	private function get_featured_image( $node_id ) {

		$result = $this->pdo->prepare("
			SELECT xml
			FROM cmsContentXml
			WHERE nodeId = ?
		");
		$result->execute( array( $node_id ) );
			
		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   
		
			$xml = simplexml_load_string( $row['xml'] );

			$url = null;

			// for <Image nodes
			switch ( (int) $xml['nodeType'] ) {

				// <Image nodes
				case 1032:

					// umbracoFile may not be proper JSON in some cases, so use preg_match
					preg_match('/src[\'"]?: [\'\"]([^\'\"]+)[\'\"]/', (string) $xml->umbracoFile, $image_matches);
					
					if( 2 != count( $image_matches ) ) {
						WP_CLI::error( 'Malformated featured image url for node: ' . print_r( $row['xml'] , true) );				
					}

					$url = $image_matches[1];

					break;
				
				// <File nodes
				case 1033:
					$url = (string) $xml->umbracoFile;
					break;

			} // switch

			if ( null === $url ) {
				WP_CLI::error( 'Unknow featured image node type: ' . print_r( $row['xml'] , true) );				
			}

			return [
				'id' => (string) $xml['id'],
				'name' => (string) $xml['nodeName'],
				'url' => $url,
				'xml' => $row['xml'], // for checksum and postmeta
			];
		
		}  

		return null;

	}

	private function get_tags_as_terms() {
		
		$terms = array();
		
		foreach( $this->tags as $id => $tag ) {

			// only create terms if used for a post
			// clean up by hand after import: if( $tag['post_count'] === 0 ) continue;

			$term = new stdClass();
			$term->taxonomy = 'category';
			$term->name = $tag['name'];
			$term->slug = $tag['slug'];
			$term->parent = $tag['parent'];
			
			$terms[ $id ] = $term;

		}

		return $terms;

	}

	// example: "-1,1051,1080,32617,32834,32876,32886" (last element is current element)
	// used by content and tags
	private function get_url_from_path( $path ) {
	
		// remove the -1 and Home path
		$path = preg_replace('/^-1,1051,/', '', $path);

		$nodes = explode(',', $path );

		$result = $this->pdo->prepare("
			SELECT
			CAST(cmsContentXml.xml as xml).value('(/*/@urlName)[1]', 'varchar(max)') as urlName,
			CAST(cmsContentXml.xml as xml).value('(/*/@level)[1]', 'int') as level
			FROM cmsContentXml
			WHERE nodeId in(" . implode(',', array_fill(0, count($nodes), '?')) . ")
			ORDER by level
		");
		$result->execute( $nodes );

		$url = '';
		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   
			$url .= '/' . $row['urlName'];
		}  

		return $url;

	}

	private function get_wp_category_id_else_die( $slug, $parent_id = null ) {
		
		$cat = term_exists( $slug, 'category', $parent_id );
		
		if( null === $cat ) {
			WP_CLI::error( 'WP category not found for slug "' . $slug . '" and parent_id "' . $parent_id . '"' );
		}

		return $cat['term_id'];

	}


	/**
	 * Logging
	 *
	 */

	 private function log_to_csv( $data, $path, $dimensions = 'two' ) {
		
		$file = fopen($path, 'w');
		
		// simple array
		if( 'single-with-keys' == $dimensions ) {
			foreach ($data as $key=>$value) {
				fputcsv($file, array($key, $value));
			}
		}
		// array of array
		else if( 'two' == $dimensions ) {
			$header = array_keys(reset($data));
			fputcsv($file, $header);
			foreach ($data as $row) {
				fputcsv($file, $row);
			}
	 	}

		fclose($file);
	}

	private function log_to_dump( $data, $path ) {
		ob_start();
		var_dump( $data );
		file_put_contents( $path, ob_get_clean() );
	}

	private function log_launch_helpers( $categories, $title, $date, $slug, $log_data ) {

		// new categories
		foreach( $categories['full'] as $v ) {
			if( ! in_array( $v['url'], $this->prev_categories ) ) {
				$this->mylog( 'launch-new-categories', $v );
			} 
		}

		// duplicate slug
		$query = new WP_Query( array( 'name' => $slug ) );
		if( 0 != $query->post_count) {
			$this->mylog( 'launch-slug-exists', $log_data );
		}

		// importer fail on "post exists"
		if( post_exists( $title, '', $date ) ) {
			$this->mylog( 'launch-post-exists', $log_data );
		}

	}

	private function mylog( $slug, $message, $level = null ) {

		$out_file = $this->export_path . '/' . $slug . '.log';

		// clear on each run
		if( empty( $this->out_files[$out_file] ) ) {
			file_put_contents( $out_file, '' );
			$this->out_files[$out_file] = true;
		}
		
		$message = ( is_object( $message ) || is_array( $message ) ) ? print_r( $message, true ) : $message;

		$this->logger->log( $out_file, $message, $level );		

	}


	/**
	 * Setters
	 *
	 */

	private function set_authors() {

		$result = $this->pdo->prepare("
			select cmsDocument.nodeId, cmsContentXML.xml
			from cmsDocument
			join cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			join cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			join cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias = 'author'
			where cmsDocument.published = 1	
		");
		$result->execute();

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   
			
			$xml = simplexml_load_string( $row['xml'] );
			
			$this->authors[ (string) $row['nodeId'] ] = [
				'id' => (string) $row['nodeId'],
				'name' => (string) $xml['nodeName'],
				'slug' => (string) $xml['urlName'],
				'email' => (string) $xml->email,
				'post_count' => 0,
			];

		}  

	}

	/**
	 * Set CoAuthorsPlus from postmeta
	 *
	 * @param int $posts_per_page
	 * @return int $count posts processed
	 */
	private function set_coauthors_plus( $posts_per_page ) {

		// select posts with authors postmeta where CoAuthors taxonomy isn't set
		$args = [
			'posts_per_page' => $posts_per_page,
			'post_type'     => 'post',
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => 'newspack_lf_authors',
					'compare' => 'EXISTS',
				],
			],
			'tax_query' 	=> [
				[
					'taxonomy' => 'author',
					'field' => 'slug',
					'operator' => 'NOT EXISTS',
				],
			],
		];

		$query = new WP_Query ( $args );

		foreach ($query->posts as $post_id ) {

			// get meta as json object
			$authors = json_decode( get_post_meta( $post_id, 'newspack_lf_authors', true ) );

			// create (or get) author ids
			$cap_ids = array_map( function ( $author ) {

				// creates author or gets existing author ID
				return $this->coauthorsplus_logic->create_guest_author( [
					'display_name' => sanitize_text_field( $author->name ),
					'user_login'   => sanitize_title( $author->name ),
					'user_email'   => sanitize_email( $author->email ),
				]);

			}, $authors );

			// save to post
			$this->coauthorsplus_logic->assign_guest_authors_to_post( $cap_ids, $post_id );

		} // posts

		return $query->post_count;

	}

	/**
	 * Set CoAuthorsPlus from postmeta for Launch
	 *
	 * @param int $posts_per_page
	 * @return int $count posts processed
	 */
	private function set_coauthors_plus_launch( $posts_per_page ) {

		// select posts with authors postmeta where CoAuthors taxonomy isn't set
		$args = [
			'posts_per_page' => $posts_per_page,
			'post_type'     => 'post',
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => 'newspack_lf_authors',
					'compare' => 'EXISTS',
				],
				'relation' => 'AND',
				[
					'key'     => 'newspack_lf_import_batch',
					'compare' => 'launch',
				],
			],
			'tax_query' 	=> [
				[
					'taxonomy' => 'author',
					'field' => 'name',
					'terms' => 'lf-import-user',
				],
			],
		];

		$query = new WP_Query ( $args );

		foreach ($query->posts as $post_id ) {

			// get meta as json object
			$authors = json_decode( get_post_meta( $post_id, 'newspack_lf_authors', true ) );

			// create (or get) author ids
			$cap_ids = array_map( function ( $author ) {

				// creates author or gets existing author ID
				return $this->coauthorsplus_logic->create_guest_author( [
					'display_name' => sanitize_text_field( $author->name ),
					'user_login'   => sanitize_title( $author->name ),
					'user_email'   => sanitize_email( $author->email ),
				]);

			}, $authors );

			// save to post
			$this->coauthorsplus_logic->assign_guest_authors_to_post( $cap_ids, $post_id );

		} // posts

		return $query->post_count;

	}

	private function set_correct_wp_category( $cat_id, $old_path ) {

		// remove all current posts from this category
		$args = [
			'posts_per_page' 	=> -1,
			'post_type' 		=> 'post',
			'fields'			=> 'ids',
			'cat' 				=> $cat_id,
		];
		$query = new WP_Query ( $args );
		
		WP_CLI::line( "\n" . sprintf( 'Removing %s posts in category %d.', $query->post_count, $cat_id ) );

		foreach ($query->posts as $post_id ) {
			
			// NOTE: wp_remove_object_terms expects $cat_id as INT else will look for string/slug
			wp_remove_object_terms( $post_id, (int) $cat_id, 'category' );

		}

		// add only posts that match old postmeta
		$args = [
			'posts_per_page' 	=> -1,
			'post_type' 		=> 'post',
			'fields'			=> 'ids',
			'meta_query'		=> [
				[
					'key'		=> 'newspack_lf_categories',
					'value' 	=> str_replace('/', '\\/', $old_path), // match db json string format (e.g.: '\/topics\/esg\/solar')
					'compare'	=> 'LIKE'
				],
			],
		];
		$query = new WP_Query ( $args );
		
		WP_CLI::line( sprintf( 'Updating %s posts for category %d using old path %s.', $query->post_count, $cat_id, $old_path ) );

		foreach ($query->posts as $post_id ) {
			
			// get old category list
			$old_cats = get_post_meta( $post_id, 'newspack_lf_categories' );
			
			if( empty( $old_cats ) || 1 != count( $old_cats ) ) {
				WP_CLI::error( 'Incorrect newspack_lf_categories count for post_id: ' . $post_id );
			}

			wp_set_post_terms( $post_id, (int) $cat_id, 'category', true );

		}
		
	}

	private function set_images_in_content( $post_id, $imgs, &$report ) {

		global $wpdb;

		// images per post content
		foreach( $imgs as $img ) {
			
			// DOMAIN: skip aleady set images
			if( preg_match( '#src=.{0,1}http(s)?://latinfinance-newspack#i', $img ) ) {
				$report['img-staging-skipped']++;
				continue;
			}

			// DOMAIN: images on domain
			if( preg_match( '#src=.{0,1}http(s)?://([a-z]+\.)?latinfinance.com#i', $img ) ) {
				$report['fix-by-hand'][] = 'Fix by hand: post id ' . $post_id . ' => ' . $img;
				continue;
			}

			// DOMAIN: all other domains (offsite)
			if( preg_match( '#src=.{0,1}http(s)?://#i', $img ) ) {
				$report['img-off-site-skipped']++;
				$report['img-off-site-urls'][] = $img;
				continue;
			}

			// ignore old_assets images for now
			// if( preg_match( '#src=.{0,1}/old_assets/#i', $img ) ) {
			// 	$report['img-old-assets-skipped']++;
			// 	continue;
			// }

			// remove old_assets 
			if( preg_match( '#src=.{0,1}/old_assets/#i', $img ) ) {

				// remove full tag: <img alt="ECM rank by volume" src="/old_assets/Media/images/latin-finance/novdec2016/pg4_table.gif" width="500" ... >
				$wpdb->query( $wpdb->prepare( "
					UPDATE {$wpdb->posts} 
					SET post_content = REPLACE(post_content, %s, %s)
					WHERE ID = %d
				", $img, '', $post_id ) );

				$report['img-old-assets-removed']++;
				continue;
			}

			// skip base64 images
			if( preg_match( '#src=.{0,1}data:image/#i', $img ) ) {
				$report['img-base64-skipped']++;
				continue;
			}

			// media urls
			if( preg_match( '#src=.{0,1}(/media/[^\'"]+)[\'"]#i', $img, $matches ) ) {

				if( 2 != count( $matches ) ) {
					WP_CLI::error( 'Regex error for /media/ in ' . $post_id . ' => ' . $img );
				}

				$this->set_image_url_in_post( $post_id, $matches[1] );
				$report['fixed']++;
				continue;

			}

			// anything else remaining fix by hand
			$report['fix-by-hand'][] = 'Fix by hand: post id ' . $post_id . ' => ' . $img;

		}

	}

	private function set_image_url_in_post( $post_id, $img_url ) {

		global $wpdb;

		// get the image
		// don't worry about query string width/height, this was already handled when importer grabbed the image
		// but replace &amp; with & due to importer CDATA WXR issue
		$attachment_id = $wpdb->get_var( $wpdb->prepare( "
			SELECT ID
			FROM wp_posts 
			WHERE post_type = 'attachment'
			AND post_title = %s
		", str_replace( '&amp;', '&', $img_url, ) ) );

		if( null === $attachment_id ) {
			WP_CLI::error( 'DB image not found for attachment ' . $post_id . ' and image ' . $img_url );
		}
		
		// get new url
		if( ! ( $new_url = wp_get_attachment_url( $attachment_id ) ) ) {
			WP_CLI::error( 'Attachment url not found for attachment_id ' . $attachment_id );
		}

		// replace post content
		$wpdb->query( $wpdb->prepare( "
			UPDATE {$wpdb->posts} 
			SET post_content = REPLACE(post_content, %s, %s)
			WHERE ID = %d
		", $img_url, $new_url, $post_id ) );

	}



	// Use PDO to create a connection to the DB.
	// php requires: php.ini => extension=pdo_sqlsrv
	// client requires: IP Address whitelisted
	private function set_pdo() {

		try {  
			$this->pdo = new PDO( "sqlsrv:Server=;Database=LatinFinanceUmbraco", NULL, NULL);   
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );  
			// debug: echo $this->pdo->getAttribute( PDO::SQLSRV_ATTR_ENCODING ); // show charset/collation
		}  
		catch( PDOException $e ) {  
			WP_CLI::error( 'SQL Server error ' . $e->getCode() . ': ' . $e->getMessage() );
		}  

	}

	private function set_previous_checksums(){
		
		$path = $this->export_path . '/../../lookup-original-checksums.txt';

		$file_handle = fopen( $path, "r");

		if ( ! $file_handle ) {
			// WP_CLI::error( 'File not exists: ' . $path );
			return; // no file is fine for initial insert
		}

		while( !feof( $file_handle ) ) {
			$arr = array_map( 'trim', explode( ',', trim( fgets( $file_handle ) ) ) );
			$this->prev_checksums[$arr[0]] = array( $arr[1], $arr[2], 0 ); // version, checksum, counter
		}

	}

	/**
	 * Set Yoast primary categories from old content types
	 *
	 * @param int $posts_per_page
	 * @return int $count posts processed
	 */
	private function set_primary_categories( $posts_per_page ) {

		// get term ids for primary categories keyed by slug
		$categories = array_flip( get_categories( [
			'slug'   => [ 'daily-brief', 'magazine', 'web-articles' ],
			'fields' => 'id=>slug',
		]));

		// select posts with old content type where primary category isn't set
		$query = new WP_Query ( [
			'posts_per_page' => $posts_per_page,
			'post_type'     => 'post',
			'category__in'  => array_values( $categories ),
			'fields'		=> 'ids',
			'meta_query'    => [
				[
					'key'     => 'newspack_lf_content_type',
					'compare' => 'EXISTS',
				],
				[
					'key'     => '_yoast_wpseo_primary_category',
					'compare' => 'NOT EXISTS',
				],
			]
		]);

		$count = $query->post_count;

		while ( $query->have_posts() ) {

			$query->the_post();

			// there are limited cases where 'newspack_lf_content_type' could have multiple values
			// - this is when the WXR importer saved different posts as one
			// - for this code below, just use what ever value is returned
			$content_type = get_post_meta( get_the_ID(), 'newspack_lf_content_type', true );

			$category_id = null;
			switch( $content_type ) {
				case 'dailyBriefArticle': $category_id = $categories['daily-brief']; break;
				case 'magazineArticle': $category_id = $categories['magazine']; break;
				case 'webArticle': $category_id = $categories['web-articles']; break;
			}

			// this case should not happen
			if( null === $category_id ) {
				WP_CLI::error('Unknown old content type "' . $content_type . '", no category found.');
			}

			update_post_meta( get_the_ID(), '_yoast_wpseo_primary_category', $category_id );

		} // foreach

		if( $count > 0) wp_reset_postdata();

		return $count;

	}

	// Set tags (convert to Categories under a parent category named 'Topics')
	// result will look like:
	//		Topics
	//			-> Bonds
	//		DailyBriefs
	//		etc
	private function set_tags( ) {

		// Order by level to assure WXR importing will create parent before child
		$result = $this->pdo->prepare("
			select cmsDocument.nodeId, cmsContentXML.xml,
				CAST(cmsContentXML.xml as xml).value('(/*/@level)[1]', 'int') as level
			from cmsDocument
			join cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			join cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			join cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias in('tag','tags')
			where cmsDocument.published = 1	
			order by level
		");
		$result->execute();

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			$xml = simplexml_load_string( $row['xml'] );
			
			$slug = (string) $xml['urlName'];

			$custom_tag_slugs = array( 'daily-briefs', 'magazine', 'web-articles', 'free-content' );
			if( in_array( $slug, $custom_tag_slugs ) ) {
				WP_CLI::error( 'Custom tag can not be used twice: ' . $slug . ' ' . print_r( $row['xml'] , true) );				
			}

			$this->tags[ (string) $row['nodeId'] ] = [
				'id' => (string) $row['nodeId'],
				'name' => (string) $xml['nodeName'],
				'slug' => $slug,
				'post_count' => 0,
				'parent' => (string) $xml['parentID'],
				'parent_slug' => '',
				'url' => $this->get_url_from_path( (string) $xml['path'] ),
				'description' => (string) $xml->sidebarWidget,
			];

		}  

	}

	private function set_tags_parent_slugs() {

		foreach( $this->tags as $id => $node ) {
			
			$parent = $node['parent'];

			// special case for top level category where parent is "1051/Home"
			if( $parent == '1051' ) {
				$this->tags[$id]['parent_slug'] = '';
				continue;	
			}

			// if parent id doesn't match a node
			if ( !isset( $this->tags[$parent] ) ) {
				WP_CLI::error( 'Tag parent not found for node: ' . print_r( $this->tags[$id] , true) );				
			}

			// set node's parent slug from parent node's slug
			$this->tags[$id]['parent_slug'] = $this->tags[$parent]['slug'];
			
		}

	}

	/**
	 * Hacks...
	 */

	private function hack_prev_category_urls() {

		// initial import list
		// see: migrated-google-drive-content-2023-05-10/csv-reports/latinfinance-tags.csv
		return array(
			'/topics',
			'/topics/interviews',
			'/topics/event-exclusives',
			'/topics/corporate-sovereign-strategy',
			'/topics/esg',
			'/topics/economy-policy',
			'/topics/technology',
			'/topics/funds',
			'/topics/sponsored-content',
			'/topics/debt',
			'/topics/people',
			'/topics/capital-markets',
			'/topics/international-financial-institutions',
			'/topics/ma',
			'/topics/project-infrastructure-finance',
			'/topics/debenture',
			'/topics/video',
			'/topics/telecoms',
			'/topics/coronavirus',
			'/topics/structured-finance',
			'/topics/loans',
			'/topics/latinfinance-connect',
			'/topics/regulation',
			'/topics/credit-ratings',
			'/topics/regions',
			'/topics/bonds',
			'/topics/fixed-income',
			'/topics/commerce',
			'/topics/ipo',
			'/topics/latinfinance-30',
			'/topics/tourism',
			'/topics/in-depth',
			'/topics/energy',
			'/topics/healthcare',
			'/topics/game-changers',
			'/topics/equity',
			'/topics/asset-management',
			'/topics/regions/latin-america',
			'/topics/regions/cuba',
			'/topics/esg/renewable',
			'/topics/in-depth/features',
			'/topics/regions/venezuela',
			'/topics/regions/europe',
			'/topics/international-financial-institutions/caf',
			'/topics/energy/hydro',
			'/topics/regions/barbados',
			'/topics/energy/gas',
			'/topics/telecoms/mobile',
			'/topics/regions/panama',
			'/topics/telecoms/fixed-line',
			'/topics/esg/sustainability-bonds',
			'/topics/regions/china',
			'/topics/regions/trinidad-tobago',
			'/topics/regions/guatemala',
			'/topics/international-financial-institutions/world-bank',
			'/topics/project-infrastructure-finance/water-sewage',
			'/topics/project-infrastructure-finance/railroads',
			'/topics/regions/guyana',
			'/topics/regions/south-america',
			'/topics/project-infrastructure-finance/highways',
			'/topics/regions/bahamas',
			'/topics/credit-ratings/moodys-investors-service',
			'/topics/regions/united-kingdom',
			'/topics/energy/electricity',
			'/topics/regions/germany',
			'/topics/regions/grenada',
			'/topics/regions/jamaica',
			'/topics/regions/africa',
			'/topics/project-infrastructure-finance/toll-road',
			'/topics/regions/usmca',
			'/topics/regions/australia',
			'/topics/regions/nicaragua',
			'/topics/project-infrastructure-finance/dams',
			'/topics/in-depth/comment-opinion',
			'/topics/energy/pipelines',
			'/topics/regions/costa-rica',
			'/topics/credit-ratings/sp-global',
			'/topics/regions/mercosur-ar-br-pa-ur-ve',
			'/topics/regions/uruguay',
			'/topics/regions/switzerland',
			'/topics/healthcare/health-policy',
			'/topics/regions/bermuda',
			'/topics/esg/wind',
			'/topics/project-infrastructure-finance/ports',
			'/topics/regions/bvi',
			'/topics/regions/honduras',
			'/topics/regions/italy',
			'/topics/esg/green-finance',
			'/topics/regulation/politics',
			'/topics/regulation/rule-144a-regs',
			'/topics/credit-ratings/fitch-ratings',
			'/topics/regions/russia',
			'/topics/regions/andean',
			'/topics/esg/solar',
			'/topics/international-financial-institutions/jica-japan-international-cooperation-agency',
			'/topics/regions/mexico',
			'/topics/regions/chile',
			'/topics/regions/ecuador',
			'/topics/regions/bolivia',
			'/topics/regions/netherlands',
			'/topics/technology/digital-payments',
			'/topics/regions/brazil',
			'/topics/energy/solar',
			'/topics/regions/suriname',
			'/topics/regions/peru',
			'/topics/energy/wind',
			'/topics/regions/pacific-alliance-ch-co-mx-pe',
			'/topics/healthcare/hospitals',
			'/topics/in-depth/special-reports',
			'/topics/regions/singapore',
			'/topics/esg/green-washing',
			'/topics/loans/clo',
			'/topics/regions/france',
			'/topics/energy/lng',
			'/topics/esg/sustainable-development-bonds',
			'/topics/project-infrastructure-finance/tunnels',
			'/topics/international-financial-institutions/international-monetary-fund-imf',
			'/topics/credit-ratings/upgrade',
			'/topics/esg/hydro',
			'/topics/regions/dominican-republic',
			'/topics/healthcare/pharmaceuticals',
			'/topics/esg/green-bonds',
			'/topics/energy/oil',
			'/topics/regions/paraguay',
			'/topics/regions/asia',
			'/topics/regions/japan',
			'/topics/regions/canada',
			'/topics/regions/el-salvador',
			'/topics/regions/colombia',
			'/topics/regions/argentina',
			'/topics/credit-ratings/downgrade',
			'/topics/regions/portugal',
			'/topics/regions/united-states',
			'/topics/regions/central-america',
			'/topics/esg/sustainability-linked',
			'/topics/esg/social-bonds',
			'/topics/credit-ratings/revision',
			'/topics/regions/haiti',
			'/topics/regions/india',
			'/topics/regulation/rule-400',
			'/topics/commerce/retail-shopping',
			'/topics/regulation/rule-476',
			'/topics/regions/middle-east',
			'/topics/international-financial-institutions/inter-american-development-bank-idb',
			'/topics/international-financial-institutions/idb-invest',
			'/topics/regions/belize',
			'/topics/regions/caribbean',
			'/topics/regions/spain',
			'/topics/esg/biofuels',
		);

	}

	private function hack_get_old_redirects() {

		// as of 2023-04 (first import)
		// @link https://docs.google.com/spreadsheets/d/1SG-pJBbY1Vc2S0wwhMp3uG7j_HKcjkeRjEYKqAmMyEQ/edit#gid=398957389

		return array(
			'/2019-q2' => 'http://read.nxtbook.com/latinfinance/magazine/2019_q2/index.html',
			'/2019-q3' => 'http://read.nxtbook.com/latinfinance/magazine/2019_q3/index.html',
			'/2019-q4-issue' => 'https://read.nxtbook.com/latinfinance/magazine/2019_q4/index.html',
			'/2020-q1' => 'http://read.nxtbook.com/latinfinance/magazine/2020_q1/index.html',
			'/2020-q2' => 'http://read.nxtbook.com/latinfinance/magazine/2020_q2/index.html',
			'/2020-q3' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q3/cover.html',
			'/argentinaprez' => '/daily-briefs/2019/10/29/argentinas-next-president-faces-a-rough-road-to-restructuring',
			'/argsubsov' => 'https://latinfinance.azurewebsites.net/media/1524/argentina-sub-sov-2017-summary.pdf',
			'/awardalerts' => 'https://latinfinance.us16.list-manage.com/subscribe?u=3f72033fc318b70e76ee07ccf&id=99ca3c32e4',
			'/awards/banks-of-the-year-awards/2019/bank-of-the-year-nicaragua-banco-lafise-bicentro' => '/awards/banks-of-the-year-awards/2019/bank-of-the-year-nicaragua-banco-lafise-bancentro',
			'/awards/deals-of-the-year/2018' => '/awards/deals-of-the-year-awards/2018',
			'/awards/deals-of-the-year/2018/corporate-issuer-of-the-year-petrobras' => '/awards/deals-of-the-year/2018/corporate-issuer-corporate-liability-management-and-syndicated-loan-of-the-year-petrobras',
			'/awards/deals-of-the-year/2018/corporate-liability-management-of-the-year-petrobras' => '/awards/deals-of-the-year/2018/corporate-issuer-corporate-liability-management-and-syndicated-loan-of-the-year-petrobras',
			'/awards/deals-of-the-year/2018/investment-bank-and-ma-house-of-the-year-bank-of-america-merril-lynch' => '/awards/deals-of-the-year/2018/investment-bank-and-ma-house-of-the-year-bank-of-america-merrill-lynch',
			'/awards/deals-of-the-year/2018/syndicated-loan-of-the-year-petrobras' => '/awards/deals-of-the-year/2018/corporate-issuer-corporate-liability-management-and-syndicated-loan-of-the-year-petrobras',
			'/awards/project-infrastructure-finance-awards/2019/renewable-energy-financing-of-the-year-and-project-sponsor-of-the-year-enel-green-power' => '/awards/project-infrastructure-finance-awards/2019/renewable-energy-financing-of-the-year-enel-green-power',
			'/banksoftheyear' => '/awards/banks-of-the-year-awards/2022',
			'/bestcorporates' => '/awards/best-corporates-in-the-capital-markets/2018',
			'/boty' => '/awards/banks-of-the-year-awards/2021',
			'/boty/dinner/reservation' => 'http://www.latinfinanceevents.com/d/myqzy1/4W?ct=6f1ecc35-0921-44f1-9530-e709c13da9c9&RefID=Single+Seat+Registration',
			'/boty2018' => '/awards/banks-of-the-year-awards/2018',
			'/botywinners' => '/awards/banks-of-the-year-awards/2019',
			'/brazilforum' => 'https://www.latinfinanceevents.com/d/fyqqyz/',
			'/brazilforum/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/cce187bb69db4943b3faf27c5a231ac4.pdf',
			'/caribbean/interest' => '/events',
			'/caribbean/register' => 'http://www.latinfinanceevents.com/d/p6q0m9/8K?RefID=Custom+Fees',
			'/caribbean/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/b35f4623bef24fc591dde2509d73f5a3.pdf',
			'/centam/2020' => 'https://cvent.me/3ERD8Q',
			'/centam/agenda' => 'https://read.nxtbook.com/latinfinance/events/the_6th_central_america_finan/agenda.html',
			'/centam/register' => 'https://mailchi.mp/ae6e885c42c9/2020-central-america-finance-investment-forum',
			'/centam/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/31c2629b04294193b73aab72144175dd.pdf',
			'/confirm' => 'http://eepurl.com/c8RU0z',
			'/cumbremx/agenda' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/30a18e02411344fd8e7863d4ef982fda.pdf',
			'/cumbremx/register' => 'http://www.latinfinanceevents.com/d/f6qqzf/8K?RefID=Custom+Fees',
			'/cumbremx/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/2fc10f4d02174598bbbf7f38d01efdc2.pdf',
			'/daily-brief/2021/2/26/exclusive-posadas-checks-out-of-restructuring-talks' => '/web-articles/2021/2/exclusive-posadas-checks-out-of-restructuring-talks',
			'/daily-briefs/2020/10/23/suriname-invokes-30-day-grace-period-on-debt-payments' => '/web-articles/2020/10/suriname-invokes-30-day-grace-period-on-debt-payments',
			'/daily-briefs/2020/11/20/exclusive-braskem-weighs-transition-bond-for-2021' => '/web-articles/2020/11/exclusive-braskem-weighs-transition-bond-for-2021',
			'/daily-briefs/2020/11/20/exclusive-brazil-must-observe-spending-ceiling-in-2021-deputy-minister' => '/web-articles/2020/11/exclusive-brazil-must-observe-spending-ceiling-in-2021-deputy-minister',
			'/daily-briefs/2020/11/20/exclusive-brazil-plans-to-add-two-ports-to-privatization-pipeline' => '/web-articles/2020/11/exclusive-brazil-plans-to-add-two-ports-to-privatization-pipeline',
			'/daily-briefs/2020/11/20/exclusive-brazil-to-keep-international-debt-focused-on-us-european-markets' => '/web-articles/2020/11/exclusive-brazil-to-keep-international-debt-focused-on-us-european-markets',
			'/daily-briefs/2020/12/15/perus-head-of-public-treasury-resigns' => '/web-articles/2020/12/perus-head-of-public-treasury-resigns',
			'/daily-briefs/2020/3/2/ypf-lines-up-a-three-class-bond-issue' => '/web-articles/2020/2/ypf-lines-up-a-three-class-bond-issue',
			'/daily-briefs/2020/3/20/latam-2020-economic-recovery-will-not-happen-imf' => '/web-articles/2020/3/latam-2020-economic-recovery-will-not-happen-imf',
			'/daily-briefs/2020/3/24/ecuador-seeking-emergency-financing-to-help-battle-covid-19' => '/web-articles/2020/3/ecuador-seeking-emergency-financing-to-help-battle-covid-19',
			'/daily-briefs/2020/3/5/idb-says-monitoring-coronavirus-annual-meeting-still-on-track' => '/web-articles/2020/3/idb-says-monitoring-coronavirus-annual-meeting-still-on-track',
			'/daily-briefs/2020/3/9/argentina-provinces-fate-tied-to-sovereign-debt-restructure-chaco-governor' => '/web-articles/2020/3/argentina-provinces-fate-tied-to-sovereign-debt-restructure-chaco-governor',
			'/daily-briefs/2020/4/13/latin-american-q1-deal-activity-underpinned-by-banner-january' => '/daily-briefs/2020/4/13/latin-american-q1-2020-deal-activity-underpinned-by-banner-january',
			'/daily-briefs/2020/4/15/santander-m%c3%a9xico-breaks-cross-border-ice-with-five-year-bonds' => '/web-articles/2020/4/santander-m%c3%a9xico-breaks-cross-border-ice-with-five-year-bonds',
			'/daily-briefs/2020/4/20/bondholder-groups-reject-argentinas-restructuring-offer' => '/web-articles/2020/4/bondholder-groups-reject-argentinas-restructuring-offer',
			'/daily-briefs/2020/4/20/ecuador-get-bondholder-consent-for-suspension-of-debt-service-until-august' => '/daily-briefs/2020/4/20/ecuador-gets-bondholder-consent-for-suspension-of-debt-service-until-august',
			'/daily-briefs/2020/4/20/interview-peru-s-new-debt-boosts-to-13-billion-resources-to-combat-covid-19' => '/daily-briefs/2020/4/20/interview-peru-s-new-debt-boosts-covid-19-fighting-resources-to-13-billion',
			'/daily-briefs/2020/4/21/bondholder-groups-reject-argentinas-restructuring-offer' => '/web-articles/2020/4/bondholder-groups-reject-argentinas-restructuring-offer',
			'/daily-briefs/2020/4/27/interview-mexico-went-for-cash-6-billion-worth-before-market-gets-crowded-deputy-minister' => '/daily-briefs/2020/4/27/interview-mexico-goes-for-cash-before-bond-market-gets-crowded',
			'/daily-briefs/2020/4/28/interview-latin-america-needs-to-save-lives-but-also-livelihoods-idb-s-moreno' => '/daily-briefs/2020/4/28/interview-latam-needs-to-save-lives-but-also-livelihoods-idbs-moreno',
			'/daily-briefs/2020/5/11/argentina-extends-restructuring-offer-to-monday' => '/web-articles/2020/5/argentina-extends-restructuring-offer-to-monday',
			'/daily-briefs/2020/5/26/argentina-investors-unfazed-at-restructuring-extension' => '/web-articles/2020/5/argentina-investors-unfazed-at-restructuring-extension',
			'/daily-briefs/2020/6/1/argentina-and-creditors-detail-new-debt-proposals-government-warns-challenges-remain' => '/web-articles/2020/5/argentina-and-creditors-detail-new-debt-proposals-government-warns-challenges-remain',
			'/daily-briefs/2020/6/1/imf-extends-credit-line-to-peru' => '/web-articles/2020/5/imf-extends-credit-line-to-peru',
			'/daily-briefs/2020/6/23/exclusive-brazil-looks-to-green-bonds-to-reduce-amazon-deforestation' => '/web-articles/2020/6/exclusive-brazil-looks-to-green-bonds-to-reduce-amazon-deforestation',
			'/daily-briefs/2020/6/8/buenos-aires-extends-deadline-for-restructuring-offer-possible-enhancements' => '/web-articles/2020/6/buenos-aires-extends-deadline-for-restructuring-offer-possible-enhancements',
			'/daily-briefs/2020/7/7/argentinas-new-bond-offer-attracting-investors-markets-rally' => '/web-articles/2020/7/argentinas-new-bond-offer-attracting-investors-markets-rally',
			'/daily-briefs/2020/8/14/isa-issues-first-green-bonds-in-colombia' => '/daily-briefs/2020/8/14/isa-issues-green-bonds-in-colombia',
			'/daily-briefs/2020/8/5/argentina-reaches-restructuring-deal-with-creditors' => '/web-articles/2020/8/argentina-reaches-restructuring-deal-with-creditors',
			'/daily-briefs/2020/9/9/cemex-to-buy-out-latam-busines' => '/daily-briefs/2020/9/9/cemex-to-buy-out-latam-business',
			'/daily-briefs/2021/1/26/inter-american-development-bank-to-hold-virtual-annual-meeting-for-second-time' => '/web-articles/2021/1/inter-american-development-bank-to-hold-virtual-annual-meeting-for-second-time',
			'/daily-briefs/2021/2/15/exclusive-brazils-novonor-seeks-partners-ahead-of-listing-in-2022' => '/web-articles/2021/2/exclusive-brazils-novonor-seeks-partners-ahead-of-listing-in-2022',
			'/daily-briefs/2021/3/8/ypf-to-seek-financing-for-investment-program' => '/web-articles/2021/3/ypf-to-seek-financing-for-investment-program',
			'/dbtrial' => 'https://lfp.dragonforms.com/lfp_lf_newtrial',
			'/dealsoftheyear' => '/awards/deals-of-the-year-awards/2022',
			'/doty' => '/awards/deals-of-the-year-awards/2021',
			'/doty2019/brochure' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/307bf219479c488c8557ce3caecff1e1.pdf',
			'/doty2019/invite' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/7273b2898a4e4c07bf808ee6cd850bc1.pdf',
			'/dotywinners' => 'http://read.nxtbook.com/latinfinance/latinfinance/2020_q1/deals_of_the_year_awards.html',
			'/editorialcalendar' => '/media/2294/lf-mk-2020_editorial-contentcal.pdf',
			'/esg' => '/topics/esg',
			'/esgfocus' => '/topics/esg',
			'/factbox' => '/daily-briefs/2020/5/15/factbox-51520-latin-america-moves-to-mitigate-impact-of-covid-19',
			'/felaban' => 'https://myaccount.latinfinance.com/subcnew.aspx?PC=LF&pk=rtrial&utm_source=lf&utm_medium=print&utm_campaign=felaban',
			'/fintech' => '/magazine/2018/may-june-2018/plotting-the-future-a-discussion-on-fintechs-in-brazil',
			'/freetrial' => 'https://lfp.dragonforms.com/lfp_lf_newtrial',
			'/idb-breakfast-2019' => 'https://www.latinfinanceevents.com/d/0yqmd8/',
			'/idb19/register' => 'http://www.latinfinanceevents.com/d/0yqmd8/4W',
			'/idb19/register/cacibguest' => 'http://www.latinfinanceevents.com/d/0yqmd8/4W?ct=f866a3cd-2226-4cf1-924d-8569cf09f05d&RefID=SPcAcIBFree',
			'/idb19/register/cliffordchanceguest' => 'http://www.latinfinanceevents.com/d/0yqmd8/4W?ct=f866a3cd-2226-4cf1-924d-8569cf09f05d&RefID=SpCChanceVip',
			'/idbbreakfast/agenda' => 'https://read.nxtbook.com/latinfinance/events/latin_america_sovereign_debt_/agenda.html',
			'/idforum/2020' => 'https://cvent.me/870E4E',
			'/idforum/agenda' => 'https://read.nxtbook.com/latinfinance/events/1st_integration_development_f/agenda_spanish.html',
			'/idforum/register' => 'https://mailchi.mp/ec46daf0cc22/2020-fonplata-request-pass-english',
			'/idforum/workbook' => 'https://read.nxtbook.com/latinfinance/events/1st_integration_development_f/agenda_spanish.html',
			'/julyoffer' => 'https://myaccount.latinfinance.com/subcnew.aspx?PC=LF&pk=rtrial&utm_source=lf&utm_medium=print&utm_campaign=db-trial&utm_content=july-august-2018-magazine-spread',
			'/lacapmkts/agenda' => 'https://read.nxtbook.com/latinfinance/events/capital_markets_summit/agenda.html',
			'/lacif/agenda' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/ee05b84da13b4e40808b274b4d11230b.pdf',
			'/lacif/register' => 'http://www.latinfinanceevents.com/d/jgqc7y/8K?RefID=Custom+Fees',
			'/lf-overview' => '/media/2388/lf-media-kit-2020-about-latinfinance.pdf',
			'/lf-q3-2019' => '/media/2111/lf-q3-no-rates.pdf',
			'/lf25' => 'http://www.nxtbook.com/nxtbooks/latinfinance/89456RBM/index.php',
			'/lf30' => 'https://latinfinance.us16.list-manage.com/subscribe?u=3f72033fc318b70e76ee07ccf&id=a643fad7cc',
			'/linkedin-factbox' => '/daily-briefs/2020/5/15/factbox-51520-latin-america-moves-to-mitigate-impact-of-covid-19?utm_source=linkedin&utm_medium=paid&utm_campaign=coronavirus-factbox',
			'/magazine-archive' => 'http://nxtbook.com/fx/archives/view.php?id=c5b7929c8d31642b174723b97634c67a',
			'/magazine/2018/november-december-2018/bank-of-the-year-2018-el-slavador-banco-agrícola' => '/magazine/2018/november-december-2018/bank-of-the-year-2018-el-salvador-banco-agrícola',
			'/magazine/2019/2019q3/2019-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2019',
			'/magazine/2019/2019q3/read-digital-edtion' => 'http://read.nxtbook.com/latinfinance/latinfinance/2019_q3/latinfinance_2019_q3_the_esg_.html',
			'/magazine/2019/q4/banks-of-the-year-awards' => '/awards/banks-of-the-year-awards/2019',
			'/magazine/2019/q4/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/latinfinance/2019_q4/index.html',
			'/magazine/2019/spring-2019/read-digital-edition' => 'https://latinfinance.us16.list-manage.com/subscribe?u=3f72033fc318b70e76ee07ccf&id=a643fad7cc',
			'/magazine/2020/q1/deals-of-the-year-awards' => '/awards/deals-of-the-year-awards/2019',
			'/magazine/2020/q1/read-digital-edition' => 'http://read.nxtbook.com/latinfinance/latinfinance/2020_q1/cover.html',
			'/magazine/2020/q2/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q2/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q2-issue',
			'/magazine/2020/q3/2020-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2020',
			'/magazine/2020/q3/latinfinances-2020-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2020',
			'/magazine/2020/q3/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q3/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q3-issue',
			'/magazine/2020/q4-coping-with-covid/2020-banks-of-the-year-awards' => '/awards/banks-of-the-year-awards/2020',
			'/magazine/2020/q4-coping-with-covid/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q4/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q4-issue',
			'/magazine/2021/q1-the-covid-rebuild/2020-deals-of-the-year-awards' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q1/doty.html?utm_source=lf&utm_medium=referral&utm_campaign=2021-q1-issue',
			'/magazine/2021/q1-the-covid-rebuild/podcast-timing-is-everything' => '/web-articles/2021/3/podcast-timing-is-everything',
			'/magazine/2021/q1-the-covid-rebuild/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q1/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2021-q1-issue',
			'/magazine/2021/q2-visions-of-the-new-world/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q2/q2.html',
			'/magazine/2021/q3q4-the-long-road/2021-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2021',
			'/magazine/2021/q3q4-the-long-road/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q3_q4/index.html',
			'/magazine/2022/q1-bank-to-the-future/2021-banks-of-the-year-awards' => '/awards/banks-of-the-year-awards/2021',
			'/magazine/2022/q1-bank-to-the-future/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q1/index.html',
			'/magazine/2022/q2-on-the-edge/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q2/cover.html',
			'/magazine/2022/q3-the-commodities-promise' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q3/cover.html',
			'/magazine/2022/q4-in-the-balance/project-infrastructure-finance-awards' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q4/winners.html',
			'/magazine/2022/q4-in-the-balance/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q4/q4_2022.html',
			'/magazine/2023/q1-beyond-the-horizon/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q1/cover.html',
			'/magazine/2023/q2-when-the-wind-blows/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q2/cover.html',
			'/mexicosubsov' => 'http://www.latinfinanceevents.com/d/9gqcqz?RefID=LnkP1',
			'/mxsubnational/interest' => '/events',
			'/mxsubsov/workbook' => 'https://read.nxtbook.com/latinfinance/events/2019_mexico_subsov_summit/cover.html',
			'/pafif/2020' => 'https://cvent.me/GV5Gxw',
			'/pafif/agenda' => 'https://read.nxtbook.com/latinfinance/events/pacific_alliance_finance_inve/agenda.html',
			'/pafif/register' => 'https://mailchi.mp/f29100d51837/2020-pacific-alliance-forum-request-pass-english',
			'/pif/dinner/reservation' => 'http://www.latinfinanceevents.com/d/1yqksj/4W?ct=6f1ecc35-0921-44f1-9530-e709c13da9c9&RefID=Single+Seat+Registration',
			'/pif/register' => 'http://www.latinfinanceevents.com/d/myqp7l/8K?RefID=Custom+Fees',
			'/pif/workbook' => 'https://read.nxtbook.com/latinfinance/latinfinance/lf_events_pif_summit/the_4th_latinfinance_project_.html',
			'/pifawards' => '/awards/project-infrastructure-finance-awards/2022',
			'/projectinfra/agenda' => 'http://www.latinfinanceevents.com/events/latin-america-project-infrastructure-finance-summit/agenda-b55ac659a4af4fd2b00d386ea6c7b6ea.aspx?RefID=MLFW',
			'/q2' => '/media/2386/q2-2020-latinfinance.pdf',
			'/scotiasub' => 'https://myaccount.latinfinance.com/LF/register.aspx?PC=LF&BC=SBANK',
			'/trial' => 'https://myaccount.latinfinance.com/subcnew.aspx?PC=LF&pk=rtrial',
			'/web-articles/2018/2/investors-brace-for-election-nafta-renegotiation-outcomes' => '/web-articles/2018/2/investors-brace-for-election-nafta-renegotiation',
			'/web-articles/2018/3/qa-albright-capital-talks-latam-ventures' => '/web-articles/2018/3/albright-capital-talks-latam-ventures',
			'/web-articles/2019/10/barbados-reaches-debt-restructuring-deal-with-creditors' => '/daily-briefs/2019/10/21/barbados-reaches-debt-restructuring-deal-with-creditors',
			'/web-articles/2020/1/live-stream-the-5th-latin-america-capital-markets-summit' => '/web-articles/2020/1/video-replay-the-5th-latin-america-capital-markets-summit',
			'/web-articles/2020/10/q32020-magazine-picking-up-the-pieces' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q3/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q3-issue',
			'/web-articles/2020/12/q42020-magazine-coping-with-covid' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q4/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q4-issue',
			'/web-articles/2020/2/live-stream-the-1st-integration-development-forum' => '/web-articles/2020/3/live-stream-the-1st-integration-development-forum',
			'/web-articles/2020/3/live-stream-the-1st-integration-development-forum' => '/web-articles/2020/3/video-the-1st-integration-development-forum',
			'/web-articles/2021/12/q1-magazine-bank-to-the-future' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q1/index.html',
			'/web-articles/2021/3/q12021-magazine-the-covid-rebuild' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q1/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2021-q1-issue',
			'/web-articles/2021/6/q2-2021-magazine-visions-of-the-new-world' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q2/q2.html',
			'/web-articles/2021/9/q3q4-magazine-the-long-road' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q3_q4/index.html',
			'/web-articles/2022/12/q1-magazine-beyond-the-horizon' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q1/cover.html',
			'/web-articles/2022/7/q3-magazine-the-commodities-promise' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q3/cover.html',
			'/web-articles/2022/9/q4-magazine-in-the-balance' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q4/q4_2022.html',
			'/web-articles/2023/3/q2-magazine-when-the-wind-blows' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q2/cover.html',
		);
	}

	private function hack_get_old_redirects_launch() {

		// as of 2023-08 launch
		// see local sql/redirects.sql
	
		return array(	
			'/2019-q2' => 'http://read.nxtbook.com/latinfinance/magazine/2019_q2/index.html',
			'/2019-q3' => 'http://read.nxtbook.com/latinfinance/magazine/2019_q3/index.html',
			'/2019-q4-issue' => 'https://read.nxtbook.com/latinfinance/magazine/2019_q4/index.html',
			'/2020-q1' => 'http://read.nxtbook.com/latinfinance/magazine/2020_q1/index.html',
			'/2020-q2' => 'http://read.nxtbook.com/latinfinance/magazine/2020_q2/index.html',
			'/2020-q3' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q3/cover.html',
			'/argentinaprez' => 'https://www.latinfinance.com/daily-briefs/2019/10/29/argentinas-next-president-faces-a-rough-road-to-restructuring',
			'/argsubsov' => 'https://latinfinance.azurewebsites.net/media/1524/argentina-sub-sov-2017-summary.pdf',
			'/awardalerts' => 'https://latinfinance.us16.list-manage.com/subscribe?u=3f72033fc318b70e76ee07ccf&id=99ca3c32e4',
			'/awards/banks-of-the-year-awards/2019/bank-of-the-year-nicaragua-banco-lafise-bicentro' => '/awards/banks-of-the-year-awards/2019/bank-of-the-year-nicaragua-banco-lafise-bancentro',
			'/awards/deals-of-the-year/2018' => 'https://www.latinfinance.com/awards/deals-of-the-year-awards/2018',
			'/awards/deals-of-the-year/2018/corporate-issuer-of-the-year-petrobras' => 'https://www.latinfinance.com/awards/deals-of-the-year/2018/corporate-issuer-corporate-liability-management-and-syndicated-loan-of-the-year-petrobras',
			'/awards/deals-of-the-year/2018/corporate-liability-management-of-the-year-petrobras' => 'https://www.latinfinance.com/awards/deals-of-the-year/2018/corporate-issuer-corporate-liability-management-and-syndicated-loan-of-the-year-petrobras',
			'/awards/deals-of-the-year/2018/investment-bank-and-ma-house-of-the-year-bank-of-america-merril-lynch' => 'https://www.latinfinance.com/awards/deals-of-the-year/2018/investment-bank-and-ma-house-of-the-year-bank-of-america-merrill-lynch',
			'/awards/deals-of-the-year/2018/syndicated-loan-of-the-year-petrobras' => 'https://www.latinfinance.com/awards/deals-of-the-year/2018/corporate-issuer-corporate-liability-management-and-syndicated-loan-of-the-year-petrobras',
			'/awards/project-infrastructure-finance-awards/2019/renewable-energy-financing-of-the-year-and-project-sponsor-of-the-year-enel-green-power' => '/awards/project-infrastructure-finance-awards/2019/renewable-energy-financing-of-the-year-enel-green-power',
			'/banksoftheyear' => '/awards/banks-of-the-year-awards/2022',
			'/bestcorporates' => 'http://www.latinfinance.com/awards/best-corporates-in-the-capital-markets/2018',
			'/boty' => 'https://www.latinfinance.com/awards/banks-of-the-year-awards/2021',
			'/boty/dinner/reservation' => 'http://www.latinfinanceevents.com/d/myqzy1/4W?ct=6f1ecc35-0921-44f1-9530-e709c13da9c9&RefID=Single+Seat+Registration',
			'/boty2018' => 'https://www.latinfinance.com/awards/banks-of-the-year-awards/2018',
			'/botywinners' => 'https://www.latinfinance.com/awards/banks-of-the-year-awards/2019',
			'/brazilforum' => 'https://www.latinfinanceevents.com/d/fyqqyz/',
			'/brazilforum/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/cce187bb69db4943b3faf27c5a231ac4.pdf',
			'/caribbean/interest' => 'https://www.latinfinance.com/events',
			'/caribbean/register' => 'http://www.latinfinanceevents.com/d/p6q0m9/8K?RefID=Custom+Fees',
			'/caribbean/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/b35f4623bef24fc591dde2509d73f5a3.pdf',
			'/centam/2020' => 'https://cvent.me/3ERD8Q',
			'/centam/agenda' => 'https://read.nxtbook.com/latinfinance/events/the_6th_central_america_finan/agenda.html',
			'/centam/register' => 'https://mailchi.mp/ae6e885c42c9/2020-central-america-finance-investment-forum',
			'/centam/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/31c2629b04294193b73aab72144175dd.pdf',
			'/confirm' => 'http://eepurl.com/c8RU0z',
			'/cumbremx/agenda' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/30a18e02411344fd8e7863d4ef982fda.pdf',
			'/cumbremx/register' => 'http://www.latinfinanceevents.com/d/f6qqzf/8K?RefID=Custom+Fees',
			'/cumbremx/workbook' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/2fc10f4d02174598bbbf7f38d01efdc2.pdf',
			'/daily-brief/2021/2/26/exclusive-posadas-checks-out-of-restructuring-talks' => '/web-articles/2021/2/exclusive-posadas-checks-out-of-restructuring-talks',
			'/daily-briefs/2020/10/23/suriname-invokes-30-day-grace-period-on-debt-payments' => '/web-articles/2020/10/suriname-invokes-30-day-grace-period-on-debt-payments',
			'/daily-briefs/2020/11/20/exclusive-braskem-weighs-transition-bond-for-2021' => '/web-articles/2020/11/exclusive-braskem-weighs-transition-bond-for-2021',
			'/daily-briefs/2020/11/20/exclusive-brazil-must-observe-spending-ceiling-in-2021-deputy-minister' => '/web-articles/2020/11/exclusive-brazil-must-observe-spending-ceiling-in-2021-deputy-minister',
			'/daily-briefs/2020/11/20/exclusive-brazil-plans-to-add-two-ports-to-privatization-pipeline' => '/web-articles/2020/11/exclusive-brazil-plans-to-add-two-ports-to-privatization-pipeline',
			'/daily-briefs/2020/11/20/exclusive-brazil-to-keep-international-debt-focused-on-us-european-markets' => '/web-articles/2020/11/exclusive-brazil-to-keep-international-debt-focused-on-us-european-markets',
			'/daily-briefs/2020/12/15/perus-head-of-public-treasury-resigns' => 'https://www.latinfinance.com/web-articles/2020/12/perus-head-of-public-treasury-resigns',
			'/daily-briefs/2020/3/2/ypf-lines-up-a-three-class-bond-issue' => '/web-articles/2020/2/ypf-lines-up-a-three-class-bond-issue',
			'/daily-briefs/2020/3/20/latam-2020-economic-recovery-will-not-happen-imf' => '/web-articles/2020/3/latam-2020-economic-recovery-will-not-happen-imf',
			'/daily-briefs/2020/3/24/ecuador-seeking-emergency-financing-to-help-battle-covid-19' => '/web-articles/2020/3/ecuador-seeking-emergency-financing-to-help-battle-covid-19',
			'/daily-briefs/2020/3/5/idb-says-monitoring-coronavirus-annual-meeting-still-on-track' => '/web-articles/2020/3/idb-says-monitoring-coronavirus-annual-meeting-still-on-track',
			'/daily-briefs/2020/3/9/argentina-provinces-fate-tied-to-sovereign-debt-restructure-chaco-governor' => 'https://www.latinfinance.com/web-articles/2020/3/argentina-provinces-fate-tied-to-sovereign-debt-restructure-chaco-governor',
			'/daily-briefs/2020/4/13/latin-american-q1-deal-activity-underpinned-by-banner-january' => '/daily-briefs/2020/4/13/latin-american-q1-2020-deal-activity-underpinned-by-banner-january',
			'/daily-briefs/2020/4/15/santander-m%c3%a9xico-breaks-cross-border-ice-with-five-year-bonds' => '/web-articles/2020/4/santander-m%c3%a9xico-breaks-cross-border-ice-with-five-year-bonds',
			'/daily-briefs/2020/4/20/ecuador-get-bondholder-consent-for-suspension-of-debt-service-until-august' => '/daily-briefs/2020/4/20/ecuador-gets-bondholder-consent-for-suspension-of-debt-service-until-august',
			'/daily-briefs/2020/4/20/interview-peru-s-new-debt-boosts-to-13-billion-resources-to-combat-covid-19' => '/daily-briefs/2020/4/20/interview-peru-s-new-debt-boosts-covid-19-fighting-resources-to-13-billion',
			'/daily-briefs/2020/4/21/bondholder-groups-reject-argentinas-restructuring-offer' => '/web-articles/2020/4/bondholder-groups-reject-argentinas-restructuring-offer',
			'/daily-briefs/2020/4/27/interview-mexico-went-for-cash-6-billion-worth-before-market-gets-crowded-deputy-minister' => '/daily-briefs/2020/4/27/interview-mexico-goes-for-cash-before-bond-market-gets-crowded',
			'/daily-briefs/2020/4/28/interview-latin-america-needs-to-save-lives-but-also-livelihoods-idb-s-moreno' => '/daily-briefs/2020/4/28/interview-latam-needs-to-save-lives-but-also-livelihoods-idbs-moreno',
			'/daily-briefs/2020/5/11/argentina-extends-restructuring-offer-to-monday' => '/web-articles/2020/5/argentina-extends-restructuring-offer-to-monday',
			'/daily-briefs/2020/5/26/argentina-investors-unfazed-at-restructuring-extension' => '/web-articles/2020/5/argentina-investors-unfazed-at-restructuring-extension',
			'/daily-briefs/2020/6/1/argentina-and-creditors-detail-new-debt-proposals-government-warns-challenges-remain' => '/web-articles/2020/5/argentina-and-creditors-detail-new-debt-proposals-government-warns-challenges-remain',
			'/daily-briefs/2020/6/1/imf-extends-credit-line-to-peru' => '/web-articles/2020/5/imf-extends-credit-line-to-peru',
			'/daily-briefs/2020/6/23/exclusive-brazil-looks-to-green-bonds-to-reduce-amazon-deforestation' => '/web-articles/2020/6/exclusive-brazil-looks-to-green-bonds-to-reduce-amazon-deforestation',
			'/daily-briefs/2020/6/8/buenos-aires-extends-deadline-for-restructuring-offer-possible-enhancements' => '/web-articles/2020/6/buenos-aires-extends-deadline-for-restructuring-offer-possible-enhancements',
			'/daily-briefs/2020/7/7/argentinas-new-bond-offer-attracting-investors-markets-rally' => '/web-articles/2020/7/argentinas-new-bond-offer-attracting-investors-markets-rally',
			'/daily-briefs/2020/8/14/isa-issues-first-green-bonds-in-colombia' => '/daily-briefs/2020/8/14/isa-issues-green-bonds-in-colombia',
			'/daily-briefs/2020/8/5/argentina-reaches-restructuring-deal-with-creditors' => '/web-articles/2020/8/argentina-reaches-restructuring-deal-with-creditors',
			'/daily-briefs/2020/9/9/cemex-to-buy-out-latam-busines' => '/daily-briefs/2020/9/9/cemex-to-buy-out-latam-business',
			'/daily-briefs/2021/1/26/inter-american-development-bank-to-hold-virtual-annual-meeting-for-second-time' => '/web-articles/2021/1/inter-american-development-bank-to-hold-virtual-annual-meeting-for-second-time',
			'/daily-briefs/2021/2/15/exclusive-brazils-novonor-seeks-partners-ahead-of-listing-in-2022' => '/web-articles/2021/2/exclusive-brazils-novonor-seeks-partners-ahead-of-listing-in-2022',
			'/daily-briefs/2021/3/8/ypf-to-seek-financing-for-investment-program' => '/web-articles/2021/3/ypf-to-seek-financing-for-investment-program',
			'/dbtrial' => 'https://lfp.dragonforms.com/lfp_lf_newtrial',
			'/dealsoftheyear' => 'https://www.latinfinance.com/awards/deals-of-the-year-awards/2022',
			'/doty' => 'https://www.latinfinance.com/awards/deals-of-the-year-awards/2021',
			'/doty2019/brochure' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/307bf219479c488c8557ce3caecff1e1.pdf',
			'/doty2019/invite' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/7273b2898a4e4c07bf808ee6cd850bc1.pdf',
			'/dotywinners' => 'http://read.nxtbook.com/latinfinance/latinfinance/2020_q1/deals_of_the_year_awards.html',
			'/editorialcalendar' => 'https://www.latinfinance.com/media/2294/lf-mk-2020_editorial-contentcal.pdf',
			'/esg' => '/topics/esg',
			'/esgfocus' => '/topics/esg',
			'/factbox' => '/daily-briefs/2020/5/15/factbox-51520-latin-america-moves-to-mitigate-impact-of-covid-19',
			'/felaban' => 'https://myaccount.latinfinance.com/subcnew.aspx?PC=LF&pk=rtrial&utm_source=lf&utm_medium=print&utm_campaign=felaban',
			'/fintech' => '/magazine/2018/may-june-2018/plotting-the-future-a-discussion-on-fintechs-in-brazil',
			'/freetrial' => 'https://lfp.dragonforms.com/lfp_lf_newtrial',
			'/https://www.latinfinance.com/daily-briefs/2020/4/20/bondholder-groups-reject-argentinas-restructuring-offer' => 'https://www.latinfinance.com/web-articles/2020/4/bondholder-groups-reject-argentinas-restructuring-offer',
			'/idb19/register' => 'http://www.latinfinanceevents.com/d/0yqmd8/4W',
			'/idb19/register/cacibguest' => 'http://www.latinfinanceevents.com/d/0yqmd8/4W?ct=f866a3cd-2226-4cf1-924d-8569cf09f05d&RefID=SPcAcIBFree',
			'/idb19/register/cliffordchanceguest' => 'http://www.latinfinanceevents.com/d/0yqmd8/4W?ct=f866a3cd-2226-4cf1-924d-8569cf09f05d&RefID=SpCChanceVip',
			'/idbbreakfast/agenda' => 'https://read.nxtbook.com/latinfinance/events/latin_america_sovereign_debt_/agenda.html',
			'/idb-breakfast-2019' => 'https://www.latinfinanceevents.com/d/0yqmd8/',
			'/idforum/2020' => 'https://cvent.me/870E4E',
			'/idforum/agenda' => 'https://read.nxtbook.com/latinfinance/events/1st_integration_development_f/agenda_spanish.html',
			'/idforum/register' => 'https://mailchi.mp/ec46daf0cc22/2020-fonplata-request-pass-english',
			'/idforum/workbook' => 'https://read.nxtbook.com/latinfinance/events/1st_integration_development_f/agenda_spanish.html',
			'/julyoffer' => 'https://myaccount.latinfinance.com/subcnew.aspx?PC=LF&pk=rtrial&utm_source=lf&utm_medium=print&utm_campaign=db-trial&utm_content=july-august-2018-magazine-spread',
			'/lacapmkts/agenda' => 'https://read.nxtbook.com/latinfinance/events/capital_markets_summit/agenda.html',
			'/lacif/agenda' => 'https://custom.cvent.com/6F68ACCBE09541E581894A6AFB845055/files/ee05b84da13b4e40808b274b4d11230b.pdf',
			'/lacif/register' => 'http://www.latinfinanceevents.com/d/jgqc7y/8K?RefID=Custom+Fees',
			'/lf25' => 'http://www.nxtbook.com/nxtbooks/latinfinance/89456RBM/index.php',
			'/lf30' => 'https://latinfinance.us16.list-manage.com/subscribe?u=3f72033fc318b70e76ee07ccf&id=a643fad7cc',
			'/lf-overview' => 'https://www.latinfinance.com/media/2388/lf-media-kit-2020-about-latinfinance.pdf',
			'/lf-q3-2019' => '/media/2111/lf-q3-no-rates.pdf',
			'/linkedin-factbox' => '/daily-briefs/2020/5/15/factbox-51520-latin-america-moves-to-mitigate-impact-of-covid-19?utm_source=linkedin&utm_medium=paid&utm_campaign=coronavirus-factbox',
			'/magazine/2018/november-december-2018/bank-of-the-year-2018-el-slavador-banco-agrícola' => '/magazine/2018/november-december-2018/bank-of-the-year-2018-el-salvador-banco-agrícola',
			'/magazine/2019/2019q3/2019-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2019',
			'/magazine/2019/2019q3/read-digital-edtion' => 'http://read.nxtbook.com/latinfinance/latinfinance/2019_q3/latinfinance_2019_q3_the_esg_.html',
			'/magazine/2019/q4/banks-of-the-year-awards' => '/awards/banks-of-the-year-awards/2019',
			'/magazine/2019/q4/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/latinfinance/2019_q4/index.html',
			'/magazine/2019/spring-2019/read-digital-edition' => 'https://latinfinance.us16.list-manage.com/subscribe?u=3f72033fc318b70e76ee07ccf&id=a643fad7cc',
			'/magazine/2020/q1/deals-of-the-year-awards' => '/awards/deals-of-the-year-awards/2019',
			'/magazine/2020/q1/read-digital-edition' => 'http://read.nxtbook.com/latinfinance/latinfinance/2020_q1/cover.html',
			'/magazine/2020/q2/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q2/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q2-issue',
			'/magazine/2020/q3/2020-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2020',
			'/magazine/2020/q3/latinfinances-2020-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2020',
			'/magazine/2020/q3/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q3/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q3-issue',
			'/magazine/2020/q4-coping-with-covid/2020-banks-of-the-year-awards' => '/awards/banks-of-the-year-awards/2020',
			'/magazine/2020/q4-coping-with-covid/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q4/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q4-issue',
			'/magazine/2021/q1-the-covid-rebuild/2020-deals-of-the-year-awards' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q1/doty.html?utm_source=lf&utm_medium=referral&utm_campaign=2021-q1-issue',
			'/magazine/2021/q1-the-covid-rebuild/podcast-timing-is-everything' => '/web-articles/2021/3/podcast-timing-is-everything',
			'/magazine/2021/q1-the-covid-rebuild/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q1/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2021-q1-issue',
			'/magazine/2021/q2-visions-of-the-new-world/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q2/q2.html',
			'/magazine/2021/q3q4-the-long-road/2021-project-infrastructure-finance-awards' => '/awards/project-infrastructure-finance-awards/2021',
			'/magazine/2021/q3q4-the-long-road/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q3_q4/index.html',
			'/magazine/2022/q1-bank-to-the-future/2021-banks-of-the-year-awards' => '/awards/banks-of-the-year-awards/2021',
			'/magazine/2022/q1-bank-to-the-future/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q1/index.html',
			'/magazine/2022/q2-on-the-edge/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q2/cover.html',
			'/magazine/2022/q3-the-commodities-promise' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q3/cover.html',
			'/magazine/2022/q4-in-the-balance/project-infrastructure-finance-awards' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q4/winners.html',
			'/magazine/2022/q4-in-the-balance/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q4/q4_2022.html',
			'/magazine/2023/q1-beyond-the-horizon/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q1/cover.html',
			'/magazine/2023/q2-when-the-wind-blows/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q2/cover.html',
			'/magazine/2023/q3-the-big-cleanup/read-digital-edition' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q3/cover.html',
			'/magazine-archive' => 'http://nxtbook.com/fx/archives/view.php?id=c5b7929c8d31642b174723b97634c67a',
			'/mexicosubsov' => 'http://www.latinfinanceevents.com/d/9gqcqz?RefID=LnkP1',
			'/mxsubnational/interest' => 'https://www.latinfinance.com/events',
			'/mxsubsov/workbook' => 'https://read.nxtbook.com/latinfinance/events/2019_mexico_subsov_summit/cover.html',
			'/pafif/2020' => 'https://cvent.me/GV5Gxw',
			'/pafif/agenda' => 'https://read.nxtbook.com/latinfinance/events/pacific_alliance_finance_inve/agenda.html',
			'/pafif/register' => 'https://mailchi.mp/f29100d51837/2020-pacific-alliance-forum-request-pass-english',
			'/pif/dinner/reservation' => 'http://www.latinfinanceevents.com/d/1yqksj/4W?ct=6f1ecc35-0921-44f1-9530-e709c13da9c9&RefID=Single+Seat+Registration',
			'/pif/register' => 'http://www.latinfinanceevents.com/d/myqp7l/8K?RefID=Custom+Fees',
			'/pif/workbook' => 'https://read.nxtbook.com/latinfinance/latinfinance/lf_events_pif_summit/the_4th_latinfinance_project_.html',
			'/pifawards' => 'https://www.latinfinance.com/awards/project-infrastructure-finance-awards/2023',
			'/projectinfra/agenda' => 'http://www.latinfinanceevents.com/events/latin-america-project-infrastructure-finance-summit/agenda-b55ac659a4af4fd2b00d386ea6c7b6ea.aspx?RefID=MLFW',
			'/q2' => '/media/2386/q2-2020-latinfinance.pdf',
			'/scotiasub' => 'https://myaccount.latinfinance.com/LF/register.aspx?PC=LF&BC=SBANK',
			'/trial' => 'https://myaccount.latinfinance.com/subcnew.aspx?PC=LF&pk=rtrial',
			'/web-articles/2018/2/investors-brace-for-election-nafta-renegotiation-outcomes' => 'http://www.latinfinance.com/web-articles/2018/2/investors-brace-for-election-nafta-renegotiation',
			'/web-articles/2018/3/qa-albright-capital-talks-latam-ventures' => '/web-articles/2018/3/albright-capital-talks-latam-ventures',
			'/web-articles/2019/10/barbados-reaches-debt-restructuring-deal-with-creditors' => '/daily-briefs/2019/10/21/barbados-reaches-debt-restructuring-deal-with-creditors',
			'/web-articles/2020/1/live-stream-the-5th-latin-america-capital-markets-summit' => '/web-articles/2020/1/video-replay-the-5th-latin-america-capital-markets-summit',
			'/web-articles/2020/10/q32020-magazine-picking-up-the-pieces' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q3/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q3-issue',
			'/web-articles/2020/12/q42020-magazine-coping-with-covid' => 'https://read.nxtbook.com/latinfinance/magazine/2020_q4/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2020-q4-issue',
			'/web-articles/2020/2/live-stream-the-1st-integration-development-forum' => '/web-articles/2020/3/live-stream-the-1st-integration-development-forum',
			'/web-articles/2020/3/live-stream-the-1st-integration-development-forum' => '/web-articles/2020/3/video-the-1st-integration-development-forum',
			'/web-articles/2021/12/q1-magazine-bank-to-the-future' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q1/index.html',
			'/web-articles/2021/3/q12021-magazine-the-covid-rebuild' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q1/cover.html?utm_source=lf&utm_medium=referral&utm_campaign=2021-q1-issue',
			'/web-articles/2021/6/q2-2021-magazine-visions-of-the-new-world' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q2/q2.html',
			'/web-articles/2021/9/q3q4-magazine-the-long-road' => 'https://read.nxtbook.com/latinfinance/magazine/2021_q3_q4/index.html',
			'/web-articles/2022/12/q1-magazine-beyond-the-horizon' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q1/cover.html',
			'/web-articles/2022/7/q3-magazine-the-commodities-promise' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q3/cover.html',
			'/web-articles/2022/9/q4-magazine-in-the-balance' => 'https://read.nxtbook.com/latinfinance/magazine/2022_q4/q4_2022.html',
			'/web-articles/2023/3/q2-magazine-when-the-wind-blows' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q2/cover.html',
			'/web-articles/2023/6/q3-magazine-the-big-cleanup' => 'https://read.nxtbook.com/latinfinance/magazine/2023_q3/cover.html',
		);
	}

	private function hack_data_mailchimp_dates() {

		// imported LIVE values from 2023-09-15
		// this is not all the WXR dates...it's only the actual posts that were inserted.  Many WXR imports failed...
		
		return array(
			'2023-08-25',
			'2023-08-24',
			'2023-08-23',
			'2023-08-22',
			'2023-08-17',
			'2023-08-16',
			'2023-08-11',
			'2023-08-08',
			'2023-08-07',
			'2023-08-04',
			'2023-08-03',
			'2023-08-02',
			'2023-08-01',
			'2023-07-31',
			'2023-07-28',
			'2023-07-27',
			'2023-07-26',
			'2023-07-25',
			'2023-07-24',
			'2023-07-21',
			'2023-07-20',
			'2023-07-19',
			'2023-07-18',
			'2023-07-17',
			'2023-07-13',
			'2023-07-12',
			'2023-07-11',
			'2023-07-10',
			'2023-07-07',
			'2023-07-06',
			'2023-07-05',
			'2023-07-03',
			'2023-06-30',
			'2023-06-29',
			'2023-06-28',
			'2023-06-27',
			'2023-06-26',
			'2023-06-23',
			'2023-06-22',
			'2023-06-19',
			'2023-06-14',
			'2023-06-13',
			'2023-06-12',
			'2023-06-09',
			'2023-06-07',
			'2023-06-06',
			'2023-06-05',
			'2023-06-02',
			'2023-06-01',
			'2023-05-31',
			'2023-05-29',
			'2023-05-26',
			'2023-05-25',
			'2023-05-24',
			'2023-05-23',
			'2023-05-22',
			'2023-05-19',
			'2023-05-18',
			'2023-05-17',
			'2023-05-16',
			'2023-05-15',
			'2023-05-12',
			'2023-05-11',
			'2023-05-10',
			'2023-05-09',
			'2023-05-08',
			'2023-05-05',
			'2023-05-04',
			'2023-05-03',
			'2023-05-01',
			'2023-04-28',
			'2023-04-27',
			'2023-04-26',
			'2023-04-25',
			'2023-04-24',
			'2023-04-21',
			'2023-04-20',
			'2023-04-19',
			'2023-04-17',
			'2023-04-14',
			'2023-04-13',
			'2023-04-12',
			'2023-04-11',
			'2023-04-07',
			'2023-04-06',
			'2023-04-05',
			'2023-04-04',
			'2023-03-15',
			'2023-03-14',
			'2023-03-13',
			'2023-03-10',
			'2023-03-09',
			'2023-03-08',
			'2023-03-07',
			'2023-03-06',
			'2023-03-03',
			'2023-03-02',
			'2023-03-01',
			'2023-02-28',
			'2023-02-27',
			'2023-02-24',
			'2023-02-23',
			'2023-02-22',
			'2023-02-20',
			'2023-02-17',
			'2023-02-16',
			'2023-02-15',
			'2023-02-14',
			'2023-02-13',
			'2023-02-10',
			'2023-02-09',
			'2023-02-08',
			'2023-02-07',
			'2023-02-06',
			'2023-02-03',
			'2023-02-02',
			'2023-02-01',
			'2023-01-31',
			'2023-01-30',
			'2023-01-27',
			'2023-01-26',
			'2023-01-25',
			'2023-01-24',
			'2023-01-23',
			'2023-01-20',
			'2023-01-19',
			'2023-01-18',
			'2023-01-16',
			'2023-01-13',
			'2023-01-12',
			'2023-01-11',
			'2023-01-10',
			'2023-01-09',
			'2023-01-05',
			'2023-01-04',
			'2022-12-26',
			'2022-12-23',
			'2022-12-22',
			'2022-12-21',
			'2022-12-20',
			'2022-12-19',
			'2022-12-16',
			'2022-12-15',
			'2022-12-14',
			'2022-12-13',
			'2022-12-12',
			'2022-12-09',
			'2022-12-08',
			'2022-12-07',
			'2022-12-06',
			'2022-12-05',
			'2022-12-02',
			'2022-12-01',
			'2022-11-30',
			'2022-11-29',
			'2022-11-24',
			'2022-11-23',
			'2022-11-22',
			'2022-11-21',
			'2022-11-18',
			'2022-11-17',
			'2022-11-16',
			'2022-11-14',
			'2022-11-11',
			'2022-11-10',
			'2022-11-09',
			'2022-11-08',
			'2022-11-07',
			'2022-11-04',
			'2022-11-03',
			'2022-11-02',
			'2022-10-31',
			'2022-10-28',
			'2022-10-27',
			'2022-10-26',
			'2022-10-25',
			'2022-10-24',
			'2022-10-21',
			'2022-10-20',
			'2022-10-19',
			'2022-10-18',
			'2022-10-17',
			'2022-10-14',
			'2022-10-13',
			'2022-10-12',
			'2022-10-11',
			'2022-10-10',
			'2022-10-07',
			'2022-10-06',
			'2022-10-05',
			'2022-10-04',
			'2022-10-03',
			'2022-09-30',
			'2022-09-28',
			'2022-09-27',
			'2022-09-26',
			'2022-09-23',
			'2022-09-22',
			'2022-09-21',
			'2022-09-20',
			'2022-09-19',
			'2022-09-16',
			'2022-09-15',
			'2022-09-14',
			'2022-09-13',
			'2022-09-12',
			'2022-09-09',
			'2022-09-08',
			'2022-09-07',
			'2022-09-05',
			'2022-09-02',
			'2022-09-01',
			'2022-08-31',
			'2022-08-30',
			'2022-08-29',
			'2022-08-26',
			'2022-08-25',
			'2022-08-24',
			'2022-08-23',
			'2022-08-22',
			'2022-08-19',
			'2022-08-18',
			'2022-08-17',
			'2022-08-16',
			'2022-08-15',
			'2022-08-12',
			'2022-08-11',
			'2022-08-10',
			'2022-08-09',
			'2022-08-08',
			'2022-08-05',
			'2022-08-04',
			'2022-08-03',
			'2022-08-02',
			'2022-08-01',
			'2022-07-29',
			'2022-07-28',
			'2022-07-27',
			'2022-07-26',
			'2022-07-25',
			'2022-07-22',
			'2022-07-21',
			'2022-07-20',
			'2022-07-19',
			'2022-07-18',
			'2022-07-15',
			'2022-07-14',
			'2022-07-13',
			'2022-07-12',
			'2022-07-11',
			'2022-07-08',
			'2022-07-06',
			'2022-07-04',
			'2022-07-01',
			'2022-06-30',
			'2022-06-29',
			'2022-06-28',
			'2022-06-27',
			'2022-06-24',
			'2022-06-23',
			'2022-06-22',
			'2022-06-20',
			'2022-06-17',
			'2022-06-16',
			'2022-06-15',
			'2022-06-14',
			'2022-06-13',
			'2022-06-10',
			'2022-06-09',
			'2022-06-06',
			'2022-06-03',
			'2022-06-02',
			'2022-06-01',
			'2022-05-30',
			'2022-05-27',
			'2022-05-26',
			'2022-05-25',
			'2022-05-24',
			'2022-05-23',
			'2022-05-20',
			'2022-05-19',
			'2022-05-18',
			'2022-05-17',
			'2022-05-16',
			'2022-05-13',
			'2022-05-12',
			'2022-05-11',
			'2022-05-10',
			'2022-05-09',
			'2022-05-06',
			'2022-05-05',
			'2022-05-04',
			'2022-05-03',
			'2022-05-02',
			'2022-04-29',
			'2022-04-28',
			'2022-04-27',
			'2022-04-26',
			'2022-04-25',
			'2022-04-22',
			'2022-04-21',
			'2022-04-20',
			'2022-04-19',
			'2022-04-15',
			'2022-04-14',
			'2022-04-13',
			'2022-04-12',
			'2022-04-11',
			'2022-04-08',
			'2022-04-07',
			'2022-04-06',
			'2022-04-05',
			'2022-04-04',
			'2022-04-01',
			'2022-03-31',
			'2022-03-30',
			'2022-03-29',
			'2022-03-28',
			'2022-03-25',
			'2022-03-24',
			'2022-03-23',
			'2022-03-22',
			'2022-03-21',
			'2022-03-18',
			'2022-03-17',
			'2022-03-16',
			'2022-03-15',
			'2022-03-14',
			'2022-03-11',
			'2022-03-10',
			'2022-03-09',
			'2022-03-08',
			'2022-03-07',
			'2022-03-04',
			'2022-03-03',
			'2022-03-02',
			'2022-03-01',
			'2022-02-28',
			'2022-02-25',
			'2022-02-24',
			'2022-02-23',
			'2022-02-21',
			'2022-02-18',
			'2022-02-17',
			'2022-02-16',
			'2022-02-15',
			'2022-02-14',
			'2022-02-11',
			'2022-02-10',
			'2022-02-09',
			'2022-02-08',
			'2022-02-07',
			'2022-02-04',
			'2022-02-03',
			'2022-02-01',
			'2022-01-31',
			'2022-01-28',
			'2022-01-27',
			'2022-01-26',
			'2022-01-25',
			'2022-01-24',
			'2022-01-21',
			'2022-01-20',
			'2022-01-19',
			'2022-01-17',
			'2022-01-14',
			'2022-01-13',
			'2022-01-12',
			'2022-01-11',
			'2022-01-10',
			'2022-01-07',
			'2022-01-06',
			'2022-01-05',
			'2021-12-24',
			'2021-12-23',
			'2021-12-22',
			'2021-12-21',
			'2021-12-20',
			'2021-12-17',
			'2021-12-16',
			'2021-12-15',
			'2021-12-14',
			'2021-12-13',
			'2021-12-10',
			'2021-12-09',
			'2021-12-08',
			'2021-12-07',
			'2021-12-06',
			'2021-12-03',
			'2021-12-02',
			'2021-12-01',
			'2021-11-30',
			'2021-11-25',
			'2021-11-24',
			'2021-11-23',
			'2021-11-19',
			'2021-11-18',
			'2021-11-17',
			'2021-11-16',
			'2021-11-15',
			'2021-11-12',
			'2021-11-11',
			'2021-11-10',
			'2021-11-09',
			'2021-11-08',
			'2021-11-05',
			'2021-11-04',
			'2021-11-03',
			'2021-11-02',
			'2021-11-01',
			'2021-10-29',
			'2021-10-28',
			'2021-10-27',
			'2021-10-26',
			'2021-10-25',
			'2021-10-22',
			'2021-10-21',
			'2021-10-20',
			'2021-10-19',
			'2021-10-18',
			'2021-10-15',
			'2021-10-14',
			'2021-10-13',
			'2021-10-12',
			'2021-10-11',
			'2021-10-08',
			'2021-10-07',
			'2021-10-06',
			'2021-10-05',
			'2021-10-04',
			'2021-10-01',
			'2021-09-30',
			'2021-09-29',
			'2021-09-28',
			'2021-09-27',
			'2021-09-24',
			'2021-09-23',
			'2021-09-22',
			'2021-09-21',
			'2021-09-20',
			'2021-09-17',
			'2021-09-16',
			'2021-09-15',
			'2021-09-14',
			'2021-09-13',
			'2021-09-10',
			'2021-09-09',
			'2021-09-08',
			'2021-09-07',
			'2021-09-03',
			'2021-09-02',
			'2021-09-01',
			'2021-08-31',
			'2021-08-30',
			'2021-08-27',
			'2021-08-26',
			'2021-08-25',
			'2021-08-24',
			'2021-08-23',
			'2021-08-20',
			'2021-08-19',
			'2021-08-18',
			'2021-08-17',
			'2021-08-16',
			'2021-08-13',
			'2021-08-12',
			'2021-08-11',
			'2021-08-10',
			'2021-08-06',
			'2021-08-05',
			'2021-08-04',
			'2021-08-03',
			'2021-08-02',
			'2021-07-30',
			'2021-07-29',
			'2021-07-28',
			'2021-07-27',
			'2021-07-26',
			'2021-07-23',
			'2021-07-22',
			'2021-07-21',
			'2021-07-20',
			'2021-07-19',
			'2021-07-16',
			'2021-07-15',
			'2021-07-14',
			'2021-07-13',
			'2021-07-12',
			'2021-07-09',
			'2021-07-08',
			'2021-07-07',
			'2021-07-05',
			'2021-07-02',
			'2021-07-01',
			'2021-06-30',
			'2021-06-29',
			'2021-06-28',
			'2021-06-25',
			'2021-06-24',
			'2021-06-23',
			'2021-06-22',
			'2021-06-21',
			'2021-06-18',
			'2021-06-17',
			'2021-06-16',
			'2021-06-15',
			'2021-06-14',
			'2021-06-11',
			'2021-06-10',
			'2021-06-09',
			'2021-06-08',
			'2021-06-07',
			'2021-06-04',
			'2021-06-03',
			'2021-06-02',
			'2021-05-31',
			'2021-05-28',
			'2021-05-27',
			'2021-05-26',
			'2021-05-25',
			'2021-05-24',
			'2021-05-21',
			'2021-05-20',
			'2021-05-19',
			'2021-05-18',
			'2021-05-17',
			'2021-05-14',
			'2021-05-13',
			'2021-05-12',
			'2021-05-11',
			'2021-05-10',
			'2021-05-07',
			'2021-05-06',
			'2021-05-05',
			'2021-05-04',
			'2021-05-03',
			'2021-04-30',
			'2021-04-28',
			'2021-04-27',
			'2021-04-26',
			'2021-04-23',
			'2021-04-22',
			'2021-04-21',
			'2021-04-20',
			'2021-04-19',
			'2021-04-16',
			'2021-04-15',
			'2021-04-14',
			'2021-04-13',
			'2021-04-12',
			'2021-04-09',
			'2021-04-08',
			'2021-04-07',
			'2021-04-06',
			'2021-04-02',
			'2021-04-01',
			'2021-03-31',
			'2021-03-30',
			'2021-03-29',
			'2021-03-26',
			'2021-03-25',
			'2021-03-24',
			'2021-03-23',
			'2021-03-22',
			'2021-03-19',
			'2021-03-18',
			'2021-03-17',
			'2021-03-16',
			'2021-03-15',
			'2021-03-12',
			'2021-03-11',
			'2021-03-10',
			'2021-03-09',
			'2021-03-08',
			'2021-03-05',
			'2021-03-04',
			'2021-03-03',
			'2021-03-02',
			'2021-03-01',
			'2021-02-26',
			'2021-02-25',
			'2021-02-24',
			'2021-02-23',
			'2021-02-22',
			'2021-02-19',
			'2021-02-18',
			'2021-02-17',
			'2021-02-15',
			'2021-02-12',
			'2021-02-11',
			'2021-02-10',
			'2021-02-09',
			'2021-02-08',
			'2021-02-05',
			'2021-02-04',
			'2021-02-03',
			'2021-02-02',
			'2021-02-01',
			'2021-01-29',
			'2021-01-28',
			'2021-01-27',
			'2021-01-26',
			'2021-01-25',
			'2021-01-22',
			'2021-01-21',
			'2021-01-20',
			'2021-01-18',
			'2021-01-15',
			'2021-01-14',
			'2021-01-13',
			'2021-01-12',
			'2021-01-11',
			'2021-01-08',
			'2021-01-07',
			'2021-01-06',
			'2021-01-05',
			'2020-12-24',
			'2020-12-23',
			'2020-12-22',
			'2020-12-21',
			'2020-12-18',
			'2020-12-17',
			'2020-12-16',
			'2020-12-15',
			'2020-12-14',
			'2020-12-11',
			'2020-12-10',
			'2020-12-09',
			'2020-12-08',
			'2020-12-07',
			'2020-12-04',
			'2020-12-03',
			'2020-12-02',
			'2020-12-01',
			'2020-11-26',
			'2020-11-25',
			'2020-11-24',
			'2020-11-23',
			'2020-11-20',
			'2020-11-19',
			'2020-11-18',
			'2020-11-17',
			'2020-11-16',
			'2020-11-13',
			'2020-11-12',
			'2020-11-11',
			'2020-11-10',
			'2020-11-09',
			'2020-11-06',
			'2020-11-05',
			'2020-11-04',
			'2020-11-03',
			'2020-11-02',
			'2020-10-30',
			'2020-10-29',
			'2020-10-28',
			'2020-10-27',
			'2020-10-26',
			'2020-10-23',
			'2020-10-22',
			'2020-10-21',
			'2020-10-20',
			'2020-10-19',
			'2020-10-16',
			'2020-10-15',
			'2020-10-14',
			'2020-10-13',
			'2020-10-12',
			'2020-10-09',
			'2020-10-08',
			'2020-10-07',
			'2020-10-06',
			'2020-10-05',
			'2020-10-02',
			'2020-10-01',
			'2020-09-30',
			'2020-09-29',
			'2020-09-28',
			'2020-09-14',
			'2020-09-11',
			'2020-09-10',
			'2020-09-09',
			'2020-09-07',
			'2020-09-04',
			'2020-09-03',
			'2020-09-02',
			'2020-09-01',
			'2020-08-31',
			'2020-08-28',
			'2020-08-27',
			'2020-08-26',
			'2020-08-25',
			'2020-08-24',
			'2020-08-21',
			'2020-08-20',
			'2020-08-19',
			'2020-08-18',
			'2020-08-17',
			'2020-08-14',
			'2020-08-13',
			'2020-08-12',
			'2020-08-11',
			'2020-07-02',
			'2020-07-01',
			'2020-06-30',
			'2020-06-29',
			'2020-06-26',
			'2020-06-25',
			'2020-06-24',
			'2020-06-23',
			'2020-06-22',
			'2020-06-19',
			'2020-06-18',
			'2020-06-17',
			'2020-06-16',
			'2020-06-15',
			'2020-06-12',
			'2020-06-11',
			'2020-06-10',
			'2020-06-09',
			'2020-06-08',
			'2020-04-21',
			'2020-04-20',
			'2020-04-17',
			'2020-04-16',
			'2020-04-15',
			'2020-04-14',
			'2020-04-13',
			'2020-04-10',
			'2020-04-09',
			'2020-04-08',
			'2020-04-07',
			'2020-04-06',
			'2020-04-03',
			'2020-04-02',
			'2020-03-31',
			'2020-03-30',
			'2020-03-27',
			'2020-03-26',
			'2020-03-25',
			'2020-03-24',
			'2020-03-23',
			'2020-03-20',
			'2020-03-19',
			'2020-03-18',
			'2020-03-17',
			'2020-03-16',
			'2020-03-13',
			'2020-03-12',
			'2020-03-11',
			'2020-03-10',
			'2020-03-09',
			'2020-03-06',
			'2020-03-05',
			'2020-03-04',
			'2020-03-03',
			'2020-03-02',
			'2020-02-28',
			'2020-02-27',
			'2020-02-26',
			'2020-02-25',
			'2020-02-24',
			'2020-02-21',
			'2020-02-20',
			'2020-02-19',
			'2020-02-18',
			'2020-02-14',
			'2020-02-13',
			'2020-02-12',
			'2020-02-11',
			'2020-02-10',
			'2020-02-07',
			'2020-02-06',
			'2020-02-05',
			'2020-02-04',
			'2020-02-03',
			'2020-01-31',
			'2020-01-30',
			'2020-01-29',
			'2020-01-28',
			'2020-01-27',
			'2020-01-24',
			'2020-01-23',
			'2020-01-22',
			'2020-01-21',
			'2020-01-17',
			'2020-01-16',
			'2020-01-15',
			'2020-01-14',
			'2020-01-13',
			'2020-01-10',
			'2020-01-09',
			'2020-01-08',
			'2020-01-07',
			'2020-01-06',
			'2020-01-03',
			'2019-12-31',
			'2019-12-24',
			'2019-12-23',
			'2019-12-20',
			'2019-12-19',
			'2019-12-18',
			'2019-12-17',
			'2019-12-16',
			'2019-12-13',
			'2019-12-12',
			'2019-12-11',
			'2019-12-10',
			'2019-12-09',
			'2019-12-06',
			'2019-12-05',
			'2019-12-04',
			'2019-12-03',
			'2019-11-28',
			'2019-11-27',
			'2019-11-26',
			'2019-11-25',
			'2019-11-22',
			'2019-11-21',
			'2019-11-20',
			'2019-11-19',
			'2019-11-18',
			'2019-11-15',
			'2019-11-14',
			'2019-11-13',
			'2019-11-11',
			'2019-11-08',
			'2019-11-07',
			'2019-11-05',
			'2019-11-04',
			'2019-11-01',
			'2019-10-31',
			'2019-10-30',
			'2019-10-29',
			'2019-10-28',
			'2019-10-23',
			'2019-10-22',
			'2019-10-21',
			'2019-10-18',
			'2019-10-17',
			'2019-10-16',
			'2019-10-15',
			'2019-10-14',
			'2019-10-11',
			'2019-10-10',
			'2019-10-09',
			'2019-10-07',
			'2019-10-04',
			'2019-10-03',
			'2019-10-02',
			'2019-10-01',
			'2019-09-30',
			'2019-09-27',
			'2019-09-26',
			'2019-09-25',
			'2019-09-24',
			'2019-09-23',
			'2019-09-20',
			'2019-09-19',
			'2019-09-18',
			'2019-09-17',
			'2019-09-16',
			'2019-09-13',
			'2019-09-12',
			'2019-09-11',
			'2019-09-10',
			'2019-09-09',
			'2019-09-06',
			'2019-09-05',
			'2019-09-04',
			'2019-09-03',
			'2019-08-30',
			'2019-08-29',
			'2019-08-28',
			'2019-08-27',
			'2019-08-26',
			'2019-08-23',
			'2019-08-22',
			'2019-08-21',
			'2019-08-20',
			'2019-08-19',
			'2019-08-16',
			'2019-08-15',
			'2019-08-14',
			'2019-08-13',
			'2019-08-12',
			'2019-08-09',
			'2019-08-08',
			'2019-08-07',
			'2019-08-06',
			'2019-08-05',
			'2019-08-02',
			'2019-08-01',
			'2019-07-31',
			'2019-07-30',
			'2019-07-29',
			'2019-07-26',
			'2019-07-25',
			'2019-07-24',
			'2019-07-23',
			'2019-07-22',
			'2019-07-19',
			'2019-07-18',
			'2019-07-17',
			'2019-07-12',
			'2019-07-11',
			'2019-07-10',
			'2019-07-09',
			'2019-07-08',
			'2019-07-03',
			'2019-07-02',
			'2019-07-01',
			'2019-06-28',
			'2019-06-27',
			'2019-06-26',
			'2019-06-25',
			'2019-06-24',
			'2019-06-21',
			'2019-06-20',
			'2019-06-19',
			'2019-06-18',
			'2019-06-17',
			'2019-06-14',
			'2019-06-13',
			'2019-06-12',
			'2019-06-11',
			'2019-06-10',
			'2019-06-07',
			'2019-06-06',
			'2019-06-05',
			'2019-06-04',
			'2019-06-03',
			'2019-05-31',
			'2019-05-30',
			'2019-05-29',
			'2019-05-28',
			'2019-05-24',
			'2019-05-23',
			'2019-05-22',
			'2019-05-21',
			'2019-05-20',
			'2019-05-17',
			'2019-05-16',
			'2019-05-15',
			'2019-05-14',
			'2019-05-13',
			'2019-05-10',
			'2019-05-09',
			'2019-05-08',
			'2019-05-07',
			'2019-05-06',
			'2019-05-03',
			'2019-05-02',
			'2019-05-01',
			'2019-04-30',
			'2019-04-29',
			'2019-04-26',
			'2019-04-25',
			'2019-04-24',
			'2019-04-22',
			'2019-04-18',
			'2019-04-17',
			'2019-04-16',
			'2019-04-15',
			'2019-04-12',
			'2019-04-11',
			'2019-04-10',
			'2019-04-09',
			'2019-04-08',
			'2019-04-05',
			'2019-04-04',
			'2019-04-03',
			'2019-04-02',
			'2019-04-01',
			'2019-03-29',
			'2019-03-28',
			'2019-03-27',
			'2019-03-25',
			'2019-03-22',
			'2019-03-21',
			'2019-03-20',
			'2019-03-19',
			'2019-03-18',
			'2019-03-15',
			'2019-03-14',
			'2019-03-13',
			'2019-03-12',
			'2019-03-11',
			'2019-03-08',
			'2019-03-07',
			'2019-03-06',
			'2019-03-05',
			'2019-03-04',
			'2019-03-01',
			'2019-02-28',
			'2019-02-27',
			'2019-02-26',
			'2019-02-25',
			'2019-02-22',
			'2019-02-21',
			'2019-02-20',
			'2019-02-19',
			'2019-02-15',
			'2019-02-14',
			'2019-02-13',
			'2019-02-12',
			'2019-02-11',
			'2019-02-08',
			'2019-02-07',
			'2019-02-06',
			'2019-02-05',
			'2019-02-04',
			'2019-02-01',
			'2019-01-31',
			'2019-01-30',
			'2019-01-29',
			'2019-01-28',
			'2019-01-25',
			'2019-01-24',
			'2019-01-23',
			'2019-01-22',
			'2019-01-18',
			'2019-01-17',
			'2019-01-16',
			'2019-01-15',
			'2019-01-14',
			'2019-01-11',
			'2019-01-10',
			'2019-01-09',
			'2019-01-08',
			'2019-01-07',
			'2019-01-04',
			'2019-01-03',
			'2018-12-24',
			'2018-12-21',
			'2018-12-20',
			'2018-12-19',
			'2018-12-18',
			'2018-12-17',
			'2018-12-14',
			'2018-12-13',
			'2018-12-12',
			'2018-12-11',
			'2018-12-07',
			'2018-12-06',
			'2018-12-05',
			'2018-12-04',
			'2018-12-03',
			'2018-11-30',
			'2018-11-29',
			'2018-11-28',
			'2018-11-27',
			'2018-11-26',
			'2018-11-21',
			'2018-11-20',
			'2018-11-19',
			'2018-11-16',
			'2018-11-15',
			'2018-11-14',
			'2018-11-13',
			'2018-11-12',
			'2018-11-09',
			'2018-11-08',
			'2018-11-07',
			'2018-11-06',
			'2018-11-05',
			'2018-11-02',
			'2018-11-01',
			'2018-10-31',
			'2018-10-30',
			'2018-10-29',
			'2018-10-26',
			'2018-10-25',
			'2018-10-24',
			'2018-10-23',
			'2018-10-22',
			'2018-10-19',
			'2018-10-18',
			'2018-10-17',
			'2018-10-16',
			'2018-10-15',
			'2018-10-12',
			'2018-10-11',
			'2018-10-10',
			'2018-10-09',
			'2018-10-08',
			'2018-10-01',
			'2018-09-28',
			'2018-09-27',
			'2018-09-26',
			'2018-09-25',
			'2018-09-24',
			'2018-09-21',
			'2018-09-20',
			'2018-09-19',
			'2018-09-18',
			'2018-09-17',
			'2018-09-14',
			'2018-09-13',
			'2018-09-12',
			'2018-09-11',
			'2018-09-10',
			'2018-09-07',
			'2018-09-06',
			'2018-09-05',
			'2018-09-04',
			'2018-08-31',
			'2018-08-30',
			'2018-08-29',
			'2018-08-28',
			'2018-08-27',
			'2018-08-24',
			'2018-08-23',
			'2018-08-22',
			'2018-08-21',
			'2018-08-20',
			'2018-08-17',
			'2018-08-16',
			'2018-08-15',
			'2018-08-14',
			'2018-08-13',
			'2018-08-10',
			'2018-08-09',
			'2018-08-08',
			'2018-08-07',
			'2018-08-06',
			'2018-08-03',
			'2018-08-02',
			'2018-08-01',
			'2018-07-31',
			'2018-07-30',
			'2018-07-27',
			'2018-07-24',
			'2018-07-23',
			'2018-07-20',
			'2018-07-19',
			'2018-07-18',
			'2018-07-17',
			'2018-07-16',
			'2018-07-13',
			'2018-07-12',
			'2018-07-11',
			'2018-07-10',
			'2018-07-09',
			'2018-07-06',
			'2018-07-05',
			'2018-07-02',
			'2018-06-29',
			'2018-06-28',
			'2018-06-27',
			'2018-06-26',
			'2018-06-25',
			'2018-06-22',
			'2018-06-21',
			'2018-06-20',
			'2018-06-19',
			'2018-06-18',
			'2018-06-15',
			'2018-06-14',
			'2018-06-13',
			'2018-06-12',
			'2018-06-11',
			'2018-06-08',
			'2018-06-07',
			'2018-06-06',
			'2018-06-05',
			'2018-06-04',
			'2018-06-01',
			'2018-05-31',
			'2018-05-30',
			'2018-05-25',
			'2018-05-24',
			'2018-05-23',
			'2018-05-22',
			'2018-05-21',
			'2018-05-18',
			'2018-05-17',
			'2018-05-16',
			'2018-05-15',
			'2018-05-14',
			'2018-05-11',
			'2018-05-10',
			'2018-05-09',
			'2018-05-08',
			'2018-05-07',
			'2018-05-04',
			'2018-05-03',
			'2018-05-02',
			'2018-05-01',
			'2018-04-30',
			'2018-04-27',
			'2018-04-26',
			'2018-04-25',
			'2018-04-24',
			'2018-04-23',
			'2018-04-20',
			'2018-04-19',
			'2018-04-18',
			'2018-04-17',
			'2018-04-16',
			'2018-04-13',
			'2018-04-12',
			'2018-04-11',
			'2018-04-10',
			'2018-04-06',
			'2018-04-05',
			'2018-04-04',
			'2018-04-03',
			'2018-04-02',
			'2018-03-29',
			'2018-03-28',
			'2018-03-27',
			'2018-03-26',
			'2018-03-23',
			'2018-03-22',
			'2018-03-21',
			'2018-03-20',
			'2018-03-19',
			'2018-03-16',
			'2018-03-15',
			'2018-03-14',
			'2018-03-13',
			'2018-03-12',
			'2018-03-09',
			'2018-03-08',
			'2018-03-07',
			'2018-03-06',
			'2018-03-05',
			'2018-03-02',
			'2018-03-01',
			'2018-02-26',
			'2018-02-23',
			'2018-02-22',
			'2018-02-21',
			'2018-02-20',
			'2018-02-16',
			'2018-02-15',
			'2018-02-14',
			'2018-02-13',
			'2018-02-12',
			'2018-02-09',
			'2018-02-08',
			'2018-02-07',
			'2018-02-06',
			'2018-02-05',
			'2018-02-02',
			'2018-02-01',
			'2018-01-31',
			'2018-01-30',
			'2018-01-29',
			'2018-01-26',
			'2018-01-25',
			'2018-01-24',
			'2018-01-23',
			'2018-01-22',
			'2018-01-19',
			'2018-01-18',
			'2018-01-16',
			'2018-01-12',
			'2018-01-11',
			'2018-01-10',
			'2018-01-09',
			'2018-01-08',
			'2018-01-05',
			'2018-01-04',
			'2018-01-03',
			'2017-12-22',
			'2017-12-21',
			'2017-12-20',
			'2017-12-19',
			'2017-12-18',
			'2017-12-14',
			'2017-12-13',
			'2017-12-12',
			'2017-12-11',
			'2017-12-08',
			'2017-12-07',
			'2017-12-06',
			'2017-12-05',
			'2017-12-04',
			'2017-12-01',
			'2017-11-30',
			'2017-11-29',
			'2017-11-28',
			'2017-11-27',
			'2017-11-22',
			'2017-11-17',
			'2017-11-16',
			'2017-11-15',
		);
	}

	private function hack_data_mailchimp_dates_2023_09_19() {

		// imported LIVE values from 2023-09-19
		// this is not all the WXR dates...it's only the actual posts that have been inserted.
		
		return array(
            '2023-08-28',
            '2023-08-25',
            '2023-08-24',
            '2023-08-23',
            '2023-08-22',
            '2023-08-18',
            '2023-08-17',
            '2023-08-16',
            '2023-08-14',
            '2023-08-11',
            '2023-08-10',
            '2023-08-09',
            '2023-08-08',
            '2023-08-07',
            '2023-08-04',
            '2023-08-03',
            '2023-08-02',
            '2023-08-01',
            '2023-07-31',
            '2023-07-28',
            '2023-07-27',
            '2023-07-26',
            '2023-07-25',
            '2023-07-24',
            '2023-07-21',
            '2023-07-20',
            '2023-07-19',
            '2023-07-18',
            '2023-07-17',
            '2023-07-14',
            '2023-07-13',
            '2023-07-12',
            '2023-07-11',
            '2023-07-10',
            '2023-07-07',
            '2023-07-06',
            '2023-07-05',
            '2023-07-03',
            '2023-06-30',
            '2023-06-29',
            '2023-06-28',
            '2023-06-27',
            '2023-06-26',
            '2023-06-23',
            '2023-06-22',
            '2023-06-21',
            '2023-06-19',
            '2023-06-16',
            '2023-06-15',
            '2023-06-14',
            '2023-06-13',
            '2023-06-12',
            '2023-06-09',
            '2023-06-08',
            '2023-06-07',
            '2023-06-06',
            '2023-06-05',
            '2023-06-02',
            '2023-06-01',
            '2023-05-31',
            '2023-05-29',
            '2023-05-26',
            '2023-05-25',
            '2023-05-24',
            '2023-05-23',
            '2023-05-22',
            '2023-05-19',
            '2023-05-18',
            '2023-05-17',
            '2023-05-16',
            '2023-05-15',
            '2023-05-12',
            '2023-05-11',
            '2023-05-10',
            '2023-05-09',
            '2023-05-08',
            '2023-05-05',
            '2023-05-04',
            '2023-05-03',
            '2023-05-01',
            '2023-04-28',
            '2023-04-27',
            '2023-04-26',
            '2023-04-25',
            '2023-04-24',
            '2023-04-21',
            '2023-04-20',
            '2023-04-19',
            '2023-04-18',
            '2023-04-17',
            '2023-04-14',
            '2023-04-13',
            '2023-04-12',
            '2023-04-11',
            '2023-04-07',
            '2023-04-06',
            '2023-04-05',
            '2023-04-04',
            '2023-04-03',
            '2023-03-31',
            '2023-03-30',
            '2023-03-29',
            '2023-03-28',
            '2023-03-27',
            '2023-03-24',
            '2023-03-23',
            '2023-03-22',
            '2023-03-21',
            '2023-03-20',
            '2023-03-17',
            '2023-03-16',
            '2023-03-15',
            '2023-03-14',
            '2023-03-13',
            '2023-03-10',
            '2023-03-09',
            '2023-03-08',
            '2023-03-07',
            '2023-03-06',
            '2023-03-03',
            '2023-03-02',
            '2023-03-01',
            '2023-02-28',
            '2023-02-27',
            '2023-02-24',
            '2023-02-23',
            '2023-02-22',
            '2023-02-20',
            '2023-02-17',
            '2023-02-16',
            '2023-02-15',
            '2023-02-14',
            '2023-02-13',
            '2023-02-10',
            '2023-02-09',
            '2023-02-08',
            '2023-02-07',
            '2023-02-06',
            '2023-02-03',
            '2023-02-02',
            '2023-02-01',
            '2023-01-31',
            '2023-01-30',
            '2023-01-27',
            '2023-01-26',
            '2023-01-25',
            '2023-01-24',
            '2023-01-23',
            '2023-01-20',
            '2023-01-19',
            '2023-01-18',
            '2023-01-16',
            '2023-01-13',
            '2023-01-12',
            '2023-01-11',
            '2023-01-10',
            '2023-01-09',
            '2023-01-06',
            '2023-01-05',
            '2023-01-04',
            '2022-12-26',
            '2022-12-23',
            '2022-12-22',
            '2022-12-21',
            '2022-12-20',
            '2022-12-19',
            '2022-12-16',
            '2022-12-15',
            '2022-12-14',
            '2022-12-13',
            '2022-12-12',
            '2022-12-09',
            '2022-12-08',
            '2022-12-07',
            '2022-12-06',
            '2022-12-05',
            '2022-12-02',
            '2022-12-01',
            '2022-11-30',
            '2022-11-29',
            '2022-11-24',
            '2022-11-23',
            '2022-11-22',
            '2022-11-21',
            '2022-11-18',
            '2022-11-17',
            '2022-11-16',
            '2022-11-15',
            '2022-11-14',
            '2022-11-11',
            '2022-11-10',
            '2022-11-09',
            '2022-11-08',
            '2022-11-07',
            '2022-11-04',
            '2022-11-03',
            '2022-11-02',
            '2022-11-01',
            '2022-10-31',
            '2022-10-28',
            '2022-10-27',
            '2022-10-26',
            '2022-10-25',
            '2022-10-24',
            '2022-10-21',
            '2022-10-20',
            '2022-10-19',
            '2022-10-18',
            '2022-10-17',
            '2022-10-14',
            '2022-10-13',
            '2022-10-12',
            '2022-10-11',
            '2022-10-10',
            '2022-10-07',
            '2022-10-06',
            '2022-10-05',
            '2022-10-04',
            '2022-10-03',
            '2022-09-30',
            '2022-09-29',
            '2022-09-28',
            '2022-09-27',
            '2022-09-26',
            '2022-09-23',
            '2022-09-22',
            '2022-09-21',
            '2022-09-20',
            '2022-09-19',
            '2022-09-16',
            '2022-09-15',
            '2022-09-14',
            '2022-09-13',
            '2022-09-12',
            '2022-09-09',
            '2022-09-08',
            '2022-09-07',
            '2022-09-05',
            '2022-09-02',
            '2022-09-01',
            '2022-08-31',
            '2022-08-30',
            '2022-08-29',
            '2022-08-26',
            '2022-08-25',
            '2022-08-24',
            '2022-08-23',
            '2022-08-22',
            '2022-08-19',
            '2022-08-18',
            '2022-08-17',
            '2022-08-16',
            '2022-08-15',
            '2022-08-12',
            '2022-08-11',
            '2022-08-10',
            '2022-08-09',
            '2022-08-08',
            '2022-08-05',
            '2022-08-04',
            '2022-08-03',
            '2022-08-02',
            '2022-08-01',
            '2022-07-29',
            '2022-07-28',
            '2022-07-27',
            '2022-07-26',
            '2022-07-25',
            '2022-07-22',
            '2022-07-21',
            '2022-07-20',
            '2022-07-19',
            '2022-07-18',
            '2022-07-15',
            '2022-07-14',
            '2022-07-13',
            '2022-07-12',
            '2022-07-11',
            '2022-07-08',
            '2022-07-07',
            '2022-07-06',
            '2022-07-04',
            '2022-07-01',
            '2022-06-30',
            '2022-06-29',
            '2022-06-28',
            '2022-06-27',
            '2022-06-24',
            '2022-06-23',
            '2022-06-22',
            '2022-06-20',
            '2022-06-17',
            '2022-06-16',
            '2022-06-15',
            '2022-06-14',
            '2022-06-13',
            '2022-06-10',
            '2022-06-09',
            '2022-06-08',
            '2022-06-07',
            '2022-06-06',
            '2022-06-03',
            '2022-06-02',
            '2022-06-01',
            '2022-05-30',
            '2022-05-27',
            '2022-05-26',
            '2022-05-25',
            '2022-05-24',
            '2022-05-23',
            '2022-05-20',
            '2022-05-19',
            '2022-05-18',
            '2022-05-17',
            '2022-05-16',
            '2022-05-13',
            '2022-05-12',
            '2022-05-11',
            '2022-05-10',
            '2022-05-09',
            '2022-05-06',
            '2022-05-05',
            '2022-05-04',
            '2022-05-03',
            '2022-05-02',
            '2022-04-29',
            '2022-04-28',
            '2022-04-27',
            '2022-04-26',
            '2022-04-25',
            '2022-04-22',
            '2022-04-21',
            '2022-04-20',
            '2022-04-19',
            '2022-04-15',
            '2022-04-14',
            '2022-04-13',
            '2022-04-12',
            '2022-04-11',
            '2022-04-08',
            '2022-04-07',
            '2022-04-06',
            '2022-04-05',
            '2022-04-04',
            '2022-04-01',
            '2022-03-31',
            '2022-03-30',
            '2022-03-29',
            '2022-03-28',
            '2022-03-25',
            '2022-03-24',
            '2022-03-23',
            '2022-03-22',
            '2022-03-21',
            '2022-03-18',
            '2022-03-17',
            '2022-03-16',
            '2022-03-15',
            '2022-03-14',
            '2022-03-11',
            '2022-03-10',
            '2022-03-09',
            '2022-03-08',
            '2022-03-07',
            '2022-03-04',
            '2022-03-03',
            '2022-03-02',
            '2022-03-01',
            '2022-02-28',
            '2022-02-25',
            '2022-02-24',
            '2022-02-23',
            '2022-02-21',
            '2022-02-18',
            '2022-02-17',
            '2022-02-16',
            '2022-02-15',
            '2022-02-14',
            '2022-02-11',
            '2022-02-10',
            '2022-02-09',
            '2022-02-08',
            '2022-02-07',
            '2022-02-04',
            '2022-02-03',
            '2022-02-02',
            '2022-02-01',
            '2022-01-31',
            '2022-01-28',
            '2022-01-27',
            '2022-01-26',
            '2022-01-25',
            '2022-01-24',
            '2022-01-21',
            '2022-01-20',
            '2022-01-19',
            '2022-01-17',
            '2022-01-14',
            '2022-01-13',
            '2022-01-12',
            '2022-01-11',
            '2022-01-10',
            '2022-01-07',
            '2022-01-06',
            '2022-01-05',
            '2021-12-24',
            '2021-12-23',
            '2021-12-22',
            '2021-12-21',
            '2021-12-20',
            '2021-12-17',
            '2021-12-16',
            '2021-12-15',
            '2021-12-14',
            '2021-12-13',
            '2021-12-10',
            '2021-12-09',
            '2021-12-08',
            '2021-12-07',
            '2021-12-06',
            '2021-12-03',
            '2021-12-02',
            '2021-12-01',
            '2021-11-30',
            '2021-11-25',
            '2021-11-24',
            '2021-11-23',
            '2021-11-22',
            '2021-11-19',
            '2021-11-18',
            '2021-11-17',
            '2021-11-16',
            '2021-11-15',
            '2021-11-12',
            '2021-11-11',
            '2021-11-10',
            '2021-11-09',
            '2021-11-08',
            '2021-11-05',
            '2021-11-04',
            '2021-11-03',
            '2021-11-02',
            '2021-11-01',
            '2021-10-29',
            '2021-10-28',
            '2021-10-27',
            '2021-10-26',
            '2021-10-25',
            '2021-10-22',
            '2021-10-21',
            '2021-10-20',
            '2021-10-19',
            '2021-10-18',
            '2021-10-15',
            '2021-10-14',
            '2021-10-13',
            '2021-10-12',
            '2021-10-11',
            '2021-10-08',
            '2021-10-07',
            '2021-10-06',
            '2021-10-05',
            '2021-10-04',
            '2021-10-01',
            '2021-09-30',
            '2021-09-29',
            '2021-09-28',
            '2021-09-27',
            '2021-09-24',
            '2021-09-23',
            '2021-09-22',
            '2021-09-21',
            '2021-09-20',
            '2021-09-17',
            '2021-09-16',
            '2021-09-15',
            '2021-09-14',
            '2021-09-13',
            '2021-09-10',
            '2021-09-09',
            '2021-09-08',
            '2021-09-07',
            '2021-09-03',
            '2021-09-02',
            '2021-09-01',
            '2021-08-31',
            '2021-08-30',
            '2021-08-27',
            '2021-08-26',
            '2021-08-25',
            '2021-08-24',
            '2021-08-23',
            '2021-08-20',
            '2021-08-19',
            '2021-08-18',
            '2021-08-17',
            '2021-08-16',
            '2021-08-13',
            '2021-08-12',
            '2021-08-11',
            '2021-08-10',
            '2021-08-09',
            '2021-08-06',
            '2021-08-05',
            '2021-08-04',
            '2021-08-03',
            '2021-08-02',
            '2021-07-30',
            '2021-07-29',
            '2021-07-28',
            '2021-07-27',
            '2021-07-26',
            '2021-07-23',
            '2021-07-22',
            '2021-07-21',
            '2021-07-20',
            '2021-07-19',
            '2021-07-16',
            '2021-07-15',
            '2021-07-14',
            '2021-07-13',
            '2021-07-12',
            '2021-07-09',
            '2021-07-08',
            '2021-07-07',
            '2021-07-05',
            '2021-07-02',
            '2021-07-01',
            '2021-06-30',
            '2021-06-29',
            '2021-06-28',
            '2021-06-25',
            '2021-06-24',
            '2021-06-23',
            '2021-06-22',
            '2021-06-21',
            '2021-06-18',
            '2021-06-17',
            '2021-06-16',
            '2021-06-15',
            '2021-06-14',
            '2021-06-11',
            '2021-06-10',
            '2021-06-09',
            '2021-06-08',
            '2021-06-07',
            '2021-06-04',
            '2021-06-03',
            '2021-06-02',
            '2021-05-31',
            '2021-05-28',
            '2021-05-27',
            '2021-05-26',
            '2021-05-25',
            '2021-05-24',
            '2021-05-21',
            '2021-05-20',
            '2021-05-19',
            '2021-05-18',
            '2021-05-17',
            '2021-05-14',
            '2021-05-13',
            '2021-05-12',
            '2021-05-11',
            '2021-05-10',
            '2021-05-07',
            '2021-05-06',
            '2021-05-05',
            '2021-05-04',
            '2021-05-03',
            '2021-04-30',
            '2021-04-29',
            '2021-04-28',
            '2021-04-27',
            '2021-04-26',
            '2021-04-23',
            '2021-04-22',
            '2021-04-21',
            '2021-04-20',
            '2021-04-19',
            '2021-04-16',
            '2021-04-15',
            '2021-04-14',
            '2021-04-13',
            '2021-04-12',
            '2021-04-09',
            '2021-04-08',
            '2021-04-07',
            '2021-04-06',
            '2021-04-02',
            '2021-04-01',
            '2021-03-31',
            '2021-03-30',
            '2021-03-29',
            '2021-03-26',
            '2021-03-25',
            '2021-03-24',
            '2021-03-23',
            '2021-03-22',
            '2021-03-19',
            '2021-03-18',
            '2021-03-17',
            '2021-03-16',
            '2021-03-15',
            '2021-03-12',
            '2021-03-11',
            '2021-03-10',
            '2021-03-09',
            '2021-03-08',
            '2021-03-05',
            '2021-03-04',
            '2021-03-03',
            '2021-03-02',
            '2021-03-01',
            '2021-02-26',
            '2021-02-25',
            '2021-02-24',
            '2021-02-23',
            '2021-02-22',
            '2021-02-19',
            '2021-02-18',
            '2021-02-17',
            '2021-02-15',
            '2021-02-12',
            '2021-02-11',
            '2021-02-10',
            '2021-02-09',
            '2021-02-08',
            '2021-02-05',
            '2021-02-04',
            '2021-02-03',
            '2021-02-02',
            '2021-02-01',
            '2021-01-29',
            '2021-01-28',
            '2021-01-27',
            '2021-01-26',
            '2021-01-25',
            '2021-01-22',
            '2021-01-21',
            '2021-01-20',
            '2021-01-18',
            '2021-01-15',
            '2021-01-14',
            '2021-01-13',
            '2021-01-12',
            '2021-01-11',
            '2021-01-08',
            '2021-01-07',
            '2021-01-06',
            '2021-01-05',
            '2020-12-24',
            '2020-12-23',
            '2020-12-22',
            '2020-12-21',
            '2020-12-18',
            '2020-12-17',
            '2020-12-16',
            '2020-12-15',
            '2020-12-14',
            '2020-12-11',
            '2020-12-10',
            '2020-12-09',
            '2020-12-08',
            '2020-12-07',
            '2020-12-04',
            '2020-12-03',
            '2020-12-02',
            '2020-12-01',
            '2020-11-26',
            '2020-11-25',
            '2020-11-24',
            '2020-11-23',
            '2020-11-20',
            '2020-11-19',
            '2020-11-18',
            '2020-11-17',
            '2020-11-16',
            '2020-11-13',
            '2020-11-12',
            '2020-11-11',
            '2020-11-10',
            '2020-11-09',
            '2020-11-06',
            '2020-11-05',
            '2020-11-04',
            '2020-11-03',
            '2020-11-02',
            '2020-10-30',
            '2020-10-29',
            '2020-10-28',
            '2020-10-27',
            '2020-10-26',
            '2020-10-23',
            '2020-10-22',
            '2020-10-21',
            '2020-10-20',
            '2020-10-19',
            '2020-10-16',
            '2020-10-15',
            '2020-10-14',
            '2020-10-13',
            '2020-10-12',
            '2020-10-09',
            '2020-10-08',
            '2020-10-07',
            '2020-10-06',
            '2020-10-05',
            '2020-10-02',
            '2020-10-01',
            '2020-09-30',
            '2020-09-29',
            '2020-09-28',
            '2020-09-25',
            '2020-09-24',
            '2020-09-23',
            '2020-09-22',
            '2020-09-21',
            '2020-09-18',
            '2020-09-17',
            '2020-09-16',
            '2020-09-15',
            '2020-09-14',
            '2020-09-11',
            '2020-09-10',
            '2020-09-09',
            '2020-09-07',
            '2020-09-04',
            '2020-09-03',
            '2020-09-02',
            '2020-09-01',
            '2020-08-31',
            '2020-08-28',
            '2020-08-27',
            '2020-08-26',
            '2020-08-25',
            '2020-08-24',
            '2020-08-21',
            '2020-08-20',
            '2020-08-19',
            '2020-08-18',
            '2020-08-17',
            '2020-08-14',
            '2020-08-13',
            '2020-08-12',
            '2020-08-11',
            '2020-08-10',
            '2020-08-07',
            '2020-08-06',
            '2020-08-05',
            '2020-08-04',
            '2020-08-03',
            '2020-07-31',
            '2020-07-30',
            '2020-07-29',
            '2020-07-28',
            '2020-07-27',
            '2020-07-24',
            '2020-07-23',
            '2020-07-22',
            '2020-07-21',
            '2020-07-20',
            '2020-07-17',
            '2020-07-16',
            '2020-07-15',
            '2020-07-14',
            '2020-07-13',
            '2020-07-10',
            '2020-07-09',
            '2020-07-08',
            '2020-07-07',
            '2020-07-03',
            '2020-07-02',
            '2020-07-01',
            '2020-06-30',
            '2020-06-29',
            '2020-06-26',
            '2020-06-25',
            '2020-06-24',
            '2020-06-23',
            '2020-06-22',
            '2020-06-19',
            '2020-06-18',
            '2020-06-17',
            '2020-06-16',
            '2020-06-15',
            '2020-06-12',
            '2020-06-11',
            '2020-06-10',
            '2020-06-09',
            '2020-06-08',
            '2020-06-05',
            '2020-06-04',
            '2020-06-03',
            '2020-06-02',
            '2020-05-29',
            '2020-05-28',
            '2020-05-27',
            '2020-05-26',
            '2020-05-20',
            '2020-05-19',
            '2020-05-18',
            '2020-05-15',
            '2020-05-14',
            '2020-05-13',
            '2020-05-12',
            '2020-05-11',
            '2020-05-08',
            '2020-05-07',
            '2020-05-06',
            '2020-05-05',
            '2020-05-04',
            '2020-05-01',
            '2020-04-30',
            '2020-04-29',
            '2020-04-28',
            '2020-04-27',
            '2020-04-24',
            '2020-04-23',
            '2020-04-22',
            '2020-04-21',
            '2020-04-20',
            '2020-04-17',
            '2020-04-16',
            '2020-04-15',
            '2020-04-14',
            '2020-04-13',
            '2020-04-10',
            '2020-04-09',
            '2020-04-08',
            '2020-04-07',
            '2020-04-06',
            '2020-04-03',
            '2020-04-02',
            '2020-04-01',
            '2020-03-31',
            '2020-03-30',
            '2020-03-27',
            '2020-03-26',
            '2020-03-25',
            '2020-03-24',
            '2020-03-23',
            '2020-03-20',
            '2020-03-19',
            '2020-03-18',
            '2020-03-17',
            '2020-03-16',
            '2020-03-13',
            '2020-03-12',
            '2020-03-11',
            '2020-03-10',
            '2020-03-09',
            '2020-03-06',
            '2020-03-05',
            '2020-03-04',
            '2020-03-03',
            '2020-03-02',
            '2020-02-28',
            '2020-02-27',
            '2020-02-26',
            '2020-02-25',
            '2020-02-24',
            '2020-02-21',
            '2020-02-20',
            '2020-02-19',
            '2020-02-18',
            '2020-02-14',
            '2020-02-13',
            '2020-02-12',
            '2020-02-11',
            '2020-02-10',
            '2020-02-07',
            '2020-02-06',
            '2020-02-05',
            '2020-02-04',
            '2020-02-03',
            '2020-01-31',
            '2020-01-30',
            '2020-01-29',
            '2020-01-28',
            '2020-01-27',
            '2020-01-24',
            '2020-01-23',
            '2020-01-22',
            '2020-01-21',
            '2020-01-17',
            '2020-01-16',
            '2020-01-15',
            '2020-01-14',
            '2020-01-13',
            '2020-01-10',
            '2020-01-09',
            '2020-01-08',
            '2020-01-07',
            '2020-01-06',
            '2020-01-03',
            '2019-12-31',
            '2019-12-24',
            '2019-12-23',
            '2019-12-20',
            '2019-12-19',
            '2019-12-18',
            '2019-12-17',
            '2019-12-16',
            '2019-12-13',
            '2019-12-12',
            '2019-12-11',
            '2019-12-10',
            '2019-12-09',
            '2019-12-06',
            '2019-12-05',
            '2019-12-04',
            '2019-12-03',
            '2019-11-28',
            '2019-11-27',
            '2019-11-26',
            '2019-11-25',
            '2019-11-22',
            '2019-11-21',
            '2019-11-20',
            '2019-11-19',
            '2019-11-18',
            '2019-11-15',
            '2019-11-14',
            '2019-11-13',
            '2019-11-12',
            '2019-11-11',
            '2019-11-08',
            '2019-11-07',
            '2019-11-06',
            '2019-11-05',
            '2019-11-04',
            '2019-11-01',
            '2019-10-31',
            '2019-10-30',
            '2019-10-29',
            '2019-10-28',
            '2019-10-25',
            '2019-10-24',
            '2019-10-23',
            '2019-10-22',
            '2019-10-21',
            '2019-10-18',
            '2019-10-17',
            '2019-10-16',
            '2019-10-15',
            '2019-10-14',
            '2019-10-11',
            '2019-10-10',
            '2019-10-09',
            '2019-10-08',
            '2019-10-07',
            '2019-10-04',
            '2019-10-03',
            '2019-10-02',
            '2019-10-01',
            '2019-09-30',
            '2019-09-27',
            '2019-09-26',
            '2019-09-25',
            '2019-09-24',
            '2019-09-23',
            '2019-09-20',
            '2019-09-19',
            '2019-09-18',
            '2019-09-17',
            '2019-09-16',
            '2019-09-13',
            '2019-09-12',
            '2019-09-11',
            '2019-09-10',
            '2019-09-09',
            '2019-09-06',
            '2019-09-05',
            '2019-09-04',
            '2019-09-03',
            '2019-08-30',
            '2019-08-29',
            '2019-08-28',
            '2019-08-27',
            '2019-08-26',
            '2019-08-23',
            '2019-08-22',
            '2019-08-21',
            '2019-08-20',
            '2019-08-19',
            '2019-08-16',
            '2019-08-15',
            '2019-08-14',
            '2019-08-13',
            '2019-08-12',
            '2019-08-09',
            '2019-08-08',
            '2019-08-07',
            '2019-08-06',
            '2019-08-05',
            '2019-08-02',
            '2019-08-01',
            '2019-07-31',
            '2019-07-30',
            '2019-07-29',
            '2019-07-26',
            '2019-07-25',
            '2019-07-24',
            '2019-07-23',
            '2019-07-22',
            '2019-07-19',
            '2019-07-18',
            '2019-07-17',
            '2019-07-12',
            '2019-07-11',
            '2019-07-10',
            '2019-07-09',
            '2019-07-08',
            '2019-07-03',
            '2019-07-02',
            '2019-07-01',
            '2019-06-28',
            '2019-06-27',
            '2019-06-26',
            '2019-06-25',
            '2019-06-24',
            '2019-06-21',
            '2019-06-20',
            '2019-06-19',
            '2019-06-18',
            '2019-06-17',
            '2019-06-14',
            '2019-06-13',
            '2019-06-12',
            '2019-06-11',
            '2019-06-10',
            '2019-06-07',
            '2019-06-06',
            '2019-06-05',
            '2019-06-04',
            '2019-06-03',
            '2019-05-31',
            '2019-05-30',
            '2019-05-29',
            '2019-05-28',
            '2019-05-24',
            '2019-05-23',
            '2019-05-22',
            '2019-05-21',
            '2019-05-20',
            '2019-05-17',
            '2019-05-16',
            '2019-05-15',
            '2019-05-14',
            '2019-05-13',
            '2019-05-10',
            '2019-05-09',
            '2019-05-08',
            '2019-05-07',
            '2019-05-06',
            '2019-05-03',
            '2019-05-02',
            '2019-05-01',
            '2019-04-30',
            '2019-04-29',
            '2019-04-26',
            '2019-04-25',
            '2019-04-24',
            '2019-04-23',
            '2019-04-22',
            '2019-04-18',
            '2019-04-17',
            '2019-04-16',
            '2019-04-15',
            '2019-04-12',
            '2019-04-11',
            '2019-04-10',
            '2019-04-09',
            '2019-04-08',
            '2019-04-05',
            '2019-04-04',
            '2019-04-03',
            '2019-04-02',
            '2019-04-01',
            '2019-03-29',
            '2019-03-28',
            '2019-03-27',
            '2019-03-26',
            '2019-03-25',
            '2019-03-22',
            '2019-03-21',
            '2019-03-20',
            '2019-03-19',
            '2019-03-18',
            '2019-03-15',
            '2019-03-14',
            '2019-03-13',
            '2019-03-12',
            '2019-03-11',
            '2019-03-08',
            '2019-03-07',
            '2019-03-06',
            '2019-03-05',
            '2019-03-04',
            '2019-03-01',
            '2019-02-28',
            '2019-02-27',
            '2019-02-26',
            '2019-02-25',
            '2019-02-22',
            '2019-02-21',
            '2019-02-20',
            '2019-02-19',
            '2019-02-15',
            '2019-02-14',
            '2019-02-13',
            '2019-02-12',
            '2019-02-11',
            '2019-02-08',
            '2019-02-07',
            '2019-02-06',
            '2019-02-05',
            '2019-02-04',
            '2019-02-01',
            '2019-01-31',
            '2019-01-30',
            '2019-01-29',
            '2019-01-28',
            '2019-01-25',
            '2019-01-24',
            '2019-01-23',
            '2019-01-22',
            '2019-01-18',
            '2019-01-17',
            '2019-01-16',
            '2019-01-15',
            '2019-01-14',
            '2019-01-11',
            '2019-01-10',
            '2019-01-09',
            '2019-01-08',
            '2019-01-07',
            '2019-01-04',
            '2019-01-03',
            '2018-12-24',
            '2018-12-21',
            '2018-12-20',
            '2018-12-19',
            '2018-12-18',
            '2018-12-17',
            '2018-12-14',
            '2018-12-13',
            '2018-12-12',
            '2018-12-11',
            '2018-12-10',
            '2018-12-07',
            '2018-12-06',
            '2018-12-05',
            '2018-12-04',
            '2018-12-03',
            '2018-11-30',
            '2018-11-29',
            '2018-11-28',
            '2018-11-27',
            '2018-11-26',
            '2018-11-21',
            '2018-11-20',
            '2018-11-19',
            '2018-11-16',
            '2018-11-15',
            '2018-11-14',
            '2018-11-13',
            '2018-11-12',
            '2018-11-09',
            '2018-11-08',
            '2018-11-07',
            '2018-11-06',
            '2018-11-05',
            '2018-11-02',
            '2018-11-01',
            '2018-10-31',
            '2018-10-30',
            '2018-10-29',
            '2018-10-26',
            '2018-10-25',
            '2018-10-24',
            '2018-10-23',
            '2018-10-22',
            '2018-10-19',
            '2018-10-18',
            '2018-10-17',
            '2018-10-16',
            '2018-10-15',
            '2018-10-12',
            '2018-10-11',
            '2018-10-10',
            '2018-10-09',
            '2018-10-08',
            '2018-10-02',
            '2018-10-01',
            '2018-09-28',
            '2018-09-27',
            '2018-09-26',
            '2018-09-25',
            '2018-09-24',
            '2018-09-21',
            '2018-09-20',
            '2018-09-19',
            '2018-09-18',
            '2018-09-17',
            '2018-09-14',
            '2018-09-13',
            '2018-09-12',
            '2018-09-11',
            '2018-09-10',
            '2018-09-07',
            '2018-09-06',
            '2018-09-05',
            '2018-09-04',
            '2018-08-31',
            '2018-08-30',
            '2018-08-29',
            '2018-08-28',
            '2018-08-27',
            '2018-08-24',
            '2018-08-23',
            '2018-08-22',
            '2018-08-21',
            '2018-08-20',
            '2018-08-17',
            '2018-08-16',
            '2018-08-15',
            '2018-08-14',
            '2018-08-13',
            '2018-08-10',
            '2018-08-09',
            '2018-08-08',
            '2018-08-07',
            '2018-08-06',
            '2018-08-03',
            '2018-08-02',
            '2018-08-01',
            '2018-07-31',
            '2018-07-30',
            '2018-07-27',
            '2018-07-26',
            '2018-07-25',
            '2018-07-24',
            '2018-07-23',
            '2018-07-20',
            '2018-07-19',
            '2018-07-18',
            '2018-07-17',
            '2018-07-16',
            '2018-07-13',
            '2018-07-12',
            '2018-07-11',
            '2018-07-10',
            '2018-07-09',
            '2018-07-06',
            '2018-07-05',
            '2018-07-02',
            '2018-06-29',
            '2018-06-28',
            '2018-06-27',
            '2018-06-26',
            '2018-06-25',
            '2018-06-22',
            '2018-06-21',
            '2018-06-20',
            '2018-06-19',
            '2018-06-18',
            '2018-06-15',
            '2018-06-14',
            '2018-06-13',
            '2018-06-12',
            '2018-06-11',
            '2018-06-08',
            '2018-06-07',
            '2018-06-06',
            '2018-06-05',
            '2018-06-04',
            '2018-06-01',
            '2018-05-31',
            '2018-05-30',
            '2018-05-29',
            '2018-05-25',
            '2018-05-24',
            '2018-05-23',
            '2018-05-22',
            '2018-05-21',
            '2018-05-18',
            '2018-05-17',
            '2018-05-16',
            '2018-05-15',
            '2018-05-14',
            '2018-05-11',
            '2018-05-10',
            '2018-05-09',
            '2018-05-08',
            '2018-05-07',
            '2018-05-04',
            '2018-05-03',
            '2018-05-02',
            '2018-05-01',
            '2018-04-30',
            '2018-04-27',
            '2018-04-26',
            '2018-04-25',
            '2018-04-24',
            '2018-04-23',
            '2018-04-20',
            '2018-04-19',
            '2018-04-18',
            '2018-04-17',
            '2018-04-16',
            '2018-04-13',
            '2018-04-12',
            '2018-04-11',
            '2018-04-10',
            '2018-04-09',
            '2018-04-06',
            '2018-04-05',
            '2018-04-04',
            '2018-04-03',
            '2018-04-02',
            '2018-03-29',
            '2018-03-28',
            '2018-03-27',
            '2018-03-26',
            '2018-03-23',
            '2018-03-22',
            '2018-03-21',
            '2018-03-20',
            '2018-03-19',
            '2018-03-16',
            '2018-03-15',
            '2018-03-14',
            '2018-03-13',
            '2018-03-12',
            '2018-03-09',
            '2018-03-08',
            '2018-03-07',
            '2018-03-06',
            '2018-03-05',
            '2018-03-02',
            '2018-03-01',
            '2018-02-27',
            '2018-02-26',
            '2018-02-23',
            '2018-02-22',
            '2018-02-21',
            '2018-02-20',
            '2018-02-16',
            '2018-02-15',
            '2018-02-14',
            '2018-02-13',
            '2018-02-12',
            '2018-02-09',
            '2018-02-08',
            '2018-02-07',
            '2018-02-06',
            '2018-02-05',
            '2018-02-02',
            '2018-02-01',
            '2018-01-31',
            '2018-01-30',
            '2018-01-29',
            '2018-01-26',
            '2018-01-25',
            '2018-01-24',
            '2018-01-23',
            '2018-01-22',
            '2018-01-19',
            '2018-01-18',
            '2018-01-17',
            '2018-01-16',
            '2018-01-12',
            '2018-01-11',
            '2018-01-10',
            '2018-01-09',
            '2018-01-08',
            '2018-01-05',
            '2018-01-04',
            '2018-01-03',
            '2017-12-22',
            '2017-12-21',
            '2017-12-20',
            '2017-12-19',
            '2017-12-18',
            '2017-12-15',
            '2017-12-14',
            '2017-12-13',
            '2017-12-12',
            '2017-12-11',
            '2017-12-08',
            '2017-12-07',
            '2017-12-06',
            '2017-12-05',
            '2017-12-04',
            '2017-12-01',
            '2017-11-30',
            '2017-11-29',
            '2017-11-28',
            '2017-11-27',
            '2017-11-22',
            '2017-11-21',
            '2017-11-20',
            '2017-11-17',
            '2017-11-16',
            '2017-11-15',
		);
	}			

}

