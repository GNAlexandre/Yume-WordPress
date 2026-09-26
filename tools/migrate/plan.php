<?php
/**
 * Génère le plan de migration à partir de l'export de l'ancien site.
 *
 * Commande : tools/localenv/wp.sh eval-file tools/migrate/plan.php [dossier-export]
 *
 * Lit tools/migrate/export/ (ou le dossier passé en argument) et écrit dans ce même dossier :
 *   - plan.json         : plan complet (structure décrite dans tools/migrate/README.md) ;
 *   - rapport.md        : rapport lisible (comptes, anomalies) pour l'équipe ;
 *   - redirections.csv  : redirections au format d'import de l'extension Redirection.
 *
 * Aucune écriture en base : le script n'utilise que les classes pures du module migration.
 *
 * @package Yume\Core
 */

use Yume\Core\Migration\Export_Loader;
use Yume\Core\Migration\Migration_Planner;
use Yume\Core\Migration\Plan_Report;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( Migration_Planner::class ) ) {
	require_once dirname( __DIR__, 2 ) . '/wp-content/plugins/yume-core/includes/migration/module.php';
}

$yume_dossier = rtrim( (string) ( ( $args ?? array() )[0] ?? __DIR__ . '/export' ), '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

try {
	$yume_export = Export_Loader::depuis_dossier( $yume_dossier );
} catch ( \RuntimeException $yume_erreur ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $yume_erreur->getMessage() );
	}
	fwrite( STDERR, $yume_erreur->getMessage() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

$yume_plan = ( new Migration_Planner( $yume_export ) )->plan();

$yume_sorties = array(
	'plan.json'        => wp_json_encode( $yume_plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n",
	'rapport.md'       => Plan_Report::markdown( $yume_plan ),
	'redirections.csv' => Plan_Report::csv_redirections( $yume_plan ),
);
foreach ( $yume_sorties as $yume_nom => $yume_contenu ) {
	file_put_contents( $yume_dossier . '/' . $yume_nom, $yume_contenu ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

$yume_c       = $yume_plan['comptes'];
$yume_message = sprintf(
	'Plan écrit dans %s : %d œuvres, %d tomes, %d chapitres (%d migrés, %d planifiés), %d articles, %d redirections, avertissements : %s.',
	$yume_dossier,
	$yume_c['oeuvres']['total'],
	$yume_c['tomes']['total'],
	$yume_c['chapitres']['total'],
	$yume_c['chapitres']['migres'],
	$yume_c['chapitres']['planifies'],
	$yume_c['articles']['total'],
	$yume_c['redirections'],
	wp_json_encode( $yume_c['avertissements'] )
);
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::success( $yume_message );
} else {
	echo $yume_message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}
