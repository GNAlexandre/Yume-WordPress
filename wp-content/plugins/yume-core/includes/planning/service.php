<?php
/**
 * Écritures du planning, partagées par la REST, les formulaires sans JavaScript de l'espace
 * équipe et les écouteurs d'événements : mise à jour du planning d'un tome (validation,
 * journal par champ, date de mise à jour, action yume_planning_mis_a_jour), ajout d'un tome
 * au planning, retrait, et mise en pause ou reprise d'un tome (méta yume_pause : un tome en
 * pause n'est jamais « en retard » et ne reçoit aucun rappel ; réservé à l'équipe).
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
 * L'utilisateur peut-il forcer le passage à une étape alors que les étapes précédentes ne
 * sont pas à 100 % (décision SCAN-02) ? Réservé aux administrateurs et aux gérants (capacité
 * yume_gerer_equipe) ; le passage est alors journalisé comme « étape forcée ». Forcer ne
 * permet jamais de marquer « publié » un tome non publié.
 *
 * @param int $user_id Utilisateur.
 * @return bool
 */
function peut_forcer_etape( int $user_id ): bool {
	$peut = $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'yume_gerer_equipe' ) );
	/**
	 * Filtre le droit de forcer une étape sans que les précédentes soient terminées.
	 *
	 * @param bool $peut    Droit.
	 * @param int  $user_id Utilisateur.
	 */
	return (bool) apply_filters( 'yume_planning_peut_forcer_etape', $peut, $user_id );
}

/**
 * Passage prématuré : avancer à la relecture (ou à l'édition) alors qu'une étape précédente
 * n'est pas à 100 %. Revenir à une étape antérieure n'est jamais prématuré.
 *
 * @param string $avant      Étape actuelle.
 * @param string $apres      Étape demandée.
 * @param array  $avancement Avancement {traduction, relecture, edition} après la saisie.
 * @return \WP_Error|null Erreur yume_etape_prematuree (400), ou null.
 */
function etape_prematuree( string $avant, string $apres, array $avancement ): ?\WP_Error {
	if ( $avant === $apres || ! in_array( $apres, array( 'relecture', 'edition' ), true ) || rang_etape( $apres ) <= rang_etape( $avant ) ) {
		return null;
	}
	foreach ( ETAPES_TRAVAIL as $precedente ) {
		if ( $precedente === $apres ) {
			break;
		}
		if ( (int) ( $avancement[ $precedente ] ?? 0 ) < 100 ) {
			$libelles = yume_etapes();
			return erreur(
				'yume_etape_prematuree',
				sprintf(
					/* translators: 1: étape à terminer (Traduction…), 2: étape demandée (Relecture…). */
					__( 'Terminez d’abord l’étape « %1$s » (100 %%) avant de passer à l’étape « %2$s ».', 'yume-core' ),
					$libelles[ $precedente ],
					$libelles[ $apres ]
				),
				400
			);
		}
	}
	return null;
}

/**
 * Le changement d'étape est-il un passage forcé (prématuré, accepté parce que l'utilisateur
 * peut forcer) ? Sert à journaliser « étape forcée » (espace équipe, REST, méta-boîte).
 *
 * @param int        $tome_id    Tome.
 * @param string     $avant      Étape actuelle.
 * @param string     $apres      Étape demandée.
 * @param int        $user_id    Auteur.
 * @param array|null $avancement Avancement après la saisie (null : celui du tome).
 * @return bool
 */
function est_etape_forcee( int $tome_id, string $avant, string $apres, int $user_id, ?array $avancement = null ): bool {
	$avancement = $avancement ?? donnees_tome( $tome_id )['avancement'];
	return null !== etape_prematuree( $avant, $apres, $avancement ) && peut_forcer_etape( $user_id );
}

