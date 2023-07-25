<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Utils\Logger;
use Symfony\Component\DomCrawler\Crawler;
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
	 * Logger instance.
	 *
	 * @var Logger Logger instance.
	 */
	private $logger;

	/**
	 * Attachments instance.
	 *
	 * @var Attachments Attachments instance.
	 */
	private $attachments;

	/**
	 * GutenbergBlockGenerator instance.
	 *
	 * @var GutenbergBlockGenerator GutenbergBlockGenerator instance.
	 */
	private $gutenberg_blocks;

	/**
	 * Crawler instance.
	 *
	 * @var Crawler Crawler instance.
	 */
	private $crawler;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthors_plus = new CoAuthorPlus();
		$this->logger = new Logger();
		$this->attachments = new Attachments();
		$this->gutenberg_blocks = new GutenbergBlockGenerator();
		$this->crawler = new Crawler();
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
					// [
					// 	'type'        => 'flag',
					// 	'name'        => 'default-author-user-id',
					// 	'description' => "GAs will be assigned to post, but still a u.",
					// 	'optional'    => false,
					// 	'repeating'   => false,
					// ],
					[
						'type'        => 'flag',
						'name'        => 'refresh-authors',
						'description' => "If used, will refresh all author data from JSONs, even if author exists.",
						'optional'    => true,
						'repeating'   => false,
					],
					[
						'type'        => 'flag',
						'name'        => 'refresh-posts',
						'description' => "If used, will refresh all posts or 'entries' data from JSONs, even if post exists.",
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
		// $default_author_user_id = $assoc_args['default-author-user-id'];
		$refresh_authors = $assoc_args['refresh-authors'] ?? null;
		$refresh_posts = $assoc_args['refresh-posts'] ?? null;
		$path = rtrim( $assoc_args['path-to-export'], '/' );
		$authors_path = $path . '/author';
		$entries_path = $path . '/entry';
		if ( ! file_exists( $authors_path ) || ! file_exists( $entries_path ) ) {
			WP_CLI::error( 'Content not found in path.' );
		}

		// Mapping from Chorus' featured image position to Newspack's.
		$newspack_featured_image_position = [
			// HEADLINE_OVERLAY => Behind Post Title
			'HEADLINE_OVERLAY' => 'behind',
			// HEADLINE_BELOW => Above Title,
			'HEADLINE_BELOW' => 'above',
			// SPLIT_LEFT => Beside Title
			'SPLIT_LEFT' => 'beside',
			// SPLIT_RIGHT => Beside Title
			'SPLIT_RIGHT' => 'beside',
			// STANDARD => Large
			'STANDARD' => 'large',
			// HEADLINE_BELOW_SHORT => Above Title
			'HEADLINE_BELOW_SHORT' => 'above',
		];

		// $this->import_authors( $authors_path, $refresh_authors );
		$this->import_entries( $entries_path, $refresh_posts, $newspack_featured_image_position /*, $default_author_user_id */ );
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_paragraph_to_block( $component ) {
		$paragraph_block = $this->gutenberg_blocks->get_paragraph( $component['contents']['html'] );

		return $paragraph_block;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_heading_to_block( $component ) {
		$paragraph_block = $this->gutenberg_blocks->get_heading( $component['contents']['html'] );

		return $paragraph_block;
	}

	/**
	 * @param array $component           Component data.
	 * @param bool  $strip_ending_breaks Should strip line breaks or spaces from ending of HTML.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_html_to_block( $component, $strip_ending_breaks = true ) {
		$html = $component['rawHtml'];
		if ( $strip_ending_breaks ) {
			$html = rtrim( $html );
		}
		$paragraph_block = $this->gutenberg_blocks->get_html( $html );

		return $paragraph_block;
	}

	/**
	 * @param array $component           Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_list_to_block( $component ) {
		$paragraph_block = $this->gutenberg_blocks->get_list( $component['contents']['html'] );

		return $paragraph_block;
	}

	/**
	 * @param array $component           Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_embed_to_block( $component ) {

		$block = [];

		$html = $component['embed']['embedHtml'];
		switch ( $component['embed']['provider']['name'] ) {
			case 'YouTube':

				// We expect an iframe with src attribute.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//iframe/@src' );

				// Validate that we have exactly one iframe with src attribute.
				if ( 1 !== $src_crawler->count() ) {
					$this->logger->log(
						'thecity-chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed YT component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				// We're not going to validate much more, Chorus should have this right.
				$src = trim( $src_crawler->getNode( 0 )->textContent );

				$block = $this->gutenberg_blocks->get_youtube( $src );

				break;

			case 'Vimeo':

				// We expect an iframe with src attribute.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//iframe/@src' );

				// Validate that we have exactly one iframe with src attribute.
				if ( 1 !== $src_crawler->count() ) {
					$this->logger->log(
						'thecity-chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed Vimeo component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				// We're not going to validate much more, Chorus should have this right.
				$src = trim( $src_crawler->getNode( 0 )->textContent );

				$block = $this->gutenberg_blocks->get_vimeo( $src );

				break;

			case 'Twitter':

				// Get all <a>s' srcs.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//a/@href' );

				$src = null;
				// Find src which has "twitter.com" and "/status/".
				foreach ( $src_crawler as $src_crawler_node ) {
					$src_this_node = trim( $src_crawler_node->textContent );
					if ( false !== strpos( $src_this_node, 'twitter.com' ) && false !== strpos( $src_this_node, '/status/' ) ) {
						$src = $src_this_node;
					}
				}

				// Validate.
				if ( is_null( $src ) ) {
					$this->logger->log(
						'thecity-chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed Twitter component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				$block = $this->gutenberg_blocks->get_twitter( $src );

				break;

			case 'Facebook':

				// Get all <a>s' srcs.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//a/@href' );

				$src = null;
				// Find src which has "facebook.com".
				foreach ( $src_crawler as $src_crawler_node ) {
					$src_this_node = trim( $src_crawler_node->textContent );
					if ( false !== strpos( $src_this_node, 'facebook.com' ) ) {
						$src = $src_this_node;
					}
				}

				// Validate.
				if ( is_null( $src ) ) {
					$this->logger->log(
						'thecity-chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed Facebook component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				$block = $this->gutenberg_blocks->get_facebook( $src );

				break;

			default:

				// For all other types, try and get an iframe's src attribute.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//iframe/@src' );

				if ( $src_crawler->count() >= 0 ) {
					$src = trim( $src_crawler->getNode( 0 )->textContent );
					$block = $this->gutenberg_blocks->get_iframe( $src );
				}

				break;
		}

		// Log that nothing happened.
		if ( empty( $block ) ) {
			$this->logger->log(
				'thecity-chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
				sprintf( "Err importing embed component, no known component type found, HTML =  ", $html ),
				$this->logger::WARNING
			);
		}

		return $block;
	}

	/**
	 * @param array $component           Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_pymembed_to_block( $component ) {

		$block = [];
		$src = $component['url'];

		$block = $this->gutenberg_blocks->get_iframe( $src );

		return $block;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_pullquote_to_block( $component ) {
		$paragraph_block = $this->gutenberg_blocks->get_quote( $component['quote']['html'] );

		return $paragraph_block;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_horizontal_rule_to_block( $component ) {
		$paragraph_block = $this->gutenberg_blocks->get_separator( 'is-style-wide' );

		return $paragraph_block;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_image_to_block( $component ) {

		$url = $component['image']['url'];
		$title = isset( $component['image']['asset']['title'] ) && ! empty( $component['image']['asset']['title'] ) ? $component['image']['asset']['title'] : null;
		$caption = isset( $component['image']['caption']['plaintext'] ) && ! empty( $component['image']['caption']['plaintext'] ) ? $component['image']['caption']['plaintext'] : null;

		// Import image.
		WP_CLI::line( sprintf( 'Downloading image URL %s ...', $url ) );
		$attachment_id = $this->attachments->import_external_file( $url, $title, $caption = null, $description = null, $alt = null, $post_id = 0, $args = [] );
		update_post_meta( $attachment_id, 'newspack_original_image_url', $url );
		// Logg errors.
 		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->logger->log(
				'thecity-chorus-cms-import-authors-and-posts__err__component_image_to_block.log',
				sprintf( "Err importing image URL %s error: %s", $url, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ),
				$this->logger::WARNING
			);
		}
		$attachment_post = get_post( $attachment_id );

		$paragraph_block = $this->gutenberg_blocks->get_image( $attachment_post );

		return $paragraph_block;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_gallery_to_block( $component ) {

		$attachment_ids = [];
		foreach ( $component['gallery']['images'] as $key_image => $image ) {
			$title = $image['asset']['title'];
			$caption = $image['caption']['html'];
			$url = $image['url'];

			// Import image.
			WP_CLI::line( sprintf( 'Downloading gallery image %d/%d URL %s ...', $key_image + 1, count( $component['gallery']['images'] ), $url ) );
			$attachment_id = $this->attachments->import_external_file( $url, $title, $caption = null, $description = null, $alt = null, $post_id = 0, $args = [] );
			update_post_meta( $attachment_id, 'newspack_original_image_url', $url );
			// Log errors.
	        if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
				$this->logger->log(
					'thecity-chorus-cms-import-authors-and-posts__err__component_image_to_block.log',
					sprintf( "Err importing image URL %s error: %s", $url, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ),
					$this->logger::WARNING
				);

				continue;
			}

			$attachment_ids[] = $attachment_id;
		}

		$slideshow_block = $this->gutenberg_blocks->get_jetpack_slideshow( $attachment_ids );

		return $slideshow_block;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Block data, to be rendered with serialize_blocks().
	 */
	public function component_sidebar_to_block( $component ) {

		$url = $component['image']['url'];
		$title = isset( $component['image']['asset']['title'] ) && ! empty( $component['image']['asset']['title'] ) ? $component['image']['asset']['title'] : null;
		$caption = isset( $component['image']['caption']['plaintext'] ) && ! empty( $component['image']['caption']['plaintext'] ) ? $component['image']['caption']['plaintext'] : null;

		// Import image.
		WP_CLI::line( sprintf( 'Downloading image URL %s ...', $url ) );
		$attachment_id = $this->attachments->import_external_file( $url, $title, $caption = null, $description = null, $alt = null, $post_id = 0, $args = [] );
		update_post_meta( $attachment_id, 'newspack_original_image_url', $url );
		// Logg errors.
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			$this->logger->log(
				'thecity-chorus-cms-import-authors-and-posts__err__component_image_to_block.log',
				sprintf( "Err importing image URL %s error: %s", $url, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ),
				$this->logger::WARNING
			);
		}
		$attachment_post = get_post( $attachment_id );

		$paragraph_block = $this->gutenberg_blocks->get_image( $attachment_post );

		return $paragraph_block;
	}

	public function import_entries( $entries_path, $refresh_posts, $newspack_featured_image_position /*, $default_author_user_id */ ) {
		global $wpdb;

		/**
		 * Chorus component converters.
		 *
		 * @array $component_converters {
		 * Key is the Chorus component name, value is an array with:
		 *    @type string $method    Method to call to convert the component.
		 *    @type string $arguments Arguments to pass to the method.
		 * }
		 */
		$component_converters = [
			'EntryBodyParagraph' => [
				'method' => 'component_paragraph_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodyImage' => [
				'method' => 'component_image_to_block',
				'arguments' => [
					'component',
					'post_id',
				],
			],
			'EntryBodyHeading' => [
				'method' => 'component_heading_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodyHTML' => [
				'method' => 'component_html_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodyList' => [
				'method' => 'component_list_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodyEmbed' => [
				'method' => 'component_embed_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodyPullquote' => [
				'method' => 'component_pullquote_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodyHorizontalRule' => [
				'method' => 'component_horizontal_rule_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodyGallery' => [
				'method' => 'component_gallery_to_block',
				'arguments' => [
					'component',
					'post_id',
				],
			],
			'EntryBodyPymEmbed' => [
				'method' => 'component_pymembed_to_block',
				'arguments' => [
					'component',
				],
			],
			'EntryBodySidebar' => [
				'method' => 'component_sidebar_to_block',
				'arguments' => [
					'component',
				],
			],

			// 'EntryBodyTable',
			// 'EntryBodyRelatedList',

			// These are not converted.
			'EntryBodyNewsletter' => [
				'method' => null,
				'arguments' => null,
			],
		];

$components_debug_types = [];

$components_debug_samples = [];

		// Loop through entries and import them.
		$entries_jsons = glob( $entries_path . '/*.json' );
		foreach ( $entries_jsons as $key_entry_json => $entry_json ) {
			WP_CLI::line( sprintf( "%d/%d", $key_entry_json + 1, count( $entries_jsons ) ) );

			$entry = json_decode( file_get_contents( $entry_json ), true );

// DEV debug.
// if ( 'https://www.thecity.nyc/missing-them/2021/3/24/22349311/nyc-covid-victims-destined-for-hart-island-potters-field' != $entry['url'] ) {
// 	continue;
// }


			/**
			 * Various data consistency checks -- use step debugging to catch unexpected data.
			 */
			if ( 'Entry' != $entry['__typename'] ) {
				$d=1;
			}
			if ( 'PUBLISHED' != $entry['publishStatus'] ) {
				$d=1;
			}
			if ( 'STORY' != $entry['type'] ) {
				$d=1;
			}


			// Loop through components.
			$blocks = [];
			foreach ( $entry['body']['components'] as $component ) {

				if ( ! isset( $component_converters[ $component['__typename'] ] ) ) {
					// Unknown component type, need to create a converter.
					$d=1;
				}

// DEV debug -- REMOVE.
if ( 'EntryBodySidebar' != $component['__typename'] ) {
	continue;
}

// DEV debug -- REMOVE.
// $components_debug_samples[ $component['embed']['provider']['name'] ][] = $component['embed']['embedHtml'];
// continue;

// DEV debug -- REMOVE and move conversion below after post was created.
// $post_id needed for component conversion -- for DEV purposes use this fake one
$post_id = 123;


				/**
				 * Call the converter on component.
				 */
				if ( 'EntryBodySidebar' == $component['__typename'] ) {

					/**
					 * Converting the EntryBodySidebar component to blocks is a special recursive case.
					 * This component is an array of nested components; need to loop over all of them and render them into blocks one by one.
					 */
// DEV debug -- REMOVE.
$blocks = [];
					$sidebar_component = $component['sidebar']['body'];
					foreach ( $sidebar_component as $component ) {
						$method = $component_converters[ $component['__typename'] ]['method'];
						$arguments = [];
						foreach ( $component_converters[ $component['__typename'] ]['arguments'] as $key_argument => $argument ) {
							if ( ! isset( $$argument ) ) {
								throw new \RuntimeException( sprintf( "Argument $%s not set in context and can't be passed to method %s() as argument number %d.", $argument, $method, $key_argument ) );
							}
							$arguments[] = $$argument;
						}
						$blocks[] = call_user_func_array( 'self::' . $method, $arguments );
					}

				} else {

					/**
					 * Convert a Chorus component component to Gutenberg block.
					 * Get its method name, arguments, and run it to get the equivalent Gutenberg block.
					 */
					$method = $component_converters[ $component['__typename'] ]['method'];
					$arguments = [];
					foreach ( $component_converters[ $component['__typename'] ]['arguments'] as $key_argument => $argument ) {
						if ( ! isset( $$argument ) ) {
							throw new \RuntimeException( sprintf( "Argument $%s not set in context and can't be passed to method %s() as argument number %d.", $argument, $method, $key_argument ) );
						}
						$arguments[] = $$argument;
					}
// DEV debug -- REMOVE.
$blocks = [];
					$blocks[] = call_user_func_array( 'self::' . $method, $arguments );

				}


// DEV debug -- REMOVE.
// temp store blocks for QA
$post_content = serialize_blocks( $blocks );
$d=1;
			}


			// Check if $component_converters contains this __typename, throw exception if not.
			if ( ! isset( $component_converters[ $component['__typename' ] ] ) ) {
				throw new \RuntimeException( sprintf( "%d component not registered with $component_converters.", $component['__typename' ] ) );
			}

			// Debug.
			$components_debug_types[ $component['__typename'] ] = [
				$entry['url'],
				$component,
			];

			$post_content = serialize_blocks( $blocks );

continue;

$post_content = $this->compile_post_content( $entry );

			/**
			 * Get post arguments.
			 */
			$post_arr = [
				'post_type'             => 'post',
				'post_status'           => 'publish',
				'post_title'            => $entry['title'],
				'post_content'          => '',

				// 'post_author'           => $user_id,
			];
			// Excerpt.
			if ( isset( $entry['dek']['html'] ) && ! empty( $entry['dek']['html'] ) ) {
				$post_arr['post_excerpt'] = $entry['dek']['html'];
			}

			// Post date.
			$publish_date = $this->format_date( $entry['publishDate'] );
			if ( ! $publish_date ) {
				$d=1;
			}
			$post_arr['post_date'] = $publish_date;

			/**
			 * Insert post.
			 */
			$post_id = wp_insert_post( $post_arr, true );
			if ( is_wp_error( $post_id ) ) {
				$err = $post_id->get_error_message();
				$this->logger->log( 'chorus__error__insert_post.log', "uid: {$entry['uid']} errorInserting: ". $err );
				continue;
			}


			/**
			 * Import featured image.
			 */
			if ( isset( $entry['leadImage']['asset'] ) && ! empty( $entry['leadImage']['asset'] ) ) {
				if ( 'IMAGE' != $entry['leadImage']['asset']['type'] ) {
					$d=1;
				}
				if ( $entry['leadImage']['additionalContributors'] ) {
					$d=1;
				}
				$url = $entry['leadImage']['asset']['url'];
				$credit = $entry['leadImage']['asset']['credit']['html'];
				$title = $entry['leadImage']['asset']['title'];
				$caption = $entry['leadImage']['asset']['sourceCaption'];
				$search_text = $entry['leadImage']['asset']['searchText'];

				// Download featured image.
				$attachment_id = $this->attachments->import_external_file(
					$url, $title, $caption, $description = null, $alt = null, $post_id, $args = []
				);
				if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
					$this->logger->log( 'chorus__error__import_featured_image.log', "url: {$url} errorInserting: ". ( is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ) );
					break;
				}

				// Save credit as newspack credit.
				update_post_meta( $attachment_id, '_media_credit', $credit );

				// 'createdAt' => '2022-12-02T19:23:52.000Z',
				$created_at = $this->format_date( $entry['leadImage']['asset']['createdAt'] );
				if ( $created_at ) {
					$wpdb->update( $wpdb->posts, [ 'post_date' => $created_at, 'post_date_gmt' => $created_at ], [ 'ID' => $attachment_id ] );
				}
			}
			// Set Newspack featured image position.
			if ( $entry['layoutTemplate'] ) {
				if ( ! isset( $newspack_featured_image_position[ $entry['layoutTemplate'] ] ) ) {
					$d=1;
				}
				update_post_meta( $post_id, 'newspack_featured_image_position', $newspack_featured_image_position[ $entry['layoutTemplate'] ] );
			}


			/**
			 * Get and assign authors.
			 */
			$ga_ids = [];
			foreach ( $entry['author'] as $author ) {
				// Get GA ID with that uid.
				$ga_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s and meta_value = %s", 'newspack_chorus_author_uid', $entry['author'][0]['uid'] ) );
				if ( ! $ga_id ) {
					$d=1;
				}
				$ga_ids[] = $ga_id;
			}
			if ( ! empty( $entry['contributors'] ) ) {
				$d=1;
			}
			// "Contributors" are additional GAs.
			if ( $entry['contributors'] && ! empty( $entry['contributors'] ) ) {
				foreach ( $entry['contributors'] as $contributor ) {
					if ( ! isset( $contributor['authorProfile']['uid'] ) || empty( $contributor['authorProfile']['uid'] ) ) {
						$d = 1;
					}
					$ga_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s and meta_value = %s", 'newspack_chorus_author_uid', $contributor['authorProfile']['uid'] ) );
					if ( ! $ga_id ) {
						$d=1;
					}
					$ga_ids[] = $ga_id;
				}
			}
			// Assign authors.
			$this->coauthors_plus->assign_guest_authors_to_post( $ga_ids, $post_id );

			/**
			 * Categories.
			 */
			$category_name_primary = $entry['primaryCommunityGroup']['name'];
				update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category_id );

			foreach ( $entry['communityGroups'] as $community_group ) {
				$category_name = $community_group['name'];
			}

			foreach ( $entry['body']['components'] as $component ) {

				// get all unique component types, their content, and example URLs.

				$component['__typename'];
			}


			/**
			 * Update additional post data.
			 */
			$updates = [];

			// Content
			$post_content = $this->compile_post_content( $entry );
			$updates['post_content'] = $post_content;

			// Get slug.
			$url_parsed = parse_url( $entry['url'] );
			$path_exploded = explode( '/', $url_parsed['path'] );
			$slug = $path_exploded[ count( $path_exploded ) - 1 ];
			if ( ! $slug ) {
				$d = 1;
			}
			$updates['post_name'] = $slug;

			// Modify updated date.
			$updated_date = $this->format_date( $entry['updatedAt'] );
			if ( ! $updated_date ) {
				$d=1;
			}
			$updates['post_modified'] = $updated_date;
			$updates['post_modified_gmt'] = $updated_date;

			// Apply $updates.
			$wpdb->update( $wpdb->posts, $updates, [ 'ID' => $post_id ] );

			// Set other post meta.
			$meta = [
				'newspack_chorus_entry_uid' => $entry['uid'],
				'newspack_chorus_entry_url' => $entry['url'],
			];
			if ( $entry['additionalContributors'] && ! empty( trim( $entry['additionalContributors']['plaintext'] ) ) ) {
				$meta['newspack_chorus_additional_contributors'] = $entry['additionalContributors']['plaintext'];
			}
			foreach ( $meta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}

		}

		// ururur
		$components_debug_samples;
		$components_debug_types;
		$d=1;

	}

	public function compile_post_content( $entry ) {

		$post_content = '';


return $post_content;

		array (
			'body' =>
				array (
					'components' =>
						array (
							0 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'zXfnvJ',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“We are a devastating result of the pandemic,” says the handwritten note outside of Steinway Cafe-Billiards. The iconic Astoria pool hall shuttered abruptly last Thursday, just two days after a key decision in a two-years-long lawsuit over tens of thousands of dollars in missed pandemic-era rent payments by the business.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							1 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'VSUoWC',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“I’m going through the five stages of grief,” said Athena Mennis, a general manager who started there as a waitress 22 years ago, and came in Wednesday morning to be told — along with 10 other employees — that she had lost her job. ',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							2 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => '20PTMB',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'While some patrons learned of their haunt’s closure from the note Mennis wrote and taped to the glass outside, others found out from the pool hall’s <a href="https://www.instagram.com/p/CtNUCAhO3is/?hl=en">Instagram post</a> Wednesday announcing “with great sadness and shock” that it would be the last day for the game room and bar that opened in 1990.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							3 =>
								array (
									'__typename' => 'EntryBodyRelatedList',
									'placement' =>
										array (
											'id' => '0NG4ks',
											'alignment' => NULL,
										),
									'items' =>
										array (
											0 =>
												array (
													'title' => 'One Shot From the End',
													'url' => 'https://www.thecity.nyc/queens/2022/11/8/23446172/astoria-pool-hall-steinway-cafe-billiards-innovation-queens-qns',
												),
										),
								),
							4 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'Q24QM5',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'One user shared memories of a first date there in 1993, while others shared memories of pool and chess games they’d played in the hall. ',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							5 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'h5BdDY',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“That’s really sad,” commented pool pro Fedor Gorst. “That was my first pool room that I visited in US when I was 14 or 15. I loved that place.” ',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							6 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => '0x0Yjl',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'Another commenter, Paul Taylor, lamented reading about “the closure of another billiards club,” adding that it “reminds me again of how I felt when my local club closed for the last time and is now home to a gym.”',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							7 =>
								array (
									'__typename' => 'EntryBodyImage',
									'placement' =>
										array (
											'id' => 'Eg8agL',
											'alignment' => NULL,
										),
									'contentWarning' => '',
									'image' =>
										array (
											'asset' =>
												array (
													'title' => 'A sign outside Steinway Billiards in Astoria announced its closing.',
												),
											'caption' =>
												array (
													'html' => 'A broken heart',
													'plaintext' => 'A broken heart',
												),
											'hideCredit' => false,
											'height' => 3000,
											'width' => 2000,
											'url' => 'https://cdn.vox-cdn.com/thumbor/MVJPYB1Ojxsi8aFGAMSpZz10h90=/0x0:2000x3000/2000x3000/filters:focal(1000x1500:1001x1501)/cdn.vox-cdn.com/uploads/chorus_asset/file/24722393/steinway_billiards_close.jpg',
										),
								),
							8 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'qsc3i8',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'In its 33 years, the billiards cafe has been the home of Earl Strickland, who served as the in-house pro between 2011 to 2018 and was considered one of the best nine-ball players of all time. Other notable players including Efren Reyes, Shane Van Boening and Ronnie O’Sullivan have also graced the tournament tables at the family business that operated out of a low-key block on Astoria’s bustling Steinway Street.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							9 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'Ypc59h',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'The neighborhood mainstay has also been a favorite gathering place for Greeks in Astoria and beyond. When <a href="https://www.thecity.nyc/queens/2022/11/8/23446172/astoria-pool-hall-steinway-cafe-billiards-innovation-queens-qns">THE CITY visited last year,</a> longtime patrons <a href="https://www.thecity.nyc/2022/12/4/23492338/steinway-cafe-billiards-julie-won-innovation-qns-second-shot">entertained each other</a> over games of pool or Greek backgammon, bantering with workers over $3 coffees between rounds in the manner of old friends.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							10 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'WhbBfz',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'A Queens judge first ruled in December of last year that a warrant would be issued for the pool hall’s eviction as a result of about $440,000 owed by the business in back rent since March 2020, <a href="https://iapps.courts.state.ny.us/nyscef/DocumentList?docketId=Nfh7k728Bm1xpg3Iuurvsg==&display=all&courtType=Queens%20County%20Civil%20Court%20-%20Landlord%20and%20Tenant%20Division&resultsPageNum=1">according to court documents</a>. But an appeal process stalled the eviction for about six months following the court decision, until a judge last Tuesday declined a motion from the business asking the landlords to show cause.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							11 =>
								array (
									'__typename' => 'EntryBodyHeading',
									'placement' =>
										array (
											'id' => 'NtBaUk',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '‘We All Grew Up Together’',
										),
									'level' => 2,
								),
							12 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'ofPPvh',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'Speaking to THE CITY on Monday, several longtime employees shared feelings of loss and confusion over the pool hall’s closure, as well as worries over their future. All said they had expected that any closure would be postponed for at least a few more months as the court case dragged on, and <a href="https://www.thecity.nyc/2022/12/4/23492338/steinway-cafe-billiards-julie-won-innovation-qns-second-shot">as they had hoped for a lucky break</a>.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							13 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'P2k6j4',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'Luisa Patino, 37, had been back on the job for only three days after a three-month sick leave for breast cancer when she found out that the business was closing.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							14 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => '5VIFTm',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“I feel a lot of depression. It’s pretty sad,” said Patino, who has worked at the pool hall for 13 years. “Because I know the people [a] long time, and because he closed and he told me the same day.”',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							15 =>
								array (
									'__typename' => 'EntryBodyImage',
									'placement' =>
										array (
											'id' => 'XmNGB2',
											'alignment' => NULL,
										),
									'contentWarning' => '',
									'image' =>
										array (
											'asset' =>
												array (
													'title' => 'The staff at Steinway Billiards celebrate a birthday.',
												),
											'caption' =>
												array (
													'html' => 'Staffers at Steinway Billiards, including Athena Mennis at the far left and Jana Tellez at the far right, celebrate Luisa Patino’s birthday (middle) in October of 2022.   ',
													'plaintext' => 'Staffers at Steinway Billiards, including Athena Mennis at the far left and Jana Tellez at the far right, celebrate Luisa Patino’s birthday (middle) in October of 2022.   ',
												),
											'hideCredit' => false,
											'height' => 533,
											'width' => 800,
											'url' => 'https://cdn.vox-cdn.com/thumbor/KKNaFbPkWeTCYrw8QnkKTO03kbU=/0x0:800x533/800x533/filters:focal(400x267:401x268)/cdn.vox-cdn.com/uploads/chorus_asset/file/24722614/steinway_billiards_staff.jpg',
										),
								),
							16 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'd0kTaU',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'Other members of the staff echoed Patino’s sentiment, saying they wished owner Georgiois “George” Nikolakakos would have given advance notice so they could have started looking for new jobs. ',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							17 =>
								array (
									'__typename' => 'EntryBodyImage',
									'placement' =>
										array (
											'id' => 'ChTPiW',
											'alignment' => NULL,
										),
									'contentWarning' => '',
									'image' =>
										array (
											'asset' =>
												array (
													'title' => 'Steinway Billiards owner Georgios Nikolakakos hung out behind the bar on a busy Wednesday night.',
												),
											'caption' =>
												array (
													'html' => 'George',
													'plaintext' => 'George',
												),
											'hideCredit' => false,
											'height' => 2000,
											'width' => 3000,
											'url' => 'https://cdn.vox-cdn.com/thumbor/w-4IWSiXQFrri7BOVrcm64EYbTc=/0x0:3000x2000/3000x2000/filters:focal(1500x1000:1501x1001)/cdn.vox-cdn.com/uploads/chorus_asset/file/24165138/110222_steinway_billiards_4.jpg',
										),
								),
							18 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'PQwMUZ',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'That includes 32-year-old Jana Tellez, a single mother who has worked at the billiards cafe since she was 15. Though, like many of the workers, she also emphasized her closeness with other staffers as well as with Nikolakakos, adding that she wishes him no ill will despite the sudden closure that has left her and others in limbo.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							19 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'UrBXVe',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“George’s like another dad to me, as weird as it sounds … I don’t even think he understood half the things that were going on, you know, it wasn’t even him dealing with the courts,” Tellez said. “I don’t blame him. I’m not gonna say who I blame but I just don’t blame him.”',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							20 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => '1v8ucd',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'Andres, a 34-year-old cook from Ecuador, also said he was worried about how he was going to find a job with the closure of the place due to his immigration status. He began working at the pool hall as a busboy when he was 17. ',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							21 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'kYuHVI',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“We all grew up together over there,” said Andres, who learned to play Greek backgammon at the pool hall when he was 22 years old.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							22 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => '4RzKSF',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“He played backgammon with all the old men and beat them out all the time,” Mennis, the manager, chimed in. She chuckled: “They’d all get mad at him.”',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							23 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'fvghrp',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'Nikolakakos and his daughter, Anna, who helps run the business, could not immediately be reached for comment.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							24 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'XE7EyM',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'In the meantime, Mennis has set up a <a href="https://gofund.me/e78cd004">GoFundMe</a> hoping to scrape together a sum to help support the staff as they look for their next gigs.',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							25 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'cF78ho',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'The goal, she said, is to raise about $2,000 for each former employee to help them cover upcoming bills and get back on their feet during what she calls “the slowest season for hospitality staff.”',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							26 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => '1JZKmw',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => 'Recalling the staff and customers’ last night at the pool hall on Wednesday, Tellez noted how “everybody sat there and cried — and drank.”',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							27 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'pVrFyd',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“And I worked my ass off all night,” said Mennis. ',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							28 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => 'atRNLM',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '“I had a good time saying goodbye — not a good time, but a bittersweet time,” she continued. “The people that I’ve met there I’ve known my whole life…  I just know I’m gonna lose touch with the majority of the people that I’ve spent my teenage years and adult years knowing.”',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
							29 =>
								array (
									'__typename' => 'EntryBodyNewsletter',
									'placement' =>
										array (
											'id' => 'oewNPh',
											'alignment' => NULL,
										),
									'newsletter' =>
										array (
											'name' => 'Get THE CITY Scoop',
											'slug' => 'the_city',
										),
								),
							30 =>
								array (
									'__typename' => 'EntryBodyParagraph',
									'placement' =>
										array (
											'id' => '3JkEsx',
											'alignment' => NULL,
										),
									'contents' =>
										array (
											'html' => '',
										),
									'dropcap' => false,
									'endmark' => false,
									'lead' => false,
								),
						),
				),
		);

		return $post_content;

	}

	/**
	 * @param $chorus_date
	 *
	 * @return array|string|string[]|null
	 */
	private function format_date( $chorus_date ) {

		// Extract e.g. '2023-06-13 21:05:36' from '2023-06-13T21:05:36.000Z'.
		$wp_date = preg_replace( '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2}).*/', '$1 $2', $chorus_date );

		return $wp_date;
	}

	public function import_authors( $authors_path, $refresh_authors ) {
		global $wpdb;

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
