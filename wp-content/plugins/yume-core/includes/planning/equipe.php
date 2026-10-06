<?php
/**
 * Espace équipe (bloc yume/team-dashboard, maquette TeamDashboard) : chiffres, « Mes tâches »
 * (curseur d'avancement, étape, date cible, blocage, note), « Tomes en préparation » et le lien
 * « Ajouter un tome au planning » vers « Nouveau tome » (yume_maj_planning_tous), retards, rappels
 * récents, journal de l'équipe.
 *
 * Vues de la même page (paramètre « vue », sans page supplémentaire) : « Planning complet »
 * (?vue=planning : tous les tomes vivants, filtres œuvre / état / statut / responsable, tri
 * « en retard d'abord », export CSV, formulaire par ligne, raccourcis vers la publication,
 * l'administration et la fiche, « Retirer du planning », « Mettre en pause » / « Reprendre »),
 * « Journal » (?vue=journal : tout le journal, paginé, filtrable par tome) et
 * « Réglages » (?vue=reglages, capacité yume_reglages : voir reglages-equipe.php), « Lecture à
 * compléter » (?vue=lecture, capacité yume_publier : voir lecture-a-completer.php), « Tous les
 * tomes » (?vue=tomes, capacité yume_publier, publiés compris : voir tomes-equipe.php ; « Nouveau
 * tome » et « Modifier le tome » : voir tome-fiche-equipe.php), « Mes
 * tâches » (?vue=taches : voir mes-taches.php) et « Œuvres » (?vue=oeuvres, capacité
 * edit_yume_oeuvres : œuvres du catalogue et formulaire « Nouvelle œuvre », voir oeuvres-equipe.php).
 *
 * Les formulaires de mise à jour passent par la REST en JavaScript (view.js) et, sans
 * JavaScript, par admin-post.php (actions yume_planning_maj, yume_planning_retrait et
 * yume_planning_pause, nonce) ; « Nouveau tome » est envoyé à admin-post.php (action
 * yume_planning_ajout, nonce) ; l'export CSV passe par admin-post.php (action
 * yume_planning_export, nonce, capacité yume_voir_equipe).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/*
 * -----------------------------------------------------------------------------
 * Données
 * -----------------------------------------------------------------------------
 */

/**
 * Tâches d'un membre : tomes en cours dont il est responsable d'une étape non encore passée.
 *
 * @param array $lignes  Lignes (en cours).
 * @param int   $user_id Membre.
 * @return array<int,array{ligne:array,etapes:string[],principale:string,attente:bool}>
 */
function taches( array $lignes, int $user_id ): array {
	$taches = array();
	foreach ( $lignes as $l ) {
		if ( 'publie' === $l['etat'] ) {
			continue;
		}
		$courante = etape_de_travail( $l['etape'] );
		$rang     = rang_etape( $courante );
		$etapes   = array();
		foreach ( ETAPES_TRAVAIL as $e ) {
			if ( (int) ( $l['responsables'][ $e ]['id'] ?? 0 ) === $user_id && rang_etape( $e ) >= $rang ) {
				$etapes[] = $e;
			}
		}
		if ( ! $etapes ) {
			continue;
		}
		$taches[] = array(
			'ligne'      => $l,
			'etapes'     => $etapes,
			'principale' => in_array( $courante, $etapes, true ) ? $courante : $etapes[0],
			'attente'    => ! in_array( $courante, $etapes, true ),
		);
	}
	// Mes retards d'abord, puis l'ordre du planning ; les tâches en attente en dernier.
	usort(
		$taches,
		static function ( array $a, array $b ): int {
			$ra = $a['attente'] ? 2 : ( 'en_retard' === $a['ligne']['etat'] ? 0 : 1 );
			$rb = $b['attente'] ? 2 : ( 'en_retard' === $b['ligne']['etat'] ? 0 : 1 );
			return $ra !== $rb ? $ra <=> $rb : comparer_lignes( $a['ligne'], $b['ligne'] );
		}
	);
	return $taches;
}

/**
 * Retour d'un formulaire sans JavaScript (message affiché dans le formulaire concerné).
 *
 * @param int        $user_id Membre.
 * @param array|null $retour  Nouveau retour (null : lecture et effacement).
 * @return array{cible:string,type:string,message:string}|null
 */
function retour_formulaire( int $user_id, ?array $retour = null ): ?array {
	$cle = 'yume_planning_retour_' . $user_id;
	if ( null !== $retour ) {
		set_transient( $cle, $retour, 5 * MINUTE_IN_SECONDS );
		return $retour;
	}
	$valeur = get_transient( $cle );
	if ( false === $valeur ) {
		return null;
	}
	delete_transient( $cle );
	return is_array( $valeur ) ? $valeur : null;
}

/*
 * -----------------------------------------------------------------------------
 * Champs de formulaire
 * -----------------------------------------------------------------------------
 */

/**
 * Curseur d'avancement d'une étape.
 *
 * @param string $prefixe  Préfixe d'identifiant unique.
 * @param string $etape    Étape.
 * @param int    $valeur   Pourcentage.
 * @param string $variante '' ou 'warn'.
 * @param bool   $etape_visible Nom de l'étape affiché (sinon réservé aux lecteurs d'écran).
 */
function champ_curseur( string $prefixe, string $etape, int $valeur, string $variante = '', bool $etape_visible = true ): string {
	$id         = $prefixe . '-av-' . $etape;
	$html       = '<div class="yn-team__curseur' . ( 'warn' === $variante ? ' yn-team__curseur--warn' : '' ) . '">';
	$html      .= '<span class="yn-team__curseur-tete"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Avancement', 'yume-core' );
	$etape_html = ' ' . esc_html( libelle_etape_min( $etape ) );
	$html      .= ( $etape_visible ? $etape_html : '<span class="yn-visually-hidden">' . $etape_html . '</span>' ) . '</label>';
	$html      .= '<output id="' . esc_attr( $id ) . '-val" for="' . esc_attr( $id ) . '">' . esc_html( pct( $valeur ) ) . '</output></span>';
	$html      .= '<input type="range" id="' . esc_attr( $id ) . '" name="avancement[' . esc_attr( $etape ) . ']" min="0" max="100" step="1" value="' . (int) $valeur . '" style="--v:' . (int) $valeur . '%" data-yn-avancement data-yn-sortie="' . esc_attr( $id ) . '-val">';
	return $html . '</div>';
}

/**
 * Liste déroulante.
 *
 * @param string $id       Identifiant.
 * @param string $nom      Nom du champ.
 * @param string $label    Libellé.
 * @param array  $options  valeur => libellé.
 * @param string $actuelle Valeur courante.
 * @param array  $attrs    Attributs supplémentaires.
 */
function champ_select( string $id, string $nom, string $label, array $options, string $actuelle, array $attrs = array() ): string {
	$html = '<p class="yn-team__champ"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '"';
	foreach ( $attrs as $a => $v ) {
		$html .= true === $v ? ' ' . esc_attr( $a ) : ' ' . esc_attr( $a ) . '="' . esc_attr( (string) $v ) . '"';
	}
	$html .= '>';
	foreach ( $options as $valeur => $libelle ) {
		$html .= '<option value="' . esc_attr( (string) $valeur ) . '"' . selected( (string) $valeur, $actuelle, false ) . '>' . esc_html( $libelle ) . '</option>';
	}
	return $html . '</select></p>';
}

/**
 * Champ texte, date ou nombre.
 *
 * @param string $id     Identifiant.
 * @param string $nom    Nom.
 * @param string $label  Libellé.
 * @param string $valeur Valeur.
 * @param string $type   Type.
 * @param array  $attrs  Attributs supplémentaires.
 * @param string $classe Classe du paragraphe.
 */
function champ_saisie( string $id, string $nom, string $label, string $valeur, string $type = 'text', array $attrs = array(), string $classe = '' ): string {
	$html  = '<p class="yn-team__champ' . ( '' !== $classe ? ' ' . esc_attr( $classe ) : '' ) . '"><label class="yn-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
	$html .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $nom ) . '" value="' . esc_attr( $valeur ) . '"';
	foreach ( $attrs as $a => $v ) {
		$html .= true === $v ? ' ' . esc_attr( $a ) : ' ' . esc_attr( $a ) . '="' . esc_attr( (string) $v ) . '"';
	}
	return $html . '></p>';
}

/**
 * Heure de sortie enregistrée d'une ligne du planning, pour le champ « Heure de sortie »
 * (jamais celle d'une sortie programmée : le champ modifie l'heure de sortie du planning) ;
 * à défaut, celle du rythme d'un tome antérieur à ce champ.
 *
 * @param array $ligne Ligne (tome_id).
 */
function heure_saisie_ligne( array $ligne ): string {
	$heure = donnees_tome( (int) $ligne['tome_id'] )['heure_cible'];
	if ( '' === $heure ) {
		$rythme = get_post_meta( (int) $ligne['tome_id'], 'yume_rythme', true );
		$heure  = is_array( $rythme ) && ! empty( $rythme['jour'] ) && is_string( $rythme['heure'] ?? null ) && valider_heure( $rythme['heure'] ) ? $rythme['heure'] : '';
	}
	return $heure;
}

/**
 * Case « bloqué » et raison.
 *
 * @param string $prefixe Préfixe.
 * @param array  $ligne   Ligne.
 */
function champ_blocage( string $prefixe, array $ligne ): string {
	$html   = '<div class="yn-team__blocage"><input type="hidden" name="bloque_present" value="1">';
	$html  .= '<label class="yn-team__case" for="' . esc_attr( $prefixe ) . '-bloque"><input type="checkbox" id="' . esc_attr( $prefixe ) . '-bloque" name="bloque" value="1"' . checked( (bool) $ligne['bloque'], true, false ) . '> ' . esc_html__( 'Tome bloqué', 'yume-core' ) . '</label>';
	$html  .= '<label class="yn-visually-hidden" for="' . esc_attr( $prefixe ) . '-raison">' . esc_html__( 'Raison du blocage', 'yume-core' ) . '</label>';
	$raison = (string) get_post_meta( (int) $ligne['tome_id'], 'yume_bloque_raison', true );
	$html  .= '<input type="text" id="' . esc_attr( $prefixe ) . '-raison" name="bloque_raison" value="' . esc_attr( $raison ) . '" maxlength="200" placeholder="' . esc_attr__( 'Raison (ex. relecteur manquant)', 'yume-core' ) . '">';
	return $html . '</div>';
}

/**
 * Champs cachés d'un formulaire de mise à jour (sans JavaScript).
 *
 * @param int    $tome_id Tome.
 * @param string $ancre   Ancre de retour.
 */
function champs_caches_maj( int $tome_id, string $ancre ): string {
	$html  = '<input type="hidden" name="action" value="yume_planning_maj">';
	$html .= '<input type="hidden" name="tome_id" value="' . (int) $tome_id . '">';
	$html .= '<input type="hidden" name="ancre" value="' . esc_attr( $ancre ) . '">';
	$html .= wp_nonce_field( 'yume_planning_maj_' . $tome_id, '_yume_nonce', true, false );
	return $html;
}

/**
 * Zone d'annonce du résultat d'un formulaire (aria-live).
 *
 * @param array|null $retour Retour sans JavaScript à afficher.
 */
function zone_retour( ?array $retour ): string {
	$classe = 'yn-team__retour';
	$texte  = '';
	if ( $retour ) {
		$classe .= 'ok' === $retour['type'] ? ' yn-team__retour--ok' : ' yn-team__retour--erreur';
		$texte   = (string) $retour['message'];
	}
	return '<p class="' . esc_attr( $classe ) . '" role="status" aria-live="polite" data-yn-retour>' . esc_html( $texte ) . '</p>';
}

/**
 * Sous-titre d'une tâche (« Relecture · cible dim. 27 sept. · traduction terminée par Calumi »).
 *
 * @param array $tache Tâche.
 */
function sous_titre_tache( array $tache ): string {
	$l        = $tache['ligne'];
	$etapes   = yume_etapes();
	$courante = etape_de_travail( $l['etape'] );
	$parties  = array( $etapes[ $tache['principale'] ] );
	if ( $tache['attente'] ) {
		$resp      = (string) $l['responsables'][ $courante ]['nom'];
		$parties[] = sprintf(
			/* translators: 1: étape en cours, 2: responsable et avancement */
			__( 'en attente de la %1$s (%2$s)', 'yume-core' ),
			libelle_etape_min( $courante ),
			trim( ( '' !== $resp ? $resp . ', ' : '' ) . pct( (int) $l['avancement'][ $courante ] ), ', ' )
		);
		return implode( ' · ', $parties );
	}
	if ( '' !== $l['date_cible'] ) {
		$parties[] = 'date' === $l['motif_retard']
			/* translators: %s : date */
			? sprintf( __( 'cible %s dépassée', 'yume-core' ), format_fr( ts_date( $l['date_cible'] ), 'j M' ) )
			/* translators: %s : date */
			: sprintf( __( 'cible %s', 'yume-core' ), date_cible_lisible( $l['date_cible'], true, (string) ( $l['heure_cible'] ?? '' ) ) );
	} else {
		$parties[] = __( 'pas de date cible', 'yume-core' );
	}
	$rang = rang_etape( $tache['principale'] );
	if ( $rang > 1 ) {
		$precedente = ETAPES_TRAVAIL[ $rang - 2 ];
		$nom        = (string) $l['responsables'][ $precedente ]['nom'];
		if ( 100 === (int) $l['avancement'][ $precedente ] ) {
			$parties[] = '' !== $nom
				/* translators: 1: étape, 2: pseudo */
				? sprintf( __( '%1$s terminée par %2$s', 'yume-core' ), libelle_etape_min( $precedente ), $nom )
				/* translators: %s : étape */
				: sprintf( __( '%s terminée', 'yume-core' ), libelle_etape_min( $precedente ) );
		}
	}
	$rappel = ts_gmt( (string) get_post_meta( (int) $l['tome_id'], META_DERNIER_RAPPEL, true ) );
	if ( $rappel && 'en_retard' === $l['etat'] ) {
		/* translators: %s : durée */
		$parties[] = sprintf( __( 'rappel envoyé %s', 'yume-core' ), il_y_a( $rappel ) );
	}
	return implode( ' · ', $parties );
}

