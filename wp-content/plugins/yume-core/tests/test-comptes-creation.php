<?php
/**
 * Tests du socle des comptes lecteurs (lot 0 du chantier « lecteurs ») : creer_compte_lecteur(),
 * generer_identifiant(), valider_pseudo(), pseudo_pris(), compte_sans_mot_de_passe()
 * (includes/social/creation-compte.php), points d'extension de la page compte et des
 * formulaires visiteur, filtre et action RGPD, type de réglage « secret », test « Santé du site »
 * yume_inscriptions et modules déclarés.
 *
 * Lancement : tools/localenv/test.sh comptes-creation
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Social\compte_sans_mot_de_passe;
use function Yume\Core\Social\creer_compte_lecteur;
use function Yume\Core\Social\donnees_personnelles;
use function Yume\Core\Social\effacer_donnees;
use function Yume\Core\Social\generer_identifiant;
use function Yume\Core\Social\pseudo_pris;
use function Yume\Core\Social\supprimer_compte;
use function Yume\Core\Social\valider_pseudo;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tcc_)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute $rappel et renvoie les destinataires des e-mails envoyés par wp_mail().
 *
 * @param callable $rappel Fonction.
 * @return string[]
 */
function yume_tcc_destinataires( callable $rappel ): array {
	$envois = array();
	$filtre = static function ( $retour, $atts ) use ( &$envois ) {
		foreach ( (array) $atts['to'] as $destinataire ) {
			$envois[] = (string) $destinataire;
		}
		return true;
	};
	add_filter( 'pre_wp_mail', $filtre, 5, 2 );
	try {
		$rappel();
	} finally {
		remove_filter( 'pre_wp_mail', $filtre, 5 );
	}
	return $envois;
}

/**
 * Données d'un compte valide (pseudo et adresse uniques).
 *
 * @param array $autres Valeurs remplacées.
 */
function yume_tcc_donnees( array $autres = array() ): array {
	$n = wp_rand( 100000, 999999 );
	return array_merge(
		array(
			'pseudo' => 'Mio des Brumes ' . $n,
			'email'  => 'mio' . $n . '@example.test',
		),
		$autres
	);
}

/**
 * Exécute $rappel avec les inscriptions ouvertes ou fermées, puis rétablit l'option.
 *
 * @param bool     $ouvertes Inscriptions ouvertes.
 * @param callable $rappel   Fonction.
 * @return mixed
 */
function yume_tcc_inscriptions( bool $ouvertes, callable $rappel ) {
	$avant = get_option( 'users_can_register' );
	update_option( 'users_can_register', $ouvertes ? 1 : 0 );
	try {
		return $rappel();
	} finally {
		update_option( 'users_can_register', $avant );
	}
}

/**
 * Code d'erreur d'un résultat (chaîne vide si ce n'est pas une erreur).
 *
 * @param mixed $resultat Résultat.
 */
function yume_tcc_code( $resultat ): string {
	return is_wp_error( $resultat ) ? (string) $resultat->get_error_code() : '';
}

/**
 * HTML d'un champ de la page Yume → Réglages (afficher_champ()).
 *
 * @param array $champ Champ (après champs_reglages()).
 */
function yume_tcc_champ_admin( array $champ ): string {
	ob_start();
	\Yume\Core\Core\afficher_champ( array( 'champ' => $champ ) );
	return (string) ob_get_clean();
}

/**
 * Champ secret de test tel que champs_reglages() le normalise.
 *
 * @param array $autres Clés supplémentaires (verrouille…).
 */
function yume_tcc_champ_secret( array $autres = array() ): array {
	$ajout = static function ( $champs ) use ( $autres ) {
		$champs[] = array_merge(
			array(
				'key'         => 'yume_test_secret',
				'label'       => 'Secret de test',
				'type'        => 'secret',
				'section'     => 'site',
				'description' => 'Clé de test.',
			),
			$autres
		);
		return $champs;
	};
	add_filter( 'yume_reglages_champs', $ajout );
	try {
		foreach ( \Yume\Core\Core\champs_reglages() as $champ ) {
			if ( 'yume_test_secret' === $champ['key'] ) {
				return $champ;
			}
		}
	} finally {
		remove_filter( 'yume_reglages_champs', $ajout );
	}
	return array();
}

/*
 * -----------------------------------------------------------------------------
 * creer_compte_lecteur() et ses aides
 * -----------------------------------------------------------------------------
 */

