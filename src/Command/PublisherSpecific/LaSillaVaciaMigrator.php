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
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\Taxonomy;
use NewspackCustomContentMigrator\Utils\Logger;
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
	 * Logger.
	 *
	 * @var Logger $logger
	 */
	private $logger;

	/**
	 * Attachments.
	 *
	 * @var Attachments $attachments
	 */
	private $attachments;

	/**
	 * Taxonomy logic.
	 *
	 * @var Taxonomy $taxonomy.
	 */
	private $taxonomy;

    /**
     * Singleton constructor.
     */
    private function __construct() {
        $this->log_file_path = date('YmdHis', time()) . 'LSV_import.log';
	    $this->coauthorsplus_logic = new CoAuthorPlus();
	    $this->simple_local_avatars = new SimpleLocalAvatars();
	    $this->redirection = new Redirection();
	    $this->logger = new Logger();
	    $this->attachments = new Attachments();
	    $this->taxonomy = new Taxonomy();
    }

	/**
	 * Singleton get instance.
	 *
	 * @return mixed|LaSillaVaciaMigrator
	 */
    public static function get_instance() {
        $class = get_called_class();
        if (null === self::$instance) {
            self::$instance = new $class();
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
		                'type' => 'assoc',
		                'name' => 'emails-csv',
		                'description' => 'Migrate just these emails, skip all other user records.',
		                'optional' => true,
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
            'newspack-content-migrator la-silla-vacia-update-all-author-avatars',
            [ $this, 'cmd_update_all_author_avatars' ],
            [
                'shortdesc' => 'Goes through all users JSON files, and if their avatars are not set, imports them from file expected to be found in media folder path.',
                'synopsis' => [
                    [
                        'description' => 'https://drive.google.com/file/d/1R5N1QYpcOsOT3gW6u6QCanJlR5cPrzhb/view?usp=drive_link',
                        'type' => 'assoc',
                        'name' => 'json-authors-silla-academica',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
	                    'description' => 'https://drive.google.com/file/d/1u59tq746o1Wg8p4Bbx5byqQDdwJOBV3u/view?usp=drive_link',
                        'type' => 'assoc',
                        'name' => 'json-authors-silla-llena',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
	                    'description' => 'https://drive.google.com/file/d/1ktu9ayl_sYAQbTCoXTgQHFLuJCqMLk7A/view?usp=drive_link',
                        'type' => 'assoc',
                        'name' => 'json-expertos',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
	                    'description' => 'https://drive.google.com/file/d/1UJLagdAVrFs02WdeCVJ8D8F32_o_2qi_/view?usp=drive_link',
                        'type' => 'assoc',
                        'name' => 'json-authors',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
                        'type' => 'assoc',
                        'name' => 'path-folder-with-images',
                        'optional' => false,
                        'repeating' => false,
                    ],
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
		                'type' => 'assoc',
		                'name' => 'fullnames-csv',
		                'description' => 'Migrate just these full names, skip all other user records.',
		                'optional' => true,
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
                        'type' => 'assoc',
                        'name' => 'category-name',
                        'description' => "Name of base category to where the JSON posts are being imported. See migrate_articles() for allowed values.",
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
            'newspack-content-migrator la-silla-ivans-helper',
            [ $this, 'cmd_ivan_helper_cmd' ],
            [
                'shortdesc' => "Ivan U's helper command with various dev snippets.",
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

		$specific_emails = isset( $assoc_args['emails-csv'] ) ? explode( ',', $assoc_args['emails-csv'] ) : null;

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $author ) {

			// If given, will migrate only authors with these emails.
			if ( ! is_null( $specific_emails ) && ! in_array( $author['user_email'], $specific_emails ) ) {
				continue;
			}

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
                    $this->file_logger( "Creating Guest Author {$author['user_email']}." );
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
					if ( is_wp_error( $post_id ) ) {
						$this->file_logger(
							sprintf( "Error Creating GA (user_login: '%s', user_email: '%s', first_name: '%s', user_lastname: '%s', display_name: '%s'), err: %s",
							$author['user_login'],
                            $author['user_email'],
                            $author['first_name'] ?? '',
                            $author['user_lastname'] ?? '',
                            $author['display_name'],
							$post_id->get_error_message() )
						);
						continue 2;
					}

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
					WP_CLI::success( "GA ID $post_id created." );
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
	 * Goes through all users JSON files, and if their avatars are not set, imports them from file expected to be found in media folder path.
	 *
	 * @param $args
	 * @param $assoc_args
	 *
	 * @return void
	 */
	public function cmd_update_all_author_avatars( $args, $assoc_args ) {
		// Path with images.
		$path = $assoc_args['path-folder-with-images'];
		if ( ! file_exists( $path ) ) {
			WP_CLI::error( "Folder $path does not exist." );
		}
		/**
		 * These JSONs use "image" key for avatar image:
		 *  - $json_academica
		 *  - $json_llena
		 *  - $json_authors
		 * This JSON uses "picture" key for avatar image:
		 *  - $json_expertos
		 */
		$json_files = [
			[
				'file'                     => $assoc_args['json-authors-silla-academica'],
				'json_key_used_for_avatar' => 'image',
			],
			[
				'file'                     => $assoc_args['json-authors-silla-llena'],
				'json_key_used_for_avatar' => 'image',
			],
			[
				'file'                     => $assoc_args['json-authors'],
				'json_key_used_for_avatar' => 'image',
			],
			[
				'file'                     => $assoc_args['json-expertos'],
				'json_key_used_for_avatar' => 'picture',
			],
		];
		foreach ( $json_files as $json_file ) {
			if ( ! file_exists( $json_file['file'] ) ) {
				WP_CLI::error( sprintf( "File %s does not exist.", $json_file['file'] ) );
			}
		}


		// Loop through all JSON files and import avatars if needed.
		foreach ( $json_files as $key_json_file => $json_file ) {

			$users = json_decode( file_get_contents( $json_file['file'] ), true );
			foreach ( $users as $key_user => $user ) {

				// Progress.
				WP_CLI::line( sprintf( "file %d/%d user %d/%d", $key_json_file + 1, count( $json_files ), $key_user + 1, count( $users ) ) );

				// Get avatar file name from user data.
				$avatar_filename = $this->get_avatar_image_from_user_json( $user, $json_file['json_key_used_for_avatar'] );
				if ( is_null( $avatar_filename ) ) {
					// No avatar, skip.
					continue;
				}

				// Get GA.
				$ga = null;
				// 1. get GA by email (can be two fields)
				$email = isset( $user['user_email'] ) ? $user['user_email'] : $user['email'];
				if ( $email ) {
					$ga = $this->coauthorsplus_logic->get_guest_author_by_email( $email );
					if ( ! $ga ) {
						/**
						 * Some emails in JSON contain trailing spaces, and when GAs were imported emails weren't trimmed,
						 * so now we have to work with both cases to make up for those.
						 */
						$email = trim( $email );
						$ga = $this->coauthorsplus_logic->get_guest_author_by_email( $email );
					}
				}
				// 2. get GA by full name
				if ( ! $ga ) {
					if ( isset( $user['fullname'] ) ) {
						$display_name = $user['fullname'];
					} else {
						// From LaSillaVaciaMigrator::migrate_users.
						$display_name = $user['name'] . ' ' . $user['lastname'];
					}
					$ga = $this->coauthorsplus_logic->get_guest_author_by_display_name( $display_name );
					if ( ! $ga ) {
						$display_name = trim( $display_name );
						$ga = $this->coauthorsplus_logic->get_guest_author_by_display_name( $display_name );
					}
				}

				// GA not found. Log and skip.
				if ( ! $ga ) {
					$this->logger->log( 'cmd_update_all_author_avatars__ERROR_GANOTFOUND.log', sprintf( "GA with email: '%s' display_name: '%s' not found, skipping.", $email, $display_name ), $this->logger::WARNING );
					continue;
				}
				// If multiple GAs returned, use the first one.
				$ga = is_array( $ga ) ? $ga[0] : $ga;

				// Update meta.
				update_post_meta( $ga->ID, 'avatar_filename', $avatar_filename );

				// Check if GA already has a valid avatar image so that we don't import dupe attachments.
				$existing_att_id = get_post_meta( $ga->ID, '_thumbnail_id', true );
				if ( ! empty( $existing_att_id ) ) {
					$existing_avatar_url = wp_get_attachment_url( $existing_att_id );

					// DEV -- if using local dev env, use HTTP because HTTPS is not available.
					if ( false !== strpos( $existing_avatar_url, '//lasilla.local/' ) ) {
						$existing_avatar_url = str_replace( 'https://', 'http://', $existing_avatar_url );
					}

					$response = wp_remote_get( $existing_avatar_url );
					if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
						// Avatar exists, skip.
						continue;
					}

					WP_CLI::warning( sprintf( "user_email: %s has faulty avatar, will reimport", $email ) );
				}

				// Import avatar from file.
				$image_file_path = $path . '/' . $avatar_filename;
				if ( ! file_exists( $image_file_path ) ) {
					$this->logger->log( 'cmd_update_all_author_avatars__ERROR_FILENOTFOUND.log', sprintf( "user_email: %s > json_file: %s > image_file_path: %s does not exist.", $email, $json_file['file'], $image_file_path ), $this->logger::WARNING );
					continue;
				}
				$att_id = $this->attachments->import_external_file( $image_file_path, $ga->ID );
				if ( is_wp_error( $att_id ) ) {
					$this->logger->log( 'cmd_update_all_author_avatars__ERROR_ATTACHMENTIMPORT.log', sprintf( "file:%s err:%s", $image_file_path, $att_id->get_error_message() ), $this->logger::WARNING );
					continue;
				}
				$this->coauthorsplus_logic->update_guest_author( $ga->ID, [ 'avatar' => $att_id ] );

				// Yey!
				$this->logger->log( 'cmd_update_all_author_avatars__UPDATED.log', sprintf( "ga_id: %d imported avatar att_ID: %s", $ga->ID, $att_id ), $this->logger::SUCCESS );
			}
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Gets avatar image filename from user JSON file data, which can use either "image" or "picture" keys for avatars.
	 *
	 * @param array  $data               User data.
	 * @param string $key_used_for_image String. Either "image" or "picture".
	 *
	 * @return ?string Image filename from user JSON.
	 */
	private function get_avatar_image_from_user_json( $data, $key_used_for_image ) {
		$image_file = null;

		if ( 'image' == $key_used_for_image ) {

			// Some validation.
			if ( ! isset( $data['image'][0] ) || is_null( $data['image'][0] ) ) {
				return null;
			}
			if ( count( $data['image'] ) > 1 ) {
				WP_CLI::warning( sprintf( "User %s has more than one image, using the first one.", $data['id'] ) );
			}
			$image_file = $data['image'][0];

		} elseif ( 'picture' == $key_used_for_image ) {

			// Some validation.
			if ( ! isset( $data['picture'] ) || is_null( $data['picture'] ) || empty( $data['picture'] ) ) {
				return null;
			}
			if ( ! is_string( $data['picture'] ) ) {
				WP_CLI::warning( sprintf( "Unexpected value for picture: ", json_encode( $data['picture'] ) ) );
			}

			$image_file = $data['picture'];

			// Remove "/media/" from the beginning of filename.
			if ( 0 === strpos( strtolower( $image_file ), '/media/' ) ) {
				$image_file = substr( $image_file, 7 );
			}

		} else {
			WP_CLI::error( sprintf( "Key $key_used_for_image not supported, user data: %s", json_encode( $data ) ) );
		}

		return $image_file;
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

	    $specific_fullnames = isset( $assoc_args['fullnames-csv'] ) ? explode( ',', $assoc_args['fullnames-csv'] ) : null;

	    foreach ( $this->json_generator( $assoc_args['import-json'] ) as $user ) {

		    // If given, will migrate only authors with these display names.
		    if ( ! is_null( $specific_fullnames ) && ! in_array( $user['fullname'], $specific_fullnames ) ) {
			    continue;
		    }

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

            $guest_author_data['description'] = $description;

            if ( ! $guest_author_exists ) {
	            $guest_author_id = $this->coauthorsplus_logic->create_guest_author( $guest_author_data );

	            if ( is_wp_error( $guest_author_id ) ) {
		            $this->file_logger(
			            sprintf( "Error Creating GA (user_login/slug: '%s', user_email: '%s', first_name: '%s', user_lastname: '%s', display_name: '%s'), err: %s",
				            $user['slug'],
				            $user['email'],
				            $first_name,
				            $last_name,
				            $user['fullname'],
				            $guest_author_id->get_error_message() )
		            );
		            continue;
	            }

                $this->file_logger( "Created GA ID {$guest_author_id}" );

				// Import a new media item only if new GA is created -- shouldn't reimport if GA already exists.
	            if ( ! empty( $user['picture'] ) ) {
	                $file_path_parts = explode( '/', $user['picture'] );
	                $filename = array_pop( $file_path_parts );
	                $guest_author_data['avatar'] = $this->handle_profile_photo( $filename );
	            }
            } else {
	            $guest_author_id = $guest_author_exists->ID;
                $this->file_logger( "Exists GA ID {$guest_author_exists->ID}" );
            }

			// GA fields.
            update_post_meta( $guest_author_id, 'cap-display_name', $guest_author_data['display_name'] );
            update_post_meta( $guest_author_id, 'cap-first_name', $guest_author_data['first_name'] );
            update_post_meta( $guest_author_id, 'cap-last_name', $guest_author_data['last_name'] );
            update_post_meta( $guest_author_id, 'cap-user_email', $guest_author_data['user_email'] );
            update_post_meta( $guest_author_id, 'cap-description', $guest_author_data['description'] );
            update_post_meta( $guest_author_id, 'cap-newspack_job_title', $user['lineasInvestigacion'] );
            update_post_meta( $guest_author_id, 'cap-newspack_phone_number', $user['phone'] );
            update_post_meta( $guest_author_id, 'cap-website', $user['url'] );
            update_post_meta( $guest_author_id, 'cap-newspack_role', $user['lineasInvestigacion'] );
            if ( ! empty ( $user['categories'] ) ) {
	            update_post_meta( $guest_author_id, 'cap-newspack_employer', $user['categories'][0]['name'] );
            }
			if ( isset( $guest_author_data['avatar'] ) && ! empty( $guest_author_data['avatar'] ) ) {
                update_post_meta( $guest_author_id, '_thumbnail_id', $guest_author_data['avatar'] );
			}

			// Extra postmeta.
            update_post_meta( $guest_author_id, 'original_user_id', $user['id'] );
            update_post_meta( $guest_author_id, 'publicaciones', $user['publicaciones'] );
            update_post_meta( $guest_author_id, 'lineasInvestigacion', $user['lineasInvestigacion'] );
	        if ( ! empty ( $user['categories'] ) ) {
		        update_post_meta( $guest_author_id, 'universidad', $user['categories'][0]['name'] );
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

    public function cmd_ivan_helper_cmd( $args, $assoc_args ) {
		global $wpdb;

	    /**
	     * Refactor categories.
	     */

	    $categories_that_should_be_migrated_as_tags = [
		    "Drogas" => 58,
		    "Posconflicto" => 59,
		    "Superpoderosos" => 60,
		    "Plebiscito" => 61,
		    "Renegociación" => 62,
		    "Alejandro Ordoñez" => 63,
		    "Álvaro Uribe" => 64,
		    "Camelladores" => 67,
		    "Ciudadanos de a pie" => 69,
		    "Conflicto Armado" => 70,
		    "Congreso" => 71,
		    "Coronavirus" => 72,
		    "Corrupción" => 73,
		    "Desarrollo Rural" => 75,
		    "Detector al chat de la familia" => 76,
		    "Detector en Facebook" => 78,
		    "Dónde está la Plata" => 79,
		    "Economía" => 80,
		    "Educación" => 81,
		    "El factor Vargas Lleras" => 83,
		    "Elecciones" => 84,
		    "Elecciones 2019" => 85,
		    "Encuestas" => 86,
		    "Étnico" => 87,
		    "Fuerza pública" => 88,
		    "Gobierno de Claudia López" => 89,
		    "Gobierno de Peñalosa" => 90,
		    "Gobierno de Santos" => 91,
		    "Gobierno de Uribe" => 92,
		    "Gobierno Duque" => 93,
		    "Gobiernos anteriores" => 94,
		    "Grandes casos judiciales" => 95,
		    "Gustavo Petro" => 96,
		    "Justicia" => 97,
		    "Justicia transicional" => 98,
		    "La elección del fiscal" => 99,
		    "La Silla Vacía" => 100,
		    "Las ías" => 101,
		    "Las vacas flacas" => 102,
		    "Medio Ambiente" => 103,
		    "Medios" => 104,
		    "Minería" => 105,
		    "Movimientos Sociales" => 106,
		    "Mujeres" => 107,
		    "Odebrecht" => 108,
		    "Otras Regiones" => 109,
		    "Otros países" => 110,
		    "Otros personajes" => 111,
		    "Otros temas" => 112,
		    "Polarización" => 114,
		    "Política menuda" => 115,
		    "Presidenciales 2018" => 116,
		    "Proceso con el ELN" => 117,
		    "Proceso con las FARC" => 118,
		    "Salud" => 120,
		    "Seguridad" => 122,
		    "Testigos falsos y Uribe" => 123,
		    "Urbanismo" => 124,
		    "Venezuela" => 125,
		    "Víctimas" => 126,
		    "Conversaciones" => 129,
		    "Cubrimiento Especial" => 130,
		    "Hágame el cruce" => 131,
		    "Coronavirus + 177	Coronavirus" => 172,
		    "Coronavirus + 177 Coronavirus" => 172,
		    "Proceso de paz" => 173,
		    "Jep" => 174,
		    "Arte" => 386,
		    "Posconflicto + 59 Posconflicto" => 389,
		    "Elecciones 2023" => 429,
		    "Sala de Redacción Ciudadana" => 378,
		    "Gobierno" => 176,
		    "Crisis" => 178,
		    "Elecciones 2022" => 360,
		    "La Dimensión Desconocida" => 388,
		    "Econimia" => 48,
		    "Entrevista" => 381,
		    "Redes Silla llena" => 175,
		    "Papers" => 326,
		    "Libros" => 327,
		    "Publicaciones seriadas" => 328,
		    "Estudios patrocinados" => 329,
		    "Política + 46	Politica" => 392,
		    "Política + 46 Politica" => 392,
		    "Medio Ambiente + 103 Medio ambiente" => 399,
		    "Género" => 400,
		    "Religión" => 401,
		    "Corrupción + 73 Corrupcion" => 402,
		    "Cultura + 47	Cultura" => 403,
		    "Cultura + 47 Cultura" => 403,
		    "Educación" => 404,
		    "Economía" => 405,
		    "Migraciones" => 406,
		    "Relaciones Internacionales" => 407,
		    "Ciencia" => 408,
		    "Política social" => 409,
		    "Elecciones" => 410,
		    "Posconflicto" => 411,
		    "Acuerdo de Paz" => 412,
		    "Seguridad" => 413,
		    "Desarrollo rural" => 414,
		    "Salud" => 415,
		    "Coronavirus" => 416,
		    "Congreso" => 417,
		    "Gobierno" => 418,
		    "Justicia" => 419,
		    "Movimientos sociales" => 420,
		    "Sector privado" => 421,
		    "Medios" => 422,
		    "Tecnología e innovación" => 423,
		    "Ciudades" => 424,
		    "Comunidades étnicas" => 425,
	    ];
	    $categories_that_should_not_be_migrated = [
		    "Store" => 17,
		    "Module" => 18,
		    "suscripciones pasadas" => 40,
		    "Beneficios" => 41,
		    "Items1" => 42,
		    "Items2" => 43,
		    "Destacado" => 49,
		    "Destacados silla vacia" => 369,
		    "Destacados silla llena" => 371,
		    "Destacado home" => 374,
		    "Destacado historia" => 375,
		    "Destacado Episodio Landing" => 376,
		    "Recomendados Episodio Landing" => 377,
		    "Entrevistado" => 382,
		    "Texto Citado" => 383,
		    "Fin de semana" => 384,
		    "Eventos Article" => 144,
		    "Polemico" => 145,
		    "Boletines" => 379,
		    "Mailing" => 380,
		    "Opinión" => 181,
		    "Entidades" => 143,
		    "Publicaciones" => 142,
		    "Relacion Quien es Quien" => 50,
		    "Rivalidad" => 51,
		    "Laboral" => 52,
		    "Quien es quien" => 44,
		    "tematicas" => 45,
		    "Temas" => 53,
		    "Escala Detector" => 127,
		    "Producto" => 128,
		    "Sí o no" => 133,
		    "Columnas de la silla" => 148,
		    "Podcast" => 146,
		    "Modulo Videos" => 147,
		    "Temas silla llena" => 171,
		    "Delitos" => 180,
		    "Temas Experto" => 201,
		    "Tipo de Publicación Patrocinada" => 325,
		    "Lecciones" => 362,
		    "Especiales" => 363,
		    "categoryFileds" => 426,
		    "SillaCursos" => 200,
		    "cursos asincronicos" => 373,
		    "Periodismo" => 364,
		    "cursos productos" => 356,
		    "Escritura" => 365,
		    "Diseño" => 366,
		    "Audiovisual" => 367,
		    "Curso de Desinformación" => 430,
	    ];

return;


	    /**
	     * Delete all posts in select categories, except those tagged as 'Memes de la semana'.
	     */

		// Get posts with 'Memes de la semana' tag.
	    $memes_de_la_semana_tag_name = 'Memes de la semana';
	    $memes_de_la_semana_tag_id = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM $wpdb->terms WHERE name = %s", $memes_de_la_semana_tag_name ) );
		if ( ! $memes_de_la_semana_tag_id ) {
			WP_CLI::error( "Tag '$memes_de_la_semana_tag_name' not found." );
		}
	    $memes_de_la_semana_post_ids = get_posts( [
		    'post_type' => 'post',
		    'posts_per_page' => -1,
		    'tag_id'=> $memes_de_la_semana_tag_id,
		    'fields' => 'ids'
	    ] );

		// Will delete posts from these categories.
	    $cats = [
		    [ 'term_id' => 4984, 'name' => 'Detector de mentiras' ],
		    [ 'term_id' => 4932, 'name' => 'En Vivo' ],
		    [ 'term_id' => 4952, 'name' => 'Opinión' ],
		    [ 'term_id' => 5001, 'name' => 'Podcasts' ],
		    [ 'term_id' => 5027, 'name' => 'Quién es quién' ],
		    [ 'term_id' => 5008, 'name' => 'Silla Académica' ],
		    [ 'term_id' => 4924, 'name' => 'Silla Nacional' ],
	    ];
	    foreach ( $cats as $cat ) {
		    $term = get_term_by( 'id', $cat['term_id'], 'category' );
		    if ( ! $term || ( $cat['name'] != $term->name )  ) {
			    WP_CLI::error( "Category {$cat['name']} not found." );
		    }

			// Get all children and subchildren category IDs. Two levels is enough for LSV structure.
		    $terms_children_ids = [];
		    $terms_childrens_children_ids = [];
		    $terms_children = get_categories( [ 'child_of' => $term->term_id, 'hide_empty' => 0, ] );
		    foreach ( $terms_children as $term_child ) {
			    // Child term_id.
				$terms_children_ids[] = $term_child->term_id;
			    $terms_childrens_children = get_categories( [ 'child_of' => $term_child->term_id, 'hide_empty' => 0, ] );
			    foreach ( $terms_childrens_children as $term_childs_child ) {
			        // Child's child term_id.
				    $terms_childrens_children_ids[] = $term_childs_child->term_id;
			    }
		    }

			// Get all posts in this cat.
		    $postslist = get_posts([ 'category' => $cat['term_id'], 'post_type' =>  'post', 'posts_per_page' => -1 ]);
		    WP_CLI::line( sprintf( "\n" . "Total %d posts in category '%s'", count( $postslist ), $cat['name'] ) );
		    foreach ($postslist as $post) {

			    // Check if post belongs to other cats.
			    $all_post_cats = wp_get_post_categories( $post->ID, [ 'hide_empty' => 0, ] );
				// Subtract this ID, children IDs, and children's children IDs.
			    $other_cats_ids = $all_post_cats;
			    $other_cats_ids = array_diff( $other_cats_ids, [ $cat['term_id'] ] );
			    $other_cats_ids = array_diff( $other_cats_ids, $terms_children_ids );
			    $other_cats_ids = array_diff( $other_cats_ids, $terms_childrens_children_ids );
			    $belongs_to_different_cats_too = false;
				if ( count($other_cats_ids) > 0 ) {
				    $belongs_to_different_cats_too = true;
				}

				// Skip deleting if $belongs_to_different_cats_too.
			    if ( true == $belongs_to_different_cats_too ) {
				    foreach ( $other_cats_ids as $other_cat_id ) {
					    $other_term = get_term_by( 'id', $other_cat_id, 'category' );
					    WP_CLI::warning( sprintf( "Post %d has other cat ID %d '%s'", $post->ID, $other_term->term_id, $other_term->name ) );
				    }
				    continue;
			    }

			    // Skip deleting if post has tag.
			    if ( in_array( $post->ID, $memes_de_la_semana_post_ids ) ) {
				    WP_CLI::warning( sprintf( "Post %d has 'Memes de la semana' tag", $post->ID ) );
				    continue;
			    }

			    // wp_delete_post( $post->ID, true );
			    WP_CLI::success( 'Deleted post ' . $post->ID );
		    }
	    }

	    WP_CLI::line( 'Done.' );
    }

    /**
     * @throws Exception
     */
    public function migrate_articles( $args, $assoc_args )
    {
		global $wpdb;

        if ( $assoc_args['reset-db'] ) {
            $this->reset_db();
        }

		// Top level category which posts in this JSON are for.
		$category_names = [
			'Opinión',
			'Podcasts',
			'Quién es quién',
			'Silla Académica',
			'Silla Nacional',
			'Detector de mentiras',
			'En Vivo',
			'Silla Llena',
			'Publicaciones',
		];
		if ( ! in_array( $assoc_args['category-name'], $category_names ) ) {
			WP_CLI::error( sprintf( "Category name '%s' not found.", $assoc_args['category-name'] ) );
		}
		// Get the main category term ID.
	    $top_category_term_id = $this->taxonomy->get_term_id_by_taxonmy_name_and_parent( 'category', $assoc_args['category-name'], 0 );
		if ( ! $top_category_term_id ) {
			WP_CLI::error( sprintf( "Parent category not found by name '%s'.", $assoc_args['category-name'] ) );
		}

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

		$total_count = count( json_decode( file_get_contents( $assoc_args['import-json'] ), true ) );
		$i = 0;
        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $article ) {
	        $i++;

			WP_CLI::log( sprintf( "Importing %d/%d", $i, $total_count ) );

	        // Using hash instead of just using original Id in case Id is 0. This would make it seem like the article is a duplicate.
	        $original_article_id = $article['id'] ?? 0;
	        $original_article_title = trim( $article['post_title'] ) ?? '';
	        $original_article_slug = $article['post_name'] ?? '';
	        $hashed_import_id = md5( $original_article_title . $original_article_slug );

	        $this->file_logger( "Original Article ID: $original_article_id | Original Article Title: $original_article_title | Original Article Slug: $original_article_slug" );

	        $datetime_format = 'Y-m-d H:i:s';
	        $createdOnDT     = new DateTime( $article['post_date'], new DateTimeZone( 'America/Bogota' ) );
	        $createdOn       = $createdOnDT->format( $datetime_format );
	        $createdOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
	        $createdOnGmt = $createdOnDT->format( $datetime_format );

	        $modifiedOnDT = new DateTime( $article['post_date'], new DateTimeZone( 'America/Bogota' ) );
	        $modifiedOn   = $modifiedOnDT->format( $datetime_format );
	        $modifiedOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
	        $modifiedOnGmt = $modifiedOnDT->format( $datetime_format );

	        $html = '';

	        // Get content.
	        if ( ! empty( $article['html'] ) ) {
				// handle_extracting_html_content() encapsulates post_content in <html> tag.
		        // $html = $this->handle_extracting_html_content( $article['html'] );

		        $html = $article['html'];
	        } elseif ( ! empty( $article['post_html'] ) ) {
		        $html = $article['post_html'];
            } elseif ( ! empty( $article['post_content'] ) ) {
                $html = $article['post_content'];
            }

	        // Check if post 'html' or 'post_content' exists in JSON.
	        if ( empty( $html ) ) {
		        $msg = sprintf( "ERROR: Article %d '%s' has no post_content", $original_article_id, $original_article_title );
		        WP_CLI::warning( $msg );
		        $this->file_logger( $msg );
		        continue;
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
                    'newspack_original_article_id' => $original_article_id,
//                    'canonical_url' => $article['CanonicalUrl'],
                    'newspack_hashed_import_id' => $hashed_import_id,
	                'newspack_original_article_categories' => $article['categories'],
	                'newspack_original_post_author' => $article['post_author'],
                ]
            ];

            if ( 1 === count( $article['post_author'] ) ) {
                $article_data['post_author'] = $authors[ $article['post_author'][0] ] ?? 0;
            }

			if ( isset( $article['customfields'] ) ) {
	            foreach ( $article['customfields'] as $customfield ) {
	                $article_data['meta_input'][ $customfield['name'] ] = $customfield['value'];
	            }
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
                $guest_author_query = $wpdb->prepare(
                    "SELECT 
                        post_id 
                    FROM $wpdb->postmeta 
                    WHERE meta_key = 'original_user_id' 
                      AND meta_value IN (" . implode( ',', $article['post_author'] ) . ')'
                );
                $guest_author_ids = $wpdb->get_col( $guest_author_query );

				$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id, false );
				/**
				 * This existing code doesn't work -- it's not finding the $term_taxonomy_ids.
				 * Plus we have a one-liner for this 👆.
				 */
                // if ( ! empty( $guest_author_ids ) ) {
                //     $term_taxonomy_ids_query = $wpdb->prepare(
                //         "SELECT
                //             tt.term_taxonomy_id
                //         FROM $wpdb->term_taxonomy tt
                //             INNER JOIN $wpdb->term_relationships tr
                //                 ON tt.term_taxonomy_id = tr.term_taxonomy_id
                //         WHERE tt.taxonomy = 'author'
                //           AND tr.object_id IN (" . implode( ',', $guest_author_ids ) . ')'
                //     );
                //     $term_taxonomy_ids = $wpdb->get_col( $term_taxonomy_ids_query );
				//
                //     foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
                //         $wpdb->insert(
                //             $wpdb->term_relationships,
                //             [
                //                 'object_id'        => $post_id,
                //                 'term_taxonomy_id' => $term_taxonomy_id,
                //             ]
                //         );
                //     }
                // }
            }

			// Set categories.
            foreach ( $article['categories'] as $category ) {
	            $category_name = $category['title'];
				$term_id = $this->taxonomy->get_or_create_category_by_name_and_parent_id( $category_name, $top_category_term_id );
                wp_set_post_terms( $post_id, $term_id, 'category', true );
            }
			// If no cats were assigned, at least assign the top level category.
			if ( ! isset( $article['categories'] ) || ! $article['categories'] ) {
                wp_set_post_terms( $post_id, $top_category_term_id, 'category', true );
			}

			// It's not recommended to modify the guid
            // wp_update_post(
            //     [
            //         'ID' => $post_id,
            //         'guid' => "http://lasillavacia-staging.newspackstaging.com/?page_id={$post_id}"
            //     ]
            // );

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

