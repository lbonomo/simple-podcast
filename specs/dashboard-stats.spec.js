const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test( 'dashboard renders charts, filters and refreshes stats cache', async ( {
	admin,
	editor,
	page,
} ) => {
	const consoleErrors = [];
	page.on( 'console', ( msg ) => {
		if ( 'error' === msg.type() ) {
			consoleErrors.push( msg.text() );
		}
	} );

	const runId = Date.now();
	const title = `E2E Stats ${ runId }`;
	const audioFilename = `e2e-stats-${ runId }.mp3`;
	const audioPath = path.resolve( process.cwd(), 'tests/audios/demo.mp3' );

	await admin.createNewPost();
	await editor.canvas
		.getByRole( 'textbox', { name: 'Add title' } )
		.fill( title );
	await editor.openDocumentSettingsSidebar();

	const panelToggle = page.getByRole( 'button', {
		name: 'Podcast (audio MP3)',
		exact: true,
	} );
	const selectAudioButton = page.getByRole( 'button', {
		name: 'Seleccionar MP3',
		exact: true,
	} );
	if ( ! ( await selectAudioButton.isVisible() ) ) {
		await panelToggle.click();
	}
	await selectAudioButton.click();

	await page.getByRole( 'tab', { name: 'Upload files' } ).click();
	await page.locator( '.media-modal input[type="file"]' ).setInputFiles( {
		name: audioFilename,
		mimeType: 'audio/mpeg',
		buffer: fs.readFileSync( audioPath ),
	} );
	await page.getByRole( 'tab', { name: 'Media Library' } ).click();

	const uploadedAudio = page
		.locator( '.media-modal .attachment' )
		.filter( { hasText: audioFilename } )
		.last();
	await expect( uploadedAudio ).toBeVisible();
	if ( 'true' !== ( await uploadedAudio.getAttribute( 'aria-checked' ) ) ) {
		await uploadedAudio.locator( '.attachment-preview' ).click();
	}
	await page
		.locator( '.media-modal' )
		.getByRole( 'button', { name: 'Select', exact: true } )
		.click();
	await expect( page.locator( '.lb-sp-audio-panel audio' ) ).toHaveAttribute(
		'src',
		/.+/
	);

	const postId = await editor.publishPost();
	await page.goto( `/?p=${ postId }` );
	const audioSrc = await page.locator( 'audio' ).first().getAttribute( 'src' );
	expect( audioSrc ).toBeTruthy();

	const audioRes = await page.request.get( audioSrc, {
		headers: {
			'User-Agent':
				'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
		},
	} );
	expect( audioRes.status() ).toBe( 200 );

	await admin.visitAdminPage( 'admin.php', 'page=lb-sp-dashboard' );
	await page.getByRole( 'button', { name: 'Actualizar datos' } ).click();

	await expect( page.locator( '#lb-sp-trend-chart svg' ) ).toBeVisible();
	await expect( page.locator( '#lb-sp-clients-chart svg' ) ).toBeVisible();
	await expect( page.locator( '.lb-sp-top-table' ) ).toContainText( title );

	// Verifica toggle de serie y filtro por período.
	await page.locator( '[data-lb-sp-series="noise"]' ).check();
	await page.selectOption( '#lb-sp-filter-days', '7' );
	await page.getByRole( 'button', { name: 'Aplicar' } ).click();
	await expect( page ).toHaveURL( /days=7/ );
	await expect( page.locator( '#lb-sp-trend-chart svg' ) ).toBeVisible();

	expect( consoleErrors ).toEqual( [] );
} );
