<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use cli\Streams;
use cli\Table;
use DateTime;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Exception;
use Generator;
use NewspackCustomContentMigrator\Command\General\DownloadMissingImages;
use NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Logic\CoAuthorPlus;
use NewspackCustomContentMigrator\Logic\Posts;
use NewspackCustomContentMigrator\Logic\Redirection;
use NewspackCustomContentMigrator\Logic\SimpleLocalAvatars;
use NewspackCustomContentMigrator\Logic\Attachments;
use NewspackCustomContentMigrator\Logic\Images;
use NewspackCustomContentMigrator\Logic\Taxonomy;
use NewspackCustomContentMigrator\Utils\CommonDataFileIterator\FileImportFactory;
use NewspackCustomContentMigrator\Utils\ConsoleColor;
use NewspackCustomContentMigrator\Utils\ConsoleTable;
use NewspackCustomContentMigrator\Utils\JsonIterator;
use NewspackCustomContentMigrator\Utils\Logger;
use NewspackCustomContentMigrator\Utils\MigrationMeta;
use WP_CLI;
use WP_Http;
use WP_Term;
use WP_User;

class LaSillaVaciaMigrator implements InterfaceCommand {


	private $category_tree = array(
		array(
			'name'     => 'La Silla Nacional',
			'children' => array(
				array(
					'name'     => 'Caribe',
					'children' => array(),
				),
				array(
					'name'     => 'Bogotá',
					'children' => array(),
				),
				array(
					'name'     => 'Pacífico',
					'children' => array(),
				),
				array(
					'name'     => 'Antioquia',
					'children' => array(),
				),
				array(
					'name'     => 'Santanderes',
					'children' => array(),
				),
				array(
					'name'     => 'Región Sur',
					'children' => array(),
				),
				array(
					'name'     => 'Eje Cafetero',
					'children' => array(),
				),
			),
		),
		array(
			'name'     => 'En Vivo',
			'children' => array(),
		),
		array(
			'name'     => 'Red de Expertos',
			'children' => array(
				array(
					'name'     => 'Red Rural',
					'children' => array(),
				),
				array(
					'name'     => 'Red de la Paz',
					'children' => array(),
				),
				array(
					'name'     => 'Red de las Mujeres',
					'children' => array(),
				),
				array(
					'name'     => 'Red Cachaca',
					'children' => array(),
				),
				array(
					'name'     => 'Red de la Educación',
					'children' => array(),
				),
				array(
					'name'     => 'Red de Ciencia e Innovación',
					'children' => array(),
				),
				array(
					'name'     => 'Red Social',
					'children' => array(),
				),
				array(
					'name'     => 'Red Étnica',
					'children' => array(),
				),
				array(
					'name'     => 'Red Verde',
					'children' => array(),
				),
				array(
					'name'     => 'Red de Venezuela',
					'children' => array(),
				),
				array(
					'name'     => 'Red Paisa',
					'children' => array(),
				),
				array(
					'name'     => 'Red Sur',
					'children' => array(),
				),
				array(
					'name'     => 'Blogeconomía',
					'children' => array(),
				),
				array(
					'name'     => 'Red Pacífico',
					'children' => array(),
				),
				array(
					'name'     => 'Red Santandereana',
					'children' => array(),
				),
				array(
					'name'     => 'Red Caribe',
					'children' => array(),
				),
				array(
					'name'     => 'Red Minera',
					'children' => array(),
				),
				array(
					'name'     => 'Red Líder',
					'children' => array(),
				),
			),
		),
		array(
			'name'     => 'Opinión',
			'children' => array(
				array(
					'name'     => 'El Computador de Palacio',
					'children' => array(),
				),
				array(
					'name'     => 'Latonería y pintura',
					'children' => array(),
				),
				array(
					'name'     => 'El poder de las Cifras',
					'children' => array(),
				),
				array(
					'name'     => 'Del director editorial',
					'children' => array(),
				),
				array(
					'name'     => 'Desde el jardín',
					'children' => array(),
				),
				array(
					'name'     => 'Mi plebi-SI-TIO',
					'children' => array(),
				),
				array(
					'name'     => 'Dimensión desconocida',
					'children' => array(),
				),
				array(
					'name'     => 'Ojo al dato',
					'children' => array(),
				),
				array(
					'name'     => 'De la dirección',
					'children' => array(),
				),
				array(
					'name'     => 'Suarezterapia',
					'children' => array(),
				),
				array(
					'name'     => 'Desde los santanderes',
					'children' => array(),
				),
				array(
					'name'     => 'Ya está pintón',
					'children' => array(),
				),
				array(
					'name'     => 'Desde mi mecedora',
					'children' => array(),
				),
				array(
					'name'     => 'La pecera',
					'children' => array(),
				),
				array(
					'name'     => 'Piedra de Toque',
					'children' => array(),
				),
				array(
					'name'     => 'Otra Mirada',
					'children' => array(),
				),
				array(
					'name'     => 'Colombia Civil',
					'children' => array(),
				),
				array(
					'name'     => 'Ruido blanco',
					'children' => array(),
				),
				array(
					'name'     => 'Bemoles',
					'children' => array(),
				),
				array(
					'name'     => 'El picó',
					'children' => array(),
				),
				array(
					'name'     => 'La mesa de centro',
					'children' => array(),
				),
				array(
					'name'     => 'Hector Riveros',
					'children' => array(),
				),
				array(
					'name'     => 'Disculpe, se cayó el sistema',
					'children' => array(),
				),
				array(
					'name'     => 'Caleidoscopio',
					'children' => array(),
				),
			),
		),
		array(
			'name'     => 'Silla Datos',
			'children' => array(
				array(
					'name'     => 'Contratación',
					'children' => array(),
				),
				array(
					'name'     => 'Caso Uribe',
					'children' => array(),
				),
				array(
					'name'     => 'Poder de las empresas',
					'children' => array(),
				),
				array(
					'name'     => 'Poder regional',
					'children' => array(),
				),
				array(
					'name'     => 'Acuerdo de paz y posconflicto',
					'children' => array(),
				),
				array(
					'name'     => 'Poder nacional',
					'children' => array(),
				),
			),
		),
		array(
			'name'     => 'Detector de mentiras',
			'children' => array(
				array(
					'name'     => 'Cierto',
					'children' => array(),
				),
				array(
					'name'     => 'Cierto, pero',
					'children' => array(),
				),
				array(
					'name'     => 'Debatible',
					'children' => array(),
				),
				array(
					'name'     => 'Engañoso',
					'children' => array(),
				),
				array(
					'name'     => 'Falso',
					'children' => array(),
				),
			),
		),
		array(
			'name'     => 'Silla Cursos',
			'children' => array(
				array(
					'name'     => 'en línea',
					'children' => array(
						array(
							'name'     => 'Periodismo digital',
							'children' => array(),
						),
						array(
							'name'     => 'Liderazgo femenino',
							'children' => array(),
						),
						array(
							'name'     => 'Create digital products',
							'children' => array(),
						),
					),
				),
				array(
					'name'     => 'presenciales',
					'children' => array(
						array(
							'name'     => 'Inmersión 2023',
							'children' => array(),
						),
						array(
							'name'     => 'Inmersión 2022',
							'children' => array(),
						),
						array(
							'name'     => 'Inmersión 2021',
							'children' => array(),
						),
						array(
							'name'     => 'Curso de vacaciones',
							'children' => array(),
						),
						array(
							'name'     => 'Contraseña',
							'children' => array(),
						),
					),
				),
			),
		),
		array(
			'name'     => 'Podcasts',
			'children' => array(
				array(
					'name'     => 'Huevos revueltos con política',
					'children' => array(),
				),
				array(
					'name'     => 'On the Record',
					'children' => array(),
				),
				array(
					'name'     => 'Deja Vu',
					'children' => array(),
				),
				array(
					'name'     => 'El futuro del futuro',
					'children' => array(),
				),
				array(
					'name'     => 'El País de los Millenials',
					'children' => array(),
				),
				array(
					'name'     => 'Los Incómodos',
					'children' => array(),
				),
			),
		),
		array(
			'name'     => 'Silla Académica',
			'children' => array(
				array(
					'name'     => 'Universidad Javeriana',
					'children' => array(),
				),
				array(
					'name'     => 'Universidad del Norte',
					'children' => array(),
				),
				array(
					'name'     => 'Universidad del Rosario',
					'children' => array(),
				),
				array(
					'name'     => 'Universidad Pontificia Bolivariana',
					'children' => array(),
				),
				array(
					'name'     => 'Instituto de Estudios Urbanos de la Universidad Nacional de Colombia',
					'children' => array(),
				),
				array(
					'name'     => 'Universidades públicas - Convenio Ford',
					'children' => array(),
				),
				array(
					'name'     => 'Universidad públicas - Convenio Usaid',
					'children' => array(),
				),
				array(
					'name'     => 'Universidad Externado',
					'children' => array(),
				),
				array(
					'name'     => 'Universidad de Los Andes',
					'children' => array(),
				),
				array(
					'name'     => 'Universidad de Manizales',
					'children' => array(),
				),
				array(
					'name'     => 'Observatorio para la Equidad de Las Mujeres ICESI-FWWB',
					'children' => array(),
				),
				array(
					'name'     => 'Facultad de Ciencias Sociales de La Universidad de Los Andes',
					'children' => array(),
				),
				array(
					'name'     => 'Publicaciones',
					'children' => array(
						array(
							'name'     => 'Papers',
							'children' => array(),
						),
					),
				),
				array(
					'name'     => 'Eventos',
					'children' => array(
						array(
							'name'     => 'Libros',
							'children' => array(),
						),
						array(
							'name'     => 'Publicaciones seriadas',
							'children' => array(),
						),
						array(
							'name'     => 'Estudios patrocinados',
							'children' => array(),
						),
					),
				),
			),
		),
		array(
			'name'     => 'Quién es quién',
			'children' => array(),
		),
		array(
			'name'     => 'Especiales',
			'children' => array(),
		),
	);

