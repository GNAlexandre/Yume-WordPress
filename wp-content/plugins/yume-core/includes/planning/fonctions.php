<?php
/**
 * Constantes et fonctions internes du module planning : temps (heure de Paris), dates en
 * français, lecture et normalisation des données de planning d'un tome, utilisateurs.
 *
 * Réservé au module planning et à ses tests : les autres modules passent par api.php.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/** Table du journal du planning (sans préfixe). */
const TABLE_JOURNAL = 'yume_planning_journal';

/** Table de la file de notifications (sans préfixe). */
const TABLE_NOTIFICATIONS = 'yume_notifications';

/** Version du schéma des tables du module. */
const VERSION_SCHEMA = '1';

/** Option : version du schéma installée. */
const OPTION_SCHEMA = 'yume_planning_db_version';

/** Option : derniers échecs d'envoi (Discord, e-mail), pour les gérants. */
const OPTION_ECHECS = 'yume_planning_echecs';

/** Option : date (Y-m-d, Paris) du dernier récapitulatif hebdomadaire. */
const OPTION_DERNIER_DIGEST = 'yume_planning_dernier_digest';

/** Option : horodatage de la dernière purge de la file d'e-mails. */
const OPTION_DERNIERE_PURGE = 'yume_notifications_derniere_purge';

/** Méta de tome : date GMT du dernier rappel (anti-répétition). */
const META_DERNIER_RAPPEL = '_yume_dernier_rappel';

/** Événement cron quotidien des rappels. */
const HOOK_RAPPELS = 'yume_planning_rappels';

/** Événement cron hebdomadaire du récapitulatif des gérants. */
const HOOK_DIGEST = 'yume_planning_digest';

/** Événement cron d'envoi de la file d'e-mails (toutes les 5 minutes). */
const HOOK_ENVOI = 'yume_notifications_envoyer';

/** Récurrence personnalisée de l'envoi des e-mails. */
const RECURRENCE_ENVOI = 'yume_5_minutes';

/** Étapes de travail d'un tome, dans l'ordre. */
const ETAPES_TRAVAIL = array( 'traduction', 'relecture', 'edition' );

/** Nombre maximal de tentatives d'envoi d'un e-mail. */
const TENTATIVES_MAX = 3;

/*
 * -----------------------------------------------------------------------------
 * Tables
 * -----------------------------------------------------------------------------
 */

/**
 * Nom complet de la table du journal.
 */
function table_journal(): string {
	global $wpdb;
	return $wpdb->prefix . TABLE_JOURNAL;
}

/**
 * Nom complet de la table des notifications.
 */
function table_notifications(): string {
	global $wpdb;
	return $wpdb->prefix . TABLE_NOTIFICATIONS;
}

/*
 * -----------------------------------------------------------------------------
 * Temps
 * -----------------------------------------------------------------------------
 */

/**
 * Horodatage courant. Le filtre permet aux tests de simuler une heure donnée.
 */
function maintenant(): int {
	/**
	 * Filtre l'horodatage « maintenant » du module planning (tests, simulations).
	 *
	 * @param int $ts Horodatage Unix.
	 */
	return (int) apply_filters( 'yume_planning_maintenant', time() );
}

/**
 * Fuseau des rappels et des dates affichées : Europe/Paris (contrat §6).
 */
function fuseau(): \DateTimeZone {
	/**
	 * Filtre le fuseau horaire du planning.
	 *
	 * @param string $fuseau Identifiant IANA.
	 */
	$nom = (string) apply_filters( 'yume_planning_fuseau', 'Europe/Paris' );
	try {
		return new \DateTimeZone( $nom );
	} catch ( \Exception $e ) {
		return new \DateTimeZone( 'Europe/Paris' );
	}
}

/**
 * Date-heure GMT « Y-m-d H:i:s » d'un horodatage (maintenant par défaut).
 *
 * @param int $ts Horodatage (0 = maintenant()).
 */
function gmt( int $ts = 0 ): string {
	return gmdate( 'Y-m-d H:i:s', $ts > 0 ? $ts : maintenant() );
}

/**
 * Horodatage d'une date-heure GMT « Y-m-d H:i:s » (0 si invalide ou vide).
 *
 * @param mixed $gmt Date-heure GMT.
 */
function ts_gmt( $gmt ): int {
	if ( ! is_string( $gmt ) || '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
		return 0;
	}
	$ts = strtotime( $gmt . ' UTC' );
	return false === $ts ? 0 : (int) $ts;
}

