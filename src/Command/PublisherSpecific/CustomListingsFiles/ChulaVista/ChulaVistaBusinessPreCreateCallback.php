<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific\CustomListingsFiles\ChulaVista;

use Newspack_Listings\Importer\Abstract_Callable_Pre_Create;
use Newspack_Listings\Importer_Mode;

class ChulaVistaBusinessPreCreateCallback extends Abstract_Callable_Pre_Create {

	/**
	 * @inheritDoc
	 */
	protected function get_callable(): callable {
		return function ( array &$row, Importer_Mode $importer_mode ) {
			$row['wp_post.post_title'] = $row['name'];
			$row['business_url'] = '';

			if ( ! empty( $row['website'] ) ) {
				$row['business_url'] = "<!-- wp:paragraph --><p><a href='{$row['website']}'>Click Here for Business' Website</a></p><!-- /wp:paragraph --><br>";
			}

			$row['business_email'] = '';

			if ( ! empty( $row['email'] ) ) {
				$row['business_email'] = '<!-- wp:jetpack/email {"email":"' . $row['email'] . '"} -->
    <div class="wp-block-jetpack-email"><a href="mailto:' . $row['email'] . '">' . $row['email'] . '</a></div>
    <!-- /wp:jetpack/email -->';
			}

			$row['business_phone'] = '';
			if ( ! empty( $row['phone'] ) ) {
				$row['business_phone'] = '<!-- wp:jetpack/phone {"phone":"' . $row['phone'] . '"} -->
	<div class="wp-block-jetpack-phone"><a href="tel:' . preg_replace('/\D/', '', $row['phone']) . '">' . $row['phone'] . '</a></div>
	<!-- /wp:jetpack/phone -->';
			}

			$row['address_line_1'] = $row['address'];

			if ( ! empty( $row['address_2'] ) ) {
				$row['address_line_1'] .= ' ' . $row['address_2'];
			}
		};
	}
}