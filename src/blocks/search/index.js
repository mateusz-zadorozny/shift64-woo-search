import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import { legacyDeprecations, ParentEdit, saveParent } from '../shared/parent';
import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	edit: ( props ) => ParentEdit( props, 'inline' ),
	save: saveParent,
	deprecated: legacyDeprecations( 'inline' ),
} );
