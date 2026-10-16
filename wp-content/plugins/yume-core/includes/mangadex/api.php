<?php
/**
 * API PHP publique du module mangadex (contrat §7) : œuvres et chapitres liés à MangaDex.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\MangaDex\contenu_par_uuid;
use function Yume\Core\MangaDex\extraire_uuid;

/**
 * Chapitre hébergé sur MangaDex : identifiant, nombre de pages, version (8 caractères, entre
 * dans l'adresse des pages relayées), groupes crédités et adresse du chapitre sur MangaDex ;
 * null pour un chapitre importé (DOCX, EPUB) ou un autre contenu.
 *
 * @param int $chapitre_id Chapitre (yume_chapitre).
 * @return array{uuid:string,pages:int,version:string,groupes:string[],url:string}|null
 */
function yume_mangadex_chapitre( int $chapitre_id ): ?array {
	if ( 'yume_chapitre' !== get_post_type( $chapitre_id ) ) {
		return null;
	}
	$uuid = extraire_uuid( get_post_meta( $chapitre_id, 'yume_mdx_chapitre', true ) );
	if ( '' === $uuid ) {
		return null;
	}
	$groupes = get_post_meta( $chapitre_id, 'yume_mdx_groupes', true );
	return array(
		'uuid'    => $uuid,
		'pages'   => max( 0, (int) get_post_meta( $chapitre_id, 'yume_mdx_pages', true ) ),
		'version' => (string) get_post_meta( $chapitre_id, 'yume_mdx_version', true ),
		'groupes' => is_array( $groupes ) ? array_values( array_filter( array_map( 'strval', $groupes ) ) ) : array(),
		'url'     => 'https://mangadex.org/chapter/' . $uuid,
	);
}

/**
 * Série MangaDex d'une œuvre (identifiant), '' si l'œuvre n'est pas liée à MangaDex.
 *
 * @param int $oeuvre_id Œuvre (yume_oeuvre).
 */
function yume_mangadex_serie( int $oeuvre_id ): string {
	return 'yume_oeuvre' === get_post_type( $oeuvre_id ) ? extraire_uuid( get_post_meta( $oeuvre_id, 'yume_mdx_serie', true ) ) : '';
}

/**
 * Œuvre liée à une série MangaDex (la plus ancienne si plusieurs), 0 si aucune.
 *
 * @param string $serie Identifiant de la série.
 */
function yume_mangadex_oeuvre_par_serie( string $serie ): int {
	return contenu_par_uuid( 'yume_oeuvre', 'yume_mdx_serie', $serie );
}

/**
 * Chapitre du site correspondant à un chapitre MangaDex, 0 si aucun.
 *
 * @param string $uuid Identifiant du chapitre MangaDex.
 */
function yume_mangadex_chapitre_par_uuid( string $uuid ): int {
	return contenu_par_uuid( 'yume_chapitre', 'yume_mdx_chapitre', $uuid );
}
