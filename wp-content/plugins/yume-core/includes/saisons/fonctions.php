<?php
/**
 * Thèmes saisonniers : définitions, calendrier, résolution de la saison active, aperçu,
 * événement yume_saison_changee et purge des caches (docs/07-themes-saisonniers.md §2 à §5, §7).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Saisons;

defined( 'ABSPATH' ) || exit;

/** Option : dernière saison calculée (hors aperçu), comparée pour émettre yume_saison_changee. */
const OPTION_COURANTE = 'yume_saison_courante';

/** Tâche planifiée quotidienne (00:05, heure du site). */
const HOOK_QUOTIDIEN = 'yume_saisons_quotidien';

/** Paramètre d'URL de l'aperçu (?yn-saison=<slug|aucun>). */
const PARAM_APERCU = 'yn-saison';

/** Valeur de l'aperçu qui affiche le thème de base. */
const APERCU_AUCUN = 'aucun';

/** Capacité qui ouvre l'aperçu (celle des réglages). */
const CAPACITE_APERCU = 'yume_reglages';

/** Ordre de priorité des lignes du calendrier (figé) : les fêtes avant les saisons. */
const ORDRE = array( 'noel', 'halloween', 'printemps', 'ete', 'automne', 'hiver' );

/** Modes de pilotage (réglage saison_mode). */
const MODES = array( 'auto', 'manuel', 'aucun' );

/*
 * -----------------------------------------------------------------------------
 * Définitions et calendrier
 * -----------------------------------------------------------------------------
 */

/**
 * Les six thèmes saisonniers, dans l'ordre de priorité (§2 du cahier des charges).
 *
 * @return array<string,array{nom:string,type:string,logo:string}> slug => définition.
 */
function definitions(): array {
	$noms  = did_action( 'init' )
		? array(
			'noel'      => __( 'Noël', 'yume-core' ),
			'halloween' => __( 'Halloween', 'yume-core' ),
			'printemps' => __( 'Printemps', 'yume-core' ),
			'ete'       => __( 'Été', 'yume-core' ),
			'automne'   => __( 'Automne', 'yume-core' ),
			'hiver'     => __( 'Hiver', 'yume-core' ),
		)
		: array(
			'noel'      => 'Noël',
			'halloween' => 'Halloween',
			'printemps' => 'Printemps',
			'ete'       => 'Été',
			'automne'   => 'Automne',
			'hiver'     => 'Hiver',
		);
	$fetes = array( 'noel', 'halloween' );
	$defs  = array();
	foreach ( ORDRE as $slug ) {
		$defs[ $slug ] = array(
			'nom'  => $noms[ $slug ],
			'type' => in_array( $slug, $fetes, true ) ? 'fete' : 'saison',
			'logo' => $slug . '.webp',
		);
	}
	return $defs;
}

/**
 * Le slug désigne-t-il un des six thèmes ?
 *
 * @param mixed $slug Slug.
 */
function slug_connu( $slug ): bool {
	return is_string( $slug ) && in_array( $slug, ORDRE, true );
}

/**
 * Calendrier par défaut (§3), dans l'ordre de priorité.
 *
 * @return array<int,array{theme:string,debut:string,fin:string,actif:bool}>
 */
function calendrier_par_defaut(): array {
	$dates  = array(
		'noel'      => array( '01/12', '31/12' ),
		'halloween' => array( '01/10', '31/10' ),
		'printemps' => array( '20/03', '20/06' ),
		'ete'       => array( '21/06', '21/09' ),
		'automne'   => array( '22/09', '20/12' ),
		'hiver'     => array( '21/12', '19/03' ),
	);
	$lignes = array();
	foreach ( ORDRE as $slug ) {
		$lignes[] = array(
			'theme' => $slug,
			'debut' => $dates[ $slug ][0],
			'fin'   => $dates[ $slug ][1],
			'actif' => true,
		);
	}
	return $lignes;
}

