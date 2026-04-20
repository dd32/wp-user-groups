#!/usr/bin/env node
/**
 * wp-env's generated Dockerfile runs `composer global require phpunit/phpunit`
 * against a range of versions that now all carry security advisories
 * (PKSA-5jz8-6tcw-pbk4 / PKSA-z3gr-8qht-p93v). Modern composer refuses to
 * install packages flagged by an advisory unless the project config opts out,
 * and neither --no-audit nor COMPOSER_DISABLE_NETWORK bypass it.
 *
 * This script patches the Dockerfile template inside @wordpress/env so that
 * the global composer config ignores those two advisories before the
 * `composer global require` line runs. Safe to re-apply.
 *
 * Supports both the wp-env 10 path (`runtime/docker/init-config.js`) and the
 * wp-env 11 path (`runtime/docker/docker-config.js`).
 */

const fs = require( 'fs' );
const path = require( 'path' );

const candidates = [
	'node_modules/@wordpress/env/lib/runtime/docker/init-config.js',
	'node_modules/@wordpress/env/lib/runtime/docker/docker-config.js',
];

const needle =
	'RUN composer global require --dev phpunit/phpunit:"^5.7.21 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0"\nUSER root';

const replacement =
	'RUN mkdir -p /home/$HOST_USERNAME/.composer\n' +
	'RUN printf \'{"config":{"audit":{"abandoned":"ignore","ignore":["PKSA-5jz8-6tcw-pbk4","PKSA-z3gr-8qht-p93v"]}}}\' > /home/$HOST_USERNAME/.composer/composer.json\n' +
	'RUN composer global require --dev --no-audit phpunit/phpunit:"^5.7.21 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0"\nUSER root';

let patched = 0;
for ( const rel of candidates ) {
	const target = path.resolve( __dirname, '..', rel );
	if ( ! fs.existsSync( target ) ) {
		continue;
	}
	const src = fs.readFileSync( target, 'utf8' );
	if ( ! src.includes( needle ) ) {
		continue; // already patched, or not the line we expected
	}
	fs.writeFileSync( target, src.replace( needle, replacement ) );
	console.log( 'Patched ' + rel + ' to bypass composer audit advisories.' );
	patched++;
}

if ( patched === 0 ) {
	// Nothing to do — probably already patched, or wp-env isn't installed yet.
	process.exit( 0 );
}
