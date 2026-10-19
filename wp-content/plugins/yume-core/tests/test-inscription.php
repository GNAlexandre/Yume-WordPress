<?php
/**
 * Tests du lot L6 du chantier « lecteurs » : pseudo public distinct de l'identifiant de
 * connexion. Inscription en façade (pseudo public saisi, identifiant généré, connexion par
 * l'adresse e-mail, règles de valider_pseudo(), filtres registration_errors des extensions
 * anti-spam), formulaires visiteur, avis « pseudo = identifiant » des comptes existants,
 * « Profil et sécurité » et retrait de la méta _yume_compte_sans_mdp quand un mot de passe est
 * défini.
 *
 * Lancement : tools/localenv/test.sh inscription
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Social\avis_pseudo;
use function Yume\Core\Social\compte_sans_mot_de_passe;
use function Yume\Core\Social\creer_compte_lecteur;
use function Yume\Core\Social\identifiant_genere;
use function Yume\Core\Social\messages;
use function Yume\Core\Social\pseudo_est_identifiant;
use function Yume\Core\Social\section_profil;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tins_)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute un gestionnaire de formulaire avec $champs en $_POST et renvoie l'URL de redirection
 * demandée (chaîne vide si aucune).
 *
 * @param array    $champs Champs POST.
 * @param callable $rappel Gestionnaire.
 * @throws RuntimeException Toute exception autre que la redirection interceptée.
 */
function yume_tins_formulaire( array $champs, callable $rappel ): string {
	$avant  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$_POST  = wp_slash( $champs );
	$filtre = static function ( $url ) {
		throw new RuntimeException( (string) $url, 302 );
	};
	add_filter( 'wp_redirect', $filtre, 1 );
	try {
		$rappel();
	} catch ( RuntimeException $e ) {
		if ( 302 !== $e->getCode() ) {
			throw $e;
		}
		return $e->getMessage();
	} finally {
		remove_filter( 'wp_redirect', $filtre, 1 );
		$_POST = $avant;
	}
	return '';
}

/**
 * Inscription en façade (inscriptions ouvertes, adresse IP neuve, jeton valide) ; renvoie
 * l'URL de redirection.
 *
 * @param array  $champs Champs remplacés (yn_pseudo, yn_email…).
 * @param string $ip     Adresse IP de la requête ('' : tirée au hasard).
 */
function yume_tins_inscrire( array $champs, string $ip = '' ): string {
	$t        = (string) ( time() - 30 );
	$base     = array(
		'action'      => 'yume_inscription',
		'_yn_nonce'   => wp_create_nonce( 'yume_inscription' ),
		'yn_jeton'    => $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 20 ),
		'yn_retour'   => home_url( '/connexion/' ),
		'yn_pseudo'   => 'Lectrice ' . wp_rand( 100000, 999999 ),
		'yn_email'    => 'inscrite' . wp_rand( 100000, 999999 ) . '@example.test',
		'yn_site_web' => '',
	);
	$serveur  = $_SERVER;
	$ouvertes = get_option( 'users_can_register' );
	update_option( 'users_can_register', 1 );
	$_SERVER['REMOTE_ADDR'] = '' !== $ip ? $ip : '192.0.2.' . wp_rand( 1, 254 );
	try {
		return yume_tins_formulaire( array_merge( $base, $champs ), 'Yume\Core\Social\traiter_inscription' );
	} finally {
		$_SERVER = $serveur; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( 'users_can_register', $ouvertes );
	}
}

/**
 * Formulaire « Profil et sécurité » du membre connecté ; renvoie l'URL de redirection.
 *
 * @param array $champs Champs remplacés.
 */
function yume_tins_profil( array $champs ): string {
	$base = array(
		'_yn_nonce' => wp_create_nonce( 'yume_compte_profil' ),
		'yn_retour' => home_url( '/compte/' ),
		'yn_email'  => wp_get_current_user()->user_email,
	);
	return yume_tins_formulaire( array_merge( $base, $champs ), 'Yume\Core\Social\traiter_profil' );
}

/**
 * Lecteur créé comme avant 2.5.0 (pseudo = identifiant de connexion).
 */
