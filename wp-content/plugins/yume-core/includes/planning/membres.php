<?php
/**
 * Page « Membres et rôles » de l'espace équipe (bloc yume/team-members, page `equipe/membres`,
 * capacité yume_gerer_equipe) : liste des membres de l'équipe avec leur rôle, changement de
 * rôle, ajout d'un compte existant (un lecteur, par exemple) et retrait de l'équipe (retour au
 * rôle Lecteur).
 *
 * Seuls les comptes dont tous les rôles sont dans Core\roles_gerables() (Lecteur et rôles de
 * l'équipe) sont modifiables, et seulement vers un rôle de l'équipe ou Lecteur : jamais un
 * administrateur, un gérant ni son propre compte, y compris pour un administrateur (qui passe
 * par l'administration pour ces comptes). Chaque action vérifie aussi
 * current_user_can( 'promote_user', $id ) (règles de Core\limiter_gestion_membres()).
 *
 * Les formulaires sont envoyés à admin-post.php (action yume_equipe_membres, nonce).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Règles
 * -----------------------------------------------------------------------------
 */

/**
 * Rôles de l'équipe attribuables depuis cette page (dans l'ordre d'affichage) : rôles de
 * l'équipe permis par Core\roles_gerables(), hors Lecteur, et existants.
 *
 * @return array<string,string> slug => nom affiché.
 */
function roles_equipe_attribuables(): array {
	$gerables = function_exists( '\Yume\Core\Core\roles_gerables' ) ? \Yume\Core\Core\roles_gerables() : array();
	$roles    = wp_roles()->roles;
	$liste    = array();
	foreach ( $gerables as $slug ) {
		if ( 'subscriber' !== $slug && isset( $roles[ $slug ] ) ) {
			$liste[ $slug ] = translate_user_role( $roles[ $slug ]['name'] );
		}
	}
	return $liste;
}

/**
 * L'utilisateur peut-il changer le rôle de ce compte depuis la page « Membres et rôles » ?
 *
 * @param int $acteur Utilisateur qui agit.
 * @param int $cible  Compte visé.
 */
function peut_gerer_membre( int $acteur, int $cible ): bool {
	if ( $acteur <= 0 || $cible <= 0 || $acteur === $cible || ! user_can( $acteur, 'yume_gerer_equipe' ) ) {
		return false;
	}
	$compte = get_userdata( $cible );
	if ( ! $compte instanceof \WP_User || is_super_admin( $cible ) ) {
		return false;
	}
	$gerables = function_exists( '\Yume\Core\Core\roles_gerables' ) ? \Yume\Core\Core\roles_gerables() : array();
	if ( array_diff( (array) $compte->roles, $gerables ) ) {
		return false;
	}
	return user_can( $acteur, 'promote_user', $cible );
}

/**
 * Membres de l'équipe (comptes qui voient l'espace équipe), par pseudo.
 *
 * @return \WP_User[]
 */
function comptes_equipe(): array {
	return get_users(
		array(
			'capability' => 'yume_voir_equipe',
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		)
	);
}

/**
 * Adresse de la page « Membres et rôles » si elle existe (option yume_pages ou equipe/membres),
 * sinon ''.
 */
function url_page_membres(): string {
	$pages = get_option( 'yume_pages', array() );
	$id    = is_array( $pages ) ? (int) ( $pages['membres'] ?? 0 ) : 0;
	$page  = $id ? get_post( $id ) : get_page_by_path( 'equipe/membres' );
	if ( $page instanceof \WP_Post && 'page' === $page->post_type && in_array( $page->post_status, array( 'publish', 'private' ), true ) ) {
		return (string) get_permalink( $page );
	}
	return '';
}

/**
 * Cible du lien « Membres et rôles » pour l'utilisateur courant : la page de l'espace équipe
 * (yume_gerer_equipe), sinon, si elle n'existe pas encore, la liste des utilisateurs de
 * l'administration (list_users) ; '' si aucun des deux n'est permis.
 */
function url_membres(): string {
	if ( current_user_can( 'yume_gerer_equipe' ) ) {
		$page = url_page_membres();
		if ( '' !== $page ) {
			return $page;
		}
	}
	return current_user_can( 'list_users' ) ? admin_url( 'users.php' ) : '';
}

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Ligne d'un membre : pseudo, identifiant, rôle ; formulaire de changement de rôle et de
 * retrait si le compte est modifiable.
 *
 * @param \WP_User   $membre Membre.
 * @param array      $roles  Rôles attribuables.
 * @param array|null $retour Retour sans JavaScript (si ce formulaire est concerné).
 */
