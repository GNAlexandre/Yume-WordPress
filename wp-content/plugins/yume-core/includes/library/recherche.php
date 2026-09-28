<?php
/**
 * Recherche du site (AMEL-04).
 *
 * 1. Ordre de la requête principale : la fiche d'une œuvre passe avant ses tomes et ses annonces.
 * 2. Moteur de la page de résultats (bloc yume/recherche, recherche-rendu.php) et des suggestions
 *    instantanées (GET /yume/v1/suggestions, recherche-rest.php) : résultats groupés (œuvres,
 *    tomes, actualités, texte des chapitres si le réglage recherche_chapitres est actif),
 *    insensibles à la casse et aux accents sur les deux moteurs (SQLite et MariaDB) : SQL ne
 *    fait qu'un préfiltre large (LIKE où chaque lettre accentuable devient « _ » quand la
 *    collation ne suffit pas) et PHP décide sur le texte « plié » (minuscules, sans accents).
 *
 * Ordre de la requête principale. Par défaut, WordPress classe les résultats d'une recherche
 * par pertinence du titre, puis par date : chercher « grimgar » listait d'abord les annonces
 * et les tomes les plus récents,
 * et la fiche de l'œuvre (page d'entrée voulue, contrat §10) n'arrivait qu'en page 2.
 * Pour la recherche principale du site public, l'ordre devient :
 * 1. les œuvres dont le titre (ou un titre alternatif) contient la recherche ;
 * 2. puis l'ordre de pertinence de WordPress (titre contenant la phrase, tous les mots…) ;
 * 3. à pertinence égale : œuvres, puis tomes, puis le reste ; enfin la date.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * La requête est-elle la recherche principale du site public ?
 *
 * @param \WP_Query $requete Requête.
 */
function est_recherche_publique( $requete ): bool {
	return $requete instanceof \WP_Query
		&& $requete->is_main_query()
		&& $requete->is_search()
		&& ! $requete->is_feed()
		&& ! is_admin()
		&& '' !== trim( (string) $requete->get( 's' ) )
		&& in_array( $requete->get( 'orderby' ), array( '', 'relevance' ), true );
}

/**
 * Ordre des résultats de la recherche principale : œuvres correspondantes d'abord.
 *
 * @param string    $ordre   Clause ORDER BY de pertinence calculée par WordPress (peut être vide).
 * @param \WP_Query $requete Requête.
 * @return string
 */
