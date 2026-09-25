<?php
/**
 * Fonctions internes du module lecture : réglages de lecture (valeurs par défaut, bornes,
 * liste blanche des polices, assainissement, lecture et écriture de la méta utilisateur
 * yume_reglages) et progression de lecture (table {$wpdb->prefix}yume_progression).
 *
 * Les autres modules passent par l'API publique (yume_get_progression() du module lecteurs)
 * ou appellent ces fonctions derrière un function_exists().
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

/** Méta utilisateur des réglages de lecture (contrat §13 et docs/03). */
const META_REGLAGES = 'yume_reglages';

/** Option : version du schéma de la table du module. */
const OPTION_SCHEMA = 'yume_reader_schema';

/** Version du schéma de la table progression. */
const VERSION_SCHEMA = '1';

/** Thèmes de lecture reconnus (html[data-yn-theme]). */
const THEMES = array( 'nuit', 'papier', 'sepia' );

/** Plus grand index de paragraphe accepté (garde-fou contre des valeurs absurdes). */
const PARAGRAPHE_MAX = 100000;

/**
 * Nom complet de la table de progression.
 */
function table_progression(): string {
	global $wpdb;
	return $wpdb->prefix . 'yume_progression';
}

/**
 * Date et heure GMT courantes au format MySQL.
 */
function maintenant_gmt(): string {
	return current_time( 'mysql', true );
}

/**
 * Convertit une date GMT « Y-m-d H:i:s » en ISO 8601 (UTC). Chaîne vide si invalide.
 *
 * @param string $gmt Date GMT.
 */
function iso( string $gmt ): string {
	if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		return '';
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? '' : gmdate( 'c', $ts );
}

/*
 * -----------------------------------------------------------------------------
 * Réglages de lecture
 * -----------------------------------------------------------------------------
 */

/**
 * Valeurs par défaut des réglages de lecture (docs/04 §4.2).
 *
 * @return array{size:int,lh:float,font:string,width:int,bgAlpha:float,theme:string}
 */
function defauts_reglages(): array {
	return array(
		'size'    => 18,
		'lh'      => 1.6,
		'font'    => 'literata',
		'width'   => 68,
		'bgAlpha' => 0.93,
		'theme'   => 'nuit',
	);
}

/**
 * Bornes des réglages numériques : clé => [minimum, maximum, pas].
 *
 * @return array<string,array{0:float,1:float,2:float}>
 */
function bornes_reglages(): array {
	return array(
		'size'    => array( 14, 26, 1 ),
		'lh'      => array( 1.3, 2.1, 0.05 ),
		'width'   => array( 56, 80, 1 ),
		'bgAlpha' => array( 0.6, 1, 0.01 ),
	);
}

/**
 * Polices proposées dans le panneau (ordre de la capture du client), avec des piles de repli
 * système. Literata (défaut) et Nunito Sans (repli d'Avenir) sont auto-hébergées par le thème.
 * Les piles ne contiennent jamais de saisie : elles servent telles quelles de valeur à --yn-font.
 *
 * @return array<string,array{label:string,pile:string}>
 */
