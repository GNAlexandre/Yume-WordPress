<?php
/**
 * Matrice de sécurité REST (audit AMEL-14, lot P2-E) : chaque route yume/v1 est appelée pour
 * chaque méthode déclarée, en anonyme et avec chaque rôle, et le résultat (autorisé / refusé)
 * est comparé à la table d'attentes yume_tsrest_attentes().
 *
 * Toute route yume/v1 absente de la table fait échouer le test : une nouvelle route doit
 * recevoir une attente de sécurité explicite, relue en revue (docs/guide-developpeur.md,
 * « Matrice de sécurité REST »). Vérifie aussi les routes sensibles du cœur (/wp/v2/users,
 * /settings, /plugins, commentaires anonymes) et l'absence des métas privées des tomes en
 * contexte « view » anonyme.
 *
 * Les rappels des routes ne sont JAMAIS exécutés : le filtre rest_dispatch_request renvoie un
 * marqueur dès que la permission est accordée (DELETE /moi ne supprime aucun compte). Les
 * erreurs de validation des paramètres (levées par WordPress avant le contrôle de permission)
 * sont neutralisées pour que la permission soit toujours évaluée.
 *
 * Lancement : tools/localenv/test.sh securite-rest
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Table d'attentes (à tenir à jour à chaque nouvelle route yume/v1)
 * -----------------------------------------------------------------------------
 */

