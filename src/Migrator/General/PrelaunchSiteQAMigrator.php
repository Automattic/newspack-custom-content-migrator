<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use Exception;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

/**
 * General Prelaunch QA migrator
 */
class PrelaunchSiteQAMigrator implements InterfaceMigrator  {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var array Array of available commands
	 * Each command is an associative array with with following keys:
	 * - class (required): Fully qualified name of the class that has the method
	 * - method (required): Name of the method to run
	 * - name (required): The name of the step to show in the CLI
	 * - pos_args (optional): An array of positional arguments to pass to the command
	 * - assoc_args (optional): An associative array of flags to pass to the command
	 * - - dry-run: If this flag is set, the command won't make any changes
	 * - - log-file: If the command creates log files, this flag specifies the prefix of the files
	 */
	private $available_commands;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->available_commands = [
			[
				'class' => AttachmentsMigrator::class,
				'method' => 'cmd_check_broken_images',
				'name' => 'Check broken images',
			],
		];
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
			'newspack-content-migrator prelaunchsiteqamigrator-run-qa',
			[ $this, 'cmd_run_qa' ],
			[
				'shortdesc' => 'Run all QA commands to make sure everything is correct before launching.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => 'Run the QA step(s) without making any changes to the database.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'run-all-steps',
						'description' => 'Run all steps instead of choosing which one to run.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'skip-confirmation',
						'description' => 'Continue running the steps without asking for confirmation before each one.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	public function cmd_run_qa( $pos_args, $assoc_args ) {
		$dry_run           = isset( $assoc_args['dry-run'] ) ? true : false;
		$run_all_steps     = isset( $assoc_args['run-all-steps'] ) ? true : false;
		$skip_confirmation = isset( $assoc_args['skip-confirmation'] ) ? true : false;

		if ( $run_all_steps ) {
			$commands = $this->available_commands;
		} else {
			$commands = $this->get_user_choice();
		}

		foreach ( $commands as $index => $command ) {
			WP_CLI::log( sprintf( '----------%s----------', $command['name'] ) );

			$result = $this->call_qa_command( $command, $dry_run );

			$ask_for_confirmation = ! $skip_confirmation || $result === false;
			
			if ( $index < count( $commands ) - 1 && $ask_for_confirmation ) {
				WP_CLI::confirm( 'Would you like to continue running the other steps?' );
			}
		}

		WP_CLI::success( 'All PrelaunchQA steps were ran.' );
	}

	public function get_user_choice() {
		WP_CLI::log( 'Please choose which commands to run. You can separate multiple commands using a comma (,).' );

		foreach ( $this->available_commands as $index => $command ) {
			WP_CLI::log( sprintf( '%d. %s', $index + 1, $command['name'] ) );
		}

		// Read which commands to run
		$commands = readline( 'Please enter the commands: ');

		// Put them in an array
		$commands = explode( ',', $commands );

		// Make the indexes zero based
		$commands = array_map( function( $index ) {
			return intval( $index ) - 1;
		}, $commands);

		// Make sure all indexes actually exist
		$commands = array_filter( $commands, function( $index ) {
			return isset( $this->available_commands[ $index ] );
		});

		$commands = array_map( function( $index ) {
			return $this->available_commands[ $index ];
		}, $commands );

		if ( empty( $commands ) ) {
			die();
		}

		WP_CLI::log( 'The following commands are going to be executed:' );

		foreach ( $commands as $index => $command ) {
			WP_CLI::log( sprintf( '%d. %s', $index + 1, $command['name'] ) );
		}

		WP_CLI::confirm( 'Would you like to continue?' );

		return $commands;
	}

	/**
	 * Run a command that's of part of the PrelaunchSiteQAMigrator
	 *
	 * @param array $command {
	 *     The command to run
	 *
	 *     @type class-string $class (required) Fully qualified name of the class that has the method
	 *     @type string $method (required): Name of the method to run
	 *     @type string $name (required): The name of the step to show in the CLI
	 *     @type array $pos_args (optional): An array of positional arguments to pass to the command
	 *     @type array $assoc_args (optional): An associative array of arguments to pass to the command
	 * }
	 * @param boolean $dry_run True if the command should be ran with the --dry-run flag
	 * @return boolean
	 */
	public function call_qa_command( $command, $dry_run = false ) {
		$pos_args = array();
		$assoc_args = array();

		if ( $dry_run ) {
			$assoc_args['dry-run'] = true;
		}

		$pos_args = array_merge( $command['pos_args'] ?? array(), $pos_args );
		$assoc_args = array_merge( $command['assoc_args'] ?? array(), $assoc_args );

		// Check if the provided class exists and is loaded
		if ( ! class_exists( $command['class' ] ) ) {
			$this->warn_and_ask_to_continue( sprintf( 'The class provided for "%s" does not exist.', $command['name'] ) );
		}

		// Check if the class implements InterfaceMigrator, before trying to call get_instance()
		if ( ! in_array( 'NewspackCustomContentMigrator\Migrator\InterfaceMigrator', class_implements( $command['class'] ) ) ) {
			$this->warn_and_ask_to_continue( sprintf( 'The class provided for "%s" does not implement InterfaceMigrator', $command['name'] ) );
		}

		$migrator_instance = $command['class']::get_instance();
		
		// Check if the object has the provided method
		if ( ! method_exists( $migrator_instance, $command['method'] ) ) {
			$this->warn_and_ask_to_continue( sprintf( 'The class provided for "%s" does not have the provided method %s.', $command['name'], $command['method'] ) );
		}

		// If the command creates logs, create a directory for them
		if ( isset( $assoc_args['log-file'] ) ) {
			$folder = sprintf( '%s_logs', $assoc_args['log-file'] );
			$folder_created = $this->maybe_create_folder( $folder );
			if ( $folder_created ) {
				$assoc_args['log-file'] = sprintf( '%s/%s', $folder, $assoc_args['log-file'] );
			}
		}

		try {
			call_user_func_array( [ $migrator_instance, $command['method'] ], [ $pos_args, $assoc_args ] );
		} catch (Exception $e) {
			WP_CLI::warning( sprintf( 'Command %s failed with error: %s', $command['method'], $e->getMessage() ) );
			return false;
		}

		return true;
	}

	public function warn_and_ask_to_continue( $error_message = '' ) {
		if ( $error_message ) {
			WP_CLI::warning( $error_message );
		}
		WP_CLI::confirm( 'Would you like to continue?' );
	}

	public function maybe_create_folder( $folder ) {
		// Return true if the folder already exists (from previous command run with --dry-run for example)
		if ( is_dir( $folder ) ) {
			return true;
		}

		// Make sure we have permissions to create the folder
		if ( is_writable( dirname( __FILE__ ) ) ) {
			mkdir( $folder );
			return true;
		}

		return false;
	}
}
