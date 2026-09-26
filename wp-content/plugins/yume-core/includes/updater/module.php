<?php
/**
 * Module « updater » : mises à jour du plugin yume-core et du thème yume depuis les
 * releases GitHub (docs/05-pipeline-github-wordpress.md, contrat §6 : github_repo, maj_auto).
 *
 * Chaque release taguée vX.Y.Z du dépôt porte deux archives : yume-core.zip (plugin) et
 * yume.zip (thème). La bibliothèque Plugin Update Checker (vendorisée dans
 * lib/plugin-update-checker/, licence MIT) interroge l'API GitHub toutes les 12 heures et
 * présente la nouvelle version dans Extensions → Mises à jour comme n'importe quelle extension.
 * Le réglage maj_auto pilote les filtres auto_update_plugin / auto_update_theme.
 *
 * Garanties :
 * - rien ne casse si la bibliothèque est absente ou si le réseau est bloqué (aucune notice) ;
 * - seules les releases publiées (ni brouillon ni préversion) portant l'archive attendue
 *   comptent : jamais l'archive source du dépôt entier, jamais une branche ou un tag nu ;
 * - une copie de développement (lien symbolique ou dépôt Git) n'est jamais écrasée.
 *
 * Dépôt privé : définir YUME_GITHUB_TOKEN (jeton en lecture seule) dans wp-config.php.
 * Source figée : définir YUME_GITHUB_REPO (« propriétaire/dépôt ») dans wp-config.php ; elle
 * prime sur le réglage github_repo. Ce réglage et maj_auto ne sont visibles et modifiables que
 * par les comptes qui peuvent déjà installer des mises à jour (update_plugins).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Updater;

defined( 'ABSPATH' ) || exit;

/** Dépôt par défaut (contrat §6, clé github_repo). */
const DEPOT_DEFAUT = 'GNAlexandre/Yume-WordPress';

/** Archive de release du plugin. */
const ARCHIVE_PLUGIN = 'yume-core.zip';

/** Archive de release du thème. */
const ARCHIVE_THEME = 'yume.zip';

/** Dossier (stylesheet) du thème mis à jour. */
const THEME = 'yume';

/** Intervalle entre deux vérifications automatiques, en heures. */
const PERIODE_HEURES = 12;

/** Classe d'entrée de Plugin Update Checker (version majeure 5). */
const FABRIQUE = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';

/** Motif d'un dépôt GitHub « propriétaire/dépôt » (même règle que la validation de core). */
const MOTIF_DEPOT = '#^[A-Za-z0-9_.-]{1,39}/[A-Za-z0-9_.-]{1,100}$#';

add_action( 'plugins_loaded', __NAMESPACE__ . '\\demarrer', 20 );
add_filter( 'auto_update_plugin', __NAMESPACE__ . '\\filtrer_maj_auto_plugin', 20, 2 );
add_filter( 'auto_update_theme', __NAMESPACE__ . '\\filtrer_maj_auto_theme', 20, 2 );
add_filter( 'upgrader_pre_install', __NAMESPACE__ . '\\proteger_copie_de_developpement', 10, 2 );
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champs_reglages', 20 );
add_action( 'yume_core_deactivate', __NAMESPACE__ . '\\nettoyer' );
add_filter( 'gettext_with_context_plugin-update-checker', __NAMESPACE__ . '\\traduire_bibliotheque', 10, 4 );

/*
 * -----------------------------------------------------------------------------
 * Réglages
 * -----------------------------------------------------------------------------
 */

/**
 * Lit un réglage Yume : yume_setting() quand le module core est chargé, sinon le défaut du contrat.
 *
 * @param string $cle    Clé du réglage (§6).
 * @param mixed  $defaut Défaut du contrat.
 * @return mixed
 */
function reglage( string $cle, $defaut ) {
	if ( function_exists( 'yume_setting' ) ) {
		$valeur = yume_setting( $cle, $defaut );
		return null === $valeur ? $defaut : $valeur;
	}
	return $defaut;
}

/**
 * Dépôt GitHub « propriétaire/dépôt » d'où viennent les mises à jour.
 *
 * Une valeur invalide (saisie erronée, URL complète…) retombe sur le dépôt par défaut.
 *
 * @return string
 */
