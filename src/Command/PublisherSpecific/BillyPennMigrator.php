<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;
use WP_Query;

/**
 * Custom migration scripts for LkldNow.
 */
class BillyPennMigrator implements InterfaceCommand {

	private $content_diff_ids_mappings_file;

	private $content_diff_ids;

	/**
	 * Instance of BillyPennMigrator
	 * 
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Instance of \Logic\SimpleLocalAvatars
	 * 
	 * @var null|SimpleLocalAvatars Instance.
	 */
	private $sla_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->sla_logic = new SimpleLocalAvatars();
		$this->content_diff_ids_mappings_file = ABSPATH . '/ids.csv';
		$this->content_diff_ids = array();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator billypenn-create-taxonomies',
			[ $this, 'cmd_billypenn_create_taxonomies' ],
			[
				'shortdesc' => 'Create taxonomies from Stories, Clusters, People etc.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator billypenn-migrate-users-avatars',
			[ $this, 'cmd_billypenn_migrate_users_avatars' ],
			[
				'shortdesc' => 'Migrate the users\' avatars.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator billypenn-migrate-users-bios',
			[ $this, 'cmd_billypenn_migrate_users_bios' ],
			[
				'shortdesc' => 'Migrate the users\' bios.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator billypenn-convert-img-shortcodes',
			[ $this, 'cmd_billypenn_convert_img_shortcodes' ],
			[
				'shortdesc' => 'Convert [img] shortcodes to blocks.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator billypenn-convert-img-shortcodes`.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_billypenn_convert_img_shortcodes( $args, $assoc_args ) {
		global $wpdb;

		$args = array(
			'post_type'      => array( 'post', 'page' ),
			'posts_per_page' => -1,
			'post_status'    => 'publish,draft',
		);
		
		$query = new WP_Query( $args );

		$posts = $query->posts;

		$shortcode_pattern = '/\[img[^\]]+\]/';

		foreach ( $posts as $post ) {
			$found = preg_match_all( $shortcode_pattern, $post->post_content, $matches );
			
			if ( $found == 0 ) {
				continue;
			}

			WP_CLI::log( sprintf( 'Replacing %d img shortcodes in post #%d', count( $matches[0] ), $post->ID ) );

			$searches = array();
			$replaces = array();

			foreach ( $matches[0] as $match ) {
				$shortcode = $this->fix_shortcode( urldecode( $match ) );
				$shortcode_atts = shortcode_parse_atts( $shortcode );

				if ( isset( $shortcode_atts['attachment'] ) ) {
					$attachment_id = $shortcode_atts['attachment'];
				} else if ( isset( $shortcode_atts['src'] ) ) {
					$attachment_id = attachment_url_to_postid( $shortcode_atts['src'] );
				}

				if ( isset( $shortcode_atts['src'] ) ) {
					$image_url = $shortcode_atts['src'];
				} else if ( $attachment_id ) {
					$image_url = wp_get_attachment_url( $attachment_id );
				} else {
					$image_url = null;
				}

				if ( isset( $shortcode_atts['url'] ) ) {
					$link = $shortcode_atts['url'];
				} else if ( isset( $shortcode_atts['href'] ) ) {
					$link = $shortcode_atts['href'];
				} else {
					$link = null;
				}

				$credit = array();

				if ( isset( $shortcode_atts['credit'] ) ) {
					$credit['credit'] = urldecode( $shortcode_atts['credit'] );	
				}

				if ( isset( $shortcode_atts['credit_link'] ) ) {
					$credit['url'] = urldecode( $shortcode_atts['credit_link'] );	
				}

				if ( ! empty( $credit ) && $attachment_id ) {
					WP_CLI::log( sprintf( 'Adding image credits to attachment #%d...', $attachment_id ) );
					$this->add_image_credits( $attachment_id, $credit );
				}

				$block_args = array(
					'align' => isset( $shortcode_atts['align'] ) ? $shortcode_atts['align'] : NULL,
					'sizeSlug' => isset( $shortcode_atts['size'] ) ? $shortcode_atts['size'] : NULL,
					'linkDestination' => isset( $shortcode_atts['linkto'] ) ? $shortcode_atts['linkto'] : NULL,
					'alt' => isset( $shortcode_atts['alt'] ) ? $shortcode_atts['alt'] : NULL,
					'caption' => isset( $shortcode_atts['caption'] ) ? $shortcode_atts['caption'] : NULL,
					'href' => $link,
					'url' => $image_url,
					'id' => $attachment_id,
				);

				$block = $this->generate_image_block( $block_args );

				$searches[] = sprintf( "<!-- wp:shortcode -->\n%s\n<!-- /wp:shortcode -->", $match );
				$searches[] = $match;

				$replaces[] = $block;
				$replaces[] = $block;
			}

			$new_content = str_replace( $searches, $replaces, $post->post_content );

			$wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => $post->ID ) );
		}
	}

	/**
	 * Callable for `newspack-content-migrator billypenn-migrate-users-bios`.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_billypenn_migrate_users_bios( $args, $assoc_args ) {
		if ( ! $this->sla_logic->is_sla_plugin_active() ) {
			WP_CLI::warning( 'Simple Local Avatars not found. Install and activate it before using this command.' );
			return;
		}

		$users = get_users(
			array(
				'meta_key'     => 'user_bio_extended',
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $users as $user ) {
			$user_bio = get_user_meta( $user->ID, 'user_bio_extended', true );
			
			if ( ! $user_bio ) {
				continue;
			}

			WP_CLI::log( sprintf( 'Migrating bioography for user #%d', $user->ID ) );

			wp_update_user(
				array(
					'ID' => $user->ID,
					'description' => $user_bio,
				),
			);
		}
	}

	/**
	 * Callable for `newspack-content-migrator billypenn-migrate-users-avatars`.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_billypenn_migrate_users_avatars( $args, $assoc_args ) {
		if ( ! $this->sla_logic->is_sla_plugin_active() ) {
			WP_CLI::warning( 'Simple Local Avatars not found. Install and activate it before using this command.' );
			return;
		}

		$users = get_users(
			array(
				'meta_key'     => 'wp_user_img',
				'meta_compare' => 'EXISTS',
			)
		);

		foreach ( $users as $user ) {
			$avatar_id = get_user_meta( $user->ID, 'wp_user_img', true );
			
			if ( ! $avatar_id ) {
				continue;
			}

			WP_CLI::log( sprintf( 'Migrating avatar for user #%d', $user->ID ) );

			$this->sla_logic->import_avatar( $user->ID, $avatar_id );
		}

		WP_CLI::success( 'Done!' );
	}

	/**
	 * Callable for `newspack-content-migrator billypenn-create-taxonomies`.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_billypenn_create_taxonomies( $args, $assoc_args ) {
		$this->load_ids_mappings();

		$this->convert_posts_to_taxonomies( 'pedestal_story', 'category' );
		$this->convert_posts_to_taxonomies( 'pedestal_topic', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_place', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_person', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_org', 'post_tag' );
		$this->convert_posts_to_taxonomies( 'pedestal_locality', 'post_tag' );

		WP_CLI::success( 'Done!' );
	}

	/**
	 * Convert a custom post type to a taxonomy.
	 * 
	 * @param string $post_type The custom post type.
	 * @param string $taxonomy The taxonomy (category, post_tag etc.).
	 */
	public function convert_posts_to_taxonomies( $post_type, $taxonomy ) {
		global $wpdb;

		if ( 'category' != $taxonomy && 'post_tag' != $taxonomy ) {
			return false;
		}

		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
		);

		$query = new WP_Query( $args );
		$terms = $query->posts;

		foreach ( $terms as $term ) {
			$posts = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p2p_from FROM {$wpdb->prefix}p2p WHERE p2p_to = %d",
					$term->ID,
				),
			);

