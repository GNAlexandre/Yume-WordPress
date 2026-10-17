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
use Yume\Core\MangaDex\Proxy;
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
	return gmdate( 'Y-m-d\TH:i:s+00:00', yume_tmdx_maintenant() - $secondes );
}

/**
 * Heure de référence des flux simulés, figée pour tout un test : un flux reconstruit une
 * seconde plus tard garde les mêmes dates, donc les mêmes versions (sinon la relance voit des
 * chapitres « modifiés »). Remise à l'heure au début de chaque test (yume_tmdx_test_synchro()),
 * pour rester proche de time() que core utilise (annonces : 15 min, 2 jours).
 *
 * @param bool $remettre Reprendre l'heure courante.
 */
function yume_tmdx_maintenant( bool $remettre = false ): int {
	static $maintenant = null;
	if ( $remettre || null === $maintenant ) {
		$maintenant = time();
	}
	return $maintenant;
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
			yume_tmdx_maintenant( true );
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
	'mangadex : synchronisation — coupée par défaut et avec le lecteur (filtre), tâche horaire planifiée puis retirée, page Yume → Mangas (MangaDex) (simulation, rapport échappé)',
	function () {
		$reglages = get_option( 'yume_reglages', array() );
		$coupe    = '__return_false';
		try {
			yume_assert_true( \Yume\Core\MangaDex\lecteur_pret(), 'lecteur livré' );
			yume_assert_false( Synchro::active(), 'défaut' );
			update_option( 'yume_reglages', array_merge( is_array( $reglages ) ? $reglages : array(), array( 'mangadex_synchro' => true ) ) );
			add_filter( 'yume_mangadex_lecteur_pret', $coupe );
			yume_assert_false( Synchro::active(), 'lecteur coupé' );
			remove_filter( 'yume_mangadex_lecteur_pret', $coupe );
			yume_assert_true( Synchro::active() );
			wp_clear_scheduled_hook( Synchro::CRON );
			\Yume\Core\MangaDex\planifier();
			yume_assert_true( (bool) wp_next_scheduled( Synchro::CRON ), 'tâche planifiée' );
			update_option( 'yume_reglages', $reglages );
			\Yume\Core\MangaDex\planifier();
			yume_assert_false( wp_next_scheduled( Synchro::CRON ), 'tâche retirée' );
		} finally {
			remove_filter( 'yume_mangadex_lecteur_pret', $coupe );
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
		add_filter( 'yume_mangadex_lecteur_pret', $coupe );
		try {
			ob_start();
			\Yume\Core\MangaDex\page();
			$page = (string) ob_get_clean();
		} finally {
			remove_filter( 'yume_mangadex_lecteur_pret', $coupe );
			wp_set_current_user( 0 );
		}
		yume_assert_contains( 'Simuler (sans rien changer)', $page );
		yume_assert_contains( 'Le lecteur de mangas est coupé', $page );
		yume_assert_not_contains( 'Synchroniser maintenant', $page, 'synchronisation réelle indisponible' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Proxy des pages (étape 4)
 * -----------------------------------------------------------------------------
 */

/** Version d'essai d'un chapitre du site. */
const YUME_TMDX_VERSION = 'abcdef01';

/** Empreinte MD@Home d'essai. */
const YUME_TMDX_HASH = '0123456789abcdef0123456789abcdef';

/** JPEG minimal (premiers octets d'un JPEG). */
const YUME_TMDX_JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01page";

/**
 * Œuvre, tome et chapitre MangaDex publiés (3 pages).
 *
 * @param string $statut Statut du chapitre.
 * @return array{oeuvre:int,tome:int,chapitre:int}
 */
function yume_tmdx_chapitre_site( string $statut = 'publish' ): array {
	$oeuvre   = yume_tmdx_oeuvre();
	$tome     = yume_factory_post(
		array(
			'post_type'  => 'yume_tome',
			'meta_input' => array( 'yume_oeuvre_id' => $oeuvre ),
		)
	);
	$chapitre = yume_factory_post(
		array(
			'post_type'   => 'yume_chapitre',
			'post_status' => $statut,
			'meta_input'  => array(
				'yume_tome_id'      => $tome,
				'yume_oeuvre_id'    => $oeuvre,
				'yume_mdx_chapitre' => yume_tmdx_uuid( 1 ),
				'yume_mdx_pages'    => 3,
				'yume_mdx_version'  => YUME_TMDX_VERSION,
			),
		)
	);
	return array(
		'oeuvre'   => $oeuvre,
		'tome'     => $tome,
		'chapitre' => $chapitre,
	);
}

/**
 * Chemin relayé d'une page du chapitre d'essai.
 *
 * @param int    $page    Page.
 * @param string $qualite Qualité.
 * @param string $version Version.
 */
function yume_tmdx_chemin( int $page, string $qualite = 'data-saver', string $version = YUME_TMDX_VERSION ): string {
	return yume_tmdx_uuid( 1 ) . '/' . $version . '/' . $qualite . '/' . $page;
}

/**
 * Réponse d'image simulée.
 *
 * @param string $corps Contenu.
 * @param string $type  Content-Type annoncé par le nœud.
 * @param int    $code  Statut.
 */
function yume_tmdx_image( string $corps = YUME_TMDX_JPEG, string $type = 'image/jpeg', int $code = 200 ): array {
	$reponse            = yume_tmdx_reponse( $code, $corps );
	$reponse['headers'] = array(
		'content-type' => $type,
		'x-cache'      => 'HIT from noeud',
	);
	return $reponse;
}

/**
 * Routeur MangaDex simulé : /at-home/server (nœud $noeuds[ appel ]), images des nœuds
 * ($images : callable( url ) → réponse), signalement ($signalement : code ou WP_Error).
 *
 * @param string[]      $noeuds      Adresses de nœud servies à chaque appel at-home (la dernière se répète).
 * @param callable|null $images      Réponse à une image.
 * @param mixed         $signalement Code HTTP du signalement, ou WP_Error.
 */
function yume_tmdx_routeur_proxy( array $noeuds = array( 'https://noeud.mangadex.network:443/jeton' ), ?callable $images = null, $signalement = 200 ): callable {
	$appel = 0;
	return static function ( string $url ) use ( $noeuds, $images, $signalement, &$appel ) {
		if ( Client::API . '/at-home/server/' . yume_tmdx_uuid( 1 ) === $url ) {
			$noeud = $noeuds[ min( $appel, count( $noeuds ) - 1 ) ];
			++$appel;
			return yume_tmdx_reponse(
				200,
				array(
					'result'  => 'ok',
					'baseUrl' => $noeud,
					'chapter' => array(
						'hash'      => YUME_TMDX_HASH,
						'data'      => array( '1-a.png', '2-b.png', '3-c.png' ),
						'dataSaver' => array( '1-a.jpg', '2-b.jpg', '3-c.jpg' ),
					),
				)
			);
		}
		if ( Proxy::SIGNALEMENT === $url ) {
			return is_wp_error( $signalement ) ? $signalement : yume_tmdx_reponse( (int) $signalement, '' );
		}
		foreach ( $noeuds as $noeud ) {
			if ( 0 === strpos( $url, $noeud . '/' ) ) {
				return $images ? $images( $url ) : yume_tmdx_image();
			}
		}
		return null;
	};
}

/**
 * Exécute un test du proxy : lecteur livré (sauf $pret faux), caches et file de signalements
 * vidés avant et après, aucune requête réelle.
 *
 * @param string   $nom   Nom.
 * @param callable $corps Corps.
 * @param bool     $pret  Lecteur livré.
 */
function yume_tmdx_test_proxy( string $nom, callable $corps, bool $pret = true ): void {
	yume_test(
		$nom,
		static function () use ( $corps, $pret ) {
			$vider  = static function () {
				delete_transient( 'yume_mdx_ah_' . yume_tmdx_uuid( 1 ) );
				delete_transient( Proxy::PAUSE );
				set_transient( Proxy::COUPURE, 1, 60 );
				Proxy::envoyer_signalements(); // Coupe-circuit ouvert : vide la file sans requête.
				delete_transient( Proxy::COUPURE );
			};
			$filtre = $pret ? '__return_true' : '__return_false';
			add_filter( 'yume_mangadex_lecteur_pret', $filtre );
			$vider();
			try {
				$corps();
			} finally {
				remove_filter( 'yume_mangadex_lecteur_pret', $filtre );
				$vider();
			}
		}
	);
}

yume_tmdx_test_proxy(
	'mangadex : proxy — adresse d’une page (version dans l’adresse, qualité par défaut économie de données, page hors du chapitre ou autre contenu : vide)',
	function () {
		$site = yume_tmdx_chapitre_site();
		$url  = yume_mangadex_url_page( $site['chapitre'], 2 );
		yume_assert_same( add_query_arg( Proxy::PARAMETRE, yume_tmdx_chemin( 2 ), home_url( '/' ) ), $url );
		yume_assert_contains( '/data/3', yume_mangadex_url_page( $site['chapitre'], 3, 'data' ) );
		yume_assert_contains( '/data-saver/1', yume_mangadex_url_page( $site['chapitre'], 1, 'autre' ) );
		yume_assert_same( '', yume_mangadex_url_page( $site['chapitre'], 0 ) );
		yume_assert_same( '', yume_mangadex_url_page( $site['chapitre'], 4 ) );
		yume_assert_same( '', yume_mangadex_url_page( yume_factory_post( array( 'post_type' => 'yume_chapitre' ) ), 1 ) );
	}
);

yume_tmdx_test_proxy(
	'mangadex : proxy — image relayée (type lu dans les octets, cache d’un an, aucun cookie), adresse MD@Home gardée, signalement après la réponse, aucune écriture en base par image, aucun fichier gardé',
	function () {
		$site      = yume_tmdx_chapitre_site();
		$api       = yume_tmdx_api( yume_tmdx_routeur_proxy() );
		$ecritures = 0;
		$compter   = static function () use ( &$ecritures ) {
			++$ecritures;
		};
		$medias    = count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$dossier   = wp_upload_dir()['basedir'];
		$lister    = static function () use ( $dossier ): array {
			if ( ! is_dir( $dossier ) ) {
				return array();
			}
			$fichiers = array();
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dossier, FilesystemIterator::SKIP_DOTS ) ) as $fichier ) {
				$fichiers[] = $fichier->getPathname();
			}
			sort( $fichiers );
			return $fichiers;
		};
		$avant     = $lister();
		try {
			$premiere = Proxy::reponse( yume_tmdx_chemin( 1 ) );
			foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $action ) {
				add_action( $action, $compter );
			}
			$deuxieme = Proxy::reponse( yume_tmdx_chemin( 2, 'data' ) );
			foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $action ) {
				remove_action( $action, $compter );
			}
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_same( 200, $premiere['statut'] );
		yume_assert_same( YUME_TMDX_JPEG, $premiere['corps'] );
		yume_assert_same( 'image/jpeg', $premiere['entetes']['Content-Type'] );
		yume_assert_same( 'public, max-age=31536000, immutable', $premiere['entetes']['Cache-Control'] );
		yume_assert_same( 'nosniff', $premiere['entetes']['X-Content-Type-Options'] );
		yume_assert_same( 'same-site', $premiere['entetes']['Cross-Origin-Resource-Policy'] );
		yume_assert_false( isset( $premiere['entetes']['Set-Cookie'] ) );
		yume_assert_same( 200, $deuxieme['statut'] );
		yume_assert_same( 0, $ecritures, 'aucune écriture en base pour une image (serveur MD@Home en cache)' );

		$urls = array_column( $api->requetes, 'url' );
		yume_assert_same(
			array(
				Client::API . '/at-home/server/' . yume_tmdx_uuid( 1 ),
				'https://noeud.mangadex.network:443/jeton/data-saver/' . YUME_TMDX_HASH . '/1-a.jpg',
				'https://noeud.mangadex.network:443/jeton/data/' . YUME_TMDX_HASH . '/2-b.png',
			),
			$urls,
			'un seul appel at-home, qualité choisie, aucun signalement avant la fin de la réponse'
		);
		foreach ( $api->requetes as $requete ) {
			yume_assert_contains( 'YumeNovel/', (string) $requete['args']['user-agent'] );
			yume_assert_true( empty( $requete['args']['stream'] ) && empty( $requete['args']['filename'] ), 'jamais écrit sur le disque' );
		}
		$file = Proxy::signalements_en_attente();
		yume_assert_same( 2, count( $file ) );
		yume_assert_same( $urls[1], $file[0]['url'] );
		yume_assert_true( $file[0]['success'] && $file[0]['cached'] );
		yume_assert_same( strlen( YUME_TMDX_JPEG ), $file[0]['bytes'] );
		yume_assert_same( $medias, count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) ), 'aucun média créé' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		yume_assert_same( $avant, $lister(), 'aucun fichier dans uploads' );
	}
);

