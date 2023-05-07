<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \Newspack_WXR_Exporter;
use \PDO, \PDOException;
use PHPCompatibility\Sniffs\Keywords\ForbiddenNamesAsDeclaredSniff;
use stdClass;
use \WP_CLI;

/**
 * Custom migration scripts for Latin Finance.
 */
class LatinFinanceMigrator implements InterfaceCommand {

	private $pdo = null;
	private $authors = array();
	private $tags = array();
	private $custom_tag_slugs = array( 'daily-briefs', 'free-content', 'magazine', 'web-articles' );

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
			'newspack-content-migrator latinfinance-import-from-mssql',
			[ $this, 'cmd_import_from_mssql' ],
			[
				'shortdesc' => 'Imports content from MS SQL DB as posts.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-import-from-mssql'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_import_from_mssql( $pos_args, $assoc_args ) {
		
		WP_CLI::line( "Doing latinfinance-import-from-mssql..." );
		
		$this->set_pdo();
		$this->set_authors();
		$this->set_tags();
		$this->set_tags_parent_slugs(); // only for CSV output

		// Get published content types to migrate
		$sql = "
			select top 50
				cmsDocument.nodeId, cmsDocument.versionId, cmsDocument.expireDate,
				cmsContentXML.xml
			from cmsDocument
			join cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			join cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			join cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias in('dailyBriefArticle', 'magazineArticle', 'webArticle')
			where cmsDocument.published = 1	
		";
		$result = $this->pdo->query( $sql );   

		// Setup data array WXR for post content
		$data = [
			'site_title'  => "LatinFinance",
			'site_url'    => 'https://www.latinfinance.com',
			'export_file' => \WP_CONTENT_DIR  . '/latinfinance-wxr-export.xml',
			'posts'       => [],
		];
		
		$slugs = array();

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			$xml = simplexml_load_string( $row['xml'] );
			
			// Test dublicate content slugs
			$slug = (string) $xml['urlName'];
			if( isset( $slugs[$slug] ) ) {
				$slugs[$slug]++;
				WP_CLI::warning( 'Duplicate content "' . $slug .'" for row: ' . print_r( $row , true) );
			}
			else {
				$slugs[$slug] = 1;
			}
			
			// Test expireDate
			if( null !== $row['expireDate'] ) {
				WP_CLI::warning( 'Post expireDate exists "' . $row['expireDate'] .'" for node ' . $row['nodeId']);
			}

			$authors = $this->get_authors_and_increment( (string) $xml->authors );

			$post = [

				// head.<wp:author>.<wp:author_login> will create a user accounts
				// but post.<dc:creator> doesn't support multiple authors so no point in creating user accounts...
				// do this post migration using: postmeta.newspack_lf_author
				'author'  => '', // was: $authors['basic']

				// todo: how to pre-load these into WP?  
				// just use local WP db connection?
				// The WXR doesn't have <wp:category> nodes inside <channel>.
				// Need this for slug/hierarchy
				'categories' => $this->get_cats_and_increment( (string) $xml->tags ),
				
				'content' => (string) $xml->body,
				
				// todo: attribute createDate? this is old and doesn't matter!!!
				'date'    => (string) $xml->displayDate,
				
				'excerpt' => (string) $xml->snippet,
				
				'meta'    => [

					// todo: command to load these as Guest Authors in CoAuthorsPlus and connect to PostId
					'newspack_lf_author' => json_encode( $authors['full'] ),

					// todo: catch changes from previous imports
					'newspack_lf_original_id' => (string) $row['nodeId'],
					'newspack_lf_original_version' => (string) $row['versionId'],
					'newspack_lf_checksum' => md5( serialize( $row ) ),

					'newspack_lf_original_url' => $this->get_url_from_path( (string) $xml['path'] ),
				],
				
				// Convert <metaKeywords><![CDATA[Arcos Dorados, McDonald's, Argentina]]></metaKeywords> to Tags (trimmed)
				'tags' => preg_split( '/,\s*/', trim( (string) $xml->metaKeywords ), -1, PREG_SPLIT_NO_EMPTY ),

				'title'   => (string) $xml['nodeName'],
				'url'    => $slug,
			];

			// Convert "content type" to a category
			switch( $xml['nodeTypeAlias'] ) {
				case 'dailyBriefArticle': $post['categories'][] = 'Daily Briefs'; break;
				case 'magazineArticle': $post['categories'][] = 'Magazine'; break;
				case 'webArticle': $post['categories'][] = 'Web Articles'; break;
			}
				
			if ( (int) $xml->isFree === 1 ) {
				$post['categories'][] = 'Free Content';
			}

			// <image><![CDATA[64047]]></image>
			// body: <img

			// print_r($post); exit();

			$data['posts'][] = $post;

		} // while content

		// $this->export_to_dump( $data['posts'], \WP_CONTENT_DIR  . '/latinfinance-posts.txt'); exit();

		// todo: handle duplicate emails
		// $this->check_author_emails();
		// $this->export_to_csv( $this->authors, \WP_CONTENT_DIR  . '/latinfinance-authors.csv');

		// todo: handle duplicate child categories names across different parents
		// todo: do we need to import Descriptions from some categories? don't worry!! they can recreate by hand, 
		// todo: test for $this->custom_tag_slugs
		// $this->check_tags_slugs();
		$this->export_to_csv( $this->tags, \WP_CONTENT_DIR  . '/latinfinance-tags.csv');

		// Append neccessary Categories to WXR <channel>
		// todo: only need to do this once!
		$terms = $this->get_tags_as_terms();
		if( ! empty( $terms ) ) $data['terms'] = $terms;

		// Create WXR file

		Newspack_WXR_Exporter::generate_export( $data );
		WP_CLI::success( sprintf( "\n" . 'Exported to file %s ...', $data[ 'export_file' ] ) );

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
	 * Exports
	 *
	 */

	private function export_to_csv( $data, $path ) {
		$file = fopen($path, 'w');
		
		$header = array_keys(reset($data));
		fputcsv($file, $header);

		foreach ($data as $row) {
			fputcsv($file, $row);
		}

		fclose($file);
	}

	private function export_to_dump( $data, $path ) {
		ob_start();
		var_dump( $data );
		file_put_contents( $path, ob_get_clean() );
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

		if( empty( $node ) ) return array();
		$ids = explode(',', $node );
		
		$out = array();
		foreach( $ids as $id ) {
			// only add matching key/values to each output
			$out[] = array_intersect_key( $this->tags[$id], array( 'name' => 1, 'slug' => 1) );
			$this->tags[$id]['post_count']++;
		}

		return $out;

	}

	private function get_tags_as_terms() {
		
		$terms = array();
		
		foreach( $this->tags as $id => $tag ) {

			// only create terms if used for a post
			if( $tag['post_count'] === 0 ) continue;

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

	// Use PDO to create a connection to the DB.
	// php requires: php.ini => extension=pdo_sqlsrv
	// client requires: IP Address whitelisted
	// todo: fix collation/character sets
	// plan on my local
	private function set_pdo() {

		try {  
			$this->pdo = new PDO( "sqlsrv:Server=;Database=LatinFinanceUmbraco", NULL, NULL);   
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );  
		}  
		catch( PDOException $e ) {  
			WP_CLI::error( 'SQL Server error ' . $e->getCode() . ': ' . $e->getMessage() );
		}  

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
			
			$this->tags[ (string) $row['nodeId'] ] = [
				'id' => (string) $row['nodeId'],
				'name' => (string) $xml['nodeName'],
				'slug' => (string) $xml['urlName'],
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

}
