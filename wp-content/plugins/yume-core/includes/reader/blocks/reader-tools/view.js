/**
 * Barre de lecture Yume (bloc yume/reader-tools).
 *
 * - Panneau Paramètres (<dialog> modal) : aperçu en direct, « Valider » (localStorage
 *   yn.reglages pour tous, PUT /yume/v1/moi/reglages pour les membres), Échap / ✕ annulent,
 *   « Réinitialiser par défaut ». Focus piégé dans le panneau, retour au bouton à la fermeture.
 * - Variables --yn-size, --yn-lh, --yn-font, --yn-width, --yn-bg-alpha posées sur .yn-reader.
 * - Bascule de thème nuit → papier → sépia via window.ynTheme.set (contrat §14).
 * - Suivi de lecture : IntersectionObserver sur les paragraphes du chapitre ; position dans
 *   localStorage yn.progression (clé : œuvre) et, pour les membres, PUT /moi/progression
 *   (au plus toutes les 10 s, et au départ de la page avec fetch keepalive).
 * - Marque-page explicite avec retour visuel ; bandeau « Reprendre au paragraphe N ».
 * - Raccourcis : ← / → chapitre précédent / suivant, « s » paramètres (inactifs dans un champ).
 * - Page « Illustrations » d'un tome (config.chapitre = 0) : ni suivi ni marque-page, la
 *   position enregistrée n'est jamais remplacée ; → ouvre le premier chapitre.
 *
 * JavaScript sans étape de build (ES2019), sans dépendance.
 */
