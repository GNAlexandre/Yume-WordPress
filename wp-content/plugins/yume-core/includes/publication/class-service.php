<?php
/**
 * Service de publication (contrat §8) : du fichier source aux brouillons, puis à la sortie.
 *
 * Préparation (preparer()) :
 * - le tome de même œuvre + nature + numéro est RÉUTILISÉ s'il existe (brouillon créé par le
 *   planning ou tome déjà publié) : son adresse est conservée ; sinon il est créé en brouillon ;
 * - les chapitres sont créés ou mis à jour en place, par numéro (jamais de doublon) ; les
 *   chapitres absents du nouveau fichier sont signalés (mis en brouillon seulement avec
 *   l'option retirer_absents) ;
 * - images versées dans la médiathèque et rattachées au chapitre, galerie du tome, couverture ;
 * - métadonnées du contrat §4 (yume_source : format, empreinte, date d'import) ;
 * - article d'annonce en brouillon ;
 * - le fichier source téléversé est supprimé du serveur, même en cas d'erreur.
 *
 * Sortie (publier()) : yume_publication_en_cours, puis chapitres, tome et annonce publiés (ou programmés
 * à la date donnée), puis yume_tome_publie une seule fois pour une publication immédiate.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

use Yume\Core\Import\Blocks;
use Yume\Core\Import\Docx_Converter;
use Yume\Core\Import\Epub_Converter;
use Yume\Core\Import\Import_Exception;
use Yume\Core\Import\Result;
use Yume\Core\Import\Texte;

defined( 'ABSPATH' ) || exit;

/**
 * Publication d'un tome.
 */
final class Service {

	/** Méta du tome : dernière préparation (champs, fichier, rapport résumé). */
	public const META = '_yume_publication';

	/** Méta d'un chapitre retiré (absent du dernier fichier) : jamais republié automatiquement. */
	public const META_RETIRE = '_yume_retire';

	/** Méta de core : événement de publication traité. */
	private const META_NOTIFIE = '_yume_publie_notifie';

	/**
	 * Relève les limites de temps et de mémoire pendant un traitement lourd.
	 */
	public static function relever_limites(): void {
		wp_raise_memory_limit( 'yume_publication' );
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
	}

