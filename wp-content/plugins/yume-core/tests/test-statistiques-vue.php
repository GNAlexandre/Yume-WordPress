<?php
/**
 * Tests de la vue « Statistiques » de l'espace équipe (lot D) : déclaration et navigation (menu
 * « Site », avant « Indicateurs »), accès, onglets et période (formulaires GET, sans JavaScript),
 * rendu sans données (« Aucune donnée pour le moment ») et avec données, graphiques SVG
 * accessibles (role="img", aria-label, <title> par point, tableau des valeurs), variations
 * lisibles, échappement, fiche d'une œuvre et liste nominative réservée aux gérants.
 *
 * Les données des autres fichiers du module (trafic, classements, fiche, lecteurs, export) sont
 * fournies par le filtre yume_statistiques_vue_donnees : les tests ne dépendent pas de leur
 * présence (fonction absente = false).
 *
 * Lancement : tools/localenv/test.sh statistiques-vue
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Statistiques\CAPACITE_STATS;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\vue_equipe;
use function Yume\Core\Planning\vues_equipe_ajoutees;
use function Yume\Core\Statistiques\graphique_echelle;
use function Yume\Core\Statistiques\rendu_vue_statistiques;
use function Yume\Core\Statistiques\svg_barres_h;
use function Yume\Core\Statistiques\svg_courbe;
use function Yume\Core\Statistiques\svg_sparkline;
use function Yume\Core\Statistiques\url_statistiques;
use function Yume\Core\Statistiques\variation_html;
use function Yume\Core\Statistiques\vue_donnees;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tsvue_)
 * -----------------------------------------------------------------------------
 */

/** Fonctions de données de la vue (toutes court-circuitées par les tests). */
const YUME_TSVUE_FONCTIONS = array( 'source_trafic', 'derniere_erreur_trafic', 'trafic_resume', 'trafic_contenus', 'trafic_referents', 'trafic_recherches', 'trafic_pays', 'resume_lecteurs', 'classement', 'fiche_oeuvre', 'lecteurs_oeuvre', 'url_export' );

/**
 * Déclare un test isolé : rôles réinstallés, $_GET et utilisateur rétablis, filtre des données
 * retiré à la fin.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tsvue_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			\Yume\Core\Core\installer_roles();
			$get = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			try {
				$corps();
			} finally {
				remove_all_filters( 'yume_statistiques_vue_donnees' );
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Fournit les données de la vue : $donnees[ fonction ] (valeur, ou callable recevant les
 * arguments) ; toute autre fonction est absente (false).
 *
 * @param array $donnees Fonction => résultat ou callable.
 */
function yume_tsvue_donnees( array $donnees ): void {
	remove_all_filters( 'yume_statistiques_vue_donnees' );
	add_filter(
		'yume_statistiques_vue_donnees',
		static function ( $pre, $fonction, $args ) use ( $donnees ) {
			if ( ! array_key_exists( $fonction, $donnees ) ) {
				return false;
			}
			$valeur = $donnees[ $fonction ];
			return $valeur instanceof Closure ? $valeur( ...$args ) : $valeur;
		},
		10,
		3
	);
}

/**
 * Rend la vue pour un compte et des paramètres GET.
 *
 * @param int   $user_id Compte.
 * @param array $get     Paramètres GET (vue=statistiques ajouté).
 */
