<?php
/**
 * Espace équipe, vue « Glossaires » (?vue=glossaire, filtre yume_vues_equipe, capacité
 * yume_glossaire) : téléversement d'un fichier .yaml pour une œuvre, « Vérifier » (simulation :
 * bilan et avertissements, brouillon gardé 30 minutes) puis « Publier le glossaire » ;
 * le YAML vérifié est gardé dans la table des versions (source « brouillon », jamais servi ni
 * compté dans l'historique) et le transient du compte ne garde que l'identifiant, l'empreinte
 * et le bilan (quelques Ko : compatible avec la limite de 1 Mo de Memcached) ;
 * historique des versions (date, auteur, source, entrées) avec « Télécharger ce YAML » et
 * « Restaurer cette version » ; lien vers la page publique.
 *
 * Formulaires envoyés à admin-post.php (action yume_glossaire, nonce yume_glossaire ;
 * téléchargement : action yume_glossaire_yaml, nonce par version).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Glossaire;

use function Yume\Core\Planning\champ_select;
use function Yume\Core\Planning\champs_caches_vue;
use function Yume\Core\Planning\choix_oeuvres;
use function Yume\Core\Planning\navigation_equipe;
use function Yume\Core\Planning\ouvrir_racine;
use function Yume\Core\Planning\retour_formulaire;
use function Yume\Core\Planning\tete_vue;
use function Yume\Core\Planning\url_vue_equipe;

defined( 'ABSPATH' ) || exit;

/** Identifiant de la zone de retour de la vue. */
const RETOUR = 'yn-glossaire-retour';

/**
 * Déclare la vue ?vue=glossaire.
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue( $vues ) {
	$vues              = is_array( $vues ) ? $vues : array();
	$vues['glossaire'] = array(
		'libelle'  => __( 'Glossaires', 'yume-core' ),
		'capacite' => CAPACITE,
		'rendu'    => __NAMESPACE__ . '\\rendu_vue_glossaire',
	);
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue' );

/**
 * Feuille de style de la vue (chargée seulement quand la vue est rendue).
 */
function enregistrer_style_vue(): void {
	wp_register_style( 'yume-glossaire-equipe', YUME_CORE_URL . 'includes/glossaire/assets/equipe.css', array(), YUME_CORE_VERSION );
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_style_vue' );

/**
 * Clé du brouillon (glossaire vérifié, pas encore publié) d'un compte.
 *
 * @param int $user_id Compte.
 */
function cle_brouillon( int $user_id ): string {
	return 'yume_glossaire_brouillon_' . $user_id;
}

/**
 * Brouillon d'un compte : array{id:int, sha256:string, oeuvre:int, fichier:string, bilan:array}
 * (transient), ou null s'il a expiré ou si sa ligne a disparu.
 *
 * @param int $user_id Compte.
 */
function brouillon( int $user_id ): ?array {
	$b = get_transient( cle_brouillon( $user_id ) );
	if ( ! is_array( $b ) || empty( $b['id'] ) || empty( $b['sha256'] ) || ! isset( $b['oeuvre'] ) ) {
		return null;
	}
	return null !== ligne_brouillon( (int) $b['id'], $user_id, (string) $b['sha256'] ) ? $b : null;
}

/**
 * Oublie le brouillon d'un compte (ligne et transient).
 *
 * @param int $user_id Compte.
 */
function oublier_brouillon( int $user_id ): void {
	$b = get_transient( cle_brouillon( $user_id ) );
	if ( is_array( $b ) && ! empty( $b['id'] ) ) {
		supprimer_brouillon( (int) $b['id'] );
	}
	delete_transient( cle_brouillon( $user_id ) );
}

/**
 * Œuvre choisie dans la vue (GET oeuvre), ou 0.
 */
function oeuvre_vue(): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix d'affichage en lecture seule.
	$id = isset( $_GET['oeuvre'] ) && is_scalar( $_GET['oeuvre'] ) ? absint( $_GET['oeuvre'] ) : 0;
	return $id && 'yume_oeuvre' === get_post_type( $id ) ? $id : 0;
}

/**
 * Libellé d'une source de version.
 *
 * @param string $source api, televersement ou restauration.
 */
function libelle_source( string $source ): string {
	$libelles = array(
		'api'           => __( 'Yume-Trad (envoi direct)', 'yume-core' ),
		'televersement' => __( 'Téléversement', 'yume-core' ),
		'restauration'  => __( 'Restauration', 'yume-core' ),
	);
	return $libelles[ $source ] ?? $source;
}