/**
 * Normalise une date « JJ/MM » (sans année ; séparateurs / . - acceptés) : « 01/12 », ou ''
 * si la saisie n'est pas une date valide. Le 29/02 est accepté (il ne compte que les années
 * bissextiles).
 *
 * @param mixed $saisie Saisie.
 */
function date_valide( $saisie ): string {
	if ( ! is_scalar( $saisie ) ) {
		return '';
	}
	if ( ! preg_match( '#^\s*(\d{1,2})\s*[/.\-]\s*(\d{1,2})\s*$#', (string) $saisie, $m ) ) {
		return '';
	}
	$jour = (int) $m[1];
	$mois = (int) $m[2];
	if ( ! checkdate( $mois, $jour, 2024 ) ) {
		return '';
	}
	return sprintf( '%02d/%02d', $jour, $mois );
}

/**
 * Rang d'une date « JJ/MM » dans l'année (mois × 100 + jour), pour comparer les bornes.
 *
 * @param string $date Date normalisée.
 */
function rang_date( string $date ): int {
	list( $jour, $mois ) = array_map( 'intval', explode( '/', $date ) + array( 0, 0 ) );
	return $mois * 100 + $jour;
}

/**
 * La période [début, fin] (bornes incluses) contient-elle ce rang ? Une période dont le début
 * est après la fin chevauche le Nouvel An (ex. Hiver 21/12 → 19/03).
 *
 * @param string $debut Début « JJ/MM ».
 * @param string $fin   Fin « JJ/MM ».
 * @param int    $rang  Rang du jour (mois × 100 + jour).
 */
function periode_contient( string $debut, string $fin, int $rang ): bool {
	$d = rang_date( $debut );
	$f = rang_date( $fin );
	return $d <= $f ? ( $rang >= $d && $rang <= $f ) : ( $rang >= $d || $rang <= $f );
}

/**
 * Lignes d'un calendrier indexées par slug. Accepte la forme enregistrée (liste de lignes
 * {theme, debut, fin, actif}) et celle du formulaire (slug => {debut, fin, actif}). Les lignes
 * de thème inconnu sont ignorées ; à slug répété, la première l'emporte.
 *
 * @param mixed $valeur Calendrier.
 * @return array<string,array<string,mixed>>
 */
function indexer_lignes( $valeur ): array {
	$lignes = array();
	foreach ( is_array( $valeur ) ? $valeur : array() as $cle => $ligne ) {
		if ( ! is_array( $ligne ) ) {
			continue;
		}
		$slug = isset( $ligne['theme'] ) && is_string( $ligne['theme'] ) ? sanitize_key( $ligne['theme'] ) : ( is_string( $cle ) ? sanitize_key( $cle ) : '' );
		if ( slug_connu( $slug ) && ! isset( $lignes[ $slug ] ) ) {
			$lignes[ $slug ] = $ligne;
		}
	}
	return $lignes;
}

/**
 * Booléen d'une case (valeurs de formulaire « 1 », « 0 », « on », vrai, faux).
 *
 * @param mixed $valeur Valeur.
 * @param bool  $defaut Valeur si la saisie n'est pas scalaire.
 */
function booleen( $valeur, bool $defaut ): bool {
	return is_scalar( $valeur ) ? rest_sanitize_boolean( $valeur ) : $defaut;
}

/**
 * Calendrier complet : les six lignes dans l'ordre de priorité, la valeur enregistrée fusionnée
 * avec les défauts (ligne absente ou date invalide → défaut du §3).
 *
 * @param mixed $valeur Calendrier enregistré.
 * @return array<int,array{theme:string,debut:string,fin:string,actif:bool}>
 */
function calendrier_normalise( $valeur ): array {
	$lignes = indexer_lignes( $valeur );
	$sortie = array();
	foreach ( calendrier_par_defaut() as $defaut ) {
		$ligne    = $lignes[ $defaut['theme'] ] ?? array();
		$debut    = date_valide( $ligne['debut'] ?? '' );
		$fin      = date_valide( $ligne['fin'] ?? '' );
		$sortie[] = array(
			'theme' => $defaut['theme'],
			'debut' => '' !== $debut ? $debut : $defaut['debut'],
			'fin'   => '' !== $fin ? $fin : $defaut['fin'],
			'actif' => array_key_exists( 'actif', $ligne ) ? booleen( $ligne['actif'], $defaut['actif'] ) : $defaut['actif'],
		);
	}
	return $sortie;
}

