'use strict';

const assert = require('node:assert/strict');
const http = require('node:http');
const test = require('node:test');

const { parseOptions, makeSurfaces, runProbe } = require('../../maintenance/probeSemanticHttp.cjs');

test('HTTP cache probe reads real GET surfaces without editing pages', async () => {
	let value = 'before-marker';
	const requests = [];
	const server = http.createServer((request, response) => {
		const url = new URL(request.url, 'http://127.0.0.1');
		requests.push({ method: request.method, path: url.pathname,
			pageTitle: url.searchParams.get('title') || url.searchParams.get('page') });
		response.setHeader('Cache-Control', 'private, max-age=0');
		response.setHeader('CF-Cache-Status', 'DYNAMIC');
		response.setHeader('X-Cache', 'MISS');
		response.setHeader('Content-Type', url.pathname.endsWith('/api.php')
			? 'application/json' : 'text/html; charset=utf-8');
		if (url.pathname.endsWith('/api.php')) {
			response.end(JSON.stringify({
				wikidebatesreading: {
					html: '<div>' + value + '</div>',
					source: 'fragment-cache'
				}
			}));
		} else {
			response.end('<html><main>' + value + '</main></html>');
		}
	});
	await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
	try {
		const origin = 'http://127.0.0.1:' + server.address().port;
		const title = 'Dieu existe-t-il ?';
		const beforeOptions = parseOptions([
			'--base=' + origin, '--title=' + title, '--iterations=2',
			'--expect-html=before-marker', '--expect-fragment=before-marker'
		]);
		assert.equal(makeSurfaces(beforeOptions).length, 4);
		const before = await runProbe(beforeOptions);
		assert.equal(before.passed, true);
		assert.equal(before.results.vector.stable, true);
		assert.equal(before.results.fragment_minerva.last.source, 'fragment-cache');
		assert.equal(before.results.minerva.last.headers['cf-cache-status'], 'DYNAMIC');
		assert.equal(before.results.vector.samples.length, 2);
		assert.ok(requests.every(request => request.method === 'GET' && request.pageTitle === title));

		value = 'after-marker';
		const afterOptions = parseOptions([
			'--base=' + origin, '--title=' + title, '--iterations=2',
			'--expect-html=after-marker', '--expect-fragment=after-marker',
			'--baseline=disposable-before.json',
			'--require-changed=vector,minerva,fragment_vector,fragment_minerva'
		]);
		const after = await runProbe(afterOptions, { baselineReport: before });
		assert.equal(after.passed, true);
		for (const result of Object.values(after.results)) {
			assert.equal(result.changed_from_baseline, true);
			assert.equal(result.last.marker_present, true);
		}
		const stale = await runProbe({
			...afterOptions,
			expectHtml: 'not-present',
			expectFragment: 'not-present'
		}, { baselineReport: before });
		assert.equal(stale.passed, false);
		assert.equal(stale.failures.length, 4);
		assert.equal(requests.length, 24, 'Only anonymous GET requests were issued');
	} finally {
		await new Promise(resolve => server.close(resolve));
	}
});

test('HTTP cache probe rejects unsafe or ambiguous options', () => {
	assert.throws(() => parseOptions(['--base=http://example.org', '--title=Example']), /HTTPS/);
	assert.throws(() => parseOptions(['--base=https://example.org/secret', '--title=Example']), /origin/);
	assert.throws(() => parseOptions([
		'--base=https://example.org', '--title=Example', '--require-changed=vector'
	]), /requires --baseline/);
	assert.throws(() => parseOptions([
		'--base=https://example.org', '--title=Example', '--iterations=100'
	]), /iterations/);
	assert.throws(() => parseOptions([
		'--base=https://example.org', '--title=Example', '--script-path=/w/../admin'
	]), /script-path/);
});
