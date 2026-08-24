import { globSync, readFileSync } from 'node:fs';

const metadataFiles = globSync( 'src/blocks/*/block.json' ).sort();

const searchParents = [
	'shift64-woo-search/search',
	'shift64-woo-search/modal-search',
];
const filtersParent = [ 'shift64-woo-search/product-filters' ];

// Every registered block and its structural contract:
// - interactive parents must ship a view module;
// - template-locked search children stay out of every inserter;
// - the repeatable Filter Pill is constrained to its parent but stays
//   insertable there (merchants add/remove/reorder pills themselves).
const contracts = {
	'shift64-woo-search/search': { kind: 'interactive-parent' },
	'shift64-woo-search/modal-search': { kind: 'interactive-parent' },
	'shift64-woo-search/search-control': {
		kind: 'locked-child',
		parents: searchParents,
	},
	'shift64-woo-search/search-panel': {
		kind: 'locked-child',
		parents: searchParents,
	},
	'shift64-woo-search/product-filters': { kind: 'interactive-parent' },
	'shift64-woo-search/filter-pill': {
		kind: 'repeatable-child',
		parents: filtersParent,
	},
};

const expectedNames = new Set( Object.keys( contracts ) );
const seenNames = new Set();

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

	const contract = contracts[ metadata.name ];
	if ( contract.kind === 'interactive-parent' ) {
		if (
			! metadata.viewScriptModule ||
			metadata.supports?.interactivity !== true
		) {
			throw new Error(
				`${ file } must declare its interactive view module.`
			);
		}
	} else {
		if (
			JSON.stringify( metadata.parent ) !==
			JSON.stringify( contract.parents )
		) {
			throw new Error(
				`${ file } must be restricted to its declared parents.`
			);
		}
		if (
			JSON.stringify( metadata.ancestor ) !==
			JSON.stringify( contract.parents )
		) {
			throw new Error(
				`${ file } must declare its parents as ancestors.`
			);
		}
		if (
			contract.kind === 'locked-child' &&
			metadata.supports?.inserter !== false
		) {
			throw new Error(
				`${ file } must stay hidden from the standalone inserter.`
			);
		}
		if (
			contract.kind === 'repeatable-child' &&
			metadata.supports?.inserter === false
		) {
			throw new Error(
				`${ file } must stay insertable inside its parent.`
			);
		}
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
