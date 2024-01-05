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
use WP_CLI;
use WP_CLI\ExitException;

/**
 * Custom migration scripts for The Emancipator.
 */
class TheEmancipatorMigrator implements InterfaceCommand {

	const SITE_TIMEZONE = 'America/New_York';

	/**
	 * Singleton instance.
	 *
	 * @var ?self
	 */
	private static ?self $instance = null;

	private CoAuthorPlus $coauthorsplus_logic;

	private Posts $posts_logic;

	private Attachments $attachments_logic;

	private GutenbergBlockGenerator $gutenberg_block_gen;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->posts_logic         = new Posts();
		$this->attachments_logic   = new Attachments();
		$this->gutenberg_block_gen = new GutenbergBlockGenerator();
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
wp newspack-content-migrator emancipator-authors
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
			'synopsis'      => '[--post-id=<post-id>] [--dry-run] [--num-posts=<num-posts>] [--min-post-id=<post-id>]',
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

	public function cmd_process_images( array $args, array $assoc_args ): void {
		WP_CLI::log( 'Downloading missing images and adding image credits' );

		$dry_run = $assoc_args['dry-run'] ?? false;

		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
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

					$blocks[ $idx ] = $this->gutenberg_block_gen->get_image(
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

		WP_CLI::log( 'Processing post subtitles' );
		$dry_run = $assoc_args['dry-run'] ?? false;
		$posts   = $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		foreach ( $posts as $post ) {

			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );
			$subtitle    = $api_content['subheadlines']['basic'] ?? false;
			if ( ! $dry_run && $subtitle ) {
				update_post_meta( $post->ID, 'newspack_post_subtitle', $subtitle );
			}
		}

		WP_CLI::success( 'Finished processing post subtitles' );
	}

	/**
	 * Create redirects for articles that are just redirects. Uses the Page Links To plugin.
	 *
	 * @throws ExitException
	 */
	public function cmd_redirects( array $args, array $assoc_args ): void {
		WP_CLI::log( 'Processing redirects into page links to.' );
		$dry_run = $assoc_args['dry-run'] ?? false;

		foreach ( $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args ) as $post ) {
			$meta        = get_post_meta( $post->ID );
			$api_content = maybe_unserialize( $meta['api_content_element'][0] );

			$redirect_to = $api_content['related_content']['redirect'][0]['redirect_url'] ?? false;
			if ( ! $dry_run && $redirect_to && ! CWS_PageLinksTo::get_link( $post ) ) {
				CWS_PageLinksTo::set_link( $post->ID, $redirect_to );
			}
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

		WP_CLI::log( 'Processing bylines' );
		$dry_run = $assoc_args['dry-run'] ?? false;
		$posts   = $this->get_all_wp_posts( 'post', [ 'publish' ], $assoc_args );

		foreach ( $posts as $post ) {
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
					$maybe_co_author = $this->coauthorsplus_logic->get_guest_author_by_display_name( $co_author );
					$co_author_id    = 0;
					if ( empty( $maybe_co_author ) ) {
						$author_description = $this->find_byline_credit( $co_author, $post->post_content );

						$co_author_id = $this->coauthorsplus_logic->create_guest_author(
							[
								'display_name' => $co_author,
								'description'  => wp_strip_all_tags( $author_description ),
							]
						);
						if ( ! empty( $author_description ) ) {
							$to_replace         = sprintf(
								'<!-- wp:paragraph -->%s<!-- /wp:paragraph -->',
								$author_description
							);
							$post->post_content = str_replace( $to_replace, '', $post->post_content );
							$replace_in_content = true;
						}
					} elseif ( is_object( $maybe_co_author ) ) {
						$co_author_id = $maybe_co_author->ID;
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
		}

		WP_CLI::success( 'Finished processing bylines' );
	}

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
