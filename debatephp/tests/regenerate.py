#!/usr/bin/env python3
"""Compatibility command: validate native renderers; never regenerate PHP.

The former byte-for-byte transpiler check is archived in Git history.
This command compares exact behaviour against the corrected native baseline.
"""
import argparse
import difflib
import json
from pathlib import Path
import subprocess
import tempfile

from nativeContract import compare_corpora

ROOT = Path(__file__).resolve().parents[2]
BASELINE = '2439e0749bcdc9ab81aeb20a902f8b6845a744e4'
DRIVER = ROOT / 'debatephp/tests/nativeCorpus.php'
def git(*args):
	return subprocess.run(['git', '-C', str(ROOT), *args], check=True)


def compare(php, baseline):
	for path in (ROOT / 'debatephp').rglob('*.lua'):
		raise SystemExit('Retired executable Lua source restored: ' + str(path.relative_to(ROOT)))
	expected = subprocess.check_output([php, str(DRIVER), '--root=' + str(baseline)])
	actual = subprocess.check_output([php, str(DRIVER)])
	try:
		compare_corpora(json.loads(expected), json.loads(actual))
	except ValueError as error:
		for line in difflib.unified_diff(
			expected.decode().splitlines(), actual.decode().splitlines(),
			fromfile='baseline', tofile='current', n=2
		):
			print(line)
		raise SystemExit(f'Native renderer behaviour differs from baseline: {error}')

	print('Native renderer behaviour matches the pinned PHP baseline; no bundled Lua sources remain')


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument('--php', default='php')
	parser.add_argument('--baseline-root', type=Path, help='Existing checkout of the pinned baseline')
	args = parser.parse_args()
	if args.baseline_root:
		baseline = args.baseline_root.resolve()
		head = subprocess.check_output(['git', '-C', str(baseline), 'rev-parse', 'HEAD'], text=True).strip()
		if head != BASELINE:
			raise SystemExit('Baseline checkout must be at ' + BASELINE)
		if subprocess.check_output(['git', '-C', str(baseline), 'status', '--porcelain']):
			raise SystemExit('Baseline checkout must be clean')
		compare(args.php, baseline)
		return
	try:
		git('cat-file', '-e', BASELINE + '^{commit}')
	except subprocess.CalledProcessError:
		raise SystemExit('Fetch the baseline first: git fetch --no-tags origin ' + BASELINE)
	with tempfile.TemporaryDirectory(prefix='wikidebates-native-contract-') as temporary:
		baseline = Path(temporary) / 'baseline'
		git('worktree', 'add', '--detach', str(baseline), BASELINE)
		try:
			compare(args.php, baseline)
		finally:
			git('worktree', 'remove', str(baseline))


if __name__ == '__main__':
	main()
