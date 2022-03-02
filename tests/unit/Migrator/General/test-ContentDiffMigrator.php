<?php
/**
 * Test class for the \NewspackCustomContentMigrator\Migrator\General\ContentDiffMigrator.
 *
 * @package Newspack
 */

namespace NewspackCustomContentMigratorTest\Migrator\General;

use http\Exception\UnexpectedValueException;
use PHP_CodeSniffer\Tests\Core\Autoloader\Sub\C;
use WP_UnitTestCase;
use NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator;
use NewspackCustomContentMigratorTest\DataProviders\DataProviderGutenbergBlocks;
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
	 * @var DataProviderGutenbergBlocks.
	 */
	private $blocks_data_provider;

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
		$this->wpdb_mock->termmeta = $wpdb->prefix . 'termmeta';
		$this->wpdb_mock->term_taxonomy = $wpdb->prefix . 'term_taxonomy';
		$this->wpdb_mock->term_relationships = $wpdb->prefix . 'term_relationships';
		$this->logic = new ContentDiffMigrator( $this->wpdb_mock );
		$this->blocks_data_provider = new DataProviderGutenbergBlocks();
		$this->table_prefix = $wpdb->prefix;
	}

	/**
	 * Enables to set expectations on a partial mock object, so that the exact list of arguments and return values is checked in
	 * respect to the exact execution order (i.e. \PHPUnit\Framework\TestCase::at).
	 *
	 * This is a custom alternative which could otherwise have been obtained with `withConsecutive()` and `at()` but since these
	 * two methods will be deprecated in PHPUnit 10, here is a custom alternative to using those (perhaps PHPUnit 11 will fill
	 * the missing functionality gap).
	 *
	 * @param \PHPUnit\Framework\MockObject\MockBuilder $mock MockBuilder object.
	 * @param string $method Method mocked.
	 * @param array $return_value_map An array of function arguments and a return value.
	 *
	 * @return mixed
	 */
	public function mock_consecutive_value_maps( $mock, $method, $return_value_map ) {
		$at = 1;
		$total_calls = count( $return_value_map );
		$mock->expects( $this->exactly( $total_calls ) )
		     ->method( $method )
		     ->will( $this->returnCallback( function() use ( $return_value_map, &$at, $method ) {
			     $numargs = func_num_args();
			     $arg_list = func_get_args();
			     $this_return_value_map = $return_value_map[ $at - 1 ];
			     foreach ( $arg_list as $key_arg => $arg ) {
				     if ( $this_return_value_map[ $key_arg ] !== $arg ) {
					     throw new \UnexpectedValueException( sprintf(
						     'Unexpected argument number %d with value %s in method %s at execution %d.',
						     $key_arg + 1,
						     print_r( $arg, true ),
						     $method,
						     $at
					     ) );
				     }
			     }

			     $at++;
			     return $this_return_value_map[$numargs] ?? null;
		     } ) );

		return $mock;
	}

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
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];
		$post_id = $post_row[ 'ID' ];
		$sql_prepare = "SELECT * FROM {$live_table_prefix}posts WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( [
			                [ $sql_prepare, [ $post_id ], $sql ]
		                ] ) );
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( [
			                [ $sql, ARRAY_A, $post_row ]
		                ] ) );

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
		$sql_prepare = "SELECT * FROM {$live_table_prefix}postmeta WHERE post_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( [
			          [ $sql_prepare, [ $post_id ], $sql ]
		          ] ) );
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( [
			          [ $sql, ARRAY_A, $postmeta_rows ]
		          ] ) );

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
		$autor_id = $author_row[ 'ID' ];
		$sql_prepare = "SELECT * FROM {$live_table_prefix}users WHERE ID = %s";
		$sql = sprintf( $sql_prepare, $autor_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( [
			                [ $sql_prepare, [ $autor_id ], $sql ]
		                ] ) );
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( [
			                [ $sql, ARRAY_A, $author_row ]
		                ] ) );

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
		$sql_prepare = "SELECT * FROM {$live_table_prefix}usermeta WHERE user_id = %s";
		$sql = sprintf( $sql_prepare, $post_author_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( [
			                [ $sql_prepare, [ $post_author_id ], $sql ]
		                ] ) );
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'get_results' )
		                ->will( $this->returnValueMap( [
			                [ $sql, ARRAY_A, $authormeta_rows ]
		                ] ) );

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
		$sql_prepare = "SELECT * FROM {$live_table_prefix}comments WHERE comment_post_ID = %s";
		$sql = sprintf( $sql_prepare, $post_id );

		// Mock
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( [
			          [ $sql_prepare, [ $post_id ], $sql ]
		          ] ) );
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( [
			          [ $sql, ARRAY_A, $comments_rows ]
		          ] ) );

		// Run.
		$comment_rows_actual = $this->logic->select_comment_rows( $live_table_prefix, $post_id );

		// Assert.
		$this->assertEquals( $comments_rows, $comment_rows_actual );
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
		$sql_prepare = "SELECT * FROM {$live_table_prefix}commentmeta WHERE comment_id = %s";
		$sql = sprintf( $sql_prepare, $comment_1_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( [
			          [ $sql_prepare, [ $comment_1_id ], $sql ]
		          ] ) );
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( [
			          [ $sql, ARRAY_A, $commentmeta_rows ]
		          ] ) );

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
		$sql_prepare = "SELECT * FROM {$live_table_prefix}term_relationships WHERE object_id = %s";
		$sql = sprintf( $sql_prepare, $post_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( [
			                [ $sql_prepare, [ $post_id ], $sql ]
		                ] ) );
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'get_results' )
		                ->will( $this->returnValueMap( [
			                [ $sql, ARRAY_A, $term_relationships_rows ]
		                ] ) );

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
		$term_taxonomy_id = 1;
		$term_taxonomy_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_taxonomy_id', 1 );
		$sql_prepare               = "SELECT * FROM {$live_table_prefix}term_taxonomy WHERE term_taxonomy_id = %s";
		$sql                       = sprintf( $sql_prepare, $term_taxonomy_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( [
			                [ $sql_prepare, [ $term_taxonomy_id ], $sql ]
		                ] ) );
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'get_row' )
		                ->will( $this->returnValueMap( [
			                [ $sql, ARRAY_A, $term_taxonomy_row ]
		                ] ) );

		// Run.
		$term_taxonomy_row_actual = $this->logic->select_term_taxonomy_row( $live_table_prefix, $term_taxonomy_id );

		// Assert.
		$this->assertEquals( $term_taxonomy_row, $term_taxonomy_row_actual );
	}

	/**
	 * Tests that a Term is queried correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::select_term_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_select_terms_rows( $data ) {
		// Prepare.
		$live_table_prefix = 'live_wp_';
		$term_id = 41;
		$term_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_id );
		$sql_prepare = "SELECT * FROM {$live_table_prefix}terms WHERE term_id = %s";
		$sql = sprintf( $sql_prepare, $term_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( [
			          [ $sql_prepare, [ $term_id ], $sql ]
		          ] ) );
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'get_row' )
		          ->will( $this->returnValueMap( [
			          [ $sql, ARRAY_A, $term_row ]
		          ] ) );

		// Run.
		$term_row_actual = $this->logic->select_term_row( $live_table_prefix, $term_id );

		// Assert.
		$this->assertEquals( $term_row, $term_row_actual );
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
		$term_1_id = 41;
		$termmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_1_id );
		$sql_prepare = "SELECT * FROM {$live_table_prefix}termmeta WHERE term_id = %s";
		$sql = sprintf( $sql_prepare, $term_1_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'prepare' )
		          ->will( $this->returnValueMap( [
			          [ $sql_prepare, [ $term_1_id ], $sql ]
		          ] ) );
		$this->wpdb_mock->expects( $this->once() )
		          ->method( 'get_results' )
		          ->will( $this->returnValueMap( [
			          [ $sql, ARRAY_A, $termmeta_rows ]
		          ] ) );

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
		// Prepare test data.
		$live_table_prefix = 'live_wp_';
		$post_id = 123;
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];
		$postmeta_rows = $data[ ContentDiffMigrator::DATAKEY_POSTMETA ];
		$post_author_id = $post_row[ 'post_author' ];
		$post_author_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );
		$post_author_meta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $post_author_id );
		$comments_rows = $data[ ContentDiffMigrator::DATAKEY_COMMENTS ];
		$comment_1_id = 11;
		$comment_1_commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_1_id );
		$comment_2_id = 12;
		$comment_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_2_id );
		$comment_2_commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_2_id );
		$comment_2_user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment_2_row[ 'user_id' ] );
		$comment_2_user_meta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $comment_2_user_row[ 'ID' ] );
		$comment_3_id = 13;
		$comment_3_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_3_id );
		$comment_3_commentmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'comment_id', $comment_3_id );
		$comment_3_user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment_3_row[ 'user_id' ] );
		$comment_3_user_meta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $comment_3_user_row[ 'ID' ] );
		$term_relationships_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMRELATIONSHIPS ], 'object_id', $post_id );
		$term_taxonomy_rows = $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ];
		$term_1_id = 41;
		$term_1_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_1_id );
		$term_2_id = 42;
		$term_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_2_id );
		$term_2_termmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_2_id );
		$term_3_id = 70;
		$term_3_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_3_id );
		$term_3_termmeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_3_id );

		// Mock.
		$logic_partial_mock = $this->getMockBuilder( ContentDiffMigrator::class )
		                           ->setConstructorArgs( [ $this->wpdb_mock ] )
		                           ->setMethods( [
			                           'select_post_row',
			                           'select_postmeta_rows',
			                           'select_user_row',
			                           'select_usermeta_rows',
			                           'select_comment_rows',
			                           'select_commentmeta_rows',
			                           'select_term_relationships_rows',
			                           'select_term_taxonomy_row',
			                           'select_term_row',
			                           'select_termmeta_rows',
		                           ] )
		                           ->getMock();
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_post_row', [
			[ $live_table_prefix, $post_id, $post_row ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_postmeta_rows', [
			[ $live_table_prefix, $post_id, $postmeta_rows ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_user_row', [
			// Post Author.
			[ $live_table_prefix, $post_author_id, $post_author_row ],
			// Comment 2 User.
			[ $live_table_prefix, $comment_2_row[ 'user_id' ], $comment_2_user_row ],
			// Comment 3 User.
			[ $live_table_prefix, $comment_3_row[ 'user_id' ], $comment_3_user_row ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_usermeta_rows', [
			// Post Author.
			[ $live_table_prefix, $post_author_id, $post_author_meta_rows ],
			// Comment 2 User.
			[ $live_table_prefix, $comment_2_user_row[ 'ID' ], $comment_2_user_meta_rows ],
			// Comment 3 User.
			[ $live_table_prefix, $comment_3_user_row[ 'ID' ], $comment_3_user_meta_rows ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_comment_rows', [
			[ $live_table_prefix, $post_id, $comments_rows ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_commentmeta_rows', [
			[ $live_table_prefix, $comment_1_id, $comment_1_commentmeta_rows ],
			[ $live_table_prefix, $comment_2_id, $comment_2_commentmeta_rows ],
			[ $live_table_prefix, $comment_3_id, $comment_3_commentmeta_rows ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_term_relationships_rows', [
			[ $live_table_prefix, $post_id, $term_relationships_rows ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_term_taxonomy_row', [
			[ $live_table_prefix, $term_relationships_rows[0][ 'term_taxonomy_id' ], $term_taxonomy_rows[0] ],
			[ $live_table_prefix, $term_relationships_rows[1][ 'term_taxonomy_id' ], $term_taxonomy_rows[1] ],
			[ $live_table_prefix, $term_relationships_rows[2][ 'term_taxonomy_id' ], $term_taxonomy_rows[2] ],
			[ $live_table_prefix, $term_relationships_rows[3][ 'term_taxonomy_id' ], $term_taxonomy_rows[3] ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_term_row', [
			[ $live_table_prefix, $term_1_id, $term_1_row ],
			[ $live_table_prefix, $term_2_id, $term_2_row ],
			[ $live_table_prefix, $term_3_id, $term_3_row ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'select_termmeta_rows', [
			[ $live_table_prefix, $term_1_id, [] ],
			// Terms 2 and 3 will have some meta.
			[ $live_table_prefix, $term_2_id, $term_2_termmeta_rows ],
			[ $live_table_prefix, $term_3_id, $term_3_termmeta_rows ],
		] );

		// Run.
		$data_actual = $logic_partial_mock->get_data( $post_id, $live_table_prefix );

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
		$post_row_expected = $post_row;
		unset( $post_row_expected[ 'ID' ] );


		// Mock.
		$this->wpdb_mock->insert_id = $new_post_id;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'posts', $post_row_expected, 1 ]
		                ] ) );

		// Run.
		$new_post_id_actual = $this->logic->insert_post( $post_row );

		// Assert.
		$this->assertEquals( $new_post_id, $new_post_id_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_post fails.
	 *
	 * @covers ContentDiffMigrator::insert_post.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_post_should_throw_exception( $data ) {
		// Prepare.
		$new_post_id = 333;
		$id = 123;
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting post, ID %d, post row %s', $id, json_encode( $post_row ) ) );

		// Run.
		$this->logic->insert_post( $post_row );
	}

	/**
	 * Tests that a Post Meta rows are inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_postmeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_postmeta_row( $data ) {
		// Prepare.
		$new_post_id = 333;
		$meta_id = 22;
		$postmeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_POSTMETA ], 'meta_id', $meta_id );
		$meta_id_new = 54;
		$postmeta_row_expected = $postmeta_row;
		unset( $postmeta_row_expected[ 'meta_id' ] );
		$postmeta_row_expected[ 'post_id' ] = $new_post_id;

		// Mock.
		$this->wpdb_mock->insert_id = $meta_id_new;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'postmeta', $postmeta_row_expected, 1 ]
		                ] ) );

		// Run.
		$new_meta_ids_actual = $this->logic->insert_postmeta_row( $postmeta_row, $new_post_id );

		// Assert.
		$this->assertEquals( $meta_id_new, $new_meta_ids_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_postmeta_row fails.
	 *
	 * @covers ContentDiffMigrator::insert_postmeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_postmeta_row_should_throw_exception( $data ) {
		// Prepare.
		$new_post_id = 333;
		$meta_id = 22;
		$postmeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_POSTMETA ], 'meta_id', $meta_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error in insert_postmeta_row, post_id %s, postmeta_row %s', $new_post_id, json_encode( $postmeta_row ) ) );

		// Run.
		$this->logic->insert_postmeta_row( $postmeta_row, $new_post_id );
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
		$user_row_expected = $user_row;
		unset( $user_row_expected[ 'ID' ] );

		// Mock.
		$this->wpdb_mock->insert_id = $new_user_id;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'users', $user_row_expected, 1 ]
		                ] ) );

		// Run.
		$new_post_id_actual = $this->logic->insert_user( $user_row );

		// Assert.
		$this->assertEquals( $new_user_id, $new_post_id_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_user fails.
	 *
	 * @covers ContentDiffMigrator::insert_pinsert_userostmeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_user_should_throw_exception( $data ) {
		// Prepare.
		$old_user_id = 22;
		$user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $old_user_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting user, ID %d, user_row %s', $user_row[ 'ID' ], json_encode( $user_row ) ) );

		// Run.
		$this->logic->insert_user( $user_row );
	}

	/**
	 * Tests that a User Meta row is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_usermeta.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_usermeta_rows( $data ) {
		// Prepare.
		$new_user_id  = 333;
		$umeta_id     = 2;
		$new_umeta_id = 56;
		$usermeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'umeta_id', $umeta_id );
		$usermeta_row_expected = $usermeta_row;
		unset( $usermeta_row_expected[ 'umeta_id' ] );
		$usermeta_row_expected[ 'user_id' ] = $new_user_id;

		// Mock.
		$this->wpdb_mock->insert_id = $new_umeta_id;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'usermeta', $usermeta_row_expected, 1 ]
		                ] ) );

		// Run.
		$new_meta_ids_actual = $this->logic->insert_usermeta_row( $usermeta_row, $new_user_id );

		// Assert.
		$this->assertEquals( $new_umeta_id, $new_meta_ids_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_usermeta_row fails.
	 *
	 * @covers ContentDiffMigrator::insert_usermeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_usermeta_row_should_throw_exception( $data ) {
		// Prepare.
		$new_user_id  = 333;
		$umeta_id     = 2;
		$usermeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'umeta_id', $umeta_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting user meta, user_id %d, $usermeta_row %s', $new_user_id, json_encode( $usermeta_row ) ) );

		// Run.
		$this->logic->insert_usermeta_row( $usermeta_row, $new_user_id );
	}

	/**
	 * Tests that a User Meta row is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::update_post_parent.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_update_post_parent( $data ) {
		// Prepare.
		$post_id = 123;
		$post_parent = 145;
		$new_post_parent = 456;
		$imported_post_ids[ $post_parent ] = $new_post_parent;
		$post = new \stdClass();
		$post->ID = $post_id;
		$post->post_parent = $post_parent;

		// Mock.
		$logic_partial_mock = $this->getMockBuilder( ContentDiffMigrator::class )
		                           ->setConstructorArgs( [ $this->wpdb_mock ] )
		                           ->setMethods( [ 'get_post' ] )
		                           ->getMock();
		$logic_partial_mock->expects( $this->once() )
		                   ->method( 'get_post' )
						   ->with( $post_id )
		                   ->will( $this->returnValue( $post ) );
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'update' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'posts', [ 'post_parent' => $new_post_parent ], [ 'ID' => $post_id ] ]
		                ] ) );

		// Run.
		$logic_partial_mock->update_post_parent( $post_id, $imported_post_ids );
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
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'update' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'posts', [ 'post_author' => $new_author_id ], [ 'ID' => $post_id ], 1 ]
		                ] ) );

		// Run.
		$updated_actual = $this->logic->update_post_author( $post_id, $new_author_id );

		// Assert.
		$this->assertEquals( 1, $updated_actual );
	}

	/**
	 * Tests that a proper exception is thrown when update_post_author fails.
	 *
	 * @covers ContentDiffMigrator::update_post_author.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_update_post_author_should_throw_exception( $data ) {
		// Prepare.
		$post_id = 123;
		$new_author_id = 321;

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'update' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error updating post author, $post_id %d, $new_author_id %d', $post_id, $new_author_id ) );

		// Run.
		$this->logic->update_post_author( $post_id, $new_author_id );
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
		$comment_row_expected = $comment_row;
		unset( $comment_row_expected[ 'comment_ID' ] );
		$comment_row_expected[ 'comment_post_ID' ] = $new_post_id;
		$comment_row_expected[ 'user_id' ] = $new_user_id;

		// Mock.
		$this->wpdb_mock->insert_id = $new_comment_id;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'comments', $comment_row_expected, 1 ]
		                ] ) );

		// Run.
		$new_comment_id_actual = $this->logic->insert_comment( $comment_row, $new_post_id, $new_user_id );

		// Assert.
		$this->assertEquals( $new_comment_id, $new_comment_id_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_comment fails.
	 *
	 * @covers ContentDiffMigrator::insert_comment.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_comment_should_throw_exception( $data ) {
		// Prepare.
		$comment_id = 11;
		$comment_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_id );
		$new_post_id = 234;
		$new_user_id = 345;

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting comment, $new_post_id %d, $new_user_id %d, $comment_row %s', $new_post_id, $new_user_id, json_encode( $comment_row ) ) );

		// Run.
		$this->logic->insert_comment( $comment_row, $new_post_id, $new_user_id );
	}

	/**
	 * Tests that Comment Meta is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_commentmeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_commentmeta_row( $data ) {
		// Prepare.
		$meta_id        = 2;
		$new_comment_id = 456;
		$new_commentmeta_id = 456;
		$commentmeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'meta_id', $meta_id );
		$commentmeta_row_expected = $commentmeta_row;
		unset( $commentmeta_row_expected[ 'meta_id' ] );
		$commentmeta_row_expected[ 'comment_id' ] = $new_comment_id ;

		// Mock.
		$this->wpdb_mock->insert_id = $new_commentmeta_id;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'commentmeta', $commentmeta_row_expected, 1 ]
		                ] ) );

		// Run.
		$commentmeta_ids_actual = $this->logic->insert_commentmeta_row( $commentmeta_row, $new_comment_id );

		// Assert.
		$this->assertEquals( $commentmeta_ids_actual, $new_commentmeta_id );
	}

	/**
	 * Tests that a proper exception is thrown when insert_commentmeta_row fails.
	 *
	 * @covers ContentDiffMigrator::insert_commentmeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_commentmeta_row_should_throw_exception( $data ) {
		// Prepare.
		$meta_id        = 2;
		$new_comment_id = 456;
		$commentmeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTMETA ], 'meta_id', $meta_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting comment meta, $new_comment_id %d, $commentmeta_row %s', $new_comment_id, json_encode( $commentmeta_row ) ) );

		// Run.
		$this->logic->insert_commentmeta_row( $commentmeta_row, $new_comment_id );
	}

	/**
	 * Tests that a Term is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_term.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_term( $data ) {
		// Prepare.
		$term_id = 41;
		$term_id_new = 123;
		$term_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_id );
		$term_row_expected = $term_row;
		unset( $term_row_expected[ 'term_id' ] );

		// Mock.
		$this->wpdb_mock->insert_id = $term_id_new;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'terms', $term_row_expected, 1 ]
		                ] ) );

		// Run.
		$term_id_new_actual = $this->logic->insert_term( $term_row );

		// Assert.
		$this->assertEquals( $term_id_new, $term_id_new_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_term fails.
	 *
	 * @covers ContentDiffMigrator::insert_term.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_term_should_throw_exception( $data ) {
		// Prepare.
		$term_id = 41;
		$term_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting term, $term_row %s', json_encode( $term_row ) ) );

		// Run.
		$this->logic->insert_term( $term_row );
	}

	/**
	 * Tests that a Term Meta row is inserted correctly and that correct calls are made to the $wpdb.
	 *
	 * @covers ContentDiffMigrator::insert_termmeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_insert_termmeta_row( $data ) {
		// Prepare.
		$term_id = 42;
		$term_id_new = 123;
		$termmeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_id );
		$insert_id_expected = 543;
		$termmeta_row_expected = $termmeta_row;
		unset( $termmeta_row_expected[ 'meta_id' ] );
		$termmeta_row_expected[ 'term_id' ] = $term_id_new;

		// Mock.
		$this->wpdb_mock->insert_id = $insert_id_expected;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap([
			                [ $this->wpdb_mock->table_prefix . 'termmeta', $termmeta_row_expected, 1 ]
		                ] ) );

		// Run.
		$termmeta_id_actual = $this->logic->insert_termmeta_row( $termmeta_row, $term_id_new );

		// Assert.
		$this->assertEquals( $insert_id_expected, $termmeta_id_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_termmeta_row fails.
	 *
	 * @covers ContentDiffMigrator::insert_termmeta_row.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_termmeta_row_should_throw_exception( $data ) {
		// Prepare.
		$term_id = 42;
		$term_id_new = 123;
		$termmeta_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_id );

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting term meta, $term_id %d, $termmeta_row %s', $term_id_new, json_encode( $termmeta_row ) ) );

		// Run.
		$this->logic->insert_termmeta_row( $termmeta_row, $term_id_new );
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
		$term_id = 123;
		$taxonomy = 'taxonomy';
		$term_taxonomy_id_expected = 234;
		$sql_sprintf = "SELECT tt.term_taxonomy_id
			FROM {$this->wpdb_mock->term_taxonomy} tt
			WHERE tt.term_id = %d
			AND tt.taxonomy = %s;";
		$sql = sprintf( $sql_sprintf, $term_id, $taxonomy );

		// Mock.
		$this->wpdb_mock->insert_id = $term_taxonomy_id_expected;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'prepare' )
		                ->will( $this->returnValueMap( [
			                // [ $sql_sprintf, $term_name, $term_slug, $taxonomy, $sql ]
			                [ $sql_sprintf, $term_id, $taxonomy, $sql ]
		                ] ) );
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'get_var' )
		                ->will( $this->returnValueMap( [
			                [ $sql, $term_taxonomy_id_expected ]
		                ] ) );

		// Run.
		$term_taxonomy_id_actual = $this->logic->get_existing_term_taxonomy( $term_id, $taxonomy );

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
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'update' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'comments', [ 'comment_parent' => $comment_parent_new ], [ 'comment_ID' => $comment_id ], 1 ]
		                ] ) );

		// Run.
		$updated_actual = $this->logic->update_comment_parent( $comment_id, $comment_parent_new );

		// Assert.
		$this->assertEquals( 1, $updated_actual );
	}

	/**
	 * Tests that a proper exception is thrown when update_comment_parent fails.
	 *
	 * @covers ContentDiffMigrator::update_comment_parent.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_update_comment_parent_should_throw_exception( $data ) {
		// Prepare.
		$comment_id = 11;
		$comment_parent_new = 432;

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'update' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error updating comment parent, $comment_id %d, $comment_parent_new %d', $comment_id, $comment_parent_new ) );

		// Run.
		$this->logic->update_comment_parent( $comment_id, $comment_parent_new );
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
		$term_taxonomy_row_expected = $term_taxonomy_row;
		unset( $term_taxonomy_row_expected[ 'term_taxonomy_id' ] );
		$term_taxonomy_row_expected[ 'term_id' ] = $term_id_new;

		// Mock.
		$this->wpdb_mock->insert_id = $term_taxonomy_id_expected;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'term_taxonomy', $term_taxonomy_row_expected, 1 ]
		                ] ) );

		// Run.
		$term_taxonomy_id_new_actual = $this->logic->insert_term_taxonomy( $term_taxonomy_row, $term_id_new );

		// Assert.
		$this->assertEquals( $term_taxonomy_id_expected, $term_taxonomy_id_new_actual );
	}

	/**
	 * Tests that a proper exception is thrown when insert_term_taxonomy fails.
	 *
	 * @covers ContentDiffMigrator::insert_term_taxonomy.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_term_taxonomy_should_throw_exception( $data ) {
		// Prepare.
		$term_id = 41;
		$term_taxonomy_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_id', $term_id );
		$term_id_new = 234;

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting term_taxonomy, $new_term_id %d, term_taxonomy_id %s', $term_id_new, json_encode( $term_taxonomy_row ) ) );

		// Run.
		$this->logic->insert_term_taxonomy( $term_taxonomy_row, $term_id_new );
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
		$last_insert_id = 345;

		// Mock.
		$this->wpdb_mock->insert_id = $last_insert_id;
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValueMap( [
			                [ $this->wpdb_mock->table_prefix . 'term_relationships', [ 'object_id' => $post_id, 'term_taxonomy_id' => $term_taxonomy_id, ], 1 ]
		                ] ) );

		// Run.
		$inserted = $this->logic->insert_term_relationship( $post_id, $term_taxonomy_id );

		// Assert.
		$this->assertEquals( $last_insert_id, $inserted );
	}

	/**
	 * Tests that a proper exception is thrown when insert_term_relationship fails.
	 *
	 * @covers ContentDiffMigrator::insert_term_relationship.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_insert_term_relationship_should_throw_exception( $data ) {
		// Prepare.
		$post_id = 123;
		$term_taxonomy_id = 234;

		// Mock.
		$this->wpdb_mock->expects( $this->once() )
		                ->method( 'insert' )
		                ->will( $this->returnValue( false ) );

		// Expect.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( sprintf( 'Error inserting term relationship, $object_id %d, $term_taxonomy_id %d', $post_id, $term_taxonomy_id ) );

		// Run.
		$this->logic->insert_term_relationship( $post_id, $term_taxonomy_id );
	}

	/**
	 * Checks that ContentDiffMigrator::import_post_data runs all insert methods with all the appropriate arguments.
	 *
	 * @covers ContentDiffMigrator::import_post_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_should_correctly_import_post_data_array( $data ) {
		// Prepare all the test data that's going to be queried by the ContentDiffMigrator::get_data method.
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];
		$new_post_id = 500;
		$post_author_id = $post_row[ 'post_author' ];
		$post_author_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );
		$post_author_user_login = $post_author_row[ 'user_login' ];
		$post_author_usermeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $post_author_id );
		$new_post_author_id = 321;
		$user_admin = new WP_User();
		$user_admin->ID = 22;
		$comment_1_id = 11;
		$comment_1_id_new = 31;
		$comment_1_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_1_id );
		$comment_2_id = 12;
		$comment_2_id_new = 32;
		$comment_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_2_id );
		$comment_3_id = 13;
		$comment_3_id_new = 33;
		$comment_3_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_3_id );
		$comment1_user_id = 0;
		$comment2_user_id = $comment_2_row[ 'user_id' ];
		$comment3_user_id = $comment_3_row[ 'user_id' ];
		$comment3_user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment3_user_id );
		$comment3_user_login = $comment3_user_row[ 'user_login' ];
		$comment3_user_usermeta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_USERMETA ], 'user_id', $comment3_user_id );
		$new_comment3_user_id = 400;
		$term_1_id = 41;
		$term_1_name = 'Uncategorized';
		$term_1_slug = 'uncategorized';
		$term_2_name = 'Custom Term';
		$term_2_slug = 'custom-term';
		$term_2_id = 42;
		$term_2_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_2_id );
		$term_2_taxonomy_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ], 'term_id', $term_2_id );
		$term_2_meta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_2_id );
		$new_term_2_id = 62;
		$term_3_name = 'Blue';
		$term_3_slug = 'blue';
		$term_3_id = 70;
		$term_3_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_TERMS ], 'term_id', $term_3_id );
		$term_3_meta_rows = $this->logic->filter_array_elements( $data[ ContentDiffMigrator::DATAKEY_TERMMETA ], 'term_id', $term_3_id );
		$new_term_3_id = 100;
		$new_term_4_id = 100;
		$term_taxonomy_rows = $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ];
		$term_1_taxonomy_id = 1;
		$term_2_taxonomy_id = 2;
		$new_term_taxonomy_3_id = 521;
		$new_term_taxonomy_4_id = 522;

		$logic_partial_mock = $this->getMockBuilder( ContentDiffMigrator::class )
		                           ->setConstructorArgs( [ $this->wpdb_mock ] )
		                           ->setMethods( [
			                           'insert_postmeta_row',
			                           'insert_user',
			                           'insert_usermeta_row',
			                           'update_post_author',
		                           	   'get_user_by',
			                           'insert_comment',
			                           'insert_commentmeta_row',
			                           'update_comment_parent',
			                           'term_exists',
			                           'insert_term',
			                           'insert_termmeta_row',
			                           'get_existing_term_taxonomy',
			                           'insert_term_taxonomy',
			                           'insert_term_relationship',
		                           ] )
		                           ->getMock();
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_postmeta_row', [
			[ $data[ ContentDiffMigrator::DATAKEY_POSTMETA ][0], $new_post_id ],
			[ $data[ ContentDiffMigrator::DATAKEY_POSTMETA ][1], $new_post_id ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'get_user_by', [
			// First call is when trying to get the existing Post user, false will be returned because it is a new user.
			[ 'login', $post_author_user_login, false ],
			// Comment 1 has no user ('user_id' => 0), so no call is made to it.
			// Comment 2, existing $user_admin is returned.
			[ 'login', 'admin', $user_admin ],
			// Comment 3.
			[ 'login', $comment3_user_login, false ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_user', [
			// Inserting a new Post User.
			[ $post_author_row, $new_post_author_id ],
			// Inserting a new Comment3 User.
			[ $comment3_user_row, $new_comment3_user_id ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_usermeta_row', [
			// Inserting a new Post User.
			[ $post_author_usermeta_rows[0], $new_post_author_id ],
			[ $post_author_usermeta_rows[1], $new_post_author_id ],
			[ $post_author_usermeta_rows[2], $new_post_author_id ],
			// Inserting a new Comment3 User.
			[ $comment3_user_usermeta_rows[0], $new_comment3_user_id ],
			[ $comment3_user_usermeta_rows[1], $new_comment3_user_id ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'update_post_author', [
			[ $new_post_id, $new_post_author_id ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_comment', [
			[ $comment_1_row, $new_post_id, 0, $comment_1_id_new ],
			[ $comment_2_row, $new_post_id, $comment2_user_id, $comment_2_id_new ],
			[ $comment_3_row, $new_post_id, $new_comment3_user_id, $comment_3_id_new ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'update_comment_parent', [
			// Comment 1 has a parent, which is Comment 2.
			[ $comment_3_id_new, $comment_2_id_new ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'term_exists', [
			// Term 1 exists, $term_1_id is returned.
			[ $term_1_name, '', null, $term_1_id ],
			// Term 2 doesn't exist, null is returned.
			[ $term_2_name, '', null, null ],
			// Term 3 doesn't exist.
			[ $term_3_name, '', null, null ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_term', [
			// Term 2 gets inserted.
			[ $term_2_row, $new_term_2_id ],
			// Term 3 gets inserted.
			[ $term_3_row, $new_term_3_id ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_termmeta_row', [
			// Term 2 has some meta.
			[ $term_2_meta_rows[0], $new_term_2_id ],
			[ $term_2_meta_rows[1], $new_term_2_id ],
			// Term .
			[ $term_3_meta_rows[0], $new_term_3_id ],
			[ $term_3_meta_rows[1], $new_term_3_id ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'get_existing_term_taxonomy', [
			// Term 1 calls.
			[ $term_1_id, $term_taxonomy_rows[0][ 'taxonomy' ], 1 ],
			// Term 2 calls.
			[ $new_term_2_id, $term_taxonomy_rows[1][ 'taxonomy' ], 2 ],
			// Term 3 calls.
			[ $new_term_3_id, $term_taxonomy_rows[2][ 'taxonomy' ], null ],
			[ $new_term_4_id, $term_taxonomy_rows[3][ 'taxonomy' ], null ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_term_taxonomy', [
			// Term 3 calls.
			[ $term_taxonomy_rows[2], $new_term_3_id, $new_term_taxonomy_3_id ],
			[ $term_taxonomy_rows[3], $new_term_3_id, $new_term_taxonomy_4_id ],
		] );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'insert_term_relationship', [
			[ $new_post_id, $term_1_taxonomy_id ],
			[ $new_post_id, $term_2_taxonomy_id ],
			[ $new_post_id, $new_term_taxonomy_3_id ],
			[ $new_post_id, $new_term_taxonomy_4_id ],
		] );

		// Run.
		$import_errors = $logic_partial_mock->import_post_data( $new_post_id, $data );

		// Assert.
		$this->assertEquals( [], $import_errors );
	}

	/**
	 * Checks that ContentDiffMigrator::import_post_data captures all errors that occurred.
	 *
	 * @covers ContentDiffMigrator::import_post_data.
	 *
	 * @dataProvider db_data_provider
	 */
	public function test_import_post_data_should_capture_errors( $data ) {
		// Prepare all the test data that's going to be queried by the ContentDiffMigrator::get_data method.
		$post_row = $data[ ContentDiffMigrator::DATAKEY_POST ];
		$new_post_id = 500;
		$post_author_id = $post_row[ 'post_author' ];
		$post_author_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $post_author_id );
		$post_author_user_login = $post_author_row[ 'user_login' ];
		$user_admin = new WP_User();
		$user_admin->ID = 22;
		$comment_3_id = 13;
		$comment_3_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_COMMENTS ], 'comment_ID', $comment_3_id );
		$comment3_user_id = $comment_3_row[ 'user_id' ];
		$comment3_user_row = $this->logic->filter_array_element( $data[ ContentDiffMigrator::DATAKEY_USERS ], 'ID', $comment3_user_id );
		$comment3_user_login = $comment3_user_row[ 'user_login' ];
		$term_1_id = 41;
		$term_1_name = 'Uncategorized';
		$term_1_slug = 'uncategorized';
		$term_2_name = 'Custom Term';
		$term_2_slug = 'custom-term';
		$term_3_name = 'Blue';
		$term_3_slug = 'blue';
		$term_taxonomy_rows = $data[ ContentDiffMigrator::DATAKEY_TERMTAXONOMY ];

		// Mock.
		$logic_partial_mock = $this->getMockBuilder( ContentDiffMigrator::class )
		                           ->setConstructorArgs( [ $this->wpdb_mock ] )
		                           ->setMethods( [
			                           'insert_postmeta_row',
			                           'insert_user',
			                           'insert_usermeta_row',
			                           'update_post_author',
		                           	   'get_user_by',
			                           'insert_comment',
			                           'insert_commentmeta_row',
			                           'update_comment_parent',
			                           'term_exists',
			                           'insert_term',
			                           'insert_termmeta_row',
			                           'get_existing_term_taxonomy',
			                           'insert_term_taxonomy',
			                           'insert_term_relationship',
		                           ] )
		                           ->getMock();
		$logic_partial_mock->method( 'insert_postmeta_row' )
			->will( $this->throwException( new \RuntimeException( 'err insert_postmeta_row' ) ) );
		$logic_partial_mock->method( 'insert_postmeta_row' )
			->will( $this->throwException( new \RuntimeException( 'err insert_postmeta_row' ) ) );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'get_user_by', [
			// Trying to get the existing Post user, returns false because it is a new user.
			[ 'login', $post_author_user_login, false ],
			// Comment 1 has no user.
			// Comment 2, existing $user_admin is returned.
			[ 'login', 'admin', $user_admin ],
			// Comment 3.
			[ 'login', $comment3_user_login, false ],
		] );
		$logic_partial_mock->method( 'insert_user' )
		                   ->will( $this->throwException( new \RuntimeException( 'err insert_user' ) ) );
		$logic_partial_mock->method( 'update_post_author' )
		                   ->will( $this->throwException( new \RuntimeException( 'err update_post_author' ) ) );
		$logic_partial_mock->method( 'insert_comment' )
		                   ->will( $this->throwException( new \RuntimeException( 'err insert_comment' ) ) );
		$logic_partial_mock->method( 'update_comment_parent' )
		                   ->will( $this->throwException( new \RuntimeException( 'err update_comment_parent' ) ) );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'term_exists', [
			// Term 1 exists, $term_1_id is returned.
			[ $term_1_name, '', null, $term_1_id ],
			// Term 2 doesn't exist, null is returned.
			[ $term_2_name, '', null, null ],
			// Term 3 doesn't exist.
			[ $term_3_name, '', null, null ],
		] );
		$logic_partial_mock->method( 'insert_term' )
		                   ->will( $this->throwException( new \RuntimeException( 'err insert_term' ) ) );
		$this->mock_consecutive_value_maps( $logic_partial_mock, 'get_existing_term_taxonomy', [
			// Term 1 calls.
			[ $term_1_id, $term_taxonomy_rows[0][ 'taxonomy' ], 1 ],
			// Term 2 calls.
			[ 0, $term_taxonomy_rows[1][ 'taxonomy' ], 2 ],
			// Term 3 calls.
			[ 0, $term_taxonomy_rows[2][ 'taxonomy' ], null ],
			[ 0, $term_taxonomy_rows[3][ 'taxonomy' ], null ],
		] );
		$logic_partial_mock->method( 'insert_term_taxonomy' )
		                   ->will( $this->throwException( new \RuntimeException( 'err insert_term_taxonomy' ) ) );
		$logic_partial_mock->method( 'insert_term_relationship' )
		                   ->will( $this->throwException( new \RuntimeException( 'err insert_term_relationship' ) ) );

		// Run.
		$import_errors = $logic_partial_mock->import_post_data( $new_post_id, $data );

		// Assert.
		$expected_errors = [
			'err insert_postmeta_row',
			'err insert_postmeta_row',
			// Inserting Post User.
			'err insert_user',
// 'err update_post_author',
			// Comment 1 doesn't have a User.
			'err insert_comment',
			// Comment 2 doesn't has an existing User.
			'err insert_comment',
			// Comment 3 has a new User.
			'err insert_user',
			'err insert_comment',
			// Terms insertions.
			'err insert_term',
			'err insert_term',
			// Termtaxonomy rows.
			'err insert_term_taxonomy',
			'err insert_term_taxonomy',
			// Term relationships rows.
			'err insert_term_relationship',
			'err insert_term_relationship',
			// Inserts didn't happen here.
			'Error could not insert term_relationship because the new updated term_taxonomy_id is not found, $term_taxonomy_id_old 3',
			'Error could not insert term_relationship because the new updated term_taxonomy_id is not found, $term_taxonomy_id_old 4',
		];
		$this->assertEquals( $expected_errors, $import_errors );
	}

	/**
	 * Tests if update_image_element_class_attribute updates class attribute with new ID reference correctly, for various class values examples.
	 */
	public function test_update_image_element_class_attribute_should_update_class() {
		// Old IDs => new IDs.
		$imported_attachment_ids = [
			11111 => 11110,
		];

		$html = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="wp-image-11111"/>
HTML;
		$html_expected = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="wp-image-11110"/>
HTML;
		$html_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html );
		$this->assertEquals( $html_expected, $html_actual );

		$html = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="otherclass wp-image-11111"/>