function yume_tsvue_rendu( int $user_id, array $get = array() ): string {
	$_GET = array_merge( array( 'vue' => 'statistiques' ), $get ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_set_current_user( $user_id );
	return rendu_vue_statistiques();
}

/**
 * Série quotidienne de trafic se terminant le 15 septembre 2026.
 *
 * @param int $jours Nombre de jours.
 * @return array<int,array{date:string,vues:int,visiteurs:int}>
 */
function yume_tsvue_serie( int $jours ): array {
	$fin   = new DateTimeImmutable( '2026-09-15' );
	$serie = array();
	for ( $i = $jours - 1; $i >= 0; $i-- ) {
		$serie[] = array(
			'date'      => $fin->modify( '-' . $i . ' days' )->format( 'Y-m-d' ),
			'vues'      => 100 + ( $i * 7 ) % 50,
			'visiteurs' => 30 + ( $i * 3 ) % 20,
		);
	}
	return $serie;
}

/**
 * Jeu de données complet (formes exactes des contrats du plan, §3.4 à §3.6).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $titre     Titre de l'œuvre.
 * @return array<string,mixed>
 */
function yume_tsvue_jeu( int $oeuvre_id, string $titre ): array {
	$ligne = array(
		'oeuvre_id'          => $oeuvre_id,
		'titre'              => $titre,
		'url'                => 'https://exemple.test/oeuvre/',
		'favoris'            => 412,
		'suiveurs'           => 380,
		'lecteurs'           => 920,
		'lecteurs_30j'       => 184,
		'vues'               => 523,
		'nouveaux_favoris'   => 38,
		'croissance_favoris' => 10,
		'croissance_vues'    => null,
	);
	return array(
		'source_trafic'          => 'simule',
		'derniere_erreur_trafic' => '',
		'trafic_resume'          => array(
			'source'              => 'simule',
			'unite'               => 'jour',
			'vues'                => 820,
			'visiteurs'           => 260,
			'vues_prec'           => 700,
			'visiteurs_prec'      => 280,
			'variation_vues'      => 17.1,
			'variation_visiteurs' => -7.1,
			'serie'               => yume_tsvue_serie( 30 ),
			'calcule'             => time() - 240,
		),
		'trafic_contenus'        => array(
			'source'      => 'simule',
			'contenus'    => array(
				array(
					'post_id'   => $oeuvre_id,
					'titre'     => $titre,
					'url'       => 'https://exemple.test/oeuvre/',
					'type'      => 'oeuvre',
					'oeuvre_id' => $oeuvre_id,
					'vues'      => 310,
				),
				array(
					'post_id'   => 0,
					'titre'     => 'Accueil',
					'url'       => 'https://exemple.test/',
					'type'      => 'page',
					'oeuvre_id' => 0,
					'vues'      => 120,
				),
			),
			'oeuvres'     => array(
				array(
					'oeuvre_id' => $oeuvre_id,
					'titre'     => $titre,
					'url'       => 'https://exemple.test/oeuvre/',
					'vues'      => 310,
				),
			),
			'autres_vues' => 120,
			'total_vues'  => 430,
		),
		'trafic_referents'       => array(
			'source'    => 'simule',
			'referents' => array(
				array(
					'nom'  => 'Discord',
					'url'  => 'https://discord.com',
					'vues' => 98,
				),
			),
			'total'     => 98,
		),
		'trafic_recherches'      => array(
			'source'   => 'simule',
			'termes'   => array(
				array(
					'terme' => 'yume novel',
					'vues'  => 41,
				),
			),
			'chiffres' => 129,
			'total'    => 170,
		),
		'trafic_pays'            => array(
			'source' => 'simule',
			'pays'   => array(
				array(
					'code' => 'FR',
					'nom'  => 'France',
					'vues' => 78,
				),
				array(
					'code' => 'BE',
					'nom'  => 'Belgique',
					'vues' => 8,
				),
			),
			'autres' => 14,
			'total'  => 100,
		),
		'resume_lecteurs'        => array(
			'comptes'                => 234,
			'inscriptions'           => 46,
			'inscriptions_prec'      => 40,
			'variation_inscriptions' => 15.0,
			'actifs'                 => 184,
			'actifs_prec'            => 190,
			'variation_actifs'       => -3.2,
			'favoris_total'          => 412,
			'suiveurs_total'         => 380,
			'lecteurs_avec_favori'   => 143,
			'serie_inscriptions'     => array_map(
				static fn( $j ) => array(
					'date' => $j['date'],
					'n'    => $j['visiteurs'] % 4,
				),
				yume_tsvue_serie( 30 )
			),
			'frequences'             => array(
				'immediat' => 70,
				'hebdo'    => 15,
				'jamais'   => 15,
			),
		),
		'classement'             => array(
			'tri'     => 'favoris',
			'total'   => 1,
			'page'    => 1,
			'pages'   => 1,
			'oeuvres' => array( $ligne ),
		),
		'url_export'             => 'https://exemple.test/wp-admin/admin-post.php?action=yume_statistiques_export',
	);
}

/**
 * Balises ouvrantes <svg> d'un HTML.
 *
 * @param string $html HTML.
 * @return string[]
 */
function yume_tsvue_svgs( string $html ): array {
	preg_match_all( '#<svg\b[^>]*>#', $html, $m );
	return $m[0];
}

/**
 * Vérifie que chaque SVG non décoratif porte role="img" et un aria-label non vide ; renvoie le
 * nombre de SVG informatifs.
 *
 * @param string $html HTML.
 * @param string $msg  Contexte.
 */
function yume_tsvue_svgs_accessibles( string $html, string $msg ): int {
	$n = 0;
	foreach ( yume_tsvue_svgs( $html ) as $svg ) {
		if ( str_contains( $svg, 'aria-hidden="true"' ) ) {
			continue;
		}
		++$n;
		yume_assert_contains( 'role="img"', $svg, $msg . ' : role="img"' );
		yume_assert_true( (bool) preg_match( '#aria-label="[^"]{10,}"#', $svg ), $msg . ' : aria-label résumé' );
	}
	return $n;
}

/*
 * -----------------------------------------------------------------------------
 * Déclaration, navigation, accès
 * -----------------------------------------------------------------------------
 */

yume_tsvue_test(
	'vue Statistiques : proposée à toute l’équipe (pas aux lecteurs), menu « Site » ouvert, avant « Indicateurs »',
	static function () {
		foreach ( array( 'yume_traducteur', 'yume_relecteur', 'yume_graphiste', 'yume_editeur', 'yume_gerant', 'administrator' ) as $role ) {
			wp_set_current_user( yume_factory_user( $role ) );
			$vues = vues_equipe_ajoutees();
			yume_assert_true( isset( $vues['statistiques'] ), $role );
			yume_assert_same( 'site', $vues['statistiques']['groupe'], $role );
			yume_assert_same( CAPACITE_STATS, $vues['statistiques']['capacite'], $role );
			yume_assert_same( 'Statistiques', $vues['statistiques']['libelle'] );
		}
		wp_set_current_user( yume_factory_user( 'subscriber' ) );
		yume_assert_false( isset( vues_equipe_ajoutees()['statistiques'] ), 'lecteur' );
		$_GET['vue'] = 'statistiques';
		yume_assert_same( '', vue_equipe(), 'lecteur : ?vue=statistiques ignoré' );

		// Traducteur : la vue existe, le menu « Site » apparaît avec elle.
		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		yume_assert_same( 'statistiques', vue_equipe() );
		$nav = navigation_equipe( 'statistiques' );
		yume_assert_contains( '<details open><summary>Site</summary>', $nav, 'menu « Site » ouvert' );
		yume_assert_true( (bool) preg_match( '#<a href="[^"]*vue=statistiques[^"]*" aria-current="page">Statistiques</a>#', $nav ), 'entrée active' );
		yume_assert_same( 1, substr_count( $nav, 'aria-current' ) );

		// Gérant : « Statistiques » avant « Indicateurs » (priorité 5 du filtre).
		wp_set_current_user( yume_factory_user( 'yume_gerant' ) );
		$cles = array_keys( vues_equipe_ajoutees() );
		yume_assert_true( array_search( 'statistiques', $cles, true ) < array_search( 'kpi', $cles, true ), 'ordre des vues' );
		$nav = navigation_equipe( 'tableau' );
		yume_assert_true( strpos( $nav, 'vue=statistiques' ) < strpos( $nav, 'vue=kpi' ), 'ordre du menu' );
		yume_assert_contains( 'yn-team--stats', yume_render_block( 'yume/team-dashboard' ), 'rendue par le bloc de l’espace équipe' );
	}
);

yume_tsvue_test(
	'vue Statistiques : refusée hors capacité, feuille de style chargée (dépend de yume-kpi)',
	static function () {
		yume_tsvue_donnees( array() );
		$html = yume_tsvue_rendu( yume_factory_user( 'subscriber' ) );
		yume_assert_contains( 'Seuls les membres de l’équipe', $html );
		yume_assert_not_contains( 'yn-stats__onglets', $html );

		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ) );
		yume_assert_contains( 'yn-stats__onglets', $html );
		yume_assert_true( wp_style_is( 'yume-statistiques', 'enqueued' ), 'feuille enfilée' );
		yume_assert_same( array( 'yume-kpi' ), wp_styles()->registered['yume-statistiques']->deps );
	}
);

