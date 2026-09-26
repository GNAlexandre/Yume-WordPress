<?php
/**
 * Versionnage de Yume Novel : une version par commit.
 *
 * La version est écrite à cinq endroits, toujours identiques : en-tête « Version » et constante
 * YUME_CORE_VERSION du plugin, en-tête « Version » du thème (style.css), « Stable tag » et
 * dernière entrée du readme du thème, version de l'asset de l'éditeur du bloc theme-toggle.
 * CHANGELOG.md reçoit une entrée par version.
 *
 * Numérotation (semver, compatible version_compare() de WordPress) :
 *   2.0.0-dev.1, 2.0.0-dev.2, …   développement avant la première release 2.0.0 ;
 *   2.0.0                          release (tag v2.0.0) ;
 *   2.0.1, 2.1.0, 3.0.0            correctif, évolution, rupture ; puis 2.0.1-dev.1… entre deux.
 *
 * Usage (PHP 8.1+, sans WordPress) :
 *
 *   php tools/build/version.php                                  affiche la version
 *   php tools/build/version.php --suivante "Ce que change le commit"          dev.N → dev.N+1
 *   php tools/build/version.php --suivante=patch|minor|major|release "…"      autre incrément
 *   php tools/build/version.php --fixer=2.1.0 "…"                version donnée
 *   php tools/build/version.php --verifier                       fichiers cohérents (CI)
 *   php tools/build/version.php --verifier --depuis=REF          et version > celle de REF (hook, CI)
 *
 * @package Yume\Core
 */

// Script en ligne de commande uniquement.
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$yume_v_racine = dirname( __DIR__, 2 );
$yume_v_opts   = getopt( '', array( 'suivante::', 'fixer:', 'verifier', 'depuis:', 'aide' ), $yume_v_rest );
$yume_v_note   = trim( implode( ' ', array_slice( $argv, $yume_v_rest ) ) );

if ( isset( $yume_v_opts['aide'] ) ) {
	echo "php tools/build/version.php [--suivante[=dev|patch|minor|major|release] \"note\" | --fixer=X.Y.Z \"note\" | --verifier [--depuis=REF]]\n";
	exit( 0 );
}

const YUME_V_MOTIF = '/^(\d+)\.(\d+)\.(\d+)(?:-dev\.(\d+)|-([0-9A-Za-z.-]+))?$/';

/**
 * Emplacements de la version : fichier, expression (groupe 1 = version), remplacement (%s).
 *
 * @return array<int,array{0:string,1:string,2:string}>
 */
function yume_v_emplacements(): array {
	return array(
		array( 'wp-content/plugins/yume-core/yume-core.php', '/^( \* Version:\s+)(\S+)$/m', '${1}%s' ),
		array( 'wp-content/plugins/yume-core/yume-core.php', "/(define\\( 'YUME_CORE_VERSION', ')([^']+)(' \\);)/", '${1}%s${3}' ),
		array( 'wp-content/themes/yume/style.css', '/^(Version:\s+)(\S+)$/m', '${1}%s' ),
		array( 'wp-content/themes/yume/readme.txt', '/^(Stable tag:\s+)(\S+)$/m', '${1}%s' ),
		array( 'wp-content/themes/yume/blocks/theme-toggle/editor.asset.php', "/('version'\\s*=>\\s*')([^']+)(')/", '${1}%s${3}' ),
	);
}

/**
 * Versions lues à chaque emplacement.
 *
 * @param string $racine Racine du dépôt.
 * @return array<string,string> « fichier#n » => version ('' si introuvable).
 */
