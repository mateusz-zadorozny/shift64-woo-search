import { globSync, readFileSync } from 'node:fs';

const metadataFiles = globSync( 'src/blocks/*/block.json' ).sort();
const expectedNames = new Set( [
	'shift64-woo-search/modal-search',
	'shift64-woo-search/search',
	'shift64-woo-search/search-control',
	'shift64-woo-search/search-panel',
] );
const seenNames = new Set();
const parentNames = [
	'shift64-woo-search/search',
	'shift64-woo-search/modal-search',
];

if ( metadataFiles.length !== expectedNames.size ) {
	throw new Error(
		`Expected ${ expectedNames.size } block.json files, found ${ metadataFiles.length }.`
	);
}

for ( const file of metadataFiles ) {
	const metadata = JSON.parse( readFileSync( file, 'utf8' ) );
	if ( metadata.apiVersion !== 3 ) {
		throw new Error( `${ file } must declare apiVersion 3.` );
	}
	if ( ! expectedNames.has( metadata.name ) ) {
		throw new Error(
			`${ file } has unexpected block name ${ metadata.name }.`
		);
	}
	if ( seenNames.has( metadata.name ) ) {
		throw new Error( `Duplicate block name ${ metadata.name }.` );
	}
	if (
		! metadata.textdomain ||
		metadata.textdomain !== 'shift64-woo-search'
	) {
		throw new Error( `${ file } must declare the plugin text domain.` );
	}
	if (
		metadata.name.endsWith( 'search-control' ) ||
		metadata.name.endsWith( 'search-panel' )
	) {
		if (
			JSON.stringify( metadata.parent ) !== JSON.stringify( parentNames )
		) {
			throw new Error(
				`${ file } must be restricted to both search parents.`
			);
		}
		if (
			JSON.stringify( metadata.ancestor ) !==
			JSON.stringify( parentNames )
		) {
			throw new Error(
				`${ file } must declare both search parents as ancestors.`
			);
		}
		if ( metadata.supports?.inserter !== false ) {
			throw new Error(
				`${ file } must stay hidden from the standalone inserter.`
			);
		}
	} else if (
		! metadata.viewScriptModule ||
		metadata.supports?.interactivity !== true
	) {
		throw new Error(
			`${ file } must declare its interactive view module.`
		);
	}
	seenNames.add( metadata.name );
}

for ( const name of expectedNames ) {
	if ( ! seenNames.has( name ) ) {
		throw new Error( `Missing block metadata for ${ name }.` );
	}
}

process.stdout.write(
	`Validated ${ metadataFiles.length } block metadata files.\n`
);