yume_tsvue_test(
	'url_statistiques : vue=statistiques, période par défaut et aperçu omis',
	static function () {
		$url = url_statistiques(
			array(
				'periode' => 30,
				'onglet'  => 'apercu',
			)
		);
		yume_assert_contains( 'vue=statistiques', $url );
		yume_assert_not_contains( 'periode', $url );
		yume_assert_not_contains( 'onglet', $url );
		$url = url_statistiques(
			array(
				'periode' => 7,
				'onglet'  => 'trafic',
				'oeuvre'  => 12,
			)
		);
		yume_assert_contains( 'periode=7', $url );
		yume_assert_contains( 'onglet=trafic', $url );
		yume_assert_contains( 'oeuvre=12', $url );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Rendu des onglets
 * -----------------------------------------------------------------------------
 */

yume_tsvue_test(
	'sans données (fichiers des autres lots absents) : onglets, période et « Aucune donnée pour le moment »',
	static function () {
		yume_tsvue_donnees( array() );
		$uid = yume_factory_user( 'yume_traducteur' );

		$html = yume_tsvue_rendu( $uid );
		preg_match( '#<nav class="yn-stats__onglets".*?</nav>#s', $html, $nav );
		yume_assert_same( 4, substr_count( $nav[0] ?? '', '<a href=' ), 'quatre onglets' );
		yume_assert_true( (bool) preg_match( '#<a href="[^"]*" aria-current="page">Aperçu</a>#', $html ), 'onglet courant' );
		yume_assert_contains( '<form class="yn-stats__periode" method="get"', $html, 'formulaire GET' );
		yume_assert_same( 4, substr_count( $html, 'name="periode"' ), 'quatre périodes' );
		yume_assert_contains( 'value="30" checked', $html, '30 jours par défaut' );
		yume_assert_contains( '<input type="hidden" name="vue" value="statistiques">', $html );
		yume_assert_contains( 'Aucune donnée pour le moment', $html );
		yume_assert_not_contains( '<script', $html, 'aucun JavaScript' );
		yume_assert_not_contains( 'Exporter le classement', $html, 'pas d’export sans url_export()' );

		foreach ( array( 'trafic', 'oeuvres', 'lecteurs' ) as $onglet ) {
			$html = yume_tsvue_rendu(
				$uid,
				array(
					'onglet'  => $onglet,
					'periode' => '90',
				)
			);
			yume_assert_contains( 'Aucune donnée pour le moment', $html, $onglet );
			yume_assert_contains( 'value="90" checked', $html, $onglet . ' : période conservée' );
			yume_assert_contains( '<input type="hidden" name="onglet" value="' . $onglet . '">', $html, $onglet . ' : onglet conservé au changement de période' );
			yume_assert_true( (bool) preg_match( '#periode=90&(?:amp;|\#038;)onglet=' . $onglet . '" aria-current="page"#', $html ), $onglet . ' : onglet courant, période dans les liens' );
		}

		// Valeurs inconnues : aperçu, 30 jours.
		$html = yume_tsvue_rendu(
			$uid,
			array(
				'onglet'  => 'pirate',
				'periode' => '12',
			)
		);
		yume_assert_true( (bool) preg_match( '#aria-current="page">Aperçu</a>#', $html ) );
		yume_assert_contains( 'value="30" checked', $html );
	}
);

yume_tsvue_test(
	'aperçu avec données : cartes et variations (symbole + texte), courbe SVG accessible, tops, bandeau de démonstration, échappement',
	static function () {
		$oeuvre = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Brume',
			)
		);
		$jeu    = yume_tsvue_jeu( $oeuvre, '<script>alert(1)</script> Brume' );
		yume_tsvue_donnees( $jeu );
		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ) );

		yume_assert_contains( 'Données de démonstration', $html, 'bandeau (source simule)' );
		yume_assert_contains( 'yn-stats__variation--hausse"><span aria-hidden="true">▲</span> +17', $html, 'hausse des vues' );
		yume_assert_contains( 'yn-stats__variation--baisse"><span aria-hidden="true">▼</span> ' . "\u{2212}" . '7', $html, 'baisse des visiteurs' );
		yume_assert_contains( 'Suiveurs alertés', $html );
		yume_assert_contains( 'Vues et visiteurs par jour', $html );
		yume_assert_true( yume_tsvue_svgs_accessibles( $html, 'aperçu' ) >= 3, 'courbe et tendances' );
		yume_assert_same( 1, substr_count( $html, 'yn-stats__ligne yn-stats__ligne--2' ), 'visiteurs : seconde série (pointillés)' );
		yume_assert_contains( 'yn-stats__trait yn-stats__trait--2', $html, 'légende' );
		yume_assert_true( substr_count( $html, '<title>' ) >= 30, 'un <title> par jour' );
		yume_assert_contains( '<details class="yn-kpi__details yn-stats__donnees"><summary>Voir le tableau des valeurs</summary>', $html );
		yume_assert_contains( '<table class="yn-kpi__table">', $html );
		yume_assert_contains( 'Œuvres les plus suivies', $html );
		yume_assert_contains( 'Œuvres les plus vues', $html );
		yume_assert_contains( 'oeuvre=' . $oeuvre, $html, 'lien vers la fiche statistique' );
		yume_assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt; Brume', $html, 'titre échappé' );
		yume_assert_not_contains( '<script>alert', $html );
		yume_assert_contains( 'Exporter le classement (CSV)', $html );
		yume_assert_contains( 'admin-post.php?action=yume_statistiques_export', $html );

		// Jetpack : « mis à jour il y a … » ; pas de bandeau.
		$jeu['source_trafic'] = 'jetpack';
		yume_tsvue_donnees( $jeu );
		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ) );
		yume_assert_contains( 'Trafic : Jetpack (WordPress.com) · mis à jour il y a 4 min.', $html );
		yume_assert_not_contains( 'Données de démonstration', $html );

		// Jetpack non connecté : trafic indisponible, le reste s'affiche.
		$jeu['source_trafic'] = 'aucune';
		$jeu['trafic_resume'] = false; // trafic_resume() renvoie null : rendu identique.
		yume_tsvue_donnees( $jeu );
		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ) );
		yume_assert_contains( 'Trafic indisponible', $html );
		yume_assert_contains( 'Œuvres les plus suivies', $html );
	}
);