	private $tags = array(
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
	);

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
		$this->log_file_path        = date( 'YmdHis', time() ) . 'LSV_import.log';
		$this->coauthorsplus_logic  = new CoAuthorPlus();
		$this->simple_local_avatars = new SimpleLocalAvatars();
		$this->redirection          = new Redirection();
		$this->logger               = new Logger();
		$this->attachments          = new Attachments();
		$this->taxonomy             = new Taxonomy();
		$this->images               = new Images();
		$this->json_iterator        = new JsonIterator();
	}

	/**
	 * Singleton get instance.
	 *
	 * @return mixed|LaSillaVaciaMigrator
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
			'newspack-content-migrator la-silla-vacia-establish-taxonomy',
			array( $this, 'establish_taxonomy' ),
			array(
				'shortdesc' => 'Establishes the category tree and tags for this publisher',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-migrate-authors',
			array( $this, 'migrate_authors' ),
			array(
				'shortdesc' => 'Migrates authors.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV authors.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'emails-csv',
						'description' => 'Migrate just these emails, skip all other user records.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'media-location',
						'description' => 'Path to media directory',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-resolve-damaged-author-guest-author-rels',
			array( $this, 'cmd_resolve_damaged_author_guest_author_relationships' ),
			array(
				'shortdesc' => 'Goes through all users JSON files, and if their avatars are not set, imports them from file expected to be found in media folder path.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-all-author-avatars',
			array( $this, 'cmd_update_all_author_avatars' ),
			array(
				'shortdesc' => 'Goes through all users JSON files, and if their avatars are not set, imports them from file expected to be found in media folder path.',
				'synopsis'  => array(
					array(
						'description' => 'https://drive.google.com/file/d/1R5N1QYpcOsOT3gW6u6QCanJlR5cPrzhb/view?usp=drive_link',
						'type'        => 'assoc',
						'name'        => 'json-authors-silla-academica',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'description' => 'https://drive.google.com/file/d/1u59tq746o1Wg8p4Bbx5byqQDdwJOBV3u/view?usp=drive_link',
						'type'        => 'assoc',
						'name'        => 'json-authors-silla-llena',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'description' => 'https://drive.google.com/file/d/1ktu9ayl_sYAQbTCoXTgQHFLuJCqMLk7A/view?usp=drive_link',
						'type'        => 'assoc',
						'name'        => 'json-expertos',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'description' => 'https://drive.google.com/file/d/1UJLagdAVrFs02WdeCVJ8D8F32_o_2qi_/view?usp=drive_link',
						'type'        => 'assoc',
						'name'        => 'json-authors',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'      => 'assoc',
						'name'      => 'path-folder-with-images',
						'optional'  => false,
						'repeating' => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-migrate-expertos-as-guest-authors',
			array( $this, 'migrate_expertos_2' ),
			array(
				'shortdesc' => 'Migrates expertos as guest authors.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV authors.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'fullnames-csv',
						'description' => 'Migrate just these full names, skip all other user records.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'media-location',
						'description' => 'Path to media directory',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-migrate-expertos-without-emails-as-guest-authors',
			array( $this, 'cmd_import_expertos_without_email' ),
			array(
				'shortdesc' => 'Migrates expertos as guest authors.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV authors.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-migrate-customers',
			array( $this, 'cmd_import_customers' ),
			array(
				'shortdesc' => 'Migrates expertos as guest authors.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV authors.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-migrate-users',
			array( $this, 'migrate_users' ),
			array(
				'shortdesc' => 'Migrates users.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV authors.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'start-at-id',
						'description' => 'The original user ID to start at.',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-migrate-articles',
			array( $this, 'migrate_articles' ),
			array(
				'shortdesc' => 'Migrate articles',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'incremental-import',
						'description' => "If this flag is set, it will only import new posts and won't re-import data for existing ones.",
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV articles.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'category-name',
						'description' => 'Name of base category to where the JSON posts are being imported. See migrate_articles() for allowed values.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'media-location',
						'description' => 'Path to media directory',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		$update_migrated_articles_synopsis = array(
			array(
				'type'        => 'assoc',
				'name'        => 'import-json',
				'description' => 'The file which contains LSV articles.',
				'optional'    => false,
				'repeating'   => false,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'media-location',
				'description' => 'Path to media directory',
				'optional'    => false,
				'repeating'   => false,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'start-at-id',
				'description' => 'Original article ID to start from',
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'assoc',
				'name'        => 'end-at-id',
				'description' => 'Original article ID to end at',
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'flag',
				'name'        => 'published-date',
				'description' => 'If this flag is set, it will update the published date of the post.',
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'flag',
				'name'        => 'post-authors',
				'description' => "If this flag is set, it will update the post's post authors.",
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'flag',
				'name'        => 'keywords',
				'description' => 'If this flag is set, it will update/set the yoast keywords for the post.',
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'flag',
				'name'        => 'featured-image',
				'description' => 'If this flag is set, it will set the featured image for the post.',
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'flag',
				'name'        => 'remove-existing-featured-image',
				'description' => 'If this flag is set, it will update the featured image for the post.',
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'flag',
				'name'        => 'video-featured-image',
				'description' => 'If this flag is set, it will update/set a video as the featured image for the post.',
				'optional'    => true,
				'repeating'   => false,
			),
			array(
				'type'        => 'flag',
				'name'        => 'taxonomy',
				'description' => 'If this flag is set, it will update/set the taxonomy for the post.',
				'optional'    => true,
				'repeating'   => false,
			),
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-migrated-articles',
			array( $this, 'cmd_update_migrated_articles' ),
			array(
				'shortdesc' => 'Update migrated articles',
				'synopsis'  => $update_migrated_articles_synopsis,
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-specific-articles',
			array( $this, 'cmd_force_update_specific_articles' ),
			array(
				'shortdesc' => 'Force update specific artilces by Post or Original Article IDs',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'The Post IDs which need to get updated.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'original-article-ids',
						'description' => 'The Original Article IDs which need to get updated.',
						'optional'    => false,
						'repeating'   => false,
					),
					...$update_migrated_articles_synopsis,
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-set-category-restriction-for-guest-author',
			[ $this, 'cmd_set_category_restriction_for_guest_author' ],
			[
				'shortdesc' => 'Set category restriction for guest author.',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'csv-file-path',
						'description' => 'Full path for CSV File pointing to category restrictions.',
						'optional'    => false,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-set-featured-images-for-detector-de-mentiras',
			array( $this, 'cmd_set_featured_images_for_detector_de_mentiras' ),
			array(
				'shortdesc' => 'Go through DB and find meta data for featured images for Detector de Mentiras posts and set them as featured images.',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-author-metadata',
			array( $this, 'cmd_update_user_metadata' ),
			array(
				'shortdesc' => 'Update or insert missing author metadata',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV articles.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-link-wp-user-to-guest-author',
			array( $this, 'link_wp_users_to_guest_authors' ),
			array(
				'shortdesc' => 'Link WP users to guest authors',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-set-author-for-posts',
			array( $this, 'cmd_set_author_for_posts' ),
			array(
				'shortdesc' => 'Set a particular author for posts (of a certain category if necessary)',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'guest-author-id',
						'description' => 'The guest author ID to assign to posts. This or wp-user-id required, but not both.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'wp-user-id',
						'description' => 'The WP_User ID to assign to posts. This or guest-author-id required, but not both.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'The Post IDs to assign the author to.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'start-at-post-id',
						'description' => 'The Post ID to start from.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'term-taxonomy-id',
						'description' => 'The taxonomy belonging to posts where the author will be assigned to.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'flag',
						'name'        => 'append',
						'description' => 'If set, the author will be added to the posts in question as a co-author.',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-wp-user-logins-and-nicenames',
			array( $this, 'driver_update_wp_user_logins_and_nicenames' ),
			array(
				'shortdesc' => 'Updates guest author slug.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'wp-user-id',
						'description' => 'WP_User ID.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-guest-author-slug',
			array( $this, 'cmd_update_guest_author_slug' ),
			array(
				'shortdesc' => 'Updates guest author slug.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'guest-author-id',
						'description' => 'Guest author ID (Post ID).',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-set-primary-category',
			array( $this, 'cmd_set_primary_category' ),
			array(
				'shortdesc' => 'Sets primary category for posts in a particular taxonomy.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'term-taxonomy-id',
						'description' => 'The term taxonomy ID used to obtain posts for which a primary category needs to be set.',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-devhelper-ivans-helper',
			array( $this, 'cmd_ivan_helper_cmd' ),
			array(
				'shortdesc' => "Ivan U's helper command with various dev snippets.",
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-devhelper-get-all-children-cats-of-a-cat',
			array( $this, 'cmd_helper_get_all_children_cats_of_a_cat' ),
			array(
				'shortdesc' => "Ivan U's helper command which gets all children cats of a cat.",
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-devhelper-delete-all-posts-in-select-categories',
			array( $this, 'cmd_helper_delete_all_posts_in_select_categories' ),
			array(
				'shortdesc' => "Ivan U's helper command which gets all children cats of a cat.",
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-update-img-paths-in-category-or-posts',
			array( $this, 'cmd_update_img_paths_in_category_or_posts' ),
			array(
				'shortdesc' => 'Updates paths in <img> elements either in all posts in category, or in specific post IDs. Provide either category-term-id or post-ids-csv.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'search',
						'description' => 'Search string in <img>.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'replace',
						'description' => 'Replace string in <img>.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'category-term-id',
						'description' => 'Category term_id in which all belonging posts will be updated.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'category-term-id',
						'description' => 'Category term_id in which all belonging posts will be updated.',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-ids-csv',
						'description' => 'Post IDs in CSV format, which will be updated.',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-create-author-redirects',
			array( $this, 'cmd_create_author_redirects' ),
			array(
				'shortdesc' => 'Migrate redirects',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-migrate-redirects',
			array( $this, 'migrate_redirects' ),
			array(
				'shortdesc' => 'Migrate redirects',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file which contains LSV redirects',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'flag',
						'name'        => 'reset-db',
						'description' => 'Resets the database for a fresh import.',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-podcasts',
			array( $this, 'update_podcasts' ),
			array(
				'shortdesc' => 'Go over podcasts and update their data if necessary.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file that contains podcasts data.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'media-dir',
						'description' => 'The directory where the media folder is located',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-create-missing-podcasts',
			array( $this, 'create_missing_podcasts' ),
			array(
				'shortdesc' => 'Create podcasts with only an audio file.',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file that contains podcasts data.',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'media-dir',
						'description' => 'The directory where the media folder is located',
						'optional'    => false,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-download-missing-images',
			array( $this, 'download_missing_images' ),
			array(
				'shortdesc' => 'Try to find and download missing images',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'media-dir',
						'description' => 'Location of media files on disk',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-id-range',
						'description' => 'Post ID range to process - separated by a dash, e.g. 1-1000',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'Only run on the given post ID',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-fix-incorrect-author-terms',
			array( $this, 'cmd_fix_incorrect_author_terms' ),
			array(
				'shortdesc' => 'Fix incorrect author terms',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-validate-guest-authors',
			array( $this, 'cmd_validate_guest_authors' ),
			array(
				'shortdesc' => 'Go through current Guest Authors and validate their data',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-fix-loose-author-terms',
			array( $this, 'cmd_fix_loose_author_terms' ),
			array(
				'shortdesc' => 'Fix author terms which are not tied to a wp_term_taxonomy record',
				'synopsis'  => array(),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-fix-user-guest-author-term-data',
			array( $this, 'cmd_fix_user_guest_author_term_data' ),
			array(
				'shortdesc' => 'Fix user guest author term data',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'guest-author-id',
						'description' => 'Guest Author ID, (wp_posts.ID)',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'term-id',
						'description' => 'Term ID, (wp_terms.term_id) that links to a taxonomy, which may need linking to the GA',
						'optional'    => true,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'user-id',
						'description' => 'The User ID (wp_users.ID) that needs to or should be linked to the GA',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-fix-standalone-guest-author-term-data',
			array( $this, 'cmd_fix_standalone_guest_author_term_data' ),
			array(
				'shortdesc' => 'Fix user guest author term data',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'guest-author-id',
						'description' => 'Guest Author ID, (wp_posts.ID)',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'term-id',
						'description' => 'Term ID, (wp_terms.term_id) that links to a taxonomy, which may need linking to the GA',
						'optional'    => true,
						'repeating'   => false,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-update-post-content-that-has-specific-url',
			array( $this, 'cmd_update_post_content_that_has_specific_url' ),
			array(
				'shortdesc' => 'This command will find post content with lasilla.com. It will then find the media in the DB and attempt to update the URL',
				'synopsis'  => array(
					array(
						'type'        => 'assoc',
						'name'        => 'media-location',
						'description' => 'Path to media directory',
						'optional'    => false,
						'repeating'   => false,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'after-post-id',
						'description' => 'Start after this post ID',
						'optional'    => true,
						'repeating'   => false,
						'default'     => 0,
					),
				),
			)
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-sync-author-guest-author-to-posts',
			[ $this, 'cmd_sync_author_guest_author_to_posts' ],
			[
				'shortdesc' => 'This command will process a file provided by the LSV team which tells us which Author should be assigned to which Post',
				'synopsis'  => [
					[
						'type'        => 'assoc',
						'name'        => 'author-json-path',
						'description' => 'Path to media directory',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'author-posts-json-path',
						'description' => 'Path to media directory',
						'optional'    => false,
						'repeating'   => false,
					],

					[
						'type'        => 'assoc',
						'name'        => 'after-original-article-id',
						'description' => 'Process only after this original article ID',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-upload-missing-publicaciones-images',
			[ $this, 'cmd_upload_missing_publicaciones_images' ],
			[
				'shortdesc' => 'This command will upload a missing featured image for Publicaciones content',
				'synopsis' => [
					[
						'type'        => 'assoc',
						'name'        => 'import-json',
						'description' => 'The file that contains podcasts data.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'media-location',
						'description' => 'Path to media directory',
						'optional'    => false,
						'repeating'   => false,
					]
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-fix-featured-images-for-publicaciones-directly',
			[ $this, 'cmd_fix_featured_images_for_publicaciones_directly' ],
			[
				'shortdesc' => 'This command will upload a missing featured image for Publicaciones content',
				'synopsis' => [
					[
						'type'        => 'assoc',
						'name'        => 'post-ids',
						'description' => 'The Post IDs to address',
						'optional'    => false,
						'repeating'   => false,
					],
				]
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-fix-missing-author-guest-author-link',
			[ $this, 'cmd_fix_missing_author_guest_author_link' ],
			[
				'shortdesc' => 'This command will pull a list of authors which seem to be missing a linkage to a guest author',
				'synopsis'  => [],
			]
		);

		WP_CLI::add_command(
			'newspack-content-migrator la-silla-vacia-remove-duplicate-article-import-meta-data',
			[ $this, 'cmd_remove_duplicate_article_import_meta_data' ],
			[
				'shortdesc' => 'This command will address a set of data which has been duplicated from various imports',
				'synopsis'  => [],
			]
		);
	}

	private function reset_db() {
		WP_CLI::runcommand(
			'db reset --yes --defaults',
			array(
				'return'     => true,
				'parse'      => 'json',
				'launch'     => false,
				'exit_error' => true,
			)
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
	public function establish_taxonomy() {
		$this->create_categories( $this->category_tree );

		foreach ( $this->tags as $tag ) {
			wp_create_tag( $tag );
		}
	}

	/**
	 * @param array $categories
	 * @param int   $parent_id
	 */
	public function create_categories( array $categories, int $parent_id = 0 ) {
		foreach ( $categories as $category ) {
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
	private function json_generator( string $file, string $json_path = '' ) {
		$file = file_get_contents( $file );
		$json = json_decode( $file, true );

		if ( ! empty( $json_path ) ) {
			$path = explode( '.', $json_path );
			foreach ( $path as $step ) {
				$json = $json[ $step ];
			}
		}

		foreach ( $json as $element ) {
			yield $element;
		}
	}

	/**
	 * Migrates the author data from LSV.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function migrate_authors( $args, $assoc_args ) {
		$specific_emails = isset( $assoc_args['emails-csv'] ) ? explode( ',', $assoc_args['emails-csv'] ) : null;
		$media_location  = $assoc_args['media-location'];

		foreach ( $this->json_generator( $assoc_args['import-json'] ) as $author ) {

			// If given, will migrate only authors with these emails.
			if ( ! is_null( $specific_emails ) && ! in_array( $author['user_email'], $specific_emails ) ) {
				continue;
			}

			$role = $author['xpr_rol'] ?? $author['role'] ?? 'antiguos usuarios';

			if ( ! array_key_exists( 'user_email', $author ) ) {
				if ( array_key_exists( 'email', $author ) ) {
					$author['user_email'] = $author['email'];
				} else {
					echo "Skipping because missing email\n";
					continue;
				}
			}

			if ( empty( $author['user_login'] ) ) {
				$author['user_login'] = substr( $author['user_email'], 0, strpos( $author['user_email'], '@' ) );
			}

			$this->file_logger( "Attempting to create User. email: {$author['user_email']} | login: {$author['user_login']} | role: $role" );
			$author_data = array(
				'user_login'      => substr( $author['user_email'], 0, strpos( $author['user_email'], '@' ) ),
				'user_pass'       => wp_generate_password( 24 ),
				'user_email'      => $author['user_email'],
				'user_registered' => $author['user_registered'],
				'first_name'      => $author['user_name'] ?? '',
				'last_name'       => $author['user_lastname'] ?? '',
				'display_name'    => $author['display_name'],
			);

			$guest_author_required = false;

			switch ( $role ) {
				case 'author':
				case 'editor':
					if ( isset( $author['xpr_rol'] ) ) {
						$author_data['role'] = $author['xpr_rol'];
					}
					$guest_author_required = true;
					break;
				case 'SillaLlenaExpertos':
					$author_data['role']   = 'contributor';
					$guest_author_required = true;
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
					// CAP
					$guest_author_data = array(
						'user_login'  => $author['user_login'],
						'user_email'  => $author['user_email'],
						'first_name'  => $author['user_name'] ?? $author['name'] ?? '',
						'last_name'   => $author['user_lastname'] ?? $author['lastname'] ?? '',
						'description' => strip_tags( $author['bio'] ?? '' ),
						// TODO handle avatar for guest author

						/*
						if ( is_array( $author['image'] ) ) {
							$author['image'] = $author['image'][0];
						}*/
						// 'avatar' => $this->handle_profile_photo( $author['image'] );
					);

					if ( empty( $guest_author_data['display_name'] ) ) {
						$guest_author_data['display_name'] = $guest_author_data['first_name'] . ' ' . $guest_author_data['last_name'];
						$author['display_name']            = $guest_author_data['display_name'];
					}

					$this->file_logger( json_encode( $guest_author_data ), false );

					$post_id = $this->coauthorsplus_logic->create_guest_author( $guest_author_data );
					if ( is_wp_error( $post_id ) ) {
						$this->file_logger(
							sprintf(
								"Error Creating GA (user_login: '%s', user_email: '%s', first_name: '%s', user_lastname: '%s', display_name: '%s'), err: %s",
								$author['user_login'],
								$author['user_email'],
								$author['first_name'] ?? '',
								$author['user_lastname'] ?? '',
								$author['display_name'],
								$post_id->get_error_message()
							)
						);
						continue 2;
					}

					update_post_meta( $post_id, 'original_user_id', $author['id'] );
					update_post_meta( $post_id, 'original_role_id', $author['xpr_role_id'] ?? null );
					update_post_meta( $post_id, 'red', $author['red'] ?? null );
					update_post_meta( $post_id, 'description', $author['bio'] ?? null );
					update_post_meta( $post_id, 'xpr_usuario_de_twitter', $author['xpr_UsuariodeTwitter'] ?? null );
					update_post_meta( $post_id, 'usuario_de_twitter', $author['UsuariodeTwitter'] ?? null );
					update_post_meta( $post_id, 'ocupacion', $author['xpr_ocupacion'] ?? null );
					update_post_meta( $post_id, 'genero', $author['xpr_genero'] ?? null );
					update_post_meta( $post_id, 'facebook_url', $author['FacebookURL'] ?? null );
					update_post_meta( $post_id, 'linkedin_url', $author['LinkedInURL'] ?? null );
					update_post_meta( $post_id, 'instagram_url', $author['InstagramURL'] ?? null );
					update_post_meta( $post_id, 'whatsapp', $author['whatsApp'] ?? null );
					WP_CLI::success( "GA ID $post_id created." );
					continue 2;
			}

			$meta = array(
				'original_user_id'       => $author['id'],
				'original_role_id'       => $author['xpr_role_id'],
				'red'                    => $author['red'],
				'description'            => $author['bio'],
				'xpr_usuario_de_twitter' => $author['xpr_UsuariodeTwitter'],
				'usuario_de_twitter'     => $author['UsuariodeTwitter'],
				'ocupacion'              => $author['xpr_ocupacion'],
				'genero'                 => $author['xpr_genero'] ?? '',
				'facebook_url'           => $author['FacebookURL'],
				'linkedin_url'           => $author['LinkedInURL'],
				'instagram_url'          => $author['InstagramURL'],
				'whatsapp'               => $author['whatsApp'],
			);

			$this->file_logger( json_encode( $author_data ), false );
			$user_id = wp_insert_user( $author_data );
			if ( is_wp_error( $user_id ) ) {
				$field = 'login';
				$value = $author_data['user_login'];

				if ( $user_id->get_error_code() === 'existing_user_email' ) {
					$field = 'email';
					$value = $author_data['user_email'];
				}

				if ( $user_id->get_error_code() === 'existing_user_login' || $user_id->get_error_code() === 'existing_user_email' ) {
					echo WP_CLI::colorize( "%YUser already exists. Attempting to link existing user to guest author.%n\n" );
					$user = get_user_by( $field, $value );
					$this->insert_user_meta( $user->ID, $meta );
					$linked_guest_author = $this->coauthorsplus_logic->get_guest_author_by_linked_wpusers_user_login( $user->user_login );

					if ( ! $linked_guest_author ) {
						$this->file_logger( "Creating Guest Author {$author['user_email']}." );
						$new_guest_author_id = $this->coauthorsplus_logic->create_guest_author_from_wp_user( $user->ID );

						if ( is_wp_error( $new_guest_author_id ) ) {
							if ( $new_guest_author_id->get_error_code() === 'duplicate-field' ) {
								$unlinked_guest_author = $this->coauthorsplus_logic->coauthors_plus->get_coauthor_by( 'login', $user->user_login );

								if ( false === $unlinked_guest_author ) {
									$this->file_logger( "Error: Guest author with login {$user->user_login} not found." );
									continue;
								}
								$this->coauthorsplus_logic->link_guest_author_to_wp_user( $unlinked_guest_author->ID, $user );
								$linked_guest_author = $unlinked_guest_author;
							} else {
								$this->file_logger( $new_guest_author_id->get_error_message() );
								continue;
							}
						} else {
							$linked_guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $new_guest_author_id );
						}
					}

					if ( is_array( $author['image'] ) ) {
						$author['image'] = $author['image'][0];
					}

					if ( ! empty( $author['image'] ) ) {
						$this->file_logger( "Creating User's avatar. File: {$author['image']}" );
						$file_path_parts      = explode( '/', $author['image'] );
						$filename             = array_pop( $file_path_parts );
						$avatar_attachment_id = $this->handle_profile_photo( $filename, $media_location );

						$this->simple_local_avatars->assign_avatar( $user->ID, $avatar_attachment_id );
					}

					update_post_meta( $linked_guest_author->ID, 'original_user_id', $author['id'] );
					update_post_meta( $linked_guest_author->ID, 'original_role_id', $author['xpr_role_id'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'red', $author['red'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'description', $author['bio'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'xpr_usuario_de_twitter', $author['xpr_UsuariodeTwitter'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'usuario_de_twitter', $author['UsuariodeTwitter'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'ocupacion', $author['xpr_ocupacion'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'genero', $author['xpr_genero'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'facebook_url', $author['FacebookURL'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'linkedin_url', $author['LinkedInURL'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'instagram_url', $author['InstagramURL'] ?? null );
					update_post_meta( $linked_guest_author->ID, 'whatsapp', $author['whatsApp'] ?? null );
					continue;
				} else {
					$this->file_logger( $user_id->get_error_message() );
					continue;
				}
			}

			$this->file_logger( "User created. ID: $user_id" );

			$this->insert_user_meta( $user_id, $meta );

			if ( is_array( $author['image'] ) ) {
				$author['image'] = $author['image'][0];
			}

			if ( ! empty( $author['image'] ) ) {
				$this->file_logger( "Creating User's avatar. File: {$author['image']}" );
				$file_path_parts      = explode( '/', $author['image'] );
				$filename             = array_pop( $file_path_parts );
				$avatar_attachment_id = $this->handle_profile_photo( $filename, $media_location );

				$this->simple_local_avatars->assign_avatar( $user_id, $avatar_attachment_id );
			}

			if ( $guest_author_required ) {
				$guest_author_id = $this->coauthorsplus_logic->create_guest_author_from_wp_user( $user_id );
				update_post_meta( $guest_author_id, 'original_user_id', $author['id'] );
				update_post_meta( $guest_author_id, 'original_role_id', $author['xpr_role_id'] ?? null );
				update_post_meta( $guest_author_id, 'red', $author['red'] ?? null );
				update_post_meta( $guest_author_id, 'description', $author['bio'] ?? null );
				update_post_meta( $guest_author_id, 'xpr_usuario_de_twitter', $author['xpr_UsuariodeTwitter'] ?? null );
				update_post_meta( $guest_author_id, 'usuario_de_twitter', $author['UsuariodeTwitter'] ?? null );
				update_post_meta( $guest_author_id, 'ocupacion', $author['xpr_ocupacion'] ?? null );
				update_post_meta( $guest_author_id, 'genero', $author['xpr_genero'] ?? null );
				update_post_meta( $guest_author_id, 'facebook_url', $author['FacebookURL'] ?? null );
				update_post_meta( $guest_author_id, 'linkedin_url', $author['LinkedInURL'] ?? null );
				update_post_meta( $guest_author_id, 'instagram_url', $author['InstagramURL'] ?? null );
				update_post_meta( $guest_author_id, 'whatsapp', $author['whatsApp'] ?? null );
			}
		}
	}

	private function insert_user_meta( int $user_id, array $meta ) {
		foreach ( $meta as $meta_key => $meta_value ) {
			if ( ! empty( $meta_value ) ) {
				add_user_meta( $user_id, $meta_key, $meta_value );
			}
		}
	}

	private function insert_guest_author_meta( int $guest_author_id, array $meta ) {
		foreach ( $meta as $meta_key => $meta_value ) {
			if ( ! empty( $meta_value ) ) {
				add_post_meta( $guest_author_id, $meta_key, $meta_value );
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
		$json_files = array(
			array(
				'file'                     => $assoc_args['json-authors-silla-academica'],
				'json_key_used_for_avatar' => 'image',
			),
			array(
				'file'                     => $assoc_args['json-authors-silla-llena'],
				'json_key_used_for_avatar' => 'image',
			),
			array(
				'file'                     => $assoc_args['json-authors'],
				'json_key_used_for_avatar' => 'image',
			),
			array(
				'file'                     => $assoc_args['json-expertos'],
				'json_key_used_for_avatar' => 'picture',
			),
		);
		foreach ( $json_files as $json_file ) {
			if ( ! file_exists( $json_file['file'] ) ) {
				WP_CLI::error( sprintf( 'File %s does not exist.', $json_file['file'] ) );
			}
		}

		// Loop through all JSON files and import avatars if needed.
		foreach ( $json_files as $key_json_file => $json_file ) {

			$users = json_decode( file_get_contents( $json_file['file'] ), true );
			foreach ( $users as $key_user => $user ) {

				// Progress.
				WP_CLI::line( sprintf( 'file %d/%d user %d/%d', $key_json_file + 1, count( $json_files ), $key_user + 1, count( $users ) ) );

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
						$ga    = $this->coauthorsplus_logic->get_guest_author_by_email( $email );
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
						$ga           = $this->coauthorsplus_logic->get_guest_author_by_display_name( $display_name );
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

					WP_CLI::warning( sprintf( 'user_email: %s has faulty avatar, will reimport', $email ) );
				}

				// Import avatar from file.
				$image_file_path = $path . '/' . $avatar_filename;
				if ( ! file_exists( $image_file_path ) ) {
					$this->logger->log( 'cmd_update_all_author_avatars__ERROR_FILENOTFOUND.log', sprintf( 'user_email: %s > json_file: %s > image_file_path: %s does not exist.', $email, $json_file['file'], $image_file_path ), $this->logger::WARNING );
					continue;
				}
				$att_id = $this->attachments->import_external_file( $image_file_path, $ga->ID );
				if ( is_wp_error( $att_id ) ) {
					$this->logger->log( 'cmd_update_all_author_avatars__ERROR_ATTACHMENTIMPORT.log', sprintf( 'file:%s err:%s', $image_file_path, $att_id->get_error_message() ), $this->logger::WARNING );
					continue;
				}
				$this->coauthorsplus_logic->update_guest_author( $ga->ID, array( 'avatar' => $att_id ) );

				// Yey!
				$this->logger->log( 'cmd_update_all_author_avatars__UPDATED.log', sprintf( 'ga_id: %d imported avatar att_ID: %s', $ga->ID, $att_id ), $this->logger::SUCCESS );
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
				WP_CLI::warning( sprintf( 'User %s has more than one image, using the first one.', $data['id'] ) );
			}
			$image_file = $data['image'][0];

		} elseif ( 'picture' == $key_used_for_image ) {

			// Some validation.
			if ( ! isset( $data['picture'] ) || is_null( $data['picture'] ) || empty( $data['picture'] ) ) {
				return null;
			}
			if ( ! is_string( $data['picture'] ) ) {
				WP_CLI::warning( sprintf( 'Unexpected value for picture: ', json_encode( $data['picture'] ) ) );
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
	public function migrate_expertos_as_guest_authors( $args, $assoc_args ) {
		$media_location = $assoc_args['media-location'];

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
			if ( ! empty( $user['email'] ) ) {
				$guest_author_exists = $this->coauthorsplus_logic->coauthors_guest_authors->get_guest_author_by( 'user_email', $user['email'] );
			}

			$names      = explode( ' ', $user['fullname'] );
			$last_name  = array_pop( $names );
			$first_name = implode( ' ', $names );

			$guest_author_data = array(
				'display_name' => $user['fullname'],
				'user_login'   => $user['slug'],
				'first_name'   => $first_name,
				'last_name'    => $last_name,
				'user_email'   => $user['email'],
				'website'      => $user['url'],
			);

			$description = '';

			if ( ! empty( $user['description'] ) ) {
				$description .= $user['description'];
			}

			$guest_author_data['description'] = $description;

			if ( ! $guest_author_exists ) {
				if ( empty( $user['email'] ) ) {
					$guest_author_data['user_email'] = $user['slug'] . '@no-site.com';
				}

				$guest_author_data['user_pass'] = wp_generate_password( 24 );
				$user_id                        = wp_insert_user( $guest_author_data );

				if ( is_wp_error( $user_id ) ) {
					$this->file_logger( "Error creating user {$user_id->get_error_code()}: {$user_id->get_error_message()}" );
					continue;
				}

				$guest_author_id = $this->coauthorsplus_logic->create_guest_author_from_wp_user( $user_id );

				if ( is_wp_error( $guest_author_id ) ) {
					$this->file_logger(
						sprintf(
							"Error Creating GA (user_login/slug: '%s', user_email: '%s', first_name: '%s', user_lastname: '%s', display_name: '%s'), err: %s",
							$user['slug'],
							$user['email'],
							$first_name,
							$last_name,
							$user['fullname'],
							$guest_author_id->get_error_message()
						)
					);
					continue;
				}

				$this->file_logger( "Created GA ID {$guest_author_id}" );

				// Import a new media item only if new GA is created -- shouldn't reimport if GA already exists.
				if ( ! empty( $user['picture'] ) ) {
					$file_path_parts             = explode( '/', $user['picture'] );
					$filename                    = array_pop( $file_path_parts );
					$guest_author_data['avatar'] = $this->handle_profile_photo( $filename, $media_location );
				}
			} else {
				$guest_author_id = $guest_author_exists->ID;
				$this->file_logger( "Exists GA ID {$guest_author_exists->ID}" );
				if ( $guest_author_exists->linked_account ) {
					$user_id = get_user_by( 'login', $guest_author_exists->linked_account );
				} else {
					$user_id = wp_insert_user(
						array(
							'user_login'   => $guest_author_exists->user_login,
							'user_pass'    => wp_generate_password( 24 ),
							'user_email'   => $guest_author_exists->user_email,
							'first_name'   => $guest_author_exists->first_name ?? '',
							'last_name'    => $guest_author_exists->last_name ?? '',
							'display_name' => $guest_author_exists->display_name,
						)
					);
				}
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
			if ( ! empty( $user['categories'] ) ) {
				foreach ( $user['categories'] as $index => $category ) {
					$term_ids = get_terms(
						array(
							'fields'     => 'ids',
							'taxonomy'   => 'category',
							'name'       => $category['name'],
							'hide_empty' => false,
						)
					);

					if ( ! empty( $term_ids ) ) {
						resautcat_db_set_term( $user_id, $term_ids[0]->term_id, 'true' );
					}

					if ( str_contains( $category['name'], 'Univers' ) ) {
						update_post_meta( $guest_author_id, 'universidad', $category['name'] );
					} else {
						update_post_meta( $guest_author_id, "category_$index", $category['name'] );
					}
				}
			}
			if ( isset( $guest_author_data['avatar'] ) && ! empty( $guest_author_data['avatar'] ) ) {
				update_post_meta( $guest_author_id, '_thumbnail_id', $guest_author_data['avatar'] );
			}

			// Extra postmeta.
			update_post_meta( $guest_author_id, 'original_user_id', $user['id'] );
			update_post_meta( $guest_author_id, 'publicaciones', $user['publicaciones'] );
			update_post_meta( $guest_author_id, 'lineasInvestigacion', $user['lineasInvestigacion'] );

			foreach ( $user['publicaciones'] as $publicacion ) {
				$post_exists = get_post( $publicacion['id'] );

				if ( $post_exists ) {
					$this->coauthorsplus_logic->coauthors_plus->add_coauthors( $publicacion['id'], array( $guest_author_id ), true );
				}
			}
		}
	}

	public function migrate_expertos_2( $args, $assoc_args ) {
		$media_location = $assoc_args['media-location'];

		foreach ( $this->json_iterator->items( $assoc_args['import-json'] ) as $contributor ) {
			echo "\n";

			$contributor_id = $contributor->id;
			$full_name      = $contributor->fullname;
			$exploded_name  = explode( ' ', $full_name );
			$last_name      = array_pop( $exploded_name );
			$first_name     = implode( ' ', $exploded_name );

			echo WP_CLI::colorize( "%wID%n: %W$contributor_id%n | %wFull Name%n: %W$full_name%n\n" );

			$email = $contributor->email;

			if ( empty( $email ) ) {
				echo WP_CLI::colorize( '%YNo email found.%n' );
				$email = $contributor->slug . '@no-site.com';
				echo WP_CLI::colorize( " %wUsing%n %Y{$email}%n %was email.%n\n" );
			}

			$guest_author = $this->get_guest_author_by_original_user_id( $contributor_id );

			if ( null === $guest_author ) {
				// Does a Guest Author with the same user_login already exist?
				echo WP_CLI::colorize( "%wChecking if Guest Author exists with user_login: {$contributor->slug}%n " );
				$guest_author = $this->coauthorsplus_logic->get_guest_author_by_linked_wpusers_user_login( $contributor->slug );

				if ( $guest_author ) {
					echo WP_CLI::colorize( "%YYes%n\n" );
				} else {
					echo WP_CLI::colorize( "%BNo%n\n" );
				}
			}
			$user_id = 0;

			if ( null === $guest_author ) {
				// No Guest Author Exists
				// Create as WP_User, then create as Guest Author
				$user = get_user_by( 'email', $email );

				if ( ! $user ) {
					$user = get_user_by( 'login', $contributor->slug );
				}

				if ( ! $user ) {

					echo WP_CLI::colorize( "%cCreating WP_User%n\n" );

					$user_id = wp_insert_user(
						array(
							'user_login'   => $contributor->slug,
							'user_email'   => $email,
							'user_pass'    => wp_generate_password( 24 ),
							'display_name' => $full_name,
							'first_name'   => $first_name,
							'last_name'    => $last_name,
							'description'  => $contributor->description,
							'role'         => 'contributor',
						)
					);

					$user = get_user_by( 'id', $user_id );
				} else {
					$user_id = $user->ID;
				}

				if ( is_wp_error( $user_id ) ) {
					echo WP_CLI::colorize( "%rError creating WP_User: {$user_id->get_error_message()}%n\n" );
					continue;
				}

				echo WP_CLI::colorize( "%cCreating Guest Author%n\n" );
				$guest_author_id = $this->coauthorsplus_logic->create_guest_author_from_wp_user( $user_id );

				if ( is_wp_error( $guest_author_id ) ) {
					if ( 'duplicate-field' === $guest_author_id->get_error_code() ) {
						$guest_author = $this->get_existing_guest_author_which_prevents_creation( $user_id );

						$this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author->ID, $user );
					} else {
						echo WP_CLI::colorize( "%rError creating Guest Author {$guest_author_id->get_error_code()}: {$guest_author_id->get_error_message()}%n\n" );
						continue;
					}
				} else {
					$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );
				}
			} else {
				// Create WP_User from Guest Author
				echo WP_CLI::colorize( "%mGuest Author exists, creating WP_user from Guest Author%n\n" );
				$user = $this->coauthorsplus_logic->create_wp_user_from_guest_author( $guest_author->ID );

				if ( $user ) {
					$user_id = $user->ID;
				} else {
					echo WP_CLI::colorize( "%rError creating WP_User from Guest Author%n\n" );
					continue;
				}
			}

			if ( ! $user_id ) {
				WP_CLI::error( 'Could not create user.' );
			}

			// Handle Meta
			$meta = array(
				'original_user_id'          => $contributor_id,
				'cap-website'               => $contributor->url,
				'cap-newspack_phone_number' => $contributor->phone,
				'publicaciones'             => $contributor->publicaciones,
				'cap-newspack_role'         => $contributor->lineasInvestigacion,
				'lineasInvestigacion'       => $contributor->lineasInvestigacion,
				'cap-newspack_job_title'    => $contributor->lineasInvestigacion,
			);

			$this->insert_user_meta(
				$user_id,
				array_filter(
					$meta,
					fn( $key ) => ! str_starts_with( $key, 'cap-' ),
					ARRAY_FILTER_USE_KEY
				)
			);

			$this->insert_guest_author_meta( $guest_author->ID, $meta );

			// Handle Profile Picture
			if ( ! empty( $contributor->image ) || ! empty( $contributor->picture ) ) {
				$filename = basename( $contributor->image ?? $contributor->picture );
				echo WP_CLI::colorize( "%wCreating User's avatar.%n File: $filename\n" );
				$avatar_attachment_id = $this->handle_profile_photo( $filename, $media_location );

				$this->simple_local_avatars->assign_avatar( $user_id, $avatar_attachment_id );
			}

			// Handle Categories and Category Restrictions
			if ( ! empty( $contributor->categories ) ) {
				foreach ( $contributor->categories as $index => $category ) {
					$term_ids = get_terms(
						array(
							'fields'     => 'ids',
							'taxonomy'   => 'category',
							'name'       => $category->name,
							'hide_empty' => false,
						)
					);

					if ( ! empty( $term_ids ) && $term_ids[0] instanceof WP_Term ) {
						resautcat_db_set_term( $user_id, $term_ids[0]->term_id, 'true' );
					}

					if ( str_contains( $category->name, 'Univers' ) ) {
						update_post_meta( $guest_author->ID, 'universidad', $category->name );
					} else {
						update_post_meta( $guest_author->ID, "category_$index", $category->name );
					}
				}
			}

			// Handle Publicaciones
			$this->handle_publicaciones( $contributor->publicaciones, $guest_author );
		}
	}

	public function cmd_import_expertos_without_email( $args, $assoc_args ) {
		foreach ( $this->json_iterator->items( $assoc_args['import-json'] ) as $contributor ) {
			echo "\n";
			$contributor_id = $contributor->id;
			$full_name      = $contributor->name;
			$exploded_name  = explode( ' ', $full_name );
			$last_name      = array_pop( $exploded_name );
			$first_name     = implode( ' ', $exploded_name );

			echo WP_CLI::colorize( "%wID%n: %W$contributor_id%n | %wFull Name%n: %W$full_name%n\n" );

			$email = $contributor->slug . '@no-site.com';
			echo WP_CLI::colorize( " %wUsing%n %Y{$email}%n %was email.%n\n" );

			$guest_author = $this->get_guest_author_by_original_user_id( $contributor_id );

			if ( null === $guest_author ) {
				// Does a Guest Author with the same user_login already exist?
				echo WP_CLI::colorize( "%wChecking if Guest Author exists with user_login: {$contributor->slug}%n " );
				$guest_author = $this->coauthorsplus_logic->get_guest_author_by_linked_wpusers_user_login( $contributor->slug );

				if ( $guest_author ) {
					echo WP_CLI::colorize( "%YYes%n\n" );
				} else {
					echo WP_CLI::colorize( "%BNo%n\n" );

					$guest_author_id = $this->coauthorsplus_logic->create_guest_author(
						array(
							'display_name' => $full_name,
							'user_login'   => $contributor->slug,
							'first_name'   => $first_name,
							'last_name'    => $last_name,
							'user_email'   => $email,
							'website'      => $contributor->url,
							'description'  => $contributor->description,
						)
					);

					$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );
				}
			}

			$meta = array(
				'original_user_id' => $contributor_id,
				'cap-website'      => $contributor->url,
			);

			$this->insert_guest_author_meta( $guest_author->ID, $meta );

			if ( ! empty( $contributor->pubs ) ) {
				$this->handle_publicaciones(
					array_map(
						fn( $pub ) => (object) array( 'id' => $pub ),
						$contributor->pubs
					),
					$guest_author
				);
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
	public function migrate_users( $args, $assoc_args ) {
		$start_at_id = $assoc_args['start-at-id'] ?? null;
		$skip        = ! is_null( $start_at_id );

		$unmigrated_users_file_path = 'unmigrated-users.json';
		$unmigrated_users           = array();

		foreach ( $this->json_generator( $assoc_args['import-json'] ) as $user ) {
			if ( $skip && $user['id'] < $start_at_id ) {
				continue;
			} else {
				$skip = false;
			}

			$this->file_logger( "ID: {$user['id']} | EMAIL: {$user['email']} | NAME: {$user['name']}" );

			$is_valid_email = filter_var( $user['email'], FILTER_VALIDATE_EMAIL );

			if ( false === $is_valid_email ) {
				$this->file_logger( 'Invalid email. Skipping.' );
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
				array(
					'user_pass'       => wp_generate_password( 24 ),
					'user_login'      => $user['email'],
					'user_email'      => $user['email'],
					'display_name'    => $display_name,
					'first_name'      => $user['name'],
					'last_name'       => $user['lastname'],
					'description'     => $user['job'],
					'user_registered' => $created_at->format( 'Y-m-d H:i:s' ),
					'role'            => 'subscriber',
					'meta_input'      => array(
						'original_user_id'     => $user['id'],
						'original_system_role' => $user['user_group_name'] ?? '',
					),
				)
			);

			if ( is_wp_error( $user_id ) ) {
				$this->file_logger( $user_id->get_error_message() );
				$user['error']      = $user_id->get_error_message();
				$unmigrated_users[] = $user;
			}
		}

		if ( ! empty( $unmigrated_users ) ) {
			$this->file_logger( 'Writing unmigrated users to file.' );
			file_put_contents( $unmigrated_users_file_path, json_encode( $unmigrated_users ) );
		}
	}

	/**
	 * This command will use an export provided by the LSV team which contains all the author => post
	 * bylines they'd like to sync to the site.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_sync_author_guest_author_to_posts( $args, $assoc_args ) {
		$author_posts_json_path    = $assoc_args['author-posts-json-path'];
		$authors_json_path         = $assoc_args['author-json-path'];
		$after_original_article_id = $assoc_args['after-original-article-id'] ?? 0;

		$author_posts = wp_json_file_decode( $author_posts_json_path );
		$authors      = wp_json_file_decode( $authors_json_path );

		$authors_by_id = [];
		foreach ( $authors as $author ) {
			$author = (array) $author;
			if ( ! array_key_exists( $author['id'], $authors_by_id ) ) {
				$authors_by_id[ $author['id'] ] = $author;
			} else {
				WP_CLI::line( 'Duplicate author ID' );
				$authors_by_id[ $author['id'] ] = array_merge( $authors_by_id[ $author['id'] ], $author );
			}
		}

		$author_posts_by_original_article_id = [];
		foreach ( $author_posts as $author_post ) {
			$author_post = (array) $author_post;

			if ( ! array_key_exists( $author_post['section_id'], $author_posts_by_original_article_id ) ) {
				$author_post['user_ids'] = array_unique( array_map( 'intval', $author_post['user_ids'] ) );

				$author_posts_by_original_article_id[ $author_post['section_id'] ] = $author_post;
			}
		}
		$target_original_article_ids             = array_keys( $author_posts_by_original_article_id );
		$target_original_article_id_placeholders = implode( ',', array_fill( 0, count( $target_original_article_ids ), '%d' ) );

		global $wpdb;

		// Need uncached data, and $target_original_article_id_placeholders is a safe value.
		// phpcs:disable
		$imported_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value 
				FROM $wpdb->postmeta WHERE meta_key IN ('original_article_id','original_post_id','newspack_original_article_id')
				AND meta_value IN ( $target_original_article_id_placeholders )",
				...$target_original_article_ids
			)
		);
		// phpcs:enable

		$imported_posts_by_original_article_id = [];
		foreach ( $imported_posts as $post ) {
			if ( ! array_key_exists( $post->meta_value, $imported_posts_by_original_article_id ) ) {
				$imported_posts_by_original_article_id[ $post->meta_value ] = get_post( $post->post_id );
			} else {
				ConsoleColor::red( 'Duplicate Original Article ID' )->output();
				( new ConsoleTable() )->output_comparison(
					[],
					[
						'original_article_id' => $post->meta_value,
						'POST_ID'             => $post->post_id,
						'Current Post'        => $imported_posts_by_original_article_id[ $post->meta_value ],
					]
				);
			}
		}

		ksort( $author_posts_by_original_article_id );

		foreach ( $author_posts_by_original_article_id as $original_article_id => $value ) {
			if ( $original_article_id < $after_original_article_id ) {
				unset( $author_posts_by_original_article_id[ $original_article_id ] );
			} else {
				break;
			}
		}

		$total_posts = count( $author_posts_by_original_article_id );
		$counter     = 1;
		foreach ( $author_posts_by_original_article_id as $original_article_id => $value ) {
			echo "\n\n";
			WP_CLI::line( sprintf( 'Processing %d of %d', $counter, $total_posts ) );
			$post = $imported_posts_by_original_article_id[ $original_article_id ] ?? null;

			if ( null === $post ) {
				ConsoleColor::white( 'Post not found' )
							->white( 'Original Article ID' )
							->underlined_white( $original_article_id )
							->white( 'Original Post ID' )
							->underlined_white( $value['slug'] )
							->output();

				++$counter;
				continue;
			}

			$output_data = [
				'Post ID' => $post->ID,
				'OAID'    => $original_article_id,
				'DB Post' => $post->post_name,
				'File'    => $value['slug'],
			];
			( new ConsoleTable() )->output_data( [ $output_data ], array_keys( $output_data ) );

			$reset_authors = [];
			foreach ( $value['user_ids'] as $original_author_id ) {
				if ( ! array_key_exists( $original_author_id, $authors_by_id ) ) {
					ConsoleColor::yellow( 'Original Author ID not found in authors_by_id:' )->bright_yellow( $original_author_id )->output();
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$user = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM $wpdb->users WHERE ID = (SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'original_user_id' AND meta_value = %d)",
						$original_author_id
					)
				);

				$possible_user = null;
				if ( ! empty( $user ) ) {
					if ( count( $user ) > 1 ) {
						ConsoleColor::magenta( 'Multiple Users Found' )->output();
						( new ConsoleTable() )->output_comparison(
							[],
							$authors_by_id[ $original_author_id ],
							...$user,
						);
						$user_menu = [];
						foreach ( $user as $u ) {
							$user_menu[ $u->ID ] = $u->user_email;
						}
						$user_menu[] = 'None of the above';

						$choice = Streams::menu( $user_menu, null, 'Choose a user' );

						if ( 'None of the above' === $user_menu[ $choice ] ) {
							continue;
						}

						$user = get_user_by( 'id', $user_menu[ $choice ] );
					} else {
						$user = get_user_by( 'id', $user[0]->ID );

						if ( $user->user_email !== $authors_by_id[ $original_author_id ]['email'] ) {
							ConsoleColor::bright_yellow( 'Email Mismatch, going to try and find GA' )->output();
							( new ConsoleTable() )->output_comparison(
								[],
								$authors_by_id[ $original_author_id ],
								(array) $user->data,
							);

							$possible_user = new WP_User( clone $user->data );
							$user          = [];
						}
					}
				}

				if ( empty( $user ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$guest_author_ids = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT DISTINCT post_id FROM $wpdb->postmeta WHERE meta_key IN ('original_user_id', 'newspack_original_post_author') AND meta_value = %d",
							$original_author_id
						)
					);

					if ( empty( $guest_author_ids ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$guest_author_ids = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT post_id FROM $wpdb->postmeta WHERE meta_key LIKE %s AND meta_value = %s",
								$wpdb->esc_like( 'cap-' ) . '%',
								$authors_by_id[ $original_author_id ]['email']
							)
						);
					}

					if ( empty( $guest_author_ids ) ) {
						ConsoleColor::magenta( 'No Guest Author Found' )
									->white( 'Original Author ID:' )
									->underlined_white( $original_author_id )
									->output();
						( new ConsoleTable() )->output_comparison(
							[],
							$authors_by_id[ $original_author_id ]
						);

						continue;
					} elseif ( count( $guest_author_ids ) > 1 ) {
						foreach ( $guest_author_ids as $key => $guest_author_id ) {
							$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id->post_id );
							if ( is_bool( $guest_author ) ) {
								ConsoleColor::magenta( 'Guest Author results in Bool' )
											->white( 'Original Author ID' )
											->underlined_white( $original_author_id )
											->white( 'Guest Author ID' )
											->underlined_white( $guest_author_id )
											->output();

								( new ConsoleTable() )->output_data( [ $guest_author_ids ], array_keys( $guest_author_ids ), 'Guest Author IDs' );
								( new ConsoleTable() )->output_comparison(
									[],
									$authors_by_id[ $original_author_id ]
								);
								continue 2;
							}

							$guest_author_ids[ $guest_author_id->post_id ] = $guest_author;
							unset( $guest_author_ids[ $key ] );
						}

						ConsoleColor::yellow( "Multiple GA's" )->output();
						( new ConsoleTable() )->output_comparison(
							[],
							array_values( array_map( fn( $ga ) => $ga->user_email, $guest_author_ids ) )
						);
						( new ConsoleTable() )->output_comparison(
							[],
							$authors_by_id[ $original_author_id ]
						);
						$user_menu = [];
						foreach ( $guest_author_ids as $ga ) {
							$user_menu[ $ga->ID ] = $ga->user_email;
						}
						$user_menu[] = 'None of the above';

						$choice = Streams::menu( $user_menu, null, 'Choose a GA' );

						if ( 'None of the above' === $user_menu[ $choice ] ) {
							continue;
						}

						$user = $guest_author_ids[ $choice ];
					} else {
						$user = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_ids[0]->post_id );
						if ( is_bool( $user ) ) {
							ConsoleColor::magenta_with_white_background( 'Guest Author results in Bool' )
										->white( 'Original Author ID' )
										->underlined_white( $original_author_id )
										->output();

							( new ConsoleTable() )->output_data( [ $guest_author_ids ], array_keys( $guest_author_ids ), 'Guest Author IDs' );
							( new ConsoleTable() )->output_comparison(
								[],
								$authors_by_id[ $original_author_id ]
							);
							continue;
						}
					}
				}

				if ( empty( $user ) && $possible_user ) {
					$reset_authors[] = $possible_user;
				} else {
					$reset_authors[] = $user;
				}
			}

			$prepared_assigned_authors_query = $wpdb->prepare(
				"SELECT 
    						t.term_id,
    						tt.term_taxonomy_id,
    						t.name,
    						t.slug,
    						tt.taxonomy
						FROM $wpdb->terms t 
						    LEFT JOIN $wpdb->term_taxonomy tt 
						        ON t.term_id = tt.term_id 
						    LEFT JOIN $wpdb->term_relationships tr 
						        ON tt.term_taxonomy_id = tr.term_taxonomy_id 
						WHERE tt.taxonomy = 'author'
						  AND tr.object_id = %d",
				$post->ID
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$currently_assigned_authors = $wpdb->get_results( $prepared_assigned_authors_query );

			$this->coauthorsplus_logic->assign_authors_to_post( $reset_authors, $post->ID );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$assigned_authors_check = $wpdb->get_results( $prepared_assigned_authors_query );

			$term_taxonomy_id_cb = fn( $assigned_author_term ) => intval( $assigned_author_term->term_taxonomy_id );
			$different_authors   = array_diff(
				array_map(
					$term_taxonomy_id_cb,
					$assigned_authors_check
				),
				array_map(
					$term_taxonomy_id_cb,
					$currently_assigned_authors
				)
			);

			if ( ! empty( $different_authors ) ) {
				ConsoleColor::cyan( 'Old Authors' )->output();
				( new ConsoleTable() )->output_comparison( [], ...array_map( fn( $record ) => (array) $record, $currently_assigned_authors ) );
				ConsoleColor::underlined_cyan( 'New Authors' )->output();
				( new ConsoleTable() )->output_comparison( [], ...array_map( fn( $record ) => (array) $record, $assigned_authors_check ) );
			}

			++$counter;
		}
	}

	public function cmd_import_customers( $args, $assoc_args ) {
		foreach ( $this->json_iterator->items( $assoc_args['import-json'] ) as $index => $customer ) {
			echo "\n";

			$original_user_id = $customer->Id;

			$user = $this->get_wp_user_by_original_user_id( $original_user_id );

			$email = $customer->Correo;

			$this->file_logger( $email );

			$meta = array(
				'Curso'              => $customer->Curso,
				'original_user_id'   => $original_user_id,
				'OrderId'            => $customer->OrderId,
				'FechaInicio'        => $customer->FechaInicio,
				'ModulosVistos'      => $customer->ModulosVistos,
				'PorcentajeVisto'    => $customer->PorcentajeVisto,
				'LeccionesVistas'    => $customer->LeccionesVistas,
				'PorcentajeFaltante' => $customer->PorcentajeFaltante,
			);

			if ( ! $user ) {
				$user = get_user_by( 'email', $email );
			}

			if ( $user ) {
				$this->file_logger( 'User already exists. Skipping.' );
				$this->insert_user_meta( $user->ID, $meta );
				continue;
			}

			$display_name = $customer->Nombre ?? 'Sin Nombre ' . rand( $index, 10000 );
			$explode_name = explode( ' ', $display_name );
			$last_name    = array_pop( $explode_name );
			$first_name   = implode( ' ', $explode_name );

			$user_id = wp_insert_user(
				array(
					'user_pass'    => wp_generate_password( 24 ),
					'user_login'   => $email,
					'user_email'   => $email,
					'display_name' => $display_name,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'role'         => 'subscriber',
				)
			);

			if ( is_wp_error( $user_id ) ) {
				$this->file_logger( $user_id->get_error_message() );
				continue;
			}

			$this->insert_user_meta( $user_id, $meta );
		}
	}

	/**
	 * This function handles a custom CSV file provided by the publisher which details which Guest Authors
	 * should be restricted to certain categories. It relies on the Restrict Author Categories plugin.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 * @throws Exception
	 */
	public function cmd_set_category_restriction_for_guest_author( $args, $assoc_args ) {
		$csv_file_path = $assoc_args['csv-file-path'];
		$iterator      = ( new FileImportFactory() )->get_file( $csv_file_path )->getIterator();

		foreach ( $iterator as $row ) {
			ConsoleColor::white( 'Processing email:' )->underlined_white( $row['email'] )->output();
			$guest_author = $this->coauthorsplus_logic->get_guest_author_by_email( $row['email'] );

			if ( ! $guest_author ) {
				$user = get_user_by( 'email', $row['email'] );

				if ( $user ) {
					$guest_author_id = $this->coauthorsplus_logic->create_guest_author_from_wp_user( $user->ID );
					if ( is_wp_error( $guest_author_id ) ) {
						$guest_author_login = sanitize_title( $user->display_name );
						if ( empty( $user->display_name ) ) {
							$guest_author_login = sanitize_title( $user->first_name . ' ' . $user->last_name );
						}

						ConsoleColor::white( 'Error creating Guest Author:' )->underlined_white( $row['email'] )->output();
						ConsoleColor::yellow( 'Possible duplicate user_login' )->underlined_yellow( $guest_author_login )->output();
						continue;
					}
					$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );
				} else {
					ConsoleColor::bright_red( 'Guest Author not found:' )->underlined_bright_red( $row['email'] )->output();
					continue;
				}
			}

			if ( ! empty( $row['update_email'] ) ) {
				$this->coauthorsplus_logic->update_guest_author( $guest_author->ID, [ 'user_email' => $row['update_email'] ] );
			}

			if ( empty( $guest_author->linked_account ) ) {
				ConsoleColor::yellow( 'Guest Author does not have a linked account:' )->underlined_yellow( $row['email'] )->output();
				$user_by_email = get_user_by( 'email', $guest_author->user_email );

				if ( $user_by_email ) {
					ConsoleColor::cyan( 'Found user by email, linking to Guest Author' )->output();
					$this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author->ID, $user_by_email );
					$guest_author->linked_account = $user_by_email->user_login;
				} else {
					ConsoleColor::green( 'Creating user and linking to Guest Author' )->output();
					$user_id = wp_insert_user(
						[
							'user_login'    => substr( $guest_author->user_email, 0, strpos( $guest_author->user_email, '@' ) ),
							'user_email'    => $guest_author->user_email,
							'display_name'  => $guest_author->display_name,
							'user_nicename' => str_replace( 'cap-', '', $guest_author->user_login ),
							'user_pass'     => wp_generate_password( 24 ),
							'role'          => 'contributor',
						]
					);

					$new_user = get_user_by( 'id', $user_id );
					$this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author->ID, $new_user );
					$guest_author->linked_account = $new_user->user_login;
				}
			}

			$user = get_user_by( 'login', $guest_author->linked_account );

			if ( ! $user ) {
				ConsoleColor::bright_red( 'User not found:' )->underlined_bright_red( $guest_author->linked_account )->output();
				continue;
			}

			$category_columns = [
				$row['category_3'],
				$row['category_2'],
				$row['category_1'],
			];

			foreach ( $category_columns as $category_name ) {
				if ( empty( $category_name ) ) {
					continue;
				}

				$category = get_term_by( 'name', $category_name, 'category' );

				if ( ! $category ) {
					ConsoleColor::bright_red( 'Category not found:' )->underlined_bright_red( $category_name )->output();
					continue;
				}

				resautcat_db_set_user( $user->ID, 'true' );
				resautcat_db_set_term( $user->ID, $category->term_id, 'true' );

				while ( $category->parent ) {
					$category = get_term_by( 'id', $category->parent, 'category' );
					resautcat_db_set_term( $user->ID, $category->term_id, 'true' );
				}
			}
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
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} tr
				join {$wpdb->term_taxonomy} tt on tt.term_taxonomy_id = tr.term_taxonomy_id
				join {$wpdb->terms} t on t.term_id = tt.term_id
				join {$wpdb->posts} p on p.ID = tr.object_id
				where t.term_id = %d
				and p.post_type = 'post';",
					$category_term_id
				)
			);
		}

		WP_CLI::log( sprintf( 'Updating <imgs> in %d posts... Replacing:', count( $post_ids ) ) );
		WP_CLI::log( sprintf( '- from %s', $search ) );
		WP_CLI::log( sprintf( '- to %s', $replace ) );

		$updated_post_ids = array();
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
		$term_id            = 4984;
		$term               = get_term( $term_id, 'category' );
		$terms_children     = get_categories(
			array(
				'child_of'   => $term_id,
				'hide_empty' => 0,
			)
		);
		$terms_children_ids = array();
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
		$memes_de_la_semana_tag_id   = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM $wpdb->terms WHERE name = %s", $memes_de_la_semana_tag_name ) );
		if ( ! $memes_de_la_semana_tag_id ) {
			WP_CLI::error( "Tag '$memes_de_la_semana_tag_name' not found." );
		}
		$memes_de_la_semana_post_ids = get_posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => -1,
				'tag_id'         => $memes_de_la_semana_tag_id,
				'fields'         => 'ids',
			)
		);

		// Will delete posts from these categories.
		$cats = array(
			array(
				'term_id' => 4984,
				'name'    => 'Detector de mentiras',
			),
			array(
				'term_id' => 4932,
				'name'    => 'En Vivo',
			),
			array(
				'term_id' => 4952,
				'name'    => 'Opinión',
			),
			array(
				'term_id' => 5001,
				'name'    => 'Podcasts',
			),
			array(
				'term_id' => 5027,
				'name'    => 'Quién es quién',
			),
			array(
				'term_id' => 5008,
				'name'    => 'Silla Académica',
			),
			array(
				'term_id' => 4924,
				'name'    => 'Silla Nacional',
			),
		);
		foreach ( $cats as $cat ) {
			$term = get_term_by( 'id', $cat['term_id'], 'category' );
			if ( ! $term || ( $cat['name'] != $term->name ) ) {
				WP_CLI::error( "Category {$cat['name']} not found." );
			}

			// Get all children and subchildren category IDs. Two levels is enough for LSV structure.
			$terms_children_ids           = array();
			$terms_childrens_children_ids = array();
			$terms_children               = get_categories(
				array(
					'child_of'   => $term->term_id,
					'hide_empty' => 0,
				)
			);
			foreach ( $terms_children as $term_child ) {
				// Child term_id.
				$terms_children_ids[]     = $term_child->term_id;
				$terms_childrens_children = get_categories(
					array(
						'child_of'   => $term_child->term_id,
						'hide_empty' => 0,
					)
				);
				foreach ( $terms_childrens_children as $term_childs_child ) {
					// Child's child term_id.
					$terms_childrens_children_ids[] = $term_childs_child->term_id;
				}
			}

			// Get all posts in this cat.
			$postslist = get_posts(
				array(
					'category'       => $cat['term_id'],
					'post_type'      => 'post',
					'posts_per_page' => -1,
				)
			);
			WP_CLI::line( sprintf( "\n" . "Total %d posts in category '%s'", count( $postslist ), $cat['name'] ) );
			foreach ( $postslist as $post ) {

				// Check if post belongs to other cats.
				$all_post_cats = wp_get_post_categories( $post->ID, array( 'hide_empty' => 0 ) );
				// Subtract this ID, children IDs, and children's children IDs.
				$other_cats_ids                = $all_post_cats;
				$other_cats_ids                = array_diff( $other_cats_ids, array( $cat['term_id'] ) );
				$other_cats_ids                = array_diff( $other_cats_ids, $terms_children_ids );
				$other_cats_ids                = array_diff( $other_cats_ids, $terms_childrens_children_ids );
				$belongs_to_different_cats_too = false;
				if ( count( $other_cats_ids ) > 0 ) {
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

		$categories_that_should_be_migrated_as_tags = array(
			'Drogas'                              => 58,
			'Posconflicto'                        => 59,
			'Superpoderosos'                      => 60,
			'Plebiscito'                          => 61,
			'Renegociación'                       => 62,
			'Alejandro Ordoñez'                   => 63,
			'Álvaro Uribe'                        => 64,
			'Camelladores'                        => 67,
			'Ciudadanos de a pie'                 => 69,
			'Conflicto Armado'                    => 70,
			'Congreso'                            => 71,
			'Coronavirus'                         => 72,
			'Corrupción'                          => 73,
			'Desarrollo Rural'                    => 75,
			'Detector al chat de la familia'      => 76,
			'Detector en Facebook'                => 78,
			'Dónde está la Plata'                 => 79,
			'Economía'                            => 80,
			'Educación'                           => 81,
			'El factor Vargas Lleras'             => 83,
			'Elecciones'                          => 84,
			'Elecciones 2019'                     => 85,
			'Encuestas'                           => 86,
			'Étnico'                              => 87,
			'Fuerza pública'                      => 88,
			'Gobierno de Claudia López'           => 89,
			'Gobierno de Peñalosa'                => 90,
			'Gobierno de Santos'                  => 91,
			'Gobierno de Uribe'                   => 92,
			'Gobierno Duque'                      => 93,
			'Gobiernos anteriores'                => 94,
			'Grandes casos judiciales'            => 95,
			'Gustavo Petro'                       => 96,
			'Justicia'                            => 97,
			'Justicia transicional'               => 98,
			'La elección del fiscal'              => 99,
			'La Silla Vacía'                      => 100,
			'Las ías'                             => 101,
			'Las vacas flacas'                    => 102,
			'Medio Ambiente'                      => 103,
			'Medios'                              => 104,
			'Minería'                             => 105,
			'Movimientos Sociales'                => 106,
			'Mujeres'                             => 107,
			'Odebrecht'                           => 108,
			'Otras Regiones'                      => 109,
			'Otros países'                        => 110,
			'Otros personajes'                    => 111,
			'Otros temas'                         => 112,
			'Polarización'                        => 114,
			'Política menuda'                     => 115,
			'Presidenciales 2018'                 => 116,
			'Proceso con el ELN'                  => 117,
			'Proceso con las FARC'                => 118,
			'Salud'                               => 120,
			'Seguridad'                           => 122,
			'Testigos falsos y Uribe'             => 123,
			'Urbanismo'                           => 124,
			'Venezuela'                           => 125,
			'Víctimas'                            => 126,
			'Conversaciones'                      => 129,
			'Cubrimiento Especial'                => 130,
			'Hágame el cruce'                     => 131,
			'Coronavirus + 177	Coronavirus'      => 172,
			'Coronavirus + 177 Coronavirus'       => 172,
			'Proceso de paz'                      => 173,
			'Jep'                                 => 174,
			'Arte'                                => 386,
			'Posconflicto + 59 Posconflicto'      => 389,
			'Elecciones 2023'                     => 429,
			'Sala de Redacción Ciudadana'         => 378,
			'Gobierno'                            => 176,
			'Crisis'                              => 178,
			'Elecciones 2022'                     => 360,
			'La Dimensión Desconocida'            => 388,
			'Econimia'                            => 48,
			'Entrevista'                          => 381,
			'Redes Silla llena'                   => 175,
			'Papers'                              => 326,
			'Libros'                              => 327,
			'Publicaciones seriadas'              => 328,
			'Estudios patrocinados'               => 329,
			'Política + 46	Politica'             => 392,
			'Política + 46 Politica'              => 392,
			'Medio Ambiente + 103 Medio ambiente' => 399,
			'Género'                              => 400,
			'Religión'                            => 401,
			'Corrupción + 73 Corrupcion'          => 402,
			'Cultura + 47	Cultura'              => 403,
			'Cultura + 47 Cultura'                => 403,
			'Educación'                           => 404,
			'Economía'                            => 405,
			'Migraciones'                         => 406,
			'Relaciones Internacionales'          => 407,
			'Ciencia'                             => 408,
			'Política social'                     => 409,
			'Elecciones'                          => 410,
			'Posconflicto'                        => 411,
			'Acuerdo de Paz'                      => 412,
			'Seguridad'                           => 413,
			'Desarrollo rural'                    => 414,
			'Salud'                               => 415,
			'Coronavirus'                         => 416,
			'Congreso'                            => 417,
			'Gobierno'                            => 418,
			'Justicia'                            => 419,
			'Movimientos sociales'                => 420,
			'Sector privado'                      => 421,
			'Medios'                              => 422,
			'Tecnología e innovación'             => 423,
			'Ciudades'                            => 424,
			'Comunidades étnicas'                 => 425,
		);
		$categories_that_should_not_be_migrated     = array(
			'Store'                           => 17,
			'Module'                          => 18,
			'suscripciones pasadas'           => 40,
			'Beneficios'                      => 41,
			'Items1'                          => 42,
			'Items2'                          => 43,
			'Destacado'                       => 49,
			'Destacados silla vacia'          => 369,
			'Destacados silla llena'          => 371,
			'Destacado home'                  => 374,
			'Destacado historia'              => 375,
			'Destacado Episodio Landing'      => 376,
			'Recomendados Episodio Landing'   => 377,
			'Entrevistado'                    => 382,
			'Texto Citado'                    => 383,
			'Fin de semana'                   => 384,
			'Eventos Article'                 => 144,
			'Polemico'                        => 145,
			'Boletines'                       => 379,
			'Mailing'                         => 380,
			'Opinión'                         => 181,
			'Entidades'                       => 143,
			'Publicaciones'                   => 142,
			'Relacion Quien es Quien'         => 50,
			'Rivalidad'                       => 51,
			'Laboral'                         => 52,
			'Quien es quien'                  => 44,
			'tematicas'                       => 45,
			'Temas'                           => 53,
			'Escala Detector'                 => 127,
			'Producto'                        => 128,
			'Sí o no'                         => 133,
			'Columnas de la silla'            => 148,
			'Podcast'                         => 146,
			'Modulo Videos'                   => 147,
			'Temas silla llena'               => 171,
			'Delitos'                         => 180,
			'Temas Experto'                   => 201,
			'Tipo de Publicación Patrocinada' => 325,
			'Lecciones'                       => 362,
			'Especiales'                      => 363,
			'categoryFileds'                  => 426,
			'SillaCursos'                     => 200,
			'cursos asincronicos'             => 373,
			'Periodismo'                      => 364,
			'cursos productos'                => 356,
			'Escritura'                       => 365,
			'Diseño'                          => 366,
			'Audiovisual'                     => 367,
			'Curso de Desinformación'         => 430,
		);
	}

	/**
	 * @throws Exception
	 */
	public function migrate_articles( $args, $assoc_args ) {
		global $wpdb;

		$incremental_import = isset( $assoc_args['incremental-import'] ) ? true : false;

		$media_location = $assoc_args['media-location'];

		$skip_base64_html_ids = array();

		// Top level category which posts in this JSON are for.
		$category_names = array(
			'Opinión',
			'Podcasts',
			'Quién es quién',
			'Silla Académica',
			'Silla Nacional',
			'Detector de mentiras',
			'En Vivo',
			'Silla Llena',
			'Publicaciones',
			'Red de Expertos',
		);
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
		$authors     = $wpdb->get_results( $authors_sql, OBJECT_K );
		$authors     = array_map( fn( $value ) => (int) $value->ID, $authors );

		/*
		$imported_hashed_ids_sql = "SELECT meta_value, post_id
			FROM wp_postmeta
			WHERE meta_key IN ('hashed_import_id')";
		$imported_hashed_ids = $wpdb->get_results( $imported_hashed_ids_sql, OBJECT_K );
		$imported_hashed_ids = array_map( fn( $value ) => (int) $value->post_id, $imported_hashed_ids );*/
		$original_article_ids = $wpdb->get_results( "SELECT meta_value, post_id FROM wp_postmeta WHERE meta_key = 'newspack_original_article_id'", OBJECT_K );
		$original_article_ids = array_map( fn( $value ) => (int) $value->post_id, $original_article_ids );

		// Count total articles, but don't do it for very large files because of memory consumption -- a rough count is good enough for just approx. progress.
		if ( 'Silla Académica' == $assoc_args['category-name'] ) {
			$total_count = '?';
		} else {
			$total_count = count( json_decode( file_get_contents( $assoc_args['import-json'] ), true ) );
		}
		$i = 0;
		foreach ( $this->json_generator( $assoc_args['import-json'] ) as $article ) {
			++$i;

			WP_CLI::log( sprintf( 'Importing %d/%s', $i, $total_count ) );

			/**
			 * Get post data from JSON.
			 */

			if ( 'Detector de mentiras' == $assoc_args['category-name'] ) {
				$original_article_id = $article['head_id'] ?? 0;
			} else {
				$original_article_id = $article['id'] ?? 0;
			}

			if ( true === $incremental_import && array_key_exists( $original_article_id, $original_article_ids ) ) {
				WP_CLI::line( sprintf( 'Article was imported as Post ID %d, skipping.', $original_article_ids[ $original_article_id ] ) );
				continue;
			}

			/*
			// No longer want this function to handle articles if they've already been imported.
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

			$additional_meta              = array();
			$featured_image_attachment_id = null;

			$post_title      = '';
			$post_excerpt    = '';
			$post_date       = '';
			$post_modified   = '';
			$post_name       = '';
			$article_authors = array();
			$article_tags    = array();
			if ( 'Opinión' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['post_title'] );
				$post_date  = $article['post_date'];
				if ( empty( $article['post_date'] ) || 'none' == strtolower( $article['post_date'] ) ) {
					$post_date = $article['publishedAt'];
				}
				$post_modified = $article['post_modified'] ?? $post_date;
				if ( empty( $article['post_modified'] ) || 'none' == strtolower( $article['post_modified'] ) ) {
					$post_modified = date( 'Y-m-d H:i:s', strtotime( 'now' ) );
				}
				$post_name       = $article['post_name'];
				$article_authors = $article['post_author'];
			} elseif ( 'Podcasts' == $assoc_args['category-name'] ) {
				if ( is_null( $article['audio'] ) ) {
					WP_CLI::warning( sprintf( 'Article ID %d has no audio. Skipping.', $original_article_id ) );
					$skip_base64_html_ids[] = $article['id'];
					continue;
				}
				$post_title = trim( $article['title'] );
				$post_date  = $article['createdAt'];
				if ( empty( $article['createdAt'] ) || 'none' == strtolower( $article['createdAt'] ) ) {
					$post_date = $article['publishedAt'];
				}
				$post_modified = $article['post_modified'] ?? $post_date;
				if ( empty( $article['post_modified'] ) || 'none' == strtolower( $article['post_modified'] ) ) {
					$post_modified = date( 'Y-m-d H:i:s', strtotime( 'now' ) );
				}
				$post_name       = $article['slug'];
				$article_authors = ! is_null( $article['author'] ) ? $article['author'] : array();
			} elseif ( 'Quién es quién' == $assoc_args['category-name'] ) {

				// Stop re-importing Quien es quen posts for now. We need an incremental check first otherwise we'll end up with dupe avatar images.
				// WP_CLI::error( "Re-importing Quien es quen posts will create duplicate featured images. This command is not ready for that yet, make necessary adjustments to it first." );

				$post_title = trim( $article['title'] );
				$post_date  = $article['createdAt'];
				if ( empty( $article['createdAt'] ) || 'none' == strtolower( $article['createdAt'] ) ) {
					$post_date = $article['publishedAt'];
				}
				$post_modified = $article['post_modified'] ?? $post_date;
				if ( empty( $article['post_modified'] ) || 'none' == strtolower( $article['post_modified'] ) ) {
					$post_modified = date( 'Y-m-d H:i:s', strtotime( 'now' ) );
				}
				$post_name             = $article['slug'];
				$article['categories'] = array_map(
					function ( $category ) {
						$category['term_taxonomy_id'] = $category['id'];
						$category['taxonomy']         = 'category';

						return $category;
					},
					$article['categories']
				);
				if ( isset( $article['picture']['name'] ) ) {
					$featured_img_url             = 'https://www.lasillavacia.com/media/' . $article['picture']['name'];
					$featured_image_attachment_id = $this->attachments->import_external_file( $featured_img_url );
					if ( is_wp_error( $featured_image_attachment_id ) || ! $featured_image_attachment_id ) {
						$msg = sprintf( 'ERROR: Article ID %d, error importing featured image URL %s err: %s', $original_article_id, $featured_img_url, is_wp_error( $featured_image_attachment_id ) ? $featured_image_attachment_id->get_error_message() : '/' );
						$this->file_logger( $msg );
					} else {
						$msg = sprintf( 'Article ID %d, imported featured image attachment ID %d', $original_article_id, $featured_image_attachment_id );
						$this->file_logger( $msg );
					}
				}
				if ( isset( $article['picture'] ) ) {
					$additional_meta['newspack_picture'] = $article['picture'];
				}
			} elseif ( 'Silla Académica' == $assoc_args['category-name'] ) {
				$post_title = trim( $article['post_title'] );
				$post_date  = $article['post_date'];
				if ( empty( $article['post_date'] ) || 'none' == strtolower( $article['post_date'] ) ) {
					$post_date = $article['publishedAt'];
				}
				$post_modified = $article['post_modified'] ?? $post_date;
				if ( empty( $article['post_modified'] ) || 'none' == strtolower( $article['post_modified'] ) ) {
					$post_modified = date( 'Y-m-d H:i:s', strtotime( 'now' ) );
				}
				$post_name       = $article['post_name'];
				$article_authors = ! is_null( $article['post_author'] ) ? $article['post_author'] : array();
				if ( isset( $article['image'] ) ) {
					$additional_meta['newspack_image'] = $article['image'];
				}
				if ( isset( $article['keywords'] ) ) {
					$additional_meta['newspack_keywords'] = $article['keywords'];
				}
				if ( isset( $article['url'] ) ) {
					$additional_meta['newspack_url'] = $article['url'];
				}
			} elseif ( in_array( $assoc_args['category-name'], array( 'Silla Nacional', 'Silla Llena', 'Red de Expertos' ) ) ) {
				$post_title   = trim( $article['post_title'] );
				$post_excerpt = $article['post_excerpt'] ?? '';
				$post_date    = $article['post_date'];
				if ( empty( $article['post_date'] ) || 'none' == strtolower( $article['post_date'] ) ) {
					$post_date = $article['publishedAt'];
				}
				$post_modified = $article['post_modified'] ?? $post_date;
				if ( empty( $article['post_modified'] ) || 'none' == strtolower( $article['post_modified'] ) ) {
					$post_modified = date( 'Y-m-d H:i:s', strtotime( 'now' ) );
				}
				$post_name       = $article['post_name'];
				$article_authors = ! is_null( $article['post_author'] ) ? $article['post_author'] : array();

				$current_term_taxonomies = $this->get_current_term_taxonomies();
				if ( isset( $article['tags'] ) && ! is_null( $article['tags'] ) && ! empty( $article['tags'] ) ) {
					$article_tags = $this->handle_article_terms( $article['tags'], $current_term_taxonomies );
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
				$post_title   = trim( $article['title'] );
				$post_excerpt = $article['description'] ?? '';
				$post_name    = $article['slug'];
				$post_date    = $article['createdAt'];
				if ( empty( $article['createdAt'] ) || 'none' == strtolower( $article['createdAt'] ) ) {
					$post_date = $article['publishedAt'];
				}
				$post_modified = $article['post_modified'] ?? $post_date;
				if ( empty( $article['post_modified'] ) || 'none' == strtolower( $article['post_modified'] ) ) {
					$post_modified = date( 'Y-m-d H:i:s', strtotime( 'now' ) );
				}
				$article_authors = ! is_null( $article['authors'] ) ? $article['authors'] : array();
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
				$post_name  = $article['slug'];

				$date_part = date( 'Y-m-d' );
				// Date may be faulty.
				if ( ! is_null( $article['StartDate'] ) && $this->is_date_valid( $article['StartDate'] ) ) {
					$date_part = $article['StartDate'];
				}

				// Very faulty time, contains many formats and some pure errors.
				$time_part = ( $article['time'] != 'None' ? $article['time'] : '00:00:00' );
				$time_part = str_replace( ' pm', '', $time_part );
				$time_part = str_replace( ' am', '', $time_part );
				$time_part = str_replace( ' ', '', $time_part );
				if ( 1 != preg_match( '|^\d{1,2}:\d{1,2}$|', $time_part ) ) {
					$time_part = '00:00:00';
				}

				$post_date = $date_part . ' ' . $time_part;

				if ( isset( $article['canonical'] ) ) {
					$additional_meta['newspack_canonical'] = $article['canonical'];
				}

				$article['categories'] = array_map(
					function ( $category ) {

						$category['term_taxonomy_id'] = $category['id'];
						$category['taxonomy']         = 'category';

						return $category;
					},
					$article['categories']
				);
			}

			// Using hash instead of just using original Id in case Id is 0. This would make it seem like the article is a duplicate.
			$original_article_slug = sanitize_title( $post_name ) ?? '';
			// $hashed_import_id = md5( $post_title . $original_article_slug );
			$this->file_logger( "Original Article ID: $original_article_id | Original Article Title: $post_title | Original Article Slug: $original_article_slug" );

			// Skip importing post if $incremental_import is true and post already exists.
			/*
			if ( true === $incremental_import ) {
				$existing_postid_by_original_article_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_original_article_id' and meta_value = %s", $original_article_id ) );
				//$existing_postid_by_hashed_import_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_hashed_import_id' and meta_value = %s", $hashed_import_id ) );
				if ( $existing_postid_by_original_article_id && $existing_postid_by_hashed_import_id && $existing_postid_by_original_article_id == $existing_postid_by_hashed_import_id ) {
					WP_CLI::line( sprintf( "Article was imported as Post ID %d, skipping.", $existing_postid_by_original_article_id ) );
					continue;
				}
			}*/

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
				$html                   = '__';
				continue;
			}

			// This is a one and single article out of all others for which wp_insert_post() fails to insert post_content because it contains BASE64 encoded images.
			// This post has been manually imported and should be skipped because data is not supported and already in the database.
			$failed_inserts_post_names = array( 'los-10-imperdibles-del-ano-para-procrastinar-en-vacaciones' );
			if ( in_array( $post_name, $failed_inserts_post_names ) ) {
				WP_CLI::warning( sprintf( 'Post name %s contains invalid data which makes wp_insert_post() crash and will be skipped.', $post_name ) );
				continue;
			}

			if ( 'Podcasts' == $assoc_args['category-name'] ) {
				$audio_result = $this->handle_podcast_audio( $article['audio'], $media_location );

				if ( ! is_null( $audio_result ) ) {
					$html = $audio_result . $html;
				}
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

			$meta_input = array(
				'newspack_original_article_id'             => $original_article_id,
				// 'canonical_url' => $article['CanonicalUrl'],
				// 'newspack_hashed_import_id' => $hashed_import_id,
					'newspack_original_article_categories' => $article['categories'],
				'newspack_original_post_author'            => $article_authors,
			);
			if ( ! empty( $additional_meta ) ) {
				$meta_input = array_merge( $meta_input, $additional_meta );
			}
			$article_data = array(
				'post_author'           => 0,
				'post_date'             => $createdOn,
				'post_date_gmt'         => $createdOnGmt,
				'post_content'          => $html,
				'post_title'            => $post_title,
				'post_excerpt'          => $post_excerpt,
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => $post_name,
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => $modifiedOn,
				'post_modified_gmt'     => $modifiedOnGmt,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'menu_order'            => 0,
				'post_type'             => 'post',
				'post_mime_type'        => '',
				'comment_count'         => 0,
				'meta_input'            => $meta_input,
			);

			/*
			if ( 1 === count( $article_authors ) ) {
				$article_data['post_author'] = $authors[ $article_authors[0] ] ?? 0;
			}*/

			if ( isset( $article['customfields'] ) ) {
				foreach ( $article['customfields'] as $customfield ) {
					$article_data['meta_input'][ $customfield['name'] ] = $customfield['value'];
				}
			}

			// $new_post_id = $imported_hashed_ids[ $hashed_import_id ] ?? null;

			/*
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
			}*/

			$post_id = wp_insert_post( $article_data );

			if ( ! is_null( $featured_image_attachment_id ) ) {
				set_post_thumbnail( $post_id, $featured_image_attachment_id );
			} elseif ( isset( $article['image'] ) ) {
					$this->handle_featured_image( $article['image'], $article['id'], $post_id, $media_location );
			}

			if ( ! empty( $article_authors ) ) {
				try {
					$migration_post_authors = new MigrationPostAuthors( $article_authors );
					$assigned_to_post       = $migration_post_authors->assign_to_post( $post_id );

					if ( $assigned_to_post ) {
						foreach ( $migration_post_authors->get_authors() as $migration_author ) {
							echo WP_CLI::colorize( "%WAssigned {$migration_author->get_output_description()} to post ID {$post_id}%n\n" );
						}
					}
				} catch ( Exception $e ) {
					$message = strtoupper( $e->getMessage() );
					echo WP_CLI::colorize( "%Y$message%n\n" );
				}
				/**
				 * This existing code below doesn't work -- it's not finding the $term_taxonomy_ids.
				 * Plus we have a one-liner for this 👆.
				 */
				// if ( ! empty( $guest_author_ids ) ) {
				// $term_taxonomy_ids_query = $wpdb->prepare(
				// "SELECT
				// tt.term_taxonomy_id
				// FROM $wpdb->term_taxonomy tt
				// INNER JOIN $wpdb->term_relationships tr
				// ON tt.term_taxonomy_id = tr.term_taxonomy_id
				// WHERE tt.taxonomy = 'author'
				// AND tr.object_id IN (" . implode( ',', $guest_author_ids ) . ')'
				// );
				// $term_taxonomy_ids = $wpdb->get_col( $term_taxonomy_ids_query );
				//
				// foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
				// $wpdb->insert(
				// $wpdb->term_relationships,
				// [
				// 'object_id'        => $post_id,
				// 'term_taxonomy_id' => $term_taxonomy_id,
				// ]
				// );
				// }
				// }
			}

			// Set categories.
			/*
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
			}*/
			if ( ! empty( $article['categories'] ) ) {
				$category_ids = $this->handle_article_terms( $article['categories'], $this->get_current_term_taxonomies() );
				wp_set_post_terms( $post_id, $category_ids, 'category' );
			}

			// Set tags.
			if ( ! empty( $article_tags ) ) {
				wp_set_post_terms( $post_id, $article_tags );
			}

			// It's not recommended to modify the guid
			// wp_update_post(
			// [
			// 'ID' => $post_id,
			// 'guid' => "http://lasillavacia-staging.newspackstaging.com/?page_id={$post_id}"
			// ]
			// );

			$this->file_logger( "Article Imported: $post_id" );
		}

		if ( ! empty( $skip_base64_html_ids ) ) {
			WP_CLI::error( sprintf( 'Done with errors -- skipped importing post_content (because HTML contained B64 which failed during post creation) for original IDs -- these post_contents should be inserted manually : %s', implode( ',', $skip_base64_html_ids ) ) );
		}
	}

	/**
	 * @throws Exception
	 */
	public function cmd_update_migrated_articles( $args, $assoc_args ) {
		global $wpdb;

		$start_at_id = $assoc_args['start-at-id'] ?? null;
		$end_at_id   = $assoc_args['end-at-id'] ?? null;
		$skip        = ! is_null( $start_at_id );
		$end         = ! is_null( $end_at_id );

		$media_location = $assoc_args['media-location'];

		$update_published_date          = $assoc_args['published-date'] ?? false;
		$update_post_authors            = $assoc_args['post-authors'] ?? false;
		$update_keywords                = $assoc_args['keywords'] ?? false;
		$update_featured_image          = $assoc_args['featured-image'] ?? false;
		$update_video_as_featured_image = $assoc_args['video-featured-image'] ?? false;
		$update_taxonomy                = $assoc_args['taxonomy'] ?? false;
		$remove_existing_featured_image = $assoc_args['remove-existing-featured-image'] ?? false;

		$original_article_ids_query = "SELECT meta_value as original_article_id, post_id as new_article_id 
                FROM $wpdb->postmeta 
                WHERE meta_key = 'newspack_original_article_id'";

		if ( $skip ) {
			$original_article_ids_query .= " AND meta_value >= $start_at_id";
		}

		if ( $end ) {
			$original_article_ids_query .= " AND meta_value <= $end_at_id";
		}

		$original_article_id_to_new_article_id_map = $wpdb->get_results(
			$original_article_ids_query,
			OBJECT_K
		);
		$original_article_id_to_new_article_id_map = array_map(
			function ( $item ) {
				return intval( $item->new_article_id );
			},
			$original_article_id_to_new_article_id_map
		);

		$tags_and_category_taxonomy_ids = $this->get_current_term_taxonomies();

		$datetime_format = 'Y-m-d H:i:s';

		foreach ( $this->json_generator( $assoc_args['import-json'] ) as $article ) {
			if ( $skip && $start_at_id != $article['id'] ) {
				continue;
			} else {
				$skip = false;
			}

			echo "Handling OAID: {$article['id']}";

			if ( ! array_key_exists( $article['id'], $original_article_id_to_new_article_id_map ) ) {
				echo WP_CLI::colorize( " %YCORRESPONDING POST ID NOT FOUND. Skipping...%n\n\n" );
				continue;
			}

			$post_id = $original_article_id_to_new_article_id_map[ $article['id'] ];
			echo " | WPAID: $post_id\n";

			$post_data = array();
			$post_meta = array();

			/*
			 * PUBLISHED DATE UPDATE SECTION
			 * * */
			if ( $update_published_date ) {
				$post_date     = date( $datetime_format, time() );
				$post_modified = '';
				if ( isset( $article['post_date'] ) ) {
					$post_date = $article['post_date'];

					if ( empty( $article['post_date'] ) || 'none' == strtolower( $article['post_date'] ) ) {
						$post_date = $article['publishedAt'];
					}
				} elseif ( isset( $article['createdAt'] ) ) {
					$post_date = $article['createdAt'];
				} else {
					$post_date = $article['publishedAt'];
				}

				if ( isset( $article['publishedAt'] ) ) {
					$post_modified = $article['publishedAt'];
				}

				if ( empty( $post_modified ) && isset( $article['post_modified'] ) ) {
					$post_modified = $article['post_modified'];
				}

				$createdOnDT = new DateTime( $post_date, new DateTimeZone( 'America/Bogota' ) );
				$createdOn   = $createdOnDT->format( $datetime_format );
				$createdOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
				$createdOnGmt = $createdOnDT->format( $datetime_format );
				if ( empty( $post_modified ) ) {
					$post_modified = $post_date;
				}
				$modifiedOnDT = new DateTime( $post_modified, new DateTimeZone( 'America/Bogota' ) );
				$modifiedOn   = $modifiedOnDT->format( $datetime_format );
				$modifiedOnDT->setTimezone( new DateTimeZone( 'GMT' ) );
				$modifiedOnGmt                  = $modifiedOnDT->format( $datetime_format );
				$post_data['post_date']         = $createdOn;
				$post_data['post_date_gmt']     = $createdOnGmt;
				$post_data['post_modified']     = $modifiedOn;
				$post_data['post_modified_gmt'] = $modifiedOnGmt;
			}
			/*
			 * *
			 * PUBLISHED DATE UPDATE SECTION
			 */

			/*
			 * POST AUTHOR UPDATE SECTION
			 * * */
			if ( $update_post_authors ) {
				if ( ! empty( $article['post_author'] ) ) {
					try {
						$migration_post_authors = new MigrationPostAuthors( $article['post_author'] );
						$assigned_to_post       = $migration_post_authors->assign_to_post( $post_id );

						if ( $assigned_to_post ) {
							foreach ( $migration_post_authors->get_authors() as $migration_author ) {
								echo WP_CLI::colorize( "%WAssigned {$migration_author->get_output_description()} to post ID {$post_id}%n\n" );
							}
						}
					} catch ( Exception $e ) {
						$message = strtoupper( $e->getMessage() );
						echo WP_CLI::colorize( "%Y$message%n\n" );
					}
				}
			}
			/*
			 * *
			 * POST AUTHOR UPDATE SECTION
			 */

			/*
			 * IMPORT KEYWORDS SECTION
			 * * */
			if ( $update_keywords ) {
				if ( ! empty( $article['keywords'] ) ) {
					$first_keyword                     = array_shift( $article['keywords'] );
					$post_meta['_yoast_wpseo_focuskw'] = $first_keyword;

					$post_meta['_yoast_wpseo_focuskeywords'] = array();
					foreach ( $article['keywords'] as $keyword ) {
						$post_meta['_yoast_wpseo_focuskeywords'][] = array(
							'keyword' => $keyword,
							'score'   => 50,
						);
					}
					$post_meta['_yoast_wpseo_focuskeywords'] = json_encode( $post_meta['_yoast_wpseo_focuskeywords'] );
				}
			}
			/*
			 * *
			 * IMPORT KEYWORDS SECTION
			 */

			/*
			 * FEATURED IMAGE SECTION
			 * * */
			if ( $update_featured_image ) {
				$has_featured_image = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND post_id = %d",
						$post_id
					)
				);
				$has_featured_image = ! is_null( $has_featured_image );

				if ( $has_featured_image && $remove_existing_featured_image ) {
					$wpdb->delete(
						$wpdb->postmeta,
						array(
							'post_id'  => $post_id,
							'meta_key' => '_thumbnail_id',
						)
					);

					$has_featured_image = false;
				}

				if ( ! $has_featured_image ) {
					if ( ! empty( $article['image'] ) ) {
						$this->handle_featured_image(
							$article['image'],
							intval( $article['id'] ),
							$post_id,
							$media_location
						);
					}
				}
			}
			/*
			 * *
			 * FEATURED IMAGE SECTION
			 * /

			/*
			 * VIDEO AS FEATURED IMAGE SECTION
			 * * */

			if ( $update_video_as_featured_image ) {
				$html = $article['post_html'];
				$html = str_replace( '//www.lasillavacia.com', '//lasillavacia-staging.newspackstaging.com', $html );
				$html = str_replace( '//lasillavacia.com', '//lasillavacia-staging.newspackstaging.com', $html );
				if ( ! is_null( $article['video'] ) ) {
					$src  = $article['video']['name'];
					$html = '<iframe src="' . $src . '" style="width:100%;height:500px;overflow:auto;">' . $src . '</iframe>' . $html;

					$featured_image_update = $wpdb->update(
						$wpdb->postmeta,
						array(
							'meta_key'   => 'newspack_featured_image_position',
							'meta_value' => 'hidden',
						),
						array(
							'post_id'  => $post_id,
							'meta_key' => 'newspack_featured_image_position',
						)
					);

					echo WP_CLI::colorize( "%wFeatured image update: $featured_image_update%n\n" );
				}
				$post_data['post_content'] = $html;
			}
			/*
			 * *
			 * VIDEO AS FEATURED IMAGE SECTION
			 */

			/*
			 * TAXONOMY SECTION
			 * * */
			if ( $update_taxonomy ) {
				$tag_term_ids = $this->handle_article_terms( $article['tags'], $tags_and_category_taxonomy_ids );
				if ( ! empty( $tag_term_ids ) ) {
					$result = wp_set_post_terms( $post_id, $tag_term_ids );
				}
				$category_term_ids = $this->handle_article_terms( $article['categories'], $tags_and_category_taxonomy_ids );
				if ( ! empty( $category_term_ids ) ) {
					$result = wp_set_post_terms( $post_id, $category_term_ids, 'category' );
				}
				if ( ! empty( $article['categories'] ) ) {
					$first_category                             = array_shift( $article['categories'] );
					$post_meta['_yoast_wpseo_primary_category'] = $first_category['term_taxonomy_id'];
				}
			}
			/*
			 * *
			 * TAXONOMY SECTION
			 */

			if ( ! empty( $post_data ) ) {
				$execution = $wpdb->update(
					$wpdb->posts,
					$post_data,
					array(
						'ID' => $post_id,
					)
				);

				if ( $execution ) {
					echo WP_CLI::colorize( "%GPost updated: $post_id%n\n\n" );
				} else {
					echo WP_CLI::colorize( "%RPost update failed: $post_id%n\n\n" );
				}
			} else {
				echo WP_CLI::colorize( "%yNo post data to update%n\n\n" );
			}

			if ( ! empty( $post_meta ) ) {
				foreach ( $post_meta as $meta_key => $meta_value ) {
					update_post_meta( $post_id, $meta_key, $meta_value );
				}
			}

			if ( $end && $end_at_id == $article['id'] ) {
				break;
			}
		}

		wp_cache_flush();
	}

	public function cmd_force_update_specific_articles( $args, $assoc_args ) {
		$post_ids             = $assoc_args['post-ids'] ?? array();
		$original_article_ids = $assoc_args['original-article-ids'] ?? array();

		if ( is_string( $post_ids ) ) {
			$post_ids = explode( ',', $post_ids );
		}

		if ( is_string( $original_article_ids ) ) {
			$original_article_ids = explode( ',', $original_article_ids );
		}

		global $wpdb;

		if ( ! empty( $post_ids ) ) {
			$post_id_placeholders       = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$original_article_ids_in_db = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_value as original_article_id
					FROM $wpdb->postmeta
					WHERE meta_key = 'newspack_original_article_id'
					  AND post_id IN ( $post_id_placeholders )",
					$post_ids
				)
			);
			$original_article_ids_in_db = array_map( fn( $item ) => intval( $item->original_article_id ), $original_article_ids_in_db );
			$original_article_ids       = array_filter( array_unique( array_merge( $original_article_ids, $original_article_ids_in_db ) ) );
		}

		foreach ( $original_article_ids as $original_article_id ) {
			$assoc_args['start-at-id'] = $original_article_id;
			$assoc_args['end-at-id']   = $original_article_id;

			$this->cmd_update_migrated_articles( $args, $assoc_args );
		}
	}

	public function cmd_set_featured_images_for_detector_de_mentiras( $args, $assoc_args ) {
		global $wpdb;

		$post_meta_posts_to_check = $wpdb->get_results(
			"SELECT * FROM $wpdb->postmeta 
         		WHERE meta_key = 'newspack_picture' 
         		  AND post_id IN (
					SELECT object_id 
					FROM wp_term_relationships 
					WHERE term_taxonomy_id IN (4984,5429,5499,5633,5669) 
					)"
		);

		foreach ( $post_meta_posts_to_check as $postmeta ) {
			$has_featured_image = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND post_id = %d",
					$postmeta->post_id
				)
			);
			$has_featured_image = ! is_null( $has_featured_image );

			if ( ! $has_featured_image ) {
				$picture                 = maybe_unserialize( $postmeta->meta_value );
				$picture['FriendlyName'] = $picture['name'];
				$this->handle_featured_image( $picture, 0, $postmeta->post_id, '/tmp/media_content/bak_Media' );
			}
		}
	}

	/**
	 * @param array $args
	 * @param array $assoc_args
	 * @return void
	 */
	public function cmd_update_user_metadata( $args, $assoc_args ) {
		global $wpdb;

		foreach ( $this->json_generator( $assoc_args['import-json'] ) as $import_user ) {
			unset( $import_user['bio'] );

			$login = WP_CLI::colorize( '%RNO LOGIN%n' );
			if ( isset( $import_user['user_login'] ) ) {
				$login = WP_CLI::colorize( "%Y{$import_user['user_login']}%n" );
			} elseif ( isset( $import_user['slug'] ) ) {
				$login = WP_CLI::colorize( "%Y{$import_user['slug']}%n" );
			}

			$email = ! empty( $import_user['user_email'] ) ? WP_CLI::colorize( "%C%U{$import_user['user_email']}%n" ) : WP_CLI::colorize( '%RNO EMAIL%n' );
			$role  = $import_user['xpr_rol'] ?? $import_user['role'] ?? WP_CLI::colorize( '%wNO ROLE%n' );

			echo "{$import_user['id']}\t$login\t$email\t$role\n";
			$identifier = '';
			if ( ! empty( $import_user['user_email'] ) ) {
				$identifier = $import_user['user_email'];
			} elseif ( ! empty( $import_user['user_login'] ) ) {
				$identifier = $import_user['user_login'];
			} elseif ( ! empty( $import_user['slug'] ) ) {
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
				$wpdb->prepare(
					"SELECT meta_value, umeta_id FROM $wpdb->usermeta 
                            WHERE meta_key = 'original_user_id' 
                              AND user_id = %d 
                              AND meta_value = %d",
					$db_user->ID,
					$import_user['id']
				)
			);

			if ( ! is_null( $original_user_id ) ) {
				echo WP_CLI::colorize( "%G User has original_user_id metadata: $original_user_id->meta_value.%n\n" );
				continue;
			}

			$insertion = $wpdb->insert(
				$wpdb->usermeta,
				array(
					'user_id'    => $db_user->ID,
					'meta_key'   => 'original_user_id',
					'meta_value' => $import_user['id'],
				)
			);

			if ( ! $insertion ) {
				echo WP_CLI::colorize( "%R Error inserting original_user_id metadata. %n\n" );
			} else {
				echo WP_CLI::colorize( "%G Success inserting original_user_id metadata. %n\n" );
			}
		}
	}

	private function get_current_term_taxonomies() {
		global $wpdb;

		$tags_and_category_taxonomy_ids = $wpdb->get_results(
			"SELECT term_taxonomy_id, term_id FROM $wpdb->term_taxonomy WHERE taxonomy IN ( 'post_tag', 'category' )",
			OBJECT_K
		);

		return array_map(
			function ( $item ) {
				return intval( $item->term_id );
			},
			$tags_and_category_taxonomy_ids
		);
	}

	private function handle_article_terms( array $terms, array $current_term_taxonomy_ids ) {
		$term_ids = array();

		foreach ( $terms as $term ) {
			$taxonomy         = $term['taxonomy'];
			$term_taxonomy_id = $term['term_taxonomy_id'];

			if ( ! array_key_exists( $term_taxonomy_id, $current_term_taxonomy_ids ) ) {
				echo WP_CLI::colorize( "%m$taxonomy term_taxonomy_id: $term_taxonomy_id does not exist in DB%n\n" );
				continue;
			}

			$term_ids[] = $current_term_taxonomy_ids[ $term_taxonomy_id ];
		}

		return $term_ids;
	}

	public function handle_featured_image( $image, int $original_article_id, int $post_id, string $media_location ) {
		$filename       = $image['FriendlyName'] ?? $image['name'];
		$full_file_path = $media_location . '/' . $filename;

		$replace_accents = array(
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
		);

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
			if ( $post_id !== 0 ) {
				update_post_meta( $post_id, 'newspack_featured_image_position', '' );
			}
			$featured_image_attachment_id = $this->attachments->import_external_file(
				$full_file_path,
				$image['FriendlyName'] ?? $image['name'],
				$image['caption'] ?? '',
				null,
				null,
				$post_id
			);

			if ( is_wp_error( $featured_image_attachment_id ) || ! $featured_image_attachment_id ) {
				$msg = sprintf(
					"ERROR: (OAID) %d, WPAID %d, error importing featured image %s err: %s\n",
					$original_article_id,
					$post_id,
					$full_file_path,
					is_wp_error( $featured_image_attachment_id )
						? $featured_image_attachment_id->get_error_message() : '/'
				);
				echo $msg;
			} else {
				if ( $post_id !== 0 ) {
					update_post_meta( $post_id, '_thumbnail_id', $featured_image_attachment_id );
				}
				$msg = sprintf(
					"(OAID) %d, WPAID %d, imported featured image attachment ID %d\n",
					$original_article_id,
					$post_id,
					$featured_image_attachment_id
				);
				echo WP_CLI::colorize( "%b$msg%n" );
				return $featured_image_attachment_id;
			}
		} else {
			echo WP_CLI::colorize( "%mFile doesn't exist: $full_file_path%n\n" );
		}

		return 0;
	}

	/**
	 * @param int $original_user_id
	 *
	 * @return object|null
	 */
	public function get_guest_author_by_original_user_id( int $original_user_id ) {
		global $wpdb;

		$guest_author_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_user_id' AND meta_value = %d",
				$original_user_id
			)
		);

		if ( ! $guest_author_id ) {
			return null;
		}

		return $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id ) ?: null;
	}

	public function get_wp_user_by_original_user_id( int $original_user_id ) {
		global $wpdb;

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'original_user_id' AND meta_value = %d",
				$original_user_id
			)
		);

		if ( ! $user_id ) {
			return null;
		}

		return get_user_by( 'id', $user_id );
	}

	public function get_post_id_from_original_article_id( int $original_article_id ) {
		global $wpdb;

		// Sorry there are so many of these!
		$original_article_meta_keys = array(
			'original_post_id',
			'original_article_id',
			'newspack_original_article_id',
		);
		$imploded_placeholders      = implode( ',', array_fill( 0, count( $original_article_meta_keys ), '%s' ) );

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id 
				FROM $wpdb->postmeta 
				WHERE meta_value = %d 
				  AND meta_key IN ( $imploded_placeholders ) 
				  LIMIT 1",
				$original_article_id,
				...$original_article_meta_keys
			)
		);
	}

	public function get_existing_guest_author_which_prevents_creation( int $user_id ) {
		$user = get_user_by( 'id', $user_id );

		if ( ! empty( $user->display_name ) && $user->display_name != $user->user_login ) {
			$user_login = sanitize_title( $user->display_name );
		} elseif ( ! empty( $user->first_name ) && ! empty( $user->last_name ) ) {
			$user_login = sanitize_title( $user->first_name . ' ' . $user->last_name );
		}

		WP_CLI::log( "User login to use to create new GA: $user_login" );

		return $this->coauthorsplus_logic->coauthors_plus->get_coauthor_by( 'user_login', $user_login, true );
	}

	public function handle_publicaciones( array $publicaciones, $guest_author ) {
		foreach ( $publicaciones as $publicacion ) {
			$post_exists = $this->get_post_id_from_original_article_id( $publicacion->id );
			$post_exists = null !== $post_exists;

			if ( $post_exists ) {
				$this->coauthorsplus_logic->coauthors_plus->add_coauthors(
					$post_exists,
					array( $guest_author->ID ),
					true
				);
			}
		}
	}

	public function link_wp_users_to_guest_authors() {
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
			WP_CLI::log( 'Guest Authors with multiple accounts found. Attempting to remediate...' );
			foreach ( $guest_authors_with_multiple_accounts as $guest_author_row ) {
				$guest_author_ids = explode( ',', $guest_author_row->post_ids );
				$number_of_ids    = count( $guest_author_ids );
				echo WP_CLI::colorize( "%y$guest_author_row->email%n %wwith $number_of_ids GA IDs found%n\n" );
				$first_guest_author_id = array_shift( $guest_author_ids );
				echo WP_CLI::colorize( "%wKeeping $first_guest_author_id%n\n" );

				$first_guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $first_guest_author_id );
				/*
				$original_user_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'original_user_id' AND post_id = %d",
						$first_guest_author_id
					)
				);*/

				$facebook_url = get_post_meta( $first_guest_author_id, 'facebook_url', true );
				$linkedin_url = get_post_meta( $first_guest_author_id, 'linkedin_url', true );
				foreach ( $guest_author_ids as $guest_author_id ) {
					$other_original_user_ids           = $wpdb->get_col(
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
							array(
								'post_id'    => $first_guest_author_id,
								'meta_key'   => 'original_user_id',
								'meta_value' => $other_original_user_id,
							)
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
				WP_CLI::warning( 'No guest author found, skipping...' );
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

	public function driver_update_wp_user_logins_and_nicenames( $args, $assoc_args ) {
		global $wpdb;

		$newspack_admin_id = $wpdb->get_var(
			"SELECT ID FROM $wpdb->users 
          WHERE user_email = 'newspack@a8c.com' 
             OR user_email = 'newspack@automattic.com' 
             OR user_login = 'adminnewspack'"
		);

		$user_id = $assoc_args['wp-user-id'] ?? null;

		if ( ! is_null( $user_id ) && $newspack_admin_id != $user_id ) {
			$users          = $wpdb->get_results(
				"SELECT ID, user_login, user_nicename, user_email, display_name FROM $wpdb->users
				WHERE ID = $user_id"
			);
			$count_of_users = count( $users );
			WP_CLI::log( "$count_of_users users will be processed" );
			$this->cmd_update_wp_user_logins_and_nicenames( $users );
		} else {
			$users          = $wpdb->get_results(
				"SELECT ID, user_login, user_nicename, user_email, display_name FROM $wpdb->users
				WHERE user_login IN (
				    SELECT meta_value FROM wp_postmeta WHERE meta_key = 'cap-linked_account' AND meta_value <> ''
				    ) AND ID NOT IN ( $newspack_admin_id )"
			);
			$count_of_users = count( $users );
			WP_CLI::log( "$count_of_users users will be processed" );
			$this->cmd_update_wp_user_logins_and_nicenames( $users );

			$exclude_user_ids          = array(
				$newspack_admin_id,
				...array_map( fn( $item ) => $item->ID, $users ),
			);
			$imploded_exclude_user_ids = implode( ',', $exclude_user_ids );
			$users                     = $wpdb->get_results(
				"SELECT ID, user_login, user_nicename, user_email, display_name FROM $wpdb->users
				WHERE user_email LIKE '%lasillavacia%' 
				  AND ID NOT IN ( $imploded_exclude_user_ids )"
			);
			$count_of_users            = count( $users );
			WP_CLI::log( "$count_of_users users will be processed" );
			$this->cmd_update_wp_user_logins_and_nicenames( $users );
		}
	}

	private function fix_user_login_and_nicename( WP_User $user ) {
		$this->high_contrast_output( 'wp_user.user_login', $user->user_login );
		$this->high_contrast_output( 'wp_user.user_nicename', $user->user_nicename );

		$new_user_login = $user->user_login;
		if ( is_email( $new_user_login ) ) {
			$new_user_login = substr( $user->user_email, 0, strpos( $user->user_email, '@' ) );
		}

		$new_user_nicename = sanitize_title( $user->display_name );

		$updated_attributes = array();

		if ( $new_user_login !== $user->user_login ) {
			$this->high_contrast_output( 'NEW wp_user.user_login', $new_user_login );
			$updated_attributes['user_login'] = $new_user_login;
		}

		if ( $new_user_nicename !== $user->user_nicename ) {
			$this->high_contrast_output( 'NEW wp_user.user_nicename', $new_user_nicename );
			$updated_attributes['user_nicename'] = $new_user_nicename;
		}

		if ( ! empty( $updated_attributes ) ) {

			global $wpdb;

			$user_updated = $wpdb->update(
				$wpdb->users,
				$updated_attributes,
				array(
					'ID' => $user->ID,
				)
			);

			if ( false !== $user_updated ) {
				echo WP_CLI::colorize( "%GSuccess updating user_login and user_nicename%n\n" );
				$cap_linked_accounts = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT * FROM $wpdb->postmeta WHERE meta_key = 'cap-linked_account' AND meta_value = %s",
						$user->user_login
					)
				);

				if ( ! empty( $cap_linked_accounts ) ) {
					foreach ( $cap_linked_accounts as $cap_linked_account ) {
						echo WP_CLI::colorize( "%wUpdating cap-linked_account%n\n" );
						$this->high_contrast_output( 'Guest Author ID | meta_id | meta_value', $cap_linked_account->post_id . ' | ' . $cap_linked_account->meta_id . ' | ' . $cap_linked_account->meta_value );
						if ( $new_user_login !== $cap_linked_account->meta_value ) {
							$linked_account_updated = $wpdb->update(
								$wpdb->postmeta,
								array(
									'meta_value' => $new_user_login,
								),
								array(
									'meta_id' => $cap_linked_account->meta_id,
								)
							);

							if ( false === $linked_account_updated ) {
								echo WP_CLI::colorize( "%RFailed updating cap-linked_account%n\n" );
							} else {
								echo WP_CLI::colorize( "%GSuccess updating cap-linked_account%n\n" );
							}
						} else {
							echo WP_CLI::colorize( "%wNo cap-linked_account changes to make%n\n" );
						}
					}
				} else {
					echo WP_CLI::colorize( "%wNo cap-linked_account found%n\n" );
				}

				$user->user_login    = $new_user_login;
				$user->user_nicename = $new_user_nicename;
			} else {
				echo WP_CLI::colorize( "%RFailed updating user_login and user_nicename%n\n" );
			}
		} else {
			echo WP_CLI::colorize( "%wNo User changes to make%n\n" );
		}
	}

	public function cmd_update_wp_user_logins_and_nicenames( array $users ) {
		global $wpdb;

		foreach ( $users as $user ) {

			foreach ( get_object_vars( $user ) as $prop_name => $value ) {
				echo WP_CLI::colorize( "%w$prop_name%n: " );
				echo WP_CLI::colorize( "%W%U$value%n " );
			}
			echo "\n";

			$new_user_login    = substr( $user->user_email, 0, strpos( $user->user_email, '@' ) );
			$new_user_nicename = sanitize_title( $user->display_name );

			echo WP_CLI::colorize( '%wNew user_login%n: ' );
			echo WP_CLI::colorize( "%Y$new_user_login%n " );
			echo WP_CLI::colorize( '%wNew user_nicename%n: ' );
			echo WP_CLI::colorize( "%Y$new_user_nicename%n " );
			echo "\n";

			// Update fields for WP_User
			// If user has cap-linked_account, then update with new user_login
			$user_updated = $wpdb->update(
				$wpdb->users,
				array(
					'user_login'    => $new_user_login,
					'user_nicename' => $new_user_nicename,
				),
				array(
					'ID' => $user->ID,
				)
			);

			if ( false !== $user_updated ) {
				echo WP_CLI::colorize( "%GSuccess updating user_login and user_nicename%n\n" );
			} else {
				echo WP_CLI::colorize( "%RFailed updating user_login and user_nicename%n\n" );
			}

			$author_term = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->terms t LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.taxonomy = 'author' AND t.slug = %s",
					"cap-{$user->user_nicename}"
				)
			);

			if ( $author_term ) {
				$author_term_updated = $wpdb->update(
					$wpdb->terms,
					array(
						'name' => $new_user_login,
						'slug' => "cap-$new_user_nicename",
					),
					array(
						'term_id' => $author_term->term_id,
					)
				);

				if ( false === $author_term_updated ) {
					echo WP_CLI::colorize( "%RFailed updating author term%n\n" );
				} else {
					echo WP_CLI::colorize( "%GSuccess updating author term%n\n" );
				}
			} else {
				echo WP_CLI::colorize( "%wNo author term found%n\n" );
			}

			$cap_linked_account = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->postmeta WHERE meta_key = 'cap-linked_account' AND meta_value = %s",
					$user->user_login
				)
			);

			if ( $cap_linked_account ) {
				$linked_account_updated = $wpdb->update(
					$wpdb->postmeta,
					array(
						'meta_value' => $new_user_login,
					),
					array(
						'meta_id' => $cap_linked_account->meta_id,
					)
				);

				if ( false === $linked_account_updated ) {
					echo WP_CLI::colorize( "%RFailed updating cap-linked_account%n\n" );
				} else {
					echo WP_CLI::colorize( "%GSuccess updating cap-linked_account%n\n" );
				}
			} else {
				echo WP_CLI::colorize( "%wNo cap-linked_account found%n\n" );
			}
		}
	}

	public function cmd_create_author_redirects( $args, $assoc_args ) {
		global $wpdb;

		$original_author_ids = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value, MIN( post_id ) as post_id FROM $wpdb->postmeta WHERE meta_key = 'original_user_id' GROUP BY meta_value"
			)
		);
		$count               = count( $original_author_ids );
		WP_CLI::log( "$count original author IDs found" );

		foreach ( $original_author_ids as $original_author_id ) {
			echo "\n";
			WP_CLI::log( "Original author ID: $original_author_id->meta_value, Post ID: $original_author_id->post_id" );
			$post = get_post( $original_author_id->post_id );
			$this->redirection->create_redirection_rule(
				"Author Redirect (OAID: $original_author_id->meta_value ) $post->post_title",
				"/la-silla-vacia/autores/$original_author_id->meta_value",
				get_home_url( null, 'author/' . substr( $post->post_name, 4 ) ),
			);
		}
	}

	/**
	 * This function will pull a list of users which have matching email addresses with Guest Authors, but missing
	 * cap-linked_account meta data. If the accounts should be linked, then the user_login will be used to
	 * update the meta data field.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_fix_missing_author_guest_author_link( $args, $assoc_args ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$users_in_question = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM (
					SELECT 
					    u.ID as user_id, 
					    cap_emails.post_id as email_post_id,
					    u.user_email, 
					    cap_emails.meta_value as guest_author_email
					FROM $wpdb->users u 
					    LEFT JOIN (
					    	SELECT pm.* FROM wp_postmeta pm
					    	    INNER JOIN wp_posts p ON pm.post_id = p.ID
					    	WHERE p.post_type = 'guest-author' AND pm.meta_key = 'cap-user_email'
					    ) as cap_emails 
					        ON u.user_email = cap_emails.meta_value 
					WHERE cap_emails.meta_value IS NOT NULL
				) as emails
					LEFT JOIN (
						SELECT 
						    post_id, 
						    meta_value 
						FROM wp_postmeta 
						WHERE meta_key = 'cap-linked_account'
					) as linked_accounts 
					    ON emails.email_post_id = linked_accounts.post_id
				WHERE linked_accounts.meta_value = '' 
				   OR linked_accounts.meta_value IS NULL 
				ORDER BY emails.email_post_id"
			)
		);

		foreach ( $users_in_question as $user ) {
			$this->output_users_as_table( [ $user->user_id ] );
			$this->output_post_table( [ $user->email_post_id ] );
			$this->output_postmeta_table( $user->email_post_id );
			$author_taxonomies = $this->get_author_term_from_guest_author_id( $user->email_post_id );

			$this->output_terms_table( array_map( fn( $tax ) => $tax->term_id, $author_taxonomies ) );

			if ( 1 === count( $author_taxonomies ) ) {
				$this->fix_author_term_data_from_guest_author( intval( $user->email_post_id ), $author_taxonomies[0], get_user_by( 'id', $user->user_id ), false );
			}
		}
	}

	/**
	 * This function will retrieve any associated term rows for a given guest author ID.
	 *
	 * @param int $guest_author_id Guest Author ID.
	 *
	 * @return array
	 */
	private function get_author_term_from_guest_author_id( int $guest_author_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
    				t.term_id,
    				tt.term_taxonomy_id,
    				t.name,
    				t.slug,
    				tt.taxonomy,
    				tt.description,
    				tt.count,
    				tt.parent
				FROM $wpdb->term_relationships tr 
				    LEFT JOIN $wpdb->term_taxonomy as tt
				    	ON tr.term_taxonomy_id = tt.term_taxonomy_id
					LEFT JOIN $wpdb->terms t 
					    ON t.term_id = tt.term_id
					INNER JOIN $wpdb->posts p 
					    ON tr.object_id = p.ID
				WHERE tt.taxonomy = 'author' AND p.post_type = 'guest-author' AND tr.object_id = %d",
				$guest_author_id
			)
		);
	}

	public function cmd_resolve_damaged_author_guest_author_relationships( $args, $assoc_args ) {
		global $wpdb;

		$damaged_profiles = $wpdb->get_results(
			"SELECT sub.slug, GROUP_CONCAT(sub.term_id) as term_ids, COUNT(sub.term_id) as counter
			FROM (SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.term_taxonomy_id
			      FROM $wpdb->terms t
			               LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
			      WHERE tt.taxonomy = 'author' AND tt.count <> 0) as sub
			GROUP BY sub.slug
			HAVING counter > 1
			ORDER BY counter DESC"
		);

		foreach ( $damaged_profiles as $profile ) {
			echo "\n\n";
			echo WP_CLI::colorize( "%Y$profile->slug%n %wTerm IDs%n: %W$profile->term_ids%n\n" );

			$term_ids              = explode( ',', $profile->term_ids );
			$copy_term_ids         = $term_ids;
			$term_ids_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

			$this->output_terms_table( $copy_term_ids );

			$connected_guest_author_terms = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM (
								SELECT 
								    t.term_id, 
								    t.name, 
								    t.slug, 
								    tt.term_taxonomy_id, 
								    tt.taxonomy, 
								    tt.description, 
								    tt.count, 
								    tt.parent
								FROM wp_terms t
								    LEFT JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
								WHERE tt.term_taxonomy_id IN (
									SELECT tr.term_taxonomy_id
									FROM wp_term_relationships tr
									    LEFT JOIN wp_posts p ON tr.object_id = p.ID
									WHERE p.post_type = 'guest-author'
								)
							) as sub
							WHERE sub.term_id IN ( $term_ids_placeholders )",
					...$term_ids
				)
			);

			foreach ( $connected_guest_author_terms as $connected_guest_author_term ) {
				$term_ids          = array_filter( $term_ids, fn( $term_id ) => $term_id != $connected_guest_author_term->term_id );
				$guest_author_post = $this->get_guest_author_post_from_term_taxonomy_id(
					$connected_guest_author_term->term_taxonomy_id
				);

				$email = $this->extract_email_from_term_description( $connected_guest_author_term->description );

				$user = get_user_by( 'email', $email );

				if ( ! $user ) {
					WP_CLI::error( "User not found via email: $email" );
				}

				$this->fix_author_term_data_from_guest_author( $guest_author_post->ID, $connected_guest_author_term, $user );
			}

			$term_ids_placeholders    = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$loose_guest_author_terms = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->terms t 
    						LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
         				WHERE tt.taxonomy = 'author' AND t.term_id IN ( $term_ids_placeholders )",
					...$term_ids
				)
			);

			foreach ( $loose_guest_author_terms as $loose_guest_author_term ) {
				echo "\n";
				$this->high_contrast_output( 'Term ID', $loose_guest_author_term->term_id );
				$this->high_contrast_output( 'Term Name', $loose_guest_author_term->name );
				$this->high_contrast_output( 'Term Description', $loose_guest_author_term->description );

				$id = $this->extract_id_from_description( $loose_guest_author_term->description );

				$email = $this->extract_email_from_term_description( $loose_guest_author_term->description );

				$user_by_id    = get_user_by( 'id', $id );
				$user_by_email = get_user_by( 'email', $email );

				if ( false === $user_by_email ) {
					echo WP_CLI::colorize( "%RUser not found by email: $email%n\n" );
					// This likely means that the guest author does not have a WP_User login.
					// The $id likely belongs to a post.
					// Display author term and post
					$this->handle_fixing_standalone_guest_author_data( $id, $loose_guest_author_term );
					continue;
				}

				$this->output_value_comparison_table(
					array(),
					$user_by_id ? $user_by_id->to_array() : array(),
					$user_by_email ? $user_by_email->to_array() : array(),
					true,
					'ID',
					'Email'
				);

				// Need to figure out if this should be linked with a user or with a guest author post.
				// If right is chosen, then this means the ID belongs to post.
				$result = $this->ask_prompt( 'Should the (e)mail one be used? Or (h)alt execution?' );

				if ( 'e' !== $result ) {
					die();
				}

				$user = $user_by_email;

				// Updates
				// Check that display_name is equal to santize_title( display_name ) = user_nicename.
				// user_nicename should equal cap-<user_nicename> = wp_terms.slug
				// user_login should equal user_login = wp_terms.name

				$this->fix_standalone_wp_user_author_term_data( $user, $loose_guest_author_term );

				// user_nicename should equal cap-<user_nicename> = wp_posts.post_name
				// cap-user_login meta should equal wp_posts.post_name = cap-<user_nicename>
				// cap-user-linked_account should equal user_login
				// relate term to guest author post id

				$guest_author_post          = $this->get_guest_author_post_by_id( $id );
				$cap_fields                 = array(
					'cap-user_login',
					'cap-user_nicename',
					'cap-user_email',
					'cap-linked_account',
					'cap-display_name',
				);
				$filtered_author_cap_fields = $this->get_filtered_cap_fields( $id, $cap_fields );

				echo WP_CLI::colorize( "%BWP_User vs wp_postmeta field%n\n" );
				$comparison = $this->output_value_comparison_table(
					array(
						'cap-user_login',
					),
					array(
						'cap-user_login' => $this->get_guest_author_user_login( $user ),
					),
					array(
						'cap-user_login' => $filtered_author_cap_fields['cap-user_login'],
					),
					true,
					'WP_User',
					'Post Meta'
				);
				if ( ! empty( $comparison['different'] ) ) {
					// Update Post Meta to match WP_User fields
					foreach ( $comparison['different'] as $key => $value ) {
						echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['Post Meta']}%n to %G%U{$value['WP_User']}%n\n" );
						$filtered_author_cap_fields['cap-user_login'] = $value['WP_User'];
						$wpdb->update(
							$wpdb->postmeta,
							array(
								'meta_value' => $value['WP_User'],
							),
							array(
								'post_id'  => $id,
								'meta_key' => $key,
							)
						);
					}
				}

				$this->fix_wp_post_postmeta_data( $guest_author_post, $filtered_author_cap_fields );

				echo WP_CLI::colorize( "%BWP_User vs Guest Author%n\n" );
				$comparison = $this->output_value_comparison_table(
					array(
						'post_name',
						'cap-linked_account',
						'cap-user_email',
						'cap-display_name',
					),
					array(
						'post_name'          => 'cap-' . $user->user_nicename,
						'cap-linked_account' => $user->user_login,
						'cap-user_email'     => $user->user_email,
						'cap-display_name'   => $user->display_name,
					),
					array(
						'post_name'          => $guest_author_post->post_name,
						'cap-linked_account' => $filtered_author_cap_fields['cap-linked_account'] ?? '',
						'cap-user_email'     => $filtered_author_cap_fields['cap-user_email'],
						'cap-display_name'   => $filtered_author_cap_fields['cap-display_name'] ?? '',
					),
					true,
					'WP_User',
					'Guest Author'
				);
				if ( ! empty( $comparison['different'] ) ) {
					// Update Post Meta to match WP_User fields
					foreach ( $comparison['different'] as $key => $value ) {
						echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['Guest Author']}%n to %G%U{$value['WP_User']}%n\n" );
						if ( 'post_name' === $key ) {
							$wpdb->update(
								$wpdb->posts,
								array(
									'post_name' => $value['WP_User'],
								),
								array(
									'ID' => $guest_author_post->ID,
								)
							);
						} else {
							update_post_meta( $guest_author_post->ID, $key, $value['WP_User'] );
						}
					}
				}
			}

			$this->output_terms_table( $copy_term_ids );
		}
	}

	private function output_terms_table( array $term_ids ): ?array {
		global $wpdb;

		$term_ids_placeholders = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

		$author_terms = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
					    t.term_id, 
					    t.name, 
					    t.slug, 
					    tt.term_taxonomy_id, 
					    tt.taxonomy, 
					    tt.description, 
					    tt.parent,
					    tt.count
					FROM wp_terms t
					    LEFT JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
					WHERE t.term_id IN ( $term_ids_placeholders )",
				...$term_ids
			)
		);

		WP_CLI\Utils\format_items( 'table', $author_terms, array( 'term_id', 'name', 'slug', 'term_taxonomy_id', 'taxonomy', 'description', 'parent', 'count' ) );
		return $author_terms;
	}

	private function output_post_table( array $post_ids ): void {
		global $wpdb;

		$post_ids_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$post_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE ID IN ( $post_ids_placeholders )",
				...$post_ids
			)
		);

		echo WP_CLI::colorize( "%BPost's Table%n\n" );
		WP_CLI\Utils\format_items(
			'table',
			$post_rows,
			array(
				'ID',
				'post_type',
				'post_title',
				'post_name',
				'post_status',
				'post_date',
				'post_modified',
				'post_content',
				'post_author',
				'post_parent',
			)
		);
	}

	private function output_users_as_table( array $users ) {
		$users = array_map(
			function ( $user ) {
				if ( $user instanceof WP_User ) {
					return $user->to_array();
				}

				if ( is_numeric( $user ) ) {
					$user = get_user_by( 'id', $user );

					if ( $user ) {
						return $user->to_array();
					}
				}

				return null;
			},
			$users
		);
		$users = array_filter( $users );

		if ( empty( $users ) ) {
			echo WP_CLI::colorize( "%YNo users found%n\n" );
			return null;
		}

		echo WP_CLI::colorize( "%BUser's Table%n\n" );
		WP_CLI\Utils\format_items(
			'table',
			$users,
			array(
				'ID',
				'user_login',
				'user_nicename',
				'user_email',
				'display_name',
			)
		);
	}

	/**
	 * This function will output a table to terminal with the postmeta results for a given Post ID.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array
	 */
	public function output_postmeta_table( int $post_id ) {
		global $wpdb;

		$postmeta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->postmeta WHERE post_id = %d ORDER BY meta_key ASC",
				$post_id
			)
		);

		echo WP_CLI::colorize( "%BPostmeta Table (%n%W$post_id%n%B)%n\n" );
		WP_CLI\Utils\format_items(
			'table',
			$postmeta_rows,
			array(
				'meta_id',
				'meta_key',
				'meta_value',
			)
		);

		return $postmeta_rows;
	}

	public function output_postmeta_data_table( array $identifiers ) {
		global $wpdb;

		$base_query = "SELECT * FROM $wpdb->postmeta WHERE ";

		$post_meta_records = array();
		foreach ( $identifiers as $meta_key => $meta_value ) {
			$query         = "$base_query meta_key = '$meta_key' AND meta_value = '$meta_value'";
			$postmeta_rows = $wpdb->get_results( $query );

			if ( empty( $postmeta_rows ) ) {
				echo WP_CLI::colorize( "%YNo postmeta found for meta_key: $meta_key and meta_value: $meta_value%n\n" );
				continue;
			}

			echo WP_CLI::colorize( "%BPostmeta Data (%n%W$meta_key%n%B)%n\n" );
			WP_CLI\Utils\format_items(
				'table',
				$postmeta_rows,
				array(
					'meta_id',
					'post_id',
					'meta_key',
					'meta_value',
				)
			);
			$post_meta_records = array_merge( $post_meta_records, $postmeta_rows );
		}

		return $post_meta_records;
	}

	public function output_term_taxonomy_table( array $term_taxonomy_ids ) {
		global $wpdb;

		if ( empty( $term_taxonomy_ids ) ) {
			echo WP_CLI::colorize( "%YNo term_taxonomy_ids provided%n\n" );
			return null;
		}

		$term_taxonomy_ids_placeholder = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$taxonomies                    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->term_taxonomy WHERE term_taxonomy_id IN ( $term_taxonomy_ids_placeholder )",
				...$term_taxonomy_ids
			)
		);

		if ( empty( $taxonomies ) ) {
			echo WP_CLI::colorize( '%YNo term_taxonomy found for term_taxonomy_ids: ' . implode( ', ', $term_taxonomy_ids ) . "%n\n" );
			return null;
		}

		echo WP_CLI::colorize( "%BTerm Taxonomy's Table%n\n" );
		WP_CLI\Utils\format_items(
			'table',
			$taxonomies,
			array(
				'term_taxonomy_id',
				'term_id',
				'taxonomy',
				'description',
				'parent',
				'count',
			)
		);

		return $taxonomies;
	}

	private function get_guest_author_post_by_id( int $post_id ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE post_type = 'guest-author' AND ID = %d",
				$post_id
			)
		);
	}

	private function high_contrast_output( string $identifier, string $value ) {
		echo WP_CLI::colorize( "%w$identifier%n: %W$value%n\n" );
	}

	private function get_guest_author_user_login( WP_User $user ) {
		if ( ! empty( $user->display_name ) && $user->display_name != $user->user_login ) {
			$user_login = sanitize_title( $user->display_name );
		} elseif ( ! empty( $user->first_name ) && ! empty( $user->last_name ) ) {
			$user_login = sanitize_title( $user->first_name . ' ' . $user->last_name );
		} else {
			if ( is_email( $user->user_login ) ) {
				$user_login = substr( $user->user_email, 0, strpos( $user->user_email, '@' ) );
			} else {
				$user_login = $user->user_login;
			}
		}

		if ( $user_login == $user->user_nicename ) {
			echo WP_CLI::colorize( "%wPotential Guest Author user_login%n%W($user_login)%n %wand user_nicename%n%W($user->user_nicename)%n are equal. Updating user_login to%n " );
			$user_login = "cap-$user_login";
			echo WP_CLI::colorize( "%Y$user_login%n\n" );
		}

		return $user_login;
	}

	/**
	 * This is a convenience function that allows for immediate feedback on whether the chosen slug is unique.
	 *
	 * @param string $slug This is the unique author slug, which should nearly match the user_nicename (e.g. slug = <user_nicename> or slug = cap-<user_nicename>).
	 * @param int    $term_taxonomy_id Existing term_taxonomy_id to exclude from search result.
	 *
	 * @return string
	 */
	private function prompt_for_unique_author_slug( string $slug, int $term_taxonomy_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_slugs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.term_taxonomy_id, t.slug, tt.description 
					FROM $wpdb->terms t 
    					LEFT JOIN $wpdb->term_taxonomy tt 
    					    ON t.term_id = tt.term_id 
         			WHERE tt.taxonomy = 'author' AND tt.term_taxonomy_id <> %d AND t.slug = %s",
				$term_taxonomy_id,
				$slug
			)
		);

		if ( ! empty( $existing_slugs ) ) {
			echo WP_CLI::colorize( "%YExisting slugs found%n\n" );
			foreach ( $existing_slugs as $record ) {
				$this->output_terms_table( [ intval( $record->term_id ) ] );
				$description_id = $this->extract_id_from_description( $record->description );
				$guest_author_record = $this->get_guest_author_post_from_term_taxonomy_id( $record->term_taxonomy_id );

				if ( null === $guest_author_record ) {
					echo WP_CLI::colorize( "%Yterm_taxonomy_id (%n%W$record->term_taxonomy_id%n%Y) not connected to Guest Author%n\n" );
					$guest_author_record = new \stdClass();
					$guest_author_record->ID = $description_id;
				}

				$post_ids = array_unique( [ $description_id, $guest_author_record->ID ] );

				foreach ( $post_ids as $post_id ) {
					$this->output_post_table( [ $post_id ] );
					$this->output_postmeta_table( $post_id );
				}
			}
			
			$prompt = $this->ask_prompt( 'What would become the author slug for this GA already exists. Would you like to (u)pdate it, or (h)alt execution?' );

			if ( 'u' === $prompt ) {
				$user_provided_slug = $this->ask_prompt( 'Please enter a unique slug' );

				if ( ! str_starts_with( $user_provided_slug, 'cap-' ) ) {
					$user_provided_slug = "cap-$user_provided_slug";
				}

				return $this->prompt_for_unique_author_slug( $user_provided_slug, $term_taxonomy_id );

			} elseif ( 'h' === $prompt ) {
				die();
			}
		}

		return $slug;
	}

	/**
	 * Performs a validation to ensure that a user's login, nicename, and display name fields are correctly set.
	 *
	 * @param WP_User $user
	 *
	 * @return WP_User
	 */
	public function validate_user_name_fields( WP_User $user, bool $confirm = false ) {
		$clone = new WP_User( clone $user->data );

		if ( empty( $clone->user_login ) || is_email( $clone->user_login ) ) {
			$user_login_first_attempt = substr( $clone->user_email, 0, strpos( $clone->user_email, '@' ) );
			$user_login          = $user_login_first_attempt;
			$user_login          = $this->obtain_unique_user_field(
				'user_login',
				$user_login,
				function ( $value ) {
					return $value . '-' . substr( md5( mt_rand() ), 0, 5 );
				},
				$clone->ID,
				1,
				1
			);

			if ( null === $user_login ) {
				if ( $confirm ) {
					$user_login = $this->ask_prompt( 'Unable to create a unique user_login. Please (s)et a user_login, or (h)alt execution' );

					if ( 's' === $user_login ) {
						$user_login = $this->ask_prompt( 'Enter user_login' );

						if ( ! $this->is_unique_user_field( 'user_login', $user_login, $clone->ID ) ) {
							echo WP_CLI::colorize( "%RThat user_login is not unique. Please review the database. Halting further execution.%n\n" );
							die();
						}
					} else {
						// Stop execution on any other input.
						die();
					}
				} else {
					$user_login = $this->obtain_unique_user_field(
						'user_login',
						$user_login_first_attempt,
						function ( $value ) {
							return $value . '-' . substr( md5( mt_rand() ), 0, 5 );
						},
						$clone->ID,
						1,
						2
					);

					if ( null === $user_login ) {
						echo WP_CLI::colorize( "%RUnable to create a unique user_login. Please review the database. Halting further execution.%n\n" );
						die();
					}
				}
			}

			$clone->user_login = $user_login;
		}

		if ( ! empty( $clone->display_name ) && ! is_email( $clone->display_name ) ) {
			$user_nicename_first_attempt = sanitize_title( $clone->display_name );
			$clone->user_nicename        = $this->obtain_unique_user_nicename( $user_nicename_first_attempt, $clone->ID, $confirm );
			return $clone;
		}

		if ( ! empty( $clone->first_name ) && ! empty( $clone->last_name ) ) {
			// At this point we know that $clone->display_name is either empty or an email, so it should be set/updated.
			$clone->display_name  = "$clone->first_name $clone->last_name";
			$clone->user_nicename = $this->obtain_unique_user_nicename( sanitize_title( $clone->first_name . '-' . $clone->last_name ), $clone->ID );
			return $clone;
		}

		$user_nicename = $this->ask_prompt( 'No display name found for WP_User. Please (s)et a display_name, or (h)alt execution' );

		if ( 's' === $user_nicename ) {
			$clone->display_name = $this->ask_prompt( 'Enter display name' );
			return $this->validate_user_name_fields( $clone );
		}

		if ( 'h' === $user_nicename || ! in_array( $user_nicename, array( 's' ), true ) ) {
			die();
		}

		return $clone;
	}


	/**
	 * This is a convenience function to help with checking whether a particular user_login or user_nicename
	 * are unique.
	 *
	 * @param string $field The wp_user column to check for uniqueness.
	 * @param string $value The value to check for uniqueness.
	 * @param int    $exclude_user_id This user_id should be excluded from the results.
	 *
	 * @return bool
	 */
	private function is_unique_user_field( string $field, string $value, int $exclude_user_id = 0 ): bool {
		global $wpdb;

		$field_escaped = esc_sql( $field );
		$sql_escaped = esc_sql( sprintf( "SELECT ID FROM $wpdb->users WHERE $field_escaped = %s", $value ) );

		if ( $exclude_user_id ) {
			$sql_escaped = esc_sql( sprintf( "$sql_escaped AND ID <> %d", $exclude_user_id ) );
		}

		$exists = $wpdb->get_var( $sql_escaped );

		return null === $exists;
	}

	/**
	 * This function helps with obtaining a unique user_login or user_nicename. It is a recursive function
	 * that will make a variable amount of attempts to generate a unique value.
	 *
	 * @param string $field The wp_user column to check for uniqueness.
	 * @param string $value The value to check for uniqueness.
	 * @param callable $appender A function that will append a value to the $value parameter.
	 * @param int $attempt The current attempt number.
	 * @param int $total_attempts The total number of attempts to make.
	 *
	 * @return string|null
	 */
	private function obtain_unique_user_field( string $field, string $value, $appender, int $exclude_user_id = 0, int $attempt = 1, int $total_attempts = 3 ) {
		if ( $attempt > $total_attempts ) {
			return null;
		}

		if ( ! $this->is_unique_user_field( $field, $value, $exclude_user_id ) ) {
			$new_value = $appender( $value );

			return $this->obtain_unique_user_field( $field, $new_value, $appender, $exclude_user_id, $attempt + 1, $total_attempts );
		}

		return $value;
	}

	/**
	 * Resuable function to facilitate obtaining a unique user_nicename.
	 *
	 * @param string $user_nicename_first_attempt The first attempt at creating a unique user_nicename.
	 * @param int    $exclude_user_id The user_id to exclude from the search.
	 * @param bool   $confirm Whether to prompt the user for input.
	 *
	 * @return string|void|null
	 */
	private function obtain_unique_user_nicename( string $user_nicename_first_attempt, int $exclude_user_id = 0, bool $confirm = false ) {
		$user_nicename = $user_nicename_first_attempt;
		$user_nicename = $this->obtain_unique_user_field(
			'user_nicename',
			$user_nicename,
			function ( $value ) {
				return $value . '-' . 1;
			},
			$exclude_user_id,
			1,
			1
		);

		if ( null === $user_nicename ) {
			if ( $confirm ) {
				$user_nicename = $this->ask_prompt( 'Unable to create a unique user_nicename. Please (s)et a user_nicename, or (h)alt execution' );

				if ( 's' === $user_nicename ) {
					$user_nicename = $this->ask_prompt( 'Enter user_nicename' );

					if ( ! $this->is_unique_user_field( 'user_nicename', $user_nicename, $exclude_user_id ) ) {
						echo WP_CLI::colorize( "%RThat user_nicename is not unique. Please review the database. Halting further execution.%n\n" );
						die();
					}
				} else {
					// Stop execution on any other input.
					die();
				}
			} else {
				global $wpdb;

				$no_of_similar_nicenames = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $wpdb->users WHERE user_nicename LIKE %s",
						$user_nicename_first_attempt . '-%'
					)
				);

				$user_nicename = $this->obtain_unique_user_field(
					'user_nicename',
					$user_nicename_first_attempt,
					function ( $value ) use ( $no_of_similar_nicenames ) {
						$no_of_similar_nicenames++;
						return $value . '-' . ( $no_of_similar_nicenames );
					},
					$exclude_user_id
				);

				if ( null === $user_nicename ) {
					echo WP_CLI::colorize( "%RUnable to create a unique user_nicename. Please review the database. Halting further execution.%n\n" );
					die();
				}
			}
		}

		return $user_nicename;
	}

	private function output_value_comparison_table( array $keys, array $left_set, array $right_set, bool $strict = true, string $left = 'left', string $right = 'right' ) {
		if ( empty( $keys ) ) {
			$keys = array_keys( array_merge( $left_set, $right_set ) );
		}

		$table = new Table();
		$table->setHeaders(
			array(
				'',
				'Match?',
				$left,
				$right,
			)
		);

		$matching_rows     = array();
		$different_rows    = array();
		$undetermined_rows = array();

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $left_set ) && array_key_exists( $key, $right_set ) ) {
				if ( empty( $left_set[ $key ] ) && empty( $right_set[ $key ] ) ) {
					$match = '-';
				} else {
					$match = $strict ? ( $left_set[ $key ] === $right_set[ $key ] ? 'Yes' : 'No' ) : ( $left_set[ $key ] == $right_set[ $key ] ? 'Yes' : 'No' );
				}
			} elseif ( empty( $left_set[ $key ] ) && empty( $right_set[ $key ] ) ) {
					$match = '-';
			} else {
				$match = 'No';
			}

			$values = array(
				$left  => $left_set[ $key ] ?? '',
				$right => $right_set[ $key ] ?? '',
			);

			$row = array(
				$key,
				$match,
				...array_values( $values ),
			);

			if ( 'Yes' === $match ) {
				$matching_rows[ $key ] = $values;
			} elseif ( 'No' === $match ) {
				$different_rows[ $key ] = $values;
			} else {
				$undetermined_rows[ $key ] = $values;
			}

			$table->addRow( $row );
		}

		$table->display();

		return array(
			'matching'     => $matching_rows,
			'different'    => $different_rows,
			'undetermined' => $undetermined_rows,
		);
	}

	private function output_comparison_table( array $keys, array ...$arrays ) {
		$array_bag = array(
			...$arrays,
		);

		if ( empty( $keys ) ) {
			$keys = array_keys( array_merge( ...$arrays ) );
		}

		$table = new Table();
		$table->setHeaders(
			array(
				'',
				...array_keys( $array_bag ),
			)
		);

		foreach ( $keys as $key ) {
			$row = array(
				$key,
			);

			foreach ( $array_bag as $array ) {
				$row[] = $array[ $key ] ?? '';
			}

			$table->addRow( $row );
		}

		$table->display();
	}

	private function get_guest_author_post_from_term_taxonomy_id( int $term_taxonomy_id ) {
		echo WP_CLI::colorize( "%BGetting Guest Author Record%n\n" );
		$this->high_contrast_output( 'Term Taxonomy ID', $term_taxonomy_id );
		global $wpdb;

		$guest_author_post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE post_type = 'guest-author' AND ID IN (
    					SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d
				)",
				$term_taxonomy_id
			)
		);

		if ( $guest_author_post ) {
			$this->high_contrast_output( 'Guest Author Post ID', $guest_author_post->ID );
		}

		return $guest_author_post;
	}

	private function extract_id_from_description( string $description ) {
		preg_match( '/( [0-9]+ )/', $description, $matches );

		$id = null;

		if ( ! empty( $matches ) ) {
			$id = trim( $matches[0] );
		}

		if ( ! is_null( $id ) ) {
			$this->high_contrast_output( 'ID', $id );
			$response = 'c';

			// $response = $this->ask_prompt( "Is the ID actually (c)orrect? Or would you like to (u)pdate it? Should I (h)alt execution?" );
			// $response = strtolower( $response );

			if ( 'c' !== $response ) {
				if ( 'u' === $response ) {
					$id = $this->ask_prompt( 'Please enter the correct ID' );
				} else {
					die();
				}
			}
		}

		return intval( $id );
	}

	private function extract_email_from_term_description( string $description ) {
		$exploded = explode( ' ', $description );
		$email    = array_pop( $exploded );

		if ( ! is_email( $email ) ) {
			$response = $this->ask_prompt( "Apparently not an email: '$email'. Is it actually (c)orrect? Or would you like to (u)pdate it? Should I (h)alt execution?" );
			$response = strtolower( $response );

			if ( 'c' !== $response ) {
				if ( 'u' === $response ) {
					$email = $this->ask_prompt( 'Please enter the correct email' );
				} else {
					die();
				}
			}
		}

		$this->high_contrast_output( 'Email', $email );

		return $email;
	}

	private function get_cap_fields( int $guest_author_post_id ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_key, meta_value FROM $wpdb->postmeta WHERE post_id = %d AND meta_key LIKE 'cap-%'",
				$guest_author_post_id
			),
		);

		return array_map(
			function ( $result ) {
				return array(
					'meta_id'    => $result->meta_id,
					'meta_key'   => $result->meta_key,
					'meta_value' => $result->meta_value,
				);
			},
			$results
		);
	}

	private function get_filtered_cap_fields( int $guest_author_post_id, array $keys ) {
		$filtered_author_cap_fields = array();

		foreach ( $this->get_cap_fields( $guest_author_post_id ) as $author_cap_field ) {
			if ( in_array( $author_cap_field['meta_key'], $keys, true ) ) {
				if ( array_key_exists( $author_cap_field['meta_key'], $filtered_author_cap_fields ) ) {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$filtered_author_cap_fields[ $author_cap_field['meta_key'] ] = array(
						$filtered_author_cap_fields[ $author_cap_field['meta_key'] ],
						$author_cap_field['meta_value'],
					);
				} else {
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					$filtered_author_cap_fields[ $author_cap_field['meta_key'] ] = $author_cap_field['meta_value'];
				}
			}
		}

		foreach ( $filtered_author_cap_fields as $key => $filtered_author_cap_field ) {
			if ( is_array( $filtered_author_cap_field ) ) {

				unset( $filtered_author_cap_fields[ $key ] );

				foreach ( $this->get_cap_fields( $guest_author_post_id ) as $author_cap_field ) {
					if ( $author_cap_field['meta_key'] === $key ) {
						$filtered_author_cap_fields[ $author_cap_field['meta_key'] ][ $author_cap_field['meta_id' ] ] = $author_cap_field['meta_value'];
					}
				}
			}
		}

		return $filtered_author_cap_fields;
	}

	private function ask_prompt( string $question ) {
		if ( str_contains( $question, '%n' ) ) {
			echo WP_CLI::colorize( "$question: " );
		} else {
			fwrite( STDOUT, "$question: " );
		}

		return strtolower( trim( fgets( STDIN ) ) );
	}

	private function fix_wp_user_wp_post_postmeta_data( WP_User $user, $guest_author_id ) {
		global $wpdb;

		$cap_fields = array(
			'cap-user_login',
			'cap-user_nicename',
			'cap-user_email',
			'cap-linked_account',
		);

		$filtered_author_cap_fields = $this->get_filtered_cap_fields( $guest_author_id, $cap_fields );

//		$cap_user_login = $this->get_guest_author_user_login( $user );

		echo WP_CLI::colorize( "%BWP_User vs wp_postmeta fields%n\n" );
		$comparison = $this->output_value_comparison_table(
			$cap_fields,
			array(
				'cap-user_login'     => $cap_user_login,
				'cap-user_email'     => $user->user_email,
				'cap-linked_account' => $user->user_login,
			),
			$filtered_author_cap_fields,
			true,
			'User Fields',
			'Post Meta Fields',
		);
		if ( ! empty( $comparison['different'] ) ) {
			// Update Post Meta to match WP_User fields
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['Post Meta Fields']}%n to %G%U{$value['User Fields']}%n\n" );
				$wpdb->update(
					$wpdb->postmeta,
					array(
						'meta_value' => $value['User Fields'],
					),
					array(
						'post_id'  => $guest_author_id,
						'meta_key' => $key,
					)
				);
			}
		}
	}

	private function fix_wp_post_postmeta_data( $guest_author_record, array &$filtered_author_cap_fields ) {
		echo WP_CLI::colorize( "%BGuest Author (wp_posts) vs wp_postmeta field%n\n" );
		global $wpdb;

		if ( ! isset( $filtered_author_cap_fields['cap-user_login'] ) ) {
			$cap_user_login                               = $this->get_filtered_cap_fields( $guest_author_record->ID, array( 'cap-user_login' ) );
			$filtered_author_cap_fields['cap-user_login'] = $cap_user_login['cap-user_login'];
		}

		if ( ! str_starts_with( $filtered_author_cap_fields['cap-user_login'], 'cap-' ) ) {
			$filtered_author_cap_fields['cap-user_login'] = 'cap-' . $filtered_author_cap_fields['cap-user_login'];
		}

		$comparison = $this->output_value_comparison_table(
			array(
				'post_name',
			),
			array(
				'post_name' => $guest_author_record->post_name,
			),
			array(
				'post_name' => $filtered_author_cap_fields['cap-user_login'],
			),
			true,
			'Guest Author Fields',
			'Post Meta Fields'
		);
		if ( ! empty( $comparison['different'] ) ) {
			// Update Post Meta to match WP_User fields
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['Guest Author Fields']}%n to %G%U{$value['Post Meta Fields']}%n\n" );
				$wpdb->update(
					$wpdb->posts,
					array(
						$key => $value['Post Meta Fields'],
					),
					array(
						'ID' => $guest_author_record->ID,
					)
				);
			}
		}
	}

	public function cmd_fix_user_guest_author_term_data( $args, $assoc_args ) {
		$guest_author_id = $assoc_args['guest-author-id'];
		$term_id         = $assoc_args['term-id'];
		$user_id         = $assoc_args['user-id'];

		global $wpdb;

		$term = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
	                t.term_id,
	                t.name,
	                t.slug,
	                tt.term_taxonomy_id,
	                tt.description
	            FROM $wpdb->terms t 
	            LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
	                WHERE tt.taxonomy = 'author' AND t.term_id = %d",
				$term_id
			)
		);

		if ( empty( $term ) ) {
			echo WP_CLI::colorize( "%YNo term found for term_id: $term_id%n\n" );
			return null;
		}

		if ( count( $term ) > 1 ) {
			echo WP_CLI::colorize( "%YMore than one term found for term_id: $term_id%n\n" );
			return null;
		}

		$term = $term[0];

		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			echo WP_CLI::colorize( "%YNo user found for user_id: $user_id%n\n" );
			return null;
		}

		$this->fix_author_term_data_from_guest_author( $guest_author_id, $term, $user );
	}

	private function fix_author_term_data_from_guest_author( $guest_author_id, $term, $user, $confirm = true ) {
		global $wpdb;

		$guest_author_record        = $this->get_guest_author_post_by_id( $guest_author_id );
		$cap_fields                 = array(
			'cap-user_login',
			'cap-user_email',
			'cap-linked_account',
			'cap-display_name',
		);
		$filtered_author_cap_fields = $this->get_filtered_cap_fields( $guest_author_id, $cap_fields );
		$filtered_author_cap_fields = $this->ensure_important_cap_meta_fields_exist( $guest_author_id, $filtered_author_cap_fields );

		$user_display_name = $user->display_name;
		if ( is_email( $user_display_name ) ) {
			$user_display_name = '';
		}

		if ( empty( $user_display_name ) && ! empty( $user->first_name ) && ! empty( $user->last_name ) ) {
			$user_display_name = "$user->first_name $user->last_name";
		}

		$post_meta_display_name    = $filtered_author_cap_fields['cap-display_name'] ?? '';
		$guest_author_display_name = $post_meta_display_name;

		if ( empty( $post_meta_display_name ) && ! empty( $user_display_name ) ) {
			$guest_author_display_name = $user_display_name;
		} elseif ( empty( $user_display_name ) && ! empty( $post_meta_display_name ) ) {
			$user->display_name = $post_meta_display_name;
		} elseif ( $user_display_name !== $post_meta_display_name ) {
			if ( $confirm ) {
				$this->high_contrast_output( 'User Display Name', $user_display_name );
				$this->high_contrast_output( 'Guest Author Display Name', $post_meta_display_name );
				$prompt = $this->ask_prompt( 'Which display name would you like me to use? (u)ser, (g)uest author, (gu) guest author and update user display name, or (h)alt execution' );

				if ( 'u' === $prompt ) {
					$guest_author_display_name = $user_display_name;
				} elseif ( 'g' === $prompt || 'gu' === $prompt ) {
					$user->display_name = $post_meta_display_name;

					if ( 'gu' === $prompt ) {
						$update = $wpdb->update(
							$wpdb->users,
							[
								'display_name' => $user->display_name,
							],
							[
								'ID' => $user->ID,
							]
						);
					}
				} else {
					die();
				}
			} else {
				$user->display_name = $post_meta_display_name;
				$wpdb->update(
					$wpdb->users,
					[
						'display_name' => $user->display_name,
					],
					[
						'ID' => $user->ID,
					]
				);
			}
		}

		$this->update_relevant_user_fields_if_necessary( $user );

		$cap_user_login = $this->get_guest_author_user_login( $user );

		// Ensure that $cap_user_login is unique, since it will ultimately become the slug for the author term.
		$cap_user_login = $this->prompt_for_unique_author_slug( $cap_user_login, $term->term_taxonomy_id );

		$capless_user_login = str_replace( 'cap-', '', $cap_user_login );
		if ( $capless_user_login !== $user->user_nicename ) {
			echo WP_CLI::colorize( "%wUpdating user_nicename%n %W($user->user_nicename)%n to %G%U($capless_user_login)%n %wso that it conforms with cap-user_login%n %W({$capless_user_login})%n%w:%n " );
			$user->user_nicename = $capless_user_login;

			$user_update = $wpdb->update(
				$wpdb->users,
				array(
					'user_nicename' => $user->user_nicename,
				),
				array(
					'ID' => $user->ID,
				)
			);

			if ( false === $user_update ) {
				echo WP_CLI::colorize( "%RFailed%n\n" );
			} else {
				echo WP_CLI::colorize( "%GSuccess%n\n" );
			}
		}

		$insert_guest_author_term_rel_result = $this->insert_guest_author_term_relationship( $guest_author_id, $term->term_taxonomy_id );

		if ( null === $insert_guest_author_term_rel_result && $confirm ) {
			$prompt = $this->ask_prompt( 'Would you like to (u)se that one, allow to (s)kip, or (h)alt execution?' );

			if ( 'h' === $prompt ) {
				die();
			} elseif ( 's' === $prompt ) {
				return;
			}
		}

		if ( $guest_author_display_name !== $post_meta_display_name ) {
			echo WP_CLI::colorize( "%wUpdating%n %Wcap-display_name:%n %C{$post_meta_display_name}%n to %G%U{$guest_author_display_name}%n\n" );
			$wpdb->update(
				$wpdb->postmeta,
				array(
					'meta_value' => $guest_author_display_name,
				),
				array(
					'post_id'  => $guest_author_id,
					'meta_key' => 'cap-display_name',
				)
			);
		}

		echo WP_CLI::colorize( "%BDisplay Name vs WP_Posts.post_title%n\n" );
		$comparison = $this->output_value_comparison_table(
			array(
				'post_title',
			),
			array(
				'post_title' => $guest_author_record->post_title,
			),
			array(
				'post_title' => $guest_author_display_name,
			),
			true,
			'WP_Posts.post_title',
			'Display Name'
		);
		if ( ! empty( $comparison['different'] ) ) {
			// Update Post Meta to match WP_User fields
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['WP_Posts.post_title']}%n to %G%U{$value['Display Name']}%n\n" );
				$guest_author_record->$key = $value['Display Name'];
				$wpdb->update(
					$wpdb->posts,
					array(
						$key => $value['Display Name'],
					),
					array(
						'ID' => $guest_author_id,
					)
				);
			}
		}

		echo WP_CLI::colorize( "%BGuest Author vs wp_postmeta field%n\n" );
		$comparison = $this->output_value_comparison_table(
			array(
				'cap-user_login',
			),
			array(
				'cap-user_login' => $cap_user_login,
			),
			array(
				'cap-user_login' => $filtered_author_cap_fields['cap-user_login'],
			),
			true,
			'Guest Author',
			'Post Meta'
		);
		if ( ! empty( $comparison['different'] ) ) {
			// Update Post Meta to match WP_User fields
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['Post Meta']}%n to %G%U{$value['Guest Author']}%n\n" );
				$filtered_author_cap_fields['cap-user_login'] = $value['Guest Author'];
				$wpdb->update(
					$wpdb->postmeta,
					array(
						'meta_value' => $value['Guest Author'],
					),
					array(
						'post_id'  => $guest_author_id,
						'meta_key' => $key,
					)
				);
			}
		}

		$this->fix_wp_post_postmeta_data( $guest_author_record, $filtered_author_cap_fields );

		echo WP_CLI::colorize( "%BGuest Author Record vs WP_Terms%n\n" );
		$comparison = $this->output_value_comparison_table(
			array(
				'name',
				'slug',
			),
			array(
				'name' => $guest_author_record->post_title,
				'slug' => $filtered_author_cap_fields['cap-user_login'],
			),
			array(
				'name' => $term->name,
				'slug' => $term->slug,
			),
			true,
			'Guest Author Record',
			'Author Term'
		);
		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['Author Term']}%n to %G%U{$value['Guest Author Record']}%n\n" );
				$wpdb->update(
					$wpdb->terms,
					array(
						$key => $value['Guest Author Record'],
					),
					array(
						'term_id' => $term->term_id,
					)
				);
			}
		}

		//$this->fix_user_login_and_nicename( $user );

		echo WP_CLI::colorize( "%Bwp_postmeta vs User Fields%n\n" );
		$comparison = $this->output_value_comparison_table(
			array(
				'cap-user_email',
				'cap-linked_account',
			),
			array(
				'cap-user_email'     => $user->user_email,
				'cap-linked_account' => $user->user_login,
			),
			array(
				'cap-user_email'     => $filtered_author_cap_fields['cap-user_email'] ?? '',
				'cap-linked_account' => $filtered_author_cap_fields['cap-linked_account'] ?? '',
			),
			true,
			'User Fields',
			'wp_postmeta'
		);
		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['wp_postmeta']}%n to %G%U{$value['User Fields']}%n\n" );
				$filtered_author_cap_fields[ $key ] = $value['User Fields'];
				$wpdb->update(
					$wpdb->postmeta,
					array(
						'meta_value' => $value['User Fields'],
					),
					array(
						'post_id'  => $guest_author_id,
						'meta_key' => $key,
					)
				);
			}
		}

		wp_cache_flush();
		$this->update_author_description( $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id ), $term );
	}

	/**
	 * This function will insert a row into wp_postmeta for guest authors with a missing cap-linked_account or cap-user_email field.
	 * This field needs to be in the DB so that it can be properly updated when necessary.
	 *
	 * @param int   $guest_author_id Guest Author ID.
	 * @param array $filtered_author_cap_fields Filtered Author Cap Fields.
	 *
	 * @return array
	 * @throws WP_CLI\ExitException Thorws an exception if the insert fails.
	 */
	private function ensure_important_cap_meta_fields_exist( int $guest_author_id, array $filtered_author_cap_fields ) {
		global $wpdb;

		$important_keys = [
			'cap-linked_account',
			'cap-user_email',
		];

		foreach ( $important_keys as $key ) {
			if ( ! array_key_exists( $key, $filtered_author_cap_fields ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $wpdb->insert(
					$wpdb->postmeta,
					[
						'post_id'    => $guest_author_id,
						'meta_key'   => $key,
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value' => '',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					]
				);

				if ( false === $result ) {
					WP_CLI::error( "Failed to insert $key postmeta" );
				}

				$filtered_author_cap_fields[$key] = '';
			}
		}

		return $filtered_author_cap_fields;
	}

	private function procure_unique_user_login( $user_login ) {
		$user_by_login         = get_user_by( 'login', $user_login );
		$guest_author_by_login = $this->coauthorsplus_logic->coauthors_plus->get_coauthor_by( 'user_login', $user_login );

		if ( $user_by_login || $guest_author_by_login ) {
			$user_login = $this->ask_prompt( "User login '$user_login' already exists. Please enter a new user login, or (h)alt execution: " );

			if ( 'h' === $user_login ) {
				die();
			}

			return $this->procure_unique_user_login( $user_login );
		}

		return $user_login;
	}


	private function fix_standalone_wp_user_author_term_data( WP_User $user, $term ) {
		echo WP_CLI::colorize( "%BWP_User vs Author Term Fields%n\n" );
		global $wpdb;

		$user_login = $user->user_login;
		if ( is_email( $user_login ) ) {
			echo WP_CLI::colorize( "%YUser login is an email and must be updated: $user_login%n\n" );
			$user_login = $this->procure_unique_user_login(
				substr( $user_login, 0, strpos( $user_login, '@' ) )
			);

			$updated = $wpdb->update(
				$wpdb->users,
				array(
					'user_login' => $user_login,
				),
				array(
					'ID' => $user->ID,
				)
			);

			if ( $updated ) {
				$user->user_login = $user_login;
			}
		}

		$this->high_contrast_output( 'user_login', $user->user_login );
		$this->update_relevant_user_fields_if_necessary( $user );

		$comparison = $this->output_value_comparison_table(
			array(
				'name',
				'slug',
			),
			array(
				'name' => $user->user_login,
				'slug' => 'cap-' . $user->user_nicename,
			),
			array(
				'name' => $term->name,
				'slug' => $term->slug,
			),
			true,
			'WP_User',
			'Author Term'
		);
		if ( ! empty( $comparison['different'] ) ) {
			// Update Post Meta to match WP_User fields
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['Author Term']}%n to %G%U{$value['WP_User']}%n\n" );
				$wpdb->update(
					$wpdb->terms,
					array(
						$key => $value['WP_User'],
					),
					array(
						'term_id' => $term->term_id,
					)
				);
			}
		}

		$this->update_author_description( $user, $term );
	}

	public function cmd_fix_standalone_guest_author_term_data( $args, $assoc_args ) {
		$guest_author_id = $assoc_args['guest-author-id'];
		$term_id         = $assoc_args['term-id'];

		global $wpdb;

		$author_term_taxonomy = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
	                t.term_id,
	                t.name,
	                t.slug,
	                tt.term_taxonomy_id,
	                tt.description
	            FROM $wpdb->terms t 
	            LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
	                WHERE tt.taxonomy = 'author' AND t.term_id = %d",
				$term_id
			)
		);

		$term = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT term_id, name, slug FROM $wpdb->terms WHERE term_id = %d",
				$term_id
			)
		);

		if ( empty( $author_term_taxonomy ) ) {
			if ( empty( $term ) ) {
				echo WP_CLI::colorize( "%YNo term found for term_id: $term_id%n\n" );
				return null;
			} else {
				$this->standalone_guest_author( $guest_author_id, $term );
				return null;
			}
		}

		if ( count( $author_term_taxonomy ) > 1 ) {
			echo WP_CLI::colorize( "%YMore than one author term found for term_id: $term_id%n\n" );
			return null;
		}

		$author_term_taxonomy = $author_term_taxonomy[0];

		$this->handle_fixing_standalone_guest_author_data( $guest_author_id, $author_term_taxonomy );
	}

	private function handle_fixing_standalone_guest_author_data( $guest_author_id, $term ) {
		global $wpdb;

		echo WP_CLI::colorize( "%BGuest Author Record%n\n" );
		$this->output_post_table( array( $guest_author_id ) );
		$post = get_post( $guest_author_id );
		echo WP_CLI::colorize( "%BAuthor Term%n\n" );
		$this->output_terms_table( array( $term->term_id ) );
		$filtered_author_cap_fields = $this->get_filtered_cap_fields(
			$guest_author_id,
			array(
				'cap-user_login',
				'cap-user_email',
				'cap-linked_account',
				'cap-display_name',
			)
		);
		echo WP_CLI::colorize( "%BPost Meta Fields%n\n" );
		$this->output_comparison_table( array(), $filtered_author_cap_fields );

		$display_name = $this->ask_prompt( "Use '{$filtered_author_cap_fields['cap-display_name']}' as display_name? (y/n)" );

		if ( 'y' === $display_name ) {
			$display_name = $filtered_author_cap_fields['cap-display_name'];
		} else {
			$display_name = ucwords( $this->ask_prompt( 'Enter display_name' ) );
		}

		//TODO user_login should not collide with other cap-user_login records. It should also not be similar to any existing user_nicename.
		$sanitized_display_name                   = sanitize_title( $display_name );
		$use_sanitized_display_name_as_user_login = $this->ask_prompt( "Use '$sanitized_display_name' as basis for slug? (y/n)" );
		$use_sanitized_display_name_as_user_login = strtolower( $use_sanitized_display_name_as_user_login );
		if ( 'y' === $use_sanitized_display_name_as_user_login ) {
			$user_login = $sanitized_display_name;
		} else {
			$user_login = $filtered_author_cap_fields['cap-user_login'];

			if ( is_email( $user_login ) ) {
				$user_login = substr( $user_login, 0, strpos( $user_login, '@' ) );
			} else {
				$response = $this->ask_prompt( "Would you like to update user_login ('$user_login')? (y/n)" );
				$response = strtolower( $response );

				if ( 'y' === $response ) {
					$user_login = $this->ask_prompt( 'Enter user_login:' );
				}
			}
		}

		if ( ! str_starts_with( $user_login, 'cap-' ) ) {
			$user_login = "cap-$user_login";
		}

		echo WP_CLI::colorize( "%BUser Login and Display Name vs CAP User Login and Display Name%n\n" );
		$comparison = $this->output_value_comparison_table(
			array(
				'cap-user_login',
				'cap-display_name',
			),
			array(
				'cap-user_login'   => $user_login,
				'cap-display_name' => $display_name,
			),
			array(
				'cap-user_login'   => $filtered_author_cap_fields['cap-user_login'],
				'cap-display_name' => $filtered_author_cap_fields['cap-display_name'] ?? '',
			)
		);
		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['right']}%n to %G%U{$value['left']}%n\n" );
				$filtered_author_cap_fields[ $key ] = $value['left'];
				$wpdb->update(
					$wpdb->postmeta,
					array(
						'meta_value' => $value['left'],
					),
					array(
						'meta_key' => $key,
						'post_id'  => $guest_author_id,
					)
				);
			}
		}

		echo WP_CLI::colorize( "%BName and Title vs WP_Post Name and Title%n\n" );
		$comparison = $this->output_value_comparison_table(
			array(
				'post_name',
				'post_title',
			),
			array(
				'post_name'  => $filtered_author_cap_fields['cap-user_login'],
				'post_title' => $display_name,
			),
			array(
				'post_name'  => $post->post_name,
				'post_title' => $post->post_title,
			)
		);
		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['right']}%n to %G%U{$value['left']}%n\n" );
				$post->$key = $value['left'];
				$wpdb->update(
					$wpdb->posts,
					array(
						$key => $value['left'],
					),
					array(
						'id' => $guest_author_id,
					)
				);
			}
		}

		echo WP_CLI::colorize( "%BCAP Display Name vs Author Term%n\n" );
		$comparison = $this->output_value_comparison_table(
			array(
				'name',
				'slug',
			),
			array(
				'name' => $post->post_title,
				'slug' => $filtered_author_cap_fields['cap-user_login'],
			),
			array(
				'name' => $term->name,
				'slug' => $term->slug,
			)
		);
		if ( ! empty( $comparison['different'] ) ) {
			foreach ( $comparison['different'] as $key => $value ) {
				echo WP_CLI::colorize( "%wUpdating%n %W$key:%n %C{$value['right']}%n to %G%U{$value['left']}%n\n" );
				$wpdb->update(
					$wpdb->terms,
					array(
						$key => $value['left'],
					),
					array(
						'term_id' => $term->term_id,
					)
				);
			}
		}

		wp_cache_flush();
		$this->update_author_description(
			$this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id ),
			$term
		);

		$term_relationship_exists = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->term_relationships WHERE object_id = %d AND term_taxonomy_id = %d",
				$guest_author_id,
				$term->term_taxonomy_id
			)
		);
		$term_relationship_exists = ! empty( $term_relationship_exists );

		if ( ! $term_relationship_exists ) {
			$wpdb->insert(
				$wpdb->term_relationships,
				array(
					'object_id'        => $guest_author_id,
					'term_taxonomy_id' => $term->term_taxonomy_id,
				)
			);
		}
	}

	private function get_author_term_description( $author ) {
		// @see https://github.com/Automattic/Co-Authors-Plus/blob/e9e76afa767bc325123c137df3ad7af169401b1f/php/class-coauthors-plus.php#L1623
		$fields = array(
			'display_name',
			'first_name',
			'last_name',
			'user_login',
			'ID',
			'user_email',
		);

		$values = array();
		foreach ( $fields as $field ) {
			$values[] = $author->$field;
		}

		return implode( ' ', $values );
	}

	private function update_author_description( $user, $term ) {
		$description = $this->get_author_term_description( $user );

		global $wpdb;

		if ( ! isset( $term->description ) ) {
			$term->description = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT description FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d",
					$term->term_taxonomy_id
				)
			);
		}

		if ( $description !== $term->description ) {
			echo WP_CLI::colorize( "%wUpdating%n %Wwp_term_taxonomy.description%n from %C{$term->description}%n to %G%U{$description}%n\n" );
			$wpdb->update(
				$wpdb->term_taxonomy,
				array(
					'description' => $description,
				),
				array(
					'term_taxonomy_id' => $term->term_taxonomy_id,
				)
			);
		}
	}

	public function cmd_fix_incorrect_author_terms( $args, $assoc_args ) {
		global $wpdb;

		$unlinked_author_terms = $wpdb->get_results(
			"SELECT
                t.term_id,
                t.name,
                t.slug,
                tt.term_taxonomy_id,
                tt.description
            FROM $wpdb->terms t 
            LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id 
                WHERE tt.taxonomy = 'author' 
                  AND tt.term_taxonomy_id NOT IN ( 
                    SELECT 
                        tr.term_taxonomy_id 
                    FROM wp_term_relationships tr 
                        LEFT JOIN wp_posts p ON tr.object_id = p.ID 
                    WHERE p.post_type = 'guest-author' 
                  ) AND t.term_id NOT IN (
                      SELECT
                          term_id
                      FROM $wpdb->termmeta 
                      WHERE meta_key = 'updated_by_fix_incorrect_author_terms'
                  )"
		);

		foreach ( $unlinked_author_terms as $author_term ) {
			echo "\n\n\n";
			$this->high_contrast_output( 'wp-term.term_id', $author_term->term_id );
			$this->high_contrast_output( 'wp_term.name', $author_term->name );
			$this->high_contrast_output( 'wp_term.slug', $author_term->slug );
			$this->high_contrast_output( 'wp_term.description', $author_term->description );
			// Confirm that the term does not belong to a Guest Author.
			// If it does, ensure that the guest author and wp_term fields are correct.
			// Insert relationship into wp_term_relationships.
			// If does not belong to a GA, then look for a WP_User with the same email or user ID.
			// If found, ensure that the wp_user and wp_term fields are correct.

			$description_id    = $this->extract_id_from_description( $author_term->description );
			$description_email = $this->extract_email_from_term_description( $author_term->description );
			// $this->high_contrast_output( 'Description ID', $description_id );
			// $this->high_contrast_output( 'Description Email', $description_email );

			$guest_author_post = $this->get_guest_author_post_by_id( $description_id );

			if ( null === $guest_author_post ) {
				echo WP_CLI::colorize( "%wGuest Author not found. Dealing with a WP_User?%n\n" );
				// Confirm slug does not result in wp_post
				$guest_author_by_post_name_query = "SELECT * FROM $wpdb->posts WHERE post_type = 'guest-author' AND post_name = %s";
				$guest_author_records            = $wpdb->get_results(
					$wpdb->prepare(
						$guest_author_by_post_name_query,
						$author_term->slug
					)
				);

				if ( empty( $guest_author_records ) ) {
					// Check one more time, with modified slug
					if ( str_starts_with( $author_term->slug, 'cap-' ) ) {
						$modified_slug = substr( $author_term->slug, 4 );
					} else {
						$modified_slug = 'cap-' . $author_term->slug;
					}

					$guest_author_records = $wpdb->get_results(
						$wpdb->prepare(
							$guest_author_by_post_name_query,
							$modified_slug
						)
					);
				}

				if ( ! empty( $guest_author_records ) ) {
					$count = count( $guest_author_records );
					echo WP_CLI::colorize( "%w$count Guest Author posts found for $author_term->slug.%n\n" );
					// Multiple Guest Author records exist. These need to be merged.
					if ( $count === 1 ) {
						// Guest Author Record exists, but is not tied to the author term.

						$this->fix_author_term_data_from_guest_author(
							$guest_author_records[0]->ID,
							$author_term,
							$this->get_user_from_possible_identifiers( $description_id, $description_email )
						);
					}
				} else {
					echo WP_CLI::colorize( "%wConfirmed no Guest Authors for $author_term->slug.%n\n" );

					$user = $this->get_user_from_possible_identifiers( $description_id, $description_email );

					$cap_linked_accounts = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT 
    								p.ID, 
    								p.post_name, 
    								p.post_type, 
    								p.post_title, 
    								pm.meta_key, 
    								pm.meta_value 
								FROM $wpdb->posts p INNER JOIN $wpdb->postmeta pm ON p.ID = pm.post_id 
								WHERE pm.meta_key = 'cap-linked_account' 
								  AND pm.meta_value = %s",
							$user->user_login
						)
					);

					$count_of_cap_linked_accounts = count( $cap_linked_accounts );

					if ( $count_of_cap_linked_accounts > 1 ) {
						echo WP_CLI::colorize( "%wMultiple Guest Authors found with cap-linked_account = {$user->user_login}.%n\n" );
						// This is a problem. We need to figure out which one is correct.
						var_dump( $cap_linked_accounts );
						echo 'Halting execution';
						die();
					} elseif ( $count_of_cap_linked_accounts === 1 ) {
						// This means that this guest author is tied to a WP_User, but the wp_term_relationships table doesn't have the record for this guest author record
						echo WP_CLI::colorize( "%wConfirmed one Guest Author with cap-linked_account = {$user->user_login}.%n\n" );
						$this->high_contrast_output( 'wp_posts.ID', $cap_linked_accounts[0]->ID );
						$this->high_contrast_output( 'wp_posts.post_name', $cap_linked_accounts[0]->post_name );
						$this->high_contrast_output( 'wp_posts.post_type', $cap_linked_accounts[0]->post_type );

						// Needs to go through process where WP_User and Guest Author data is handled
						$this->fix_author_term_data_from_guest_author( $cap_linked_accounts[0]->ID, $author_term, $user );
						$wpdb->insert(
							$wpdb->termmeta,
							array(
								'term_id'    => $author_term->term_id,
								'meta_key'   => 'updated_by_fix_incorrect_author_terms',
								'meta_value' => 1,
							)
						);
						continue;
					} else {
						echo WP_CLI::colorize( "%wConfirmed no Guest Authors with cap-linked_account = {$user->user_login}.%n\n" );
					}

					// This is only a WP_User and an Author Term which need to be verified to ensure data points match
					$this->fix_standalone_wp_user_author_term_data( $user, $author_term );
					$wpdb->insert(
						$wpdb->termmeta,
						array(
							'term_id'    => $author_term->term_id,
							'meta_key'   => 'updated_by_fix_incorrect_author_terms',
							'meta_value' => 1,
						)
					);
				}
			} else {
				echo WP_CLI::colorize( "%wGuest Author found.%n\n" );
				// We have a Guest Author, check if GA is linked to WP_User
				$cap_linked_account = $this->get_filtered_cap_fields( $guest_author_post->ID, array( 'cap-linked_account' ) );

				if ( $cap_linked_account ) {
					echo WP_CLI::colorize( "%wGuest Author is linked to a WP_User.%n\n" );
					$this->high_contrast_output( 'cap-linked_account', $cap_linked_account['cap-linked_account'] );
					// Check that the necessary fields are correct along with WP_User fields
					$this->fix_author_term_data_from_guest_author(
						$guest_author_post->ID,
						$author_term,
						get_user_by( 'user_login', $cap_linked_account['cap-linked_account'] )
					);
					$wpdb->insert(
						$wpdb->termmeta,
						array(
							'term_id'    => $author_term->term_id,
							'meta_key'   => 'updated_by_fix_incorrect_author_terms',
							'meta_value' => 1,
						)
					);
				} else {
					echo WP_CLI::colorize( "%wGuest Author is not linked to a WP_User.%n\n" );
					// This is likely a standalone Guest Author, check that the necessary fields are correct.
					// Check email just to be safe though.

					$user_by_email = get_user_by( 'email', $description_email );

					if ( $user_by_email ) {
						echo WP_CLI::colorize( "%wWP_User found via email.%n\n" );
						// Check that necessary fields are correct along with WP_User fields
						$this->coauthorsplus_logic->link_guest_author_to_wp_user( $guest_author_post->ID, $user_by_email );
						$this->fix_author_term_data_from_guest_author( $guest_author_post->ID, $author_term, $user_by_email );
					} else {
						// This is definitely a standalone Guest Author, check that the necessary fields are correct.
						$this->handle_fixing_standalone_guest_author_data( $guest_author_post->ID, $author_term );
					}
					$wpdb->insert(
						$wpdb->termmeta,
						array(
							'term_id'    => $author_term->term_id,
							'meta_key'   => 'updated_by_fix_incorrect_author_terms',
							'meta_value' => 1,
						)
					);
				}
			}
		}
	}

	/**
	 * This function will pull the entire list of guest authors in the database, and check that all their
	 * data is correct. From post_title, to post_name, to all the necessary CAP related meta fields,
	 * and finally ensuring that the author taxonomy is set up correctly and not colliding
	 * with other authors.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_validate_guest_authors( $args, $assoc_args ) {
		global $wpdb;

		$date                   = date( 'Y-m-d' );
		$job_status_key_name    = 'validate_guest_authors_job_status';
		$job_details_key_name   = 'validate_guest_authors_job_details';
		$job_status_option_key  = "{$date}_{$job_status_key_name}"; // Started, Completed, Cancelled.
		$job_details_option_key = "{$date}_{$job_details_key_name}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$current_job_status = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s",
				$job_status_option_key
			)
		);
		$current_job_details = [
			'total'                          => 0,
			'completed'                      => 0,
			'not_validated'                  => 0,
			'next_guest_author_id'           => 0,
			'completed_guest_author_ids'     => [],
			'not_validated_guest_author_ids' => [],
		];

		if ( 'COMPLETED' === $current_job_status ) {
			// Update keys in DB to append a run number.
			$no_of_completed_jobs = intval(
				$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE %s AND option_value = 'COMPLETED'",
					$wpdb->esc_like( $job_status_option_key ) . '%'
					)
				)
			);
			$completed_job_number             = max( $no_of_completed_jobs, 1 );
			$completed_job_status_option_key  = "{$job_status_option_key}_{$completed_job_number}";
			$completed_job_details_option_key = "{$job_details_option_key}_{$completed_job_number}";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				[
					'option_name' => $completed_job_status_option_key,
				],
				[
					'option_name' => $job_status_option_key,
				]
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->options,
				[
					'option_name' => $completed_job_details_option_key,
				],
				[
					'option_name' => $job_details_option_key,
				]
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->postmeta,
				[
					'meta_key' => $completed_job_status_option_key,
				],
				[
					'meta_key' => $job_status_option_key,
				]
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $job_status_option_key,
					'option_value' => 'STARTED',
					'autoload'     => 'no'
				]
			);
			$current_job_status = 'STARTED';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $job_details_option_key,
					'option_value' => serialize( $current_job_details ),
					'autoload'     => 'no',
				]
			);
		} elseif ( 'STARTED' === $current_job_status ) {
			$current_job_details = maybe_unserialize(
				$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT option_value FROM $wpdb->options WHERE option_name = %s",
						$job_details_option_key
					)
				)
			);
		} else {
			// Start a new job.
			update_option( $job_status_option_key, 'STARTED', false );
			$current_job_status = 'STARTED';
			update_option( $job_details_option_key, $current_job_details, false );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $wpdb->options SET option_value = 'CANCELLED' WHERE option_name NOT LIKE %s AND option_value = 'STARTED'",
				$wpdb->esc_like( $job_status_option_key ) . '%'
			)
		);

		// TODO add a param to process skipped records. The query would need an update for the postmeta to find only skipped records.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$guest_author_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM $wpdb->posts p 
    				LEFT JOIN (SELECT * FROM $wpdb->postmeta WHERE meta_key = %s) pm 
    					ON p.ID = pm.post_id 
            	WHERE p.post_type = 'guest-author'
            	  AND pm.meta_value IS NULL 
            	ORDER BY p.ID",
				$job_status_option_key,
			)
		);

		if ( 0 === $current_job_details['total'] ) {
			$current_job_details['total'] = count( $guest_author_ids );
		}
		$current_job_details['next_guest_author_id'] = $guest_author_ids[0];
		update_option( $job_details_option_key, $current_job_details, false );

//		$guest_author_ids = array_slice( $guest_author_ids, 0, 50 );

		foreach ( $guest_author_ids as $key => $guest_author_id ) {
			if ( array_key_exists( $guest_author_id, $current_job_details['not_validated_guest_author_ids'] ) ) {
				continue;
			}
			$number = $current_job_details['completed'] + $current_job_details['not_validated'] + 1;
			echo "\n\n\n\n$number out of {$current_job_details['total']}\n\n";
			$current_job_details['next_guest_author_id'] = $guest_author_ids[ $key + 1 ] ?? null;
			update_option( $job_details_option_key, $current_job_details, false );
			$validated = true;
			$validation_issues = [];

			$this->output_post_table( array( $guest_author_id ) );
			$meta_data = $this->output_postmeta_table( $guest_author_id );
			$cap_fields = $this->get_filtered_cap_fields( $guest_author_id, array_column( $meta_data, 'meta_key' ) );

			// Search for email in users table.
			$user_by_email = null;
			if ( isset( $cap_fields['cap-user_email'] ) ) {
				if ( is_array( $cap_fields['cap-user_email'] ) ) {
					echo WP_CLI::colorize( "%rMultiple emails found for Guest Author ID: $guest_author_id%n\n" );
					$validated = false;
					$validation_issues[] = [
						'description' => 'Multiple emails found',
						'data' => $cap_fields['cap-user_email']
					];
				} else {
					$user_by_email = get_user_by( 'email', $cap_fields['cap-user_email'] );
				}
			} else {
				echo WP_CLI::colorize( "%rNo email found for Guest Author ID: $guest_author_id%n\n" );
				$validated = false;
				$validation_issues[] = [
					'description' => 'No email found.',
					'data' => null,
				];
			}

			// Search for user_login in users table.
			$user_by_login = null;
			if ( isset( $cap_fields['cap-linked_account'] ) ) {
				if ( is_array( $cap_fields['cap-linked_account'] ) ) {
					echo WP_CLI::colorize( "%MMultiple linked_accounts found for Guest Author ID: $guest_author_id%n\n" );

					foreach ( $cap_fields['cap-linked_account'] as $meta_id => $cap_linked_account_value ) {
						if ( empty( $cap_linked_account_value ) ) {
							$delete = $wpdb->delete(
								$wpdb->postmeta,
								[
									'meta_id' => $meta_id,
								]
							);

							if ( false === $delete ) {
								echo WP_CLI::colorize( "%rFailed to delete duplicate and empty cap-linked_account meta_id: $meta_id%n\n" );
							} else {
								echo WP_CLI::colorize( "%yDeleted duplicate and empty cap-linked_account meta_id: $meta_id%n\n" );
								unset( $cap_fields['cap-linked_account'][ $meta_id ] );
							}
						}
					}

					$extra_cap_linked_accounts = array_diff_assoc( $cap_fields['cap-linked_account'], array_unique( $cap_fields['cap-linked_account'] ) );

					foreach ( $extra_cap_linked_accounts as $meta_id => $extra_cap_linked_account ) {
						$delete = $wpdb->delete(
							$wpdb->postmeta,
							[
								'meta_id' => $meta_id,
							]
						);

						if ( false === $delete ) {
							echo WP_CLI::colorize( "%rFailed to delete duplicate cap-linked_account meta_id: $meta_id%n\n" );
						} else {
							echo WP_CLI::colorize( "%yDeleted duplicate cap-linked_account meta_id: $meta_id%n\n" );
							unset( $cap_fields['cap-linked_account'][ $meta_id ] );
						}
					}

					if ( count( $cap_fields['cap-linked_account'] ) > 1 ) {
						$validated           = false;
						$validation_issues[] = [
							'description' => 'Multiple linked_accounts found',
							'data'        => $cap_fields['cap-linked_account'],
						];
					} else {
						WP_CLI::colorize( "%cResolved duplicate linked_accounts%n\n" );
						$user_by_login = get_user_by( 'login', $cap_fields['cap-linked_account'][0] );
					}
				} else {
					$user_by_login = get_user_by( 'login', $cap_fields['cap-linked_account'] );
				}
			} else {
				echo WP_CLI::colorize( "%yNo cap-linked_account field found.%n\n" );
			}

			$user = $this->choose_between_users( $user_by_login, $user_by_email, 'Linked Account' );

			if ( $user instanceof WP_User ) {
				$this->output_users_as_table( [ $user ] );
			}

			$author_terms = $this->get_author_term_from_guest_author_id( $guest_author_id );

			if ( empty( $author_terms ) && isset( $cap_fields['cap-user_login'] ) && ! is_array( $cap_fields['cap-user_login'] ) ) {
				// Search for corresponding Author Term using cap-user_login.
				$author_terms = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT 
							t.term_id, 
							t.name, 
							t.slug, 
							tt.term_taxonomy_id,
							tt.taxonomy, 
							tt.description
							FROM $wpdb->terms t LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id WHERE t.slug = %s OR tt.description LIKE %s",
						$cap_fields['cap-user_login'],
						'%' . $wpdb->esc_like( $cap_fields['cap-user_login'] ) . '%'
					)
				);

				if ( ! empty( $author_terms ) && 1 === count( $author_terms ) ) {
					WP_CLI\Utils\format_items(
						'table',
						$author_terms,
						array_keys( (array) $author_terms[0] )
					);

					$link_exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT * FROM $wpdb->term_relationships tr LEFT JOIN $wpdb->posts p ON tr.object_id = p.ID WHERE p.post_type = 'guest-author' AND tr.term_taxonomy_id = %d",
							$author_terms[0]->term_taxonomy_id
						)
					);

					if ( $link_exists ) {
						echo WP_CLI::colorize( "%yAuthor Term is already linked to a Guest Author.%n\n" );
						$author_terms = [];
					} else {
						$choice = Streams::choose( 'Should this term be linked to the Guest Author?',
							array( 'y', 'n' ),
							'y' );

						if ( 'y' === $choice ) {
							$author_term = $author_terms[0];
							$wpdb->insert(
								$wpdb->term_relationships,
								array(
									'object_id' => $guest_author_id,
									'term_taxonomy_id' => $author_term->term_taxonomy_id,
								)
							);
						}
					}
				}
			}


			if ( empty( $author_terms ) ) {
				echo WP_CLI::colorize( "%rNo linked author term found for Guest Author ID: $guest_author_id%n\n" );
				$validated = false;
				$validation_issues[] = [
					'description' => 'No linked author term found',
					'data' => null,
				];

				$email = $cap_fields['cap-user_email'] ?? $user->user_email ?? null;
				$terms = [];
				if ( $email ) {
					// Do a like search on taxonomy.description using email.
					$terms = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT 
							t.term_id, 
							t.name, 
							t.slug, 
							tt.term_taxonomy_id,
							tt.taxonomy, 
							tt.description
							FROM $wpdb->terms t LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id WHERE tt.description LIKE %s OR t.name LIKE %s",
							'%' . $wpdb->esc_like( $email ) . '%',
							'%' . $wpdb->esc_like( $email ) . '%'
						)
					);
				}

				if ( ! empty( $terms ) ) {
					foreach ( $terms as $term ) {
						$termmeta_key = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT meta_key FROM $wpdb->termmeta WHERE term_id = %d",
								$term->term_id
							)
						);

						$term->meta_key = $termmeta_key;
					}

					echo WP_CLI::colorize( "%BFound Possible Author Terms Based on Like Search%n\n" );
					WP_CLI\Utils\format_items(
						'table',
						$terms,
						array_keys( (array) $terms[0] )
					);
				}

			} else {
				echo WP_CLI::colorize( "%BAuthor Terms Table%n\n" );
				if ( count( $author_terms ) > 1 ) {
					$validated = false;
					$validation_issues[] = [
						'description' => 'Multiple author terms found',
						'data' => $author_terms,
					];
				}

				WP_CLI\Utils\format_items(
					'table',
					$author_terms,
					array_keys( (array) $author_terms[0] )
				);
			}

			if ( isset( $cap_fields['cap-linked_account'] ) && ! is_array( $cap_fields['cap-linked_account'] ) ) {
				if ( $user instanceof WP_User ) {
					if ( $user->user_login !== $cap_fields['cap-linked_account'] ) {
						echo WP_CLI::colorize( "%rMismatch between cap-linked_account and user_login for Guest Author ID: $guest_author_id%n\n" );
						$validated = false;
						$validation_issues[] = [
							'description' => 'Mismatch between cap-linked_account and user_login',
							'data' => [
								'cap-linked_account' => $cap_fields['cap-linked_account'],
								'user_login' => $user->user_login,
							],
						];
					}
				}
			} elseif ( $user instanceof WP_User && ! isset( $cap_fields['cap-linked_account'] ) ) {
				// Update cap-linked_account
				$choice = Streams::choose( 'Link User to Guest Author', array( 'y', 'n' ), 'y' );
				if ( 'y' === $choice ) {
					update_post_meta( $guest_author_id, 'cap-linked_account', $user->user_login );
				}
			}

			if ( empty( $cap_fields['cap-user_login'] ) ) {
				$validated = false;
				$validation_issues[] = [
					'description' => 'cap-user_login is not set',
					'data' => $cap_fields['cap-user_login'],
				];
			} elseif ( isset( $cap_fields['cap-user_login'] ) && is_array( $cap_fields['cap-user_login'] ) ) {
				$validated = false;
				$validation_issues[] = [
					'description' => 'Multiple cap-user_login fields found.',
					'data' => $cap_fields['cap-user_login'],
				];
			} elseif ( isset( $cap_fields['cap-user_login'] ) && is_email( $cap_fields['cap-user_login'] ) ) {
				$validated = false;
				$validation_issues[] = [
					'description' => 'cap-user_login is an email address',
					'data' => $cap_fields['cap-user_login'],
				];
			}

			$post = get_post( $guest_author_id );

			if ( isset( $cap_fields['cap-user_login'] ) && ! is_array( $cap_fields['cap-user_login'] ) ) {
				$term_slug = '';

				if ( ! empty( $author_terms ) && 1 === count( $author_terms ) ) {
					$term_slug = $author_terms[0]->slug;
				}

				$this->output_comparison_table(
					[],
					[
						'cap-user_login' => $cap_fields['cap-user_login'],
						'post_name'      => $post->post_name,
						'term_slug'      => $term_slug,
					]
				);

				if ( $post->post_name !== $term_slug ) {
					$validated = false;
					$validation_issues[] = [
						'description' => 'post_name does not match author term slug',
						'data' => [
							'post_name' => $post->post_name,
							'term_slug' => $term_slug,
						],
					];
				} else {
					// Here we know that wp_post.post_name and wp_terms.slug are equal.
					// So only need to verify that one of those are equal with cap-user_login.
					// It also doesn't matter if cap-user_login begins with `cap-` or not,
					// so we'll remove it from both strings before comparing.
					$cap_user_login = $cap_fields['cap-user_login'];
					$cap_user_login = str_replace( 'cap-', '', $cap_user_login );
					$post_name      = $post->post_name;
					$post_name	    = str_replace( 'cap-', '', $post_name );

					if ( $cap_user_login !== $post_name ) {
						$validated           = false;
						$validation_issues[] = [
							'description' => 'cap-user_login, post_name, and author term slug do not match (when `cap-` is removed)',
							'data'        => [
								'cap-user_login' => $cap_fields['cap-user_login'],
								'post_name'      => $post_name,
								'term_slug'      => $term_slug,
							],
						];
					}
				}
			}


			if ( $validated ) {
				if ( $user ) {
					// Here we have a GA and a User who are properly linked.
					// Now we just want to confirm there hasn't been a false-positive in terms of validation.

					if ( is_email( $user->user_nicename ) ) {
						$validated = false;
						$validation_issues[] = [
							'description' => 'user_nicename is an email address',
							'data' => $user->user_nicename,
						];
					}

					//Confirm User Nicename is correct
					$validated_user = $this->validate_user_name_fields( $user );

					if ( $validated_user->user_login !== $user->user_login ) {
						$validated = false;
						$validation_issues[] = [
							'description' => 'user_login likely needs to be updated.',
							'data' => [
								'validated_user' => $validated_user->data,
								'user' => $user->data,
							],
						];
					}

					$response = wp_remote_get( "https://www.lasillavacia.com/author/{$post->post_name}/" );
					$author_page_exists = ! str_contains( $response['body'], '<h1 class="page-title">Archivos:</h1>' );
					if ( $author_page_exists ) {
						// The page does not contain archivos, but let's also make sure it doesn't contain this
						// custom 404 message.
						$author_page_exists = ! str_contains( strtolower( $response['body'] ), '¡vaya! no se puede encontrar esa' );
					}

					if ( ! $author_page_exists && $validated_user->user_nicename !== $user->user_nicename ) {
						$validated           = false;
						$validation_issues[] = [
							'description' => 'user_nicename likely needs to be updated.',
							'data'        => [
								'validated_user' => $validated_user->data,
								'user'           => $user->data,
							],
						];
					}

					// This check is not really necessary if the author page exists and does not lead to an archive page.
					// This is because the main things that need to be equal, term_slug and post_name, are already equal.
					if ( ! $author_page_exists ) {
						$cap_user_login = $this->get_guest_author_user_login( $validated_user );

						if ( $cap_user_login !== $cap_fields['cap-user_login'] ) {
							$validated = false;
							$validation_issues[] = [
								'description' => 'cap-user_login likely needs to be updated to correspond to a WP_User which may or may not be linked.',
								'data' => [
									'cap_user_login' => $cap_user_login,
									'cap_fields' => $cap_fields,
								],
							];
						}
					}

//					if ( $validated ) {
						$existing_slugs = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT t.term_id, tt.term_taxonomy_id, t.slug, tt.description 
					FROM $wpdb->terms t 
    					LEFT JOIN $wpdb->term_taxonomy tt 
    					    ON t.term_id = tt.term_id 
         			WHERE tt.taxonomy = 'author' AND tt.term_taxonomy_id <> %d AND t.slug = %s",
								$author_terms[0]->term_taxonomy_id,
								$cap_user_login
							)
						);

						if ( ! empty( $existing_slugs ) ) {
							$validated = false;
							$validation_issues[] = [
								'description' => 'cap-user_login is already in use.',
								'data' => [
									'existing_slugs' => $existing_slugs,
									'cap_fields' => $cap_fields,
								],
							];
						}
//					}

//					if ( $validated ) {
						if ( $cap_fields['cap-display_name'] !== $post->post_title ) {
							$validated = false;
							$validation_issues[] = [
								'description' => 'cap-display_name does not match post_title',
								'data' => [
									'cap_display_name' => $cap_fields['cap-display_name'],
									'post_title' => $post->post_title,
								],
							];
						}
//					}

					if ( $cap_fields['cap-user_email'] !== $validated_user->user_email ) {
						$validated = false;
						$validation_issues[] = [
							'description' => 'cap-user_email does not match user_email',
							'data' => [
								'cap_user_email' => $cap_fields['cap-user_email'],
								'user_email' => $validated_user->user_email,
							],
						];
					}

					if ( ! isset( $cap_fields['cap-linked_account'] ) ) {
						$validated = false;
						$validation_issues[] = [
							'description' => 'cap-linked_account is not set even though a WP_User exists which is likely related',
							'data' => null,
						];
					} elseif ( $cap_fields['cap-linked_account'] !== $validated_user->user_login ) {
						$validated = false;
						$validation_issues[] = [
							'description' => 'cap-linked_account does not match user_login',
							'data' => [
								'cap_linked_account' => $cap_fields['cap-linked_account'],
								'user_login' => $validated_user->user_login,
							],
						];
					}

				} else {

//					if ( $validated ) {

						if ( $cap_fields['cap-display_name'] !== $post->post_title ) {
							$validated = false;
							$validation_issues[] = [
								'description' => 'cap-display_name does not match post_title',
								'data' => [
									'cap_display_name' => $cap_fields['cap-display_name'],
									'post_title' => $post->post_title,
								],
							];
						}

						/*if ( $post->post_title !== $author_terms[0]->name ) {
							$validated = false;
							$validation_issues[] = [
								'description' => 'post_title does not match author term name',
								'data' => [
									'post_title' => $post->post_title,
									'author_term_name' => $author_terms[0]->name,
								],
							];
						}*/
