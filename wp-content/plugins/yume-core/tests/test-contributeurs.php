<?php
/**
 * Tests du recrutement (PAGE-03 : page « Rejoindre l'équipe », réglages, bloc yume/recrutement,
 * lien de l'en-tête) et des profils publics des contributeurs (PAGE-04 : consentement, adresse
 * /contributeurs/{slug}/ sans identifiant de connexion, 404, contributions publiées seulement,
 * liens assainis, liste, rubrique de la page compte, plan du site, page « L'équipe »).
 *
 * Lancement : tools/localenv/test.sh contributeurs
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Migration\Migration_Planner;

use function Yume\Core\Core\recreer_pages_yume;
use function Yume\Core\Planning\assainir_postes_recrutement;
use function Yume\Core\Planning\postes_ouverts;
use function Yume\Core\Planning\rendu_recrutement;
use function Yume\Core\Social\completer_page_lequipe;
use function Yume\Core\Social\contributeur_par_slug;
use function Yume\Core\Social\contributeurs_publics;
use function Yume\Core\Social\enregistrer_profil_public;
use function Yume\Core\Social\profil_public_actif;
use function Yume\Core\Social\rendu_profil_contributeur;
use function Yume\Core\Social\rubriques_profil_public;
use function Yume\Core\Social\section_profil_public;
use function Yume\Core\Social\statut_contributeurs;
use function Yume\Core\Social\url_profil_public;
use function Yume\Core\Social\url_x_valide;
use function Yume\Core\Social\url_contributeurs;
use function Yume\Core\Social\urls_plan_contributeurs;

if ( ! function_exists( 'batcache_clear_url' ) ) {
	/**
	 * Batcache simulé (WordPress.com) : note les adresses purgées.
	 *
	 * @param string $url Adresse.
	 */
	function batcache_clear_url( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- fonction de WordPress.com simulée.
		$GLOBALS['yume_tests_batcache'][] = (string) $url;
		return true;
	}
}

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tct_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test isolé : requête principale, $_GET et utilisateur courant restaurés.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tct_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wp_query, $wp_the_query;
			$sauve = array( $wp_query, $wp_the_query, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			\Yume\Core\Core\installer_roles();
			wp_cache_flush();
			try {
				$corps();
			} finally {
				// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restauration après le test.
				list( $wp_query, $wp_the_query, $_GET ) = $sauve;
				// phpcs:enable
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Membre de l'équipe dont le pseudo diffère de l'identifiant de connexion.
 *
 * @param string $pseudo Pseudo.
 * @param string $role   Rôle.
 */
function yume_tct_membre( string $pseudo, string $role = 'yume_traducteur' ): int {
	$id = yume_factory_user( $role );
	wp_update_user(
		array(
			'ID'           => $id,
			'display_name' => $pseudo,
		)
	);
	return $id;
}

/**
 * Fait d'une adresse la requête principale (analyse comme WP::parse_request) et applique le
 * statut (200/404) des contributeurs.
 *
 * @param string $chemin Chemin (« /contributeurs/… »).
 */
function yume_tct_aller( string $chemin ): WP_Query {
	global $wp_query, $wp_the_query;
	$sauve                  = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$_SERVER['REQUEST_URI'] = $chemin;
	try {
		$wp                    = new WP();
		$wp->public_query_vars = $GLOBALS['wp']->public_query_vars;
		$wp->parse_request();
		$vars = $wp->query_vars;
	} finally {
		if ( null === $sauve ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $sauve;
		}
	}
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restaurées par yume_tct_test().
	$wp_query     = new WP_Query();
	$wp_the_query = $wp_query;
	$wp_query->query( $vars );
	// phpcs:enable
	statut_contributeurs( false, $wp_query );
	return $wp_query;
}

/**
 * Œuvre publiée (ou non) et un tome dont les responsables sont donnés.
 *
 * @param string $titre        Titre de l'œuvre.
 * @param array  $responsables Étape => ID.
 * @param string $statut_tome  Statut du tome.
 * @param string $statut_oeuvre Statut de l'œuvre.
 * @return int[] [œuvre, tome]
 */
function yume_tct_tome( string $titre, array $responsables, string $statut_tome = 'publish', string $statut_oeuvre = 'publish' ): array {
	$oeuvre = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => $statut_oeuvre,
		)
	);
	$tome   = yume_factory_post(
		array(
			'post_type'   => 'yume_tome',
			'post_title'  => $titre . ' — Tome 1',
			'post_status' => $statut_tome,
			'meta_input'  => array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => 1,
			),
		)
	);
	update_post_meta(
		$tome,
		'yume_responsables',
		array_merge(
			array(
				'traduction' => 0,
				'relecture'  => 0,
				'edition'    => 0,
			),
			$responsables
		)
	);
	return array( $oeuvre, $tome );
}

