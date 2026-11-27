"""Frozen native renderer fingerprints and timing sample integrity checks."""
import hashlib
import json
from pathlib import Path

MODES = ('optimized',)
FIXTURE = Path(__file__).with_name('fixtures') / 'native-render-contract.json'


def verify_frozen(rows):
	expected = json.loads(FIXTURE.read_text(encoding='utf-8'))
	if expected['format'] != 1 or expected['block_size'] != 10:
		raise ValueError('Unsupported native render contract format')
	if len(rows) != expected['cases']:
		raise ValueError('Native render case count differs')
	entries = []
	for name, modes in rows.items():
		if set(modes) != set(MODES):
			raise ValueError(f'{name}: unexpected renderer mode')
		entries.append((name, {mode: {
			'sha256': modes[mode]['sha256'],
			'ask_queries': modes[mode]['ask_queries'],
		} for mode in MODES}))
	blocks = []
	for index in range(0, len(entries), 10):
		block = json.dumps(entries[index:index + 10], ensure_ascii=True, separators=(',', ':')).encode('ascii')
		blocks.append(hashlib.sha256(block).hexdigest())
	if len(blocks) != len(expected['blocks']):
		raise ValueError('Native render block count differs')
	for index, (actual, frozen) in enumerate(zip(blocks, expected['blocks'])):
		if actual != frozen:
			names = ', '.join(name for name, _ in entries[index * 10:(index + 1) * 10])
			raise ValueError(f'Native render contract block {index + 1} differs: {names}')


def check_sample_counts(row):
	for sample in row['samples']:
		if sample['semantic_read_calls'] != len(row['ask_queries']):
			raise ValueError('Timed semantic read count differs from the untimed trace')