//					}
				}
			}

			if ( $validated ) {
				update_post_meta( $guest_author_id, $job_status_option_key, 'validated' );
				if ( ! array_key_exists( $guest_author_id, $current_job_details['completed_guest_author_ids'] ) ) {
					$current_job_details['completed']++;
					$current_job_details['completed_guest_author_ids'][$guest_author_id] = $guest_author_id;
				}

				if ( array_key_exists( $guest_author_id, $current_job_details['not_validated_guest_author_ids'] ) ) {
					unset( $current_job_details['not_validated_guest_author_ids'][$guest_author_id] );
					$current_job_details['not_validated']--;
				}
				update_option( $job_details_option_key, $current_job_details, false );
				echo WP_CLI::colorize( "%GVALIDATED%n\n" );
			} else {
				echo WP_CLI::colorize( "%rNOT VALIDATED%n\n" );
				if ( ! array_key_exists( $guest_author_id, $current_job_details['not_validated_guest_author_ids'] ) ) {
					$current_job_details['not_validated']++;
					$current_job_details['not_validated_guest_author_ids'][$guest_author_id] = $guest_author_id;
				}

				if ( array_key_exists( $guest_author_id, $current_job_details['completed_guest_author_ids'] ) ) {
					unset( $current_job_details['completed_guest_author_ids'][$guest_author_id] );
					$current_job_details['completed']--;
				}

				update_post_meta( $guest_author_id, $job_details_option_key, wp_json_encode( $validation_issues ) );

				foreach ( $validation_issues as $issue ) {
					echo WP_CLI::colorize( "%r{$issue['description']}%n\n" );
					if ( $issue['data'] ) {
						var_dump( $issue['data'] );
					}
				}
			}
		}

		update_option( $job_status_option_key, 'COMPLETED', false );
	}

	private function get_user_from_possible_identifiers( int $user_id, string $user_email ) {
		$user_by_id    = get_user_by( 'id', $user_id );
		$user_by_email = get_user_by( 'email', $user_email );

		if ( $user_by_id ) {
			echo WP_CLI::colorize( "%gWP_User found via ID.%n\n" );
		} else {
			$user_by_id->ID = PHP_INT_MIN;
		}

		if ( $user_by_email ) {
			echo WP_CLI::colorize( "%gWP_User found via email.%n\n" );
		} else {
			$user_by_email->ID = PHP_INT_MAX;
		}

		if ( $user_by_id->ID === $user_by_email->ID ) {
			echo WP_CLI::colorize( "%wWP_User by ID and by email match.%n\n" );
		} else {
			echo WP_CLI::colorize( "%rWP_User by ID and by email do not match.%n\n" );
			// This is a problem. We need to figure out which one is correct.
			echo 'Halting execution';
			die();
		}

		// If $user_by_id and $user_by_email are not the same user, then execution would be halted. So it is safe to do this.
		return $user_by_id ?: $user_by_email;
	}

	private function insert_guest_author_term_relationship( int $guest_author_id, int $term_taxonomy_id ) {
		global $wpdb;

		// Double confirm that the term_taxonomy_id doesn't already have a guest-author tied to it
		$guest_author_posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
    				p.ID as post_id, 
    				p.post_name,
    				p.post_title,
    				p.post_type, 
    				tr.term_taxonomy_id 
				FROM $wpdb->term_relationships tr LEFT JOIN $wpdb->posts p ON tr.object_id = p.ID 
				WHERE p.post_type = 'guest-author' AND tr.term_taxonomy_id = %d",
				$term_taxonomy_id
			)
		);

		if ( ! empty( $guest_author_posts ) ) {
			echo WP_CLI::colorize( "%RInsertion of new Guest Author Relationship failed because one already exists for term_taxonomy_id:%n %R%U$term_taxonomy_id%n\n" );
			foreach ( $guest_author_posts as $guest_author_post ) {
				$this->high_contrast_output( 'Post ID', $guest_author_post->post_id );
				$this->high_contrast_output( 'Post name', $guest_author_post->post_name );
				$this->high_contrast_output( 'Post Title', $guest_author_post->post_title );
				$this->high_contrast_output( 'Post Type', $guest_author_post->post_type );
			}

			return null;
		}

		$term_relationship_insert = $wpdb->insert(
			$wpdb->term_relationships,
			array(
				'object_id'        => $guest_author_id,
				'term_taxonomy_id' => $term_taxonomy_id,
				'term_order'       => 0,
			)
		);

		if ( $term_relationship_insert ) {
			echo WP_CLI::colorize( "%wInserted wp_term_relationship record: (object_id: %W{$guest_author_id}%n, term_taxonomy_id: %W{$term_taxonomy_id}%n)%n\n" );
			return true;
		} else {
			echo WP_CLI::colorize( "%rUnable to insert wp_term_relationship record.%n\n" );
			return false;
		}
	}

	/**
	 * This function handles validating whether a User's user_nicename, display_name, and user_login
	 * fields are correctly set. If they are not, then it will update the fields accordingly.
	 *
	 * @param WP_User $user
	 *
	 * @return void
	 */
	private function update_relevant_user_fields_if_necessary( WP_User $user ) {
		global $wpdb;

		$validated_user = $this->validate_user_name_fields( $user );
		$comparison = $this->output_value_comparison_table(
			[],
			$user->to_array(),
			$validated_user->to_array(),
			true,
			'Original User',
			'Validated User'
		);

		foreach ( $comparison['different'] as $key => $value ) {
			echo WP_CLI::colorize( "%wUpdating%n %W$key%n %wfrom%n %C{$user->$key}%n %wto%n %C%U{$validated_user->$key}%n:  " );
			$user->$key = $validated_user->$key;
			$update = $wpdb->update(
				$wpdb->users,
				[
					$key => $value['Validated User'],
				],
				[
					'ID' => $user->ID,
				]
			);

			if ( false === $update ) {
				echo WP_CLI::colorize( "%RFailed%n\n" );
			} else {
				echo WP_CLI::colorize( "%GSuccess%n\n" );
			}
		}
	}

	public function insert_author_taxonomy_record( $author, int $term_id ) {
		$coauthor_slug = $author->user_nicename;

		if ( ! str_starts_with( $coauthor_slug, 'cap-' ) ) {
			$coauthor_slug = "cap-$coauthor_slug";
		}

		global $wpdb;
		$slug_exists = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->terms WHERE slug = %s",
				$coauthor_slug
			)
		);

		if ( ! empty( $slug_exists ) ) {
			if ( count( $slug_exists ) > 1 || $slug_exists[0]->term_id != $term_id ) {
				echo WP_CLI::colorize( "%YSlug already exists%n\n" );
				return $slug_exists;
			}
		}

		$is_tied_to_author_taxonomy = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->term_taxonomy WHERE term_id = %d AND taxonomy = 'author'",
				$term_id
			)
		);

		if ( ! empty( $is_tied_to_author_taxonomy ) ) {
			echo WP_CLI::colorize( "%YTerm already tied to author taxonomy%n\n" );
			return $is_tied_to_author_taxonomy;
		}

		$result = $wpdb->insert(
			$wpdb->term_taxonomy,
			array(
				'term_id'     => $term_id,
				'taxonomy'    => 'author',
				'description' => $this->get_author_term_description( $author ),
				'parent'      => 0,
				'count'       => 0,
			)
		);

		if ( $result ) {
			return $wpdb->get_var(
				$wpdb->prepare(
					"SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE term_id = %d AND taxonomy = 'author'",
					$term_id
				)
			);
		} else {
			return false;
		}
	}

	public function cmd_fix_loose_author_terms( $args, $assoc_args ) {
		global $wpdb;

		$loose_author_terms = $wpdb->get_results(
			"SELECT 
    			t.term_id, 
    			t.name, 
    			t.slug, 
    			tt.taxonomy, 
    			tt.term_taxonomy_id
			FROM $wpdb->terms t
			         LEFT JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
			WHERE SUBSTR( t.slug, 1, 4 ) = 'cap-' 
			  AND tt.term_taxonomy_id IS NULL
			  AND t.term_id NOT IN (
			      SELECT term_id FROM $wpdb->termmeta WHERE meta_key IN ( 'updated_by_loose_terms_script', 'skipped_by_loose_terms_script' )
			  )"
		);

		$total_records = count( $loose_author_terms );
		echo "\n\n";
		echo WP_CLI::colorize( "%wFound%n %C$total_records%n %wloose author terms.%n\n" );

		foreach ( $loose_author_terms as $index => $loose_author_term ) {
			$curr = $total_records - $index;
			echo "\n\n***** $curr ****\n\n";
			// $this->high_contrast_output( 'wp_terms.term_id', $loose_author_term->term_id );
			// $this->high_contrast_output( 'wp_terms.name', $loose_author_term->name );
			// $this->high_contrast_output( 'wp_terms.slug', $loose_author_term->slug );
			WP_CLI\Utils\format_items(
				'table',
				array( $loose_author_term ),
				array( 'term_id', 'name', 'slug' )
			);

			$guest_author = $this->get_guest_author_from_post_name( $loose_author_term->slug );

			// There might be a Guest Author that has different post_name somehow, but is tied to an email.
			if ( is_email( $loose_author_term->name ) ) {
				$potentially_same_guest_authors = $this->get_guest_authors_using_email( $loose_author_term->name );
				if ( null !== $guest_author ) {
					$potentially_same_guest_authors = array_filter(
						$potentially_same_guest_authors,
						function ( $psga ) use ( $guest_author ) {
							return $psga->post_id !== $guest_author->ID;
						}
					);
				}

				if ( ! empty( $potentially_same_guest_authors ) ) {
					echo WP_CLI::colorize( "%YFound%n %C" . count( $potentially_same_guest_authors ) . "%n %Ypotentially same Guest Authors.%n\n" );
					foreach ( $potentially_same_guest_authors as $psga ) {
						$this->output_post_table( [ $psga->post_id ] );
						$this->output_postmeta_table( $psga->post_id );
					}
				}
			}

			if ( null !== $guest_author ) {
				$this->output_post_table( array( $guest_author->ID ) );
				$filtered_post_meta = $this->get_filtered_cap_fields(
					$guest_author->ID,
					array(
						'cap-user_login',
						'cap-user_email',
						'cap-linked_account',
						'cap-display_name',
					)
				);
				$this->output_comparison_table( array(), $filtered_post_meta );

				$user_by_login = get_user_by( 'login', $loose_author_term->name );
				echo WP_CLI::colorize( "%BUser by login%n %w(%n%W$loose_author_term->name%n%w)%n\n" );
				$this->output_users_as_table( array( $user_by_login ) );

				$user_by_email = null;
				if ( is_email( $loose_author_term->name ) ) {
					$user_by_email = get_user_by( 'email', $loose_author_term->name );
					echo WP_CLI::colorize( "%BUser by email%n %w(%n%W$loose_author_term->name%n%w)%n\n" );
					$this->output_users_as_table( array( $user_by_email ) );
				}

				$user_by_cap_linked_account = null;
				if ( array_key_exists( 'cap-linked_account', $filtered_post_meta ) && ! empty( $filtered_post_meta['cap-linked_account'] ) ) {
					$user_by_cap_linked_account = get_user_by( 'login', $filtered_post_meta['cap-linked_account'] );
					echo WP_CLI::colorize( "%BUser by cap-linked_account%n %w(%n%W{$filtered_post_meta['cap-linked_account']}%n%w)%n\n" );
					$this->output_users_as_table( array( $user_by_cap_linked_account ) );
				}

				$term_taxonomy_ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id = %d",
						$guest_author->ID
					)
				);

				if ( empty( $term_taxonomy_ids ) ) {
					$term_taxonomy_ids = array();

					if ( isset( $filtered_post_meta['cap-user_login'] ) ) {
						$term_taxonomy_ids = array_merge(
							$term_taxonomy_ids,
							$this->search_for_taxonomies_with_descriptions_like( $filtered_post_meta['cap-user_login'] )
						);
					}

					if ( isset( $filtered_post_meta['cap-user_email'] ) ) {
						$term_taxonomy_ids = array_merge(
							$term_taxonomy_ids,
							$this->search_for_taxonomies_with_descriptions_like( $filtered_post_meta['cap-user_email'] )
						);
					}

					if ( isset( $filtered_post_meta['cap-display_name'] ) ) {
						$term_taxonomy_ids = array_merge(
							$term_taxonomy_ids,
							$this->search_for_taxonomies_with_display_name( $filtered_post_meta['cap-display_name'] )
						);
					}

					$term_taxonomy_ids = array_unique( $term_taxonomy_ids, SORT_NUMERIC );

					$user = $this->choose_between_users(
						$this->choose_between_users( $user_by_login, $user_by_email ),
						$user_by_cap_linked_account,
						'Login or Email',
						'CAP Linked Account'
					);

					if ( empty( $term_taxonomy_ids ) ) {
						// if this is STILL empty, then there is no term_taxonomy_id to tie to the term and guest author.

						if ( $user instanceof WP_User ) {
							$result = $this->insert_author_taxonomy_record(
								$this->coauthorsplus_logic->get_guest_author_by_id( $guest_author->ID ),
								$loose_author_term->term_id
							);

							if ( is_numeric( $result ) ) {
								$loose_author_term->term_taxonomy_id = $result;
								$this->fix_author_term_data_from_guest_author( $guest_author->ID, $loose_author_term, $user );
							} else {
								echo WP_CLI::colorize( "%rUnable to insert author taxonomy record.%n\n" );
								var_dump( $result );
							}
						} else {
							$this->standalone_guest_author( $guest_author->ID, $loose_author_term );
						}
						$this->confirm_ok_to_proceed( $loose_author_term->term_id );
						continue;
					} else {
						$this->output_term_taxonomy_table( $term_taxonomy_ids );

						if ( $user instanceof WP_User ) {
							$command = "wp newspack-content-migrator la-silla-vacia-fix-user-guest-author-term-data --guest-author-id=$guest_author->ID --term-id=$loose_author_term->term_id --user-id=$user->ID";

							$prompt = $this->ask_prompt( "Would you like to run the following command? (y/n): %m%U$command%n" );

							if ( 'y' === $prompt ) {
								if ( ! isset( $loose_author_term->term_taxonomy_id ) ) {
									$result = $this->insert_author_taxonomy_record(
										$this->coauthorsplus_logic->get_guest_author_by_id( $guest_author->ID ),
										$loose_author_term->term_id
									);

									if ( is_numeric( $result ) ) {
										$loose_author_term->term_taxonomy_id = $result;
										$this->fix_author_term_data_from_guest_author( $guest_author->ID, $loose_author_term, $user );
									} else {
										echo WP_CLI::colorize( "%rUnable to insert author taxonomy record.%n\n" );
										var_dump( $result );
									}
								} else {
									$this->fix_author_term_data_from_guest_author( $guest_author->ID, $loose_author_term, $user );
								}
							}
						} else {
							$command = "wp newspack-content-migrator la-silla-vacia-fix-standalone-guest-author-term-data --guest-author-id=$guest_author->ID --term-id=$loose_author_term->term_id";
							$prompt  = $this->ask_prompt( "Would you like to run the following command? (y/n): %m%U$command%n" );

							if ( 'y' === $prompt ) {
								$this->standalone_guest_author( $guest_author->ID, $loose_author_term );
							}
						}
					}
				} else {
					$taxonomies = $this->output_term_taxonomy_table( $term_taxonomy_ids );

					if ( empty( $taxonomies ) ) {
						// Somehow the term_taxonomy_id - guest author relationship is invalid. So no harm in automating this part
						foreach ( $term_taxonomy_ids as $term_taxonomy_id ) {
							$wpdb->delete(
								$wpdb->term_relationships,
								array(
									'object_id'        => $guest_author->ID,
									'term_taxonomy_id' => $term_taxonomy_id,
								)
							);
						}

						$this->standalone_guest_author( $guest_author->ID, $loose_author_term );
						$this->confirm_ok_to_proceed( $loose_author_term->term_id );
						continue;
					}

					$single_taxonomy = count( $term_taxonomy_ids ) === 1;

					foreach ( $taxonomies as $taxonomy_record ) {
						$terms       = $this->output_terms_table( array( $taxonomy_record->term_id ) );
						$terms_count = count( $terms );

						if ( $terms_count > 1 ) {
							var_dump( $terms );
						} elseif ( 1 === $terms_count ) {
							$user_by_login = get_user_by( 'login', $terms[0]->name );
							echo WP_CLI::colorize( "%BUser by login%n %w(%n%W{$terms[0]->name}%n%w)%n\n" );
							$this->output_users_as_table( array( $user_by_login ) );

							if ( is_email( $terms[0]->name ) ) {
								$user_by_email_taxonomy = get_user_by( 'email', $terms[0]->name );
								echo WP_CLI::colorize( "%BUser by email%n %w(%n%W{$terms[0]->name}%n%w)%n\n" );
								$this->output_users_as_table( array( $user_by_email_taxonomy ) );
								$user_by_email = $this->choose_between_users( $user_by_email, $user_by_email_taxonomy, 'Email', 'Taxonomy-Email' );
							}

							if ( $single_taxonomy && 'author' == $taxonomy_record->taxonomy ) {
								$description_id = $this->extract_id_from_description( $terms[0]->description );

								if ( $description_id == $guest_author->ID ) {
									$user = $this->choose_between_users(
										$this->choose_between_users( $user_by_login, $user_by_email ),
										$user_by_cap_linked_account
									);

									if ( $user instanceof WP_User ) {
										$this->fix_author_term_data_from_guest_author( $guest_author->ID, $terms[0], $user );
									} else {
										if ( $terms[0]->slug == $loose_author_term->slug ) {
											$wpdb->delete(
												$wpdb->terms,
												array(
													'term_id' => $loose_author_term->term_id,
												)
											);
										}
										$result = $this->standalone_guest_author( $guest_author->ID, $terms[0] );

										if ( false === $result && $terms[0]->slug == $loose_author_term->slug ) {
											$wpdb->insert(
												$wpdb->terms,
												array(
													'term_id' => $loose_author_term->term_id,
													'name' => $loose_author_term->name,
													'slug' => $loose_author_term->slug,
													'term_group' => 0,
												)
											);
										}
									}
								}
							}
						}
					}
				}

				$this->confirm_ok_to_proceed( $loose_author_term->term_id );
				continue;
			}

			if ( ! is_email( $loose_author_term->name ) ) {
				echo WP_CLI::colorize( "%rTerm name is not an email address.%n\n" );
				continue;
			}

			$user_ids    = array();
			$user_emails = array();

			$user_by_email = get_user_by( 'email', $loose_author_term->name );
			$user_by_login = get_user_by( 'login', $loose_author_term->name );

			$post_ids = array();
			if ( $user_by_email ) {
				echo WP_CLI::colorize( "%BUSER BY EMAIL%n\n" );
				$user_ids[]    = $user_by_email->ID;
				$user_emails[] = $user_by_email->user_email;
				$this->output_users_as_table( array( $user_by_email ) );
				$postmeta_records = $this->output_postmeta_data_table(
					array(
						'cap-linked_account' => $user_by_email->user_login,
					)
				);

				foreach ( $postmeta_records as $postmeta_record ) {
					$post_ids[] = $postmeta_record->post_id;
				}
			}

			if ( $user_by_login ) {
				echo WP_CLI::colorize( '%BUSER BY LOGIN%n' );
				$user_ids[]    = $user_by_login->ID;
				$user_emails[] = $user_by_login->user_email;
				$this->output_users_as_table( array( $user_by_login ) );
				$postmeta_records = $this->output_postmeta_data_table(
					array(
						'cap-linked_account' => $user_by_login->user_login,
					)
				);

				foreach ( $postmeta_records as $postmeta_record ) {
					$post_ids[] = $postmeta_record->post_id;
				}
			}

			$user = $this->choose_between_users( $user_by_login, $user_by_email );

			$postmeta_records = $this->output_postmeta_data_table(
				array(
					'cap-user_email' => $loose_author_term->name,
					'cap-user_login' => $loose_author_term->slug,
				)
			);

			foreach ( $postmeta_records as $postmeta_record ) {
				$post_ids[] = $postmeta_record->post_id;
			}

			$post_ids = array_unique( $post_ids, SORT_NUMERIC );

			$term_taxonomy_ids_from_post_id_relationships = array();

			if ( ! empty( $post_ids ) ) {
				foreach ( $post_ids as $post_id ) {
					$this->output_post_table( array( $post_id ) );
					$filtered_post_meta = $this->get_filtered_cap_fields(
						$post_id,
						array(
							'cap-user_login',
							'cap-user_email',
							'cap-linked_account',
							'cap-display_name',
						)
					);
					$this->output_comparison_table( array(), $filtered_post_meta );
				}
				$post_ids_placeholder                         = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );
				$term_taxonomy_ids_from_post_id_relationships = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT term_taxonomy_id FROM $wpdb->term_relationships WHERE object_id IN ( $post_ids_placeholder )",
						...$post_ids
					)
				);

				if ( empty( $term_taxonomy_ids_from_post_id_relationships ) ) {
					if ( 1 === count( $post_ids ) ) {
						if ( $user instanceof WP_User ) {
							wp_cache_flush();
							$result = $this->insert_author_taxonomy_record(
								$this->coauthorsplus_logic->get_guest_author_by_id( $post_ids[0] ),
								$loose_author_term->term_id
							);

							if ( is_numeric( $result ) ) {
								$loose_author_term->term_taxonomy_id = $result;
								$loose_author_term->description      = $wpdb->get_var(
									$wpdb->prepare(
										"SELECT description FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d",
										$result
									)
								);
								$this->fix_author_term_data_from_guest_author( $post_ids[0], $loose_author_term, $user );
							} else {
								echo WP_CLI::colorize( "%RUnable to insert author taxonomy record for $loose_author_term->slug%n\n" );
								var_dump( $result );
							}
						} else {
							$this->standalone_guest_author( $post_ids[0], $loose_author_term );
						}
						$this->confirm_ok_to_proceed( $loose_author_term->term_id );
						continue;
					}
				} else {
					$this->output_term_taxonomy_table( $term_taxonomy_ids_from_post_id_relationships );
				}
			}

			// Since term_id and term_taxonomy_id usually match, check to see if there's anything.
			$taxonomies = $this->output_term_taxonomy_table( array( $loose_author_term->term_id ) );

			if ( ! empty( $taxonomies ) ) {
				foreach ( $taxonomies as $taxonomy_record ) {
					$this->high_contrast_output( 'wp_term_taxonomy.term_taxonomy_id', $taxonomy_record->term_taxonomy_id );
					$this->high_contrast_output( 'wp_term_taxonomy.term_id', $taxonomy_record->term_id );
					$this->high_contrast_output( 'wp_term_taxonomy.description', $taxonomy_record->description );
					$description_id    = $this->extract_id_from_description( $taxonomy_record->description );
					$description_email = $this->extract_email_from_term_description( $taxonomy_record->description );
					$this->high_contrast_output( 'Description ID', $description_id );
					$this->high_contrast_output( 'Description Email', $description_email );
					if ( ! in_array( $description_id, $user_ids ) ) {
						$this->output_users_as_table( array( $description_id ) );
					}

					if ( ! in_array( $description_email, $user_emails ) ) {
						$description_user_by_email = get_user_by( 'email', $description_email );

						if ( $description_user_by_email ) {
							$this->output_users_as_table( array( $description_user_by_email ) );
						}
					}

					$this->output_post_table( array( $description_id ) );
				}
			}

			$term_taxonomy_ids = $this->search_for_taxonomies_with_descriptions_like( $loose_author_term->name );

			$this->output_term_taxonomy_table( $term_taxonomy_ids );

			if ( 1 === count( $post_ids ) ) {
				$standalone_command = 'newspack-content-migrator la-silla-vacia-fix-standalone-guest-author-term-data';

				if ( $user instanceof WP_User ) {
					$standalone_command = "newspack-content-migrator la-silla-vacia-fix-user-guest-author-term-data --user-id={$user->ID}";
				}

				$command = "$standalone_command --guest-author-id=$post_ids[0] ";
				$prompt  = 'Run the following command? (y/n/s/h) ';
				if ( 1 === count( $term_taxonomy_ids_from_post_id_relationships ) && 1 === count( $term_taxonomy_ids ) ) {
					if ( $term_taxonomy_ids_from_post_id_relationships[0] === $term_taxonomy_ids[0] ) {
						$taxonomy_description = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT description FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d",
								$term_taxonomy_ids[0]
							)
						);
						$description_id       = $this->extract_id_from_description( $taxonomy_description );
						$term_id              = $this->get_term_id_from_term_taxonomy_id( $term_taxonomy_ids[0] );

						$command = "$standalone_command --guest-author-id={$post_ids[0]} --term-id=$term_id";

						if ( $description_id === $post_ids[0] ) {
							$prompt .= "%Cwp $command%n";
						} else {
							$prompt .= "%Ywp $command%n";
						}

						$response = $this->ask_prompt( $prompt );

						if ( 'y' === $response ) {
							if ( $user instanceof WP_User ) {

								$this->cmd_fix_user_guest_author_term_data(
									array(),
									array(
										'guest-author-id' => $post_ids[0],
										'term-id'         => $term_id,
										'user-id'         => $user->ID,
									)
								);
							} else {
								$this->cmd_fix_standalone_guest_author_term_data(
									array(),
									array(
										'guest-author-id' => $post_ids[0],
										'term-id'         => $term_id,
									)
								);
							}

							$this->confirm_ok_to_proceed( $loose_author_term->term_id );
							continue;
						} elseif ( 'h' === $response ) {
							die();
						} elseif ( 's' === $response ) {
							$wpdb->insert(
								$wpdb->termmeta,
								array(
									'term_id'    => $loose_author_term->term_id,
									'meta_key'   => 'skipped_by_loose_terms_script',
									'meta_value' => 1,
								)
							);
							continue;
						} else {
							continue;
						}
					} else {
						$term_id   = $loose_author_term->term_id;
						$term_id_1 = $this->get_term_id_from_term_taxonomy_id( $term_taxonomy_ids_from_post_id_relationships[0] );
						$term_id_2 = $this->get_term_id_from_term_taxonomy_id( $term_taxonomy_ids[0] );

						$choice = Streams::menu(
							array(
								$term_id_1,
								$term_id_2,
								$loose_author_term->term_id,
								'None of the above',
							),
							null,
							'Which term_id should be used?'
						);

						if ( 3 == $choice ) {
							$this->confirm_ok_to_proceed( $loose_author_term->term_id );
							continue;
						} elseif ( 2 == $choice ) {
							$command .= "--term_id=$loose_author_term->term_id";
						} elseif ( 1 == $choice ) {
							$command .= "--term_id=$term_id_2";
							$term_id  = $term_id_2;
						} elseif ( 0 == $choice ) {
							$command .= "--term_id=$term_id_1";
							$term_id  = $term_id_1;
						}

						echo WP_CLI::colorize( "%m%7Running: wp $command%n\n" );

						if ( $user instanceof WP_User ) {
							$this->cmd_fix_user_guest_author_term_data(
								array(),
								array(
									'guest-author-id' => $post_ids[0],
									'term-id'         => $term_id,
									'user-id'         => $user->ID,
								)
							);
						} else {
							$this->cmd_fix_standalone_guest_author_term_data(
								array(),
								array(
									'guest-author-id' => $post_ids[0],
									'term-id'         => $term_id,
								)
							);
						}
						$this->confirm_ok_to_proceed( $loose_author_term->term_id );
						continue;
					}
				}

				if ( $user instanceof WP_User ) {
					$prompt = $this->ask_prompt( "Run the following command? (y/n) %mwp newspack-content-migrator la-silla-vacia-fix-user-guest-author-term-data --guest-author-id={$post_ids[0]} --term-id=$loose_author_term->term_id --user-id=$user->ID%n" );
					if ( 'y' === $prompt ) {
						$this->fix_author_term_data_from_guest_author( $post_ids[0], $loose_author_term, $user );
					}
				} else {
					$prompt = $this->ask_prompt( "Run the following command? (y/n) %mwp newspack-content-migrator la-silla-vacia-fix-standalone-guest-author-term-data --guest-author-id={$post_ids[0]} --term-id=$loose_author_term->term_id%n" );
					if ( 'y' === $prompt ) {
						$this->standalone_guest_author( $post_ids[0], $loose_author_term );
					}
				}
			}

			$this->confirm_ok_to_proceed( $loose_author_term->term_id );
		}
	}

	private function search_for_taxonomies_with_descriptions_like( string $needle, bool $left_wildcard = true, bool $right_wildcard = true ) {
		global $wpdb;

		$arg = $wpdb->esc_like( $needle );

		if ( $left_wildcard ) {
			$arg = '%' . $arg;
		}

		if ( $right_wildcard ) {
			$arg .= '%';
		}

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = 'author' AND description LIKE %s",
				$arg
			)
		);
	}

	private function search_for_taxonomies_with_display_name( string $needle ) {
		$exploded   = explode( ' ', preg_replace( '/\s\s+/', ' ', $needle ) );
		$first_name = array_shift( $exploded );
		$last_name  = ! empty( $exploded ) ? array_pop( $exploded ) : $first_name;

		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE taxonomy = 'author' AND description LIKE %s AND description LIKE %s",
				"%{$wpdb->esc_like($first_name)}%",
				"%{$wpdb->esc_like($last_name)}%",
			)
		);
	}

	/**
	 * Attempts to find Guest Authors by leveraging cap-user_email and cap-user_login postmeta fields.
	 *
	 * @param string $email
	 *
	 * @return array
	 */
	private function get_guest_authors_using_email( string $email ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
    				DISTINCT pm.post_id 
				FROM $wpdb->postmeta pm 
				    INNER JOIN $wpdb->posts p 
				        ON p.ID = pm.post_id 
				WHERE p.post_type = 'guest-author' 
				  AND (pm.meta_key = 'cap-user_email' OR pm.meta_key = 'cap-user_login') 
				  AND pm.meta_value = %s",
				$email
			)
		);
	}

	private function get_guest_author_from_post_name( string $post_name ) {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_name = %s",
				$post_name
			)
		);

		if ( $post_id ) {
			return $this->coauthorsplus_logic->coauthors_guest_authors->get_guest_author_by( 'ID', $post_id, true );
		}

		return null;
	}

	private function standalone_guest_author( int $guest_author_id, $term ) {
		wp_cache_flush();
		$result = $this->insert_author_taxonomy_record(
			$this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id ),
			$term->term_id
		);

		if ( is_numeric( $result ) ) {
			$term->term_taxonomy_id = $result;
			$this->handle_fixing_standalone_guest_author_data( $guest_author_id, $term );
		} elseif ( is_array( $result ) && 1 === count( $result ) && $term->term_id === $result[0]->term_id ) {
			$term->term_taxonomy_id = $result[0]->term_taxonomy_id;
			$this->handle_fixing_standalone_guest_author_data( $guest_author_id, $term );
		} else {
			echo WP_CLI::colorize( "%rUnable to create author taxonomy record.%n\n" );
			var_dump( $result );
			return false;
		}
	}

	private function get_term_id_from_term_taxonomy_id( int $term_taxonomy_id ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT term_id FROM $wpdb->term_taxonomy WHERE term_taxonomy_id = %d",
				$term_taxonomy_id
			)
		);
	}

	private function choose_between_users( $user_by_login, $user_by_email, string $user_by_login_name = 'Login', string $user_by_email_name = 'Email' ) {
		$user = null;

		if ( $user_by_login instanceof WP_User && $user_by_email instanceof WP_User ) {
			if ( $user_by_login->ID === $user_by_email->ID ) {
				$user = $user_by_login;
			} else {
				$this->output_value_comparison_table(
					array(),
					$user_by_login->to_array(),
					$user_by_email->to_array(),
					true,
					$user_by_login_name,
					$user_by_email_name
				);

				$result = $this->ask_prompt( 'Should the (l)ogin or (e)mail one be used? Or (h)alt execution?' );

				if ( 'h' === $result ) {
					die();
				}

				if ( 'l' === $result ) {
					$user = $user_by_login;
				} elseif ( 'e' === $result ) {
					$user = $user_by_email;
				}
			}
		} elseif ( $user_by_login instanceof WP_User ) {
			$user = $user_by_login;
		} elseif ( $user_by_email instanceof WP_User ) {
			$user = $user_by_email;
		}

		return $user;
	}

	private function ask_for_confirmation_to_proceed( ) {
		return Streams::menu(
			[
				'continue',
				'halt execution',
				'skip this item',
			],
			'continue',
			'Continue, halt execution, or skip this item?'
		);
	}
	
	private function confirm_ok_to_proceed( int $term_id, bool $delete = false ) {
		global $wpdb;

		$prompt = $this->ask_prompt( '(s)kip, (h)alt, or (c)ontinue?' );
		$prompt = strtolower( $prompt );

		if ( 'h' === $prompt ) {
			die();
		} elseif ( 's' === $prompt ) {
			$wpdb->insert(
				$wpdb->termmeta,
				array(
					'term_id' => $term_id,
					'meta_key' => 'skipped_by_loose_terms_script',
					'meta_value' => 1,
				)
			);
			return false;
		} else {
			if ( $delete ) {
				$wpdb->delete(
					$wpdb->terms,
					array(
						'term_id' => $term_id,
					)
				);
			}

			return $wpdb->insert(
				$wpdb->termmeta,
				array(
					'term_id'    => $term_id,
					'meta_key'   => 'updated_by_loose_terms_script',
					'meta_value' => 1,
				)
			);
		}
	}

	public function cmd_update_guest_author_slug( $args, $assoc_args ) {
		$guest_author_id = $assoc_args['guest-author-id'];

		$guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id );

		if ( ! $guest_author ) {
			WP_CLI::error( 'Guest author not found.' );
		}

		global $wpdb;

		if ( $guest_author->linked_account ) {
			WP_CLI::log( "Linked account: {$guest_author->linked_account}" );
			// $posts = $this->coauthorsplus_logic->get_all_posts_by_wp_user( get_user_by( 'user_login', $guest_author->linked_account ) );
			$user = get_user_by( 'login', $guest_author->linked_account );

			if ( false === $user ) {
				WP_CLI::log( 'User not found via linked_account.' );
			} else {
				$count_of_posts = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) as count FROM $wpdb->posts WHERE post_author = %d AND post_type = 'post'",
						$user->ID
					)
				);
				echo WP_CLI::colorize( "%w$count_of_posts posts found for linked WP_User%n\n" );
			}
		}

		$posts          = $this->coauthorsplus_logic->get_all_posts_by_guest_author( $guest_author_id );
		$count_of_posts = count( $posts );
		echo WP_CLI::colorize( "%w$count_of_posts posts found for Guest Author%n\n" );

		$user_login       = sanitize_title( $guest_author->display_name );
		$final_user_login = $user_login;

		$attempt = 1;
		do {

			$existing_coauthor = $this->coauthorsplus_logic->coauthors_plus->get_coauthor_by( 'user_login', $final_user_login, true );

			if ( $existing_coauthor && 'guest-author' == $existing_coauthor->type ) {
				$final_user_login = $user_login . '-' . $attempt;
				++$attempt;
			} else {
				break;
			}
		} while ( true );

		$new_guest_author_id = $this->coauthorsplus_logic->create_guest_author(
			array(
				'display_name' => $guest_author->display_name,
				'user_login'   => $final_user_login,
				'first_name'   => $guest_author->first_name,
				'last_name'    => $guest_author->last_name,
				'user_email'   => $guest_author->user_email,
				'website'      => $guest_author->website,
				'description'  => $guest_author->description,
			)
		);

		$new_guest_author = $this->coauthorsplus_logic->get_guest_author_by_id( $new_guest_author_id );

		// Complicated query coming, sorry!
		$meta_to_transfer            = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT existing_meta.meta_id FROM ( 
						SELECT DISTINCT meta_key FROM $wpdb->postmeta WHERE post_id IN ( %d, %d )
					) as meta_keys 
    				LEFT JOIN (SELECT * FROM $wpdb->postmeta WHERE post_id = %d) as existing_meta 
    				    ON meta_keys.meta_key = existing_meta.meta_key 
    				LEFT JOIN ( SELECT * FROM $wpdb->postmeta WHERE post_id = %d) as new_meta 
    				    ON meta_keys.meta_key = new_meta.meta_key 
         			WHERE new_meta.meta_id IS NULL",
				$guest_author_id,
				$new_guest_author_id,
				$guest_author_id,
				$new_guest_author_id
			)
		);
		$meta_to_transfer            = array_map( fn( $item ) => $item->meta_id, $meta_to_transfer );
		$imploded_meta_to_transfer   = implode( ',', $meta_to_transfer );
		$meta_transfer_update_result = $wpdb->query(
			"UPDATE $wpdb->postmeta SET post_id = $new_guest_author_id WHERE meta_id IN ( $imploded_meta_to_transfer )"
		);

		if ( $meta_transfer_update_result ) {
			echo WP_CLI::colorize( "%GSuccess transferring postmeta%n\n" );
		} else {
			echo WP_CLI::colorize( "%RFailed transferring postmeta%n\n" );
		}

		$delete_result = $this->coauthorsplus_logic->delete_ga( $guest_author_id, $new_guest_author->user_login );

		if ( is_wp_error( $delete_result ) ) {
			echo WP_CLI::colorize( "%RFailed deleting old guest author: {$delete_result->get_error_message()}%n\n" );
		} else {
			echo WP_CLI::colorize( "%GSuccess deleting old guest author%n\n" );
		}

		if ( $guest_author->linked_account ) {
			WP_CLI::log( 'Linking WP_User account to new GA Account' );
			$user = get_user_by( 'login', $guest_author->linked_account );

			if ( $user ) {
				$this->coauthorsplus_logic->link_guest_author_to_wp_user( $new_guest_author_id, $user );
			}
		}

		$wpdb->delete(
			$wpdb->postmeta,
			array(
				'post_id' => $guest_author_id,
			)
		);

		$new_guest_author_url = get_home_url() . "/author/{$new_guest_author->user_nicename}/";

		echo WP_CLI::colorize( "%M$new_guest_author_url%n\n" );
	}

	public function cmd_set_author_for_posts( $args, $assoc_args ) {
		$guest_author_id = $assoc_args['guest-author-id'] ?? null;
		$user_id         = $assoc_args['wp-user-id'] ?? null;

		if ( is_null( $guest_author_id ) && is_null( $user_id ) ) {
			WP_CLI::error( 'You must specify either a guest author ID or a WP User ID.' );
		}

		if ( ! is_null( $guest_author_id ) && ! is_null( $user_id ) ) {
			WP_CLI::error( 'You must specify either a guest author ID or a WP User ID, not both.' );
		}

		$author = $this->coauthorsplus_logic->get_guest_author_by_id( $guest_author_id )
					?: $this->coauthorsplus_logic->coauthors_plus->get_coauthor_by( 'id', $user_id, true );

		$term_taxonomy_id = $assoc_args['term-taxonomy-id'];
		$append           = $assoc_args['append'] ?? false;
		$start_at_post_id = $assoc_args['start-at-post-id'] ?? null;

		$post_id_constraint = '';
		if ( ! is_null( $start_at_post_id ) ) {
			$post_id_constraint = "AND ID >= $start_at_post_id";
		}

		$post_ids = $assoc_args['post-ids'] ?? '';
		$post_ids = explode( ',', $post_ids );
		$post_ids = array_filter( $post_ids );

		global $wpdb;

		$posts = array();

		if ( ! empty( $post_ids ) ) {
			$post_id_placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * 
					FROM $wpdb->posts 
					WHERE post_type = 'post' 
					  AND post_status = 'publish'
					  AND ID IN ( $post_id_placeholders )
					  {$post_id_constraint}
					ORDER BY ID",
					$post_ids
				)
			);
		} else {
			$posts = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * 
				FROM $wpdb->posts 
				WHERE post_type = 'post' 
				  AND post_status = 'publish' 
				  AND ID IN (
				  	SELECT object_id 
				  	FROM $wpdb->term_relationships 
				  	WHERE term_taxonomy_id = %d
				  	)
				  {$post_id_constraint}
				ORDER BY ID",
					$term_taxonomy_id
				)
			);
		}

		$count_of_posts = count( $posts );
		echo WP_CLI::colorize( "%c$count_of_posts posts found%n\n" );

		foreach ( $posts as $post ) {
			echo WP_CLI::colorize( "%WPost ID:%n %Y$post->ID%n " );
			$result = $this->coauthorsplus_logic->coauthors_plus->add_coauthors( $post->ID, array( $author->user_login ), $append, 'user_login' );
			if ( false === $result ) {
				if ( false === $append && ! is_null( $guest_author_id ) ) {
					$result = true;
				}
			}
			$result = $result ? '%GSuccess%n' : '%RFailed%n';
			echo WP_CLI::colorize( "%WResult:%n $result\n" );
		}
	}

	public function cmd_set_primary_category( $args, $assoc_args ) {
		$term_taxonomy_id = $assoc_args['term-taxonomy-id'];

		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name FROM $wpdb->posts WHERE ID IN (SELECT object_id FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d)",
				$term_taxonomy_id
			)
		);

		$count_of_posts = count( $posts );
		echo WP_CLI::colorize( "%c$count_of_posts posts found%n\n" );

		foreach ( $posts as $post ) {
			echo WP_CLI::colorize( "%WPost ID:%n %Y$post->ID%n " );
			$first_term = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT tt.term_taxonomy_id, tt.taxonomy, t.term_id, tt.parent, t.name, tr.term_order 
					FROM ' . $wpdb->term_taxonomy . ' tt 
					    LEFT JOIN ' . $wpdb->terms . ' t ON t.term_id = tt.term_id 
					    INNER JOIN ' . $wpdb->term_relationships . ' tr ON tt.term_taxonomy_id = tr.term_taxonomy_id 
					WHERE tr.object_id = %1$d AND tt.taxonomy = "category" AND tt.parent = %2$d AND tr.term_taxonomy_id <> %2$d
					ORDER BY tr.term_order ASC LIMIT 1',
					$post->ID,
					$term_taxonomy_id,
					$term_taxonomy_id
				)
			);

			if ( empty( $first_term ) ) {
				echo WP_CLI::colorize( "%RNo primary category found.%n\n" );
				continue;
			}

			echo WP_CLI::colorize( "%WPrimary Category:%n %Y$first_term->name%n %w$first_term->taxonomy-$first_term->term_id-$first_term->parent%n\n" );

			update_post_meta( $post->ID, '_yoast_wpseo_primary_category', $first_term->term_id );
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
	private function is_date_valid( $date, $format = 'Y-m-d' ) {
		$d = DateTime::createFromFormat( $format, $date );
		// The Y ( 4 digits year ) returns TRUE for any integer with any number of digits so changing the comparison from == to === fixes the issue.
		return $d && $d->format( $format ) === $date;
	}

	/**
	 * Migrator for LSV redirects.
	 *
	 * @param $args
	 * @param $assoc_args
	 */
	public function migrate_redirects( $args, $assoc_args ) {
		if ( $assoc_args['reset-db'] ) {
			$this->reset_db();
		}

		global $wpdb;

		foreach ( $this->json_generator( $assoc_args['import-json'] ) as $redirect ) {
			$from_path = parse_url( $redirect['CustomUrl'], PHP_URL_PATH );
			$to_path   = $redirect['Redirect'];
			$this->file_logger( "Original Redirect From: $from_path | Original Redirect To: $to_path" );

			$response_code = wp_remote_retrieve_response_code( wp_remote_get( $redirect['CustomUrl'] ) );

			if ( 200 != $response_code ) {
				$this->file_logger( "Unsuccessful request: ($response_code) {$redirect['CustomUrl']}" );
				continue;
			}

			// TODO Search in postmeta for $to_path
			$query  = $wpdb->prepare(
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
				$to_path = get_site_url( null, $result->post_name );
				$this->file_logger( "Creating redirect to $to_path" );
				$this->redirection->create_redirection_rule( '', get_site_url( null, $from_path ), $to_path );
			}
		}
	}

	public function download_missing_images( array $args, array $assoc_args ): void {

		add_filter(
			'newspack_content_migrator_download_images_sanctioned_hosts',
			function ( $hosts ) {
				$hosts[] = parse_url( home_url(), PHP_URL_HOST );

				return $hosts;
			}
		);

		add_filter(
			'newspack_content_migrator_download_images_path_translations',
			function ( $paths ) {

				$paths['relative'] += array(
					'/sites' => 'https://archivo.lasillavacia.com',
					'/media' => 'https://www.lasillavacia.com',
				);
				$paths['hosts']    += array(
					'lasillavacia.com'         => 'https://www.lasillavacia.com/media',
					'www.lasillavacia.com'     => 'https://www.lasillavacia.com/media',
					'lasilla.com'              => 'https://www.lasillavacia.com/media',
					'www.lasilla.com'          => 'https://www.lasillavacia.com/media',
					'archivo.lasillavacia.com' => 'https://www.lasillavacia.com/media',
				);

				return $paths;
			}
		);

		$imageHelper = DownloadMissingImages::get_instance();

		$imageHelper->download_missing_images(
			$assoc_args['media-dir'],
			'post',
			array(
				'post-id'       => $assoc_args['post-id'] ?? '',
				'post-id-range' => $assoc_args['post-id-range'] ?? '',
			),
		);
	}

	public function create_missing_podcasts( array $args, array $assoc_args ) {
		$command_meta_key     = 'create_missing_podcasts';
		$command_meta_version = 'v1';
		$log_file             = "{$command_meta_key}_{$command_meta_version}.log";

		global $wpdb;
		foreach ( $this->json_iterator->items( $assoc_args['import-json'] ) as $podcast ) {

			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_original_article_id' and meta_value = %s",
					$podcast->id
				)
			);
			if ( $existing_id ) {
				$this->logger->log(
					$log_file,
					sprintf(
						'Podcast with original ID %d has already been imported to post id %d. Skipping',
						$podcast->id,
						$existing_id
					),
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

			$post                  = array(
				'post_title'     => $podcast->title,
				'post_date'      => $podcast->createdAt,
				'comment_status' => 'closed',
				'post_author'    => 6, // Hard code to Karen because the futuro del futuro posts have no author.
				'meta_input'     => array(
					'newspack_original_article_id' => $podcast->id,
				),
			);
			$sanctioned_categories = array(
				5001, // Podcasts
				5005, // El futuro del futuro
			);

			if ( ! empty( $podcast->categories ) ) {
				$cats = array_map(
					fn( $cat ) => (int) $cat->id,
					$podcast->categories
				);

				$post['post_category'] = array_filter(
					$cats,
					fn( $id ) => in_array( $id, $sanctioned_categories, true )
				);
			}

			$post_id = wp_insert_post( $post );

			$attachment_id = $this->attachments->import_external_file( $file_path, false, false, false, false, $post_id );

			if ( is_wp_error( $attachment_id ) ) {
				$this->logger->log(
					$log_file,
					sprintf(
						'Error importing audio file %s for podcast with original ID %d. Error: %s',
						$file_path,
						$podcast->id,
						$attachment_id->get_error_message()
					),
					Logger::ERROR
				);
				// No need for a post if we don't have the audio file.
				wp_delete_post( $post_id );
			}

			$audio_url   = wp_get_attachment_url( $attachment_id );
			$audio_block = <<<BLOCK
<!-- wp:audio {"id":$attachment_id} -->
<figure class="wp-block-audio"><audio controls src="$audio_url"></audio></figure>
<!-- /wp:audio -->
BLOCK;
			// Now add the block with the audio file link.
			$updated_post = array(
				'ID'           => $post_id,
				'post_content' => $audio_block,
				'post_status'  => 'publish',
			);
			if ( ! is_wp_error( wp_update_post( $updated_post ) ) ) {

				WP_CLI::success( sprintf( 'Updated post ID %d with audio file %s', $post_id, $file_path ) );
			}
		}
	}

	// Updates podcasts with their audio files and prepends an audio block to the post content.
	public function update_podcasts( array $args, array $assoc_args ): void {

		$command_meta_key     = 'update_podcasts';
		$command_meta_version = 'v1';
		$log_file             = "{$command_meta_key}_{$command_meta_version}.log";
		global $wpdb;

		foreach ( $this->json_iterator->items( $assoc_args['import-json'] ) as $podcast ) {
			// Find the existing podcast/article.
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'newspack_original_article_id' and meta_value = %s",
					$podcast->id
				)
			);

			if ( ! $existing_id ) {
				$this->logger->log(
					$log_file,
					sprintf( 'Could not find a post to update with original ID %d.', $podcast->id ),
					Logger::ERROR
				);
				continue;
			}

			if ( MigrationMeta::get( $existing_id, $command_meta_key, 'post' ) === $command_meta_version ) {
				$this->logger->log(
					$log_file,
					sprintf(
						'Post ID %d already has been updated to %s. Skipping.',
						$existing_id,
						$command_meta_version
					)
				);
				continue;
			}

			if ( empty( $podcast->audio ) ) {
				$this->logger->log(
					$log_file,
					sprintf( 'Podcast ID %d has no audio file.', $podcast->id ),
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

			WP_CLI::success( sprintf( 'Updated post ID %d with audio file %s', $existing_id, $file_path ) );
		}
	}

	/**
	 * @param $args
	 * @param $assoc_args

	/**
	 * This function will address post_content containing the text `lasilla.com`. In all cases,
	 * this was added during the migration process to identify media that needed to be updated.
	 * However, it was also added erroneously to links that shouldn't have been updated.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cmd_update_post_content_that_has_specific_url( $args, $assoc_args ) {
		global $wpdb;

		$after_post_id  = $assoc_args['after-post-id'] ?? 0;
		$media_location = trailingslashit( $assoc_args['media-location'] );

		$full_path = function ( $path ) use ( $media_location ) {
			return $media_location . $path;
		};

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT 
				* 
				FROM (
					SELECT 
    					*
					FROM (
				    	SELECT ID, post_content FROM $wpdb->posts 
				    	WHERE post_type = 'post' AND post_content LIKE %s ORDER BY ID
					) AS sub
					ORDER BY sub.ID
				) AS subber
				WHERE subber.ID > %d",
				'%' . $wpdb->esc_like( 'lasilla.com' ) . '%',
				$after_post_id
			)
		);

		foreach ( $posts as $post ) {
			echo "\n\n\n";
			echo WP_CLI::colorize( "%wPost ID:%n %B$post->ID%n\n" );

			// Can't seem to trust the system to maintain a carbon copy of the post_content before update, so saving it as postmeta.
			$this->handle_saving_post_content_as_meta( $post->post_content, $post->ID );

			echo WP_CLI::colorize( "%BHANDLING IMAGES%n\n" );
			$image_urls = $this->attachments->get_images_sources_from_content( $post->post_content );
			foreach ( $image_urls as $image_url ) {
				$this->high_contrast_output( 'Original URL', $image_url );

				if ( str_contains( $image_url, 'lasillavacia.com' ) && str_contains( $image_url, 'wp-content/uploads/' ) && ! str_contains( $image_url, 'lasilla.com' ) ) {
					echo WP_CLI::colorize( "%mSkipping%n\n" );
					continue;
				}

				if ( str_starts_with( $image_url, 'https://i0.wp.com/' ) ) {
					$new_image_url = str_replace( 'https://i0.wp.com/', 'https://', $image_url );
					$parsed_url    = WP_CLI\Utils\parse_url( $new_image_url );
					$new_image_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $parsed_url['path'];
					$this->high_contrast_output( 'Replaced URL', $new_image_url );

					if ( ! str_contains( $new_image_url, 'lasilla.com' ) ) {
						echo WP_CLI::colorize( "%YAttempting to download image%n\n" );
						$attachment_id  = $this->attachments->import_external_file( $new_image_url, null, null, null, null, $post->ID );
						$attachment_url = wp_get_attachment_url( $attachment_id );

						$post->post_content = str_replace( $image_url, $attachment_url, $post->post_content );
						continue;
					}
				}

				$exploded            = explode( '/', $image_url );
				$filename            = array_pop( $exploded );
				$filename            = urldecode( $filename );
				$question_mark_index = strpos( $filename, '?' );

				if ( false !== $question_mark_index ) {
					$filename = substr( $filename, 0, $question_mark_index );
				}

				$this->high_contrast_output( 'Exploded Filename', $filename );
				// $this->high_contrast_output( 'Basename filename', WP_CLI\Utils\basename( $image_url ) );
				$full_filename_path = $full_path( $filename );
				$file_exists        = file_exists( $full_filename_path );
				$this->high_contrast_output( 'File Exists?', $file_exists ? 'Yes' : 'Nope' );

				$possible_attachment_id = $this->attachments->maybe_get_existing_attachment_id( $full_filename_path, $filename );
				$this->high_contrast_output( 'Possible Attachment ID', $possible_attachment_id ?? 'Nope' );

				if ( $possible_attachment_id ) {
					$attachment_url     = wp_get_attachment_url( $possible_attachment_id );
					$post->post_content = str_replace( $image_url, $attachment_url, $post->post_content );
				} elseif ( $file_exists ) {
					echo WP_CLI::colorize( "%YAttempting to download image%n\n" );
					$attachment_id  = $this->attachments->import_external_file( $full_filename_path, null, null, null, null, $post->ID );
					$attachment_url = wp_get_attachment_url( $attachment_id );

					$post->post_content = str_replace( $image_url, $attachment_url, $post->post_content );
				}
			}

			echo WP_CLI::colorize( "%BHANDLING VIDEO URLs%n\n" );
			preg_match_all( '/<video[^>]+(?:src)="([^">]+)"/', $post->post_content, $video_sources_match );
			if ( array_key_exists( 1, $video_sources_match ) && ! empty( $video_sources_match[1] ) ) {
				foreach ( $video_sources_match[1] as $match ) {
					$this->high_contrast_output( 'Video URL', $match );
					$filename = WP_CLI\Utils\basename( $match );
					$this->high_contrast_output( 'Basename filename', $filename );
					$full_filename_path = $full_path( $filename );
					$file_exists        = file_exists( $full_filename_path );
					$this->high_contrast_output( 'File Exists?', $file_exists ? 'Yes' : 'Nope' );

					if ( $file_exists ) {
						$attachment_id      = $this->attachments->import_external_file( $full_filename_path, null, null, null, null, $post->ID );
						$attachment_url     = wp_get_attachment_url( $attachment_id );
						$post->post_content = str_replace( $match, $attachment_url, $post->post_content );
					} else {
						$possible_attachment_id = $this->attachments->maybe_get_existing_attachment_id( $full_filename_path, $filename );
						$this->high_contrast_output( 'Possible Attachment ID', $possible_attachment_id ?? 'Nope' );

						if ( $possible_attachment_id ) {
							$attachment_url     = wp_get_attachment_url( $possible_attachment_id );
							$post->post_content = str_replace( $match, $attachment_url, $post->post_content );
						}
					}
				}
			}

			echo WP_CLI::colorize( "%BHANDLING IFRAME URLs%n\n" );
			preg_match_all( '/<iframe[^>]+(?:src)="([^">]+)"/', $post->post_content, $iframe_source_matches );
			if ( array_key_exists( 1, $iframe_source_matches ) && ! empty( $iframe_source_matches[1] ) ) {
				foreach ( $iframe_source_matches[1] as $match ) {
					$this->high_contrast_output( 'iFrame URL', $match );
					$filename = WP_CLI\Utils\basename( $match );
					$this->high_contrast_output( 'Basename filename', $filename );
					$full_filename_path = $full_path( $filename );
					$file_exists        = file_exists( $full_filename_path );
					$this->high_contrast_output( 'File Exists?', $file_exists ? 'Yes' : 'Nope' );

					if ( $file_exists ) {
						$attachment_id      = $this->attachments->import_external_file( $full_filename_path, null, null, null, null, $post->ID );
						$attachment_url     = wp_get_attachment_url( $attachment_id );
						$post->post_content = str_replace( $match, $attachment_url, $post->post_content );
					} else {
						$possible_attachment_id = $this->attachments->maybe_get_existing_attachment_id( $full_filename_path, $filename );
						$this->high_contrast_output( 'Possible Attachment ID', $possible_attachment_id ?? 'Nope' );

						if ( $possible_attachment_id ) {
							$attachment_url     = wp_get_attachment_url( $possible_attachment_id );
							$post->post_content = str_replace( $match, $attachment_url, $post->post_content );
						} else {
							// Only attempt to correct URL if it contains lasilla.com.
							// Otherwise, the URL should be fine and/or we shouldn't be touching it.
							if ( str_contains( $match, 'lasilla.com' ) ) {
								$url = $this->attempt_to_get_correct_url( $match, $post->ID );

								if ( $url !== null ) {
									$post->post_content = str_replace( $match, $url, $post->post_content );
								}
							}
						}
					}
				}
			}

			echo WP_CLI::colorize( "%BHANDLING DOCUMENT URLs%n\n" );
			preg_match_all( '/<a[^>]+(?:href)="([^">]+)"/', $post->post_content, $document_sources_match );
			if ( array_key_exists( 1, $document_sources_match ) && ! empty( $document_sources_match[1] ) ) {
				foreach ( $document_sources_match[1] as $match ) {
					if ( ! str_contains( $match, 'lasilla.com' ) ) {
						continue;
					}

					$this->high_contrast_output( 'Doc URL', $match );
					$filename = WP_CLI\Utils\basename( $match );
					$this->high_contrast_output( 'Basename filename', $filename );
					$full_filename_path = $full_path( $filename );
					$file_exists        = file_exists( $full_filename_path );
					$this->high_contrast_output( 'File Exists?', $file_exists ? 'Yes' : 'Nope' );

					if ( $file_exists ) {
						WP_CLI::success('File exists');
						$attachment_id      = $this->attachments->import_external_file( $full_filename_path, null, null, null, null, $post->ID );
						$attachment_url     = wp_get_attachment_url( $attachment_id );
						$post->post_content = str_replace( $match, $attachment_url, $post->post_content );
					} else {
						$possible_attachment_id = $this->attachments->maybe_get_existing_attachment_id( $full_filename_path, $filename );
						$this->high_contrast_output( 'Possible Attachment ID', $possible_attachment_id ?? 'Nope' );

						if ( $possible_attachment_id ) {
							$attachment_url     = wp_get_attachment_url( $possible_attachment_id );
							$post->post_content = str_replace( $match, $attachment_url, $post->post_content );
						} else {
							$url = $this->attempt_to_get_correct_url( $match, $post->ID );

							if ( null !== $url ) {
								$post->post_content = str_replace( $match, $url, $post->post_content );
							}
						}
					}
				}
			}

			wp_update_post(
				array(
					'post_content' => $post->post_content,
					'ID'           => $post->ID,
				)
			);
		}
	}

	/**
	 * This function will attempt to find the correct URL for URL's containing lasilla.com in them.
	 *
	 * @param string $original_url The original URL that needs to be checked.
	 * @param int    $post_id The post ID where the original URL was found.
	 *
	 * @return string|null
	 */
	private function attempt_to_get_correct_url( $original_url, $post_id ) {
		$http        = new WP_Http();
		$moved_codes = [ $http::MOVED_PERMANENTLY, $http::FOUND ];

		$modified_url = str_replace( 'https://lasilla.com', '/media', $original_url );
		$this->high_contrast_output( 'Modified URL', $modified_url );

		if ( false === wp_http_validate_url( $modified_url ) ) {
			echo WP_CLI::colorize( "%YInvalid URL%n\n" );
			return null;
		}

		$response = $http->request( $modified_url, [ 'method' => 'HEAD' ] );

		if ( is_wp_error( $response ) ) {
			// Log error and keep it moving.
			$this->file_logger( $response->get_error_message() );
			return null;
		}

		$response_code = $response['response']['code'];

		if ( in_array( $response_code, $moved_codes, true ) ) {
			// Getting 302 might indicate that the page was legitimately moved.
			// So requesting the URL again, this time with GET method, to see what code we get.
			$response = $http->request( $modified_url, [ 'timeout' => 10, 'redirection' => 5 ] );

			if ( is_wp_error( $response ) ) {
				// Log error and keep it moving.
				$this->file_logger( $response->get_error_message() );
				return null;
			}

			$response_code = $response['response']['code'];
		}

		if ( $http::NOT_FOUND === $response_code ) {
			// Try one more variation to the link.
			$modified_url = str_replace( 'https://lasilla.com', '/media/docs', $original_url );
			$response     = $http->request( $modified_url, [ 'timeout' => 10, 'redirection' => 5 ] );

			if ( is_wp_error( $response ) ) {
				// Log error and keep it moving.
				$this->file_logger( $response->get_error_message() );
				return null;
			}

			$response_code = $response['response']['code'];
		}

		if ( $http::OK === $response_code ) {
			// Page was found, so update the post_content with the new URL.
			echo WP_CLI::colorize( "%gReplacement URL found!%n\n" );
			return $modified_url;
		} else {
			// Page was moved or not found. Log the link and move on.
			$this->file_logger( "Post ID: $post_id\nOriginal URL:\n$original_url\nModified URL:\n$modified_url\nResponse Code: $response_code\n", true );
			return null;
		}
	}

	public function cmd_upload_missing_publicaciones_images( $args, $assoc_args ) {
		$media_location = $assoc_args['media-location'];

		global $wpdb;

		foreach ( $this->json_iterator->items( $assoc_args['import-json'] ) as $item ) {
			echo "\n\n\n";
			$this->high_contrast_output( 'Original Post ID', $item->id );

			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'original_post_id' AND meta_value = %d",
					$item->id
				)
			);

			if ( ! $post_id ) {
				echo WP_CLI::colorize( "%YOriginal Post ID wasn't imported: $item->id%n\n" );
				continue;
			}

			$this->high_contrast_output( 'NEWSPACK POST ID', $post_id );

			$filename = basename( $item->picture );

			$post_content = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_content FROM $wpdb->posts WHERE ID = %d",
					$post_id
				)
			);

			$featured_image_id = get_post_thumbnail_id( $post_id );

			if ( ! empty( $featured_image_id ) ) {
				$url = wp_get_attachment_image_src( $featured_image_id, 'full' )[0];

				if ( str_contains( $post_content, '{image_attachment_src}' ) ) {
					$this->high_contrast_output( 'Post has featured image, but it is not in the post_content. Updating post_content.', '' );
					$post_content = strtr(
						$post_content,
						[
							'{image_attachment_id}'  => $featured_image_id,
							'{image_attachment_src}' => $url,
						]
					);

					$wpdb->update(
						$wpdb->posts,
						[
							'post_content' => $post_content,
						],
						[
							'ID' => $post_id,
						]
					);
				}

				$this->high_contrast_output( 'Post has featured image', "($featured_image_id) $url" );
				continue;
			}

			if ( null === $item->picture ) {
				echo WP_CLI::colorize( "%YOriginal Post ID doesn't have a 'picture' field%n\n" );
				continue;
			}

			if ( ! str_contains( $post_content, '{image_attachment_id}' ) ) {
				$potential_attachment_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 
	                        post_id 
						FROM $wpdb->postmeta 
						WHERE meta_key = '_wp_attached_file' 
						  AND meta_value LIKE %s 
						ORDER BY post_id 
						LIMIT 1",
						'%' . $wpdb->esc_like( $filename )
					)
				);

				if ( $potential_attachment_id ) {
					$this->high_contrast_output( 'Potential Attachment ID', $potential_attachment_id );
					// If $post_content has URL, $post_id does not have a thumbnail_id, fill it in.
					$potential_attachment_url = wp_get_attachment_url( $potential_attachment_id );
					$potential_attachment_url = str_replace( 'https://www.', '', $potential_attachment_url );
					if ( str_contains( $post_content, $potential_attachment_url ) ) {
						update_post_meta( $post_id, '_thumbnail_id', $potential_attachment_id );
						update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
						continue;
					}
				}

				echo WP_CLI::colorize( "%MLooks like this contains featured image, but cannot find in DB.%n\n" );
				continue;
			}

			$image = [
				'FriendlyName' => $filename,
			];

			$featured_image_id = $this->handle_featured_image( $image, $item->id, 0, $media_location );

			if ( ! $featured_image_id ) {
				echo WP_CLI::colorize( "%MUnable to find image $item->picture%n\n" );
				continue;
			}

			$post_content = strtr(
				$post_content,
				[
					'{image_attachment_id}'  => $featured_image_id,
					'{image_attachment_src}' => wp_get_attachment_image_src( $featured_image_id, 'full' )[0],
				]
			);

			$update = $wpdb->update(
				$wpdb->posts,
				[
					'post_content' => $post_content,
				],
				[
					'ID' => $post_id
				]
			);

			if ( $update ) {
				echo WP_CLI::colorize( "%GFeatured image successfully updated.%n\n" );
				update_post_meta( $post_id, '_thumbnail_id', $featured_image_id );
				update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
			} else {
				echo WP_CLI::colorize( "%RUnable to update featured image.%n\n" );
			}
		}
	}

	/**
	 * During our various article import exercises it seems we created a small batch of duplicate articles and
	 * duplicate metadata. It's important to clean this up so that if we need to do future imports or updates,
	 * we can clearly track which articles should be affected and which should not.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_remove_duplicate_article_import_meta_data( $args, $assoc_args ) {
		/*
		 * wp_postmeta.meta_value (key) => [ ...duplicate wp_posts.ID ]
		 */
		$target_data = [
			8367 => [ 12543, 26680 ],
			8411 => [ 12631, 26706 ],
			8435 => [ 12679, 26724 ],
			8437 => [ 12683, 162753 ],
			8457 => [ 12723, 26738 ],
			8479 => [ 12767, 162757 ],
			8515 => [ 12839, 26788 ],
			8601 => [ 13011, 26860 ],
			8605 => [ 13019, 26864 ],
			8607 => [ 13023, 26866 ],
			8623 => [ 13055, 26882 ],
			8631 => [ 13071, 26889 ],
			8637 => [ 13083, 26895 ],
			8699 => [ 13207, 26944 ],
			8707 => [ 13223, 26950 ],
			8739 => [ 13287, 26977 ],
			8783 => [ 13375, 27012 ],
			8841 => [ 13491, 27058 ],
			8881 => [ 13571, 27093 ],
			8885 => [ 13579, 27096 ],
			8899 => [ 13607, 27108 ],
			8915 => [ 13639, 27121 ],
		];

		global $wpdb;
		foreach ( $target_data as $meta_value => $post_ids ) {
			WP_CLI::log( sprintf( 'Processing meta_value (LSV Article ID): %d', $meta_value ) );
			$first_post_id = $post_ids[0];
			$dupe_post_id  = $post_ids[1];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$first_post = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE ID = %d", $first_post_id ),
				ARRAY_A
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$dupe_post = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM $wpdb->posts WHERE ID = %d", $dupe_post_id ),
				ARRAY_A
			);

			$comparison = $this->output_value_comparison_table( [], $first_post, $dupe_post );

			$target_column_names = [ 'post_date', 'post_date_gmt', 'post_content' ];
			$update_columns      = [];
			if ( ! empty( $comparison['different'] ) ) {
				foreach ( $comparison['different'] as $column_name => $value ) {
					if ( in_array( $column_name, $target_column_names, true ) ) {
						$update_columns[ $column_name ] = $value;
					}
				}
			}

			if ( ! empty( $update_columns ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$update_first_post = $wpdb->update(
					$wpdb->posts,
					$update_columns,
					[
						'ID' => $first_post_id,
					]
				);

				if ( $update_first_post ) {
					WP_CLI::success( sprintf( 'Updated Post ID: %d', $first_post_id ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$delete_dupe_meta = $wpdb->query(
						$wpdb->prepare(
							"DELETE FROM $wpdb->postmeta WHERE post_id = %d AND meta_value = %d AND meta_key LIKE %s",
							$dupe_post_id,
							$meta_value,
							'%' . $wpdb->esc_like( 'original_article_id' )
						)
					);

					if ( $delete_dupe_meta ) {
						WP_CLI::success(
							sprintf(
								'Deleted duplicate meta data for Post ID: %d',
								$dupe_post_id
							)
						);
					} else {
						WP_CLI::warning(
							sprintf(
								'Failed to delete duplicate meta data for Post ID: %d',
								$dupe_post_id
							)
						);
					}

					$correct_meta_exists = get_post_meta( $first_post_id, 'newspack_original_article_id', true );

					if ( ! $correct_meta_exists ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
						$update_remaining_meta_key = $wpdb->query(
							$wpdb->prepare(
								"UPDATE $wpdb->postmeta SET meta_key = 'newspack_original_article_id' 
             				WHERE post_id = %d AND meta_value = %d AND meta_key LIKE %s",
								$first_post_id,
								$meta_value,
								'%' . $wpdb->esc_like( 'original_article_id' )
							)
						);

						if ( $update_remaining_meta_key ) {
							WP_CLI::success(
								sprintf(
									'Updated remaining meta key for Post ID: %d',
									$first_post_id
								)
							);
						} else {
							WP_CLI::warning(
								sprintf(
									'Failed to update remaining meta key for Post ID: %d',
									$first_post_id
								)
							);
						}
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$delete_dupe_post = $wpdb->delete(
						$wpdb->posts,
						[
							'ID' => $dupe_post_id,
						]
					);

					if ( $delete_dupe_post ) {
						WP_CLI::success( sprintf( 'Deleted duplicate Post ID: %d', $dupe_post_id ) );
					} else {
						WP_CLI::warning( sprintf( 'Failed to delete duplicate Post ID: %d', $dupe_post_id ) );
					}
				}
			}
		}
	}

	private function handle_saving_post_content_as_meta( string $post_content, int $post_id ) {
		$key = 'lasillacom_update_script_';

		global $wpdb;

		$key_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_key FROM $wpdb->postmeta WHERE meta_key LIKE %s AND post_id = %d",
				$wpdb->esc_like( $key ) . '%',
				$post_id
			)
		);

		if ( $key_exists ) {
			$number = explode( '_', $key_exists );
			$number = (int) array_pop( $number );
			$number++;
			$key .= $number;
		} else {
			$key .= '1';
		}

		update_post_meta( $post_id, $key, $post_content );
	}

	public function cmd_fix_featured_images_for_publicaciones_directly( $args, $assoc_args ) {
		$post_ids = $assoc_args['post-ids'];
		$post_ids = explode( ',', $post_ids );

		foreach ( $post_ids as $post_id ) {
			echo "\n\n\n";
			$this->high_contrast_output( 'Post ID', $post_id );
			$post = get_post( $post_id );

			$featured_image = $this->attachments->get_images_sources_from_content( $post->post_content );

			if ( is_array( $featured_image ) && ! empty( $featured_image ) ) {
				$featured_image = $featured_image[0];
				$this->high_contrast_output( 'Featured Image', $featured_image );

				$path = wp_parse_url( $featured_image )['path'];
				$path = str_replace( '/wp-content/uploads/', '', $path );

				$this->high_contrast_output( 'Filename', $path );

				global $wpdb;

				$potential_attachment_id = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
						$path
					)
				);

				if ( $potential_attachment_id ) {
					$potential_attachment_url = wp_get_attachment_url( $potential_attachment_id );
					$potential_attachment_url = str_replace( 'www.', '', $potential_attachment_url );
					$potential_attachment_url = str_replace( 'https://', '', $potential_attachment_url );
					if ( str_contains( $post->post_content, $potential_attachment_url ) ) {
						echo WP_CLI::colorize( "%gUpdating meta%n\n" );
						update_post_meta( $post_id, '_thumbnail_id', $potential_attachment_id );
						update_post_meta( $post_id, 'newspack_featured_image_position', 'hidden' );
					}
				}
			}
		}
	}

	private function handle_podcast_audio( string $audio, string $media_location ) {
		// Strip the "media" folder part of the path in the json.
		$file_path = $media_location . str_ireplace( '/media', '', $audio );

		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		$attachment_id = $this->attachments->import_external_file( $file_path );
		$audio_url     = wp_get_attachment_url( $attachment_id );
		return <<<BLOCK
<!-- wp:audio {"id":$attachment_id} -->
<figure class="wp-block-audio"><audio controls src="$audio_url"></audio></figure>
<!-- /wp:audio -->
BLOCK;
	}

	/**
	 * @param string $html
	 * @return string
	 */
	private function handle_extracting_html_content( string $html ) {
		$dom           = new DOMDocument();
		$dom->encoding = 'utf-8';
		@$dom->loadHTML( utf8_decode( htmlentities( $html ) ) );
		$xpath = new DOMXPath( $dom );
		/* @var DOMNodeList $nodes */
		$nodes = $xpath->query( '//@*' );

		foreach ( $nodes as $node ) {
			/* @var DOMElement $node */
			if ( 'href' === $node->nodeName ) {
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
	private function handle_profile_photo( string $filename, string $media_location ): int {
		$media_location = trailingslashit( $media_location );
		if ( file_exists( $media_location . $filename ) ) {
			return $this->attachments->import_external_file(
				$media_location . $filename,
				false,
				false,
				false,
				false,
				0,
			);
		}

		$base_dir = wp_upload_dir()['basedir'];

		$output = shell_exec( "find '$base_dir' -name '$filename'" );
		$files  = explode( "\n", trim( $output ) );

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
			array(
				'guid'           => wp_upload_dir()['url'] . '/' . $filename,
				'post_mime_type' => wp_get_image_mime( $file_path ),
				'post_title'     => sanitize_file_name( $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'comment_status' => 'closed',
			),
			$file_path
		);
	}

	/**
	 * Look in the DB to see if a record already exists for file. If so, return the attachment/post ID.
	 *
	 * @param string $filename
	 * @return string|null
	 */
	private function get_attachment_id( string $filename ) {
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
	 * @param bool   $output
	 */
	private function file_logger( string $message, bool $output = true ) {
		file_put_contents( $this->log_file_path, "$message\n", FILE_APPEND );

		if ( $output ) {
			WP_CLI::log( $message );
		}
	}
}

class MigrationAuthor {

	protected CoAuthorPlus $coauthorsplus_logic;

	protected int $original_system_id;

	protected string $description = '';

	protected ?WP_User $wp_user = null;

	protected ?\stdClass $guest_author = null;

	/**
	 * @throws Exception
	 */
	public function __construct( int $original_system_id ) {
		$this->original_system_id  = $original_system_id;
		$this->coauthorsplus_logic = new CoAuthorPlus();
		$this->find_user_from_original_system_id();
		$this->set_output_description();
	}

	public function get_original_system_id(): int {
		return $this->original_system_id;
	}

	public function get_wp_user(): WP_User {
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
			} elseif ( $this->is_wp_user() ) {
				// Must update `wp_posts.post_author` manually here.
				$update = wp_update_post(
					array(
						'ID'          => $post_id,
						'post_author' => $this->get_wp_user()->ID,
					)
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
		/*
		if ( ! $assigned_to_post && ! $append && ! $this->is_wp_user() && $this->is_guest_author() ) {
			return true;
		} else {
			return $assigned_to_post;
		}*/
	}

	public function get_author_data( bool $appending = false ): array {
		// TODO Once PR is accepted: https://github.com/Automattic/Co-Authors-Plus/pull/988 we can return with 'guest_author' immediately, if available, since it should be linked to WP_User.
		$author_data = array(
			'wp_user'      => null,
			'guest_author' => null,
		);

		if ( $this->is_guest_author() ) {
			// $author_data['guest_author'] = $this->get_guest_author()->user_nicename;
			return array( $this->get_guest_author()->user_nicename );
		} else {
			// unset( $author_data['guest_author'] );
		}

		if ( $this->is_wp_user() ) {
			// $author_data['wp_user'] = $this->get_wp_user()->user_nicename;
			return array( $this->get_wp_user()->user_nicename );
		} else {
			// unset( $author_data['wp_user'] );
		}

		/*
		if ( $appending && $this->is_guest_author() ) {
			// If appending author, must remove wp_user to not overwrite wp_post.post_author
			unset( $author_data['wp_user'] );
		}

		return array_values( $author_data );*/
	}

	private function set_output_description(): void {
		$description = '';

		if ( $this->is_wp_user() ) {
			$description .= "WP_User.ID: {$this->get_wp_user()->ID}";
		}

		if ( $this->is_guest_author() ) {
			if ( ! empty( $description ) ) {
				$description .= ' | ';
			}
			$description .= "GA.ID: {$this->get_guest_author()->ID}";
		}

		$this->description = $description;
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

class MigrationPostAuthors {


	protected CoAuthorPlus $coauthorsplus_logic;

	/**
	 * @var MigrationAuthor[] $authors
	 */
	protected array $authors = array();

	/**
	 * @var MigrationAuthor|null $first_wp_user
	 */
	protected ?MigrationAuthor $first_wp_user = null;

	/**
	 * @throws Exception
	 */
	public function __construct( array $original_author_ids ) {
		$this->coauthorsplus_logic = new CoAuthorPlus();

		foreach ( $original_author_ids as $original_author_id ) {
			$author = new MigrationAuthor( $original_author_id );

			if ( is_null( $this->first_wp_user ) && $author->is_wp_user() ) {
				$this->first_wp_user = $author;
			}

			$this->authors[] = $author;
		}
	}

	public function assign_to_post( int $post_id ) {
		$author_data = array();

		foreach ( $this->get_authors() as $author ) {
			$author_data = array_merge( $author_data, $author->get_author_data() );
		}

		$assigned_to_post = $this->coauthorsplus_logic->coauthors_plus->add_coauthors( $post_id, $author_data );

		if ( ! $assigned_to_post && is_null( $this->first_wp_user ) ) {
			foreach ( $this->get_authors() as $author ) {
				if ( $author->is_guest_author() ) {
					return true;
				}
			}
		}

		return $assigned_to_post;
	}

	/**
	 * @return MigrationAuthor[]
	 */
	public function get_authors(): array {
		return $this->authors;
	}
}
