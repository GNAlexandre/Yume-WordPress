<?php
/**
 * Rapport lisible (Markdown) d'un plan de migration : comptes, œuvres, tomes, chapitres,
 * articles, redirections et anomalies, pour relecture par l'équipe.
 *
 * Classe pure (aucun accès à la base).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Plan → rapport Markdown.
 */
final class Plan_Report {

	/** Libellés des niveaux d'avertissement. */
	private const NIVEAUX = array(
		'erreur'    => 'Erreurs (à corriger avant la bascule)',
		'attention' => 'Points d’attention (à valider par l’équipe)',
		'info'      => 'Informations',
	);

	/**
	 * Échappe une cellule de tableau Markdown.
	 *
	 * @param mixed $valeur Valeur.
	 */
	private static function cellule( $valeur ): string {
		if ( is_bool( $valeur ) ) {
			$valeur = $valeur ? 'oui' : 'non';
		}
		return str_replace( array( '|', "\n" ), array( '\\|', ' ' ), (string) $valeur );
	}

	/**
	 * Tableau Markdown.
	 *
	 * @param string[] $entetes En-têtes.
	 * @param array    $lignes  Lignes (tableaux de valeurs).
	 */
	private static function tableau( array $entetes, array $lignes ): string {
		$sortie  = '| ' . implode( ' | ', $entetes ) . " |\n";
		$sortie .= '|' . str_repeat( ' --- |', count( $entetes ) ) . "\n";
		foreach ( $lignes as $ligne ) {
			$sortie .= '| ' . implode( ' | ', array_map( array( self::class, 'cellule' ), $ligne ) ) . " |\n";
		}
		return $sortie . "\n";
	}

	/**
	 * Libellés français des clés de comptes, par groupe (les clés du plan restent les slugs
	 * WordPress ; seul l'affichage est traduit).
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function libelles(): array {
		$statuts = function_exists( 'yume_statuts' ) ? yume_statuts() : array();
		$types   = function_exists( 'yume_types' ) ? yume_types() : array();
		$natures = function_exists( 'yume_natures_tome' ) ? yume_natures_tome() : array();
		$etapes  = function_exists( 'yume_etapes' ) ? yume_etapes() : array();
		return array(
			'statut'      => $statuts + Legacy_Oeuvre_Parser::STATUTS_LIBELLES,
			'type'        => $types + array(
				'light-novel' => 'Light novel',
				'web-novel'   => 'Web novel',
				'manga'       => 'Manga',
			),
			'nature'      => $natures + array(
				'tome'      => 'Tome',
				'arc'       => 'Arc',
				'ex'        => 'Tome EX',
				'bonus'     => 'Bonus',
				'chapitres' => 'Chapitres',
			),
			'etape'       => $etapes + array(
				'a_faire'    => 'À faire',
				'traduction' => 'Traduction',
				'relecture'  => 'Relecture',
				'edition'    => 'Édition',
				'publie'     => 'Publié',
			),
			'publication' => array(
				'publish' => 'Publié',
				'draft'   => 'Brouillon',
				'future'  => 'Planifié',
				'pending' => 'En attente de relecture',
				'private' => 'Privé',
				'trash'   => 'Corbeille',
			),
			'classement'  => array(
				'sortie'    => 'Sorties',
				'actualite' => 'Actualités',
			),
			'famille'     => array(
				'fiche'            => 'Fiches',
				'arc'              => 'Arcs',
				'chapitre'         => 'Chapitres',
				'hub'              => 'Hubs',
				'categorie'        => 'Pages de catégorie',
				'institutionnelle' => 'Institutionnelles',
				'ignoree'          => 'Ignorées',
			),
			'pages'       => array(
				'conserver' => 'Conservées',
				'remplacer' => 'Remplacées',
				'ignorer'   => 'Ignorées',
				'creer'     => 'À créer',
			),
			'niveau'      => array(
				'erreur'    => 'Erreurs',
				'attention' => 'Points d’attention',
				'info'      => 'Informations',
			),
			'action'      => array(
				'renommer'  => 'Renommée',
				'conserver' => 'Conservée',
				'supprimer' => 'Supprimée',
				'creer'     => 'Créée',
			),
		);
	}

	/**
	 * Libellé français d'une clé de compte (« draft » → « Brouillon », « abandonnee » →
	 * « Abandonnée »), ou la clé elle-même si elle n'a pas de libellé (hébergeurs…).
	 *
	 * @param string $groupe statut, type, nature, etape, publication, classement, famille, pages,
	 *                       niveau ou action.
	 * @param string $cle    Clé.
	 */
	public static function libelle( string $groupe, string $cle ): string {
		static $cache = null;
		if ( null === $cache ) {
			$cache = self::libelles();
		}
		return (string) ( $cache[ $groupe ][ $cle ] ?? $cle );
	}

