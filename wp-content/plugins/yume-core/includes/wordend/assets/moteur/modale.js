/**
 * WordEnd — moteur : modale <dialog>, boutons, accessibilité, boucle à pas fixe, orchestration.
 *
 * - showModal (le reste de la page est inerte), Tab et Maj+Tab bouclent dans la modale.
 *   Échap met la partie en pause, un second Échap (ou Échap hors partie) ferme ; le focus
 *   revient à l'élément d'origine. Événement document « yn:wordend » (detail.etat =
 *   ouvert|ferme, detail.univers = slug).
 * - Écrans de sélection dessinés dans le canvas ET doublés par de vrais contrôles sous le
 *   canvas (.yn-wordend__choix : listes Univers, Personnage et Niveau), synchronisés,
 *   désactivés pendant la partie ; bouton « Niveaux » (retour à la sélection) ; barre de vie du
 *   boss doublée par un <progress> visuellement caché.
 * - Aide et manettes tactiles adaptées au personnage (Saut, compétences principale et secondaire).
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

	/* Texte des boutons tactiles selon le type de compétence (à défaut de def.bouton). */
	var BOUTONS = { melee: 'Épée', projectile: 'Tir', onde: 'Charge', ruee: 'Ruée', parade: 'Parade' };

	var config = null;
	var dialogue = null;
	var titre = null;
	var ecran = null;
	var annonce = null;
	var aide = null;
	var boutonJouer = null;
	var boutonPause = null;
	var boutonNiveaux = null;
	var groupeSon = null;
	var boutonMuet = null;
	var curseurVolume = null;
	var choix = null;
	var listes = {};
	var barreBoss = null;
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

	function avertir( message ) {
		// eslint-disable-next-line no-console
		console.warn( '[WordEnd] ' + message );
	}

	function minuscule( texte ) {
		texte = String( texte || '' );
		return texte.charAt( 0 ).toLowerCase() + texte.slice( 1 );
	}

	/* ------------------------------------------------------------------ */
	/* Aide et manettes d'après le personnage                              */
	/* ------------------------------------------------------------------ */

	function competences( personnage ) {
		return ( personnage && personnage.competences ) || {};
	}

	function aidePour( personnage ) {
		var c = competences( personnage );
		var morceaux = [
			'Flèches ou Q/D : se déplacer',
			'↑, Z ou Espace : sauter',
			'↓ + saut : descendre d’une plateforme',
			'Maj : courir',
			'J ou X : ' + minuscule( c.principale && c.principale.libelle ? c.principale.libelle : 'Coup d’épée' ),
		];
		if ( c.secondaire ) {
			morceaux.push( 'K ou C' + ( c.secondaire.type === 'onde' ? ' maintenu puis relâché' : '' ) + ' : ' + minuscule( c.secondaire.libelle || c.secondaire.type ) );
		}
		morceaux.push( 'P : pause', 'M : musique', 'R : niveaux', 'Échap : pause, puis fermer' );
		return morceaux.join( ' · ' );
	}

	function schemaPour( personnage ) {
		var c = competences( personnage );
		var schema = [
			{ nom: 'gauche', texte: '◀', libelle: 'Aller à gauche', groupe: 'gauche' },
			{ nom: 'droite', texte: '▶', libelle: 'Aller à droite', groupe: 'gauche' },
			{ nom: 'courir', texte: 'Courir', libelle: 'Courir', bascule: true, groupe: 'gauche' },
			{ nom: 'saut', texte: 'Saut', libelle: 'Sauter', groupe: 'droite' },
		];
		var principale = c.principale || { type: 'melee', libelle: 'Coup d’épée' };
		schema.push( { nom: 'epee', texte: principale.bouton || BOUTONS[ principale.type ] || 'Épée', libelle: principale.libelle || 'Action principale', groupe: 'droite' } );
		if ( c.secondaire ) {
			var sec = c.secondaire;
			schema.push( {
				nom: 'competence',
				texte: sec.bouton || BOUTONS[ sec.type ] || 'Compétence',
				libelle: ( sec.libelle || 'Compétence' ) + ( sec.type === 'onde' ? ' (maintenir puis relâcher)' : '' ),
				groupe: 'droite',
			} );
		}
		return schema;
	}

	var personnageCommandes = null;
	function adapterCommandes( personnage ) {
		if ( ! personnage || personnage === personnageCommandes ) {
			return;
		}
		personnageCommandes = personnage;
		aide.textContent = aidePour( personnage );
		entrees.actualiserManettes( schemaPour( personnage ) );
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
		var nouvelle = ynWE.niveau.demarrer( univers, univers.niveaux[ niveauSlug ], personnage, entrees );
		partie = nouvelle;
		preparerMonde( partie.monde );
	}

	/* Démarre un niveau (appelé par les écrans). */
	function demarrerNiveau( slugUnivers, slugPersonnage, niveauSlug ) {
		if ( ! univers || slugUnivers !== univers.slug ) {
			return;
		}
		var u = univers;
		function commencer() {
			if ( ! ouvert || univers !== u ) {
				return;
			}
			var personnage = u.personnages[ slugPersonnage ];
			try {
				lancerPartie( niveauSlug, personnage );
			} catch ( erreur ) {
				avertir( 'niveau impossible à lancer (' + niveauSlug + ') : ' + erreur.message );
				annoncer( 'Ce niveau n’est pas encore disponible.' );
				if ( ec.etat !== 'niveaux' ) {
					ec.aller( 'niveaux' );
				}
				return;
			}
			personnageSlug = slugPersonnage;
			adapterCommandes( personnage );
			entrees.vider( 'impulsions' );
			ec.aller( 'jeu' );
			var textes = u.niveaux[ niveauSlug ].textes || {};
			annoncer( textes.intro || 'Partie commencée.' );
		}
		// Chemin synchrone si tout est déjà là (personnage, niveau et son décor) ; sinon chargement
		// (chargerNiveau charge aussi le décor du niveau, lot A ; tout est mis en cache).
		var niveauCharge = u.niveaux[ niveauSlug ];
		var decorPret = niveauCharge && ( ! niveauCharge.decor || Object.prototype.hasOwnProperty.call( u.decors, niveauCharge.decor ) );
		if ( u.personnages[ slugPersonnage ] && decorPret ) {
			commencer();
			return;
		}
		Promise.all( [
			ynWE.ressources.chargerPersonnage( u, slugPersonnage ),
			ynWE.ressources.chargerNiveau( u, niveauSlug ),
		] ).then( commencer, function ( erreur ) {
			avertir( erreur.message );
			annoncer( 'Ce niveau n’a pas pu être chargé.' );
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
		ecran.addEventListener( 'click', surClicEcran );
		cadre.appendChild( ecran );

		barreBoss = element( 'progress', { class: 'yn-visually-hidden yn-wordend__boss', max: '1', value: '0', 'aria-label': 'Vie du boss' } );
		barreBoss.hidden = true;
		cadre.appendChild( barreBoss );

		cadre.appendChild( construireChoix() );

		var barre = element( 'div', { class: 'yn-wordend__barre' } );
		boutonJouer = element( 'button', { type: 'button', class: 'yn-btn yn-btn--primary yn-btn--sm yn-wordend__jouer' }, 'Jouer' );
		boutonJouer.addEventListener( 'click', function () {
			ec.surAction( 'jouer' );
			ecran.focus();
		} );
		boutonPause = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm yn-wordend__pause', 'aria-pressed': 'false' }, 'Pause' );
		boutonPause.addEventListener( 'click', function () {
			ec.surAction( 'pause' );
		} );
		boutonNiveaux = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm yn-wordend__niveaux' }, 'Niveaux' );
		boutonNiveaux.addEventListener( 'click', function () {
			ec.surAction( 'niveaux' );
			ecran.focus();
		} );
		barre.appendChild( boutonJouer );
		barre.appendChild( boutonPause );
		barre.appendChild( boutonNiveaux );
		barre.appendChild( construireSon() );
		cadre.appendChild( barre );

		r = ynWE.rendu.creer( ecran );
		audio = ynWE.audio.creer();
		entrees = ynWE.entrees.creer( dialogue, ecran );
		entrees.surAppui = function ( nom ) {
			ec.surAction( 'tactile', nom );
		};
		cadre.appendChild( entrees.element );

		aide = element( 'p', { id: 'yn-wordend-aide', class: 'yn-wordend__aide' }, aidePour( null ) );
		cadre.appendChild( aide );
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
		// « close » arrive en tâche différée : ignoré si la modale a été rouverte entre-temps.
		dialogue.addEventListener( 'close', function () {
			if ( ouvert && ! dialogue.open ) {
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
			partie: function () {
				return partie;
			},
			ouvrirUnivers: function ( slug, apres ) {
				if ( univers && slug === univers.slug ) {
					ec.aller( apres || 'titre' );
					return;
				}
				universSlug = slug;
				ouvrirUnivers( apres );
			},
			demarrerNiveau: demarrerNiveau,
			fermer: fermer,
			surChoix: synchroniserChoix,
		};
		ec = ynWE.ecrans.creer( contexte );

		ynWE.evenements.sur( 'annonce', function ( detail ) {
			annoncer( detail.texte );
		} );
		ynWE.evenements.sur( 'partie:etat', function ( detail ) {
			if ( detail.etat === 'pause' ) {
				entrees.vider();
			} else if ( detail.etat === 'jeu' ) {
				entrees.vider( 'impulsions' ); // Entrée / Espace de reprise : pas de saut.
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

	/* Listes Univers / Personnage / Niveau (doublons des écrans de sélection du canvas). */
	function construireChoix() {
		choix = element( 'div', { class: 'yn-wordend__choix', role: 'group', 'aria-label': 'Sélection' } );
		[ [ 'univers', 'Univers' ], [ 'personnage', 'Personnage' ], [ 'niveau', 'Niveau' ] ].forEach( function ( paire ) {
			var nom = paire[ 0 ];
			var etiquette = element( 'label', { class: 'yn-wordend__liste yn-wordend__liste--' + nom } );
			etiquette.appendChild( element( 'span', {}, paire[ 1 ] ) );
			var liste = element( 'select', { name: nom } );
			liste.addEventListener( 'change', function () {
				ec.choisir( nom, liste.value );
			} );
			liste.addEventListener( 'focus', function () {
				ec.preparer(); // Listes complètes (niveaux, personnages) à la première interaction.
			} );
			etiquette.appendChild( liste );
			choix.appendChild( etiquette );
			listes[ nom ] = { etiquette: etiquette, liste: liste, signature: '' };
		} );
		return choix;
	}

	function remplir( nom, elements, valeur ) {
		var entree = listes[ nom ];
		var signature = elements.map( function ( e ) {
			return e.slug + '|' + e.texte + '|' + ( e.desactive ? 1 : 0 );
		} ).join( ';' );
		if ( signature !== entree.signature ) {
			entree.signature = signature;
			while ( entree.liste.firstChild ) {
				entree.liste.removeChild( entree.liste.firstChild );
			}
			elements.forEach( function ( e ) {
				var option = element( 'option', { value: e.slug }, e.texte );
				option.disabled = !! e.desactive;
				entree.liste.appendChild( option );
			} );
		}
		if ( valeur && entree.liste.value !== valeur ) {
			entree.liste.value = valeur;
		}
	}

	/* Synchronise les listes DOM avec l'état des écrans (appelé par ec à chaque changement). */
	function synchroniserChoix() {
		if ( ! ec || ! choix ) {
			return;
		}
		var donnees = ec.listes();
		remplir( 'univers', donnees.univers.map( function ( u ) {
			return { slug: u.slug, texte: u.titre };
		} ), donnees.choix.univers || universSlug );
		listes.univers.etiquette.hidden = ! donnees.avecUnivers;
		remplir( 'personnage', donnees.personnages.map( function ( p ) {
			return { slug: p.slug, texte: p.nom + ( p.debloque ? '' : ' (verrouillé)' ), desactive: ! p.debloque };
		} ), donnees.choix.personnage );
		remplir( 'niveau', donnees.niveaux.map( function ( n ) {
			var texte = n.arcade ? 'Arcade' : n.numero + '. ' + n.titre;
			if ( ! n.debloque ) {
				texte += ' (verrouillé)';
			} else if ( ! n.arcade ) {
				texte += ' (' + n.etoiles + ' / 3 étoiles)';
			}
			return { slug: n.slug, texte: texte, desactive: ! n.debloque };
		} ), donnees.choix.niveau );
		var bloque = ! univers || [ 'jeu', 'pause', 'chargement', 'erreur' ].indexOf( ec.etat ) !== -1;
		// Aide et manettes du personnage choisi dès la sélection (sinon : celui de la partie).
		if ( univers && ! bloque && univers.personnages[ donnees.choix.personnage ] ) {
			adapterCommandes( univers.personnages[ donnees.choix.personnage ] );
		}
		Object.keys( listes ).forEach( function ( nom ) {
			listes[ nom ].liste.disabled = bloque;
		} );
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
		var enPartie = etat === 'fin' || etat === 'victoire' || etat === 'jeu' || etat === 'pause';
		boutonJouer.textContent = enPartie ? 'Rejouer' : ( etat === 'univers' || etat === 'personnage' ? 'Valider' : 'Jouer' );
		boutonJouer.disabled = etat === 'chargement' || etat === 'erreur';
		boutonPause.disabled = etat !== 'jeu' && etat !== 'pause';
		boutonPause.setAttribute( 'aria-pressed', etat === 'pause' ? 'true' : 'false' );
		boutonPause.textContent = etat === 'pause' ? 'Reprendre' : 'Pause';
		boutonNiveaux.disabled = etat === 'chargement' || etat === 'erreur' || etat === 'niveaux';
		if ( etat !== 'jeu' && etat !== 'pause' ) {
			barreBoss.hidden = true;
		}
		mettreAJourSon();
	}

	/* Vie du boss pour les lecteurs d'écran (mise à jour seulement quand elle change). */
	function mettreAJourBoss() {
		var boss = partie && ( ec.etat === 'jeu' || ec.etat === 'pause' ) ? partie.hud().boss : null;
		if ( ! boss || ! boss.pvMax ) {
			if ( ! barreBoss.hidden ) {
				barreBoss.hidden = true;
			}
			return;
		}
		var valeur = Math.max( 0, Math.round( boss.pv ) );
		if ( barreBoss.hidden || String( valeur ) !== barreBoss.getAttribute( 'value' ) || String( boss.pvMax ) !== barreBoss.getAttribute( 'max' ) ) {
			barreBoss.hidden = false;
			barreBoss.setAttribute( 'max', String( boss.pvMax ) );
			barreBoss.setAttribute( 'value', String( valeur ) );
			barreBoss.setAttribute( 'aria-label', 'Vie du boss' + ( boss.nom ? ' : ' + boss.nom : '' ) );
			barreBoss.setAttribute( 'aria-valuetext', valeur + ' sur ' + boss.pvMax );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Entrées                                                             */
	/* ------------------------------------------------------------------ */

	/* Tab et Maj+Tab restent dans la modale (en plus de l'inertie de showModal). */
	function pieger( e ) {
		var candidats = dialogue.querySelectorAll( 'button, select, input, [tabindex]:not([tabindex="-1"])' );
		var focalisables = Array.prototype.filter.call( candidats, function ( el ) {
			return ! el.disabled && ! el.closest( '[hidden]' ) && el.getClientRects().length > 0;
		} );
		if ( ! focalisables.length ) {
			return;
		}
		var premier = focalisables[ 0 ];
		var dernier = focalisables[ focalisables.length - 1 ];
		var actif = document.activeElement;
		if ( e.shiftKey && ( actif === premier || ! dialogue.contains( actif ) ) ) {
			e.preventDefault();
			dernier.focus();
		} else if ( ! e.shiftKey && ( actif === dernier || ! dialogue.contains( actif ) ) ) {
			e.preventDefault();
			premier.focus();
		}
	}

	function surTouche( e ) {
		if ( e.key === 'Tab' ) {
			pieger( e );
			return;
		}
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

	/* Clic ou toucher sur le canvas : cartes des écrans de sélection, écrans titre et fin. */
	function surClicEcran( e ) {
		if ( ! ouvert || ec.etat === 'jeu' || ec.etat === 'pause' ) {
			return;
		}
		var cadre = ecran.getBoundingClientRect();
		if ( ! cadre.width || ! cadre.height ) {
			return;
		}
		var x = ( e.clientX - cadre.left ) / cadre.width * ynWE.LARGEUR;
		var y = ( e.clientY - cadre.top ) / cadre.height * ynWE.HAUTEUR;
		ec.surPointeur( x, y );
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

	/* Charge l'univers courant puis affiche l'écran titre (ou apres : 'personnage'). */
	function ouvrirUnivers( apres ) {
		ec.aller( 'chargement' );
		chargerUnivers( universSlug ).then(
			function ( u ) {
				if ( ! ouvert || ec.etat !== 'chargement' ) {
					return;
				}
				univers = u;
				partie = null;
				personnageSlug = u.ordre.personnages[ 0 ];
				var manifeste = u.manifeste;
				titre.textContent = manifeste.sousTitre || manifeste.titre || 'WordEnd';
				ecran.setAttribute( 'aria-label', ( manifeste.textes && manifeste.textes.libelleEcran ) || 'Zone de jeu ' + ( manifeste.titre || 'WordEnd' ) );
				groupeSon.hidden = ! aMusique();
				adapterCommandes( u.personnages[ personnageSlug ] );
				return ynWE.ressources.chargerNiveau( u, niveauParDefaut( u ) ).then( function ( niveau ) {
					if ( ! ouvert || ec.etat !== 'chargement' ) {
						return;
					}
					// Monde de fond des écrans titre et de sélection (partie préparée, non animée).
					lancerPartie( niveau.slug, u.personnages[ personnageSlug ] );
					ec.aller( 'titre' );
					if ( apres && apres !== 'titre' ) {
						ec.aller( apres );
					} else {
						annoncer( ( manifeste.textes && manifeste.textes.pret ) || 'Appuyez sur Entrée pour commencer.' );
					}
				} );
			}
		).then(
			null,
			function ( erreur ) {
				avertir( erreur.message );
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
		var demande = slug || config.universPage || config.universParDefaut || Object.keys( config.univers || {} )[ 0 ] || '';
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
		document.dispatchEvent( new CustomEvent( 'yn:wordend', { detail: { etat: 'ouvert', univers: demande } } ) );

		if ( univers && univers.slug === demande && partie ) {
			// Réouverture du même univers : écran titre sans recharger.
			universSlug = demande;
			ec.aller( 'titre' );
			annoncer( ( univers.manifeste.textes && univers.manifeste.textes.pret ) || 'Appuyez sur Entrée pour commencer.' );
		} else {
			universSlug = demande;
			ouvrirUnivers();
		}
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
		} else if ( ec.etat !== 'pause' ) {
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
		mettreAJourBoss();
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
