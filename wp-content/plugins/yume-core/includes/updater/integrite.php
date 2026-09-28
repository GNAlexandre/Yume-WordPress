<?php
/**
 * Module « updater » : vérification d'intégrité des mises à jour Yume (SEC-01, AMEL-02).
 *
 * Avant que WordPress n'installe une mise à jour du plugin yume-core ou du thème yume, le filtre
 * upgrader_pre_download télécharge lui-même l'archive de la release GitHub, télécharge le fichier
 * SHA256SUMS de la même release (produit par .github/workflows/release.yml via
 * tools/build/zip.sh, format « <sha256>  <nom> »), calcule l'empreinte SHA-256 de l'archive et
 * refuse l'installation (WP_Error, message consigné dans le journal PHP) si :
 * - l'archive ne vient pas d'une release du dépôt configuré, ou n'est pas l'archive attendue ;
 * - le tag de la release ne correspond pas à la version proposée (« v » + version) ;
 * - SHA256SUMS est absent, illisible ou ne liste pas l'archive ;
 * - l'empreinte calculée diffère de celle de SHA256SUMS ;
 * - un téléchargement échoue ou est redirigé vers un hôte hors de la liste autorisée.
 *
 * Les mises à jour automatiques (maj_auto) passent par le même WP_Upgrader, donc par la même
 * vérification. Les autres extensions et thèmes ne sont jamais concernés.
 *
 * Désactivation (secours uniquement, déconseillée) : define( 'YUME_EXIGER_EMPREINTE', false );
 * dans wp-config.php, ou filtre yume_updater_exiger_empreinte.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Updater;

defined( 'ABSPATH' ) || exit;

/** Nom du fichier d'empreintes attaché à chaque release. */
const FICHIER_EMPREINTES = 'SHA256SUMS';

/** Hôtes HTTPS autorisés pour les téléchargements (y compris après redirection). */
const HOTES_AUTORISES = array(
	'github.com',
	'api.github.com',
	'objects.githubusercontent.com',
	'release-assets.githubusercontent.com',
);

/** Nombre maximal de redirections suivies. */
const REDIRECTIONS_MAX = 5;

/** Délai d'un téléchargement d'archive, en secondes. */
const DELAI_ARCHIVE = 300;

/** Délai d'une requête courte (SHA256SUMS, API), en secondes. */
const DELAI_COURT = 30;

/** Taille maximale lue pour SHA256SUMS ou la réponse de l'API, en octets. */
const TAILLE_MAX_TEXTE = 1048576;

// Priorité tardive : voit la réponse d'éventuels autres filtres (dont Plugin Update Checker, 10).
add_filter( 'upgrader_pre_download', __NAMESPACE__ . '\\verifier_telechargement', 999, 4 );

/**
 * La vérification d'empreinte est-elle exigée ? Vrai par défaut ; constante YUME_EXIGER_EMPREINTE
 * (wp-config.php) puis filtre yume_updater_exiger_empreinte.
 *
 * @return bool
 */
