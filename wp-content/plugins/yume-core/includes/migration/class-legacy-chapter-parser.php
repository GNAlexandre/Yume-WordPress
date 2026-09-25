<?php
/**
 * Analyse d'une page chapitre de l'ancien site (« Secrets of the Silent Witch T.4 – Chapitre 1 »)
 * et conversion de son contenu au format du contrat §9 :
 *
 *   - le titre (sous-titre du chapitre, paragraphe centré en gras) et les crédits
 *     (« 「Traduction – X / Relecture – Y」 ») sont retirés du corps et deviennent des métadonnées ;
 *   - répliques « — » → paragraphe yn-dialogue (« — » + espace insécable) ;
 *   - paragraphe entièrement en italique → yn-thought ; paragraphe centré → yn-center ;
 *   - séparateurs (<hr>, « *** », « ◇ ») → core/separator yn-scene-break ;
 *   - images → core/image yn-illustration, sauf les images de navigation (liées à une autre
 *     page du site : chapitre précédent / suivant), supprimées ;
 *   - styles, tailles de police et classes de l'éditeur supprimés.
 *
 * Classe pure (aucun accès à la base) : parse_blocks et fonctions de formatage uniquement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Page chapitre → chapitre.
 */
final class Legacy_Chapter_Parser {

	/** Mots par minute pour le temps de lecture (contrat §4). */
	public const MOTS_PAR_MINUTE = 230;

	/**
	 * Reconnaît un titre de page chapitre : « <Œuvre> T.<n> – Chapitre <m> ».
	 *
	 * @param string $titre Titre de la page.
	 * @return array{oeuvre:string,tome:int,numero:?float,nature:string}|null
	 */
	public static function analyser_titre( string $titre ): ?array {
		$titre = trim( (string) preg_replace( '/\s+/u', ' ', Html::decoder( $titre ) ) );
		if ( ! preg_match( '/^(.*?)\s+T\.?\s*(\d+)\s*[–—-]\s*(chapitre|prologue|[ée]pilogue|interlude|bonus|postface)\s*(\d+(?:[.,]\d+)?)?\s*$/iu', $titre, $m ) ) {
			return null;
		}
		$nature = Html::normaliser( $m[3] );
		return array(
			'oeuvre' => trim( $m[1] ),
			'tome'   => (int) $m[2],
			'numero' => isset( $m[4] ) && '' !== $m[4] ? Html::nombre( $m[4] ) : ( 'prologue' === $nature ? 0.0 : null ),
			'nature' => $nature,
		);
	}

	/**
	 * Slug attendu d'une page chapitre d'après son titre (sert à détecter les slugs incohérents).
	 *
	 * @param array $titre Résultat d'analyser_titre().
	 */
	public static function slug_attendu( array $titre ): string {
		$numero = null === $titre['numero'] ? '' : ' ' . str_replace( '.', '-', rtrim( rtrim( number_format( (float) $titre['numero'], 3, '.', '' ), '0' ), '.' ) );
		return sanitize_title( $titre['oeuvre'] . ' T ' . $titre['tome'] . ' ' . $titre['nature'] . $numero );
	}

