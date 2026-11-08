<?php
/**
 * Programmation des sorties (contrat §8, §9) : calendrier sur mesure (sortie « calendrier »),
 * répartition des chapitres à sortir en N publications et reprogrammation des chapitres déjà
 * programmés (vue de l'espace équipe « Programmer les sorties »).
 *
 * - Unités : les chapitres à sortir d'un tome, dans l'ordre de yume_get_chapitres() (brouillons
 *   hors chapitres retirés et versions en attente ; la vue dédiée y ajoute les chapitres
 *   programmés). Une unité est traitée de façon générique : clé, libellé, nature, mots.
 * - repartir() : partition CONTIGUË des unités en N publications qui minimise la plus grosse en
 *   nombre de mots (partition linéaire) puis, à maximum égal, la somme des carrés (groupes les
 *   plus réguliers). Une unité à 0 mot (chapitre parent sans texte, page d'illustrations) est
 *   collée à l'unité suivante (à la précédente si elle est la dernière) : elle ne forme jamais
 *   une publication seule. Même algorithme, à l'identique, dans programmer.js (formulaire).
 * - Calendrier envoyé par le formulaire (champ « calendrier », JSON) :
 *   {"publications": [{"date": "2026-11-15T18:00", "chapitres": ["chapitre:1#1", "id:123"]}]} ;
 *   clés = clés de comparaison du fichier analysé (Service::comparer()) ou « id:N » pour un
 *   chapitre déjà enregistré. Chaque chapitre à sortir est affecté une fois, dans l'ordre ;
 *   date vide = maintenant, date passée = maintenant (avertissement), dates croissantes.
 *   Sans JavaScript : « repartition » (N) + date de départ et intervalle, ou rythme du tome.
 * - Les chapitres d'une même publication partagent la même date : UNE annonce. Plusieurs
 *   publications de plusieurs chapitres peuvent attendre en même temps : chacune a sa sortie
 *   groupée (méta META_GROUPES, tâche cron HOOK_GROUPE par horodatage), à côté de la sortie
 *   groupée unique historique de Service (META_GROUPE).
 *
 * @package Yume\Core
 */

namespace Yume\Core\Publication;

use Yume\Core\Import\Texte;

defined( 'ABSPATH' ) || exit;

/**
 * Calendrier sur mesure et répartition en publications.
 */
final class Calendrier {

	/** Méta du tome : sorties groupées du calendrier, horodatage => {ts, ids, ignores}. */
	public const META_GROUPES = '_yume_sorties_calendrier';

	/** Tâche cron d'une sortie groupée du calendrier (arguments : tome, horodatage). */
	public const HOOK_GROUPE = 'yume_publication_sortie_calendrier';

	/** Publications et unités au plus dans un calendrier. */
	public const MAX = 400;

	/** Poignée du script et de la feuille de style du panneau « Programmer les sorties ». */
	public const POIGNEE = 'yume-programmer-sorties';

	/** Action admin-post de la vue dédiée (envoi sans JavaScript). */
	public const ACTION = 'yume_programmer_sorties';

	/*
	 * -------------------------------------------------------------------------
	 * Répartition (sans WordPress)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Blocs insécables : chaque unité à 0 mot est collée à l'unité suivante (à la précédente si
	 * elle termine la liste ; une liste sans aucun mot forme un seul bloc).
	 *
	 * @param int[] $mots Mots de chaque unité.
	 * @return array<int,array{0:int,1:int}> [nombre d'unités, mots] par bloc.
	 */
	private static function blocs( array $mots ): array {
		$blocs   = array();
		$attente = 0;
		foreach ( array_values( $mots ) as $m ) {
			$m = max( 0, (int) $m );
			++$attente;
			if ( $m > 0 ) {
				$blocs[] = array( $attente, $m );
				$attente = 0;
			}
		}
		if ( $attente > 0 ) {
			if ( $blocs ) {
				$blocs[ count( $blocs ) - 1 ][0] += $attente;
			} else {
				$blocs[] = array( $attente, 0 );
			}
		}
		return $blocs;
	}

