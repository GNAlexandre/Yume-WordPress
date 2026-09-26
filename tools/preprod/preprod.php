<?php
/**
 * Étapes de construction de la préproduction locale (voir tools/preprod/README.md), appelées
 * une par une par tools/preprod/construire.sh, chacune dans son propre processus WP-CLI :
 *
 * Commande : tools/localenv/wp.sh eval-file tools/preprod/preprod.php <étape> [arguments].
 *
 * Étapes :
 *   reglages              réglages Yume (webhooks Discord vides, e-mails aux lecteurs)
 *   medias                couvertures et bannières de substitution redessinées (même ID)
 *   comptes               un compte par rôle de l'équipe et deux lecteurs (mot de passe connu)
 *   complements           équipe, source, genres et liens de quelques œuvres (saisie de l'équipe)
 *   grimgar <docx>        publication réelle du tome 7 de Grimgar par le service de publication
 *   planning              planning de démonstration (historique daté, retards, blocage, rappels)
 *   lecteurs              favoris, notes, alertes, progression, réglages et commentaires
 *   bilan                 comptes et adresses utiles
 *
 * DESTINÉ À UNE BASE LOCALE : refusé hors environnement local (même contrôle que le seed).
 *
 * @package Yume\Core
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/class-yume-preprod-images.php';
require_once dirname( __DIR__ ) . '/migrate/lib/class-yume-seed-local.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables d'un script WP-CLI.

/** Mot de passe commun des comptes de la préproduction (local uniquement). */
define( 'YUME_PREPROD_MDP', getenv( 'YUME_PREPROD_MDP' ) ? (string) getenv( 'YUME_PREPROD_MDP' ) : 'preprod' );

/**
 * Comptes : identifiant => [nom affiché, rôle, description].
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function yume_preprod_comptes(): array {
	return array(
		'angeloids' => array( 'Angeloids', 'yume_gerant', 'Gérant' ),
		'jojogg'    => array( 'JojoGg', 'yume_editeur', 'Éditeur Yume' ),
		'calumi'    => array( 'Calumi', 'yume_traducteur', 'Traducteur' ),
		'mael7523m' => array( 'Mael7523m', 'yume_relecteur', 'Relecteur' ),
		'cerale'    => array( 'Cerale', 'yume_graphiste', 'Graphiste' ),
		'kaede'     => array( 'Kaede', 'subscriber', 'Lectrice' ),
		'yuzu'      => array( 'Yuzu', 'subscriber', 'Lecteur' ),
	);
}

/**
 * Affiche une ligne (WP-CLI ou sortie standard).
 *
 * @param string $message Message.
 */