function exiger_empreinte(): bool {
	$exiger = true;
	if ( defined( 'YUME_EXIGER_EMPREINTE' ) ) {
		$exiger = filter_var( constant( 'YUME_EXIGER_EMPREINTE' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true;
	}
	/**
	 * Exige (vrai, défaut) ou non la vérification SHA256SUMS des mises à jour Yume.
	 *
	 * Ne la désactiver qu'en secours : sans elle, quiconque publie une release sur le dépôt
	 * fait installer son code sur le site.
	 *
	 * @param bool $exiger Valeur de YUME_EXIGER_EMPREINTE, vrai par défaut.
	 */
	return (bool) apply_filters( 'yume_updater_exiger_empreinte', $exiger );
}

/**
 * Filtre upgrader_pre_download : télécharge et vérifie une archive Yume.
 *
 * @param bool|string|\WP_Error $reponse    Décision courante (false : téléchargement par WordPress).
 * @param string                $paquet     URL du paquet.
 * @param object|null           $upgrader   Instance de WP_Upgrader.
 * @param array                 $hook_extra Contexte (plugin ou theme).
 * @return bool|string|\WP_Error Chemin de l'archive vérifiée, WP_Error, ou $reponse intacte.
 */
function verifier_telechargement( $reponse, $paquet, $upgrader = null, $hook_extra = array() ) {
	if ( is_wp_error( $reponse ) || ! is_string( $paquet ) || ! preg_match( '#^https?://#i', $paquet ) ) {
		return $reponse;
	}
	$type = type_paquet( $paquet, is_array( $hook_extra ) ? $hook_extra : array() );
	if ( '' === $type || ! exiger_empreinte() ) {
		return $reponse;
	}

	if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) && method_exists( $upgrader->skin, 'feedback' ) ) {
		$upgrader->skin->feedback( __( 'Téléchargement et vérification de l’empreinte SHA-256 de la mise à jour…', 'yume-core' ) );
	}

	// Une archive déjà fournie par un autre filtre est vérifiée telle quelle.
	$existant = is_string( $reponse ) && '' !== $reponse && is_file( $reponse ) ? $reponse : '';

	$resultat = verifier_paquet( $type, $paquet, $existant );
	if ( is_wp_error( $resultat ) ) {
		journaliser( $resultat, $paquet );
		if ( '' !== $existant ) {
			wp_delete_file( $existant );
		}
	}
	return $resultat;
}

/**
 * Le paquet est-il une mise à jour Yume ? Renvoie 'plugin', 'theme' ou chaîne vide.
 *
 * @param string $paquet     URL du paquet.
 * @param array  $hook_extra Contexte de WP_Upgrader.
 * @return string
 */
function type_paquet( string $paquet, array $hook_extra ): string {
	if ( isset( $hook_extra['plugin'] ) && base_plugin() === $hook_extra['plugin'] ) {
		return 'plugin';
	}
	if ( isset( $hook_extra['theme'] ) && THEME === $hook_extra['theme'] ) {
		return 'theme';
	}
	// Sans contexte : paquet proposé pour Yume, ou archive de release du dépôt configuré.
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$offre = offre( $type );
		if ( '' !== $offre['paquet'] && $offre['paquet'] === $paquet ) {
			return $type;
		}
	}
	$source = analyser_url( $paquet );
	if ( $source && 'navigateur' === $source['forme'] ) {
		if ( ARCHIVE_PLUGIN === $source['archive'] ) {
			return 'plugin';
		}
		if ( ARCHIVE_THEME === $source['archive'] ) {
			return 'theme';
		}
	}
	return '';
}

/**
 * Offre de mise à jour connue pour le plugin ou le thème (transients WordPress, puis état de
 * Plugin Update Checker).
 *
 * @param string $type 'plugin' ou 'theme'.
 * @return array{version:string,paquet:string}
 */
function offre( string $type ): array {
	$version = '';
	$paquet  = '';
	if ( 'plugin' === $type ) {
		$transient = get_site_transient( 'update_plugins' );
		$item      = is_object( $transient ) && isset( $transient->response[ base_plugin() ] ) ? (array) $transient->response[ base_plugin() ] : array();
	} else {
		$transient = get_site_transient( 'update_themes' );
		$item      = is_object( $transient ) && isset( $transient->response[ THEME ] ) ? (array) $transient->response[ THEME ] : array();
	}
	if ( isset( $item['new_version'], $item['package'] ) ) {
		$version = (string) $item['new_version'];
		$paquet  = (string) $item['package'];
	}
	if ( '' === $version ) {
		$verificateur = verificateur( $type );
		$maj          = is_object( $verificateur ) && method_exists( $verificateur, 'getUpdate' ) ? $verificateur->getUpdate() : null;
		if ( is_object( $maj ) && isset( $maj->version ) ) {
			$version = (string) $maj->version;
			$paquet  = isset( $maj->download_url ) ? (string) $maj->download_url : '';
		}
	}
	return array(
		'version' => $version,
		'paquet'  => $paquet,
	);
}

