<?php
/**
 * Tests du module wordend (easter egg WordEnd) : fichiers livrés (moteur, univers), cohérence des
 * univers intégrés (manifeste v2, personnages, ennemis, niveaux, planches), registre des univers
 * (filtre yume_wordend_univers, assainissement), configuration du jeu (scripts dans l'ordre, URLs
 * versionnées, univers, universPage selon la requête), script déclencheur en façade (defer,
 * configuration posée avant), coupure par filtre, papillon de la fiche d'œuvre.
 *
 * Commande : tools/localenv/test.sh wordend
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use function Yume\Core\WordEnd\ajouter_papillon;
use function Yume\Core\WordEnd\configuration;
use function Yume\Core\WordEnd\chemin_manifeste;
use function Yume\Core\WordEnd\dossier_valide;
use function Yume\Core\WordEnd\enfiler_declencheur;
use function Yume\Core\WordEnd\fichiers_requis;
use function Yume\Core\WordEnd\fichiers_univers;
use function Yume\Core\WordEnd\oeuvres_declencheuses;
use function Yume\Core\WordEnd\univers;
use function Yume\Core\WordEnd\univers_de_la_page;
use function Yume\Core\WordEnd\univers_par_defaut;
use function Yume\Core\WordEnd\univers_par_oeuvre;
use const Yume\Core\WordEnd\POIGNEE;
use const Yume\Core\WordEnd\POIGNEE_SECRET;
use const Yume\Core\WordEnd\SCRIPTS_MOTEUR;
use const Yume\Core\WordEnd\UNIVERS_INTEGRES;

// Module non chargé (YUME_ONLY_MODULES sans « wordend ») : rien à tester.
if ( ! function_exists( 'Yume\Core\WordEnd\configuration' ) ) {
	return;
}

/** Dossier des univers intégrés. */
const YUME_TWE_UNIVERS = YUME_CORE_DIR . 'includes/wordend/assets/univers/';

/**
 * Fichiers référencés par le manifeste sukasuka que les lots B (personnages) et C (niveaux) de
 * WordEnd v2 créent : tolérés absents tant qu'ils ne sont pas intégrés (chemins relatifs à
 * assets/univers/<dossier>/).
 *
 * Intégration : une fois les lots B et C fusionnés, remplacer la liste par `array()` (garder la
 * constante, vide) : tout fichier référencé par un manifeste devient alors obligatoire et son
 * contenu est validé (personnages, niveaux). La liste peut aussi être raccourcie lot par lot.
 */
const YUME_TWE_ATTENDUS_LOTS = array(
	'personnages/nephren.json',
	'personnages/ithea.json',
	'niveaux/01-plage.json',
	'niveaux/02-dunes.json',
	'niveaux/03-falaise.json',
	'niveaux/04-nuit.json',
	'niveaux/05-boss.json',
);

/** Types connus des registres du moteur (docs/wordend-formats.md §4). */
const YUME_TWE_COMPETENCES   = array( 'melee', 'onde', 'projectile', 'ruee', 'parade' );
const YUME_TWE_COMPORTEMENTS = array( 'marcheur', 'coureur', 'volant', 'tireur', 'bouclier', 'boss' );
const YUME_TWE_OBJECTIFS     = array( 'arcade', 'vagues', 'survie', 'boss' );

/**
 * Retire le déclencheur et la feuille du papillon des files (état global des scripts).
 */
function yume_twe_vider_files(): void {
	wp_dequeue_script( POIGNEE );
	wp_dequeue_style( POIGNEE_SECRET );
	$scripts = wp_scripts();
	if ( isset( $scripts->registered[ POIGNEE ] ) ) {
		unset( $scripts->registered[ POIGNEE ]->extra['before'] );
	}
}

/**
 * Rendu simulé de yume/oeuvre-header passé au filtre du papillon.
 *
 * @param int $oeuvre_id Œuvre (contexte du bloc).
 */
function yume_twe_papillon( int $oeuvre_id ): string {
	$html     = '<div class="wp-block-yume-oeuvre-header yn-oeuvre-header"><div class="yn-oeuvre-header__tete"><h1 class="yn-oeuvre-header__titre">Titre</h1></div></div>';
	$instance = new WP_Block(
		array(
			'blockName'    => 'yume/oeuvre-header',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		),
		array(
			'postId'   => $oeuvre_id,
			'postType' => 'yume_oeuvre',
		)
	);
	return (string) ajouter_papillon( $html, array( 'blockName' => 'yume/oeuvre-header' ), $instance );
}

/**
 * Œuvre publiée dont le slug est exactement « sukasuka » (celle de la base locale, sinon créée ;
 * les modifications sont annulées par la transaction du test).
 */
