<?php
/**
 * Newspack Custom Content Migrator: Ghost CMS Migrator.
 * 
 * Commands related to migrating Ghost CMS.
 * 
 * @link: https://ghost.org/
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use WP_CLI;

/**
 * Custom migration scripts for Ghost CMS.
 */
class GhostCMSMigrator implements InterfaceCommand {

	/**
	 * Log (file path)
	 *
	 * @var string $log
	 */
	private $log;

	/**
	 * Logger
	 * 
	 * @var Logger
	 */
	private $logger;

	/**
	 * Instance
	 * 
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger              = new Logger();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {

		WP_CLI::add_command(
			'newspack-content-migrator migrate-ghost-cms-content',
			[ $this, 'cmd_migrate_ghost_cms_content' ],
			[
				'shortdesc' => 'Migrate Ghost CMS Content using a Ghost JSON export.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'json-file',
						'description' => 'Path to Ghost JSON export file.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			]
		);
	}

	/**
	 * Migrate Ghost CMS Content.
	 * 
	 * @param array $pos_args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_migrate_ghost_cms_content( $pos_args, $assoc_args ) {

		global $wpdb;

		if( ! isset( $assoc_args['json-file'] ) || ! file_exists( $assoc_args['json-file'] ) ) {
			WP_CLI::error( 'JSON file not found.' );
		}

		$this->log = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ ) . '_' . __FUNCTION__ . '.log';

		$this->logger->log( $this->log, 'Doing migration.' );
		$this->logger->log( $this->log, '--json-file: ' . $assoc_args['json-file'] );
		
        $contents = file_get_contents( $assoc_args['json-file'] );
		$json = json_decode( $contents, null, 2147483647 );
        
        if( 0 != json_last_error() || 'No error' != json_last_error_msg() ) {
			WP_CLI::error( 'JSON file could not be parsed.' );
		}
		
		if( empty( $json->db[0]->data->posts ) ) {
			WP_CLI::error( 'JSON file contained no posts.' );
		}
	
		$cut_off_time = strtotime( "2023-02-17 00:52:24");

		$csv_posts = [];
		$csv_posts[] = array( 'Created', 'Updated', 'Published', 'Slug', 'Link' );
		

		foreach( $json->db[0]->data->posts as $json_post ) {
		
			if( strtotime( $json_post->created_at ) >= $cut_off_time ) {		
				$csv_arr = array( $json_post->created_at, $json_post->updated_at, $json_post->published_at, $json_post->slug );
				$pub_strtotime = ( $json_post->published_at ) ? strtotime( $json_post->published_at ) : 0;
				if( $pub_strtotime > 0 ) {
					$csv_arr[] = 'https://www.ourweekly.com/' . date("Y/m/d", $pub_strtotime ) . '/' . $json_post->slug . '/';
				}
				$csv_posts[] = $csv_arr;
				continue;
			}

			continue;

			// range of posts near cut off time
			// if( abs( $cut_off_time - $post_time ) < 60*60*24*7 ) {
			

			// $sql = $wpdb->prepare("
			// 	SELECT ID, post_modified
			// 	FROM wp_posts 
			// 	where post_type in ( 'page', 'post' ) and post_status = 'publish'
			// 	and post_date = %s and post_date = %s and post_name = %s
			// 	", $json_post->created_at, $json_post->published_at, $json_post->slug
			// );

			// $results = $wpdb->get_results( $sql );

			// if( 0 == count( $results ) ) {
			// 	array_unshift( $csv_arr, 'notfound' );
			// 	$csv_posts[] = $csv_arr;
			// 	continue;
			// }

			// if( 1 != count( $results ) ) {
			// 	WP_CLI::error( 'Duplicate posts in wordpress?' );
			// }

			// // Single post.

			// // Check if modified
			// if( strtotime( $results[0]->post_modified ) != strtotime( $json_post->updated_at ) ) {
			// 	array_unshift( $csv_arr, 'modified' );
			// 	$csv_posts[] = $csv_arr;
			// 	continue;
			// }

		}

		$fp = fopen('file'.time().'.csv', 'w');
		foreach ($csv_posts as $fields) {
			fputcsv($fp, $fields);
		}
		fclose($fp);
        
        
        $this->logger->log( $this->log, 'Done.', $this->logger::SUCCESS );
        
	}


}