/**
 * Analyse une URL d'archive de release GitHub.
 *
 * Deux formes : https://github.com/<dépôt>/releases/download/<tag>/<archive> (dépôt public) et
 * https://api.github.com/repos/<dépôt>/releases/assets/<id> (dépôt privé, avec jeton).
 *
 * @param string $url URL.
 * @return array|null Clés forme, depot, tag, archive (navigateur) ou id (api) ; null sinon.
 */
function analyser_url( string $url ): ?array {
	if ( preg_match( '#^https://github\.com/([^/?\#]+/[^/?\#]+)/releases/download/([^/?\#]+)/([^/?\#]+)$#', $url, $m ) ) {
		return array(
			'forme'   => 'navigateur',
			'depot'   => $m[1],
			'tag'     => rawurldecode( $m[2] ),
			'archive' => rawurldecode( $m[3] ),
		);
	}
	if ( preg_match( '#^https://api\.github\.com/repos/([^/?\#]+/[^/?\#]+)/releases/assets/([0-9]+)$#', $url, $m ) ) {
		return array(
			'forme' => 'api',
			'depot' => $m[1],
			'id'    => (int) $m[2],
		);
	}
	return null;
}

/**
 * Nom lisible du paquet pour les messages.
 *
 * @param string $type 'plugin' ou 'theme'.
 * @return string
 */
function nom_paquet( string $type ): string {
	return 'theme' === $type ? __( 'du thème Yume', 'yume-core' ) : __( 'de l’extension Yume Core', 'yume-core' );
}

/**
 * Construit une erreur de vérification.
 *
 * @param string $code   Code d'erreur (suffixe).
 * @param string $type   'plugin' ou 'theme'.
 * @param string $raison Raison, déjà traduite.
 * @return \WP_Error
 */
function refus( string $code, string $type, string $raison ): \WP_Error {
	return new \WP_Error(
		'yume_maj_' . $code,
		/* translators: 1 : « de l'extension Yume Core » ou « du thème Yume », 2 : raison du refus. */
		sprintf( __( 'Mise à jour %1$s refusée : %2$s', 'yume-core' ), nom_paquet( $type ), $raison )
	);
}

/**
 * Télécharge (si besoin) et vérifie l'archive d'une mise à jour Yume.
 *
 * @param string $type     'plugin' ou 'theme'.
 * @param string $paquet   URL du paquet.
 * @param string $existant Archive déjà téléchargée, ou chaîne vide.
 * @return string|\WP_Error Chemin de l'archive vérifiée.
 */
