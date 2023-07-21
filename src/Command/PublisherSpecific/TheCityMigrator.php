<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \WP_CLI;

/**
 * Custom migration scripts for The City.
 */
class TheCityMigrator implements InterfaceCommand {

	/**
	 * @var null|CLASS Instance.
	 */
	private static $instance = null;

	/**
	 * CoAuthors Plus instance.
	 *
	 * @var CoAuthorPlus CoAuthors Plus instance.
	 */
	private $coauthors_plus;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthors_plus = new CoAuthorPlus();
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator thecity-chorus-cms-import-authors-and-posts',
			[ $this, 'cmd_choruscms_import_authors_and_posts' ],
			[
				'shortdesc' => 'Migrates Chorus CMS authors and posts (entries) to WordPress.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'path-to-export',
						'description' => "Path to where 'author/' and 'entry/' folders with JSONs are located.",
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-authors',
						'description' => "If used, will refresh all author data from JSONs, even if author exists.",
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);
	}

	/**
	 * Callable to `newspack-content-migrator thecity-chorus-cms-import-authors-and-posts`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_choruscms_import_authors_and_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		// Params.
		$refresh_authors = $assoc_args['refresh-authors'] ?? null;
		$path = rtrim( $assoc_args['path-to-export'], '/' );
		$authors_path = $path . '/author';
		$entries_path = $path . '/entry';
		if ( ! file_exists( $authors_path ) || ! file_exists( $entries_path ) ) {
			WP_CLI::error( 'Content not found in path.' );
		}

		/**
		 * Import authors.
		 */
		$authors_jsons = glob( $authors_path . '/*.json' );
		foreach ( $authors_jsons as $author_json ) {
			$author = json_decode( file_get_contents( $author_json ), true );

			// Get GA creation/update params.
			$ga_args = [
				'display_name' => $author['name'],
				'user_login' => $author['user']['username'],
				'first_name' => $author['user']['firstName'],
				'last_name' => $author['user']['lastName'],
			];

			// Apparently shortBio is always empty :(.
			if ( $author['shortBio'] ) {
				$ga_args['description'] = $author['shortBio'];
			}

			if ( isset( $author['socialLinks'] ) && ! empty( $author['socialLinks'] ) ) {

				// Extract links HTML for bio from socialLinks.
				$links_bio = '';
				foreach ( $author['socialLinks'] as $social_link ) {
					/**
					 * Available types: PROFILE, TWITTER, RSS, EMAIL, INSTAGRAM.
					 */
					if ( $social_link['type'] ) {
						if ( 'PROFILE' === $social_link['type'] ) {
							// Local site author page URL.
						} elseif ( 'TWITTER' === $social_link['type'] ) {
							// If doesn't end with dot, add dot.
							$links_bio .= ( ! empty( $links_bio ) && '.' != substr( $links_bio, -1 ) ) ? '.' : '';
							// If doesn't end with space, add space.
							$links_bio .= ( ! empty( $links_bio ) && ' ' != substr( $links_bio, -1 ) ) ? ' ' : '';
							// Get handle from URL.
							$handle = rtrim( $social_link['url'], '/' );
							$handle = substr( $handle, strrpos( $handle, '/' ) + 1 );
							// Add Twitter link.
							$links_bio .= sprintf( '<a href="%s" target="_blank">Follow @%s on Twitter</a>.', $social_link['url'], $handle );
						} elseif ( 'RSS' === $social_link['type'] ) {
							// RSS feed URL.
						} elseif ( 'EMAIL' === $social_link['type'] ) {
							$ga_args['user_email'] = $social_link['url'];
						} elseif ( 'INSTAGRAM' === $social_link['type'] ) {
							// If doesn't end with dot, add dot.
							$links_bio .= ( ! empty( $links_bio ) && '.' != substr( $links_bio, -1 ) ) ? '.' : '';
							// If doesn't end with space, add space.
							$links_bio .= ( ! empty( $links_bio ) && ' ' != substr( $links_bio, -1 ) ) ? ' ' : '';
							// Get handle from URL.
							$handle = rtrim( $social_link['url'], '/' );
							$handle = substr( $handle, strrpos( $handle, '/' ) + 1 );
							// Add Twitter link.
							$links_bio .= sprintf( '<a href="%s" target="_blank">Follow @%s on Instagram</a>.', $social_link['url'], $handle );
						}
					}

					// Not used key in JSONs: $social_link['label']
				}

				// Append social links to GA bio.
				if ( ! empty( $links_bio ) ) {
					// Start with bio.
					$bio_updated = isset( $ga_args['description'] ) && ! empty( $ga_args['description'] ) ? $ga_args['description'] : '';
					// If doesn't end with dot, add dot.
					$bio_updated .= ( ! empty( $bio_updated ) && '.' != substr( $bio_updated, -1 ) ) ? '.' : '';
					// If doesn't end with space, add space.
					$bio_updated .= ( ! empty( $bio_updated ) && ' ' != substr( $bio_updated, -1 ) ) ? ' ' : '';
					// Add links bio.
					$bio_updated .= $links_bio;

					// Update bio.
					$ga_args['description'] = $bio_updated;
				}
			}

			// Get existing GA.
			$ga_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_chorus_author_uid' and meta_value = %s", $author['uid'] ) );

			// If GA exists...
			if ( $ga_id ) {

				// ... and not refreshing, skip.
				if ( ! $refresh_authors ) {
					WP_CLI::log( sprintf( "Author '%s' already exists. Skipping.", $author['name'] ) );
					continue;
				}

				// ... and refreshing, update the GA.
				// Don't attempt to update user_login -- presently not supported.
				unset( $ga_args['user_login'] );
				$this->coauthors_plus->update_guest_author( $ga_id, $ga_args );
				WP_CLI::success( sprintf( 'Updated existing user data GA %d for author %s.', $ga_id, $author['name'] ) );
				continue;
			}

			// Create GA.
			$ga_id = $this->coauthors_plus->create_guest_author( $ga_args );
			WP_CLI::success( sprintf( "Created GA %d for author '%s'.", $ga_id, $author['name'] ) );
			// Save $author['uid'] as postmeta.
			if ( $author['uid'] ) {
				update_post_meta( $ga_id, 'newspack_chorus_author_uid', $author['uid'] );
			}

			/**
			 * These $authors keys also exist in author JSONs:
			 *  $author['url'] -- local site author page URL
			 *  $author['title'] -- not used, always empty
			 */
		}
	}
}
