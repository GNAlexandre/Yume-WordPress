/**
 * Signaler une coquille (module corrections, lot L1).
 *
 * - Sélection d'un passage (3 à 300 caractères, espaces normalisées) dans le texte du chapitre
 *   (.yn-reader__texte) → bouton flottant « Signaler une coquille » sous la sélection. Sélection
 *   sur plusieurs paragraphes : seul le premier compte (extrait coupé à sa fin).
 * - Numérotation identique au lecteur et à la recherche : data-yn-paragraphe s'il est posé par
 *   la barre de lecture, sinon rang de l'enfant direct du contenu (hors script/style) ; index 0
 *   pour le premier, ancre #yn-p-N avec N = index + 1.
 * - Fenêtre <dialog> modale : passage (lecture seule), « paragraphe N », commentaire facultatif ;
 *   envoi POST /yume/v1/chapitres/{id}/coquilles avec X-WP-Nonce ; message de retour annoncé par
 *   une zone aria-live. Le lien « Signaler une coquille dans ce chapitre » (fin de chapitre) ouvre
 *   la même fenêtre au clavier, avec le paragraphe courant et un champ « Passage » à recopier.
 * - Visiteur (sans mode anonyme) : le bouton flottant et le lien mènent à la connexion, retour sur
 *   le paragraphe (#yn-p-N).
 *
 * Sans JavaScript, rien n'apparaît (éléments hidden, <dialog> fermé). ES2019, sans dépendance.
 */
