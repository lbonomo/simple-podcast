import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { MediaUpload } from '@wordpress/media-utils';
import { MediaUploadCheck } from '@wordpress/block-editor';
import { useSelect } from '@wordpress/data';
import { store as coreStore, useEntityProp } from '@wordpress/core-data';
import { store as editorStore } from '@wordpress/editor';
import { META_KEY, MIME_TYPE, formatBytes, formatDuration, isMp3 } from './utils';

const ALLOWED_TYPES = [ MIME_TYPE ];

/**
 * Contenido del panel: selector de MP3, vista previa y botón de quitar.
 *
 * @return {Element|null} Panel o null si el tipo de contenido no es "post".
 */
export default function AudioPanel() {
	const postType = useSelect( ( select ) => select( editorStore ).getCurrentPostType(), [] );
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const [ error, setError ] = useState( '' );

	const audioId = Number( meta?.[ META_KEY ] ) || 0;
	const media = useSelect(
		( select ) => ( audioId ? select( coreStore ).getMedia( audioId ) : null ),
		[ audioId ]
	);

	if ( 'post' !== postType ) {
		return null;
	}

	const onSelect = ( selected ) => {
		if ( ! isMp3( selected ) ) {
			setError( __( 'Solo se admiten archivos MP3 (audio/mpeg).', 'lb-simple-podcast' ) );
			return;
		}
		setError( '' );
		setMeta( { ...meta, [ META_KEY ]: selected.id } );
	};

	const onRemove = () => {
		setError( '' );
		setMeta( { ...meta, [ META_KEY ]: 0 } );
	};

	const details = media?.media_details;
	const info = [ formatDuration( details?.length ), formatBytes( details?.filesize ) ]
		.filter( Boolean )
		.join( ' · ' );

	return (
		<div className="lb-sp-audio-panel">
			{ audioId > 0 && (
				<div className="lb-sp-audio-panel__current">
					<p className="lb-sp-audio-panel__title">
						{ media?.title?.rendered || media?.slug || __( 'Audio seleccionado', 'lb-simple-podcast' ) }
					</p>
					{ info && <p className="lb-sp-audio-panel__info">{ info }</p> }
					{ media?.source_url && (
						// eslint-disable-next-line jsx-a11y/media-has-caption
						<audio controls preload="none" src={ media.source_url } style={ { width: '100%' } } />
					) }
				</div>
			) }

			{ error && (
				<p role="alert" className="lb-sp-audio-panel__error">
					{ error }
				</p>
			) }

			<MediaUploadCheck>
				<MediaUpload
					onSelect={ onSelect }
					allowedTypes={ ALLOWED_TYPES }
					value={ audioId || undefined }
					render={ ( { open } ) => (
						<Button variant="secondary" onClick={ open }>
							{ audioId > 0
								? __( 'Reemplazar MP3', 'lb-simple-podcast' )
								: __( 'Seleccionar MP3', 'lb-simple-podcast' ) }
						</Button>
					) }
				/>
			</MediaUploadCheck>

			{ audioId > 0 && (
				<Button variant="link" isDestructive onClick={ onRemove } style={ { marginLeft: '8px' } }>
					{ __( 'Quitar audio', 'lb-simple-podcast' ) }
				</Button>
			) }
		</div>
	);
}
