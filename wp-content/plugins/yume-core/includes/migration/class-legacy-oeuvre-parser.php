<?php
/**
 * Analyse d'une fiche œuvre de l'ancien site.
 *
 * Structure rencontrée : un paragraphe d'informations (« Noms : … / Scénario : … /
 * Illustrations : … / Nombre de volumes VO : … / Editeur VO : … / Traduction : … »), un
 * paragraphe « Synopsis : » ou « Résumé : », puis un bloc par tome sous trois formes :
 *   A. media-text : couverture + paragraphe « Tome N » + boutons PDF / EPUB ;
 *   B. media-text : couverture + paragraphe « TOME N | ==> PDF <== | EPUB » suivi du sommaire ;
 *   C. suite de blocs : paragraphe « Tome N », image de couverture, boutons PDF / EPUB.
 * Les web novels découpés en arcs (Silent Witch) listent des media-text « ARC N | TITRE »
 * pointant vers les pages d'arc (voir Legacy_Arc_Parser).
 *
 * Classe pure (aucun accès à la base) : parse_blocks et fonctions de formatage uniquement.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Fiche → œuvre + tomes.
 */
final class Legacy_Oeuvre_Parser {

	/** Champs d'informations dont les liens (fiche AniList, éditeur…) rejoignent les liens de l'œuvre. */
	private const ROLES_LIENS = array(
		'auteur'       => 'Auteur',
		'illustrateur' => 'Illustrations',
		'chara'        => 'Chara-design',
		'mangaka'      => 'Mangaka',
		'editeur_vo'   => 'Éditeur VO',
		'editeur_vf'   => 'Éditeur VF',
	);

	/** Libellés des statuts de traduction (mêmes libellés que yume_statuts() du cœur). */
	public const STATUTS_LIBELLES = array(
		'en-cours'   => 'En cours',
		'terminee'   => 'Terminée',
		'en-pause'   => 'En pause',
		'licenciee'  => 'Licenciée',
		'abandonnee' => 'Abandonnée',
	);

	/** Libellés normalisés du paragraphe d'informations → champ. */
	private const LIBELLES = array(
		'noms'                 => 'noms',
		'nom'                  => 'noms',
		'titres'               => 'noms',
		'scenario'             => 'auteur',
		'scenariste'           => 'auteur',
		'auteur'               => 'auteur',
		'auteure'              => 'auteur',
		'illustrations'        => 'illustrateur',
		'illustration'         => 'illustrateur',
		'illustrateur'         => 'illustrateur',
		'illustratrice'        => 'illustrateur',
		'chara designer'       => 'chara',
		'chara design'         => 'chara',
		'character designer'   => 'chara',
		'mangaka'              => 'mangaka',
		'dessin'               => 'mangaka',
		'nombre de volumes vo' => 'volumes_vo',
		'nombre volumes vo'    => 'volumes_vo',
		'volumes vo'           => 'volumes_vo',
		'editeur vo'           => 'editeur_vo',
		'editeur vf'           => 'editeur_vf',
		'nombre de volumes vf' => 'volumes_vf',
		'nombre volumes vf'    => 'volumes_vf',
		'prix'                 => 'prix_vf',
		'traduction'           => 'statut_fiche',
		'traduction relecture' => 'statut_fiche',
		'statut'               => 'statut_fiche',
		'collab'               => 'collab',
		'collaboration'        => 'collab',
	);

	/**
	 * Titre propre : sans « (LN) », « (WN) », « (Manga) », espaces normalisés.
	 *
	 * @param string $titre Titre de la page.
	 * @return array{titre:string,suffixe:string}
	 */
	public static function titre_propre( string $titre ): array {
		$titre   = trim( (string) preg_replace( '/\s+/u', ' ', Html::decoder( $titre ) ) );
		$suffixe = '';
		if ( preg_match( '/\s*\(\s*(LN|WN|Manga|Light\s*Novel|Web\s*Novel)\s*\)\s*$/iu', $titre, $m ) ) {
			$suffixe = self::type_depuis_mention( $m[1] );
			$titre   = trim( substr( $titre, 0, -strlen( $m[0] ) ) );
		}
		return array(
			'titre'   => $titre,
			'suffixe' => $suffixe,
		);
	}

	/**
	 * Terme yume_type d'une mention (« LN », « WN », « Manga »…), ou chaîne vide.
	 *
	 * @param string $mention Mention.
	 */
	public static function type_depuis_mention( string $mention ): string {
		$n = Html::normaliser( $mention );
		if ( in_array( $n, array( 'ln', 'light novel' ), true ) ) {
			return 'light-novel';
		}
		if ( in_array( $n, array( 'wn', 'web novel' ), true ) ) {
			return 'web-novel';
		}
		return 'manga' === $n ? 'manga' : '';
	}