function depot(): string {
	// La constante YUME_GITHUB_REPO (wp-config.php) prime sur le réglage modifiable dans l'admin.
	$depot = depot_constante();
	if ( '' === $depot ) {
		$depot = normaliser_depot( (string) reglage( 'github_repo', DEPOT_DEFAUT ) );
	}
	/**
	 * Filtre le dépôt GitHub utilisé pour les mises à jour (forme « propriétaire/dépôt »).
	 *
	 * @param string $depot Dépôt.
	 */
	$depot = normaliser_depot( (string) apply_filters( 'yume_updater_depot', $depot ) );
	return '' !== $depot ? $depot : DEPOT_DEFAUT;
}

/**
 * Dépôt figé par la constante YUME_GITHUB_REPO, normalisé ; chaîne vide si elle est absente
 * ou invalide.
 *
 * @return string
 */
function depot_constante(): string {
	if ( ! defined( 'YUME_GITHUB_REPO' ) ) {
		return '';
	}
	$valeur = constant( 'YUME_GITHUB_REPO' );
	return is_string( $valeur ) ? normaliser_depot( $valeur ) : '';
}

/**
 * Normalise une saisie de dépôt : accepte « propriétaire/dépôt » ou une URL github.com.
 *
 * @param string $saisie Valeur brute.
 * @return string Dépôt valide, ou chaîne vide.
 */
function normaliser_depot( string $saisie ): string {
	$depot = trim( $saisie );
	$depot = (string) preg_replace( '#^(?:https?://)?(?:www\.)?github\.com/#i', '', $depot );
	$depot = (string) preg_replace( '#(?:\.git)?/*$#', '', $depot );
	if ( ! preg_match( MOTIF_DEPOT, $depot ) ) {
		return '';
	}
	// « . » et « .. » ne sont pas des noms de propriétaire ou de dépôt.
	foreach ( explode( '/', $depot ) as $segment ) {
		if ( '' === trim( $segment, '.' ) ) {
			return '';
		}
	}
	return $depot;
}

/**
 * URL du dépôt telle que l'attend Plugin Update Checker.
 *
 * @param string $depot Dépôt « propriétaire/dépôt ».
 * @return string
 */
function url_depot( string $depot ): string {
	return 'https://github.com/' . $depot . '/';
}

/**
 * Réglage maj_auto converti en booléen (case à cocher, chaîne « 1 », « 0 », « oui »…).
 *
 * @return bool
 */