/*
 * -----------------------------------------------------------------------------
 * PAGE-03 : page « Rejoindre l'équipe »
 * -----------------------------------------------------------------------------
 */

yume_tct_test(
	'Rejoindre l’équipe : page du contrat (migration), créée par « Recréer les pages manquantes »',
	static function () {
		yume_assert_same( array( 'rejoindre-l-equipe', '', 'Rejoindre l’équipe', 'yume/recrutement', '' ), Migration_Planner::PAGES_A_CREER['rejoindre'] ?? null );
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'name' => 'rejoindre-l-equipe', 'fields' => 'ids' ) ) as $id ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
			wp_delete_post( (int) $id, true );
		}
		$pages = get_option( 'yume_pages', array() );
		$pages = is_array( $pages ) ? $pages : array();
		unset( $pages['rejoindre'] );
		$pages['bibliotheque'] = $pages['bibliotheque'] ?? 0;
		update_option( 'yume_pages', $pages );

		$traites = recreer_pages_yume();
		yume_assert_true( isset( $traites['rejoindre'] ), 'page recréée' );
		$pages = get_option( 'yume_pages' );
		$page  = get_post( (int) $pages['rejoindre'] );
		yume_assert_same( 'rejoindre-l-equipe', $page->post_name );
		yume_assert_same( 'publish', $page->post_status );
		yume_assert_same( 0, (int) $page->post_parent );
		yume_assert_contains( '<!-- wp:yume/recrutement /-->', $page->post_content );
		yume_assert_same( get_permalink( $page ), yume_url_page( 'rejoindre' ) );
		yume_assert_contains( 'Postuler sur le Discord', do_blocks( $page->post_content ) );

		// Sans page enregistrée, yume_url_page() donne l'adresse du contrat.
		update_option( 'yume_pages', array() );
		yume_assert_same( home_url( '/rejoindre-l-equipe/' ), yume_url_page( 'rejoindre' ) );
	}
);

yume_tct_test(
	'Recrutement : postes ouverts en cartes, lien Discord du réglage, test de traduction, aucun formulaire',
	static function () {
		$reglages                         = get_option( 'yume_reglages', array() );
		$reglages                         = is_array( $reglages ) ? $reglages : array();
		$reglages['recrutement_postes']   = "Traducteur EN→FR | Traduire des LN | ouvert\nRelecteur | Corriger | fermé\nGraphiste";
		$reglages['recrutement_intro']    = "Premier paragraphe.\n\nSecond <b>paragraphe</b>.";
		$reglages['recrutement_test_url'] = 'https://example.test/test.docx';
		$reglages['discord_invite']       = 'https://discord.gg/exemple';
		update_option( 'yume_reglages', $reglages );

		yume_assert_same( array( 'Traducteur EN→FR', 'Graphiste' ), array_column( postes_ouverts(), 'intitule' ) );
		$html = rendu_recrutement();
		yume_assert_contains( 'class="yn-recrutement"', $html );
		yume_assert_contains( '<h3 class="yn-recrutement__intitule">Traducteur EN→FR</h3>', $html );
		yume_assert_contains( '<h3 class="yn-recrutement__intitule">Graphiste</h3>', $html );
		yume_assert_not_contains( 'Relecteur', $html, 'poste fermé masqué' );
		yume_assert_contains( 'href="https://discord.gg/exemple"', $html );
		yume_assert_contains( 'Postuler sur le Discord', $html );
		yume_assert_contains( 'href="https://example.test/test.docx"', $html );
		yume_assert_contains( '<p>Premier paragraphe.</p>', $html );
		yume_assert_contains( 'Second &lt;b&gt;paragraphe&lt;/b&gt;.', $html );
		yume_assert_not_contains( '<form', $html, 'aucune donnée personnelle recueillie' );
		yume_assert_not_contains( 'Aucun poste ouvert', $html );
	}
);

