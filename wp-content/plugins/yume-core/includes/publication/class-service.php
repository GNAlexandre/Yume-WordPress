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
 * Tome déjà paru qui a une lecture en ligne (Remplacement::mode()) : un nouveau fichier n'est PAS
 * appliqué aux chapitres en ligne à la préparation. Il devient une préparation en attente (versions
 * des chapitres au statut interne yume_remplacement, voir Remplacement) : « Enregistrer en
 * brouillon », « Vérifier » et « Prévisualiser » ne changent rien pour les lecteurs ; publier()
 * l'applique en place avant la sortie.
 *
 * Découpage manuel (plan()) : l'équipe choisit elle-même les débuts de chapitre après l'analyse.
 * Le fichier source n'est jamais conservé entre deux requêtes : le découpage est renvoyé AVEC le
 * même fichier (analyse, préparation, remplacement) et appliqué à la conversion
 * (Chapter_Builder). Il n'est pas enregistré sur le tome.
 *
 * Sortie (publier()) : yume_publication_en_cours, puis chapitres, tome et annonce publiés (ou programmés
 * à la date donnée), puis yume_tome_publie une seule fois pour une publication immédiate. Plusieurs
 * chapitres ajoutés d'un coup à un tome déjà en ligne forment UNE sortie (annoncer_groupe()),
 * immédiate ou programmée (tâche cron unique yume_publication_sortie_groupee).
 *
 * Ajout au catalogue (option sans_annonce) : la lecture en ligne d'un tome déjà paru (tome migré
 * avec ses seuls PDF/EPUB) est mise en ligne sans rien annoncer : ni article d'annonce, ni
 * yume_tome_publie / yume_chapitre_publie (donc ni Discord, ni e-mail, ni récapitulatif
 * hebdomadaire). Les chapitres prennent la date de sortie du tome et sont marqués
 * (_yume_publie_notifie = « catalogue »), comme le tome : une sortie ultérieure (nouveaux
 * chapitres) est annoncée comme telle, jamais le contenu ancien comme une nouveauté.
 *
 * Ajout de chapitres à un tome (mode « chapitres », formulaire « Ajouter des chapitres à un
 * tome ») : le tome est CHOISI (tome_id), sa nature et son numéro ne sont jamais réécrits. Le
 * fichier est comparé au tome (comparer() : nouveau, en ligne identique, en ligne modifié,
 * programmé, brouillon ; empreinte du texte _yume_empreinte_texte) : les chapitres nouveaux
 * sont créés directement en brouillon, ceux en ligne ne sont pas touchés, sauf choix « Mettre à
 * jour (sans annonce) » (version en attente appliquée à la sortie, voir Remplacement) ; rien
 * n'est retiré. Sortie (publier() avec mode « chapitres ») : maintenant (une annonce groupée),
 * un par un au rythme du tome (yume_prochaine_sortie_rythme(), une annonce par chapitre) ou à
 * une date. Parution : première sortie sans « Tome complet » → yume_parution = en_cours (annonce
 * « SukaMoka, Tome 2 : Prologue disponible ! ») ; « Tome complet » → marquer_complet() (liens
 * PDF/EPUB, yume_parution = complet, planning « publié », annonce « … est complet »), tout de
 * suite ou à la sortie du dernier chapitre programmé. Un tome complet publié d'un coup se
 * comporte comme une publication de tome classique. Sans mode (API, outil en ligne de
 * commande) ou en mode « remplacement » : comportement historique ci-dessus.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