	/**
	 * Analyse une fiche.
	 *
	 * @param array $page    Page de l'export.
	 * @param array $hub     Entrée du hub pour cette fiche (Legacy_Hub_Parser::parse()['entrees'][slug]), ou vide.
	 * @param array $options 'domaines' => string[].
	 * @return array<string,mixed>
	 */
	public static function parse( array $page, array $hub = array(), array $options = array() ): array {
		$domaines = $options['domaines'] ?? array( 'yumenovel.fr', 'yumenovel.wordpress.com' );
		$avert    = array();
		$source   = (string) ( $page['title'] ?? '' );
		$propre   = self::titre_propre( $source );
		$blocs    = array_values( array_filter( parse_blocks( (string) ( $page['content'] ?? '' ) ), static fn( $b ) => ! empty( $b['blockName'] ) ) );

		$infos    = self::infos( $blocs );
		$synopsis = self::synopsis( $blocs );
		$tomes    = array();
		$arcs     = array();
		self::tomes( $blocs, $tomes, $arcs, $avert, $domaines );

		// Titre : la casse d'un titre alternatif identique est préférée (« Sukasuka » → « SukaSuka »).
		$titre = $propre['titre'];
		foreach ( $infos['titres_alt'] as $alt ) {
			if ( mb_strtolower( $alt, 'UTF-8' ) === mb_strtolower( $titre, 'UTF-8' ) && $alt !== $titre && preg_match( '/\p{Lu}.*\p{Lu}/u', $alt ) ) {
				$titre = $alt;
				break;
			}
		}
		$titres_alt = array_values( array_filter( $infos['titres_alt'], static fn( $alt ) => mb_strtolower( $alt, 'UTF-8' ) !== mb_strtolower( $titre, 'UTF-8' ) ) );

		// Type : mention du titre > hub > champ « Mangaka » > light novel par défaut.
		$type        = $propre['suffixe'];
		$type_indice = '' !== $type ? 'titre' : '';
		if ( '' === $type && 'manga' === ( $hub['type_hub'] ?? '' ) ) {
			$type        = 'manga';
			$type_indice = 'hub';
		}
		if ( '' === $type && '' !== $infos['mangaka'] ) {
			$type        = 'manga';
			$type_indice = 'fiche';
		}
		if ( '' === $type ) {
			$type        = 'light-novel';
			$type_indice = 'defaut';
		}
		if ( 'manga' === ( $hub['type_hub'] ?? '' ) && 'manga' !== $type ) {
			$avert[] = sprintf( 'Fiche « %s » : classée dans le hub Manga mais de type %s.', $titre, $type );
		}

		// Auteur et illustrateur (mangas : mangaka au dessin, chara-designer en complément).
		$auteur       = '' !== $infos['auteur'] ? $infos['auteur'] : $infos['mangaka'];
		$illustrateur = '' !== $infos['mangaka'] ? $infos['mangaka'] : $infos['illustrateur'];
		if ( '' !== $infos['chara'] ) {
			$illustrateur = trim( $illustrateur . ( '' !== $illustrateur ? ', ' : '' ) . $infos['chara'] . ' (chara-design)' );
		}

		$liens = $infos['liens'];
		foreach ( $tomes as $tome ) {
			$libelle = 'ex' === $tome['nature'] ? 'Tome EX' : 'Tome ' . self::numero_fr( $tome['numero'] );
			foreach ( $tome['liens'] as $lien ) {
				if ( 'achat' === $lien['type'] ) {
					$liens[] = array(
						'label' => sprintf( 'Acheter le %s (édition française)', mb_strtolower( $libelle, 'UTF-8' ) ),
						'url'   => $lien['url'],
					);
				} elseif ( 'lecture' === $lien['type'] ) {
					$liens[] = array(
						'label' => sprintf( '%s — lecture en ligne', $libelle ),
						'url'   => $lien['url'],
					);
				}
			}
		}

		$statut = self::statut_yume( $hub, $infos, $tomes, $titre, $avert );

		$contenu = $synopsis['blocs'];
		if ( '' !== $infos['editeur_vf'] ) {
			$vf = '<strong>Édition française :</strong> ' . Html::attr( $infos['editeur_vf'] );
			if ( '' !== $infos['volumes_vf'] ) {
				$vf .= ' — ' . Html::attr( $infos['volumes_vf'] );
			}
			$contenu[] = Blocks::paragraphe( $vf );
		}
		if ( '' === $synopsis['texte'] ) {
			$avert[] = sprintf( 'Fiche « %s » : synopsis introuvable.', $titre );
		}
		if ( ! $tomes && ! $arcs ) {
			$avert[] = sprintf( 'Fiche « %s » : aucun tome reconnu.', $titre );
		}

		return array(
			'source_id'        => (int) ( $page['id'] ?? 0 ),
			'source_slug'      => (string) ( $page['slug'] ?? '' ),
			'source_url'       => (string) ( $page['link'] ?? '' ),
			'source_titre'     => $source,
			'titre'            => $titre,
			'slug'             => sanitize_title( $titre ),
			'type'             => $type,
			'type_indice'      => $type_indice,
			'titres_alt'       => $titres_alt,
			'auteur'           => $auteur,
			'illustrateur'     => $illustrateur,
			'editeur_vo'       => $infos['editeur_vo'],
			'nb_tomes_vo'      => $infos['nb_tomes_vo'],
			'statut_vo'        => $infos['statut_vo'],
			'statut'           => $statut,
			'statut_hub'       => (string) ( $hub['libelle'] ?? '' ),
			'statut_fiche'     => $infos['statut_fiche'],
			'avancement_fiche' => $infos['avancement'],
			'editeur_vf'       => $infos['editeur_vf'],
			'infos_vf'         => array_filter(
				array(
					'editeur' => $infos['editeur_vf'],
					'volumes' => $infos['volumes_vf'],
					'prix'    => $infos['prix_vf'],
				)
			),
			'autres_infos'     => $infos['autres'],
			'synopsis'         => $synopsis['texte'],
			'contenu'          => Blocks::assembler( $contenu ),
			'image_id'         => (int) ( $page['featured_media'] ?? 0 ),
			'banniere_id'      => (int) ( $hub['image_id'] ?? 0 ),
			'banniere_url'     => (string) ( $hub['image_url'] ?? '' ),
			'liens'            => self::liens_uniques( $liens ),
			'tomes'            => $tomes,
			'arcs'             => $arcs,
			'date'             => (string) ( $page['date'] ?? '' ),
			'avertissements'   => $avert,
		);
	}

