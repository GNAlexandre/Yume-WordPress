<?php
/**
 * Lecteur en ligne : enregistrement du bloc yume/reader-tools, script en ligne qui applique
 * les réglages de lecture avant le premier rendu (localStorage pour tous, réglages du compte
 * pour les membres) et configuration transmise au script de la barre de lecture.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Reader;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre le bloc yume/reader-tools.
 */
function enregistrer_blocs(): void {
	if ( function_exists( 'yume_register_dynamic_block' ) ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/reader-tools' );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_blocs' );

/**
 * Le rendu en cours est-il un aperçu de l'éditeur de blocs (ServerSideRender) ?
 */
function apercu_editeur(): bool {
	if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
		return false;
	}
	$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
	return '' === $route || str_contains( $route, '/block-renderer/' );
}

/**
 * Chapitre du contexte courant : bloc (postId) ou objet de la requête.
 *
 * @param \WP_Block|null $bloc Instance du bloc.
 */
function chapitre_courant( $bloc = null ): int {
	$id = 0;
	if ( $bloc instanceof \WP_Block && ! empty( $bloc->context['postId'] ) ) {
		$id = (int) $bloc->context['postId'];
	}
	if ( ! $id || 'yume_chapitre' !== get_post_type( $id ) ) {
		$id = (int) get_queried_object_id();
	}
	return 'yume_chapitre' === get_post_type( $id ) ? $id : 0;
}

/**
 * Tome dont la requête principale affiche la page « Illustrations », ou 0.
 */
function tome_illustrations_courant(): int {
	if ( ! function_exists( 'yume_est_page_illustrations' ) || ! yume_est_page_illustrations() ) {
		return 0;
	}
	$id = (int) get_queried_object_id();
	return 'yume_tome' === get_post_type( $id ) ? $id : 0;
}

/**
 * La page affichée est-elle une page de lecture (chapitre ou page Illustrations d'un tome) ?
 */
function page_de_lecture(): bool {
	return is_singular( 'yume_chapitre' ) || tome_illustrations_courant() > 0;
}

/**
 * Polices : slug => pile CSS (pour les scripts).
 *
 * @return array<string,string>
 */
function piles_polices(): array {
	return wp_list_pluck( polices(), 'pile' );
}

/**
 * Données de lecture propres au membre connecté (progression de l'œuvre, réglages).
 *
 * @param int $oeuvre_id Œuvre du chapitre.
 * @return array{progression:array|null,reglages:array|null}
 */
function donnees_membre( int $oeuvre_id ): array {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return array(
			'progression' => null,
			'reglages'    => null,
		);
	}
	$ligne = $oeuvre_id ? lignes_progression( $user_id, $oeuvre_id ) : array();
	$ligne = $ligne ? enrichir_ligne( $ligne[0] ) : null;
	return array(
		'progression' => $ligne,
		'reglages'    => reglages_enregistres( $user_id ),
	);
}

/**
 * Configuration du script de la barre de lecture pour un chapitre.
 *
 * @param int $chapitre_id Chapitre.
 * @return array<string,mixed>
 */
function configuration( int $chapitre_id ): array {
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $chapitre_id ) : 0;
	$tome_id   = function_exists( 'yume_get_tome_id' ) ? yume_get_tome_id( $chapitre_id ) : 0;
	$prev      = function_exists( 'yume_chapitre_voisin' ) ? yume_chapitre_voisin( $chapitre_id, 'prev' ) : null;
	$next      = function_exists( 'yume_chapitre_voisin' ) ? yume_chapitre_voisin( $chapitre_id, 'next' ) : null;
	// Premier chapitre d'un tome qui a une page Illustrations : ← y ramène.
	$illus    = function_exists( 'yume_url_illustrations_avant' ) ? yume_url_illustrations_avant( $chapitre_id ) : '';
	$membre   = donnees_membre( $oeuvre_id );
	$connecte = is_user_logged_in();
	return array(
		'chapitre'    => $chapitre_id,
		'tome'        => $tome_id,
		'oeuvre'      => $oeuvre_id,
		'titre'       => titre_position( $chapitre_id ),
		'url'         => (string) get_permalink( $chapitre_id ),
		'prev'        => '' !== $illus ? $illus : ( $prev ? (string) get_permalink( $prev ) : '' ),
		'next'        => $next ? (string) get_permalink( $next ) : '',
		'connecte'    => $connecte,
		'rest'        => $connecte ? esc_url_raw( rest_url( REST_NS . '/' ) ) : '',
		'nonce'       => $connecte ? wp_create_nonce( 'wp_rest' ) : '',
		'progression' => $membre['progression'],
		'reglages'    => $membre['reglages'],
		'defauts'     => defauts_reglages(),
		'bornes'      => bornes_reglages(),
		'polices'     => piles_polices(),
		'themes'      => libelles_themes(),
	);
}

