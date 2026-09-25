<?php
/**
 * Écritures du planning, partagées par la REST, les formulaires sans JavaScript de l'espace
 * équipe et les écouteurs d'événements : mise à jour du planning d'un tome (validation,
 * journal par champ, date de mise à jour, action yume_planning_mis_a_jour) et ajout d'un tome
 * au planning.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Drapeau « écriture faite par le module » : yume_journal_planning() n'a alors pas à émettre
 * yume_planning_mis_a_jour (le module l'émet lui-même une fois).
 *
 * @param bool|null $valeur Nouvelle valeur (null : lecture).
 */
function en_service( ?bool $valeur = null ): bool {
	static $etat = false;
	if ( null !== $valeur ) {
		$etat = $valeur;
	}
	return $etat;
}

/**
 * Tampon des changements journalisés hors du module (méta-boîte du cœur) : tome => données.
 *
 * @param array|null $nouveau Remplace le tampon (null : lecture).
 * @return array<int,array{user_id:int,changements:array}>
 */
function tampon( ?array $nouveau = null ): array {
	static $tampon = array();
	if ( null !== $nouveau ) {
		$tampon = $nouveau;
	}
	return $tampon;
}

/**
 * Ajoute un changement au tampon.
 *
 * @param int    $tome_id Tome.
 * @param int    $user_id Auteur.
 * @param string $champ   Champ.
 * @param mixed  $ancien  Ancienne valeur.
 * @param mixed  $nouveau Nouvelle valeur.
 */
function tampon_ajouter( int $tome_id, int $user_id, string $champ, $ancien, $nouveau ): void {
	$t = tampon();
	if ( ! isset( $t[ $tome_id ] ) ) {
		$t[ $tome_id ] = array(
			'user_id'     => $user_id,
			'changements' => array(),
		);
	}
	$t[ $tome_id ]['changements'][ $champ ] = array(
		'ancien'  => $ancien,
		'nouveau' => $nouveau,
	);
	tampon( $t );
}

/**
 * Émet yume_planning_mis_a_jour pour les changements en attente (un tome ou tous).
 *
 * @param int $tome_id Tome (0 : tous).
 */
function vider_tampon( int $tome_id = 0 ): void {
	$t = tampon();
	foreach ( $t as $id => $donnees ) {
		if ( $tome_id && $id !== $tome_id ) {
			continue;
		}
		unset( $t[ $id ] );
		tampon( $t );
		/** Cette action est documentée dans mettre_a_jour(). */
		do_action( 'yume_planning_mis_a_jour', (int) $id, $donnees['changements'], (int) $donnees['user_id'] );
	}
}

/**
 * Fin de l'enregistrement d'un tome (méta-boîte du cœur) : émission de l'action.
 *
 * @param int $post_id Contenu enregistré.
 */
function apres_enregistrement( $post_id ): void {
	if ( isset( tampon()[ (int) $post_id ] ) ) {
		vider_tampon( (int) $post_id );
	}
}
add_action( 'wp_after_insert_post', __NAMESPACE__ . '\\apres_enregistrement', 40 );

/**
 * Filet : changements journalisés hors d'un enregistrement de tome.
 */
function fin_de_requete(): void {
	if ( tampon() ) {
		vider_tampon();
	}
}
add_action( 'shutdown', __NAMESPACE__ . '\\fin_de_requete', 5 );

/**
 * Erreur d'écriture.
 *
 * @param string $code    Code.
 * @param string $message Message.
 * @param int    $statut  Statut HTTP.
 * @param array  $donnees Données complémentaires.
 */
function erreur( string $code, string $message, int $statut, array $donnees = array() ): \WP_Error {
	return new \WP_Error( $code, $message, array_merge( array( 'status' => $statut ), $donnees ) );
}

