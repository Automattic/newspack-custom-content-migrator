<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use DateTime;
use DateTimeZone;
use DOMNode;
use Exception;
use NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use NewspackCustomContentMigrator\MigrationLogic\Attachments;
use \WP_CLI;
use WP_Error;
use WP_User;

/**
 * EnergeticCityMigrator.
 */
class EnergeticCityMigrator implements InterfaceMigrator {

	/**
	 * EnergeticCityMigrator Instance.
	 *
	 * @var EnergeticCityMigrator
	 */
	private static $instance;

    /**
     * Log File Path.
     *
     * @var string $log_file_path
     */
    private string $log_file_path = '';

    /**
     * @var Attachments $attachments_logic
     */
    protected Attachments $attachments_logic;

	/**
	 * Constructor.
	 */
	public function __construct() {
        $this->attachments_logic = new Attachments();
	}

	/**
	 * Get Instance.
	 *
	 * @return EnergeticCityMigrator
	 */
	public static function get_instance() {
		$class = get_called_class();

		if ( null === self::$instance ) {
			self::$instance = new $class();
		}

		return self::$instance;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator energetic-city-fix-missing-featured-images',
			[ $this, 'fix_missing_featured_images' ],
			[
				'shortdesc' => 'Will attempt to tie featured images to posts.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator energetic-city-update-posts',
			[ $this, 'update_posts' ],
			[
				'shortdesc' => 'Will fix the metadata for posts that were brought in from an XML import.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator energetic-city-move-posts',
			[ $this, 'move_posts' ],
			[
				'shortdesc' => 'Will copy and paste posts from missing data set to live data set.',
				'synopsis'  => [],
			]
		);
        WP_CLI::add_command(
            'newspack-content-migrator energetic-city-reimport-authors',
            [ $this, 'cmd_reimport_authors' ],
            [
                'shortdesc' => 'This script will handle a custom XML the pub provided to attempt to reimport content with Authors properly created',
                'synopsis'  => []
            ]
        );
        WP_CLI::add_command(
            'newspack-content-migrator energetic-city-handle-extra-xml-export',
            [ $this, 'cmd_handle_extra_xml_export' ],
            [
                'shortdesc' => 'This script will handle another XML import that was given to us by the pub.',
                'synopsis'  => [],
            ]
        );
	}

	/**
	 * Handler.
	 */
	public function fix_missing_featured_images() {
		/*
		 * Find all posts with post_title beginning with https://media.socastsrm.com
		 * For each post_title, parse and obtain last path string
		 * remove any extension
		 * use that string to search in posts table for matching post_name
		 * if found, delete any wp_postmeta row for post with meta_key = '_thumbnail_id'
		 * then create new row with meta_key = '_thumbnail_id', post_id, and meta_value = to the media post_id
		 */

		global $wpdb;

		$image_posts = $wpdb->get_results( "SELECT ID, post_name, post_title, post_type FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title LIKE 'https://media.socastsrm.com%'" );

		foreach ( $image_posts as $post ) {
			$path          = parse_url( $post->post_title, PHP_URL_PATH );
			$exploded_path = explode( '/', $path );
			$filename      = array_pop( $exploded_path );
			$position      = strrpos( $filename, '.', - 1 );

			if ( false !== $position ) {
				$filename = substr( $filename, 0, $position );
			}

			WP_CLI::line( $filename );
			$found_post = $wpdb->get_row( "SELECT ID, post_name, post_title, post_type FROM $wpdb->posts WHERE post_type = 'post' AND post_name = '$filename'" );

			if ( $found_post ) {
				$deleted = $wpdb->delete(
					$wpdb->postmeta,
					[
						'meta_key' => '_thumbnail_id',
						'post_id'  => $post->ID,
					]
				);

				if ( false !== $deleted ) {
					WP_CLI::line( "Removed $deleted featured image rows in $wpdb->postmeta" );
				}

				$wpdb->insert(
					$wpdb->postmeta,
					[
						'meta_key'   => '_thumbnail_id',
						'meta_value' => $found_post->ID,
						'post_id'    => $post->ID,
					]
				);
				WP_CLI::line( 'Updated.' );
			}
		}
	}

	/**
	 * THIS NOT SHOULD NOT BE RUN THE SITE. LOCAL ONLY.
	 *
	 * @throws Exception
	 */
	public function update_posts() {
		global $wpdb;

		$posts = $wpdb->get_results(
			"SELECT p.*, IF(lu.user_nicename IS NOT NULL, lu.ID, 41) as live_post_author
                  FROM $wpdb->posts p
                           LEFT JOIN $wpdb->users u ON p.post_author = u.ID
                           LEFT JOIN live_users lu ON u.user_nicename = lu.user_nicename"
		);

		foreach ( $posts as $post ) {
			$post_date          = new DateTime( $post->post_date, new DateTimeZone( 'America/Edmonton' ) );
			$post_modified_date = new DateTime( $post->post_modified, new DateTimeZone( 'America/Edmonton' ) );
			$post_date->setTimezone( new DateTimeZone( 'GMT' ) );
			$post_modified_date->setTimezone( new DateTimeZone( 'GMT' ) );

			$wpdb->update(
				$wpdb->posts,
				[
					'post_author'       => $post->live_post_author,
					'post_date_gmt'     => $post_date->format( 'Y-m-d H:i:s' ),
					'post_modified_gmt' => $post_date->format( 'Y-m-d H:i:s' ),
					'post_name'         => sanitize_title_with_dashes( $post->post_title ),
					'post_status'       => 'publish',
					'comment_status'    => 'closed',
					'ping_status'       => 'closed',
					'guid'              => "https://energeticcity.ca/?p=$post->ID",
				],
				[
					'ID' => $post->ID,
				]
			);
		}
	}

	/**
	 * Will copy posts that were recovered and inserted into missing_2_posts table into the main wp_posts table.
	 */
	public function move_posts() {
		global $wpdb;

		$missing_posts = $wpdb->get_results( 'SELECT * FROM missing_2_posts' );

		foreach ( $missing_posts as $post ) {

			$new_post_id = wp_insert_post(
				[
					'post_author'           => $post->post_author,
					'post_date'             => $post->post_date,
					'post_date_gmt'         => $post->post_date_gmt,
					'post_content'          => $post->post_content,
					'post_title'            => $post->post_title,
					'post_excerpt'          => $post->post_excerpt,
					'post_status'           => $post->post_status,
					'comment_status'        => $post->comment_status,
					'post_password'         => $post->post_password,
					'post_name'             => $post->post_name,
					'to_ping'               => $post->to_ping,
					'pinged'                => $post->pinged,
					'post_modified'         => $post->post_modified,
					'post_modified_gmt'     => $post->post_modified_gmt,
					'post_content_filtered' => $post->post_content_filtered,
					'post_parent'           => $post->post_parent,
					'guid'                  => $post->guid,
					'menu_order'            => $post->menu_order,
					'post_type'             => $post->post_type,
					'post_mime_type'        => $post->post_mime_type,
					'comment_count'         => $post->comment_count,
				]
			);

			if ( $new_post_id ) {
				WP_CLI::line( "Old Post ID: $post->ID New Post ID: $new_post_id" );
				$wpdb->update(
					$wpdb->posts,
					[
						'guid' => "https://energeticcity.ca/?p=$new_post_id",
					],
					[
						'ID' => $new_post_id,
					]
				);
			}
		}
	}

    public function cmd_reimport_authors()
    {
        $xml_path = get_home_path() . 'Posts-Export-2014-2020_fixed.formatted.xml';
        $xml = new \DOMDocument();
        $xml->loadXML( file_get_contents( $xml_path ) );

        $posts = $xml->getElementsByTagName('post');

        foreach ($posts as $post) {
            $title = $content = $excerpt = $date = $post_type = $guid = $author_email = $modified_date = '';
            /* @var DOMNode $post */
            foreach ($post->childNodes as $childNode) {
                switch ($childNode->nodeName) {
                    case 'Title':
                        $title = $childNode->nodeValue;
                        break;
                    case 'Content':
                        $content = $childNode->nodeValue;
                        break;
                    case 'Excerpt':
                        $excerpt = $childNode->nodeValue;
                        break;
                    case 'Date':
                        $date = date_create_from_format( 'Y-m-d H:i:s', $childNode->nodeValue );
                        break;
                    case 'PostType':
                        $post_type = $childNode->nodeValue;
                        break;
                    case 'Permalink':
                        $guid = $childNode->nodeValue;
                        break;
                    case 'AuthorEmail':
                        $author_email = $childNode->nodeValue;
                        break;
                    case 'PostModifiedDate':
                        $modified_date = date_create_from_format( 'Y-m-d H:i:s', $childNode->nodeValue );
                        break;
                }
            }

            $url_path = parse_url( $guid, PHP_URL_PATH );
            $exploded = array_filter( explode( '/', $url_path ) );
            $last_path = array_pop( $exploded );

            $this->log( "$title\t$last_path\t$author_email" );

            $formatted_date = $date->format( 'Y-m-d H:i:s' );
            global $wpdb;

            $db_post = $wpdb->get_row(
                "SELECT 
                    p.ID as post_id,
                    u.*
                FROM $wpdb->posts p
                LEFT JOIN $wpdb->users u ON p.post_author = u.ID
                WHERE post_name = '$last_path' 
                  AND post_date = '$formatted_date' 
                  AND post_type = '$post_type'
                  AND post_status = 'publish'"
            );

            if ( is_null( $db_post ) ) {
                $db_post = $wpdb->get_row(
                    "SELECT 
                    p.ID as post_id,
                    u.*
                FROM $wpdb->posts p
                LEFT JOIN $wpdb->users u ON p.post_author = u.ID
                WHERE post_title = '$title' 
                  AND post_date = '$formatted_date' 
                  AND post_type = '$post_type'
                  AND post_status = 'publish'"
                );
            }

            $found_post = ! is_null( $db_post ) ? 'YES' : 'NO';
            $this->log( "FOUND POST? $found_post" );
            if ( is_null( $db_post ) ) {

                $user_id = $this->get_or_create_user_id( $author_email );

                $post_data = [
                    'post_author' => $user_id,
                    'post_date' => $formatted_date,
                    'post_date_gmt' => $formatted_date,
                    'post_content' => $content,
                    'post_title' => $title,
                    'post_excerpt' => $excerpt,
                    'post_status' => 'publish',
                    'post_type' => $post_type,
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'post_name' => $last_path,
                    'post_modified' => $modified_date->format( 'Y-m-d H:i:s' ),
                    'guid' => $guid,
                ];

                $result = wp_insert_post( $post_data );

                if ( 0 === $result ) {
                    $this->log( 'UNABLE TO CREATE POST' );
                } else if ( $result instanceof WP_Error ) {
                    $this->log( 'UNABLE TO CREATE POST:' . $result->get_error_message() );
                } else {
                    $this->log( "POST CREATED: $result" );
                }
            } else {
                $authors_match = $author_email === $db_post->user_email ? 'YES' : 'NO';

                $this->log( "AUTHOR'S MATCH?: $authors_match\t$author_email\t$db_post->user_email" );

                if ( ! $author_email !== $db_post->user_email ) {
                    $user_id = $this->get_or_create_user_id( $author_email );

                    $wpdb->update(
                        $wpdb->posts,
                        [
                            'post_author' => $user_id,
                        ],
                        [
                            'ID' => $db_post->post_id,
                        ]
                    );
                }
            }
        }
    }

    public function cmd_handle_extra_xml_export()
    {
        $xml_path = get_home_path() . 'energetic_city_export.xml';
        $xml = new \DOMDocument();
        $xml->loadXML( file_get_contents( $xml_path ) );

        $rss = $xml->getElementsByTagName( 'rss' )->item( 0 );

        /* @var DOMNodeList $channel_children */
        $channel_children = $rss->childNodes->item( 1 )->childNodes;

        $posts = [];
        $authors = [];
        foreach ( $channel_children as $child ) {
            /* @var DOMNode $child */
            if ( 'wp:author' === $child->nodeName ) {
                try {
                    $author = $this->handle_xml_author($child);
                    $authors[ $author->user_login ] = $author;
                } catch ( Exception $e ) {
                    // Continue.
                }
            } else if ( 'item' === $child->nodeName ) {
                $posts[] = $this->handle_xml_item( $child, $authors );
            }
        }

        global $wpdb;
        foreach ( $posts as $post ) {
            $categories = $post['categories'];
            /* @var WP_USER $author */
            $author = $post['author'];
            $post = $post['post'];

            $date = date_create_from_format( 'Y-m-d H:i:s', $post['post_date'] );
            $date_string = $date->format( 'Y-m-d' );
            $hour_string = $date->format( 'H' );
            $result = $wpdb->get_row(
                "SELECT 
                    p.ID as post_id,
                    u.ID as author_id,
                    u.user_email
                FROM $wpdb->posts p
                LEFT JOIN $wpdb->users u ON p.post_author = u.ID
                WHERE post_name = '{$post['post_name']}' 
                  AND post_type = 'post' 
                  AND DATE(post_date) = '$date_string'
                  AND HOUR(post_date) = '$hour_string'"
            );

            if ( is_null( $result ) ) {
                WP_CLI::line( "CREATING: {$post['post_name']}\t{$post['post_date']}" );
                $post_id = wp_insert_post($post);

                foreach ($categories as &$category) {
                    $exists = category_exists($category['cat_name']);

                    if (is_null($exists)) {
                        $result = wp_insert_category($category);

                        if (is_int($result) && $result > 0) {
                            $category = $result;
                        }
                    } else {
                        $category = (int)$exists;
                    }
                }

                wp_set_post_categories($post_id, $categories);
            } else {
                WP_CLI::line( "POST ALREADY EXISTS: {$post['post_name']}\t{$post['post_date']}" );
                WP_CLI::line( "POST AUTHOR: $result->user_email" );
                if ( ! is_null( $author ) ) {
                    WP_CLI::line( "INTENDED AUTHOR: $author->user_email" );
                }
                if ( ! is_null( $author ) && $result->user_email != $author->user_email ) {
                    $update = wp_update_post(
                        [
                            'ID'          => $result->post_id,
                            'post_author' => $author->ID,
                        ]
                    );

                    if ( ! ( $update instanceof WP_Error ) ) {
                        WP_CLI::line( 'UPDATED' );
                    }
                }
            }
        }
    }

    /**
     * @param DOMNode $author
     *
     * @return false|WP_User
     * @throws Exception
     */
    private function handle_xml_author(DOMNode $author ) {
        $author_data = [
            'user_login'   => '',
            'user_email'   => '',
            'display_name' => '',
            'first_name'   => '',
            'last_name'    => '',
            'role'         => 'author',
            'user_pass'    => wp_generate_password( 24 ),
        ];

        foreach ( $author->childNodes as $node ) {
            /* @var DOMNode $node */
            $nodeName = $node->nodeName;

            switch ( $nodeName ) {
                case 'wp:author_login':
                    $author_data['user_login'] = $node->nodeValue;
                    break;
                case 'wp:author_email':
                    if ( str_contains( $node->nodeValue, 'socastdigital.com' ) ) {
                        throw new Exception( 'Avoiding socast emails' );
                    }
                    $author_data['user_email'] = $node->nodeValue;
                    break;
                case 'wp:author_display_name':
                    $author_data['display_name'] = $node->nodeValue;
                    break;
                case 'wp:author_first_name':
                    $author_data['first_name'] = $node->nodeValue;
                    break;
                case 'wp:author_last_name':
                    $author_data['last_name'] = $node->nodeValue;
                    break;
            }
        }

        $user = get_user_by( 'login', $author_data['user_login'] );

        if ( false === $user ) {
            $user_id = wp_insert_user( $author_data );

            if ( $user_id instanceof WP_Error ) {
                throw new Exception( 'Could not create user' );
            }

            $user = get_user_by( 'id', $user_id );
        }

        return $user;
    }

    /**
     * Handles XML <item>'s from provided file to import as Posts.
     *
     * @param DOMNode $item XML <item>.
     * @param array $authors Recently imported authors.
     *
     * @return array
     */
    private function handle_xml_item( DOMNode $item, array $authors = [] ) {
        $post                      = [
            'post_type'     => 'post',
            'meta_input'    => [],
        ];
        $categories = [];
        $tags       = [];
        $author     = null;

        foreach ( $item->childNodes as $child ) {
            /* @var DOMNode $child */
            if ( 'title' === $child->nodeName ) {
                $post['post_title'] = $child->nodeValue;
            }

            if ( 'dc:creator' === $child->nodeName ) {
                $post['post_author'] = $authors[ $child->nodeValue ]->ID ?? 0;
                $author = $authors[ $child->nodeValue ] ?? null;
            }

            if ( 'guid' === $child->nodeName ) {
                $post['guid'] = $child->nodeValue;
            }

            if ( 'content:encoded' === $child->nodeName ) {
                $post['post_content'] = $child->nodeValue;
            }

            if ( 'excerpt:encoded' === $child->nodeName ) {
                $post['post_excerpt'] = $child->nodeValue;
            }

            if ( 'wp:post_date' === $child->nodeName ) {
                $post['post_date'] = $child->nodeValue;
            }

            if ( 'wp:post_date_gmt' === $child->nodeName ) {
                $post['post_date_gmt'] = $child->nodeValue;
            }

            if ( 'wp:post_modified' === $child->nodeName ) {
                $post['post_modified'] = $child->nodeValue;
            }

            if ( 'wp:post_modified_gmt' === $child->nodeName ) {
                $post['post_modified_gmt'] = $child->nodeValue;
            }

            if ( 'wp:comment_status' === $child->nodeName ) {
                $post['comment_status'] = $child->nodeValue;
            }

            if ( 'wp:ping_status' === $child->nodeName ) {
                $post['ping_status'] = $child->nodeValue;
            }

            if ( 'wp:status' === $child->nodeName ) {
                $post['post_status'] = $child->nodeValue;
            }

            if ( 'wp:post_name' === $child->nodeName ) {
                $post['post_name'] = $child->nodeValue;
            }

            if ( 'wp:post_parent' === $child->nodeName ) {
                $post['post_parent'] = $child->nodeValue;
            }

            if ( 'wp:menu_order' === $child->nodeName ) {
                $post['menu_order'] = $child->nodeValue;
            }

            if ( 'wp:post_type' === $child->nodeName ) {
                $post['post_type'] = $child->nodeValue;
            }

            if ( 'wp:post_password' === $child->nodeName ) {
                $post['post_password'] = $child->nodeValue;
            }

            if ( 'wp:postmeta' === $child->nodeName ) {
                $meta_key   = $child->childNodes->item( 1 )->nodeValue;
                $meta_value = trim( $child->childNodes->item( 3 )->nodeValue );

                if ( empty( $meta_value ) || str_starts_with( $meta_value, 'field_' ) ) {
                    continue;
                }

                switch ( $meta_key ) {
                    case '_thumbnail_url';
                        $attachment_id = $this->attachments_logic->import_external_file( $meta_value );

                        if ( is_int( $attachment_id ) ) {
                            $post['meta_input']['_thumbnail_id'] = $attachment_id;
                        }
                        break;
                }
            }

            if ( 'category' === $child->nodeName ) {
                $categories[] = [
                    'cat_name'          => htmlspecialchars_decode( $child->nodeValue ),
                    'category_nicename' => $child->attributes->getNamedItem( 'nicename' )->nodeValue,
                ];
            }
        }

        return [
            'post'       => $post,
            'author'     => $author,
            'tags'       => $tags,
            'categories' => $categories,
        ];
    }

    private function log(string $message)
    {
        if ( empty( $this->log_file_path ) ) {
            $timestamp = date( 'Ymd_His', time() );
            $this->log_file_path = get_home_path() . "author_fix_$timestamp.log";
            file_put_contents( $this->log_file_path, '' );
        }

        WP_CLI::log( $message );
        file_put_contents( $this->log_file_path, "$message\n", FILE_APPEND );
    }

    private function get_or_create_user_id( string $email )
    {
        $user = get_user_by( 'email', $email );

        if ( $user instanceof WP_User) {
            $this->log( "USER EXISTS: $user->ID" );
            return $user->ID;
        }

        $this->log( "CREATING USER: $email" );
        return wp_create_user(
            substr( $email, 0, strpos( $email, '@' ) ),
            wp_generate_password( 32 ),
            $email
        );
    }
}