function ligne_membre( \WP_User $membre, array $roles, ?array $retour ): string {
	$id     = (int) $membre->ID;
	$ancre  = 'yn-membre-' . $id;
	$titre  = $ancre . '-nom';
	$nom    = (string) $membre->display_name;
	$role   = nom_role( $membre );
	$html   = '<li class="yn-team__ligne yn-team__membre" id="' . esc_attr( $ancre ) . '">';
	$html  .= '<div class="yn-team__membre-tete"><span class="yn-team__avatar" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $nom, 0, 1 ) ) ) . '</span>';
	$html  .= '<span class="yn-team__membre-id"><span class="yn-team__nom" id="' . esc_attr( $titre ) . '">' . esc_html( $nom ) . '</span>';
	$html  .= '<span class="yn-muted">' . esc_html( $membre->user_login ) . '</span></span>';
	$html  .= '<span class="yn-chip yn-chip--info">' . esc_html( '' !== $role ? $role : __( 'Sans rôle', 'yume-core' ) ) . '</span></div>';
	$acteur = get_current_user_id();
	if ( ! peut_gerer_membre( $acteur, $id ) ) {
		$motif = $id === $acteur
			? __( 'Votre compte : votre rôle ne se change pas ici.', 'yume-core' )
			: __( 'Administrateur ou gérant : non modifiable depuis l’espace équipe.', 'yume-core' );
		return $html . '<p class="yn-muted yn-team__membre-note">' . esc_html( $motif ) . '</p></li>';
	}
	$actuel = (string) ( array_values( (array) $membre->roles )[0] ?? '' );
	$html  .= '<form class="yn-team__membre-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" aria-labelledby="' . esc_attr( $titre ) . '">';
	$html  .= '<input type="hidden" name="action" value="yume_equipe_membres">';
	$html  .= '<input type="hidden" name="user_id" value="' . $id . '">';
	$html  .= wp_nonce_field( 'yume_membres_' . $id, '_yume_nonce', true, false );
	$html  .= champ_select( 'yn-membre-' . $id . '-role', 'role', __( 'Rôle', 'yume-core' ), $roles, $actuel );
	$html  .= '<p class="yn-team__action"><button type="submit" name="op" value="role" class="yn-btn yn-btn--primary">' . esc_html__( 'Changer le rôle', 'yume-core' ) . '</button></p>';
	$html  .= '<p class="yn-team__action"><button type="submit" name="op" value="retrait" class="yn-btn">' . esc_html__( 'Retirer de l’équipe', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $nom ) . '</span></button></p>';
	return $html . zone_retour( $retour ) . '</form></li>';
}

/**
 * Formulaire « Ajouter un membre » (compte existant).
 *
 * @param array      $roles  Rôles attribuables.
 * @param array|null $retour Retour sans JavaScript.
 */
function formulaire_ajout_membre( array $roles, ?array $retour ): string {
	$html  = '<form class="yn-card yn-team__ajout" id="yn-ajouter-membre-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" aria-labelledby="yn-ajouter-membre">';
	$html .= '<input type="hidden" name="action" value="yume_equipe_membres"><input type="hidden" name="op" value="ajout">';
	$html .= wp_nonce_field( 'yume_membres_ajout', '_yume_nonce', true, false );
	$html .= '<div class="yn-team__grille">';
	$html .= champ_saisie(
		'yn-membre-compte',
		'compte',
		__( 'Identifiant ou e-mail du compte', 'yume-core' ),
		'',
		'text',
		array(
			'required'     => true,
			'maxlength'    => 100,
			'autocomplete' => 'off',
		)
	);
	$html .= champ_select( 'yn-membre-role', 'role', __( 'Rôle dans l’équipe', 'yume-core' ), $roles, (string) array_key_first( $roles ) );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Ajouter à l’équipe', 'yume-core' ) . '</button></p>';
	$html .= '</div><p class="yn-muted">' . esc_html__( 'Le compte doit déjà exister : la personne s’inscrit comme lecteur sur le site, puis vous l’ajoutez ici.', 'yume-core' ) . '</p>';
	return $html . zone_retour( $retour ) . '</form>';
}

/**
 * Rendu du bloc « Membres et rôles » (aucun attribut).
 */
