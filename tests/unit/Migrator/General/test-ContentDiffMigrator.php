<?php
/**
 * Test class for the \NewspackCustomContentMigrator\Migrator\General\ContentDiffMigrator.
 *
 * @package Newspack
 */

namespace NewspackCustomContentMigratorTest\Migrator\General;

use PHP_CodeSniffer\Tests\Core\Autoloader\Sub\C;
use WP_UnitTestCase;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator;
use WP_User;

/**
 * Class TestBlockquotePatcher.
 */
class TestContentDiffMigrator extends WP_UnitTestCase {

	/**
	 * @var \PHPUnit\Framework\MockObject\MockObject|stdClass
	 */
	private $wpdb_mock;

	/**
	 * @var ContentDiffMigrator.
	 */
	private $logic;

	/**
	 * @var string Local table prefix.
	 */
	private $table_prefix;

	/**
	 * Override setUp.
	 */
	public function setUp() {
		global $wpdb;

		parent::setUp();

		// Setting multiple expectations for method 'get_row' on mock of 'wpdb' class doesn't work for some reason, so here using
		// Mock Builder for the 'stdClass' instead.
		$this->wpdb_mock = $this->getMockBuilder( 'stdClass' )
		                        ->setMethods( [ 'prepare', 'get_row', 'get_results', 'insert', 'update', 'get_var' ] )
		                        ->getMock();
		$this->wpdb_mock->table_prefix = $wpdb->prefix;
		$this->wpdb_mock->posts = $wpdb->prefix . 'posts';
		$this->wpdb_mock->postmeta = $wpdb->prefix . 'postmeta';
		$this->wpdb_mock->users = $wpdb->prefix . 'users';
		$this->wpdb_mock->usermeta = $wpdb->prefix . 'usermeta';
		$this->wpdb_mock->comments = $wpdb->prefix . 'comments';
		$this->wpdb_mock->commentmeta = $wpdb->prefix . 'commentmeta';
		$this->wpdb_mock->terms = $wpdb->prefix . 'terms';
		$this->wpdb_mock->term_taxonomy = $wpdb->prefix . 'term_taxonomy';
		$this->wpdb_mock->term_relationships = $wpdb->prefix . 'term_relationships';
		$this->logic = new ContentDiffMigrator( $this->wpdb_mock );
		$this->table_prefix = $wpdb->prefix;
	}

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

