<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific\CustomListingsFiles\LaSillaVacia;

use Newspack_Listings\Contracts\Importer_Mode;
use Newspack_Listings\Importer\Abstract_Callable_Pre_Create;
use function Newspack_Listings\Importer\sort;

class LaSillaVaciaPreCreateEventImporter extends Abstract_Callable_Pre_Create
{

    /**
     * @inheritDoc
     */
    protected function get_callable(): callable
    {
        return function ( array &$row, Importer_Mode $importer_Mode ) {
            $row['wp_post.post_title'] = $row['title'];
            $row['wp_post.post_date'] = $row['createdAt'];

            $row['wp_post.post_author'] = 0;

            $new_user_id = $this->find_new_user_id( $row['createdBy'] );

            if (!is_null($new_user_id)) {
                $row['wp_post.post_author'] = $new_user_id;
            }
            // TODO Handle case where user_id is 0 in post processor

            $row['show_time'] = "true";
            $row['show_end'] = "false";

            $start_date = date('Y-m-d', strtotime( $row['start'] ) );
            $end_date = ! is_null( $row['end'] ) ? date('Y-m-d', strtotime( $row['end'] ) ) : null;

            // Need to handle start and end times
            $time_range = $row['friendly_range_time'];

            // regex to pull out time followed by meridian, could be a.m., am, p.m, or pm, and ignores other text.
            $time_regex = '/(\d{1,2}:\d{2})\s*(a\.?m\.?|p\.?m\.?)/i';
            $matches = [];
            preg_match_all($time_regex, $time_range, $matches);

            // $matches[0] is the full match, this is what I'm interested in
            // need to compare which time is earlier and which is later
            // if there's only one time, then it's the start time
            // if there's two times, then need to compare them and determine which one is earlier
            // if there's more than two times, then only use the first two
            // Convert times to timestamps
            $matches = array_map( fn ($time) => strtotime($time), $matches[0] );
            sort( $matches );
            // now $matches[0] is the earliest time and $matches[1] is the latest time

            $start_time = isset( $matches[0] ) ? date('H:i:s', $matches[0] ) : '';
            $end_time = isset( $matches[1] ) ? date( 'H:i:s', $matches[1] ) : '';

            $row['start_date'] = "{$start_date}T{$start_time}";
            $row['end_date'] = '';

            if ( is_null( $end_date ) ) {
                if ( ! empty( $end_time ) ) {
                    $row['end_date'] = "{$start_date}T{$end_time}";
                    $row['show_end'] = "true";
                }
            } else {
                $row['end_date'] = "{$end_date}T{$end_time}";
                $row['show_end'] = "true";
            }

            if ( empty( $start_time ) && empty( $end_time ) ) {
                $row['show_time'] = "false";
            }

            $row['organizer'] = '';
            $row['separator'] = '';

            if ( ! empty( $row['by'] ) ) {
                $row['organizer'] = '<!-- wp:paragraph --><p><strong>Organizador:</strong> ' . $row['by'] . '</p><!-- /wp:paragraph -->';
            }

            if ( ! empty( $row['event_place'] ) ) {
                $row['event_place'] = '<!-- wp:paragraph --><p><strong>Lugar:</strong> ' . $row['event_place'] . '</p><!-- /wp:paragraph -->';
            }

            if ( ! empty( $row['details' ] ) ) {
                $row['details'] = '<!-- wp:html --><div class="wp-block-html">' . $row['details'] . '</div><!-- /wp:html -->';
            }

            if ( ! empty( $row['schedule'] ) ) {
                $row['schedule'] = '<!-- wp:heading {"level":4,"style":{"typography":{"textTransform":"uppercase"}}} -->'
                        . '<h4 style="text-transform:uppercase">Agenda</h4>'
                    .'<!-- /wp:heading -->'
                    . '<!-- wp:html -->'
                        . '<div class="wp-block-html">' . $row['schedule'] . '</div>'
                    . '<!-- /wp:html -->';
                $row['separator'] = '<!-- wp:separator {"className":"is-style-wide"} --><hr class="wp-block-separator has-alpha-channel-opacity is-style-wide"/><!-- /wp:separator -->';
            }

            $row['html_description'] = '';

            if ( ! empty( $row['_embedded']['CustomFields']['DetallesEvento'] ) ) {
                $row['html_description'] .= $row['_embedded']['CustomFields']['DetallesEvento'];
            }

            if ( ! empty( $row['_embedded']['CustomFields']['AgendaEvento'] ) ) {
                $row['html_description'] .= $row['_embedded']['CustomFields']['AgendaEvento'];
            }

            if ( ! empty( $row['_embedded']['CustomFields']['OrganizadorEvento'] ) ) {
                $row['html_description'] .= $row['_embedded']['CustomFields']['OrganizadorEvento'];
            }

            if ( ! empty( $row['_embedded']['CustomFields']['Lugarevento'] ) ) {
                $row['html_description'] .= $row['_embedded']['CustomFields']['Lugarevento'];
            }


            $row['categories'] = array_map( fn($category) => $category['name'], $row['categories'] );

            $row['images'] = [];

            if ( ! is_null( $row['picture'] ) ) {
                $url = $this->get_encoded_url( $row['picture'] );
                $row['images'][] = [ 'path' => $url ];
            }

            foreach ( $row['sponsor'] as $sponsor ) {
                $url = $this->get_encoded_url( $sponsor );
                $row['images'][] = [ 'path' => $url ];
            }
        };
    }

    /**
     * @param int $original_user_id
     * @return int|null
     */
    private function find_new_user_id( int $original_user_id ): ?int
    {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 
                user_id
            FROM $wpdb->usermeta 
            WHERE meta_key = 'original_user_id' 
              AND meta_value = %d", $original_user_id
            )
        );
    }

    /**
     * @param array $obj
     * @return string
     */
    private function get_encoded_url( array $obj ):string
    {
        $full_url = $obj['url'];
        $url_without_file_name = substr($full_url, 0, strrpos($full_url, '/') + 1);

        if ( ! str_starts_with( $url_without_file_name, 'http://' ) ) {
            $url_without_file_name = 'http://' . $url_without_file_name;
        }

        $file_name = $obj['name'];
        $file_name = array_map( fn($char) => ctype_space($char) ? '%20' : $char, mb_str_split( $file_name ) );
        $file_name = implode($file_name);

        return $url_without_file_name . $file_name;
    }
}