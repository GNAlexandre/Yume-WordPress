<?php
/**
 * Composants HTML partagés par les blocs de la bibliothèque : couvertures, pastilles,
 * boutons de lecture et de téléchargement, fil d'Ariane, crédits.
 *
 * Toutes les valeurs sont échappées ici ; les fonctions renvoient du HTML sûr.
 * Couleurs : uniquement les classes du thème (§15).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Icône SVG décorative.
 *
 * @param string $nom    'externe', 'chevron', 'precedent', 'suivant', 'sommaire', 'livre'.
 * @param int    $taille Taille en pixels.
 */
function icone( string $nom, int $taille = 16 ): string {
	$chemins = array(
		'externe'   => '<path d="M14 4h6v6"></path><path d="M20 4 10 14"></path><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"></path>',
		'chevron'   => '<path d="m6 9 6 6 6-6"></path>',
		'precedent' => '<path d="m15 18-6-6 6-6"></path>',
		'suivant'   => '<path d="m9 18 6-6-6-6"></path>',
		'sommaire'  => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path>',
		'livre'     => '<path d="M4 19.5V5a2 2 0 0 1 2-2h14v16H6a2 2 0 0 0-2 2.5Z"></path><path d="M8 7h8"></path>',
	);
	if ( ! isset( $chemins[ $nom ] ) ) {
		return '';
	}
	return '<svg class="yn-icone yn-icone--' . esc_attr( $nom ) . '" width="' . (int) $taille . '" height="' . (int) $taille . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $chemins[ $nom ] . '</svg>';
}

/**
 * Pastille (.yn-chip) : texte, variante et icône décorative facultatives.
 *
 * @param string $texte    Texte visible.
 * @param string $variante '', 'ok', 'warn', 'err', 'info', 'new'.
 * @param string $icone    Caractère décoratif placé avant le texte (masqué aux lecteurs d'écran).
 * @param string $classe   Classes supplémentaires.
 */
function pastille( string $texte, string $variante = '', string $icone = '', string $classe = '' ): string {
	$classes = trim( 'yn-chip' . ( '' !== $variante ? ' yn-chip--' . $variante : '' ) . ' ' . $classe );
	$contenu = '' !== $icone ? '<span aria-hidden="true">' . esc_html( $icone ) . '</span> ' : '';
	return '<span class="' . esc_attr( $classes ) . '">' . $contenu . esc_html( $texte ) . '</span>';
}

/**
 * Pastille de statut de traduction (« ● En cours »).
 *
 * @param string $slug   Slug du statut.
 * @param string $nom    Libellé.
 * @param string $classe Classes supplémentaires.
 */
function pastille_statut( string $slug, string $nom, string $classe = '' ): string {
	$apparence = apparence_statut( $slug );
	return pastille( $nom, $apparence['variante'], $apparence['icone'], trim( 'yn-statut yn-statut--' . sanitize_html_class( $slug ) . ' ' . $classe ) );
}

/**
 * Couverture 2:3 (.yn-cover) : image de la médiathèque ou dégradé de substitution du thème
 * portant le titre.
 *
 * @param int   $image_id ID de la pièce jointe (0 : substitution).
 * @param array $options  alt (texte alternatif), texte (substitution), badge (HTML sûr),
 *                        classe, taille, sizes, chargement ('lazy'|'eager'), priorite (bool).
 */
function couverture( int $image_id, array $options = array() ): string {
	$o   = wp_parse_args(
		$options,
		array(
			'alt'        => '',
			'texte'      => '',
			'badge'      => '',
			'classe'     => '',
			'taille'     => 'yume-couverture',
			'sizes'      => '',
			'chargement' => 'lazy',
			'priorite'   => false,
		)
	);
	$img = '';
	if ( $image_id > 0 && wp_attachment_is_image( $image_id ) ) {
		$taille = has_image_size( (string) $o['taille'] ) || in_array( $o['taille'], array( 'thumbnail', 'medium', 'medium_large', 'large', 'full' ), true ) ? (string) $o['taille'] : 'medium_large';
		$attrs  = array(
			'class'    => 'yn-cover__image',
			'alt'      => (string) $o['alt'],
			'loading'  => 'eager' === $o['chargement'] ? 'eager' : 'lazy',
			'decoding' => 'async',
		);
		if ( '' !== (string) $o['sizes'] ) {
			$attrs['sizes'] = (string) $o['sizes'];
		}
		if ( $o['priorite'] ) {
			$attrs['fetchpriority'] = 'high';
		}
		$img = yume_image_couverture( $image_id, $taille, $attrs );
	}
	$contenu = (string) $o['badge'];
	if ( '' !== $img ) {
		$contenu .= $img;
		$classe   = 'yn-cover yn-cover--image';
	} else {
		$contenu .= '<span class="yn-cover__texte" aria-hidden="true">' . esc_html( (string) $o['texte'] ) . '</span>';
		$classe   = 'yn-cover yn-cover--substitution';
	}
	return '<span class="' . esc_attr( trim( $classe . ' ' . $o['classe'] ) ) . '">' . $contenu . '</span>';
}

