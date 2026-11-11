/**
 * Test del panel con las dependencias de WordPress simuladas.
 */
import { act } from 'react';
import { createRoot } from 'react-dom/client';

let mockMeta = {};
const mockSetMeta = jest.fn();
let mockMedia = null;
let mockPostType = 'post';
let mockOnSelect;

jest.mock( '@wordpress/i18n', () => ( { __: ( text ) => text } ), { virtual: true } );
jest.mock( '@wordpress/element', () => require( 'react' ), { virtual: true } );
jest.mock(
	'@wordpress/components',
	() => ( {
		Button: ( { children, onClick } ) => <button onClick={ onClick }>{ children }</button>,
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/media-utils',
	() => ( {
		MediaUpload: ( { onSelect, allowedTypes, render } ) => {
			mockOnSelect = onSelect;
			return <div data-allowed={ allowedTypes.join( ',' ) }>{ render( { open: () => {} } ) }</div>;
		},
	} ),
	{ virtual: true }
);
jest.mock( '@wordpress/block-editor', () => ( { MediaUploadCheck: ( { children } ) => children } ), {
	virtual: true,
} );
jest.mock(
	'@wordpress/data',
	() => ( {
		useSelect: ( callback ) =>
			callback( () => ( {
				getCurrentPostType: () => mockPostType,
				getMedia: () => mockMedia,
			} ) ),
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/core-data',
	() => ( {
		store: 'core',
		useEntityProp: () => [ mockMeta, mockSetMeta ],
	} ),
	{ virtual: true }
);
jest.mock( '@wordpress/editor', () => ( { store: 'core/editor' } ), { virtual: true } );

import AudioPanel from './AudioPanel';

globalThis.IS_REACT_ACT_ENVIRONMENT = true;

function render() {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	act( () => root.render( <AudioPanel /> ) );
	return container;
}

beforeEach( () => {
	mockMeta = {};
	mockMedia = null;
	mockPostType = 'post';
	mockSetMeta.mockClear();
	document.body.innerHTML = '';
} );

describe( 'AudioPanel', () => {
	it( 'restringe el selector a audio/mpeg y ofrece seleccionar', () => {
		const container = render();
		expect( container.querySelector( '[data-allowed]' ).dataset.allowed ).toBe( 'audio/mpeg' );
		expect( container.textContent ).toContain( 'Seleccionar MP3' );
		expect( container.textContent ).not.toContain( 'Quitar audio' );
	} );

	it( 'guarda el ID al seleccionar un MP3', () => {
		render();
		act( () => mockOnSelect( { id: 42, mime: 'audio/mpeg' } ) );
		expect( mockSetMeta ).toHaveBeenCalledWith( { _lb_sp_audio_id: 42 } );
	} );

	it( 'rechaza archivos que no son MP3 y muestra el error', () => {
		const container = render();
		act( () => mockOnSelect( { id: 7, mime: 'audio/wav' } ) );
		expect( mockSetMeta ).not.toHaveBeenCalled();
		expect( container.querySelector( '[role="alert"]' ).textContent ).toContain( 'MP3' );
	} );

	it( 'muestra datos del audio y permite quitarlo', () => {
		mockMeta = { _lb_sp_audio_id: 5 };
		mockMedia = {
			title: { rendered: 'Episodio 1' },
			source_url: 'https://example.org/a.mp3',
			media_details: { length: 345, filesize: 2048 },
		};
		const container = render();
		expect( container.textContent ).toContain( 'Episodio 1' );
		expect( container.textContent ).toContain( '5:45 · 2.0 KB' );
		expect( container.querySelector( 'audio' ).getAttribute( 'src' ) ).toBe( 'https://example.org/a.mp3' );

		const remove = [ ...container.querySelectorAll( 'button' ) ].find( ( b ) =>
			b.textContent.includes( 'Quitar' )
		);
		act( () => remove.click() );
		expect( mockSetMeta ).toHaveBeenCalledWith( { _lb_sp_audio_id: 0 } );
	} );

	it( 'no renderiza en otros tipos de contenido', () => {
		mockPostType = 'page';
		expect( render().innerHTML ).toBe( '' );
	} );
} );
