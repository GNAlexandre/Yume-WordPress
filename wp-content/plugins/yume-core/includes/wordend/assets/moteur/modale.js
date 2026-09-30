/**
 * WordEnd — moteur : modale <dialog>, boutons, accessibilité, boucle à pas fixe, orchestration.
 *
 * - showModal (le reste de la page est inerte). Échap met la partie en pause, un second Échap
 *   (ou Échap hors partie) ferme ; le focus revient à l'élément d'origine. Événement document
 *   « yn:wordend » (detail.etat = ouvert|ferme, detail.univers = slug).
 * - Pas fixe de 1/60 s ; pause quand l'onglet est masqué ou que la fenêtre perd le focus.
 * - Musique pendant la partie seulement (bouton, curseur de volume, touche M), réglages mémorisés.
 * - Mouvement réduit (prefers-reduced-motion ou html[data-yn-animations="reduites"]) : ni
 *   secousse ni clignotement, moins de particules (lu par rendu.lirePalette).
 *
 * ynWE.modale = { ouvrir(config, universSlug?), fermer(), estOuvert() } ; jeu.js l'expose en
 * window.ynWordEndJeu. Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var DT = ynWE.DT;

	var AIDE = 'Flèches ou Q/D : marcher · Maj : courir · J ou X : coup d’épée · K ou C maintenu puis relâché : charge magique · P : pause · M : musique · Échap : pause, puis fermer';

	var config = null;
	var dialogue = null;
	var titre = null;
	var ecran = null;
	var annonce = null;
	var boutonJouer = null;
	var boutonPause = null;
	var groupeSon = null;
	var boutonMuet = null;
	var curseurVolume = null;
	var focusAvant = null;
	var ouvert = false;
	var echapTraite = false;
	var requete = 0;
	var dernierTemps = 0;
	var accumulateur = 0;
	var images = 0;
	var debutIps = 0;

	var r = null;
	var entrees = null;
	var ec = null;
	var contexte = null;
	var audio = null;

	var universSlug = '';
	var univers = null;
	var personnageSlug = '';
	var partie = null;
	var chargements = {};

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

	function annoncer( texte ) {
		if ( annonce ) {
			annonce.textContent = '';
			window.setTimeout( function () {
				annonce.textContent = texte;
			}, 50 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Univers et parties                                                  */
	/* ------------------------------------------------------------------ */

	function chargerUnivers( slug ) {
		if ( chargements[ slug ] ) {
			return chargements[ slug ];
		}
		var entree = config.univers && config.univers[ slug ];
		if ( ! entree ) {
			return Promise.reject( new Error( 'univers inconnu : ' + slug ) );
		}
		chargements[ slug ] = ynWE.ressources.chargerUnivers( {
			manifeste: entree.manifeste,
			version: entree.version,
			slug: slug,
		} ).catch( function ( erreur ) {
			delete chargements[ slug ];
			throw erreur;
		} );
		return chargements[ slug ];
	}

	/* Palette et mouvement réduit du rendu recopiés dans le monde (particules, secousse). */
	function preparerMonde( monde ) {
		if ( monde ) {
			monde.palette = r.palette;
			monde.mouvementReduit = r.mouvementReduit;
		}
	}

	function niveauParDefaut( u ) {
		var depart = config.depart || {};
		if ( depart.niveau && u.chemins.niveaux[ depart.niveau ] ) {
			return depart.niveau;
		}
		return u.chemins.niveaux.arcade ? 'arcade' : u.ordre.niveaux[ 0 ];
	}

	function lancerPartie( niveauSlug, personnage ) {
		partie = ynWE.niveau.demarrer( univers, univers.niveaux[ niveauSlug ], personnage, entrees );
		preparerMonde( partie.monde );
	}

	/* Démarre un niveau (appelé par les écrans). */
	function demarrerNiveau( slugUnivers, slugPersonnage, niveauSlug ) {
		if ( ! univers || slugUnivers !== univers.slug ) {
			return;
		}
		function commencer() {
			var personnage = univers.personnages[ slugPersonnage ];
			personnageSlug = slugPersonnage;
			lancerPartie( niveauSlug, personnage );
			entrees.vider( 'impulsions' );
			ec.aller( 'jeu' );
			var textes = univers.niveaux[ niveauSlug ].textes || {};
			annoncer( textes.intro || 'Partie commencée.' );
		}
		if ( univers.personnages[ slugPersonnage ] && univers.niveaux[ niveauSlug ] ) {
			commencer();
			return;
		}
		Promise.all( [
			ynWE.ressources.chargerPersonnage( univers, slugPersonnage ),
			ynWE.ressources.chargerNiveau( univers, niveauSlug ),
		] ).then( commencer, function ( erreur ) {
			// eslint-disable-next-line no-console
			console.warn( '[WordEnd] ' + erreur.message );
		} );
	}

	function surFinNiveau( bilan ) {
		if ( ! ouvert || ! univers ) {
			return;
		}
		ynWE.stockage.enregistrerResultat( univers.slug, bilan.niveau, { score: bilan.score, etoiles: bilan.etoiles, fini: bilan.resultat === 'gagne' } );
		ec.aller( bilan.resultat === 'gagne' ? 'victoire' : 'fin', bilan );
	}

	/* ------------------------------------------------------------------ */
	/* Construction                                                        */
	/* ------------------------------------------------------------------ */

	function construire() {
		dialogue = element( 'dialog', { class: 'yn-wordend', 'aria-labelledby': 'yn-wordend-titre', 'aria-describedby': 'yn-wordend-aide' } );
		var cadre = element( 'div', { class: 'yn-wordend__cadre' } );

		var entete = element( 'div', { class: 'yn-wordend__entete' } );
		titre = element( 'h2', { id: 'yn-wordend-titre', class: 'yn-wordend__titre' }, 'WordEnd' );
		entete.appendChild( titre );
		var fermerBouton = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm yn-wordend__fermer', 'aria-label': 'Fermer le jeu' }, '×' );
		fermerBouton.addEventListener( 'click', fermer );
		entete.appendChild( fermerBouton );
		cadre.appendChild( entete );

		ecran = element( 'canvas', {
			class: 'yn-wordend__ecran',
			width: String( ynWE.LARGEUR * ynWE.DENSITE ),
			height: String( ynWE.HAUTEUR * ynWE.DENSITE ),
			tabindex: '0',
			role: 'img',
			'aria-label': 'Zone de jeu WordEnd',
		} );
		ecran.textContent = 'Votre navigateur ne peut pas afficher le jeu.';
		cadre.appendChild( ecran );

		var barre = element( 'div', { class: 'yn-wordend__barre' } );
		boutonJouer = element( 'button', { type: 'button', class: 'yn-btn yn-btn--primary yn-btn--sm' }, 'Jouer' );
		boutonJouer.addEventListener( 'click', function () {
			ec.surAction( 'jouer' );
			ecran.focus();
		} );
		boutonPause = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm', 'aria-pressed': 'false' }, 'Pause' );
		boutonPause.addEventListener( 'click', function () {
			ec.surAction( 'pause' );
		} );
		barre.appendChild( boutonJouer );
		barre.appendChild( boutonPause );
		barre.appendChild( construireSon() );
		cadre.appendChild( barre );

		r = ynWE.rendu.creer( ecran );
		audio = ynWE.audio.creer();
		entrees = ynWE.entrees.creer( dialogue, ecran );
		entrees.surAppui = function () {
			ec.surAction( 'tactile' );
		};
		cadre.appendChild( entrees.element );

		cadre.appendChild( element( 'p', { id: 'yn-wordend-aide', class: 'yn-wordend__aide' }, AIDE ) );
		annonce = element( 'p', { class: 'yn-visually-hidden', role: 'status', 'aria-live': 'polite' } );
		cadre.appendChild( annonce );

		dialogue.appendChild( cadre );
		// Échap arrive par keydown (surTouche) ; « cancel » ne sert que si le keydown n'a pas atteint
		// la modale (aucun élément focalisé), pour ne pas traiter deux fois la même touche.
		dialogue.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			if ( ! echapTraite ) {
				ec.surAction( 'echap' );
			}
			echapTraite = false;
		} );
		dialogue.addEventListener( 'close', function () {
			if ( ouvert ) {
				fermer();
			}
		} );
		dialogue.addEventListener( 'keydown', surTouche );
		dialogue.addEventListener( 'keyup', function ( e ) {
			entrees.relacher( e );
		} );
		document.body.appendChild( dialogue );

		contexte = {
			r: r,
			stockage: ynWE.stockage,
			config: config,
			univers: function () {
				return univers;
			},
			personnage: function () {
				return univers ? univers.personnages[ personnageSlug ] || null : null;
			},
			ouvrirUnivers: function ( slug ) {
				universSlug = slug;
				ouvrirUnivers();
			},
			demarrerNiveau: demarrerNiveau,
			fermer: fermer,
		};
		ec = ynWE.ecrans.creer( contexte );

		ynWE.evenements.sur( 'annonce', function ( detail ) {
			annoncer( detail.texte );
		} );
		ynWE.evenements.sur( 'partie:etat', function ( detail ) {
			if ( detail.etat === 'pause' ) {
				entrees.vider();
			}
			mettreAJourBoutons();
		} );
		ynWE.evenements.sur( 'niveau:fin', surFinNiveau );
		ynWE.evenements.sur( 'son:changement', mettreAJourSon );

		ynWE.debug.source = {
			etat: function () {
				return ouvert ? ec.etat : 'ferme';
			},
			monde: function () {
				return partie ? partie.monde : null;
			},
			partie: function () {
				return partie;
			},
		};
	}

	/* Bouton muet et curseur de volume de la musique. */
	function construireSon() {
		groupeSon = element( 'div', { class: 'yn-wordend__son', role: 'group', 'aria-label': 'Musique' } );
		boutonMuet = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm yn-wordend__muet', 'aria-pressed': 'false' }, 'Couper la musique' );
		boutonMuet.addEventListener( 'click', function () {
			basculerMuet();
		} );
		var etiquette = element( 'label', { class: 'yn-wordend__volume' } );
		etiquette.appendChild( element( 'span', {}, 'Volume' ) );
		curseurVolume = element( 'input', { type: 'range', min: '0', max: '100', step: '5' } );
		curseurVolume.addEventListener( 'input', function () {
			var volume = Math.min( 1, Math.max( 0, parseInt( curseurVolume.value, 10 ) / 100 || 0 ) );
			var reglages = audio.reglages();
			audio.regler( { volume: volume, muet: volume > 0 && reglages.muet ? false : reglages.muet } );
		} );
		etiquette.appendChild( curseurVolume );
		groupeSon.appendChild( boutonMuet );
		groupeSon.appendChild( etiquette );
		return groupeSon;
	}

	/* ------------------------------------------------------------------ */
	/* Son et boutons                                                      */
	/* ------------------------------------------------------------------ */

	function adresseMusique() {
		if ( ! univers || ! partie ) {
			return '';
		}
		var musique = univers.musiques[ partie.niveau.musique ];
		return musique ? musique.url : '';
	}

	function aMusique() {
		return !! ( univers && Object.keys( univers.musiques ).length );
	}

	function basculerMuet() {
		var muet = ! audio.reglages().muet;
		audio.regler( { muet: muet } );
		annoncer( muet ? 'Musique coupée.' : 'Musique activée.' );
	}

	/* Musique : jouée seulement pendant une partie, fenêtre ouverte, sans muet ni volume nul. */
	function mettreAJourSon() {
		var son = audio.reglages();
		boutonMuet.setAttribute( 'aria-pressed', son.muet ? 'true' : 'false' );
		boutonMuet.textContent = son.muet ? 'Remettre la musique' : 'Couper la musique';
		curseurVolume.value = String( Math.round( son.volume * 100 ) );
		curseurVolume.setAttribute( 'aria-valuetext', Math.round( son.volume * 100 ) + ' %' + ( son.muet ? ', musique coupée' : '' ) );
		audio.musique( ouvert && ec.etat === 'jeu' ? adresseMusique() : null );
	}

	function mettreAJourBoutons() {
		var etat = ec.etat;
		boutonJouer.textContent = etat === 'fin' || etat === 'victoire' || etat === 'jeu' || etat === 'pause' ? 'Rejouer' : 'Jouer';
		boutonJouer.disabled = etat === 'chargement' || etat === 'erreur';
		boutonPause.disabled = etat !== 'jeu' && etat !== 'pause';
		boutonPause.setAttribute( 'aria-pressed', etat === 'pause' ? 'true' : 'false' );
		boutonPause.textContent = etat === 'pause' ? 'Reprendre' : 'Pause';
		mettreAJourSon();
	}

	/* ------------------------------------------------------------------ */
	/* Entrées                                                             */
	/* ------------------------------------------------------------------ */

	function surTouche( e ) {
		var action = entrees.surTouche( e );
		if ( action === 'echap' ) {
			echapTraite = true;
			window.setTimeout( function () {
				echapTraite = false;
			}, 0 );
			ec.surAction( 'echap' );
			return;
		}
		if ( action === 'muet' ) {
			if ( aMusique() ) {
				basculerMuet();
			}
			return;
		}
		if ( action ) {
			ec.surAction( action );
		}
	}

	function surVisibilite() {
		if ( document.hidden && ec.etat === 'jeu' ) {
			ec.surAction( 'pause' );
		}
	}

	function surPerteFocus() {
		entrees.vider();
		if ( ec.etat === 'jeu' ) {
			ec.surAction( 'pause' );
		}
	}

	function surTheme() {
		r.lirePalette();
		if ( partie ) {
			preparerMonde( partie.monde );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Ouverture et fermeture                                              */
	/* ------------------------------------------------------------------ */

	function ouvrirUnivers() {
		ec.aller( 'chargement' );
		chargerUnivers( universSlug ).then(
			function ( u ) {
				if ( ! ouvert || ec.etat !== 'chargement' ) {
					return;
				}
				univers = u;
				personnageSlug = u.ordre.personnages[ 0 ];
				var manifeste = u.manifeste;
				titre.textContent = manifeste.sousTitre || manifeste.titre || 'WordEnd';
				ecran.setAttribute( 'aria-label', ( manifeste.textes && manifeste.textes.libelleEcran ) || 'Zone de jeu ' + ( manifeste.titre || 'WordEnd' ) );
				groupeSon.hidden = ! aMusique();
				return ynWE.ressources.chargerNiveau( u, niveauParDefaut( u ) ).then( function ( niveau ) {
					if ( ! ouvert || ec.etat !== 'chargement' ) {
						return;
					}
					// Monde de fond de l'écran titre (comme la v1 : partie préparée, non animée).
					lancerPartie( niveau.slug, u.personnages[ personnageSlug ] );
					ec.aller( 'titre' );
					annoncer( ( manifeste.textes && manifeste.textes.pret ) || 'Appuyez sur Entrée pour commencer.' );
				} );
			}
		).then(
			null,
			function ( erreur ) {
				// eslint-disable-next-line no-console
				console.warn( '[WordEnd] ' + erreur.message );
				if ( ouvert ) {
					ec.aller( 'erreur' );
					annoncer( 'Le jeu n’a pas pu être chargé.' );
				}
			}
		);
	}

	/**
	 * Ouvre le jeu.
	 *
	 * @param {Object} configuration window.ynWordEnd.
	 * @param {string} slug          Univers à ouvrir (défaut : universPage, puis universParDefaut).
	 */
	function ouvrir( configuration, slug ) {
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
		contexte.config = config;
		universSlug = slug || config.universPage || config.universParDefaut || Object.keys( config.univers || {} )[ 0 ] || '';
		ouvert = true;
		focusAvant = document.activeElement;
		r.lirePalette();
		audio.relire();
		document.documentElement.classList.add( 'yn-wordend-ouvert' );
		if ( typeof dialogue.showModal === 'function' ) {
			dialogue.showModal();
		} else {
			dialogue.setAttribute( 'open', '' );
		}
		ecran.focus();
		document.addEventListener( 'visibilitychange', surVisibilite );
		window.addEventListener( 'blur', surPerteFocus );
		document.addEventListener( 'yn:theme', surTheme );
		document.dispatchEvent( new CustomEvent( 'yn:wordend', { detail: { etat: 'ouvert', univers: universSlug } } ) );

		ouvrirUnivers();
		dernierTemps = 0;
		requete = window.requestAnimationFrame( boucle );
	}

	function fermer() {
		if ( ! ouvert ) {
			return;
		}
		ouvert = false;
		window.cancelAnimationFrame( requete );
		entrees.vider( 'tout' );
		if ( ec.etat === 'jeu' ) {
			ec.aller( 'pause' );
		}
		document.removeEventListener( 'visibilitychange', surVisibilite );
		window.removeEventListener( 'blur', surPerteFocus );
		document.removeEventListener( 'yn:theme', surTheme );
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
		document.dispatchEvent( new CustomEvent( 'yn:wordend', { detail: { etat: 'ferme', univers: universSlug } } ) );
	}

	/* ------------------------------------------------------------------ */
	/* Boucle                                                              */
	/* ------------------------------------------------------------------ */

	function mettreAJour( dt ) {
		ec.temps += dt;
		if ( ! partie ) {
			return;
		}
		if ( ec.etat === 'jeu' ) {
			partie.mettreAJour( dt );
		} else if ( ec.etat === 'fin' || ec.etat === 'victoire' || ec.etat === 'titre' ) {
			ynWE.rendu.mettreAJourParticules( partie.monde, dt );
		}
	}

	function boucle( maintenant ) {
		if ( ! ouvert ) {
			return;
		}
		if ( ! dernierTemps ) {
			dernierTemps = maintenant;
			debutIps = maintenant;
		}
		accumulateur += Math.min( 0.25, ( maintenant - dernierTemps ) / 1000 );
		dernierTemps = maintenant;
		var pas = 0;
		while ( accumulateur >= DT && pas < 5 ) {
			mettreAJour( DT );
			accumulateur -= DT;
			pas++;
		}
		if ( pas === 5 ) {
			accumulateur = 0;
		}
		ec.dessiner( partie ? partie.monde : null, partie );
		images++;
		if ( maintenant - debutIps >= 1000 ) {
			ynWE.debug.ips = Math.round( images * 1000 / ( maintenant - debutIps ) );
			images = 0;
			debutIps = maintenant;
		}
		requete = window.requestAnimationFrame( boucle );
	}

	ynWE.modale = {
		ouvrir: ouvrir,
		fermer: fermer,
		estOuvert: function () {
			return ouvert;
		},
	};
}() );