/**
 * Date locale (Paris) « Y-m-d » d'un horodatage.
 *
 * @param int $ts Horodatage (0 = maintenant()).
 */
function date_locale( int $ts = 0 ): string {
	return ( new \DateTimeImmutable( '@' . ( $ts > 0 ? $ts : maintenant() ) ) )->setTimezone( fuseau() )->format( 'Y-m-d' );
}

/**
 * Horodatage de minuit (Paris) d'une date « Y-m-d » (0 si invalide).
 *
 * @param mixed $ymd Date.
 */
function ts_date( $ymd ): int {
	if ( ! valider_date( $ymd ) ) {
		return 0;
	}
	$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, fuseau() );
	return $d ? $d->getTimestamp() : 0;
}

/**
 * Écart en jours calendaires (Paris) entre deux dates « Y-m-d » : positif si $a est après $de.
 *
 * @param string $de Date de départ.
 * @param string $a  Date d'arrivée.
 */
function ecart_jours( string $de, string $a ): int {
	$d1 = \DateTimeImmutable::createFromFormat( '!Y-m-d', $de, new \DateTimeZone( 'UTC' ) );
	$d2 = \DateTimeImmutable::createFromFormat( '!Y-m-d', $a, new \DateTimeZone( 'UTC' ) );
	if ( ! $d1 || ! $d2 ) {
		return 0;
	}
	return (int) round( ( $d2->getTimestamp() - $d1->getTimestamp() ) / DAY_IN_SECONDS );
}

/**
 * Date « Y-m-d » valide ?
 *
 * @param mixed $v Valeur.
 */
