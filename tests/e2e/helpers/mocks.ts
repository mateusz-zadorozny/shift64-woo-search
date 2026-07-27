/**
 * Canned endpoint payloads for route-mocked failure-mode tests.
 *
 * These mirror the real contracts in mu-plugins/endpoint.php exactly:
 * - Redis unavailable → HTTP 200, success:false + fallback (endpoint.php:110-122)
 * - Rate limited     → HTTP 429 + Retry-After: 1 (endpoint.php:124-139)
 *
 * If endpoint.php changes shape, update these AND the corresponding client
 * behavior assertions in specs/failure-modes.spec.ts.
 */

export function redisDownPayload(query: string) {
	return {
		success: false,
		count: 0,
		query,
		time_ms: 0,
		results: [],
		fallback: `/?s=${encodeURIComponent(query)}&post_type=product`,
	};
}

export const rateLimitedPayload = {
	success: false,
	error: 'Too many requests.',
};

export const rateLimitedResponse = {
	status: 429,
	headers: { 'Retry-After': '1' },
	contentType: 'application/json; charset=utf-8',
	body: JSON.stringify(rateLimitedPayload),
};