function verifier_paquet( string $type, string $paquet, string $existant = '' ) {
	$archive_attendue = 'theme' === $type ? ARCHIVE_THEME : ARCHIVE_PLUGIN;
	$depot            = depot();
	$source           = analyser_url( $paquet );
	if ( ! $source || 0 !== strcasecmp( $source['depot'], $depot ) ) {
		/* translators: %s : dépôt « propriétaire/dépôt ». */
		return refus( 'source_inconnue', $type, sprintf( __( 'l’archive ne provient pas d’une release GitHub du dépôt %s.', 'yume-core' ), $depot ) );
	}

	$offre = offre( $type );
	if ( '' === $offre['version'] ) {
		return refus( 'version_inconnue', $type, __( 'aucune version proposée n’est connue pour ce paquet ; relancez la recherche de mises à jour.', 'yume-core' ) );
	}

	$entetes = array();
	if ( 'navigateur' === $source['forme'] ) {
		$tag         = $source['tag'];
		$archive     = $source['archive'];
		$url_sommes  = 'https://github.com/' . $source['depot'] . '/releases/download/' . rawurlencode( $tag ) . '/' . FICHIER_EMPREINTES;
		$url_archive = $paquet;
	} else {
		$release = release_par_asset( $source['depot'], (int) $source['id'] );
		if ( is_wp_error( $release ) ) {
			return refus( 'release_introuvable', $type, $release->get_error_message() );
		}
		$tag         = $release['tag'];
		$archive     = $release['archive'];
		$url_sommes  = $release['sommes'];
		$url_archive = $paquet;
		$entetes     = array( 'Accept' => 'application/octet-stream' );
	}

	if ( $archive !== $archive_attendue ) {
		/* translators: 1 : archive reçue, 2 : archive attendue. */
		return refus( 'archive_inattendue', $type, sprintf( __( 'archive « %1$s » reçue, « %2$s » attendue.', 'yume-core' ), $archive, $archive_attendue ) );
	}
	if ( 'v' . $offre['version'] !== $tag ) {
		/* translators: 1 : tag de la release, 2 : version attendue. */
		return refus( 'version', $type, sprintf( __( 'la release %1$s ne correspond pas à la version attendue %2$s.', 'yume-core' ), $tag, $offre['version'] ) );
	}

	if ( '' === $url_sommes ) {
		$sommes = new \WP_Error( 'yume_absent', __( 'fichier absent de la release', 'yume-core' ) );
	} else {
		$sommes = telecharger( $url_sommes, array( 'headers' => $entetes ) );
	}
	$empreintes = is_wp_error( $sommes ) ? array() : lire_empreintes( (string) $sommes );
	if ( is_wp_error( $sommes ) && 'yume_hote_refuse' === $sommes->get_error_code() ) {
		return refus( 'hote_refuse', $type, $sommes->get_error_message() );
	}
	if ( is_wp_error( $sommes ) || ! $empreintes ) {
		return refus(
			'sha256sums_absent',
			$type,
			/* translators: 1 : SHA256SUMS, 2 : tag de la release. */
			sprintf( __( 'le fichier %1$s de la release %2$s est introuvable ou illisible.', 'yume-core' ), FICHIER_EMPREINTES, $tag )
		);
	}
	if ( ! isset( $empreintes[ $archive ] ) ) {
		/* translators: 1 : nom de l'archive, 2 : SHA256SUMS. */
		return refus( 'empreinte_absente', $type, sprintf( __( '%1$s ne figure pas dans %2$s.', 'yume-core' ), $archive, FICHIER_EMPREINTES ) );
	}
	if ( false === $empreintes[ $archive ] ) {
		/* translators: 1 : nom de l'archive, 2 : SHA256SUMS. */
		return refus( 'empreinte_ambigue', $type, sprintf( __( '%1$s figure plusieurs fois, avec des empreintes différentes, dans %2$s.', 'yume-core' ), $archive, FICHIER_EMPREINTES ) );
	}

	$fichier = $existant;
	if ( '' === $fichier ) {
		$fichier = fichier_temporaire( $paquet );
		if ( '' === $fichier ) {
			return refus( 'telechargement', $type, __( 'impossible de créer un fichier temporaire.', 'yume-core' ) );
		}
		$telechargement = telecharger(
			$url_archive,
			array(
				'headers'  => $entetes,
				'fichier'  => $fichier,
				'delai'    => DELAI_ARCHIVE,
				'illimite' => true,
			)
		);
		if ( is_wp_error( $telechargement ) ) {
			wp_delete_file( $fichier );
			$code = 'yume_hote_refuse' === $telechargement->get_error_code() ? 'hote_refuse' : 'telechargement';
			return refus( $code, $type, $telechargement->get_error_message() );
		}
	}

	$calcule = is_readable( $fichier ) ? (string) hash_file( 'sha256', $fichier ) : '';
	if ( '' === $calcule || ! hash_equals( $empreintes[ $archive ], $calcule ) ) {
		if ( $fichier !== $existant ) {
			wp_delete_file( $fichier );
		}
		return refus(
			'empreinte_differente',
			$type,
			/* translators: 1 : nom de l'archive, 2 : SHA256SUMS. */
			sprintf( __( 'l’empreinte SHA-256 de %1$s ne correspond pas à celle de %2$s (archive altérée ou corrompue).', 'yume-core' ), $archive, FICHIER_EMPREINTES )
		);
	}
	return $fichier;
}

