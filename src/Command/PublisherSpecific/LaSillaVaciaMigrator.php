<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Exception;
use Generator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use \WP_CLI;

class LaSillaVaciaMigrator implements InterfaceCommand
{

    private $category_tree = [
        [
            'name' => 'Detector de Mentiras',
            'children' => [
                [
                    'name' => 'Detector en Facebook',
                    'children' => [],
                ],
                [
                    'name' => 'Gobierno Duque',
                    'children' => [],
                ],
                [
                    'name' => 'Coronavirus',
                    'children' => [],
                ],
                [
                    'name' => 'Elecciones 2022',
                    'children' => [],
                ],
                [
                    'name' => 'Detector al chat de la familia',
                    'children' => [],
                ],
                [
                    'name' => 'Cierto',
                    'children' => [],
                ],
                [
                    'name' => 'Cierto, pero',
                    'children' => [],
                ],
                [
                    'name' => 'Debatible',
                    'children' => [],
                ],
                [
                    'name' => 'Más falso que cierto',
                    'children' => [],
                ],
                [
                    'name' => 'Falso',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'Silla Datos',
            'children' => [
                [
                    'name' => 'Poder nacional',
                    'children' => [],
                ],
                [
                    'name' => 'Poder regional',
                    'children' => [],
                ],
                [
                    'name' => 'Acuerdo de paz y posconflicto',
                    'children' => [],
                ],
                [
                    'name' => 'Contractación',
                    'children' => [],
                ],
                [
                    'name' => 'Poder de las empresas',
                    'children' => [],
                ],
                [
                    'name' => 'Caso Uribe',
                    'children' => [],
                ]
            ]
        ],
        [
            'name' => 'Silla Nacional',
            'children' => [
                [
                    'name' => 'Antioquia', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Bogotá', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Caribe', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Elecciones 2023', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Gustavo Petro', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Pacífico', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'En vivo',
                    'children' => [],
                ],
                [
                    'name' => 'Quién es Quién',
                    'children' => [],
                ],
                [
                    'name' => 'Detector de mentiras',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'Opinión',
            'children' => [],
        ],
        [
            'name' => 'Silla Llena',
            'children' => [
                [
                    'name' => 'Conflicto armado', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Coronavirus', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Desarrollo rural', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Economía', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Educación', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Justicia transicional', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Medio ambiente', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Movimientos sociales', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Proceso con las Farc', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Posconflicto', // Hilos
                    'children' => [],
                ],
                [
                    'name' => 'Red Cachaca',
                    'children' => [],
                ],
                [
                    'name' => 'Red de Ciencia e Innovación',
                    'children' => [],
                ],
                [
                    'name' => 'Red de la Educación',
                    'children' => [],
                ],
                [
                    'name' => 'Red de la paz',
                    'children' => [],
                ],
                [
                    'name' => 'Red de las mujeres',
                    'children' => [],
                ],
                [
                    'name' => 'Red de Venezuela',
                    'children' => [],
                ],
                [
                    'name' => 'Red Etnica',
                    'children' => [],
                ],
                [
                    'name' => 'Red Rural',
                    'children' => [],
                ],
                [
                    'name' => 'Red Social',
                    'children' => [],
                ],
                [
                    'name' => 'Red Verde',
                    'children' => [],
                ],
                [
                    'name' => 'Red Líder',
                    'children' => [],
                ],
                [
                    'name' => 'Red Minera',
                    'children' => [],
                ],
                [
                    'name' => 'Red Caribe',
                    'children' => [],
                ],
                [
                    'name' => 'Red Santandereana',
                    'children' => [],
                ],
                [
                    'name' => 'Red Pacifico',
                    'children' => [],
                ],
                [
                    'name' => 'Red Sur',
                    'children' => [],
                ],
                [
                    'name' => 'Blogeconomia',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'Silla Académica',
            'children' => [
                [
                    'name' => 'Facultad de Ciencias Sociales de La Universidad de Los Andes',
                    'children' => [],
                ],
                [
                    'name' => 'Instituto de Estudios Urbanos de la Universidad Nacional de Colombia',
                    'children' => [],
                ],
                [
                    'name' => 'Observatorio para la Equidad de Las Mujeres ICESI-FWWB',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad de Los Andes',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad de Manizales',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad del Norte',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad del Rosario',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad Externado',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad Javeriana',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad Pontificia Bolivariana',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad públicas - Convenio Ford',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad públicas - Convenio Usaid',
                    'children' => [],
                ],
                [
                    'name' => 'Publicaciones',
                    'children' => [],
                ],
                [
                    'name' => 'Eventos',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'Silla Datos',
            'children' => [],
        ],
        [
            'name' => 'Silla Podcasts',
            'children' => []
        ],
        [
            'name' => 'Silla Podcasts',
            'children' => [
                [
                    'name' => 'Huevos revueltos con política',
                    'children' => [],
                ],
                [
                    'name' => 'On the Record',
                    'children' => [],
                ],
                [
                    'name' => 'Deja Vu',
                    'children' => [],
                ],
                [
                    'name' => 'El futuro del futuro',
                    'children' => [],
                ],
                [
                    'name' => 'El País de los Millenials',
                    'children' => [],
                ],
                [
                    'name' => 'Los Incómodos',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'Silla Cursos',
            'children' => [],
        ],
        [
            'name' => 'Silla Viajera',
            'children' => [],
        ],
        [
            'name' => 'Superamigos',
            'children' => [],
        ],
        [
            'name' => 'Temas',
            'children' => [
                [
                    'name' => 'Álvaro Uribe',
                    'children' => [],
                ],
                [
                    'name' => 'Conflicto Armado',
                    'children' => [],
                ],
                [
                    'name' => 'Congreso',
                    'children' => [],
                ],
                [
                    'name' => 'Coronavirus',
                    'children' => [],
                ],
                [
                    'name' => 'Corrupción',
                    'children' => [],
                ],
                [
                    'name' => 'Cundinamarca',
                    'children' => [],
                ],
                [
                    'name' => 'Desarrollo Rural',
                    'children' => [],
                ],
                [
                    'name' => 'Detector de mentiras',
                    'children' => [],
                ],
                [
                    'name' => 'Dónde está la Plata',
                    'children' => [],
                ],
                [
                    'name' => 'Drogas',
                    'children' => [],
                ],
                [
                    'name' => 'Economía',
                    'children' => [],
                ],
                [
                    'name' => 'Educación',
                    'children' => [],
                ],
                [
                    'name' => 'Eje Cafetero',
                    'children' => [],
                ],
                [
                    'name' => 'Elecciones',
                    'children' => [],
                ],
                [
                    'name' => 'Elecciones 2022',
                    'children' => [],
                ],
                [
                    'name' => 'Encuestas',
                    'children' => [],
                ],
                [
                    'name' => 'Fuerza pública',
                    'children' => [],
                ],
                [
                    'name' => 'Gobierno Duque',
                    'children' => [],
                ],
                [
                    'name' => 'Gobiernos anteriores',
                    'children' => [],
                ],
                [
                    'name' => 'Grandes casos judiciales',
                    'children' => [],
                ],
                [
                    'name' => 'Justicia',
                    'children' => [],
                ],
                [
                    'name' => 'Justicia transicional',
                    'children' => [],
                ],
                [
                    'name' => 'La Silla Vacía',
                    'children' => [],
                ],
                [
                    'name' => 'Medio Ambiente',
                    'children' => [],
                ],
                [
                    'name' => 'Medios',
                    'children' => [],
                ],
                [
                    'name' => 'Movimientos Sociales',
                    'children' => [],
                ],
                [
                    'name' => 'Mujeres',
                    'children' => [],
                ],
                [
                    'name' => 'Otras Regiones',
                    'children' => [],
                ],
                [
                    'name' => 'Otros personajes',
                    'children' => [],
                ],
                [
                    'name' => 'Otros temas',
                    'children' => [],
                ],
                [
                    'name' => 'Polarización',
                    'children' => [],
                ],
                [
                    'name' => 'Política menuda',
                    'children' => [],
                ],
                [
                    'name' => 'Posconflicto',
                    'children' => [],
                ],
                [
                    'name' => 'Proceso con el ELN',
                    'children' => [],
                ],
                [
                    'name' => 'Región Sur',
                    'children' => [],
                ],
                [
                    'name' => 'Sala de Redacción Ciudadana',
                    'children' => [],
                ],
                [
                    'name' => 'Salud',
                    'children' => [],
                ],
                [
                    'name' => 'Superpoderosos',
                    'children' => [],
                ],
                [
                    'name' => 'Venezuela',
                    'children' => [],
                ],
                [
                    'name' => 'Víctimas',
                    'children' => [],
                ],
            ]
        ]
    ];

    private $log_file_path = '';

    /**
     * LaSillaVaciaMigrator Instance.
     *
     * @var LaSillaVaciaMigrator
     */
    private static $instance;

    /**
     * Constructor.
     */
    public function __constructor()
    {
    }

    public static function get_instance()
    {
        $class = get_called_class();

        if (null === self::$instance) {
            self::$instance = new $class();
            self::$instance->log_file_path = date('YmdHis', time()) . 'LSV_import.log';
        }

        return self::$instance;
    }

    /**
     * {@inheritDoc}
     */
    public function register_commands()
    {
        WP_CLI::add_command(
            'newspack-content-migrator la-silla-vacia-establish-taxonomy',
            [ $this, 'establish_taxonomy' ],
            [
                'shortdesc' => 'Establishes the category tree and tags for this publisher',
                'synopsis'  => [],
            ]
        );
        
        WP_CLI::add_command(
            'newspack-content-migrator la-silla-vacia-migrate-authors',
            [ $this, 'migrate_authors' ],
            [
                'shortdesc' => 'Migrates authors.',
                'synopsis' => [
                    [
                        'type' => 'assoc',
                        'name' => 'import-json',
                        'description' => 'The file which contains LSV authors.',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
                        'type' => 'flag',
                        'name' => 'reset-db',
                        'description' => 'Resets the database for a fresh import.',
                        'optional' => true,
                        'repeating' => false,
                    ]
                ],
            ]
        );

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-vacia-migrate-articles',
            [ $this, 'migrate_articles' ],
            [
                'shortdesc' => 'Migrate articles',
                'synopsis' => [
                    [
                        'type' => 'assoc',
                        'name' => 'import-json',
                        'description' => 'The file which contains LSV articles.',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
                        'type' => 'flag',
                        'name' => 'reset-db',
                        'description' => 'Resets the database for a fresh import.',
                        'optional' => true,
                        'repeating' => false,
                    ]
                ]
            ]
        );
    }

    private function reset_db()
    {
        WP_CLI::runcommand(
            'db reset --yes --defaults',
            [
                'return'     => true,
                'parse'      => 'json',
                'launch'     => false,
                'exit_error' => true,
            ]
        );

        $output = shell_exec(
            'wp core install --url=http://localhost:10013 --title="La Silla Vacia" --admin_user=edc598 --admin_email=edc598@gmail.com'
        );
        echo $output;

        shell_exec( 'wp user update edc598 --user_pass=ilovenews' );

        shell_exec( 'wp plugin activate newspack-custom-content-migrator' );
    }
    
    /**
     * @void
     */
    public function establish_taxonomy()
    {
        $this->create_categories( $this->category_tree );
    }

    /**
     * @param array $categories
     * @param int $parent_id
     */
    public function create_categories( array $categories, int $parent_id = 0 )
    {
        foreach ($categories as $category) {
            $created_category_id = wp_create_category( $category['name'], $parent_id );

            if ( ! empty( $category['children'] ) ) {
                $this->create_categories( $category['children'], $created_category_id );
            }
        }
    }

    /**
     * Generator for Author JSON.
     *
     * @param string $file
     * @param string $json_path
     * @return Generator
     */
    private function json_generator( string $file, string $json_path = '' )
    {
        $file = file_get_contents( $file );
        $json = json_decode( $file, true );

        if ( ! empty( $json_path ) ) {
            $path = explode('.', $json_path);
            foreach ($path as $step) {
                $json = $json[$step];
            }
        }

        foreach ($json as $element) {
            yield $element;
        }
    }

    /**
     * Migrates the author data from LSV.
     *
     * @param $args
     * @param $assoc_args
     */
    public function migrate_authors( $args, $assoc_args )
    {
        if ( $assoc_args['reset-db'] ) {
            $this->reset_db();
        }

        foreach ( $this->json_generator( $assoc_args['import-json'], '_embedded.User' ) as $author ) {
            $author_data = [
                'user_login' => $author['Username'],
                'user_pass' => wp_generate_password(),
                'user_email' => $author['Email'],
                'user_registered' => $author['CreatedOn'] ?? $author['UpdatedOn'] ?? date('Y-m-d H:i:s', time() ),
                'first_name' => $author['FirstName'],
                'last_name' => $author['LastName'],
                'display_name' => $author['FirstName'] . ' ' . $author['LastName'],
                'role' => 'author',
                'meta_input' => [
                    'compañia' => $author['CompanyName'],
                    'original_user_id' => $author['Id'],
                ]
            ];

            $user_id = wp_insert_user( $author_data );
        }
    }

    /**
     * @throws Exception
     */
    public function migrate_articles( $args, $assoc_args )
    {
        if ( $assoc_args['reset-db'] ) {
            $this->reset_db();
        }

        global $wpdb;
        $authors_sql = "SELECT um.meta_value, u.ID, um.meta_key
            FROM wp_users u LEFT JOIN wp_usermeta um ON um.user_id = u.ID
            WHERE um.meta_key = 'original_user_id'";
        $authors = $wpdb->get_results( $authors_sql, OBJECT_K );
        $authors = array_map( fn( $value ) => (int) $value->ID, $authors );

        $imported_hashed_ids_sql = "SELECT meta_value, meta_key
            FROM wp_postmeta
            WHERE meta_key IN ('hashed_import_id')";
        $imported_hashed_ids = $wpdb->get_results( $imported_hashed_ids_sql, OBJECT_K );
        $imported_hashed_ids = array_map( fn( $value ) => null, $imported_hashed_ids );

        foreach ( $this->json_generator( $assoc_args['import-json'], '_embedded.Article') as $article ) {

            // Using hash instead of just using original Id in case Id is 0. This would make it seem like the article is a duplicate.
            $original_article_id = $article['Id'] ?? 0;
            $original_article_title = $article['Title'] ?? '';
            $original_article_slug = $article['Slug'] ?? '';
            $hashed_import_id = md5( $original_article_id . $original_article_title . $original_article_slug );

            $this->file_logger("Original Article ID: $original_article_id | Original Article Title: $original_article_title | Original Article Slug: $original_article_slug" );

            if ( array_key_exists( $hashed_import_id, $imported_hashed_ids ) ) {
                $this->file_logger("Possible duplicate article, skipping." );
                continue;
            }

            $datetime_format = 'Y-m-d H:i:s';
            $createdOnDT = new DateTime( $article['CreatedOn'], new DateTimeZone( 'America/Bogota' ) );
            $createdOn = $createdOnDT->format( $datetime_format );
            $createdOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
            $createdOnGmt = $createdOnDT->format( $datetime_format );

            $modifiedOnDT = new DateTime( $article['LastModified'], new DateTimeZone( 'America/Bogota' ) );
            $modifiedOn = $modifiedOnDT->format( $datetime_format );
            $modifiedOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
            $modifiedOnGmt = $modifiedOnDT->format( $datetime_format );

            $html = '';
            if (!empty($article['Html'])) {

                $dom = new DOMDocument();
                $dom->encoding = 'utf-8';
                @$dom->loadHTML( utf8_decode( htmlentities(  $article['Html'] ) ) );
                $xpath = new DOMXPath($dom);
                /* @var DOMNodeList $nodes */
                $nodes = $xpath->query('//@*');

                foreach ($nodes as $node) {
                    /* @var DOMNode $node */
                    if ('href' === $node->nodeName) {
                        continue;
                    }

                    $node->parentNode->removeAttribute( $node->nodeName );
                }

                $html = html_entity_decode( $dom->saveHTML( $dom->documentElement ) );
            }

            $article_data = [
                'post_author' => $authors[ $article['CreatedBy'] ] ?? 0,
                'post_date' => $createdOn,
                'post_date_gmt' => $createdOnGmt,
                'post_content' => $html,
                'post_title' => $article['Title'],
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => $article['Slug'],
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $modifiedOn,
                'post_modified_gmt' => $modifiedOnGmt,
                'post_content_filtered' => '',
                'post_parent' => 0,
                'menu_order' => 0,
                'post_type' => 'post',
                'post_mime_type' => '',
                'comment_count' => 0,
                'meta_input' => [
                    'original_article_id' => $article['Id'],
                    'canonical_url' => $article['CanonicalUrl'],
                    'hashed_import_id' => $hashed_import_id,
                ]
            ];

            $this->file_logger( json_encode( $article_data ), false );

            $post_id = wp_insert_post( $article_data );

            wp_update_post(
                [
                    'ID' => $post_id,
                    'guid' => "http://lasillavacia-staging.newspackstaging.com/?page_id={$post_id}"
                ]
            );

            $this->file_logger( "Article Imported: $post_id" );
        }
    }

    /**
     * Convenience function to handle low level task of getting the file from path and inserting attachment.
     *
     * @param string $filename
     * @return int
     */
    private function handle_profile_photo(string $filename): int
    {
        // TODO find $filename with path

        return wp_insert_attachment(
            [
                'comment_status' => 'closed',
            ],
            $filename
        );
    }

    /**
     * @param string $message
     * @param bool $output
     */
    private function file_logger(string $message, bool $output = true)
    {
        file_put_contents( $this->log_file_path, "$message\n", FILE_APPEND );

        if ($output) {
            WP_CLI::log( $message );
        }
    }
}

