<?php
/**
 * Tests de la fiche d'un compte (lot C, includes/comptes/fiche.php) : toutes les sections,
 * e-mail réservé à list_users, identifiant jamais affiché, actions selon le compte courant et le
 * compte affiché (« Ajouter à l'équipe », lien vers Membres et rôles, « Modifier dans
 * l'administration »), compte inexistant, accès refusé, journalisation et action
 * yume_comptes_fiche_consultee, formulaire d'ajout traité par Membres et rôles (user_id),
 * message de retour, listes privées et données push jamais affichées, données absentes.
 *
 * Les données de fiche_compte() sont fournies par le filtre yume_comptes_vue_donnees.
 *
 * Lancement : tools/localenv/test.sh comptes-fiche
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Comptes\formulaire_ajout_equipe;
use function Yume\Core\Comptes\journal_recent;
use function Yume\Core\Comptes\rendu_fiche;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tcf_)
 * -----------------------------------------------------------------------------
 */

/** Maintenant figé des tests : 24 octobre 2026, 12 h UTC. */
const YUME_TCF_MAINTENANT = 1792843200;

/**
 * Crée un compte avec un pseudo (display_name) donné.
 *
 * @param string $role   Rôle.
 * @param string $pseudo Pseudo (vide : celui de la fabrique).
 */
function yume_tcf_compte( string $role, string $pseudo = '' ): int {
	$id = yume_factory_user( $role );
	if ( '' !== $pseudo ) {
		wp_update_user(
			array(
				'ID'           => $id,
				'display_name' => $pseudo,
			)
		);
		clean_user_cache( $id );
	}
	return $id;
}

/**
 * Données de fiche_compte() complètes pour un compte (bouchon).
 *
 * @param int $id Compte.
 * @return array
 */
