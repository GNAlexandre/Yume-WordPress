<?php
/**
 * Types de contenu (œuvre, tome, chapitre) et taxonomies (§3 du contrat).
 *
 * Enregistrés sur init (priorité 5) et sur l'action yume_core_register_content, déclenchée
 * à l'activation avant le vidage des règles de réécriture.
 *
 * @package Yume\Core
 */

namespace Yume\Core\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre les types de contenu, les taxonomies et les règles de réécriture.
 * Idempotent : un second appel dans la même requête ne refait rien.
 */
function enregistrer_contenu(): void {
	if ( etat_get( 'contenu_enregistre' ) && post_type_exists( CPT_OEUVRE ) ) {
		return;
	}
	etat_set( 'contenu_enregistre', true );

	enregistrer_taxonomies();
	enregistrer_types();
	ajouter_regles_reecriture();
}
add_action( 'init', __NAMESPACE__ . '\\enregistrer_contenu', 5 );
add_action( 'yume_core_register_content', __NAMESPACE__ . '\\enregistrer_contenu' );

/**
 * Libellés complets d'un type de contenu.
 *
 * @param array<string,string> $l Libellés propres au type.
 * @return array<string,string>
 */
function libelles_type( array $l ): array {
	return array(
		'name'                     => $l['pluriel'],
		'singular_name'            => $l['singulier'],
		'add_new'                  => __( 'Ajouter', 'yume-core' ),
		'add_new_item'             => $l['ajouter'],
		'edit_item'                => $l['modifier'],
		'new_item'                 => $l['nouveau'],
		'view_item'                => $l['voir'],
		'view_items'               => $l['voir_tous'],
		'search_items'             => $l['rechercher'],
		'not_found'                => $l['aucun'],
		'not_found_in_trash'       => $l['aucun_corbeille'],
		'parent_item_colon'        => $l['parent'],
		'all_items'                => $l['pluriel'],
		'archives'                 => $l['archives'],
		'attributes'               => $l['attributs'],
		'insert_into_item'         => $l['inserer'],
		'uploaded_to_this_item'    => $l['televerse'],
		'featured_image'           => __( 'Couverture', 'yume-core' ),
		'set_featured_image'       => __( 'Définir la couverture', 'yume-core' ),
		'remove_featured_image'    => __( 'Retirer la couverture', 'yume-core' ),
		'use_featured_image'       => __( 'Utiliser comme couverture', 'yume-core' ),
		'menu_name'                => $l['pluriel'],
		'filter_items_list'        => $l['filtrer'],
		'filter_by_date'           => __( 'Filtrer par date', 'yume-core' ),
		'items_list_navigation'    => $l['navigation'],
		'items_list'               => $l['liste'],
		'item_published'           => $l['publie'],
		'item_published_privately' => $l['publie_prive'],
		'item_reverted_to_draft'   => $l['brouillon'],
		'item_trashed'             => $l['corbeille'],
		'item_scheduled'           => $l['programme'],
		'item_updated'             => $l['maj'],
		'item_link'                => $l['lien'],
		'item_link_description'    => $l['lien_desc'],
		'template_name'            => $l['modele'],
	);
}

/**
 * Enregistre yume_oeuvre, yume_tome et yume_chapitre.
 */
