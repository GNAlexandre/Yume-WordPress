/**
 * WordEnd — moteur : son (musique de fond en boucle, volume, muet ; effets en v2.2).
 *
 * audio.musique(url) choisit la piste et la joue (sauf muet ou volume nul) ; une autre URL (la
 * musique d'un autre niveau) remplace la piste et libère l'ancienne ; musique(null) la met en
 * pause (position conservée). L'élément Audio n'est créé qu'à la première lecture effective. Les réglages sont mémorisés (stockage.enregistrerSon) et annoncés par l'événement
 * 'son:changement'. Interface : docs/wordend-formats.md.
 *
 * ES2019, sans dépendance.
 */
( function () {
	'use strict';

	var ynWE = window.ynWordEndMoteur;

	function creer() {
		var son = ynWE.stockage ? ynWE.stockage.reglagesSon() : { volume: 0.5, muet: false };
		var piste = null;
		var url = '';
		var voulue = false;

		function appliquer() {
			var jouer = voulue && !! url && ! son.muet && son.volume > 0;
			if ( jouer && ( ! piste || piste.dataset.url !== url ) ) {
				if ( piste ) {
					// Changement de piste (musique d'un autre niveau) : l'ancienne est libérée.
					piste.pause();
					piste.removeAttribute( 'src' );
					try {
						piste.load();
					} catch ( e ) {}
				}
				piste = new Audio( url );
				piste.dataset.url = url;
				piste.loop = true;
				piste.preload = 'auto';
			}
			if ( ! piste ) {
				return;
			}
			piste.volume = son.volume;
			if ( jouer && piste.paused ) {
				var promesse = piste.play();
				if ( promesse && promesse.catch ) {
					promesse.catch( function () {} ); // Lecture refusée par le navigateur : silence.
				}
			} else if ( ! jouer && ! piste.paused ) {
				piste.pause();
			}
		}

		return {
			reglages: function () {
				return { volume: son.volume, muet: son.muet };
			},
			regler: function ( reglages ) {
				if ( typeof reglages.volume === 'number' && isFinite( reglages.volume ) ) {
					son.volume = Math.min( 1, Math.max( 0, reglages.volume ) );
				}
				if ( typeof reglages.muet === 'boolean' ) {
					son.muet = reglages.muet;
				}
				if ( ynWE.stockage ) {
					ynWE.stockage.enregistrerSon( son );
				}
				appliquer();
				ynWE.evenements.emettre( 'son:changement', { volume: son.volume, muet: son.muet } );
			},
			/* Relit les réglages mémorisés (ouverture de la modale). */
			relire: function () {
				if ( ynWE.stockage ) {
					son = ynWE.stockage.reglagesSon();
				}
				appliquer();
			},
			musique: function ( adresse ) {
				if ( adresse ) {
					url = String( adresse );
					voulue = true;
				} else {
					voulue = false;
				}
				appliquer();
			},
			pauser: function () {
				voulue = false;
				appliquer();
			},
			reprendre: function () {
				voulue = true;
				appliquer();
			},
			/* État (tests, banc ; ajout lot A) : piste créée, lecture voulue, lecture en cours. */
			etat: function () {
				return { url: piste ? piste.dataset.url : '', voulue: voulue && !! url, joue: !! piste && ! piste.paused };
			},
			/* Effets sonores : v2.2. */
			effet: function () {},
		};
	}

	ynWE.audio = { creer: creer };
}() );
