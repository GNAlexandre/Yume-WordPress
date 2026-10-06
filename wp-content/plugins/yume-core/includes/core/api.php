<?php
/**
 * API PHP publique du module core (§7 du contrat). Signatures figées : toute évolution
 * passe d'abord par docs/06-contrat-technique.md.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Core\defauts_reglages;
use function Yume\Core\Core\est_page_illustrations;
use function Yume\Core\Core\ids_par_meta;
use function Yume\Core\Core\images_galerie;
use function Yume\Core\Core\numero_fr;
use function Yume\Core\Core\numero_ou_null;
use function Yume\Core\Core\san_url;
use function Yume\Core\Core\statuts_demandes;
use function Yume\Core\Core\termes_taxonomie;
use function Yume\Core\Core\url_illustrations;
use function Yume\Core\Core\url_illustrations_avant;

/**
 * Lit un réglage Yume (option yume_reglages).
 *
 * Ordre de résolution : valeur enregistrée, sinon $default s'il est fourni, sinon la valeur
 * par défaut du contrat (§6) ou d'un champ ajouté par yume_reglages_champs.
 *
 * @param string $key     Clé du réglage.
 * @param mixed  $default Valeur de repli.
 * @return mixed
 */
function yume_setting( string $key, $default = null ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- signature figée par le contrat (§7).
	$reglages = get_option( 'yume_reglages', array() );
	if ( is_array( $reglages ) && array_key_exists( $key, $reglages ) ) {
		return $reglages[ $key ];
	}
	if ( null !== $default ) {
		return $default;
	}
	$defauts = defauts_reglages();
	return $defauts[ $key ] ?? null;
}

/**
 * Tomes d'une œuvre, triés par numéro.
 *
 * @param int                 $oeuvre_id ID de l'œuvre.
 * @param array<string,mixed> $args      status ('publish' par défaut | 'any' | liste), order ('ASC'|'DESC'
 *                                       sur yume_numero ; les tomes sans numéro restent à la fin),
 *                                       nature (slug ou liste de slugs de yume_natures_tome()).
 * @return WP_Post[]
 */
function yume_get_tomes( int $oeuvre_id, array $args = array() ): array {
	$args = wp_parse_args(
		$args,
		array(
			'status' => 'publish',
			'order'  => 'ASC',
			'nature' => '',
		)
	);
	if ( $oeuvre_id <= 0 ) {
		return array();
	}
	$ids = ids_par_meta( 'yume_tome', 'yume_oeuvre_id', $oeuvre_id, statuts_demandes( $args['status'] ) );
	if ( ! $ids ) {
		return array();
	}
	_prime_post_caches( $ids, false, true );
	$tomes   = array_values( array_filter( array_map( 'get_post', $ids ) ) );
	$natures = array_filter( array_map( 'strval', (array) $args['nature'] ) );
	if ( $natures ) {
		$tomes = array_values(
			array_filter(
				$tomes,
				static function ( WP_Post $tome ) use ( $natures ): bool {
					return in_array( (string) get_post_meta( $tome->ID, 'yume_nature', true ), $natures, true );
				}
			)
		);
	}
	$sens = 'DESC' === strtoupper( (string) $args['order'] ) ? -1 : 1;
	usort(
		$tomes,
		static function ( WP_Post $a, WP_Post $b ) use ( $sens ): int {
			$na = numero_ou_null( get_post_meta( $a->ID, 'yume_numero', true ) );
			$nb = numero_ou_null( get_post_meta( $b->ID, 'yume_numero', true ) );
			if ( $na !== $nb ) {
				if ( null === $na ) {
					return 1;
				}
				if ( null === $nb ) {
					return -1;
				}
				return $sens * ( $na <=> $nb );
			}
			$cmp = ( $a->menu_order <=> $b->menu_order );
			if ( 0 === $cmp ) {
				$cmp = strcmp( (string) $a->post_date, (string) $b->post_date );
			}
			if ( 0 === $cmp ) {
				$cmp = $a->ID <=> $b->ID;
			}
			return $sens * $cmp;
		}
	);
	return $tomes;
}

/**
 * Chapitres d'un tome, triés par menu_order puis par numéro (sans numéro en dernier).
 *
 * @param int                 $tome_id ID du tome.
 * @param array<string,mixed> $args    status ('publish' par défaut | 'any' | liste).
 * @return WP_Post[]
 */
