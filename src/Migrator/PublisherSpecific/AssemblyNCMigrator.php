<?php
namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use NewspackCustomContentMigrator\MigrationLogic\CoAuthorPlus;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use Parsedown;

/**
 * Custom migration scripts for Bethesda Mag.
 */
class AssemblyNCMigrator implements InterfaceMigrator {

    /**
     * @var null|InterfaceMigrator Instance.
     */
    private static ?InterfaceMigrator $instance = null;

    /**
     * @var Parsedown $parsedown
     */
    protected Parsedown $parsedown;

    /**
     * @var CoAuthorPlus $coAuthorPlus
     */
    protected CoAuthorPlus $coAuthorPlus;

    /**
     * @var array|string[] $header_attributes
     */
    protected array $header_attributes = [
        'templateKey',
        'title',
        'description',
        'category',
        'date',
        'author',
        'form',
        'heroArticle',
        'trendingArticle',
        'featuredImage',
        'seo',
        'image'
    ];

    /**
     * @var string $image_template
     */
    protected string $image_template = '<!-- wp:image {"id":{attachment_id},"sizeSlug":"full","linkDestination":"none"} -->
<figure class="wp-block-image size-full">
<img src="{url}" alt="" class="wp-image-{attachment_id}"/>{image_caption}
</figure><!-- /wp:image -->';

    /**
     * @var string $caption_template
     */
    protected string $caption_template = '<figcaption>{caption}</figcaption>';