( function () {
	'use strict';

	const racine = document.querySelector( '[data-yn-coquilles]' );
	if ( ! racine ) {
		return;
	}
	let config;
	try {
		config = JSON.parse( racine.getAttribute( 'data-yn-coquilles' ) || '{}' );
	} catch ( e ) {
		return;
	}
	const texte = document.querySelector( '.yn-reader .yn-reader__texte' ) || document.querySelector( '.yn-reader .wp-block-post-content' );
	const flottant = racine.querySelector( '[data-yn-coquilles-flottant]' );
	const annonce = racine.querySelector( '[data-yn-coquilles-annonce]' );
	const dialogue = racine.querySelector( 'dialog' );
	const membre = ! config.connexion;
	if ( ! texte || ! flottant || ( membre && ( ! dialogue || typeof dialogue.showModal !== 'function' ) ) ) {
		return;
	}
	const messages = config.messages || {};
	const MIN = config.min || 3;
	const MAX = config.max || 300;
	const IGNORES = /^(SCRIPT|STYLE|TEMPLATE|NOSCRIPT)$/;

	/** Texte aux espaces normalisées. */
	function normaliser( s ) {
		return String( s || '' ).replace( /\s+/g, ' ' ).trim();
	}

	/** Paragraphes du chapitre, dans l'ordre de la numérotation. */
	function paragraphes() {
		return Array.prototype.filter.call( texte.children, function ( el ) {
			return ! IGNORES.test( el.tagName );
		} );
	}

	/** Paragraphe (enfant direct du contenu, ou élément numéroté) qui contient un nœud. */
	function elementParagraphe( noeud ) {
		let el = noeud && noeud.nodeType === 1 ? noeud : noeud && noeud.parentElement;
		if ( ! el || ! texte.contains( el ) || el === texte ) {
			return null;
		}
		const marque = el.closest( '[data-yn-paragraphe]' );
		if ( marque && texte.contains( marque ) ) {
			return marque;
		}
		while ( el && el.parentElement !== texte ) {
			el = el.parentElement;
		}
		return el && ! IGNORES.test( el.tagName ) ? el : null;
	}

	/** Index (0 pour le premier) d'un paragraphe, -1 s'il n'est pas numéroté. */
	function indexParagraphe( el ) {
		const attribut = el.getAttribute( 'data-yn-paragraphe' );
		if ( attribut !== null && /^\d+$/.test( attribut ) ) {
			return parseInt( attribut, 10 );
		}
		return paragraphes().indexOf( el );
	}

	/** Élément du paragraphe d'index i. */
	function paragrapheIndex( i ) {
		const marque = texte.querySelector( '[data-yn-paragraphe="' + i + '"]' );
		return marque || paragraphes()[ i ] || null;
	}

	/** Contexte envoyé avec le signalement (le serveur garde de préférence son propre texte). */
	function contexte( i ) {
		const el = paragrapheIndex( i );
		return el ? normaliser( el.textContent ).slice( 0, 600 ) : '';
	}

	/**
	 * Paragraphe courant : position du suivi de lecture de la barre (localStorage yn.progression,
	 * entrée de ce chapitre), sinon le dernier paragraphe dont le haut a passé le tiers supérieur
	 * de la fenêtre.
	 */
	function paragrapheCourant() {
		try {
			const toutes = JSON.parse( window.localStorage.getItem( 'yn.progression' ) || 'null' );
			if ( toutes && typeof toutes === 'object' ) {
				const cles = Object.keys( toutes );
				for ( let i = 0; i < cles.length; i++ ) {
					const pos = toutes[ cles[ i ] ];
					if ( pos && +pos.chapitre_id === +config.chapitre && +pos.paragraphe >= 0 ) {
						return Math.floor( +pos.paragraphe );
					}
				}
			}
		} catch ( e ) {
			// Stockage indisponible : position calculée.
		}
		const liste = paragraphes();
		const limite = window.innerHeight / 3;
		let courant = 0;
		for ( let i = 0; i < liste.length; i++ ) {
			if ( liste[ i ].getBoundingClientRect().top <= limite ) {
				courant = i;
			} else {
				break;
			}
		}
		const el = liste[ courant ];
		return el ? Math.max( 0, indexParagraphe( el ) ) : 0;
	}

	/** Adresse de connexion qui ramène au paragraphe i. */
	function urlConnexion( i ) {
		return String( config.connexion ).replace( 'yn-p-' + config.fictif, 'yn-p-' + ( i + 1 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Sélection et bouton flottant                                        */
	/* ------------------------------------------------------------------ */

	let selection = null;
	let minuterie = null;

	/** Sélection courante dans le texte du chapitre : {paragraphe, extrait, rect} ou null. */
	function lireSelection() {
		const sel = window.getSelection ? window.getSelection() : null;
		if ( ! sel || sel.isCollapsed || ! sel.rangeCount ) {
			return null;
		}
		const plage = sel.getRangeAt( 0 );
		const el = elementParagraphe( plage.startContainer );
		if ( ! el ) {
			return null;
		}
		let partie = plage;
		if ( ! el.contains( plage.endContainer ) ) {
			// Plusieurs paragraphes : le premier seulement.
			partie = plage.cloneRange();
			partie.setEnd( el, el.childNodes.length );
		}
		let extrait = normaliser( partie.toString() );
		if ( extrait.length < MIN ) {
			return null;
		}
		if ( extrait.length > MAX ) {
			extrait = extrait.slice( 0, MAX - 1 ).trim() + '…';
		}
		const index = indexParagraphe( el );
		if ( index < 0 ) {
			return null;
		}
		return { paragraphe: index, extrait: extrait, rect: partie.getBoundingClientRect() };
	}

	function masquerFlottant() {
		flottant.hidden = true;
		selection = null;
	}

	function placerFlottant() {
		if ( dialogue && dialogue.open ) {
			return;
		}
		selection = lireSelection();
		if ( ! selection ) {
			masquerFlottant();
			return;
		}
		if ( ! membre ) {
			flottant.setAttribute( 'href', urlConnexion( selection.paragraphe ) );
		}
		flottant.hidden = false;
		const largeur = flottant.offsetWidth;
		const page = document.documentElement.clientWidth;
		const gauche = Math.min( Math.max( 8, selection.rect.left ), Math.max( 8, page - largeur - 8 ) );
		flottant.style.left = Math.round( gauche + window.scrollX ) + 'px';
		flottant.style.top = Math.round( selection.rect.bottom + window.scrollY + 8 ) + 'px';
	}

	function planifier() {
		clearTimeout( minuterie );
		minuterie = setTimeout( placerFlottant, 120 );
	}

	document.addEventListener( 'selectionchange', planifier );
	texte.addEventListener( 'mouseup', planifier );
	texte.addEventListener( 'keyup', planifier );
	texte.addEventListener( 'touchend', planifier );
	window.addEventListener( 'resize', planifier );
	// Garder la sélection au clic sur le bouton.
	flottant.addEventListener( 'mousedown', function ( e ) {
		e.preventDefault();
	} );

	/* ------------------------------------------------------------------ */
	/* Annonce                                                             */
	/* ------------------------------------------------------------------ */

	let minuterieAnnonce = null;

	function annoncer( message ) {
		clearTimeout( minuterieAnnonce );
		annonce.textContent = '';
		// Après un tour de boucle : relu par les lecteurs d'écran même si le texte est identique.
		setTimeout( function () {
			annonce.textContent = message;
			minuterieAnnonce = setTimeout( function () {
				annonce.textContent = '';
			}, 7000 );
		}, 50 );
	}

	/* ------------------------------------------------------------------ */
	/* Lien de fin de chapitre                                             */
	/* ------------------------------------------------------------------ */

	const fin = document.querySelector( '[data-yn-coquilles-fin]' );
	const ouvrirFin = fin ? fin.querySelector( '[data-yn-coquilles-ouvrir]' ) : null;

	if ( ! membre ) {
		if ( fin && ouvrirFin ) {
			fin.hidden = false;
			ouvrirFin.addEventListener( 'click', function () {
				window.location.href = urlConnexion( paragrapheCourant() );
			} );
		}
		return;
	}

	/* ------------------------------------------------------------------ */
	/* Fenêtre de signalement (membre, ou visiteur en mode anonyme)        */
	/* ------------------------------------------------------------------ */

	const formulaire = dialogue.querySelector( 'form' );
	const modeSelection = dialogue.querySelector( '[data-yn-mode-selection]' );
	const modeSaisie = dialogue.querySelector( '[data-yn-mode-saisie]' );
	const passage = dialogue.querySelector( '[data-yn-coquilles-passage]' );
	const numero = dialogue.querySelector( '[data-yn-coquilles-numero]' );
	const champParagraphe = dialogue.querySelector( '#yn-coquilles-paragraphe' );
	const champExtrait = dialogue.querySelector( '#yn-coquilles-extrait' );
	const champCommentaire = dialogue.querySelector( '#yn-coquilles-commentaire' );
	const champPiege = dialogue.querySelector( '[name="yn_site_web"]' );
	const erreur = dialogue.querySelector( '[data-yn-coquilles-erreur]' );
	const envoyer = dialogue.querySelector( '[data-yn-coquilles-envoyer]' );
	let etat = null;
	let retourFocus = null;

	function libelleNumero( i ) {
		numero.textContent = String( messages.paragraphe || 'paragraphe %d' ).replace( '%d', String( i + 1 ) );
	}

	function ouvrir( info, saisie, depuis ) {
		etat = { paragraphe: info.paragraphe, extrait: info.extrait || '', saisie: saisie };
		retourFocus = depuis || null;
		modeSelection.hidden = saisie;
		modeSaisie.hidden = ! saisie;
		passage.textContent = saisie ? '' : '« ' + etat.extrait + ' »';
		champParagraphe.max = String( Math.max( 1, paragraphes().length ) );
		champParagraphe.value = String( etat.paragraphe + 1 );
		champExtrait.value = '';
		champCommentaire.value = '';
		erreur.textContent = '';
		envoyer.disabled = false;
		libelleNumero( etat.paragraphe );
		dialogue.showModal();
		( saisie ? champExtrait : champCommentaire ).focus();
	}

	function fermer() {
		if ( dialogue.open ) {
			dialogue.close();
		}
	}

	dialogue.addEventListener( 'close', function () {
		if ( retourFocus && ! retourFocus.hidden && document.contains( retourFocus ) ) {
			retourFocus.focus();
		}
		retourFocus = null;
	} );
	// Les raccourcis de la barre de lecture (← →, « s ») ne s'appliquent pas dans la fenêtre.
	dialogue.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== 'Escape' && e.key !== 'Tab' ) {
			e.stopPropagation();
		}
	} );
	dialogue.querySelectorAll( '[data-yn-fermer]' ).forEach( function ( bouton ) {
		bouton.addEventListener( 'click', fermer );
	} );
	champParagraphe.addEventListener( 'input', function () {
		const n = parseInt( champParagraphe.value, 10 );
		if ( n >= 1 ) {
			libelleNumero( n - 1 );
		}
	} );

	flottant.addEventListener( 'click', function () {
		const info = selection || lireSelection();
		if ( ! info ) {
			masquerFlottant();
			return;
		}
		flottant.hidden = true;
		ouvrir( info, false, null );
	} );

	if ( fin && ouvrirFin ) {
		fin.hidden = false;
		ouvrirFin.addEventListener( 'click', function () {
			ouvrir( { paragraphe: paragrapheCourant(), extrait: '' }, true, ouvrirFin );
		} );
	}

	formulaire.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		if ( ! etat ) {
			return;
		}
		let paragraphe = etat.paragraphe;
		let extrait = etat.extrait;
		if ( etat.saisie ) {
			paragraphe = parseInt( champParagraphe.value, 10 ) - 1;
			extrait = normaliser( champExtrait.value );
			if ( ! ( paragraphe >= 0 ) ) {
				champParagraphe.focus();
				erreur.textContent = messages.erreur || '';
				return;
			}
			if ( extrait.length < MIN || extrait.length > MAX ) {
				erreur.textContent = messages.court || '';
				champExtrait.focus();
				return;
			}
		}
		const corps = {
			paragraphe: paragraphe,
			extrait: extrait,
			contexte: contexte( paragraphe ),
			commentaire: champCommentaire.value.trim(),
		};
		if ( config.anonyme ) {
			corps.jeton = config.jeton || '';
			corps.yn_site_web = champPiege ? champPiege.value : '';
		}
		const entetes = { 'Content-Type': 'application/json', Accept: 'application/json' };
		if ( config.nonce ) {
			entetes[ 'X-WP-Nonce' ] = config.nonce;
		}
		envoyer.disabled = true;
		erreur.textContent = '';
		envoyer.setAttribute( 'aria-busy', 'true' );
		window.fetch( config.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: entetes,
			body: JSON.stringify( corps ),
		} )
			.then( function ( reponse ) {
				return reponse.json().catch( function () {
					return {};
				} ).then( function ( donnees ) {
					return { ok: reponse.ok, donnees: donnees || {} };
				} );
			} )
			.then( function ( r ) {
				if ( r.ok || r.donnees.code === 'yume_coquille_doublon' ) {
					fermer();
					masquerFlottant();
					if ( window.getSelection ) {
						window.getSelection().removeAllRanges();
					}
					annoncer( r.donnees.message || '' );
					return;
				}
				erreur.textContent = r.donnees.code === 'rest_cookie_invalid_nonce' ? messages.session : r.donnees.message || messages.erreur;
			} )
			.catch( function () {
				erreur.textContent = messages.reseau || messages.erreur || '';
			} )
			.finally( function () {
				envoyer.disabled = false;
				envoyer.removeAttribute( 'aria-busy' );
			} );
	} );
}() );
