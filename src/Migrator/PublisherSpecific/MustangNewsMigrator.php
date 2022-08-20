<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus as CoAuthorPlusLogic;

/**
 * Custom migration scripts for Mustang News.
 */
class MustangNewsMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var null|CoAuthorPlusLogic.
	 */
	private $coauthorsplus_logic;

	private $target_tags = [
		'p',
		'figure',
		'blockquote',
		'iframe',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
	];

	private $stop = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlusLogic();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator mustangnews-migrate-bylines',
			[ $this, 'cmd_migrate_bylines' ],
			[
				'shortdesc' => 'Migrate bylines to Co-Authors Plus.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator mustangnews-migrate-php-snippets',
			[ $this, 'cmd_migrate_snippets' ],
			[
				'shortdesc' => 'Migrate php-snippet shortcodes.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator mustangnews-scrape-vc-posts',
			[ $this, 'cmd_scrape_vc_posts' ],
		);
	}

	/**
	 * Migrate bylines from custom 'byline' taxonomy to Co-Authors Plus.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_bylines( $args, $assoc_args ) {
		// Temporarily register bylines taxonomy.
		if ( ! taxonomy_exists( 'byline' ) ) {
			$args = [
				'hierarchical' => false,
			];
			register_taxonomy( 'byline', 'post', $args );
		}

		$post_ids = get_posts(
			[
				'posts_per_page' => -1,
				'fields'         => 'ids',
			]
		);
		foreach ( $post_ids as $post_id ) {
			$byline_terms = wp_get_post_terms( $post_id, 'byline' );
			if ( empty( $byline_terms ) ) {
				continue;
			}

			$guest_author_ids = [];

			foreach ( $byline_terms as $byline_term ) {
				$guest_author_name  = $byline_term->name;
				$guest_author_login = $byline_term->slug;

				// Find guest author if already created.
				$guest_author    = $this->coauthorsplus_logic->get_guest_author_by_user_login( $guest_author_login );
				$guest_author_id = $guest_author ? $guest_author->ID : 0;

				// Create guest author if not found.
				if ( ! $guest_author_id ) {
					$guest_author_data = [
						'display_name' => $guest_author_name,
						'user_login'   => $guest_author_login,
					];
					WP_CLI::warning( 'Creating guest author: ' . json_encode( $guest_author_data ) );
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author( $guest_author_data );
				}

				$guest_author_ids[] = $guest_author_id;
			}

			$existing_guest_authors    = $this->coauthorsplus_logic->get_guest_authors_for_post( $post_id );
			$existing_guest_author_ids = wp_list_pluck( $existing_guest_authors, 'ID' );
			if ( empty( array_diff( $guest_author_ids, $existing_guest_author_ids ) ) ) {
				WP_CLI::warning( 'Post ' . $post_id . ' already has all guest authors. Skipping.' );
				continue;
			}

			$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id );
			WP_CLI::warning( 'Updated post ' . $post_id );
		}
	}

	/**
	 * Migrate php-snippet-based shortcodes to blocks.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_migrate_snippets( $args, $assoc_args ) {
		global $wpdb;

		$posts = get_posts(
			[
				'posts_per_page' => -1,
				's'              => 'xyz-ips',
			]
		);

		$snippet_shortcode_regex = '#\[xyz-ips snippet=\"([^\"]*)\"[^\]]*\]#isU';

		foreach ( $posts as $post ) {
			$has_snippets = preg_match_all( $snippet_shortcode_regex, $post->post_content, $snippet_matches );
			if ( ! $has_snippets ) {
				WP_CLI::warning( 'Post ' . $post->ID . " doesn't appear to have snippets. Skipping." );
				continue;
			}

			$updated_content = $post->post_content;

			foreach ( $snippet_matches[0] as $index => $full_snippet ) {
				$snippet_type = $snippet_matches[1][ $index ];

				if ( 'longformpostinfo' === $snippet_type ) {
					WP_CLI::warning( 'Byline snippet found on post ' . $post->ID );
					$replacement     = $this->get_byline_snippet( $post->ID );
					$updated_content = str_replace( $full_snippet, $replacement, $updated_content );
				} elseif ( 'longformsocial' === $snippet_type ) {
					WP_CLI::warning( 'Social snippet found on post ' . $post->ID );
					$replacement     = $this->get_social_block_snippet( $post->ID );
					$updated_content = str_replace( $full_snippet, $replacement, $updated_content );
				} else {
					WP_CLI::error( 'Unknown snippet type encountered: ' . $snippet_type . ' on post ' . $post->ID );
				}
			}

			if ( $updated_content !== $post->post_content ) {
				$result = $wpdb->update( $wpdb->prefix . 'posts', [ 'post_content' => $updated_content ], [ 'ID' => $post->ID ] );
				if ( ! $result ) {
					WP_CLI::line( 'Error updating post ' . $post->ID );
				} else {
					WP_CLI::warning( 'Updated post ' . $post->ID );
				}
			} else {
				WP_CLI::warning( 'Nothing found to update on post ' . $post->ID );
			}
		}
	}

	public function cmd_scrape_vc_posts() {
		global $wpdb;

		$posts_with_visual_composer_content = $wpdb->get_results( "SELECT ID, post_name FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' AND post_content LIKE '%vc_row%'" );

		$posts_count = count( $posts_with_visual_composer_content );

		foreach ( $posts_with_visual_composer_content as $key => $post ) {
			$post_number = $key + 1;
			$url         = "https://mustangnews.net/$post->post_name";
			WP_CLI::log( "Post $post_number out of $posts_count\t$url" );
			$get  = wp_remote_get( $url );
			$body = wp_remote_retrieve_body( $get );

			$dom = new \DOMDocument();
			@$dom->loadHTML( $body );
			$article = $dom->getElementsByTagName( 'article' )->item( 0 );

            $this->stop = false;
			$body = $this->traverse_tree( $article, '' );

			$result = wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $body,
				]
			);

			if ( ! is_wp_error( $result ) ) {
                WP_CLI::log( 'Updated' );
			}
		}
	}

	protected function traverse_tree( \DOMNode $element, string $body ) {
		// var_dump(['nodeName' => $element->nodeName, 'children?' => $element->hasChildNodes(), 'parent' => $element->parentNode->nodeName, ]);
		if ( $this->stop ) {
			return $body;
		}

		if ( in_array( $element->nodeName, $this->target_tags ) ) {
			if ( 'p' === $element->nodeName ) {
				if ( empty( $element->nodeValue ) ) {
					return $body;
				}

				$body .= '<!-- wp:paragraph -->' . $element->ownerDocument->saveHTML( $element ) . '<!-- /wp:paragraph -->';
			}

			if ( 'iframe' === $element->nodeName ) {
				$body .= $element->ownerDocument->saveHTML( $element );
			}

			if ( 'blockquote' === $element->nodeName ) {
				$link = null;

				foreach ( $element->attributes as $attribute ) {
					/* @var \DOMAttr $attribute */
					if ( filter_var( $attribute->nodeValue, FILTER_VALIDATE_URL ) ) {
						$link = $attribute->nodeValue;
						break;
					}
				}

				if ( ! is_null( $link ) ) {
					$link = html_entity_decode( $link );
					$host = parse_url( $link, PHP_URL_HOST );

					if ( str_starts_with( $host, 'www.' ) ) {
						$host = substr( $host, 4 );
					}

					if ( str_ends_with( $host, '.com' ) ) {
						$host = substr( $host, 0, -4 );
					}

					$body .= '<!-- wp:embed {"url":"' . str_replace( '&', '\u0026', $link ) .
							 '","type":"rich","providerNameSlug":"' . $host .
							 '","responsive":true} --><figure class="wp-block-embed is-type-rich is-provider-' .
							 $host . ' wp-block-embed-' . $host . '"><div class="wp-block-embed__wrapper">' .
							 htmlentities( $link ) . '</div></figure><!-- /wp:embed -->';
				} else {
					$body .= $element->ownerDocument->saveHTML( $element );
				}
			}

			if ( 'figure' === $element->nodeName ) {
				$id_attr         = $element->attributes->getNamedItem( 'id' );
				$aria_attr       = $element->attributes->getNamedItem( 'aria-describedby' );
				$caption_id_attr = null;
				$figcaption      = '';

				if ( is_null( $id_attr ) ) {
					return $body;
				}

				if ( 'figcaption' === $element->lastChild->nodeName ) {
					$caption_id_attr = $element->lastChild->attributes->getNamedItem( 'id' );
					$figcaption      = '<figcaption>' . $element->lastChild->nodeValue . '</figcaption>';
				}

				$figure_class = $element->attributes->getNamedItem( 'class' );

				WP_CLI::log( "Encountered image, Original Attachment ID: {$id_attr->nodeValue}" );
				$image            = $this->find_image( $element );
				$image_class_attr = $image->attributes->getNamedItem( 'class' );
				$source           = $image->attributes->getNamedItem( 'src' )->nodeValue;
				$exploded         = explode( '/', $source );
				$filename         = array_pop( $exploded );

				global $wpdb;
				$result = $wpdb->get_row( "SELECT ID as post_id, guid FROM $wpdb->posts WHERE guid LIKE '%$filename' LIMIT 1" );

				if ( $result ) {
					$id = "attachment_$result->post_id";
					WP_CLI::log( "New Attachment ID: $id" );
					$id_attr->nodeValue = $id;

					if ( $aria_attr ) {
						$aria_attr->nodeValue = "caption-attachment-$result->post_id";
					}

					if ( $caption_id_attr ) {
						$caption_id_attr->nodeValue = "caption-attachment-$result->post_id";
					}

					if ( $image_class_attr ) {
						$image_class_attr->nodeValue = "wp-image-$result->post_id";
					}

					$alignment       = '';
					$alignment_class = '';
					if ( $figure_class ) {
						if ( str_contains( $figure_class->nodeValue, 'alignleft' ) ) {
							$alignment       = '"align":"left",';
							$alignment_class = 'alignleft';
						} elseif ( str_contains( $figure_class->nodeValue, 'alignright' ) ) {
							$alignment       = '"align":"right",';
							$alignment_class = 'alignright';
						} elseif ( str_contains( $figure_class->nodeValue, 'aligncenter' ) ) {
							$alignment       = '"align":"center",';
							$alignment_class = 'aligncenter';
						} elseif ( str_contains( $figure_class->nodeValue, 'alignwide' ) ) {
							$alignment       = '"align":"wide",';
							$alignment_class = 'alignwide';
						} elseif ( str_contains( $figure_class->nodeValue, 'alignfull' ) ) {
							$alignment       = '"align":"full",';
							$alignment_class = 'alignfull';
						}
					}

					$body .= '<!-- wp:image {' . $alignment . '"id":' . $result->post_id .
							 ',"sizeSlug":"large","linkDestination":"media"} --><figure class="wp-block-image size-large ' .
							 $alignment_class . '"><a href="' . $result->guid
							 . '"><img src="' . $result->guid . '" class="wp-image-' . $result->post_id . '"/></a>' .
							 $figcaption . '</figure><!-- /wp:image -->';
				} else {
					$source_set = $image->attributes->getNamedItem( 'srcset' );

					if ( $source_set ) {
						$source_set = $source_set->nodeValue;
						$sources    = explode( ', ', $source_set );

						foreach ( $sources as $source_2 ) {
							if ( $source_2 === $source ) {
								continue;
							}

							$url      = substr( $source_2, 0, strpos( $source_2, ' ' ) );
							$exploded = explode( '/', $url );
							$filename = array_pop( $exploded );
							$result   = $wpdb->get_row( "SELECT ID as post_id, guid FROM $wpdb->posts WHERE guid LIKE '%$filename' LIMIT 1" );

							if ( $result ) {
								$id = "attachment_$result->post_id";
								WP_CLI::log( "New Attachment ID: $id" );
								$id_attr->nodeValue = $id;

								if ( $aria_attr ) {
									$aria_attr->nodeValue = "caption-attachment-$result->post_id";
								}

								if ( $caption_id_attr ) {
									$caption_id_attr->nodeValue = "caption-attachment-$result->post_id";
								}

								if ( $image_class_attr ) {
									$image_class_attr->nodeValue = "wp-image-$result->post_id";
								}

								$alignment       = '';
								$alignment_class = '';
								if ( $figure_class ) {
									if ( str_contains( $figure_class->nodeValue, 'alignleft' ) ) {
										$alignment       = '"align":"left",';
										$alignment_class = 'alignleft';
									} elseif ( str_contains( $figure_class->nodeValue, 'alignright' ) ) {
										$alignment       = '"align":"right",';
										$alignment_class = 'alignright';
									} elseif ( str_contains( $figure_class->nodeValue, 'aligncenter' ) ) {
										$alignment       = '"align":"center",';
										$alignment_class = 'aligncenter';
									} elseif ( str_contains( $figure_class->nodeValue, 'alignwide' ) ) {
										$alignment       = '"align":"wide",';
										$alignment_class = 'alignwide';
									} elseif ( str_contains( $figure_class->nodeValue, 'alignfull' ) ) {
										$alignment       = '"align":"full",';
										$alignment_class = 'alignfull';
									}
								}

								$body .= '<!-- wp:image {' . $alignment . '"id":' . $result->post_id .
										 ',"sizeSlug":"large","linkDestination":"media"} --><figure class="wp-block-image size-large ' .
										 $alignment_class . '"><a href="' . $result->guid
										 . '"><img src="' . $result->guid . '" class="wp-image-' . $result->post_id .
										 '"/></a>' . $figcaption . '</figure><!-- /wp:image -->';
							}
						}
					} else {
						$body .= $element->ownerDocument->saveHTML( $element );
					}
				}
			}

			if ( 'h1' === $element->nodeName ) {
				$class_attr = $element->attributes->getNamedItem( 'class' );
				if ( $class_attr && str_contains( $class_attr->nodeValue, 'entry-title' ) ) {
					return $body;
				}
			}

			if ( 'h2' === $element->nodeName ) {
				$body .= '<!-- wp:heading -->' . $element->ownerDocument->saveHTML( $element ) . '<!-- /wp:heading -->';
			}

			if ( in_array( $element->nodeName, [ 'h1', 'h3', 'h4', 'h5', 'h6' ] ) ) {
				$level = substr( $element->nodeName, -1 );

				$body .= '<!-- wp:heading {"level":' . $level . '} -->' . $element->ownerDocument->saveHTML( $element ) . '<!-- /wp:heading -->';
			}

			return $body;
		}

		foreach ( $element->childNodes as $node ) {
			/*
			 @var \DOMNode $node */
			// var_dump( ['nodeName' => $node->nodeName, 'parent' => $node->parentNode->nodeName, 'value' => $node->nodeValue]);
			if ( str_starts_with( strtolower( $node->nodeName ), 'h' ) && 'aside' === $node->parentNode->nodeName && str_contains( strtolower( $node->nodeValue ), 'related sto' ) ) {
				$this->stop = true;
			} else {
				$body = $this->traverse_tree( $node, $body );
			}
		}

		return $body;
	}

	protected function find_image( \DOMNode $node ): ?\DOMNode {
		if ( 'img' === $node->nodeName ) {
			return $node;
		}

		foreach ( $node->childNodes as $child ) {
			$result = $this->find_image( $child );

			if ( $result instanceof \DOMNode ) {
				return $result;
			}
		}

		return null;
	}
	/**
	 * Get markup for a social icons block.
	 *
	 * @param int $post_id Post ID.
	 * @return string Block markup.
	 */
	private function get_social_block_snippet( $post_id ) {

		$url = get_permalink( $post_id );

		ob_start();
		?>
<!-- wp:social-links -->
<ul class="wp-block-social-links"><!-- wp:social-link {"url":"http://www.facebook.com/sharer.php?u=<?php echo urlencode( $url ); ?>","service":"facebook"} /-->

<!-- wp:social-link {"url":"https://twitter.com/intent/tweet?text=<?php echo urlencode( get_the_title( $post_id ) ); ?>\u0026url=<?php echo urlencode( $url ); ?>\u0026via=CPMustangNews","service":"twitter"} /--></ul>
<!-- /wp:social-links -->
		<?php
		return ob_get_clean();
	}

	/**
	 * Get markup for an author byline "block".
	 *
	 * @param int $post_id Post ID.
	 * @return string Block markup.
	 */
	private function get_byline_snippet( $post_id ) {

		$byline = '';
		if ( function_exists( 'get_coauthors' ) && ! empty( get_coauthors( $post_id ) ) ) {
			$authors = get_coauthors( $post_id );
			$byline  = implode( ', ', wp_list_pluck( $authors, 'display_name' ) ) . ' - ';
		}

		$byline .= get_the_date( '', $post_id );
		ob_start();
		?>
<!-- wp:paragraph -->
<p><strong><?php echo $byline; ?></strong></p>
<!-- /wp:paragraph -->
		<?php
		return ob_get_clean();
	}
}
