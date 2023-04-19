<?php

namespace Newspack_Listings\Importer;

use Newspack_Listings\Contracts\Importer_Mode;

class LaSillaVaciaPreCreatePublicationImporter extends Abstract_Callable_Pre_Create
{

    /**
     * @inheritDoc
     */
    protected function get_callable(): callable
    {
        return function (array &$row, Importer_Mode $importer_Mode ) {
            $row['wp_post.post_title'] = $row['title'];
            $row['wp_post.post_date'] = $row['createdAt'];
            // TODO Handle post author lookup
            $row['wp_post.post_author'] = 0;

            $new_user_id = $this->find_new_user_id( $row['createdBy'] );

            if ( ! is_null( $new_user_id ) ) {
                $row['wp_post.post_author'] = $new_user_id;
            }

            $row['wp_post.meta_input']['original_url'] = $row['url'];
            $row['wp_post.meta_input']['original_post_id'] = $row['id'];

            $row['book_title'] = $row['title'];
            $row['book_publisher'] = '';
            $row['book_buy_or_download'] = 'comprar';

            if ( (int) $row['precio'] > 0 ) {
                $row['book_price'] = "<!-- wp:paragraph -->
                    <p>{$row['precio']} COP</p>
                    <!-- /wp:paragraph -->";
            } else {
                $row['book_price'] = '';
                $row['book_buy_or_download'] = 'descargar';
            }

            $row['book_isbn'] = '';
            if ( ! empty( $row['ISBN'] ) ) {
                $row['book_isbn'] = "<!-- wp:list-item -->
                    <li><strong>ISBN:</strong> {$row['ISBN']}</li>
                    <!-- /wp:list-item -->";
            }

            $row['book_published_year'] = '';
            if ( ! empty( $row['bookPublishedAt'] ) ) {
                if ( 4 === strlen( $row['bookPublishedAt'] ) ) {
                    $year = $row['bookPublishedAt'];
                } else {
                    $year = date( 'Y', strtotime( $row['bookPublishedAt'] ) );
                }

                $row['book_published_year'] = "<!-- wp:list-item -->
                    <li><strong>Publicación:</strong> $year</li>
                    <!-- /wp:list-item --></ul>";
            }

            $authors = array_map( fn( $expert ) => $expert['name'], $row['experts'] );
            if ( ! empty( $authors ) ) {
                $row['book_author'] = implode( ', ', $authors );
            } else {
                $row['book_author'] = $row['facultad'];
            }
            $row['book_pages'] = $row['paginas'];
            $row['book_description'] = $row['description'];
            $row['book_link'] = $row['link'];


            $row['images'] = [];

            if ( ! is_null( $row['picture'] ) ) {
                $row['images'][] = [ 'path' => wp_upload_dir()['basedir'] . '/' . $row['picture'] ];
            }

            $row['categories'] = array_map( fn( $category ) => $category['name'], $row['categories'] );
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
}