	/**
	 * Répartit des unités (dans l'ordre) en $n publications contiguës : la plus grosse
	 * publication est la plus petite possible (nombre de mots), puis les publications sont les
	 * plus régulières possible (somme des carrés minimale ; à égalité, la coupure la plus
	 * tardive). Jamais de coupure dans une unité ; une unité à 0 mot ne sort jamais seule.
	 * $n est ramené entre 1 et le nombre de blocs (unités, celles à 0 mot collées à la suivante).
	 *
	 * @param int[] $mots Mots de chaque unité.
	 * @param int   $n    Nombre de publications voulu.
	 * @return int[] Nombre d'unités de chaque publication (somme = nombre d'unités).
	 */
	public static function repartir( array $mots, int $n ): array {
		$blocs = self::blocs( $mots );
		$b     = count( $blocs );
		if ( 0 === $b ) {
			return array();
		}
		$k       = max( 1, min( $n, $b ) );
		$poids   = array_column( $blocs, 1 );
		$prefixe = array( 0 );
		foreach ( $poids as $i => $p ) {
			$prefixe[ $i + 1 ] = $prefixe[ $i ] + $p;
		}
		// 1. Plus petit maximum possible (recherche dichotomique, découpage glouton).
		$bas  = max( $poids );
		$haut = $prefixe[ $b ];
		while ( $bas < $haut ) {
			$milieu  = intdiv( $bas + $haut, 2 );
			$groupes = 1;
			$somme   = 0;
			foreach ( $poids as $p ) {
				if ( $somme + $p > $milieu ) {
					++$groupes;
					$somme = 0;
				}
				$somme += $p;
			}
			if ( $groupes <= $k ) {
				$haut = $milieu;
			} else {
				$bas = $milieu + 1;
			}
		}
		$max = $bas;
		// 2. Programmation dynamique : k groupes de au plus $max mots, somme des carrés minimale.
		$cout  = array( array( 0 => 0 ) );
		$choix = array();
		for ( $g = 1; $g <= $k; $g++ ) {
			$cout[ $g ]  = array();
			$choix[ $g ] = array();
			for ( $i = $g; $i <= $b - ( $k - $g ); $i++ ) {
				$meilleur = -1;
				$coupure  = -1;
				for ( $j = $i - 1; $j >= $g - 1; $j-- ) {
					$s = $prefixe[ $i ] - $prefixe[ $j ];
					if ( $s > $max ) {
						break;
					}
					if ( ! isset( $cout[ $g - 1 ][ $j ] ) ) {
						continue;
					}
					$c = $cout[ $g - 1 ][ $j ] + $s * $s;
					if ( $meilleur < 0 || $c < $meilleur ) {
						$meilleur = $c;
						$coupure  = $j;
					}
				}
				if ( $coupure >= 0 ) {
					$cout[ $g ][ $i ]  = $meilleur;
					$choix[ $g ][ $i ] = $coupure;
				}
			}
		}
		$bornes = array();
		$i      = $b;
		for ( $g = $k; $g >= 1; $g-- ) {
			$j        = $choix[ $g ][ $i ];
			$bornes[] = array( $j, $i );
			$i        = $j;
		}
		$tailles = array();
		foreach ( array_reverse( $bornes ) as $borne ) {
			$unites = 0;
			for ( $x = $borne[0]; $x < $borne[1]; $x++ ) {
				$unites += $blocs[ $x ][0];
			}
			$tailles[] = $unites;
		}
		return $tailles;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Unités d'un tome
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Chapitres à programmer d'un tome, dans l'ordre de lecture : brouillons (hors chapitres
	 * retirés et versions en attente), plus les chapitres programmés si demandé.
	 *
	 * @param int  $tome_id    Tome.
	 * @param bool $programmes Inclure les chapitres programmés (vue « Programmer les sorties »).
	 * @return \WP_Post[]
	 */
	public static function unites( int $tome_id, bool $programmes = false ): array {
		$statuts = $programmes ? array( 'draft', 'pending', 'future' ) : array( 'draft', 'pending' );
		return array_values(
			array_filter(
				$tome_id ? yume_get_chapitres( $tome_id, array( 'status' => $statuts ) ) : array(),
				static fn( \WP_Post $c ): bool => ! get_post_meta( $c->ID, Service::META_RETIRE, true ) && ! metadata_exists( 'post', $c->ID, Remplacement::META_DE )
			)
		);
	}

	/**
	 * Mots d'un chapitre (cache yume_nb_mots, sinon compté).
	 *
	 * @param \WP_Post $post Chapitre.
	 */
	public static function mots( \WP_Post $post ): int {
		if ( metadata_exists( 'post', $post->ID, 'yume_nb_mots' ) ) {
			return max( 0, (int) get_post_meta( $post->ID, 'yume_nb_mots', true ) );
		}
		return Texte::compter_mots( (string) $post->post_content );
	}

	/**
	 * Description d'une unité pour le panneau : clé « id:N », libellé, nature, mots, statut et
	 * date programmée (heure du site, AAAA-MM-JJTHH:MM, vide si brouillon).
	 *
	 * @param \WP_Post $post Chapitre.
	 * @return array<string,mixed>
	 */
	public static function unite( \WP_Post $post ): array {
		$natures    = yume_natures_chapitre();
		$nature     = (string) get_post_meta( $post->ID, 'yume_nature', true );
		$nature     = '' === $nature ? 'chapitre' : $nature;
		$sous_titre = trim( (string) get_post_meta( $post->ID, 'yume_sous_titre', true ) );
		$libelle    = yume_libelle_chapitre( (int) $post->ID );
		return array(
			'id'             => (int) $post->ID,
			'cle'            => 'id:' . (int) $post->ID,
			'libelle'        => $libelle . ( '' !== $sous_titre && ! str_contains( $libelle, $sous_titre ) ? ' — ' . $sous_titre : '' ),
			'nature'         => $nature,
			'nature_libelle' => (string) ( $natures[ $nature ] ?? ucfirst( $nature ) ),
			'mots'           => self::mots( $post ),
			'statut'         => (string) $post->post_status,
			'date'           => 'future' === $post->post_status ? substr( str_replace( ' ', 'T', (string) $post->post_date ), 0, 16 ) : '',
		);
	}

	/**
	 * Règle du rythme du tome pour le script : jour (1 = lundi … 7 = dimanche) et heure, ou null.
	 *
	 * @param int $tome_id Tome.
	 * @return array{jour:int,heure:string}|null
	 */
	public static function regle_rythme( int $tome_id ): ?array {
		$rythme = get_post_meta( $tome_id, 'yume_rythme', true );
		$rang   = is_array( $rythme ) ? array_search( (string) ( $rythme['jour'] ?? '' ), array_keys( yume_jours_semaine() ), true ) : false;
		if ( false === $rang || null === yume_prochaine_sortie_rythme( $tome_id ) ) {
			return null;
		}
		return array(
			'jour'  => (int) $rang + 1,
			'heure' => preg_match( '/^\d{2}:\d{2}$/', (string) ( $rythme['heure'] ?? '' ) ) ? (string) $rythme['heure'] : '18:00',
		);
	}

	/**
	 * Date pour un champ datetime-local (heure du site), vide pour « maintenant ».
	 *
	 * @param \DateTimeImmutable|null $date Date.
	 */
	public static function valeur_locale( ?\DateTimeImmutable $date ): string {
		return $date ? $date->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' ) : '';
	}

	/**
	 * Réglages du panneau « Programmer les sorties » (attribut data-yn-programmer-config) :
	 * unités, rythme, dates par défaut (une par unité au plus), maintenant, intervalle.
	 *
	 * @param int                 $tome_id    Tome (0 : aucun).
	 * @param bool                $programmes Chapitres programmés compris (vue dédiée).
	 * @param array<string,mixed> $args       depart (AAAA-MM-JJTHH:MM), intervalle.
	 * @return array<string,mixed>
	 */
	public static function config( int $tome_id, bool $programmes = false, array $args = array() ): array {
		$unites     = $tome_id ? array_map( array( self::class, 'unite' ), self::unites( $tome_id, $programmes ) ) : array();
		$intervalle = max( 1, min( 60, (int) ( $args['intervalle'] ?? Service::INTERVALLE_DEFAUT ) ) );
		$depart     = (string) ( $args['depart'] ?? '' );
		$date       = null;
		if ( '' !== $depart ) {
			$lue  = self::date( $depart );
			$date = is_array( $lue ) ? $lue[0] : null;
		}
		$dates = array();
		if ( $tome_id && $unites ) {
			foreach ( Service::calendrier( $tome_id, min( count( $unites ), self::MAX ), 'rythme', $date, $intervalle, ! $programmes ) as $d ) {
				$dates[] = self::valeur_locale( $d );
			}
		}
		return array(
			'tome'       => $tome_id,
			'unites'     => $unites,
			'rythme'     => $tome_id ? self::regle_rythme( $tome_id ) : null,
			'dates'      => $dates,
			'maintenant' => self::valeur_locale( new \DateTimeImmutable( 'now', wp_timezone() ) ),
			'intervalle' => $intervalle,
			'programmes' => $programmes,
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Calendrier reçu
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Erreur 400 du calendrier.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function erreur( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	/**
	 * Analyse le calendrier reçu (JSON ou tableau) : liste de publications {date, chapitres}.
	 * Rien n'est contrôlé contre le tome ici (voir dates()).
	 *
	 * @param mixed $valeur Valeur reçue.
	 * @return array<int,array{date:string,chapitres:string[]}>|\WP_Error Liste vide : aucun calendrier.
	 */
	public static function analyser( $valeur ) {
		$invalide = __( 'Calendrier des sorties illisible : rechargez la page et recommencez.', 'yume-core' );
		if ( is_string( $valeur ) ) {
			if ( '' === trim( $valeur ) ) {
				return array();
			}
			$valeur = strlen( $valeur ) <= Service::PLAN_OCTETS_MAX ? json_decode( $valeur, true, 6 ) : null;
			if ( ! is_array( $valeur ) ) {
				return self::erreur( 'yume_calendrier_invalide', $invalide );
			}
		}
		if ( null === $valeur || array() === $valeur ) {
			return array();
		}
		if ( ! is_array( $valeur ) || ! isset( $valeur['publications'] ) || ! is_array( $valeur['publications'] ) || ! array_is_list( $valeur['publications'] ) ) {
			return self::erreur( 'yume_calendrier_invalide', $invalide );
		}
		if ( count( $valeur['publications'] ) > self::MAX ) {
			/* translators: %d : nombre maximal de publications */
			return self::erreur( 'yume_calendrier_invalide', sprintf( __( 'Calendrier trop long : %d publications au plus.', 'yume-core' ), self::MAX ) );
		}
		$publications = array();
		foreach ( $valeur['publications'] as $publication ) {
			if ( ! is_array( $publication ) || ! isset( $publication['chapitres'] ) || ! is_array( $publication['chapitres'] ) || count( $publication['chapitres'] ) > self::MAX ) {
				return self::erreur( 'yume_calendrier_invalide', $invalide );
			}
			$date = $publication['date'] ?? '';
			if ( null === $date ) {
				$date = '';
			}
			if ( ! is_string( $date ) || strlen( $date ) > 40 ) {
				return self::erreur( 'yume_calendrier_invalide', $invalide );
			}
			$cles = array();
			foreach ( $publication['chapitres'] as $cle ) {
				if ( ! is_string( $cle ) || ! preg_match( '/^[a-z0-9:#._\-]{1,80}\z/', $cle ) ) {
					return self::erreur( 'yume_calendrier_invalide', $invalide );
				}
				$cles[] = $cle;
			}
			$publications[] = array(
				'date'      => sanitize_text_field( $date ),
				'chapitres' => $cles,
			);
		}
		return $publications;
	}

	/**
	 * Date d'une publication (heure du site si aucun fuseau) : [date ou null (maintenant),
	 * date passée ramenée à maintenant].
	 *
	 * @param string $valeur AAAA-MM-JJTHH:MM, vide ou « maintenant ».
	 * @return array{0:\DateTimeImmutable|null,1:bool}|\WP_Error
	 */
	public static function date( string $valeur ) {
		$valeur = trim( $valeur );
		if ( '' === $valeur || 'maintenant' === strtolower( $valeur ) ) {
			return array( null, false );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:Z|[+\-]\d{2}:?\d{2})?)?$/', $valeur ) ) {
			return self::erreur( 'yume_date_invalide', __( 'Date de sortie invalide (format attendu : AAAA-MM-JJTHH:MM).', 'yume-core' ) );
		}
		try {
			$date = new \DateTimeImmutable( $valeur, wp_timezone() );
		} catch ( \Exception $e ) {
			return self::erreur( 'yume_date_invalide', __( 'Date de sortie invalide (format attendu : AAAA-MM-JJTHH:MM).', 'yume-core' ) );
		}
		if ( $date->getTimestamp() <= time() + MINUTE_IN_SECONDS ) {
			return array( null, true );
		}
		return array( $date, false );
	}

	/**
	 * Dates de sortie de chaque chapitre à sortir (même ordre que $a_publier ; null :
	 * maintenant) d'après les publications reçues. Contrôles : chaque chapitre une seule fois,
	 * tous affectés, ordre des chapitres respecté, dates valides et croissantes.
	 *
	 * @param int                                              $tome_id      Tome.
	 * @param \WP_Post[]                                       $a_publier    Chapitres à sortir.
	 * @param array<int,array{date:string,chapitres:string[]}> $publications Publications (analyser()).
	 * @param string[]                                         $avert        Avertissements (modifié).
	 * @return array<int,\DateTimeImmutable|null>|\WP_Error
	 */
	public static function dates( int $tome_id, array $a_publier, array $publications, array &$avert ) {
		$ids     = array_map( static fn( \WP_Post $c ): int => (int) $c->ID, $a_publier );
		$permis  = array_flip( $ids );
		$par_cle = null;
		$ordre   = array();
		$par_id  = array();
		$rang    = 0;
		$avant   = 0;
		foreach ( $publications as $publication ) {
			if ( ! $publication['chapitres'] ) {
				continue;
			}
			++$rang;
			$lue = self::date( $publication['date'] );
			if ( is_wp_error( $lue ) ) {
				/* translators: 1: numéro de la publication, 2: message */
				return self::erreur( $lue->get_error_code(), sprintf( __( 'Publication %1$d : %2$s', 'yume-core' ), $rang, $lue->get_error_message() ) );
			}
			list( $date, $passee ) = $lue;
			if ( $passee ) {
				/* translators: %d : numéro de la publication */
				$avert[] = sprintf( __( 'Publication %d : la date choisie est passée, elle sort maintenant.', 'yume-core' ), $rang );
			}
			$ts = $date ? $date->getTimestamp() : 0;
			if ( $ts < $avant ) {
				/* translators: %d : numéro de la publication */
				return self::erreur( 'yume_calendrier_dates', sprintf( __( 'Publication %d : sa date précède celle de la publication d’avant. Les chapitres doivent sortir dans l’ordre de lecture.', 'yume-core' ), $rang ) );
			}
			$avant = $ts;
			foreach ( $publication['chapitres'] as $cle ) {
				$id = 0;
				if ( preg_match( '/^id:(\d{1,20})\z/', $cle, $m ) ) {
					$id = (int) $m[1];
				} else {
					if ( null === $par_cle ) {
						$par_cle = Service::chapitres_par_cle( $tome_id );
					}
					$id = isset( $par_cle[ $cle ] ) ? (int) $par_cle[ $cle ]->ID : 0;
				}
				if ( ! isset( $permis[ $id ] ) ) {
					/* translators: %s : clé du chapitre */
					return self::erreur( 'yume_calendrier_chapitre', sprintf( __( 'Calendrier : « %s » n’est pas un chapitre à sortir de ce tome. Rechargez la page.', 'yume-core' ), $cle ) );
				}
				if ( isset( $par_id[ $id ] ) ) {
					/* translators: %s : chapitre */
					return self::erreur( 'yume_calendrier_doublon', sprintf( __( 'Calendrier : « %s » figure dans deux publications.', 'yume-core' ), yume_libelle_chapitre( $id ) ) );
				}
				$par_id[ $id ] = $date;
				$ordre[]       = $id;
			}
		}
		if ( count( $ordre ) < count( $ids ) ) {
			$manquants = array();
			foreach ( $ids as $id ) {
				if ( ! array_key_exists( $id, $par_id ) ) {
					$manquants[] = yume_libelle_chapitre( $id );
				}
			}
			/* translators: %s : chapitres sans publication */
			return self::erreur( 'yume_calendrier_incomplet', sprintf( __( 'Calendrier incomplet : chapitre(s) à sortir sans publication : %s. Rechargez la page.', 'yume-core' ), implode( ', ', $manquants ) ) );
		}
		if ( $ordre !== $ids ) {
			return self::erreur( 'yume_calendrier_ordre', __( 'Calendrier : les publications doivent suivre l’ordre des chapitres (chapitres consécutifs).', 'yume-core' ) );
		}
		return array_map( static fn( int $id ) => $par_id[ $id ], $ids );
	}

	/**
	 * Dates d'une répartition en $n publications (repli sans JavaScript) : répartition par
	 * nombre de mots (repartir()), puis une date par publication comme une sortie au rythme
	 * (Service::calendrier()).
	 *
	 * @param int                     $tome_id          Tome.
	 * @param \WP_Post[]              $a_publier        Chapitres à sortir.
	 * @param int                     $n                Publications voulues.
	 * @param \DateTimeImmutable|null $depart           Date de départ (null : rythme, ou maintenant).
	 * @param int                     $intervalle       Jours entre deux publications sans rythme.
	 * @param bool                    $apres_programmes Au rythme : après le dernier chapitre déjà programmé.
	 * @return array<int,\DateTimeImmutable|null>
	 */
	public static function dates_repartition( int $tome_id, array $a_publier, int $n, ?\DateTimeImmutable $depart, int $intervalle, bool $apres_programmes = true ): array {
		$tailles = self::repartir( array_map( array( self::class, 'mots' ), $a_publier ), $n );
		$jours   = Service::calendrier( $tome_id, count( $tailles ), 'rythme', $depart, $intervalle, $apres_programmes );
		$dates   = array();
		foreach ( $tailles as $g => $taille ) {
			for ( $x = 0; $x < $taille; $x++ ) {
				$dates[] = $jours[ $g ] ?? null;
			}
		}
		return $dates;
	}

	/**
	 * Dates d'une sortie « calendrier » (Service::publier_chapitres()) : calendrier explicite
	 * (option calendrier), sinon répartition (option repartition).
	 *
	 * @param int                     $tome_id   Tome.
	 * @param \WP_Post[]              $a_publier Chapitres à sortir.
	 * @param array<string,mixed>     $options   calendrier, repartition, intervalle, inclure_programmes.
	 * @param \DateTimeImmutable|null $depart    Date de départ (repli sans JavaScript).
	 * @param string[]                $avert     Avertissements (modifié).
	 * @return array<int,\DateTimeImmutable|null>|\WP_Error
	 */
	public static function dates_sortie( int $tome_id, array $a_publier, array $options, ?\DateTimeImmutable $depart, array &$avert ) {
		$publications = self::analyser( $options['calendrier'] ?? null );
		if ( is_wp_error( $publications ) ) {
			return $publications;
		}
		if ( ! $a_publier ) {
			return array();
		}
		if ( $publications ) {
			return self::dates( $tome_id, $a_publier, $publications, $avert );
		}
		$n      = (int) ( $options['repartition'] ?? 0 );
		$depart = $depart ?? ( ( $options['depart'] ?? null ) instanceof \DateTimeImmutable ? $options['depart'] : null );
		if ( $n < 1 ) {
			return self::erreur( 'yume_calendrier_manquant', __( 'Calendrier sur mesure : indiquez le nombre de publications (ou répartissez les chapitres dans le panneau « Programmer les sorties »).', 'yume-core' ) );
		}
		return self::dates_repartition( $tome_id, $a_publier, $n, $depart, (int) ( $options['intervalle'] ?? Service::INTERVALLE_DEFAUT ), empty( $options['inclure_programmes'] ) );
	}

	/**
	 * Nombre de publications d'une liste de dates (même date = même publication).
	 *
	 * @param array<int,\DateTimeImmutable|null> $dates Dates.
	 */
	public static function nb_publications( array $dates ): int {
		$creneaux = array();
		foreach ( $dates as $d ) {
			$creneaux[ $d ? $d->getTimestamp() : 0 ] = true;
		}
		return count( $creneaux );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Sorties groupées du calendrier
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Sorties groupées du calendrier en attente d'un tome.
	 *
	 * @param int $tome_id Tome.
	 * @return array<string,array<string,mixed>>
	 */
	public static function groupes( int $tome_id ): array {
		$groupes = get_post_meta( $tome_id, self::META_GROUPES, true );
		return is_array( $groupes ) ? $groupes : array();
	}

	/**
	 * Programme UNE annonce pour plusieurs chapitres programmés à la même date (une publication
	 * du calendrier) : chapitres marqués pour que core n'émette rien à leur publication, tâche
	 * cron propre à cet horodatage.
	 *
	 * @param int   $tome_id Tome.
	 * @param int[] $ids     Chapitres de la publication.
	 * @param int   $ts      Horodatage de la sortie.
	 */
	public static function programmer_groupe( int $tome_id, array $ids, int $ts ): void {
		$ignores = array();
		foreach ( $ids as $id ) {
			if ( ! metadata_exists( 'post', $id, Service::META_NOTIFIE ) ) {
				update_post_meta( $id, Service::META_NOTIFIE, 'ignore' );
				$ignores[] = (int) $id;
			}
			update_post_meta( $id, Service::META_GROUPE_CHAPITRE, $ts );
		}
		$groupes                 = self::groupes( $tome_id );
		$groupes[ (string) $ts ] = array(
			'ts'      => $ts,
			'ids'     => array_values( array_map( 'intval', $ids ) ),
			'ignores' => $ignores,
		);
		update_post_meta( $tome_id, self::META_GROUPES, $groupes );
		wp_clear_scheduled_hook( self::HOOK_GROUPE, array( $tome_id, $ts ) );
		wp_schedule_single_event( $ts, self::HOOK_GROUPE, array( $tome_id, $ts ) );
	}

	/**
	 * Annule les sorties groupées du calendrier d'un tome (tâches supprimées, marques retirées
	 * des chapitres qui ne sont pas en ligne).
	 *
	 * @param int $tome_id Tome.
	 * @return bool Il y en avait.
	 */
	public static function annuler_groupes( int $tome_id ): bool {
		$groupes = self::groupes( $tome_id );
		foreach ( $groupes as $groupe ) {
			wp_clear_scheduled_hook( self::HOOK_GROUPE, array( $tome_id, (int) ( $groupe['ts'] ?? 0 ) ) );
			Service::liberer_chapitres( (array) $groupe );
		}
		delete_post_meta( $tome_id, self::META_GROUPES );
		return (bool) $groupes;
	}

	/**
	 * Tâche cron d'une publication du calendrier : publie ses chapitres arrivés à échéance, puis
	 * l'annonce une seule fois (Service::annoncer_groupe()).
	 *
	 * @param int $tome_id Tome.
	 * @param int $ts      Horodatage.
	 */
	public static function sortie_programmee( $tome_id, $ts ): void {
		$tome_id = (int) $tome_id;
		$ts      = (int) $ts;
		$groupes = self::groupes( $tome_id );
		$groupe  = $groupes[ (string) $ts ] ?? null;
		if ( ! is_array( $groupe ) ) {
			return; // Publication reprogrammée ou annulée.
		}
		unset( $groupes[ (string) $ts ] );
		if ( $groupes ) {
			update_post_meta( $tome_id, self::META_GROUPES, $groupes );
		} else {
			delete_post_meta( $tome_id, self::META_GROUPES );
		}
		foreach ( array_map( 'intval', (array) ( $groupe['ids'] ?? array() ) ) as $id ) {
			$post = get_post( $id );
			if ( $post && 'future' === $post->post_status && (int) get_post_meta( $id, Service::META_GROUPE_CHAPITRE, true ) === $ts ) {
				check_and_publish_future_post( $post );
				clean_post_cache( $id );
			}
		}
		$publies = Service::liberer_chapitres( $groupe );
		if ( $publies && 'publish' === get_post_status( $tome_id ) ) {
			Service::annoncer_groupe( $tome_id, $publies );
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Reprogrammation (vue « Programmer les sorties »)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Programme les brouillons et REPROGRAMME les chapitres déjà programmés d'un tome d'après un
	 * calendrier (ou une répartition) : sorties groupées annulées puis recréées (une annonce par
	 * publication), passage « complet » programmé déplacé à la sortie du dernier chapitre,
	 * journal du planning (« calendrier »).
	 *
	 * @param int                 $tome_id Tome.
	 * @param array<string,mixed> $options calendrier (JSON ou tableau), ou repartition (N) avec
	 *                                     depart (AAAA-MM-JJTHH:MM) et intervalle ; sans_annonce
	 *                                     (bool, défaut selon le tome).
	 * @return array<string,mixed>|\WP_Error Résultat de Service::publier() + message.
	 */
	public static function reprogrammer( int $tome_id, array $options ) {
		$tome = get_post( $tome_id );
		if ( ! $tome || 'yume_tome' !== $tome->post_type || in_array( $tome->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return new \WP_Error( 'yume_tome_introuvable', __( 'Tome introuvable.', 'yume-core' ), array( 'status' => 404 ) );
		}
		$unites = self::unites( $tome_id, true );
		if ( ! $unites ) {
			return new \WP_Error( 'yume_rien_a_programmer', __( 'Ce tome n’a aucun chapitre en brouillon ni programmé : rien à programmer. Déposez d’abord ses chapitres avec « Ajouter des chapitres ».', 'yume-core' ), array( 'status' => 409 ) );
		}
		$publications = self::analyser( $options['calendrier'] ?? null );
		if ( is_wp_error( $publications ) ) {
			return $publications;
		}
		$depart = null;
		if ( ! $publications ) {
			$lue = self::date( (string) ( $options['depart'] ?? '' ) );
			if ( is_wp_error( $lue ) ) {
				return $lue;
			}
			$depart = $lue[0];
		}
		// Contrôle avant tout changement : rien n'est annulé si le calendrier est refusé.
		$avert = array();
		$essai = $publications
			? self::dates( $tome_id, $unites, $publications, $avert )
			: ( (int) ( $options['repartition'] ?? 0 ) > 0 ? array() : self::erreur( 'yume_calendrier_manquant', __( 'Indiquez le nombre de publications, ou répartissez les chapitres.', 'yume-core' ) ) );
		if ( is_wp_error( $essai ) ) {
			return $essai;
		}
		$avant = array();
		foreach ( $unites as $c ) {
			$avant[ (int) $c->ID ] = 'future' === $c->post_status ? (string) $c->post_date_gmt : '';
		}
		// Les sorties groupées en attente sont recréées par la nouvelle programmation.
		Service::annuler_sortie_groupee( $tome_id );
		$sans_annonce = isset( $options['sans_annonce'] ) && null !== $options['sans_annonce'] ? (bool) $options['sans_annonce'] : Service::sans_annonce_par_defaut( $tome_id );
		$resultat     = Service::publier(
			$tome_id,
			'maintenant',
			array(
				'mode'               => Service::MODE_CHAPITRES,
				'sortie'             => 'calendrier',
				'calendrier'         => $publications ? array( 'publications' => $publications ) : null,
				'repartition'        => (int) ( $options['repartition'] ?? 0 ),
				'depart'             => $depart,
				'intervalle'         => (int) ( $options['intervalle'] ?? Service::INTERVALLE_DEFAUT ),
				'inclure_programmes' => true,
				'complet'            => false,
				'sans_annonce'       => $sans_annonce,
				'confirmer_vide'     => true,
			)
		);
		if ( is_wp_error( $resultat ) ) {
			return $resultat;
		}
		$reprogrammes = 0;
		$programmes   = 0;
		$fin          = '';
		$debut        = '';
		foreach ( $unites as $c ) {
			clean_post_cache( $c->ID );
			$apres = get_post( $c->ID );
			if ( ! $apres || 'future' !== $apres->post_status ) {
				continue;
			}
			++$programmes;
			if ( '' !== $avant[ (int) $c->ID ] && $avant[ (int) $c->ID ] !== $apres->post_date_gmt ) {
				++$reprogrammes;
			}
			$debut = '' === $debut || $apres->post_date_gmt < $debut ? (string) $apres->post_date_gmt : $debut;
			$fin   = $apres->post_date_gmt > $fin ? (string) $apres->post_date_gmt : $fin;
		}
		if ( function_exists( 'yume_journal_planning' ) ) {
			yume_journal_planning(
				$tome_id,
				get_current_user_id(),
				'calendrier',
				'',
				array(
					'publications' => (int) $resultat['publications'],
					'chapitres'    => count( $unites ),
					'programmes'   => $programmes,
					'reprogrammes' => $reprogrammes,
					'debut'        => $debut,
					'fin'          => $fin,
				)
			);
		}
		$resultat['avertissements'] = array_merge( $avert, (array) ( $resultat['avertissements'] ?? array() ) );
		$resultat['avertissements'] = array_values( array_unique( $resultat['avertissements'] ) );
		$resultat['reprogrammes']   = $reprogrammes;
		$resultat['message']        = Formulaire::message_chapitres( $resultat );
		$resultat['programmer']     = self::config( $tome_id, true );
		return $resultat;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Panneau et vue
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Enregistre le script et la feuille de style du panneau (formulaire et vue dédiée).
	 */
	public static function enregistrer_ressources(): void {
		$dossier = __DIR__ . '/blocks/publish-form/';
		$url     = plugins_url( 'blocks/publish-form/', __FILE__ );
		wp_register_script( self::POIGNEE, $url . 'programmer.js', array(), (string) filemtime( $dossier . 'programmer.js' ), true );
		wp_register_style( self::POIGNEE, $url . 'programmer.css', array(), (string) filemtime( $dossier . 'programmer.css' ) );
	}

	/**
	 * Adresse de la vue « Programmer les sorties » de l'espace équipe (?vue=programmer).
	 *
	 * @param int $tome_id Tome (0 : choix du tome).
	 */
	public static function url_vue( int $tome_id = 0 ): string {
		if ( function_exists( '\Yume\Core\Planning\url_vue_equipe' ) ) {
			return \Yume\Core\Planning\url_vue_equipe( 'programmer', array( 'tome' => $tome_id ) );
		}
		return '';
	}

	/**
	 * HTML du panneau « Programmer les sorties » (data-yn-programmer), rendu serveur complet :
	 * nombre de publications (champ repartition, utilisable sans JavaScript), résumé, liste des
	 * unités (remplacée par le tableau interactif du script), boutons, champ caché calendrier.
	 *
	 * @param array<string,mixed> $config Réglages (config()).
	 * @param array<string,mixed> $args   id (préfixe), cache (masqué au départ), aide (texte sans JavaScript).
	 */
	public static function panneau( array $config, array $args = array() ): string {
		$id     = sanitize_key( (string) ( $args['id'] ?? 'yn-programmer' ) );
		$unites = (array) $config['unites'];
		$nb     = count( $unites );
		$total  = array_sum( array_column( $unites, 'mots' ) );
		$html   = '<section class="yn-card yn-programmer" data-yn-programmer aria-labelledby="' . esc_attr( $id ) . '-titre" data-yn-programmer-config="' . esc_attr( (string) wp_json_encode( $config ) ) . '"' . ( ! empty( $args['cache'] ) ? ' hidden' : '' ) . '>';
		$html  .= '<h3 class="yn-programmer__titre" id="' . esc_attr( $id ) . '-titre">' . esc_html__( 'Programmer les sorties', 'yume-core' ) . '</h3>';
		$html  .= '<p class="yn-muted yn-programmer__intro">' . esc_html__( 'Choisissez le nombre de publications : les chapitres sont répartis par nombre de mots, sans jamais couper un chapitre. Ajustez ensuite les coupures (« Nouvelle publication ici ») et la date de chaque publication. Les chapitres d’une même publication sortent ensemble, avec une seule annonce.', 'yume-core' ) . '</p>';
		$html  .= '<div class="yn-programmer__tete">';
		$html  .= '<p class="yn-programmer__champ"><label class="yn-label" for="' . esc_attr( $id ) . '-n">' . esc_html__( 'Nombre de publications', 'yume-core' ) . '</label>';
		$html  .= '<input id="' . esc_attr( $id ) . '-n" type="number" name="repartition" min="1" max="' . esc_attr( (string) max( 1, $nb ) ) . '" step="1" inputmode="numeric" value="' . esc_attr( (string) max( 1, $nb ) ) . '" data-yn-programmer-n></p>';
		$html  .= '<p class="yn-programmer__champ"><button type="button" class="yn-btn" data-yn-programmer-action="repartir">' . esc_html__( 'Répartir', 'yume-core' ) . '</button></p>';
		$html  .= '<p class="yn-programmer__resume" role="status" aria-live="polite" data-yn-programmer-resume>';
		if ( $nb ) {
			$html .= esc_html(
				sprintf(
					/* translators: 1: nombre de chapitres, 2: nombre de mots */
					_n( '%1$s chapitre à sortir · %2$s mots', '%1$s chapitres à sortir · %2$s mots', $nb, 'yume-core' ),
					number_format_i18n( $nb ),
					number_format_i18n( (int) $total )
				)
			);
		} else {
			$html .= esc_html__( 'Aucun chapitre à sortir pour le moment.', 'yume-core' );
		}
		$html .= '</p></div>';
		$html .= '<div class="yn-programmer__liste" data-yn-programmer-liste>';
		if ( $unites ) {
			$html .= '<ol class="yn-programmer__unites">';
			foreach ( $unites as $u ) {
				$html .= '<li><span class="yn-chip">' . esc_html( (string) $u['nature_libelle'] ) . '</span> ' . esc_html( (string) $u['libelle'] ) . ' <span class="yn-muted">· ' . esc_html(
					/* translators: %s : nombre de mots */
					sprintf( _n( '%s mot', '%s mots', (int) $u['mots'], 'yume-core' ), number_format_i18n( (int) $u['mots'] ) )
				) . ( '' !== (string) ( $u['date'] ?? '' ) ? ' · ' . esc_html__( 'programmé', 'yume-core' ) : '' ) . '</span></li>';
			}
			$html .= '</ol>';
		}
		$html .= '</div>';
		if ( ! empty( $args['aide'] ) ) {
			$html .= '<p class="yn-muted yn-programmer__sans-js" data-yn-programmer-sans-js>' . esc_html( (string) $args['aide'] ) . '</p>';
		}
		$html .= '<p class="yn-programmer__boutons" data-yn-programmer-boutons hidden>';
		$html .= '<button type="button" class="yn-btn yn-btn--sm" data-yn-programmer-action="recalculer">' . esc_html__( 'Recalculer les dates', 'yume-core' ) . '</button>';
		$html .= '<button type="button" class="yn-btn yn-btn--sm" data-yn-programmer-action="une">' . esc_html__( 'Tout en une publication', 'yume-core' ) . '</button>';
		$html .= '<button type="button" class="yn-btn yn-btn--sm" data-yn-programmer-action="chacun">' . esc_html__( 'Une par chapitre', 'yume-core' ) . '</button>';
		$html .= '</p>';
		if ( ! empty( $args['lien_vue'] ) ) {
			$html .= '<p class="yn-programmer__lien"><a href="' . esc_url( (string) $args['lien_vue'] ) . '" data-yn-programmer-lien>' . esc_html__( 'Programmer plus tard dans la vue dédiée', 'yume-core' ) . '</a></p>';
		}
		$html .= '<input type="hidden" name="calendrier" value="" data-yn-programmer-calendrier>';
		return $html . '</section>';
	}
}