yume_test(
	'creer_compte_lecteur : compte subscriber, pseudo affiché, identifiant généré, action avec source, e-mail',
	function () {
		$recus  = array();
		$ecoute = static function ( $user_id, $source = '' ) use ( &$recus ) {
			$recus[] = array( (int) $user_id, (string) $source );
		};
		add_action( 'yume_lecteur_inscrit', $ecoute, 10, 2 );
		$donnees = yume_tcc_donnees();
		$user_id = 0;
		try {
			$mails = yume_tcc_destinataires(
				static function () use ( $donnees, &$user_id ) {
					$user_id = yume_tcc_inscriptions( true, static fn() => creer_compte_lecteur( $donnees ) );
				}
			);
		} finally {
			remove_action( 'yume_lecteur_inscrit', $ecoute, 10 );
		}
		yume_assert_true( is_int( $user_id ) && $user_id > 0, 'compte créé' );
		$user = get_userdata( $user_id );
		yume_assert_same( array( 'subscriber' ), array_values( $user->roles ) );
		yume_assert_same( $donnees['pseudo'], $user->display_name );
		yume_assert_same( $donnees['pseudo'], (string) get_user_meta( $user_id, 'nickname', true ) );
		yume_assert_same( $donnees['email'], $user->user_email );
		yume_assert_true( (bool) preg_match( '/^yn-[a-z0-9]{8}$/', $user->user_login ), 'identifiant généré : ' . $user->user_login );
		yume_assert_same( array( array( $user_id, 'inscription' ) ), $recus, 'yume_lecteur_inscrit( id, source )' );
		yume_assert_same( array( $donnees['email'] ), $mails, 'e-mail « choisissez votre mot de passe » au titulaire, rien à l’administrateur' );
		yume_assert_false( compte_sans_mot_de_passe( $user_id ), 'méta absente par défaut' );
		yume_assert_true( (bool) get_user_meta( $user_id, 'default_password_nag', true ), 'mot de passe aléatoire : rappel WordPress' );
	}
);

yume_test(
	'creer_compte_lecteur : notifier faux, administrateur par filtre, mot de passe, identifiant, métas, source équipe',
	function () {
		$donnees = yume_tcc_donnees(
			array(
				'identifiant'  => 'mio_' . wp_rand( 1000, 9999 ),
				'mot_de_passe' => 'Correct-Cheval-Pile-42',
				'source'       => 'equipe',
				'notifier'     => false,
				'meta'         => array(
					'_yume_compte_sans_mdp' => 1,
					'yume_test_meta'        => 'abc',
				),
			)
		);
		$user_id = 0;
		$mails   = yume_tcc_destinataires(
			static function () use ( $donnees, &$user_id ) {
				$user_id = creer_compte_lecteur( $donnees );
			}
		);
		yume_assert_true( is_int( $user_id ), yume_tcc_code( $user_id ) );
		yume_assert_same( array(), $mails, 'aucun e-mail' );
		$user = get_userdata( $user_id );
		yume_assert_same( $donnees['identifiant'], $user->user_login );
		yume_assert_true( wp_check_password( 'Correct-Cheval-Pile-42', $user->user_pass, $user_id ) );
		yume_assert_false( (bool) get_user_meta( $user_id, 'default_password_nag', true ), 'mot de passe fourni : pas de rappel' );
		yume_assert_true( compte_sans_mot_de_passe( $user_id ) );
		yume_assert_same( 'abc', get_user_meta( $user_id, 'yume_test_meta', true ) );
		yume_assert_false( compte_sans_mot_de_passe( 0 ) );

		// Administrateur prévenu seulement par le filtre (même avec notifier faux).
		$admin  = static fn() => true;
		$autres = yume_tcc_donnees(
			array(
				'source'   => 'equipe',
				'notifier' => false,
			)
		);
		add_filter( 'yume_notifier_admin_inscription', $admin );
		try {
			$mails = yume_tcc_destinataires( static fn() => creer_compte_lecteur( $autres ) );
		} finally {
			remove_filter( 'yume_notifier_admin_inscription', $admin );
		}
		yume_assert_same( array( (string) get_option( 'admin_email' ) ), $mails );
	}
);

