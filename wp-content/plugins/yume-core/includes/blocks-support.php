<?php
/**
 * Blocs dynamiques Yume : enregistrement commun et éditeur sans étape de build.
 *
 * Chaque module enregistre ses blocs avec yume_register_dynamic_block( __DIR__ . '/blocks/<nom>' ).
 * Le dossier contient un block.json (apiVersion 3, "name": "yume/<nom>", "render": "file:./render.php")
 * et un render.php. Dans l'éditeur, le script partagé affiche un aperçu serveur (ServerSideRender)
 * et génère les réglages à partir des attributs (string, number, boolean, enum).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Noms des blocs dynamiques enregistrés, transmis au script d'éditeur.
 *
 * @var string[]
 */
$GLOBALS['yume_dynamic_blocks'] = array();

/**
 * Enregistre un bloc dynamique Yume depuis un dossier contenant block.json.
 *
 * @param string $dir  Chemin absolu du dossier du bloc.
 * @param array  $args Arguments supplémentaires pour register_block_type().
 * @return WP_Block_Type|false
 */
function yume_register_dynamic_block( string $dir, array $args = array() ) {
	$type = register_block_type( $dir, $args );
	if ( $type instanceof WP_Block_Type ) {
		$GLOBALS['yume_dynamic_blocks'][] = $type->name;
	}
	return $type;
}

add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		wp_enqueue_script(
			'yume-dynamic-blocks',
			YUME_CORE_URL . 'assets/editor/dynamic-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			YUME_CORE_VERSION,
			true
		);
		wp_add_inline_script(
			'yume-dynamic-blocks',
			'window.yumeDynamicBlocks = ' . wp_json_encode( array_values( array_unique( $GLOBALS['yume_dynamic_blocks'] ) ) ) . ';',
			'before'
		);
	}
);

add_filter(
	'block_categories_all',
	static function ( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'yume',
				'title' => __( 'Yume Novel', 'yume-core' ),
				'icon'  => null,
			)
		);
		return $categories;
	}
);