function polices(): array {
	$polices = array(
		'avenir'       => array(
			'label' => 'Avenir Roman',
			'pile'  => '"Avenir Roman", "Avenir Next", Avenir, "Nunito Sans", "Segoe UI", system-ui, sans-serif',
		),
		'merriweather' => array(
			'label' => 'Merriweather',
			'pile'  => 'Merriweather, "Merriweather Serif", Georgia, "Times New Roman", serif',
		),
		'arial'        => array(
			'label' => 'Arial',
			'pile'  => 'Arial, "Liberation Sans", "Helvetica Neue", Helvetica, sans-serif',
		),
		'roboto'       => array(
			'label' => 'Roboto',
			'pile'  => 'Roboto, "Segoe UI", "Helvetica Neue", Arial, sans-serif',
		),
		'calibri'      => array(
			'label' => 'Calibri',
			'pile'  => 'Calibri, Carlito, "Segoe UI", Candara, sans-serif',
		),
		'times'        => array(
			'label' => 'Times New Roman',
			'pile'  => '"Times New Roman", Times, "Liberation Serif", "Nimbus Roman", serif',
		),
		'verdana'      => array(
			'label' => 'Verdana',
			'pile'  => 'Verdana, "DejaVu Sans", Geneva, Tahoma, sans-serif',
		),
		'georgia'      => array(
			'label' => 'Georgia',
			'pile'  => 'Georgia, "DejaVu Serif", "Times New Roman", serif',
		),
		'garamond'     => array(
			'label' => 'Garamond',
			'pile'  => 'Garamond, "EB Garamond", "Adobe Garamond Pro", "Cormorant Garamond", Georgia, serif',
		),
		'trebuchet'    => array(
			'label' => 'Trebuchet MS',
			'pile'  => '"Trebuchet MS", "Lucida Grande", "Segoe UI", Tahoma, sans-serif',
		),
		'courier'      => array(
			'label' => 'Courier New',
			'pile'  => '"Courier New", Courier, "Liberation Mono", "Nimbus Mono PS", monospace',
		),
		'literata'     => array(
			'label' => 'Literata',
			'pile'  => 'Literata, Georgia, "Times New Roman", serif',
		),
	);
	/**
	 * Filtre les polices du panneau de lecture (slug => ['label', 'pile']). Une pile ne doit
	 * contenir que des noms de familles CSS (aucune donnée saisie par un lecteur).
	 *
	 * @param array $polices Polices.
	 */
	$filtrees = apply_filters( 'yume_lecture_polices', $polices );
	if ( ! is_array( $filtrees ) ) {
		return $polices;
	}
	$propres = array();
	foreach ( $filtrees as $slug => $police ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug || ! is_array( $police ) || empty( $police['label'] ) || empty( $police['pile'] ) ) {
			continue;
		}
		$pile = (string) $police['pile'];
		// Une pile de polices : lettres, chiffres, espaces, guillemets droits, virgules et tirets.
		if ( ! preg_match( '/^[A-Za-z0-9 ,"\'\-]+$/', $pile ) ) {
			continue;
		}
		$propres[ $slug ] = array(
			'label' => (string) $police['label'],
			'pile'  => $pile,
		);
	}
	if ( ! isset( $propres['literata'] ) ) {
		$propres['literata'] = $polices['literata'];
	}
	return $propres;
}

/**
 * Libellés des thèmes de lecture.
 *
 * @return array<string,string>
 */
function libelles_themes(): array {
	return array(
		'nuit'   => __( 'Nuit', 'yume-core' ),
		'papier' => __( 'Papier', 'yume-core' ),
		'sepia'  => __( 'Sépia', 'yume-core' ),
	);
}

/**
 * Ramène un nombre dans ses bornes et l'arrondit au pas.
 *
 * @param mixed  $valeur Valeur brute.
 * @param string $cle    Clé du réglage (size, lh, width, bgAlpha).
 * @return float|int|null Null si la valeur n'est pas numérique.
 */
function borner( $valeur, string $cle ) {
	$bornes = bornes_reglages()[ $cle ] ?? null;
	if ( null === $bornes ) {
		return null;
	}
	if ( is_string( $valeur ) ) {
		$valeur = str_replace( ',', '.', trim( $valeur ) );
	}
	if ( ! is_numeric( $valeur ) ) {
		return null;
	}
	$valeur = max( (float) $bornes[0], min( (float) $bornes[1], (float) $valeur ) );
	if ( in_array( $cle, array( 'size', 'width' ), true ) ) {
		return (int) round( $valeur );
	}
	return round( $valeur, 2 );
}

/**
 * Assainit des réglages de lecture : clés connues seulement, nombres bornés, police et thème
 * pris dans la liste blanche. Les valeurs absentes ou invalides reprennent celles de $base.
 *
 * @param mixed      $brut Réglages reçus.
 * @param array|null $base Valeurs de départ (défaut : valeurs par défaut).
 * @return array{size:int,lh:float,font:string,width:int,bgAlpha:float,theme:string}
 */
function assainir_reglages( $brut, ?array $base = null ): array {
	$reglages = null === $base ? defauts_reglages() : array_merge( defauts_reglages(), $base );
	$brut     = is_array( $brut ) ? $brut : array();
	foreach ( array_keys( bornes_reglages() ) as $cle ) {
		if ( array_key_exists( $cle, $brut ) ) {
			$valeur = borner( $brut[ $cle ], $cle );
			if ( null !== $valeur ) {
				$reglages[ $cle ] = $valeur;
			}
		}
	}
	if ( isset( $brut['font'] ) && is_string( $brut['font'] ) && isset( polices()[ $brut['font'] ] ) ) {
		$reglages['font'] = $brut['font'];
	}
	if ( isset( $brut['theme'] ) && is_string( $brut['theme'] ) && in_array( $brut['theme'], THEMES, true ) ) {
		$reglages['theme'] = $brut['theme'];
	}
	// Types finaux garantis, même pour une base douteuse.
	$reglages['size']    = (int) borner( $reglages['size'], 'size' );
	$reglages['width']   = (int) borner( $reglages['width'], 'width' );
	$reglages['lh']      = (float) borner( $reglages['lh'], 'lh' );
	$reglages['bgAlpha'] = (float) borner( $reglages['bgAlpha'], 'bgAlpha' );
	if ( ! isset( polices()[ $reglages['font'] ] ) ) {
		$reglages['font'] = 'literata';
	}
	if ( ! in_array( $reglages['theme'], THEMES, true ) ) {
		$reglages['theme'] = 'nuit';
	}
	return array(
		'size'    => $reglages['size'],
		'lh'      => $reglages['lh'],
		'font'    => $reglages['font'],
		'width'   => $reglages['width'],
		'bgAlpha' => $reglages['bgAlpha'],
		'theme'   => $reglages['theme'],
	);
}