	/**
	 * Liste « libellé : valeur » d'un tableau de comptes.
	 *
	 * @param array  $comptes   Comptes.
	 * @param string $groupe    Groupe de libellés (voir libelle()).
	 * @param string $separateur Séparateur.
	 */
	public static function liste_comptes( array $comptes, string $groupe = '', string $separateur = ' · ' ): string {
		$morceaux = array();
		foreach ( $comptes as $cle => $valeur ) {
			$morceaux[] = self::libelle( $groupe, (string) $cle ) . ' : ' . $valeur;
		}
		return implode( $separateur, $morceaux );
	}

	/**
	 * Construit le rapport.
	 *
	 * @param array $plan Plan (Migration_Planner::plan()).
	 */
	public static function markdown( array $plan ): string {
		$c   = $plan['comptes'];
		$md  = "# Rapport de migration — yumenovel.fr\n\n";
		$md .= sprintf( "Plan v%d généré le %s à partir de l’export du %s (%s).\n\n", $plan['version'], $plan['genere_le'], $plan['source']['exporte_le'] ?? '?', $plan['source']['domaine'] );

		$md .= "## Comptes\n\n";
		$md .= self::tableau(
			array( 'Élément', 'Total', 'Détail' ),
			array(
				array( 'Pages de l’ancien site', $c['pages']['total'], self::liste_comptes( $c['pages']['par_famille'], 'famille' ) . ' (statuts : ' . self::liste_comptes( $c['pages']['par_statut'], 'publication' ) . ')' ),
				array( 'Articles', $c['articles']['total'], self::liste_comptes( $c['articles']['par_classement'], 'classement' ) . sprintf( ' ; avec œuvre liée : %d ; sorties sans œuvre : %d ; en « Non classé » : %d ; brouillons ignorés : %d', $c['articles']['avec_oeuvre'], $c['articles']['sorties_sans_oeuvre'], $c['articles']['non_classe'], $c['articles']['a_ignorer'] ) . ' (statuts : ' . self::liste_comptes( $c['articles']['par_statut'], 'publication' ) . ')' ),
				array( 'Œuvres', $c['oeuvres']['total'], self::liste_comptes( $c['oeuvres']['par_type'], 'type' ) . ' ; ' . self::liste_comptes( $c['oeuvres']['par_statut'], 'statut' ) ),
				array( 'Tomes', $c['tomes']['total'], self::liste_comptes( $c['tomes']['par_nature'], 'nature' ) . ' ; ' . self::liste_comptes( $c['tomes']['par_statut'], 'publication' ) ),
				array( 'Chapitres', $c['chapitres']['total'], sprintf( 'migrés : %d ; planifiés (brouillons) : %d ; %d mots ; %d dialogues ; %d pensées ; %d illustrations ; %d images de navigation supprimées', $c['chapitres']['migres'], $c['chapitres']['planifies'], $c['chapitres']['mots'], $c['chapitres']['dialogues'], $c['chapitres']['pensees'], $c['chapitres']['illustrations'], $c['chapitres']['images_navigation_supprimees'] ) ),
				array( 'Liens de téléchargement', $c['liens']['pdf'] + $c['liens']['epub'], sprintf( 'PDF : %d ; EPUB : %d ; ', $c['liens']['pdf'], $c['liens']['epub'] ) . self::liste_comptes( $c['liens']['hebergeurs'] ) ),
				array( 'Redirections 301', $c['redirections'], '' ),
				array( 'Pages (plan)', array_sum( $c['pages_plan'] ), self::liste_comptes( $c['pages_plan'], 'pages' ) ),
				array( 'Médias', $c['medias']['exportes'], sprintf( 'référencés par le plan : %d ; manquants : %d', $c['medias']['references'], $c['medias']['manquants'] ) ),
				array( 'Avertissements', array_sum( $c['avertissements'] ), self::liste_comptes( $c['avertissements'], 'niveau' ) ),
			)
		);

		$md    .= "## Œuvres\n\n";
		$lignes = array();
		foreach ( $plan['oeuvres'] as $o ) {
			$lignes[] = array(
				$o['post']['post_title'],
				self::libelle( 'type', (string) $o['termes']['yume_type'][0] ),
				self::libelle( 'statut', (string) $o['termes']['yume_statut'][0] ),
				$o['infos']['statut_hub'],
				$o['infos']['statut_fiche'],
				count( $o['tomes'] ),
				$o['source']['url'],
				$o['url'],
			);
		}
		$md .= self::tableau( array( 'Œuvre', 'Type', 'Statut Yume', 'Hub', 'Fiche', 'Tomes', 'Ancienne URL', 'Nouvelle URL' ), $lignes );

		$md    .= "## Tomes et arcs\n\n";
		$lignes = array();
		foreach ( $plan['tomes'] as $t ) {
			$lignes[] = array(
				$t['post']['post_title'],
				self::libelle( 'publication', (string) $t['post']['post_status'] ),
				self::libelle( 'etape', (string) $t['meta']['yume_etape'] ),
				$t['post']['post_date'] . ( '' !== $t['date_source'] ? ' (' . $t['date_source'] . ')' : '' ),
				'' !== $t['meta']['yume_lien_pdf'] ? 'oui' : '—',
				'' !== $t['meta']['yume_lien_epub'] ? 'oui' : '—',
				$t['thumbnail_id'] ? $t['thumbnail_id'] : '—',
				count( $t['chapitres'] ),
				$t['source']['type'] ?? '',
			);
		}
		$md .= self::tableau( array( 'Tome', 'Statut', 'Étape', 'Date (source)', 'PDF', 'EPUB', 'Couverture', 'Chapitres', 'Source' ), $lignes );

		$md    .= "## Chapitres\n\n";
		$lignes = array();
		foreach ( $plan['chapitres'] as $ch ) {
			$lignes[] = array(
				$ch['tome'],
				$ch['post']['post_title'],
				self::libelle( 'publication', (string) $ch['post']['post_status'] ),
				$ch['meta']['yume_credits']['traduction'] . ( '' !== $ch['meta']['yume_credits']['relecture'] ? ' / ' . $ch['meta']['yume_credits']['relecture'] : '' ),
				$ch['meta']['yume_nb_mots'],
				isset( $ch['source']['slug'] ) ? '/' . $ch['source']['slug'] . '/' : '(annoncé)',
				$ch['url'],
			);
		}
		$md .= self::tableau( array( 'Tome', 'Chapitre', 'Statut', 'Crédits', 'Mots', 'Ancienne URL', 'Nouvelle URL' ), $lignes );

		$md    .= "## Articles\n\n";
		$lignes = array();
		foreach ( $plan['articles'] as $a ) {
			$lignes[] = array(
				$a['source_id'],
				$a['date'],
				$a['titre'],
				implode( ', ', $a['categories_slugs'] ),
				'ignorer' === $a['action'] ? '(ignoré)' : $a['categorie_cible'],
				$a['oeuvre'] ?? '—',
				$a['tome'] ?? '—',
			);
		}
		$md .= self::tableau( array( 'ID', 'Date', 'Titre', 'Catégories actuelles', 'Catégorie cible', 'Œuvre liée', 'Tome' ), $lignes );

		$md .= "## Catégories\n\n";
		foreach ( $plan['categories'] as $cat ) {
			$md .= sprintf( "- **%s** %s%s\n", self::libelle( 'action', (string) $cat['action'] ), $cat['nom_actuel'] ?? $cat['nom'], isset( $cat['nom'] ) && ( $cat['nom_actuel'] ?? '' ) !== $cat['nom'] ? ' → ' . $cat['nom'] . ' (' . $cat['slug'] . ')' : ( isset( $cat['condition'] ) ? ' — ' . $cat['condition'] : '' ) );
		}
		$md .= "\n## Pages\n\n";
		$md .= "### Conservées\n\n";
		foreach ( $plan['pages']['conserver'] as $p ) {
			$md .= sprintf( "- %s (`%s`)%s\n", $p['titre'], $p['url'], $p['remarques'] ? ' — ' . implode( ' ', $p['remarques'] ) : '' );
		}
		$md         .= "\n### Remplacées (dépubliées après migration)\n\n";
		$par_famille = array();
		foreach ( $plan['pages']['remplacer'] as $p ) {
			$par_famille[ $p['famille'] ][] = $p;
		}
		foreach ( $par_famille as $famille => $liste ) {
			$md .= sprintf( "- %s : %d page(s) — %s\n", self::libelle( 'famille', (string) $famille ), count( $liste ), implode( ', ', array_map( static fn( $p ) => $p['slug'], array_slice( $liste, 0, 12 ) ) ) . ( count( $liste ) > 12 ? '…' : '' ) );
		}
		$md .= "\n### Ignorées\n\n";
		foreach ( $plan['pages']['ignorer'] as $p ) {
			$md .= sprintf( "- %d « %s » (%s) : %s\n", $p['id'], $p['titre'], self::libelle( 'publication', (string) $p['statut'] ), $p['raison'] );
		}
		$md .= "\n### À créer (contrat §11)\n\n";
		foreach ( $plan['pages']['creer'] as $p ) {
			$md .= sprintf( "- %s : `%s` → %s\n", $p['post_title'], $p['url'], self::resume_contenu_page( $p ) );
		}

		$md    .= "\n## Redirections 301\n\n";
		$lignes = array();
		foreach ( $plan['redirections'] as $r ) {
			$lignes[] = array( $r['source'], $r['cible'], $r['type'] );
		}
		$md .= self::tableau( array( 'Ancienne URL', 'Nouvelle URL', 'Type' ), $lignes );

		$md .= "## Anomalies et avertissements\n\n";
		foreach ( self::NIVEAUX as $niveau => $titre ) {
			$liste = array_filter( $plan['avertissements'], static fn( $a ) => $a['niveau'] === $niveau );
			if ( ! $liste ) {
				continue;
			}
			$md .= sprintf( "### %s (%d)\n\n", $titre, count( $liste ) );
			foreach ( $liste as $a ) {
				$md .= sprintf( "- [%s]%s %s\n", $a['categorie'], null !== $a['source_id'] ? ' (#' . $a['source_id'] . ')' : '', $a['message'] );
			}
			$md .= "\n";
		}
		return $md;
	}