function enregistrer_types(): void {
	$commun = array(
		'public'              => true,
		'publicly_queryable'  => true,
		'show_ui'             => true,
		'show_in_menu'        => 'yume',
		'show_in_nav_menus'   => true,
		'show_in_admin_bar'   => true,
		'show_in_rest'        => true,
		'map_meta_cap'        => true,
		'delete_with_user'    => false,
		'can_export'          => true,
		'hierarchical'        => false,
		'exclude_from_search' => false,
	);

	register_post_type(
		CPT_OEUVRE,
		array_merge(
			$commun,
			array(
				'labels'          => libelles_type(
					array(
						'pluriel'         => __( 'Œuvres', 'yume-core' ),
						'singulier'       => __( 'Œuvre', 'yume-core' ),
						'ajouter'         => __( 'Ajouter une œuvre', 'yume-core' ),
						'modifier'        => __( 'Modifier l’œuvre', 'yume-core' ),
						'nouveau'         => __( 'Nouvelle œuvre', 'yume-core' ),
						'voir'            => __( 'Voir l’œuvre', 'yume-core' ),
						'voir_tous'       => __( 'Voir les œuvres', 'yume-core' ),
						'rechercher'      => __( 'Rechercher des œuvres', 'yume-core' ),
						'aucun'           => __( 'Aucune œuvre trouvée.', 'yume-core' ),
						'aucun_corbeille' => __( 'Aucune œuvre dans la corbeille.', 'yume-core' ),
						'parent'          => __( 'Œuvre parente :', 'yume-core' ),
						'archives'        => __( 'Toutes les œuvres', 'yume-core' ),
						'attributs'       => __( 'Attributs de l’œuvre', 'yume-core' ),
						'inserer'         => __( 'Insérer dans l’œuvre', 'yume-core' ),
						'televerse'       => __( 'Téléversé sur cette œuvre', 'yume-core' ),
						'filtrer'         => __( 'Filtrer la liste des œuvres', 'yume-core' ),
						'navigation'      => __( 'Navigation de la liste des œuvres', 'yume-core' ),
						'liste'           => __( 'Liste des œuvres', 'yume-core' ),
						'publie'          => __( 'Œuvre publiée.', 'yume-core' ),
						'publie_prive'    => __( 'Œuvre publiée en privé.', 'yume-core' ),
						'brouillon'       => __( 'Œuvre repassée en brouillon.', 'yume-core' ),
						'corbeille'       => __( 'Œuvre mise à la corbeille.', 'yume-core' ),
						'programme'       => __( 'Œuvre programmée.', 'yume-core' ),
						'maj'             => __( 'Œuvre mise à jour.', 'yume-core' ),
						'lien'            => __( 'Lien vers l’œuvre', 'yume-core' ),
						'lien_desc'       => __( 'Un lien vers une œuvre.', 'yume-core' ),
						'modele'          => __( 'Fiche œuvre', 'yume-core' ),
					)
				),
				'description'     => __( 'Une œuvre traduite (light novel, web novel ou manga) : fiche, synopsis, tomes.', 'yume-core' ),
				'menu_icon'       => 'dashicons-book-alt',
				'capability_type' => array( 'yume_oeuvre', 'yume_oeuvres' ),
				'rest_base'       => 'oeuvres',
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'custom-fields', 'revisions' ),
				'taxonomies'      => array( TAX_TYPE, TAX_STATUT, TAX_GENRE ),
				'has_archive'     => 'oeuvres',
				'rewrite'         => array(
					'slug'       => 'oeuvres',
					'with_front' => false,
					'feeds'      => true,
					'pages'      => true,
				),
				'query_var'       => CPT_OEUVRE,
				'template'        => array(
					array(
						'core/paragraph',
						array( 'placeholder' => __( 'Synopsis de l’œuvre…', 'yume-core' ) ),
					),
				),
			)
		)
	);

	register_post_type(
		CPT_TOME,
		array_merge(
			$commun,
			array(
				'labels'          => libelles_type(
					array(
						'pluriel'         => __( 'Tomes', 'yume-core' ),
						'singulier'       => __( 'Tome', 'yume-core' ),
						'ajouter'         => __( 'Ajouter un tome', 'yume-core' ),
						'modifier'        => __( 'Modifier le tome', 'yume-core' ),
						'nouveau'         => __( 'Nouveau tome', 'yume-core' ),
						'voir'            => __( 'Voir le tome', 'yume-core' ),
						'voir_tous'       => __( 'Voir les tomes', 'yume-core' ),
						'rechercher'      => __( 'Rechercher des tomes', 'yume-core' ),
						'aucun'           => __( 'Aucun tome trouvé.', 'yume-core' ),
						'aucun_corbeille' => __( 'Aucun tome dans la corbeille.', 'yume-core' ),
						'parent'          => __( 'Tome parent :', 'yume-core' ),
						'archives'        => __( 'Tous les tomes', 'yume-core' ),
						'attributs'       => __( 'Attributs du tome', 'yume-core' ),
						'inserer'         => __( 'Insérer dans le tome', 'yume-core' ),
						'televerse'       => __( 'Téléversé sur ce tome', 'yume-core' ),
						'filtrer'         => __( 'Filtrer la liste des tomes', 'yume-core' ),
						'navigation'      => __( 'Navigation de la liste des tomes', 'yume-core' ),
						'liste'           => __( 'Liste des tomes', 'yume-core' ),
						'publie'          => __( 'Tome publié.', 'yume-core' ),
						'publie_prive'    => __( 'Tome publié en privé.', 'yume-core' ),
						'brouillon'       => __( 'Tome repassé en brouillon (planifié, non sorti).', 'yume-core' ),
						'corbeille'       => __( 'Tome mis à la corbeille.', 'yume-core' ),
						'programme'       => __( 'Tome programmé.', 'yume-core' ),
						'maj'             => __( 'Tome mis à jour.', 'yume-core' ),
						'lien'            => __( 'Lien vers le tome', 'yume-core' ),
						'lien_desc'       => __( 'Un lien vers un tome.', 'yume-core' ),
						'modele'          => __( 'Page de tome', 'yume-core' ),
					)
				),
				'description'     => __( 'Un tome, un arc ou un volume d’une œuvre. Brouillon = planifié non sorti, programmé = sortie datée, publié = sorti.', 'yume-core' ),
				'menu_icon'       => 'dashicons-book',
				'capability_type' => array( 'yume_tome', 'yume_tomes' ),
				'rest_base'       => 'tomes',
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'comments', 'custom-fields', 'page-attributes' ),
				'has_archive'     => false,
				// Les URL /oeuvres/{oeuvre}/{tome}/ sont gérées par routing.php.
				'rewrite'         => false,
				'query_var'       => false,
				'template'        => array(
					array(
						'core/paragraph',
						array( 'placeholder' => __( 'Présentation du tome (facultatif)…', 'yume-core' ) ),
					),
				),
			)
		)
	);

	register_post_type(
		CPT_CHAPITRE,
		array_merge(
			$commun,
			array(
				'labels'              => libelles_type(
					array(
						'pluriel'         => __( 'Chapitres', 'yume-core' ),
						'singulier'       => __( 'Chapitre', 'yume-core' ),
						'ajouter'         => __( 'Ajouter un chapitre', 'yume-core' ),
						'modifier'        => __( 'Modifier le chapitre', 'yume-core' ),
						'nouveau'         => __( 'Nouveau chapitre', 'yume-core' ),
						'voir'            => __( 'Lire le chapitre', 'yume-core' ),
						'voir_tous'       => __( 'Voir les chapitres', 'yume-core' ),
						'rechercher'      => __( 'Rechercher des chapitres', 'yume-core' ),
						'aucun'           => __( 'Aucun chapitre trouvé.', 'yume-core' ),
						'aucun_corbeille' => __( 'Aucun chapitre dans la corbeille.', 'yume-core' ),
						'parent'          => __( 'Chapitre parent :', 'yume-core' ),
						'archives'        => __( 'Tous les chapitres', 'yume-core' ),
						'attributs'       => __( 'Ordre du chapitre', 'yume-core' ),
						'inserer'         => __( 'Insérer dans le chapitre', 'yume-core' ),
						'televerse'       => __( 'Téléversé sur ce chapitre', 'yume-core' ),
						'filtrer'         => __( 'Filtrer la liste des chapitres', 'yume-core' ),
						'navigation'      => __( 'Navigation de la liste des chapitres', 'yume-core' ),
						'liste'           => __( 'Liste des chapitres', 'yume-core' ),
						'publie'          => __( 'Chapitre publié.', 'yume-core' ),
						'publie_prive'    => __( 'Chapitre publié en privé.', 'yume-core' ),
						'brouillon'       => __( 'Chapitre repassé en brouillon.', 'yume-core' ),
						'corbeille'       => __( 'Chapitre mis à la corbeille.', 'yume-core' ),
						'programme'       => __( 'Chapitre programmé.', 'yume-core' ),
						'maj'             => __( 'Chapitre mis à jour.', 'yume-core' ),
						'lien'            => __( 'Lien vers le chapitre', 'yume-core' ),
						'lien_desc'       => __( 'Un lien vers un chapitre.', 'yume-core' ),
						'modele'          => __( 'Lecteur de chapitre', 'yume-core' ),
					)
				),
				'description'         => __( 'Une page de lecture : un chapitre d’un tome (menu_order = ordre dans le tome).', 'yume-core' ),
				'menu_icon'           => 'dashicons-media-text',
				'capability_type'     => array( 'yume_chapitre', 'yume_chapitres' ),
				'rest_base'           => 'chapitres',
				'supports'            => array( 'title', 'editor', 'comments', 'custom-fields', 'page-attributes' ),
				'has_archive'         => false,
				// Les chapitres ne noient pas les résultats de la recherche du site.
				'exclude_from_search' => true,
				// Les URL /lire/{oeuvre}/{tome}/{numero|slug}/ sont gérées par routing.php.
				'rewrite'             => false,
				'query_var'           => false,
				'template'            => array(
					array(
						'core/paragraph',
						array( 'placeholder' => __( 'Texte du chapitre… (le titre « Chapitre N » et le sous-titre sont affichés automatiquement)', 'yume-core' ) ),
					),
				),
			)
		)
	);
}

