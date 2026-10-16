<?php
/**
 * Tests du module mangadex : client de l'API MangaDex (flux paginé du groupe, serveur MD@Home
 * en cache, normalisation, erreurs sans nouvel essai), User-Agent réel, réglage du groupe.
 *
 * Aucune requête ne sort : l'API est simulée par le filtre pre_http_request, et toute adresse
 * non prévue par un test fait échouer ce test.
 *
 * Lancement : tools/localenv/test.sh mangadex
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\MangaDex\Client;
use const Yume\Core\MangaDex\GROUPE_YUME;
use function Yume\Core\MangaDex\extraire_uuid;
use function Yume\Core\MangaDex\groupe;

// Module non chargé (YUME_ONLY_MODULES sans « mangadex ») : rien à tester.
if ( ! class_exists( Client::class ) ) {
	return;
}

/*
 * -----------------------------------------------------------------------------
 * Aides propres à ces tests (préfixe yume_tmdx_)
 * -----------------------------------------------------------------------------
 */

/** Série d'essai (Mikadono Sanshimai wa Angai, Choroi.). */
const YUME_TMDX_SERIE = '04c892b3-a202-46f5-ad11-0d433e712ab8';

/**
 * Identifiant MangaDex d'essai déterministe.
 *
 * @param int $n Numéro.
 */
function yume_tmdx_uuid( int $n ): string {
	return sprintf( '%08x-0000-4000-8000-%012x', $n, $n );
}

/**
 * Ressource « chapter » de l'API (forme réelle de MangaDex).
 *
 * @param int                 $n         Numéro (identifiant).
 * @param array<string,mixed> $attributs Attributs à remplacer.
 * @return array<string,mixed>
 */
function yume_tmdx_chapitre( int $n, array $attributs = array() ): array {
	return array(
		'id'            => yume_tmdx_uuid( $n ),
		'type'          => 'chapter',
		'attributes'    => array_merge(
			array(
				'volume'             => '1',
				'chapter'            => (string) $n,
				'title'              => null,
				'translatedLanguage' => 'fr',
				'externalUrl'        => null,
				'pages'              => 20 + $n,
				'readableAt'         => '2025-03-0' . min( 9, $n ) . 'T12:00:00+00:00',
				'updatedAt'          => '2025-03-0' . min( 9, $n ) . 'T12:00:00+00:00',
			),
			$attributs
		),
		'relationships' => array(
			array(
				'id'         => YUME_TMDX_SERIE,
				'type'       => 'manga',
				'attributes' => array(
					'title' => array( 'ja-ro' => 'Mikadono Sanshimai wa Angai, Choroi.' ),
				),
			),
			array(
				'id'         => GROUPE_YUME,
				'type'       => 'scanlation_group',
				'attributes' => array( 'name' => 'Yume Novel' ),
			),
		),
	);
}

/**
 * Réponse HTTP simulée.
 *
 * @param int   $code  Code HTTP.
 * @param mixed $corps Corps (tableau encodé en JSON, ou texte).
 * @return array<string,mixed>
 */
