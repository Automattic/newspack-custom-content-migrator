<?php
/**
 * Test class for the \NewspackCustomContentMigrator\Migrator\General\ContentDiffMigrator.
 *
 * @package Newspack
 */

namespace NewspackCustomContentMigratorTest\Migrator\General;

use WP_UnitTestCase;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator;

/**
 * Class TestBlockquotePatcher.
 */
class TestContentDiffMigrator extends WP_UnitTestCase {

	/**
	 * ContentDiffMigrator.
	 *
	 * @var ContentDiffMigrator
	 */
	// private $migrator;

	/**
	 * Override setUp.
	 */
	public function setUp() {
		parent::setUp();

		// Setting multiple expectations for method 'get_row' on mock of 'wpdb' class doesn't work for some reason, so here using
		// the Mock Builder for 'stdClass' instead.
		$this->wpdb_mock = $this->getMockBuilder( 'stdClass' )
		                        ->setMethods( [ 'prepare', 'get_row', 'get_results' ] )
		                        ->getMock();
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_row( $data_sample ) {

		$post_id = 123;
		$live_table_prefix = 'live_wp_';

		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}posts WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $post_id ], $sql ];
		$wpdb_get_row_map[] = [ $sql, ARRAY_A, $data_sample[ ContentDiffMigrator::DATAKEY_POST ] ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $wpdb_get_row_map ) );

		$logic = new ContentDiffMigrator( $this->wpdb_mock );
		$post_row_actual = $logic->select_post_row( $live_table_prefix, $post_id );

		$this->assertEquals( $data_sample[ ContentDiffMigrator::DATAKEY_POST ], $post_row_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_postmeta_rows( $data_sample ) {

		$post_id = 123;
		$live_table_prefix = 'live_wp_';

		$wpdb_prepare_map = [];
		$wpdb_get_results_map = [];

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}postmeta WHERE post_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $post_id ], $sql ];
		$wpdb_get_results_map[] = [ $sql, ARRAY_A, $data_sample[ ContentDiffMigrator::DATAKEY_POSTMETA ] ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$logic = new ContentDiffMigrator( $this->wpdb_mock );
		$postmeta_rows_actual = $logic->select_postmeta_rows( $live_table_prefix, $post_id );

		$this->assertEquals( $data_sample[ ContentDiffMigrator::DATAKEY_POSTMETA ], $postmeta_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_author_row( $data_sample ) {

		// Prepare parameters.

		$post_id = 123;
		$live_table_prefix = 'live_wp_';

		$post_author_id = 22;

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];

		// Prepare mock and expectations.
$logic = new ContentDiffMigrator( $this->wpdb_mock );
// TODO try and move to the bottom to see if $this->wpdb_mock is passed by reference and can be configured afterwards. I think... it should work, objects are passed by reference, aren't they :)

		$author_row_sample = $logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}users WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $post_author_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $post_author_id ], $sql ];
		$wpdb_get_row_map[] = [ $sql, ARRAY_A, $author_row_sample ];


		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $wpdb_get_row_map ) );

		// Execute and assert.

		$author_row_actual = $logic->select_user_row( $live_table_prefix, $post_author_id );

		$this->assertEquals( $author_row_sample, $author_row_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_author_usermeta_rows( $data_sample ) {

		$live_table_prefix = 'live_wp_';
		$post_author_id = 22;


		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_results_map = [];

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$authormeta_rows_sample = $logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $post_author_id );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}usermeta WHERE user_id = %s";
		$sql = sprintf( $sql_prepare, $post_author_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $post_author_id ], $sql ];
		$wpdb_get_results_map[] = [ $sql, ARRAY_A, $authormeta_rows_sample ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$author_meta_rows_actual = $logic->select_usermeta_rows( $live_table_prefix, $post_author_id );

		$this->assertEquals( $authormeta_rows_sample, $author_meta_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_rows( $data_sample ) {

		$post_id = 123;
		$live_table_prefix = 'live_wp_';

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_results_map = [];

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}comments WHERE comment_post_ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $post_id ], $sql ];
		$wpdb_get_results_map[] = [ $sql, ARRAY_A, $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTS ] ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );
		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$comment_rows_actual = $logic->select_comment_rows( $live_table_prefix, $post_id );

		$this->assertEquals( $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTS ], $comment_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_commentmeta_rows( $data_sample ) {

		$live_table_prefix = 'live_wp_';
		$comment_1_id = 11;
		// Comment 2 with an existing user (same as Post Author).
		$comment_2_id = 12;
		// Comment 3 with an new user and a comment parent.
		$comment_3_id = 13;
		$comment_3_user_id = 23;

		$term_taxonomy_1_id = 1;
		$term_taxonomy_2_id = 2;

		// Term 1 has some meta.
		$term_1_id = 41;
		// Term 2 has no meta.
		$term_2_id = 42;


		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_results_map = [];

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$commentmeta_rows_sample = $logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_1_id );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}commentmeta WHERE comment_id = %s";
		$sql = sprintf( $sql_prepare, $comment_1_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $comment_1_id ], $sql ];
		$wpdb_get_results_map[] = [ $sql, ARRAY_A, $commentmeta_rows_sample ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$comment_rows_actual = $logic->select_commentmeta_rows( $live_table_prefix, $comment_1_id );

		$this->assertEquals( $commentmeta_rows_sample, $comment_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_user_row( $data_sample ) {

		$live_table_prefix = 'live_wp_';
		$comment_3_user_id = 23;

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];


		// Prepare mock and expectations.

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$user_row_sample = $logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment_3_user_id );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}users WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $comment_3_user_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $comment_3_user_id ], $sql ];
		$wpdb_get_row_map[] = [ $sql, ARRAY_A, $user_row_sample ];


		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $wpdb_get_row_map ) );

		$user_row_actual = $logic->select_user_row( $live_table_prefix, $comment_3_user_id );

		$this->assertEquals( $user_row_sample, $user_row_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_usermeta_rows( $data_sample ) {

		$live_table_prefix = 'live_wp_';
		$comment_3_user_id = 23;

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];
		$wpdb_get_results_map = [];

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$usermeta_rows_sample = $logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $comment_3_user_id );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}usermeta WHERE user_id = %s";
		$sql = sprintf( $sql_prepare, $comment_3_user_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $comment_3_user_id ], $sql ];
		$wpdb_get_results_map[] = [ $sql, ARRAY_A, $usermeta_rows_sample ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$user_meta_rows_actual = $logic->select_usermeta_rows( $live_table_prefix, $comment_3_user_id );

		$this->assertEquals( $usermeta_rows_sample, $user_meta_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_term_relationships_rows( $data_sample ) {

		$post_id = 123;
		$live_table_prefix = 'live_wp_';

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_results_map = [];

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$term_relationships_rows_sample = $logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_TERMRELATIONSHIPS ], 'object_id', $post_id );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}term_relationships WHERE object_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $post_id ], $sql ];
		$wpdb_get_results_map[] = [ $sql, ARRAY_A, $term_relationships_rows_sample ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$term_relationships_rows_actual = $logic->select_term_relationships_rows( $live_table_prefix, $post_id );

		$this->assertEquals( $term_relationships_rows_sample, $term_relationships_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_term_taxonomy_rows( $data_sample ) {

		$live_table_prefix = 'live_wp_';
		$term_taxonomy_1_id = 1;
		$term_taxonomy_2_id = 2;

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$term_taxonomy_rows_sample = [
			$logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'ID', $term_taxonomy_1_id ),
			$logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'ID', $term_taxonomy_2_id ),
		];

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		foreach ( $term_taxonomy_rows_sample as $term_taxonomy_row_sample ) {
			$term_taxonomy_id  = $term_taxonomy_row_sample[ 'term_taxonomy_id' ];
			$sql_prepare = "SELECT * FROM {$live_table_prefix}term_taxonomy WHERE term_taxonomy_id = %s";
			$sql = sprintf( $sql_prepare, $term_taxonomy_id );
			$wpdb_prepare_map[] = [ $sql_prepare, [ $term_taxonomy_id ], $sql ];
			$wpdb_get_row_map[] = [ $sql, ARRAY_A, $term_taxonomy_row_sample ];
		}

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $wpdb_get_row_map ) );


		$term_taxonomy_rows_actual = [];
		foreach ( $term_taxonomy_rows_sample as $term_taxonomy_row_sample ) {
			$term_taxonomy_id  = $term_taxonomy_row_sample[ 'term_taxonomy_id' ];
			$term_taxonomy_rows_actual[] = $logic->select_term_taxonomy_row( $live_table_prefix, $term_taxonomy_id );
		}

		$this->assertEquals( $term_taxonomy_rows_sample, $term_taxonomy_rows_actual );
	}


	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_terms_rows( $data_sample ) {

		$live_table_prefix = 'live_wp_';
		// Term 1 has some meta.
		$term_1_id = 41;
		// Term 2 has no meta.
		$term_2_id = 42;

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];

		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$term_1_row_sample = $logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_1_id );
		$term_2_row_sample = $logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_2_id );
		$terms_rows_sample = array_merge( $term_1_row_sample, $term_2_row_sample );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}terms WHERE term_id = %s";
		// Term 1 calls.
		$sql = sprintf( $sql_prepare, $term_1_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $term_1_id ], $sql ];
		$wpdb_get_row_map[] = [ $sql, ARRAY_A, $term_1_row_sample ];
		// Term 2 calls.
		$sql = sprintf( $sql_prepare, $term_2_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $term_2_id ], $sql ];
		$wpdb_get_row_map[] = [ $sql, ARRAY_A, $term_2_row_sample ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $wpdb_get_row_map ) );

		$terms_rows_actual = array_merge(
			$logic->select_terms_row( $live_table_prefix, $term_1_id ),
			$logic->select_terms_row( $live_table_prefix, $term_2_id ),
		);

		$this->assertEquals( $terms_rows_sample, $terms_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_termmeta_rows( $data_sample ) {

		$live_table_prefix = 'live_wp_';
		// Term 1 has some meta.
		$term_1_id = 41;

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_results_map = [];


		$logic = new ContentDiffMigrator( $this->wpdb_mock );

		$termmeta_rows_sample = $logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_1_id );

		// Expected calls to $wpdb::prepare() and $wpdb::get_row().
		$sql_prepare = "SELECT * FROM {$live_table_prefix}termmeta WHERE term_id = %s";
		$sql = sprintf( $sql_prepare, $term_1_id );
		$wpdb_prepare_map[] = [ $sql_prepare, [ $term_1_id ], $sql ];
		$wpdb_get_results_map[] = [ $sql, ARRAY_A, $termmeta_rows_sample ];

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$term_relationships_rows_actual = $logic->select_termmeta_rows( $live_table_prefix, $term_1_id );

		$this->assertEquals( $termmeta_rows_sample, $term_relationships_rows_actual );
	}











	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function __test_should_select_from_db_and_load_data_array( $data_expected ) {

		// $post_id = 123;
		// $post_author_id = 22;
		// $live_table_prefix = 'live_wp_';
		//
		// $post_row = [
		// 	'ID' => $post_id,
		// 	'post_author' => $post_author_id,
		// 	'post_date' => '2021-09-23 11:43:56.000',
		// 	'post_date_gmt' => '2021-09-23 11:43:56.000',
		// 	'post_content' => '<p>WP</p>',
		// 	'post_title' => 'Hello world!',
		// 	'post_excerpt' => '',
		// 	'post_status' => 'publish',
		// 	'comment_status' => 'open',
		// 	'ping_status' => 'open',
		// 	'post_password' => '',
		// 	'post_name' => 'hello-world',
		// 	'to_ping' => '',
		// 	'pinged' => '',
		// 	'post_modified' => '2021-09-23 11:43:56.000',
		// 	'post_modified_gmt' => '2021-09-23 11:43:56.000',
		// 	'post_content_filtered' => '',
		// 	'post_parent' => 0,
		// 	'guid' => 'http://testing.test/?p=1',
		// 	'menu_order' => 0,
		// 	'post_type' => 'post',
		// 	'post_mime_type' => '',
		// 	'comment_count' => 1,
		// ];
		//
		// $postmeta_rows = [
		// 	[
		// 		'meta_id' => 21,
		// 		'post_id' => $post_id,
		// 		'meta_key' => '_wp_page_template',
		// 		'meta_value' => 'default'
		// 	],
		// 	[
		// 		'meta_id' => 22,
		// 		'post_id' => $post_id,
		// 		'meta_key' => 'custom_meta',
		// 		'meta_value' => 'custom_value'
		// 	],
		// ];
		//
		// $author_user_row = [
		// 	'ID' => $post_author_id,
		// 	'user_login' => 'admin',
		// 	'user_pass' => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
		// 	'user_nicename' => 'admin',
		// 	'user_email' => 'admin@local.test',
		// 	'user_url' => 'http=>\/\/testing.test',
		// 	'user_registered' => '2021-09-23T09=>43=>56.000Z',
		// 	'user_activation_key' => '',
		// 	'user_status' => 0,
		// 	'display_name' => 'admin'
		// ];
		//
		// $author_usermeta_rows = [
		// [
		// 	'umeta_id' => 1,
		// 	'user_id' => $post_author_id,
		// 	'meta_key' => 'nickname',
		// 	'meta_value' => 'admin',
		// ],
		// 	[
		// 		'umeta_id' => 2,
		// 		'user_id' => $post_author_id,
		// 		'meta_key' => 'first_name',
		// 		'meta_value' => 'Admin',
		// 	],
		// 	[
		// 		'umeta_id' => 3,
		// 		'user_id' => $post_author_id,
		// 		'meta_key' => 'last_name',
		// 		'meta_value' => 'Adminowich',
		// 	],
		// ];
		//
		// // Comment 1 without a user and some meta.
		// $comment_1_id = 11;
		// $comment_1_user_id = 0;
		// // Comment 2 with an existing user (same as Post Author).
		// $comment_2_id = 12;
		// $comment_2_user_id = 22;
		// // Comment 3 with an new user and a comment parent.
		// $comment_3_id = 13;
		// $comment_3_user_id = 23;
		//
		// $comments_rows = [
		// 	[
		// 		'comment_ID' => $comment_1_id,
		// 		'comment_post_ID' => $post_id,
		// 		'comment_author' => 'A WordPress Commenter',
		// 		'comment_author_email' => 'wapuu@wordpress.example',
		// 		'comment_author_url' => 'https=>\/\/wordpress.org\/',
		// 		'comment_author_IP' => '',
		// 		'comment_date' => '2021-09-23T09=>43=>56.000Z',
		// 		'comment_date_gmt' => '2021-09-23T09=>43=>56.000Z',
		// 		'comment_content' => 'howdy!',
		// 		'comment_karma' => 0,
		// 		'comment_approved' => '1',
		// 		'comment_agent' => '',
		// 		'comment_type' => 'comment',
		// 		'comment_parent' => 0,
		// 		'user_id' => $comment_1_user_id,
		// 	],
		// 	[
		// 		'comment_ID' => $comment_2_id,
		// 		'comment_post_ID' => $post_id,
		// 		'comment_author' => 'A WordPress Commenter',
		// 		'comment_author_email' => 'wapuu@wordpress.example',
		// 		'comment_author_url' => 'https=>\/\/wordpress.org\/',
		// 		'comment_author_IP' => '',
		// 		'comment_date' => '2021-09-23T09=>43=>56.000Z',
		// 		'comment_date_gmt' => '2021-09-23T09=>43=>56.000Z',
		// 		'comment_content' => 'howdy 2!',
		// 		'comment_karma' => 0,
		// 		'comment_approved' => '1',
		// 		'comment_agent' => '',
		// 		'comment_type' => 'comment',
		// 		'comment_parent' => 0,
		// 		'user_id' => $comment_2_user_id,
		// 	],
		// 	[
		// 		'comment_ID' => $comment_3_id,
		// 		'comment_post_ID' => $post_id,
		// 		'comment_author' => 'A WordPress Commenter',
		// 		'comment_author_email' => 'wapuu@wordpress.example',
		// 		'comment_author_url' => 'https=>\/\/wordpress.org\/',
		// 		'comment_author_IP' => '',
		// 		'comment_date' => '2021-09-23T09=>43=>56.000Z',
		// 		'comment_date_gmt' => '2021-09-23T09=>43=>56.000Z',
		// 		'comment_content' => 'reply to howdy 2!',
		// 		'comment_karma' => 0,
		// 		'comment_approved' => '1',
		// 		'comment_agent' => '',
		// 		'comment_type' => 'comment',
		// 		'comment_parent' => 12,
		// 		'user_id' => $comment_3_user_id,
		// 	],
		// ];
		//
		// $comment_1_meta_rows = [
		// 	[
		// 		'meta_id' => 1,
		// 		'comment_id' => $comment_1_id,
		// 		'meta_key' => 'meta_a1',
		// 		'meta_value' => 'value_a1',
		// 	],
		// 	[
		// 		'meta_id' => 2,
		// 		'comment_id' => $comment_1_id,
		// 		'meta_key' => 'meta_a2',
		// 		'meta_value' => 'value_a2',
		// 	],
		// ];
		//
		// $comment_3_user_row = [
		// 	'ID' => $comment_3_user_id,
		// 	'user_login' => 'test_user',
		// 	'user_pass' => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
		// 	'user_nicename' => 'test_user',
		// 	'user_email' => 'test_user@local.test',
		// 	'user_url' => 'http=>\/\/testing.test',
		// 	'user_registered' => '2021-09-23T09=>43=>56.000Z',
		// 	'user_activation_key' => '',
		// 	'user_status' => 0,
		// 	'display_name' => 'test_user'
		// ];
		//
		// $comment_3_user_meta_rows = [
		// 	[
		// 		'umeta_id' => 11,
		// 		'user_id' => $comment_3_user_id,
		// 		'meta_key' => 'nickname',
		// 		'meta_value' => 'bla',
		// 	],
		// 	[
		// 		'umeta_id' => 12,
		// 		'user_id' => $comment_3_user_id,
		// 		'meta_key' => 'first_name',
		// 		'meta_value' => 'bla bla',
		// 	],
		// ];
		//
		// $term_taxonomy_1_id = 1;
		// $term_taxonomy_2_id = 2;
		// $term_relationships_rows = [
		// 	[
		// 		'object_id' => $post_id,
		// 		'term_taxonomy_id' => $term_taxonomy_1_id,
		// 		'term_order' => 0
		// 	],
		// 	[
		// 		'object_id' => $post_id,
		// 		'term_taxonomy_id' => $term_taxonomy_2_id,
		// 		'term_order' => 0
		// 	],
		// ];
		//
		// // Term 1 has some meta.
		// $term_1_id = 41;
		// // Term 2 has no meta.
		// $term_2_id = 42;
		//
		// $term_taxonomy_1_row = [
		// 	'term_taxonomy_id' => $term_taxonomy_1_id,
		// 	'term_id' => $term_1_id,
		// 	'taxonomy' => 'category',
		// 	'description' => '',
		// 	'parent' => 0,
		// 	'count' => 8
		// ];
		// $term_taxonomy_2_row = [
		// 	'term_taxonomy_id' => $term_taxonomy_2_id,
		// 	'term_id' => $term_2_id,
		// 	'taxonomy' => 'category',
		// 	'description' => 'Et accusamus odio aut dolor sed voluptas ea aliquid',
		// 	'parent' => 0,
		// 	'count' => 8
		// ];
		//
		// $term_1_row = [
		// 	'term_id' => $term_1_id,
		// 	'name' => 'Uncategorized',
		// 	'slug' => 'uncategorized',
		// 	'term_group' => 0
		// ];
		// $term_2_row = [
		// 	'term_id' => $term_2_id,
		// 	'name' => 'Officia eos ut temporibus',
		// 	'slug' => 'officia-eos-ut-temporibus',
		// 	'term_group' => 0
		// ];
		//
		// $term_1_meta_rows = [
		// 	[
		// 		'meta_id' => 1,
		// 		'term_id' => $term_1_id,
		// 		'meta_key' => '_some_numbermeta',
		// 		'meta_value' => '7'
		// 	],
		// 	[
		// 		'meta_id' => 1,
		// 		'term_id' => $term_1_id,
		// 		'meta_key' => '_some_other_numbermeta',
		// 		'meta_value' => '71'
		// 	],
		// ];
		//
		// $data_expected = [
		// 	'post' => $post_row,
		// 	'postmeta' => $postmeta_rows,
		// 	'comment' => $comments_rows,
		// 	'commentmeta' => $comment_1_meta_rows,
		// 	'users' => [
		// 		$author_user_row,
		// 		$comment_3_user_row
		// 	],
		// 	'usermeta' => array_merge(
		// 		$author_usermeta_rows,
		// 		$comment_3_user_meta_rows,
		// 	),
		// 	'term_relationships' => $term_relationships_rows,
		// 	'term_taxonomy' => [
		// 		$term_taxonomy_1_row,
		// 		$term_taxonomy_2_row,
		// 	],
		// 	'terms' => [
		// 		$term_1_row,
		// 		$term_2_row,
		// 	],
		// 	'termmeta' => $term_1_meta_rows,
		// ];


		$post_id = 123;
		$post_author_id = 22;
		$live_table_prefix = 'live_wp_';

		// Comment 1 without a user and some meta.
		$comment_1_id = 11;
		// Comment 2 with an existing user (same as Post Author).
		$comment_2_id = 12;
		// Comment 3 with an new user and a comment parent.
		$comment_3_id = 13;
		$comment_3_user_id = 23;
		$term_taxonomy_1_id = 1;
		$term_taxonomy_2_id = 2;
		// Term 1 has some meta.
		$term_1_id = 41;
		// Term 2 has no meta.
		$term_2_id = 42;


		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];
		$wpdb_get_results_map = [];

		// `live_wp_posts` expected calls to $wpdb::prepare() and $wpdb::get_row().
		$wpdb_prepare_map[] = [ ' WHERE ID = %s', [ $post_id ], " WHERE ID = {$post_id}" ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE ID = %s', $live_table_prefix . 'posts', $post_id ), ARRAY_A, $data_expected[ ContentDiffMigrator::DATAKEY_POST ] ];

		// Setting multiple expectations for method 'get_row' on mock of 'wpdb' class doesn't work for some reason, so here using
		// the Mock Builder for 'stdClass' instead.
		$this->wpdb_mock = $this->getMockBuilder( 'stdClass' )
		                  ->setMethods( [ 'prepare', 'get_row', 'get_results' ] )
		                  ->getMock();

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $wpdb_get_row_map ) );

		// $this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		//           ->method( 'get_results' )
		//           ->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$logic = new ContentDiffMigrator( $this->wpdb_mock );
		$data = $logic->get_data( $post_id, $live_table_prefix );


		// TODO use DataProvider for queried rows and for value maps.


		$this->assertEquals( $data_expected[ ContentDiffMigrator::DATAKEY_POST ], $data[ ContentDiffMigrator::DATAKEY_POST ] );


		// // `live_wp_posts` expected calls to $wpdb::prepare() and $wpdb::get_row().
		// $wpdb_prepare_map[] = [ ' WHERE ID = %s', [ $post_id ], " WHERE ID = {$post_id}" ];
		// $wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE ID = %s', $live_table_prefix . 'posts', $post_id ), ARRAY_A, $post_row ];
		//
		// // `live_wp_postmeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		// $wpdb_prepare_map[] = [ ' WHERE post_id = %s', [ $post_id ], " WHERE post_id = {$post_id}" ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE post_id = %s', $live_table_prefix . 'postmeta', $post_id ), ARRAY_A, $postmeta_rows ];
		//
		// // `live_wp_users` expected calls to $wpdb::prepare() and $wpdb::get_row().
		// $wpdb_prepare_map[] = [ ' WHERE ID = %s', [ $post_author_id ], " WHERE ID = {$post_author_id}" ];
		// $wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE ID = %s', $live_table_prefix . 'users', $post_author_id ), ARRAY_A, $author_user_row ];
		//
		// // `live_wp_usermeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		// $wpdb_prepare_map[] = [ ' WHERE user_id = %s', [ $post_author_id ], " WHERE user_id = {$post_author_id}" ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE user_id = %s', $live_table_prefix . 'usermeta', $post_author_id ), ARRAY_A, $author_usermeta_rows ];
		//
		// // `live_wp_comments` expected calls to $wpdb::prepare() and $wpdb::get_results().
		// $wpdb_prepare_map[] = [ ' WHERE comment_post_ID = %s', [ $post_id ], " WHERE comment_post_ID = {$post_id}" ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_post_ID = %s', $live_table_prefix . 'comments', $post_id ), ARRAY_A, $comments_rows ];
		//
		// // `live_wp_commentmeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		// $wpdb_prepare_map[] = [ ' WHERE comment_id = %s', [ $comment_1_id ], " WHERE comment_id = {$comment_1_id}" ];
		// $wpdb_prepare_map[] = [ ' WHERE comment_id = %s', [ $comment_2_id ], " WHERE comment_id = {$comment_2_id}" ];
		// $wpdb_prepare_map[] = [ ' WHERE comment_id = %s', [ $comment_3_id ], " WHERE comment_id = {$comment_3_id}" ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_id = %s', $live_table_prefix . 'commentmeta', $comment_1_id ), ARRAY_A, $comment_1_meta_rows ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_id = %s', $live_table_prefix . 'commentmeta', $comment_2_id ), ARRAY_A, [] ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_id = %s', $live_table_prefix . 'commentmeta', $comment_3_id ), ARRAY_A, [] ];
		//
		// // Comment 3 User expected calls to $wpdb::prepare() and $wpdb::get_row().
		// $wpdb_prepare_map[] = [ ' WHERE ID = %s', [ $comment_3_user_id ], " WHERE ID = {$comment_3_user_id}" ];
		// $wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE ID = %s', $live_table_prefix . 'users', $comment_3_user_id ), ARRAY_A, $comment_3_user_row ];
		//
		// // Comment 3 User Metas expected calls to $wpdb::prepare() and $wpdb::get_results().
		// $wpdb_prepare_map[] = [ ' WHERE user_id = %s', [ $comment_3_user_id ], " WHERE user_id = {$comment_3_user_id}" ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE user_id = %s', $live_table_prefix . 'usermeta', $comment_3_user_id ), ARRAY_A, $comment_3_user_meta_rows ];
		//
		// // `live_wp_term_relationships` expected calls to $wpdb::prepare() and $wpdb::get_results().
		// $wpdb_prepare_map[] = [ ' WHERE object_id = %s', [ $post_id ], " WHERE object_id = {$post_id}" ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE object_id = %s', $live_table_prefix . 'term_relationships', $post_id ), ARRAY_A, $term_relationships_rows ];
		//
		// // `live_wp_term_taxonomy` expected calls to $wpdb::prepare() and $wpdb::get_row().
		// $wpdb_prepare_map[] = [ ' WHERE term_taxonomy_id = %s', [ $term_taxonomy_1_id ], " WHERE term_taxonomy_id = {$term_taxonomy_1_id}" ];
		// $wpdb_prepare_map[] = [ ' WHERE term_taxonomy_id = %s', [ $term_taxonomy_2_id ], " WHERE term_taxonomy_id = {$term_taxonomy_2_id}" ];
		// $wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_taxonomy_id = %s', $live_table_prefix . 'term_taxonomy', $term_taxonomy_1_id ), ARRAY_A, $term_taxonomy_1_row ];
		// $wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_taxonomy_id = %s', $live_table_prefix . 'term_taxonomy', $term_taxonomy_2_id ), ARRAY_A, $term_taxonomy_2_row ];
		//
		// // `live_wp_terms` expected calls to $wpdb::prepare() and $wpdb::get_row().
		// $wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_1_id ], " WHERE term_id = {$term_1_id}" ];
		// $wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_2_id ], " WHERE term_id = {$term_2_id}" ];
		// $wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'terms', $term_1_id ), ARRAY_A, $term_1_row ];
		// $wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'terms', $term_2_id ), ARRAY_A, $term_2_row ];
		//
		// // `live_wp_termmeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		// $wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_1_id ], " WHERE term_id = {$term_1_id}" ];
		// $wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_2_id ], " WHERE term_id = {$term_2_id}" ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'termmeta', $term_1_id ), ARRAY_A, $term_1_meta_rows ];
		// $wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'termmeta', $term_2_id ), ARRAY_A, [] ];

		// // Setting multiple expectations for method 'get_row' on mock of 'wpdb' class doesn't work for some reason, so here using
		// // the Mock Builder for 'stdClass' instead.
		// $this->wpdb_mock = $this->getMockBuilder( 'stdClass' )
		// 	->setMethods( [ 'prepare', 'get_row', 'get_results' ] )
		// 	->getMock();
		//
		// $this->wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
		// 	->method( 'prepare' )
		// 	->will( $this->returnValueMap( $wpdb_prepare_map ) );
		//
		// $this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
		// 	->method( 'get_row' )
		// 	->will( $this->returnValueMap( $wpdb_get_row_map ) );
		//
		// $this->wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
		// 	->method( 'get_results' )
		// 	->will( $this->returnValueMap( $wpdb_get_results_map ) );
		//
		// $logic = new ContentDiffMigrator( $this->wpdb_mock );
		// $data = $logic->get_data( $post_id, $live_table_prefix );
		//
		//
		// // TODO use DataProvider for queried rows and for value maps.
		//
		//
		// $this->assertEquals( $data_expected, $data );
	}

	/**
	 * Sample data.
	 *
	 * @return \array[][][] Array with keys and values defined in ContentDiffMigrator::get_empty_data_array.
	 */
	public function db_data_provider() {
		return [
			[
				[
					// Post.
					ContentDiffMigrator::DATAKEY_POST => [
						'ID' => 123,
						'post_author' => 22,
						'post_date' => '2021-09-23 11:43:56.000',
						'post_date_gmt' => '2021-09-23 11:43:56.000',
						'post_content' => '<p>WP</p>',
						'post_title' => 'Hello world!',
						'post_excerpt' => '',
						'post_status' => 'publish',
						'comment_status' => 'open',
						'ping_status' => 'open',
						'post_password' => '',
						'post_name' => 'hello-world',
						'to_ping' => '',
						'pinged' => '',
						'post_modified' => '2021-09-23 11:43:56.000',
						'post_modified_gmt' => '2021-09-23 11:43:56.000',
						'post_content_filtered' => '',
						'post_parent' => 0,
						'guid' => 'http://testing.test/?p=1',
						'menu_order' => 0,
						'post_type' => 'post',
						'post_mime_type' => '',
						'comment_count' => 1,
					],
					// Postmeta.
					ContentDiffMigrator::DATAKEY_POSTMETA => [
						[
							'meta_id' => 21,
							'post_id' => 123,
							'meta_key' => '_wp_page_template',
							'meta_value' => 'default'
						],
						[
							'meta_id' => 22,
							'post_id' => 123,
							'meta_key' => 'custom_meta',
							'meta_value' => 'custom_value'
						],
					],
					ContentDiffMigrator::DATAKEY_COMMENTS => [
						// Comment 1 without user.
						[
							'comment_ID' => 11,
							'comment_post_ID' => 123,
							'comment_author' => 'A WordPress Commenter',
							'comment_author_email' => 'wapuu@wordpress.example',
							'comment_author_url' => 'https=>\/\/wordpress.org\/',
							'comment_author_IP' => '',
							'comment_date' => '2021-09-23T09=>43=>56.000Z',
							'comment_date_gmt' => '2021-09-23T09=>43=>56.000Z',
							'comment_content' => 'howdy!',
							'comment_karma' => 0,
							'comment_approved' => '1',
							'comment_agent' => '',
							'comment_type' => 'comment',
							'comment_parent' => 0,
							'user_id' => 0,
						],
						// Comment 2 with existing user.
						[
							'comment_ID' => 12,
							'comment_post_ID' => 123,
							'comment_author' => 'A WordPress Commenter',
							'comment_author_email' => 'wapuu@wordpress.example',
							'comment_author_url' => 'https=>\/\/wordpress.org\/',
							'comment_author_IP' => '',
							'comment_date' => '2021-09-23T09=>43=>56.000Z',
							'comment_date_gmt' => '2021-09-23T09=>43=>56.000Z',
							'comment_content' => 'howdy 2!',
							'comment_karma' => 0,
							'comment_approved' => '1',
							'comment_agent' => '',
							'comment_type' => 'comment',
							'comment_parent' => 0,
							'user_id' => 22,
						],
						// Comment 3 with new user.
						[
							'comment_ID' => 13,
							'comment_post_ID' => 123,
							'comment_author' => 'A WordPress Commenter',
							'comment_author_email' => 'wapuu@wordpress.example',
							'comment_author_url' => 'https=>\/\/wordpress.org\/',
							'comment_author_IP' => '',
							'comment_date' => '2021-09-23T09=>43=>56.000Z',
							'comment_date_gmt' => '2021-09-23T09=>43=>56.000Z',
							'comment_content' => 'reply to howdy 2!',
							'comment_karma' => 0,
							'comment_approved' => '1',
							'comment_agent' => '',
							'comment_type' => 'comment',
							'comment_parent' => 12,
							'user_id' => 23,
						],
					],
					// Comment 1 has some Comment Meta.
					ContentDiffMigrator::DATAKEY_COMMENTMETA => [
						[
							'meta_id' => 1,
							'comment_id' => 11,
							'meta_key' => 'meta_a1',
							'meta_value' => 'value_a1',
						],
						[
							'meta_id' => 2,
							'comment_id' => 11,
							'meta_key' => 'meta_a2',
							'meta_value' => 'value_a2',
						],
					],
					ContentDiffMigrator::DATAKEY_USERS => [
						// Post Author User, and Comment 2 existing User.
						[
							'ID' => 22,
							'user_login' => 'admin',
							'user_pass' => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
							'user_nicename' => 'admin',
							'user_email' => 'admin@local.test',
							'user_url' => 'http=>\/\/testing.test',
							'user_registered' => '2021-09-23T09=>43=>56.000Z',
							'user_activation_key' => '',
							'user_status' => 0,
							'display_name' => 'admin'
						],
						// Comment 3 new User.
						[
							'ID' => 23,
							'user_login' => 'test_user',
							'user_pass' => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
							'user_nicename' => 'test_user',
							'user_email' => 'test_user@local.test',
							'user_url' => 'http=>\/\/testing.test',
							'user_registered' => '2021-09-23T09=>43=>56.000Z',
							'user_activation_key' => '',
							'user_status' => 0,
							'display_name' => 'test_user'
						]
					],
					ContentDiffMigrator::DATAKEY_USERMETA => [
						// User Meta for Post Author, and Comment 2 existing User.
						[
							'umeta_id' => 1,
							'user_id' => 22,
							'meta_key' => 'nickname',
							'meta_value' => 'admin',
						],
						[
							'umeta_id' => 2,
							'user_id' => 22,
							'meta_key' => 'first_name',
							'meta_value' => 'Admin',
						],
						[
							'umeta_id' => 3,
							'user_id' => 22,
							'meta_key' => 'last_name',
							'meta_value' => 'Adminowich',
						],
						// User Meta for Comment 3 new User.
						[
							'umeta_id' => 11,
							'user_id' => 23,
							'meta_key' => 'nickname',
							'meta_value' => 'bla',
						],
						[
							'umeta_id' => 12,
							'user_id' => 23,
							'meta_key' => 'first_name',
							'meta_value' => 'bla bla',
						],
					],
					ContentDiffMigrator::DATAKEY_TERMRELATIONSHIPS => [
						[
							'object_id' => 123,
							'term_taxonomy_id' => 1,
							'term_order' => 0
						],
						[
							'object_id' => 123,
							'term_taxonomy_id' => 2,
							'term_order' => 0
						],
					],
					ContentDiffMigrator::DATAKEY_TERMTAXONOMY => [
						[
							'term_taxonomy_id' => 1,
							'term_id' => 41,
							'taxonomy' => 'category',
							'description' => '',
							'parent' => 0,
							'count' => 8
						],
						[
							'term_taxonomy_id' => 2,
							'term_id' => 42,
							'taxonomy' => 'category',
							'description' => 'Lorem Ipsum',
							'parent' => 0,
							'count' => 8
						]
					],
					ContentDiffMigrator::DATAKEY_TERMS => [
						// Term 1 has some meta.
						[
							'term_id' => 41,
							'name' => 'Uncategorized',
							'slug' => 'uncategorized',
							'term_group' => 0
						],
						// Term 2 has no meta.
						[
							'term_id' => 42,
							'name' => 'Officia eos ut temporibus',
							'slug' => 'officia-eos-ut-temporibus',
							'term_group' => 0
						],
					],
					ContentDiffMigrator::DATAKEY_TERMMETA => [
						// Term 1 Meta.
						[
							'meta_id' => 1,
							'term_id' => 41,
							'meta_key' => '_some_numbermeta',
							'meta_value' => '7'
						],
						[
							'meta_id' => 1,
							'term_id' => 41,
							'meta_key' => '_some_other_numbermeta',
							'meta_value' => '71'
						],
					],
				]
			]
		];
	}

	// public function test_should_insert_data_correctly() {
	// 	// exact wpdb calls
	// }

	/**

		simulate failures
			- using mocks for when your services break (db, payment, ...):
			- ie.
		// na drugom pozivu neke metode ovog objekta
		// dakle gleda koja po redu je 'process' zvana !
			$mock->expects($this->at(2))
				->method('proceess')
				->with($this->Order)
				->will($this->throwException(
					new GatewayOrderFailedException()
				)):

		check if objects interract correctly
		ie. Check if Controller modifies Reponse correcdtly:
			- mock Resonse obj, and check it's state

			$response = $this->getMock('ResponseObj');
			$response->expects($this->once())
				->method('header')
				->with('Location: /posts');
		- Give this mock to controller and see how it uses it
			$this->Controller->response = $response;
			$this->Controller->myMethod();

	 */
}
