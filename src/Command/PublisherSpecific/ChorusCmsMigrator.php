<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Logic\Attachments;
use \NewspackCustomContentMigrator\Logic\GutenbergBlockGenerator;
use \NewspackCustomContentMigrator\Logic\Taxonomy;
use \NewspackCustomContentMigrator\Utils\Logger;
use Symfony\Component\DomCrawler\Crawler;
use \WP_CLI;

/**
 * Custom migration scripts for The City.
 */
class ChorusCmsMigrator implements InterfaceCommand {

	/**
	 * Chorus components to Gutenberg blocks converters.
	 *
	 * Keys are the Chorus component name, values define a method used to convert it to blocks.
	 *
	 * @array COMPONENT_CONVERTERS {
	 *      @type string $method    Method to call to convert the component.
	 *      @type string $arguments Arguments to pass to the method.
	 * }
	 */
	const COMPONENT_CONVERTERS = [
		/**
		 * Key is Chorus component name.
		 *
		 * @array {
		 *  string $method    A method in this class which gets to convert component to Gutenberg blocks.
		 *  string $arguments Names of variables passed to the conversion method.
		 * }
		 */
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
				'post_id',
			],
		],
		'EntryBodyRelatedList' => [
			'method' => 'component_related_list_to_block',
			'arguments' => [
				'component',
			],
		],

		/**
		 * These components with nulls for methods and arrays will not be converted.
		 */
		'EntryBodyNewsletter' => [
			'method' => null,
			'arguments' => null,
		],
		'EntryBodyTable' => [
			'method' => null,
			'arguments' => null,
		],
	];

	/**
	 * Mapping from Chorus' featured image position to Newspack's.
	 */
	const FEATURED_IMAGE_POSITION_MAPPING = [
		'HEADLINE_OVERLAY'     => 'behind',
		// HEADLINE_BELOW => Above Title,
		'HEADLINE_BELOW'       => 'above',
		// SPLIT_LEFT => Beside Title
		'SPLIT_LEFT'           => 'beside',
		// SPLIT_RIGHT => Beside Title
		'SPLIT_RIGHT'          => 'beside',
		// STANDARD => Large
		'STANDARD'             => 'large',
		// HEADLINE_BELOW_SHORT => Above Title
		'HEADLINE_BELOW_SHORT' => 'above',
	];

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
	 * Taxonomy instance.
	 *
	 * @var Taxonomy Taxonomy instance.
	 */
	private $taxonomy;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->coauthors_plus = new CoAuthorPlus();
		$this->logger = new Logger();
		$this->attachments = new Attachments();
		$this->gutenberg_blocks = new GutenbergBlockGenerator();
		$this->crawler = new Crawler();
		$this->taxonomy = new Taxonomy();
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
			'newspack-content-migrator chorus-cms-import-authors-and-posts',
			[ $this, 'cmd_import_authors_and_posts' ],
			[
				'shortdesc' => 'Migrates authors and entries (posts) to WordPress.',
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
	 * Callable to `newspack-content-migrator chorus-cms-import-authors-and-posts`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_authors_and_posts( $pos_args, $assoc_args ) {
		global $wpdb;

		/**
		 * CLI Params.
		 */
		// $default_author_user_id = $assoc_args['default-author-user-id'];
		$refresh_authors = $assoc_args['refresh-authors'] ?? null;
		$refresh_posts = $assoc_args['refresh-posts'] ?? null;
		$path = rtrim( $assoc_args['path-to-export'], '/' );
		$authors_path = $path . '/author';
		$entries_path = $path . '/entry';
		if ( ! file_exists( $authors_path ) || ! file_exists( $entries_path ) ) {
			WP_CLI::error( 'Content not found in path.' );
		}

		// WP_CLI::line( "Checking whether this script knows how to convert all Chorus' content components..." );
		// $this->validate_known_component_types( $entries_path );
		//
		// WP_CLI::line( "Importing authors..." );
		// $this->import_authors( $authors_path, $refresh_authors );

		WP_CLI::line( "Importing posts..." );
		$this->import_entries( $entries_path, $refresh_posts /*, $default_author_user_id */ );
	}

	public function validate_known_component_types( $entries_path ) {
		// Loop through entries and import them.
		$entries_jsons = glob( $entries_path . '/*.json' );
		foreach ( $entries_jsons as $entry_json ) {
			$entry = json_decode( file_get_contents( $entry_json ), true );

			// Loop through components.
			foreach ( $entry['body']['components'] as $component ) {
				if ( ! isset( self::COMPONENT_CONVERTERS[ $component['__typename'] ] ) ) {
					WP_CLI::error( sprintf( "Unknown component type '%s', need to create a converter first.", $component['__typename'] ) );
				}
			}
		}
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one paragraph block.
	 */
	public function component_paragraph_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_paragraph( $component['contents']['html'] );

		return $blocks;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one heading block.
	 */
	public function component_heading_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_heading( $component['contents']['html'] );

		return $blocks;
	}

	/**
	 * @param array $component           Component data.
	 * @param bool  $strip_ending_breaks Should strip line breaks or spaces from ending of HTML.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one HTML block.
	 */
	public function component_html_to_block( $component, $strip_ending_breaks = true ) {
		$blocks = [];

		$html = $component['rawHtml'];
		if ( $strip_ending_breaks ) {
			$html = rtrim( $html );
		}
		$blocks[] = $this->gutenberg_blocks->get_html( $html );

		return $blocks;
	}

	/**
	 * @param array $component           Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one list block.
	 */
	public function component_list_to_block( $component ) {
		$blocks = [];

		$blocks[] = $this->gutenberg_blocks->get_list( $component['contents']['html'] );

		return $blocks;
	}

	/**
	 * @param array $component           Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one block.
	 */
	public function component_embed_to_block( $component ) {

		$blocks = [];

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
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed YT component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				// We're not going to validate much more, Chorus should have this right.
				$src = trim( $src_crawler->getNode( 0 )->textContent );

				$blocks[] = $this->gutenberg_blocks->get_youtube( $src );

				break;

			case 'Vimeo':

				// We expect an iframe with src attribute.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//iframe/@src' );

				// Validate that we have exactly one iframe with src attribute.
				if ( 1 !== $src_crawler->count() ) {
					$this->logger->log(
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed Vimeo component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				// We're not going to validate much more, Chorus should have this right.
				$src = trim( $src_crawler->getNode( 0 )->textContent );

				$blocks[] = $this->gutenberg_blocks->get_vimeo( $src );

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
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed Twitter component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				$blocks[] = $this->gutenberg_blocks->get_twitter( $src );

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
						'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
						sprintf( "Err importing embed Facebook component, HTML =  ", $html ),
						$this->logger::WARNING
					);
					return [];
				}

				$blocks[] = $this->gutenberg_blocks->get_facebook( $src );

				break;

			case 'Tableau Software':

				// This works as Classic Editor shortcode.
				$blocks[] = $this->gutenberg_blocks->get_html( $component['embed']['embedHtml'] );

				break;

			default:

				// For all other types, try and get an iframe's src attribute.
				$this->crawler->clear();
				$this->crawler->add( $html );
				$src_crawler = $this->crawler->filterXPath( '//iframe/@src' );

				if ( $src_crawler->count() >= 0 ) {
					$src = trim( $src_crawler->getNode( 0 )->textContent );
					$blocks[] = $this->gutenberg_blocks->get_iframe( $src );
				}

				break;
		}

		// Log that nothing happened.
		if ( empty( $blocks ) ) {
			$this->logger->log(
				'chorus-cms-import-authors-and-posts__err__component_embed_to_block.log',
				sprintf( "Err importing embed component, no known component type found, HTML =  ", $html ),
				$this->logger::WARNING
			);
		}

		return $blocks;
	}

	/**
	 * @param array $component           Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one iframe block.
	 */
	public function component_pymembed_to_block( $component ) {
		$blocks = [];

		$src = $component['url'];
		$blocks[] = $this->gutenberg_blocks->get_iframe( $src );

		return $blocks;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one quote block.
	 */
	public function component_pullquote_to_block( $component ) {
		$blocks = [];

		$blocks = $this->gutenberg_blocks->get_quote( $component['quote']['html'] );

		return $blocks;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one separator block.
	 */
	public function component_horizontal_rule_to_block( $component ) {
		$blocks = [];

		$blocks = $this->gutenberg_blocks->get_separator( 'is-style-wide' );

		return $blocks;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one image block.
	 */
	public function component_image_to_block( $component ) {
		$blocks = [];

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
				'chorus-cms-import-authors-and-posts__err__component_image_to_block.log',
				sprintf( "Err importing image URL %s error: %s", $url, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ),
				$this->logger::WARNING
			);
		}
		$attachment_post = get_post( $attachment_id );

		$blocks[] = $this->gutenberg_blocks->get_image( $attachment_post );

		return $blocks;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns one Jetpack slideshow gallery block.
	 */
	public function component_gallery_to_block( $component ) {
		$blocks = [];

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
					'chorus-cms-import-authors-and-posts__err__component_image_to_block.log',
					sprintf( "Err importing image URL %s error: %s", $url, is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ),
					$this->logger::WARNING
				);

				continue;
			}

			$attachment_ids[] = $attachment_id;
		}

		$blocks[] = $this->gutenberg_blocks->get_jetpack_slideshow( $attachment_ids );

		return $blocks;
	}

	/**
	 * @param array $sidebar_component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks(). Returns _________________.
	 */
	public function component_sidebar_to_block( $sidebar_component, $post_id ) {
		$blocks = [];

		$inner_blocks = [];
		foreach ( $sidebar_component['sidebar']['body'] as $component ) {
			// Get method name and arguments.
			$method = self::COMPONENT_CONVERTERS[ $component['__typename'] ]['method'];
			$arguments = [];
			foreach ( self::COMPONENT_CONVERTERS[ $component['__typename'] ]['arguments'] as $key_argument => $argument ) {
				if ( ! isset( $$argument ) ) {
					throw new \RuntimeException( sprintf( "Argument $%s not set in context and can't be passed to method %s() as argument number %d.", $argument, $method, $key_argument ) );
				}
				$arguments[] = $$argument;
			}

			// Call the method and merge resulting converted block.
			$inner_blocks = array_merge(
				$inner_blocks,
				call_user_func_array( 'self::' . $method, $arguments )
			);
		}

		$group_block = $this->gutenberg_blocks->get_group_constrained( $inner_blocks, [ 'group-sidebar' ] );
		$blocks[] = $group_block;

		return $blocks;
	}

	public function get_author_names_from_additional_contributors_field( $contributor_field ) {

		$contributor_field = trim( $contributor_field );
		$contributor_field = str_replace( 'Additional Reporting By ', '', $contributor_field );
		$contributor_field = str_replace( 'Additional Reporting by ', '', $contributor_field );
		$contributor_field = str_replace( 'ADDITIONAL REPORTING BY ', '', $contributor_field );
		$contributor_field = str_replace( 'With Additional Reporting by ', '', $contributor_field );
		$contributor_field = str_replace( 'Additional reporting by ', '', $contributor_field );
		$contributor_field = str_replace( ' and ', ', ', $contributor_field );
		$contributor_field = str_replace( ' AND ', ', ', $contributor_field );
		$contributor_field = str_replace( ', ', ',', $contributor_field );

		$author_names = explode( ',', $contributor_field );

		return $author_names;
	}

	/**
	 * @param array $component Component data.
	 *
	 * @return array Array of resulting blocks to be rendered with serialize_blocks().
	 */
	public function component_related_list_to_block( $component ) {
		$blocks = [];

		$li_elements = [];
		foreach ( $component['items'] as $item ) {
			$li_elements[] = sprintf( "<a href='%s'><strong>%s</strong></a>", $item['url'], $item['title'] );
		}

		if ( ! empty( $li_elements ) ) {
			$blocks[] = $this->gutenberg_blocks->get_separator( 'is-style-wide' );
			$blocks[] = $this->gutenberg_blocks->get_paragraph( '<strong>Related:</strong>' );
			$blocks[] = $this->gutenberg_blocks->get_list( $li_elements, true );
			$blocks[] = $this->gutenberg_blocks->get_separator( 'is-style-wide' );
		}

		return $blocks;
	}

	public function import_entries( $entries_path, $refresh_posts /*, $default_author_user_id */ ) {
		global $wpdb;

		// Loop through entries and import them.
		$entries_jsons = glob( $entries_path . '/*.json' );
		foreach ( $entries_jsons as $key_entry_json => $entry_json ) {
			WP_CLI::line( sprintf( "%d/%d", $key_entry_json + 1, count( $entries_jsons ) ) );

			$entry = json_decode( file_get_contents( $entry_json ), true );


			foreach ( $entry['author'] as $author ) {
				$au=$entry['url'];
				$af=$entry_json;
				$d=1;
			}

			if ( $entry['contributors'] && ! empty( $entry['contributors'] ) ) {
				$au=$entry['url'];
				$af=$entry_json;
$entry['contributors'][0]['authorProfile']['user']['uid'];
				$d=1;
			}

			/**
			 * Import only published entries of type STORY.
			 */
			if ( 'PUBLISHED' != $entry['publishStatus'] ) {
				continue;
			}
			if ( 'Entry' != $entry['__typename'] ) {
				continue;
			}
			if ( 'STORY' != $entry['type'] ) {
				continue;
			}

			/**
			 * Post creation arguments.
			 */
			$post_create_args = [
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => $entry['title'],
			];

			/**
			 * Excerpt.
			 */
			if ( isset( $entry['dek']['html'] ) && ! empty( $entry['dek']['html'] ) ) {
				$post_create_args['post_excerpt'] = $entry['dek']['html'];
			}

			/**
			 * Post date.
			 */
			$publish_date = $this->format_date( $entry['publishDate'] );
			if ( ! $publish_date ) {
				$publish_date = date('Y-m-d H:i:s' );
			}
			$post_create_args['post_date'] = $publish_date;

			/**
			 * Slug.
			 */
			$url_parsed = parse_url( $entry['url'] );
			$path_exploded = explode( '/', $url_parsed['path'] );
			$slug = $path_exploded[ count( $path_exploded ) - 1 ];
			$post_create_args['post_name'] = $slug;

			/**
			 * Insert post.
			 */
			$post_id = wp_insert_post( $post_create_args, true );
			if ( is_wp_error( $post_id ) ) {
				$err = $post_id->get_error_message();
				$this->logger->log( 'chorus__error__insert_post.log', "uid: {$entry['uid']} errorInserting: ". $err );
				continue;
			}

			/**
			 * Convert all Chorus entry's "components" to Gutenberg blocks.
			 * (Needs to happen after post creation because some blocks need the post ID.)
			 */
			$blocks = [];
			foreach ( $entry['body']['components'] as $component ) {

				// Get conversion method name.
				$method = self::COMPONENT_CONVERTERS[ $component['__typename'] ]['method'];
				if ( is_null( $method ) ) {
					continue;
				}

				// Get arguments.
				$arguments = [];
				foreach ( self::COMPONENT_CONVERTERS[ $component['__typename'] ]['arguments'] as $key_argument => $argument ) {
					if ( ! isset( $$argument ) ) {
						throw new \RuntimeException( sprintf( "Argument $%s not set in context and can't be passed to method %s() as argument number %d.", $argument, $method, $key_argument ) );
					}
					$arguments[] = $$argument;
				}

				// Call the method and get resulting blocks.
				$blocks = array_merge( $blocks, call_user_func_array( 'self::' . $method, $arguments ) );
			}

			// Update post data all at once.
			$post_update_data = [];

			/**
			 * Get post_content.
			 */
			$post_content = serialize_blocks( $blocks );
			$post_update_data['post_content'] = $post_content;

			/**
			 * Import featured image.
			 */
			if ( isset( $entry['leadImage']['asset'] ) && ! empty( $entry['leadImage']['asset'] ) ) {
				if ( 'IMAGE' != $entry['leadImage']['asset']['type'] ) {
					continue;
				}
				$url = $entry['leadImage']['asset']['url'];
				$credit = $entry['leadImage']['asset']['credit']['html'];
				$title = $entry['leadImage']['asset']['title'];
				$caption = $entry['leadImage']['asset']['sourceCaption'];

				// Download featured image.
				$attachment_id = $this->attachments->import_external_file( $url, $title, $caption, null, null, $post_id );
				if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
					$this->logger->log( 'chorus__error__import_featured_image.log', "url: {$url} errorInserting: ". ( is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'na/' ) );
					break;
				}

				// Set is as featured image.
				set_post_thumbnail( $post_id, $attachment_id );

				// Save credit as Newspack credit.
				update_post_meta( $attachment_id, '_media_credit', $credit );

				// Set Newspack featured image position.
				if ( $entry['layoutTemplate'] ) {
					update_post_meta( $post_id, 'newspack_featured_image_position', self::FEATURED_IMAGE_POSITION_MAPPING[ $entry['layoutTemplate'] ] );
				}
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

			// "Contributors" are regular GAs.
			if ( $entry['contributors'] && ! empty( $entry['contributors'] ) ) {
				foreach ( $entry['contributors'] as $contributor ) {
					$ga_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = %s and meta_value = %s", 'newspack_chorus_author_uid', $contributor['authorProfile']['uid'] ) );
					if ( ! $ga_id ) {
						$d=1;
					}
					$ga_ids[] = $ga_id;
				}
			}

			// "Additional contributors" are also GAs, but they should get "additional reporting by" in front of their name. For now let's just save postmeta.
			$ga_ids_additional_contributors = [];
			if ( $entry['additionalContributors'] && ! empty( trim( $entry['additionalContributors']['plaintext'] ) ) ) {
				$author_names = $this->get_author_names_from_additional_contributors_field( $entry['additionalContributors']['plaintext'] );
				foreach ( $author_names as $author_name ) {
					$ga_id = $this->coauthors_plus->get_guest_author_by_display_name( $author_name );
					if ( ! $ga_id ) {
						$d=1;
					}
					$ga_ids[] = $ga_id;
					$ga_ids_additional_contributors[] = $ga_id;
				}
			}
			// Save meta for additional contributors.
			foreach ( $ga_ids_additional_contributors as $ga_id ) {
				add_post_meta( $post_id, 'newspack_chorus_additional_contributor_ga_id', $ga_id );
			}

			// Assign all co-authors.
			$this->coauthors_plus->assign_guest_authors_to_post( $ga_ids, $post_id );

			/**
			 * Categories.
			 */
			$category_ids = [];

			// Set primary.
			$category_name_primary = $entry['primaryCommunityGroup']['name'];
			$category_primary_term_id = $this->taxonomy->get_or_create_category_by_name_and_parent_id( $category_name_primary, 0 );
			update_post_meta( $post_id, '_yoast_wpseo_primary_category', $category_primary_term_id );
			$category_ids[] = $category_primary_term_id;

			// Set other categories.
			foreach ( $entry['communityGroups'] as $community_group ) {
				$category_name = $community_group['name'];
				$category_term_id = $this->taxonomy->get_or_create_category_by_name_and_parent_id( $category_name, 0 );
				$category_ids[] = $category_term_id;
			}

			// Set post categories.
			wp_set_post_categories( $post_id, $category_ids );

			/**
			 * Updated date.
			 */
			$updated_date = $this->format_date( $entry['updatedAt'] );
			if ( $updated_date ) {
				$post_update_data['post_modified'] = $updated_date;
				$post_update_data['post_modified_gmt'] = $updated_date;
			}

			/**
			 * Update all remaining post data.
			 */
			$wpdb->update( $wpdb->posts, $post_update_data, [ 'ID' => $post_id ] );

			/**
			 * Set post meta.
			 */
			$meta = [
				'newspack_chorus_entry_uid' => $entry['uid'],
				'newspack_chorus_entry_url' => $entry['url'],
			];
			foreach ( $meta as $meta_key => $meta_value ) {
				update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}

		$d=1;
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
