<?php
/**
 * Contrôles de bout en bout de la migration sur une base LOCALE peuplée par seed-local.php
 * (utilisé par tools/migrate/bout-en-bout.sh).
 *
 * Usage :
 *   tools/localenv/wp.sh eval-file tools/migrate/verifier-local.php instantane <fichier.json>
 *   tools/localenv/wp.sh eval-file tools/migrate/verifier-local.php execution
 *   tools/localenv/wp.sh eval-file tools/migrate/verifier-local.php urls <nombre> <fichier.tsv>
 *   tools/localenv/wp.sh eval-file tools/migrate/verifier-local.php comparer <fichier.json>
 *
 * - instantane : écrit l'état des contenus touchés (tous les contenus, leurs champs et
 *   métadonnées, termes, relations, options de lecture et de Yume) ;
 * - execution  : vérifie les comptes (15 œuvres, 55 tomes, 81 chapitres…), les URL
 *   /oeuvres/… et /lire/… et la table des redirections ;
 * - urls       : écrit un échantillon d'anciennes URL et de leur cible (TSV) pour le test HTTP ;
 * - comparer   : compare la base à un instantané (après annulation).
 *
 * Sort avec le code 1 si un contrôle échoue.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

use Yume\Core\Migration\Migration_State;
use Yume\Core\Migration\Redirections;

$yume_v_args = array_values( (array) ( $args ?? array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
$yume_v_mode = (string) ( $yume_v_args[0] ?? '' );

/**
 * Affiche un message et, si c'est un échec, termine le script en erreur.
 *
 * @param bool   $ok      Contrôle réussi.
 * @param string $message Message.
 */
function yume_verifier( bool $ok, string $message ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		$ok ? WP_CLI::log( '  ok  ' . $message ) : WP_CLI::error( 'ÉCHEC : ' . $message );
		return;
	}
	echo ( $ok ? '  ok  ' : 'ÉCHEC : ' ) . $message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	if ( ! $ok ) {
		exit( 1 );
	}
}

/**
 * Instantané des contenus et réglages que la migration peut toucher.
 *
 * @return array<string,mixed>
 */
function yume_verifier_instantane(): array {
	global $wpdb;
	$posts = array();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	foreach ( $wpdb->get_results( "SELECT ID, post_type, post_status, post_name, post_title, post_excerpt, post_content, post_date, post_date_gmt, post_modified, post_parent, menu_order, post_author FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) as $ligne ) {
		$ligne['post_content']       = md5( $ligne['post_content'] );
		$posts[ 'p' . $ligne['ID'] ] = $ligne;
	}
	$meta = array();
	foreach ( $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY post_id, meta_key, meta_value", ARRAY_A ) as $ligne ) {
		$meta[] = $ligne['post_id'] . '|' . $ligne['meta_key'] . '|' . md5( (string) $ligne['meta_value'] );
	}
	$termes    = $wpdb->get_results( "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id ORDER BY t.term_id", ARRAY_A );
	$relations = array();
	foreach ( $wpdb->get_results( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_A ) as $ligne ) {
		$relations[] = $ligne['object_id'] . '|' . $ligne['term_taxonomy_id'];
	}
	$options = array();
	foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts', 'default_category', 'yume_pages', 'yume_reglages', 'yume_redirections', 'sticky_posts', 'permalink_structure' ) as $option ) {
		$brut               = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$options[ $option ] = null === $brut ? '(absente)' : md5( (string) $brut );
	}
	// phpcs:enable
	return array(
		'posts'     => $posts,
		'meta'      => $meta,
		'termes'    => $termes,
		'relations' => $relations,
		'options'   => $options,
	);
}

