<?php
/**
 * Lecture hors ligne (AMEL-07) : manifeste web, service worker et page « Hors ligne ».
 *
 * Trois adresses servies par le plugin à la racine du site (paramètres de requête : aucune
 * règle de réécriture à régénérer, compatibles avec le cache de page de WordPress.com) :
 *   /?yume_manifest=1   manifeste web (application/manifest+json) ;
 *   /?yume_sw=1         service worker (portée « / », en-tête Service-Worker-Allowed) ;
 *   /?yume_hors_ligne=1 page de repli affichée hors ligne (liste des chapitres disponibles).
 *
 * Le service worker (assets/sw.js, versionné par YUME_CORE_VERSION) ne met en cache que les pages
 * de lecture publiques (/lire/…) consultées, le chapitre suivant et les ressources statiques.
 * Choix de sécurité : seul le HTML d'un visiteur non connecté entre dans le cache — le serveur
 * marque ces pages avec <meta name="yume-hors-ligne" content="lecture">, jamais écrit pour un
 * membre connecté ; pour un membre, le service worker demande une copie anonyme (sans cookie).
 * Aucun nonce, pseudo ou position de lecture n'est donc stocké, et rien n'est à purger à la
 * déconnexion (un appareil partagé ne garde que des pages publiques).
 *
 * Désactivation : réglage Yume → Réglages « Lecture hors ligne » (pwa_hors_ligne) ou filtre
 * yume_pwa_actif. Désactivé, /?yume_sw=1 sert un service worker qui vide les caches Yume et se
 * désinscrit, et les pages désinscrivent l'ancien service worker.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

/** Paramètre de requête du service worker. */
const QV_SW = 'yume_sw';

/** Paramètre de requête du manifeste web. */
const QV_MANIFESTE = 'yume_manifest';

/** Paramètre de requête de la page « Hors ligne ». */
const QV_HORS_LIGNE = 'yume_hors_ligne';

/** Nombre de pages de lecture gardées hors ligne (LRU), par défaut. */
const PWA_MAX_CHAPITRES = 30;

/**
 * La lecture hors ligne est-elle active ? Réglage pwa_hors_ligne (défaut : oui) puis filtre.
 */
function pwa_actif(): bool {
	$actif = function_exists( 'yume_setting' ) ? (bool) yume_setting( 'pwa_hors_ligne', true ) : true;
	/**
	 * Active ou désactive la lecture hors ligne (manifeste et service worker).
	 *
	 * @param bool $actif Valeur du réglage « Lecture hors ligne ».
	 */
	return (bool) apply_filters( 'yume_pwa_actif', $actif );
}

/**
 * Champ « Lecture hors ligne » de Yume → Réglages (filtre yume_reglages_champs du module core).
 *
 * @param array $champs Champs.
 * @return array
 */
function champ_reglage_pwa( $champs ): array {
	$champs   = is_array( $champs ) ? $champs : array();
	$champs[] = array(
		'key'         => 'pwa_hors_ligne',
		'label'       => __( 'Lecture hors ligne', 'yume-core' ),
		'type'        => 'checkbox',
		'section'     => 'site',
		'default'     => true,
		'description' => __( 'Installation du site comme application et lecture hors ligne des chapitres déjà ouverts (30 au plus) et du chapitre suivant. Seules les pages publiques sont gardées sur l’appareil.', 'yume-core' ),
	);
	return $champs;
}
add_filter( 'yume_reglages_champs', __NAMESPACE__ . '\\champ_reglage_pwa' );

/**
 * Chemin de la racine du site (« / » ou « /sous-dossier/ »).
 */
function chemin_base(): string {
	$chemin = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	return '/' . ltrim( trailingslashit( $chemin ), '/' );
}

/**
 * Chemin d'une URL (sans domaine ni paramètres), ou '' si elle n'est pas analysable.
 *
 * @param string $url URL.
 */
function chemin_de( string $url ): string {
	$chemin = wp_parse_url( $url, PHP_URL_PATH );
	return is_string( $chemin ) ? $chemin : '';
}

/**
 * Adresse d'une ressource PWA : racine du site + paramètre.
 *
 * @param string $parametre QV_SW, QV_MANIFESTE ou QV_HORS_LIGNE.
 */
function url_pwa( string $parametre ): string {
	return add_query_arg( $parametre, '1', home_url( '/' ) );
}

