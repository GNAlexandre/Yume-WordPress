<?php
/**
 * Page publique du glossaire : /oeuvres/{oeuvre}/glossaire/ (sous-page déclarée par
 * yume_sous_pages_oeuvre, gabarit de thème single-yume_oeuvre-glossaire.html), onglet
 * « Glossaire » de la fiche (yume_onglets_oeuvre) seulement si l'œuvre en a un, 404 sinon ;
 * balises (titre, description, adresse canonique, noindex sous SEUIL_INDEXATION entrées
 * publiques) ; bloc dynamique yume/glossaire.
 *
 * Visiteurs : aucun champ interne (provenance, preuve, confiance, interdits, force, variantes) ;
 * les entrées sans traduction française (hors « traduire: false ») sont masquées. Équipe
 * (yume_voir_equipe) : « Afficher les notes de traduction » (?notes=1, ou bascule en place par
 * view.js) révèle ces champs et les entrées « à définir ».
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

defined( 'ABSPATH' ) || exit;

/** Slug de la sous-page. */
const ONGLET = 'glossaire';

/*
 * -----------------------------------------------------------------------------
 * Sous-page, onglet, 404
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare la sous-page /oeuvres/{oeuvre}/glossaire/ (filtre posé au chargement, avant init).
 *
 * @param string[] $slugs Slugs.
 * @return string[]
 */
function declarer_sous_page( $slugs ) {
	$slugs   = is_array( $slugs ) ? $slugs : array();
	$slugs[] = ONGLET;
	return $slugs;
}
add_filter( 'yume_sous_pages_oeuvre', __NAMESPACE__ . '\\declarer_sous_page' );

/**
 * Onglet « Glossaire » de la fiche, seulement si la page existe pour ce visiteur.
 *
 * @param array $onglets   slug => array{libelle, url}.
 * @param int   $oeuvre_id Œuvre.
 * @return array
 */
function ajouter_onglet( $onglets, $oeuvre_id = 0 ) {
	$onglets   = is_array( $onglets ) ? $onglets : array();
	$oeuvre_id = (int) $oeuvre_id;
	if ( $oeuvre_id <= 0 || ! glossaire_visible( $oeuvre_id ) ) {
		return $onglets;
	}
	$url = url_glossaire( $oeuvre_id );
	if ( '' !== $url ) {
		$onglets[ ONGLET ] = array(
			'libelle' => __( 'Glossaire', 'yume-core' ),
			'url'     => $url,
		);
	}
	return $onglets;
}
add_filter( 'yume_onglets_oeuvre', __NAMESPACE__ . '\\ajouter_onglet', 30, 2 );

/**
 * Œuvre dont la requête principale affiche le glossaire, ou 0.
 */
function oeuvre_page_glossaire(): int {
	if ( ! function_exists( '\\Yume\\Core\\Core\\onglet_oeuvre' ) || ONGLET !== \Yume\Core\Core\onglet_oeuvre() ) {
		return 0;
	}
	return (int) get_queried_object_id();
}

/**
 * Glossaire absent (ou sans entrée visible pour ce visiteur) : 404.
 */
