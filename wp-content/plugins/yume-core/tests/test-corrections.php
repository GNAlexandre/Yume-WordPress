<?php
/**
 * Tests du module corrections (lot L1 du chantier « lecteurs ») : signalement des coquilles.
 *
 * - table yume_coquilles (SQLite et MariaDB), empreinte et numérotation des paragraphes
 *   (cohérente avec la recherche et le rendu du contenu, ancre #yn-p-N) ;
 * - signaler_coquille() : chapitre non signalable, extrait, doublon, débit, saturation, MangaDex,
 *   visiteur refusé par défaut, mode anonyme avec jeton et pot de miel ;
 * - REST POST /chapitres/{id}/coquilles : rappels appelés directement (une requête REST
 *   déclencherait rest_api_init pour les fichiers de test suivants), permission ;
 * - vue ?vue=corrections : entrée de navigation, droits (lecture pour toute l'équipe, actions pour
 *   edit_post), filtres, groupement par chapitre, pastille « Paragraphe modifié depuis » ;
 *   changer_statut(), traiter_chapitre(), actions admin-post ;
 * - Discord (étouffement, réglage), notification du lecteur, RGPD, purge, chapitre supprimé ;
 * - lecteur : fenêtre imprimée sur wp_footer seulement sur un chapitre texte publié, lien après
 *   yume/chapter-nav, script chargé.
 *
 * Lancement : tools/localenv/test.sh corrections
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\Corrections\anonymiser_coquilles;
use function Yume\Core\Corrections\changer_statut;
use function Yume\Core\Corrections\chapitre_signalable;
use function Yume\Core\Corrections\charger_assets;
use function Yume\Core\Corrections\compter_nouvelles;
use function Yume\Core\Corrections\coquille;
use function Yume\Core\Corrections\coquilles_actives;
use function Yume\Core\Corrections\empreinte_paragraphe;
use function Yume\Core\Corrections\exporter_donnees;
use function Yume\Core\Corrections\effacer_donnees_email;
use function Yume\Core\Corrections\imprimer_fenetre;
use function Yume\Core\Corrections\lancer_purge;
use function Yume\Core\Corrections\lien_fin_de_chapitre;
use function Yume\Core\Corrections\lister_coquilles;
use function Yume\Core\Corrections\notifier_lecteur;
use function Yume\Core\Corrections\paragraphes_chapitre;
use function Yume\Core\Corrections\permission_signaler;
use function Yume\Core\Corrections\purger_coquilles;
use function Yume\Core\Corrections\rendu_vue_corrections;
use function Yume\Core\Corrections\rest_signaler;
use function Yume\Core\Corrections\signaler_coquille;
use function Yume\Core\Corrections\table_coquilles;
use function Yume\Core\Corrections\table_prete;
use function Yume\Core\Corrections\texte_paragraphe;
use function Yume\Core\Corrections\traiter_chapitre;
use function Yume\Core\Corrections\traiter_correction;
use function Yume\Core\Corrections\traiter_correction_chapitre;
use function Yume\Core\Planning\vues_equipe_ajoutees;

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tcor_)
 * -----------------------------------------------------------------------------
 */

/**
 * Déclare un test : table des signalements vidée, réglages, $_GET, requête principale, scripts et
 * compte courant remis en place ensuite.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tcor_test( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			$wpdb->query( 'DELETE FROM ' . table_coquilles() ); // phpcs:ignore WordPress.DB
			\Yume\Core\Core\installer_roles();
			$reglages = get_option( 'yume_reglages', array() );
			$get      = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requete  = $GLOBALS['wp_query'];
			try {
				$corps();
			} finally {
				update_option( 'yume_reglages', $reglages );
				$_GET                = $get; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$GLOBALS['wp_query'] = $requete; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				wp_dequeue_script( 'yume-coquilles' );
				wp_dequeue_style( 'yume-coquilles' );
				wp_set_current_user( 0 );
			}
		}
	);
}

/**
 * Modifie des réglages Yume (le test les remet en place).
 *
 * @param array $valeurs Clé => valeur.
 */
function yume_tcor_reglages( array $valeurs ): void {
	$reglages = get_option( 'yume_reglages', array() );
	update_option( 'yume_reglages', array_merge( is_array( $reglages ) ? $reglages : array(), $valeurs ) );
}

/**
 * Contenu en blocs d'un chapitre : un paragraphe par texte, '---' pour un séparateur de scène.
 *
 * @param string[] $paragraphes Paragraphes.
 */
function yume_tcor_contenu( array $paragraphes ): string {
	$contenu = '';
	foreach ( $paragraphes as $p ) {
		$contenu .= '---' === $p
			? "<!-- wp:separator {\"className\":\"yn-scene-break\"} -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity yn-scene-break\"/>\n<!-- /wp:separator -->\n\n"
			: "<!-- wp:paragraph -->\n<p>" . esc_html( $p ) . "</p>\n<!-- /wp:paragraph -->\n\n";
	}
	return $contenu;
}

/**
 * Paragraphes du chapitre de test.
 *
 * @return string[]
 */
function yume_tcor_paragraphes(): array {
	return array(
		'Le vent du col charriait une odeur de pierre mouillée.',
		'— Encore trois heures de marche, souffla Doran.',
		'Elle poussa la porte de la lanterne et sourit. Il ne restait plus qu’a attendre.',
		'---',
		'Doran s’assit sur le muret, déroula la carte et suivit le sentier.',
	);
}

/**
 * Œuvre, tome et chapitre publiés (contenu yume_tcor_paragraphes()).
 *
 * @param string $statut_oeuvre Statut de l'œuvre.
 * @param string $statut_tome   Statut du tome.
 * @return array{oeuvre:int,tome:int,chapitre:int}
 */
function yume_tcor_contexte( string $statut_oeuvre = 'publish', string $statut_tome = 'publish' ): array {
	$oeuvre = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => 'Les Lanternes de Brume-Haute',
			'post_status' => $statut_oeuvre,
		)
	);
	$tome   = yume_factory_post(
		array(
			'post_type'   => 'yume_tome',
			'post_title'  => 'Lanternes — Tome 1',
			'post_status' => $statut_tome,
			'meta_input'  => array(
				'yume_oeuvre_id' => $oeuvre,
				'yume_numero'    => 1,
				'yume_nature'    => 'tome',
			),
		)
	);
	return array(
		'oeuvre'   => $oeuvre,
		'tome'     => $tome,
		'chapitre' => yume_tcor_chapitre( $tome, 2 ),
	);
}

/**
 * Chapitre d'un tome.
 *
 * @param int      $tome_id     Tome.
 * @param int      $numero      Numéro.
 * @param string   $statut      Statut.
 * @param string[] $paragraphes Paragraphes (défaut : yume_tcor_paragraphes()).
 */
function yume_tcor_chapitre( int $tome_id, int $numero, string $statut = 'publish', array $paragraphes = array() ): int {
	return yume_factory_post(
		array(
			'post_type'    => 'yume_chapitre',
			'post_title'   => 'La Crête Brumeuse ' . $numero,
			'post_name'    => 'chapitre-' . $numero . '-' . wp_rand( 1, 99999 ),
			'post_status'  => $statut,
			'post_content' => yume_tcor_contenu( $paragraphes ? $paragraphes : yume_tcor_paragraphes() ),
			'menu_order'   => $numero,
			'meta_input'   => array(
				'yume_tome_id'   => $tome_id,
				'yume_oeuvre_id' => (int) get_post_meta( $tome_id, 'yume_oeuvre_id', true ),
				'yume_numero'    => $numero,
				'yume_nature'    => 'chapitre',
			),
		)
	);
}