	/**
	 * Analyse une page chapitre.
	 *
	 * @param array $page    Page de l'export.
	 * @param array $options 'domaines' => string[] ; 'chemins_chapitres' => string[] (chemins des
	 *                       autres pages chapitres, pour reconnaître la navigation) ;
	 *                       'medias' => array<int,string> (ID → URL source des pièces jointes).
	 * @return array<string,mixed>
	 */
	public static function parse( array $page, array $options = array() ): array {
		$domaines = $options['domaines'] ?? array( 'yumenovel.fr', 'yumenovel.wordpress.com' );
		$chemins  = array_flip( (array) ( $options['chemins_chapitres'] ?? array() ) );
		$medias   = (array) ( $options['medias'] ?? array() );
		$avert    = array();
		$contenu  = (string) ( $page['content'] ?? '' );
		$titre    = self::analyser_titre( (string) ( $page['title'] ?? '' ) );
		if ( null === $titre ) {
			$avert[] = sprintf( 'Page %d : titre de chapitre non reconnu (« %s »).', (int) ( $page['id'] ?? 0 ), $page['title'] ?? '' );
			$titre   = array(
				'oeuvre' => '',
				'tome'   => 0,
				'numero' => null,
				'nature' => 'chapitre',
			);
		}

		$blocs = self::blocs( $contenu );

		// En-tête : sous-titre centré puis crédits, dans les premiers blocs.
		$index_credits = -1;
		$index_titre   = -1;
		$premiers      = array();
		foreach ( $blocs as $i => $bloc ) {
			if ( in_array( $bloc['blockName'], array( 'core/separator', 'core/spacer' ), true ) ) {
				continue;
			}
			if ( 'core/paragraph' === $bloc['blockName'] && '' === Html::texte( (string) $bloc['innerHTML'] ) ) {
				continue;
			}
			$premiers[] = $i;
			if ( count( $premiers ) > 4 ) {
				break;
			}
			if ( 'core/paragraph' === $bloc['blockName'] && self::est_credits( Html::texte( (string) $bloc['innerHTML'] ) ) ) {
				$index_credits = $i;
				break;
			}
		}
		// Le sous-titre est le premier bloc de texte, avant les crédits : centré, titre ou tout en gras.
		$premier = $premiers[0] ?? -1;
		if ( $premier >= 0 && ( $index_credits < 0 || $premier < $index_credits ) ) {
			$bloc = $blocs[ $premier ];
			if ( in_array( $bloc['blockName'], array( 'core/paragraph', 'core/heading' ), true ) ) {
				$texte   = Html::texte( (string) $bloc['innerHTML'] );
				$en_tete = self::est_centre( $bloc ) || 'core/heading' === $bloc['blockName'] || self::est_gras( (string) $bloc['innerHTML'] );
				$court   = mb_strlen( $texte, 'UTF-8' ) <= 200;
				if ( $en_tete && $court && ! Html::est_separateur_texte( $texte ) ) {
					$index_titre = $premier;
				}
			}
		}

		$sous_titre = '';
		if ( $index_titre >= 0 ) {
			$sous_titre = Html::texte( (string) $blocs[ $index_titre ]['innerHTML'] );
			if ( preg_match( '/^chapitre\s*\d+(?:[.,]\d+)?\s*$/iu', $sous_titre ) ) {
				$sous_titre = '';
			} else {
				$sous_titre = trim( (string) preg_replace( '/^chapitre\s*\d+(?:[.,]\d+)?\s*[:：–—-]\s*/iu', '', $sous_titre ) );
			}
		} else {
			$avert[] = 'Sous-titre (titre en grand) introuvable en tête de page.';
		}
		$credits = array(
			'traduction' => '',
			'relecture'  => '',
			'edition'    => '',
		);
		if ( $index_credits >= 0 ) {
			$credits = self::credits( (string) $blocs[ $index_credits ]['innerHTML'] );
		}

		// Corps.
		$sortie     = array();
		$navigation = array();
		$illus      = array();
		$textes     = array();
		$stats      = array(
			'paragraphes'   => 0,
			'dialogues'     => 0,
			'pensees'       => 0,
			'centres'       => 0,
			'separateurs'   => 0,
			'illustrations' => 0,
			'navigation'    => 0,
		);
		$repliques  = array();
		$debut      = max( $index_titre, $index_credits ) + 1;
		foreach ( array_slice( $blocs, $debut ) as $bloc ) {
			$nom = $bloc['blockName'];
			if ( 'core/paragraph' === $nom || 'core/heading' === $nom ) {
				$html   = (string) preg_replace( '#^\s*<(?:p|h\d)[^>]*>|</(?:p|h\d)>\s*$#i', '', trim( (string) $bloc['innerHTML'] ) );
				$centre = self::est_centre( $bloc );
				if ( $index_credits < 0 && self::est_credits( Html::texte( $html ) ) ) {
					$credits = self::credits( $html );
					continue;
				}
				foreach ( self::morceaux( $html ) as $morceau ) {
					$propre = Html::nettoyer_inline( $morceau );
					$propre = (string) preg_replace( '/^(?:<br>\s*)+|(?:\s*<br>)+$/u', '', $propre );
					$texte  = Html::texte( $propre );
					if ( '' === $texte ) {
						continue;
					}
					if ( Html::est_separateur_texte( $texte ) ) {
						$sortie[] = array( 'separateur', '' );
						continue;
					}
					if ( Html::commence_par_tiret( $texte ) ) {
						$sortie[] = array( 'paragraphe', Html::normaliser_dialogue( $propre ), array( 'yn-dialogue' ), '' );
						++$stats['dialogues'];
						// Plusieurs répliques collées dans le même paragraphe (« … ? — Oui. — Non… »).
						if ( preg_match( '/[.?!…»]\s*[' . Html::TIRETS . ']\s/u', mb_substr( $texte, 1, null, 'UTF-8' ) ) ) {
							$repliques[] = mb_substr( $texte, 0, 60, 'UTF-8' );
						}
					} elseif ( Html::entierement_italique( $propre ) ) {
						$sortie[] = array( 'paragraphe', Html::sans_italique( $propre ), array( 'yn-thought' ), '' );
						++$stats['pensees'];
					} elseif ( $centre ) {
						$sortie[] = array( 'paragraphe', $propre, array( 'yn-center' ), 'center' );
						++$stats['centres'];
					} else {
						$sortie[] = array( 'paragraphe', $propre, array(), '' );
					}
					++$stats['paragraphes'];
					$textes[] = $texte;
				}
				continue;
			}
			if ( 'core/separator' === $nom ) {
				$sortie[] = array( 'separateur', '' );
				continue;
			}
			if ( 'core/image' === $nom ) {
				$image = self::image( $bloc, $domaines, $chemins, $medias );
				if ( 'navigation' === $image['role'] ) {
					$navigation[] = $image['lien'];
					$sortie[]     = array( 'navigation', $image );
					++$stats['navigation'];
				} elseif ( '' !== $image['url'] ) {
					$sortie[] = array( 'image', $image );
				}
				continue;
			}
			if ( 'core/list' === $nom ) {
				$elements = array();
				if ( preg_match_all( '#<li[^>]*>(.*?)</li>#is', (string) serialize_block( $bloc ), $m ) ) {
					foreach ( $m[1] as $li ) {
						$li = Html::nettoyer_inline( (string) preg_replace( '/<!--.*?-->/s', '', $li ) );
						if ( '' !== Html::texte( $li ) ) {
							$elements[] = $li;
							$textes[]   = Html::texte( $li );
						}
					}
				}
				if ( $elements ) {
					$sortie[] = array( 'liste', $elements, ! empty( $bloc['attrs']['ordered'] ) );
				}
				continue;
			}
			if ( 'core/buttons' === $nom || 'core/button' === $nom ) {
				foreach ( Html::liens( (string) serialize_block( $bloc ) ) as $lien ) {
					$navigation[] = $lien['url'];
					++$stats['navigation'];
				}
				continue;
			}
			if ( in_array( $nom, array( 'core/spacer', 'core/embed' ), true ) ) {
				continue;
			}
			$avert[] = sprintf( 'Bloc %s ignoré.', $nom );
		}

		// Pied de page de navigation : après le dernier texte, une image non liée faite sur le même
		// modèle que les images de navigation (même nom de fichier aux numéros près : couverture
		// du chapitre suivant, pas encore publié) relève aussi de la navigation. Une vraie
		// illustration de fin de chapitre est conservée.
		$dernier_texte = -1;
		foreach ( $sortie as $i => $element ) {
			if ( in_array( $element[0], array( 'paragraphe', 'liste' ), true ) ) {
				$dernier_texte = $i;
			}
		}
		$pied    = array_slice( $sortie, $dernier_texte + 1, null, true );
		$modeles = array();
		foreach ( $pied as $element ) {
			if ( 'navigation' === $element[0] && '' !== self::modele_fichier( $element[1]['url'] ) ) {
				$modeles[ self::modele_fichier( $element[1]['url'] ) ] = true;
			}
		}
		foreach ( $pied as $i => $element ) {
			if ( 'image' === $element[0] && isset( $modeles[ self::modele_fichier( $element[1]['url'] ) ] ) ) {
				$avert[] = sprintf( 'Image %s non liée dans le pied de navigation : retirée (couverture du chapitre suivant ?).', $element[1]['id'] ? '#' . $element[1]['id'] : $element[1]['url'] );
				++$stats['navigation'];
				unset( $sortie[ $i ] );
			}
		}
		$sortie = array_values(
			array_filter(
				$sortie,
				static fn( $e ) => 'navigation' !== $e[0]
			)
		);
		foreach ( $sortie as $element ) {
			if ( 'image' === $element[0] ) {
				if ( $element[1]['id'] > 0 ) {
					$illus[] = $element[1]['id'];
				}
				++$stats['illustrations'];
			}
		}
		if ( $repliques ) {
			$avert[] = sprintf( '%d paragraphe(s) de dialogue réunissant plusieurs répliques (à scinder à la relecture) : « %s… ».', count( $repliques ), implode( '… », « ', array_slice( $repliques, 0, 3 ) ) );
		}

		// Séparateurs : ni en tête, ni en fin, jamais deux de suite.
		$final = array();
		foreach ( $sortie as $element ) {
			if ( 'separateur' === $element[0] && ( ! $final || 'separateur' === end( $final )[0] ) ) {
				continue;
			}
			$final[] = $element;
		}
		while ( $final && 'separateur' === end( $final )[0] ) {
			array_pop( $final );
		}

		$blocs_sortie = array();
		foreach ( $final as $element ) {
			switch ( $element[0] ) {
				case 'paragraphe':
					$blocs_sortie[] = Blocks::paragraphe( $element[1], $element[2], $element[3] );
					break;
				case 'separateur':
					$blocs_sortie[] = Blocks::separateur();
					++$stats['separateurs'];
					break;
				case 'image':
					$blocs_sortie[] = Blocks::image( $element[1]['id'], $element[1]['url'], $element[1]['alt'] );
					break;
				case 'liste':
					$blocs_sortie[] = Blocks::liste( $element[1], '', $element[2] );
					break;
			}
		}

		$nb_mots = Html::nombre_mots( implode( ' ', $textes ) );
		if ( '' === $credits['traduction'] ) {
			$avert[] = 'Crédit de traduction introuvable.';
		}
		if ( 0 === $stats['paragraphes'] ) {
			$avert[] = 'Aucun paragraphe de texte.';
		}
		$slug_attendu = null !== $titre['numero'] || 'chapitre' !== $titre['nature'] ? self::slug_attendu( $titre ) : '';
		$slug         = (string) ( $page['slug'] ?? '' );

		return array(
			'source_id'       => (int) ( $page['id'] ?? 0 ),
			'source_slug'     => $slug,
			'source_url'      => (string) ( $page['link'] ?? '' ),
			'source_titre'    => (string) ( $page['title'] ?? '' ),
			'oeuvre_indice'   => $titre['oeuvre'],
			'tome_numero'     => $titre['tome'],
			'numero'          => $titre['numero'],
			'nature'          => $titre['nature'],
			'sous_titre'      => $sous_titre,
			'credits'         => $credits,
			'contenu'         => Blocks::assembler( $blocs_sortie ),
			'illustrations'   => array_values( array_unique( $illus ) ),
			'navigation'      => $navigation,
			'nb_mots'         => $nb_mots,
			'temps_lecture'   => max( 1, (int) ceil( $nb_mots / self::MOTS_PAR_MINUTE ) ),
			'stats'           => $stats,
			'slug_attendu'    => $slug_attendu,
			'slug_incoherent' => '' !== $slug_attendu && '' !== $slug && $slug !== $slug_attendu,
			'hash'            => sha1( $contenu ),
			'image_id'        => (int) ( $page['featured_media'] ?? 0 ),
			'date'            => (string) ( $page['date'] ?? '' ),
			'modified'        => (string) ( $page['modified'] ?? '' ),
			'status'          => (string) ( $page['status'] ?? 'publish' ),
			'avertissements'  => $avert,
		);
	}

