/**
 * Lecteur des chapitres MangaDex (module mangadex, contrat §8 bis et §14).
 *
 * - Modes : défilement (par défaut, toutes les pages à la suite) ou page par page.
 * - Page par page : à l'ouverture en haut du chapitre, la page est alignée sous la barre de
 *   lecture. Clic sur la moitié droite de la page ou → / Page suivante : page
 *   suivante ; moitié gauche ou ← / Page précédente : page précédente (même sens quel que soit
 *   le sens de lecture, choix de l'équipe). Au bout du chapitre, les flèches laissent la
 *   barre de lecture passer au chapitre voisin. Les deux pages suivantes sont préchargées.
 * - Qualité : « Économie de données » (src) ou « Originale » (data-yn-originale).
 * - Choix gardés sur l'appareil : localStorage yn.manga = { mode, qualite }.
 * - Page qui ne se charge pas : « Page N indisponible » et lien vers MangaDex.
 * - Coordination avec la barre de lecture (yume/reader-tools) : attribut data-yn-courante
 *   (index de la page affichée en mode page), événement document « yn:manga-page » à chaque
 *   page tournée, et « yn:manga-aller » (detail.index) reçu pour une reprise de lecture.
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	const racine = document.querySelector( '.yn-manga[data-yn-manga]' );
	if ( ! racine ) {
		return;
	}
	const CLE = 'yn.manga';
	const MODES = [ 'defilement', 'page' ];
	const QUALITES = [ 'data-saver', 'data' ];
	const PRECHARGEES = 2;

	const pages = Array.prototype.slice.call( racine.querySelectorAll( '.yn-manga__page' ) );
	const zone = racine.querySelector( '.yn-manga__pages' );
	const outils = racine.querySelector( '.yn-manga__outils' );
	const navigation = racine.querySelector( '.yn-manga__navigation' );
	const compteur = racine.querySelector( '.yn-manga__compteur' );
	const boutonPrecedente = racine.querySelector( '[data-yn-manga-aller="precedente"]' );
	const boutonSuivante = racine.querySelector( '[data-yn-manga-aller="suivante"]' );
	const lienMangadex = racine.querySelector( '[data-yn-manga-mangadex]' );
	const total = pages.length;
	if ( ! total || ! zone ) {
		return;
	}

	function lire() {
		try {
			const valeur = JSON.parse( window.localStorage.getItem( CLE ) || 'null' );
			return valeur && typeof valeur === 'object' ? valeur : {};
		} catch ( e ) {
			return {};
		}
	}

	function ecrire() {
		try {
			window.localStorage.setItem( CLE, JSON.stringify( { mode: etat.mode, qualite: etat.qualite } ) );
		} catch ( e ) {
			// Stockage indisponible (navigation privée) : le choix vaut pour cette page.
		}
	}

	const memo = lire();
	const etat = {
		mode: MODES.indexOf( memo.mode ) >= 0 ? memo.mode : 'defilement',
		qualite: QUALITES.indexOf( memo.qualite ) >= 0 ? memo.qualite : 'data-saver',
		courante: 0,
	};

	/* ------------------------------------------------------------------ */
	/* Images                                                              */
	/* ------------------------------------------------------------------ */

	function image( index ) {
		const page = pages[ index ];
		return page ? page.querySelector( 'img' ) : null;
	}

	function adresse( img ) {
		if ( ! img.hasAttribute( 'data-yn-economie' ) ) {
			img.setAttribute( 'data-yn-economie', img.getAttribute( 'src' ) || '' );
		}
		return etat.qualite === 'data' ? img.getAttribute( 'data-yn-originale' ) : img.getAttribute( 'data-yn-economie' );
	}

	function appliquerQualite() {
		pages.forEach( function ( page ) {
			const img = page.querySelector( 'img' );
			if ( ! img ) {
				return;
			}
			const voulue = adresse( img );
			if ( voulue && img.getAttribute( 'src' ) !== voulue ) {
				img.classList.remove( 'is-chargee' );
				img.setAttribute( 'src', voulue );
			}
		} );
	}

	function surChargement( img ) {
		img.classList.add( 'is-chargee' );
		if ( img.naturalWidth && img.naturalHeight > img.naturalWidth * 2.2 ) {
			img.classList.add( 'is-longue' );
		}
	}

	function surErreur( img ) {
		const page = img.closest( '.yn-manga__page' );
		if ( ! page || page.querySelector( '.yn-manga__erreur' ) ) {
			return;
		}
		const numero = pages.indexOf( page ) + 1;
		const message = document.createElement( 'p' );
		message.className = 'yn-manga__erreur';
		message.setAttribute( 'role', 'status' );
		message.appendChild( document.createTextNode( 'Page ' + numero + ' indisponible. ' ) );
		if ( lienMangadex ) {
			const lien = document.createElement( 'a' );
			lien.href = lienMangadex.href;
			lien.rel = 'noopener';
			lien.textContent = 'Lire sur MangaDex';
			message.appendChild( lien );
		}
		img.hidden = true;
		page.appendChild( message );
	}

	pages.forEach( function ( page ) {
		const img = page.querySelector( 'img' );
		if ( ! img ) {
			return;
		}
		img.addEventListener( 'load', function () {
			const message = page.querySelector( '.yn-manga__erreur' );
			if ( message ) {
				message.remove();
			}
			img.hidden = false;
			surChargement( img );
		} );
		img.addEventListener( 'error', function () {
			surErreur( img );
		} );
		if ( img.complete ) {
			if ( img.naturalWidth ) {
				surChargement( img );
			} else if ( img.getAttribute( 'src' ) ) {
				surErreur( img );
			}
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Page par page                                                       */
	/* ------------------------------------------------------------------ */

	function hauteurBarre() {
		const barre = document.querySelector( '.yn-reader-tools__collant' );
		return barre ? barre.offsetHeight + ( parseFloat( window.getComputedStyle( barre ).top ) || 0 ) : 0;
	}

	function prevenirLecteur() {
		document.dispatchEvent( new CustomEvent( 'yn:manga-page', { detail: { index: etat.courante, total: total } } ) );
	}

	function lienChapitreSuivant() {
		const lien = document.querySelector( '.yn-chapter-nav a[rel~="next"][href]' );
		return lien ? lien.href : '';
	}

	function afficherPage( index, defiler ) {
		etat.courante = Math.max( 0, Math.min( total - 1, index ) );
		racine.setAttribute( 'data-yn-courante', String( etat.courante ) );
		pages.forEach( function ( page, i ) {
			page.classList.toggle( 'is-courante', i === etat.courante );
		} );
		for ( let i = etat.courante; i <= Math.min( total - 1, etat.courante + PRECHARGEES ); i++ ) {
			const img = image( i );
			if ( img && img.getAttribute( 'loading' ) === 'lazy' ) {
				img.setAttribute( 'loading', 'eager' );
			}
		}
		if ( compteur ) {
			compteur.textContent = 'Page ' + ( etat.courante + 1 ) + ' sur ' + total;
		}
		if ( boutonPrecedente ) {
			boutonPrecedente.disabled = etat.courante === 0;
		}
		if ( boutonSuivante ) {
			const fin = etat.courante === total - 1;
			const suivant = lienChapitreSuivant();
			boutonSuivante.textContent = fin ? ( suivant ? 'Chapitre suivant →' : 'Fin du chapitre' ) : 'Page suivante →';
			boutonSuivante.disabled = fin && ! suivant;
		}
		// Page alignée sous la barre de lecture : elle tient entière à l'écran (manga.css).
		if ( defiler ) {
			const haut = zone.getBoundingClientRect().top;
			if ( Math.abs( haut - hauteurBarre() - 8 ) > 4 ) {
				window.scrollTo( { top: Math.max( 0, haut + window.pageYOffset - hauteurBarre() - 8 ) } );
			}
		}
		prevenirLecteur();
	}

	/**
	 * Tourne la page ; renvoie faux au bout du chapitre (rien n'est fait).
	 *
	 * @param {number} pas +1 ou -1.
	 */
	function tourner( pas ) {
		const cible = etat.courante + pas;
		if ( cible < 0 || cible >= total ) {
			return false;
		}
		afficherPage( cible, true );
		return true;
	}

	/** Page la plus haute encore visible sous la barre de lecture (mode défilement). */
	function pageVisible() {
		const limite = hauteurBarre() + 1;
		for ( let i = 0; i < total; i++ ) {
			if ( pages[ i ].getBoundingClientRect().bottom > limite + 20 ) {
				return i;
			}
		}
		return total - 1;
	}

	/* ------------------------------------------------------------------ */
	/* Modes et outils                                                     */
	/* ------------------------------------------------------------------ */

	function majBoutons() {
		racine.querySelectorAll( '[data-yn-manga-mode]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', String( b.getAttribute( 'data-yn-manga-mode' ) === etat.mode ) );
		} );
		racine.querySelectorAll( '[data-yn-manga-qualite]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', String( b.getAttribute( 'data-yn-manga-qualite' ) === etat.qualite ) );
		} );
	}

	function appliquerMode( mode, depuis, defiler ) {
		etat.mode = mode;
		racine.setAttribute( 'data-yn-mode', mode );
		if ( navigation ) {
			navigation.hidden = mode !== 'page';
		}
		if ( mode === 'page' ) {
			afficherPage( typeof depuis === 'number' ? depuis : etat.courante, defiler !== false );
		} else {
			racine.removeAttribute( 'data-yn-courante' );
			pages.forEach( function ( page ) {
				page.classList.remove( 'is-courante' );
			} );
			if ( typeof depuis === 'number' && pages[ depuis ] ) {
				const haut = pages[ depuis ].getBoundingClientRect().top + window.pageYOffset - hauteurBarre() - 8;
				window.scrollTo( { top: Math.max( 0, haut ) } );
			}
			prevenirLecteur();
		}
		majBoutons();
	}

	racine.addEventListener( 'click', function ( e ) {
		const cible = e.target.closest ? e.target.closest( 'button, a' ) : null;
		if ( cible && cible.hasAttribute( 'data-yn-manga-mode' ) ) {
			const mode = cible.getAttribute( 'data-yn-manga-mode' );
			if ( mode !== etat.mode ) {
				const depuis = etat.mode === 'page' ? etat.courante : pageVisible();
				appliquerMode( mode, depuis );
				ecrire();
			}
			return;
		}
		if ( cible && cible.hasAttribute( 'data-yn-manga-qualite' ) ) {
			etat.qualite = cible.getAttribute( 'data-yn-manga-qualite' );
			appliquerQualite();
			majBoutons();
			ecrire();
			return;
		}
		if ( cible && cible.hasAttribute( 'data-yn-manga-aller' ) ) {
			const pas = cible.getAttribute( 'data-yn-manga-aller' ) === 'suivante' ? 1 : -1;
			if ( ! tourner( pas ) && pas > 0 && lienChapitreSuivant() ) {
				window.location.href = lienChapitreSuivant();
			}
			return;
		}
		// Clic sur la page en mode page par page : moitié droite → suivante, gauche → précédente.
		if ( etat.mode === 'page' && ! cible && e.target.closest && e.target.closest( '.yn-manga__page' ) ) {
			const rect = zone.getBoundingClientRect();
			tourner( e.clientX >= rect.left + rect.width / 2 ? 1 : -1 );
		}
	} );

	function estChampSaisie( el ) {
		return !! ( el && ( el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( el.tagName || '' ) ) );
	}

	// Phase de capture : passe avant la barre de lecture (← / → : chapitre voisin), qui ignore
	// une touche déjà traitée (defaultPrevented). Au bout du chapitre, la touche lui revient.
	window.addEventListener(
		'keydown',
		function ( e ) {
			if ( etat.mode !== 'page' || e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || e.isComposing ) {
				return;
			}
			if ( estChampSaisie( e.target ) || document.querySelector( 'dialog[open]' ) ) {
				return;
			}
			let pas = 0;
			if ( e.key === 'ArrowRight' || e.key === 'PageDown' ) {
				pas = 1;
			} else if ( e.key === 'ArrowLeft' || e.key === 'PageUp' ) {
				pas = -1;
			}
			if ( pas && tourner( pas ) ) {
				e.preventDefault();
			}
		},
		true
	);

	// Reprise de lecture demandée par la barre (mode page).
	racine.addEventListener( 'yn:manga-aller', function ( e ) {
		const index = e.detail ? parseInt( e.detail.index, 10 ) : NaN;
		if ( ! isNaN( index ) && etat.mode === 'page' ) {
			afficherPage( index, true );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Démarrage                                                           */
	/* ------------------------------------------------------------------ */

	if ( outils ) {
		outils.hidden = false;
	}
	appliquerQualite();
	if ( etat.mode === 'page' ) {
		// Ouverture en haut du chapitre : la page est alignée sous la barre de lecture, entière à
		// l'écran (en-tête, crédits et outils restent au-dessus, accessibles en remontant). Une
		// ancre (#yn-p-N, #commentaires) ou une position restaurée par le navigateur est respectée.
		const enHaut = window.pageYOffset < 10 && ! window.location.hash;
		appliquerMode( 'page', 0, enHaut );
	} else {
		majBoutons();
	}
}() );
