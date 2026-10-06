<?php
/**
 * Métadonnées des œuvres, tomes et chapitres (§4 du contrat) : déclaration, schémas REST,
 * assainissement et droits.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Assainissement
 * -----------------------------------------------------------------------------
 */

/**
 * Texte court sur une ligne.
 *
 * @param mixed $v Valeur.
 */
function san_texte( $v ): string {
	return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
}

/**
 * Texte long (sauts de ligne conservés).
 *
 * @param mixed $v Valeur.
 */
function san_texte_long( $v ): string {
	return is_scalar( $v ) ? sanitize_textarea_field( (string) $v ) : '';
}

/**
 * URL http(s) ou chaîne vide.
 *
 * @param mixed $v Valeur.
 */
function san_url( $v ): string {
	if ( ! is_scalar( $v ) ) {
		return '';
	}
	$v = trim( (string) $v );
	return '' === $v ? '' : esc_url_raw( $v, array( 'http', 'https' ) );
}

/**
 * Entier positif ou nul (ID, compteur).
 *
 * @param mixed $v Valeur.
 */
function san_entier( $v ): int {
	return is_scalar( $v ) && is_numeric( $v ) ? max( 0, (int) $v ) : 0;
}

/**
 * Nombre (numéro de tome ou de chapitre, note) ; chaîne vide si absent ou invalide.
 *
 * @param mixed $v Valeur.
 * @return float|string
 */
function san_nombre( $v ) {
	$n = numero_ou_null( $v );
	return null === $n ? '' : $n;
}

/**
 * Booléen.
 *
 * @param mixed $v Valeur.
 */
function san_booleen( $v ): bool {
	return (bool) rest_sanitize_boolean( is_scalar( $v ) ? $v : false );
}

/** Longueur maximale du nom public d'une série à venir (yume_libelle_a_venir). */
const LIBELLE_A_VENIR_MAX = 80;

/**
 * Nom public d'une série à venir : texte court sur une ligne, LIBELLE_A_VENIR_MAX caractères
 * au plus.
 *
 * @param mixed $v Valeur.
 */
function san_libelle_a_venir( $v ): string {
	return trim( mb_substr( san_texte( $v ), 0, LIBELLE_A_VENIR_MAX ) );
}

/**
 * Date « Y-m-d » valide ou chaîne vide.
 *
 * @param mixed $v Valeur.
 */
function san_date( $v ): string {
	if ( ! is_scalar( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', trim( (string) $v ), $m ) ) {
		return '';
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $m[0] : '';
}

/**
 * Date-heure « Y-m-d H:i:s » valide ou chaîne vide.
 *
 * @param mixed $v Valeur.
 */
function san_datetime( $v ): string {
	if ( ! is_scalar( $v ) ) {
		return '';
	}
	$v = trim( str_replace( 'T', ' ', (string) $v ) );
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', substr( $v, 0, 19 ), $m ) ) {
		return '';
	}
	if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
		return '';
	}
	return sprintf( '%s-%s-%s %s:%s:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], isset( $m[6] ) ? (int) $m[6] : 0 );
}

/**
 * Heure « HH:MM » valide (« 9:05 » et « 20:30:00 » ramenées à « 09:05 » et « 20:30 ») ou
 * chaîne vide.
 *
 * @param mixed $v Valeur.
 */
function san_heure( $v ): string {
	if ( ! is_scalar( $v ) || ! preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim( (string) $v ), $m ) ) {
		return '';
	}
	if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) {
		return '';
	}
	return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
}

/**
 * Valeur d'une énumération (ou valeur par défaut).
 *
 * @param mixed    $v       Valeur.
 * @param string[] $valeurs Valeurs permises.
 * @param string   $defaut  Défaut.
 */
function san_enum( $v, array $valeurs, string $defaut = '' ): string {
	$v = is_scalar( $v ) ? sanitize_key( (string) $v ) : '';
	return in_array( $v, $valeurs, true ) ? $v : $defaut;
}

/**
 * Rythme de sortie d'un tome : { jour : lundi…dimanche, heure : HH:MM } ; vide (chaîne) si
 * le jour manque ou est inconnu (sortie libre). Heure par défaut : 18:00.
 *
 * @param mixed $v Valeur.
 * @return array{jour:string,heure:string}|string
 */