function yume_preprod_log( string $message ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $message );
	} else {
		echo $message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

/**
 * Arrête le script en erreur.
 *
 * @param string $message Message.
 */
function yume_preprod_erreur( string $message ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::error( $message );
	}
	fwrite( STDERR, $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

/**
 * ID d'un compte de la préproduction.
 *
 * @param string $login Identifiant.
 */
function yume_preprod_user( string $login ): int {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		yume_preprod_erreur( "Compte « $login » absent : lancer d'abord l'étape comptes." );
	}
	return (int) $user->ID;
}

/**
 * Œuvre migrée par son slug.
 *
 * @param string $slug Slug.
 */
function yume_preprod_oeuvre( string $slug ): int {
	$posts = get_posts(
		array(
			'post_type'      => 'yume_oeuvre',
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! $posts ) {
		yume_preprod_erreur( "Œuvre « $slug » absente : la migration a-t-elle été exécutée ?" );
	}
	return (int) $posts[0];
}

/**
 * Tome d'une œuvre par sa nature et son numéro (0 si absent).
 *
 * @param int    $oeuvre_id Œuvre.
 * @param string $nature    Nature.
 * @param float  $numero    Numéro.
 */
function yume_preprod_tome( int $oeuvre_id, string $nature, float $numero ): int {
	foreach ( yume_get_tomes( $oeuvre_id, array( 'status' => 'any' ) ) as $tome ) {
		$n = (string) get_post_meta( $tome->ID, 'yume_nature', true );
		if ( ( '' === $n ? 'tome' : $n ) === $nature && abs( (float) get_post_meta( $tome->ID, 'yume_numero', true ) - $numero ) < 0.001 ) {
			return (int) $tome->ID;
		}
	}
	return 0;
}

/**
 * Exécute $code comme si l'on était $heures heures dans le passé (horodatage du planning :
 * journal, dernière mise à jour), pour un historique réaliste.
 *
 * @param float    $heures Heures dans le passé.
 * @param callable $code   Code.
 * @return mixed
 */
function yume_preprod_il_y_a( float $heures, callable $code ) {
	$ts     = time() - (int) round( $heures * HOUR_IN_SECONDS );
	$filtre = static fn() => $ts;
	add_filter( 'yume_planning_maintenant', $filtre );
	try {
		return $code();
	} finally {
		remove_filter( 'yume_planning_maintenant', $filtre );
	}
}

/**
 * Vérifie un résultat (WP_Error → arrêt).
 *
 * @param mixed  $resultat Résultat.
 * @param string $contexte Contexte du message.
 * @return mixed
 */
function yume_preprod_ok( $resultat, string $contexte ) {
	if ( is_wp_error( $resultat ) ) {
		yume_preprod_erreur( $contexte . ' : ' . $resultat->get_error_message() );
	}
	return $resultat;
}

/*
 * -----------------------------------------------------------------------------
 * Étapes
 * -----------------------------------------------------------------------------
 */

/**
 * Réglages Yume : webhooks Discord vides (aucun appel sortant), e-mails aux lecteurs actifs.
 */
function yume_preprod_reglages(): void {
	$reglages = get_option( 'yume_reglages', array() );
	$reglages = is_array( $reglages ) ? $reglages : array();
	$reglages = array_merge(
		$reglages,
		array(
			'discord_webhook_sorties' => '',
			'discord_webhook_equipe'  => '',
			'emails_lecteurs'         => true,
		)
	);
	update_option( 'yume_reglages', $reglages );
	update_option( 'blogdescription', 'Fan-traductions françaises de light novels' );
	yume_preprod_log( sprintf( 'Réglages : webhooks Discord vides, inscriptions %s (rôle par défaut : %s).', get_option( 'users_can_register' ) ? 'ouvertes' : 'FERMÉES', get_option( 'default_role' ) ) );
}

/**
 * Couvertures, bannières d'œuvre et bannière du site : images de substitution redessinées.
 */
function yume_preprod_medias(): void {
	if ( ! Yume_Preprod_Images::disponible() ) {
		yume_preprod_log( 'GD indisponible : images du seed conservées.' );
		return;
	}
	$faites = array();
	$nb     = array(
		'couvertures' => 0,
		'bannieres'   => 0,
	);
	// Couvertures des tomes (libellé), puis des œuvres (sans libellé).
	foreach ( array( 'yume_tome', 'yume_oeuvre' ) as $type ) {
		$ids = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$image = (int) get_post_thumbnail_id( $id );
			if ( ! $image || isset( $faites[ $image ] ) || ! wp_attachment_is_image( $image ) ) {
				continue;
			}
			$oeuvre  = 'yume_tome' === $type ? yume_get_oeuvre_id( $id ) : $id;
			$libelle = 'yume_tome' === $type ? yume_libelle_tome( $id ) : '';
			if ( Yume_Preprod_Images::couverture( $image, html_entity_decode( get_the_title( $oeuvre ), ENT_QUOTES ), $libelle ) ) {
				$faites[ $image ] = true;
				++$nb['couvertures'];
			}
		}
	}
	// Bannières d'œuvre.
	foreach ( get_posts(
		array(
			'post_type'      => 'yume_oeuvre',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	) as $oeuvre ) {
		$image = (int) get_post_meta( $oeuvre, 'yume_banniere_id', true );
		if ( $image && ! isset( $faites[ $image ] ) && wp_attachment_is_image( $image ) ) {
			// Illustration de fond sans texte (fiche de l'œuvre, lecteur).
			if ( Yume_Preprod_Images::banniere( $image, '', '', 1920, html_entity_decode( get_the_title( $oeuvre ), ENT_QUOTES ) ) ) {
				$faites[ $image ] = true;
				++$nb['bannieres'];
			}
		}
	}
	// Bannière du site : celle de l'ancien site (réglée par la migration), sinon une nouvelle.
	$banniere = (int) yume_setting( 'banniere_id', 0 );
	$origine  = 'bannière actuelle du site (pièce jointe ' . $banniere . ')';
	if ( ! $banniere || ! wp_attachment_is_image( $banniere ) ) {
		$banniere                = Yume_Preprod_Images::nouvelle_piece( 'banniere-yume-novel.jpg', 'Bannière Yume Novel', 2400, 292 );
		$reglages                = (array) get_option( 'yume_reglages', array() );
		$reglages['banniere_id'] = $banniere;
		update_option( 'yume_reglages', $reglages );
		$origine = 'image générée (pièce jointe ' . $banniere . ')';
	}
	if ( $banniere && Yume_Preprod_Images::banniere( $banniere, 'Yume Novel', 'Un nouvel élan pour Yume' ) ) {
		update_post_meta( $banniere, '_wp_attachment_image_alt', 'Yume Novel — Un nouvel élan pour Yume' );
		++$nb['bannieres'];
	}
	yume_preprod_log( sprintf( 'Images redessinées : %d couvertures, %d bannières ; bannière du site : %s.', $nb['couvertures'], $nb['bannieres'], $origine ) );
}

/**
 * Comptes : un par rôle de l'équipe et deux lecteurs.
 */
function yume_preprod_comptes_creer(): void {
	foreach ( yume_preprod_comptes() as $login => $compte ) {
		list( $nom, $role, $description ) = $compte;
		$existant                         = get_user_by( 'login', $login );
		$donnees                          = array(
			'user_login'   => $login,
			'user_pass'    => YUME_PREPROD_MDP,
			'user_email'   => $login . '@yumenovel.test',
			'display_name' => $nom,
			'nickname'     => $nom,
			'role'         => $role,
			'description'  => $description,
			'locale'       => '',
		);
		if ( $existant ) {
			$donnees['ID'] = $existant->ID;
			$id            = wp_update_user( $donnees );
		} else {
			$donnees['user_registered'] = gmdate( 'Y-m-d H:i:s', time() - wp_rand( 40, 400 ) * DAY_IN_SECONDS );
			$id                         = wp_insert_user( $donnees );
		}
		yume_preprod_ok( $id, "Compte $login" );
		update_user_meta( (int) $id, 'show_admin_bar_front', 'subscriber' === $role ? 'false' : 'true' );
		yume_preprod_log( sprintf( '  %-10s %-16s %s', $login, $role, $nom ) );
	}
}

/**
 * Compléments éditoriaux (données absentes de l'ancien site, saisies par l'équipe après la
 * migration) : équipe, source de la traduction, genres et liens de quelques œuvres.
 */
function yume_preprod_complements(): void {
	$genres = array(
		'grimgar-of-fantasy-and-ash'                 => array( 'Fantasy', 'Isekai', 'Drame' ),
		'secrets-of-the-silent-witch'                => array( 'Fantasy', 'Magie', 'Comédie' ),
		'raven-of-the-inner-palace'                  => array( 'Historique', 'Mystère', 'Fantasy' ),
		'survival-in-another-world-with-my-mistress' => array( 'Isekai', 'Aventure', 'Romance' ),
		'roshidere'                                  => array( 'Comédie romantique', 'Vie scolaire' ),
		'otonari-no-tenshi-sama'                     => array( 'Romance', 'Vie scolaire' ),
		'sukasuka'                                   => array( 'Fantasy', 'Drame' ),
	);
	foreach ( $genres as $slug => $noms ) {
		wp_set_object_terms( yume_preprod_oeuvre( $slug ), $noms, 'yume_genre', false );
	}
	$grimgar = yume_preprod_oeuvre( 'grimgar-of-fantasy-and-ash' );
	update_post_meta(
		$grimgar,
		'yume_equipe',
		array(
			'traduction' => 'Calumi',
			'relecture'  => 'Angeloids',
			'edition'    => 'JojoGg',
		)
	);
	update_post_meta( $grimgar, 'yume_source_traduction', 'Édition anglaise officielle (J-Novel Club)' );
	update_post_meta(
		$grimgar,
		'yume_liens',
		array(
			array(
				'label' => 'Fiche Novel-Index',
				'url'   => 'https://www.novel-index.com/',
			),
			array(
				'label' => 'Éditeur OVERLAP (VO)',
				'url'   => 'https://over-lap.co.jp/',
			),
			array(
				'label' => 'Fil Discord de l’œuvre',
				'url'   => (string) yume_setting( 'discord_invite', 'https://discord.gg/SMBZqhgUv8' ),
			),
		)
	);
	update_post_meta( $grimgar, 'yume_jours_sortie', array( 'dimanche' ) );
	$sw = yume_preprod_oeuvre( 'secrets-of-the-silent-witch' );
	update_post_meta( $sw, 'yume_source_traduction', 'Web novel original (Kakuyomu), traduit du japonais' );
	update_post_meta( $sw, 'yume_jours_sortie', array( 'mercredi', 'samedi' ) );
	yume_preprod_log( sprintf( 'Compléments : genres de %d œuvres, équipe et liens de Grimgar, rythme de Silent Witch.', count( $genres ) ) );
}

/**
 * Publication réelle du tome 7 de Grimgar (DOCX de référence) par le service de publication,
 * en tant qu'éditeur : le tome migré (PDF/EPUB seuls, sans chapitre) est réutilisé.
 *
 * @param string $docx Chemin du DOCX.
 */
function yume_preprod_grimgar( string $docx ): void {
	if ( ! is_readable( $docx ) ) {
		yume_preprod_log( "DOCX introuvable ($docx) : publication du tome 7 ignorée." );
		return;
	}
	$oeuvre = yume_preprod_oeuvre( 'grimgar-of-fantasy-and-ash' );
	$tome   = yume_preprod_tome( $oeuvre, 'tome', 7 );
	if ( ! $tome ) {
		yume_preprod_erreur( 'Tome 7 de Grimgar absent après migration.' );
	}
	wp_set_current_user( yume_preprod_user( 'jojogg' ) );
	$tmp = wp_tempnam( 'grimgar-t7' );
	copy( $docx, $tmp );
	// Fichier local accepté comme téléversé ; limite de téléversement du serveur de production
	// (au moins 30 Mo, voir tools/docx2chapters/README.md) au lieu de celle de PHP en ligne de commande.
	$limite = static fn(): int => 128 * MB_IN_BYTES;
	add_filter( 'yume_publication_fichier_local', '__return_true' );
	add_filter( 'upload_size_limit', $limite );
	$rapport = \Yume\Core\Publication\Service::preparer(
		array(
			'oeuvre_id' => $oeuvre,
			'nature'    => 'tome',
			'numero'    => '7',
			'lien_pdf'  => (string) get_post_meta( $tome, 'yume_lien_pdf', true ),
			'lien_epub' => (string) get_post_meta( $tome, 'yume_lien_epub', true ),
			'credits'   => array(
				'traduction' => 'Calumi',
				'relecture'  => 'Angeloids',
				'edition'    => 'JojoGg',
			),
		),
		array(
			'source' => array(
				'name'     => 'grimgar-t7.docx',
				'type'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => (int) filesize( $tmp ),
			),
		)
	);
	remove_filter( 'yume_publication_fichier_local', '__return_true' );
	remove_filter( 'upload_size_limit', $limite );
	yume_preprod_ok( $rapport, 'Préparation du tome 7' );
	if ( (int) $rapport['tome']['id'] !== $tome ) {
		yume_preprod_erreur( sprintf( 'Le tome migré (%d) n’a pas été réutilisé (tome %d).', $tome, (int) $rapport['tome']['id'] ) );
	}
	$sortie = yume_preprod_ok( \Yume\Core\Publication\Service::publier( $tome, 'maintenant' ), 'Publication du tome 7' );
	yume_preprod_log(
		sprintf(
			'Grimgar tome 7 (ID %d, réutilisé) : %d chapitres publiés, %d avertissement(s), annonce %s.',
			$tome,
			(int) $sortie['chapitres'],
			count( (array) ( $rapport['avertissements'] ?? array() ) ),
			$sortie['article'] ? '« ' . $sortie['article']['titre'] . ' »' : 'absente'
		)
	);
}

/**
 * Planning de démonstration : tomes en cours, historique daté, retards, un blocage, rappels.
 */
function yume_preprod_planning(): void {
	$p = '\\Yume\\Core\\Planning\\';
	if ( ! function_exists( $p . 'mettre_a_jour' ) ) {
		yume_preprod_erreur( 'Module planning absent.' );
	}
	$angeloids  = yume_preprod_user( 'angeloids' );
	$jojogg     = yume_preprod_user( 'jojogg' );
	$calumi     = yume_preprod_user( 'calumi' );
	$mael       = yume_preprod_user( 'mael7523m' );
	$cerale     = yume_preprod_user( 'cerale' );
	$maj        = $p . 'mettre_a_jour';
	$ajouter    = $p . 'ajouter_tome';
	$aujourdhui = new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Paris' ) );
	$jour       = static fn( int $n ): string => $aujourdhui->modify( ( $n >= 0 ? '+' : '' ) . $n . ' days' )->format( 'Y-m-d' );
	$dimanche   = $aujourdhui->modify( 'next sunday' )->format( 'Y-m-d' );

	// Grimgar tome 10 : ajouté par le gérant, traduction terminée, relecture à 62 %, à l'heure.
	$grimgar = yume_preprod_oeuvre( 'grimgar-of-fantasy-and-ash' );
	$t10     = yume_preprod_tome( $grimgar, 'tome', 10 );
	if ( ! $t10 ) {
		$t10 = (int) yume_preprod_ok(
			yume_preprod_il_y_a(
				14 * 24,
				static fn() => $ajouter(
					array(
						'oeuvre_id'    => $grimgar,
						'nature'       => 'tome',
						'numero'       => '10',
						'responsables' => array(
							'traduction' => $calumi,
							'relecture'  => $angeloids,
							'edition'    => $jojogg,
						),
						'date_cible'   => $dimanche,
						'etape'        => 'traduction',
					),
					$angeloids
				)
			),
			'Grimgar tome 10'
		);
	}
	$etapes_t10 = array(
		array( 11 * 24, $calumi, array( 'avancement' => array( 'traduction' => 35 ) ) ),
		array( 8 * 24, $calumi, array( 'avancement' => array( 'traduction' => 70 ) ) ),
		array(
			5 * 24,
			$calumi,
			array(
				'avancement' => array( 'traduction' => 100 ),
				'etape'      => 'relecture',
			),
		),
		array( 3 * 24, $angeloids, array( 'avancement' => array( 'relecture' => 30 ) ) ),
		array(
			5,
			$angeloids,
			array(
				'avancement'  => array( 'relecture' => 62 ),
				'note_equipe' => 'Chapitres 1 à 12 relus ; reste la postface et le glossaire.',
			),
		),
	);
	foreach ( $etapes_t10 as $e ) {
		yume_preprod_ok( yume_preprod_il_y_a( $e[0], static fn() => $maj( $t10, $e[2], $e[1] ) ), 'Grimgar tome 10' );
	}

	// Silent Witch, arc 7 (publié, chapitres encore à venir) : édition en retard sur la date.
	$sw   = yume_preprod_oeuvre( 'secrets-of-the-silent-witch' );
	$arc7 = yume_preprod_tome( $sw, 'arc', 7 );
	$arc8 = yume_preprod_tome( $sw, 'arc', 8 );
	yume_preprod_ok(
		yume_preprod_il_y_a(
			20 * 24,
			static fn() => $maj(
				$arc7,
				array(
					'responsables' => array(
						'traduction' => $calumi,
						'relecture'  => $mael,
						'edition'    => $jojogg,
					),
					'date_cible'   => $jour( -6 ),
				),
				$angeloids
			)
		),
		'Arc 7'
	);
	yume_preprod_ok( yume_preprod_il_y_a( 9 * 24, static fn() => $maj( $arc7, array( 'avancement' => array( 'edition' => 53 ) ), $jojogg ) ), 'Arc 7' );
	yume_preprod_ok(
		yume_preprod_il_y_a(
			2 * 24,
			static fn() => $maj( $arc7, array( 'note_equipe' => 'Chapitres 9 à 15 : mise en page à reprendre (dialogues fusionnés au chapitre 11).' ), $jojogg )
		),
		'Arc 7'
	);

	// Silent Witch, arc 8 (brouillon) : relecture commencée puis arrêtée, en retard.
	yume_preprod_ok(
		yume_preprod_il_y_a(
			18 * 24,
			static fn() => $maj(
				$arc8,
				array(
					'responsables' => array(
						'traduction' => $calumi,
						'relecture'  => $mael,
						'edition'    => $jojogg,
					),
					'date_cible'   => $jour( -2 ),
				),
				$angeloids
			)
		),
		'Arc 8'
	);
	yume_preprod_ok( yume_preprod_il_y_a( 16 * 24, static fn() => $maj( $arc8, array( 'avancement' => array( 'relecture' => 20 ) ), $mael ) ), 'Arc 8' );

	// Raven of the Inner Palace, tome 7 : ajouté par l'éditeur, traduction en cours.
	$raven = yume_preprod_oeuvre( 'raven-of-the-inner-palace' );
	$t7    = yume_preprod_tome( $raven, 'tome', 7 );
	if ( ! $t7 ) {
		$t7 = (int) yume_preprod_ok(
			yume_preprod_il_y_a(
				10 * 24,
				static fn() => $ajouter(
					array(
						'oeuvre_id'    => $raven,
						'nature'       => 'tome',
						'numero'       => '7',
						'responsables' => array(
							'traduction' => $cerale,
							'relecture'  => $mael,
							'edition'    => $jojogg,
						),
						'date_cible'   => $jour( 16 ),
						'etape'        => 'traduction',
					),
					$jojogg
				)
			),
			'Raven tome 7'
		);
	}
	yume_preprod_ok( yume_preprod_il_y_a( 6 * 24, static fn() => $maj( $t7, array( 'avancement' => array( 'traduction' => 20 ) ), $cerale ) ), 'Raven tome 7' );
	yume_preprod_ok( yume_preprod_il_y_a( 20, static fn() => $maj( $t7, array( 'avancement' => array( 'traduction' => 45 ) ), $cerale ) ), 'Raven tome 7' );

	// Survival in Another World with My Mistress, tome 5 : bloqué (relecteur indisponible).
	$survival = yume_preprod_oeuvre( 'survival-in-another-world-with-my-mistress' );
	$t5       = yume_preprod_tome( $survival, 'tome', 5 );
	if ( ! $t5 ) {
		$t5 = (int) yume_preprod_ok(
			yume_preprod_il_y_a(
				12 * 24,
				static fn() => $ajouter(
					array(
						'oeuvre_id'    => $survival,
						'nature'       => 'tome',
						'numero'       => '5',
						'responsables' => array( 'traduction' => $angeloids ),
						'date_cible'   => $jour( 9 ),
						'etape'        => 'traduction',
					),
					$angeloids
				)
			),
			'Survival tome 5'
		);
	}
	yume_preprod_ok( yume_preprod_il_y_a( 4 * 24, static fn() => $maj( $t5, array( 'avancement' => array( 'traduction' => 80 ) ), $angeloids ) ), 'Survival tome 5' );
	yume_preprod_ok(
		yume_preprod_il_y_a(
			30,
			static fn() => $maj(
				$t5,
				array(
					'bloque'        => true,
					'bloque_raison' => 'relecteur indisponible jusqu’au 5 oct.',
				),
				$angeloids
			)
		),
		'Survival tome 5'
	);

	// Miss Medic's Diary at War, tome 2 : traduction en cours (tâche du traducteur).
	$medic = yume_preprod_oeuvre( 'miss-medics-diary-at-war' );
	$mm2   = yume_preprod_tome( $medic, 'tome', 2 );
	if ( ! $mm2 ) {
		$mm2 = (int) yume_preprod_ok(
			yume_preprod_il_y_a(
				9 * 24,
				static fn() => $ajouter(
					array(
						'oeuvre_id'    => $medic,
						'nature'       => 'tome',
						'numero'       => '2',
						'responsables' => array(
							'traduction' => $calumi,
							'relecture'  => $mael,
							'edition'    => $jojogg,
						),
						'date_cible'   => $jour( 23 ),
						'etape'        => 'traduction',
					),
					$jojogg
				)
			),
			'Miss Medic tome 2'
		);
	}
	yume_preprod_ok( yume_preprod_il_y_a( 5 * 24, static fn() => $maj( $mm2, array( 'avancement' => array( 'traduction' => 30 ) ), $calumi ) ), 'Miss Medic tome 2' );
	yume_preprod_ok(
		yume_preprod_il_y_a(
			26,
			static fn() => $maj(
				$mm2,
				array(
					'avancement'  => array( 'traduction' => 55 ),
					'note_equipe' => 'Glossaire médical à valider avec Mael7523m.',
				),
				$calumi
			)
		),
		'Miss Medic tome 2'
	);

	// Rappels du jour (retards, blocage sans responsable) : journal et e-mails en file.
	$rapport = call_user_func( $p . 'executer_rappels' );
	yume_preprod_log(
		sprintf(
			'Planning : Grimgar T.10 (%d), Silent Witch arcs 7 et 8 (%d, %d), Raven T.7 (%d), Survival T.5 (%d), Miss Medic T.2 (%d) ; rappels : %s.',
			$t10,
			$arc7,
			$arc8,
			$t7,
			$t5,
			$mm2,
			wp_json_encode( is_array( $rapport ) ? array_map( static fn( $v ) => is_array( $v ) ? count( $v ) : $v, $rapport ) : $rapport )
		)
	);
	foreach ( array( $t10, $arc7, $arc8, $t7, $t5, $mm2 ) as $id ) {
		yume_preprod_log( sprintf( '  %-55s %s', html_entity_decode( get_the_title( $id ), ENT_QUOTES ), yume_planning_etat( $id ) ) );
	}
}

/**
 * Lecteurs : favoris, alertes, notes, progression, réglages de lecture, commentaires.
 */
function yume_preprod_lecteurs(): void {
	$s       = '\\Yume\\Core\\Social\\';
	$r       = '\\Yume\\Core\\Reader\\';
	$kaede   = yume_preprod_user( 'kaede' );
	$yuzu    = yume_preprod_user( 'yuzu' );
	$grimgar = yume_preprod_oeuvre( 'grimgar-of-fantasy-and-ash' );
	$sw      = yume_preprod_oeuvre( 'secrets-of-the-silent-witch' );
	$raven   = yume_preprod_oeuvre( 'raven-of-the-inner-palace' );
	$t7      = yume_preprod_tome( $grimgar, 'tome', 7 );
	$arc7    = yume_preprod_tome( $sw, 'arc', 7 );
	$chap_g  = $t7 ? yume_get_chapitres( $t7 ) : array();
	$chap_sw = $arc7 ? yume_get_chapitres( $arc7 ) : array();

	foreach (
		array(
			array( $kaede, $grimgar, 'immediat', 5 ),
			array( $kaede, $sw, 'hebdo', 4 ),
			array( $kaede, $raven, 'immediat', 0 ),
			array( $yuzu, $raven, 'immediat', 5 ),
			array( $yuzu, $grimgar, 'hebdo', 4 ),
			array( $yuzu, $sw, 'jamais', 5 ),
		) as $f
	) {
		yume_preprod_ok( call_user_func( $s . 'ajouter_favori', $f[0], $f[1], $f[2] ), 'Favori' );
		call_user_func( $s . 'definir_frequence', $f[0], $f[1], $f[2] );
		if ( $f[3] ) {
			yume_preprod_ok( call_user_func( $s . 'noter', $f[0], $f[1], $f[3] ), 'Note' );
		}
	}

	// Positions de lecture (une par œuvre ; paragraphes numérotés à partir de 0).
	if ( count( $chap_sw ) >= 6 ) {
		yume_preprod_ok( call_user_func( $r . 'enregistrer_progression', $kaede, (int) $chap_sw[5]->ID, 12, 35 ), 'Progression Kaede (Silent Witch)' );
		yume_preprod_ok( call_user_func( $r . 'enregistrer_progression', $yuzu, (int) end( $chap_sw )->ID, 40, 80 ), 'Progression Yuzu (Silent Witch)' );
	}
	if ( count( $chap_g ) >= 3 ) {
		// La plus récente : Grimgar, tome 7, chapitre 3, 41 %.
		yume_preprod_ok( call_user_func( $r . 'enregistrer_progression', $kaede, (int) $chap_g[2]->ID, 24, 41 ), 'Progression Kaede (Grimgar)' );
	}
	// Dates de lecture étalées (sinon identiques à la seconde près, ordre indéterminé).
	if ( function_exists( $r . 'table_progression' ) ) {
		global $wpdb;
		foreach ( array( array( $kaede, $sw, 2 * DAY_IN_SECONDS ), array( $yuzu, $sw, 5 * HOUR_IN_SECONDS ) ) as $p ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update(
				call_user_func( $r . 'table_progression' ),
				array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $p[2] ) ),
				array(
					'user_id'   => $p[0],
					'oeuvre_id' => $p[1],
				)
			);
		}
	}

	call_user_func(
		$r . 'enregistrer_reglages',
		$kaede,
		array(
			'theme' => 'sepia',
			'font'  => 'merriweather',
			'size'  => 19,
			'lh'    => 1.7,
			'width' => 64,
		)
	);
	call_user_func(
		$r . 'enregistrer_reglages',
		$yuzu,
		array(
			'theme' => 'papier',
			'font'  => 'literata',
			'size'  => 20,
			'lh'    => 1.8,
		)
	);
	call_user_func(
		$s . 'enregistrer_preferences',
		$kaede,
		array(
			'sorties'      => true,
			'hebdo'        => true,
			'commentaires' => true,
		)
	);

	// Commentaires (maquette Oeuvre : signalement d'une coquille, réponse de l'équipe).
	$maintenant = time();
	$commenter  = static function ( int $post, int $user, string $texte, int $heures, int $reponse_a = 0 ) use ( $maintenant ): int {
		$u    = get_userdata( $user );
		$date = $maintenant - $heures * HOUR_IN_SECONDS;
		return (int) wp_insert_comment(
			array(
				'comment_post_ID'      => $post,
				'comment_parent'       => $reponse_a,
				'user_id'              => $user,
				'comment_author'       => $u->display_name,
				'comment_author_email' => $u->user_email,
				'comment_content'      => $texte,
				'comment_approved'     => 1,
				'comment_date'         => wp_date( 'Y-m-d H:i:s', $date ),
				'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $date ),
			)
		);
	};
	$premier    = $commenter( $grimgar, $kaede, 'Le tome 7 est incroyable, merci pour la traduction ! Petite coquille au chapitre 3 : « il pensa » → « il pensait ».', 3 );
	$commenter( $grimgar, yume_preprod_user( 'angeloids' ), 'Corrigé, merci du signalement !', 1, $premier );
	if ( $chap_g ) {
		$commenter( (int) $chap_g[0]->ID, $yuzu, 'Ce premier chapitre donne le ton, j’ai hâte de lire la suite.', 20 );
	}
	yume_preprod_log( 'Lecteurs : kaede et yuzu (favoris, notes, alertes, progression, réglages) ; 3 commentaires.' );
}