yume_tct_test(
	'Recrutement : « Aucun poste ouvert pour le moment » quand tous les postes sont fermés ou absents',
	static function () {
		$reglages                         = get_option( 'yume_reglages', array() );
		$reglages                         = is_array( $reglages ) ? $reglages : array();
		$reglages['recrutement_postes']   = "Relecteur | Corriger | fermé\nTraducteur | | pourvu";
		$reglages['recrutement_test_url'] = '';
		update_option( 'yume_reglages', $reglages );
		$html = rendu_recrutement();
		yume_assert_contains( 'Aucun poste ouvert pour le moment', $html );
		yume_assert_not_contains( 'yn-recrutement__poste"', $html );
		yume_assert_not_contains( 'test de traduction', mb_strtolower( $html ) );
		yume_assert_contains( 'Postuler sur le Discord', $html, 'le Discord reste proposé' );

		$reglages['recrutement_postes'] = '';
		update_option( 'yume_reglages', $reglages );
		yume_assert_contains( 'Aucun poste ouvert pour le moment', rendu_recrutement() );
	}
);

yume_tct_test(
	'Recrutement : réglages déclarés (section, vue Réglages de l’espace équipe) et assainis',
	static function () {
		$cles = array_column( \Yume\Core\Core\champs_reglages(), 'section', 'key' );
		foreach ( array( 'recrutement_intro', 'recrutement_postes', 'recrutement_test_url', 'recrutement_consigne' ) as $cle ) {
			yume_assert_same( 'recrutement', $cles[ $cle ] ?? null, $cle );
		}
		yume_assert_true( isset( \Yume\Core\Core\sections_reglages()['recrutement'] ) );
		yume_assert_true( isset( \Yume\Core\Planning\sections_reglages_equipe()['recrutement'] ), 'vue Réglages de l’espace équipe' );

		yume_assert_same(
			"Traducteur / JP | desc | ouvert\nRelecteur |  | fermé",
			assainir_postes_recrutement( "  Traducteur / JP | <b>desc</b> |  \n\n | orphelin | ouvert\nRelecteur||Fermé" )
		);
		yume_assert_same( 12, count( explode( "\n", assainir_postes_recrutement( str_repeat( "Poste | d | ouvert\n", 20 ) ) ) ), '12 postes au plus' );

		$sortie = \Yume\Core\Core\assainir_reglages(
			array(
				'recrutement_postes'   => '<script>x</script>Traducteur | <i>bonjour</i> | ouvert',
				'recrutement_test_url' => 'javascript:alert(1)',
				'recrutement_intro'    => '<script>alert(1)</script>Bienvenue',
			)
		);
		update_option( 'yume_reglages', $sortie );
		yume_assert_same( 'Traducteur | bonjour | ouvert', yume_setting( 'recrutement_postes' ) );
		yume_assert_same( '', yume_setting( 'recrutement_test_url' ) );
		yume_assert_same( 'Bienvenue', yume_setting( 'recrutement_intro' ) );
	}
);

