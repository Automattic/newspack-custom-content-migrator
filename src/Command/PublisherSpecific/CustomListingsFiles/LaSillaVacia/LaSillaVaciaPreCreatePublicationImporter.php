<?php

namespace Newspack_Listings\Importer;

use Newspack_Listings\Contracts\Importer_Mode;
use NewspackCustomContentMigrator\Command\PublisherSpecific\LaSillaVaciaMigrator;

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

            if ( ! $row['free'] ) {
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

			$row['book_issn'] = '';
			if ( ! empty( $row['ISSN'] ) ) {
				$row['book_issn'] = "<!-- wp:list-item -->
					<li><strong>ISSN:</strong> {$row['ISSN']}</li>
					<!-- /wp:list-item -->";
			}

			$row['book_doi'] = '';
			if ( ! empty( $row['DOI'] ) ) {
				$row['book_doi'] = "<!-- wp:list-item -->
					<li><strong>DOI:</strong> {$row['DOI']}</li>
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


            if ( ! is_null( $row['picture'] ) ) {
				$lsv_migrator = LaSillaVaciaMigrator::get_instance();
				$image = [
					'FriendlyName' => basename( $row['picture'] ),
				];
				global $wpdb;
				$post_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_post_id' AND meta_value = %d",
						$row['id']
					)
				);

	            if ( $post_id ) {
//		            $lsv_migrator->handle_featured_image( $image, $row['id'], 0, '/tmp/media_content/bak_Media' );
		            $featured_image_id = $lsv_migrator->handle_featured_image( $image, $row['id'], 0, '/Users/edc598/Local_Sites/la-silla-vacia-complete-content/app/public/wp-content/Media' );

		            if ( $featured_image_id ) {
						$row['image_attachment_id'] = $featured_image_id;
						$row['image_attachment_src'] = wp_get_attachment_image_src( $featured_image_id, 'full' )[0];
		            }
	            }
            }

			$row['book_university'] = '';
			foreach ( $row['categories'] as &$category ) {
				$term = get_term_by( 'term_taxonomy_id', $category['term_taxonomy_id'] );

				$category['category'] = $term->term_id;

				if ( str_contains( $category['name'], 'universi' ) ) {

					$link = $category['name'];

					if ( $term ) {
						$category_link = get_category_link( $term->term_id );
						$link = '<a href="' . $category_link . '">' . $category['name'] . '</a>';
					}

					$row['book_university'] = '<!-- wp:paragraph -->' .
					                          '<p>' . $link . '</p>' .
											'<!-- /wp:paragraph -->';
				}
			}

			$row['tags'] = array_map( function( $tag ) {
				$tag['tag'] = $tag['name'];

				return $tag;
			}, $row['tags'] );
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