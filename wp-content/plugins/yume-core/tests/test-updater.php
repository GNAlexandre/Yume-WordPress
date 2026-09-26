<?php
/**
 * Tests du module updater : bibliothèque vendorisée, dépôt, détection d'une release GitHub
 * simulée (plugin et thème), archive obligatoire, réglage maj_auto, absence de réseau,
 * protection des copies de développement, champs de réglages, nettoyage.
 *
 * Aucune requête ne sort : l'API GitHub est simulée par le filtre pre_http_request.
 *
 *   tools/localenv/test.sh updater
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Updater\base_plugin;
use function Yume\Core\Updater\champs_reglages;
use function Yume\Core\Updater\charger_bibliotheque;
use function Yume\Core\Updater\chemin_bibliotheque;
use function Yume\Core\Updater\depot;
use function Yume\Core\Updater\jeton;
use function Yume\Core\Updater\maj_auto_active;
use function Yume\Core\Updater\nettoyer;
use function Yume\Core\Updater\normaliser_depot;
use function Yume\Core\Updater\proteger_copie_de_developpement;
use function Yume\Core\Updater\strategies;
use function Yume\Core\Updater\verificateur;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tu_)
 * -----------------------------------------------------------------------------
 */

/**
 * Vérificateur du plugin ou du thème, ou échec du test s'il n'a pas été construit.
 *
 * @param string $type 'plugin' ou 'theme'.
 * @return object
 * @throws Yume_Test_Failure Si le vérificateur n'existe pas.
 */
function yume_tu_verificateur( string $type ) {
	$verificateur = verificateur( $type );
	if ( ! is_object( $verificateur ) ) {
		throw new Yume_Test_Failure( "Aucun vérificateur « {$type} » construit par le module updater." );
	}
	return $verificateur;
}

/**
 * « propriétaire/dépôt » interrogé par un vérificateur.
 *
 * @param object $verificateur Vérificateur.
 * @return string
 */
function yume_tu_depot( $verificateur ): string {
	return trim( (string) wp_parse_url( $verificateur->getVcsApi()->getRepositoryUrl(), PHP_URL_PATH ), '/' );
}

/**
 * Release GitHub fictive (même forme que la réponse de l'API REST v3).
 *
 * @param string   $depot    « propriétaire/dépôt ».
 * @param string   $tag      Tag (ex. v2.1.0).
 * @param string[] $archives Noms des archives attachées.
 * @return array
 */
function yume_tu_release( string $depot, string $tag = 'v2.1.0', array $archives = array( 'yume-core.zip', 'yume.zip' ) ): array {
	$assets = array();
	foreach ( array_values( $archives ) as $i => $nom ) {
		$assets[] = array(
			'url'                  => "https://api.github.com/repos/{$depot}/releases/assets/" . ( 100 + $i ),
			'id'                   => 100 + $i,
			'name'                 => $nom,
			'content_type'         => 'application/zip',
			'state'                => 'uploaded',
			'size'                 => 123456,
			'download_count'       => 7 + $i,
			'browser_download_url' => "https://github.com/{$depot}/releases/download/{$tag}/{$nom}",
		);
	}
	return array(
		'url'          => "https://api.github.com/repos/{$depot}/releases/1",
		'html_url'     => "https://github.com/{$depot}/releases/tag/{$tag}",
		'id'           => 1,
		'tag_name'     => $tag,
		'name'         => $tag,
		'draft'        => false,
		'prerelease'   => false,
		'created_at'   => '2026-09-20T10:00:00Z',
		'published_at' => '2026-09-20T10:05:00Z',
		'zipball_url'  => "https://api.github.com/repos/{$depot}/zipball/{$tag}",
		'tarball_url'  => "https://api.github.com/repos/{$depot}/tarball/{$tag}",
		'body'         => "## Nouveautés\n\n- Planning public\n- Lecteur en ligne <script>alert(1)</script>",
		'assets'       => $assets,
	);
}

/**
 * Simule l'API GitHub : /releases/latest renvoie $release (ou 404 si null), tout le reste 404.
 * Renvoie le callback installé (à retirer avec yume_tu_retirer_http) ; les URL demandées sont
 * ajoutées à $journal.
 *
 * @param array|null        $release Release renvoyée, ou null.
 * @param array<int,string> $journal URL demandées (par référence).
 * @return callable
 */
