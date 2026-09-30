/**
 * WordEnd — moteur : rendu sur canvas (2×), caméra, décor, sprites, texte, particules.
 *
 * Coordonnées logiques (480 × 270). r.camera(monde) suit le joueur horizontalement (bornée au
 * niveau, lissée sauf en mouvement réduit) ; r.commencer(monde) pose la densité, la secousse et
 * la caméra (coordonnées du monde) ; r.finir() revient aux coordonnées de l'écran (HUD, textes).
 * Décor répété avec parallaxe et voile du niveau ; plateformes dessinées aux couleurs du thème.
 * Couleurs : jetons du thème (--wp--preset--color--*), relus à chaque changement de thème.
 * Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;
	var PLAFOND_PARTICULES = 300;

	/*
	 * Couleurs du HUD, indépendantes du thème : le HUD est dessiné sur le décor peint, identique
	 * dans les trois thèmes (ciel sombre). Texte clair à contour sombre, lisible partout (y
	 * compris sur le décor de secours clair du thème Papier). Valeurs de la palette Nuit.
	 */
	var HUD = {
		texte: '#fff8fb',
		faible: '#e4d9f0',
		accent: '#f3a6c8',
		contour: '#1b1231',
		vide: '#4a3b6e',
		danger: '#ff8f7e',
	};

	/* Particules : fonctions sans état, aussi exposées sur r. */
	function ajouterParticule( monde, x, y, vx, vy, vie, couleur, taille, flotte ) {
		if ( ! monde || monde.particules.length >= PLAFOND_PARTICULES ) {
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
		var courant = null; // Monde de l'image en cours (r.commencer) : voile du niveau, caméra.

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
			courant = monde || null;
			if ( monde && monde.secousse > 0 && ! monde.mouvementReduit ) {
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

		/* Arrondi au pixel de l'écran (densité 2) : pas de couture entre deux copies d'un décor. */
		function auPixel( v ) {
			return Math.round( v * DENSITE ) / DENSITE;
		}

		/*
		 * Décor, en coordonnées du monde (après r.commencer) : image 960 × 540 dessinée en
		 * 480 × 270 et répétée horizontalement en miroir (une copie sur deux retournée), qui défile à parallaxe × la vitesse de la caméra
		 * (0 : fixe à l'écran, 1 : solidaire du monde ; 0 en mouvement réduit). Sans image : ciel
		 * dégradé et sol aux couleurs du thème (secours.sol, 238 par défaut). Puis le voile du
		 * niveau (secours.voile, sinon monde.niveau.voile du monde passé à r.commencer) : couleur
		 * CSS posée sur tout l'écran (nuit, brume…).
		 */
		r.decor = function ( image, camera, parallaxe, secours ) {
			secours = secours || {};
			var cx = camera ? camera.x : 0;
			var reduit = r.mouvementReduit || !! ( courant && courant.mouvementReduit );
			var p = typeof parallaxe === 'number' && isFinite( parallaxe ) && ! reduit ? ynWE.limiter( parallaxe, 0, 1 ) : 0;
			var gauche = cx; // Bord gauche de l'écran, en coordonnées du monde.
			if ( image ) {
				// Copies n = …, 0, 1, … posées bout à bout dans l'espace du décor (décalé de cx × p) ;
				// les copies impaires sont retournées (miroir) : raccord sans couture visible.
				var defilement = cx * p;
				for ( var n = Math.floor( defilement / LARGEUR ); n * LARGEUR - defilement < LARGEUR; n++ ) {
					var x = auPixel( gauche + n * LARGEUR - defilement );
					if ( n % 2 ) {
						ctx.save();
						ctx.translate( x + LARGEUR, 0 );
						ctx.scale( -1, 1 );
						ctx.drawImage( image, 0, 0, LARGEUR, HAUTEUR );
						ctx.restore();
					} else {
						ctx.drawImage( image, x, 0, LARGEUR, HAUTEUR );
					}
				}
			} else {
				var sol = typeof secours.sol === 'number' ? secours.sol : 238;
				var ciel = ctx.createLinearGradient( 0, 0, 0, sol );
				ciel.addColorStop( 0, r.palette.fond );
				ciel.addColorStop( 1, r.palette.bande );
				ctx.fillStyle = ciel;
				ctx.fillRect( gauche, 0, LARGEUR, HAUTEUR );
				ctx.fillStyle = r.palette.carte;
				ctx.fillRect( gauche, sol, LARGEUR, HAUTEUR - sol );
				ctx.fillStyle = r.palette.filet;
				ctx.fillRect( gauche, sol, LARGEUR, 2 );
			}
			var voile = typeof secours.voile === 'string' ? secours.voile : ( courant && courant.niveau && courant.niveau.voile );
			if ( voile && typeof voile === 'string' ) {
				ctx.save();
				ctx.fillStyle = 'rgba(0,0,0,0)';
				ctx.fillStyle = voile; // Couleur invalide : ignorée par le canvas (reste transparente).
				ctx.fillRect( gauche - 4, -4, LARGEUR + 8, HAUTEUR + 8 );
				ctx.restore();
			}
		};

		/* Rectangle aux coins arrondis (chemin seulement). */
		function rectangleArrondi( x, y, l, h, rayon ) {
			var rr = Math.max( 0, Math.min( rayon, l / 2, h / 2 ) );
			ctx.beginPath();
			ctx.moveTo( x + rr, y );
			ctx.lineTo( x + l - rr, y );
			ctx.quadraticCurveTo( x + l, y, x + l, y + rr );
			ctx.lineTo( x + l, y + h - rr );
			ctx.quadraticCurveTo( x + l, y + h, x + l - rr, y + h );
			ctx.lineTo( x + rr, y + h );
			ctx.quadraticCurveTo( x, y + h, x, y + h - rr );
			ctx.lineTo( x, y + rr );
			ctx.quadraticCurveTo( x, y, x + rr, y );
			ctx.closePath();
		}

		/*
		 * Plateformes (en attendant des tuiles) : dalles aux couleurs du thème, en coordonnées du
		 * monde. Traversable (défaut) : dalle de 6 px, coins arrondis, fond carte, contour et
		 * liseré supérieur filet/accent, ombre portée légère. « solide » : bloc plein jusqu'au sol
		 * (ou h px si le JSON le donne), mêmes couleurs, rainures de pierre. Seules les plateformes
		 * visibles (caméra) sont dessinées.
		 */
		r.plateformes = function ( monde ) {
			if ( ! monde || ! monde.plateformes || ! monde.plateformes.length ) {
				return;
			}
			var pal = r.palette;
			var gauche = monde.camera ? monde.camera.x : 0;
			var sol = typeof monde.sol === 'number' ? monde.sol : 238;
			ctx.save();
			monde.plateformes.forEach( function ( p ) {
				if ( ! p || typeof p.x !== 'number' || typeof p.y !== 'number' || ! ( p.l > 0 ) ) {
					return;
				}
				if ( p.x > gauche + LARGEUR + 8 || p.x + p.l < gauche - 8 ) {
					return;
				}
				var solide = p.type === 'solide';
				var h = typeof p.h === 'number' && p.h > 0 ? p.h : ( solide ? Math.max( 6, sol - p.y ) : 6 );
				// Ombre portée (décalée vers le bas).
				ctx.globalAlpha = 0.28;
				ctx.fillStyle = '#000';
				rectangleArrondi( p.x + 1, p.y + 2, p.l, h, 3 );
				ctx.fill();
				ctx.globalAlpha = 1;
				// Corps de la dalle.
				ctx.fillStyle = pal.carte;
				rectangleArrondi( p.x, p.y, p.l, h, 3 );
				ctx.fill();
				ctx.lineWidth = 1;
				ctx.strokeStyle = pal.filet;
				rectangleArrondi( p.x + 0.5, p.y + 0.5, p.l - 1, h - 1, 2.5 );
				ctx.stroke();
				if ( solide ) {
					// Rainures horizontales et joints décalés : bloc de pierre.
					ctx.globalAlpha = 0.5;
					ctx.fillStyle = pal.filet;
					for ( var ry = p.y + 8, rang = 0; ry < p.y + h - 2; ry += 8, rang++ ) {
						ctx.fillRect( p.x + 2, ry, p.l - 4, 1 );
						for ( var rx = p.x + ( rang % 2 ? 10 : 20 ); rx < p.x + p.l - 4; rx += 20 ) {
							ctx.fillRect( rx, ry - 7, 1, 7 );
						}
					}
					ctx.globalAlpha = 1;
				}
				// Liseré supérieur : surface où l'on se pose.
				ctx.fillStyle = pal.accent;
				ctx.globalAlpha = 0.85;
				ctx.fillRect( p.x + 2, p.y, p.l - 4, 1 );
				ctx.globalAlpha = 0.35;
				ctx.fillStyle = pal.texteFort;
				ctx.fillRect( p.x + 3, p.y + 1, p.l - 6, 1 );
				ctx.globalAlpha = 1;
			} );
			ctx.restore();
		};

		/*
		 * Sprite d'une planche {image, meta} : image i de l'animation nom, ancre en (x, y),
		 * retourné si dir < 0. echelleSup : taille relative (1 par défaut). lissage : false pour
		 * le pixel art. alpha : opacité. flash (0…1) : éclat blanc en mode « lighter ».
		 */
		r.sprite = function ( planche, nom, i, x, y, dir, echelleSup, lissage, alpha, flash ) {
			var anim = planche && planche.image ? ynWE.animation( planche.meta, nom ) : null;
			var cadre = anim && anim.images ? anim.images[ i ] : null;
			if ( ! cadre ) {
				return;
			}
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

		/* Texte du HUD (sur le décor) : couleurs HUD, contour sombre, indépendant du thème. */
		r.texteHud = function ( contenu, x, y, taille, alignement, couleur, graisse ) {
			ctx.font = ( graisse || 700 ) + ' ' + taille + 'px ' + r.police;
			ctx.textAlign = alignement || 'left';
			ctx.textBaseline = 'middle';
			ctx.lineJoin = 'round';
			ctx.lineWidth = 3.5;
			ctx.strokeStyle = HUD.contour;
			ctx.strokeText( contenu, x, y );
			ctx.fillStyle = couleur || HUD.texte;
			ctx.fillText( contenu, x, y );
		};

		r.voile = function () {
			ctx.globalAlpha = 0.72;
			ctx.fillStyle = r.palette.fond;
			ctx.fillRect( 0, 0, LARGEUR, HAUTEUR );
			ctx.globalAlpha = 1;
		};

		/*
		 * Cœur du HUD (pointe en (x, y + 9 × echelle)) : plein rose, vide sombre, toujours cerclé
		 * d'un contour sombre (couleurs HUD, lisibles sur le décor dans les trois thèmes).
		 */
		r.coeur = function ( x, y, plein, echelle ) {
			var e = echelle || 1;
			ctx.save();
			ctx.translate( x, y );
			ctx.scale( e, e );
			ctx.beginPath();
			ctx.moveTo( 0, 3 );
			ctx.bezierCurveTo( 0, 0, -5, -1, -5, 2 );
			ctx.bezierCurveTo( -5, 5, -1, 7, 0, 9 );
			ctx.bezierCurveTo( 1, 7, 5, 5, 5, 2 );
			ctx.bezierCurveTo( 5, -1, 0, 0, 0, 3 );
			ctx.closePath();
			ctx.lineJoin = 'round';
			ctx.lineWidth = 2.4 / e;
			ctx.strokeStyle = HUD.contour;
			ctx.stroke();
			ctx.fillStyle = plein ? HUD.accent : HUD.vide;
			ctx.fill();
			ctx.restore();
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
		HUD: HUD,
		creer: creer,
		ajouterParticule: ajouterParticule,
		mettreAJourParticules: mettreAJourParticules,
	};
}() );