function yume_get_chapitres( int $tome_id, array $args = array() ): array {
	$args = wp_parse_args( $args, array( 'status' => 'publish' ) );
	if ( $tome_id <= 0 ) {
		return array();
	}
	$ids = ids_par_meta( 'yume_chapitre', 'yume_tome_id', $tome_id, statuts_demandes( $args['status'] ) );
	if ( ! $ids ) {
		return array();
	}
	_prime_post_caches( $ids, false, true );
	$chapitres = array_values( array_filter( array_map( 'get_post', $ids ) ) );
	usort(
		$chapitres,
		static function ( WP_Post $a, WP_Post $b ): int {
			$cmp = ( $a->menu_order <=> $b->menu_order );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			$na = numero_ou_null( get_post_meta( $a->ID, 'yume_numero', true ) );
			$nb = numero_ou_null( get_post_meta( $b->ID, 'yume_numero', true ) );
			if ( $na !== $nb ) {
				if ( null === $na ) {
					return 1;
				}
				if ( null === $nb ) {
					return -1;
				}
				return $na <=> $nb;
			}
			$cmp = strcmp( (string) $a->post_date, (string) $b->post_date );
			return 0 !== $cmp ? $cmp : ( $a->ID <=> $b->ID );
		}
	);
	return $chapitres;
}

/**
 * Œuvre d'un contenu : l'œuvre elle-même, celle d'un tome ou d'un chapitre, ou la première
 * œuvre liée d'un article (taxonomie yume_oeuvre_liee). 0 si aucune.
 *
 * @param int $post_id ID.
 */
function yume_get_oeuvre_id( int $post_id ): int {
	$type = $post_id > 0 ? get_post_type( $post_id ) : false;
	switch ( $type ) {
		case 'yume_oeuvre':
			return $post_id;
		case 'yume_tome':
			$id = (int) get_post_meta( $post_id, 'yume_oeuvre_id', true );
			return 'yume_oeuvre' === get_post_type( $id ) ? $id : 0;
		case 'yume_chapitre':
			$id = (int) get_post_meta( $post_id, 'yume_oeuvre_id', true );
			if ( ! $id ) {
				$id = (int) get_post_meta( (int) get_post_meta( $post_id, 'yume_tome_id', true ), 'yume_oeuvre_id', true );
			}
			return 'yume_oeuvre' === get_post_type( $id ) ? $id : 0;
		case 'post':
			$termes = get_the_terms( $post_id, 'yume_oeuvre_liee' );
			if ( is_array( $termes ) ) {
				foreach ( $termes as $terme ) {
					$id = (int) get_term_meta( $terme->term_id, 'yume_oeuvre_id', true );
					if ( 'yume_oeuvre' === get_post_type( $id ) ) {
						return $id;
					}
				}
			}
			return 0;
	}
	return 0;
}

/**
 * Tome d'un chapitre (ou le tome lui-même si l'ID est celui d'un tome). 0 si aucun.
 *
 * @param int $chapitre_id ID du chapitre.
 */
function yume_get_tome_id( int $chapitre_id ): int {
	$type = $chapitre_id > 0 ? get_post_type( $chapitre_id ) : false;
	if ( 'yume_tome' === $type ) {
		return $chapitre_id;
	}
	if ( 'yume_chapitre' !== $type ) {
		return 0;
	}
	$id = (int) get_post_meta( $chapitre_id, 'yume_tome_id', true );
	return 'yume_tome' === get_post_type( $id ) ? $id : 0;
}

/**
 * Chapitre publié précédent ou suivant, en traversant les tomes publiés de l'œuvre
 * (dernier chapitre du tome précédent, premier chapitre du tome suivant).
 *
 * @param int    $chapitre_id ID du chapitre courant (éventuellement non publié : aperçu).
 * @param string $sens        'prev' ou 'next'.
 */
function yume_chapitre_voisin( int $chapitre_id, string $sens ): ?WP_Post {
	$pas = 'prev' === $sens ? -1 : ( 'next' === $sens ? 1 : 0 );
	if ( ! $pas || 'yume_chapitre' !== get_post_type( $chapitre_id ) ) {
		return null;
	}
	$tome_id = yume_get_tome_id( $chapitre_id );
	if ( ! $tome_id ) {
		return null;
	}

	// Dans le tome : position du chapitre courant parmi tous les chapitres, puis premier publié.
	$tous = yume_get_chapitres( $tome_id, array( 'status' => 'any' ) );
	$ids  = array_map( 'intval', wp_list_pluck( $tous, 'ID' ) );
	$pos  = array_search( $chapitre_id, $ids, true );
	$nb   = count( $tous );
	if ( false !== $pos ) {
		for ( $i = $pos + $pas; $i >= 0 && $i < $nb; $i += $pas ) {
			if ( 'publish' === $tous[ $i ]->post_status ) {
				return $tous[ $i ];
			}
		}
	}

	// Tomes voisins publiés de l'œuvre.
	$oeuvre_id = yume_get_oeuvre_id( $tome_id );
	if ( ! $oeuvre_id ) {
		return null;
	}
	$tomes = yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) );
	$ids_t = array_map( 'intval', wp_list_pluck( $tomes, 'ID' ) );
	$pos_t = array_search( $tome_id, $ids_t, true );
	if ( false === $pos_t ) {
		return null;
	}
	$nb_t = count( $tomes );
	for ( $i = $pos_t + $pas; $i >= 0 && $i < $nb_t; $i += $pas ) {
		if ( 'publish' !== $tomes[ $i ]->post_status ) {
			continue;
		}
		$chapitres = yume_get_chapitres( (int) $tomes[ $i ]->ID );
		if ( $chapitres ) {
			return $pas > 0 ? $chapitres[0] : $chapitres[ count( $chapitres ) - 1 ];
		}
	}
	return null;
}