yume_tsvue_test(
	'trafic : source réservée aux administrateurs, contenus, regroupement par œuvre, référents, recherches, pays',
	static function () {
		$oeuvre = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		yume_tsvue_donnees( yume_tsvue_jeu( $oeuvre, 'Brume' ) );
		$get = array( 'onglet' => 'trafic' );

		$html = yume_tsvue_rendu( yume_factory_user( 'yume_gerant' ), $get );
		yume_assert_not_contains( 'Source du trafic', $html, 'gérant' );
		foreach ( array( 'Vues par visiteur', 'Meilleur jour', 'Contenus les plus vus', 'Par œuvre', 'Fiche œuvre', 'Discord', 'yume novel', 'Termes masqués par les moteurs', 'France', 'jamais par ville' ) as $texte ) {
			yume_assert_contains( $texte, $html, $texte );
		}
		yume_assert_contains( 'Autres pages', $html );
		yume_tsvue_svgs_accessibles( $html, 'trafic' );
		yume_assert_contains( 'aria-label="Part des vues par pays : France 78', $html, 'barres des pays' );

		$html = yume_tsvue_rendu( yume_factory_user( 'administrator' ), $get );
		yume_assert_contains( 'Source du trafic : données de démonstration', $html, 'administrateur' );
		yume_assert_contains( 'dernière erreur : aucune', $html );
	}
);

