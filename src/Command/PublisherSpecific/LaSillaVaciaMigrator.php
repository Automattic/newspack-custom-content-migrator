<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

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
}