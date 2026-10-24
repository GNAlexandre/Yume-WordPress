<?php
/**
 * Tests de la vue liste « Comptes inscrits » (lot B) : entrée du menu « Équipe » juste après
 * « Membres et rôles » (gérants et administrateurs seulement), vue reconnue, accès refusé,
 * formulaire GET de filtres (sans JavaScript), état invalide ramené aux défauts, échappement des
 * pseudos, liste vide, pagination, indicateurs, export, journal réservé aux administrateurs,
 * lien vers la fiche et passage à rendu_fiche().
 *
 * Les fonctions de données des autres lots (lister_comptes(), indicateurs(), url_export(),
 * rendu_fiche()) sont fournies par le filtre yume_comptes_vue_donnees : les tests ne dépendent
 * pas de leur présence (fonction absente = false).
 *
 * Lancement : tools/localenv/test.sh comptes-vue
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use const Yume\Core\Comptes\CAPACITE;
use const Yume\Core\Comptes\COMPTES_PAR_PAGE;
use const Yume\Core\Comptes\VUE_STYLE;
use function Yume\Core\Comptes\args_comptes;
use function Yume\Core\Comptes\journaliser;
use function Yume\Core\Comptes\rendu_vue_comptes;
use function Yume\Core\Comptes\url_comptes;
use function Yume\Core\Comptes\vue_etat;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\vue_equipe;
use function Yume\Core\Planning\vues_equipe_ajoutees;

/*
 * -----------------------------------------------------------------------------
 * Aides (préfixe yume_tcv_)
 * -----------------------------------------------------------------------------
 */

/** « Maintenant » des tests : 24 octobre 2026, 15 h à Paris. */
const YUME_TCV_MAINTENANT = 1792846800;

/**
 * Déclare un test isolé : rôles réinstallés, date figée (yume_comptes_maintenant, fuseau de
 * Paris), $_GET et utilisateur rétablis, filtre des données retiré à la fin.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tcv_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			\Yume\Core\Core\installer_roles();
			$get  = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$fige = static function (): int {
				return YUME_TCV_MAINTENANT;
			};
			add_filter( 'yume_comptes_maintenant', $fige );
			update_option( 'timezone_string', 'Europe/Paris' );
			try {
				$corps();
			} finally {
				remove_all_filters( 'yume_comptes_vue_donnees' );
				remove_filter( 'yume_comptes_maintenant', $fige );
				$_GET = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Fournit les données de la vue : $donnees[ fonction ] (valeur, ou Closure recevant les
 * arguments ; null : vraie fonction appelée) ; toute autre fonction est absente (false).
 *
 * @param array $donnees Fonction => résultat ou Closure.
 */
