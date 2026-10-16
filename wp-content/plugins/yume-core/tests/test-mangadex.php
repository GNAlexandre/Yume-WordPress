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
 * @param callable $routeur Routeur ( string $url, array $args ) : array|WP_Error|null.
 * @return stdClass {requetes: array<int,array{url:string,args:array}>, imprevues: string[], filtre: callable}
 */
function yume_tmdx_api( callable $routeur ): stdClass {
	$etat            = new stdClass();
	$etat->requetes  = array();
	$etat->imprevues = array();
	$etat->filtre    = static function ( $pre, $args, $url ) use ( $routeur, $etat ) {
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
	add_filter( 'pre_http_request', $etat->filtre, 10, 3 );
	return $etat;
}

/**
 * Retire la simulation et vérifie qu'aucune adresse imprévue n'a été demandée.
 *
 * @param stdClass $etat État de yume_tmdx_api().
 */
function yume_tmdx_fin( stdClass $etat ): void {
	remove_filter( 'pre_http_request', $etat->filtre, 10 );
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