/**
 * Données d'un signalement valide.
 *
 * @param array $autres Valeurs à remplacer.
 */
function yume_tcor_donnees( array $autres = array() ): array {
	return array_merge(
		array(
			'paragraphe'  => 2,
			'extrait'     => 'Il ne restait plus qu’a attendre.',
			'contexte'    => '',
			'commentaire' => 'il manque l’accent sur « à »',
		),
		$autres
	);
}

/**
 * Jeton horodaté valide (au moins 3 secondes), comme Social\jeton_formulaire().
 *
 * @param int $age Âge en secondes.
 */
function yume_tcor_jeton( int $age = 30 ): string {
	$t = (string) ( time() - $age );
	return $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 20 );
}

/**
 * Place la requête principale sur un contenu (is_singular, get_queried_object_id).
 *
 * @param int $post_id Contenu.
 */
function yume_tcor_requete( int $post_id ): void {
	$GLOBALS['wp_query'] = new WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		array(
			'p'           => $post_id,
			'post_type'   => get_post_type( $post_id ),
			'post_status' => 'any',
		)
	);
}

/**
 * Sortie de wp_footer pour la fenêtre de signalement.
 */
function yume_tcor_pied(): string {
	ob_start();
	imprimer_fenetre();
	return (string) ob_get_clean();
}

/**
 * Requête REST du signalement, sans passer par le serveur REST.
 *
 * @param int   $chapitre_id Chapitre.
 * @param array $corps       Corps.
 */
function yume_tcor_requete_rest( int $chapitre_id, array $corps ): WP_REST_Request {
	$requete = new WP_REST_Request( 'POST', '/yume/v1/chapitres/' . $chapitre_id . '/coquilles' );
	$requete->set_url_params( array( 'chapitre' => (string) $chapitre_id ) );
	$requete->set_body_params( $corps );
	return $requete;
}

/**
 * Exécute $rappel en capturant les requêtes HTTP sortantes (réponse 204 simulée).
 *
 * @param callable $rappel Fonction.
 * @return array<int,array{url:string,body:string}>
 */
function yume_tcor_http( callable $rappel ): array {
	$appels = array();
	$filtre = static function ( $pre, $args, $url ) use ( &$appels ) {
		$appels[] = array(
			'url'  => (string) $url,
			'body' => (string) ( $args['body'] ?? '' ),
		);
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 204,
				'message' => 'No Content',
			),
			'cookies'  => array(),
		);
	};
	add_filter( 'pre_http_request', $filtre, 10, 3 );
	try {
		$rappel();
	} finally {
		remove_filter( 'pre_http_request', $filtre, 10 );
	}
	return $appels;
}

/*
 * -----------------------------------------------------------------------------
 * Table, empreinte, paragraphes
 * -----------------------------------------------------------------------------
 */

yume_tcor_test(
	'Table yume_coquilles installée avec toutes ses colonnes (moteur courant)',
	function () {
		global $wpdb;
		yume_assert_true( table_prete() );
		$c                = yume_tcor_contexte();
		$u                = yume_factory_user();
		$id               = signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees() )['id'];
		$wpdb->last_error = '';
		$ligne            = $wpdb->get_row( $wpdb->prepare( 'SELECT id, chapitre_id, tome_id, oeuvre_id, user_id, paragraphe, extrait, contexte, empreinte, commentaire, statut, ip_hash, cree_le, traite_le, traite_par FROM ' . table_coquilles() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		yume_assert_same( '', $wpdb->last_error );
		yume_assert_same( 'nouveau', $ligne['statut'] );
		yume_assert_true( null === $ligne['traite_le'], 'traite_le NULL tant que non traité' );
	}
);

yume_tcor_test(
	'empreinte_paragraphe() : stable aux espaces, balises et apostrophes ; change avec le texte',
	function () {
		$base = empreinte_paragraphe( 'Il ne restait plus qu’a attendre.' );
		yume_assert_same( 32, strlen( $base ) );
		yume_assert_same( $base, empreinte_paragraphe( "  Il ne  restait\nplus qu'a attendre. " ) );
		yume_assert_same( $base, empreinte_paragraphe( '<p>Il ne <em>restait</em> plus qu&rsquo;a&nbsp;attendre.</p>' ) );
		yume_assert_true( empreinte_paragraphe( 'Il ne restait plus qu’à attendre.' ) !== $base );
	}
);

yume_tcor_test(
	'texte_paragraphe() : i-ème bloc de premier niveau, même numérotation que la recherche (#yn-p-N) et que le contenu rendu',
	function () {
		$c = yume_tcor_contexte();
		yume_assert_same( 'Le vent du col charriait une odeur de pierre mouillée.', texte_paragraphe( $c['chapitre'], 0 ) );
		yume_assert_same( 'Elle poussa la porte de la lanterne et sourit. Il ne restait plus qu\'a attendre.', texte_paragraphe( $c['chapitre'], 2 ) );
		yume_assert_same( '', texte_paragraphe( $c['chapitre'], 3 ), 'séparateur : paragraphe sans texte' );
		yume_assert_same( 'Doran s\'assit sur le muret, déroula la carte et suivit le sentier.', texte_paragraphe( $c['chapitre'], 4 ) );
		yume_assert_same( '', texte_paragraphe( $c['chapitre'], 5 ) );
		yume_assert_same( '', texte_paragraphe( $c['chapitre'], -1 ) );
		yume_assert_same( 5, count( paragraphes_chapitre( $c['chapitre'] ) ) );

		// Recherche : « muret » est au paragraphe 5 (#yn-p-5) → index 4.
		$contenu = (string) get_post_field( 'post_content', $c['chapitre'] );
		$numero  = \Yume\Core\Library\paragraphe_occurrence( $contenu, array( \Yume\Core\Library\plier( 'muret' ) ) );
		yume_assert_same( 5, $numero );
		yume_assert_contains( 'muret', texte_paragraphe( $c['chapitre'], $numero - 1 ) );

		// Contenu rendu : les enfants directs (ce que le lecteur numérote) suivent le même ordre.
		$html = apply_filters( 'the_content', $contenu ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$doc  = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="racine">' . $html . '</div>' );
		libxml_clear_errors();
		$enfants = array();
		foreach ( $doc->getElementById( 'racine' )->childNodes as $noeud ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriétés de DOMNode.
			if ( XML_ELEMENT_NODE === $noeud->nodeType && ! in_array( strtolower( $noeud->nodeName ), array( 'script', 'style' ), true ) ) {
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété de DOMNode.
				$enfants[] = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( array( '’', "\u{00A0}" ), array( "'", ' ' ), $noeud->textContent ) ) );
			}
		}
		yume_assert_same( paragraphes_chapitre( $c['chapitre'] ), array_slice( $enfants, 0, 5 ) );
	}
);

