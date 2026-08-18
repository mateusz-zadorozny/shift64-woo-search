import { registerBlockType } from '@wordpress/blocks';
import metadata from './block.json';
import { legacyDeprecations, ParentEdit, saveParent } from '../shared/parent';
import '../search/editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	edit: ( props ) => ParentEdit( props, 'modal' ),
	save: saveParent,
	deprecated: legacyDeprecations( 'modal' ),
} );
