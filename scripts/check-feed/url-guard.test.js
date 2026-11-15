/**
 * @jest-environment node
 */
'use strict';

const { isPrivateAddress, resolveSafe, validateInputUrl, assertSafeProtocol, BlockedHostError, BlockedProtocolError } = require( './url-guard' );

describe( 'url-guard', () => {
	const privateAddresses = [
		'0.0.0.0',
		'0.1.2.3',
		'10.0.0.1',
		'10.255.255.255',
		'100.64.0.1',
		'100.127.255.255',
		'127.0.0.1',
		'127.255.255.254',
		'169.254.169.254',
		'172.16.0.1',
		'172.31.255.255',
		'192.0.0.1',
		'192.0.2.10',
		'192.168.1.1',
		'198.18.0.1',
		'198.19.255.255',
		'198.51.100.7',
		'203.0.113.9',
		'224.0.0.1',
		'239.255.255.255',
		'240.0.0.1',
		'255.255.255.255',
		'::',
		'::1',
		'fc00::1',
		'fd12:3456::1',
		'fe80::1',
		'febf::1',
		'ff02::1',
		'2001:db8::1',
		'::ffff:127.0.0.1',
		'::ffff:10.1.2.3',
		'::ffff:192.168.0.1',
		'::ffff:7f00:1',
	];
	const publicAddresses = [
		'1.1.1.1',
		'8.8.8.8',
		'93.184.216.34',
		'172.15.255.255',
		'172.32.0.1',
		'100.63.255.255',
		'100.128.0.1',
		'192.0.3.1',
		'198.17.255.255',
		'198.20.0.1',
		'223.255.255.255',
		'2606:4700:4700::1111',
		'2a00:1450:4001:81b::200e',
		'::ffff:8.8.8.8',
	];

	test.each( privateAddresses )( 'bloquea %s', ( ip ) => {
		expect( isPrivateAddress( ip ) ).toBe( true );
	} );

	test.each( publicAddresses )( 'permite %s', ( ip ) => {
		expect( isPrivateAddress( ip ) ).toBe( false );
	} );

	test( 'trata como privado un texto que no es IP', () => {
		expect( isPrivateAddress( 'no-es-ip' ) ).toBe( true );
	} );

	describe( 'resolveSafe', () => {
		const lookupFor = ( addresses ) => jest.fn( async () => addresses );

		test( 'bloquea un literal privado sin consultar DNS', async () => {
			const lookup = lookupFor( [] );
			await expect( resolveSafe( '127.0.0.1', { lookup } ) ).rejects.toBeInstanceOf( BlockedHostError );
			await expect( resolveSafe( '[::1]', { lookup } ) ).rejects.toBeInstanceOf( BlockedHostError );
			expect( lookup ).not.toHaveBeenCalled();
		} );

		test( 'bloquea si cualquiera de las direcciones es privada', async () => {
			const lookup = lookupFor( [
				{ address: '93.184.216.34', family: 4 },
				{ address: '10.0.0.5', family: 4 },
			] );
			await expect( resolveSafe( 'mixto.test', { lookup } ) ).rejects.toBeInstanceOf( BlockedHostError );
		} );

		test( 'devuelve la primera dirección pública validada', async () => {
			const lookup = lookupFor( [
				{ address: '93.184.216.34', family: 4 },
				{ address: '8.8.8.8', family: 4 },
			] );
			await expect( resolveSafe( 'publico.test', { lookup } ) ).resolves.toEqual( { address: '93.184.216.34', family: 4 } );
		} );

		test( 'con allowPrivate permite direcciones privadas', async () => {
			const lookup = lookupFor( [ { address: '127.0.0.1', family: 4 } ] );
			await expect( resolveSafe( 'local.test', { allowPrivate: true, lookup } ) ).resolves.toEqual( {
				address: '127.0.0.1',
				family: 4,
			} );
			await expect( resolveSafe( '10.0.0.1', { allowPrivate: true, lookup } ) ).resolves.toMatchObject( { address: '10.0.0.1' } );
		} );

		test( 'informa un error de DNS con código dns', async () => {
			const lookup = jest.fn( async () => {
				throw Object.assign( new Error( 'ENOTFOUND' ), { code: 'ENOTFOUND' } );
			} );
			await expect( resolveSafe( 'noexiste.test', { lookup } ) ).rejects.toMatchObject( { code: 'dns' } );
		} );
	} );

	describe( 'validateInputUrl', () => {
		test( 'acepta http y https', () => {
			expect( validateInputUrl( 'http://example.com/a' ).url.href ).toBe( 'http://example.com/a' );
			expect( validateInputUrl( 'https://example.com/a' ).error ).toBeUndefined();
		} );

		test.each( [ 'ftp://example.com', 'file:///etc/passwd', 'javascript:alert(1)', 'gopher://x', 'texto', '' ] )(
			'rechaza %s',
			( input ) => {
				expect( validateInputUrl( input ).error ).toEqual( expect.any( String ) );
			}
		);

		test( 'rechaza credenciales', () => {
			expect( validateInputUrl( 'https://u:p@example.com/' ).error ).toMatch( /credenciales/ );
			expect( validateInputUrl( 'https://u@example.com/' ).error ).toMatch( /credenciales/ );
		} );

		test( 'las formas decimales y hexadecimales de IP se normalizan y bloquean', () => {
			const decimal = validateInputUrl( 'http://2130706433/' ).url;
			expect( isPrivateAddress( decimal.hostname ) ).toBe( true );
			const hex = validateInputUrl( 'http://0x7f.1/' ).url;
			expect( isPrivateAddress( hex.hostname ) ).toBe( true );
		} );
	} );

	test( 'assertSafeProtocol rechaza esquemas distintos de http y https', () => {
		expect( () => assertSafeProtocol( new URL( 'https://e.com/' ) ) ).not.toThrow();
		expect( () => assertSafeProtocol( new URL( 'ftp://e.com/' ) ) ).toThrow( BlockedProtocolError );
		expect( () => assertSafeProtocol( new URL( 'file:///x' ) ) ).toThrow( BlockedProtocolError );
	} );
} );
