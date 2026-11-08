<?php
/**
 * Espace équipe, vue « Programmer les sorties » (?vue=programmer&tome=ID, menu Catalogue,
 * capacité yume_publier) : calendrier sur mesure des chapitres BROUILLONS et PROGRAMMÉS d'un
 * tome existant, avec le panneau du formulaire de publication (Calendrier::panneau(),
 * programmer.js) : nombre de publications et « Répartir » (par nombre de mots), coupures
 * « Nouvelle publication ici », date de chaque publication, « Recalculer les dates »,
 * « Tout en une publication », « Une par chapitre ».
 *
 * Enregistrement : POST /yume/v1/publications/{id}/calendrier (script), ou sans JavaScript
 * admin-post.php?action=yume_programmer_sorties (nombre de publications, date de départ,
 * intervalle) ; les deux appellent Calendrier::reprogrammer() : brouillons programmés, chapitres
 * déjà programmés reprogrammés, une annonce par publication, journal « calendrier ».
 * Sans tome choisi : liste des tomes que le compte peut modifier.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

use Yume\Core\Publication\Calendrier;
use Yume\Core\Publication\Formulaire;
use Yume\Core\Publication\Service;

defined( 'ABSPATH' ) || exit;

/** Clé de la vue « Programmer les sorties ». */
const VUE_PROGRAMMER = 'programmer';

/**
 * Adresse de la vue « Programmer les sorties ».
 *
 * @param int $tome_id Tome (0 : choix du tome).
 */
function url_programmer( int $tome_id = 0 ): string {
	return url_vue_equipe( VUE_PROGRAMMER, array( 'tome' => $tome_id ) );
}

/**
 * Déclare la vue dans l'espace équipe (filtre yume_vues_equipe), menu Catalogue.
 *
 * @param array $vues Vues.
 * @return array
 */
function declarer_vue_programmer( $vues ): array {
	$vues = is_array( $vues ) ? $vues : array();
	if ( class_exists( Calendrier::class ) ) {
		$vues[ VUE_PROGRAMMER ] = array(
			'libelle'  => __( 'Programmer les sorties', 'yume-core' ),
			'capacite' => 'yume_publier',
			'groupe'   => 'catalogue',
			'rendu'    => __NAMESPACE__ . '\\rendu_vue_programmer',
		);
	}
	return $vues;
}
add_filter( 'yume_vues_equipe', __NAMESPACE__ . '\\declarer_vue_programmer' );

/**
 * Le compte peut-il programmer les sorties de ce tome (yume_publier, modifier et publier) ?
 *
 * @param int $tome_id Tome.
 * @param int $user_id Compte (0 : compte courant).
 */
function peut_programmer( int $tome_id, int $user_id = 0 ): bool {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return user_can( $user_id, 'yume_publier' ) && user_can( $user_id, 'edit_post', $tome_id ) && user_can( $user_id, 'publish_yume_tomes' );
}

/**
 * Choix du tome (sans ?tome=) : formulaire GET listant les tomes que le compte peut modifier.
 */
function choix_tome_programmer(): string {
	$tomes = Formulaire::tomes_formulaire();
	$html  = '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Choisir le tome', 'yume-core' ) . '">' . champs_caches_vue( VUE_PROGRAMMER );
	$html .= '<p class="yn-team__champ"><label class="yn-label" for="yn-programmer-tome">' . esc_html__( 'Tome', 'yume-core' ) . '</label><select id="yn-programmer-tome" name="tome" required>';
	$html .= '<option value="">' . esc_html__( '— Choisir un tome —', 'yume-core' ) . '</option>';
	foreach ( $tomes as $t ) {
		$html .= '<option value="' . esc_attr( (string) $t['id'] ) . '">' . esc_html( get_the_title( (int) $t['oeuvre_id'] ) . ' · ' . $t['libelle'] ) . '</option>';
	}
	$html .= '</select></p><p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Afficher', 'yume-core' ) . '</button></p></form>';
	return $html;
}

/**
 * Vue « Programmer les sorties ».
 */
