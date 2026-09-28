<?php
/**
 * Génère les blueprints WordPress Playground de Yume Novel. Le contenu de démonstration
 * (étape runPHP) est recopié depuis demo.php : c'est ce fichier qu'on modifie, jamais le JSON.
 *
 * Fichiers produits : blueprint.json (plugin et thème téléchargés depuis la dernière release
 * GitHub), blueprint-branche.json (plugin et thème lus directement dans une branche du dépôt) et
 * blueprint-local.json (plugin et thème de la copie locale du dépôt, montés par le CLI Playground :
 * voir lancer.sh).
 *
 * Usage (PHP 8.1+, sans WordPress) :
 *
 *   php tools/playground/construire.php              réécrit les deux fichiers
 *   php tools/playground/construire.php --verifier   code de sortie 1 si un fichier n'est pas à jour (CI)
 *   php tools/playground/construire.php --depot=Proprietaire/Depot --branche=develop --sortie=/tmp
 *
 * @package Yume\Core
 */

// Script en ligne de commande uniquement.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$yume_pg_options = getopt( '', array( 'verifier', 'depot:', 'branche:', 'sortie:', 'aide' ) );
if ( isset( $yume_pg_options['aide'] ) ) {
	echo "php tools/playground/construire.php [--verifier] [--depot=Proprietaire/Depot] [--branche=main] [--sortie=dossier]\n";
	exit( 0 );
}

$yume_pg_depot   = (string) ( $yume_pg_options['depot'] ?? 'GNAlexandre/Yume-WordPress' );
$yume_pg_branche = (string) ( $yume_pg_options['branche'] ?? 'main' );
$yume_pg_sortie  = rtrim( (string) ( $yume_pg_options['sortie'] ?? __DIR__ ), '/' );
$yume_pg_verif   = isset( $yume_pg_options['verifier'] );

if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $yume_pg_depot ) ) {
	fwrite( STDERR, "Dépôt invalide : {$yume_pg_depot} (attendu Proprietaire/Depot)\n" );
	exit( 2 );
}
if ( ! preg_match( '#^[A-Za-z0-9._/-]+$#', $yume_pg_branche ) ) {
	fwrite( STDERR, "Branche invalide : {$yume_pg_branche}\n" );
	exit( 2 );
}

