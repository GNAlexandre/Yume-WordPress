/**
 * Éditeur des blocs dynamiques Yume : aperçu serveur + réglages générés depuis les attributs.
 * Aucun build requis (scripts WordPress globaux).
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.blocks || ! Array.isArray( window.yumeDynamicBlocks ) ) {
		return;
	}
	var el = wp.element.createElement;
	var ServerSideRender = wp.serverSideRender;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var c = wp.components;

	function control( name, def, value, setAttributes ) {
		var label = def.label || name;
		var set = function ( v ) {
			var o = {};
			o[ name ] = v;
			setAttributes( o );
		};
		if ( Array.isArray( def.enum ) ) {
			return el( c.SelectControl, {
				key: name,
				label: label,
				value: value,
				options: def.enum.map( function ( v ) {
					return { label: String( v ), value: v };
				} ),
				onChange: set,
			} );
		}
		if ( def.type === 'boolean' ) {
			return el( c.ToggleControl, { key: name, label: label, checked: !! value, onChange: set } );
		}
		if ( def.type === 'number' || def.type === 'integer' ) {
			return el( c.TextControl, {
				key: name,
				label: label,
				type: 'number',
				value: value === undefined || value === null ? '' : value,
				onChange: function ( v ) {
					set( v === '' ? undefined : Number( v ) );
				},
			} );
		}
		if ( def.type === 'string' ) {
			return el( c.TextControl, { key: name, label: label, value: value || '', onChange: set } );
		}
		return null;
	}

	window.yumeDynamicBlocks.forEach( function ( name ) {
		if ( wp.blocks.getBlockType( name ) ) {
			// Déjà enregistré côté client par un script dédié du module.
			return;
		}
		var settings = {
			edit: function ( props ) {
				var blockProps = useBlockProps();
				var attrs = ( wp.blocks.getBlockType( name ) || {} ).attributes || {};
				var controls = Object.keys( attrs )
					.filter( function ( k ) {
						return k !== 'lock' && k !== 'metadata' && k !== 'className' && k !== 'style';
					} )
					.map( function ( k ) {
						return control( k, attrs[ k ], props.attributes[ k ], props.setAttributes );
					} )
					.filter( Boolean );
				return el(
					'div',
					blockProps,
					controls.length
						? el( InspectorControls, null, el( c.PanelBody, { title: 'Réglages' }, controls ) )
						: null,
					el( ServerSideRender, {
						block: name,
						attributes: props.attributes,
						urlQueryArgs: { post_id: wp.data && wp.data.select( 'core/editor' ) ? wp.data.select( 'core/editor' ).getCurrentPostId() : 0 },
					} )
				);
			},
			save: function () {
				return null;
			},
		};
		// registerBlockType fusionne automatiquement la définition serveur (block.json) :
		// titre, catégorie, attributs, supports.
		wp.blocks.registerBlockType( name, settings );
	} );
} )( window.wp );