yume_tsvue_test(
	'œuvres : tri (formulaire GET), classement paginé, rang, croissance, liens vers les fiches',
	static function () {
		$oeuvre = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		$jeu    = yume_tsvue_jeu( $oeuvre, 'Brume' );
		$appels = array();
		$ligne  = $jeu['classement']['oeuvres'][0];
		yume_tsvue_donnees(
			array(
				'classement' => static function ( ...$args ) use ( &$appels, $ligne ) {
					$appels[] = $args;
					return array(
						'tri'     => $args[1],
						'total'   => 45,
						'page'    => $args[3],
						'pages'   => 3,
						'oeuvres' => array( $ligne ),
					);
				},
			)
		);
		$html = yume_tsvue_rendu(
			yume_factory_user( 'yume_editeur' ),
			array(
				'onglet'  => 'oeuvres',
				'tri'     => 'vues',
				'pg'      => '2',
				'periode' => '7',
			)
		);
		yume_assert_same( array( 7, 'vues', 20, 2 ), $appels[0], 'classement( période, tri, 20, page )' );
		yume_assert_contains( '<option value="vues" selected', $html );
		yume_assert_contains( '<span class="yn-stats__rang">21</span>', $html, 'rang continu d’une page à l’autre' );
		yume_assert_contains( 'Page 2 sur 3', $html );
		yume_assert_contains( 'tri=vues', $html, 'tri conservé dans la pagination' );
		yume_assert_contains( '45 œuvres', $html );
		yume_assert_contains( 'yn-stats__variation--hausse', $html, 'croissance' );
		yume_assert_contains( 'oeuvre=' . $oeuvre, $html );

		// Tri inconnu : favoris.
		$appels = array();
		yume_tsvue_rendu(
			yume_factory_user( 'yume_editeur' ),
			array(
				'onglet' => 'oeuvres',
				'tri'    => 'pirate',
			)
		);
		yume_assert_same( 'favoris', $appels[0][1] );
	}
);

