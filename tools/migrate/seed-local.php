<?php
/**
 * Peuple une base WordPress LOCALE avec l'ancien site yumenovel.fr (avant migration), depuis
 * l'export JSON : pages, articles, catégories, médias factices, navigations et parties de
 * modèle, aux mêmes ID que la production. Sert aux tests de bout en bout de la migration et
 * à la revue par l'équipe (Yume → Migrer sur une copie locale).
 *
 * Usage : tools/localenv/wp.sh eval-file tools/migrate/seed-local.php [dossier-export] [options…]
 *
 * Options (arguments positionnels, WP-CLI n'acceptant pas d'options nommées ici) :
 *   medias=<chemin>   fichiers des médias sous ABSPATH/<chemin> (option upload_path), pour
 *                     isoler les médias de cette base, ex. medias=wp-content/uploads-migration2
 *   sans-images       ne génère pas les images de substitution
 *   sans-purge        n'efface pas les contenus existants (déconseillé : ID occupés)
 *
 * Par défaut le dossier est tools/migrate/export/ (export complet, non versionné) ; les
 * fixtures (tools/migrate/fixtures/) conviennent pour un essai rapide.
 *
 * DESTRUCTIF : tous les contenus de la base courante sont supprimés. Refusé hors
 * environnement local (voir Yume_Seed_Local::verifier_environnement()).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Yume\Core\Migration\Export_Loader::class ) ) {
	require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/yume-core/includes/migration/module.php';
}
require_once __DIR__ . '/lib/class-yume-seed-local.php';

$yume_seed_args    = array_values( (array) ( $args ?? array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
$yume_seed_dossier = __DIR__ . '/export';
$yume_seed_options = array();
foreach ( $yume_seed_args as $yume_seed_arg ) {
	$yume_seed_arg = (string) $yume_seed_arg;
	if ( str_starts_with( $yume_seed_arg, 'medias=' ) ) {
		$yume_seed_options['dossier_medias'] = substr( $yume_seed_arg, 7 );
	} elseif ( 'sans-images' === $yume_seed_arg ) {
		$yume_seed_options['images'] = false;
	} elseif ( 'sans-purge' === $yume_seed_arg ) {
		$yume_seed_options['purger'] = false;
	} elseif ( '' !== $yume_seed_arg ) {
		$yume_seed_dossier = rtrim( $yume_seed_arg, '/' );
	}
}

try {
	$yume_seed_rapport = Yume_Seed_Local::executer( $yume_seed_dossier, $yume_seed_options );
} catch ( \RuntimeException $yume_seed_erreur ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $yume_seed_erreur->getMessage() );
	}
	fwrite( STDERR, $yume_seed_erreur->getMessage() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

flush_rewrite_rules( false );

$yume_seed_c = $yume_seed_rapport['comptes'];
$yume_seed_m = sprintf(
	'Base locale peuplée depuis %s en %s s : %d pages, %d articles, %d catégories, %d médias, %d navigations, %d parties de modèle (%d ID d’origine conservés).',
	$yume_seed_dossier,
	$yume_seed_rapport['duree'],
	$yume_seed_c['pages'],
	$yume_seed_c['articles'],
	$yume_seed_c['categories'],
	$yume_seed_c['medias'],
	$yume_seed_c['navigations'],
	$yume_seed_c['template_parts'],
	$yume_seed_rapport['ids_conserves']
);
if ( class_exists( 'WP_CLI' ) ) {
	foreach ( $yume_seed_rapport['avertissements'] as $yume_seed_a ) {
		WP_CLI::warning( $yume_seed_a );
	}
	WP_CLI::success( $yume_seed_m );
} else {
	echo $yume_seed_m . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}
