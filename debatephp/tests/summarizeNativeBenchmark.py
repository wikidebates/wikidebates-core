#!/usr/bin/env python3
"""Summarize isolated baseline/candidate samples without a noisy timing gate."""
import json
from pathlib import Path
from statistics import median
import sys

from nativeContract import MODES, compare_corpora, check_sample_counts

METRICS = (
	('wall_ns', 1e6, 'wall ms'),
	('cpu_us', 1e3, 'CPU ms'),
	('peak_php_bytes_delta', 1024, 'peak PHP KiB'),
	('preprocess_calls', 1, 'preprocess calls'),
	('semantic_read_calls', 1, 'mock semantic reads'),
	('semantic_write_calls', 1, 'mock semantic writes'),
)


def read(root, label, opcache):
	with (root / f'{label}-opcache-{opcache}.json').open() as stream:
		return json.load(stream)


def series(rows, names, mode, key, repeats):
	result = []
	for name in names:
		samples = rows[name][mode]['samples']
		if len(samples) != repeats:
			raise ValueError(f'Invalid sample count for {name}/{mode}')
		values = [sample[key] for sample in samples]
		if any(not isinstance(value, (float, int)) or value < 0 for value in values):
			raise ValueError(f'Invalid metric {key} for {name}/{mode}')
		result.append(values)
	return result


def aggregate(values):
	medians = [median(v) for v in values]
	# Dispersion is computed per case, before aggregation.
	mad = [median(abs(x - m) for x in v) for v, m in zip(values, medians)]
	return sum(medians), median(mad)


def main(root):
	print('Isolated PHP render benchmarks: sums of per-case medians; not production measurements.')
	for opcache in (0, 1):
		before = read(root, 'before', opcache)
		after = read(root, 'after', opcache)
		env = before['environment']
		if ({k: v for k, v in env.items() if k not in ('json_boundary', 'semantic_boundary')} !=
			{k: v for k, v in after['environment'].items() if k not in ('json_boundary', 'semantic_boundary')}):
			raise ValueError('Benchmark environments differ')
		rows = before['cases']
		other = after['cases']
		if rows.keys() != other.keys() or len(rows) != env['cases']:
			raise ValueError('Benchmark corpora differ')
		compare_corpora(rows, other)
		for name in rows:
			for mode in MODES:
				check_sample_counts(rows[name][mode])
				check_sample_counts(other[name][mode])
		print(f"\nPHP {env['php']} {env['os']}; CLI OPcache requested={opcache}, active={env['opcache_status']}; "
			f"cases={env['cases']}; repetitions={env['repeats']}; warmup={env['warmup']}")
		print(f"  JSON boundary: {env.get('json_boundary', 'unrecorded')} -> "
			f"{after['environment'].get('json_boundary', 'unrecorded')}")
		print(f"  SMW boundary: {env.get('semantic_boundary', 'unrecorded')} -> "
			f"{after['environment'].get('semantic_boundary', 'unrecorded')}")
		if bool(env['opcache_status']) != bool(opcache):
			print('WARNING: actual OPcache status differs from requested; do not label this a valid OPcache comparison.')
		for module in ('Argument', 'Debate'):
			names = [name for name in rows if name.startswith(module + '/')]
			if not names:
				raise ValueError('Missing module: ' + module)
			for mode in MODES:
				print(f'  {module}/{mode}: {len(names)} cases')
				for key, scale, label in METRICS:
					b, bm = aggregate(series(rows, names, mode, key, env['repeats']))
					a, am = aggregate(series(other, names, mode, key, env['repeats']))
					delta = f'{(a / b - 1) * 100:+.2f}%' if b else 'n/a'
					print(f'    {label}: {b / scale:.3f} -> {a / scale:.3f} ({delta}); '
						f'median per-case MAD {bm / scale:.3f} -> {am / scale:.3f}')


if __name__ == '__main__':
	main(Path(sys.argv[1]))