function valider_date( $v ): bool {
	if ( ! is_string( $v ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/*
 * -----------------------------------------------------------------------------
 * Dates en français (indépendantes de la langue installée)
 * -----------------------------------------------------------------------------
 */

/**
 * Noms des jours (0 = dimanche), complets et abrégés.
 *
 * @param bool $court Abrégés.
 * @return string[]
 */
function noms_jours( bool $court = false ): array {
	if ( $court ) {
		return array(
			__( 'dim.', 'yume-core' ),
			__( 'lun.', 'yume-core' ),
			__( 'mar.', 'yume-core' ),
			__( 'mer.', 'yume-core' ),
			__( 'jeu.', 'yume-core' ),
			__( 'ven.', 'yume-core' ),
			__( 'sam.', 'yume-core' ),
		);
	}
	return array(
		__( 'dimanche', 'yume-core' ),
		__( 'lundi', 'yume-core' ),
		__( 'mardi', 'yume-core' ),
		__( 'mercredi', 'yume-core' ),
		__( 'jeudi', 'yume-core' ),
		__( 'vendredi', 'yume-core' ),
		__( 'samedi', 'yume-core' ),
	);
}

/**
 * Noms des mois (1 = janvier), complets et abrégés.
 *
 * @param bool $court Abrégés.
 * @return array<int,string>
 */
function noms_mois( bool $court = false ): array {
	if ( $court ) {
		return array(
			1  => __( 'janv.', 'yume-core' ),
			2  => __( 'févr.', 'yume-core' ),
			3  => __( 'mars', 'yume-core' ),
			4  => __( 'avr.', 'yume-core' ),
			5  => __( 'mai', 'yume-core' ),
			6  => __( 'juin', 'yume-core' ),
			7  => __( 'juil.', 'yume-core' ),
			8  => __( 'août', 'yume-core' ),
			9  => __( 'sept.', 'yume-core' ),
			10 => __( 'oct.', 'yume-core' ),
			11 => __( 'nov.', 'yume-core' ),
			12 => __( 'déc.', 'yume-core' ),
		);
	}
	return array(
		1  => __( 'janvier', 'yume-core' ),
		2  => __( 'février', 'yume-core' ),
		3  => __( 'mars', 'yume-core' ),
		4  => __( 'avril', 'yume-core' ),
		5  => __( 'mai', 'yume-core' ),
		6  => __( 'juin', 'yume-core' ),
		7  => __( 'juillet', 'yume-core' ),
		8  => __( 'août', 'yume-core' ),
		9  => __( 'septembre', 'yume-core' ),
		10 => __( 'octobre', 'yume-core' ),
		11 => __( 'novembre', 'yume-core' ),
		12 => __( 'décembre', 'yume-core' ),
	);
}

/**
 * Formate un horodatage en français, heure de Paris.
 *
 * Jetons : D (jour abrégé), l (jour), j, d, M (mois abrégé), F (mois), n, m, Y, H, i ;
 * « \ » protège le caractère ASCII suivant.
 *
 * @param int    $ts     Horodatage.
 * @param string $format Format.
 */
function format_fr( int $ts, string $format ): string {
	$d   = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( fuseau() );
	$out = '';
	$len = strlen( $format );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $format[ $i ];
		if ( '\\' === $c && $i + 1 < $len ) {
			$out .= $format[ ++$i ];
			continue;
		}
		switch ( $c ) {
			case 'D':
				$out .= noms_jours( true )[ (int) $d->format( 'w' ) ];
				break;
			case 'l':
				$out .= noms_jours()[ (int) $d->format( 'w' ) ];
				break;
			case 'M':
				$out .= noms_mois( true )[ (int) $d->format( 'n' ) ];
				break;
			case 'F':
				$out .= noms_mois()[ (int) $d->format( 'n' ) ];
				break;
			case 'j':
			case 'd':
			case 'n':
			case 'm':
			case 'Y':
			case 'H':
			case 'i':
				$out .= $d->format( $c );
				break;
			default:
				$out .= $c;
		}
	}
	return $out;
}

/**
 * Met en majuscule la première lettre (UTF-8).
 *
 * @param string $texte Texte.
 */
function majuscule( string $texte ): string {
	return mb_strtoupper( mb_substr( $texte, 0, 1 ) ) . mb_substr( $texte, 1 );
}

/**
 * Date cible lisible : précise (« sam. 26 sept. ») à moins de trois semaines, sinon
 * indicative (« mi-octobre », « début novembre 2027 ») comme sur le planning public.
 *
 * @param string $date    Date « Y-m-d » (vide : non planifié).
 * @param bool   $precise Toujours afficher le jour exact.
 */
function date_cible_lisible( string $date, bool $precise = false ): string {
	$ts = ts_date( $date );
	if ( ! $ts ) {
		return __( 'non planifié', 'yume-core' );
	}
	$ecart   = ecart_jours( date_locale(), $date );
	$annee   = (int) substr( $date, 0, 4 );
	$courant = (int) substr( date_locale(), 0, 4 );
	if ( $precise || $ecart <= 21 ) {
		return format_fr( $ts, $annee !== $courant ? 'D j M Y' : 'D j M' );
	}
	$jour = (int) substr( $date, 8, 2 );
	$mois = noms_mois()[ (int) substr( $date, 5, 2 ) ];
	if ( $jour <= 10 ) {
		/* translators: %s : mois */
		$texte = sprintf( __( 'début %s', 'yume-core' ), $mois );
	} elseif ( $jour <= 20 ) {
		/* translators: %s : mois */
		$texte = sprintf( __( 'mi-%s', 'yume-core' ), $mois );
	} else {
		/* translators: %s : mois */
		$texte = sprintf( __( 'fin %s', 'yume-core' ), $mois );
	}
	return $annee !== $courant ? $texte . ' ' . $annee : $texte;
}

/**
 * Durée écoulée en français (« à l'instant », « il y a 5 min », « il y a 2 h », « hier »,
 * « il y a 9 j », puis la date).
 *
 * @param int $ts Horodatage passé.
 */
function il_y_a( int $ts ): string {
	if ( $ts <= 0 ) {
		return __( 'jamais', 'yume-core' );
	}
	$ecart = maintenant() - $ts;
	if ( $ecart < MINUTE_IN_SECONDS ) {
		return __( 'à l’instant', 'yume-core' );
	}
	if ( $ecart < HOUR_IN_SECONDS ) {
		/* translators: %d : minutes */
		return sprintf( __( 'il y a %d min', 'yume-core' ), (int) floor( $ecart / MINUTE_IN_SECONDS ) );
	}
	$jours = ecart_jours( date_locale( $ts ), date_locale() );
	if ( $jours <= 0 || $ecart < 6 * HOUR_IN_SECONDS ) {
		/* translators: %d : heures */
		return sprintf( __( 'il y a %d h', 'yume-core' ), max( 1, (int) floor( $ecart / HOUR_IN_SECONDS ) ) );
	}
	if ( 1 === $jours ) {
		return __( 'hier', 'yume-core' );
	}
	if ( $jours <= 60 ) {
		/* translators: %d : jours */
		return sprintf( __( 'il y a %d j', 'yume-core' ), $jours );
	}
	return format_fr( $ts, 'j M Y' );
}

/**
 * Pourcentage affiché à la française (« 62 % » avec espace insécable).
 *
 * @param int $pct Pourcentage.
 */
function pct( int $pct ): string {
	return $pct . "\u{00A0}%";
}

/*
 * -----------------------------------------------------------------------------
 * Données de planning d'un tome
 * -----------------------------------------------------------------------------
 */

/**
 * Avancement normalisé {traduction, relecture, edition} (entiers 0–100).
 *
 * @param mixed $v Valeur.
 * @return array{traduction:int,relecture:int,edition:int}
 */
function norm_avancement( $v ): array {
	$v   = is_array( $v ) ? $v : ( is_object( $v ) ? (array) $v : array() );
	$out = array();
	foreach ( ETAPES_TRAVAIL as $etape ) {
		$n             = isset( $v[ $etape ] ) && is_numeric( $v[ $etape ] ) ? (int) round( (float) $v[ $etape ] ) : 0;
		$out[ $etape ] = max( 0, min( 100, $n ) );
	}
	return $out;
}

/**
 * Responsables normalisés {traduction, relecture, edition} (IDs, 0 = personne).
 *
 * @param mixed $v Valeur.
 * @return array{traduction:int,relecture:int,edition:int}
 */
function norm_responsables( $v ): array {
	$v   = is_array( $v ) ? $v : ( is_object( $v ) ? (array) $v : array() );
	$out = array();
	foreach ( ETAPES_TRAVAIL as $etape ) {
		$out[ $etape ] = isset( $v[ $etape ] ) && is_numeric( $v[ $etape ] ) ? max( 0, (int) $v[ $etape ] ) : 0;
	}
	return $out;
}

/**
 * Données brutes du planning d'un tome.
 *
 * @param int $tome_id Tome.
 * @return array{etape:string,avancement:array,responsables:array,date_cible:string,bloque:bool,bloque_raison:string,derniere_maj:string,maj_par:int,note_equipe:string}
 */
function donnees_tome( int $tome_id ): array {
	$etape = (string) get_post_meta( $tome_id, 'yume_etape', true );
	if ( ! array_key_exists( $etape, yume_etapes() ) ) {
		$etape = 'a_faire';
	}
	$date = (string) get_post_meta( $tome_id, 'yume_date_cible', true );
	return array(
		'etape'         => $etape,
		'avancement'    => norm_avancement( get_post_meta( $tome_id, 'yume_avancement', true ) ),
		'responsables'  => norm_responsables( get_post_meta( $tome_id, 'yume_responsables', true ) ),
		'date_cible'    => valider_date( $date ) ? $date : '',
		'bloque'        => (bool) get_post_meta( $tome_id, 'yume_bloque', true ),
		'bloque_raison' => (string) get_post_meta( $tome_id, 'yume_bloque_raison', true ),
		'derniere_maj'  => (string) get_post_meta( $tome_id, 'yume_derniere_maj', true ),
		'maj_par'       => (int) get_post_meta( $tome_id, 'yume_maj_par', true ),
		'note_equipe'   => (string) get_post_meta( $tome_id, 'yume_note_equipe', true ),
	);
}

/**
 * Rang d'une étape dans le flux (a_faire 0 … publie 4).
 *
 * @param string $etape Étape.
 */
function rang_etape( string $etape ): int {
	$rang = array_search( $etape, array_keys( yume_etapes() ), true );
	return false === $rang ? 0 : (int) $rang;
}

/**
 * Étape de travail en cours (« à faire » = traduction ; vide une fois publié).
 *
 * @param string $etape Étape du planning.
 */
function etape_de_travail( string $etape ): string {
	if ( 'publie' === $etape ) {
		return '';
	}
	return in_array( $etape, ETAPES_TRAVAIL, true ) ? $etape : 'traduction';
}

/**
 * Libellé d'une étape de travail en minuscules (« relecture »).
 *
 * @param string $etape Étape.
 */
function libelle_etape_min( string $etape ): string {
	$etapes = yume_etapes();
	return isset( $etapes[ $etape ] ) ? mb_strtolower( $etapes[ $etape ] ) : $etape;
}

/**
 * Horodatage de la dernière activité d'un tome : yume_derniere_maj, sinon dernière
 * modification du contenu (tome migré ou créé sans planning).
 *
 * @param int $tome_id Tome.
 */
function ts_derniere_activite( int $tome_id ): int {
	$ts = ts_gmt( (string) get_post_meta( $tome_id, 'yume_derniere_maj', true ) );
	if ( $ts ) {
		return $ts;
	}
	$post = get_post( $tome_id );
	if ( ! $post ) {
		return 0;
	}
	$ts = ts_contenu( $post, 'post_modified' );
	return $ts ? $ts : ts_contenu( $post, 'post_date' );
}

/**
 * Horodatage d'une date d'un contenu (post_date ou post_modified). Les brouillons n'ont pas
 * de date GMT : la date locale du site est alors convertie.
 *
 * @param \WP_Post $post  Contenu.
 * @param string   $champ 'post_date' ou 'post_modified'.
 */
function ts_contenu( \WP_Post $post, string $champ ): int {
	$gmt = $champ . '_gmt';
	$ts  = ts_gmt( (string) $post->$gmt );
	if ( ! $ts ) {
		$locale = (string) $post->$champ;
		if ( '' !== $locale && ! str_starts_with( $locale, '0000-00-00' ) ) {
			$ts = ts_gmt( get_gmt_from_date( $locale ) );
		}
	}
	return $ts;
}

/**
 * Seuil d'inactivité (jours) au-delà duquel un tome est en retard.
 */
function seuil_inactivite(): int {
	$jours = function_exists( 'yume_setting' ) ? yume_setting( 'rappel_jours_sans_maj', 14 ) : 14;
	return max( 1, (int) $jours );
}

/**
 * Analyse de l'état d'un tome.
 *
 * @param int $tome_id Tome.
 * @return array{etat:string,motif:string,jours:int}
 *   motif : 'date' (date cible dépassée de « jours »), 'inactivite' (sans mise à jour depuis
 *   « jours »), ou vide.
 */
function analyser_etat( int $tome_id ): array {
	$d = donnees_tome( $tome_id );
	if ( 'publie' === $d['etape'] ) {
		return array(
			'etat'  => 'publie',
			'motif' => '',
			'jours' => 0,
		);
	}
	if ( $d['bloque'] ) {
		return array(
			'etat'  => 'bloque',
			'motif' => '',
			'jours' => 0,
		);
	}
	$aujourdhui = date_locale();
	if ( '' !== $d['date_cible'] && $d['date_cible'] < $aujourdhui ) {
		return array(
			'etat'  => 'en_retard',
			'motif' => 'date',
			'jours' => ecart_jours( $d['date_cible'], $aujourdhui ),
		);
	}
	$activite = ts_derniere_activite( $tome_id );
	$ecart    = $activite ? maintenant() - $activite : 0;
	if ( $activite && $ecart > seuil_inactivite() * DAY_IN_SECONDS ) {
		return array(
			'etat'  => 'en_retard',
			'motif' => 'inactivite',
			'jours' => (int) floor( $ecart / DAY_IN_SECONDS ),
		);
	}
	return array(
		'etat'  => 'a_lheure',
		'motif' => '',
		'jours' => 0,
	);
}

/**
 * États du planning (slug => libellé).
 *
 * @return array<string,string>
 */
function etats(): array {
	return array(
		'a_lheure'  => __( 'À l’heure', 'yume-core' ),
		'en_retard' => __( 'En retard', 'yume-core' ),
		'bloque'    => __( 'Bloqué', 'yume-core' ),
		'publie'    => __( 'Publié', 'yume-core' ),
	);
}

/**
 * Icône (non colorée) d'un état : un état ne se lit jamais à la seule couleur.
 *
 * @param string $etat État.
 */
function icone_etat( string $etat ): string {
	$icones = array(
		'a_lheure'  => '●',
		'en_retard' => '▲',
		'bloque'    => '■',
		'publie'    => '✓',
	);
	return $icones[ $etat ] ?? '●';
}

/**
 * Variante de pastille (.yn-chip--*) d'un état.
 *
 * @param string $etat État.
 */
function variante_etat( string $etat ): string {
	$variantes = array(
		'a_lheure'  => 'ok',
		'en_retard' => 'warn',
		'bloque'    => 'err',
		'publie'    => 'ok',
	);
	return $variantes[ $etat ] ?? 'info';
}

/**
 * Sous-titre d'un tome : partie du titre après l'œuvre et le libellé
 * (« Secrets of the Silent Witch — Arc 7 : Tournoi d'échecs » → « Tournoi d'échecs »).
 *
 * @param int $tome_id Tome.
 */
function sous_titre_tome( int $tome_id ): string {
	$titre     = html_entity_decode( (string) get_the_title( $tome_id ), ENT_QUOTES, 'UTF-8' );
	$oeuvre_id = yume_get_oeuvre_id( $tome_id );
	if ( $oeuvre_id ) {
		$oeuvre = html_entity_decode( (string) get_the_title( $oeuvre_id ), ENT_QUOTES, 'UTF-8' );
		if ( '' !== $oeuvre && str_starts_with( $titre, $oeuvre ) ) {
			$titre = ltrim( substr( $titre, strlen( $oeuvre ) ), " \t-—–:·" );
		}
	}
	$libelle = yume_libelle_tome( $tome_id );
	if ( '' !== $libelle && 0 === mb_stripos( $titre, $libelle ) ) {
		$titre = mb_substr( $titre, mb_strlen( $libelle ) );
	}
	$titre = trim( $titre, " \t-—–:·" );
	return ( '' === $titre || 0 === strcasecmp( $titre, $libelle ) ) ? '' : $titre;
}

/**
 * Chapitres d'un tome : publiés et total (hors corbeille).
 *
 * @param int $tome_id Tome.
 * @return array{publies:int,total:int}
 */
function compte_chapitres( int $tome_id ): array {
	$tous    = yume_get_chapitres( $tome_id, array( 'status' => 'any' ) );
	$publies = 0;
	foreach ( $tous as $chapitre ) {
		if ( 'publish' === $chapitre->post_status ) {
			++$publies;
		}
	}
	return array(
		'publies' => $publies,
		'total'   => count( $tous ),
	);
}

/*
 * -----------------------------------------------------------------------------
 * Utilisateurs
 * -----------------------------------------------------------------------------
 */

/**
 * Nom affiché (pseudo) d'un utilisateur ; « Système » pour 0.
 *
 * @param int $user_id Utilisateur.
 */
function nom_utilisateur( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return __( 'Système', 'yume-core' );
	}
	$user = get_userdata( $user_id );
	return $user ? (string) $user->display_name : __( 'Ancien membre', 'yume-core' );
}

/**
 * Membres de l'équipe (capacité yume_voir_equipe), ID => pseudo, par ordre alphabétique.
 *
 * @return array<int,string>
 */
function membres_equipe(): array {
	$liste = array();
	foreach (
		get_users(
			array(
				'capability' => 'yume_voir_equipe',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'display_name' ),
			)
		) as $u
	) {
		$liste[ (int) $u->ID ] = (string) $u->display_name;
	}
	return $liste;
}