function yume_twe_oeuvre_sukasuka(): int {
	$existante = get_page_by_path( 'sukasuka', OBJECT, 'yume_oeuvre' );
	if ( $existante instanceof WP_Post ) {
		if ( 'publish' !== $existante->post_status ) {
			wp_update_post(
				array(
					'ID'          => $existante->ID,
					'post_status' => 'publish',
				)
			);
		}
		return (int) $existante->ID;
	}
	$id = yume_factory_post(
		array(
			'post_type'   => 'yume_oeuvre',
			'post_title'  => 'SukaSuka',
			'post_name'   => 'sukasuka',
			'post_status' => 'publish',
		)
	);
	yume_assert_same( 'sukasuka', get_post_field( 'post_name', $id ), 'slug de l’œuvre de test' );
	return $id;
}

/**
 * Exécute $corps avec la requête principale posée sur $vars (fiche d'une œuvre, article…), puis
 * restaure la requête d'origine.
 *
 * @param array    $vars  Variables de WP_Query.
 * @param callable $corps Assertions.
 */
function yume_twe_avec_requete( array $vars, callable $corps ): void {
	global $wp_query, $wp_the_query, $post;
	$sauve = array( $wp_query, $wp_the_query, $post );
	// phpcs:disable WordPress.WP.GlobalVariablesOverride -- restaurées ci-dessous.
	try {
		$wp_query     = new WP_Query( $vars );
		$wp_the_query = $wp_query;
		$post         = $wp_query->post;
		$corps();
	} finally {
		list( $wp_query, $wp_the_query, $post ) = $sauve;
	}
	// phpcs:enable
}

/**
 * Lit un JSON (tableau vide et échec si absent ou invalide).
 *
 * @param string $chemin  Chemin absolu.
 * @param string $libelle Nom affiché dans les messages.
 */