/**
 * Valide et assainit une saisie de planning. Seules les clés présentes sont renvoyées.
 *
 * @param array $saisie  etape, avancement (partiel), date_cible, bloque, bloque_raison,
 *                       responsables (partiel), note_equipe.
 * @param int   $user_id Auteur (droits sur responsables et note).
 * @param bool  $forcer  Écriture système (aucun contrôle de droits).
 * @return array<string,mixed>|\WP_Error
 */
function valider_saisie( array $saisie, int $user_id, bool $forcer = false ) {
	$propre = array();

	if ( array_key_exists( 'etape', $saisie ) ) {
		$etape = is_scalar( $saisie['etape'] ) ? sanitize_key( (string) $saisie['etape'] ) : '';
		if ( ! array_key_exists( $etape, yume_etapes() ) ) {
			return erreur( 'yume_etape_invalide', __( 'Étape inconnue.', 'yume-core' ), 400 );
		}
		$propre['etape'] = $etape;
	}

	if ( array_key_exists( 'avancement', $saisie ) ) {
		$valeurs = is_object( $saisie['avancement'] ) ? (array) $saisie['avancement'] : $saisie['avancement'];
		if ( ! is_array( $valeurs ) ) {
			return erreur( 'yume_avancement_invalide', __( 'Avancement invalide.', 'yume-core' ), 400 );
		}
		$propre['avancement'] = array();
		foreach ( ETAPES_TRAVAIL as $etape ) {
			if ( ! array_key_exists( $etape, $valeurs ) ) {
				continue;
			}
			if ( ! is_numeric( $valeurs[ $etape ] ) ) {
				return erreur( 'yume_avancement_invalide', __( 'L’avancement doit être un nombre entre 0 et 100.', 'yume-core' ), 400 );
			}
			$propre['avancement'][ $etape ] = max( 0, min( 100, (int) round( (float) $valeurs[ $etape ] ) ) );
		}
	}

	if ( array_key_exists( 'date_cible', $saisie ) ) {
		$date = is_scalar( $saisie['date_cible'] ) ? trim( (string) $saisie['date_cible'] ) : null;
		if ( null === $date || ( '' !== $date && ! valider_date( $date ) ) ) {
			return erreur( 'yume_date_invalide', __( 'Date cible invalide (format attendu : AAAA-MM-JJ).', 'yume-core' ), 400 );
		}
		$propre['date_cible'] = $date;
	}

	if ( array_key_exists( 'bloque', $saisie ) ) {
		$propre['bloque'] = (bool) rest_sanitize_boolean( is_scalar( $saisie['bloque'] ) ? $saisie['bloque'] : false );
	}

	if ( array_key_exists( 'bloque_raison', $saisie ) ) {
		$propre['bloque_raison'] = is_scalar( $saisie['bloque_raison'] ) ? mb_substr( sanitize_text_field( (string) $saisie['bloque_raison'] ), 0, 200 ) : '';
	}

	if ( array_key_exists( 'responsables', $saisie ) ) {
		if ( ! $forcer && ! user_can( $user_id, 'yume_maj_planning_tous' ) ) {
			return erreur( 'yume_responsables_interdit', __( 'Seuls les éditeurs et les gérants peuvent désigner les responsables.', 'yume-core' ), 403 );
		}
		$valeurs = is_object( $saisie['responsables'] ) ? (array) $saisie['responsables'] : $saisie['responsables'];
		if ( ! is_array( $valeurs ) ) {
			return erreur( 'yume_responsable_invalide', __( 'Responsables invalides.', 'yume-core' ), 400 );
		}
		$propre['responsables'] = array();
		foreach ( ETAPES_TRAVAIL as $etape ) {
			if ( ! array_key_exists( $etape, $valeurs ) ) {
				continue;
			}
			$uid = is_numeric( $valeurs[ $etape ] ) ? (int) $valeurs[ $etape ] : -1;
			if ( $uid < 0 || ( $uid > 0 && ! est_membre( $uid ) ) ) {
				return erreur( 'yume_responsable_invalide', __( 'Un responsable doit être un membre de l’équipe.', 'yume-core' ), 400 );
			}
			$propre['responsables'][ $etape ] = $uid;
		}
	}

	if ( array_key_exists( 'note_equipe', $saisie ) ) {
		if ( ! $forcer && ! user_can( $user_id, 'yume_maj_planning' ) ) {
			return erreur( 'yume_note_interdite', __( 'Vous ne pouvez pas modifier la note de l’équipe.', 'yume-core' ), 403 );
		}
		$propre['note_equipe'] = is_scalar( $saisie['note_equipe'] ) ? mb_substr( sanitize_textarea_field( (string) $saisie['note_equipe'] ), 0, 2000 ) : '';
	}

	return $propre;
}

