/**
 * Banc d'essai de WordEnd (outil de développement, jamais livré).
 *
 * Trois modes selon l'adresse :
 * - jeu (défaut) : pose window.ynWordEnd comme le ferait WordPress (feuille, scripts du moteur
 *   dans l'ordre de SCRIPTS_MOTEUR, univers), charge declencheur.js et ouvre le jeu (bouton, ou
 *   &auto=1). Paramètres : univers, personnage, niveau (config.depart).
 * - visionneuse (?planche=personnages/chtholly) : animations d'une planche, lecture ou pas à
 *   pas, cadre, ancre et boîte de jeu dessinés.
 * - aperçu de niveau (?apercu=niveaux/arcade) : décor, sol, plateformes, apparition.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ASSETS = '../../../wp-content/plugins/yume-core/includes/wordend/assets/';
	// Même ordre que SCRIPTS_MOTEUR (includes/wordend/fonctions.php).
	var SCRIPTS = [ '00-espace', 'stockage', 'ressources', 'audio', 'rendu', 'physique', 'competences', 'joueur', 'ennemis', 'niveau', 'entrees', 'ecrans', 'modale', 'jeu' ];
	var VERSION = 'banc-' + Date.now(); // Pas de cache pendant le développement.

	var params = new URLSearchParams( window.location.search );
	var universSlug = ( params.get( 'univers' ) || 'sukasuka' ).replace( /[^a-z0-9-]/g, '' );
	var dossierUnivers = ASSETS + 'univers/' + universSlug + '/';
	var racine = document.getElementById( 'banc' );
	var etat = document.getElementById( 'banc-etat' );

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

	function json( chemin ) {
		return window.fetch( dossierUnivers + chemin + '?ver=' + VERSION ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'introuvable : ' + chemin );
			}
			return r.json();
		} );
	}

	function image( chemin ) {
		return new Promise( function ( resoudre, rejeter ) {
			var img = new Image();
			img.onload = function () {
				resoudre( img );
			};
			img.onerror = function () {
				rejeter( new Error( 'introuvable : ' + chemin ) );
			};
			img.src = dossierUnivers + chemin + '?ver=' + VERSION;
		} );
	}

	function slugDe( chemin ) {
		return chemin.slice( chemin.lastIndexOf( '/' ) + 1 ).replace( /\.json$/, '' );
	}

	function afficherErreur( erreur ) {
		etat.textContent = 'Erreur : ' + erreur.message;
	}

	/* ------------------------------------------------------------------ */
	/* Mode jeu                                                            */
	/* ------------------------------------------------------------------ */

	function modeJeu() {
		window.ynWordEnd = {
			version: VERSION,
			style: ASSETS + 'jeu.css?ver=' + VERSION,
			scripts: SCRIPTS.map( function ( nom ) {
				return ASSETS + 'moteur/' + nom + '.js?ver=' + VERSION;
			} ),
			univers: {},
			universParDefaut: universSlug,
			universPage: '',
			depart: {
				personnage: params.get( 'personnage' ) || '',
				niveau: params.get( 'niveau' ) || '',
			},
		};
		window.ynWordEnd.univers[ universSlug ] = {
			titre: universSlug,
			oeuvre: universSlug,
			manifeste: dossierUnivers + 'manifeste.json',
			version: VERSION,
		};

		var barre = element( 'form', { class: 'banc__barre', method: 'get' } );
		barre.appendChild( element( 'input', { type: 'hidden', name: 'univers', value: universSlug } ) );
		var choixPersonnage = element( 'select', { name: 'personnage' } );
		var choixNiveau = element( 'select', { name: 'niveau' } );
		var etiquetteP = element( 'label', {}, 'Personnage' );
		etiquetteP.appendChild( choixPersonnage );
		var etiquetteN = element( 'label', {}, 'Niveau' );
		etiquetteN.appendChild( choixNiveau );
		barre.appendChild( etiquetteP );
		barre.appendChild( etiquetteN );
		barre.appendChild( element( 'button', { type: 'submit', class: 'yn-btn yn-btn--sm' }, 'Appliquer' ) );
		var ouvrir = element( 'button', { type: 'button', class: 'yn-btn yn-btn--primary yn-btn--sm', id: 'banc-ouvrir' }, 'Ouvrir le jeu' );
		barre.appendChild( ouvrir );
		racine.appendChild( barre );

		json( 'manifeste.json' ).then( function ( manifeste ) {
			( manifeste.personnages || [] ).forEach( function ( chemin ) {
				var slug = slugDe( chemin );
				var option = element( 'option', { value: slug }, slug );
				option.selected = slug === window.ynWordEnd.depart.personnage;
				choixPersonnage.appendChild( option );
			} );
			( manifeste.niveaux || [] ).forEach( function ( chemin ) {
				var slug = slugDe( chemin );
				var option = element( 'option', { value: slug }, slug );
				option.selected = slug === window.ynWordEnd.depart.niveau;
				choixNiveau.appendChild( option );
			} );
		} ).catch( afficherErreur );

		var script = element( 'script', { src: ASSETS + 'declencheur.js?ver=' + VERSION } );
		script.onload = function () {
			ouvrir.addEventListener( 'click', function () {
				window.ynWordEnd.ouvrir( universSlug );
			} );
			if ( params.get( 'auto' ) === '1' ) {
				window.ynWordEnd.ouvrir( universSlug );
			}
		};
		document.body.appendChild( script );

		// État du moteur (débogage).
		window.setInterval( function () {
			var moteur = window.ynWordEndMoteur;
			if ( ! moteur || ! moteur.debug ) {
				etat.textContent = 'Moteur non chargé (bouton « Ouvrir le jeu »).';
				return;
			}
			var monde = moteur.debug.monde();
			etat.textContent = 'état : ' + moteur.debug.etat() + ' · ips : ' + moteur.debug.ips +
				( monde ? ' · niveau : ' + monde.niveau.slug + ' · personnage : ' + monde.personnage.slug + ' · score : ' + monde.score +
					' · pv : ' + monde.joueur.pv + ' · joueur : ' + monde.joueur.etat + ' x=' + Math.round( monde.joueur.x ) +
					' · ennemis : ' + monde.ennemis.length + ' · particules : ' + monde.particules.length + ' · caméra : ' + Math.round( monde.camera.x ) : '' );
		}, 250 );
	}

	/* ------------------------------------------------------------------ */
	/* Visionneuse de planche                                              */
	/* ------------------------------------------------------------------ */

	function modePlanche( chemin ) {
		chemin = chemin.replace( /\.(json|png)$/, '' ).replace( /\.planche$/, '' ).replace( /\.\./g, '' );
		var dossier = chemin.slice( 0, chemin.lastIndexOf( '/' ) + 1 );
		// Le JSON d'entité (boîte, lissage) est facultatif : la planche peut porter un autre nom.
		var entite = json( chemin + '.json' ).catch( function () {
			return {};
		} );
		entite.then( function ( def ) {
			var nom = def.planche || chemin.slice( dossier.length );
			return Promise.all( [ image( dossier + nom + '.png' ), json( dossier + nom + '.planche.json' ), def ] );
		} ).then( function ( res ) {
			visionneuse( chemin, res[ 0 ], res[ 1 ], res[ 2 ] );
		} ).catch( afficherErreur );
	}

	function visionneuse( chemin, img, meta, def ) {
		var ZOOM = 3;
		var barre = element( 'div', { class: 'banc__barre' } );
		var choix = element( 'select', { 'aria-label': 'Animation' } );
		Object.keys( meta.animations ).forEach( function ( nom ) {
			choix.appendChild( element( 'option', { value: nom }, nom + ' (' + meta.animations[ nom ].images.length + ')' ) );
		} );
		var etiquette = element( 'label', {}, 'Animation' );
		etiquette.appendChild( choix );
		barre.appendChild( etiquette );
		var lecture = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm', 'aria-pressed': 'true' }, 'Lecture' );
		var precedente = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm' }, '◀ Image' );
		var suivante = element( 'button', { type: 'button', class: 'yn-btn yn-btn--sm' }, 'Image ▶' );
		var vitesse = element( 'input', { type: 'number', min: '0.1', max: '4', step: '0.1', value: '1' } );
		var etiquetteV = element( 'label', {}, 'Vitesse' );
		etiquetteV.appendChild( vitesse );
		var taille = element( 'input', { type: 'number', min: '0.2', max: '3', step: '0.1', value: '1' } );
		var etiquetteT = element( 'label', {}, 'Taille (ennemi)' );
		etiquetteT.appendChild( taille );
		[ lecture, precedente, suivante, etiquetteV, etiquetteT ].forEach( function ( el ) {
			barre.appendChild( el );
		} );
		racine.appendChild( element( 'h2', {}, chemin ) );
		racine.appendChild( barre );
		var toile = element( 'canvas', { class: 'banc__vue', width: '960', height: '600', tabindex: '0', role: 'img', 'aria-label': 'Aperçu de la planche ' + chemin } );
		racine.appendChild( toile );
		var ctx = toile.getContext( '2d' );

		var enLecture = true;
		var indice = 0;
		var t = 0;
		var avant = 0;

		function anim() {
			return meta.animations[ choix.value ];
		}

		lecture.addEventListener( 'click', function () {
			enLecture = ! enLecture;
			lecture.setAttribute( 'aria-pressed', enLecture ? 'true' : 'false' );
			lecture.textContent = enLecture ? 'Lecture' : 'Arrêt';
		} );
		precedente.addEventListener( 'click', function () {
			enLecture = false;
			indice = ( indice - 1 + anim().images.length ) % anim().images.length;
		} );
		suivante.addEventListener( 'click', function () {
			enLecture = false;
			indice = ( indice + 1 ) % anim().images.length;
		} );
		choix.addEventListener( 'change', function () {
			indice = 0;
			t = 0;
		} );

		function dessiner( maintenant ) {
			var dt = avant ? ( maintenant - avant ) / 1000 : 0;
			avant = maintenant;
			var a = anim();
			if ( enLecture ) {
				t += dt * ( parseFloat( vitesse.value ) || 1 );
				var i = Math.floor( t * a.ips );
				indice = a.boucle ? i % a.images.length : Math.min( a.images.length - 1, i );
				if ( ! a.boucle && i > a.images.length + a.ips ) {
					t = 0; // Rejoue les animations sans boucle après une pause.
				}
			}
			var cadre = a.images[ indice ];
			var echelle = ( meta.echelle || 2 ) / ( parseFloat( taille.value ) || 1 );
			var solX = 480;
			var solY = 460;
			ctx.setTransform( 1, 0, 0, 1, 0, 0 );
			ctx.fillStyle = '#0d0818';
			ctx.fillRect( 0, 0, toile.width, toile.height );
			// Sol.
			ctx.strokeStyle = '#4a3b6e';
			ctx.beginPath();
			ctx.moveTo( 0, solY + 0.5 );
			ctx.lineTo( toile.width, solY + 0.5 );
			ctx.stroke();
			ctx.imageSmoothingEnabled = def.lissage !== false;
			var z = ZOOM / echelle;
			var x = solX - cadre[ 4 ] * z;
			var y = solY - cadre[ 5 ] * z;
			ctx.drawImage( img, cadre[ 0 ], cadre[ 1 ], cadre[ 2 ], cadre[ 3 ], x, y, cadre[ 2 ] * z, cadre[ 3 ] * z );
			// Cadre de l'image.
			ctx.strokeStyle = 'rgba(247,197,159,0.6)';
			ctx.strokeRect( x + 0.5, y + 0.5, cadre[ 2 ] * z, cadre[ 3 ] * z );
			// Boîte de jeu (px logiques × zoom), ancrée au sol et centrée.
			if ( def.boite ) {
				var l = def.boite.l * ZOOM * ( parseFloat( taille.value ) || 1 );
				var h = def.boite.h * ZOOM * ( parseFloat( taille.value ) || 1 );
				ctx.strokeStyle = '#8fd6a3';
				ctx.strokeRect( solX - l / 2 + 0.5, solY - h + 0.5, l, h );
			}
			// Ancre.
			ctx.strokeStyle = '#ff8f7e';
			ctx.beginPath();
			ctx.moveTo( solX - 8, solY );
			ctx.lineTo( solX + 8, solY );
			ctx.moveTo( solX, solY - 8 );
			ctx.lineTo( solX, solY + 8 );
			ctx.stroke();
			var coup = ( a.coup || [] ).indexOf( indice ) !== -1;
			ctx.fillStyle = '#ebe3f2';
			ctx.font = '14px ui-monospace, monospace';
			ctx.fillText( choix.value + ' ' + ( indice + 1 ) + '/' + a.images.length + ' · ' + a.ips + ' ips · ' + ( a.boucle ? 'boucle' : 'une fois' ) + ( coup ? ' · COUP' : '' ) + ( a.onde === indice ? ' · ONDE' : '' ), 12, 22 );
			ctx.fillText( 'cadre [' + cadre.join( ', ' ) + '] · échelle ' + ( meta.echelle || 2 ) + ' · planche ' + meta.planche.join( '×' ), 12, 42 );
			ctx.fillText( 'rouge : ancre · orange : cadre · vert : boîte de jeu (JSON de l’entité)', 12, 590 );
			etat.textContent = 'visionneuse : ' + chemin + ' · ' + choix.value + ' image ' + indice;
			window.requestAnimationFrame( dessiner );
		}
		window.requestAnimationFrame( dessiner );
	}

	/* ------------------------------------------------------------------ */
	/* Aperçu de niveau                                                    */
	/* ------------------------------------------------------------------ */

	function modeApercu( chemin ) {
		chemin = chemin.replace( /\.json$/, '' ).replace( /\.\./g, '' );
		Promise.all( [ json( 'manifeste.json' ), json( chemin + '.json' ) ] ).then( function ( res ) {
			var manifeste = res[ 0 ];
			var niveau = res[ 1 ];
			var decor = ( manifeste.decors || {} )[ niveau.decor ];
			return ( decor ? image( decor.image ).catch( function () {
				return null;
			} ) : Promise.resolve( null ) ).then( function ( img ) {
				apercu( chemin, niveau, img );
			} );
		} ).catch( afficherErreur );
	}

	function apercu( chemin, niveau, img ) {
		var largeur = niveau.largeur || 480;
		var sol = niveau.sol || 238;
		var echelle = Math.min( 2, 1400 / largeur );
		racine.appendChild( element( 'h2', {}, chemin + ' — ' + ( niveau.titre || '' ) ) );
		var toile = element( 'canvas', { class: 'banc__vue', width: String( Math.round( largeur * echelle ) ), height: String( 270 * echelle ), role: 'img', 'aria-label': 'Aperçu du niveau ' + chemin } );
		racine.appendChild( toile );
		var ctx = toile.getContext( '2d' );
		ctx.scale( echelle, echelle );
		for ( var x = 0; x < largeur; x += 480 ) {
			if ( img ) {
				ctx.drawImage( img, x, 0, 480, 270 );
			} else {
				ctx.fillStyle = '#241740';
				ctx.fillRect( x, 0, 480, 270 );
			}
			ctx.strokeStyle = 'rgba(255,255,255,0.35)';
			ctx.setLineDash( [ 4, 4 ] );
			ctx.strokeRect( x + 0.5, 0.5, 480, 269 );
			ctx.setLineDash( [] );
		}
		ctx.fillStyle = 'rgba(143,214,163,0.9)';
		ctx.fillRect( 0, sol, largeur, 2 );
		( niveau.plateformes || [] ).forEach( function ( p ) {
			ctx.fillStyle = p.type === 'solide' ? 'rgba(255,143,126,0.9)' : 'rgba(247,197,159,0.9)';
			ctx.fillRect( p.x, p.y, p.l, 6 );
		} );
		if ( niveau.apparition ) {
			ctx.fillStyle = '#f3a6c8';
			ctx.beginPath();
			ctx.arc( niveau.apparition.x, typeof niveau.apparition.y === 'number' ? niveau.apparition.y : sol, 5, 0, Math.PI * 2 );
			ctx.fill();
		}
		var liste = element( 'ul', { class: 'banc__liste' } );
		[ 'objectif : ' + ( niveau.objectif && niveau.objectif.type ), 'largeur : ' + largeur, 'sol : ' + sol, 'gravité : ' + niveau.gravite, 'plateformes : ' + ( niveau.plateformes || [] ).length, 'suivant : ' + ( niveau.suivant || '—' ) ].forEach( function ( texte ) {
			liste.appendChild( element( 'li', {}, texte ) );
		} );
		racine.appendChild( liste );
		etat.textContent = 'aperçu : ' + chemin + ' (vert : sol · orange : plateformes traversables · rouge : solides · rose : apparition · pointillés : écrans)';
	}

	if ( params.get( 'planche' ) ) {
		modePlanche( params.get( 'planche' ) );
	} else if ( params.get( 'apercu' ) ) {
		modeApercu( params.get( 'apercu' ) );
	} else {
		modeJeu();
	}
}() );