if ( ! function_exists( 'yume_tsrest_attentes' ) ) {

	/**
	 * Profils testés : clé courte → rôle WordPress (chaîne vide : visiteur anonyme).
	 *
	 * @return array<string,string>
	 */
	function yume_tsrest_profils(): array {
		return array(
			'anonyme'        => '',
			'lecteur'        => 'subscriber',
			'traducteur'     => 'yume_traducteur',
			'editeur'        => 'yume_editeur',
			'gerant'         => 'yume_gerant',
			'administrateur' => 'administrator',
		);
	}

	/**
	 * Attentes de sécurité des routes yume/v1 : « MÉTHODE /route » (motif exact tel que déclaré
	 * par register_rest_route) → profils autorisés, ou « publique » (tout le monde, y compris
	 * anonyme ; seules ces routes peuvent utiliser __return_true), ou « connectes » (tout
	 * profil connecté).
	 *
	 * Les routes à paramètre sont appelées sur un tome en brouillon SANS responsable, une œuvre
	 * publiée et un chapitre en brouillon (voir yume_tsrest_chemin()) : « traducteur » n'y est
	 * donc pas autorisé à modifier le planning.
	 *
	 * @return array<string,string|string[]>
	 */
	function yume_tsrest_attentes(): array {
		$equipe_planning = array( 'editeur', 'gerant', 'administrateur' );
		return array(
			// Index de l'espace de noms (généré par WordPress).
			'GET /yume/v1'                                => 'publique',

			// Publication (includes/publication/class-rest.php) : capacité yume_publier.
			'POST /yume/v1/publications/analyse'          => $equipe_planning,
			'POST /yume/v1/publications'                  => $equipe_planning,
			'POST /yume/v1/publications/(?P<id>\d+)/publier' => $equipe_planning,
			'DELETE /yume/v1/publications/(?P<id>\d+)/remplacement' => $equipe_planning,

			// Planning (includes/planning/rest.php).
			'GET /yume/v1/planning'                       => 'publique',
			'GET /yume/v1/planning/journal'               => 'publique',
			'GET /yume/v1/planning\.ics'                  => 'publique',
			'POST /yume/v1/commentaires/(?P<id>\d+)/signalement' => 'connectes',
			'POST /yume/v1/planning/tomes'                => $equipe_planning,
			'DELETE /yume/v1/tomes/(?P<id>\d+)/planning'  => $equipe_planning,
			'PATCH /yume/v1/tomes/(?P<id>\d+)/planning'   => $equipe_planning,

			// Lecteur (includes/reader) : réglages et progression du compte connecté.
			'GET /yume/v1/moi/reglages'                   => 'connectes',
			'POST /yume/v1/moi/reglages'                  => 'connectes',
			'PUT /yume/v1/moi/reglages'                   => 'connectes',
			'PATCH /yume/v1/moi/reglages'                 => 'connectes',
			'GET /yume/v1/moi/progression'                => 'connectes',
			'POST /yume/v1/moi/progression'               => 'connectes',
			'PUT /yume/v1/moi/progression'                => 'connectes',
			'PATCH /yume/v1/moi/progression'              => 'connectes',

			// Social (includes/social) : compte, favoris, notes, alertes, export RGPD.
			'GET /yume/v1/moi'                            => 'connectes',
			'DELETE /yume/v1/moi'                         => 'connectes',
			'POST /yume/v1/moi/favoris/(?P<oeuvre>\d+)'   => 'connectes',
			'DELETE /yume/v1/moi/favoris/(?P<oeuvre>\d+)' => 'connectes',
			'POST /yume/v1/moi/notes/(?P<oeuvre>\d+)'     => 'connectes',
			'PUT /yume/v1/moi/notes/(?P<oeuvre>\d+)'      => 'connectes',
			'PATCH /yume/v1/moi/notes/(?P<oeuvre>\d+)'    => 'connectes',
			'POST /yume/v1/moi/alertes/(?P<oeuvre>\d+)'   => 'connectes',
			'PUT /yume/v1/moi/alertes/(?P<oeuvre>\d+)'    => 'connectes',
			'PATCH /yume/v1/moi/alertes/(?P<oeuvre>\d+)'  => 'connectes',
			'GET /yume/v1/moi/export'                     => 'connectes',

			// Listes de lecture, notifications du lecteur et Web Push (lot P3-D, includes/social).
			'GET /yume/v1/moi/listes'                     => 'connectes',
			'POST /yume/v1/moi/listes'                    => 'connectes',
			'PATCH /yume/v1/moi/listes/(?P<id>\d+)'       => 'connectes',
			'DELETE /yume/v1/moi/listes/(?P<id>\d+)'      => 'connectes',
			'PUT /yume/v1/moi/listes/(?P<id>\d+)/oeuvres/(?P<oeuvre>\d+)' => 'connectes',
			'DELETE /yume/v1/moi/listes/(?P<id>\d+)/oeuvres/(?P<oeuvre>\d+)' => 'connectes',
			'GET /yume/v1/moi/notifications'              => 'connectes',
			'POST /yume/v1/moi/notifications/lues'        => 'connectes',
			'POST /yume/v1/moi/push'                      => 'connectes',
			'DELETE /yume/v1/moi/push'                    => 'connectes',
			'GET /yume/v1/push/cle'                       => 'publique',

			// Migration (includes/migration) : administrateur seulement (manage_options).
			'GET /yume/v1/migration'                      => array( 'administrateur' ),
			'POST /yume/v1/migration/executer'            => array( 'administrateur' ),
			'POST /yume/v1/migration/annuler'             => array( 'administrateur' ),
			'GET /yume/v1/suggestions'                    => 'publique', // Recherche (includes/library/recherche-rest.php).

			// Glossaire (includes/glossaire/rest.php) : capacité yume_glossaire.
			'GET /yume/v1/oeuvres/(?P<oeuvre>[\w-]+)/glossaire' => $equipe_planning,
			'POST /yume/v1/oeuvres/(?P<oeuvre>[\w-]+)/glossaire' => $equipe_planning,
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Aides propres à ces tests (préfixe yume_tsrest_)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Contenus et comptes de test (créés une fois par test, annulés par la transaction).
	 *
	 * @return array{ids:array<string,int>,users:array<string,int>}
	 */
	function yume_tsrest_contexte(): array {
		$oeuvre   = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Œuvre de la matrice',
			)
		);
		$tome     = yume_factory_post(
			array(
				'post_type'   => 'yume_tome',
				'post_status' => 'draft',
				'post_title'  => 'Tome de la matrice',
				'meta_input'  => array( 'yume_oeuvre_id' => $oeuvre ),
			)
		);
		$chapitre = yume_factory_post(
			array(
				'post_type'   => 'yume_chapitre',
				'post_status' => 'draft',
				'post_title'  => 'Chapitre de la matrice',
				'meta_input'  => array( 'yume_tome_id' => $tome ),
			)
		);
		$users    = array();
		foreach ( yume_tsrest_profils() as $profil => $role ) {
			$users[ $profil ] = '' === $role ? 0 : yume_factory_user( $role );
		}
		return array(
			'ids'   => array(
				'id'       => $tome,
				'tome'     => $tome,
				'oeuvre'   => $oeuvre,
				'chapitre' => $chapitre,
				'post'     => $tome,
			),
			'users' => $users,
		);
	}

	/**
	 * Chemin concret d'un motif de route : chaque groupe nommé (?P<nom>…) est remplacé par l'ID
	 * du contenu de test du même nom (id, tome, oeuvre, chapitre…), ou par une valeur qui
	 * satisfait le motif du groupe (nouvelles routes aux paramètres inconnus). Les caractères
	 * échappés hors groupe (« \. ») sont rendus littéraux.
	 *
	 * @param string            $motif Motif de la route.
	 * @param array<string,int> $ids   Contenus de test.
	 */
	function yume_tsrest_chemin( string $motif, array $ids ): string {
		$chemin = (string) preg_replace_callback(
			'/\(\?P<(\w+)>([^()]*(?:\([^()]*\)[^()]*)*)\)/',
			static function ( array $m ) use ( $ids ): string {
				$candidats = array_merge(
					isset( $ids[ $m[1] ] ) ? array( (string) $ids[ $m[1] ] ) : array(),
					array( (string) $ids['tome'], 'test', 'a', '1', 'test-1', '2026-01-01' )
				);
				foreach ( $candidats as $valeur ) {
					if ( preg_match( '#^(?:' . $m[2] . ')$#', $valeur ) ) {
						return $valeur;
					}
				}
				return (string) $ids['tome'];
			},
			$motif
		);
		// Caractères échappés du motif (« planning\.ics ») : littéraux dans le chemin.
		return (string) preg_replace( '/\\\\([^\w])/', '$1', $chemin );
	}

	/**
	 * Appelle une route sans exécuter son rappel : 'autorise' si la permission est accordée,
	 * 'refuse' (401 ou 403), ou une description du résultat inattendu.
	 *
	 * @param string $methode Méthode HTTP.
	 * @param string $chemin  Chemin concret.
	 * @param int    $user_id Utilisateur (0 : anonyme).
	 */
	function yume_tsrest_sonder( string $methode, string $chemin, int $user_id ): string {
		$marqueur = array( 'yume_matrice' => 'autorise' );
		// Les paramètres manquants ou invalides sont signalés par WordPress AVANT la permission :
		// on les ignore ici pour que la permission soit toujours évaluée.
		$avant = static function ( $reponse ) {
			if ( is_wp_error( $reponse ) && array_intersect( $reponse->get_error_codes(), array( 'rest_missing_callback_param', 'rest_invalid_param' ) ) ) {
				return null;
			}
			return $reponse;
		};
		// Permission accordée : le rappel de la route n'est pas exécuté.
		$court = static fn() => new WP_REST_Response( $marqueur, 200 );
		add_filter( 'rest_request_before_callbacks', $avant, PHP_INT_MAX );
		add_filter( 'rest_dispatch_request', $court, PHP_INT_MAX );
		wp_set_current_user( $user_id );
		try {
			$reponse = rest_ensure_response( rest_do_request( new WP_REST_Request( $methode, $chemin ) ) );
		} finally {
			wp_set_current_user( 0 );
			remove_filter( 'rest_request_before_callbacks', $avant, PHP_INT_MAX );
			remove_filter( 'rest_dispatch_request', $court, PHP_INT_MAX );
		}
		if ( $reponse->get_data() === $marqueur ) {
			return 'autorise';
		}
		$statut = $reponse->get_status();
		if ( in_array( $statut, array( 401, 403 ), true ) ) {
			return 'refuse';
		}
		$donnees = $reponse->get_data();
		return 'inattendu ' . $statut . ( is_array( $donnees ) && isset( $donnees['code'] ) ? ' (' . $donnees['code'] . ')' : '' );
	}

	/**
	 * Couples « MÉTHODE /route » → gestionnaire, pour toutes les routes yume/v1 déclarées.
	 *
	 * @return array<string,array{route:string,methode:string,handler:array}>
	 */
	function yume_tsrest_routes_yume(): array {
		$couples = array();
		foreach ( rest_get_server()->get_routes( 'yume/v1' ) as $route => $handlers ) {
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $methode ) {
					$couples[ $methode . ' ' . $route ] = array(
						'route'   => $route,
						'methode' => $methode,
						'handler' => $handler,
					);
				}
			}
		}
		ksort( $couples );
		return $couples;
	}

	/**
	 * Profils autorisés d'une attente.
	 *
	 * @param string|string[] $attente Attente de la table.
	 * @return string[]
	 */
	function yume_tsrest_autorises( $attente ): array {
		$profils = array_keys( yume_tsrest_profils() );
		if ( 'publique' === $attente ) {
			return $profils;
		}
		if ( 'connectes' === $attente ) {
			return array_values( array_diff( $profils, array( 'anonyme' ) ) );
		}
		return (array) $attente;
	}

	/**
	 * Statut HTTP d'une requête REST interne (le rappel est exécuté).
	 *
	 * @param string $methode Méthode.
	 * @param string $chemin  Route.
	 * @param int    $user_id Utilisateur (0 : anonyme).
	 * @param array  $params  Paramètres (requête pour GET, corps sinon).
	 */
	function yume_tsrest_statut( string $methode, string $chemin, int $user_id, array $params = array() ): int {
		return yume_rest( $methode, $chemin, $params, $user_id )->get_status();
	}
}