function yume_tcf_donnees( int $id ): array {
	return array(
		'identite'      => array(
			'id'                     => $id,
			'pseudo'                 => 'Sakura_Lit',
			'roles'                  => array( 'subscriber' ),
			'role'                   => 'Lecteur',
			'equipe'                 => false,
			'inscrit_le'             => '2026-10-12T08:00:00+00:00',
			'source'                 => 'discord',
			'source_deduite'         => false,
			'discord'                => array(
				'pseudo' => 'sakura_lit',
				'lie_le' => '2026-10-12 08:00:00',
			),
			'sans_mdp'               => true,
			'pseudo_est_identifiant' => false,
			'activite'               => '2026-10-22',
			'email_disponible'       => true,
		),
		'lecture'       => array(
			'tomes_termines'  => 2,
			'chapitres_lus'   => 37,
			'minutes'         => 580,
			'series_en_cours' => 2,
			'series_a_jour'   => 1,
			'series'          => array(),
			'derniere'        => array(
				'oeuvre_id'   => 11,
				'titre'       => 'Les Lanternes de Brume-Haute',
				'chapitre_id' => 12,
				'chapitre'    => 'Tome 2, chapitre 5',
				'url'         => 'https://exemple.test/lanternes/',
				'le'          => '2026-10-24T09:00:00+00:00',
			),
			'series_detail'   => array(
				array(
					'oeuvre_id'     => 11,
					'titre'         => 'Les Lanternes de Brume-Haute',
					'url'           => 'https://exemple.test/lanternes/',
					'chapitres_lus' => 17,
					'chapitres'     => 24,
					'a_jour'        => false,
				),
				array(
					'oeuvre_id'     => 13,
					'titre'         => 'Mikadono & <b>Sanshimai</b><script>alert(1)</script>',
					'url'           => 'javascript:alert(1)',
					'chapitres_lus' => 37,
					'chapitres'     => 37,
					'a_jour'        => true,
				),
			),
		),
		'favoris'       => array(
			array(
				'oeuvre_id' => 11,
				'titre'     => 'Les Lanternes de Brume-Haute',
				'url'       => 'https://exemple.test/lanternes/',
				'frequence' => 'immediat',
				'depuis'    => '2026-10-12 10:00:00',
			),
			array(
				'oeuvre_id' => 14,
				'titre'     => 'Grimgar',
				'url'       => 'https://exemple.test/grimgar/',
				'frequence' => 'hebdo',
				'depuis'    => '2026-10-20 10:00:00',
			),
		),
		'alertes'       => array(
			'sorties'      => true,
			'hebdo'        => false,
			'commentaires' => true,
		),
		'listes'        => array(
			'personnelles'    => 3,
			'privees'         => 2,
			'oeuvres_privees' => 14,
			'privees_detail'  => array( array( 'nom' => 'Liste secrète du lecteur' ) ),
			'publiques'       => array(
				array(
					'nom' => 'Mes coups de cœur',
					'url' => 'https://exemple.test/listes/abc/',
					'nb'  => 7,
				),
			),
			'systeme'         => array(
				'a_lire'   => 5,
				'en_cours' => 2,
				'termine'  => 1,
			),
		),
		'notes'         => array(
			'nb'      => 2,
			'moyenne' => 4.5,
			'lignes'  => array(
				array(
					'oeuvre_id' => 11,
					'titre'     => 'Les Lanternes de Brume-Haute',
					'note'      => 5,
					'le'        => '2026-10-20 10:00:00',
				),
				array(
					'oeuvre_id' => 13,
					'titre'     => 'Mikadono Sanshimai',
					'note'      => 4,
					'le'        => '2026-10-18 10:00:00',
				),
			),
		),
		'commentaires'  => array(
			'total'     => 12,
			'approuves' => 11,
			'attente'   => 1,
			'spam'      => 0,
			'derniers'  => array(
				array(
					'id'      => 99,
					'sur'     => 'Tome 2, ch. 4',
					'url'     => 'https://exemple.test/lanternes/t2/c4/#comment-99',
					'date'    => '2026-10-23 10:00:00',
					'statut'  => 'attente',
					'extrait' => 'Merci pour ce chapitre…',
				),
			),
		),
		'coquilles'     => array(
			'total'      => 3,
			'par_statut' => array(
				'nouveau' => 1,
				'corrige' => 2,
				'rejete'  => 0,
			),
			'dernieres'  => array(),
		),
		'notifications' => array(
			'non_lues' => 2,
			'total'    => 14,
			'push'     => 1,
			'endpoint' => 'https://push.exemple.test/SECRET-ENDPOINT',
			'auth'     => 'SECRET-AUTH-KEY',
			'p256dh'   => 'SECRET-P256DH',
		),
	);
}

/**
 * Exécute $rappel en tant que $acteur, horloges figées, fiche_compte() bouchonnée par
 * $donnees (false : fonction absente ; null : rien de bouchonné) ; remet tout en place.
 *
 * @param int        $acteur  Compte courant.
 * @param array|bool $donnees Données de fiche_compte() ou false.
 * @param callable   $rappel  Fonction.
 * @return mixed
 */
function yume_tcf_en_tant_que( int $acteur, $donnees, callable $rappel ) {
	$fige    = static function (): int {
		return YUME_TCF_MAINTENANT;
	};
	$bouchon = static function ( $pre, $fonction ) use ( $donnees ) {
		return 'fiche_compte' === $fonction ? $donnees : $pre;
	};
	add_filter( 'yume_comptes_maintenant', $fige );
	add_filter( 'yume_planning_maintenant', $fige );
	add_filter( 'yume_comptes_vue_donnees', $bouchon, 10, 2 );
	update_option( 'timezone_string', 'Europe/Paris' );
	wp_set_current_user( $acteur );
	try {
		return $rappel();
	} finally {
		wp_set_current_user( 0 );
		remove_filter( 'yume_comptes_maintenant', $fige );
		remove_filter( 'yume_planning_maintenant', $fige );
		remove_filter( 'yume_comptes_vue_donnees', $bouchon, 10 );
	}
}

/**
 * Fiche d'un compte rendue par $acteur avec les données complètes du bouchon.
 *
 * @param int $acteur Compte courant.
 * @param int $id     Compte affiché.
 */
