/**
 * WordEnd — moteur : entrées (clavier, manettes tactiles, impulsions).
 *
 * Touches physiques (e.code, indépendantes de la disposition) pour les déplacements et les
 * actions ; M (musique) et P (pause) sur la lettre tapée (e.key : en AZERTY, le M est à la
 * place du « ; » du QWERTY). Les touches du jeu ne remontent pas à la page (stopPropagation).
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

	/* Actions de navigation renvoyées par surTouche pour les flèches. */
	var NAVIGATION = { ArrowLeft: 'gauche', ArrowRight: 'droite', ArrowUp: 'haut', ArrowDown: 'bas' };

	/* Commandes qui produisent une impulsion (vraie une fois par appui). */
	var IMPULSIONS = { epee: true, competence: true, saut: true };

	/* Manettes par défaut (lot 0 : Chtholly v1). */
	var SCHEMA_DEFAUT = [
		{ nom: 'gauche', texte: '◀', libelle: 'Aller à gauche' },
		{ nom: 'droite', texte: '▶', libelle: 'Aller à droite' },
		{ nom: 'courir', texte: 'Courir', libelle: 'Courir', bascule: true },
		{ nom: 'epee', texte: 'Épée', libelle: 'Coup d’épée' },
		{ nom: 'competence', texte: 'Charge', libelle: 'Charge magique (maintenir puis relâcher)' },
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

	function creer( dialogue, ecran ) {
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
		 * 'echap'|'pause'|'muet'|'valider'|'epee'|'gauche'|'droite'|'haut'|'bas'|''.
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
			if ( lettre === 'm' ) {
				e.preventDefault();
				return e.repeat ? '' : 'muet';
			}
			if ( lettre === 'p' ) {
				e.preventDefault();
				return e.repeat ? '' : 'pause';
			}
			var action = TOUCHES[ e.code ];
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				if ( action && ! e.repeat ) {
					impulsions[ action ] = true;
				}
				return 'valider';
			}
			if ( ! action ) {
				return '';
			}
			e.preventDefault();
			clavier[ action ] = true;
			if ( ! e.repeat && IMPULSIONS[ action ] ) {
				impulsions[ action ] = true;
			}
			if ( action === 'epee' && ! e.repeat ) {
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

		/* Construit les manettes tactiles : schema = [{nom, texte, libelle, bascule}]. */
		entrees.actualiserManettes = function ( schema ) {
			var conteneur = entrees.element;
			while ( conteneur.firstChild ) {
				conteneur.removeChild( conteneur.firstChild );
			}
			tactile = {};
			( schema || SCHEMA_DEFAUT ).forEach( function ( commande ) {
				var attributs = { type: 'button', class: 'yn-btn yn-wordend__manette', 'aria-label': commande.libelle, 'data-commande': commande.nom };
				if ( commande.bascule ) {
					attributs[ 'aria-pressed' ] = entrees.courirTactile ? 'true' : 'false';
				}
				var bouton = element( 'button', attributs, commande.texte );
				if ( commande.bascule ) {
					bouton.addEventListener( 'click', function () {
						entrees.courirTactile = ! entrees.courirTactile;
						bouton.setAttribute( 'aria-pressed', entrees.courirTactile ? 'true' : 'false' );
					} );
				} else {
					var relacher = function () {
						tactile[ commande.nom ] = false;
					};
					bouton.addEventListener( 'pointerdown', function ( e ) {
						e.preventDefault();
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
					bouton.addEventListener( 'contextmenu', function ( e ) {
						e.preventDefault();
					} );
					// Clavier sur le bouton (Entrée / Espace) : action ponctuelle.
					bouton.addEventListener( 'click', function ( e ) {
						if ( e.detail === 0 && IMPULSIONS[ commande.nom ] ) {
							impulsions[ commande.nom ] = true;
						}
					} );
				}
				conteneur.appendChild( bouton );
			} );
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