function yume_tu_simuler_github( ?array $release, array &$journal ): callable {
	$callback = static function ( $pre, $args, $url ) use ( $release, &$journal ) {
		$journal[] = (string) $url;
		$ok        = null !== $release && false !== strpos( (string) $url, '/releases/latest' );
		return array(
			'headers'  => array( 'content-type' => 'application/json; charset=utf-8' ),
			'body'     => wp_json_encode( $ok ? $release : array( 'message' => 'Not Found' ) ),
			'response' => array(
				'code'    => $ok ? 200 : 404,
				'message' => $ok ? 'OK' : 'Not Found',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $callback, 10, 3 );
	return $callback;
}

/**
 * Retire un filtre pre_http_request installé par les aides ci-dessus.
 *
 * @param callable $callback Callback.
 */
function yume_tu_retirer_http( callable $callback ): void {
	remove_filter( 'pre_http_request', $callback, 10 );
}

/**
 * Exécute $fn en capturant toutes les erreurs PHP (notices, avertissements, dépréciations).
 *
 * @param callable $code Code à exécuter.
 * @return array<int,string> Erreurs capturées (« niveau : message (fichier:ligne) »).
 */
function yume_tu_sans_erreur( callable $code ): array {
	$erreurs = array();
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- capture le temps du test.
	set_error_handler(
		static function ( $niveau, $message, $fichier = '', $ligne = 0 ) use ( &$erreurs ) {
			$erreurs[] = $niveau . ' : ' . $message . ' (' . $fichier . ':' . $ligne . ')';
			return true;
		}
	);
	try {
		$code();
	} finally {
		restore_error_handler();
	}
	return $erreurs;
}

/**
 * Remet à zéro l'état mis en cache des vérificateurs (option et mémoire).
 */
function yume_tu_reinitialiser(): void {
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$verificateur = verificateur( $type );
		if ( is_object( $verificateur ) ) {
			$verificateur->resetUpdateState();
		}
	}
	delete_site_transient( 'update_plugins' );
	delete_site_transient( 'update_themes' );
}

/**
 * Enregistre le réglage maj_auto (option yume_reglages) le temps du test.
 *
 * @param mixed $valeur Valeur.
 */
function yume_tu_maj_auto( $valeur ): void {
	$reglages             = get_option( 'yume_reglages', array() );
	$reglages             = is_array( $reglages ) ? $reglages : array();
	$reglages['maj_auto'] = $valeur;
	update_option( 'yume_reglages', $reglages );
}

/*
 * -----------------------------------------------------------------------------
 * Bibliothèque et configuration
 * -----------------------------------------------------------------------------
 */

yume_test(
	'la bibliothèque Plugin Update Checker 5.6 est vendorisée avec sa licence et sa traduction',
	static function () {
		$dossier = dirname( chemin_bibliotheque() );
		yume_assert_true( is_readable( chemin_bibliotheque() ), 'fichier d’entrée absent' );
		yume_assert_true( is_readable( $dossier . '/license.txt' ), 'licence MIT absente' );
		yume_assert_true( is_readable( $dossier . '/load-v5p6.php' ), 'version 5.6 attendue' );
		yume_assert_true( is_readable( $dossier . '/languages/plugin-update-checker-fr_FR.mo' ), 'traduction française absente' );
		yume_assert_false( file_exists( $dossier . '/.git' ), 'pas de .git vendorisé' );
		yume_assert_false( file_exists( $dossier . '/examples' ), 'pas de fichiers d’exemple' );
		yume_assert_true( charger_bibliotheque() );
		yume_assert_true( class_exists( 'YahnisElsts\\PluginUpdateChecker\\v5p6\\Vcs\\GitHubApi' ) );
	}
);

yume_test(
	'bibliothèque absente : charger_bibliotheque() renvoie faux sans erreur',
	static function () {
		$resultat = null;
		$erreurs  = yume_tu_sans_erreur(
			static function () use ( &$resultat ) {
				$resultat = charger_bibliotheque( WP_CONTENT_DIR . '/yume-inexistant/plugin-update-checker.php', 'Yume\\Tests\\FabriqueInexistante' );
			}
		);
		yume_assert_false( $resultat );
		yume_assert_same( array(), $erreurs );
	}
);

yume_test(
	'dépôt : normalisation, repli sur le défaut du contrat et filtre',
	static function () {
		yume_assert_same( 'GNAlexandre/Yume-WordPress', normaliser_depot( ' GNAlexandre/Yume-WordPress ' ) );
		yume_assert_same( 'GNAlexandre/Yume-WordPress', normaliser_depot( 'https://github.com/GNAlexandre/Yume-WordPress.git' ) );
		yume_assert_same( 'Yume-Novel/site.v2', normaliser_depot( 'github.com/Yume-Novel/site.v2/' ) );
		yume_assert_same( '', normaliser_depot( 'GNAlexandre' ) );
		yume_assert_same( '', normaliser_depot( 'a/b/c' ) );
		yume_assert_same( '', normaliser_depot( '../..' ) );
		yume_assert_same( '', normaliser_depot( 'https://evil.example/owner/repo' ) );

		update_option( 'yume_reglages', array( 'github_repo' => 'pas un dépôt !' ) );
		yume_assert_same( 'GNAlexandre/Yume-WordPress', depot(), 'valeur invalide : défaut du contrat' );

		update_option( 'yume_reglages', array( 'github_repo' => 'Yume-Novel/Yume-WordPress' ) );
		yume_assert_same( 'Yume-Novel/Yume-WordPress', depot() );

		$filtre = static function () {
			return 'https://github.com/Autre/Depot';
		};
		add_filter( 'yume_updater_depot', $filtre );
		try {
			yume_assert_same( 'Autre/Depot', depot() );
		} finally {
			remove_filter( 'yume_updater_depot', $filtre );
		}
		yume_assert_same( defined( 'YUME_GITHUB_TOKEN' ) ? trim( (string) YUME_GITHUB_TOKEN ) : '', jeton() );
	}
);

yume_test(
	'vérificateurs construits pour yume-core et le thème yume, dernière release seulement',
	static function () {
		$plugin = yume_tu_verificateur( 'plugin' );
		yume_assert_same( 'yume-core', $plugin->slug );
		yume_assert_same( 'yume-core/yume-core.php', base_plugin() );
		yume_assert_same( YUME_CORE_VERSION, $plugin->getInstalledVersion() );
		yume_assert_contains( 'https://github.com/', $plugin->getVcsApi()->getRepositoryUrl() );

		$toutes = array(
			'latest_release' => '__return_null',
			'latest_tag'     => '__return_null',
			'branch'         => '__return_null',
		);
		yume_assert_same( array( 'latest_release' ), array_keys( strategies( $toutes ) ) );
		yume_assert_same(
			array( 'latest_release' ),
			array_keys( (array) apply_filters( $plugin->getUniqueName( 'vcs_update_detection_strategies' ), $toutes, $plugin->slug ) ),
			'aucun repli sur un tag nu ou une branche'
		);

		if ( wp_get_theme( 'yume' )->exists() ) {
			$theme = yume_tu_verificateur( 'theme' );
			yume_assert_same( 'yume', $theme->slug );
			yume_assert_same( wp_get_theme( 'yume' )->get( 'Version' ), $theme->getInstalledVersion() );
			yume_assert_same(
				array( 'latest_release' ),
				array_keys( (array) apply_filters( $theme->getUniqueName( 'vcs_update_detection_strategies' ), $toutes, $theme->slug ) )
			);
		} else {
			yume_assert_same( null, verificateur( 'theme' ), 'pas de vérificateur sans thème installé' );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Détection d'une release simulée
 * -----------------------------------------------------------------------------
 */

yume_test(
	'release v2.1.0 simulée : mise à jour de yume-core détectée avec l’archive yume-core.zip',
	static function () {
		$plugin  = yume_tu_verificateur( 'plugin' );
		$depot   = yume_tu_depot( $plugin );
		$journal = array();
		$http    = yume_tu_simuler_github( yume_tu_release( $depot ), $journal );
		yume_tu_reinitialiser();
		try {
			$demande = $plugin->requestUpdate();
			yume_assert_true( is_object( $demande ), 'requestUpdate() doit trouver la release' );
			yume_assert_same( '2.1.0', $demande->version );

			$plugin->checkForUpdates();
			$maj = $plugin->getUpdate();
			yume_assert_true( is_object( $maj ), 'mise à jour attendue' );
			yume_assert_same( '2.1.0', $maj->version );
			yume_assert_same( "https://github.com/{$depot}/releases/download/v2.1.0/yume-core.zip", $maj->download_url );

			$transient = get_site_transient( 'update_plugins' );
			yume_assert_true( is_object( $transient ) && isset( $transient->response['yume-core/yume-core.php'] ), 'mise à jour absente de update_plugins' );
			$offre = $transient->response['yume-core/yume-core.php'];
			yume_assert_same( '2.1.0', $offre->new_version );
			yume_assert_same( "https://github.com/{$depot}/releases/download/v2.1.0/yume-core.zip", $offre->package );
			yume_assert_same( 'yume-core/yume-core.php', $offre->plugin );

			yume_assert_contains( "https://api.github.com/repos/{$depot}/releases/latest", implode( "\n", $journal ) );
			foreach ( $journal as $url ) {
				yume_assert_same( 0, strpos( $url, 'https://api.github.com/' ), 'seule l’API GitHub est interrogée : ' . $url );
				yume_assert_not_contains( '/tags', $url, 'pas de repli sur les tags' );
				yume_assert_not_contains( '/branches/', $url, 'pas de repli sur une branche' );
			}

			$infos = $plugin->requestInfo();
			yume_assert_true( is_object( $infos ) );
			yume_assert_contains( 'Planning public', (string) $infos->sections['changelog'] );
			yume_assert_not_contains( '<script', (string) $infos->sections['changelog'], 'notes de version assainies' );
		} finally {
			yume_tu_retirer_http( $http );
			yume_tu_reinitialiser();
		}
	}
);

yume_test(
	'release v2.1.0 simulée : mise à jour du thème yume détectée avec l’archive yume.zip',
	static function () {
		if ( ! wp_get_theme( 'yume' )->exists() ) {
			yume_assert_same( null, verificateur( 'theme' ) );
			return;
		}
		$theme   = yume_tu_verificateur( 'theme' );
		$depot   = yume_tu_depot( $theme );
		$journal = array();
		$http    = yume_tu_simuler_github( yume_tu_release( $depot ), $journal );
		yume_tu_reinitialiser();
		try {
			$theme->checkForUpdates();
			$maj = $theme->getUpdate();
			yume_assert_true( is_object( $maj ), 'mise à jour du thème attendue' );
			yume_assert_same( '2.1.0', $maj->version );
			yume_assert_same( "https://github.com/{$depot}/releases/download/v2.1.0/yume.zip", $maj->download_url );

			$transient = get_site_transient( 'update_themes' );
			yume_assert_true( is_object( $transient ) && isset( $transient->response['yume'] ), 'mise à jour absente de update_themes' );
			yume_assert_same( '2.1.0', $transient->response['yume']['new_version'] );
			yume_assert_same( "https://github.com/{$depot}/releases/download/v2.1.0/yume.zip", $transient->response['yume']['package'] );
		} finally {
			yume_tu_retirer_http( $http );
			yume_tu_reinitialiser();
		}
	}
);

yume_test(
	'release sans archive yume-core.zip, ou version déjà installée : aucune mise à jour',
	static function () {
		$plugin  = yume_tu_verificateur( 'plugin' );
		$depot   = yume_tu_depot( $plugin );
		$journal = array();
		$http    = yume_tu_simuler_github( yume_tu_release( $depot, 'v2.1.0', array( 'notes.txt' ) ), $journal );
		yume_tu_reinitialiser();
		try {
			$plugin->checkForUpdates();
			yume_assert_same( null, $plugin->getUpdate(), 'jamais l’archive source du dépôt' );
			$transient = get_site_transient( 'update_plugins' );
			yume_assert_false( is_object( $transient ) && isset( $transient->response['yume-core/yume-core.php'] ) );
			yume_assert_true( count( $journal ) >= 1 );
			foreach ( $journal as $url ) {
				yume_assert_not_contains( '/tags', $url, 'pas de repli sur le tag le plus récent' );
				yume_assert_not_contains( '/branches/', $url, 'pas de repli sur une branche' );
				yume_assert_not_contains( '/zipball/', $url, 'pas d’archive source' );
			}
		} finally {
			yume_tu_retirer_http( $http );
			yume_tu_reinitialiser();
		}

		$journal = array();
		$http    = yume_tu_simuler_github( yume_tu_release( $depot, 'v' . YUME_CORE_VERSION ), $journal );
		try {
			$plugin->checkForUpdates();
			yume_assert_same( null, $plugin->getUpdate(), 'même version : rien à proposer' );
		} finally {
			yume_tu_retirer_http( $http );
			yume_tu_reinitialiser();
		}
	}
);

yume_test(
	'réseau indisponible ou API en erreur : ni erreur fatale ni notice, aucune mise à jour',
	static function () {
		$verificateurs = array_filter( array( verificateur( 'plugin' ), verificateur( 'theme' ) ) );
		yume_assert_true( count( $verificateurs ) >= 1 );
		yume_tu_reinitialiser();

		// 1. Réseau coupé : wp_remote_get() renvoie une WP_Error.
		$coupure = static function () {
			return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: api.github.com' );
		};
		add_filter( 'pre_http_request', $coupure, 10, 3 );
		try {
			$erreurs = yume_tu_sans_erreur(
				static function () use ( $verificateurs ) {
					foreach ( $verificateurs as $verificateur ) {
						$verificateur->checkForUpdates();
						yume_assert_same( null, $verificateur->getUpdate() );
						yume_assert_true( count( $verificateur->getLastRequestApiErrors() ) >= 1, 'erreur d’API consignée' );
					}
					$verificateurs[0]->requestInfo();
					get_site_transient( 'update_plugins' );
					get_site_transient( 'update_themes' );
				}
			);
			yume_assert_same( array(), $erreurs, 'aucune notice ni avertissement' );
		} finally {
			remove_filter( 'pre_http_request', $coupure, 10 );
			yume_tu_reinitialiser();
		}

		// 2. Limite de débit de l'API (403) et réponse illisible.
		$reponses = array(
			403 => '{"message":"API rate limit exceeded"}',
			200 => 'pas du JSON',
		);
		foreach ( $reponses as $code => $corps ) {
			$reponse = static function () use ( $code, $corps ) {
				return array(
					'headers'  => array(),
					'body'     => $corps,
					'response' => array(
						'code'    => $code,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			};
			add_filter( 'pre_http_request', $reponse, 10, 3 );
			try {
				$erreurs = yume_tu_sans_erreur(
					static function () use ( $verificateurs ) {
						foreach ( $verificateurs as $verificateur ) {
							$verificateur->checkForUpdates();
							yume_assert_same( null, $verificateur->getUpdate() );
						}
					}
				);
				yume_assert_same( array(), $erreurs, "réponse HTTP {$code} : aucune notice" );
			} finally {
				remove_filter( 'pre_http_request', $reponse, 10 );
				yume_tu_reinitialiser();
			}
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Mises à jour automatiques (maj_auto) et copies de développement
 * -----------------------------------------------------------------------------
 */

yume_test(
	'maj_auto pilote auto_update_plugin et auto_update_theme',
	static function () {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$pas_de_copie = static function () {
			return false;
		};
		add_filter( 'yume_updater_copie_de_developpement', $pas_de_copie );
		try {
			$plugin = (object) array( 'plugin' => 'yume-core/yume-core.php' );
			$theme  = (object) array( 'theme' => 'yume' );

			yume_tu_maj_auto( true );
			yume_assert_true( maj_auto_active() );
			yume_assert_true( apply_filters( 'auto_update_plugin', null, $plugin ) );
			yume_assert_true( apply_filters( 'auto_update_plugin', false, $plugin ), 'maj_auto l’emporte sur le choix par défaut' );
			yume_assert_true( wp_is_auto_update_forced_for_item( 'plugin', null, $plugin ) );
			yume_assert_true( apply_filters( 'auto_update_theme', null, $theme ) );

			yume_tu_maj_auto( false );
			yume_assert_false( maj_auto_active() );
			yume_assert_false( apply_filters( 'auto_update_plugin', true, $plugin ) );
			yume_assert_false( wp_is_auto_update_forced_for_item( 'plugin', null, $plugin ) );
			yume_assert_false( apply_filters( 'auto_update_theme', true, $theme ) );

			yume_tu_maj_auto( '1' );
			yume_assert_true( apply_filters( 'auto_update_plugin', null, $plugin ), 'case cochée enregistrée en chaîne' );
			yume_tu_maj_auto( '0' );
			yume_assert_false( apply_filters( 'auto_update_plugin', null, $plugin ) );

			// Les autres extensions et thèmes ne sont pas concernés.
			yume_assert_same( null, apply_filters( 'auto_update_plugin', null, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
			yume_assert_same( true, apply_filters( 'auto_update_plugin', true, (object) array( 'plugin' => 'akismet/akismet.php' ) ) );
			yume_assert_same( null, apply_filters( 'auto_update_theme', null, (object) array( 'theme' => 'twentytwentyfive' ) ) );
		} finally {
			remove_filter( 'yume_updater_copie_de_developpement', $pas_de_copie );
		}

		// Sans réglage enregistré : défaut du contrat (true).
		delete_option( 'yume_reglages' );
		yume_assert_true( maj_auto_active() );
	}
);

yume_test(
	'copie de développement : jamais de mise à jour automatique ni d’installation par-dessus',
	static function () {
		$copie = static function () {
			return true;
		};
		add_filter( 'yume_updater_copie_de_developpement', $copie );
		try {
			yume_tu_maj_auto( true );
			yume_assert_false( apply_filters( 'auto_update_plugin', null, (object) array( 'plugin' => 'yume-core/yume-core.php' ) ) );
			yume_assert_false( apply_filters( 'auto_update_theme', null, (object) array( 'theme' => 'yume' ) ) );

			$refus = apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'yume-core/yume-core.php' ) );
			yume_assert_true( is_wp_error( $refus ) );
			yume_assert_same( 'yume_copie_de_developpement', $refus->get_error_code() );
			yume_assert_true( is_wp_error( apply_filters( 'upgrader_pre_install', true, array( 'theme' => 'yume' ) ) ) );

			// Autres paquets, et première installation par téléversement : non concernés.
			yume_assert_true( proteger_copie_de_developpement( true, array( 'plugin' => 'akismet/akismet.php' ) ) );
			yume_assert_true(
				proteger_copie_de_developpement(
					true,
					array(
						'type'   => 'plugin',
						'action' => 'install',
					)
				)
			);
		} finally {
			remove_filter( 'yume_updater_copie_de_developpement', $copie );
		}

		$pas_de_copie = static function () {
			return false;
		};
		add_filter( 'yume_updater_copie_de_developpement', $pas_de_copie );
		try {
			yume_assert_true( proteger_copie_de_developpement( true, array( 'plugin' => 'yume-core/yume-core.php' ) ) );
		} finally {
			remove_filter( 'yume_updater_copie_de_developpement', $pas_de_copie );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Réglages et désactivation
 * -----------------------------------------------------------------------------
 */

yume_test(
	'yume_reglages_champs : github_repo et maj_auto ajoutés seulement s’ils manquent',
	static function () {
		$ajoutes = champs_reglages( array() );
		yume_assert_same( array( 'github_repo', 'maj_auto' ), array_column( $ajoutes, 'key' ) );
		yume_assert_same( 'mises_a_jour', $ajoutes[0]['section'] );
		yume_assert_same( 'checkbox', $ajoutes[1]['type'] );

		$existants = array(
			array(
				'key'  => 'github_repo',
				'type' => 'text',
			),
			array(
				'key'  => 'maj_auto',
				'type' => 'checkbox',
			),
		);
		yume_assert_same( $existants, champs_reglages( $existants ) );

		// Sur la page réelle (module core chargé), chaque clé n'apparaît qu'une fois.
		$cles = array_column( (array) apply_filters( 'yume_reglages_champs', array() ), 'key' );
		yume_assert_same( 1, count( array_keys( $cles, 'github_repo', true ) ) );
		yume_assert_same( 1, count( array_keys( $cles, 'maj_auto', true ) ) );
	}
);

yume_test(
	'messages de Plugin Update Checker absents de sa traduction : affichés en français',
	static function () {
		// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment -- chaîne de la bibliothèque.
		$message = _x( 'Could not determine if updates are available for %s.', 'the plugin title', 'plugin-update-checker' );
		yume_assert_contains( 'Impossible de savoir', $message );
		yume_assert_contains( '%s', $message, 'le nom de l’extension reste injecté' );
		yume_assert_same( 'Déjà traduit', \Yume\Core\Updater\traduire_bibliotheque( 'Déjà traduit', 'Check for updates', '', 'plugin-update-checker' ) );
		yume_assert_same( 'Other', \Yume\Core\Updater\traduire_bibliotheque( 'Other', 'Other', '', 'plugin-update-checker' ) );
	}
);

yume_test(
	'désactivation : vérifications planifiées et état en cache supprimés',
	static function () {
		$plugin = yume_tu_verificateur( 'plugin' );
		$tache  = $plugin->getUniqueName( 'cron_check_updates' );
		if ( ! wp_next_scheduled( $tache ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', $tache );
		}
		update_site_option( 'external_updates-yume-core', (object) array( 'lastCheck' => time() ) );

		do_action( 'yume_core_deactivate' );

		yume_assert_false( wp_next_scheduled( $tache ) );
		yume_assert_false( wp_next_scheduled( 'puc_cron_check_updates_theme-yume' ) );
		yume_assert_false( get_site_option( 'external_updates-yume-core' ) );
		yume_assert_same( 0, $plugin->getUpdateState()->getLastCheck() );

		// Idempotent.
		nettoyer();
		yume_assert_false( wp_next_scheduled( $tache ) );
	}
);
