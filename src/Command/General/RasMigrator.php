<?php

namespace NewspackCustomContentMigrator\Command\General;

use Newspack\MigrationTools\Command\WpCliCommandTrait;
use NewspackCustomContentMigrator\Command\RegisterCommandInterface;
use WP_CLI;
use Newspack\Reader_Activation;
use Newspack_Segments_Model;
use Newspack_Popups_Model;

/**
 * Profile Press reusable commands.
 */
class RasMigrator implements RegisterCommandInterface {

	use WpCliCommandTrait;

    /**
     * {@inheritDoc}
     */
    public static function register_commands(): void
    {
        WP_CLI::add_command(
            'newspack-content-migrator ras-campaign-migrator',
	        self::get_command_closure( 'cmd_ras_campaign_migrator'),
            [
                'shortdesc' => 'Imports Campaigns and checks the last item in the RAS wizard, activating RAS at the end.',
            ]
        );
    }

    /**
     * Imports Campaigns and checks the last item in the RAS wizard, activating RAS at the end.
     *
     * This command is useful if you dont want to manually go through the last step of the RAS wizard, but
     * instead import the Campaigns from a json file and then activate RAS.
     *
     * ## OPTIONS
     *
     * <json-url>
     * : The URL of the json file containing the Campaigns. This must be generated using the newspack-popups export command
     *
     * @param array $pos_args   Positional arguments.
     * @param array $assoc_args Associative arguments.
     *
     * @throws \RuntimeException If author term wasn't successfully converted to GA.
     */
    public function cmd_ras_campaign_migrator($pos_args, $assoc_args)
    {

        $is_ready = Reader_Activation::is_ras_ready_to_configure();

        if ( ! $is_ready ) {
            WP_CLI::error('All prior steps in the RAS wizard must be complete in order to run this command.');
        }

        // save the json file to a temp file
        $target_file = tempnam(sys_get_temp_dir(), 'ras-campaigns-');
        $json_url = $pos_args[0];
        $json = file_get_contents($json_url);
        if ( false !== $json ) {
            WP_CLI::success('Successfully downloaded the json file.');
        } else {
            WP_CLI::error('Failed to download the json file.');
        }
        file_put_contents($target_file, $json);

        // Deactivate all existing segments.
        WP_CLI::line('Deactivating all existing segments...');
		foreach ( Newspack_Segments_Model::get_segments() as $existing_segment ) {
			$existing_segment['configuration']['is_disabled'] = true;
			Newspack_Segments_Model::update_segment( $existing_segment );
		}

		// Deactivate all existing prompts.
        WP_CLI::line('Deactivating all existing prompts...');
		$existing_prompts = Newspack_Popups_Model::retrieve_active_popups();
		foreach ( $existing_prompts as $prompt ) {
			$updated = \wp_update_post(
				[
					'ID'          => $prompt['id'],
					'post_status' => 'draft',
				]
			);

			if ( \is_wp_error( $updated ) ) {
				return $updated;
			}
		}

        // Check if the json is valid.
        $validation = new \Newspack\Campaigns\Schemas\Package( json_decode( $json ) );
		if ( ! $validation->is_valid() ) {
            WP_CLI::error( 'The json file is not valid.' );
        }

        WP_CLI::runcommand( 'newspack-popups import ' . $target_file );

        Reader_Activation::update_setting( 'enabled', true );

        WP_CLI::success('RAS is now activated.');

    }
}