function yume_tmdx_reponse( int $code, $corps ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => is_array( $corps ) ? (string) wp_json_encode( $corps ) : (string) $corps,
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

/**
 * Simule l'API : $routeur reçoit l'adresse et renvoie une réponse (yume_tmdx_reponse()), une
 * WP_Error, ou null pour une adresse non prévue (le test échoue). Les requêtes sont notées.
 *
 * Une simulation installée plus tôt (priorité plus basse, nombre plus grand) laisse passer la
 * réponse d'une simulation plus prioritaire.
 *
 * @param callable $routeur  Routeur ( string $url, array $args ) : array|WP_Error|null.
 * @param int      $priorite Priorité du filtre.
 * @return stdClass {requetes: array<int,array{url:string,args:array}>, imprevues: string[], filtre: callable}
 */
function yume_tmdx_api( callable $routeur, int $priorite = 10 ): stdClass {
	$etat            = new stdClass();
	$etat->requetes  = array();
	$etat->imprevues = array();
	$etat->priorite  = $priorite;
	$etat->filtre    = static function ( $pre, $args, $url ) use ( $routeur, $etat ) {
		if ( false !== $pre ) {
			return $pre; // Déjà simulée par un filtre plus prioritaire.
		}
		$etat->requetes[] = array(
			'url'  => (string) $url,
			'args' => (array) $args,
		);
		$reponse          = $routeur( (string) $url, (array) $args );
		if ( null === $reponse ) {
			$etat->imprevues[] = (string) $url;
			return new WP_Error( 'yume_tmdx_imprevue', 'Adresse non simulée : ' . $url );
		}
		return $reponse;
	};
	add_filter( 'pre_http_request', $etat->filtre, $priorite, 3 );
	return $etat;
}

/**
 * Retire la simulation et vérifie qu'aucune adresse imprévue n'a été demandée.
 *
 * @param stdClass $etat État de yume_tmdx_api().
 */
function yume_tmdx_fin( stdClass $etat ): void {
	remove_filter( 'pre_http_request', $etat->filtre, $etat->priorite );
	yume_assert_same( array(), $etat->imprevues, 'aucune requête hors de la simulation' );
}

/**
 * Paramètres de requête d'une adresse (clés telles quelles, valeurs multiples en liste).
 *
 * @param string $url Adresse.
 * @return array<string,string[]>
 */
function yume_tmdx_parametres( string $url ): array {
	$parametres = array();
	foreach ( explode( '&', (string) wp_parse_url( $url, PHP_URL_QUERY ) ) as $paire ) {
		if ( '' === $paire ) {
			continue;
		}
		list( $cle, $valeur )                 = array_pad( explode( '=', $paire, 2 ), 2, '' );
		$parametres[ rawurldecode( $cle ) ][] = rawurldecode( $valeur );
	}
	return $parametres;
}

/*
 * -----------------------------------------------------------------------------
 * Tests
 * -----------------------------------------------------------------------------
 */

yume_test(
	'mangadex : module chargé, réglage « Groupe MangaDex » (Yume Novel par défaut, adresse acceptée, saisie invalide → défaut)',
	function () {
		yume_assert_true( function_exists( 'Yume\Core\MangaDex\groupe' ) );
		yume_assert_same( '4bbecb8b-41bb-48a8-8ddb-0c9243e4c344', GROUPE_YUME );
		yume_assert_same( GROUPE_YUME, groupe(), 'défaut' );
		$champs = array_column( \Yume\Core\Core\champs_reglages(), null, 'key' );
		yume_assert_true( isset( $champs['mangadex_groupe'] ), 'champ déclaré' );
		yume_assert_same( 'mangadex', $champs['mangadex_groupe']['section'] );
		yume_assert_true( isset( \Yume\Core\Core\sections_reglages()['mangadex'] ), 'section Mangas (MangaDex)' );
		$assainir = $champs['mangadex_groupe']['sanitize'];
		yume_assert_same( yume_tmdx_uuid( 7 ), $assainir( 'https://mangadex.org/group/' . strtoupper( yume_tmdx_uuid( 7 ) ) . '/yume-novel' ) );
		yume_assert_same( GROUPE_YUME, $assainir( 'pas un identifiant' ) );

		$reglages = get_option( 'yume_reglages', array() );
		try {
			update_option( 'yume_reglages', array_merge( is_array( $reglages ) ? $reglages : array(), array( 'mangadex_groupe' => yume_tmdx_uuid( 9 ) ) ) );
			yume_assert_same( yume_tmdx_uuid( 9 ), groupe(), 'réglage enregistré' );
			update_option( 'yume_reglages', array_merge( is_array( $reglages ) ? $reglages : array(), array( 'mangadex_groupe' => 'abîmé' ) ) );
			yume_assert_same( GROUPE_YUME, groupe(), 'valeur abîmée → défaut' );
		} finally {
			update_option( 'yume_reglages', $reglages );
		}

		yume_assert_same( '', extraire_uuid( array( 'x' ) ) );
		yume_assert_same( YUME_TMDX_SERIE, extraire_uuid( 'https://mangadex.org/title/' . YUME_TMDX_SERIE . '/mikadono' ) );
	}
);

yume_test(
	'mangadex : chapitres du groupe — toutes les pages lues (offset), paramètres attendus, normalisation (série, volume, numéro, pages, version, groupe, chapitre externe, oneshot)',
	function () {
		$tous = array();
		for ( $n = 1; $n <= 150; $n++ ) {
			$tous[] = yume_tmdx_chapitre( $n );
		}
		$tous[149]['attributes']['volume']      = null;
		$tous[149]['attributes']['chapter']     = null;
		$tous[148]['attributes']['externalUrl'] = 'https://mangaplus.shueisha.co.jp/viewer/1';
		$api                                    = yume_tmdx_api(
			static function ( string $url ) use ( $tous ) {
				if ( 0 !== strpos( $url, Client::API . '/chapter?' ) ) {
					return null;
				}
				$p      = yume_tmdx_parametres( $url );
				$offset = (int) ( $p['offset'][0] ?? 0 );
				$limit  = (int) ( $p['limit'][0] ?? 10 );
				return yume_tmdx_reponse(
					200,
					array(
						'result' => 'ok',
						'data'   => array_slice( $tous, $offset, $limit ),
						'limit'  => $limit,
						'offset' => $offset,
						'total'  => count( $tous ),
					)
				);
			}
		);
		try {
			$chapitres = Client::chapitres_du_groupe( GROUPE_YUME );
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_false( is_wp_error( $chapitres ), is_wp_error( $chapitres ) ? $chapitres->get_error_message() : '' );
		yume_assert_same( 150, count( $chapitres ), 'deux pages lues' );
		yume_assert_same( 2, count( $api->requetes ) );
		$p = yume_tmdx_parametres( $api->requetes[0]['url'] );
		yume_assert_same( array( GROUPE_YUME ), $p['groups[]'] );
		yume_assert_same( array( 'fr' ), $p['translatedLanguage[]'] );
		yume_assert_same( array( 'safe', 'suggestive', 'erotica' ), $p['contentRating[]'] );
		yume_assert_same( array( 'manga', 'scanlation_group' ), $p['includes[]'] );
		yume_assert_same( array( 'asc' ), $p['order[readableAt]'] );
		yume_assert_same( array( '100' ), $p['limit'] );
		yume_assert_same( array( '100' ), yume_tmdx_parametres( $api->requetes[1]['url'] )['offset'] );

		$premier = $chapitres[0];
		yume_assert_same( yume_tmdx_uuid( 1 ), $premier['id'] );
		yume_assert_same(
			array(
				'id'    => YUME_TMDX_SERIE,
				'titre' => 'Mikadono Sanshimai wa Angai, Choroi.',
			),
			$premier['serie']
		);
		yume_assert_same( '1', $premier['volume'] );
		yume_assert_same( '1', $premier['chapitre'] );
		yume_assert_same( '', $premier['titre'], 'titre null → vide' );
		yume_assert_same( 21, $premier['pages'] );
		yume_assert_same( '', $premier['externe'] );
		yume_assert_same( '2025-03-01T12:00:00+00:00', $premier['publie'] );
		yume_assert_true( 1 === preg_match( '/^[0-9a-f]{8}$/', $premier['version'] ), 'version sur 8 caractères' );
		yume_assert_same(
			array(
				array(
					'id'  => GROUPE_YUME,
					'nom' => 'Yume Novel',
				),
			),
			$premier['groupes']
		);
		yume_assert_same( 'https://mangaplus.shueisha.co.jp/viewer/1', $chapitres[148]['externe'], 'chapitre externe repéré' );
		yume_assert_same( array( '', '' ), array( $chapitres[149]['volume'], $chapitres[149]['chapitre'] ), 'oneshot sans volume ni numéro' );

		// La version change quand MangaDex met le chapitre à jour, pas autrement.
		$modifie = yume_tmdx_chapitre( 1, array( 'updatedAt' => '2026-10-16T08:00:00+00:00' ) );
		yume_assert_same( $premier['version'], Client::normaliser_chapitre( yume_tmdx_chapitre( 1 ) )['version'] );
		yume_assert_true( Client::normaliser_chapitre( $modifie )['version'] !== $premier['version'] );

		// Requêtes conformes aux règles de MangaDex : User-Agent réel, pas d'en-tête Via.
		foreach ( $api->requetes as $requete ) {
			yume_assert_contains( 'YumeNovel/' . YUME_CORE_VERSION, (string) $requete['args']['user-agent'] );
			yume_assert_false( isset( $requete['args']['headers']['Via'] ) || isset( $requete['args']['headers']['via'] ), 'pas de Via' );
		}
	}
);

yume_test(
	'mangadex : erreurs — 429 et 403 sans nouvel essai, 404, réponse illisible, réseau, groupe invalide (aucun appel)',
	function () {
		$cas = array(
			'429'       => array( yume_tmdx_reponse( 429, array( 'result' => 'error' ) ), 'yume_mdx_limite', 503 ),
			'403'       => array( yume_tmdx_reponse( 403, 'Forbidden' ), 'yume_mdx_limite', 503 ),
			'404'       => array(
				yume_tmdx_reponse(
					404,
					array(
						'result' => 'error',
						'errors' => array( array( 'detail' => 'Not found' ) ),
					)
				),
				'yume_mdx_api',
				404,
			),
			'illisible' => array( yume_tmdx_reponse( 200, '<html>maintenance</html>' ), 'yume_mdx_api', 502 ),
			'réseau'    => array( new WP_Error( 'http_request_failed', 'cURL error 28' ), 'yume_mdx_reseau', 502 ),
		);
		foreach ( $cas as $nom => list( $reponse, $code, $statut ) ) {
			$api = yume_tmdx_api(
				static function () use ( $reponse ) {
					return $reponse;
				}
			);
			try {
				$resultat = Client::chapitres_du_groupe( GROUPE_YUME );
			} finally {
				yume_tmdx_fin( $api );
			}
			yume_assert_true( is_wp_error( $resultat ), $nom );
			yume_assert_same( $code, $resultat->get_error_code(), $nom );
			yume_assert_same( $statut, $resultat->get_error_data()['status'] ?? null, $nom );
			yume_assert_same( 1, count( $api->requetes ), $nom . ' : un seul appel, jamais de nouvel essai' );
		}
		yume_assert_contains(
			'Not found',
			( function () {
				$api = yume_tmdx_api(
					static function () {
						return yume_tmdx_reponse(
							404,
							array(
								'result' => 'error',
								'errors' => array( array( 'detail' => 'Not found' ) ),
							)
						);
					}
				);
				try {
					return Client::chapitres_du_groupe( GROUPE_YUME )->get_error_message();
				} finally {
					yume_tmdx_fin( $api );
				}
			} )(),
			'détail de MangaDex repris'
		);

		$api = yume_tmdx_api(
			static function () {
				return null;
			}
		);
		try {
			$resultat = Client::chapitres_du_groupe( 'pas-un-uuid' );
			yume_assert_same( 'yume_mdx_id', $resultat->get_error_code() );
			yume_assert_same( 0, count( $api->requetes ), 'aucun appel' );
		} finally {
			yume_tmdx_fin( $api );
		}
	}
);

yume_test(
	'mangadex : serveur MD@Home — en cache 10 min, nouveau nœud sur demande, réponse invalide refusée, noms de fichiers contrôlés',
	function () {
		$chapitre = yume_tmdx_uuid( 3 );
		delete_transient( 'yume_mdx_ah_' . $chapitre );
		$noeud = 1;
		$api   = yume_tmdx_api(
			static function ( string $url ) use ( $chapitre, &$noeud ) {
				if ( Client::API . '/at-home/server/' . $chapitre !== $url ) {
					return null;
				}
				return yume_tmdx_reponse(
					200,
					array(
						'result'  => 'ok',
						'baseUrl' => 'https://noeud' . ( $noeud++ ) . '.mangadex.network',
						'chapter' => array(
							'hash'      => str_repeat( 'ab', 16 ),
							'data'      => array( '1-aa.jpg', '2-bb.png', '../etc/passwd', 'x.php' ),
							'dataSaver' => array( '1-cc.jpg', '2-dd.webp' ),
						),
					)
				);
			}
		);
		try {
			$ah = Client::at_home( $chapitre );
			yume_assert_same( 'https://noeud1.mangadex.network', $ah['baseUrl'] );
			yume_assert_same( array( '1-aa.jpg', '2-bb.png' ), $ah['data'], 'chemins et extensions douteux écartés' );
			yume_assert_same( array( '1-cc.jpg', '2-dd.webp' ), $ah['dataSaver'] );
			yume_assert_same( $ah, Client::at_home( $chapitre ), 'en cache' );
			yume_assert_same( 1, count( $api->requetes ), 'un seul appel pour deux lectures' );
			yume_assert_same( 'https://noeud2.mangadex.network', Client::at_home( $chapitre, true )['baseUrl'], 'nouveau nœud forcé' );
			yume_assert_same( 2, count( $api->requetes ) );
			yume_assert_same( 'yume_mdx_id', Client::at_home( 'nimporte' )->get_error_code() );
			yume_assert_same( 2, count( $api->requetes ), 'identifiant invalide : aucun appel' );
		} finally {
			yume_tmdx_fin( $api );
			delete_transient( 'yume_mdx_ah_' . $chapitre );
		}

		foreach ( array( 'http://noeud.mangadex.network', 'javascript:alert(1)' ) as $base ) {
			$api = yume_tmdx_api(
				static function () use ( $base ) {
					return yume_tmdx_reponse(
						200,
						array(
							'result'  => 'ok',
							'baseUrl' => $base,
							'chapter' => array(
								'hash' => str_repeat( 'ab', 16 ),
								'data' => array( '1.jpg' ),
							),
						)
					);
				}
			);
			try {
				$ah = Client::at_home( $chapitre );
			} finally {
				yume_tmdx_fin( $api );
			}
			yume_assert_same( 'yume_mdx_at_home', is_wp_error( $ah ) ? $ah->get_error_code() : 'accepté', $base );
			yume_assert_false( get_transient( 'yume_mdx_ah_' . $chapitre ), 'rien en cache' );
		}
	}
);

yume_test(
	'mangadex : modèle de données — métas déclarées (types, REST), assainissement (adresse → identifiant, saisie invalide → vide, version sur 8 caractères, groupes)',
	function () {
		$oeuvre_metas   = get_registered_meta_keys( 'post', 'yume_oeuvre' );
		$chapitre_metas = get_registered_meta_keys( 'post', 'yume_chapitre' );
		yume_assert_same( 'string', $oeuvre_metas['yume_mdx_serie']['type'] ?? null );
		yume_assert_same( 'string', $chapitre_metas['yume_mdx_chapitre']['type'] ?? null );
		yume_assert_same( 'integer', $chapitre_metas['yume_mdx_pages']['type'] ?? null );
		yume_assert_same( 'string', $chapitre_metas['yume_mdx_version']['type'] ?? null );
		yume_assert_same( 'array', $chapitre_metas['yume_mdx_groupes']['type'] ?? null );
		foreach ( array( $oeuvre_metas['yume_mdx_serie'], $chapitre_metas['yume_mdx_chapitre'], $chapitre_metas['yume_mdx_groupes'] ) as $def ) {
			yume_assert_true( ! empty( $def['single'] ) && ! empty( $def['show_in_rest'] ) );
		}

		$oeuvre   = yume_factory_post(
			array(
				'post_type'  => 'yume_oeuvre',
				'post_title' => 'Mikadono',
			)
		);
		$chapitre = yume_factory_post(
			array(
				'post_type'  => 'yume_chapitre',
				'post_title' => 'Chapitre 1',
			)
		);
		update_post_meta( $oeuvre, 'yume_mdx_serie', 'https://mangadex.org/title/' . strtoupper( YUME_TMDX_SERIE ) . '/mikadono-sanshimai' );
		yume_assert_same( YUME_TMDX_SERIE, get_post_meta( $oeuvre, 'yume_mdx_serie', true ), 'adresse → identifiant en minuscules' );
		update_post_meta( $oeuvre, 'yume_mdx_serie', '<script>pas un uuid</script>' );
		yume_assert_same( '', get_post_meta( $oeuvre, 'yume_mdx_serie', true ) );

		update_post_meta( $chapitre, 'yume_mdx_chapitre', yume_tmdx_uuid( 5 ) );
		update_post_meta( $chapitre, 'yume_mdx_pages', '59' );
		update_post_meta( $chapitre, 'yume_mdx_version', 'zzzzzzzz' );
		yume_assert_same( '', get_post_meta( $chapitre, 'yume_mdx_version', true ), 'version invalide refusée' );
		update_post_meta( $chapitre, 'yume_mdx_version', 'a1b2c3d4' );
		update_post_meta( $chapitre, 'yume_mdx_groupes', "Yume Novel\nYume Novel\n<b>Autre</b>" );
		yume_assert_same( 59, (int) get_post_meta( $chapitre, 'yume_mdx_pages', true ) );
		yume_assert_same( 'a1b2c3d4', get_post_meta( $chapitre, 'yume_mdx_version', true ) );
		yume_assert_same( array( 'Yume Novel', 'Autre' ), get_post_meta( $chapitre, 'yume_mdx_groupes', true ), 'sans doublon ni balise' );
	}
);

yume_test(
	'mangadex : API — yume_mangadex_chapitre (null pour un chapitre importé), yume_mangadex_serie, œuvre par série et chapitre par identifiant (corbeille exclue)',
	function () {
		$oeuvre   = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		$importe  = yume_factory_post( array( 'post_type' => 'yume_chapitre' ) );
		$mdx      = yume_factory_post( array( 'post_type' => 'yume_chapitre' ) );
		$jete     = yume_factory_post( array( 'post_type' => 'yume_chapitre' ) );
		$serie    = yume_tmdx_uuid( 41 );
		$uuid     = yume_tmdx_uuid( 42 );
		$uuid_bis = yume_tmdx_uuid( 43 );
		update_post_meta( $oeuvre, 'yume_mdx_serie', $serie );
		update_post_meta( $mdx, 'yume_mdx_chapitre', $uuid );
		update_post_meta( $mdx, 'yume_mdx_pages', 21 );
		update_post_meta( $mdx, 'yume_mdx_version', '0badc0de' );
		update_post_meta( $mdx, 'yume_mdx_groupes', array( 'Yume Novel' ) );
		update_post_meta( $jete, 'yume_mdx_chapitre', $uuid_bis );
		wp_trash_post( $jete );

		yume_assert_same( null, yume_mangadex_chapitre( $importe ), 'chapitre importé' );
		yume_assert_same( null, yume_mangadex_chapitre( $oeuvre ), 'pas un chapitre' );
		yume_assert_same(
			array(
				'uuid'    => $uuid,
				'pages'   => 21,
				'version' => '0badc0de',
				'groupes' => array( 'Yume Novel' ),
				'url'     => 'https://mangadex.org/chapter/' . $uuid,
			),
			yume_mangadex_chapitre( $mdx )
		);
		yume_assert_same( $serie, yume_mangadex_serie( $oeuvre ) );
		yume_assert_same( '', yume_mangadex_serie( $mdx ), 'pas une œuvre' );
		yume_assert_same( $oeuvre, yume_mangadex_oeuvre_par_serie( strtoupper( $serie ) ) );
		yume_assert_same( 0, yume_mangadex_oeuvre_par_serie( yume_tmdx_uuid( 999 ) ) );
		yume_assert_same( 0, yume_mangadex_oeuvre_par_serie( 'nimporte' ) );
		yume_assert_same( $mdx, yume_mangadex_chapitre_par_uuid( $uuid ) );
		yume_assert_same( 0, yume_mangadex_chapitre_par_uuid( $uuid_bis ), 'corbeille exclue' );
	}
);

yume_test(
	'mangadex : boîte « MangaDex » de l’œuvre — enregistrement (jeton et droits), effacement, adresse acceptée, refus sans jeton ou sans droit, avertissement si la série est déjà liée',
	function () {
		$admin   = yume_factory_user( 'administrator' );
		$abonne  = yume_factory_user( 'subscriber' );
		$premier = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		$oeuvre  = yume_factory_post( array( 'post_type' => 'yume_oeuvre' ) );
		update_post_meta( $premier, 'yume_mdx_serie', YUME_TMDX_SERIE );
		$poster = static function ( array $champs ) use ( $oeuvre ) {
			$_POST = $champs;
			\Yume\Core\MangaDex\enregistrer_oeuvre( $oeuvre );
			$_POST = array();
		};
		try {
			wp_set_current_user( $admin );
			$poster(
				array(
					'_yume_mdx_nonce' => wp_create_nonce( \Yume\Core\MangaDex\NONCE_OEUVRE ),
					'yume_mdx'        => array( 'serie' => 'https://mangadex.org/title/' . YUME_TMDX_SERIE ),
				)
			);
			yume_assert_same( YUME_TMDX_SERIE, yume_mangadex_serie( $oeuvre ), 'adresse enregistrée en identifiant' );

			ob_start();
			\Yume\Core\MangaDex\boite_oeuvre( get_post( $oeuvre ) );
			$html = (string) ob_get_clean();
			yume_assert_contains( 'value="' . YUME_TMDX_SERIE . '"', $html );
			yume_assert_contains( 'https://mangadex.org/title/' . YUME_TMDX_SERIE, $html );
			yume_assert_contains( 'déjà liée', $html, 'série partagée signalée' );

			$poster(
				array(
					'yume_mdx' => array( 'serie' => '' ),
				)
			);
			yume_assert_same( YUME_TMDX_SERIE, yume_mangadex_serie( $oeuvre ), 'sans jeton : rien ne change' );

			wp_set_current_user( $abonne );
			$poster(
				array(
					'_yume_mdx_nonce' => wp_create_nonce( \Yume\Core\MangaDex\NONCE_OEUVRE ),
					'yume_mdx'        => array( 'serie' => '' ),
				)
			);
			yume_assert_same( YUME_TMDX_SERIE, yume_mangadex_serie( $oeuvre ), 'sans droit : rien ne change' );

			wp_set_current_user( $admin );
			$poster(
				array(
					'_yume_mdx_nonce' => wp_create_nonce( \Yume\Core\MangaDex\NONCE_OEUVRE ),
					'yume_mdx'        => array( 'serie' => '' ),
				)
			);
			yume_assert_same( '', yume_mangadex_serie( $oeuvre ), 'champ vidé : lien retiré' );
			yume_assert_false( metadata_exists( 'post', $oeuvre, 'yume_mdx_serie' ) );
		} finally {
			$_POST = array();
			wp_set_current_user( 0 );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Synchronisation (étape 3)
 * -----------------------------------------------------------------------------
 */

use Yume\Core\MangaDex\Synchro;

/**
 * Chapitre MangaDex normalisé d'essai.
 *
 * @param int                 $n         Numéro (identifiant).
 * @param array<string,mixed> $attributs Attributs MangaDex à remplacer.
 * @param string              $serie     Série.
 * @return array<string,mixed>
 */
function yume_tmdx_norm( int $n, array $attributs = array(), string $serie = YUME_TMDX_SERIE ): array {
	$chapitre                = Client::normaliser_chapitre( yume_tmdx_chapitre( $n, $attributs ) );
	$chapitre['serie']['id'] = $serie;
	return $chapitre;
}

/**
 * Date ISO 8601 d'il y a $secondes.
 *
 * @param int $secondes Secondes.
 */
function yume_tmdx_il_y_a( int $secondes ): string {
	return gmdate( 'Y-m-d\TH:i:s+00:00', time() - $secondes );
}

/**
 * Œuvre publiée liée à une série.
 *
 * @param string $serie  Série.
 * @param string $statut Statut.
 * @param string $titre  Titre.
 */
function yume_tmdx_oeuvre( string $serie = YUME_TMDX_SERIE, string $statut = 'publish', string $titre = 'Mikadono' ): int {
	$oeuvre = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => $titre,
			'post_status' => $statut,
		)
	);
	update_post_meta( $oeuvre, 'yume_mdx_serie', $serie );
	return $oeuvre;
}

/**
 * Flux de départ : vol. 1 ch. 1-2 (anciens), vol. 2 ch. 3, ch. 4 sans volume (récent), ch. 5
 * externe, et un chapitre d'une série liée à aucune œuvre.
 *
 * @return array<int,array<string,mixed>>
 */
function yume_tmdx_flux_depart(): array {
	return array(
		yume_tmdx_norm( 1 ),
		yume_tmdx_norm( 2 ),
		yume_tmdx_norm( 3, array( 'volume' => '2' ) ),
		yume_tmdx_norm(
			4,
			array(
				'volume'     => null,
				'readableAt' => yume_tmdx_il_y_a( 3600 ),
				'updatedAt'  => yume_tmdx_il_y_a( 3600 ),
			)
		),
		yume_tmdx_norm(
			5,
			array(
				'volume'      => '2',
				'externalUrl' => 'https://mangaplus.shueisha.co.jp/viewer/5',
			)
		),
		yume_tmdx_norm( 6, array(), yume_tmdx_uuid( 77 ) ),
	);
}

/**
 * Déclare un test de synchronisation : aucune requête HTTP ni e-mail ne sort, contenus Yume vidés.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 */
function yume_tmdx_test_synchro( string $nom, callable $corps ): void {
	yume_test(
		$nom,
		static function () use ( $corps ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type IN ( 'yume_oeuvre', 'yume_tome', 'yume_chapitre' )" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			delete_option( Synchro::OPTION_RAPPORT );
			delete_option( Synchro::VERROU );
			$api      = yume_tmdx_api(
				static function () {
					return null;
				},
				20
			);
			$courrier = static function () {
				return true;
			};
			add_filter( 'pre_wp_mail', $courrier );
			try {
				$corps();
			} finally {
				remove_filter( 'pre_wp_mail', $courrier );
				yume_tmdx_fin( $api );
				delete_option( Synchro::VERROU );
			}
		}
	);
}

/**
 * Nouvelle requête simulée : la synchronisation horaire tourne chaque fois dans sa propre
 * requête (le socle note les tomes publiés pendant la requête pour regrouper leurs annonces).
 */
function yume_tmdx_nouvelle_requete(): void {
	\Yume\Core\Core\etat_set( 'publies_yume_tome', array() );
	\Yume\Core\Core\etat_set( 'publies_yume_chapitre', array() );
}

/**
 * Événements de publication émis jusqu'ici.
 *
 * @return array{tome:int,chapitre:int}
 */
function yume_tmdx_evenements(): array {
	return array(
		'tome'     => did_action( 'yume_tome_publie' ),
		'chapitre' => did_action( 'yume_chapitre_publie' ),
	);
}

yume_tmdx_test_synchro(
	'mangadex : synchronisation — import initial (tomes par volume, tome « Chapitres », chapitres datés de MangaDex, aucune annonce même récente), série non liée signalée, chapitre externe ignoré, relance sans effet',
	function () {
		$oeuvre    = yume_tmdx_oeuvre();
		$evenement = yume_tmdx_evenements();
		$rapport   = Synchro::executer( false, yume_tmdx_flux_depart() );
		yume_assert_true( $rapport['ok'], (string) $rapport['erreur'] );
		yume_assert_same( 4, $rapport['crees'] );
		yume_assert_same( 3, $rapport['tomes_crees'] );
		yume_assert_same( 1, $rapport['externes'] );
		yume_assert_same( 0, $rapport['retires'] );
		yume_assert_same( array( yume_tmdx_uuid( 77 ) ), array_column( $rapport['series_non_liees'], 'serie' ) );
		yume_assert_true( $rapport['oeuvres'][0]['import'], 'import initial' );
		yume_assert_same( $evenement, yume_tmdx_evenements(), 'aucune annonce à l’import initial' );
		yume_assert_true( metadata_exists( 'post', $oeuvre, Synchro::META_SYNCHRONISEE ) );
		yume_assert_same( $rapport, Synchro::dernier_rapport(), 'rapport enregistré' );

		$tomes = yume_get_tomes( $oeuvre, array( 'status' => 'any' ) );
		yume_assert_same( 3, count( $tomes ) );
		$par_nom = array();
		foreach ( $tomes as $tome ) {
			$par_nom[ $tome->post_name ] = $tome;
			yume_assert_same( 'publish', $tome->post_status, $tome->post_name );
			yume_assert_same( 'en_cours', get_post_meta( $tome->ID, 'yume_parution', true ) );
			yume_assert_same( 'catalogue', get_post_meta( $tome->ID, '_yume_publie_notifie', true ) );
		}
		yume_assert_same( array( 'chapitres', 'tome-1', 'tome-2' ), array_values( array_intersect( array( 'chapitres', 'tome-1', 'tome-2' ), array_keys( $par_nom ) ) ) );
		yume_assert_same( 'Mikadono — Tome 1', $par_nom['tome-1']->post_title );
		yume_assert_same( 'chapitres', get_post_meta( $par_nom['chapitres']->ID, 'yume_nature', true ) );
		yume_assert_same( '2025-03-01 12:00:00', $par_nom['tome-1']->post_date_gmt, 'tome daté de son premier chapitre' );

		$c1 = get_post( yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 1 ) ) );
		yume_assert_same( 'publish', $c1->post_status );
		yume_assert_same( 'Chapitre 1', $c1->post_title );
		yume_assert_same( 'chapitre-1', $c1->post_name );
		yume_assert_same( '', $c1->post_content, 'aucun contenu gardé' );
		yume_assert_same( '2025-03-01 12:00:00', $c1->post_date_gmt );
		yume_assert_same( (int) $par_nom['tome-1']->ID, (int) get_post_meta( $c1->ID, 'yume_tome_id', true ) );
		yume_assert_same( $oeuvre, (int) get_post_meta( $c1->ID, 'yume_oeuvre_id', true ) );
		yume_assert_same( 21, yume_mangadex_chapitre( $c1->ID )['pages'] );
		yume_assert_same( array( 'Yume Novel' ), yume_mangadex_chapitre( $c1->ID )['groupes'] );
		yume_assert_same( 'Yume Novel', get_post_meta( $c1->ID, 'yume_credits', true )['traduction'] );
		yume_assert_same( 'catalogue', get_post_meta( $c1->ID, '_yume_publie_notifie', true ) );
		$c4 = yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 4 ) );
		yume_assert_same( (int) $par_nom['chapitres']->ID, (int) get_post_meta( $c4, 'yume_tome_id', true ), 'sans volume → tome « Chapitres »' );
		yume_assert_same( 0, yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 5 ) ), 'chapitre externe non repris' );
		yume_assert_same( 0, yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 6 ) ), 'série non liée non reprise' );

		$relance = Synchro::executer( false, yume_tmdx_flux_depart() );
		yume_assert_same( array( 0, 0, 0, 0 ), array( $relance['crees'], $relance['maj'], $relance['tomes_crees'], $relance['retires'] ), 'relance sans effet' );
		yume_assert_false( $relance['oeuvres'][0]['import'] );
		yume_assert_same( 4, count( get_posts( array( 'post_type' => 'yume_chapitre', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
	}
);

yume_tmdx_test_synchro(
	'mangadex : synchronisation — titres avec apostrophes, guillemets et esperluette : tome au titre brut de l’œuvre, relance sans mise à jour',
	function () {
		$oeuvre  = yume_tmdx_oeuvre( YUME_TMDX_SERIE, 'publish', 'L\'héritière d\'Akane' );
		$flux    = array(
			yume_tmdx_norm( 1, array( 'title' => 'C\'est "la" fête & co' ) ),
			yume_tmdx_norm( 2, array( 'title' => 'L\'arrivée -- enfin...' ) ),
		);
		$rapport = Synchro::executer( false, $flux );
		yume_assert_same( 2, $rapport['crees'] );
		$tomes = yume_get_tomes( $oeuvre, array( 'status' => 'any' ) );
		yume_assert_same( 'L\'héritière d\'Akane — Tome 1', $tomes[0]->post_title, 'titre brut, sans entités' );
		$relance = Synchro::executer( false, $flux );
		yume_assert_same( array( 0, 0, 0 ), array( $relance['crees'], $relance['maj'], $relance['tomes_crees'] ), 'relance sans mise à jour' );
	}
);

yume_tmdx_test_synchro(
	'mangadex : synchronisation — après l’import, un nouveau chapitre d’un tome en ligne est annoncé seul (yume_chapitre_publie), un nouveau volume est annoncé avec son tome (yume_tome_publie)',
	function () {
		yume_tmdx_oeuvre();
		Synchro::executer( false, yume_tmdx_flux_depart() );
		yume_tmdx_nouvelle_requete();
		$avant   = yume_tmdx_evenements();
		$flux    = yume_tmdx_flux_depart();
		$flux[]  = yume_tmdx_norm(
			7,
			array(
				'chapter'    => '5',
				'readableAt' => yume_tmdx_il_y_a( 600 ),
				'updatedAt'  => yume_tmdx_il_y_a( 600 ),
			)
		);
		$flux[]  = yume_tmdx_norm(
			8,
			array(
				'volume'     => '3',
				'chapter'    => '6',
				'readableAt' => yume_tmdx_il_y_a( 300 ),
				'updatedAt'  => yume_tmdx_il_y_a( 300 ),
			)
		);
		$rapport = Synchro::executer( false, $flux );
		yume_assert_same( array( 2, 1 ), array( $rapport['crees'], $rapport['tomes_crees'] ) );
		$apres = yume_tmdx_evenements();
		yume_assert_same( 1, $apres['chapitre'] - $avant['chapitre'], 'chapitre 5 annoncé seul' );
		yume_assert_same( 1, $apres['tome'] - $avant['tome'], 'tome 3 annoncé avec son chapitre' );
		$c8 = yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 8 ) );
		yume_assert_same( 'publish', get_post_status( (int) get_post_meta( $c8, 'yume_tome_id', true ) ) );
	}
);

