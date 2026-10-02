<?php
/**
 * Rappels automatiques (docs/02 F2) :
 *
 * - chaque jour à yume_setting( 'rappel_heure' ) (heure de Paris) : tome en retard (date cible
 *   dépassée ou aucune mise à jour depuis rappel_jours_sans_maj jours) → e-mail aux
 *   responsables et message sur le Discord de l'équipe ; tome bloqué sans responsable →
 *   signalement aux gérants. Rappels plafonnés (BUG-10) : un premier rappel, une relance
 *   après le délai minimal (3 jours, méta _yume_dernier_rappel), puis au plus un par semaine ;
 *   au-delà de yume_rappels_plafond semaines de retard (8 par défaut), plus aucun rappel : le
 *   tome ne figure plus que dans le récapitulatif. Un tome en pause (méta yume_pause) ou d'une
 *   œuvre en pause, terminée, abandonnée ou licenciée (tome_sans_rappels()) n'est jamais
 *   rappelé ni signalé ;
 * - chaque semaine, le jour digest_jour : récapitulatif aux gérants (sorties de la semaine,
 *   retards, bloqués, sorties prévues), sans les tomes de ces œuvres.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Délai minimal entre deux rappels d'un même tome (secondes).
 */
function delai_rappel(): int {
	/**
	 * Nombre de jours minimal entre deux rappels d'un même tome.
	 *
	 * @param int $jours Défaut 3.
	 */
	$jours = max( 1, (int) apply_filters( 'yume_planning_delai_rappel', 3 ) );
	// Une heure de marge : le rappel de 9 h passe encore trois jours plus tard à 9 h.
	return $jours * DAY_IN_SECONDS - HOUR_IN_SECONDS;
}

/**
 * Plafond des rappels d'un tome en retard, en semaines de retard (0 : aucun plafond).
 */
function plafond_rappels(): int {
	/**
	 * Nombre de semaines de retard au-delà duquel un tome ne reçoit plus de rappel (il reste
	 * dans le récapitulatif hebdomadaire des gérants).
	 *
	 * @param int $semaines Défaut 8 ; 0 désactive le plafond.
	 */
	return max( 0, (int) apply_filters( 'yume_rappels_plafond', 8 ) );
}

/**
 * Le retard d'un tome dépasse-t-il le plafond des rappels ?
 *
 * @param array $ligne Ligne du planning (jours_retard).
 */
function rappels_plafonnes( array $ligne ): bool {
	$plafond = plafond_rappels();
	return $plafond > 0 && (int) ( $ligne['jours_retard'] ?? 0 ) >= $plafond * 7;
}

/**
 * Nombre de rappels de retard envoyés pour un tome depuis un instant (sa dernière activité).
 *
 * @param int $tome_id Tome.
 * @param int $depuis  Horodatage.
 */
function rappels_envoyes_depuis( int $tome_id, int $depuis ): int {
	global $wpdb;
	$table = table_journal();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE tome_id = %d AND champ = 'rappel' AND created_at >= %s", $tome_id, gmt( $depuis ) ) );
}

/**
 * Un rappel de retard peut-il partir aujourd'hui pour ce tome ? Jamais pour un tome en pause,
 * d'une œuvre en pause, terminée, abandonnée ou licenciée, ni au-delà du plafond ; sinon le
 * premier rappel part, la relance attend le délai minimal (delai_rappel()) et les suivants une
 * semaine.
 *
 * @param array $ligne Ligne du planning.
 */
function rappel_permis( array $ligne ): bool {
	$tome_id = (int) $ligne['tome_id'];
	if ( tome_sans_rappels( $tome_id ) || rappels_plafonnes( $ligne ) ) {
		return false;
	}
	$dernier = ts_gmt( (string) get_post_meta( $tome_id, META_DERNIER_RAPPEL, true ) );
	if ( ! $dernier ) {
		return true;
	}
	$delai = delai_rappel();
	if ( rappels_envoyes_depuis( $tome_id, (int) ( $ligne['ts_activite'] ?? 0 ) ) >= 2 ) {
		$delai = max( $delai, 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS );
	}
	return maintenant() - $dernier >= $delai;
}

