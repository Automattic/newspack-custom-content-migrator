<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific\CustomListingsFiles\LaSillaVacia;

use Newspack_Listings\Contracts\Importer_Mode;
use Newspack_Listings\Importer\Abstract_Callable_Post_Create;
use Newspack_Listings\Importer\WP_Post;

class LaSillaVaciaPostCreateEventImporter extends Abstract_Callable_Post_Create
{

    /**
     * @inheritDoc
     */
    protected function get_callable(): callable
    {
        return function( WP_Post $listing, Importer_Mode $importer_mode, array $row ) {
            add_post_meta( $listing->ID, 'original_article_id', $row['Id'] );
        };
    }
}