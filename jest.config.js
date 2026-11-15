module.exports = {
	testEnvironment: 'jsdom',
	testMatch: [ '<rootDir>/assets/src/**/*.test.js', '<rootDir>/scripts/check-feed/**/*.test.js' ],
	transform: {
		'^.+\\.js$': [ 'babel-jest', { presets: [ '@wordpress/babel-preset-default' ] } ],
	},
};