/**
 * Le tome a-t-il reçu un rappel trop récemment ?
 *
 * @param int $tome_id Tome.
 */
function rappel_recent( int $tome_id ): bool {
	$dernier = ts_gmt( (string) get_post_meta( $tome_id, META_DERNIER_RAPPEL, true ) );
	return $dernier > 0 && maintenant() - $dernier < delai_rappel();
}

/**
 * Destinataires d'un rappel de retard : responsable de l'étape en cours, sinon les
 * responsables dont l'étape n'est pas terminée, sinon tous les responsables. Seuls les
 * membres actuels de l'équipe comptent (SCAN-08) : un responsable retiré de l'équipe est
 * traité comme absent (les gérants sont alors prévenus).
 *
 * @param array $ligne Ligne du planning.
 * @return int[]
 */
function destinataires_rappel( array $ligne ): array {
	$membre = static function ( array $ligne, string $etape ): int {
		$id = (int) ( $ligne['responsables'][ $etape ]['id'] ?? 0 );
		return $id > 0 && est_membre( $id ) ? $id : 0;
	};
	$ids    = array();
	$etape  = etape_de_travail( (string) $ligne['etape'] );
	if ( '' !== $etape && $membre( $ligne, $etape ) ) {
		$ids[] = $membre( $ligne, $etape );
	}
	if ( ! $ids ) {
		foreach ( ETAPES_TRAVAIL as $e ) {
			if ( $membre( $ligne, $e ) && (int) $ligne['avancement'][ $e ] < 100 ) {
				$ids[] = $membre( $ligne, $e );
			}
		}
	}
	if ( ! $ids ) {
		foreach ( ETAPES_TRAVAIL as $e ) {
			if ( $membre( $ligne, $e ) ) {
				$ids[] = $membre( $ligne, $e );
			}
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Étapes restantes sans responsable d'un tome (étape en cours et suivantes non terminées). Un
 * responsable qui n'est plus membre de l'équipe compte comme absent (SCAN-08).
 *
 * @param array $ligne Ligne du planning.
 * @return string[]
 */
function roles_manquants( array $ligne ): array {
	$rang      = rang_etape( etape_de_travail( (string) $ligne['etape'] ) );
	$manquants = array();
	foreach ( ETAPES_TRAVAIL as $e ) {
		$uid = (int) ( $ligne['responsables'][ $e ]['id'] ?? 0 );
		if ( rang_etape( $e ) >= $rang && (int) $ligne['avancement'][ $e ] < 100 && ( $uid <= 0 || ! est_membre( $uid ) ) ) {
			$manquants[] = $e;
		}
	}
	return $manquants;
}

/**
 * Nom complet d'un tome pour les messages (« Grimgar of Fantasy and Ash — Tome 10 »).
 *
 * @param array $ligne Ligne.
 */
function nom_tome( array $ligne ): string {
	return trim( $ligne['oeuvre'] . ' — ' . $ligne['tome'], ' —' );
}

/**
 * Résumé de l'avancement (« traduction 100 %, relecture 62 %, édition 0 % »).
 *
 * @param array $ligne Ligne.
 */
function resume_avancement( array $ligne ): string {
	$parties = array();
	foreach ( ETAPES_TRAVAIL as $e ) {
		$parties[] = libelle_etape_min( $e ) . ' ' . pct( (int) $ligne['avancement'][ $e ] );
	}
	return implode( ', ', $parties );
}

/**
 * Phrase du motif d'un retard.
 *
 * @param array $ligne Ligne (motif_retard, jours_retard, date_cible).
 * @param bool  $html  Nom du tome en gras (HTML échappé).
 */
function phrase_retard( array $ligne, bool $html = true ): string {
	$nom   = $html ? '<strong>' . esc_html( nom_tome( $ligne ) ) . '</strong>' : nom_tome( $ligne );
	$jours = (int) $ligne['jours_retard'];
	if ( 'date' === $ligne['motif_retard'] ) {
		return sprintf(
			/* translators: 1: tome, 2: date cible, 3: jours de retard */
			_n( 'La date cible de %1$s (%2$s) est dépassée de %3$d jour.', 'La date cible de %1$s (%2$s) est dépassée de %3$d jours.', $jours, 'yume-core' ),
			$nom,
			esc_html( format_fr( ts_date( (string) $ligne['date_cible'] ), 'l j F' ) ),
			$jours
		);
	}
	return sprintf(
		/* translators: 1: tome, 2: jours */
		_n( 'Le planning de %1$s n’a pas été mis à jour depuis %2$d jour.', 'Le planning de %1$s n’a pas été mis à jour depuis %2$d jours.', $jours, 'yume-core' ),
		$nom,
		$jours
	);
}

/**
 * Corps de l'e-mail de rappel.
 *
 * @param array $ligne        Ligne.
 * @param int   $user_id      Destinataire.
 * @param bool  $vers_gerants Rappel adressé aux gérants faute de responsable.
 */
function email_rappel( array $ligne, int $user_id, bool $vers_gerants ): string {
	$etapes = yume_etapes();
	$html   = '<p>' . esc_html( sprintf( /* translators: %s : pseudo */ __( 'Bonjour %s,', 'yume-core' ), nom_utilisateur( $user_id ) ) ) . '</p>';
	$html  .= '<p>' . phrase_retard( $ligne ) . '</p>';
	$html  .= '<p>' . esc_html(
		sprintf(
			/* translators: 1: étape, 2: avancement */
			__( 'État actuel : %1$s · %2$s.', 'yume-core' ),
			$etapes[ $ligne['etape'] ] ?? '',
			resume_avancement( $ligne )
		)
	) . '</p>';
	if ( $vers_gerants ) {
		$html .= '<p>' . esc_html__( 'Personne n’est responsable de l’étape en cours : attribuez-la depuis l’espace équipe.', 'yume-core' ) . '</p>';
	} else {
		$html .= '<p>' . esc_html__( 'Mettez le planning à jour (même un petit pourcentage compte) ou indiquez une nouvelle date cible. Si le tome ne peut plus avancer, marquez-le bloqué en précisant la raison.', 'yume-core' ) . '</p>';
	}
	// Gérants : ligne du tome dans « Tomes en préparation » (responsables) ; responsable : sa
	// carte dans la vue « Mes tâches ».
	$lien  = $vers_gerants ? yume_url_page( 'equipe' ) . '#yn-tome-' : url_vue_equipe( 'taches' ) . '#yn-tache-';
	$html .= bouton_email( $lien . (int) $ligne['tome_id'], __( 'Mettre à jour le planning', 'yume-core' ) );
	return $html;
}

/**
 * Exécute les rappels du jour.
 *
 * @return array{rappels:array<int,array>,signalements:array<int,array>}
 */
function executer_rappels(): array {
	$rapport = array(
		'rappels'      => array(),
		'signalements' => array(),
	);
	$discord = array();
	$canal   = '' !== webhook( 'equipe' );

	foreach ( lignes_planning(
		array(
			'a_venir' => true,
			'public'  => false,
		)
	) as $ligne ) {
		$tome_id = (int) $ligne['tome_id'];
		// Tome en pause ou d'une œuvre en pause, terminée… : ni rappel ni signalement aux gérants.
		if ( ! in_array( $ligne['etat'], array( 'en_retard', 'bloque' ), true ) || tome_sans_rappels( $tome_id ) ) {
			continue;
		}

		if ( 'en_retard' === $ligne['etat'] ) {
			if ( ! rappel_permis( $ligne ) ) {
				continue;
			}
			$destinataires = destinataires_rappel( $ligne );
			$vers_gerants  = ! $destinataires;
			if ( $vers_gerants ) {
				$destinataires = gerants();
			}
			if ( ! $destinataires ) {
				continue;
			}
			/* translators: %s : tome */
			$sujet = sprintf( __( 'Rappel planning : %s', 'yume-core' ), nom_tome( $ligne ) );
			foreach ( $destinataires as $uid ) {
				mettre_en_file( $uid, $sujet, email_rappel( $ligne, $uid, $vers_gerants ), 'rappel' );
			}
			$noms      = implode( ', ', array_map( __NAMESPACE__ . '\\nom_utilisateur', $destinataires ) );
			$discord[] = sprintf(
				'▲ **%1$s** · %2$s (%3$s) · %4$s',
				echapper_discord( nom_tome( $ligne ) ),
				echapper_discord( wp_strip_all_tags( phrase_retard( $ligne, false ) ) ),
				echapper_discord( libelle_etape_min( etape_de_travail( $ligne['etape'] ) ) . ' ' . pct( (int) ( $ligne['avancement'][ etape_de_travail( $ligne['etape'] ) ] ?? 0 ) ) ),
				/* translators: %s : pseudos */
				echapper_discord( sprintf( $vers_gerants ? __( 'sans responsable, gérants prévenus (%s)', 'yume-core' ) : __( 'responsable : %s', 'yume-core' ), $noms ) )
			);
			journaliser(
				$tome_id,
				0,
				'rappel',
				'',
				array(
					'motif'         => $ligne['motif_retard'],
					'jours'         => (int) $ligne['jours_retard'],
					'destinataires' => $destinataires,
					'canaux'        => $canal ? array( 'email', 'discord' ) : array( 'email' ),
				),
				! $vers_gerants
			);
			update_post_meta( $tome_id, META_DERNIER_RAPPEL, gmt() );
			$rapport['rappels'][] = array(
				'tome_id'       => $tome_id,
				'motif'         => $ligne['motif_retard'],
				'destinataires' => $destinataires,
			);
			continue;
		}

		// Tome bloqué : signalé aux gérants s'il manque quelqu'un.
		if ( rappel_recent( $tome_id ) ) {
			continue;
		}
		$manquants = roles_manquants( $ligne );
		if ( ! $manquants ) {
			continue;
		}
		$gerants = gerants();
		$roles   = implode( ', ', array_map( __NAMESPACE__ . '\\libelle_role_manquant', $manquants ) );
		/* translators: %s : tome */
		$sujet = sprintf( __( 'Tome bloqué sans responsable : %s', 'yume-core' ), nom_tome( $ligne ) );
		$html  = '<p>' . sprintf(
			/* translators: 1: tome, 2: rôles manquants */
			esc_html__( '%1$s est bloqué : %2$s.', 'yume-core' ),
			'<strong>' . esc_html( nom_tome( $ligne ) ) . '</strong>',
			esc_html( $roles )
		) . '</p>';
		if ( '' !== $ligne['bloque_raison'] ) {
			/* translators: %s : raison */
			$html .= '<p>' . esc_html( sprintf( __( 'Raison indiquée : %s', 'yume-core' ), $ligne['bloque_raison'] ) ) . '</p>';
		}
		// Ligne du tome dans « Tomes en préparation » du tableau de bord (formulaire des
		// responsables), comme le rappel d'un retard sans responsable ; la vue « Tous les tomes »
		// (?vue=tomes) ne gère que la lecture en ligne.
		$html .= bouton_email( yume_url_page( 'equipe' ) . '#yn-tome-' . $tome_id, __( 'Attribuer les responsables', 'yume-core' ) );
		foreach ( $gerants as $uid ) {
			mettre_en_file( $uid, $sujet, $html, 'signalement' );
		}
		$discord[] = sprintf(
			'■ **%1$s** · %2$s%3$s · %4$s',
			echapper_discord( nom_tome( $ligne ) ),
			echapper_discord( $roles ),
			'' !== $ligne['bloque_raison'] ? ' (' . echapper_discord( $ligne['bloque_raison'] ) . ')' : '',
			__( 'gérants prévenus', 'yume-core' )
		);
		journaliser(
			$tome_id,
			0,
			'signalement',
			'',
			array(
				'manquants'     => $manquants,
				'destinataires' => $gerants,
				'canaux'        => $canal ? array( 'email', 'discord' ) : array( 'email' ),
			),
			false
		);
		update_post_meta( $tome_id, META_DERNIER_RAPPEL, gmt() );
		$rapport['signalements'][] = array(
			'tome_id'   => $tome_id,
			'manquants' => $manquants,
		);
	}

	if ( $discord ) {
		/* translators: %s : date */
		discord_en_lignes( 'equipe', sprintf( __( '**Rappels du planning** · %s', 'yume-core' ), format_fr( maintenant(), 'l j F' ) ), $discord );
	}
	/**
	 * Les rappels du jour ont été traités.
	 *
	 * @param array $rapport ['rappels' => …, 'signalements' => …].
	 */
	do_action( 'yume_planning_rappels_executes', $rapport );
	return $rapport;
}

/**
 * Tâche cron quotidienne.
 */
function tache_rappels(): void {
	executer_rappels();
	planifier();
}
add_action( HOOK_RAPPELS, __NAMESPACE__ . '\\tache_rappels' );

/**
 * Liste HTML d'un ensemble de lignes pour le récapitulatif.
 *
 * @param array    $lignes Lignes.
 * @param callable $detail Texte de détail d'une ligne.
 */
function liste_digest( array $lignes, callable $detail ): string {
	$html = '<ul style="margin:0 0 16px;padding-left:20px;">';
	foreach ( $lignes as $ligne ) {
		$html .= '<li style="margin:4px 0;"><strong>' . esc_html( nom_tome( $ligne ) ) . '</strong> · ' . esc_html( (string) $detail( $ligne ) ) . '</li>';
	}
	return $html . '</ul>';
}

/**
 * Récapitulatif hebdomadaire aux gérants.
 *
 * @param bool $forcer Envoyer même si le récapitulatif du jour est déjà parti.
 * @return array{envoye:bool,destinataires:int[],sorties:int,retards:int,bloques:int}
 */
function envoyer_digest( bool $forcer = false ): array {
	$aujourdhui = date_locale();
	$resultat   = array(
		'envoye'        => false,
		'destinataires' => array(),
		'sorties'       => 0,
		'retards'       => 0,
		'bloques'       => 0,
	);
	if ( ! $forcer && get_option( OPTION_DERNIER_DIGEST ) === $aujourdhui ) {
		return $resultat;
	}
	$gerants = gerants();
	if ( ! $gerants ) {
		return $resultat;
	}

	$lignes = lignes_planning(
		array(
			'public'                 => false,
			'inclure_publies_depuis' => 7,
		)
	);
	// Œuvres en pause, terminées, abandonnées ou licenciées : ni retard, ni blocage, ni sortie
	// prévue dans le récapitulatif (leurs sorties de la semaine, s'il y en a, restent listées).
	$lignes    = array_values( array_filter( $lignes, static fn( array $l ): bool => 'publie' === $l['etat'] || ! oeuvre_sans_rappels( (int) $l['oeuvre_id'] ) ) );
	$sorties   = array_values( array_filter( $lignes, static fn( array $l ): bool => 'publie' === $l['etat'] ) );
	$retards   = array_values( array_filter( $lignes, static fn( array $l ): bool => 'en_retard' === $l['etat'] ) );
	$bloques   = array_values( array_filter( $lignes, static fn( array $l ): bool => 'bloque' === $l['etat'] ) );
	$dans7     = date_locale( ts_date( $aujourdhui ) + 7 * DAY_IN_SECONDS + 12 * HOUR_IN_SECONDS );
	$a_venir   = array_values( array_filter( $lignes, static fn( array $l ): bool => ! in_array( $l['etat'], array( 'publie', 'bloque' ), true ) && '' !== $l['date_cible'] && $l['date_cible'] >= $aujourdhui && $l['date_cible'] <= $dans7 ) );
	$chapitres = grouper_journal(
		lire_journal(
			array(
				'champs' => array( 'chapitre_publie' ),
				'depuis' => gmt( maintenant() - 7 * DAY_IN_SECONDS ),
				'limit'  => 100,
			)
		),
		true
	);

	$html  = '<p>' . esc_html__( 'Voici l’état du planning cette semaine.', 'yume-core' ) . '</p>';
	$html .= '<h2 style="font-size:17px;margin:20px 0 8px;">' . esc_html__( 'Sorties de la semaine', 'yume-core' ) . '</h2>';
	if ( $sorties || $chapitres ) {
		$html .= liste_digest(
			$sorties,
			static function ( array $l ): string {
				return __( 'publié', 'yume-core' ) . ' ' . format_fr( ts_gmt( $l['date_sortie'] ), 'l j F' );
			}
		);
		if ( $chapitres ) {
			$html .= '<ul style="margin:0 0 16px;padding-left:20px;">';
			foreach ( $chapitres as $entree ) {
				$html .= '<li style="margin:4px 0;">' . esc_html( $entree['cible'] . ' · ' . format_fr( $entree['ts'], 'l j F' ) ) . '</li>';
			}
			$html .= '</ul>';
		}
	} else {
		$html .= '<p>' . esc_html__( 'Aucune sortie cette semaine.', 'yume-core' ) . '</p>';
	}
	$html .= '<h2 style="font-size:17px;margin:20px 0 8px;">' . esc_html__( 'Retards', 'yume-core' ) . '</h2>';
	$html .= $retards ? liste_digest(
		$retards,
		static function ( array $l ): string {
			$noms = implode( ', ', array_filter( wp_list_pluck( $l['responsables'], 'nom' ) ) );
			/* translators: %s : pseudos */
			$texte = wp_strip_all_tags( phrase_retard( $l, false ) ) . ' ' . sprintf( __( 'Responsables : %s.', 'yume-core' ), '' !== $noms ? $noms : __( 'aucun', 'yume-core' ) );
			return rappels_plafonnes( $l ) ? $texte . ' ' . __( 'Plus de rappel automatique : mettez le tome à jour, en pause ou retirez-le du planning.', 'yume-core' ) : $texte;
		}
	) : '<p>' . esc_html__( 'Aucun retard : tout est à l’heure.', 'yume-core' ) . '</p>';
	$html .= '<h2 style="font-size:17px;margin:20px 0 8px;">' . esc_html__( 'Tomes bloqués', 'yume-core' ) . '</h2>';
	$html .= $bloques ? liste_digest(
		$bloques,
		static function ( array $l ): string {
			$manquants = array_map( __NAMESPACE__ . '\\libelle_role_manquant', roles_manquants( $l ) );
			$texte     = trim( implode( ' · ', array_filter( array( $l['bloque_raison'], implode( ', ', $manquants ) ) ) ) );
			return '' !== $texte ? $texte : __( 'raison non précisée', 'yume-core' );
		}
	) : '<p>' . esc_html__( 'Aucun tome bloqué.', 'yume-core' ) . '</p>';
	$html .= '<h2 style="font-size:17px;margin:20px 0 8px;">' . esc_html__( 'Sorties prévues dans les 7 jours', 'yume-core' ) . '</h2>';
	$html .= $a_venir ? liste_digest(
		$a_venir,
		static function ( array $l ): string {
			$etape = etape_de_travail( $l['etape'] );
			return format_fr( ts_date( $l['date_cible'] ), 'l j F' ) . ' · ' . libelle_etape_min( $etape ) . ' ' . pct( (int) ( $l['avancement'][ $etape ] ?? 0 ) );
		}
	) : '<p>' . esc_html__( 'Aucune sortie datée cette semaine.', 'yume-core' ) . '</p>';
	$html .= bouton_email( yume_url_page( 'equipe' ), __( 'Ouvrir l’espace équipe', 'yume-core' ) );

	/* translators: %s : date du jour */
	$sujet = sprintf( __( 'Récapitulatif du planning · %s', 'yume-core' ), format_fr( maintenant(), 'l j F' ) );
	foreach ( $gerants as $uid ) {
		mettre_en_file( $uid, $sujet, $html, 'digest' );
	}
	$resultat = array(
		'envoye'        => true,
		'destinataires' => $gerants,
		'sorties'       => count( $sorties ) + count( $chapitres ),
		'retards'       => count( $retards ),
		'bloques'       => count( $bloques ),
	);
	journaliser(
		0,
		0,
		'digest',
		'',
		array(
			'sorties'       => $resultat['sorties'],
			'retards'       => $resultat['retards'],
			'bloques'       => $resultat['bloques'],
			'destinataires' => $gerants,
		),
		false
	);
	update_option( OPTION_DERNIER_DIGEST, $aujourdhui, false );
	return $resultat;
}

/**
 * Tâche cron hebdomadaire.
 */
function tache_digest(): void {
	envoyer_digest();
	planifier();
}
add_action( HOOK_DIGEST, __NAMESPACE__ . '\\tache_digest' );