/**
 * Bilan : comptes et adresses.
 */
function yume_preprod_bilan(): void {
	$grimgar = yume_preprod_oeuvre( 'grimgar-of-fantasy-and-ash' );
	$t7      = yume_preprod_tome( $grimgar, 'tome', 7 );
	$chap    = $t7 ? yume_get_chapitres( $t7 ) : array();
	yume_preprod_log( 'Préproduction : ' . home_url( '/' ) );
	yume_preprod_log( '  administrateur : admin / admin ; équipe et lecteurs : mot de passe « ' . YUME_PREPROD_MDP . ' »' );
	foreach ( yume_preprod_comptes() as $login => $compte ) {
		yume_preprod_log( sprintf( '  %-10s %s', $login, $compte[2] ) );
	}
	yume_preprod_log( '  fiche Grimgar : ' . get_permalink( $grimgar ) );
	if ( $chap ) {
		yume_preprod_log( '  tome 7 : ' . get_permalink( $t7 ) . ' (' . count( $chap ) . ' chapitres)' );
		yume_preprod_log( '  chapitre 1 : ' . get_permalink( $chap[0] ) );
	}
	foreach ( array( 'bibliotheque', 'planning', 'equipe', 'publier', 'membres', 'compte', 'connexion', 'actualites' ) as $cle ) {
		yume_preprod_log( sprintf( '  %-13s %s', $cle, yume_url_page( $cle ) ) );
	}
}