    /**
     * Singleton get_instance().
     *
     * @return InterfaceMigrator|null
     */
    public static function get_instance(): ?InterfaceMigrator
    {
        $class = get_called_class();
        if ( null === self::$instance ) {
            self::$instance = new $class();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->parsedown = new Parsedown();
        $this->coAuthorPlus = new CoAuthorPlus();
    }

    public function register_commands()
    {
        WP_CLI::add_command(
            'newspack-content-migrator asssemblync-import-content',
            [ $this, 'cmd_import_content' ],
            [
                'shortdesc' => "This command will import Assembly NC's from their github repository.",
                'synopsis'  => [
                    [
                        'type'        => 'flag',
                        'name'        => 'reset-db',
                        'description' => 'This flag determines whether to clear the DB before executing the operation',
                        'optional'    => true,
                    ]
                ],
            ]
        );
    }

    /**
     * @param array $args
     * @param array $assoc_args
     */
    public function cmd_import_content( $args, $assoc_args )
    {
        $clear_db = $assoc_args['reset-db'] ?? false;

        if ( $clear_db ) {
            WP_CLI::runcommand(
                'db reset --yes --defaults',
                [
                    'return' => true,
                    'parse' => 'json',
                    'launch' => false,
                    'exit_error' => true,
                ]
            );

            $output = shell_exec(
                'wp core install --url=http://localhost:10008 --title=AssemblyNC --admin_user=edc598 --admin_email=edc598@gmail.com'
            );
            echo $output;

            shell_exec( 'wp user update edc598 --user_pass=ilovenews' );

            shell_exec( 'wp plugin activate newspack-custom-content-migrator' );

            shell_exec( 'wp plugin install co-authors-plus --activate' );
        }

        $home_path = get_home_path();
        $repo_path = "$home_path../../the-assembly-2021";


        $categories = $this->handle_categories( $repo_path );
        $authors = $this->handle_authors( $repo_path );
        $images = $this->handle_images( $repo_path );

        $this->handle_posts( $repo_path, $categories, $authors, $images );
//        $this->handle_posts( $repo_path );

//        $file = file_get_contents( "$repo_path/src/content/articles/apples-big-bite.md" );

//        echo $parsedown->text($file);

    }

    /**
     * This function will handle the custom formatting for categories,
     * based on .md files.
     *
     * @param string $repo_path
     * @return array
     */
    public function handle_categories( string $repo_path ): array
    {
        $categories = [];

        $full_path = "$repo_path/src/content/categories";

        $categories_in_path = scandir( $full_path );

        if (is_array($categories_in_path)) {
            $categories_in_path = array_diff( $categories_in_path, [ '.','..' ] );
            foreach ($categories_in_path as $category_file) {
                $content = file_get_contents( "$full_path/$category_file" );
                $lines = explode( "\n", $content );
                $title_line = $lines['1'];
                $title = substr( $title_line, 7 ); // 7 = length of "title: ".
                if (array_key_exists($title, $categories)) {
                    WP_CLI::warning( "$title already exists in categories array" );
                }
                $categories[ $title ] = wp_create_category( $title );
            }
        }

        return $categories;
    }

    /**
     * This function will handle the custom formatting for authors,
     * based on .md files.
     *
     * @param string $repo_path
     * @return array
     */
    public function handle_authors( string $repo_path ): array
    {
        $authors = [];

        $full_path = "$repo_path/src/content/authors";

        $authors_in_path = scandir( $full_path );

        if (is_array($authors_in_path)) {
            $authors_in_path = array_diff( $authors_in_path, [ '.', '..' ] );
            foreach ($authors_in_path as $author_file) {
                $content = file_get_contents( "$full_path/$author_file" );
                $lines = explode( "\n", $content );
                $trimmed_first_name = trim( $lines[2] );
                $trimmed_last_name = trim( $lines[3] );

                $first_name = substr( $trimmed_first_name, 7 ); // 7 = length of "first: "
                $last_name = substr( $trimmed_last_name, 6 ); // 6 = length of "last: "

                // Sometimes, last name is listed in $lines[2] and first in $lines[3]
                if ( str_starts_with( $trimmed_last_name, 'first' ) ) {
                    $first_name = substr( $trimmed_last_name, 7 );
                    $last_name = substr( $trimmed_first_name, 6 );
                }

                if ( str_contains( $first_name, '"' ) ) {
                    $first_name = trim( str_replace( '"', '', $first_name ) );
                }

                if ( str_ends_with( $first_name, ' and' ) || str_starts_with( $last_name, 'and ' ) ) {
                    if ( str_ends_with( $first_name, ' and' ) ) {
                        // Must treat $first_name and $last_name as 2 separate names.
                        $first_name = substr( $first_name, 0, -4 );
                    } else if ( str_starts_with( $last_name, 'and ' ) ) {
                        $last_name = substr( $last_name, 4 );
                    }

                    $first_user = $this->handle_author_creation( $first_name );
                    $authors[ "$first_user->first_name $first_user->last_name" ] = $first_user;
                    $second_user = $this->handle_author_creation( $last_name );
                    $authors[ "$second_user->first_name $second_user->last_name" ] = $second_user;
                } else {
                    $authors[ "$first_name $last_name" ] = $this->handle_author_creation( "$first_name $last_name" );
                }
            }
        }

        return $authors;
    }

    /**
     * This function will handle generating the necessary post
     * attachment data for proper display within posts.
     *
     * @param string $repo_path
     * @return array
     */
    public function handle_images( string $repo_path ): array
    {
        $images = [];

        $full_path = "$repo_path/static/img/cms";

        $upload_dir = wp_upload_dir();

        shell_exec("rsync -a ../../the-assembly-2021/static/img/cms/ ../../app/public/wp-content/uploads" );

        $images_in_path = scandir( $full_path );

        if ( is_array( $images_in_path ) ) {
            $images_in_path = array_diff( $images_in_path, [ '.', '..' ] );
            foreach ($images_in_path as $image_file) {
                $local_filename = "{$upload_dir['basedir']}/$image_file";
                $file_type = wp_check_filetype( $local_filename );
                $result = wp_insert_attachment(
                    [
                        'post_author' => 0,
                        'post_mime_type' => $file_type['type'],
                        'post_title' => preg_replace( '/\.[^.]+$/', '', $image_file ),
                        'post_name' => preg_replace( '/\.[^.]+$/', '', $image_file ),
                        'comment_status' => 'closed',
                        'guid' => "{$upload_dir['baseurl']}/$image_file",
                    ],
                    $local_filename,
                    0,
                    true
                );

                if (is_wp_error($result)) {
                    WP_CLI::warning( "Unable to insert $image_file, {$result->get_error_message()}" );
                } else {
                    $images["/img/cms/$image_file"] = get_post( $result );
                }
            }
        }

        return $images;
    }

    /**
     * Custom handler for Assembly posts.
     *
     * @param string $repo_path
     * @param array $categories
     * @param array $authors
     * @param array $images
     */
    public function handle_posts( string $repo_path, array $categories = [], array $authors = [], array $images = [] )
    {
        $full_path = "$repo_path/src/content/articles";

        $articles_in_path = scandir( $full_path );

        if ( is_array( $articles_in_path ) ) {
            $articles_in_path = array_diff( $articles_in_path, [ '.', '..' ] );
            $article_count = count( $articles_in_path );
            echo "$article_count\n";
            foreach ( $articles_in_path as $article_file ) {
                /*if ( $article_file != 'mccrae-dowless-last-spin.md' ) {
                    continue;
                }*/
                --$article_count;
                $content = file_get_contents( "$full_path/$article_file" );
                $content = htmlentities( $content, ENT_QUOTES, 'UTF-8' );
                $content = str_replace("&nbsp;", "", $content);

                $html_content = $this->parsedown->text( $content );

                $dom = new DOMDocument();
                @$dom->loadHTML( $html_content );

                $first_header = $dom->getElementsByTagName( 'h2' )->item( 0 );
                $text = $first_header->textContent;
                if ( str_contains( $text, 'seo:') ) {
                    $text = substr( $text, 0, strpos( $text, 'seo:' ) );
                }
                $first_header->parentNode->removeChild( $first_header );
                $post = $this->get_post_data( $text );
                echo $post['post_title'] . "\n";

                if ( array_key_exists( 'post_category', $post ) && array_key_exists( $post['post_category'], $categories ) ) {
                    $post['post_category'] = [ $categories[ $post['post_category'] ] ];
                } else {
                    unset( $post['post_category'] );
                }

                if ( array_key_exists( 'featured_image', $post ) && array_key_exists( $post['featured_image'], $images ) ) {
                    $post['meta_input']['_thumbnail_id'] = $images[ $post['featured_image' ] ];
                }

                unset( $post['featured_image'] );

                $guest_authors = [];
                if ( array_key_exists( 'post_author', $post ) ) {
//                    var_dump(['BEFORE GUEST' => $post['post_author']]);
                    $exploded_authors = explode( ' and ', $post['post_author'] );
//                    var_dump(['exploded' => $exploded_authors]);

                    if ( count( $exploded_authors ) > 1 ) {
                        foreach ($exploded_authors as $author) {
                            $guest_authors[] = $authors[ $author ];
                        }
                        unset($post['post_author']);
                    } else {
                        if ( array_key_exists( $post['post_author'], $authors ) ) {
                            $post['post_author'] = $authors[ $post['post_author'] ]->ID;
                        }
                    }
                }

                $final_html_content = '';

                foreach ( $dom->lastChild->firstChild->childNodes as $node ) {
                    /* @var DOMNode $node */
                    if ( 'hr' === $node->nodeName ) {
                        continue;
                    }

                    if ( 'p' === $node->nodeName ) {
                        /*var_dump(
                            [
                                $node->hasChildNodes(),
                                $node->childNodes->count(),
                                $node->hasChildNodes() ? $node->firstChild->nodeName : '',
                                $node
                            ]
                        );*/
                        if ( $node->hasChildNodes() && 'img' === $node->firstChild->nodeName ) {
                            $featured_image_html = '';
                            $content_image = $node->firstChild;
                            $source = $content_image->attributes->getNamedItem( 'src' );
                            /* @var DOMAttr $source */

                            if ( $source && array_key_exists( $source->nodeValue, $images ) ) {
                                $attachment_data = $images[$source->nodeValue];
                                $alt = $content_image->attributes->getNamedItem('alt');

                                $caption = '';
                                if ($alt && !empty($alt->nodeValue)) {
                                    $alt_text = $this->parsedown->line( $alt->textContent );

                                    while ( str_starts_with( $alt_text, '#' ) ) {
                                        $alt_text = substr( $alt_text, 1 );
                                    }

                                    $caption = strtr(
                                        $this->caption_template,
                                        [
                                            '{caption}' => trim( $alt_text ),
                                        ]
                                    );
                                }

                                $featured_image_html = strtr(
                                    $this->image_template,
                                    [
                                        '{image_caption}' => $caption,
                                        '{url}' => $attachment_data->guid,
                                        '{attachment_id}' => $attachment_data->ID,
                                    ]
                                );

//                                echo $final_html_content . $featured_image_html;die();

                                $final_html_content .= $featured_image_html;
                            }
                            continue;
                        }

                        if ( empty( $node->nodeValue ) ) {
                            continue;
                        }

                        if ( '***' === $node->textContent ) {
                            $final_html_content .= '<!-- wp:separator {"className":"is-style-dots"} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-dots"/>
<!-- /wp:separator -->';
                            continue;
                        }

                        $final_html_content .= '<!-- wp:paragraph -->' . $dom->saveHTML( $node ) . '<!-- /wp:paragraph -->';
                        continue;
                    }

                    if ( in_array( $node->nodeName, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] ) ) {
                        $level = '{"level":' . substr( $node->nodeName, -1 ) . '}';

                        $final_html_content .= "<!-- wp:heading $level -->" . $dom->saveHTML( $node ) . '<!-- /wp:heading -->';
                        continue;
                    }
                }

                $post['post_content'] = $final_html_content;
                $result = wp_insert_post( $post );

                if ( ! is_wp_error( $result ) ) {
                    if ( ! empty( $guest_authors ) ) {
//                        var_dump([ 'PRE GUEST AUTHORS' => $guest_authors ]);
                        $guest_authors = array_map(
                            function($guest_author) {
                                $db_guest_author = $this->coAuthorPlus->get_guest_author_by_user_login( $guest_author->user_login );
//                                var_dump(['DB_GUEST_AUTHOR' => $db_guest_author]);

                                if ( false === $db_guest_author ) {
                                    return $this->coAuthorPlus->create_guest_author_from_wp_user( $guest_author->ID );
//                                    var_dump(['CREATED_FROM_WP' => $db_guest_author]);
                                }

                                if ( is_wp_error( $db_guest_author ) ) {
                                    echo $db_guest_author->get_error_message();
                                    die();
                                } else {
                                    return $db_guest_author->ID;
                                }
                            },
                            $guest_authors);

//                        var_dump([ 'FINAL GUEST AUTHORS' => $guest_authors ]);

                        $this->coAuthorPlus->assign_guest_authors_to_post($guest_authors, $result);
                    }
                }
                echo "$article_count\n";
            }
        }
    }