yume_tmdx_test_synchro(
	'mangadex : synchronisation — mise à jour en place (titre, volume renseigné après coup : le chapitre rejoint son tome), tome « Chapitres » vidé mis en brouillon, aucune annonce',
	function () {
		$oeuvre = yume_tmdx_oeuvre();
		Synchro::executer( false, yume_tmdx_flux_depart() );
		$c2      = yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 2 ) );
		$c4      = yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 4 ) );
		$date_c4 = get_post( $c4 )->post_date_gmt;
		$avant   = yume_tmdx_evenements();
		$flux    = yume_tmdx_flux_depart();
		$flux[1] = yume_tmdx_norm(
			2,
			array(
				'title'     => 'La fête',
				'updatedAt' => yume_tmdx_il_y_a( 60 ),
			)
		);
		$flux[3] = yume_tmdx_norm(
			4,
			array(
				'volume'     => '2',
				'readableAt' => yume_tmdx_il_y_a( 3600 ),
				'updatedAt'  => yume_tmdx_il_y_a( 60 ),
			)
		);
		$rapport = Synchro::executer( false, $flux );
		yume_assert_same( array( 0, 2, 1 ), array( $rapport['crees'], $rapport['maj'], $rapport['tomes_vides'] ) );
		yume_assert_same( 'Chapitre 2 — La fête', get_the_title( $c2 ) );
		yume_assert_same( 'La fête', get_post_meta( $c2, 'yume_sous_titre', true ) );
		yume_assert_same( 'publish', get_post_status( $c2 ) );
		$tome2 = 0;
		foreach ( yume_get_tomes( $oeuvre, array( 'status' => 'any' ) ) as $tome ) {
			if ( 'tome-2' === $tome->post_name ) {
				$tome2 = (int) $tome->ID;
			}
			if ( 'chapitres' === $tome->post_name ) {
				yume_assert_same( 'draft', $tome->post_status, 'tome « Chapitres » vidé' );
			}
		}
		yume_assert_same( $tome2, (int) get_post_meta( $c4, 'yume_tome_id', true ), 'chapitre 4 dans le tome 2' );
		yume_assert_same( $date_c4, get_post( $c4 )->post_date_gmt, 'date gardée' );
		yume_assert_same( $avant, yume_tmdx_evenements(), 'une mise à jour n’est pas annoncée' );
	}
);

