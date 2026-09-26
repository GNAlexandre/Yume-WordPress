<?php
/**
 * Blocs du planning (§10) : enregistrement, composants partagés (pastilles d'état, barres,
 * journal) et rendu des blocs publics yume/upcoming, yume/planning et yume/oeuvre-planning.
 * Le bloc yume/team-dashboard est rendu par equipe.php, yume/team-members par membres.php.
 *
 * Couleurs : uniquement les variables et classes du thème (§15).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Planning;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre les blocs du module.
 */
function enregistrer_blocs(): void {
	if ( ! function_exists( 'yume_register_dynamic_block' ) ) {
		return;
	}
	foreach ( array( 'upcoming', 'planning', 'oeuvre-planning', 'team-dashboard', 'team-members' ) as $bloc ) {
		yume_register_dynamic_block( __DIR__ . '/blocks/' . $bloc );
	}
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_blocs' );

/*
 * -----------------------------------------------------------------------------
 * Composants
 * -----------------------------------------------------------------------------
 */

/**
 * Attributs de la racine d'un bloc (classes de l'éditeur comprises).
 *
 * @param string $classe Classe racine.
 * @param array  $extra  Attributs supplémentaires.
 */
function attributs_racine( string $classe, array $extra = array() ): string {
	$extra['class'] = trim( $classe . ' ' . ( $extra['class'] ?? '' ) );
	if ( class_exists( '\WP_Block_Supports' ) && ! empty( \WP_Block_Supports::$block_to_render ) ) {
		return get_block_wrapper_attributes( $extra );
	}
	$html = '';
	foreach ( $extra as $nom => $valeur ) {
		$html .= ' ' . $nom . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return ltrim( $html );
}

/**
 * Pastille d'état : icône + texte (jamais la couleur seule). Un texte personnalisé est
 * précédé de l'état pour les lecteurs d'écran.
 *
 * @param string $etat  État (a_lheure, en_retard, bloque, publie).
 * @param string $texte Texte visible (vide : libellé de l'état).
 * @param array  $attrs Attributs supplémentaires.
 */
function pastille( string $etat, string $texte = '', array $attrs = array() ): string {
	$etats   = etats();
	$libelle = $etats[ $etat ] ?? '';
	$contenu = '<span aria-hidden="true">' . esc_html( icone_etat( $etat ) ) . '</span> ';
	if ( '' === $texte ) {
		$contenu .= esc_html( $libelle );
	} elseif ( '' !== $libelle && 0 === mb_stripos( $texte, $libelle ) ) {
		// Le texte nomme déjà l'état (« En retard de 3 j », « En retard · édition ») : pas de doublon.
		$contenu .= esc_html( $texte );
	} else {
		$contenu .= '<span class="yn-visually-hidden">' . esc_html( $libelle ) . ' : </span>' . esc_html( $texte );
	}
	$html = '<span class="yn-chip yn-chip--' . esc_attr( variante_etat( $etat ) ) . '"';
	foreach ( $attrs as $nom => $valeur ) {
		$html .= ' ' . esc_attr( $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return $html . '>' . $contenu . '</span>';
}

/**
 * Texte de l'état d'une ligne : « En retard de 3 j », « Sans nouvelles depuis 16 j »,
 * « Bloqué · relecteur manquant ».
 *
 * @param array $ligne Ligne.
 */
function texte_etat( array $ligne ): string {
	$etats = etats();
	if ( 'en_retard' === $ligne['etat'] && $ligne['jours_retard'] > 0 ) {
		return 'date' === $ligne['motif_retard']
			/* translators: %d : jours */
			? sprintf( __( 'En retard de %d j', 'yume-core' ), (int) $ligne['jours_retard'] )
			/* translators: %d : jours */
			: sprintf( __( 'Sans nouvelles depuis %d j', 'yume-core' ), (int) $ligne['jours_retard'] );
	}
	if ( 'bloque' === $ligne['etat'] && '' !== $ligne['bloque_raison'] ) {
		return $etats['bloque'] . ' · ' . $ligne['bloque_raison'];
	}
	return $etats[ $ligne['etat'] ] ?? '';
}

/**
 * Cellule « État » du tableau public : pastille, avec la raison d'un blocage dans la pastille
 * si elle est courte (« Bloqué · relecteur manquant »), sinon en texte discret sous la pastille
 * (une raison longue ne déforme pas la colonne).
 *
 * @param array $ligne Ligne.
 */
function cellule_etat( array $ligne ): string {
	if ( 'bloque' !== $ligne['etat'] || '' === $ligne['bloque_raison'] ) {
		return pastille( $ligne['etat'] );
	}
	if ( mb_strlen( $ligne['bloque_raison'] ) <= 32 ) {
		return pastille( $ligne['etat'], texte_etat( $ligne ) );
	}
	return pastille( $ligne['etat'] ) . '<span class="yn-planning__raison">' . esc_html( $ligne['bloque_raison'] ) . '</span>';
}

/**
 * Barre de progression décorative (le pourcentage est écrit à côté).
 *
 * @param int    $pct      Pourcentage.
 * @param string $variante '', 'ok' ou 'warn'.
 */
function barre( int $pct, string $variante = '' ): string {
	$classe = 'yn-bar' . ( '' !== $variante ? ' yn-bar--' . $variante : '' );
	return '<span class="' . esc_attr( $classe ) . '" style="--v:' . (int) max( 0, min( 100, $pct ) ) . '%" aria-hidden="true"></span>';
}

/**
 * Variante de barre d'une étape : terminée (ok), en retard (warn) ou normale.
 *
 * @param array  $ligne Ligne.
 * @param string $etape Étape.
 */
function variante_barre( array $ligne, string $etape ): string {
	$pct = (int) $ligne['avancement'][ $etape ];
	if ( 100 === $pct || 'publie' === $ligne['etat'] ) {
		return 'ok';
	}
	if ( 'en_retard' === $ligne['etat'] && etape_de_travail( $ligne['etape'] ) === $etape ) {
		return 'warn';
	}
	return '';
}

/**
 * Complément du nom d'un tome : sous-titre et chapitres (« Tournoi d'échecs · chapitre 9 / 15 »).
 *
 * @param array $ligne Ligne.
 */
function complement_tome( array $ligne ): string {
	$parties = array( $ligne['tome'] );
	if ( '' !== $ligne['titre'] ) {
		$parties[] = $ligne['titre'];
	}
	$chap = $ligne['chapitres'];
	if ( 'publish' === $ligne['statut'] && $chap['total'] > $chap['publies'] && 'publie' !== $ligne['etat'] ) {
		/* translators: 1: prochain chapitre, 2: total */
		$parties[] = sprintf( __( 'chapitre %1$d / %2$d', 'yume-core' ), $chap['publies'] + 1, $chap['total'] );
	}
	return implode( ' · ', $parties );
}

/**
 * Date d'une entrée de journal (« 24 sept. 14:12 »).
 *
 * @param int $ts Horodatage.
 */
function date_entree( int $ts ): string {
	return format_fr( $ts, 'j M H:i' );
}

/**
 * Entrées du journal en HTML (liste).
 *
 * @param array  $entrees Entrées regroupées.
 * @param string $classe  Classe de la liste.
 * @param bool   $court   Date courte (heure du jour, « hier », « 20 sept. »).
 */
function liste_journal( array $entrees, string $classe, bool $court = false ): string {
	$html = '<ol class="' . esc_attr( $classe ) . '">';
	foreach ( $entrees as $e ) {
		if ( $court ) {
			$jours = ecart_jours( date_locale( $e['ts'] ), date_locale() );
			$quand = 0 === $jours ? format_fr( $e['ts'], 'H:i' ) : ( 1 === $jours ? __( 'hier', 'yume-core' ) : format_fr( $e['ts'], 'j M' ) );
		} else {
			$quand = date_entree( $e['ts'] );
		}
		$html .= '<li><time class="yn-muted" datetime="' . esc_attr( gmdate( 'c', $e['ts'] ) ) . '">' . esc_html( $quand ) . '</time>';
		$html .= '<span class="yn-journal__texte"><b>' . esc_html( $e['auteur'] ) . '</b>';
		if ( '' !== $e['cible'] ) {
			$html .= ' · ' . esc_html( $e['cible'] );
		}
		if ( $e['parties'] ) {
			$html .= ' · ' . esc_html( implode( ', ', $e['parties'] ) );
		}
		if ( $e['publie'] ) {
			$html .= ' ' . pastille( 'publie' );
		}
		$html .= '</span></li>';
	}
	return $html . '</ol>';
}

/**
 * Phrase des jours de sortie habituels (« le mercredi, le samedi et le dimanche »).
 */
function phrase_jours_sortie(): string {
	$jours = function_exists( 'yume_setting' ) ? (array) yume_setting( 'jours_sortie', array() ) : array();
	$noms  = array();
	foreach ( yume_jours_semaine() as $slug => $nom ) {
		if ( in_array( $slug, $jours, true ) ) {
			/* translators: %s : jour */
			$noms[] = sprintf( __( 'le %s', 'yume-core' ), mb_strtolower( $nom ) );
		}
	}
	if ( ! $noms ) {
		return '';
	}
	$dernier = array_pop( $noms );
	/* translators: 1: jours, 2: dernier jour */
	return $noms ? sprintf( __( '%1$s et %2$s', 'yume-core' ), implode( ', ', $noms ), $dernier ) : $dernier;
}

/**
 * Message discret dans l'aperçu de l'éditeur (chaîne vide en façade).
 *
 * @param string $classe  Classe racine.
 * @param string $message Message.
 */
function message_editeur( string $classe, string $message ): string {
	if ( ! est_apercu_editeur() ) {
		return '';
	}
	return '<div ' . attributs_racine( $classe ) . '><p class="yn-muted">' . esc_html( $message ) . '</p></div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/upcoming
 * -----------------------------------------------------------------------------
 */

/**
 * Rendu du bloc « Prochaines sorties » (compact, sans carte propre).
 *
 * @param array $attributs Attributs (count).
 */
function rendu_upcoming( array $attributs ): string {
	$nombre = max( 1, min( 12, (int) ( $attributs['count'] ?? 3 ) ) );
	$lignes = array_values(
		array_filter(
			yume_get_planning( array( 'a_venir' => true ) ),
			static function ( array $l ): bool {
				return 'bloque' !== $l['etat'];
			}
		)
	);
	// Les tomes datés d'abord (par date), puis les plus avancés.
	usort(
		$lignes,
		static function ( array $a, array $b ): int {
			if ( ( '' === $a['date_cible'] ) !== ( '' === $b['date_cible'] ) ) {
				return '' === $a['date_cible'] ? 1 : -1;
			}
			if ( '' !== $a['date_cible'] && $a['date_cible'] !== $b['date_cible'] ) {
				return strcmp( $a['date_cible'], $b['date_cible'] );
			}
			$cmp = array_sum( $b['avancement'] ) <=> array_sum( $a['avancement'] );
			return 0 !== $cmp ? $cmp : ( $a['tome_id'] <=> $b['tome_id'] );
		}
	);
	$lignes = array_slice( $lignes, 0, $nombre );

	$html = '<div ' . attributs_racine( 'yn-upcoming' ) . '>';
	if ( ! $lignes ) {
		return $html . '<p class="yn-muted yn-upcoming__vide">' . esc_html__( 'Aucune sortie planifiée pour le moment.', 'yume-core' ) . '</p></div>';
	}
	$html .= '<ul class="yn-upcoming__liste">';
	foreach ( $lignes as $l ) {
		$etape = etape_de_travail( $l['etape'] );
		// Le retard se lit en toutes lettres, pas seulement à la couleur et à l'icône.
		$texte = 'en_retard' === $l['etat']
			? etats()['en_retard'] . ' · ' . libelle_etape_min( $etape )
			: pct( (int) ( $l['avancement'][ $etape ] ?? 0 ) );
		if ( '' === $l['date_cible'] ) {
			$date = __( 'à venir', 'yume-core' );
		} elseif ( $l['date_cible'] < date_locale() ) {
			// Date dépassée : ce n'est plus une date de sortie annoncée.
			/* translators: %s : date cible dépassée */
			$date = sprintf( __( 'prévu %s', 'yume-core' ), date_cible_lisible( $l['date_cible'], true ) );
		} else {
			$date = date_cible_lisible( $l['date_cible'] );
		}
		$titre = '<b>' . esc_html( $l['oeuvre'] ) . '</b>';
		if ( '' !== $l['url_oeuvre'] ) {
			$titre = '<a href="' . esc_url( $l['url_oeuvre'] ) . '">' . $titre . '</a>';
		}
		$html .= '<li class="yn-upcoming__item">';
		$html .= '<span class="yn-label yn-upcoming__date">' . esc_html( $date ) . '</span>';
		$html .= '<span class="yn-upcoming__titre">' . $titre . ' · ' . esc_html( libelle_prochaine_sortie( $l ) ) . '</span>';
		$html .= '<span class="yn-upcoming__etat">' . pastille( $l['etat'], $texte ) . '</span>';
		$html .= '</li>';
	}
	return $html . '</ul></div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/planning
 * -----------------------------------------------------------------------------
 */

/**
 * Filtres GET du planning public (type, etat, oeuvre), validés.
 *
 * @return array{type:string,etat:string,oeuvre:int}
 */
function filtres_planning(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtres d'affichage en lecture seule.
	$type   = isset( $_GET['type'] ) && is_string( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
	$etat   = isset( $_GET['etat'] ) && is_string( $_GET['etat'] ) ? sanitize_key( wp_unslash( $_GET['etat'] ) ) : '';
	$oeuvre = isset( $_GET['oeuvre'] ) && is_scalar( $_GET['oeuvre'] ) ? absint( $_GET['oeuvre'] ) : 0;
	// phpcs:enable
	return array(
		'type'   => array_key_exists( $type, yume_types() ) ? $type : '',
		'etat'   => array_key_exists( $etat, etats() ) ? $etat : '',
		'oeuvre' => $oeuvre && 'yume_oeuvre' === get_post_type( $oeuvre ) && 'publish' === get_post_status( $oeuvre ) ? $oeuvre : 0,
	);
}

/**
 * Adresse de base de la page du planning (sans filtre).
 */
function url_base_planning(): string {
	$id = (int) get_queried_object_id();
	if ( $id && is_singular() ) {
		return (string) get_permalink( $id );
	}
	return yume_url_page( 'planning' );
}

/**
 * Adresse filtrée du planning.
 *
 * @param array $filtres Filtres courants.
 * @param array $changer Filtres à changer (valeur vide : retirer).
 */
function url_filtre( array $filtres, array $changer ): string {
	$args = array_filter( array_merge( $filtres, $changer ) );
	return add_query_arg( $args, url_base_planning() ) . '#yn-planning';
}

/**
 * Libellés des types au pluriel (filtres).
 *
 * @return array<string,string>
 */
function types_pluriel(): array {
	$pluriels = array(
		'light-novel' => __( 'Light novels', 'yume-core' ),
		'web-novel'   => __( 'Web novels', 'yume-core' ),
		'manga'       => __( 'Manga', 'yume-core' ),
	);
	$types    = array();
	foreach ( yume_types() as $slug => $nom ) {
		$types[ $slug ] = $pluriels[ $slug ] ?? $nom;
	}
	return $types;
}

/**
 * Lien de filtre (pastille).
 *
 * @param string $texte   Texte (HTML autorisé : déjà échappé).
 * @param string $url     Adresse.
 * @param bool   $actif   Filtre courant.
 * @param string $classe  Variante de pastille.
 */
function lien_filtre( string $texte, string $url, bool $actif, string $classe = 'info' ): string {
	return '<li><a class="yn-chip yn-chip--' . esc_attr( $classe ) . ' yn-planning__filtre" href="' . esc_url( $url ) . '"'
		. ( $actif ? ' aria-current="true"' : '' ) . '>' . $texte . '</a></li>';
}

/**
 * Carte d'un chiffre clé.
 *
 * @param string $label    Surtitre.
 * @param string $valeur   Valeur.
 * @param string $detail   Détail.
 * @param string $variante Variante (warn).
 */
function chiffre( string $label, string $valeur, string $detail, string $variante = '' ): string {
	$html  = '<div class="yn-card yn-planning__chiffre' . ( '' !== $variante ? ' yn-planning__chiffre--' . esc_attr( $variante ) : '' ) . '">';
	$html .= '<p class="yn-label">' . esc_html( $label ) . '</p>';
	$html .= '<p class="yn-planning__valeur">' . esc_html( $valeur ) . '</p>';
	$html .= '<p class="yn-muted yn-planning__detail">' . esc_html( $detail ) . '</p>';
	return $html . '</div>';
}

/**
 * Cellule d'une étape du tableau.
 *
 * @param array  $ligne Ligne.
 * @param string $etape Étape.
 */
function cellule_etape( array $ligne, string $etape ): string {
	$pct = (int) $ligne['avancement'][ $etape ];
	$nom = (string) $ligne['responsables'][ $etape ]['nom'];
	if ( $pct > 0 || 'publie' === $ligne['etat'] ) {
		$texte = '<span>' . esc_html( pct( 'publie' === $ligne['etat'] ? 100 : $pct ) . ( '' !== $nom ? ' · ' . $nom : '' ) ) . '</span>';
	} else {
		$texte = '<span class="yn-muted">' . esc_html( ( '' !== $nom ? $nom . ' · ' : '' ) . __( 'à faire', 'yume-core' ) ) . '</span>';
	}
	return '<div class="yn-planning__etape">' . barre( 'publie' === $ligne['etat'] ? 100 : $pct, variante_barre( $ligne, $etape ) ) . $texte . '</div>';
}

/**
 * Rendu du bloc « Planning » (page /planning/).
 *
 * @param array $attributs Attributs (showFilters).
 */
function rendu_planning( array $attributs ): string {
	$avec_filtres = ! isset( $attributs['showFilters'] ) || (bool) $attributs['showFilters'];
	$filtres      = $avec_filtres ? filtres_planning() : array(
		'type'   => '',
		'etat'   => '',
		'oeuvre' => 0,
	);
	$base         = yume_get_planning(
		array(
			'type'      => $filtres['type'],
			'oeuvre_id' => $filtres['oeuvre'],
		)
	);
	$lignes       = '' === $filtres['etat'] ? $base : array_values(
		array_filter(
			$base,
			static function ( array $l ) use ( $filtres ): bool {
				return $l['etat'] === $filtres['etat'];
			}
		)
	);
	$stats        = statistiques( $base );
	$journal      = grouper_journal(
		lire_journal(
			array(
				'public'    => true,
				'oeuvre_id' => $filtres['oeuvre'],
				'limit'     => 40,
			)
		),
		false,
		6
	);

	$html = '<div ' . attributs_racine( 'yn-planning', array( 'id' => 'yn-planning' ) ) . '>';

	// En-tête : fraîcheur, présentation, filtres.
	$html .= '<div class="yn-planning__tete"><div class="yn-planning__intro">';
	if ( $journal ) {
		/* translators: %s : durée (« il y a 2 h ») */
		$html .= '<p class="yn-label">' . esc_html( sprintf( __( 'Mis à jour %s par l’équipe', 'yume-core' ), il_y_a( $journal[0]['ts'] ) ) ) . '</p>';
	}
	$intro = __( 'Chaque tome passe par trois étapes : traduction, relecture, édition.', 'yume-core' );
	$jours = phrase_jours_sortie();
	if ( '' !== $jours ) {
		/* translators: %s : jours de sortie */
		$intro .= ' ' . sprintf( __( 'Les jours de sortie habituels sont %s.', 'yume-core' ), $jours );
	}
	$intro .= ' ' . __( 'Les dates sont indicatives : la relecture décide.', 'yume-core' );
	$html  .= '<p class="yn-muted yn-planning__texte">' . esc_html( $intro ) . '</p></div>';

	if ( $avec_filtres ) {
		$html .= '<nav class="yn-planning__filtres" aria-label="' . esc_attr__( 'Filtrer le planning', 'yume-core' ) . '">';
		$html .= '<ul class="yn-planning__groupe" aria-label="' . esc_attr__( 'Type d’œuvre', 'yume-core' ) . '">';
		$html .= lien_filtre( esc_html__( 'Tout', 'yume-core' ), url_filtre( $filtres, array( 'type' => '' ) ), '' === $filtres['type'] );
		foreach ( types_pluriel() as $slug => $nom ) {
			$html .= lien_filtre( esc_html( $nom ), url_filtre( $filtres, array( 'type' => $slug ) ), $slug === $filtres['type'] );
		}
		$html .= '</ul><ul class="yn-planning__groupe" aria-label="' . esc_attr__( 'État', 'yume-core' ) . '">';
		foreach ( array( 'a_lheure', 'en_retard', 'bloque' ) as $etat ) {
			$actif = $etat === $filtres['etat'];
			$texte = '<span aria-hidden="true">' . esc_html( icone_etat( $etat ) ) . '</span> ' . esc_html( etats()[ $etat ] );
			$html .= lien_filtre( $texte, url_filtre( $filtres, array( 'etat' => $actif ? '' : $etat ) ), $actif, variante_etat( $etat ) );
		}
		$html .= '</ul>';
		$html .= '<p class="yn-planning__flux"><a class="yn-btn yn-btn--sm" href="' . esc_url( rest_url( REST_NS . '/planning/journal?format=rss' ) ) . '">' . esc_html__( 'Flux RSS', 'yume-core' ) . '</a>';
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( rest_url( REST_NS . '/planning' ) ) . '">' . esc_html__( 'JSON', 'yume-core' ) . '</a></p>';
		$html .= '</nav>';
	}
	$html .= '</div>';

	if ( $filtres['oeuvre'] ) {
		$html .= '<p class="yn-planning__actif">' . esc_html(
			sprintf(
				/* translators: %s : œuvre */
				__( 'Planning de %s', 'yume-core' ),
				titre_brut( $filtres['oeuvre'] )
			)
		) . ' <a href="' . esc_url( url_filtre( $filtres, array( 'oeuvre' => '' ) ) ) . '">' . esc_html__( 'Voir toutes les œuvres', 'yume-core' ) . '</a></p>';
	}

	// Chiffres clés.
	$html  .= '<section class="yn-planning__chiffres" aria-labelledby="yn-planning-chiffres">';
	$html  .= '<h2 id="yn-planning-chiffres" class="yn-visually-hidden">' . esc_html__( 'En bref', 'yume-core' ) . '</h2>';
	$labels = array( __( 'Prochaine sortie', 'yume-core' ), __( 'Puis', 'yume-core' ) );
	for ( $i = 0; $i < 2; $i++ ) {
		$p     = $stats['prochaines'][ $i ] ?? null;
		$html .= $p
			? chiffre( $labels[ $i ], date_cible_lisible( $p['date_cible'], true ), $p['oeuvre'] . ' · ' . libelle_prochaine_sortie( $p ) )
			: chiffre( $labels[ $i ], '—', 0 === $i ? __( 'Aucune date annoncée', 'yume-core' ) : __( 'À suivre', 'yume-core' ) );
	}
	$html .= chiffre(
		__( 'En cours', 'yume-core' ),
		/* translators: %d : nombre de tomes */
		sprintf( _n( '%d tome', '%d tomes', $stats['en_cours'], 'yume-core' ), $stats['en_cours'] ),
		sprintf(
			/* translators: 1: œuvres, 2: membres */
			__( 'sur %1$s · %2$s', 'yume-core' ),
			/* translators: %d : œuvres */
			sprintf( _n( '%d œuvre', '%d œuvres', $stats['oeuvres'], 'yume-core' ), $stats['oeuvres'] ),
			/* translators: %d : membres */
			sprintf( _n( '%d membre actif', '%d membres actifs', $stats['membres'], 'yume-core' ), $stats['membres'] )
		)
	);
	if ( $stats['retards'] ) {
		$detail = $stats['rappel_recent'] ? __( 'rappel envoyé au responsable', 'yume-core' ) : __( 'l’équipe est prévenue', 'yume-core' );
	} else {
		$detail = $stats['bloques']
			/* translators: %d : tomes bloqués */
			? sprintf( _n( '%d tome bloqué', '%d tomes bloqués', $stats['bloques'], 'yume-core' ), $stats['bloques'] )
			: __( 'tout est à l’heure', 'yume-core' );
	}
	$html .= chiffre( __( 'Retards', 'yume-core' ), (string) $stats['retards'], $detail, $stats['retards'] ? 'warn' : '' );
	$html .= '</section>';

	// Tableau.
	$html .= '<section class="yn-card yn-planning__tableau" aria-labelledby="yn-planning-tableau">';
	$html .= '<h2 id="yn-planning-tableau" class="yn-visually-hidden">' . esc_html__( 'Avancement des tomes', 'yume-core' ) . '</h2>';
	if ( ! $lignes ) {
		$html .= '<p class="yn-muted yn-planning__vide">' . esc_html__( 'Aucun tome ne correspond à ces filtres.', 'yume-core' );
		if ( $filtres['type'] || $filtres['etat'] || $filtres['oeuvre'] ) {
			$html .= ' <a href="' . esc_url( url_base_planning() . '#yn-planning' ) . '">' . esc_html__( 'Afficher tout le planning', 'yume-core' ) . '</a>';
		}
		$html .= '</p>';
	} else {
		$etapes = yume_etapes();
		$cols   = array(
			'traduction' => $etapes['traduction'],
			'relecture'  => $etapes['relecture'],
			'edition'    => $etapes['edition'],
		);
		$html  .= '<table class="yn-planning__table" role="table"><caption class="yn-visually-hidden">' . esc_html__( 'Avancement de chaque tome en cours : étapes, sortie prévue, état et dernière mise à jour.', 'yume-core' ) . '</caption>';
		$html  .= '<thead role="rowgroup"><tr class="yn-label" role="row"><th scope="col" role="columnheader">' . esc_html__( 'Œuvre · tome', 'yume-core' ) . '</th>';
		foreach ( $cols as $nom ) {
			$html .= '<th scope="col" role="columnheader">' . esc_html( $nom ) . '</th>';
		}
		$html .= '<th scope="col" role="columnheader">' . esc_html__( 'Sortie prévue', 'yume-core' ) . '</th><th scope="col" role="columnheader">' . esc_html__( 'État', 'yume-core' ) . '</th><th scope="col" role="columnheader">' . esc_html__( 'Dernière maj', 'yume-core' ) . '</th></tr></thead><tbody role="rowgroup">';
		foreach ( $lignes as $l ) {
			$oeuvre = esc_html( $l['oeuvre'] );
			if ( '' !== $l['url_oeuvre'] ) {
				$oeuvre = '<a href="' . esc_url( $l['url_oeuvre'] ) . '">' . $oeuvre . '</a>';
			}
			$html .= '<tr class="yn-planning__ligne yn-planning__ligne--' . esc_attr( $l['etat'] ) . '" role="row">';
			$html .= '<th scope="row" role="rowheader"><span class="yn-planning__oeuvre">' . $oeuvre . '</span><span class="yn-muted yn-planning__tome">' . esc_html( complement_tome( $l ) ) . '</span></th>';
			foreach ( $cols as $etape => $nom ) {
				$html .= '<td role="cell" data-label="' . esc_attr( $nom ) . '">' . cellule_etape( $l, $etape ) . '</td>';
			}
			if ( 'publie' === $l['etat'] ) {
				$sortie = '' !== $l['date_sortie'] ? format_fr( ts_gmt( $l['date_sortie'] ), 'D j M' ) : '';
				$sortie = '' !== $l['url'] ? '<a href="' . esc_url( $l['url'] ) . '">' . esc_html( $sortie ) . '</a>' : esc_html( $sortie );
			} elseif ( '' === $l['date_cible'] ) {
				$sortie = '<span class="yn-muted">' . esc_html__( 'non planifié', 'yume-core' ) . '</span>';
			} else {
				$sortie = esc_html( date_cible_lisible( $l['date_cible'], 'en_retard' === $l['etat'] ) );
			}
			$html .= '<td role="cell" data-label="' . esc_attr__( 'Sortie prévue', 'yume-core' ) . '">' . $sortie . '</td>';
			$html .= '<td role="cell" data-label="' . esc_attr__( 'État', 'yume-core' ) . '">' . cellule_etat( $l ) . '</td>';
			$html .= '<td role="cell" data-label="' . esc_attr__( 'Dernière maj', 'yume-core' ) . '"><span class="yn-muted">' . esc_html( il_y_a( (int) $l['ts_activite'] ) ) . '</span></td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';
	}
	$html .= '</section>';

	// Journal et légende.
	$html .= '<div class="yn-planning__bas">';
	$html .= '<section class="yn-card yn-planning__journal" id="yn-planning-journal" aria-labelledby="yn-planning-journal-titre">';
	$html .= '<h2 id="yn-planning-journal-titre" class="yn-label">' . esc_html__( 'Journal des mises à jour', 'yume-core' ) . '</h2>';
	$html .= $journal ? liste_journal( $journal, 'yn-planning__entrees yn-journal' ) : '<p class="yn-muted">' . esc_html__( 'Aucune mise à jour pour le moment.', 'yume-core' ) . '</p>';
	$html .= '</section>';
	$html .= '<section class="yn-card yn-planning__legende" aria-labelledby="yn-planning-legende">';
	$html .= '<h2 id="yn-planning-legende" class="yn-label">' . esc_html__( 'Comment lire ce planning', 'yume-core' ) . '</h2><ul>';
	$html .= '<li>' . pastille( 'a_lheure' ) . ' ' . esc_html__( 'la date cible tient.', 'yume-core' ) . '</li>';
	$html .= '<li>' . pastille( 'en_retard' ) . ' ' . esc_html(
		sprintf(
			/* translators: %d : jours */
			_n( 'date dépassée ou aucune nouvelle depuis %d jour.', 'date dépassée ou aucune nouvelle depuis %d jours.', seuil_inactivite(), 'yume-core' ),
			seuil_inactivite()
		)
	) . '</li>';
	$invitation = function_exists( 'yume_setting' ) ? (string) yume_setting( 'discord_invite', '' ) : '';
	$html      .= '<li>' . pastille( 'bloque' ) . ' ' . esc_html__( 'il manque quelqu’un :', 'yume-core' ) . ' ';
	$html      .= '' !== $invitation ? '<a href="' . esc_url( $invitation ) . '">' . esc_html__( 'rejoindre l’équipe', 'yume-core' ) . '</a>.' : esc_html__( 'rejoignez l’équipe !', 'yume-core' );
	$html      .= '</li>';
	$html      .= '<li>' . pastille( 'publie' ) . ' ' . esc_html__( 'le tome est sorti : bonne lecture !', 'yume-core' ) . '</li>';
	$html      .= '</ul></section></div>';

	return $html . '</div>';
}

/*
 * -----------------------------------------------------------------------------
 * yume/oeuvre-planning
 * -----------------------------------------------------------------------------
 */

/**
 * Œuvre du contexte (page courante ou contexte du bloc).
 *
 * @param mixed $bloc Instance WP_Block.
 */
function oeuvre_du_contexte( $bloc ): int {
	$id = 0;
	if ( $bloc instanceof \WP_Block && ! empty( $bloc->context['postId'] ) ) {
		$id = (int) $bloc->context['postId'];
	}
	if ( ! $id ) {
		$id = (int) get_queried_object_id();
	}
	if ( ! $id ) {
		$id = (int) get_the_ID();
	}
	return $id ? yume_get_oeuvre_id( $id ) : 0;
}

/**
 * Rendu de la carte « Planning de l'œuvre ».
 *
 * @param array $attributs Attributs.
 * @param mixed $bloc      Instance WP_Block.
 */
function rendu_oeuvre_planning( array $attributs, $bloc = null ): string {
	$oeuvre_id = oeuvre_du_contexte( $bloc );
	if ( ! $oeuvre_id ) {
		return message_editeur( 'yn-oeuvre-planning', __( 'Planning de l’œuvre : s’affiche sur la fiche d’une œuvre.', 'yume-core' ) );
	}
	$lignes = yume_get_planning(
		array(
			'oeuvre_id' => $oeuvre_id,
			'a_venir'   => true,
			'public'    => 'publish' === get_post_status( $oeuvre_id ),
		)
	);
	if ( ! $lignes ) {
		return message_editeur( 'yn-oeuvre-planning', __( 'Planning de l’œuvre : aucun tome en préparation.', 'yume-core' ) );
	}
	// Le tome le plus proche de la sortie : daté d'abord, sinon le plus petit numéro.
	usort(
		$lignes,
		static function ( array $a, array $b ): int {
			if ( ( '' === $a['date_cible'] ) !== ( '' === $b['date_cible'] ) ) {
				return '' === $a['date_cible'] ? 1 : -1;
			}
			$cmp = strcmp( $a['date_cible'], $b['date_cible'] );
			return 0 !== $cmp ? $cmp : ( ( $a['numero'] ?? 0 ) <=> ( $b['numero'] ?? 0 ) );
		}
	);
	$l     = array_shift( $lignes );
	$titre = 'yn-oeuvre-planning-' . $oeuvre_id;

	$html   = '<section ' . attributs_racine( 'yn-oeuvre-planning yn-card', array( 'aria-labelledby' => $titre ) ) . '>';
	$html  .= '<h2 class="yn-label" id="' . esc_attr( $titre ) . '">' . esc_html__( 'Planning de l’œuvre', 'yume-core' ) . '</h2>';
	$sortie = '' !== $l['date_cible']
		/* translators: %s : date */
		? sprintf( __( 'sortie prévue %s', 'yume-core' ), date_cible_lisible( $l['date_cible'] ) )
		: __( 'sortie non planifiée', 'yume-core' );
	$html .= '<p class="yn-oeuvre-planning__tome">' . esc_html( libelle_prochaine_sortie( $l ) . ( '' !== $l['titre'] ? ' : ' . $l['titre'] : '' ) . ' · ' . $sortie ) . '</p>';
	$html .= '<ul class="yn-oeuvre-planning__etapes">';
	foreach ( ETAPES_TRAVAIL as $etape ) {
		$pct   = (int) $l['avancement'][ $etape ];
		$nom   = (string) $l['responsables'][ $etape ]['nom'];
		$html .= '<li><span class="yn-oeuvre-planning__ligne"><span>' . esc_html( yume_etapes()[ $etape ] . ( '' !== $nom ? ' · ' . $nom : '' ) ) . '</span>';
		$html .= '<span>' . ( $pct > 0 ? esc_html( pct( $pct ) ) : '<span aria-hidden="true">—</span><span class="yn-visually-hidden">' . esc_html__( 'pas commencée', 'yume-core' ) . '</span>' ) . '</span></span>';
		$html .= barre( $pct, variante_barre( $l, $etape ) ) . '</li>';
	}
	$html .= '</ul>';
	$html .= '<p class="yn-oeuvre-planning__etat">' . pastille( $l['etat'], 'a_lheure' === $l['etat'] ? '' : texte_etat( $l ) ) . '</p>';
	if ( $lignes ) {
		$autres = array();
		foreach ( array_slice( $lignes, 0, 3 ) as $autre ) {
			$etape    = etape_de_travail( $autre['etape'] );
			$autres[] = $autre['tome'] . ' (' . libelle_etape_min( $etape ) . ' ' . pct( (int) ( $autre['avancement'][ $etape ] ?? 0 ) ) . ')';
		}
		/* translators: %s : tomes */
		$html .= '<p class="yn-muted yn-oeuvre-planning__autres">' . esc_html( sprintf( __( 'Aussi en préparation : %s', 'yume-core' ), implode( ', ', $autres ) ) ) . '</p>';
	}
	$html .= '<a class="yn-oeuvre-planning__lien" href="' . esc_url( add_query_arg( 'oeuvre', $oeuvre_id, yume_url_page( 'planning' ) ) . '#yn-planning-journal' ) . '">' . esc_html__( 'Historique des mises à jour', 'yume-core' ) . ' <span aria-hidden="true">→</span></a>';
	return $html . '</section>';
}
