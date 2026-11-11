import { formatBytes, formatDuration, isMp3 } from './utils';

describe( 'formatDuration', () => {
	it( 'formatea minutos y segundos', () => {
		expect( formatDuration( 345 ) ).toBe( '5:45' );
		expect( formatDuration( 59 ) ).toBe( '0:59' );
	} );

	it( 'formatea horas', () => {
		expect( formatDuration( 3723 ) ).toBe( '1:02:03' );
	} );

	it( 'devuelve vacío con valores inválidos', () => {
		expect( formatDuration( 0 ) ).toBe( '' );
		expect( formatDuration( undefined ) ).toBe( '' );
		expect( formatDuration( 'x' ) ).toBe( '' );
	} );
} );

describe( 'formatBytes', () => {
	it( 'formatea KB y MB', () => {
		expect( formatBytes( 2048 ) ).toBe( '2.0 KB' );
		expect( formatBytes( 15420310 ) ).toBe( '14.7 MB' );
	} );

	it( 'devuelve vacío con valores inválidos', () => {
		expect( formatBytes( null ) ).toBe( '' );
		expect( formatBytes( -1 ) ).toBe( '' );
	} );
} );

describe( 'isMp3', () => {
	it( 'acepta solo audio/mpeg', () => {
		expect( isMp3( { mime: 'audio/mpeg' } ) ).toBe( true );
		expect( isMp3( { mime_type: 'audio/mpeg' } ) ).toBe( true );
		expect( isMp3( { mime: 'audio/wav' } ) ).toBe( false );
		expect( isMp3( null ) ).toBe( false );
	} );
} );
