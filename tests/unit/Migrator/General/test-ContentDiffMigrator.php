<?php
/**
 * Test class for the \NewspackCustomContentMigrator\Migrator\General\ContentDiffMigrator.
 *
 * @package Newspack
 */

namespace NewspackCustomContentMigratorTest\Migrator\General;

use WP_UnitTestCase;
use NewspackCustomContentMigrator\Migrator\General\ContentDiffMigrator;

/**
 * Class TestBlockquotePatcher.
 */
class TestContentDiffMigrator extends WP_UnitTestCase {

	/**
	 * ContentDiffMigrator.
	 *
	 * @var ContentDiffMigrator
	 */
	private $migrator;

	/**
	 * Override setUp.
	 */
	public function setUp() {
		$this->migrator = ContentDiffMigrator::get_instance();
	}

	public function test_should_work() {
		$expected = true;
		$actual = false;

		$this->assertSame( $expected, $actual );
	}
}