/**
 * Champs d'une mise à jour « Mes tâches » : curseur(s), étape, date, bouton ; puis note et
 * blocage.
 *
 * @param array      $tache   Tâche.
 * @param string     $prefixe Préfixe d'identifiant.
 * @param array|null $retour  Retour sans JavaScript.
 */
function champs_tache( array $tache, string $prefixe, ?array $retour ): string {
	$l      = $tache['ligne'];
	$retard = 'en_retard' === $l['etat'] && ! $tache['attente'];
	$html   = '<div class="yn-team__champs">';
	$html  .= '<div class="yn-team__curseurs">';
	foreach ( $tache['etapes'] as $etape ) {
		$html .= champ_curseur( $prefixe, $etape, (int) $l['avancement'][ $etape ], $retard && $etape === $tache['principale'] ? 'warn' : '', count( $tache['etapes'] ) > 1 );
	}
	$html   .= '</div>';
	$options = etapes_proposees( (int) $l['tome_id'], (string) $l['etape'], get_current_user_id() );
	$html   .= champ_select( $prefixe . '-etape', 'etape', __( 'Étape', 'yume-core' ), $options, $l['etape'] );
	$html   .= champ_saisie( $prefixe . '-date', 'date_cible', 'date' === $l['motif_retard'] ? __( 'Nouvelle date', 'yume-core' ) : __( 'Date cible', 'yume-core' ), $l['date_cible'], 'date' );
	$html   .= champ_saisie( $prefixe . '-heure', 'heure_cible', __( 'Heure de sortie', 'yume-core' ), heure_saisie_ligne( $l ), 'time', array( 'step' => 60 ) );
	$html   .= '<p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button></p>';
	$html   .= '</div><div class="yn-team__champs yn-team__champs--bas">';
	$html   .= champ_saisie(
		$prefixe . '-note',
		'note_equipe',
		__( 'Note pour l’équipe (facultatif)', 'yume-core' ),
		(string) get_post_meta( (int) $l['tome_id'], 'yume_note_equipe', true ),
		'text',
		array(
			'maxlength'   => 2000,
			'placeholder' => __( 'Ex. : chapitres 1 à 12 relus, reste la postface', 'yume-core' ),
		),
		'yn-team__note'
	);
	$html   .= champ_blocage( $prefixe, $l );
	$html   .= '</div>';
	return $html . zone_retour( $retour );
}

/**
 * Carte d'une tâche.
 *
 * @param array      $tache  Tâche.
 * @param array|null $retour Retour sans JavaScript (si ce formulaire est concerné).
 */
function carte_tache( array $tache, ?array $retour ): string {
	$l     = $tache['ligne'];
	$id    = (int) $l['tome_id'];
	$ancre = 'yn-tache-' . $id;
	$titre = $ancre . '-titre';
	// Une tâche en attente n'est pas en retard pour ce membre : le retard est celui de l'étape en cours.
	$retard  = 'en_retard' === $l['etat'] && ! $tache['attente'];
	$classes = 'yn-card yn-team__tache' . ( $retard ? ' yn-team__tache--retard' : '' ) . ( $tache['attente'] ? ' yn-team__tache--attente' : '' );
	$nom     = nom_ligne( $l );
	$puce    = $tache['attente'] && 'bloque' !== $l['etat']
		? '<span class="yn-chip yn-chip--info" data-yn-puce>' . esc_html__( 'En attente', 'yume-core' ) . '</span>'
		: pastille_ligne( $l, true, array( 'data-yn-puce' => '' ) );
	$tete    = '<div class="yn-team__tache-tete"><div><h3 class="yn-team__tache-titre" id="' . esc_attr( $titre ) . '">' . esc_html( $nom ) . badge_pause( $l ) . '</h3>';
	$tete   .= '<p class="yn-muted yn-team__tache-info">' . esc_html( sous_titre_tache( $tache ) ) . '</p></div>' . $puce . '</div>';
	$action  = esc_url( admin_url( 'admin-post.php' ) );

	if ( $tache['attente'] && ! $retour ) {
		$html  = '<article class="' . esc_attr( $classes ) . '" id="' . esc_attr( $ancre ) . '" aria-labelledby="' . esc_attr( $titre ) . '" data-yn-carte>' . $tete;
		$html .= '<details class="yn-team__details"><summary>' . esc_html__( 'Mettre à jour quand même', 'yume-core' ) . '</summary>';
		$html .= '<form method="post" action="' . $action . '" data-yn-planning="' . $id . '">' . champs_caches_maj( $id, $ancre ) . champs_tache( $tache, 't' . $id, null ) . '</form>';
		return $html . '</details>' . liens_tome( $l, false, true ) . '</article>';
	}
	// La carte est un formulaire : les raccourcis sont de simples liens (pas de formulaire imbriqué).
	$html  = '<form class="' . esc_attr( $classes ) . '" id="' . esc_attr( $ancre ) . '" method="post" action="' . $action . '" aria-labelledby="' . esc_attr( $titre ) . '" data-yn-planning="' . $id . '" data-yn-carte>';
	$html .= $tete . champs_caches_maj( $id, $ancre ) . champs_tache( $tache, 't' . $id, $retour ) . liens_tome( $l, false, true );
	return $html . '</form>';
}

/**
 * Résumé d'une ligne du planning (texte du <summary>) : œuvre, tome, étape en cours, date, et
 * statut WordPress quand le tome n'est pas un simple brouillon.
 *
 * @param array $l Ligne.
 */
function resume_ligne( array $l ): string {
	$etape  = etape_de_travail( $l['etape'] );
	$resume = 'publie' === $l['etat']
		? __( 'publié', 'yume-core' )
		: libelle_etape_min( $etape ) . ' ' . pct( (int) ( $l['avancement'][ $etape ] ?? 0 ) ) . ' · ' . ( '' !== $l['date_cible'] ? date_cible_lisible( $l['date_cible'], true, 'en_retard' === $l['etat'] ? '' : (string) ( $l['heure_cible'] ?? '' ) ) : __( 'pas de date', 'yume-core' ) );
	$statut = (string) ( $l['statut'] ?? '' );
	$html   = '<span class="yn-team__resume"><b>' . esc_html( $l['oeuvre'] ) . '</b> · ' . esc_html( $l['tome'] . ( '' !== $l['titre'] ? ' : ' . $l['titre'] : '' ) ) . ' <span class="yn-muted">' . esc_html( $resume ) . '</span>';
	if ( '' !== $statut && 'draft' !== $statut && 'publie' !== $l['etat'] && ! est_programme( $l ) ) {
		$html .= ' <span class="yn-team__statut yn-muted">(' . esc_html( libelle_statut( $l ) ) . ')</span>';
	}
	return $html . badge_pause( $l ) . ( ! empty( $l['titre_cache'] ) ? pastille_titre_cache( (int) $l['oeuvre_id'] ) : '' ) . '</span>';
}

/**
 * Badge « En pause » d'un tome mis en pause par l'équipe, ou « Œuvre en pause » (« Œuvre
 * terminée »…) pour un tome pas encore publié d'une œuvre sans rappels (vide sinon ; jamais
 * en façade publique : seul l'espace équipe l'affiche).
 *
 * @param array $l Ligne.
 */
function badge_pause( array $l ): string {
	$pause = infos_pause( (int) $l['tome_id'] );
	if ( ! $pause ) {
		return badge_oeuvre_sans_rappels( $l );
	}
	$titre = sprintf(
		/* translators: 1: date, 2: pseudo */
		__( 'En pause depuis le %1$s (%2$s)', 'yume-core' ),
		format_fr( ts_gmt( $pause['depuis'] ), 'j M Y' ),
		$pause['par'] ? nom_utilisateur( $pause['par'] ) : __( 'Système', 'yume-core' )
	);
	return ' <span class="yn-chip yn-team__pause" title="' . esc_attr( $titre ) . '"><span aria-hidden="true">❚❚</span> ' . esc_html__( 'En pause', 'yume-core' ) . '</span>';
}

/**
 * Libellé de l'état d'une œuvre sans rappels pour ses tomes (« Œuvre en pause », « Œuvre
 * terminée »…), ou chaîne vide si l'œuvre relance normalement.
 *
 * @param int $oeuvre_id Œuvre.
 */
function libelle_oeuvre_sans_rappels( int $oeuvre_id ): string {
	if ( ! oeuvre_sans_rappels( $oeuvre_id ) ) {
		return '';
	}
	$libelles = array(
		'en-pause'   => __( 'Œuvre en pause', 'yume-core' ),
		'terminee'   => __( 'Œuvre terminée', 'yume-core' ),
		'abandonnee' => __( 'Œuvre abandonnée', 'yume-core' ),
		'licenciee'  => __( 'Œuvre licenciée', 'yume-core' ),
	);
	foreach ( etats_termes_oeuvre( $oeuvre_id ) as $etat ) {
		if ( isset( $libelles[ $etat ] ) ) {
			return $libelles[ $etat ];
		}
	}
	return '';
}

/**
 * Badge « Œuvre en pause » (« Œuvre terminée »…) d'un tome pas encore publié dont l'œuvre ne
 * relance personne : à la place d'un retard (vide sinon).
 *
 * @param array $l Ligne.
 */
function badge_oeuvre_sans_rappels( array $l ): string {
	if ( 'publie' === ( $l['etat'] ?? '' ) ) {
		return '';
	}
	$libelle = libelle_oeuvre_sans_rappels( (int) ( $l['oeuvre_id'] ?? 0 ) );
	if ( '' === $libelle ) {
		return '';
	}
	return ' <span class="yn-chip yn-team__pause yn-team__pause--oeuvre" title="' . esc_attr__( 'Ni retard, ni rappel, ni alerte tant que l’œuvre garde cet état.', 'yume-core' ) . '"><span aria-hidden="true">❚❚</span> ' . esc_html( $libelle ) . '</span>';
}

/**
 * Ligne dépliable de « Tomes en préparation » et du planning complet : formulaire complet
 * (responsables compris), raccourcis et « Retirer du planning ».
 *
 * @param array      $l       Ligne.
 * @param array      $membres Membres (ID => pseudo).
 * @param array|null $retour  Retour sans JavaScript.
 * @param bool       $ouvert  Ligne dépliée d'office (lien direct vers ce tome).
 */
function ligne_gestion( array $l, array $membres, ?array $retour, bool $ouvert = false ): string {
	$id      = (int) $l['tome_id'];
	$ancre   = 'yn-tome-' . $id;
	$prefixe = 'g' . $id;
	$html    = '<li class="yn-team__ligne" data-yn-carte><details class="yn-team__details" id="' . esc_attr( $ancre ) . '"' . ( $retour || $ouvert ? ' open' : '' ) . '>';
	$html   .= '<summary>' . resume_ligne( $l );
	$html   .= pastille_ligne( $l, true, array( 'data-yn-puce' => '' ) ) . '</summary>';
	$html   .= '<div class="yn-team__corps">';
	$html   .= '<form class="yn-team__gestion" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-planning="' . $id . '">';
	$html   .= champs_caches_maj( $id, $ancre );
	// Erreur de la dernière tentative (étape prématurée…) : en tête du formulaire, bien visible.
	$html .= zone_retour( $retour );
	$html .= '<div class="yn-team__champs">';
	$html .= champ_select( $prefixe . '-etape', 'etape', __( 'Étape', 'yume-core' ), etapes_proposees( $id, (string) $l['etape'], get_current_user_id() ), $l['etape'] );
	$html .= champ_saisie( $prefixe . '-date', 'date_cible', __( 'Date cible', 'yume-core' ), $l['date_cible'], 'date' );
	$html .= champ_saisie( $prefixe . '-heure', 'heure_cible', __( 'Heure de sortie', 'yume-core' ), heure_saisie_ligne( $l ), 'time', array( 'step' => 60 ) );
	$html .= '</div><div class="yn-team__etapes">';
	$choix = array( '0' => __( '— Personne —', 'yume-core' ) ) + $membres;
	foreach ( ETAPES_TRAVAIL as $e ) {
		$uid = (int) $l['responsables'][ $e ]['id'];
		if ( $uid && ! isset( $choix[ $uid ] ) ) {
			$choix[ $uid ] = nom_utilisateur( $uid );
		}
		$html .= '<fieldset class="yn-team__etape"><legend class="yn-label">' . esc_html( yume_etapes()[ $e ] ) . '</legend>';
		$html .= champ_curseur( $prefixe, $e, (int) $l['avancement'][ $e ] );
		$html .= champ_select( $prefixe . '-resp-' . $e, 'responsables[' . $e . ']', __( 'Responsable', 'yume-core' ), $choix, (string) $uid );
		$html .= '</fieldset>';
	}
	$html .= '</div><div class="yn-team__champs yn-team__champs--bas">';
	$html .= champ_saisie( $prefixe . '-note', 'note_equipe', __( 'Note pour l’équipe (facultatif)', 'yume-core' ), (string) get_post_meta( $id, 'yume_note_equipe', true ), 'text', array( 'maxlength' => 2000 ), 'yn-team__note' );
	$html .= champ_blocage( $prefixe, $l );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn yn-btn--primary">' . esc_html__( 'Enregistrer', 'yume-core' ) . '</button></p>';
	$html .= '</div></form>' . liens_tome( $l, true ) . '</div></details></li>';
	return $html;
}