/**
 * Lignes exploitables d'un calendrier fourni tel quel (sans complément par les défauts) : seules
 * les lignes de thème connu et aux deux dates valides, dans l'ordre de priorité.
 *
 * @param array $calendrier Calendrier.
 * @return array<int,array{theme:string,debut:string,fin:string,actif:bool}>
 */
function lignes_valides( array $calendrier ): array {
	$lignes = indexer_lignes( $calendrier );
	$sortie = array();
	foreach ( ORDRE as $slug ) {
		if ( ! isset( $lignes[ $slug ] ) ) {
			continue;
		}
		$debut = date_valide( $lignes[ $slug ]['debut'] ?? '' );
		$fin   = date_valide( $lignes[ $slug ]['fin'] ?? '' );
		if ( '' === $debut || '' === $fin ) {
			continue;
		}
		$sortie[] = array(
			'theme' => $slug,
			'debut' => $debut,
			'fin'   => $fin,
			'actif' => array_key_exists( 'actif', $lignes[ $slug ] ) ? booleen( $lignes[ $slug ]['actif'], true ) : true,
		);
	}
	return $sortie;
}

/**
 * Thème du calendrier à une date : première ligne active dont la période contient la date (dans
 * le fuseau du site), '' si aucune.
 *
 * @param \DateTimeInterface $date       Date.
 * @param array              $calendrier Lignes (voir lignes_valides()).
 */
function saison_du_calendrier( \DateTimeInterface $date, array $calendrier ): string {
	$local = \DateTimeImmutable::createFromInterface( $date )->setTimezone( wp_timezone() );
	$rang  = (int) $local->format( 'n' ) * 100 + (int) $local->format( 'j' );
	foreach ( $calendrier as $ligne ) {
		if ( $ligne['actif'] && periode_contient( $ligne['debut'], $ligne['fin'], $rang ) ) {
			return $ligne['theme'];
		}
	}
	return '';
}

/*
 * -----------------------------------------------------------------------------
 * Réglages et résolution
 * -----------------------------------------------------------------------------
 */

/**
 * Lit un réglage Yume (module core) avec une valeur de repli explicite.
 *
 * @param string $cle    Clé.
 * @param mixed  $defaut Repli.
 * @return mixed
 */
function reglage( string $cle, $defaut ) {
	return function_exists( 'yume_setting' ) ? yume_setting( $cle, $defaut ) : $defaut;
}

/**
 * Mode de pilotage : auto (calendrier), manuel (saison_manuelle) ou aucun (thème de base).
 */
function mode(): string {
	$mode = reglage( 'saison_mode', 'auto' );
	return is_string( $mode ) && in_array( $mode, MODES, true ) ? $mode : 'auto';
}

/**
 * Thème forcé en mode manuel ('' : thème de base).
 */
function saison_manuelle(): string {
	$slug = reglage( 'saison_manuelle', '' );
	return slug_connu( $slug ) ? $slug : '';
}

/**
 * « Maintenant » dans le fuseau du site. Le filtre yume_saison_maintenant (horodatage Unix)
 * permet aux tests et aux simulations de figer la date.
 */
function maintenant(): \DateTimeImmutable {
	/**
	 * Filtre l'horodatage « maintenant » du module saisons (tests, simulations).
	 *
	 * @param int $ts Horodatage Unix.
	 */
	$ts = (int) apply_filters( 'yume_saison_maintenant', time() );
	return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
}

/**
 * Saison programmée par les réglages, hors aperçu et hors filtre yume_saison_active : mode
 * aucun → '', manuel → saison_manuelle, auto → calendrier à la date donnée (maintenant par
 * défaut). C'est elle que compare l'événement yume_saison_changee.
 *
 * @param \DateTimeInterface|null $date Date (maintenant si null).
 */