	/**
	 * Tests that a Post is queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_post_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_row( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_post_row( $return_value_maps, $post_row, $live_table_prefix );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$post_row_actual = $this->logic->select_post_row( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $post_row, $post_row_actual );
	}

	/**
	 * Tests that Post Meta is queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_postmeta_rows.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_postmeta_rows( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$postmeta_rows = $data[ ContentDiffMigrator::DATAKEY_POSTMETA ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_postmeta_rows( $return_value_maps, $postmeta_rows, $live_table_prefix, $post_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$postmeta_rows_actual = $this->logic->select_postmeta_rows( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $postmeta_rows, $postmeta_rows_actual );
	}

	/**
	 * Tests that a User is queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_user_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_author_row( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_author_id = 22;
		$author_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_user_row( $return_value_maps, $author_row, $live_table_prefix );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$author_row_actual = $this->logic->select_user_row( $live_table_prefix, $post_author_id );

		// Assert.
		$this->assertEquals( $author_row, $author_row_actual );
	}

	/**
	 * Tests that User Meta is queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_usermeta_rows.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_author_usermeta_rows( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_author_id = 22;
		$authormeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $post_author_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_usermeta_rows( $return_value_maps, $authormeta_rows, $live_table_prefix, $post_author_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		                ->method( 'get_results' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$author_meta_rows_actual = $this->logic->select_usermeta_rows( $live_table_prefix, $post_author_id );

		// Test.
		$this->assertEquals( $authormeta_rows, $author_meta_rows_actual );
	}

	/**
	 * Tests that Comments are queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_comment_rows.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_rows( $data ) {
		// Prepare.
		$post_id = 123;
		$live_table_prefix = 'live_wp_';
		$comments_rows = $data[ ContentDiffMigrator::DATAKEY_COMMENTS ];

		// Mock
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_comments_rows( $return_value_maps, $comments_rows, $live_table_prefix, $post_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$comment_rows_actual = $this->logic->select_comment_rows( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $comments_rows, $comment_rows_actual );
	}

	/**
	 * Tests that a Comments is queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_comment_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_row( $data ) {
		// Prepare.
		$comment_id = 11;
		$live_table_prefix = 'live_wp_';
		$comment_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_id );

		// Mock
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_comment_row( $return_value_maps, $comment_row, $live_table_prefix, $comment_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$comment_row_actual = $this->logic->select_comment_row( $live_table_prefix, $comment_id );

		// Assert.
		$this->assertEquals( $comment_row, $comment_row_actual );
	}

	/**
	 * Tests that Comment Meta are queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_commentmeta_rows.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_commentmeta_rows( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$comment_1_id = 11;
		$commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_1_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_commentmeta_rows( $return_value_maps, $commentmeta_rows, $live_table_prefix, $comment_1_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$comment_rows_actual = $this->logic->select_commentmeta_rows( $live_table_prefix, $comment_1_id );

		// Assert.
		$this->assertEquals( $commentmeta_rows, $comment_rows_actual );
	}

	/**
	 * Tests that Terms Relationships are queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_term_relationships_rows.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_term_relationships_rows( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$term_relationships_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMRELATIONSHIPS ], 'object_id', $post_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_term_relationships_rows( $return_value_maps, $term_relationships_rows, $live_table_prefix, $post_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		                ->method( 'get_results' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$term_relationships_rows_actual = $this->logic->select_term_relationships_rows( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $term_relationships_rows, $term_relationships_rows_actual );
	}

	/**
	 * Tests that Terms Taxonomies are queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_term_taxonomy_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_term_taxonomy_rows( $data ) {
		$live_table_prefix = 'live_wp_';
		$term_taxonomy_1_id = 1;
		$term_taxonomy_1_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_taxonomy_id', 1 );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_term_taxonomy_row( $return_value_maps, $term_taxonomy_1_row, $live_table_prefix );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$term_taxonomy_row_actual = $this->logic->select_term_taxonomy_row( $live_table_prefix, $term_taxonomy_1_id );

		// Assert.
		$this->assertEquals( $term_taxonomy_1_row, $term_taxonomy_row_actual );
	}

	/**
	 * Tests that a Term is queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_terms_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_terms_rows( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$term_1_id = 41;
		$term_1_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_1_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_term_row( $return_value_maps, $term_1_row, $live_table_prefix );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$term_row_actual = $this->logic->select_terms_row( $live_table_prefix, $term_1_id );

		// Assert.
		$this->assertEquals( $term_1_row, $term_row_actual );
	}

	/**
	 * Tests that Term Metas are queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_termmeta_rows.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_termmeta_rows( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		// Test data for Term 1 has some meta.
		$term_1_id = 41;
		$termmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_1_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_termmeta_rows( $return_value_maps, $termmeta_rows, $live_table_prefix, $term_1_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$term_relationships_rows_actual = $this->logic->select_termmeta_rows( $live_table_prefix, $term_1_id );

		// Assert.
		$this->assertEquals( $termmeta_rows, $term_relationships_rows_actual );
	}

	/**
	 * Checks that ContentDiffMigrator::get_data queries the DB as expected, and returns a correctly formatted data array.
	 *
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_correctly_load_data_array( $data ) {
		// Prepare all the test data that's going to be queried by the ContentDiffMigrator::get_data method.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];
		$postmeta_rows = $data[ ContentDiffMigrator::DATAKEY_POSTMETA ];
		$post_author_id = 22;
		$author_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );
		$authormeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $post_author_id );
		$comments_rows = $data[ ContentDiffMigrator::DATAKEY_COMMENTS ];
		$comment_1_id = 11;
		$comment_2_id = 12;
		$comment_3_id = 13;
		// Test data for Comment 1 has some metas.
		$comment_1_commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_1_id );
		$comment_2_commentmeta_rows = [];
		$comment_3_commentmeta_rows = [];
		$comment_3_user_id = 23;
		// Test data for Comment 3 contains a new User.
		$comment_user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment_3_user_id );
		$comment_usermeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $comment_3_user_id );
		$term_relationships_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMRELATIONSHIPS ], 'object_id', $post_id );
		$term_taxonomy_1_id = 1;
		$term_taxonomy_2_id = 2;
		$term_taxonomy_1_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_taxonomy_id', $term_taxonomy_1_id );
		$term_taxonomy_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_taxonomy_id', $term_taxonomy_2_id );
		$term_1_id = 41;
		$term_2_id = 42;
		$term_1_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_1_id );
		$term_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_2_id );
		// Test data for Term 1 has some metas.
		$term_1_termmeta_rows = $data[ ContentDiffMigrator::DATAKEY_TERMMETA ];
		$term_2_termmeta_rows = [];

		// Mock full execution of ContentDiffMigrator::get_data().
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_post_row( $return_value_maps, $post_row, $live_table_prefix );
		$this->build_value_maps_select_postmeta_rows( $return_value_maps, $postmeta_rows, $live_table_prefix, $post_id );
		$this->build_value_maps_select_user_row( $return_value_maps, $author_row, $live_table_prefix );
		$this->build_value_maps_select_usermeta_rows( $return_value_maps, $authormeta_rows, $live_table_prefix, $post_author_id );
		$this->build_value_maps_select_comments_rows( $return_value_maps, $comments_rows, $live_table_prefix, $post_id );
		$this->build_value_maps_select_commentmeta_rows( $return_value_maps, $comment_1_commentmeta_rows, $live_table_prefix, $comment_1_id );
		$this->build_value_maps_select_commentmeta_rows( $return_value_maps, $comment_2_commentmeta_rows, $live_table_prefix, $comment_2_id );
		$this->build_value_maps_select_commentmeta_rows( $return_value_maps, $comment_3_commentmeta_rows, $live_table_prefix, $comment_3_id );
		$this->build_value_maps_select_user_row( $return_value_maps, $comment_user_row, $live_table_prefix );
		$this->build_value_maps_select_usermeta_rows( $return_value_maps, $comment_usermeta_rows, $live_table_prefix, $comment_3_user_id );
		$this->build_value_maps_select_term_relationships_rows( $return_value_maps, $term_relationships_rows, $live_table_prefix, $post_id );
		$this->build_value_maps_select_term_taxonomy_row( $return_value_maps, $term_taxonomy_1_row, $live_table_prefix );
		$this->build_value_maps_select_term_taxonomy_row( $return_value_maps, $term_taxonomy_2_row, $live_table_prefix );
		$this->build_value_maps_select_term_row( $return_value_maps, $term_1_row, $live_table_prefix );
		$this->build_value_maps_select_term_row( $return_value_maps, $term_2_row, $live_table_prefix );
		$this->build_value_maps_select_termmeta_rows( $return_value_maps, $term_1_termmeta_rows, $live_table_prefix, $term_1_id );
		$this->build_value_maps_select_termmeta_rows( $return_value_maps, $term_2_termmeta_rows, $live_table_prefix, $term_2_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		                ->method( 'get_results' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$data_actual = $this->logic->get_data( $post_id, $live_table_prefix );

		// Assert.
		$this->assertEquals( $data, $data_actual );
	}

	/**
	 * Tests that a Post is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_post.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_post_row( $data ) {
		// Prepare.
		$new_post_id = 234;
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_post_row( $return_value_maps, $post_row, $new_post_id );
		$this->wpdb_mock->insert_id = $new_post_id;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$new_post_id_actual = $this->logic->insert_post( $post_row );

		// Assert.
		$this->assertEquals( $new_post_id, $new_post_id_actual );
	}

	/**
	 * Tests that a Post Meta rows are inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_postmeta.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_postmeta_rows( $data ) {
		// Prepare.
		$new_post_id = 333;
		$postmeta_rows = $data[ ContentDiffMigrator::DATAKEY_POSTMETA ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_postmeta_rows( $return_value_maps, $postmeta_rows, $new_post_id );
		$this->wpdb_mock->insert_id = 2;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$new_meta_ids_actual = $this->logic->insert_postmeta( $postmeta_rows, $new_post_id );

		// Assert.
		$new_meta_ids_expected = array_fill( 0, count( $postmeta_rows ), $this->wpdb_mock->insert_id );
		$this->assertEquals( $new_meta_ids_expected, $new_meta_ids_actual );
	}

	/**
	 * Tests that a User is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_user.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_user_row( $data ) {
		// Prepare.
		$new_user_id = 234;
		$old_user_id = 22;
		$user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $old_user_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_user_row( $return_value_maps, $user_row );
		$this->wpdb_mock->insert_id = $new_user_id;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$new_post_id_actual = $this->logic->insert_user( $user_row );

		// Assert.
		$this->assertEquals( $new_user_id, $new_post_id_actual );
	}

	/**
	 * Tests that ContentDiffMigrator::get_existing_user_or_insert_new returns an existing user if the user exists.
	 *
	 * @covers ContentDiffMigrator::get_existing_user_or_insert_new.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_get_existing_user_or_insert_new_should_return_existing_user( $data ) {
		// Prepare.
		$existing_user_id = 22;
		$existing_user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $existing_user_id );
		$existing_user = new WP_User();
		$existing_user->ID = $existing_user_id;

		// Mock.
		$get_user_by_return_value_map = [];
		$this->build_value_map( $get_user_by_return_value_map, [ 'user_login', $existing_user_row[ 'user_login' ] ], $existing_user );
		$logic_partial_mock = $this->getMockBuilder( ContentDiffMigrator::class )
	                           ->setConstructorArgs( [ $this->wpdb_mock ] )
	                           ->setMethods( [ 'get_user_by' ] )
	                           ->getMock();
		$logic_partial_mock->expects( $this->once() )
		                   ->method( 'get_user_by' )
		                   ->will( $this->returnValueMap( $get_user_by_return_value_map ) );

		// Run.
		$existing_user_id_actual = $logic_partial_mock->get_existing_user_or_insert_new( $existing_user_row, $data );

		// Assert.
		$this->assertEquals( $existing_user_id, $existing_user_id_actual );
	}

	/**
	 * Tests that ContentDiffMigrator::get_existing_user_or_insert_new creates a new user if the user doesn't exists.
	 *
	 * @covers ContentDiffMigrator::get_existing_user_or_insert_new.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_get_existing_user_or_insert_new_should_create_new_user( $data ) {
		// Prepare.
		$user_id = 22;
		$new_user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $user_id );
		$new_usermeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $user_id );
		$new_user_id = 33;

		// Mock.
		$wpdb_return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$get_user_by_return_value_map = [];
		$this->build_value_map( $get_user_by_return_value_map, [ 'user_login', $new_user_row[ 'user_login' ] ], false );
		$this->build_value_maps_insert_user_row( $wpdb_return_value_maps, $new_user_row );
		$this->build_value_maps_insert_usermeta_rows( $wpdb_return_value_maps, $new_usermeta_rows, $new_user_id );
		// Using partial mock to mock just one method.
		$logic_partial_mock = $this->getMockBuilder( ContentDiffMigrator::class )
	                           ->setConstructorArgs( [ $this->wpdb_mock ] )
	                           ->setMethods( [ 'get_user_by' ] )
	                           ->getMock();
		$logic_partial_mock->expects( $this->once() )
		                   ->method( 'get_user_by' )
		                   ->will( $this->returnValueMap( $get_user_by_return_value_map ) );
		$this->wpdb_mock->insert_id = $new_user_id;
		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $wpdb_return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$new_user_id_actual = $logic_partial_mock->get_existing_user_or_insert_new( $new_user_row, $data );

		// Assert.
		$this->assertEquals( $new_user_id, $new_user_id_actual );
	}

	/**
	 * Tests that ContentDiffMigrator::get_term_taxonomy_or_insert_new insert a new termtaxonomy record if it doesn't already exist.
	 *
	 * Large tests like this one show that the code should be decoupled even better, and that a separate class should have been be
	 * used here.
	 *
	 * @covers ContentDiffMigrator::get_term_taxonomy_or_insert_new.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_get_term_taxonomy_or_insert_new_should_insert_new_record( $data ) {
		// Prepare.
		$term_id = 41;
		$term_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_id );
		$term_id_new = 234;
		$term_name = $term_row[ 'name' ];
		$term_slug = $term_row[ 'slug' ];
		$term_taxonomy_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_id', $term_id );
		$term_taxonomy_id_new = 123;
		$taxonomy = $term_taxonomy_row[ 'taxonomy' ];

		// Mock.
		// Existance of record should be queried correctly.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_get_existing_term_taxonomy( $return_value_maps, $term_name, $term_slug, $taxonomy, null );
		$this->wpdb_mock->insert_id = 123;
		// New termtaxonomy record should get inserted.
		$this->build_value_maps_insert_term_taxonmy_row( $return_value_maps, $term_taxonomy_row, $term_id_new );
		$this->wpdb_mock->insert_id = $term_taxonomy_id_new;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_var' ] ) ) )
		                ->method( 'get_var' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_var' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$term_taxonomy_id_actual = $this->logic->get_term_taxonomy_or_insert_new( $term_name, $term_slug, $taxonomy, $term_taxonomy_row, $term_id );

		// Assert.
		$this->assertEquals( $term_taxonomy_id_new, $term_taxonomy_id_actual );
	}

	/**
	 * Tests that a User Meta rows are inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_usermeta.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_usermeta_rows( $data ) {
		// Prepare.
		$new_user_id = 333;
		$old_user_id = 22;
		$usermeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $old_user_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_postmeta_rows( $return_value_maps, $usermeta_rows, $new_user_id );
		$this->wpdb_mock->insert_id = 2;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$new_meta_ids_actual = $this->logic->insert_usermeta( $usermeta_rows, $new_user_id );

		// Assert.
		$new_meta_ids_expected = array_fill( 0, count( $usermeta_rows ), $this->wpdb_mock->insert_id );
		$this->assertEquals( $new_meta_ids_expected, $new_meta_ids_actual );
	}

	/**
	 * Tests that a User is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::update_post_author.
	 */
	public function test_should_update_post_author() {
		// Prepare.
		$post_id = 123;
		$new_author_id = 321;

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_update_post_author( $return_value_maps, $post_id, $new_author_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::update' ] ) ) )
		                ->method( 'update' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::update' ] ) );

		// Run.
		$updated_actual = $this->logic->update_post_author( $post_id, $new_author_id );

		// Assert.
		$this->assertEquals( 1, $updated_actual );
	}

	/**
	 * Tests that a Comment is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_comment.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_comment_row( $data ) {
		// Prepare.
		$old_comment_id = 11;
		$new_comment_id = 456;
		$comment_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $old_comment_id );
		$new_post_id = 234;
		$new_user_id = 345;

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_comment_row( $return_value_maps, $comment_row, $new_post_id, $new_user_id );
		$this->wpdb_mock->insert_id = $new_comment_id;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$new_comment_id_actual = $this->logic->insert_comment( $comment_row, $new_post_id, $new_user_id );

		// Assert.
		$this->assertEquals( $new_comment_id, $new_comment_id_actual );
	}

	/**
	 * Tests that Comment Metas are inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_commentmetas.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_commentmeta_row( $data ) {
		// Prepare.
		$old_comment_id = 11;
		$new_comment_id = 456;
		$commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $old_comment_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_commentmeta_rows( $return_value_maps, $commentmeta_rows, $new_comment_id );
		$this->wpdb_mock->insert_id = 2;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$commentmeta_ids_actual = $this->logic->insert_commentmetas( $commentmeta_rows, $new_comment_id );

		// Assert.
		$commentmeta_ids_expected = array_fill( 0, count( $commentmeta_rows ), $this->wpdb_mock->insert_id);
		$this->assertEquals( $commentmeta_ids_actual, $commentmeta_ids_expected );
	}

	/**
	 * Tests that a Term is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_term.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_term_row( $data ) {
		// Prepare.
		$term_id = 41;
		$term_id_new = 123;
		$term_row = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'comment_id', $term_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_term_row( $return_value_maps, $term_row );
		$this->wpdb_mock->insert_id = $term_id_new;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$term_id_new_actual = $this->logic->insert_term( $term_row );

		// Assert.
		$this->assertEquals( $term_id_new, $term_id_new_actual );
	}

	/**
	 * Tests that ContentDiffMigrator::get_existing_term_taxonomy performs correct calls to the $wpdb and returns existing records.
	 *
	 * @covers ContentDiffMigrator::get_existing_term_taxonomy.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_get_existing_term_taxonomy_should_query_and_return_correct_value( $data ) {
		// Prepare.
		$term_name = 'name';
		$term_slug = 'slug';
		$taxonomy = 'taxonomy';
		$term_taxonomy_id_expected = 123;

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_get_existing_term_taxonomy( $return_value_maps, $term_name, $term_slug, $taxonomy, $term_taxonomy_id_expected );
		$this->wpdb_mock->insert_id = $term_taxonomy_id_expected;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_var' ] ) ) )
		                ->method( 'get_var' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_var' ] ) );

		// Run.
		$term_taxonomy_id_actual = $this->logic->get_existing_term_taxonomy( $term_name, $term_slug, $taxonomy );

		// Assert.
		$this->assertEquals( $term_taxonomy_id_expected, $term_taxonomy_id_actual );
	}

	/**
	 * Tests that a Comment Parent is updated correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::update_comment_parent.
	 */
	public function test_should_update_comment_parent() {
		// Prepare.
		$comment_id = 11;
		$comment_parent_new = 432;

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_update_comment_parent( $return_value_maps, $comment_id, $comment_parent_new );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::update' ] ) ) )
		                ->method( 'update' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::update' ] ) );

		// Run.
		$updated_actual = $this->logic->update_comment_parent( $comment_id, $comment_parent_new );

		// Assert.
		$this->assertEquals( 1, $updated_actual );
	}

	/**
	 * Tests that a Term Taxonomy is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_term_taxonomy.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_term_taxonomy( $data ) {
		// Prepare.
		$term_id = 41;
		$term_taxonomy_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_id', $term_id );
		$term_id_new = 234;
		$term_taxonomy_id_expected = 123;

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_term_taxonmy_row( $return_value_maps, $term_taxonomy_row, $term_id_new );
		$this->wpdb_mock->insert_id = $term_taxonomy_id_expected;
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$term_taxonomy_id_new_actual = $this->logic->insert_term_taxonomy( $term_taxonomy_row, $term_id_new );

		// Assert.
		$this->assertEquals( $term_taxonomy_id_expected, $term_taxonomy_id_new_actual );
	}

	/**
	 * Tests that a Term Relationship is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_term_relationship.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_term_relationship( $data ) {
		// Prepare.
		$post_id = 123;
		$term_taxonomy_id = 234;

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_insert_term_relationship_row( $return_value_maps, $post_id, $term_taxonomy_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::insert' ] ) );

		// Run.
		$inserted = $this->logic->insert_term_relationship( $post_id, $term_taxonomy_id );

		// Assert.
		$this->assertEquals( 1, $inserted );
	}

	/**
	 * Checks that ContentDiffMigrator::import_post_data queries the DB as expected, and inserts the data array correctly.
	 *
	 * Though a super long test, all its bits are also individually tested. This test is an extra effort to ensure the import_post_data
	 * will do the final DB updates correctly.
	 *
	 * @covers ContentDiffMigrator::import_post_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_correctly_import_post_data_array( $data ) {
		// Prepare all the test data that's going to be queried by the ContentDiffMigrator::get_data method.
		$last_insert_id = 300;
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];
		$post_id = $post_row[ 'ID' ];
		$postmeta_rows = $data[ ContentDiffMigrator::DATAKEY_POSTMETA ];
		$user_admin = new WP_User();
		$user_admin->ID = 50;
		$comment_1_id = 11;
		$comment_1_id_new = 31;
		$comment_1_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_1_id );
		$comment_1_commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_1_id );
		$comment_2_id = 12;
		$comment_2_id_new = 32;
		$comment_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_2_id );
		$comment_2_commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_2_id );
		$comment_3_id = 13;
		$comment_3_id_new = 33;
		$comment_3_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_3_id );
		$comment_3_commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_3_id );
		$user_test_user = new WP_User();
		$user_test_user->ID = 51;
		$term_1_id = 41;
		$term_1_id_existing = 61;
		$term_1_name = 'Uncategorized';
		$term_1_slug = 'uncategorized';
		$term_2_name = 'Custom Term';
		$term_2_slug = 'custom-term';
		$term_2_id = 42;
		$term_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_2_id );
		$term_2_taxonomy_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_id', $term_2_id );

		// Mock full execution of ContentDiffMigrator::import_post_data(). Using a partial mock to mock responses from wapper methods
		// for WP's native global functions.
		$get_user_by_return_value_map = [];
		$term_exists_return_value_map = [];
		$logic_partial_mock = $this->getMockBuilder( ContentDiffMigrator::class )
		                           ->setConstructorArgs( [ $this->wpdb_mock ] )
		                           ->setMethods( [ 'get_user_by', 'term_exists' ] )
		                           ->getMock();
		// Post's User 'admin' will be inserted as new user.
		$this->build_value_map( $get_user_by_return_value_map, [ 'user_login', 'admin' ], $user_admin );
		// Comment 2's User is the same existing User 'admin'.
		$this->build_value_map( $get_user_by_return_value_map, [ 'user_login', 'admin' ], $user_admin );
		// Comment 3's User doesn't exist, and will have to be inserted too.
		$this->build_value_map( $get_user_by_return_value_map, [ 'user_login', 'test_user' ], $user_test_user );
		$logic_partial_mock->expects( $this->exactly( count( $get_user_by_return_value_map ) ) )
		                   ->method( 'get_user_by' )
		                   ->will( $this->returnValueMap( $get_user_by_return_value_map ) );
		// Term 1 exists.
		$this->build_value_map( $term_exists_return_value_map, [ $term_1_name, '', null ], $term_1_id_existing );
		// Term 2 should be inserted as new.
		$this->build_value_map( $term_exists_return_value_map, [ $term_2_name, '', null ], null );
		$logic_partial_mock->expects( $this->exactly( count( $term_exists_return_value_map ) ) )
		                   ->method( 'term_exists' )
		                   ->will( $this->returnValueMap( $term_exists_return_value_map ) );
		// Expectations for all $wpdb calls.
		$wpdb_return_value_maps = $this->get_empty_wpdb_return_value_maps();
		// Should insert Post Meta.
		$this->build_value_maps_insert_postmeta_rows( $wpdb_return_value_maps, $postmeta_rows, $last_insert_id );
		// Post gets updated with newly inserted user ID.
		$this->build_value_maps_update_post_author( $wpdb_return_value_maps, $post_id, $user_admin->ID );
		// Comment 1 with no user.
		$this->build_value_maps_insert_comment_row( $wpdb_return_value_maps, $comment_1_row, $last_insert_id, 0 );
		$this->build_value_maps_insert_commentmeta_rows( $wpdb_return_value_maps, $comment_1_commentmeta_rows, $comment_1_id_new );
		// Comment 2 with existing 'admin' User.
		$this->build_value_maps_insert_comment_row( $wpdb_return_value_maps, $comment_2_row, $last_insert_id, $user_admin->ID );
		$this->build_value_maps_insert_commentmeta_rows( $wpdb_return_value_maps, $comment_2_commentmeta_rows, $comment_2_id_new );
		// Comment 3 with new 'test_user' User.
		$this->build_value_maps_insert_comment_row( $wpdb_return_value_maps, $comment_3_row, $last_insert_id, $user_test_user->ID );
		$this->build_value_maps_insert_commentmeta_rows( $wpdb_return_value_maps, $comment_3_commentmeta_rows, $comment_3_id_new );
		// Comment 3 has a parent which should get updated.
		$this->build_value_maps_update_comment_parent( $wpdb_return_value_maps, $comment_3_id, $last_insert_id );
		// Term 2 is new and should be inserted.
		$this->build_value_maps_insert_term_row( $wpdb_return_value_maps, $term_2_row );
		// term_taxonomy_id = 1 relationship will already exist.
		$term_1_taxonomy_id = 1;
		$this->build_value_maps_get_existing_term_taxonomy( $wpdb_return_value_maps, $term_1_name, $term_1_slug, 'category', $term_1_taxonomy_id );
		// term_taxonomy_id = 2 should be inserted as new.
		$this->build_value_maps_get_existing_term_taxonomy( $wpdb_return_value_maps, $term_2_name, $term_2_slug, 'category', null );
		$this->build_value_maps_insert_term_taxonmy_row( $wpdb_return_value_maps, $term_2_taxonomy_row, 222 );
		// term_relationship 1 and 2 inserts.
		$this->build_value_maps_insert_term_relationship_row( $wpdb_return_value_maps, $post_id, $last_insert_id );
		$this->build_value_maps_insert_term_relationship_row( $wpdb_return_value_maps, $post_id, $last_insert_id );
		// Expectations.
		$this->wpdb_mock->insert_id = $last_insert_id;
		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_return_value_maps[ 'wpdb::update' ] ) ) )
		                ->method( 'update' )
		                ->will( $this->returnValueMap( $wpdb_return_value_maps[ 'wpdb::update' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_return_value_maps[ 'wpdb::insert' ] ) ) )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( $wpdb_return_value_maps[ 'wpdb::insert' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $wpdb_return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $wpdb_return_value_maps[ 'wpdb::get_var' ] ) ) )
		                ->method( 'get_var' )
		                ->will( $this->returnValueMap( $wpdb_return_value_maps[ 'wpdb::get_var' ] ) );

		// Run.
		$inserted_post_id = $last_insert_id;
		$post_id_new_actual = $logic_partial_mock->import_post_data( $inserted_post_id, $data );

		// Assert.
		$this->assertEquals( $last_insert_id, $post_id_new_actual );
	}

	// TODO test error returns from select/insert methods.

	/**
	 * Creates a blank array which will contain value map subarrays as defined by \PHPUnit\Framework\TestCase::returnValueMap
	 * used by mock expectation in \PHPUnit\Framework\MockObject\Builder\InvocationMocker::will to mock calls to $wpdb.
	 *
	 * @return array[]
	 */
	private function get_empty_wpdb_return_value_maps() {
		return [
			'wpdb::prepare' => [],
			'wpdb::get_row' => [],
			'wpdb::get_results' => [],
			'wpdb::insert' => [],
			'wpdb::update' => [],
			'wpdb::get_var' => [],
		];
	}

	/**
	 * Builds a simple return value as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $map       Array containing the return value map.
	 * @param array $arguments Method arguments.
	 * @param mixed $return    Return value.
	 */
	private function build_value_map( &$map, $arguments, $return ) {
		$map[] = array_merge( $arguments, [ $return ] );
	}

	/**
	 * Builds return value maps for posts table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps         Array containing return value maps.
	 * @param array  $post_row     Post row.
	 * @param string $table_prefix Table prefix.
	 */
	private function build_value_maps_select_post_row( &$maps, $post_row, $table_prefix ) {
		$post_id = $post_row[ 'ID' ];
		$sql_prepare = "SELECT * FROM {$table_prefix}posts WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $post_row ];
	}

	/**
	 * Builds return value maps for postmeta table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps          Array containing return value maps.
	 * @param array  $postmeta_rows Post Meta rows.
	 * @param string $table_prefix  Table prefix.
	 * @param int    $post_id       Post ID.
	 */
	private function build_value_maps_select_postmeta_rows( &$maps, $postmeta_rows, $table_prefix, $post_id ) {
		$sql_prepare = "SELECT * FROM {$table_prefix}postmeta WHERE post_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $postmeta_rows ];
	}

	/**
	 * Builds return value maps for comments table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps          Array containing return value maps.
	 * @param array  $comments_rows Comments rows.
	 * @param string $table_prefix  Table prefix.
	 * @param int    $post_id       Post ID.
	 */
	private function build_value_maps_select_comments_rows( &$maps, $comments_rows, $table_prefix, $post_id ) {
		$sql_prepare = "SELECT * FROM {$table_prefix}comments WHERE comment_post_ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $comments_rows ];
	}

	/**
	 * Builds return value maps for comments table select query as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps         Array containing return value maps.
	 * @param array  $comment_row  Comment row.
	 * @param string $table_prefix Table prefix.
	 * @param int    $comment_ID   Comment ID.
	 */
	private function build_value_maps_select_comment_row( &$maps, $comment_row, $table_prefix, $comment_ID ) {
		$sql_prepare = "SELECT * FROM {$table_prefix}comments WHERE comment_ID = %s";
		$sql = sprintf( $sql_prepare, $comment_ID );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $comment_ID ], $sql ];
		$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $comment_row ];
	}