/**
 * Ligne du planning complet pour un membre qui ne gère pas tout le planning : formulaire de
 * ses étapes s'il est responsable du tome (comme « Mes tâches »), sinon lecture seule.
 *
 * @param array      $l      Ligne.
 * @param array|null $retour Retour sans JavaScript.
 * @param bool       $ouvert Ligne dépliée d'office.
 */
function ligne_membre_planning( array $l, ?array $retour, bool $ouvert = false ): string {
	$id    = (int) $l['tome_id'];
	$ancre = 'yn-tome-' . $id;
	$tache = taches( array( $l ), get_current_user_id() )[0] ?? null;
	$html  = '<li class="yn-team__ligne" data-yn-carte><details class="yn-team__details" id="' . esc_attr( $ancre ) . '"' . ( $retour || $ouvert ? ' open' : '' ) . '>';
	$html .= '<summary>' . resume_ligne( $l ) . pastille_ligne( $l, true, array( 'data-yn-puce' => '' ) ) . '</summary>';
	$html .= '<div class="yn-team__corps">';
	if ( $tache ) {
		$html .= '<form class="yn-team__gestion" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-planning="' . $id . '">';
		$html .= champs_caches_maj( $id, $ancre ) . champs_tache( $tache, 'g' . $id, $retour ) . '</form>';
	} else {
		$noms = array();
		foreach ( ETAPES_TRAVAIL as $e ) {
			$noms[] = yume_etapes()[ $e ] . ' : ' . pct( (int) $l['avancement'][ $e ] ) . ( '' !== $l['responsables'][ $e ]['nom'] ? ' · ' . $l['responsables'][ $e ]['nom'] : '' );
		}
		$html .= '<p class="yn-muted yn-team__lecture">' . esc_html( implode( ' — ', $noms ) ) . '</p>';
		$html .= '<p class="yn-muted">' . esc_html__( 'Vous n’êtes pas responsable de ce tome : demandez à un éditeur ou à un gérant pour le modifier.', 'yume-core' ) . '</p>';
	}
	return $html . liens_tome( $l ) . '</div></details></li>';
}

/**
 * Statuts WordPress des tomes dans la vue de gestion (filtre « Statut »).
 *
 * @return array<string,string>
 */
function statuts_gestion(): array {
	return array(
		'draft'   => __( 'Brouillon', 'yume-core' ),
		'pending' => __( 'En attente de relecture', 'yume-core' ),
		'future'  => __( 'Programmé', 'yume-core' ),
		'publish' => __( 'Publié', 'yume-core' ),
		'private' => __( 'Privé', 'yume-core' ),
	);
}

/**
 * Libellé du statut WordPress d'une ligne (« Brouillon », « Programmé le 3 oct. »…).
 *
 * @param array $l Ligne.
 */
function libelle_statut( array $l ): string {
	$statut = (string) ( $l['statut'] ?? get_post_status( (int) $l['tome_id'] ) );
	if ( 'future' === $statut ) {
		$post = get_post( (int) $l['tome_id'] );
		$ts   = $post ? ts_gmt( (string) $post->post_date_gmt ) : 0;
		/* translators: %s : date de sortie programmée */
		return $ts ? sprintf( __( 'Programmé le %s', 'yume-core' ), format_fr( $ts, 'j M H:i' ) ) : __( 'Programmé', 'yume-core' );
	}
	return statuts_gestion()[ $statut ] ?? '';
}

/**
 * Nom complet d'une ligne (« Grimgar · Tome 10 : Titre »).
 *
 * @param array $l Ligne.
 */
function nom_ligne( array $l ): string {
	return $l['oeuvre'] . ' · ' . $l['tome'] . ( '' !== $l['titre'] ? ' : ' . $l['titre'] : '' );
}

/**
 * L'utilisateur courant peut-il proposer « Retirer du planning » pour ce tome ? Éditeurs,
 * gérants et administrateurs (yume_maj_planning_tous + delete_post), tome brouillon ou en
 * attente ; le service refuse (avec explication) un tome dont un chapitre est publié.
 *
 * @param array $l Ligne.
 */
function peut_proposer_retrait( array $l ): bool {
	$id = (int) $l['tome_id'];
	return in_array( (string) ( $l['statut'] ?? get_post_status( $id ) ), array( 'draft', 'pending' ), true )
		&& current_user_can( 'yume_maj_planning_tous' ) && current_user_can( 'delete_post', $id );
}

/**
 * Raccourcis d'un tome (SCAN-07) : publier, modifier dans l'administration, voir la fiche,
 * historique ; et, si demandé, « Retirer du planning » (formulaire séparé : jamais à l'intérieur
 * d'un autre formulaire).
 *
 * @param array $l       Ligne.
 * @param bool  $retrait Proposer « Retirer du planning ».
 * @param bool  $gerer   Proposer « Gérer dans le planning complet ».
 */
function liens_tome( array $l, bool $retrait = false, bool $gerer = false ): string {
	$id     = (int) $l['tome_id'];
	$nom    = '<span class="yn-visually-hidden"> : ' . esc_html( nom_ligne( $l ) ) . '</span>';
	$statut = (string) ( $l['statut'] ?? get_post_status( $id ) );
	$liens  = array();
	if ( 'publish' !== $statut && current_user_can( 'yume_publier' ) && function_exists( 'yume_url_page' ) ) {
		$liens[] = '<a href="' . esc_url( add_query_arg( 'tome', $id, yume_url_page( 'publier' ) ) ) . '">' . esc_html__( 'Publier ce tome', 'yume-core' ) . $nom . '</a>';
	}
	if ( $gerer && current_user_can( 'yume_maj_planning_tous' ) ) {
		$liens[] = '<a href="' . esc_url( url_vue_equipe( 'planning', array( 'tome' => $id ) ) . '#yn-tome-' . $id ) . '">' . esc_html__( 'Gérer dans le planning complet', 'yume-core' ) . $nom . '</a>';
	}
	if ( current_user_can( 'edit_post', $id ) ) {
		$edition = (string) get_edit_post_link( $id, 'raw' );
		if ( '' !== $edition ) {
			$liens[] = '<a href="' . esc_url( $edition ) . '">' . esc_html__( 'Modifier dans l’administration', 'yume-core' ) . $nom . '</a>';
		}
	}
	if ( '' !== (string) ( $l['url'] ?? '' ) ) {
		$liens[] = '<a href="' . esc_url( $l['url'] ) . '">' . esc_html__( 'Voir la fiche', 'yume-core' ) . $nom . '</a>';
	}
	$liens[] = '<a href="' . esc_url( url_vue_equipe( 'journal', array( 'tome' => $id ) ) ) . '">' . esc_html__( 'Historique', 'yume-core' ) . $nom . '</a>';
	$html    = '<div class="yn-team__liens"><ul class="yn-team__raccourcis"><li>' . implode( '</li><li>', $liens ) . '</li></ul>';
	if ( $retrait && peut_proposer_pause( $l ) ) {
		$html .= formulaire_pause( $l );
	}
	if ( $retrait && peut_proposer_retrait( $l ) ) {
		$html .= formulaire_retrait( $l );
	}
	return $html . '</div>';
}

/**
 * L'utilisateur courant peut-il proposer « Mettre en pause » ou « Reprendre » pour ce tome ?
 * Éditeurs, gérants et administrateurs (yume_maj_planning_tous) ; un tome publié n'a rien à
 * mettre en pause (mais un tome publié resté en pause peut être repris).
 *
 * @param array $l Ligne.
 */
function peut_proposer_pause( array $l ): bool {
	if ( ! current_user_can( 'yume_maj_planning_tous' ) ) {
		return false;
	}
	return 'publie' !== $l['etat'] || est_en_pause( (int) $l['tome_id'] );
}

/**
 * Formulaire « Mettre en pause » / « Reprendre » d'un tome (admin-post.php, action
 * yume_planning_pause).
 *
 * @param array $l Ligne.
 */
function formulaire_pause( array $l ): string {
	$id    = (int) $l['tome_id'];
	$pause = est_en_pause( $id );
	$html  = '<form class="yn-team__pause-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$html .= '<input type="hidden" name="action" value="yume_planning_pause"><input type="hidden" name="tome_id" value="' . $id . '">';
	$html .= '<input type="hidden" name="pause" value="' . ( $pause ? '0' : '1' ) . '">';
	$html .= wp_nonce_field( 'yume_planning_pause_' . $id, '_yume_nonce', true, false );
	$html .= '<button type="submit" class="yn-btn yn-btn--sm">' . esc_html( $pause ? __( 'Reprendre', 'yume-core' ) : __( 'Mettre en pause', 'yume-core' ) ) . '<span class="yn-visually-hidden"> : ' . esc_html( nom_ligne( $l ) ) . '</span></button>';
	if ( ! $pause ) {
		$html .= ' <span class="yn-muted yn-team__pause-aide">' . esc_html__( 'Ni retard ni rappel tant que le tome est en pause.', 'yume-core' ) . '</span>';
	}
	return $html . '</form>';
}

/**
 * Formulaire « Retirer du planning » d'un tome (admin-post.php, action yume_planning_retrait).
 *
 * @param array $l Ligne.
 */
function formulaire_retrait( array $l ): string {
	$id   = (int) $l['tome_id'];
	$html = '<form class="yn-team__retrait" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-yn-confirmer="' . esc_attr(
		sprintf(
			/* translators: %s : tome */
			__( 'Retirer %s du planning ? Le brouillon du tome part à la corbeille (récupérable depuis l’administration).', 'yume-core' ),
			nom_ligne( $l )
		)
	) . '">';
	$html .= '<input type="hidden" name="action" value="yume_planning_retrait"><input type="hidden" name="tome_id" value="' . $id . '">';
	$html .= wp_nonce_field( 'yume_planning_retrait_' . $id, '_yume_nonce', true, false );
	$html .= '<button type="submit" class="yn-btn yn-btn--sm yn-team__retirer">' . esc_html__( 'Retirer du planning', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( nom_ligne( $l ) ) . '</span></button>';
	return $html . '</form>';
}

/*
 * -----------------------------------------------------------------------------
 * Rendu
 * -----------------------------------------------------------------------------
 */

/**
 * Carte d'un chiffre de l'espace équipe.
 *
 * @param string $label    Surtitre.
 * @param string $valeur   Valeur.
 * @param string $detail   Détail.
 * @param bool   $alerte   Mise en avant (retards).
 */
function chiffre_equipe( string $label, string $valeur, string $detail, bool $alerte = false ): string {
	$html  = '<div class="yn-card yn-team__chiffre' . ( $alerte ? ' yn-team__chiffre--alerte' : '' ) . '">';
	$html .= '<p class="yn-label">' . esc_html( $label ) . '</p><p class="yn-team__valeur">' . esc_html( $valeur ) . '</p>';
	return $html . '<p class="yn-muted">' . esc_html( $detail ) . '</p></div>';
}

/**
 * Message d'accès (non connecté ou hors équipe).
 *
 * @param string $titre   Titre.
 * @param string $message Message.
 * @param string $lien    HTML du lien.
 */
function acces_equipe( string $titre, string $message, string $lien ): string {
	$html  = '<div ' . attributs_racine( 'yn-team yn-team--acces' ) . '><div class="yn-card yn-team__acces">';
	$html .= '<h2>' . esc_html( $titre ) . '</h2><p>' . esc_html( $message ) . '</p>';
	return $html . ( '' !== $lien ? '<p>' . $lien . '</p>' : '' ) . '</div></div>';
}

/**
 * Nom du rôle principal d'un utilisateur (« Gérant », « Traducteur »…).
 *
 * @param \WP_User $user Utilisateur.
 */
function nom_role( \WP_User $user ): string {
	$roles = wp_roles()->roles;
	foreach ( (array) $user->roles as $role ) {
		if ( 'administrator' === $role ) {
			// Libellé court : le paquet de langue récent dit « Administrateur/administratrice ».
			return __( 'Administrateur', 'yume-core' );
		}
		if ( isset( $roles[ $role ]['name'] ) ) {
			return translate_user_role( $roles[ $role ]['name'] );
		}
	}
	return '';
}

/**
 * Adresse d'une vue de l'espace équipe (paramètre « vue » de la page équipe, sans nouvelle page) :
 * 'planning' (gestion de tout le planning), 'journal' (tout le journal), 'reglages' (réglages
 * du site), 'lecture' (lecture en ligne à compléter), 'tomes' (tous les tomes, publiés compris)
 * 'taches' (mes tâches) ou 'oeuvres' (œuvres et nouvelle œuvre) ; '' : tableau de bord.
 *
 * @param string $vue  Vue.
 * @param array  $args Paramètres supplémentaires (valeurs vides ignorées).
 */
function url_vue_equipe( string $vue = '', array $args = array() ): string {
	$base = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
	$args = array_filter(
		array_merge( array( 'vue' => $vue ), $args ),
		static function ( $v ): bool {
			return '' !== (string) $v && 0 !== $v;
		}
	);
	return $args ? add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $base ) : $base;
}