yume_tct_test(
	'En-tête : lien « Rejoindre l’équipe » du sous-menu Yume Novel (clé rejoindre)',
	static function () {
		$entete = (string) file_get_contents( get_theme_root() . '/yume/parts/header.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier local du thème.
		yume_assert_contains( '"className":"yn-lien-rejoindre"', $entete );
		yume_assert_true( strpos( $entete, 'yn-lien-equipe' ) < strpos( $entete, 'yn-lien-rejoindre' ) && strpos( $entete, 'yn-lien-rejoindre' ) < strpos( $entete, '/wp:navigation-submenu' ), 'dans le sous-menu, après L’équipe' );
		if ( ! function_exists( 'yume_theme_lien' ) ) {
			return; // Thème Yume inactif.
		}
		yume_assert_same( 'rejoindre', yume_theme_cle_lien_depuis_classes( 'yn-lien-rejoindre' ) );
		$page               = yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Rejoindre l’équipe',
				'post_name'  => 'recrutement-test',
			)
		);
		$pages              = get_option( 'yume_pages', array() );
		$pages              = is_array( $pages ) ? $pages : array();
		$pages['rejoindre'] = $page;
		update_option( 'yume_pages', $pages );
		$html = do_blocks( '<!-- wp:navigation-link {"label":"Rejoindre l\'équipe","url":"/rejoindre-l-equipe/","kind":"custom","isTopLevelLink":false,"className":"yn-lien-rejoindre"} /-->' );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $page ) ) . '"', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * PAGE-04 : profils publics
 * -----------------------------------------------------------------------------
 */