/**
 * Libellés d'une taxonomie.
 *
 * @param string $pluriel   Nom pluriel.
 * @param string $singulier Nom singulier.
 * @param bool   $feminin   Accord des libellés.
 * @return array<string,string>
 */
function libelles_taxonomie( string $pluriel, string $singulier, bool $feminin = false ): array {
	$min_p = mb_strtolower( $pluriel );
	$min_s = mb_strtolower( $singulier );
	return array(
		'name'                       => $pluriel,
		'singular_name'              => $singulier,
		'menu_name'                  => $pluriel,
		/* translators: %s : nom pluriel */
		'all_items'                  => sprintf( $feminin ? __( 'Toutes les %s', 'yume-core' ) : __( 'Tous les %s', 'yume-core' ), $min_p ),
		/* translators: %s : nom singulier */
		'edit_item'                  => sprintf( __( 'Modifier : %s', 'yume-core' ), $min_s ),
		/* translators: %s : nom singulier */
		'view_item'                  => sprintf( __( 'Voir : %s', 'yume-core' ), $min_s ),
		'update_item'                => __( 'Mettre à jour', 'yume-core' ),
		/* translators: %s : nom singulier */
		'add_new_item'               => sprintf( $feminin ? __( 'Ajouter une %s', 'yume-core' ) : __( 'Ajouter un %s', 'yume-core' ), $min_s ),
		/* translators: %s : nom singulier */
		'new_item_name'              => sprintf( __( 'Nom : %s', 'yume-core' ), $min_s ),
		/* translators: %s : nom singulier */
		'parent_item'                => sprintf( __( '%s parent', 'yume-core' ), $singulier ),
		/* translators: %s : nom singulier */
		'parent_item_colon'          => sprintf( __( '%s parent :', 'yume-core' ), $singulier ),
		/* translators: %s : nom pluriel */
		'search_items'               => sprintf( __( 'Rechercher des %s', 'yume-core' ), $min_p ),
		/* translators: %s : nom pluriel */
		'popular_items'              => sprintf( __( '%s les plus utilisés', 'yume-core' ), $pluriel ),
		/* translators: %s : nom pluriel */
		'separate_items_with_commas' => sprintf( __( 'Séparez les %s par des virgules', 'yume-core' ), $min_p ),
		/* translators: %s : nom pluriel */
		'add_or_remove_items'        => sprintf( __( 'Ajouter ou retirer des %s', 'yume-core' ), $min_p ),
		/* translators: %s : nom pluriel */
		'choose_from_most_used'      => sprintf( __( 'Choisir parmi les %s les plus utilisés', 'yume-core' ), $min_p ),
		/* translators: %s : nom pluriel */
		'not_found'                  => sprintf( __( 'Aucun élément trouvé (%s).', 'yume-core' ), $min_p ),
		'no_terms'                   => __( 'Aucun terme', 'yume-core' ),
		/* translators: %s : nom pluriel */
		'items_list_navigation'      => sprintf( __( 'Navigation de la liste des %s', 'yume-core' ), $min_p ),
		/* translators: %s : nom pluriel */
		'items_list'                 => sprintf( __( 'Liste des %s', 'yume-core' ), $min_p ),
		'back_to_items'              => __( '← Retour à la liste', 'yume-core' ),
		'item_link'                  => $singulier,
		/* translators: %s : nom singulier */
		'item_link_description'      => sprintf( __( 'Un lien vers : %s', 'yume-core' ), $min_s ),
	);
}

