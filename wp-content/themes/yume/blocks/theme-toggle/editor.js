/**
 * Bloc « Bascule de thème » dans l'éditeur : aperçu statique du bouton.
 * Le rendu réel est fait côté serveur (render.php) ; le comportement vit dans assets/js/yume.js.
 */
( function ( blocks, element, blockEditor ) {
	'use strict';

	var el = element.createElement;

	function icone() {
		return el(
			'svg',
			{
				width: 16,
				height: 16,
				viewBox: '0 0 24 24',
				fill: 'none',
				stroke: 'currentColor',
				strokeWidth: 2,
				strokeLinecap: 'round',
				strokeLinejoin: 'round',
				'aria-hidden': 'true',
				focusable: 'false'
			},
			el( 'path', { d: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z' } )
		);
	}

	blocks.registerBlockType( 'yume/theme-toggle', {
		edit: function () {
			var proprietes = blockEditor.useBlockProps( {
				className: 'yn-theme-toggle yn-theme-toggle--apercu',
				title: 'Bascule entre le thème Nuit et le thème Papier'
			} );
			return el(
				'span',
				proprietes,
				icone(),
				el( 'span', { className: 'yn-visually-hidden' }, 'Thème clair' )
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor ) );