function rendu_vue_programmer(): string {
	$html  = ouvrir_racine( 'yn-team yn-team--vue yn-team--programmer' );
	$html .= navigation_equipe( VUE_PROGRAMMER );
	$html .= '<div class="yn-team__principal">';
	$titre = __( 'Programmer les sorties', 'yume-core' );
	if ( ! current_user_can( 'yume_publier' ) ) {
		return acces_refuse_vue( $html, $titre, __( 'Seuls les rôles « Éditeur Yume » et « Gérant » peuvent programmer les sorties.', 'yume-core' ) );
	}
	$tome_id = get_entier( 'tome' );
	if ( ! $tome_id ) {
		$html .= tete_vue( $titre, '' );
		$html .= '<p class="yn-muted">' . esc_html__( 'Choisissez le tome : ses chapitres en brouillon et déjà programmés s’affichent, à répartir en publications et à dater.', 'yume-core' ) . '</p>';
		return $html . choix_tome_programmer() . '</div></div>';
	}
	$tome = get_post( $tome_id );
	if ( ! $tome || 'yume_tome' !== $tome->post_type || in_array( $tome->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return acces_refuse_vue( $html, $titre, __( 'Ce tome n’existe pas ou a été supprimé.', 'yume-core' ), url_programmer(), $titre );
	}
	if ( ! peut_programmer( $tome_id ) ) {
		return acces_refuse_vue( $html, $titre, __( 'Votre rôle ne permet pas de programmer les sorties de ce tome.', 'yume-core' ), url_vue_equipe( 'tomes' ), __( 'Tous les tomes', 'yume-core' ) );
	}
	wp_enqueue_script( Calendrier::POIGNEE );
	wp_enqueue_style( Calendrier::POIGNEE );

	$retour = retour_formulaire( get_current_user_id() );
	$retour = $retour && 'yn-programmer-vue' === ( $retour['cible'] ?? '' ) && (int) ( $retour['tome_id'] ?? 0 ) === $tome_id ? $retour : null;
	$config = Calendrier::config( $tome_id, true );
	$rythme = Service::rythme_texte( $tome_id );

	$boutons  = '<a class="yn-btn" href="' . esc_url( url_modifier_tome( $tome_id ) ) . '">' . esc_html__( 'Fiche du tome', 'yume-core' ) . '</a>';
	$boutons .= '<a class="yn-btn yn-btn--primary" href="' . esc_url( url_publier_tome( $tome_id ) ) . '">' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . '</a>';
	$html    .= '<p class="yn-fiche__retour"><a href="' . esc_url( url_modifier_tome( $tome_id ) . '#yn-tome-chapitres' ) . '"><span aria-hidden="true">←</span> ' . esc_html( titre_brut( $tome_id ) ) . '</a></p>';
	/* translators: %s : tome (« Œuvre — Tome 2 ») */
	$html .= tete_vue( sprintf( __( 'Programmer les sorties : %s', 'yume-core' ), titre_brut( $tome_id ) ), $boutons );

	if ( ! $config['unites'] ) {
		$html .= zone_retour( $retour );
		$html .= '<div class="yn-card yn-team__acces"><p>' . esc_html__( 'Ce tome n’a aucun chapitre en brouillon ni programmé : rien à programmer. Déposez ses chapitres avec « Ajouter des chapitres ».', 'yume-core' ) . '</p></div>';
		return $html . '</div></div>';
	}

	$html .= '<form class="yn-programmer-vue" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-programmer-vue data-tome="' . esc_attr( (string) $tome_id ) . '" aria-label="' . esc_attr( $titre ) . '">';
	$html .= '<input type="hidden" name="action" value="' . esc_attr( Calendrier::ACTION ) . '"><input type="hidden" name="tome_id" value="' . esc_attr( (string) $tome_id ) . '">';
	$html .= wp_nonce_field( Calendrier::ACTION . '_' . $tome_id, '_yume_nonce', true, false );
	$html .= zone_retour( $retour );
	$html .= '<div class="yn-card yn-programmer-vue__reglages">';
	if ( '' !== $rythme ) {
		/* translators: %s : rythme (« chaque samedi à 18 h ») */
		$html .= '<p class="yn-muted">' . esc_html( sprintf( __( 'Rythme du tome : %s. Les dates proposées le suivent à partir de maintenant ; modifiez-les une à une, ou la première puis « Recalculer les dates ».', 'yume-core' ), $rythme ) ) . '</p>';
	} else {
		$html .= champ_saisie( 'yn-programmer-depart', 'depart', __( 'Première publication (vide : maintenant)', 'yume-core' ), '', 'datetime-local', array( 'data-yn-programmer-depart' => '' ) );
		$html .= champ_saisie(
			'yn-programmer-intervalle',
			'intervalle',
			__( 'Une publication tous les (jours)', 'yume-core' ),
			(string) Service::INTERVALLE_DEFAUT,
			'number',
			array(
				'min'                           => 1,
				'max'                           => 60,
				'step'                          => 1,
				'data-yn-programmer-intervalle' => '',
			)
		);
	}
	$annoncer = ! Service::sans_annonce_par_defaut( $tome_id );
	$html    .= '<p class="yn-team__champ--case"><input type="hidden" name="annoncer" value="0"><label class="yn-team__case" for="yn-programmer-annoncer"><input type="checkbox" id="yn-programmer-annoncer" name="annoncer" value="1" data-yn-programmer-annoncer' . checked( $annoncer, true, false ) . '> ' . esc_html__( 'Annoncer chaque publication (Discord, e-mail aux lecteurs qui suivent l’œuvre)', 'yume-core' ) . '</label></p>';
	$html    .= '</div>';
	$html    .= Calendrier::panneau(
		$config,
		array(
			'id'   => 'yn-programmer-vue',
			'aide' => __( 'Sans JavaScript : indiquez le nombre de publications puis enregistrez. Les chapitres sont répartis par nombre de mots et datés au rythme du tome, sinon à partir de la première date puis tous les N jours.', 'yume-core' ),
		)
	);
	$html    .= '<p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer le calendrier', 'yume-core' ) . '</button></p>';
	$html    .= '<p class="yn-muted">' . esc_html__( 'Les chapitres déjà programmés prennent leurs nouvelles dates (annonces déplacées avec eux) ; les brouillons sont programmés. Les chapitres en ligne ne changent pas.', 'yume-core' ) . '</p>';
	$html    .= '</form>';
	return $html . '</div></div>';
}