			$wp_term = get_term_by( 'name', $term->post_title, $taxonomy );		

			if ( false == $wp_term ) {
				WP_CLI::log( sprintf( 'Creating the term %s', $term->post_title ) );
			
				$new_term = wp_insert_term(
					$term->post_title,
					$taxonomy,
					array(
						'description' => $term->post_content,
					),
				);

				$term_id = $new_term['term_id'];
	
				if ( is_wp_error( $new_term ) ) {
					WP_CLI::warning( 'Could not create term...' );
					WP_CLI::warning( $new_term->get_error_message() );
				}
			} else {
				$term_id = $wp_term->term_id;
			}

			foreach ( $posts as $post_id ) {
				if ( isset( $this->content_diff_ids[ $post_id ] ) ) {
					$post_id = $this->content_diff_ids[ $post_id ];
				}
				WP_CLI::log( sprintf( 'Adding term to post #%d', $post_id ) );
				wp_set_post_terms( $post_id, array( $term_id ), $taxonomy, true );
			}
		}
	}

	public function add_image_credits( $attachment_id, $credit ) {
		if ( class_exists( '\Newspack\Newspack_Image_Credits' ) ) {
			// Get the meta keys from Newspack plugins.
			$credit_meta = \Newspack\Newspack_Image_Credits::MEDIA_CREDIT_META;
			$credit_url_meta = \Newspack\Newspack_Image_Credits::MEDIA_CREDIT_URL_META;
		} else {
			// Fall back for when Newspack is not installed.
			$credit_meta = '_media_credit';
			$credit_url_meta = '_media_credit_url';
		}

		if ( isset( $credit['credit'] ) ) {
			update_post_meta( $attachment_id, $credit_meta, $credit['credit'] );
		}

		if ( isset( $credit['url'] ) ) {
			update_post_meta( $attachment_id, $credit_url_meta, $credit['url'] );
		}
		
	}

	public function generate_image_block( $args ) {
		$block_html = <<<HTML
<!-- wp:image {"align":"%s","id": %d,"sizeSlug":"%s","linkDestination":"%s"} -->
<figure class="wp-block-image%s%s">%s<img src="%s" alt="%s"%s/>%s%s</figure>
<!-- /wp:image -->
HTML;

		$link_html = <<<HTML
<a href="%s">
HTML;


		$caption_html = <<<HTML
<figcaption class="wp-element-caption">%s</figcaption>
HTML;

		if ( $args['href'] ) {
			$href_start = sprintf( $link_html, $args['href'] );
			$href_end = '</a>';
		} else {
			$href_start = '';
			$href_end = '';
		}

		if ( $args['caption'] ) {
			$caption = sprintf( $caption_html, $args['caption'] );
		} else {
			$caption = '';
		}

		if ( $args['align'] ) {
			$align_class = ' ' . $args['align'];
			$align = str_replace( 'align', '',  $args['align'] );
		} else {
			$align_class = '';
			$align = '';
		}

		$block = sprintf(
			$block_html,
			$align,
			$args['id'],
			$args['sizeSlug'] ?? 'large',
			$args['linkDestination'] == 'file' ? 'media' : $args['linkDestination'],
			$align_class,
			$args['sizeSlug'] ? sprintf( ' size-%s', $args['sizeSlug'] ) : '',
			$href_start,
			$args['url'],
			$args['alt'],
			$args['id'] ? sprintf( ' class="wp-image-%d"', $args['id'] ) : '',
			$href_end,
			$caption,
		);

		return $block;
	}

	/**
	 * Make sure the shortcode is using quotation marks for attributes, instead of other special characters
	 */
	public function fix_shortcode( $shortcode ) {
		return strtr(
			html_entity_decode( $shortcode ),
			array(
				'”' => '"',
				'″' => '"',
			),
		);
	}

	public function load_ids_mappings() {
		if ( ! file_exists( $this->content_diff_ids_mappings_file ) ) {
			WP_CLI::error( 'Cant find mappings file.' );
		}

		$lines = file( $this->content_diff_ids_mappings_file );

		foreach ( $lines as $index => $line ) {
			if ( 0 == $index ) {
				continue;
			}

			$object = json_decode( $line );

			if ( ! $object ) {
				continue;
			}

			$this->content_diff_ids[ $object->id_new ] = $object->id_old;
		}
	}

	/**
	 * Simple file logging.
	 *
	 * @param string $file    File name or path.
	 * @param string $message Log message.
	 */
	public function log( $file, $message ) {
		$message .= "\n";
		file_put_contents( $file, $message, FILE_APPEND );
	}
}
