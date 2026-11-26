#!/usr/bin/env python3
"""Paired, isolated measurements of the compatibility and native renderer boundaries.

Use the same PHP driver and fixtures for both source checkouts. No historical
query projection is applied: this comparison is between two adjacent revisions.
"""
import argparse
import json
from pathlib import Path
import re
import subprocess

from nativeContract import MODES, check_sample_counts
from summarizeNativeBenchmark import METRICS, aggregate, series

CONTEXTS = ('legacy', 'native-json', 'native', 'native-schema')
DRIVER = Path(__file__).with_name('nativeCorpus.php').resolve()


def signature(row):
	return row['sha256'], row['ask_queries']


def compare(before, after, label):
	if before.keys() != after.keys():
		raise ValueError(f'{label}: different fixture sets')
	for name in before:
		if before[name].keys() != after[name].keys() or set(before[name]) != set(MODES):
			raise ValueError(f'{label}: {name}: different renderer modes')
		for mode in MODES:
			if signature(before[name][mode]) != signature(after[name][mode]):
				raise ValueError(f'{label}: {name}/{mode}: different HTML or effects')


def run(root, output, label, context, opcache, repeats, warmup):
	path = output / f'{label}-{context}-opcache-{opcache}.json'
	with path.open('w', encoding='utf-8') as stream:
		subprocess.run([
			'php', '-d', f'opcache.enable_cli={opcache}', str(DRIVER),
			f'--root={root}', f'--context={context}',
			f'--repeats={repeats}', f'--warmup={warmup}',
		], stdout=stream, check=True)
	with path.open(encoding='utf-8') as stream:
		result = json.load(stream)
	env, cases = result['environment'], result['cases']
	if (env['context'] != context or env['repeats'] != repeats
		or env['warmup'] != warmup or env['cases'] != len(cases)
		or bool(env['opcache_status']) != bool(opcache)):
		raise ValueError(f'{path}: invalid benchmark environment')
	for row in cases.values():
		for mode in MODES:
			check_sample_counts(row[mode])
	return result


def groups(rows):
	for module in ('Argument', 'Debate'):
		names = [name for name in rows if name.startswith(module + '/')]
		yield module + '/all', names
		for size in ('1', '4', '24', 'empty'):
			selected = [name for name in names if re.fullmatch(
				r'(Argument/argument|Debate/debate)-(fr|en|de|es|it|pt)-' + size, name)]
			if selected:
				yield f'{module}/{size}', selected


def report(before, after, label, repeats):
	print(f'\n{label}')
	for group, names in groups(before):
		for mode in MODES:
			print(f'  {group}/{mode}: {len(names)} cases')
			for key, scale, metric in METRICS:
				b, bm = aggregate(series(before, names, mode, key, repeats))
				a, am = aggregate(series(after, names, mode, key, repeats))
				delta = f'{(a / b - 1) * 100:+.2f}%' if b else 'n/a'
				print(f'    {metric}: {b / scale:.3f} -> {a / scale:.3f} ({delta}); '
					f'median per-case MAD {bm / scale:.3f} -> {am / scale:.3f}')


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument('--base-root', required=True, type=Path)
	parser.add_argument('--candidate-root', type=Path, default=DRIVER.parents[2])
	parser.add_argument('--output', required=True, type=Path)
	parser.add_argument('--repeats', type=int, default=10)
	parser.add_argument('--warmup', type=int, default=3)
	args = parser.parse_args()
	if not 1 <= args.repeats <= 1000 or not 0 <= args.warmup <= 100:
		parser.error('invalid repetition or warmup count')
	args.output.mkdir(parents=True, exist_ok=True)
	roots = {'base': args.base_root.resolve(), 'candidate': args.candidate_root.resolve()}
	print('Isolated adjacent-revision comparison; sums of per-case medians, not production measurements.')
	print('native-schema uses schema filtering only; no live parser, revision lookup, SMW database or strip insertion.')
	for opcache in (0, 1):
		results = {}
		for i, context in enumerate(CONTEXTS):
			# Alternate checkout order to reduce systematic runner drift.
			order = ('base', 'candidate') if (i + opcache) % 2 == 0 else ('candidate', 'base')
			for label in order:
				results[label, context] = run(roots[label], args.output, label, context,
					opcache, args.repeats, args.warmup)
		envs = [{k: v for k, v in result['environment'].items() if k != 'context'}
			for result in results.values()]
		if any(env != envs[0] for env in envs):
			raise ValueError('Benchmark environments differ')
		print(f'\nPHP {envs[0]["php"]}; OPcache={opcache}; {envs[0]["cases"]} cases')
		for context in CONTEXTS:
			before = results['base', context]['cases']
			after = results['candidate', context]['cases']
			compare(before, after, context)
			report(before, after, f'base -> candidate / {context}', args.repeats)
		for label in roots:
			legacy = results[label, 'legacy']['cases']
			for context in ('native-json', 'native'):
				native = results[label, context]['cases']
				compare(legacy, native, f'{label}/{context} vs legacy')
				report(legacy, native, f'{label}: legacy -> {context}', args.repeats)
		print('PASS: exact adjacent-revision fingerprints in all contexts; legacy/native trace parity.')


if __name__ == '__main__':
	main()
