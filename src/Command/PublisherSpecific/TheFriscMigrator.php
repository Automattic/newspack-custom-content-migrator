<?php
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use Symfony\Component\DomCrawler\Crawler;
use WP_CLI;

/**
 * Custom migration scripts for The Frisc.
 */
class TheFriscMigrator implements InterfaceCommand {

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
	 * Get Instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
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
				],
			]
		);
	}

	/**
	 * Callable for `newspack-content-migrator the-frisk-clean-content-from-inline-donation`.
	 *
	 * @param array $args CLI args.
	 * @param array $assoc_args CLI args.
	 */
	public function cmd_the_frisk_clean_content_from_inline_donation( array $args, array $assoc_args ): void {
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

		$query = new \WP_Query(
			[
				// 'p'              => 440,
				'post_type'      => 'post',
				'paged'          => $batch,
				'posts_per_page' => $posts_per_batch,
			]
		);

		$posts       = $query->get_posts();
		$total_posts = count( $posts );

		foreach ( $posts as $index => $post ) {
			$this->logger->log( $log_file, sprintf( 'Processing post %d of %d', $index + 1, $total_posts ), Logger::INFO );
			$fixed_content = $post->post_content;

			$crawler = new Crawler();
			$crawler->addHtmlContent( $post->post_content );

			// Remove inline donation image.
			$donation_image_filenames = [ '146TmTISmYAk6D6', 'IRdBXuOB3yQRI2ug', '14H8PXxZb3hKNgEjeiHZOjA', '1hysjT9T1QhiLyp7qBG9TsA', 'EemQ_88mTjP6p37So2fNA' ];
			foreach ( $donation_image_filenames as $donation_image_filename ) {
				$donation_image_elements = $crawler->filterXPath( '//img[contains(@src, "' . $donation_image_filename . '")]' );

				if ( $donation_image_elements->count() > 0 ) {
					$this->logger->log( $log_file, sprintf( 'Found inline donation image in post %d', $post->ID ), Logger::INFO );

					$donation_image_elements->each(
						function ( Crawler $node ) use ( $post, $log_file ) {
							$link_parent_node = $node->getNode( 0 )->parentNode;

							if ( 'a' === $link_parent_node->nodeName ) {
								$figure_parent_node = $link_parent_node->parentNode;

								if ( 'figure' === $figure_parent_node->nodeName ) {
									$figure_parent_node->parentNode->removeChild( $figure_parent_node );
								} else {
									$this->logger->log( $log_file, sprintf( 'No parent figure found for image in post %d', $post->ID ), Logger::INFO );
								}
							} else {
								$link_parent_node->parentNode->removeChild( $link_parent_node );
							}
						}
					);

					$fixed_content = $crawler->html();

					if ( $fixed_content !== $post->post_content ) {
						wp_update_post(
							[
								'ID'           => $post->ID,
								'post_content' => $fixed_content,
							]
						);

						$this->logger->log( $log_file, sprintf( 'Fixed content for post %d', $post->ID ), Logger::SUCCESS );
					}
				}
			}

			// Remove Mailchimp signup text.
			$mailchimp_text_regexes = [
				'/(?<mailchimp_cta><blockquote>(<strong>)?(.*)list-manage.com\/subscribe(.*)<\/blockquote>)/i',
			];

			foreach ( $mailchimp_text_regexes as $mailchimp_text_regex ) {
				preg_match_all( $mailchimp_text_regex, $fixed_content, $matches );

				foreach ( $matches['mailchimp_cta'] as $mailchimp_signup_text ) {
					if ( str_contains( $fixed_content, $mailchimp_signup_text ) ) {
						$fixed_content = str_replace( $mailchimp_signup_text, '', $fixed_content );

						wp_update_post(
							[
								'ID'           => $post->ID,
								'post_content' => $fixed_content,
							]
						);

						$this->logger->log( $log_file, sprintf( 'Removed Mailchimp signup text for post %d', $post->ID ), Logger::SUCCESS );
					}
				}
			}

			// Migrate related coverage to Homepage Posts Block.
			$related_coverage_regexes = [
				'/(?<more_posts><h[34]{1}>(<strong>)?MORE(.*)(<\/strong>)?<\/h[34]{1}>\[embed\](.*)\[\/embed\])/i',
				'/(?<more_posts><h[34]{1}>(<strong>)?RELATED COVERAGE(<\/strong>)?<\/h[34]{1}>\[embed\](.*)\[\/embed\])/i',
			];

			foreach ( $related_coverage_regexes as $related_coverage_regex ) {
				preg_match_all( $related_coverage_regex, $fixed_content, $matches );

				foreach ( $matches['more_posts'] as $related_coverage_text ) {
					if ( str_contains( $fixed_content, $related_coverage_text ) ) {
						$homepage_posts_block_content = $this->migrate_related_coverage_text( $related_coverage_text, $log_file );

						if ( empty( $homepage_posts_block_content ) ) {
							$this->logger->log( $log_file, sprintf( 'No related posts found for the post %d', $post->ID ), Logger::WARNING );
							continue;
						}

						$fixed_content = str_replace( $related_coverage_text, $homepage_posts_block_content, $fixed_content );

						wp_update_post(
							[
								'ID'           => $post->ID,
								'post_content' => $fixed_content,
							]
						);
					}
				}
			}

			$crawler->clear();
		}
	}

	/**
	 * Migrate related coverage to Homepage Posts Block.
	 *
	 * @param string $related_coverage_text Related coverage text.
	 * @param string $log_file Log file.
	 *
	 * @return string Serialized blocks.
	 */
	private function migrate_related_coverage_text( $related_coverage_text, $log_file ) {
		preg_match( '/<h[34]{1}>(<strong>)?(?<title>.*)(<\/strong>)?<\/h[34]{1}>/i', $related_coverage_text, $title_match );
		$title = $title_match['title'];

		preg_match_all( '/\[embed\](?<embed_url>.*?)\[\/embed\]/i', $related_coverage_text, $embed_url_match );

		foreach ( $embed_url_match['embed_url'] as $embed_url ) {
			$embed_url = trim( $embed_url );

			$embed_url_parts = explode( '/', $embed_url );
			$embed_url_parts = array_filter( $embed_url_parts );

			$embed_url_post_sulg = end( $embed_url_parts );

			$related_post = get_page_by_path( $embed_url_post_sulg, OBJECT, 'post' );

			if ( ! $related_post ) {
				$this->logger->log( $log_file, sprintf( 'Related post not found for embed URL %s', $embed_url ), Logger::WARNING );
				continue;
			}

			$related_posts[] = $related_post->ID;
		}

		if ( empty( $related_posts ) ) {
			$this->logger->log( $log_file, sprintf( 'No related posts found for embed URL %s', $embed_url ), Logger::WARNING );
			return '';
		}

		$homepage_posts_block_title   = $this->gutenberg_block_generator->get_heading( empty( $title ) ? 'Related Coverage' : $title, 'h4' );
		$homepage_posts_block_content = $this->gutenberg_block_generator->get_homepage_articles_for_specific_posts(
			$related_posts,
			[
				'showExcerpt'   => false,
				'showDate'      => false,
				'showAuthor'    => false,
				'columns'       => 4,
				'postsToShow'   => count( $related_posts ),
				'mediaPosition' => 'left',
				'typeScale'     => 3,
				'imageScale'    => 1,
				'specificMode'  => true,
				'className'     => [ 'is-style-default' ],
			]
		);

		return serialize_blocks( [ $homepage_posts_block_title, $homepage_posts_block_content ] );
	}
}