/**
 * Configuration du script de la barre de lecture sur la page « Illustrations » d'un tome :
 * aucun chapitre (chapitre = 0) donc aucun suivi de lecture ni marque-page — la position
 * enregistrée du lecteur n'est jamais remplacée —, → ouvre le premier chapitre du tome.
 *
 * @param int $tome_id Tome.
 * @return array<string,mixed>
 */
function configuration_illustrations( int $tome_id ): array {
	$oeuvre_id = function_exists( 'yume_get_oeuvre_id' ) ? yume_get_oeuvre_id( $tome_id ) : 0;
	$chapitres = function_exists( 'yume_get_chapitres' ) ? yume_get_chapitres( $tome_id ) : array();
	$membre    = donnees_membre( $oeuvre_id );
	$connecte  = is_user_logged_in();
	return array(
		'chapitre'    => 0,
		'tome'        => $tome_id,
		'oeuvre'      => $oeuvre_id,
		'titre'       => __( 'Illustrations', 'yume-core' ),
		'url'         => function_exists( 'yume_url_illustrations' ) ? yume_url_illustrations( $tome_id ) : '',
		'prev'        => '',
		'next'        => $chapitres ? (string) get_permalink( $chapitres[0] ) : '',
		'connecte'    => $connecte,
		'rest'        => $connecte ? esc_url_raw( rest_url( REST_NS . '/' ) ) : '',
		'nonce'       => $connecte ? wp_create_nonce( 'wp_rest' ) : '',
		'progression' => $membre['progression'],
		'reglages'    => $membre['reglages'],
		'defauts'     => defauts_reglages(),
		'bornes'      => bornes_reglages(),
		'polices'     => piles_polices(),
		'themes'      => libelles_themes(),
	);
}

/**
 * Script en ligne (en-tête des pages de lecture : chapitres et page Illustrations, juste après
 * celui du thème) : applique les réglages mémorisés avant le premier rendu, sans attendre le
 * script de la barre. Pour un membre, les réglages du compte l'emportent et sont recopiés dans le stockage local.
 * Les options d'accessibilité (yn.a11y, appareil seulement) posent html[data-yn-contraste] et
 * html[data-yn-animations].
 */
