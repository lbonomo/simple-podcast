import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';
import * as editor from '@wordpress/editor';
import * as editPost from '@wordpress/edit-post';
import AudioPanel from './AudioPanel';

// WP >= 6.6 expone el panel en @wordpress/editor; antes estaba en @wordpress/edit-post.
const PluginDocumentSettingPanel =
	editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;

registerPlugin( 'lb-simple-podcast', {
	render: () => (
		<PluginDocumentSettingPanel
			name="lb-sp-audio"
			title={ __( 'Podcast (audio MP3)', 'lb-simple-podcast' ) }
			className="lb-sp-audio-panel-wrapper"
		>
			<AudioPanel />
		</PluginDocumentSettingPanel>
	),
} );