function yume_tins_ancien_compte(): WP_User {
	$login = 'sakura_' . wp_rand( 100000, 999999 );
	$id    = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => 'secret-123',
			'user_email'   => $login . '@example.test',
			'display_name' => $login,
			'nickname'     => $login,
			'role'         => 'subscriber',
		)
	);
	return get_userdata( (int) $id );
}

/**
 * Lecteur créé par creer_compte_lecteur() (identifiant généré).
 *
 * @param array $autres Données remplacées.
 * @throws Yume_Test_Failure Création refusée.
 */
function yume_tins_nouveau_compte( array $autres = array() ): WP_User {
	$n  = wp_rand( 100000, 999999 );
	$id = creer_compte_lecteur(
		array_merge(
			array(
				'pseudo'   => 'Mio des Brumes ' . $n,
				'email'    => 'mio' . $n . '@example.test',
				'source'   => 'equipe',
				'notifier' => false,
			),
			$autres
		)
	);
	if ( is_wp_error( $id ) ) {
		throw new Yume_Test_Failure( 'creer_compte_lecteur : ' . $id->get_error_message() );
	}
	return get_userdata( $id );
}

/*
 * -----------------------------------------------------------------------------
 * Inscription
 * -----------------------------------------------------------------------------
 */

yume_test(
	'inscription : pseudo public saisi (espaces, accents), identifiant généré, connexion par e-mail et par identifiant',
	function () {
		$pseudo = 'Mio des Brumes é ' . wp_rand( 1000, 9999 );
		$email  = 'mio' . wp_rand( 100000, 999999 ) . '@example.test';
		$url    = yume_tins_inscrire(
			array(
				'yn_pseudo' => '  ' . str_replace( ' ', '  ', $pseudo ) . ' ',
				'yn_email'  => $email,
			)
		);
		yume_assert_contains( 'yn-msg=inscription-ok', $url );
		yume_assert_contains( '#yn-connexion', $url );
		$user = get_user_by( 'email', $email );
		yume_assert_true( $user instanceof WP_User );
		yume_assert_same( 1, preg_match( '/^yn-[a-z0-9]{8}$/', $user->user_login ), 'identifiant généré' );
		yume_assert_true( identifiant_genere( $user->user_login ) );
		yume_assert_same( $pseudo, $user->display_name, 'espaces réduites, accents gardés' );
		yume_assert_same( $pseudo, get_user_meta( $user->ID, 'nickname', true ) );
		yume_assert_false( pseudo_est_identifiant( $user ) );
		yume_assert_same( array( 'subscriber' ), array_values( $user->roles ) );

		// Connexion : l'adresse e-mail (cas courant) et l'identifiant généré sont acceptés.
		wp_set_password( 'mot-de-passe-1', $user->ID );
		$par_email = wp_authenticate( $email, 'mot-de-passe-1' );
		yume_assert_true( $par_email instanceof WP_User && $par_email->ID === $user->ID, 'connexion par e-mail' );
		$par_login = wp_authenticate( $user->user_login, 'mot-de-passe-1' );
		yume_assert_true( $par_login instanceof WP_User && $par_login->ID === $user->ID, 'connexion par identifiant' );
		yume_assert_true( is_wp_error( wp_authenticate( $pseudo, 'mot-de-passe-1' ) ), 'le pseudo n’est pas un identifiant' );
	}
);

