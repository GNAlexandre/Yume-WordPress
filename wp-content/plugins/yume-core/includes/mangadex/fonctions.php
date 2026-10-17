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
 * Le lecteur de mangas est-il disponible ? Livré avec 2.3.0 (vrai) ; le filtre permet de le
 * couper : lecteur, proxy des pages et synchronisation s'arrêtent alors ensemble (la
 * synchronisation publierait des chapitres illisibles).
 */
function lecteur_pret(): bool {
	/**
	 * Le lecteur des chapitres MangaDex est-il disponible ?
	 *
	 * @param bool $pret Vrai ; faux coupe lecteur, proxy et synchronisation.
	 */
	return (bool) apply_filters( 'yume_mangadex_lecteur_pret', true );
}

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
 * Premier contenu d'un type (hors corbeille et brouillon automatique) dont la méta vaut $uuid.
 *
 * @param string $post_type Type de contenu.
 * @param string $meta_key  Méta.
 * @param string $uuid      Identifiant MangaDex.
 */
function contenu_par_uuid( string $post_type, string $meta_key, string $uuid ): int {
	$uuid = extraire_uuid( $uuid );
	if ( '' === $uuid ) {
		return 0;
	}
	$ids = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- recherche ponctuelle (synchronisation, administration).
			'meta_query'     => array(
				array(
					'key'   => $meta_key,
					'value' => $uuid,
				),
			),
		)
	);
	return $ids ? (int) $ids[0] : 0;
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
	$champs[] = array(
		'key'         => 'mangadex_synchro',
		'label'       => __( 'Synchroniser les chapitres', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => 'mangadex',
		'default'     => false,
		'description' => __( 'Chaque heure, les chapitres du groupe sur MangaDex deviennent des chapitres du site, dans les œuvres liées à leur série (champ « Lien MangaDex de la série » du formulaire équipe de l’œuvre). Aucun tome ni chapitre à créer à la main. La première synchronisation d’une œuvre n’annonce rien ; ensuite, les nouveaux chapitres sont annoncés comme les autres. Rapport et essai sans rien changer : Yume → Mangas (MangaDex).', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champs_reglages' );

/**
 * Rappel à l'équipe : un chapitre publié sur MangaDex y est public tout de suite ; le site ne
 * peut qu'en retarder la reprise et l'annonce (synchronisation horaire), jamais le cacher.
 */
function rappel_publication(): string {
	return __( 'Un chapitre publié sur MangaDex y est public tout de suite : le site le reprend à la synchronisation suivante (au plus une heure) et l’annonce comme les autres. Pour un chapitre qui ne doit pas encore sortir, ne le publiez pas sur MangaDex (ou programmez-le là-bas).', 'yume-core' );
}