yume_tcor_test(
	'chapitre_signalable() : chapitre texte publié d’un tome et d’une œuvre publiés, sans mot de passe, hors MangaDex',
	function () {
		$c = yume_tcor_contexte();
		yume_assert_true( chapitre_signalable( $c['chapitre'] ) );
		yume_assert_false( chapitre_signalable( yume_tcor_chapitre( $c['tome'], 3, 'draft' ) ), 'brouillon' );
		yume_assert_false( chapitre_signalable( $c['tome'] ), 'un tome n’est pas un chapitre' );
		$protege = yume_tcor_chapitre( $c['tome'], 4 );
		wp_update_post(
			array(
				'ID'            => $protege,
				'post_password' => 'secret',
			)
		);
		yume_assert_false( chapitre_signalable( $protege ), 'mot de passe' );
		$manga = yume_tcor_chapitre( $c['tome'], 5 );
		update_post_meta( $manga, 'yume_mdx_chapitre', '0b9a2c1e-1234-4abc-8def-1234567890ab' );
		yume_assert_false( chapitre_signalable( $manga ), 'MangaDex' );
		$brouillon_tome = yume_tcor_contexte( 'publish', 'draft' );
		yume_assert_false( chapitre_signalable( $brouillon_tome['chapitre'] ), 'tome en brouillon' );
		$brouillon_oeuvre = yume_tcor_contexte( 'draft' );
		yume_assert_false( chapitre_signalable( $brouillon_oeuvre['chapitre'] ), 'œuvre en brouillon' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Signalement
 * -----------------------------------------------------------------------------
 */

yume_tcor_test(
	'signaler_coquille() : ligne enregistrée (œuvre et tome dénormalisés, contexte, empreinte, URL retirées du commentaire), action émise',
	function () {
		$c      = yume_tcor_contexte();
		$u      = yume_factory_user();
		$emises = array();
		$ecoute = static function ( $id, $chapitre, $user ) use ( &$emises ) {
			$emises[] = array( $id, $chapitre, $user );
		};
		add_action( 'yume_coquille_signalee', $ecoute, 10, 3 );
		try {
			$r = signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees( array( 'commentaire' => "accent manquant <b>voir</b> https://spam.example/x et www.spam.example\nmerci" ) ) );
		} finally {
			remove_action( 'yume_coquille_signalee', $ecoute, 10 );
		}
		yume_assert_true( is_array( $r ), 'signalement accepté' );
		yume_assert_same( 'Merci ! L’équipe a reçu votre signalement.', $r['message'] );
		yume_assert_same( array( array( $r['id'], $c['chapitre'], $u ) ), $emises );
		$ligne = coquille( $r['id'] );
		yume_assert_same( $c['tome'], $ligne['tome_id'] );
		yume_assert_same( $c['oeuvre'], $ligne['oeuvre_id'] );
		yume_assert_same( 2, $ligne['paragraphe'] );
		yume_assert_same( 'Il ne restait plus qu\'a attendre.', $ligne['extrait'] );
		yume_assert_same( texte_paragraphe( $c['chapitre'], 2 ), $ligne['contexte'] );
		yume_assert_same( empreinte_paragraphe( texte_paragraphe( $c['chapitre'], 2 ) ), $ligne['empreinte'] );
		yume_assert_same( "accent manquant voir et\nmerci", $ligne['commentaire'] );
		yume_assert_same( '', $ligne['ip_hash'], 'pas d’empreinte d’IP pour un compte' );
		yume_assert_same( 1, compter_nouvelles() );
		yume_assert_same( 1, compter_nouvelles( $c['oeuvre'] ) );

		// Liens tolérés par filtre.
		add_filter( 'yume_coquilles_liens', '__return_true' );
		try {
			$r2 = signaler_coquille(
				$c['chapitre'],
				$u,
				yume_tcor_donnees(
					array(
						'paragraphe'  => 0,
						'extrait'     => 'odeur de pierre',
						'commentaire' => 'voir https://exemple.fr',
					)
				)
			);
		} finally {
			remove_filter( 'yume_coquilles_liens', '__return_true' );
		}
		yume_assert_same( 'voir https://exemple.fr', coquille( $r2['id'] )['commentaire'] );
	}
);

yume_tcor_test(
	'signaler_coquille() : refus (brouillon 404, extrait 400, paragraphe inconnu 400, doublon 409, visiteur 401, désactivé 403)',
	function () {
		$c         = yume_tcor_contexte();
		$u         = yume_factory_user();
		$brouillon = yume_tcor_chapitre( $c['tome'], 3, 'draft' );
		$code      = static function ( $r ): string {
			return is_wp_error( $r ) ? $r->get_error_code() . ' ' . (int) $r->get_error_data()['status'] : 'accepté';
		};
		yume_assert_same( 'yume_chapitre_introuvable 404', $code( signaler_coquille( $brouillon, $u, yume_tcor_donnees() ) ) );
		yume_assert_same( 'yume_chapitre_introuvable 404', $code( signaler_coquille( 999999, $u, yume_tcor_donnees() ) ) );
		yume_assert_same( 'yume_coquille_extrait 400', $code( signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees( array( 'extrait' => ' a  ' ) ) ) ) );
		yume_assert_same( 'yume_coquille_extrait 400', $code( signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees( array( 'extrait' => str_repeat( 'x', 301 ) ) ) ) ) );
		yume_assert_same( 'yume_coquille_extrait 400', $code( signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees( array( 'paragraphe' => 5 ) ) ) ) );
		yume_assert_same( 'yume_coquille_extrait 400', $code( signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees( array( 'paragraphe' => -1 ) ) ) ) );
		yume_assert_same( 'accepté', $code( signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees( array( 'extrait' => str_repeat( 'x', 300 ) ) ) ) ) );
		$doublon = signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees() );
		yume_assert_same( 'yume_coquille_doublon 409', $code( $doublon ) );
		yume_assert_same( 'Vous avez déjà signalé ce passage.', $doublon->get_error_message() );
		yume_assert_same( 'accepté', $code( signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() ) ), 'un autre lecteur, même paragraphe' );
		yume_assert_same(
			'accepté',
			$code(
				signaler_coquille(
					$c['chapitre'],
					$u,
					yume_tcor_donnees(
						array(
							'paragraphe' => 4,
							'extrait'    => 'sur le muret',
						)
					)
				)
			),
			'même lecteur, autre paragraphe'
		);
		yume_assert_same( 'rest_forbidden 401', $code( signaler_coquille( $c['chapitre'], 0, yume_tcor_donnees( array( 'jeton' => yume_tcor_jeton() ) ) ) ), 'visiteur refusé par défaut' );

		// Paragraphe sans texte (séparateur) : contexte du client gardé.
		$r = signaler_coquille(
			$c['chapitre'],
			$u,
			yume_tcor_donnees(
				array(
					'paragraphe' => 3,
					'extrait'    => 'scène',
					'contexte'   => '<i>* * *</i>',
				)
			)
		);
		yume_assert_same( '* * *', coquille( $r['id'] )['contexte'] );

		yume_tcor_reglages( array( 'coquilles_actives' => false ) );
		yume_assert_false( coquilles_actives() );
		yume_assert_same(
			'yume_coquilles_inactives 403',
			$code(
				signaler_coquille(
					$c['chapitre'],
					$u,
					yume_tcor_donnees(
						array(
							'paragraphe' => 0,
							'extrait'    => 'le vent',
						)
					)
				)
			)
		);
		yume_tcor_reglages( array( 'coquilles_actives' => true ) );
		add_filter( 'yume_coquilles_actives', '__return_false' );
		try {
			yume_assert_same(
				'yume_coquilles_inactives 403',
				$code(
					signaler_coquille(
						$c['chapitre'],
						$u,
						yume_tcor_donnees(
							array(
								'paragraphe' => 0,
								'extrait'    => 'le vent',
							)
						)
					)
				)
			);
		} finally {
			remove_filter( 'yume_coquilles_actives', '__return_false' );
		}
	}
);