	/**
	 * Résumé du contenu d'une page à créer : bloc dynamique, rôle de lecture ou texte de base.
	 *
	 * @param array $page Page du plan (pages.creer[]).
	 */
	public static function resume_contenu_page( array $page ): string {
		$contenu = trim( (string) $page['post_content'] );
		$roles   = array(
			'page_on_front'  => 'page d’accueil (modèle front-page du thème)',
			'page_for_posts' => 'page des articles',
		);
		if ( '' === $contenu ) {
			return $roles[ $page['reglage'] ?? '' ] ?? 'page vide';
		}
		if ( preg_match( '#^<!-- wp:[a-z0-9/-]+ (?:\{.*\} )?/-->$#', $contenu ) ) {
			return '`' . $contenu . '`';
		}
		return sprintf( 'texte de base (%d mots)', Html::nombre_mots( Html::texte( $contenu ) ) );
	}

	/**
	 * Redirections au format CSV de l'extension Redirection (source, cible, regex, code).
	 *
	 * @param array $plan Plan.
	 */
	public static function csv_redirections( array $plan ): string {
		$csv = "source,target,regex,code\n";
		foreach ( $plan['redirections'] as $r ) {
			$csv .= sprintf( "\"%s\",\"%s\",0,%d\n", str_replace( '"', '""', $r['source'] ), str_replace( '"', '""', $r['cible'] ), (int) $r['code'] );
		}
		return $csv;
	}
}
