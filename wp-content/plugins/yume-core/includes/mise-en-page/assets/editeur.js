/**
 * Vue d'équipe « Mettre en page un chapitre » (module mise-en-page, docs/10 lot E).
 *
 * Charge les unités du chapitre (GET /yume/v1/chapitres/{id}/mise-en-page), les rend dans une
 * colonne de lecture identique au site (.yn-reader), et permet :
 * - la sélection : clic, Maj + clic (plage), Ctrl/Cmd + clic (ajout), glisser (plage), ↑ ↓ (Maj :
 *   étendre), Échap (vider) ;
 * - les styles de la palette ou du clavier : R, D, P, C, S (séparateur avant la sélection),
 *   T (intertitre / paragraphe) ;
 * - le texte en place : double clic ou Entrée → contenteditable (Ctrl+I, Ctrl+B, Entrée = scinder,
 *   Retour arrière en début = fusionner, Échap = annuler, clic ailleurs = valider), HTML nettoyé
 *   (mêmes balises que le serveur), tiret de dialogue posé par le style ;
 * - les actions groupées, annuler / rétablir (pile en mémoire), Enregistrer (Ctrl+S, PUT, 409 de
 *   conflit), avertissement beforeunload, verrou souple (POST …/presence toutes les 2 minutes).
 *
 * Le double clic d'un paragraphe commence par un clic qui le sélectionne : c'est voulu (le
 * paragraphe saisi est aussi la sélection). Le bouton de la souris n'ouvre jamais de sélection de
 * texte hors saisie (mousedown sans action par défaut) : le double clic n'est donc pas « avalé »
 * par la sélection d'un mot.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var racine = document.querySelector( '[data-yn-mep]' );
	var equipe = document.getElementById( 'yn-team' );
	if ( ! racine || ! equipe || ! window.fetch || ! document.createElement( 'template' ).content ) {
		return;
	}
	var T = ( window.ynMiseEnPage && window.ynMiseEnPage.textes ) || {};
	var rest = equipe.getAttribute( 'data-yn-rest' ) || '/wp-json/';
	var nonce = equipe.getAttribute( 'data-yn-nonce' ) || '';
	var chapitre = racine.getAttribute( 'data-chapitre' );
	var adresse = rest.replace( /\/?$/, '/' ) + 'yume/v1/chapitres/' + chapitre + '/mise-en-page';
	if ( -1 !== rest.indexOf( '?' ) ) {
		// Permaliens simples : ?rest_route=/
		adresse = rest + 'yume/v1/chapitres/' + chapitre + '/mise-en-page';
	}

	var $ = function ( sel, base ) {
		return ( base || racine ).querySelector( sel );
	};
	var $$ = function ( sel, base ) {
		return Array.prototype.slice.call( ( base || racine ).querySelectorAll( sel ) );
	};
	var liste = $( '[data-yn-mep-unites]' );
	var barre = $( '[data-yn-mep-barre]' );
	var palette = $( '[data-yn-mep-palette]' );
	var annonce = $( '[data-yn-mep-annonce]' );
	var etatTexte = $( '[data-yn-mep-etat]' );
	var compteur = $( '[data-yn-mep-compteur]' );
	var alerte = $( '[data-yn-mep-alerte]' );
	var presenceZone = $( '[data-yn-mep-presence]' );
	var boutonEnregistrer = $( '[data-yn-mep-enregistrer]' );
	var boutonAnnuler = $( '[data-yn-mep-annuler]' );
	var boutonRetablir = $( '[data-yn-mep-retablir]' );
	var menuBouton = $( '[data-yn-mep-menu]' );
	var menu = $( '#yn-mep-actions' );
	var aideBouton = $( '[data-yn-mep-aide]' );
	var aide = $( '#yn-mep-raccourcis' );

	var TIRETS = [ '-', '‐', '‑', '‒', '–', '—', '―', '−', '⁃', '', '', '﹘', '﹣', '－' ];
	var BLANCS = /^[\s  ​-‍⁠﻿]+/;
	var LETTRES = { recit: 'R', dialogue: 'D', pensee: 'P', centre: 'C' };
	var CLASSES = { recit: '', dialogue: 'yn-dialogue yn-dialogue--tiret', pensee: 'yn-thought', centre: 'yn-center has-text-align-center' };

	var etat = {
		unites: [],
		origine: {},
		base: '',
		empreinte: '',
		peut: false,
		selection: [],
		ancre: null,
		courant: null,
		pile: [],
		refaire: [],
		edition: null,
		envoi: false,
		glisse: false,
		cle: 0,
	};

	/* ---------------------------------------------------------------- Textes */

	function texte( cle ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var t = T[ cle ] || cle;
		var n = 0;
		return t.replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			var i = pos ? parseInt( pos, 10 ) - 1 : n++;
			return undefined === args[ i ] ? '' : String( args[ i ] );
		} );
	}

	function pluriel( n, un, plusieurs ) {
		return 1 === n ? texte( un, n ) : texte( plusieurs, n );
	}

	function annoncer( message ) {
		annonce.textContent = '';
		window.setTimeout( function () {
			annonce.textContent = message;
		}, 30 );
	}

	function heure( d ) {
		var m = d.getMinutes();
		return d.getHours() + ' h ' + ( m < 10 ? '0' : '' ) + m;
	}

	/* --------------------------------------------------------- HTML en ligne */

	var PERMIS = { EM: 1, STRONG: 1, U: 1, BR: 1, SUB: 1, SUP: 1, A: 1 };
	var RETIRES = { SCRIPT: 1, STYLE: 1, TEMPLATE: 1, IFRAME: 1, OBJECT: 1, NOSCRIPT: 1, TEXTAREA: 1, SELECT: 1 };

	function echapper( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function echapperAttr( s ) {
		return echapper( s ).replace( /"/g, '&quot;' );
	}

	function fragment( html ) {
		var t = document.createElement( 'template' );
		t.innerHTML = html;
		return t.content;
	}

	function serialiser( parent, permis ) {
		permis = permis || PERMIS;
		var out = '';
		Array.prototype.forEach.call( parent.childNodes, function ( n ) {
			if ( 3 === n.nodeType ) {
				out += echapper( n.nodeValue );
				return;
			}
			if ( 1 !== n.nodeType ) {
				return;
			}
			var nom = n.nodeName;
			if ( 'B' === nom ) {
				nom = 'STRONG';
			} else if ( 'I' === nom ) {
				nom = 'EM';
			}
			if ( RETIRES[ nom ] ) {
				return;
			}
			if ( 'BR' === nom ) {
				out += '<br>';
				return;
			}
			var dedans = serialiser( n, permis );
			if ( ! permis[ nom ] ) {
				out += dedans;
				return;
			}
			if ( 'A' === nom ) {
				var href = n.getAttribute( 'href' ) || '';
				var id = n.getAttribute( 'id' ) || '';
				if ( ! /^#yn-note-\d+$/.test( href ) && ! /^https?:\/\//i.test( href ) ) {
					out += dedans;
					return;
				}
				out += '<a href="' + echapperAttr( href ) + '"' + ( /^yn-ref-\d+$/.test( id ) ? ' id="' + id + '"' : '' ) + '>' + dedans + '</a>';
				return;
			}
			if ( '' === dedans ) {
				return;
			}
			if ( 'SUP' === nom && n.classList.contains( 'yn-note' ) ) {
				out += '<sup class="yn-note">' + dedans + '</sup>';
				return;
			}
			nom = nom.toLowerCase();
			out += '<' + nom + '>' + dedans + '</' + nom + '>';
		} );
		return out;
	}

	/** Mêmes balises que le serveur (Unites::nettoyer()). */
	function nettoyer( html ) {
		// Saut de ligne final (ajouté par le navigateur après Maj+Entrée) retiré, comme au serveur.
		return serialiser( fragment( html ) ).replace( /[ \t\r\n]+/g, ' ' ).trim().replace( /^(<br>)+|(<br>)+$/g, '' ).trim();
	}

	function texteDe( html ) {
		return fragment( html ).textContent || '';
	}

	function vide( html ) {
		return '' === texteDe( html ).replace( /[\s  ]+/g, '' );
	}

	function commenceParTiret( html ) {
		var t = texteDe( html ).replace( BLANCS, '' );
		if ( ! t || -1 === TIRETS.indexOf( t.charAt( 0 ) ) ) {
			return false;
		}
		var reste = t.slice( 1 ).replace( BLANCS, '' );
		return '' !== reste && ! /^[\d\-–—]/.test( reste );
	}

	/** Retire le tiret de tête (première portion de texte), comme Unites::sans_tiret(). */
	function sansTiret( html ) {
		var f = fragment( html );
		var marcheur = document.createTreeWalker( f, NodeFilter.SHOW_TEXT );
		var n = marcheur.nextNode();
		while ( n ) {
			var sans = n.nodeValue.replace( BLANCS, '' );
			if ( '' !== sans ) {
				if ( -1 !== TIRETS.indexOf( sans.charAt( 0 ) ) ) {
					n.nodeValue = sans.slice( 1 ).replace( BLANCS, '' );
				}
				break;
			}
			n = marcheur.nextNode();
		}
		return serialiser( f ).trim();
	}

	/** Contenu d'un paragraphe entièrement en italique (<em>…</em>), ou null. */
	function italiqueEntier( html ) {
		var f = fragment( html.trim() );
		var enfants = Array.prototype.filter.call( f.childNodes, function ( n ) {
			return ! ( 3 === n.nodeType && '' === n.nodeValue.trim() );
		} );
		if ( 1 !== enfants.length || 1 !== enfants[ 0 ].nodeType || -1 === [ 'EM', 'I' ].indexOf( enfants[ 0 ].nodeName ) ) {
			return null;
		}
		return serialiser( enfants[ 0 ] ).trim();
	}

	/* ------------------------------------------------------------- Modèle */

	function nouvelleCle() {
		etat.cle += 1;
		return 'u' + etat.cle;
	}

	function trouver( cle ) {
		for ( var i = 0; i < etat.unites.length; i++ ) {
			if ( etat.unites[ i ].cle === cle ) {
				return etat.unites[ i ];
			}
		}
		return null;
	}

	function rang( cle ) {
		for ( var i = 0; i < etat.unites.length; i++ ) {
			if ( etat.unites[ i ].cle === cle ) {
				return i;
			}
		}
		return -1;
	}

	function selectionnees() {
		return etat.unites.filter( function ( u ) {
			return -1 !== etat.selection.indexOf( u.cle );
		} );
	}

	function modifiee( u ) {
		if ( 'autre' === u.type ) {
			return false; // Intouchable : jamais modifiée ni nouvelle.
		}
		if ( null === u.index || undefined === u.index ) {
			return true;
		}
		var o = etat.origine[ u.index ];
		if ( ! o || o.type !== u.type ) {
			return true;
		}
		if ( 'paragraphe' === u.type ) {
			return o.style !== u.style || o.html !== u.html;
		}
		if ( 'intertitre' === u.type ) {
			return o.niveau !== u.niveau || o.html !== u.html;
		}
		return false;
	}

	function nombreModifies() {
		var vus = {};
		var n = 0;
		etat.unites.forEach( function ( u ) {
			if ( null !== u.index ) {
				vus[ u.index ] = true;
			}
			if ( modifiee( u ) ) {
				n++;
			}
		} );
		Object.keys( etat.origine ).forEach( function ( i ) {
			if ( ! vus[ i ] ) {
				n++;
			}
		} );
		return n;
	}

	function copie() {
		return {
			unites: JSON.parse( JSON.stringify( etat.unites ) ),
			selection: etat.selection.slice(),
			courant: etat.courant,
		};
	}

	function memoriser() {
		etat.pile.push( copie() );
		if ( etat.pile.length > 200 ) {
			etat.pile.shift();
		}
		etat.refaire = [];
	}

	function restaurer( c ) {
		etat.unites = c.unites;
		etat.selection = c.selection;
		etat.courant = c.courant;
		etat.ancre = c.courant;
		rendre();
	}

	function annuler() {
		if ( ! etat.pile.length ) {
			return;
		}
		etat.refaire.push( copie() );
		restaurer( etat.pile.pop() );
		annoncer( texte( 'annule' ) );
	}

	function retablir() {
		if ( ! etat.refaire.length ) {
			return;
		}
		etat.pile.push( copie() );
		restaurer( etat.refaire.pop() );
		annoncer( texte( 'retabli' ) );
	}

	/* -------------------------------------------------------------- Rendu */

	function etiquette( u ) {
		if ( 'paragraphe' === u.type ) {
			return LETTRES[ u.style ] || 'R';
		}
		if ( 'intertitre' === u.type ) {
			return 'T';
		}
		if ( 'separateur' === u.type ) {
			return '—';
		}
		return '•';
	}

	function nomStyle( u ) {
		if ( 'paragraphe' === u.type ) {
			return texte( u.style );
		}
		return texte( { intertitre: 'titre', separateur: 'sep', autre: 'autre' }[ u.type ] );
	}

	function htmlUnite( u, i ) {
		var classes = [ 'yn-mep__unite', 'yn-mep__unite--' + u.type ];
		var choisi = -1 !== etat.selection.indexOf( u.cle );
		if ( choisi ) {
			classes.push( 'is-selected' );
		}
		if ( modifiee( u ) ) {
			classes.push( 'is-modifie' );
		}
		var corps;
		if ( 'paragraphe' === u.type ) {
			corps = '<p' + ( CLASSES[ u.style ] ? ' class="' + CLASSES[ u.style ] + '"' : '' ) + '>'
				+ ( 'dialogue' === u.style ? '<span class="yn-tiret">—</span>' : '' )
				+ '<span class="yn-mep__texte">' + u.html + '</span></p>';
		} else if ( 'intertitre' === u.type ) {
			var h = 'h' + ( u.niveau || 3 );
			corps = '<' + h + ' class="wp-block-heading"><span class="yn-mep__texte">' + u.html + '</span></' + h + '>';
		} else if ( 'separateur' === u.type ) {
			corps = '<hr class="wp-block-separator has-alpha-channel-opacity yn-scene-break">';
		} else {
			corps = '<div class="yn-mep__autre" title="' + echapperAttr( u.apercu || '' ) + '">' + ( u.rendu || '<p class="yn-muted">' + echapper( u.apercu || '' ) + '</p>' ) + '</div>';
		}
		return '<div class="' + classes.join( ' ' ) + '" data-cle="' + u.cle + '" role="option" aria-selected="' + ( choisi ? 'true' : 'false' ) + '" tabindex="-1">'
			+ '<span class="yn-mep__etiquette" aria-hidden="true"><span class="yn-mep__lettre">' + etiquette( u ) + '</span> <span class="yn-mep__num">' + ( i + 1 ) + '</span></span>'
			+ '<span class="yn-visually-hidden">' + echapper( texte( 'unite', nomStyle( u ), i + 1 ) ) + ' : </span>'
			+ corps + '</div>';
	}

	function rendre() {
		liste.innerHTML = etat.unites.map( htmlUnite ).join( '' );
		majSelection();
		majBarre();
	}

	function noeud( cle ) {
		return liste.querySelector( '[data-cle="' + cle + '"]' );
	}

	function majSelection() {
		$$( '.yn-mep__unite', liste ).forEach( function ( el ) {
			var choisi = -1 !== etat.selection.indexOf( el.getAttribute( 'data-cle' ) );
			el.classList.toggle( 'is-selected', choisi );
			el.setAttribute( 'aria-selected', choisi ? 'true' : 'false' );
			el.setAttribute( 'tabindex', '-1' );
		} );
		var courant = etat.courant && noeud( etat.courant ) ? noeud( etat.courant ) : liste.querySelector( '.yn-mep__unite' );
		if ( courant ) {
			courant.setAttribute( 'tabindex', '0' );
		}
		majPalette();
	}

	function majPalette() {
		var choix = selectionnees();
		var paras = choix.filter( function ( u ) {
			return 'paragraphe' === u.type;
		} );
		var titres = choix.filter( function ( u ) {
			return 'intertitre' === u.type;
		} );
		var commun = paras.length && paras.length === choix.length && paras.every( function ( u ) {
			return u.style === paras[ 0 ].style;
		} ) ? paras[ 0 ].style : '';
		$$( '[data-yn-mep-style]', palette ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-yn-mep-style' ) === commun ? 'true' : 'false' );
			b.disabled = ! etat.peut;
		} );
		$$( '[data-yn-mep-op]', palette ).forEach( function ( b ) {
			b.disabled = ! etat.peut;
		} );
		var bt = $( '[data-yn-mep-op="intertitre"]', palette );
		if ( bt ) {
			bt.setAttribute( 'aria-pressed', titres.length && titres.length === choix.length ? 'true' : 'false' );
		}
		var n = choix.length;
		$( '[data-yn-mep-selection]', palette ).textContent = 0 === n ? texte( 'selection0' ) : ( 1 === n ? texte( 'selection1' ) : texte( 'selections', n ) );
	}

	function majBarre() {
		var n = nombreModifies();
		compteur.textContent = 0 === n ? texte( 'aucun' ) : pluriel( n, 'modifie1', 'modifies' );
		compteur.classList.toggle( 'is-modifie', n > 0 );
		boutonEnregistrer.disabled = ! etat.peut || etat.envoi || ( 0 === n && ! etat.edition );
		boutonAnnuler.disabled = ! etat.pile.length;
		boutonRetablir.disabled = ! etat.refaire.length;
	}

	function sale() {
		return !! etat.edition || nombreModifies() > 0;
	}

	/* ---------------------------------------------------------- Sélection */

	function choisir( cles, courant ) {
		etat.selection = cles;
		etat.courant = courant || cles[ cles.length - 1 ] || null;
		majSelection();
	}

	function plage( de, a ) {
		var i = rang( de );
		var j = rang( a );
		if ( -1 === i || -1 === j ) {
			return [ a ];
		}
		var debut = Math.min( i, j );
		var fin = Math.max( i, j );
		return etat.unites.slice( debut, fin + 1 ).map( function ( u ) {
			return u.cle;
		} );
	}

	function focaliser( cle ) {
		var el = noeud( cle );
		if ( el ) {
			el.focus( { preventScroll: true } );
			el.scrollIntoView( { block: 'nearest' } );
		}
	}

	function cliquer( cle, e ) {
		if ( e.shiftKey && etat.ancre ) {
			choisir( plage( etat.ancre, cle ), cle );
		} else if ( e.ctrlKey || e.metaKey ) {
			var sel = etat.selection.slice();
			var i = sel.indexOf( cle );
			if ( -1 === i ) {
				sel.push( cle );
			} else {
				sel.splice( i, 1 );
			}
			etat.ancre = cle;
			choisir( sel, cle );
		} else {
			etat.ancre = cle;
			choisir( [ cle ], cle );
		}
		focaliser( cle );
	}

	function deplacer( pas, etendre ) {
		if ( ! etat.unites.length ) {
			return;
		}
		var i = etat.courant ? rang( etat.courant ) : -1;
		var j = Math.max( 0, Math.min( etat.unites.length - 1, -1 === i ? 0 : i + pas ) );
		var cle = etat.unites[ j ].cle;
		if ( etendre ) {
			if ( ! etat.ancre ) {
				etat.ancre = etat.courant || cle;
			}
			choisir( plage( etat.ancre, cle ), cle );
		} else {
			etat.ancre = cle;
			choisir( [ cle ], cle );
		}
		focaliser( cle );
	}

	function cibles( toutSansSelection ) {
		var choix = selectionnees();
		if ( ! choix.length && toutSansSelection ) {
			return etat.unites.slice();
		}
		return choix;
	}

	/* ------------------------------------------------------------ Actions */

	function appliquerStyle( style ) {
		if ( ! etat.peut ) {
			return;
		}
		var choix = cibles( false ).filter( function ( u ) {
			return 'paragraphe' === u.type || 'intertitre' === u.type;
		} );
		var change = choix.filter( function ( u ) {
			return 'paragraphe' !== u.type || u.style !== style;
		} );
		if ( ! change.length ) {
			annoncer( texte( 'rien' ) );
			return;
		}
		memoriser();
		change.forEach( function ( u ) {
			u.type = 'paragraphe';
			u.style = style;
			delete u.niveau;
			if ( 'dialogue' === style ) {
				u.html = sansTiret( u.html );
			}
		} );
		rendre();
		annoncer( 1 === change.length ? texte( 'passes1', 1, texte( style ) ) : texte( 'passes', change.length, texte( style ) ) );
	}

	function insererSeparateur() {
		if ( ! etat.peut ) {
			return;
		}
		var choix = selectionnees();
		if ( ! choix.length ) {
			annoncer( texte( 'selection0' ) );
			return;
		}
		var i = rang( choix[ 0 ].cle );
		if ( i > 0 && 'separateur' === etat.unites[ i - 1 ].type ) {
			annoncer( texte( 'rien' ) );
			return;
		}
		memoriser();
		etat.unites.splice( i, 0, { cle: nouvelleCle(), index: null, type: 'separateur' } );
		rendre();
		annoncer( texte( 'separateur' ) );
	}

	function basculerIntertitre() {
		if ( ! etat.peut ) {
			return;
		}
		var choix = cibles( false ).filter( function ( u ) {
			return 'paragraphe' === u.type || 'intertitre' === u.type;
		} );
		if ( ! choix.length ) {
			annoncer( texte( 'rien' ) );
			return;
		}
		var versTitre = 'paragraphe' === choix[ 0 ].type;
		memoriser();
		choix.forEach( function ( u ) {
			if ( versTitre ) {
				u.html = 'dialogue' === u.style ? u.html : sansTiret( u.html );
				u.type = 'intertitre';
				u.niveau = u.niveau || 3;
				delete u.style;
			} else {
				u.type = 'paragraphe';
				u.style = 'recit';
				delete u.niveau;
			}
		} );
		rendre();
		annoncer( versTitre ? pluriel( choix.length, 'intertitre1', 'intertitre' ) : pluriel( choix.length, 'paragraphe1', 'paragraphe' ) );
	}

	function action( nom ) {
		if ( ! etat.peut ) {
			return;
		}
		var paras = cibles( 'recit' !== nom ).filter( function ( u ) {
			return 'paragraphe' === u.type;
		} );
		if ( 'recit' === nom ) {
			var aChanger = paras.filter( function ( u ) {
				return 'recit' !== u.style;
			} );
			if ( ! aChanger.length ) {
				annoncer( selectionnees().length ? texte( 'rien' ) : texte( 'selection0' ) );
				return;
			}
			memoriser();
			aChanger.forEach( function ( u ) {
				u.style = 'recit';
			} );
			rendre();
			annoncer( 1 === aChanger.length ? texte( 'passes1', 1, texte( 'recit' ) ) : texte( 'passes', aChanger.length, texte( 'recit' ) ) );
			return;
		}
		if ( 'tirets' === nom ) {
			var tirets = paras.filter( function ( u ) {
				return 'dialogue' !== u.style && commenceParTiret( u.html );
			} );
			if ( ! tirets.length ) {
				annoncer( texte( 'rien' ) );
				return;
			}
			memoriser();
			tirets.forEach( function ( u ) {
				u.style = 'dialogue';
				u.html = sansTiret( u.html );
			} );
			rendre();
			annoncer( 1 === tirets.length ? texte( 'passes1', 1, texte( 'dialogue' ) ) : texte( 'passes', tirets.length, texte( 'dialogue' ) ) );
			return;
		}
		if ( 'italique' === nom ) {
			var italiques = paras.filter( function ( u ) {
				return 'pensee' !== u.style && null !== italiqueEntier( u.html );
			} );
			if ( ! italiques.length ) {
				annoncer( texte( 'rien' ) );
				return;
			}
			memoriser();
			italiques.forEach( function ( u ) {
				// Dans une pensée, l'italique est donné par le style : le texte redevient droit.
				u.html = italiqueEntier( u.html );
				u.style = 'pensee';
			} );
			rendre();
			annoncer( 1 === italiques.length ? texte( 'passes1', 1, texte( 'pensee' ) ) : texte( 'passes', italiques.length, texte( 'pensee' ) ) );
			return;
		}
		if ( 'vides' === nom ) {
			var vides = paras.filter( function ( u ) {
				return vide( u.html );
			} );
			if ( ! vides.length ) {
				annoncer( texte( 'rien' ) );
				return;
			}
			memoriser();
			var retires = vides.map( function ( u ) {
				return u.cle;
			} );
			etat.unites = etat.unites.filter( function ( u ) {
				return -1 === retires.indexOf( u.cle );
			} );
			etat.selection = etat.selection.filter( function ( c ) {
				return -1 === retires.indexOf( c );
			} );
			if ( -1 !== retires.indexOf( etat.courant ) ) {
				etat.courant = etat.selection[ 0 ] || null;
			}
			rendre();
			annoncer( pluriel( retires.length, 'supprimes1', 'supprimes' ) );
		}
	}

	/* ------------------------------------------------------ Texte en place */

	function caretAuPoint( x, y ) {
		if ( document.caretRangeFromPoint ) {
			return document.caretRangeFromPoint( x, y );
		}
		if ( document.caretPositionFromPoint ) {
			var p = document.caretPositionFromPoint( x, y );
			if ( p ) {
				var r = document.createRange();
				r.setStart( p.offsetNode, p.offset );
				r.collapse( true );
				return r;
			}
		}
		return null;
	}

	/** Place le curseur après « offset » caractères du texte de el (fin si trop loin). */
	function placerCaret( el, offset ) {
		var r = document.createRange();
		var marcheur = document.createTreeWalker( el, NodeFilter.SHOW_TEXT );
		var n = marcheur.nextNode();
		var reste = offset;
		var place = false;
		while ( n ) {
			if ( reste <= n.nodeValue.length ) {
				r.setStart( n, reste );
				place = true;
				break;
			}
			reste -= n.nodeValue.length;
			n = marcheur.nextNode();
		}
		if ( ! place ) {
			r.selectNodeContents( el );
			r.collapse( offset <= 0 );
		} else {
			r.collapse( true );
		}
		var s = window.getSelection();
		s.removeAllRanges();
		s.addRange( r );
	}

	function editer( cle, position ) {
		var u = trouver( cle );
		if ( ! etat.peut || ! u || ( 'paragraphe' !== u.type && 'intertitre' !== u.type ) ) {
			if ( u && 'autre' === u.type ) {
				annoncer( texte( 'intouchable' ) );
			}
			return;
		}
		var el = noeud( cle ) && noeud( cle ).querySelector( '.yn-mep__texte' );
		if ( ! el ) {
			return;
		}
		etat.edition = { cle: cle, avant: el.innerHTML, el: el };
		noeud( cle ).classList.add( 'is-edition' );
		el.setAttribute( 'contenteditable', 'true' );
		el.setAttribute( 'role', 'textbox' );
		el.setAttribute( 'aria-multiline', 'false' );
		el.setAttribute( 'aria-label', texte( 'saisie', rang( cle ) + 1 ) );
		el.focus();
		if ( position && position.range && el.contains( position.range.startContainer ) ) {
			var s = window.getSelection();
			s.removeAllRanges();
			s.addRange( position.range );
		} else if ( position && 'number' === typeof position.offset ) {
			placerCaret( el, position.offset );
		} else {
			placerCaret( el, Infinity );
		}
		majBarre();
		annoncer( texte( 'saisie', rang( cle ) + 1 ) );
	}

	function nettoyerSaisie( html, u ) {
		var propre = nettoyer( html );
		return 'paragraphe' === u.type && 'dialogue' === u.style ? sansTiret( propre ) : propre;
	}

	function finEdition( valider ) {
		var ed = etat.edition;
		if ( ! ed ) {
			return;
		}
		etat.edition = null;
		var apres = ed.el.innerHTML;
		var u = trouver( ed.cle );
		if ( valider && u && apres !== ed.avant ) {
			memoriser();
			u.html = nettoyerSaisie( apres, u );
			annoncer( texte( 'saisie_ok' ) );
		} else if ( ! valider ) {
			annoncer( texte( 'saisie_annul' ) );
		}
		var retire = false;
		if ( u && ( 'paragraphe' === u.type || 'intertitre' === u.type ) && vide( u.html ) ) {
			// Règle simple : un paragraphe vide qui perd le focus disparaît (Annuler le rend).
			var i = rang( u.cle );
			etat.unites.splice( i, 1 );
			var voisin = etat.unites[ Math.max( 0, i - 1 ) ] || null;
			etat.selection = voisin ? [ voisin.cle ] : [];
			etat.courant = voisin ? voisin.cle : null;
			etat.ancre = etat.courant;
			annoncer( texte( 'vide_retire' ) );
			retire = true;
		}
		rendre();
		return retire ? etat.courant : ed.cle;
	}

	/** Nouvelle unité « paragraphe » après le rang i, en saisie (style : celui de la référence, Récit sinon). */
	function nouveauParagraphe( i, reference, html ) {
		var nouvelle = {
			cle: nouvelleCle(),
			index: null,
			type: 'paragraphe',
			style: reference && 'paragraphe' === reference.type ? reference.style : 'recit',
			html: html || '',
		};
		if ( 'dialogue' === nouvelle.style ) {
			nouvelle.html = sansTiret( nouvelle.html );
		}
		etat.unites.splice( i + 1, 0, nouvelle );
		etat.selection = [ nouvelle.cle ];
		etat.courant = nouvelle.cle;
		etat.ancre = nouvelle.cle;
		return nouvelle;
	}

	function ajouterApres() {
		if ( ! etat.peut ) {
			return;
		}
		var choix = selectionnees();
		var ref = choix.length ? choix[ choix.length - 1 ] : etat.unites[ etat.unites.length - 1 ];
		var i = ref ? rang( ref.cle ) : -1;
		memoriser();
		var nouvelle = nouveauParagraphe( i, ref, '' );
		rendre();
		annoncer( texte( 'ajoute' ) );
		editer( nouvelle.cle, { offset: 0 } );
	}

	function supprimerSelection() {
		if ( ! etat.peut ) {
			return false;
		}
		var choix = selectionnees().filter( function ( u ) {
			return 'autre' !== u.type;
		} );
		if ( ! choix.length ) {
			if ( selectionnees().length ) {
				annoncer( texte( 'intouchable' ) );
			}
			return false;
		}
		memoriser();
		var cles = choix.map( function ( u ) {
			return u.cle;
		} );
		var premier = rang( cles[ 0 ] );
		etat.unites = etat.unites.filter( function ( u ) {
			return -1 === cles.indexOf( u.cle );
		} );
		var voisin = etat.unites[ Math.min( premier, etat.unites.length - 1 ) ] || null;
		etat.selection = voisin ? [ voisin.cle ] : [];
		etat.courant = voisin ? voisin.cle : null;
		etat.ancre = etat.courant;
		rendre();
		if ( voisin ) {
			focaliser( voisin.cle );
		}
		annoncer( pluriel( cles.length, 'supprime1', 'supprimes_sel' ) );
		return true;
	}

	/** Lignes d'un collage : texte brut, ou HTML réduit à l'italique et au gras ; une ligne = un paragraphe. */
	function lignesCollees( donnees ) {
		var html = donnees.getData( 'text/html' ) || '';
		var morceaux;
		if ( html ) {
			html = html.replace( /<\/(p|div|li|h[1-6]|tr|blockquote)\s*>|<br\s*\/?>/gi, '\u2029' );
			var f = fragment( html );
			// Texte brut de chaque nœud : les retours à la ligne du HTML ne comptent pas.
			morceaux = serialiser( f, { EM: 1, STRONG: 1 } ).replace( /[ \t\r\n]+/g, ' ' ).split( '\u2029' );
		} else {
			morceaux = ( donnees.getData( 'text/plain' ) || '' ).split( /\r?\n/ ).map( echapper );
		}
		return morceaux.map( function ( m ) {
			return m.replace( /^ +| +$/g, '' );
		} ).filter( function ( m ) {
			return ! vide( m );
		} );
	}

	function coller( donnees ) {
		var ed = etat.edition;
		var s = window.getSelection();
		var lignes = lignesCollees( donnees );
		if ( ! ed || ! s.rangeCount || ! lignes.length ) {
			return;
		}
		var r = s.getRangeAt( 0 );
		r.deleteContents();
		var fin = document.createRange();
		fin.setStart( r.startContainer, r.startOffset );
		fin.setEnd( ed.el, ed.el.childNodes.length );
		var boite = document.createElement( 'div' );
		boite.appendChild( fin.extractContents() );
		var reste = boite.innerHTML;
		var u = trouver( ed.cle );
		etat.edition = null;
		memoriser();
		var debut = ed.el.innerHTML;
		var cible = u;
		var offset;
		if ( 1 === lignes.length ) {
			u.html = nettoyerSaisie( debut + lignes[ 0 ] + reste, u );
			offset = longueurTexte( debut + lignes[ 0 ] );
		} else {
			u.html = nettoyerSaisie( debut + lignes[ 0 ], u );
			var i = rang( u.cle );
			for ( var k = 1; k < lignes.length; k++ ) {
				var derniere = k === lignes.length - 1;
				cible = nouveauParagraphe( i + k - 1, u, nettoyer( lignes[ k ] + ( derniere ? reste : '' ) ) );
			}
			offset = longueurTexte( lignes[ lignes.length - 1 ] );
		}
		etat.selection = [ cible.cle ];
		etat.courant = cible.cle;
		etat.ancre = cible.cle;
		rendre();
		if ( lignes.length > 1 ) {
			annoncer( texte( 'colles', lignes.length ) );
		}
		editer( cible.cle, { offset: offset } );
	}

	function longueurTexte( html ) {
		return texteDe( html ).length;
	}

	function scinder() {
		var ed = etat.edition;
		var s = window.getSelection();
		if ( ! ed || ! s.rangeCount ) {
			return;
		}
		var r = s.getRangeAt( 0 );
		if ( ! r.collapsed ) {
			r.deleteContents();
		}
		var fin = document.createRange();
		fin.setStart( r.startContainer, r.startOffset );
		fin.setEnd( ed.el, ed.el.childNodes.length );
		var boite = document.createElement( 'div' );
		boite.appendChild( fin.extractContents() );
		var u = trouver( ed.cle );
		etat.edition = null;
		// Instantané d'avant la saisie : un seul « Annuler » défait saisie et coupure.
		memoriser();
		u.html = nettoyerSaisie( ed.el.innerHTML, u );
		var suite = nettoyer( boite.innerHTML );
		// Entrée en fin de paragraphe : nouveau paragraphe vide du même style, prêt à taper.
		var nouvelle = nouveauParagraphe( rang( u.cle ), u, suite );
		rendre();
		annoncer( vide( suite ) ? texte( 'ajoute' ) : texte( 'scinde' ) );
		editer( nouvelle.cle, { offset: 0 } );
	}

	function auDebut( el ) {
		var s = window.getSelection();
		if ( ! s.rangeCount || ! s.isCollapsed ) {
			return false;
		}
		var r = s.getRangeAt( 0 );
		var avant = document.createRange();
		avant.setStart( el, 0 );
		avant.setEnd( r.startContainer, r.startOffset );
		var boite = document.createElement( 'div' );
		boite.appendChild( avant.cloneContents() );
		return '' === avant.toString() && ! boite.querySelector( 'br' );
	}

	function fusionner() {
		var ed = etat.edition;
		var i = ed ? rang( ed.cle ) : -1;
		var prec = i > 0 ? etat.unites[ i - 1 ] : null;
		if ( ! prec || ( 'paragraphe' !== prec.type && 'intertitre' !== prec.type ) ) {
			return false;
		}
		etat.edition = null;
		memoriser();
		var jonction = longueurTexte( prec.html );
		var suite = nettoyer( ed.el.innerHTML );
		var espace = /\s$/.test( texteDe( prec.html ) ) || /^\s/.test( texteDe( suite ) ) || '' === texteDe( prec.html ) ? '' : ' ';
		prec.html = nettoyer( prec.html + espace + suite );
		etat.unites.splice( i, 1 );
		etat.selection = [ prec.cle ];
		etat.courant = prec.cle;
		etat.ancre = prec.cle;
		rendre();
		annoncer( texte( 'fusionne' ) );
		editer( prec.cle, { offset: jonction + espace.length } );
		return true;
	}

	/* -------------------------------------------------------- Serveur */

	function requete( methode, url, corps ) {
		return window.fetch( url, {
			method: methode,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce, Accept: 'application/json' },
			body: corps ? JSON.stringify( corps ) : undefined,
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( d ) {
				return { ok: r.ok, statut: r.status, donnees: d };
			} );
		} );
	}

	function montrerPresence( p ) {
		if ( p && p.message ) {
			presenceZone.textContent = p.message;
			presenceZone.hidden = false;
		} else {
			presenceZone.hidden = true;
			presenceZone.textContent = '';
		}
	}

	function montrerAlerte( message, recharger ) {
		alerte.textContent = '';
		if ( ! message ) {
			alerte.hidden = true;
			return;
		}
		var p = document.createElement( 'p' );
		p.textContent = message;
		alerte.appendChild( p );
		if ( recharger ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'yn-btn yn-btn--sm';
			b.textContent = texte( 'recharger' );
			b.addEventListener( 'click', function () {
				montrerAlerte( '' );
				charger();
			} );
			alerte.appendChild( b );
		}
		alerte.hidden = false;
	}

	function initialiser( d, garderSelection ) {
		var ancienneSelection = garderSelection ? etat.selection.map( rang ) : [];
		etat.base = d.chapitre.modifie;
		etat.empreinte = d.chapitre.empreinte;
		etat.peut = !! d.peut_enregistrer;
		etat.origine = {};
		etat.unites = ( d.unites || [] ).map( function ( u ) {
			var c = JSON.parse( JSON.stringify( u ) );
			c.cle = nouvelleCle();
			if ( 'autre' !== u.type ) {
				etat.origine[ u.index ] = { type: u.type, style: u.style, html: u.html, niveau: u.niveau };
			}
			return c;
		} );
		etat.pile = [];
		etat.refaire = [];
		etat.edition = null;
		etat.selection = ancienneSelection.filter( function ( i ) {
			return i >= 0 && i < etat.unites.length;
		} ).map( function ( i ) {
			return etat.unites[ i ].cle;
		} );
		etat.courant = etat.selection[ etat.selection.length - 1 ] || null;
		etat.ancre = etat.courant;
		var revisions = $( '[data-yn-mep-revisions]', document );
		if ( revisions && d.liens && d.liens.revisions ) {
			revisions.href = d.liens.revisions;
		}
		rendre();
		if ( ! etat.peut ) {
			etatTexte.textContent = texte( 'lecture' );
		}
	}

	function charger() {
		etatTexte.textContent = texte( 'chargement' );
		return requete( 'GET', adresse ).then( function ( res ) {
			if ( ! res.ok || ! res.donnees.chapitre ) {
				etatTexte.textContent = '';
				montrerAlerte( ( res.donnees && res.donnees.message ) || texte( 'erreur' ) );
				return;
			}
			initialiser( res.donnees, false );
			montrerPresence( res.donnees.presence );
			etatTexte.textContent = etat.peut ? '' : texte( 'lecture' );
			barre.hidden = false;
			palette.hidden = false;
		} ).catch( function () {
			etatTexte.textContent = '';
			montrerAlerte( texte( 'reseau' ) );
		} );
	}

	function enregistrer() {
		if ( etat.edition ) {
			finEdition( true );
		}
		if ( ! etat.peut || etat.envoi || 0 === nombreModifies() ) {
			return;
		}
		etat.envoi = true;
		majBarre();
		etatTexte.textContent = texte( 'envoi' );
		var corps = {
			base: etat.base,
			empreinte: etat.empreinte,
			unites: etat.unites.map( function ( u ) {
				var e = { index: null === u.index || undefined === u.index ? null : u.index, type: u.type };
				if ( 'paragraphe' === u.type ) {
					e.style = u.style;
					e.html = u.html;
				} else if ( 'intertitre' === u.type ) {
					e.niveau = u.niveau || 3;
					e.html = u.html;
				}
				return e;
			} ),
		};
		requete( 'PUT', adresse, corps ).then( function ( res ) {
			etat.envoi = false;
			if ( res.ok && res.donnees.chapitre ) {
				initialiser( res.donnees, true );
				montrerAlerte( '' );
				etatTexte.textContent = texte( 'enregistre', heure( new Date() ) );
				annoncer( res.donnees.message || etatTexte.textContent );
				return;
			}
			etatTexte.textContent = '';
			var d = res.donnees || {};
			if ( 409 === res.statut && 'yume_mise_en_page_conflit' === d.code ) {
				montrerAlerte( d.message, true );
			} else if ( 403 === res.statut && 'rest_cookie_invalid_nonce' === d.code ) {
				montrerAlerte( texte( 'session' ) );
			} else {
				montrerAlerte( texte( 'echec', d.message || res.statut ) );
			}
			majBarre();
		} ).catch( function () {
			etat.envoi = false;
			etatTexte.textContent = '';
			montrerAlerte( texte( 'reseau' ) );
			majBarre();
		} );
	}

	function signalerPresence() {
		requete( 'POST', adresse + '/presence' ).then( function ( res ) {
			if ( res.ok ) {
				montrerPresence( res.donnees.presence );
			}
		} ).catch( function () {} );
	}

	/* ----------------------------------------------------------- Événements */

	function uniteDe( cible ) {
		var el = cible && cible.closest ? cible.closest( '.yn-mep__unite' ) : null;
		return el && liste.contains( el ) ? el : null;
	}

	function dansSaisie( cible ) {
		return !! ( etat.edition && cible && etat.edition.el.contains( cible ) );
	}

	liste.addEventListener( 'mousedown', function ( e ) {
		if ( 0 !== e.button || dansSaisie( e.target ) ) {
			return;
		}
		var el = uniteDe( e.target );
		if ( etat.edition ) {
			finEdition( true );
			el = el && noeud( el.getAttribute( 'data-cle' ) ) ? noeud( el.getAttribute( 'data-cle' ) ) : uniteDe( document.elementFromPoint( e.clientX, e.clientY ) );
		}
		if ( ! el ) {
			return;
		}
		e.preventDefault();
		cliquer( el.getAttribute( 'data-cle' ), e );
		etat.glisse = ! ( e.shiftKey || e.ctrlKey || e.metaKey );
	} );

	liste.addEventListener( 'mouseover', function ( e ) {
		if ( ! etat.glisse || ! ( e.buttons & 1 ) || etat.edition ) {
			return;
		}
		var el = uniteDe( e.target );
		if ( el && etat.ancre ) {
			var cle = el.getAttribute( 'data-cle' );
			choisir( plage( etat.ancre, cle ), cle );
		}
	} );

	document.addEventListener( 'mouseup', function () {
		etat.glisse = false;
	} );

	liste.addEventListener( 'click', function ( e ) {
		// Liens des notes et des images : pas de navigation dans la vue.
		if ( ! dansSaisie( e.target ) && e.target.closest && e.target.closest( 'a' ) ) {
			e.preventDefault();
		}
	} );

	liste.addEventListener( 'dblclick', function ( e ) {
		if ( dansSaisie( e.target ) ) {
			return;
		}
		var el = uniteDe( e.target );
		if ( ! el ) {
			return;
		}
		e.preventDefault();
		var cle = el.getAttribute( 'data-cle' );
		etat.selection = [ cle ];
		etat.courant = cle;
		etat.ancre = cle;
		majSelection();
		editer( cle, { range: caretAuPoint( e.clientX, e.clientY ) } );
	} );

	liste.addEventListener( 'focusout', function ( e ) {
		if ( etat.edition && e.target === etat.edition.el && ! ( e.relatedTarget && etat.edition.el.contains( e.relatedTarget ) ) ) {
			var cle = finEdition( true );
			if ( cle && ( ! e.relatedTarget || liste.contains( e.relatedTarget ) ) ) {
				focaliser( cle );
			}
		}
	} );

	liste.addEventListener( 'paste', function ( e ) {
		if ( ! dansSaisie( e.target ) ) {
			return;
		}
		e.preventDefault();
		coller( e.clipboardData || window.clipboardData );
	} );

	liste.addEventListener( 'drop', function ( e ) {
		if ( dansSaisie( e.target ) ) {
			e.preventDefault();
		}
	} );

	function clavierSaisie( e ) {
		var cmd = e.ctrlKey || e.metaKey;
		if ( 'Escape' === e.key ) {
			e.preventDefault();
			focaliser( finEdition( false ) );
		} else if ( 'Enter' === e.key && e.shiftKey ) {
			e.preventDefault();
			document.execCommand( 'insertLineBreak' );
		} else if ( 'Enter' === e.key ) {
			e.preventDefault();
			scinder();
		} else if ( 'Backspace' === e.key && ! cmd && auDebut( etat.edition.el ) ) {
			if ( fusionner() ) {
				e.preventDefault();
			}
		} else if ( cmd && ! e.altKey && ( 'i' === e.key.toLowerCase() || 'b' === e.key.toLowerCase() || 'u' === e.key.toLowerCase() ) ) {
			e.preventDefault();
			document.execCommand( { i: 'italic', b: 'bold', u: 'underline' }[ e.key.toLowerCase() ], false, null );
		} else if ( cmd && 's' === e.key.toLowerCase() ) {
			e.preventDefault();
			enregistrer();
		}
	}

	function champSaisie( cible ) {
		return cible && ( /^(INPUT|TEXTAREA|SELECT)$/.test( cible.nodeName ) || cible.isContentEditable );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( etat.edition ) {
			if ( dansSaisie( e.target ) || e.target === etat.edition.el ) {
				clavierSaisie( e );
			}
			return;
		}
		var cmd = e.ctrlKey || e.metaKey;
		var touche = e.key || '';
		if ( cmd && ! e.altKey && 's' === touche.toLowerCase() ) {
			e.preventDefault();
			enregistrer();
			return;
		}
		if ( champSaisie( e.target ) || barre.hidden ) {
			return;
		}
		if ( cmd && ! e.altKey && ( 'z' === touche.toLowerCase() || 'y' === touche.toLowerCase() ) ) {
			e.preventDefault();
			if ( 'y' === touche.toLowerCase() || e.shiftKey ) {
				retablir();
			} else {
				annuler();
			}
			return;
		}
		if ( 'Escape' === touche ) {
			if ( ! menu.hidden ) {
				fermerMenu( true );
				return;
			}
			if ( ! aide.hidden ) {
				basculerAide( false );
				return;
			}
			if ( etat.selection.length ) {
				choisir( [], etat.courant );
				annoncer( texte( 'selection0' ) );
			}
			return;
		}
		if ( cmd || e.altKey ) {
			return;
		}
		var dansListe = liste.contains( e.target );
		var dansVue = racine.contains( e.target ) || e.target === document.body;
		if ( ! dansVue ) {
			return;
		}
		if ( 'ArrowDown' === touche || 'ArrowUp' === touche ) {
			if ( dansListe || etat.selection.length ) {
				e.preventDefault();
				deplacer( 'ArrowDown' === touche ? 1 : -1, e.shiftKey );
			}
			return;
		}
		if ( 'Enter' === touche && dansListe && etat.courant ) {
			e.preventDefault();
			editer( etat.courant, null );
			return;
		}
		if ( ( 'Delete' === touche || 'Backspace' === touche ) && dansListe ) {
			if ( supprimerSelection() ) {
				e.preventDefault();
			}
			return;
		}
		if ( '?' === touche ) {
			e.preventDefault();
			basculerAide( aide.hidden );
			return;
		}
		if ( 'BUTTON' === e.target.nodeName && ( ' ' === touche || 'Enter' === touche ) ) {
			return;
		}
		var lettre = touche.toLowerCase();
		var styles = { r: 'recit', d: 'dialogue', p: 'pensee', c: 'centre' };
		if ( styles[ lettre ] ) {
			e.preventDefault();
			appliquerStyle( styles[ lettre ] );
		} else if ( 's' === lettre ) {
			e.preventDefault();
			insererSeparateur();
		} else if ( 't' === lettre ) {
			e.preventDefault();
			basculerIntertitre();
		} else if ( 'n' === lettre ) {
			e.preventDefault();
			ajouterApres();
		}
	} );

	palette.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( 'button' );
		if ( ! b ) {
			return;
		}
		if ( b.hasAttribute( 'data-yn-mep-style' ) ) {
			appliquerStyle( b.getAttribute( 'data-yn-mep-style' ) );
		} else if ( 'separateur' === b.getAttribute( 'data-yn-mep-op' ) ) {
			insererSeparateur();
		} else if ( 'intertitre' === b.getAttribute( 'data-yn-mep-op' ) ) {
			basculerIntertitre();
		} else if ( 'ajouter' === b.getAttribute( 'data-yn-mep-op' ) ) {
			ajouterApres();
		} else if ( 'supprimer' === b.getAttribute( 'data-yn-mep-op' ) ) {
			supprimerSelection();
		}
	} );
	// Les boutons de la palette ne volent pas le focus à la sélection.
	palette.addEventListener( 'mousedown', function ( e ) {
		if ( e.target.closest( 'button' ) && ! etat.edition ) {
			e.preventDefault();
		}
	} );

	function fermerMenu( rendreFocus ) {
		menu.hidden = true;
		menuBouton.setAttribute( 'aria-expanded', 'false' );
		if ( rendreFocus ) {
			menuBouton.focus();
		}
	}

	menuBouton.addEventListener( 'click', function () {
		var ouvrir = menu.hidden;
		menu.hidden = ! ouvrir;
		menuBouton.setAttribute( 'aria-expanded', ouvrir ? 'true' : 'false' );
		if ( ouvrir ) {
			var premier = menu.querySelector( 'button' );
			if ( premier ) {
				premier.focus();
			}
		}
	} );
	menu.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '[data-yn-mep-action]' );
		if ( b ) {
			fermerMenu( true );
			action( b.getAttribute( 'data-yn-mep-action' ) );
		}
	} );
	menu.addEventListener( 'keydown', function ( e ) {
		var boutons = $$( 'button', menu );
		var i = boutons.indexOf( document.activeElement );
		if ( 'ArrowDown' === e.key || 'ArrowUp' === e.key ) {
			e.preventDefault();
			e.stopPropagation();
			boutons[ ( i + ( 'ArrowDown' === e.key ? 1 : boutons.length - 1 ) ) % boutons.length ].focus();
		}
	} );
	document.addEventListener( 'click', function ( e ) {
		if ( ! menu.hidden && ! menu.contains( e.target ) && ! menuBouton.contains( e.target ) ) {
			fermerMenu( false );
		}
	} );

	function basculerAide( ouvrir ) {
		aide.hidden = ! ouvrir;
		aideBouton.setAttribute( 'aria-expanded', ouvrir ? 'true' : 'false' );
	}
	aideBouton.addEventListener( 'click', function () {
		basculerAide( aide.hidden );
	} );

	boutonEnregistrer.addEventListener( 'click', enregistrer );
	boutonAnnuler.addEventListener( 'click', annuler );
	boutonRetablir.addEventListener( 'click', retablir );

	$$( '[data-yn-mep-largeur]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var mobile = 'mobile' === b.getAttribute( 'data-yn-mep-largeur' );
			racine.classList.toggle( 'yn-mep--mobile', mobile );
			$$( '[data-yn-mep-largeur]' ).forEach( function ( x ) {
				x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' );
			} );
		} );
	} );

	// Thème d'aperçu : attribut du document seulement, jamais enregistré (localStorage
	// « yn.theme » du membre inchangé).
	var themeActuel = document.documentElement.getAttribute( 'data-yn-theme' ) || 'nuit';
	$$( '[data-yn-mep-theme]' ).forEach( function ( b ) {
		b.setAttribute( 'aria-pressed', b.getAttribute( 'data-yn-mep-theme' ) === themeActuel ? 'true' : 'false' );
		b.addEventListener( 'click', function () {
			document.documentElement.setAttribute( 'data-yn-theme', b.getAttribute( 'data-yn-mep-theme' ) );
			$$( '[data-yn-mep-theme]' ).forEach( function ( x ) {
				x.setAttribute( 'aria-pressed', x === b ? 'true' : 'false' );
			} );
		} );
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( sale() ) {
			e.preventDefault();
			e.returnValue = texte( 'quitter' );
			return e.returnValue;
		}
		return undefined;
	} );

	/* --------------------------------------------------------- Démarrage */

	var sansJs = $( '[data-yn-mep-sansjs]' );
	if ( sansJs ) {
		sansJs.hidden = true;
	}
	racine.classList.add( 'is-actif' );
	liste.setAttribute( 'role', 'listbox' );
	liste.setAttribute( 'aria-multiselectable', 'true' );
	charger();
	window.setInterval( signalerPresence, 120000 );
}() );
