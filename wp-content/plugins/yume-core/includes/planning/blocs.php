<?php
/**
 * Blocs du planning (§10) : enregistrement, composants partagés (pastilles d'état, barres,
 * journal) et rendu des blocs publics yume/upcoming, yume/planning (onglets Tableau, Chapitres :
 * ?vue=chapitres, et Calendrier : ?vue=calendrier), yume/calendrier et yume/oeuvre-planning.
 * Le bloc yume/planning-accueil et la vitrine (à la une, chapitres) sont rendus par vitrine.php.
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
	foreach ( array( 'upcoming', 'planning-accueil', 'planning', 'calendrier', 'oeuvre-planning', 'team-dashboard', 'team-members' ) as $bloc ) {
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
 * Le tome de cette ligne a-t-il une sortie programmée (statut future) pas encore parue ?
 *
 * @param array $ligne Ligne (programme, etat).
 */
function est_programme( array $ligne ): bool {
	return ! empty( $ligne['programme'] ) && 'publie' !== ( $ligne['etat'] ?? '' );
}

/**
 * Libellé d'une sortie programmée (« Programmé le sam. 3 oct. »).
 *
 * @param array $ligne Ligne.
 */
function texte_programme( array $ligne ): string {
	if ( function_exists( __NAMESPACE__ . '\\libelle_etat_ligne' ) ) {
		return libelle_etat_ligne( $ligne );
	}
	return __( 'Programmé', 'yume-core' );
}

/**
 * Pastille de l'état d'une ligne : « Programmé le … » (style distinct) pour une sortie
 * programmée, sinon la pastille de l'état (détaillée hors « à l'heure » si demandé).
 *
 * @param array $ligne  Ligne.
 * @param bool  $detail Texte détaillé (« En retard de 3 j », « Bloqué · raison »).
 * @param array $attrs  Attributs supplémentaires.
 */
function pastille_ligne( array $ligne, bool $detail = true, array $attrs = array() ): string {
	if ( ! est_programme( $ligne ) ) {
		return pastille( $ligne['etat'], $detail && 'a_lheure' !== $ligne['etat'] ? texte_etat( $ligne ) : '', $attrs );
	}
	$html = '<span class="yn-chip yn-chip--new yn-chip--programme"';
	foreach ( $attrs as $nom => $valeur ) {
		$html .= ' ' . esc_attr( $nom ) . '="' . esc_attr( (string) $valeur ) . '"';
	}
	return $html . '><span aria-hidden="true">◷</span> ' . esc_html( texte_programme( $ligne ) ) . '</span>';
}

/**
 * Texte de l'état d'une ligne : « En retard de 3 j », « Sans nouvelles depuis 16 j »,
 * « Bloqué · relecteur manquant », « Programmé le sam. 3 oct. ».
 *
 * @param array $ligne Ligne.
 */
