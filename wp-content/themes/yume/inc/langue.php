<?php
/**
 * Français quelle que soit la langue installée : le site Yume est francophone, mais une
 * installation en anglais (démonstration, Playground, langue non réglée) afficherait des
 * libellés du cœur en anglais à côté des modèles du thème, écrits en français.
 *
 * - titre du document (<title>) de la page 404 et des résultats de recherche ;
 * - noms des rôles du cœur (Administrateur, Éditeur…) si la langue n'est pas le français ;
 * - noms des mois et des jours des dates affichées en façade si la langue n'est pas le français.
 *
 * @package Yume
 */

defined( 'ABSPATH' ) || exit;

/**
 * La langue en cours (façade : langue du site ; administration : langue du compte) est-elle
 * le français (fr_FR, fr_BE, fr_CA…) ?
 *
 * @return bool
 */
function yume_theme_langue_francaise(): bool {
	return str_starts_with( determine_locale(), 'fr' );
}

/**
 * Titre du document de la page 404 et des résultats de recherche, en français et accordé au
 * titre de la page (« Page introuvable », « Résultats pour « terme » »).
 *
 * @param array<string,string> $parties Parties du titre.
 * @return array<string,string>
 */
function yume_theme_titre_document( $parties ) {
	if ( ! is_array( $parties ) ) {
		return $parties;
	}
	if ( is_404() ) {
		$parties['title'] = __( 'Page introuvable', 'yume' );
	} elseif ( is_search() ) {
		$terme            = get_search_query( false );
		$parties['title'] = '' === $terme
			? __( 'Recherche', 'yume' )
			/* translators: %s : termes recherchés. */
			: sprintf( __( 'Résultats pour « %s »', 'yume' ), $terme );
	}
	return $parties;
}
add_filter( 'document_title_parts', 'yume_theme_titre_document' );

/**
 * Noms français des rôles du cœur quand la langue n'est pas le français (translate_user_role()
 * passe par le contexte gettext « User role »). Une traduction déjà fournie (fichier de langue,
 * extension : « Lecteur » pour subscriber) est conservée.
 *
 * @param string $traduction Traduction.
 * @param string $texte      Texte original.
 * @param string $contexte   Contexte.
 * @param string $domaine    Domaine.
 * @return string
 */
function yume_theme_nom_role( $traduction, $texte, $contexte, $domaine ) {
	if ( 'User role' !== $contexte || 'default' !== $domaine || $traduction !== $texte ) {
		return $traduction;
	}
	$roles = array(
		'Administrator' => 'Administrateur',
		'Editor'        => 'Éditeur',
		'Author'        => 'Auteur',
		'Contributor'   => 'Contributeur',
		'Subscriber'    => 'Abonné',
		'Super Admin'   => 'Super administrateur',
	);
	if ( ! isset( $roles[ $texte ] ) || yume_theme_langue_francaise() ) {
		return $traduction;
	}
	return $roles[ $texte ];
}
add_filter( 'gettext_with_context', 'yume_theme_nom_role', 10, 4 );

/**
 * Dates en français en façade quand la langue n'est pas le français : les noms de mois et de
 * jours (formats F, M, l, D) de wp_date() — donc aussi date_i18n() et des blocs « Date de
 * l'article » — sont remplacés par leurs équivalents français (« 28 septembre 2026 »).
 *
 * @param string            $date      Date formatée.
 * @param string            $format    Format PHP.
 * @param int               $timestamp Horodatage Unix.
 * @param DateTimeZone|null $fuseau    Fuseau horaire.
 * @return string
 */
function yume_theme_date_francaise( $date, $format, $timestamp, $fuseau = null ) {
	if ( is_admin() || ! is_string( $format ) || ! preg_match( '/(?<!\\\\)[FMlD]/', $format ) || yume_theme_langue_francaise() ) {
		return $date;
	}
	$mois       = array( 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
	$mois_court = array( 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
	$jours      = array( 'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi' );
	$jour_court = array( 'dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.' );

	$moment  = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $fuseau instanceof DateTimeZone ? $fuseau : wp_timezone() );
	$n_mois  = (int) $moment->format( 'n' ) - 1;
	$n_jour  = (int) $moment->format( 'w' );
	$nouveau = '';
	$taille  = strlen( $format );
	for ( $i = 0; $i < $taille; $i++ ) {
		$car = $format[ $i ];
		if ( '\\' === $car ) {
			$nouveau .= $car . ( $format[ $i + 1 ] ?? '' );
			++$i;
			continue;
		}
		$remplace = array(
			'F' => $mois[ $n_mois ],
			'M' => $mois_court[ $n_mois ],
			'l' => $jours[ $n_jour ],
			'D' => $jour_court[ $n_jour ],
		);
		$nouveau .= isset( $remplace[ $car ] ) ? backslashit( $remplace[ $car ] ) : $car;
	}
	return $moment->format( $nouveau );
}
add_filter( 'wp_date', 'yume_theme_date_francaise', 10, 4 );