yume_test(
	'inscription : pseudos refusés (réservé, adresse e-mail, identifiant ou pseudo d’un autre compte, balise, longueur)',
	function () {
		$ancien = yume_tins_ancien_compte();
		$autre  = yume_tins_nouveau_compte();
		$cas    = array(
			'Admin'                               => 'pseudo-reserve',
			'Yume Novel'                          => 'pseudo-reserve',
			'yume.novel'                          => 'pseudo-reserve',
			'Équipe'                              => 'pseudo-reserve',
			'mio@exemple.fr'                      => 'pseudo-email',
			'Mio @ Brumes'                        => 'pseudo-email',
			strtoupper( $ancien->user_login )     => 'pseudo-pris',
			$autre->user_login                    => 'pseudo-pris',
			mb_strtolower( $autre->display_name ) => 'pseudo-pris',
			'<b>Mio</b>'                          => 'pseudo-invalide',
			'Mio <3'                              => 'pseudo-invalide',
			'M'                                   => 'pseudo-invalide',
			str_repeat( 'a', 41 )                 => 'pseudo-invalide',
			'   '                                 => 'pseudo-invalide',
		);
		foreach ( $cas as $pseudo => $code ) {
			$email = 'refus' . wp_rand( 100000, 999999 ) . '@example.test';
			$url   = yume_tins_inscrire(
				array(
					'yn_pseudo' => (string) $pseudo,
					'yn_email'  => $email,
				)
			);
			yume_assert_contains( 'yn-msg=' . $code, $url, 'pseudo « ' . $pseudo . ' »' );
			yume_assert_contains( '#yn-inscription', $url );
			yume_assert_false( get_user_by( 'email', $email ), 'aucun compte pour « ' . $pseudo . ' »' );
		}
		$messages = messages();
		foreach ( array( 'pseudo-reserve', 'pseudo-email', 'pseudo-invalide', 'pseudo-pris' ) as $code ) {
			yume_assert_true( isset( $messages[ $code ] ) && 'erreur' === $messages[ $code ][0], 'message ' . $code );
		}
	}
);

yume_test(
	'inscription : un filtre registration_errors qui ajoute une erreur bloque l’inscription (message générique)',
	function () {
		$recus  = array();
		$refus  = static function ( $erreurs, $identifiant = '', $email = '' ) use ( &$recus ) {
			$recus[] = array( $identifiant, $email );
			if ( $erreurs instanceof WP_Error && str_starts_with( (string) $email, 'spam' ) ) {
				$erreurs->add( 'antispam', 'Refusé par l’anti-spam.' );
			}
			return $erreurs;
		};
		$avant  = array();
		$poste  = static function ( $identifiant, $email, $erreurs ) use ( &$avant ) {
			$avant[] = array( $identifiant, $email, $erreurs instanceof WP_Error );
		};
		$crees  = 0;
		$compte = static function () use ( &$crees ) {
			++$crees;
		};
		add_filter( 'registration_errors', $refus, 10, 3 );
		add_action( 'register_post', $poste, 10, 3 );
		add_action( 'user_register', $compte );
		try {
			$spam    = 'spam' . wp_rand( 100000, 999999 ) . '@example.test';
			$url     = yume_tins_inscrire( array( 'yn_email' => $spam ) );
			$normal  = 'humain' . wp_rand( 100000, 999999 ) . '@example.test';
			$url_ok  = yume_tins_inscrire( array( 'yn_email' => $normal ) );
			$sans_wp = static fn() => 'pas une erreur';
			add_filter( 'registration_errors', $sans_wp, 20 );
			$bizarre = 'bizarre' . wp_rand( 100000, 999999 ) . '@example.test';
			$url_biz = yume_tins_inscrire( array( 'yn_email' => $bizarre ) );
			remove_filter( 'registration_errors', $sans_wp, 20 );
		} finally {
			remove_filter( 'registration_errors', $refus, 10 );
			remove_action( 'register_post', $poste, 10 );
			remove_action( 'user_register', $compte );
		}
		yume_assert_contains( 'yn-msg=inscription-refusee', $url );
		yume_assert_contains( '#yn-inscription', $url );
		yume_assert_not_contains( 'anti-spam', rawurldecode( $url ), 'message générique' );
		yume_assert_false( get_user_by( 'email', $spam ), 'aucun compte créé' );
		yume_assert_same( 1, preg_match( '/^yn-[a-z0-9]{8}$/', $recus[0][0] ), 'identifiant généré passé au filtre' );
		yume_assert_same( $spam, $recus[0][1], 'adresse passée au filtre' );
		yume_assert_same( array( $recus[0][0], $spam, true ), $avant[0], 'register_post émise avant, avec les mêmes arguments' );

		yume_assert_contains( 'yn-msg=inscription-ok', $url_ok, 'aucune erreur ajoutée : inscription acceptée' );
		$user = get_user_by( 'email', $normal );
		yume_assert_true( $user instanceof WP_User );
		yume_assert_same( $recus[1][0], $user->user_login, 'l’identifiant vu par le filtre est celui du compte' );
		yume_assert_contains( 'yn-msg=inscription-ok', $url_biz, 'valeur de filtre qui n’est pas une WP_Error : ignorée' );
		yume_assert_same( 2, $crees, 'deux comptes créés en tout' );
	}
);

