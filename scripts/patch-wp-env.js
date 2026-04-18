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
 */

const fs = require( 'fs' );
const path = require( 'path' );

const target = path.resolve(
	__dirname,
	'..',
	'node_modules/@wordpress/env/lib/runtime/docker/init-config.js'
);

if ( ! fs.existsSync( target ) ) {
	// wp-env not installed (e.g. running lint before npm install). Skip.
	process.exit( 0 );
}

const src = fs.readFileSync( target, 'utf8' );

const needle =
	'RUN composer global require --dev phpunit/phpunit:"^5.7.21 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0"\nUSER root';

if ( ! src.includes( needle ) ) {
	// Already patched, or a wp-env version we don't recognise. Leave it alone.
	process.exit( 0 );
}

const replacement =
	'RUN mkdir -p /home/$HOST_USERNAME/.composer\n' +
	'RUN printf \'{"config":{"audit":{"abandoned":"ignore","ignore":["PKSA-5jz8-6tcw-pbk4","PKSA-z3gr-8qht-p93v"]}}}\' > /home/$HOST_USERNAME/.composer/composer.json\n' +
	'RUN composer global require --dev --no-audit phpunit/phpunit:"^5.7.21 || ^6.0 || ^7.0 || ^8.0 || ^9.0 || ^10.0"\nUSER root';

fs.writeFileSync( target, src.replace( needle, replacement ) );
console.log( 'Patched wp-env Dockerfile to bypass composer audit advisories.' );
