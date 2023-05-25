<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
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

	private $coauthorsplus_logic = null;

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
			'newspack-content-migrator latinfinance-export-from-mssql',
			[ $this, 'cmd_export_from_mssql' ],
			[
				'shortdesc' => 'Exports content from MS SQL DB to WXR files.',
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
		$this->log_to_csv( $old_redirects, $this->export_path  . '/latinfinance-redirects-1.csv', 'single-with-keys' );

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
				
		// Setup query vars for MSSQL DB for content types
		$limit = 500; // row limit per batch
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

		// export categories
		$this->export_categories();

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

		// keep track of last row processed
		$last_id = null;

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			// load xml column
			$xml = simplexml_load_string( $row['xml'] );
			
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
					'newspack_lf_checksum' => md5( serialize( $row ) ),

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

	/**
	 * Set Yoast primary categories from old content types
	 *
	 * @param int $posts_per_page
	 * @return int $count posts processed
	 */
	private function set_primary_categories( $posts_per_page ) {

		// get term ids for primary categories keyed by slug
		$categories = array_flip( get_categories( [
			'slug'   => [ 'daily-briefs', 'magazine', 'web-articles' ],
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
				case 'dailyBriefArticle': $category_id = $categories['daily-briefs']; break;
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
}

