<?php
/**
 * Analyse d'un article de l'ancien site : classement (sortie / actualité), œuvre liée
 * (par correspondance du titre avec les titres et alias des œuvres), tome / arc / chapitres
 * annoncés, liens de téléchargement ou de lecture, crédits annoncés.
 *
 * Exemples : « Tome 9 de Grimgar of Fantasy and Ash disponible ! » (sortie de tome),
 * « Chapitres 1 & 2 du Tome 3 du WN de Silent Witch disponible ! » (sortie de chapitres,
 * « Tome » = arc pour un web novel), « PDF du Tome 3 du WN de Silent Witch disponible »
 * (tome relié), « Couverture du T.16 de Mikadono Sisters » (actualité).
 *
 * Classe pure (aucun accès à la base).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Article → classement et rattachement.
 */
final class Legacy_Post_Parser {

	/** Catégorie cible d'une sortie. */
	public const CATEGORIE_SORTIES = 'sorties';

	/** Catégorie cible d'une actualité. */
	public const CATEGORIE_ACTUALITES = 'actualites';

	/**
	 * Index de correspondance des œuvres.
	 *
	 * @param array $oeuvres Liste de ['cle', 'titre', 'titres_alt' => string[], 'type', 'alias' => string[]].
	 * @return array<int,array{cle:string,type:string,alias:string[]}>
	 */
	public static function index_oeuvres( array $oeuvres ): array {
		$index = array();
		foreach ( $oeuvres as $oeuvre ) {
			$alias = array();
			foreach ( array_merge( array( $oeuvre['titre'] ?? '' ), (array) ( $oeuvre['titres_alt'] ?? array() ), (array) ( $oeuvre['alias'] ?? array() ) ) as $nom ) {
				$n = Html::normaliser( (string) $nom );
				// Un alias doit contenir au moins 4 lettres latines (les titres japonais ne servent pas au rapprochement).
				if ( '' !== $n && preg_match_all( '/[a-z]/', $n ) >= 4 && ! in_array( $n, $alias, true ) ) {
					$alias[] = $n;
				}
			}
			$index[] = array(
				'cle'   => (string) $oeuvre['cle'],
				'type'  => (string) ( $oeuvre['type'] ?? '' ),
				'alias' => $alias,
			);
		}
		return $index;
	}

	/**
	 * Œuvre citée dans un texte : alias le plus long trouvé (mots entiers). À longueur égale,
	 * l'œuvre dont le type est mentionné (« manga », « LN », « WN ») est préférée.
	 *
	 * @param string $texte Texte (titre, extrait…).
	 * @param array  $index Index d'index_oeuvres().
	 * @return array{cle:?string,alias:string}
	 */
	public static function trouver_oeuvre( string $texte, array $index ): array {
		$n       = ' ' . Html::normaliser( $texte ) . ' ';
		$type    = '';
		$mention = array(
			'manga'       => '/ manga /',
			'web-novel'   => '/ (wn|web novel) /',
			'light-novel' => '/ (ln|light novel) /',
		);
		foreach ( $mention as $t => $motif ) {
			if ( preg_match( $motif, $n ) ) {
				$type = $t;
				break;
			}
		}
		$meilleur = array(
			'cle'   => null,
			'alias' => '',
			'score' => 0,
		);
		foreach ( $index as $oeuvre ) {
			foreach ( $oeuvre['alias'] as $alias ) {
				if ( false === strpos( $n, ' ' . $alias . ' ' ) ) {
					continue;
				}
				$score = strlen( $alias ) * 10 + ( '' !== $type && $type === $oeuvre['type'] ? 5 : 0 ) + ( '' === $type && 'manga' !== $oeuvre['type'] ? 1 : 0 );
				if ( $score > $meilleur['score'] ) {
					$meilleur = array(
						'cle'   => $oeuvre['cle'],
						'alias' => $alias,
						'score' => $score,
					);
				}
			}
		}
		return array(
			'cle'   => $meilleur['cle'],
			'alias' => $meilleur['alias'],
		);
	}

