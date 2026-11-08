/**
 * Panneau « Programmer les sorties » (sortie « Calendrier sur mesure ») : formulaire
 * « Ajouter des chapitres à un tome » et vue de l'espace équipe ?vue=programmer.
 *
 * - repartir( mots, n ) : partition contiguë des unités en n publications qui minimise la plus
 *   grosse en nombre de mots, puis la somme des carrés ; une unité à 0 mot est collée à la
 *   suivante. Copie exacte de Calendrier::repartir() (PHP) : mêmes groupes, vérifiés par
 *   tests/js/programmer.test.js et tests/test-publication-calendrier.php ;
 * - tableau des unités groupées par publication : case « Nouvelle publication ici », date
 *   (datetime-local) de chaque publication, résumé (aria-live), boutons « Répartir »,
 *   « Recalculer les dates », « Tout en une publication », « Une par chapitre » ;
 * - le calendrier est écrit en JSON dans le champ caché « calendrier »
 *   ({"publications": [{"date": "AAAA-MM-JJTHH:MM", "chapitres": [clés]}]}) ;
 * - réglages (attribut data-yn-programmer-config, ou panneau.ynProgrammer.definir()) :
 *   unites [{cle, libelle, nature, nature_libelle, mots, date}], rythme {jour 1-7, heure} ou
 *   null, dates (dates par défaut du serveur), maintenant, intervalle, depart ;
 * - vue dédiée (form[data-yn-programmer-vue]) : enregistrement par
 *   POST /yume/v1/publications/{id}/calendrier (nonce wp_rest), sans JavaScript par admin-post.
 *
 * Les dates sont des heures du site écrites « AAAA-MM-JJTHH:MM » et calculées sans fuseau
 * (horloge UTC du navigateur utilisée comme calendrier naïf). JavaScript ES2019, sans dépendance.
 */