/**
 * Texte alternatif d'une couverture : celui de la pièce jointe s'il existe, sinon
 * « Couverture : {titre} ».
 *
 * @param int    $image_id ID de la pièce jointe.
 * @param string $titre    Titre décrit.
 */
function alt_couverture( int $image_id, string $titre ): string {
	$alt = $image_id ? trim( wp_strip_all_tags( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) ) : '';
	/* translators: %s : titre de l'œuvre ou du tome. */
	return '' !== $alt ? $alt : sprintf( __( 'Couverture : %s', 'yume-core' ), $titre );
}

/**
 * Attributs d'un lien : nouvel onglet et rel="noopener" pour un lien externe.
 *
 * @param string $url Adresse.
 */
function attributs_lien( string $url ): string {
	return est_externe( $url ) ? ' target="_blank" rel="noopener"' : '';
}

/**
 * Indication d'un lien externe : icône visible et texte pour les lecteurs d'écran.
 *
 * @param string $url Adresse.
 */
function indication_externe( string $url ): string {
	if ( ! est_externe( $url ) ) {
		return '';
	}
	return icone( 'externe', 12 ) . '<span class="yn-visually-hidden">' . esc_html__( '(lien externe, nouvel onglet)', 'yume-core' ) . '</span>';
}

/**
 * Boutons de téléchargement PDF et EPUB d'un tome (absents si le lien est vide).
 *
 * @param int    $tome_id  ID du tome.
 * @param string $contexte Précision pour les lecteurs d'écran (« Grimgar, Tome 9 »).
 * @param bool   $petit    Boutons compacts (.yn-btn--sm).
 */
function boutons_telechargement( int $tome_id, string $contexte, bool $petit = true ): string {
	if ( ! function_exists( 'yume_liens_telechargement' ) ) {
		return '';
	}
	$liens = yume_liens_telechargement( $tome_id );
	$html  = '';
	foreach ( array(
		'pdf'  => __( 'PDF', 'yume-core' ),
		'epub' => __( 'EPUB', 'yume-core' ),
	) as $format => $libelle ) {
		$url = isset( $liens[ $format ] ) ? (string) $liens[ $format ] : '';
		if ( ! est_url_http( $url ) ) {
			continue;
		}
		$html .= sprintf(
			'<a class="%1$s" href="%2$s"%3$s>%4$s<span class="yn-visually-hidden"> — %5$s</span>%6$s</a>',
			esc_attr( 'yn-btn' . ( $petit ? ' yn-btn--sm' : '' ) . ' yn-telechargement yn-telechargement--' . $format ),
			esc_url( $url ),
			attributs_lien( $url ),
			esc_html( $libelle ),
			esc_html( $contexte ),
			indication_externe( $url )
		);
	}
	return $html;
}

/**
 * Bouton de lecture en ligne.
 *
 * @param int    $chapitre_id Chapitre ouvert par le bouton.
 * @param string $texte       Texte visible (« Lire », « Lire en ligne »).
 * @param string $precision   Précision pour les lecteurs d'écran.
 * @param bool   $petit       Bouton compact.
 */
function bouton_lire( int $chapitre_id, string $texte, string $precision = '', bool $petit = true ): string {
	$url = $chapitre_id ? lien_public( $chapitre_id ) : '';
	return '' !== $url ? bouton_lire_url( $url, $texte, $precision, $petit ) : '';
}

/**
 * Bouton de lecture en ligne vers une adresse donnée.
 *
 * @param string               $url       Adresse.
 * @param string               $texte     Texte visible.
 * @param string               $precision Précision pour les lecteurs d'écran.
 * @param bool                 $petit     Bouton compact.
 * @param array<string,string> $donnees   Attributs data-* supplémentaires (nom => valeur).
 */