function rendu_team_members(): string {
	if ( ! is_user_logged_in() ) {
		$page = url_page_membres();
		return acces_equipe(
			__( 'Membres et rôles', 'yume-core' ),
			__( 'Connectez-vous avec votre compte de gérant pour gérer les membres de l’équipe.', 'yume-core' ),
			'<a class="yn-btn yn-btn--primary" href="' . esc_url( wp_login_url( '' !== $page ? $page : yume_url_page( 'equipe' ) ) ) . '">' . esc_html__( 'Se connecter', 'yume-core' ) . '</a>'
		);
	}
	if ( ! current_user_can( 'yume_gerer_equipe' ) ) {
		return acces_equipe(
			__( 'Page réservée aux gérants', 'yume-core' ),
			__( 'Seuls les gérants de l’équipe peuvent attribuer les rôles.', 'yume-core' ),
			current_user_can( 'yume_voir_equipe' ) ? '<a class="yn-btn" href="' . esc_url( yume_url_page( 'equipe' ) ) . '">' . esc_html__( 'Retour à l’espace équipe', 'yume-core' ) . '</a>' : ''
		);
	}

	$uid     = get_current_user_id();
	$retour  = est_apercu_editeur() ? null : retour_formulaire( $uid );
	$roles   = roles_equipe_attribuables();
	$membres = comptes_equipe();
	$pour    = static function ( string $cible ) use ( $retour ): ?array {
		return $retour && ( $retour['cible'] ?? '' ) === $cible ? $retour : null;
	};

	$racine = array( 'id' => 'yn-team' );
	if ( est_apercu_editeur() ) {
		// Aperçu de l'éditeur : formulaires visibles mais inactifs.
		$racine['inert'] = '';
	}
	$html  = '<div ' . attributs_racine( 'yn-team yn-team--membres', $racine ) . '>';
	$html .= navigation_equipe( 'membres' );
	$html .= '<div class="yn-team__principal">';

	// En-tête.
	$html .= '<div class="yn-team__tete"><div><p class="yn-label">' . esc_html__( 'Espace équipe', 'yume-core' ) . '</p>';
	$html .= '<h2 class="yn-team__bonjour">' . esc_html__( 'Membres et rôles', 'yume-core' ) . '</h2></div>';
	if ( current_user_can( 'list_users' ) ) {
		$html .= '<p class="yn-team__boutons"><a class="yn-btn" href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Comptes dans l’administration', 'yume-core' ) . '</a></p>';
	}
	$html .= '</div>';

	// Membres.
	$html .= '<section class="yn-team__section" id="yn-membres" aria-labelledby="yn-membres-titre">';
	/* translators: %d : nombre de membres */
	$html .= '<h2 id="yn-membres-titre">' . esc_html( sprintf( _n( 'Équipe (%d membre)', 'Équipe (%d membres)', count( $membres ), 'yume-core' ), count( $membres ) ) ) . '</h2>';
	$html .= '<p class="yn-muted">' . esc_html__( 'Changer le rôle d’un membre prend effet tout de suite. « Retirer de l’équipe » le repasse Lecteur : il garde son compte, mais n’accède plus à l’espace équipe.', 'yume-core' ) . '</p>';
	$html .= zone_retour( $pour( 'yn-membres' ) );
	if ( $membres ) {
		$html .= '<ul class="yn-card yn-team__liste">';
		foreach ( $membres as $membre ) {
			$html .= ligne_membre( $membre, $roles, $pour( 'yn-membre-' . $membre->ID ) );
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html__( 'Aucun membre dans l’équipe.', 'yume-core' ) . '</p>';
	}
	$html .= '</section>';

	// Ajout.
	$html .= '<section class="yn-team__section" id="yn-ajouter-membre-section" aria-labelledby="yn-ajouter-membre"><h2 id="yn-ajouter-membre">' . esc_html__( 'Ajouter un membre', 'yume-core' ) . '</h2>';
	$html .= formulaire_ajout_membre( $roles, $pour( 'yn-ajouter-membre-form' ) ) . '</section>';

	return $html . '</div></div>';
}

/*
 * -----------------------------------------------------------------------------
 * Formulaires (admin-post.php)
 * -----------------------------------------------------------------------------
 */

/**
 * Compte désigné par son identifiant ou son e-mail.
 *
 * @param string $saisie Identifiant ou e-mail.
 */
function compte_designe( string $saisie ): ?\WP_User {
	$saisie = trim( $saisie );
	if ( '' === $saisie ) {
		return null;
	}
	$compte = is_email( $saisie ) ? get_user_by( 'email', $saisie ) : false;
	if ( ! $compte ) {
		$compte = get_user_by( 'login', sanitize_user( $saisie ) );
	}
	return $compte instanceof \WP_User ? $compte : null;
}

/**
 * Traite un formulaire de la page « Membres et rôles » : op = role (changer le rôle), retrait
 * (retour au rôle Lecteur) ou ajout (compte existant, par identifiant ou e-mail).
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur qui agit.
 * @return array{type:string,message:string,cible:string}
 */
