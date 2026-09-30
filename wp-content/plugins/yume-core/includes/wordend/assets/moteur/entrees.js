/**
 * WordEnd — moteur : entrées (clavier, manettes tactiles, impulsions).
 *
 * Touches physiques (e.code, indépendantes de la disposition) pour les déplacements et les
 * actions ; M (musique), P (pause) et R (retour aux niveaux) sur la lettre tapée (e.key : en
 * AZERTY, le M est à la place du « ; » du QWERTY). Les touches du jeu ne remontent pas à la
 * page (stopPropagation). Saut : flèche haut, W (Z en AZERTY) ou Espace ; Entrée et Espace
 * gardent aussi leur rôle « valider » sur les écrans (titre, sélection, pause, fin).
 * Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;

	var TOUCHES = {
		ArrowLeft: 'gauche',
		KeyA: 'gauche', // Q en AZERTY (touche physique).
		ArrowRight: 'droite',
		KeyD: 'droite',
		ArrowUp: 'saut',
		KeyW: 'saut', // Z en AZERTY.
		Space: 'saut',
		ArrowDown: 'bas',
		KeyS: 'bas',
		ShiftLeft: 'courir',
		ShiftRight: 'courir',
		KeyJ: 'epee',
		KeyX: 'epee',
		KeyK: 'competence',
		KeyC: 'competence',
	};

	/* Actions de navigation (écrans de sélection) renvoyées par surTouche. */
	var NAVIGATION = {
		ArrowLeft: 'gauche',
		KeyA: 'gauche',
		ArrowRight: 'droite',
		KeyD: 'droite',
		ArrowUp: 'haut',
		KeyW: 'haut',
		ArrowDown: 'bas',
		KeyS: 'bas',
	};

	/* Lettres lues sur e.key (disposition du clavier respectée). */
	var LETTRES = { m: 'muet', p: 'pause', r: 'niveaux' };

	/* Commandes qui produisent une impulsion (vraie une fois par appui). */
	var IMPULSIONS = { epee: true, competence: true, saut: true };

	/*
	 * Manettes par défaut (Chtholly). groupe : 'gauche' (déplacements) ou 'droite' (actions).
	 * La modale les remplace d'après le personnage choisi (schéma de la même forme).
	 */
	var SCHEMA_DEFAUT = [
		{ nom: 'gauche', texte: '◀', libelle: 'Aller à gauche', groupe: 'gauche' },
		{ nom: 'droite', texte: '▶', libelle: 'Aller à droite', groupe: 'gauche' },
		{ nom: 'courir', texte: 'Courir', libelle: 'Courir', bascule: true, groupe: 'gauche' },
		{ nom: 'saut', texte: 'Saut', libelle: 'Sauter', groupe: 'droite' },
		{ nom: 'epee', texte: 'Épée', libelle: 'Coup d’épée', groupe: 'droite' },
		{ nom: 'competence', texte: 'Charge', libelle: 'Charge magique (maintenir puis relâcher)', groupe: 'droite' },
	];

	function element( balise, attributs, texte ) {
		var el = document.createElement( balise );
		Object.keys( attributs || {} ).forEach( function ( nom ) {
			el.setAttribute( nom, attributs[ nom ] );
		} );
		if ( texte ) {
			el.textContent = texte;
		}
		return el;
	}

	function creer() {
		var clavier = {};
		var tactile = {};
		var impulsions = {};

		var entrees = {
			TOUCHES: TOUCHES,
			courirTactile: false,
			/* Conteneur des manettes tactiles (à insérer par la modale). */
			element: element( 'div', { class: 'yn-wordend__manettes', role: 'group', 'aria-label': 'Commandes tactiles' } ),
			/* Rappel de la modale à chaque appui tactile : fn(nom). */
			surAppui: null,
		};

		entrees.commande = function ( nom ) {
			return !! ( clavier[ nom ] || tactile[ nom ] );
		};

		/* Vrai une seule fois par appui (consommée à la lecture). */
		entrees.impulsion = function ( nom ) {
			var valeur = !! impulsions[ nom ];
			impulsions[ nom ] = false;
			return valeur;
		};

		/* quoi : undefined (clavier + impulsions), 'impulsions', 'tout' (+ tactile). */
		entrees.vider = function ( quoi ) {
			impulsions = {};
			if ( quoi !== 'impulsions' ) {
				clavier = {};
			}
			if ( quoi === 'tout' ) {
				tactile = {};
			}
		};

		/**
		 * Touche enfoncée dans la modale : met à jour l'état et renvoie l'action d'interface :
		 * 'echap'|'pause'|'muet'|'niveaux'|'valider'|'epee'|'gauche'|'droite'|'haut'|'bas'|''.
		 */
		entrees.surTouche = function ( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				return 'echap';
			}
			if ( e.key === 'Tab' || e.altKey || e.ctrlKey || e.metaKey ) {
				return '';
			}
			var cible = e.target && e.target.tagName;
			if ( cible === 'INPUT' || cible === 'SELECT' ) {
				e.stopPropagation(); // Curseur de volume, listes : flèches et Début/Fin gardent leur rôle.
				return '';
			}
			if ( cible === 'BUTTON' && ( e.key === 'Enter' || e.key === ' ' ) ) {
				return ''; // Laisse le bouton agir.
			}
			// Les raccourcis de la page (lecteur, thème) ne voient pas les touches du jeu.
			e.stopPropagation();

			var lettre = String( e.key || '' ).toLowerCase();
			if ( LETTRES[ lettre ] ) {
				e.preventDefault();
				return e.repeat ? '' : LETTRES[ lettre ];
			}
			var action = TOUCHES[ e.code ];
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				if ( action ) {
					clavier[ action ] = true; // Espace maintenu : saut long.
					if ( ! e.repeat && IMPULSIONS[ action ] ) {
						impulsions[ action ] = true;
					}
				}
				return e.repeat ? '' : 'valider';
			}
			if ( ! action ) {
				return '';
			}
			e.preventDefault();
			clavier[ action ] = true;
			if ( e.repeat ) {
				return '';
			}
			if ( IMPULSIONS[ action ] ) {
				impulsions[ action ] = true;
			}
			if ( action === 'epee' ) {
				return 'epee';
			}
			return NAVIGATION[ e.code ] || '';
		};

		entrees.relacher = function ( e ) {
			var action = TOUCHES[ e.code ];
			if ( action ) {
				clavier[ action ] = false;
			}
		};

		function creerBouton( commande ) {
			var attributs = {
				type: 'button',
				class: 'yn-btn yn-wordend__manette yn-wordend__manette--' + commande.nom,
				'aria-label': commande.libelle || commande.texte,
				'data-commande': commande.nom,
			};
			if ( commande.bascule ) {
				attributs[ 'aria-pressed' ] = entrees.courirTactile ? 'true' : 'false';
			}
			var bouton = element( 'button', attributs, commande.texte );
			if ( commande.bascule ) {
				bouton.addEventListener( 'click', function () {
					entrees.courirTactile = ! entrees.courirTactile;
					bouton.setAttribute( 'aria-pressed', entrees.courirTactile ? 'true' : 'false' );
				} );
				return bouton;
			}
			var relacher = function () {
				tactile[ commande.nom ] = false;
			};
			var dernierPointeur = 0;
			bouton.addEventListener( 'pointerdown', function ( e ) {
				e.preventDefault();
				dernierPointeur = Date.now();
				if ( bouton.setPointerCapture ) {
					try {
						bouton.setPointerCapture( e.pointerId );
					} catch ( err ) {}
				}
				tactile[ commande.nom ] = true;
				if ( IMPULSIONS[ commande.nom ] ) {
					impulsions[ commande.nom ] = true;
				}
				if ( typeof entrees.surAppui === 'function' ) {
					entrees.surAppui( commande.nom );
				}
			} );
			bouton.addEventListener( 'pointerup', relacher );
			bouton.addEventListener( 'pointercancel', relacher );
			bouton.addEventListener( 'lostpointercapture', relacher );
			// Appui long : ni menu contextuel ni sélection de texte.
			bouton.addEventListener( 'contextmenu', function ( e ) {
				e.preventDefault();
			} );
			// Clavier sur le bouton (Entrée / Espace) : action ponctuelle. Le clic qui suit un
			// toucher (detail 0 lui aussi dans certains navigateurs) est ignoré.
			bouton.addEventListener( 'click', function ( e ) {
				if ( e.detail !== 0 || Date.now() - dernierPointeur < 1000 ) {
					return;
				}
				if ( IMPULSIONS[ commande.nom ] ) {
					impulsions[ commande.nom ] = true;
				}
				if ( typeof entrees.surAppui === 'function' ) {
					entrees.surAppui( commande.nom );
				}
			} );
			return bouton;
		}

		/*
		 * Construit les manettes tactiles : schema = [{nom, texte, libelle, bascule?, groupe?}] ;
		 * deux groupes : déplacements à gauche, actions à droite (groupe absent : déduit du nom).
		 */
		entrees.actualiserManettes = function ( schema ) {
			var conteneur = entrees.element;
			while ( conteneur.firstChild ) {
				conteneur.removeChild( conteneur.firstChild );
			}
			tactile = {};
			var groupes = {
				gauche: element( 'div', { class: 'yn-wordend__manettes-groupe yn-wordend__manettes-groupe--gauche' } ),
				droite: element( 'div', { class: 'yn-wordend__manettes-groupe yn-wordend__manettes-groupe--droite' } ),
			};
			( schema && schema.length ? schema : SCHEMA_DEFAUT ).forEach( function ( commande ) {
				var groupe = commande.groupe || ( /^(gauche|droite|courir|bas)$/.test( commande.nom ) ? 'gauche' : 'droite' );
				( groupes[ groupe ] || groupes.droite ).appendChild( creerBouton( commande ) );
			} );
			conteneur.appendChild( groupes.gauche );
			conteneur.appendChild( groupes.droite );
		};

		entrees.actualiserManettes( SCHEMA_DEFAUT );
		return entrees;
	}

	ynWE.entrees = {
		creer: creer,
		TOUCHES: TOUCHES,
		SCHEMA_DEFAUT: SCHEMA_DEFAUT,
	};
}() );
