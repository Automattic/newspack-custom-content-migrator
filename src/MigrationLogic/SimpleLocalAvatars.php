<?php

namespace NewspackCustomContentMigrator\MigrationLogic;
use Simple_Local_Avatars;

class SimpleLocalAvatars {

	/**
	 * @var string Avatar meta key
	 */
	const AVATAR_META_KEY = 'simple_local_avatar_rating';

	/**
	 * @var string Rating meta key
	 */
	const AVATAR_RATING_META_KEY = 'simple_local_avatar_rating';

	/**
	 * @var null|Simple_Local_Avatars Instance of Simple_Local_Avatars
	 */
	public $simple_local_avatars;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$plugins_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

		$simple_local_avatars_plugin_file = $plugins_path . '/simple-local-avatars/simple-local-avatars.php';
		
		if ( is_file( $simple_local_avatars_plugin_file ) && include_once $simple_local_avatars_plugin_file ) {
			$this->simple_local_avatars = new Simple_Local_Avatars();
		}
	}

	/**
	 * Attach an avatar to a user through Simple Local Avatars
	 * 
	 * @param int $user_id The user ID
	 * @param int $attachment_id The attachment ID that has the avatar
	 * @param string $rating The avatar rating (G, PG, R, X)
	 *
	 * @return boolean True on success, false otherwise
	 */
	public function import_avatar( $user_id, $attachment_id, $rating ) {
		$this->simple_local_avatars->assign_new_user_avatar( (int) $attachment_id, $user_id );

		update_user_meta( $user_id, self::AVATAR_RATING_META_KEY, $rating );

		$avatar = get_user_meta( $user_id, self::AVATAR_META_KEY, true );

		if ( ! empty( $avatar ) ) {
			return false;
		}

		return true;
	}

	public function is_sla_plugin_active() {
		return $this->simple_local_avatars !== null;
	}
}