/*
 * -----------------------------------------------------------------------------
 * Aiguillage
 * -----------------------------------------------------------------------------
 */

try {
	Yume_Seed_Local::verifier_environnement();
} catch ( \RuntimeException $e ) {
	yume_preprod_erreur( $e->getMessage() );
}
$yume_preprod_args  = array_values( (array) ( $args ?? array() ) );
$yume_preprod_etape = (string) ( $yume_preprod_args[0] ?? '' );
switch ( $yume_preprod_etape ) {
	case 'reglages':
		yume_preprod_reglages();
		break;
	case 'medias':
		yume_preprod_medias();
		break;
	case 'comptes':
		yume_preprod_comptes_creer();
		break;
	case 'complements':
		yume_preprod_complements();
		break;
	case 'grimgar':
		yume_preprod_grimgar( (string) ( $yume_preprod_args[1] ?? '' ) );
		break;
	case 'planning':
		yume_preprod_planning();
		break;
	case 'lecteurs':
		yume_preprod_lecteurs();
		break;
	case 'bilan':
		yume_preprod_bilan();
		break;
	default:
		yume_preprod_erreur( 'Étape inconnue « ' . $yume_preprod_etape . ' » : reglages, medias, comptes, complements, grimgar <docx>, planning, lecteurs, bilan.' );
}