/**
 * Bilan d'une analyse en HTML : compteurs par catégorie, avertissements.
 *
 * @param array $bilan Bilan (bilan()).
 */
function html_bilan( array $bilan ): string {
	$html = '<ul class="yn-glossaire-eq__compteurs">';
	foreach ( (array) $bilan['nb_entrees'] as $cle => $nb ) {
		$html .= '<li><span>' . esc_html( libelle_categorie( (string) $cle ) ) . '</span> <b>' . (int) $nb . '</b></li>';
	}
	$html .= '</ul>';
	$html .= '<p>' . esc_html(
		sprintf(
			/* translators: 1: entrées, 2: entrées publiques, 3: anglicismes */
			__( '%1$d entrées, dont %2$d visibles du public (avec une traduction française ou un nom conservé) ; %3$d anglicismes.', 'yume-core' ),
			(int) $bilan['total'],
			(int) $bilan['publiques'],
			(int) $bilan['anglicismes']
		)
	) . '</p>';
	if ( ! empty( $bilan['identique'] ) ) {
		$html .= '<p class="yn-chip yn-chip--info">' . esc_html__( 'Identique au glossaire en ligne', 'yume-core' ) . '</p>';
	}
	$avertissements = (array) ( $bilan['avertissements'] ?? array() );
	if ( $avertissements ) {
		$html .= '<details class="yn-glossaire-eq__avertissements"' . ( count( $avertissements ) <= 5 ? ' open' : '' ) . '><summary>' . esc_html(
			sprintf(
				/* translators: %d : nombre d'avertissements */
				_n( '%d avertissement (entrée ignorée)', '%d avertissements (entrées ignorées)', count( $avertissements ), 'yume-core' ),
				count( $avertissements )
			)
		) . '</summary><ul>';
		foreach ( $avertissements as $a ) {
			$html .= '<li>' . esc_html( (string) $a ) . '</li>';
		}
		$html .= '</ul></details>';
	}
	return $html;
}

/**
 * Zone de retour (message, détails).
 *
 * @param array|null $retour Retour du dernier envoi.
 */
function zone_retour_glossaire( ?array $retour ): string {
	$classe = 'yn-team__retour yn-glossaire-eq__retour';
	$corps  = '';
	if ( $retour ) {
		$classe .= 'ok' === $retour['type'] ? ' yn-team__retour--ok' : ' yn-team__retour--erreur';
		$corps   = '<p>' . esc_html( (string) $retour['message'] ) . '</p>';
	}
	return '<div class="' . esc_attr( $classe ) . '" id="' . esc_attr( RETOUR ) . '" role="status" aria-live="polite" tabindex="-1">' . $corps . '</div>';
}

/**
 * Champs cachés communs d'un formulaire de la vue.
 *
 * @param string $op        Opération par défaut.
 * @param int    $oeuvre_id Œuvre.
 */
function champs_formulaire( string $op, int $oeuvre_id = 0 ): string {
	$html  = '<input type="hidden" name="action" value="yume_glossaire">';
	$html .= '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	$html .= $oeuvre_id ? '<input type="hidden" name="oeuvre" value="' . (int) $oeuvre_id . '">' : '';
	return $html . wp_nonce_field( 'yume_glossaire', '_yume_nonce', true, false );
}

/**
 * Adresse de téléchargement du YAML d'une version (nonce par version).
 *
 * @param int $version_id Version.
 */
function url_telechargement( int $version_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'  => 'yume_glossaire_yaml',
				'version' => $version_id,
			),
			admin_url( 'admin-post.php' )
		),
		'yume_glossaire_yaml_' . $version_id
	);
}

/**
 * Historique des versions d'une œuvre.
 *
 * @param int $oeuvre_id Œuvre.
 */
