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
 * - Doubles pages : une image paysage (largeur > hauteur) reçoit is-paysage (figure et image) ;
 *   manga.css l'élargit au-delà de la colonne, centrée sur la fenêtre, d'après les largeurs
 *   mesurées ici (--yn-manga-fenetre, --yn-manga-colonne, --yn-manga-decalage).
 * - Zoom : Auto (ajustement actuel) ou 50 à 300 % par pas de 25 (− / + / Auto, touches + = − 0,
 *   double clic ou double appui sur une page : Auto ↔ 200 %). Largeur rendue = largeur auto ×
 *   zoom (--yn-manga-zoom, data-yn-zoom="auto|manuel") ; l'image zoomée défile dans sa figure.
 *   En mode page, un simple clic tourne la page après DELAI_CLIC ms, annulé par un double clic.
 * - Choix gardés sur l'appareil : localStorage yn.manga = { mode, qualite, zoom } (zoom absent :
 *   Auto).
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
	const ZOOM_MIN = 50;
	const ZOOM_MAX = 300;
	const ZOOM_PAS = 25;
	const ZOOM_DOUBLE = 200;
	const DELAI_CLIC = 250;

	const pages = Array.prototype.slice.call( racine.querySelectorAll( '.yn-manga__page' ) );
	const zone = racine.querySelector( '.yn-manga__pages' );
	const outils = racine.querySelector( '.yn-manga__outils' );
	const navigation = racine.querySelector( '.yn-manga__navigation' );
	const compteur = racine.querySelector( '.yn-manga__compteur' );
	const boutonPrecedente = racine.querySelector( '[data-yn-manga-aller="precedente"]' );
	const boutonSuivante = racine.querySelector( '[data-yn-manga-aller="suivante"]' );
	const lienMangadex = racine.querySelector( '[data-yn-manga-mangadex]' );
	const valeurZoom = racine.querySelector( '[data-yn-manga-zoom-valeur]' );
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
			const valeur = { mode: etat.mode, qualite: etat.qualite };
			if ( etat.zoom !== null ) {
				valeur.zoom = etat.zoom;
			}
			window.localStorage.setItem( CLE, JSON.stringify( valeur ) );
		} catch ( e ) {
			// Stockage indisponible (navigation privée) : le choix vaut pour cette page.
		}
	}

	/**
	 * Zoom valide (nombre entier de 50 à 300, multiple de 25) ou null (Auto).
	 *
	 * @param {*} valeur Valeur lue.
	 * @return {number|null} Zoom.
	 */
	function zoomValide( valeur ) {
		return typeof valeur === 'number' && valeur >= ZOOM_MIN && valeur <= ZOOM_MAX && valeur % ZOOM_PAS === 0 ? valeur : null;
	}

	const memo = lire();
	const etat = {
		mode: MODES.indexOf( memo.mode ) >= 0 ? memo.mode : 'defilement',
		qualite: QUALITES.indexOf( memo.qualite ) >= 0 ? memo.qualite : 'data-saver',
		zoom: zoomValide( memo.zoom ),
		courante: 0,
	};

	/* ------------------------------------------------------------------ */
	/* Largeurs (doubles pages, zoom)                                      */
	/* ------------------------------------------------------------------ */

	/** Largeurs de la fenêtre et de la colonne, pour manga.css (en px). */
	function mesurer() {
		const fenetre = document.documentElement.clientWidth || window.innerWidth;
		const rect = zone.getBoundingClientRect();
		racine.style.setProperty( '--yn-manga-fenetre', fenetre + 'px' );
		racine.style.setProperty( '--yn-manga-colonne', rect.width + 'px' );
		racine.style.setProperty( '--yn-manga-decalage', ( fenetre / 2 - ( rect.left + rect.width / 2 ) ) + 'px' );
	}

	let mesureDemandee = false;
	function demanderMesure() {
		if ( mesureDemandee ) {
			return;
		}
		mesureDemandee = true;
		window.requestAnimationFrame( function () {
			mesureDemandee = false;
			mesurer();
		} );
	}

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
				page.classList.remove( 'is-chargee' );
				img.setAttribute( 'src', voulue );
			}
		} );
	}

	function surChargement( img ) {
		const page = img.closest( '.yn-manga__page' );
		const largeur = img.naturalWidth;
		const hauteur = img.naturalHeight;
		if ( largeur && hauteur ) {
			img.style.setProperty( '--yn-manga-naturelle', largeur + 'px' );
			img.style.setProperty( '--yn-manga-ratio', String( largeur / hauteur ) );
		}
		const paysage = !! ( largeur && largeur > hauteur );
		img.classList.toggle( 'is-paysage', paysage );
		img.classList.toggle( 'is-longue', !! ( largeur && hauteur > largeur * 2.2 ) );
		img.classList.add( 'is-chargee' );
		if ( page ) {
			page.classList.toggle( 'is-paysage', paysage );
			page.classList.add( 'is-chargee' );
			centrerFigure( page );
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
	/* Zoom                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Image zoomée plus large que sa figure : défilement horizontal centré (ou sur le point
	 * visé, fraction 0…1 de la largeur de l'image).
	 *
	 * @param {Element} page    Figure.
	 * @param {number}  [point] Fraction de la largeur de l'image à garder au centre.
	 */
	function centrerFigure( page, point ) {
		const debord = page.scrollWidth - page.clientWidth;
		if ( debord <= 0 ) {
			return;
		}
		const fraction = typeof point === 'number' ? point : 0.5;
		page.scrollLeft = Math.max( 0, Math.min( debord, fraction * page.scrollWidth - page.clientWidth / 2 ) );
	}

	function majZoom() {
		if ( valeurZoom ) {
			valeurZoom.textContent = etat.zoom === null ? 'Auto' : etat.zoom + ' %';
		}
		racine.querySelectorAll( '[data-yn-manga-zoom]' ).forEach( function ( b ) {
			const action = b.getAttribute( 'data-yn-manga-zoom' );
			if ( action === 'auto' ) {
				b.setAttribute( 'aria-pressed', String( etat.zoom === null ) );
			} else if ( action === 'moins' ) {
				b.disabled = etat.zoom !== null && etat.zoom <= ZOOM_MIN;
			} else if ( action === 'plus' ) {
				b.disabled = etat.zoom !== null && etat.zoom >= ZOOM_MAX;
			}
		} );
	}

	/**
	 * Applique un zoom (null : Auto) en gardant à l'écran la page lue.
	 *
	 * @param {number|null} zoom   Zoom en %.
	 * @param {Object}      [vise] { page, x } : figure et fraction horizontale à garder au centre.
	 */
	function appliquerZoom( zoom, vise ) {
		const page = vise && vise.page ? vise.page : pages[ etat.mode === 'page' ? etat.courante : pageVisible() ];
		const limite = hauteurBarre() + 1;
		const avant = page ? page.getBoundingClientRect() : null;
		const fraction = avant && avant.height ? ( limite - avant.top ) / avant.height : 0;

		etat.zoom = zoom;
		racine.setAttribute( 'data-yn-zoom', zoom === null ? 'auto' : 'manuel' );
		racine.style.setProperty( '--yn-manga-zoom', String( zoom === null ? 1 : zoom / 100 ) );
		majZoom();
		mesurer();

		// Défilement : la même ligne de la page lue reste sous la barre de lecture.
		if ( page && avant && etat.mode !== 'page' && avant.top < limite ) {
			const apres = page.getBoundingClientRect();
			window.scrollBy( 0, apres.top + fraction * apres.height - limite );
		}
		pages.forEach( function ( p ) {
			centrerFigure( p, p === page && vise && typeof vise.x === 'number' ? vise.x : undefined );
		} );
	}

	/**
	 * Pas de zoom : +1 agrandir, -1 réduire (depuis Auto : 125 % ou 75 %).
	 *
	 * @param {number} sens +1 ou -1.
	 */
	function pasZoom( sens ) {
		const depart = etat.zoom === null ? 100 : etat.zoom;
		const cible = Math.max( ZOOM_MIN, Math.min( ZOOM_MAX, depart + sens * ZOOM_PAS ) );
		if ( cible !== etat.zoom ) {
			appliquerZoom( cible );
			ecrire();
		}
	}

	function zoomAuto() {
		if ( etat.zoom !== null ) {
			appliquerZoom( null );
			ecrire();
		}
	}

	/**
	 * Double clic ou double appui : Auto ↔ 200 %, centré sur le point visé.
	 *
	 * @param {Element} page Figure.
	 * @param {number}  x    Abscisse du clic (fenêtre).
	 */
	function basculerZoom( page, x ) {
		const img = page.querySelector( 'img' );
		const rect = img ? img.getBoundingClientRect() : null;
		const point = rect && rect.width ? Math.max( 0, Math.min( 1, ( x - rect.left ) / rect.width ) ) : 0.5;
		appliquerZoom( etat.zoom === null ? ZOOM_DOUBLE : null, { page: page, x: point } );
		ecrire();
		if ( window.getSelection ) {
			window.getSelection().removeAllRanges();
		}
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
		mesurer();
	}

	let clicEnAttente = 0;
	let dernierClic = { page: null, t: 0 };

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
		if ( cible && cible.hasAttribute( 'data-yn-manga-zoom' ) ) {
			const action = cible.getAttribute( 'data-yn-manga-zoom' );
			if ( action === 'auto' ) {
				zoomAuto();
			} else {
				pasZoom( action === 'plus' ? 1 : -1 );
			}
			return;
		}
		if ( cible && cible.hasAttribute( 'data-yn-manga-aller' ) ) {
			const pas = cible.getAttribute( 'data-yn-manga-aller' ) === 'suivante' ? 1 : -1;
			if ( ! tourner( pas ) && pas > 0 && lienChapitreSuivant() ) {
				window.location.href = lienChapitreSuivant();
			}
			return;
		}
		const page = ! cible && e.target.closest ? e.target.closest( '.yn-manga__page' ) : null;
		if ( ! page ) {
			return;
		}
		// Deuxième clic rapproché sur la même page (double clic à la souris, double appui au
		// doigt, quel que soit e.detail) : bascule du zoom ; la page ne tourne pas (le premier
		// clic attendait encore). Un troisième clic repart de zéro.
		const maintenant = Date.now();
		if ( e.detail !== 0 && dernierClic.page === page && maintenant - dernierClic.t <= DELAI_CLIC ) {
			window.clearTimeout( clicEnAttente );
			clicEnAttente = 0;
			dernierClic = { page: null, t: 0 };
			basculerZoom( page, e.clientX );
			return;
		}
		dernierClic = { page: page, t: maintenant };
		// Clic sur la page en mode page par page : moitié droite → suivante, gauche → précédente,
		// après DELAI_CLIC ms (le temps d'un éventuel double clic). Clic sans pointeur
		// (detail 0) : tout de suite.
		if ( etat.mode === 'page' ) {
			const rect = zone.getBoundingClientRect();
			const pas = e.clientX >= rect.left + rect.width / 2 ? 1 : -1;
			window.clearTimeout( clicEnAttente );
			clicEnAttente = window.setTimeout( function () {
				clicEnAttente = 0;
				tourner( pas );
			}, e.detail === 0 ? 0 : DELAI_CLIC );
		}
	} );

	function estChampSaisie( el ) {
		if ( ! el || ! el.tagName ) {
			return false;
		}
		return !! ( el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( el.tagName ) || ( el.closest && el.closest( '[contenteditable="true"], [role="textbox"], [role="combobox"], [role="slider"]' ) ) );
	}

	// Phase de capture : passe avant la barre de lecture (← / → : chapitre voisin), qui ignore
	// une touche déjà traitée (defaultPrevented). Au bout du chapitre, la touche lui revient.
	// Zoom (+ = − 0, les deux modes) : Maj permise (« + » ou « 0 » selon le clavier), jamais
	// Ctrl, Alt ou Cmd (zoom du navigateur).
	window.addEventListener(
		'keydown',
		function ( e ) {
			if ( e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.isComposing ) {
				return;
			}
			if ( estChampSaisie( e.target ) || document.querySelector( 'dialog[open]' ) ) {
				return;
			}
			if ( e.key === '+' || e.key === '=' || e.key === '-' || e.key === '0' ) {
				e.preventDefault();
				if ( e.key === '0' ) {
					zoomAuto();
				} else {
					pasZoom( e.key === '-' ? -1 : 1 );
				}
				return;
			}
			if ( etat.mode !== 'page' || e.shiftKey ) {
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
	mesurer();
	window.addEventListener( 'resize', demanderMesure );
	if ( typeof window.ResizeObserver === 'function' ) {
		new window.ResizeObserver( demanderMesure ).observe( zone );
	}
	appliquerZoom( etat.zoom );
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