/**
 * Couverture d'un contenu : celle du tome (pour un chapitre, celle de son tome), sinon
 * celle de l'œuvre, sinon 0.
 *
 * @param int $post_id ID.
 */
function yume_get_cover_id( int $post_id ): int {
	$type = $post_id > 0 ? get_post_type( $post_id ) : false;
	if ( ! $type ) {
		return 0;
	}
	if ( 'yume_chapitre' === $type ) {
		$tome_id = yume_get_tome_id( $post_id );
		if ( $tome_id ) {
			return yume_get_cover_id( $tome_id );
		}
		$oeuvre_id = yume_get_oeuvre_id( $post_id );
		return $oeuvre_id ? (int) get_post_thumbnail_id( $oeuvre_id ) : 0;
	}
	$id = (int) get_post_thumbnail_id( $post_id );
	if ( $id ) {
		return $id;
	}
	if ( 'yume_tome' === $type ) {
		$oeuvre_id = yume_get_oeuvre_id( $post_id );
		return $oeuvre_id ? (int) get_post_thumbnail_id( $oeuvre_id ) : 0;
	}
	return 0;
}

/**
 * Cadrage d'une image de couverture : point à garder visible quand la couverture est rognée
 * au format 2:3 (pourcentages horizontal et vertical, 0 = gauche/haut), ou null (centre).
 *
 * @param int $image_id Pièce jointe.
 * @return array{x:int,y:int}|null
 */
function yume_cadrage_couverture( int $image_id ): ?array {
	$cadrage = $image_id > 0 ? get_post_meta( $image_id, 'yume_cadrage', true ) : '';
	if ( ! is_array( $cadrage ) || ! isset( $cadrage['x'], $cadrage['y'] ) ) {
		return null;
	}
	return array(
		'x' => max( 0, min( 100, (int) $cadrage['x'] ) ),
		'y' => max( 0, min( 100, (int) $cadrage['y'] ) ),
	);
}

/**
 * Enregistre le cadrage d'une couverture ; le centre (50, 50) ou null le supprime.
 *
 * @param int        $image_id Pièce jointe.
 * @param array|null $cadrage  array{x:int,y:int} ou null.
 */
function yume_enregistrer_cadrage( int $image_id, ?array $cadrage ): void {
	if ( $image_id <= 0 || 'attachment' !== get_post_type( $image_id ) ) {
		return;
	}
	$x = null !== $cadrage ? max( 0, min( 100, (int) ( $cadrage['x'] ?? 50 ) ) ) : 50;
	$y = null !== $cadrage ? max( 0, min( 100, (int) ( $cadrage['y'] ?? 50 ) ) ) : 50;
	if ( 50 === $x && 50 === $y ) {
		delete_post_meta( $image_id, 'yume_cadrage' );
		return;
	}
	update_post_meta(
		$image_id,
		'yume_cadrage',
		array(
			'x' => $x,
			'y' => $y,
		)
	);
}

/**
 * Balise img d'une couverture (wp_get_attachment_image) qui respecte son cadrage : la taille
 * yume-couverture est rognée au centre dès le téléversement ; une couverture cadrée utilise
 * donc une taille non rognée, positionnée sur son point de cadrage (object-position).
 *
 * @param int    $image_id Pièce jointe.
 * @param string $taille   Taille WordPress (yume-couverture par défaut).
 * @param array  $attrs    Attributs de wp_get_attachment_image().
 */
