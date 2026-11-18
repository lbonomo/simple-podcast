/* global lbSpDashboard, ResizeObserver */
( function () {
	'use strict';

	const SVG_NS = 'http://www.w3.org/2000/svg';

	function svgEl( tag, attrs ) {
		const el = document.createElementNS( SVG_NS, tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( key ) {
				el.setAttribute( key, String( attrs[ key ] ) );
			} );
		}
		return el;
	}

	function formatInt( value ) {
		return Number( value || 0 ).toLocaleString();
	}

	function niceMax( rawMax ) {
		if ( rawMax <= 0 ) {
			return 4;
		}
		const exp = Math.floor( Math.log10( rawMax ) );
		const base = Math.pow( 10, exp );
		const frac = rawMax / base;
		let niceFrac = 10;
		if ( frac <= 1 ) {
			niceFrac = 1;
		} else if ( frac <= 2 ) {
			niceFrac = 2;
		} else if ( frac <= 5 ) {
			niceFrac = 5;
		}
		const ceil = niceFrac * base;
		return Math.max( 4, Math.ceil( ceil / 4 ) * 4 );
	}

	function createTooltip() {
		const tip = document.createElement( 'div' );
		tip.className = 'lb-sp-tooltip';
		tip.setAttribute( 'role', 'tooltip' );
		tip.hidden = true;
		document.body.appendChild( tip );
		return tip;
	}

	function showTooltip( tip, target, title, lines, pubNotes ) {
		while ( tip.firstChild ) {
			tip.removeChild( tip.firstChild );
		}
		const titleEl = document.createElement( 'div' );
		titleEl.className = 'lb-sp-tooltip__title';
		titleEl.textContent = title;
		tip.appendChild( titleEl );

		lines.forEach( function ( line ) {
			const row = document.createElement( 'div' );
			row.textContent = line;
			tip.appendChild( row );
		} );

		if ( pubNotes && pubNotes.length ) {
			const pubEl = document.createElement( 'div' );
			pubEl.className = 'lb-sp-tooltip__pub';
			pubEl.textContent = pubNotes.join( ' · ' );
			tip.appendChild( pubEl );
		}

		tip.hidden = false;
		const rect = target.getBoundingClientRect();
		const tipRect = tip.getBoundingClientRect();
		let left = rect.left + rect.width / 2 - tipRect.width / 2;
		left = Math.max(
			8,
			Math.min( left, window.innerWidth - tipRect.width - 8 )
		);
		let top = rect.top - tipRect.height - 10;
		if ( top < 8 ) {
			top = rect.bottom + 10;
		}
		tip.style.left = Math.round( left ) + 'px';
		tip.style.top = Math.round( top ) + 'px';
	}

	function hideTooltip( tip ) {
		tip.hidden = true;
	}

	function buildPublishedMap( published, labelPrefix ) {
		const map = {};
		( published || [] ).forEach( function ( item ) {
			if ( ! map[ item.date ] ) {
				map[ item.date ] = [];
			}
			map[ item.date ].push( labelPrefix + ': ' + item.title );
		} );
		return map;
	}

	function renderTrendChart( container, data, tip ) {
		const daily = data.daily || [];
		if ( ! daily.length ) {
			return;
		}

		const i18n = data.i18n || {};
		const pubMap = buildPublishedMap(
			data.published,
			i18n.published || 'Publicado'
		);

		function getSeriesState() {
			const state = { listens: true, requests: true, noise: false };
			const inputs = document.querySelectorAll( '[data-lb-sp-series]' );
			inputs.forEach( function ( input ) {
				state[ input.getAttribute( 'data-lb-sp-series' ) ] = Boolean(
					input.checked
				);
			} );
			return state;
		}

		function draw() {
			const state = getSeriesState();
			const width = Math.max(
				420,
				Math.floor( container.clientWidth || 720 )
			);
			const height = 260;
			const padLeft = 44;
			const padRight = 16;
			const padTop = 18;
			const padBottom = 34;
			const plotW = width - padLeft - padRight;
			const plotH = height - padTop - padBottom;

			let maxVal = 0;
			daily.forEach( function ( d ) {
				if ( state.listens ) {
					maxVal = Math.max( maxVal, d.listens || 0 );
				}
				if ( state.requests ) {
					maxVal = Math.max( maxVal, d.requests || 0 );
				}
				if ( state.noise ) {
					maxVal = Math.max( maxVal, d.noise || 0 );
				}
			} );
			const yMax = niceMax( maxVal );

			function xFor( index ) {
				if ( daily.length === 1 ) {
					return padLeft + plotW / 2;
				}
				return padLeft + ( index / ( daily.length - 1 ) ) * plotW;
			}

			function yFor( val ) {
				return padTop + plotH - ( val / yMax ) * plotH;
			}

			const svg = svgEl( 'svg', {
				viewBox: '0 0 ' + width + ' ' + height,
				role: 'img',
				'aria-label': i18n.trendTitle || 'Evolución diaria',
			} );
			const svgTitle = svgEl( 'title' );
			svgTitle.textContent = i18n.trendTitle || 'Evolución diaria';
			svg.appendChild( svgTitle );

			// Eje Y y líneas guía.
			const steps = 4;
			for ( let s = 0; s <= steps; s++ ) {
				const tickVal = Math.round( ( yMax / steps ) * s );
				const yPos = yFor( tickVal );
				svg.appendChild(
					svgEl( 'line', {
						x1: padLeft,
						y1: yPos,
						x2: width - padRight,
						y2: yPos,
						class: 'lb-sp-svg-grid',
					} )
				);
				const yLabel = svgEl( 'text', {
					x: padLeft - 8,
					y: yPos + 4,
					'text-anchor': 'end',
					class: 'lb-sp-svg-axis-label',
				} );
				yLabel.textContent = formatInt( tickVal );
				svg.appendChild( yLabel );
			}

			// Etiquetas X adaptativas.
			const maxLabels = Math.max( 2, Math.floor( plotW / 76 ) );
			const labelStep = Math.max(
				1,
				Math.ceil( daily.length / maxLabels )
			);
			daily.forEach( function ( d, idx ) {
				if ( idx % labelStep === 0 || idx === daily.length - 1 ) {
					let anchor = 'middle';
					if ( idx === 0 ) {
						anchor = 'start';
					} else if ( idx === daily.length - 1 ) {
						anchor = 'end';
					}
					const xText = svgEl( 'text', {
						x: xFor( idx ),
						y: height - 10,
						'text-anchor': anchor,
						class: 'lb-sp-svg-axis-label',
					} );
					xText.textContent = d.label || d.date;
					svg.appendChild( xText );
				}
			} );

			// Marcadores de publicación.
			daily.forEach( function ( d, idx ) {
				if ( pubMap[ d.date ] ) {
					const px = xFor( idx );
					svg.appendChild(
						svgEl( 'line', {
							x1: px,
							y1: padTop,
							x2: px,
							y2: padTop + plotH,
							class: 'lb-sp-svg-pub-line',
						} )
					);
					svg.appendChild(
						svgEl( 'circle', {
							cx: px,
							cy: padTop,
							r: 3.5,
							class: 'lb-sp-svg-pub-pin',
						} )
					);
				}
			} );

			// Barras de ruido (HEAD + bots).
			if ( state.noise ) {
				const barW = Math.max(
					3,
					Math.min( 12, ( plotW / daily.length ) * 0.55 )
				);
				daily.forEach( function ( d, idx ) {
					if ( d.noise > 0 ) {
						const topY = yFor( d.noise );
						svg.appendChild(
							svgEl( 'rect', {
								x: xFor( idx ) - barW / 2,
								y: topY,
								width: barW,
								height: Math.max( 1, padTop + plotH - topY ),
								fill: '#dba617',
								'fill-opacity': '0.35',
								rx: 1.5,
							} )
						);
					}
				} );
			}

			// Serie secundaria: Solicitudes GET (discontinua).
			if ( state.requests ) {
				const reqPoints = daily
					.map( function ( d, idx ) {
						return (
							xFor( idx ).toFixed( 1 ) +
							',' +
							yFor( d.requests ).toFixed( 1 )
						);
					} )
					.join( ' ' );
				svg.appendChild(
					svgEl( 'polyline', {
						fill: 'none',
						stroke: '#646970',
						'stroke-width': '2',
						'stroke-dasharray': '5 4',
						points: reqPoints,
					} )
				);
			}

			// Serie principal: Escuchas estimadas (área + línea).
			if ( state.listens ) {
				const listenCoords = daily.map( function ( d, idx ) {
					return [ xFor( idx ), yFor( d.listens ) ];
				} );
				const areaPath =
					'M ' +
					listenCoords[ 0 ][ 0 ].toFixed( 1 ) +
					' ' +
					( padTop + plotH ).toFixed( 1 ) +
					' ' +
					listenCoords
						.map( function ( p ) {
							return (
								'L ' +
								p[ 0 ].toFixed( 1 ) +
								' ' +
								p[ 1 ].toFixed( 1 )
							);
						} )
						.join( ' ' ) +
					' L ' +
					listenCoords[ listenCoords.length - 1 ][ 0 ].toFixed( 1 ) +
					' ' +
					( padTop + plotH ).toFixed( 1 ) +
					' Z';
				svg.appendChild(
					svgEl( 'path', {
						d: areaPath,
						fill: '#2271b1',
						'fill-opacity': '0.12',
					} )
				);
				svg.appendChild(
					svgEl( 'polyline', {
						fill: 'none',
						stroke: '#2271b1',
						'stroke-width': '2.5',
						'stroke-linejoin': 'round',
						'stroke-linecap': 'round',
						points: listenCoords
							.map( function ( p ) {
								return (
									p[ 0 ].toFixed( 1 ) +
									',' +
									p[ 1 ].toFixed( 1 )
								);
							} )
							.join( ' ' ),
					} )
				);
			}

			// Puntos interactivos accesibles.
			daily.forEach( function ( d, idx ) {
				const cx = xFor( idx );
				let primaryVal = d.noise;
				if ( state.listens ) {
					primaryVal = d.listens;
				} else if ( state.requests ) {
					primaryVal = d.requests;
				}
				const cy = yFor( primaryVal );
				const pubs = pubMap[ d.date ] || [];
				const lines = [
					( i18n.listens || 'Escuchas estimadas' ) +
						': ' +
						formatInt( d.listens ),
					( i18n.requests || 'Solicitudes GET' ) +
						': ' +
						formatInt( d.requests ),
				];
				if ( state.noise || d.noise > 0 ) {
					lines.push(
						( i18n.noise || 'HEAD + bots' ) +
							': ' +
							formatInt( d.noise )
					);
				}

				const group = svgEl( 'g', {
					class: 'lb-sp-svg-point',
					tabindex: '0',
					role: 'button',
					'aria-label':
						( d.label || d.date ) +
						' — ' +
						lines.concat( pubs ).join( ', ' ),
				} );

				group.appendChild(
					svgEl( 'circle', {
						cx,
						cy,
						r: 10,
						fill: 'transparent',
					} )
				);
				group.appendChild(
					svgEl( 'circle', {
						cx,
						cy,
						r: d.listens > 0 || d.requests > 0 ? 3.5 : 2,
						fill: state.listens ? '#2271b1' : '#646970',
						stroke: '#ffffff',
						'stroke-width': '1.5',
						class: 'lb-sp-svg-dot',
					} )
				);

				const handleOpen = function () {
					showTooltip(
						tip,
						group,
						d.date + ' (' + ( d.label || '' ) + ')',
						lines,
						pubs
					);
				};
				const handleClose = function () {
					hideTooltip( tip );
				};

				group.addEventListener( 'mouseenter', handleOpen );
				group.addEventListener( 'focus', handleOpen );
				group.addEventListener( 'mouseleave', handleClose );
				group.addEventListener( 'blur', handleClose );
				svg.appendChild( group );
			} );

			while ( container.firstChild ) {
				container.removeChild( container.firstChild );
			}
			container.appendChild( svg );
		}

		draw();

		document
			.querySelectorAll( '[data-lb-sp-series]' )
			.forEach( function ( input ) {
				input.addEventListener( 'change', draw );
			} );

		if ( typeof ResizeObserver === 'function' ) {
			const ro = new ResizeObserver( function () {
				draw();
			} );
			ro.observe( container );
		} else {
			window.addEventListener( 'resize', draw );
		}
	}

	function renderClientsDonut( container, data, tip ) {
		const clients = data.clients || [];
		if ( ! clients.length ) {
			return;
		}
		const i18n = data.i18n || {};
		const total = clients.reduce( function ( sum, c ) {
			return sum + ( c.listens || 0 );
		}, 0 );
		if ( total <= 0 ) {
			return;
		}

		const size = 160;
		const cx = 80;
		const cy = 80;
		const radius = 56;
		const circumference = 2 * Math.PI * radius;
		let offset = 0;

		const svg = svgEl( 'svg', {
			viewBox: '0 0 ' + size + ' ' + size,
			role: 'img',
			'aria-label': i18n.clientTitle || 'Clientes y reproductores',
		} );
		const title = svgEl( 'title' );
		title.textContent = i18n.clientTitle || 'Clientes y reproductores';
		svg.appendChild( title );

		clients.forEach( function ( client ) {
			const fraction = client.listens / total;
			const segLen = fraction * circumference;
			const seg = svgEl( 'circle', {
				cx,
				cy,
				r: radius,
				fill: 'none',
				stroke: client.color || '#2271b1',
				'stroke-width': '24',
				'stroke-dasharray':
					segLen.toFixed( 2 ) + ' ' + circumference.toFixed( 2 ),
				'stroke-dashoffset': ( -offset ).toFixed( 2 ),
				transform: 'rotate(-90 ' + cx + ' ' + cy + ')',
				class: 'lb-sp-donut-segment',
				tabindex: '0',
				role: 'button',
				'aria-label':
					client.label +
					': ' +
					formatInt( client.listens ) +
					' (' +
					client.percent +
					'%)',
			} );

			const lines = [
				( i18n.listens || 'Escuchas estimadas' ) +
					': ' +
					formatInt( client.listens ) +
					' (' +
					client.percent +
					'%)',
				( i18n.requests || 'Solicitudes GET' ) +
					': ' +
					formatInt( client.requests ),
			];
			seg.addEventListener( 'mouseenter', function () {
				showTooltip( tip, seg, client.label, lines );
			} );
			seg.addEventListener( 'focus', function () {
				showTooltip( tip, seg, client.label, lines );
			} );
			seg.addEventListener( 'mouseleave', function () {
				hideTooltip( tip );
			} );
			seg.addEventListener( 'blur', function () {
				hideTooltip( tip );
			} );

			svg.appendChild( seg );
			offset += segLen;
		} );

		const centerVal = svgEl( 'text', {
			x: cx,
			y: cy + 3,
			'text-anchor': 'middle',
			class: 'lb-sp-donut-total',
		} );
		centerVal.textContent = formatInt( total );
		svg.appendChild( centerVal );

		const centerSub = svgEl( 'text', {
			x: cx,
			y: cy + 19,
			'text-anchor': 'middle',
			class: 'lb-sp-donut-sub',
		} );
		centerSub.textContent = i18n.totalPlays || 'Escuchas';
		svg.appendChild( centerSub );

		container.appendChild( svg );
	}

	function init() {
		if ( typeof lbSpDashboard !== 'object' || ! lbSpDashboard ) {
			return;
		}
		const tip = createTooltip();
		const trendContainer = document.getElementById( 'lb-sp-trend-chart' );
		if ( trendContainer ) {
			renderTrendChart( trendContainer, lbSpDashboard, tip );
		}
		const donutContainer = document.getElementById( 'lb-sp-clients-chart' );
		if ( donutContainer ) {
			renderClientsDonut( donutContainer, lbSpDashboard, tip );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
