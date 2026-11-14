const path = require( 'path' );
const config = require( '@wordpress/scripts/config/playwright.config' );

config.testDir = path.resolve( process.cwd(), 'specs' );
config.webServer.command = 'npm run env:start';

module.exports = config;
