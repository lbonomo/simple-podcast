import { registerBlockType } from '@wordpress/blocks';
import metadata from '../../inc/Frontend/block.json';
import StatsBlockEdit from './StatsBlockEdit';

registerBlockType( metadata.name, {
	...metadata,
	edit: StatsBlockEdit,
	save: () => null,
} );
