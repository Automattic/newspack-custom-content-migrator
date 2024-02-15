<?php

namespace NewspackCustomContentMigrator\Command\General;

use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use WP_CLI;
use WP_CLI\ExitException;

/**
 * Class MigrationHelper.
 */
class MigrationHelper implements InterfaceCommand {
	/**
	 * Get Instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Register commands.
	 *
	 * @throws Exception When WP_CLI::add_command fails.
	 */
	public function register_commands(): void {
		WP_CLI::add_command(
			'newspack-content-migrator migration-helper-output-batched',
			[ $this, 'cmd_output_batched' ],
			[
				'shortdesc' => 'Outputs commands batched. Only outputs the command strings – will not run any of the commands.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch-size',
						'description' => 'Number of items to be processed in each batch.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'total-items',
						'description' => 'How many items to process in total.', // TODO. Better description.
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'begin-at',
						'description' => 'Number we should start from',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'positional',
						'name'        => 'command-string',
						'description' => 'Command you want to run. Use "" quotes around the command and feel free to add as many args INSIDE those as you want.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'start-arg-name',
						'description' => 'Name of start arg. Optional. Defaults to values in the BatchLogic class',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'end-arg-name',
						'description' => 'Name of end arg. Optional. Defaults to values in the BatchLogic class',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'iterm-trigger',
						'description' => 'Print a trigger for iTerm to pick up after the command has run.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Outputs commands batched. Only outputs the command strings – will not run any of the commands.
	 *
	 * @param array $positional_args Positional args.
	 * @param array $assoc_args Associative args.
	 *
	 * @throws ExitException When WP_CLI::runcommand fails.
	 */
	public function cmd_output_batched( array $positional_args, array $assoc_args ): void {
		$begin_at       = $assoc_args['begin-at'] ?? 0;
		$total_items    = $assoc_args['total-items'];
		$batch_size     = $assoc_args['batch-size'];
		$command_string = $positional_args[0] ?? false;
		$start_arg_name = $assoc_args['start-arg-name'] ?? BatchLogic::$start['name'];
		$end_arg_name   = $assoc_args['end-arg-name'] ?? BatchLogic::$end['name'];
		$iterm_trigger  = $assoc_args['iterm-trigger'] ?? false;

		if ( $command_string ) {
			if ( empty( preg_match( '/(wp\s\S+\s\S+)/', $command_string, $matches ) ) ) {
				WP_CLI::error( 'Command does not look valid. It should start with 3 words like "wp command-this command-that"' );
			}
			$command        = $matches[1];
			$command_args   = trim( str_replace( $command, '', $command_string ) );
			$command_args   = \WP_CLI\Utils\parse_str_to_argv( $command_args );
			$command_args   = implode( " \\\n", $command_args );
			$command_string = $command . " \\\n" . $command_args . " \\\n";
		}

		$total_batched_items = $begin_at > 0 ? $total_items - $begin_at : $total_items;
		$num_batches         = ceil( $total_batched_items / $batch_size );

		for ( $batch = 0; $batch < $num_batches; $batch ++ ) {
			$batch_start = ( $batch * $batch_size ) + $begin_at;
			$batch_end   = $batch_start + $batch_size;
			if ( $batch_end > $total_items ) {
				$batch_end = $total_items;
			}
			$batch_info = sprintf( 'Batch %d of %d', $batch + 1, $num_batches );
			WP_CLI::line( sprintf( PHP_EOL . '# MigrationHelper: %s', $batch_info ) );
			if ( $command_string ) {
				WP_CLI::out( $command_string );
			}

			WP_CLI::out( self::get_batch_string( $start_arg_name, $end_arg_name, $batch_start, $batch_end ) );

			if ( $iterm_trigger ) {
				WP_CLI::line( sprintf( ' && echo "MigrationHelper says:___%s___"', $batch_info ) );
			} else {
				WP_CLI::line( '' ); // Just to add a newline for ease of copypasta.
			}
		}
	}

	/**
	 * Get batch string.
	 *
	 * @param string $start_arg_name Start arg name.
	 * @param string $end_arg_name End arg name.
	 * @param int    $start Start.
	 * @param int    $end End.
	 *
	 * @return string
	 */
	public static function get_batch_string( string $start_arg_name, string $end_arg_name, int $start, int $end ): string {
		$batch_string = '';

		if ( $start > 0 ) {
			$batch_string .= sprintf( ' --%s=%d', $start_arg_name, $start );
		}

		if ( $end > 0 ) {
			$batch_string .= sprintf( ' --%s=%d', $end_arg_name, $end );
		}

		return $batch_string;

	}

}