	/**
	 * Convertit un fichier source contrôlé (Fichiers::source()).
	 *
	 * @param array<string,mixed> $source Fichier source.
	 * @return Result|\WP_Error
	 */
	public static function convertir( array $source ) {
		/**
		 * Options du convertisseur (typographie…).
		 *
		 * @param array<string,mixed> $options Options.
		 * @param array<string,mixed> $source  Fichier source.
		 */
		$options = (array) apply_filters( 'yume_publication_options_import', array(), $source );
		try {
			return 'epub' === $source['format']
				? Epub_Converter::convert_file( (string) $source['chemin'], $options )
				: Docx_Converter::convert_file( (string) $source['chemin'], $options );
		} catch ( Import_Exception $e ) {
			return new \WP_Error( 'yume_import_' . $e->code_erreur(), $e->getMessage(), array( 'status' => 422 ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'yume_import',
				/* translators: %s : détail technique */
				sprintf( __( 'Le fichier n’a pas pu être analysé (%s).', 'yume-core' ), $e->getMessage() ),
				array( 'status' => 422 )
			);
		}
	}

	/**
	 * Libellé d'un tome à partir de sa nature et de son numéro (« Tome 10 », « Arc 7 »).
	 *
	 * @param string     $nature Nature.
	 * @param float|null $numero Numéro.
	 */
	public static function libelle_tome( string $nature, ?float $numero ): string {
		$natures = yume_natures_tome();
		$libelle = (string) ( $natures[ $nature ] ?? $natures['tome'] );
		return null === $numero ? $libelle : $libelle . ' ' . Texte::numero_fr( $numero );
	}

	/**
	 * Résumé lisible d'une conversion (« 19 chapitres + postface · 10 illustrations · 6 ornements EMF ignorés »).
	 *
	 * @param Result $resultat Résultat.
	 */
	public static function resume( Result $resultat ): string {
		$numerotes = 0;
		$speciaux  = array();
		foreach ( $resultat->chapters as $chapitre ) {
			if ( 'chapitre' === $chapitre['nature'] ) {
				++$numerotes;
			} else {
				$speciaux[] = mb_strtolower( (string) $chapitre['titre'] );
			}
		}
		$parts = array();
		if ( $numerotes || ! $speciaux ) {
			/* translators: %d : nombre de chapitres */
			$parts[] = sprintf( _n( '%d chapitre', '%d chapitres', $numerotes, 'yume-core' ), $numerotes ) . ( $speciaux ? ' + ' . implode( ', ', $speciaux ) : '' );
		} else {
			$parts[] = implode( ', ', $speciaux );
		}
		$images = count( $resultat->images );
		/* translators: %d : nombre d'illustrations */
		$parts[] = sprintf( _n( '%d illustration', '%d illustrations', $images, 'yume-core' ), $images );
		$emf     = (int) ( $resultat->stats['images_emf'] ?? 0 );
		if ( $emf ) {
			/* translators: %d : nombre d'ornements EMF */
			$parts[] = sprintf( _n( '%d ornement EMF ignoré', '%d ornements EMF ignorés', $emf, 'yume-core' ), $emf );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Rapport d'analyse (rien n'est créé).
	 *
	 * @param Result              $resultat Résultat.
	 * @param array<string,mixed> $source   Fichier source.
	 * @return array<string,mixed>
	 */
	public static function rapport_analyse( Result $resultat, array $source ): array {
		$rapport = $resultat->rapport();
		foreach ( $rapport['chapitres'] as $i => $chapitre ) {
			$rapport['chapitres'][ $i ]['libelle'] = Result::libelle( $chapitre );
		}
		$rapport['fichier'] = array(
			'nom'    => (string) $source['nom'],
			'format' => (string) $source['format'],
			'octets' => (int) $source['octets'],
			'taille' => Fichiers::taille_lisible( (int) $source['octets'] ),
		);
		$rapport['resume']  = self::resume( $resultat );
		unset( $rapport['stats']['hash'] );
		return $rapport;
	}

	/**
	 * Analyse un fichier téléversé sans rien créer (le fichier est ensuite supprimé).
	 *
	 * @param array<string,mixed> $fichier Entrée de $_FILES.
	 * @param array<string,mixed> $champs  oeuvre_id, nature, numero (facultatifs : tome existant).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function analyser( array $fichier, array $champs = array() ) {
		self::relever_limites();
		try {
			$source = Fichiers::source( $fichier );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$resultat = self::convertir( $source );
			if ( is_wp_error( $resultat ) ) {
				return $resultat;
			}
			$rapport                  = self::rapport_analyse( $resultat, $source );
			$rapport['tome_existant'] = null;
			$oeuvre_id                = absint( $champs['oeuvre_id'] ?? 0 );
			if ( $oeuvre_id && 'yume_oeuvre' === get_post_type( $oeuvre_id ) ) {
				$nature = sanitize_key( (string) ( $champs['nature'] ?? 'tome' ) );
				$tome   = self::trouver_tome( $oeuvre_id, isset( yume_natures_tome()[ $nature ] ) ? $nature : 'tome', self::numero( $champs['numero'] ?? '' ) );
				if ( $tome ) {
					$rapport['tome_existant'] = self::resume_contenu( $tome );
				}
			}
			return $rapport;
		} finally {
			Fichiers::supprimer( $fichier );
		}
	}

	/**
	 * Numéro saisi (« 10 », « 26,5 ») ou null.
	 *
	 * @param mixed $valeur Valeur.
	 */
	public static function numero( $valeur ): ?float {
		if ( is_string( $valeur ) ) {
			$valeur = str_replace( ',', '.', trim( $valeur ) );
		}
		if ( '' === $valeur || null === $valeur || ! is_numeric( $valeur ) || (float) $valeur < 0 ) {
			return null;
		}
		return round( (float) $valeur, 3 );
	}

	/**
	 * Contrôle un lien externe de téléchargement (PDF ou EPUB).
	 *
	 * @param mixed  $valeur  Saisie.
	 * @param string $libelle « PDF » ou « EPUB ».
	 * @return string|\WP_Error URL nettoyée ('' si vide).
	 */
	public static function lien_externe( $valeur, string $libelle ) {
		$url = is_scalar( $valeur ) ? trim( (string) $valeur ) : '';
		if ( '' === $url ) {
			return '';
		}
		$propre = esc_url_raw( $url, array( 'http', 'https' ) );
		$hote   = (string) wp_parse_url( $propre, PHP_URL_HOST );
		if ( '' === $propre || '' === $hote || false === filter_var( $propre, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error(
				'yume_lien_invalide',
				/* translators: %s : PDF ou EPUB */
				sprintf( __( 'Le lien %s doit être une adresse web complète (https://…).', 'yume-core' ), $libelle ),
				array( 'status' => 400 )
			);
		}
		$site    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$chemin  = strtolower( (string) wp_parse_url( $propre, PHP_URL_PATH ) );
		$uploads = wp_get_upload_dir();
		if ( ( $hote === $site && preg_match( '/\.(pdf|epub)$/', $chemin ) ) || str_starts_with( $propre, (string) $uploads['baseurl'] ) ) {
			return new \WP_Error(
				'yume_lien_heberge',
				/* translators: %s : PDF ou EPUB */
				sprintf( __( 'Le fichier %s n’est jamais hébergé sur le site : indiquez un lien de téléchargement externe (ClicTune, Mega…).', 'yume-core' ), $libelle ),
				array( 'status' => 400 )
			);
		}
		return $propre;
	}

	/**
	 * Normalise et contrôle les champs d'une publication.
	 *
	 * @param array<string,mixed> $brut Champs reçus.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function champs( array $brut ) {
		$natures = yume_natures_tome();
		$nature  = sanitize_key( (string) ( $brut['nature'] ?? 'tome' ) );
		if ( '' === $nature ) {
			$nature = 'tome';
		}
		if ( ! isset( $natures[ $nature ] ) ) {
			return new \WP_Error( 'yume_nature_invalide', __( 'Nature de tome inconnue.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$champs       = array(
			'oeuvre_id'       => absint( $brut['oeuvre_id'] ?? 0 ),
			'tome_id'         => absint( $brut['tome_id'] ?? 0 ),
			'nature'          => $nature,
			'numero'          => self::numero( $brut['numero'] ?? '' ),
			'titre'           => sanitize_text_field( (string) ( $brut['titre'] ?? '' ) ),
			'date_sortie'     => sanitize_text_field( (string) ( $brut['date_sortie'] ?? '' ) ),
			'couverture_id'   => absint( $brut['couverture_id'] ?? 0 ),
			'retirer_absents' => rest_sanitize_boolean( $brut['retirer_absents'] ?? false ),
		);
		$numero_saisi = $brut['numero'] ?? '';
		if ( null === $champs['numero'] && ( ! is_scalar( $numero_saisi ) || '' !== trim( (string) $numero_saisi ) ) ) {
			return new \WP_Error( 'yume_numero_invalide', __( 'Numéro invalide : indiquez un nombre (10, 26,5…).', 'yume-core' ), array( 'status' => 400 ) );
		}
		if ( null === $champs['numero'] && ! in_array( $nature, array( 'ex', 'bonus' ), true ) ) {
			return new \WP_Error( 'yume_numero_manquant', __( 'Indiquez le numéro du tome.', 'yume-core' ), array( 'status' => 400 ) );
		}
		foreach ( array(
			'lien_pdf'  => 'PDF',
			'lien_epub' => 'EPUB',
		) as $cle => $libelle ) {
			if ( array_key_exists( $cle, $brut ) ) {
				$lien = self::lien_externe( $brut[ $cle ], $libelle );
				if ( is_wp_error( $lien ) ) {
					return $lien;
				}
				$champs[ $cle ] = $lien;
			}
		}
		$credits = $brut['credits'] ?? null;
		if ( ! is_array( $credits ) ) {
			$credits = array();
			foreach ( array( 'traduction', 'relecture', 'edition' ) as $role ) {
				if ( isset( $brut[ 'credits_' . $role ] ) ) {
					$credits[ $role ] = $brut[ 'credits_' . $role ];
				}
			}
		}
		if ( $credits ) {
			$champs['credits'] = array(
				'traduction' => sanitize_text_field( (string) ( $credits['traduction'] ?? '' ) ),
				'relecture'  => sanitize_text_field( (string) ( $credits['relecture'] ?? '' ) ),
				'edition'    => sanitize_text_field( (string) ( $credits['edition'] ?? '' ) ),
			);
		}
		return $champs;
	}

	/**
	 * Tome existant (hors corbeille) d'une œuvre pour une nature et un numéro.
	 *
	 * @param int        $oeuvre_id Œuvre.
	 * @param string     $nature    Nature.
	 * @param float|null $numero    Numéro.
	 */
	public static function trouver_tome( int $oeuvre_id, string $nature, ?float $numero ): ?\WP_Post {
		foreach ( yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) ) as $tome ) {
			$sa_nature = (string) get_post_meta( $tome->ID, 'yume_nature', true );
			if ( ( '' === $sa_nature ? 'tome' : $sa_nature ) !== $nature ) {
				continue;
			}
			$son_numero = self::numero( get_post_meta( $tome->ID, 'yume_numero', true ) );
			if ( ( null === $numero && null === $son_numero ) || ( null !== $numero && null !== $son_numero && abs( $numero - $son_numero ) < 0.0005 ) ) {
				return $tome;
			}
		}
		return null;
	}

	/**
	 * Résumé d'un contenu pour les réponses (id, titre, statut, liens).
	 *
	 * @param \WP_Post $post Contenu.
	 * @return array<string,mixed>
	 */
	public static function resume_contenu( \WP_Post $post ): array {
		$statuts = array(
			'publish' => __( 'Publié', 'yume-core' ),
			'future'  => __( 'Programmé', 'yume-core' ),
			'draft'   => __( 'Brouillon', 'yume-core' ),
			'pending' => __( 'En attente', 'yume-core' ),
			'private' => __( 'Privé', 'yume-core' ),
		);
		return array(
			'id'      => (int) $post->ID,
			'titre'   => get_the_title( $post ),
			'statut'  => $post->post_status,
			'etat'    => $statuts[ $post->post_status ] ?? $post->post_status,
			'date'    => 'future' === $post->post_status ? mysql_to_rfc3339( $post->post_date ) : '',
			'lien'    => (string) get_permalink( $post ),
			'apercu'  => 'publish' === $post->post_status ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post ),
			'edition' => (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}

	/**
	 * Clé de rapprochement d'un chapitre : nature + numéro (sans numéro : postface,
	 * épilogue…), suivie du rang d'apparition pour départager les doublons.
	 *
	 * @param string            $nature     Nature.
	 * @param float|null        $numero     Numéro.
	 * @param array<string,int> $compteurs  Rangs par clé (modifié).
	 */
	private static function cle_chapitre( string $nature, ?float $numero, array &$compteurs ): string {
		$base               = ( '' === $nature ? 'chapitre' : $nature ) . ':' . ( null === $numero ? '' : Texte::numero_url( $numero ) );
		$compteurs[ $base ] = ( $compteurs[ $base ] ?? 0 ) + 1;
		return $base . '#' . $compteurs[ $base ];
	}

	/**
	 * Slug d'un tome (« tome-10 », « arc-7 », « tome-ex »).
	 *
	 * @param string     $nature Nature.
	 * @param float|null $numero Numéro.
	 */
	private static function slug_tome( string $nature, ?float $numero ): string {
		$prefixes = array(
			'tome'      => 'tome',
			'arc'       => 'arc',
			'ex'        => 'tome-ex',
			'bonus'     => 'bonus',
			'chapitres' => 'chapitres',
		);
		$slug     = $prefixes[ $nature ] ?? 'tome';
		return null === $numero ? $slug : $slug . '-' . str_replace( '.', '-', Texte::numero_url( $numero ) );
	}

	/**
	 * Slug d'un chapitre (« chapitre-3 », « chapitre-12-5 », « postface »).
	 *
	 * @param array<string,mixed> $chapitre Chapitre du Result.
	 */
	private static function slug_chapitre( array $chapitre ): string {
		if ( 'chapitre' === $chapitre['nature'] && null !== $chapitre['numero'] ) {
			return 'chapitre-' . str_replace( '.', '-', Texte::numero_url( (float) $chapitre['numero'] ) );
		}
		return sanitize_title( (string) $chapitre['titre'] );
	}

	/**
	 * Crée ou met à jour le tome.
	 *
	 * @param \WP_Post|null       $tome   Tome existant.
	 * @param array<string,mixed> $champs Champs.
	 * @param \WP_Post            $oeuvre Œuvre.
	 * @return int|\WP_Error ID du tome.
	 */
	private static function enregistrer_tome( ?\WP_Post $tome, array $champs, \WP_Post $oeuvre ) {
		$libelle = self::libelle_tome( $champs['nature'], $champs['numero'] );
		$titre   = $oeuvre->post_title . ' — ' . $libelle . ( '' !== $champs['titre'] ? ' : ' . $champs['titre'] : '' );
		$meta    = array(
			'yume_oeuvre_id' => (int) $oeuvre->ID,
			'yume_nature'    => $champs['nature'],
		);
		if ( null !== $champs['numero'] ) {
			$meta['yume_numero'] = $champs['numero'];
		}
		foreach ( array( 'lien_pdf', 'lien_epub' ) as $cle ) {
			if ( array_key_exists( $cle, $champs ) ) {
				$meta[ 'yume_' . $cle ] = $champs[ $cle ];
			}
		}
		if ( isset( $champs['credits'] ) ) {
			$meta['yume_credits'] = $champs['credits'];
		}
		if ( null === $tome ) {
			$ordre = null === $champs['numero'] ? 0 : (int) round( $champs['numero'] * 10 );
			if ( 'ex' === $champs['nature'] ) {
				$ordre += 1000;
			}
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'   => 'yume_tome',
						'post_status' => 'draft',
						'post_title'  => $titre,
						'post_name'   => self::slug_tome( $champs['nature'], $champs['numero'] ),
						'menu_order'  => $ordre,
						'post_author' => get_current_user_id(),
						'meta_input'  => $meta,
					)
				),
				true
			);
			return is_wp_error( $id ) ? $id : (int) $id;
		}
		$donnees = array(
			'ID'         => (int) $tome->ID,
			'meta_input' => $meta,
		);
		if ( '' !== $champs['titre'] || '' === trim( $tome->post_title ) ) {
			$donnees['post_title'] = $titre;
		}
		$id = wp_update_post( wp_slash( $donnees ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( null === $champs['numero'] ) {
			delete_post_meta( (int) $tome->ID, 'yume_numero' );
		}
		return (int) $tome->ID;
	}

	/**
	 * Verse une image du Result (avec cache par clé) ; avertissement en cas d'échec.
	 *
	 * @param Result            $resultat Résultat.
	 * @param string            $cle      Clé.
	 * @param int               $rattachement Contenu de rattachement.
	 * @param int               $tome_id  Tome.
	 * @param string            $alt      Texte alternatif.
	 * @param array<string,int> $cache    Clé => ID déjà versé (modifié).
	 * @param string[]          $avert    Avertissements (modifié).
	 */
	private static function image( Result $resultat, string $cle, int $rattachement, int $tome_id, string $alt, array &$cache, array &$avert ): int {
		if ( isset( $cache[ $cle ] ) ) {
			return $cache[ $cle ];
		}
		$image = $resultat->images[ $cle ] ?? array();
		$alt   = '' !== (string) ( $image['alt'] ?? '' ) ? (string) $image['alt'] : $alt;
		$id    = Medias::importer( $resultat, $cle, $rattachement, $tome_id, $alt, $alt );
		if ( is_wp_error( $id ) ) {
			$avert[]       = $id->get_error_message();
			$cache[ $cle ] = 0;
			return 0;
		}
		$cache[ $cle ] = (int) $id;
		return (int) $id;
	}

	/**
	 * Crée ou met à jour les chapitres du tome depuis le Result.
	 *
	 * @param int                 $tome_id  Tome.
	 * @param Result              $resultat Résultat.
	 * @param array<string,mixed> $champs   Champs.
	 * @param array<string,int>   $cache    Images déjà versées (modifié).
	 * @param string[]            $avert    Avertissements (modifié).
	 * @return array{chapitres:array<int,array<string,mixed>>,disparus:array<int,array<string,mixed>>}
	 */
	private static function enregistrer_chapitres( int $tome_id, Result $resultat, array $champs, array &$cache, array &$avert ): array {
		$existants = array();
		$rangs     = array();
		foreach ( yume_get_chapitres( $tome_id, array( 'status' => 'any' ) ) as $post ) {
			$cle               = self::cle_chapitre( (string) get_post_meta( $post->ID, 'yume_nature', true ), self::numero( get_post_meta( $post->ID, 'yume_numero', true ) ), $rangs );
			$existants[ $cle ] = $post;
		}
		$oeuvre_id = yume_get_oeuvre_id( $tome_id );
		$prefixe   = ( $oeuvre_id ? get_the_title( $oeuvre_id ) . ', ' : '' ) . yume_libelle_tome( $tome_id );
		$credits   = $champs['credits'] ?? get_post_meta( $tome_id, 'yume_credits', true );
		$credits   = is_array( $credits ) ? $credits : array();
		$source    = array(
			'format'     => (string) ( $resultat->stats['format'] ?? 'docx' ),
			'hash'       => (string) ( $resultat->stats['hash'] ?? '' ),
			'importe_le' => current_time( 'mysql', true ),
		);
		$rangs     = array();
		$vus       = array();
		$lignes    = array();
		foreach ( $resultat->chapters as $i => $chapitre ) {
			$numero = null === $chapitre['numero'] ? null : (float) $chapitre['numero'];
			$cle    = self::cle_chapitre( (string) $chapitre['nature'], $numero, $rangs );
			$post   = $existants[ $cle ] ?? null;
			$titre  = (string) $chapitre['titre'] . ( '' !== (string) $chapitre['sous_titre'] ? ' — ' . $chapitre['sous_titre'] : '' );
			$meta   = array(
				'yume_tome_id'       => $tome_id,
				'yume_nature'        => (string) $chapitre['nature'],
				'yume_sous_titre'    => (string) $chapitre['sous_titre'],
				'yume_nb_mots'       => (int) $chapitre['nb_mots'],
				'yume_temps_lecture' => (int) $chapitre['nb_mots'] > 0 ? max( 1, (int) ceil( (int) $chapitre['nb_mots'] / 230 ) ) : 0,
				'yume_source'        => $source,
			);
			if ( null !== $numero ) {
				$meta['yume_numero'] = $numero;
			}
			if ( $credits ) {
				$meta['yume_credits'] = $credits;
			}
			if ( null === $post ) {
				$id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => 'yume_chapitre',
							'post_status'  => 'draft',
							'post_title'   => $titre,
							'post_name'    => self::slug_chapitre( $chapitre ),
							'post_content' => '',
							'menu_order'   => $i + 1,
							'post_author'  => get_current_user_id(),
							'meta_input'   => array(
								'yume_tome_id' => $tome_id,
								'yume_nature'  => (string) $chapitre['nature'],
							) + ( null !== $numero ? array( 'yume_numero' => $numero ) : array() ),
						)
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					/* translators: 1: chapitre, 2: erreur */
					$avert[] = sprintf( __( '%1$s non créé : %2$s', 'yume-core' ), $titre, $id->get_error_message() );
					continue;
				}
				$id     = (int) $id;
				$action = 'cree';
			} else {
				$id     = (int) $post->ID;
				$action = 'maj';
			}
			$alt = $prefixe . ', ' . $chapitre['titre'] . ' — ' . __( 'illustration', 'yume-core' );
			$ids = array();
			foreach ( (array) $chapitre['images'] as $cle_image ) {
				$ids[ $cle_image ] = self::image( $resultat, (string) $cle_image, $id, $tome_id, $alt, $cache, $avert );
			}
			$contenu = Blocks::remplacer_images(
				(string) $chapitre['blocks'],
				static function ( string $cle_image, string $texte_alt ) use ( $ids, $alt ): string {
					$att = (int) ( $ids[ $cle_image ] ?? 0 );
					if ( ! $att ) {
						return '';
					}
					$url = wp_get_attachment_image_url( $att, 'large' );
					$url = $url ? $url : (string) wp_get_attachment_url( $att );
					return Blocks::image( $att, $url, '' !== $texte_alt ? $texte_alt : $alt );
				}
			);
			// Contenu tel qu'il sera enregistré (filtres de sauvegarde, kses pour un compte sans unfiltered_html).
			$enregistre = wp_unslash( (string) apply_filters( 'content_save_pre', wp_slash( $contenu ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre du cœur.
			if ( $post && $post->post_content === $enregistre && $post->post_title === $titre && (int) $post->menu_order === $i + 1 ) {
				$action = 'inchange';
			}
			$maj = wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_title'   => $titre,
						'post_content' => $contenu,
						'menu_order'   => $i + 1,
						'meta_input'   => $meta,
					)
				),
				true
			);
			if ( is_wp_error( $maj ) ) {
				/* translators: 1: chapitre, 2: erreur */
				$avert[] = sprintf( __( '%1$s non mis à jour : %2$s', 'yume-core' ), $titre, $maj->get_error_message() );
				continue;
			}
			if ( null === $numero ) {
				delete_post_meta( $id, 'yume_numero' );
			}
			delete_post_meta( $id, self::META_RETIRE );
			$vus[ $id ] = true;
			$ligne      = array_merge(
				self::resume_contenu( get_post( $id ) ),
				array(
					'numero'     => $numero,
					'nature'     => (string) $chapitre['nature'],
					'libelle'    => (string) $chapitre['titre'],
					'sous_titre' => (string) $chapitre['sous_titre'],
					'nb_mots'    => (int) $chapitre['nb_mots'],
					'action'     => $action,
				)
			);
			$lignes[]   = $ligne;
		}