yume_test(
	'generer_identifiant : motif yn- + 8 caractères [a-z0-9], libre, différent à chaque appel',
	function () {
		$vus = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$id = generer_identifiant();
			yume_assert_true( (bool) preg_match( '/^yn-[a-z0-9]{8}$/', $id ), $id );
			yume_assert_false( (bool) username_exists( $id ) );
			$vus[ $id ] = true;
		}
		yume_assert_same( 20, count( $vus ) );
	}
);

yume_test(
	'valider_pseudo et pseudo_pris : longueur, balises, e-mail, réservés, nom affiché et identifiant (casse)',
	function () {
		$cas = array(
			''                    => 'vide',
			'   '                 => 'vide',
			'a'                   => 'longueur',
			str_repeat( 'é', 41 ) => 'longueur',
			'<b>Mio</b>'          => 'balise',
			"Mio\tBrume"          => 'balise',
			'mio@example.test'    => 'email',
			'Mio @ Brume'         => 'email',
			'Admin'               => 'reserve',
			'administrateur'      => 'reserve',
			'Yume_Novel'          => 'reserve',
			'EQUIPE'              => 'reserve',
			'Équipe'              => 'reserve',
		);
		foreach ( $cas as $pseudo => $raison ) {
			$r = valider_pseudo( (string) $pseudo );
			yume_assert_same( 'yume_pseudo_invalide', yume_tcc_code( $r ), 'pseudo « ' . $pseudo . ' »' );
			yume_assert_same( $raison, $r->get_error_data()['raison'], 'raison pour « ' . $pseudo . ' »' );
			yume_assert_same( 400, $r->get_error_data()['status'] );
		}
		yume_assert_true( valider_pseudo( 'Mio des Brumes ' . wp_rand( 1000, 9999 ) ) );
		yume_assert_true( valider_pseudo( 'Yumeko' ), 'un mot réservé n’est refusé qu’en entier' );
		yume_assert_true( valider_pseudo( str_repeat( 'é', 40 ) ), '40 caractères multioctets' );

		$reserves = static fn( $liste ) => array_merge( $liste, array( 'Lanterne' ) );
		add_filter( 'yume_pseudos_reserves', $reserves );
		try {
			yume_assert_same( 'yume_pseudo_invalide', yume_tcc_code( valider_pseudo( 'lanterne' ) ), 'filtre yume_pseudos_reserves' );
		} finally {
			remove_filter( 'yume_pseudos_reserves', $reserves );
		}

		$autre = yume_factory_user();
		wp_update_user(
			array(
				'ID'           => $autre,
				'display_name' => 'Sakura Lit',
			)
		);
		$login = get_userdata( $autre )->user_login;
		yume_assert_true( pseudo_pris( 'sakura lit' ), 'nom affiché, casse ignorée' );
		yume_assert_true( pseudo_pris( strtoupper( $login ) ), 'identifiant, casse ignorée' );
		yume_assert_false( pseudo_pris( 'Sakura Lit', $autre ), 'son propre pseudo' );
		yume_assert_same( 'yume_pseudo_pris', yume_tcc_code( valider_pseudo( 'SAKURA LIT' ) ) );
		yume_assert_same( 409, valider_pseudo( $login )->get_error_data()['status'] );
		yume_assert_same( 'yume_pseudo_pris', yume_tcc_code( valider_pseudo( $login ) ), 'identifiant d’un autre compte' );
		yume_assert_true( valider_pseudo( 'Sakura Lit', $autre ) );

		$r = creer_compte_lecteur(
			array(
				'pseudo' => 'sakura LIT',
				'email'  => 'sakura' . wp_rand( 1000, 9999 ) . '@example.test',
				'source' => 'equipe',
			)
		);
		yume_assert_same( 'yume_pseudo_pris', yume_tcc_code( $r ) );
		yume_assert_same(
			'yume_pseudo_invalide',
			yume_tcc_code(
				creer_compte_lecteur(
					yume_tcc_donnees(
						array(
							'pseudo' => 'x',
							'source' => 'equipe',
						)
					)
				)
			)
		);
	}
);