( function () {
	'use strict';

	/*
	 * Répartition (identique à Calendrier::repartir()).
	 */

	function blocs( mots ) {
		var liste = [];
		var attente = 0;
		mots.forEach( function ( m ) {
			m = Math.max( 0, parseInt( m, 10 ) || 0 );
			attente++;
			if ( m > 0 ) {
				liste.push( [ attente, m ] );
				attente = 0;
			}
		} );
		if ( attente > 0 ) {
			if ( liste.length ) {
				liste[ liste.length - 1 ][ 0 ] += attente;
			} else {
				liste.push( [ attente, 0 ] );
			}
		}
		return liste;
	}

	function repartir( mots, n ) {
		var bl = blocs( mots || [] );
		var b = bl.length;
		if ( ! b ) {
			return [];
		}
		var k = Math.max( 1, Math.min( parseInt( n, 10 ) || 1, b ) );
		var poids = bl.map( function ( x ) {
			return x[ 1 ];
		} );
		var prefixe = [ 0 ];
		poids.forEach( function ( p, i ) {
			prefixe[ i + 1 ] = prefixe[ i ] + p;
		} );
		// 1. Plus petit maximum possible.
		var bas = Math.max.apply( null, poids );
		var haut = prefixe[ b ];
		while ( bas < haut ) {
			var milieu = Math.floor( ( bas + haut ) / 2 );
			var groupes = 1;
			var somme = 0;
			for ( var q = 0; q < b; q++ ) {
				if ( somme + poids[ q ] > milieu ) {
					groupes++;
					somme = 0;
				}
				somme += poids[ q ];
			}
			if ( groupes <= k ) {
				haut = milieu;
			} else {
				bas = milieu + 1;
			}
		}
		var max = bas;
		// 2. Programmation dynamique : somme des carrés minimale, groupes d'au plus max mots.
		var cout = [ { 0: 0 } ];
		var choix = [ null ];
		for ( var g = 1; g <= k; g++ ) {
			cout[ g ] = {};
			choix[ g ] = {};
			for ( var i = g; i <= b - ( k - g ); i++ ) {
				var meilleur = -1;
				var coupure = -1;
				for ( var j = i - 1; j >= g - 1; j-- ) {
					var s = prefixe[ i ] - prefixe[ j ];
					if ( s > max ) {
						break;
					}
					if ( ! Object.prototype.hasOwnProperty.call( cout[ g - 1 ], j ) ) {
						continue;
					}
					var c = cout[ g - 1 ][ j ] + s * s;
					if ( meilleur < 0 || c < meilleur ) {
						meilleur = c;
						coupure = j;
					}
				}
				if ( coupure >= 0 ) {
					cout[ g ][ i ] = meilleur;
					choix[ g ][ i ] = coupure;
				}
			}
		}
		var bornes = [];
		var fin = b;
		for ( var h = k; h >= 1; h-- ) {
			var debut = choix[ h ][ fin ];
			bornes.unshift( [ debut, fin ] );
			fin = debut;
		}
		return bornes.map( function ( borne ) {
			var unites = 0;
			for ( var x = borne[ 0 ]; x < borne[ 1 ]; x++ ) {
				unites += bl[ x ][ 0 ];
			}
			return unites;
		} );
	}

	/*
	 * Dates naïves « AAAA-MM-JJTHH:MM » (heure du site).
	 */

	function lireDate( texte ) {
		var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec( texte || '' );
		return m ? Date.UTC( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ] ) : null;
	}

	function deux( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function ecrireDate( ms ) {
		var d = new Date( ms );
		return d.getUTCFullYear() + '-' + deux( d.getUTCMonth() + 1 ) + '-' + deux( d.getUTCDate() ) + 'T' + deux( d.getUTCHours() ) + ':' + deux( d.getUTCMinutes() );
	}

	/**
	 * Prochaine date du rythme (jour 1 = lundi … 7 = dimanche, heure « HH:MM ») strictement
	 * après ms, comme yume_prochaine_sortie_rythme().
	 */
	function prochainRythme( ms, regle ) {
		var heure = /^(\d{2}):(\d{2})$/.exec( regle.heure || '' ) || [ '', '18', '00' ];
		var d = new Date( ms );
		var cible = Date.UTC( d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate(), +heure[ 1 ], +heure[ 2 ] );
		var jour = new Date( cible ).getUTCDay() || 7;
		cible += ( ( regle.jour - jour + 7 ) % 7 ) * 86400000;
		if ( cible <= ms ) {
			cible += 7 * 86400000;
		}
		return cible;
	}

	/**
	 * Dates de n publications : à partir de depart (vide : maintenant), au rythme (dates du
	 * serveur tant que le départ ne les dépasse pas) ou tous les intervalle jours ; la première
	 * reste vide (« maintenant ») sans départ ni rythme, comme Service::calendrier().
	 */
	function datesParDefaut( config, n, depart, intervalle ) {
		var dates = [];
		var serveur = config.dates || [];
		var ms = lireDate( depart );
		var maintenant = lireDate( config.maintenant ) || Date.now();
		if ( config.rythme ) {
			var ref = Math.max( maintenant, ms !== null ? ms - 60000 : maintenant );
			var depasse = ms !== null && serveur[ 0 ] && lireDate( serveur[ 0 ] ) <= ms - 60000;
			for ( var i = 0; i < n; i++ ) {
				if ( ! depasse && serveur[ i ] ) {
					dates.push( serveur[ i ] );
					ref = lireDate( serveur[ i ] );
				} else {
					ref = prochainRythme( ref, config.rythme );
					dates.push( ecrireDate( ref ) );
				}
			}
			return dates;
		}
		var pas = Math.max( 1, Math.min( 60, parseInt( intervalle, 10 ) || 7 ) );
		var base = ms !== null ? ms : maintenant;
		for ( var j = 0; j < n; j++ ) {
			dates.push( j === 0 ? ( ms !== null ? ecrireDate( ms ) : '' ) : ecrireDate( base + j * pas * 86400000 ) );
		}
		return dates;
	}

	/**
	 * « Recalculer les dates » : la première date est gardée, les suivantes la suivent.
	 */
	function datesDepuis( config, n, premiere, intervalle ) {
		var maintenant = lireDate( config.maintenant ) || Date.now();
		var ms = lireDate( premiere );
		var dates = [ premiere || '' ];
		var ref = ms !== null ? ms : maintenant;
		var pas = Math.max( 1, Math.min( 60, parseInt( intervalle, 10 ) || 7 ) );
		for ( var i = 1; i < n; i++ ) {
			ref = config.rythme ? prochainRythme( ref, config.rythme ) : ( ms !== null ? ms : maintenant ) + i * pas * 86400000;
			dates.push( ecrireDate( ref ) );
		}
		return dates;
	}

	var api = {
		repartir: repartir,
		datesParDefaut: datesParDefaut,
		datesDepuis: datesDepuis,
		prochainRythme: prochainRythme,
		lireDate: lireDate,
		ecrireDate: ecrireDate,
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = api;
	}
	if ( typeof document === 'undefined' ) {
		return;
	}
	window.ynRepartir = repartir;

	/*
	 * Panneau.
	 */

	var NF = typeof Intl !== 'undefined' ? new Intl.NumberFormat( 'fr-FR' ) : null;

	function nombre( n ) {
		return NF ? NF.format( n ) : String( n );
	}

	function el( balise, classe, texte ) {
		var e = document.createElement( balise );
		if ( classe ) {
			e.className = classe;
		}
		if ( texte !== undefined && texte !== null ) {
			e.textContent = texte;
		}
		return e;
	}

	function pluriel( n, un, plusieurs ) {
		return nombre( n ) + ' ' + ( n > 1 ? plusieurs : un );
	}

	function lireConfig( panneau ) {
		try {
			return JSON.parse( panneau.getAttribute( 'data-yn-programmer-config' ) || '{}' ) || {};
		} catch ( err ) {
			return {};
		}
	}

	function initialiser( panneau ) {
		if ( panneau.ynProgrammer ) {
			return;
		}
		var id = ( panneau.getAttribute( 'aria-labelledby' ) || 'yn-programmer-titre' ).replace( /-titre$/, '' );
		var champN = panneau.querySelector( '[data-yn-programmer-n]' );
		var resume = panneau.querySelector( '[data-yn-programmer-resume]' );
		var liste = panneau.querySelector( '[data-yn-programmer-liste]' );
		var boutons = panneau.querySelector( '[data-yn-programmer-boutons]' );
		var cache = panneau.querySelector( '[data-yn-programmer-calendrier]' );
		var sansJs = panneau.querySelector( '[data-yn-programmer-sans-js]' );
		var config = {};
		var unites = [];
		var debuts = [];
		var manuel = {};
		var depart = '';
		var intervalle = 7;

		panneau.classList.add( 'is-js' );
		if ( sansJs ) {
			sansJs.hidden = true;
		}

		function cles() {
			return unites.map( function ( u ) {
				return u.cle;
			} ).join( '|' );
		}

		function appliquerTailles( tailles ) {
			debuts = unites.map( function () {
				return false;
			} );
			var i = 0;
			tailles.forEach( function ( t ) {
				if ( i < debuts.length ) {
					debuts[ i ] = true;
				}
				i += t;
			} );
			if ( debuts.length ) {
				debuts[ 0 ] = true;
			}
		}

		function publications() {
			var liste = [];
			unites.forEach( function ( u, i ) {
				if ( debuts[ i ] || ! liste.length ) {
					liste.push( { unites: [] } );
				}
				liste[ liste.length - 1 ].unites.push( u );
			} );
			return liste;
		}

		function dates( pubs ) {
			var defaut = datesParDefaut( config, pubs.length, depart, intervalle );
			return pubs.map( function ( p, k ) {
				var cle = p.unites[ 0 ].cle;
				return Object.prototype.hasOwnProperty.call( manuel, cle ) ? manuel[ cle ] : defaut[ k ];
			} );
		}

		function mots( p ) {
			return p.unites.reduce( function ( s, u ) {
				return s + ( parseInt( u.mots, 10 ) || 0 );
			}, 0 );
		}

		function ecrire( pubs, ds ) {
			cache.value = unites.length ? JSON.stringify( {
				publications: pubs.map( function ( p, k ) {
					return {
						date: ds[ k ] || '',
						chapitres: p.unites.map( function ( u ) {
							return u.cle;
						} ),
					};
				} ),
			} ) : '';
			if ( champN ) {
				champN.max = String( Math.max( 1, unites.length ) );
				champN.value = String( Math.max( 1, pubs.length ) );
			}
			if ( ! unites.length ) {
				resume.textContent = 'Aucun chapitre à sortir pour le moment.';
				return;
			}
			var tailles = pubs.map( mots );
			var total = tailles.reduce( function ( s, t ) {
				return s + t;
			}, 0 );
			resume.textContent = pluriel( pubs.length, 'publication', 'publications' )
				+ ' · ' + nombre( Math.round( total / pubs.length ) ) + ' mots en moyenne'
				+ ( pubs.length > 1 ? ' · de ' + nombre( Math.min.apply( null, tailles ) ) + ' à ' + nombre( Math.max.apply( null, tailles ) ) : '' );
		}

		function rendre( focus ) {
			var pubs = publications();
			var ds = dates( pubs );
			liste.textContent = '';
			if ( boutons ) {
				boutons.hidden = ! unites.length;
			}
			if ( ! unites.length ) {
				ecrire( pubs, ds );
				return;
			}
			var table = el( 'table', 'yn-programmer__table' );
			var legende = el( 'caption', 'yn-visually-hidden', 'Chapitres à sortir, groupés par publication' );
			table.appendChild( legende );
			var thead = el( 'thead' );
			var tr = el( 'tr' );
			[ 'Nouvelle publication ici', 'Nature', 'Chapitre', 'Mots' ].forEach( function ( t ) {
				var th = el( 'th', '', t );
				th.scope = 'col';
				tr.appendChild( th );
			} );
			thead.appendChild( tr );
			table.appendChild( thead );
			var index = 0;
			pubs.forEach( function ( p, k ) {
				var tbody = el( 'tbody', 'yn-programmer__groupe' );
				var tete = el( 'tr', 'yn-programmer__entete' );
				var th = el( 'th', '' );
				th.colSpan = 4;
				th.scope = 'colgroup';
				var champId = id + '-date-' + k;
				th.appendChild( el( 'strong', 'yn-programmer__numero', 'Publication ' + ( k + 1 ) ) );
				var label = el( 'label', 'yn-programmer__date' );
				label.htmlFor = champId;
				label.appendChild( el( 'span', 'yn-visually-hidden', 'Date de la publication ' + ( k + 1 ) ) );
				var champ = el( 'input' );
				champ.type = 'datetime-local';
				champ.id = champId;
				champ.value = ds[ k ] || '';
				champ.setAttribute( 'data-yn-programmer-date', p.unites[ 0 ].cle );
				champ.setAttribute( 'aria-describedby', champId + '-aide' );
				label.appendChild( champ );
				th.appendChild( label );
				th.appendChild( el( 'span', 'yn-muted yn-programmer__aide', ds[ k ] ? '' : 'vide : maintenant' ) ).id = champId + '-aide';
				th.appendChild( el( 'span', 'yn-programmer__mots', pluriel( mots( p ), 'mot', 'mots' ) + ' · ' + pluriel( p.unites.length, 'chapitre', 'chapitres' ) ) );
				tete.appendChild( th );
				tbody.appendChild( tete );
				p.unites.forEach( function ( u ) {
					var i = index++;
					var ligne = el( 'tr', 'yn-programmer__unite' + ( i > 0 && debuts[ i ] ? ' is-debut' : '' ) );
					var td = el( 'td', 'yn-programmer__coupure' );
					var caseId = id + '-coupure-' + i;
					var coche = el( 'input' );
					coche.type = 'checkbox';
					coche.id = caseId;
					coche.checked = i === 0 || !! debuts[ i ];
					coche.disabled = i === 0;
					coche.setAttribute( 'data-yn-programmer-coupure', String( i ) );
					var lab = el( 'label', 'yn-visually-hidden', 'Nouvelle publication à partir de « ' + u.libelle + ' »' );
					lab.htmlFor = caseId;
					td.appendChild( coche );
					td.appendChild( lab );
					ligne.appendChild( td );
					var tdNature = el( 'td' );
					tdNature.appendChild( el( 'span', 'yn-chip yn-programmer__nature yn-programmer__nature--' + String( u.nature || 'chapitre' ).replace( /[^a-z0-9-]/g, '' ), u.nature_libelle || u.nature || 'Chapitre' ) );
					ligne.appendChild( tdNature );
					var thLib = el( 'th', 'yn-programmer__libelle', u.libelle );
					thLib.scope = 'row';
					if ( u.date ) {
						thLib.appendChild( el( 'span', 'yn-muted yn-programmer__programme', ' · déjà programmé' ) );
					}
					ligne.appendChild( thLib );
					ligne.appendChild( el( 'td', 'yn-programmer__nombre', nombre( parseInt( u.mots, 10 ) || 0 ) ) );
					tbody.appendChild( ligne );
				} );
				table.appendChild( tbody );
			} );
			liste.appendChild( table );
			ecrire( pubs, ds );
			if ( focus ) {
				var cible = liste.querySelector( focus );
				if ( cible ) {
					cible.focus();
				}
			}
		}

		function definir( nouvelle ) {
			var avant = cles();
			config = nouvelle || {};
			unites = Array.isArray( config.unites ) ? config.unites : [];
			if ( config.depart !== undefined ) {
				depart = config.depart || '';
			}
			if ( config.intervalle ) {
				intervalle = config.intervalle;
			}
			// Mêmes unités : coupures et dates choisies gardées. Sinon : chapitres déjà programmés
			// groupés par date (vue dédiée), puis une publication par unité.
			if ( cles() !== avant || debuts.length !== unites.length ) {
				manuel = {};
				if ( unites.some( function ( u ) {
					return !! u.date;
				} ) ) {
					debuts = unites.map( function ( u, i ) {
						return i === 0 || ! u.date || u.date !== unites[ i - 1 ].date;
					} );
					unites.forEach( function ( u, i ) {
						if ( debuts[ i ] && u.date ) {
							manuel[ u.cle ] = u.date;
						}
					} );
				} else {
					appliquerTailles( repartir( unites.map( function ( u ) {
						return u.mots;
					} ), unites.length ) );
				}
			}
			rendre();
		}

		function reglages( r ) {
			if ( r && r.depart !== undefined ) {
				depart = r.depart || '';
			}
			if ( r && r.intervalle ) {
				intervalle = r.intervalle;
			}
			rendre();
		}

		function garderManuelles() {
			// Une date choisie à la main suit sa publication tant qu'elle existe.
			var premieres = {};
			publications().forEach( function ( p ) {
				premieres[ p.unites[ 0 ].cle ] = true;
			} );
			Object.keys( manuel ).forEach( function ( cle ) {
				if ( ! premieres[ cle ] ) {
					delete manuel[ cle ];
				}
			} );
		}

		liste.addEventListener( 'change', function ( e ) {
			var cible = e.target;
			if ( cible.hasAttribute( 'data-yn-programmer-coupure' ) ) {
				var i = parseInt( cible.getAttribute( 'data-yn-programmer-coupure' ), 10 );
				if ( i > 0 ) {
					debuts[ i ] = cible.checked;
				}
				garderManuelles();
				rendre( '[data-yn-programmer-coupure="' + i + '"]' );
			} else if ( cible.hasAttribute( 'data-yn-programmer-date' ) ) {
				manuel[ cible.getAttribute( 'data-yn-programmer-date' ) ] = cible.value;
				var pubs = publications();
				ecrire( pubs, dates( pubs ) );
			}
		} );

		panneau.addEventListener( 'click', function ( e ) {
			var bouton = e.target.closest ? e.target.closest( '[data-yn-programmer-action]' ) : null;
			if ( ! bouton ) {
				return;
			}
			var action = bouton.getAttribute( 'data-yn-programmer-action' );
			var motsUnites = unites.map( function ( u ) {
				return u.mots;
			} );
			if ( action === 'repartir' ) {
				appliquerTailles( repartir( motsUnites, parseInt( champN.value, 10 ) || 1 ) );
				garderManuelles();
			} else if ( action === 'une' ) {
				appliquerTailles( [ unites.length ] );
				garderManuelles();
			} else if ( action === 'chacun' ) {
				appliquerTailles( unites.map( function () {
					return 1;
				} ) );
				garderManuelles();
			} else if ( action === 'recalculer' ) {
				var pubs = publications();
				var premiere = dates( pubs )[ 0 ] || '';
				var calcul = datesDepuis( config, pubs.length, premiere, intervalle );
				manuel = {};
				pubs.forEach( function ( p, k ) {
					manuel[ p.unites[ 0 ].cle ] = calcul[ k ];
				} );
			}
			rendre();
		} );
		if ( champN ) {
			champN.addEventListener( 'keydown', function ( e ) {
				// Entrée dans le champ : répartir plutôt qu'envoyer le formulaire.
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					appliquerTailles( repartir( unites.map( function ( u ) {
						return u.mots;
					} ), parseInt( champN.value, 10 ) || 1 ) );
					garderManuelles();
					rendre();
				}
			} );
		}

		panneau.ynProgrammer = {
			definir: definir,
			reglages: reglages,
			calendrier: function () {
				return cache.value;
			},
			nbPublications: function () {
				return unites.length ? publications().length : 0;
			},
		};
		definir( lireConfig( panneau ) );
	}

	/**
	 * Vue de l'espace équipe : enregistrement par l'API REST.
	 */
	function initialiserVue( form ) {
		var panneau = form.querySelector( '[data-yn-programmer]' );
		var racine = form.closest( '[data-yn-rest]' );
		var retour = form.querySelector( '[data-yn-retour]' );
		var depart = form.querySelector( '[data-yn-programmer-depart]' );
		var intervalle = form.querySelector( '[data-yn-programmer-intervalle]' );
		var annoncer = form.querySelector( '[data-yn-programmer-annoncer]' );
		if ( ! panneau || ! racine || ! window.fetch ) {
			return;
		}
		initialiser( panneau );
		var majReglages = function () {
			panneau.ynProgrammer.reglages( {
				depart: depart ? depart.value : '',
				intervalle: intervalle ? intervalle.value : 7,
			} );
		};
		[ depart, intervalle ].forEach( function ( champ ) {
			if ( champ ) {
				champ.addEventListener( 'change', majReglages );
			}
		} );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var bouton = form.querySelector( 'button[type="submit"]' );
			var corps = {
				calendrier: panneau.ynProgrammer.calendrier(),
			};
			if ( annoncer ) {
				corps.annoncer = !! annoncer.checked;
			}
			if ( ! window.confirm( 'Enregistrer ce calendrier ? ' + pluriel( panneau.ynProgrammer.nbPublications(), 'publication', 'publications' ) + ' : les chapitres déjà programmés prennent leurs nouvelles dates.' ) ) {
				return;
			}
			if ( bouton ) {
				bouton.disabled = true;
			}
			retour.className = 'yn-team__retour';
			retour.textContent = 'Enregistrement…';
			fetch( racine.getAttribute( 'data-yn-rest' ) + 'yume/v1/publications/' + form.getAttribute( 'data-tome' ) + '/calendrier', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': racine.getAttribute( 'data-yn-nonce' ) || '',
				},
				body: JSON.stringify( corps ),
			} ).then( function ( reponse ) {
				return reponse.json().then( function ( donnees ) {
					if ( ! reponse.ok ) {
						throw new Error( donnees && donnees.message ? donnees.message : 'L’enregistrement a échoué. Réessayez.' );
					}
					return donnees;
				} );
			} ).then( function ( donnees ) {
				retour.className = 'yn-team__retour yn-team__retour--ok';
				retour.textContent = donnees.message || 'Calendrier enregistré.';
				if ( donnees.programmer ) {
					panneau.ynProgrammer.definir( Object.assign( {}, donnees.programmer, { depart: depart ? depart.value : '' } ) );
				}
			} ).catch( function ( erreur ) {
				retour.className = 'yn-team__retour yn-team__retour--erreur';
				retour.textContent = erreur.message;
			} ).finally( function () {
				if ( bouton ) {
					bouton.disabled = false;
				}
			} );
		} );
	}

	function demarrer() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-yn-programmer-vue]' ), initialiserVue );
		Array.prototype.forEach.call( document.querySelectorAll( '[data-yn-programmer]' ), initialiser );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', demarrer );
	} else {
		demarrer();
	}
}() );