function san_rythme( $v ) {
	if ( ! is_array( $v ) ) {
		return '';
	}
	$jour = san_enum( $v['jour'] ?? '', array_keys( yume_jours_semaine() ) );
	if ( '' === $jour ) {
		return '';
	}
	$heure = is_string( $v['heure'] ?? null ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $v['heure'] ) ? $v['heure'] : '18:00';
	return array(
		'jour'  => $jour,
		'heure' => $heure,
	);
}

/**
 * Liste de textes (tableau ou texte d'une valeur par ligne).
 *
 * @param mixed $v Valeur.
 * @return string[]
 */
function san_liste_textes( $v ): array {
	if ( is_string( $v ) ) {
		$v = preg_split( '/\R/u', $v );
	}
	$liste = array();
	foreach ( (array) $v as $item ) {
		$item = san_texte( $item );
		if ( '' !== $item && ! in_array( $item, $liste, true ) ) {
			$liste[] = $item;
		}
	}
	return array_slice( $liste, 0, 30 );
}

/**
 * Jours de la semaine (slugs français), dans l'ordre de la semaine.
 *
 * @param mixed $v Valeur.
 * @return string[]
 */
function san_jours( $v ): array {
	if ( is_string( $v ) ) {
		$v = explode( ',', $v );
	}
	$choisis = array_map( 'sanitize_key', array_filter( (array) $v, 'is_scalar' ) );
	return array_values( array_intersect( array_keys( yume_jours_semaine() ), $choisis ) );
}

/**
 * Liens {label, url} ; les entrées sans URL valide sont écartées.
 *
 * @param mixed $v Valeur.
 * @return array<int,array{label:string,url:string}>
 */
function san_liens( $v ): array {
	$liens = array();
	foreach ( (array) $v as $lien ) {
		if ( ! is_array( $lien ) ) {
			continue;
		}
		$url = san_url( $lien['url'] ?? '' );
		if ( '' === $url ) {
			continue;
		}
		$label   = san_texte( $lien['label'] ?? '' );
		$liens[] = array(
			'label' => '' !== $label ? $label : (string) wp_parse_url( $url, PHP_URL_HOST ),
			'url'   => $url,
		);
	}
	return array_slice( $liens, 0, 30 );
}

/**
 * Objet {traduction, relecture, edition} de textes (équipe, crédits).
 *
 * @param mixed $v Valeur.
 * @return array{traduction:string,relecture:string,edition:string}
 */
function san_trio_textes( $v ): array {
	$v = (array) $v;
	return array(
		'traduction' => san_texte( $v['traduction'] ?? '' ),
		'relecture'  => san_texte( $v['relecture'] ?? '' ),
		'edition'    => san_texte( $v['edition'] ?? '' ),
	);
}

/**
 * Avancement {traduction, relecture, edition} en pourcentages entiers 0–100.
 *
 * @param mixed $v Valeur.
 * @return array{traduction:int,relecture:int,edition:int}
 */
function san_avancement( $v ): array {
	$v   = (array) $v;
	$out = array();
	foreach ( array( 'traduction', 'relecture', 'edition' ) as $etape ) {
		$out[ $etape ] = min( 100, san_entier( $v[ $etape ] ?? 0 ) );
	}
	return $out;
}

/**
 * Responsables {traduction, relecture, edition} (IDs utilisateurs, 0 = personne).
 *
 * @param mixed $v Valeur.
 * @return array{traduction:int,relecture:int,edition:int}
 */
function san_responsables( $v ): array {
	$v   = (array) $v;
	$out = array();
	foreach ( array( 'traduction', 'relecture', 'edition' ) as $etape ) {
		$out[ $etape ] = san_entier( $v[ $etape ] ?? 0 );
	}
	return $out;
}

/**
 * Liste d'IDs (pièces jointes), sans doublon.
 *
 * @param mixed $v Valeur (tableau ou « 12,15,18 »).
 * @return int[]
 */