	/**
	 * Blocs de premier niveau (groupes et citations aplatis). Un contenu sans commentaires de
	 * blocs (HTML rendu) est découpé en pseudo-blocs p / hr / figure / h*.
	 *
	 * @param string $contenu Contenu brut.
	 * @return array<int,array>
	 */
	private static function blocs( string $contenu ): array {
		$blocs = array();
		foreach ( parse_blocks( $contenu ) as $bloc ) {
			if ( empty( $bloc['blockName'] ) ) {
				if ( '' !== trim( wp_strip_all_tags( (string) $bloc['innerHTML'] ) ) || false !== stripos( (string) $bloc['innerHTML'], '<img' ) || false !== stripos( (string) $bloc['innerHTML'], '<hr' ) ) {
					$blocs = array_merge( $blocs, self::pseudo_blocs( (string) $bloc['innerHTML'] ) );
				}
				continue;
			}
			if ( in_array( $bloc['blockName'], array( 'core/group', 'core/quote', 'core/column', 'core/columns', 'core/gallery' ), true ) && ! empty( $bloc['innerBlocks'] ) ) {
				$blocs = array_merge( $blocs, self::blocs( serialize_blocks( $bloc['innerBlocks'] ) ) );
				continue;
			}
			$blocs[] = $bloc;
		}
		return $blocs;
	}

