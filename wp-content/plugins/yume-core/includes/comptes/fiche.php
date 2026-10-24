<?php
/**
 * Fiche d'un compte de la vue « Comptes inscrits » (?vue=comptes&compte=ID), réservée aux
 * gérants et aux administrateurs (capacité yume_voir_lecteurs).
 *
 * Sections : identité (pseudo, rôle, numéro, inscription et source, Discord, mot de passe,
 * dernière activité, e-mail pour list_users seulement), lecture (statistiques, dernière lecture,
 * séries), favoris et alertes, listes (listes privées en nombres seulement), notes,
 * commentaires, coquilles signalées, notifications (nombre d'appareils push seulement), actions
 * (« Ajouter à l'équipe » par le formulaire de « Membres et rôles », lien « Modifier dans
 * l'administration ») et pied (consultation journalisée, lien Confidentialité).
 *
 * Données : fiche_compte() par vue_donnees() (filtre yume_comptes_vue_donnees : tests,
 * démonstrations) ; sans elles, l'identité est lue du compte et les autres sections disent
 * « données indisponibles ». Jamais affichés : identifiant de connexion, adresse IP, mot de passe,
 * clés push, réglages de lecture, nom ou contenu d'une liste privée.
 *
 * Chaque fiche affichée d'un compte existant est journalisée (journaliser( 'fiche', … )) et
 * déclenche l'action yume_comptes_fiche_consultee( $compte_id, $acteur ). Sans JavaScript.
 *
 * Contrat : docs/06-contrat-technique.md §8 septies.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Comptes;

use function Yume\Core\Planning\champ_select;
use function Yume\Core\Planning\format_fr;
use function Yume\Core\Planning\get_cle;
use function Yume\Core\Planning\nom_role;
use function Yume\Core\Planning\peut_gerer_membre;
use function Yume\Core\Planning\retour_formulaire;
use function Yume\Core\Planning\roles_equipe_attribuables;
use function Yume\Core\Planning\ts_date;
use function Yume\Core\Planning\ts_gmt;
use function Yume\Core\Planning\url_vue_equipe;
use function Yume\Core\Planning\zone_retour;

defined( 'ABSPATH' ) || exit;

/** Cible du retour du formulaire « Ajouter à l'équipe » (celle de Membres et rôles). */
const FICHE_CIBLE_AJOUT = 'yn-ajouter-membre-form';

/*
 * -----------------------------------------------------------------------------
 * Aides de mise en forme
 * -----------------------------------------------------------------------------
 */

/**
 * Les aides de vue du module planning sont-elles chargées ?
 */
function fiche_planning_pret(): bool {
	return function_exists( '\Yume\Core\Planning\zone_retour' ) && function_exists( '\Yume\Core\Planning\peut_gerer_membre' );
}

/**
 * Horodatage d'une date reçue des données : entier, jour 'Y-m-d' (minuit, heure de Paris),
 * date-heure GMT 'Y-m-d H:i:s' ou date ISO 8601 ; 0 si vide ou invalide.
 *
 * @param mixed $valeur Date.
 */
function fiche_horodatage( $valeur ): int {
	if ( is_int( $valeur ) ) {
		return max( 0, $valeur );
	}
	if ( ! is_string( $valeur ) ) {
		return 0;
	}
	$valeur = trim( $valeur );
	if ( '' === $valeur || str_starts_with( $valeur, '0000-00-00' ) ) {
		return 0;
	}
	if ( ctype_digit( $valeur ) ) {
		return (int) $valeur;
	}
	if ( jour_valide( $valeur ) ) {
		return ts_date( $valeur );
	}
	if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $valeur ) ) {
		return ts_gmt( $valeur );
	}
	$ts = strtotime( $valeur );
	return false === $ts ? 0 : max( 0, (int) $ts );
}

/**
 * Date lisible en français (« 12 oct. 2026 ») ; '' si la date est vide ou invalide.
 *
 * @param mixed  $valeur Date (voir fiche_horodatage()).
 * @param string $format Format de format_fr().
 */
function fiche_date( $valeur, string $format = 'j M Y' ): string {
	$ts = fiche_horodatage( $valeur );
	return $ts > 0 ? format_fr( $ts, $format ) : '';
}

/**
 * Ancienneté d'un jour 'Y-m-d' (heure du site) : « aujourd'hui », « hier », « il y a 12 jours ».
 *
 * @param string $jour Jour.
 */