/**
 * L'utilisateur peut-il marquer « publié » un tome (ou en retirer l'étape « publié ») à la
 * main ? L'étape suit normalement la publication (événement yume_tome_publie).
 *
 * @param int $user_id Utilisateur.
 */
function peut_forcer_publie( int $user_id ): bool {
	return user_can( $user_id, 'yume_maj_planning_tous' ) || user_can( $user_id, 'yume_publier' );
}

/**
 * Contrôle un changement d'étape saisi à la main (hors écriture système).
 *
 * - « publié » n'est accepté que pour un tome réellement publié (statut publish), et
 *   seulement de la part d'un éditeur, d'un gérant ou d'un publieur ;
 * - un tome publié ne quitte l'étape « publié » que par un éditeur ou un gérant
 *   (yume_maj_planning_tous) : sinon il reviendrait dans les prochaines sorties.
 *
 * @param int    $tome_id Tome.
 * @param string $avant   Étape actuelle.
 * @param string $apres   Étape demandée.
 * @param int    $user_id Auteur.
 * @return true|\WP_Error
 */
function controler_etape( int $tome_id, string $avant, string $apres, int $user_id ) {
	if ( $avant === $apres ) {
		return true;
	}
	$publie = 'publish' === get_post_status( $tome_id );
	if ( 'publie' === $apres ) {
		if ( ! $publie ) {
			return erreur( 'yume_etape_publie_interdite', __( 'Ce tome n’est pas encore publié : l’étape « publié » est fixée à sa publication.', 'yume-core' ), 400 );
		}
		if ( ! peut_forcer_publie( $user_id ) ) {
			return erreur( 'yume_etape_publie_interdite', __( 'Seuls les éditeurs et les gérants peuvent marquer un tome comme publié.', 'yume-core' ), 403 );
		}
	}
	if ( 'publie' === $avant && $publie && ! user_can( $user_id, 'yume_maj_planning_tous' ) ) {
		return erreur( 'yume_etape_publie_interdite', __( 'Ce tome est publié : seuls les éditeurs et les gérants peuvent changer son étape.', 'yume-core' ), 403 );
	}
	return true;
}

/**
 * Étapes proposées dans les formulaires de l'espace équipe pour un tome : celles que
 * controler_etape() accepterait, plus l'étape actuelle.
 *
 * @param int    $tome_id  Tome.
 * @param string $courante Étape actuelle.
 * @param int    $user_id  Utilisateur.
 * @return array<string,string>
 */
function etapes_proposees( int $tome_id, string $courante, int $user_id ): array {
	$options = array();
	foreach ( yume_etapes() as $cle => $libelle ) {
		if ( $cle === $courante || true === controler_etape( $tome_id, $courante, (string) $cle, $user_id ) ) {
			$options[ $cle ] = $libelle;
		}
	}
	return $options;
}