function maj_auto_active(): bool {
	$valeur = reglage( 'maj_auto', true );
	if ( is_bool( $valeur ) ) {
		return $valeur;
	}
	if ( '' === $valeur || null === $valeur ) {
		return false;
	}
	$bool = filter_var( $valeur, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	return null === $bool ? (bool) $valeur : $bool;
}

/**
 * Jeton GitHub facultatif (dépôt privé), lu dans la constante YUME_GITHUB_TOKEN.
 *
 * @return string Jeton, ou chaîne vide.
 */
function jeton(): string {
	if ( ! defined( 'YUME_GITHUB_TOKEN' ) ) {
		return '';
	}
	$jeton = constant( 'YUME_GITHUB_TOKEN' );
	return is_string( $jeton ) ? trim( $jeton ) : '';
}

/**
 * Le module est-il actif ? Désactivable par le filtre yume_updater_actif.
 *
 * @return bool
 */
function actif(): bool {
	/**
	 * Active ou désactive entièrement la recherche de mises à jour sur GitHub.
	 *
	 * @param bool $actif Vrai par défaut.
	 */
	return (bool) apply_filters( 'yume_updater_actif', true );
}

/*
 * -----------------------------------------------------------------------------
 * Bibliothèque et vérificateurs
 * -----------------------------------------------------------------------------
 */

/**
 * Chemin du fichier d'entrée de Plugin Update Checker.
 *
 * @return string
 */
function chemin_bibliotheque(): string {
	$base = defined( 'YUME_CORE_DIR' ) ? YUME_CORE_DIR : dirname( __DIR__, 2 ) . '/';
	return $base . 'lib/plugin-update-checker/plugin-update-checker.php';
}

/**
 * Charge Plugin Update Checker si le fichier est présent.
 *
 * @param string $fichier Fichier d'entrée (défaut : copie vendorisée).
 * @param string $classe  Classe qui doit exister après chargement.
 * @return bool Vrai si la bibliothèque est utilisable.
 */
function charger_bibliotheque( string $fichier = '', string $classe = FABRIQUE ): bool {
	$fichier = '' !== $fichier ? $fichier : chemin_bibliotheque();
	if ( is_readable( $fichier ) ) {
		require_once $fichier;
	}
	return class_exists( $classe, false );
}

/**
 * Registre des vérificateurs construits pendant la requête.
 *
 * @param array<string,object>|null $nouveau Remplace le registre (usage interne).
 * @return array<string,object> Clés 'plugin' et/ou 'theme'.
 */
function registre( ?array $nouveau = null ): array {
	static $verificateurs = array();
	if ( null !== $nouveau ) {
		$verificateurs = $nouveau;
	}
	return $verificateurs;
}

/**
 * Vérificateur de mises à jour construit pour le plugin ou le thème.
 *
 * @param string $type 'plugin' ou 'theme'.
 * @return object|null Instance de Plugin Update Checker, ou null.
 */
function verificateur( string $type ) {
	$verificateurs = registre();
	return $verificateurs[ $type ] ?? null;
}

/**
 * Construit les vérificateurs (plugins_loaded, priorité 20 : après le chargement de core).
 */
function demarrer(): void {
	if ( registre() || ! actif() || ! defined( 'YUME_CORE_FILE' ) ) {
		return;
	}
	if ( ! charger_bibliotheque() ) {
		return;
	}

	$url           = url_depot( depot() );
	$verificateurs = array();

	$plugin = construire( $url, YUME_CORE_FILE, 'yume-core', ARCHIVE_PLUGIN );
	if ( $plugin ) {
		$verificateurs['plugin'] = $plugin;
	}

	$theme = wp_get_theme( THEME );
	if ( $theme->exists() ) {
		$verificateur = construire( $url, $theme->get_stylesheet_directory(), THEME, ARCHIVE_THEME );
		if ( $verificateur ) {
			$verificateurs['theme'] = $verificateur;
		}
	}

	registre( $verificateurs );
}

/**
 * Construit et configure un vérificateur GitHub. Ne lève jamais d'exception.
 *
 * @param string $url     URL du dépôt.
 * @param string $chemin  Fichier principal du plugin ou dossier du thème.
 * @param string $slug    Identifiant (dossier du plugin ou du thème).
 * @param string $archive Nom exact de l'archive attendue dans la release.
 * @return object|null
 */
function construire( string $url, string $chemin, string $slug, string $archive ) {
	try {
		$fabrique     = FABRIQUE;
		$verificateur = $fabrique::buildUpdateChecker( $url, $chemin, $slug, PERIODE_HEURES );
		configurer( $verificateur, $archive );
		return $verificateur;
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[yume-core] mises à jour GitHub indisponibles pour %s : %s', $slug, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return null;
	}
}

/**
 * Configure un vérificateur : archive de release obligatoire, jeton, dernière release seulement.
 *
 * @param object $verificateur Vérificateur GitHub de Plugin Update Checker.
 * @param string $archive      Nom exact de l'archive attendue.
 */
function configurer( $verificateur, string $archive ): void {
	$api = $verificateur->getVcsApi();

	// L'archive doit être présente : sans elle, la release est ignorée (jamais l'archive source).
	$api->enableReleaseAssets( '/^' . preg_quote( $archive, '/' ) . '$/', 2 /* Api::REQUIRE_RELEASE_ASSETS */ );

	$jeton = jeton();
	if ( '' !== $jeton ) {
		$verificateur->setAuthentication( $jeton );
	}

	// Seule la dernière release publiée compte : pas de repli sur un tag nu ni sur une branche.
	$verificateur->addFilter( 'vcs_update_detection_strategies', __NAMESPACE__ . '\\strategies' );

	// Fiche « Voir les détails » du plugin : lien vers le dépôt, notes de version assainies.
	if ( method_exists( $verificateur, 'requestInfo' ) ) {
		$verificateur->addFilter( 'request_info_result', __NAMESPACE__ . '\\completer_infos' );
	}
}

/**
 * Ne garde que la stratégie « dernière release ».
 *
 * @param array<string,callable> $strategies Stratégies de Plugin Update Checker.
 * @return array<string,callable>
 */
function strategies( $strategies ): array {
	$strategies = is_array( $strategies ) ? $strategies : array();
	return array_intersect_key( $strategies, array( 'latest_release' => true ) );
}

/**
 * Complète la fiche d'information de la mise à jour (fenêtre « Voir les détails »).
 *
 * @param object|null $infos Fiche PluginInfo, ou null.
 * @return object|null
 */
function completer_infos( $infos ) {
	if ( ! is_object( $infos ) ) {
		return $infos;
	}
	if ( empty( $infos->homepage ) ) {
		$infos->homepage = url_depot( depot() );
	}
	if ( isset( $infos->sections ) && is_array( $infos->sections ) ) {
		$infos->sections = array_map( 'wp_kses_post', $infos->sections );
	}
	return $infos;
}

/*
 * -----------------------------------------------------------------------------
 * Mises à jour automatiques et protection des copies de développement
 * -----------------------------------------------------------------------------
 */

/**
 * Fichier principal du plugin relatif au dossier des extensions (« yume-core/yume-core.php »).
 *
 * @return string
 */
function base_plugin(): string {
	return defined( 'YUME_CORE_FILE' ) ? plugin_basename( YUME_CORE_FILE ) : 'yume-core/yume-core.php';
}

/**
 * Le plugin ou le thème installé est-il une copie de développement (lien symbolique vers un
 * dépôt, ou dossier situé dans un dépôt Git) ? Une mise à jour la remplacerait et effacerait
 * le travail en cours.
 *
 * @param string $type 'plugin' ou 'theme'.
 * @return bool
 */
function est_copie_de_developpement( string $type ): bool {
	if ( 'theme' === $type ) {
		$installe = get_theme_root( THEME ) . '/' . THEME;
	} else {
		$installe = WP_PLUGIN_DIR . '/' . dirname( base_plugin() );
	}
	$installe = untrailingslashit( wp_normalize_path( $installe ) );
	$reel     = realpath( $installe );
	$copie    = is_link( $installe );
	if ( ! $copie && $reel ) {
		// Arborescence du dépôt : <racine>/wp-content/(plugins|themes)/<dossier>.
		$racine = dirname( $reel, 3 );
		$copie  = is_dir( $reel . '/.git' ) || file_exists( $racine . '/.git' );
	}
	/**
	 * Indique si le plugin ou le thème installé est une copie de développement, qui ne doit
	 * être ni mise à jour automatiquement ni écrasée par une mise à jour.
	 *
	 * @param bool   $copie Détection automatique.
	 * @param string $type  'plugin' ou 'theme'.
	 */
	return (bool) apply_filters( 'yume_updater_copie_de_developpement', $copie, $type );
}

/**
 * Filtre auto_update_plugin : applique maj_auto au plugin yume-core.
 *
 * @param bool|null $maj  Décision courante.
 * @param object    $item Offre de mise à jour (propriété plugin).
 * @return bool|null
 */
function filtrer_maj_auto_plugin( $maj, $item ) {
	if ( ! is_object( $item ) || ! isset( $item->plugin ) || base_plugin() !== $item->plugin ) {
		return $maj;
	}
	return maj_auto_active() && ! est_copie_de_developpement( 'plugin' );
}

/**
 * Filtre auto_update_theme : applique maj_auto au thème yume.
 *
 * @param bool|null $maj  Décision courante.
 * @param object    $item Offre de mise à jour (propriété theme).
 * @return bool|null
 */
function filtrer_maj_auto_theme( $maj, $item ) {
	if ( ! is_object( $item ) || ! isset( $item->theme ) || THEME !== $item->theme ) {
		return $maj;
	}
	return maj_auto_active() && ! est_copie_de_developpement( 'theme' );
}

/**
 * Refuse d'installer une mise à jour par-dessus une copie de développement.
 *
 * @param bool|\WP_Error $reponse    Décision courante.
 * @param array          $hook_extra Contexte de l'installation (plugin ou theme).
 * @return bool|\WP_Error
 */
function proteger_copie_de_developpement( $reponse, $hook_extra ) {
	if ( is_wp_error( $reponse ) || ! is_array( $hook_extra ) ) {
		return $reponse;
	}
	$type = '';
	if ( isset( $hook_extra['plugin'] ) && base_plugin() === $hook_extra['plugin'] ) {
		$type = 'plugin';
	} elseif ( isset( $hook_extra['theme'] ) && THEME === $hook_extra['theme'] ) {
		$type = 'theme';
	}
	if ( '' === $type || ! est_copie_de_developpement( $type ) ) {
		return $reponse;
	}
	return new \WP_Error(
		'yume_copie_de_developpement',
		'plugin' === $type
			? __( 'Mise à jour refusée : l’extension Yume Core installée est une copie de développement (dépôt Git ou lien symbolique). Mettez-la à jour avec Git.', 'yume-core' )
			: __( 'Mise à jour refusée : le thème Yume installé est une copie de développement (dépôt Git ou lien symbolique). Mettez-le à jour avec Git.', 'yume-core' )
	);
}

/**
 * Complète la traduction française de Plugin Update Checker : les messages absents de son
 * fichier fr_FR.mo s'afficheraient en anglais dans Extensions.
 *
 * @param string $traduction Traduction courante.
 * @param string $texte      Texte source.
 * @param string $contexte   Contexte gettext.
 * @param string $domaine    Domaine (plugin-update-checker).
 * @return string
 */
function traduire_bibliotheque( $traduction, $texte, $contexte, $domaine ): string {
	unset( $contexte, $domaine );
	if ( $traduction !== $texte ) {
		return (string) $traduction;
	}
	$manquantes = array(
		/* translators: %s : nom de l'extension. */
		'Could not determine if updates are available for %s.' => __( 'Impossible de savoir si une mise à jour de %s est disponible : réessayez plus tard.', 'yume-core' ),
	);
	return $manquantes[ $texte ] ?? (string) $traduction;
}

/*
 * -----------------------------------------------------------------------------
 * Page Yume → Réglages et désactivation
 * -----------------------------------------------------------------------------
 */

/**
 * Ajoute les champs github_repo et maj_auto à Yume → Réglages s'ils n'y figurent pas déjà
 * (le module core les déclare normalement lui-même, section « Mises à jour »).
 *
 * @param array $champs Champs déclarés.
 * @return array
 */
function champs_reglages( $champs ): array {
	$champs = is_array( $champs ) ? $champs : array();
	$cles   = array();
	foreach ( $champs as $champ ) {
		if ( is_array( $champ ) && isset( $champ['key'] ) && is_string( $champ['key'] ) ) {
			$cles[ $champ['key'] ] = true;
		}
	}
	if ( ! isset( $cles['github_repo'] ) ) {
		$champs[] = array(
			'key'         => 'github_repo',
			'label'       => __( 'Dépôt GitHub', 'yume-core' ),
			'type'        => 'text',
			'section'     => 'mises_a_jour',
			'default'     => DEPOT_DEFAUT,
			'placeholder' => 'propriétaire/dépôt',
			'description' => __( 'Dépôt dont les releases fournissent les mises à jour du plugin et du thème.', 'yume-core' ),
			'capability'  => 'update_plugins',
			'verrouille'  => '' !== depot_constante() ? __( 'Dépôt figé par la constante YUME_GITHUB_REPO (wp-config.php).', 'yume-core' ) : '',
		);
	}
	if ( ! isset( $cles['maj_auto'] ) ) {
		$champs[] = array(
			'key'         => 'maj_auto',
			'label'       => __( 'Mises à jour automatiques', 'yume-core' ),
			'type'        => 'checkbox',
			'section'     => 'mises_a_jour',
			'default'     => true,
			'description' => __( 'Installer automatiquement les nouvelles versions publiées sur GitHub.', 'yume-core' ),
			'capability'  => 'update_plugins',
		);
	}
	return $champs;
}

/**
 * À la désactivation du plugin : supprime les vérifications planifiées et l'état mis en cache.
 */
function nettoyer(): void {
	foreach ( registre() as $verificateur ) {
		wp_clear_scheduled_hook( $verificateur->getUniqueName( 'cron_check_updates' ) );
		$verificateur->resetUpdateState();
	}
	// Noms utilisés par Plugin Update Checker, au cas où les vérificateurs n'existent pas.
	wp_clear_scheduled_hook( 'puc_cron_check_updates-yume-core' );
	wp_clear_scheduled_hook( 'puc_cron_check_updates_theme-' . THEME );
	delete_site_option( 'external_updates-yume-core' );
	delete_site_option( 'puc_external_updates_theme-' . THEME );
}
