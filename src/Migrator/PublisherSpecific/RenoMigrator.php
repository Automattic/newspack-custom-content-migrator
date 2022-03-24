<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for Reno News.
 */
class RenoMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator reno-postgress-archive-import',
			[ $this, 'cmd_postgress_archive_import' ],
			[
				'shortdesc' => 'Imports Reno\'s Postgres archive content.',
			]
		);
	}

	/**
	 * @param $args
	 * @param $assoc_args
	 */
	public function cmd_postgress_archive_import( $args, $assoc_args ) {
		try {
			$pdo = new \PDO( 'pgsql:host=localhost;port=5432;dbname=renovvv;', 'postgres', 'root', [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		} catch ( \PDOException $e ) {
			// Check whether Postgres is installed and creds are valid.
			die( $e->getMessage() );
		}


	    $statement = $pdo->query( "SELECT * FROM users_user ;" );
        while( $result = $statement->fetch( \PDO::FETCH_ASSOC )  ) {
	        // printf ('<table><thead><tr><th  colspan="4" align="left" >TCDD 3.BOLGE MUDURLUGU <img src="tcdd.png" align="right" width="92px" /></th></tr><tr><th  colspan="4">Hemzemin Gecitler ve Ozellikeri</th></tr></thead><tbody><tr><th>Kilometre</th><td colspan="3">%s</td></tr><tr><th>Turu</th><td colspan="3">%s</td></tr><tr><th>Hat Kesimi</th><td colspan="3">%s</td></tr><tr><th>Sehir</th><td colspan="3">%s</td></tr><tr><th>Ilce</th><td colspan="3">%s</td></tr><tr><th>Mahalle</th><td colspan="3">%s</td></tr><tr><th colspan="4" > copyright © all rights reserved by Piri Reis Bilisim  </th></tr></tbody></table>', $result["km"],$result["turu"], $result["hat_kesimi"], $result["ili"], $result["ilcesi"],$result["mahadi"]);
	        echo sprintf( "%d %s \n", $result['id'], $result['username'] );
        }
	}
}