/*
 * -----------------------------------------------------------------------------
 * Matrice yume/v1
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-14 : chaque route yume/v1 a une attente de sécurité (sinon : ajoutez-la à la table)',
	function () {
		$attentes   = yume_tsrest_attentes();
		$declarees  = yume_tsrest_routes_yume();
		$sans       = array_diff( array_keys( $declarees ), array_keys( $attentes ) );
		$obsoletes  = array_diff( array_keys( $attentes ), array_keys( $declarees ) );
		$profils    = array_keys( yume_tsrest_profils() );
		$incoherent = array();
		foreach ( $attentes as $cle => $attente ) {
			if ( ! in_array( $attente, array( 'publique', 'connectes' ), true ) && ( ! is_array( $attente ) || array_diff( $attente, $profils ) ) ) {
				$incoherent[] = $cle;
			}
		}
		yume_assert_same(
			array(),
			array_values( $sans ),
			'Nouvelle route sans attente de sécurité : ajoutez-la à yume_tsrest_attentes() (tests/test-securite-rest.php)'
		);
		yume_assert_same( array(), array_values( $obsoletes ), 'Attentes de routes qui n’existent plus : retirez-les de la table' );
		yume_assert_same( array(), $incoherent, 'Attente invalide (publique, connectes ou liste de profils de yume_tsrest_profils())' );
	}
);

yume_test(
	'AMEL-14 : seules les routes publiques de la table ont un permission_callback ouvert',
	function () {
		$attentes = yume_tsrest_attentes();
		$ecarts   = array();
		foreach ( yume_tsrest_routes_yume() as $cle => $couple ) {
			if ( '/yume/v1' === $couple['route'] ) {
				continue; // Index de l'espace de noms, déclaré par WordPress lui-même.
			}
			$permission = $couple['handler']['permission_callback'] ?? null;
			$ouverte    = null === $permission || '__return_true' === $permission;
			if ( $ouverte && 'publique' !== ( $attentes[ $cle ] ?? null ) ) {
				$ecarts[] = $cle . ' : permission_callback ' . ( null === $permission ? 'absent' : '__return_true' );
			}
			if ( null === $permission ) {
				$ecarts[] = $cle . ' : permission_callback absent (même publique : déclarer __return_true)';
			}
		}
		yume_assert_same( array(), array_values( array_unique( $ecarts ) ) );
	}
);

yume_test(
	'AMEL-14 : matrice REST yume/v1 (anonyme, lecteur, traducteur, éditeur, gérant, administrateur)',
	function () {
		$ctx      = yume_tsrest_contexte();
		$attentes = yume_tsrest_attentes();
		$ecarts   = array();
		foreach ( yume_tsrest_routes_yume() as $cle => $couple ) {
			if ( ! isset( $attentes[ $cle ] ) ) {
				continue; // Signalé par le test précédent.
			}
			$chemin    = yume_tsrest_chemin( $couple['route'], $ctx['ids'] );
			$autorises = yume_tsrest_autorises( $attentes[ $cle ] );
			foreach ( $ctx['users'] as $profil => $user_id ) {
				$obtenu  = yume_tsrest_sonder( $couple['methode'], $chemin, $user_id );
				$attendu = in_array( $profil, $autorises, true ) ? 'autorise' : 'refuse';
				if ( $obtenu !== $attendu ) {
					$ecarts[] = sprintf( '%s %s [%s] : %s, attendu %s', $couple['methode'], $chemin, $profil, $obtenu, $attendu );
				}
			}
		}
		yume_assert_same( array(), $ecarts, 'Écarts entre la matrice REST et yume_tsrest_attentes()' );
	}
);

yume_test(
	'AMEL-14 : la sonde n’exécute pas le rappel et un refus anonyme est un 401',
	function () {
		$ctx = yume_tsrest_contexte();
		// DELETE /moi (suppression de compte) : autorisé pour le lecteur, mais rien n'est supprimé.
		yume_assert_same( 'autorise', yume_tsrest_sonder( 'DELETE', '/yume/v1/moi', $ctx['users']['lecteur'] ) );
		yume_assert_true( get_userdata( $ctx['users']['lecteur'] ) instanceof WP_User, 'compte toujours présent' );
		// Refus anonyme : code 401 (et non 403) sur une route réservée.
		yume_assert_same( 401, yume_tsrest_statut( 'GET', '/yume/v1/migration', 0 ) );
		yume_assert_same( 401, yume_tsrest_statut( 'GET', '/yume/v1/moi', 0 ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Routes sensibles du cœur de WordPress
 * -----------------------------------------------------------------------------
 */