/**
 * Traite l'enregistrement sans JavaScript (admin-post.php?action=yume_programmer_sorties).
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,tome_id:int}
 */
function traiter_programmer( array $post, int $user_id ): array {
	$tome_id = isset( $post['tome_id'] ) && is_scalar( $post['tome_id'] ) ? absint( $post['tome_id'] ) : 0;
	$retour  = array(
		'type'    => 'erreur',
		'message' => '',
		'cible'   => 'yn-programmer-vue',
		'tome_id' => $tome_id,
	);
	$nonce   = isset( $post['_yume_nonce'] ) && is_string( $post['_yume_nonce'] ) ? sanitize_text_field( wp_unslash( $post['_yume_nonce'] ) ) : '';
	if ( ! $tome_id || ! wp_verify_nonce( $nonce, Calendrier::ACTION . '_' . $tome_id ) ) {
		$retour['message'] = __( 'La session a expiré : rechargez la page et recommencez.', 'yume-core' );
		return $retour;
	}
	if ( 'yume_tome' !== get_post_type( $tome_id ) || ! peut_programmer( $tome_id, $user_id ) ) {
		$retour['message'] = __( 'Votre rôle ne permet pas de programmer les sorties de ce tome.', 'yume-core' );
		return $retour;
	}
	$texte    = static function ( string $cle ) use ( $post ): string {
		return isset( $post[ $cle ] ) && is_scalar( $post[ $cle ] ) ? sanitize_text_field( wp_unslash( (string) $post[ $cle ] ) ) : '';
	};
	$annoncer = isset( $post['annoncer'] ) && is_scalar( $post['annoncer'] ) ? rest_sanitize_boolean( $texte( 'annoncer' ) ) : null;
	$resultat = Calendrier::reprogrammer(
		$tome_id,
		array(
			// JSON contrôlé strictement par Calendrier::analyser().
			'calendrier'   => isset( $post['calendrier'] ) && is_string( $post['calendrier'] ) ? wp_unslash( $post['calendrier'] ) : '',
			'repartition'  => absint( $texte( 'repartition' ) ),
			'depart'       => $texte( 'depart' ),
			'intervalle'   => '' !== $texte( 'intervalle' ) ? absint( $texte( 'intervalle' ) ) : Service::INTERVALLE_DEFAUT,
			'sans_annonce' => null === $annoncer ? null : ! $annoncer,
		)
	);
	if ( is_wp_error( $resultat ) ) {
		$retour['message'] = $resultat->get_error_message();
		return $retour;
	}
	$retour['type']    = 'ok';
	$retour['message'] = (string) $resultat['message'];
	return $retour;
}

/**
 * Formulaire « Programmer les sorties » envoyé sans JavaScript.
 */
function admin_post_programmer(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_programmer().
	$retour = traiter_programmer( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	wp_safe_redirect( ( $retour['tome_id'] ? url_programmer( (int) $retour['tome_id'] ) : url_programmer() ) . '#yn-programmer-vue-titre' );
	exit;
}
add_action( 'admin_post_yume_programmer_sorties', __NAMESPACE__ . '\\admin_post_programmer' );
add_action( 'admin_post_nopriv_yume_programmer_sorties', __NAMESPACE__ . '\\admin_post_anonyme' );