( function () {
	'use strict';

	const racine = document.querySelector( '.yn-reader-tools[data-yn-lecteur]' );
	if ( ! racine ) {
		return;
	}
	let config;
	try {
		config = JSON.parse( racine.getAttribute( 'data-yn-lecteur' ) || '{}' );
	} catch ( e ) {
		return;
	}

	const CLE_REGLAGES = 'yn.reglages';
	const CLE_PROGRESSION = 'yn.progression';
	const CLE_THEME = 'yn.theme';
	const THEMES = [ 'nuit', 'papier', 'sepia' ];
	const NOMS_THEMES = config.themes || { nuit: 'Nuit', papier: 'Papier', sepia: 'Sépia' };
	const MAX_ENTREES = 100;
	const DELAI_SERVEUR = 10000;

	const html = document.documentElement;
	const D = config.defauts;
	const B = config.bornes;
	const P = config.polices || {};
	const reduit = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	const collant = racine.querySelector( '.yn-reader-tools__collant' );
	const dialogue = document.getElementById( 'yn-parametres-lecture' );
	const formulaire = dialogue ? dialogue.querySelector( '[data-yn-formulaire]' ) : null;
	const boutonParametres = racine.querySelector( '[data-yn-action="parametres"]' );
	const boutonTheme = racine.querySelector( '[data-yn-action="theme"]' );
	const boutonMarque = racine.querySelector( '[data-yn-action="marque-page"]' );
	const barre = racine.querySelector( '[data-yn-progression]' );
	const barreRemplie = barre ? barre.querySelector( 'span' ) : null;
	const texteProgression = racine.querySelector( '[data-yn-pourcentage-texte]' );
	const bandeau = racine.querySelector( '[data-yn-reprise]' );
	const annonce = racine.querySelector( '[data-yn-annonce]' );
	const toast = racine.querySelector( '[data-yn-toast]' );

	/* ------------------------------------------------------------------ */
	/* Outils                                                              */
	/* ------------------------------------------------------------------ */

	function lireJSON( cle ) {
		try {
			const valeur = window.localStorage.getItem( cle );
			return valeur ? JSON.parse( valeur ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function ecrireJSON( cle, valeur ) {
		try {
			window.localStorage.setItem( cle, JSON.stringify( valeur ) );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	function effacer( cle ) {
		try {
			window.localStorage.removeItem( cle );
		} catch ( e ) {
			// Stockage indisponible : rien à effacer.
		}
	}

	function nombreFr( n ) {
		return String( Math.round( n * 100 ) / 100 ).replace( '.', ',' );
	}

	/**
	 * Appel REST authentifié (membres seulement), avec le nonce wp_rest en X-WP-Nonce.
	 */
	function requete( methode, route, donnees, options ) {
		if ( ! config.connecte || ! config.rest || ! window.fetch ) {
			return Promise.reject( new Error( 'indisponible' ) );
		}
		const init = {
			method: methode,
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
		};
		if ( donnees ) {
			init.body = JSON.stringify( donnees );
		}
		if ( options && options.keepalive ) {
			init.keepalive = true;
		}
		return window.fetch( config.rest + route, init ).then( function ( reponse ) {
			if ( ! reponse.ok ) {
				throw new Error( 'HTTP ' + reponse.status );
			}
			return reponse.json();
		} );
	}

	let minuterieAnnonce = null;
	function annoncer( texte ) {
		if ( ! annonce ) {
			return;
		}
		annonce.textContent = '';
		clearTimeout( minuterieAnnonce );
		minuterieAnnonce = setTimeout( function () {
			annonce.textContent = texte;
		}, 60 );
	}

	let minuterieToast = null;
	function afficherToast( texte ) {
		if ( ! toast ) {
			return;
		}
		toast.textContent = texte;
		toast.hidden = false;
		clearTimeout( minuterieToast );
		minuterieToast = setTimeout( function () {
			toast.hidden = true;
		}, 2800 );
	}

	/* ------------------------------------------------------------------ */
	/* Thème                                                               */
	/* ------------------------------------------------------------------ */

	function themeCourant() {
		const theme = window.ynTheme && window.ynTheme.get ? window.ynTheme.get() : html.getAttribute( 'data-yn-theme' );
		return THEMES.indexOf( theme ) > -1 ? theme : 'nuit';
	}

	function poserTheme( theme ) {
		if ( THEMES.indexOf( theme ) < 0 || theme === themeCourant() ) {
			return;
		}
		if ( window.ynTheme && window.ynTheme.set ) {
			window.ynTheme.set( theme );
			return;
		}
		html.setAttribute( 'data-yn-theme', theme );
		try {
			window.localStorage.setItem( CLE_THEME, theme );
		} catch ( e ) {
			// Le choix vaut pour la page.
		}
		let evenement;
		try {
			evenement = new CustomEvent( 'yn:theme', { detail: { theme: theme } } );
		} catch ( e ) {
			evenement = document.createEvent( 'CustomEvent' );
			evenement.initCustomEvent( 'yn:theme', false, false, { theme: theme } );
		}
		document.dispatchEvent( evenement );
	}

	function themeSuivant( theme ) {
		return THEMES[ ( THEMES.indexOf( theme ) + 1 ) % THEMES.length ];
	}

	function majBoutonTheme() {
		if ( ! boutonTheme ) {
			return;
		}
		const theme = themeCourant();
		const libelle = 'Thème de lecture : ' + NOMS_THEMES[ theme ] + '. Passer au thème ' + NOMS_THEMES[ themeSuivant( theme ) ];
		boutonTheme.setAttribute( 'aria-label', libelle );
		boutonTheme.setAttribute( 'title', libelle );
	}

	/* ------------------------------------------------------------------ */
	/* Réglages de lecture                                                 */
	/* ------------------------------------------------------------------ */

	function borner( valeur, cle ) {
		let v = parseFloat( valeur );
		if ( ! isFinite( v ) ) {
			return D[ cle ];
		}
		v = Math.min( B[ cle ][ 1 ], Math.max( B[ cle ][ 0 ], v ) );
		return cle === 'size' || cle === 'width' ? Math.round( v ) : Math.round( v * 100 ) / 100;
	}

	function valider( brut ) {
		const source = brut && typeof brut === 'object' ? brut : {};
		const r = {};
		[ 'size', 'lh', 'width', 'bgAlpha' ].forEach( function ( cle ) {
			r[ cle ] = source[ cle ] === undefined || source[ cle ] === null ? D[ cle ] : borner( source[ cle ], cle );
		} );
		r.font = typeof source.font === 'string' && Object.prototype.hasOwnProperty.call( P, source.font ) ? source.font : D.font;
		r.theme = THEMES.indexOf( source.theme ) > -1 ? source.theme : themeCourant();
		return r;
	}

	function copie( r ) {
		return JSON.parse( JSON.stringify( r ) );
	}

	/** Réglages en vigueur au chargement : compte (membre), sinon appareil, sinon défauts. */
	function reglagesInitiaux() {
		const base = config.reglages || lireJSON( CLE_REGLAGES ) || {};
		const r = valider( base );
		r.theme = themeCourant();
		return r;
	}

	let enVigueur = reglagesInitiaux();
	let styleReglages = document.getElementById( 'yn-reglages-init' );

	function declarations( r ) {
		const d = {};
		d[ '--yn-size' ] = r.size !== D.size ? r.size + 'px' : '';
		d[ '--yn-lh' ] = r.lh !== D.lh ? String( r.lh ) : '';
		d[ '--yn-width' ] = r.width !== D.width ? r.width + 'ch' : '';
		d[ '--yn-bg-alpha' ] = r.bgAlpha !== D.bgAlpha ? String( r.bgAlpha ) : '';
		d[ '--yn-font' ] = r.font !== D.font && P[ r.font ] ? P[ r.font ] : '';
		return d;
	}

	/** Applique des réglages (aperçu ou définitif) : variables sur chaque .yn-reader, thème. */
	function appliquer( r ) {
		const d = declarations( r );
		const regle = [];
		Object.keys( d ).forEach( function ( variable ) {
			if ( d[ variable ] ) {
				regle.push( variable + ':' + d[ variable ] );
			}
		} );
		if ( ! styleReglages ) {
			styleReglages = document.createElement( 'style' );
			styleReglages.id = 'yn-reglages-init';
			document.head.appendChild( styleReglages );
		}
		styleReglages.textContent = regle.length ? '.yn-reader{' + regle.join( ';' ) + '}' : '';
		document.querySelectorAll( '.yn-reader' ).forEach( function ( lecteur ) {
			Object.keys( d ).forEach( function ( variable ) {
				if ( d[ variable ] ) {
					lecteur.style.setProperty( variable, d[ variable ] );
				} else {
					lecteur.style.removeProperty( variable );
				}
			} );
		} );
		poserTheme( r.theme );
	}

	/** Enregistre les réglages validés : appareil pour tous, compte pour les membres. */
	function enregistrerReglages( r ) {
		enVigueur = copie( r );
		ecrireJSON( CLE_REGLAGES, { size: r.size, lh: r.lh, font: r.font, width: r.width, bgAlpha: r.bgAlpha } );
		if ( ! config.connecte ) {
			return Promise.resolve( 'local' );
		}
		return requete( 'PUT', 'moi/reglages', r ).then(
			function ( reponse ) {
				config.reglages = reponse;
				return 'compte';
			},
			function () {
				return 'echec';
			}
		);
	}

	let minuterieTheme = null;
	function enregistrerThemeCompte( theme ) {
		if ( ! config.connecte ) {
			return;
		}
		clearTimeout( minuterieTheme );
		minuterieTheme = setTimeout( function () {
			// Compte encore sans réglages : le serveur compléterait le thème avec les valeurs
			// par défaut, qui écraseraient ensuite les réglages de l'appareil (appliqués
			// jusque-là). On envoie donc le jeu complet en vigueur.
			const donnees = config.reglages ? { theme: theme } : Object.assign( copie( enVigueur ), { theme: theme } );
			requete( 'PUT', 'moi/reglages', donnees ).then(
				function ( reponse ) {
					config.reglages = reponse;
				},
				function () {}
			);
		}, 1200 );
	}

	/* ------------------------------------------------------------------ */
	/* Panneau Paramètres                                                  */
	/* ------------------------------------------------------------------ */

	const libellesSorties = {
		size: function ( v ) {
			return v + ' px';
		},
		lh: function ( v ) {
			return nombreFr( v );
		},
		bgAlpha: function ( v ) {
			return Math.round( v * 100 ) + ' %';
		},
		width: function ( v ) {
			return v + ' caractères';
		},
	};
	const textesAria = {
		size: function ( v ) {
			return v + ' pixels';
		},
		lh: function ( v ) {
			return 'interligne ' + nombreFr( v );
		},
		bgAlpha: function ( v ) {
			return 'opacité ' + Math.round( v * 100 ) + ' %';
		},
		width: function ( v ) {
			return v + ' caractères par ligne';
		},
	};

	function champ( nom ) {
		return formulaire ? formulaire.querySelector( '[name="' + nom + '"]' ) : null;
	}

	function majCurseur( input ) {
		const min = parseFloat( input.min );
		const max = parseFloat( input.max );
		const v = parseFloat( input.value );
		input.style.setProperty( '--yn-rempli', ( ( v - min ) / ( max - min ) ) * 100 + '%' );
	}

	function majChoix() {
		if ( ! formulaire ) {
			return;
		}
		formulaire.querySelectorAll( '.yn-reader-panel__option' ).forEach( function ( option ) {
			const radio = option.querySelector( 'input' );
			option.classList.toggle( 'est-choisi', !! ( radio && radio.checked ) );
		} );
	}

	function majSorties( r ) {
		Object.keys( libellesSorties ).forEach( function ( cle ) {
			const sortie = formulaire.querySelector( '[data-yn-sortie="' + cle + '"]' );
			if ( sortie ) {
				sortie.textContent = libellesSorties[ cle ]( r[ cle ] );
			}
			const input = champ( cle );
			if ( input ) {
				input.setAttribute( 'aria-valuetext', textesAria[ cle ]( r[ cle ] ) );
				majCurseur( input );
			}
		} );
		majChoix();
	}

	function remplirFormulaire( r ) {
		if ( ! formulaire ) {
			return;
		}
		champ( 'size' ).value = r.size;
		champ( 'lh' ).value = r.lh;
		champ( 'bgAlpha' ).value = Math.round( r.bgAlpha * 100 );
		champ( 'width' ).value = r.width;
		formulaire.querySelectorAll( 'input[name="font"]' ).forEach( function ( radio ) {
			radio.checked = radio.value === r.font;
		} );
		formulaire.querySelectorAll( 'input[name="theme"]' ).forEach( function ( radio ) {
			radio.checked = radio.value === r.theme;
		} );
		majSorties( r );
	}

	function lireFormulaire() {
		const police = formulaire.querySelector( 'input[name="font"]:checked' );
		const theme = formulaire.querySelector( 'input[name="theme"]:checked' );
		return valider( {
			size: champ( 'size' ).value,
			lh: champ( 'lh' ).value,
			bgAlpha: parseFloat( champ( 'bgAlpha' ).value ) / 100,
			width: champ( 'width' ).value,
			font: police ? police.value : D.font,
			theme: theme ? theme.value : themeCourant(),
		} );
	}

	let avantOuverture = null;
	let restauration = false;
	let rafPosition = 0;

	function positionnerPanneau() {
		if ( ! dialogue || ! collant ) {
			return;
		}
		const bas = Math.max( 0, collant.getBoundingClientRect().bottom );
		dialogue.style.setProperty( '--yn-panneau-haut', Math.round( bas + 12 ) + 'px' );
	}

	function surDefilementPanneau() {
		if ( rafPosition ) {
			return;
		}
		rafPosition = window.requestAnimationFrame( function () {
			rafPosition = 0;
			positionnerPanneau();
		} );
	}

	function focusables() {
		return Array.prototype.filter.call(
			dialogue.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' ),
			function ( el ) {
				if ( el.disabled || el.getClientRects().length === 0 ) {
					return false;
				}
				// Un seul arrêt par groupe de boutons radio (celui qui est coché).
				if ( el.type === 'radio' && ! el.checked ) {
					const groupe = dialogue.querySelectorAll( 'input[type="radio"][name="' + el.name + '"]:checked' );
					return groupe.length === 0;
				}
				return true;
			}
		);
	}

	function ouvrirPanneau() {
		if ( ! dialogue || dialogue.open ) {
			return;
		}
		avantOuverture = copie( enVigueur );
		avantOuverture.theme = themeCourant();
		remplirFormulaire( avantOuverture );
		positionnerPanneau();
		if ( typeof dialogue.showModal === 'function' ) {
			dialogue.showModal();
		} else {
			dialogue.setAttribute( 'open', '' );
		}
		html.classList.add( 'yn-panneau-ouvert' );
		boutonParametres.setAttribute( 'aria-expanded', 'true' );
		const premier = dialogue.querySelector( '[data-yn-action="fermer"]' );
		if ( premier ) {
			premier.focus();
		}
		window.addEventListener( 'scroll', surDefilementPanneau, { passive: true } );
		window.addEventListener( 'resize', surDefilementPanneau );
	}

	/**
	 * Remise en état après fermeture (y compris une fermeture forcée par le navigateur) :
	 * un aperçu non validé est annulé, le focus revient au bouton Paramètres.
	 */
	function apresFermeture() {
		if ( avantOuverture ) {
			restauration = true;
			appliquer( avantOuverture );
			remplirFormulaire( avantOuverture );
			avantOuverture = null;
			restauration = false;
		}
		html.classList.remove( 'yn-panneau-ouvert' );
		boutonParametres.setAttribute( 'aria-expanded', 'false' );
		window.removeEventListener( 'scroll', surDefilementPanneau );
		window.removeEventListener( 'resize', surDefilementPanneau );
		boutonParametres.focus();
		majBoutonTheme();
	}

	function fermerPanneau( annuler ) {
		if ( ! dialogue || ! dialogue.open ) {
			return;
		}
		if ( ! annuler ) {
			avantOuverture = null;
		}
		if ( typeof dialogue.close === 'function' ) {
			dialogue.close();
		} else {
			dialogue.removeAttribute( 'open' );
			apresFermeture();
		}
	}

	function validerPanneau() {
		const r = lireFormulaire();
		appliquer( r );
		fermerPanneau( false );
		enregistrerReglages( r ).then( function ( resultat ) {
			if ( resultat === 'compte' ) {
				annoncer( 'Réglages de lecture enregistrés sur votre compte.' );
				afficherToast( 'Réglages enregistrés sur votre compte' );
			} else if ( resultat === 'echec' ) {
				annoncer( 'Réglages enregistrés sur cet appareil ; la synchronisation avec votre compte a échoué.' );
				afficherToast( 'Réglages enregistrés sur cet appareil (compte injoignable)' );
			} else {
				annoncer( 'Réglages de lecture enregistrés sur cet appareil.' );
				afficherToast( 'Réglages enregistrés sur cet appareil' );
			}
		} );
	}

	function reinitialiserPanneau() {
		const r = copie( D );
		remplirFormulaire( r );
		appliquer( r );
		enVigueur = copie( r );
		avantOuverture = copie( r );
		effacer( CLE_REGLAGES );
		if ( config.connecte ) {
			requete( 'PUT', 'moi/reglages', { reinitialiser: true } ).then(
				function ( reponse ) {
					config.reglages = null;
					return reponse;
				},
				function () {}
			);
		}
		annoncer( 'Réglages de lecture par défaut rétablis.' );
	}

	if ( dialogue && formulaire && boutonParametres ) {
		boutonParametres.addEventListener( 'click', function () {
			if ( dialogue.open ) {
				fermerPanneau( true );
			} else {
				ouvrirPanneau();
			}
		} );

		formulaire.addEventListener( 'input', function () {
			const r = lireFormulaire();
			majSorties( r );
			appliquer( r );
		} );
		formulaire.addEventListener( 'change', function () {
			majChoix();
		} );
		formulaire.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			validerPanneau();
		} );
		formulaire.addEventListener( 'focusin', function ( e ) {
			const option = e.target.closest ? e.target.closest( '.yn-reader-panel__option' ) : null;
			formulaire.querySelectorAll( '.a-le-focus' ).forEach( function ( el ) {
				el.classList.remove( 'a-le-focus' );
			} );
			if ( option ) {
				option.classList.add( 'a-le-focus' );
			}
		} );
		formulaire.addEventListener( 'focusout', function ( e ) {
			const option = e.target.closest ? e.target.closest( '.yn-reader-panel__option' ) : null;
			if ( option ) {
				option.classList.remove( 'a-le-focus' );
			}
		} );

		dialogue.addEventListener( 'click', function ( e ) {
			const action = e.target.closest ? e.target.closest( '[data-yn-action]' ) : null;
			if ( action && action.getAttribute( 'data-yn-action' ) === 'fermer' ) {
				fermerPanneau( true );
				return;
			}
			if ( action && action.getAttribute( 'data-yn-action' ) === 'reinitialiser' ) {
				reinitialiserPanneau();
				return;
			}
			const pas = e.target.closest ? e.target.closest( '[data-yn-pas]' ) : null;
			if ( pas ) {
				const input = champ( pas.getAttribute( 'data-yn-pas' ) );
				const sens = parseFloat( pas.getAttribute( 'data-yn-sens' ) ) || 0;
				const suivant = Math.min( parseFloat( input.max ), Math.max( parseFloat( input.min ), parseFloat( input.value ) + sens * ( parseFloat( input.step ) || 1 ) ) );
				input.value = suivant;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				return;
			}
			// Clic sur le fond (hors du panneau) : annulation.
			if ( e.target === dialogue ) {
				const zone = dialogue.getBoundingClientRect();
				const dedans = e.clientX >= zone.left && e.clientX <= zone.right && e.clientY >= zone.top && e.clientY <= zone.bottom;
				if ( ! dedans ) {
					fermerPanneau( true );
				}
			}
		} );

		// Échap : annule l'aperçu et ferme.
		dialogue.addEventListener( 'cancel', function ( e ) {
			e.preventDefault();
			fermerPanneau( true );
		} );
		dialogue.addEventListener( 'close', apresFermeture );

		// Focus piégé : Tab et Maj+Tab bouclent dans le panneau.
		dialogue.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && typeof dialogue.showModal !== 'function' ) {
				e.preventDefault();
				fermerPanneau( true );
				return;
			}
			if ( e.key !== 'Tab' ) {
				return;
			}
			const liste = focusables();
			if ( ! liste.length ) {
				return;
			}
			const premier = liste[ 0 ];
			const dernier = liste[ liste.length - 1 ];
			if ( e.shiftKey && ( document.activeElement === premier || ! dialogue.contains( document.activeElement ) ) ) {
				e.preventDefault();
				dernier.focus();
			} else if ( ! e.shiftKey && ( document.activeElement === dernier || ! dialogue.contains( document.activeElement ) ) ) {
				e.preventDefault();
				premier.focus();
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Bascule de thème                                                    */
	/* ------------------------------------------------------------------ */

	if ( boutonTheme ) {
		boutonTheme.addEventListener( 'click', function () {
			const suivant = themeSuivant( themeCourant() );
			poserTheme( suivant );
			annoncer( 'Thème ' + NOMS_THEMES[ suivant ] + ' appliqué.' );
		} );
	}

	document.addEventListener( 'yn:theme', function ( e ) {
		const theme = e && e.detail && THEMES.indexOf( e.detail.theme ) > -1 ? e.detail.theme : themeCourant();
		majBoutonTheme();
		if ( dialogue && dialogue.open ) {
			// Aperçu en cours : le choix sera enregistré par « Valider ».
			formulaire.querySelectorAll( 'input[name="theme"]' ).forEach( function ( radio ) {
				radio.checked = radio.value === theme;
			} );
			majChoix();
			return;
		}
		enVigueur.theme = theme;
		if ( ! restauration ) {
			enregistrerThemeCompte( theme );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Suivi de lecture                                                    */
	/* ------------------------------------------------------------------ */

	const article = document.querySelector( '.yn-reader' );
	const conteneur = article ? article.querySelector( '.wp-block-post-content' ) || article : null;
	let paragraphes = [];
	if ( conteneur ) {
		paragraphes = Array.prototype.filter.call( conteneur.children, function ( el ) {
			return ! /^(SCRIPT|STYLE|TEMPLATE|NOSCRIPT)$/.test( el.tagName );
		} );
		if ( conteneur === article || paragraphes.length < 2 ) {
			paragraphes = Array.prototype.slice.call( article.querySelectorAll( 'p' ) );
		}
	}
	paragraphes.forEach( function ( el, i ) {
		el.setAttribute( 'data-yn-paragraphe', String( i ) );
	} );

	const suiviPossible = !! ( article && config.oeuvre && config.chapitre );
	let suiviActif = false;
	let position = { paragraphe: 0, pourcentage: 0 };
	let derniereLocale = 0;
	let derniereEnvoyee = null;
	let minuterieServeur = null;
	const visibles = new Set();

	function hauteurCollant() {
		if ( ! collant ) {
			return 0;
		}
		const haut = parseFloat( window.getComputedStyle( collant ).top ) || 0;
		return collant.offsetHeight + haut;
	}

	function pourcentageLu() {
		if ( ! article ) {
			return 0;
		}
		const zone = article.getBoundingClientRect();
		const decalage = hauteurCollant();
		const parcours = zone.height - window.innerHeight + decalage;
		if ( parcours <= 0 ) {
			return 100;
		}
		const fait = decalage - zone.top;
		return Math.max( 0, Math.min( 100, Math.round( ( fait / parcours ) * 100 ) ) );
	}

	/**
	 * Paragraphe en cours : le premier dont une part lisible (au moins SEUIL_VISIBLE px) dépasse
	 * sous la barre collante. Le bas du paragraphe précédent, visible de quelques pixels après
	 * un saut vers #yn-p-N (marge de 16 px), ne compte pas : sinon chaque reprise sans
	 * lecture reculerait la position enregistrée d'un paragraphe.
	 */
	const SEUIL_VISIBLE = 20;
	function paragrapheCourant() {
		if ( visibles.size ) {
			const tries = Array.from( visibles ).sort( function ( a, b ) {
				return a - b;
			} );
			const limite = hauteurCollant();
			for ( let i = 0; i < tries.length; i++ ) {
				const el = paragraphes[ tries[ i ] ];
				if ( el && el.getBoundingClientRect().bottom - limite >= SEUIL_VISIBLE ) {
					return tries[ i ];
				}
			}
			return tries[ tries.length - 1 ];
		}
		// Aucun paragraphe visible (grande illustration) : le dernier dont le haut est passé.
		const limite = hauteurCollant() + 1;
		let trouve = position.paragraphe;
		for ( let i = 0; i < paragraphes.length; i++ ) {
			if ( paragraphes[ i ].getBoundingClientRect().top <= limite ) {
				trouve = i;
			} else {
				break;
			}
		}
		return trouve;
	}

	function memoriserLocal( pos ) {
		let toutes = lireJSON( CLE_PROGRESSION );
		if ( ! toutes || typeof toutes !== 'object' || Array.isArray( toutes ) ) {
			toutes = {};
		}
		toutes[ config.oeuvre ] = {
			chapitre_id: config.chapitre,
			tome_id: config.tome,
			paragraphe: pos.paragraphe,
			pourcentage: pos.pourcentage,
			url: config.url,
			titre: config.titre,
			updated_at: new Date().toISOString(),
		};
		const cles = Object.keys( toutes );
		if ( cles.length > MAX_ENTREES ) {
			cles.sort( function ( a, b ) {
				return String( ( toutes[ b ] || {} ).updated_at ).localeCompare( String( ( toutes[ a ] || {} ).updated_at ) );
			} );
			cles.slice( MAX_ENTREES ).forEach( function ( cle ) {
				delete toutes[ cle ];
			} );
		}
		return ecrireJSON( CLE_PROGRESSION, toutes );
	}

	function envoyerServeur( keepalive ) {
		if ( ! config.connecte || ! suiviPossible ) {
			return Promise.resolve( false );
		}
		clearTimeout( minuterieServeur );
		minuterieServeur = null;
		const pos = { paragraphe: position.paragraphe, pourcentage: position.pourcentage };
		if ( derniereEnvoyee && derniereEnvoyee.paragraphe === pos.paragraphe && derniereEnvoyee.pourcentage === pos.pourcentage ) {
			return Promise.resolve( true );
		}
		derniereEnvoyee = pos;
		return requete(
			'PUT',
			'moi/progression',
			{ chapitre_id: config.chapitre, paragraphe: pos.paragraphe, pourcentage: pos.pourcentage },
			{ keepalive: !! keepalive }
		).then(
			function () {
				return true;
			},
			function () {
				derniereEnvoyee = null;
				return false;
			}
		);
	}

	function planifierServeur() {
		if ( ! config.connecte || minuterieServeur ) {
			return;
		}
		minuterieServeur = setTimeout( function () {
			minuterieServeur = null;
			envoyerServeur( false );
		}, DELAI_SERVEUR );
	}

	function majBarre( pourcentage ) {
		if ( barreRemplie ) {
			barreRemplie.style.setProperty( '--v', pourcentage + '%' );
		}
		if ( barre ) {
			barre.setAttribute( 'aria-valuenow', String( pourcentage ) );
			barre.setAttribute( 'aria-valuetext', pourcentage + ' % ' + ( barre.getAttribute( 'data-yn-portee' ) || 'du chapitre' ) );
		}
		if ( texteProgression ) {
			texteProgression.textContent = ' · ' + pourcentage + ' %';
		}
	}

	function suivre( force ) {
		const pourcentage = pourcentageLu();
		majBarre( pourcentage );
		if ( ! suiviPossible ) {
			return;
		}
		const nouvelle = { paragraphe: paragrapheCourant(), pourcentage: pourcentage };
		const change = nouvelle.paragraphe !== position.paragraphe || nouvelle.pourcentage !== position.pourcentage;
		position = nouvelle;
		if ( ! suiviActif ) {
			return;
		}
		const maintenant = Date.now();
		if ( force || ( change && maintenant - derniereLocale > 800 ) ) {
			derniereLocale = maintenant;
			memoriserLocal( position );
		}
		if ( change || force ) {
			planifierServeur();
		}
	}

	let rafSuivi = 0;
	function surDefilement() {
		if ( rafSuivi ) {
			return;
		}
		rafSuivi = window.requestAnimationFrame( function () {
			rafSuivi = 0;
			suivre( false );
		} );
	}

	function activerSuivi() {
		if ( suiviActif || ! suiviPossible ) {
			return;
		}
		suiviActif = true;
		suivre( true );
	}

	if ( paragraphes.length && 'IntersectionObserver' in window ) {
		const observateur = new IntersectionObserver(
			function ( entrees ) {
				entrees.forEach( function ( entree ) {
					const index = parseInt( entree.target.getAttribute( 'data-yn-paragraphe' ), 10 );
					if ( entree.isIntersecting ) {
						visibles.add( index );
					} else {
						visibles.delete( index );
					}
				} );
				surDefilement();
			},
			{ rootMargin: '-' + Math.round( hauteurCollant() ) + 'px 0px 0px 0px', threshold: 0 }
		);
		paragraphes.forEach( function ( el ) {
			observateur.observe( el );
		} );
	}

	window.addEventListener( 'scroll', surDefilement, { passive: true } );
	window.addEventListener( 'resize', surDefilement );

	// Départ de la page : dernier envoi avec keepalive (la requête survit à la navigation).
	function depart() {
		if ( ! suiviActif ) {
			return;
		}
		suivre( true );
		envoyerServeur( true );
	}
	window.addEventListener( 'pagehide', depart );
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			depart();
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Reprise et marque-page                                              */
	/* ------------------------------------------------------------------ */

	function allerAuParagraphe( index, doux ) {
		const cible = paragraphes[ index ];
		if ( ! cible ) {
			return false;
		}
		const y = cible.getBoundingClientRect().top + window.pageYOffset - hauteurCollant() - 16;
		window.scrollTo( { top: Math.max( 0, y ), behavior: doux && ! reduit ? 'smooth' : 'auto' } );
		cible.classList.add( 'yn-reprise-cible' );
		if ( ! cible.hasAttribute( 'tabindex' ) ) {
			cible.setAttribute( 'tabindex', '-1' );
			cible.addEventListener(
				'blur',
				function () {
					cible.removeAttribute( 'tabindex' );
				},
				{ once: true }
			);
		}
		try {
			cible.focus( { preventScroll: true } );
		} catch ( e ) {
			cible.focus();
		}
		setTimeout( function () {
			cible.classList.remove( 'yn-reprise-cible' );
		}, 2600 );
		return true;
	}

	/** Position mémorisée pour l'œuvre : la plus récente entre le compte et l'appareil. */
	function positionMemorisee() {
		const locales = lireJSON( CLE_PROGRESSION );
		const locale = locales && typeof locales === 'object' ? locales[ config.oeuvre ] : null;
		const serveur = config.progression;
		let choix = null;
		if ( locale && locale.chapitre_id ) {
			choix = { chapitre_id: +locale.chapitre_id, paragraphe: +locale.paragraphe || 0, pourcentage: +locale.pourcentage || 0, date: Date.parse( locale.updated_at ) || 0 };
		}
		if ( serveur && serveur.chapitre_id ) {
			const date = Date.parse( serveur.updated_iso ) || 0;
			if ( ! choix || date >= choix.date ) {
				choix = { chapitre_id: +serveur.chapitre_id, paragraphe: +serveur.paragraphe || 0, pourcentage: +serveur.pourcentage || 0, date: date };
			}
		}
		return choix;
	}

	function masquerBandeau() {
		if ( bandeau ) {
			bandeau.hidden = true;
		}
	}

	function proposerReprise( memo ) {
		if ( ! bandeau || ! memo ) {
			activerSuivi();
			return;
		}
		const numero = memo.paragraphe + 1;
		const texte = bandeau.querySelector( '[data-yn-reprise-texte]' );
		const bouton = bandeau.querySelector( '[data-yn-action="reprendre"]' );
		texte.textContent = '';
		texte.appendChild( document.createTextNode( 'Chapitre déjà commencé : ' ) );
		const fort = document.createElement( 'strong' );
		fort.textContent = memo.pourcentage + ' %';
		texte.appendChild( fort );
		texte.appendChild( document.createTextNode( ' lu.' ) );
		bouton.textContent = 'Reprendre au paragraphe ' + numero;
		bandeau.hidden = false;
		bandeau.addEventListener( 'click', function ( e ) {
			const action = e.target.closest ? e.target.closest( '[data-yn-action]' ) : null;
			if ( ! action ) {
				return;
			}
			const nom = action.getAttribute( 'data-yn-action' );
			if ( nom === 'reprendre' ) {
				masquerBandeau();
				allerAuParagraphe( memo.paragraphe, true );
				setTimeout( activerSuivi, reduit ? 50 : 900 );
				annoncer( 'Reprise au paragraphe ' + numero + '.' );
			} else if ( nom === 'ignorer-reprise' ) {
				masquerBandeau();
				activerSuivi();
				if ( boutonParametres ) {
					boutonParametres.focus();
				}
			}
		} );
		// Défilement volontaire du lecteur : il lit ailleurs, le suivi démarre.
		const positionDepart = window.pageYOffset;
		function surPremierDefilement() {
			if ( Math.abs( window.pageYOffset - positionDepart ) > window.innerHeight * 0.6 ) {
				window.removeEventListener( 'scroll', surPremierDefilement );
				masquerBandeau();
				activerSuivi();
			}
		}
		window.addEventListener( 'scroll', surPremierDefilement, { passive: true } );
	}

	function ancreParagraphe() {
		const m = /^#yn-p-(\d+)$/.exec( window.location.hash || '' );
		return m ? parseInt( m[ 1 ], 10 ) - 1 : -1;
	}

	function demarrerSuivi() {
		if ( ! suiviPossible ) {
			suivre( false );
			return;
		}
		suivre( false );
		const ancre = ancreParagraphe();
		if ( ancre >= 0 && paragraphes[ ancre ] ) {
			allerAuParagraphe( ancre, false );
			setTimeout( activerSuivi, 100 );
			return;
		}
		const memo = positionMemorisee();
		const ici = paragrapheCourant();
		if ( memo && memo.chapitre_id === config.chapitre && memo.paragraphe > 0 && memo.paragraphe < paragraphes.length && Math.abs( memo.paragraphe - ici ) > 1 ) {
			proposerReprise( memo );
			return;
		}
		activerSuivi();
	}

	if ( boutonMarque ) {
		boutonMarque.addEventListener( 'click', function () {
			if ( ! suiviPossible ) {
				return;
			}
			masquerBandeau();
			suiviActif = true;
			suivre( true );
			const numero = position.paragraphe + 1;
			const local = memoriserLocal( position );
			boutonMarque.setAttribute( 'data-yn-etat', 'enregistre' );
			setTimeout( function () {
				boutonMarque.removeAttribute( 'data-yn-etat' );
			}, 3000 );
			if ( config.connecte ) {
				derniereEnvoyee = null;
				envoyerServeur( false ).then( function ( ok ) {
					const message = ok ? 'Marque-page enregistré : paragraphe ' + numero + ' (' + position.pourcentage + ' %)' : 'Marque-page enregistré sur cet appareil (compte injoignable)';
					afficherToast( message );
					annoncer( message + '.' );
				} );
			} else {
				const message = local ? 'Marque-page enregistré sur cet appareil : paragraphe ' + numero : 'Impossible d’enregistrer le marque-page sur cet appareil';
				afficherToast( message );
				annoncer( message + '.' );
			}
		} );
	}

	window.addEventListener( 'hashchange', function () {
		const ancre = ancreParagraphe();
		if ( ancre >= 0 ) {
			allerAuParagraphe( ancre, true );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Raccourcis clavier                                                  */
	/* ------------------------------------------------------------------ */

	function estChampSaisie( el ) {
		if ( ! el || ! el.tagName ) {
			return false;
		}
		if ( el.isContentEditable ) {
			return true;
		}
		return /^(INPUT|TEXTAREA|SELECT)$/.test( el.tagName ) || !! ( el.closest && el.closest( '[contenteditable="true"], [role="textbox"], [role="combobox"], [role="slider"]' ) );
	}

	function lienVoisin( sens ) {
		const lien = document.querySelector( '.yn-chapter-nav a[rel~="' + sens + '"][href]' ) || document.querySelector( 'a[rel~="' + sens + '"][href]' );
		if ( lien ) {
			return lien.href;
		}
		return config[ sens ] || '';
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.isComposing ) {
			return;
		}
		if ( ( dialogue && dialogue.open ) || estChampSaisie( e.target ) ) {
			return;
		}
		if ( ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) && ! e.shiftKey ) {
			const url = lienVoisin( e.key === 'ArrowLeft' ? 'prev' : 'next' );
			if ( url ) {
				e.preventDefault();
				depart();
				window.location.href = url;
			}
			return;
		}
		if ( ( e.key === 's' || e.key === 'S' ) && dialogue ) {
			e.preventDefault();
			ouvrirPanneau();
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Démarrage                                                           */
	/* ------------------------------------------------------------------ */

	majBoutonTheme();
	if ( formulaire ) {
		remplirFormulaire( enVigueur );
	}
	appliquer( enVigueur );

	// Laisse au navigateur le temps de restaurer la position de défilement (retour arrière).
	if ( document.readyState === 'complete' ) {
		window.requestAnimationFrame( demarrerSuivi );
	} else {
		window.addEventListener(
			'load',
			function () {
				window.requestAnimationFrame( demarrerSuivi );
			},
			{ once: true }
		);
	}
}() );
