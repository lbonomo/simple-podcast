const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test( 'publishes a post with an MP3 player and podcast feed item', async ( {
	admin,
	editor,
	page,
} ) => {
	const runId = Date.now();
	const title = `E2E Podcast ${ runId }`;
	const audioFilename = `e2e-podcast-${ runId }.mp3`;
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
	await expect( panelToggle ).toBeVisible();
	if ( ! ( await selectAudioButton.isVisible() ) ) {
		await panelToggle.click();
	}
	await selectAudioButton.click();

	await page.getByRole( 'tab', { name: 'Upload files' } ).click();
	const uploadInput = page.locator( '.media-modal input[type="file"]' );
	await expect( uploadInput ).toBeAttached();
	await uploadInput.setInputFiles( {
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
	await expect( uploadedAudio ).toHaveAttribute( 'data-id', /^\d+$/ );
	if ( 'true' !== ( await uploadedAudio.getAttribute( 'aria-checked' ) ) ) {
		await uploadedAudio.locator( '.attachment-preview' ).click();
	}
	await expect( uploadedAudio ).toHaveAttribute( 'aria-checked', 'true' );
	const selectButton = page
		.locator( '.media-modal' )
		.getByRole( 'button', { name: 'Select', exact: true } );
	await expect( selectButton ).toBeEnabled();
	await selectButton.click();
	await expect( page.locator( '.lb-sp-audio-panel audio' ) ).toHaveAttribute(
		'src',
		/.+/
	);

	const postId = await editor.publishPost();
	expect( postId ).toBeGreaterThan( 0 );

	await page.goto( `/?p=${ postId }` );
	await expect( page.locator( 'audio' ).first() ).toBeVisible();

	const feedResponse = await page.request.get( '/?feed=podcast' );
	expect( feedResponse.status() ).toBe( 200 );
	const feed = await feedResponse.text();
	const episode = feed.match(
		new RegExp(
			`<item>[\\s\\S]*?<title>${ title }</title>[\\s\\S]*?<enclosure\\b[^>]*type="audio/mpeg"[^>]*>[\\s\\S]*?</item>`
		)
	);
	expect( episode ).not.toBeNull();
} );
