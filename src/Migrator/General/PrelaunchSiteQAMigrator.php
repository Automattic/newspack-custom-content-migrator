<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use Exception;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\Migrator\PublisherSpecific\TestMigrator;
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
	 */
	private $available_commands;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->available_commands = [

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

			$result = $this->call_qa_command( $command, $dry_run );

			if ( $result === false ) {
				$this->warn_and_ask_to_continue( sprintf( 'The last command (%s) failed. ', $command['name'] ) );
			}

			if ( $index < count( $commands ) - 1 && ! $skip_confirmation ) {
				$this->warn_and_ask_to_continue( 'Would you like to continue running the other steps?' );
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

	public function call_qa_command( $command, $dry_run = false ) {
		$pos_args = array();
		$assoc_args = array();

		if ( $dry_run ) {
			$assoc_args['dry-run'] = true;
		}

		$pos_args = array_merge( $command['pos_args'] ?? array(), $pos_args );
		$assoc_args = array_merge( $command['assoc_args'] ?? array(), $assoc_args );

		WP_CLI::log('Calling command');

		// Check if the provided class exists and is loaded
		if ( ! class_exists( $command['class' ] ) ) {
			$this->warn_and_ask_to_continue( sprintf( 'The class provided for "%s" does not exist.', $command['name'] ) );
		}

		// Check if the class implements InterfaceMigrator, before trying to call get_instance()
		if ( ! in_array( 'NewspackCustomContentMigrator\Migrator\InterfaceMigrator', class_implements( $command['class'] ) ) ) {
			$this->warn_and_ask_to_continue( sprintf( 'The class provided for "%s" does not implement InterfaceMigrator', $command['name'] ) );
		}

		$migrator_instance = $command['class']::get_instance();
		
		// Check fi the object has the provided method
		if ( ! method_exists( $migrator_instance, $command['method'] ) ) {
			$this->warn_and_ask_to_continue( sprintf( 'The class provided for "%s" does not have the provided method %s.', $command['name'], $command['method'] ) );
		}

		try {
			call_user_func_array( [ $migrator_instance, $command['method'] ], [ $pos_args, $assoc_args ] );
		} catch (Exception $e) {
			return false;
		}
	}

	public function warn_and_ask_to_continue( $error_message = '' ) {
		if ( $error_message ) {
			WP_CLI::warning( $error_message );
		}
		WP_CLI::confirm( 'Would you like to continue?' );
	}
}