yume_test(
	'AMEL-14 : /wp/v2/users, /users/me, /settings et /plugins donnent les codes attendus',
	function () {
		$ctx = yume_tsrest_contexte();
		$u   = $ctx['users'];
		$cas = array(
			// Énumération des comptes (SEC-04) : connectés avec list_users seulement.
			array( 'GET', '/wp/v2/users', 'anonyme', 401 ),
			array( 'GET', '/wp/v2/users', 'lecteur', 403 ),
			array( 'GET', '/wp/v2/users', 'traducteur', 403 ),
			array( 'GET', '/wp/v2/users', 'gerant', 200 ),
			array( 'GET', '/wp/v2/users', 'administrateur', 200 ),
			array( 'GET', '/wp/v2/users/' . $u['administrateur'], 'anonyme', 401 ),
			array( 'GET', '/wp/v2/users/me', 'anonyme', 401 ),
			array( 'GET', '/wp/v2/users/me', 'lecteur', 200 ),
			// Réglages du site : administrateur seulement.
			array( 'GET', '/wp/v2/settings', 'anonyme', 401 ),
			array( 'GET', '/wp/v2/settings', 'lecteur', 403 ),
			array( 'GET', '/wp/v2/settings', 'gerant', 403 ),
			array( 'GET', '/wp/v2/settings', 'administrateur', 200 ),
			// Extensions : administrateur seulement.
			array( 'GET', '/wp/v2/plugins', 'anonyme', 401 ),
			array( 'GET', '/wp/v2/plugins', 'lecteur', 403 ),
			array( 'GET', '/wp/v2/plugins', 'gerant', 403 ),
			array( 'GET', '/wp/v2/plugins', 'administrateur', 200 ),
		);
		$ecarts = array();
		foreach ( $cas as list( $methode, $chemin, $profil, $attendu ) ) {
			$obtenu = yume_tsrest_statut( $methode, $chemin, $u[ $profil ] );
			if ( $obtenu !== $attendu ) {
				$ecarts[] = sprintf( '%s %s [%s] : %d, attendu %d', $methode, $chemin, $profil, $obtenu, $attendu );
			}
		}
		// Écritures sur un autre compte : jamais pour le gérant (SEC-03) ni le lecteur.
		foreach ( array( 'lecteur', 'gerant' ) as $profil ) {
			$obtenu = yume_tsrest_statut( 'POST', '/wp/v2/users/' . $u['administrateur'], $u[ $profil ], array( 'email' => 'pirate@example.test' ) );
			if ( ! in_array( $obtenu, array( 401, 403 ), true ) ) {
				$ecarts[] = sprintf( 'POST /wp/v2/users/{admin} [%s] : %d, attendu 403', $profil, $obtenu );
			}
		}
		yume_assert_same( array(), $ecarts );
		yume_assert_true( 'pirate@example.test' !== get_userdata( $u['administrateur'] )->user_email, 'e-mail de l’administrateur inchangé' );
	}
);