function saison_programmee( ?\DateTimeInterface $date = null ): string {
	switch ( mode() ) {
		case 'aucun':
			return '';
		case 'manuel':
			return saison_manuelle();
		default:
			return saison_du_calendrier( $date ?? maintenant(), lignes_valides( yume_saison_calendrier() ) );
	}
}

/**
 * Mémoire de yume_saison_active() pour la requête courante.
 *
 * @param string|null $valeur  Valeur à mémoriser (null : lecture).
 * @param bool        $effacer Oublie la valeur mémorisée.
 * @return string|null Valeur mémorisée, null si aucune.
 */
function memoire( ?string $valeur = null, bool $effacer = false ): ?string {
	static $memo = null;
	if ( $effacer ) {
		$memo = null;
	} elseif ( null !== $valeur ) {
		$memo = $valeur;
	}
	return $memo;
}

/**
 * Oublie la saison mémorisée (changement d'utilisateur courant, de réglages, tests).
 */
function reinitialiser(): void {
	memoire( null, true );
}
add_action( 'set_current_user', __NAMESPACE__ . '\\reinitialiser' );

/**
 * Aperçu demandé et autorisé : slug demandé, '' pour ?yn-saison=aucun, null si le paramètre est
 * absent, inconnu ou si le compte n'a pas la capacité yume_reglages (paramètre ignoré).
 */
function apercu_demande(): ?string {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- aperçu en lecture seule, réservé à une capacité.
	if ( ! isset( $_GET[ PARAM_APERCU ] ) || ! is_string( $_GET[ PARAM_APERCU ] ) ) {
		return null;
	}
	$demande = sanitize_key( wp_unslash( $_GET[ PARAM_APERCU ] ) );
	// phpcs:enable
	if ( ! is_user_logged_in() || ! current_user_can( CAPACITE_APERCU ) ) {
		return null;
	}
	if ( APERCU_AUCUN === $demande ) {
		return '';
	}
	return slug_connu( $demande ) ? $demande : null;
}

/**
 * Page d'aperçu : jamais dans le cache de page (Batcache) ni dans celui du navigateur.
 */
function exclure_du_cache(): void {
	if ( function_exists( 'batcache_cancel' ) ) {
		batcache_cancel();
	}
	if ( ! headers_sent() ) {
		nocache_headers(); // Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private.
	}
}

/**
 * En-têtes de la réponse (send_headers) : aperçu honoré → page exclue des caches avant tout
 * rendu (le thème ne lit la saison qu'une fois les en-têtes envoyés).
 */
function entetes_apercu(): void {
	if ( null !== apercu_demande() ) {
		exclure_du_cache();
	}
}
add_action( 'send_headers', __NAMESPACE__ . '\\entetes_apercu' );

/**
 * Saison active pour la requête courante (voir yume_saison_active()).
 */
function saison_active(): string {
	$memo = memoire();
	if ( null !== $memo ) {
		return $memo;
	}
	$apercu = apercu_demande();
	if ( null !== $apercu ) {
		exclure_du_cache();
	}
	$saison = null !== $apercu ? $apercu : saison_programmee();
	/**
	 * Filtre la saison active de la requête ('' : thème de base). Une valeur qui n'est pas un des
	 * six slugs est ramenée à ''.
	 *
	 * @param string $saison Slug.
	 * @param bool   $apercu Vrai si la valeur vient de l'aperçu ?yn-saison.
	 */
	$saison = apply_filters( 'yume_saison_active', $saison, null !== $apercu );
	$saison = slug_connu( $saison ) ? $saison : '';
	// Avant init, l'utilisateur courant et les réglages des modules ne sont pas encore connus.
	if ( did_action( 'init' ) ) {
		memoire( $saison );
	}
	return $saison;
}

/*
 * -----------------------------------------------------------------------------
 * Événement yume_saison_changee et purge des caches
 * -----------------------------------------------------------------------------
 */