function yume_tcf_fiche( int $acteur, int $id ): string {
	return yume_tcf_en_tant_que(
		$acteur,
		yume_tcf_donnees( $id ),
		static function () use ( $id ): string {
			return rendu_fiche( array( 'compte' => $id ) );
		}
	);
}

/**
 * Valeurs des champs cachés d'un formulaire HTML (nom => valeur, entités décodées).
 *
 * @param string $html HTML.
 * @return array<string,string>
 */
function yume_tcf_champs_caches( string $html ): array {
	preg_match_all( '/<input type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"/', $html, $m, PREG_SET_ORDER );
	$champs = array();
	foreach ( $m as $c ) {
		$champs[ $c[1] ] = html_entity_decode( $c[2], ENT_QUOTES );
	}
	return $champs;
}

/*
 * -----------------------------------------------------------------------------
 * Sections
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Fiche : toutes les sections, valeurs lisibles, HTML échappé',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );
		$url    = static function (): string {
			return 'https://exemple.test/confidentialite/';
		};
		add_filter( 'privacy_policy_url', $url );
		try {
			$html = yume_tcf_fiche( $gerant, $id );
		} finally {
			remove_filter( 'privacy_policy_url', $url );
		}
		foreach (
			array(
				'yn-comptes__fiche',
				'←</span> Comptes inscrits</a>',
				'>Sakura_Lit</h2>',
				'Lecteur · compte n° ' . $id . ' · inscrit le 12 oct. 2026 (il y a 12 jours) · via Discord · dernière activité : il y a 2 jours',
				'>Identité</h2>',
				'Lié à @sakura_lit depuis le 12 oct. 2026',
				'Aucun mot de passe défini (connexion par Discord)',
				'22 oct. 2026 (il y a 2 jours)',
				'>Ajouter à l’équipe</h2>',
				'>Lecture</h2>',
				'Chapitres lus (est.)',
				'>37</dd>',
				'9 h 40',
				'Dernière lecture : <strong><a href="https://exemple.test/lanternes/">Les Lanternes de Brume-Haute</a></strong> · Tome 2, chapitre 5 · 24 oct. 2026',
				'17 / 24',
				'yn-chip yn-chip--ok">À jour',
				'Favoris et alertes (2)',
				'Hebdomadaire',
				'e-mail à chaque sortie : oui · récapitulatif hebdomadaire : non · réponses à ses commentaires : oui',
				'>Listes</h2>',
				'À lire 5 · En cours 2 · Terminé 1',
				'Listes personnelles : 3, dont 1 publique.',
				'<a href="https://exemple.test/listes/abc/">« Mes coups de cœur »</a>',
				'(7 œuvres)',
				'2 listes privées (14 œuvres) : contenu non consultable.',
				'Notes (2 · moyenne ' . number_format_i18n( 4.5, 1 ) . ')', // « 4,5 » en fr_FR, « 4.5 » sans fichiers de langue.
				'Les Lanternes de Brume-Haute : 5/5 · 20 oct. 2026',
				'Commentaires (12)',
				'11 approuvés · 1 en attente',
				'« Merci pour ce chapitre… »',
				'En attente</span>',
				'Coquilles signalées (3)',
				'2 corrigées · 1 nouvelle · 0 rejetée',
				'Voir dans Corrections',
				'2 non lues · 14 reçues (90 j) · 1 appareil push',
				'Consultation journalisée.',
				'href="https://exemple.test/confidentialite/"',
			) as $attendu
		) {
			yume_assert_contains( $attendu, $html, $attendu );
		}
		yume_assert_not_contains( '<script>', $html, 'titre échappé' );
		yume_assert_contains( '>Mikadono &amp; Sanshimai<', $html, 'texte sans balise, échappé' );
		yume_assert_not_contains( 'alert(1)', $html );
		yume_assert_not_contains( 'javascript:', $html, 'adresse non sûre retirée' );
	}
);

yume_test(
	'Fiche : e-mail visible des gérants et administrateurs seulement, identifiant jamais affiché',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$admin  = yume_tcf_compte( 'administrator' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );
		$compte = get_userdata( $id );
		$email  = (string) $compte->user_email;
		$login  = (string) $compte->user_login;
		foreach ( array( $gerant, $admin ) as $acteur ) {
			$html = yume_tcf_fiche( $acteur, $id );
			yume_assert_contains( '>E-mail</dt><dd>' . esc_html( $email ) . ' <span', $html );
			yume_assert_contains( '(visible des gérants, ne pas diffuser)', $html );
			yume_assert_not_contains( $login, str_replace( $email, '', $html ), 'identifiant absent' );
		}
		// Sans list_users (capacité retirée), la fiche reste lisible mais sans e-mail.
		$retirer = static function ( $caps ) {
			unset( $caps['list_users'] );
			return $caps;
		};
		add_filter( 'user_has_cap', $retirer );
		try {
			$html = yume_tcf_fiche( $gerant, $id );
		} finally {
			remove_filter( 'user_has_cap', $retirer );
		}
		yume_assert_contains( '>Identité</h2>', $html );
		yume_assert_not_contains( $email, $html );
		yume_assert_not_contains( '>E-mail</dt>', $html );
	}
);

yume_test(
	'Fiche : listes privées jamais nommées, aucune donnée push brute',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );
		$html   = yume_tcf_fiche( $gerant, $id );
		foreach ( array( 'Liste secrète du lecteur', 'SECRET-ENDPOINT', 'push.exemple.test', 'SECRET-AUTH-KEY', 'SECRET-P256DH', 'p256dh' ) as $interdit ) {
			yume_assert_not_contains( $interdit, $html, $interdit );
		}
	}
);

yume_test(
	'Fiche : sans fiche_compte(), identité lue du compte et sections « indisponibles »',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$id     = yume_tcf_compte( 'subscriber', 'Lune & Soleil' );
		$html   = yume_tcf_en_tant_que(
			$gerant,
			false,
			static function () use ( $id ): string {
				return rendu_fiche( array( 'compte' => $id ) );
			}
		);
		yume_assert_contains( '>Lune &amp; Soleil</h2>', $html, 'pseudo décodé puis échappé une fois' );
		yume_assert_not_contains( '&amp;amp;', $html );
		yume_assert_contains( 'Non lié', $html );
		yume_assert_contains( 'Données indisponibles pour le moment.', $html );
		yume_assert_contains( '>Notifications</h2>', $html );
		yume_assert_same( 'fiche', journal_recent( 1 )[0]['action'] ?? '' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Actions selon les comptes
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Fiche : formulaire « Ajouter à l’équipe » pour un lecteur, lien d’administration selon edit_user',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$admin  = yume_tcf_compte( 'administrator' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );

		$html = yume_tcf_fiche( $gerant, $id );
		yume_assert_contains( 'id="yn-ajouter-membre-form"', $html );
		yume_assert_contains( 'name="role"', $html );
		yume_assert_contains( 'value="yume_traducteur"', $html );
		yume_assert_not_contains( 'value="yume_gerant"', $html, 'rôle non attribuable absent' );
		yume_assert_not_contains( 'user-edit.php', $html, 'gérant : pas de edit_user (SEC-03)' );

		$html = yume_tcf_fiche( $admin, $id );
		yume_assert_contains( 'id="yn-ajouter-membre-form"', $html );
		yume_assert_contains( 'user-edit.php?user_id=' . $id, $html );
		yume_assert_contains( 'Modifier dans l’administration', $html );
	}
);

yume_test(
	'Fiche : gérant vu par un gérant, soi-même, membre de l’équipe',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$autre  = yume_tcf_compte( 'yume_gerant', 'Autre gérant' );
		$trad   = yume_tcf_compte( 'yume_traducteur', 'Calumi' );

		$html = yume_tcf_fiche( $gerant, $autre );
		yume_assert_not_contains( '<form', $html, 'pas de formulaire d’ajout' );
		yume_assert_not_contains( 'user-edit.php', $html, 'pas de lien d’administration' );
		yume_assert_contains( '>Équipe</h2>', $html );
		yume_assert_contains( 'Administrateur ou gérant : non modifiable depuis l’espace équipe.', $html );
		yume_assert_not_contains( 'Changer son rôle', $html );

		$html = yume_tcf_fiche( $gerant, $gerant );
		yume_assert_not_contains( '<form', $html );
		yume_assert_contains( 'Votre compte : votre rôle ne se change pas ici.', $html );

		$html = yume_tcf_fiche( $gerant, $trad );
		yume_assert_not_contains( '<form', $html, 'déjà dans l’équipe' );
		yume_assert_contains( 'Changer son rôle dans Membres et rôles', $html );
		yume_assert_contains( '#yn-membre-' . $trad . '"', $html );
	}
);

yume_test(
	'Fiche : formulaire d’ajout seulement si peut_gerer_membre(), traité par Membres et rôles (user_id)',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$editeu = yume_tcf_compte( 'yume_editeur' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );
		$compte = get_userdata( $id );

		$sans = yume_tcf_en_tant_que(
			$editeu,
			null,
			static function () use ( $compte ): string {
				return formulaire_ajout_equipe( $compte );
			}
		);
		yume_assert_same( '', $sans, 'éditeur : pas de yume_gerer_equipe' );
		$moi = yume_tcf_en_tant_que(
			$gerant,
			null,
			static function () use ( $gerant ): string {
				return formulaire_ajout_equipe( get_userdata( $gerant ) );
			}
		);
		yume_assert_same( '', $moi, 'son propre compte' );

		wp_set_current_user( $gerant );
		try {
			$form   = formulaire_ajout_equipe(
				$compte,
				array(
					'type'    => 'erreur',
					'message' => 'Message <b>précédent</b>',
					'cible'   => 'yn-ajouter-membre-form',
				)
			);
			$champs = yume_tcf_champs_caches( $form );
			yume_assert_contains( 'method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $form );
			yume_assert_contains( 'Message &lt;b&gt;précédent&lt;/b&gt;', $form );
			yume_assert_same( 'yume_equipe_membres', $champs['action'] ?? '' );
			yume_assert_same( 'ajout', $champs['op'] ?? '' );
			yume_assert_same( (string) $id, $champs['user_id'] ?? '' );
			yume_assert_false( isset( $champs['compte'] ), 'champ compte absent' );
			yume_assert_true( false !== wp_verify_nonce( $champs['_yume_nonce'] ?? '', 'yume_membres_ajout' ), 'nonce yume_membres_ajout' );
			yume_assert_contains( 'compte=' . $id, $champs['yn_retour'] ?? '' );
			yume_assert_contains( 'vue=comptes', $champs['yn_retour'] ?? '' );
			yume_assert_same( $champs['yn_retour'], wp_validate_redirect( $champs['yn_retour'], '' ), 'adresse de retour sur le site' );

			$post   = array_merge( $champs, array( 'role' => 'yume_traducteur' ) );
			$retour = \Yume\Core\Planning\traiter_formulaire_membres( wp_slash( $post ), $gerant );
		} finally {
			wp_set_current_user( 0 );
		}
		yume_assert_same( 'ok', $retour['type'], $retour['message'] );
		yume_assert_same( 'yn-ajouter-membre-form', $retour['cible'] );
		clean_user_cache( $id );
		yume_assert_same( array( 'yume_traducteur' ), array_values( get_userdata( $id )->roles ) );
	}
);

yume_test(
	'Fiche : message de retour du formulaire affiché après la redirection (yume_membres)',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$id     = yume_tcf_compte( 'yume_traducteur', 'Sakura_Lit' );
		$retour = array(
			'type'    => 'ok',
			'message' => 'Sakura_Lit rejoint l’équipe : Traducteur.',
			'cible'   => 'yn-ajouter-membre-form',
		);
		\Yume\Core\Planning\retour_formulaire( $gerant, $retour );
		$html = yume_tcf_fiche( $gerant, $id );
		yume_assert_not_contains( 'rejoint l’équipe', $html, 'sans yume_membres : message non lu' );

		$_GET['yume_membres'] = 'ok';
		try {
			$html = yume_tcf_fiche( $gerant, $id );
		} finally {
			unset( $_GET['yume_membres'] );
		}
		yume_assert_contains( 'id="yn-ajouter-membre-form"', $html );
		yume_assert_contains( 'yn-team__retour--ok', $html );
		yume_assert_contains( 'Sakura_Lit rejoint l’équipe : Traducteur.', $html );
		yume_assert_contains( 'Changer son rôle dans Membres et rôles', $html );
		yume_assert_same( null, \Yume\Core\Planning\retour_formulaire( $gerant ), 'message lu une fois' );

		// Message destiné à un autre formulaire : ignoré.
		\Yume\Core\Planning\retour_formulaire(
			$gerant,
			array(
				'type'    => 'ok',
				'message' => 'Autre message',
				'cible'   => 'yn-membres',
			)
		);
		$_GET['yume_membres'] = 'ok';
		try {
			$html = yume_tcf_fiche( $gerant, $id );
		} finally {
			unset( $_GET['yume_membres'] );
		}
		yume_assert_not_contains( 'Autre message', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Accès, compte inexistant, journal
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Fiche : consultation journalisée et action yume_comptes_fiche_consultee',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );
		$recus  = array();
		$ecoute = static function ( $compte_id, $acteur ) use ( &$recus ): void {
			$recus[] = array( $compte_id, $acteur );
		};
		add_action( 'yume_comptes_fiche_consultee', $ecoute, 10, 2 );
		try {
			yume_tcf_fiche( $gerant, $id );
		} finally {
			remove_action( 'yume_comptes_fiche_consultee', $ecoute, 10 );
		}
		yume_assert_same( array( array( $id, $gerant ) ), $recus );
		$entree = journal_recent( 1 )[0] ?? array();
		yume_assert_same( 'fiche', $entree['action'] ?? '' );
		yume_assert_same( $gerant, $entree['acteur'] ?? 0 );
		yume_assert_same( $id, $entree['compte'] ?? 0 );
		yume_assert_same( YUME_TCF_MAINTENANT, $entree['quand'] ?? 0 );
	}
);

yume_test(
	'Fiche : compte inexistant et accès refusé, sans journal',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$editeu = yume_tcf_compte( 'yume_editeur' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );
		$avant  = count( journal_recent( 200 ) );
		$vu     = did_action( 'yume_comptes_fiche_consultee' );

		$html = yume_tcf_en_tant_que(
			$gerant,
			null,
			static function (): string {
				return rendu_fiche( array( 'compte' => 999999 ) );
			}
		);
		yume_assert_contains( 'Ce compte n’existe plus.', $html );
		yume_assert_not_contains( '>Identité</h2>', $html );

		$html = yume_tcf_fiche( $editeu, $id );
		yume_assert_contains( 'Réservé aux gérants', $html );
		yume_assert_not_contains( 'Sakura_Lit', $html );

		yume_assert_same( $avant, count( journal_recent( 200 ) ), 'rien journalisé' );
		yume_assert_same( $vu, did_action( 'yume_comptes_fiche_consultee' ) );
	}
);

yume_test(
	'Fiche : lien de retour vers la liste avec ses filtres',
	function () {
		$gerant = yume_tcf_compte( 'yume_gerant' );
		$id     = yume_tcf_compte( 'subscriber', 'Sakura_Lit' );
		$html   = yume_tcf_en_tant_que(
			$gerant,
			yume_tcf_donnees( $id ),
			static function () use ( $id ): string {
				return rendu_fiche(
					array(
						'compte' => $id,
						'q'      => 'saku',
						'tri'    => 'pseudo',
						'page'   => 2,
					)
				);
			}
		);
		preg_match( '/class="yn-comptes__fiche-retour"><a href="([^"]+)"/', $html, $m );
		$href = html_entity_decode( $m[1] ?? '', ENT_QUOTES );
		yume_assert_contains( 'vue=comptes', $href );
		yume_assert_contains( 'q=saku', $href );
		yume_assert_contains( 'tri=pseudo', $href );
		yume_assert_contains( 'pg=2', $href );
		yume_assert_not_contains( 'compte=', $href );
	}
);