/**
 * Chemins que le service worker ne touche jamais : administration, connexion, REST.
 *
 * @return string[]
 */
function chemins_systeme(): array {
	return array_values(
		array_unique(
			array_filter(
				array(
					chemin_de( admin_url( '/' ) ),
					chemin_de( site_url( 'wp-login.php' ) ),
					chemin_de( rest_url( '/' ) ),
				)
			)
		)
	);
}

/**
 * Pages personnelles jamais mises en cache par le service worker (espace équipe, compte,
 * connexion en façade ; hors ligne : page « Hors ligne »). Filtre yume_pwa_exclus.
 *
 * @return string[]
 */
function chemins_exclus(): array {
	$chemins = array();
	if ( function_exists( 'yume_url_page' ) ) {
		foreach ( array( 'equipe', 'publier', 'membres', 'compte', 'connexion' ) as $cle ) {
			$chemin = chemin_de( yume_url_page( $cle ) );
			if ( '' !== $chemin && '/' !== $chemin && chemin_base() !== $chemin ) {
				$chemins[] = $chemin;
			}
		}
	}
	/**
	 * Filtre les pages exclues du cache du service worker (préfixes de chemin absolus).
	 *
	 * @param string[] $chemins Chemins.
	 */
	$chemins = (array) apply_filters( 'yume_pwa_exclus', $chemins );
	return array_values( array_unique( array_filter( array_map( 'strval', $chemins ) ) ) );
}

/**
 * Configuration transmise au service worker (self.YUME_SW_CONFIG).
 *
 * @return array<string,mixed>
 */
function configuration_sw(): array {
	$base      = chemin_base();
	$statiques = array(
		trailingslashit( chemin_de( get_template_directory_uri() ) ),
		trailingslashit( chemin_de( get_stylesheet_directory_uri() ) ),
		trailingslashit( chemin_de( YUME_CORE_URL ) ),
		trailingslashit( chemin_de( includes_url() ) ),
	);
	/**
	 * Nombre de pages de lecture gardées hors ligne (les moins récemment lues sont retirées).
	 *
	 * @param int $max Maximum (30 par défaut).
	 */
	$max = (int) apply_filters( 'yume_pwa_max_chapitres', PWA_MAX_CHAPITRES );
	return array(
		'version'      => YUME_CORE_VERSION,
		'base'         => $base,
		'lecture'      => $base . 'lire/',
		'horsLigne'    => $base . '?' . QV_HORS_LIGNE . '=1',
		'systeme'      => chemins_systeme(),
		'exclus'       => chemins_exclus(),
		'statiques'    => array_values( array_unique( array_filter( $statiques, static fn( $c ) => '/' !== $c ) ) ),
		'maxChapitres' => max( 1, min( 200, $max ) ),
		'maxStatiques' => 120,
	);
}

/**
 * Code du service worker : configuration puis assets/sw.js ; si la lecture hors ligne est
 * désactivée, un service worker qui vide les caches Yume et se désinscrit.
 */