/**
 * Réglages enregistrés par un membre (assainis), ou null s'il n'en a jamais enregistré.
 *
 * @param int $user_id Utilisateur.
 */
function reglages_enregistres( int $user_id ): ?array {
	if ( $user_id <= 0 ) {
		return null;
	}
	$brut = get_user_meta( $user_id, META_REGLAGES, true );
	if ( is_string( $brut ) && '' !== $brut ) {
		$json = json_decode( $brut, true );
		$brut = is_array( $json ) ? $json : null;
	}
	if ( ! is_array( $brut ) || ! $brut ) {
		return null;
	}
	return assainir_reglages( $brut );
}

/**
 * Réglages effectifs d'un membre : ses réglages enregistrés, sinon les valeurs par défaut.
 *
 * @param int $user_id Utilisateur.
 */
function reglages_utilisateur( int $user_id ): array {
	return reglages_enregistres( $user_id ) ?? defauts_reglages();
}

/**
 * Enregistre des réglages (mise à jour partielle : les clés absentes gardent leur valeur).
 *
 * @param int   $user_id Utilisateur.
 * @param array $valeurs Réglages reçus.
 * @return array Réglages enregistrés.
 */
function enregistrer_reglages( int $user_id, array $valeurs ): array {
	$reglages = assainir_reglages( $valeurs, reglages_utilisateur( $user_id ) );
	update_user_meta( $user_id, META_REGLAGES, $reglages );
	return $reglages;
}

/**
 * Efface les réglages d'un membre (retour aux valeurs par défaut).
 *
 * @param int $user_id Utilisateur.
 */
function effacer_reglages( int $user_id ): void {
	delete_user_meta( $user_id, META_REGLAGES );
}

/**
 * Nombre décimal à la française, sans zéros inutiles (« 1,6 », « 1,65 », « 2 »).
 *
 * @param float $nombre Nombre.
 */
function nombre_fr( float $nombre ): string {
	return str_replace( '.', ',', rtrim( rtrim( number_format( $nombre, 2, '.', '' ), '0' ), '.' ) );
}

/**
 * Variables CSS du lecteur (§14) pour des réglages donnés.
 *
 * @param array $reglages Réglages assainis.
 * @return array<string,string>
 */
function variables_css( array $reglages ): array {
	$reglages = assainir_reglages( $reglages );
	$polices  = polices();
	return array(
		'--yn-size'     => $reglages['size'] . 'px',
		'--yn-lh'       => (string) $reglages['lh'],
		'--yn-font'     => $polices[ $reglages['font'] ]['pile'],
		'--yn-width'    => $reglages['width'] . 'ch',
		'--yn-bg-alpha' => (string) $reglages['bgAlpha'],
	);
}

/**
 * Description lisible des réglages (page compte) : libellé => valeur.
 *
 * @param array $reglages Réglages assainis.
 * @return array<string,string>
 */
