<?php
/**
 * Tests de la parution d'un tome (à paraître, en cours, complet ; « planifie » choisi par l'équipe,
 * liens masqués, passage automatique à « en cours ») et du rythme de sortie.
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crée une œuvre et un tome.
 *
 * @param array $tome Arguments du tome (post_status, meta_input…).
 * @param array $oeuvre_meta Métadonnées de l'œuvre.
 * @return array{0:int,1:int} Œuvre, tome.
 */
function yume_tpar_tome( array $tome = array(), array $oeuvre_meta = array() ): array {
	$oeuvre_id = yume_factory_post(
		array(
			'post_type'  => 'yume_oeuvre',
			'post_title' => 'Œuvre parution',
			'meta_input' => $oeuvre_meta,
		)
	);
	$tome_id   = yume_factory_post(
		array_replace_recursive(
			array(
				'post_type'   => 'yume_tome',
				'post_title'  => 'Œuvre parution — Tome 2',
				'post_status' => 'publish',
				'meta_input'  => array(
					'yume_oeuvre_id' => $oeuvre_id,
					'yume_numero'    => 2,
					'yume_nature'    => 'tome',
				),
			),
			$tome
		)
	);
	return array( $oeuvre_id, $tome_id );
}

/**
 * Crée un chapitre d'un tome.
 *
 * @param int    $tome_id Tome.
 * @param int    $numero  Numéro.
 * @param string $statut  Statut.
 */
function yume_tpar_chapitre( int $tome_id, int $numero, string $statut = 'publish' ): int {
	return yume_factory_post(
		array(
			'post_type'   => 'yume_chapitre',
			'post_title'  => 'Chapitre ' . $numero,
			'post_status' => $statut,
			'post_date'   => 'future' === $statut ? wp_date( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) : current_time( 'mysql' ),
			'menu_order'  => $numero,
			'meta_input'  => array(
				'yume_tome_id' => $tome_id,
				'yume_numero'  => $numero,
				'yume_nature'  => 'chapitre',
			),
		)
	);
}

yume_test(
	'parution : à paraître tant que le tome n’est pas en ligne, complet ou en cours selon la méta',
	static function () {
		list( , $brouillon ) = yume_tpar_tome( array( 'post_status' => 'draft' ) );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $brouillon ), 'tome vide en brouillon' );
		update_post_meta( $brouillon, 'yume_parution', 'en_cours' );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $brouillon ), 'en cours mais pas encore en ligne' );

		list( , $tome ) = yume_tpar_tome();
		yume_tpar_chapitre( $tome, 1 );
		yume_assert_same( 'complet', yume_parution_tome( $tome ), 'tome en ligne antérieur (méta vide) : complet' );
		update_post_meta( $tome, 'yume_parution', 'en_cours' );
		yume_assert_same( 'en_cours', yume_parution_tome( $tome ), 'méta en_cours' );
		update_post_meta( $tome, 'yume_parution', 'complet' );
		yume_assert_same( 'complet', yume_parution_tome( $tome ), 'méta complet' );
		update_post_meta( $tome, 'yume_parution', 'n’importe quoi' );
		yume_assert_same( '', (string) get_post_meta( $tome, 'yume_parution', true ), 'valeur inconnue refusée' );
		yume_assert_same( array( 'a_paraitre', 'en_cours', 'complet' ), array_keys( yume_parutions() ), 'libellés' );
		yume_assert_same(
			array(
				'a_paraitre' => 'À paraître',
				'en_cours'   => 'En cours',
				'complet'    => 'Publié',
			),
			yume_parutions(),
			'libellés des lecteurs'
		);
	}
);