yume_test(
	'inscription : ni register_new_user ni e-mail en double, tentative comptée une seule fois par adresse IP',
	function () {
		$nouvel = 0;
		$ecoute = static function () use ( &$nouvel ) {
			++$nouvel;
		};
		$envois = array();
		$mail   = static function ( $retour, $atts ) use ( &$envois ) {
			foreach ( (array) $atts['to'] as $destinataire ) {
				$envois[] = (string) $destinataire;
			}
			return true;
		};
		$email  = 'unique' . wp_rand( 100000, 999999 ) . '@example.test';
		$ip     = '198.51.100.' . wp_rand( 1, 254 );
		add_action( 'register_new_user', $ecoute );
		add_filter( 'pre_wp_mail', $mail, 5, 2 );
		try {
			$url = yume_tins_inscrire( array( 'yn_email' => $email ), $ip );
		} finally {
			remove_action( 'register_new_user', $ecoute );
			remove_filter( 'pre_wp_mail', $mail, 5 );
		}
		yume_assert_contains( 'yn-msg=inscription-ok', $url );
		yume_assert_same( 0, $nouvel, 'action register_new_user non émise' );
		yume_assert_same( array( $email ), array_values( array_intersect( $envois, array( $email ) ) ), 'un seul e-mail au nouveau lecteur' );
		$cle  = 'yume_lim_inscription_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
		$etat = get_transient( $cle );
		yume_assert_same( 1, is_array( $etat ) ? (int) $etat['n'] : 0, 'erreurs_inscription() ne recompte pas la tentative' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Formulaires visiteur
 * -----------------------------------------------------------------------------
 */

yume_test(
	'formulaires visiteur : « Pseudo public » et adresse e-mail, connexion par « Adresse e-mail ou identifiant »',
	function () {
		$ouvertes = get_option( 'users_can_register' );
		update_option( 'users_can_register', 1 );
		try {
			$html = \Yume\Core\Social\compte_visiteur();
		} finally {
			update_option( 'users_can_register', $ouvertes );
		}
		yume_assert_contains( '<label for="yn-inscription-pseudo">Pseudo public</label>', $html );
		yume_assert_contains( 'name="yn_pseudo" required minlength="2" maxlength="40" autocomplete="nickname"', $html );
		yume_assert_contains( 'Affiché avec vos commentaires. 2 à 40 caractères, pas d’adresse e-mail.', $html );
		yume_assert_contains( '<label for="yn-inscription-email">Adresse e-mail</label>', $html );
		yume_assert_contains( 'Vous vous connecterez avec votre adresse e-mail. Votre pseudo est le nom affiché avec vos commentaires', $html );
		yume_assert_not_contains( 'autocomplete="username" aria-describedby="yn-inscription-pseudo-aide"', $html, 'le pseudo n’est plus l’identifiant' );
		yume_assert_contains( '<label for="yn-identifiant">Adresse e-mail ou identifiant</label>', $html );
		yume_assert_contains( '<label for="yn-oubli-identifiant">Adresse e-mail ou identifiant</label>', $html );
		yume_assert_contains( 'Compte créé avant la version 2.5 : votre identifiant est le pseudo choisi à l’inscription.', $html );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Comptes existants : avis et « Profil et sécurité »
 * -----------------------------------------------------------------------------
 */

yume_test(
	'avis « pseudo = identifiant » : seulement pour un ancien compte, fermé et mémorisé',
	function () {
		$ancien  = yume_tins_ancien_compte();
		$nouveau = yume_tins_nouveau_compte();
		$renomme = yume_tins_ancien_compte();
		wp_update_user(
			array(
				'ID'           => $renomme->ID,
				'display_name' => 'Kenji des Brumes',
			)
		);
		$renomme = get_userdata( $renomme->ID );

		yume_assert_true( pseudo_est_identifiant( $ancien ) );
		yume_assert_contains( 'id="yn-avis-pseudo"', avis_pseudo( $ancien ) );
		yume_assert_contains( 'name="action" value="yume_compte_avis"', avis_pseudo( $ancien ) );
		yume_assert_contains( 'href="#yn-profil"', avis_pseudo( $ancien ) );
		yume_assert_same( '', avis_pseudo( $nouveau ), 'identifiant généré : aucun avis' );
		yume_assert_same( '', avis_pseudo( $renomme ), 'pseudo déjà différent : aucun avis' );
		$casse = yume_tins_ancien_compte();
		wp_update_user(
			array(
				'ID'           => $casse->ID,
				'display_name' => strtoupper( $casse->user_login ),
			)
		);
		yume_assert_true( pseudo_est_identifiant( get_userdata( $casse->ID ) ), 'casse ignorée' );

		// Page compte : l'avis est en tête, avant les rubriques.
		wp_set_current_user( $ancien->ID );
		$page = \Yume\Core\Social\compte_connecte();
		yume_assert_contains( 'id="yn-avis-pseudo"', $page );
		yume_assert_true( strpos( $page, 'id="yn-avis-pseudo"' ) < strpos( $page, 'yn-account__grille' ), 'en tête de page' );

		// Fermeture : nonce exigé, puis mémorisée.
		$refus = yume_tins_formulaire(
			array(
				'_yn_nonce' => 'faux',
				'yn_retour' => home_url( '/compte/' ),
			),
			'Yume\Core\Social\traiter_avis_pseudo'
		);
		yume_assert_contains( 'yn-msg=session', $refus );
		yume_assert_same( '', (string) get_user_meta( $ancien->ID, 'yume_avis_pseudo_vu', true ) );
		$url = yume_tins_formulaire(
			array(
				'_yn_nonce' => wp_create_nonce( 'yume_compte_avis' ),
				'yn_retour' => home_url( '/compte/' ),
			),
			'Yume\Core\Social\traiter_avis_pseudo'
		);
		yume_assert_contains( '/compte/', $url );
		yume_assert_not_contains( 'yn-msg', $url );
		yume_assert_true( (int) get_user_meta( $ancien->ID, 'yume_avis_pseudo_vu', true ) > 0, 'fermeture mémorisée' );
		yume_assert_same( '', avis_pseudo( get_userdata( $ancien->ID ) ) );
		yume_assert_not_contains( 'id="yn-avis-pseudo"', \Yume\Core\Social\compte_connecte() );
		// Rien d'automatique : le pseudo et l'identifiant sont inchangés.
		yume_assert_same( $ancien->user_login, get_userdata( $ancien->ID )->display_name );

		// Visiteur : formulaire réservé aux membres.
		wp_set_current_user( 0 );
		$visiteur = yume_tins_formulaire( array(), 'Yume\Core\Social\traiter_avis_pseudo' );
		yume_assert_not_contains( 'admin-post.php', $visiteur );
		yume_assert_true( '' !== $visiteur, 'renvoyé vers la connexion' );
	}
);

yume_test(
	'profil et sécurité : identifiant affiché seulement s’il a été choisi, encart pour un ancien compte',
	function () {
		$ancien = yume_tins_ancien_compte();
		$html   = section_profil( $ancien );
		yume_assert_contains( '<label for="yn-pseudo">Pseudo public</label>', $html );
		yume_assert_contains( 'Identifiant de connexion : ' . $ancien->user_login . ' (non modifiable).', $html );
		yume_assert_contains( 'id="yn-profil-conseil"', $html );
		yume_assert_contains( '« ' . $ancien->user_login . ' » ou votre adresse e-mail', $html );

		$nouveau = yume_tins_nouveau_compte();
		$html    = section_profil( $nouveau );
		yume_assert_contains( 'Affiché avec vos commentaires. Vous vous connectez avec votre adresse e-mail.', $html );
		yume_assert_not_contains( 'Identifiant de connexion', $html, 'identifiant généré jamais affiché' );
		yume_assert_not_contains( $nouveau->user_login, $html );
		yume_assert_not_contains( 'id="yn-profil-conseil"', $html );

		$renomme = yume_tins_ancien_compte();
		wp_update_user(
			array(
				'ID'           => $renomme->ID,
				'display_name' => 'Kenji des Brumes',
			)
		);
		$html = section_profil( get_userdata( $renomme->ID ) );
		yume_assert_contains( 'Identifiant de connexion : ' . $renomme->user_login, $html, 'identifiant choisi : rappelé' );
		yume_assert_not_contains( 'id="yn-profil-conseil"', $html );
	}
);

yume_test(
	'profil et sécurité : changement de pseudo soumis à valider_pseudo(), puis l’avis disparaît',
	function () {
		$ancien = yume_tins_ancien_compte();
		$autre  = yume_tins_ancien_compte();
		wp_set_current_user( $ancien->ID );
		yume_assert_contains( 'yn-msg=pseudo-reserve', yume_tins_profil( array( 'yn_pseudo' => 'Administrateur' ) ) );
		yume_assert_contains( 'yn-msg=pseudo-email', yume_tins_profil( array( 'yn_pseudo' => 'sakura@exemple.fr' ) ) );
		yume_assert_contains( 'yn-msg=pseudo-pris', yume_tins_profil( array( 'yn_pseudo' => strtoupper( $autre->user_login ) ) ) );
		yume_assert_contains( 'yn-msg=pseudo-invalide', yume_tins_profil( array( 'yn_pseudo' => '<i>Sakura</i>' ) ) );
		yume_assert_same( $ancien->user_login, get_userdata( $ancien->ID )->display_name, 'pseudo inchangé après les refus' );

		// Sa propre casse reste permise (pseudo_pris() exclut le compte lui-même).
		$url = yume_tins_profil( array( 'yn_pseudo' => strtoupper( $ancien->user_login ) ) );
		yume_assert_contains( 'pseudo-ok', $url );
		$url = yume_tins_profil( array( 'yn_pseudo' => 'Sakura  des Brumes' ) );
		yume_assert_contains( 'pseudo-ok', $url );
		$compte = get_userdata( $ancien->ID );
		yume_assert_same( 'Sakura des Brumes', $compte->display_name );
		yume_assert_same( $ancien->user_login, $compte->user_login, 'identifiant inchangé' );
		yume_assert_same( '', avis_pseudo( $compte ), 'avis retiré' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Méta _yume_compte_sans_mdp
 * -----------------------------------------------------------------------------
 */

yume_test(
	'_yume_compte_sans_mdp : gardée à la création, retirée quand un mot de passe est défini',
	function () {
		$meta = array( \Yume\Core\Social\META_COMPTE_SANS_MDP => 1 );

		$a = yume_tins_nouveau_compte( array( 'meta' => $meta ) );
		yume_assert_true( compte_sans_mot_de_passe( $a->ID ), 'posée à la création (wp_set_password émise avant les métas)' );
		wp_update_user(
			array(
				'ID'           => $a->ID,
				'display_name' => 'Autre pseudo ' . wp_rand( 1000, 9999 ),
			)
		);
		yume_assert_true( compte_sans_mot_de_passe( $a->ID ), 'profil modifié sans mot de passe : gardée' );
		wp_set_password( 'mot-de-passe-1', $a->ID );
		yume_assert_false( compte_sans_mot_de_passe( $a->ID ), 'wp_set_password()' );

		$b = yume_tins_nouveau_compte( array( 'meta' => $meta ) );
		wp_update_user(
			array(
				'ID'        => $b->ID,
				'user_pass' => 'mot-de-passe-2',
			)
		);
		yume_assert_false( compte_sans_mot_de_passe( $b->ID ), 'wp_update_user() avec un mot de passe' );

		$c = yume_tins_nouveau_compte( array( 'meta' => $meta ) );
		add_filter( 'send_password_change_email', '__return_false' );
		try {
			reset_password( $c, 'mot-de-passe-3' );
		} finally {
			remove_filter( 'send_password_change_email', '__return_false' );
		}
		yume_assert_false( compte_sans_mot_de_passe( $c->ID ), 'réinitialisation (Mot de passe oublié)' );

		$d = yume_tins_nouveau_compte( array( 'meta' => $meta ) );
		\Yume\Core\Social\mot_de_passe_reinitialise( $d );
		yume_assert_false( compte_sans_mot_de_passe( $d->ID ), 'after_password_reset' );
		\Yume\Core\Social\profil_mot_de_passe( $d->ID, 'pas un compte' );
		\Yume\Core\Social\mot_de_passe_defini( 'x', 0 );
	}
);