function script_initialisation(): void {
	if ( ! page_de_lecture() ) {
		return;
	}
	$serveur = reglages_enregistres( get_current_user_id() );
	$donnees = array(
		'p' => piles_polices(),
		'd' => defauts_reglages(),
		'b' => bornes_reglages(),
		's' => $serveur,
		't' => THEMES,
	);
	$script  = '(function(c,d){var r=null,s=c.s,b=c.b,v,css=[];'
		. 'try{r=JSON.parse(window.localStorage.getItem("yn.reglages")||"null");}catch(e){r=null;}'
		// Options d'accessibilité de l'appareil (yn.a11y) : contraste renforcé, animations réduites.
		. 'try{var a=JSON.parse(window.localStorage.getItem("yn.a11y")||"null");if(a&&a.contraste===true){d.setAttribute("data-yn-contraste","renforce");}if(a&&a.animations==="reduites"){d.setAttribute("data-yn-animations","reduites");}}catch(e){}'
		. 'if(s){r=s;try{window.localStorage.setItem("yn.reglages",JSON.stringify({size:s.size,lh:s.lh,font:s.font,width:s.width,bgAlpha:s.bgAlpha}));'
		. 'if(c.t.indexOf(s.theme)>-1){window.localStorage.setItem("yn.theme",s.theme);}}catch(e){}'
		. 'if(c.t.indexOf(s.theme)>-1){d.setAttribute("data-yn-theme",s.theme);}}'
		. 'if(!r||typeof r!=="object"){return;}'
		. 'function n(x,k){x=parseFloat(x);return isFinite(x)?Math.min(b[k][1],Math.max(b[k][0],x)):null;}'
		. 'if((v=n(r.size,"size"))!==null&&v!==c.d.size){css.push("--yn-size:"+Math.round(v)+"px");}'
		. 'if((v=n(r.lh,"lh"))!==null&&v!==c.d.lh){css.push("--yn-lh:"+v);}'
		. 'if((v=n(r.width,"width"))!==null&&v!==c.d.width){css.push("--yn-width:"+Math.round(v)+"ch");}'
		. 'if((v=n(r.bgAlpha,"bgAlpha"))!==null&&v!==c.d.bgAlpha){css.push("--yn-bg-alpha:"+v);}'
		. 'if(typeof r.font==="string"&&Object.prototype.hasOwnProperty.call(c.p,r.font)&&r.font!==c.d.font){css.push("--yn-font:"+c.p[r.font]);}'
		. 'if(css.length){var e=document.createElement("style");e.id="yn-reglages-init";e.textContent=".yn-reader{"+css.join(";")+"}";document.head.appendChild(e);}'
		. '}(' . wp_json_encode( $donnees ) . ',document.documentElement));';
	wp_print_inline_script_tag( $script, array( 'id' => 'yume-lecture-init' ) );
}
add_action( 'wp_head', __NAMESPACE__ . '\\script_initialisation', 1 );

/**
 * Hors chapitre, pour un membre : le thème choisi avec la bascule de l'en-tête (événement
 * « yn:theme » du thème) est aussi enregistré sur le compte. Sinon, le thème du compte,
 * imposé à l'ouverture d'un chapitre (script_initialisation), annulerait ce choix partout.
 * Sur une page de lecture, la barre de lecture s'en charge déjà. Un compte encore sans réglages reçoit
 * le jeu complet de l'appareil (yn.reglages), pour que le serveur ne le complète pas avec les
 * valeurs par défaut.
 */
function script_theme_membre(): void {
	if ( ! is_user_logged_in() || is_admin() || page_de_lecture() ) {
		return;
	}
	$donnees = array(
		'u' => esc_url_raw( rest_url( REST_NS . '/moi/reglages' ) ),
		'n' => wp_create_nonce( 'wp_rest' ),
		'r' => null !== reglages_enregistres( get_current_user_id() ),
		't' => THEMES,
		'b' => bornes_reglages(),
		'p' => array_keys( polices() ),
	);
	$script  = '(function(c){var m=null,a=null;'
		. 'function envoyer(k){var t=a;a=null;clearTimeout(m);m=null;if(!t||!window.fetch){return;}var d={theme:t};'
		. 'if(!c.r){try{var l=JSON.parse(window.localStorage.getItem("yn.reglages")||"null");if(l&&typeof l==="object"){["size","lh","width","bgAlpha"].forEach(function(x){var v=parseFloat(l[x]);if(isFinite(v)&&c.b[x]){v=Math.min(c.b[x][1],Math.max(c.b[x][0],v));d[x]=(x==="size"||x==="width")?Math.round(v):Math.round(v*100)/100;}});if(c.p.indexOf(l.font)>-1){d.font=l.font;}}}catch(e){}}'
		. 'window.fetch(c.u,{method:"PUT",credentials:"same-origin",keepalive:!!k,headers:{"Content-Type":"application/json",Accept:"application/json","X-WP-Nonce":c.n},body:JSON.stringify(d)}).then(function(r){if(r.ok){c.r=true;}},function(){});}'
		. 'document.addEventListener("yn:theme",function(e){var t=e&&e.detail?e.detail.theme:null;if(c.t.indexOf(t)<0){return;}a=t;clearTimeout(m);m=setTimeout(function(){envoyer(false);},600);});'
		. 'window.addEventListener("pagehide",function(){if(a){envoyer(true);}});'
		. '}(' . wp_json_encode( $donnees ) . '));';
	wp_print_inline_script_tag( $script, array( 'id' => 'yume-theme-membre' ) );
}
add_action( 'wp_footer', __NAMESPACE__ . '\\script_theme_membre', 20 );
