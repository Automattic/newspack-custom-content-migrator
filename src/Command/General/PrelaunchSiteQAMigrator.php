<?php
/**
 * PrelaunchSiteQAMigrator takes care of running QA commands prior to launching a site.
 * 
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\General;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Command\General\AttachmentsMigrator;
use NewspackCustomContentMigrator\Utils\Logger;
use \WP_CLI;

/**
 * General Prelaunch QA migrator
 */
class PrelaunchSiteQAMigrator implements InterfaceCommand {

	/**
	 * Instance of the class
	 * 
	 * @var null|InterfaceCommand
	 */
	private static $instance = null;

	/**
	 * Instance of Logger
	 * 
	 * @var Logger
	 */
	private $logger;

	/**
	 * Array of available commands
	 * 
	 * @var array {
	 *
	 *     @type array {
	 *         @type func $method (required) A callable to run for the command
	 *         @type string $name (required): The name of the step to show in the CLI
	 *     }
	 * }
	 */
	private $available_commands;

	/**
	 *  Array of positional arguments provided to the QA command
	 * 
	 * @var array
	 */
	private $global_pos_args;

	/**
	 * Associative array of associative arguments provided to the QA command
	 * 
	 * @var array
	 */
	private $global_assoc_args;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();

		$this->available_commands = [
			[
				'name'   => 'Check broken images',
				'method' => [ $this, 'call_check_broken_images' ],
			],
		];
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
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

	/**
	 * Run the Prelaunch Site QA command, which itself runs other commands
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative Arguments.
	 *
	 * @return void
	 */
	public function cmd_run_qa( $pos_args, $assoc_args ) {
		$this->global_pos_args   = $assoc_args;
		$this->global_assoc_args = $assoc_args;

		if ( $this->should_run_all_steps() ) {
			$commands = $this->available_commands;
		} else {
			$commands = $this->get_user_choice();
		}

		foreach ( $commands as $index => $command ) {
			WP_CLI::log( sprintf( '----------%s----------', $command['name'] ) );

			$result = $this->call_qa_command( $command );

			$ask_for_confirmation = ! $this->should_skip_confirmation() || false === $result;
			
			if ( $index < count( $commands ) - 1 && $ask_for_confirmation ) {
				WP_CLI::confirm( 'Would you like to continue running the other steps?' );
			}
		}

		WP_CLI::success( 'All PrelaunchQA steps were ran.' );
	}

	/**
	 * Show the user a menu of available QA commands and ask for their input
	 * 
	 * @return array Array of chosen commands
	 */
	public function get_user_choice() {
		WP_CLI::log( 'Please choose which commands to run. You can separate multiple commands using a comma (,).' );

		foreach ( $this->available_commands as $index => $command ) {
			WP_CLI::log( sprintf( '%d. %s', $index + 1, $command['name'] ) );
		}

		WP_CLI::log( "\n0. Run all the steps " );

		// Read which commands to run.
		$commands = $this->prompt( 'Please enter the commands, or 0 if you want to run all the steps: ' );

		// The case where the user wants to run all commands.
		if ( '0' == $commands ) {
			$commands = $this->available_commands;
		} else {
			// Put them in an array.
			$commands = explode( ',', $commands );

			// Make the indexes zero based.
			$commands = array_map(
				function( $index ) {
					return intval( $index ) - 1;
				},
				$commands
			);

			// Make sure all indexes actually exist.
			$commands = array_filter(
				$commands,
				function( $index ) {
					return isset( $this->available_commands[ $index ] );
				}
			);

			$commands = array_map(
				function( $index ) {
					return $this->available_commands[ $index ];
				},
				$commands 
			);

			if ( empty( $commands ) ) {
				die();
			}
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
	 *       The command to run.
	 *
	 *     @type func $method (required) A callable to run for the command
	 *     @type string $name (required): The name of the step to show in the CLI
	 * }
	 *
	 * @return boolean False if thec command threw an Exceptionm, true otherwise
	 */
	public function call_qa_command( $command ) {
		try {
			call_user_func( $command['method'] );
		} catch ( Exception $e ) {
			WP_CLI::warning( sprintf( 'Command %s failed with error: %s', $command['method'], $e->getMessage() ) );
			return false;
		}

		return true;
	}

	/**
	 * Ask the user for input from the CLI
	 * 
	 * @param string $question A question to show in the CLI.
	 * 
	 * @return string The user's input
	 */
	public function prompt( $question ) {
		echo $question;

		$response = stream_get_line( STDIN, 1024, "\n" );
		if ( "\r" === substr( $response, -1 ) ) {
			$response = substr( $response, 0, -1 );
		}

		return $response;
	}

	/**
	 * Check if we're running the QA in dry-run mode
	 * 
	 * @return boolean
	 */
	public function is_dry_run_mode() {
		return isset( $this->global_assoc_args['dry-run'] );
	}

	/**
	 * Check if the script should skip asking for confirmation after each step
	 * 
	 * @return boolean
	 */
	public function should_skip_confirmation() {
		return isset( $this->global_assoc_args['skip-confirmation'] );
	}

	/**
	 * Check if the script should run all the steps, instead of asking which commands to run
	 * 
	 * @return boolean
	 */
	public function should_run_all_steps() {
		return isset( $this->global_assoc_args['run-all-steps'] );
	}

	/**
	 * Create a unified log file name prefix for the command, with a timestamp. Also creates a directory is possible
	 * 
	 * @param string $command_name The name of the commande.
	 * 
	 * @return string The log file name prefix, with the folder. For example: qa_check_broken_images_logs/qa_2022-08-28_00-00-00_check_broken_images
	 */
	public function get_log_file_name( $command_name ) {
		$log_file_prefix = sprintf( 'qa_%s_%s', gmdate( 'Y-m-d_H-i-s' ), $command_name );
		$log_folder_name = $this->logger->get_le_log_path();

		// Append the LE log folder to the filename.
		$log_file_prefix = $log_folder_name . '/' . $log_file_prefix;
		return $log_file_prefix;
	}

	/**
	 * Wrapper function for calling the check_broken_images command
	 */
	public function call_check_broken_images() {
		$assoc_args = array(
			'log-file-prefix' => $this->get_log_file_name( 'check_broken_images' ),
		);

		if ( $this->is_dry_run_mode() ) {
			$assoc_args['dry-run'] = true;
		}

		$attachments_migrator = AttachmentsMigrator::get_instance();
		$attachments_migrator->cmd_check_broken_images( array(), $assoc_args );
	}
}