$yume_pg_demo = file_get_contents( __DIR__ . '/demo.php' );
if ( false === $yume_pg_demo || '' === trim( $yume_pg_demo ) ) {
	fwrite( STDERR, "demo.php illisible\n" );
	exit( 1 );
}
// Contenu réel des pages institutionnelles (pages-institutionnelles.json) : recopié dans le code
// de l'étape runPHP, qui ne peut pas lire les fichiers voisins dans Playground.
$yume_pg_pages = is_readable( __DIR__ . '/pages-institutionnelles.json' ) ? (string) file_get_contents( __DIR__ . '/pages-institutionnelles.json' ) : '';
if ( '' !== trim( $yume_pg_pages ) ) {
	$yume_pg_demo = preg_replace( '/^<\?php\n/', "<?php\n\$GLOBALS['yume_demo_pages_json'] = <<<'YUME_PAGES_JSON'\n" . rtrim( $yume_pg_pages ) . "\nYUME_PAGES_JSON;\n", $yume_pg_demo, 1 );
}
// Glossaire de démonstration (tools/fixtures/glossaire-exemple.yaml), recopié de même.
$yume_pg_glossaire = is_readable( dirname( __DIR__ ) . '/fixtures/glossaire-exemple.yaml' ) ? (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/glossaire-exemple.yaml' ) : '';
if ( '' !== trim( $yume_pg_glossaire ) ) {
	$yume_pg_demo = preg_replace( '/^<\?php\n/', "<?php\n\$GLOBALS['yume_demo_glossaire_yaml'] = <<<'YUME_GLOSSAIRE_YAML'\n" . rtrim( $yume_pg_glossaire ) . "\nYUME_GLOSSAIRE_YAML;\n", $yume_pg_demo, 1 );
}

/**
 * Étapes qui installent et activent le plugin et le thème depuis des ressources (url ou git:directory).
 *
 * @param array $plugin Ressource du plugin.
 * @param array $theme  Ressource du thème.
 * @return array
 */
function yume_pg_installation( array $plugin, array $theme ): array {
	return array(
		array(
			'step'       => 'installPlugin',
			'pluginData' => $plugin,
			'options'    => array(
				'activate'         => true,
				'targetFolderName' => 'yume-core',
			),
		),
		array(
			'step'      => 'installTheme',
			'themeData' => $theme,
			'options'   => array(
				'activate'         => true,
				'targetFolderName' => 'yume',
			),
		),
	);
}

/**
 * Blueprint commun : métadonnées, versions, langue, installation (étapes fournies) et démo.
 *
 * @param string $titre        Titre affiché dans Playground.
 * @param string $description  Description.
 * @param array  $installation Étapes d'installation du plugin et du thème.
 * @param string $demo         Code PHP de demo.php.
 * @return array
 */
function yume_pg_blueprint( string $titre, string $description, array $installation, string $demo ): array {
	return array(
		'$schema'           => 'https://playground.wordpress.net/blueprint-schema.json',
		'meta'              => array(
			'title'       => $titre,
			'description' => $description,
			'author'      => 'GNAlexandre',
			'categories'  => array( 'Yume Novel' ),
		),
		'landingPage'       => '/',
		'preferredVersions' => array(
			'php' => '8.3',
			'wp'  => 'latest',
		),
		'features'          => array(
			'intl' => true,
		),
		'login'             => true,
		'steps'             => array_merge(
			array(
				array(
					'step'     => 'setSiteLanguage',
					'language' => 'fr_FR',
				),
			),
			$installation,
			array(
				array(
					'step'     => 'runPHP',
					'progress' => array(
						'caption' => 'Contenu de démonstration',
					),
					'code'     => $demo,
				),
			)
		),
	);
}

$yume_pg_release = 'https://github.com/' . $yume_pg_depot . '/releases/latest/download/';
$yume_pg_git     = 'https://github.com/' . $yume_pg_depot;

$yume_pg_fichiers = array(
	'blueprint.json'         => yume_pg_blueprint(
		'Yume Novel — dernière version publiée',
		'Installe yume-core.zip et yume.zip de la dernière release GitHub, en français, avec un contenu de démonstration (comptes admin / password, equipe / equipe, lecteur / lecteur).',
		yume_pg_installation(
			array(
				'resource' => 'url',
				'url'      => $yume_pg_release . 'yume-core.zip',
				'caption'  => 'Téléchargement de yume-core.zip',
			),
			array(
				'resource' => 'url',
				'url'      => $yume_pg_release . 'yume.zip',
				'caption'  => 'Téléchargement de yume.zip',
			)
		),
		$yume_pg_demo
	),
	'blueprint-branche.json' => yume_pg_blueprint(
		'Yume Novel — branche ' . $yume_pg_branche,
		'Installe le plugin et le thème directement depuis la branche ' . $yume_pg_branche . ' du dépôt (sans release), en français, avec un contenu de démonstration.',
		yume_pg_installation(
			array(
				'resource' => 'git:directory',
				'url'      => $yume_pg_git,
				'ref'      => $yume_pg_branche,
				'refType'  => 'branch',
				'path'     => 'wp-content/plugins/yume-core',
			),
			array(
				'resource' => 'git:directory',
				'url'      => $yume_pg_git,
				'ref'      => $yume_pg_branche,
				'refType'  => 'branch',
				'path'     => 'wp-content/themes/yume',
			)
		),
		$yume_pg_demo
	),
	'blueprint-local.json'   => yume_pg_blueprint(
		'Yume Novel — copie locale',
		'Active le plugin et le thème de la copie locale du dépôt, montés par le CLI Playground (tools/playground/lancer.sh), en français, avec un contenu de démonstration.',
		array(
			array(
				'step'       => 'activatePlugin',
				'pluginPath' => 'yume-core/yume-core.php',
			),
			array(
				'step'            => 'activateTheme',
				'themeFolderName' => 'yume',
			),
		),
		$yume_pg_demo
	),
);

$yume_pg_ecarts = 0;
foreach ( $yume_pg_fichiers as $yume_pg_nom => $yume_pg_blueprint ) {
	$yume_pg_json   = json_encode( $yume_pg_blueprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ) . "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- hors WordPress.
	$yume_pg_chemin = $yume_pg_sortie . '/' . $yume_pg_nom;
	if ( $yume_pg_verif ) {
		$yume_pg_actuel = is_readable( $yume_pg_chemin ) ? file_get_contents( $yume_pg_chemin ) : '';
		if ( $yume_pg_actuel !== $yume_pg_json ) {
			fwrite( STDERR, "{$yume_pg_nom} n'est pas à jour : lancer « php tools/playground/construire.php ».\n" );
			++$yume_pg_ecarts;
		} else {
			echo "{$yume_pg_nom} : à jour.\n";
		}
		continue;
	}
	if ( false === file_put_contents( $yume_pg_chemin, $yume_pg_json ) ) {
		fwrite( STDERR, "Écriture impossible : {$yume_pg_chemin}\n" );
		exit( 1 );
	}
	echo "{$yume_pg_nom} écrit.\n";
}
exit( $yume_pg_ecarts ? 1 : 0 );