	/**
	 * Découpe du HTML rendu (sans commentaires de blocs) en pseudo-blocs.
	 *
	 * @param string $html HTML.
	 * @return array<int,array>
	 */
	private static function pseudo_blocs( string $html ): array {
		$blocs = array();
		if ( ! preg_match_all( '#<p\b[^>]*>.*?</p>|<hr\b[^>]*/?>|<figure\b[^>]*>.*?</figure>|<h[1-6]\b[^>]*>.*?</h[1-6]>|<img\b[^>]*/?>#is', $html, $m ) ) {
			return $blocs;
		}
		foreach ( $m[0] as $element ) {
			$balise = strtolower( (string) preg_replace( '#^<([a-z0-9]+).*$#is', '$1', $element ) );
			$nom    = array(
				'p'      => 'core/paragraph',
				'hr'     => 'core/separator',
				'figure' => 'core/image',
				'img'    => 'core/image',
			)[ $balise ] ?? 'core/heading';
			$attrs  = array();
			if ( 'core/paragraph' === $nom && preg_match( '/has-text-align-center|text-align:\s*center/i', (string) strtok( $element, '>' ) ) ) {
				$attrs['align'] = 'center';
			}
			$blocs[] = array(
				'blockName'    => $nom,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => $element,
				'innerContent' => array( $element ),
			);
		}
		return $blocs;
	}