yume_tsvue_test(
	'lecteurs : cartes, courbe des inscriptions, répartition des alertes en texte, aucun lecteur nommé',
	static function () {
		yume_tsvue_donnees( yume_tsvue_jeu( 1, 'Brume' ) );
		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ), array( 'onglet' => 'lecteurs' ) );
		foreach ( array( 'Comptes lecteurs', 'Inscriptions par jour', 'Avec au moins 1 favori', 'Fréquence des alertes', 'Aucun lecteur n’est nommé' ) as $texte ) {
			yume_assert_contains( $texte, $html, $texte );
		}
		yume_assert_contains( 'Immédiate <b>70', $html, 'part écrite, pas seulement la couleur' );
		yume_assert_contains( 'Avec au moins 1 favori</p><p class="yn-team__valeur">61', $html, '143 / 234 comptes avec un favori' );
		yume_assert_true( yume_tsvue_svgs_accessibles( $html, 'lecteurs' ) >= 1 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Fiche d'une œuvre et liste nominative
 * -----------------------------------------------------------------------------
 */

yume_tsvue_test(
	'fiche d’une œuvre : chiffres, évolution, tomes ; liste nominative pour les gérants seulement (encart cadenas sinon)',
	static function () {
		$oeuvre                 = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Brume',
			)
		);
		$appels                 = 0;
		$args                   = array();
		$jeu                    = yume_tsvue_jeu( $oeuvre, 'Brume' );
		$serie                  = array_map(
			static fn( $j ) => array(
				'date'         => $j['date'],
				'favoris'      => 380 + $j['visiteurs'] % 7,
				'suiveurs'     => 350 + $j['visiteurs'] % 5,
				'lecteurs_30j' => 150 + $j['visiteurs'] % 9,
				'vues'         => null,
			),
			yume_tsvue_serie( 30 )
		);
		$jeu['fiche_oeuvre']    = array(
			'oeuvre'            => array(
				'id'    => $oeuvre,
				'titre' => 'Brume <i>haute</i>',
				'url'   => 'https://exemple.test/brume/',
			),
			'compteurs'         => array(
				'favoris'       => 412,
				'suiveurs'      => 380,
				'listes'        => array(
					'a_lire'   => 96,
					'en_cours' => 210,
					'termine'  => 140,
					'perso'    => 35,
				),
				'lecteurs'      => 920,
				'lecteurs_30j'  => 184,
				'chapitres_lus' => 987,
			),
			'periode'           => array(
				'nouveaux_favoris' => 38,
				'lecteurs_actifs'  => 184,
				'vues'             => null,
				'vues_prec'        => null,
			),
			'frequences'        => array(
				'immediat' => 300,
				'hebdo'    => 80,
				'jamais'   => 32,
			),
			'serie'             => $serie,
			'tomes'             => array(
				array(
					'tome_id'              => 5,
					'titre'                => 'Tome 10',
					'chapitres'            => 12,
					'lecteurs_positionnes' => 420,
				),
			),
			'depuis_historique' => '2026-08-17',
		);
		$jeu['lecteurs_oeuvre'] = static function ( $id, $a ) use ( &$appels, &$args ) {
			++$appels;
			$args = $a;
			return array(
				'oeuvre'   => array(
					'id'    => $id,
					'titre' => 'Brume',
					'url'   => '',
				),
				'type'     => $a['type'],
				'total'    => 2,
				'page'     => 1,
				'pages'    => 1,
				'limite'   => 50,
				'q'        => $a['q'],
				'lecteurs' => array(
					array(
						'pseudo'    => 'Sakura_Lit',
						'depuis'    => '2026-09-12T08:00:00Z',
						'frequence' => 'immediat',
						'equipe'    => false,
					),
					array(
						'pseudo'    => '<b>Kenji</b>',
						'depuis'    => '2026-09-03T08:00:00Z',
						'frequence' => 'hebdo',
						'equipe'    => true,
					),
				),
			);
		};
		yume_tsvue_donnees( $jeu );

		// Traducteur : chiffres, pas de pseudos (lecteurs_oeuvre jamais appelée).
		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ), array( 'oeuvre' => (string) $oeuvre ) );
		yume_assert_contains( 'Brume &lt;i&gt;haute&lt;/i&gt;', $html, 'titre échappé' );
		foreach ( array( 'Favoris', 'Suiveurs alertés', 'Chapitres lus (est.)', 'Évolution des favoris et des suiveurs', 'Dans les listes des lecteurs', 'Lecteurs par tome', 'Tome 10', 'Voir la fiche publique', '← Retour au classement des œuvres', 'Lecteurs qui suivent l’œuvre' ) as $texte ) {
			yume_assert_contains( $texte, $html, $texte );
		}
		yume_assert_contains( 'Historique quotidien depuis le 17 août 2026', $html );
		yume_assert_contains( 'La liste des pseudos est réservée aux gérants', $html, 'encart cadenas' );
		yume_assert_contains( '412 lecteurs ont mis cette œuvre en favori', $html );
		yume_assert_not_contains( 'Sakura_Lit', $html );
		yume_assert_not_contains( 'Rechercher un pseudo', $html );
		yume_assert_same( 0, $appels, 'liste nominative jamais demandée pour un traducteur' );
		yume_assert_not_contains( 'yn-stats__onglets', $html, 'pas d’onglets sur la fiche' );
		yume_assert_contains( '<input type="hidden" name="oeuvre" value="' . $oeuvre . '">', $html, 'œuvre conservée au changement de période' );
		yume_assert_true( yume_tsvue_svgs_accessibles( $html, 'fiche' ) >= 2 );
		yume_assert_contains( 'trafic indisponible', $html, 'vues inconnues' );

		// Gérant : liste nominative, paramètres transmis, pseudo échappé, badge Équipe, export.
		$html = yume_tsvue_rendu(
			yume_factory_user( 'yume_gerant' ),
			array(
				'oeuvre' => (string) $oeuvre,
				'liste'  => 'suiveurs',
				'q'      => 'sak',
				'pg'     => '2',
				'tri'    => 'pseudo',
			)
		);
		yume_assert_same( 1, $appels );
		yume_assert_same( 'suiveurs', $args['type'] );
		yume_assert_same( 'sak', $args['q'] );
		yume_assert_same( 2, $args['page'] );
		yume_assert_same( 'pseudo', $args['tri'] );
		yume_assert_same( 'asc', $args['ordre'] );
		yume_assert_same( 50, $args['limite'] );
		yume_assert_contains( 'Sakura_Lit', $html );
		yume_assert_contains( '&lt;b&gt;Kenji&lt;/b&gt;', $html );
		yume_assert_not_contains( '<b>Kenji</b>', $html );
		yume_assert_contains( 'Équipe</span>', $html );
		yume_assert_contains( 'Rechercher un pseudo', $html );
		yume_assert_contains( 'value="sak"', $html );
		yume_assert_contains( 'Suiveurs alertés (380)</a>', $html );
		yume_assert_true( (bool) preg_match( '#aria-current="true">Suiveurs alertés#', $html ), 'liste affichée' );
		yume_assert_contains( 'Exporter la liste (CSV)', $html );
		yume_assert_contains( 'journalisé', $html );
		yume_assert_not_contains( 'La liste des pseudos est réservée', $html );
		yume_assert_not_contains( '@example.test', $html, 'aucune adresse e-mail' );

		// Gérant sans lecteurs.php (bouchon) : la section reste, message vide.
		unset( $jeu['lecteurs_oeuvre'] );
		yume_tsvue_donnees( $jeu );
		$html = yume_tsvue_rendu( yume_factory_user( 'administrator' ), array( 'oeuvre' => (string) $oeuvre ) );
		yume_assert_contains( 'Rechercher un pseudo', $html );
		yume_assert_contains( 'Aucune donnée pour le moment', $html );

		// Sans fiche_oeuvre : titre de l'œuvre, message vide, encart pour le traducteur.
		yume_tsvue_donnees( array() );
		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ), array( 'oeuvre' => (string) $oeuvre ) );
		yume_assert_contains( '>Brume</h2>', $html );
		yume_assert_contains( 'Aucune donnée pour le moment', $html );
		yume_assert_contains( 'La liste des pseudos est réservée', $html );

		// Œuvre inconnue.
		$html = yume_tsvue_rendu( yume_factory_user( 'yume_traducteur' ), array( 'oeuvre' => (string) yume_factory_post() ) );
		yume_assert_contains( 'Œuvre introuvable.', $html );
	}
);