function resume_reglages( array $reglages ): array {
	$reglages = assainir_reglages( $reglages );
	$polices  = polices();
	$themes   = libelles_themes();
	return array(
		__( 'Police', 'yume-core' )             => $polices[ $reglages['font'] ]['label'],
		__( 'Taille', 'yume-core' )             => sprintf( /* translators: %d : taille en pixels. */ __( '%d px', 'yume-core' ), $reglages['size'] ),
		__( 'Interligne', 'yume-core' )         => nombre_fr( $reglages['lh'] ),
		__( 'Thème', 'yume-core' )              => $themes[ $reglages['theme'] ],
		__( 'Opacité du fond', 'yume-core' )    => sprintf( /* translators: %d : pourcentage. */ __( '%d %%', 'yume-core' ), (int) round( $reglages['bgAlpha'] * 100 ) ),
		__( 'Largeur de colonne', 'yume-core' ) => sprintf( /* translators: %d : nombre de caractères. */ __( '%d caractères', 'yume-core' ), $reglages['width'] ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Progression de lecture
 * -----------------------------------------------------------------------------
 */

/**
 * La table de progression existe-t-elle (module installé) ?
 */
function table_prete(): bool {
	return VERSION_SCHEMA === get_option( OPTION_SCHEMA );
}

/**
 * Normalise une ligne de la table.
 *
 * @param array|object $ligne Ligne brute.
 * @return array{oeuvre_id:int,chapitre_id:int,tome_id:int,paragraphe:int,pourcentage:int,updated_at:string}
 */
function normaliser_ligne( $ligne ): array {
	$ligne = (array) $ligne;
	return array(
		'oeuvre_id'   => (int) ( $ligne['oeuvre_id'] ?? 0 ),
		'chapitre_id' => (int) ( $ligne['chapitre_id'] ?? 0 ),
		'tome_id'     => (int) ( $ligne['tome_id'] ?? 0 ),
		'paragraphe'  => (int) ( $ligne['paragraphe'] ?? 0 ),
		'pourcentage' => (int) ( $ligne['pourcentage'] ?? 0 ),
		'updated_at'  => (string) ( $ligne['updated_at'] ?? '' ),
	);
}

/**
 * Lignes de progression d'un membre, de la plus récente à la plus ancienne.
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre (0 : toutes).
 * @return array<int,array>
 */
function lignes_progression( int $user_id, int $oeuvre_id = 0 ): array {
	global $wpdb;
	if ( $user_id <= 0 || ! table_prete() ) {
		return array();
	}
	$table = table_progression();
	if ( $oeuvre_id > 0 ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$lignes = $wpdb->get_results( $wpdb->prepare( "SELECT oeuvre_id, chapitre_id, tome_id, paragraphe, pourcentage, updated_at FROM {$table} WHERE user_id = %d AND oeuvre_id = %d", $user_id, $oeuvre_id ), ARRAY_A );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$lignes = $wpdb->get_results( $wpdb->prepare( "SELECT oeuvre_id, chapitre_id, tome_id, paragraphe, pourcentage, updated_at FROM {$table} WHERE user_id = %d ORDER BY updated_at DESC, oeuvre_id ASC", $user_id ), ARRAY_A );
	}
	return array_map( __NAMESPACE__ . '\\normaliser_ligne', (array) $lignes );
}

/**
 * Le chapitre est-il un chapitre publié rattaché à une œuvre ?
 *
 * @param int $chapitre_id ID.
 */
function chapitre_lisible( int $chapitre_id ): bool {
	$post = $chapitre_id > 0 ? get_post( $chapitre_id ) : null;
	return $post instanceof \WP_Post && 'yume_chapitre' === $post->post_type && 'publish' === $post->post_status
		&& function_exists( 'yume_get_oeuvre_id' ) && yume_get_oeuvre_id( $chapitre_id ) > 0;
}

/**
 * Enregistre la position de lecture d'un membre (une ligne par œuvre : la dernière position
 * remplace la précédente). L'œuvre et le tome sont déduits du chapitre.
 *
 * @param int $user_id     Utilisateur.
 * @param int $chapitre_id Chapitre publié.
 * @param int $paragraphe  Index du paragraphe (≥ 0).
 * @param int $pourcentage Pourcentage lu du chapitre (0–100).
 * @return array|\WP_Error Ligne enregistrée.
 */
function enregistrer_progression( int $user_id, int $chapitre_id, int $paragraphe = 0, int $pourcentage = 0 ) {
	global $wpdb;
	if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
		return new \WP_Error( 'yume_utilisateur_invalide', __( 'Utilisateur inconnu.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! chapitre_lisible( $chapitre_id ) ) {
		return new \WP_Error( 'yume_chapitre_invalide', __( 'Ce chapitre n’existe pas ou n’est pas publié.', 'yume-core' ), array( 'status' => 400 ) );
	}
	if ( ! table_prete() ) {
		installer_tables();
	}
	$oeuvre_id = yume_get_oeuvre_id( $chapitre_id );
	$tome_id   = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $chapitre_id ) : 0;
	$donnees   = array(
		'user_id'     => $user_id,
		'oeuvre_id'   => $oeuvre_id,
		'tome_id'     => $tome_id,
		'chapitre_id' => $chapitre_id,
		'paragraphe'  => max( 0, min( PARAGRAPHE_MAX, $paragraphe ) ),
		'pourcentage' => max( 0, min( 100, $pourcentage ) ),
		'updated_at'  => maintenant_gmt(),
	);
	// REPLACE : compatible MySQL et SQLite, clé primaire (user_id, oeuvre_id).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$ok = $wpdb->replace( table_progression(), $donnees, array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' ) );
	if ( false === $ok ) {
		return new \WP_Error( 'yume_progression_echec', __( 'La position de lecture n’a pas pu être enregistrée.', 'yume-core' ), array( 'status' => 500 ) );
	}
	/**
	 * Position de lecture enregistrée.
	 *
	 * @param int   $user_id Utilisateur.
	 * @param array $ligne   Ligne enregistrée.
	 */
	do_action( 'yume_progression_enregistree', $user_id, normaliser_ligne( $donnees ) );
	return normaliser_ligne( $donnees );
}

/**
 * Supprime des lignes de progression selon une colonne.
 *
 * @param string $colonne user_id, oeuvre_id ou chapitre_id.
 * @param int    $valeur  Valeur.
 * @return int Nombre de lignes supprimées.
 */
function supprimer_progression_par( string $colonne, int $valeur ): int {
	global $wpdb;
	if ( $valeur <= 0 || ! in_array( $colonne, array( 'user_id', 'oeuvre_id', 'chapitre_id', 'tome_id' ), true ) || ! table_prete() ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$n = $wpdb->delete( table_progression(), array( $colonne => $valeur ), array( '%d' ) );
	return (int) $n;
}

/**
 * Supprime la progression d'un membre pour une œuvre.
 *
 * @param int $user_id   Utilisateur.
 * @param int $oeuvre_id Œuvre.
 */
function supprimer_progression( int $user_id, int $oeuvre_id ): bool {
	global $wpdb;
	if ( $user_id <= 0 || $oeuvre_id <= 0 || ! table_prete() ) {
		return false;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return (bool) $wpdb->delete(
		table_progression(),
		array(
			'user_id'   => $user_id,
			'oeuvre_id' => $oeuvre_id,
		),
		array( '%d', '%d' )
	);
}

/**
 * Libellé complet d'une position : « Grimgar of Fantasy and Ash · Tome 7 · Chapitre 3 ».
 *
 * @param int $chapitre_id Chapitre.
 */
function titre_position( int $chapitre_id ): string {
	if ( ! function_exists( 'yume_get_oeuvre_id' ) || 'yume_chapitre' !== get_post_type( $chapitre_id ) ) {
		return '';
	}
	$morceaux  = array();
	$oeuvre_id = yume_get_oeuvre_id( $chapitre_id );
	if ( $oeuvre_id ) {
		$morceaux[] = wp_strip_all_tags( get_the_title( $oeuvre_id ) );
	}
	$tome = function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $chapitre_id ) : '';
	if ( '' !== $tome ) {
		$morceaux[] = $tome;
	}
	$chapitre   = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $chapitre_id ) : '';
	$morceaux[] = '' !== $chapitre ? $chapitre : wp_strip_all_tags( get_the_title( $chapitre_id ) );
	return implode( ' · ', array_filter( $morceaux, 'strlen' ) );
}

/**
 * Ligne de progression enrichie pour l'affichage et l'API : URL du chapitre (avec l'ancre du
 * paragraphe), titres, date ISO. Null si le chapitre n'est plus lisible.
 *
 * @param array $ligne Ligne normalisée.
 * @return array|null
 */
function enrichir_ligne( array $ligne ): ?array {
	$ligne = normaliser_ligne( $ligne );
	if ( ! chapitre_lisible( $ligne['chapitre_id'] ) ) {
		return null;
	}
	$oeuvre = get_post( $ligne['oeuvre_id'] );
	if ( ! $oeuvre || 'publish' !== $oeuvre->post_status ) {
		return null;
	}
	$url = (string) get_permalink( $ligne['chapitre_id'] );
	return array_merge(
		$ligne,
		array(
			'url'         => $url,
			'url_reprise' => $ligne['paragraphe'] > 0 ? $url . '#yn-p-' . ( $ligne['paragraphe'] + 1 ) : $url,
			'titre'       => titre_position( $ligne['chapitre_id'] ),
			'oeuvre'      => wp_strip_all_tags( get_the_title( $oeuvre ) ),
			'tome'        => function_exists( 'yume_libelle_tome' ) ? yume_libelle_tome( $ligne['chapitre_id'] ) : '',
			'chapitre'    => function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( $ligne['chapitre_id'] ) : '',
			'updated_iso' => iso( $ligne['updated_at'] ),
		)
	);
}