/**
 * Met à jour le planning d'un tome.
 *
 * Écrit les champs modifiés, puis yume_derniere_maj et yume_maj_par, journalise chaque champ
 * modifié (note d'équipe non publique) et émet yume_planning_mis_a_jour.
 *
 * @param int   $tome_id Tome.
 * @param array $saisie  Voir valider_saisie().
 * @param int   $user_id Auteur (0 = système).
 * @param array $options 'verifier_droits' (bool, défaut vrai), 'forcer' (bool : écriture
 *                       système sans contrôle), 'toujours_dater' (bool : met à jour la date même
 *                       sans changement), 'evenements' (lignes de journal supplémentaires :
 *                       champ => [ancien, nouveau]).
 * @return array{changements:array,etat:string}|\WP_Error
 */
function mettre_a_jour( int $tome_id, array $saisie, int $user_id, array $options = array() ) {
	$options = wp_parse_args(
		$options,
		array(
			'verifier_droits' => true,
			'forcer'          => false,
			'toujours_dater'  => false,
			'evenements'      => array(),
		)
	);
	$post    = get_post( $tome_id );
	if ( ! $post || 'yume_tome' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
		return erreur( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), 404 );
	}
	if ( $options['verifier_droits'] && ! $options['forcer'] && ! yume_user_can_edit_planning( $tome_id, $user_id ) ) {
		return erreur( 'yume_planning_interdit', __( 'Vous n’êtes pas responsable de ce tome.', 'yume-core' ), 403 );
	}
	$propre = valider_saisie( $saisie, $user_id, (bool) $options['forcer'] );
	if ( is_wp_error( $propre ) ) {
		return $propre;
	}

	$avant  = donnees_tome( $tome_id );
	if ( ! $options['forcer'] && array_key_exists( 'etape', $propre ) ) {
		$controle = controler_etape( $tome_id, $avant['etape'], $propre['etape'], $user_id );
		if ( is_wp_error( $controle ) ) {
			return $controle;
		}
	}
	$apres  = array();
	$champs = array( 'etape', 'avancement', 'responsables', 'date_cible', 'bloque', 'bloque_raison', 'note_equipe' );
	foreach ( $champs as $champ ) {
		if ( ! array_key_exists( $champ, $propre ) ) {
			continue;
		}
		$valeur = $propre[ $champ ];
		if ( 'avancement' === $champ || 'responsables' === $champ ) {
			$valeur = array_merge( $avant[ $champ ], $valeur );
		}
		$apres[ $champ ] = $valeur;
	}
	// Débloquer efface la raison (sauf si une nouvelle raison est donnée).
	if ( array_key_exists( 'bloque', $apres ) && ! $apres['bloque'] && ! array_key_exists( 'bloque_raison', $propre ) ) {
		$apres['bloque_raison'] = '';
	}

	$changements = array();
	foreach ( $apres as $champ => $valeur ) {
		if ( $avant[ $champ ] === $valeur ) {
			continue;
		}
		// update_post_meta() retire une couche de barres obliques inverses : on en ajoute une.
		update_post_meta( $tome_id, 'yume_' . $champ, wp_slash( $valeur ) );
		$changements[ $champ ] = array(
			'ancien'  => $avant[ $champ ],
			'nouveau' => $valeur,
		);
	}

	$evenements = (array) $options['evenements'];
	if ( $changements || $evenements || $options['toujours_dater'] ) {
		update_post_meta( $tome_id, 'yume_derniere_maj', gmt() );
		update_post_meta( $tome_id, 'yume_maj_par', max( 0, $user_id ) );
	}

	en_service( true );
	try {
		foreach ( $changements as $champ => $valeurs ) {
			yume_journal_planning( $tome_id, $user_id, $champ, $valeurs['ancien'], $valeurs['nouveau'] );
		}
		foreach ( $evenements as $champ => $valeurs ) {
			journaliser( $tome_id, $user_id, (string) $champ, $valeurs[0] ?? '', $valeurs[1] ?? '' );
			$changements[ $champ ] = array(
				'ancien'  => $valeurs[0] ?? null,
				'nouveau' => $valeurs[1] ?? null,
			);
		}
	} finally {
		en_service( false );
	}

	if ( $changements ) {
		/**
		 * Le planning d'un tome vient d'être mis à jour.
		 *
		 * @param int   $tome_id     Tome.
		 * @param array $changements champ => ['ancien' => …, 'nouveau' => …].
		 * @param int   $user_id     Auteur (0 = système).
		 */
		do_action( 'yume_planning_mis_a_jour', $tome_id, $changements, $user_id );
	}

	return array(
		'changements' => $changements,
		'etat'        => yume_planning_etat( $tome_id ),
	);
}

