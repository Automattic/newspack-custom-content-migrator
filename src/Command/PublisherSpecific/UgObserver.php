<?php
/**
 * Joomla to WordPress wrapper that uses the great fg-joomla-to-wordpress and fg-joomla-to-wordpress-premium plugins.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use WP_CLI;
use WP_CLI\ExitException;

/**
 * Class. Yup. It's a class.
 */
class UgObserver implements RegisterCommandInterface {

	use WpCliCommandTrait;


	/**
	 * Constructor.
	 *
	 * @throws ExitException If the NCCM_SOURCE_WEBSITE_URL constant is not defined.
	 */
	private function __construct() {
		if ( ! defined( 'NCCM_SOURCE_WEBSITE_URL' ) ) {
			WP_CLI::error( 'NCCM_SOURCE_WEBSITE_URL is not defined in wp-config.php' );
		}
	}

	/**
	 * Add hooks.
	 */
	public function add_fg_hooks(): void {
		// Add filters and actions here if we need them.
	}

	/**
	 * {@inheritDoc}
	 */
	public static function register_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator uc-wrap-import',
			self::get_command_closure( 'cmd_wrap_joomla_import' ),
			[
				'shortdesc' => 'Wrap the import command from FG Joomla.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Run the import.
	 *
	 * We simply wrap the import command from FG Joomla and add our hooks before running the import.
	 * Note that we can't batch this at all, so timeouts might be a thing.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_wrap_joomla_import( array $pos_args, array $assoc_args ): void {
		add_filter( 'option_fgj2wp_options', [ $this, 'filter_fgj2wp_options' ] );
		add_action( 'fgj2wp_pre_dispatch', [ $this, 'add_fg_hooks' ] );
		// Note that the 'launch' arg is important – without it the hooks above will not be registered.
		WP_CLI::runcommand( 'import-joomla import', [ 'launch' => false ] );
	}

	/**
	 * Override the database connection details with environment variables.
	 *
	 * @param array $options Options for the fgj2wp plugin (it's fgj2wp_options in the database).
	 */
	public function filter_fgj2wp_options( $options ) {
		$options['hostname'] = getenv( 'DB_HOST' );
		$options['database'] = getenv( 'DB_NAME' );
		$options['username'] = getenv( 'DB_USER' );
		$options['password'] = getenv( 'DB_PASSWORD' );
		if ( empty( $options['hostname'] ) || empty( $options['database'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
			WP_CLI::error( 'Could not get database connection details from environment variables.' );
		}

		$options['prefix'] = $this->get_prefix();

		return $options;
	}

	/**
	 * Get the prefix for the Joomla tables.
	 *
	 * @return string
	 */
	private function get_prefix(): string {
		if ( defined( 'NCCM_JOOMLA_PREFIX' ) && ! empty( trim( NCCM_JOOMLA_PREFIX ) ) ) {
			return NCCM_JOOMLA_PREFIX;
		}

		return 'joomla_';
	}
}