function traiter_formulaire_membres( array $post, int $user_id ): array {
	$op     = is_scalar( $post['op'] ?? null ) ? sanitize_key( (string) $post['op'] ) : '';
	$cible  = isset( $post['user_id'] ) && is_scalar( $post['user_id'] ) ? absint( $post['user_id'] ) : 0;
	$nonce  = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$ancre  = 'ajout' === $op ? 'yn-ajouter-membre-form' : ( 'retrait' === $op ? 'yn-membres' : 'yn-membre-' . $cible );
	$erreur = static function ( string $message ) use ( $ancre ): array {
		return array(
			'type'    => 'erreur',
			'message' => $message,
			'cible'   => $ancre,
		);
	};
	if ( ! in_array( $op, array( 'role', 'retrait', 'ajout' ), true ) ) {
		return $erreur( __( 'Action inconnue.', 'yume-core' ) );
	}
	if ( ! wp_verify_nonce( $nonce, 'ajout' === $op ? 'yume_membres_ajout' : 'yume_membres_' . $cible ) ) {
		return $erreur( __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	if ( ! user_can( $user_id, 'yume_gerer_equipe' ) ) {
		return $erreur( __( 'Seuls les gérants de l’équipe peuvent attribuer les rôles.', 'yume-core' ) );
	}

	$roles = roles_equipe_attribuables();
	$role  = is_scalar( $post['role'] ?? null ) ? sanitize_key( (string) $post['role'] ) : '';
	if ( 'ajout' === $op ) {
		$compte = compte_designe( is_scalar( $post['compte'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['compte'] ) ) : '' );
		if ( ! $compte ) {
			return $erreur( __( 'Aucun compte ne correspond à cet identifiant ou à cet e-mail.', 'yume-core' ) );
		}
		if ( user_can( $compte, 'yume_voir_equipe' ) ) {
			/* translators: %s : pseudo */
			return $erreur( sprintf( __( '%s fait déjà partie de l’équipe : changez son rôle dans la liste.', 'yume-core' ), $compte->display_name ) );
		}
	} else {
		$compte = $cible ? get_userdata( $cible ) : false;
		if ( ! $compte instanceof \WP_User ) {
			return $erreur( __( 'Ce compte n’existe plus.', 'yume-core' ) );
		}
	}
	if ( ! peut_gerer_membre( $user_id, (int) $compte->ID ) ) {
		return $erreur( __( 'Vous ne pouvez pas modifier le rôle de ce compte.', 'yume-core' ) );
	}

	$nouveau = 'retrait' === $op ? 'subscriber' : $role;
	if ( 'subscriber' !== $nouveau && ! isset( $roles[ $nouveau ] ) ) {
		return $erreur( __( 'Ce rôle ne peut pas être attribué depuis l’espace équipe.', 'yume-core' ) );
	}
	$compte->set_role( $nouveau );
	clean_user_cache( $compte );

	$nom = (string) $compte->display_name;
	if ( 'retrait' === $op ) {
		/* translators: %s : pseudo */
		$message = sprintf( __( '%s ne fait plus partie de l’équipe (rôle Lecteur).', 'yume-core' ), $nom );
	} elseif ( 'ajout' === $op ) {
		/* translators: 1: pseudo, 2: rôle */
		$message = sprintf( __( '%1$s rejoint l’équipe : %2$s.', 'yume-core' ), $nom, $roles[ $nouveau ] );
	} else {
		/* translators: 1: pseudo, 2: rôle */
		$message = sprintf( __( 'Rôle de %1$s : %2$s.', 'yume-core' ), $nom, $roles[ $nouveau ] );
	}
	return array(
		'type'    => 'ok',
		'message' => $message,
		'cible'   => 'ajout' === $op ? 'yn-ajouter-membre-form' : ( 'retrait' === $op ? 'yn-membres' : 'yn-membre-' . (int) $compte->ID ),
	);
}

/**
 * Formulaire de la page « Membres et rôles » (admin-post.php, action yume_equipe_membres).
 */
function admin_post_membres(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_membres().
	$retour = traiter_formulaire_membres( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	$page = url_page_membres();
	$page = '' !== $page ? $page : yume_url_page( 'equipe' );
	$page = remove_query_arg( array( 'yume_membres' ), $page );
	wp_safe_redirect( add_query_arg( 'yume_membres', 'ok' === $retour['type'] ? 'ok' : 'erreur', $page ) . '#' . $retour['cible'] );
	exit;
}
add_action( 'admin_post_yume_equipe_membres', __NAMESPACE__ . '\\admin_post_membres' );

/**
 * Visiteur non connecté : vers la connexion, retour sur la page « Membres et rôles ».
 */
function admin_post_membres_anonyme(): void {
	$page = url_page_membres();
	wp_safe_redirect( wp_login_url( '' !== $page ? $page : yume_url_page( 'equipe' ) ) );
	exit;
}
add_action( 'admin_post_nopriv_yume_equipe_membres', __NAMESPACE__ . '\\admin_post_membres_anonyme' );