function yume_image_couverture( int $image_id, string $taille = 'yume-couverture', array $attrs = array() ): string {
	$cadrage = yume_cadrage_couverture( $image_id );
	if ( $cadrage ) {
		$taille         = 'medium_large';
		$style          = trim( (string) ( $attrs['style'] ?? '' ) );
		$attrs['style'] = ( '' !== $style ? rtrim( $style, ';' ) . ';' : '' ) . 'object-position:' . $cadrage['x'] . '% ' . $cadrage['y'] . '%';
	}
	return (string) wp_get_attachment_image( $image_id, $taille, false, $attrs );
}

/**
 * Libellé d'un tome : « Tome 9 », « Arc 7 », « Tome EX 2 », « Bonus 1 »… ;
 * forme courte : « T.9 », « A.7 », « EX.2 », « B.1 », « Ch.3 ».
 *
 * @param int  $tome_id ID du tome (ou d'un chapitre : son tome).
 * @param bool $court   Forme courte.
 */
function yume_libelle_tome( int $tome_id, bool $court = false ): string {
	$tome_id = yume_get_tome_id( $tome_id );
	if ( ! $tome_id ) {
		return '';
	}
	$nature = (string) get_post_meta( $tome_id, 'yume_nature', true );
	$numero = numero_ou_null( get_post_meta( $tome_id, 'yume_numero', true ) );
	$formes = array(
		'tome'      => array( __( 'Tome', 'yume-core' ), __( 'T.', 'yume-core' ) ),
		'arc'       => array( __( 'Arc', 'yume-core' ), __( 'A.', 'yume-core' ) ),
		'ex'        => array( __( 'Tome EX', 'yume-core' ), __( 'EX.', 'yume-core' ) ),
		'bonus'     => array( __( 'Bonus', 'yume-core' ), __( 'B.', 'yume-core' ) ),
		'chapitres' => array( __( 'Chapitres', 'yume-core' ), __( 'Ch.', 'yume-core' ) ),
	);
	$forme  = $formes[ $nature ] ?? $formes['tome'];
	if ( null === $numero ) {
		$libelle = $court ? rtrim( $forme[1], '.' ) : $forme[0];
	} else {
		$libelle = $court ? $forme[1] . numero_fr( $numero ) : $forme[0] . ' ' . numero_fr( $numero );
	}
	/**
	 * Filtre le libellé d'un tome.
	 *
	 * @param string $libelle Libellé.
	 * @param int    $tome_id ID du tome.
	 * @param bool   $court   Forme courte.
	 */
	return (string) apply_filters( 'yume_libelle_tome', $libelle, $tome_id, $court );
}

/**
 * Libellé d'un chapitre : « Chapitre 3 », « Prologue », « Interlude 2 », « Postface »…
 *
 * @param int $chapitre_id ID du chapitre.
 */
function yume_libelle_chapitre( int $chapitre_id ): string {
	if ( 'yume_chapitre' !== get_post_type( $chapitre_id ) ) {
		return '';
	}
	$nature  = (string) get_post_meta( $chapitre_id, 'yume_nature', true );
	$numero  = numero_ou_null( get_post_meta( $chapitre_id, 'yume_numero', true ) );
	$natures = yume_natures_chapitre();
	$nom     = $natures[ $nature ] ?? $natures['chapitre'];
	if ( '' === $nature || 'chapitre' === $nature ) {
		$libelle = null === $numero ? $nom : $nom . ' ' . numero_fr( $numero );
	} elseif ( in_array( $nature, array( 'interlude', 'bonus' ), true ) && null !== $numero && $numero > 0 ) {
		$libelle = $nom . ' ' . numero_fr( $numero );
	} else {
		$libelle = $nom;
	}
	/**
	 * Filtre le libellé d'un chapitre.
	 *
	 * @param string $libelle     Libellé.
	 * @param int    $chapitre_id ID du chapitre.
	 */
	return (string) apply_filters( 'yume_libelle_chapitre', $libelle, $chapitre_id );
}

/**
 * Types d'œuvre (slug => libellé) : light-novel, web-novel, manga.
 *
 * @return array<string,string>
 */
function yume_types(): array {
	return termes_taxonomie( 'yume_type' );
}

/**
 * Statuts de traduction (slug => libellé) : en-cours, terminee, en-pause, licenciee, abandonnee.
 *
 * @return array<string,string>
 */
function yume_statuts(): array {
	return termes_taxonomie( 'yume_statut' );
}

/**
 * Natures de tome (slug => libellé).
 *
 * @return array<string,string>
 */
function yume_natures_tome(): array {
	return array(
		'tome'      => __( 'Tome', 'yume-core' ),
		'arc'       => __( 'Arc', 'yume-core' ),
		'ex'        => __( 'Tome EX', 'yume-core' ),
		'bonus'     => __( 'Bonus', 'yume-core' ),
		'chapitres' => __( 'Chapitres', 'yume-core' ),
	);
}

