'use strict';

// Read-only HTTP cache probe for a staging wiki. No MediaWiki edits, purges,
// cookies or authentication. Requires Node.js 20+.
const fs = require('node:fs');
const crypto = require('node:crypto');

const SURFACE_NAMES = ['vector', 'minerva', 'fragment_vector', 'fragment_minerva'];
const MOBILE_UA = 'Mozilla/5.0 (Linux; Android 12; Mobile) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';

function parseOptions(args) {
	const allowed = new Set([
		'base', 'title', 'script-path', 'iterations', 'expect-html',
		'expect-fragment', 'baseline', 'out', 'require-changed'
	]);
	const input = {};
	for (const item of args) {
		if (!item.startsWith('--') || item.indexOf('=') <= 2) {
			throw new Error('Use --key=value arguments; no page mutations are supported.');
		}
		const index = item.indexOf('=');
		const key = item.slice(2, index);
		if (!allowed.has(key) || Object.hasOwn(input, key)) {
			throw new Error('Unrecognized or repeated argument: ' + key);
		}
		input[key] = item.slice(index + 1);
	}
	if (!input.base || !input.title || input.title.length > 250) {
		throw new Error('Both --base and --title are required.');
	}
	const base = new URL(input.base);
	if (base.username || base.password || base.search || base.hash ||
		base.pathname !== '/' || !base.hostname) {
		throw new Error('--base must be an origin without credentials, path or query.');
	}
	const loopback = ['localhost', '127.0.0.1', '[::1]'].includes(base.hostname);
	if (base.protocol !== 'https:' && !(base.protocol === 'http:' && loopback)) {
		throw new Error('HTTPS is mandatory outside loopback tests.');
	}
	const scriptPath = input['script-path'] || '/w';
	if (!/^\/[A-Za-z0-9_/-]+$/.test(scriptPath) ||
		scriptPath.split('/').includes('..') || scriptPath.endsWith('/')) {
		throw new Error('--script-path must be an absolute simple path, e.g. /w.');
	}
	const iterations = input.iterations === undefined ? 2 : Number(input.iterations);
	if (!Number.isInteger(iterations) || iterations < 1 || iterations > 4) {
		throw new Error('--iterations must be 1..4.');
	}
	const requireChanged = input['require-changed']
		? input['require-changed'].split(',') : [];
	if (requireChanged.some(name => !SURFACE_NAMES.includes(name))) {
		throw new Error('--require-changed contains an unknown surface.');
	}
	if (requireChanged.length && !input.baseline) {
		throw new Error('--require-changed requires --baseline=previous.json.');
	}
	return {
		base: base.origin,
		title: input.title,
		scriptPath,
		iterations,
		expectHtml: input['expect-html'] || null,
		expectFragment: input['expect-fragment'] || null,
		baseline: input.baseline || null,
		output: input.out || null,
		requireChanged
	};
}

function makeSurfaces(options) {
	const base = options.base;
	const pageUrl = new URL(base + options.scriptPath + '/index.php');
	const apiUrl = new URL(base + options.scriptPath + '/api.php');
	const surfaces = [];
	for (const skin of ['vector', 'minerva']) {
		const page = new URL(pageUrl);
		page.searchParams.set('title', options.title);
		page.searchParams.set('useskin', skin);
		surfaces.push({
			name: skin,
			url: page,
			api: false,
			userAgent: skin === 'minerva' ? MOBILE_UA : null
		});
		const api = new URL(apiUrl);
		api.searchParams.set('action', 'wikidebatesreading');
		api.searchParams.set('page', options.title);
		api.searchParams.set('useskin', skin);
		api.searchParams.set('format', 'json');
		api.searchParams.set('formatversion', '2');
		surfaces.push({
			name: 'fragment_' + skin,
			url: api,
			api: true,
			userAgent: skin === 'minerva' ? MOBILE_UA : null
		});
	}
	return surfaces;
}