function section_historique( int $oeuvre_id ): string {
	$versions = versions( $oeuvre_id );
	$courante = etat_glossaire( $oeuvre_id )['version'];
	$titre    = titre_oeuvre( $oeuvre_id );
	$html     = '<section class="yn-card yn-team__carte yn-glossaire-eq__historique" id="yn-glossaire-historique" aria-labelledby="yn-glossaire-historique-titre">';
	$html    .= '<div class="yn-team__section-tete"><h2 id="yn-glossaire-historique-titre" class="yn-label">' . esc_html(
		/* translators: %s : titre de l'œuvre */
		sprintf( __( 'Historique · %s', 'yume-core' ), $titre )
	) . '</h2>';
	$public = url_glossaire( $oeuvre_id );
	if ( $courante && '' !== $public && glossaire_visible( $oeuvre_id ) ) {
		$html .= '<a href="' . esc_url( $public ) . '">' . esc_html__( 'Voir la page publique', 'yume-core' ) . '</a>';
	}
	$html .= '</div>';
	if ( ! $versions ) {
		return $html . '<p class="yn-muted">' . esc_html__( 'Aucun glossaire pour cette œuvre.', 'yume-core' ) . '</p></section>';
	}
	/* translators: %d : nombre de versions conservées */
	$html .= '<p class="yn-muted">' . esc_html( sprintf( __( 'Les %d dernières versions sont conservées.', 'yume-core' ), VERSIONS_CONSERVEES ) ) . '</p>';
	$html .= '<div class="yn-glossaire-eq__table" tabindex="0" role="region" aria-labelledby="yn-glossaire-historique-titre"><table><thead><tr>';
	foreach ( array( __( 'Date', 'yume-core' ), __( 'Auteur', 'yume-core' ), __( 'Source', 'yume-core' ), __( 'Entrées', 'yume-core' ), __( 'Actions', 'yume-core' ) ) as $col ) {
		$html .= '<th scope="col">' . esc_html( $col ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $versions as $v ) {
		$id       = (int) $v['id'];
		$date     = date_lisible( (string) $v['cree_le'] );
		$contexte = '<span class="yn-visually-hidden"> ' . esc_html(
			/* translators: %s : date de la version */
			sprintf( __( '(version du %s)', 'yume-core' ), $date )
		) . '</span>';
		$html .= '<tr><td>' . esc_html( $date );
		if ( $id === $courante ) {
			$html .= ' <span class="yn-chip yn-chip--ok">' . esc_html__( 'En ligne', 'yume-core' ) . '</span>';
		}
		if ( '' !== (string) $v['note'] ) {
			$html .= '<br><span class="yn-muted">' . esc_html( (string) $v['note'] ) . '</span>';
		}
		$html .= '</td><td>' . esc_html( nom_auteur( (int) $v['user_id'] ) ) . '</td><td>' . esc_html( libelle_source( (string) $v['source'] ) ) . '</td><td>' . (int) $v['nb_entrees'] . '</td>';
		$html .= '<td><div class="yn-glossaire-eq__actions"><a class="yn-btn yn-btn--sm" href="' . esc_url( url_telechargement( $id ) ) . '" download>' . esc_html__( 'Télécharger ce YAML', 'yume-core' ) . $contexte . '</a>';
		if ( $id !== $courante ) {
			$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_formulaire( 'restaurer', $oeuvre_id );
			$html .= '<input type="hidden" name="version" value="' . $id . '">';
			$html .= '<button type="submit" class="yn-btn yn-btn--sm" data-yn-confirmer="' . esc_attr__( 'Remplacer le glossaire en ligne par cette version ?', 'yume-core' ) . '">' . esc_html__( 'Restaurer cette version', 'yume-core' ) . $contexte . '</button></form>';
		}
		$html .= '</div></td></tr>';
	}
	return $html . '</tbody></table></div></section>';
}

/**
 * Œuvres qui ont un glossaire (titre, entrées, date).
 *
 * @return array<int,array{titre:string,entrees:int,maj:string}>
 */
function oeuvres_avec_glossaire(): array {
	$ids     = get_posts(
		array(
			'post_type'        => 'yume_oeuvre',
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'meta_key'         => META_ETAT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'           => 'ids',
			'posts_per_page'   => 100,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		)
	);
	$oeuvres = array();
	foreach ( $ids as $id ) {
		$etat = etat_glossaire( (int) $id );
		if ( $etat['version'] ) {
			$oeuvres[ (int) $id ] = array(
				'titre'   => titre_oeuvre( (int) $id ),
				'entrees' => $etat['entrees'],
				'maj'     => $etat['maj'],
			);
		}
	}
	return $oeuvres;
}

/**
 * Vue « Glossaires » (?vue=glossaire).
 */
function rendu_vue_glossaire(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--glossaire' );
	$html .= navigation_equipe( 'glossaire' );
	$html .= '<div class="yn-team__principal">';
	if ( ! current_user_can( CAPACITE ) ) {
		$html .= tete_vue( __( 'Glossaires', 'yume-core' ), '' );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent publier un glossaire.', 'yume-core' ) . '</p>';
		$html .= '<p><a class="yn-btn" href="' . esc_url( url_vue_equipe() ) . '">' . esc_html__( 'Retour au tableau de bord', 'yume-core' ) . '</a></p></div>';
		return $html . '</div></div>';
	}
	wp_enqueue_style( 'yume-glossaire-equipe' );
	$user_id   = get_current_user_id();
	$retour    = retour_formulaire( $user_id );
	$brouillon = brouillon( $user_id );
	$oeuvre_id = oeuvre_vue();
	if ( ! $oeuvre_id && $brouillon ) {
		$oeuvre_id = (int) $brouillon['oeuvre'];
	}

	$html .= tete_vue( __( 'Glossaires', 'yume-core' ), '' );
	$html .= '<p class="yn-muted">' . esc_html__( 'Le glossaire d’une œuvre vient de Yume-Trad (fichier glossaire.yaml). Téléversez-le ici, ou laissez l’application l’envoyer directement : chaque envoi remplace le glossaire de l’œuvre et reste dans l’historique.', 'yume-core' ) . '</p>';
	$html .= zone_retour_glossaire( $retour );

	// Glossaire vérifié, en attente de publication.
	if ( $brouillon ) {
		$b     = (array) $brouillon['bilan'];
		$html .= '<section class="yn-card yn-team__carte yn-glossaire-eq__bilan" id="yn-glossaire-bilan" aria-labelledby="yn-glossaire-bilan-titre">';
		$html .= '<h2 id="yn-glossaire-bilan-titre" class="yn-label">' . esc_html(
			sprintf(
				/* translators: 1: nom du fichier, 2: titre de l'œuvre */
				__( 'Vérification : %1$s pour %2$s', 'yume-core' ),
				(string) $brouillon['fichier'],
				titre_oeuvre( (int) $brouillon['oeuvre'] )
			)
		) . '</h2>';
		$html .= html_bilan( $b );
		$html .= '<form class="yn-glossaire-eq__boutons" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_formulaire( 'publier_brouillon', (int) $brouillon['oeuvre'] );
		$html .= '<button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Publier le glossaire', 'yume-core' ) . '</button>';
		$html .= '<button type="submit" class="yn-btn" name="op" value="annuler">' . esc_html__( 'Annuler', 'yume-core' ) . '</button></form>';
		$html .= '</section>';
	}

	// Téléversement.
	$choix = choix_oeuvres( __( 'Choisir une œuvre', 'yume-core' ) );
	$html .= '<section class="yn-card yn-team__carte yn-glossaire-eq__envoi" aria-labelledby="yn-glossaire-envoi-titre">';
	$html .= '<h2 id="yn-glossaire-envoi-titre" class="yn-label">' . esc_html__( 'Téléverser un glossaire', 'yume-core' ) . '</h2>';
	$html .= '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . champs_formulaire( 'verifier' );
	$html .= '<div class="yn-glossaire-eq__champs">';
	$html .= champ_select( 'yn-glossaire-oeuvre', 'oeuvre', __( 'Œuvre', 'yume-core' ), $choix, $oeuvre_id ? (string) $oeuvre_id : '', array( 'required' => true ) );
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-glossaire-fichier">' . esc_html__( 'Fichier YAML (4 Mo au plus)', 'yume-core' ) . '</label>';
	$html .= '<input type="file" id="yn-glossaire-fichier" name="glossaire" accept=".yaml,.yml,application/yaml,text/yaml" required aria-describedby="yn-glossaire-fichier-aide"></p>';
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-glossaire-note">' . esc_html__( 'Note (facultative)', 'yume-core' ) . '</label>';
	$html .= '<input type="text" id="yn-glossaire-note" name="note" maxlength="255"></p>';
	$html .= '</div><p class="yn-muted" id="yn-glossaire-fichier-aide">' . esc_html__( '« Vérifier » analyse le fichier sans rien publier : vous voyez le nombre d’entrées et les éventuelles entrées ignorées avant de confirmer.', 'yume-core' ) . '</p>';
	$html .= '<p class="yn-glossaire-eq__boutons"><button type="submit" class="yn-btn yn-btn--primary" name="op" value="verifier">' . esc_html__( 'Vérifier', 'yume-core' ) . '</button>';
	$html .= '<button type="submit" class="yn-btn" name="op" value="publier">' . esc_html__( 'Publier le glossaire', 'yume-core' ) . '</button></p>';
	$html .= '</form></section>';

	// Historique de l'œuvre choisie, ou liste des œuvres qui ont un glossaire.
	if ( $oeuvre_id ) {
		$html .= section_historique( $oeuvre_id );
		$html .= '<p><a href="' . esc_url( url_vue_equipe( 'glossaire' ) ) . '">' . esc_html__( 'Toutes les œuvres', 'yume-core' ) . '</a></p>';
	} else {
		$oeuvres = oeuvres_avec_glossaire();
		$html   .= '<section class="yn-card yn-team__carte" aria-labelledby="yn-glossaire-oeuvres-titre"><h2 id="yn-glossaire-oeuvres-titre" class="yn-label">' . esc_html__( 'Œuvres avec un glossaire', 'yume-core' ) . '</h2>';
		if ( ! $oeuvres ) {
			$html .= '<p class="yn-muted">' . esc_html__( 'Aucune œuvre n’a encore de glossaire.', 'yume-core' ) . '</p>';
		} else {
			$html .= '<ul class="yn-glossaire-eq__oeuvres">';
			foreach ( $oeuvres as $id => $o ) {
				$html .= '<li><a href="' . esc_url( url_vue_equipe( 'glossaire', array( 'oeuvre' => $id ) ) . '#yn-glossaire-historique' ) . '">' . esc_html( $o['titre'] ) . '</a> <span class="yn-muted">' . esc_html(
					sprintf(
						/* translators: 1: nombre d'entrées, 2: date */
						_n( '%1$d entrée · %2$s', '%1$d entrées · %2$s', max( 1, $o['entrees'] ), 'yume-core' ),
						$o['entrees'],
						date_lisible( $o['maj'] )
					)
				) . '</span></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section>';
	}

	// Connecteur Yume-Trad.
	$route = rest_url( 'yume/v1/oeuvres/' . ( $oeuvre_id ? (string) get_post_field( 'post_name', $oeuvre_id ) : '{oeuvre}' ) . '/glossaire' );
	$html .= '<section class="yn-card yn-team__carte yn-glossaire-eq__api" aria-labelledby="yn-glossaire-api-titre"><h2 id="yn-glossaire-api-titre" class="yn-label">' . esc_html__( 'Envoi direct depuis Yume-Trad', 'yume-core' ) . '</h2>';
	$html .= '<p>' . esc_html__( 'Créez un mot de passe d’application (Profil → Mots de passe d’application), puis donnez à Yume-Trad votre identifiant, ce mot de passe et l’adresse ci-dessous. L’application envoie le glossaire quand vous le décidez ; il n’y a aucune liaison permanente.', 'yume-core' ) . '</p>';
	$html .= '<p><code class="yn-glossaire-eq__route">POST ' . esc_html( $route ) . '</code></p>';
	$html .= '<p class="yn-muted">' . esc_html__( 'Mode d’emploi et exemples : docs/glossaire.md.', 'yume-core' ) . '</p></section>';

	return $html . '</div></div>';
}

/**
 * Traite un envoi de la vue : vérifier, publier (fichier), publier_brouillon, annuler, restaurer.
 *
 * @param array $post    Données POST (brutes).
 * @param array $files   Fichiers ($_FILES).
 * @param int   $user_id Compte.
 * @return array{type:string,message:string,oeuvre:int}
 */
function traiter_formulaire_glossaire( array $post, array $files, int $user_id ): array {
	$op        = isset( $post['op'] ) && is_scalar( $post['op'] ) ? sanitize_key( (string) $post['op'] ) : '';
	$nonce     = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$oeuvre_id = isset( $post['oeuvre'] ) && is_scalar( $post['oeuvre'] ) ? absint( $post['oeuvre'] ) : 0;
	$retour    = static function ( string $type, string $message ) use ( &$oeuvre_id ): array {
		return array(
			'type'    => $type,
			'message' => $message,
			'oeuvre'  => $oeuvre_id,
		);
	};
	if ( ! wp_verify_nonce( $nonce, 'yume_glossaire' ) ) {
		return $retour( 'erreur', __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	if ( ! user_can( $user_id, CAPACITE ) ) {
		return $retour( 'erreur', __( 'Votre compte ne peut pas gérer les glossaires.', 'yume-core' ) );
	}
	$note = isset( $post['note'] ) && is_scalar( $post['note'] ) ? sanitize_text_field( wp_unslash( (string) $post['note'] ) ) : '';

	switch ( $op ) {
		case 'annuler':
			oublier_brouillon( $user_id );
			return $retour( 'ok', __( 'Vérification abandonnée : rien n’a été publié.', 'yume-core' ) );

		case 'restaurer':
			$version_id = isset( $post['version'] ) && is_scalar( $post['version'] ) ? absint( $post['version'] ) : 0;
			$version    = version( $version_id );
			if ( ! $version || ( $oeuvre_id && (int) $version['oeuvre_id'] !== $oeuvre_id ) ) {
				return $retour( 'erreur', __( 'Version introuvable.', 'yume-core' ) );
			}
			$oeuvre_id = (int) $version['oeuvre_id'];
			$bilan     = restaurer_version( $version_id, $user_id );
			break;

		case 'publier_brouillon':
			$brouillon = brouillon( $user_id );
			$ligne     = $brouillon ? ligne_brouillon( (int) $brouillon['id'], $user_id, (string) $brouillon['sha256'] ) : null;
			if ( ! $ligne || ( $oeuvre_id && (int) $ligne['oeuvre_id'] !== $oeuvre_id ) ) {
				return $retour( 'erreur', __( 'La vérification a expiré : téléversez de nouveau le fichier.', 'yume-core' ) );
			}
			$oeuvre_id = (int) $ligne['oeuvre_id'];
			$bilan     = importer(
				$oeuvre_id,
				(string) $ligne['yaml'],
				array(
					'user_id' => $user_id,
					'source'  => 'televersement',
					'note'    => (string) $ligne['note'],
				)
			);
			if ( ! is_wp_error( $bilan ) ) {
				oublier_brouillon( $user_id );
			}
			break;

		case 'verifier':
		case 'publier':
			if ( 'yume_oeuvre' !== get_post_type( $oeuvre_id ) ) {
				return $retour( 'erreur', __( 'Choisissez une œuvre.', 'yume-core' ) );
			}
			$fichier = isset( $files['glossaire'] ) && is_array( $files['glossaire'] ) ? $files['glossaire'] : array();
			$yaml    = lire_fichier_televerse( $fichier );
			if ( is_wp_error( $yaml ) ) {
				return $retour( 'erreur', $yaml->get_error_message() );
			}
			$bilan = importer(
				$oeuvre_id,
				$yaml,
				array(
					'user_id'    => $user_id,
					'source'     => 'televersement',
					'note'       => $note,
					'simulation' => 'verifier' === $op,
				)
			);
			if ( ! is_wp_error( $bilan ) && 'verifier' === $op ) {
				// Un seul brouillon par compte : le précédent est remplacé.
				oublier_brouillon( $user_id );
				$id = enregistrer_brouillon( $oeuvre_id, $user_id, $yaml, (string) $bilan['sha256'], (int) $bilan['total'], $note );
				if ( ! $id ) {
					return $retour( 'erreur', __( 'La vérification n’a pas pu être enregistrée : réessayez.', 'yume-core' ) );
				}
				// Transient : identifiant et empreinte (plus le bilan affiché), jamais le YAML.
				set_transient(
					cle_brouillon( $user_id ),
					array(
						'id'      => $id,
						'sha256'  => (string) $bilan['sha256'],
						'oeuvre'  => $oeuvre_id,
						'fichier' => mb_substr( sanitize_file_name( (string) ( $fichier['name'] ?? 'glossaire.yaml' ) ), 0, 120 ),
						'bilan'   => $bilan,
					),
					DUREE_BROUILLON
				);
				return $retour(
					'ok',
					sprintf(
						/* translators: %d : nombre d'entrées */
						_n( 'Fichier valide : %d entrée. Vérifiez le bilan puis publiez.', 'Fichier valide : %d entrées. Vérifiez le bilan puis publiez.', max( 1, (int) $bilan['total'] ), 'yume-core' ),
						(int) $bilan['total']
					)
				);
			}
			if ( ! is_wp_error( $bilan ) ) {
				oublier_brouillon( $user_id );
			}
			break;

		default:
			return $retour( 'erreur', __( 'Action inconnue.', 'yume-core' ) );
	}

	if ( is_wp_error( $bilan ) ) {
		return $retour( 'erreur', $bilan->get_error_message() );
	}
	if ( 'inchange' === $bilan['statut'] ) {
		return $retour( 'ok', __( 'Glossaire inchangé : il est identique à la version en ligne.', 'yume-core' ) );
	}
	return $retour(
		'ok',
		sprintf(
			/* translators: 1: titre de l'œuvre, 2: nombre d'entrées */
			_n( 'Glossaire de %1$s publié : %2$d entrée.', 'Glossaire de %1$s publié : %2$d entrées.', max( 1, (int) $bilan['total'] ), 'yume-core' ),
			titre_oeuvre( $oeuvre_id ),
			(int) $bilan['total']
		)
	);
}

/**
 * Envoi de la vue (admin-post.php, action yume_glossaire) : traitement puis retour sur la vue.
 */
function admin_post_glossaire(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_glossaire().
	$retour = traiter_formulaire_glossaire( $_POST, $_FILES, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	wp_safe_redirect( url_vue_equipe( 'glossaire', $retour['oeuvre'] ? array( 'oeuvre' => $retour['oeuvre'] ) : array() ) . '#' . RETOUR );
	exit;
}
add_action( 'admin_post_yume_glossaire', __NAMESPACE__ . '\\admin_post_glossaire' );

/**
 * Visiteur non connecté : vers la connexion, retour sur la vue.
 */
function admin_post_glossaire_anonyme(): void {
	wp_safe_redirect( wp_login_url( url_vue_equipe( 'glossaire' ) ) );
	exit;
}
add_action( 'admin_post_nopriv_yume_glossaire', __NAMESPACE__ . '\\admin_post_glossaire_anonyme' );
add_action( 'admin_post_nopriv_yume_glossaire_yaml', __NAMESPACE__ . '\\admin_post_glossaire_anonyme' );

/**
 * Fichier d'une version à télécharger (nonce par version, capacité yume_glossaire).
 *
 * @param array $get     Paramètres GET (bruts).
 * @param int   $user_id Compte.
 * @return array{nom:string,contenu:string}|\WP_Error
 */
function fichier_version( array $get, int $user_id ) {
	$version_id = isset( $get['version'] ) && is_scalar( $get['version'] ) ? absint( $get['version'] ) : 0;
	$nonce      = is_scalar( $get['_wpnonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $get['_wpnonce'] ) ) : '';
	if ( ! $version_id || ! wp_verify_nonce( $nonce, 'yume_glossaire_yaml_' . $version_id ) ) {
		return new \WP_Error( 'yume_nonce', __( 'Lien expiré : rechargez la page puis réessayez.', 'yume-core' ), array( 'status' => 403 ) );
	}
	if ( ! user_can( $user_id, CAPACITE ) ) {
		return new \WP_Error( 'yume_interdit', __( 'Votre compte ne peut pas gérer les glossaires.', 'yume-core' ), array( 'status' => 403 ) );
	}
	$version = version( $version_id );
	if ( ! $version ) {
		return new \WP_Error( 'yume_glossaire_version_introuvable', __( 'Version introuvable.', 'yume-core' ), array( 'status' => 404 ) );
	}
	$slug = (string) get_post_field( 'post_name', (int) $version['oeuvre_id'] );
	$ts   = strtotime( (string) $version['cree_le'] . ' UTC' );
	return array(
		'nom'     => sanitize_file_name( 'glossaire-' . ( '' !== $slug ? $slug : (int) $version['oeuvre_id'] ) . '-' . ( $ts ? wp_date( 'Y-m-d-His', $ts ) : $version_id ) . '.yaml' ),
		'contenu' => (string) $version['yaml'],
	);
}

/**
 * Téléchargement du YAML d'une version (admin-post.php, action yume_glossaire_yaml).
 */
function admin_post_telecharger(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce vérifié dans fichier_version().
	$fichier = fichier_version( $_GET, get_current_user_id() );
	if ( is_wp_error( $fichier ) ) {
		wp_die( esc_html( $fichier->get_error_message() ), '', array( 'response' => (int) ( $fichier->get_error_data()['status'] ?? 403 ) ) );
	}
	nocache_headers();
	header( 'Content-Type: application/yaml; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $fichier['nom'] . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $fichier['contenu']; // phpcs:ignore WordPress.Security.EscapeOutput -- fichier texte téléchargé, servi en pièce jointe (nosniff).
	exit;
}
add_action( 'admin_post_yume_glossaire_yaml', __NAMESPACE__ . '\\admin_post_telecharger' );
