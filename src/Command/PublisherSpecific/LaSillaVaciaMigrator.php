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
use NewspackCustomContentMigrator\Logic\Images;
use NewspackCustomContentMigrator\Logic\Taxonomy;
use NewspackCustomContentMigrator\Utils\JsonIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
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
	 * JSON Iterator.
	 *
	 * @var null|JsonIterator
	 */
	private $json_iterator;

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
	 * Images logic.
	 *
	 * @var Images $images Images logic.
	 */
	private $images;

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
	    $this->images = new Images();
		$this->json_iterator = new JsonIterator();
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
		                'type' => 'assoc',
		                'name' => 'start-at-id',
		                'description' => 'The original user ID to start at.',
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
		                'type' => 'flag',
		                'name' => 'incremental-import',
		                'description' => "If this flag is set, it will only import new posts and won't re-import data for existing ones.",
		                'optional' => true,
		                'repeating' => false,
	                ],
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
                    ],
                ]
            ]
        );

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-vacia-update-migrated-articles',
            [ $this, 'cmd_update_migrated_articles' ],
            [
                'shortdesc' => 'Update migrated articles',
                'synopsis'  => [
                    [
                        'type' => 'assoc',
                        'name' => 'import-json',
                        'description' => 'The file which contains LSV articles.',
                        'optional' => false,
                        'repeating' => false,
                    ],
	                [
		                'type' => 'assoc',
		                'name' => 'media-location',
		                'description' => 'Path to media directory',
		                'optional' => false,
		                'repeating' => false,
	                ],
	                [
		                'type' => 'assoc',
		                'name' => 'start-at-id',
		                'description' => 'Original article ID to start from',
		                'optional' => true,
		                'repeating' => false,
	                ],
	                [
		                'type' => 'assoc',
		                'name' => 'end-at-id',
		                'description' => 'Original article ID to end at',
		                'optional' => true,
		                'repeating' => false,
	                ],
                ]
            ]
        );

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-vacia-update-author-metadata',
            [ $this, 'cmd_update_user_metadata' ],
            [
                'shortdesc' => 'Update or insert missing author metadata',
                'synopsis'  => [
                    [
                        'type' => 'assoc',
                        'name' => 'import-json',
                        'description' => 'The file which contains LSV articles.',
                        'optional' => false,
                        'repeating' => false,
                    ]
                ]
            ]
        );

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-link-wp-user-to-guest-author',
			[ $this, 'link_wp_users_to_guest_authors' ],
			[
				'shortdesc' => 'Link WP users to guest authors',
				'synopsis'  => [],
			]
		);

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-devhelper-ivans-helper',
            [ $this, 'cmd_ivan_helper_cmd' ],
            [
                'shortdesc' => "Ivan U's helper command with various dev snippets.",
            ]
        );

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-devhelper-get-all-children-cats-of-a-cat',
            [ $this, 'cmd_helper_get_all_children_cats_of_a_cat' ],
            [
                'shortdesc' => "Ivan U's helper command which gets all children cats of a cat.",
            ]
        );

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-devhelper-delete-all-posts-in-select-categories',
            [ $this, 'cmd_helper_delete_all_posts_in_select_categories' ],
            [
                'shortdesc' => "Ivan U's helper command which gets all children cats of a cat.",
            ]
        );

        WP_CLI::add_command(
            'newspack-content-migrator la-silla-update-img-paths-in-category-or-posts',
            [ $this, 'cmd_update_img_paths_in_category_or_posts' ],
            [
                'shortdesc' => "Updates paths in <img> elements either in all posts in category, or in specific post IDs. Provide either category-term-id or post-ids-csv.",
                'synopsis' => [
                    [
                        'type' => 'assoc',
                        'name' => 'search',
                        'description' => 'Search string in <img>.',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
                        'type' => 'assoc',
                        'name' => 'replace',
                        'description' => 'Replace string in <img>.',
                        'optional' => false,
                        'repeating' => false,
                    ],
                    [
                        'type' => 'assoc',
                        'name' => 'category-term-id',
                        'description' => 'Category term_id in which all belonging posts will be updated.',
                        'optional' => true,
                        'repeating' => false,
                    ],
                    [
                        'type' => 'assoc',
                        'name' => 'category-term-id',
                        'description' => 'Category term_id in which all belonging posts will be updated.',
                        'optional' => true,
                        'repeating' => false,
                    ],
                    [
                        'type' => 'assoc',
                        'name' => 'post-ids-csv',
                        'description' => 'Post IDs in CSV format, which will be updated.',
                        'optional' => true,
                        'repeating' => false,
                    ],
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

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-podcasts',
			[ $this, 'update_podcasts' ],
			[
				'shortdesc' => 'Go over podcasts and update their data if necessary.',
				'synopsis' => [
					[
						'type' => 'assoc',
						'name' => 'import-json',
						'description' => 'The file that contains podcasts data.',
						'optional' => false,
						'repeating' => false,
					],
					[
						'type' => 'assoc',
						'name' => 'media-dir',
						'description' => 'The directory where the media folder is located',
						'optional' => false,
						'repeating' => false,
					],
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
		$start_at_id = $assoc_args['start-at-id'] ?? null;
		$skip = ! is_null( $start_at_id );

        $unmigrated_users_file_path = 'unmigrated-users.json';
        $unmigrated_users = [];

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $user ) {
			if ( $skip && $user['id'] < $start_at_id ) {
				continue;
			} else {
				$skip = false;
			}

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
	                    'original_system_role' => $user['user_group_name'] ?? '',
                    ],
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

    public function cmd_update_img_paths_in_category_or_posts( $args, $assoc_args ) {
		$search           = $assoc_args['search'];
		$replace          = $assoc_args['replace'];
	    $category_term_id = isset( $assoc_args['category-term-id'] ) ? $assoc_args['category-term-id'] : null;
		$post_ids         = isset( $assoc_args['post-ids-csv'] ) ? explode( ',', $assoc_args['post-ids-csv'] ) : null;
		if ( is_null( $category_term_id ) && is_null( $post_ids ) ) {
			WP_CLI::error( 'You must specify either a category term ID or a comma-separated list of post IDs.' );
		}

	    global $wpdb;

		// Get all post IDs from category.
		if ( ! is_null( $category_term_id ) ) {
		    $post_ids = $wpdb->get_col( $wpdb->prepare(
			    "select object_id from {$wpdb->term_relationships} tr
				join {$wpdb->term_taxonomy} tt on tt.term_taxonomy_id = tr.term_taxonomy_id
				join {$wpdb->terms} t on t.term_id = tt.term_id
				join {$wpdb->posts} p on p.ID = tr.object_id
				where t.term_id = %d
				and p.post_type = 'post';",
			    $category_term_id
		    ) );
		}

		WP_CLI::log( sprintf( "Updating <imgs> in %d posts... Replacing:", count( $post_ids ) ) );
		WP_CLI::log( sprintf( "- from %s", $search ) );
		WP_CLI::log( sprintf( "- to %s", $replace ) );

		$updated_post_ids = [];
	    foreach ( $post_ids as $post_id ) {
		    $updated = $this->images->str_replace_in_img_elements_in_post( $post_id, $search, $replace );
			if ( 0 != $updated ) {
				$updated_post_ids[] = $post_id;
			}
	    }

		if ( empty( $updated_post_ids ) ) {
			WP_CLI::warning( 'No posts updated.' );
			return;
		}

        $log = date( 'Y-m-d_H-i-s' ) . '__catid_' . $category_term_id . '_updatedpostids.log';
		file_put_contents( $log, implode( "\n", $updated_post_ids ) );

		WP_CLI::success( sprintf( 'Done. List of updated posts saved to %s.', $log ) );
		wp_cache_flush();
    }

    public function cmd_helper_get_all_children_cats_of_a_cat( $args, $assoc_args ) {

	    /**
	     * Get all children cats of a cat.
	     */
	    $term_id = 4984;
		$term = get_term( $term_id, 'category' );
	    $terms_children = get_categories( [ 'child_of' => $term_id, 'hide_empty' => 0, ] );
	    $terms_children_ids = [];
		foreach ( $terms_children as $term_child ) {
			$terms_children_ids[] = $term_child->term_id;
	    }
		WP_CLI::log( sprintf( "%d '%s' children:", $term_id, $term->name ) );
		WP_CLI::log( implode( ',', $terms_children_ids ) );
		WP_CLI::success( 'Done.' );

    }

    public function cmd_helper_delete_all_posts_in_select_categories( $args, $assoc_args ) {

		global $wpdb;

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

    public function cmd_ivan_helper_cmd( $args, $assoc_args ) {

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

    }

    /**
     * @throws Exception
     */
    public function migrate_articles( $args, $assoc_args )
    {
		global $wpdb;

		$incremental_import = isset( $assoc_args['incremental-import'] ) ? true : false;
        if ( isset( $assoc_args['reset-db'] ) ) {
            $this->reset_db();
        }

		$skip_base64_html_ids = [];

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

		// Count total articles, but don't do it for very large files because of memory consumption -- a rough count is good enough for just approx. progress.
	    if ( 'Silla Académica' == $assoc_args['category-name'] ) {
		    $total_count = '?';
	    } else {
			$total_count = count( json_decode( file_get_contents( $assoc_args['import-json'] ), true ) );
	    }
		$i = 0;
        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $article ) {
	        $i++;

			WP_CLI::log( sprintf( "Importing %d/%s", $i, $total_count ) );

	        /**
	         * Get post data from JSON.
	         */

	        if ( 'Detector de mentiras' == $assoc_args['category-name'] ) {
	            $original_article_id = $article['head_id'] ?? 0;
	        } else {
	            $original_article_id = $article['id'] ?? 0;
	        }

			/*// No longer want this function to handle articles if they've already been imported.
	        $original_article_id_exists = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT post_id, meta_value 
					FROM $wpdb->postmeta 
					WHERE meta_key = 'newspack_original_article_id' 
					  AND meta_value = %d",
					$original_article_id
				)
	        );
			$original_article_id_exists = ! is_null( $original_article_id_exists );

	        if ( $original_article_id_exists ) {
		        WP_CLI::warning( sprintf( "Article ID %d already exists. Skipping.", $original_article_id ) );
				continue;
	        }*/

			$additional_meta = [];
	        $featured_image_attachment_id = null;

	        $post_title = '';
	        $post_excerpt = '';
	        $post_date = '';
            $post_modified = '';
	        $post_name = '';
			$article_authors = [];
			$article_tags = [];
			if ( 'Opinión' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['post_title'] );
				$post_date = $article['post_date'];
				$post_name = $article['post_name'];
				$article_authors = $article['post_author'];
			} elseif ( 'Podcasts' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['title'] );
				$post_date = $article['createdAt'];
				$post_name = $article['slug'];
				$article_authors = ! is_null( $article['author'] ) ? $article['author'] : [];
			} elseif ( 'Quién es quién' == $assoc_args['category-name'] ) {

				// Stop re-importing Quien es quen posts for now. We need an incremental check first otherwise we'll end up with dupe avatar images.
				WP_CLI::error( "Re-importing Quien es quen posts will create duplicate featured images. This command is not ready for that yet, make necessary adjustments to it first." );

				$post_title = trim( $article['title'] );
				$post_date = $article['createdAt'];
				$post_name = $article['slug'];
				$article_authors = [];
				if ( isset( $article['picture']['name'] ) ) {
					$featured_img_url = 'https://www.lasillavacia.com/media/' . $article['picture']['name'];
					$featured_image_attachment_id = $this->attachments->import_external_file( $featured_img_url );
					if ( is_wp_error( $featured_image_attachment_id ) || ! $featured_image_attachment_id ) {
						$msg = sprintf( "ERROR: Article ID %d, error importing featured image URL %s err: %s", $original_article_id, $featured_img_url, is_wp_error( $featured_image_attachment_id ) ? $featured_image_attachment_id->get_error_message() : '/' );
						$this->file_logger( $msg );
					} else {
						$msg = sprintf( "Article ID %d, imported featured image attachment ID %d", $original_article_id, $featured_image_attachment_id );
						$this->file_logger( $msg );
					}
				};
				if ( isset( $article['picture'] ) ) {
					$additional_meta['newspack_picture'] = $article['picture'];
				}
			} elseif ( 'Silla Académica' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['post_title'] );
                $post_date = $article['post_date'];
				$post_modified = $article['publishedAt'] ?? $post_date;
				$post_name = $article['post_name'];
				$article_authors = ! is_null( $article['post_author'] ) ? $article['post_author'] : [];
				if ( isset( $article['image'] ) ) {
					$additional_meta['newspack_image'] = $article['image'];
				}
				if ( isset( $article['keywords'] ) ) {
					$additional_meta['newspack_keywords'] = $article['keywords'];
				}
				if ( isset( $article['url'] ) ) {
					$additional_meta['newspack_url'] = $article['url'];
				}
			} elseif ( 'Silla Nacional' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['post_title'] );
				$post_excerpt = $article['post_excerpt'] ?? '';
				$post_date = $article['post_date'];
                $post_modified = $article['publishedAt'] ?? $post_date;
				$post_name = $article['post_name'];
				$article_authors = ! is_null( $article['post_author'] ) ? $article['post_author'] : [];
				if ( isset( $article['tags'] ) && ! is_null( $article['tags'] ) && ! empty( $article['tags'] ) ) {
					foreach ( $article['tags'] as $article_tag ) {
						if ( isset( $article_tag['name'] ) && ! empty( $article_tag['name'] ) ) {
							$article_tags[] = $article_tag['name'];
						}
					}
				}
				if ( isset( $article['image'] ) ) {
					$additional_meta['newspack_image'] = $article['image'];
				}
				if ( isset( $article['keywords'] ) ) {
					$additional_meta['newspack_keywords'] = $article['keywords'];
				}
				if ( isset( $article['url'] ) ) {
					$additional_meta['newspack_url'] = $article['url'];
				}
				if ( isset( $article['tags'] ) ) {
					$additional_meta['newspack_tags'] = $article['tags'];
				}
			} elseif ( 'Detector de mentiras' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['title'] );
				$post_excerpt = $article['description'] ?? '';
				$post_name = $article['slug'];
				$post_date = $article['createdAt'];
				$article_authors = ! is_null( $article['authors'] ) ? $article['authors'] : [];
				if ( isset( $article['tags'] ) && ! is_null( $article['tags'] ) && ! empty( $article['tags'] ) ) {
					foreach ( $article['tags'] as $article_tag ) {
						if ( isset( $article_tag['name'] ) && ! empty( $article_tag['name'] ) ) {
							$article_tags[] = $article_tag['name'];
						}
					}
				}

				if ( isset( $article['picture'] ) ) {
					$additional_meta['newspack_picture'] = $article['picture'];
				}
				if ( isset( $article['url'] ) ) {
					$additional_meta['newspack_url'] = $article['url'];
				}
				if ( isset( $article['tags'] ) ) {
					$additional_meta['newspack_tags'] = $article['tags'];
				}
			} elseif ( 'En Vivo' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['title'] );
				$post_name = $article['slug'];

				// Date may be faulty.
				$date_part = $this->is_date_valid( $article['StartDate'], 'Y-m-d' ) ? $article['StartDate'] : date("Y-m-d");;

				// Very faulty time, contains many formats and some pure errors.
				$time_part = ( $article['time'] != "None" ? $article['time'] : '00:00:00' );
				$time_part = str_replace( ' pm', '', $time_part );
				$time_part = str_replace( ' am', '', $time_part );
				$time_part = str_replace( ' ', '', $time_part );
				if ( 1 != preg_match("|^\d{1,2}:\d{1,2}$|", $time_part ) ) {
					$time_part = '00:00:00';
				}

				$post_date = $date_part . ' ' . $time_part;

				if ( isset( $article['canonical'] ) ) {
					$additional_meta['newspack_canonical'] = $article['canonical'];
				}
			}

	        // Using hash instead of just using original Id in case Id is 0. This would make it seem like the article is a duplicate.
	        $original_article_slug = $post_name ?? '';
	        $hashed_import_id = md5( $post_title . $original_article_slug );
	        $this->file_logger( "Original Article ID: $original_article_id | Original Article Title: $post_title | Original Article Slug: $original_article_slug" );


	        // Skip importing post if $incremental_import is true and post already exists.
	        if ( true === $incremental_import ) {
				$existing_postid_by_original_article_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_original_article_id' and meta_value = %s", $original_article_id ) );
		        $existing_postid_by_hashed_import_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_hashed_import_id' and meta_value = %s", $hashed_import_id ) );
				if ( $existing_postid_by_original_article_id && $existing_postid_by_hashed_import_id && $existing_postid_by_original_article_id == $existing_postid_by_hashed_import_id ) {
					WP_CLI::line( sprintf( "Article was imported as Post ID %d, skipping.", $existing_postid_by_original_article_id ) );
					continue;
				}
	        }

	        // Get content.
	        $html = '';
	        if ( ! empty( $article['html'] ) ) {
				// handle_extracting_html_content() encapsulates post_content in <html> tag.
		        // $html = $this->handle_extracting_html_content( $article['html'] );
		        $html = $article['html'];
	        } elseif ( ! empty( $article['post_html'] ) ) {
		        $html = $article['post_html'];
            } elseif ( ! empty( $article['post_content'] ) ) {
                $html = $article['post_content'];
            } elseif ( ! empty( $article['content'] ) ) {
                $html = $article['content'];
            }

			if (
				( false != strpos( $html, 'data:image/' ) )
				&& ( false != strpos( $html, ';base64' ) )
			) {
				$skip_base64_html_ids[] = $original_article_id;
				$html = '__';
				continue;
			}

			// This is a one and single article out of all others for which wp_insert_post() fails to insert post_content because it contains BASE64 encoded images.
	        // This post has been manually imported and should be skipped because data is not supported and already in the database.
	        $failed_inserts_post_names = [ 'los-10-imperdibles-del-ano-para-procrastinar-en-vacaciones'];
	        if ( in_array( $post_name, $failed_inserts_post_names ) ) {
				WP_CLI::warning( sprintf( "Post name %s contains invalid data which makes wp_insert_post() crash and will be skipped.", $post_name ) );
				continue;
	        }

	        // Check if post 'html' or 'post_content' exists in JSON.
	        if ( empty( $html ) ) {
		        $msg = sprintf( "ERROR: Article ID %d '%s' has no post_content", $original_article_id, $post_title );
		        WP_CLI::warning( $msg );
		        $this->file_logger( $msg );
		        continue;
	        }

	        $datetime_format = 'Y-m-d H:i:s';
	        $createdOnDT     = new DateTime( $post_date, new DateTimeZone( 'America/Bogota' ) );
	        $createdOn       = $createdOnDT->format( $datetime_format );
	        $createdOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
	        $createdOnGmt = $createdOnDT->format( $datetime_format );

            if ( empty( $post_modified ) ) {
                $post_modified = $post_date;
            }

	        $modifiedOnDT = new DateTime( $post_modified, new DateTimeZone( 'America/Bogota' ) );
	        $modifiedOn   = $modifiedOnDT->format( $datetime_format );
	        $modifiedOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
	        $modifiedOnGmt = $modifiedOnDT->format( $datetime_format );

			$meta_input = [
				'newspack_original_article_id' => $original_article_id,
//                    'canonical_url' => $article['CanonicalUrl'],
				'newspack_hashed_import_id' => $hashed_import_id,
				'newspack_original_article_categories' => $article['categories'],
				'newspack_original_post_author' => $article_authors,
			];
			if ( ! empty( $additional_meta ) ) {
				$meta_input = array_merge( $meta_input, $additional_meta );
			}
            $article_data = [
                'post_author' => 0,
                'post_date' => $modifiedOn,
                'post_date_gmt' => $modifiedOnGmt,
                'post_content' => $html,
                'post_title' => $post_title,
                'post_excerpt' => $post_excerpt,
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => $post_name,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $createdOn,
                'post_modified_gmt' => $createdOnGmt,
                'post_content_filtered' => '',
                'post_parent' => 0,
                'menu_order' => 0,
                'post_type' => 'post',
                'post_mime_type' => '',
                'comment_count' => 0,
                'meta_input' => $meta_input,
            ];

            if ( 1 === count( $article_authors ) ) {
                $article_data['post_author'] = $authors[ $article_authors[0] ] ?? 0;
            }

			if ( isset( $article['customfields'] ) ) {
	            foreach ( $article['customfields'] as $customfield ) {
	                $article_data['meta_input'][ $customfield['name'] ] = $customfield['value'];
	            }
			}

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

			if ( ! is_null( $featured_image_attachment_id ) ) {
				set_post_thumbnail( $post_id, $featured_image_attachment_id );
			}

            if ( count( $article_authors ) > 1 ) {
                $guest_author_query = "SELECT 
                        post_id 
                    FROM $wpdb->postmeta 
                    WHERE meta_key = 'original_user_id' 
                      AND meta_value IN (" . implode( ',', $article_authors ) . ')';
                $guest_author_ids = $wpdb->get_col( $guest_author_query );

				$this->coauthorsplus_logic->assign_guest_authors_to_post( $guest_author_ids, $post_id, false );
				/**
				 * This existing code below doesn't work -- it's not finding the $term_taxonomy_ids.
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
	        $some_categories_were_set = false;
            foreach ( $article['categories'] as $category ) {
				// Get category name.
				$category_name = null;
	            if ( isset( $category['title'] ) && ! is_null( $category['title'] ) ) {
		            $category_name = $category['title'];
	            } elseif ( isset( $category['name'] ) && ! is_null( $category['name'] ) ) {
		            $category_name = $category['name'];
	            }
				// Assign cat.
	            if ( ! is_null( $category_name ) ) {
					$term_id = $this->taxonomy->get_or_create_category_by_name_and_parent_id( $category_name, $top_category_term_id );
                    wp_set_post_terms( $post_id, $term_id, 'category', true );
	                $some_categories_were_set = true;
	            } else {
		            $this->file_logger( sprintf( "ERROR: ID %d, Category does not have a title: %s", $original_article_id, json_encode( $category ) ) );
	            }
            }
			// And if no cats were assigned, at least assign the top level category.
			if ( false === $some_categories_were_set ) {
                wp_set_post_terms( $post_id, $top_category_term_id, 'category', true );
			}

			// Set tags.
	        if ( ! empty( $article_tags ) ) {
		        wp_set_post_tags( $post_id, $article_tags, false );
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

		if ( ! empty( $skip_base64_html_ids ) ) {
			WP_CLI::error( sprintf( "Done with errors -- skipped importing post_content (because HTML contained B64 which failed during post creation) for original IDs -- these post_contents should be inserted manually : %s", implode( ',', $skip_base64_html_ids ) ) );
		}
    }

    /**
     * @throws Exception
     */
    public function cmd_update_migrated_articles( $args, $assoc_args )
    {
        global $wpdb;

		$start_at_id = $assoc_args['start-at-id'] ?? null;
		$end_at_id = $assoc_args['end-at-id'] ?? null;
		$skip = ! is_null( $start_at_id );
		$end = ! is_null( $end_at_id );

		$media_location = $assoc_args['media-location'];

        $original_article_id_to_new_article_id_map = $wpdb->get_results(
				"SELECT meta_value as original_article_id, post_id as new_article_id 
                FROM $wpdb->postmeta 
                WHERE meta_key = 'newspack_original_article_id'",
            OBJECT_K
        );
        $original_article_id_to_new_article_id_map = array_map( function( $item ) {
            return intval( $item->new_article_id );
        }, $original_article_id_to_new_article_id_map );

		$tags_and_category_taxonomy_ids = $wpdb->get_results(
			"SELECT term_taxonomy_id, term_id FROM $wpdb->term_taxonomy WHERE taxonomy IN ( 'post_tag', 'category' )",
			OBJECT_K
		);
		$tags_and_category_taxonomy_ids = array_map( function( $item ) {
			return intval( $item->term_id );
		}, $tags_and_category_taxonomy_ids );

        $datetime_format = 'Y-m-d H:i:s';

		$replace_accents = [
			'á' => 'a',
			'é' => 'e',
			'í' => 'i',
			'ó' => 'o',
			'ú' => 'u',
			'ñ' => 'n',
			'Á' => 'A',
			'É' => 'E',
			'Í' => 'I',
			'Ó' => 'O',
			'Ú' => 'U',
			'Ñ' => 'N',
		];

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $article ) {
	        if ( $skip && $start_at_id != $article['id']) {
		        continue;
	        } else {
		        $skip = false;
	        }

//            if ( 19375 === $article['id'] ) {
				echo "Handling OAID: {$article['id']}";

	            if ( ! array_key_exists( $article['id'], $original_article_id_to_new_article_id_map ) ) {
		            echo WP_CLI::colorize( " %YCORRESPONDING POST ID NOT FOUND. Skipping...%n\n\n" );
		            continue;
	            }

	            $post_id = $original_article_id_to_new_article_id_map[ $article['id'] ];
				echo " | WPAID: $post_id\n";

	            $post_data = [];
	            $post_meta = [];

                /*
                 * PUBLISHED DATE UPDATE SECTION
                 * * */
                $post_date = date( $datetime_format, time() );
                $post_modified = '';

                if ( isset( $article['post_date'] ) ) {
                    $post_date = $article['post_date'];
                }

                if ( isset( $article['publishedAt'] ) ) {
                    $post_modified = $article['publishedAt'];
                }

                if ( empty( $post_modified ) && isset( $article['post_modified'] ) ) {
                    $post_modified = $article['post_modified'];
                }

                $createdOnDT     = new DateTime( $post_date, new DateTimeZone( 'America/Bogota' ) );
                $createdOn       = $createdOnDT->format( $datetime_format );
                $createdOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
                $createdOnGmt = $createdOnDT->format( $datetime_format );

                if ( empty( $post_modified ) ) {
                    $post_modified = $post_date;
                }

                $modifiedOnDT = new DateTime( $post_modified, new DateTimeZone( 'America/Bogota' ) );
                $modifiedOn   = $modifiedOnDT->format( $datetime_format );
                $modifiedOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
                $modifiedOnGmt = $modifiedOnDT->format( $datetime_format );

				$post_data['post_date'] = $modifiedOn;
				$post_data['post_date_gmt'] = $modifiedOnGmt;
				$post_data['post_modified'] = $createdOn;
				$post_data['post_modified_gmt'] = $createdOnGmt;
                /* * *
                 * PUBLISHED DATE UPDATE SECTION
                 */

                /*
                 * POST AUTHOR UPDATE SECTION
                 * * */
	            if ( ! empty( $article['post_author'] ) ) {
		            $first_author_original_id = array_shift( $article['post_author'] );
		            try {
			            $author          = new MigrationAuthor( $first_author_original_id );
			            $author_assigned = $author->assign_to_post( $post_id );

			            if ( $author_assigned ) {
				            echo WP_CLI::colorize( "%WAssigned {$author->get_output_description()} to post ID {$post_id}%n\n" );
			            }
		            } catch ( Exception $e ) {
			            echo WP_CLI::colorize( "%Y{$e->getMessage()}%n\n" );
		            }

		            foreach ( $article['post_author'] as $original_author_id ) {
			            try {
				            $author          = new MigrationAuthor( $original_author_id );
				            $author_assigned = $author->assign_to_post(
					            $post_id,
					            true
				            );

				            if ( $author_assigned ) {
					            echo WP_CLI::colorize( "%WAssigned {$author->get_output_description()} to post ID {$post_id}%n\n" );
				            }
			            } catch ( Exception $e ) {
				            echo WP_CLI::colorize( "%Y{$e->getMessage()}%n\n" );
			            }
		            }
	            }
                /* * *
                 * POST AUTHOR UPDATE SECTION
                 */

                /*
                 * IMPORT KEYWORDS SECTION
                 * * */
	            if ( ! empty( $article['keywords'] ) ) {
		            $first_keyword = array_shift( $article['keywords'] );
					$post_meta['_yoast_wpseo_focuskw'] = $first_keyword;

					$post_meta['_yoast_wpseo_focuskeywords'] = [];
		            foreach ( $article['keywords'] as $keyword ) {
						$post_meta['_yoast_wpseo_focuskeywords'][] = [
							'keyword' => $keyword,
							'score' => 50,
						];
		            }
					$post_meta['_yoast_wpseo_focuskeywords'] = json_encode( $post_meta['_yoast_wpseo_focuskeywords'] );
	            }
	            /* * *
				 * IMPORT KEYWORDS SECTION
				 */

                /*
                 * FEATURED IMAGE SECTION
                 * * */
	            $has_featured_image = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND post_id = %d",
						$post_id
					)
	            );
				$has_featured_image = ! is_null( $has_featured_image );
				if ( ! $has_featured_image ) {
					if ( ! empty( $article['image'] ) ) {
						$filename       = $article['image']['name'];
						$full_file_path = $media_location . '/' . $filename;

						if ( ! file_exists( $full_file_path ) ) {
							$modified_file_name = str_replace(
								array_keys( $replace_accents ),
								array_values( $replace_accents ),
								$filename
							);
							echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path, searching for $media_location/$modified_file_name ...%n\n" );
							$full_file_path = $media_location . '/' . $modified_file_name;
						}

						if ( ! file_exists( $full_file_path ) ) {
							$quoted_file_name = "'$filename'";
							echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path, searching for $media_location/$quoted_file_name ...%n\n" );
							$full_file_path = $media_location . '/' . $quoted_file_name;
						}

						if ( ! file_exists( $full_file_path ) ) {
							$quoted_file_name = "'$filename'";
							$quoted_file_name = str_replace(
								array_keys( $replace_accents ),
								array_values( $replace_accents ),
								$quoted_file_name
							);
							echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path, searching for $media_location/$quoted_file_name ...%n\n" );
							$full_file_path = $media_location . '/' . $quoted_file_name;
						}

						if ( ! file_exists( $full_file_path ) ) {
							$modified_file_name = strtolower( str_replace( ' ', '_', $filename ) );
							echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path, searching for $media_location/$modified_file_name ...%n\n" );
							$full_file_path = $media_location . '/' . $modified_file_name;
						}

						if ( ! file_exists( $full_file_path ) ) {
							$modified_file_name = strtolower(
								str_replace(
									array_keys( $replace_accents ),
									array_values( $replace_accents ),
									$filename
								)
							);
							echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path, searching for $media_location/$modified_file_name ...%n\n" );
							$full_file_path = $media_location . '/' . $modified_file_name;
						}

						if ( ! file_exists( $full_file_path ) ) {
							$modified_file_name = strtolower( str_replace( ' ', '_', $filename ) );
							$modified_file_name = strtolower(
								str_replace(
									array_keys( $replace_accents ),
									array_values( $replace_accents ),
									$modified_file_name
								)
							);
							echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path, searching for $media_location/$modified_file_name ...%n\n" );
							$full_file_path = $media_location . '/' . $modified_file_name;
						}

						if ( file_exists( $full_file_path ) ) {
							update_post_meta( $post_id, 'newspack_featured_image_position', '' );
							$featured_image_attachment_id = $this->attachments->import_external_file(
								$full_file_path,
								$article['image']['FriendlyName'],
								$article['image']['caption'] ?? '',
								null,
								null,
								$post_id
							);

							if ( is_wp_error( $featured_image_attachment_id ) || ! $featured_image_attachment_id ) {
								$msg = sprintf(
									"ERROR: (OAID) %d, WPAID %d, error importing featured image %s err: %s\n",
									$article['id'],
									$post_id,
									$full_file_path,
									is_wp_error( $featured_image_attachment_id )
										? $featured_image_attachment_id->get_error_message() : '/'
								);
								echo $msg;
							} else {
								$post_meta['_thumbnail_id'] = $featured_image_attachment_id;
								$msg = sprintf(
									"(OAID) %d, WPAID %d, imported featured image attachment ID %d\n",
									$article['id'],
									$post_id,
									$featured_image_attachment_id
								);
								echo WP_CLI::colorize( "%b$msg%n" );
							}
						} else {
							echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path%n\n" );
						}
					}
				}
                /* * *
                 * FEATURED IMAGE SECTION
                 * /

                /*
                 * VIDEO AS FEATURED IMAGE SECTION
                 * * */
                $html = $article['post_html'];

                if ( ! is_null( $article['video'] ) ) {
                    $src = $article['video']['name'];
                    $html = '<iframe src="' . $src . '" style="width:100%;height:500px;overflow:auto;">' . $src . '</iframe>' . $html;

                    $featured_image_update = $wpdb->update(
                        $wpdb->postmeta,
                        [
                            'meta_key'   => 'newspack_featured_image_position',
                            'meta_value' => 'hidden',
                        ],
                        [
                            'post_id' => $post_id,
                            'meta_key' => 'newspack_featured_image_position'
                        ]
                    );

                    echo WP_CLI::colorize( "%wFeatured image update: $featured_image_update%n\n" );
                }
				$post_data['post_content'] = $html;
                /* * *
                 * VIDEO AS FEATURED IMAGE SECTION
                 */

	            /*
                 * TAXONOMY SECTION
                 * * */
	            $tag_term_ids = [];
	            foreach ( $article['tags'] as $tag ) {
					$taxonomy = $tag['taxonomy'];
					$term_taxonomy_id = $tag['term_taxonomy_id'];

					if ( ! array_key_exists( $term_taxonomy_id, $tags_and_category_taxonomy_ids ) ) {
			            echo WP_CLI::colorize( "%m$taxonomy term_taxonomy_id: $term_taxonomy_id does not exist in DB%n\n" );
						continue;
		            }

					$tag_term_ids[] = $tags_and_category_taxonomy_ids[ $term_taxonomy_id ];
	            }
	            if ( ! empty( $tag_term_ids ) ) {
		            $result = wp_set_post_terms( $post_id, $tag_term_ids );
	            }

				$category_term_ids = [];
				foreach ( $article['categories'] as $category ) {
					$taxonomy = $category['taxonomy'];
					$term_taxonomy_id = $category['term_taxonomy_id'];

					if ( ! array_key_exists( $term_taxonomy_id, $tags_and_category_taxonomy_ids ) ) {
						echo WP_CLI::colorize( "%m$taxonomy term_taxonomy_id: $term_taxonomy_id does not exist in DB%n\n" );
						continue;
					}

					$category_term_ids[] = $tags_and_category_taxonomy_ids[ $term_taxonomy_id ];
				}
	            if ( ! empty( $category_term_ids ) ) {
		            $result = wp_set_post_terms( $post_id, $category_term_ids, 'category' );
	            }
	            /* * *
				 * TAXONOMY SECTION
				 */

                $execution = $wpdb->update(
                    $wpdb->posts,
                    $post_data,
                    [
                        'ID' => $post_id
                    ]
                );

	            if ( ! empty( $post_meta ) ) {
		            foreach ( $post_meta as $meta_key => $meta_value ) {
			            update_post_meta( $post_id, $meta_key, $meta_value );
		            }
	            }

                /*$execution = wp_update_post(
                    [
                        'ID' => $post_id,
                        'post_content' => $article['post_html']
                    ]
                );*/
	            if ( (bool) $execution ) {
					echo WP_CLI::colorize( "%GPost updated: $post_id%n\n\n" );
	            } else {
		            echo WP_CLI::colorize( "%RPost update failed: $post_id%n\n\n" );
	            }
//                die();
//            }
	        if ( $end && $end_at_id == $article['id'] ) {
		        break;
	        }
        }

		wp_cache_flush();
    }

    /**
     * @param array $args
     * @param array $assoc_args
     * @return void
     */
    public function cmd_update_user_metadata( $args, $assoc_args )
    {
        global $wpdb;

        foreach ( $this->json_generator( $assoc_args['import-json'] ) as $import_user ) {
            unset( $import_user['bio'] );

            $login = WP_CLI::colorize( '%RNO LOGIN%n');
            if ( isset( $import_user['user_login'] ) ) {
                $login = WP_CLI::colorize( "%Y{$import_user['user_login']}%n" );
            } else if ( isset( $import_user['slug'] ) ) {
                $login = WP_CLI::colorize( "%Y{$import_user['slug']}%n" );
            }

            $email = ! empty( $import_user['user_email'] ) ? WP_CLI::colorize("%C{$import_user['user_email']}%n") : WP_CLI::colorize( '%RNO EMAIL%n');
            $role = $import_user['xpr_rol'] ?? $import_user['role'] ?? WP_CLI::colorize( "%wNO ROLE%n" );

            echo "{$import_user['id']}\t$login\t$email\t$role\n";
			$identifier = '';
	        if ( ! empty( $import_user['user_email'] ) ) {
		        $identifier = $import_user['user_email'];
	        } else if ( ! empty( $import_user['user_login'] ) ) {
		        $identifier = $import_user['user_login'];
	        } else if ( ! empty( $import_user['slug'] ) ) {
		        $identifier = $import_user['slug'];
	        }

            if ( empty( $identifier ) ) {
                echo WP_CLI::colorize( "%RNO IDENTIFIER FOUND. %n\n" );
                continue;
            }

            $db_user = get_user_by( 'email', $identifier );

            if ( ! $db_user ) {
                $db_user = get_user_by( 'login', $identifier );
            }

            if ( ! $db_user ) {
                echo WP_CLI::colorize( "%M User not found. %n\n" );
                continue;
            }

            echo WP_CLI::colorize( "%W wp_users.ID: $db_user->ID%n\n" );

            $original_user_id = $wpdb->get_row(
                $wpdb->prepare( "SELECT meta_value, umeta_id FROM $wpdb->usermeta 
                            WHERE meta_key = 'original_user_id' 
                              AND user_id = %d 
                              AND meta_value = %d", $db_user->ID, $import_user['id'] )
            );

            if ( ! is_null( $original_user_id ) ) {
                echo WP_CLI::colorize( "%G User has original_user_id metadata: $original_user_id->meta_value.%n\n" );
                continue;
            }

            $insertion = $wpdb->insert(
                $wpdb->usermeta,
                [
                    'user_id' => $db_user->ID,
                    'meta_key' => 'original_user_id',
                    'meta_value' => $import_user['id']
                ]
            );

            if ( ! $insertion ) {
                echo WP_CLI::colorize( "%R Error inserting original_user_id metadata. %n\n" );
            } else {
                echo WP_CLI::colorize( "%G Success inserting original_user_id metadata. %n\n" );
            }
        }
    }

    public function link_wp_users_to_guest_authors()
    {
	    global $wpdb;

		// Fix any guest authors with multiple accounts.
	    $guest_authors_with_multiple_accounts = $wpdb->get_results(
			"SELECT 
    				sub.meta_value as email, 
    				GROUP_CONCAT( sub.post_id ORDER BY sub.post_id ) as post_ids, 
    				COUNT( sub.post_id ) as counter 
				FROM (
    				SELECT *
					FROM wp_postmeta
					WHERE meta_key = 'cap-user_email' 
					  AND meta_value <> ''
				) as sub
				GROUP BY sub.meta_value
				HAVING counter > 1
				ORDER BY counter DESC"
	    );

	    if ( ! empty( $guest_authors_with_multiple_accounts ) ) {
		    WP_CLI::log( "Guest Authors with multiple accounts found. Attempting to remediate..." );
		    foreach ( $guest_authors_with_multiple_accounts as $guest_author_row ) {
			    $guest_author_ids = explode( ',', $guest_author_row->post_ids );
				$number_of_ids = count( $guest_author_ids );
				echo WP_CLI::colorize( "%y$guest_author_row->email%n %wwith $number_of_ids GA IDs found%n\n" );
				$first_guest_author_id = array_shift( $guest_author_ids );
				echo WP_CLI::colorize( "%wKeeping $first_guest_author_id%n\n" );

				$first_guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $first_guest_author_id );
				/*$original_user_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'original_user_id' AND post_id = %d",
						$first_guest_author_id
					)
				);*/

				$facebook_url = get_post_meta( $first_guest_author_id, 'facebook_url', true );
				$linkedin_url = get_post_meta( $first_guest_author_id, 'linkedin_url', true );
			    foreach ( $guest_author_ids as $guest_author_id ) {
				    $other_original_user_ids = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT meta_value 
							FROM $wpdb->postmeta 
							WHERE meta_key = 'original_user_id' 
							  AND post_id = %d 
							  AND meta_value NOT IN ( 
							      SELECT meta_value 
							      FROM $wpdb->postmeta 
							      WHERE meta_key = 'original_user_id' 
							        AND post_id = %d
							   )",
							$guest_author_id,
							$first_guest_author_id
						)
				    );
					$number_of_other_original_user_ids = count( $other_original_user_ids );
					echo WP_CLI::colorize( "%wFound $number_of_other_original_user_ids other original_user_ids, adding to $first_guest_author_id..%n\n" );
					foreach ( $other_original_user_ids as $other_original_user_id ) {
						$insertion = $wpdb->insert(
							$wpdb->postmeta,
							[
								'post_id' => $first_guest_author_id,
								'meta_key' => 'original_user_id',
								'meta_value' => $other_original_user_id
							]
						);

						if ( ! $insertion ) {
							echo WP_CLI::colorize( "%mFailed to add $other_original_user_id..%n\n" );
						} else {
							echo WP_CLI::colorize( "%wAdded $other_original_user_id%n\n" );
						}
					}

				    if ( empty( $facebook_url ) ) {
					    $facebook_url = get_post_meta( $guest_author_id, 'facebook_url', true );
					    if ( ! empty( $facebook_url ) ) {
						    update_post_meta( $first_guest_author_id, 'facebook_url', $facebook_url );
					    }
				    }

				    if ( empty( $linkedin_url ) ) {
					    $linkedin_url = get_post_meta( $guest_author_id, 'linkedin_url', true );
					    if ( ! empty( $linkedin_url ) ) {
						    update_post_meta( $first_guest_author_id, 'linkedin_url', $linkedin_url );
					    }
				    }

				    $result = $this->coauthorsplus_logic->delete_ga(
						$guest_author_id,
						sanitize_title( $first_guest_author->user_login )
				    );

				    if ( is_wp_error( $result ) ) {
					    echo WP_CLI::colorize( "%RError deleting GA ID $guest_author_id: {$result->get_error_message()}%n\n" );
				    } else {
						echo WP_CLI::colorize( "%CDeleted GA ID $guest_author_id%n\n" );
				    }
			    }
		    }
	    }

		$users_and_original_system_ids = $wpdb->get_results(
			"SELECT 
    			sub.*, 
    			u.user_login 
			FROM (
    			SELECT 
    			    user_id, 
    			    GROUP_CONCAT( meta_value ) as original_system_ids
    			FROM wp_usermeta um 
    			WHERE meta_key = 'original_user_id' 
    			GROUP BY user_id) as sub
			INNER JOIN wp_users u ON sub.user_id = u.ID"
		);

		foreach ( $users_and_original_system_ids as $user ) {
			WP_CLI::log( "Checking User ID: $user->user_id" );
			// Check if original_system_ids exists in wp_postmeta. If so, then we must link GA to WP User.
			$guest_author_row = $wpdb->get_row(
				"SELECT 
    					post_id as guest_author_id 
				FROM $wpdb->postmeta 
				WHERE meta_key = 'original_user_id' 
				  AND meta_value IN ( $user->original_system_ids )"
			);

			if ( ! $guest_author_row ) {
				WP_CLI::warning( "No guest author found, skipping..." );
				continue;
			}

			$first_guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_row->guest_author_id );

			if ( ! empty( $first_guest_author->linked_account ) ) {
				WP_CLI::warning( "Guest author already linked to: $first_guest_author->linked_account (wp_user.user_login $user->user_login ) skipping..." );
				continue;
			}

			$this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author_row->guest_author_id, get_user_by( 'id', $user->user_id ) );
			echo WP_CLI::colorize( "%GLinking to Guest Author ID: $guest_author_row->guest_author_id%n\n" );
		}
    }

	/**
	 * Checks if date is valid.
	 * Taken from https://stackoverflow.com/a/29093651 .
	 *
	 * @param string $date
	 * @param string $format
	 *
	 * @return bool
	 */
	private function is_date_valid ($date, $format = 'Y-m-d' ) {
		$d = DateTime::createFromFormat($format, $date);
		// The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
		return $d && $d->format($format) === $date;
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

	// Updates podcasts with their audio files and prepends an audio block to the post content.
	public function update_podcasts( array $args, array $assoc_args) : void {

		$command_meta_key = 'update_podcasts';
		$command_meta_version = 'v1';
		$log_file = "{$command_meta_key}_{$command_meta_version}.log";
		global $wpdb;

		foreach ( $this->json_iterator->items( $assoc_args['import-json'] ) as $podcast ) {
			// Find the existing podcast/article.
			$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_original_article_id' and meta_value = %s",
				$podcast->id ) );

			if ( ! $existing_id ) {
				$this->logger->log(
					$log_file,
					sprintf( "Could not find a post to update with original ID %d.", $podcast->id ),
					Logger::ERROR
				);
				continue;
			}

			if ( MigrationMeta::get( $existing_id, $command_meta_key, 'post' ) === $command_meta_version ) {
				$this->logger->log(
					$log_file,
					sprintf( "Post ID %d already has been updated to %s. Skipping.", $existing_id,
						$command_meta_version )
				);
				continue;
			}

			if ( empty( $podcast->audio ) ) {
				$this->logger->log(
					$log_file,
					sprintf( "Podcast ID %d has no audio file.", $podcast->id ),
					Logger::ERROR
				);
				continue;
			}

			// Strip the "media" folder part of the path in the json.
			$file_path = $assoc_args['media-dir'] . str_ireplace( '/media', '', $podcast->audio );

			if ( ! file_exists( $file_path ) ) {
				$this->logger->log(
					$log_file,
					sprintf( "Cant' find audio file %s for podcast with original ID %d.", $file_path, $podcast->id ),
					Logger::ERROR
				);
				continue;
			}

			$attachment_id = $this->attachments->import_external_file( $file_path );
			$audio_url     = wp_get_attachment_url( $attachment_id );
			$audio_block   = <<<BLOCK
<!-- wp:audio {"id":$attachment_id} -->
<figure class="wp-block-audio"><audio controls src="$audio_url"></audio></figure>
<!-- /wp:audio -->
BLOCK;

			$post               = get_post( $existing_id );
			$post->post_content = $audio_block . $post->post_content;
			wp_update_post( $post );

			MigrationMeta::update( $existing_id, $command_meta_key, 'post', $command_meta_version );

			WP_CLI::success( sprintf( "Updated post ID %d with audio file %s", $existing_id, $file_path ) );
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

class MigrationAuthor {

	protected CoAuthorPlus $coauthorsplus_logic;

	protected int $original_system_id;

	protected string $description = '';

	protected ?\WP_User $wp_user = null;

	protected ?\stdClass $guest_author = null;

	/**
	 * @throws Exception
	 */
	public function __construct( int $original_system_id ) {
		$this->original_system_id = $original_system_id;
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->find_user_from_original_system_id();
		$this->set_output_description();
	}

	public function get_original_system_id(): int {
		return $this->original_system_id;
	}

	public function get_wp_user(): \WP_User {
		return $this->wp_user;
	}

	public function get_guest_author(): \stdClass {
		return $this->guest_author;
	}

	public function is_wp_user(): bool {
		return ! is_null( $this->wp_user );
	}

	public function is_guest_author(): bool {
		return ! is_null( $this->guest_author );
	}

	public function get_output_description(): string {
		return $this->description;
	}

	public function assign_to_post( int $post_id, bool $append = false ): bool {
		$assigned_to_post = $this->coauthorsplus_logic
			->coauthors_plus
			->add_coauthors(
				$post_id,
				$this->get_author_data( $append ),
				$append
			);

		// TODO Remove this block once PR is accepted: https://github.com/Automattic/Co-Authors-Plus/pull/988
		if ( ! $assigned_to_post ) {
			if ( $this->is_guest_author() && ! $this->is_wp_user() ) {
				return true;
			} else if ( $this->is_wp_user() ) {
				// Must update `wp_posts.post_author` manually here.
				$update = wp_update_post(
					[
						'ID'            => $post_id,
						'post_author'   => $this->get_wp_user()->ID,
					]
				);

				if ( is_wp_error( $update ) ) {
					return false;
				} else {
					return (bool) $update;
				}
			}
		} else {
			return $assigned_to_post;
		}

		// TODO Uncomment this block once PR is accepted: https://github.com/Automattic/Co-Authors-Plus/pull/988
		/*if ( ! $assigned_to_post && ! $append && ! $this->is_wp_user() && $this->is_guest_author() ) {
			return true;
		} else {
			return $assigned_to_post;
		}*/
	}

	private function set_output_description(): void {
		$description = '';

		if ( $this->is_wp_user() ) {
			$description .= "WP_User.ID: {$this->get_wp_user()->ID}";
		}

		if ( $this->is_guest_author() ) {
			if ( ! empty( $description ) ) {
				$description .= " | ";
			}
			$description .= "GA.ID: {$this->get_guest_author()->ID}";
		}

		$this->description = $description;
	}

	private function get_author_data( bool $appending = false ): array {
		$author_data = [
			'wp_user' => null,
			'guest_author' => null,
		];

		if ( $this->is_wp_user() ) {
			$author_data['wp_user'] = $this->get_wp_user()->user_nicename;
		}

		if ( $this->is_guest_author() ) {
			$author_data['guest_author'] = $this->get_guest_author()->user_nicename;
		}

		if ( $appending ) {
			// If appending author, must remove wp_user to not overwrite wp_post.post_author
			unset( $author_data['wp_user'] );
		}

		return array_values( array_filter( $author_data ) );
	}

	/**
	 * @throws Exception
	 */
	private function find_user_from_original_system_id(): void {
		global $wpdb;
		// Check wp_usermeta first
		$usermeta_check = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'original_user_id' AND meta_value = %d",
				$this->get_original_system_id()
			)
		);

		if ( ! is_null( $usermeta_check ) ) {
			$this->wp_user = get_user_by( 'id', $usermeta_check->user_id );

			$this->guest_author = $this->coauthorsplus_logic->get_guest_author_by_linked_wpusers_user_login( $this->wp_user->user_login );

			return;
		}

		$guest_author_check = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_user_id' AND meta_value = %d",
				$this->get_original_system_id()
			)
		);

		if ( ! is_null( $guest_author_check ) ) {
			$this->guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_check->post_id );

			return;
		}

		throw new \Exception( "Could not find user with original_user_id: {$this->get_original_system_id()}" );
	}
}