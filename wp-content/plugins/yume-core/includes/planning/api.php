<?php
/**
 * API PHP publique du module planning (§7 du contrat). Signatures figées : toute évolution
 * passe d'abord par docs/06-contrat-technique.md.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * État d'un tome sur le planning.
 *
 * - 'publie'    : l'étape du planning est « Publié » ;
 * - 'bloque'    : le tome est marqué bloqué ;
 * - 'en_retard' : date cible antérieure à aujourd'hui (heure de Paris), ou aucune mise à jour
 *                 depuis plus de yume_setting( 'rappel_jours_sans_maj' ) jours ;
 * - 'a_lheure'  : sinon (et pour un ID qui n'est pas un tome). Un tome programmé (statut
 *                 future) est toujours « à l'heure » : il sortira à sa date (libellé
 *                 « Programmé le … », voir yume_get_planning()).
 *
 * @param int $tome_id ID du tome.
 * @return string 'publie'|'bloque'|'en_retard'|'a_lheure'
 */
function yume_planning_etat( int $tome_id ): string {
	$etat = 'yume_tome' === get_post_type( $tome_id ) ? \Yume\Core\Planning\analyser_etat( $tome_id )['etat'] : 'a_lheure';
	/**
	 * Filtre l'état d'un tome sur le planning.
	 *
	 * @param string $etat    'publie'|'bloque'|'en_retard'|'a_lheure'.
	 * @param int    $tome_id ID du tome.
	 */
	$etat = (string) apply_filters( 'yume_planning_etat', $etat, $tome_id );
	return array_key_exists( $etat, \Yume\Core\Planning\etats() ) ? $etat : 'a_lheure';
}

/**
 * Lignes du planning.
 *
 * Chaque ligne : 'tome_id', 'oeuvre_id', 'oeuvre' (titre), 'tome' (« Tome 9 »), 'etape',
 * 'avancement' (traduction/relecture/edition → 0-100), 'responsables' (étape → ['id','nom']),
 * 'date_cible' (Y-m-d ou ''), 'etat', 'derniere_maj' (Y-m-d H:i:s GMT ou ''), 'url_oeuvre' ;
 * et en complément : 'titre' (sous-titre du tome), 'nature', 'numero', 'type' (slug yume_type),
 * 'statut' (statut WordPress), 'url' (tome publié), 'bloque', 'bloque_raison', 'motif_retard'
 * ('date'|'inactivite'|''), 'jours_retard', 'chapitres' (['publies','total']), 'date_sortie'
 * (GMT, tome publié), 'maj_par' (['id','nom']), 'ts_activite', 'programme' (bool : tome
 * programmé, statut future), 'date_programmee' (Y-m-d, heure de Paris, ou ''), 'heure_cible'
 * (heure de sortie HH:MM, heure du site, ou '' si elle n'est pas précisée) et 'titre_cache'
 * (bool : œuvre « série à venir », yume_oeuvre_a_venir()) ; pour un tome programmé,
 * 'date_cible' et 'heure_cible' sont le jour et l'heure de sortie programmés. Vue publique : une
 * ligne au titre caché a pour 'oeuvre' le nom public (yume_titre_public_oeuvre()), 'titre',
 * 'url' et 'url_oeuvre' vides.
 *
 * Tri : tomes en cours par date cible (sans date en dernier), puis tomes publiés du plus récent
 * au plus ancien.
 *
 * @param array $args 'oeuvre_id' (int), 'type' (slug yume_type), 'etat', 'a_venir' (bool, exclut
 *                    les tomes publiés), 'limit' (0 = tout), 'inclure_publies_depuis' (jours,
 *                    défaut 14 ; 0 = aucun tome publié), 'publies_du_jour' (bool, défaut faux :
 *                    seuls les tomes publiés aujourd'hui, depuis minuit heure de Paris, plus les
 *                    tomes publiés encore en cours de publication ; page Planning) ; extensions : 'responsable' (ID : tomes
 *                    dont il est responsable), 'public' (bool, défaut vrai : exclut les tomes
 *                    privés et ceux d'une œuvre non publiée, sauf une série à venir) ;
 *                    'gestion' (bool) : vue de gestion
 *                    de l'équipe, TOUS les tomes vivants (draft, future, pending, publish,
 *                    private) de toutes les œuvres quels que soient leur étape et leur âge
 *                    (a_venir, inclure_publies_depuis et public ignorés), filtrables par
 *                    'oeuvre_id', 'statut' (statut WordPress), 'etat', 'type', 'responsable'.
 * @return array<int,array<string,mixed>>
 */
function yume_get_planning( array $args = array() ): array {
	return \Yume\Core\Planning\lignes_planning( $args );
}

/**
 * Journalise la modification d'un champ du planning d'un tome (table planning_journal).
 *
 * Les tableaux et objets sont enregistrés en JSON, les booléens en « 1 »/« 0 ». La note
 * d'équipe (champ 'note_equipe') n'est jamais publique. Rien n'est écrit si l'ancienne et la
 * nouvelle valeur sont identiques. Hors d'une mise à jour faite par le module planning (par
 * exemple la méta-boîte du cœur), l'action yume_planning_mis_a_jour est émise une fois
 * l'enregistrement du tome terminé.
 *
 * @param int    $tome_id ID du tome.
 * @param int    $user_id Auteur (0 = système).
 * @param string $champ   Champ modifié (etape, avancement, responsables, date_cible,
 *                        heure_cible, bloque, bloque_raison, note_equipe…).
 * @param mixed  $ancien  Ancienne valeur.
 * @param mixed  $nouveau Nouvelle valeur.
 */
function yume_journal_planning( int $tome_id, int $user_id, string $champ, $ancien, $nouveau ): void {
	$id = \Yume\Core\Planning\journaliser( $tome_id, $user_id, $champ, $ancien, $nouveau );
	if ( $id && ! \Yume\Core\Planning\en_service() ) {
		\Yume\Core\Planning\tampon_ajouter( $tome_id, $user_id, $champ, $ancien, $nouveau );
	}
}

/**
 * Ajoute un e-mail à la file d'envoi. L'envoi se fait par lots (événement cron toutes les
 * 5 minutes) dans le gabarit HTML Yume, avec le lien « Gérer mes alertes ».
 *
 * @param int|string $destinataire ID utilisateur ou adresse e-mail.
 * @param string     $sujet        Sujet.
 * @param string     $html         Contenu HTML (le corps : il est placé dans le gabarit).
 * @param string     $contexte     Origine (ex. 'rappel', 'digest', 'alerte'), 60 caractères max.
 */
function yume_queue_email( $destinataire, string $sujet, string $html, string $contexte = '' ): void {
	\Yume\Core\Planning\mettre_en_file( $destinataire, $sujet, $html, $contexte );
}

/**
 * Publie un message sur un salon Discord par webhook (réglages discord_webhook_sorties,
 * discord_webhook_equipe et, pour le canal 'chapitres', discord_chapitres /
 * discord_webhook_chapitres). Aucune requête si le webhook n'est pas réglé ; un échec est
 * journalisé (option yume_planning_echecs) sans erreur fatale.
 *
 * @param string $canal  'sorties', 'chapitres' ou 'equipe'.
 * @param string $texte  Message (tronqué à 2 000 caractères).
 * @param array  $embeds Embeds Discord (10 au plus).
 * @return bool Vrai si Discord a accepté le message.
 */
function yume_discord( string $canal, string $texte, array $embeds = array() ): bool {
	return \Yume\Core\Planning\envoyer_discord( $canal, $texte, $embeds );
}