HTML;
		$html_expected = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="otherclass wp-image-11110"/>
HTML;
		$html_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html );
		$this->assertEquals( $html_expected, $html_actual );

		$html = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="wp-image-11111 otherclass"/>
HTML;
		$html_expected = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="wp-image-11110 otherclass"/>
HTML;
		$html_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html );
		$this->assertEquals( $html_expected, $html_actual );

		$html = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="otherclassA wp-image-11111 otherclassB"/>
HTML;
		$html_expected = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="otherclassA wp-image-11110 otherclassB"/>
HTML;
		$html_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html );
		$this->assertEquals( $html_expected, $html_actual );
	}

	/**
	 * Tests if update_image_element_class_attribute updates data-id attribute with new ID reference correctly.
	 */
	public function test_update_image_element_class_attribute_should_update_data_id_attribute() {
		// Old IDs => new IDs.
		$imported_attachment_ids = [
			11111 => 11110,
		];
		$html = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11111" class="wp-image-11111"/>
HTML;
		$html_expected = <<<HTML
<img src="https://philomath.test/wp-content/uploads/2022/02/022822-haskell-sign.jpeg" alt="Haskell Indian Nations University entrance sign" data-id="11110" class="wp-image-11111"/>
HTML;

		$html_actual = $this->logic->update_image_element_data_id_attribute( $imported_attachment_ids, $html );

		$this->assertEquals( $html_expected, $html_actual );
	}

	/**
	 * Tests ID updates on an entire Gutenberg Gallery block, by updating block IDs in block header/definition, and by updating
	 * nested image blocks correctly.
	 */
	public function test_wp_gallery_block_ids_all_get_updated_correctly() {
		// Old IDs => new IDs.
		$imported_attachment_ids = [
			11111 => 11110,
			22222 => 22220,
			33333 => 33330,
		];
		$html = $this->blocks_data_provider->get_gutenberg_gallery_block( 11111, 22222, 33333 );
		$html_expected = $this->blocks_data_provider->get_gutenberg_gallery_block( 11110, 22220, 33330 );

		$html_actual = $html;
		$html_actual = $this->logic->update_gutenberg_blocks_single_id( $imported_attachment_ids, $html_actual );
		$html_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html_actual );
		$html_actual = $this->logic->update_image_element_data_id_attribute( $imported_attachment_ids, $html_actual );

		$this->assertEquals( $html_expected, $html_actual );
	}

	/**
	 * Tests that update_gutenberg_blocks_single_id updates correctly different blocks' IDs in headers.
	 */
	public function test_update_gutenberg_blocks_single_id_should_update_id() {
		// Old IDs => new IDs.
		$imported_attachment_ids = [
			11111 => 11110,
			33333 => 33330,
			44444 => 44440,
		];
		$html_with_placeholders = <<<HTML
<!-- wp:blocka {"id":%d,"sizeSlug":"large"} -->
some content
<!-- /wp:image -->

<!-- wp:blockb {"id":%d} -->
some other content
<!-- /wp:image -->

<!-- wp:blockc {"sizeSlug":"large","id":%d} -->
something else
<!-- /wp:image -->

<!-- wp:blockd {"id":%d} -->
there's always something... :)
<!-- /wp:image -->
HTML;

		$html = sprintf( $html_with_placeholders, 11111, 22222, 33333, 44444 );
		$html_expected = sprintf( $html_with_placeholders, 11110, 22222, 33330, 44440 );

		$html_actual = $this->logic->update_gutenberg_blocks_single_id( $imported_attachment_ids, $html );

		$this->assertEquals( $html_expected, $html_actual );
	}

	/**
	 * Tests if several different blocks which use CSV IDs in their headers get updated correctly.
	 */
	public function test_update_gutenberg_blocks_multiple_ids_should_update_multiple_csv_ids_in_block_definitions() {

		// Old IDs => new IDs.
		$imported_attachment_ids = [
			11111 => 11110,
			22222 => 22220,
			33333 => 33330,
		];

		// The first assertion is for Jetpack Slideshow block.
		$html_slideshow = $this->blocks_data_provider->get_jetpack_slideshow_block( 11111, 22222, 33333 )
			. "\n\n" . $this->blocks_data_provider->get_jetpack_slideshow_block( 1111111111, 2222222222, 3333333333 );
		$html_slideshow_expected = $this->blocks_data_provider->get_jetpack_slideshow_block( 11110, 22220, 33330 )
			. "\n\n" . $this->blocks_data_provider->get_jetpack_slideshow_block( 1111111111, 2222222222, 3333333333 );

		$html_slideshow_actual = $html_slideshow;
		$html_slideshow_actual = $this->logic->update_gutenberg_blocks_multiple_ids( $imported_attachment_ids, $html_slideshow_actual );
		$html_slideshow_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html_slideshow_actual );
		$html_slideshow_actual = $this->logic->update_image_element_data_id_attribute( $imported_attachment_ids, $html_slideshow_actual );

		$this->assertEquals( $html_slideshow_expected, $html_slideshow_actual );


		// The second assertion is for Jetpack Tiled Gallery block.
		$html_jp_tiled_gallery = $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 11111, 99999, 33333 )
		                         . "\n\n" . $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 1111111111, 2222222222, 3333333333 );
		$html_jp_tiled_gallery_expected = $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 11110, 99999, 33330 )
		                                  . "\n\n" . $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 1111111111, 2222222222, 3333333333 );

		$html_jp_tiled_gallery_actual = $html_jp_tiled_gallery;
		$html_jp_tiled_gallery_actual = $this->logic->update_gutenberg_blocks_multiple_ids( $imported_attachment_ids, $html_jp_tiled_gallery_actual );
		$html_jp_tiled_gallery_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html_jp_tiled_gallery_actual );
		$html_jp_tiled_gallery_actual = $this->logic->update_image_element_data_id_attribute( $imported_attachment_ids, $html_jp_tiled_gallery_actual );

		$this->assertEquals( $html_jp_tiled_gallery_expected, $html_jp_tiled_gallery_actual );
	}

	/**
	 * A more exhaustive search replace test, testing all the exact replacements which the update_blocks_ids method does.
	 *
	 * @covers \NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator::update_blocks_ids
	 */
	public function test_update_blocks_ids_should_update_all_ids_correctly() {

		// Old IDs => new IDs.
		$imported_attachment_ids = [
			11111 => 11110,
			22222 => 22220,
			33333 => 33330,
		];

		$html = $this->blocks_data_provider->get_gutenberg_gallery_block( 11111, 22222, 33333 )
			. "\n\n" . $this->blocks_data_provider->get_jetpack_slideshow_block( 11111, 22222, 33333 )
			. "\n\n" . $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 11111, 22222, 33333 )
			. "\n\n" . $this->blocks_data_provider->get_gutenberg_gallery_block( 1111111111, 2222222222, 3333333333 )
	        . "\n\n" . $this->blocks_data_provider->get_jetpack_slideshow_block( 1111111111, 2222222222, 3333333333 )
	        . "\n\n" . $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 1111111111, 2222222222, 3333333333 )
		;
		$html_expected = $this->blocks_data_provider->get_gutenberg_gallery_block( 11110, 22220, 33330 )
			. "\n\n" . $this->blocks_data_provider->get_jetpack_slideshow_block( 11110, 22220, 33330 )
			. "\n\n" . $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 11110, 22220, 33330 )
			. "\n\n" . $this->blocks_data_provider->get_gutenberg_gallery_block( 1111111111, 2222222222, 3333333333 )
	        . "\n\n" . $this->blocks_data_provider->get_jetpack_slideshow_block( 1111111111, 2222222222, 3333333333 )
	        . "\n\n" . $this->blocks_data_provider->get_jetpack_tiled_gallery_block( 1111111111, 2222222222, 3333333333 )
		;

		$html_actual = $html;
		// All the updates made in \NewspackCustomContentMigrator\MigrationLogic\ContentDiffMigrator::update_blocks_ids.
		$html_actual = $this->logic->update_gutenberg_blocks_single_id( $imported_attachment_ids, $html_actual );
		$html_actual = $this->logic->update_gutenberg_blocks_multiple_ids( $imported_attachment_ids, $html_actual );
		$html_actual = $this->logic->update_image_element_class_attribute( $imported_attachment_ids, $html_actual );
		$html_actual = $this->logic->update_image_element_data_id_attribute( $imported_attachment_ids, $html_actual );

		$this->assertEquals( $html_expected, $html_actual );
	}

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
						'post_author' => 21,
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
						'comment_count' => 3,
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
						// Post Author User.
						[
							'ID' => 21,
							'user_login' => 'postauthor',
							'user_pass' => '$P$BJTe8iBJUuOui0O.A4JDRkLMfqqraF.',
							'user_nicename' => 'postauthor',
							'user_email' => 'postauthor@local.test',
							'user_url' => 'http=>\/\/testing.test',
							'user_registered' => '2021-09-23T09=>43=>56.000Z',
							'user_activation_key' => '',
							'user_status' => 0,
							'display_name' => 'postauthor'
						],
						// Comment 2 User.
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
						// User Meta for Post Author.
						[
							'umeta_id' => 1,
							'user_id' => 21,
							'meta_key' => 'nickname',
							'meta_value' => 'newuser',
						],
						[
							'umeta_id' => 2,
							'user_id' => 21,
							'meta_key' => 'first_name',
							'meta_value' => 'New',
						],
						[
							'umeta_id' => 3,
							'user_id' => 21,
							'meta_key' => 'last_name',
							'meta_value' => 'User',
						],
						// User Meta for Comment 2 existing User.
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
						[
							'object_id' => 123,
							'term_taxonomy_id' => 3,
							'term_order' => 0
						],
						[
							'object_id' => 123,
							'term_taxonomy_id' => 4,
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
						],
						[
							'term_taxonomy_id' => 3,
							'term_id' => 70,
							'taxonomy' => 'color',
							'description' => 'Color',
							'parent' => 0,
							'count' => 0
						],
						[
							'term_taxonomy_id' => 4,
							'term_id' => 70,
							'taxonomy' => 'mood',
							'description' => 'Mood',
							'parent' => 0,
							'count' => 0
						],
					],
					ContentDiffMigrator::DATAKEY_TERMS => [
						// Term 1 has no meta.
						[
							'term_id' => 41,
							'name' => 'Uncategorized',
							'slug' => 'uncategorized',
							'term_group' => 0
						],
						// Term 2 has some meta.
						[
							'term_id' => 42,
							'name' => 'Custom Term',
							'slug' => 'custom-term',
							'term_group' => 0
						],
						// Term 3 has some meta.
						[
							'term_id' => 70,
							'name' => 'Blue',
							'slug' => 'blue',
							'term_group' => 0
						],
					],
					ContentDiffMigrator::DATAKEY_TERMMETA => [
						// Term 2 Meta.
						[
							'meta_id' => 1,
							'term_id' => 42,
							'meta_key' => '_some_numbermeta',
							'meta_value' => '7'
						],
						[
							'meta_id' => 2,
							'term_id' => 42,
							'meta_key' => '_some_other_numbermeta',
							'meta_value' => '71'
						],
						// Term 3 Meta.
						[
							'meta_id' => 1,
							'term_id' => 70,
							'meta_key' => 'brightness',
							'meta_value' => 60,
						],
						[
							'meta_id' => 2,
							'term_id' => 70,
							'meta_key' => 'contrast',
							'meta_value' => 50,
						],
					],
				]
			]
		];
	}
}