function fiche_depuis_jour( string $jour ): string {
	if ( ! jour_valide( $jour ) ) {
		return '';
	}
	$ecart = (int) round( ( (int) strtotime( jour_site() . ' 00:00:00 UTC' ) - (int) strtotime( $jour . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS );
	if ( $ecart <= 0 ) {
		return __( 'aujourd’hui', 'yume-core' );
	}
	if ( 1 === $ecart ) {
		return __( 'hier', 'yume-core' );
	}
	/* translators: %s : nombre de jours */
	return sprintf( _n( 'il y a %s jour', 'il y a %s jours', $ecart, 'yume-core' ), number_format_i18n( $ecart ) );
}

/**
 * Nombre entier à la française.
 *
 * @param mixed $n Valeur.
 */
function fiche_nombre( $n ): string {
	return number_format_i18n( is_numeric( $n ) ? (int) $n : 0 );
}

/**
 * Pseudo à afficher : le cœur enregistre « & » sous la forme « &amp; » ; décodé ici, échappé
 * à l'affichage.
 *
 * @param mixed $pseudo Pseudo.
 */
function fiche_pseudo( $pseudo ): string {
	return is_scalar( $pseudo ) ? wp_specialchars_decode( (string) $pseudo, ENT_QUOTES ) : '';
}

/**
 * Texte d'une donnée (scalaire seulement).
 *
 * @param array  $ligne Données.
 * @param string $cle   Clé.
 */
function fiche_texte( array $ligne, string $cle ): string {
	$valeur = $ligne[ $cle ] ?? '';
	return is_scalar( $valeur ) ? trim( wp_strip_all_tags( (string) $valeur ) ) : '';
}

/**
 * Lien vers une adresse du site ou texte seul si l'adresse est vide.
 *
 * @param string $texte Texte.
 * @param mixed  $url   Adresse.
 */
function fiche_lien( string $texte, $url ): string {
	$url = is_string( $url ) ? esc_url( $url ) : '';
	return '' !== $url ? '<a href="' . $url . '">' . esc_html( $texte ) . '</a>' : esc_html( $texte );
}

/**
 * Section en carte avec titre (h2).
 *
 * @param string $id     Identifiant du titre.
 * @param string $titre  Titre.
 * @param string $corps  HTML du contenu.
 * @param string $classe Classe ajoutée.
 * @param string $balise section ou aside.
 */
function fiche_section( string $id, string $titre, string $corps, string $classe = '', string $balise = 'section' ): string {
	$balise = 'aside' === $balise ? 'aside' : 'section';
	$html   = '<' . $balise . ' class="yn-card yn-comptes__fiche-section' . ( '' !== $classe ? ' ' . esc_attr( $classe ) : '' ) . '" aria-labelledby="' . esc_attr( $id ) . '">';
	$html  .= '<h2 class="yn-comptes__fiche-titre" id="' . esc_attr( $id ) . '">' . esc_html( $titre ) . '</h2>';
	return $html . $corps . '</' . $balise . '>';
}

/**
 * Message « aucune donnée ».
 *
 * @param string $texte Texte (vide : « Données indisponibles pour le moment. »).
 */
function fiche_vide( string $texte = '' ): string {
	return '<p class="yn-muted yn-comptes__fiche-vide">' . esc_html( '' !== $texte ? $texte : __( 'Données indisponibles pour le moment.', 'yume-core' ) ) . '</p>';
}

/**
 * Tableau défilant (classes des Indicateurs : yn-kpi__defile, yn-kpi__table).
 *
 * @param string   $id       Identifiant de la légende.
 * @param string   $legende  Légende (lecteurs d'écran).
 * @param string[] $entetes  En-têtes.
 * @param array    $lignes   Lignes de cellules déjà échappées (la première : en-tête de ligne).
 */
function fiche_tableau( string $id, string $legende, array $entetes, array $lignes ): string {
	$html  = '<div class="yn-kpi__defile yn-comptes__fiche-defile" role="region" tabindex="0" aria-labelledby="' . esc_attr( $id ) . '">';
	$html .= '<table class="yn-kpi__table yn-comptes__fiche-table"><caption id="' . esc_attr( $id ) . '" class="yn-visually-hidden">' . esc_html( $legende ) . '</caption><thead><tr>';
	foreach ( $entetes as $entete ) {
		$html .= '<th scope="col">' . esc_html( $entete ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $lignes as $cellules ) {
		$html .= '<tr>';
		foreach ( array_values( $cellules ) as $i => $cellule ) {
			$html .= $i ? '<td>' . $cellule . '</td>' : '<th scope="row">' . $cellule . '</th>';
		}
		$html .= '</tr>';
	}
	return $html . '</tbody></table></div>';
}

/*
 * -----------------------------------------------------------------------------
 * Données
 * -----------------------------------------------------------------------------
 */

/**
 * Identité lue du compte (sans fiche_compte()) : mêmes clés que fiche_compte()['identite'].
 *
 * @param \WP_User $compte Compte.
 * @return array<string,mixed>
 */
function fiche_identite_compte( \WP_User $compte ): array {
	$id      = (int) $compte->ID;
	$source  = source_compte( $id );
	$discord = null;
	if ( function_exists( '\Yume\Core\Discord\discord_lie' ) ) {
		$pseudo = (string) \Yume\Core\Discord\discord_lie( $id );
		if ( '' !== $pseudo ) {
			$discord = array(
				'pseudo' => $pseudo,
				'lie_le' => (string) get_user_meta( $id, 'yume_discord_lie_le', true ),
			);
		}
	}
	$inscrit = ts_gmt( (string) $compte->user_registered );
	return array(
		'id'                     => $id,
		'pseudo'                 => (string) $compte->display_name,
		'roles'                  => array_values( (array) $compte->roles ),
		'role'                   => nom_role( $compte ),
		'equipe'                 => user_can( $compte, 'yume_voir_equipe' ),
		'inscrit_le'             => $inscrit > 0 ? gmdate( 'c', $inscrit ) : '',
		'source'                 => (string) $source['source'],
		'source_deduite'         => (bool) $source['deduite'],
		'discord'                => $discord,
		'sans_mdp'               => function_exists( '\Yume\Core\Social\compte_sans_mot_de_passe' ) && \Yume\Core\Social\compte_sans_mot_de_passe( $id ),
		'pseudo_est_identifiant' => function_exists( '\Yume\Core\Social\pseudo_est_identifiant' ) && \Yume\Core\Social\pseudo_est_identifiant( $compte ),
		'activite'               => derniere_activite( $id ),
		'email_disponible'       => '' !== (string) $compte->user_email,
	);
}

/*
 * -----------------------------------------------------------------------------
 * Sections
 * -----------------------------------------------------------------------------
 */

/**
 * Dernière activité lisible : « 22 oct. 2026 (il y a 2 jours) » ou « inconnue — relevée depuis
 * le … ».
 *
 * @param string $jour Jour 'Y-m-d' ou ''.
 */
function fiche_activite( string $jour ): string {
	if ( jour_valide( $jour ) ) {
		return fiche_date( $jour ) . ' (' . fiche_depuis_jour( $jour ) . ')';
	}
	$depuis = (string) get_option( OPTION_DEPUIS, '' );
	return jour_valide( $depuis )
		/* translators: %s : date du début du relevé */
		? sprintf( __( 'inconnue — relevée depuis le %s', 'yume-core' ), fiche_date( $depuis ) )
		: __( 'inconnue', 'yume-core' );
}

/**
 * Tête de la fiche : pseudo, résumé, bouton « Modifier dans l'administration ».
 *
 * @param array $identite Identité.
 */
function fiche_tete( array $identite ): string {
	$id      = (int) $identite['id'];
	$pseudo  = fiche_pseudo( $identite['pseudo'] );
	$sources = libelles_sources();
	$source  = (string) $identite['source'];
	$resume  = array( '' !== (string) $identite['role'] ? (string) $identite['role'] : __( 'Sans rôle', 'yume-core' ) );
	/* translators: %d : numéro du compte */
	$resume[] = sprintf( __( 'compte n° %d', 'yume-core' ), $id );
	$inscrit  = fiche_horodatage( $identite['inscrit_le'] );
	if ( $inscrit > 0 ) {
		/* translators: 1: date, 2: ancienneté (« il y a 12 jours ») */
		$resume[] = sprintf( __( 'inscrit le %1$s (%2$s)', 'yume-core' ), format_fr( $inscrit, 'j M Y' ), fiche_depuis_jour( jour_site( $inscrit ) ) );
	}
	$resume[] = 'inconnue' === $source || ! isset( $sources[ $source ] )
		? __( 'source d’inscription inconnue', 'yume-core' )
		/* translators: %s : source d'inscription (« Discord ») */
		: sprintf( __( 'via %s', 'yume-core' ), $sources[ $source ] );
	/* translators: %s : dernière activité */
	$resume[] = sprintf( __( 'dernière activité : %s', 'yume-core' ), jour_valide( (string) $identite['activite'] ) ? fiche_depuis_jour( (string) $identite['activite'] ) : __( 'inconnue', 'yume-core' ) );

	$html  = '<div class="yn-team__tete yn-comptes__fiche-tete"><div><p class="yn-label">' . esc_html__( 'Comptes inscrits · fiche', 'yume-core' ) . '</p>';
	$html .= '<h2 class="yn-team__bonjour yn-comptes__fiche-pseudo">' . esc_html( $pseudo ) . '</h2>';
	$html .= '<p class="yn-muted yn-comptes__fiche-resume">' . esc_html( implode( ' · ', $resume ) ) . '</p></div>';
	if ( current_user_can( 'edit_user', $id ) ) {
		$html .= '<p class="yn-team__boutons"><a class="yn-btn" href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $id ) ) . '">' . esc_html__( 'Modifier dans l’administration', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $pseudo ) . '</span></a></p>';
	}
	return $html . '</div>';
}

/**
 * Section « Identité ».
 *
 * @param array $identite Identité.
 */
function fiche_section_identite( array $identite ): string {
	$id     = (int) $identite['id'];
	$lignes = array();

	$note = ! empty( $identite['pseudo_est_identifiant'] )
		? ' <span class="yn-muted yn-comptes__fiche-note">' . esc_html__( '(pseudo par défaut d’un compte antérieur à la version 2.5 : identique à l’identifiant de connexion)', 'yume-core' ) . '</span>'
		: '';

	$lignes[ __( 'Pseudo', 'yume-core' ) ] = esc_html( fiche_pseudo( $identite['pseudo'] ) ) . $note;

	$role                                = '' !== (string) $identite['role'] ? (string) $identite['role'] : __( 'Sans rôle', 'yume-core' );
	$lignes[ __( 'Rôle', 'yume-core' ) ] = esc_html( $role ) . ( ! empty( $identite['equipe'] ) ? ' <span class="yn-chip yn-chip--info">' . esc_html__( 'Équipe', 'yume-core' ) . '</span>' : '' );
	/* translators: %d : numéro du compte */
	$lignes[ __( 'Compte', 'yume-core' ) ] = esc_html( sprintf( __( 'n° %d', 'yume-core' ), $id ) );

	if ( peut_voir_email() ) {
		$compte = get_userdata( $id );
		$email  = $compte instanceof \WP_User ? (string) $compte->user_email : '';
		if ( '' !== $email ) {
			$lignes[ __( 'E-mail', 'yume-core' ) ] = esc_html( $email ) . ' <span class="yn-muted yn-comptes__fiche-note">' . esc_html__( '(visible des gérants, ne pas diffuser)', 'yume-core' ) . '</span>';
		}
	}

	$sources      = libelles_sources();
	$source       = isset( $sources[ (string) $identite['source'] ] ) ? (string) $identite['source'] : 'inconnue';
	$inscrit      = fiche_horodatage( $identite['inscrit_le'] );
	$inscription  = $inscrit > 0 ? esc_html( format_fr( $inscrit, 'j M Y' ) . ' (' . fiche_depuis_jour( jour_site( $inscrit ) ) . ')' ) . ' · ' : '';
	$variante     = 'discord' === $source ? ' yn-chip--info' : ( 'inconnue' === $source ? '' : ' yn-chip--ok' );
	$inscription .= '<span class="yn-chip' . $variante . '">' . esc_html( $sources[ $source ] ) . '</span>';
	if ( ! empty( $identite['source_deduite'] ) && 'inconnue' !== $source ) {
		$inscription .= ' <span class="yn-muted yn-comptes__fiche-note">' . esc_html__( '(déduite : compte antérieur au relevé de la source)', 'yume-core' ) . '</span>';
	}
	$lignes[ __( 'Inscription', 'yume-core' ) ] = $inscription;

	$discord = is_array( $identite['discord'] ?? null ) ? $identite['discord'] : null;
	$nom     = $discord ? ltrim( fiche_texte( $discord, 'pseudo' ), '@' ) : '';
	if ( '' !== $nom ) {
		$le    = fiche_date( $discord['lie_le'] ?? '' );
		$texte = '' !== $le
			/* translators: 1: nom Discord, 2: date */
			? sprintf( __( 'Lié à @%1$s depuis le %2$s', 'yume-core' ), $nom, $le )
			/* translators: %s : nom Discord */
			: sprintf( __( 'Lié à @%s', 'yume-core' ), $nom );
	} else {
		$texte = __( 'Non lié', 'yume-core' );
	}
	$lignes[ __( 'Discord', 'yume-core' ) ] = esc_html( $texte );

	$lignes[ __( 'Mot de passe', 'yume-core' ) ] = esc_html(
		! empty( $identite['sans_mdp'] )
			? __( 'Aucun mot de passe défini (connexion par Discord)', 'yume-core' )
			: __( 'Défini', 'yume-core' )
	);

	$lignes[ __( 'Dernière activité', 'yume-core' ) ] = esc_html( fiche_activite( (string) $identite['activite'] ) );

	$corps = '<dl class="yn-comptes__fiche-identite">';
	foreach ( $lignes as $terme => $definition ) {
		$corps .= '<dt>' . esc_html( $terme ) . '</dt><dd>' . $definition . '</dd>';
	}
	return fiche_section( 'yn-comptes-fiche-identite', __( 'Identité', 'yume-core' ), $corps . '</dl>', 'yn-comptes__fiche-identite-carte' );
}

/**
 * Section « Ajouter à l'équipe » / « Équipe » : formulaire d'ajout (compte hors équipe que le
 * compte courant peut gérer), sinon lien vers Membres et rôles ou explication ; message de
 * retour du formulaire.
 *
 * @param \WP_User   $compte Compte affiché.
 * @param array|null $retour Retour du formulaire (cible yn-ajouter-membre-form) ou null.
 */
function fiche_section_actions( \WP_User $compte, ?array $retour ): string {
	$id     = (int) $compte->ID;
	$acteur = get_current_user_id();
	$equipe = user_can( $compte, 'yume_voir_equipe' );
	$form   = $equipe ? '' : formulaire_ajout_equipe( $compte, $retour );
	if ( '' !== $form ) {
		return fiche_section( 'yn-comptes-fiche-equipe', __( 'Ajouter à l’équipe', 'yume-core' ), $form, 'yn-comptes__fiche-actions', 'aside' );
	}

	$corps = $retour ? '<div id="' . esc_attr( FICHE_CIBLE_AJOUT ) . '">' . zone_retour( $retour ) . '</div>' : '';
	if ( $equipe && peut_gerer_membre( $acteur, $id ) ) {
		$page   = function_exists( '\Yume\Core\Planning\url_page_membres' ) ? \Yume\Core\Planning\url_page_membres() : '';
		$page   = '' !== $page ? $page : \Yume\Core\Planning\url_membres();
		$corps .= '' !== $page
			? '<p><a href="' . esc_url( $page . '#yn-membre-' . $id ) . '">' . esc_html__( 'Changer son rôle dans Membres et rôles', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p>'
			: '';
	} elseif ( $id === $acteur ) {
		$corps .= '<p class="yn-muted">' . esc_html__( 'Votre compte : votre rôle ne se change pas ici.', 'yume-core' ) . '</p>';
	} elseif ( $equipe ) {
		$corps .= '<p class="yn-muted">' . esc_html__( 'Administrateur ou gérant : non modifiable depuis l’espace équipe.', 'yume-core' ) . '</p>';
	} else {
		$corps .= '<p class="yn-muted">' . esc_html__( 'Ce compte ne peut pas être ajouté à l’équipe depuis cette page.', 'yume-core' ) . '</p>';
	}
	return fiche_section( 'yn-comptes-fiche-equipe', $equipe ? __( 'Équipe', 'yume-core' ) : __( 'Ajouter à l’équipe', 'yume-core' ), $corps, 'yn-comptes__fiche-actions', 'aside' );
}

/**
 * Section « Lecture » : chiffres de statistiques_lecture(), dernière lecture, séries.
 *
 * @param mixed $lecture Données (fiche_compte()['lecture']).
 */
function fiche_section_lecture( $lecture ): string {
	$titre = __( 'Lecture', 'yume-core' );
	if ( ! is_array( $lecture ) ) {
		return fiche_section( 'yn-comptes-fiche-lecture', $titre, fiche_vide() );
	}
	$series  = is_array( $lecture['series_detail'] ?? null ) && $lecture['series_detail'] ? $lecture['series_detail'] : ( is_array( $lecture['series'] ?? null ) ? $lecture['series'] : array() );
	$series  = array_values( array_filter( $series, 'is_array' ) );
	$minutes = (int) ( $lecture['minutes'] ?? 0 );
	$duree   = function_exists( '\Yume\Core\Reader\duree_lisible' )
		? \Yume\Core\Reader\duree_lisible( $minutes )
		/* translators: %d : minutes */
		: sprintf( __( '%d min', 'yume-core' ), $minutes );
	$chiffres = array(
		__( 'Séries suivies', 'yume-core' )       => fiche_nombre( count( $series ) ),
		__( 'En cours', 'yume-core' )             => fiche_nombre( $lecture['series_en_cours'] ?? 0 ),
		__( 'À jour', 'yume-core' )               => fiche_nombre( $lecture['series_a_jour'] ?? 0 ),
		__( 'Chapitres lus (est.)', 'yume-core' ) => fiche_nombre( $lecture['chapitres_lus'] ?? 0 ),
		__( 'Tomes terminés', 'yume-core' )       => fiche_nombre( $lecture['tomes_termines'] ?? 0 ),
		__( 'Temps estimé', 'yume-core' )         => $duree,
	);
	$corps    = '<dl class="yn-comptes__fiche-chiffres">';
	foreach ( $chiffres as $libelle => $valeur ) {
		$corps .= '<div><dt class="yn-label">' . esc_html( $libelle ) . '</dt><dd class="yn-comptes__fiche-valeur">' . esc_html( $valeur ) . '</dd></div>';
	}
	$corps .= '</dl>';

	$derniere = is_array( $lecture['derniere'] ?? null ) ? $lecture['derniere'] : null;
	if ( $derniere ) {
		$morceaux = array( '<strong>' . fiche_lien( fiche_texte( $derniere, 'titre' ), $derniere['url'] ?? '' ) . '</strong>' );
		foreach ( array( fiche_texte( $derniere, 'chapitre' ), fiche_date( $derniere['le'] ?? '' ) ) as $morceau ) {
			if ( '' !== $morceau ) {
				$morceaux[] = esc_html( $morceau );
			}
		}
		$corps .= '<p class="yn-comptes__fiche-derniere">' . esc_html__( 'Dernière lecture :', 'yume-core' ) . ' ' . implode( ' · ', $morceaux ) . '</p>';
	} else {
		$corps .= '<p class="yn-muted">' . esc_html__( 'Aucune position de lecture enregistrée.', 'yume-core' ) . '</p>';
	}

	if ( $series ) {
		$lignes = array();
		foreach ( $series as $serie ) {
			$a_jour   = ! empty( $serie['a_jour'] );
			$position = array_filter( array( fiche_texte( $serie, 'position' ), fiche_texte( $serie, 'chapitre' ), fiche_date( $serie['le'] ?? '', 'j M' ) ) );
			$lignes[] = array(
				fiche_lien( fiche_texte( $serie, 'titre' ), $serie['url'] ?? '' ),
				/* translators: 1: chapitres lus, 2: chapitres publiés */
				esc_html( sprintf( __( '%1$s / %2$s', 'yume-core' ), fiche_nombre( $serie['chapitres_lus'] ?? 0 ), fiche_nombre( $serie['chapitres'] ?? 0 ) ) ),
				'<span class="yn-chip' . ( $a_jour ? ' yn-chip--ok' : '' ) . '">' . esc_html( $a_jour ? __( 'À jour', 'yume-core' ) : __( 'En cours', 'yume-core' ) ) . '</span>',
				esc_html( $position ? implode( ' · ', $position ) : '—' ),
			);
		}
		$corps .= fiche_tableau(
			'yn-comptes-fiche-t-series',
			__( 'Séries lues par ce compte', 'yume-core' ),
			array( __( 'Œuvre', 'yume-core' ), __( 'Lus / total', 'yume-core' ), __( 'État', 'yume-core' ), __( 'Dernière position', 'yume-core' ) ),
			$lignes
		);
	}
	return fiche_section( 'yn-comptes-fiche-lecture', $titre, $corps, 'yn-comptes__fiche-lecture' );
}

/**
 * Section « Favoris et alertes » : favoris (œuvre, depuis, fréquence) et préférences d'alerte.
 *
 * @param mixed $favoris Favoris (fiche_compte()['favoris']).
 * @param mixed $alertes Préférences (fiche_compte()['alertes']).
 */
function fiche_section_favoris( $favoris, $alertes ): string {
	if ( ! is_array( $favoris ) ) {
		return fiche_section( 'yn-comptes-fiche-favoris', __( 'Favoris et alertes', 'yume-core' ), fiche_vide() );
	}
	$favoris    = array_values( array_filter( $favoris, 'is_array' ) );
	$frequences = function_exists( '\Yume\Core\Social\libelles_frequences' )
		? \Yume\Core\Social\libelles_frequences()
		: array(
			'immediat' => __( 'Immédiate', 'yume-core' ),
			'hebdo'    => __( 'Hebdomadaire', 'yume-core' ),
			'jamais'   => __( 'Jamais', 'yume-core' ),
		);
	$corps      = '';
	if ( $favoris ) {
		$lignes = array();
		foreach ( $favoris as $favori ) {
			$frequence = fiche_texte( $favori, 'frequence' );
			$depuis    = fiche_date( $favori['depuis'] ?? '' );
			$lignes[]  = array(
				fiche_lien( fiche_texte( $favori, 'titre' ), $favori['url'] ?? '' ),
				esc_html( '' !== $depuis ? $depuis : '—' ),
				esc_html( $frequences[ $frequence ] ?? ( '' !== $frequence ? $frequence : '—' ) ),
			);
		}
		$corps .= fiche_tableau(
			'yn-comptes-fiche-t-favoris',
			__( 'Favoris de ce compte et fréquence des alertes', 'yume-core' ),
			array( __( 'Œuvre', 'yume-core' ), __( 'Depuis', 'yume-core' ), __( 'Alerte', 'yume-core' ) ),
			$lignes
		);
	} else {
		$corps .= '<p class="yn-muted">' . esc_html__( 'Aucun favori.', 'yume-core' ) . '</p>';
	}
	if ( is_array( $alertes ) ) {
		$oui_non = static function ( string $cle ) use ( $alertes ): string {
			return ! empty( $alertes[ $cle ] ) ? __( 'oui', 'yume-core' ) : __( 'non', 'yume-core' );
		};
		$prefs   = array(
			/* translators: %s : oui ou non */
			sprintf( __( 'e-mail à chaque sortie : %s', 'yume-core' ), $oui_non( 'sorties' ) ),
			/* translators: %s : oui ou non */
			sprintf( __( 'récapitulatif hebdomadaire : %s', 'yume-core' ), $oui_non( 'hebdo' ) ),
			/* translators: %s : oui ou non */
			sprintf( __( 'réponses à ses commentaires : %s', 'yume-core' ), $oui_non( 'commentaires' ) ),
		);
		$corps  .= '<p class="yn-muted yn-comptes__fiche-note">' . esc_html__( 'Préférences d’alerte :', 'yume-core' ) . ' ' . esc_html( implode( ' · ', $prefs ) ) . '</p>';
	}
	/* translators: %s : nombre de favoris */
	return fiche_section( 'yn-comptes-fiche-favoris', sprintf( __( 'Favoris et alertes (%s)', 'yume-core' ), fiche_nombre( count( $favoris ) ) ), $corps );
}

/**
 * Section « Listes » : listes système (nombres), listes personnelles, listes publiques (nom,
 * lien, nombre d'œuvres), listes privées en nombres seulement (jamais leur nom ni leur contenu).
 *
 * @param mixed $listes Données (fiche_compte()['listes']).
 */
function fiche_section_listes( $listes ): string {
	$titre = __( 'Listes', 'yume-core' );
	if ( ! is_array( $listes ) ) {
		return fiche_section( 'yn-comptes-fiche-listes', $titre, fiche_vide() );
	}
	$systeme = is_array( $listes['systeme'] ?? null ) ? $listes['systeme'] : array();
	$corps   = '<p>' . esc_html(
		implode(
			' · ',
			array(
				/* translators: %s : nombre d'œuvres */
				sprintf( __( 'À lire %s', 'yume-core' ), fiche_nombre( $systeme['a_lire'] ?? 0 ) ),
				/* translators: %s : nombre d'œuvres */
				sprintf( __( 'En cours %s', 'yume-core' ), fiche_nombre( $systeme['en_cours'] ?? 0 ) ),
				/* translators: %s : nombre d'œuvres */
				sprintf( __( 'Terminé %s', 'yume-core' ), fiche_nombre( $systeme['termine'] ?? 0 ) ),
			)
		)
	) . '</p>';

	$personnelles = (int) ( $listes['personnelles'] ?? 0 );
	$publiques    = array_values( array_filter( is_array( $listes['publiques'] ?? null ) ? $listes['publiques'] : array(), 'is_array' ) );
	$corps       .= '<p>' . esc_html(
		sprintf(
			/* translators: 1: listes personnelles, 2: listes publiques */
			_n( 'Listes personnelles : %1$s, dont %2$s publique.', 'Listes personnelles : %1$s, dont %2$s publiques.', count( $publiques ), 'yume-core' ),
			fiche_nombre( $personnelles ),
			fiche_nombre( count( $publiques ) )
		)
	) . '</p>';
	if ( $publiques ) {
		$corps .= '<ul class="yn-comptes__fiche-liste">';
		foreach ( $publiques as $liste ) {
			$nb     = (int) ( $liste['nb'] ?? 0 );
			$corps .= '<li>' . fiche_lien( '« ' . fiche_texte( $liste, 'nom' ) . ' »', $liste['url'] ?? '' ) . ' <span class="yn-muted">' . esc_html(
				/* translators: %s : nombre d'œuvres */
				sprintf( _n( '(%s œuvre)', '(%s œuvres)', $nb, 'yume-core' ), fiche_nombre( $nb ) )
			) . '</span></li>';
		}
		$corps .= '</ul>';
	}
	$privees = (int) ( $listes['privees'] ?? 0 );
	if ( $privees > 0 ) {
		$oeuvres = (int) ( $listes['oeuvres_privees'] ?? 0 );
		$corps  .= '<p class="yn-muted yn-comptes__fiche-note">' . esc_html(
			sprintf(
				/* translators: 1: listes privées, 2: phrase « (14 œuvres) » */
				_n( '%1$s liste privée %2$s : contenu non consultable.', '%1$s listes privées %2$s : contenu non consultable.', $privees, 'yume-core' ),
				fiche_nombre( $privees ),
				/* translators: %s : nombre d'œuvres */
				sprintf( _n( '(%s œuvre)', '(%s œuvres)', $oeuvres, 'yume-core' ), fiche_nombre( $oeuvres ) )
			)
		) . '</p>';
	}
	return fiche_section( 'yn-comptes-fiche-listes', $titre, $corps );
}

/**
 * Section « Notes » : nombre, moyenne, notes par œuvre.
 *
 * @param mixed $notes Données (fiche_compte()['notes']).
 */
function fiche_section_notes( $notes ): string {
	if ( ! is_array( $notes ) ) {
		return fiche_section( 'yn-comptes-fiche-notes', __( 'Notes', 'yume-core' ), fiche_vide() );
	}
	$nb    = (int) ( $notes['nb'] ?? 0 );
	$titre = $nb > 0 && is_numeric( $notes['moyenne'] ?? null )
		/* translators: 1: nombre de notes, 2: moyenne */
		? sprintf( __( 'Notes (%1$s · moyenne %2$s)', 'yume-core' ), fiche_nombre( $nb ), number_format_i18n( (float) $notes['moyenne'], 1 ) )
		/* translators: %s : nombre de notes */
		: sprintf( __( 'Notes (%s)', 'yume-core' ), fiche_nombre( $nb ) );
	$lignes = array_values( array_filter( is_array( $notes['lignes'] ?? null ) ? $notes['lignes'] : array(), 'is_array' ) );
	if ( ! $lignes ) {
		return fiche_section( 'yn-comptes-fiche-notes', $titre, '<p class="yn-muted">' . esc_html__( 'Aucune note.', 'yume-core' ) . '</p>' );
	}
	$corps = '<ul class="yn-comptes__fiche-liste">';
	foreach ( $lignes as $ligne ) {
		$le     = fiche_date( $ligne['le'] ?? '' );
		$corps .= '<li>' . esc_html( fiche_texte( $ligne, 'titre' ) ) . ' : ' . esc_html(
			/* translators: %s : note sur 5 */
			sprintf( __( '%s/5', 'yume-core' ), fiche_nombre( $ligne['note'] ?? 0 ) ) . ( '' !== $le ? ' · ' . $le : '' )
		) . '</li>';
	}
	return fiche_section( 'yn-comptes-fiche-notes', $titre, $corps . '</ul>' );
}

/**
 * Libellé du statut d'un commentaire (approuve|attente|spam ou valeurs du cœur).
 *
 * @param string $statut Statut.
 * @return array{0:string,1:string} Libellé et variante de pastille.
 */
function fiche_statut_commentaire( string $statut ): array {
	switch ( $statut ) {
		case 'approuve':
		case 'approved':
		case '1':
			return array( __( 'Approuvé', 'yume-core' ), 'yn-chip--ok' );
		case 'attente':
		case 'hold':
		case 'unapproved':
		case '0':
			return array( __( 'En attente', 'yume-core' ), 'yn-chip--warn' );
		case 'spam':
			return array( __( 'Indésirable', 'yume-core' ), 'yn-chip--warn' );
		case 'trash':
		case 'corbeille':
			return array( __( 'Corbeille', 'yume-core' ), '' );
	}
	return array( '', '' );
}

/**
 * Section « Commentaires » : total par statut, dix derniers, lien de modération.
 *
 * @param mixed $commentaires Données (fiche_compte()['commentaires']).
 * @param int   $compte_id    Compte.
 */
function fiche_section_commentaires( $commentaires, int $compte_id ): string {
	if ( ! is_array( $commentaires ) ) {
		return fiche_section( 'yn-comptes-fiche-commentaires', __( 'Commentaires', 'yume-core' ), fiche_vide() );
	}
	$total = (int) ( $commentaires['total'] ?? 0 );
	/* translators: %s : nombre de commentaires */
	$titre = sprintf( __( 'Commentaires (%s)', 'yume-core' ), fiche_nombre( $total ) );
	if ( $total <= 0 ) {
		return fiche_section( 'yn-comptes-fiche-commentaires', $titre, '<p class="yn-muted">' . esc_html__( 'Aucun commentaire.', 'yume-core' ) . '</p>' );
	}
	$approuves = (int) ( $commentaires['approuves'] ?? 0 );
	$attente   = (int) ( $commentaires['attente'] ?? 0 );
	$spam      = (int) ( $commentaires['spam'] ?? 0 );
	$statuts   = array(
		/* translators: %s : nombre */
		sprintf( _n( '%s approuvé', '%s approuvés', $approuves, 'yume-core' ), fiche_nombre( $approuves ) ),
		/* translators: %s : nombre */
		sprintf( _n( '%s en attente', '%s en attente', $attente, 'yume-core' ), fiche_nombre( $attente ) ),
	);
	if ( $spam > 0 ) {
		/* translators: %s : nombre */
		$statuts[] = sprintf( _n( '%s indésirable', '%s indésirables', $spam, 'yume-core' ), fiche_nombre( $spam ) );
	}
	$corps    = '<p class="yn-muted">' . esc_html( implode( ' · ', $statuts ) ) . '</p>';
	$derniers = array_slice( array_values( array_filter( is_array( $commentaires['derniers'] ?? null ) ? $commentaires['derniers'] : array(), 'is_array' ) ), 0, 10 );
	if ( $derniers ) {
		$corps .= '<ul class="yn-comptes__fiche-liste yn-comptes__fiche-commentaires">';
		foreach ( $derniers as $c ) {
			$morceaux = array_filter( array( fiche_date( $c['date'] ?? '' ), fiche_texte( $c, 'sur' ) ) );
			$extrait  = fiche_texte( $c, 'extrait' );
			$ligne    = esc_html( implode( ' · ', $morceaux ) );
			if ( '' !== $extrait ) {
				$ligne .= ( '' !== $ligne ? ' · ' : '' ) . fiche_lien( '« ' . $extrait . ' »', $c['url'] ?? '' );
			}
			list( $statut, $variante ) = fiche_statut_commentaire( fiche_texte( $c, 'statut' ) );
			if ( '' !== $statut && 'yn-chip--ok' !== $variante ) {
				$ligne .= ' <span class="yn-chip ' . esc_attr( $variante ) . '">' . esc_html( $statut ) . '</span>';
			}
			$corps .= '<li>' . $ligne . '</li>';
		}
		$corps .= '</ul>';
	}
	if ( current_user_can( 'moderate_comments' ) ) {
		$corps .= '<p class="yn-comptes__fiche-lien"><a href="' . esc_url( admin_url( 'edit-comments.php?user_id=' . $compte_id ) ) . '">' . esc_html__( 'Modérer ses commentaires', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p>';
	}
	return fiche_section( 'yn-comptes-fiche-commentaires', $titre, $corps );
}

/**
 * Section « Coquilles signalées » : total par statut, lien vers la vue Corrections.
 *
 * @param mixed $coquilles Données (fiche_compte()['coquilles']).
 */
function fiche_section_coquilles( $coquilles ): string {
	if ( ! is_array( $coquilles ) ) {
		return fiche_section( 'yn-comptes-fiche-coquilles', __( 'Coquilles signalées', 'yume-core' ), fiche_vide() );
	}
	$total = (int) ( $coquilles['total'] ?? 0 );
	/* translators: %s : nombre de signalements */
	$titre = sprintf( __( 'Coquilles signalées (%s)', 'yume-core' ), fiche_nombre( $total ) );
	if ( $total <= 0 ) {
		return fiche_section( 'yn-comptes-fiche-coquilles', $titre, '<p class="yn-muted">' . esc_html__( 'Aucun signalement.', 'yume-core' ) . '</p>' );
	}
	$par      = is_array( $coquilles['par_statut'] ?? null ) ? $coquilles['par_statut'] : array();
	$corriges = (int) ( $par['corrige'] ?? 0 );
	$nouveaux = (int) ( $par['nouveau'] ?? 0 );
	$rejetes  = (int) ( $par['rejete'] ?? 0 );
	$corps    = '<p>' . esc_html(
		implode(
			' · ',
			array(
				/* translators: %s : nombre */
				sprintf( _n( '%s corrigée', '%s corrigées', $corriges, 'yume-core' ), fiche_nombre( $corriges ) ),
				/* translators: %s : nombre */
				sprintf( _n( '%s nouvelle', '%s nouvelles', $nouveaux, 'yume-core' ), fiche_nombre( $nouveaux ) ),
				/* translators: %s : nombre */
				sprintf( _n( '%s rejetée', '%s rejetées', $rejetes, 'yume-core' ), fiche_nombre( $rejetes ) ),
			)
		)
	) . '</p>';
	if ( current_user_can( 'yume_voir_equipe' ) ) {
		$corps .= '<p class="yn-comptes__fiche-lien"><a href="' . esc_url( url_vue_equipe( 'corrections', array( 'statut' => 'tous' ) ) ) . '">' . esc_html__( 'Voir dans Corrections', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p>';
	}
	return fiche_section( 'yn-comptes-fiche-coquilles', $titre, $corps );
}

/**
 * Section « Notifications » : non lues, reçues sur 90 jours, nombre d'appareils push (jamais
 * leurs adresses ni leurs clés).
 *
 * @param mixed $notifications Données (fiche_compte()['notifications']).
 */
function fiche_section_notifications( $notifications ): string {
	$titre = __( 'Notifications', 'yume-core' );
	if ( ! is_array( $notifications ) ) {
		return fiche_section( 'yn-comptes-fiche-notifications', $titre, fiche_vide() );
	}
	$non_lues = (int) ( $notifications['non_lues'] ?? 0 );
	$total    = (int) ( $notifications['total'] ?? 0 );
	$push     = (int) ( $notifications['push'] ?? 0 );
	$texte    = implode(
		' · ',
		array(
			/* translators: %s : nombre */
			sprintf( _n( '%s non lue', '%s non lues', $non_lues, 'yume-core' ), fiche_nombre( $non_lues ) ),
			/* translators: %s : nombre */
			sprintf( _n( '%s reçue (90 j)', '%s reçues (90 j)', $total, 'yume-core' ), fiche_nombre( $total ) ),
			/* translators: %s : nombre */
			sprintf( _n( '%s appareil push', '%s appareils push', $push, 'yume-core' ), fiche_nombre( $push ) ),
		)
	);
	return fiche_section( 'yn-comptes-fiche-notifications', $titre, '<p>' . esc_html( $texte ) . '</p>' );
}

/**
 * Pied de la fiche : consultation journalisée, usage interne, lien Confidentialité.
 */
function fiche_pied(): string {
	$html = '<p class="yn-muted yn-comptes__fiche-pied">' . esc_html__( 'Consultation journalisée. Données personnelles : usage interne de l’équipe, ne pas diffuser. Jamais d’adresse IP ni de mot de passe affichés.', 'yume-core' );
	$url  = get_privacy_policy_url();
	if ( '' !== $url ) {
		$html .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Politique de confidentialité', 'yume-core' ) . '</a>';
	}
	return $html . '</p>';
}

/*
 * -----------------------------------------------------------------------------
 * Formulaire « Ajouter à l'équipe »
 * -----------------------------------------------------------------------------
 */

/**
 * Formulaire « Ajouter à l'équipe » de la fiche : envoyé à la page « Membres et rôles »
 * (admin-post.php, action yume_equipe_membres, op=ajout, user_id, nonce yume_membres_ajout,
 * yn_retour = adresse de la fiche). Vide si le compte fait déjà partie de l'équipe, si le compte
 * courant ne peut pas le gérer (Planning\peut_gerer_membre()) ou si aucun rôle n'est attribuable.
 *
 * @param \WP_User   $compte Compte affiché.
 * @param array|null $retour Retour du formulaire (message affiché sous le bouton).
 */
function formulaire_ajout_equipe( \WP_User $compte, ?array $retour = null ): string {
	if ( ! fiche_planning_pret() ) {
		return '';
	}
	$id    = (int) $compte->ID;
	$roles = roles_equipe_attribuables();
	if ( ! $roles || user_can( $compte, 'yume_voir_equipe' ) || ! peut_gerer_membre( get_current_user_id(), $id ) ) {
		return '';
	}
	$pseudo = fiche_pseudo( $compte->display_name );
	$html   = '<form class="yn-comptes__fiche-ajout" id="' . esc_attr( FICHE_CIBLE_AJOUT ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"';
	/* translators: %s : pseudo */
	$html .= ' aria-label="' . esc_attr( sprintf( __( 'Ajouter %s à l’équipe', 'yume-core' ), $pseudo ) ) . '">';
	$html .= '<input type="hidden" name="action" value="yume_equipe_membres"><input type="hidden" name="op" value="ajout">';
	$html .= '<input type="hidden" name="user_id" value="' . $id . '">';
	$html .= '<input type="hidden" name="yn_retour" value="' . esc_url( url_comptes( array( 'compte' => $id ) ) ) . '">';
	$html .= wp_nonce_field( 'yume_membres_ajout', '_yume_nonce', true, false );
	$html .= champ_select( 'yn-comptes-fiche-role', 'role', __( 'Rôle dans l’équipe', 'yume-core' ), $roles, (string) array_key_first( $roles ) );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Ajouter à l’équipe', 'yume-core' ) . '</button></p>';
	$html .= '<p class="yn-muted yn-comptes__fiche-note">' . esc_html__( 'Le compte garde son pseudo, ses favoris et sa lecture ; il accède ensuite à l’espace équipe.', 'yume-core' ) . '</p>';
	return $html . zone_retour( $retour ) . '</form>';
}

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Retour du formulaire « Ajouter à l'équipe » (après la redirection de Membres et rôles, qui
 * ajoute yume_membres=ok|erreur à l'adresse de la fiche) ; null sinon. Le message est lu une
 * seule fois (Planning\retour_formulaire()).
 */
function fiche_retour_ajout(): ?array {
	if ( '' === get_cle( 'yume_membres' ) ) {
		return null;
	}
	$retour = retour_formulaire( get_current_user_id() );
	return is_array( $retour ) && FICHE_CIBLE_AJOUT === ( $retour['cible'] ?? '' ) && isset( $retour['type'], $retour['message'] ) ? $retour : null;
}

/**
 * Fiche d'un compte (contenu de la colonne principale de la vue « Comptes inscrits », sans la
 * navigation de l'espace équipe) : lien de retour à la liste, tête, sections. Journalise la
 * consultation d'un compte existant.
 *
 * @param array $etat État de la vue : compte (ID) ; paramètres de la liste (clés de
 *                    args_comptes(), directement ou sous la clé « liste ») pour le lien de retour.
 */
function rendu_fiche( array $etat ): string {
	if ( ! fiche_planning_pret() ) {
		return '';
	}
	$liste = is_array( $etat['liste'] ?? null ) ? $etat['liste'] : $etat;
	$liste = array_intersect_key( $liste, args_comptes( array() ) );
	$html  = '<div class="yn-comptes__fiche">';
	$html .= '<p class="yn-comptes__fiche-retour"><a href="' . esc_url( url_comptes( $liste ) ) . '"><span aria-hidden="true">←</span> ' . esc_html__( 'Comptes inscrits', 'yume-core' ) . '</a></p>';

	$acteur = get_current_user_id();
	if ( ! peut_voir_comptes( $acteur ) ) {
		$html .= '<div class="yn-card yn-team__acces"><h2>' . esc_html__( 'Réservé aux gérants', 'yume-core' ) . '</h2>';
		return $html . '<p>' . esc_html__( 'Seuls les gérants et les administrateurs peuvent consulter les comptes inscrits.', 'yume-core' ) . '</p></div></div>';
	}

	$id     = isset( $etat['compte'] ) && is_scalar( $etat['compte'] ) ? absint( $etat['compte'] ) : 0;
	$compte = $id > 0 ? get_userdata( $id ) : false;
	if ( ! $compte instanceof \WP_User ) {
		return $html . '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Ce compte n’existe plus.', 'yume-core' ) . '</p></div></div>';
	}
	if ( wp_style_is( VUE_STYLE, 'registered' ) ) {
		wp_enqueue_style( VUE_STYLE );
	}

	$fiche    = vue_donnees( 'fiche_compte', array( $id ) );
	$fiche    = is_array( $fiche ) ? $fiche : array();
	$identite = array_merge( fiche_identite_compte( $compte ), is_array( $fiche['identite'] ?? null ) ? $fiche['identite'] : array() );

	$identite['id'] = $id;

	journaliser( 'fiche', $acteur, $id );
	/**
	 * Une fiche de compte vient d'être affichée (consultation journalisée).
	 *
	 * @param int $compte_id Compte consulté.
	 * @param int $acteur    Compte qui consulte.
	 */
	do_action( 'yume_comptes_fiche_consultee', $id, $acteur );

	$html .= fiche_tete( $identite );
	$html .= '<div class="yn-comptes__fiche-haut">' . fiche_section_identite( $identite ) . fiche_section_actions( $compte, fiche_retour_ajout() ) . '</div>';
	$html .= fiche_section_lecture( $fiche['lecture'] ?? null );
	$html .= '<div class="yn-comptes__fiche-duo">' . fiche_section_favoris( $fiche['favoris'] ?? null, $fiche['alertes'] ?? null ) . fiche_section_listes( $fiche['listes'] ?? null ) . '</div>';
	$html .= '<div class="yn-comptes__fiche-grille">';
	$html .= fiche_section_notes( $fiche['notes'] ?? null );
	$html .= fiche_section_commentaires( $fiche['commentaires'] ?? null, $id );
	$html .= fiche_section_coquilles( $fiche['coquilles'] ?? null );
	$html .= fiche_section_notifications( $fiche['notifications'] ?? null );
	$html .= '</div>';
	return $html . fiche_pied() . '</div>';
}