	/**
	 * Numéros annoncés dans un texte : tome (ou « EX »), arc, chapitres.
	 *
	 * @param string $texte Texte (titre).
	 * @return array{tome:?float,tome_ex:bool,arc:?int,chapitres:float[]}
	 */
	public static function numeros( string $texte ): array {
		$texte = Html::decoder( $texte );
		$res   = array(
			'tome'      => null,
			'tome_ex'   => false,
			'arc'       => null,
			'chapitres' => array(),
		);
		if ( preg_match( '/\btome\s+(ex\b|\d+(?:[.,]\d+)?)/iu', $texte, $m ) || preg_match( '/\bT\.\s*(\d+(?:[.,]\d+)?)/u', $texte, $m ) ) {
			if ( 'ex' === strtolower( $m[1] ) ) {
				$res['tome_ex'] = true;
			} else {
				$res['tome'] = Html::nombre( $m[1] );
			}
		}
		if ( preg_match( '/\barc\s+(\d+)/iu', $texte, $m ) ) {
			$res['arc'] = (int) $m[1];
		}
		if ( preg_match( '/\bchapitres?\s+(\d+(?:[.,]\d+)?)((?:\s*(?:&|et|,)\s*\d+(?:[.,]\d+)?)*)(?:\s*(?:à|a|-)\s*(\d+(?:[.,]\d+)?))?/iu', $texte, $m ) ) {
			$res['chapitres'][] = (float) Html::nombre( $m[1] );
			if ( ! empty( $m[2] ) && preg_match_all( '/(\d+(?:[.,]\d+)?)/u', $m[2], $autres ) ) {
				foreach ( $autres[1] as $autre ) {
					$res['chapitres'][] = (float) Html::nombre( $autre );
				}
			}
			if ( ! empty( $m[3] ) ) {
				$fin = (float) Html::nombre( $m[3] );
				for ( $i = (int) floor( $res['chapitres'][0] ) + 1; $i <= $fin && $i - $res['chapitres'][0] <= 50; $i++ ) {
					$res['chapitres'][] = (float) $i;
				}
				// Borne finale décimale (« 12 à 16.5 ») : le demi-chapitre fait partie de la sortie.
				if ( floor( $fin ) !== $fin && $fin - $res['chapitres'][0] <= 50 ) {
					$res['chapitres'][] = $fin;
				}
			}
			$res['chapitres'] = array_values( array_unique( $res['chapitres'], SORT_REGULAR ) );
		}
		return $res;
	}