/**
 * Enregistre les taxonomies des œuvres et des articles.
 *
 * Les archives de termes ne sont pas publiques : les liens de termes mènent à la
 * bibliothèque filtrée (voir terms.php).
 */
function enregistrer_taxonomies(): void {
	$commun = array(
		'public'             => true,
		'publicly_queryable' => false,
		'show_ui'            => true,
		'show_in_menu'       => false,
		'show_in_nav_menus'  => true,
		'show_in_rest'       => true,
		'show_admin_column'  => true,
		'show_in_quick_edit' => true,
		'show_tagcloud'      => false,
		'query_var'          => false,
		'rewrite'            => false,
		'capabilities'       => array(
			'manage_terms' => 'manage_categories',
			'edit_terms'   => 'manage_categories',
			'delete_terms' => 'manage_categories',
			'assign_terms' => 'edit_yume_oeuvres',
		),
	);

	register_taxonomy(
		TAX_TYPE,
		array( CPT_OEUVRE ),
		array_merge(
			$commun,
			array(
				'labels'       => libelles_taxonomie( __( 'Types', 'yume-core' ), __( 'Type', 'yume-core' ) ),
				'description'  => __( 'Nature de l’œuvre : light novel, web novel, manga.', 'yume-core' ),
				'hierarchical' => true,
			)
		)
	);

	register_taxonomy(
		TAX_STATUT,
		array( CPT_OEUVRE ),
		array_merge(
			$commun,
			array(
				'labels'       => libelles_taxonomie( __( 'Statuts', 'yume-core' ), __( 'Statut', 'yume-core' ) ),
				'description'  => __( 'Statut de la traduction : en cours, terminée, en pause, licenciée, abandonnée.', 'yume-core' ),
				'hierarchical' => true,
			)
		)
	);

	register_taxonomy(
		TAX_GENRE,
		array( CPT_OEUVRE ),
		array_merge(
			$commun,
			array(
				'labels'       => libelles_taxonomie( __( 'Genres', 'yume-core' ), __( 'Genre', 'yume-core' ) ),
				'description'  => __( 'Genres de l’œuvre (fantasy, romance, tranche de vie…).', 'yume-core' ),
				'hierarchical' => false,
			)
		)
	);

	register_taxonomy(
		TAX_OEUVRE_LIEE,
		array( 'post' ),
		array_merge(
			$commun,
			array(
				'labels'               => libelles_taxonomie( __( 'Œuvres liées', 'yume-core' ), __( 'Œuvre liée', 'yume-core' ), true ),
				'description'          => __( 'Relie un article d’actualité à une œuvre. Termes synchronisés automatiquement avec les œuvres.', 'yume-core' ),
				'hierarchical'         => false,
				'show_in_nav_menus'    => false,
				'show_in_quick_edit'   => false,
				// Cases à cocher plutôt qu'un champ libre : seules les œuvres existantes sont proposées.
				'meta_box_cb'          => 'post_categories_meta_box',
				'meta_box_sanitize_cb' => 'taxonomy_meta_box_sanitize_cb_checkboxes',
				'capabilities'         => array(
					'manage_terms' => 'manage_categories',
					// Les termes suivent les œuvres : personne ne les crée ni ne les supprime à la main.
					'edit_terms'   => 'do_not_allow',
					'delete_terms' => 'do_not_allow',
					'assign_terms' => 'edit_posts',
				),
			)
		)
	);
}
