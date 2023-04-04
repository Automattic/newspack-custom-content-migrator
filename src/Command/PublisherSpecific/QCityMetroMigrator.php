<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use DOMElement;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use WP_CLI;

class QCityMetroMigrator implements InterfaceCommand {

	/**
	 * DublinInquirerMigrator Instance.
	 *
	 * @var DublinInquirerMigrator
	 */
	private static $instance;

	/**
	 * CoAuthorPlus instance.
	 *
	 * @var CoAuthorPlus|null CoAuthorPlus instance.
	 */
	protected ?CoAuthorPlus $coauthorplus;

	/**
	 * Get Instance.
	 *
	 * @return DublinInquirerMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance               = new $class();
			self::$instance->coauthorplus = new CoAuthorPlus();
		}

		return self::$instance;
	}

	/**
	 * @inheritDoc
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator qcity_metro_migrate_church_listings',
			[ $this, 'cmd_migrate_church_listings' ],
			[
				'shortdesc' => 'Migrate church listings from the old plugin format to the Newspack Listings.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator qcity_metro_migrate_business_listings',
			[ $this, 'cmd_migrate_business_listings' ],
			[
				'shortdesc' => 'Migrate business listings from the old plugin format to the Newspack Listings.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator qcity_metro_migrate_job_listings',
			[ $this, 'cmd_migrate_job_listings' ],
			[
				'shortdesc' => 'Migrate job listings from the old plugin format to the Newspack Listings.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator qcity_metro_migrate_xml_posts',
			[ $this, 'cmd_migrate_xml_posts' ],
			[
				'shortdesc' => 'Migrate posts from XML file.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'XML file to import.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post_type',
						'description' => 'Post type to import.',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 'post',
						'options'     => [
							'post',
							'attachment',
						],
					]
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator qcity_metro_fix_attachment_postmeta',
			[ $this, 'cmd_fix_attachment_data' ],
			[
				'shortdesc' => 'Fix attachment postmeta.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator qcity_metro_migrate_galleries',
			[ $this, 'cmd_migrate_galleries' ],
			[
				'shortdesc' => 'Migrate galleries from the old plugin format to the Newspack Galleries.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator qcity_metro_migrate_memberful_data',
			[ $this, 'cmd_qcity_metro_migrate_memberful_data' ],
			[
				'shortdesc' => 'Migrate Memberful data.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'file',
						'description' => 'CSV file to import.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * @throws Exception
	 */
	public function cmd_migrate_church_listings() {
		$post_content_template = '<!-- wp:paragraph -->'
		                         . '{website}{separator}{telephone} '
		                         . '<!-- /wp:paragraph -->'
		                         . '{address} '
		                         . '{church_special} '
		                         . '<!-- wp:paragraph -->'
		                         . '<p>Founded: {founded}</p>'
		                         . '<!-- /wp:paragraph -->';
		global $wpdb;

		$this->update_church_taxonomies();

		$parent_category_id = wp_create_category( 'Business Listing' );

		$church_listings_query = "SELECT 
       			ID, 
       			post_author, 
       			post_date, 
       			post_title, 
       			post_status, 
       			post_modified 
			FROM $wpdb->posts 
			WHERE post_type = 'church_listing'";

		$church_listings = $wpdb->get_results( $church_listings_query );

		foreach ( $church_listings as $church_listing ) {
			$meta = get_post_meta( $church_listing->ID );

			$date = new DateTime( $church_listing->post_date, new DateTimeZone( 'America/New_York' ) );
			$date->setTimezone( new DateTimeZone( 'GMT' ) );
			$modified_date = new DateTime( $church_listing->post_modified, new DateTimeZone( 'America/New_York' ) );
			$modified_date->setTimezone( new DateTimeZone( 'GMT' ) );

			$place_listing_data = [
				'post_title'        => $church_listing->post_title,
				'post_author'       => $church_listing->post_author,
				'post_status'       => $church_listing->post_status,
				'post_name'         => sanitize_title( $church_listing->post_title ),
				'post_date'         => $church_listing->post_date,
				'post_date_gmt'     => $date->format( 'Y-m-d H:i:s' ),
				'post_modified'     => $church_listing->post_modified,
				'post_modified_gmt' => $modified_date->format( 'Y-m-d H:i:s' ),
				'post_type'         => 'newspack_lst_place',
			];

			$website        = '';
			$telephone      = '';
			$address        = '';
			$founded        = '';
			$church_special = '';

			$child_category_ids = [];
			foreach ( $meta as $key => $value ) {
				if ( str_starts_with( $key, '_' ) ) {
					continue;
				}

				if ( str_starts_with( $key, 'services_' ) ) {
					$child_category_ids[] = wp_create_category( $value[0], $parent_category_id );
				}

				switch ( $key ) {
					case 'website':
						$website = $this->get_website_html( $value[0] );
						break;
					case 'phone':
						$telephone = $this->get_telephone_html( $value[0] );
						break;
					case 'address':
						$address = $this->get_address_html( $value[0] );
						break;
					case 'founded':
						$founded = $value[0];
						break;
					case 'church_special':
						$church_special = $this->get_church_special_html( $value[0] );
						break;
				}
			}

			$post_content = strtr(
				$post_content_template,
				[
					'{website}'        => $website,
					'{telephone}'      => $telephone,
					'{address}'        => $address,
					'{founded}'        => $founded,
					'{church_special}' => $church_special,
					'{separator}'      => ! empty( $website ) && ! empty( $telephone ) ? ' · ' : '',
				]
			);

			$place_listing_data['post_content'] = $post_content;

			$place_listing_id = wp_insert_post( $place_listing_data );

			if ( is_numeric( $place_listing_id ) ) {
				WP_CLI::log( 'Migrated church listing: ' . $church_listing->post_title );
				$this->coauthorplus->assign_guest_authors_to_post( [ 217640 ], $place_listing_id );

				wp_set_post_categories( $place_listing_id, $child_category_ids, true );

				$old_terms = $wpdb->get_results(
					"SELECT 
       						* 
						FROM $wpdb->terms t 
       					    INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
						WHERE tt.taxonomy IN ( 'denomination', 'size' ) 
						  AND tt.term_taxonomy_id IN ( 
						      SELECT 
						             term_taxonomy_id 
						      FROM $wpdb->term_relationships 
						      WHERE object_id = $church_listing->ID 
						      )"
				);

				foreach ( $old_terms as $old_term ) {
					$slug     = str_replace( '-old', '', $old_term->slug );
					$new_term = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM $wpdb->terms t 
								INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
								WHERE tt.taxonomy = 'post_tag'
								  AND tt.parent <> 0
								  AND t.slug = %s",
							$slug
						)
					);

					if ( $new_term ) {
						$wpdb->insert(
							$wpdb->term_relationships,
							[
								'object_id'        => $place_listing_id,
								'term_taxonomy_id' => $new_term->term_taxonomy_id,
							]
						);
					}
				}
			} else {
				WP_CLI::log( 'Failed to migrate church listing: ' . $church_listing->post_title );
			}
		}
	}

	public function cmd_migrate_business_listings() {
		$post_content_template = '<!-- wp:paragraph -->'
		                         . '<p>{website}{separator}{telephone}</p>'
		                         . '<!-- /wp:paragraph -->'
		                         . '{address}'
		                         . '{black_owned}';

		global $wpdb;

		$this->update_business_taxonomies();

		$business_listings_query = "SELECT
				ID, 
       			post_author, 
       			post_date, 
       			post_title, 
       			post_status, 
       			post_modified 
			FROM $wpdb->posts 
			WHERE post_type = 'business_listing'";

		$business_listings = $wpdb->get_results( $business_listings_query );

		foreach ( $business_listings as $business_listing ) {
			$place_listing_data = [
				'post_author'   => $business_listing->post_author,
				'post_date'     => $business_listing->post_date,
				'post_title'    => $business_listing->post_title,
				'post_status'   => $business_listing->post_status,
				'post_name'     => sanitize_title( $business_listing->post_title ),
				'post_modified' => $business_listing->post_modified,
				'post_type'     => 'newspack_lst_place',
			];

			$website     = '';
			$telephone   = '';
			$address     = '';
			$black_owned = '';

			$meta = get_post_meta( $business_listing->ID );

			$tag_taxonomy_ids = [];
			foreach ( $meta as $key => $value ) {
				switch ( $key ) {
					case 'website':
						$website = $this->get_website_html( $value[0] );
						break;
					case 'telephone':
						$telephone = $this->get_telephone_html( $value[0] );
						break;
					case 'address':
						$address = $this->get_address_html( $value[0] );
						break;
					case 'black_owned':
					case 'black_owned_business':
						$black_owned = $this->get_black_owned_html( $value[0] );
						break;
					case 'Number Employees':
					case 'Business also':
						if ( false !== @unserialize( $value[0] ) ) {
							$parts = unserialize( $value[0] );
							foreach ( $parts as $particle ) {
								$tag_taxonomy_ids[] = intval( wp_create_tag( $particle )['term_taxonomy_id'] );
							}
						} else {
							if ( ! empty( $value[0] ) ) {
								$tag_taxonomy_ids[] = intval( wp_create_tag( $value[0] )['term_taxonomy_id'] );
							}
						}
						break;
				}
			}

			$post_content = strtr(
				$post_content_template,
				[
					'{website}'     => $website,
					'{telephone}'   => $telephone,
					'{address}'     => $address,
					'{black_owned}' => $black_owned,
					'{separator}'   => ! empty( $website ) && ! empty( $telephone ) ? ' · ' : '',
				]
			);

			$place_listing_data['post_content'] = $post_content;

			$place_listing_id = wp_insert_post( $place_listing_data );

			if ( is_numeric( $place_listing_id ) ) {
				WP_CLI::log( 'Migrated business listing: ' . $business_listing->post_title );
				$this->coauthorplus->assign_guest_authors_to_post( [ 217633 ], $place_listing_id );

				$old_related_categories = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT
								*
							FROM $wpdb->terms t
								INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
								INNER JOIN $wpdb->term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
							WHERE tr.object_id = %d
								AND tt.taxonomy = 'business_category'",
						intval( $business_listing->ID )
					)
				);

				foreach ( $old_related_categories as $old_category ) {
					$new_slug     = str_replace( '-old', '', $old_category->slug );
					$new_category = get_category_by_slug( $new_slug );
					if ( $new_category ) {
						wp_set_post_categories( $place_listing_id, [ $new_category->term_id ], true );
					}
				}

				$wpdb->update(
					$wpdb->postmeta,
					[
						'meta_key' => '_thumbnail_id',
					],
					[
						'post_id'  => $business_listing->ID,
						'meta_key' => 'business_thumbnail',
					]
				);

				if ( ! empty( $tag_taxonomy_ids ) ) {
					foreach ( $tag_taxonomy_ids as $tag_id ) {
						$wpdb->insert(
							$wpdb->term_relationships,
							[
								'object_id'        => $place_listing_id,
								'term_taxonomy_id' => $tag_id,
								'term_order'       => 0,
							]
						);
					}
				}
			} else {
				WP_CLI::log( 'Failed to migrate business listing: ' . $business_listing->post_title );
			}
		}
	}

	public function cmd_migrate_job_listings() {
		$post_content_template = '<!-- wp:group {"backgroundColor":"light-gray","layout":{"type":"constrained"}} -->'
		                         . '<div class="wp-block-group has-light-gray-background-color has-background">'
		                         . '<!-- wp:paragraph -->'
		                         . '<p>{company_name}</p>'
		                         . '<!-- /wp:paragraph -->'
		                         . '</div>'
		                         . '<!-- /wp:group -->'
		                         . '<!-- wp:paragraph -->'
		                         . '<p>{job_title}</p>'
		                         . '<!-- /wp:paragraph -->'
		                         . ' <!-- wp:paragraph -->'
		                         . '<p>{job_description}</p>'
		                         . '<!-- /wp:paragraph -->'
		                         . '<!-- wp:buttons -->'
		                         . '<div class="wp-block-buttons">'
		                         . '<!-- wp:button -->'
		                         . '<div class="wp-block-button">'
		                         . '{apply_link}'
		                         . '</div>'
		                         . '<!-- /wp:button -->'
		                         . '</div>'
		                         . '<!-- /wp:buttons -->';

		$this->update_job_taxonomies();

		global $wpdb;

		$job_listings = $wpdb->get_results(
			"SELECT 
	   				ID, 
	                post_author, 
	                post_date, 
	                post_title, 
	                post_status, 
	                post_modified 
				FROM $wpdb->posts p 
				WHERE p.post_type = 'job'"
		);

		foreach ( $job_listings as $job ) {
			$generic_listing_data = [
				'post_author'   => $job->post_author,
				'post_date'     => $job->post_date,
				'post_title'    => $job->post_title,
				'post_status'   => $job->post_status,
				'post_name'     => sanitize_title( $job->post_title ),
				'post_modified' => $job->post_modified,
				'post_type'     => 'newspack_lst_generic',
			];

			$company_name    = '';
			$job_title       = '';
			$job_description = '';
			$apply_link      = '';

			$meta = get_post_meta( $job->ID );

			foreach ( $meta as $key => $value ) {
				switch ( $key ) {
					case 'company_name':
						$company_name = $value[0];
						break;
					case 'job_title':
						$job_title = $value[0];
						break;
					case 'job_description':
						$job_description = $value[0];
						break;
					case 'how_to_apply':
						if ( 'direct' === $value[0] ) {
							$apply_link = $this->get_apply_button_html( $meta['application_direct'][0], 'direct' );
						} else if ( 'email' === $value[0] ) {
							$apply_link = $this->get_apply_button_html( $meta['application_email'][0], 'email' );
						}
						break;
				}
			}

			$post_content = strtr(
				$post_content_template,
				[
					'{company_name}'    => $company_name,
					'{job_title}'       => $job_title,
					'{job_description}' => $job_description,
					'{apply_link}'      => $apply_link,
				]
			);

			$generic_listing_data['post_content'] = $post_content;

			$generic_listing_id = wp_insert_post( $generic_listing_data );

			if ( is_numeric( $generic_listing_id ) ) {
				WP_CLI::log( 'Migrated job listing: ' . $job->post_title );
				$this->coauthorplus->assign_guest_authors_to_post( [ 217626 ], $generic_listing_id );

				$old_related_categories = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM $wpdb->terms t 
							INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
							INNER JOIN $wpdb->term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id 
						WHERE tr.object_id = %d AND tt.taxonomy = 'job_cat'",
						$job->ID
					)
				);

				foreach ( $old_related_categories as $old_category ) {
					$new_slug     = str_replace( '-old', '', $old_category->slug );
					$new_category = get_category_by_slug( $new_slug );
					if ( $new_category ) {
						wp_set_post_categories( $generic_listing_id, [ $new_category->term_id ], true );
					}
				}

				$old_related_job_level_categories = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM $wpdb->terms t 
							INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
							INNER JOIN $wpdb->term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id 
						WHERE tr.object_id = %d AND tt.taxonomy = 'level'",
						$job->ID
					)
				);

				foreach ( $old_related_job_level_categories as $old_category ) {
					$new_slug = str_replace( '-old', '', $old_category->slug );
					$new_tag  = get_term_by( 'slug', $new_slug, 'post_tag' );
					if ( $new_tag ) {
						wp_set_post_tags( $generic_listing_id, [ $new_tag->term_id ], true );
					}
				}

				$wpdb->update(
					$wpdb->postmeta,
					[
						'post_id'  => $generic_listing_id,
						'meta_key' => '_thumbnail_id',
					],
					[
						'post_id'  => $job->ID,
						'meta_key' => 'image',
					]
				);
			} else {
				WP_CLI::log( 'Failed to migrate job listing: ' . $job->post_title );
			}
		}
	}

	public function cmd_migrate_xml_posts( $args, $assoc_args ) {
		$file_path = $args[0];
		$file      = file_get_contents( $file_path );

		$post_type = $assoc_args['post_type'];

		$dom = new \DOMDocument();
		$dom->loadXML( $file, LIBXML_PARSEHUGE );

		$channel = $dom->getElementsByTagName( 'channel' )->item( 0 );

		$authors = [];

		foreach ( $channel->childNodes as $child ) {
			switch ( $child->nodeName ) {
				case 'wp:author':
					$author                      = $this->handle_xml_author( $child );
					$authors[ $author['login'] ] = $author['id'];
					break;
				case 'item':
					$this->handle_xml_post( $child, $authors, $post_type );
					break;
			}
		}
	}

	public function cmd_fix_attachment_data() {

		global $wpdb;

		$post_ids = $wpdb->get_results( "SELECT pm.post_id FROM $wpdb->postmeta pm INNER JOIN $wpdb->posts p ON p.ID = pm.post_id WHERE pm.meta_key = 'original_post_id' AND p.post_type = 'attachment'" );

		$count_of_posts = count( $post_ids );

		WP_CLI::log( 'Found ' . $count_of_posts . ' posts to fix' );
		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( 'Fixing post ' . $post_id->post_id );

			$meta = wp_get_attachment_metadata( $post_id->post_id );

			WP_CLI::log( 'Meta: ' . print_r( $meta, true ) );

			if ( is_array( $meta ) ) {
				WP_CLI::log( 'Is already array.' );
				continue;
			}

			if ( false !== @unserialize( $meta ) ) {
				WP_CLI::log( 'Needs to be updated.' );
				$meta = unserialize( $meta );
				$wpdb->update(
					$wpdb->postmeta,
					[
						'meta_value' => serialize( $meta ),
					],
					[
						'meta_key' => '_wp_attachment_metadata',
						'post_id'  => $post_id->post_id,
					]
				);
			}
		}
	}

	public function cmd_migrate_galleries() {
		$gutenberg_block_generator = new GutenbergBlockGenerator();

		global $wpdb;

		$gallery_posts = $wpdb->get_results( "SELECT * FROM $wpdb->posts WHERE post_type = 'gallery'" );

		foreach ( $gallery_posts as $gallery_post ) {
			$post_data = [
				'post_author'       => $gallery_post->post_author,
				'post_date'         => $gallery_post->post_date,
				'post_date_gmt'     => $gallery_post->post_date_gmt,
				'post_title'        => $gallery_post->post_title,
				'post_excerpt'      => $gallery_post->post_excerpt,
				'post_status'       => $gallery_post->post_status,
				'comment_status'    => $gallery_post->comment_status,
				'ping_status'       => $gallery_post->ping_status,
				'post_name'         => $gallery_post->post_name,
				'post_modified'     => $gallery_post->post_modified,
				'post_modified_gmt' => $gallery_post->post_modified_gmt,
				'post_type'         => 'post',
			];

			$attachment_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key LIKE 'photos_%'",
					$gallery_post->ID
				)
			);

			$jetpack_slideshow = $gutenberg_block_generator->get_jetpack_slideshow( $attachment_ids )['innerHTML'];
			$attributes = [
				'ids' => array_map( fn( $attachment_id ) => intval( $attachment_id ), $attachment_ids ),
				'sizeSlug' => 'large',
			];
			$json_attributes = wp_json_encode( $attributes );

			$content                   = "<!-- wp:jetpack/slideshow $json_attributes -->$jetpack_slideshow<!-- /wp:jetpack/slideshow --><br>$gallery_post->post_content";
			$post_data['post_content'] = $content;

			$new_post_id = wp_insert_post( $post_data );

			if ( is_numeric( $new_post_id ) ) {
				WP_CLI::log( 'Migrated gallery ' . $gallery_post->post_title . ' to post ' . $new_post_id );
			} else {
				WP_CLI::log( 'Failed to migrate gallery ' . $gallery_post->post_title );
			}
		}
	}

	/**
	 * Migrating memberful data to woocommerce.
	 *
	 * @param array $args Positional Arguments.
	 * @param array $assoc_args Associative Arguments.
	 *
	 * @throws Exception Throws exception if file is not found.
	 */
	public function cmd_qcity_metro_migrate_memberful_data( $args, $assoc_args ) {
		$file_path = $args[0];

		$iterator = ( new FileImportFactory() )->get_file( $file_path )->getIterator();

		$new_woo_data = [];

		foreach ( $iterator as $row_number => $row ) {
			WP_CLI::log( 'Row Number: ' . $row_number + 1 . ' - ' . $row['email'] );
			$data = [];

			$full_name_parts = explode( ' ', $row['full_name'] );
			$last_name       = array_pop( $full_name_parts );
			$first_name      = implode( ' ', $full_name_parts );

			$status = 'wc-expired';

			if ( 'yes' === strtolower( $row['active'] ) ) {
				$status = 'wc-active';
			} elseif ( 'no' === strtolower( $row['active'] ) ) {
				$status = 'wc-cancelled';
			}

			$row_plan       = strtolower( trim( $row['plan'] ) );
			$billing_period = '';
			$order_item     = null;

			if ( 'two-year membership' === $row_plan ) {
				$billing_period = 'year';
				$order_item     = 223057;
			} elseif ( 'one-year membership' === $row_plan ) {
				$billing_period = 'year';
				$order_item     = 223057;
			} elseif ( 'pay monthly' === $row_plan ) {
				$billing_period = 'month';
				$order_item     = 223056;
			} elseif ( 'one-time donor' === $row_plan ) {
				$billing_period  = 'month';
				$status          = 'wc-expired';
				$row['end_date'] = $row['created_at'];
				$order_item      = 223053;
			}

			if ( empty( $billing_period ) ) {
				WP_CLI::error( 'Unabled to determine billing period. Row: ' . print_r( $row, true ) );
			}

			$payment_method_post_meta_data = [
				'_stripe_customer_id' => $row['stripe_customer_id'],
			];
			array_walk(
				$payment_method_post_meta_data,
				function ( $key, $value ) {
					return $key . ':' . $value;
				}
			);

			$data['customer_email']           = $row['email'];
			$data['billing_first_name']       = $row['first_name'] ?? $first_name;
			$data['billing_last_name']        = $row['last_name'] ?? $last_name;
			$data['billing_address_1']        = $row['address'];
			$data['billing_address_2']        = '';
			$data['billing_city']             = $row['city'];
			$data['billing_state']            = $row['state'];
			$data['billing_postcode']         = $row['zip_postal'];
			$data['billing_country']          = $row['country'];
			$data['subscription_status']      = $status;
			$data['start_date']               = $row['created_at'];
			$data['next_payment_date']        = $row['expiration_date'];
			$data['billing_period']           = $billing_period;
			$data['billing_interval']         = '1';
			$data['order_items']              = $order_item;
			$data['order_total']              = $row['total_spend'];
			$data['order_notes']              = 'Memberful ID: ' . $row['memberful_id'] . ';';
			$data['payment_method']           = 'one-time donor' === $row_plan ? '' : 'stripe';
			$data['payment_method_title']     = 'Online payment';
			$data['payment_method_post_meta'] = implode( '|', $payment_method_post_meta_data );
			$data['_custom_field']            = $row['custom_field'];
			$data['_referrer']                = $row['referrer'];
			$data['_utm_campaign']            = $row['utm_campaign'];
			$data['_utm_content']             = $row['utm_content'];
			$data['_utm_medium']              = $row['utm_medium'];
			$data['_utm_source']              = $row['utm_source'];
			$data['_utm_term']                = $row['utm_term'];

			$new_woo_data[] = $data;
		}

		$header = array_keys( $new_woo_data[0] );
		array_unshift( $new_woo_data, $header );

		$fp = fopen( 'woo_data.csv', 'w' );
		foreach ( $new_woo_data as $new_row ) {
			fputcsv( $fp, $new_row );
		}
		fclose( $fp );
	}

	private function handle_xml_author( DOMElement $author ) {
		$author_data = [
			'login'        => '',
			'email'        => '',
			'display_name' => '',
			'first_name'   => '',
			'last_name'    => '',
		];

		WP_CLI::log( 'Handing Author' );

		foreach ( $author->childNodes as $child ) {
			switch ( $child->nodeName ) {
				case 'wp:author_login':
					$author_data['login'] = $child->nodeValue;
					break;
				case 'wp:author_email':
					$author_data['email'] = $child->nodeValue;
					break;
				case 'wp:author_display_name':
					$author_data['display_name'] = $child->nodeValue;
					break;
				case 'wp:author_first_name':
					$author_data['first_name'] = $child->nodeValue;
					break;
				case 'wp:author_last_name':
					$author_data['last_name'] = $child->nodeValue;
					break;
			}
		}

		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->users WHERE user_email = %s",
				$author_data['email']
			)
		);

		if ( ! is_numeric( $user_id ) ) {
			// Try using login
			$user_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->users WHERE user_login = %s",
					$author_data['login']
				)
			);
		}

		if ( ! is_numeric( $user_id ) ) {
			$user_id = wp_insert_user(
				[
					'user_login'   => $author_data['login'],
					'user_email'   => $author_data['email'],
					'display_name' => $author_data['display_name'],
					'first_name'   => $author_data['first_name'],
					'last_name'    => $author_data['last_name'],
					'user_pass'    => wp_generate_password(),
				]
			);
		}

		if ( is_wp_error( $user_id ) ) {
			WP_CLI::error( $user_id->get_error_message() );

			return false;
		}

		$author_data['id'] = $user_id;

		WP_CLI::log( 'Author Login:' . $author_data['login'] . ' Author ID: ' . $user_id );

		return $author_data;
	}

	private function handle_xml_post( DOMElement $post, array $authors, string $post_type = 'post' ) {
		$post_data = [
			'post_title'     => '',
			'post_content'   => '',
			'post_date'      => '',
			'post_status'    => '',
			'post_author'    => '',
			'post_type'      => '',
			'post_name'      => '',
			'post_excerpt'   => '',
			'comment_status' => '',
			'post_modified'  => '',
			//'post_parent' => '',
			'guid'           => '',
			'post_category'  => [],
			'tags_input'     => [],
			'tax_input'      => [],
			'meta_input'     => [],
		];

		foreach ( $post->childNodes as $child ) {
			/* @var DOMElement $child */
			switch ( $child->nodeName ) {
				case 'wp:post_id':
					$post_data['meta_input']['original_post_id'] = $child->nodeValue;
					break;
				case 'title':
					$post_data['post_title'] = $child->nodeValue;
					break;
				case 'dc:creator':
					$post_data['post_author'] = $authors[ $child->nodeValue ] ?? 0;
					break;
				case 'content:encoded':
					$post_data['post_content'] = $child->nodeValue;
					break;
				case 'wp:post_date':
					$post_data['post_date'] = $child->nodeValue;
					break;
				case 'wp:status':
					$post_data['post_status'] = $child->nodeValue;
					break;
				case 'wp:post_name':
					$post_data['post_name'] = $child->nodeValue;
					break;
				case 'wp:post_type':
					$post_data['post_type'] = $child->nodeValue;
					break;
				case 'wp:post_parent':
					$post_data['post_parent'] = $child->nodeValue;
					break;
				case 'wp:post_id':
					$post_data['guid'] = $child->nodeValue;
					break;
				case 'wp:postmeta':
					$meta_key = $meta_value = '';
					foreach ( $child->childNodes as $meta ) {
						switch ( $meta->nodeName ) {
							case 'wp:meta_key':
								$meta_key = $meta->nodeValue;
								break;
							case 'wp:meta_value':
								$meta_value = $meta->nodeValue;
								break;
						}
					}

					if ( '_thumbnail_id' === $meta_key ) {
						global $wpdb;

						$attachment_id = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_post_id' AND meta_value = %d",
								intval( $meta_value )
							)
						);

						if ( is_numeric( $attachment_id ) ) {
							$post_data['meta_input'][ $meta_key ] = $attachment_id;
						}
					} else {
						$post_data['meta_input'][ $meta_key ] = $meta_value;
					}
					break;
				case 'category':
					$domain   = $child->getAttribute( 'domain' );
					$nicename = $child->getAttribute( 'nicename' );

					if ( 'category' === $domain ) {
						$category = get_term_by( 'slug', $nicename, 'category', ARRAY_A );

						if ( false === $category ) {
							$category = wp_insert_term( $child->nodeValue, 'category', [ 'slug' => $nicename ] );
						}

						$post_data['post_category'][] = $category['term_id'];

					} elseif ( 'post_tag' === $domain ) {
						$tag = get_term_by( 'slug', $nicename, 'post_tag', ARRAY_A );

						if ( false === $tag ) {
							$tag = wp_insert_term( $child->nodeValue, 'post_tag', [ 'slug' => $nicename ] );
						}

						$post_data['tags_input'][] = $tag['term_id'];
					}

					break;
				case 'wp:post_date_gmt':
					$post_data['post_modified'] = $child->nodeValue;
					break;
				case 'excerpt:encoded':
					$post_data['post_excerpt'] = $child->nodeValue;
					break;
				case 'wp:comment_status':
					$post_data['comment_status'] = $child->nodeValue;
					break;
			}
		}

		global $wpdb;
		// Check if post is duplicate
		$duplicate_post = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = '%s' AND post_title = %s AND post_date = %s",
				$post_type,
				$post_data['post_title'],
				$post_data['post_date']
			)
		);

		if ( is_numeric( $duplicate_post ) ) {
			$post_data['tags_input'][] = (int) wp_create_tag( 'Duplicate' )['term_id'];
		}

		// Check if possible duplicate
		$possible_duplicate_post = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_type = '%s' AND post_title = %s",
				$post_type,
				$post_data['post_title']
			)
		);

		if ( ! is_numeric( $duplicate_post ) && is_numeric( $possible_duplicate_post ) ) {
			$post_data['tags_input'][] = (int) wp_create_tag( 'Possible Duplicate' )['term_id'];
		}

		$post_id = wp_insert_post( $post_data );

		if ( is_numeric( $post_id ) ) {
			WP_CLI::log( "Inserted $post_type " . $post_id );
		} else {
			WP_CLI::warning( "Failed to insert $post_type " . $post_data['post_title'] );
		}
	}

	private function update_church_taxonomies() {
		global $wpdb;

		$denomination_parent_tag_id = wp_create_tag( 'Denomination' );
		$size_parent_tag_id         = wp_create_tag( 'Size' );

		// Get Deonominations.
		$denomination_terms = $wpdb->get_results(
			"SELECT 
       				* 
				FROM $wpdb->terms t 
				    INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
				WHERE tt.taxonomy = 'denomination'"
		);

		foreach ( $denomination_terms as $term ) {
			if ( str_ends_with( $term->slug, '-old' ) ) {
				continue;
			}

			$wpdb->update(
				$wpdb->terms,
				[
					'slug' => $term->slug . '-old',
				],
				[
					'term_id' => $term->term_id,
				]
			);

			wp_insert_term(
				$term->name,
				'post_tag',
				[
					'slug'   => $term->slug,
					'parent' => $denomination_parent_tag_id,
				]
			);
		}

		// Get size.
		$size_terms = $wpdb->get_results(
			"SELECT 
	   				* 
				FROM $wpdb->terms t 
				    INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
				WHERE tt.taxonomy = 'size'"
		);

		foreach ( $size_terms as $term ) {
			if ( str_ends_with( $term->slug, '-old' ) ) {
				continue;
			}

			$wpdb->update(
				$wpdb->terms,
				[
					'slug' => $term->slug . '-old',
				],
				[
					'term_id' => $term->term_id,
				]
			);

			wp_insert_term(
				$term->name,
				'post_tag',
				[
					'slug'   => $term->slug,
					'parent' => $size_parent_tag_id,
				]
			);
		}
	}

	private function update_business_taxonomies() {
		$business_listing_parent_category_id = wp_create_category( 'Business Listing' );

		global $wpdb;

		$business_listing_terms = $wpdb->get_results(
			"SELECT 
	   				* 
				FROM $wpdb->terms t 
				    INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
				WHERE tt.taxonomy = 'business_category'"
		);

		foreach ( $business_listing_terms as $term ) {
			if ( str_ends_with( $term->slug, '-old' ) ) {
				continue;
			}

			$wpdb->update(
				$wpdb->terms,
				[
					'slug' => $term->slug . '-old',
				],
				[
					'term_id' => $term->term_id,
				]
			);

			wp_insert_term(
				$term->name,
				'category',
				[
					'slug'   => $term->slug,
					'parent' => $business_listing_parent_category_id,
				]
			);
		}
	}

	private function update_job_taxonomies() {
		$job_listing_parent_category_id = wp_create_category( 'Jobs' );

		global $wpdb;

		$job_listing_categories = $wpdb->get_results(
			"SELECT 
	   				* 
				FROM $wpdb->terms t 
				    INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
				WHERE tt.taxonomy = 'job_cat'"
		);

		foreach ( $job_listing_categories as $category ) {
			if ( str_ends_with( $category->slug, '-old' ) ) {
				continue;
			}

			$wpdb->update(
				$wpdb->terms,
				[
					'slug' => $category->slug . '-old',
				],
				[
					'term_id' => $category->term_id,
				]
			);

			wp_insert_term(
				$category->name,
				'category',
				[
					'slug'   => $category->slug,
					'parent' => $job_listing_parent_category_id,
				]
			);
		}

		$job_listing_parent_tag_id = wp_create_tag( 'Job Level' );

		$job_listing_levels = $wpdb->get_results(
			"SELECT 
	   				* 
				FROM $wpdb->terms t 
				    INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
				WHERE tt.taxonomy = 'level'"
		);

		foreach ( $job_listing_levels as $level ) {
			if ( str_ends_with( $level->slug, '-old' ) ) {
				continue;
			}

			$wpdb->update(
				$wpdb->terms,
				[
					'slug' => $level->slug . '-old',
				],
				[
					'term_id' => $level->term_id,
				]
			);

			wp_insert_term(
				$level->name,
				'post_tag',
				[
					'slug'   => $level->slug,
					'parent' => $job_listing_parent_tag_id,
				]
			);
		}
	}

	private function get_website_html( $url ) {
		if ( empty( $url ) ) {
			return '';
		}

		return "<a href='$url' target='_blank' rel='noopener noreferrer'>Visit Web Site</a>";
	}

	private function get_telephone_html( $telephone ) {
		if ( empty( $telephone ) ) {
			return '';
		}

		$data_telephone = str_replace( [ '(', ')', ' ', '-' ], '', $telephone );

		return '<a href="tel:' . $data_telephone . '">' . $telephone . '</a>';
	}

	private function get_address_html( $address ) {
		if ( false !== @unserialize( $address ) ) {
			$address = unserialize( $address );
		}

		if ( is_string( $address ) ) {
			return "<!-- wp:paragraph --><p><address>$address</address></p><!-- /wp:paragraph -->";
		}

		if ( is_array( $address ) ) {
			$address_html = '<!-- wp:paragraph --><p><address>';
			$address_html .= $address['address'] . '<br>';
			$address_html .= '</address></p><!-- /wp:paragraph -->';

			return $address_html;
		}

		return '';
	}

	private function get_church_special_html( $church_special_content ) {
		if ( empty( $church_special_content ) ) {
			return '';
		}

		return '<!-- wp:paragraph -->'
		       . '<p>' . $church_special_content . '</p>'
		       . '<!-- /wp:paragraph -->';
	}

	private function get_black_owned_html( $black_owned ) {
		if ( empty( $black_owned ) ) {
			return '';
		}

		return '<!-- wp:paragraph -->'
		       . "<p>Black Owned: <strong>$black_owned</strong></p>"
		       . '<!-- /wp:paragraph -->';
	}

	private function get_apply_button_html( $apply, $type ) {
		if ( 'direct' === $type ) {
			return '<a class="wp-block-button__link wp-element-button" href="' . $apply . '">Apply</a>';
		}

		if ( 'email' === $type ) {
			return '<a class="wp-block-button__link wp-element-button" href="mailto:' . $apply . '">Apply</a>';
		}

		return '';
	}
}