yume_test(
	'creer_compte_lecteur : e-mail invalide ou pris, identifiant invalide ou pris, inscriptions fermées sauf équipe',
	function () {
		$existant = get_userdata( yume_factory_user() );
		$equipe   = array( 'source' => 'equipe' );
		yume_assert_same( 'yume_email_invalide', yume_tcc_code( creer_compte_lecteur( yume_tcc_donnees( $equipe + array( 'email' => 'pas-une-adresse' ) ) ) ) );
		yume_assert_same( 'yume_email_invalide', yume_tcc_code( creer_compte_lecteur( yume_tcc_donnees( $equipe + array( 'email' => '' ) ) ) ) );
		$pris = creer_compte_lecteur( yume_tcc_donnees( $equipe + array( 'email' => strtoupper( $existant->user_email ) ) ) );
		yume_assert_same( 'yume_email_pris', yume_tcc_code( $pris ) );
		yume_assert_same( 409, $pris->get_error_data()['status'] );
		yume_assert_same( 'yume_identifiant_pris', yume_tcc_code( creer_compte_lecteur( yume_tcc_donnees( $equipe + array( 'identifiant' => $existant->user_login ) ) ) ) );
		yume_assert_same( 'yume_identifiant_invalide', yume_tcc_code( creer_compte_lecteur( yume_tcc_donnees( $equipe + array( 'identifiant' => 'mio<b>' ) ) ) ) );

		yume_tcc_inscriptions(
			false,
			static function () {
				foreach ( array( 'inscription', 'discord', '', 'inconnue' ) as $source ) {
					$r = creer_compte_lecteur( yume_tcc_donnees( array( 'source' => $source ) ) );
					yume_assert_same( 'yume_inscriptions_fermees', yume_tcc_code( $r ), 'source « ' . $source . ' »' );
					yume_assert_same( 403, $r->get_error_data()['status'] );
				}
				yume_assert_true( is_int( creer_compte_lecteur( yume_tcc_donnees( array( 'source' => 'equipe' ) ) ) ), 'source equipe' );
			}
		);
		$discord = yume_tcc_inscriptions(
			true,
			static fn() => creer_compte_lecteur(
				yume_tcc_donnees(
					array(
						'source'   => 'discord',
						'notifier' => false,
					)
				)
			)
		);
		yume_assert_true( is_int( $discord ), 'discord avec inscriptions ouvertes' );
	}
);