function bouton_lire_url( string $url, string $texte, string $precision = '', bool $petit = true, array $donnees = array() ): string {
	$extra = '';
	foreach ( $donnees as $nom => $valeur ) {
		$extra .= ' ' . esc_attr( (string) $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return sprintf(
		'<a class="%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
		esc_attr( 'yn-btn yn-btn--primary' . ( $petit ? ' yn-btn--sm' : '' ) . ' yn-lire' ),
		esc_url( $url ),
		$extra,
		esc_html( $texte ),
		'' !== $precision ? '<span class="yn-visually-hidden"> — ' . esc_html( $precision ) . '</span>' : ''
	);
}

/**
 * Fil d'Ariane accessible (le dernier élément est la page courante).
 *
 * @param array<int,array{texte:string,url?:string}> $elements Éléments, du plus général au courant.
 * @param string                                     $classe   Classe supplémentaire.
 */
function fil_ariane( array $elements, string $classe = '' ): string {
	$elements = array_values(
		array_filter(
			$elements,
			static fn( $e ): bool => is_array( $e ) && '' !== trim( (string) ( $e['texte'] ?? '' ) )
		)
	);
	if ( count( $elements ) < 2 ) {
		return '';
	}
	$dernier = count( $elements ) - 1;
	$items   = '';
	foreach ( $elements as $i => $element ) {
		$texte = esc_html( (string) $element['texte'] );
		$url   = (string) ( $element['url'] ?? '' );
		if ( $i === $dernier ) {
			$items .= '<li class="yn-ariane__item" aria-current="page"><span>' . $texte . '</span></li>';
		} elseif ( '' !== $url ) {
			$items .= '<li class="yn-ariane__item"><a href="' . esc_url( $url ) . '">' . $texte . '</a></li>';
		} else {
			$items .= '<li class="yn-ariane__item"><span>' . $texte . '</span></li>';
		}
	}
	return '<nav class="' . esc_attr( trim( 'yn-ariane ' . $classe ) ) . '" aria-label="' . esc_attr__( 'Fil d’Ariane', 'yume-core' ) . '"><ol class="yn-ariane__liste">' . $items . '</ol></nav>';
}

/**
 * Rôles des crédits (clé => libellé).
 *
 * @return array<string,string>
 */
function roles_credits(): array {
	return array(
		'traduction' => __( 'Traduction', 'yume-core' ),
		'relecture'  => __( 'Relecture', 'yume-core' ),
		'edition'    => __( 'Édition', 'yume-core' ),
	);
}

/**
 * Crédits non vides d'une métadonnée {traduction, relecture, edition}.
 *
 * @param int    $post_id ID.
 * @param string $cle     yume_credits (tome, chapitre) ou yume_equipe (œuvre).
 * @return array<string,string> rôle => nom(s).
 */
function credits( int $post_id, string $cle = 'yume_credits' ): array {
	$brut    = $post_id ? get_post_meta( $post_id, $cle, true ) : array();
	$brut    = is_array( $brut ) ? $brut : array();
	$credits = array();
	foreach ( array_keys( roles_credits() ) as $role ) {
		$nom = isset( $brut[ $role ] ) && is_scalar( $brut[ $role ] ) ? trim( wp_strip_all_tags( (string) $brut[ $role ] ) ) : '';
		if ( '' !== $nom ) {
			$credits[ $role ] = $nom;
		}
	}
	return $credits;
}

/**
 * Crédits d'un chapitre : les siens, sinon ceux de son tome, sinon l'équipe de l'œuvre.
 *
 * @param int $chapitre_id ID du chapitre (ou d'un tome).
 * @return array<string,string>
 */
function credits_herites( int $chapitre_id ): array {
	$credits = credits( $chapitre_id );
	if ( $credits ) {
		return $credits;
	}
	$tome_id = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $chapitre_id ) : 0;
	if ( $tome_id && $tome_id !== $chapitre_id ) {
		$credits = credits( $tome_id );
		if ( $credits ) {
			return $credits;
		}
	}
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $chapitre_id ) : 0;
	return $oeuvre_id ? credits( $oeuvre_id, 'yume_equipe' ) : array();
}