yume_tcor_test(
	'signaler_coquille() : signalement possible de nouveau une fois le précédent traité',
	function () {
		$c  = yume_tcor_contexte();
		$u  = yume_factory_user();
		$ed = yume_factory_user( 'yume_editeur' );
		$id = signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees() )['id'];
		yume_assert_true( changer_statut( $id, 'corrige', $ed ) );
		yume_assert_true( is_array( signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees() ) ) );
	}
);

yume_tcor_test(
	'signaler_coquille() : débit par compte (filtre yume_coquilles_debit) → 429',
	function () {
		$c     = yume_tcor_contexte();
		$u     = yume_factory_user();
		$debit = static fn() => 2;
		add_filter( 'yume_coquilles_debit', $debit );
		try {
			yume_assert_true(
				is_array(
					signaler_coquille(
						$c['chapitre'],
						$u,
						yume_tcor_donnees(
							array(
								'paragraphe' => 0,
								'extrait'    => 'le vent du col',
							)
						)
					)
				)
			);
			yume_assert_true(
				is_array(
					signaler_coquille(
						$c['chapitre'],
						$u,
						yume_tcor_donnees(
							array(
								'paragraphe' => 1,
								'extrait'    => 'trois heures',
							)
						)
					)
				)
			);
			$r = signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees() );
			yume_assert_true( is_wp_error( $r ) );
			yume_assert_same( 'yume_coquilles_debit', $r->get_error_code() );
			yume_assert_same( 429, $r->get_error_data()['status'] );
			yume_assert_true( is_array( signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() ) ), 'un autre compte n’est pas limité' );
		} finally {
			remove_filter( 'yume_coquilles_debit', $debit );
		}
	}
);

