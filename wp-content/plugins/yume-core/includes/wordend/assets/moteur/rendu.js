/**
 * WordEnd — moteur : rendu sur canvas (2×), caméra, décor, sprites, texte, particules.
 *
 * Coordonnées logiques (480 × 270). r.commencer(monde) pose la densité, la secousse et la
 * caméra (coordonnées du monde) ; r.finir() revient aux coordonnées de l'écran (HUD, textes).
 * Couleurs : jetons du thème (--wp--preset--color--*), relus à chaque changement de thème.
 * Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var PLAFOND_PARTICULES = 300;

	/* Particules : fonctions sans état, aussi exposées sur r. */
	function ajouterParticule( monde, x, y, vx, vy, vie, couleur, taille, flotte ) {
		if ( ! monde || monde.particules.length > PLAFOND_PARTICULES ) {
			return;
		}
		monde.particules.push( { x: x, y: y, vx: vx, vy: vy, vie: vie, max: vie, couleur: couleur, taille: taille, flotte: !! flotte } );
	}

	function mettreAJourParticules( monde, dt ) {
		if ( ! monde ) {
			return;
		}
		monde.particules.forEach( function ( p ) {
			p.vie -= dt;
			p.x += p.vx * dt;
			p.y += p.vy * dt;
			p.vy += ( p.flotte ? 8 : 260 ) * dt;
		} );
		monde.particules = monde.particules.filter( function ( p ) {
			return p.vie > 0;
		} );
	}

	function creer( canvas ) {
		var ctx = canvas.getContext( '2d' );
		var LARGEUR = ynWE.LARGEUR;
		var HAUTEUR = ynWE.HAUTEUR;
		var DENSITE = ynWE.DENSITE;
		var decalage = { x: 0, y: 0 };

		var r = {
			canvas: canvas,
			ctx: ctx,
			palette: {},
			police: 'sans-serif',
			mouvementReduit: false,
		};

		r.lirePalette = function () {
			var style = window.getComputedStyle( document.documentElement );
			function jeton( nom, secours ) {
				var valeur = style.getPropertyValue( '--wp--preset--color--' + nom ).trim();
				return valeur || secours;
			}
			r.palette = {
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
			r.police = titres || window.getComputedStyle( document.body ).fontFamily || 'sans-serif';
			r.mouvementReduit = document.documentElement.getAttribute( 'data-yn-animations' ) === 'reduites' ||
				!! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
			return r.palette;
		};

		/* Caméra horizontale : suit le joueur, bornée au niveau ; sans lissage en mouvement réduit. */
		r.camera = function ( monde ) {
			if ( ! monde ) {
				return;
			}
			var max = Math.max( 0, monde.largeur - LARGEUR );
			var cible = monde.joueur ? ynWE.limiter( monde.joueur.x - LARGEUR / 2, 0, max ) : 0;
			var lissage = monde.mouvementReduit || r.mouvementReduit ? 1 : 0.15;
			monde.camera.x += ( cible - monde.camera.x ) * lissage;
			if ( Math.abs( cible - monde.camera.x ) < 0.01 ) {
				monde.camera.x = cible;
			}
		};

		r.commencer = function ( monde ) {
			ctx.setTransform( DENSITE, 0, 0, DENSITE, 0, 0 );
			ctx.imageSmoothingEnabled = true;
			ctx.globalAlpha = 1;
			ctx.globalCompositeOperation = 'source-over';
			decalage = { x: 0, y: 0 };
			if ( monde && monde.secousse > 0 ) {
				decalage = { x: ynWE.hasard( -2, 2 ), y: ynWE.hasard( -2, 2 ) };
			}
			ctx.translate( decalage.x, decalage.y );
			if ( monde ) {
				ctx.translate( -Math.round( monde.camera.x * DENSITE ) / DENSITE, 0 );
			}
		};

		/* Retour aux coordonnées de l'écran (la secousse s'applique aussi au HUD, comme en v1). */
		r.finir = function () {
			ctx.setTransform( DENSITE, 0, 0, DENSITE, 0, 0 );
			ctx.globalAlpha = 1;
			ctx.translate( decalage.x, decalage.y );
		};

		/*
		 * Décor : image 960 × 540 dessinée sur l'écran, décalée de camera.x × parallaxe (0 : fixe,
		 * 1 : suit le monde). secours = {sol} pour le ciel dégradé quand l'image manque.
		 */
		r.decor = function ( image, camera, parallaxe, secours ) {
			var cx = camera ? camera.x : 0;
			var p = typeof parallaxe === 'number' ? parallaxe : 0;
			var gauche = cx; // Bord gauche de l'écran, en coordonnées du monde.
			if ( image ) {
				var depart = gauche - ( ( cx * p ) % LARGEUR );
				for ( var x = depart; x < gauche + LARGEUR; x += LARGEUR ) {
					ctx.drawImage( image, x, 0, LARGEUR, HAUTEUR );
				}
				return;
			}
			var sol = secours && typeof secours.sol === 'number' ? secours.sol : 238;
			var ciel = ctx.createLinearGradient( 0, 0, 0, sol );
			ciel.addColorStop( 0, r.palette.fond );
			ciel.addColorStop( 1, r.palette.bande );
			ctx.fillStyle = ciel;
			ctx.fillRect( gauche, 0, LARGEUR, HAUTEUR );
			ctx.fillStyle = r.palette.carte;
			ctx.fillRect( gauche, sol, LARGEUR, HAUTEUR - sol );
			ctx.fillStyle = r.palette.filet;
			ctx.fillRect( gauche, sol, LARGEUR, 2 );
		};

		/* Plateformes : dessin simple aux couleurs du thème (lot A : tuiles, coins arrondis). */
		r.plateformes = function ( monde ) {
			( monde && monde.plateformes || [] ).forEach( function ( p ) {
				ctx.fillStyle = r.palette.carte;
				ctx.fillRect( p.x, p.y, p.l, 6 );
				ctx.fillStyle = r.palette.filet;
				ctx.fillRect( p.x, p.y, p.l, 2 );
			} );
		};

		/*
		 * Sprite d'une planche {image, meta} : image i de l'animation nom, ancre en (x, y),
		 * retourné si dir < 0. echelleSup : taille relative (1 par défaut). lissage : false pour
		 * le pixel art. alpha : opacité. flash (0…1) : éclat blanc en mode « lighter ».
		 */
		r.sprite = function ( planche, nom, i, x, y, dir, echelleSup, lissage, alpha, flash ) {
			var anim = ynWE.animation( planche.meta, nom );
			if ( ! anim ) {
				return;
			}
			var cadre = anim.images[ i ];
			var echelle = ( planche.meta.echelle || DENSITE ) / ( echelleSup || 1 );
			ctx.save();
			if ( typeof alpha === 'number' ) {
				ctx.globalAlpha = alpha;
			}
			ctx.translate( Math.round( x * DENSITE ) / DENSITE, Math.round( y * DENSITE ) / DENSITE );
			if ( dir < 0 ) {
				ctx.scale( -1, 1 );
			}
			if ( lissage === false ) {
				ctx.imageSmoothingEnabled = false;
			}
			ctx.drawImage( planche.image, cadre[ 0 ], cadre[ 1 ], cadre[ 2 ], cadre[ 3 ], -cadre[ 4 ] / echelle, -cadre[ 5 ] / echelle, cadre[ 2 ] / echelle, cadre[ 3 ] / echelle );
			if ( flash > 0 ) {
				ctx.globalCompositeOperation = 'lighter';
				ctx.globalAlpha = 0.7 * Math.min( 1, flash );
				ctx.drawImage( planche.image, cadre[ 0 ], cadre[ 1 ], cadre[ 2 ], cadre[ 3 ], -cadre[ 4 ] / echelle, -cadre[ 5 ] / echelle, cadre[ 2 ] / echelle, cadre[ 3 ] / echelle );
			}
			ctx.restore();
		};

		/* Ombre elliptique au sol. */
		r.ombre = function ( x, y, rayonX, rayonY, opacite ) {
			ctx.fillStyle = 'rgba(0,0,0,' + ( typeof opacite === 'number' ? opacite : 0.25 ) + ')';
			ctx.beginPath();
			ctx.ellipse( x, y, rayonX, rayonY, 0, 0, Math.PI * 2 );
			ctx.fill();
		};

		/* Jauge horizontale (fond filet, remplissage couleur). */
		r.jauge = function ( x, y, l, h, part, couleur ) {
			ctx.fillStyle = r.palette.filet;
			ctx.fillRect( x, y, l, h );
			ctx.fillStyle = couleur || r.palette.accent;
			ctx.fillRect( x, y, l * ynWE.limiter( part, 0, 1 ), h );
		};

		r.texte = function ( contenu, x, y, taille, alignement, couleur, graisse ) {
			ctx.font = ( graisse || 700 ) + ' ' + taille + 'px ' + r.police;
			ctx.textAlign = alignement || 'left';
			ctx.textBaseline = 'middle';
			ctx.lineJoin = 'round';
			ctx.lineWidth = 3;
			ctx.strokeStyle = r.palette.fond;
			ctx.strokeText( contenu, x, y );
			ctx.fillStyle = couleur || r.palette.texteFort;
			ctx.fillText( contenu, x, y );
		};

		r.voile = function () {
			ctx.globalAlpha = 0.72;
			ctx.fillStyle = r.palette.fond;
			ctx.fillRect( 0, 0, LARGEUR, HAUTEUR );
			ctx.globalAlpha = 1;
		};

		r.coeur = function ( x, y, plein ) {
			ctx.fillStyle = plein ? r.palette.accent : r.palette.filet;
			ctx.beginPath();
			ctx.moveTo( x, y + 3 );
			ctx.bezierCurveTo( x, y, x - 5, y - 1, x - 5, y + 2 );
			ctx.bezierCurveTo( x - 5, y + 5, x - 1, y + 7, x, y + 9 );
			ctx.bezierCurveTo( x + 1, y + 7, x + 5, y + 5, x + 5, y + 2 );
			ctx.bezierCurveTo( x + 5, y - 1, x, y, x, y + 3 );
			ctx.fill();
		};

		r.particules = function ( monde ) {
			( monde && monde.particules || [] ).forEach( function ( p ) {
				ctx.globalAlpha = Math.max( 0, p.vie / p.max );
				ctx.fillStyle = p.couleur;
				ctx.fillRect( p.x - p.taille / 2, p.y - p.taille / 2, p.taille, p.taille );
			} );
			ctx.globalAlpha = 1;
		};

		r.ajouterParticule = ajouterParticule;
		r.mettreAJourParticules = mettreAJourParticules;
		r.lirePalette();
		return r;
	}

	ynWE.rendu = {
		creer: creer,
		ajouterParticule: ajouterParticule,
		mettreAJourParticules: mettreAJourParticules,
	};
}() );
