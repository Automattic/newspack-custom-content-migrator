<?php

namespace Newspack_Listings\Importer;

use Exception;
use Newspack_Listings\Contracts\Importer_Mode;
use NewspackCustomContentMigrator\Command\PublisherSpecific\MigrationPostAuthors;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use WP_Post;
use WP_CLI;

class LaSillaVaciaPostCreatePublicationImporter extends Abstract_Callable_Post_Create
{

    /**
     * @inheritDoc
     */
    protected function get_callable(): callable
    {
        return function ( WP_Post $listing, Importer_Mode $importer_mode, array $row) {
            if ( empty( $row['experts'] ) ) {
                return;
            }

            $original_user_ids = array_map( fn( $expert ) => $expert['id'], $row['experts'] );
	        try {
				$migration_post_authors = new MigrationPostAuthors( $original_user_ids );
				$assigned_to_post = $migration_post_authors->assign_to_post( $listing->ID );

		        if ( $assigned_to_post ) {
			        foreach ( $migration_post_authors->get_authors() as $migration_author ) {
				        echo WP_CLI::colorize( "%WAssigned {$migration_author->get_output_description()} to post ID {$listing->ID}%n\n" );
			        }
		        }
	        } catch ( Exception $e ) {
				$this->handle_expertos_on_the_fly( $row['experts'], $listing->ID );
	        }
        };
    }

	private function handle_expertos_on_the_fly( array $experts, int $post_id ) {
		$original_user_ids = array_map( fn( $expert ) => $expert['id'], $experts );

		global $wpdb;

		$original_user_id_placeholders = array_fill( 0, count( $original_user_ids ), '%d' );
		$original_user_id_placeholders = implode( ',', $original_user_id_placeholders );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value 
				FROM $wpdb->postmeta 
				WHERE meta_key = 'original_user_id' 
				  AND meta_value IN ( $original_user_id_placeholders )",
				$original_user_ids
			)
		);

		$existing_original_user_ids = array_map( fn( $result ) => intval( $result->meta_value ), $results );

		$co_author_plus_logic = new CoAuthorPlus();

		foreach ( $experts as $expert ) {
			if ( in_array( $expert['id'], $existing_original_user_ids ) ) {
				continue;
			}

			$exploded_name = explode( ' ', $expert['name'] );
			$last_name = array_pop( $exploded_name );
			$first_name = implode( ' ', $exploded_name );

			$guest_author_id = $co_author_plus_logic->create_guest_author( [
				'display_name' => $expert['name'],
				'first_name' => $first_name,
				'last_name' => $last_name,
				'user_email' => sanitize_title( $expert['name'] ) . '@no-site.com',
			] );

			update_post_meta( $guest_author_id, 'original_user_id', $expert['id'] );
		}

		try {
			$migration_post_authors = new MigrationPostAuthors( $original_user_ids );
			$assigned_to_post = $migration_post_authors->assign_to_post( $post_id );

			if ( $assigned_to_post ) {
				foreach ( $migration_post_authors->get_authors() as $migration_author ) {
					echo WP_CLI::colorize( "%WAssigned {$migration_author->get_output_description()} to post ID {$post_id}%n\n" );
				}
			}
		} catch ( Exception $e ) {
			$message = strtoupper( $e->getMessage() );
			echo WP_CLI::colorize( "%Y$message%n\n" );
		}
	}
}