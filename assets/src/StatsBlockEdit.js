import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEntityRecords } from '@wordpress/core-data';
import ServerSideRender from '@wordpress/server-side-render';

const BLOCK_NAME = 'lb-simple-podcast/podcast-stats';

function EmptyStatsPreview() {
	return (
		<Placeholder
			icon="chart-bar"
			label={ __( 'Estadísticas del podcast', 'lb-simple-podcast' ) }
			instructions={ __(
				'Las estadísticas públicas solo se muestran para episodios publicados sin contraseña y con audio MP3 válido.',
				'lb-simple-podcast'
			) }
		/>
	);
}

export default function StatsBlockEdit( { attributes, setAttributes } ) {
	const {
		days = 30,
		scope = 'all',
		postId = 0,
		showListens = true,
		showListeners = true,
		showEpisodes = true,
		showTopEpisodes = false,
		showClients = false,
		limit = 5,
		title = '',
	} = attributes;

	const blockProps = useBlockProps();

	const { records, isResolving } = useEntityRecords(
		'postType',
		'post',
		{
			status: 'publish',
			per_page: 50,
			_fields: 'id,title,audio_data',
		},
		{ enabled: 'episode' === scope }
	);

	const episodes = Array.isArray( records )
		? records.filter( ( item ) => Boolean( item && item.audio_data ) )
		: [];

	const episodeOptions = [
		{
			label: isResolving
				? __( 'Cargando episodios…', 'lb-simple-podcast' )
				: __( 'Selecciona un episodio…', 'lb-simple-podcast' ),
			value: '0',
		},
		...episodes.map( ( ep ) => ( {
			label:
				ep.title?.rendered ||
				/* translators: %d: ID de la entrada. */
				`#${ ep.id }`,
			value: String( ep.id ),
		} ) ),
	];

	const hasVisibleMetric =
		Boolean( showListens ) ||
		Boolean( showListeners ) ||
		Boolean( showClients ) ||
		( 'all' === scope &&
			( Boolean( showEpisodes ) || Boolean( showTopEpisodes ) ) );

	let preview;
	if ( 'episode' === scope && Number( postId ) <= 0 ) {
		preview = (
			<Placeholder
				icon="chart-bar"
				label={ __( 'Estadísticas del podcast', 'lb-simple-podcast' ) }
				instructions={ __(
					'Selecciona un episodio publicado con audio en el panel lateral del bloque.',
					'lb-simple-podcast'
				) }
			/>
		);
	} else if ( ! hasVisibleMetric ) {
		preview = (
			<Placeholder
				icon="chart-bar"
				label={ __( 'Estadísticas del podcast', 'lb-simple-podcast' ) }
				instructions={ __(
					'Activa al menos una métrica visible en el panel lateral.',
					'lb-simple-podcast'
				) }
			/>
		);
	} else {
		preview = (
			<ServerSideRender
				block={ BLOCK_NAME }
				attributes={ attributes }
				EmptyResponsePlaceholder={ EmptyStatsPreview }
			/>
		);
	}

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Configuración general', 'lb-simple-podcast' ) }
					initialOpen={ true }
				>
					<TextControl
						label={ __( 'Título (opcional)', 'lb-simple-podcast' ) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
					/>
					<SelectControl
						label={ __( 'Período', 'lb-simple-podcast' ) }
						value={ String( days ) }
						options={ [
							{
								label: __(
									'Últimos 7 días',
									'lb-simple-podcast'
								),
								value: '7',
							},
							{
								label: __(
									'Últimos 30 días',
									'lb-simple-podcast'
								),
								value: '30',
							},
							{
								label: __(
									'Últimos 90 días',
									'lb-simple-podcast'
								),
								value: '90',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { days: Number( value ) || 30 } )
						}
					/>
					<SelectControl
						label={ __( 'Alcance', 'lb-simple-podcast' ) }
						value={ scope }
						options={ [
							{
								label: __(
									'Todo el podcast',
									'lb-simple-podcast'
								),
								value: 'all',
							},
							{
								label: __(
									'Episodio actual',
									'lb-simple-podcast'
								),
								value: 'current',
							},
							{
								label: __(
									'Episodio específico',
									'lb-simple-podcast'
								),
								value: 'episode',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( {
								scope: value,
								postId: 'episode' === value ? postId : 0,
							} )
						}
					/>
					{ 'episode' === scope && (
						<SelectControl
							label={ __( 'Episodio', 'lb-simple-podcast' ) }
							value={ String( postId || 0 ) }
							options={ episodeOptions }
							onChange={ ( value ) =>
								setAttributes( {
									postId: Number( value ) || 0,
								} )
							}
						/>
					) }
				</PanelBody>

				<PanelBody
					title={ __( 'Métricas visibles', 'lb-simple-podcast' ) }
					initialOpen={ true }
				>
					<ToggleControl
						label={ __(
							'Escuchas estimadas',
							'lb-simple-podcast'
						) }
						checked={ Boolean( showListens ) }
						onChange={ ( value ) =>
							setAttributes( { showListens: Boolean( value ) } )
						}
					/>
					<ToggleControl
						label={ __( 'Oyentes únicos', 'lb-simple-podcast' ) }
						checked={ Boolean( showListeners ) }
						onChange={ ( value ) =>
							setAttributes( { showListeners: Boolean( value ) } )
						}
					/>
					{ 'all' === scope && (
						<>
							<ToggleControl
								label={ __(
									'Episodios publicados',
									'lb-simple-podcast'
								) }
								checked={ Boolean( showEpisodes ) }
								onChange={ ( value ) =>
									setAttributes( {
										showEpisodes: Boolean( value ),
									} )
								}
							/>
							<ToggleControl
								label={ __(
									'Episodios más escuchados',
									'lb-simple-podcast'
								) }
								checked={ Boolean( showTopEpisodes ) }
								onChange={ ( value ) =>
									setAttributes( {
										showTopEpisodes: Boolean( value ),
									} )
								}
							/>
							{ Boolean( showTopEpisodes ) && (
								<RangeControl
									label={ __(
										'Cantidad en el ranking',
										'lb-simple-podcast'
									) }
									value={ limit }
									onChange={ ( value ) =>
										setAttributes( {
											limit: Number( value ) || 5,
										} )
									}
									min={ 1 }
									max={ 10 }
								/>
							) }
						</>
					) }
					<ToggleControl
						label={ __(
							'Aplicaciones y reproductores',
							'lb-simple-podcast'
						) }
						checked={ Boolean( showClients ) }
						onChange={ ( value ) =>
							setAttributes( { showClients: Boolean( value ) } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			{ preview }
		</div>
	);
}
