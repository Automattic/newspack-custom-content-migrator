<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Exception;
use Generator;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use \WP_CLI;

class LaSillaVaciaMigrator implements InterfaceCommand
{

    private $category_tree = [
        [
            'name' => 'La Silla Nacional',
            'children' => [
                [
                    'name' => 'Caribe',
                    'children' => [],
                ],
                [
                    'name' => 'Bogotá',
                    'children' => [],
                ],
                [
                    'name' => 'Pacífico',
                    'children' => [],
                ],
                [
                    'name' => 'Antioquia',
                    'children' => [],
                ],
                [
                    'name' => 'Santanderes',
                    'children' => [],
                ],
                [
                    'name' => 'Región Sur',
                    'children' => [],
                ],
                [
                    'name' => 'Eje Cafetero',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'En Vivo',
            'children' => [],
        ],
        [
            'name' => 'Red de Expertos',
            'children' => [
                [
                    'name' => 'Red Rural',
                    'children' => [],
                ],
                [
                    'name' => 'Red de la Paz',
                    'children' => [],
                ],
                [
                    'name' => 'Red de las Mujeres',
                    'children' => [],
                ],
                [
                    'name' => 'Red Cachaca',
                    'children' => [],
                ],
                [
                    'name' => 'Red de la Educación',
                    'children' => [],
                ],
                [
                    'name' => 'Red de Ciencia e Innovación',
                    'children' => [],
                ],
                [
                    'name' => 'Red Social',
                    'children' => [],
                ],
                [
                    'name' => 'Red Étnica',
                    'children' => [],
                ],
                [
                    'name' => 'Red Verde',
                    'children' => [],
                ],
                [
                    'name' => 'Red de Venezuela',
                    'children' => [],
                ],
                [
                    'name' => 'Red Paisa',
                    'children' => [],
                ],
                [
                    'name' => 'Red Sur',
                    'children' => [],
                ],
                [
                    'name' => 'Blogeconomía',
                    'children' => [],
                ],
                [
                    'name' => 'Red Pacífico',
                    'children' => [],
                ],
                [
                    'name' => 'Red Santandereana',
                    'children' => [],
                ],
                [
                    'name' => 'Red Caribe',
                    'children' => [],
                ],
                [
                    'name' => 'Red Minera',
                    'children' => [],
                ],
                [
                    'name' => 'Red Líder',
                    'children' => [],
                ]
            ]
        ],
        [
            'name' => 'Opinión',
            'children' => [
                [
                    'name' => 'El Computador de Palacio',
                    'children' => [],
                ],
                [
                    'name' => 'Latonería y pintura',
                    'children' => [],
                ],
                [
                    'name' => 'El poder de las Cifras',
                    'children' => [],
                ],
                [
                    'name' => 'Del director editorial',
                    'children' => [],
                ],
                [
                    'name' => 'Desde el jardín',
                    'children' => [],
                ],
                [
                    'name' => 'Mi plebi-SI-TIO',
                    'children' => [],
                ],
                [
                    'name' => 'Dimensión desconocida',
                    'children' => [],
                ],
                [
                    'name' => 'Ojo al dato',
                    'children' => [],
                ],
                [
                    'name' => 'De la dirección',
                    'children' => [],
                ],
                [
                    'name' => 'Suarezterapia',
                    'children' => [],
                ],
                [
                    'name' => 'Desde los santanderes',
                    'children' => [],
                ],
                [
                    'name' => 'Ya está pintón',
                    'children' => [],
                ],
                [
                    'name' => 'Desde mi mecedora',
                    'children' => [],
                ],
                [
                    'name' => 'La pecera',
                    'children' => [],
                ],
                [
                    'name' => 'Piedra de Toque',
                    'children' => [],
                ],
                [
                    'name' => 'Otra Mirada',
                    'children' => [],
                ],
                [
                    'name' => 'Colombia Civil',
                    'children' => [],
                ],
                [
                    'name' => 'Ruido blanco',
                    'children' => [],
                ],
                [
                    'name' => 'Bemoles',
                    'children' => [],
                ],
                [
                    'name' => 'El picó',
                    'children' => [],
                ],
                [
                    'name' => 'La mesa de centro',
                    'children' => [],
                ],
                [
                    'name' => 'Hector Riveros',
                    'children' => [],
                ],
                [
                    'name' => 'Disculpe, se cayó el sistema',
                    'children' => [],
                ],
                [
                    'name' => 'Caleidoscopio',
                    'children' => [],
                ],
            ],
        ],
        [
            'name' => 'Silla Datos',
            'children' => [
                [
                    'name' => 'Contratación',
                    'children' => [],
                ],
                [
                    'name' => 'Caso Uribe',
                    'children' => [],
                ],
                [
                    'name' => 'Poder de las empresas',
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
                    'name' => 'Poder nacional',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'Detector de mentiras',
            'children' => [
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
                    'name' => 'Engañoso',
                    'children' => [],
                ],
                [
                    'name' => 'Falso',
                    'children' => [],
                ],
            ]
        ],
        [
            'name' => 'Silla Cursos',
            'children' => [
                [
                    'name' => 'en línea',
                    'children' => [
                        [
                            'name' => 'Periodismo digital',
                            'children' => [],
                        ],
                        [
                            'name' => 'Liderazgo femenino',
                            'children' => [],
                        ],
                        [
                            'name' => 'Create digital products',
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'name' => 'presenciales',
                    'children' => [
                        [
                            'name' => 'Inmersión 2023',
                            'children' => [],
                        ],
                        [
                            'name' => 'Inmersión 2022',
                            'children' => [],
                        ],
                        [
                            'name' => 'Inmersión 2021',
                            'children' => [],
                        ],
                        [
                            'name' => 'Curso de vacaciones',
                            'children' => [],
                        ],
                        [
                            'name' => 'Contraseña',
                            'children' => [],
                        ],
                    ]
                ]
            ]
        ],
        [
            'name' => 'Podcasts',
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
            'name' => 'Silla Académica',
            'children' => [
                [
                    'name' => 'Universidad Javeriana',
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
                    'name' => 'Universidad Pontificia Bolivariana',
                    'children' => [],
                ],
                [
                    'name' => 'Instituto de Estudios Urbanos de la Universidad Nacional de Colombia',
                    'children' => [],
                ],
                [
                    'name' => 'Universidades públicas - Convenio Ford',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad públicas - Convenio Usaid',
                    'children' => [],
                ],
                [
                    'name' => 'Universidad Externado',
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
                    'name' => 'Observatorio para la Equidad de Las Mujeres ICESI-FWWB',
                    'children' => [],
                ],
                [
                    'name' => 'Facultad de Ciencias Sociales de La Universidad de Los Andes',
                    'children' => [],
                ],
                [
                    'name' => 'Publicaciones',
                    'children' => [
                        [
                            'name' => 'Papers',
                            'children' => [],
                        ],
                    ],
                ],
                [
                    'name' => 'Eventos',
                    'children' => [
                        [
                            'name' => 'Libros',
                            'children' => [],
                        ],
                        [
                            'name' => 'Publicaciones seriadas',
                            'children' => [],
                        ],
                        [
                            'name' => 'Estudios patrocinados',
                            'children' => [],
                        ]
                    ],
                ],
            ]
        ],
        [
            'name' => 'Quién es quién',
            'children' => [],
        ],
        [
            'name' => 'Especiales',
            'children' => [],
        ],
    ];

    private $tags = [
        'Drogas',
        'Posconflicto',
        'Superpoderosos',
        'Plebiscito',
        'Renegociación',
        'Alejandro Ordoñez',
        'Álvaro Uribe',
        'Camelladores',
        'Ciudadanos de a pie',
        'Conflicto Armado',
        'Congreso',
        'Coronavirus',
        'Corrupción',
        'Desarrollo Rural',
        'Detector al chat de la familia',
        'Detector en Facebook',
        'Dónde está la Plata',
        'Economía',
        'Educación',
        'El factor Vargas Lleras',
        'Elecciones',
        'Elecciones 2019',
        'Encuestas',
        'Étnico',
        'Fuerza pública',
        'Gobierno de Claudia López',
        'Gobierno de Peñalosa',
        'Gobierno de Santos',
        'Gobierno de Uribe',
        'Gobierno Duque',
        'Gobiernos anteriores',
        'Grandes casos judiciales',
        'Gustavo Petro',
        'Justicia',
        'Justicia transicional',
        'La elección del fiscal',
        'La Silla Vacía',
        'Las ías',
        'Las vacas flacas',
        'Medio Ambiente',
        'Medios',
        'Minería',
        'Movimientos Sociales',
        'Mujeres',
        'Odebrecht',
        'Otras Regiones',
        'Otros países',
        'Otros personajes',
        'Otros temas',
        'Polarización',
        'Política menuda',
        'Presidenciales 2018',
        'Proceso con el ELN',
        'Proceso con las FARC',
        'Salud',
        'Seguridad',
        'Testigos falsos y Uribe',
        'Urbanismo',
        'Venezuela',
        'Víctimas',
        'Conversaciones',
        'Cubrimiento Especial',
        'Hágame el cruce',
        'Coronavirus',
        'Proceso de paz',
        'Jep',
        'Arte',
        'Posconflicto',
        'Elecciones 2023',
        'Sala de Redacción Ciudadana',
        'Gobierno',
        'Crisis',
        'Elecciones 2022',
        'La Dimensión Desconocida',
        'Econimia',
        'Entrevista',
        'Redes Silla llena',
        'Papers',
        'Libros',
        'Publicaciones seriadas',
        'Estudios patrocinados',
        'Política',
        'Medio Ambiente',
        'Género',
        'Religión',
        'Corrupción',
        'Cultura',
        'Educación',
        'Economía',
        'Migraciones',
        'Relaciones Internacionales',
        'Ciencia',
        'Política social',
        'Elecciones',
        'Posconflicto',
        'Acuerdo de Paz',
        'Seguridad',
        'Desarrollo rural',
        'Salud',
        'Coronavirus',
        'Congreso',
        'Gobierno',
        'Justicia',
        'Movimientos sociales',
        'Sector privado',
        'Medios',
        'Tecnología e innovación',
        'Ciudades',
        'Comunidades étnicas',
    ];

    private $log_file_path = '';

    /**
     * LaSillaVaciaMigrator Instance.
     *
     * @var LaSillaVaciaMigrator
     */
    private static $instance;

    /**
     * @var CoAuthorPlus $coauthorsplus_logic
     */
    private $coauthorsplus_logic;

    /**
     * @var SimpleLocalAvatars $simple_local_avatars
     */
    private $simple_local_avatars;

    /**
     * @var Redirection $redirection
     */
    private $redirection;

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
            self::$instance->coauthorsplus_logic = new CoAuthorPlus();
            self::$instance->simple_local_avatars = new SimpleLocalAvatars();
            self::$instance->redirection = new Redirection();
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
            'newspack-content-migrator la-silla-vacia-migrate-expertos-as-guest-authors',
            [ $this, 'migrate_expertos_as_guest_authors'],
            [
                'shortdesc' => 'Migrates expertos as guest authors.',
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
            'newspack-content-migrator la-silla-vacia-migrate-users',
            [ $this, 'migrate_users' ],
            [
                'shortdesc' => 'Migrates users.',
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
                ]
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

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-vacia-migrate-redirects',
            [ $this, 'migrate_redirects' ],
            [
                'shortdesc' => 'Migrate redirects',
                'synopsis' => [
                    [
                        'type' => 'assoc',
                        'name' => 'import-json',
                        'description' => 'The file which contains LSV redirects',
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

        foreach ( $this->tags as $tag ) {
            wp_create_tag( $tag );
        }
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

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $author ) {
            $role = $author['xpr_rol'] ?? $author['role'] ?? 'antiguos usuarios';

            $this->file_logger( "Attempting to create User. email: {$author['user_email']} | login: {$author['user_login']} | role: $role" );
            $author_data = [
                'user_login' => $author['user_login'],
                'user_pass' => wp_generate_password( 24 ),
                'user_email' => $author['user_email'],
                'user_registered' => $author['user_registered'],
                'first_name' => $author['user_name'] ?? '',
                'last_name' => $author['user_lastname'] ?? '',
                'display_name' => $author['display_name'],
                'meta_input' => [
                    'original_user_id' => $author['id'],
                    'original_role_id' => $author['xpr_role_id'],
                    'red' => $author['red'],
                    'description' => $author['bio'],
                    'xpr_usuario_de_twitter' => $author['xpr_UsuariodeTwitter'],
                    'usuario_de_twitter' => $author['UsuariodeTwitter'],
                    'ocupacion' => $author['xpr_ocupacion'],
                    'genero' => $author['xpr_genero'] ?? '',
                    'facebook_url' => $author['FacebookURL'],
                    'linkedin_url' => $author['LinkedInURL'],
                    'instagram_url' => $author['InstagramURL'],
                    'whatsapp' => $author['whatsApp'],
                ]
            ];

            switch ( $role ) {
                case 'author':
                case 'editor':
                    $author_data['role'] = $author['xpr_rol'];
                    break;
                case 'admin':
                    $author_data['role'] = 'administrator';
                    break;
                case 'antiguos usuarios':
                case 'Silla Academica':
                case 'SillaAcademica':
                case 'Silla Llena':
                case 'SillaLlena':
                    $this->file_logger( "Creating Guest Author." );
                    //CAP
                    $guest_author_data = [
                        'user_login' => $author['user_login'],
                        'user_email' => $author['user_email'],
                        'first_name' => $author['user_name'] ?? '',
                        'last_name' => $author['user_lastname'] ?? '',
                        'display_name' => $author['display_name'],
                        'description' => strip_tags( $author['bio'] ),
                        // TODO handle avatar for guest author

                        /*if ( is_array( $author['image'] ) ) {
                            $author['image'] = $author['image'][0];
                        }*/
                        // 'avatar' => $this->handle_profile_photo( $author['image'] );
                    ];

                    $this->file_logger( json_encode( $guest_author_data ), false );

                    $post_id = $this->coauthorsplus_logic->create_guest_author( $guest_author_data );

                    update_post_meta( $post_id, 'original_user_id', $author['id'] );
                    update_post_meta( $post_id, 'original_role_id', $author['xpr_role_id'] );
                    update_post_meta( $post_id, 'red', $author['red'] );
                    update_post_meta( $post_id, 'description', $author['bio'] );
                    update_post_meta( $post_id, 'xpr_usuario_de_twitter', $author['xpr_UsuariodeTwitter'] );
                    update_post_meta( $post_id, 'usuario_de_twitter', $author['UsuariodeTwitter'] );
                    update_post_meta( $post_id, 'ocupacion', $author['xpr_ocupacion'] );
                    update_post_meta( $post_id, 'genero', $author['xpr_genero'] );
                    update_post_meta( $post_id, 'facebook_url', $author['FacebookURL'] );
                    update_post_meta( $post_id, 'linkedin_url', $author['LinkedInURL'] );
                    update_post_meta( $post_id, 'instagram_url', $author['InstagramURL'] );
                    update_post_meta( $post_id, 'whatsapp', $author['whatsApp'] );
                    continue 2;
            }

            $this->file_logger( json_encode( $author_data ), false );
            $user_id = wp_insert_user( $author_data );
            if ( is_wp_error( $user_id ) ) {
                $this->file_logger( $user_id->get_error_message() );
                continue;
            }

            $this->file_logger( "User created. ID: $user_id" );

            if ( is_array( $author['image'] ) ) {
                $author['image'] = $author['image'][0];
            }

            if ( ! empty( $author['image'] ) ) {
                $this->file_logger( "Creating User's avatar. File: {$author['image']}" );
                $file_path_parts = explode( '/', $author['image'] );
                $filename = array_pop( $file_path_parts );
                $avatar_attachment_id = $this->handle_profile_photo( $filename );

                $this->simple_local_avatars->import_avatar( $user_id, $avatar_attachment_id );
            }
        }
    }

    /**
     * Imports Guest Authors from JSON file.
     *
     * @param $args
     * @param $assoc_args
     */
    public function migrate_expertos_as_guest_authors( $args, $assoc_args )
    {
        if ( $assoc_args['reset-db'] ) {
            $this->reset_db();
        }

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $user ) {
            $this->file_logger( "ID: {$user['id']} | FULLNAME: {$user['fullname']}" );

            // There will always be a slug, but not always an email.
            $guest_author_exists = $this->coauthorsplus_logic->coauthors_guest_authors->get_guest_author_by( 'user_login', $user['slug'] );

            // Email is preferred for finding guest authors.
            if  ( ! empty( $user['email'] ) ) {
                $guest_author_exists = $this->coauthorsplus_logic->coauthors_guest_authors->get_guest_author_by( 'user_email', $user['email'] );
            }

            $names = explode(' ', $user['fullname']);
            $last_name = array_pop($names);
            $first_name = implode(' ', $names);

            $guest_author_data = [
                'display_name' => $user['fullname'],
                'user_login' => $user['slug'],
                'first_name' => $first_name,
                'last_name' => $last_name,
                'user_email' => $user['email'],
                'website' => $user['url'],
            ];

            $description = '';

            if ( ! empty( $user['description'] ) ) {
                $description .= $user['description'];
            }

            if ( ! empty( $user['lineasInvestigacion'] ) ) {
                $description .= "\n<strong>Líneas de Investigación: </strong>" . $user['lineasInvestigacion'];
            }

            if ( ! empty ( $user['categories'] ) ) {
                $description .= "\n\n<strong>Universidad: </strong>" . $user['categories'][0]['name'];
            }

            $guest_author_data['description'] = $description;

            if ( ! empty( $user['picture'] ) ) {
                $file_path_parts = explode( '/', $user['picture'] );
                $filename = array_pop( $file_path_parts );
                $guest_author_data['avatar'] = $this->handle_profile_photo( $filename );
            }

            if ( false === $guest_author_exists ) {
                $guest_author_id = $this->coauthorsplus_logic->create_guest_author( $guest_author_data );

                update_post_meta( $guest_author_id, 'original_user_id', $user['id'] );
                update_post_meta( $guest_author_id, 'publicaciones', $user['publicaciones'] );
                update_post_meta( $guest_author_id, 'lineasInvestigacion', $user['lineasInvestigacion'] );
                update_post_meta( $guest_author_id, 'cap-newspack_job_title', $user['lineasInvestigacion'] );
                update_post_meta( $guest_author_id, 'cap-newspack_phone_number', $user['phone'] );
                update_post_meta( $guest_author_id, 'cap-website', $user['url'] );
            } else {
                $this->file_logger( "Guest Author already exists. ID: {$guest_author_exists->ID}, will attempt to update information." );
                update_post_meta( $guest_author_exists->ID, 'cap-display_name', $guest_author_data['display_name'] );
                update_post_meta( $guest_author_exists->ID, 'cap-first_name', $guest_author_data['first_name'] );
                update_post_meta( $guest_author_exists->ID, 'cap-last_name', $guest_author_data['last_name'] );
                update_post_meta( $guest_author_exists->ID, 'cap-user_email', $guest_author_data['user_email'] );
                update_post_meta( $guest_author_exists->ID, 'cap-description', $guest_author_data['description'] );
                update_post_meta( $guest_author_exists->ID, 'cap-description', $guest_author_data['description'] );
                update_post_meta( $guest_author_exists->ID, '_thumbnail_id', $guest_author_data['avatar'] );
                update_post_meta( $guest_author_exists->ID, 'original_user_id', $user['id'] );
                update_post_meta( $guest_author_exists->ID, 'publicaciones', $user['publicaciones'] );
                update_post_meta( $guest_author_exists->ID, 'lineasInvestigacion', $user['lineasInvestigacion'] );
                update_post_meta( $guest_author_exists->ID, 'cap-newspack_job_title', $user['lineasInvestigacion'] );
                update_post_meta( $guest_author_exists->ID, 'cap-newspack_phone_number', $user['phone'] );
                update_post_meta( $guest_author_exists->ID, 'cap-website', $user['url'] );
            }
        }
    }

    /**
     * Migrates users from JSON file.
     *
     * @param $args
     * @param $assoc_args
     * @throws Exception
     */
    public function migrate_users($args, $assoc_args )
    {
        if ( $assoc_args['reset-db'] ) {
            $this->reset_db();
        }

        $unmigrated_users_file_path = 'unmigrated-users.json';
        $unmigrated_users = [];

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $user ) {
            $this->file_logger( "ID: {$user['id']} | EMAIL: {$user['email']} | NAME: {$user['name']}" );

            $is_valid_email = filter_var( $user['email'], FILTER_VALIDATE_EMAIL );

            if ( false === $is_valid_email ) {
                $this->file_logger( "Invalid email. Skipping." );
                $unmigrated_users[] = $user;
                continue;
            }

            $display_name = $user['name'] . ' ' . $user['lastname'];

            if ( is_null( $user['lastname'] ) ) {
                $display_name = $user['name'];
            }

            $created_at = new DateTime( $user['createdAt'], new DateTimeZone( 'America/Bogota' ) );
            $created_at->setTimezone( new DateTimeZone( 'UTC' ) );

            $user_id = wp_insert_user(
                [
                    'user_pass' => wp_generate_password( 24 ),
                    'user_login' => $user['email'],
                    'user_email' => $user['email'],
                    'display_name' => $display_name,
                    'first_name' => $user['name'],
                    'last_name' => $user['lastname'],
                    'description' => $user['job'],
                    'user_registered' => $created_at->format( 'Y-m-d H:i:s' ),
                    'role' => 'subscriber',
                    'meta_input' => [
                        'original_user_id' => $user['id'],
                    ]
                ]
            );

            if ( is_wp_error( $user_id ) ) {
                $this->file_logger( $user_id->get_error_message() );
                $user['error'] = $user_id->get_error_message();
                $unmigrated_users[] = $user;
            }
        }

        if ( ! empty( $unmigrated_users ) ) {
            $this->file_logger( "Writing unmigrated users to file." );
            file_put_contents( $unmigrated_users_file_path, json_encode( $unmigrated_users ) );
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

        $imported_hashed_ids_sql = "SELECT meta_value, post_id
            FROM wp_postmeta
            WHERE meta_key IN ('hashed_import_id')";
        $imported_hashed_ids = $wpdb->get_results( $imported_hashed_ids_sql, OBJECT_K );
        $imported_hashed_ids = array_map( fn( $value ) => (int) $value->post_id, $imported_hashed_ids );

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $article ) {

            // Using hash instead of just using original Id in case Id is 0. This would make it seem like the article is a duplicate.
            $original_article_id = $article['id'] ?? 0;
            $original_article_title = $article['post_title'] ?? '';
            $original_article_slug = $article['post_name'] ?? '';
            $hashed_import_id = md5( $original_article_title . $original_article_slug );

            $this->file_logger("Original Article ID: $original_article_id | Original Article Title: $original_article_title | Original Article Slug: $original_article_slug" );

            $datetime_format = 'Y-m-d H:i:s';
            $createdOnDT = new DateTime( $article['post_date'], new DateTimeZone( 'America/Bogota' ) );
            $createdOn = $createdOnDT->format( $datetime_format );
            $createdOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
            $createdOnGmt = $createdOnDT->format( $datetime_format );

            $modifiedOnDT = new DateTime( $article['post_date'], new DateTimeZone( 'America/Bogota' ) );
            $modifiedOn = $modifiedOnDT->format( $datetime_format );
            $modifiedOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
            $modifiedOnGmt = $modifiedOnDT->format( $datetime_format );

            $html = '';

            if ( ! empty( $article['html'] ) ) {
                $html = $this->handle_extracting_html_content( $article['html'] );
            } else {
                $html = $article['post_content'];
            }

            $article_data = [
                'post_author' => 0,
                'post_date' => $createdOn,
                'post_date_gmt' => $createdOnGmt,
                'post_content' => $html,
                'post_title' => $article['post_title'],
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => $article['post_name'],
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
                    'original_article_id' => $original_article_id,
//                    'canonical_url' => $article['CanonicalUrl'],
                    'hashed_import_id' => $hashed_import_id,
                ]
            ];

            if ( 1 === count( $article['post_author'] ) ) {
                $article_data['post_author'] = $authors[ $article['post_author'][0] ] ?? 0;
            }

            foreach ( $article['customfields'] as $customfield ) {
                $article_data['meta_input'][ $customfield['name'] ] = $customfield['value'];
            }

            $this->file_logger( json_encode( $article_data ), false );

            $new_post_id = $imported_hashed_ids[ $hashed_import_id ] ?? null;

            if ( ! is_null( $new_post_id ) ) {
                $this->file_logger( "Found existing post with ID: $new_post_id. Updating..." );
                // Setting the ID to the existing post ID will update the existing post.
                $article_data['ID'] = $new_post_id;
                // Delete the existing post's categories and guest authors.
                $wpdb->delete(
                    $wpdb->term_relationships,
                    [
                        'object_id' => $new_post_id,
                    ]
                );
            }

            $post_id = wp_insert_post( $article_data );

            if ( count( $article['post_author'] ) > 1 ) {
                global $wpdb;

                $guest_author_query = $wpdb->prepare(
                    "SELECT 
                        post_id 
                    FROM $wpdb->postmeta 
                    WHERE meta_key = 'original_user_id' 
                      AND meta_value IN (" . implode( ',', $article['post_author'] ) . ')'
                );
                $guest_author_ids = $wpdb->get_col( $guest_author_query );

                if ( ! empty( $guest_author_ids ) ) {
                    $term_taxonomy_ids_query = $wpdb->prepare(
                        "SELECT 
                            tt.term_taxonomy_id
                        FROM $wpdb->term_taxonomy tt 
                            INNER JOIN $wpdb->term_relationships tr 
                                ON tt.term_taxonomy_id = tr.term_taxonomy_id
                        WHERE tt.taxonomy = 'author' 
                          AND tr.object_id IN (" . implode( ',', $guest_author_ids ) . ')'
                    );
                    $term_taxonomy_ids = $wpdb->get_col( $term_taxonomy_ids_query );

                    foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
                        $wpdb->insert(
                            $wpdb->term_relationships,
                            [
                                'object_id'        => $post_id,
                                'term_taxonomy_id' => $term_taxonomy_id,
                            ]
                        );
                    }
                }
            }

            foreach ( $article['categories'] as $category ) {
                $term = get_term_by( 'name', $category['name'], 'category' );
                if ( $term ) {
                    wp_set_post_terms( $post_id, $term->term_id, 'category', true );
                }
            }

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
     * Migrator for LSV redirects.
     *
     * @param $args
     * @param $assoc_args
     */
    public function migrate_redirects( $args, $assoc_args )
    {
        if ( $assoc_args['reset-db'] ) {
            $this->reset_db();
        }

        global $wpdb;

        foreach ( $this->json_generator( $assoc_args['import-json']) as $redirect ) {
            $from_path = parse_url( $redirect['CustomUrl'], PHP_URL_PATH );
            $to_path = $redirect['Redirect'];
            $this->file_logger( "Original Redirect From: $from_path | Original Redirect To: $to_path" );

            $response_code = wp_remote_retrieve_response_code( wp_remote_get( $redirect['CustomUrl'] ) );

            if ( 200 != $response_code ) {
                $this->file_logger( "Unsuccessful request: ($response_code) {$redirect['CustomUrl']}" );
                continue;
            }

            // TODO Search in postmeta for $to_path
            $query = $wpdb->prepare(
                "SELECT 
                        p.ID, 
                        p.post_title, 
                        p.post_name
                    FROM $wpdb->posts p 
                        LEFT JOIN $wpdb->postmeta pm 
                            ON pm.post_id = p.ID 
                    WHERE pm.meta_key = 'original_article_path' 
                      AND pm.meta_value = '%s'",
                $to_path
            );
            $result = $wpdb->get_row( $query );

            if ( $result ) {
                $to_path = get_site_url(null, $result->post_name );
                $this->file_logger( "Creating redirect to $to_path" );
                $this->redirection->create_redirection_rule( '', get_site_url( null, $from_path ), $to_path );
            }
        }
    }

    /**
     * @param string $html
     * @return string
     */
    private function handle_extracting_html_content(string $html)
    {
        $dom = new DOMDocument();
        $dom->encoding = 'utf-8';
        @$dom->loadHTML( utf8_decode( htmlentities(  $html ) ) );
        $xpath = new DOMXPath($dom);
        /* @var DOMNodeList $nodes */
        $nodes = $xpath->query('//@*');

        foreach ($nodes as $node) {
            /* @var DOMElement $node */
            if ('href' === $node->nodeName) {
                continue;
            }

            $node->parentNode->removeAttribute( $node->nodeName );
        }

        return html_entity_decode( $dom->saveHTML( $dom->documentElement ) );
    }

    /**
     * Convenience function to handle low level task of getting the file from path and inserting attachment.
     *
     * @param string $filename
     * @return int
     */
    private function handle_profile_photo(string $filename): int
    {
        $base_dir = wp_upload_dir()['basedir'];

        $output = shell_exec( "find '$base_dir' -name '$filename'" );
        $files = explode( "\n", trim ( $output ) );

        if ( empty( $files ) ) {
            $attachment_id = $this->get_attachment_id( $filename );

            if ( ! is_null( $attachment_id ) ) {
                return $attachment_id;
            }
        }

        $file_path = $files[0];

        $attachment_id = $this->get_attachment_id( $filename );

        if ( ! is_null( $attachment_id ) ) {
            return $attachment_id;
        }

        return wp_insert_attachment(
            [
                'guid'  => wp_upload_dir()['url'] . '/' . $filename,
                'post_mime_type' => wp_get_image_mime( $file_path ),
                'post_title' => sanitize_file_name( $filename ),
                'post_content' => '',
                'post_status' => 'inherit',
                'comment_status' => 'closed',
            ],
            $file_path
        );
    }

    /**
     * Look in the DB to see if a record already exists for file. If so, return the attachment/post ID.
     *
     * @param string $filename
     * @return string|null
     */
    private function get_attachment_id( string $filename )
    {
        global $wpdb;

        $file_exists_query = $wpdb->prepare(
            "SELECT 
                post_id 
            FROM $wpdb->postmeta 
            WHERE meta_key = '_wp_attached_file' 
              AND meta_value LIKE '%%s%' 
              LIMIT 1",
            $filename
        );

        return $wpdb->get_var( $file_exists_query );
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