/**
 * Natures de chapitre (slug => libellé).
 *
 * @return array<string,string>
 */
function yume_natures_chapitre(): array {
	return array(
		'chapitre'      => __( 'Chapitre', 'yume-core' ),
		'prologue'      => __( 'Prologue', 'yume-core' ),
		'interlude'     => __( 'Interlude', 'yume-core' ),
		'epilogue'      => __( 'Épilogue', 'yume-core' ),
		'postface'      => __( 'Postface', 'yume-core' ),
		'bonus'         => __( 'Bonus', 'yume-core' ),
		'illustrations' => __( 'Illustrations', 'yume-core' ),
	);
}

/**
 * Étapes du planning (slug => libellé), dans l'ordre du flux de travail.
 *
 * @return array<string,string>
 */
function yume_etapes(): array {
	return array(
		'a_faire'    => __( 'À faire', 'yume-core' ),
		'traduction' => __( 'Traduction', 'yume-core' ),
		'relecture'  => __( 'Relecture', 'yume-core' ),
		'edition'    => __( 'Édition', 'yume-core' ),
		'publie'     => __( 'Publié', 'yume-core' ),
	);
}

/**
 * Jours de la semaine (slug => libellé), du lundi au dimanche.
 *
 * @return array<string,string>
 */
function yume_jours_semaine(): array {
	return array(
		'lundi'    => __( 'Lundi', 'yume-core' ),
		'mardi'    => __( 'Mardi', 'yume-core' ),
		'mercredi' => __( 'Mercredi', 'yume-core' ),
		'jeudi'    => __( 'Jeudi', 'yume-core' ),
		'vendredi' => __( 'Vendredi', 'yume-core' ),
		'samedi'   => __( 'Samedi', 'yume-core' ),
		'dimanche' => __( 'Dimanche', 'yume-core' ),
	);
}

/**
 * Liens externes de téléchargement d'un tome (pour un chapitre : ceux de son tome).
 *
 * Un tome « Planifié » ou « En cours de publication » choisi par l'équipe (méta yume_parution
 * = planifie ou en_cours) n'en montre aucun aux lecteurs : les liens restent enregistrés (voir
 * yume_liens_tome_masques()) et réapparaissent quand le tome repasse « Publié ».
 *
 * @param int $tome_id ID du tome.
 * @return array{pdf:string,epub:string}
 */
function yume_liens_telechargement( int $tome_id ): array {
	$tome_id = yume_get_tome_id( $tome_id );
	if ( ! $tome_id || yume_liens_tome_masques( $tome_id ) ) {
		return array(
			'pdf'  => '',
			'epub' => '',
		);
	}
	return array(
		'pdf'  => san_url( get_post_meta( $tome_id, 'yume_lien_pdf', true ) ),
		'epub' => san_url( get_post_meta( $tome_id, 'yume_lien_epub', true ) ),
	);
}

/**
 * L'utilisateur peut-il mettre à jour le planning de ce tome ?
 * Vrai avec yume_maj_planning_tous, ou avec yume_maj_planning s'il est l'un des responsables.
 *
 * @param int $tome_id ID du tome.
 * @param int $user_id Utilisateur (0 = utilisateur courant).
 */
function yume_user_can_edit_planning( int $tome_id, int $user_id = 0 ): bool {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	$peut    = false;
	if ( $user_id > 0 && 'yume_tome' === get_post_type( $tome_id ) ) {
		if ( user_can( $user_id, 'yume_maj_planning_tous' ) ) {
			$peut = true;
		} elseif ( user_can( $user_id, 'yume_maj_planning' ) ) {
			$responsables = get_post_meta( $tome_id, 'yume_responsables', true );
			$peut         = in_array( $user_id, array_map( 'intval', array_values( (array) $responsables ) ), true );
		}
	}
	/**
	 * Filtre le droit de mise à jour du planning d'un tome.
	 *
	 * @param bool $peut    Droit calculé.
	 * @param int  $tome_id ID du tome.
	 * @param int  $user_id ID de l'utilisateur.
	 */
	return (bool) apply_filters( 'yume_user_can_edit_planning', $peut, $tome_id, $user_id );
}

/**
 * URL d'une page Yume ('bibliotheque', 'planning', 'equipe', 'publier', 'membres', 'compte',
 * 'connexion', 'actualites', 'mentions-legales', 'accueil').
 * Page enregistrée dans l'option yume_pages (clé => ID, créée par la migration) si elle est
 * publiée ainsi que ses parents ; sinon (absente, à la corbeille, en brouillon, privée ou sous
 * un parent dépublié) repli sur home_url( '/<chemin>/' ), le chemin du contrat §11.
 *
 * @param string $cle Clé de page.
 */
