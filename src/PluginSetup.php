<?php
declare(ticks=1);

namespace NewspackCustomContentMigrator;

use Newspack\MigrationTools\Command\WpCliCommandInterface;
use Newspack\MigrationTools\Command\WpCliCommands;
use WP_CLI;

/**
 * PluginSetup class.
 */
class PluginSetup {
	/**
	 * Register a tick callback to check the if we exceed the memory limit.
	 */
	public static function register_ticker() {
		register_tick_function(
			function() {
				$memory_usage = memory_get_usage( false );

				if ( $memory_usage > 490000000 ) { // 490 MB in bytes, since the limit on Atomic is 512 MB.
					print_r( 'Exit due to memory usage: ' . $memory_usage );
					exit( 1 );
				}
			}
		);
	}

	/**
	 * Configures all errors and warnings will be output to CLI.
	 * 
	 * @param string $level Error reporting level. 'dev' is default. 'live' will not change error reporting.
	 */
	public static function configure_error_reporting( $level = 'dev' ): void {
		if ( 'dev' === $level ) {
			// phpcs:disable -- Adds extra debugging config options for dev purposes.
			@ini_set( 'display_errors', 1 );
			@ini_set( 'display_startup_errors', 1 );
			error_reporting( E_ALL );
			// phpcs:enable

			// Enable WP_DEBUG mode.
			if ( ! defined( 'WP_DEBUG' ) ) {
				define( 'WP_DEBUG', true );
			}
			// Enable Debug logging to the /wp-content/debug.log file.
			if ( ! defined( 'WP_DEBUG_LOG' ) ) {
				define( 'WP_DEBUG_LOG', true );
			}
			// Enable display of errors and warnings.
			if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
				define( 'WP_DEBUG_DISPLAY', true );
			}
		}
	}

	/**
	 * Registers command classes.
	 *
	 * @param array $classes Array of classes implementing the RegisterCommandInterface.
	 */
	public static function register_command_classes( array $classes ): void {

		// Get the commands from implementers of the newspack_migration_tools_command_classes hook.
		foreach ( WpCliCommands::get_classes_with_cli_commands() as $command_class ) {
			if ( is_a( $command_class, WpCliCommandInterface::class, true ) ) {
				array_map( function ( $command ) {
					WP_CLI::add_command( ...$command );
				}, $command_class::get_cli_commands() );
			}
		}

		try {
			// Register the commands from the passed classes array.
			foreach ( $classes as $command_class ) {
				if ( is_a( $command_class, Command\RegisterCommandInterface::class, true ) ) {
					$command_class::register_commands();
				}
			}
		} catch ( \Exception $o_0 ) {
			WP_CLI::error( sprintf('Error registering command for class %s. Message: %s', $command_class, $o_0->getMessage() ));
		}

	}

	/**
	 * Registers migrators' commands.
	 *
	 * @deprecated Use register_command_classes instead (and refactor the class passed to it).
	 *
	 * @param array $migrator_classes Array of Command\InterfaceCommand classes.
	 */
	public static function register_migrators( array $migrator_classes ) {

		foreach ( $migrator_classes as $migrator_class ) {
			$migrator = $migrator_class::get_instance();
			if ( $migrator instanceof Command\InterfaceCommand ) {
				$migrator->register_commands();
			}
		}
	}

	/**
	 * Checks whether wordpress-importer is active and valid, and if not, installs and activates it.
	 */
	public static function setup_wordpress_importer() {
		$plugin_installer = \NewspackCustomContentMigrator\PluginInstaller::get_instance();
		$plugin_slug      = 'wordpress-importer';
		$is_installed     = $plugin_installer->is_installed( $plugin_slug );
		$is_active        = $plugin_installer->is_active( $plugin_slug );

		if ( $is_installed && ! $is_active ) {
			WP_CLI::line( sprintf( 'Activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'WP Importer Plugin activation error: ' . $e->getMessage() );
			}
		} elseif ( ! $is_installed ) {
			WP_CLI::line( sprintf( 'Installing and activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->install( $plugin_slug );
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'WP Importer Plugin installation error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Checks whether Co-Authors-Plus is active and valid, and if not, installs and activates it.
	 */
	public static function setup_coauthors_plus() {
		$plugin_installer = \NewspackCustomContentMigrator\PluginInstaller::get_instance();
		$plugin_slug      = 'co-authors-plus';
		$is_installed     = $plugin_installer->is_installed( $plugin_slug );
		$is_active        = $plugin_installer->is_active( $plugin_slug );

		if ( $is_installed && ! $is_active ) {
			WP_CLI::line( sprintf( 'Activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'Plugin activation error: ' . $e->getMessage() );
			}
		} elseif ( ! $is_installed ) {
			WP_CLI::line( sprintf( 'Installing and activating the %s plugin now...', $plugin_slug ) );
			try {
				$plugin_installer->install( $plugin_slug );
				$plugin_installer->activate( $plugin_slug );
			} catch ( \Exception $e ) {
				WP_CLI::error( 'Plugin installation error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Add hooks for all commands.
	 *
	 * Note that to add a hook for a specific command, you should add it in the command class in the command (not the constructor).
	 * That way it only applies to that command/publisher when run.
	 *
	 * @return void
	 */
	public static function add_hooks(): void {
		if ( ! defined( 'NCCM_DISABLE_CLI_LOG' ) || empty( 'NCCM_DISABLE_CLI_LOG' ) ) {
			add_filter( 'newspack_migration_tools_enable_cli_log', '__return_true' );
		}
		if ( ! defined( 'NCCM_DISABLE_FILE_LOG' ) || empty( 'NCCM_DISABLE_FILE_LOG' ) ) {
			add_filter( 'newspack_migration_tools_enable_file_log', '__return_true' );
		}
		if ( ! defined( 'NCCM_DISABLE_PLAIN_LOG' ) || empty( 'NCCM_DISABLE_PLAIN_LOG' ) ) {
			add_filter( 'newspack_migration_tools_enable_plain_log', '__return_true' );
		}
	}

	/**
	 * @param string $message Message to log.
	 * @param string $level Log level - see constants in Logger class.
	 * @param bool $exit_on_error If true, will exit the script on error.
	 *
	 * @return void
	 */
//	public static function action_cli_log( string $message, string $level, bool $exit_on_error ): void {
//		static $logger = null;
//		if ( is_null( $logger ) ) {
//			$logger = new Utils\Logger();
//		}
//		$logger->wp_cli_log( $message, $level, $exit_on_error );
//	}

}