function page_introuvable(): void {
	$oeuvre_id = oeuvre_page_glossaire();
	if ( ! $oeuvre_id || glossaire_visible( $oeuvre_id ) ) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', __NAMESPACE__ . '\\page_introuvable', 5 );

/*
 * -----------------------------------------------------------------------------
 * Balises : titre, description, canonique, robots
 * -----------------------------------------------------------------------------
 */

/**
 * Titre du document : « Glossaire — Œuvre ».
 *
 * @param array<string,string> $parties Parties du titre.
 * @return array<string,string>
 */
function titre_document( $parties ) {
	$oeuvre_id = oeuvre_page_glossaire();
	if ( ! is_array( $parties ) || ! $oeuvre_id || is_404() ) {
		return $parties;
	}
	/* translators: %s : titre de l'œuvre */
	$parties['title'] = sprintf( __( 'Glossaire — %s', 'yume-core' ), titre_oeuvre( $oeuvre_id ) );
	return $parties;
}
add_filter( 'document_title_parts', __NAMESPACE__ . '\\titre_document' );

/**
 * Adresse canonique : celle du glossaire, pas celle de la fiche.
 *
 * @param string   $url  Adresse canonique calculée.
 * @param \WP_Post $post Contenu.
 * @return string
 */
function canonique( $url, $post = null ) {
	$oeuvre_id = oeuvre_page_glossaire();
	if ( ! $oeuvre_id || ! $post instanceof \WP_Post || (int) $post->ID !== $oeuvre_id ) {
		return $url;
	}
	$glossaire = url_glossaire( $oeuvre_id );
	return '' !== $glossaire ? $glossaire : $url;
}
add_filter( 'get_canonical_url', __NAMESPACE__ . '\\canonique', 10, 2 );

/**
 * Robots : noindex, follow si moins de SEUIL_INDEXATION entrées publiques.
 *
 * @param array<string,bool|string> $robots Directives.
 * @return array<string,bool|string>
 */
function robots( $robots ) {
	$oeuvre_id = oeuvre_page_glossaire();
	if ( ! is_array( $robots ) || ! $oeuvre_id ) {
		return $robots;
	}
	$etat = etat_glossaire( $oeuvre_id );
	if ( $etat['publiques'] + $etat['anglicismes'] < SEUIL_INDEXATION ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );
	}
	return $robots;
}
add_filter( 'wp_robots', __NAMESPACE__ . '\\robots' );

/**
 * Description et Open Graph de la page (filtre yume_open_graph du module bibliothèque).
 *
 * @param array<string,string> $balises Balises.
 * @param int                  $post_id Contenu affiché.
 * @return array<string,string>
 */
function balises_partage( $balises, $post_id = 0 ) {
	$oeuvre_id = oeuvre_page_glossaire();
	if ( ! is_array( $balises ) || ! $balises || ! $oeuvre_id || (int) $post_id !== $oeuvre_id ) {
		return $balises;
	}
	$etat  = etat_glossaire( $oeuvre_id );
	$titre = titre_oeuvre( $oeuvre_id );
	$texte = sprintf(
		/* translators: 1: titre de l'œuvre, 2: nombre d'entrées publiques */
		_n(
			'Glossaire de « %1$s » : personnages, lieux et termes de l’univers, avec leur nom original et leur traduction française (%2$d entrée).',
			'Glossaire de « %1$s » : personnages, lieux et termes de l’univers, avec leur nom original et leur traduction française (%2$d entrées).',
			max( 1, $etat['publiques'] ),
			'yume-core'
		),
		$titre,
		$etat['publiques']
	);
	$balises['description']    = $texte;
	$balises['og:description'] = $texte;
	/* translators: %s : titre de l'œuvre */
	$balises['og:title'] = sprintf( __( 'Glossaire — %s', 'yume-core' ), $titre );
	$balises['og:type']  = 'website';
	$balises['og:url']   = url_glossaire( $oeuvre_id );
	return $balises;
}
add_filter( 'yume_open_graph', __NAMESPACE__ . '\\balises_partage', 10, 2 );

/*
 * -----------------------------------------------------------------------------
 * Bloc yume/glossaire
 * -----------------------------------------------------------------------------
 */

/**
 * Enregistre le bloc.
 */