yume_test(
	'parution : « planifie » (état « Planifié » choisi par l’équipe) = à paraître ; liens PDF et EPUB masqués aux lecteurs tant que le tome n’est pas « Publié »',
	static function () {
		list( , $tome ) = yume_tpar_tome(
			array(
				'meta_input' => array(
					'yume_lien_pdf'  => 'https://exemple.test/t2.pdf',
					'yume_lien_epub' => 'https://exemple.test/t2.epub',
				),
			)
		);
		$chapitre       = yume_tpar_chapitre( $tome, 1 );
		yume_assert_same( 'https://exemple.test/t2.pdf', yume_liens_telechargement( $tome )['pdf'], 'méta vide (tome antérieur) : liens montrés' );
		update_post_meta( $tome, 'yume_parution', 'planifie' );
		yume_assert_same( 'planifie', get_post_meta( $tome, 'yume_parution', true ), 'valeur acceptée' );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $tome ), 'tome en ligne mais « Planifié »' );
		$vides = array(
			'pdf'  => '',
			'epub' => '',
		);
		yume_assert_same( $vides, yume_liens_telechargement( $tome ), 'planifie : liens masqués' );
		yume_assert_same( $vides, yume_liens_telechargement( $chapitre ), 'liens du tome d’un chapitre masqués aussi' );
		update_post_meta( $tome, 'yume_parution', 'en_cours' );
		yume_assert_same( $vides, yume_liens_telechargement( $tome ), 'en_cours : liens masqués' );
		yume_assert_true( yume_liens_tome_masques( $tome ) );
		update_post_meta( $tome, 'yume_parution', 'complet' );
		yume_assert_same( 'https://exemple.test/t2.epub', yume_liens_telechargement( $tome )['epub'], 'complet : liens montrés' );
		yume_assert_false( yume_liens_tome_masques( $tome ) );

		list( , $brouillon ) = yume_tpar_tome( array( 'post_status' => 'draft' ) );
		update_post_meta( $brouillon, 'yume_parution', 'planifie' );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $brouillon ) );
		yume_assert_same( null, yume_parution_manuelle( $brouillon ), 'aucun choix manuel noté' );
	}
);

yume_test(
	'parution : un chapitre publié dans un tome « Planifié » en ligne le fait passer « En cours » (choix manuel remplacé, journalisé) ; un tome « Planifié » publié reprend la règle historique',
	static function () {
		global $wpdb;
		$editeur        = yume_factory_user( 'yume_editeur' );
		list( , $tome ) = yume_tpar_tome();
		yume_tpar_chapitre( $tome, 1 );
		$suivant = yume_tpar_chapitre( $tome, 2, 'draft' );
		\Yume\Core\Planning\noter_parution_manuelle( $tome, 'a_paraitre', $editeur );
		yume_assert_same( 'planifie', yume_parution_manuelle( $tome )['etat'] ?? '', 'choix manuel noté' );
		yume_assert_same( $editeur, yume_parution_manuelle( $tome )['par'] ?? 0 );
		yume_assert_same( 'a_paraitre', yume_parution_tome( $tome ) );

		add_filter( 'yume_core_notifier', '__return_false' );
		wp_update_post(
			array(
				'ID'          => $suivant,
				'post_status' => 'publish',
			)
		);
		remove_filter( 'yume_core_notifier', '__return_false' );
		yume_assert_same( 'en_cours', get_post_meta( $tome, 'yume_parution', true ), 'chapitre publié : « En cours de publication »' );
		yume_assert_same( 'en_cours', yume_parution_tome( $tome ) );
		yume_assert_same( null, yume_parution_manuelle( $tome ), 'marque du choix manuel effacée' );
		$table = \Yume\Core\Planning\table_journal();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ligne = $wpdb->get_row( $wpdb->prepare( "SELECT ancien, nouveau, public FROM {$table} WHERE tome_id = %d AND champ = 'parution' ORDER BY id DESC LIMIT 1", $tome ) );
		yume_assert_same( 'a_paraitre', $ligne->ancien ?? '', 'journal : ancien état' );
		yume_assert_contains( '"auto":true', (string) ( $ligne->nouveau ?? '' ), 'journal : changement automatique' );
		yume_assert_same( '0', (string) ( $ligne->public ?? '' ), 'journal de l’équipe seulement' );

		// Tome « Planifié » publié hors du formulaire de publication : règle historique (méta vide).
		list( , $planifie ) = yume_tpar_tome( array( 'post_status' => 'draft' ) );
		update_post_meta( $planifie, 'yume_parution', 'planifie' );
		add_filter( 'yume_core_notifier', '__return_false' );
		wp_update_post(
			array(
				'ID'          => $planifie,
				'post_status' => 'publish',
			)
		);
		remove_filter( 'yume_core_notifier', '__return_false' );
		yume_assert_false( metadata_exists( 'post', $planifie, 'yume_parution' ), 'méta vide' );
		yume_assert_same( 'complet', yume_parution_tome( $planifie ), 'règle historique : tome de light novel complet' );
	}
);

