<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\BatchLogic;
use \NewspackCustomContentMigrator\Utils\Logger;
use simplehtmldom\HtmlDocument;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for Saporta News.
 */
class TheFriscMigrator implements InterfaceCommand {
	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Logger instance.
	 *
	 * @var Logger.
	 */
	private $logger;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator.
	 */
	private $gutenberg_block_generator;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger                    = new Logger();
		$this->gutenberg_block_generator = new GutenbergBlockGenerator();
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
			'newspack-content-migrator the-frisk-clean-content-from-inline-donation',
			[ $this, 'cmd_the_frisk_clean_content_from_inline_donation' ],
			[
				'shortdesc' => 'Set migrated posts as archive.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'batch',
						'description' => 'Batch to start from.',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'posts-per-batch',
						'description' => 'Posts to import per batch',
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'Only this post id',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator the-frisk-transform-orphan-links-to-homepage-blocks',
			[ $this, 'cmd_transform_links_to_homepage_blocks' ],
			[
				'shortdesc' => 'Transform links where link text and destination equals to homepage blocks.',
				'synopsis'  => [
					BatchLogic::$num_items,
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator the-frisk-remove-related-blocks',
			[ $this, 'cmd_remove_related_blocks' ],
			[
				'shortdesc' => 'Remove related blocks from the bottom of posts.',
				'synopsis'  => [
					BatchLogic::$num_items,
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator the-frisk-repair-homepage-blocks',
			[ $this, 'cmd_repair_homepage_blocks' ],
			[
				'shortdesc' => 'Repair homepage blocks that were converted to HTML blocks.',
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator the-frisk-repair-twitter-blocks',
			[ $this, 'cmd_repair_twitter_blocks' ],
			[
				'shortdesc' => 'Repair twitter blocks that were converted to HTML blocks.',
			]
		);
	}

	public function cmd_repair_homepage_blocks( array $pos_args, array $assoc_args ): void {
		$logfile     = __FUNCTION__ . '.log';
		$block_class = 'np-single-post-embed';

		$block_args = [
			'showExcerpt'   => false,
			'showDate'      => false,
			'showAuthor'    => false,
			'postsToShow'   => 1,
			'mediaPosition' => 'left',
			'typeScale'     => 3,
			'imageScale'    => 1,
			'specificMode'  => true,
			'className'     => [ 'is-style-default', 'np-single-post-embed' ],
		];

		global $wpdb;
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM wp_posts WHERE post_content LIKE %s AND post_status = 'publish' AND post_type = 'post' ORDER BY ID LIMIT %d",
				'%' . $wpdb->esc_like( $block_class ) . '%',
				$assoc_args[ BatchLogic::$num_items['name'] ] ?? PHP_INT_MAX
			)
		);

		$this->logger->log( $logfile, sprintf( 'Found %d posts with related posts blocks', count( $post_ids ) ), Logger::INFO );

		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( 'Processing post %d', $post_id ) );
			$post        = get_post( $post_id );
			$blocks      = array_map(
				function ( $block ) use ( $block_class, $block_args ) {
					if ( $block['blockName'] !== 'core/html' || ! str_contains( $block['innerHTML'], $block_class ) ) {
						return $block;
					}
					$doc  = new HtmlDocument( $block['innerHTML'] );
					$link = $doc->find( '.entry-title a' );
					if ( empty( $link[0] ) ) {
						return $block;
					}
					$url         = $link[0]->getAttribute( 'href' );
					$slug        = trim( wp_parse_url( $url, PHP_URL_PATH ), '/' );
					$linked_post = get_page_by_path( $slug, OBJECT, 'post' );

					if ( empty( $linked_post->ID ) ) {
						return $block;
					}

					return $this->gutenberg_block_generator->get_homepage_articles_for_specific_posts(
						[ $linked_post->ID ],
						$block_args
					);
				},
				parse_blocks( $post->post_content )
			);
			$new_content = serialize_blocks( $blocks );
			if ( $new_content !== $post->post_content ) {
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $new_content,
					]
				);
				$this->logger->log( $logfile, sprintf( 'Updated homepage blocks post %d', $post_id ), Logger::SUCCESS );
			}
		}

	}

	public function cmd_repair_twitter_blocks( array $pos_args, array $assoc_args ): void {
		$logfile    = __FUNCTION__ . '.log';
		$script_tag = '<script async="" src="https://platform.twitter.com/widgets.js"';

		global $wpdb;
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM wp_posts WHERE post_content LIKE %s AND post_status = 'publish' AND post_type = 'post' ORDER BY ID LIMIT %d",
				'%' . $script_tag . '%',
				$assoc_args[ BatchLogic::$num_items['name'] ] ?? PHP_INT_MAX
			)
		);

		$this->logger->log( $logfile, sprintf( 'Found %d posts with potential twitter embeds', count( $post_ids ) ), Logger::INFO );

		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( 'Processing post %d', $post_id ) );
			$post   = get_post( $post_id );
			$blocks = parse_blocks( $post->post_content );
			foreach ( $blocks as $idx => $block ) {
				// Find HTML blocks with that script tag.
				if ( $block['blockName'] !== 'core/html' || ! str_contains( $block['innerHTML'], $script_tag ) ) {
					continue;
				}

				$one_before = $blocks[ $idx - 1 ];
				$two_before = $blocks[ $idx - 2 ];
				$to_delete  = false;
				// There is a blockquote before the HTML block that has the url we need for the embed.
				// There may or may not be an "empty" block between the HTML block and the blockquote.
				if ( $two_before['blockName'] === 'core/quote' && $one_before['blockName'] === null ) {
					$to_delete = $idx - 2;
				} elseif ( $one_before['blockName'] === 'core/quote' ) {
					$to_delete = $idx - 1;
				}

				$doc  = new HtmlDocument( render_block( $blocks[ $to_delete ] ) );
				$link = $doc->find( 'a' );
				if ( empty( $link ) ) {
					continue;
				}
				$a   = end( $link ); // The last link is the one that is embeddable.
				$url = $a->getAttribute( 'href' );
				if ( empty( $url ) || ! str_contains( $url, 'twitter.com' ) ) {
					continue;
				}
				// Strip the query and/or fragment.
				$url_parts    = parse_url( $url );
				$stripped_url = trim( $url_parts['scheme'] . '://' . $url_parts['host'] . $url_parts['path'] );

				$blocks[ $idx ]                       = $this->gutenberg_block_generator->get_twitter( $stripped_url, '', [ 'align' => 'center' ] );
				$blocks[ $idx ]['attrs']['align']     = 'center';
				$blocks[ $idx ]['attrs']['className'] .= ' aligncenter';

				// Remove the block with the blockquote.
				unset( $blocks[ $to_delete ] );
			}

			$new_content = serialize_blocks( array_values( $blocks ) );
			if ( $new_content !== $post->post_content ) {
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => $new_content,
					]
				);
				$this->logger->log( $logfile, sprintf( 'Fixed twitter embeds in post %d. %s', $post_id, get_permalink( $post ) ), Logger::SUCCESS );
			}
		}

	}

	public function cmd_remove_related_blocks( array $pos_args, array $assoc_args ): void {
		$logfile     = __FUNCTION__ . '.log';
		$heading_tag = '<h4 class="wp-block-heading">';

		global $wpdb;
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM wp_posts WHERE post_content LIKE %s AND post_status = 'publish' AND post_type = 'post' ORDER BY ID LIMIT %d",
				'%' . $wpdb->esc_like( $heading_tag ) . '%',
				$assoc_args[ BatchLogic::$num_items['name'] ] ?? PHP_INT_MAX
			)
		);

		$this->logger->log( $logfile, sprintf( 'Found %d posts with related posts blocks', count( $post_ids ) ), Logger::INFO );

		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( 'Processing post %d', $post_id ) );
			$post        = get_post( $post_id );
			$blocks      = parse_blocks( $post->post_content );
			$block_count = count( $blocks );

			foreach ( $blocks as $idx => $block ) {
				if ( $block['blockName'] === 'core/heading' ) {
					if ( str_starts_with( $block['innerHTML'], $heading_tag . 'RELATED COVERAGE ' ) || str_starts_with( $block['innerHTML'], $heading_tag . 'MORE ' ) ) {
						// If we find one of those blocks, remove it and all the blocks after it.
						array_splice( $blocks, $idx );
						// And stop looking.
						break;
					}
				}
			}

			$count_after = count( $blocks );
			if ( $count_after !== $block_count ) {
				wp_update_post(
					[
						'ID'           => $post_id,
						'post_content' => serialize_blocks( $blocks ),
					]
				);
			}
			WP_CLI::log( sprintf( 'Removed %d blocks in %d', ( $block_count - $count_after ), $post_id ) );
		}
	}

	public function cmd_transform_links_to_homepage_blocks( array $pos_args, array $assoc_args ): void {
		$logfile = __FUNCTION__ . '.log';

		$home_block_args = [
			'showExcerpt'   => false,
			'showDate'      => false,
			'showAuthor'    => false,
			'postsToShow'   => 1,
			'mediaPosition' => 'left',
			'typeScale'     => 3,
			'imageScale'    => 1,
			'specificMode'  => true,
			'className'     => [ 'np-single-post-embed' ],
		];

		global $wpdb;
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM wp_posts WHERE post_content LIKE '%>https://thefrisc.com%' AND post_status = 'publish' AND post_type = 'post' ORDER BY ID LIMIT %d",
				$assoc_args[ BatchLogic::$num_items['name'] ] ?? PHP_INT_MAX
			)
		);

		$this->logger->log( $logfile, sprintf( 'Found %d posts with links to thefrisc.com', count( $post_ids ) ), Logger::INFO );
		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( 'Processing post %d', $post_id ) );
			$post          = get_post( $post_id );
			$blocks        = parse_blocks( $post->post_content );
			$new_block_arr = [];
			foreach ( $blocks as $block ) {
				if (
					'core/paragraph' !== $block['blockName'] || ! str_contains( $block['innerHTML'], '>https://thefrisc.com' ) ) {
					$new_block_arr[] = $block;
					continue;
				}

				$doc = new HtmlDocument( $block['innerHTML'] );

				$post_ids_linked_to = [];
				foreach ( $doc->find( 'a' ) as $a ) {
					if ( $a->getAttribute( 'href' ) !== $a->getAttribute( 'innertext' ) ) {
						continue;
					}
					$slug = trim( wp_parse_url( $a->getAttribute( 'href' ), PHP_URL_PATH ), '/' );
					if ( empty( $slug ) ) { // This is just a link to the homepage.
						$this->logger->log( $logfile, sprintf( 'Skipping link to homepage for post %d', $post_id ), Logger::WARNING );
						continue;
					} elseif ( str_contains( $slug, '/' ) ) {
						$this->logger->log( $logfile, sprintf( 'Skipping non-post link for post %d', $post_id ), Logger::WARNING );
						continue;
					}
					$linked_post = get_page_by_path( $slug, OBJECT, 'post' );
					if ( ! $linked_post instanceof \WP_Post ) {
						$this->logger->log( $logfile, sprintf( 'Linked post %s not found for post %d', $slug, $post->ID ), Logger::WARNING );
						continue;
					}
					$post_ids_linked_to[] = $linked_post->ID;
					$a->remove();
				}

				$has_links = count( $post_ids_linked_to ) > 0;
				if ( $has_links ) {
					$replaced_content      = $doc->save();
					$block['innerHTML']    = $replaced_content;
					$block['innerContent'] = [ $replaced_content ];
				}

				$new_block_arr[] = $block;

				if ( $has_links ) {
					$home_block_args['postsToShow'] = count( $post_ids_linked_to );
					$new_block_arr[]                = $this->gutenberg_block_generator->get_homepage_articles_for_specific_posts( $post_ids_linked_to, $home_block_args );
				}
			}

			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => serialize_blocks( $new_block_arr ),
				]
			);
		}
	}

	private function remove_donation_elements( Crawler $donation_elements ): Crawler {
		$donation_elements->each(
			function ( Crawler $node ) {
				$link_parent_node = $node->getNode( 0 )->parentNode;

				if ( 'a' === $link_parent_node->nodeName ) {
					$figure_parent_node = $link_parent_node->parentNode;

					if ( 'figure' === $figure_parent_node->nodeName ) {
						$figure_parent_node->parentNode->removeChild( $figure_parent_node );
					}
				} else {
					$link_parent_node->parentNode->removeChild( $link_parent_node );
				}
			}
		);

		return $donation_elements;
	}

	/**
	 * Callable for `newspack-content-migrator the-frisk-clean-content-from-inline-donation`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_the_frisk_clean_content_from_inline_donation( $args, $assoc_args ) {
		$log_file        = 'the_frisk_clean_content_from_inline_donation.log';
		$posts_per_batch = isset( $assoc_args['posts-per-batch'] ) ? intval( $assoc_args['posts-per-batch'] ) : 10000;
		$batch           = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 1;

		$total_query = new \WP_Query(
			[
				'posts_per_page' => -1,
				'post_type'      => 'post',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		WP_CLI::warning( sprintf( 'Total posts: %d', count( $total_query->posts ) ) );

		$query_args = [
			'post_type'      => 'post',
			'paged'          => $batch,
			'posts_per_page' => $posts_per_batch,
		];
		if ( ! empty( $assoc_args['post-id'] ) ) {
			$query_args['p'] = $assoc_args['post-id'];
		}
		$query = new \WP_Query( $query_args );

		$posts       = $query->get_posts();
		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			$this->logger->log( $log_file, sprintf( 'Processing post %d of %d', $index + 1, $total_posts ), Logger::INFO );
			$fixed_content = $post->post_content;

			$crawler = new Crawler();
			$crawler->addHtmlContent( $fixed_content );

			// Remove inline donation images.
			$donation_image_filenames = [
				'146TmTISmYAk6D6',
				'IRdBXuOB3yQRI2ug',
				'14H8PXxZb3hKNgEjeiHZOjA',
				'1hysjT9T1QhiLyp7qBG9TsA',
				'EemQ_88mTjP6p37So2fNA',
				'146TmTISmYAk6D6-e7Pf7FA',
				'1HBvjvIAvmIlBbtkz2tejbw',
				'1WZoy33dPrQigNA3lVAYzvw',
			];
			if ( preg_match( sprintf( '@%s@', implode( '|', $donation_image_filenames ) ), $fixed_content ) ) {

				foreach ( $donation_image_filenames as $donation_image_filename ) {
					$donation_image_elements = $crawler->filterXPath( '//img[contains(@src, "' . $donation_image_filename . '")]' );
					if ( $donation_image_elements->count() > 0 ) {
						$this->remove_donation_elements( $donation_image_elements );
					}
				}
				$this->logger->log( $log_file, sprintf( 'Removed donation images for post %d', $post->ID ), Logger::SUCCESS );
			}

			// Remove even more inline donation images that we might have missed.
			if ( str_contains( $fixed_content, 'list-manage.com/subscribe' ) || str_contains( $fixed_content, 'https://the-frisc.fundjournalism.org' ) ) {
				$donation_image_elements = $crawler->filterXPath( '//a[(contains(@href, "list-manage.com") or contains(@href, "the-frisc.fundjournalism.org")) and .//img]' );

				if ( $donation_image_elements->count() > 0 ) {
					$this->remove_donation_elements( $donation_image_elements );
				}
				$this->logger->log( $log_file, sprintf( 'Removed signup images for post %d', $post->ID ), Logger::SUCCESS );
			}

			// Remove Mailchimp signup blockquotes
			if ( str_contains( $fixed_content, 'list-manage.com/subscribe' ) ) {
				foreach ( $crawler->filterXPath( '//blockquote[.//a[contains(@href, "list-manage.com/subscribe")]]' ) as $blockquote ) {
					$blockquote->parentNode->removeChild( $blockquote );
				}
				$this->logger->log( $log_file, sprintf( 'Removed Mailchimp signup text for post %d', $post->ID ), Logger::SUCCESS );
			}

			$fixed_content = $crawler->html();

			// Remove the more blocks entirely.
			$fixed_content = preg_replace( '@<h[34]{1}>(<strong>)?(RELATED|MORE|MAS HISTORIAS) .*(<\/strong>)?<\/h[34]{1}>(\[embed\]https://thefrisc.com(.*)\[\/embed\])+@i', '',
				$fixed_content );

			// Grab all the single url embeds and replace them with Homepage Posts Block with just one post.
			if ( preg_match_all( '@\[embed\](https://thefrisc.com/(.*?))\[/embed\]@i', $fixed_content, $matches ) ) {
				foreach ( $matches[0] as $idx => $embed ) {

					$path = trim( wp_parse_url( $matches[1][ $idx ], PHP_URL_PATH ) ?? '' );
					if ( empty( $path ) ) {
						continue;
					}

					$related_post = get_page_by_path( $path, OBJECT, 'post' );
					// Replace with nothing if we don't find the post.
					if ( ! $related_post instanceof \WP_Post ) {
						$replacement = '';
					} else {
						$block       = $this->gutenberg_block_generator->get_homepage_articles_for_specific_posts(
							[ $related_post->ID ],
							[
								'showExcerpt'   => false,
								'showDate'      => false,
								'showAuthor'    => false,
								'postsToShow'   => 1,
								'mediaPosition' => 'left',
								'typeScale'     => 3,
								'imageScale'    => 1,
								'specificMode'  => true,
								'className'     => [ 'is-style-default', 'np-single-post-embed' ],
							]
						);
						$replacement = serialize_block( $block );
					}

					$fixed_content = str_replace( $embed, $replacement, $fixed_content );
				}
				$this->logger->log( $log_file, sprintf( 'Replaced single link embeds for post %s', get_permalink( $post->ID ) ), Logger::SUCCESS );
			}

			// The Crawler will add body tags. No thanks.
			$fixed_content = trim( $fixed_content );
			if ( str_starts_with( $fixed_content, '<body>' ) ) {
				$fixed_content = substr( $fixed_content, 6 );
			}
			if ( str_ends_with( $fixed_content, '<body>' ) ) {
				$fixed_content = substr( $fixed_content, 0, -7 );
			}

			if ( $fixed_content !== $post->post_content ) {
				wp_update_post(
					[
						'ID'           => $post->ID,
						'post_content' => $fixed_content,
					]
				);
			}
		}
	}

}