function enregistrer_bloc(): void {
	if ( function_exists( 'yume_register_dynamic_block' ) ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/glossaire' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_bloc' );

/**
 * Œuvre du contexte du bloc (postId, sinon objet de la requête), ou 0.
 *
 * @param \WP_Block|null $bloc Bloc.
 */
function oeuvre_du_bloc( $bloc ): int {
	if ( function_exists( '\\Yume\\Core\\Library\\oeuvre_contexte' ) ) {
		return (int) \Yume\Core\Library\oeuvre_contexte( $bloc );
	}
	$id = $bloc instanceof \WP_Block && ! empty( $bloc->context['postId'] ) ? (int) $bloc->context['postId'] : (int) get_queried_object_id();
	return 'yume_oeuvre' === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? $id : 0;
}

/**
 * Les notes de traduction sont-elles affichées au chargement (?notes=1, équipe seulement) ?
 */
function notes_demandees(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix d'affichage en lecture seule.
	return isset( $_GET['notes'] ) && '1' === $_GET['notes'] && current_user_can( 'yume_voir_equipe' );
}

/**
 * Entrées regroupées par catégorie (ordre d'affichage), triées par nom dans chaque catégorie.
 *
 * @param array $entrees Entrées (lire_entrees()).
 * @return array<string,array>
 */
function par_categorie( array $entrees ): array {
	$groupes = array();
	foreach ( $entrees as $e ) {
		$groupes[ $e['categorie'] ][] = $e;
	}
	uksort( $groupes, __NAMESPACE__ . '\\comparer_categories' );
	foreach ( $groupes as $cle => &$liste ) {
		if ( 'anglicismes' === $cle ) {
			continue;
		}
		usort(
			$liste,
			static function ( array $a, array $b ): int {
				return strnatcasecmp( normaliser_recherche( $a['nom'] ), normaliser_recherche( $b['nom'] ) );
			}
		);
	}
	unset( $liste );
	return $groupes;
}

/**
 * Attribut lang d'un terme original : « ja » s'il contient du japonais, sinon la langue source
 * de l'entrée (graphies refusées) ou l'anglais pour un terme en alphabet latin.
 *
 * @param string $texte  Terme.
 * @param string $langue Langue source déclarée ('' : inconnue).
 */
function attribut_lang( string $texte, string $langue = '' ): string {
	if ( est_japonais( $texte ) ) {
		return ' lang="ja"';
	}
	if ( preg_match( '/[A-Za-z]/', $texte ) ) {
		return ' lang="' . esc_attr( '' !== $langue ? $langue : 'en' ) . '"';
	}
	return '';
}

/**
 * Paragraphes d'un texte multiligne (échappés).
 *
 * @param string $texte  Texte.
 * @param string $classe Classe des paragraphes.
 */
function paragraphes( string $texte, string $classe ): string {
	$html = '';
	foreach ( preg_split( '/\n{2,}/', $texte ) as $bloc ) {
		$bloc = trim( (string) $bloc );
		if ( '' !== $bloc ) {
			$html .= '<p class="' . esc_attr( $classe ) . '">' . nl2br( esc_html( $bloc ), false ) . '</p>';
		}
	}
	return $html;
}

/**
 * Notes de traduction d'une entrée (équipe) : variantes, interdits, force, provenance,
 * confiance, preuve.
 *
 * @param array $d       Données de l'entrée.
 * @param bool  $visible Affichées au chargement.
 */
function notes_entree( array $d, bool $visible ): string {
	$lignes = array();
	if ( ! empty( $d['variantes'] ) ) {
		$lignes[ __( 'Variantes', 'yume-core' ) ] = esc_html( implode( ', ', (array) $d['variantes'] ) );
	}
	if ( ! empty( $d['interdits'] ) ) {
		$lignes[ __( 'Formes interdites', 'yume-core' ) ] = implode( ', ', array_map( static fn( $t ): string => '<del>' . esc_html( (string) $t ) . '</del>', (array) $d['interdits'] ) );
	}
	if ( ! empty( $d['force'] ) ) {
		$lignes[ __( 'Forme', 'yume-core' ) ] = '<span class="yn-chip yn-chip--warn">' . esc_html__( 'imposé', 'yume-core' ) . '</span>';
	}
	$lignes[ __( 'Provenance', 'yume-core' ) ] = esc_html( (string) ( $d['provenance'] ?? 'inconnue' ) );
	if ( ! empty( $d['confiance'] ) ) {
		$libelles                                 = array(
			'sure'      => __( 'sûre', 'yume-core' ),
			'probable'  => __( 'probable', 'yume-core' ),
			'hypothese' => __( 'hypothèse', 'yume-core' ),
		);
		$lignes[ __( 'Confiance', 'yume-core' ) ] = esc_html( $libelles[ $d['confiance'] ] ?? (string) $d['confiance'] );
	}
	foreach ( (array) ( $d['refusees'] ?? array() ) as $langue => $graphies ) {
		/* translators: %s : code de langue (en, ja…) */
		$lignes[ sprintf( __( 'Graphies refusées (%s)', 'yume-core' ), strtoupper( (string) $langue ) ) ] = implode( ', ', array_map( static fn( $t ): string => '<span' . attribut_lang( (string) $t, (string) $langue ) . '>' . esc_html( (string) $t ) . '</span>', (array) $graphies ) );
	}
	if ( ! empty( $d['preuve'] ) ) {
		$lignes[ __( 'Preuve', 'yume-core' ) ] = nl2br( esc_html( (string) $d['preuve'] ), false );
	}
	$html = '<dl class="yn-glossaire__notes" data-yn-glossaire-note' . ( $visible ? '' : ' hidden' ) . '>';
	foreach ( $lignes as $terme => $valeur ) {
		$html .= '<div><dt>' . esc_html( $terme ) . '</dt><dd>' . $valeur . '</dd></div>';
	}
	return $html . '</dl>';
}

/**
 * Carte d'une entrée.
 *
 * @param array $e      Entrée (lire_entrees()).
 * @param bool  $equipe Visiteur de l'équipe.
 * @param bool  $notes  Notes affichées au chargement.
 */
function carte_entree( array $e, bool $equipe, bool $notes ): string {
	$d        = $e['donnees'];
	$public   = ! empty( $d['public'] );
	$source   = array_values( array_filter( array_map( 'strval', (array) ( $d['termes_source'] ?? array() ) ) ) );
	$conserve = false === ( $d['traduire'] ?? null );
	$spoiler  = ! empty( $d['spoiler'] );
	$tome     = (int) ( $d['tome'] ?? 0 );
	$nom      = (string) $e['nom'];

	$recherche = normaliser_recherche( $nom, (string) ( $d['pluriel'] ?? '' ), implode( ' ', $source ), $spoiler ? '' : (string) ( $d['role'] ?? '' ) . ' ' . (string) ( $d['description'] ?? '' ) );
	if ( $equipe ) {
		$recherche = normaliser_recherche( $recherche, implode( ' ', (array) ( $d['variantes'] ?? array() ) ) );
	}
	$attrs = ' class="yn-card yn-glossaire__entree' . ( $public ? '' : ' yn-glossaire__entree--a-definir' ) . '" data-yn-recherche="' . esc_attr( $recherche ) . '"';
	if ( ! $public ) {
		// Entrée sans traduction : visible seulement avec les notes de traduction.
		$attrs .= ' data-yn-glossaire-note' . ( $notes ? '' : ' hidden' );
	}
	$html   = '<li' . $attrs . '><div class="yn-glossaire__entete">';
	$langue = (string) ( $d['langue_source'] ?? '' );
	$html  .= '<h3 class="yn-glossaire__nom"' . ( '' === (string) ( $d['nom_fr'] ?? '' ) ? attribut_lang( $nom, $langue ) : '' ) . '>' . esc_html( $nom ) . '</h3>';
	if ( $conserve ) {
		$html .= '<span class="yn-chip yn-chip--info" title="' . esc_attr__( 'Nom gardé tel quel dans la traduction', 'yume-core' ) . '">' . esc_html__( 'Nom conservé', 'yume-core' ) . '</span>';
	}
	if ( ! $public ) {
		$html .= '<span class="yn-chip yn-chip--warn">' . esc_html__( 'À définir', 'yume-core' ) . '</span>';
	}
	$html .= '</div>';

	// Terme original, sauf s'il ne diffère du nom affiché que par la casse ou les accents
	// (« SABER » pour « Saber »).
	$cle_nom = normaliser_recherche( $nom );
	$vo      = array_values(
		array_filter(
			$source,
			static fn( string $t ): bool => normaliser_recherche( $t ) !== $cle_nom
		)
	);
	if ( $vo ) {
		$html .= '<p class="yn-glossaire__vo"><span class="yn-visually-hidden">' . esc_html( _n( 'Terme original :', 'Termes originaux :', count( $vo ), 'yume-core' ) ) . ' </span>';
		$html .= implode( '<span aria-hidden="true"> · </span>', array_map( static fn( string $t ): string => '<span' . attribut_lang( $t, $langue ) . '>' . esc_html( $t ) . '</span>', $vo ) ) . '</p>';
	}

	$gram = array();
	if ( '' !== (string) ( $d['genre'] ?? '' ) ) {
		$gram[] = (string) $d['genre'];
	}
	if ( '' !== (string) ( $d['pluriel'] ?? '' ) ) {
		/* translators: %s : forme du pluriel */
		$gram[] = sprintf( __( 'pluriel : %s', 'yume-core' ), (string) $d['pluriel'] );
	}
	if ( $tome > 0 && ! $spoiler ) {
		/* translators: %d : numéro du tome */
		$gram[] = sprintf( __( 'dès le tome %d', 'yume-core' ), $tome );
	}
	if ( $gram ) {
		$html .= '<p class="yn-glossaire__gram yn-muted">' . esc_html( implode( ' · ', $gram ) ) . '</p>';
	}

	$corps = '';
	if ( '' !== (string) ( $d['role'] ?? '' ) ) {
		$corps .= '<p class="yn-glossaire__role">' . esc_html( (string) $d['role'] ) . '</p>';
	}
	$corps .= paragraphes( (string) ( $d['description'] ?? '' ), 'yn-glossaire__description' );
	if ( '' !== $corps ) {
		if ( $spoiler ) {
			$resume = $tome > 0
				/* translators: %d : numéro du tome */
				? sprintf( __( 'Révéler (spoiler, tome %d)', 'yume-core' ), $tome )
				: __( 'Révéler (spoiler)', 'yume-core' );
			$html .= '<details class="yn-glossaire__spoiler"><summary>' . esc_html( $resume ) . '</summary>' . $corps . '</details>';
		} else {
			$html .= $corps;
		}
	}
	if ( $equipe ) {
		$html .= notes_entree( $d, $notes );
	}
	return $html . '</li>';
}

/**
 * Section des anglicismes (tableau VO → FR).
 *
 * @param string $id      Identifiant de la section.
 * @param array  $entrees Anglicismes.
 */
function section_anglicismes( string $id, array $entrees ): string {
	$html  = '<section class="yn-glossaire__categorie" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-titre" data-yn-categorie="anglicismes">';
	$html .= '<h2 id="' . esc_attr( $id ) . '-titre">' . esc_html( libelle_categorie( 'anglicismes' ) ) . '</h2>';
	$html .= '<div class="yn-glossaire__tableau" tabindex="0" role="region" aria-labelledby="' . esc_attr( $id ) . '-legende"><table>';
	$html .= '<caption id="' . esc_attr( $id ) . '-legende">' . esc_html__( 'Expressions en anglais dans le texte original et leur traduction française', 'yume-core' ) . '</caption>';
	$html .= '<thead><tr><th scope="col">' . esc_html__( 'Version originale', 'yume-core' ) . '</th><th scope="col">' . esc_html__( 'Traduction', 'yume-core' ) . '</th></tr></thead><tbody>';
	foreach ( $entrees as $e ) {
		$vo    = (string) ( $e['donnees']['vo'] ?? '' );
		$fr    = (string) ( $e['donnees']['fr'] ?? $e['nom'] );
		$html .= '<tr data-yn-recherche="' . esc_attr( normaliser_recherche( $vo, $fr ) ) . '"><td lang="en">' . esc_html( $vo ) . '</td><td>' . esc_html( $fr ) . '</td></tr>';
	}
	return $html . '</tbody></table></div></section>';
}

/**
 * Rendu du bloc yume/glossaire.
 *
 * @param array          $attributs Attributs (aucun).
 * @param \WP_Block|null $bloc      Bloc.
 */
function rendu_glossaire( array $attributs = array(), $bloc = null ): string {
	unset( $attributs );
	$oeuvre_id = oeuvre_du_bloc( $bloc );
	$apercu    = function_exists( '\\Yume\\Core\\Library\\apercu_editeur' ) && \Yume\Core\Library\apercu_editeur();
	if ( ! $oeuvre_id ) {
		return $apercu ? '<div class="yn-glossaire yn-apercu-editeur"><p class="yn-muted">' . esc_html__( 'Glossaire : visible sur la page Glossaire d’une œuvre.', 'yume-core' ) . '</p></div>' : '';
	}
	$equipe  = current_user_can( 'yume_voir_equipe' );
	$notes   = $equipe && notes_demandees();
	$groupes = par_categorie( lire_entrees( $oeuvre_id ) );
	$etat    = etat_glossaire( $oeuvre_id );
	if ( ! $groupes || ( ! $equipe && $etat['publiques'] + $etat['anglicismes'] <= 0 ) ) {
		return $apercu ? '<div class="yn-glossaire yn-apercu-editeur"><p class="yn-muted">' . esc_html__( 'Cette œuvre n’a pas encore de glossaire.', 'yume-core' ) . '</p></div>' : '';
	}

	// Sections (entrées visibles de chaque catégorie).
	$sections = '';
	$sommaire = '';
	$options  = '';
	foreach ( $groupes as $cle => $entrees ) {
		$id = 'yn-glossaire-' . $cle;
		if ( 'anglicismes' === $cle ) {
			$nb        = count( $entrees );
			$sections .= section_anglicismes( $id, $entrees );
		} else {
			$publiques = array_filter( $entrees, static fn( array $e ): bool => ! empty( $e['donnees']['public'] ) );
			$nb        = count( $publiques );
			if ( ! $equipe && ! $nb ) {
				continue;
			}
			$sections .= '<section class="yn-glossaire__categorie" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-titre" data-yn-categorie="' . esc_attr( $cle ) . '"' . ( ! $nb && ! $notes ? ' data-yn-glossaire-note hidden' : ( ! $nb ? ' data-yn-glossaire-note' : '' ) ) . '>';
			$sections .= '<h2 id="' . esc_attr( $id ) . '-titre">' . esc_html( libelle_categorie( $cle ) ) . '</h2><ul class="yn-glossaire__entrees" role="list">';
			foreach ( ( $equipe ? $entrees : $publiques ) as $e ) {
				$sections .= carte_entree( $e, $equipe, $notes );
			}
			$sections .= '</ul></section>';
		}
		if ( $nb ) {
			$sommaire .= '<li><a href="#' . esc_attr( $id ) . '">' . esc_html( libelle_categorie( $cle ) ) . ' <span class="yn-glossaire__nb">' . (int) $nb . '</span></a></li>';
			$options  .= '<option value="' . esc_attr( $cle ) . '">' . esc_html( libelle_categorie( $cle ) ) . '</option>';
		}
	}

	$racine = array(
		'data-yn-glossaire'    => '',
		'data-yn-msg-aucun'    => __( 'Aucun résultat', 'yume-core' ),
		'data-yn-msg-un'       => __( '1 résultat', 'yume-core' ),
		/* translators: %d : nombre de résultats (remplacé par le script) */
		'data-yn-msg-n'        => __( '%d résultats', 'yume-core' ),
		'data-yn-msg-afficher' => __( 'Afficher les notes de traduction', 'yume-core' ),
		'data-yn-msg-masquer'  => __( 'Masquer les notes de traduction', 'yume-core' ),
	);
	$html   = '<div ' . ( function_exists( '\\Yume\\Core\\Library\\attributs_racine' ) ? \Yume\Core\Library\attributs_racine( 'yn-glossaire', $racine ) : 'class="yn-glossaire" data-yn-glossaire=""' ) . '>';

	// En-tête : titre, compteurs, bascule des notes (équipe).
	$total = $etat['publiques'] + $etat['anglicismes'];
	$html .= '<div class="yn-glossaire__tete"><h2 class="yn-glossaire__titre">' . esc_html__( 'Glossaire', 'yume-core' ) . '</h2>';
	$meta  = sprintf(
		/* translators: %d : nombre d'entrées */
		_n( '%d entrée', '%d entrées', max( 1, $total ), 'yume-core' ),
		$total
	);
	if ( '' !== $etat['maj'] ) {
		$ts = strtotime( $etat['maj'] . ' UTC' );
		/* translators: %s : date */
		$meta .= $ts ? ' · ' . sprintf( __( 'mis à jour le %s', 'yume-core' ), wp_date( 'j F Y', $ts ) ) : '';
	}
	$html .= '<p class="yn-muted">' . esc_html( $meta ) . '</p>';
	$html .= '<p class="yn-glossaire__intro">' . esc_html__( 'Les noms propres et les termes de l’univers, tels que l’équipe les traduit, avec leur forme originale.', 'yume-core' ) . '</p>';
	if ( $equipe ) {
		$lien  = $notes ? remove_query_arg( 'notes' ) : add_query_arg( 'notes', '1' );
		$html .= '<p class="yn-glossaire__equipe"><a class="yn-btn yn-btn--sm" href="' . esc_url( $lien ) . '" role="button" aria-pressed="' . ( $notes ? 'true' : 'false' ) . '" data-yn-glossaire-bascule>'
			. esc_html( $notes ? __( 'Masquer les notes de traduction', 'yume-core' ) : __( 'Afficher les notes de traduction', 'yume-core' ) ) . '</a>';
		if ( current_user_can( CAPACITE ) && function_exists( '\\Yume\\Core\\Planning\\url_vue_equipe' ) ) {
			$html .= ' <a class="yn-btn yn-btn--sm" href="' . esc_url( \Yume\Core\Planning\url_vue_equipe( 'glossaire', array( 'oeuvre' => $oeuvre_id ) ) ) . '">' . esc_html__( 'Gérer le glossaire', 'yume-core' ) . '</a>';
		}
		$html .= '</p>';
	}
	$html .= '</div>';

	// Recherche instantanée et filtre (révélés par view.js ; sans script, tout est affiché).
	$html .= '<form class="yn-card yn-glossaire__outils" role="search" aria-label="' . esc_attr__( 'Rechercher dans le glossaire', 'yume-core' ) . '" data-yn-glossaire-outils hidden>';
	$html .= '<p class="yn-glossaire__champ yn-glossaire__champ--recherche"><label for="yn-glossaire-q">' . esc_html__( 'Rechercher', 'yume-core' ) . '</label>';
	$html .= '<input type="search" id="yn-glossaire-q" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__( 'Nom, terme original, description…', 'yume-core' ) . '" data-yn-glossaire-q></p>';
	$html .= '<p class="yn-glossaire__champ"><label for="yn-glossaire-cat">' . esc_html__( 'Catégorie', 'yume-core' ) . '</label>';
	$html .= '<select id="yn-glossaire-cat" data-yn-glossaire-cat><option value="">' . esc_html__( 'Toutes les catégories', 'yume-core' ) . '</option>' . $options . '</select></p>';
	$html .= '<p class="yn-glossaire__compte" role="status" aria-live="polite" data-yn-glossaire-compte></p>';
	$html .= '</form>';

	if ( '' !== $sommaire ) {
		$html .= '<nav class="yn-glossaire__sommaire" aria-label="' . esc_attr__( 'Catégories du glossaire', 'yume-core' ) . '"><ul>' . $sommaire . '</ul></nav>';
	}
	$html .= $sections;
	$html .= '<p class="yn-card yn-glossaire__vide yn-muted" data-yn-glossaire-vide hidden>' . esc_html__( 'Aucune entrée ne correspond à cette recherche.', 'yume-core' ) . '</p>';
	return $html . '</div>';
}