function ordre_recherche( $ordre, $requete ): string {
	$ordre = (string) $ordre;
	if ( ! est_recherche_publique( $requete ) ) {
		return $ordre;
	}
	global $wpdb;
	$phrase = trim( (string) $requete->get( 's' ) );
	$phrase = trim( $phrase, "\"' \t\n\r" );
	if ( '' === $phrase ) {
		return $ordre;
	}
	$like = '%' . $wpdb->esc_like( $phrase ) . '%';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- noms de tables uniquement.
	$oeuvre = $wpdb->prepare(
		"CASE WHEN {$wpdb->posts}.post_type = %s AND ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'yume_titres_alt' AND meta_value LIKE %s ) ) THEN 0 ELSE 1 END ASC",
		TYPE_OEUVRE,
		$like,
		$like
	);
	$type   = $wpdb->prepare(
		"CASE {$wpdb->posts}.post_type WHEN %s THEN 0 WHEN %s THEN 1 ELSE 2 END ASC",
		TYPE_OEUVRE,
		TYPE_TOME
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return implode( ', ', array_filter( array( $oeuvre, $ordre, $type ) ) );
}
add_filter( 'posts_search_orderby', __NAMESPACE__ . '\\ordre_recherche', 10, 2 );

/*
 * -----------------------------------------------------------------------------
 * Moteur : constantes et réglage
 * -----------------------------------------------------------------------------
 */

/** Longueur (caractères) de l'extrait affiché autour d'une occurrence. */
const LONGUEUR_EXTRAIT = 200;

/** Longueur minimale d'une demande de suggestions. */
const MIN_SUGGESTIONS = 2;

/** Nombre maximal de suggestions. */
const MAX_SUGGESTIONS = 8;

/** Longueur minimale du terme pour chercher dans le texte des chapitres. */
const MIN_CHAPITRES = 3;

/** Longueur maximale d'un terme de recherche (caractères). */
const MAX_TERME = 100;

/** Nombre maximal de mots pris en compte. */
const MAX_MOTS = 6;

require_once __DIR__ . '/recherche-rendu.php';
require_once __DIR__ . '/recherche-rest.php';

/**
 * La recherche dans le texte des chapitres est-elle active ? Réglage recherche_chapitres
 * (défaut : non, pour le coût d'un LIKE sur les textes) puis filtre.
 */
function recherche_chapitres_active(): bool {
	$actif = function_exists( 'yume_setting' ) ? (bool) yume_setting( 'recherche_chapitres', false ) : false;
	/**
	 * Active ou désactive la recherche dans le texte des chapitres.
	 *
	 * @param bool $actif Valeur du réglage « Recherche dans les chapitres ».
	 */
	return (bool) apply_filters( 'yume_recherche_chapitres_active', $actif );
}

/**
 * Champ « Recherche dans les chapitres » de Yume → Réglages (filtre yume_reglages_champs).
 *
 * @param array $champs Champs.
 * @return array
 */
function champ_reglage_recherche( $champs ): array {
	$champs   = is_array( $champs ) ? $champs : array();
	$champs[] = array(
		'key'         => 'recherche_chapitres',
		'label'       => __( 'Recherche dans les chapitres', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => 'site',
		'default'     => false,
		'description' => __( 'Ajoute aux résultats de la recherche un groupe « Dans les chapitres » (extrait autour du mot trouvé). Plus coûteux pour le serveur : seuls les chapitres publiés d’œuvres ni licenciées ni retirées sont lus, 60 au plus par recherche, pour un terme de 3 caractères ou plus.', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champ_reglage_recherche' );

/**
 * Métadonnées d'œuvre lues par la recherche : leur changement renouvelle le cache.
 *
 * @param int|int[] $meta_id   ID(s) de la métadonnée.
 * @param int       $object_id ID du contenu.
 * @param string    $meta_key  Clé.
 */
function invalider_meta_recherche( $meta_id, $object_id, $meta_key ): void {
	if ( in_array( $meta_key, array( 'yume_titres_alt', 'yume_auteur', 'yume_illustrateur', 'yume_editeur_vo', '_thumbnail_id' ), true ) && est_contenu_yume( (int) $object_id ) ) {
		invalider();
	}
}
add_action( 'added_post_meta', __NAMESPACE__ . '\\invalider_meta_recherche', 20, 3 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\\invalider_meta_recherche', 20, 3 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\\invalider_meta_recherche', 20, 3 );

/*
 * -----------------------------------------------------------------------------
 * Texte « plié » : minuscules, sans accents
 * -----------------------------------------------------------------------------
 */

/**
 * Plie un texte pour la comparaison : minuscules, sans accents ni ligatures (« Œ » → « oe »),
 * apostrophes et tirets typographiques ramenés à leur forme simple, espaces insécables ordinaires.
 *
 * @param string $texte Texte (UTF-8).
 */
function plier( string $texte ): string {
	$texte = remove_accents( mb_strtolower( $texte, 'UTF-8' ) );
	return strtr(
		$texte,
		array(
			"\u{2019}" => "'",
			"\u{2018}" => "'",
			"\u{02BC}" => "'",
			"\u{00A0}" => ' ',
			"\u{202F}" => ' ',
			"\u{2013}" => '-',
			"\u{2014}" => '-',
		)
	);
}

/**
 * Plie un texte caractère par caractère en gardant la correspondance des positions : pour
 * surligner dans le texte d'origine ce qui a été trouvé dans le texte plié.
 *
 * @param string $texte Texte (UTF-8).
 * @return array{0:string,1:string[],2:int[]} Texte plié, caractères d'origine, position (octet)
 *                                            dans le texte plié du début de chaque caractère.
 */
function plier_avec_positions( string $texte ): array {
	static $memo = array();
	$caracteres  = preg_split( '//u', $texte, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $caracteres ) ) {
		return array( plier( $texte ), array(), array() );
	}
	if ( count( $memo ) > 4000 ) {
		$memo = array();
	}
	$plie   = '';
	$debuts = array();
	foreach ( $caracteres as $c ) {
		$debuts[] = strlen( $plie );
		if ( ! isset( $memo[ $c ] ) ) {
			$memo[ $c ] = plier( $c );
		}
		$plie .= $memo[ $c ];
	}
	return array( $plie, $caracteres, $debuts );
}

/**
 * Terme de recherche nettoyé : UTF-8 valide, sans caractère de contrôle, 100 caractères au plus.
 * Le texte n'est pas débarrassé des balises (on cherche « <b> » littéralement) : il est échappé
 * à l'affichage.
 *
 * @param mixed $brut Valeur reçue.
 */
function nettoyer_terme( $brut ): string {
	if ( ! is_string( $brut ) ) {
		return '';
	}
	$terme = wp_check_invalid_utf8( $brut );
	$terme = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $terme );
	$terme = trim( (string) preg_replace( '/\s+/u', ' ', $terme ) );
	return trim( mb_substr( $terme, 0, MAX_TERME ) );
}

/**
 * Mots pliés d'un terme de recherche (6 au plus, sans doublon ni ponctuation de bord).
 *
 * @param string $terme Terme.
 * @return string[]
 */
function mots_recherche( string $terme ): array {
	$mots = array();
	foreach ( preg_split( '/\s+/u', plier( $terme ), -1, PREG_SPLIT_NO_EMPTY ) as $mot ) {
		$mot = trim( $mot, " \t\"'«»“”.,;:!?()[]{}" );
		if ( '' !== $mot && ! in_array( $mot, $mots, true ) ) {
			$mots[] = $mot;
		}
	}
	return array_slice( $mots, 0, MAX_MOTS );
}

/**
 * Tous les mots figurent-ils dans le texte plié ?
 *
 * @param string   $plie Texte plié.
 * @param string[] $mots Mots pliés.
 */
function contient_tous( string $plie, array $mots ): bool {
	if ( ! $mots ) {
		return false;
	}
	foreach ( $mots as $mot ) {
		if ( false === strpos( $plie, $mot ) ) {
			return false;
		}
	}
	return true;
}

/**
 * L'un des mots figure-t-il dans le texte plié ?
 *
 * @param string   $plie Texte plié.
 * @param string[] $mots Mots pliés.
 */
function contient_un( string $plie, array $mots ): bool {
	foreach ( $mots as $mot ) {
		if ( '' !== $plie && false !== strpos( $plie, $mot ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Plages (caractères d'origine, fin exclue) où apparaissent les mots, triées et fusionnées.
 *
 * @param string   $plie   Texte plié.
 * @param int[]    $debuts Positions de plier_avec_positions().
 * @param string[] $mots   Mots pliés.
 * @param int      $max    Nombre maximal d'occurrences par mot.
 * @return array<int,array{0:int,1:int}>
 */
function plages_occurrences( string $plie, array $debuts, array $mots, int $max = 50 ): array {
	$plages = array();
	$n      = count( $debuts );
	foreach ( $mots as $mot ) {
		$longueur = strlen( $mot );
		$depart   = 0;
		$trouves  = 0;
		while ( $longueur > 0 && $trouves < $max && false !== ( $pos = strpos( $plie, $mot, $depart ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$plages[] = array( caractere_a( $debuts, $pos ), caractere_apres( $debuts, $pos + $longueur, $n ) );
			$depart   = $pos + $longueur;
			++$trouves;
		}
	}
	usort( $plages, static fn( array $a, array $b ): int => 0 !== ( $a[0] <=> $b[0] ) ? $a[0] <=> $b[0] : $b[1] <=> $a[1] );
	$fusion = array();
	foreach ( $plages as $plage ) {
		$dernier = count( $fusion ) - 1;
		if ( $dernier >= 0 && $plage[0] <= $fusion[ $dernier ][1] ) {
			$fusion[ $dernier ][1] = max( $fusion[ $dernier ][1], $plage[1] );
		} else {
			$fusion[] = $plage;
		}
	}
	return $fusion;
}

/**
 * Caractère d'origine contenant la position (octet) donnée du texte plié.
 *
 * @param int[] $debuts Positions de début des caractères.
 * @param int   $pos    Position dans le texte plié.
 */
function caractere_a( array $debuts, int $pos ): int {
	$bas  = 0;
	$haut = count( $debuts ) - 1;
	while ( $bas < $haut ) {
		$milieu = intdiv( $bas + $haut + 1, 2 );
		if ( $debuts[ $milieu ] <= $pos ) {
			$bas = $milieu;
		} else {
			$haut = $milieu - 1;
		}
	}
	return max( 0, $bas );
}

/**
 * Premier caractère d'origine qui commence à la position (octet) donnée ou après.
 *
 * @param int[] $debuts Positions de début des caractères.
 * @param int   $pos    Position dans le texte plié.
 * @param int   $n      Nombre de caractères.
 */
function caractere_apres( array $debuts, int $pos, int $n ): int {
	$i = caractere_a( $debuts, $pos );
	return ( $i < $n && $debuts[ $i ] < $pos ) ? $i + 1 : $i;
}

/**
 * HTML échappé d'une portion de texte, occurrences entourées de <mark>.
 *
 * @param string[]                      $caracteres Caractères d'origine.
 * @param array<int,array{0:int,1:int}> $plages     Plages à surligner.
 * @param int                           $debut      Premier caractère.
 * @param int                           $fin        Fin (exclue).
 */
function html_surligne( array $caracteres, array $plages, int $debut, int $fin ): string {
	$html    = '';
	$curseur = $debut;
	foreach ( $plages as list( $a, $b ) ) {
		if ( $b <= $debut || $a >= $fin ) {
			continue;
		}
		$a = max( $a, $debut );
		$b = min( $b, $fin );
		if ( $a > $curseur ) {
			$html .= esc_html( implode( '', array_slice( $caracteres, $curseur, $a - $curseur ) ) );
		}
		$html   .= '<mark class="yn-search__marque">' . esc_html( implode( '', array_slice( $caracteres, $a, $b - $a ) ) ) . '</mark>';
		$curseur = $b;
	}
	if ( $curseur < $fin ) {
		$html .= esc_html( implode( '', array_slice( $caracteres, $curseur, $fin - $curseur ) ) );
	}
	return $html;
}

/**
 * Texte entier échappé, occurrences des mots surlignées (<mark>).
 *
 * @param string   $texte Texte brut.
 * @param string[] $mots  Mots pliés.
 */
function surligner( string $texte, array $mots ): string {
	list( $plie, $caracteres, $debuts ) = plier_avec_positions( $texte );
	if ( ! $caracteres ) {
		return esc_html( $texte );
	}
	return html_surligne( $caracteres, plages_occurrences( $plie, $debuts, $mots ), 0, count( $caracteres ) );
}

/**
 * Extrait d'environ 200 caractères autour de la première occurrence, surligné et échappé.
 *
 * @param string   $texte    Texte brut (sans balise).
 * @param string[] $mots     Mots pliés.
 * @param int      $longueur Longueur de l'extrait.
 * @return string HTML (vide si le texte est vide).
 */
function extrait_autour( string $texte, array $mots, int $longueur = LONGUEUR_EXTRAIT ): string {
	$texte = trim( (string) preg_replace( '/\s+/u', ' ', $texte ) );
	if ( '' === $texte ) {
		return '';
	}
	list( $plie, $caracteres, $debuts ) = plier_avec_positions( $texte );
	$n                                  = count( $caracteres );
	$plages                             = plages_occurrences( $plie, $debuts, $mots );
	$debut                              = 0;
	if ( $plages && $n > $longueur ) {
		$premier = $plages[0];
		$debut   = max( 0, min( $n - $longueur, $premier[0] - intdiv( max( 0, $longueur - ( $premier[1] - $premier[0] ) ), 2 ) ) );
		// Commencer au début d'un mot (20 caractères au plus d'écart).
		if ( $debut > 0 ) {
			$borne = min( $debut + 20, $premier[0] );
			for ( $i = $debut; $i < $borne; $i++ ) {
				if ( ' ' === $caracteres[ $i ] ) {
					$debut = $i + 1;
					break;
				}
			}
		}
	}
	$fin = min( $n, $debut + $longueur );
	// Finir à la fin d'un mot (20 caractères au plus en arrière).
	if ( $fin < $n ) {
		$limite = max( $debut + 1, $fin - 20 );
		if ( $plages ) {
			$limite = max( $limite, $plages[0][1] );
		}
		for ( $i = $fin - 1; $i >= $limite; $i-- ) {
			if ( ' ' === $caracteres[ $i ] ) {
				$fin = $i;
				break;
			}
		}
	}
	$html = html_surligne( $caracteres, $plages, $debut, $fin );
	return ( $debut > 0 ? '…' : '' ) . trim( $html ) . ( $fin < $n ? '…' : '' );
}

/**
 * Texte brut d'un contenu HTML ou de blocs (balises, commentaires et codes courts retirés).
 *
 * @param string $html Contenu.
 */
function texte_brut( string $html ): string {
	$html = strip_shortcodes( $html );
	$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html );
	$html = (string) preg_replace( '#</(p|div|h[1-6]|li|blockquote|figcaption|br)>|<br\s*/?>#i', '$0 ', $html );
	$html = wp_strip_all_tags( $html );
	return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

/*
 * -----------------------------------------------------------------------------
 * Préfiltre SQL
 * -----------------------------------------------------------------------------
 */

/**
 * La collation de la base compare-t-elle déjà sans tenir compte des accents ni de la casse
 * (MariaDB / MySQL en *_ci, pas SQLite) ? Sinon le motif LIKE remplace chaque lettre
 * accentuable par « _ ».
 */
function like_insensible_accents(): bool {
	global $wpdb;
	$sqlite = ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || ( class_exists( '\WP_SQLite_DB' ) && $wpdb instanceof \WP_SQLite_DB );
	$c      = strtolower( (string) ( $wpdb->collate ?? '' ) );
	$ok     = ! $sqlite && '' !== $c && str_ends_with( $c, '_ci' ) && ! str_contains( $c, '_as_' ) && ! str_contains( $c, 'bin' );
	/**
	 * La comparaison LIKE de la base ignore-t-elle déjà accents et casse ?
	 *
	 * @param bool $ok Détection d'après le moteur et la collation.
	 */
	return (bool) apply_filters( 'yume_recherche_like_insensible', $ok );
}

/**
 * Motif LIKE large (préfiltre) d'un mot plié : trouve le mot quelle que soit la graphie
 * (accents, casse, ligatures, apostrophe typographique) ; PHP vérifie ensuite.
 *
 * @param string $mot Mot plié.
 */
function motif_like( string $mot ): string {
	global $wpdb;
	$insensible = like_insensible_accents();
	$motif      = '';
	foreach ( preg_split( '/(oe|ae)/', $mot, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) as $morceau ) {
		if ( 'oe' === $morceau || 'ae' === $morceau ) {
			$motif .= '%'; // « œ » (un caractère) ou « oe » (deux).
			continue;
		}
		foreach ( preg_split( '//u', $morceau, -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
			if ( str_contains( '<>&"', $c ) ) {
				$motif .= '%'; // Souvent enregistré en entité (&lt; &amp;…).
				continue;
			}
			$variable = str_contains( "'-", $c ) || ( ! $insensible && str_contains( 'aceinouy', $c ) ) || strlen( $c ) > 1;
			$motif   .= $variable ? '_' : $wpdb->esc_like( $c );
		}
	}
	return '%' . $motif . '%';
}

/**
 * Clause SQL préparée : chaque mot figure dans l'une des colonnes.
 *
 * @param string[] $mots     Mots pliés.
 * @param string[] $colonnes Colonnes (noms sûrs).
 */
function clause_mots( array $mots, array $colonnes ): string {
	global $wpdb;
	$clauses = array();
	foreach ( $mots as $mot ) {
		$motif = motif_like( $mot );
		$ou    = array();
		foreach ( $colonnes as $colonne ) {
			$ou[] = $wpdb->prepare( $colonne . ' LIKE %s', $motif ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nom de colonne fixe.
		}
		$clauses[] = '( ' . implode( ' OR ', $ou ) . ' )';
	}
	return $clauses ? implode( ' AND ', $clauses ) : '1=0';
}

/*
 * -----------------------------------------------------------------------------
 * Index de recherche (œuvres et tomes publiés), mis en cache
 * -----------------------------------------------------------------------------
 */

/**
 * Index de recherche : œuvres publiées (titres alternatifs, auteur, illustrateur, éditeur VO)
 * et tomes publiés de ces œuvres.
 *
 * @return array{oeuvres:array<int,array>,tomes:array<int,array>}
 */
function index_recherche(): array {
	return en_cache( 'recherche', array(), __NAMESPACE__ . '\\calculer_index_recherche' );
}

/**
 * Calcule l'index de recherche (sans cache).
 *
 * @return array{oeuvres:array<int,array>,tomes:array<int,array>}
 */
function calculer_index_recherche(): array {
	global $wpdb;
	$index    = index_oeuvres();
	$resultat = array(
		'oeuvres' => array(),
		'tomes'   => array(),
	);
	$ids      = array_map( 'intval', array_keys( $index['oeuvres'] ) );
	if ( ! $ids ) {
		return $resultat;
	}
	update_meta_cache( 'post', $ids );
	$propre = static fn( $t ): string => is_scalar( $t ) ? trim( html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) : '';
	foreach ( $index['oeuvres'] as $id => $oeuvre ) {
		$id                         = (int) $id;
		$alt                        = array_values( array_filter( array_map( $propre, (array) get_post_meta( $id, 'yume_titres_alt', true ) ), 'strlen' ) );
		$resultat['oeuvres'][ $id ] = array(
			'id'           => $id,
			'titre'        => (string) $oeuvre['titre'],
			'alt'          => $alt,
			'auteur'       => $propre( get_post_meta( $id, 'yume_auteur', true ) ),
			'illustrateur' => $propre( get_post_meta( $id, 'yume_illustrateur', true ) ),
			'editeur'      => $propre( get_post_meta( $id, 'yume_editeur_vo', true ) ),
			'image'        => (int) get_post_meta( $id, '_thumbnail_id', true ),
			'slug'         => (string) get_post_field( 'post_name', $id ),
			'types'        => (array) $oeuvre['types'],
			'statuts'      => (array) $oeuvre['statuts'],
			'genres'       => (array) $oeuvre['genres'],
			'date'         => (int) $oeuvre['date'],
			'tri'          => (string) $oeuvre['tri'],
		);
	}

	// Tomes publiés dont l'œuvre est publiée (une requête).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$lignes = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_date_gmt, m.meta_value AS oeuvre FROM {$wpdb->posts} p"
			. " INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_oeuvre_id'"
			. " WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''",
			TYPE_TOME
		),
		ARRAY_A
	);
	foreach ( (array) $lignes as $ligne ) {
		$oeuvre = (int) $ligne['oeuvre'];
		$id     = (int) $ligne['ID'];
		if ( ! isset( $resultat['oeuvres'][ $oeuvre ] ) || isset( $resultat['tomes'][ $id ] ) ) {
			continue;
		}
		$titre                    = $propre( $ligne['post_title'] );
		$ts                       = strtotime( (string) $ligne['post_date_gmt'] . ' UTC' );
		$resultat['tomes'][ $id ] = array(
			'id'     => $id,
			'titre'  => $titre,
			'oeuvre' => $oeuvre,
			'date'   => false !== $ts ? (int) $ts : 0,
			'tri'    => cle_tri( $titre ),
		);
	}
	ksort( $resultat['tomes'] );
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Filtres et groupes
 * -----------------------------------------------------------------------------
 */

/**
 * Groupes de résultats (clé => libellé), dans l'ordre d'affichage. « Dans les chapitres »
 * seulement si le réglage est actif.
 *
 * @return array<string,string>
 */
function groupes_recherche(): array {
	$groupes = array(
		'oeuvres'    => __( 'Œuvres', 'yume-core' ),
		'tomes'      => __( 'Tomes', 'yume-core' ),
		'actualites' => __( 'Actualités', 'yume-core' ),
	);
	if ( recherche_chapitres_active() ) {
		$groupes['chapitres'] = __( 'Dans les chapitres', 'yume-core' );
	}
	return $groupes;
}

/**
 * Filtres de la page de résultats normalisés (paramètres GET contenu, statut, genre, tri et
 * pg_{groupe}) ; une valeur inconnue est ignorée.
 *
 * @param array $brut Paramètres reçus (chaînes non assainies).
 * @return array{contenu:string,statuts:string[],genre:string,tri:string,pages:array<string,int>}
 */
function normaliser_filtres_recherche( array $brut ): array {
	$base    = normaliser_filtres( array_intersect_key( $brut, array_flip( array( 'statut', 'genre' ) ) ) );
	$index   = index_oeuvres();
	$contenu = isset( $brut['contenu'] ) && is_string( $brut['contenu'] ) ? sanitize_key( $brut['contenu'] ) : '';
	$groupes = groupes_recherche();
	$tri     = isset( $brut['tri'] ) && is_string( $brut['tri'] ) ? sanitize_key( $brut['tri'] ) : '';
	$genre   = $base['genre'];
	if ( '' !== $genre && ! isset( $index['termes'][ TAX_GENRE ][ $genre ] ) && ! term_exists( $genre, TAX_GENRE ) ) {
		$genre = '';
	}
	$pages = array();
	foreach ( array_keys( $groupes ) as $cle ) {
		$valeur        = $brut[ 'pg_' . $cle ] ?? '';
		$pages[ $cle ] = is_string( $valeur ) || is_int( $valeur ) ? max( 1, absint( $valeur ) ) : 1;
	}
	return array(
		'contenu' => isset( $groupes[ $contenu ] ) ? $contenu : '',
		'statuts' => array_values( array_intersect( $base['statuts'], array_keys( statuts() + $index['termes'][ TAX_STATUT ] ) ) ),
		'genre'   => $genre,
		'tri'     => in_array( $tri, array( 'recent', 'az' ), true ) ? $tri : 'pertinence',
		'pages'   => $pages,
	);
}

/**
 * Œuvres de l'index de recherche retenues par les filtres statut et genre.
 *
 * @param array $filtres Filtres normalisés.
 * @return array<int,array>
 */
function oeuvres_filtrees( array $filtres ): array {
	return filtrer_oeuvres(
		index_recherche()['oeuvres'],
		array(
			'type'    => '',
			'statuts' => (array) ( $filtres['statuts'] ?? array() ),
			'genre'   => (string) ( $filtres['genre'] ?? '' ),
		)
	);
}

/**
 * Des filtres portant sur l'œuvre (statut, genre) sont-ils actifs ?
 *
 * @param array $filtres Filtres normalisés.
 */
function filtres_oeuvre_actifs( array $filtres ): bool {
	return ! empty( $filtres['statuts'] ) || '' !== (string) ( $filtres['genre'] ?? '' );
}

/*
 * -----------------------------------------------------------------------------
 * Recherche par groupe
 * -----------------------------------------------------------------------------
 */

/**
 * Note de pertinence d'un contenu : chaque mot doit figurer dans l'un des champs (sinon null) ;
 * il rapporte le poids du meilleur champ ; la phrase entière, le début et l'égalité du premier
 * champ (le titre) donnent un bonus.
 *
 * @param array<int,array{0:string,1:int}> $champs Champs pliés et poids (le premier = titre).
 * @param string[]                         $mots   Mots pliés.
 * @param string                           $phrase Phrase pliée.
 * @return int|null
 */
function note_pertinence( array $champs, array $mots, string $phrase ): ?int {
	$note = 0;
	foreach ( $mots as $mot ) {
		$meilleur = 0;
		foreach ( $champs as list( $texte, $poids ) ) {
			if ( $poids > $meilleur && '' !== $texte && false !== strpos( $texte, $mot ) ) {
				$meilleur = $poids;
			}
		}
		if ( 0 === $meilleur ) {
			return null;
		}
		$note += $meilleur;
	}
	$titre = (string) ( $champs[0][0] ?? '' );
	if ( '' !== $phrase && '' !== $titre ) {
		if ( $titre === $phrase ) {
			$note += 60;
		} elseif ( str_starts_with( $titre, $phrase ) ) {
			$note += 30;
		} elseif ( str_contains( $titre, $phrase ) ) {
			$note += 15;
		}
	}
	return $note;
}

/**
 * Œuvres correspondant à la recherche : titre, titres alternatifs, auteur, illustrateur,
 * éditeur VO (ou seulement titre et titres alternatifs pour les suggestions).
 *
 * @param string[] $mots        Mots pliés.
 * @param array    $filtres     Filtres normalisés.
 * @param bool     $titres_seuls Titre et titres alternatifs seulement.
 * @return array<int,array> Résultats (id, note, date, tri, champ, texte).
 */
function chercher_oeuvres( array $mots, array $filtres, bool $titres_seuls = false ): array {
	$phrase    = implode( ' ', $mots );
	$resultats = array();
	foreach ( oeuvres_filtrees( $filtres ) as $oeuvre ) {
		$champs = array( array( plier( $oeuvre['titre'] ), 10, 'titre', $oeuvre['titre'] ) );
		foreach ( $oeuvre['alt'] as $alt ) {
			$champs[] = array( plier( $alt ), 9, 'alt', $alt );
		}
		if ( ! $titres_seuls ) {
			$champs[] = array( plier( $oeuvre['auteur'] ), 5, 'auteur', $oeuvre['auteur'] );
			$champs[] = array( plier( $oeuvre['illustrateur'] ), 5, 'illustrateur', $oeuvre['illustrateur'] );
			$champs[] = array( plier( $oeuvre['editeur'] ), 4, 'editeur', $oeuvre['editeur'] );
		}
		$note = note_pertinence( $champs, $mots, $phrase );
		if ( null === $note ) {
			continue;
		}
		// Champ affiché en plus du titre : le premier autre champ qui contient un mot absent du titre.
		$champ = '';
		$texte = '';
		if ( ! contient_tous( $champs[0][0], $mots ) ) {
			foreach ( array_slice( $champs, 1 ) as $c ) {
				foreach ( $mots as $mot ) {
					if ( false === strpos( $champs[0][0], $mot ) && '' !== $c[0] && false !== strpos( $c[0], $mot ) ) {
						$champ = $c[2];
						$texte = $c[3];
						break 2;
					}
				}
			}
			// Un titre alternatif qui contient toute la phrase passe avant.
			foreach ( array_slice( $champs, 1 ) as $c ) {
				if ( 'alt' === $c[2] && contient_tous( $c[0], $mots ) ) {
					$note += 20;
					break;
				}
			}
		}
		$resultats[] = array(
			'id'    => (int) $oeuvre['id'],
			'note'  => $note,
			'date'  => (int) $oeuvre['date'],
			'tri'   => (string) $oeuvre['tri'],
			'champ' => $champ,
			'texte' => $texte,
		);
	}
	return $resultats;
}

/**
 * Tomes publiés (d'œuvres publiées retenues par les filtres) dont le titre correspond.
 *
 * @param string[] $mots    Mots pliés.
 * @param array    $filtres Filtres normalisés.
 * @return array<int,array>
 */
function chercher_tomes( array $mots, array $filtres ): array {
	$phrase    = implode( ' ', $mots );
	$oeuvres   = oeuvres_filtrees( $filtres );
	$resultats = array();
	foreach ( index_recherche()['tomes'] as $tome ) {
		if ( ! isset( $oeuvres[ $tome['oeuvre'] ] ) ) {
			continue;
		}
		$note = note_pertinence( array( array( plier( $tome['titre'] ), 10 ) ), $mots, $phrase );
		if ( null === $note ) {
			continue;
		}
		$resultats[] = array(
			'id'     => (int) $tome['id'],
			'note'   => $note,
			'date'   => (int) $tome['date'],
			'tri'    => (string) $tome['tri'],
			'oeuvre' => (int) $tome['oeuvre'],
		);
	}
	return $resultats;
}

/**
 * Actualités publiées (articles) : titre, extrait, texte. Préfiltre SQL (300 articles au plus,
 * les plus récents), vérification en PHP. Avec un filtre statut ou genre, seulement les
 * articles liés (yume_oeuvre_liee) à une œuvre retenue.
 *
 * @param string[] $mots    Mots pliés.
 * @param array    $filtres Filtres normalisés.
 * @return array<int,array>
 */
function chercher_actualites( array $mots, array $filtres ): array {
	global $wpdb;
	if ( ! $mots ) {
		return array();
	}
	/**
	 * Nombre maximal d'articles lus par une recherche (préfiltre SQL).
	 *
	 * @param int $max Défaut : 300.
	 */
	$max = max( 1, (int) apply_filters( 'yume_recherche_actualites_max', 300 ) );
	$sql = "SELECT ID, post_title, post_excerpt, post_content, post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_password = ''"
		. ' AND ' . clause_mots( $mots, array( 'post_title', 'post_excerpt', 'post_content' ) )
		. ' ORDER BY post_date_gmt DESC, ID DESC LIMIT ' . $max;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- clauses préparées par clause_mots().
	$lignes = (array) $wpdb->get_results( $sql, ARRAY_A );

	$autorises = null;
	if ( filtres_oeuvre_actifs( $filtres ) ) {
		$slugs     = array_values( array_filter( wp_list_pluck( oeuvres_filtrees( $filtres ), 'slug' ), 'strlen' ) );
		$termes    = $slugs ? get_terms(
			array(
				'taxonomy'   => 'yume_oeuvre_liee',
				'slug'       => $slugs,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		) : array();
		$autorises = ( is_array( $termes ) && $termes ) ? array_flip( array_map( 'intval', (array) get_objects_in_term( array_map( 'intval', $termes ), 'yume_oeuvre_liee' ) ) ) : array();
	}

	$phrase    = implode( ' ', $mots );
	$resultats = array();
	foreach ( $lignes as $ligne ) {
		$id = (int) $ligne['ID'];
		if ( null !== $autorises && ! isset( $autorises[ $id ] ) ) {
			continue;
		}
		$titre   = trim( html_entity_decode( wp_strip_all_tags( (string) $ligne['post_title'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$extrait = texte_brut( (string) $ligne['post_excerpt'] );
		$texte   = texte_brut( (string) $ligne['post_content'] );
		$note    = note_pertinence(
			array(
				array( plier( $titre ), 10 ),
				array( plier( $extrait ), 4 ),
				array( plier( $texte ), 2 ),
			),
			$mots,
			$phrase
		);
		if ( null === $note ) {
			continue;
		}
		$ts          = strtotime( (string) $ligne['post_date_gmt'] . ' UTC' );
		$resultats[] = array(
			'id'    => $id,
			'note'  => $note,
			'date'  => false !== $ts ? (int) $ts : 0,
			'tri'   => cle_tri( $titre ),
			'titre' => $titre,
			// Texte de l'extrait affiché : le résumé s'il contient un mot cherché, sinon l'article.
			'texte' => ( '' === $texte || contient_un( plier( $extrait ), $mots ) ) ? $extrait : $texte,
		);
	}
	return $resultats;
}

/**
 * Chapitres publiés dont le texte (ou le titre) contient la recherche. Seulement si le réglage
 * est actif et le terme d'au moins 3 caractères ; jamais les chapitres d'un tome ou d'une œuvre
 * non publiés, ni ceux d'une œuvre licenciée ; 60 chapitres lus au plus (les plus récents).
 *
 * @param string[] $mots    Mots pliés.
 * @param array    $filtres Filtres normalisés.
 * @return array{resultats:array<int,array>,tronque:bool}
 */
function chercher_chapitres( array $mots, array $filtres ): array {
	global $wpdb;
	$vide = array(
		'resultats' => array(),
		'tronque'   => false,
	);
	if ( ! recherche_chapitres_active() || mb_strlen( implode( ' ', $mots ) ) < MIN_CHAPITRES ) {
		return $vide;
	}
	/**
	 * Statuts d'œuvre dont les chapitres ne sont jamais cherchés (texte retiré du site).
	 *
	 * @param string[] $statuts Défaut : licenciee.
	 */
	$exclus  = (array) apply_filters( 'yume_recherche_statuts_exclus_chapitres', array( 'licenciee' ) );
	$oeuvres = array_filter( oeuvres_filtrees( $filtres ), static fn( array $o ): bool => ! array_intersect( $exclus, $o['statuts'] ) );
	$tomes   = array();
	foreach ( index_recherche()['tomes'] as $tome ) {
		if ( isset( $oeuvres[ $tome['oeuvre'] ] ) ) {
			$tomes[ (int) $tome['id'] ] = (int) $tome['oeuvre'];
		}
	}
	if ( ! $tomes ) {
		return $vide;
	}
	/**
	 * Nombre maximal de chapitres lus par une recherche (préfiltre SQL, les plus récents).
	 *
	 * @param int $max Défaut : 60.
	 */
	$max = max( 1, (int) apply_filters( 'yume_recherche_chapitres_max', 60 ) );
	$ids = implode( ', ', array_map( static fn( int $id ): string => "'" . $id . "'", array_keys( $tomes ) ) );
	$sql = $wpdb->prepare(
		"SELECT p.ID, p.post_title, p.post_content, p.post_date_gmt, t.meta_value AS tome FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = 'yume_tome_id'"
		. " WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_password = ''",
		TYPE_CHAPITRE
	)
		. ' AND t.meta_value IN (' . $ids . ') AND ' . clause_mots( $mots, array( 'p.post_title', 'p.post_content' ) )
		. ' ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT ' . $max;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- identifiants entiers et clauses préparées.
	$lignes = (array) $wpdb->get_results( $sql, ARRAY_A );

	$phrase    = implode( ' ', $mots );
	$resultats = array();
	$vus       = array();
	foreach ( $lignes as $ligne ) {
		$id = (int) $ligne['ID'];
		if ( isset( $vus[ $id ] ) ) {
			continue;
		}
		$vus[ $id ] = true;
		$titre      = trim( html_entity_decode( wp_strip_all_tags( (string) $ligne['post_title'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$texte      = texte_brut( (string) $ligne['post_content'] );
		$plie       = plier( $texte );
		$note       = note_pertinence(
			array(
				array( plier( $titre ), 10 ),
				array( $plie, 3 ),
			),
			$mots,
			$phrase
		);
		if ( null === $note ) {
			continue;
		}
		foreach ( $mots as $mot ) {
			$note += min( 10, substr_count( $plie, $mot ) );
		}
		$ts          = strtotime( (string) $ligne['post_date_gmt'] . ' UTC' );
		$resultats[] = array(
			'id'      => $id,
			'note'    => $note,
			'date'    => false !== $ts ? (int) $ts : 0,
			'tri'     => cle_tri( $titre ),
			'tome'    => (int) $ligne['tome'],
			'oeuvre'  => (int) ( $tomes[ (int) $ligne['tome'] ] ?? 0 ),
			'contenu' => (string) $ligne['post_content'],
		);
	}
	return array(
		'resultats' => $resultats,
		'tronque'   => count( $lignes ) >= $max,
	);
}

/**
 * Trie des résultats : pertinence (puis date), plus récents d'abord, ou A → Z.
 *
 * @param array<int,array> $resultats Résultats.
 * @param string           $tri       pertinence, recent ou az.
 * @return array<int,array>
 */
function trier_resultats( array $resultats, string $tri ): array {
	usort(
		$resultats,
		static function ( array $a, array $b ) use ( $tri ): int {
			if ( 'az' === $tri ) {
				$cmp = strnatcasecmp( $a['tri'], $b['tri'] );
				return 0 !== $cmp ? $cmp : ( $a['id'] <=> $b['id'] );
			}
			if ( 'pertinence' === $tri && $a['note'] !== $b['note'] ) {
				return $b['note'] <=> $a['note'];
			}
			if ( $a['date'] !== $b['date'] ) {
				return $b['date'] <=> $a['date'];
			}
			return $b['id'] <=> $a['id'];
		}
	);
	return $resultats;
}

/**
 * Résultats groupés d'une recherche (tous les groupes, pour les compteurs).
 *
 * @param string $terme   Terme brut.
 * @param array  $filtres Filtres normalisés (normaliser_filtres_recherche()).
 * @return array{terme:string,mots:string[],total:int,groupes:array<string,array{libelle:string,total:int,resultats:array,tronque:bool}>}
 */
function resultats_recherche( string $terme, array $filtres ): array {
	static $memo = array();
	$terme       = nettoyer_terme( $terme );
	$mots        = mots_recherche( $terme );
	$cle         = md5( wp_json_encode( array( $mots, $filtres['statuts'] ?? array(), $filtres['genre'] ?? '', $filtres['tri'] ?? '', recherche_chapitres_active(), version_cache() ) ) );
	if ( isset( $memo[ $cle ] ) ) {
		return $memo[ $cle ];
	}
	if ( count( $memo ) > 10 ) {
		$memo = array();
	}
	$tri     = (string) ( $filtres['tri'] ?? 'pertinence' );
	$groupes = array();
	$total   = 0;
	foreach ( groupes_recherche() as $groupe => $libelle ) {
		$tronque = false;
		if ( ! $mots ) {
			$liste = array();
		} elseif ( 'oeuvres' === $groupe ) {
			$liste = chercher_oeuvres( $mots, $filtres );
		} elseif ( 'tomes' === $groupe ) {
			$liste = chercher_tomes( $mots, $filtres );
		} elseif ( 'actualites' === $groupe ) {
			$liste = chercher_actualites( $mots, $filtres );
		} else {
			$chapitres = chercher_chapitres( $mots, $filtres );
			$liste     = $chapitres['resultats'];
			$tronque   = $chapitres['tronque'];
		}
		$groupes[ $groupe ] = array(
			'libelle'   => $libelle,
			'total'     => count( $liste ),
			'resultats' => trier_resultats( $liste, $tri ),
			'tronque'   => $tronque,
		);
		$total             += count( $liste );
	}
	$memo[ $cle ] = array(
		'terme'   => $terme,
		'mots'    => $mots,
		'total'   => $total,
		'groupes' => $groupes,
	);
	return $memo[ $cle ];
}

/**
 * Œuvres au titre proche (fautes de frappe) pour la page « Aucun résultat » : distance
 * d'édition entre chaque mot cherché et les mots des titres (et titres alternatifs).
 *
 * @param string[] $mots   Mots pliés.
 * @param int      $nombre Nombre maximal.
 * @return int[] IDs d'œuvres publiées.
 */
function oeuvres_proches( array $mots, int $nombre = 4 ): array {
	$mots = array_values( array_filter( $mots, static fn( string $m ): bool => strlen( $m ) >= 3 ) );
	if ( ! $mots ) {
		return array();
	}
	$notes = array();
	foreach ( index_recherche()['oeuvres'] as $oeuvre ) {
		$meilleure = 0.0;
		foreach ( array_merge( array( $oeuvre['titre'] ), $oeuvre['alt'] ) as $titre ) {
			$mots_titre = preg_split( '/[^a-z0-9]+/', plier( $titre ), -1, PREG_SPLIT_NO_EMPTY );
			if ( ! $mots_titre ) {
				continue;
			}
			$somme = 0.0;
			foreach ( $mots as $mot ) {
				$proche = 0.0;
				foreach ( $mots_titre as $mt ) {
					$long   = max( strlen( $mot ), strlen( $mt ) );
					$proche = max( $proche, 1 - levenshtein( substr( $mot, 0, 255 ), substr( $mt, 0, 255 ) ) / max( 1, $long ) );
				}
				$somme += $proche;
			}
			$meilleure = max( $meilleure, $somme / count( $mots ) );
		}
		if ( $meilleure >= 0.6 ) {
			$notes[ (int) $oeuvre['id'] ] = $meilleure;
		}
	}
	arsort( $notes );
	return array_slice( array_keys( $notes ), 0, max( 0, $nombre ) );
}

/*
 * -----------------------------------------------------------------------------
 * Suggestions instantanées (GET /yume/v1/suggestions)
 * -----------------------------------------------------------------------------
 */

/**
 * Suggestions pour un début de recherche : œuvres (titre et titres alternatifs) puis tomes,
 * 8 au plus, publiés seulement. Mises en cache 5 minutes (transient ; le cache de la
 * bibliothèque est renouvelé à chaque changement de contenu).
 *
 * @param string $terme Terme saisi.
 * @return array<int,array{type:string,id:int,titre:string,detail:string,url:string,image:string}>
 */
function suggestions( string $terme ): array {
	$terme = nettoyer_terme( $terme );
	$mots  = mots_recherche( $terme );
	if ( mb_strlen( $terme ) < MIN_SUGGESTIONS || ! $mots ) {
		return array();
	}
	$cle   = cle_cache( 'suggestions', array( $mots ) );
	$cache = get_transient( $cle );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$filtres = array(
		'statuts' => array(),
		'genre'   => '',
	);
	$index   = index_recherche();
	$liste   = array();
	$types   = types() + index_oeuvres()['termes'][ TAX_TYPE ];
	foreach ( array_slice( trier_resultats( chercher_oeuvres( $mots, $filtres, true ), 'pertinence' ), 0, MAX_SUGGESTIONS ) as $r ) {
		$oeuvre = $index['oeuvres'][ $r['id'] ] ?? null;
		if ( ! $oeuvre ) {
			continue;
		}
		$detail  = 'alt' === $r['champ'] ? $r['texte'] : (string) ( $oeuvre['types'] ? ( $types[ $oeuvre['types'][0] ] ?? '' ) : '' );
		$liste[] = array(
			'type'   => 'oeuvre',
			'id'     => (int) $r['id'],
			'titre'  => (string) $oeuvre['titre'],
			'detail' => $detail,
			'url'    => (string) get_permalink( (int) $r['id'] ),
			'image'  => url_vignette( (int) $oeuvre['image'] ),
		);
	}
	if ( count( $liste ) < MAX_SUGGESTIONS ) {
		$tomes = trier_resultats( chercher_tomes( $mots, $filtres ), 'pertinence' );
		foreach ( array_slice( $tomes, 0, MAX_SUGGESTIONS - count( $liste ) ) as $r ) {
			$tome    = $index['tomes'][ $r['id'] ];
			$image   = (int) get_post_thumbnail_id( (int) $r['id'] );
			$liste[] = array(
				'type'   => 'tome',
				'id'     => (int) $r['id'],
				'titre'  => (string) $tome['titre'],
				'detail' => __( 'Tome', 'yume-core' ),
				'url'    => (string) get_permalink( (int) $r['id'] ),
				'image'  => url_vignette( $image ? $image : (int) ( $index['oeuvres'][ $tome['oeuvre'] ]['image'] ?? 0 ) ),
			);
		}
	}
	set_transient( $cle, $liste, 5 * MINUTE_IN_SECONDS );
	return $liste;
}

/**
 * Adresse d'une miniature de couverture (vide sans image).
 *
 * @param int $image_id Pièce jointe.
 */
function url_vignette( int $image_id ): string {
	if ( $image_id <= 0 || ! wp_attachment_is_image( $image_id ) ) {
		return '';
	}
	$src = wp_get_attachment_image_src( $image_id, 'thumbnail' );
	return is_array( $src ) && ! empty( $src[0] ) ? (string) $src[0] : '';
}
