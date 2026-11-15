'use strict';

/**
 * Crea una comprobación.
 *
 * @param {string} id Identificador estable (por ejemplo "range.status").
 * @param {'feed'|'channel'|'item'|'enclosure'} scope Ámbito.
 * @param {'ok'|'warning'|'failure'|'info'} result Resultado.
 * @param {string} message Mensaje en español.
 * @param {Object} [extra] Campos opcionales: expected, actual, method.
 * @return {Object}
 */
function makeCheck( id, scope, result, message, extra = {} ) {
	const check = { id, scope, result, message };
	for ( const key of [ 'expected', 'actual', 'method' ] ) {
		if ( extra[ key ] !== undefined ) {
			check[ key ] = extra[ key ];
		}
	}
	return check;
}

const ok = ( id, scope, message, extra ) => makeCheck( id, scope, 'ok', message, extra );
const warning = ( id, scope, message, extra ) => makeCheck( id, scope, 'warning', message, extra );
const failure = ( id, scope, message, extra ) => makeCheck( id, scope, 'failure', message, extra );
const info = ( id, scope, message, extra ) => makeCheck( id, scope, 'info', message, extra );

/**
 * Cuenta fallos y advertencias de una lista de comprobaciones.
 *
 * @param {Object[]} checks Comprobaciones.
 * @return {{failures: number, warnings: number}}
 */
function countChecks( checks ) {
	return {
		failures: checks.filter( ( c ) => c.result === 'failure' ).length,
		warnings: checks.filter( ( c ) => c.result === 'warning' ).length,
	};
}

module.exports = { makeCheck, ok, warning, failure, info, countChecks };