function yume_tcv_donnees( array $donnees ): void {
	remove_all_filters( 'yume_comptes_vue_donnees' );
	add_filter(
		'yume_comptes_vue_donnees',
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
 * @param array $get     Paramètres GET (vue=comptes ajouté).
 */
function yume_tcv_rendu( int $user_id, array $get = array() ): string {
	$_GET = array_merge( array( 'vue' => 'comptes' ), $get ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	wp_set_current_user( $user_id );
	return rendu_vue_comptes();
}

/**
 * Compte de la liste (forme exacte du contrat de lister_comptes()).
 *
 * @param int   $id     Identifiant.
 * @param array $champs Champs remplacés.
 * @return array<string,mixed>
 */
function yume_tcv_compte( int $id, array $champs = array() ): array {
	return array_merge(
		array(
			'id'               => $id,
			'pseudo'           => 'Lecteur ' . $id,
			'roles'            => array( 'subscriber' ),
			'role'             => 'Abonné',
			'equipe'           => false,
			'inscrit_le'       => '2026-10-12T08:30:00+00:00',
			'source'           => 'inscription',
			'source_deduite'   => false,
			'discord'          => '',
			'sans_mdp'         => false,
			'activite'         => '2026-10-22',
			'derniere_lecture' => '2026-10-23T19:00:00+00:00',
			'chapitres_lus'    => 37,
			'series'           => 2,
			'favoris'          => 4,
			'suiveurs'         => 3,
			'commentaires'     => 12,
		),
		$champs
	);
}

/**
 * Résultat de lister_comptes().
 *
 * @param array $comptes Comptes.
 * @param array $champs  Champs remplacés (total, page, pages…).
 * @return array<string,mixed>
 */
function yume_tcv_liste( array $comptes, array $champs = array() ): array {
	return array_merge(
		array(
			'total'   => count( $comptes ),
			'page'    => 1,
			'pages'   => 1,
			'limite'  => COMPTES_PAR_PAGE,
			'args'    => args_comptes( array() ),
			'comptes' => $comptes,
		),
		$champs
	);
}

/**
 * Indicateurs (forme exacte du contrat de indicateurs()).
 *
 * @return array<string,mixed>
 */
function yume_tcv_indicateurs(): array {
	return array(
		'comptes'         => 1312,
		'lecteurs'        => 1303,
		'equipe'          => 9,
		'nouveaux_30j'    => 41,
		'actifs_30j'      => 128,
		'discord'         => 57,
		'sans_mdp'        => 23,
		'activite_depuis' => '2026-10-20',
	);
}

/**
 * Contenu du tableau des comptes (tbody), '' s'il n'y en a pas.
 *
 * @param string $html HTML de la vue.
 */
function yume_tcv_corps( string $html ): string {
	return preg_match( '#<table class="yn-kpi__table yn-comptes__table"><caption id="yn-comptes-legende".*?</table>#s', $html, $m ) ? $m[0] : '';
}

/*
 * -----------------------------------------------------------------------------
 * Déclaration, navigation, accès
 * -----------------------------------------------------------------------------
 */

yume_tcv_test(
	'menu : « Comptes inscrits » dans « Équipe » pour gérants et administrateurs, absent pour éditeur, traducteur, lecteur',
	static function () {
		foreach ( array( 'yume_gerant', 'administrator' ) as $role ) {
			wp_set_current_user( yume_factory_user( $role ) );
			$vues = vues_equipe_ajoutees();
			yume_assert_true( isset( $vues['comptes'] ), $role );
			yume_assert_same( 'equipe', $vues['comptes']['groupe'], $role );
			yume_assert_same( CAPACITE, $vues['comptes']['capacite'], $role );
			yume_assert_same( 'Comptes inscrits', $vues['comptes']['libelle'], $role );
		}
		$_GET['vue'] = 'comptes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( array( 'yume_editeur', 'yume_traducteur', 'yume_relecteur', 'subscriber' ) as $role ) {
			wp_set_current_user( yume_factory_user( $role ) );
			yume_assert_false( isset( vues_equipe_ajoutees()['comptes'] ), $role . ' : pas d’entrée' );
			yume_assert_not_contains( 'vue=comptes', navigation_equipe( 'tableau' ), $role . ' : pas de lien' );
			yume_assert_same( '', vue_equipe(), $role . ' : ?vue=comptes ignoré' );
		}
	}
);

yume_tcv_test(
	'menu : juste après « Membres et rôles », avant « Commentaires » ; vue reconnue, entrée active, menu ouvert',
	static function () {
		$gerant = yume_factory_user( 'yume_gerant' );
		$post   = yume_factory_post( array( 'post_title' => 'Billet commenté' ) );
		wp_insert_comment(
			array(
				'comment_post_ID'  => $post,
				'comment_content'  => 'À modérer',
				'comment_approved' => '0',
				'comment_author'   => 'Anonyme',
			)
		);
		wp_set_current_user( $gerant );
		$cles = array_keys( vues_equipe_ajoutees() );
		yume_assert_true( in_array( 'commentaires', $cles, true ), 'commentaires à modérer : entrée présente' );
		yume_assert_true( array_search( 'comptes', $cles, true ) < array_search( 'commentaires', $cles, true ), 'comptes avant commentaires' );

		$nav = navigation_equipe( 'tableau' );
		preg_match( '#<summary>Équipe</summary><ul>(.*?)</ul>#s', $nav, $menu );
		preg_match_all( '#<a href="[^"]*"[^>]*>([^<]*)</a>#', $menu[1] ?? '', $liens );
		yume_assert_same( 'Membres et rôles', $liens[1][0] ?? '', 'premier : Membres et rôles' );
		yume_assert_same( 'Comptes inscrits', $liens[1][1] ?? '', 'deuxième : Comptes inscrits' );
		yume_assert_contains( 'Commentaires', $liens[1][2] ?? '', 'puis Commentaires' );

		$_GET['vue'] = 'comptes'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		yume_assert_same( 'comptes', vue_equipe() );
		$nav = navigation_equipe( 'comptes' );
		yume_assert_contains( '<details open><summary>Équipe</summary>', $nav, 'menu « Équipe » ouvert' );
		yume_assert_true( (bool) preg_match( '#<a href="[^"]*vue=comptes[^"]*" aria-current="page">Comptes inscrits</a>#', $nav ), 'entrée active' );
		yume_assert_same( 1, substr_count( $nav, 'aria-current' ) );

		yume_tcv_donnees( array() );
		yume_assert_contains( 'yn-team--comptes', yume_render_block( 'yume/team-dashboard' ), 'rendue par le bloc de l’espace équipe' );
	}
);

yume_tcv_test(
	'accès : encart « Réservé aux gérants » sans la capacité ; feuille de style enfilée (dépend de yume-kpi)',
	static function () {
		yume_tcv_donnees(
			array(
				'lister_comptes' => yume_tcv_liste( array( yume_tcv_compte( 7 ) ) ),
				'indicateurs'    => yume_tcv_indicateurs(),
			)
		);
		foreach ( array( 'yume_editeur', 'yume_traducteur', 'subscriber' ) as $role ) {
			$html = yume_tcv_rendu( yume_factory_user( $role ) );
			yume_assert_contains( 'Réservé aux gérants', $html, $role );
			yume_assert_not_contains( 'Lecteur 7', $html, $role . ' : aucun compte affiché' );
			yume_assert_not_contains( '<table', $html, $role );
			yume_assert_not_contains( 'yn-comptes__filtres', $html, $role );
		}
		$html = yume_tcv_rendu( yume_factory_user( 'yume_gerant' ), array( 'compte' => '7' ) );
		yume_assert_not_contains( 'Réservé aux gérants', $html );
		yume_assert_true( wp_style_is( VUE_STYLE, 'enqueued' ), 'feuille enfilée' );
		yume_assert_same( array( 'yume-kpi' ), wp_styles()->registered[ VUE_STYLE ]->deps );
		yume_assert_contains( 'includes/comptes/assets/comptes.css', (string) wp_styles()->registered[ VUE_STYLE ]->src );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Liste
 * -----------------------------------------------------------------------------
 */

yume_tcv_test(
	'liste : en-tête, indicateurs, tableau (colonnes, pastilles, dates, activité), aucune colonne e-mail, aucun script',
	static function () {
		$recu = array();
		yume_tcv_donnees(
			array(
				'indicateurs'    => yume_tcv_indicateurs(),
				'lister_comptes' => static function ( $args ) use ( &$recu ) {
					$recu = $args;
					return yume_tcv_liste(
						array(
							yume_tcv_compte(
								11,
								array(
									'pseudo'   => 'Sakura_Lit',
									'discord'  => 'sakura_lit',
									'source'   => 'discord',
									'activite' => '2026-10-24',
									'email'    => 'fuite@example.test',
								)
							),
							yume_tcv_compte(
								12,
								array(
									'pseudo'           => 'Mio des Brumes',
									'roles'            => array( 'yume_traducteur' ),
									'role'             => 'Traducteur',
									'equipe'           => true,
									'source'           => 'inconnue',
									'activite'         => '2026-10-23',
									'derniere_lecture' => '',
									'chapitres_lus'    => 0,
									'favoris'          => 0,
									'suiveurs'         => 0,
									'commentaires'     => 1480,
								)
							),
							yume_tcv_compte(
								13,
								array(
									'pseudo'         => 'Kenji',
									'sans_mdp'       => true,
									'source_deduite' => true,
									'activite'       => '',
								)
							),
							yume_tcv_compte( 14, array( 'activite' => '2026-10-15' ) ),
							yume_tcv_compte( 15, array( 'activite' => '2026-06-01' ) ),
						),
						array( 'total' => 5 )
					);
				},
			)
		);
		$html = yume_tcv_rendu( yume_factory_user( 'yume_gerant' ) );

		yume_assert_same( args_comptes( array() ), $recu, 'lister_comptes reçoit les paramètres par défaut' );
		yume_assert_contains( '<h2 class="yn-team__bonjour">Comptes inscrits</h2>', $html );
		yume_assert_contains( 'Statistiques des lecteurs', $html );
		yume_assert_contains( 'onglet=lecteurs', $html, 'lien vers l’onglet Lecteurs des statistiques' );
		yume_assert_contains( '>Membres et rôles</a>', $html );
		yume_assert_contains( 'depuis le 20 oct. 2026', $html, 'début du relevé de l’activité' );

		// Indicateurs.
		preg_match( '#<section class="yn-comptes__chiffres".*?</section>#s', $html, $chiffres );
		$chiffres = $chiffres[0] ?? '';
		yume_assert_same( 6, substr_count( $chiffres, 'yn-comptes__chiffre"' ), 'six cartes' );
		foreach ( array( 'Comptes', 'Lecteurs', 'Équipe', 'Nouveaux (30 j)', 'Actifs (30 j)', 'Liés à Discord' ) as $libelle ) {
			yume_assert_contains( '<p class="yn-label">' . $libelle . '</p>', $chiffres, $libelle );
		}
		yume_assert_contains( number_format_i18n( 1312 ), $chiffres );
		yume_assert_contains( 'dont 23 sans mot de passe', $chiffres );
		yume_assert_contains( 'relevé depuis le 20 oct. 2026', $chiffres );
		yume_assert_contains( 'Évolution', $chiffres );

		// Tableau.
		$table = yume_tcv_corps( $html );
		yume_assert_true( '' !== $table, 'tableau présent' );
		yume_assert_contains( '<div class="yn-kpi__defile yn-comptes__defile" role="region" tabindex="0"', $html, 'défilement accessible sur mobile' );
		preg_match_all( '#<th scope="col"[^>]*>(.*?)</th>#', $table, $entetes );
		yume_assert_same( array( 'Pseudo', 'Rôle', 'Inscrit le', 'Source', 'Dernière activité', 'Dernière lecture', 'Chap. lus (est.)Chapitres lus (estimation)', 'Favoris', 'Comm.Commentaires' ), array_map( 'wp_strip_all_tags', $entetes[1] ) );
		yume_assert_contains( '<span aria-hidden="true">Comm.</span><span class="yn-visually-hidden">Commentaires</span>', $table, 'en-tête abrégé lu en entier' );
		yume_assert_contains( 'aria-sort="descending">Inscrit le', $table, 'tri courant signalé' );
		yume_assert_same( 5, substr_count( $table, '<th scope="row">' ) );
		yume_assert_not_contains( 'fuite@example.test', $html, 'jamais d’e-mail' );
		yume_assert_false( (bool) preg_match( '#e-mail</th>#i', $html ), 'aucune colonne e-mail' );
		yume_assert_not_contains( '<script', $html, 'aucun JavaScript' );

		// Pastilles, rôle, source, dates, nombres.
		yume_assert_true( (bool) preg_match( '#>Sakura_Lit</a> <span class="yn-chip yn-comptes__badge yn-comptes__badge--discord">Discord</span>#', $table ), 'pastille Discord' );
		yume_assert_true( (bool) preg_match( '#>Mio des Brumes</a> <span class="yn-chip yn-comptes__badge yn-comptes__badge--equipe">Équipe</span>#', $table ), 'pastille Équipe' );
		yume_assert_contains( '>Sans mot de passe</span>', $table, 'pastille sans mot de passe' );
		yume_assert_contains( '<td>Traducteur</td>', $table );
		yume_assert_contains( '<td>Discord</td>', $table, 'source Discord' );
		yume_assert_contains( '<td>Inconnue</td>', $table );
		yume_assert_contains( 'Formulaire du site <span class="yn-muted">(déduite)</span>', $table, 'source déduite' );
		yume_assert_contains( '<time datetime="2026-10-12T08:30:00+00:00">12 oct. 2026</time>', $table, 'inscription' );
		yume_assert_contains( '>23 oct. 2026</time>', $table, 'dernière lecture' );
		yume_assert_contains( '<time datetime="2026-10-24">aujourd’hui</time>', $table );
		yume_assert_contains( '<time datetime="2026-10-23">hier</time>', $table );
		yume_assert_contains( '<time datetime="2026-10-15">il y a 9 j</time>', $table );
		yume_assert_contains( '<time datetime="2026-06-01">1 juin 2026</time>', $table, 'au-delà de 60 jours : la date' );
		yume_assert_contains( '<span class="yn-muted">inconnue</span>', $table, 'activité inconnue' );
		yume_assert_contains( number_format_i18n( 1480 ), $table );
		yume_assert_contains( '4 <span class="yn-muted" aria-hidden="true">(3)</span><span class="yn-visually-hidden">, dont 3 avec alertes</span>', $table, 'favoris dont alertés' );

		yume_assert_contains( '5 comptes · page 1 sur 1', $html, 'résumé' );
		yume_assert_not_contains( 'Exporter la liste', $html, 'pas d’export sans url_export()' );
		yume_assert_not_contains( 'yn-team__pages', $html, 'pas de pagination sur une seule page' );
		yume_assert_contains( 'Chaque fiche consultée et chaque export sont journalisés', $html );
	}
);

yume_tcv_test(
	'filtres : formulaire GET (vue cachée, listes, cases, Filtrer, Réinitialiser), valeurs reprises, filtres rapides',
	static function () {
		$recu = array();
		yume_tcv_donnees(
			array(
				'lister_comptes' => static function ( $args ) use ( &$recu ) {
					$recu = $args;
					return yume_tcv_liste( array( yume_tcv_compte( 21 ) ) );
				},
			)
		);
		$uid  = yume_factory_user( 'yume_gerant' );
		$html = yume_tcv_rendu( $uid );
		yume_assert_contains( '<form class="yn-card yn-comptes__filtres" method="get"', $html, 'formulaire GET' );
		yume_assert_contains( 'role="search"', $html );
		yume_assert_contains( '<input type="hidden" name="vue" value="comptes">', $html );
		foreach ( array( 'role', 'source', 'discord', 'activite', 'inscrits', 'tri', 'ordre' ) as $nom ) {
			yume_assert_contains( 'name="' . $nom . '"', $html, $nom );
		}
		yume_assert_contains( 'type="search" id="yn-comptes-q" name="q" value=""', $html );
		yume_assert_contains( 'name="sans_lecture" value="1">', $html );
		yume_assert_contains( 'name="sans_mdp" value="1">', $html );
		yume_assert_contains( '>Filtrer</button>', $html );
		yume_assert_true( (bool) preg_match( '#<a class="yn-comptes__reinitialiser" href="[^"]*vue=comptes"#', $html ), 'Réinitialiser : liste sans filtre' );
		yume_assert_true( (bool) preg_match( '#aria-current="true" data-yn-rapide="tous">Tous</a>#', $html ), 'filtre rapide « Tous » courant' );
		yume_assert_not_contains( 'Aucun compte', $html );
		yume_assert_not_contains( 'yn-comptes__journal', $html, 'journal : pas pour un gérant' );

		$html = yume_tcv_rendu(
			$uid,
			array(
				'q'        => 'Sa"kura',
				'role'     => 'equipe',
				'source'   => 'discord',
				'discord'  => 'lie',
				'activite' => '30',
				'inscrits' => '365',
				'sans_mdp' => '1',
				'tri'      => 'pseudo',
				'ordre'    => 'desc',
			)
		);
		yume_assert_same( 'Sa"kura', $recu['q'] );
		yume_assert_same( 'equipe', $recu['role'] );
		yume_assert_same( 'discord', $recu['source'] );
		yume_assert_same( 'lie', $recu['discord'] );
		yume_assert_same( '30', $recu['activite'] );
		yume_assert_same( '365', $recu['inscrits'] );
		yume_assert_true( $recu['sans_mdp'] );
		yume_assert_false( $recu['sans_lecture'] );
		yume_assert_same( 'pseudo', $recu['tri'] );
		yume_assert_same( 'desc', $recu['ordre'] );
		yume_assert_contains( 'name="q" value="Sa&quot;kura"', $html, 'recherche reprise, échappée' );
		foreach ( array(
			'role'     => 'equipe',
			'source'   => 'discord',
			'discord'  => 'lie',
			'activite' => '30',
			'inscrits' => '365',
			'tri'      => 'pseudo',
			'ordre'    => 'desc',
		) as $nom => $valeur ) {
			yume_assert_true( (bool) preg_match( '#<select id="yn-comptes-' . $nom . '" name="' . $nom . '">.*?<option value="' . $valeur . '" selected=\'selected\'>#s', $html ), $nom . ' sélectionné' );
		}
		yume_assert_contains( 'name="sans_mdp" value="1" checked=\'checked\'>', $html );
		yume_assert_contains( 'name="sans_lecture" value="1">', $html );
		yume_assert_contains( '1 compte correspond aux filtres', $html );
		yume_assert_false( (bool) preg_match( '#aria-current="true" data-yn-rapide#', $html ), 'aucun filtre rapide courant' );
		// Lien vers la fiche : filtres de la liste conservés.
		yume_assert_true( (bool) preg_match( '#<a class="yn-comptes__pseudo" href="([^"]*)">Lecteur 21</a>#', $html, $lien ), 'lien vers la fiche' );
		$fiche = html_entity_decode( $lien[1] ?? '', ENT_QUOTES );
		yume_assert_contains( 'vue=comptes', $fiche );
		yume_assert_contains( 'compte=21', $fiche );
		yume_assert_contains( 'role=equipe', $fiche );
		yume_assert_contains( 'sans_mdp=1', $fiche );

		// Filtre rapide « Discord » courant (le tri ne compte pas).
		$html = yume_tcv_rendu(
			$uid,
			array(
				'discord' => 'lie',
				'tri'     => 'favoris',
			)
		);
		yume_assert_true( (bool) preg_match( '#href="[^"]*discord=lie" aria-current="true" data-yn-rapide="discord">Discord</a>#', $html ), 'filtre rapide Discord' );
		yume_assert_same( 1, substr_count( $html, 'aria-current="true" data-yn-rapide' ) );
		yume_assert_contains( 'aria-sort="descending">Favoris</th>', $html );
	}
);

yume_tcv_test(
	'état invalide : valeurs inconnues ramenées aux défauts (rôle, tri, ordre, page, activité, cases), compte non numérique ignoré',
	static function () {
		$recu = array();
		yume_tcv_donnees(
			array(
				'lister_comptes' => static function ( $args ) use ( &$recu ) {
					$recu = $args;
					return yume_tcv_liste( array() );
				},
				'rendu_fiche'    => '<p>FICHE</p>',
			)
		);
		$_GET = array( // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'vue'          => 'comptes',
			'role'         => 'pirate',
			'source'       => '<b>',
			'discord'      => array( 'lie' ),
			'activite'     => '999',
			'inscrits'     => '12',
			'tri'          => 'email',
			'ordre'        => 'haut',
			'pg'           => '-3',
			'limite'       => '5000',
			'sans_lecture' => 'peut-être',
			'q'            => 'a',
			'compte'       => 'abc',
		);
		wp_set_current_user( yume_factory_user( 'yume_gerant' ) );
		$etat = vue_etat();
		yume_assert_same( 0, $etat['compte'] );
		$html = rendu_vue_comptes();
		yume_assert_not_contains( 'FICHE', $html, 'compte invalide : la liste' );
		$attendu           = args_comptes( array() );
		$attendu['limite'] = 100;
		yume_assert_same( $attendu, $recu );
		yume_assert_true( (bool) preg_match( '#name="role">.*?<option value="tous" selected=\'selected\'>#s', $html ), 'rôle : tous' );
		yume_assert_true( (bool) preg_match( '#name="tri">.*?<option value="inscription" selected=\'selected\'>#s', $html ), 'tri : inscription' );
		yume_assert_true( (bool) preg_match( '#name="ordre">.*?<option value="" selected=\'selected\'>#s', $html ), 'ordre : par défaut' );
		yume_assert_contains( 'Aucun compte pour le moment.', $html, 'limite seule : pas de filtre actif' );
		yume_assert_contains( '<input type="hidden" name="limite" value="100">', $html, 'taille de page plafonnée, conservée' );
	}
);

yume_tcv_test(
	'échappement : pseudo décodé de &amp; puis échappé, balises neutralisées, rôle et source échappés',
	static function () {
		yume_tcv_donnees(
			array(
				'indicateurs'    => yume_tcv_indicateurs(),
				'lister_comptes' => yume_tcv_liste(
					array(
						yume_tcv_compte(
							31,
							array(
								'pseudo' => 'Tom &amp; Jerry',
								'role'   => '<i>Rôle</i>',
								'source' => '<script>',
							)
						),
						yume_tcv_compte( 32, array( 'pseudo' => '<script>alert(1)</script>"\'' ) ),
						yume_tcv_compte( 33, array( 'pseudo' => '' ) ),
					)
				),
			)
		);
		$html = yume_tcv_rendu( yume_factory_user( 'administrator' ) );
		yume_assert_contains( '>Tom &amp; Jerry</a>', $html, 'décodé une fois' );
		yume_assert_not_contains( '&amp;amp;', $html, 'pas de double encodage' );
		yume_assert_not_contains( '<script', $html );
		yume_assert_contains( '&lt;script&gt;alert(1)&lt;/script&gt;&quot;&#039;</a>', $html );
		yume_assert_contains( '<td>&lt;i&gt;Rôle&lt;/i&gt;</td>', $html );
		yume_assert_contains( '<td>Inconnue</td>', $html, 'source inconnue → « Inconnue »' );
		yume_assert_contains( '>Compte n° 33</a>', $html, 'pseudo vide' );
	}
);

yume_tcv_test(
	'liste vide et données absentes : « Aucun compte », « ne correspond à ces filtres », liste ou indicateurs indisponibles',
	static function () {
		$uid = yume_factory_user( 'yume_gerant' );
		yume_tcv_donnees(
			array(
				'indicateurs'    => yume_tcv_indicateurs(),
				'lister_comptes' => yume_tcv_liste( array() ),
				'url_export'     => 'https://exemple.test/export',
			)
		);
		$html = yume_tcv_rendu( $uid );
		yume_assert_contains( 'Aucun compte pour le moment.', $html );
		yume_assert_contains( '0 compte', $html );
		yume_assert_same( '', yume_tcv_corps( $html ), 'pas de tableau' );
		yume_assert_not_contains( 'Exporter la liste', $html, 'pas d’export d’une liste vide' );

		$html = yume_tcv_rendu( $uid, array( 'q' => 'personne' ) );
		yume_assert_contains( 'Aucun compte ne correspond à ces filtres.', $html );

		yume_tcv_donnees( array() );
		$html = yume_tcv_rendu( $uid );
		yume_assert_contains( 'La liste des comptes n’est pas disponible pour le moment.', $html, 'lister_comptes() absente' );
		yume_assert_contains( 'Indicateurs indisponibles pour le moment.', $html, 'indicateurs() absente' );
		yume_assert_contains( 'yn-comptes__filtres', $html, 'filtres toujours là' );
		update_option( 'yume_comptes_activite_depuis', '2026-10-01' );
		yume_assert_contains( 'depuis le 1 oct. 2026', yume_tcv_rendu( $uid ), 'début du relevé lu dans l’option' );
	}
);

yume_tcv_test(
	'pagination (pg) et export : liens Précédent / Suivant avec les filtres, page sur pages, bouton CSV',
	static function () {
		$exporte = null;
		yume_tcv_donnees(
			array(
				'lister_comptes' => static function ( $args ) {
					return yume_tcv_liste(
						array( yume_tcv_compte( 40 + $args['page'] ) ),
						array(
							'total' => 120,
							'page'  => $args['page'],
							'pages' => 3,
						)
					);
				},
				'url_export'     => static function ( $args ) use ( &$exporte ) {
					if ( ! empty( $args['email'] ) ) {
						return 'https://exemple.test/wp-admin/admin-post.php?action=yume_comptes_export&amp;email=1';
					}
					$exporte = $args;
					return 'https://exemple.test/wp-admin/admin-post.php?action=yume_comptes_export&role=equipe&_wpnonce=abc';
				},
			)
		);
		$uid  = yume_factory_user( 'yume_gerant' );
		$html = yume_tcv_rendu(
			$uid,
			array(
				'role' => 'equipe',
				'pg'   => '2',
			)
		);
		yume_assert_contains( '120 comptes correspondent aux filtres · page 2 sur 3', $html );
		yume_assert_contains( 'Page 2 sur 3', $html );
		yume_assert_true( (bool) preg_match( '#rel="prev" href="([^"]*)"#', $html, $prec ), 'Précédent' );
		yume_assert_true( (bool) preg_match( '#rel="next" href="([^"]*)"#', $html, $suiv ), 'Suivant' );
		$prec = html_entity_decode( $prec[1], ENT_QUOTES );
		$suiv = html_entity_decode( $suiv[1], ENT_QUOTES );
		yume_assert_contains( 'role=equipe', $prec );
		yume_assert_not_contains( 'pg=', $prec, 'page 1 sans pg' );
		yume_assert_contains( 'role=equipe', $suiv );
		yume_assert_contains( 'pg=3', $suiv );
		yume_assert_contains( 'vue=comptes', $suiv );
		yume_assert_contains( 'Lecteur 42', $html );
		preg_match( '#<a class="yn-comptes__pseudo" href="([^"]*)"#', $html, $fiche );
		$fiche = html_entity_decode( $fiche[1] ?? '', ENT_QUOTES );
		yume_assert_contains( 'compte=42', $fiche );
		yume_assert_contains( 'pg=2', $fiche, 'fiche : page de la liste conservée' );

		yume_assert_contains( '<a class="yn-btn" href="https://exemple.test/wp-admin/admin-post.php?action=yume_comptes_export&#038;role=equipe&#038;_wpnonce=abc" download>Exporter la liste (CSV)', $html );
		yume_assert_same( 'equipe', $exporte['role'] ?? '', 'url_export reçoit les filtres' );
		yume_assert_same( 2, $exporte['page'] ?? 0 );
		yume_assert_not_contains( 'Exporter avec e-mail', $html, 'gérant : pas d’export avec e-mail' );
		yume_assert_not_contains( 'email=1', $html );

		$html = yume_tcv_rendu( yume_factory_user( 'administrator' ), array( 'role' => 'equipe' ) );
		yume_assert_contains( 'Exporter la liste (CSV)', $html );
		yume_assert_contains( 'href="https://exemple.test/wp-admin/admin-post.php?action=yume_comptes_export&#038;email=1" download>Exporter avec e-mail', $html, 'administrateur : second lien, adresse déjà encodée gardée' );

		$html = yume_tcv_rendu( $uid, array( 'pg' => '3' ) );
		yume_assert_contains( 'rel="prev"', $html );
		yume_assert_not_contains( 'rel="next"', $html, 'dernière page' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Journal, fiche
 * -----------------------------------------------------------------------------
 */

yume_tcv_test(
	'journal : « Dernières consultations » pour les administrateurs seulement, entrées lisibles',
	static function () {
		$admin  = yume_factory_user( 'administrator' );
		$gerant = yume_factory_user( 'yume_gerant' );
		$compte = yume_factory_user( 'subscriber' );
		wp_update_user(
			array(
				'ID'           => $gerant,
				'display_name' => 'Gérant & Co',
			)
		);
		wp_update_user(
			array(
				'ID'           => $compte,
				'display_name' => 'Lectrice & Cie',
			)
		);
		delete_option( 'yume_comptes_journal' );
		yume_tcv_donnees(
			array(
				'lister_comptes' => yume_tcv_liste( array() ),
				'journal_recent' => null,
			)
		);
		$html = yume_tcv_rendu( $admin );
		yume_assert_contains( '<details class="yn-card yn-comptes__journal"><summary>Dernières consultations', $html );
		yume_assert_contains( 'Aucune consultation journalisée pour le moment.', $html );

		journaliser( 'fiche', $gerant, $compte );
		journaliser( 'export', $gerant, 0, 12, array( 'role' => 'equipe' ) );
		journaliser( 'export_email', $admin, 0, 1 );
		$html = yume_tcv_rendu( $admin );
		preg_match( '#<details class="yn-card yn-comptes__journal">.*?</details>#s', $html, $journal );
		$journal = $journal[0] ?? '';
		yume_assert_same( 3, substr_count( $journal, '<tr><td>' ), 'trois entrées' );
		yume_assert_true( strpos( $journal, 'Export CSV avec e-mails (1 compte)' ) < strpos( $journal, 'Export CSV (12 comptes)' ), 'plus récente en tête' );
		yume_assert_contains( 'Fiche consultée', $journal );
		yume_assert_contains( 'Gérant &amp; Co', $journal );
		yume_assert_contains( '>Lectrice &amp; Cie</a>', $journal, 'compte consulté lié à sa fiche' );
		yume_assert_not_contains( '&amp;amp;', $journal, 'pseudos décodés une fois' );
		yume_assert_contains( 'compte=' . $compte, $journal );
		yume_assert_contains( '24 oct. 2026, 15h00', $journal, 'date et heure de Paris' );

		$html = yume_tcv_rendu( $gerant );
		yume_assert_not_contains( 'Dernières consultations', $html, 'gérant : pas de journal' );
		yume_assert_not_contains( 'Fiche consultée', $html );
	}
);

yume_tcv_test(
	'fiche : ?compte=ID passe l’état à rendu_fiche() ; sans elle, « bientôt disponible » et retour à la liste',
	static function () {
		$recu = null;
		yume_tcv_donnees(
			array(
				'lister_comptes' => yume_tcv_liste( array( yume_tcv_compte( 51 ) ) ),
				'rendu_fiche'    => static function ( $etat ) use ( &$recu ) {
					$recu = $etat;
					return '<section class="yn-test-fiche">Fiche du compte</section>';
				},
			)
		);
		$uid  = yume_factory_user( 'yume_gerant' );
		$html = yume_tcv_rendu(
			$uid,
			array(
				'compte' => '51',
				'role'   => 'lecteurs',
				'pg'     => '2',
			)
		);
		yume_assert_contains( '<section class="yn-test-fiche">Fiche du compte</section>', $html );
		yume_assert_contains( 'yn-team__nav', $html, 'navigation de l’espace équipe' );
		yume_assert_not_contains( 'yn-comptes__filtres', $html, 'pas la liste' );
		yume_assert_not_contains( '<h2 class="yn-team__bonjour">', $html, 'la fiche a sa propre tête' );
		yume_assert_not_contains( 'yn-comptes__chiffres', $html, 'ni indicateurs' );
		yume_assert_contains( 'yn-team--comptes', $html, 'racine de la vue' );
		$attendu           = args_comptes(
			array(
				'role' => 'lecteurs',
				'pg'   => '2',
			)
		);
		$attendu['compte'] = 51;
		yume_assert_same( $attendu, $recu, 'rendu_fiche reçoit args_comptes() + compte, rien d’autre' );
		$liste = html_entity_decode( url_comptes( array_diff_key( $recu, array( 'compte' => 0 ) ) ), ENT_QUOTES );
		yume_assert_contains( 'role=lecteurs', $liste, 'lien de retour possible' );
		yume_assert_contains( 'pg=2', $liste );

		yume_tcv_donnees( array() );
		$html = yume_tcv_rendu( $uid, array( 'compte' => '51' ) );
		yume_assert_contains( 'La fiche d’un compte sera bientôt disponible.', $html );
		yume_assert_contains( '← Retour à la liste des comptes', $html );

		// Sans la capacité : jamais la fiche.
		yume_tcv_donnees( array( 'rendu_fiche' => '<p>FICHE</p>' ) );
		$html = yume_tcv_rendu( yume_factory_user( 'yume_traducteur' ), array( 'compte' => '51' ) );
		yume_assert_not_contains( 'FICHE', $html );
		yume_assert_contains( 'Réservé aux gérants', $html );
	}
);
