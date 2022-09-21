<?php

namespace NewspackCustomContentMigrator;

use Exception;

/**
 * Abstract Class to establish Gutenberg Block contract,
 * and provide some low level functionality.
 */
abstract class AbstractGutenbergBlock implements GutenbergBlockInterface {

	/**
	 * Constructor.
	 *
	 * @param array $properties Key => Value pair for class properties.
	 */
	public function __construct( array $properties = [] ) {
		foreach ( $properties as $property_name => $value ) {
			$setter = "set_$property_name";
			$this->$setter( $value );
		}
	}

	/**
	 * Magic Method.
	 *
	 * @param string $name Method being called.
	 * @param array  $arguments Arguments being supplied.
	 *
	 * @throws Exception
	 */
	public function __call( string $name, array $arguments ) {
		if ( str_starts_with( $name, 'get_' ) ) {
			$property = substr( $name, 4 );

			if ( ! property_exists( $this, $property ) ) {
				throw new Exception( 'Property ' . $property . ' does not exist in ' . __CLASS__ );
			}

			if ( isset( $this->$property ) ) {
				return $this->$property;
			}

			return null; // Just to be explicit.
		} elseif ( str_starts_with( $name, 'set_' ) ) {
			$property = substr( $name, 4 );

			if ( ! property_exists( $this, $property ) ) {
				throw new Exception( 'Property ' . $property . ' does not exist in ' . __CLASS__ );
			}

			$this->$property = $arguments[0];
		}
	}
}