yume_tmdx_test_proxy(
	'mangadex : proxy — signalements envoyés après coup (JSON, délai court) ; échec (522, réseau) : coupe-circuit, plus aucun envoi pendant la coupure ; succès sans écriture en base',
	function () {
		yume_tmdx_chapitre_site();
		$api = yume_tmdx_api( yume_tmdx_routeur_proxy() );
		try {
			Proxy::reponse( yume_tmdx_chemin( 1 ) );
			$ecritures = 0;
			$compter   = static function () use ( &$ecritures ) {
				++$ecritures;
			};
			add_action( 'added_option', $compter );
			add_action( 'updated_option', $compter );
			Proxy::envoyer_signalements();
			remove_action( 'added_option', $compter );
			remove_action( 'updated_option', $compter );
		} finally {
			yume_tmdx_fin( $api );
		}
		$envoi = end( $api->requetes );
		yume_assert_same( Proxy::SIGNALEMENT, $envoi['url'] );
		yume_assert_same( 'POST', $envoi['args']['method'] );
		yume_assert_true( $envoi['args']['timeout'] <= 3, 'délai court' );
		$corps = json_decode( (string) $envoi['args']['body'], true );
		yume_assert_same( array( 'url', 'success', 'cached', 'bytes', 'duration' ), array_keys( $corps ) );
		yume_assert_same( 0, $ecritures, 'succès : rien en base' );
		yume_assert_same( array(), Proxy::signalements_en_attente() );
		yume_assert_false( (bool) get_transient( Proxy::COUPURE ) );

		foreach ( array( 522, new WP_Error( 'http_request_failed', 'Délai dépassé' ) ) as $panne ) {
			delete_transient( Proxy::COUPURE );
			$api = yume_tmdx_api( yume_tmdx_routeur_proxy( array( 'https://noeud.mangadex.network:443/jeton' ), null, $panne ) );
			try {
				Proxy::reponse( yume_tmdx_chemin( 1 ) );
				Proxy::reponse( yume_tmdx_chemin( 2 ) );
				Proxy::envoyer_signalements();
				$apres_panne = count( $api->requetes );
				Proxy::reponse( yume_tmdx_chemin( 3 ) );
				Proxy::envoyer_signalements();
			} finally {
				yume_tmdx_fin( $api );
			}
			yume_assert_true( (bool) get_transient( Proxy::COUPURE ), 'coupe-circuit ouvert' );
			$signalements = array_filter(
				array_column( $api->requetes, 'url' ),
				static function ( $url ) {
					return Proxy::SIGNALEMENT === $url;
				}
			);
			yume_assert_same( 1, count( $signalements ), 'un seul essai, puis plus rien pendant la coupure' );
			yume_assert_same( $apres_panne + 1, count( $api->requetes ), 'la 3e page est servie, sans signalement' );
			yume_assert_same( array(), Proxy::signalements_en_attente() );
		}
	}
);