use Yume\Core\Import\Blocks;
use Yume\Core\Import\Chapter_Builder;
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
	 * Valeur de _yume_publie_notifie d'un tome ou d'un chapitre mis en ligne par un ajout au
	 * catalogue (sans annonce) : core n'émet jamais d'événement pour lui.
	 */
	public const NOTIFIE_CATALOGUE = 'catalogue';

	/** Méta du module social : sortie notée (verrou d'alerte, date du récapitulatif hebdomadaire). */
	private const META_ALERTE = '_yume_alerte_envoyee';

	/** Méta du tome : sortie groupée programmée ['ts' => horodatage, 'ids' => chapitres, 'ignores' => chapitres marqués]. */
	public const META_GROUPE = '_yume_sortie_groupee';

	/** Méta d'un chapitre : horodatage de la sortie groupée programmée dont il fait partie. */
	public const META_GROUPE_CHAPITRE = '_yume_sortie_groupee_ts';

	/** Tâche cron (unique) de la sortie groupée programmée d'un tome déjà en ligne. */
	public const HOOK_GROUPE = 'yume_publication_sortie_groupee';

	/**
	 * Méta d'un chapitre : empreinte de son texte {contenu: md5 du titre et du contenu
	 * enregistrés, texte: empreinte du texte normalisé} (comparaison avec un nouveau fichier).
	 */
	public const META_EMPREINTE = '_yume_empreinte_texte';

	/** Méta du tome : passage « complet » programmé {ts, annoncer, liens}. */
	public const META_COMPLET = '_yume_complet_programme';

	/** Tâche cron (unique) du passage « complet » d'un tome à la sortie de son dernier chapitre. */
	public const HOOK_COMPLET = 'yume_publication_tome_complet';

	/** Mode « Ajouter des chapitres à un tome » (formulaire) : voir l'en-tête du fichier. */
	public const MODE_CHAPITRES = 'chapitres';

	/** Mode « Remplacer la lecture en ligne » explicite (en deux temps, Remplacement). */
	public const MODE_REMPLACEMENT = 'remplacement';

	/** Sorties des nouveaux chapitres (mode chapitres) : ensemble maintenant, un par un, à une date. */
	public const SORTIES = array( 'maintenant', 'rythme', 'date' );

	/** Clé de rapprochement d'un chapitre (« chapitre:3#1 », « postface:#1 »). */
	public const MOTIF_CLE = '/^[a-z]{1,20}:[0-9.]{0,12}#[0-9]{1,5}\z/';

	/** Intervalle par défaut (jours) d'une sortie un par un sans rythme de tome. */
	public const INTERVALLE_DEFAUT = 7;

	/**
	 * Relève les limites de temps et de mémoire pendant un traitement lourd.
	 */
	public static function relever_limites(): void {
		wp_raise_memory_limit( 'yume_publication' );
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		}
	}

	/** Taille maximale du découpage manuel reçu en JSON (octets). */
	public const PLAN_OCTETS_MAX = 1048576;

	/**
	 * Contrôle strict d'un découpage manuel reçu (champ « plan » : JSON ou tableau) :
	 * {"debuts": [{"ancre": "e12-3fa9c1", "nature": "chapitre", "titre": "…", "numero": 3}, …],
	 * "garder_avant": false}. Au plus Chapter_Builder::PLAN_MAX débuts, ancres au format
	 * e{rang}-{6 hexadécimaux}, natures connues (Texte::LIBELLES), titres en texte brut
	 * (sanitize_text_field, 200 caractères au plus), numéro facultatif ; deux débuts ne peuvent
	 * pas porter la même ancre ni donner la même clé nature + numéro (rapprochement des chapitres).
	 *
	 * @param mixed $valeur Valeur reçue.
	 * @return array<string,mixed>|null|\WP_Error Découpage normalisé, null si aucun (détection
	 *                                             automatique), erreur 400 sinon.
	 */
	public static function plan( $valeur ) {
		if ( null === $valeur || '' === $valeur || false === $valeur ) {
			return null;
		}
		$erreur = static function ( string $message ): \WP_Error {
			return new \WP_Error( 'yume_plan_invalide', $message, array( 'status' => 400 ) );
		};
		if ( is_string( $valeur ) ) {
			if ( strlen( $valeur ) > self::PLAN_OCTETS_MAX ) {
				return $erreur( __( 'Découpage manuel refusé : il est trop volumineux.', 'yume-core' ) );
			}
			$valeur = json_decode( $valeur, true, 8 );
		}
		if ( ! is_array( $valeur ) || ! isset( $valeur['debuts'] ) || ! is_array( $valeur['debuts'] ) || array_values( $valeur['debuts'] ) !== $valeur['debuts'] ) {
			return $erreur( __( 'Découpage manuel illisible : relancez l’analyse du fichier et refaites le découpage.', 'yume-core' ) );
		}
		$nb = count( $valeur['debuts'] );
		if ( 0 === $nb ) {
			return $erreur( __( 'Le découpage manuel ne contient aucun début de chapitre : cochez au moins un début, ou revenez au découpage automatique.', 'yume-core' ) );
		}
		if ( $nb > Chapter_Builder::PLAN_MAX ) {
			/* translators: %s : nombre maximal de chapitres */
			return $erreur( sprintf( __( 'Découpage manuel refusé : au plus %s débuts de chapitre.', 'yume-core' ), number_format_i18n( Chapter_Builder::PLAN_MAX ) ) );
		}
		$debuts = array();
		$ancres = array();
		foreach ( $valeur['debuts'] as $i => $entree ) {
			/* translators: %d : rang du début de chapitre dans le découpage */
			$ou    = sprintf( __( 'début n° %d', 'yume-core' ), $i + 1 );
			$ancre = is_array( $entree ) && is_string( $entree['ancre'] ?? null ) ? $entree['ancre'] : '';
			if ( ! preg_match( Chapter_Builder::MOTIF_ANCRE, $ancre ) ) {
				/* translators: %s : début de chapitre concerné */
				return $erreur( sprintf( __( 'Découpage manuel refusé (%s) : repère de début de chapitre invalide.', 'yume-core' ), $ou ) );
			}
			if ( isset( $ancres[ $ancre ] ) ) {
				/* translators: %s : repère */
				return $erreur( sprintf( __( 'Découpage manuel refusé : le début « %s » figure deux fois.', 'yume-core' ), $ancre ) );
			}
			$ancres[ $ancre ] = true;
			$nature           = $entree['nature'] ?? 'chapitre';
			if ( ! is_string( $nature ) || ! isset( Texte::LIBELLES[ $nature ] ) ) {
				/* translators: %s : début de chapitre concerné */
				return $erreur( sprintf( __( 'Découpage manuel refusé (%s) : nature de chapitre inconnue.', 'yume-core' ), $ou ) );
			}
			$titre = $entree['titre'] ?? '';
			if ( ! is_scalar( $titre ) ) {
				/* translators: %s : début de chapitre concerné */
				return $erreur( sprintf( __( 'Découpage manuel refusé (%s) : titre invalide.', 'yume-core' ), $ou ) );
			}
			$titre = sanitize_text_field( (string) $titre );
			if ( mb_strlen( $titre, 'UTF-8' ) > Chapter_Builder::TITRE_MAX ) {
				/* translators: 1: début de chapitre concerné, 2: longueur maximale */
				return $erreur( sprintf( __( 'Découpage manuel refusé (%1$s) : titre trop long (%2$d caractères au plus).', 'yume-core' ), $ou, Chapter_Builder::TITRE_MAX ) );
			}
			$numero = null;
			if ( isset( $entree['numero'] ) && '' !== $entree['numero'] ) {
				$numero = is_scalar( $entree['numero'] ) ? self::numero( (string) $entree['numero'] ) : null;
				if ( null === $numero || $numero >= 100000 ) {
					/* translators: %s : début de chapitre concerné */
					return $erreur( sprintf( __( 'Découpage manuel refusé (%s) : numéro invalide.', 'yume-core' ), $ou ) );
				}
			}
			$debuts[] = array(
				'ancre'  => $ancre,
				'nature' => $nature,
				'titre'  => $titre,
				'numero' => $numero,
			);
		}
		// Clés de rapprochement des chapitres (nature + numéro) : jamais deux fois la même.
		$cles = array();
		foreach ( Chapter_Builder::numeroter( $debuts ) as $i => $n ) {
			if ( null === $n['numero'] ) {
				continue;
			}
			$cle = $debuts[ $i ]['nature'] . ':' . Texte::numero_url( (float) $n['numero'] );
			if ( isset( $cles[ $cle ] ) ) {
				/* translators: %s : libellé du chapitre (« Chapitre 3 ») */
				return $erreur( sprintf( __( 'Découpage manuel refusé : deux chapitres porteraient le même numéro (%s). Corrigez la nature ou le numéro.', 'yume-core' ), $n['titre'] ) );
			}
			$cles[ $cle ] = true;
		}
		return array(
			'debuts'       => $debuts,
			// Texte d'ouverture conservé : seulement sur une valeur vraie explicite.
			'garder_avant' => in_array( $valeur['garder_avant'] ?? false, array( true, 1, '1', 'true', 'on' ), true ),
		);
	}

	/**
	 * Convertit un fichier source contrôlé (Fichiers::source()).
	 *
	 * @param array<string,mixed>      $source Fichier source.
	 * @param array<string,mixed>|null $plan   Découpage manuel contrôlé (plan()), ou null.
	 * @return Result|\WP_Error
	 */
	public static function convertir( array $source, ?array $plan = null ) {
		/**
		 * Options du convertisseur (typographie…).
		 *
		 * @param array<string,mixed> $options Options.
		 * @param array<string,mixed> $source  Fichier source.
		 */
		$options = (array) apply_filters( 'yume_publication_options_import', array(), $source );
		if ( null !== $plan ) {
			$options['plan'] = $plan;
		}
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
	 * Analyse un fichier téléversé sans rien créer (le fichier est ensuite supprimé). Le rapport
	 * contient les débuts de chapitre possibles (candidats) et les découpages rapides.
	 *
	 * Comparaison avec le tome (mode « Ajouter des chapitres ») : comparaison (comparer() :
	 * état de chaque chapitre du fichier par rapport au tome), tome (infos_tome() : parution,
	 * chapitres en ligne, rythme, prochaines dates de sortie au rythme pour les chapitres à
	 * sortir).
	 *
	 * @param array<string,mixed> $fichier Entrée de $_FILES.
	 * @param array<string,mixed> $champs  tome_id (tome choisi), sinon oeuvre_id, nature, numero
	 *                                     (facultatifs : tome existant), choix (voir choix()),
	 *                                     plan (découpage manuel à essayer, voir plan()).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function analyser( array $fichier, array $champs = array() ) {
		self::relever_limites();
		try {
			$plan = self::plan( $champs['plan'] ?? null );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$choix = self::choix( $champs['choix'] ?? array() );
			if ( is_wp_error( $choix ) ) {
				return $choix;
			}
			$source = Fichiers::source( $fichier );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			$resultat = self::convertir( $source, $plan );
			if ( is_wp_error( $resultat ) ) {
				return $resultat;
			}
			$rapport                  = self::rapport_analyse( $resultat, $source );
			$rapport['tome_existant'] = null;
			$tome                     = self::tome_choisi( absint( $champs['tome_id'] ?? 0 ) );
			$oeuvre_id                = absint( $champs['oeuvre_id'] ?? 0 );
			if ( ! $tome && $oeuvre_id && 'yume_oeuvre' === get_post_type( $oeuvre_id ) ) {
				$nature = sanitize_key( (string) ( $champs['nature'] ?? 'tome' ) );
				$tome   = self::trouver_tome( $oeuvre_id, isset( yume_natures_tome()[ $nature ] ) ? $nature : 'tome', self::numero( $champs['numero'] ?? '' ) );
			}
			if ( $tome ) {
				$rapport['tome_existant'] = self::resume_contenu( $tome );
			}
			$rapport['comparaison'] = self::comparer( $tome ? (int) $tome->ID : 0, $resultat, $choix );
			$a_sortir               = (int) $rapport['comparaison']['nouveaux'] + (int) $rapport['comparaison']['brouillons'];
			$rapport['tome']        = $tome ? self::infos_tome( (int) $tome->ID, $a_sortir ) : null;
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
			'oeuvre_id'        => absint( $brut['oeuvre_id'] ?? 0 ),
			'tome_id'          => absint( $brut['tome_id'] ?? 0 ),
			'nature'           => $nature,
			'numero'           => self::numero( $brut['numero'] ?? '' ),
			'titre'            => sanitize_text_field( (string) ( $brut['titre'] ?? '' ) ),
			'date_sortie'      => sanitize_text_field( (string) ( $brut['date_sortie'] ?? '' ) ),
			'couverture_id'    => absint( $brut['couverture_id'] ?? 0 ),
			'retirer_absents'  => rest_sanitize_boolean( $brut['retirer_absents'] ?? false ),
			// Null : selon le tome (déjà paru : sans annonce), voir sans_annonce_par_defaut().
			'sans_annonce'     => isset( $brut['sans_annonce'] ) && '' !== $brut['sans_annonce'] ? rest_sanitize_boolean( $brut['sans_annonce'] ) : null,
			'mode'             => sanitize_key( is_scalar( $brut['mode'] ?? '' ) ? (string) ( $brut['mode'] ?? '' ) : '' ),
			// Null : selon la préparation (mode chapitres), voir publier().
			'complet'          => isset( $brut['complet'] ) && '' !== $brut['complet'] ? rest_sanitize_boolean( $brut['complet'] ) : null,
			// Liens PDF/EPUB affichés à la sortie du dernier chapitre programmé (mode chapitres).
			'liens_dernier'    => isset( $brut['liens_dernier'] ) && '' !== $brut['liens_dernier'] ? rest_sanitize_boolean( $brut['liens_dernier'] ) : null,
			// Null : chapitres prévus inchangés, relevés seulement si le fichier en apporte plus.
			'chapitres_prevus' => null,
			'choix'            => array(),
		);
		$prevus_saisi = $brut['chapitres_prevus'] ?? null;
		if ( null !== $prevus_saisi && ( ! is_scalar( $prevus_saisi ) || '' !== trim( (string) $prevus_saisi ) ) ) {
			$prevus_saisi = is_scalar( $prevus_saisi ) ? trim( (string) $prevus_saisi ) : '';
			if ( ! preg_match( '/^\d{1,3}$/', $prevus_saisi ) ) {
				return new \WP_Error( 'yume_chapitres_prevus_invalide', __( 'Chapitres prévus : indiquez un nombre entier de 0 à 999.', 'yume-core' ), array( 'status' => 400 ) );
			}
			$champs['chapitres_prevus'] = (int) $prevus_saisi;
		}
		if ( ! in_array( $champs['mode'], array( '', self::MODE_CHAPITRES, self::MODE_REMPLACEMENT ), true ) ) {
			return new \WP_Error( 'yume_mode_invalide', __( 'Mode de publication inconnu (chapitres ou remplacement).', 'yume-core' ), array( 'status' => 400 ) );
		}
		// Case « Annoncer les nouveaux chapitres » : l'inverse d'« Ajout au catalogue » (sans_annonce l'emporte).
		if ( null === $champs['sans_annonce'] && isset( $brut['annoncer'] ) && '' !== $brut['annoncer'] ) {
			$champs['sans_annonce'] = ! rest_sanitize_boolean( $brut['annoncer'] );
		}
		$choix = self::choix( $brut['choix'] ?? array() );
		if ( is_wp_error( $choix ) ) {
			return $choix;
		}
		$champs['choix'] = $choix;
		$numero_saisi    = $brut['numero'] ?? '';
		if ( null === $champs['numero'] && ( ! is_scalar( $numero_saisi ) || '' !== trim( (string) $numero_saisi ) ) ) {
			return new \WP_Error( 'yume_numero_invalide', __( 'Numéro invalide : indiquez un nombre (10, 26,5…).', 'yume-core' ), array( 'status' => 400 ) );
		}
		// Numéro obligatoire pour créer un tome (pas pour un tome existant choisi : voir preparer()).
		if ( null === $champs['numero'] && ! $champs['tome_id'] && ! in_array( $nature, array( 'ex', 'bonus' ), true ) ) {
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
	 * Choix « Garder la version en ligne » / « Mettre à jour (sans annonce) » des chapitres en
	 * ligne modifiés (mode chapitres) : clé de rapprochement (comparer()) => garder | maj.
	 *
	 * @param mixed $valeur Valeur reçue (tableau, ou JSON).
	 * @return array<string,string>|\WP_Error
	 */
	public static function choix( $valeur ) {
		if ( is_string( $valeur ) && '' !== $valeur ) {
			$valeur = strlen( $valeur ) <= self::PLAN_OCTETS_MAX ? json_decode( $valeur, true, 4 ) : null;
		}
		if ( null === $valeur || '' === $valeur || array() === $valeur ) {
			return array();
		}
		if ( ! is_array( $valeur ) || count( $valeur ) > Chapter_Builder::PLAN_MAX ) {
			return new \WP_Error( 'yume_choix_invalide', __( 'Choix des chapitres à mettre à jour illisible : analysez à nouveau le fichier.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$choix = array();
		foreach ( $valeur as $cle => $action ) {
			if ( ! is_string( $cle ) || ! preg_match( self::MOTIF_CLE, $cle ) || ! in_array( $action, array( 'garder', 'maj' ), true ) ) {
				return new \WP_Error( 'yume_choix_invalide', __( 'Choix des chapitres à mettre à jour illisible : analysez à nouveau le fichier.', 'yume-core' ), array( 'status' => 400 ) );
			}
			$choix[ $cle ] = $action;
		}
		return $choix;
	}

	/**
	 * Liens PDF et EPUB fournis (clés présentes seulement), contrôlés par lien_externe().
	 *
	 * @param array<string,mixed> $brut Champs reçus (lien_pdf, lien_epub).
	 * @return array<string,string>|\WP_Error lien_pdf, lien_epub.
	 */
	public static function liens( array $brut ) {
		$liens = array();
		foreach ( array(
			'lien_pdf'  => 'PDF',
			'lien_epub' => 'EPUB',
		) as $cle => $libelle ) {
			if ( array_key_exists( $cle, $brut ) && null !== $brut[ $cle ] ) {
				$lien = self::lien_externe( $brut[ $cle ], $libelle );
				if ( is_wp_error( $lien ) ) {
					return $lien;
				}
				$liens[ $cle ] = $lien;
			}
		}
		return $liens;
	}

	/**
	 * Mode « Ajout au catalogue (sans annonce) » par défaut : oui pour un tome déjà paru et
	 * complet (statut publish : tome migré avec ses seuls PDF/EPUB, yume_parution_tome() =
	 * complet), non pour un nouveau tome, un brouillon, un tome programmé ou un tome en cours
	 * de parution (ses nouveaux chapitres sont annoncés).
	 *
	 * @param int|\WP_Post|null $tome Tome (ou null : nouveau tome).
	 */
	public static function sans_annonce_par_defaut( $tome ): bool {
		$tome = $tome ? get_post( $tome ) : null;
		return $tome instanceof \WP_Post && 'yume_tome' === $tome->post_type && 'publish' === $tome->post_status
			&& 'en_cours' !== yume_parution_tome( (int) $tome->ID );
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
	 * Titre d'un contenu en texte brut (entités de wptexturize décodées, sans balises) : pour
	 * les réponses JSON (affichées avec textContent), les titres enregistrés et les messages
	 * échappés à l'affichage.
	 *
	 * @param int|\WP_Post $post Contenu.
	 */
	public static function titre_texte( $post ): string {
		return trim( html_entity_decode( wp_strip_all_tags( (string) get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
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
		if ( Remplacement::STATUT === $post->post_status ) {
			$statuts[ Remplacement::STATUT ] = __( 'Version en attente', 'yume-core' );
		}
		return array(
			'id'      => (int) $post->ID,
			'titre'   => self::titre_texte( $post ),
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
	 * Texte d'un titre extrait d'un document (DOCX, EPUB) : balises retirées, espaces
	 * normalisées. Le résultat est du texte brut, à échapper à l'affichage.
	 *
	 * @param string $texte Texte extrait.
	 */
	public static function texte_titre( string $texte ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $texte ) ) );
	}

	/**
	 * Le tome, pas encore en ligne, a-t-il un slug vide ou tiré de son titre par WordPress
	 * (« grimgar-of-fantasy-and-ash-tome-10 ») plutôt que le slug du contrat (« tome-10 ») ?
	 * C'est le cas d'un brouillon créé par le planning ou dans l'administration. Un tome publié
	 * (ou privé) garde toujours son adresse.
	 *
	 * @param \WP_Post $tome Tome.
	 */
	public static function slug_a_poser( \WP_Post $tome ): bool {
		if ( in_array( $tome->post_status, array( 'publish', 'private' ), true ) ) {
			return false;
		}
		$slug = (string) $tome->post_name;
		if ( '' === $slug ) {
			return true;
		}
		$auto = sanitize_title( (string) $tome->post_title );
		return '' !== $auto && (bool) preg_match( '/^' . preg_quote( $auto, '/' ) . '(?:-\d+)?$/', urldecode( $slug ) );
	}

	/**
	 * Slug du contrat pour un tome existant, d'après ses métadonnées (nature, numéro).
	 *
	 * @param int $tome_id Tome.
	 */
	public static function slug_tome_existant( int $tome_id ): string {
		$nature = (string) get_post_meta( $tome_id, 'yume_nature', true );
		return self::slug_tome( '' === $nature ? 'tome' : $nature, self::numero( get_post_meta( $tome_id, 'yume_numero', true ) ) );
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
	 * Tome existant choisi par l'équipe (hors corbeille, modifiable par le compte courant), ou null.
	 *
	 * @param int $tome_id Tome.
	 */
	public static function tome_choisi( int $tome_id ): ?\WP_Post {
		$tome = $tome_id ? get_post( $tome_id ) : null;
		if ( ! $tome instanceof \WP_Post || 'yume_tome' !== $tome->post_type || in_array( $tome->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		return current_user_can( 'edit_post', $tome->ID ) ? $tome : null;
	}

	/**
	 * Texte brut normalisé d'un contenu de chapitre (blocs enregistrés ou issus d'un fichier) :
	 * illustrations retirées (jetons du fichier comme images enregistrées), balises et commentaires
	 * de blocs retirés, entités décodées, toutes les espaces (insécables comprises) réduites à une.
	 *
	 * @param string $html Contenu (blocs).
	 */
	public static function texte_normalise( string $html ): string {
		$html  = (string) preg_replace( '#<!--\s*wp:image\b.*?<!--\s*/wp:image\s*-->#s', ' ', $html );
		$texte = Texte::texte( $html );
		return trim( (string) preg_replace( '/[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', ' ', $texte ) );
	}

	/**
	 * Empreinte du texte d'un chapitre (titre et contenu normalisés, texte_normalise()) : deux
	 * versions de même texte ont la même empreinte, quelles que soient leurs illustrations.
	 *
	 * @param string $titre   Titre du chapitre.
	 * @param string $contenu Contenu (blocs).
	 */
	public static function empreinte_texte( string $titre, string $contenu ): string {
		return md5( self::texte_normalise( $titre ) . "\n" . self::texte_normalise( $contenu ) );
	}

	/**
	 * Empreinte du texte d'un chapitre enregistré : méta META_EMPREINTE si elle correspond encore
	 * au titre et au contenu (chapitre non retouché depuis), sinon calculée à la volée (chapitres
	 * plus anciens que la méta, ou corrigés dans l'éditeur).
	 *
	 * @param \WP_Post $post Chapitre.
	 */
	public static function empreinte_chapitre( \WP_Post $post ): string {
		$meta = get_post_meta( $post->ID, self::META_EMPREINTE, true );
		if ( is_array( $meta ) && is_string( $meta['texte'] ?? null ) && md5( $post->post_title . "\n" . $post->post_content ) === ( $meta['contenu'] ?? '' ) ) {
			return $meta['texte'];
		}
		return self::empreinte_texte( (string) $post->post_title, (string) $post->post_content );
	}

	/**
	 * Enregistre l'empreinte du texte d'un chapitre (après sa création ou sa mise à jour).
	 *
	 * @param int $chapitre_id Chapitre.
	 */
	public static function noter_empreinte( int $chapitre_id ): void {
		clean_post_cache( $chapitre_id );
		$post = get_post( $chapitre_id );
		if ( ! $post ) {
			return;
		}
		update_post_meta(
			$chapitre_id,
			self::META_EMPREINTE,
			array(
				'contenu' => md5( $post->post_title . "\n" . $post->post_content ),
				'texte'   => self::empreinte_texte( (string) $post->post_title, (string) $post->post_content ),
			)
		);
	}

	/**
	 * Libellé, sous-titre et titre enregistré d'un chapitre du fichier (« Chapitre 3 — La ville
	 * sans ciel ») : du texte, jamais du balisage.
	 *
	 * @param array<string,mixed> $chapitre Chapitre du Result.
	 * @return array{libelle:string,sous_titre:string,titre:string}
	 */
	private static function titres_chapitre( array $chapitre ): array {
		$numero     = null === $chapitre['numero'] ? null : (float) $chapitre['numero'];
		$libelle    = self::texte_titre( (string) $chapitre['titre'] );
		$sous_titre = self::texte_titre( (string) $chapitre['sous_titre'] );
		if ( '' === $libelle ) {
			$libelle = __( 'Chapitre', 'yume-core' ) . ( null !== $numero ? ' ' . Texte::numero_fr( $numero ) : '' );
		}
		return array(
			'libelle'    => $libelle,
			'sous_titre' => $sous_titre,
			'titre'      => $libelle . ( '' !== $sous_titre ? ' — ' . $sous_titre : '' ),
		);
	}

	/**
	 * Chapitres actuels du tome (tous statuts actifs, hors versions en attente) par clé de
	 * rapprochement (nature + numéro + rang).
	 *
	 * @param int $tome_id Tome (0 : aucun).
	 * @return array<string,\WP_Post>
	 */
	private static function chapitres_par_cle( int $tome_id ): array {
		$existants = array();
		$rangs     = array();
		foreach ( $tome_id ? yume_get_chapitres( $tome_id, array( 'status' => 'any' ) ) : array() as $post ) {
			$cle               = self::cle_chapitre( (string) get_post_meta( $post->ID, 'yume_nature', true ), self::numero( get_post_meta( $post->ID, 'yume_numero', true ) ), $rangs );
			$existants[ $cle ] = $post;
		}
		return $existants;
	}

	/**
	 * Compare les chapitres d'un fichier au tome (mode « Ajouter des chapitres ») : pour chaque
	 * chapitre du fichier, dans l'ordre, son état par rapport au tome et ce qui en sera fait.
	 *
	 * - nouveau (aucun chapitre de même nature et numéro) : créé (action « cree ») ;
	 * - en ligne, identique (même texte, empreinte_texte()) : rien n'est touché (« inchange ») ;
	 * - en ligne, modifié : « garder » (défaut, rien n'est touché) ou « maj » (choix de l'équipe :
	 *   mis à jour en place, sans annonce, à la sortie) ;
	 * - programmé ou brouillon (pas encore visible des lecteurs) : mis à jour en place
	 *   (« programme » : sa date est gardée ; « brouillon » : il sort avec les nouveaux).
	 *
	 * @param int                  $tome_id  Tome (0 : nouveau tome, tout est nouveau).
	 * @param Result               $resultat Résultat de la conversion.
	 * @param array<string,string> $choix    Choix des chapitres modifiés (choix()).
	 * @return array{lignes:array<int,array<string,mixed>>,nouveaux:int,identiques:int,modifies:int,a_mettre_a_jour:int,programmes:int,brouillons:int}
	 */
	public static function comparer( int $tome_id, Result $resultat, array $choix = array() ): array {
		$existants = self::chapitres_par_cle( $tome_id );
		$etats     = array(
			'nouveau'   => __( 'Nouveau', 'yume-core' ),
			'identique' => __( 'En ligne, identique', 'yume-core' ),
			'modifie'   => __( 'En ligne, modifié', 'yume-core' ),
			'programme' => __( 'Programmé', 'yume-core' ),
			'brouillon' => __( 'Brouillon', 'yume-core' ),
		);
		$bilan     = array(
			'lignes'          => array(),
			'nouveaux'        => 0,
			'identiques'      => 0,
			'modifies'        => 0,
			'a_mettre_a_jour' => 0,
			'programmes'      => 0,
			'brouillons'      => 0,
		);
		$rangs     = array();
		foreach ( $resultat->chapters as $i => $chapitre ) {
			$numero = null === $chapitre['numero'] ? null : (float) $chapitre['numero'];
			$cle    = self::cle_chapitre( (string) $chapitre['nature'], $numero, $rangs );
			$noms   = self::titres_chapitre( $chapitre );
			$post   = $existants[ $cle ] ?? null;
			$ligne  = array(
				'index'      => (int) $i,
				'cle'        => $cle,
				'numero'     => $numero,
				'nature'     => (string) $chapitre['nature'],
				'libelle'    => $noms['libelle'],
				'sous_titre' => $noms['sous_titre'],
				'titre'      => $noms['titre'],
				'nb_mots'    => (int) $chapitre['nb_mots'],
				'existant'   => null,
				'etat'       => 'nouveau',
				'action'     => 'cree',
				'choix'      => '',
			);
			if ( $post ) {
				$ligne['existant'] = array(
					'id'           => (int) $post->ID,
					'statut'       => $post->post_status,
					'lien'         => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
					'date'         => 'future' === $post->post_status ? mysql_to_rfc3339( $post->post_date ) : '',
					'date_libelle' => 'future' === $post->post_status ? Formulaire::date_fr( (int) strtotime( $post->post_date_gmt . ' UTC' ), 'court' ) : '',
				);
				if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) ) {
					$fichier = Blocks::remplacer_images(
						(string) $chapitre['blocks'],
						static function (): string {
							return '';
						}
					);
					if ( self::empreinte_chapitre( $post ) === self::empreinte_texte( $noms['titre'], $fichier ) ) {
						$ligne['etat']   = 'identique';
						$ligne['action'] = 'inchange';
					} else {
						$ligne['etat']   = 'modifie';
						$ligne['choix']  = 'maj' === ( $choix[ $cle ] ?? '' ) ? 'maj' : 'garder';
						$ligne['action'] = $ligne['choix'];
					}
				} elseif ( 'future' === $post->post_status ) {
					$ligne['etat']   = 'programme';
					$ligne['action'] = 'programme';
				} else {
					$ligne['etat']   = 'brouillon';
					$ligne['action'] = 'brouillon';
				}
			}
			$ligne['etat_libelle'] = $etats[ $ligne['etat'] ];
			$compteur              = array(
				'nouveau'   => 'nouveaux',
				'identique' => 'identiques',
				'modifie'   => 'modifies',
				'programme' => 'programmes',
				'brouillon' => 'brouillons',
			)[ $ligne['etat'] ];
			++$bilan[ $compteur ];
			if ( 'maj' === $ligne['action'] ) {
				++$bilan['a_mettre_a_jour'];
			}
			$bilan['lignes'][] = $ligne;
		}
		return $bilan;
	}

	/**
	 * Date du dernier chapitre programmé du tome (fuseau du site), ou null.
	 *
	 * @param int $tome_id Tome.
	 */
	private static function dernier_programme( int $tome_id ): ?\DateTimeImmutable {
		$dernier = null;
		foreach ( yume_get_chapitres( $tome_id, array( 'status' => 'future' ) ) as $chapitre ) {
			$date = date_create_immutable( $chapitre->post_date_gmt, new \DateTimeZone( 'UTC' ) );
			if ( $date && ( null === $dernier || $date > $dernier ) ) {
				$dernier = $date;
			}
		}
		return $dernier ? $dernier->setTimezone( wp_timezone() ) : null;
	}

	/**
	 * Rythme de sortie d'un tome en clair (« chaque samedi à 18 h »), vide sans rythme.
	 *
	 * @param int $tome_id Tome.
	 */
	public static function rythme_texte( int $tome_id ): string {
		$rythme = get_post_meta( $tome_id, 'yume_rythme', true );
		$jours  = yume_jours_semaine();
		if ( ! is_array( $rythme ) || ! isset( $jours[ (string) ( $rythme['jour'] ?? '' ) ] ) ) {
			return '';
		}
		$heure = preg_match( '/^(\d{2}):(\d{2})$/', (string) ( $rythme['heure'] ?? '' ), $m ) ? array( (int) $m[1], (int) $m[2] ) : array( 18, 0 );
		return sprintf(
			/* translators: 1: jour (samedi), 2: heure (18 h, 18 h 30) */
			__( 'chaque %1$s à %2$s', 'yume-core' ),
			mb_strtolower( $jours[ (string) $rythme['jour'] ] ),
			$heure[0] . ' h' . ( $heure[1] ? ' ' . sprintf( '%02d', $heure[1] ) : '' )
		);
	}

	/**
	 * Calendrier d'une sortie de chapitres : une date par chapitre (null : maintenant).
	 *
	 * - maintenant : tous maintenant ; à une date : tous à cette date ;
	 * - un par un (rythme) : tome avec un rythme → dates successives du rythme
	 *   (yume_prochaine_sortie_rythme()), la première après le dernier chapitre déjà programmé du
	 *   tome, après maintenant et après la date de départ si elle est donnée ; sans rythme → date
	 *   de départ (ou maintenant), puis tous les $intervalle jours.
	 *
	 * @param int                     $tome_id    Tome.
	 * @param int                     $nb         Nombre de chapitres.
	 * @param string                  $sortie     maintenant | rythme | date.
	 * @param \DateTimeImmutable|null $date       Date choisie (départ du rythme), ou null.
	 * @param int                     $intervalle Jours entre deux chapitres sans rythme (1 à 60).
	 * @return array<int,\DateTimeImmutable|null>
	 */
	public static function calendrier( int $tome_id, int $nb, string $sortie, ?\DateTimeImmutable $date, int $intervalle = self::INTERVALLE_DEFAUT ): array {
		if ( $nb <= 0 ) {
			return array();
		}
		if ( 'rythme' !== $sortie ) {
			return array_fill( 0, $nb, 'maintenant' === $sortie ? null : $date );
		}
		$dates = array();
		if ( null !== yume_prochaine_sortie_rythme( $tome_id ) ) {
			$ref     = new \DateTimeImmutable( 'now', wp_timezone() );
			$dernier = self::dernier_programme( $tome_id );
			if ( $dernier && $dernier > $ref ) {
				$ref = $dernier;
			}
			if ( $date && $date->modify( '-1 second' ) > $ref ) {
				$ref = $date->modify( '-1 second' );
			}
			for ( $i = 0; $i < $nb; $i++ ) {
				$ref     = yume_prochaine_sortie_rythme( $tome_id, $ref );
				$dates[] = $ref;
			}
			return $dates;
		}
		$intervalle = max( 1, min( 60, $intervalle ) );
		$depart     = $date ?? new \DateTimeImmutable( 'now', wp_timezone() );
		for ( $i = 0; $i < $nb; $i++ ) {
			$dates[] = 0 === $i ? $date : $depart->modify( '+' . ( $i * $intervalle ) . ' days' );
		}
		return $dates;
	}

	/**
	 * Chapitres prévus d'un tome après un ajout de chapitres : la valeur saisie (0 : inconnu),
	 * sinon la valeur actuelle ; si le tome compte désormais plus de chapitres que ce nombre
	 * (tome entier déposé, découpage plus fin), il est relevé à ce compte. Journalisé.
	 *
	 * @param int      $tome_id Tome.
	 * @param int|null $saisi   Valeur saisie (null : aucune).
	 * @param bool     $relever Relever automatiquement si dépassé.
	 * @return array{avant:int,apres:int,auto:bool}
	 */
	public static function ajuster_chapitres_prevus( int $tome_id, ?int $saisi, bool $relever = true ): array {
		$avant = (int) get_post_meta( $tome_id, 'yume_chapitres_prevus', true );
		$apres = null === $saisi ? $avant : $saisi;
		$auto  = false;
		if ( $relever && $apres > 0 ) {
			$compte = 0;
			foreach ( yume_get_chapitres( $tome_id, array( 'status' => array( 'publish', 'future', 'draft', 'pending', 'private' ) ) ) as $chapitre ) {
				if ( ! get_post_meta( $chapitre->ID, self::META_RETIRE, true ) ) {
					++$compte;
				}
			}
			if ( $compte > $apres ) {
				$apres = $compte;
				$auto  = true;
			}
		}
		if ( $apres !== $avant ) {
			if ( $apres > 0 ) {
				update_post_meta( $tome_id, 'yume_chapitres_prevus', $apres );
			} else {
				delete_post_meta( $tome_id, 'yume_chapitres_prevus' );
			}
			if ( function_exists( 'yume_journal_planning' ) ) {
				yume_journal_planning( $tome_id, get_current_user_id(), 'chapitres_prevus', (string) $avant, (string) $apres );
			}
		}
		return array(
			'avant' => $avant,
			'apres' => $apres,
			'auto'  => $auto,
		);
	}

	/**
	 * Informations d'un tome pour le formulaire « Ajouter des chapitres » : parution (à paraître,
	 * en cours, complet), chapitres en ligne et prévus, rythme, prochaines dates au rythme.
	 *
	 * @param int $tome_id  Tome.
	 * @param int $a_sortir Chapitres à sortir (dates au rythme calculées pour eux).
	 * @return array<string,mixed>
	 */
	public static function infos_tome( int $tome_id, int $a_sortir = 0 ): array {
		$parutions = yume_etats_tome();
		$parution  = yume_parution_tome( $tome_id );
		$en_ligne  = wp_list_pluck( yume_get_chapitres( $tome_id ), 'ID' );
		$dernier   = self::dernier_programme( $tome_id );
		$rythme    = self::rythme_texte( $tome_id );
		$dates     = array();
		if ( '' !== $rythme && $a_sortir > 0 ) {
			foreach ( self::calendrier( $tome_id, min( $a_sortir, 200 ), 'rythme', null ) as $date ) {
				$dates[] = array(
					'date'    => $date ? $date->format( DATE_ATOM ) : '',
					'libelle' => $date ? Formulaire::date_fr( $date->getTimestamp(), 'court' ) : '',
				);
			}
		}
		return array(
			'id'                => $tome_id,
			'libelle'           => yume_libelle_tome( $tome_id ),
			'statut'            => (string) get_post_status( $tome_id ),
			'parution'          => $parution,
			'parution_libelle'  => (string) ( $parutions[ $parution ] ?? $parution ),
			'en_ligne'          => count( $en_ligne ),
			'en_ligne_libelle'  => $en_ligne ? self::libelle_groupe( array_map( 'intval', $en_ligne ) ) : '',
			'programmes'        => count( yume_get_chapitres( $tome_id, array( 'status' => 'future' ) ) ),
			'dernier_programme' => $dernier ? $dernier->format( DATE_ATOM ) : '',
			'prevus'            => (int) get_post_meta( $tome_id, 'yume_chapitres_prevus', true ),
			'rythme'            => $rythme,
			'dates_rythme'      => $dates,
			'sans_annonce'      => self::sans_annonce_par_defaut( $tome_id ),
			'lien'              => 'publish' === get_post_status( $tome_id ) ? (string) get_permalink( $tome_id ) : '',
		);
	}

	/**
	 * Crée ou met à jour le tome. Un tome existant garde TOUJOURS sa nature et son numéro (ils
	 * lui appartiennent, choisis à sa création) : le libellé de son titre vient de ses propres
	 * métadonnées, jamais des champs nature et numéro reçus.
	 *
	 * @param \WP_Post|null       $tome   Tome existant.
	 * @param array<string,mixed> $champs Champs.
	 * @param \WP_Post            $oeuvre Œuvre.
	 * @return int|\WP_Error ID du tome.
	 */
	private static function enregistrer_tome( ?\WP_Post $tome, array $champs, \WP_Post $oeuvre ) {
		$libelle = null === $tome ? self::libelle_tome( $champs['nature'], $champs['numero'] ) : yume_libelle_tome( (int) $tome->ID );
		$titre   = $oeuvre->post_title . ' — ' . $libelle . ( '' !== $champs['titre'] ? ' : ' . $champs['titre'] : '' );
		$meta    = array( 'yume_oeuvre_id' => (int) $oeuvre->ID );
		// Tome existant : nature et numéro posés seulement s'ils manquent (tome créé sans eux
		// dans l'administration), jamais remplacés.
		if ( null === $tome || '' === (string) get_post_meta( $tome->ID, 'yume_nature', true ) ) {
			$meta['yume_nature'] = $champs['nature'];
		}
		if ( null !== $champs['numero'] && ( null === $tome || ! metadata_exists( 'post', $tome->ID, 'yume_numero' ) ) ) {
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
		if ( self::slug_a_poser( $tome ) ) {
			// Brouillon du planning ou de l'administration : adresse du contrat §3 (« tome-10 »),
			// et non celle que WordPress tirerait du titre à la publication.
			$donnees['post_name'] = self::slug_tome_existant( (int) $tome->ID );
		}
		$id = wp_update_post( wp_slash( $donnees ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return (int) $tome->ID;
	}

	/**
	 * Verse une image du Result (avec cache par clé) ; avertissement en cas d'échec.
	 *
	 * Le titre du média est toujours le libellé généré (« Œuvre, Tome 7, Chapitre 3 —
	 * illustration ») ; son texte alternatif est celui du document s'il en porte un (hors
	 * description générée par Word, Texte::alt_automatique()), sinon ce même libellé.
	 *
	 * @param Result            $resultat Résultat.
	 * @param string            $cle      Clé.
	 * @param int               $rattachement Contenu de rattachement.
	 * @param int               $tome_id  Tome.
	 * @param string            $libelle  Libellé généré : titre du média et texte alternatif par défaut.
	 * @param array<string,int> $cache    Clé => ID déjà versé (modifié).
	 * @param string[]          $avert    Avertissements (modifié).
	 */
	private static function image( Result $resultat, string $cle, int $rattachement, int $tome_id, string $libelle, array &$cache, array &$avert ): int {
		if ( isset( $cache[ $cle ] ) ) {
			return $cache[ $cle ];
		}
		$image = $resultat->images[ $cle ] ?? array();
		$alt   = (string) ( $image['alt'] ?? '' );
		if ( '' === $alt || Texte::alt_automatique( $alt ) ) {
			$alt = $libelle;
		}
		$id = Medias::importer( $resultat, $cle, $rattachement, $tome_id, $alt, $libelle );
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
	 * En préparation séparée ($attente, tome en mode remplacement) : chaque chapitre du fichier
	 * devient une version en attente (Remplacement::STATUT) liée au chapitre existant de même clé
	 * (Remplacement::META_DE) ; aucun chapitre existant n'est modifié, les images nouvelles sont
	 * versées sans rattachement et les absents seulement signalés.
	 *
	 * Ajout de chapitres ($comparaison, mode chapitres, voir comparer()) : les chapitres nouveaux
	 * sont créés en brouillon (placés après les chapitres du tome), les chapitres en ligne
	 * identiques ou gardés ne sont pas touchés, ceux à mettre à jour deviennent une version en
	 * attente (appliquée à la sortie), les chapitres programmés ou en brouillon sont mis à jour
	 * en place (date et ordre gardés) ; aucun chapitre absent du fichier n'est signalé ni retiré.
	 *
	 * @param int                      $tome_id     Tome.
	 * @param Result                   $resultat    Résultat.
	 * @param array<string,mixed>      $champs      Champs.
	 * @param array<string,int>        $cache       Images déjà versées (modifié).
	 * @param string[]                 $avert       Avertissements (modifié).
	 * @param bool                     $attente     Préparation séparée (remplacement d'une lecture en ligne).
	 * @param array<string,mixed>|null $comparaison Comparaison avec le tome (mode chapitres), ou null.
	 * @return array{chapitres:array<int,array<string,mixed>>,disparus:array<int,array<string,mixed>>}
	 */
	private static function enregistrer_chapitres( int $tome_id, Result $resultat, array $champs, array &$cache, array &$avert, bool $attente = false, ?array $comparaison = null ): array {
		$existants = self::chapitres_par_cle( $tome_id );
		$oeuvre_id = yume_get_oeuvre_id( $tome_id );
		$prefixe   = ( $oeuvre_id ? get_the_title( $oeuvre_id ) . ', ' : '' ) . yume_libelle_tome( $tome_id );
		$credits   = $champs['credits'] ?? get_post_meta( $tome_id, 'yume_credits', true );
		$credits   = is_array( $credits ) ? $credits : array();
		$source    = array(
			'format'     => (string) ( $resultat->stats['format'] ?? 'docx' ),
			'hash'       => (string) ( $resultat->stats['hash'] ?? '' ),
			'importe_le' => current_time( 'mysql', true ),
		);
		// Ajout de chapitres : les nouveaux se placent après les chapitres actuels du tome.
		$ordre_max = 0;
		foreach ( $existants as $post ) {
			$ordre_max = max( $ordre_max, (int) $post->menu_order );
		}
		$rangs  = array();
		$vus    = array();
		$lignes = array();
		foreach ( $resultat->chapters as $i => $chapitre ) {
			$numero = null === $chapitre['numero'] ? null : (float) $chapitre['numero'];
			$cle    = self::cle_chapitre( (string) $chapitre['nature'], $numero, $rangs );
			$post   = $existants[ $cle ] ?? null;
			// Titre et sous-titre extraits du document : du texte, jamais du balisage (le titre
			// est affiché sans échappement par core/post-title, et kses ne s'applique pas à un
			// compte unfiltered_html).
			$noms       = self::titres_chapitre( $chapitre );
			$libelle    = $noms['libelle'];
			$sous_titre = $noms['sous_titre'];
			$titre      = $noms['titre'];
			$comparee   = null !== $comparaison ? ( $comparaison['lignes'][ $i ] ?? null ) : null;
			if ( $comparee && $post && in_array( $comparee['action'], array( 'inchange', 'garder' ), true ) ) {
				// Chapitre en ligne identique, ou modifié mais gardé : rien n'est touché.
				$vus[ (int) $post->ID ] = true;
				$lignes[]               = array_merge(
					self::resume_contenu( $post ),
					array(
						'numero'     => $numero,
						'nature'     => (string) $chapitre['nature'],
						'libelle'    => $libelle,
						'sous_titre' => $sous_titre,
						'nb_mots'    => (int) $chapitre['nb_mots'],
						'action'     => 'inchange' === $comparee['action'] ? 'inchange' : 'garde',
						'cle'        => $cle,
					)
				);
				continue;
			}
			// Version en attente : remplacement complet, ou chapitre en ligne à mettre à jour.
			$version = $attente || ( $comparee && $post && 'maj' === $comparee['action'] );
			if ( null !== $comparaison ) {
				$ordre = $post ? (int) $post->menu_order : ++$ordre_max;
			} else {
				$ordre = $i + 1;
			}
			$meta = array(
				'yume_tome_id'       => $tome_id,
				'yume_nature'        => (string) $chapitre['nature'],
				'yume_sous_titre'    => sanitize_text_field( $sous_titre ),
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
			if ( $version ) {
				$id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => 'yume_chapitre',
							'post_status'  => Remplacement::STATUT,
							'post_title'   => $titre,
							'post_name'    => self::slug_chapitre( $chapitre ),
							'post_content' => '',
							'menu_order'   => $ordre,
							'post_author'  => get_current_user_id(),
							'meta_input'   => array(
								'yume_tome_id'        => $tome_id,
								'yume_nature'         => (string) $chapitre['nature'],
								Remplacement::META_DE => $post ? (int) $post->ID : 0,
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
				$action = $post ? 'maj' : 'cree';
			} elseif ( null === $post ) {
				$id = wp_insert_post(
					wp_slash(
						array(
							'post_type'    => 'yume_chapitre',
							'post_status'  => 'draft',
							'post_title'   => $titre,
							'post_name'    => self::slug_chapitre( $chapitre ),
							'post_content' => '',
							'menu_order'   => $ordre,
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
			$alt = $prefixe . ', ' . $libelle . ' — ' . __( 'illustration', 'yume-core' );
			$ids = array();
			foreach ( (array) $chapitre['images'] as $cle_image ) {
				// Version en attente : aucune image (même réutilisée) n'est rattachée avant l'application.
				$ids[ $cle_image ] = self::image( $resultat, (string) $cle_image, $version ? 0 : $id, $tome_id, $alt, $cache, $avert );
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
			if ( $post && $post->post_content === $enregistre && $post->post_title === $titre && (int) $post->menu_order === $ordre ) {
				$action = 'inchange';
			}
			if ( $version ) {
				$meta[ Remplacement::META_ACTION ] = $action;
				$meta[ Remplacement::META_MEDIAS ] = array_values( array_filter( array_map( 'intval', $ids ) ) );
			}
			$maj = wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_title'   => $titre,
						'post_content' => $contenu,
						'menu_order'   => $ordre,
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
			if ( ! $version ) {
				self::noter_empreinte( $id );
			}
			$vus[ $post && $version ? (int) $post->ID : $id ] = true;
			$resume = self::resume_contenu( get_post( $id ) );
			if ( $version ) {
				// Version en attente : aperçu réservé à l'équipe, jamais ouverte dans l'éditeur.
				$resume['lien']     = $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
				$resume['edition']  = '';
				$resume['remplace'] = $post ? (int) $post->ID : 0;
			}
			$ligne = array_merge(
				$resume,
				array(
					'numero'     => $numero,
					'nature'     => (string) $chapitre['nature'],
					'libelle'    => $libelle,
					'sous_titre' => $sous_titre,
					'nb_mots'    => (int) $chapitre['nb_mots'],
					'action'     => $action,
				)
			);
			if ( null !== $comparaison ) {
				$ligne['cle'] = $cle;
			}
			$lignes[] = $ligne;
		}

		// Ajout de chapitres : rien n'est jamais retiré, les chapitres absents du fichier ne
		// sont pas signalés (le fichier peut ne contenir qu'un chapitre).
		if ( null !== $comparaison ) {
			return array(
				'chapitres' => $lignes,
				'disparus'  => array(),
			);
		}

		// Chapitres du tome absents du nouveau fichier.
		$disparus = array();
		foreach ( $existants as $post ) {
			if ( isset( $vus[ (int) $post->ID ] ) ) {
				continue;
			}
			$retire = false;
			if ( ! empty( $champs['retirer_absents'] ) && ! $attente ) {
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
			) . ' ' . ( ! empty( $champs['retirer_absents'] )
				? ( $attente ? __( 'Il(s) sera (seront) mis en brouillon au remplacement de la lecture en ligne.', 'yume-core' ) : __( 'Il(s) a (ont) été mis en brouillon.', 'yume-core' ) )
				: __( 'Il(s) reste(nt) en ligne : cochez « Mettre en brouillon les chapitres absents » pour les retirer.', 'yume-core' ) );
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
	 *                                      couverture_id, retirer_absents, tome_id, sans_annonce
	 *                                      (null ou absent : sans_annonce_par_defaut() ; vrai :
	 *                                      aucun article d'annonce créé ni mis à jour), plan
	 *                                      (découpage manuel du fichier source, voir plan()),
	 *                                      annoncer (inverse de sans_annonce), mode (chapitres :
	 *                                      ajout de chapitres au tome choisi, voir l'en-tête ;
	 *                                      remplacement ; vide : comportement historique), choix
	 *                                      (voir choix()), complet (mode chapitres : liens PDF/EPUB
	 *                                      gardés pour la sortie).
	 * @param array<string,mixed> $fichiers Fichiers ($_FILES) : source (DOCX/EPUB), couverture.
	 * @return array<string,mixed>|\WP_Error Rapport : tome, chapitres, disparus, article, import,
	 *                                      avertissements, sans_annonce, mode, comparaison (mode
	 *                                      chapitres : comparer()), parution, remplacement (préparation
	 *                                      en attente : Remplacement::etat(), sinon null). Tome paru
	 *                                      avec sa lecture en ligne : chapitres = versions en attente
	 *                                      (aperçus), rien n'est modifié en ligne ; 409
	 *                                      yume_remplacement_en_attente si un autre membre a déjà
	 *                                      un remplacement en attente pour ce tome.
	 */
	public static function preparer( array $brut, array $fichiers = array() ) {
		self::relever_limites();
		$source_brute = Fichiers::fourni( $fichiers['source'] ?? null ) ? (array) $fichiers['source'] : null;
		try {
			$champs = self::champs( $brut );
			if ( is_wp_error( $champs ) ) {
				return $champs;
			}
			$plan = self::plan( $brut['plan'] ?? null );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
			$chapitres_mode = self::MODE_CHAPITRES === $champs['mode'];
			// Tome choisi (tome_id) : la cible, quels que soient la nature et le numéro reçus.
			$choisi = self::tome_choisi( $champs['tome_id'] );
			if ( ! $choisi && $champs['tome_id'] && $chapitres_mode ) {
				return new \WP_Error( 'yume_tome_invalide', __( 'Tome introuvable : choisissez un tome de la liste.', 'yume-core' ), array( 'status' => 404 ) );
			}
			if ( $choisi && ! $champs['oeuvre_id'] ) {
				$champs['oeuvre_id'] = yume_get_oeuvre_id( (int) $choisi->ID );
			}
			$oeuvre = $champs['oeuvre_id'] ? get_post( $champs['oeuvre_id'] ) : null;
			if ( ! $oeuvre || 'yume_oeuvre' !== $oeuvre->post_type || 'trash' === $oeuvre->post_status ) {
				return new \WP_Error( 'yume_oeuvre_invalide', __( 'Choisissez l’œuvre du tome.', 'yume-core' ), array( 'status' => 400 ) );
			}
			if ( $choisi && yume_get_oeuvre_id( (int) $choisi->ID ) && yume_get_oeuvre_id( (int) $choisi->ID ) !== (int) $oeuvre->ID ) {
				return new \WP_Error( 'yume_tome_autre_oeuvre', __( 'Ce tome appartient à une autre œuvre : choisissez l’œuvre puis l’un de ses tomes.', 'yume-core' ), array( 'status' => 400 ) );
			}
			if ( ! $choisi && null === $champs['numero'] && ! in_array( $champs['nature'], array( 'ex', 'bonus' ), true ) ) {
				return new \WP_Error( 'yume_numero_manquant', __( 'Indiquez le numéro du tome.', 'yume-core' ), array( 'status' => 400 ) );
			}
			$resultat = null;
			$source   = null;
			if ( $source_brute ) {
				$source = Fichiers::source( $source_brute );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				$resultat = self::convertir( $source, $plan );
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
			if ( null !== $plan && ! $resultat ) {
				$avert[] = __( 'Découpage manuel ignoré : il s’applique au fichier DOCX ou EPUB, à déposer à nouveau avec lui.', 'yume-core' );
			}
			$tome         = $choisi ? $choisi : self::trouver_tome( (int) $oeuvre->ID, $champs['nature'], $champs['numero'] );
			$reutilise    = null !== $tome;
			$sans_annonce = null === $champs['sans_annonce'] ? self::sans_annonce_par_defaut( $tome ) : (bool) $champs['sans_annonce'];
			// Ajout de chapitres : comparaison du fichier avec le tome (rien n'est retiré).
			$comparaison  = $chapitres_mode && $resultat ? self::comparer( $tome ? (int) $tome->ID : 0, $resultat, $champs['choix'] ) : null;
			$mises_a_jour = $comparaison && $comparaison['a_mettre_a_jour'] > 0;
			// Tome paru avec sa lecture en ligne (hors ajout de chapitres) : le nouveau fichier
			// attend l'application (remplacement en deux temps).
			$attente = $resultat && $tome && ! $chapitres_mode && Remplacement::mode( $tome );
			$etat    = null;
			if ( $tome ) {
				$etat = Remplacement::nettoyer_si_expiree( (int) $tome->ID );
			}
			if ( $attente || $mises_a_jour ) {
				$verrou = Remplacement::verrou( (int) $tome->ID );
				if ( $verrou ) {
					return $verrou;
				}
				if ( $mises_a_jour && $etat && self::MODE_CHAPITRES !== ( $etat['mode'] ?? '' ) ) {
					return new \WP_Error(
						'yume_remplacement_en_attente',
						__( 'Un remplacement complet de la lecture en ligne de ce tome attend déjà : appliquez-le ou annulez-le avant de mettre à jour des chapitres en ligne.', 'yume-core' ),
						array( 'status' => 409 )
					);
				}
			} elseif ( $comparaison && $etat && self::MODE_CHAPITRES === ( $etat['mode'] ?? '' ) && (int) ( $etat['par'] ?? 0 ) === get_current_user_id() ) {
				// La nouvelle préparation remplace la précédente : plus aucun chapitre à mettre à jour.
				Remplacement::annuler( (int) $tome->ID );
			}
			// Ajout de chapitres : les liens PDF/EPUB accompagnent « Le tome est complet » et ne
			// sont posés qu'à ce moment (marquer_complet()).
			$liens_complet = array();
			if ( $chapitres_mode ) {
				$liens_complet = array_intersect_key( $champs, array_flip( array( 'lien_pdf', 'lien_epub' ) ) );
				unset( $champs['lien_pdf'], $champs['lien_epub'] );
			}
			$tome_id = self::enregistrer_tome( $tome, $champs, $oeuvre );
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
				// Préparation séparée (remplacement, ou chapitres en ligne à mettre à jour) : images
				// versées suivies (supprimées à l'annulation).
				$suivi  = $attente || $mises_a_jour;
				$medias = $suivi ? Remplacement::debuter( $tome_id ) : array();
				$suivre = static function ( $id ) use ( &$medias ) {
					$medias[] = (int) $id;
				};
				if ( $suivi ) {
					add_action( 'add_attachment', $suivre );
				}
				try {
					$galerie = array();
					$alt     = sprintf( /* translators: %s : titre du tome */ __( 'Illustration — %s', 'yume-core' ), get_the_title( $tome_id ) );
					// Ajout de chapitres : la galerie du tome n'est posée que s'il n'en a pas encore.
					$images_avant = $chapitres_mode && get_post_meta( $tome_id, 'yume_illustrations', true ) ? array() : $resultat->front_images;
					foreach ( $images_avant as $cle ) {
						$id = self::image( $resultat, (string) $cle, $attente ? 0 : $tome_id, $tome_id, $alt, $cache, $avert );
						if ( $id ) {
							$galerie[] = $id;
						}
					}
					if ( $galerie && ! $attente ) {
						update_post_meta( $tome_id, 'yume_illustrations', $galerie );
					}
					$cle_couv  = (string) ( $resultat->stats['couverture'] ?? '' );
					$couv_epub = '' !== $cle_couv && ! empty( $cache[ $cle_couv ] ) && ! has_post_thumbnail( $tome_id ) ? (int) $cache[ $cle_couv ] : 0;
					if ( $couv_epub && ! $attente ) {
						set_post_thumbnail( $tome_id, $couv_epub );
					}
					$chapitres = self::enregistrer_chapitres( $tome_id, $resultat, $champs, $cache, $avert, $attente, $comparaison );
				} finally {
					remove_action( 'add_attachment', $suivre );
				}
				if ( $suivi ) {
					Remplacement::terminer(
						$tome_id,
						array(
							'fichier'         => $source ? array(
								'nom'    => (string) $source['nom'],
								'format' => (string) $source['format'],
								'octets' => (int) $source['octets'],
								'hash'   => (string) ( $resultat->stats['hash'] ?? '' ),
							) : array(),
							'resume'          => self::resume( $resultat ),
							'galerie'         => $attente && $galerie ? $galerie : null,
							'couverture'      => $attente ? $couv_epub : 0,
							'retirer_absents' => $attente && ! empty( $champs['retirer_absents'] ),
							'absents'         => array_map( 'intval', array_column( $chapitres['disparus'], 'id' ) ),
							// Ajout de chapitres : seules les mises à jour choisies attendent la sortie.
							'mode'            => $attente ? self::MODE_REMPLACEMENT : self::MODE_CHAPITRES,
						),
						$medias,
						array_values( $cache )
					);
				}
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

			// Article d'annonce (aucun pour un ajout au catalogue sans annonce). Ajout de chapitres :
			// seulement pour la première sortie du tome (variante « chapitres disponibles » sans
			// « Tome complet »), régénéré à la sortie avec les chapitres qui sortent alors.
			$article_id = 0;
			if ( $chapitres_mode ) {
				if ( ! $sans_annonce && 'publish' !== get_post_status( $tome_id ) ) {
					$article_id = self::preparer_annonce( $tome_id, empty( $champs['complet'] ) ? 'en_cours' : '', null, $avert );
				}
			} elseif ( ! $sans_annonce ) {
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
			}

			// Chapitres prévus : valeur saisie, sinon relevée si le tome en compte désormais plus.
			$prevus = self::ajuster_chapitres_prevus( $tome_id, $attente ? null : $champs['chapitres_prevus'], ! $attente );

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
			if ( $resultat && $source && ! $attente ) {
				$meta['fichier'] = array(
					'nom'    => (string) $source['nom'],
					'format' => (string) $source['format'],
					'octets' => (int) $source['octets'],
					'hash'   => (string) ( $resultat->stats['hash'] ?? '' ),
				);
				$meta['resume']  = self::resume( $resultat );
			}
			if ( $chapitres_mode ) {
				// « Le tome est complet », « Liens avec le dernier chapitre » et les liens : repris
				// par publier() s'il ne les reçoit pas.
				$meta['complet']       = ! empty( $champs['complet'] );
				$meta['liens_dernier'] = ! $meta['complet'] && ! empty( $champs['liens_dernier'] );
				$meta['liens']         = $meta['complet'] || $meta['liens_dernier'] ? $liens_complet : array();
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
					'titre' => self::titre_texte( $oeuvre ),
				),
				'chapitres'      => $chapitres['chapitres'],
				'disparus'       => $chapitres['disparus'],
				'article'        => $article_id ? self::resume_contenu( get_post( $article_id ) ) : null,
				'import'         => $resultat && $source ? self::rapport_analyse( $resultat, $source ) : null,
				'avertissements' => array_values( array_unique( $avert ) ),
				'sans_annonce'   => $sans_annonce,
				// Remplacement en attente (tome paru avec sa lecture en ligne), sinon null.
				'remplacement'   => Remplacement::etat( $tome_id ),
				'mode'           => $champs['mode'],
				// Ajout de chapitres : état de chaque chapitre du fichier par rapport au tome.
				'comparaison'    => $comparaison,
				'parution'       => yume_parution_tome( $tome_id ),
			);
			if ( null !== $rapport['import'] ) {
				foreach ( $rapport['import']['chapitres'] as $i => $c ) {
					unset( $rapport['import']['chapitres'][ $i ]['stats'] );
				}
				// Débuts possibles : utiles à l'analyse seulement (le fichier n'est pas conservé).
				unset( $rapport['import']['candidats'], $rapport['import']['decoupages'] );
			}
			/**
			 * Une publication vient d'être préparée (tome et chapitres en brouillon ou mis à jour).
			 *
			 * @param int                 $tome_id Tome.
			 * @param array<string,mixed> $rapport Rapport.
			 */
			do_action( 'yume_publication_preparee', $tome_id, $rapport );
			$rapport['chapitres_prevus'] = $prevus;
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
	 * Libellé d'un groupe de chapitres : « Chapitres 21 à 23 », « Chapitres 4 et 5 »,
	 * sinon « Prologue à Chapitre 3 ».
	 *
	 * @param int[] $ids Chapitres, dans l'ordre de lecture.
	 */
	public static function libelle_groupe( array $ids ): string {
		$ids = array_values( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return '';
		}
		if ( 1 === count( $ids ) ) {
			return yume_libelle_chapitre( $ids[0] );
		}
		$numeros = array();
		foreach ( $ids as $id ) {
			$nature = (string) get_post_meta( $id, 'yume_nature', true );
			$numero = self::numero( get_post_meta( $id, 'yume_numero', true ) );
			if ( ( '' !== $nature && 'chapitre' !== $nature ) || null === $numero ) {
				$numeros = array();
				break;
			}
			$numeros[] = $numero;
		}
		if ( $numeros ) {
			$min = min( $numeros );
			$max = max( $numeros );
			if ( 2 === count( $numeros ) ) {
				/* translators: 1: premier numéro, 2: second numéro */
				return sprintf( __( 'Chapitres %1$s et %2$s', 'yume-core' ), Texte::numero_fr( $min ), Texte::numero_fr( $max ) );
			}
			/* translators: 1: premier numéro, 2: dernier numéro */
			return sprintf( __( 'Chapitres %1$s à %2$s', 'yume-core' ), Texte::numero_fr( $min ), Texte::numero_fr( $max ) );
		}
		/* translators: 1: premier chapitre, 2: dernier chapitre */
		return sprintf( __( '%1$s à %2$s', 'yume-core' ), yume_libelle_chapitre( $ids[0] ), yume_libelle_chapitre( $ids[ count( $ids ) - 1 ] ) );
	}

	/**
	 * Annonce UNE sortie pour plusieurs chapitres publiés ensemble dans un tome déjà en ligne :
	 * yume_tome_publie si le tome n'a jamais été annoncé (migration, PDF/EPUB seuls, contrat §8),
	 * sinon un seul yume_chapitre_publie (premier chapitre) dont le libellé cite tout le groupe
	 * (« Chapitres 21 à 23 ») dans l'e-mail, le journal et Discord. Les autres chapitres
	 * reçoivent la même date de sortie pour le récapitulatif hebdomadaire.
	 *
	 * @param int   $tome_id Tome.
	 * @param int[] $ids     Chapitres publiés, dans l'ordre de lecture.
	 */
	public static function annoncer_groupe( int $tome_id, array $ids ): void {
		$ids = array_values( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$notifie = (string) get_post_meta( $tome_id, self::META_NOTIFIE, true );
		if ( '' === $notifie || 'ignore' === $notifie ) {
			// Tome jamais annoncé comme sortie : c'est sa sortie en lecture en ligne.
			do_action( 'yume_tome_publie', $tome_id );
			return;
		}
		$premier = $ids[0];
		if ( 1 === count( $ids ) ) {
			/** This action is documented in includes/core/events.php */
			do_action( 'yume_chapitre_publie', $premier, $ids );
			return;
		}
		$libelle        = self::libelle_groupe( $ids );
		$filtre_libelle = static function ( $valeur, $chapitre_id ) use ( $premier, $libelle ) {
			return (int) $chapitre_id === $premier ? $libelle : $valeur;
		};
		$filtre_sous    = static function ( $valeur, $object_id, $cle ) use ( $premier ) {
			// Le sous-titre du premier chapitre ne décrit pas tout le groupe.
			return (int) $object_id === $premier && 'yume_sous_titre' === $cle ? array( '' ) : $valeur;
		};
		$filtre_discord = static function ( $texte, $chapitre_id ) use ( $premier ) {
			$singulier = __( 'Nouveau chapitre :', 'yume-core' );
			if ( (int) $chapitre_id === $premier && str_starts_with( (string) $texte, $singulier ) ) {
				$texte = __( 'Nouveaux chapitres :', 'yume-core' ) . substr( (string) $texte, strlen( $singulier ) );
			}
			return $texte;
		};
		add_filter( 'yume_libelle_chapitre', $filtre_libelle, 1000, 2 );
		add_filter( 'get_post_metadata', $filtre_sous, 1000, 3 );
		add_filter( 'yume_planning_annonce_chapitre', $filtre_discord, 1, 2 );
		try {
			/**
			 * Sortie groupée : un seul événement pour le premier chapitre ; le second argument
			 * donne tous les chapitres du groupe.
			 *
			 * This action is documented in includes/core/events.php
			 */
			do_action( 'yume_chapitre_publie', $premier, $ids );
		} finally {
			remove_filter( 'yume_libelle_chapitre', $filtre_libelle, 1000 );
			remove_filter( 'get_post_metadata', $filtre_sous, 1000 );
			remove_filter( 'yume_planning_annonce_chapitre', $filtre_discord, 1 );
		}
		// Récapitulatif hebdomadaire : chaque chapitre du groupe est une sortie de cette date
		// (verrou unique : aucune alerte supplémentaire ne partira pour eux).
		$verrou = (string) get_post_meta( $premier, self::META_ALERTE, true );
		if ( '' !== $verrou ) {
			foreach ( array_slice( $ids, 1 ) as $id ) {
				add_post_meta( $id, self::META_ALERTE, $verrou, true );
			}
		}
	}

	/**
	 * Sortie programmée de plusieurs chapitres d'un tome déjà en ligne : chaque chapitre est
	 * marqué pour que core n'émette rien à sa publication par WordPress, et une seule tâche
	 * cron annonce le groupe à la date de sortie (Service::sortie_groupee_programmee()).
	 *
	 * @param int   $tome_id Tome.
	 * @param int[] $ids     Chapitres programmés.
	 * @param int   $ts      Horodatage de la sortie.
	 */
	private static function programmer_sortie_groupee( int $tome_id, array $ids, int $ts ): void {
		$ignores = array();
		foreach ( $ids as $id ) {
			if ( ! metadata_exists( 'post', $id, self::META_NOTIFIE ) ) {
				update_post_meta( $id, self::META_NOTIFIE, 'ignore' );
				$ignores[] = (int) $id;
			}
			update_post_meta( $id, self::META_GROUPE_CHAPITRE, $ts );
		}
		update_post_meta(
			$tome_id,
			self::META_GROUPE,
			array(
				'ts'      => $ts,
				'ids'     => array_values( array_map( 'intval', $ids ) ),
				'ignores' => $ignores,
			)
		);
		wp_schedule_single_event( $ts, self::HOOK_GROUPE, array( $tome_id, $ts ) );
	}

	/**
	 * Annule la sortie groupée programmée d'un tome (nouvelle sortie) : tâche cron supprimée,
	 * marques retirées des chapitres qui ne sont pas encore en ligne.
	 *
	 * @param int $tome_id Tome.
	 */
	private static function annuler_sortie_groupee( int $tome_id ): void {
		$groupe = get_post_meta( $tome_id, self::META_GROUPE, true );
		if ( ! is_array( $groupe ) ) {
			return;
		}
		$ts = (int) ( $groupe['ts'] ?? 0 );
		wp_clear_scheduled_hook( self::HOOK_GROUPE, array( $tome_id, $ts ) );
		self::liberer_chapitres( $groupe );
		delete_post_meta( $tome_id, self::META_GROUPE );
	}

	/**
	 * Retire les marques d'une sortie groupée des chapitres qui ne sont pas en ligne : leur
	 * publication ultérieure sera notifiée normalement par core.
	 *
	 * @param array<string,mixed> $groupe Méta META_GROUPE.
	 * @return int[] Chapitres du groupe publiés.
	 */
	private static function liberer_chapitres( array $groupe ): array {
		$ts      = (int) ( $groupe['ts'] ?? 0 );
		$ignores = array_map( 'intval', (array) ( $groupe['ignores'] ?? array() ) );
		$publies = array();
		foreach ( array_map( 'intval', (array) ( $groupe['ids'] ?? array() ) ) as $id ) {
			if ( (int) get_post_meta( $id, self::META_GROUPE_CHAPITRE, true ) !== $ts ) {
				continue; // Chapitre passé depuis dans une autre sortie.
			}
			delete_post_meta( $id, self::META_GROUPE_CHAPITRE );
			if ( 'publish' === get_post_status( $id ) ) {
				$publies[] = $id;
			} elseif ( in_array( $id, $ignores, true ) && 'ignore' === get_post_meta( $id, self::META_NOTIFIE, true ) ) {
				delete_post_meta( $id, self::META_NOTIFIE );
			}
		}
		return $publies;
	}

	/**
	 * Tâche cron de la sortie groupée programmée : publie les chapitres arrivés à échéance
	 * (si WordPress ne l'a pas encore fait), puis annonce le groupe une seule fois.
	 *
	 * @param int $tome_id Tome.
	 * @param int $ts      Horodatage de la sortie.
	 */
	public static function sortie_groupee_programmee( $tome_id, $ts ): void {
		$tome_id = (int) $tome_id;
		$groupe  = get_post_meta( $tome_id, self::META_GROUPE, true );
		if ( ! is_array( $groupe ) || (int) ( $groupe['ts'] ?? 0 ) !== (int) $ts ) {
			return; // Sortie remplacée ou annulée.
		}
		delete_post_meta( $tome_id, self::META_GROUPE );
		foreach ( array_map( 'intval', (array) ( $groupe['ids'] ?? array() ) ) as $id ) {
			$post = get_post( $id );
			if ( $post && 'future' === $post->post_status && (int) get_post_meta( $id, self::META_GROUPE_CHAPITRE, true ) === (int) $ts ) {
				check_and_publish_future_post( $post );
				clean_post_cache( $id );
			}
		}
		$publies = self::liberer_chapitres( $groupe );
		if ( $publies && 'publish' === get_post_status( $tome_id ) ) {
			self::annoncer_groupe( $tome_id, $publies );
		}
	}

	/**
	 * Le tome n'a-t-il rien à lire : aucun chapitre (hors chapitres retirés) et aucun lien
	 * de téléchargement PDF ou EPUB ?
	 *
	 * @param int $tome_id Tome.
	 */
	public static function tome_vide( int $tome_id ): bool {
		foreach ( array( 'yume_lien_pdf', 'yume_lien_epub' ) as $cle ) {
			if ( '' !== trim( (string) get_post_meta( $tome_id, $cle, true ) ) ) {
				return false;
			}
		}
		foreach ( yume_get_chapitres( $tome_id, array( 'status' => array( 'draft', 'pending', 'future', 'publish', 'private' ) ) ) as $chapitre ) {
			if ( ! get_post_meta( $chapitre->ID, self::META_RETIRE, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Sortie programmée : la date cible du planning suit la date programmée (mise à jour
	 * journalisée par le module planning, s'il est chargé).
	 *
	 * @param int                $tome_id Tome.
	 * @param \DateTimeImmutable $date    Date de sortie.
	 */
	private static function caler_date_cible( int $tome_id, \DateTimeImmutable $date ): void {
		if ( ! function_exists( '\Yume\Core\Planning\mettre_a_jour' ) ) {
			return;
		}
		$jour = $date->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		if ( (string) get_post_meta( $tome_id, 'yume_date_cible', true ) === $jour ) {
			return;
		}
		\Yume\Core\Planning\mettre_a_jour( $tome_id, array( 'date_cible' => $jour ), get_current_user_id(), array( 'forcer' => true ) );
	}

	/**
	 * Publie tout de suite, ou programme, le tome, ses chapitres et son annonce.
	 *
	 * Un tome sans chapitre ni lien PDF/EPUB n'est publié (ou programmé) que sur confirmation
	 * explicite (option confirmer_vide) ; sinon erreur yume_tome_vide (409) : les lecteurs
	 * prévenus n'auraient rien à lire.
	 *
	 * Option sans_annonce (défaut false) : ajout au catalogue, voir ajouter_au_catalogue().
	 *
	 * Remplacement de lecture en ligne en attente (Remplacement) : appliqué en place d'abord
	 * (résultat « remplacement_applique » : remplaces, inchanges, nouveaux, retires).
	 *
	 * Mode « chapitres » (option mode) : voir publier_chapitres().
	 *
	 * @param int                 $tome_id Tome.
	 * @param string              $quand   « maintenant » ou date ISO.
	 * @param array<string,mixed> $options confirmer_vide (bool, défaut false), sans_annonce
	 *                                     (bool, défaut false), mode (chapitres : ajout de
	 *                                     chapitres), sortie (maintenant | rythme | date),
	 *                                     intervalle (jours), complet (bool ou null : repris de la
	 *                                     préparation), liens (lien_pdf, lien_epub).
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function publier( int $tome_id, string $quand = 'maintenant', array $options = array() ) {
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
		if ( self::MODE_CHAPITRES === ( $options['mode'] ?? '' ) ) {
			return self::publier_chapitres( $tome, $date, $options );
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
		// Seule une sortie applique un remplacement préparé (les chapitres en ligne changent ici).
		Remplacement::nettoyer_si_expiree( $tome_id );
		$applique = Remplacement::appliquer( $tome_id );
		if ( ! $deja_sorti && empty( $options['confirmer_vide'] ) && self::tome_vide( $tome_id ) ) {
			return new \WP_Error(
				'yume_tome_vide',
				__( 'Ce tome n’a aucun chapitre ni lien de téléchargement PDF ou EPUB : les lecteurs prévenus n’auraient rien à lire. Déposez le fichier du tome ou indiquez un lien, ou confirmez la publication d’un tome vide.', 'yume-core' ),
				array(
					'status'       => 409,
					'confirmation' => 'confirmer_vide',
				)
			);
		}
		$a_publier = array_values(
			array_filter(
				yume_get_chapitres( $tome_id, array( 'status' => array( 'draft', 'pending', 'future' ) ) ),
				static fn( \WP_Post $c ): bool => ! get_post_meta( $c->ID, self::META_RETIRE, true ) && ! metadata_exists( 'post', $c->ID, Remplacement::META_DE )
			)
		);
		if ( ! empty( $options['sans_annonce'] ) ) {
			$resultat = self::ajouter_au_catalogue( $tome, $a_publier, $date, $local, $gmt );
			if ( is_array( $resultat ) ) {
				$resultat['remplacement_applique'] = $applique;
			}
			return $resultat;
		}
		// Tome déjà en ligne qui reçoit plusieurs chapitres d'un coup (tome migré avec ses seuls
		// PDF/EPUB mis en lecture en ligne, nouveaux chapitres en bloc) : une seule sortie, et
		// non un événement yume_chapitre_publie (e-mails, Discord) par chapitre, que la sortie
		// soit immédiate ou programmée.
		$groupe = $deja_sorti && count( $a_publier ) > 1;

		// Une sortie groupée programmée auparavant est remplacée par celle-ci.
		self::annuler_sortie_groupee( $tome_id );

		if ( ! $deja_sorti || ( $groupe && $immediat ) ) {
			/**
			 * Une publication de tome commence : core n'émet aucun événement de sortie pendant
			 * celle-ci (le module publication émet yume_tome_publie une fois tout publié).
			 *
			 * @param int $tome_id Tome.
			 */
			do_action( 'yume_publication_en_cours', $tome_id );
		}

		$publies = 0;
		$ids     = array();
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
				$ids[] = (int) $chapitre->ID;
			}
		}
		if ( ! $deja_sorti ) {
			$donnees = array(
				'ID'            => $tome_id,
				'post_status'   => $statut,
				'post_date'     => $local,
				'post_date_gmt' => $gmt,
				'edit_date'     => true,
			);
			if ( self::slug_a_poser( $tome ) ) {
				$donnees['post_name'] = self::slug_tome_existant( $tome_id );
			}
			$ok = wp_update_post( $donnees, true );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$article_id = Annonce::sortir( $tome_id, $statut, $local, $gmt );
		if ( ! $immediat && 'future' === get_post_status( $tome_id ) ) {
			self::caler_date_cible( $tome_id, $date );
		}

		clean_post_cache( $tome_id );
		if ( $immediat && ! $deja_sorti && ! metadata_exists( 'post', $tome_id, self::META_NOTIFIE ) && 'publish' === get_post_status( $tome_id ) ) {
			/**
			 * Un tome vient de sortir (émis une seule fois, voir contrat §8).
			 *
			 * @param int $tome_id Tome.
			 */
			do_action( 'yume_tome_publie', $tome_id );
		} elseif ( $groupe && $immediat && $publies > 0 && 'publish' === get_post_status( $tome_id ) ) {
			self::annoncer_groupe( $tome_id, $ids );
		} elseif ( $groupe && ! $immediat && count( $ids ) > 1 ) {
			self::programmer_sortie_groupee( $tome_id, $ids, $date->getTimestamp() );
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
			'tome'                  => array_merge( self::resume_contenu( $tome ), array( 'libelle' => yume_libelle_tome( $tome_id ) ) ),
			'statut'                => $tome->post_status,
			'date'                  => mysql_to_rfc3339( $tome->post_date ),
			'chapitres'             => $publies,
			'article'               => $article_id ? self::resume_contenu( get_post( $article_id ) ) : null,
			'sans_annonce'          => false,
			'remplacement_applique' => $applique,
		);
	}

	/**
	 * Sortie de chapitres ajoutés à un tome (mode « chapitres », formulaire « Ajouter des
	 * chapitres à un tome ») :
	 *
	 * - les mises à jour choisies (« Mettre à jour (sans annonce) ») sont appliquées en place tout
	 *   de suite, sans annonce ; un remplacement complet en attente n'est jamais appliqué ici ;
	 * - les chapitres à sortir (brouillons du tome, hors chapitres retirés) sortent maintenant
	 *   (une seule annonce), un par un au rythme du tome (calendrier() ; une annonce par chapitre
	 *   à sa sortie) ou ensemble à une date ; les chapitres déjà programmés gardent leur date ;
	 * - première sortie du tome : le tome sort avec ses premiers chapitres ; sans « Tome
	 *   complet », yume_parution = en_cours et l'annonce dit « SukaMoka, Tome 2 : Prologue
	 *   disponible ! » ; tome complet publié d'un coup : yume_parution = complet, liens posés,
	 *   annonce et planning habituels ;
	 * - « Tome complet » d'un tome déjà en cours (ou sorti un par un) : marquer_complet()
	 *   maintenant, ou à la sortie du dernier chapitre programmé (tâche HOOK_COMPLET) ;
	 * - sans annonce (option sans_annonce) : comme un ajout au catalogue, rien n'est annoncé.
	 *
	 * @param \WP_Post                $tome    Tome.
	 * @param \DateTimeImmutable|null $date    Date choisie (null : maintenant).
	 * @param array<string,mixed>     $options Voir publier().
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function publier_chapitres( \WP_Post $tome, ?\DateTimeImmutable $date, array $options ) {
		$tome_id    = (int) $tome->ID;
		$deja_sorti = 'publish' === $tome->post_status;
		$sortie     = in_array( $options['sortie'] ?? '', self::SORTIES, true ) ? (string) $options['sortie'] : ( null === $date ? 'maintenant' : 'date' );
		if ( 'date' === $sortie && null === $date ) {
			return new \WP_Error( 'yume_date_manquante', __( 'Indiquez la date et l’heure de sortie des nouveaux chapitres.', 'yume-core' ), array( 'status' => 400 ) );
		}
		$meta    = get_post_meta( $tome_id, self::META, true );
		$meta    = is_array( $meta ) ? $meta : array();
		$complet = isset( $options['complet'] ) && null !== $options['complet'] ? (bool) $options['complet'] : ! empty( $meta['complet'] );
		// « Liens avec le dernier chapitre » : le tome passe « Publié » (liens affichés) à la sortie
		// de son dernier chapitre programmé. Sans objet si le tome est complet tout de suite.
		$liens_dernier = ! $complet && ( isset( $options['liens_dernier'] ) && null !== $options['liens_dernier'] ? (bool) $options['liens_dernier'] : ! empty( $meta['liens_dernier'] ) );
		// « Le tome est complet » : tout est publié maintenant, chapitres déjà programmés compris.
		if ( $complet ) {
			$sortie = 'maintenant';
			$date   = null;
			self::annuler_programmations( $tome_id );
		}
		$liens = self::liens( isset( $options['liens'] ) && is_array( $options['liens'] ) && $options['liens'] ? $options['liens'] : (array) ( $meta['liens'] ?? array() ) );
		if ( is_wp_error( $liens ) ) {
			return $liens;
		}
		$muet = ! empty( $options['sans_annonce'] );

		// Chapitres en ligne à mettre à jour (choix de l'équipe) : appliqués maintenant, en place.
		Remplacement::nettoyer_si_expiree( $tome_id );
		$etat     = Remplacement::lire( $tome_id );
		$applique = $etat && self::MODE_CHAPITRES === ( $etat['mode'] ?? '' ) ? Remplacement::appliquer( $tome_id ) : null;

		$a_publier = array_values(
			array_filter(
				yume_get_chapitres( $tome_id, array( 'status' => $complet ? array( 'draft', 'pending', 'future' ) : array( 'draft', 'pending' ) ) ),
				static fn( \WP_Post $c ): bool => ! get_post_meta( $c->ID, self::META_RETIRE, true ) && ! metadata_exists( 'post', $c->ID, Remplacement::META_DE )
			)
		);
		if ( ! $deja_sorti && empty( $options['confirmer_vide'] ) && self::tome_vide( $tome_id ) && ! ( $complet && array_filter( $liens ) ) ) {
			return new \WP_Error(
				'yume_tome_vide',
				__( 'Ce tome n’a aucun chapitre ni lien de téléchargement PDF ou EPUB : les lecteurs prévenus n’auraient rien à lire. Déposez le fichier du tome ou indiquez un lien, ou confirmez la publication d’un tome vide.', 'yume-core' ),
				array(
					'status'       => 409,
					'confirmation' => 'confirmer_vide',
				)
			);
		}
		$dates    = self::calendrier( $tome_id, count( $a_publier ), $sortie, $date, (int) ( $options['intervalle'] ?? self::INTERVALLE_DEFAUT ) );
		$premiere = $a_publier ? $dates[0] : ( 'maintenant' === $sortie ? null : $date );
		$fin      = null;
		$creneaux = array();
		foreach ( $dates as $d ) {
			$creneaux[ $d ? $d->getTimestamp() : 0 ] = true;
			if ( $d && ( null === $fin || $d > $fin ) ) {
				$fin = $d;
			}
		}
		// Liens avec le dernier chapitre, mais rien d'autre n'est programmé : c'est une sortie
		// complète, maintenant, ou à la date choisie pour la première sortie du tome entier.
		if ( $liens_dernier && null === self::dernier_programme( $tome_id ) && ( null === $fin || ( ! $deja_sorti && count( $creneaux ) <= 1 ) ) ) {
			$complet       = true;
			$liens_dernier = false;
		}
		// Tome complet publié d'un coup (tous ses chapitres ensemble) : sortie de tome habituelle.
		$complet_direct = $complet && ! $deja_sorti && count( $creneaux ) <= 1;
		if ( ! $deja_sorti ) {
			update_post_meta( $tome_id, 'yume_parution', $complet_direct ? 'complet' : 'en_cours' );
			if ( $complet_direct ) {
				self::ecrire_liens( $tome_id, $liens );
			}
		}
		// Une sortie groupée programmée auparavant (chapitres déjà programmés) n'est pas touchée.
		$faite = $muet
			? self::sortie_muette( $tome, $a_publier, $dates, $premiere )
			: self::sortie_annoncee( $tome, $a_publier, $dates, $premiere, $complet_direct );
		if ( is_wp_error( $faite ) ) {
			return $faite;
		}

		// « Le tome est complet » : maintenant, ou à la sortie du dernier chapitre programmé.
		$etat_complet    = $complet_direct ? 'fait' : '';
		$complet_le      = '';
		$article_complet = null;
		if ( $liens_dernier ) {
			// Liens avec le dernier chapitre : à la sortie du dernier chapitre programmé du tome
			// (nouveaux ou déjà programmés), ou tout de suite s'il n'y en a aucun.
			$dernier = self::dernier_programme( $tome_id );
			if ( $dernier && ( null === $fin || $dernier > $fin ) ) {
				$fin = $dernier;
			}
			$complet = true;
		}
		if ( $complet && ! $complet_direct ) {
			if ( null === $fin && 'publish' === get_post_status( $tome_id ) ) {
				$fait = self::marquer_complet( $tome_id, $liens, ! $muet );
				if ( ! is_wp_error( $fait ) ) {
					$etat_complet    = 'fait';
					$article_complet = $fait['article'];
				}
			} else {
				$quand = $fin ? $fin : ( $premiere ? $premiere : new \DateTimeImmutable( 'now', wp_timezone() ) );
				self::programmer_complet( $tome_id, $quand->getTimestamp(), $liens, ! $muet );
				$etat_complet = 'programme';
				$complet_le   = $quand->format( DATE_ATOM );
			}
		}
		// Sortie sans annonce : aucun événement, l'avancement du planning suit quand même.
		if ( $muet && function_exists( '\Yume\Core\Planning\avancer_selon_chapitres' ) ) {
			\Yume\Core\Planning\avancer_selon_chapitres( $tome_id, get_current_user_id() );
		}

		$meta = get_post_meta( $tome_id, self::META, true );
		$meta = is_array( $meta ) ? $meta : array();
		update_post_meta(
			$tome_id,
			self::META,
			array_merge(
				$meta,
				array(
					'sortie'        => null === $premiere ? 'maintenant' : $premiere->format( DATE_ATOM ),
					'sortie_par'    => get_current_user_id(),
					'sortie_le'     => current_time( 'mysql', true ),
					'sans_annonce'  => $muet,
					// « Tome complet » traité : il ne s'appliquera pas à une prochaine sortie.
					'complet'       => false,
					'liens_dernier' => false,
					'liens'         => array(),
				)
			)
		);
		clean_post_cache( $tome_id );
		$tome = get_post( $tome_id );
		return array(
			'tome'                  => array_merge( self::resume_contenu( $tome ), array( 'libelle' => yume_libelle_tome( $tome_id ) ) ),
			'statut'                => $tome->post_status,
			'date'                  => mysql_to_rfc3339( $tome->post_date ),
			'chapitres'             => (int) $faite['publies'],
			'calendrier'            => $faite['calendrier'],
			'article'               => $faite['article'] ? self::resume_contenu( get_post( $faite['article'] ) ) : null,
			'article_complet'       => $article_complet,
			'sans_annonce'          => $muet,
			'remplacement_applique' => $applique,
			'mode'                  => self::MODE_CHAPITRES,
			'sortie'                => $sortie,
			'parution'              => yume_parution_tome( $tome_id ),
			'complet'               => $etat_complet,
			'complet_le'            => $complet_le,
			'en_ligne'              => count( yume_get_chapitres( $tome_id ) ),
		);
	}

	/**
	 * Statut et dates (locale, GMT) d'une sortie : maintenant (null) ou programmée.
	 *
	 * @param \DateTimeImmutable|null $date Date.
	 * @return array{0:string,1:string,2:string} statut, date locale, date GMT.
	 */
	private static function horodatage( ?\DateTimeImmutable $date ): array {
		if ( null === $date ) {
			return array( 'publish', current_time( 'mysql' ), current_time( 'mysql', true ) );
		}
		return array(
			'future',
			$date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
			$date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Publie maintenant ou programme un chapitre ou un tome.
	 *
	 * @param int                     $post_id Contenu.
	 * @param \DateTimeImmutable|null $date    Date (null : maintenant).
	 * @param string                  $slug    Adresse à poser (vide : inchangée).
	 * @return int|\WP_Error
	 */
	private static function sortir_contenu( int $post_id, ?\DateTimeImmutable $date, string $slug = '' ) {
		list( $statut, $local, $gmt ) = self::horodatage( $date );
		$donnees                      = array(
			'ID'            => $post_id,
			'post_status'   => $statut,
			'post_date'     => $local,
			'post_date_gmt' => $gmt,
			'edit_date'     => true,
		);
		if ( '' !== $slug ) {
			$donnees['post_name'] = $slug;
		}
		return wp_update_post( $donnees, true );
	}

	/**
	 * Ligne du calendrier de sortie d'un chapitre (réponses, récapitulatif).
	 *
	 * @param int $chapitre_id Chapitre.
	 * @return array<string,mixed>
	 */
	private static function ligne_calendrier( int $chapitre_id ): array {
		clean_post_cache( $chapitre_id );
		$post = get_post( $chapitre_id );
		return array(
			'id'           => $chapitre_id,
			'libelle'      => yume_libelle_chapitre( $chapitre_id ),
			'titre'        => self::titre_texte( $post ),
			'statut'       => $post ? $post->post_status : '',
			'date'         => $post ? mysql_to_rfc3339( $post->post_date ) : '',
			'date_libelle' => $post ? Formulaire::date_fr( (int) strtotime( $post->post_date_gmt . ' UTC' ), 'court' ) : '',
			'lien'         => $post && 'publish' === $post->post_status ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post ),
		);
	}

	/**
	 * Sortie annoncée de chapitres (voir publier_chapitres()).
	 *
	 * - Première sortie du tome : le tome sort avec ses premiers chapitres (yume_tome_publie
	 *   émis une fois, ou par core à la date programmée) et son article d'annonce (variante
	 *   « chapitres disponibles », ou modèle habituel pour un tome complet publié d'un coup) ;
	 *   les chapitres programmés ensuite sont annoncés un par un à leur sortie (core).
	 * - Tome déjà en ligne : les chapitres sortis maintenant forment une seule annonce
	 *   (annoncer_groupe()) ; plusieurs chapitres programmés à la même date aussi (sortie groupée
	 *   programmée), sauf si une autre sortie groupée attend déjà ; un chapitre programmé seul
	 *   est annoncé à sa sortie (core).
	 *
	 * @param \WP_Post                           $tome           Tome.
	 * @param \WP_Post[]                         $a_publier      Chapitres à sortir.
	 * @param array<int,\DateTimeImmutable|null> $dates          Date de chaque chapitre.
	 * @param \DateTimeImmutable|null            $premiere       Date de sortie du tome.
	 * @param bool                               $complet_direct Tome complet publié d'un coup.
	 * @return array{publies:int,calendrier:array<int,array<string,mixed>>,article:int}|\WP_Error
	 */
	private static function sortie_annoncee( \WP_Post $tome, array $a_publier, array $dates, ?\DateTimeImmutable $premiere, bool $complet_direct ) {
		$tome_id    = (int) $tome->ID;
		$deja_sorti = 'publish' === $tome->post_status;
		if ( ! $deja_sorti || in_array( null, $dates, true ) ) {
			/** This action is documented in includes/publication/class-service.php */
			do_action( 'yume_publication_en_cours', $tome_id );
		}
		$maintenant = array();
		$par_date   = array();
		$calendrier = array();
		foreach ( $a_publier as $i => $chapitre ) {
			$d  = $dates[ $i ] ?? null;
			$ok = self::sortir_contenu( (int) $chapitre->ID, $d );
			if ( is_wp_error( $ok ) ) {
				continue;
			}
			if ( null === $d ) {
				$maintenant[] = (int) $chapitre->ID;
			} else {
				$par_date[ $d->getTimestamp() ][] = (int) $chapitre->ID;
			}
			$calendrier[] = self::ligne_calendrier( (int) $chapitre->ID );
		}
		$article_id = 0;
		if ( ! $deja_sorti ) {
			$ok = self::sortir_contenu( $tome_id, $premiere, self::slug_a_poser( $tome ) ? self::slug_tome_existant( $tome_id ) : '' );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			$premiers = null === $premiere ? $maintenant : ( $par_date[ $premiere->getTimestamp() ] ?? array() );
			$avert    = array();
			self::preparer_annonce( $tome_id, $complet_direct ? '' : 'en_cours', $premiers, $avert );
			list( $statut, $local, $gmt ) = self::horodatage( $premiere );
			$article_id                   = Annonce::sortir( $tome_id, $statut, $local, $gmt );
			if ( $premiere && 'future' === get_post_status( $tome_id ) ) {
				self::caler_date_cible( $tome_id, $premiere );
			}
			clean_post_cache( $tome_id );
			if ( null === $premiere && ! metadata_exists( 'post', $tome_id, self::META_NOTIFIE ) && 'publish' === get_post_status( $tome_id ) ) {
				/** This action is documented in includes/publication/class-service.php */
				do_action( 'yume_tome_publie', $tome_id );
			}
		} else {
			if ( $maintenant ) {
				self::annoncer_groupe( $tome_id, $maintenant );
			}
			foreach ( $par_date as $ts => $ids ) {
				$groupe = get_post_meta( $tome_id, self::META_GROUPE, true );
				// Une seule sortie groupée programmée par tome : si une autre attend, ces chapitres
				// seront annoncés un par un à leur sortie.
				if ( count( $ids ) > 1 && ! ( is_array( $groupe ) && (int) ( $groupe['ts'] ?? 0 ) > time() ) ) {
					self::programmer_sortie_groupee( $tome_id, $ids, (int) $ts );
				}
			}
		}
		return array(
			'publies'    => count( $calendrier ),
			'calendrier' => $calendrier,
			'article'    => (int) $article_id,
		);
	}

	/**
	 * Sortie sans annonce de chapitres (option sans_annonce du mode chapitres), comme un ajout
	 * au catalogue (ajouter_au_catalogue()) : aucun événement ni article, chapitres et tome
	 * marqués « catalogue ». Tome déjà paru et complet, sortie immédiate : les chapitres prennent
	 * la date du tome ; tome en cours : ils sont datés de leur sortie.
	 *
	 * @param \WP_Post                           $tome      Tome.
	 * @param \WP_Post[]                         $a_publier Chapitres à sortir.
	 * @param array<int,\DateTimeImmutable|null> $dates     Date de chaque chapitre.
	 * @param \DateTimeImmutable|null            $premiere  Date de sortie du tome.
	 * @return array{publies:int,calendrier:array<int,array<string,mixed>>,article:int}|\WP_Error
	 */
	private static function sortie_muette( \WP_Post $tome, array $a_publier, array $dates, ?\DateTimeImmutable $premiere ) {
		$tome_id    = (int) $tome->ID;
		$deja_sorti = 'publish' === $tome->post_status;
		$date_tome  = $deja_sorti && 'en_cours' !== yume_parution_tome( $tome_id ) && '' !== (string) $tome->post_date_gmt && ! str_starts_with( (string) $tome->post_date_gmt, '0000-00-00' );
		$muet       = static function (): bool {
			return false;
		};
		$calendrier = array();
		add_filter( 'yume_core_notifier', $muet, 99 );
		try {
			$notifie = (string) get_post_meta( $tome_id, self::META_NOTIFIE, true );
			if ( '' === $notifie || 'ignore' === $notifie ) {
				update_post_meta( $tome_id, self::META_NOTIFIE, self::NOTIFIE_CATALOGUE );
			}
			delete_post_meta( $tome_id, '_yume_notification_en_attente' );
			foreach ( $a_publier as $i => $chapitre ) {
				$chapitre_id = (int) $chapitre->ID;
				$valeur      = (string) get_post_meta( $chapitre_id, self::META_NOTIFIE, true );
				if ( '' === $valeur || 'ignore' === $valeur ) {
					update_post_meta( $chapitre_id, self::META_NOTIFIE, self::NOTIFIE_CATALOGUE );
				}
				$d = $dates[ $i ] ?? null;
				if ( null === $d && $date_tome ) {
					$ok = wp_update_post(
						array(
							'ID'            => $chapitre_id,
							'post_status'   => 'publish',
							'post_date'     => (string) $tome->post_date,
							'post_date_gmt' => (string) $tome->post_date_gmt,
							'edit_date'     => true,
						),
						true
					);
				} else {
					$ok = self::sortir_contenu( $chapitre_id, $d );
				}
				if ( ! is_wp_error( $ok ) ) {
					$calendrier[] = self::ligne_calendrier( $chapitre_id );
				}
			}
			if ( ! $deja_sorti ) {
				$ok = self::sortir_contenu( $tome_id, $premiere, self::slug_a_poser( $tome ) ? self::slug_tome_existant( $tome_id ) : '' );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
		} finally {
			remove_filter( 'yume_core_notifier', $muet, 99 );
		}
		if ( $premiere && 'future' === get_post_status( $tome_id ) ) {
			self::caler_date_cible( $tome_id, $premiere );
		}
		clean_post_cache( $tome_id );
		self::planning_catalogue( $tome_id, $deja_sorti, null === $premiere, count( $calendrier ) );
		return array(
			'publies'    => count( $calendrier ),
			'calendrier' => $calendrier,
			'article'    => 0,
		);
	}

	/**
	 * Crée ou régénère l'article d'annonce de la première sortie d'un tome (mode chapitres).
	 *
	 * @param int        $tome_id   Tome.
	 * @param string     $parution  en_cours (variante « chapitres disponibles ») ou vide
	 *                              (modèle habituel d'un tome complet).
	 * @param int[]|null $chapitres Chapitres de la sortie (null : brouillons du tome).
	 * @param string[]   $avert     Avertissements (modifié).
	 */
	private static function preparer_annonce( int $tome_id, string $parution, ?array $chapitres, array &$avert ): int {
		$speciaux   = array();
		$nb         = 0;
		$brouillons = array();
		foreach ( yume_get_chapitres( $tome_id, array( 'status' => 'any' ) ) as $chap ) {
			if ( get_post_meta( $chap->ID, self::META_RETIRE, true ) ) {
				continue;
			}
			if ( in_array( $chap->post_status, array( 'draft', 'pending' ), true ) ) {
				$brouillons[] = (int) $chap->ID;
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
				'sortie'       => array(
					'parution'  => $parution,
					'chapitres' => null === $chapitres ? $brouillons : $chapitres,
				),
			)
		);
		if ( is_wp_error( $article_id ) ) {
			$avert[] = $article_id->get_error_message();
			return 0;
		}
		return (int) $article_id;
	}

	/**
	 * Enregistre les liens PDF / EPUB fournis (clés présentes seulement).
	 *
	 * @param int                  $tome_id Tome.
	 * @param array<string,string> $liens   lien_pdf, lien_epub (contrôlés par liens()).
	 */
	private static function ecrire_liens( int $tome_id, array $liens ): void {
		foreach ( array( 'lien_pdf', 'lien_epub' ) as $cle ) {
			if ( array_key_exists( $cle, $liens ) ) {
				update_post_meta( $tome_id, 'yume_' . $cle, (string) $liens[ $cle ] );
			}
		}
	}

	/**
	 * Marque un tome publié chapitre par chapitre comme complet (case « Le tome est complet avec
	 * ces chapitres ») : liens PDF / EPUB enregistrés, yume_parution = complet, passage complet
	 * programmé annulé. Tome en ligne qui n'était pas encore complet : action yume_tome_complet
	 * (planning « publié » 100 %, journal, Discord) et, s'il était en cours de parution et que
	 * $annoncer est vrai, article « Le tome 2 de SukaMoka est complet : PDF et EPUB disponibles »
	 * (Annonce::complet()). Un tome pas encore en ligne est seulement marqué : il sortira comme un
	 * tome complet. Réutilisable par l'espace équipe (« Modifier le tome »).
	 *
	 * @param int                  $tome_id  Tome.
	 * @param array<string,string> $liens    lien_pdf, lien_epub (clés présentes seulement).
	 * @param bool                 $annoncer Annoncer (article, Discord) le tome complet.
	 * @return array<string,mixed>|\WP_Error tome, parution, annonce (bool), article (ou null).
	 */
	public static function marquer_complet( int $tome_id, array $liens = array(), bool $annoncer = true ) {
		$tome = get_post( $tome_id );
		if ( ! $tome || 'yume_tome' !== $tome->post_type || in_array( $tome->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), array( 'status' => 404 ) );
		}
		$liens = self::liens( $liens );
		if ( is_wp_error( $liens ) ) {
			return $liens;
		}
		$avant = yume_parution_tome( $tome_id );
		self::ecrire_liens( $tome_id, $liens );
		update_post_meta( $tome_id, 'yume_parution', 'complet' );
		self::annuler_complet_programme( $tome_id );
		$annonce    = false;
		$article_id = 0;
		if ( 'publish' === $tome->post_status && 'complet' !== $avant ) {
			$annonce = $annoncer && 'en_cours' === $avant;
			if ( $annonce ) {
				$article_id = Annonce::complet( $tome_id );
				$article_id = is_wp_error( $article_id ) ? 0 : (int) $article_id;
			}
			clean_post_cache( $tome_id );
			/**
			 * Un tome publié chapitre par chapitre est désormais complet (contrat §8).
			 *
			 * @param int  $tome_id  Tome.
			 * @param bool $annoncer Annonce publique (Discord) de la fin de parution.
			 */
			do_action( 'yume_tome_complet', $tome_id, $annonce );
		}
		return array(
			'tome'     => array_merge( self::resume_contenu( get_post( $tome_id ) ), array( 'libelle' => yume_libelle_tome( $tome_id ) ) ),
			'parution' => yume_parution_tome( $tome_id ),
			'annonce'  => $annonce,
			'article'  => $article_id ? self::resume_contenu( get_post( $article_id ) ) : null,
		);
	}

	/**
	 * Programme le passage « complet » d'un tome à la sortie de son dernier chapitre.
	 *
	 * @param int                  $tome_id  Tome.
	 * @param int                  $ts       Horodatage.
	 * @param array<string,string> $liens    Liens PDF / EPUB.
	 * @param bool                 $annoncer Annoncer la fin de parution.
	 */
	private static function programmer_complet( int $tome_id, int $ts, array $liens, bool $annoncer ): void {
		update_post_meta(
			$tome_id,
			self::META_COMPLET,
			array(
				'ts'       => $ts,
				'annoncer' => $annoncer,
				'liens'    => $liens,
			)
		);
		wp_clear_scheduled_hook( self::HOOK_COMPLET, array( $tome_id ) );
		wp_schedule_single_event( $ts, self::HOOK_COMPLET, array( $tome_id ) );
	}

	/**
	 * Annule le passage « complet » programmé d'un tome.
	 *
	 * @param int $tome_id Tome.
	 */
	private static function annuler_complet_programme( int $tome_id ): void {
		delete_post_meta( $tome_id, self::META_COMPLET );
		wp_clear_scheduled_hook( self::HOOK_COMPLET, array( $tome_id ) );
	}

	/**
	 * Annule ce qui attend la sortie d'un tome : sortie groupée programmée (tâche cron, marques
	 * des chapitres pas encore en ligne) et passage « complet » programmé. Utilisé quand
	 * l'équipe remet un tome « Planifié » (espace équipe, « Modifier le tome »).
	 *
	 * @param int $tome_id Tome.
	 * @return array{groupe:bool,complet:bool} Ce qui était programmé.
	 */
	public static function annuler_programmations( int $tome_id ): array {
		$annule = array(
			'groupe'  => is_array( get_post_meta( $tome_id, self::META_GROUPE, true ) ),
			'complet' => is_array( get_post_meta( $tome_id, self::META_COMPLET, true ) ),
		);
		self::annuler_sortie_groupee( $tome_id );
		self::annuler_complet_programme( $tome_id );
		return $annule;
	}

	/**
	 * Tâche cron du passage « complet » programmé : publie d'abord les chapitres arrivés à
	 * échéance (ordre des tâches non garanti) ; s'il reste des chapitres programmés plus tard
	 * (ajoutés depuis), le passage est repoussé à la sortie du dernier ; sinon marquer_complet().
	 *
	 * @param int $tome_id Tome.
	 */
	public static function complet_programme( $tome_id ): void {
		$tome_id = (int) $tome_id;
		$prog    = get_post_meta( $tome_id, self::META_COMPLET, true );
		if ( ! is_array( $prog ) || 'yume_tome' !== get_post_type( $tome_id ) ) {
			return;
		}
		$plus_tard = 0;
		foreach ( yume_get_chapitres( $tome_id, array( 'status' => 'future' ) ) as $chapitre ) {
			$ts = (int) strtotime( $chapitre->post_date_gmt . ' UTC' );
			if ( $ts <= time() ) {
				check_and_publish_future_post( $chapitre );
				clean_post_cache( $chapitre->ID );
			} else {
				$plus_tard = max( $plus_tard, $ts );
			}
		}
		$statut = (string) get_post_status( $tome_id );
		if ( 'future' === $statut ) {
			$plus_tard = max( $plus_tard, (int) strtotime( (string) get_post_field( 'post_date_gmt', $tome_id ) . ' UTC' ) );
		} elseif ( 'publish' !== $statut ) {
			// Tome retiré du site entre-temps : le passage est abandonné (à refaire à son retour).
			self::annuler_complet_programme( $tome_id );
			return;
		}
		if ( $plus_tard > 0 ) {
			// Chapitres programmés après (ajoutés depuis), ou tome pas encore sorti : le passage attend.
			self::programmer_complet( $tome_id, max( $plus_tard, time() + MINUTE_IN_SECONDS ), (array) ( $prog['liens'] ?? array() ), ! empty( $prog['annoncer'] ) );
			return;
		}
		self::marquer_complet( $tome_id, (array) ( $prog['liens'] ?? array() ), ! empty( $prog['annoncer'] ) );
	}

	/**
	 * Ajout au catalogue (sans annonce) : les chapitres en attente du tome (et le tome s'il
	 * n'est pas encore en ligne) sont publiés ou programmés comme d'habitude (lecture, sommaire,
	 * étape du planning), mais rien n'est annoncé pour cette opération :
	 *
	 * - aucun yume_tome_publie ni yume_chapitre_publie (ni Discord, ni e-mail, ni récapitulatif
	 *   hebdomadaire) : filtre yume_core_notifier coupé pendant l'opération et chapitres marqués
	 *   _yume_publie_notifie = « catalogue » (une sortie programmée publiée plus tard par le cron
	 *   reste muette) ;
	 * - aucun article d'annonce créé, mis à jour ou publié ;
	 * - tome marqué « catalogue » s'il n'avait jamais été annoncé : une vraie sortie ultérieure
	 *   (nouveaux chapitres) sera annoncée comme telle, jamais le contenu ancien comme une nouveauté ;
	 * - tome déjà paru, publication immédiate : les chapitres prennent la date de sortie du tome
	 *   (la « dernière sortie » de l'œuvre et les listes de nouveautés ne bougent pas) ;
	 * - journal de l'équipe : « lecture en ligne ajoutée (sans annonce) » (ligne non publique) ;
	 * - tome paru qui avait déjà des chapitres en ligne : remplacement de la lecture en ligne
	 *   (résultat remplacement = true, en_ligne = chapitres en ligne après l'opération).
	 *
	 * @param \WP_Post                $tome      Tome.
	 * @param \WP_Post[]              $a_publier Chapitres à publier.
	 * @param \DateTimeImmutable|null $date      Date programmée (null : maintenant).
	 * @param string                  $local     Date locale « Y-m-d H:i:s ».
	 * @param string                  $gmt       Date GMT « Y-m-d H:i:s ».
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function ajouter_au_catalogue( \WP_Post $tome, array $a_publier, ?\DateTimeImmutable $date, string $local, string $gmt ) {
		$tome_id    = (int) $tome->ID;
		$immediat   = null === $date;
		$statut     = $immediat ? 'publish' : 'future';
		$deja_sorti = 'publish' === $tome->post_status;
		// Tome paru qui avait déjà une lecture en ligne : remplacement (chapitres mis à jour en
		// place par preparer(), seuls les nouveaux chapitres sont publiés ici).
		$en_ligne = $deja_sorti ? count( yume_get_chapitres( $tome_id ) ) : 0;
		if ( $deja_sorti && $immediat && '' !== (string) $tome->post_date_gmt && ! str_starts_with( (string) $tome->post_date_gmt, '0000-00-00' ) ) {
			$local = (string) $tome->post_date;
			$gmt   = (string) $tome->post_date_gmt;
		}

		// Une sortie groupée programmée auparavant est remplacée par celle-ci.
		self::annuler_sortie_groupee( $tome_id );

		$muet = static function (): bool {
			return false;
		};
		add_filter( 'yume_core_notifier', $muet, 99 );
		try {
			$notifie = (string) get_post_meta( $tome_id, self::META_NOTIFIE, true );
			if ( '' === $notifie || 'ignore' === $notifie ) {
				update_post_meta( $tome_id, self::META_NOTIFIE, self::NOTIFIE_CATALOGUE );
			}
			delete_post_meta( $tome_id, '_yume_notification_en_attente' );
			$publies = 0;
			$ids     = array();
			foreach ( $a_publier as $chapitre ) {
				$chapitre_id = (int) $chapitre->ID;
				$valeur      = (string) get_post_meta( $chapitre_id, self::META_NOTIFIE, true );
				if ( '' === $valeur || 'ignore' === $valeur ) {
					update_post_meta( $chapitre_id, self::META_NOTIFIE, self::NOTIFIE_CATALOGUE );
				}
				$ok = wp_update_post(
					array(
						'ID'            => $chapitre_id,
						'post_status'   => $statut,
						'post_date'     => $local,
						'post_date_gmt' => $gmt,
						'edit_date'     => true,
					),
					true
				);
				if ( ! is_wp_error( $ok ) ) {
					++$publies;
					$ids[] = $chapitre_id;
				}
			}
			if ( ! $deja_sorti ) {
				$donnees = array(
					'ID'            => $tome_id,
					'post_status'   => $statut,
					'post_date'     => $local,
					'post_date_gmt' => $gmt,
					'edit_date'     => true,
				);
				if ( self::slug_a_poser( $tome ) ) {
					$donnees['post_name'] = self::slug_tome_existant( $tome_id );
				}
				$ok = wp_update_post( $donnees, true );
				if ( is_wp_error( $ok ) ) {
					return $ok;
				}
			}
		} finally {
			remove_filter( 'yume_core_notifier', $muet, 99 );
		}

		if ( ! $immediat && 'future' === get_post_status( $tome_id ) ) {
			self::caler_date_cible( $tome_id, $date );
		}
		clean_post_cache( $tome_id );
		self::planning_catalogue( $tome_id, $deja_sorti, $immediat, $publies );

		$meta = get_post_meta( $tome_id, self::META, true );
		$meta = is_array( $meta ) ? $meta : array();
		update_post_meta(
			$tome_id,
			self::META,
			array_merge(
				$meta,
				array(
					'sortie'       => $immediat ? 'maintenant' : $date->format( DATE_ATOM ),
					'sortie_par'   => get_current_user_id(),
					'sortie_le'    => current_time( 'mysql', true ),
					'sans_annonce' => true,
				)
			)
		);
		$tome = get_post( $tome_id );
		return array(
			'tome'         => array_merge( self::resume_contenu( $tome ), array( 'libelle' => yume_libelle_tome( $tome_id ) ) ),
			'statut'       => $tome->post_status,
			'date'         => mysql_to_rfc3339( $tome->post_date ),
			'chapitres'    => $publies,
			'article'      => null,
			'sans_annonce' => true,
			'remplacement' => $en_ligne > 0,
			'en_ligne'     => count( yume_get_chapitres( $tome_id ) ),
		);
	}

	/**
	 * Planning après un ajout au catalogue (module planning chargé) : un tome qui vient d'être mis
	 * en ligne passe à l'étape « publié » (sans ligne « publie » du journal public, qui compterait
	 * comme une sortie), puis une ligne d'équipe « lecture en ligne ajoutée (sans annonce) ».
	 *
	 * @param int  $tome_id    Tome.
	 * @param bool $deja_sorti Le tome était déjà en ligne.
	 * @param bool $immediat   Publication immédiate.
	 * @param int  $publies    Chapitres publiés ou programmés.
	 */
	private static function planning_catalogue( int $tome_id, bool $deja_sorti, bool $immediat, int $publies ): void {
		$user_id = get_current_user_id();
		// Tome en cours de parution (chapitre par chapitre) : l'étape reste, l'avancement suit
		// les chapitres (Planning\avancer_selon_chapitres()).
		$en_cours = 'en_cours' === (string) get_post_meta( $tome_id, 'yume_parution', true );
		if ( ! $deja_sorti && $immediat && ! $en_cours && 'publish' === get_post_status( $tome_id ) && function_exists( '\Yume\Core\Planning\mettre_a_jour' ) ) {
			\Yume\Core\Planning\mettre_a_jour(
				$tome_id,
				array(
					'etape'      => 'publie',
					'avancement' => array(
						'traduction' => 100,
						'relecture'  => 100,
						'edition'    => 100,
					),
					'bloque'     => false,
				),
				$user_id,
				array( 'forcer' => true )
			);
		}
		if ( $publies > 0 && function_exists( 'yume_journal_planning' ) ) {
			yume_journal_planning(
				$tome_id,
				$user_id,
				'lecture_ajoutee',
				'',
				array(
					'chapitres' => $publies,
					'programme' => ! $immediat,
				)
			);
		}
	}
}