yume_test(
	'parution : règle historique des arcs (chapitres programmés ou étape non publiée = en cours)',
	static function () {
		list( , $arc ) = yume_tpar_tome( array( 'meta_input' => array( 'yume_nature' => 'arc' ) ) );
		yume_tpar_chapitre( $arc, 1 );
		yume_assert_same( 'complet', yume_parution_tome( $arc ), 'arc sans chapitre à venir' );
		yume_tpar_chapitre( $arc, 2, 'future' );
		yume_assert_same( 'en_cours', yume_parution_tome( $arc ), 'arc avec un chapitre programmé' );

		list( , $arc2 ) = yume_tpar_tome( array( 'meta_input' => array( 'yume_nature' => 'arc' ) ) );
		yume_tpar_chapitre( $arc2, 1 );
		// Étape posée après la publication du chapitre (le planning la recalcule à la sortie).
		update_post_meta( $arc2, 'yume_etape', 'traduction' );
		yume_assert_same( 'en_cours', yume_parution_tome( $arc2 ), 'étape traduction' );

		list( , $tome ) = yume_tpar_tome();
		yume_tpar_chapitre( $tome, 1 );
		yume_tpar_chapitre( $tome, 2, 'future' );
		update_post_meta( $tome, 'yume_etape', 'traduction' );
		yume_assert_same( 'complet', yume_parution_tome( $tome ), 'tome de light novel sans méta : complet (règle historique inchangée)' );
	}
);

yume_test(
	'rythme : méta assainie et prochaine date au jour et à l’heure dits',
	static function () {
		list( , $tome ) = yume_tpar_tome();
		update_post_meta( $tome, 'yume_rythme', array( 'jour' => 'mardi-gras' ) );
		yume_assert_same( '', get_post_meta( $tome, 'yume_rythme', true ), 'jour inconnu : libre' );
		yume_assert_same( null, yume_prochaine_sortie_rythme( $tome ), 'pas de rythme' );

		update_post_meta(
			$tome,
			'yume_rythme',
			array(
				'jour'  => 'samedi',
				'heure' => '25:99',
			)
		);
		yume_assert_same(
			array(
				'jour'  => 'samedi',
				'heure' => '18:00',
			),
			get_post_meta( $tome, 'yume_rythme', true ),
			'heure invalide : 18:00'
		);
		$tz       = wp_timezone();
		$mercredi = new \DateTimeImmutable( '2026-10-07 10:00', $tz );
		yume_assert_same( '2026-10-10 18:00', yume_prochaine_sortie_rythme( $tome, $mercredi )->format( 'Y-m-d H:i' ), 'samedi suivant' );
		$samedi_soir = new \DateTimeImmutable( '2026-10-10 18:00', $tz );
		yume_assert_same( '2026-10-17 18:00', yume_prochaine_sortie_rythme( $tome, $samedi_soir )->format( 'Y-m-d H:i' ), 'strictement après : semaine suivante' );
		$samedi_matin = new \DateTimeImmutable( '2026-10-10 09:00', $tz );
		yume_assert_same( '2026-10-10 18:00', yume_prochaine_sortie_rythme( $tome, $samedi_matin )->format( 'Y-m-d H:i' ), 'le jour même, plus tard' );

		update_post_meta( $tome, 'yume_chapitres_prevus', '12' );
		yume_assert_same( 12, (int) get_post_meta( $tome, 'yume_chapitres_prevus', true ), 'chapitres prévus' );
	}
);

yume_test(
	'états : libellés équipe du tome et de l’œuvre, œuvres sans rappels',
	static function () {
		yume_assert_same(
			array(
				'a_paraitre' => 'Planifié',
				'en_cours'   => 'En cours de publication',
				'complet'    => 'Publié',
			),
			yume_etats_tome()
		);
		$etats = yume_etats_oeuvre();
		yume_assert_same( 'En cours de publication', $etats['en-cours'] ?? '', 'en-cours' );
		yume_assert_same( 'Licenciée', $etats['licenciee'] ?? '', 'licenciee' );

		list( $oeuvre ) = yume_tpar_tome();
		yume_assert_false( yume_oeuvre_sans_rappels( $oeuvre ), 'sans état : rappels' );
		wp_set_object_terms( $oeuvre, array( 'en-cours' ), 'yume_statut' );
		yume_assert_false( yume_oeuvre_sans_rappels( $oeuvre ), 'en cours : rappels' );
		foreach ( array( 'en-pause', 'abandonnee', 'licenciee', 'terminee' ) as $etat ) {
			wp_set_object_terms( $oeuvre, array( $etat ), 'yume_statut' );
			yume_assert_true( yume_oeuvre_sans_rappels( $oeuvre ), $etat . ' : pas de rappels' );
		}
		yume_assert_false( yume_oeuvre_sans_rappels( 0 ), 'aucune œuvre' );
	}
);