function yume_twe_lire( string $chemin, string $libelle ): array {
	$donnees = is_readable( $chemin ) ? json_decode( (string) file_get_contents( $chemin ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	yume_assert_true( is_array( $donnees ), "$libelle : JSON valide" );
	return is_array( $donnees ) ? $donnees : array();
}

/**
 * Le fichier (relatif au dossier de l'univers) est-il créé par un lot pas encore intégré ?
 *
 * @param string $base    Dossier de l'univers (absolu, « / » final).
 * @param string $relatif Chemin relatif.
 */
function yume_twe_attendu_absent( string $base, string $relatif ): bool {
	return in_array( $relatif, YUME_TWE_ATTENDUS_LOTS, true ) && ! file_exists( $base . $relatif );
}

/**
 * Vérifie une planche (PNG + .planche.json) : dimensions, cadres dans l'image, ancres dans leur
 * cadre, images « coup » et « onde » existantes, et le nombre d'images attendu par animation.
 *
 * @param string $prefixe Chemin absolu sans extension.
 * @param string $nom     Nom affiché.
 * @param array  $attendu animation => nombre d'images (facultatif).
 * @return array Métadonnées de la planche.
 */
function yume_twe_verifier_planche( string $prefixe, string $nom, array $attendu = array() ): array {
	$meta = yume_twe_lire( $prefixe . '.planche.json', "$nom.planche.json" );
	yume_assert_true( is_readable( $prefixe . '.png' ), "$nom.png présent" );
	foreach ( $attendu as $animation => $nombre ) {
		yume_assert_same( $nombre, count( $meta['animations'][ $animation ]['images'] ?? array() ), "$nom : images de « $animation »" );
	}
	$taille = is_readable( $prefixe . '.png' ) ? getimagesize( $prefixe . '.png' ) : array( 0, 0 );
	yume_assert_same( $meta['planche'] ?? null, array( $taille[0], $taille[1] ), "$nom : dimensions de la planche" );
	yume_assert_true( ! empty( $meta['animations'] ) && is_array( $meta['animations'] ), "$nom : animations" );
	foreach ( (array) ( $meta['animations'] ?? array() ) as $animation => $donnees ) {
		$images = (array) ( $donnees['images'] ?? array() );
		yume_assert_true( count( $images ) > 0 && ( $donnees['ips'] ?? 0 ) > 0, "$nom : « $animation » a des images et un rythme" );
		foreach ( $images as $cadre ) {
			yume_assert_true( $cadre[0] >= 0 && $cadre[1] >= 0 && $cadre[0] + $cadre[2] <= $taille[0] && $cadre[1] + $cadre[3] <= $taille[1], "$nom : cadre de « $animation » dans la planche" );
			yume_assert_true( $cadre[4] >= 0 && $cadre[4] <= $cadre[2] && $cadre[5] >= 0 && $cadre[5] <= $cadre[3], "$nom : ancre de « $animation » dans son cadre" );
		}
		foreach ( (array) ( $donnees['coup'] ?? array() ) as $indice ) {
			yume_assert_true( $indice >= 0 && $indice < count( $images ), "$nom : image de coup de « $animation » existante" );
		}
		if ( isset( $donnees['onde'] ) ) {
			yume_assert_true( $donnees['onde'] >= 0 && $donnees['onde'] < count( $images ), "$nom : image d’onde de « $animation » existante" );
		}
	}
	return $meta;
}

/**
 * Slug d'un fichier d'entité ou de niveau : nom du fichier sans « .json ».
 *
 * @param string $relatif Chemin relatif.
 */
function yume_twe_slug( string $relatif ): string {
	return basename( $relatif, '.json' );
}

/**
 * Valide un univers intégré selon docs/wordend-formats.md §4–5 : manifeste v2, fichiers
 * référencés, personnages (planche, poses, compétences, déblocage), ennemis (planches, types,
 * comportements, attaques animées), niveaux (objectif, décor, musique, suivant, plateformes,
 * ennemis cités).
 *
 * @param string $dossier Dossier sous assets/univers/.
 * @return array Manifeste.
 */
function yume_twe_verifier_univers( string $dossier ): array {
	$base      = YUME_TWE_UNIVERS . $dossier . '/';
	$manifeste = yume_twe_lire( chemin_manifeste( $dossier ), "$dossier/manifeste.json" );
	yume_assert_same( 2, $manifeste['version'] ?? 0, "$dossier : manifeste en version 2" );
	yume_assert_same( $dossier, $manifeste['slug'] ?? '', "$dossier : slug du manifeste" );
	foreach ( array( 'personnages', 'ennemis', 'niveaux' ) as $cle ) {
		yume_assert_true( ! empty( $manifeste[ $cle ] ) && is_array( $manifeste[ $cle ] ), "$dossier : liste « $cle »" );
	}
	foreach ( (array) ( $manifeste['decors'] ?? array() ) as $nom => $decor ) {
		yume_assert_true( is_readable( $base . ( $decor['image'] ?? '' ) ), "$dossier : image du décor « $nom »" );
	}
	foreach ( (array) ( $manifeste['musiques'] ?? array() ) as $nom => $musique ) {
		yume_assert_true( is_readable( $base . ( $musique['fichier'] ?? '' ) ), "$dossier : fichier de la musique « $nom »" );
	}
	$niveaux_slugs = array_map( 'yume_twe_slug', (array) ( $manifeste['niveaux'] ?? array() ) );

	// Ennemis (lus d'abord : les niveaux les citent).
	$ennemis = array();
	foreach ( (array) ( $manifeste['ennemis'] ?? array() ) as $relatif ) {
		$slug   = yume_twe_slug( $relatif );
		$ennemi = yume_twe_lire( $base . $relatif, "$dossier/$relatif" );
		yume_assert_same( 2, $ennemi['version'] ?? 0, "$relatif : version 2" );
		yume_assert_same( $slug, $ennemi['slug'] ?? '', "$relatif : slug = nom du fichier" );
		$dossier_entite = dirname( $base . $relatif ) . '/';
		$meta           = yume_twe_verifier_planche( $dossier_entite . ( $ennemi['planche'] ?? '' ), "$dossier/ennemis/" . ( $ennemi['planche'] ?? '' ) );
		yume_assert_true( ! empty( $ennemi['types'] ) && is_array( $ennemi['types'] ), "$slug : types" );
		foreach ( (array) ( $ennemi['types'] ?? array() ) as $nom => $type ) {
			yume_assert_true( in_array( $type['comportement'] ?? '', YUME_TWE_COMPORTEMENTS, true ), "$slug : comportement du type « $nom »" );
			$meta_type = empty( $type['planche'] ) ? $meta : yume_twe_verifier_planche( $dossier_entite . $type['planche'], "$dossier/ennemis/" . $type['planche'] );
			yume_assert_true( ! empty( $type['attaques'] ), "$slug : attaques du type « $nom »" );
			foreach ( (array) ( $type['attaques'] ?? array() ) as $attaque ) {
				yume_assert_true( isset( $ennemi['attaques'][ $attaque ], $meta_type['animations'][ $attaque ] ), "$slug : attaque « $attaque » du type « $nom » déclarée et animée" );
			}
		}
		$ennemis[ $slug ] = $ennemi;
	}

	/**
	 * Vérifie une référence { ennemi, type } d'un niveau.
	 *
	 * @param mixed  $ref     Référence.
	 * @param string $libelle Contexte.
	 */
	$verifier_ref = static function ( $ref, string $libelle ) use ( $ennemis ): void {
		if ( ! is_array( $ref ) || ! isset( $ref['type'] ) ) {
			return;
		}
		$ennemi = (string) ( $ref['ennemi'] ?? array_key_first( $ennemis ) );
		yume_assert_true( isset( $ennemis[ $ennemi ]['types'][ $ref['type'] ] ), "$libelle : ennemi « $ennemi » de type « {$ref['type']} » déclaré" );
	};

	// Personnages.
	foreach ( (array) ( $manifeste['personnages'] ?? array() ) as $i => $relatif ) {
		if ( yume_twe_attendu_absent( $base, $relatif ) ) {
			continue;
		}
		$slug       = yume_twe_slug( $relatif );
		$personnage = yume_twe_lire( $base . $relatif, "$dossier/$relatif" );
		yume_assert_same( 2, $personnage['version'] ?? 0, "$relatif : version 2" );
		yume_assert_same( $slug, $personnage['slug'] ?? '', "$relatif : slug = nom du fichier" );
		$planche = yume_twe_verifier_planche( dirname( $base . $relatif ) . '/' . ( $personnage['planche'] ?? '' ), "$dossier/personnages/" . ( $personnage['planche'] ?? '' ) );
		foreach ( (array) ( $personnage['poses'] ?? array() ) as $pose => $cible ) {
			yume_assert_true( is_array( $cible ) && isset( $planche['animations'][ $cible[0] ?? '' ]['images'][ $cible[1] ?? -1 ] ), "$slug : pose « $pose » existante" );
		}
		yume_assert_true( isset( $personnage['competences']['principale'] ), "$slug : compétence principale" );
		foreach ( (array) ( $personnage['competences'] ?? array() ) as $emplacement => $competence ) {
			yume_assert_true( in_array( $competence['type'] ?? '', YUME_TWE_COMPETENCES, true ), "$slug : type de la compétence « $emplacement »" );
			if ( ! empty( $competence['animation'] ) ) {
				yume_assert_true( isset( $planche['animations'][ $competence['animation'] ] ), "$slug : animation de la compétence « $emplacement »" );
			}
		}
		$debloque = $personnage['debloque'] ?? true;
		if ( is_array( $debloque ) ) {
			yume_assert_true( in_array( $debloque['niveau'] ?? '', $niveaux_slugs, true ), "$slug : niveau de déblocage listé" );
		}
		if ( 0 === $i ) {
			yume_assert_true( true === $debloque, "$slug : personnage par défaut débloqué" );
		}
	}

	// Niveaux.
	foreach ( (array) ( $manifeste['niveaux'] ?? array() ) as $relatif ) {
		if ( yume_twe_attendu_absent( $base, $relatif ) ) {
			continue;
		}
		$slug   = yume_twe_slug( $relatif );
		$niveau = yume_twe_lire( $base . $relatif, "$dossier/$relatif" );
		yume_assert_same( 2, $niveau['version'] ?? 0, "$relatif : version 2" );
		yume_assert_same( $slug, $niveau['slug'] ?? '', "$relatif : slug = nom du fichier" );
		$objectif = (array) ( $niveau['objectif'] ?? array() );
		yume_assert_true( in_array( $objectif['type'] ?? 'arcade', YUME_TWE_OBJECTIFS, true ), "$slug : type d’objectif connu" );
		if ( isset( $niveau['decor'] ) ) {
			yume_assert_true( isset( $manifeste['decors'][ $niveau['decor'] ] ), "$slug : décor déclaré dans le manifeste" );
		}
		if ( isset( $niveau['musique'] ) ) {
			yume_assert_true( isset( $manifeste['musiques'][ $niveau['musique'] ] ), "$slug : musique déclarée dans le manifeste" );
		}
		if ( isset( $niveau['suivant'] ) ) {
			yume_assert_true( in_array( $niveau['suivant'], $niveaux_slugs, true ), "$slug : niveau suivant listé" );
		}
		$largeur = $niveau['largeur'] ?? 480;
		yume_assert_true( is_numeric( $largeur ) && $largeur >= 480, "$slug : largeur d’au moins un écran" );
		foreach ( (array) ( $niveau['plateformes'] ?? array() ) as $n => $plateforme ) {
			$x = $plateforme['x'] ?? -1;
			$l = $plateforme['l'] ?? 0;
			yume_assert_true( $x >= 0 && $l > 0 && $x + $l <= $largeur, "$slug : plateforme $n dans [0, largeur]" );
			yume_assert_true( in_array( $plateforme['type'] ?? 'traversable', array( 'traversable', 'solide' ), true ), "$slug : type de la plateforme $n" );
		}
		if ( isset( $objectif['ennemi'] ) ) {
			yume_assert_true( isset( $ennemis[ $objectif['ennemi'] ] ), "$slug : ennemi de l’objectif déclaré" );
		}
		foreach ( (array) ( $objectif['vagues'] ?? array() ) as $v => $vague ) {
			foreach ( (array) ( $vague['ennemis'] ?? array() ) as $groupe ) {
				$verifier_ref( $groupe, "$slug (vague $v)" );
			}
		}
		foreach ( (array) ( $objectif['generateur']['ennemis'] ?? array() ) as $groupe ) {
			$verifier_ref( $groupe, "$slug (générateur)" );
		}
	}
	return $manifeste;
}

yume_test(
	'wordend : module chargé, déclencheur accroché en façade seulement',
	function () {
		yume_assert_true( in_array( 'wordend', YUME_CORE_MODULES, true ), 'module déclaré' );
		yume_assert_true( false !== has_action( 'wp_enqueue_scripts', 'Yume\Core\WordEnd\enfiler_declencheur' ), 'accroché à wp_enqueue_scripts' );
		yume_assert_false( has_action( 'admin_enqueue_scripts', 'Yume\Core\WordEnd\enfiler_declencheur' ), 'jamais en administration' );
		yume_assert_true( wp_script_is( POIGNEE, 'registered' ), 'script enregistré' );
		yume_assert_same( 'defer', wp_scripts()->get_data( POIGNEE, 'strategy' ), 'chargé en defer' );
	}
);

yume_test(
	'wordend : fichiers livrés (déclencheur, moteur, univers intégrés), planches de Chtholly et du Timere',
	function () {
		$requis = fichiers_requis();
		foreach ( array( 'declencheur.js', 'secret.css', 'jeu.css' ) as $asset ) {
			yume_assert_true( in_array( YUME_CORE_DIR . 'includes/wordend/assets/' . $asset, $requis, true ), "$asset requis" );
		}
		foreach ( SCRIPTS_MOTEUR as $script ) {
			yume_assert_true( in_array( YUME_CORE_DIR . 'includes/wordend/assets/' . $script, $requis, true ), "$script requis" );
		}
		foreach ( UNIVERS_INTEGRES as $slug => $univers ) {
			$fichiers = fichiers_univers( $univers['dossier'] );
			yume_assert_same( chemin_manifeste( $univers['dossier'] ), $fichiers[0], "$slug : manifeste en tête" );
			yume_assert_same( array(), array_values( array_diff( $fichiers, $requis ) ), "$slug : fichiers de l’univers requis" );
		}
		yume_assert_same( array(), fichiers_univers( '../sukasuka' ), 'dossier hors de assets/univers/ refusé' );

		foreach ( $requis as $chemin ) {
			$relatif = str_replace( YUME_TWE_UNIVERS . 'sukasuka/', '', $chemin );
			if ( yume_twe_attendu_absent( YUME_TWE_UNIVERS . 'sukasuka/', $relatif ) ) {
				continue; // Créé par un lot de WordEnd v2 pas encore intégré.
			}
			yume_assert_true( is_readable( $chemin ), str_replace( YUME_CORE_DIR, '', $chemin ) . ' présent' );
		}

		$sukasuka = YUME_TWE_UNIVERS . 'sukasuka/';
		foreach ( array( 'personnages/chtholly', 'ennemis/timere' ) as $base ) {
			yume_assert_true( in_array( $sukasuka . $base . '.png', $requis, true ) && in_array( $sukasuka . $base . '.planche.json', $requis, true ), "$base : planche requise" );
		}
		yume_twe_verifier_planche(
			$sukasuka . 'personnages/chtholly',
			'chtholly',
			array(
				'repos'   => 2,
				'marche'  => 6,
				'course'  => 5,
				'attaque' => 4,
				'charge'  => 4,
				'degats'  => 1,
				'mort'    => 1,
			)
		);
		$timere = yume_twe_verifier_planche(
			$sukasuka . 'ennemis/timere',
			'timere',
			array(
				'repos'   => 5,
				'marche'  => 4,
				'course'  => 6,
				'fouet'   => 4,
				'morsure' => 4,
				'degats'  => 5,
				'mort'    => 6,
			)
		);
		foreach ( array( 'fouet', 'morsure' ) as $attaque ) {
			yume_assert_true( ! empty( $timere['animations'][ $attaque ]['coup'] ), "timere : images de coup de « $attaque »" );
		}
	}
);

yume_test(
	'wordend : univers intégrés cohérents (manifeste v2, personnages, ennemis, niveaux)',
	function () {
		foreach ( UNIVERS_INTEGRES as $slug => $univers ) {
			$manifeste = yume_twe_verifier_univers( $univers['dossier'] );
			yume_assert_same( $univers['oeuvre'], $manifeste['oeuvre'] ?? '', "$slug : œuvre du registre = œuvre du manifeste" );
		}

		// Points propres à SukaSuka (v1 conservé : arcade, Chtholly par défaut, types v1 du Timere).
		$manifeste = yume_twe_lire( chemin_manifeste( 'sukasuka' ), 'manifeste' );
		yume_assert_same( 'personnages/chtholly.json', $manifeste['personnages'][0] ?? '', 'Chtholly, personnage par défaut' );
		yume_assert_true( in_array( 'niveaux/arcade.json', $manifeste['niveaux'] ?? array(), true ), 'niveau arcade listé' );
		$timere = yume_twe_lire( YUME_TWE_UNIVERS . 'sukasuka/ennemis/timere.json', 'timere' );
		yume_assert_same( array( 'petit', 'normal', 'coureur', 'grand' ), array_slice( array_keys( $timere['types'] ?? array() ), 0, 4 ), 'types v1 du Timere' );
		$arcade = yume_twe_lire( YUME_TWE_UNIVERS . 'sukasuka/niveaux/arcade.json', 'arcade' );
		yume_assert_same( 'arcade', $arcade['objectif']['type'] ?? '', 'objectif du niveau arcade' );
		yume_assert_same( 480, $arcade['largeur'] ?? 0, 'arcade : largeur d’un écran' );
	}
);

yume_test(
	'wordend : registre des univers (yume_wordend_univers assaini, œuvre ↔ univers, univers par défaut)',
	function () {
		$defaut = univers();
		yume_assert_same( array( 'sukasuka' ), array_keys( $defaut ), 'univers intégrés' );
		yume_assert_same( array( 'titre', 'oeuvre', 'manifeste', 'version' ), array_keys( $defaut['sukasuka'] ), 'clés publiques seulement (pas de dossier)' );
		yume_assert_same( 'WordEnd', $defaut['sukasuka']['titre'] );
		yume_assert_same( 'sukasuka', univers_par_oeuvre( 'sukasuka' ) );
		yume_assert_same( '', univers_par_oeuvre( 'autre-oeuvre' ) );
		yume_assert_same( '', univers_par_oeuvre( '' ) );
		yume_assert_same( 'sukasuka', univers_par_defaut() );
		yume_assert_true( dossier_valide( 'sukasuka' ) && dossier_valide( 'grimgar_2' ), 'dossiers valides' );
		foreach ( array( '', '..', '../sukasuka', 'suka/suka', '/sukasuka', '.cache', 'suka suka' ) as $dossier ) {
			yume_assert_false( dossier_valide( $dossier ), "dossier « $dossier » refusé" );
		}

		$ajout = static function ( $univers ) {
			$univers['grimgar']        = array(
				'titre'     => 'Grimgar <b>gris</b>',
				'oeuvre'    => 'Grimgar of Fantasy and Ash',
				'manifeste' => 'https://exemple.test/wordend/grimgar/manifeste.json',
				'version'   => '1.0',
			);
			$univers['SukaSuka']       = array(
				'dossier' => 'sukasuka',
				'titre'   => 'Doublon',
			);
			$univers['!!!']            = array( 'dossier' => 'sukasuka' );
			$univers['evasion']        = array( 'dossier' => '../sukasuka' );
			$univers['absent']         = array( 'dossier' => 'absent' );
			$univers['relatif']        = array( 'manifeste' => '/wp-content/univers/manifeste.json' );
			$univers['ftp']            = array( 'manifeste' => 'ftp://exemple.test/manifeste.json' );
			$univers['script']         = array( 'manifeste' => 'javascript:alert(1)' );
			$univers['chaine']         = 'https://exemple.test/manifeste.json';
			$univers['sans-manifeste'] = array( 'titre' => 'Rien' );
			$univers['sans-titre']     = array(
				'dossier' => 'sukasuka',
				'oeuvre'  => 7,
			);
			return $univers;
		};
		add_filter( 'yume_wordend_univers', $ajout );
		try {
			$liste = univers();
			yume_assert_same( array( 'sukasuka', 'grimgar', 'sans-titre' ), array_keys( $liste ), 'entrées invalides retirées, doublon ignoré' );
			yume_assert_same( 'WordEnd', $liste['sukasuka']['titre'], 'le premier de deux slugs identiques l’emporte' );
			yume_assert_same(
				array(
					'titre'     => 'Grimgar gris',
					'oeuvre'    => 'grimgar-of-fantasy-and-ash',
					'manifeste' => 'https://exemple.test/wordend/grimgar/manifeste.json?ver=1.0',
					'version'   => '1.0',
				),
				$liste['grimgar'],
				'univers externe assaini et versionné'
			);
			yume_assert_same( 'WordEnd', $liste['sans-titre']['titre'], 'titre par défaut' );
			yume_assert_same( '7', $liste['sans-titre']['oeuvre'] );
			yume_assert_same( 'grimgar', univers_par_oeuvre( 'grimgar-of-fantasy-and-ash' ) );
			yume_assert_same( array( 'sukasuka', 'grimgar-of-fantasy-and-ash', '7' ), oeuvres_declencheuses(), 'œuvres des univers déclencheuses' );
			yume_assert_same( $liste, configuration()['univers'], 'configuration = registre filtré' );
		} finally {
			remove_filter( 'yume_wordend_univers', $ajout );
		}

		$sans_sukasuka = static function ( $univers ) {
			unset( $univers['sukasuka'] );
			$univers['grimgar'] = array(
				'titre'     => 'Grimgar',
				'manifeste' => 'https://exemple.test/grimgar/manifeste.json',
			);
			return $univers;
		};
		add_filter( 'yume_wordend_univers', $sans_sukasuka );
		try {
			yume_assert_same( 'grimgar', univers_par_defaut(), 'premier univers si le défaut est retiré' );
			yume_assert_same( 'grimgar', configuration()['universParDefaut'] );
			yume_assert_same( YUME_CORE_VERSION, univers()['grimgar']['version'], 'version par défaut : celle de l’extension' );
			yume_assert_same( '', univers()['grimgar']['oeuvre'], 'univers sans œuvre' );
		} finally {
			remove_filter( 'yume_wordend_univers', $sans_sukasuka );
		}

		add_filter( 'yume_wordend_univers', '__return_empty_array' );
		try {
			yume_assert_same( array(), univers() );
			yume_assert_same( '', univers_par_defaut(), 'aucun univers' );
		} finally {
			remove_filter( 'yume_wordend_univers', '__return_empty_array' );
		}
	}
);

yume_test(
	'wordend : configuration (scripts dans l’ordre, URLs versionnées, univers) et filtre des œuvres',
	function () {
		$config = configuration();
		$base   = YUME_CORE_URL . 'includes/wordend/assets/';
		yume_assert_same( array( 'version', 'style', 'scripts', 'univers', 'universParDefaut', 'universPage' ), array_keys( $config ), 'clés de window.ynWordEnd' );
		yume_assert_same( YUME_CORE_VERSION, $config['version'] );
		yume_assert_contains( $base . 'jeu.css', $config['style'], 'feuille du jeu' );
		yume_assert_contains( 'ver=', $config['style'], 'version de la feuille' );
		yume_assert_same( count( SCRIPTS_MOTEUR ), count( $config['scripts'] ), 'un script par fichier du moteur' );
		yume_assert_same( 14, count( $config['scripts'] ), '14 scripts du moteur' );
		yume_assert_same( 'moteur/00-espace.js', SCRIPTS_MOTEUR[0], 'espace de noms en premier' );
		yume_assert_same( 'moteur/jeu.js', SCRIPTS_MOTEUR[ count( SCRIPTS_MOTEUR ) - 1 ], 'point d’entrée en dernier' );
		foreach ( SCRIPTS_MOTEUR as $i => $script ) {
			yume_assert_contains( $base . $script . '?ver=' . rawurlencode( YUME_CORE_VERSION . '.' ), $config['scripts'][ $i ], "script « $script » versionné, à son rang" );
		}
		yume_assert_same( array( 'sukasuka' ), array_keys( $config['univers'] ), 'univers intégrés' );
		yume_assert_same( $config['univers'], univers() );
		yume_assert_contains( $base . 'univers/sukasuka/manifeste.json?ver=', $config['univers']['sukasuka']['manifeste'], 'manifeste versionné' );
		yume_assert_same( YUME_CORE_VERSION . '.' . filemtime( chemin_manifeste( 'sukasuka' ) ), $config['univers']['sukasuka']['version'], 'version de l’univers' );
		yume_assert_same( 'sukasuka', $config['univers']['sukasuka']['oeuvre'] );
		yume_assert_same( 'sukasuka', $config['universParDefaut'] );
		yume_assert_same( '', $config['universPage'], 'aucun univers de page hors fiche d’œuvre' );
		yume_assert_same( array( 'sukasuka' ), oeuvres_declencheuses() );

		$filtre = static function () {
			return array( 'Grimgar !', 'sukasuka', '' );
		};
		add_filter( 'yume_wordend_oeuvres', $filtre );
		try {
			yume_assert_same( array( 'grimgar', 'sukasuka' ), oeuvres_declencheuses(), 'slugs assainis et dédoublonnés' );
		} finally {
			remove_filter( 'yume_wordend_oeuvres', $filtre );
		}
		$vide = static function () {
			return array();
		};
		add_filter( 'yume_wordend_oeuvres', $vide );
		try {
			yume_assert_same( array( 'sukasuka' ), oeuvres_declencheuses(), 'les œuvres des univers restent déclencheuses' );
		} finally {
			remove_filter( 'yume_wordend_oeuvres', $vide );
		}
	}
);

yume_test(
	'wordend : universPage selon la requête (fiche d’une œuvre à univers), identique pour tous les visiteurs',
	function () {
		$sukasuka = yume_twe_oeuvre_sukasuka();
		$autre    = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_title'  => 'Autre œuvre',
				'post_name'   => 'autre-oeuvre-wordend',
				'post_status' => 'publish',
			)
		);
		$article  = yume_factory_post( array( 'post_name' => 'sukasuka-article' ) );

		yume_twe_avec_requete(
			array(
				'post_type' => 'yume_oeuvre',
				'p'         => $sukasuka,
			),
			static function () {
				yume_assert_true( is_singular( 'yume_oeuvre' ), 'fiche d’œuvre simulée' );
				yume_assert_same( 'sukasuka', univers_de_la_page() );
				$visiteur = configuration();
				yume_assert_same( 'sukasuka', $visiteur['universPage'], 'universPage de la fiche SukaSuka' );
				wp_set_current_user( yume_factory_user( 'administrator' ) );
				yume_assert_same( $visiteur, configuration(), 'même configuration connecté ou non (Batcache)' );
				wp_set_current_user( 0 );
			}
		);
		yume_twe_avec_requete(
			array(
				'post_type' => 'yume_oeuvre',
				'p'         => $autre,
			),
			static function () {
				yume_assert_true( is_singular( 'yume_oeuvre' ), 'fiche d’une autre œuvre' );
				yume_assert_same( '', configuration()['universPage'], 'œuvre sans univers' );
			}
		);
		yume_twe_avec_requete(
			array( 'p' => $article ),
			static function () {
				yume_assert_true( is_singular( 'post' ), 'article simulé' );
				yume_assert_same( '', configuration()['universPage'], 'pas une fiche d’œuvre' );
			}
		);
		yume_assert_same( '', configuration()['universPage'], 'requête restaurée' );
	}
);