function yume_url_page( string $cle ): string {
	$slugs = array(
		'bibliotheque'     => 'bibliotheque',
		'planning'         => 'planning',
		'equipe'           => 'equipe',
		'publier'          => 'equipe/publier',
		'membres'          => 'equipe/membres',
		'compte'           => 'compte',
		'connexion'        => 'connexion',
		'actualites'       => 'actualites',
		'mentions-legales' => 'mentions-legales',
		'accueil'          => '',
	);
	$url   = '';
	$pages = get_option( 'yume_pages', array() );
	if ( is_array( $pages ) && ! empty( $pages[ $cle ] ) ) {
		$page     = get_post( (int) $pages[ $cle ] );
		$publiee  = static fn( $p ): bool => $p instanceof WP_Post && 'page' === $p->post_type && 'publish' === $p->post_status;
		$en_ligne = $publiee( $page );
		foreach ( $en_ligne ? get_post_ancestors( $page ) : array() as $parent ) {
			$en_ligne = $en_ligne && $publiee( get_post( $parent ) );
		}
		if ( $en_ligne ) {
			$url = (string) get_permalink( $page );
		}
	}
	if ( '' === $url ) {
		$slug = $slugs[ $cle ] ?? sanitize_title( $cle );
		$url  = home_url( '' !== $slug ? '/' . $slug . '/' : '/' );
	}
	/**
	 * Filtre l'URL d'une page Yume.
	 *
	 * @param string $url URL.
	 * @param string $cle Clé de page.
	 */
	return (string) apply_filters( 'yume_url_page', $url, $cle );
}

/**
 * Images de la galerie d'un tome (métadonnée yume_illustrations : images placées avant le
 * premier chapitre), dans l'ordre de lecture ; pièces jointes absentes ou non images ignorées.
 *
 * @param int $tome_id Tome.
 * @return int[]
 */
function yume_illustrations_tome( int $tome_id ): array {
	return images_galerie( $tome_id );
}

/**
 * Adresse de la page « Illustrations » d'un tome (/lire/{oeuvre}/{tome}/illustrations/), ou
 * chaîne vide si le tome n'en a pas (galerie vide, chapitre réel « illustrations », liens simples).
 *
 * @param int $tome_id Tome.
 */
function yume_url_illustrations( int $tome_id ): string {
	return url_illustrations( $tome_id );
}

/**
 * La requête principale affiche-t-elle la page « Illustrations » d'un tome (l'objet de la
 * requête est alors le tome) ?
 */
function yume_est_page_illustrations(): bool {
	return est_page_illustrations();
}

/**
 * Page « Illustrations » qui précède un chapitre dans l'ordre de lecture (premier chapitre
 * publié d'un tome qui en a une), ou chaîne vide.
 *
 * @param int $chapitre_id Chapitre.
 */
function yume_url_illustrations_avant( int $chapitre_id ): string {
	return url_illustrations_avant( $chapitre_id );
}

/**
 * Parution d'un tome (publication chapitre par chapitre) :
 *
 * - « a_paraitre » : aucun chapitre lisible (tome en brouillon, programmé ou en attente) ;
 * - « en_cours » : tome en ligne dont des chapitres restent à sortir (méta yume_parution =
 *   en_cours, posée à la publication d'un chapitre sans « Tome complet ») ;
 * - « complet » : méta yume_parution = complet (case « Tome complet », état « Publié »), ou
 *   tome en ligne antérieur à cette méta qui n'est pas une sortie progressive en cours.
 *
 * Méta « planifie » (état « Planifié » choisi par l'équipe dans « Modifier le tome ») :
 * « a_paraitre », quel que soit le statut du tome. Un choix de l'équipe est noté dans la méta
 * interne _yume_parution_manuelle (yume_parution_manuelle()).
 *
 * Tomes antérieurs (méta vide) : un arc, un recueil « Chapitres » ou un tome de web novel en
 * ligne reste « en_cours » tant que des chapitres sont programmés ou en brouillon, ou que son
 * étape de planning n'est pas « publié » (règle historique de la bibliothèque) ; tout autre tome
 * en ligne est « complet ».
 *
 * @param int $tome_id Tome.
 * @return string a_paraitre | en_cours | complet
 */
