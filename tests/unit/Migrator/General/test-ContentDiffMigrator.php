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
	// public function setUp() {
	// 	// $this->migrator = ContentDiffMigrator;
	// }

	// public function test_should_work() {
	// 	$expected = true;
	// 	$actual   = true;
	//
	// 	$this->assertSame( $expected, $actual );
	// }

	// public function test_should_load_data_correctly() {
	//
	// 	$wpdb_mock = $this->createMock( 'wpdb' );
	// 	$wpdb_mock->expects( $this->once() )
	// 			->method('select')
	// 			->with( $this->equalTo('a1'), $this->equalTo('a2') )
	// 			->willReturn('fooReturnnnn')
	// 	;
	//
	// 	$logic = new ContentDiffMigrator( $wpdb_mock );
	// 	$res = $logic->a();
	// 	var_dump($res);
	// }

	public function test_should_select_from_db_and_load_data_array() {

		$post_id = 123;
		$post_author_id = 22;
		$live_table_prefix = 'live_wp_';

		$post_row = [
			'ID' => $post_id,
			'post_author' => $post_author_id,
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
		];

		$postmeta_rows = [
			[
				'meta_id' => 21,
				'post_id' => $post_id,
				'meta_key' => '_wp_page_template',
				'meta_value' => 'default'
			],
			[
				'meta_id' => 22,
				'post_id' => $post_id,
				'meta_key' => 'custom_meta',
				'meta_value' => 'custom_value'
			],
		];

		$author_user_row = [
			'ID' => $post_author_id,
			'user_login' => 'admin',
			'user_pass' => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
			'user_nicename' => 'admin',
			'user_email' => 'admin@local.test',
			'user_url' => 'http=>\/\/testing.test',
			'user_registered' => '2021-09-23T09=>43=>56.000Z',
			'user_activation_key' => '',
			'user_status' => 0,
			'display_name' => 'admin'
		];

		$author_usermeta_rows = [
			[
				'umeta_id' => 1,
				'user_id' => $post_author_id,
				'meta_key' => 'nickname',
				'meta_value' => 'admin',
			],
			[
				'umeta_id' => 2,
				'user_id' => $post_author_id,
				'meta_key' => 'first_name',
				'meta_value' => 'Admin',
			],
			[
				'umeta_id' => 3,
				'user_id' => $post_author_id,
				'meta_key' => 'last_name',
				'meta_value' => 'Adminowich',
			],
		];

		// Comment 1 without a user and some meta.
		$comment_1_id = 11;
		$comment_1_user_id = 0;
		// Comment 2 with an existing user (same as Post Author).
		$comment_2_id = 12;
		$comment_2_user_id = 22;
		// Comment 3 with an new user and a comment parent.
		$comment_3_id = 13;
		$comment_3_user_id = 23;

		$comments_rows = [
			[
				'comment_ID' => $comment_1_id,
				'comment_post_ID' => $post_id,
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
				'user_id' => $comment_1_user_id,
			],
			[
				'comment_ID' => $comment_2_id,
				'comment_post_ID' => $post_id,
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
				'user_id' => $comment_2_user_id,
			],
			[
				'comment_ID' => $comment_3_id,
				'comment_post_ID' => $post_id,
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
				'user_id' => $comment_3_user_id,
			],
		];

		$comment_1_meta_rows = [
			[
				'meta_id' => 1,
				'comment_id' => $comment_1_id,
				'meta_key' => 'meta_a1',
				'meta_value' => 'value_a1',
			],
			[
				'meta_id' => 2,
				'comment_id' => $comment_1_id,
				'meta_key' => 'meta_a2',
				'meta_value' => 'value_a2',
			],
		];

		$comment_3_user_row = [
			'ID' => $comment_3_user_id,
			'user_login' => 'test_user',
			'user_pass' => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
			'user_nicename' => 'test_user',
			'user_email' => 'test_user@local.test',
			'user_url' => 'http=>\/\/testing.test',
			'user_registered' => '2021-09-23T09=>43=>56.000Z',
			'user_activation_key' => '',
			'user_status' => 0,
			'display_name' => 'test_user'
		];

		$comment_3_user_meta_rows = [
			[
				'umeta_id' => 11,
				'user_id' => $comment_3_user_id,
				'meta_key' => 'nickname',
				'meta_value' => 'bla',
			],
			[
				'umeta_id' => 12,
				'user_id' => $comment_3_user_id,
				'meta_key' => 'first_name',
				'meta_value' => 'bla bla',
			],
		];

		$term_taxonomy_1_id = 1;
		$term_taxonomy_2_id = 2;
		$term_relationships_rows = [
			[
				'object_id' => $post_id,
				'term_taxonomy_id' => $term_taxonomy_1_id,
				'term_order' => 0
			],
			[
				'object_id' => $post_id,
				'term_taxonomy_id' => $term_taxonomy_2_id,
				'term_order' => 0
			],
		];

		// Term 1 has some meta.
		$term_1_id = 41;
		// Term 2 has no meta.
		$term_2_id = 42;

		$term_taxonomy_1_row = [
			'term_taxonomy_id' => $term_taxonomy_1_id,
			'term_id' => $term_1_id,
			'taxonomy' => 'category',
			'description' => '',
			'parent' => 0,
			'count' => 8
		];
		$term_taxonomy_2_row = [
			'term_taxonomy_id' => $term_taxonomy_2_id,
			'term_id' => $term_2_id,
			'taxonomy' => 'category',
			'description' => 'Et accusamus odio aut dolor sed voluptas ea aliquid',
			'parent' => 0,
			'count' => 8
		];

		$term_1_row = [
			'term_id' => $term_1_id,
			'name' => 'Uncategorized',
			'slug' => 'uncategorized',
			'term_group' => 0
		];
		$term_2_row = [
			'term_id' => $term_2_id,
			'name' => 'Officia eos ut temporibus',
			'slug' => 'officia-eos-ut-temporibus',
			'term_group' => 0
		];

		$term_1_meta_rows = [
			[
				'meta_id' => 1,
				'term_id' => $term_1_id,
				'meta_key' => '_some_numbermeta',
				'meta_value' => '7'
			],
			[
				'meta_id' => 1,
				'term_id' => $term_1_id,
				'meta_key' => '_some_other_numbermeta',
				'meta_value' => '71'
			],
		];

		$data_expected = [
			'post' => $post_row,
			'postmeta' => $postmeta_rows,
			'comment' => $comments_rows,
			'commentmeta' => $comment_1_meta_rows,
			'users' => [
				$author_user_row,
				$comment_3_user_row
			],
			'usermeta' => array_merge(
				$author_usermeta_rows,
				$comment_3_user_meta_rows,
			),
			'term_relationships' => $term_relationships_rows,
			'term_taxonomy' => [
				$term_taxonomy_1_row,
				$term_taxonomy_2_row,
			],
			'terms' => [
				$term_1_row,
				$term_2_row,
			],
			'termmeta' => $term_1_meta_rows,
		];

		// Value maps are defined by \PHPUnit\Framework\TestCase::returnValueMap (they consist of [ arg1, arg2, ..., return_value ]).
		$wpdb_prepare_map = [];
		$wpdb_get_row_map = [];
		$wpdb_get_results_map = [];

		// `live_wp_posts` expected calls to $wpdb::prepare() and $wpdb::get_row().
		$wpdb_prepare_map[] = [ ' WHERE ID = %s', [ $post_id ], " WHERE ID = {$post_id}" ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE ID = %s', $live_table_prefix . 'posts', $post_id ), ARRAY_A, $post_row ];

		// `live_wp_postmeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		$wpdb_prepare_map[] = [ ' WHERE post_id = %s', [ $post_id ], " WHERE post_id = {$post_id}" ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE post_id = %s', $live_table_prefix . 'postmeta', $post_id ), ARRAY_A, $postmeta_rows ];

		// `live_wp_users` expected calls to $wpdb::prepare() and $wpdb::get_row().
		$wpdb_prepare_map[] = [ ' WHERE ID = %s', [ $post_author_id ], " WHERE ID = {$post_author_id}" ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE ID = %s', $live_table_prefix . 'users', $post_author_id ), ARRAY_A, $author_user_row ];

		// `live_wp_usermeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		$wpdb_prepare_map[] = [ ' WHERE user_id = %s', [ $post_author_id ], " WHERE user_id = {$post_author_id}" ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE user_id = %s', $live_table_prefix . 'usermeta', $post_author_id ), ARRAY_A, $author_usermeta_rows ];

		// `live_wp_comments` expected calls to $wpdb::prepare() and $wpdb::get_results().
		$wpdb_prepare_map[] = [ ' WHERE comment_post_ID = %s', [ $post_id ], " WHERE comment_post_ID = {$post_id}" ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_post_ID = %s', $live_table_prefix . 'comments', $post_id ), ARRAY_A, $comments_rows ];

		// `live_wp_commentmeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		$wpdb_prepare_map[] = [ ' WHERE comment_id = %s', [ $comment_1_id ], " WHERE comment_id = {$comment_1_id}" ];
		$wpdb_prepare_map[] = [ ' WHERE comment_id = %s', [ $comment_2_id ], " WHERE comment_id = {$comment_2_id}" ];
		$wpdb_prepare_map[] = [ ' WHERE comment_id = %s', [ $comment_3_id ], " WHERE comment_id = {$comment_3_id}" ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_id = %s', $live_table_prefix . 'commentmeta', $comment_1_id ), ARRAY_A, $comment_1_meta_rows ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_id = %s', $live_table_prefix . 'commentmeta', $comment_2_id ), ARRAY_A, [] ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE comment_id = %s', $live_table_prefix . 'commentmeta', $comment_3_id ), ARRAY_A, [] ];

		// Comment 3 User expected calls to $wpdb::prepare() and $wpdb::get_row().
		$wpdb_prepare_map[] = [ ' WHERE ID = %s', [ $comment_3_user_id ], " WHERE ID = {$comment_3_user_id}" ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE ID = %s', $live_table_prefix . 'users', $comment_3_user_id ), ARRAY_A, $comment_3_user_row ];

		// Comment 3 User Metas expected calls to $wpdb::prepare() and $wpdb::get_results().
		$wpdb_prepare_map[] = [ ' WHERE user_id = %s', [ $comment_3_user_id ], " WHERE user_id = {$comment_3_user_id}" ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE user_id = %s', $live_table_prefix . 'usermeta', $comment_3_user_id ), ARRAY_A, $comment_3_user_meta_rows ];

		// `live_wp_term_relationships` expected calls to $wpdb::prepare() and $wpdb::get_results().
		$wpdb_prepare_map[] = [ ' WHERE object_id = %s', [ $post_id ], " WHERE object_id = {$post_id}" ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE object_id = %s', $live_table_prefix . 'term_relationships', $post_id ), ARRAY_A, $term_relationships_rows ];

		// `live_wp_term_taxonomy` expected calls to $wpdb::prepare() and $wpdb::get_row().
		$wpdb_prepare_map[] = [ ' WHERE term_taxonomy_id = %s', [ $term_taxonomy_1_id ], " WHERE term_taxonomy_id = {$term_taxonomy_1_id}" ];
		$wpdb_prepare_map[] = [ ' WHERE term_taxonomy_id = %s', [ $term_taxonomy_2_id ], " WHERE term_taxonomy_id = {$term_taxonomy_2_id}" ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_taxonomy_id = %s', $live_table_prefix . 'term_taxonomy', $term_taxonomy_1_id ), ARRAY_A, $term_taxonomy_1_row ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_taxonomy_id = %s', $live_table_prefix . 'term_taxonomy', $term_taxonomy_2_id ), ARRAY_A, $term_taxonomy_2_row ];

		// `live_wp_terms` expected calls to $wpdb::prepare() and $wpdb::get_row().
		$wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_1_id ], " WHERE term_id = {$term_1_id}" ];
		$wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_2_id ], " WHERE term_id = {$term_2_id}" ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'terms', $term_1_id ), ARRAY_A, $term_1_row ];
		$wpdb_get_row_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'terms', $term_2_id ), ARRAY_A, $term_2_row ];

		// `live_wp_termmeta` expected calls to $wpdb::prepare() and $wpdb::get_results().
		$wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_1_id ], " WHERE term_id = {$term_1_id}" ];
		$wpdb_prepare_map[] = [ ' WHERE term_id = %s', [ $term_2_id ], " WHERE term_id = {$term_2_id}" ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'termmeta', $term_1_id ), ARRAY_A, $term_1_meta_rows ];
		$wpdb_get_results_map[] = [ sprintf( 'SELECT * FROM %s WHERE term_id = %s', $live_table_prefix . 'termmeta', $term_2_id ), ARRAY_A, [] ];

		// Setting multiple expectations for method 'get_row' on mock of 'wpdb' class doesn't work for some reason, so here using
		// the Mock Builder for 'stdClass' instead.
		$wpdb_mock = $this->getMockBuilder( 'stdClass' )
			->setMethods( [ 'prepare', 'get_row', 'get_results' ] )
			->getMock();

		$wpdb_mock->expects( $this->exactly( count( $wpdb_prepare_map ) ) )
			->method( 'prepare' )
			->will( $this->returnValueMap( $wpdb_prepare_map ) );

		$wpdb_mock->expects( $this->exactly( count( $wpdb_get_row_map ) ) )
			->method( 'get_row' )
			->will( $this->returnValueMap( $wpdb_get_row_map ) );

		$wpdb_mock->expects( $this->exactly( count( $wpdb_get_results_map ) ) )
			->method( 'get_results' )
			->will( $this->returnValueMap( $wpdb_get_results_map ) );

		$logic = new ContentDiffMigrator( $wpdb_mock );
		$data = $logic->get_data( $post_id, $live_table_prefix );


		// TODO use DataProvider for queried rows and for value maps.


		$this->assertEquals( $data_expected, $data );
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
