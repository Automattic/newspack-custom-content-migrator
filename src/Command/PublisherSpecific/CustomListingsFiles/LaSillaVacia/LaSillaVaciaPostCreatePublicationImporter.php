<?php

namespace Newspack_Listings\Importer;

use Newspack_Listings\Contracts\Importer_Mode;
use WP_Post;

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

            global $wpdb;

            $original_user_ids = array_map( fn( $expert ) => $expert['id'], $row['experts'] );
            $guest_author_query = $wpdb->prepare(
                "SELECT 
                        post_id 
                    FROM $wpdb->postmeta 
                    WHERE meta_key = 'original_user_id' 
                      AND meta_value IN (" . implode( ',', $original_user_ids ) . ')'
            );
            $guest_author_ids = $wpdb->get_col( $guest_author_query );

            if ( ! empty( $guest_author_ids ) ) {
                $term_taxonomy_ids_query = $wpdb->prepare(
                    "SELECT 
                            tt.term_taxonomy_id
                        FROM $wpdb->term_taxonomy tt 
                            INNER JOIN $wpdb->term_relationships tr 
                                ON tt.term_taxonomy_id = tr.term_taxonomy_id
                        WHERE tt.taxonomy = 'author' 
                          AND tr.object_id IN (" . implode( ',', $guest_author_ids ) . ')'
                );
                $term_taxonomy_ids = $wpdb->get_col( $term_taxonomy_ids_query );

                foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
                    $wpdb->insert(
                        $wpdb->term_relationships,
                        [
                            'object_id'        => $listing->ID,
                            'term_taxonomy_id' => $term_taxonomy_id,
                        ]
                    );
                }
            }
        };
    }
}