function yume_parution_tome( int $tome_id ): string {
	$tome_id = yume_get_tome_id( $tome_id );
	if ( ! $tome_id ) {
		return 'a_paraitre';
	}
	$meta = (string) get_post_meta( $tome_id, 'yume_parution', true );
	if ( 'planifie' === $meta ) {
		$etat = 'a_paraitre';
	} elseif ( 'publish' !== get_post_status( $tome_id ) ) {
		$etat = 'complet' === $meta ? 'complet' : 'a_paraitre';
	} elseif ( 'complet' === $meta || 'en_cours' === $meta ) {
		$etat = $meta;
	} else {
		$etat = yume_parution_historique( $tome_id );
	}
	/**
	 * Filtre la parution d'un tome.
	 *
	 * @param string $etat    a_paraitre | en_cours | complet.
	 * @param int    $tome_id Tome.
	 */
	return (string) apply_filters( 'yume_parution_tome', $etat, $tome_id );
}

/**
 * Parution d'un tome en ligne sans méta yume_parution (tomes antérieurs) : voir
 * yume_parution_tome().
 *
 * @param int $tome_id Tome publié.
 * @return string en_cours | complet
 */
function yume_parution_historique( int $tome_id ): string {
	$nature      = (string) get_post_meta( $tome_id, 'yume_nature', true );
	$oeuvre_id   = yume_get_oeuvre_id( $tome_id );
	$progressive = in_array( $nature, array( 'arc', 'chapitres' ), true ) || ( $oeuvre_id > 0 && has_term( 'web-novel', 'yume_type', $oeuvre_id ) );
	if ( ! $progressive ) {
		return 'complet';
	}
	$etape = (string) get_post_meta( $tome_id, 'yume_etape', true );
	if ( '' !== $etape && 'publie' !== $etape ) {
		return 'en_cours';
	}
	return yume_get_chapitres( $tome_id, array( 'status' => array( 'future', 'draft', 'pending' ) ) ) ? 'en_cours' : 'complet';
}

/**
 * Libellés des parutions (clé => libellé).
 *
 * @return array<string,string>
 */
function yume_parutions(): array {
	return array(
		'a_paraitre' => __( 'À paraître', 'yume-core' ),
		'en_cours'   => __( 'En cours', 'yume-core' ),
		'complet'    => __( 'Publié', 'yume-core' ),
	);
}

/**
 * Les liens PDF et EPUB d'un tome sont-ils masqués aux lecteurs ? Vrai quand l'équipe a choisi
 * l'état « Planifié » ou « En cours de publication » (méta yume_parution = planifie ou
 * en_cours) : un tome rouvert garde ses liens en base sans les montrer.
 *
 * @param int $tome_id Tome.
 */
function yume_liens_tome_masques( int $tome_id ): bool {
	return in_array( (string) get_post_meta( $tome_id, 'yume_parution', true ), array( 'planifie', 'en_cours' ), true );
}

/**
 * Dernier choix d'état fait à la main par l'équipe (« Modifier le tome »), ou null : méta
 * interne _yume_parution_manuelle {etat: planifie|en_cours|complet, date: GMT « Y-m-d H:i:s »,
 * par: ID}. Effacée quand une action de l'équipe ailleurs change l'état (publication d'un
 * chapitre d'un tome « Planifié », « Tome complet » du formulaire de publication), changement
 * alors journalisé.
 *
 * @param int $tome_id Tome.
 * @return array{etat:string,date:string,par:int}|null
 */
function yume_parution_manuelle( int $tome_id ): ?array {
	$choix = get_post_meta( $tome_id, '_yume_parution_manuelle', true );
	if ( ! is_array( $choix ) || empty( $choix['etat'] ) ) {
		return null;
	}
	return array(
		'etat' => (string) $choix['etat'],
		'date' => (string) ( $choix['date'] ?? '' ),
		'par'  => (int) ( $choix['par'] ?? 0 ),
	);
}

/**
 * Libellés de l'état d'un tome dans l'espace équipe (clé de yume_parution_tome() => libellé) :
 * « Planifié » (au planning, rien de lisible), « En cours de publication » (chapitre par chapitre),
 * « Publié » (tous les chapitres en ligne). Côté lecteurs : yume_parutions().
 *
 * @return array<string,string>
 */
function yume_etats_tome(): array {
	return array(
		'a_paraitre' => __( 'Planifié', 'yume-core' ),
		'en_cours'   => __( 'En cours de publication', 'yume-core' ),
		'complet'    => __( 'Publié', 'yume-core' ),
	);
}

/**
 * Libellés de l'état d'une œuvre dans l'espace équipe (slug du terme yume_statut => libellé) :
 * mêmes termes que le « Statut de la traduction », « en-cours » nommé « En cours de publication ».
 * Termes inconnus : leur nom.
 *
 * @return array<string,string>
 */
