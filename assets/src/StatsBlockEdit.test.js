/**
 * Tests del editor del bloque `lb-simple-podcast/podcast-stats`.
 */
import { act } from 'react';
import { createRoot } from 'react-dom/client';

let mockRecords = [];
let mockIsResolving = false;
let mockEntityQuery = null;

jest.mock( '@wordpress/i18n', () => ( { __: ( text ) => text } ), { virtual: true } );
jest.mock( '@wordpress/element', () => require( 'react' ), { virtual: true } );
jest.mock(
	'@wordpress/block-editor',
	() => ( {
		useBlockProps: () => ( { className: 'wp-block-lb-simple-podcast-podcast-stats' } ),
		InspectorControls: ( { children } ) => <div data-testid="inspector">{ children }</div>,
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/components',
	() => ( {
		PanelBody: ( { title, children } ) => (
			<section data-panel={ title }>{ children }</section>
		),
		Placeholder: ( { label, instructions } ) => (
			<div data-testid="placeholder">
				<strong>{ label }</strong>
				<p>{ instructions }</p>
			</div>
		),
		TextControl: ( { label, value, onChange } ) => (
			<label>
				{ label }
				<input
					type="text"
					value={ value }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			</label>
		),
		SelectControl: ( { label, value, options, onChange } ) => (
			<label>
				{ label }
				<select
					aria-label={ label }
					value={ value }
					onChange={ ( e ) => onChange( e.target.value ) }
				>
					{ options.map( ( opt ) => (
						<option key={ opt.value } value={ opt.value }>
							{ opt.label }
						</option>
					) ) }
				</select>
			</label>
		),
		ToggleControl: ( { label, checked, onChange } ) => (
			<label>
				<input
					type="checkbox"
					checked={ checked }
					onChange={ ( e ) => onChange( e.target.checked ) }
				/>
				{ label }
			</label>
		),
		RangeControl: ( { label, value, onChange } ) => (
			<label>
				{ label }
				<input
					type="range"
					value={ value }
					onChange={ ( e ) => onChange( Number( e.target.value ) ) }
				/>
			</label>
		),
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/core-data',
	() => ( {
		useEntityRecords: ( kind, name, query, options ) => {
			mockEntityQuery = { kind, name, query, options };
			return { records: mockRecords, isResolving: mockIsResolving };
		},
	} ),
	{ virtual: true }
);
jest.mock(
	'@wordpress/server-side-render',
	() => ( { block, attributes } ) => (
		<div
			data-testid="ssr"
			data-block={ block }
			data-attributes={ JSON.stringify( attributes ) }
		/>
	),
	{ virtual: true }
);

import StatsBlockEdit from './StatsBlockEdit';

globalThis.IS_REACT_ACT_ENVIRONMENT = true;

const DEFAULT_ATTRS = {
	days: 30,
	scope: 'all',
	postId: 0,
	showListens: true,
	showListeners: true,
	showEpisodes: true,
	showTopEpisodes: false,
	showClients: false,
	limit: 5,
	title: '',
};

function renderEdit( overrides = {}, setAttributes = jest.fn() ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	act( () =>
		root.render(
			<StatsBlockEdit
				attributes={ { ...DEFAULT_ATTRS, ...overrides } }
				setAttributes={ setAttributes }
			/>
		)
	);
	return container;
}

beforeEach( () => {
	mockRecords = [];
	mockIsResolving = false;
	mockEntityQuery = null;
	document.body.innerHTML = '';
} );

describe( 'StatsBlockEdit', () => {
	it( 'renderiza ServerSideRender por defecto con resumen del podcast', () => {
		const container = renderEdit();
		const ssr = container.querySelector( '[data-testid="ssr"]' );
		expect( ssr ).not.toBeNull();
		expect( ssr.dataset.block ).toBe( 'lb-simple-podcast/podcast-stats' );
		expect( mockEntityQuery.options.enabled ).toBe( false );
	} );

	it( 'muestra placeholder cuando scope=episode no tiene episodio seleccionado y filtra solo posts con audio', () => {
		mockRecords = [
			{ id: 10, title: { rendered: 'Ep Con Audio' }, audio_data: { attachment_id: 1 } },
			{ id: 11, title: { rendered: 'Post Sin Audio' }, audio_data: null },
		];
		const container = renderEdit( { scope: 'episode', postId: 0 } );

		expect( container.querySelector( '[data-testid="ssr"]' ) ).toBeNull();
		expect( container.querySelector( '[data-testid="placeholder"]' ) ).not.toBeNull();
		expect( mockEntityQuery.query.status ).toBe( 'publish' );

		const episodeSelect = container.querySelector( 'select[aria-label="Episodio"]' );
		expect( episodeSelect ).not.toBeNull();
		const optionsText = [ ...episodeSelect.querySelectorAll( 'option' ) ].map(
			( o ) => o.textContent
		);
		expect( optionsText ).toContain( 'Ep Con Audio' );
		expect( optionsText ).not.toContain( 'Post Sin Audio' );
	} );

	it( 'muestra placeholder cuando no hay métricas activas', () => {
		const container = renderEdit( {
			scope: 'current',
			showListens: false,
			showListeners: false,
			showEpisodes: true,
			showTopEpisodes: true,
			showClients: false,
		} );

		expect( container.querySelector( '[data-testid="ssr"]' ) ).toBeNull();
		expect( container.querySelector( '[data-testid="placeholder"]' ).textContent ).toContain(
			'Activa al menos una métrica visible'
		);
	} );
} );