/**
 * L'utilisateur fait-il partie de l'équipe ?
 *
 * @param int $user_id Utilisateur.
 */
function est_membre( int $user_id ): bool {
	return $user_id > 0 && user_can( $user_id, 'yume_voir_equipe' );
}

/**
 * Gérants destinataires des signalements et du récapitulatif : rôles yume_gerant et
 * administrator.
 *
 * @return int[]
 */
function gerants(): array {
	$ids = get_users(
		array(
			'role__in' => array( 'yume_gerant', 'administrator' ),
			'fields'   => 'ID',
			'orderby'  => 'ID',
		)
	);
	/**
	 * Filtre les destinataires des signalements et du récapitulatif hebdomadaire.
	 *
	 * @param int[] $ids IDs utilisateurs.
	 */
	return array_values( array_unique( array_map( 'intval', (array) apply_filters( 'yume_planning_gerants', $ids ) ) ) );
}

/**
 * Nom des rôles manquants (« aucun relecteur assigné »).
 *
 * @param string $etape Étape.
 */
function libelle_role_manquant( string $etape ): string {
	$libelles = array(
		'traduction' => __( 'aucun traducteur assigné', 'yume-core' ),
		'relecture'  => __( 'aucun relecteur assigné', 'yume-core' ),
		'edition'    => __( 'aucun éditeur assigné', 'yume-core' ),
	);
	return $libelles[ $etape ] ?? '';
}

/**
 * Sommes-nous dans l'aperçu serveur de l'éditeur de blocs ?
 */
function est_apercu_editeur(): bool {
	if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	return str_contains( rawurldecode( $uri ), '/wp/v2/block-renderer/' );
}