/**
 * Message de confirmation d'une mise à jour (annoncé à l'écran).
 *
 * @param int   $tome_id     Tome.
 * @param array $changements Changements.
 */
function message_mise_a_jour( int $tome_id, array $changements ): string {
	if ( ! $changements ) {
		return __( 'Aucun changement à enregistrer.', 'yume-core' );
	}
	$parties = array();
	foreach ( $changements as $champ => $valeurs ) {
		$ligne     = (object) array(
			'champ'   => $champ,
			'ancien'  => valeur_journal( $valeurs['ancien'] ),
			'nouveau' => valeur_journal( $valeurs['nouveau'] ),
		);
		$parties[] = texte_changement( $ligne, true );
	}
	$parties = array_filter( $parties );
	return sprintf(
		/* translators: 1: tome, 2: changements */
		__( 'Planning enregistré pour %1$s%2$s.', 'yume-core' ),
		cible_journal( $tome_id ),
		$parties ? ' : ' . implode( ', ', $parties ) : ''
	);
}

/**
 * Ajoute un tome au planning (brouillon) : œuvre, nature, numéro, titre facultatif,
 * responsables, date cible, étape de départ.
 *
 * @param array $saisie  oeuvre_id, nature, numero, titre, responsables, date_cible, etape.
 * @param int   $user_id Auteur (capacité yume_maj_planning_tous).
 * @return int|\WP_Error ID du tome créé.
 */