	/**
	 * Le bloc est-il centré (attribut align, style textAlign ou classe) ?
	 *
	 * @param array $bloc Bloc.
	 */
	private static function est_centre( array $bloc ): bool {
		$attrs = (array) ( $bloc['attrs'] ?? array() );
		if ( 'center' === ( $attrs['align'] ?? '' ) || 'center' === ( $attrs['textAlign'] ?? '' ) || 'center' === ( $attrs['style']['typography']['textAlign'] ?? '' ) ) {
			return true;
		}
		$ouvrante = (string) strtok( trim( (string) ( $bloc['innerHTML'] ?? '' ) ), '>' );
		return (bool) preg_match( '/has-text-align-center|text-align:\s*center/i', $ouvrante );
	}

	/**
	 * Le fragment est-il entièrement en gras ?
	 *
	 * @param string $html HTML.
	 */
	private static function est_gras( string $html ): bool {
		$html  = (string) preg_replace( '#^\s*<p[^>]*>|</p>\s*$#i', '', trim( $html ) );
		$texte = Html::texte( $html );
		if ( '' === $texte ) {
			return false;
		}
		$gras = '';
		if ( preg_match_all( '#<(strong|b)\b[^>]*>(.*?)</\1>#is', $html, $m ) ) {
			$gras = Html::texte( implode( ' ', $m[2] ) );
		}
		return Html::normaliser( $gras ) === Html::normaliser( $texte );
	}

	/**
	 * Le texte est-il une ligne de crédits (« Traduction – X / Relecture – Y ») ?
	 *
	 * @param string $texte Texte brut.
	 */
	public static function est_credits( string $texte ): bool {
		$n = Html::normaliser( $texte );
		return mb_strlen( $texte, 'UTF-8' ) < 250
			&& (bool) preg_match( '/\btraduct(ion|eur|rice)\b/', $n )
			&& ( (bool) preg_match( '/\b(relecture|relecteur|relectrice|correction|correcteur|check|edition|editeur)\b/', $n ) || false !== strpos( $texte, '「' ) );
	}