async function fetchSurface(surface, origin, fetchFn) {
	let url = surface.url;
	let response;
	for (let redirect = 0; redirect <= 3; redirect++) {
		response = await fetchFn(url, {
			method: 'GET',
			redirect: 'manual',
			signal: AbortSignal.timeout(20000),
			headers: {
				'accept': surface.api ? 'application/json' : 'text/html',
				...(surface.userAgent ? { 'user-agent': surface.userAgent } : {})
			}
		});
		if (response.status >= 300 && response.status < 400) {
			const location = response.headers.get('location');
			if (!location) {
				throw new Error('Redirect without Location for ' + surface.name);
			}
			url = new URL(location, url);
			if (url.origin !== origin) {
				throw new Error('Cross-origin redirect refused for ' + surface.name);
			}
			continue;
		}
		break;
	}
	if (!response || (response.status >= 300 && response.status < 400)) {
		throw new Error('Too many redirects for ' + surface.name);
	}
	if (!response.ok) {
		throw new Error('HTTP ' + response.status + ' for ' + surface.name);
	}
	const body = await response.text();
	let html = body;
	let source = null;
	if (surface.api) {
		let json;
		try {
			json = JSON.parse(body);
		} catch {
			throw new Error('Invalid JSON fragment for ' + surface.name);
		}
		const fragment = json.wikidebatesreading;
		if (!fragment || typeof fragment.html !== 'string') {
			throw new Error('Missing wikidebatesreading HTML for ' + surface.name);
		}
		html = fragment.html;
		source = typeof fragment.source === 'string' ? fragment.source : null;
	}
	const headers = {};
	for (const header of [
		'cache-control', 'cf-cache-status', 'age', 'x-cache',
		'etag', 'last-modified', 'vary', 'content-type'
	]) {
		const value = response.headers.get(header);
		if (value !== null) {
			headers[header] = value;
		}
	}
	return {
		html,
		metadata: {
			status: response.status,
			sha256: crypto.createHash('sha256').update(html).digest('hex'),
			bytes: Buffer.byteLength(html),
			source,
			headers
		}
	};
}

async function runProbe(options, overrides = {}) {
	const fetchFn = overrides.fetchFn || fetch;
	const baseline = overrides.baselineReport ||
		(options.baseline ? JSON.parse(fs.readFileSync(options.baseline, 'utf8')) : null);
	if (baseline && (baseline.title !== options.title || baseline.base !== options.base)) {
		throw new Error('Baseline title or site origin does not match this probe.');
	}
	const results = {};
	const failures = [];
	for (const surface of makeSurfaces(options)) {
		const samples = [];
		const expected = surface.api ? options.expectFragment : options.expectHtml;
		for (let i = 0; i < options.iterations; i++) {
			const result = await fetchSurface(surface, options.base, fetchFn);
			samples.push({
				...result.metadata,
				marker_present: expected === null ? null : result.html.includes(expected)
			});
		}
		const last = samples[samples.length - 1];
		const stable = samples.every(sample => sample.sha256 === samples[0].sha256);
		const previous = baseline?.results?.[surface.name]?.last?.sha256;
		const changed = typeof previous === 'string' ? last.sha256 !== previous : null;
		if (expected !== null && samples.some(sample => !sample.marker_present)) {
			failures.push('Expected text missing in at least one response: ' + surface.name);
		}
		if (options.requireChanged.includes(surface.name) && changed !== true) {
			failures.push('Expected a changed response compared with baseline: ' + surface.name);
		}
		results[surface.name] = { stable, changed_from_baseline: changed, samples, last };
	}
	return {
		schema_version: 1,
		type: 'semantic_http_cache_observation',
		base: options.base,
		title: options.title,
		script_path: options.scriptPath,
		iterations: options.iterations,
		surfaces: SURFACE_NAMES,
		results,
		passed: failures.length === 0,
		failures,
		note: 'Anonymous GET-only observations; unchanged hashes alone do not prove stale content. Use controlled staging edits and explicit expected markers.'
	};
}

async function main() {
	const opts = parseOptions(process.argv.slice(2));
	const report = await runProbe(opts);
	const rendered = JSON.stringify(report, null, 2) + '\n';
	if (opts.output) {
		fs.writeFileSync(opts.output, rendered, { mode: 0o600 });
	}
	process.stdout.write(rendered);
	if (!report.passed) {
		process.exitCode = 2;
	}
}

if (require.main === module) {
	main().catch(error => {
		process.stderr.write('Semantic HTTP probe: ' + error.message + '\n');
		process.exitCode = 1;
	});
}

module.exports = { parseOptions, makeSurfaces, runProbe };