function contenu_sw(): string {
	if ( ! pwa_actif() ) {
		return "/* Yume Novel : lecture hors ligne désactivée. */\n"
			. "self.addEventListener('install',function(){self.skipWaiting();});\n"
			. "self.addEventListener('activate',function(e){e.waitUntil(caches.keys().then(function(n){return Promise.all(n.filter(function(x){return x.indexOf('yume-')===0;}).map(function(x){return caches.delete(x);}));}).then(function(){return self.registration.unregister();}));});\n";
	}
	$fichier = __DIR__ . '/assets/sw.js';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du plugin.
	$code = is_readable( $fichier ) ? (string) file_get_contents( $fichier ) : '';
	return 'self.YUME_SW_CONFIG = ' . wp_json_encode( configuration_sw(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . ";\n" . $code;
}

/**
 * En-têtes HTTP du service worker. Cache-Control sans mise en cache : le navigateur (et un cache
 * de page) ne doit jamais retarder une nouvelle version ou la désactivation.
 *
 * @return array<string,string>
 */
function entetes_sw(): array {
	return array(
		'Content-Type'           => 'application/javascript; charset=utf-8',
		'Service-Worker-Allowed' => chemin_base(),
		'Cache-Control'          => 'no-cache, no-store, max-age=0, must-revalidate',
		'X-Content-Type-Options' => 'nosniff',
		'X-Robots-Tag'           => 'noindex',
	);
}

/**
 * Couleur d'une teinte de la palette du thème (theme.json), ou $defaut.
 *
 * @param string $slug   Slug (fond, bande…).
 * @param string $defaut Couleur par défaut.
 */
function couleur_theme( string $slug, string $defaut ): string {
	$palette = function_exists( 'wp_get_global_settings' ) ? wp_get_global_settings( array( 'color', 'palette' ) ) : array();
	$listes  = is_array( $palette ) ? ( isset( $palette['theme'] ) ? array( $palette['theme'] ) : array( $palette ) ) : array();
	foreach ( $listes as $liste ) {
		foreach ( (array) $liste as $couleur ) {
			if ( is_array( $couleur ) && ( $couleur['slug'] ?? '' ) === $slug && is_string( $couleur['color'] ?? null ) ) {
				$hex = sanitize_hex_color( $couleur['color'] );
				if ( $hex ) {
					return $hex;
				}
			}
		}
	}
	return $defaut;
}

/**
 * Icônes du manifeste : icône du site (Réglages → Général) si définie, sinon l'icône SVG du plugin.
 *
 * @return array<int,array<string,string>>
 */
function icones_manifeste(): array {
	$icones = array();
	if ( (int) get_option( 'site_icon' ) > 0 ) {
		$type = (string) get_post_mime_type( (int) get_option( 'site_icon' ) );
		foreach ( array( 192, 512 ) as $taille ) {
			$url = get_site_icon_url( $taille );
			if ( $url ) {
				$icones[] = array(
					'src'     => $url,
					'sizes'   => $taille . 'x' . $taille,
					'type'    => '' !== $type ? $type : 'image/png',
					'purpose' => 'any',
				);
			}
		}
	}
	if ( ! $icones ) {
		$icones[] = array(
			'src'     => YUME_CORE_URL . 'includes/reader/assets/icone.svg?ver=' . rawurlencode( YUME_CORE_VERSION ),
			'sizes'   => 'any',
			'type'    => 'image/svg+xml',
			'purpose' => 'any',
		);
	}
	return $icones;
}

/**
 * Manifeste web.
 *
 * @return array<string,mixed>
 */
function manifeste(): array {
	$base        = chemin_base();
	$description = wp_strip_all_tags( (string) get_bloginfo( 'description' ) );
	$manifeste   = array(
		'id'               => $base,
		'name'             => 'Yume Novel',
		'short_name'       => 'Yume',
		'description'      => '' !== $description ? $description : __( 'Traductions françaises de light novels, à lire en ligne.', 'yume-core' ),
		'lang'             => 'fr',
		'dir'              => 'ltr',
		'start_url'        => $base,
		'scope'            => $base,
		'display'          => 'standalone',
		'orientation'      => 'any',
		'background_color' => couleur_theme( 'fond', '#1b1231' ),
		'theme_color'      => couleur_theme( 'bande', '#241740' ),
		'categories'       => array( 'books', 'entertainment' ),
		'icons'            => icones_manifeste(),
	);
	/**
	 * Filtre le manifeste web du site.
	 *
	 * @param array $manifeste Manifeste.
	 */
	return (array) apply_filters( 'yume_pwa_manifeste', $manifeste );
}

/**
 * Page « Hors ligne » : document autonome (aucune donnée de membre), qui liste les chapitres
 * gardés sur l'appareil en lisant le cache du service worker.
 */
function page_hors_ligne(): string {
	$fond   = couleur_theme( 'fond', '#1b1231' );
	$texte  = couleur_theme( 'texte', '#ebe3f2' );
	$accent = couleur_theme( 'accent', '#f3a6c8' );
	$carte  = couleur_theme( 'carte', '#2d1f4f' );
	$css    = 'body{margin:0;min-height:100vh;background:' . $fond . ';color:' . $texte . ';font:17px/1.6 "Nunito Sans",system-ui,sans-serif}'
		. 'main{max-width:40rem;margin:0 auto;padding:48px 16px}h1{font-size:1.8rem;line-height:1.2;margin:0 0 12px}'
		. 'a{color:' . $accent . ';text-underline-offset:3px}ul{padding:0;list-style:none}li{margin:8px 0;padding:10px 14px;border-radius:10px;background:' . $carte . '}'
		. 'button{font:inherit;padding:8px 16px;border-radius:6px;border:1px solid ' . $accent . ';background:none;color:inherit;cursor:pointer}'
		. 'button:focus-visible,a:focus-visible{outline:2px solid ' . $accent . ';outline-offset:2px}';
	$script = '(function(){var l=document.querySelector("[data-yn-hors-ligne-liste]"),v=document.querySelector("[data-yn-hors-ligne-vide]");'
		. 'document.querySelector("[data-yn-reessayer]").addEventListener("click",function(){window.location.reload();});'
		. 'if(!window.caches||!l){return;}caches.keys().then(function(n){return Promise.all(n.filter(function(x){return x.indexOf("yume-lecture-")===0;}).map(function(x){return caches.open(x).then(function(c){return c.keys().then(function(k){return Promise.all(k.slice().reverse().map(function(r){return c.match(r).then(function(p){return p?p.text():"";}).then(function(t){var m=/<title[^>]*>([^<]*)<\/title>/i.exec(t),e=document.createElement("textarea");e.innerHTML=m?m[1]:r.url;return{u:r.url,t:e.value};});}));});});}));})'
		. '.then(function(g){var a=[].concat.apply([],g);if(!a.length){return;}v.hidden=true;a.forEach(function(x){var li=document.createElement("li"),lien=document.createElement("a");lien.href=x.u;lien.textContent=x.t;li.appendChild(lien);l.appendChild(li);});}).catch(function(){});}());';
	return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<meta name="robots" content="noindex"><title>' . esc_html__( 'Hors ligne · Yume Novel', 'yume-core' ) . '</title>'
		. '<style>' . $css . '</style></head><body><main>'
		. '<h1>' . esc_html__( 'Vous êtes hors ligne', 'yume-core' ) . '</h1>'
		. '<p>' . esc_html__( 'Cette page n’est pas disponible sans connexion. Les chapitres déjà ouverts sur cet appareil restent lisibles :', 'yume-core' ) . '</p>'
		. '<ul data-yn-hors-ligne-liste></ul>'
		. '<p data-yn-hors-ligne-vide>' . esc_html__( 'Aucun chapitre gardé sur cet appareil pour l’instant.', 'yume-core' ) . '</p>'
		. '<p><button type="button" data-yn-reessayer>' . esc_html__( 'Réessayer', 'yume-core' ) . '</button></p>'
		. '</main><script>' . $script . '</script></body></html>';
}

/**
 * Ressource PWA demandée par la requête courante (QV_SW, QV_MANIFESTE, QV_HORS_LIGNE) ou ''.
 * Seulement à la racine du site : /lire/…?yume_sw=1 reste une page ordinaire.
 */
function ressource_demandee(): string {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- lecture seule, aucune action.
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé seulement.
	if ( 'GET' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
		return '';
	}
	if ( untrailingslashit( chemin_de( $uri ) ) !== untrailingslashit( chemin_base() ) ) {
		return '';
	}
	foreach ( array( QV_SW, QV_MANIFESTE, QV_HORS_LIGNE ) as $parametre ) {
		if ( isset( $_GET[ $parametre ] ) && '1' === $_GET[ $parametre ] ) {
			return $parametre;
		}
	}
	// phpcs:enable
	return '';
}

/**
 * Sert le manifeste, le service worker ou la page « Hors ligne » et termine la requête.
 */
function servir_ressource_pwa(): void {
	$ressource = ressource_demandee();
	if ( '' === $ressource || is_admin() ) {
		return;
	}
	// Cache de page de WordPress.com (Batcache) : jamais pour le service worker.
	if ( QV_SW === $ressource && function_exists( 'batcache_cancel' ) ) {
		batcache_cancel();
	}
	if ( QV_SW === $ressource ) {
		$entetes = entetes_sw();
		$corps   = contenu_sw();
	} elseif ( QV_MANIFESTE === $ressource ) {
		$entetes = array(
			'Content-Type'           => 'application/manifest+json; charset=utf-8',
			'Cache-Control'          => 'public, max-age=86400',
			'X-Content-Type-Options' => 'nosniff',
		);
		$corps   = (string) wp_json_encode( manifeste(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	} else {
		$entetes = array(
			'Content-Type'  => 'text/html; charset=utf-8',
			'Cache-Control' => 'public, max-age=3600',
			'X-Robots-Tag'  => 'noindex',
		);
		$corps   = page_hors_ligne();
	}
	if ( ! headers_sent() ) {
		status_header( 200 );
		foreach ( $entetes as $nom => $valeur ) {
			header( $nom . ': ' . $valeur );
		}
	}
	echo $corps; // phpcs:ignore WordPress.Security.EscapeOutput -- JS, JSON ou HTML construits et échappés ci-dessus.
	exit;
}
add_action( 'init', __NAMESPACE__ . '\\servir_ressource_pwa', 99 );

/**
 * La page courante fait-elle partie des chemins exclus (équipe, compte, connexion) ?
 */
function page_exclue_pwa(): bool {
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? chemin_de( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- comparé seulement.
	$chemin = '' !== $uri ? $uri : '/';
	foreach ( array_merge( chemins_systeme(), chemins_exclus() ) as $exclu ) {
		if ( str_starts_with( $chemin, $exclu ) ) {
			return true;
		}
	}
	return false;
}

/**
 * En-tête du document : lien vers le manifeste, et marqueur « page publique mémorisable » sur
 * les pages de lecture d'un visiteur non connecté (le service worker ne garde que celles-là).
 */
function entete_pwa(): void {
	if ( is_admin() || ! pwa_actif() ) {
		return;
	}
	printf( '<link rel="manifest" href="%s">' . "\n", esc_url( url_pwa( QV_MANIFESTE ) ) );
	if ( page_de_lecture() && ! is_user_logged_in() ) {
		echo '<meta name="yume-hors-ligne" content="lecture">' . "\n";
	}
}
add_action( 'wp_head', __NAMESPACE__ . '\\entete_pwa', 3 );

/**
 * Script d'enregistrement du service worker (pages publiques seulement). Sur une page de
 * lecture, il demande au service worker de garder la page et le chapitre suivant (rel=next)
 * ainsi que les ressources déjà chargées ; html[data-yn-hors-ligne="pret"] une fois fait.
 * Lecture hors ligne désactivée : désinscrit le service worker Yume encore présent.
 */
function script_pwa(): void {
	if ( is_admin() || is_feed() || is_embed() ) {
		return;
	}
	if ( ! pwa_actif() ) {
		$script = '(function(){if(!("serviceWorker" in navigator)){return;}navigator.serviceWorker.getRegistrations().then(function(l){l.forEach(function(r){var w=r.active||r.waiting||r.installing;if(w&&w.scriptURL.indexOf("' . QV_SW . '=1")>-1){try{w.postMessage({type:"yume-vider"});}catch(e){}r.unregister();}});}).catch(function(){});}());';
		wp_print_inline_script_tag( $script, array( 'id' => 'yume-pwa' ) );
		return;
	}
	if ( page_exclue_pwa() ) {
		return;
	}
	$donnees = array(
		'sw'      => url_pwa( QV_SW ),
		'portee'  => chemin_base(),
		'lecture' => page_de_lecture(),
	);
	$script  = '(function(c){if(!("serviceWorker" in navigator)||!window.isSecureContext){return;}var d=document.documentElement;'
		. 'function ressources(){var l=[];try{performance.getEntriesByType("resource").forEach(function(e){l.push(e.name);});}catch(e){}'
		. 'document.querySelectorAll("link[rel=stylesheet][href],script[src]").forEach(function(e){l.push(e.href||e.src);});return l;}'
		. 'navigator.serviceWorker.addEventListener("message",function(e){if(e.data&&e.data.type==="yume-memorise"){d.setAttribute("data-yn-hors-ligne","pret");}});'
		. 'function lancer(){navigator.serviceWorker.register(c.sw,{scope:c.portee}).then(function(){return navigator.serviceWorker.ready;}).then(function(r){'
		. 'if(!c.lecture||!r.active){d.setAttribute("data-yn-hors-ligne","actif");return;}var p=[location.origin+location.pathname],s=document.querySelector("link[rel=next][href]");if(s){p.push(s.href);}'
		. 'r.active.postMessage({type:"yume-memoriser",pages:p,ressources:ressources()});}).catch(function(){});}'
		. 'if(document.readyState==="complete"){lancer();}else{window.addEventListener("load",lancer);}'
		. '}(' . wp_json_encode( $donnees, JSON_UNESCAPED_SLASHES ) . '));';
	wp_print_inline_script_tag( $script, array( 'id' => 'yume-pwa' ) );
}
add_action( 'wp_footer', __NAMESPACE__ . '\\script_pwa', 30 );