function san_ids( $v ): array {
	if ( is_string( $v ) ) {
		$v = preg_split( '/[\s,;]+/', $v );
	}
	$ids = array();
	foreach ( (array) $v as $id ) {
		$id = san_entier( $id );
		if ( $id && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}
	return array_slice( $ids, 0, 200 );
}

/**
 * Traçabilité d'import {format, hash, importe_le}.
 *
 * @param mixed $v Valeur.
 * @return array{format:string,hash:string,importe_le:string}
 */
function san_source( $v ): array {
	$v = (array) $v;
	return array(
		'format'     => sanitize_key( is_scalar( $v['format'] ?? '' ) ? (string) ( $v['format'] ?? '' ) : '' ),
		'hash'       => preg_replace( '/[^a-f0-9]/', '', strtolower( is_scalar( $v['hash'] ?? '' ) ? (string) ( $v['hash'] ?? '' ) : '' ) ),
		'importe_le' => san_datetime( $v['importe_le'] ?? '' ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Déclarations
 * -----------------------------------------------------------------------------
 */

/**
 * Schéma REST d'un objet {traduction, relecture, edition}.
 *
 * @param string $type Type des valeurs (string ou integer).
 * @return array<string,mixed>
 */
function schema_trio( string $type ): array {
	$prop = array( 'type' => $type );
	if ( 'integer' === $type ) {
		$prop['minimum'] = 0;
	}
	return array(
		'type'                 => 'object',
		'properties'           => array(
			'traduction' => $prop,
			'relecture'  => $prop,
			'edition'    => $prop,
		),
		'additionalProperties' => false,
	);
}

/**
 * Définitions des métadonnées par type de contenu.
 *
 * Chaque entrée : type, description, sanitize, default (facultatif), schema (facultatif,
 * obligatoire pour les objets et tableaux), lecture_seule (écriture REST refusée),
 * equipe (lecture et écriture réservées à yume_maj_planning).
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function definitions_meta(): array {
	$jours     = array_keys( yume_jours_semaine() );
	$chaine    = array( 'type' => 'string' );
	$trio_txt  = schema_trio( 'string' );
	$trio_vide = array(
		'traduction' => '',
		'relecture'  => '',
		'edition'    => '',
	);
	$trio_zero = array(
		'traduction' => 0,
		'relecture'  => 0,
		'edition'    => 0,
	);

	return array(
		CPT_OEUVRE   => array(
			'yume_titres_alt'        => array(
				'type'        => 'array',
				'description' => __( 'Titres alternatifs (français, anglais, romaji, japonais).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_liste_textes',
				'default'     => array(),
				'schema'      => array(
					'type'  => 'array',
					'items' => $chaine,
				),
			),
			'yume_auteur'            => array(
				'type'        => 'string',
				'description' => __( 'Auteur (scénario).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte',
			),
			'yume_illustrateur'      => array(
				'type'        => 'string',
				'description' => __( 'Illustrateur.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte',
			),
			'yume_editeur_vo'        => array(
				'type'        => 'string',
				'description' => __( 'Éditeur de la version originale.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte',
			),
			'yume_nb_tomes_vo'       => array(
				'type'        => 'integer',
				'description' => __( 'Nombre de tomes parus en version originale.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_statut_vo'         => array(
				'type'        => 'string',
				'description' => __( 'Statut de la version originale.', 'yume-core' ),
				'sanitize'    => static function ( $v ) {
					return san_enum( $v, array( 'en_cours', 'termine' ) );
				},
				'default'     => '',
				'schema'      => array(
					'type' => 'string',
					'enum' => array( '', 'en_cours', 'termine' ),
				),
			),
			'yume_jours_sortie'      => array(
				'type'        => 'array',
				'description' => __( 'Jours de sortie habituels.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_jours',
				'default'     => array(),
				'schema'      => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => $jours,
					),
					'uniqueItems' => true,
				),
			),
			'yume_liens'             => array(
				'type'        => 'array',
				'description' => __( 'Liens externes (Novel-Index, MangaDex, éditeur…).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_liens',
				'default'     => array(),
				'schema'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'label' => $chaine,
							'url'   => $chaine,
						),
						'additionalProperties' => false,
					),
				),
			),
			'yume_source_traduction' => array(
				'type'        => 'string',
				'description' => __( 'Source de la traduction (ex. « Édition anglaise officielle (J-Novel Club) »).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte',
			),
			'yume_banniere_id'       => array(
				'type'        => 'integer',
				'description' => __( 'Bannière de l’œuvre (pièce jointe).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_equipe'            => array(
				'type'        => 'object',
				'description' => __( 'Équipe de traduction (texte libre par étape).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_trio_textes',
				'default'     => $trio_vide,
				'schema'      => $trio_txt,
			),
			'yume_note_moyenne'      => array(
				'type'          => 'number',
				'description'   => __( 'Note moyenne des lecteurs (cache).', 'yume-core' ),
				'sanitize'      => static function ( $v ) {
					$n = numero_ou_null( $v );
					return null === $n ? 0 : max( 0, min( 5, $n ) );
				},
				'default'       => 0,
				'lecture_seule' => true,
			),
			'yume_nb_notes'          => array(
				'type'          => 'integer',
				'description'   => __( 'Nombre de notes (cache).', 'yume-core' ),
				'sanitize'      => __NAMESPACE__ . '\\san_entier',
				'default'       => 0,
				'lecture_seule' => true,
			),
			'yume_nb_favoris'        => array(
				'type'          => 'integer',
				'description'   => __( 'Nombre de favoris (cache).', 'yume-core' ),
				'sanitize'      => __NAMESPACE__ . '\\san_entier',
				'default'       => 0,
				'lecture_seule' => true,
			),
			'yume_derniere_sortie'   => array(
				'type'          => 'string',
				'description'   => __( 'Date GMT (Y-m-d H:i:s) du dernier tome ou chapitre publié (cache).', 'yume-core' ),
				'sanitize'      => __NAMESPACE__ . '\\san_datetime',
				'lecture_seule' => true,
			),
			'yume_serie_a_venir'     => array(
				'type'        => 'boolean',
				'description' => __( 'Série à venir : tant que l’œuvre n’est pas publiée, ses tomes paraissent au planning public sous le nom yume_libelle_a_venir, sans titre, lien ni couverture (lire avec yume_oeuvre_a_venir()). Retirée à la première publication de l’œuvre ou d’un de ses tomes ou chapitres.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_booleen',
				'default'     => false,
			),
			'yume_libelle_a_venir'   => array(
				'type'        => 'string',
				'description' => __( 'Nom affiché au public d’une série à venir (80 caractères au plus ; vide : « Nouvelle série à venir »). Lire avec yume_titre_public_oeuvre().', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_libelle_a_venir',
			),
		),
		CPT_TOME     => array(
			'yume_oeuvre_id'        => array(
				'type'        => 'integer',
				'description' => __( 'Œuvre du tome (obligatoire).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_numero'           => array(
				'type'        => 'number',
				'description' => __( 'Numéro du tome (ex. 9, 26.5).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_nombre',
			),
			'yume_nature'           => array(
				'type'        => 'string',
				'description' => __( 'Nature : tome, arc, ex, bonus, chapitres.', 'yume-core' ),
				'sanitize'    => static function ( $v ) {
					return san_enum( $v, array_keys( yume_natures_tome() ), 'tome' );
				},
				'default'     => 'tome',
				'schema'      => array(
					'type' => 'string',
					'enum' => array_keys( yume_natures_tome() ),
				),
			),
			'yume_parution'         => array(
				'type'        => 'string',
				'description' => __( 'Parution du tome : vide (déduite, tomes antérieurs), « planifie » (« Planifié » choisi par l’équipe : rien de lisible), « en_cours » (chapitres publiés au fil de l’eau) ou « complet » (« Publié » : tous les chapitres en ligne, marqué par l’équipe). Lire avec yume_parution_tome().', 'yume-core' ),
				'sanitize'    => static function ( $v ) {
					return san_enum( $v, array( '', 'planifie', 'en_cours', 'complet' ), '' );
				},
				'default'     => '',
				'schema'      => array(
					'type' => 'string',
					'enum' => array( '', 'planifie', 'en_cours', 'complet' ),
				),
			),
			'yume_chapitres_prevus' => array(
				'type'        => 'integer',
				'description' => __( 'Nombre de chapitres prévus pour le tome (0 : inconnu), pour « 3 sur 12 ».', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_rythme'           => array(
				'type'        => 'object',
				'description' => __( 'Rythme de sortie des chapitres : jour (lundi…dimanche) et heure (HH:MM, heure du site : l’heure de sortie du tome, yume_heure_cible, 18:00 à défaut) ; vide : libre.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_rythme',
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'jour'  => array( 'type' => 'string' ),
						'heure' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			),
			'yume_lien_pdf'         => array(
				'type'        => 'string',
				'description' => __( 'Lien externe de téléchargement PDF.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_url',
			),
			'yume_lien_epub'        => array(
				'type'        => 'string',
				'description' => __( 'Lien externe de téléchargement EPUB.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_url',
			),
			'yume_equivalence'      => array(
				'type'        => 'string',
				'description' => __( 'Équivalence (ex. « cet arc équivaut au tome 3 du LN »).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte',
			),
			'yume_illustrations'    => array(
				'type'        => 'array',
				'description' => __( 'Galerie d’illustrations (pièces jointes).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_ids',
				'default'     => array(),
				'schema'      => array(
					'type'  => 'array',
					'items' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			'yume_credits'          => array(
				'type'        => 'object',
				'description' => __( 'Crédits du tome.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_trio_textes',
				'default'     => $trio_vide,
				'schema'      => $trio_txt,
			),
			'yume_etape'            => array(
				'type'        => 'string',
				'description' => __( 'Étape du planning.', 'yume-core' ),
				'sanitize'    => static function ( $v ) {
					return san_enum( $v, array_keys( yume_etapes() ), 'a_faire' );
				},
				'default'     => 'a_faire',
				'schema'      => array(
					'type' => 'string',
					'enum' => array_keys( yume_etapes() ),
				),
			),
			'yume_avancement'       => array(
				'type'        => 'object',
				'description' => __( 'Avancement de chaque étape (0 à 100).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_avancement',
				'default'     => $trio_zero,
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'traduction' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
						'relecture'  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
						'edition'    => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 100,
						),
					),
					'additionalProperties' => false,
				),
			),
			'yume_responsables'     => array(
				'type'        => 'object',
				'description' => __( 'Responsable de chaque étape (ID utilisateur, 0 = personne).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_responsables',
				'default'     => $trio_zero,
				'schema'      => schema_trio( 'integer' ),
				'prive'       => true,
			),
			'yume_date_cible'       => array(
				'type'        => 'string',
				'description' => __( 'Date de sortie visée (Y-m-d).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_date',
			),
			'yume_heure_cible'      => array(
				'type'        => 'string',
				'description' => __( 'Heure de sortie visée (HH:MM, heure du site) ; vide : non précisée. Reprise par le rythme des chapitres.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_heure',
			),
			'yume_bloque'           => array(
				'type'        => 'boolean',
				'description' => __( 'Tome bloqué.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_booleen',
				'default'     => false,
			),
			'yume_bloque_raison'    => array(
				'type'        => 'string',
				'description' => __( 'Raison du blocage.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte',
			),
			'yume_derniere_maj'     => array(
				'type'        => 'string',
				'description' => __( 'Dernière mise à jour du planning (GMT, Y-m-d H:i:s).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_datetime',
			),
			'yume_maj_par'          => array(
				'type'        => 'integer',
				'description' => __( 'Auteur de la dernière mise à jour du planning (ID utilisateur).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
				'prive'       => true,
			),
			'yume_note_equipe'      => array(
				'type'        => 'string',
				'description' => __( 'Note interne de l’équipe (jamais publique).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte_long',
				'equipe'      => true,
			),
			'yume_pause'            => array(
				'type'          => 'object',
				'description'   => __( 'Tome mis en pause par l’équipe (ni retard ni rappel) : depuis (GMT, Y-m-d H:i:s) et par (ID utilisateur) ; vide sinon.', 'yume-core' ),
				'sanitize'      => static function ( $v ) {
					return is_array( $v ) ? array(
						'depuis' => san_datetime( $v['depuis'] ?? '' ),
						'par'    => san_entier( $v['par'] ?? 0 ),
					) : '';
				},
				'schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'depuis' => $chaine,
						'par'    => array( 'type' => 'integer' ),
					),
					'additionalProperties' => false,
				),
				'prive'         => true,
				'lecture_seule' => true,
			),
			'yume_nb_chapitres'     => array(
				'type'          => 'integer',
				'description'   => __( 'Nombre de chapitres publiés (cache).', 'yume-core' ),
				'sanitize'      => __NAMESPACE__ . '\\san_entier',
				'default'       => 0,
				'lecture_seule' => true,
			),
		),
		CPT_CHAPITRE => array(
			'yume_tome_id'       => array(
				'type'        => 'integer',
				'description' => __( 'Tome du chapitre.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_oeuvre_id'     => array(
				'type'        => 'integer',
				'description' => __( 'Œuvre du chapitre (recopiée depuis le tome : toute autre valeur est corrigée à l’enregistrement).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_numero'        => array(
				'type'        => 'number',
				'description' => __( 'Numéro du chapitre (0 pour un prologue).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_nombre',
			),
			'yume_sous_titre'    => array(
				'type'        => 'string',
				'description' => __( 'Sous-titre du chapitre.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_texte',
			),
			'yume_nature'        => array(
				'type'        => 'string',
				'description' => __( 'Nature : chapitre, prologue, interlude, épilogue, postface, bonus, illustrations.', 'yume-core' ),
				'sanitize'    => static function ( $v ) {
					return san_enum( $v, array_keys( yume_natures_chapitre() ), 'chapitre' );
				},
				'default'     => 'chapitre',
				'schema'      => array(
					'type' => 'string',
					'enum' => array_keys( yume_natures_chapitre() ),
				),
			),
			'yume_credits'       => array(
				'type'        => 'object',
				'description' => __( 'Crédits du chapitre.', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_trio_textes',
				'default'     => $trio_vide,
				'schema'      => $trio_txt,
			),
			'yume_nb_mots'       => array(
				'type'        => 'integer',
				'description' => __( 'Nombre de mots (calculé si absent).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_temps_lecture' => array(
				'type'        => 'integer',
				'description' => __( 'Temps de lecture en minutes (230 mots/min, calculé si absent).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_entier',
				'default'     => 0,
			),
			'yume_source'        => array(
				'type'        => 'object',
				'description' => __( 'Traçabilité de l’import (format, empreinte, date).', 'yume-core' ),
				'sanitize'    => __NAMESPACE__ . '\\san_source',
				'default'     => array(
					'format'     => '',
					'hash'       => '',
					'importe_le' => '',
				),
				'schema'      => array(
					'type'                 => 'object',
					'properties'           => array(
						'format'     => $chaine,
						'hash'       => $chaine,
						'importe_le' => $chaine,
					),
					'additionalProperties' => false,
				),
			),
		),
	);
}

/**
 * Clés déclarées par le module core (toutes sous-catégories confondues).
 *
 * @return string[]
 */
function cles_meta(): array {
	static $cles = null;
	if ( null === $cles ) {
		$cles = array();
		foreach ( definitions_meta() as $defs ) {
			$cles = array_merge( $cles, array_keys( $defs ) );
		}
		$cles = array_values( array_unique( $cles ) );
	}
	return $cles;
}

/**
 * Droit d'écriture de la note d'équipe.
 *
 * @param bool   $autorise  Valeur par défaut.
 * @param string $meta_key  Clé.
 * @param int    $object_id Contenu.
 * @param int    $user_id   Utilisateur.
 */
function auth_note_equipe( $autorise, $meta_key, $object_id, $user_id ): bool {
	return user_can( (int) $user_id, 'yume_maj_planning' );
}

/**
 * Valeur de la note d'équipe en réponse REST : vide pour qui n'est pas de l'équipe.
 *
 * @param mixed $valeur Valeur.
 */
function preparer_note_equipe( $valeur ) {
	return current_user_can( 'yume_maj_planning' ) ? (string) $valeur : '';
}

/**
 * Clés lisibles en REST par l'équipe seulement : note d'équipe ('equipe') et ID des comptes
 * de l'équipe ('prive' : responsables, auteur de la dernière mise à jour ; SEC-04). Les noms
 * des responsables restent publics par /yume/v1/planning.
 *
 * @return string[]
 */
function cles_meta_equipe(): array {
	static $cles = null;
	if ( null === $cles ) {
		$cles = array();
		foreach ( definitions_meta() as $defs ) {
			foreach ( $defs as $cle => $def ) {
				if ( ! empty( $def['equipe'] ) || ! empty( $def['prive'] ) ) {
					$cles[] = $cle;
				}
			}
		}
		$cles = array_values( array_unique( $cles ) );
	}
	return $cles;
}

/**
 * Valeur d'une méta réservée à l'équipe en réponse REST : null hors de l'équipe (la clé est
 * ensuite retirée par masquer_note_equipe()), sinon la valeur typée selon son schéma, comme
 * le fait le cœur sans prepare_callback (WP_REST_Meta_Fields::prepare_value()).
 *
 * @param mixed            $valeur  Valeur.
 * @param \WP_REST_Request $requete Requête.
 * @param array            $args    Déclaration de la méta (schema…).
 * @return mixed
 */
function preparer_meta_privee( $valeur, $requete = null, $args = array() ) {
	if ( ! current_user_can( 'yume_maj_planning' ) ) {
		return null;
	}
	$schema = is_array( $args ) && isset( $args['schema'] ) && is_array( $args['schema'] ) ? $args['schema'] : array();
	if ( ! $schema ) {
		return $valeur;
	}
	if ( '' === $valeur && in_array( $schema['type'] ?? '', array( 'boolean', 'integer', 'number' ), true ) ) {
		$valeur = 'boolean' === $schema['type'] ? false : 0;
	}
	if ( is_wp_error( rest_validate_value_from_schema( $valeur, $schema ) ) ) {
		return null;
	}
	return rest_sanitize_value_from_schema( $valeur, $schema );
}

/**
 * Déclare toutes les métadonnées (init).
 */
function enregistrer_meta(): void {
	foreach ( definitions_meta() as $post_type => $defs ) {
		foreach ( $defs as $cle => $def ) {
			$show_in_rest = true;
			if ( isset( $def['schema'] ) || ! empty( $def['equipe'] ) || ! empty( $def['prive'] ) ) {
				$show_in_rest = array();
				if ( isset( $def['schema'] ) ) {
					$show_in_rest['schema'] = $def['schema'];
				}
				if ( ! empty( $def['equipe'] ) ) {
					$show_in_rest['prepare_callback'] = __NAMESPACE__ . '\\preparer_note_equipe';
				} elseif ( ! empty( $def['prive'] ) ) {
					$show_in_rest['prepare_callback'] = __NAMESPACE__ . '\\preparer_meta_privee';
				}
			}
			if ( ! empty( $def['equipe'] ) ) {
				$auth = __NAMESPACE__ . '\\auth_note_equipe';
			} elseif ( ! empty( $def['lecture_seule'] ) ) {
				$auth = '__return_false';
			} else {
				// map_meta_cap exige déjà le droit de modifier le contenu.
				$auth = '__return_true';
			}
			$args = array(
				'type'              => $def['type'],
				'description'       => $def['description'],
				'single'            => true,
				'sanitize_callback' => $def['sanitize'],
				'auth_callback'     => $auth,
				'show_in_rest'      => $show_in_rest,
			);
			if ( array_key_exists( 'default', $def ) ) {
				$args['default'] = $def['default'];
			}
			register_post_meta( $post_type, $cle, $args );
		}
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_meta', 6 );

/**
 * Les métadonnées Yume sont « protégées » : elles n'apparaissent pas dans la boîte brute
 * « Champs personnalisés » (les méta-boîtes Yume valident et assainissent les saisies).
 *
 * @param bool   $protegee  Valeur.
 * @param string $meta_key  Clé.
 * @param string $meta_type Type d'objet.
 */
function filtre_meta_protegee( $protegee, $meta_key, $meta_type ) {
	if ( 'post' === $meta_type && is_string( $meta_key ) && str_starts_with( $meta_key, 'yume_' ) && in_array( $meta_key, cles_meta(), true ) ) {
		return true;
	}
	return $protegee;
}
add_filter( 'is_protected_meta', __NAMESPACE__ . '\\filtre_meta_protegee', 10, 3 );

/**
 * Réponse REST d'un tome : la note d'équipe et les ID des comptes de l'équipe (responsables,
 * auteur de la dernière mise à jour) ne sont jamais exposés hors de l'équipe.
 *
 * @param \WP_REST_Response $reponse Réponse.
 * @return \WP_REST_Response
 */
function masquer_note_equipe( $reponse ) {
	if ( $reponse instanceof \WP_REST_Response && ! current_user_can( 'yume_maj_planning' ) ) {
		$data = $reponse->get_data();
		if ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$masquees = array_intersect( cles_meta_equipe(), array_keys( $data['meta'] ) );
			if ( $masquees ) {
				$data['meta'] = array_diff_key( $data['meta'], array_flip( $masquees ) );
				$reponse->set_data( $data );
			}
		}
	}
	return $reponse;
}
add_filter( 'rest_prepare_' . CPT_TOME, __NAMESPACE__ . '\\masquer_note_equipe', 99 );
