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
	 * Override setUp.
	 */
	public function setUp() {
		parent::setUp();

		// Setting multiple expectations for method 'get_row' on mock of 'wpdb' class doesn't work for some reason, so here using
		// the Mock Builder for 'stdClass' instead.
		$this->wpdb_mock = $this->getMockBuilder( 'stdClass' )
		                        ->setMethods( [ 'prepare', 'get_row', 'get_results' ] )
		                        ->getMock();
		$this->logic = new ContentDiffMigrator( $this->wpdb_mock );
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
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_row( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$post_row_sample = $data_sample[ ContentDiffMigrator::DATAKEY_POST ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_post( $return_value_maps, $post_row_sample, $live_table_prefix, $post_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$post_row_actual = $this->logic->select_post_row( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $data_sample[ ContentDiffMigrator::DATAKEY_POST ], $post_row_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_postmeta_rows( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$postmeta_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_POSTMETA ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_postmeta( $return_value_maps, $postmeta_rows_sample, $live_table_prefix, $post_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$postmeta_rows_actual = $this->logic->select_postmeta_rows( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $data_sample[ ContentDiffMigrator::DATAKEY_POSTMETA ], $postmeta_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_author_row( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_author_id = 22;
		$author_row_sample = $this->logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_user( $return_value_maps, $author_row_sample, $live_table_prefix, $post_author_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$author_row_actual = $this->logic->select_user_row( $live_table_prefix, $post_author_id );

		// Assert.
		$this->assertEquals( $author_row_sample, $author_row_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_post_author_usermeta_rows( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_author_id = 22;
		$authormeta_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $post_author_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_usermeta( $return_value_maps, $authormeta_rows_sample, $live_table_prefix, $post_author_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		                ->method( 'get_results' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$author_meta_rows_actual = $this->logic->select_usermeta_rows( $live_table_prefix, $post_author_id );

		// Test.
		$this->assertEquals( $authormeta_rows_sample, $author_meta_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_rows( $data_sample ) {
		// Prepare.
		$post_id = 123;
		$live_table_prefix = 'live_wp_';
		$comments_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTS ];

		// Mock
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_comments( $return_value_maps, $comments_rows_sample, $live_table_prefix, $post_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$comment_rows_actual = $this->logic->select_comment_rows( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTS ], $comment_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_commentmeta_rows( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		// Test data for Comment 1 has some metas.
		$comment_1_id = 11;
		$commentmeta_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_1_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_commentmeta( $return_value_maps, $commentmeta_rows_sample, $live_table_prefix, $comment_1_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$comment_rows_actual = $this->logic->select_commentmeta_rows( $live_table_prefix, $comment_1_id );

		// Assert.
		$this->assertEquals( $commentmeta_rows_sample, $comment_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_user_row( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$comment_3_user_id = 23;
		$user_row_sample = $this->logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment_3_user_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_user( $return_value_maps, $user_row_sample, $live_table_prefix, $comment_3_user_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$user_row_actual = $this->logic->select_user_row( $live_table_prefix, $comment_3_user_id );

		// Test.
		$this->assertEquals( $user_row_sample, $user_row_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_comment_usermeta_rows( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$comment_3_user_id = 23;
		$usermeta_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $comment_3_user_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_usermeta( $return_value_maps, $usermeta_rows_sample, $live_table_prefix, $comment_3_user_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$user_meta_rows_actual = $this->logic->select_usermeta_rows( $live_table_prefix, $comment_3_user_id );

		// Assert.
		$this->assertEquals( $usermeta_rows_sample, $user_meta_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_term_relationships_rows( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$term_relationships_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_TERMRELATIONSHIPS ], 'object_id', $post_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_term_relationships( $return_value_maps, $term_relationships_rows_sample, $live_table_prefix, $post_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		                ->method( 'get_results' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$term_relationships_rows_actual = $this->logic->select_term_relationships_rows( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $term_relationships_rows_sample, $term_relationships_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_term_taxonomy_rows( $data_sample ) {
		$live_table_prefix = 'live_wp_';
		$term_taxonomy_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_term_taxonomy( $return_value_maps, $term_taxonomy_rows_sample, $live_table_prefix );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$term_taxonomy_rows_actual = [];
		foreach ( $term_taxonomy_rows_sample as $term_taxonomy_row_sample ) {
			$term_taxonomy_id  = $term_taxonomy_row_sample[ 'term_taxonomy_id' ];
			$term_taxonomy_rows_actual[] = $this->logic->select_term_taxonomy_row( $live_table_prefix, $term_taxonomy_id );
		}

		$this->assertEquals( $term_taxonomy_rows_sample, $term_taxonomy_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_terms_rows( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$terms_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_TERMS ];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_terms( $return_value_maps, $terms_rows_sample, $live_table_prefix );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_row' ] ) ) )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_row' ] ) );

		// Run.
		$term_rows_actual = [];
		foreach ( $terms_rows_sample as $terms_row ) {
			$term_id = $terms_row['term_id'];
			$term_rows_actual[] = $this->logic->select_terms_row( $live_table_prefix, $term_id );
		}

		// Assert.
		$this->assertEquals( $terms_rows_sample, $term_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_termmeta_rows( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		// Test data for Term 1 has some meta.
		$term_1_id = 41;
		$termmeta_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_1_id );

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_termmeta( $return_value_maps, $termmeta_rows_sample, $live_table_prefix, $term_1_id );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::prepare' ] ) ) )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::prepare' ] ) );
		$this->wpdb_mock->expects( $this->exactly( count( $return_value_maps[ 'wpdb::get_results' ] ) ) )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( $return_value_maps[ 'wpdb::get_results' ] ) );

		// Run.
		$term_relationships_rows_actual = $this->logic->select_termmeta_rows( $live_table_prefix, $term_1_id );

		// Assert.
		$this->assertEquals( $termmeta_rows_sample, $term_relationships_rows_actual );
	}

	/**
	 * @covers ContentDiffMigrator::get_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_correctly_load_data_array( $data_sample ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$post_row_sample = $data_sample[ ContentDiffMigrator::DATAKEY_POST ];
		$postmeta_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_POSTMETA ];
		$post_author_id = 22;
		$author_row_sample = $this->logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );
		$authormeta_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $post_author_id );
		$comments_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTS ];
		// Test data for Comment 1 has some metas.
		$comment_1_id = 11;
		$comment_2_id = 12;
		$comment_3_id = 13;
		$comment_1_commentmeta_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_1_id );
		$comment_2_commentmeta_rows_sample = [];
		$comment_3_commentmeta_rows_sample = [];
		$comment_3_user_id = 23;
		$user_row_sample = $this->logic->filter_array_element( $data_sample[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment_3_user_id );
		$usermeta_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $comment_3_user_id );
		$term_relationships_rows_sample = $this->logic->filter_array_elements( $data_sample[ ContentDiffMigrator::DATAKEY_TERMRELATIONSHIPS ], 'object_id', $post_id );
		$term_taxonomy_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ];
		$terms_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_TERMS ];
		// Test data for Term 1 has some metas.
		$term_1_id = 41;
		$term_2_id = 42;
		$term_1_termmeta_rows_sample = $data_sample[ ContentDiffMigrator::DATAKEY_TERMMETA ];
		$term_2_termmeta_rows_sample = [];

		// Mock.
		$return_value_maps = $this->get_empty_wpdb_return_value_maps();
		$this->build_value_maps_select_post( $return_value_maps, $post_row_sample, $live_table_prefix, $post_id );
		$this->build_value_maps_select_postmeta( $return_value_maps, $postmeta_rows_sample, $live_table_prefix, $post_id );
		$this->build_value_maps_select_user( $return_value_maps, $author_row_sample, $live_table_prefix, $post_author_id );
		$this->build_value_maps_select_usermeta( $return_value_maps, $authormeta_rows_sample, $live_table_prefix, $post_author_id );
		$this->build_value_maps_select_comments( $return_value_maps, $comments_rows_sample, $live_table_prefix, $post_id );
		$this->build_value_maps_select_commentmeta( $return_value_maps, $comment_1_commentmeta_rows_sample, $live_table_prefix, $comment_1_id );
		$this->build_value_maps_select_commentmeta( $return_value_maps, $comment_2_commentmeta_rows_sample, $live_table_prefix, $comment_2_id );
		$this->build_value_maps_select_commentmeta( $return_value_maps, $comment_3_commentmeta_rows_sample, $live_table_prefix, $comment_3_id );
		$this->build_value_maps_select_user( $return_value_maps, $user_row_sample, $live_table_prefix, $comment_3_user_id );
		$this->build_value_maps_select_usermeta( $return_value_maps, $usermeta_rows_sample, $live_table_prefix, $comment_3_user_id );
		$this->build_value_maps_select_term_relationships( $return_value_maps, $term_relationships_rows_sample, $live_table_prefix, $post_id );
		$this->build_value_maps_select_term_taxonomy( $return_value_maps, $term_taxonomy_rows_sample, $live_table_prefix );
		$this->build_value_maps_select_terms( $return_value_maps, $terms_rows_sample, $live_table_prefix );
		$this->build_value_maps_select_termmeta( $return_value_maps, $term_1_termmeta_rows_sample, $live_table_prefix, $term_1_id );
		$this->build_value_maps_select_termmeta( $return_value_maps, $term_2_termmeta_rows_sample, $live_table_prefix, $term_2_id );
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
		$this->assertEquals( $data_sample, $data_actual );
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

	private function get_empty_wpdb_return_value_maps() {
		return [
			'wpdb::prepare' => [],
			'wpdb::get_row' => [],
			'wpdb::get_results' => [],
		];
	}

	private function build_value_maps_select_post( &$maps, $post_row_sample, $live_table_prefix, $post_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}posts WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $post_row_sample ];
	}

	private function build_value_maps_select_postmeta( &$maps, $postmeta_rows_sample, $live_table_prefix, $post_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}postmeta WHERE post_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $postmeta_rows_sample ];
	}

	private function build_value_maps_select_comments( &$maps, $comments_rows_sample, $live_table_prefix, $post_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}comments WHERE comment_post_ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $comments_rows_sample ];
	}

	private function build_value_maps_select_commentmeta( &$maps, $commentmeta_rows_sample, $live_table_prefix, $comment_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}commentmeta WHERE comment_id = %s";
		$sql = sprintf( $sql_prepare, $comment_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $comment_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $commentmeta_rows_sample ];
	}

	private function build_value_maps_select_user( &$maps, $user_row_sample, $live_table_prefix, $user_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}users WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $user_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $user_id ], $sql ];
		$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $user_row_sample ];
	}

	private function build_value_maps_select_usermeta( &$maps, $usermeta_rows_sample, $live_table_prefix, $user_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}usermeta WHERE user_id = %s";
		$sql = sprintf( $sql_prepare, $user_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $user_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $usermeta_rows_sample ];
	}

	private function build_value_maps_select_term_relationships( &$maps, $term_relationships_rows_sample, $live_table_prefix, $post_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}term_relationships WHERE object_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $post_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $term_relationships_rows_sample ];
	}

	private function build_value_maps_select_term_taxonomy( &$value_maps, $term_taxonomy_rows_sample, $live_table_prefix ) {
		foreach ( $term_taxonomy_rows_sample as $term_taxonomy_row_sample ) {
			$term_taxonomy_id  = $term_taxonomy_row_sample[ 'term_taxonomy_id' ];
			$sql_prepare = "SELECT * FROM {$live_table_prefix}term_taxonomy WHERE term_taxonomy_id = %s";
			$sql = sprintf( $sql_prepare, $term_taxonomy_id );
			$value_maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $term_taxonomy_id ], $sql ];
			$value_maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $term_taxonomy_row_sample ];
		}
	}

	private function build_value_maps_select_terms( &$maps, $terms_rows_sample, $live_table_prefix ) {
		foreach ( $terms_rows_sample as $terms_row ) {
			$term_id = $terms_row[ 'term_id' ];
			$sql_prepare = "SELECT * FROM {$live_table_prefix}terms WHERE term_id = %s";
			$sql = sprintf( $sql_prepare, $term_id );
			$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $term_id ], $sql ];
			$maps[ 'wpdb::get_row' ][] = [ $sql, ARRAY_A, $terms_row ];
		}
	}

	private function build_value_maps_select_termmeta( &$maps, $termmeta_rows_sample, $live_table_prefix, $term_1_id ) {
		$sql_prepare = "SELECT * FROM {$live_table_prefix}termmeta WHERE term_id = %s";
		$sql = sprintf( $sql_prepare, $term_1_id );
		$maps[ 'wpdb::prepare' ][] = [ $sql_prepare, [ $term_1_id ], $sql ];
		$maps[ 'wpdb::get_results' ][] = [ $sql, ARRAY_A, $termmeta_rows_sample ];
	}
}
