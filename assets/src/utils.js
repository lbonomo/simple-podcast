/**
 * Utilidades de formato para el panel de audio.
 */

export const META_KEY = '_lb_sp_audio_id';
export const MIME_TYPE = 'audio/mpeg';

/**
 * Formatea segundos como m:ss o h:mm:ss.
 *
 * @param {number} seconds Duración en segundos.
 * @return {string} Duración legible; cadena vacía si no es válida.
 */
export function formatDuration( seconds ) {
	const total = Math.floor( Number( seconds ) );
	if ( ! Number.isFinite( total ) || total <= 0 ) {
		return '';
	}
	const h = Math.floor( total / 3600 );
	const m = Math.floor( ( total % 3600 ) / 60 );
	const s = total % 60;
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return h > 0 ? `${ h }:${ pad( m ) }:${ pad( s ) }` : `${ m }:${ pad( s ) }`;
}

/**
 * Formatea bytes como KB/MB.
 *
 * @param {number} bytes Tamaño en bytes.
 * @return {string} Tamaño legible; cadena vacía si no es válido.
 */
export function formatBytes( bytes ) {
	const n = Number( bytes );
	if ( ! Number.isFinite( n ) || n <= 0 ) {
		return '';
	}
	if ( n < 1024 * 1024 ) {
		return `${ ( n / 1024 ).toFixed( 1 ) } KB`;
	}
	return `${ ( n / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
}

/**
 * Indica si el objeto de medios seleccionado es un MP3.
 *
 * @param {Object} media Objeto devuelto por el selector de medios.
 * @return {boolean} Verdadero si es audio/mpeg.
 */
export function isMp3( media ) {
	return !! media && ( media.mime === MIME_TYPE || media.mime_type === MIME_TYPE );
}