yume_test(
	'AMEL-14 : un commentaire anonyme par REST est refusé (401)',
	function () {
		$article = yume_factory_post( array( 'comment_status' => 'open' ) );
		$avant   = (int) get_comments_number( $article );
		$statut  = yume_tsrest_statut(
			'POST',
			'/wp/v2/comments',
			0,
			array(
				'post'         => $article,
				'content'      => 'Pourriel anonyme',
				'author_name'  => 'Robot',
				'author_email' => 'robot@example.test',
			)
		);
		yume_assert_same( 401, $statut );
		clean_post_cache( $article );
		yume_assert_same( $avant, (int) get_comments_number( $article ), 'aucun commentaire créé' );
	}
);

yume_test(
	'AMEL-14 : métas privées des tomes (yume_responsables, yume_maj_par) absentes en « view » anonyme',
	function () {
		$ctx     = yume_tsrest_contexte();
		$tome    = yume_factory_post(
			array(
				'post_type'  => 'yume_tome',
				'post_title' => 'Tome publié de la matrice',
				'meta_input' => array(
					'yume_oeuvre_id'    => $ctx['ids']['oeuvre'],
					'yume_responsables' => array( 'traduction' => $ctx['users']['traducteur'] ),
					'yume_maj_par'      => $ctx['users']['gerant'],
				),
			)
		);
		$privees = array( 'yume_responsables', 'yume_maj_par' );
		foreach ( array( '/wp/v2/tomes/' . $tome, '/wp/v2/tomes' ) as $chemin ) {
			foreach ( array( array(), array( 'context' => 'view' ), array( '_fields' => 'id,meta' ) ) as $params ) {
				$reponse = yume_rest( 'GET', $chemin, $params, 0 );
				yume_assert_same( 200, $reponse->get_status(), $chemin );
				$json = (string) wp_json_encode( $reponse->get_data() );
				foreach ( $privees as $meta ) {
					yume_assert_not_contains( '"' . $meta . '"', $json, $chemin . ' ' . wp_json_encode( $params ) );
				}
			}
		}
		// Le contexte « edit » reste refusé aux anonymes.
		yume_assert_same( 401, yume_tsrest_statut( 'GET', '/wp/v2/tomes/' . $tome, 0, array( 'context' => 'edit' ) ) );
	}
);
