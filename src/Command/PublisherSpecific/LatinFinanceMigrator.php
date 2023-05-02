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

	private $pdo;
	private $authors;
	private $tags;

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

		// Get tags (will convert to Categories under a parent category named 'Topics')
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
		// todo: preload content type categories: Daily Briefs, Magazine, Web Articles, Free Content
		$this->tags = $this->get_array_of_nodes('tag');
		// print_r($tags); exit();

		// Get Authors
		// todo: how to load existing email addreses for authors?  WXR is hard-coded to @example
		// just do this with local WP db connection?
		// todo: do any authors have a bio/description?
		$this->authors = $this->get_array_of_nodes('author');
		// print_r($authors); exit();

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
			
			$post = [
				'author'  => 'Ron Author',
				'categories' => [
					'Bonds', 
					'Corporate & Sovereign Strategy',
					'Debt',
				],
				'content' => (string) $xml->body,
				// todo: attribute createDate?
				'date'    => (string) $xml->displayDate,
				// todo: column expireDate?
				'excerpt' => (string) $xml->snippet,
				'meta'    => [
					'newspack_lf_author' => $this->get_authors_from_list( (string) $xml->authors ),
					'newspack_lf_checksum' => md5( serialize( $row ) ),
					'newspack_lf_original_id' => $row['nodeId'],
					'newspack_lf_original_url' => $this->get_url_from_path( $xml['path'] ),
					'newspack_lf_original_version' => $row['versionId'],
				],
				'title'   => $xml['nodeName'],
				// todo: test existing slugs across different content types for duplicates
				'url'    => $xml['urlName'],
			];

			// Convert "content type" to category
			switch( $xml['nodeTypeAlias'] ) {
				case 'dailyBriefArticle': $post['categories'][] = 'Daily Briefs'; break;
				case 'magazineArticle': $post['categories'][] = 'Magazine'; break;
				case 'webArticle': $post['categories'][] = 'Web Articles'; break;
			}
			
			//Free Content
			// $post['categories'][]
			
			// <metaKeywords><![CDATA[bond buyback, liability management, Arcos Dorados, McDonald's, Argentina]]></metaKeywords>
			// <tags><![CDATA[1136]]></tags>
			// 
			// <isFree>0</isFree>



			// <image><![CDATA[64047]]></image>
				// body: <img

			// print_r($post); 

			$data['posts'][] = $post;

		} // while content

		// print_r($data);
		exit();

		// Create WXR file
		// Newspack_WXR_Exporter::generate_export( $data );
		// WP_CLI::success( sprintf( "\n" . 'Exported to file %s ...', $data[ 'export_file' ] ) );

	}

	private function get_array_of_nodes( $content_type ) {

		$result = $this->pdo->prepare('
			select cmsDocument.nodeId, cmsContentXML.xml
			from cmsDocument
			join cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			join cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			join cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias = :content_type
			where cmsDocument.published = 1	
		');
		$result->execute( array( 
			':content_type' => $content_type 
		));

		$arr = array();
		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   
			$arr[$row['nodeId']] = $row['xml'];
		}  

		return $arr;

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

	// example: "-1,1051,1080,32617,32834,32876,32886" (last element is current element)
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
}