yume_tcor_test(
	'signaler_coquille() : 200 signalements nouveaux sur un chapitre → 429 yume_coquilles_chapitre_sature',
	function () {
		global $wpdb;
		$c = yume_tcor_contexte();
		for ( $i = 0; $i < 200; $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				table_coquilles(),
				array(
					'chapitre_id' => $c['chapitre'],
					'user_id'     => 100000 + $i,
					'paragraphe'  => 0,
					'extrait'     => 'le vent',
					'contexte'    => '',
					'statut'      => 'nouveau',
					'cree_le'     => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
		$r = signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
		yume_assert_true( is_wp_error( $r ) );
		yume_assert_same( 'yume_coquilles_chapitre_sature', $r->get_error_code() );
		yume_assert_same( 429, $r->get_error_data()['status'] );
	}
);

yume_tcor_test(
	'signaler_coquille() : MangaDex refusé (404)',
	function () {
		$c = yume_tcor_contexte();
		update_post_meta( $c['chapitre'], 'yume_mdx_chapitre', '0b9a2c1e-1234-4abc-8def-1234567890ab' );
		$r = signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
		yume_assert_same( 'yume_chapitre_introuvable', is_wp_error( $r ) ? $r->get_error_code() : 'accepté' );
	}
);

yume_tcor_test(
	'Mode anonyme (coquilles_anonymes) : jeton horodaté et pot de miel exigés, empreinte d’IP, doublon par IP',
	function () {
		$c = yume_tcor_contexte();
		yume_tcor_reglages( array( 'coquilles_anonymes' => true ) );
		$code = static function ( $r ): string {
			return is_wp_error( $r ) ? $r->get_error_code() : 'accepté';
		};
		yume_assert_same( 'yume_coquille_jeton', $code( signaler_coquille( $c['chapitre'], 0, yume_tcor_donnees() ) ), 'sans jeton' );
		yume_assert_same( 'yume_coquille_jeton', $code( signaler_coquille( $c['chapitre'], 0, yume_tcor_donnees( array( 'jeton' => yume_tcor_jeton( 0 ) ) ) ) ), 'trop tôt' );
		yume_assert_same(
			'yume_coquille_jeton',
			$code(
				signaler_coquille(
					$c['chapitre'],
					0,
					yume_tcor_donnees(
						array(
							'jeton'       => yume_tcor_jeton(),
							'yn_site_web' => 'http://robot',
						)
					)
				)
			),
			'pot de miel'
		);
		$r = signaler_coquille( $c['chapitre'], 0, yume_tcor_donnees( array( 'jeton' => yume_tcor_jeton() ) ) );
		yume_assert_same( 'accepté', $code( $r ) );
		$ligne = coquille( $r['id'] );
		yume_assert_same( 0, $ligne['user_id'] );
		yume_assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', $ligne['ip_hash'] ), 'empreinte HMAC de l’IP' );
		yume_assert_same( 'yume_coquille_doublon', $code( signaler_coquille( $c['chapitre'], 0, yume_tcor_donnees( array( 'jeton' => yume_tcor_jeton() ) ) ) ) );

		// Permission REST : ouverte aux visiteurs dans ce mode seulement.
		yume_assert_true( permission_signaler() );
		yume_tcor_reglages( array( 'coquilles_anonymes' => false ) );
		yume_assert_true( is_wp_error( permission_signaler() ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * REST
 * -----------------------------------------------------------------------------
 */

yume_tcor_test(
	'REST POST /chapitres/{id}/coquilles : 401 anonyme, 201 lecteur {id, message}, erreurs transmises',
	function () {
		$c = yume_tcor_contexte();
		$u = yume_factory_user();
		wp_set_current_user( 0 );
		$refus = permission_signaler();
		yume_assert_true( is_wp_error( $refus ) );
		yume_assert_same( 'rest_forbidden', $refus->get_error_code() );
		yume_assert_same( 401, $refus->get_error_data()['status'] );

		wp_set_current_user( $u );
		yume_assert_true( permission_signaler() );
		$reponse = rest_signaler( yume_tcor_requete_rest( $c['chapitre'], yume_tcor_donnees() ) );
		yume_assert_true( $reponse instanceof WP_REST_Response );
		yume_assert_same( 201, $reponse->get_status() );
		$donnees = $reponse->get_data();
		yume_assert_same( array( 'id', 'message' ), array_keys( $donnees ) );
		yume_assert_true( is_int( $donnees['id'] ) && $donnees['id'] > 0 );
		yume_assert_same( $u, coquille( $donnees['id'] )['user_id'] );
		yume_assert_contains( 'no-store', (string) ( $reponse->get_headers()['Cache-Control'] ?? '' ) );
		yume_assert_true( false !== wp_json_encode( $donnees ) );

		$doublon = rest_signaler( yume_tcor_requete_rest( $c['chapitre'], yume_tcor_donnees() ) );
		yume_assert_true( is_wp_error( $doublon ) );
		yume_assert_same( 409, $doublon->get_error_data()['status'] );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Espace équipe
 * -----------------------------------------------------------------------------
 */

yume_tcor_test(
	'Vue : entrée « Corrections (N) » dans le menu Catalogue pour l’équipe seulement, absente tant que la file est vide',
	function () {
		$c = yume_tcor_contexte();
		signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
		signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
		wp_set_current_user( yume_factory_user() );
		yume_assert_false( isset( vues_equipe_ajoutees()['corrections'] ), 'lecteur : pas d’entrée' );
		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		$vues = vues_equipe_ajoutees();
		yume_assert_true( isset( $vues['corrections'] ), 'traducteur : entrée visible' );
		yume_assert_same( 'Corrections (2)', $vues['corrections']['libelle'] );
		yume_assert_same( 'catalogue', $vues['corrections']['groupe'] );
		yume_assert_same( 'yume_voir_equipe', $vues['corrections']['capacite'] );
		$ed = yume_factory_user( 'yume_editeur' );
		foreach ( lister_coquilles( array( 'limite' => 0 ) )['lignes'] as $ligne ) {
			changer_statut( $ligne['id'], 'corrige', $ed );
		}
		yume_assert_same( 'Corrections', vues_equipe_ajoutees()['corrections']['libelle'], 'file traitée : entrée sans compteur' );

		// File vide : pas d'entrée (navigation sobre), sauf sur la vue elle-même.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . table_coquilles() ); // phpcs:ignore WordPress.DB
		yume_assert_false( isset( vues_equipe_ajoutees()['corrections'] ), 'file vide : pas d’entrée' );
		$_GET['vue'] = 'corrections';
		yume_assert_true( isset( vues_equipe_ajoutees()['corrections'] ), 'sur la vue : entrée présente' );
	}
);

yume_tcor_test(
	'Vue : traducteur en lecture seule, éditeur avec les actions ; groupement par chapitre, citation surlignée, pastille « Paragraphe modifié depuis »',
	function () {
		$c   = yume_tcor_contexte();
		$ch2 = yume_tcor_chapitre( $c['tome'], 3 );
		$a   = yume_factory_user();
		wp_update_user(
			array(
				'ID'           => $a,
				'display_name' => 'Mio des Brumes',
			)
		);
		$id1 = signaler_coquille( $c['chapitre'], $a, yume_tcor_donnees() )['id'];
		$id2 = signaler_coquille(
			$c['chapitre'],
			yume_factory_user(),
			yume_tcor_donnees(
				array(
					'paragraphe'  => 4,
					'extrait'     => 'sur le muret',
					'commentaire' => '',
				)
			)
		)['id'];
		$id3 = signaler_coquille(
			$ch2,
			$a,
			yume_tcor_donnees(
				array(
					'paragraphe' => 0,
					'extrait'    => 'odeur de pierre',
				)
			)
		)['id'];

		wp_set_current_user( yume_factory_user( 'yume_traducteur' ) );
		$html = rendu_vue_corrections();
		yume_assert_contains( 'Corrections signalées par les lecteurs', $html );
		yume_assert_same( 2, substr_count( $html, 'class="yn-card yn-corrections__chapitre"' ), 'deux chapitres, deux cartes' );
		yume_assert_contains( 'Les Lanternes de Brume-Haute › Tome 1 › ', $html );
		yume_assert_contains( '2 signalements', $html );
		yume_assert_contains( '<mark>Il ne restait plus qu&#039;a attendre.</mark>', $html );
		yume_assert_contains( 'Mio des Brumes', $html );
		yume_assert_contains( 'Sans commentaire', $html );
		yume_assert_contains( '#yn-p-3', $html, 'lien vers le paragraphe 3' );
		yume_assert_contains( 'Seul un éditeur peut traiter ce signalement.', $html );
		yume_assert_not_contains( 'yume_correction', $html, 'aucune action pour un traducteur' );
		yume_assert_not_contains( 'Paragraphe modifié depuis', $html );
		yume_assert_contains( '3 nouveaux · 0 corrigé', $html );

		// Le paragraphe 3 du premier chapitre change : pastille.
		wp_update_post(
			array(
				'ID'           => $c['chapitre'],
				'post_content' => yume_tcor_contenu( array( 'Le vent du col charriait une odeur de pierre mouillée.', '— Encore trois heures de marche, souffla Doran.', 'Elle poussa la porte de la lanterne et sourit. Il ne restait plus qu’à attendre.', '---', 'Doran s’assit sur le muret, déroula la carte et suivit le sentier.' ) ),
			)
		);
		wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
		$html = rendu_vue_corrections();
		yume_assert_same( 1, substr_count( $html, 'Paragraphe modifié depuis' ), 'seul le paragraphe 3 a changé' );
		yume_assert_contains( 'name="action" value="yume_correction"', $html );
		yume_assert_contains( 'name="coquille" value="' . $id1 . '"', $html );
		yume_assert_contains( 'value="corrige"', $html );
		yume_assert_contains( 'value="rejete"', $html );
		yume_assert_contains( 'name="action" value="yume_correction_chapitre"', $html );
		yume_assert_contains( 'Corriger dans l’éditeur', $html );
		yume_assert_not_contains( 'Seul un éditeur', $html );

		// Ordre : chapitre du signalement le plus récent d'abord ; dans un chapitre, par paragraphe.
		yume_assert_true( strpos( $html, 'id="yn-coquille-' . $id1 . '"' ) < strpos( $html, 'id="yn-coquille-' . $id2 . '"' ) );
		yume_assert_true( false !== strpos( $html, 'id="yn-coquille-' . $id3 . '"' ) );
	}
);

yume_tcor_test(
	'Vue : filtres œuvre, tome et statut (nouveaux par défaut)',
	function () {
		$c1 = yume_tcor_contexte();
		$c2 = yume_tcor_contexte();
		wp_update_post(
			array(
				'ID'         => $c2['oeuvre'],
				'post_title' => 'Silent Witch',
			)
		);
		$ed  = yume_factory_user( 'yume_editeur' );
		$id1 = signaler_coquille( $c1['chapitre'], yume_factory_user(), yume_tcor_donnees() )['id'];
		$id2 = signaler_coquille( $c2['chapitre'], yume_factory_user(), yume_tcor_donnees() )['id'];
		$id3 = signaler_coquille(
			$c2['chapitre'],
			yume_factory_user(),
			yume_tcor_donnees(
				array(
					'paragraphe' => 0,
					'extrait'    => 'le vent',
				)
			)
		)['id'];
		changer_statut( $id3, 'rejete', $ed );
		wp_set_current_user( $ed );

		$html = rendu_vue_corrections();
		yume_assert_contains( 'yn-coquille-' . $id1 . '"', $html );
		yume_assert_contains( 'yn-coquille-' . $id2 . '"', $html );
		yume_assert_not_contains( 'yn-coquille-' . $id3 . '"', $html, 'rejeté : hors du filtre par défaut' );
		yume_assert_contains( '<option value="' . $c2['oeuvre'] . '">Silent Witch</option>', $html );

		$_GET = array( 'oeuvre' => (string) $c2['oeuvre'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html = rendu_vue_corrections();
		yume_assert_not_contains( 'yn-coquille-' . $id1 . '"', $html );
		yume_assert_contains( 'yn-coquille-' . $id2 . '"', $html );
		yume_assert_contains( '1 nouveau · 0 corrigé', $html );
		yume_assert_contains( ' · 1 rejeté</p>', $html );

		$_GET = array( 'tome' => (string) $c1['tome'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html = rendu_vue_corrections();
		yume_assert_contains( 'yn-coquille-' . $id1 . '"', $html );
		yume_assert_not_contains( 'yn-coquille-' . $id2 . '"', $html );

		$_GET = array( 'statut' => 'rejete' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$html = rendu_vue_corrections();
		yume_assert_contains( 'yn-coquille-' . $id3 . '"', $html );
		yume_assert_contains( 'value="rouvrir"', $html );
		yume_assert_not_contains( 'yn-coquille-' . $id1 . '"', $html );

		$_GET = array( 'statut' => 'tous' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		yume_assert_same( 3, substr_count( rendu_vue_corrections(), 'class="yn-corrections__signalement"' ) );

		$_GET = array( 'statut' => 'nimporte' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		yume_assert_same( 2, substr_count( rendu_vue_corrections(), 'class="yn-corrections__signalement"' ), 'statut inconnu : nouveaux' );
		yume_assert_same( 1, lister_coquilles( array( 'statut' => 'rejete' ) )['total'] );
		yume_assert_same( 3, lister_coquilles( array( 'statut' => 'tous' ) )['total'] );
	}
);

yume_tcor_test(
	'changer_statut() : refusé au traducteur (403), accepté pour l’éditeur ; Rouvrir ; traiter_chapitre()',
	function () {
		$c      = yume_tcor_contexte();
		$ed     = yume_factory_user( 'yume_editeur' );
		$trad   = yume_factory_user( 'yume_traducteur' );
		$id     = signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() )['id'];
		$emises = array();
		$ecoute = static function ( $i, $statut, $user ) use ( &$emises ) {
			$emises[] = array( $i, $statut, $user );
		};
		add_action( 'yume_coquille_traitee', $ecoute, 10, 3 );
		try {
			$refus = changer_statut( $id, 'corrige', $trad );
			yume_assert_true( is_wp_error( $refus ) );
			yume_assert_same( 403, $refus->get_error_data()['status'] );
			yume_assert_same( 'yume_coquille_statut', changer_statut( $id, 'inconnu', $ed )->get_error_code() );
			yume_assert_same( 'yume_coquille_introuvable', changer_statut( 999999, 'corrige', $ed )->get_error_code() );
			yume_assert_true( changer_statut( $id, 'corrige', $ed ) );
			$ligne = coquille( $id );
			yume_assert_same( 'corrige', $ligne['statut'] );
			yume_assert_same( $ed, $ligne['traite_par'] );
			yume_assert_true( '' !== $ligne['traite_le'] );
			yume_assert_true( changer_statut( $id, 'nouveau', $ed ) );
			yume_assert_same( 0, coquille( $id )['traite_par'] );
			yume_assert_same( '', coquille( $id )['traite_le'] );
		} finally {
			remove_action( 'yume_coquille_traitee', $ecoute, 10 );
		}
		yume_assert_same( array( array( $id, 'corrige', $ed ), array( $id, 'nouveau', $ed ) ), $emises );

		signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
		signaler_coquille(
			$c['chapitre'],
			yume_factory_user(),
			yume_tcor_donnees(
				array(
					'paragraphe' => 0,
					'extrait'    => 'le vent',
				)
			)
		);
		yume_assert_same( 0, traiter_chapitre( $c['chapitre'], 'corrige', $trad ) );
		yume_assert_same( 0, traiter_chapitre( $c['chapitre'], 'nouveau', $ed ) );
		yume_assert_same( 3, traiter_chapitre( $c['chapitre'], 'corrige', $ed ) );
		yume_assert_same( 0, compter_nouvelles() );
		yume_assert_same( 3, lister_coquilles( array( 'statut' => 'corrige' ) )['total'] );
	}
);

yume_tcor_test(
	'Actions admin-post (yume_correction, yume_correction_chapitre) : nonce, droits, messages',
	function () {
		$c    = yume_tcor_contexte();
		$ed   = yume_factory_user( 'yume_editeur' );
		$trad = yume_factory_user( 'yume_traducteur' );
		$id   = signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() )['id'];
		signaler_coquille(
			$c['chapitre'],
			yume_factory_user(),
			yume_tcor_donnees(
				array(
					'paragraphe' => 0,
					'extrait'    => 'le vent',
				)
			)
		);

		wp_set_current_user( $ed );
		$nonce = wp_create_nonce( 'yume_correction_' . $id );
		$r     = traiter_correction(
			array(
				'coquille'    => (string) $id,
				'op'          => 'corrige',
				'_yume_nonce' => 'mauvais',
			),
			$ed
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( 'yn-corrections-retour', $r['cible'] );
		$r = traiter_correction(
			array(
				'coquille'    => (string) $id,
				'op'          => 'rejete',
				'_yume_nonce' => $nonce,
			),
			$ed
		);
		yume_assert_same( 'ok', $r['type'], $r['message'] );
		yume_assert_same( 'rejete', coquille( $id )['statut'] );
		$r = traiter_correction(
			array(
				'coquille'    => (string) $id,
				'op'          => 'rouvrir',
				'_yume_nonce' => $nonce,
			),
			$ed
		);
		yume_assert_same( 'nouveau', coquille( $id )['statut'] );

		wp_set_current_user( $trad );
		$r = traiter_correction(
			array(
				'coquille'    => (string) $id,
				'op'          => 'corrige',
				'_yume_nonce' => wp_create_nonce( 'yume_correction_' . $id ),
			),
			$trad
		);
		yume_assert_same( 'erreur', $r['type'] );
		yume_assert_same( 'nouveau', coquille( $id )['statut'] );
		$r = traiter_correction_chapitre(
			array(
				'chapitre'    => (string) $c['chapitre'],
				'op'          => 'corrige',
				'_yume_nonce' => wp_create_nonce( 'yume_correction_chapitre_' . $c['chapitre'] ),
			),
			$trad
		);
		yume_assert_same( 'erreur', $r['type'] );

		wp_set_current_user( $ed );
		$r = traiter_correction_chapitre(
			array(
				'chapitre'    => (string) $c['chapitre'],
				'op'          => 'corrige',
				'_yume_nonce' => wp_create_nonce( 'yume_correction_chapitre_' . $c['chapitre'] ),
			),
			$ed
		);
		yume_assert_same( 'ok', $r['type'] );
		yume_assert_same( '2 signalements marqués corrigés.', $r['message'] );
		yume_assert_same( 0, compter_nouvelles() );
		yume_assert_true( has_action( 'admin_post_yume_correction' ) > 0 && has_action( 'admin_post_nopriv_yume_correction' ) > 0 );
		yume_assert_true( has_action( 'admin_post_yume_correction_chapitre' ) > 0 && has_action( 'admin_post_nopriv_yume_correction_chapitre' ) > 0 );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Notifications
 * -----------------------------------------------------------------------------
 */

yume_tcor_test(
	'Discord : message au webhook équipe, au plus un par chapitre et par 10 minutes (« +N autres »), réglage coquilles_discord',
	function () {
		$c = yume_tcor_contexte();
		yume_tcor_reglages( array( 'discord_webhook_equipe' => 'https://discord.com/api/webhooks/123456/jeton-test' ) );
		$a = yume_factory_user();
		wp_update_user(
			array(
				'ID'           => $a,
				'display_name' => 'Mio_des_Brumes',
			)
		);
		$appels = yume_tcor_http(
			static function () use ( $c, $a ) {
				signaler_coquille( $c['chapitre'], $a, yume_tcor_donnees() );
			}
		);
		yume_assert_same( 1, count( $appels ) );
		yume_assert_same( 'https://discord.com/api/webhooks/123456/jeton-test', $appels[0]['url'] );
		$corps = json_decode( $appels[0]['body'], true );
		yume_assert_contains( 'Coquille signalée — **Les Lanternes de Brume-Haute** · Tome 1 · Chapitre 2 — La Crête Brumeuse 2, ¶ 3', $corps['content'] );
		yume_assert_contains( ', ¶ 3 : « Il ne restait plus qu\'a attendre. » — par Mio\\_des\\_Brumes → <', $corps['content'] );
		yume_assert_contains( 'vue=corrections', $corps['content'] );

		// Dans la fenêtre de 10 minutes : rien, compté.
		$appels = yume_tcor_http(
			static function () use ( $c ) {
				signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
				signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
			}
		);
		yume_assert_same( 0, count( $appels ) );

		// Un autre chapitre n'est pas étouffé.
		$autre  = yume_tcor_chapitre( $c['tome'], 3 );
		$appels = yume_tcor_http(
			static function () use ( $autre ) {
				signaler_coquille( $autre, yume_factory_user(), yume_tcor_donnees() );
			}
		);
		yume_assert_same( 1, count( $appels ) );

		// Fenêtre écoulée : le message suivant compte les signalements tus.
		$cle  = 'yume_coquilles_discord_' . $c['chapitre'];
		$etat = get_transient( $cle );
		yume_assert_same( 2, $etat['attente'] );
		$etat['jusqua'] = time() - 1;
		set_transient( $cle, $etat, DAY_IN_SECONDS );
		$appels = yume_tcor_http(
			static function () use ( $c ) {
				signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
			}
		);
		yume_assert_same( 1, count( $appels ) );
		yume_assert_contains( '(+2 autres signalements sur ce chapitre)', json_decode( $appels[0]['body'], true )['content'] );

		// Réglage coupé : aucun appel.
		delete_transient( $cle );
		yume_tcor_reglages( array( 'coquilles_discord' => false ) );
		$appels = yume_tcor_http(
			static function () use ( $c ) {
				signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
			}
		);
		yume_assert_same( 0, count( $appels ) );
	}
);

yume_tcor_test(
	'Notification du lecteur à « corrigé » (cloche, type coquille) quand le module social accepte ce type',
	function () {
		$c  = yume_tcor_contexte();
		$u  = yume_factory_user();
		$ed = yume_factory_user( 'yume_editeur' );
		$id = signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees() )['id'];
		yume_assert_same( array(), notifier_lecteur( $id, 'rejete' ), 'rien pour « rejeté »' );
		yume_assert_true( changer_statut( $id, 'corrige', $ed ) );
		$accepte = defined( 'Yume\\Core\\Social\\TYPES_NOTIFICATION' ) && in_array( 'coquille', \Yume\Core\Social\TYPES_NOTIFICATION, true );
		$notes   = \Yume\Core\Social\notifications_utilisateur( $u );
		$trouvee = array_values( array_filter( $notes['notifications'] ?? array(), static fn( $n ): bool => 'coquille' === $n['type'] ) );
		if ( $accepte ) {
			yume_assert_same( 1, count( $trouvee ) );
			yume_assert_contains( 'a été corrigé, merci !', $trouvee[0]['titre'] );
			yume_assert_contains( '#yn-p-3', $trouvee[0]['url'] );
		} else {
			// Type « coquille » pas encore déclaré par le module social (TYPES_NOTIFICATION) :
			// l'appel est fait mais refusé sans erreur.
			yume_assert_same( array(), $trouvee );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * RGPD, purge, suppression
 * -----------------------------------------------------------------------------
 */

yume_tcor_test(
	'RGPD : export (clé signalements_coquilles, exportateur yume-core-coquilles), compte effacé ou supprimé → user_id 0',
	function () {
		$c  = yume_tcor_contexte();
		$u  = yume_factory_user();
		$id = signaler_coquille( $c['chapitre'], $u, yume_tcor_donnees() )['id'];

		$export = \Yume\Core\Social\donnees_personnelles( $u );
		yume_assert_same( 1, count( $export['signalements_coquilles'] ) );
		yume_assert_same( 3, $export['signalements_coquilles'][0]['paragraphe'] );
		yume_assert_same( 'Il ne restait plus qu\'a attendre.', $export['signalements_coquilles'][0]['extrait'] );
		yume_assert_true( isset( apply_filters( 'wp_privacy_personal_data_exporters', array() )['yume-core-coquilles'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		yume_assert_true( isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['yume-core-coquilles'] ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$items = exporter_donnees( get_userdata( $u )->user_email );
		yume_assert_same( 1, count( $items['data'] ) );
		yume_assert_same( 'yume-coquille-' . $id, $items['data'][0]['item_id'] );

		// Effaceur WordPress du module social → yume_effacer_donnees → anonymisation.
		\Yume\Core\Social\effacer_donnees( $u );
		yume_assert_same( 0, coquille( $id )['user_id'] );
		yume_assert_same( 'Compte supprimé', \Yume\Core\Corrections\auteur( coquille( $id ) ) );

		// Effaceur du module.
		$v   = yume_factory_user();
		$id2 = signaler_coquille( $c['chapitre'], $v, yume_tcor_donnees() )['id'];
		$r   = effacer_donnees_email( get_userdata( $v )->user_email );
		yume_assert_true( $r['items_removed'] );
		yume_assert_same( 0, coquille( $id2 )['user_id'] );

		// Compte supprimé dans wp-admin.
		$w   = yume_factory_user();
		$id3 = signaler_coquille(
			$c['chapitre'],
			$w,
			yume_tcor_donnees(
				array(
					'paragraphe' => 0,
					'extrait'    => 'le vent',
				)
			)
		)['id'];
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $w );
		yume_assert_same( 0, coquille( $id3 )['user_id'] );
		yume_assert_same( 0, anonymiser_coquilles( 0 ) );
	}
);

yume_tcor_test(
	'Purge : traités depuis plus de 180 jours supprimés (filtre yume_coquilles_retention), nouveaux gardés, empreintes d’IP de plus de 30 jours effacées ; tâche planifiée',
	function () {
		global $wpdb;
		$c      = yume_tcor_contexte();
		$ed     = yume_factory_user( 'yume_editeur' );
		$vieux  = signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() )['id'];
		$recent = signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() )['id'];
		$ouvert = signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() )['id'];
		changer_statut( $vieux, 'corrige', $ed );
		changer_statut( $recent, 'rejete', $ed );
		$table = table_coquilles();
		$date  = static fn( int $jours ): string => gmdate( 'Y-m-d H:i:s', time() - $jours * DAY_IN_SECONDS );
		$wpdb->update( $table, array( 'traite_le' => $date( 200 ) ), array( 'id' => $vieux ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $table, array( 'traite_le' => $date( 100 ) ), array( 'id' => $recent ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'cree_le' => $date( 400 ),
				'ip_hash' => str_repeat( 'a', 64 ),
			),
			array( 'id' => $ouvert )
		);
		yume_assert_same( 1, purger_coquilles() );
		yume_assert_true( null === coquille( $vieux ) );
		yume_assert_true( null !== coquille( $recent ) );
		yume_assert_same( '', coquille( $ouvert )['ip_hash'], 'empreinte d’IP effacée' );
		yume_assert_same( 'nouveau', coquille( $ouvert )['statut'], 'un signalement nouveau n’est jamais purgé' );

		$retention = static fn() => 90;
		add_filter( 'yume_coquilles_retention', $retention );
		try {
			lancer_purge();
		} finally {
			remove_filter( 'yume_coquilles_retention', $retention );
		}
		yume_assert_true( null === coquille( $recent ), 'rétention ramenée à 90 jours' );

		\Yume\Core\Corrections\planifier();
		yume_assert_true( false !== wp_next_scheduled( 'yume_corrections_purge' ) );
		yume_assert_same( 'Purge des signalements de coquilles traités (180 jours)', apply_filters( 'yume_taches_cron', array() )['yume_corrections_purge'] ?? '' );
	}
);

yume_tcor_test(
	'Chapitre supprimé définitivement → ses signalements aussi',
	function () {
		$c     = yume_tcor_contexte();
		$autre = yume_tcor_chapitre( $c['tome'], 3 );
		signaler_coquille( $c['chapitre'], yume_factory_user(), yume_tcor_donnees() );
		$garde = signaler_coquille( $autre, yume_factory_user(), yume_tcor_donnees() )['id'];
		wp_trash_post( $c['chapitre'] );
		yume_assert_same( 2, lister_coquilles( array( 'statut' => 'tous' ) )['total'], 'corbeille : gardés' );
		wp_delete_post( $c['chapitre'], true );
		yume_assert_same( 1, lister_coquilles( array( 'statut' => 'tous' ) )['total'] );
		yume_assert_true( null !== coquille( $garde ) );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Lecteur
 * -----------------------------------------------------------------------------
 */

yume_tcor_test(
	'wp_footer : fenêtre <dialog> seulement sur un chapitre texte publié ; visiteur : lien vers la connexion, HTML identique pour tous',
	function () {
		$c = yume_tcor_contexte();
		$u = yume_factory_user();

		yume_tcor_requete( $c['chapitre'] );
		wp_set_current_user( $u );
		$html = yume_tcor_pied();
		yume_assert_contains( '<dialog class="yn-coquilles"', $html );
		yume_assert_contains( 'data-yn-coquilles="', $html );
		yume_assert_contains( 'hidden data-yn-coquilles-flottant', $html );
		yume_assert_contains( 'role="status" aria-live="polite"', $html );
		yume_assert_contains( 'Votre pseudo sera visible de l’équipe seulement.', $html );
		preg_match( '/data-yn-coquilles="([^"]*)"/', $html, $m );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		yume_assert_same( $c['chapitre'], $config['chapitre'] );
		yume_assert_contains( '/yume/v1/chapitres/' . $c['chapitre'] . '/coquilles', $config['rest'] );
		yume_assert_true( '' !== $config['nonce'] );
		yume_assert_same( '', $config['connexion'] );

		wp_set_current_user( 0 );
		$visiteur = yume_tcor_pied();
		yume_assert_not_contains( '<dialog', $visiteur );
		yume_assert_contains( 'Connectez-vous pour signaler une coquille', $visiteur );
		yume_assert_contains( 'yn-p-999999', $visiteur );
		yume_assert_not_contains( '"nonce":"', str_replace( '"nonce":""', '', html_entity_decode( $visiteur, ENT_QUOTES ) ), 'aucun nonce pour un visiteur' );
		yume_assert_same( $visiteur, yume_tcor_pied(), 'HTML identique d’un visiteur à l’autre (cache de page)' );

		yume_tcor_requete( yume_tcor_chapitre( $c['tome'], 3, 'draft' ) );
		yume_assert_same( '', yume_tcor_pied(), 'brouillon' );
		$manga = yume_tcor_chapitre( $c['tome'], 4 );
		update_post_meta( $manga, 'yume_mdx_chapitre', '0b9a2c1e-1234-4abc-8def-1234567890ab' );
		yume_tcor_requete( $manga );
		yume_assert_same( '', yume_tcor_pied(), 'MangaDex' );
		yume_tcor_requete( $c['tome'] );
		yume_assert_same( '', yume_tcor_pied(), 'page d’un tome' );

		yume_tcor_requete( $c['chapitre'] );
		yume_tcor_reglages( array( 'coquilles_actives' => false ) );
		yume_assert_same( '', yume_tcor_pied(), 'signalement désactivé' );
	}
);

yume_tcor_test(
	'Lien « Signaler une coquille dans ce chapitre » après yume/chapter-nav (masqué sans JavaScript), script chargé sur le chapitre',
	function () {
		$c = yume_tcor_contexte();
		yume_tcor_requete( $c['chapitre'] );
		$nav  = '<nav class="yn-chapter-nav">…</nav>';
		$html = apply_filters( 'render_block_yume/chapter-nav', $nav, array( 'blockName' => 'yume/chapter-nav' ), null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores
		yume_assert_same( 0, strpos( $html, $nav ), 'après la navigation' );
		yume_assert_contains( '<p class="yn-coquilles-fin yn-muted" hidden data-yn-coquilles-fin>', $html );
		yume_assert_contains( '<button type="button" class="yn-coquilles-fin__bouton" data-yn-coquilles-ouvrir>Signaler une coquille dans ce chapitre</button>', $html );
		yume_assert_contains( 'Une coquille ? Sélectionnez le passage puis « Signaler une coquille ».', $html );
		yume_assert_same( '', lien_fin_de_chapitre( '' ) );

		charger_assets();
		yume_assert_true( wp_script_is( 'yume-coquilles', 'enqueued' ) );
		yume_assert_true( wp_style_is( 'yume-coquilles', 'enqueued' ) );

		yume_tcor_requete( $c['tome'] );
		yume_assert_same( $nav, lien_fin_de_chapitre( $nav ), 'hors chapitre : inchangé' );
	}
);

yume_tcor_test(
	'Réglages : section « Lecteurs » et champs coquilles_actives (vrai), coquilles_anonymes (faux), coquilles_discord (vrai) ; table dans uninstall.php',
	function () {
		yume_assert_same( 'Lecteurs', apply_filters( 'yume_reglages_sections', array() )['lecteurs'] ?? '' );
		$champs = array();
		foreach ( apply_filters( 'yume_reglages_champs', array() ) as $champ ) {
			$champs[ $champ['key'] ] = $champ;
		}
		foreach ( array(
			'coquilles_actives'  => true,
			'coquilles_anonymes' => false,
			'coquilles_discord'  => true,
		) as $cle => $defaut ) {
			yume_assert_true( isset( $champs[ $cle ] ), $cle );
			yume_assert_same( 'checkbox', $champs[ $cle ]['type'] );
			yume_assert_same( 'lecteurs', $champs[ $cle ]['section'] );
			yume_assert_same( $defaut, $champs[ $cle ]['default'] );
		}
		yume_assert_contains( "'coquilles',", (string) file_get_contents( YUME_CORE_DIR . 'uninstall.php' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
);