yume_tmdx_test_proxy(
	'mangadex : proxy — nœud mangadex.org non signalé ; nœud en échec : un seul autre nœud demandé (le premier échec est signalé) ; réponse non image refusée (502)',
	function () {
		yume_tmdx_chapitre_site();
		$api = yume_tmdx_api( yume_tmdx_routeur_proxy( array( 'https://uploads.mangadex.org' ) ) );
		try {
			yume_assert_same( 200, Proxy::reponse( yume_tmdx_chemin( 1 ) )['statut'] );
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_same( array(), Proxy::signalements_en_attente(), 'repli de MangaDex : pas de signalement' );

		delete_transient( 'yume_mdx_ah_' . yume_tmdx_uuid( 1 ) );
		$api = yume_tmdx_api(
			yume_tmdx_routeur_proxy(
				array( 'https://panne.mangadex.network/a', 'https://bon.mangadex.network/b' ),
				static function ( string $url ) {
					return 0 === strpos( $url, 'https://panne.' ) ? yume_tmdx_image( 'erreur', 'text/html', 502 ) : yume_tmdx_image();
				}
			)
		);
		try {
			$reponse = Proxy::reponse( yume_tmdx_chemin( 2 ) );
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_same( 200, $reponse['statut'] );
		yume_assert_same( 2, count( array_filter( array_column( $api->requetes, 'url' ), static fn( $u ) => false !== strpos( $u, '/at-home/' ) ) ), 'deux appels at-home, pas plus' );
		$file = Proxy::signalements_en_attente();
		yume_assert_same( array( false, true ), array_column( $file, 'success' ) );

		// Le nœud renvoie du HTML annoncé comme une image : refusé, jamais relayé.
		delete_transient( 'yume_mdx_ah_' . yume_tmdx_uuid( 1 ) );
		$api = yume_tmdx_api(
			yume_tmdx_routeur_proxy(
				array( 'https://noeud.mangadex.network/a' ),
				static function () {
					return yume_tmdx_image( '<html><script>alert(1)</script></html>', 'image/jpeg' );
				}
			)
		);
		try {
			$html = Proxy::reponse( yume_tmdx_chemin( 1 ) );
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_same( 502, $html['statut'] );
		yume_assert_same( 'text/plain; charset=utf-8', $html['entetes']['Content-Type'] );
		yume_assert_same( 'no-store', $html['entetes']['Cache-Control'] );
		yume_assert_not_contains( 'script', $html['corps'] );
		yume_assert_same( 2, count( array_filter( array_column( $api->requetes, 'url' ), static fn( $u ) => false !== strpos( $u, '/at-home/' ) ) ), 'un seul autre nœud' );
	}
);

yume_tmdx_test_proxy(
	'mangadex : proxy — refus sans aucun appel à MangaDex : adresse invalide (400), chapitre inconnu, brouillon, tome ou œuvre non publiés, version périmée, page hors du chapitre (404) ; limite 429 : pause de tout le site (503, Retry-After)',
	function () {
		$site = yume_tmdx_chapitre_site();
		$api  = yume_tmdx_api(
			static function () {
				return null;
			}
		);
		try {
			foreach ( array( '', '../etc/passwd', yume_tmdx_uuid( 1 ) . '/abcdef01/data-saver/0', yume_tmdx_uuid( 1 ) . '/abcdef01/autre/1', 'https://exemple.org/x.jpg', yume_tmdx_uuid( 1 ) . '/ABCDEF01/data/1' ) as $chemin ) {
				yume_assert_same( 400, Proxy::reponse( $chemin )['statut'], $chemin );
			}
			yume_assert_same( 404, Proxy::reponse( yume_tmdx_uuid( 9 ) . '/abcdef01/data-saver/1' )['statut'], 'chapitre inconnu' );
			yume_assert_same( 404, Proxy::reponse( yume_tmdx_chemin( 1, 'data-saver', '00000000' ) )['statut'], 'version périmée' );
			yume_assert_contains( 'rechargez', Proxy::reponse( yume_tmdx_chemin( 1, 'data-saver', '00000000' ) )['corps'] );
			yume_assert_same( 404, Proxy::reponse( yume_tmdx_chemin( 4 ) )['statut'], 'page hors du chapitre' );
			foreach ( array( 'chapitre', 'tome', 'oeuvre' ) as $cle ) {
				wp_update_post(
					array(
						'ID'          => $site[ $cle ],
						'post_status' => 'draft',
					)
				);
				yume_assert_same( 404, Proxy::reponse( yume_tmdx_chemin( 1 ) )['statut'], $cle . ' en brouillon' );
				wp_update_post(
					array(
						'ID'          => $site[ $cle ],
						'post_status' => 'publish',
					)
				);
			}
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_same( array(), $api->requetes, 'aucun appel à MangaDex' );

		$api = yume_tmdx_api(
			static function ( string $url ) {
				return false !== strpos( $url, '/at-home/' ) ? yume_tmdx_reponse( 429, '' ) : null;
			}
		);
		try {
			$limite = Proxy::reponse( yume_tmdx_chemin( 1 ) );
			$pause  = Proxy::reponse( yume_tmdx_chemin( 2 ) );
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_same( 503, $limite['statut'] );
		yume_assert_same( '60', $limite['entetes']['Retry-After'] );
		yume_assert_same( 503, $pause['statut'] );
		yume_assert_same( 1, count( $api->requetes ), 'aucun nouvel essai après 429, ni pendant la pause' );
	}
);

yume_tmdx_test_proxy(
	'mangadex : proxy — fermé tant que le lecteur n’est pas livré (404, aucun appel)',
	function () {
		yume_tmdx_chapitre_site();
		$api = yume_tmdx_api(
			static function () {
				return null;
			}
		);
		try {
			yume_assert_same( 404, Proxy::reponse( yume_tmdx_chemin( 1 ) )['statut'] );
		} finally {
			yume_tmdx_fin( $api );
		}
		yume_assert_same( array(), $api->requetes );
	},
	false
);

yume_test(
	'mangadex : proxy — type d’image lu dans les octets (JPEG, PNG, GIF, WebP ; HTML, SVG, vide refusés)',
	function () {
		yume_assert_same( 'image/jpeg', Proxy::type_image( YUME_TMDX_JPEG ) );
		yume_assert_same( 'image/png', Proxy::type_image( "\x89PNG\r\n\x1A\n...." ) );
		yume_assert_same( 'image/gif', Proxy::type_image( 'GIF89a....' ) );
		yume_assert_same( 'image/webp', Proxy::type_image( 'RIFF....WEBPVP8 ' ) );
		foreach ( array( '', '<html>', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'RIFF....WAVE' ) as $corps ) {
			yume_assert_same( '', Proxy::type_image( $corps ), $corps );
		}
	}
);

/*
 * -----------------------------------------------------------------------------
 * Lecteur (étape 5)
 * -----------------------------------------------------------------------------
 */

/**
 * Exécute $rappel comme sur la page du contenu $post_id (requête principale et $post).
 *
 * @param int      $post_id Contenu affiché.
 * @param callable $rappel  Rappel.
 * @return mixed
 */
function yume_tmdx_sur( int $post_id, callable $rappel ) {
	global $wp_query, $wp_the_query, $post;
	$avant = array( $wp_query, $wp_the_query, $post );
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- contexte de rendu simulé puis restauré.
	$wp_query     = new WP_Query(
		array(
			'p'           => $post_id,
			'post_type'   => get_post_type( $post_id ),
			'post_status' => 'any',
		)
	);
	$wp_the_query = $wp_query;
	$post         = get_post( $post_id );
	setup_postdata( $post );
	try {
		return $rappel();
	} finally {
		list( $wp_query, $wp_the_query, $post ) = $avant;
		wp_reset_postdata();
	}
	// phpcs:enable
}

/**
 * Rendu du bloc core/post-content (modèle « Chapitre ») du contenu affiché.
 *
 * @param int $post_id Contenu affiché.
 */
function yume_tmdx_rendu_contenu( int $post_id ): string {
	return (string) yume_tmdx_sur(
		$post_id,
		static function () use ( $post_id ) {
			$bloc = new WP_Block(
				array(
					'blockName'    => 'core/post-content',
					'attrs'        => array( 'className' => 'yn-reader__texte' ),
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
				array(
					'postId'   => $post_id,
					'postType' => get_post_type( $post_id ),
				)
			);
			return $bloc->render();
		}
	);
}

yume_test(
	'mangadex : lecteur — pages relayées à la place du contenu (économie de données dans src, originale à part, 2 pages immédiates, texte alternatif, poids de l’originale), crédits et lien MangaDex, outils masqués sans JavaScript',
	function () {
		$site = yume_tmdx_chapitre_site();
		$html = yume_tmdx_rendu_contenu( $site['chapitre'] );
		yume_assert_contains( 'wp-block-post-content', $html );
		yume_assert_contains( 'class="yn-manga" data-yn-manga data-yn-mode="defilement" data-yn-pages="3"', $html );
		yume_assert_same( 3, substr_count( $html, '<figure class="yn-manga__page"' ) );
		foreach ( array( 1, 2, 3 ) as $n ) {
			yume_assert_contains( 'src="' . esc_url( yume_mangadex_url_page( $site['chapitre'], $n ) ) . '"', $html );
			yume_assert_contains( 'data-yn-originale="' . esc_url( yume_mangadex_url_page( $site['chapitre'], $n, 'data' ) ) . '"', $html );
			yume_assert_contains( 'alt="Page ' . $n . ' sur 3"', $html );
		}
		yume_assert_same( 2, substr_count( $html, 'loading="eager"' ) );
		yume_assert_same( 1, substr_count( $html, 'loading="lazy"' ) );
		yume_assert_same( 1, substr_count( $html, 'fetchpriority="high"' ) );
		yume_assert_contains( 'Originale (≈ 5 Mo)', $html, '3 pages × 1,6 Mo' );
		yume_assert_contains( '3 pages', $html );
		yume_assert_contains( '<div class="yn-manga__outils" hidden>', $html );
		yume_assert_contains( '<div class="yn-manga__navigation" hidden>', $html );
		yume_assert_contains( 'Images hébergées par <a href="https://mangadex.org/"', $html );
		yume_assert_contains( 'href="https://mangadex.org/chapter/' . yume_tmdx_uuid( 1 ) . '"', $html );
		yume_assert_not_contains( 'mangadex.network', $html, 'jamais d’adresse MD@Home dans la page' );
		yume_assert_not_contains( 'uploads.mangadex.org', $html );
	}
);

yume_test(
	'mangadex : lecteur — HTML identique pour un visiteur et un membre (cache de pages), feuille et script chargés seulement sur un chapitre MangaDex',
	function () {
		$site     = yume_tmdx_chapitre_site();
		$visiteur = yume_tmdx_rendu_contenu( $site['chapitre'] );
		wp_set_current_user( yume_factory_user( 'administrator' ) );
		try {
			$membre = yume_tmdx_rendu_contenu( $site['chapitre'] );
		} finally {
			wp_set_current_user( 0 );
		}
		yume_assert_same( $visiteur, $membre );
		yume_assert_not_contains( 'nonce', $visiteur );

		wp_dequeue_script( \Yume\Core\MangaDex\POIGNEE_LECTEUR );
		wp_dequeue_style( \Yume\Core\MangaDex\POIGNEE_LECTEUR );
		$texte = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_content' => '<!-- wp:paragraph --><p>Texte.</p><!-- /wp:paragraph -->',
				'meta_input'   => array( 'yume_tome_id' => $site['tome'] ),
			)
		);
		yume_tmdx_sur( $texte, '\Yume\Core\MangaDex\charger_assets_lecteur' );
		yume_assert_false( wp_script_is( \Yume\Core\MangaDex\POIGNEE_LECTEUR, 'enqueued' ), 'chapitre en texte' );
		yume_tmdx_sur( $site['chapitre'], '\Yume\Core\MangaDex\charger_assets_lecteur' );
		yume_assert_true( wp_script_is( \Yume\Core\MangaDex\POIGNEE_LECTEUR, 'enqueued' ) );
		yume_assert_true( wp_style_is( \Yume\Core\MangaDex\POIGNEE_LECTEUR, 'enqueued' ) );
		wp_dequeue_script( \Yume\Core\MangaDex\POIGNEE_LECTEUR );
		wp_dequeue_style( \Yume\Core\MangaDex\POIGNEE_LECTEUR );
	}
);

yume_test(
	'mangadex : lecteur — absent d’un chapitre en texte (contenu intact) et d’un autre contenu affiché ; lecteur coupé ou chapitre sans nombre de pages : message et lien MangaDex',
	function () {
		$site  = yume_tmdx_chapitre_site();
		$texte = yume_factory_post(
			array(
				'post_type'    => 'yume_chapitre',
				'post_content' => '<!-- wp:paragraph --><p>Il était une fois.</p><!-- /wp:paragraph -->',
				'meta_input'   => array( 'yume_tome_id' => $site['tome'] ),
			)
		);
		$html  = yume_tmdx_rendu_contenu( $texte );
		yume_assert_contains( 'Il était une fois.', $html );
		yume_assert_not_contains( 'yn-manga', $html );

		// Le chapitre MangaDex cité ailleurs (autre page affichée) : pas de lecteur.
		$ailleurs = (string) yume_tmdx_sur(
			$texte,
			static function () {
				return \Yume\Core\MangaDex\contenu_chapitre( '' );
			}
		);
		yume_assert_same( '', $ailleurs );

		// Lecteur coupé : message et lien vers MangaDex, ni page ni adresse du proxy (coupé aussi),
		// ni feuille ni script du lecteur.
		add_filter( 'yume_mangadex_lecteur_pret', '__return_false' );
		try {
			$coupe = yume_tmdx_rendu_contenu( $site['chapitre'] );
			yume_tmdx_sur( $site['chapitre'], '\Yume\Core\MangaDex\charger_assets_lecteur' );
			yume_assert_false( wp_script_is( \Yume\Core\MangaDex\POIGNEE_LECTEUR, 'enqueued' ), 'script non chargé' );
		} finally {
			remove_filter( 'yume_mangadex_lecteur_pret', '__return_false' );
		}
		yume_assert_contains( 'yn-manga--coupe', $coupe );
		yume_assert_contains( 'momentanément indisponible', $coupe );
		yume_assert_contains( 'href="https://mangadex.org/chapter/' . yume_tmdx_uuid( 1 ) . '"', $coupe );
		yume_assert_contains( 'Lire ce chapitre sur MangaDex', $coupe );
		yume_assert_not_contains( '<img', $coupe );
		yume_assert_not_contains( Proxy::PARAMETRE, $coupe );
		yume_assert_not_contains( 'data-yn-manga', $coupe, 'pas de lecteur actif' );

		update_post_meta( $site['chapitre'], 'yume_mdx_pages', 0 );
		$vide = yume_tmdx_rendu_contenu( $site['chapitre'] );
		yume_assert_contains( 'yn-manga--vide', $vide );
		yume_assert_contains( 'pas encore disponibles', $vide );
		yume_assert_contains( 'Lire ce chapitre sur MangaDex', $vide );
		yume_assert_not_contains( '<img', $vide );
	}
);

yume_test(
	'mangadex : lecteur — la barre de lecture compte les pages comme des paragraphes (suivi, reprise « à la page N », mode page par page)',
	function () {
		$js = (string) file_get_contents( YUME_CORE_DIR . 'includes/reader/blocks/reader-tools/view.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		yume_assert_contains( "querySelectorAll( '.yn-manga__page' )", $js );
		yume_assert_contains( "'yn:manga-page'", $js );
		yume_assert_contains( "'yn:manga-aller'", $js );
		yume_assert_contains( "'à la page '", $js );
		$manga = (string) file_get_contents( YUME_CORE_DIR . 'includes/mangadex/assets/manga.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		yume_assert_contains( "const CLE = 'yn.manga'", $manga );
		yume_assert_contains( 'e.clientX >= rect.left + rect.width / 2 ? 1 : -1', $manga, 'moitié droite : page suivante, quel que soit le sens' );
	}
);

/*
 * -----------------------------------------------------------------------------
 * Formulaire équipe des œuvres : lien MangaDex
 * -----------------------------------------------------------------------------
 */

/**
 * Envoi du formulaire « Nouvelle œuvre » (ou « Modifier » avec oeuvre_id) de l'espace équipe.
 *
 * @param array<string,mixed> $champs    Champs.
 * @param int                 $oeuvre_id Œuvre modifiée (0 : création).
 * @return array<string,mixed> Retour de traiter_formulaire_oeuvre().
 */
function yume_tmdx_formulaire( array $champs, int $oeuvre_id = 0 ): array {
	$post = array_merge(
		array(
			'action'      => $oeuvre_id ? 'yume_oeuvre_modifier' : 'yume_oeuvre_creer',
			'_yume_nonce' => wp_create_nonce( $oeuvre_id ? 'yume_oeuvre_modifier_' . $oeuvre_id : 'yume_oeuvre_creer' ),
		),
		$oeuvre_id ? array( 'oeuvre_id' => (string) $oeuvre_id ) : array(),
		$champs
	);
	return \Yume\Core\Planning\traiter_formulaire_oeuvre( wp_slash( $post ), array(), get_current_user_id() );
}

if ( function_exists( 'Yume\Core\Planning\traiter_formulaire_oeuvre' ) ) {
	yume_test(
		'mangadex : formulaire équipe — champ « Lien MangaDex » (création, adresse ou identifiant, modification, lien vidé = œuvre déliée, champ absent = lien gardé), refus (adresse de chapitre, autre site, type non manga, série déjà liée), pastilles de la liste',
		function () {
			wp_set_current_user( yume_factory_user( 'yume_editeur' ) );
			try {
				$form = \Yume\Core\Planning\formulaire_oeuvre( null );
				yume_assert_contains( 'name="mangadex"', $form );
				yume_assert_contains( 'data-yn-mangadex-champ', $form );

				$cree = yume_tmdx_formulaire(
					array(
						'titre'    => 'Mikadono Sanshimai',
						'type'     => 'manga',
						'mangadex' => 'https://mangadex.org/title/' . YUME_TMDX_SERIE . '/mikadono-sanshimai-wa-angai-choroi?tab=chapters',
					)
				);
				yume_assert_same( 'ok', $cree['type'], (string) ( $cree['message'] ?? '' ) );
				$oeuvre = (int) $cree['oeuvre_id'];
				yume_assert_same( YUME_TMDX_SERIE, yume_mangadex_serie( $oeuvre ) );
				yume_assert_same( $oeuvre, yume_mangadex_oeuvre_par_serie( YUME_TMDX_SERIE ) );
				yume_assert_contains( 'value="https://mangadex.org/title/' . YUME_TMDX_SERIE . '"', \Yume\Core\Planning\formulaire_oeuvre( null, $oeuvre ), 'lien repris dans « Modifier »' );

				// Refus, rien n'est créé ni lié.
				$refus = array(
					'chapitre'   => array(
						'type'     => 'manga',
						'mangadex' => 'https://mangadex.org/chapter/' . yume_tmdx_uuid( 1 ),
					),
					'autre site' => array(
						'type'     => 'manga',
						'mangadex' => 'https://exemple.org/title/' . yume_tmdx_uuid( 2 ),
					),
					'type'       => array(
						'type'     => 'light-novel',
						'mangadex' => yume_tmdx_uuid( 3 ),
					),
					'déjà liée'  => array(
						'type'     => 'manga',
						'mangadex' => strtoupper( YUME_TMDX_SERIE ),
					),
				);
				foreach ( $refus as $cas => $champs ) {
					$retour = yume_tmdx_formulaire( array_merge( array( 'titre' => 'Refus ' . $cas ), $champs ) );
					yume_assert_same( 'erreur', $retour['type'], $cas );
					yume_assert_same( $champs['mangadex'], $retour['saisie']['mangadex'], $cas . ' : saisie reprise' );
				}
				yume_assert_contains( 'pas celle d’un chapitre', yume_tmdx_formulaire( array_merge( array( 'titre' => 'X' ), $refus['chapitre'] ) )['message'] );
				yume_assert_contains( 'réservé aux œuvres de type « Manga »', yume_tmdx_formulaire( array_merge( array( 'titre' => 'X' ), $refus['type'] ) )['message'] );
				yume_assert_contains( 'déjà liée à l’œuvre « Mikadono Sanshimai »', yume_tmdx_formulaire( array_merge( array( 'titre' => 'X' ), $refus['déjà liée'] ) )['message'] );
				yume_assert_same( 0, yume_mangadex_oeuvre_par_serie( yume_tmdx_uuid( 3 ) ) );

				// Modification : la même série reste acceptée pour cette œuvre ; identifiant seul accepté.
				$modif = yume_tmdx_formulaire(
					array(
						'titre'    => 'Mikadono Sanshimai',
						'type'     => 'manga',
						'mangadex' => yume_tmdx_uuid( 4 ),
					),
					$oeuvre
				);
				yume_assert_same( 'ok', $modif['type'], (string) ( $modif['message'] ?? '' ) );
				yume_assert_same( yume_tmdx_uuid( 4 ), yume_mangadex_serie( $oeuvre ) );

				// Champ absent (formulaire sans le module) : lien gardé.
				yume_tmdx_formulaire(
					array(
						'titre' => 'Mikadono Sanshimai',
						'type'  => 'manga',
					),
					$oeuvre
				);
				yume_assert_same( yume_tmdx_uuid( 4 ), yume_mangadex_serie( $oeuvre ) );

				// Pastilles : lié → « MangaDex » ; manga sans lien → « Lien MangaDex manquant ».
				$ligne = static function ( int $id ): string {
					return \Yume\Core\Planning\ligne_oeuvre_equipe(
						array(
							'id'          => $id,
							'titre'       => get_the_title( $id ),
							'statut'      => (string) get_post_status( $id ),
							'type'        => 'Manga',
							'avancement'  => '',
							'etat'        => '',
							'tomes'       => 0,
							'tomes_etats' => array(),
							'suggestion'  => null,
						),
						null
					);
				};
				yume_assert_contains( '>MangaDex</span>', $ligne( $oeuvre ) );

				// Lien vidé : œuvre déliée.
				yume_tmdx_formulaire(
					array(
						'titre'    => 'Mikadono Sanshimai',
						'type'     => 'manga',
						'mangadex' => '',
					),
					$oeuvre
				);
				yume_assert_same( '', yume_mangadex_serie( $oeuvre ) );
				yume_assert_contains( 'Lien MangaDex manquant', $ligne( $oeuvre ) );

				// Light novel : ni champ rempli ni pastille.
				$ln = yume_tmdx_formulaire(
					array(
						'titre'    => 'Un light novel',
						'type'     => 'light-novel',
						'mangadex' => '',
					)
				);
				yume_assert_same( 'ok', $ln['type'] );
				yume_assert_not_contains( 'MangaDex', $ligne( (int) $ln['oeuvre_id'] ) );
			} finally {
				wp_set_current_user( 0 );
			}
		}
	);
}