yume_test(
	'wordend : déclencheur mis en file avec sa configuration, coupé par yume_wordend_actif ou sans univers',
	function () {
		yume_twe_vider_files();
		try {
			enfiler_declencheur();
			yume_assert_true( wp_script_is( POIGNEE, 'enqueued' ), 'mis en file' );
			$avant = implode( "\n", (array) wp_scripts()->get_data( POIGNEE, 'before' ) );
			yume_assert_contains( 'window.ynWordEnd = ', $avant );
			yume_assert_contains( 'moteur/00-espace.js', $avant );
			yume_assert_contains( 'univers/sukasuka/manifeste.json', $avant );
			yume_assert_contains( '"universPage":""', $avant );
			yume_assert_not_contains( '<', $avant, 'aucune balise dans la configuration' );

			yume_twe_vider_files();
			add_filter( 'yume_wordend_actif', '__return_false' );
			enfiler_declencheur();
			yume_assert_false( wp_script_is( POIGNEE, 'enqueued' ), 'coupé par le filtre' );
			remove_filter( 'yume_wordend_actif', '__return_false' );

			yume_twe_vider_files();
			add_filter( 'yume_wordend_univers', '__return_empty_array' );
			enfiler_declencheur();
			yume_assert_false( wp_script_is( POIGNEE, 'enqueued' ), 'aucun univers : rien à charger' );
		} finally {
			remove_filter( 'yume_wordend_actif', '__return_false' );
			remove_filter( 'yume_wordend_univers', '__return_empty_array' );
			yume_twe_vider_files();
		}
	}
);