	/**
	 * Numéro affichable (« 3 », « 26,5 »).
	 *
	 * @param float|null $numero Numéro.
	 */
	public static function numero_fr( ?float $numero ): string {
		if ( null === $numero ) {
			return '';
		}
		$texte = floor( $numero ) === $numero ? (string) (int) $numero : rtrim( rtrim( number_format( $numero, 3, '.', '' ), '0' ), '.' );
		return str_replace( '.', ',', $texte );
	}

	/**
	 * Lit le paragraphe d'informations (« Noms : … »).
	 *
	 * @param array $blocs Blocs de premier niveau.
	 * @return array<string,mixed>
	 */
	private static function infos( array $blocs ): array {
		$infos      = array(
			'titres_alt'   => array(),
			'auteur'       => '',
			'illustrateur' => '',
			'chara'        => '',
			'mangaka'      => '',
			'editeur_vo'   => '',
			'nb_tomes_vo'  => null,
			'statut_vo'    => '',
			'editeur_vf'   => '',
			'volumes_vf'   => '',
			'prix_vf'      => '',
			'statut_fiche' => '',
			'avancement'   => null,
			'liens'        => array(),
			'autres'       => array(),
		);
		$paragraphe = null;
		foreach ( $blocs as $bloc ) {
			if ( 'core/paragraph' === $bloc['blockName'] && preg_match( '/^noms?\b/', Html::normaliser( Html::texte( (string) $bloc['innerHTML'] ) ) ) ) {
				$paragraphe = (string) $bloc['innerHTML'];
				break;
			}
		}
		if ( null === $paragraphe ) {
			return $infos;
		}
		$paragraphe = (string) preg_replace( '#^\s*<p[^>]*>|</p>\s*$#i', '', trim( $paragraphe ) );

		// Lignes, en rattachant les suites commençant par « / » à la ligne précédente.
		$lignes = array();
		foreach ( Html::lignes_html( $paragraphe ) as $ligne ) {
			$texte = Html::texte( $ligne );
			if ( '' === $texte ) {
				continue;
			}
			if ( $lignes && str_starts_with( $texte, '/' ) ) {
				$lignes[ count( $lignes ) - 1 ]['texte'] .= ' ' . $texte;
				$lignes[ count( $lignes ) - 1 ]['html']  .= ' ' . $ligne;
				continue;
			}
			$lignes[] = array(
				'texte' => $texte,
				'html'  => $ligne,
			);
		}

		foreach ( $lignes as $ligne ) {
			if ( ! preg_match( '/^([^:：]{2,40}?)\s*[:：]\s*(.*)$/u', $ligne['texte'], $m ) ) {
				continue;
			}
			$libelle = Html::normaliser( $m[1] );
			$valeur  = trim( $m[2] );
			$champ   = self::LIBELLES[ $libelle ] ?? '';
			switch ( $champ ) {
				case 'noms':
					foreach ( explode( '/', $valeur ) as $nom ) {
						$nom = trim( (string) preg_replace( '/\s+/u', ' ', $nom ) );
						if ( '' !== $nom && ! in_array( $nom, $infos['titres_alt'], true ) ) {
							$infos['titres_alt'][] = $nom;
						}
					}
					break;
				case 'volumes_vo':
					if ( preg_match( '/(\d+)/', $valeur, $n ) ) {
						$infos['nb_tomes_vo'] = (int) $n[1];
					}
					$v = Html::normaliser( $valeur );
					if ( preg_match( '/\btermin/', $v ) ) {
						$infos['statut_vo'] = 'termine';
					} elseif ( preg_match( '/\ben cours\b/', $v ) ) {
						$infos['statut_vo'] = 'en_cours';
					}
					break;
				case 'statut_fiche':
					$infos['statut_fiche'] = $valeur;
					// « En cours (TR : T.11 / REC : T.8) » : traduction jusqu'au T.11, relecture au T.8.
					$tr  = preg_match( '/\bTR\s*:\s*T\.?\s*(\d+)/iu', $valeur, $a ) ? (int) $a[1] : null;
					$rec = preg_match( '/\bREC\s*:\s*T\.?\s*(\d+)/iu', $valeur, $b ) ? (int) $b[1] : null;
					if ( null !== $tr || null !== $rec ) {
						$infos['avancement'] = array(
							'traduction' => $tr,
							'relecture'  => $rec,
						);
					}
					break;
				case 'collab':
					foreach ( Html::liens( $ligne['html'] ) as $lien ) {
						$infos['liens'][] = array(
							'label' => ( '' !== $lien['texte'] ? $lien['texte'] : $valeur ) . ' (collaboration)',
							'url'   => $lien['url'],
						);
					}
					break;
				case '':
					$infos['autres'][ trim( $m[1] ) ] = $valeur;
					break;
				default:
					$infos[ $champ ] = $valeur;
					// Noms liés (auteur sur AniList, éditeur sur Nautiljon…) : le lien est gardé
					// dans les liens de l'œuvre, le champ ne reçoit que le texte.
					if ( isset( self::ROLES_LIENS[ $champ ] ) ) {
						foreach ( Html::liens( $ligne['html'] ) as $lien ) {
							if ( ! preg_match( '#^https?://#i', $lien['url'] ) ) {
								continue;
							}
							$infos['liens'][] = array(
								'label' => sprintf( '%s : %s (%s)', self::ROLES_LIENS[ $champ ], '' !== $lien['texte'] ? $lien['texte'] : $valeur, self::site_lien( $lien['url'] ) ),
								'url'   => $lien['url'],
							);
						}
					}
			}
		}
		return $infos;
	}