		// Chapitres du tome absents du nouveau fichier.
		$disparus = array();
		foreach ( $existants as $post ) {
			if ( isset( $vus[ (int) $post->ID ] ) ) {
				continue;
			}
			$retire = false;
			if ( ! empty( $champs['retirer_absents'] ) ) {
				if ( in_array( $post->post_status, array( 'publish', 'future', 'pending', 'private' ), true ) ) {
					wp_update_post(
						array(
							'ID'          => (int) $post->ID,
							'post_status' => 'draft',
						)
					);
				}
				update_post_meta( (int) $post->ID, self::META_RETIRE, 1 );
				$retire = true;
			}
			$disparus[] = array_merge( self::resume_contenu( get_post( (int) $post->ID ) ), array( 'retire' => $retire ) );
		}
		if ( $disparus ) {
			$avert[] = sprintf(
				/* translators: %d : nombre de chapitres */
				_n( '%d chapitre du tome est absent du nouveau fichier.', '%d chapitres du tome sont absents du nouveau fichier.', count( $disparus ), 'yume-core' ),
				count( $disparus )
			) . ' ' . ( ! empty( $champs['retirer_absents'] ) ? __( 'Il(s) a (ont) été mis en brouillon.', 'yume-core' ) : __( 'Il(s) reste(nt) en ligne : cochez « Mettre en brouillon les chapitres absents » pour les retirer.', 'yume-core' ) );
		}
		return array(
			'chapitres' => $lignes,
			'disparus'  => $disparus,
		);
	}

	/**
	 * Prépare (crée ou met à jour) un tome, ses chapitres et son annonce, en brouillon.
	 *
	 * @param array<string,mixed> $brut     Champs : oeuvre_id, nature, numero, titre, date_sortie,
	 *                                      lien_pdf, lien_epub, credits{traduction,relecture,edition},
	 *                                      couverture_id, retirer_absents, tome_id.
	 * @param array<string,mixed> $fichiers Fichiers ($_FILES) : source (DOCX/EPUB), couverture.
	 * @return array<string,mixed>|\WP_Error Rapport : tome, chapitres, disparus, article, import, avertissements.
	 */
	public static function preparer( array $brut, array $fichiers = array() ) {
		self::relever_limites();
		$source_brute = Fichiers::fourni( $fichiers['source'] ?? null ) ? (array) $fichiers['source'] : null;
		try {
			$champs = self::champs( $brut );
			if ( is_wp_error( $champs ) ) {
				return $champs;
			}
			$oeuvre = $champs['oeuvre_id'] ? get_post( $champs['oeuvre_id'] ) : null;
			if ( ! $oeuvre || 'yume_oeuvre' !== $oeuvre->post_type || 'trash' === $oeuvre->post_status ) {
				return new \WP_Error( 'yume_oeuvre_invalide', __( 'Choisissez l’œuvre du tome.', 'yume-core' ), array( 'status' => 400 ) );
			}
			$resultat = null;
			$source   = null;
			if ( $source_brute ) {
				$source = Fichiers::source( $source_brute );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				$resultat = self::convertir( $source );
				if ( is_wp_error( $resultat ) ) {
					return $resultat;
				}
			}
			$couverture = null;
			if ( Fichiers::fourni( $fichiers['couverture'] ?? null ) ) {
				$couverture = Fichiers::couverture( (array) $fichiers['couverture'] );
				if ( is_wp_error( $couverture ) ) {
					return $couverture;
				}
			}

			$avert = $resultat ? $resultat->warnings : array();
			$tome  = self::trouver_tome( (int) $oeuvre->ID, $champs['nature'], $champs['numero'] );
			if ( ! $tome && $champs['tome_id'] && 'yume_tome' === get_post_type( $champs['tome_id'] ) && 'trash' !== get_post_status( $champs['tome_id'] ) && current_user_can( 'edit_post', $champs['tome_id'] ) ) {
				$tome = get_post( $champs['tome_id'] );
			}
			$reutilise = null !== $tome;
			$tome_id   = self::enregistrer_tome( $tome, $champs, $oeuvre );
			if ( is_wp_error( $tome_id ) ) {
				return $tome_id;
			}

			// Couverture : fichier téléversé, pièce jointe existante, ou couverture de l'EPUB.
			if ( $couverture ) {
				$cid = Medias::couverture( $couverture, $tome_id, sprintf( /* translators: %s : titre du tome */ __( 'Couverture — %s', 'yume-core' ), get_the_title( $tome_id ) ) );
				if ( is_wp_error( $cid ) ) {
					$avert[] = $cid->get_error_message();
				} else {
					set_post_thumbnail( $tome_id, $cid );
				}
			} elseif ( $champs['couverture_id'] && wp_attachment_is_image( $champs['couverture_id'] ) ) {
				set_post_thumbnail( $tome_id, $champs['couverture_id'] );
			}

			$cache     = array();
			$chapitres = array(
				'chapitres' => array(),
				'disparus'  => array(),
			);
			if ( $resultat ) {
				$galerie = array();
				$alt     = sprintf( /* translators: %s : titre du tome */ __( 'Illustration — %s', 'yume-core' ), get_the_title( $tome_id ) );
				foreach ( $resultat->front_images as $cle ) {
					$id = self::image( $resultat, (string) $cle, $tome_id, $tome_id, $alt, $cache, $avert );
					if ( $id ) {
						$galerie[] = $id;
					}
				}
				if ( $galerie ) {
					update_post_meta( $tome_id, 'yume_illustrations', $galerie );
				}
				$cle_couv = (string) ( $resultat->stats['couverture'] ?? '' );
				if ( '' !== $cle_couv && ! empty( $cache[ $cle_couv ] ) && ! has_post_thumbnail( $tome_id ) ) {
					set_post_thumbnail( $tome_id, $cache[ $cle_couv ] );
				}
				$chapitres = self::enregistrer_chapitres( $tome_id, $resultat, $champs, $cache, $avert );
			} else {
				if ( ! $reutilise ) {
					$avert[] = __( 'Aucun fichier DOCX ou EPUB : le tome est créé sans chapitres de lecture en ligne.', 'yume-core' );
				}
				// Sans nouveau fichier : chapitres actuels du tome (liens d'aperçu).
				foreach ( yume_get_chapitres( $tome_id, array( 'status' => 'any' ) ) as $chap ) {
					if ( get_post_meta( $chap->ID, self::META_RETIRE, true ) ) {
						continue;
					}
					$numero                   = self::numero( get_post_meta( $chap->ID, 'yume_numero', true ) );
					$chapitres['chapitres'][] = array_merge(
						self::resume_contenu( $chap ),
						array(
							'numero'     => $numero,
							'nature'     => (string) get_post_meta( $chap->ID, 'yume_nature', true ),
							'libelle'    => yume_libelle_chapitre( (int) $chap->ID ),
							'sous_titre' => (string) get_post_meta( $chap->ID, 'yume_sous_titre', true ),
							'nb_mots'    => (int) get_post_meta( $chap->ID, 'yume_nb_mots', true ),
							'action'     => 'inchange',
						)
					);
				}
			}
			if ( ! has_post_thumbnail( $tome_id ) ) {
				$avert[] = __( 'Le tome n’a pas de couverture : celle de l’œuvre sera affichée.', 'yume-core' );
			}

			// Champs mémorisés (utilisés par l'annonce : {titre}) avant l'article d'annonce.
			$precedent = get_post_meta( $tome_id, self::META, true );
			$precedent = is_array( $precedent ) ? $precedent : array();
			update_post_meta( $tome_id, self::META, array_merge( $precedent, array( 'titre' => $champs['titre'] ) ) );

			// Article d'annonce.
			$speciaux = array();
			$nb       = 0;
			foreach ( yume_get_chapitres( $tome_id, array( 'status' => 'any' ) ) as $chap ) {
				if ( get_post_meta( $chap->ID, self::META_RETIRE, true ) ) {
					continue;
				}
				$nature = (string) get_post_meta( $chap->ID, 'yume_nature', true );
				if ( '' === $nature || 'chapitre' === $nature ) {
					++$nb;
				} else {
					$speciaux[] = yume_libelle_chapitre( (int) $chap->ID );
				}
			}
			$credits    = get_post_meta( $tome_id, 'yume_credits', true );
			$article_id = Annonce::preparer(
				$tome_id,
				array(
					'nb_chapitres' => $nb,
					'speciaux'     => $speciaux,
					'credits'      => is_array( $credits ) ? $credits : array(),
				)
			);
			if ( is_wp_error( $article_id ) ) {
				$avert[]    = $article_id->get_error_message();
				$article_id = 0;
			}

			$meta = array_merge(
				(array) get_post_meta( $tome_id, self::META, true ),
				array(
					'titre'       => $champs['titre'],
					'date_sortie' => $champs['date_sortie'],
					'article_id'  => (int) $article_id,
					'maj'         => current_time( 'mysql', true ),
					'par'         => get_current_user_id(),
				)
			);
			if ( $resultat && $source ) {
				$meta['fichier'] = array(
					'nom'    => (string) $source['nom'],
					'format' => (string) $source['format'],
					'octets' => (int) $source['octets'],
					'hash'   => (string) ( $resultat->stats['hash'] ?? '' ),
				);
				$meta['resume']  = self::resume( $resultat );
			}
			update_post_meta( $tome_id, self::META, $meta );

			$tome_post = get_post( $tome_id );
			$rapport   = array(
				'tome'           => array_merge(
					self::resume_contenu( $tome_post ),
					array(
						'libelle'    => yume_libelle_tome( $tome_id ),
						'reutilise'  => $reutilise,
						'couverture' => (int) get_post_thumbnail_id( $tome_id ),
					)
				),
				'oeuvre'         => array(
					'id'    => (int) $oeuvre->ID,
					'titre' => get_the_title( $oeuvre ),
				),
				'chapitres'      => $chapitres['chapitres'],
				'disparus'       => $chapitres['disparus'],
				'article'        => $article_id ? self::resume_contenu( get_post( $article_id ) ) : null,
				'import'         => $resultat && $source ? self::rapport_analyse( $resultat, $source ) : null,
				'avertissements' => array_values( array_unique( $avert ) ),
			);
			if ( null !== $rapport['import'] ) {
				foreach ( $rapport['import']['chapitres'] as $i => $c ) {
					unset( $rapport['import']['chapitres'][ $i ]['stats'] );
				}
			}
			/**
			 * Une publication vient d'être préparée (tome et chapitres en brouillon ou mis à jour).
			 *
			 * @param int                 $tome_id Tome.
			 * @param array<string,mixed> $rapport Rapport.
			 */
			do_action( 'yume_publication_preparee', $tome_id, $rapport );
			return $rapport;
		} finally {
			if ( $source_brute ) {
				Fichiers::supprimer( $source_brute );
			}
		}
	}

	/**
	 * Interprète la date de sortie : « maintenant » ou date ISO (heure locale du site si
	 * aucun fuseau n'est indiqué).
	 *
	 * @param string $quand Valeur.
	 * @return \DateTimeImmutable|null|\WP_Error Null pour « maintenant ».
	 */
	public static function date_sortie( string $quand ) {
		$quand = trim( $quand );
		if ( '' === $quand || 'maintenant' === strtolower( $quand ) ) {
			return null;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+\-]\d{2}:?\d{2})?)?$/', $quand ) ) {
			return new \WP_Error( 'yume_date_invalide', __( 'Date de sortie invalide (format attendu : AAAA-MM-JJTHH:MM).', 'yume-core' ), array( 'status' => 400 ) );
		}
		try {
			$date = new \DateTimeImmutable( $quand, wp_timezone() );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'yume_date_invalide', __( 'Date de sortie invalide (format attendu : AAAA-MM-JJTHH:MM).', 'yume-core' ), array( 'status' => 400 ) );
		}
		if ( $date->getTimestamp() <= time() + MINUTE_IN_SECONDS ) {
			return new \WP_Error( 'yume_date_passee', __( 'La date de sortie programmée doit être dans le futur.', 'yume-core' ), array( 'status' => 400 ) );
		}
		return $date;
	}

	/**
	 * Publie tout de suite, ou programme, le tome, ses chapitres et son annonce.
	 *
	 * @param int    $tome_id Tome.
	 * @param string $quand   « maintenant » ou date ISO.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function publier( int $tome_id, string $quand = 'maintenant' ) {
		$tome = get_post( $tome_id );
		if ( ! $tome || 'yume_tome' !== $tome->post_type || 'trash' === $tome->post_status ) {
			return new \WP_Error( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), array( 'status' => 404 ) );
		}
		if ( ! yume_get_oeuvre_id( $tome_id ) ) {
			return new \WP_Error( 'yume_tome_sans_oeuvre', __( 'Ce tome n’est rattaché à aucune œuvre.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$date = self::date_sortie( $quand );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$immediat = null === $date;
		if ( $immediat ) {
			$local = current_time( 'mysql' );
			$gmt   = current_time( 'mysql', true );
		} else {
			$local = $date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
			$gmt   = $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
		$statut     = $immediat ? 'publish' : 'future';
		$deja_sorti = 'publish' === $tome->post_status;
		$a_publier  = array_values(
			array_filter(
				yume_get_chapitres( $tome_id, array( 'status' => array( 'draft', 'pending', 'future' ) ) ),
				static fn( \WP_Post $c ): bool => ! get_post_meta( $c->ID, self::META_RETIRE, true )
			)
		);
		// Tome déjà en ligne qui reçoit plusieurs chapitres d'un coup (tome migré avec ses seuls
		// PDF/EPUB mis en lecture en ligne, nouveaux chapitres en bloc) : une seule sortie, et
		// non un événement yume_chapitre_publie (e-mails, Discord) par chapitre.
		$groupe = $deja_sorti && $immediat && count( $a_publier ) > 1;

		if ( ! $deja_sorti || $groupe ) {
			/**
			 * Une publication de tome commence : core n'émet aucun événement de sortie pendant
			 * celle-ci (le module publication émet yume_tome_publie une fois tout publié).
			 *
			 * @param int $tome_id Tome.
			 */
			do_action( 'yume_publication_en_cours', $tome_id );
		}

		$publies = 0;
		$premier = 0;
		foreach ( $a_publier as $chapitre ) {
			$ok = wp_update_post(
				array(
					'ID'            => (int) $chapitre->ID,
					'post_status'   => $statut,
					'post_date'     => $local,
					'post_date_gmt' => $gmt,
					'edit_date'     => true,
				),
				true
			);
			if ( ! is_wp_error( $ok ) ) {
				++$publies;
				$premier = $premier ? $premier : (int) $chapitre->ID;
			}
		}
		if ( ! $deja_sorti ) {
			$ok = wp_update_post(
				array(
					'ID'            => $tome_id,
					'post_status'   => $statut,
					'post_date'     => $local,
					'post_date_gmt' => $gmt,
					'edit_date'     => true,
				),
				true
			);
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$article_id = Annonce::sortir( $tome_id, $statut, $local, $gmt );

		clean_post_cache( $tome_id );
		if ( $immediat && ! $deja_sorti && ! metadata_exists( 'post', $tome_id, self::META_NOTIFIE ) && 'publish' === get_post_status( $tome_id ) ) {
			/**
			 * Un tome vient de sortir (émis une seule fois, voir contrat §8).
			 *
			 * @param int $tome_id Tome.
			 */
			do_action( 'yume_tome_publie', $tome_id );
		} elseif ( $groupe && $publies > 0 && 'publish' === get_post_status( $tome_id ) ) {
			$notifie = (string) get_post_meta( $tome_id, self::META_NOTIFIE, true );
			if ( '' === $notifie || 'ignore' === $notifie ) {
				// Tome jamais annoncé comme sortie (migration, PDF/EPUB seuls) : c'est sa sortie
				// en lecture en ligne. Voir contrat §8.
				do_action( 'yume_tome_publie', $tome_id );
			} else {
				/** This action is documented in includes/core/events.php */
				do_action( 'yume_chapitre_publie', $premier );
			}
		}

		$meta = get_post_meta( $tome_id, self::META, true );
		$meta = is_array( $meta ) ? $meta : array();
		update_post_meta(
			$tome_id,
			self::META,
			array_merge(
				$meta,
				array(
					'sortie'     => $immediat ? 'maintenant' : $date->format( DATE_ATOM ),
					'sortie_par' => get_current_user_id(),
					'sortie_le'  => current_time( 'mysql', true ),
				)
			)
		);
		$tome = get_post( $tome_id );
		return array(
			'tome'      => array_merge( self::resume_contenu( $tome ), array( 'libelle' => yume_libelle_tome( $tome_id ) ) ),
			'statut'    => $tome->post_status,
			'date'      => mysql_to_rfc3339( $tome->post_date ),
			'chapitres' => $publies,
			'article'   => $article_id ? self::resume_contenu( get_post( $article_id ) ) : null,
		);
	}
}