yume_test(
	'wordend : papillon sur la fiche des œuvres déclencheuses, avec leur univers',
	function () {
		yume_twe_vider_files();
		$sukasuka = yume_twe_oeuvre_sukasuka();
		$autre    = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_title'  => 'Autre œuvre',
				'post_name'   => 'autre-oeuvre-wordend',
				'post_status' => 'publish',
			)
		);
		$ajoutee  = yume_factory_post(
			array(
				'post_type'   => 'yume_oeuvre',
				'post_title'  => 'SukaMoka',
				'post_name'   => 'sukamoka-wordend',
				'post_status' => 'publish',
			)
		);
		try {
			$html = yume_twe_papillon( $sukasuka );
			yume_assert_contains( 'data-yn-wordend-ouvrir', $html );
			yume_assert_contains( 'data-yn-wordend-univers="sukasuka"', $html, 'univers de l’œuvre' );
			yume_assert_contains( 'aria-label="Un papillon bleu s’est posé ici… (jeu caché WordEnd)"', $html, 'libellé avec le titre de l’univers' );
			yume_assert_contains( '</h1><button type="button" class="yn-wordend-secret"', $html, 'juste après le titre' );
			yume_assert_true( wp_style_is( POIGNEE_SECRET, 'enqueued' ), 'feuille du papillon en file' );

			yume_assert_not_contains( 'data-yn-wordend-ouvrir', yume_twe_papillon( $autre ), 'autre œuvre' );

			// Œuvre ajoutée par l'ancien filtre : papillon sans univers propre (univers par défaut).
			$oeuvres = static function ( $slugs ) {
				$slugs[] = 'sukamoka-wordend';
				return $slugs;
			};
			add_filter( 'yume_wordend_oeuvres', $oeuvres );
			$html = yume_twe_papillon( $ajoutee );
			yume_assert_contains( 'data-yn-wordend-ouvrir', $html, 'œuvre du filtre yume_wordend_oeuvres' );
			yume_assert_not_contains( 'data-yn-wordend-univers', $html, 'univers par défaut (attribut absent)' );
			remove_filter( 'yume_wordend_oeuvres', $oeuvres );

			// Titre de l'univers dans le libellé, échappé.
			$titre = static function ( $univers ) {
				$univers['sukasuka']['titre'] = 'Fin "du" monde';
				return $univers;
			};
			add_filter( 'yume_wordend_univers', $titre );
			yume_assert_contains( 'aria-label="Un papillon bleu s’est posé ici… (jeu caché Fin &quot;du&quot; monde)"', yume_twe_papillon( $sukasuka ), 'titre échappé' );
			remove_filter( 'yume_wordend_univers', $titre );

			add_filter( 'yume_wordend_univers', '__return_empty_array' );
			yume_assert_not_contains( 'data-yn-wordend-ouvrir', yume_twe_papillon( $sukasuka ), 'aucun univers' );
			remove_filter( 'yume_wordend_univers', '__return_empty_array' );

			add_filter( 'yume_wordend_actif', '__return_false' );
			yume_assert_not_contains( 'data-yn-wordend-ouvrir', yume_twe_papillon( $sukasuka ), 'easter egg coupé' );
		} finally {
			remove_filter( 'yume_wordend_actif', '__return_false' );
			remove_filter( 'yume_wordend_univers', '__return_empty_array' );
			remove_filter( 'yume_wordend_oeuvres', $oeuvres ?? '__return_null' );
			remove_filter( 'yume_wordend_univers', $titre ?? '__return_null' );
			yume_twe_vider_files();
		}
	}
);