/**
 * Vues ajoutées à l'espace équipe par les autres fichiers (filtre yume_vues_equipe), limitées à
 * celles que le compte courant peut voir : clé => array{libelle: string, capacite: string,
 * groupe: string, rendu: callable(): string}. Chaque vue a son entrée dans le menu « groupe »
 * de la navigation (groupes_navigation_equipe() ; « site » par défaut, avant « Réglages ») et
 * s'affiche par ?vue=<clé>.
 *
 * @return array<string,array{libelle:string,capacite:string,groupe:string,rendu:callable}>
 */
function vues_equipe_ajoutees(): array {
	/**
	 * Filtre les vues ajoutées à l'espace équipe.
	 *
	 * @param array $vues clé (sanitize_key) => array{libelle, capacite, rendu}.
	 */
	$vues   = (array) apply_filters( 'yume_vues_equipe', array() );
	$retenu = array();
	foreach ( $vues as $cle => $vue ) {
		$cle = sanitize_key( (string) $cle );
		if ( '' === $cle || in_array( $cle, array( 'planning', 'journal', 'reglages', 'lecture', 'tomes', 'taches', 'oeuvres' ), true ) || ! is_array( $vue ) ) {
			continue;
		}
		if ( ! isset( $vue['libelle'], $vue['rendu'] ) || ! is_callable( $vue['rendu'] ) ) {
			continue;
		}
		$capacite = (string) ( $vue['capacite'] ?? 'yume_voir_equipe' );
		if ( current_user_can( $capacite ) ) {
			$groupe         = sanitize_key( (string) ( $vue['groupe'] ?? 'site' ) );
			$retenu[ $cle ] = array(
				'libelle'  => (string) $vue['libelle'],
				'capacite' => $capacite,
				'groupe'   => isset( groupes_navigation_equipe()[ $groupe ] ) ? $groupe : 'site',
				'rendu'    => $vue['rendu'],
			);
		}
	}
	return $retenu;
}

/**
 * Vue demandée de l'espace équipe (paramètre GET « vue ») : 'planning', 'journal', 'reglages',
 * 'lecture', 'tomes', 'taches', 'oeuvres', une vue ajoutée (vues_equipe_ajoutees()) ou ''.
 */
function vue_equipe(): string {
	if ( est_apercu_editeur() ) {
		return '';
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix d'affichage en lecture seule.
	$vue = isset( $_GET['vue'] ) && is_string( $_GET['vue'] ) ? sanitize_key( wp_unslash( $_GET['vue'] ) ) : '';
	return in_array( $vue, array( 'planning', 'journal', 'reglages', 'lecture', 'tomes', 'taches', 'oeuvres' ), true ) || isset( vues_equipe_ajoutees()[ $vue ] ) ? $vue : '';
}

/**
 * Menus de la navigation de l'espace équipe : clé => libellé, dans l'ordre d'affichage.
 *
 * @return array<string,string>
 */
function groupes_navigation_equipe(): array {
	return array(
		'catalogue' => __( 'Catalogue', 'yume-core' ),
		'planning'  => __( 'Planning', 'yume-core' ),
		'equipe'    => __( 'Équipe', 'yume-core' ),
		'site'      => __( 'Site', 'yume-core' ),
	);
}

/**
 * Entrées de la navigation de l'espace équipe visibles pour le compte courant, par menu :
 * groupe => liste de array{cle, libelle, url}.
 *
 * @return array<string,array<int,array{cle:string,libelle:string,url:string}>>
 */
function entrees_navigation_equipe(): array {
	$groupes = array_fill_keys( array_keys( groupes_navigation_equipe() ), array() );
	$ajouter = static function ( string $groupe, string $cle, string $libelle, string $url ) use ( &$groupes ): void {
		$groupes[ $groupe ][] = array(
			'cle'     => $cle,
			'libelle' => $libelle,
			'url'     => $url,
		);
	};
	if ( current_user_can( CAPACITE_OEUVRES ) ) {
		$ajouter( 'catalogue', 'oeuvres', __( 'Œuvres', 'yume-core' ), url_vue_equipe( 'oeuvres' ) );
	}
	if ( current_user_can( 'yume_publier' ) ) {
		// Tout le catalogue, publiés compris (la section du tableau de bord ne liste que les
		// tomes en préparation) : retrouver un tome paru pour remplacer sa lecture en ligne.
		$ajouter( 'catalogue', 'tomes', __( 'Tous les tomes', 'yume-core' ), url_vue_equipe( 'tomes' ) );
		$ajouter( 'catalogue', 'publier', __( 'Ajouter des chapitres', 'yume-core' ), yume_url_page( 'publier' ) );
		$ajouter( 'catalogue', 'lecture', __( 'Lecture à compléter', 'yume-core' ), url_vue_equipe( 'lecture' ) );
	}
	// Vue de gestion de tout le planning (le planning public reste accessible par le bouton
	// « Voir le planning public »).
	$ajouter( 'planning', 'planning', __( 'Planning complet', 'yume-core' ), url_vue_equipe( 'planning' ) );
	$ajouter( 'planning', 'journal', __( 'Journal', 'yume-core' ), url_vue_equipe( 'journal' ) );
	$membres = url_membres();
	if ( '' !== $membres ) {
		$ajouter( 'equipe', 'membres', __( 'Membres et rôles', 'yume-core' ), $membres );
	}
	foreach ( vues_equipe_ajoutees() as $cle => $ajoutee ) {
		$ajouter( $ajoutee['groupe'], $cle, $ajoutee['libelle'], url_vue_equipe( $cle ) );
	}
	if ( current_user_can( 'yume_reglages' ) ) {
		$ajouter( 'site', 'reglages', __( 'Réglages', 'yume-core' ), url_vue_equipe( 'reglages' ) );
	}
	return array_filter( $groupes );
}

/**
 * Navigation latérale de l'espace équipe, partagée par le tableau de bord (yume/team-dashboard),
 * toutes ses vues, la page « Membres et rôles » (yume/team-members) et le formulaire de
 * publication : mêmes entrées, même ordre et mêmes cibles (WCAG 3.2.3).
 *
 * « Tableau de bord » et « Mes tâches » en tête, puis des menus repliables (<details>, sans
 * JavaScript) : Catalogue, Planning, Équipe, Site (groupes_navigation_equipe()). Le menu de la
 * page affichée est ouvert. Sur le tableau de bord, son entrée est une ancre ; « Mes tâches »
 * mène toujours à la vue ?vue=taches.
 *
 * @param string $actif   Page affichée : 'tableau', 'planning' (gestion du planning), 'journal',
 *                        'publier', 'lecture' (lecture en ligne à compléter), 'tomes' (tous les tomes),
 *                        'taches' (mes tâches), 'oeuvres' (œuvres), 'membres', 'reglages' ou la clé
 *                        d'une vue ajoutée.
 * @param int    $retards Nombre de mes retards (pastille de « Mes tâches »).
 */
function navigation_equipe( string $actif, int $retards = 0 ): string {
	$user    = wp_get_current_user();
	$tableau = 'tableau' === $actif;
	$equipe  = $tableau ? '' : url_vue_equipe();
	$courant = static function ( string $cle ) use ( $actif ): string {
		return $cle === $actif ? ' aria-current="page"' : '';
	};
	$html    = '<nav class="yn-team__nav" aria-label="' . esc_attr__( 'Espace équipe', 'yume-core' ) . '"><ul class="yn-team__menu">';
	$html   .= '<li><a href="' . esc_url( $tableau ? '#yn-team' : $equipe ) . '"' . ( $tableau ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'Tableau de bord', 'yume-core' ) . '</a></li>';
	$html   .= '<li><a href="' . esc_url( url_vue_equipe( 'taches' ) ) . '"' . $courant( 'taches' ) . '>' . esc_html__( 'Mes tâches', 'yume-core' );
	if ( $retards ) {
		/* translators: %d : retards */
		$html .= ' <span class="yn-chip yn-chip--warn"><span aria-hidden="true">' . $retards . '</span><span class="yn-visually-hidden">' . esc_html( sprintf( _n( '%d en retard', '%d en retard', $retards, 'yume-core' ), $retards ) ) . '</span></span>';
	}
	$html    .= '</a></li>';
	$libelles = groupes_navigation_equipe();
	foreach ( entrees_navigation_equipe() as $groupe => $entrees ) {
		$ouvert = in_array( $actif, wp_list_pluck( $entrees, 'cle' ), true );
		$html  .= '<li class="yn-team__groupe"><details' . ( $ouvert ? ' open' : '' ) . '><summary>' . esc_html( $libelles[ $groupe ] ) . '</summary><ul>';
		foreach ( $entrees as $entree ) {
			$html .= '<li><a href="' . esc_url( $entree['url'] ) . '"' . $courant( $entree['cle'] ) . '>' . esc_html( $entree['libelle'] ) . '</a></li>';
		}
		$html .= '</ul></details></li>';
	}
	$html .= '</ul><div class="yn-team__moi"><span class="yn-team__avatar" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( (string) $user->display_name, 0, 1 ) ) ) . '</span>';
	$html .= '<span><span class="yn-team__nom">' . esc_html( $user->display_name ) . '</span><span class="yn-label">' . esc_html( nom_role( $user ) ) . '</span></span></div>';
	$html .= '<p class="yn-team__sortie"><a class="yn-team__deconnexion" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Se déconnecter', 'yume-core' ) . '</a></p></nav>';
	return $html;
}

/**
 * Ouverture de la racine de l'espace équipe : adresse REST, nonce et messages pour view.js ;
 * inerte dans l'aperçu de l'éditeur.
 *
 * @param string $classe Classes.
 */
function ouvrir_racine( string $classe ): string {
	$racine = array(
		'id'                  => 'yn-team',
		'data-yn-rest'        => esc_url_raw( rest_url() ),
		'data-yn-nonce'       => wp_create_nonce( 'wp_rest' ),
		'data-yn-msg-envoi'   => __( 'Enregistrement…', 'yume-core' ),
		'data-yn-msg-erreur'  => __( 'L’enregistrement a échoué. Réessayez.', 'yume-core' ),
		'data-yn-msg-session' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		'data-yn-msg-reseau'  => __( 'Connexion impossible : vérifiez votre réseau puis réessayez.', 'yume-core' ),
	);
	if ( est_apercu_editeur() ) {
		// Aperçu de l'éditeur : formulaires visibles mais inactifs.
		$racine['inert'] = '';
	}
	return '<div ' . attributs_racine( $classe, $racine ) . '>';
}

/**
 * En-tête d'une vue de l'espace équipe : surtitre, titre et boutons.
 *
 * @param string $titre   Titre.
 * @param string $boutons HTML des boutons.
 */
function tete_vue( string $titre, string $boutons ): string {
	$html  = '<div class="yn-team__tete"><div><p class="yn-label">' . esc_html__( 'Espace équipe', 'yume-core' ) . '</p>';
	$html .= '<h2 class="yn-team__bonjour">' . esc_html( $titre ) . '</h2></div>';
	return $html . ( '' !== $boutons ? '<p class="yn-team__boutons">' . $boutons . '</p>' : '' ) . '</div>';
}

/**
 * Champs cachés qui reportent la requête de l'adresse de la page équipe (permaliens simples :
 * ?page_id=…) dans un formulaire GET de filtres.
 *
 * @param string $vue Vue.
 */
function champs_caches_vue( string $vue ): string {
	$html  = '';
	$query = (string) wp_parse_url( url_vue_equipe(), PHP_URL_QUERY );
	parse_str( $query, $args );
	foreach ( $args as $nom => $valeur ) {
		if ( is_scalar( $valeur ) && 'vue' !== $nom ) {
			$html .= '<input type="hidden" name="' . esc_attr( (string) $nom ) . '" value="' . esc_attr( (string) $valeur ) . '">';
		}
	}
	return $html . '<input type="hidden" name="vue" value="' . esc_attr( $vue ) . '">';
}

/**
 * Pagination d'une vue (« Précédent » / « Suivant »).
 *
 * @param string $vue     Vue.
 * @param array  $args    Filtres courants.
 * @param int    $page    Page courante (1…).
 * @param bool   $suivant Une page suivante existe.
 * @param int    $total   Nombre de pages (0 : inconnu).
 */