function yume_etats_oeuvre(): array {
	$libelles = array(
		'en-cours'   => __( 'En cours de publication', 'yume-core' ),
		'terminee'   => __( 'Terminée', 'yume-core' ),
		'en-pause'   => __( 'En pause', 'yume-core' ),
		'abandonnee' => __( 'Abandonnée', 'yume-core' ),
		'licenciee'  => __( 'Licenciée', 'yume-core' ),
	);
	$termes   = yume_statuts();
	return array_intersect_key( $libelles, $termes ) + array_diff_key( $termes, $libelles );
}

/**
 * États d'œuvre où le planning ne relance personne (ni rappel de retard, ni récapitulatif) :
 * en pause, abandonnée, licenciée, terminée.
 *
 * @param int $oeuvre_id Œuvre.
 */
function yume_oeuvre_sans_rappels( int $oeuvre_id ): bool {
	$etats = $oeuvre_id > 0 ? wp_get_object_terms( $oeuvre_id, 'yume_statut', array( 'fields' => 'slugs' ) ) : array();
	return is_array( $etats ) && (bool) array_intersect( $etats, array( 'en-pause', 'abandonnee', 'licenciee', 'terminee' ) );
}

/**
 * L'œuvre est-elle une « série à venir » (méta yume_serie_a_venir) ? Vrai seulement pour une
 * œuvre pas encore publiée (brouillon, en attente, programmée) : ses tomes paraissent alors au
 * planning public sous le nom de yume_titre_public_oeuvre(), sans titre, lien ni couverture.
 * Une œuvre publiée, privée ou à la corbeille n'est jamais « à venir ».
 *
 * @param int $oeuvre_id Œuvre.
 */
function yume_oeuvre_a_venir( int $oeuvre_id ): bool {
	if ( $oeuvre_id <= 0 || 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
		return false;
	}
	if ( ! in_array( get_post_status( $oeuvre_id ), array( 'draft', 'pending', 'future' ), true ) ) {
		return false;
	}
	return '1' === (string) get_post_meta( $oeuvre_id, 'yume_serie_a_venir', true );
}

/**
 * Titre d'une œuvre tel que le public peut le lire : le nom choisi (méta yume_libelle_a_venir,
 * « Nouvelle série à venir » par défaut) pour une série à venir (yume_oeuvre_a_venir()), sinon
 * son titre (sans balises ni entités).
 *
 * @param int $oeuvre_id Œuvre.
 */
function yume_titre_public_oeuvre( int $oeuvre_id ): string {
	if ( yume_oeuvre_a_venir( $oeuvre_id ) ) {
		$libelle = trim( (string) get_post_meta( $oeuvre_id, 'yume_libelle_a_venir', true ) );
		return '' !== $libelle ? $libelle : __( 'Nouvelle série à venir', 'yume-core' );
	}
	return $oeuvre_id > 0 ? trim( wp_strip_all_tags( html_entity_decode( (string) get_the_title( $oeuvre_id ), ENT_QUOTES, 'UTF-8' ) ) ) : '';
}

/**
 * Prochaine date de sortie selon le rythme du tome (méta yume_rythme), strictement après
 * $apres (défaut : maintenant), dans le fuseau du site ; null si le tome n'a pas de rythme.
 *
 * @param int                     $tome_id Tome.
 * @param \DateTimeImmutable|null $apres   Référence.
 */
function yume_prochaine_sortie_rythme( int $tome_id, ?\DateTimeImmutable $apres = null ): ?\DateTimeImmutable {
	$rythme = get_post_meta( $tome_id, 'yume_rythme', true );
	if ( ! is_array( $rythme ) || empty( $rythme['jour'] ) ) {
		return null;
	}
	$jours = array_keys( yume_jours_semaine() );
	$rang  = array_search( (string) $rythme['jour'], $jours, true );
	if ( false === $rang ) {
		return null;
	}
	$heure = preg_match( '/^(\d{2}):(\d{2})$/', (string) ( $rythme['heure'] ?? '' ), $m ) ? array( (int) $m[1], (int) $m[2] ) : array( 18, 0 );
	$apres = ( $apres ?? new \DateTimeImmutable( 'now', wp_timezone() ) )->setTimezone( wp_timezone() );
	$date  = $apres->setTime( $heure[0], $heure[1] );
	$ecart = ( (int) $rang + 1 - (int) $date->format( 'N' ) + 7 ) % 7;
	$date  = $date->modify( '+' . $ecart . ' days' );
	if ( $date <= $apres ) {
		$date = $date->modify( '+7 days' );
	}
	return $date;
}
