<?php
/**
 * Fonctions du module mangadex : identifiants, User-Agent, réglages.
 *
 * @package Yume\Core
 */

namespace Yume\Core\MangaDex;

defined( 'ABSPATH' ) || exit;

/** Motif d'un identifiant MangaDex (UUID en minuscules). */
const MOTIF_UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

/** Groupe de traduction « Yume Novel » sur MangaDex (réglage mangadex_groupe par défaut). */
const GROUPE_YUME = '4bbecb8b-41bb-48a8-8ddb-0c9243e4c344';

/**
 * Identifiant MangaDex valide ?
 *
 * @param mixed $valeur Valeur.
 */
function est_uuid( $valeur ): bool {
	return is_string( $valeur ) && 1 === preg_match( '/^' . MOTIF_UUID . '$/', $valeur );
}

/**
 * Premier identifiant MangaDex d'une saisie (identifiant seul ou adresse
 * https://mangadex.org/title/<uuid>/…), en minuscules ; '' s'il n'y en a pas.
 *
 * @param mixed $valeur Saisie.
 */
function extraire_uuid( $valeur ): string {
	if ( ! is_scalar( $valeur ) ) {
		return '';
	}
	return preg_match( '/' . MOTIF_UUID . '/', strtolower( (string) $valeur ), $m ) ? $m[0] : '';
}

/**
 * User-Agent réel du site : MangaDex refuse les User-Agent usurpés.
 */
function agent(): string {
	$version = defined( 'YUME_CORE_VERSION' ) ? YUME_CORE_VERSION : '0';
	return 'YumeNovel/' . $version . ' (+' . home_url( '/' ) . ')';
}

/**
 * Groupe MangaDex dont le site lit les chapitres (réglage mangadex_groupe).
 */
function groupe(): string {
	$groupe = extraire_uuid( function_exists( 'yume_setting' ) ? yume_setting( 'mangadex_groupe', GROUPE_YUME ) : GROUPE_YUME );
	return '' !== $groupe ? $groupe : GROUPE_YUME;
}

/**
 * Section « Mangas (MangaDex) » de Yume → Réglages.
 *
 * @param array<string,string> $sections Sections.
 * @return array<string,string>
 */
function section_reglages( $sections ): array {
	$sections             = is_array( $sections ) ? $sections : array();
	$sections['mangadex'] = __( 'Mangas (MangaDex)', 'yume-core' );
	return $sections;
}
add_filter( 'yume_reglages_sections', __NAMESPACE__ . '\\section_reglages' );

/**
 * Champs de la section « Mangas (MangaDex) ».
 *
 * @param array $champs Champs.
 * @return array
 */
function champs_reglages( $champs ): array {
	$champs   = is_array( $champs ) ? $champs : array();
	$champs[] = array(
		'key'         => 'mangadex_groupe',
		'label'       => __( 'Groupe MangaDex', 'yume-core' ),
		'type'        => 'text',
		'section'     => 'mangadex',
		'default'     => GROUPE_YUME,
		'placeholder' => GROUPE_YUME,
		'sanitize'    => static function ( $valeur ) {
			$uuid = extraire_uuid( $valeur );
			return '' !== $uuid ? $uuid : GROUPE_YUME;
		},
		'description' => __( 'Identifiant (ou adresse https://mangadex.org/group/…) du groupe de traduction dont le site lit les chapitres en français. Par défaut : Yume Novel.', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champs_reglages' );
