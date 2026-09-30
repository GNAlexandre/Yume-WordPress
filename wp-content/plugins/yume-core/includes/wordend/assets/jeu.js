/**
 * WordEnd — Chtholly – Bats-toi contre ton destin (easter egg de Yume Novel).
 *
 * Chargé à la demande par declencheur.js. Expose window.ynWordEndJeu :
 * { ouvrir( config ), fermer(), estOuvert() }.
 *
 * - Modale <dialog> (showModal : le reste de la page est inerte). Échap met la partie en pause,
 *   un second Échap (ou Échap hors partie) ferme ; le focus revient à l'élément d'origine. Événement document « yn:wordend » (detail.etat = ouvert|ferme).
 * - Écran logique 480 × 270 dessiné en 2× (planche de Chtholly à l'échelle 1:1), pas fixe
 *   de 1/60 s, pause quand l'onglet est masqué ou la fenêtre perd le focus.
 * - Chtholly : repos, marche, course (Maj), coup d'épée (J/X), charge magique (K/C maintenu
 *   puis relâché : onde qui traverse les Timeres), dégâts (invincibilité), mort.
 * - Timeres (planche timere.png, même format que celle de Chtholly) : petit, normal, coureur,
 *   grand ; approche, morsure ou coup de fouet (touche sur les images « coup »), dégâts, mort ;
 *   par vagues croissantes.
 * - Décor peint (decor.webp) ; musique de fond (musique.mp3) pendant la partie seulement, en
 *   boucle, avec volume et bouton muet (touche M), réglages mémorisés.
 * - Meilleur score et réglages du son dans localStorage['yn.wordend'] (try/catch).
 * - Mouvement réduit (prefers-reduced-motion ou html[data-yn-animations="reduites"]) : ni
 *   secousse ni clignotement, moins de particules.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* Constantes                                                          */
	/* ------------------------------------------------------------------ */

	var LARGEUR = 480;
	var HAUTEUR = 270;
	var DENSITE = 2; // Pixels de canvas par pixel logique (planche dessinée pour 2×).
	var DT = 1 / 60;
	var SOL_Y = 238;
	var BORD = 18;
	var CLE_STOCKAGE = 'yn.wordend';

	var VITESSE_MARCHE = 72;
	var VITESSE_COURSE = 138;
	var PV_MAX = 5;
	var INVINCIBILITE = 1.2;
	var DUREE_DEGATS = 0.35;
	var RECUL = 150;
	var CHARGE_MIN = 0.55;
	var DUREE_ONDE = 0.42;
	var RECHARGE_ONDE = 1.2;
	var DUREE_MORT = 2.2;

	var TYPES = {
		petit: { taille: 0.8, pv: 1, vitesse: 54, points: 10, attaques: [ 'morsure' ] },
		normal: { taille: 1, pv: 2, vitesse: 38, points: 15, attaques: [ 'morsure', 'fouet' ] },
		coureur: { taille: 0.9, pv: 1, vitesse: 112, points: 20, attaques: [ 'morsure' ], course: true },
		grand: { taille: 1.3, pv: 5, vitesse: 28, points: 40, attaques: [ 'fouet' ], stoique: true },
	};
	var PORTEE = { morsure: 40, fouet: 48 }; // Devant l'ancre, à la taille 1 (px logiques).
	var DUREE_FONDU = 0.6;

	var TOUCHES = {
		ArrowLeft: 'gauche',
		KeyA: 'gauche', // Q en AZERTY (touche physique).
		ArrowRight: 'droite',
		KeyD: 'droite',
		ShiftLeft: 'courir',
		ShiftRight: 'courir',
		KeyJ: 'epee',
		KeyX: 'epee',
		KeyK: 'charge',
		KeyC: 'charge',
	};

	/* ------------------------------------------------------------------ */
	/* État                                                                */
	/* ------------------------------------------------------------------ */

	var config = null;
	var dialogue = null;
	var ecran = null;
	var ctx = null;
	var annonce = null;
	var boutonJouer = null;
	var boutonPause = null;
	var focusAvant = null;
	var ouvert = false;
	var echapTraite = false;
	var planche = null;
	var meta = null;
	var plancheTimere = null;
	var imageDecor = null;
	var musique = null;
	var son = { volume: 0.5, muet: false };
	var boutonMuet = null;
	var curseurVolume = null;
	var metaTimere = null;
	var ressources = null;
	var palette = {};
	var police = 'sans-serif';
	var requete = 0;
	var dernierTemps = 0;
	var accumulateur = 0;
	var temps = 0;
	var mouvementReduit = false;

	var clavier = {};
	var tactile = {};
	var courirTactile = false;
	var appuiEpee = false;

	var etat = 'chargement'; // chargement | titre | jeu | pause | fin | erreur
	var joueur = null;
	var timeres = [];
	var ondes = [];
	var particules = [];
	var vague = null;
	var score = 0;
	var meilleur = 0;
	var secousse = 0;
	var prochainId = 1;

	/* ------------------------------------------------------------------ */
	/* Outils                                                              */
	/* ------------------------------------------------------------------ */

	function hasard( min, max ) {
		return min + Math.random() * ( max - min );
	}

	function chevauche( a, b ) {
		return a.x < b.x + b.l && a.x + a.l > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
	}

	function annoncer( texte ) {
		if ( annonce ) {
			annonce.textContent = '';
			window.setTimeout( function () {
				annonce.textContent = texte;
			}, 50 );
		}
	}

	function lireStockage() {
		try {
			var donnees = JSON.parse( window.localStorage.getItem( CLE_STOCKAGE ) || 'null' );
			if ( donnees && typeof donnees === 'object' ) {
				return {
					meilleur: Math.max( 0, parseInt( donnees.meilleur, 10 ) || 0 ),
					parties: Math.max( 0, parseInt( donnees.parties, 10 ) || 0 ),
				};
			}
		} catch ( e ) {}
		return { meilleur: 0, parties: 0 };
	}

	/* Fusionne des champs dans localStorage['yn.wordend'] (score et son partagent la clé). */
	function fusionnerStockage( champs ) {
		try {
			var donnees = JSON.parse( window.localStorage.getItem( CLE_STOCKAGE ) || 'null' );
			donnees = donnees && typeof donnees === 'object' ? donnees : {};
			Object.keys( champs ).forEach( function ( cle ) {
				donnees[ cle ] = champs[ cle ];
			} );
			window.localStorage.setItem( CLE_STOCKAGE, JSON.stringify( donnees ) );
		} catch ( e ) {}
	}

	function ecrireStockage( meilleurScore ) {
		var donnees = lireStockage();
		fusionnerStockage( {
			meilleur: Math.max( donnees.meilleur, meilleurScore ),
			parties: donnees.parties + 1,
			maj: new Date().toISOString().slice( 0, 10 ),
		} );
	}

	function lireSon() {
		try {
			var donnees = JSON.parse( window.localStorage.getItem( CLE_STOCKAGE ) || 'null' );
			if ( donnees && typeof donnees === 'object' ) {
				var volume = parseFloat( donnees.volume );
				return {
					volume: isFinite( volume ) ? Math.min( 1, Math.max( 0, volume ) ) : 0.5,
					muet: donnees.muet === true,
				};
			}
		} catch ( e ) {}
		return { volume: 0.5, muet: false };
	}

	function lirePalette() {
		var style = window.getComputedStyle( document.documentElement );
		function jeton( nom, secours ) {
			var valeur = style.getPropertyValue( '--wp--preset--color--' + nom ).trim();
			return valeur || secours;
		}
		palette = {
			fond: jeton( 'fond', '#1b1231' ),
			bande: jeton( 'bande', '#241740' ),
			carte: jeton( 'carte', '#2d1f4f' ),
			filet: jeton( 'filet', '#4a3b6e' ),
			texteFort: jeton( 'texte-fort', '#fff8fb' ),
			texte: jeton( 'texte', '#ebe3f2' ),
			texteFaible: jeton( 'texte-faible', '#b7a9cc' ),
			accent: jeton( 'accent', '#f3a6c8' ),
			accent2: jeton( 'accent-2', '#f7c59f' ),
			erreur: jeton( 'erreur', '#ff8f7e' ),
		};
		var titres = style.getPropertyValue( '--wp--preset--font-family--titres' ).trim();
		police = titres || window.getComputedStyle( document.body ).fontFamily || 'sans-serif';
		mouvementReduit = document.documentElement.getAttribute( 'data-yn-animations' ) === 'reduites' ||
			!! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/* ------------------------------------------------------------------ */
	/* Ressources                                                          */
	/* ------------------------------------------------------------------ */

	function chargerImage( url ) {
		return new Promise( function ( resoudre, rejeter ) {
			var img = new Image();
			img.onload = function () {
				resoudre( img );
			};
			img.onerror = function () {
				rejeter( new Error( 'planche introuvable' ) );
			};
			img.src = url;
		} );
	}

	function chargerJson( url ) {
		return window.fetch( url, { credentials: 'same-origin' } ).then( function ( reponse ) {
			if ( ! reponse.ok ) {
				throw new Error( 'métadonnées introuvables' );
			}
			return reponse.json();
		} );
	}

	function chargerRessources() {
		if ( ressources ) {
			return ressources;
		}
		ressources = Promise.all( [
			chargerImage( config.planche ),
			chargerJson( config.meta ),
			chargerImage( config.timere ),
			chargerJson( config.timereMeta ),
			// Le décor est facultatif : sans lui, le ciel dégradé du thème le remplace.
			config.decor ? chargerImage( config.decor ).catch( function () {
				return null;
			} ) : null,
		] ).then(
			function ( resultats ) {
				planche = resultats[ 0 ];
				meta = resultats[ 1 ];
				plancheTimere = resultats[ 2 ];
				metaTimere = resultats[ 3 ];
				imageDecor = resultats[ 4 ];
			},
			function ( erreur ) {
				ressources = null;
				throw erreur;
			}
		);
		return ressources;
	}

	/* ------------------------------------------------------------------ */
	/* Modale                                                              */
	/* ------------------------------------------------------------------ */

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

	function construire() {
		dialogue = element( 'dialog', { class: 'yn-wordend', 'aria-labelledby': 'yn-wordend-titre', 'aria-describedby': 'yn-wordend-aide' } );
		var cadre = element( 'div', { class: 'yn-wordend__cadre' } );

		var entete = element( 'div', { class: 'yn-wordend__entete' } );
		entete.appendChild( element( 'h2', { id: 'yn-wordend-titre', class: 'yn-wordend__titre' }, 'Chtholly – Bats-toi contre ton destin' ) );
		var fermerBouton = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm yn-wordend__fermer', 'aria-label': 'Fermer le jeu' }, '×' );
		fermerBouton.addEventListener( 'click', fermer );
		entete.appendChild( fermerBouton );
		cadre.appendChild( entete );

		ecran = element( 'canvas', {
			class: 'yn-wordend__ecran',
			width: String( LARGEUR * DENSITE ),
			height: String( HAUTEUR * DENSITE ),
			tabindex: '0',
			role: 'img',
			'aria-label': 'Zone de jeu : Chtholly et son épée Seniolis face aux Timeres',
		} );
		ecran.textContent = 'Votre navigateur ne peut pas afficher le jeu.';
		cadre.appendChild( ecran );

		var barre = element( 'div', { class: 'yn-wordend__barre' } );
		boutonJouer = element( 'button', { type: 'button', class: 'yn-btn yn-btn--primary yn-btn--sm' }, 'Jouer' );
		boutonJouer.addEventListener( 'click', function () {
			demarrer();
			ecran.focus();
		} );
		boutonPause = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm', 'aria-pressed': 'false' }, 'Pause' );
		boutonPause.addEventListener( 'click', function () {
			basculerPause();
		} );
		barre.appendChild( boutonJouer );
		barre.appendChild( boutonPause );
		if ( config.musique ) {
			barre.appendChild( construireSon() );
		}
		cadre.appendChild( barre );

		cadre.appendChild( construireManettes() );

		cadre.appendChild(
			element(
				'p',
				{ id: 'yn-wordend-aide', class: 'yn-wordend__aide' },
				'Flèches ou Q/D : marcher · Maj : courir · J ou X : coup d’épée · K ou C maintenu puis relâché : charge magique · P : pause · M : musique · Échap : pause, puis fermer'
			)
		);
		annonce = element( 'p', { class: 'yn-visually-hidden', role: 'status', 'aria-live': 'polite' } );
		cadre.appendChild( annonce );

		dialogue.appendChild( cadre );
		// Échap arrive par keydown (surTouche) ; « cancel » ne sert que si le keydown n'a pas atteint
		// la modale (aucun élément focalisé), pour ne pas traiter deux fois la même touche.
		dialogue.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			if ( ! echapTraite ) {
				echap();
			}
			echapTraite = false;
		} );
		dialogue.addEventListener( 'close', function () {
			if ( ouvert ) {
				fermer();
			}
		} );
		dialogue.addEventListener( 'keydown', surTouche );
		dialogue.addEventListener( 'keyup', surToucheRelachee );
		document.body.appendChild( dialogue );

		ctx = ecran.getContext( '2d' );
	}

	/* Bouton muet et curseur de volume de la musique. */
	function construireSon() {
		var groupe = element( 'div', { class: 'yn-wordend__son', role: 'group', 'aria-label': 'Musique' } );
		boutonMuet = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm yn-wordend__muet', 'aria-pressed': 'false' }, 'Couper la musique' );
		boutonMuet.addEventListener( 'click', function () {
			basculerMuet();
		} );
		var etiquette = element( 'label', { class: 'yn-wordend__volume' } );
		etiquette.appendChild( element( 'span', {}, 'Volume' ) );
		curseurVolume = element( 'input', { type: 'range', min: '0', max: '100', step: '5' } );
		curseurVolume.addEventListener( 'input', function () {
			son.volume = Math.min( 1, Math.max( 0, parseInt( curseurVolume.value, 10 ) / 100 || 0 ) );
			if ( son.volume > 0 && son.muet ) {
				son.muet = false;
			}
			fusionnerStockage( { volume: son.volume, muet: son.muet } );
			mettreAJourSon();
		} );
		etiquette.appendChild( curseurVolume );
		groupe.appendChild( boutonMuet );
		groupe.appendChild( etiquette );
		return groupe;
	}

	function basculerMuet() {
		son.muet = ! son.muet;
		fusionnerStockage( { volume: son.volume, muet: son.muet } );
		mettreAJourSon();
		annoncer( son.muet ? 'Musique coupée.' : 'Musique activée.' );
	}

	/* Musique : jouée seulement pendant une partie, fenêtre ouverte, sans muet ni volume nul. */
	function mettreAJourSon() {
		if ( boutonMuet ) {
			boutonMuet.setAttribute( 'aria-pressed', son.muet ? 'true' : 'false' );
			boutonMuet.textContent = son.muet ? 'Remettre la musique' : 'Couper la musique';
		}
		if ( curseurVolume ) {
			curseurVolume.value = String( Math.round( son.volume * 100 ) );
			curseurVolume.setAttribute( 'aria-valuetext', Math.round( son.volume * 100 ) + ' %' + ( son.muet ? ', musique coupée' : '' ) );
		}
		if ( ! config || ! config.musique ) {
			return;
		}
		var jouer = ouvert && etat === 'jeu' && ! son.muet && son.volume > 0;
		if ( jouer && ! musique ) {
			musique = new Audio( config.musique );
			musique.loop = true;
			musique.preload = 'auto';
		}
		if ( ! musique ) {
			return;
		}
		musique.volume = son.volume;
		if ( jouer && musique.paused ) {
			var promesse = musique.play();
			if ( promesse && promesse.catch ) {
				promesse.catch( function () {} ); // Lecture refusée par le navigateur : silence.
			}
		} else if ( ! jouer && ! musique.paused ) {
			musique.pause();
		}
	}

	function construireManettes() {
		var manettes = element( 'div', { class: 'yn-wordend__manettes', role: 'group', 'aria-label': 'Commandes tactiles' } );
		var commandes = [
			{ nom: 'gauche', texte: '◀', libelle: 'Aller à gauche' },
			{ nom: 'droite', texte: '▶', libelle: 'Aller à droite' },
			{ nom: 'courir', texte: 'Courir', libelle: 'Courir', bascule: true },
			{ nom: 'epee', texte: 'Épée', libelle: 'Coup d’épée' },
			{ nom: 'charge', texte: 'Charge', libelle: 'Charge magique (maintenir puis relâcher)' },
		];
		commandes.forEach( function ( commande ) {
			var attributs = { type: 'button', class: 'yn-btn yn-wordend__manette', 'aria-label': commande.libelle };
			if ( commande.bascule ) {
				attributs[ 'aria-pressed' ] = 'false';
			}
			var bouton = element( 'button', attributs, commande.texte );
			if ( commande.bascule ) {
				bouton.addEventListener( 'click', function () {
					courirTactile = ! courirTactile;
					bouton.setAttribute( 'aria-pressed', courirTactile ? 'true' : 'false' );
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
					if ( commande.nom === 'epee' ) {
						appuiEpee = true;
					}
					if ( etat === 'titre' || etat === 'fin' ) {
						demarrer();
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
					if ( e.detail === 0 && commande.nom === 'epee' ) {
						appuiEpee = true;
					}
				} );
			}
			manettes.appendChild( bouton );
		} );
		return manettes;
	}

	function ouvrir( configuration ) {
		config = configuration || config;
		if ( ! config ) {
			return;
		}
		if ( ! dialogue ) {
			construire();
		}
		if ( ouvert ) {
			return;
		}
		ouvert = true;
		focusAvant = document.activeElement;
		lirePalette();
		meilleur = lireStockage().meilleur;
		son = lireSon();
		document.documentElement.classList.add( 'yn-wordend-ouvert' );
		if ( typeof dialogue.showModal === 'function' ) {
			dialogue.showModal();
		} else {
			dialogue.setAttribute( 'open', '' );
		}
		ecran.focus();
		document.addEventListener( 'visibilitychange', surVisibilite );
		window.addEventListener( 'blur', surPerteFocus );
		document.addEventListener( 'yn:theme', lirePalette );
		document.dispatchEvent( new CustomEvent( 'yn:wordend', { detail: { etat: 'ouvert' } } ) );

		etat = 'chargement';
		mettreAJourBoutons();
		chargerRessources().then(
			function () {
				if ( etat === 'chargement' ) {
					etat = 'titre';
					preparerPartie();
					mettreAJourBoutons();
					annoncer( 'WordEnd est prêt. Appuyez sur Entrée ou sur Jouer pour commencer.' );
				}
			},
			function () {
				etat = 'erreur';
				mettreAJourBoutons();
				annoncer( 'Le jeu n’a pas pu être chargé.' );
			}
		);
		dernierTemps = 0;
		requete = window.requestAnimationFrame( boucle );
	}

	function fermer() {
		if ( ! ouvert ) {
			return;
		}
		ouvert = false;
		window.cancelAnimationFrame( requete );
		if ( etat === 'jeu' ) {
			etat = 'pause';
		}
		clavier = {};
		tactile = {};
		document.removeEventListener( 'visibilitychange', surVisibilite );
		window.removeEventListener( 'blur', surPerteFocus );
		document.removeEventListener( 'yn:theme', lirePalette );
		if ( dialogue.open ) {
			if ( typeof dialogue.close === 'function' ) {
				dialogue.close();
			} else {
				dialogue.removeAttribute( 'open' );
			}
		}
		document.documentElement.classList.remove( 'yn-wordend-ouvert' );
		mettreAJourSon();
		if ( focusAvant && typeof focusAvant.focus === 'function' && document.contains( focusAvant ) ) {
			focusAvant.focus();
		}
		focusAvant = null;
		document.dispatchEvent( new CustomEvent( 'yn:wordend', { detail: { etat: 'ferme' } } ) );
	}

	function mettreAJourBoutons() {
		if ( ! boutonJouer ) {
			return;
		}
		boutonJouer.textContent = etat === 'fin' || etat === 'jeu' || etat === 'pause' ? 'Rejouer' : 'Jouer';
		boutonJouer.disabled = etat === 'chargement' || etat === 'erreur';
		boutonPause.disabled = etat !== 'jeu' && etat !== 'pause';
		boutonPause.setAttribute( 'aria-pressed', etat === 'pause' ? 'true' : 'false' );
		boutonPause.textContent = etat === 'pause' ? 'Reprendre' : 'Pause';
		mettreAJourSon();
	}

	/* ------------------------------------------------------------------ */
	/* Entrées                                                             */
	/* ------------------------------------------------------------------ */

	/* Échap : met en pause une partie en cours ; sinon (pause, titre, fin) ferme le jeu. */
	function echap() {
		if ( etat === 'jeu' ) {
			basculerPause();
			annoncer( 'Pause. Échap de nouveau pour fermer le jeu, P ou Entrée pour reprendre.' );
		} else {
			fermer();
		}
	}

	function surTouche( e ) {
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			echapTraite = true;
			window.setTimeout( function () {
				echapTraite = false;
			}, 0 );
			echap();
			return;
		}
		if ( e.key === 'Tab' || e.altKey || e.ctrlKey || e.metaKey ) {
			return;
		}
		if ( e.target && e.target.tagName === 'INPUT' ) {
			e.stopPropagation(); // Curseur de volume : flèches et Début/Fin gardent leur rôle.
			return;
		}
		var surBouton = e.target && e.target.tagName === 'BUTTON';
		if ( surBouton && ( e.key === 'Enter' || e.key === ' ' ) ) {
			return; // Laisse le bouton agir.
		}
		// Les raccourcis de la page (lecteur, thème) ne voient pas les touches du jeu.
		e.stopPropagation();

		var action = TOUCHES[ e.code ];
		// M et P se lisent sur la lettre tapée (e.key), pas sur la position de la touche : en
		// AZERTY, le M est à la place du « ; » du QWERTY (e.code = Semicolon).
		var lettre = String( e.key || '' ).toLowerCase();
		if ( lettre === 'm' && config.musique ) {
			e.preventDefault();
			if ( ! e.repeat ) {
				basculerMuet();
			}
			return;
		}
		if ( lettre === 'p' ) {
			e.preventDefault();
			if ( ! e.repeat ) {
				basculerPause();
			}
			return;
		}
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			if ( etat === 'titre' || etat === 'fin' ) {
				demarrer();
			} else if ( etat === 'pause' ) {
				basculerPause();
			}
			return;
		}
		if ( action ) {
			e.preventDefault();
			if ( action === 'epee' && ! e.repeat ) {
				appuiEpee = true;
				if ( etat === 'titre' ) {
					demarrer();
				}
			}
			clavier[ action ] = true;
		}
	}

	function surToucheRelachee( e ) {
		var action = TOUCHES[ e.code ];
		if ( action ) {
			clavier[ action ] = false;
		}
	}

	function commande( nom ) {
		return !! ( clavier[ nom ] || tactile[ nom ] );
	}

	function surVisibilite() {
		if ( document.hidden && etat === 'jeu' ) {
			basculerPause();
		}
	}

	function surPerteFocus() {
		clavier = {};
		if ( etat === 'jeu' ) {
			basculerPause();
		}
	}

	function basculerPause() {
		if ( etat === 'jeu' ) {
			etat = 'pause';
			clavier = {};
			annoncer( 'Pause.' );
		} else if ( etat === 'pause' ) {
			etat = 'jeu';
			annoncer( 'Reprise.' );
		}
		mettreAJourBoutons();
	}

	/* ------------------------------------------------------------------ */
	/* Partie                                                              */
	/* ------------------------------------------------------------------ */

	function preparerPartie() {
		joueur = {
			x: LARGEUR / 2,
			y: SOL_Y,
			vx: 0,
			dir: 1,
			etat: 'repos',
			t: 0,
			pv: PV_MAX,
			invincible: 0,
			recharge: 0,
			touches: [],
		};
		timeres = [];
		ondes = [];
		particules = [];
		score = 0;
		secousse = 0;
		vague = { numero: 0, aFaire: 0, minuterie: 1.2, banniere: 0, pause: true };
	}

	function demarrer() {
		if ( etat === 'chargement' || etat === 'erreur' ) {
			return;
		}
		preparerPartie();
		etat = 'jeu';
		appuiEpee = false;
		mettreAJourBoutons();
		annoncer( 'Partie commencée. Défendez l’île contre les Timeres !' );
	}

	function terminer() {
		etat = 'fin';
		var record = score > meilleur;
		meilleur = Math.max( meilleur, score );
		ecrireStockage( score );
		mettreAJourBoutons();
		annoncer( 'Fin de partie. Score : ' + score + ( record ? '. Nouveau record !' : '. Meilleur score : ' + meilleur + '.' ) + ' Appuyez sur Entrée pour rejouer.' );
	}

	function nouvelleVague() {
		vague.numero++;
		vague.aFaire = 3 + 2 * vague.numero;
		vague.minuterie = 0.8;
		vague.banniere = 2;
		vague.pause = false;
		annoncer( 'Vague ' + vague.numero + ' : ' + vague.aFaire + ' Timeres.' );
	}

	function faireApparaitre() {
		var n = vague.numero;
		var choix = [ 'petit', 'petit', 'normal', 'normal' ];
		if ( n >= 2 ) {
			choix.push( 'coureur', 'normal' );
		}
		if ( n >= 3 && ! timeres.some( function ( t ) {
			return t.type === 'grand' && t.etat !== 'mort';
		} ) ) {
			choix.push( 'grand' );
		}
		var type = choix[ Math.floor( Math.random() * choix.length ) ];
		var modele = TYPES[ type ];
		var gauche = Math.random() < 0.5;
		timeres.push( {
			id: prochainId++,
			type: type,
			modele: modele,
			taille: modele.taille * hasard( 0.94, 1.06 ),
			x: gauche ? -50 : LARGEUR + 50,
			dir: gauche ? 1 : -1,
			etat: modele.course ? 'course' : 'marche',
			t: hasard( 0, 1 ),
			attaque: '',
			pv: modele.pv + ( n >= 6 && type === 'normal' ? 1 : 0 ),
			vitesse: modele.vitesse * ( 1 + Math.min( 0.5, n * 0.04 ) ),
			points: modele.points,
			recharge: hasard( 0.2, 0.8 ),
			flash: 0,
			recul: 0,
			touche: false,
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Mise à jour                                                         */
	/* ------------------------------------------------------------------ */

	function animation( nom ) {
		return meta.animations[ nom ];
	}

	function imageCourante( nom, t ) {
		var anim = animation( nom );
		var n = anim.images.length;
		var i = Math.floor( t * anim.ips );
		return anim.boucle ? i % n : Math.min( n - 1, i );
	}

	function boiteJoueur() {
		return { x: joueur.x - 10, y: joueur.y - 58, l: 20, h: 56 };
	}

	function boiteEpee() {
		return joueur.dir > 0 ?
			{ x: joueur.x + 4, y: joueur.y - 62, l: 60, h: 58 } :
			{ x: joueur.x - 64, y: joueur.y - 62, l: 60, h: 58 };
	}

	function changerEtat( nouvel ) {
		joueur.etat = nouvel;
		joueur.t = 0;
	}

	function mettreAJourJoueur( dt ) {
		var j = joueur;
		j.t += dt;
		j.invincible = Math.max( 0, j.invincible - dt );
		j.recharge = Math.max( 0, j.recharge - dt );
		var libre = j.etat === 'repos' || j.etat === 'marche' || j.etat === 'course';

		if ( j.etat === 'mort' ) {
			j.vx *= 0.9;
			if ( j.t >= DUREE_MORT ) {
				terminer();
			}
		} else if ( j.etat === 'degats' ) {
			j.vx *= 0.88;
			if ( j.t >= DUREE_DEGATS ) {
				changerEtat( 'repos' );
			}
		} else if ( j.etat === 'attaque' ) {
			j.vx = 0;
			var i = imageCourante( 'attaque', j.t );
			if ( animation( 'attaque' ).coup.indexOf( i ) !== -1 ) {
				frapper( boiteEpee(), 1, j.touches );
			}
			if ( j.t >= animation( 'attaque' ).images.length / animation( 'attaque' ).ips ) {
				changerEtat( 'repos' );
			}
		} else if ( j.etat === 'concentration' ) {
			j.vx = 0;
			if ( ! commande( 'charge' ) ) {
				if ( j.t >= CHARGE_MIN ) {
					changerEtat( 'onde' );
					lancerOnde();
				} else {
					changerEtat( 'repos' );
				}
			} else if ( j.t >= CHARGE_MIN && ! mouvementReduit && Math.random() < 0.5 ) {
				particule( j.x + j.dir * hasard( 10, 40 ), j.y - hasard( 30, 60 ), hasard( -20, 20 ), hasard( -40, -10 ), 0.4, '#bfe6ff', 2 );
			}
		} else if ( j.etat === 'onde' ) {
			j.vx = 0;
			if ( j.t >= DUREE_ONDE ) {
				changerEtat( 'repos' );
			}
		}

		if ( libre ) {
			if ( appuiEpee ) {
				j.touches = [];
				changerEtat( 'attaque' );
			} else if ( commande( 'charge' ) && j.recharge <= 0 ) {
				changerEtat( 'concentration' );
			} else {
				var sens = ( commande( 'droite' ) ? 1 : 0 ) - ( commande( 'gauche' ) ? 1 : 0 );
				var court = commande( 'courir' ) || courirTactile;
				if ( sens !== 0 ) {
					j.dir = sens;
					j.vx = sens * ( court ? VITESSE_COURSE : VITESSE_MARCHE );
					var voulu = court ? 'course' : 'marche';
					if ( j.etat !== voulu ) {
						changerEtat( voulu );
					}
				} else {
					j.vx = 0;
					if ( j.etat !== 'repos' ) {
						changerEtat( 'repos' );
					}
				}
			}
		}
		appuiEpee = false;

		j.x = Math.max( BORD, Math.min( LARGEUR - BORD, j.x + j.vx * dt ) );
	}

	function lancerOnde() {
		var j = joueur;
		j.recharge = RECHARGE_ONDE;
		ondes.push( { x: j.x + j.dir * 40, y: j.y - 34, dir: j.dir, vie: 1.1, t: 0, touches: [] } );
		secouer( 0.12 );
	}

	function frapper( boite, degats, dejaTouches ) {
		timeres.forEach( function ( t ) {
			if ( t.etat !== 'mort' && dejaTouches.indexOf( t.id ) === -1 && chevauche( boite, boiteTimere( t ) ) ) {
				dejaTouches.push( t.id );
				blesserTimere( t, degats, boite.x + boite.l / 2 < t.x ? 1 : -1 );
			}
		} );
	}

	/* Corps d'un Timere (ancre au sol, au milieu des pattes). */
	function boiteTimere( t ) {
		var l = 46 * t.taille;
		var h = 44 * t.taille;
		return { x: t.x - l / 2, y: SOL_Y - h, l: l, h: h };
	}

	/* Zone touchée par l'attaque en cours d'un Timere. */
	function boiteAttaque( t ) {
		var portee = PORTEE[ t.attaque ] * t.taille;
		var debut = 6 * t.taille;
		var h = ( t.attaque === 'fouet' ? 36 : 34 ) * t.taille;
		return {
			x: t.dir > 0 ? t.x + debut : t.x - debut - portee,
			y: SOL_Y - h - 4 * t.taille,
			l: portee,
			h: h,
		};
	}

	function animationTimere( nom ) {
		return metaTimere.animations[ nom ];
	}

	function imageTimere( nom, t ) {
		var anim = animationTimere( nom );
		var n = anim.images.length;
		var i = Math.floor( t * anim.ips );
		return anim.boucle ? i % n : Math.min( n - 1, i );
	}

	function dureeTimere( nom ) {
		var anim = animationTimere( nom );
		return anim.images.length / anim.ips;
	}

	function etatTimere( t, etat ) {
		t.etat = etat;
		t.t = 0;
	}

	function blesserTimere( t, degats, sens ) {
		var centre = SOL_Y - 22 * t.taille;
		t.pv -= degats;
		t.flash = 0.1;
		var n = mouvementReduit ? 2 : 6;
		for ( var i = 0; i < n; i++ ) {
			particule( t.x, centre, sens * hasard( 20, 120 ), hasard( -120, -20 ), 0.4, '#bfe6ff', 2 );
		}
		if ( t.pv <= 0 ) {
			score += t.points;
			etatTimere( t, 'mort' );
			t.recul = sens * 90;
			var m = mouvementReduit ? 4 : 12;
			for ( var k = 0; k < m; k++ ) {
				particule( t.x + hasard( -16, 16 ) * t.taille, centre + hasard( -14, 14 ) * t.taille, hasard( -60, 60 ), hasard( -110, -10 ), hasard( 0.4, 0.9 ), k % 3 ? '#36502a' : '#eae26e', hasard( 2, 3 ) );
			}
			return;
		}
		// Le grand Timere ne recule que sous l'onde magique.
		if ( ! t.modele.stoique || degats > 1 ) {
			t.recul = sens * 170;
			etatTimere( t, 'degats' );
		}
	}

	function mettreAJourTimeres( dt ) {
		var j = joueur;
		var cible = boiteJoueur();
		timeres.forEach( function ( t ) {
			t.t += dt;
			t.flash = Math.max( 0, t.flash - dt );
			t.recharge = Math.max( 0, t.recharge - dt );
			t.recul *= 0.86;
			var vx = 0;
			var distance = Math.abs( j.x - t.x );
			var fuite = j.etat === 'mort';

			if ( t.etat === 'mort' ) {
				t.x += t.recul * dt;
				return;
			}
			if ( t.etat === 'degats' ) {
				if ( t.t >= Math.min( 0.45, dureeTimere( 'degats' ) ) ) {
					etatTimere( t, 'repos' );
				}
			} else if ( t.etat === 'attaque' ) {
				var i = imageTimere( t.attaque, t.t );
				if ( ! t.touche && animationTimere( t.attaque ).coup.indexOf( i ) !== -1 && j.etat !== 'mort' && j.invincible <= 0 && chevauche( cible, boiteAttaque( t ) ) ) {
					t.touche = true;
					blesserJoueur( t.dir );
				}
				if ( t.t >= dureeTimere( t.attaque ) ) {
					etatTimere( t, 'repos' );
					t.recharge = hasard( 0.8, 1.5 ) * ( t.type === 'grand' ? 1.4 : 1 );
				}
			} else {
				// Approche, attente ou attaque.
				if ( ! fuite ) {
					t.dir = j.x > t.x ? 1 : -1;
				} else {
					t.dir = t.x < LARGEUR / 2 ? -1 : 1;
				}
				var attaque = t.modele.attaques[ Math.floor( Math.random() * t.modele.attaques.length ) ];
				var portee = PORTEE[ t.modele.attaques[ 0 ] ] * t.taille + 6;
				// Pas d'attaque depuis l'extérieur de l'écran : l'ancre (milieu des pattes) doit
				// être dans le cadre, donc au moins la moitié du corps visible.
				var visible = t.x > 4 && t.x < LARGEUR - 4;
				if ( ! fuite && visible && distance <= portee + 4 && t.recharge <= 0 ) {
					t.attaque = distance > PORTEE.morsure * t.taille + 6 && t.modele.attaques.indexOf( 'fouet' ) !== -1 ? 'fouet' : attaque;
					t.touche = false;
					etatTimere( t, 'attaque' );
				} else if ( fuite || distance > portee || ! visible ) {
					var court = t.modele.course && distance > 50;
					var voulu = court ? 'course' : 'marche';
					if ( t.etat !== voulu ) {
						etatTimere( t, voulu );
					}
					vx = t.dir * ( court ? t.vitesse : Math.min( t.vitesse, 46 ) );
				} else if ( t.etat !== 'repos' ) {
					etatTimere( t, 'repos' );
				}
			}
			t.x += ( vx + t.recul ) * dt;
		} );
		// Les Timeres ne se superposent pas tout à fait.
		timeres.forEach( function ( a, ia ) {
			timeres.forEach( function ( b, ib ) {
				if ( ib <= ia || a.etat === 'mort' || b.etat === 'mort' ) {
					return;
				}
				var ecart = ( 18 * ( a.taille + b.taille ) ) - Math.abs( a.x - b.x );
				if ( ecart > 0 ) {
					var sens = a.x < b.x ? -1 : 1;
					a.x += sens * ecart * 0.25;
					b.x -= sens * ecart * 0.25;
				}
			} );
		} );
		timeres = timeres.filter( function ( t ) {
			var fini = t.etat === 'mort' && t.t > dureeTimere( 'mort' ) + DUREE_FONDU;
			return ! fini && t.x > -140 && t.x < LARGEUR + 140;
		} );
	}

	function blesserJoueur( sens ) {
		var j = joueur;
		j.pv--;
		j.invincible = INVINCIBILITE;
		j.vx = sens * RECUL;
		secouer( 0.25 );
		if ( j.pv <= 0 ) {
			changerEtat( 'mort' );
			annoncer( 'Chtholly est à terre.' );
			var n = mouvementReduit ? 6 : 24;
			for ( var i = 0; i < n; i++ ) {
				particule( j.x + hasard( -30, 30 ), j.y - hasard( 0, 50 ), hasard( -30, 30 ), hasard( -50, -10 ), hasard( 1.2, 2.2 ), i % 2 ? palette.accent : '#8fd0ff', 3, true );
			}
		} else {
			changerEtat( 'degats' );
		}
	}

	function mettreAJourOndes( dt ) {
		ondes.forEach( function ( o ) {
			o.t += dt;
			o.vie -= dt;
			o.x += o.dir * 250 * dt;
			frapper( { x: o.x - 14, y: o.y - 26, l: 28, h: 52 }, 3, o.touches );
			if ( ! mouvementReduit && Math.random() < 0.6 ) {
				particule( o.x - o.dir * 10, o.y + hasard( -20, 20 ), -o.dir * hasard( 10, 40 ), hasard( -20, 20 ), 0.35, '#d8f1ff', 2 );
			}
		} );
		ondes = ondes.filter( function ( o ) {
			return o.vie > 0 && o.x > -40 && o.x < LARGEUR + 40;
		} );
	}

	function particule( x, y, vx, vy, vie, couleur, taille, flotte ) {
		if ( particules.length > 300 ) {
			return;
		}
		particules.push( { x: x, y: y, vx: vx, vy: vy, vie: vie, max: vie, couleur: couleur, taille: taille, flotte: !! flotte } );
	}

	function mettreAJourParticules( dt ) {
		particules.forEach( function ( p ) {
			p.vie -= dt;
			p.x += p.vx * dt;
			p.y += p.vy * dt;
			p.vy += ( p.flotte ? 8 : 260 ) * dt;
		} );
		particules = particules.filter( function ( p ) {
			return p.vie > 0;
		} );
	}

	function mettreAJourVague( dt ) {
		vague.banniere = Math.max( 0, vague.banniere - dt );
		if ( joueur.etat === 'mort' ) {
			return;
		}
		vague.minuterie -= dt;
		if ( vague.pause ) {
			if ( vague.minuterie <= 0 ) {
				nouvelleVague();
			}
			return;
		}
		if ( vague.aFaire > 0 && vague.minuterie <= 0 ) {
			faireApparaitre();
			vague.aFaire--;
			vague.minuterie = Math.max( 0.5, 2.2 - 0.15 * vague.numero ) * hasard( 0.7, 1.2 );
		}
		if ( vague.aFaire === 0 && timeres.length === 0 ) {
			score += 50 * vague.numero;
			vague.pause = true;
			vague.minuterie = 1.8;
			if ( joueur.pv < PV_MAX && vague.numero % 2 === 0 ) {
				joueur.pv++;
			}
		}
	}

	function secouer( duree ) {
		if ( ! mouvementReduit ) {
			secousse = Math.max( secousse, duree );
		}
	}

	function mettreAJour( dt ) {
		temps += dt;
		if ( etat === 'jeu' ) {
			mettreAJourJoueur( dt );
			mettreAJourTimeres( dt );
			mettreAJourOndes( dt );
			mettreAJourVague( dt );
			secousse = Math.max( 0, secousse - dt );
		}
		if ( etat === 'jeu' || etat === 'fin' || etat === 'titre' ) {
			mettreAJourParticules( dt );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Rendu                                                               */
	/* ------------------------------------------------------------------ */

	function dessinerDecor() {
		if ( imageDecor ) {
			// Décor peint (960 × 540, soit l'écran en 2×).
			ctx.drawImage( imageDecor, 0, 0, LARGEUR, HAUTEUR );
			return;
		}
		// Secours (décor non chargé) : ciel dégradé et sol aux couleurs du thème.
		var ciel = ctx.createLinearGradient( 0, 0, 0, SOL_Y );
		ciel.addColorStop( 0, palette.fond );
		ciel.addColorStop( 1, palette.bande );
		ctx.fillStyle = ciel;
		ctx.fillRect( 0, 0, LARGEUR, HAUTEUR );
		ctx.fillStyle = palette.carte;
		ctx.fillRect( 0, SOL_Y, LARGEUR, HAUTEUR - SOL_Y );
		ctx.fillStyle = palette.filet;
		ctx.fillRect( 0, SOL_Y, LARGEUR, 2 );
	}

	function dessinerSprite( nom, i, x, y, dir ) {
		var cadre = animation( nom ).images[ i ];
		var echelle = meta.echelle || DENSITE;
		ctx.save();
		ctx.translate( Math.round( x * DENSITE ) / DENSITE, Math.round( y * DENSITE ) / DENSITE );
		if ( dir < 0 ) {
			ctx.scale( -1, 1 );
		}
		ctx.drawImage( planche, cadre[ 0 ], cadre[ 1 ], cadre[ 2 ], cadre[ 3 ], -cadre[ 4 ] / echelle, -cadre[ 5 ] / echelle, cadre[ 2 ] / echelle, cadre[ 3 ] / echelle );
		ctx.restore();
	}

	function dessinerJoueur() {
		var j = joueur;
		var nom = j.etat;
		var i = 0;
		if ( nom === 'concentration' ) {
			nom = 'charge';
			if ( j.t < 0.2 ) {
				i = 0;
			} else if ( j.t < CHARGE_MIN ) {
				i = 1;
			} else {
				i = mouvementReduit || Math.floor( j.t * 8 ) % 2 ? 2 : 1;
			}
		} else if ( nom === 'onde' ) {
			nom = 'charge';
			i = 3;
		} else {
			i = imageCourante( nom, j.t );
		}
		// Ombre au sol.
		ctx.fillStyle = 'rgba(0,0,0,0.25)';
		ctx.beginPath();
		ctx.ellipse( j.x, SOL_Y + 1, nom === 'mort' ? 34 : 18, 4, 0, 0, Math.PI * 2 );
		ctx.fill();

		if ( j.invincible > 0 && nom !== 'mort' ) {
			ctx.globalAlpha = mouvementReduit ? 0.6 : ( Math.floor( j.invincible * 12 ) % 2 ? 0.35 : 1 );
		}
		dessinerSprite( nom, i, j.x, j.y, j.dir );
		ctx.globalAlpha = 1;

		if ( j.etat === 'concentration' ) {
			var part = Math.min( 1, j.t / CHARGE_MIN );
			ctx.fillStyle = palette.filet;
			ctx.fillRect( j.x - 16, j.y - 82, 32, 4 );
			ctx.fillStyle = part >= 1 ? '#8fd0ff' : palette.texteFaible;
			ctx.fillRect( j.x - 16, j.y - 82, 32 * part, 4 );
		}
	}

	function dessinerTimere( t ) {
		var nom = t.etat === 'attaque' ? t.attaque : t.etat;
		var cadre = animationTimere( nom ).images[ imageTimere( nom, t.t ) ];
		var echelle = ( metaTimere.echelle || DENSITE ) / t.taille;
		var alpha = 1;
		if ( t.etat === 'mort' ) {
			alpha = Math.max( 0, 1 - Math.max( 0, t.t - dureeTimere( 'mort' ) ) / DUREE_FONDU );
		}
		ctx.fillStyle = 'rgba(0,0,0,0.22)';
		ctx.beginPath();
		ctx.ellipse( t.x, SOL_Y + 1, 28 * t.taille, 3, 0, 0, Math.PI * 2 );
		ctx.fill();

		ctx.save();
		ctx.globalAlpha = alpha;
		ctx.translate( Math.round( t.x * DENSITE ) / DENSITE, SOL_Y );
		if ( t.dir < 0 ) {
			ctx.scale( -1, 1 );
		}
		ctx.imageSmoothingEnabled = false; // Pixel art.
		ctx.drawImage( plancheTimere, cadre[ 0 ], cadre[ 1 ], cadre[ 2 ], cadre[ 3 ], -cadre[ 4 ] / echelle, -cadre[ 5 ] / echelle, cadre[ 2 ] / echelle, cadre[ 3 ] / echelle );
		if ( t.flash > 0 ) {
			// Éclat blanc : même image, en mode « lighter ».
			ctx.globalCompositeOperation = 'lighter';
			ctx.globalAlpha = 0.7;
			ctx.drawImage( plancheTimere, cadre[ 0 ], cadre[ 1 ], cadre[ 2 ], cadre[ 3 ], -cadre[ 4 ] / echelle, -cadre[ 5 ] / echelle, cadre[ 2 ] / echelle, cadre[ 3 ] / echelle );
		}
		ctx.restore();
	}

	function dessinerOnde( o ) {
		var alpha = Math.min( 1, o.vie * 3 );
		ctx.save();
		ctx.translate( o.x, o.y );
		ctx.scale( o.dir, 1 );
		ctx.globalAlpha = alpha;
		for ( var k = 0; k < 3; k++ ) {
			ctx.strokeStyle = k === 0 ? '#e8f7ff' : ( k === 1 ? '#9fdcff' : '#5aa9e6' );
			ctx.lineWidth = 5 - k * 1.5;
			ctx.beginPath();
			ctx.arc( -10 - k * 6, 0, 26 - k * 2, -1.1, 1.1 );
			ctx.stroke();
		}
		ctx.restore();
		ctx.globalAlpha = 1;
	}

	function dessinerParticules() {
		particules.forEach( function ( p ) {
			ctx.globalAlpha = Math.max( 0, p.vie / p.max );
			ctx.fillStyle = p.couleur;
			ctx.fillRect( p.x - p.taille / 2, p.y - p.taille / 2, p.taille, p.taille );
		} );
		ctx.globalAlpha = 1;
	}

	function texte( contenu, x, y, taille, alignement, couleur, graisse ) {
		ctx.font = ( graisse || 700 ) + ' ' + taille + 'px ' + police;
		ctx.textAlign = alignement || 'left';
		ctx.textBaseline = 'middle';
		ctx.lineJoin = 'round';
		ctx.lineWidth = 3;
		ctx.strokeStyle = palette.fond;
		ctx.strokeText( contenu, x, y );
		ctx.fillStyle = couleur || palette.texteFort;
		ctx.fillText( contenu, x, y );
	}

	function coeur( x, y, plein ) {
		ctx.fillStyle = plein ? palette.accent : palette.filet;
		ctx.beginPath();
		ctx.moveTo( x, y + 3 );
		ctx.bezierCurveTo( x, y, x - 5, y - 1, x - 5, y + 2 );
		ctx.bezierCurveTo( x - 5, y + 5, x - 1, y + 7, x, y + 9 );
		ctx.bezierCurveTo( x + 1, y + 7, x + 5, y + 5, x + 5, y + 2 );
		ctx.bezierCurveTo( x + 5, y - 1, x, y, x, y + 3 );
		ctx.fill();
	}

	function dessinerInterface() {
		for ( var i = 0; i < PV_MAX; i++ ) {
			coeur( 16 + i * 13, 10, i < joueur.pv );
		}
		texte( 'Score ' + score, LARGEUR / 2, 15, 12, 'center' );
		texte( 'Record ' + Math.max( meilleur, score ), LARGEUR - 10, 15, 11, 'right', palette.texteFaible );
		if ( vague.numero > 0 ) {
			texte( 'Vague ' + vague.numero, LARGEUR - 10, 30, 11, 'right', palette.texteFaible );
		}
		if ( joueur.recharge > 0 ) {
			ctx.fillStyle = palette.filet;
			ctx.fillRect( 16, 26, 60, 3 );
			ctx.fillStyle = '#8fd0ff';
			ctx.fillRect( 16, 26, 60 * ( 1 - joueur.recharge / RECHARGE_ONDE ), 3 );
		}
		if ( vague.banniere > 0 && etat === 'jeu' ) {
			var decalage = mouvementReduit ? 0 : Math.max( 0, vague.banniere - 1.7 ) * 200;
			ctx.globalAlpha = Math.min( 1, vague.banniere * 2 );
			texte( 'Vague ' + vague.numero, LARGEUR / 2 - decalage, 96, 26, 'center', palette.accent, 800 );
			ctx.globalAlpha = 1;
		}
	}

	function voile() {
		ctx.globalAlpha = 0.72;
		ctx.fillStyle = palette.fond;
		ctx.fillRect( 0, 0, LARGEUR, HAUTEUR );
		ctx.globalAlpha = 1;
	}

	function dessinerSurcouche() {
		if ( etat === 'titre' ) {
			voile();
			texte( 'WordEnd', LARGEUR / 2, 62, 34, 'center', palette.accent, 800 );
			texte( 'Chtholly – Bats-toi contre ton destin', LARGEUR / 2, 94, 15, 'center' );
			dessinerSprite( 'repos', imageCourante( 'repos', temps ), LARGEUR / 2 - 20, 196, 1 );
			texte( 'Entrée ou « Jouer » pour commencer', LARGEUR / 2, 222, 12, 'center' );
			texte( 'Record : ' + meilleur, LARGEUR / 2, 244, 11, 'center', palette.texteFaible );
		} else if ( etat === 'pause' ) {
			voile();
			texte( 'Pause', LARGEUR / 2, HAUTEUR / 2 - 10, 28, 'center', palette.accent, 800 );
			texte( 'P ou Entrée pour reprendre · Échap pour fermer', LARGEUR / 2, HAUTEUR / 2 + 18, 12, 'center' );
		} else if ( etat === 'fin' ) {
			voile();
			texte( 'Fin de partie', LARGEUR / 2, 86, 28, 'center', palette.accent, 800 );
			texte( 'Score : ' + score, LARGEUR / 2, 122, 16, 'center' );
			texte( score >= meilleur && score > 0 ? 'Nouveau record !' : 'Record : ' + meilleur, LARGEUR / 2, 146, 12, 'center', palette.accent2 );
			texte( 'Entrée ou « Rejouer » pour recommencer', LARGEUR / 2, 184, 12, 'center' );
		} else if ( etat === 'chargement' ) {
			texte( 'Chargement…', LARGEUR / 2, HAUTEUR / 2, 14, 'center' );
		} else if ( etat === 'erreur' ) {
			texte( 'Le jeu n’a pas pu être chargé.', LARGEUR / 2, HAUTEUR / 2, 14, 'center', palette.erreur );
		}
	}

	function dessiner() {
		ctx.setTransform( DENSITE, 0, 0, DENSITE, 0, 0 );
		ctx.imageSmoothingEnabled = true;
		if ( secousse > 0 ) {
			ctx.translate( hasard( -2, 2 ), hasard( -2, 2 ) );
		}
		dessinerDecor();
		if ( planche && meta && plancheTimere && metaTimere && joueur ) {
			timeres.forEach( dessinerTimere );
			if ( etat !== 'titre' ) {
				dessinerJoueur();
			}
			ondes.forEach( dessinerOnde );
			dessinerParticules();
			if ( etat !== 'titre' ) {
				dessinerInterface();
			}
		}
		dessinerSurcouche();
	}

	function boucle( maintenant ) {
		if ( ! ouvert ) {
			return;
		}
		if ( ! dernierTemps ) {
			dernierTemps = maintenant;
		}
		accumulateur += Math.min( 0.25, ( maintenant - dernierTemps ) / 1000 );
		dernierTemps = maintenant;
		var pas = 0;
		while ( accumulateur >= DT && pas < 5 ) {
			if ( planche && meta && joueur ) {
				mettreAJour( DT );
			}
			accumulateur -= DT;
			pas++;
		}
		if ( pas === 5 ) {
			accumulateur = 0;
		}
		dessiner();
		requete = window.requestAnimationFrame( boucle );
	}

	window.ynWordEndJeu = {
		ouvrir: ouvrir,
		fermer: fermer,
		estOuvert: function () {
			return ouvert;
		},
	};
}() );