/**
 * Release (dernière publiée) portant l'asset $id, via l'API GitHub : tag, nom de l'archive et
 * URL API de son SHA256SUMS.
 *
 * @param string $depot Dépôt « propriétaire/dépôt ».
 * @param int    $id    Identifiant de l'asset.
 * @return array{tag:string,archive:string,sommes:string}|\WP_Error
 */
function release_par_asset( string $depot, int $id ) {
	$corps = telecharger( 'https://api.github.com/repos/' . $depot . '/releases/latest', array( 'headers' => array( 'Accept' => 'application/vnd.github+json' ) ) );
	if ( is_wp_error( $corps ) ) {
		return $corps;
	}
	$release = json_decode( (string) $corps, true );
	if ( ! is_array( $release ) || empty( $release['tag_name'] ) || ! isset( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
		return new \WP_Error( 'yume_release_illisible', __( 'réponse de l’API GitHub illisible.', 'yume-core' ) );
	}
	$archive = '';
	$sommes  = '';
	$prefixe = 'https://api.github.com/repos/' . $depot . '/releases/assets/';
	foreach ( $release['assets'] as $asset ) {
		if ( ! is_array( $asset ) || ! isset( $asset['id'], $asset['name'] ) ) {
			continue;
		}
		if ( (int) $asset['id'] === $id ) {
			$archive = (string) $asset['name'];
		}
		if ( FICHIER_EMPREINTES === $asset['name'] && isset( $asset['url'] ) && 0 === strpos( (string) $asset['url'], $prefixe ) ) {
			$sommes = (string) $asset['url'];
		}
	}
	if ( '' === $archive ) {
		return new \WP_Error( 'yume_asset_introuvable', __( 'l’archive n’appartient pas à la dernière release publiée.', 'yume-core' ) );
	}
	return array(
		'tag'     => (string) $release['tag_name'],
		'archive' => $archive,
		'sommes'  => $sommes,
	);
}

/**
 * Lit un fichier SHA256SUMS (sortie de sha256sum ou shasum -a 256 : « <empreinte>  <nom> » ou
 * « <empreinte> *<nom> »). Les noms doivent être nus (sans dossier).
 *
 * @param string $contenu Contenu du fichier.
 * @return array<string,string|false> Nom => empreinte en minuscules (false si ambiguë).
 */
function lire_empreintes( string $contenu ): array {
	$empreintes = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $contenu ) as $ligne ) {
		if ( ! preg_match( '/^([0-9A-Fa-f]{64}) [ *]([^\/\\\\]+)$/', trim( $ligne ), $m ) ) {
			continue;
		}
		$nom       = $m[2];
		$empreinte = strtolower( $m[1] );
		if ( isset( $empreintes[ $nom ] ) && $empreintes[ $nom ] !== $empreinte ) {
			$empreintes[ $nom ] = false;
			continue;
		}
		$empreintes[ $nom ] = $empreinte;
	}
	return $empreintes;
}

/**
 * L'URL est-elle une URL HTTPS vers un hôte autorisé (port par défaut, sans identifiants) ?
 *
 * @param string $url URL.
 * @return bool
 */
function url_autorisee( string $url ): bool {
	$parties = wp_parse_url( $url );
	if ( ! is_array( $parties ) || 'https' !== strtolower( $parties['scheme'] ?? '' ) ) {
		return false;
	}
	if ( isset( $parties['user'] ) || isset( $parties['pass'] ) || ( isset( $parties['port'] ) && 443 !== (int) $parties['port'] ) ) {
		return false;
	}
	return in_array( strtolower( $parties['host'] ?? '' ), HOTES_AUTORISES, true );
}

