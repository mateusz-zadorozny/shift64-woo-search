export function isEnabled( value ) {
	if ( value === undefined || value === null ) {
		return true;
	}
	return ! [ false, 0, '', '0', 'no', 'false' ].includes( value );
}

export function safeUrl( source, base, { sameOrigin = false } = {} ) {
	try {
		const url = new URL( source, base );
		const baseUrl = new URL( base );
		if ( ! [ 'http:', 'https:' ].includes( url.protocol ) ) {
			return '';
		}
		return ! sameOrigin || url.origin === baseUrl.origin
			? url.toString()
			: '';
	} catch {
		return '';
	}
}

function firstLabel( value ) {
	return typeof value === 'string' ? value.split( '|' )[ 0 ].trim() : '';
}

export function normalizeResponse( data, config, query ) {
	const sections = [];
	const addSection = ( slug, label, items ) => {
		const validItems = items.filter( ( item ) => item.url && item.label );
		if ( validItems.length ) {
			sections.push( { slug, label, items: validItems } );
		}
	};
	const fallbackTemplate =
		config.fallbackUrl || '/?s={query}&post_type=product';

	addSection(
		'suggestions',
		config.suggestionsHeaderText,
		( data.suggestions || [] ).map( ( suggestion ) => ( {
			type: 'suggestion',
			label: String( suggestion ),
			url: safeUrl(
				fallbackTemplate.replace(
					'{query}',
					encodeURIComponent( suggestion )
				),
				config.searchUrl,
				{ sameOrigin: true }
			),
		} ) )
	);
	addSection(
		'categories',
		config.categoriesHeaderText,
		( data.categories || [] ).map( ( category ) => ( {
			type: 'category',
			label: String( category.name || '' ),
			url: safeUrl( category.url, config.searchUrl, {
				sameOrigin: true,
			} ),
		} ) )
	);
	addSection(
		'brands',
		config.brandsHeaderText,
		( data.brands || [] ).map( ( brand ) => ( {
			type: 'brand',
			label: String( brand.name || '' ),
			url: safeUrl( brand.url, config.searchUrl, { sameOrigin: true } ),
		} ) )
	);
	addSection(
		'products',
		config.productsHeaderText,
		( data.results || [] ).map( ( product ) => ( {
			type: 'product',
			label: String( product.title || '' ),
			url: safeUrl( product.url, config.searchUrl, { sameOrigin: true } ),
			image: product.image
				? safeUrl( product.image, config.searchUrl )
				: '',
			sku: isEnabled( config.showSku ) ? String( product.sku || '' ) : '',
			category: isEnabled( config.showCategory )
				? firstLabel( product.category )
				: '',
			brand: isEnabled( config.showBrand )
				? firstLabel( product.brand )
				: '',
		} ) )
	);

	return {
		query: String( data.query || query || '' ),
		sections,
		items: sections.flatMap( ( section ) => section.items ),
	};
}

export function moveActiveIndex( current, key, itemCount ) {
	if ( itemCount < 1 ) {
		return -1;
	}
	if ( key === 'ArrowDown' ) {
		return Math.min( current + 1, itemCount - 1 );
	}
	if ( key === 'ArrowUp' ) {
		return Math.max( current - 1, -1 );
	}
	return current;
}