    /**
     * Custom handler for Assembly posts.
     *
     * @param string $repo_path
     * @param array $categories
     * @param array $authors
     * @param array $images
     */
    public function handle_posts_orig( string $repo_path, array $categories = [], array $authors = [], array $images = [] )
    {
        $full_path = "$repo_path/src/content/articles";

        $articles_in_path = scandir( $full_path );

        if ( is_array( $articles_in_path ) ) {
            $articles_in_path = array_diff( $articles_in_path, [ '.', '..' ] );
            $article_count = count( $articles_in_path );
            echo "$article_count\n";
            foreach ( $articles_in_path as $article_file ) {
                if ( $article_file != 'greenville-richardson-murder-trial.md' ) {
                    continue;
                }
                --$article_count;
                $content = file_get_contents( "$full_path/$article_file" );
                $content = htmlentities( $content, ENT_QUOTES, 'UTF-8' );
                $content = str_replace("&nbsp;", "", $content);

                $html_content = $this->parsedown->text( $content );

                $dom = new DOMDocument();
                @$dom->loadHTML( $html_content );

                $first_header = $dom->getElementsByTagName( 'h2' )->item( 0 );
                $text = $first_header->textContent;
                if ( str_contains( $text, 'seo:') ) {
                    $text = substr( $text, 0, strpos( $text, 'seo:' ) );
                }
                $first_header->parentNode->removeChild( $first_header );
                $post = $this->get_post_data( $text );

                if ( array_key_exists( 'post_category', $post ) && array_key_exists( $post['post_category'], $categories ) ) {
                    $post['post_category'] = [ $categories[ $post['post_category'] ] ];
                } else {
                    unset( $post['post_category'] );
                }

                if ( array_key_exists( 'featured_image', $post ) && array_key_exists( $post['featured_image'], $images ) ) {
                    $post['meta_input']['_thumbnail_id'] = $images[ $post['featured_image' ] ];
                }

                unset( $post['featured_image'] );

                if ( array_key_exists( 'post_author', $post ) && array_key_exists( $post['post_author'], $authors ) ) {
                    $post['post_author'] = $authors[ $post['post_author'] ]->ID;
                }

                $content_images = $dom->getElementsByTagName( 'img' );
                foreach ($content_images as $content_image) {
                    /* @var DOMNode $content_image */
                    $source = $content_image->attributes->getNamedItem( 'src' );
                    /* @var DOMAttr $source */

                    if ( $source && array_key_exists( $source->nodeValue, $images ) ) {
                        $attachment_data = $images[ $source->nodeValue ];
                        $alt = $content_image->attributes->getNamedItem('alt');

                        $caption = '';
                        if ( $alt && ! empty( $alt->nodeValue )) {
                            $caption = strtr(
                                $this->caption_template,
                                [
                                    '{caption}' => $alt->nodeValue,
                                ]
                            );
                        }

                        $featured_image_html = strtr(
                            $this->image_template,
                            [
                                '{image_caption}' => $caption,
                                '{url}' => $attachment_data->guid,
                                '{attachment_id}' => $attachment_data->ID,
                            ]
                        );

                        $fragment = $dom->createDocumentFragment();
                        $fragment->appendXML( $featured_image_html );
                        $parent_of_paragraph_node = $content_image->parentNode->parentNode;
                        $parent_of_paragraph_node->replaceChild( $fragment, $content_image->parentNode );
                        var_dump('HERE');
                        echo $dom->saveHTML($parent_of_paragraph_node);
                        var_dump('NODE');
//                        $parent_node->removeChild( $content_image );
//                        echo $parent_node->ownerDocument->saveHTML($parent_node);die();
                    }
                }

                foreach ( $dom->lastChild->firstChild->childNodes as $node ) {
                    /* @var DOMNode $node */
                    if ( 'p' === $node->nodeName ) {
                        if ( empty( $node->nodeValue ) ) {
                            $node->parentNode->removeChild( $node );
                            continue;
                        }

                        $parent_node = $node->parentNode;
                        $header_comment = $dom->createComment( ' wp:paragraph ' );
                        $footer_comment = $dom->createComment( ' /wp:paragraph ' );
                        $parent_node->insertBefore( $header_comment, $node );
                        $parent_node->insertBefore( $footer_comment, $node->nextSibling );

//                        echo $dom->saveHTML( $parent_node );die();
                    }

                    if ( in_array( $node->nodeName, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] ) ) {
                        $level = '{"level":' . substr( $node->nodeName, -1 ) . '}';

                        $parent_node = $node->parentNode;
                        $header_comment = $dom->createComment( " wp:heading $level " );
                        $footer_comment = $dom->createComment( ' /wp:heading ' );
                        $parent_node->insertBefore( $header_comment, $node );
                        $parent_node->insertBefore( $footer_comment, $node->nextSibling );
                    }
                }

//                echo $this->get_inner_html( $dom->lastChild->firstChild );
//                file_put_contents( get_home_path() . 'output.html', '<html><body>' . $this->get_inner_html( $dom->lastChild->firstChild ) . '</body></html>' );
//                $dom->saveHTMLFile('output_html.html');
//                die();
                $post['post_content'] = $html_content;
                wp_insert_post( $post );
                echo "$article_count\n";
            }
        }
    }

    /**
     * @param string $text
     * @return array
     */
    private function get_post_data( string $text ): array {
        $post = [
            'post_author' => 0,
            'post_title' => '',
            'post_date' => '',
            'post_date_gmt' => '',
            'post_status' => 'publish',
            'meta_input' => [],
        ];
        $exploded = explode( "\n", $text );

        /*
         * The description attribute makes processing this text tricky.
         *
         * I've opted to remove attributes after they're processed,
         * leaving (hopefully) only description behind.
         * */
        foreach ( $exploded as $key => $line ) {
//            echo "$line\n";
            foreach ( $this->header_attributes as $header_attribute ) {
//                echo "\t$header_attribute\n";
                $exploded_line = explode( "$header_attribute: ", $line );

//                var_dump(count( $exploded_line ) > 1);
                if ( count( $exploded_line ) > 1 ) {
                    switch ( $header_attribute ) {
                        case 'title':
                            $post['post_title'] = $exploded_line[1];
                            unset( $exploded[ $key ] );
                            break;
                        case 'date':
                            $post['post_date'] = date( 'Y-m-d H:i:s', strtotime( $exploded_line[1] ) );
                            $post['post_date_gmt'] = $post['post_date'];
                            unset( $exploded[ $key ] );
                            break;
                        case 'author':
                            $post['post_author'] = preg_replace("/\s+/", " ", $exploded_line[1] );
                            unset( $exploded[ $key ] );
                            break;
                        case 'category':
                            $post['post_category'] = $exploded_line[1];
                            unset( $exploded[ $key ] );
                            break;
                        case 'featuredImage':
                            $post['featured_image'] = $exploded_line[1];
                            unset( $exploded[ $key ] );
                            break;
                        case 'templateKey':
                        case 'form':
                        case 'heroArticle':
                        case 'trendingArticle':
                        case 'image':
                            unset( $exploded[ $key ] );
                            break;
                    }
                }
            }
        }

        $exploded = array_filter( $exploded );
        $first_element = array_slice( $exploded, 0, 1 );

        if ( str_starts_with( array_shift( $first_element ), 'description:' ) ) {
            $description = implode( ' ', $exploded );

            if (str_starts_with( $description, 'description: > ')) {
                $description = substr( $description, 15 );
            } else if (str_starts_with( $description, 'description: ' ) ) {
                $description = substr( $description, 13 );
            }

            $post['meta_input']['newspack_post_subtitle'] = $description;
        }

        return $post;
    }

    private function get_full_name_attributes( string $name ): array {
        $exploded = explode( ' ', $name );
        $last_name = array_pop( $exploded );
        $first_name = implode( ' ', $exploded );

        return [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'full_name' => "$first_name $last_name",
        ];
    }

    private function convert_name_to_login( string $first_name, string $last_name )
    {
        return sanitize_title( "$first_name, $last_name" );
    }

    private function handle_author_creation( string $name )
    {
        $attributes = $this->get_full_name_attributes( $name );
        $first_name = $attributes['first_name'];
        $last_name = $attributes['last_name'];
        $full_name = $attributes['full_name'];

        $login = $this->convert_name_to_login( $first_name, $last_name );
        $email = "$login@assmeblync.com";

        $user = get_user_by( 'login', $login );

        if ($user) {
            return $user;
        }

        $user_id = wp_insert_user(
            [
                'user_pass' => wp_generate_password(),
                'user_login' => $login,
                'user_email' => $email,
                'display_name' => $full_name,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'role' => 'author',
            ]
        );

        return get_user_by( 'ID', $user_id );
    }

    private function get_inner_html( DOMElement $element ): string {
        $inner_html = '';

        $doc = $element->ownerDocument;

        foreach ( $element->childNodes as $node ) {
            $inner_html .= $doc->saveHTML( $node );
        }

        return $inner_html;
    }
}