yume_tct_test(
	'Profil public : désactivé par défaut, 404 sans consentement, 200 avec, retrait immédiat',
	static function () {
		$id   = yume_tct_membre( 'Plume Rose' );
		$user = get_userdata( $id );
		yume_assert_false( profil_public_actif( $id ), 'défaut : non public' );
		yume_assert_same( '', url_profil_public( $id ) );
		yume_assert_true( yume_tct_aller( '/contributeurs/plume-rose/' )->is_404(), 'sans consentement : 404' );

		yume_assert_same( array( 'profil-public-active' ), enregistrer_profil_public( $id, array( 'public' => true, 'bio' => 'Traductrice.' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		yume_assert_true( profil_public_actif( $id ) );
		$url = url_profil_public( $id );
		yume_assert_same( home_url( '/contributeurs/plume-rose/' ), $url );
		yume_assert_not_contains( $user->user_login, $url, 'aucun identifiant de connexion dans l’URL' );
		yume_assert_not_contains( $user->user_nicename, $url );

		$q = yume_tct_aller( '/contributeurs/plume-rose/' );
		yume_assert_false( $q->is_404(), 'avec consentement : 200' );
		yume_assert_false( $q->is_home() );
		yume_assert_same( $id, (int) contributeur_par_slug( 'plume-rose' )->ID );
		$html = rendu_profil_contributeur();
		yume_assert_contains( '<h1 class="yn-contributeur__nom" id="yn-contributeur-nom">Plume Rose</h1>', $html );
		yume_assert_contains( 'Traducteur', $html );
		yume_assert_contains( 'Traductrice.', $html );
		yume_assert_not_contains( $user->user_login, $html, 'aucun identifiant de connexion dans le HTML' );
		yume_assert_not_contains( $user->user_email, $html );
		$modeles = apply_filters( 'index_template_hierarchy', array( 'index.php' ) );
		yume_assert_same( 'yume-contributeur.php', $modeles[0], 'modèle du thème' );

		// Retrait : 404 immédiat, adresse oubliée.
		yume_assert_same( array( 'profil-public-retire' ), enregistrer_profil_public( $id, array( 'public' => false ) ) );
		yume_assert_false( profil_public_actif( $id ) );
		yume_assert_same( '', (string) get_user_meta( $id, 'yume_profil_slug', true ) );
		yume_assert_true( yume_tct_aller( '/contributeurs/plume-rose/' )->is_404(), 'retiré : 404' );
		yume_assert_true( yume_tct_aller( '/contributeurs/inconnu/' )->is_404(), 'inconnu : 404' );
	}
);

yume_tct_test(
	'Sécurité : Batcache — retrait du consentement et changement de pseudo purgent les anciennes adresses',
	static function () {
		$id = yume_tct_membre( 'Plume Cache' );
		enregistrer_profil_public( $id, array( 'public' => true ) );
		$url                            = url_profil_public( $id );
		$GLOBALS['yume_tests_batcache'] = array();
		enregistrer_profil_public( $id, array( 'public' => true ) );
		yume_assert_same( array(), $GLOBALS['yume_tests_batcache'], 'toujours public : rien à purger' );
		enregistrer_profil_public( $id, array( 'public' => false ) );
		yume_assert_same( array( $url, url_contributeurs() ), $GLOBALS['yume_tests_batcache'] );
		// Déjà retiré : plus rien à purger.
		$GLOBALS['yume_tests_batcache'] = array();
		enregistrer_profil_public( $id, array( 'public' => false ) );
		yume_assert_same( array(), $GLOBALS['yume_tests_batcache'] );
		// Pseudo changé : l'ancienne adresse est purgée.
		enregistrer_profil_public( $id, array( 'public' => true ) );
		wp_update_user(
			array(
				'ID'           => $id,
				'display_name' => 'Plume Neuve',
			)
		);
		yume_assert_same( array( $url ), $GLOBALS['yume_tests_batcache'] );
	}
);

yume_tct_test(
	'Profil public : 404 hors équipe (lecteur, ou membre retiré de l’équipe), refus pour un lecteur',
	static function () {
		$lecteur = yume_tct_membre( 'Lecteur Curieux', 'subscriber' );
		yume_assert_same( array( 'profil-public-refuse' ), enregistrer_profil_public( $lecteur, array( 'public' => true ) ) );
		update_user_meta( $lecteur, 'yume_profil_public', true );
		update_user_meta( $lecteur, 'yume_profil_slug', 'lecteur-curieux' );
		yume_assert_false( profil_public_actif( $lecteur ), 'consentement forcé en base : toujours refusé' );
		yume_assert_true( yume_tct_aller( '/contributeurs/lecteur-curieux/' )->is_404() );

		$membre = yume_tct_membre( 'Ancien Membre' );
		enregistrer_profil_public( $membre, array( 'public' => true ) );
		yume_assert_false( yume_tct_aller( '/contributeurs/ancien-membre/' )->is_404() );
		( new WP_User( $membre ) )->set_role( 'subscriber' );
		yume_assert_true( yume_tct_aller( '/contributeurs/ancien-membre/' )->is_404(), 'retiré de l’équipe : 404' );
		yume_assert_same( array(), array_map( static fn( $u ) => $u->ID, contributeurs_publics() ) );
	}
);

yume_tct_test(
	'Profil public : un pseudo identique à l’identifiant de connexion empêche la publication',
	static function () {
		$id   = yume_factory_user( 'yume_relecteur' );
		$user = get_userdata( $id );
		wp_update_user(
			array(
				'ID'           => $id,
				'display_name' => $user->user_login,
			)
		);
		yume_assert_same( array( 'profil-public-pseudo' ), enregistrer_profil_public( $id, array( 'public' => true ) ) );
		yume_assert_false( profil_public_actif( $id ) );
		yume_assert_same( '', (string) get_user_meta( $id, 'yume_profil_slug', true ) );

		// Pseudo changé ensuite pour l'identifiant : le profil disparaît.
		$autre = yume_tct_membre( 'Nuage Bleu' );
		enregistrer_profil_public( $autre, array( 'public' => true ) );
		yume_assert_true( profil_public_actif( $autre ) );
		wp_update_user(
			array(
				'ID'           => $autre,
				'display_name' => get_userdata( $autre )->user_login,
			)
		);
		yume_assert_false( profil_public_actif( $autre ) );
		yume_assert_true( yume_tct_aller( '/contributeurs/nuage-bleu/' )->is_404() );
	}
);

yume_tct_test(
	'Profil public : slug unique tiré du pseudo, qui suit le changement de pseudo',
	static function () {
		$a = yume_tct_membre( 'Kaze' );
		$b = yume_tct_membre( 'KAZE' );
		enregistrer_profil_public( $a, array( 'public' => true ) );
		enregistrer_profil_public( $b, array( 'public' => true ) );
		yume_assert_same( 'kaze', get_user_meta( $a, 'yume_profil_slug', true ) );
		yume_assert_same( 'kaze-2', get_user_meta( $b, 'yume_profil_slug', true ) );
		wp_update_user(
			array(
				'ID'           => $a,
				'display_name' => 'Kaze no Uta',
			)
		);
		yume_assert_same( 'kaze-no-uta', get_user_meta( $a, 'yume_profil_slug', true ) );
		yume_assert_true( yume_tct_aller( '/contributeurs/kaze/' )->is_404(), 'ancienne adresse : 404' );
		yume_assert_false( yume_tct_aller( '/contributeurs/kaze-no-uta/' )->is_404() );
	}
);

yume_tct_test(
	'Profil public : liens assainis (http(s) seulement, X normalisé, rel="me nofollow noopener"), bio limitée',
	static function () {
		$id    = yume_tct_membre( 'Lien Test' );
		$codes = enregistrer_profil_public(
			$id,
			array(
				'public'  => true,
				'bio'     => str_repeat( 'a', 400 ) . '<script>',
				'discord' => '@lien_test',
				'x'       => 'javascript:alert(1)',
				'site'    => 'ftp://example.test/',
				'arrivee' => '2024-13',
			)
		);
		yume_assert_same( array( 'profil-public-lien', 'profil-public-active' ), $codes );
		$liens = get_user_meta( $id, 'yume_profil_liens', true );
		yume_assert_same(
			array(
				'discord' => 'lien_test',
				'x'       => '',
				'site'    => '',
			),
			$liens
		);
		yume_assert_same( 300, mb_strlen( (string) get_user_meta( $id, 'yume_profil_bio', true ) ) );
		yume_assert_same( '', get_user_meta( $id, 'yume_profil_arrivee', true ) );

		yume_assert_same( 'https://x.com/Yume_Novel', url_x_valide( '@Yume_Novel' ) );
		yume_assert_same( 'https://x.com/YumeNovel', url_x_valide( 'http://twitter.com/YumeNovel/' ) );
		yume_assert_same( '', url_x_valide( 'https://evil.test/YumeNovel' ) );

		enregistrer_profil_public(
			$id,
			array(
				'public'  => true,
				'bio'     => 'Bonjour',
				'x'       => 'https://x.com/lientest',
				'site'    => 'https://lien.example.test/"onmouseover="x',
				'arrivee' => '2024-03',
			)
		);
		yume_tct_aller( '/contributeurs/lien-test/' );
		$html = rendu_profil_contributeur();
		yume_assert_contains( '<a href="https://x.com/lientest" rel="me nofollow noopener" target="_blank">', $html );
		yume_assert_not_contains( 'onmouseover="', $html );
		yume_assert_contains( 'rel="me nofollow noopener"', $html );
		yume_assert_contains( 'Dans l’équipe depuis', $html );
		yume_assert_contains( '2024', $html );
	}
);

yume_tct_test(
	'Profil public : contributions des tomes publiés d’œuvres publiées seulement',
	static function () {
		$id = yume_tct_membre( 'Plume Verte' );
		enregistrer_profil_public( $id, array( 'public' => true ) );
		list( , $publie )    = yume_tct_tome(
			'Lanternes publiées',
			array(
				'traduction' => $id,
				'relecture'  => $id,
			)
		);
		list( , $brouillon ) = yume_tct_tome( 'Tome en préparation', array( 'traduction' => $id ), 'draft' );
		list( , $cache )     = yume_tct_tome( 'Œuvre masquée', array( 'edition' => $id ), 'publish', 'draft' );
		yume_tct_tome( 'Autre traducteur', array( 'traduction' => yume_tct_membre( 'Autre' ) ) );

		yume_tct_aller( '/contributeurs/plume-verte/' );
		$html = rendu_profil_contributeur();
		yume_assert_contains( 'Lanternes publiées — Tome 1', $html );
		yume_assert_contains( 'Traduction, Relecture', $html );
		yume_assert_contains( 'href="' . esc_url( get_permalink( $publie ) ) . '"', $html );
		yume_assert_not_contains( 'Tome en préparation', $html, 'tome non publié' );
		yume_assert_not_contains( 'Œuvre masquée', $html, 'œuvre non publiée' );
		yume_assert_not_contains( 'Autre traducteur', $html );
		yume_assert_contains( '1 tome publié', $html );
		yume_assert_true( $brouillon > 0 && $cache > 0 );

		// Profil non public : les responsables ne sont jamais lus pour lui.
		enregistrer_profil_public( $id, array( 'public' => false ) );
		yume_assert_same( array(), \Yume\Core\Social\contributions_profil( $id ) );
	}
);

yume_tct_test(
	'Liste /contributeurs/ : profils publics (initiales, pseudo, rôle, tomes), rien sans profil public ailleurs',
	static function () {
		$q = yume_tct_aller( '/contributeurs/' );
		yume_assert_false( $q->is_404() );
		yume_assert_contains( 'Aucun membre de l’équipe n’a encore rendu son profil public.', rendu_profil_contributeur() );
		yume_assert_true( ! empty( apply_filters( 'wp_robots', array() )['noindex'] ), 'liste vide : noindex' );

		$a = yume_tct_membre( 'Zéphyr', 'yume_gerant' );
		$b = yume_tct_membre( 'Aube Claire', 'yume_relecteur' );
		yume_tct_membre( 'Discret' );
		enregistrer_profil_public( $a, array( 'public' => true ) );
		enregistrer_profil_public( $b, array( 'public' => true ) );
		yume_tct_tome( 'Brume', array( 'relecture' => $b ) );

		yume_tct_aller( '/contributeurs/' );
		$html = rendu_profil_contributeur();
		yume_assert_contains( '<h1 class="yn-contributeurs__titre" id="yn-contributeurs-titre">Contributeurs</h1>', $html );
		yume_assert_true( strpos( $html, 'Aube Claire' ) < strpos( $html, 'Zéphyr' ), 'ordre alphabétique' );
		yume_assert_contains( '>AC</span>', $html );
		yume_assert_contains( 'Gérant', $html );
		yume_assert_contains( 'Relecteur', $html );
		yume_assert_contains( '1 tome publié', $html );
		yume_assert_contains( 'href="' . esc_url( url_profil_public( $b ) ) . '"', $html );
		yume_assert_not_contains( 'Discret', $html, 'sans consentement : absent' );
		foreach ( array( $a, $b ) as $uid ) {
			yume_assert_not_contains( get_userdata( $uid )->user_login, $html );
		}

		// Hors de /contributeurs/ : liste avec titre <h2> (page « L'équipe »), rien sans profil public.
		yume_tct_aller( '/' );
		yume_assert_contains( '<h2 class="yn-contributeurs__titre"', rendu_profil_contributeur( array( 'mode' => 'liste' ) ) );
		enregistrer_profil_public( $a, array( 'public' => false ) );
		enregistrer_profil_public( $b, array( 'public' => false ) );
		yume_assert_same( '', rendu_profil_contributeur( array( 'mode' => 'liste' ) ) );
	}
);

yume_tct_test(
	'Profil public : titre, canonique, noindex si le profil est presque vide, plan du site',
	static function () {
		$vide = yume_tct_membre( 'Profil Vide' );
		$bio  = yume_tct_membre( 'Profil Riche' );
		enregistrer_profil_public( $vide, array( 'public' => true ) );
		enregistrer_profil_public( $bio, array( 'public' => true, 'bio' => 'Je traduis.' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing

		yume_tct_aller( '/contributeurs/profil-vide/' );
		yume_assert_true( ! empty( apply_filters( 'wp_robots', array() )['noindex'] ), 'peu de contenu : noindex' );
		yume_assert_same( 'Profil Vide, contributeur', apply_filters( 'document_title_parts', array( 'title' => 'x' ) )['title'] );
		ob_start();
		\Yume\Core\Social\afficher_canonique_contributeurs();
		yume_assert_contains( 'href="' . esc_url( home_url( '/contributeurs/profil-vide/' ) ) . '"', (string) ob_get_clean() );

		yume_tct_aller( '/contributeurs/profil-riche/' );
		yume_assert_true( empty( apply_filters( 'wp_robots', array() )['noindex'] ), 'avec présentation : indexable' );

		$locs = array_column( urls_plan_contributeurs(), 'loc' );
		yume_assert_true( in_array( home_url( '/contributeurs/' ), $locs, true ) );
		yume_assert_true( in_array( home_url( '/contributeurs/profil-riche/' ), $locs, true ) );
		yume_assert_false( in_array( home_url( '/contributeurs/profil-vide/' ), $locs, true ), 'noindex : hors du plan' );
	}
);

yume_tct_test(
	'Page compte : rubrique « Profil public » pour l’équipe seulement, explication et case décochée par défaut',
	static function () {
		$membre  = yume_tct_membre( 'Membre Compte' );
		$lecteur = yume_tct_membre( 'Simple Lecteur', 'subscriber' );
		yume_assert_true( isset( rubriques_profil_public( array( 'yn-profil' => 'Profil' ), $membre )['yn-profil-public'] ) );
		yume_assert_same( array( 'yn-profil' => 'Profil' ), rubriques_profil_public( array( 'yn-profil' => 'Profil' ), $lecteur ) );
		yume_assert_same( '', section_profil_public( get_userdata( $lecteur ) ) );

		$html = section_profil_public( get_userdata( $membre ) );
		yume_assert_contains( 'id="yn-profil-public"', $html );
		yume_assert_contains( 'Afficher mon profil public', $html );
		yume_assert_contains( 'name="action" value="yume_compte_profil_public"', $html );
		yume_assert_contains( 'name="_yn_nonce"', $html );
		yume_assert_not_contains( 'checked', $html, 'désactivé par défaut' );
		yume_assert_contains( 'Jamais votre identifiant de connexion', $html );

		wp_set_current_user( $membre );
		$compte = \Yume\Core\Social\compte_connecte();
		yume_assert_contains( 'data-yn-onglet="yn-profil-public"', $compte );
		wp_set_current_user( $lecteur );
		yume_assert_not_contains( 'yn-profil-public', \Yume\Core\Social\compte_connecte() );
	}
);

yume_tct_test(
	'Page « L’équipe » : « Poste à pourvoir » mène au recrutement, profils publics sous le contenu',
	static function () {
		$rejoindre = yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Rejoindre l’équipe',
				'post_name'  => 'rejoindre-l-equipe',
			)
		);
		update_option( 'yume_pages', array( 'rejoindre' => $rejoindre ) );
		$equipe = get_page_by_path( 'lequipe' );
		$equipe = $equipe ? (int) $equipe->ID : yume_factory_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'L’équipe',
				'post_name'  => 'lequipe',
			)
		);
		$id     = yume_tct_membre( 'Plume Équipe' );
		enregistrer_profil_public( $id, array( 'public' => true ) );

		global $wp_query, $wp_the_query;
		// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restaurées par yume_tct_test().
		$wp_query     = new WP_Query( array( 'page_id' => $equipe ) );
		$wp_the_query = $wp_query;
		// phpcs:enable
		$bloc    = new WP_Block( array( 'blockName' => 'core/post-content', 'attrs' => array() ), array( 'postId' => $equipe ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$contenu = '<div class="entry-content wp-block-post-content"><p class="has-text-align-center"><em>Poste à pourvoir — en recrutement</em></p></div>';
		$html    = completer_page_lequipe( $contenu, array(), $bloc );
		yume_assert_contains( '<em><a href="' . esc_url( get_permalink( $rejoindre ) ) . '">Poste à pourvoir — en recrutement</a></em>', $html );
		yume_assert_contains( 'Plume Équipe', $html );
		yume_assert_contains( 'Rejoindre l’équipe', $html );
		yume_assert_same( '</div>', substr( $html, -6 ), 'ajout dans le conteneur du contenu' );

		// Autre page : rien ne change.
		$autre = yume_factory_post( array( 'post_type' => 'page' ) );
		$bloc  = new WP_Block( array( 'blockName' => 'core/post-content', 'attrs' => array() ), array( 'postId' => $autre ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		yume_assert_same( $contenu, completer_page_lequipe( $contenu, array(), $bloc ) );
	}
);