switch ( $yume_v_mode ) {
	case 'instantane':
		$yume_v_fichier = (string) ( $yume_v_args[1] ?? '' );
		yume_verifier( '' !== $yume_v_fichier, 'fichier de l’instantané indiqué' );
		file_put_contents( $yume_v_fichier, wp_json_encode( yume_verifier_instantane() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_verifier( is_file( $yume_v_fichier ), 'instantané écrit : ' . $yume_v_fichier );
		break;

	case 'execution':
		$yume_v_etat = Migration_State::etat();
		yume_verifier( 'migre' === $yume_v_etat['statut'], 'statut « migré »' );
		$yume_v_attendu = array(
			'yume_oeuvre'   => array( 15, 0 ),
			'yume_tome'     => array( 53, 2 ),
			'yume_chapitre' => array( 63, 18 ),
		);
		foreach ( $yume_v_attendu as $yume_v_type => $yume_v_n ) {
			$yume_v_c = wp_count_posts( $yume_v_type );
			yume_verifier( array( (int) $yume_v_c->publish, (int) $yume_v_c->draft ) === $yume_v_n, sprintf( '%s : %d publiés, %d brouillons (attendu %d / %d)', $yume_v_type, $yume_v_c->publish, $yume_v_c->draft, $yume_v_n[0], $yume_v_n[1] ) );
		}
		$yume_v_comptes = $yume_v_etat['comptes'];
		yume_verifier( 181 === (int) ( $yume_v_comptes['articles_reclasses'] ?? 0 ), 'articles reclassés : ' . (int) ( $yume_v_comptes['articles_reclasses'] ?? 0 ) );
		yume_verifier( 90 === (int) ( $yume_v_comptes['pages_depubliees'] ?? 0 ), 'anciennes pages passées en brouillon : ' . (int) ( $yume_v_comptes['pages_depubliees'] ?? 0 ) );
		yume_verifier( 92 === count( Redirections::table() ), 'redirections 301 : ' . count( Redirections::table() ) );
		$yume_v_pages = (array) get_option( 'yume_pages', array() );
		yume_verifier( 9 === count( $yume_v_pages ), 'pages Yume (option yume_pages) : ' . implode( ', ', array_keys( $yume_v_pages ) ) );
		yume_verifier( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) ( $yume_v_pages['accueil'] ?? -1 ), 'page d’accueil statique' );
		foreach ( array( '/oeuvres/grimgar-of-fantasy-and-ash/', '/oeuvres/grimgar-of-fantasy-and-ash/tome-9/', '/oeuvres/secrets-of-the-silent-witch/arc-7/', '/lire/secrets-of-the-silent-witch/arc-1/1/', '/lire/secrets-of-the-silent-witch/arc-4/1/', '/lire/secrets-of-the-silent-witch/arc-7/8/', '/oeuvres/roshidere-manga/' ) as $yume_v_url ) {
			yume_verifier( url_to_postid( home_url( $yume_v_url ) ) > 0, 'URL résolue : ' . $yume_v_url );
		}
		break;

	case 'urls':
		$yume_v_n       = max( 1, (int) ( $yume_v_args[1] ?? 20 ) );
		$yume_v_fichier = (string) ( $yume_v_args[2] ?? '' );
		$yume_v_table   = Redirections::table();
		$yume_v_types   = array();
		foreach ( (array) ( Migration_State::plan()['redirections'] ?? array() ) as $yume_v_r ) {
			$yume_v_types[ Redirections::normaliser( $yume_v_r['source'] ) ] = $yume_v_r['type'];
		}
		// Échantillon réparti sur tous les types (œuvre, tome, chapitre, hub, catégorie).
		$yume_v_par_type = array();
		foreach ( $yume_v_table as $yume_v_source => $yume_v_cible ) {
			$yume_v_par_type[ $yume_v_types[ $yume_v_source ] ?? 'autre' ][] = array( $yume_v_source, $yume_v_cible );
		}
		$yume_v_lignes = array();
		$yume_v_pris   = 0;
		while ( $yume_v_pris < $yume_v_n && $yume_v_par_type ) {
			foreach ( array_keys( $yume_v_par_type ) as $yume_v_type ) {
				if ( $yume_v_pris >= $yume_v_n ) {
					break;
				}
				$yume_v_e = array_shift( $yume_v_par_type[ $yume_v_type ] );
				if ( null === $yume_v_e ) {
					unset( $yume_v_par_type[ $yume_v_type ] );
					continue;
				}
				$yume_v_lignes[] = $yume_v_e[0] . "\t" . Redirections::url( (string) $yume_v_e[1] );
				++$yume_v_pris;
			}
		}
		file_put_contents( $yume_v_fichier, implode( "\n", $yume_v_lignes ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_verifier( count( $yume_v_lignes ) === min( $yume_v_n, count( $yume_v_table ) ), count( $yume_v_lignes ) . ' anciennes URL retenues pour le test HTTP' );
		break;

	case 'comparer':
		$yume_v_fichier = (string) ( $yume_v_args[1] ?? '' );
		$yume_v_avant   = json_decode( (string) file_get_contents( $yume_v_fichier ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		yume_verifier( is_array( $yume_v_avant ), 'instantané relu : ' . $yume_v_fichier );
		$yume_v_apres = json_decode( (string) wp_json_encode( yume_verifier_instantane() ), true );
		$yume_v_diff  = array();
		foreach ( $yume_v_avant as $yume_v_partie => $yume_v_valeurs ) {
			$yume_v_a = array_map( 'wp_json_encode', (array) $yume_v_valeurs );
			$yume_v_b = array_map( 'wp_json_encode', (array) ( $yume_v_apres[ $yume_v_partie ] ?? array() ) );
			if ( array_is_list( $yume_v_a ) ) {
				foreach ( array_diff( $yume_v_a, $yume_v_b ) as $yume_v_x ) {
					$yume_v_diff[] = "$yume_v_partie : disparu $yume_v_x";
				}
				foreach ( array_diff( $yume_v_b, $yume_v_a ) as $yume_v_x ) {
					$yume_v_diff[] = "$yume_v_partie : en trop $yume_v_x";
				}
				continue;
			}
			foreach ( array_diff_assoc( $yume_v_a, $yume_v_b ) as $yume_v_cle => $yume_v_x ) {
				$yume_v_diff[] = "$yume_v_partie/$yume_v_cle : $yume_v_x → " . ( $yume_v_b[ $yume_v_cle ] ?? '(absent)' );
			}
			foreach ( array_diff_key( $yume_v_b, $yume_v_a ) as $yume_v_cle => $yume_v_x ) {
				$yume_v_diff[] = "$yume_v_partie/$yume_v_cle : en trop $yume_v_x";
			}
		}
		$yume_v_etat = Migration_State::etat();
		yume_verifier( 'annule' === $yume_v_etat['statut'], 'statut « annulé »' );
		yume_verifier( array() === (array) ( $yume_v_etat['controle']['differences'] ?? array( 'non vérifié' ) ), 'contrôle par empreinte de l’annulation' );
		yume_verifier( array() === $yume_v_diff, 'base identique à l’état d’origine' . ( $yume_v_diff ? " :\n" . implode( "\n", array_slice( $yume_v_diff, 0, 20 ) ) : sprintf( ' (%d contenus, %d métadonnées, %d termes, %d relations)', count( $yume_v_avant['posts'] ), count( $yume_v_avant['meta'] ), count( $yume_v_avant['termes'] ), count( $yume_v_avant['relations'] ) ) ) );
		break;

	default:
		yume_verifier( false, 'mode inconnu (instantane, execution, urls, comparer)' );
}