	/**
	 * Crédits d'un paragraphe « 「Traduction – X<br>Relecture – Y」 ».
	 *
	 * @param string $html HTML du paragraphe.
	 * @return array{traduction:string,relecture:string,edition:string}
	 */
	public static function credits( string $html ): array {
		$credits = array(
			'traduction' => '',
			'relecture'  => '',
			'edition'    => '',
		);
		$html    = (string) preg_replace( '#^\s*<p[^>]*>|</p>\s*$#i', '', trim( $html ) );
		$texte   = implode( "\n", array_map( array( Html::class, 'texte' ), Html::lignes_html( $html ) ) );
		$texte   = str_replace( array( '「', '」', '『', '』', '[', ']' ), "\n", $texte );
		$motif   = '/(traduction|traducteur|traductrice|relecture|relecteur|relectrice|correction|correcteur|check|[ée]dition|[ée]diteur|[ée]ditrice|clean|mise en page)\s*[:：–—\-]\s*(.+?)(?=\s*(?:\/|\||\n|$|(?:traduction|relecture|correction|[ée]dition)\s*[:：–—\-]))/iu';
		if ( preg_match_all( $motif, $texte, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $credit ) {
				$role = Html::normaliser( $credit[1] );
				$nom  = trim( $credit[2], " \t\n\r\0\x0B-–—:：" );
				if ( '' === $nom ) {
					continue;
				}
				if ( str_starts_with( $role, 'traduct' ) ) {
					$cle = 'traduction';
				} elseif ( preg_match( '/^(relect|correct|check)/', $role ) ) {
					$cle = 'relecture';
				} else {
					$cle = 'edition';
				}
				$credits[ $cle ] = '' === $credits[ $cle ] ? $nom : $credits[ $cle ] . ', ' . $nom;
			}
		}
		return $credits;
	}

	/**
	 * Découpe un paragraphe en morceaux : sur les doubles <br>, et avant chaque réplique
	 * commençant après un <br> simple.
	 *
	 * @param string $html HTML du paragraphe (sans <p>).
	 * @return string[]
	 */
	private static function morceaux( string $html ): array {
		$morceaux = array();
		foreach ( preg_split( '/(?:\s*<br\s*\/?>\s*){2,}/i', $html ) as $partie ) {
			$sous = preg_split( '/<br\s*\/?>(?=(?:\s|&nbsp;|\x{00A0}|<(?:em|strong|b|i|u|s|span)[^>]*>)*[' . Html::TIRETS . '])/iu', $partie );
			foreach ( $sous as $morceau ) {
				if ( '' !== trim( $morceau ) ) {
					$morceaux[] = $morceau;
				}
			}
		}
		return $morceaux;
	}

	/**
	 * Modèle d'un nom de fichier d'image : nom sans chiffres ni paramètres
	 * (« copie-de-copie-de-sw-27.jpg?w=1024 » → « copie-de-copie-de-sw-.jpg »).
	 *
	 * @param string $url URL de l'image.
	 */
	private static function modele_fichier( string $url ): string {
		$nom = strtolower( basename( (string) preg_replace( '/[?#].*$/', '', $url ) ) );
		$nom = (string) preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/', '', $nom );
		return '' === $nom ? '' : (string) preg_replace( '/-+/', '-', (string) preg_replace( '/\d+/', '', $nom ) );
	}

	/**
	 * Analyse un bloc image : navigation (lien vers une page du site) ou illustration.
	 *
	 * @param array    $bloc     Bloc core/image.
	 * @param string[] $domaines Domaines du site.
	 * @param array    $chemins  Chemins connus des pages chapitres (clés).
	 * @param array    $medias   ID → URL source.
	 * @return array{role:string,id:int,url:string,alt:string,lien:string}
	 */
	private static function image( array $bloc, array $domaines, array $chemins, array $medias ): array {
		$html = (string) $bloc['innerHTML'];
		$id   = (int) ( $bloc['attrs']['id'] ?? 0 );
		if ( ! $id && preg_match( '/wp-image-(\d+)/', $html, $m ) ) {
			$id = (int) $m[1];
		}
		$src  = preg_match( '/<img\s[^>]*src="([^"]+)"/i', $html, $m ) ? Html::decoder( $m[1] ) : '';
		$alt  = preg_match( '/<img\s[^>]*alt="([^"]*)"/i', $html, $m ) ? Html::decoder( $m[1] ) : '';
		$lien = Html::premier_href( $html );
		$role = 'illustration';
		if ( '' !== $lien ) {
			$chemin = Html::chemin_local( $lien, $domaines );
			if ( '' !== $chemin && false === strpos( $chemin, '/wp-content/' ) && ! preg_match( '/\.(jpe?g|png|gif|webp|avif)\/?$/i', $chemin ) ) {
				$role = 'navigation';
			} elseif ( isset( $chemins[ $chemin ] ) ) {
				$role = 'navigation';
			}
		}
		$url = $id && ! empty( $medias[ $id ] ) ? (string) $medias[ $id ] : (string) preg_replace( '/\?.*$/', '', $src );
		return array(
			'role' => $role,
			'id'   => $id,
			'url'  => $url,
			'alt'  => $alt,
			'lien' => $lien,
		);
	}
}