function pagination_vue( string $vue, array $args, int $page, bool $suivant, int $total = 0 ): string {
	if ( $page <= 1 && ! $suivant ) {
		return '';
	}
	$html = '<nav class="yn-team__pages" aria-label="' . esc_attr__( 'Pages', 'yume-core' ) . '">';
	if ( $page > 1 ) {
		$html .= '<a class="yn-btn yn-btn--sm" rel="prev" href="' . esc_url( url_vue_equipe( $vue, array_merge( $args, array( 'pg' => $page > 2 ? $page - 1 : '' ) ) ) ) . '">' . esc_html__( '← Précédent', 'yume-core' ) . '</a>';
	}
	$html .= '<span class="yn-muted">' . esc_html(
		$total
			/* translators: 1: page, 2: nombre de pages */
			? sprintf( __( 'Page %1$d sur %2$d', 'yume-core' ), $page, $total )
			/* translators: %d : page */
			: sprintf( __( 'Page %d', 'yume-core' ), $page )
	) . '</span>';
	if ( $suivant ) {
		$html .= '<a class="yn-btn yn-btn--sm" rel="next" href="' . esc_url( url_vue_equipe( $vue, array_merge( $args, array( 'pg' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Suivant →', 'yume-core' ) . '</a>';
	}
	return $html . '</nav>';
}

/**
 * Entier positif d'un paramètre GET.
 *
 * @param string $cle Clé.
 */
function get_entier( string $cle ): int {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtres d'affichage en lecture seule.
	return isset( $_GET[ $cle ] ) && is_scalar( $_GET[ $cle ] ) ? absint( $_GET[ $cle ] ) : 0;
}

/**
 * Clé d'un paramètre GET.
 *
 * @param string $cle Clé.
 */
function get_cle( string $cle ): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtres d'affichage en lecture seule.
	return isset( $_GET[ $cle ] ) && is_string( $_GET[ $cle ] ) ? sanitize_key( wp_unslash( $_GET[ $cle ] ) ) : '';
}

/**
 * Œuvres proposées dans les filtres et formulaires (tous statuts vivants), par titre.
 *
 * @param string $vide Libellé de l'option vide.
 * @return array<string,string>
 */
function choix_oeuvres( string $vide ): array {
	$oeuvres = array( '' => $vide );
	foreach (
		get_posts(
			array(
				'post_type'        => 'yume_oeuvre',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		) as $o
	) {
		$oeuvres[ (string) $o->ID ] = titre_brut( (int) $o->ID ) . ( 'publish' !== $o->post_status ? ' ' . __( '(brouillon)', 'yume-core' ) : '' );
	}
	return $oeuvres;
}

/**
 * Filtres de la vue « Planning complet » (GET), validés.
 *
 * @return array{oeuvre:int,etat:string,statut:string,responsable:int,tome:int,tri:string,pg:int}
 */
function filtres_gestion(): array {
	$oeuvre = get_entier( 'oeuvre' );
	$etat   = get_cle( 'etat' );
	$statut = get_cle( 'statut' );
	$tome   = get_entier( 'tome' );
	$tri    = get_cle( 'tri' );
	return array(
		'oeuvre'      => $oeuvre && 'yume_oeuvre' === get_post_type( $oeuvre ) ? $oeuvre : 0,
		'etat'        => array_key_exists( $etat, etats() ) ? $etat : '',
		'statut'      => array_key_exists( $statut, statuts_gestion() ) ? $statut : '',
		'responsable' => get_entier( 'responsable' ),
		'tome'        => $tome && 'yume_tome' === get_post_type( $tome ) ? $tome : 0,
		'tri'         => array_key_exists( $tri, tris_gestion() ) ? $tri : '',
		'pg'          => max( 1, get_entier( 'pg' ) ),
	);
}

/**
 * Tris proposés par le planning complet (filtre « Tri »).
 *
 * @return array<string,string>
 */
function tris_gestion(): array {
	return array(
		''       => __( 'Ordre du planning', 'yume-core' ),
		'retard' => __( 'En retard d’abord', 'yume-core' ),
	);
}

/**
 * Lignes du planning complet selon les filtres (vue ?vue=planning et export CSV) : tous les
 * tomes vivants, filtrés, dans l'ordre du planning ou « en retard d'abord » (retards du plus
 * ancien au plus récent, puis tomes bloqués, puis le reste dans l'ordre du planning).
 *
 * @param array $f Filtres (filtres_gestion()).
 * @return array<int,array<string,mixed>>
 */
function lignes_vue_planning( array $f ): array {
	$lignes = yume_get_planning(
		array(
			'gestion'     => true,
			'public'      => false,
			'oeuvre_id'   => (int) ( $f['oeuvre'] ?? 0 ),
			'etat'        => (string) ( $f['etat'] ?? '' ),
			'statut'      => (string) ( $f['statut'] ?? '' ),
			'responsable' => (int) ( $f['responsable'] ?? 0 ),
		)
	);
	$lignes = array_values(
		array_filter(
			$lignes,
			static function ( array $l ) use ( $f ): bool {
				return ( empty( $f['tome'] ) || (int) $l['tome_id'] === (int) $f['tome'] )
					&& ( empty( $f['statut'] ) || ( $l['statut'] ?? '' ) === $f['statut'] );
			}
		)
	);
	if ( 'retard' === ( $f['tri'] ?? '' ) ) {
		$rang = static function ( array $l ): int {
			return 'en_retard' === $l['etat'] ? 0 : ( 'bloque' === $l['etat'] ? 1 : 2 );
		};
		usort(
			$lignes,
			static function ( array $a, array $b ) use ( $rang ): int {
				$ra = $rang( $a );
				$rb = $rang( $b );
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				if ( 0 === $ra && (int) $a['jours_retard'] !== (int) $b['jours_retard'] ) {
					return (int) $b['jours_retard'] <=> (int) $a['jours_retard'];
				}
				return comparer_lignes( $a, $b );
			}
		);
	}
	return $lignes;
}

/**
 * Adresse de l'export CSV du planning complet avec les filtres courants (admin-post.php,
 * action yume_planning_export, nonce).
 *
 * @param array $f Filtres.
 */
function url_export_planning( array $f ): string {
	$args = array_filter( array_intersect_key( $f, array_flip( array( 'oeuvre', 'etat', 'statut', 'responsable', 'tome', 'tri' ) ) ) );
	$args = array_merge(
		array( 'action' => 'yume_planning_export' ),
		array_map( 'strval', $args ),
		array( '_wpnonce' => wp_create_nonce( 'yume_planning_export' ) )
	);
	return add_query_arg( array_map( 'rawurlencode', $args ), admin_url( 'admin-post.php' ) );
}

/**
 * Cellule CSV neutralisée contre l'injection de formules (tableurs) : une valeur qui commence
 * par = + - @, une tabulation ou un retour chariot est préfixée d'une apostrophe.
 *
 * @param mixed $valeur Valeur.
 */
function cellule_csv( $valeur ): string {
	$texte = is_scalar( $valeur ) ? (string) $valeur : '';
	return '' !== $texte && false !== strpos( "=+-@\t\r", $texte[0] ) ? "'" . $texte : $texte;
}

/**
 * Colonne « Statut » de l'export : état du planning, ou « Œuvre en pause » (« Œuvre
 * terminée »…) pour un tome pas encore publié d'une œuvre sans rappels.
 *
 * @param array $l Ligne.
 */
function etat_csv( array $l ): string {
	$oeuvre = 'publie' !== $l['etat'] ? libelle_oeuvre_sans_rappels( (int) ( $l['oeuvre_id'] ?? 0 ) ) : '';
	return '' !== $oeuvre ? $oeuvre : ( etats()[ $l['etat'] ] ?? '' );
}

/**
 * Export CSV du planning complet (UTF-8 avec BOM pour Excel, séparateur « ; ») : œuvre, tome,
 * étape, statut, responsables, date cible, date programmée, dernière mise à jour, retard.
 *
 * @param array $lignes Lignes (lignes_vue_planning()).
 */
function csv_planning( array $lignes ): string {
	$flux = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $flux ) {
		return '';
	}
	$entetes = array(
		__( 'Œuvre', 'yume-core' ),
		__( 'Tome', 'yume-core' ),
		__( 'Étape', 'yume-core' ),
		__( 'Statut', 'yume-core' ),
		__( 'Responsables', 'yume-core' ),
		__( 'Date cible', 'yume-core' ),
		__( 'Date programmée', 'yume-core' ),
		__( 'Dernière mise à jour', 'yume-core' ),
		__( 'Retard', 'yume-core' ),
	);
	fputcsv( $flux, array_map( __NAMESPACE__ . '\\cellule_csv', $entetes ), ';', '"', '' );
	$etapes = yume_etapes();
	foreach ( $lignes as $l ) {
		$resp = array();
		foreach ( ETAPES_TRAVAIL as $e ) {
			if ( '' !== (string) $l['responsables'][ $e ]['nom'] ) {
				$resp[] = $etapes[ $e ] . ' : ' . $l['responsables'][ $e ]['nom'];
			}
		}
		$maj    = ts_gmt( (string) $l['derniere_maj'] );
		$retard = '';
		if ( 'en_retard' === $l['etat'] ) {
			$retard = sprintf(
				/* translators: 1: jours, 2: motif */
				__( '%1$d j (%2$s)', 'yume-core' ),
				(int) $l['jours_retard'],
				'date' === $l['motif_retard'] ? __( 'date cible dépassée', 'yume-core' ) : __( 'sans mise à jour', 'yume-core' )
			);
		}
		$ligne = array(
			$l['oeuvre'],
			$l['tome'] . ( '' !== $l['titre'] ? ' : ' . $l['titre'] : '' ),
			$etapes[ $l['etape'] ] ?? $l['etape'],
			est_en_pause( (int) $l['tome_id'] ) ? __( 'En pause', 'yume-core' ) : etat_csv( $l ),
			implode( ' · ', $resp ),
			(string) $l['date_cible'],
			(string) ( $l['date_programmee'] ?? '' ),
			$maj ? ( new \DateTimeImmutable( '@' . $maj ) )->setTimezone( fuseau() )->format( 'Y-m-d H:i' ) : '',
			$retard,
		);
		fputcsv( $flux, array_map( __NAMESPACE__ . '\\cellule_csv', $ligne ), ';', '"', '' );
	}
	rewind( $flux );
	$csv = (string) stream_get_contents( $flux );
	fclose( $flux ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return "\xEF\xBB\xBF" . $csv;
}

/** Nombre de tomes par page du planning complet. */
const TOMES_PAR_PAGE = 25;

/**
 * Vue « Planning complet » (?vue=planning) : tous les tomes vivants (brouillons, programmés,
 * publiés…), filtres œuvre / état / statut / responsable, formulaire par ligne, raccourcis
 * (publier, administration, fiche, historique) et « Retirer du planning ».
 */
function rendu_vue_planning(): string {
	$uid      = get_current_user_id();
	$tous     = current_user_can( 'yume_maj_planning_tous' );
	$f        = filtres_gestion();
	$retour   = retour_formulaire( $uid );
	$membres  = membres_equipe();
	$lignes   = lignes_vue_planning( $f );
	$total    = count( $lignes );
	$pages    = max( 1, (int) ceil( $total / TOMES_PAR_PAGE ) );
	$page     = min( $f['pg'], $pages );
	$visibles = array_slice( $lignes, ( $page - 1 ) * TOMES_PAR_PAGE, TOMES_PAR_PAGE );
	$pour     = static function ( string $cible ) use ( $retour ): ?array {
		return $retour && ( $retour['cible'] ?? '' ) === $cible ? $retour : null;
	};

	$html  = ouvrir_racine( 'yn-team yn-team--vue' );
	$html .= navigation_equipe( 'planning' );
	$html .= '<div class="yn-team__principal">';

	$boutons = '<a class="yn-btn" href="' . esc_url( yume_url_page( 'planning' ) ) . '">' . esc_html__( 'Voir le planning public', 'yume-core' ) . '</a>';
	if ( $tous ) {
		$boutons .= '<a class="yn-btn" href="' . esc_url( url_nouveau_tome( $f['oeuvre'], 'planning' ) ) . '">' . esc_html__( 'Ajouter un tome au planning', 'yume-core' ) . '</a>';
	}
	if ( current_user_can( 'yume_publier' ) ) {
		$boutons .= '<a class="yn-btn yn-btn--primary" href="' . esc_url( yume_url_page( 'publier' ) ) . '"><span aria-hidden="true">+</span> ' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . '</a>';
	}
	$html .= tete_vue( __( 'Planning complet', 'yume-core' ), $boutons );
	$html .= '<p class="yn-muted">' . esc_html(
		$tous
			? __( 'Tous les tomes du planning, y compris programmés et publiés. Dépliez une ligne pour modifier son étape, ses avancements, ses responsables, sa date, son blocage ou sa note.', 'yume-core' )
			: __( 'Tous les tomes du planning. Vous pouvez modifier ceux dont vous êtes responsable.', 'yume-core' )
	) . '</p>';
	// Retour d'une action dont la ligne n'existe plus (tome retiré du planning).
	$html .= '<div id="yn-gestion-retour">' . zone_retour( $pour( 'yn-gestion-retour' ) ) . '</div>';

	// Filtres (GET).
	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Filtrer le planning', 'yume-core' ) . '">' . champs_caches_vue( 'planning' );
	$html .= champ_select( 'yn-f-oeuvre', 'oeuvre', __( 'Œuvre', 'yume-core' ), choix_oeuvres( __( 'Toutes', 'yume-core' ) ), ( $f['oeuvre'] ? (string) $f['oeuvre'] : '' ) );
	$html .= champ_select( 'yn-f-etat', 'etat', __( 'État', 'yume-core' ), array( '' => __( 'Tous', 'yume-core' ) ) + etats(), $f['etat'] );
	$html .= champ_select( 'yn-f-statut', 'statut', __( 'Statut', 'yume-core' ), array( '' => __( 'Tous', 'yume-core' ) ) + statuts_gestion(), $f['statut'] );
	$resp  = array( '' => __( 'Tous', 'yume-core' ) ) + $membres;
	if ( $f['responsable'] && ! isset( $resp[ $f['responsable'] ] ) ) {
		$resp[ $f['responsable'] ] = nom_utilisateur( $f['responsable'] );
	}
	$html .= champ_select( 'yn-f-resp', 'responsable', __( 'Responsable', 'yume-core' ), $resp, ( $f['responsable'] ? (string) $f['responsable'] : '' ) );
	$html .= champ_select( 'yn-f-tri', 'tri', __( 'Tri', 'yume-core' ), tris_gestion(), $f['tri'] );
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button></p>';
	$html .= '</form>';

	$actifs = array_filter( array_intersect_key( $f, array_flip( array( 'oeuvre', 'etat', 'statut', 'responsable', 'tome', 'tri' ) ) ) );
	$html  .= '<section class="yn-team__section" id="yn-planning-complet" aria-labelledby="yn-planning-complet-titre"><div class="yn-team__section-tete">';
	/* translators: %d : nombre de tomes */
	$html .= '<h2 id="yn-planning-complet-titre">' . esc_html( sprintf( _n( '%d tome', '%d tomes', $total, 'yume-core' ), $total ) ) . '</h2>';
	$html .= '<p class="yn-team__section-liens">';
	// Le tri seul ne masque aucun tome : pas de « Afficher tout le planning ».
	if ( array_diff_key( $actifs, array( 'tri' => '' ) ) ) {
		$html .= '<a href="' . esc_url( url_vue_equipe( 'planning' ) ) . '">' . esc_html__( 'Afficher tout le planning', 'yume-core' ) . '</a>';
	}
	if ( $total ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_export_planning( $f ) ) . '" download>' . esc_html__( 'Exporter en CSV', 'yume-core' ) . '<span class="yn-visually-hidden"> ' . esc_html__( '(tomes filtrés, toutes les pages)', 'yume-core' ) . '</span></a>';
	}
	$html .= '</p></div>';
	if ( $visibles ) {
		$html .= '<ul class="yn-card yn-team__liste">';
		foreach ( $visibles as $l ) {
			$ouvert = $f['tome'] === (int) $l['tome_id'];
			$html  .= $tous
				? ligne_gestion( $l, $membres, $pour( 'yn-tome-' . $l['tome_id'] ), $ouvert )
				: ligne_membre_planning( $l, $pour( 'yn-tome-' . $l['tome_id'] ), $ouvert );
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html__( 'Aucun tome ne correspond à ces filtres.', 'yume-core' ) . '</p>';
	}
	$html .= pagination_vue( 'planning', $actifs, $page, $page < $pages, $pages );
	$html .= '</section>';

	return $html . '</div></div>';
}

/**
 * Tomes présents dans le journal (filtre « Tome » de la vue « Journal »), par libellé.
 *
 * @return array<string,string>
 */
function tomes_du_journal(): array {
	global $wpdb;
	$table = table_journal();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$ids   = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT tome_id FROM {$table} WHERE tome_id > 0" ) );
	$tomes = array();
	foreach ( $ids as $id ) {
		$tomes[ (string) $id ] = cible_journal( $id );
	}
	asort( $tomes, SORT_NATURAL | SORT_FLAG_CASE );
	return $tomes;
}

/** Lignes brutes du journal lues par page (une mise à jour regroupe plusieurs lignes). */
const JOURNAL_PAR_PAGE = 60;

/**
 * Vue « Journal » (?vue=journal) : tout le journal de l'équipe, paginé, filtrable par œuvre et
 * par tome, rappels automatiques en option.
 */
function rendu_vue_journal(): string {
	$tome    = get_entier( 'tome' );
	$oeuvre  = get_entier( 'oeuvre' );
	$rappels = 1 === get_entier( 'rappels' );
	$page    = max( 1, get_entier( 'pg' ) );
	$tomes   = tomes_du_journal();
	$tome    = isset( $tomes[ (string) $tome ] ) ? $tome : 0;
	$oeuvre  = $oeuvre && 'yume_oeuvre' === get_post_type( $oeuvre ) ? $oeuvre : 0;
	$args    = array(
		'tome_id'   => $tome,
		'oeuvre_id' => $oeuvre,
		'limit'     => JOURNAL_PAR_PAGE + 1,
		'offset'    => ( $page - 1 ) * JOURNAL_PAR_PAGE,
	);
	if ( ! $rappels ) {
		$args['exclure'] = array( 'rappel', 'signalement', 'digest' );
	}
	$brut    = lire_journal( $args );
	$suivant = count( $brut ) > JOURNAL_PAR_PAGE;
	$entrees = grouper_journal( array_slice( $brut, 0, JOURNAL_PAR_PAGE ), true );
	$filtres = array_filter(
		array(
			'tome'    => $tome,
			'oeuvre'  => $oeuvre,
			'rappels' => $rappels ? 1 : 0,
		)
	);

	$html  = ouvrir_racine( 'yn-team yn-team--vue' );
	$html .= navigation_equipe( 'journal' );
	$html .= '<div class="yn-team__principal">';
	$html .= tete_vue( __( 'Journal de l’équipe', 'yume-core' ), '<a class="yn-btn" href="' . esc_url( url_vue_equipe( 'planning' ) ) . '">' . esc_html__( 'Planning complet', 'yume-core' ) . '</a>' );

	$html .= '<form class="yn-card yn-team__filtres" method="get" action="' . esc_url( strtok( url_vue_equipe(), '?' ) ) . '" aria-label="' . esc_attr__( 'Filtrer le journal', 'yume-core' ) . '">' . champs_caches_vue( 'journal' );
	$html .= champ_select( 'yn-j-oeuvre', 'oeuvre', __( 'Œuvre', 'yume-core' ), choix_oeuvres( __( 'Toutes', 'yume-core' ) ), ( $oeuvre ? (string) $oeuvre : '' ) );
	$html .= champ_select( 'yn-j-tome', 'tome', __( 'Tome', 'yume-core' ), array( '' => __( 'Tous', 'yume-core' ) ) + $tomes, ( $tome ? (string) $tome : '' ) );
	$html .= '<p class="yn-team__champ yn-team__champ--case"><label class="yn-team__case" for="yn-j-rappels"><input type="checkbox" id="yn-j-rappels" name="rappels" value="1"' . checked( $rappels, true, false ) . '> ' . esc_html__( 'Inclure les rappels automatiques', 'yume-core' ) . '</label></p>';
	$html .= '<p class="yn-team__action"><button type="submit" class="yn-btn">' . esc_html__( 'Filtrer', 'yume-core' ) . '</button></p>';
	$html .= '</form>';

	$html .= '<section class="yn-card yn-team__carte" id="yn-journal-complet" aria-labelledby="yn-journal-complet-titre"><div class="yn-team__section-tete">';
	$html .= '<h2 id="yn-journal-complet-titre" class="yn-label">' . esc_html( $tome ? cible_journal( $tome ) : ( $oeuvre ? titre_brut( $oeuvre ) : __( 'Toutes les mises à jour', 'yume-core' ) ) ) . '</h2>';
	if ( $tome || $oeuvre ) {
		$html .= '<a href="' . esc_url( url_vue_equipe( 'journal', $rappels ? array( 'rappels' => 1 ) : array() ) ) . '">' . esc_html__( 'Tout le journal', 'yume-core' ) . '</a>';
	}
	$html .= '</div>';
	$lien  = static function ( int $tome_id ) use ( $rappels ): string {
		return url_vue_equipe(
			'journal',
			array(
				'tome'    => $tome_id,
				'rappels' => $rappels ? 1 : 0,
			)
		);
	};
	$html .= $entrees ? liste_journal( $entrees, 'yn-team__journal yn-journal', false, $lien ) : '<p class="yn-muted">' . esc_html__( 'Aucune mise à jour.', 'yume-core' ) . '</p>';
	$html .= pagination_vue( 'journal', $filtres, $page, $suivant );
	$html .= '</section>';

	return $html . '</div></div>';
}

/**
 * Rendu du bloc « Espace équipe » (aucun attribut).
 */
function rendu_team_dashboard(): string {
	if ( ! is_user_logged_in() ) {
		$connexion = function_exists( 'yume_url_page' ) ? yume_url_page( 'equipe' ) : home_url( '/' );
		return acces_equipe(
			__( 'Espace équipe', 'yume-core' ),
			__( 'Connectez-vous avec votre compte de l’équipe pour accéder à vos tâches et au planning.', 'yume-core' ),
			'<a class="yn-btn yn-btn--primary" href="' . esc_url( wp_login_url( $connexion ) ) . '">' . esc_html__( 'Se connecter', 'yume-core' ) . '</a>'
		);
	}
	if ( ! current_user_can( 'yume_voir_equipe' ) ) {
		return acces_equipe(
			__( 'Espace réservé à l’équipe', 'yume-core' ),
			__( 'Cet espace est réservé aux membres de l’équipe Yume. Pour nous rejoindre, passez sur le Discord.', 'yume-core' ),
			'<a class="yn-btn" href="' . esc_url( yume_url_page( 'planning' ) ) . '">' . esc_html__( 'Voir le planning public', 'yume-core' ) . '</a>'
		);
	}

	$vue = vue_equipe();
	if ( 'planning' === $vue ) {
		return rendu_vue_planning();
	}
	if ( 'journal' === $vue ) {
		return rendu_vue_journal();
	}
	if ( 'reglages' === $vue ) {
		return rendu_vue_reglages();
	}
	if ( 'lecture' === $vue ) {
		return rendu_vue_lecture();
	}
	if ( 'tomes' === $vue ) {
		return rendu_vue_tomes();
	}
	if ( 'taches' === $vue ) {
		return rendu_vue_taches();
	}
	if ( 'oeuvres' === $vue ) {
		return rendu_vue_oeuvres();
	}
	$ajoutees = vues_equipe_ajoutees();
	if ( isset( $ajoutees[ $vue ] ) ) {
		return (string) call_user_func( $ajoutees[ $vue ]['rendu'] );
	}

	$user    = wp_get_current_user();
	$uid     = (int) $user->ID;
	$tous    = current_user_can( 'yume_maj_planning_tous' );
	$lignes  = yume_get_planning(
		array(
			'a_venir' => true,
			'public'  => false,
		)
	);
	$taches  = taches( $lignes, $uid );
	$stats   = statistiques( $lignes );
	$retour  = est_apercu_editeur() ? null : retour_formulaire( $uid );
	$membres = $tous ? membres_equipe() : array();

	$mes_retards = array_values(
		array_filter(
			$taches,
			static function ( array $t ): bool {
				return 'en_retard' === $t['ligne']['etat'] && ! $t['attente'];
			}
		)
	);
	$par_etape   = array();
	foreach ( $taches as $t ) {
		$par_etape[ $t['principale'] ] = ( $par_etape[ $t['principale'] ] ?? 0 ) + 1;
	}
	$pluriels      = array(
		/* translators: %d : nombre */
		'traduction' => static fn( int $n ): string => sprintf( _n( '%d traduction', '%d traductions', $n, 'yume-core' ), $n ),
		/* translators: %d : nombre */
		'relecture'  => static fn( int $n ): string => sprintf( _n( '%d relecture', '%d relectures', $n, 'yume-core' ), $n ),
		/* translators: %d : nombre */
		'edition'    => static fn( int $n ): string => sprintf( _n( '%d édition', '%d éditions', $n, 'yume-core' ), $n ),
	);
	$detail_taches = array();
	foreach ( ETAPES_TRAVAIL as $e ) {
		if ( ! empty( $par_etape[ $e ] ) ) {
			$detail_taches[] = $pluriels[ $e ]( $par_etape[ $e ] );
		}
	}

	$debut_mois   = ( new \DateTimeImmutable( '@' . maintenant() ) )->setTimezone( fuseau() )->modify( 'first day of this month' )->setTime( 0, 0 );
	$rappels_mois = compter_journal( array( 'rappel', 'signalement' ), gmt( $debut_mois->getTimestamp() ) );
	$canaux       = '' !== webhook( 'equipe' ) ? __( 'e-mail + Discord', 'yume-core' ) : __( 'e-mail', 'yume-core' );
	$jours_digest = array( __( 'dimanche', 'yume-core' ), __( 'lundi', 'yume-core' ), __( 'mardi', 'yume-core' ), __( 'mercredi', 'yume-core' ), __( 'jeudi', 'yume-core' ), __( 'vendredi', 'yume-core' ), __( 'samedi', 'yume-core' ) );

	$retour_pour = static function ( string $cible ) use ( $retour ): ?array {
		return $retour && ( $retour['cible'] ?? '' ) === $cible ? $retour : null;
	};

	$html = ouvrir_racine( 'yn-team' );

	// Navigation latérale.
	$html .= navigation_equipe( 'tableau', count( $mes_retards ) );

	$html .= '<div class="yn-team__principal">';

	// En-tête.
	$html .= '<div class="yn-team__tete"><div><p class="yn-label">' . esc_html( majuscule( format_fr( maintenant(), 'l j F Y' ) ) ) . '</p>';
	/* translators: %s : pseudo */
	$html .= '<h2 class="yn-team__bonjour">' . esc_html( sprintf( __( 'Bonjour %s', 'yume-core' ), $user->display_name ) ) . '</h2></div>';
	$html .= '<p class="yn-team__boutons"><a class="yn-btn" href="' . esc_url( yume_url_page( 'planning' ) ) . '">' . esc_html__( 'Voir le planning public', 'yume-core' ) . '</a>';
	if ( current_user_can( 'yume_publier' ) ) {
		$html .= '<a class="yn-btn yn-btn--primary" href="' . esc_url( yume_url_page( 'publier' ) ) . '"><span aria-hidden="true">+</span> ' . esc_html__( 'Ajouter des chapitres', 'yume-core' ) . '</a>';
	}
	$html .= '</p></div>';

	// Échecs d'envoi récents (gérants).
	if ( current_user_can( 'yume_reglages' ) ) {
		$echecs = echecs_recents( 7 );
		if ( $echecs ) {
			$html .= '<div class="yn-card yn-team__alerte" role="note"><p><span aria-hidden="true">▲</span> ' . esc_html(
				sprintf(
					/* translators: 1: nombre d'échecs, 2: dernier message */
					_n( '%1$d envoi a échoué cette semaine. Dernier : %2$s', '%1$d envois ont échoué cette semaine. Dernier : %2$s', count( $echecs ), 'yume-core' ),
					count( $echecs ),
					$echecs[0]['message']
				)
			) . '</p></div>';
		}
	}

	// Chiffres.
	$prochaine = $stats['prochaines'][0] ?? null;
	$html     .= '<section class="yn-team__chiffres" aria-labelledby="yn-team-chiffres"><h2 id="yn-team-chiffres" class="yn-visually-hidden">' . esc_html__( 'En bref', 'yume-core' ) . '</h2>';
	$html     .= chiffre_equipe( __( 'Mes tâches en cours', 'yume-core' ), (string) count( $taches ), $detail_taches ? implode( ' · ', $detail_taches ) : __( 'Rien à faire pour le moment', 'yume-core' ) );
	$premier   = $mes_retards[0]['ligne'] ?? null;
	$html     .= chiffre_equipe(
		__( 'Mes retards', 'yume-core' ),
		(string) count( $mes_retards ),
		$premier ? cible_journal( (int) $premier['tome_id'] ) . ' · ' . mb_strtolower( texte_etat( $premier ) ) : __( 'Aucun retard, bravo !', 'yume-core' ),
		(bool) $mes_retards
	);
	$html     .= chiffre_equipe(
		__( 'Prochaine sortie équipe', 'yume-core' ),
		$prochaine ? format_fr( ts_date( $prochaine['date_cible'] ), 'D j' ) : '—',
		$prochaine ? $prochaine['oeuvre'] . ' · ' . libelle_prochaine_sortie( $prochaine ) : __( 'Aucune date annoncée', 'yume-core' )
	);
	$html     .= chiffre_equipe(
		__( 'Rappels ce mois', 'yume-core' ),
		(string) $rappels_mois,
		sprintf(
			/* translators: 1: canaux, 2: jour, 3: heure */
			__( '%1$s · récapitulatif %2$s %3$d h', 'yume-core' ),
			$canaux,
			$jours_digest[ jour_digest() ],
			heure_rappels()
		)
	);
	$html .= '</section>';

	$html .= '<div class="yn-team__colonnes"><div class="yn-team__gauche">';

	// Mes tâches.
	$html .= '<section class="yn-team__section" id="yn-mes-taches" aria-labelledby="yn-mes-taches-titre"><h2 id="yn-mes-taches-titre">' . esc_html__( 'Mes tâches', 'yume-core' ) . '</h2>';
	if ( ! $taches ) {
		$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html__( 'Aucune tâche ne vous est attribuée. Demandez à un éditeur ou à un gérant de vous confier un tome.', 'yume-core' ) . '</p>';
	}
	foreach ( $taches as $tache ) {
		$html .= carte_tache( $tache, $retour_pour( 'yn-tache-' . $tache['ligne']['tome_id'] ) );
	}
	$html .= '</section>';

	if ( $tous ) {
		$html .= '<section class="yn-team__section" id="yn-tous-les-tomes" aria-labelledby="yn-tous-titre"><div class="yn-team__section-tete"><h2 id="yn-tous-titre">' . esc_html__( 'Tomes en préparation', 'yume-core' ) . '</h2>';
		$html .= '<a href="' . esc_url( url_vue_equipe( 'planning' ) ) . '">' . esc_html__( 'Planning complet (publiés, programmés…)', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></div>';
		$html .= '<div id="yn-gestion-retour">' . zone_retour( $retour_pour( 'yn-gestion-retour' ) ) . '</div>';
		if ( $lignes ) {
			$html .= '<ul class="yn-card yn-team__liste">';
			foreach ( $lignes as $l ) {
				$html .= ligne_gestion( $l, $membres, $retour_pour( 'yn-tome-' . $l['tome_id'] ) );
			}
			$html .= '</ul>';
		} else {
			$html .= '<p class="yn-card yn-team__vide yn-muted">' . esc_html__( 'Aucun tome en préparation.', 'yume-core' ) . '</p>';
		}
		$html .= '</section>';
		// Ancienne section du formulaire d'ajout (ancre #yn-ajouter-tome-section conservée pour les
		// liens existants) : le formulaire est désormais la vue « Nouveau tome ».
		$html .= '<section class="yn-team__section" id="yn-ajouter-tome-section" aria-labelledby="yn-ajouter-tome"><h2 id="yn-ajouter-tome">' . esc_html__( 'Ajouter un tome au planning', 'yume-core' ) . '</h2>';
		$html .= '<div class="yn-card yn-team__ajout" id="yn-ajouter-tome-form"><p class="yn-muted">' . esc_html__( 'Le tome est créé sans fichier, avec son planning, ses chapitres prévus et son rythme de sortie ; ses chapitres arrivent ensuite avec « Ajouter des chapitres ».', 'yume-core' ) . '</p>';
		$html .= '<p class="yn-team__action"><a class="yn-btn yn-btn--primary" href="' . esc_url( url_nouveau_tome( get_entier( 'oeuvre_ajout' ), 'tableau' ) ) . '">' . esc_html__( 'Nouveau tome', 'yume-core' ) . '</a></p></div></section>';
	}
	$html .= '</div><div class="yn-team__droite"><h2>' . esc_html__( 'Rappels et journal', 'yume-core' ) . '</h2>';

	// Retards de l'équipe.
	$alertes = array_values(
		array_filter(
			$lignes,
			static function ( array $l ): bool {
				return in_array( $l['etat'], array( 'en_retard', 'bloque' ), true );
			}
		)
	);
	$html   .= '<section class="yn-card yn-team__carte" id="yn-retards" aria-labelledby="yn-retards-titre"><h3 id="yn-retards-titre" class="yn-label">' . esc_html__( 'Retards et blocages', 'yume-core' ) . '</h3>';
	if ( $alertes ) {
		$html .= '<ul class="yn-team__alertes">';
		foreach ( array_slice( $alertes, 0, 8 ) as $l ) {
			$noms  = array_filter( wp_list_pluck( $l['responsables'], 'nom' ) );
			$html .= '<li>' . pastille( $l['etat'], texte_etat( $l ) ) . ' <span><b>' . esc_html( cible_journal( (int) $l['tome_id'] ) ) . '</b>' . badge_pause( $l );
			$html .= $noms ? ' · ' . esc_html( implode( ', ', array_unique( $noms ) ) ) : ' · ' . esc_html__( 'sans responsable', 'yume-core' );
			$html .= '</span></li>';
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-muted">' . esc_html__( 'Tout est à l’heure.', 'yume-core' ) . '</p>';
	}
	$html .= '</section>';

	// Rappels automatiques récents.
	$rappels = grouper_journal(
		lire_journal(
			array(
				'champs' => array( 'rappel', 'signalement', 'digest' ),
				'depuis' => gmt( maintenant() - 30 * DAY_IN_SECONDS ),
				'limit'  => 5,
			)
		),
		true
	);
	$html   .= '<section class="yn-card yn-team__carte" aria-labelledby="yn-rappels-titre"><h3 id="yn-rappels-titre" class="yn-label">' . esc_html__( 'Rappels automatiques', 'yume-core' ) . '</h3>';
	if ( $rappels ) {
		$html .= '<ul class="yn-team__rappels">';
		foreach ( $rappels as $r ) {
			$infos  = is_array( $r['donnees'] ) ? $r['donnees'] : array();
			$canal  = in_array( 'discord', (array) ( $infos['canaux'] ?? array() ), true ) ? __( 'e-mail + Discord', 'yume-core' ) : __( 'e-mail', 'yume-core' );
			$gerant = 'rappel' !== $r['type'];
			$qui    = $gerant ? __( 'Gérants', 'yume-core' ) : implode( ', ', array_map( __NAMESPACE__ . '\\nom_utilisateur', array_map( 'intval', (array) ( $infos['destinataires'] ?? array() ) ) ) );
			$texte  = implode( ', ', $r['parties'] );
			if ( 'rappel' === $r['type'] ) {
				$texte = (string) preg_replace( '/^[^:]*:\s*/u', '', $texte );
			}
			$html .= '<li><span class="yn-chip yn-chip--' . ( 'rappel' === $r['type'] ? 'warn' : ( 'signalement' === $r['type'] ? 'err' : 'info' ) ) . '"><span aria-hidden="true">' . ( 'rappel' === $r['type'] ? '▲' : ( 'signalement' === $r['type'] ? '■' : '✉' ) ) . '</span>';
			$html .= '<span class="yn-visually-hidden">' . esc_html( 'rappel' === $r['type'] ? __( 'Rappel', 'yume-core' ) : ( 'signalement' === $r['type'] ? __( 'Signalement', 'yume-core' ) : __( 'Récapitulatif', 'yume-core' ) ) ) . '</span></span>';
			$html .= '<span><b>' . esc_html( '' !== $qui ? $qui : __( 'Équipe', 'yume-core' ) ) . '</b>' . ( '' !== $r['cible'] ? ' · ' . esc_html( $r['cible'] ) : '' ) . ' · ' . esc_html( $texte );
			/* translators: 1: date, 2: canaux */
			$html .= '<span class="yn-muted yn-team__quand">' . esc_html( sprintf( __( 'envoyé le %1$s · %2$s', 'yume-core' ), format_fr( $r['ts'], 'j M H:i' ), $canal ) ) . '</span></span></li>';
		}
		$html .= '</ul>';
	} else {
		$html .= '<p class="yn-muted">' . esc_html__( 'Aucun rappel ces 30 derniers jours.', 'yume-core' ) . '</p>';
	}
	if ( current_user_can( 'yume_reglages' ) ) {
		$html .= '<p><a href="' . esc_url( url_vue_equipe( 'reglages' ) . '#yn-reglages-planning' ) . '">' . esc_html__( 'Régler les délais et canaux', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p>';
	}
	$html .= '</section>';

	// Journal de l'équipe.
	$journal = grouper_journal(
		lire_journal(
			array(
				'exclure' => array( 'rappel', 'signalement', 'digest' ),
				'limit'   => 40,
			)
		),
		true,
		8
	);
	$html   .= '<section class="yn-card yn-team__carte" id="yn-team-journal" aria-labelledby="yn-team-journal-titre"><h3 id="yn-team-journal-titre" class="yn-label">' . esc_html__( 'Journal de l’équipe', 'yume-core' ) . '</h3>';
	$html   .= $journal ? liste_journal( $journal, 'yn-team__journal yn-journal', true ) : '<p class="yn-muted">' . esc_html__( 'Aucune mise à jour pour le moment.', 'yume-core' ) . '</p>';
	$html   .= '<p><a href="' . esc_url( url_vue_equipe( 'journal' ) ) . '">' . esc_html__( 'Tout le journal', 'yume-core' ) . ' <span aria-hidden="true">→</span></a></p>';
	$html   .= '</section>';

	$html .= '</div></div></div></div>';
	return $html;
}

/*
 * -----------------------------------------------------------------------------
 * Formulaires sans JavaScript (admin-post.php)
 * -----------------------------------------------------------------------------
 */

/**
 * Valeur texte d'un champ POST (déslashé).
 *
 * @param array  $post Données.
 * @param string $cle  Clé.
 * @return mixed
 */
function champ_post( array $post, string $cle ) {
	return isset( $post[ $cle ] ) ? wp_unslash( $post[ $cle ] ) : null;
}

/**
 * Traite le formulaire de mise à jour d'un tome.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,tome_id:int}
 */
function traiter_formulaire_maj( array $post, int $user_id ): array {
	$tome_id = isset( $post['tome_id'] ) && is_scalar( $post['tome_id'] ) ? absint( $post['tome_id'] ) : 0;
	$ancre   = is_scalar( $post['ancre'] ?? null ) ? (string) preg_replace( '/[^a-z0-9-]/', '', (string) $post['ancre'] ) : '';
	$ancre   = preg_match( '/^yn-(tache|tome)-\d+$/', $ancre ) ? $ancre : 'yn-tache-' . $tome_id;
	$nonce   = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$base    = array(
		'cible'   => $ancre,
		'tome_id' => $tome_id,
	);
	if ( ! $tome_id || ! wp_verify_nonce( $nonce, 'yume_planning_maj_' . $tome_id ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		);
	}
	$saisie = array();
	foreach ( array( 'etape', 'date_cible', 'heure_cible', 'note_equipe' ) as $cle ) {
		if ( null !== champ_post( $post, $cle ) ) {
			$saisie[ $cle ] = champ_post( $post, $cle );
		}
	}
	foreach ( array( 'avancement', 'responsables' ) as $cle ) {
		$valeur = champ_post( $post, $cle );
		if ( is_array( $valeur ) ) {
			$saisie[ $cle ] = $valeur;
		}
	}
	if ( null !== champ_post( $post, 'bloque_present' ) ) {
		$saisie['bloque']        = null !== champ_post( $post, 'bloque' );
		$saisie['bloque_raison'] = (string) ( champ_post( $post, 'bloque_raison' ) ?? '' );
	}
	$resultat = mettre_a_jour( $tome_id, $saisie, $user_id );
	if ( is_wp_error( $resultat ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => $resultat->get_error_message(),
		);
	}
	return $base + array(
		'type'    => 'ok',
		'message' => message_mise_a_jour( $tome_id, $resultat['changements'] ),
	);
}

/**
 * Traite le formulaire « Nouveau tome » (ancien « Ajouter un tome au planning ») : même
 * service que POST /yume/v1/planning/tomes (ajouter_tome()), avec l'heure de sortie
 * (heure_cible, gardée quel que soit le rythme), les chapitres prévus, le rythme (rythme_jour ;
 * rythme_heure d'un formulaire ancien accepté comme heure de sortie) et le bouton choisi
 * (suite = fiche | chapitre).
 *
 * @param array $post    Données POST.
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,tome_id:int,suite:string,saisie:array}
 *         tome_id : tome créé, ou tome existant (même œuvre, nature et numéro) dont la fiche
 *         s'ouvre ; saisie : champs à reprendre dans le formulaire en cas d'erreur.
 */
function traiter_formulaire_ajout( array $post, int $user_id ): array {
	$nonce  = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$suite  = 'chapitre' === ( is_scalar( $post['suite'] ?? null ) ? (string) $post['suite'] : '' ) ? 'chapitre' : 'fiche';
	$saisie = array();
	foreach ( array( 'oeuvre_id', 'nature', 'numero', 'titre', 'date_cible', 'etape', 'chapitres_prevus' ) as $cle ) {
		$valeur = champ_post( $post, $cle );
		if ( is_scalar( $valeur ) && '' !== (string) $valeur ) {
			$saisie[ $cle ] = sanitize_text_field( (string) $valeur );
		}
	}
	$resp = champ_post( $post, 'responsables' );
	if ( is_array( $resp ) ) {
		$saisie['responsables'] = array_map( 'absint', array_intersect_key( array_filter( $resp, 'is_scalar' ), array_flip( ETAPES_TRAVAIL ) ) );
	}
	$heure = champ_post( $post, 'heure_cible' );
	if ( null !== $heure ) {
		$saisie['heure_cible'] = is_scalar( $heure ) ? sanitize_text_field( (string) $heure ) : '';
	}
	$jour   = champ_post( $post, 'rythme_jour' );
	$ancien = champ_post( $post, 'rythme_heure' );
	if ( ( is_scalar( $jour ) && '' !== (string) $jour ) || ( is_scalar( $ancien ) && '' !== (string) $ancien ) ) {
		$saisie['rythme'] = array(
			'jour'  => is_scalar( $jour ) ? sanitize_key( (string) $jour ) : '',
			'heure' => is_scalar( $ancien ) ? sanitize_text_field( (string) $ancien ) : '',
		);
	}
	$base = array(
		'cible'   => 'yn-nouveau-tome-form',
		'suite'   => $suite,
		'saisie'  => $saisie,
		'tome_id' => 0,
	);
	if ( ! wp_verify_nonce( $nonce, 'yume_planning_ajout' ) ) {
		return array_merge(
			$base,
			array(
				'type'    => 'erreur',
				'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
			)
		);
	}
	$tome_id = ajouter_tome( $saisie, $user_id );
	if ( is_wp_error( $tome_id ) ) {
		$donnees  = $tome_id->get_error_data();
		$existant = 'yume_tome_existe' === $tome_id->get_error_code() && is_array( $donnees ) ? (int) ( $donnees['tome_id'] ?? 0 ) : 0;
		if ( $existant ) {
			// Le tome existe déjà : sa fiche s'ouvre, avec ce message en tête.
			return array_merge(
				$base,
				array(
					'cible'   => 'yn-tome-fiche',
					'type'    => 'erreur',
					/* translators: %s : tome */
					'message' => sprintf( __( '%s existe déjà : voici sa fiche, aucun tome n’a été créé.', 'yume-core' ), cible_journal( $existant ) ),
					'tome_id' => $existant,
				)
			);
		}
		return array_merge(
			$base,
			array(
				'type'    => 'erreur',
				'message' => $tome_id->get_error_message(),
			)
		);
	}
	return array_merge(
		$base,
		array(
			'cible'   => 'yn-tome-fiche',
			'type'    => 'ok',
			/* translators: %s : tome */
			'message' => sprintf( __( '%s ajouté au planning.', 'yume-core' ), cible_journal( $tome_id ) ),
			'tome_id' => $tome_id,
		)
	);
}

/**
 * Traite le formulaire « Retirer du planning » (SCAN-06) : le service retire le tome (brouillon
 * sans chapitre publié) ou explique son refus.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,tome_id:int}
 */
function traiter_formulaire_retrait( array $post, int $user_id ): array {
	$tome_id = isset( $post['tome_id'] ) && is_scalar( $post['tome_id'] ) ? absint( $post['tome_id'] ) : 0;
	$nonce   = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$erreur  = static function ( string $message ) use ( $tome_id ): array {
		return array(
			'type'    => 'erreur',
			'message' => $message,
			'cible'   => $tome_id ? 'yn-tome-' . $tome_id : 'yn-gestion-retour',
			'tome_id' => $tome_id,
		);
	};
	if ( ! $tome_id || ! wp_verify_nonce( $nonce, 'yume_planning_retrait_' . $tome_id ) ) {
		return $erreur( __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ) );
	}
	if ( ! function_exists( __NAMESPACE__ . '\\retirer_tome' ) ) {
		return $erreur( __( 'Le retrait d’un tome n’est pas disponible sur ce site : passez par l’administration.', 'yume-core' ) );
	}
	$nom      = cible_journal( $tome_id );
	$resultat = retirer_tome( $tome_id, $user_id );
	if ( is_wp_error( $resultat ) ) {
		return $erreur( $resultat->get_error_message() );
	}
	return array(
		'type'    => 'ok',
		/* translators: %s : tome */
		'message' => sprintf( __( '%s a été retiré du planning (brouillon mis à la corbeille).', 'yume-core' ), $nom ),
		'cible'   => 'yn-gestion-retour',
		'tome_id' => $tome_id,
	);
}

/**
 * Traite le formulaire « Mettre en pause » / « Reprendre » d'un tome.
 *
 * @param array $post    Données POST (brutes, avec slashes).
 * @param int   $user_id Utilisateur.
 * @return array{type:string,message:string,cible:string,tome_id:int}
 */
function traiter_formulaire_pause( array $post, int $user_id ): array {
	$tome_id = isset( $post['tome_id'] ) && is_scalar( $post['tome_id'] ) ? absint( $post['tome_id'] ) : 0;
	$nonce   = is_scalar( $post['_yume_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( (string) $post['_yume_nonce'] ) ) : '';
	$pause   = '1' === ( is_scalar( $post['pause'] ?? null ) ? (string) $post['pause'] : '' );
	$base    = array(
		'cible'   => $tome_id ? 'yn-tome-' . $tome_id : 'yn-gestion-retour',
		'tome_id' => $tome_id,
	);
	if ( ! $tome_id || ! wp_verify_nonce( $nonce, 'yume_planning_pause_' . $tome_id ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => __( 'Votre session a expiré : rechargez la page puis réessayez.', 'yume-core' ),
		);
	}
	$resultat = basculer_pause( $tome_id, $pause, $user_id );
	if ( is_wp_error( $resultat ) ) {
		return $base + array(
			'type'    => 'erreur',
			'message' => $resultat->get_error_message(),
		);
	}
	return $base + array(
		'type'    => 'ok',
		'message' => sprintf(
			$pause
				/* translators: %s : tome */
				? __( '%s est en pause : ni retard ni rappel jusqu’à sa reprise.', 'yume-core' )
				/* translators: %s : tome */
				: __( '%s a repris : il compte de nouveau dans les retards et les rappels.', 'yume-core' ),
			cible_journal( $tome_id )
		),
	);
}

/**
 * Contrôle d'une demande d'export CSV du planning : capacité yume_voir_equipe et nonce.
 *
 * @param string $nonce Nonce reçu.
 * @return true|\WP_Error
 */
function controler_export( string $nonce ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'yume_voir_equipe' ) ) {
		return new \WP_Error( 'yume_export_interdit', __( 'L’export du planning est réservé à l’équipe.', 'yume-core' ), array( 'status' => 403 ) );
	}
	if ( ! wp_verify_nonce( $nonce, 'yume_planning_export' ) ) {
		return new \WP_Error( 'yume_export_expire', __( 'Le lien d’export a expiré : rechargez le planning complet puis réessayez.', 'yume-core' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Redirige vers l'espace équipe après un formulaire.
 *
 * @param array $retour Retour.
 */
function rediriger_retour( array $retour ): void {
	$equipe = yume_url_page( 'equipe' );
	$page   = wp_get_referer();
	$page   = $page ? wp_validate_redirect( $page, $equipe ) : $equipe;
	$page   = remove_query_arg( array( 'yume_planning' ), (string) strtok( $page, '#' ) );
	wp_safe_redirect( add_query_arg( 'yume_planning', 'ok' === $retour['type'] ? 'ok' : 'erreur', $page ) . '#' . $retour['cible'] );
	exit;
}

/**
 * Formulaire de mise à jour envoyé sans JavaScript (admin-post.php, action yume_planning_maj).
 */
function admin_post_maj(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_maj().
	$retour = traiter_formulaire_maj( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_retour( $retour );
}
add_action( 'admin_post_yume_planning_maj', __NAMESPACE__ . '\\admin_post_maj' );

/**
 * Formulaire « Nouveau tome » (admin-post.php, action yume_planning_ajout) : vers le formulaire
 * de publication (« Créer le tome et ajouter un chapitre »), la fiche du tome créé ou existant,
 * ou de nouveau le formulaire en cas d'erreur (adresse_apres_ajout()).
 */
function admin_post_ajout(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_ajout().
	$retour  = traiter_formulaire_ajout( $_POST, get_current_user_id() );
	$adresse = adresse_apres_ajout( $retour );
	if ( 'ok' !== $retour['type'] || 'chapitre' !== $retour['suite'] ) {
		retour_formulaire( get_current_user_id(), $retour );
	}
	wp_safe_redirect( $adresse );
	exit;
}
add_action( 'admin_post_yume_planning_ajout', __NAMESPACE__ . '\\admin_post_ajout' );

/**
 * Formulaire « Retirer du planning » (admin-post.php, action yume_planning_retrait).
 */
function admin_post_retrait(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_retrait().
	$retour = traiter_formulaire_retrait( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_retour( $retour );
}
add_action( 'admin_post_yume_planning_retrait', __NAMESPACE__ . '\\admin_post_retrait' );

/**
 * Formulaire « Mettre en pause » / « Reprendre » (admin-post.php, action yume_planning_pause).
 */
function admin_post_pause(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié dans traiter_formulaire_pause().
	$retour = traiter_formulaire_pause( $_POST, get_current_user_id() );
	retour_formulaire( get_current_user_id(), $retour );
	rediriger_retour( $retour );
}
add_action( 'admin_post_yume_planning_pause', __NAMESPACE__ . '\\admin_post_pause' );

/**
 * Export CSV du planning complet filtré (admin-post.php, action yume_planning_export) : les
 * filtres sont ceux de la vue (paramètres GET), le fichier est téléchargé.
 */
function admin_post_export(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture du nonce, vérifié par controler_export().
	$nonce    = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	$controle = controler_export( $nonce );
	if ( is_wp_error( $controle ) ) {
		wp_die( esc_html( $controle->get_error_message() ), esc_html__( 'Export impossible', 'yume-core' ), array( 'response' => 403 ) );
	}
	$csv = csv_planning( lignes_vue_planning( filtres_gestion() ) );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="planning-yume-' . date_locale() . '.csv"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fichier CSV, cellules neutralisées par cellule_csv().
	exit;
}
add_action( 'admin_post_yume_planning_export', __NAMESPACE__ . '\\admin_post_export' );

/**
 * Visiteur non connecté : vers la connexion.
 */
function admin_post_anonyme(): void {
	wp_safe_redirect( wp_login_url( yume_url_page( 'equipe' ) ) );
	exit;
}
add_action( 'admin_post_nopriv_yume_planning_maj', __NAMESPACE__ . '\\admin_post_anonyme' );
add_action( 'admin_post_nopriv_yume_planning_ajout', __NAMESPACE__ . '\\admin_post_anonyme' );
add_action( 'admin_post_nopriv_yume_planning_retrait', __NAMESPACE__ . '\\admin_post_anonyme' );
add_action( 'admin_post_nopriv_yume_planning_pause', __NAMESPACE__ . '\\admin_post_anonyme' );
add_action( 'admin_post_nopriv_yume_planning_export', __NAMESPACE__ . '\\admin_post_anonyme' );