yume_test(
	'inscription en façade : compte créé par creer_compte_lecteur (identifiant généré, source inscription)',
	function () {
		$ip_avant               = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sauvegarde puis restauration telle quelle.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		$t                      = (string) ( time() - 30 );
		$pseudo                 = 'lecteur' . wp_rand( 10000, 99999 );
		$avant                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$_POST                  = array(
			'_yn_nonce'   => wp_create_nonce( 'yume_inscription' ),
			'yn_jeton'    => $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 20 ),
			'yn_retour'   => home_url( '/connexion/' ),
			'yn_pseudo'   => $pseudo,
			'yn_email'    => $pseudo . '@example.test',
			'yn_site_web' => '',
		);
		$sources                = array();
		$ecoute                 = static function ( $user_id, $source = '' ) use ( &$sources ) {
			$sources[] = $source;
		};
		$url                    = '';
		$filtre                 = static function ( $cible ) {
			throw new RuntimeException( (string) $cible, 302 );
		};
		add_action( 'yume_lecteur_inscrit', $ecoute, 10, 2 );
		add_filter( 'wp_redirect', $filtre, 1 );
		try {
			yume_tcc_destinataires(
				static function () use ( &$url ) {
					yume_tcc_inscriptions(
						true,
						static function () use ( &$url ) {
							try {
								\Yume\Core\Social\traiter_inscription();
							} catch ( RuntimeException $e ) {
								$url = $e->getMessage();
							}
						}
					);
				}
			);
		} finally {
			remove_filter( 'wp_redirect', $filtre, 1 );
			remove_action( 'yume_lecteur_inscrit', $ecoute, 10 );
			$_POST = $avant;
			if ( null === $ip_avant ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $ip_avant;
			}
		}
		yume_assert_contains( 'yn-msg=inscription-ok', $url );
		// Depuis le lot L6, l'identifiant est généré (tests/test-inscription.php).
		$user = get_user_by( 'email', $pseudo . '@example.test' );
		yume_assert_true( $user instanceof WP_User );
		yume_assert_same( 1, preg_match( '/^yn-[a-z0-9]{8}$/', $user->user_login ) );
		yume_assert_same( $pseudo, $user->display_name );
		yume_assert_same( array( 'inscription' ), $sources );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Page compte et formulaires visiteur
 * -----------------------------------------------------------------------------
 */

yume_test(
	'page compte : yume_compte_rubriques et yume_compte_sections_html insèrent une rubrique entre deux autres',
	function () {
		$u        = yume_factory_user();
		$rubrique = static function ( $rubriques, $user_id ) use ( $u ) {
			yume_assert_same( $u, $user_id );
			$sortie = array();
			foreach ( $rubriques as $ancre => $libelle ) {
				$sortie[ $ancre ] = $libelle;
				if ( 'yn-favoris' === $ancre ) {
					$sortie['yn-test-rubrique'] = 'Rubrique de test';
				}
			}
			return $sortie;
		};
		$section  = static function ( $sections, $user ) use ( $u ) {
			yume_assert_true( $user instanceof WP_User && $user->ID === $u );
			$sortie = array();
			foreach ( $sections as $ancre => $html ) {
				$sortie[ $ancre ] = $html;
				if ( 'yn-favoris' === $ancre ) {
					$sortie['yn-test-rubrique'] = \Yume\Core\Social\debut_section( 'yn-test-rubrique', 'Rubrique de test' ) . '<p>Contenu de test</p></section>';
				}
			}
			return $sortie;
		};
		add_filter( 'yume_compte_rubriques', $rubrique, 10, 2 );
		add_filter( 'yume_compte_sections_html', $section, 10, 2 );
		wp_set_current_user( $u );
		try {
			$html = yume_render_block( 'yume/account' );
		} finally {
			wp_set_current_user( 0 );
			remove_filter( 'yume_compte_rubriques', $rubrique, 10 );
			remove_filter( 'yume_compte_sections_html', $section, 10 );
		}
		$onglet_favoris = strpos( $html, 'href="#yn-favoris"' );
		$onglet_test    = strpos( $html, 'href="#yn-test-rubrique"' );
		$onglet_listes  = strpos( $html, 'href="#yn-listes"' );
		yume_assert_true( false !== $onglet_test && $onglet_favoris < $onglet_test && $onglet_test < $onglet_listes, 'onglet entre Favoris et Mes listes' );
		yume_assert_contains( 'data-yn-onglet="yn-test-rubrique"', $html );
		$favoris = strpos( $html, 'id="yn-favoris"' );
		$test    = strpos( $html, 'id="yn-test-rubrique"' );
		$listes  = strpos( $html, 'id="yn-listes"' );
		yume_assert_true( false !== $test && $favoris < $test && $test < $listes, 'section entre Favoris et Mes listes' );
		yume_assert_contains( 'data-yn-panneau="yn-test-rubrique"', $html );
		yume_assert_contains( '<p>Contenu de test</p>', $html );
		yume_assert_same( 11, substr_count( $html, '<h2 ' ), 'dix rubriques + celle du test' );
	}
);

yume_test(
	'page compte (visiteur) : HTML ajouté sous la connexion et sous l’inscription, ouverte ou fermée',
	function () {
		$serveur                = $_SERVER;
		$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' );
		$_SERVER['REQUEST_URI'] = '/connexion/';
		$recus                  = array();
		$connexion              = static function ( $html, $demandee ) use ( &$recus ) {
			$recus['connexion'] = $demandee;
			return $html . '<p data-yume-test="connexion">Discord</p>';
		};
		$inscription            = static function ( $html, $ici ) use ( &$recus ) {
			$recus['inscription'] = $ici;
			return $html . '<p data-yume-test="inscription">Discord</p>';
		};
		add_filter( 'yume_compte_html_connexion_extra', $connexion, 10, 2 );
		add_filter( 'yume_compte_html_inscription_extra', $inscription, 10, 2 );
		try {
			$ouverte = yume_tcc_inscriptions( true, static fn() => yume_render_block( 'yume/account' ) );
			$fermee  = yume_tcc_inscriptions( false, static fn() => yume_render_block( 'yume/account' ) );
		} finally {
			remove_filter( 'yume_compte_html_connexion_extra', $connexion, 10 );
			remove_filter( 'yume_compte_html_inscription_extra', $inscription, 10 );
			$_SERVER = $serveur; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$formulaire = strpos( $ouverte, 'name="yn_mot_de_passe"' );
		$extra      = strpos( $ouverte, 'data-yume-test="connexion"' );
		$oubli      = strpos( $ouverte, 'id="yn-oubli"' );
		yume_assert_true( false !== $formulaire && false !== $extra && $formulaire < $extra && $extra < $oubli, 'après le formulaire de connexion, avant « Mot de passe oublié »' );
		$bouton  = strpos( $ouverte, 'Créer mon compte' );
		$extra_i = strpos( $ouverte, 'data-yume-test="inscription"' );
		yume_assert_true( false !== $extra_i && $bouton < $extra_i, 'sous le formulaire d’inscription' );
		yume_assert_contains( 'data-yume-test="inscription"', $fermee, 'aussi sous « inscriptions fermées »' );
		yume_assert_true( strpos( $fermee, 'Les inscriptions sont fermées' ) < strpos( $fermee, 'data-yume-test="inscription"' ) );
		yume_assert_same( '', $recus['connexion'], 'aucune adresse de retour demandée' );
		yume_assert_contains( '/connexion/', $recus['inscription'] );

		$sans = yume_render_block( 'yume/account' );
		yume_assert_not_contains( 'data-yume-test', $sans, 'rien sans filtre' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * RGPD
 * -----------------------------------------------------------------------------
 */

yume_test(
	'RGPD : yume_donnees_personnelles enrichit l’export, yume_effacer_donnees est émise (effacement, suppression)',
	function () {
		$u      = yume_factory_user();
		$export = static function ( $donnees, $user_id ) {
			$donnees['module_test'] = array( 'user' => $user_id );
			return $donnees;
		};
		add_filter( 'yume_donnees_personnelles', $export, 10, 2 );
		try {
			$donnees = donnees_personnelles( $u );
			// Rappel de GET /moi/export appelé directement : une requête REST déclencherait
			// rest_api_init (réglage yume_reglages déclaré) pour les fichiers de test suivants.
			wp_set_current_user( $u );
			$rest = \Yume\Core\Social\rest_export()->get_data();
		} finally {
			wp_set_current_user( 0 );
			remove_filter( 'yume_donnees_personnelles', $export, 10 );
		}
		yume_assert_same( array( 'user' => $u ), $donnees['module_test'] );
		yume_assert_same( 'yume-export-1', $donnees['format'], 'clés existantes conservées' );
		yume_assert_same( array( 'user' => $u ), $rest['module_test'], 'GET /moi/export' );
		yume_assert_false( isset( donnees_personnelles( $u )['module_test'] ) );

		$effaces = array();
		$ecoute  = static function ( $user_id ) use ( &$effaces ) {
			$effaces[] = (int) $user_id;
		};
		add_action( 'yume_effacer_donnees', $ecoute );
		try {
			effacer_donnees( $u );
			\Yume\Core\Social\effacer_donnees_email( get_userdata( $u )->user_email );
			effacer_donnees( 0 );
			yume_assert_true( true === supprimer_compte( $u ) );
		} finally {
			remove_action( 'yume_effacer_donnees', $ecoute );
		}
		yume_assert_same( array( $u, $u, $u ), $effaces, 'effacement direct, effaceur WordPress, suppression du compte (jamais pour 0)' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Réglages : type « secret »
 * -----------------------------------------------------------------------------
 */

yume_test(
	'réglage secret : jamais réaffiché, vide = valeur conservée, verrouillé sans valeur',
	function () {
		$champ  = yume_tcc_champ_secret();
		$verrou = yume_tcc_champ_secret( array( 'verrouille' => 'Défini par la constante YUME_TEST_SECRET (wp-config.php).' ) );
		yume_assert_same( 'secret', $champ['type'] ?? '', 'type reconnu par champs_reglages()' );
		yume_assert_same( 'ancien', \Yume\Core\Core\assainir_champ( $champ, '', 'ancien' ), 'vide : conservé' );
		yume_assert_same( 'ancien', \Yume\Core\Core\assainir_champ( $champ, '   ', 'ancien' ), 'espaces : conservé' );
		yume_assert_same( 'ancien', \Yume\Core\Core\assainir_champ( $champ, array( 'x' ), 'ancien' ), 'non scalaire : conservé' );
		yume_assert_same( 'nouveau-secret', \Yume\Core\Core\assainir_champ( $champ, ' nouveau-secret ', 'ancien' ) );

		$avant = get_option( 'yume_reglages' );
		$ajout = static function ( $champs ) {
			$champs[] = array(
				'key'     => 'yume_test_secret',
				'label'   => 'Secret de test',
				'type'    => 'secret',
				'section' => 'site',
			);
			return $champs;
		};
		add_filter( 'yume_reglages_champs', $ajout );
		try {
			$valeurs                     = is_array( $avant ) ? $avant : array();
			$valeurs['yume_test_secret'] = 'abc-123-secret';
			update_option( 'yume_reglages', $valeurs );
			yume_assert_same( 'abc-123-secret', yume_setting( 'yume_test_secret' ) );
			$garde = \Yume\Core\Core\assainir_reglages(
				array(
					'_formulaire'      => '1',
					'yume_test_secret' => '',
				)
			);
			yume_assert_same( 'abc-123-secret', $garde['yume_test_secret'], 'formulaire avec champ vide' );
			$change = \Yume\Core\Core\assainir_reglages(
				array(
					'_formulaire'      => '1',
					'yume_test_secret' => 'autre-secret',
				)
			);
			yume_assert_same( 'autre-secret', $change['yume_test_secret'] );

			$html = yume_tcc_champ_admin( $champ );
			yume_assert_contains( 'type="password"', $html );
			yume_assert_contains( 'value=""', $html );
			yume_assert_contains( 'autocomplete="new-password"', $html );
			yume_assert_contains( 'name="yume_reglages[yume_test_secret]"', $html );
			yume_assert_not_contains( 'abc-123-secret', $html, 'page Yume → Réglages' );
			yume_assert_contains( 'Laisser vide pour conserver la valeur enregistrée', $html );
			yume_assert_contains( 'Une valeur est enregistrée', $html );

			$equipe = \Yume\Core\Planning\champ_reglage_equipe( $champ );
			yume_assert_contains( 'type="password"', $equipe );
			yume_assert_not_contains( 'abc-123-secret', $equipe, 'vue Réglages de l’espace équipe' );

			$html = yume_tcc_champ_admin( $verrou );
			yume_assert_contains( 'disabled', $html );
			yume_assert_contains( 'Défini par la constante YUME_TEST_SECRET', $html );
			yume_assert_not_contains( 'abc-123-secret', $html, 'verrouillé : valeur jamais montrée' );
			yume_assert_not_contains( 'name=', $html, 'verrouillé : jamais envoyé' );
			yume_assert_not_contains( 'abc-123-secret', \Yume\Core\Planning\champ_reglage_equipe( $verrou ) );
		} finally {
			remove_filter( 'yume_reglages_champs', $ajout );
			update_option( 'yume_reglages', $avant );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Santé du site et modules
 * -----------------------------------------------------------------------------
 */

yume_test(
	'Santé du site : test yume_inscriptions (recommandé si les inscriptions sont fermées)',
	function () {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );
		yume_assert_true( isset( $tests['direct']['yume_inscriptions'] ) && is_callable( $tests['direct']['yume_inscriptions']['test'] ) );
		$fermees = yume_tcc_inscriptions( false, static fn() => \Yume\Core\Core\test_sante_inscriptions() );
		yume_assert_same( 'recommended', $fermees['status'] );
		yume_assert_same( 'yume_inscriptions', $fermees['test'] );
		yume_assert_contains( 'Réglages → Général', $fermees['description'] );
		yume_assert_same( 'good', yume_tcc_inscriptions( true, static fn() => \Yume\Core\Core\test_sante_inscriptions() )['status'] );
		$force = static fn() => false;
		add_filter( 'yume_inscriptions_ouvertes', $force );
		try {
			yume_assert_same( 'recommended', yume_tcc_inscriptions( true, static fn() => \Yume\Core\Core\test_sante_inscriptions() )['status'], 'suit le filtre yume_inscriptions_ouvertes' );
		} finally {
			remove_filter( 'yume_inscriptions_ouvertes', $force );
		}
	}
);

yume_test(
	'modules corrections, discord, recommandations, rattrapage déclarés après statistiques',
	function () {
		$modules = YUME_CORE_MODULES;
		$rang    = array_search( 'statistiques', $modules, true );
		yume_assert_same( array( 'corrections', 'discord', 'recommandations', 'rattrapage' ), array_slice( $modules, $rang + 1, 4 ) );
		foreach ( array( 'corrections', 'discord', 'recommandations', 'rattrapage' ) as $module ) {
			yume_assert_true( is_readable( YUME_CORE_DIR . 'includes/' . $module . '/module.php' ), $module );
		}
	}
);