yume_tsvue_test(
	'vue_donnees : filtre de court-circuit (false = absente), sinon fonction du module si elle existe',
	static function () {
		yume_tsvue_donnees( array( 'classement' => array( 'total' => 3 ) ) );
		yume_assert_same( array( 'total' => 3 ), vue_donnees( 'classement', array( 30 ) ) );
		yume_assert_same( null, vue_donnees( 'trafic_resume', array( 30 ) ), 'absente' );
		remove_all_filters( 'yume_statistiques_vue_donnees' );
		yume_assert_same( null, vue_donnees( 'fonction_inexistante_yume' ) );
		yume_assert_same( 30, vue_donnees( 'periode_valide', array( 'abc' ) ), 'fonction du module appelée' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Graphiques et variations (fonctions pures)
 * -----------------------------------------------------------------------------
 */

yume_tsvue_test(
	'svg_courbe : message sans point, deux séries = deux polylines bornées, un <title> par date, résumé, tableau',
	static function () {
		yume_assert_same( '<p class="yn-muted yn-stats__vide">Aucune donnée pour le moment.</p>', svg_courbe( array() ) );
		$vide = svg_courbe(
			array(
				array(
					'libelle' => 'Vues',
					'points'  => array(),
				),
			),
			array( 'vide' => 'Rien <ici>' )
		);
		yume_assert_not_contains( '<svg', $vide );
		yume_assert_contains( 'Rien &lt;ici&gt;', $vide );

		$serie                 = yume_tsvue_serie( 10 );
		$serie[3]['visiteurs'] = null; // Donnée absente : sautée par le tracé, « — » dans le tableau.
		$html                  = svg_courbe(
			array(
				array(
					'libelle' => 'Vues',
					'points'  => array_map(
						static fn( $j ) => array(
							'date'   => $j['date'],
							'valeur' => $j['vues'],
						),
						$serie
					),
				),
				array(
					'libelle' => '<Visiteurs>',
					'points'  => array_map(
						static fn( $j ) => array(
							'date'   => $j['date'],
							'valeur' => $j['visiteurs'],
						),
						$serie
					),
				),
			),
			array(
				'titre'   => 'Vues et visiteurs',
				'hauteur' => 200,
				'id'      => 'essai',
			)
		);
		yume_assert_same( 1, count( yume_tsvue_svgs( $html ) ) );
		yume_tsvue_svgs_accessibles( $html, 'courbe' );
		yume_assert_contains( 'aria-label="Vues et visiteurs, du 6 sept. 2026 au 15 sept. 2026 : Vues : ', $html );
		yume_assert_same( 2, substr_count( $html, '<polyline' ), 'une polyline par série' );
		yume_assert_same( 1, substr_count( $html, '<polygon' ), 'aire sous la première série' );
		yume_assert_same( 10, substr_count( $html, '<title>' ), 'un <title> par date' );
		yume_assert_contains( '<title>15 sept. 2026 — Vues : 100 · &lt;Visiteurs&gt; : 30</title>', $html );
		yume_assert_contains( '&lt;Visiteurs&gt;', $html, 'libellé échappé' );
		preg_match_all( '#<polyline[^>]* points="([^"]+)"#', $html, $m );
		yume_assert_same( 9, count( explode( ' ', $m[1][1] ) ), 'point inconnu sauté' );
		foreach ( $m[1] as $points ) {
			foreach ( explode( ' ', $points ) as $point ) {
				list( $x, $y ) = array_map( 'floatval', explode( ',', $point ) );
				yume_assert_true( $x >= 0 && $x <= 1000 && $y >= 0 && $y <= 200, 'coordonnées bornées : ' . $point );
			}
		}
		yume_assert_contains( 'viewBox="0 0 1000 200"', $html );
		yume_assert_contains( '<details class="yn-kpi__details yn-stats__donnees">', $html );
		yume_assert_same( 10, substr_count( $html, '<tr><th scope="row">' ), 'une ligne par date' );
		yume_assert_contains( '<td class="yn-kpi__nombre">—</td>', $html, 'valeur inconnue' );
		yume_assert_contains( 'aria-labelledby="essai-tableau"', $html );

		// Valeurs toutes nulles : ligne au bas du repère, sans division par zéro.
		$zero = svg_courbe(
			array(
				array(
					'libelle' => 'Vues',
					'points'  => array(
						array(
							'date'   => '2026-09-01',
							'valeur' => 0,
						),
						array(
							'date'   => '2026-09-02',
							'valeur' => 0,
						),
					),
				),
			),
			array( 'hauteur' => 100 )
		);
		yume_assert_contains( 'points="0,100 1000,100"', $zero );

		// Un seul point : petit trait visible ; plus de deux séries : deux seulement.
		$un = svg_courbe(
			array(
				array(
					'libelle' => 'A',
					'points'  => array(
						array(
							'date'   => '2026-09-01',
							'valeur' => 5,
						),
					),
				),
				array(
					'libelle' => 'B',
					'points'  => array(),
				),
				array(
					'libelle' => 'C',
					'points'  => array(),
				),
			),
			array( 'tableau' => false )
		);
		yume_assert_same( 1, substr_count( $un, '<polyline' ) );
		yume_assert_not_contains( '>C<', $un );
		yume_assert_not_contains( '<details', $un );
		yume_assert_contains( ', le 1 sept. 2026', $un );

		// Mois.
		$mois = svg_courbe(
			array(
				array(
					'libelle' => 'Vues',
					'points'  => array(
						array(
							'date'   => '2026-08',
							'valeur' => 5,
						),
						array(
							'date'   => '2026-09',
							'valeur' => 7,
						),
					),
				),
			),
			array( 'unite' => 'mois' )
		);
		yume_assert_contains( '<title>sept. 2026 — Vues : 7</title>', $mois );
	}
);

yume_tsvue_test(
	'échelle des courbes : graduations rondes, 0 pour les séries additives, sous le minimum pour les stocks',
	static function () {
		yume_assert_same(
			array(
				'bas'  => 0.0,
				'haut' => 800.0,
			),
			graphique_echelle( array( 120.0, 677.0 ), true ),
			'vues : 0 à 800 (milieu 400)'
		);
		yume_assert_same(
			array(
				'bas'  => 360.0,
				'haut' => 420.0,
			),
			graphique_echelle( array( 374.0, 412.0 ), false ),
			'favoris : 360 à 420'
		);
		yume_assert_same(
			array(
				'bas'  => 0.0,
				'haut' => 2.0,
			),
			graphique_echelle( array( 0.0, 0.0 ), true ),
			'tout à zéro'
		);
		$html = svg_courbe(
			array(
				array(
					'libelle' => 'Favoris',
					'points'  => array(
						array(
							'date'   => '2026-09-01',
							'valeur' => 374,
						),
						array(
							'date'   => '2026-09-02',
							'valeur' => 412,
						),
					),
				),
			),
			array(
				'cumul'   => false,
				'hauteur' => 100,
			)
		);
		yume_assert_contains( '<span>420</span><span>390</span><span>360</span>', $html, 'graduations écrites' );
		yume_assert_contains( 'aria-label="Évolution, du 1 sept. 2026 au 2 sept. 2026 : Favoris : de 374 à 412, au plus 412."', $html );
	}
);

yume_tsvue_test(
	'svg_barres_h et svg_sparkline : role="img", résumé, <title> par barre, barres bornées à 100 %',
	static function () {
		yume_assert_contains( 'Aucune donnée', svg_barres_h( array() ) );
		$html = svg_barres_h(
			array(
				array(
					'libelle' => 'France',
					'valeur'  => 78,
					'texte'   => '78 %',
				),
				array(
					'libelle' => '<Belgique>',
					'valeur'  => 150,
				),
			),
			array(
				'titre' => 'Pays',
				'max'   => 100,
			)
		);
		yume_tsvue_svgs_accessibles( $html, 'barres' );
		yume_assert_same( 2, substr_count( $html, '<title>' ) );
		yume_assert_contains( 'aria-label="Pays : France 78 %, &lt;Belgique&gt; 150."', $html );
		yume_assert_contains( 'width="78%"', $html );
		yume_assert_contains( 'width="100%" height="8" rx="4"></rect></g></svg>', $html, 'barre plafonnée' );
		yume_assert_not_contains( '<Belgique>', $html );

		yume_assert_same( '', svg_sparkline( array( 3 ), 'Tendance' ), 'moins de deux valeurs' );
		$spark = svg_sparkline( array( 1, null, 3, 2 ), 'Tendance des vues' );
		yume_tsvue_svgs_accessibles( $spark, 'sparkline' );
		yume_assert_same( 1, substr_count( $spark, '<polyline' ) );
		preg_match( '#points="([^"]+)"#', $spark, $m );
		yume_assert_same( 3, count( explode( ' ', $m[1] ) ), 'valeur inconnue sautée' );
	}
);

yume_tsvue_test(
	'variation_html : symbole et signe (jamais la couleur seule), « — » sans valeur précédente',
	static function () {
		$aucune = variation_html( null );
		yume_assert_contains( '—', $aucune );
		yume_assert_contains( 'yn-stats__variation--inconnue', $aucune );
		yume_assert_contains( 'variation non disponible', $aucune, 'texte pour les lecteurs d’écran' );

		$hausse = variation_html( 12.0 );
		yume_assert_contains( '<span aria-hidden="true">▲</span> +12' . "\u{00A0}" . '%', $hausse );
		yume_assert_contains( 'yn-stats__variation--hausse', $hausse );
		$baisse = variation_html( -3.0 );
		yume_assert_contains( '<span aria-hidden="true">▼</span> ' . "\u{2212}" . '3' . "\u{00A0}" . '%', $baisse );
		yume_assert_contains( 'yn-stats__variation--baisse', $baisse );
		$stable = variation_html( 0.0 );
		yume_assert_contains( '<span aria-hidden="true">=</span> 0' . "\u{00A0}" . '%', $stable );
		yume_assert_contains( 'yn-stats__variation--stable', $stable );
		yume_assert_contains( '+38</span>', variation_html( 38.0, '' ), 'écart absolu' );
		yume_assert_contains( '12', variation_html( 12.46 ) );
		yume_assert_not_contains( '12.46', variation_html( 12.46 ), 'une décimale au plus' );
	}
);