function texte_etat( array $ligne ): string {
	$etats = etats();
	if ( est_programme( $ligne ) && 'a_lheure' === $ligne['etat'] ) {
		return texte_programme( $ligne );
	}
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
	if ( est_programme( $ligne ) && 'bloque' !== $ligne['etat'] ) {
		return pastille_ligne( $ligne );
	}
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
 * @param array $ligne     Ligne.
 * @param bool  $chapitres Ajouter le prochain chapitre (faux quand la ligne « En cours · N
 *                         chapitres sur M » le dit déjà, voir chapitres_en_cours()).
 */
function complement_tome( array $ligne, bool $chapitres = true ): string {
	$parties = array( $ligne['tome'] );
	if ( '' !== $ligne['titre'] ) {
		$parties[] = $ligne['titre'];
	}
	$chap = $ligne['chapitres'];
	if ( $chapitres && 'publish' === $ligne['statut'] && $chap['total'] > $chap['publies'] && 'publie' !== $ligne['etat'] ) {
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
 * @param array         $entrees Entrées regroupées.
 * @param string        $classe  Classe de la liste.
 * @param bool          $court   Date courte (heure du jour, « hier », « 20 sept. »).
 * @param callable|null $lien    Adresse du tome de chaque entrée (tome_id => URL) : le nom du
 *                               tome devient un lien (journal de l'équipe filtré par tome).
 */
function liste_journal( array $entrees, string $classe, bool $court = false, ?callable $lien = null ): string {
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
			$url   = $lien && $e['tome_id'] ? (string) $lien( (int) $e['tome_id'] ) : '';
			$html .= ' · ' . ( '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $e['cible'] ) . '</a>' : esc_html( $e['cible'] ) );
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
	limiter_cache_page();
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
			$date = date_cible_lisible( $l['date_cible'], false, (string) ( $l['heure_cible'] ?? '' ) );
		}
		$titre = '<b>' . esc_html( $l['oeuvre'] ) . '</b>';
		if ( '' !== $l['url_oeuvre'] ) {
			$titre = '<a href="' . esc_url( $l['url_oeuvre'] ) . '">' . $titre . '</a>';
		}
		$html .= '<li class="yn-upcoming__item">';
		$html .= '<span class="yn-label yn-upcoming__date">' . esc_html( $date ) . '</span>';
		$html .= '<span class="yn-upcoming__titre">' . $titre . ' · ' . esc_html( libelle_prochaine_sortie( $l ) ) . '</span>';
		$html .= '<span class="yn-upcoming__etat">' . ( est_programme( $l ) && 'a_lheure' === $l['etat'] ? pastille_ligne( $l ) : pastille( $l['etat'], $texte ) ) . '</span>';
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

/** Durée maximale (secondes) d'une page affichant le planning dans le cache Batcache. */
const CACHE_PAGE_PLANNING = 60;

/**
 * Page affichant le planning : durée courte dans le cache de pages Batcache (WordPress.com), pour
 * que les visiteurs anonymes ne voient pas un avancement et des « il y a… » périmés. Sans
 * Batcache, rien.
 */
function limiter_cache_page(): void {
	global $batcache;
	if ( is_object( $batcache ) && isset( $batcache->max_age ) && (int) $batcache->max_age > CACHE_PAGE_PLANNING ) {
		$batcache->max_age = CACHE_PAGE_PLANNING; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- réglage prévu de Batcache.
	}
}

/**
 * Adresses publiques qui affichent le planning d'un tome : page du planning, accueil (prochaines
 * sorties), fiche de l'œuvre.
 *
 * @param int $tome_id Tome (0 : pages communes seulement).
 * @return string[]
 */
function adresses_pages_planning( int $tome_id = 0 ): array {
	$adresses = array( yume_url_page( 'planning' ), home_url( '/' ) );
	$oeuvre   = $tome_id ? yume_get_oeuvre_id( $tome_id ) : 0;
	if ( $oeuvre && 'publish' === get_post_status( $oeuvre ) ) {
		$adresses[] = (string) get_permalink( $oeuvre );
	}
	return array_values( array_unique( array_filter( $adresses ) ) );
}

/**
 * Planning modifié (avancement, pause, sortie) : pages concernées purgées de Batcache si
 * batcache_clear_url() existe ; sinon elles expirent d'elles-mêmes (CACHE_PAGE_PLANNING).
 *
 * @param int|mixed $tome_id Tome.
 */
function purger_pages_planning( $tome_id = 0 ): void {
	if ( ! function_exists( 'batcache_clear_url' ) ) {
		return;
	}
	foreach ( adresses_pages_planning( (int) $tome_id ) as $adresse ) {
		batcache_clear_url( $adresse );
	}
}
add_action( 'yume_planning_mis_a_jour', __NAMESPACE__ . '\\purger_pages_planning' );
add_action( 'yume_planning_pause', __NAMESPACE__ . '\\purger_pages_planning' );
add_action( 'yume_tome_publie', __NAMESPACE__ . '\\purger_pages_planning' );

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
	$args = array_filter( array_merge( $filtres, array( 'vue' => vue_planning() ), $changer ) );
	return add_query_arg( $args, url_base_planning() ) . '#yn-planning';
}

/**
 * Vue du planning public : 'calendrier' (?vue=calendrier), 'chapitres' (?vue=chapitres) ou ''
 * (tableau).
 */
function vue_planning(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage en lecture seule.
	$vue = isset( $_GET['vue'] ) && is_string( $_GET['vue'] ) ? sanitize_key( wp_unslash( $_GET['vue'] ) ) : '';
	return in_array( $vue, array( 'calendrier', 'chapitres' ), true ) ? $vue : '';
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
 * Cellule d'une étape du tableau : pourcentage (et responsable), puis une petite barre
 * d'avancement lisible par les lecteurs d'écran (role="progressbar" nommé par l'étape : la
 * colonne reste compréhensible quand le tableau se présente en cartes).
 *
 * @param array  $ligne Ligne.
 * @param string $etape Étape.
 */
function cellule_etape( array $ligne, string $etape ): string {
	$pct    = 'publie' === $ligne['etat'] ? 100 : max( 0, min( 100, (int) $ligne['avancement'][ $etape ] ) );
	$nom    = (string) $ligne['responsables'][ $etape ]['nom'];
	$etapes = yume_etapes();
	$texte  = '<span class="yn-planning__pct' . ( 0 === $pct ? ' yn-muted' : '' ) . '">' . esc_html( pct( $pct ) . ( '' !== $nom ? ' · ' . $nom : '' ) ) . '</span>';
	$barre  = '<span class="yn-planning__progression" role="progressbar" aria-label="' . esc_attr( (string) ( $etapes[ $etape ] ?? $etape ) ) . '"'
		. ' aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-valuetext="' . esc_attr( pct( $pct ) ) . '">'
		. barre( $pct, variante_barre( $ligne, $etape ) ) . '</span>';
	return '<div class="yn-planning__etape">' . $texte . $barre . '</div>';
}

/**
 * Rendu du bloc « Planning » (page /planning/).
 *
 * @param array $attributs Attributs (showFilters).
 */
function rendu_planning( array $attributs ): string {
	limiter_cache_page();
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
	$html  .= '<p class="yn-muted yn-planning__texte">' . esc_html( $intro ) . '</p>';
	// Membre de l'équipe connecté : passerelle vers la gestion du planning (espace équipe).
	$equipe = current_user_can( 'yume_voir_equipe' ) && function_exists( __NAMESPACE__ . '\\url_vue_equipe' );
	if ( $equipe ) {
		$html .= '<p class="yn-planning__equipe-tete"><a class="yn-btn yn-btn--sm yn-btn--primary" href="' . esc_url( url_vue_equipe( 'planning' ) ) . '">' . esc_html__( 'Modifier dans l’espace équipe', 'yume-core' ) . '</a></p>';
	}
	$html .= '</div>';

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
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( rest_url( REST_NS . '/planning' ) ) . '">' . esc_html__( 'JSON', 'yume-core' ) . '</a>';
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_ics( $filtres['oeuvre'], true ) ) . '">' . esc_html__( 'S’abonner au calendrier (ICS)', 'yume-core' ) . '</a></p>';
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

	// Prochain tome à la une (vitrine.php).
	$html .= rendu_a_la_une( prochain_tome() ?? array(), 'planning' );

	// Chiffres clés.
	$html  .= '<section class="yn-planning__chiffres" aria-labelledby="yn-planning-chiffres">';
	$html  .= '<h2 id="yn-planning-chiffres" class="yn-visually-hidden">' . esc_html__( 'En bref', 'yume-core' ) . '</h2>';
	$labels = array( __( 'Prochaine sortie', 'yume-core' ), __( 'Puis', 'yume-core' ) );
	for ( $i = 0; $i < 2; $i++ ) {
		$p     = $stats['prochaines'][ $i ] ?? null;
		$html .= $p
			? chiffre( $labels[ $i ], date_cible_lisible( $p['date_cible'], true, (string) ( $p['heure_cible'] ?? '' ) ), $p['oeuvre'] . ' · ' . libelle_prochaine_sortie( $p ) )
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

	// Onglets : tableau, chapitres ou calendrier mensuel (liens simples, sans JavaScript).
	$vue   = vue_planning();
	$html .= '<nav class="yn-planning__vues" aria-label="' . esc_attr__( 'Affichage du planning', 'yume-core' ) . '"><ul class="yn-planning__groupe">';
	$html .= lien_filtre( esc_html__( 'Tableau', 'yume-core' ), url_filtre( $filtres, array( 'vue' => '' ) ), '' === $vue );
	$html .= lien_filtre( esc_html__( 'Chapitres', 'yume-core' ), url_filtre( $filtres, array( 'vue' => 'chapitres' ) ), 'chapitres' === $vue );
	$html .= lien_filtre( esc_html__( 'Calendrier', 'yume-core' ), url_filtre( $filtres, array( 'vue' => 'calendrier' ) ), 'calendrier' === $vue );
	$html .= '</ul></nav>';

	if ( 'chapitres' === $vue ) {
		$html .= rendu_file_chapitres(
			file_chapitres(
				array(
					'prochains'     => 10,
					'publies'       => 10,
					'jours_publies' => 21,
					'oeuvre_id'     => $filtres['oeuvre'],
				)
			),
			'planning'
		);
	} elseif ( 'calendrier' === $vue ) {
		// Rendu par le bloc : sa feuille de style est ainsi chargée.
		$html .= render_block(
			array(
				'blockName'    => 'yume/calendrier',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
	} else {
		$html .= tableau_planning( $lignes, $filtres, $equipe );
	}

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
	$html      .= '<li><span class="yn-chip yn-chip--new yn-chip--programme"><span aria-hidden="true">◷</span> ' . esc_html__( 'Programmé', 'yume-core' ) . '</span> ' . esc_html__( 'la sortie est programmée : le tome paraîtra tout seul à cette date.', 'yume-core' ) . '</li>';
	$html      .= '<li>' . pastille( 'publie' ) . ' ' . esc_html__( 'le tome est sorti : bonne lecture !', 'yume-core' ) . '</li>';
	$html      .= '<li>' . pastille_en_cours() . ' ' . esc_html__( 'le tome sort chapitre par chapitre, lisible en ligne au fil des sorties.', 'yume-core' ) . '</li>';
	$html      .= '<li>' . pastille_chapitre_programme( esc_html__( 'Chapitre programmé', 'yume-core' ) ) . ' ' . esc_html__( 'la date et l’heure de sortie du prochain chapitre.', 'yume-core' ) . '</li>';
	$html      .= '</ul></section></div>';

	return $html . '</div>';
}

/**
 * Tableau d'avancement du planning public (vue par défaut).
 *
 * @param array $lignes  Lignes filtrées.
 * @param array $filtres Filtres courants.
 * @param bool  $equipe  Membre de l'équipe connecté (liens « Modifier »).
 */
function tableau_planning( array $lignes, array $filtres, bool $equipe ): string {
	$html  = '<section class="yn-card yn-planning__tableau" aria-labelledby="yn-planning-tableau">';
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
		// Tomes publiés chapitre par chapitre : leurs prochains chapitres programmés, en une requête.
		$en_cours = array();
		foreach ( $lignes as $l ) {
			if ( est_en_cours_de_publication( $l ) ) {
				$en_cours[ (int) $l['tome_id'] ] = true;
			}
		}
		$programmes = chapitres_programmes( array_keys( $en_cours ) );
		foreach ( $lignes as $l ) {
			$tid    = (int) $l['tome_id'];
			$cours  = isset( $en_cours[ $tid ] );
			$oeuvre = esc_html( $l['oeuvre'] );
			if ( '' !== $l['url_oeuvre'] ) {
				$oeuvre = '<a href="' . esc_url( $l['url_oeuvre'] ) . '">' . $oeuvre . '</a>';
			}
			$html .= '<tr class="yn-planning__ligne yn-planning__ligne--' . esc_attr( $l['etat'] ) . ( $cours ? ' yn-planning__ligne--en-cours' : '' ) . '" role="row">';
			$html .= '<th scope="row" role="rowheader"><span class="yn-planning__oeuvre">' . $oeuvre . '</span><span class="yn-muted yn-planning__tome">' . esc_html( complement_tome( $l, ! $cours ) ) . '</span>';
			if ( $cours ) {
				$html .= chapitres_en_cours( $l, $programmes[ $tid ] ?? null );
			}
			if ( $equipe ) {
				$html .= '<a class="yn-planning__modifier" href="' . esc_url( url_vue_equipe( 'planning', array( 'tome' => $tid ) ) . '#yn-tome-' . $tid ) . '">' . esc_html__( 'Modifier dans l’espace équipe', 'yume-core' ) . '<span class="yn-visually-hidden"> : ' . esc_html( $l['oeuvre'] . ' · ' . $l['tome'] ) . '</span></a>';
			}
			$html .= '</th>';
			foreach ( $cols as $etape => $nom ) {
				$html .= '<td role="cell" data-label="' . esc_attr( $nom ) . '">' . cellule_etape( $l, $etape ) . '</td>';
			}
			if ( 'publie' === $l['etat'] ) {
				$sortie = '' !== $l['date_sortie'] ? format_fr( ts_gmt( $l['date_sortie'] ), 'D j M' ) : '';
				$sortie = '' !== $l['url'] ? '<a href="' . esc_url( $l['url'] ) . '">' . esc_html( $sortie ) . '</a>' : esc_html( $sortie );
			} elseif ( '' === $l['date_cible'] ) {
				$sortie = '<span class="yn-muted">' . esc_html__( 'non planifié', 'yume-core' ) . '</span>';
			} else {
				// Heure de sortie seulement pour une date à venir (une date dépassée n'en a plus besoin).
				$retard = 'en_retard' === $l['etat'];
				$sortie = esc_html( date_cible_lisible( $l['date_cible'], $retard, $retard ? '' : (string) ( $l['heure_cible'] ?? '' ) ) );
			}
			$html .= '<td role="cell" data-label="' . esc_attr__( 'Sortie prévue', 'yume-core' ) . '">' . $sortie . '</td>';
			$etat  = $cours ? '<div class="yn-planning__etats">' . pastille_en_cours() . ( 'publie' !== $l['etat'] ? cellule_etat( $l ) : '' ) . '</div>' : cellule_etat( $l );
			$html .= '<td role="cell" data-label="' . esc_attr__( 'État', 'yume-core' ) . '">' . $etat . '</td>';
			$html .= '<td role="cell" data-label="' . esc_attr__( 'Dernière maj', 'yume-core' ) . '"><span class="yn-muted">' . esc_html( il_y_a( (int) $l['ts_activite'] ) ) . '</span></td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table>';
	}
	return $html . '</section>';
}

/** Au-delà de ce nombre de chapitres, la mini-barre des chapitres est continue (plus de segments). */
const SEGMENTS_CHAPITRES_MAX = 40;

/**
 * Le tome de cette ligne est-il publié chapitre par chapitre (parution « en cours ») ?
 *
 * @param array $ligne Ligne (tome_id, statut).
 */
function est_en_cours_de_publication( array $ligne ): bool {
	return 'publish' === ( $ligne['statut'] ?? '' ) && function_exists( 'yume_parution_tome' ) && 'en_cours' === yume_parution_tome( (int) $ligne['tome_id'] );
}

/**
 * Pastille « En cours de publication » : le tome sort chapitre par chapitre.
 */
function pastille_en_cours(): string {
	return '<span class="yn-chip yn-chip--en-cours"><span aria-hidden="true">◐</span> ' . esc_html__( 'En cours de publication', 'yume-core' ) . '</span>';
}

/**
 * Pastille d'un chapitre programmé (« Prochain : Chapitre 3 · sam. 3 oct. 18:00 »).
 *
 * @param string $contenu Contenu HTML, déjà échappé.
 */
function pastille_chapitre_programme( string $contenu ): string {
	return '<span class="yn-chip yn-chip--chapitre-programme"><span aria-hidden="true">◷</span> ' . $contenu . '</span>';
}

/**
 * Chapitres programmés (statut future) d'un lot de tomes, en une requête : le prochain de chaque
 * tome (le plus tôt) et leur nombre. Les chapitres retenus sont mis en cache (titre, méta).
 *
 * @param int[] $tome_ids Tomes.
 * @return array<int,array{id:int,ts:int,nombre:int}> Par tome.
 */
function chapitres_programmes( array $tome_ids ): array {
	global $wpdb;
	$tome_ids = array_values( array_unique( array_filter( array_map( 'intval', $tome_ids ) ) ) );
	if ( ! $tome_ids ) {
		return array();
	}
	$marques = implode( ', ', array_fill( 0, count( $tome_ids ), '%s' ) );
	$sql     = 'SELECT p.ID AS id, m.meta_value AS tome, p.post_date_gmt AS date_gmt'
		. " FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'yume_tome_id'"
		. " WHERE p.post_type = 'yume_chapitre' AND p.post_status = 'future' AND m.meta_value IN ($marques)"
		. ' ORDER BY p.post_date_gmt ASC, p.ID ASC';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$resultats = $wpdb->get_results( $wpdb->prepare( $sql, array_map( 'strval', $tome_ids ) ) );
	$lot       = array();
	foreach ( (array) $resultats as $r ) {
		$tome = (int) $r->tome;
		if ( ! in_array( $tome, $tome_ids, true ) ) {
			continue;
		}
		if ( ! isset( $lot[ $tome ] ) ) {
			$lot[ $tome ] = array(
				'id'     => (int) $r->id,
				'ts'     => ts_gmt( (string) $r->date_gmt ),
				'nombre' => 0,
			);
		}
		++$lot[ $tome ]['nombre'];
	}
	if ( $lot ) {
		_prime_post_caches( array_column( $lot, 'id' ), false, true );
	}
	return $lot;
}

/**
 * Date courte et heure d'une sortie, fuseau du site (« sam. 3 oct. 18:00 »).
 *
 * @param int $ts Horodatage.
 */
function date_heure_chapitre( int $ts ): string {
	if ( class_exists( '\Yume\Core\Publication\Formulaire' ) ) {
		return \Yume\Core\Publication\Formulaire::date_fr( $ts, 'court' );
	}
	return (string) wp_date( 'j/m H:i', $ts, wp_timezone() );
}

/**
 * Mini-barre des chapitres d'un tome en cours de publication (décorative : le texte voisin dit
 * « N chapitres sur M ») : un segment par chapitre prévu, plein s'il est en ligne, cerné s'il
 * est programmé ; continue au-delà de SEGMENTS_CHAPITRES_MAX chapitres.
 *
 * @param int $en_ligne   Chapitres en ligne.
 * @param int $programmes Chapitres programmés.
 * @param int $prevus     Chapitres prévus.
 */
function segments_chapitres( int $en_ligne, int $programmes, int $prevus ): string {
	if ( $prevus > SEGMENTS_CHAPITRES_MAX ) {
		return '<span class="yn-planning__segments yn-planning__segments--continu" aria-hidden="true">' . barre( (int) round( 100 * $en_ligne / $prevus ) ) . '</span>';
	}
	$html = '<span class="yn-planning__segments" aria-hidden="true">';
	for ( $i = 0; $i < $prevus; $i++ ) {
		if ( $i < $en_ligne ) {
			$html .= '<span class="est-en-ligne"></span>';
		} elseif ( $i < $en_ligne + $programmes ) {
			$html .= '<span class="est-programme"></span>';
		} else {
			$html .= '<span></span>';
		}
	}
	return $html . '</span>';
}

/**
 * Ligne « chapitres » sous le titre d'un tome publié chapitre par chapitre : « En cours ·
 * 3 chapitres sur 12 » et sa mini-barre (« 3 chapitres en ligne » sans nombre prévu), puis
 * « Prochain : Chapitre 4 · sam. 3 oct. 18:00 » si un chapitre est programmé.
 *
 * @param array      $ligne     Ligne.
 * @param array|null $programme Chapitres programmés du tome (voir chapitres_programmes()).
 */
function chapitres_en_cours( array $ligne, ?array $programme ): string {
	$en_ligne = (int) ( $ligne['chapitres']['publies'] ?? 0 );
	$prevus   = max( 0, (int) get_post_meta( (int) $ligne['tome_id'], 'yume_chapitres_prevus', true ) );
	if ( $prevus > 0 ) {
		$prevus = max( $prevus, $en_ligne );
		/* translators: 1: chapitres en ligne, 2: chapitres prévus */
		$texte = sprintf( _n( '%1$d chapitre sur %2$d', '%1$d chapitres sur %2$d', $en_ligne, 'yume-core' ), $en_ligne, $prevus );
	} else {
		/* translators: %d : chapitres en ligne */
		$texte = sprintf( _n( '%d chapitre en ligne', '%d chapitres en ligne', $en_ligne, 'yume-core' ), $en_ligne );
	}
	$html  = '<span class="yn-planning__chapitres">';
	$html .= '<span class="yn-planning__decompte"><b>' . esc_html__( 'En cours', 'yume-core' ) . '</b> · ' . esc_html( $texte ) . '</span>';
	if ( $prevus > 0 ) {
		$html .= segments_chapitres( $en_ligne, $programme ? (int) $programme['nombre'] : 0, $prevus );
	}
	$html .= '</span>';
	if ( $programme && $programme['ts'] > 0 ) {
		$libelle = function_exists( 'yume_libelle_chapitre' ) ? yume_libelle_chapitre( (int) $programme['id'] ) : '';
		$quand   = '<time datetime="' . esc_attr( gmdate( 'c', $programme['ts'] ) ) . '">' . esc_html( date_heure_chapitre( $programme['ts'] ) ) . '</time>';
		$html   .= pastille_chapitre_programme( esc_html__( 'Prochain :', 'yume-core' ) . ' ' . ( '' !== $libelle ? esc_html( $libelle ) . ' · ' : '' ) . $quand );
	}
	return $html;
}

/*
 * -----------------------------------------------------------------------------
 * Événements datés (calendrier mensuel et flux ICS)
 * -----------------------------------------------------------------------------
 */

/** Tomes publiés repris dans le calendrier et le flux ICS : ceux des 365 derniers jours. */
const JOURS_SORTIES_CALENDRIER = 365;

/**
 * Sorties datées du planning public, une par tome : lignes de yume_get_planning() (public)
 * ayant une date, tomes parus depuis un an compris.
 *
 * Chaque événement : 'tome_id', 'oeuvre_id', 'titre' (« Œuvre T.2 »), 'nature' ('prevu' :
 * date cible indicative ; 'programme' : sortie programmée ; 'sorti' : tome paru), 'jour'
 * (Y-m-d, heure de Paris), 'ts' (horodatage UTC ; pour une prévision, date cible à l'heure de
 * sortie du tome, ou 0 si l'heure n'est pas précisée), 'etat' (texte),
 * 'url' (tome paru, sinon œuvre, sinon page du planning), 'maj' (dernière mise à jour).
 *
 * @param int    $oeuvre_id Œuvre (0 : toutes).
 * @param string $type      Type d'œuvre (slug yume_type, vide : tous).
 * @param string $etat      État (vide : tous).
 * @return array<int,array<string,mixed>> Triés par date.
 */
function evenements_calendrier( int $oeuvre_id = 0, string $type = '', string $etat = '' ): array {
	$lignes     = yume_get_planning(
		array(
			'oeuvre_id'              => $oeuvre_id,
			'type'                   => $type,
			'etat'                   => $etat,
			'public'                 => true,
			'inclure_publies_depuis' => JOURS_SORTIES_CALENDRIER,
		)
	);
	$evenements = array();
	foreach ( $lignes as $l ) {
		$tome_id = (int) $l['tome_id'];
		$ts      = 0;
		if ( 'publie' === $l['etat'] ) {
			$nature = 'sorti';
			$ts     = ts_gmt( $l['date_sortie'] );
			$jour   = $ts ? date_locale( $ts ) : '';
			$texte  = __( 'Paru', 'yume-core' );
		} elseif ( est_programme( $l ) ) {
			$nature = 'programme';
			$post   = get_post( $tome_id );
			$ts     = $post ? ts_contenu( $post, 'post_date' ) : 0;
			$jour   = (string) $l['date_programmee'];
			$texte  = texte_etat( $l );
		} else {
			$nature = 'prevu';
			$jour   = (string) $l['date_cible'];
			// Heure de sortie connue : prévision horodatée (sinon journée entière, ts = 0).
			$ts = ts_sortie( $jour, (string) ( $l['heure_cible'] ?? '' ) );
			/* translators: %s : état (« À l’heure », « En retard de 3 j ») */
			$texte = sprintf( __( 'Prévu (date indicative) · %s', 'yume-core' ), texte_etat( $l ) );
		}
		if ( ! valider_date( $jour ) || ( 'prevu' !== $nature && ! $ts ) ) {
			continue;
		}
		$url          = '' !== $l['url'] ? $l['url'] : ( '' !== $l['url_oeuvre'] ? $l['url_oeuvre'] : yume_url_page( 'planning' ) );
		$evenements[] = array(
			'tome_id'   => $tome_id,
			'oeuvre_id' => (int) $l['oeuvre_id'],
			'titre'     => trim( $l['oeuvre'] . ' ' . yume_libelle_tome( $tome_id, true ) ),
			'nature'    => $nature,
			'jour'      => $jour,
			'ts'        => $ts,
			'etat'      => $texte,
			'url'       => (string) $url,
			'maj'       => max( ts_gmt( $l['derniere_maj'] ), (int) $l['ts_activite'] ),
		);
	}
	usort(
		$evenements,
		static function ( array $a, array $b ): int {
			$cmp = strcmp( $a['jour'], $b['jour'] );
			if ( 0 === $cmp ) {
				$cmp = $a['ts'] <=> $b['ts'];
			}
			return 0 !== $cmp ? $cmp : strcmp( $a['titre'], $b['titre'] );
		}
	);
	/**
	 * Filtre les sorties datées du calendrier mensuel et du flux ICS.
	 *
	 * @param array $evenements Événements.
	 * @param int   $oeuvre_id  Œuvre (0 : toutes).
	 */
	return (array) apply_filters( 'yume_planning_evenements', $evenements, $oeuvre_id );
}

/*
 * -----------------------------------------------------------------------------
 * yume/calendrier
 * -----------------------------------------------------------------------------
 */

/**
 * Mois affiché (?mois=AAAA-MM, sinon le mois courant à Paris).
 *
 * @return string « AAAA-MM ».
 */
function mois_calendrier(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage en lecture seule.
	$mois = isset( $_GET['mois'] ) && is_string( $_GET['mois'] ) ? sanitize_text_field( wp_unslash( $_GET['mois'] ) ) : '';
	if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $mois, $m ) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100 ) {
		return $mois;
	}
	return substr( date_locale(), 0, 7 );
}

/**
 * Adresse du calendrier pour un mois (filtres et onglet courants conservés).
 *
 * @param string $mois « AAAA-MM ».
 */
function url_mois( string $mois ): string {
	$args = array_merge( filtres_planning(), array( 'vue' => vue_planning() ) );
	return add_query_arg( array_filter( $args + array( 'mois' => $mois ) ), url_base_planning() ) . '#yn-calendrier';
}

/**
 * Libellés d'une nature de sortie : icône (texte, jamais la couleur seule), libellé et
 * explication de la légende.
 *
 * @return array<string,array{icone:string,libelle:string,legende:string}>
 */
function natures_calendrier(): array {
	return array(
		'programme' => array(
			'icone'   => '◷',
			'libelle' => __( 'Programmé', 'yume-core' ),
			'legende' => __( 'sortie programmée : le tome paraîtra tout seul à cette date.', 'yume-core' ),
		),
		'prevu'     => array(
			'icone'   => '◌',
			'libelle' => __( 'Prévu', 'yume-core' ),
			'legende' => __( 'date cible indicative : la relecture décide.', 'yume-core' ),
		),
		'sorti'     => array(
			'icone'   => '✓',
			'libelle' => __( 'Paru', 'yume-core' ),
			'legende' => __( 'le tome est sorti : bonne lecture !', 'yume-core' ),
		),
	);
}

/**
 * Liste des sorties d'un jour.
 *
 * @param array $evenements Événements du jour.
 */
function liste_evenements( array $evenements ): string {
	$natures = natures_calendrier();
	$html    = '<ul class="yn-calendrier__evts">';
	foreach ( $evenements as $e ) {
		$n     = $natures[ $e['nature'] ];
		$html .= '<li class="yn-calendrier__evt yn-calendrier__evt--' . esc_attr( $e['nature'] ) . '" title="' . esc_attr( $e['etat'] ) . '">';
		$html .= '<span class="yn-calendrier__icone" aria-hidden="true">' . esc_html( $n['icone'] ) . '</span>';
		$html .= '<span class="yn-visually-hidden">' . esc_html( $n['libelle'] ) . ' : </span>';
		$html .= '<a href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['titre'] ) . '</a>';
		if ( 'prevu' !== $e['nature'] || $e['ts'] > 0 ) {
			$html .= ' <span class="yn-calendrier__heure">' . esc_html( format_fr( (int) $e['ts'], 'H:i' ) ) . '</span>';
		}
		$html .= '</li>';
	}
	return $html . '</ul>';
}

/**
 * Rendu du bloc « Calendrier des sorties » : grille mensuelle accessible (tableau), liste en
 * repli sous 600 px, navigation par ?mois=AAAA-MM sans JavaScript, abonnement ICS.
 * Filtres GET du planning (type, etat, oeuvre) respectés. Le bloc n'a pas d'attribut.
 */
function rendu_calendrier(): string {
	limiter_cache_page();
	$filtres = filtres_planning();
	$mois    = mois_calendrier();
	$annee   = (int) substr( $mois, 0, 4 );
	$num     = (int) substr( $mois, 5, 2 );
	$debut   = new \DateTimeImmutable( $mois . '-01', fuseau() );
	$nb      = (int) $debut->format( 't' );
	$decal   = (int) $debut->format( 'N' ) - 1;
	$auj     = date_locale();
	$noms    = noms_mois();
	$libelle = majuscule( $noms[ $num ] ) . ' ' . $annee;
	$prec    = $debut->modify( '-1 month' );
	$suiv    = $debut->modify( '+1 month' );

	// Sorties du mois, par jour.
	$par_jour = array();
	foreach ( evenements_calendrier( $filtres['oeuvre'], $filtres['type'], $filtres['etat'] ) as $e ) {
		if ( str_starts_with( $e['jour'], $mois . '-' ) ) {
			$par_jour[ $e['jour'] ][] = $e;
		}
	}

	$html  = '<section ' . attributs_racine(
		'yn-calendrier',
		array(
			'id'              => 'yn-calendrier',
			'aria-labelledby' => 'yn-calendrier-titre',
		)
	) . '>';
	$html .= '<div class="yn-calendrier__tete">';
	$html .= '<h2 id="yn-calendrier-titre" class="yn-calendrier__titre">' . esc_html( $libelle ) . '</h2>';
	$html .= '<nav class="yn-calendrier__nav" aria-label="' . esc_attr__( 'Changer de mois', 'yume-core' ) . '">';
	$html .= '<a class="yn-btn yn-btn--sm" rel="prev" href="' . esc_url( url_mois( $prec->format( 'Y-m' ) ) ) . '"><span aria-hidden="true">←</span> <span class="yn-visually-hidden">' . esc_html__( 'Mois précédent :', 'yume-core' ) . ' </span>' . esc_html( $noms[ (int) $prec->format( 'n' ) ] . ' ' . $prec->format( 'Y' ) ) . '</a>';
	if ( substr( $auj, 0, 7 ) !== $mois ) {
		$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_mois( substr( $auj, 0, 7 ) ) ) . '">' . esc_html__( 'Ce mois-ci', 'yume-core' ) . '</a>';
	}
	$html .= '<a class="yn-btn yn-btn--sm" rel="next" href="' . esc_url( url_mois( $suiv->format( 'Y-m' ) ) ) . '"><span class="yn-visually-hidden">' . esc_html__( 'Mois suivant :', 'yume-core' ) . ' </span>' . esc_html( $noms[ (int) $suiv->format( 'n' ) ] . ' ' . $suiv->format( 'Y' ) ) . ' <span aria-hidden="true">→</span></a>';
	$html .= '</nav></div>';

	// Grille (bureau et tablette).
	$jours_courts = noms_jours( true );
	$jours_longs  = noms_jours();
	$html        .= '<div class="yn-card yn-calendrier__cadre"><table class="yn-calendrier__grille">';
	/* translators: %s : mois (« septembre 2026 ») */
	$html .= '<caption class="yn-visually-hidden">' . esc_html( sprintf( __( 'Sorties de %s', 'yume-core' ), $noms[ $num ] . ' ' . $annee ) ) . '</caption>';
	$html .= '<thead><tr>';
	foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $j ) {
		$html .= '<th scope="col"><abbr title="' . esc_attr( $jours_longs[ $j ] ) . '">' . esc_html( $jours_courts[ $j ] ) . '</abbr></th>';
	}
	$html .= '</tr></thead><tbody><tr>';
	$html .= str_repeat( '<td class="yn-calendrier__case yn-calendrier__case--vide"></td>', $decal );
	for ( $jour = 1; $jour <= $nb; $jour++ ) {
		$ymd = sprintf( '%s-%02d', $mois, $jour );
		$col = ( $decal + $jour - 1 ) % 7;
		if ( 0 === $col && $jour > 1 ) {
			$html .= '</tr><tr>';
		}
		$classe = 'yn-calendrier__case' . ( $ymd === $auj ? ' yn-calendrier__case--aujourdhui' : '' ) . ( isset( $par_jour[ $ymd ] ) ? ' yn-calendrier__case--sorties' : '' );
		$html  .= '<td class="' . esc_attr( $classe ) . '"' . ( $ymd === $auj ? ' aria-current="date"' : '' ) . '>';
		$html  .= '<span class="yn-calendrier__num"><time datetime="' . esc_attr( $ymd ) . '">' . (int) $jour . '</time>';
		if ( $ymd === $auj ) {
			$html .= '<span class="yn-visually-hidden"> (' . esc_html__( 'aujourd’hui', 'yume-core' ) . ')</span>';
		}
		$html .= '</span>';
		if ( isset( $par_jour[ $ymd ] ) ) {
			$html .= liste_evenements( $par_jour[ $ymd ] );
		}
		$html .= '</td>';
	}
	$reste = ( 7 - ( $decal + $nb ) % 7 ) % 7;
	$html .= str_repeat( '<td class="yn-calendrier__case yn-calendrier__case--vide"></td>', $reste );
	$html .= '</tr></tbody></table></div>';

	// Liste en repli (mobile) : seulement les jours qui ont des sorties.
	$html .= '<div class="yn-card yn-calendrier__repli">';
	if ( $par_jour ) {
		$html .= '<ol class="yn-calendrier__liste">';
		foreach ( $par_jour as $ymd => $evenements ) {
			$ts    = ts_date( $ymd );
			$html .= '<li' . ( $ymd === $auj ? ' aria-current="date"' : '' ) . '><p class="yn-calendrier__date"><time datetime="' . esc_attr( $ymd ) . '">' . esc_html( majuscule( format_fr( $ts, 'l j F' ) ) ) . '</time>';
			if ( $ymd === $auj ) {
				$html .= ' <span class="yn-chip yn-chip--info">' . esc_html__( 'aujourd’hui', 'yume-core' ) . '</span>';
			}
			$html .= '</p>' . liste_evenements( $evenements ) . '</li>';
		}
		$html .= '</ol>';
	} else {
		$html .= '<p class="yn-muted yn-calendrier__vide">' . esc_html__( 'Aucune sortie datée ce mois-ci.', 'yume-core' ) . '</p>';
	}
	$html .= '</div>';

	// Légende et abonnement.
	$html .= '<div class="yn-calendrier__pied"><ul class="yn-calendrier__legende" aria-label="' . esc_attr__( 'Légende', 'yume-core' ) . '">';
	foreach ( natures_calendrier() as $nature => $n ) {
		$html .= '<li><span class="yn-calendrier__evt yn-calendrier__evt--' . esc_attr( $nature ) . '"><span class="yn-calendrier__icone" aria-hidden="true">' . esc_html( $n['icone'] ) . '</span> ' . esc_html( $n['libelle'] ) . '</span> ' . esc_html( $n['legende'] ) . '</li>';
	}
	$html .= '</ul><p class="yn-calendrier__abonnement">';
	$html .= '<a class="yn-btn yn-btn--sm" href="' . esc_url( url_ics( $filtres['oeuvre'], true ) ) . '">' . esc_html__( 'S’abonner au calendrier (ICS)', 'yume-core' ) . '</a>';
	$html .= '<a class="yn-calendrier__telecharger" href="' . esc_url( url_ics( $filtres['oeuvre'] ) ) . '">' . esc_html__( 'Télécharger le fichier .ics', 'yume-core' ) . '</a>';
	$html .= '</p></div>';

	return $html . '</section>';
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
	limiter_cache_page();
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
		? sprintf( __( 'sortie prévue %s', 'yume-core' ), date_cible_lisible( $l['date_cible'], false, (string) ( $l['heure_cible'] ?? '' ) ) )
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
	$html .= '<p class="yn-oeuvre-planning__etat">' . pastille_ligne( $l ) . '</p>';
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