	/**
	 * Analyse un article.
	 *
	 * @param array $post    Article de l'export.
	 * @param array $index   Index des œuvres (index_oeuvres()).
	 * @param array $options 'domaines' => string[], 'categories' => array<int,string> (ID → slug).
	 * @return array<string,mixed>
	 */
	public static function parse( array $post, array $index = array(), array $options = array() ): array {
		$domaines   = $options['domaines'] ?? array( 'yumenovel.fr', 'yumenovel.wordpress.com' );
		$categories = (array) ( $options['categories'] ?? array() );
		$titre      = trim( Html::decoder( (string) ( $post['title'] ?? '' ) ) );
		$extrait    = Html::texte( (string) ( $post['excerpt'] ?? '' ) );
		$contenu    = null === ( $post['content'] ?? null ) ? null : (string) $post['content'];
		$texte      = null !== $contenu ? Html::texte( $contenu ) : $extrait;
		$n_titre    = Html::normaliser( $titre );
		$avert      = array();

		// Classement.
		$annonce = (bool) preg_match( '/\b(disponibles?|dispo)\b|^nouvelle sortie\b/', $n_titre )
			|| ( (bool) preg_match( '/^(tome|tomes|chapitres?|pdf|arc)\b/', $n_titre ) && (bool) preg_match( '/\bdisponibles?\b/', Html::normaliser( $extrait . ' ' . $texte ) ) );
		$objet   = (bool) preg_match( '/\b(tome|tomes|chapitres?|pdf|epub|arc|volume)\b/', $n_titre . ' ' . Html::normaliser( $extrait ) );
		$sortie  = $annonce && $objet;

		// Numéros : titre d'abord, extrait ensuite (« Nouvelle sortie chapitre ! » → « Gimai Tome 2 chapitre 1 »).
		$numeros = self::numeros( $titre );
		if ( $sortie && null === $numeros['tome'] && ! $numeros['tome_ex'] && null === $numeros['arc'] && ! $numeros['chapitres'] ) {
			$numeros = self::numeros( $extrait );
		} elseif ( $sortie && ! $numeros['chapitres'] && preg_match( '/\bchapitres?\b/', $n_titre ) ) {
			$numeros['chapitres'] = self::numeros( $extrait )['chapitres'];
		}
		$type_sortie = null;
		if ( $sortie ) {
			if ( $numeros['chapitres'] ) {
				$type_sortie = 'chapitres';
			} elseif ( preg_match( '/\b(pdf|relie)\b/', $n_titre ) ) {
				$type_sortie = 'tome_relie';
			} elseif ( null !== $numeros['tome'] || $numeros['tome_ex'] || null !== $numeros['arc'] ) {
				$type_sortie = 'tome';
			} else {
				$type_sortie = 'autre';
			}
		}

		// Œuvre liée : titre, puis extrait, puis liens internes du contenu (résolus par le planificateur).
		$trouve = self::trouver_oeuvre( $titre, $index );
		$source = 'titre';
		if ( null === $trouve['cle'] ) {
			$trouve = self::trouver_oeuvre( $extrait, $index );
			$source = 'extrait';
		}
		if ( null === $trouve['cle'] ) {
			$source = '';
		}

		// Liens du contenu.
		$liens = array(
			'pdf'      => '',
			'epub'     => '',
			'lecture'  => array(),
			'internes' => array(),
			'autres'   => array(),
		);
		if ( null !== $contenu ) {
			foreach ( Html::liens( $contenu ) as $lien ) {
				$chemin = Html::chemin_local( $lien['url'], $domaines );
				$type   = Legacy_Oeuvre_Parser::type_lien( $lien['url'], $lien['texte'], $lien['texte'] );
				if ( 'pdf' === $type || 'epub' === $type ) {
					if ( '' === $liens[ $type ] ) {
						$liens[ $type ] = $lien['url'];
					}
				} elseif ( '' !== $chemin && false === strpos( $chemin, '/wp-content/' ) ) {
					$liens['internes'][] = $chemin;
				} elseif ( 'lecture' === $type || preg_match( '/^(lire|ici)$/', Html::normaliser( $lien['texte'] ) ) ) {
					$liens['lecture'][] = $lien['url'];
				} else {
					$liens['autres'][] = $lien['url'];
				}
			}
			$liens['internes'] = array_values( array_unique( $liens['internes'] ) );
		}

		// Crédits annoncés (« La traduction est signé par X et la correction par Y »).
		$equipe = array(
			'traduction' => '',
			'relecture'  => '',
		);
		if ( preg_match( '/traduction\s+est\s+sign[ée]+e?s?\s+par\s+([^,.!;]+?)(?:\s+et\s+la\s+(?:correction|relecture)\s+par\s+([^,.!;]+?))?\s*[,.!;]/iu', $texte . ' ' . $extrait, $m ) ) {
			$equipe['traduction'] = trim( $m[1] );
			$equipe['relecture']  = trim( $m[2] ?? '' );
		}

		$slugs_source = array();
		foreach ( (array) ( $post['categories'] ?? array() ) as $id ) {
			$slugs_source[] = $categories[ (int) $id ] ?? (string) $id;
		}

		if ( $sortie && null === $trouve['cle'] ) {
			$avert[] = sprintf( 'Article %d « %s » : sortie sans œuvre reconnue.', (int) ( $post['id'] ?? 0 ), $titre );
		}

		return array(
			'source_id'          => (int) ( $post['id'] ?? 0 ),
			'slug'               => (string) ( $post['slug'] ?? '' ),
			'titre'              => $titre,
			'date'               => (string) ( $post['date'] ?? '' ),
			'lien'               => (string) ( $post['link'] ?? '' ),
			'status'             => (string) ( $post['status'] ?? 'publish' ),
			'categories_source'  => array_values( array_map( 'intval', (array) ( $post['categories'] ?? array() ) ) ),
			'categories_slugs'   => $slugs_source,
			'classement'         => $sortie ? 'sortie' : 'actualite',
			'categorie_cible'    => $sortie ? self::CATEGORIE_SORTIES : self::CATEGORIE_ACTUALITES,
			'oeuvre'             => $trouve['cle'],
			'oeuvre_alias'       => $trouve['alias'],
			'oeuvre_source'      => $source,
			'type_sortie'        => $type_sortie,
			'tome'               => $numeros['tome'],
			'tome_ex'            => $numeros['tome_ex'],
			'arc'                => $numeros['arc'],
			'chapitres'          => $numeros['chapitres'],
			'liens'              => $liens,
			'equipe'             => $equipe,
			'contenu_disponible' => null !== $contenu,
			'nb_mots'            => Html::nombre_mots( $texte ),
			'avertissements'     => $avert,
		);
	}
}