	/**
	 * Nom lisible du site d'un lien (« AniList », « Nautiljon », sinon le domaine).
	 *
	 * @param string $url URL.
	 */
	public static function site_lien( string $url ): string {
		$hote = strtolower( (string) preg_replace( '/^www\./i', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		$noms = array(
			'anilist.co'       => 'AniList',
			'nautiljon.com'    => 'Nautiljon',
			'myanimelist.net'  => 'MyAnimeList',
			'mangaupdates.com' => 'MangaUpdates',
			'novelupdates.com' => 'NovelUpdates',
		);
		return $noms[ $hote ] ?? ( '' !== $hote ? $hote : $url );
	}

	/**
	 * Libellé français d'un statut de traduction (« abandonnee » → « Abandonnée »).
	 *
	 * @param string $slug Slug du terme yume_statut.
	 */
	public static function libelle_statut( string $slug ): string {
		return self::STATUTS_LIBELLES[ $slug ] ?? $slug;
	}

	/**
	 * Lit le synopsis (paragraphe commençant par « Synopsis » ou « Résumé »).
	 *
	 * @param array $blocs Blocs de premier niveau.
	 * @return array{texte:string,blocs:string[]}
	 */
	private static function synopsis( array $blocs ): array {
		foreach ( $blocs as $bloc ) {
			if ( 'core/paragraph' !== $bloc['blockName'] ) {
				continue;
			}
			$texte = Html::normaliser( Html::texte( (string) $bloc['innerHTML'] ) );
			if ( ! preg_match( '/^(synopsis|resume)\b/', $texte ) ) {
				continue;
			}
			$html = Html::nettoyer_inline( (string) preg_replace( '#^\s*<p[^>]*>|</p>\s*$#i', '', trim( (string) $bloc['innerHTML'] ) ) );
			$html = (string) preg_replace( '/^(?:\s*<br>\s*)+/u', '', $html );
			$html = (string) preg_replace( '/^((?:<(?:em|strong)>)*)\s*(?:synopsis|résumé|resume)\s*((?:<\/(?:em|strong)>)*)\s*[:：]\s*((?:<\/(?:em|strong)>)*)\s*/iu', '$1$2$3', $html, 1 );
			do {
				$avant = $html;
				$html  = (string) preg_replace( '#<(em|strong)>\s*</\1>#u', '', $html );
			} while ( $avant !== $html );
			$paras = array();
			$texte = array();
			foreach ( preg_split( '/\s*<br>\s*/u', $html ) as $morceau ) {
				$morceau = self::equilibrer( trim( $morceau ) );
				if ( '' === Html::texte( $morceau ) ) {
					continue;
				}
				if ( Html::entierement_italique( $morceau ) ) {
					$morceau = Html::sans_italique( $morceau );
				}
				$paras[] = Blocks::paragraphe( $morceau );
				$texte[] = Html::texte( $morceau );
			}
			return array(
				'texte' => implode( "\n\n", $texte ),
				'blocs' => $paras,
			);
		}
		return array(
			'texte' => '',
			'blocs' => array(),
		);
	}

	/**
	 * Referme ou retire les balises en ligne déséquilibrées d'un morceau découpé sur <br>.
	 *
	 * @param string $html Fragment nettoyé.
	 */
	public static function equilibrer( string $html ): string {
		return Html::nettoyer_inline( $html );
	}

	/**
	 * Parcourt les blocs de premier niveau et en extrait tomes et arcs.
	 *
	 * @param array    $blocs    Blocs.
	 * @param array    $tomes    Tomes (sortie).
	 * @param array    $arcs     Arcs (sortie).
	 * @param string[] $avert    Avertissements (sortie).
	 * @param string[] $domaines Domaines du site.
	 */
	private static function tomes( array $blocs, array &$tomes, array &$arcs, array &$avert, array $domaines ): void {
		$courant = null;
		$fermer  = static function () use ( &$courant, &$tomes ): void {
			if ( null !== $courant ) {
				self::marquer_achat( $courant );
				$tomes[] = $courant;
				$courant = null;
			}
		};
		foreach ( $blocs as $index => $bloc ) {
			$nom = $bloc['blockName'];
			if ( 'core/media-text' === $nom ) {
				$fermer();
				$resultat = self::media_text( $bloc, $index, $domaines, $avert );
				if ( isset( $resultat['arc'] ) ) {
					$arcs[] = $resultat['arc'];
				} elseif ( isset( $resultat['tome'] ) ) {
					$tomes[] = $resultat['tome'];
				}
				continue;
			}
			if ( 'core/paragraph' === $nom ) {
				$texte  = Html::texte( (string) $bloc['innerHTML'] );
				$entete = self::entete_tome( $texte );
				if ( null !== $entete && mb_strlen( $texte, 'UTF-8' ) <= 20 ) {
					$fermer();
					$courant = self::tome_vide( $entete, $index );
					self::marquer_achat( $courant, $texte );
				}
				continue;
			}
			if ( null === $courant ) {
				continue;
			}
			if ( 'core/image' === $nom && 0 === $courant['couverture_id'] ) {
				$html                      = (string) $bloc['innerHTML'];
				$courant['couverture_id']  = (int) ( $bloc['attrs']['id'] ?? ( preg_match( '/wp-image-(\d+)/', $html, $m ) ? $m[1] : 0 ) );
				$courant['couverture_url'] = preg_match( '/<img\s[^>]*src="([^"]+)"/i', $html, $m ) ? Html::decoder( $m[1] ) : '';
			} elseif ( 'core/buttons' === $nom ) {
				self::liens_boutons( $bloc, $courant, $avert );
			}
		}
		$fermer();
	}

	/**
	 * Reconnaît un en-tête de tome : « Tome 3 », « TOME 3 | … », « EX | … », « Volume 2 ».
	 *
	 * @param string $texte Texte de la première ligne.
	 * @return array{numero:?float,nature:string}|null
	 */
	public static function entete_tome( string $texte ): ?array {
		$texte = trim( $texte );
		if ( preg_match( '/^(?:tome|volume|vol\.?)\s*(\d+(?:[.,]\d+)?)\b/iu', $texte, $m ) ) {
			return array(
				'numero' => Html::nombre( $m[1] ),
				'nature' => 'tome',
			);
		}
		if ( preg_match( '/^(?:tome\s+)?ex\b/iu', $texte ) ) {
			return array(
				'numero' => null,
				'nature' => 'ex',
			);
		}
		return null;
	}

	/**
	 * Structure d'un tome vide.
	 *
	 * @param array $entete Résultat d'entete_tome().
	 * @param int   $index  Index du bloc source.
	 * @return array<string,mixed>
	 */
	private static function tome_vide( array $entete, int $index ): array {
		return array(
			'numero'          => $entete['numero'],
			'nature'          => $entete['nature'],
			'libelle_source'  => '',
			'couverture_id'   => 0,
			'couverture_url'  => '',
			'lien_pdf'        => '',
			'lien_epub'       => '',
			'liens'           => array(),
			'boutons_vides'   => array(),
			'sommaire'        => array(),
			'chapitres_plage' => null,
			'a_venir'         => false,
			'achat_mentionne' => false,
			'bloc_index'      => $index,
		);
	}

	/**
	 * Marque un tome que la fiche propose à l'achat (lien « Acheter », bouton ou simple mention
	 * « ==> Acheter <== » sans lien) : édition française, les fichiers de l'équipe sont retirés.
	 *
	 * @param array  $tome  Tome (par référence).
	 * @param string $texte Texte de l'en-tête ou du paragraphe du tome.
	 */
	private static function marquer_achat( array &$tome, string $texte = '' ): void {
		$achat = (bool) preg_match( '/\bachet/', Html::normaliser( $texte ) );
		foreach ( $tome['liens'] as $lien ) {
			$achat = $achat || 'achat' === $lien['type'];
		}
		foreach ( $tome['boutons_vides'] as $bouton ) {
			$achat = $achat || (bool) preg_match( '/\bachet/', Html::normaliser( (string) $bouton ) );
		}
		$tome['achat_mentionne'] = $tome['achat_mentionne'] || $achat;
	}

	/**
	 * Analyse un bloc media-text : tome (formes A et B) ou lien vers une page d'arc.
	 *
	 * @param array    $bloc     Bloc.
	 * @param int      $index    Index du bloc.
	 * @param string[] $domaines Domaines du site.
	 * @param string[] $avert    Avertissements (sortie).
	 * @return array{tome?:array,arc?:array}
	 */
	private static function media_text( array $bloc, int $index, array $domaines, array &$avert ): array {
		$html_media = (string) $bloc['innerHTML'];
		$image_id   = (int) ( $bloc['attrs']['mediaId'] ?? ( preg_match( '/wp-image-(\d+)/', $html_media, $m ) ? $m[1] : 0 ) );
		$image_url  = preg_match( '/<img\s[^>]*src="([^"]+)"/i', $html_media, $m ) ? Html::decoder( $m[1] ) : '';

		$paragraphes = array();
		$boutons     = array();
		foreach ( Legacy_Hub_Parser::aplatir( $bloc['innerBlocks'] ?? array() ) as $enfant ) {
			if ( in_array( $enfant['blockName'], array( 'core/paragraph', 'core/heading' ), true ) ) {
				$paragraphes[] = (string) preg_replace( '#^\s*<(?:p|h\d)[^>]*>|</(?:p|h\d)>\s*$#i', '', trim( (string) $enfant['innerHTML'] ) );
			} elseif ( 'core/button' === $enfant['blockName'] ) {
				$boutons[] = $enfant;
			}
		}
		$premier = '';
		foreach ( $paragraphes as $p ) {
			if ( '' !== Html::texte( $p ) ) {
				$premier = $p;
				break;
			}
		}
		if ( '' === $premier ) {
			$avert[] = sprintf( 'Bloc media-text %d sans texte (image %d) ignoré.', $index, $image_id );
			return array();
		}

		// Lien vers une page d'arc : « ARC 3 | CONSEIL DES ÉLÈVES ».
		$texte_premier = Html::texte( $premier );
		if ( preg_match( '/^arc\s*(\d+)\s*[|:–—-]\s*(.+)$/iu', $texte_premier, $m ) ) {
			return array(
				'arc' => array(
					'numero'         => (int) $m[1],
					'titre'          => Html::casse_phrase( trim( $m[2] ) ),
					'url'            => Html::premier_href( $premier ),
					'chemin'         => Html::chemin_local( Html::premier_href( $premier ), $domaines ),
					'couverture_id'  => $image_id,
					'couverture_url' => $image_url,
					'bloc_index'     => $index,
				),
			);
		}

		// Pré-nettoyage : <s><br></s> et <s></s> issus de l'éditeur ne barrent aucun texte.
		$premier = (string) preg_replace( '#<s>((?:\s*<br\s*/?>\s*)*)</s>#i', '$1', $premier );
		$lignes  = Html::lignes_html( $premier );
		$entete  = self::entete_tome( Html::texte( $lignes[0] ) );
		if ( null === $entete ) {
			$avert[] = sprintf( 'Bloc media-text %d non reconnu comme tome : « %s ».', $index, mb_substr( $texte_premier, 0, 60, 'UTF-8' ) );
			return array();
		}
		$tome                   = self::tome_vide( $entete, $index );
		$tome['libelle_source'] = Html::texte( $lignes[0] );
		$tome['couverture_id']  = $image_id;
		$tome['couverture_url'] = $image_url;
		if ( preg_match( '/bient[ôo]t/iu', $texte_premier ) ) {
			$tome['a_venir'] = true;
		}

		// Liens des paragraphes, classés d'après le texte du lien et de sa ligne.
		foreach ( $paragraphes as $p ) {
			foreach ( Html::lignes_html( $p ) as $ligne ) {
				$texte_ligne = Html::texte( $ligne );
				foreach ( Html::liens( $ligne ) as $lien ) {
					self::ajouter_lien( $tome, $lien['url'], $lien['texte'], $texte_ligne );
				}
			}
		}
		foreach ( $boutons as $bouton ) {
			self::lien_bouton( $bouton, $tome, $avert );
		}

		// Plage de chapitres (mangas) : « C1 à C6.5 ».
		$plage = self::plage_chapitres( Html::texte( $lignes[0] ) );
		if ( null === $plage && isset( $lignes[1] ) ) {
			$plage = self::plage_chapitres( Html::texte( $lignes[1] ) );
		}
		$tome['chapitres_plage'] = $plage;

		// Sommaire : lignes suivant l'en-tête (les lignes barrées ne sont pas encore traduites).
		$ouvert = self::solde_barre( $lignes[0] ) > 0;
		$barres = 0;
		foreach ( array_slice( $lignes, 1 ) as $ligne ) {
			$barre  = $ouvert || false !== stripos( $ligne, '<s>' );
			$ouvert = ( $ouvert ? 1 : 0 ) + self::solde_barre( $ligne ) > 0;
			$texte  = Html::texte( $ligne );
			if ( '' === $texte || self::est_ligne_lien( $ligne, $texte ) || null !== self::plage_chapitres( $texte ) ) {
				continue;
			}
			$element = self::element_sommaire( $texte, $tome['sommaire'] );
			if ( null === $element ) {
				continue;
			}
			$element['barre']   = $barre;
			$barres            += $barre ? 1 : 0;
			$tome['sommaire'][] = $element;
		}
		if ( $tome['sommaire'] && count( $tome['sommaire'] ) === $barres ) {
			$tome['a_venir'] = true;
		}
		self::marquer_achat( $tome, Html::texte( $lignes[0] ) );
		if ( ! $tome['a_venir'] && '' === $tome['lien_pdf'] && '' === $tome['lien_epub'] && ! array_filter( $tome['liens'], static fn( $l ) => in_array( $l['type'], array( 'achat', 'lecture' ), true ) ) ) {
			$avert[] = sprintf( '%s : aucun lien de téléchargement, d’achat ou de lecture.', $tome['libelle_source'] );
		}
		return array( 'tome' => $tome );
	}

	/**
	 * Solde des balises <s> d'une ligne (ouvrantes − fermantes).
	 *
	 * @param string $html HTML de la ligne.
	 */
	private static function solde_barre( string $html ): int {
		$html = strtolower( $html );
		return substr_count( $html, '<s>' ) - substr_count( $html, '</s>' );
	}

	/**
	 * Une ligne ne contient-elle qu'un lien (« ==> Acheter <== », « Lecture en ligne : ICI ») ?
	 *
	 * @param string $html  HTML de la ligne.
	 * @param string $texte Texte de la ligne.
	 */
	private static function est_ligne_lien( string $html, string $texte ): bool {
		$reste = $texte;
		foreach ( Html::liens( $html ) as $lien ) {
			$reste = str_replace( $lien['texte'], '', $reste );
		}
		$reste = trim( (string) preg_replace( '/[=<>|\/\s:.!-]+/u', ' ', $reste ) );
		return '' === $reste || (bool) preg_match( '/^(lecture en ligne|acheter|lire|pdf|epub)$/iu', $reste );
	}

	/**
	 * Plage de chapitres « C1 à C6.5 » / « c10 à c17 » → ['de' => 1.0, 'a' => 6.5].
	 *
	 * @param string $texte Texte.
	 * @return array{de:float,a:float}|null
	 */
	public static function plage_chapitres( string $texte ): ?array {
		if ( preg_match( '/\bc\s*(\d+(?:[.,]\d+)?)\s*(?:à|a|-|–)\s*c?\s*(\d+(?:[.,]\d+)?)/iu', $texte, $m ) ) {
			return array(
				'de' => (float) Html::nombre( $m[1] ),
				'a'  => (float) Html::nombre( $m[2] ),
			);
		}
		return null;
	}

	/**
	 * Élément de sommaire depuis une ligne : « 3 : Titre », « Prologue — Titre », « Postface »…
	 * Une ligne qui prolonge un titre terminé par un tiret est rattachée à l'élément précédent.
	 *
	 * @param string $texte    Texte de la ligne.
	 * @param array  $sommaire Sommaire en cours (par référence pour les prolongements).
	 * @return array{numero:?float,nature:string,titre:string}|null
	 */
	private static function element_sommaire( string $texte, array &$sommaire ): ?array {
		if ( preg_match( '/^(\d+(?:[.,]\d+)?)\s*[:：]\s*(.*)$/u', $texte, $m ) || preg_match( '/^(\d+(?:[.,]\d+)?)\s*[–—-]\s+(.*)$/u', $texte, $m ) ) {
			return array(
				'numero' => Html::nombre( $m[1] ),
				'nature' => 'chapitre',
				'titre'  => trim( $m[2] ),
			);
		}
		if ( preg_match( '/^(prologue|[ée]pilogue|postface|interlude|bonus)\b\s*[—–:\-\/]*\s*(.*)$/iu', $texte, $m ) ) {
			$natures = array(
				'prologue'  => 'prologue',
				'epilogue'  => 'epilogue',
				'postface'  => 'postface',
				'interlude' => 'interlude',
				'bonus'     => 'bonus',
			);
			return array(
				'numero' => null,
				'nature' => $natures[ Html::normaliser( $m[1] ) ] ?? 'bonus',
				'titre'  => trim( $m[2] ),
			);
		}
		$dernier = count( $sommaire ) - 1;
		if ( $dernier >= 0 && preg_match( '/[—–-]\s*$/u', $sommaire[ $dernier ]['titre'] ) ) {
			$sommaire[ $dernier ]['titre'] = trim( $sommaire[ $dernier ]['titre'] ) . ' ' . $texte;
			return null;
		}
		return array(
			'numero' => null,
			'nature' => 'bonus',
			'titre'  => $texte,
		);
	}

	/**
	 * Ajoute un lien classé au tome et renseigne lien_pdf / lien_epub.
	 *
	 * @param array  $tome        Tome (par référence).
	 * @param string $url         URL.
	 * @param string $texte_lien  Texte du lien.
	 * @param string $texte_ligne Texte de la ligne qui contient le lien.
	 */
	private static function ajouter_lien( array &$tome, string $url, string $texte_lien, string $texte_ligne ): void {
		$type = self::type_lien( $url, $texte_lien, $texte_ligne );
		foreach ( $tome['liens'] as $existant ) {
			if ( $existant['url'] === $url && $existant['type'] === $type ) {
				return;
			}
		}
		$tome['liens'][] = array(
			'type'  => $type,
			'url'   => $url,
			'texte' => $texte_lien,
		);
		if ( 'pdf' === $type && '' === $tome['lien_pdf'] ) {
			$tome['lien_pdf'] = $url;
		} elseif ( 'epub' === $type && '' === $tome['lien_epub'] ) {
			$tome['lien_epub'] = $url;
		}
	}

	/**
	 * Type d'un lien : pdf, epub, achat, lecture ou autre.
	 *
	 * @param string $url         URL.
	 * @param string $texte_lien  Texte du lien.
	 * @param string $texte_ligne Texte de la ligne.
	 */
	public static function type_lien( string $url, string $texte_lien, string $texte_ligne = '' ): string {
		$lien  = Html::normaliser( $texte_lien );
		$ligne = Html::normaliser( $texte_ligne );
		if ( preg_match( '/\bepub\b/', $lien ) ) {
			return 'epub';
		}
		if ( preg_match( '/\bpdf\b/', $lien ) ) {
			return 'pdf';
		}
		if ( preg_match( '/\bachet/', $lien ) ) {
			return 'achat';
		}
		if ( preg_match( '/mangadex\.org/i', $url ) || preg_match( '/\blecture en ligne\b/', $ligne ) || preg_match( '/^(ici|lire)$/', $lien ) ) {
			return 'lecture';
		}
		return 'autre';
	}

	/**
	 * Liens des boutons d'un groupe core/buttons.
	 *
	 * @param array    $bloc  Bloc core/buttons.
	 * @param array    $tome  Tome (par référence).
	 * @param string[] $avert Avertissements (par référence).
	 */
	private static function liens_boutons( array $bloc, array &$tome, array &$avert ): void {
		foreach ( $bloc['innerBlocks'] ?? array() as $bouton ) {
			if ( 'core/button' === $bouton['blockName'] ) {
				self::lien_bouton( $bouton, $tome, $avert );
			}
		}
	}

	/**
	 * Lien d'un bouton (core/button) ; un bouton sans href est consigné.
	 *
	 * @param array    $bouton Bloc core/button.
	 * @param array    $tome   Tome (par référence).
	 * @param string[] $avert  Avertissements (par référence).
	 */
	private static function lien_bouton( array $bouton, array &$tome, array &$avert ): void {
		$html  = (string) $bouton['innerHTML'];
		$texte = Html::texte( $html );
		$url   = Html::premier_href( $html );
		if ( '' === $url ) {
			$tome['boutons_vides'][] = $texte;
			$libelle                 = 'ex' === $tome['nature'] ? 'Tome EX' : 'Tome ' . self::numero_fr( $tome['numero'] );
			$avert[]                 = sprintf( '%s : bouton « %s » sans lien.', $libelle, $texte );
			return;
		}
		self::ajouter_lien( $tome, $url, $texte, $texte );
	}

	/**
	 * Supprime les doublons (même URL) d'une liste de liens {label, url}.
	 *
	 * @param array $liens Liens.
	 * @return array<int,array{label:string,url:string}>
	 */
	private static function liens_uniques( array $liens ): array {
		$vus    = array();
		$sortie = array();
		foreach ( $liens as $lien ) {
			if ( empty( $lien['url'] ) || isset( $vus[ $lien['url'] ] ) ) {
				continue;
			}
			$vus[ $lien['url'] ] = true;
			$sortie[]            = array(
				'label' => (string) $lien['label'],
				'url'   => (string) $lien['url'],
			);
		}
		return $sortie;
	}

	/**
	 * Statut Yume (terme yume_statut) depuis le hub, départagé par la fiche si le libellé
	 * du hub est ambigu (« Licenciée/Abandonnée », « Abandonnée/En pause »).
	 *
	 * @param array    $hub   Entrée du hub.
	 * @param array    $infos Informations de la fiche.
	 * @param array    $tomes Tomes.
	 * @param string   $titre Titre de l'œuvre.
	 * @param string[] $avert Avertissements (par référence).
	 */
	private static function statut_yume( array $hub, array $infos, array $tomes, string $titre, array &$avert ): string {
		$candidats = (array) ( $hub['statuts'] ?? array() );
		$fiche     = Html::normaliser( $infos['statut_fiche'] );
		$achat     = '' !== $infos['editeur_vf'];
		foreach ( $tomes as $tome ) {
			foreach ( $tome['liens'] as $lien ) {
				$achat = $achat || 'achat' === $lien['type'];
			}
		}
		$depuis_fiche = '';
		if ( preg_match( '/\btermin/', $fiche ) ) {
			$depuis_fiche = 'terminee';
		} elseif ( preg_match( '/\bpause\b/', $fiche ) ) {
			$depuis_fiche = 'en-pause';
		} elseif ( preg_match( '/\ben cours\b/', $fiche ) ) {
			$depuis_fiche = 'en-cours';
		}

		if ( 1 === count( $candidats ) ) {
			$statut = $candidats[0];
		} elseif ( count( $candidats ) > 1 ) {
			if ( in_array( 'licenciee', $candidats, true ) && $achat ) {
				$statut = 'licenciee';
			} elseif ( in_array( 'en-pause', $candidats, true ) && ( 'en-pause' === $depuis_fiche || ! in_array( 'abandonnee', $candidats, true ) ) ) {
				$statut = 'en-pause';
			} elseif ( in_array( 'abandonnee', $candidats, true ) && ! in_array( 'en-pause', $candidats, true ) ) {
				$statut = 'abandonnee';
			} elseif ( in_array( 'en-pause', $candidats, true ) ) {
				$statut = 'en-pause';
			} else {
				$statut = $candidats[0];
			}
			$avert[] = sprintf( '« %s » : statut ambigu dans le hub (« %s ») → « %s » retenu, à confirmer par l’équipe.', $titre, $hub['libelle'] ?? '', self::libelle_statut( $statut ) );
		} elseif ( '' !== $depuis_fiche ) {
			$statut  = $depuis_fiche;
			$avert[] = sprintf( '« %s » : absente des hubs, statut déduit de la fiche (« %s »).', $titre, self::libelle_statut( $statut ) );
		} else {
			$statut  = 'en-cours';
			$avert[] = sprintf( '« %s » : statut introuvable (ni hub ni fiche) → « %s » par défaut.', $titre, self::libelle_statut( $statut ) );
		}

		if ( '' !== $depuis_fiche && $depuis_fiche !== $statut && ! ( 'en-cours' === $depuis_fiche && 'en-cours' === $statut ) && $candidats ) {
			$avert[] = sprintf( '« %s » : la fiche indique « %s » mais le hub classe la série en « %s » (hub retenu, plus récent).', $titre, $infos['statut_fiche'], $hub['libelle'] ?? $statut );
		}
		return $statut;
	}
}
