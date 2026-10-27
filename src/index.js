import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import Panel from './panel';

const config = window.spwWorkflow || {};

registerPlugin( 'spw-workflow', {
	render: () => (
		<PluginDocumentSettingPanel
			name="spw-workflow"
			title={ __( 'Review workflow', 'spw' ) }
			className="spw-workflow-panel"
		>
			<Panel canPublish={ !! config.canPublish } />
		</PluginDocumentSettingPanel>
	),
} );