/**
 * Télécharge une URL GitHub en suivant soi-même les redirections, chacune vérifiée contre la
 * liste des hôtes autorisés. Le jeton YUME_GITHUB_TOKEN n'est envoyé qu'à api.github.com.
 *
 * @param string $url     URL de départ.
 * @param array  $options headers (array), fichier (chemin : corps écrit dans ce fichier),
 *                        delai (secondes), illimite (bool : pas de limite de taille).
 * @return string|true|\WP_Error Corps (sans fichier), true (avec fichier), ou erreur.
 */
function telecharger( string $url, array $options = array() ) {
	$fichier = isset( $options['fichier'] ) ? (string) $options['fichier'] : '';
	for ( $saut = 0; $saut <= REDIRECTIONS_MAX; $saut++ ) {
		if ( ! url_autorisee( $url ) ) {
			$hote = (string) wp_parse_url( $url, PHP_URL_HOST );
			return new \WP_Error(
				'yume_hote_refuse',
				/* translators: %s : hôte ou URL refusé. */
				sprintf( __( 'téléchargement vers un hôte non autorisé (%s).', 'yume-core' ), '' !== $hote ? $hote : $url )
			);
		}
		$entetes = isset( $options['headers'] ) && is_array( $options['headers'] ) ? $options['headers'] : array();
		$jeton   = jeton();
		if ( '' !== $jeton && 'api.github.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			$entetes['Authorization'] = 'token ' . $jeton;
		}
		$args = array(
			'timeout'     => isset( $options['delai'] ) ? (int) $options['delai'] : DELAI_COURT,
			'redirection' => 0,
			'headers'     => $entetes,
		);
		if ( '' !== $fichier ) {
			$args['stream']   = true;
			$args['filename'] = $fichier;
		}
		if ( empty( $options['illimite'] ) ) {
			$args['limit_response_size'] = TAILLE_MAX_TEXTE;
		}
		$reponse = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $reponse ) ) {
			return new \WP_Error( 'yume_telechargement', sprintf( '%s (%s)', $reponse->get_error_message(), $url ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $reponse );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$cible = wp_remote_retrieve_header( $reponse, 'location' );
			$cible = is_array( $cible ) ? (string) end( $cible ) : (string) $cible;
			if ( '' === $cible ) {
				break;
			}
			$url = \WP_Http::make_absolute_url( $cible, $url );
			continue;
		}
		if ( 200 !== $code ) {
			/* translators: 1 : code HTTP, 2 : URL. */
			return new \WP_Error( 'yume_telechargement', sprintf( __( 'réponse HTTP %1$d pour %2$s.', 'yume-core' ), $code, $url ) );
		}
		return '' !== $fichier ? true : (string) wp_remote_retrieve_body( $reponse );
	}
	return new \WP_Error( 'yume_telechargement', __( 'trop de redirections ou redirection invalide.', 'yume-core' ) );
}

/**
 * Crée un fichier temporaire pour l'archive téléchargée.
 *
 * @param string $paquet URL du paquet (sert à nommer le fichier).
 * @return string Chemin, ou chaîne vide.
 */
function fichier_temporaire( string $paquet ): string {
	if ( ! function_exists( 'wp_tempnam' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$chemin = wp_tempnam( (string) wp_parse_url( $paquet, PHP_URL_PATH ) );
	return is_string( $chemin ) && '' !== $chemin ? $chemin : '';
}

/**
 * Consigne un refus dans le journal PHP et le signale par l'action yume_updater_refus.
 *
 * @param \WP_Error $erreur Refus.
 * @param string    $paquet URL du paquet.
 */
function journaliser( \WP_Error $erreur, string $paquet ): void {
	error_log( sprintf( '[yume-core] %s [%s] paquet : %s', $erreur->get_error_message(), $erreur->get_error_code(), $paquet ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- événement de sécurité à tracer.
	/**
	 * Une mise à jour Yume vient d'être refusée par la vérification d'intégrité.
	 *
	 * @param \WP_Error $erreur Refus.
	 * @param string    $paquet URL du paquet.
	 */
	do_action( 'yume_updater_refus', $erreur, $paquet );
}
