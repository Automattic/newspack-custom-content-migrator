<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \Newspack_WXR_Exporter;
use \PDO, \PDOException;
use \WP_CLI;

/**
 * Custom migration scripts for Latin Finance.
 */
class LatinFinanceMigrator implements InterfaceCommand {

	private $pdo = null;
	private $authors = array();
	private $tags = array();

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
		
		// Use PDO to create a connection to the DB.
		// requires: php.ini => extension=pdo_sqlsrv
		try {  
			$this->pdo = new PDO( "sqlsrv:Server=;Database=LatinFinanceUmbraco", NULL, NULL);   
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );  
		}  
		catch( PDOException $e ) {  
			WP_CLI::error( 'SQL Server error ' . $e->getCode() . ': ' . $e->getMessage() );
		}  

		// Set Authors
		// todo: how to load existing email addreses for authors?  WXR is hard-coded to @example
		// just do this with local WP db connection? Needs IP address whitelisted!
		$this->set_authors();
		$this->export_to_csv( $this->authors, \WP_CONTENT_DIR  . '/latinfinance-authors.csv');
		$this->check_author_emails();
		$this->check_author_slugs();

		// Set tags (convert to Categories under a parent category named 'Topics')
		// result will look like:
		//		Topics
		//			-> Bonds
		//		DailyBriefs
		//		etc
		// todo: how to pre-load these into WP?  
		// just use local WP db connection?
		// The WXR doesn't have <wp:category> nodes inside <channel>.  Need this for slug/heirarchy
		// todo: handle duplicate child categories names across different parents
		// todo: do we need to import Descriptions from some categories?
		$this->set_tags();
		$this->set_tags_parent_slugs();
		$this->export_to_csv( $this->tags, \WP_CONTENT_DIR  . '/latinfinance-tags.csv');
		$this->check_tags_slugs();

		exit();

		// Setup data array WXR for post content
		$data = [
			'site_title'  => "LatinFinance",
			'site_url'    => 'https://www.latinfinance.com',
			'export_file' => \WP_CONTENT_DIR  . '/latinfinance-wxr-export.xml',
			'posts'       => [],
		];

		// Get published content types to migrate
		// todo: to catch changes compare: postmeta newspack_lf_checksum
		$sql = "
			select top 100
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

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			// print_r( $row );   

			$xml = simplexml_load_string( $row['xml'] );
			// print_r($xml);
			
			$post_authors = $this->get_authors_from_list( (string) $xml->authors );

			$post = [
				'author'  => $post_authors,
				'categories' => $this->get_categories_from_list( (string) $xml->tags ),
				'content' => (string) $xml->body,
				// todo: attribute createDate?
				'date'    => (string) $xml->displayDate,
				// todo: column expireDate?
				'excerpt' => (string) $xml->snippet,
				'meta'    => [
					'newspack_lf_author' => $post_authors, 
					'newspack_lf_checksum' => md5( serialize( $row ) ),
					'newspack_lf_original_id' => $row['nodeId'],
					'newspack_lf_original_url' => $this->get_url_from_path( $xml['path'] ),
					'newspack_lf_original_version' => $row['versionId'],
				],
				'title'   => (string) $xml['nodeName'],
				// todo: test existing slugs across different content types for duplicates
				'url'    => (string) $xml['urlName'],
			];

			// Convert "content type" to a category
			switch( $xml['nodeTypeAlias'] ) {
				case 'dailyBriefArticle': $post['categories'][] = 'Daily Briefs'; break;
				case 'magazineArticle': $post['categories'][] = 'Magazine'; break;
				case 'webArticle': $post['categories'][] = 'Web Articles'; break;
			}
						
			// <metaKeywords><![CDATA[bond buyback, liability management, Arcos Dorados, McDonald's, Argentina]]></metaKeywords>
			// <isFree>0</isFree>
			// <image><![CDATA[64047]]></image>
			// body: <img

			// print_r($post); exit();

			$data['posts'][] = $post;

		} // while content

		// print_r($data);
		exit();

		// Create WXR file
		// Newspack_WXR_Exporter::generate_export( $data );
		// WP_CLI::success( sprintf( "\n" . 'Exported to file %s ...', $data[ 'export_file' ] ) );

	}

	private function check_author_emails() {

		$emails = array();

		foreach( $this->authors as $id => $node ) {
			
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

	private function check_author_slugs() {

		$slugs = array();

		foreach( $this->authors as $id => $node ) {
			
			// slug to test
			$slug = $node['slug'];

			// test if already exists (can't have duplicate slugs)
			if( isset( $slugs[$slug] ) ) {
				$slugs[$slug]++;
				WP_CLI::warning( 'Duplicate author "' . $slug .'" for node: ' . print_r( $node , true) );				
			}
			else {
				$slugs[$slug] = 1;
			}
		
		}
	}

	private function check_tags_slugs() {

		$slugs = array();

		foreach( $this->tags as $id => $node ) {
			
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

	private function export_to_csv( $data, $path ) {
		$file = fopen($path, 'w');
		
		$header = array_keys(reset($data));
		fputcsv($file, $header);

		foreach ($data as $row) {
			fputcsv($file, $row);
		}

		fclose($file);
	}

	// null
	// single id
	// id,id,id
	private function get_authors_from_list( $list ) {

		if( empty( $list ) ) return array();
		$ids = explode(',', $list );
		
		$out = array();
		foreach( $ids as $id ) {
			$out[] = $this->authors[$id];
		}

		return $out;

	}

	// null
	// single id
	// id,id,id
	private function get_categories_from_list( $list ) {

		if( empty( $list ) ) return array();
		$ids = explode(',', $list );
		
		$out = array();
		foreach( $ids as $id ) {
			var_dump($this->tags[$id]);
			// nodeName="Funds" urlName="funds" 
			exit();
			$out[] = $this->tags[$id];
		}

		return $out;

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
			CAST( cmsContentXml.xml as xml).value('(/*/@level)[1]', 'int') as level
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
			];

		}  

	}

	private function set_tags( ) {

		$result = $this->pdo->prepare("
			select cmsDocument.nodeId, cmsContentXML.xml
			from cmsDocument
			join cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			join cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			join cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias in('tag','tags')
			where cmsDocument.published = 1	
		");
		$result->execute();

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			$xml = simplexml_load_string( $row['xml'] );
			
			$this->tags[ (string) $row['nodeId'] ] = [
				'id' => (string) $row['nodeId'],
				'name' => (string) $xml['nodeName'],
				'slug' => (string) $xml['urlName'],
				'parent_id' => (string) $xml['parentID'],
				'url' => $this->get_url_from_path( (string) $xml['path'] ),
				'description' => (string) $xml->sidebarWidget,
			];

		}  

	}

	private function set_tags_parent_slugs() {

		foreach( $this->tags as $id => $node ) {
			
			$parent_id = $node['parent_id'];

			// special case for top level category where parent is "1051/Home"
			if( $parent_id == '1051' ) {
				$this->tags[$id]['parent_slug'] = '';
				continue;	
			}

			// if parent id doesn't match a node
			if ( !isset( $this->tags[$parent_id] ) ) {
				WP_CLI::error( 'Tag parent_id not found for node: ' . print_r( $this->tags[$id] , true) );				
			}

			// set node's parent slug from parent node's slug
			$this->tags[$id]['parent_slug'] = $this->tags[$parent_id]['slug'];
			
		}

	}


}
