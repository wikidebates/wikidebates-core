#!/usr/bin/env python3
"""Summarize isolated baseline/candidate samples without a noisy timing gate."""
import json
from pathlib import Path
from statistics import median
import sys

from nativeContract import MODES, verify_frozen, check_sample_counts

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
		result = read(root, 'native', opcache)
		env, rows = result['environment'], result['cases']
		if env['context'] != 'native' or env['cases'] != len(rows) or bool(env['opcache_status']) != bool(opcache):
			raise ValueError('Invalid native benchmark environment')
		verify_frozen(rows)
		print(f"\nPHP {env['php']} {env['os']}; CLI OPcache requested={opcache}, active={env['opcache_status']}; "
			f"cases={env['cases']}; repetitions={env['repeats']}; warmup={env['warmup']}")
		for module in ('Argument', 'Debate'):
			names = [name for name in rows if name.startswith(module + '/')]
			if not names:
				raise ValueError('Missing module: ' + module)
			for mode in MODES:
				print(f'  {module}/{mode}: {len(names)} cases')
				for name in names:
					check_sample_counts(rows[name][mode])
				for key, scale, label in METRICS:
					total, mad = aggregate(series(rows, names, mode, key, env['repeats']))
					print(f'    {label}: {total / scale:.3f}; median per-case MAD {mad / scale:.3f}')


if __name__ == '__main__':
	main(Path(sys.argv[1]))
