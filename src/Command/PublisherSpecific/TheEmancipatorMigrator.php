<?php
/**
 * Migration tasks for The Emancipator.
 *
 * @package NewspackCustomContentMigrator
 */

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use CWS_PageLinksTo;
use Exception;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
use simplehtmldom\HtmlDocument;
use WP_CLI;
use WP_CLI\ExitException;
use WP_Post;

/**
 * Custom migration scripts for The Emancipator.
 */
class TheEmancipatorMigrator implements InterfaceCommand {

	const SITE_TIMEZONE = 'America/New_York';

	const CATEGORY_VIDEO_ID = 16;

	/**
	 * Singleton instance.
	 *
	 * @var ?self
	 */
	private static ?self $instance = null;

	private CoAuthorPlus $coauthorsplus_logic;

	private Posts $posts_logic;

	private Attachments $attachments_logic;
	private Logger $logger;

	private GutenbergBlockGenerator $block_generator;

	private GutenbergBlockGenerator $gutenberg_block_gen;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->posts_logic         = new Posts();
		$this->attachments_logic   = new Attachments();
		$this->gutenberg_block_gen = new GutenbergBlockGenerator();
		$this->logger              = new Logger();
		$this->block_generator     = new GutenbergBlockGenerator();
	}

	/**
	 * Singleton.
	 *
	 * @return TheEmancipatorMigrator
	 */
	public static function get_instance(): TheEmancipatorMigrator {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * @throws Exception
	 */
	public function register_commands(): void {

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-list-content-refresh',
			function () {
				echo esc_html(
					<<<EOT
# Empty the trash
wp post delete $( wp post list --post_status=trash --type=post_type --format=ids )
wp newspack-content-migrator emancipator-taxonomy
wp newspack-content-migrator emancipator-bylines
wp newspack-content-migrator emancipator-post-subtitles
wp newspack-content-migrator emancipator-redirects
wp newspack-content-migrator emancipator-process-images

# With UI/manually
# Maybe delete authors with 0 posts \n
EOT
				);
			},
			[
				'shortdesc' => 'Print commands needed to do a content refresh.',
			]
		);

		$generic_args = [
			'synopsis'      => '[--post-id=<post-id>] [--dry-run] [--num-posts=<num-posts>] [--min-post-id=<post-id>] [--refresh-existing]',
			'before_invoke' => [ $this, 'check_requirements' ],
		];


		WP_CLI::add_command(
			'newspack-content-migrator emancipator-taxonomy',
			[ $this, 'cmd_taxonomy' ],
			[
				'shortdesc' => 'Remove unneeded categories.',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-authors',
			[ $this, 'cmd_post_authors' ],
			[
				'shortdesc' => 'Migrates post authors/owners from the API content.',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-bylines',
			[ $this, 'cmd_post_bylines' ],
			[
				'shortdesc' => 'Migrates bylines from the API content as Co-Authors.',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-post-subtitles',
			[ $this, 'cmd_post_subtitles' ],
			[
				'shortdesc' => 'Add post subtitles',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-redirects',
			[ $this, 'cmd_redirects' ],
			[
				'shortdesc' => 'Create redirects for articles that are just redirects.',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-process-images',
			[ $this, 'cmd_process_images' ],
			[
				'shortdesc' => 'Add captions and credits and download missing images.',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-transform-readmore-in-series',
			[ $this, 'cmd_transform_readmore_in_series' ],
			[
				'shortdesc' => 'Transform readmore blocks in series to a link to the series.',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-ensure-featured-images',
			[ $this, 'cmd_ensure_featured_images' ],
			[
				'shortdesc' => 'Ensure that all posts have featured images.',
				...$generic_args,
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator emancipator-find-authors-in-text',
			[ $this, 'cmd_find_authors_in_text' ],
			[
				'shortdesc' => 'Find authors in text.',
				...$generic_args,
			]
		);
	}

	/**
	 * Do some quick sanity checks before running the commands.
	 *
	 * @throws ExitException
	 */
	public function check_requirements(): void {
		static $checked = false;
		if ( $checked ) {
			// It looks like this gets called at least more than once pr. run, so bail if we already checked.
			return;
		}
		if ( ! class_exists( 'CWS_PageLinksTo' ) ) {
			WP_CLI::error( '"Page Links To" plugin not found. Install and activate it before using the migration commands.' );
		}
		if ( ! $this->coauthorsplus_logic->validate_co_authors_plus_dependencies() ) {
			WP_CLI::error( '"Co-Authors Plus" plugin not found. Install and activate it before using the migration commands.' );
		}

		if ( get_option( 'timezone_string', false ) !== self::SITE_TIMEZONE ) {
			WP_CLI::error( sprintf( "Site timezone should be '%s'. Make sure it's set correctly before running the migration commands", self::SITE_TIMEZONE ) );
		}
		$checked = true;
	}

	/* This one does nothing - but it gives a list of posts that probably have italicezed text in the last paragraph
	 * that probably is author info that should be removed or consolidated.
	 */
	public function cmd_find_authors_in_text( array $pos_args, array $assoc_args ): void {
		$found_authors_in_text      = 0;
		$posts_with_italicized_text = [];
		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			$post_blocks = parse_blocks( $post->post_content );

			$last_block = end( $post_blocks );
			if ( 'core/paragraph' !== $last_block['blockName'] || ! str_starts_with( $last_block['innerHTML'], '<p><i>' ) ) {
				continue;
			}
			$posts_with_italicized_text[] = get_permalink( $post->ID );
			$found_authors_in_text ++;
		}
		$logfile = 'authors-in-text.log';
		$this->logger->log( $logfile, 'Posts with italicized text:' );
		array_map( fn( $post ) => $this->logger->log( $logfile, $post ), $posts_with_italicized_text );
		WP_CLI::success( sprintf( 'Found %d posts with italicized text. Logged them to authors-in-text.log', $found_authors_in_text ) );
	}

	public function cmd_ensure_featured_images( array $pos_args, array $assoc_args ): void {
		WP_CLI::log( 'Ensuring featured images' );

		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			if ( ! empty( get_post_meta( $post->ID, '_thumbnail_id', true ) ) ) {
				// This post already has a featured image.
				continue;
			}
			$images = get_attached_media( 'image', $post->ID );
			if ( empty( $images ) ) {
				// This post has no images.
				continue;
			}
			$image = array_shift( $images );
			update_post_meta( $post->ID, '_thumbnail_id', $image->ID );
			update_post_meta( $post->ID, 'newspack_featured_image_position', 'hidden' );
			WP_CLI::log( sprintf( 'Added featured image to %s', get_permalink( $post->ID ) ) );
		}
	}

	public function cmd_transform_readmore_in_series( array $pos_args, array $assoc_args ): void {
		WP_CLI::log( 'Processing readmore boxes' );
		$post_id = $assoc_args['post-id'] ?? false;

		$log_file = 'readmore-blocks.log';

		$homepage_block_default_args = [
			'showExcerpt'   => false,
			'showDate'      => false,
			'showAuthor'    => false,
			'postLayout'    => 'grid',
			'columns'       => 2,
			'postsToShow'   => 2,
			'mediaPosition' => 'left',
			'typeScale'     => 2,
			'imageScale'    => 2,
			'sectionHeader' => 'Read More in this series',
			'specificMode'  => true,
			'className'     => [ 'emancipator-readmore-in-series', 'read-more-box' ],
		];

		if ( ! $post_id ) {
			$num_posts = $assoc_args['num-posts'] ?? PHP_INT_MAX;
			global $wpdb;
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'post' AND post_content LIKE '%Read more in this series%' ORDER BY ID DESC LIMIT %d",
					[ $num_posts ]
				)
			);
		} else {
			$post_ids = [ $post_id ];
		}
		$total_posts = count( $post_ids );
		$counter     = 0;

		foreach ( $post_ids as $post_id ) {
			WP_CLI::log( sprintf( '-- %d/%d --', ++ $counter, $total_posts ) );
			$post           = get_post( $post_id );
			$post_permalink = get_permalink( $post );

			// Parse the post content into blocks.
			$post_blocks   = parse_blocks( $post->post_content );
			$target_blocks = array_filter(
				$post_blocks,
				fn( $block ) => 'core/html' === ( $block['blockName'] ?? '' ) && str_contains( $block['innerHTML'], 'Read more in this series' )
			);
			if ( empty( $target_blocks ) ) {
				continue;
			}

			// Array filter keeps the indices, so grab $idx here, so we can replace blocks.
			foreach ( $target_blocks as $idx => $block ) {

				$html_doc   = new HtmlDocument( $block['innerHTML'] );
				$link_paths = array_map(
					function ( $node ) {
						$href = $node->getAttribute( 'href' );
						if ( str_contains( $href, 'bostonglobe.com' ) ) {
							return parse_url( $href, PHP_URL_PATH );
						}

						return false;
					},
					$html_doc->find( 'div.panel a' )
				);
				// Get rid of the urls that were not from the site itself.
				$link_paths = array_filter( $link_paths );

				$referenced_post_ids = [];
				foreach ( $link_paths as $path ) {
					$slug       = basename( $path );
					$maybe_post = get_page_by_path( $slug, OBJECT, 'post' );
					if ( $maybe_post instanceof WP_Post ) {
						$referenced_post_ids[] = $maybe_post->ID;
					}
				}

				if ( empty( $referenced_post_ids ) ) {
					$this->logger->log( $log_file, sprintf( 'No valid post id references found for %s', $post_permalink ), Logger::ERROR );
					continue;
				}

				$homepage_block = $this->block_generator->get_homepage_articles_for_specific_posts( $referenced_post_ids, $homepage_block_default_args );

				$post_blocks[ $idx ] = $this->block_generator->get_group_constrained( [ $homepage_block ], [ 'wrap-readmore-in-series' ] );
			}
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => serialize_blocks( $post_blocks ),
				]
			);
			$this->logger->log(
				$log_file,
				sprintf(
					"Added read more block to:\n\t%s\n\t%s",
					$post_permalink,
					'https://www.bostonglobe.com' . wp_parse_url( $post_permalink, PHP_URL_PATH )
				),
				Logger::SUCCESS
			);
		}
		WP_CLI::success( 'Done transforming readmores. Log file in ' . $log_file );


	}

	/**
	 * @throws ExitException
	 */
	public function cmd_process_images( array $args, array $assoc_args ): void {
		WP_CLI::log( 'Downloading missing images and adding image credits' );
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;

		$dry_run          = $assoc_args['dry-run'] ?? false;
		$refresh_existing = $assoc_args['refresh-existing'] ?? false;

		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			if ( ! $refresh_existing && MigrationMeta::get( $post->ID, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::log( 'Skipping post ' . $post->ID . ' because it has already been processed.' );
				continue;
			}
			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			$attached_image_guids = array_map(
				fn( $image ) => pathinfo( $image->guid, PATHINFO_FILENAME ),
				get_attached_media( 'image', $post->ID )
			);

			if ( ! empty( $attached_image_guids ) ) {
				// The promo items are the featured images. See if we can get data from that
				// and update the featured image.
				foreach ( $api_content['promo_items'] ?? [] as $item ) {
					$attachment_id = array_search( $item['_id'], $attached_image_guids );
					if ( $attachment_id ) {
						$this->update_image_byline( $attachment_id, $item, $dry_run );
					}
				}
			}

			// Get image info from the api content for images in the body.
			$content_img = [];
			foreach ( $api_content['content_elements'] as $element ) {
				if ( empty( $element['type'] ) || 'image' !== $element['type'] ) {
					continue;
				}
				$content_img[ pathinfo( $element['url'], PATHINFO_BASENAME ) ] = $element;
			}

			$replace_in_content = false;
			$blocks             = parse_blocks( $post->post_content );
			foreach ( $blocks as $idx => $block ) {
				if ( 'core/image' !== $block['blockName'] ) {
					continue;
				}
				if ( ! preg_match( '@src="(.*?)"@i', $block['innerHTML'], $matches ) ) {
					// This does not look like an image url, so bail.
					continue;
				}

				$url        = $matches[1];
				$basename   = pathinfo( $url, PATHINFO_BASENAME );
				$image_info = $content_img[ $basename ] ?? [];
				$caption    = $image_info['caption'] ?? '';
				WP_CLI::log( "\t processing image " . $url );
				$attachment_id = $this->attachments_logic->import_attachment_for_post( $post->ID, $url, $caption, [ 'post_excerpt' => $caption ] );

				if ( ! is_wp_error( $attachment_id ) ) {
					if ( ! empty( $image_info ) ) {
						// Update the byline on the newly imported image.
						$this->update_image_byline( $attachment_id, $image_info, $dry_run );
					}

					$blocks[ $idx ]     = $this->gutenberg_block_gen->get_image(
						get_post( $attachment_id ),
						'full',
						false,
						'np-emancipator-image'
					);
					$replace_in_content = true;
				}
			}

			if ( $replace_in_content ) {
				$post_data = [
					'ID'           => $post->ID,
					'post_content' => serialize_blocks( $blocks ),
				];
				wp_update_post( $post_data );
			}

			MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
		}

	}

	public function cmd_taxonomy( $args, $assoc_args ): void {
		$opinion_cat     = get_category_by_slug( 'opinion' );
		$emancipator_cat = get_category_by_slug( 'the-emancipator' );
		if ( empty( $emancipator_cat ) ) {
			WP_CLI::log( 'Taxonomies already processed.' );

			return;
		}

		WP_CLI::log( 'Removing the superfluous "Opinion" and "The Emancipator" categories.' );

		$dry_run = $assoc_args['dry-run'] ?? false;

		// Remove the categories "opinion" and "the emancipator" from all posts.
		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			if ( ! $dry_run ) {
				wp_remove_object_terms( $post->ID, $opinion_cat->term_id, 'category' );
				wp_remove_object_terms( $post->ID, $emancipator_cat->term_id, 'category' );
			}
		}

		// Now unnest the categories under opinion -> the emancipator.
		$children = get_categories(
			[
				'parent' => $emancipator_cat->term_id,
			]
		);
		foreach ( $children as $child ) {
			if ( ! $dry_run ) {
				wp_update_term(
					$child->term_id,
					'category',
					[
						'parent' => 0,
					]
				);
			}
		}
		// And finally delete the two categories.
		if ( ! $dry_run ) {
			wp_delete_term( $opinion_cat->term_id, 'category' );
			wp_delete_term( $emancipator_cat->term_id, 'category' );
		}
	}

	public function cmd_post_subtitles( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;

		$refresh_existing = $assoc_args['refresh-existing'] ?? false;
		$dry_run          = $assoc_args['dry-run'] ?? false;

		$posts = $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		WP_CLI::log( 'Processing post subtitles' );
		foreach ( $posts as $post ) {
			if ( ! $refresh_existing && MigrationMeta::get( $post->ID, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::log('Skipping post ' . $post->ID . ' because it has already been processed.');
				continue;
			}

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );
			$subtitle    = $api_content['subheadlines']['basic'] ?? false;
			if ( ! $dry_run && $subtitle ) {
				update_post_meta( $post->ID, 'newspack_post_subtitle', $subtitle );
			}
			MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
		}

		WP_CLI::success( 'Finished processing post subtitles' );
	}

	/**
	 * Create redirects for articles that are just redirects. Uses the Page Links To plugin.
	 */
	public function cmd_redirects( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;

		$dry_run          = $assoc_args['dry-run'] ?? false;
		$refresh_existing = $assoc_args['refresh-existing'] ?? false;

		WP_CLI::log( 'Processing redirects into page links to.' );
		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			if ( ! $refresh_existing && MigrationMeta::get( $post->ID, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::log('Skipping post ' . $post->ID . ' because it has already been processed.');
				continue;
			}
			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			$redirect_to = $api_content['related_content']['redirect'][0]['redirect_url'] ?? false;
			if ( ! $dry_run && $redirect_to && ! CWS_PageLinksTo::get_link( $post ) ) {
				CWS_PageLinksTo::set_link( $post->ID, $redirect_to );
			}
			MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
		}
		WP_CLI::success( 'Finished processing redirects' );
	}


	/**
	 * @throws ExitException
	 */
	public function cmd_post_authors( array $args, array $assoc_args ): void {
		// I think this function is not needed, so just return here for now.
		return;
		WP_CLI::log( 'Processing post authors' );
		$dry_run = $assoc_args['dry-run'] ?? false;

		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			// Find the user that owns the post in the serialized API content and assign it as the post author.
			// If the user doesn't exist, create it and assign the author role.
			$real_author_email = $api_content['revision']['user_id'] ?? false;
			if ( ! $real_author_email ) {
				continue;
			}
			if ( ! $dry_run ) {
				$maybe_existing_user = get_user_by( 'email', $real_author_email );
				$author_id           = $maybe_existing_user->ID ?? false;
				if ( ! $author_id ) {
					$username  = stristr( $real_author_email, '@', true );
					$password  = wp_generate_password( 16, false );
					$author_id = wp_create_user( $username, $password, $real_author_email );
					if ( ! is_wp_error( $author_id ) ) {
						$wp_user = get_userdata( $author_id );
						$wp_user->set_role( 'author' );
					}
				}
				if ( $author_id !== $post->post_author ) {
					$post_data = array(
						'ID'          => $post->ID,
						'post_author' => $author_id,
					);
					$updated   = wp_update_post( $post_data );
					if ( is_wp_error( $updated ) ) {
						WP_CLI::error( sprintf( 'Failed to assign author to post with ID %d', $post->ID ) );
					}
				}
			}
		}
	}

	/**
	 * Add bylines (co-authors) for posts.
	 * Also delete the "credit paragraphs" at the bottom of the post content if possible.
	 */
	public function cmd_post_bylines( array $args, array $assoc_args ): void {
		$command_meta_key     = __FUNCTION__;
		$command_meta_version = 1;

		$dry_run          = $assoc_args['dry-run'] ?? false;
		$refresh_existing = $assoc_args['refresh-existing'] ?? false;

		$posts   = $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		WP_CLI::log( 'Processing bylines' );
		foreach ( $posts as $post ) {
			if ( ! $refresh_existing && MigrationMeta::get( $post->ID, $command_meta_key, 'post' ) >= $command_meta_version ) {
				WP_CLI::log('Skipping post ' . $post->ID . ' because it has already been processed.');
				continue;
			}
			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );
			$label_meta  = get_post_meta( $post->ID, 'label_storycard', true );

			$credits = [];
			if ( ! empty( $api_content['credits']['by'] ) ) {
				foreach ( $api_content['credits']['by'] as $credit ) {
					$credits[] = empty( $credit['name'] ) ? $credit : $credit['name'];
				}
			} elseif ( ! empty( $label_meta ) && str_contains( $label_meta, '|' ) ) {
				$byline = explode( '|', $label_meta )[1];
				foreach ( preg_split( '/(,\s|\s&\s|(\sand\s))/', $byline ) as $candidate ) {
					$candidate = trim( $candidate );
					if ( ! empty( $candidate ) && ! preg_match( '/[0-9]/', $candidate ) && ! ctype_upper( $candidate ) ) {
						$credits[] = ucwords( strtolower( trim( $candidate, '.' ) ) );
					}
				}
			}

			if ( ! empty( $credits ) ) {
				if ( $dry_run ) {
					continue;
				}

				$co_author_ids = [];

				$replace_in_content = false;

				foreach ( $credits as $co_author ) {
					$maybe_co_author    = $this->coauthorsplus_logic->get_guest_author_by_display_name( $co_author );
					$co_author_id       = 0;
					$author_description = $this->find_byline_credit( $co_author, $post->post_content );
					if ( empty( $maybe_co_author ) ) {
						$co_author_id = $this->coauthorsplus_logic->create_guest_author(
							[
								'display_name' => $co_author,
								'description'  => wp_strip_all_tags( $author_description ),
							]
						);
					} elseif ( is_object( $maybe_co_author ) ) {
						$co_author_id = $maybe_co_author->ID;
					}

					if ( ! empty( $author_description ) ) {
						$to_replace         = sprintf(
							'<!-- wp:paragraph -->%s<!-- /wp:paragraph -->',
							$author_description
						);
						$post->post_content = str_replace( $to_replace, '', $post->post_content );
						$replace_in_content = true;
					}

					if ( ! empty( $co_author_id ) ) {
						// Link the co-author created with the WP User with the same name if it exists.
						$co_author_wp_user = get_user_by( 'login', $co_author );
						if ( $co_author_wp_user ) {
							$this->coauthorsplus_logic->link_guest_author_to_wp_user(
								$co_author_id,
								$co_author_wp_user
							);
						}
						$co_author_ids[] = $co_author_id;
					}
				}
				if ( ! empty( $co_author_ids ) ) {
					$this->coauthorsplus_logic->assign_guest_authors_to_post( $co_author_ids, $post->ID );
				}

				if ( $replace_in_content ) {
					$post_data = [
						'ID'           => $post->ID,
						'post_content' => $post->post_content,
					];
					wp_update_post( $post_data );
				}
			}
			MigrationMeta::update( $post->ID, $command_meta_key, 'post', $command_meta_version );
		}

		WP_CLI::success( 'Finished processing bylines' );
	}

	/**
	 * @throws ExitException
	 */
	private function update_image_byline( int $attachment_id, array $item, bool $dry_run ): void {
		if ( empty( $item['type'] ) || 'image' !== $item['type'] || ! $attachment_id ) {
			return;
		}
		$maybe_byline = $this->get_byline_from_credits( $item );
		if ( $maybe_byline && ! $dry_run ) {
			$attachment_data = array(
				'ID'           => $attachment_id,
				'post_excerpt' => $item['caption'] ?? '',
			);

			if ( ! wp_update_post( $attachment_data, true ) ) {
				WP_CLI::error( sprintf( 'Failed to update attachment with ID %d', $attachment_id ) );
			}
			update_post_meta( $attachment_id, '_media_credit', $maybe_byline );
		}
	}

	/**
	 * Posts have "credit paragraphs" at the bottom of the content. They typically
	 * start with "$name is ..." and are italicized. This tries to find the string.
	 *
	 * @param string $name
	 * @param string $content
	 *
	 * @return string
	 */
	private function find_byline_credit( string $name, string $content ): string {
		// We're looking for a paragraph that starts with "$name is ...".
		$looking_for = sprintf( '<p><i>%s is', $name );
		// Loop backwards through the blocks array because the "credits paragraphs" are at the bottom.
		foreach ( array_reverse( parse_blocks( $content ) ) as $idx => $block ) {
			// Only deal with paragraphs. And only look in the bottom 10 blocks.
			if ( $idx > 10 || 'core/paragraph' !== $block['blockName'] ) {
				continue;
			}

			if ( str_starts_with( $block['innerHTML'], $looking_for ) ) {
				return $block['innerHTML'];
			}
		}

		return '';
	}

	private function get_byline_from_credits( array $item ): string {
		if ( ! empty( $item['credits']['by'][0]['byline'] ) ) {
			return $item['credits']['by'][0]['byline'];
		}
		if ( ! empty( $item['credits']['by'][0]['name'] ) ) {
			return $item['credits']['by'][0]['name'];
		}

		return '';
	}

	private function get_all_wp_posts( string $post_type, array $post_statuses = [], array $args = [], bool $log_progress = true ): iterable {
		if ( ! empty( $args['post-id'] ) ) {
			$all_ids = [ $args['post-id'] ];
		} else {
			$all_ids = $this->posts_logic->get_all_posts_ids( $post_type, $post_statuses );
			if ( ! empty( $args['num-posts'] ) ) {
				$all_ids = array_slice( $all_ids, 0, $args['num-posts'] );
			}
		}
		$total_posts = count( $all_ids );
		$home_url    = home_url();
		$counter     = 0;
		if ( $log_progress ) {
			WP_CLI::log( sprintf( 'Processing %d posts', count( $all_ids ) ) );
		}

		foreach ( $all_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				WP_CLI::log( sprintf( 'Processing post %d/%d: %s', ++ $counter, $total_posts, "${home_url}?p=${post_id}" ) );
				yield $post;
			}
		}
	}

}
