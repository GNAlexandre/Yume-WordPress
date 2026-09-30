/**
 * WordEnd — moteur : machine d'écrans (dessin du monde, HUD, surcouches, sélection, actions).
 *
 * États : chargement, titre, univers, personnage, niveaux, jeu, pause, fin, victoire, erreur.
 * Chaque changement émet 'partie:etat' {etat} et s'annonce aux lecteurs d'écran.
 * Parcours : titre → univers (seulement si plusieurs univers et aucun univers de page) →
 * personnage (cartes, verrous) → niveaux (Arcade puis niveaux numérotés : étoiles, verrous,
 * record) → jeu → pause | fin (perdu) | victoire (étoiles). Les listes sont aussi exposées à la
 * modale (ec.listes()) pour les contrôles <select> doublons, synchronisés par contexte.surChoix().
 *
 * Actions (ec.surAction(action, nom?)) : echap, pause, niveaux (R), valider, epee, jouer
 * (bouton Jouer/Rejouer), tactile (nom = commande de la manette), muet, gauche, droite, haut, bas.
 * config.depart.niveau (banc) : lance directement ce niveau depuis l'écran titre.
 * Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var LARGEUR = ynWE.LARGEUR;
	var HAUTEUR = ynWE.HAUTEUR;

	var MENUS = { titre: true, univers: true, personnage: true, niveaux: true };
	var COLONNES_NIVEAUX = 3;

	function majuscule( texte ) {
		texte = String( texte || '' );
		return texte.charAt( 0 ).toUpperCase() + texte.slice( 1 );
	}

	/* Chronomètre « m:ss ». */
	function chrono( secondes ) {
		var s = Math.max( 0, Math.ceil( secondes ) );
		var m = Math.floor( s / 60 );
		s -= m * 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	/**
	 * Crée la machine d'écrans.
	 *
	 * @param {Object} contexte {r, stockage, config, univers() → univers|null,
	 *                          personnage() → personnage|null, partie() → partie|null,
	 *                          ouvrirUnivers(slug, apres?), demarrerNiveau(universSlug, personnageSlug, niveauSlug),
	 *                          fermer(), surChoix?()}.
	 */
	function creer( contexte ) {
		var r = contexte.r;
		var ec = {
			etat: 'chargement',
			temps: 0,
			debut: 0, // ec.temps à l'entrée dans l'état courant.
			meilleur: 0, // Record du niveau en cours (HUD, fin).
			bilan: null,
			choix: { univers: '', personnage: '', niveau: '' },
			preparation: '', // '' | 'encours' | 'pret' (listes complètes de l'univers).
		};
		var zones = []; // Zones cliquables de l'écran courant : {x, y, l, h, slug}.
		var absents = {}; // Personnages listés mais introuvables.
		var preparations = {};

		function annoncer( texte ) {
			ynWE.annoncer( texte );
		}

		function univers() {
			return contexte.univers ? contexte.univers() : null;
		}

		function partieCourante() {
			return contexte.partie ? contexte.partie() : null;
		}

		function config() {
			return contexte.config || {};
		}

		function signalerChoix() {
			if ( typeof contexte.surChoix === 'function' ) {
				contexte.surChoix();
			}
		}

		/* Progression de l'univers courant, toujours complète (stockage lot 0 ou lot A). */
		function progression() {
			var u = univers();
			var p = null;
			try {
				// L'univers chargé permet au stockage (lot A) de calculer debloques.
				p = u && contexte.stockage ? contexte.stockage.progression( u.slug, u ) : null;
			} catch ( e ) {
				p = null;
			}
			p = p || {};
			return {
				parties: p.parties || 0,
				arcade: { meilleur: ( p.arcade && p.arcade.meilleur ) || 0 },
				niveaux: p.niveaux || {},
				personnage: p.personnage || '',
				debloques: {
					niveaux: ( p.debloques && p.debloques.niveaux ) || [],
					personnages: ( p.debloques && p.debloques.personnages ) || [],
				},
			};
		}

		/* ------------------------------------------------------------------ */
		/* Listes (personnages, niveaux, univers)                              */
		/* ------------------------------------------------------------------ */

		function listeUnivers() {
			var liste = config().univers || {};
			return Object.keys( liste ).map( function ( slug ) {
				return { slug: slug, titre: liste[ slug ].titre || majuscule( slug ), debloque: true };
			} );
		}

		function avecUnivers() {
			return listeUnivers().length > 1 && ! config().universPage;
		}

		function titreNiveau( u, slug ) {
			var n = u && u.niveaux[ slug ];
			if ( n && n.titre ) {
				return n.titre;
			}
			return slug === 'arcade' ? 'Arcade' : majuscule( slug.replace( /^\d+-/, '' ) );
		}

		function listePersonnages() {
			var u = univers();
			if ( ! u ) {
				return [];
			}
			var prog = progression();
			return u.ordre.personnages.filter( function ( slug ) {
				return ! absents[ slug ];
			} ).map( function ( slug ) {
				var perso = u.personnages[ slug ] || null;
				var regle = perso ? perso.debloque : true;
				var condition = regle && typeof regle === 'object' && regle.niveau ? String( regle.niveau ) : '';
				var debloque = regle !== false && ( ! condition || !! ( prog.niveaux[ condition ] && prog.niveaux[ condition ].fini ) );
				if ( prog.debloques.personnages.indexOf( slug ) !== -1 ) {
					debloque = true;
				}
				return {
					slug: slug,
					nom: perso && perso.nom ? perso.nom : majuscule( slug ),
					description: perso && perso.description ? perso.description : '',
					personnage: perso,
					debloque: debloque,
					condition: condition ? titreNiveau( u, condition ) : '',
				};
			} );
		}

		/* Arcade d'abord (toujours débloqué), puis les niveaux : le suivant s'ouvre quand le précédent est fini. */
		function listeNiveaux() {
			var u = univers();
			if ( ! u ) {
				return [];
			}
			var prog = progression();
			var slugs = u.ordre.niveaux.filter( function ( slug ) {
				return !! u.niveaux[ slug ]; // Chargés seulement (les autres le sont par preparer()).
			} );
			slugs.sort( function ( a, b ) {
				return ( a === 'arcade' ? 0 : 1 ) - ( b === 'arcade' ? 0 : 1 );
			} );
			var precedentFini = true;
			return slugs.map( function ( slug ) {
				var json = u.niveaux[ slug ] || {};
				var etat = prog.niveaux[ slug ] || {};
				var arcade = slug === 'arcade';
				var debloque = arcade || precedentFini || prog.debloques.niveaux.indexOf( slug ) !== -1;
				if ( ! arcade ) {
					precedentFini = !! etat.fini;
				}
				return {
					slug: slug,
					titre: titreNiveau( u, slug ),
					numero: typeof json.numero === 'number' ? json.numero : 0,
					arcade: arcade,
					etoiles: arcade ? 0 : Math.max( 0, Math.min( 3, etat.etoiles || 0 ) ),
					meilleur: arcade ? prog.arcade.meilleur : etat.meilleur || 0,
					fini: !! etat.fini,
					debloque: debloque,
				};
			} );
		}

		/* Slugs débloqués (personnages et niveaux) : instantané comparé par ec.nouveautes. */
		function deblocages() {
			var slugs = function ( liste ) {
				return liste.filter( function ( x ) {
					return x.debloque;
				} ).map( function ( x ) {
					return x.slug;
				} );
			};
			return { personnages: slugs( listePersonnages() ), niveaux: slugs( listeNiveaux() ) };
		}

		/*
		 * Déblocages survenus depuis l'instantané avant (ec.deblocages()) : liste de
		 * {type: 'personnage'|'niveau', slug, nom}. La modale l'appelle autour de
		 * stockage.enregistrerResultat et la passe au bilan de victoire (bilan.nouveautes).
		 */
		function nouveautes( avant ) {
			var liste = [];
			if ( ! avant ) {
				return liste;
			}
			listePersonnages().forEach( function ( p ) {
				if ( p.debloque && p.personnage && ( avant.personnages || [] ).indexOf( p.slug ) === -1 ) {
					liste.push( { type: 'personnage', slug: p.slug, nom: p.nom } );
				}
			} );
			listeNiveaux().forEach( function ( n ) {
				if ( n.debloque && ( avant.niveaux || [] ).indexOf( n.slug ) === -1 ) {
					liste.push( { type: 'niveau', slug: n.slug, nom: n.titre } );
				}
			} );
			return liste;
		}

		/* Phrase des déblocages : « Nouveau personnage : Nephren ! · Niveau débloqué : Les dunes ». */
		function texteNouveautes( liste, separateur ) {
			return ( liste || [] ).map( function ( n ) {
				return n.type === 'personnage' ? 'Nouveau personnage : ' + n.nom + ' !' : 'Niveau débloqué : ' + n.nom + '.';
			} ).join( separateur );
		}

		function trouver( liste, slug ) {
			for ( var i = 0; i < liste.length; i++ ) {
				if ( liste[ i ].slug === slug ) {
					return i;
				}
			}
			return -1;
		}

		/* Choix par défaut d'un univers qui vient d'être chargé. */
		function initialiserChoix() {
			var u = univers();
			if ( ! u || ec.choix.univers === u.slug ) {
				return;
			}
			absents = {};
			var prog = progression();
			var depart = config().depart || {};
			var perso = depart.personnage && u.chemins.personnages[ depart.personnage ] ? depart.personnage : prog.personnage;
			ec.choix = {
				univers: u.slug,
				personnage: perso && u.chemins.personnages[ perso ] ? perso : u.ordre.personnages[ 0 ],
				niveau: depart.niveau && u.chemins.niveaux[ depart.niveau ] ? depart.niveau : ( u.chemins.niveaux.arcade ? 'arcade' : u.ordre.niveaux[ 0 ] ),
			};
			ec.preparation = '';
		}

		/*
		 * Charge les listes complètes de l'univers (tous les niveaux, tous les personnages) : un
		 * fichier absent est ignoré (avertissement), jamais bloquant. Une seule fois par univers.
		 */
		function preparer() {
			var u = univers();
			if ( ! u ) {
				return Promise.resolve();
			}
			if ( preparations[ u.slug ] ) {
				return preparations[ u.slug ];
			}
			ec.preparation = 'encours';
			var taches = [ ynWE.ressources.chargerNiveaux( u ).then( null, function () {} ) ];
			if ( typeof ynWE.ressources.chargerPersonnages === 'function' ) {
				// Lot A : chargement tolérant, les absents sont retirés de u.ordre.personnages.
				taches.push( ynWE.ressources.chargerPersonnages( u ).then( null, function () {} ) );
			} else {
				u.ordre.personnages.forEach( function ( slug ) {
					taches.push( ynWE.ressources.chargerPersonnage( u, slug ).then( null, function () {
						// eslint-disable-next-line no-console
						console.warn( '[WordEnd] personnage ignoré (fichier absent) : ' + slug );
						absents[ slug ] = true;
					} ) );
				} );
			}
			preparations[ u.slug ] = Promise.all( taches ).then( function () {
				if ( univers() !== u ) {
					return;
				}
				ec.preparation = 'pret';
				var persos = listePersonnages();
				var i = trouver( persos, ec.choix.personnage );
				if ( i === -1 || ! persos[ i ].debloque ) {
					ec.choix.personnage = persos.length ? persos[ 0 ].slug : '';
				}
				if ( trouver( listeNiveaux(), ec.choix.niveau ) === -1 ) {
					ec.choix.niveau = 'arcade';
				}
				signalerChoix();
				if ( ec.etat === 'personnage' || ec.etat === 'niveaux' ) {
					annoncerEcran();
				}
			} );
			return preparations[ u.slug ];
		}

		/* ------------------------------------------------------------------ */
		/* Annonces                                                            */
		/* ------------------------------------------------------------------ */

		function descriptionPersonnage( p ) {
			if ( ! p ) {
				return '';
			}
			var texte = p.nom + '.';
			if ( ! p.debloque ) {
				texte += p.condition ? ' Verrouillé : terminez « ' + p.condition + ' ».' : ' Verrouillé.';
			} else if ( p.description ) {
				texte += ' ' + p.description;
			}
			return texte;
		}

		function descriptionNiveau( n ) {
			if ( ! n ) {
				return '';
			}
			var texte = n.arcade ? 'Arcade : vagues sans fin.' : 'Niveau ' + n.numero + ' : ' + n.titre + '.';
			if ( ! n.debloque ) {
				return texte + ' Verrouillé : terminez le niveau précédent.';
			}
			if ( ! n.arcade ) {
				texte += ' ' + n.etoiles + ( n.etoiles > 1 ? ' étoiles' : ' étoile' ) + ' sur 3.';
			}
			return texte + ( n.meilleur ? ' Record : ' + n.meilleur + '.' : '' );
		}

		function annoncerSelection() {
			if ( ec.etat === 'personnage' ) {
				var persos = listePersonnages();
				var i = trouver( persos, ec.choix.personnage );
				annoncer( descriptionPersonnage( persos[ i ] ) + ' (' + ( i + 1 ) + ' sur ' + persos.length + ')' );
			} else if ( ec.etat === 'niveaux' ) {
				var niveaux = listeNiveaux();
				var k = trouver( niveaux, ec.choix.niveau );
				annoncer( descriptionNiveau( niveaux[ k ] ) + ' (' + ( k + 1 ) + ' sur ' + niveaux.length + ')' );
			} else if ( ec.etat === 'univers' ) {
				var liste = listeUnivers();
				var j = trouver( liste, ec.choix.univers );
				annoncer( ( liste[ j ] ? liste[ j ].titre : '' ) + ' (' + ( j + 1 ) + ' sur ' + liste.length + ')' );
			}
		}

		function annoncerEcran() {
			var consigne = ' Flèches pour changer, Entrée pour valider, Échap pour fermer.';
			if ( ec.etat === 'personnage' ) {
				var persos = listePersonnages();
				annoncer( 'Choix du personnage. ' + descriptionPersonnage( persos[ trouver( persos, ec.choix.personnage ) ] ) + consigne );
			} else if ( ec.etat === 'niveaux' ) {
				var niveaux = listeNiveaux();
				annoncer( 'Choix du niveau. ' + descriptionNiveau( niveaux[ trouver( niveaux, ec.choix.niveau ) ] ) + consigne );
			} else if ( ec.etat === 'univers' ) {
				annoncer( 'Choix de l’univers.' + consigne );
			}
		}

		/* ------------------------------------------------------------------ */
		/* Records                                                             */
		/* ------------------------------------------------------------------ */

		function recordDe( slug ) {
			var prog = progression();
			if ( ! slug || slug === 'arcade' ) {
				return prog.arcade.meilleur;
			}
			return ( prog.niveaux[ slug ] && prog.niveaux[ slug ].meilleur ) || 0;
		}

		function lireRecord() {
			var partie = partieCourante();
			ec.meilleur = recordDe( partie && partie.niveau ? partie.niveau.slug : ec.choix.niveau );
		}

		/* ------------------------------------------------------------------ */
		/* Transitions                                                         */
		/* ------------------------------------------------------------------ */

		function demarrer( niveauSlug ) {
			var u = univers();
			if ( ! u ) {
				return;
			}
			ec.choix.niveau = niveauSlug;
			signalerChoix();
			contexte.demarrerNiveau( u.slug, ec.choix.personnage || u.ordre.personnages[ 0 ], niveauSlug );
		}

		function rejouer() {
			var partie = partieCourante();
			demarrer( partie && partie.niveau && partie.niveau.slug ? partie.niveau.slug : ec.choix.niveau );
		}

		/* Depuis l'écran titre : univers (s'il y a le choix), sinon personnage ; départ direct (banc). */
		function quitterTitre() {
			var u = univers();
			var depart = config().depart || {};
			if ( u && depart.niveau && u.chemins.niveaux[ depart.niveau ] ) {
				demarrer( depart.niveau );
				return;
			}
			ec.aller( avecUnivers() ? 'univers' : 'personnage' );
		}

		function niveauSuivant() {
			var u = univers();
			var partie = partieCourante();
			var courant = partie && partie.niveau ? partie.niveau : null;
			if ( ! u || ! courant || courant.slug === 'arcade' ) {
				return '';
			}
			if ( courant.suivant && u.chemins.niveaux[ courant.suivant ] ) {
				return courant.suivant;
			}
			var ordre = u.ordre.niveaux;
			var i = ordre.indexOf( courant.slug );
			return i !== -1 && i + 1 < ordre.length && ordre[ i + 1 ] !== 'arcade' ? ordre[ i + 1 ] : '';
		}

		function valider() {
			var etat = ec.etat;
			if ( etat === 'titre' ) {
				quitterTitre();
			} else if ( etat === 'univers' ) {
				var u = univers();
				if ( u && ec.choix.univers === u.slug ) {
					ec.aller( 'personnage' );
				} else {
					contexte.ouvrirUnivers( ec.choix.univers, 'personnage' );
				}
			} else if ( etat === 'personnage' ) {
				var persos = listePersonnages();
				var p = persos[ trouver( persos, ec.choix.personnage ) ];
				if ( ! p ) {
					return;
				}
				if ( ! p.debloque ) {
					annoncer( descriptionPersonnage( p ) );
					return;
				}
				try {
					contexte.stockage.choisirPersonnage( univers().slug, p.slug );
				} catch ( e ) {}
				ec.aller( 'niveaux' );
			} else if ( etat === 'niveaux' ) {
				var niveaux = listeNiveaux();
				var n = niveaux[ trouver( niveaux, ec.choix.niveau ) ];
				if ( ! n ) {
					return;
				}
				if ( ! n.debloque ) {
					annoncer( descriptionNiveau( n ) );
					return;
				}
				demarrer( n.slug );
			} else if ( etat === 'pause' ) {
				ec.aller( 'jeu' );
				annoncer( 'Reprise.' );
			} else if ( etat === 'fin' ) {
				rejouer();
			} else if ( etat === 'victoire' ) {
				var suivant = niveauSuivant();
				if ( suivant ) {
					demarrer( suivant );
				} else {
					ec.aller( 'niveaux' );
				}
			}
		}

		/* Déplace la sélection de l'écran courant (pas : ±1, ou ±colonnes). */
		function deplacer( pas ) {
			var liste;
			var cle;
			if ( ec.etat === 'personnage' ) {
				liste = listePersonnages();
				cle = 'personnage';
			} else if ( ec.etat === 'niveaux' ) {
				liste = listeNiveaux();
				cle = 'niveau';
			} else if ( ec.etat === 'univers' ) {
				liste = listeUnivers();
				cle = 'univers';
			} else {
				return;
			}
			if ( ! liste.length ) {
				return;
			}
			var i = trouver( liste, ec.choix[ cle ] );
			var j = ynWE.limiter( ( i === -1 ? 0 : i ) + pas, 0, liste.length - 1 );
			if ( j === i ) {
				return;
			}
			ec.choix[ cle ] = liste[ j ].slug;
			signalerChoix();
			annoncerSelection();
		}

		ec.aller = function ( etat, donnees ) {
			var u = univers();
			if ( etat === 'titre' ) {
				initialiserChoix();
			}
			if ( etat === 'univers' && ! avecUnivers() ) {
				etat = 'personnage';
			}
			if ( etat === 'univers' && u ) {
				ec.choix.univers = u.slug;
			}
			if ( etat === 'personnage' || etat === 'niveaux' ) {
				preparer();
			}
			if ( etat === 'niveaux' && ! ec.choix.niveau ) {
				ec.choix.niveau = 'arcade';
			}
			if ( etat === 'jeu' && ec.etat !== 'pause' ) {
				lireRecord();
				var partie = partieCourante();
				if ( partie && partie.niveau && partie.niveau.slug ) {
					ec.choix.niveau = partie.niveau.slug;
				}
				if ( partie && partie.monde && partie.monde.personnage ) {
					ec.choix.personnage = partie.monde.personnage.slug || ec.choix.personnage;
				}
			}
			if ( etat === 'fin' || etat === 'victoire' ) {
				var bilan = donnees || {};
				bilan.score = bilan.score || 0;
				bilan.record = bilan.score > ec.meilleur;
				ec.meilleur = Math.max( ec.meilleur, bilan.score );
				ec.bilan = bilan;
				if ( etat === 'fin' ) {
					annoncer( 'Fin de partie. Score : ' + bilan.score + ( bilan.record ? '. Nouveau record !' : '. Meilleur score : ' + ec.meilleur + '.' ) + ' Entrée pour rejouer, R pour choisir un niveau.' );
				} else {
					var etoiles = bilan.etoiles || 0;
					var partieFinie = partieCourante();
					var message = partieFinie && partieFinie.niveau && partieFinie.niveau.textes ? partieFinie.niveau.textes.victoire : '';
					var debloque = texteNouveautes( bilan.nouveautes, ' ' );
					annoncer( 'Victoire ! ' + ( message ? message + ' ' : '' ) + 'Score : ' + bilan.score + '. ' + etoiles + ( etoiles > 1 ? ' étoiles' : ' étoile' ) + ' sur 3.' + ( bilan.record ? ' Nouveau record !' : '' ) + ( debloque ? ' ' + debloque : '' ) + ( niveauSuivant() ? ' Entrée : niveau suivant, R : niveaux.' : ' Entrée ou R : niveaux.' ) );
				}
			}
			var precedent = ec.etat;
			ec.etat = etat;
			ec.debut = ec.temps;
			ynWE.evenements.emettre( 'partie:etat', { etat: etat, precedent: precedent } );
			if ( etat !== precedent ) {
				annoncerEcran();
			}
			signalerChoix();
		};

		/* Choix depuis un contrôle DOM (<select>) : va à l'écran correspondant. */
		ec.choisir = function ( type, slug ) {
			if ( ec.etat === 'jeu' || ec.etat === 'chargement' || ec.etat === 'erreur' ) {
				return;
			}
			if ( type === 'univers' ) {
				ec.choix.univers = slug;
				if ( ec.etat !== 'univers' ) {
					ec.aller( 'univers' );
				} else {
					annoncerSelection();
				}
				return;
			}
			var cle = type === 'niveau' ? 'niveau' : 'personnage';
			var ecranCible = type === 'niveau' ? 'niveaux' : 'personnage';
			ec.choix[ cle ] = slug;
			if ( ec.etat !== ecranCible ) {
				ec.aller( ecranCible );
			} else {
				signalerChoix();
				annoncerSelection();
			}
		};

		ec.listes = function () {
			return {
				univers: listeUnivers(),
				personnages: listePersonnages(),
				niveaux: listeNiveaux(),
				choix: ec.choix,
				avecUnivers: avecUnivers(),
			};
		};

		ec.preparer = preparer;
		ec.deblocages = deblocages;
		ec.nouveautes = nouveautes;

		ec.surAction = function ( action, nom ) {
			var etat = ec.etat;
			if ( etat === 'chargement' || etat === 'erreur' ) {
				if ( action === 'echap' ) {
					contexte.fermer();
				}
				return;
			}
			if ( action === 'echap' ) {
				if ( etat === 'jeu' ) {
					ec.aller( 'pause' );
					annoncer( 'Pause. Échap de nouveau pour fermer le jeu, P ou Entrée pour reprendre, R pour choisir un niveau.' );
				} else {
					contexte.fermer();
				}
			} else if ( action === 'pause' ) {
				if ( etat === 'jeu' ) {
					ec.aller( 'pause' );
					annoncer( 'Pause.' );
				} else if ( etat === 'pause' ) {
					ec.aller( 'jeu' );
					annoncer( 'Reprise.' );
				}
			} else if ( action === 'niveaux' ) {
				if ( etat !== 'jeu' && etat !== 'niveaux' ) {
					ec.aller( 'niveaux' );
				}
			} else if ( action === 'jouer' ) {
				if ( etat === 'jeu' || etat === 'pause' || etat === 'fin' || etat === 'victoire' ) {
					rejouer();
				} else {
					valider();
				}
			} else if ( action === 'valider' || action === 'epee' ) {
				if ( etat !== 'jeu' ) {
					valider();
				}
			} else if ( action === 'tactile' ) {
				if ( MENUS[ etat ] && etat !== 'titre' && ( nom === 'gauche' || nom === 'droite' ) ) {
					deplacer( nom === 'gauche' ? -1 : 1 );
				} else if ( nom === 'bas' ) {
					// ▼ : descend d'une rangée dans les menus, ne valide jamais.
					if ( MENUS[ etat ] && etat !== 'titre' ) {
						deplacer( etat === 'niveaux' ? COLONNES_NIVEAUX : 1 );
					}
				} else if ( MENUS[ etat ] || etat === 'fin' || etat === 'victoire' ) {
					valider();
				}
			} else if ( action === 'gauche' || action === 'droite' ) {
				deplacer( action === 'gauche' ? -1 : 1 );
			} else if ( action === 'haut' || action === 'bas' ) {
				var pas = ec.etat === 'niveaux' ? COLONNES_NIVEAUX : 1;
				deplacer( action === 'haut' ? -pas : pas );
			}
		};

		/* Clic ou toucher sur le canvas (coordonnées logiques) : choisit, puis valide au second appui. */
		ec.surPointeur = function ( x, y ) {
			var etat = ec.etat;
			if ( etat === 'titre' || etat === 'fin' || etat === 'victoire' ) {
				valider();
				return;
			}
			for ( var i = 0; i < zones.length; i++ ) {
				var z = zones[ i ];
				if ( x >= z.x && x <= z.x + z.l && y >= z.y && y <= z.y + z.h ) {
					var cle = { personnage: 'personnage', niveaux: 'niveau', univers: 'univers' }[ etat ];
					if ( ! cle ) {
						return;
					}
					if ( ec.choix[ cle ] === z.slug ) {
						valider();
					} else {
						ec.choix[ cle ] = z.slug;
						signalerChoix();
						annoncerSelection();
					}
					return;
				}
			}
		};

		/* ------------------------------------------------------------------ */
		/* Dessin : primitives d'interface (coordonnées de l'écran)            */
		/* ------------------------------------------------------------------ */

		function rectangleArrondi( x, y, l, h, rayon ) {
			var ctx = r.ctx;
			ctx.beginPath();
			ctx.moveTo( x + rayon, y );
			ctx.arcTo( x + l, y, x + l, y + h, rayon );
			ctx.arcTo( x + l, y + h, x, y + h, rayon );
			ctx.arcTo( x, y + h, x, y, rayon );
			ctx.arcTo( x, y, x + l, y, rayon );
			ctx.closePath();
		}

		/*
		 * Carte de sélection. Verrouillée (grisee) : fond carte opaque (le décor ne passe pas au
		 * travers, les libellés en texte-faible gardent leur contraste ≥ 4,5:1 du thème) et
		 * contour en tirets ; le verrou se lit au cadenas et au libellé, pas à une opacité réduite.
		 */
		function carte( x, y, l, h, choisie, grisee ) {
			var ctx = r.ctx;
			var p = r.palette;
			ctx.globalAlpha = grisee ? 1 : 0.92;
			ctx.fillStyle = p.carte;
			rectangleArrondi( x, y, l, h, 6 );
			ctx.fill();
			ctx.globalAlpha = 1;
			ctx.lineWidth = choisie ? 2 : 1;
			ctx.strokeStyle = choisie ? p.accent : ( grisee ? p.texteFaible : p.filet );
			if ( grisee && ! choisie && ctx.setLineDash ) {
				ctx.setLineDash( [ 4, 3 ] );
			}
			rectangleArrondi( x, y, l, h, 6 );
			ctx.stroke();
			if ( ctx.setLineDash ) {
				ctx.setLineDash( [] );
			}
		}

		/* Étoile à 5 branches (pleine ou contour), échelle 0…1. */
		function etoile( x, y, rayon, pleine, echelle ) {
			var ctx = r.ctx;
			var p = r.palette;
			var e = typeof echelle === 'number' ? echelle : 1;
			if ( e <= 0 ) {
				return;
			}
			ctx.beginPath();
			for ( var i = 0; i < 10; i++ ) {
				var angle = -Math.PI / 2 + i * Math.PI / 5;
				var ray = ( i % 2 ? rayon * 0.45 : rayon ) * e;
				ctx[ i ? 'lineTo' : 'moveTo' ]( x + Math.cos( angle ) * ray, y + Math.sin( angle ) * ray );
			}
			ctx.closePath();
			ctx.lineJoin = 'round';
			ctx.lineWidth = 1.5;
			ctx.strokeStyle = pleine ? p.fond : p.filet;
			ctx.stroke();
			if ( pleine ) {
				ctx.fillStyle = p.accent2;
				ctx.fill();
			}
		}

		function rangeeEtoiles( x, y, n, rayon, apparition ) {
			for ( var i = 0; i < 3; i++ ) {
				var cx = x + ( i - 1 ) * rayon * 2.4;
				etoile( cx, y, rayon, false, 1 );
				if ( i < n ) {
					etoile( cx, y, rayon, true, apparition ? apparition( i ) : 1 );
				}
			}
		}

		function cadenas( x, y, couleur ) {
			var ctx = r.ctx;
			ctx.strokeStyle = couleur || r.palette.texteFaible;
			ctx.fillStyle = couleur || r.palette.texteFaible;
			ctx.lineWidth = 1.6;
			ctx.beginPath();
			ctx.arc( x, y - 2, 3.4, Math.PI, 0 );
			ctx.stroke();
			ctx.fillRect( x - 5, y - 2, 10, 8 );
		}

		/* Texte coupé en lignes de largeur maximale ; renvoie le nombre de lignes. */
		function paragraphe( contenu, x, y, largeurMax, taille, couleur, lignesMax ) {
			var ctx = r.ctx;
			ctx.font = '700 ' + taille + 'px ' + r.police;
			var mots = String( contenu || '' ).split( /\s+/ );
			var lignes = [];
			var ligne = '';
			mots.forEach( function ( mot ) {
				var essai = ligne ? ligne + ' ' + mot : mot;
				if ( ligne && ctx.measureText( essai ).width > largeurMax ) {
					lignes.push( ligne );
					ligne = mot;
				} else {
					ligne = essai;
				}
			} );
			if ( ligne ) {
				lignes.push( ligne );
			}
			lignes = lignes.slice( 0, lignesMax || 3 );
			lignes.forEach( function ( l, i ) {
				r.texte( l, x, y + i * ( taille + 3 ), taille, 'center', couleur );
			} );
			return lignes.length;
		}

		function consigne( texte ) {
			r.texte( texte, LARGEUR / 2, HAUTEUR - 11, 10, 'center', r.palette.texteFaible );
		}

		/* ------------------------------------------------------------------ */
		/* Écrans de sélection                                                 */
		/* ------------------------------------------------------------------ */

		function dessinerUnivers() {
			var p = r.palette;
			var liste = listeUnivers();
			r.texte( 'Choisissez un univers', LARGEUR / 2, 34, 18, 'center', p.accent, 800 );
			var l = 150;
			var h = 90;
			var ecart = 14;
			var parLigne = Math.min( 3, liste.length );
			var x0 = ( LARGEUR - ( parLigne * l + ( parLigne - 1 ) * ecart ) ) / 2;
			liste.forEach( function ( u, i ) {
				var x = x0 + ( i % 3 ) * ( l + ecart );
				var y = 64 + Math.floor( i / 3 ) * ( h + ecart );
				var choisie = u.slug === ec.choix.univers;
				carte( x, y, l, h, choisie, false );
				paragraphe( u.titre, x + l / 2, y + h / 2 - 6, l - 16, 14, choisie ? p.texteFort : p.texte, 2 );
				zones.push( { x: x, y: y, l: l, h: h, slug: u.slug } );
			} );
			consigne( '← → : choisir · Entrée : valider · Échap : fermer' );
		}

		function dessinerPersonnages() {
			var p = r.palette;
			var liste = listePersonnages();
			r.texte( 'Choisissez votre personnage', LARGEUR / 2, 26, 18, 'center', p.accent, 800 );
			var n = Math.max( 1, liste.length );
			var l = Math.min( 132, ( LARGEUR - 40 - ( n - 1 ) * 12 ) / n );
			var h = 150;
			var ecart = 12;
			var x0 = ( LARGEUR - ( n * l + ( n - 1 ) * ecart ) ) / 2;
			var y = 44;
			var choisi = null;
			liste.forEach( function ( perso, i ) {
				var x = x0 + i * ( l + ecart );
				var estChoisi = perso.slug === ec.choix.personnage;
				if ( estChoisi ) {
					choisi = perso;
				}
				var leve = estChoisi && ! r.mouvementReduit ? Math.sin( ( ec.temps - ec.debut ) * 4 ) * 1.5 - 2 : 0;
				carte( x, y + leve, l, h, estChoisi, ! perso.debloque );
				var planche = perso.personnage && perso.personnage.planche;
				if ( planche && planche.meta && ynWE.animation( planche.meta, 'repos' ) ) {
					var t = estChoisi ? ec.temps : 0;
					r.sprite( planche, 'repos', ynWE.imageCourante( planche.meta, 'repos', t ), x + l / 2, y + leve + 108, 1, 1, perso.personnage.lissage !== false, perso.debloque ? 1 : 0.3 );
				} else if ( ec.preparation !== 'pret' ) {
					r.texte( '…', x + l / 2, y + leve + 70, 16, 'center', p.texteFaible );
				}
				r.texte( perso.nom, x + l / 2, y + leve + 126, 14, 'center', estChoisi ? p.texteFort : p.texte, 800 );
				if ( ! perso.debloque ) {
					cadenas( x + l / 2, y + leve + 60, p.texteFort );
					r.texte( 'Verrouillé', x + l / 2, y + leve + 142, 9, 'center', p.texteFaible );
				}
				zones.push( { x: x, y: y, l: l, h: h, slug: perso.slug } );
			} );
			if ( choisi ) {
				var texte = choisi.debloque ? choisi.description : ( choisi.condition ? 'Terminez « ' + choisi.condition + ' » pour débloquer ' + choisi.nom + '.' : 'Personnage verrouillé.' );
				paragraphe( texte, LARGEUR / 2, 214, LARGEUR - 60, 11, p.texte, 2 );
			}
			if ( ec.preparation === 'encours' ) {
				r.texte( 'Chargement…', LARGEUR - 12, 26, 10, 'right', p.texteFaible );
			}
			consigne( '← → : choisir · Entrée : valider · Échap : fermer' );
		}

		function dessinerNiveaux() {
			var p = r.palette;
			var liste = listeNiveaux();
			var perso = listePersonnages()[ trouver( listePersonnages(), ec.choix.personnage ) ];
			r.texte( 'Choisissez un niveau', LARGEUR / 2, 24, 18, 'center', p.accent, 800 );
			if ( perso ) {
				r.texte( perso.nom, LARGEUR - 12, 24, 11, 'right', p.texteFaible );
			}
			var rangees = Math.max( 1, Math.ceil( liste.length / COLONNES_NIVEAUX ) );
			var ecart = 10;
			var l = 140;
			var h = Math.min( 78, ( 176 - ( rangees - 1 ) * ecart ) / rangees );
			var x0 = ( LARGEUR - ( COLONNES_NIVEAUX * l + ( COLONNES_NIVEAUX - 1 ) * ecart ) ) / 2;
			var choisi = null;
			liste.forEach( function ( n, i ) {
				var x = x0 + ( i % COLONNES_NIVEAUX ) * ( l + ecart );
				var y = 42 + Math.floor( i / COLONNES_NIVEAUX ) * ( h + ecart );
				var estChoisi = n.slug === ec.choix.niveau;
				if ( estChoisi ) {
					choisi = n;
				}
				carte( x, y, l, h, estChoisi, ! n.debloque );
				var couleur = p.texteFaible; // Verrouillé ou non : texte-faible sur carte (≥ 4,5:1).
				r.texte( n.arcade ? 'Mode libre' : 'Niveau ' + n.numero, x + 8, y + 11, 9, 'left', couleur );
				r.texte( n.titre, x + l / 2, y + h * 0.42, 13, 'center', n.debloque ? ( estChoisi ? p.texteFort : p.texte ) : p.texteFaible, 800 );
				if ( ! n.debloque ) {
					cadenas( x + l - 14, y + 10, p.texteFaible );
				}
				if ( n.arcade ) {
					r.texte( 'Record ' + n.meilleur, x + l / 2, y + h * 0.76, 10, 'center', p.texteFaible );
				} else {
					rangeeEtoiles( x + l / 2, y + h * 0.76, n.etoiles, 5.5 );
				}
				zones.push( { x: x, y: y, l: l, h: h, slug: n.slug } );
			} );
			if ( choisi ) {
				var intro = choisi.arcade ? 'Vagues de Timeres sans fin : tenez le plus longtemps possible.' : ( ( univers().niveaux[ choisi.slug ] || {} ).textes || {} ).intro || '';
				paragraphe( choisi.debloque ? intro : 'Terminez le niveau précédent pour ouvrir celui-ci.', LARGEUR / 2, 234, LARGEUR - 60, 10, p.texte, 1 );
			}
			if ( ec.preparation === 'encours' ) {
				r.texte( 'Chargement…', 12, 24, 10, 'left', p.texteFaible );
			}
			consigne( 'Flèches : choisir · Entrée : jouer · Échap : fermer' );
		}

		/* ------------------------------------------------------------------ */
		/* HUD                                                                 */
		/* ------------------------------------------------------------------ */

		/*
		 * HUD dessiné sur le décor : couleurs ynWE.rendu.HUD (texte clair, contour sombre), les
		 * mêmes dans les trois thèmes puisque le décor ne change pas. Tailles logiques relevées
		 * pour rester lisibles sur un téléphone (canvas ≈ 0,8 × sa taille logique à 412 px).
		 */
		ec.hud = function ( monde, partie ) {
			var j = monde.joueur;
			var H = ynWE.rendu.HUD;
			var infos = partie ? partie.hud() : { vague: 0, banniere: 0 };
			for ( var i = 0; i < j.pvMax; i++ ) {
				r.coeur( 17 + i * 16, 8, i < j.pv, 1.3 );
			}
			r.texteHud( 'Score ' + monde.score, LARGEUR / 2, 15, 14, 'center' );
			r.texteHud( 'Record ' + Math.max( ec.meilleur, monde.score ), LARGEUR - 10, 15, 12, 'right', H.faible );
			var niveau = monde.niveau || {};
			var objectif = niveau.objectif || {};
			// Survie : hud().restant (lot C), à défaut objectif.duree − temps. Pas de « Vague 0 ».
			var restant = typeof infos.restant === 'number' ? infos.restant : ( infos.objectif === 'survie' && typeof objectif.duree === 'number' ? objectif.duree - ( infos.temps || 0 ) : null );
			if ( restant !== null ) {
				r.texteHud( 'Survie ' + chrono( restant ), LARGEUR - 10, 31, 12, 'right', restant <= 10 ? H.accent : H.faible );
			} else if ( infos.vague > 0 ) {
				r.texteHud( 'Vague ' + infos.vague + ( infos.total ? ' / ' + infos.total : '' ), LARGEUR - 10, 31, 12, 'right', H.faible );
			}
			var secondaire = ( monde.personnage.competences || {} ).secondaire;
			if ( secondaire && secondaire.recharge && j.recharges && j.recharges.secondaire > 0 ) {
				r.ctx.fillStyle = H.contour;
				r.ctx.fillRect( 15, 27, 62, 5 );
				r.jauge( 16, 28, 60, 3, 1 - j.recharges.secondaire / secondaire.recharge, '#8fd0ff' );
			}
			if ( infos.boss && infos.boss.pvMax > 0 ) {
				r.texteHud( infos.boss.nom || 'Boss', LARGEUR / 2, 33, 11, 'center' );
				r.ctx.fillStyle = H.contour;
				r.ctx.fillRect( LARGEUR / 2 - 101, 40, 202, 7 );
				r.jauge( LARGEUR / 2 - 100, 41, 200, 5, infos.boss.pv / infos.boss.pvMax, H.danger );
			}
			if ( infos.banniere > 0 && ec.etat === 'jeu' ) {
				var decalage = monde.mouvementReduit ? 0 : Math.max( 0, infos.banniere - 1.7 ) * 200;
				r.ctx.globalAlpha = Math.min( 1, infos.banniere * 2 );
				r.texteHud( 'Vague ' + infos.vague + ( infos.total ? ' / ' + infos.total : '' ), LARGEUR / 2 - decalage, 96, 26, 'center', H.accent, 800 );
				r.ctx.globalAlpha = 1;
			}
		};

		/* ------------------------------------------------------------------ */
		/* Surcouches                                                          */
		/* ------------------------------------------------------------------ */

		function personnageTitre() {
			var u = univers();
			if ( u && ec.choix.personnage && u.personnages[ ec.choix.personnage ] ) {
				return u.personnages[ ec.choix.personnage ];
			}
			return contexte.personnage ? contexte.personnage() : null;
		}

		function dessinerTitre() {
			var p = r.palette;
			var u = univers();
			var manifeste = u ? u.manifeste : {};
			r.texte( manifeste.titre || 'WordEnd', LARGEUR / 2, 56, 34, 'center', p.accent, 800 );
			r.texte( manifeste.sousTitre || '', LARGEUR / 2, 88, 15, 'center' );
			var personnage = personnageTitre();
			if ( personnage && personnage.planche && ynWE.animation( personnage.planche.meta, 'repos' ) ) {
				r.sprite( personnage.planche, 'repos', ynWE.imageCourante( personnage.planche.meta, 'repos', ec.temps ), LARGEUR / 2, 192, 1, 1, personnage.lissage !== false, 1 );
			}
			r.texte( 'Entrée ou « Jouer » pour commencer', LARGEUR / 2, 214, 12, 'center' );
			r.texte( 'Record arcade : ' + progression().arcade.meilleur, LARGEUR / 2, 234, 11, 'center', p.texteFaible );
			var musiques = manifeste.musiques || {};
			var credits = Object.keys( musiques ).map( function ( nom ) {
				return musiques[ nom ].credit;
			} ).filter( Boolean );
			if ( credits.length ) {
				r.texte( 'Musique : ' + credits.join( ' · ' ), LARGEUR / 2, HAUTEUR - 10, 9, 'center', p.texteFaible );
			}
		}

		/* Apparition animée d'une étoile de victoire (instantanée en mouvement réduit). */
		function apparitionEtoile( i ) {
			if ( r.mouvementReduit ) {
				return 1;
			}
			var t = ( ec.temps - ec.debut ) - 0.3 - i * 0.35;
			if ( t <= 0 ) {
				return 0;
			}
			if ( t >= 0.3 ) {
				return 1;
			}
			var k = t / 0.3;
			return k + Math.sin( k * Math.PI ) * 0.35; // Léger rebond.
		}

		function dessinerFin() {
			var p = r.palette;
			var bilan = ec.bilan || { score: 0 };
			var victoire = ec.etat === 'victoire';
			var partie = partieCourante();
			var titreNiv = partie && partie.niveau && partie.niveau.slug !== 'arcade' ? titreNiveau( univers(), partie.niveau.slug ) : '';
			r.texte( victoire ? 'Victoire !' : 'Fin de partie', LARGEUR / 2, 70, 28, 'center', p.accent, 800 );
			var message = victoire && partie && partie.niveau && partie.niveau.textes ? partie.niveau.textes.victoire : '';
			if ( message || titreNiv ) {
				r.texte( message || titreNiv, LARGEUR / 2, 98, 12, 'center', message ? p.texte : p.texteFaible );
			}
			if ( victoire ) {
				rangeeEtoiles( LARGEUR / 2, 128, bilan.etoiles || 0, 13, apparitionEtoile );
				r.texte( 'Score : ' + bilan.score + ( bilan.record ? ' · nouveau record !' : '' ), LARGEUR / 2, 160, 14, 'center' );
				var debloque = texteNouveautes( bilan.nouveautes, ' · ' ).replace( /\.( ·|$)/g, '$1' );
				if ( debloque ) {
					paragraphe( debloque, LARGEUR / 2, 182, LARGEUR - 40, 12, p.accent2, 2 );
				}
				r.texte( niveauSuivant() ? 'Entrée : niveau suivant · R : niveaux' : 'Entrée ou R : niveaux', LARGEUR / 2, debloque ? 214 : 196, 12, 'center' );
			} else {
				r.texte( 'Score : ' + bilan.score, LARGEUR / 2, 124, 16, 'center' );
				r.texte( bilan.record && bilan.score > 0 ? 'Nouveau record !' : 'Record : ' + ec.meilleur, LARGEUR / 2, 148, 12, 'center', p.accent2 );
				r.texte( 'Entrée : rejouer · R : niveaux', LARGEUR / 2, 186, 12, 'center' );
			}
		}

		function surcouche() {
			var p = r.palette;
			var etat = ec.etat;
			zones = [];
			if ( etat !== 'jeu' && etat !== 'chargement' ) {
				r.voile();
			}
			if ( etat === 'titre' ) {
				dessinerTitre();
			} else if ( etat === 'univers' ) {
				dessinerUnivers();
			} else if ( etat === 'personnage' ) {
				dessinerPersonnages();
			} else if ( etat === 'niveaux' ) {
				dessinerNiveaux();
			} else if ( etat === 'pause' ) {
				r.texte( 'Pause', LARGEUR / 2, HAUTEUR / 2 - 14, 28, 'center', p.accent, 800 );
				r.texte( 'P ou Entrée : reprendre · R : niveaux · Échap : fermer', LARGEUR / 2, HAUTEUR / 2 + 16, 12, 'center' );
			} else if ( etat === 'fin' || etat === 'victoire' ) {
				dessinerFin();
			} else if ( etat === 'chargement' ) {
				r.texte( 'Chargement…', LARGEUR / 2, HAUTEUR / 2, 14, 'center' );
			} else if ( etat === 'erreur' ) {
				r.texte( 'Le jeu n’a pas pu être chargé.', LARGEUR / 2, HAUTEUR / 2, 14, 'center', p.erreur );
			}
		}

		/* Dessine une image : décor, monde (en coordonnées du monde), HUD, surcouche. */
		ec.dessiner = function ( monde, partie ) {
			var u = univers();
			var etat = ec.etat;
			var menu = !! MENUS[ etat ];
			if ( monde ) {
				r.camera( monde );
			}
			r.commencer( monde );
			var nomDecor = monde && monde.niveau ? monde.niveau.decor : '';
			var decors = u && u.manifeste ? u.manifeste.decors || {} : {};
			r.decor( u && nomDecor ? u.decors[ nomDecor ] : null, monde ? monde.camera : null, decors[ nomDecor ] ? decors[ nomDecor ].parallaxe : 0, { sol: monde ? monde.sol : 238 } );
			if ( monde && monde.joueur ) {
				r.plateformes( monde );
				if ( ! menu ) {
					monde.ennemis.forEach( function ( e ) {
						ynWE.ennemis.dessiner( r, e, monde );
					} );
					monde.joueur.dessiner( r, monde );
					ynWE.competences.dessinerOndes( r, monde );
					ynWE.ennemis.dessinerProjectiles( r, monde );
				}
				r.particules( monde );
			}
			r.finir();
			if ( monde && monde.joueur && ! menu ) {
				ec.hud( monde, partie );
			}
			surcouche();
		};

		return ec;
	}

	ynWE.ecrans = { creer: creer };
}() );