yume_tmdx_test_synchro(
	'mangadex : synchronisation — chapitre disparu de MangaDex mis en brouillon puis remis en ligne s’il revient ; garde-fous : flux vide ou retrait massif suspendus (alerte), erreur de l’API sans aucune modification',
	function () {
		yume_tmdx_oeuvre();
		Synchro::executer( false, yume_tmdx_flux_depart() );
		$c2   = yume_mangadex_chapitre_par_uuid( yume_tmdx_uuid( 2 ) );
		$flux = yume_tmdx_flux_depart();
		unset( $flux[1] );
		$rapport = Synchro::executer( false, array_values( $flux ) );
		yume_assert_same( 1, $rapport['retires'] );
		yume_assert_same( 'draft', get_post_status( $c2 ) );
		yume_assert_true( metadata_exists( 'post', $c2, Synchro::META_RETIRE ) );

		$retour = Synchro::executer( false, yume_tmdx_flux_depart() );
		yume_assert_same( 1, $retour['remis_en_ligne'] );
		yume_assert_same( 'publish', get_post_status( $c2 ) );
		yume_assert_false( metadata_exists( 'post', $c2, Synchro::META_RETIRE ) );

		$vide = Synchro::executer( false, array() );
		yume_assert_true( $vide['ok'] );
		yume_assert_same( 0, $vide['retires'] );
		yume_assert_contains( 'aucun chapitre', $vide['alertes'][0] ?? '' );

		$massif = Synchro::executer( false, array( yume_tmdx_flux_depart()[0] ) );
		yume_assert_same( 0, $massif['retires'], 'retraits suspendus' );
		yume_assert_contains( 'suspendus', $massif['alertes'][0] ?? '' );
		yume_assert_same( 'publish', get_post_status( $c2 ) );

		// Erreur de l'API (lecture réelle simulée) : rien ne change, rapport en échec.
		$api = yume_tmdx_api(
			static function () {
				return yume_tmdx_reponse( 503, 'Service Unavailable' );
			}
		);
		try {
			$erreur = Synchro::executer();
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_false( $erreur['ok'] );
		yume_assert_true( '' !== $erreur['erreur'] );
		yume_assert_same( 'publish', get_post_status( $c2 ) );
		yume_assert_false( Synchro::dernier_rapport()['ok'], 'échec enregistré' );
	}
);

yume_tmdx_test_synchro(
	'mangadex : synchronisation — œuvre non publiée ignorée, simulation sans écriture, verrou (et verrou abandonné repris)',
	function () {
		$brouillon = yume_tmdx_oeuvre( YUME_TMDX_SERIE, 'draft' );
		$rapport   = Synchro::executer( false, yume_tmdx_flux_depart() );
		yume_assert_same( 0, $rapport['crees'] );
		yume_assert_same( array( $brouillon ), array_column( $rapport['oeuvres_non_publiees'], 'oeuvre' ) );
		wp_update_post(
			array(
				'ID'          => $brouillon,
				'post_status' => 'publish',
			)
		);

		delete_option( Synchro::OPTION_RAPPORT );
		$simulation = Synchro::executer( true, yume_tmdx_flux_depart() );
		yume_assert_true( $simulation['simulation'] );
		yume_assert_same( array( 4, 3 ), array( $simulation['crees'], $simulation['tomes_crees'] ) );
		yume_assert_same( array(), get_posts( array( 'post_type' => array( 'yume_tome', 'yume_chapitre' ), 'post_status' => 'any', 'fields' => 'ids' ) ), 'rien créé' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		yume_assert_false( metadata_exists( 'post', $brouillon, Synchro::META_SYNCHRONISEE ) );
		yume_assert_same( array(), Synchro::dernier_rapport(), 'aucun rapport enregistré' );

		add_option( Synchro::VERROU, time(), '', false );
		$occupe = Synchro::executer( false, yume_tmdx_flux_depart() );
		yume_assert_false( $occupe['ok'] );
		yume_assert_contains( 'déjà en cours', $occupe['erreur'] );
		update_option( Synchro::VERROU, time() - HOUR_IN_SECONDS, false );
		yume_assert_true( Synchro::executer( false, yume_tmdx_flux_depart() )['ok'], 'verrou abandonné repris' );
		yume_assert_false( get_option( Synchro::VERROU ), 'verrou libéré' );
	}
);

yume_tmdx_test_synchro(
	'mangadex : synchronisation — coupée par défaut et tant que le lecteur n’est pas livré, tâche horaire planifiée puis retirée, page Yume → Mangas (MangaDex) (simulation, rapport échappé)',
	function () {
		$reglages = get_option( 'yume_reglages', array() );
		$pret     = '__return_true';
		try {
			yume_assert_false( Synchro::active(), 'défaut' );
			update_option( 'yume_reglages', array_merge( is_array( $reglages ) ? $reglages : array(), array( 'mangadex_synchro' => true ) ) );
			yume_assert_false( Synchro::active(), 'lecteur pas encore livré' );
			add_filter( 'yume_mangadex_lecteur_pret', $pret );
			yume_assert_true( Synchro::active() );
			wp_clear_scheduled_hook( Synchro::CRON );
			\Yume\Core\MangaDex\planifier();
			yume_assert_true( (bool) wp_next_scheduled( Synchro::CRON ), 'tâche planifiée' );
			update_option( 'yume_reglages', $reglages );
			\Yume\Core\MangaDex\planifier();
			yume_assert_false( wp_next_scheduled( Synchro::CRON ), 'tâche retirée' );
		} finally {
			remove_filter( 'yume_mangadex_lecteur_pret', $pret );
			update_option( 'yume_reglages', $reglages );
			wp_clear_scheduled_hook( Synchro::CRON );
		}

		$html = \Yume\Core\MangaDex\html_rapport(
			array_merge(
				Synchro::executer( true, array( yume_tmdx_norm( 9, array(), yume_tmdx_uuid( 88 ) ) ) ),
				array(
					'series_non_liees' => array(
						array(
							'serie'     => yume_tmdx_uuid( 88 ),
							'titre'     => '<script>alert(1)</script>',
							'chapitres' => 1,
						),
					),
				)
			)
		);
		yume_assert_contains( 'Simulation', $html );
		yume_assert_contains( '&lt;script&gt;', $html );
		yume_assert_not_contains( '<script>', $html );

		wp_set_current_user( yume_factory_user( 'administrator' ) );
		try {
			ob_start();
			\Yume\Core\MangaDex\page();
			$page = (string) ob_get_clean();
		} finally {
			wp_set_current_user( 0 );
		}
		yume_assert_contains( 'Simuler (sans rien changer)', $page );
		yume_assert_contains( 'pas encore livré', $page );
		yume_assert_not_contains( 'Synchroniser maintenant', $page, 'synchronisation réelle indisponible' );
	}
);
