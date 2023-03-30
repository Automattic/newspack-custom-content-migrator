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

		WP_CLI::add_command(
			'newspack-content-migrator billypenn-find-remaining-shortcodes',
			[ $this, 'cmd_billypenn_find_remaining_shortcodes' ],
			[
				'shortdesc' => 'Find remaining shortcodes.',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator billypenn-fix-broken-youtube-shortcodes-and-love-letter-emojis',
			[ $this, 'cmd_fix_broken_youtube_shortcodes_and_love_letter_emojis' ],
			[
				'shortdesc' => 'Fix broken youtube shortcodes and love letter emojis.',
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

	public function cmd_billypenn_find_remaining_shortcodes() {
		global $wpdb;

		$file_path = '/tmp/affected_posts_with_shortcodes.log';
		file_put_contents( $file_path, '' );

		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID DESC"
		);

		$shortcodes_regex = get_shortcode_regex();
		$shortcodes_regex = str_replace( '|gallery|', '|gallery|instagram|twitter|', $shortcodes_regex );
//		$shortcodes_regex = '\[(\[?)(.?)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';

		$youtube_embed_template = '<!-- wp:embed {"url":"{url}","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
{url}
</div></figure>
<!-- /wp:embed -->';

		$iframe_embed_template = '<!-- wp:newspack-blocks/iframe {"src":"{url}"} /-->';

		$video_embed_template = '<!-- wp:video -->
<figure class="wp-block-video"><video controls src="{url}"></video></figure>
<!-- /wp:video -->';

		$facebook_embed_template = '<!-- wp:embed {"url":"{encoded_url}","type":"rich","providerNameSlug":"embed-handler","responsive":true,"previewable":false} -->
<figure class="wp-block-embed is-type-rich is-provider-embed-handler wp-block-embed-embed-handler"><div class="wp-block-embed__wrapper">
{url}
</div></figure>
<!-- /wp:embed -->';

		$instagram_embed_template = '<!-- wp:embed {"url":"{url}","type":"rich","providerNameSlug":"instagram","responsive":true} -->
<figure class="wp-block-embed is-type-rich is-provider-instagram wp-block-embed-instagram"><div class="wp-block-embed__wrapper">
{url}
</div></figure>
<!-- /wp:embed -->';

		$twitter_embed_template = '<!-- wp:embed {"url":"{url}","type":"rich","providerNameSlug":"twitter","responsive":true} -->
<figure class="wp-block-embed is-type-rich is-provider-twitter wp-block-embed-twitter"><div class="wp-block-embed__wrapper">
{url}
</div></figure>
<!-- /wp:embed -->';

		foreach ( $posts as $post ) {
				preg_match_all( "/$shortcodes_regex/", $post->post_content, $matches, PREG_SET_ORDER );

			$post_content = $post->post_content;

			if ( count( $matches ) > 0 ) {
				echo WP_CLI::colorize( "%wPost ID: {$post->ID}%n" ) . "\n";

				$youtube_shortcodes_replaced = 0;
				$iframe_shortcodes_replaced = 0;
				$video_shortcodes_replaced = 0;
				$facebook_shortcodes_replaced = 0;
				$instagram_shortcodes_replaced = 0;
				$twitter_shortcodes_replaced = 0;
				foreach ( $matches as $match ) {
					$shortcode = $this->fix_shortcode( $match[0] );

					if ( str_starts_with( $shortcode, '[iframe' ) ) {
						$attributes = shortcode_parse_atts( $shortcode );

						if ( isset( $attributes['src'] ) ) {
							$iframe_embed = strtr( $iframe_embed_template, [ '{url}' => $attributes['src'] ] );
							$post_content = str_replace( $match[0], $iframe_embed, $post_content );
							$iframe_shortcodes_replaced++;
						} else {
							echo WP_CLI::colorize( "%yOriginal: {$match[0]}%n" ) . "\n";
							WP_CLI::log( "Shortcode: $shortcode" );
						}
					} else if ( str_starts_with( $shortcode, '[youtube' ) ) {
						$video_id = $this->attempt_to_get_video_id( $shortcode );

						if ( $video_id ) {
							$youtube_embed = strtr( $youtube_embed_template, [ '{url}' => "https://youtu.be/$video_id" ] );
							$post_content = str_replace( $match[0], $youtube_embed, $post_content );
							$youtube_shortcodes_replaced++;
						} else {
							echo WP_CLI::colorize( "%yOriginal: {$match[0]}%n" ) . "\n";
							WP_CLI::log( "Shortcode: $shortcode" );
							echo WP_CLI::colorize( '%rCant find video id%n' ) . "\n";
						}
					} else if ( str_starts_with( $shortcode, '[video' ) ) {
						// regex to find any url within a string
						$pattern = '/https?:\/\/[^\s]+/';
						preg_match( $pattern, $shortcode, $url_matches );

						if ( isset( $url_matches[0] ) ) {
							$video_embed = strtr( $video_embed_template, [ '{url}' => $url_matches[0] ] );
							$post_content = str_replace( $match[0], $video_embed, $post_content );
							$video_shortcodes_replaced++;
						} else {
							echo WP_CLI::colorize( "%yOriginal: {$match[0]}%n" ) . "\n";
							WP_CLI::log( "Shortcode: $shortcode" );
							echo WP_CLI::colorize( '%rCant find video url%n' ) . "\n";
						}

					} else if ( str_starts_with( $shortcode, '[facebook' ) ) {
						$attributes = shortcode_parse_atts( $shortcode );

						if ( ! isset( $attributes['url'] ) ) {
							$shortcode = str_replace( [ '/]', ']' ], [ ' /]', ' /]' ], $shortcode );
							$attributes = shortcode_parse_atts( $shortcode );
						}

						if ( isset( $attributes['url'] ) ) {
							$decoded_url = urldecode( trim( $attributes['url'] ) );
							$facebook_embed = strtr(
								$facebook_embed_template,
								[
									'{url}' => htmlspecialchars( $decoded_url ),
									'{encoded_url}' => $decoded_url,
								]
							);
							$post_content = str_replace( $match[0], $facebook_embed, $post_content );
							$facebook_shortcodes_replaced++;
						} else {
							echo WP_CLI::colorize( "%yOriginal: {$match[0]}%n" ) . "\n";
							WP_CLI::log( "Shortcode: $shortcode" );
						}
					} else if ( str_starts_with( $shortcode, '[instagram' ) ) {
						$attributes = shortcode_parse_atts( $shortcode );

						if ( ! isset( $attributes['url'] ) ) {
							$shortcode = str_replace( [ '/]', ']' ], [ ' /]', ' /]' ], $shortcode );
							$attributes = shortcode_parse_atts( $shortcode );
						}

						if ( isset( $attributes['url'] ) ) {
							$decoded_url = urldecode( trim( $attributes['url'] ) );
							$instagram_embed = strtr(
								$instagram_embed_template,
								[
									'{url}' => htmlspecialchars( $decoded_url ),
								]
							);
							$post_content = str_replace( $match[0], $instagram_embed, $post_content );
							$instagram_shortcodes_replaced++;
						} else {
							echo WP_CLI::colorize( "%yOriginal: {$match[0]}%n" ) . "\n";
							WP_CLI::log( "Shortcode: $shortcode" );
						}
					} else if ( str_starts_with( $shortcode, '[twitter' ) ) {
						$attributes = shortcode_parse_atts( $shortcode );

						if ( ! isset( $attributes['url'] ) ) {
							$shortcode = str_replace( [ '/]', ']' ], [ ' /]', ' /]' ], $shortcode );
							$attributes = shortcode_parse_atts( $shortcode );
						}

						/*if ( ! isset( $attributes['url'] ) ) {
							$after_host = substr( $shortcode, strpos( $shortcode, 'twitter.com' ) + 11 );
							$after_host = substr( $after_host, 0, -2 );
							$after_host = str_replace( ' ', '', $after_host );
							$shortcode = substr( $shortcode, 0, strpos( $shortcode, 'twitter.com' ) + 11 ) . $after_host . ' /]';
							$attributes = shortcode_parse_atts( $shortcode );
						}*/

						if ( isset( $attributes['url'] ) ) {
							$decoded_url = urldecode( trim( $attributes['url'] ) );
							$twitter_embed = strtr(
								$twitter_embed_template,
								[
									'{url}' => htmlspecialchars( $decoded_url ),
								]
							);
							$post_content = str_replace( $match[0], $twitter_embed, $post_content );
							$twitter_shortcodes_replaced++;
						} else {
							echo WP_CLI::colorize( "%yOriginal: {$match[0]}%n" ) . "\n";
							WP_CLI::log( "Shortcode: $shortcode" );
						}
					} else {
						echo WP_CLI::colorize( "%yOriginal: {$match[0]}%n" ) . "\n";
						WP_CLI::log( "Shortcode: $shortcode" );
					}
				}

				WP_CLI\Utils\format_items(
					'table',
					[
						[
							'Youtube shortcodes replaced' => $youtube_shortcodes_replaced,
							'Iframe shortcodes replaced' => $iframe_shortcodes_replaced,
							'Video shortcodes replaced' => $video_shortcodes_replaced,
							'Facebook shortcodes replaced' => $facebook_shortcodes_replaced,
							'Instagram shortcodes replaced' => $instagram_shortcodes_replaced,
							'Twitter shortcodes replaced' => $twitter_shortcodes_replaced,
						],
					],
					[
						'Youtube shortcodes replaced',
						'Iframe shortcodes replaced',
						'Video shortcodes replaced',
						'Facebook shortcodes replaced',
						'Instagram shortcodes replaced',
						'Twitter shortcodes replaced',
					]
				);

				$result = $wpdb->update(
					$wpdb->posts,
					[
						'post_content' => $post_content,
					],
					[
						'ID' => $post->ID,
					]
				);

				if ( $result ) {
					echo WP_CLI::colorize( '%gPost content updated%n' ) . "\n";
					file_put_contents( $file_path, 'UPDATED POST ID: ' . $post->ID . "\n" . implode( "\n", array_map( fn( $match ) => $match[0], $matches ) ) . "\n", FILE_APPEND );
				} else {
					echo WP_CLI::colorize( '%rPost content not updated%n' ) . "\n";
					file_put_contents( $file_path, 'ISSUE UPDATING POST ID: ' . $post->ID . "\n" . implode( "\n",  array_map( fn( $match ) => $match[0], $matches ) ) . "\n", FILE_APPEND );
				}
			}
		}
	}

	public function cmd_fix_broken_youtube_shortcodes_and_love_letter_emojis() {
		global $wpdb;

		$posts = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );

		$youtube_embed_template = '<!-- wp:embed {"url":"{url}","type":"video","providerNameSlug":"youtube","responsive":true,"className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
{url}
</div></figure>
<!-- /wp:embed -->';

		foreach ( $posts as $post ) {

			$post_content = $post->post_content;
			$youtube_shortcodes_replaced = 0;
			$love_philly_text_replaced = 0;

			$pattern = '/http(s)?:\/\/(.*)youtu\.be\/.{11}(.{0,10})?(?|([\]])|([\/\]]))/i';
			preg_match_all( $pattern, $post->post_content, $youtube_matches, PREG_SET_ORDER );

			if ( count( $youtube_matches ) > 0 ) {
				WP_CLI::log( 'Post ID: ' . $post->ID );
				$seen = [];
				foreach ( $youtube_matches as $match ) {
					if ( in_array( $match[0], $seen, true ) ) {
						continue;
					}

					WP_CLI::log( $match[0] );
					$seen[] = $match[0];

					$link_pattern = '/http(s)?:\/\/(.*)youtu\.be\/.{11}/i';
					preg_match( $link_pattern, $match[0], $link_match );

					if ( count( $link_match ) > 0 ) {
						$post_content = str_replace( $match[0], $link_match[0], $post_content );
						$youtube_shortcodes_replaced++;
					}
				}
			}

			$has_love_philly_text = false;
			if ( str_contains( $post_content, '?<em> Love Philly?' ) ) {
				$post_content = str_replace( '?<em> Love Philly?', '💌<em> Love Philly?', $post_content );
				$love_philly_text_replaced++;
				$has_love_philly_text = true;
			}

			if ( count( $youtube_matches ) > 0 || $has_love_philly_text ) {
				WP_CLI::log( 'Post ID: ' . $post->ID );

				WP_CLI\Utils\format_items(
					'table',
					[
						[
							'Youtube shortcodes replaced' => $youtube_shortcodes_replaced,
							'Love Philly text replaced'   => $love_philly_text_replaced,
						],
					],
					[
						'Youtube shortcodes replaced',
						'Love Philly text replaced',
					]
				);

				$result = $wpdb->update(
					$wpdb->posts,
					[
						'post_content' => $post_content,
					],
					[
						'ID' => $post->ID,
					]
				);

				if ( $result ) {
					echo WP_CLI::colorize( '%gPost content updated%n' ) . "\n";
				} else {
					echo WP_CLI::colorize( '%rPost content not updated%n' ) . "\n";
				}
			}
		}
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

	private function attempt_to_get_video_id( string $shortcode ) {
		if ( str_contains( $shortcode, 'youtu.be/' ) ) {
			$video_id = substr( $shortcode, strrpos( $shortcode, 'youtu.be/' ) + 9 );

			if ( str_contains( $video_id, '?' ) ) {
				$video_id = substr( $video_id, 0, strpos( $video_id, '?' ) );
			}
		} else {
			// regex for extracting youtube video id from url
			$pattern = '/(?<=v=)[^&#]+/';
			preg_match( $pattern, $shortcode, $matches );
			$video_id = $matches[0] ?? '';

			// If video_id is still not found, try finding it as the last part of URL
			if ( empty( $video_id ) ) {
				$pattern = '/http(s)?:\/\/[^\s]+/';
				preg_match( $pattern, $shortcode, $url_matches );

				if ( isset( $url_matches[0] ) ) {
					$url_parts = explode( '/', $url_matches[0] );
					$video_id = end( $url_parts );
				}
			}
		}

		return $video_id;
	}
}