/**
 * Contrôle un changement d'étape saisi à la main (hors écriture système).
 *
 * - « publié » n'est accepté que pour un tome réellement publié (statut publish), et
 *   seulement de la part d'un éditeur, d'un gérant ou d'un publieur ;
 * - un tome publié ne quitte l'étape « publié » que par un éditeur ou un gérant
 *   (yume_maj_planning_tous) : sinon il reviendrait dans les prochaines sorties ;
 * - avancer à la relecture (ou à l'édition) exige que les étapes précédentes soient à 100 %,
 *   sauf pour un administrateur ou un gérant (peut_forcer_etape() : « étape forcée »).
 *   Revenir à une étape antérieure reste possible.
 *
 * @param int        $tome_id    Tome.
 * @param string     $avant      Étape actuelle.
 * @param string     $apres      Étape demandée.
 * @param int        $user_id    Auteur.
 * @param array|null $avancement Avancement {traduction, relecture, edition} après la saisie
 *                               (null : celui du tome).
 * @return true|\WP_Error
 */
function controler_etape( int $tome_id, string $avant, string $apres, int $user_id, ?array $avancement = null ) {
	if ( $avant === $apres ) {
		return true;
	}
	$prematuree = etape_prematuree( $avant, $apres, $avancement ?? donnees_tome( $tome_id )['avancement'] );
	if ( $prematuree && ! peut_forcer_etape( $user_id ) ) {
		return $prematuree;
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
 * Contrôle l'avancement saisi (décision SCAN-09) : sans yume_maj_planning_tous, un membre ne
 * modifie que l'avancement des étapes dont il est responsable. Une valeur inchangée est
 * acceptée (les formulaires renvoient les trois curseurs).
 *
 * @param array $avant      Avancement actuel.
 * @param array $saisi      Avancement saisi (partiel).
 * @param array $resp       Responsables actuels.
 * @param int   $user_id    Auteur.
 * @return true|\WP_Error
 */
function controler_avancement( array $avant, array $saisi, array $resp, int $user_id ) {
	if ( user_can( $user_id, 'yume_maj_planning_tous' ) ) {
		return true;
	}
	foreach ( $saisi as $etape => $valeur ) {
		if ( (int) ( $avant[ $etape ] ?? 0 ) === (int) $valeur || (int) ( $resp[ $etape ] ?? 0 ) === $user_id ) {
			continue;
		}
		return erreur(
			'yume_avancement_interdit',
			sprintf(
				/* translators: %s : étape (relecture…) */
				__( 'Vous n’êtes pas responsable de l’étape « %s » : seul son responsable, un éditeur ou un gérant peut modifier son avancement.', 'yume-core' ),
				libelle_etape_min( (string) $etape )
			),
			403,
			array( 'etape' => (string) $etape )
		);
	}
	return true;
}

/**
 * Étapes proposées dans les formulaires de l'espace équipe pour un tome : celles que
 * controler_etape() accepterait si les étapes précédentes étaient terminées, plus l'étape
 * actuelle. L'avancement est contrôlé à l'envoi, car le même formulaire peut le passer à 100 %.
 *
 * @param int    $tome_id  Tome.
 * @param string $courante Étape actuelle.
 * @param int    $user_id  Utilisateur.
 * @return array<string,string>
 */
function etapes_proposees( int $tome_id, string $courante, int $user_id ): array {
	$options = array();
	foreach ( yume_etapes() as $cle => $libelle ) {
		$complet = array_fill_keys( ETAPES_TRAVAIL, 100 );
		if ( $cle === $courante || true === controler_etape( $tome_id, $courante, (string) $cle, $user_id, $complet ) ) {
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
 *                       champ => [ancien, nouveau, visible publique facultative]).
 *                       Sans 'forcer' : avancement limité aux étapes dont l'auteur est
 *                       responsable (sauf yume_maj_planning_tous, 403 yume_avancement_interdit) ;
 *                       une étape forcée par un gérant est journalisée (« etape_forcee »).
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

	$avant      = donnees_tome( $tome_id );
	$evenements = (array) $options['evenements'];
	if ( ! $options['forcer'] && array_key_exists( 'avancement', $propre ) ) {
		$controle = controler_avancement( $avant['avancement'], $propre['avancement'], $avant['responsables'], $user_id );
		if ( is_wp_error( $controle ) ) {
			return $controle;
		}
	}
	if ( ! $options['forcer'] && array_key_exists( 'etape', $propre ) ) {
		$avancement = array_merge( $avant['avancement'], $propre['avancement'] ?? array() );
		$controle   = controler_etape( $tome_id, $avant['etape'], $propre['etape'], $user_id, $avancement );
		if ( is_wp_error( $controle ) ) {
			return $controle;
		}
		if ( est_etape_forcee( $tome_id, $avant['etape'], $propre['etape'], $user_id, $avancement ) ) {
			// Journal de l'équipe seulement : le changement d'étape lui-même reste public.
			$evenements['etape_forcee'] = array(
				$avant['etape'],
				array(
					'etape'      => $propre['etape'],
					'avancement' => $avancement,
				),
				false,
			);
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
			journaliser( $tome_id, $user_id, (string) $champ, $valeurs[0] ?? '', $valeurs[1] ?? '', isset( $valeurs[2] ) ? (bool) $valeurs[2] : null );
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
 * Identité d'un tome saisie (création ou modification) : œuvre existante, nature connue,
 * numéro (obligatoire pour un tome ou un arc, virgule acceptée), titre facultatif.
 *
 * @param array $saisie oeuvre_id, nature, numero, titre.
 * @return array{oeuvre_id:int,nature:string,numero:?float,titre:string}|\WP_Error
 */
function valider_identite_tome( array $saisie ) {
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
	return array(
		'oeuvre_id' => $oeuvre_id,
		'nature'    => $nature,
		'numero'    => $numero,
		'titre'     => isset( $saisie['titre'] ) && is_scalar( $saisie['titre'] ) ? mb_substr( sanitize_text_field( (string) $saisie['titre'] ), 0, 150 ) : '',
	);
}

/**
 * Nombre de chapitres prévus saisi (vide ou 0 : inconnu), de 0 à 999.
 *
 * @param mixed $valeur Valeur saisie.
 * @return int|\WP_Error
 */
function valider_chapitres_prevus( $valeur ) {
	if ( null === $valeur || ( is_scalar( $valeur ) && '' === trim( (string) $valeur ) ) ) {
		return 0;
	}
	if ( ! is_scalar( $valeur ) || ! preg_match( '/^\d{1,3}$/', trim( (string) $valeur ) ) ) {
		return erreur( 'yume_chapitres_prevus_invalide', __( 'Chapitres prévus : indiquez un nombre entier de 0 à 999.', 'yume-core' ), 400 );
	}
	return (int) trim( (string) $valeur );
}

/**
 * Rythme de sortie saisi : array{jour, heure} (jour de la semaine, heure HH:MM, 18:00 par
 * défaut), ou chaîne vide pour une sortie libre (jour vide).
 *
 * @param mixed $valeur array{jour?:string,heure?:string}, objet ou vide.
 * @return array{jour:string,heure:string}|string|\WP_Error
 */
function valider_rythme( $valeur ) {
	$valeur = is_object( $valeur ) ? (array) $valeur : $valeur;
	if ( ! is_array( $valeur ) || '' === (string) ( is_scalar( $valeur['jour'] ?? null ) ? $valeur['jour'] : '' ) ) {
		return '';
	}
	if ( ! array_key_exists( (string) $valeur['jour'], yume_jours_semaine() ) ) {
		return erreur( 'yume_rythme_invalide', __( 'Rythme : jour de la semaine inconnu.', 'yume-core' ), 400 );
	}
	$heure = is_scalar( $valeur['heure'] ?? null ) ? trim( (string) $valeur['heure'] ) : '';
	if ( '' !== $heure && ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $heure ) ) {
		return erreur( 'yume_rythme_invalide', __( 'Rythme : heure invalide (format attendu : HH:MM).', 'yume-core' ), 400 );
	}
	return array(
		'jour'  => (string) $valeur['jour'],
		'heure' => '' !== $heure ? $heure : '18:00',
	);
}

/**
 * Ajoute un tome au planning (brouillon) : œuvre, nature, numéro, titre facultatif,
 * responsables, date cible, étape de départ, chapitres prévus et rythme de sortie
 * (facultatifs). Parution « à paraître » : aucun chapitre n'est encore lisible.
 *
 * @param array $saisie  oeuvre_id, nature, numero, titre, responsables, date_cible, etape,
 *                       chapitres_prevus (entier, 0 : inconnu), rythme (array{jour, heure} ;
 *                       jour vide : libre).
 * @param int   $user_id Auteur (capacité yume_maj_planning_tous).
 * @return int|\WP_Error ID du tome créé ; erreur yume_tome_existe (409, données tome_id) si
 *                       l'œuvre a déjà un tome de même nature et de même numéro.
 */
function ajouter_tome( array $saisie, int $user_id ) {
	if ( ! user_can( $user_id, 'yume_maj_planning_tous' ) ) {
		return erreur( 'yume_planning_interdit', __( 'Seuls les éditeurs et les gérants peuvent ajouter un tome au planning.', 'yume-core' ), 403 );
	}
	$identite = valider_identite_tome( $saisie );
	if ( is_wp_error( $identite ) ) {
		return $identite;
	}
	$oeuvre_id = $identite['oeuvre_id'];
	$nature    = $identite['nature'];
	$numero    = $identite['numero'];
	$titre     = $identite['titre'];

	$planning = array_intersect_key( $saisie, array_flip( array( 'responsables', 'date_cible', 'etape' ) ) );
	if ( isset( $planning['etape'] ) && 'publie' === $planning['etape'] ) {
		return erreur( 'yume_etape_invalide', __( 'Un tome ajouté au planning n’est pas encore publié.', 'yume-core' ), 400 );
	}
	$propre = valider_saisie( $planning, $user_id );
	if ( is_wp_error( $propre ) ) {
		return $propre;
	}
	$prevus = valider_chapitres_prevus( $saisie['chapitres_prevus'] ?? null );
	if ( is_wp_error( $prevus ) ) {
		return $prevus;
	}
	$rythme = valider_rythme( $saisie['rythme'] ?? '' );
	if ( is_wp_error( $rythme ) ) {
		return $rythme;
	}

	// Doublon : même œuvre, même nature, même numéro (ou même titre sans numéro).
	$existant = tome_en_double( $oeuvre_id, $nature, $numero, $titre );
	if ( $existant ) {
		return erreur(
			'yume_tome_existe',
			sprintf(
				/* translators: %s : tome */
				__( '%s existe déjà.', 'yume-core' ),
				cible_journal( $existant )
			),
			409,
			array( 'tome_id' => $existant )
		);
	}

	$libelle = libelle_nouveau_tome( $nature, $numero );
	$titre_p = titre_tome_planning( $oeuvre_id, $nature, $numero, $titre );

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
	if ( $prevus > 0 ) {
		$meta['yume_chapitres_prevus'] = $prevus;
	}
	if ( is_array( $rythme ) ) {
		$meta['yume_rythme'] = $rythme;
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

/**
 * Tome existant de l'œuvre de même nature et de même numéro (sans numéro : même titre), ou 0.
 *
 * @param int        $oeuvre_id Œuvre.
 * @param string     $nature    Nature (yume_natures_tome()).
 * @param float|null $numero    Numéro (arrondi au millième) ou null.
 * @param string     $titre     Titre facultatif (comparé quand il n'y a pas de numéro).
 * @param int        $sauf      Tome à ignorer (celui qu'on modifie).
 */
function tome_en_double( int $oeuvre_id, string $nature, ?float $numero, string $titre, int $sauf = 0 ): int {
	foreach ( yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) ) as $existant ) {
		if ( (int) $existant->ID === $sauf ) {
			continue;
		}
		$n_nature = (string) get_post_meta( $existant->ID, 'yume_nature', true );
		$n_numero = get_post_meta( $existant->ID, 'yume_numero', true );
		$n_numero = is_numeric( $n_numero ) ? round( (float) $n_numero, 3 ) : null;
		if ( ( '' === $n_nature ? 'tome' : $n_nature ) === $nature && $n_numero === $numero
			&& ( null !== $numero || 0 === strcasecmp( sous_titre_tome( (int) $existant->ID ), $titre ) ) ) {
			return (int) $existant->ID;
		}
	}
	return 0;
}

/**
 * Libellé d'un tome d'après sa nature et son numéro (« Tome 2 », « Arc 7,5 »).
 *
 * @param string     $nature Nature.
 * @param float|null $numero Numéro.
 */
function libelle_nouveau_tome( string $nature, ?float $numero ): string {
	$natures = yume_natures_tome();
	return ( $natures[ $nature ] ?? $natures['tome'] ) . ( null !== $numero ? ' ' . str_replace( '.', ',', rtrim( rtrim( number_format( $numero, 3, '.', '' ), '0' ), '.' ) ) : '' );
}

/**
 * Titre d'un tome créé ou modifié dans l'espace équipe : « Œuvre — Tome 2 : Titre ».
 *
 * @param int        $oeuvre_id Œuvre.
 * @param string     $nature    Nature.
 * @param float|null $numero    Numéro.
 * @param string     $titre     Titre facultatif.
 */
function titre_tome_planning( int $oeuvre_id, string $nature, ?float $numero, string $titre ): string {
	return titre_brut( $oeuvre_id ) . ' — ' . libelle_nouveau_tome( $nature, $numero ) . ( '' !== $titre ? ' : ' . $titre : '' );
}

/**
 * Retire un tome du planning (décision SCAN-06) : le tome part à la corbeille, avec une ligne
 * « retiré du planning » au journal (champ « retire », public).
 *
 * Réservé aux utilisateurs qui ont yume_maj_planning_tous et le droit de supprimer ce tome
 * (delete_post). Refusé (409) pour un tome publié, programmé ou privé, ou qui a au moins un
 * chapitre publié : il faut alors passer par l'administration.
 *
 * @param int $tome_id Tome.
 * @param int $user_id Auteur.
 * @return true|\WP_Error
 */
function retirer_tome( int $tome_id, int $user_id ) {
	$post = get_post( $tome_id );
	if ( ! $post || 'yume_tome' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return erreur( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), 404 );
	}
	if ( ! user_can( $user_id, 'yume_maj_planning_tous' ) || ! user_can( $user_id, 'delete_post', $tome_id ) ) {
		return erreur( 'yume_retrait_interdit', __( 'Seuls les éditeurs et les gérants peuvent retirer un tome du planning.', 'yume-core' ), 403 );
	}
	if ( in_array( $post->post_status, array( 'publish', 'future', 'private' ), true ) ) {
		return erreur(
			'yume_retrait_impossible',
			'future' === $post->post_status
				? __( 'Ce tome est programmé : annulez d’abord sa programmation depuis l’administration.', 'yume-core' )
				: __( 'Ce tome est publié : il ne peut pas être retiré du planning. Dépubliez-le ou supprimez-le depuis l’administration.', 'yume-core' ),
			409
		);
	}
	$chap = compte_chapitres( $tome_id );
	if ( $chap['publies'] > 0 ) {
		return erreur(
			'yume_retrait_impossible',
			sprintf(
				/* translators: %d : nombre de chapitres publiés */
				_n(
					'Ce tome a %d chapitre publié : il ne peut pas être retiré du planning. Passez par l’administration.',
					'Ce tome a %d chapitres publiés : il ne peut pas être retiré du planning. Passez par l’administration.',
					$chap['publies'],
					'yume-core'
				),
				$chap['publies']
			),
			409
		);
	}
	$libelle = cible_journal( $tome_id );
	en_service( true );
	try {
		journaliser( $tome_id, $user_id, 'retire', '', $libelle );
	} finally {
		en_service( false );
	}
	if ( ! wp_trash_post( $tome_id ) ) {
		return erreur( 'yume_retrait_echec', __( 'Le tome n’a pas pu être mis à la corbeille.', 'yume-core' ), 500 );
	}
	/** Cette action est documentée dans mettre_a_jour(). */
	do_action(
		'yume_planning_mis_a_jour',
		$tome_id,
		array(
			'retire' => array(
				'ancien'  => $post->post_status,
				'nouveau' => 'trash',
			),
		),
		$user_id
	);
	return true;
}

/*
 * -----------------------------------------------------------------------------
 * Pause d'un tome (BUG-10, AMEL-09)
 * -----------------------------------------------------------------------------
 */

/**
 * Pause d'un tome : depuis (GMT) et auteur, ou null si le tome n'est pas en pause.
 *
 * @param int $tome_id Tome.
 * @return array{depuis:string,par:int}|null
 */
function infos_pause( int $tome_id ): ?array {
	$pause = get_post_meta( $tome_id, 'yume_pause', true );
	if ( ! is_array( $pause ) || '' === (string) ( $pause['depuis'] ?? '' ) ) {
		return null;
	}
	return array(
		'depuis' => (string) $pause['depuis'],
		'par'    => (int) ( $pause['par'] ?? 0 ),
	);
}

/**
 * Le tome est-il en pause ?
 *
 * @param int $tome_id Tome.
 */
function est_en_pause( int $tome_id ): bool {
	return null !== infos_pause( $tome_id );
}

/**
 * Un tome en pause n'est jamais « en retard » (yume_planning_etat) : il sort des retards de
 * l'espace équipe, du récapitulatif et des rappels. Bloqué et publié restent inchangés.
 *
 * @param string $etat    État calculé.
 * @param int    $tome_id Tome.
 */
function etat_hors_pause( $etat, $tome_id = 0 ) {
	return 'en_retard' === $etat && est_en_pause( (int) $tome_id ) ? 'a_lheure' : $etat;
}
add_filter( 'yume_planning_etat', __NAMESPACE__ . '\\etat_hors_pause', 5, 2 );

/**
 * Met un tome en pause ou le reprend (« Mettre en pause » / « Reprendre » du planning
 * complet) : méta yume_pause, ligne « pause » au journal de l'équipe (jamais publique). La
 * reprise compte comme une mise à jour (date de dernière mise à jour) : le tome ne retombe pas
 * aussitôt en retard « sans nouvelles ».
 *
 * @param int  $tome_id Tome.
 * @param bool $pause   Vrai : mettre en pause ; faux : reprendre.
 * @param int  $user_id Auteur (capacité yume_maj_planning_tous).
 * @return array{pause:bool,changement:bool}|\WP_Error
 */
function basculer_pause( int $tome_id, bool $pause, int $user_id ) {
	$post = get_post( $tome_id );
	if ( ! $post || 'yume_tome' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return erreur( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), 404 );
	}
	if ( ! user_can( $user_id, 'yume_maj_planning_tous' ) ) {
		return erreur( 'yume_pause_interdite', __( 'Seuls les éditeurs et les gérants peuvent mettre un tome en pause.', 'yume-core' ), 403 );
	}
	$avant = est_en_pause( $tome_id );
	if ( $pause && ! $avant && 'publie' === donnees_tome( $tome_id )['etape'] ) {
		return erreur( 'yume_pause_impossible', __( 'Ce tome est déjà publié : il n’y a rien à mettre en pause.', 'yume-core' ), 409 );
	}
	if ( $avant === $pause ) {
		return array(
			'pause'      => $pause,
			'changement' => false,
		);
	}
	if ( $pause ) {
		update_post_meta(
			$tome_id,
			'yume_pause',
			array(
				'depuis' => gmt(),
				'par'    => max( 0, $user_id ),
			)
		);
	} else {
		delete_post_meta( $tome_id, 'yume_pause' );
		update_post_meta( $tome_id, 'yume_derniere_maj', gmt() );
		update_post_meta( $tome_id, 'yume_maj_par', max( 0, $user_id ) );
	}
	en_service( true );
	try {
		journaliser( $tome_id, $user_id, 'pause', $avant, $pause, false );
	} finally {
		en_service( false );
	}
	/**
	 * Un tome vient d'être mis en pause ou repris.
	 *
	 * @param int  $tome_id Tome.
	 * @param bool $pause   Vrai : mis en pause ; faux : repris.
	 * @param int  $user_id Auteur.
	 */
	do_action( 'yume_planning_pause', $tome_id, $pause, $user_id );
	return array(
		'pause'      => $pause,
		'changement' => true,
	);
}