function ajouter_tome( array $saisie, int $user_id ) {
	if ( ! user_can( $user_id, 'yume_maj_planning_tous' ) ) {
		return erreur( 'yume_planning_interdit', __( 'Seuls les éditeurs et les gérants peuvent ajouter un tome au planning.', 'yume-core' ), 403 );
	}
	$oeuvre_id = isset( $saisie['oeuvre_id'] ) && is_numeric( $saisie['oeuvre_id'] ) ? (int) $saisie['oeuvre_id'] : 0;
	$oeuvre    = $oeuvre_id ? get_post( $oeuvre_id ) : null;
	if ( ! $oeuvre || 'yume_oeuvre' !== $oeuvre->post_type || in_array( $oeuvre->post_status, array( 'trash', 'auto-draft' ), true ) ) {
		return erreur( 'yume_oeuvre_invalide', __( 'Choisissez une œuvre existante.', 'yume-core' ), 400 );
	}
	$nature = isset( $saisie['nature'] ) && is_scalar( $saisie['nature'] ) && '' !== (string) $saisie['nature'] ? sanitize_key( (string) $saisie['nature'] ) : 'tome';
	if ( ! array_key_exists( $nature, yume_natures_tome() ) ) {
		return erreur( 'yume_nature_invalide', __( 'Nature de tome inconnue.', 'yume-core' ), 400 );
	}
	$numero = null;
	if ( isset( $saisie['numero'] ) && is_scalar( $saisie['numero'] ) && '' !== trim( (string) $saisie['numero'] ) ) {
		$brut = str_replace( ',', '.', trim( (string) $saisie['numero'] ) );
		if ( ! is_numeric( $brut ) || (float) $brut < 0 || (float) $brut > 9999 ) {
			return erreur( 'yume_numero_invalide', __( 'Numéro invalide.', 'yume-core' ), 400 );
		}
		$numero = round( (float) $brut, 3 );
	}
	if ( null === $numero && in_array( $nature, array( 'tome', 'arc' ), true ) ) {
		return erreur( 'yume_numero_invalide', __( 'Indiquez le numéro du tome ou de l’arc.', 'yume-core' ), 400 );
	}
	$titre = isset( $saisie['titre'] ) && is_scalar( $saisie['titre'] ) ? mb_substr( sanitize_text_field( (string) $saisie['titre'] ), 0, 150 ) : '';

	$planning = array_intersect_key( $saisie, array_flip( array( 'responsables', 'date_cible', 'etape' ) ) );
	if ( isset( $planning['etape'] ) && 'publie' === $planning['etape'] ) {
		return erreur( 'yume_etape_invalide', __( 'Un tome ajouté au planning n’est pas encore publié.', 'yume-core' ), 400 );
	}
	$propre = valider_saisie( $planning, $user_id );
	if ( is_wp_error( $propre ) ) {
		return $propre;
	}

	// Doublon : même œuvre, même nature, même numéro (ou même titre sans numéro).
	foreach ( yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) ) as $existant ) {
		$n_nature = (string) get_post_meta( $existant->ID, 'yume_nature', true );
		$n_numero = get_post_meta( $existant->ID, 'yume_numero', true );
		$n_numero = is_numeric( $n_numero ) ? round( (float) $n_numero, 3 ) : null;
		if ( ( '' === $n_nature ? 'tome' : $n_nature ) === $nature && $n_numero === $numero
			&& ( null !== $numero || 0 === strcasecmp( sous_titre_tome( (int) $existant->ID ), $titre ) ) ) {
			return erreur(
				'yume_tome_existe',
				sprintf(
					/* translators: %s : tome */
					__( '%s existe déjà.', 'yume-core' ),
					cible_journal( (int) $existant->ID )
				),
				409,
				array( 'tome_id' => (int) $existant->ID )
			);
		}
	}

	$natures = yume_natures_tome();
	$libelle = $natures[ $nature ] . ( null !== $numero ? ' ' . str_replace( '.', ',', rtrim( rtrim( number_format( $numero, 3, '.', '' ), '0' ), '.' ) ) : '' );
	$titre_p = titre_brut( $oeuvre_id ) . ' — ' . $libelle . ( '' !== $titre ? ' : ' . $titre : '' );

	$meta = array(
		'yume_oeuvre_id'    => $oeuvre_id,
		'yume_nature'       => $nature,
		'yume_etape'        => $propre['etape'] ?? 'a_faire',
		'yume_avancement'   => norm_avancement( array() ),
		'yume_responsables' => array_merge( norm_responsables( array() ), $propre['responsables'] ?? array() ),
		'yume_derniere_maj' => gmt(),
		'yume_maj_par'      => $user_id,
	);
	if ( null !== $numero ) {
		$meta['yume_numero'] = $numero;
	}
	if ( ! empty( $propre['date_cible'] ) ) {
		$meta['yume_date_cible'] = $propre['date_cible'];
	}

	// wp_insert_post() attend des données « slashées » (titre, métadonnées).
	$tome_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'yume_tome',
				'post_status'  => 'draft',
				'post_title'   => $titre_p,
				'post_content' => '',
				'post_author'  => $user_id,
				'meta_input'   => $meta,
			)
		),
		true
	);
	if ( is_wp_error( $tome_id ) ) {
		return erreur( 'yume_creation_impossible', $tome_id->get_error_message(), 500 );
	}
	$tome_id = (int) $tome_id;

	en_service( true );
	try {
		journaliser( $tome_id, $user_id, 'creation', '', $libelle );
	} finally {
		en_service( false );
	}
	/** Cette action est documentée dans mettre_a_jour(). */
	do_action(
		'yume_planning_mis_a_jour',
		$tome_id,
		array(
			'creation' => array(
				'ancien'  => null,
				'nouveau' => $meta,
			),
		),
		$user_id
	);
	return $tome_id;
}