/**
 * Crédits en ligne : « Traduction · Angeloids — Relecture · Calumi ».
 *
 * @param array<string,string> $credits Crédits.
 */
function credits_en_ligne( array $credits ): string {
	$roles   = roles_credits();
	$parties = array();
	foreach ( $credits as $role => $nom ) {
		$parties[] = '<span class="yn-credits__item"><span class="yn-credits__role">' . esc_html( $roles[ $role ] ?? $role ) . '</span> · <span class="yn-credits__nom">' . esc_html( $nom ) . '</span></span>';
	}
	// Espace insécable avant le tiret : une ligne ne commence jamais par « — ».
	return implode( '<span class="yn-credits__sep" aria-hidden="true">&nbsp;— </span><span class="yn-visually-hidden">, </span>', $parties );
}

/**
 * Résumé des tomes publiés d'une œuvre : « 9 tomes », « 7 arcs, 1 tome EX ».
 *
 * @param array<string,int> $natures nature => nombre.
 */
function resume_tomes( array $natures ): string {
	$formes = array(
		/* translators: %s : nombre. */
		'tome'      => array( __( '%s tome', 'yume-core' ), __( '%s tomes', 'yume-core' ) ),
		/* translators: %s : nombre. */
		'arc'       => array( __( '%s arc', 'yume-core' ), __( '%s arcs', 'yume-core' ) ),
		/* translators: %s : nombre. */
		'ex'        => array( __( '%s tome EX', 'yume-core' ), __( '%s tomes EX', 'yume-core' ) ),
		/* translators: %s : nombre. */
		'bonus'     => array( __( '%s bonus', 'yume-core' ), __( '%s bonus', 'yume-core' ) ),
		/* translators: %s : nombre. */
		'chapitres' => array( __( '%s recueil de chapitres', 'yume-core' ), __( '%s recueils de chapitres', 'yume-core' ) ),
	);
	$parties = array();
	foreach ( $formes as $nature => $forme ) {
		$nombre = (int) ( $natures[ $nature ] ?? 0 );
		if ( $nombre > 0 ) {
			$parties[] = sprintf( $nombre > 1 ? $forme[1] : $forme[0], nombre_fr( $nombre ) );
		}
	}
	foreach ( $natures as $nature => $nombre ) {
		if ( ! isset( $formes[ $nature ] ) && (int) $nombre > 0 ) {
			/* translators: %s : nombre. */
			$parties[] = sprintf( (int) $nombre > 1 ? __( '%s volumes', 'yume-core' ) : __( '%s volume', 'yume-core' ), nombre_fr( (int) $nombre ) );
		}
	}
	return implode( ', ', $parties );
}

/**
 * Résumé des chapitres publiés d'un tome : « 19 chapitres + postface ».
 *
 * @param array $stats Statistiques du tome (stats_tome()).
 */
function resume_chapitres( array $stats ): string {
	$nombre = (int) ( $stats['chapitres'] ?? 0 );
	$texte  = '';
	if ( $nombre > 0 ) {
		/* translators: %s : nombre de chapitres. */
		$texte = sprintf( $nombre > 1 ? __( '%s chapitres', 'yume-core' ) : __( '%s chapitre', 'yume-core' ), nombre_fr( $nombre ) );
	}
	$natures = function_exists( 'yume_natures_chapitre' ) ? yume_natures_chapitre() : array();
	foreach ( (array) ( $stats['speciaux'] ?? array() ) as $nature => $combien ) {
		$nom = mb_strtolower( (string) ( $natures[ $nature ] ?? $nature ) );
		if ( (int) $combien > 1 ) {
			$nom = nombre_fr( (int) $combien ) . ' ' . $nom . ( str_ends_with( $nom, 's' ) ? '' : 's' );
		}
		$texte = '' === $texte ? mb_strtoupper( mb_substr( $nom, 0, 1 ) ) . mb_substr( $nom, 1 ) : $texte . ' + ' . $nom;
	}
	return $texte;
}

/**
 * Nombre de mots : « 84 000 mots ».
 *
 * @param int $mots Nombre de mots.
 */
function texte_mots( int $mots ): string {
	if ( $mots <= 0 ) {
		return '';
	}
	/* translators: %s : nombre de mots. */
	return sprintf( $mots > 1 ? __( '%s mots', 'yume-core' ) : __( '%s mot', 'yume-core' ), nombre_fr( $mots ) );
}