/**
 * Compare la saison programmée (hors aperçu) à l'option yume_saison_courante ; si elle a changé,
 * met l'option à jour et émet yume_saison_changee( $nouvelle, $ancienne ). Appelée par la tâche
 * quotidienne et après l'enregistrement des réglages.
 *
 * @return bool Vrai si l'événement a été émis.
 */
function verifier_changement(): bool {
	$nouvelle = saison_programmee();
	$ancienne = get_option( OPTION_COURANTE, '' );
	$ancienne = is_string( $ancienne ) ? $ancienne : '';
	if ( $nouvelle === $ancienne ) {
		return false;
	}
	update_option( OPTION_COURANTE, $nouvelle, true );
	reinitialiser();
	/**
	 * La saison du site a changé (calendrier, mode ou thème forcé).
	 *
	 * @param string $nouvelle Nouvelle saison ('' : thème de base).
	 * @param string $ancienne Saison précédente ('' : thème de base).
	 */
	do_action( 'yume_saison_changee', $nouvelle, $ancienne );
	return true;
}
add_action( HOOK_QUOTIDIEN, __NAMESPACE__ . '\\verifier_changement' );

/**
 * Réglages qui pilotent la saison, extraits d'une valeur de l'option yume_reglages.
 *
 * @param mixed $reglages Valeur de l'option.
 * @return array<string,mixed>
 */
function reglages_saison( $reglages ): array {
	$reglages = is_array( $reglages ) ? $reglages : array();
	$sortie   = array();
	foreach ( array( 'saison_mode', 'saison_manuelle', 'saison_calendrier' ) as $cle ) {
		$sortie[ $cle ] = $reglages[ $cle ] ?? null;
	}
	return $sortie;
}

/**
 * Option yume_reglages modifiée : si le mode, le thème forcé ou le calendrier ont changé, la
 * saison mémorisée est oubliée et le changement de saison vérifié.
 *
 * @param mixed $ancien  Ancienne valeur.
 * @param mixed $nouveau Nouvelle valeur.
 */
function reglages_modifies( $ancien, $nouveau ): void {
	if ( reglages_saison( $ancien ) === reglages_saison( $nouveau ) ) {
		return;
	}
	reinitialiser();
	verifier_changement();
}
add_action( 'update_option_yume_reglages', __NAMESPACE__ . '\\reglages_modifies', 20, 2 );

/**
 * Option yume_reglages créée (premier enregistrement).
 *
 * @param string $option Nom de l'option.
 * @param mixed  $valeur Valeur.
 */
function reglages_crees( $option, $valeur ): void {
	reglages_modifies( array(), $valeur );
}
add_action( 'add_option_yume_reglages', __NAMESPACE__ . '\\reglages_crees', 20, 2 );

/**
 * Adresses dont le cache de page porte la saison et qu'il faut purger au changement : accueil,
 * pages de l'option yume_pages (bibliothèque, planning, actualités…), archive des œuvres.
 *
 * @return string[]
 */
function adresses_a_purger(): array {
	$adresses = array( home_url( '/' ) );
	if ( function_exists( 'yume_url_page' ) ) {
		$pages = get_option( 'yume_pages', array() );
		$cles  = array_merge( array( 'accueil', 'bibliotheque', 'planning', 'actualites' ), is_array( $pages ) ? array_map( 'strval', array_keys( $pages ) ) : array() );
		foreach ( array_unique( $cles ) as $cle ) {
			$adresses[] = yume_url_page( $cle );
		}
	}
	$archive = get_post_type_archive_link( 'yume_oeuvre' );
	if ( is_string( $archive ) ) {
		$adresses[] = $archive;
	}
	/**
	 * Filtre les adresses purgées du cache de page au changement de saison.
	 *
	 * @param string[] $adresses Adresses.
	 */
	$adresses = (array) apply_filters( 'yume_saisons_adresses_purge', $adresses );
	return array_values( array_unique( array_filter( array_map( 'strval', $adresses ) ) ) );
}