function yume_v_lire( string $racine ): array {
	$lues = array();
	foreach ( yume_v_emplacements() as $n => list( $fichier, $motif ) ) {
		$contenu                     = (string) @file_get_contents( $racine . '/' . $fichier ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		$lues[ $fichier . '#' . $n ] = preg_match( $motif, $contenu, $m ) ? ( 3 === count( $m ) || 4 === count( $m ) ? $m[2] : '' ) : '';
	}
	return $lues;
}

/**
 * Version suivante.
 *
 * @param string $actuelle Version actuelle.
 * @param string $type     dev, patch, minor, major ou release.
 */
function yume_v_suivante( string $actuelle, string $type ): string {
	if ( ! preg_match( YUME_V_MOTIF, $actuelle, $m ) ) {
		fwrite( STDERR, "Version actuelle non reconnue : {$actuelle}\n" );
		exit( 1 );
	}
	list( , $ma, $mi, $pa ) = $m;
	$dev                    = isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : null;
	$pre                    = isset( $m[5] ) && '' !== $m[5] ? $m[5] : null;
	switch ( $type ) {
		case 'release':
			return "{$ma}.{$mi}.{$pa}";
		case 'patch':
			return $dev || $pre ? "{$ma}.{$mi}.{$pa}" : "{$ma}.{$mi}." . ( $pa + 1 );
		case 'minor':
			return "{$ma}." . ( $mi + 1 ) . '.0';
		case 'major':
			return ( $ma + 1 ) . '.0.0';
		default:
			// dev : 2.0.0-dev → 2.0.0-dev.1 ; 2.0.0-dev.4 → 2.0.0-dev.5 ; 2.0.0 (publiée) → 2.0.1-dev.1.
			if ( null !== $dev ) {
				return "{$ma}.{$mi}.{$pa}-dev." . ( $dev + 1 );
			}
			if ( null !== $pre ) {
				return "{$ma}.{$mi}.{$pa}-dev.1";
			}
			return "{$ma}.{$mi}." . ( $pa + 1 ) . '-dev.1';
	}
}

/**
 * Écrit la version partout, et l'entrée du CHANGELOG.
 *
 * @param string $racine  Racine.
 * @param string $version Version.
 * @param string $note    Note du CHANGELOG.
 */
function yume_v_ecrire( string $racine, string $version, string $note ): void {
	foreach ( yume_v_emplacements() as list( $fichier, $motif, $remplacement ) ) {
		$chemin  = $racine . '/' . $fichier;
		$contenu = (string) file_get_contents( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$nouveau = preg_replace( $motif, sprintf( $remplacement, $version ), $contenu, 1, $compte );
		if ( 1 !== $compte ) {
			fwrite( STDERR, "Version introuvable dans {$fichier}\n" );
			exit( 1 );
		}
		file_put_contents( $chemin, $nouveau ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	// Readme du thème : l'entrée « = X = » la plus récente du Changelog suit la version.
	$readme  = $racine . '/wp-content/themes/yume/readme.txt';
	$contenu = (string) file_get_contents( $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$contenu = (string) preg_replace( '/^= [^=\n]+ =$/m', '= ' . $version . ' =', $contenu, 1 );
	file_put_contents( $readme, $contenu ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	$journal = $racine . '/CHANGELOG.md';
	$entete  = "# Journal des versions\n\nUne entrée par version ; une version par commit (tools/build/version.php, docs/guide-developpeur.md).\n";
	$actuel  = is_readable( $journal ) ? (string) file_get_contents( $journal ) : $entete; // phpcs:ignore WordPress.WP.AlternativeFunctions
	$corps   = str_starts_with( $actuel, '# Journal des versions' ) ? substr( $actuel, strlen( $entete ) ) : $actuel;
	$entree  = "\n## {$version} — " . gmdate( 'Y-m-d' ) . "\n\n- " . ( '' !== $note ? $note : 'Sans description.' ) . "\n";
	file_put_contents( $journal, $entete . $entree . $corps ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

$yume_v_lues   = yume_v_lire( $yume_v_racine );
$yume_v_uniq   = array_unique( array_values( $yume_v_lues ) );
$yume_v_courte = (string) reset( $yume_v_uniq );

if ( isset( $yume_v_opts['verifier'] ) ) {
	$yume_v_ok = true;
	if ( 1 !== count( $yume_v_uniq ) || '' === $yume_v_courte ) {
		fwrite( STDERR, "Versions incohérentes :\n" );
		foreach ( $yume_v_lues as $yume_v_ou => $yume_v_val ) {
			fwrite( STDERR, "  {$yume_v_ou} : " . ( '' !== $yume_v_val ? $yume_v_val : '(absente)' ) . "\n" );
		}
		$yume_v_ok = false;
	} elseif ( ! preg_match( YUME_V_MOTIF, $yume_v_courte ) ) {
		fwrite( STDERR, "Version invalide : {$yume_v_courte}\n" );
		$yume_v_ok = false;
	} elseif ( ! str_contains( (string) @file_get_contents( $yume_v_racine . '/CHANGELOG.md' ), '## ' . $yume_v_courte . ' ' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		fwrite( STDERR, "CHANGELOG.md n'a pas d'entrée pour {$yume_v_courte}\n" );
		$yume_v_ok = false;
	}
	if ( $yume_v_ok && isset( $yume_v_opts['depuis'] ) ) {
		$yume_v_ref = (string) $yume_v_opts['depuis'];
		$yume_v_ancien = '';
		$yume_v_sortie = array();
		exec( 'git -C ' . escapeshellarg( $yume_v_racine ) . ' show ' . escapeshellarg( $yume_v_ref . ':wp-content/plugins/yume-core/yume-core.php' ) . ' 2>/dev/null', $yume_v_sortie ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( preg_match( "/define\\( 'YUME_CORE_VERSION', '([^']+)' \\);/", implode( "\n", $yume_v_sortie ), $yume_v_m ) ) {
			$yume_v_ancien = $yume_v_m[1];
		}
		if ( '' !== $yume_v_ancien && version_compare( $yume_v_courte, $yume_v_ancien, '<=' ) ) {
			fwrite( STDERR, "La version n'a pas été augmentée : {$yume_v_courte} (déjà {$yume_v_ancien} dans {$yume_v_ref}).\n" );
			fwrite( STDERR, "Lancer : php tools/build/version.php --suivante \"ce que change le commit\"\n" );
			$yume_v_ok = false;
		}
	}
	if ( $yume_v_ok ) {
		echo "Version {$yume_v_courte} : cohérente.\n";
	}
	exit( $yume_v_ok ? 0 : 1 );
}

if ( isset( $yume_v_opts['fixer'] ) || array_key_exists( 'suivante', $yume_v_opts ) ) {
	if ( 1 !== count( $yume_v_uniq ) ) {
		fwrite( STDERR, "Versions incohérentes avant changement ; corriger d'abord (--verifier).\n" );
		exit( 1 );
	}
	$yume_v_type    = is_string( $yume_v_opts['suivante'] ?? null ) ? $yume_v_opts['suivante'] : 'dev';
	$yume_v_nouvelle = isset( $yume_v_opts['fixer'] ) ? (string) $yume_v_opts['fixer'] : yume_v_suivante( $yume_v_courte, $yume_v_type );
	if ( ! preg_match( YUME_V_MOTIF, $yume_v_nouvelle ) ) {
		fwrite( STDERR, "Version invalide : {$yume_v_nouvelle}\n" );
		exit( 1 );
	}
	yume_v_ecrire( $yume_v_racine, $yume_v_nouvelle, $yume_v_note );
	echo "{$yume_v_courte} → {$yume_v_nouvelle}\n";
	exit( 0 );
}

echo $yume_v_courte . "\n";