	/**
	 * Builds return value maps for commentmeta table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps             Array containing return value maps.
	 * @param array  $commentmeta_rows Comment Meta rows.
	 * @param string $table_prefix     Table prefix.
	 * @param int    $comment_id       Commend ID.
	 */
	private function build_value_maps_select_commentmeta_rows( &$maps, $commentmeta_rows, $table_prefix, $comment_id ) {
		$sql_prepare = "SELECT * FROM {$table_prefix}commentmeta WHERE comment_id = %s";
		$sql = sprintf( $sql_prepare, $comment_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $comment_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $commentmeta_rows ];
	}

	/**
	 * Builds return value maps for user table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps         Array containing return value maps.
	 * @param array  $user_row     User row.
	 * @param string $table_prefix Table prefix.
	 */
	private function build_value_maps_select_user_row( &$maps, $user_row, $table_prefix ) {
		$user_id = $user_row[ 'ID' ];
		$sql_prepare = "SELECT * FROM {$table_prefix}users WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $user_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $user_id ], $sql ];
		$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $user_row ];
	}

	/**
	 * Builds return value maps for usermeta table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps         Array containing return value maps.
	 * @param array  $usermeta_rows
	 * @param string $table_prefix Table prefix.
	 * @param int    $user_id
	 */
	private function build_value_maps_select_usermeta_rows( &$maps, $usermeta_rows, $table_prefix, $user_id ) {
		$sql_prepare = "SELECT * FROM {$table_prefix}usermeta WHERE user_id = %s";
		$sql = sprintf( $sql_prepare, $user_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $user_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $usermeta_rows ];
	}

	/**
	 * Builds return value maps for term_relationships table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps                    Array containing return value maps.
	 * @param array  $term_relationships_rows Term Relationships rows.
	 * @param string $table_prefix            Table prefix.
	 * @param int    $post_id                 Post ID.
	 */
	private function build_value_maps_select_term_relationships_rows( &$maps, $term_relationships_rows, $table_prefix, $post_id ) {
		$sql_prepare = "SELECT * FROM {$table_prefix}term_relationships WHERE object_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $term_relationships_rows ];
	}

	/**
	 * Builds return value maps for term_taxonomy table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps               Array containing return value maps.
	 * @param array  $term_taxonomy_rows Term Taxonomy rows.
	 * @param string $table_prefix       Table prefix.
	 */
	private function build_value_maps_select_term_taxonomy_row( &$maps, $term_taxonomy_row, $table_prefix ) {
		$term_taxonomy_id          = $term_taxonomy_row[ 'term_taxonomy_id' ];
		$sql_prepare               = "SELECT * FROM {$table_prefix}term_taxonomy WHERE term_taxonomy_id = %s";
		$sql                       = sprintf( $sql_prepare, $term_taxonomy_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $term_taxonomy_id ], $sql ];
		$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $term_taxonomy_row ];
	}

	/**
	 * Builds return value maps for terms table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps         Array containing return value maps.
	 * @param array  $terms_rows   Terms rows.
	 * @param string $table_prefix Table prefix.
	 */
	private function build_value_maps_select_term_row( &$maps, $term_row, $table_prefix ) {
		$term_id = $term_row[ 'term_id' ];
		$sql_prepare = "SELECT * FROM {$table_prefix}terms WHERE term_id = %s";
		$sql = sprintf( $sql_prepare, $term_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $term_id ], $sql ];
		$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $term_row ];
	}

	/**
	 * Builds return value maps for termmeta table select queries as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps          Array containing return value maps.
	 * @param array  $termmeta_rows Term Meta rows.
	 * @param string $table_prefix  Table prefix.
	 * @param int    $term_id       Term ID.
	 */
	private function build_value_maps_select_termmeta_rows( &$maps, $termmeta_rows, $table_prefix, $term_id ) {
		$sql_prepare = "SELECT * FROM {$table_prefix}termmeta WHERE term_id = %s";
		$sql = sprintf( $sql_prepare, $term_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $term_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $termmeta_rows ];
	}

	/**
	 * Builds return value maps for insert into posts table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps        Array containing return value maps.
	 * @param array  $post_row    Post row.
	 * @param int    $new_post_id Newly inserted Post ID.
	 */
	private function build_value_maps_insert_post_row( &$maps, $post_row, $new_post_id ) {
		unset( $post_row[ 'ID' ] );
		$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'posts', $post_row, 1 ];
	}

	/**
	 * Builds return value maps for insert into posts table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps          Array containing return value maps.
	 * @param array  $postmeta_rows Post meta rows.
	 * @param int    $new_post_id   Post ID to which this meta will be assigned to.
	 */
	private function build_value_maps_insert_postmeta_rows( &$maps, $postmeta_rows, $new_post_id ) {
		foreach ( $postmeta_rows as $key_postmeta_row => $postmeta_row ) {
			unset( $postmeta_row[ 'post_id' ] );
			$postmeta_row[ 'post_id' ] = $new_post_id;
			$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'postmeta', $postmeta_rows, $key_postmeta_row + 1 ];
		}
	}

	/**
	 * Builds return value maps for insert into users table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps     Array containing return value maps.
	 * @param array $user_row User row.
	 */
	private function build_value_maps_insert_user_row( &$maps, $user_row ) {
		unset( $user_row[ 'ID' ] );
		$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'users', $user_row, 1 ];
	}

	/**
	 * Builds return value maps for insert into usermeta table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps          Array containing return value maps.
	 * @param array $usermeta_rows Post meta rows.
	 * @param int   $new_user_id   User ID to which this meta will be assigned to.
	 */
	private function build_value_maps_insert_usermeta_rows( &$maps, $usermeta_rows, $new_user_id ) {
		foreach ( $usermeta_rows as $key_usermeta_row => $usermeta_row ) {
			unset( $usermeta_row[ 'post_id' ] );
			$usermeta_row[ 'post_id' ] = $new_user_id;
			$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'postmeta', $usermeta_rows, $key_usermeta_row + 1 ];
		}
	}

	/**
	 * Builds return value maps for ContentDiffMigrator::update_post_author usage of $wpdb as defined by
	 * \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps          Array containing return value maps.
	 * @param int   $post_id       Post ID.
	 * @param int   $new_author_id New Author ID.
	 */
	private function build_value_maps_update_post_author( &$maps, $post_id, $new_author_id ) {
		$maps[ 'wpdb::update' ][] = [ $this->wpdb_mock->table_prefix . 'posts', [ 'post_author' => $new_author_id ], [ 'ID' => $post_id ], 1 ];
	}

	/**
	 * Builds return value maps for insert into comments table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps        Array containing return value maps.
	 * @param array $user_row    User row.
	 * @param int   $new_user_id Newly inserted user ID.
	 */
	private function build_value_maps_insert_comment_row( &$maps, $comment_row, $new_post_id, $new_user_id ) {
		unset( $comment_row[ 'comment_id' ] );
		$comment_row[ 'comment_post_id' ] = $new_post_id;
		$comment_row[ 'user_id' ] = $new_user_id;
		$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'comments', $comment_row, 1 ];
	}

	/**
	 * Builds return value maps for inserts into commentmeta table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps             Array containing return value maps.
	 * @param array $commentmeta_rows Comment Meta rows.
	 * @param int   $new_comment_id   Newly Comment ID.
	 */
	private function build_value_maps_insert_commentmeta_rows( &$maps, $commentmeta_rows, $new_comment_id ) {
		foreach ( $commentmeta_rows as $commentmeta_row ) {
			unset( $commentmeta_row[ 'meta_id' ] );
			$commentmeta_row[ 'comment_id' ] = $new_comment_id ;
			$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'commentmeta', $commentmeta_row, 1 ];
		}
	}

	/**
	 * Builds return value maps for inserts into terms table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps     Array containing return value maps.
	 * @param array $term_row Term rows.
	 */
	private function build_value_maps_insert_term_row( &$maps, $term_row ) {
		unset( $term_row[ 'term_id' ] );
		$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'terms', $term_row, 1 ];
	}

	/**
	 * Builds return value maps for ContentDiffMigrator::update_comment_parent usage of $wpdb as defined by
	 * \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps               Array containing return value maps.
	 * @param int   $comment_id         Comment ID.
	 * @param int   $comment_parent_new New parent ID.
	 */
	private function build_value_maps_update_comment_parent( &$maps, $comment_id, $comment_parent_new ) {
		$maps[ 'wpdb::update' ][] = [ $this->wpdb_mock->table_prefix . 'comments', [ 'comment_parent' => $comment_parent_new ], [ 'comment_ID' => $comment_id ], 1 ];
	}

	/**
	 * Builds return value maps for ContentDiffMigrator::get_existing_term_taxonomy usage of $wpdb as defined by
	 * \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array  $maps                      Array containing return value maps.
	 * @param string $term_name                 Term name.
	 * @param string $term_slug                 Taxonomy slug.
	 * @param string $taxonomy                  Taxonomy name.
	 * @param int    $term_taxonomy_id_expected Expected return term_taxonomy_id value.
	 */
	private function build_value_maps_get_existing_term_taxonomy( &$maps, $term_name, $term_slug, $taxonomy, $term_taxonomy_id_expected ) {
		$sql_sprintf = "SELECT tt.term_taxonomy_id
			FROM {$this->wpdb_mock->term_taxonomy} tt
			JOIN {$this->wpdb_mock->terms} t
		        ON tt.term_id = t.term_id
			WHERE t.name = %s
			AND t.slug = %s
		    AND tt.taxonomy = %s;";
		$sql = sprintf( $sql_sprintf, $term_name, $term_slug, $taxonomy );
		$maps[ 'wpdb::prepare' ][] = [ $sql_sprintf, $term_name, $term_slug, $taxonomy, $sql ];
		$maps[ 'wpdb::get_var' ][] = [ $sql, $term_taxonomy_id_expected ];
	}

	/**
	 * Builds return value maps for inserts into termtaxonomy table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps              Array containing return value maps.
	 * @param array $term_taxonomy_row Term Taxonomy row.
	 * @param int   $term_id_new       New Term ID.
	 */
	private function build_value_maps_insert_term_taxonmy_row( &$maps, $term_taxonomy_row, $term_id_new ) {
		unset( $term_taxonomy_row[ 'term_taxonomy_id' ] );
		$term_taxonomy_row[ 'term_id' ] = $term_id_new;
		$maps[ 'wpdb::insert' ][] = [ $this->wpdb_mock->table_prefix . 'termtaxonomy', $term_taxonomy_row, 1 ];
	}

	/**
	 * Builds return value maps for inserts into term_relationships table as defined by \PHPUnit\Framework\TestCase::returnValueMap.
	 *
	 * @param array $maps             Array containing return value maps.
	 * @param array $object_id        Object ID.
	 * @param int   $term_taxonomy_id Term Taxonomy ID.
	 */
	private function build_value_maps_insert_term_relationship_row( &$maps, $object_id, $term_taxonomy_id ) {
		$maps[ 'wpdb::insert' ][] = [
			$this->wpdb_mock->table_prefix . 'term_relationships',
			[ 'object_id' => $object_id, 'term_taxonomy_id' => $term_taxonomy_id, ],
			1
		];
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
						// Comment 1 has no user, and has some meta.
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
						// Comment 2 has existing user.
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
						// Comment 3 has new user.
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
						// Post Author User, also used by Comment 2.
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
							'name' => 'Custom Term',
							'slug' => 'custom-term',
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
							'meta_id' => 2,
							'term_id' => 41,
							'meta_key' => '_some_other_numbermeta',
							'meta_value' => '71'
						],
					],
				]
			]
		];
	}
}