/**
 * Saison changée : pages principales purgées du cache de page (Batcache, si présent), puis cache
 * d'objets vidé. Le reste expire de lui-même (§7 du cahier des charges).
 */
function purger_caches(): void {
	if ( function_exists( 'batcache_clear_url' ) ) {
		foreach ( adresses_a_purger() as $adresse ) {
			batcache_clear_url( $adresse );
		}
	}
	wp_cache_flush();
}
add_action( 'yume_saison_changee', __NAMESPACE__ . '\\purger_caches' );

/*
 * -----------------------------------------------------------------------------
 * État affiché sous le calendrier
 * -----------------------------------------------------------------------------
 */

/**
 * Prochain changement du calendrier après une date : premier jour (dans les 366 suivants) dont
 * le thème diffère de celui de la date, ou null s'il n'y en a pas.
 *
 * @param \DateTimeInterface $depuis     Date de départ.
 * @param array              $calendrier Lignes (voir lignes_valides()).
 * @return array{theme:string,date:\DateTimeImmutable}|null
 */
function prochain_changement( \DateTimeInterface $depuis, array $calendrier ): ?array {
	$jour     = \DateTimeImmutable::createFromInterface( $depuis )->setTimezone( wp_timezone() )->setTime( 12, 0 );
	$actuelle = saison_du_calendrier( $jour, $calendrier );
	for ( $i = 1; $i <= 366; $i++ ) {
		$jour  = $jour->modify( '+1 day' );
		$theme = saison_du_calendrier( $jour, $calendrier );
		if ( $theme !== $actuelle ) {
			return array(
				'theme' => $theme,
				'date'  => $jour,
			);
		}
	}
	return null;
}

/**
 * Phrase d'état sous le calendrier : « Aujourd'hui : Automne (calendrier) — prochain
 * changement : Noël le 01/12. » (texte brut, à échapper).
 */
function phrase_etat(): string {
	$mode = mode();
	if ( 'aucun' === $mode ) {
		/* translators: %s : « Thème de base » */
		return sprintf( __( 'Aujourd’hui : %s (thème de base toute l’année).', 'yume-core' ), yume_saison_nom( '' ) );
	}
	if ( 'manuel' === $mode ) {
		/* translators: %s : nom du thème forcé */
		return sprintf( __( 'Aujourd’hui : %s (thème choisi manuellement).', 'yume-core' ), yume_saison_nom( saison_manuelle() ) );
	}
	$maintenant = maintenant();
	$calendrier = lignes_valides( yume_saison_calendrier() );
	$actuelle   = saison_du_calendrier( $maintenant, $calendrier );
	$prochain   = prochain_changement( $maintenant, $calendrier );
	if ( null === $prochain ) {
		/* translators: %s : nom du thème actif */
		return sprintf( __( 'Aujourd’hui : %s (calendrier).', 'yume-core' ), yume_saison_nom( $actuelle ) );
	}
	return sprintf(
		/* translators: 1: thème actif, 2: thème suivant, 3: date du changement (JJ/MM) */
		__( 'Aujourd’hui : %1$s (calendrier) — prochain changement : %2$s le %3$s.', 'yume-core' ),
		yume_saison_nom( $actuelle ),
		yume_saison_nom( $prochain['theme'] ),
		$prochain['date']->format( 'd/m' )
	);
}

/**
 * Lignes du formulaire du champ « saisons » (rendu par le module core) : les six lignes du
 * calendrier enregistré avec le nom du thème et l'adresse de l'aperçu.
 *
 * @param mixed $valeur Valeur enregistrée.
 * @return array<int,array{theme:string,nom:string,debut:string,fin:string,actif:bool,apercu:string}>
 */
function lignes_formulaire( $valeur ): array {
	$lignes = array();
	foreach ( calendrier_normalise( $valeur ) as $ligne ) {
		$ligne['nom']    = yume_saison_nom( $ligne['theme'] );
		$ligne['apercu'] = add_query_arg( PARAM_APERCU, $ligne['theme'], home_url( '/' ) );
		$lignes[]        = $ligne;
	}
	return $lignes;
}
