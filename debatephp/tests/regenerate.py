#!/usr/bin/env python3
"""Regenerate in a temporary tree and reject any unrecorded renderer hand edits."""
from pathlib import Path
import shutil
import difflib
import subprocess
import sys
import tempfile

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='wikidebates-regenerate-') as directory:
	work = Path(directory)
	for name in ['src', 'tools', 'resources']:
		shutil.copytree(root / name, work / name, ignore=shutil.ignore_patterns('__pycache__'))
	for name in ['buildReference.py', 'buildArgument.py']:
		subprocess.run([sys.executable, str(work / 'tools' / name)], check=True)
	mismatches = []
	for name in ['ReferenceRenderer.php', 'ArgumentReferenceRenderer.php']:
		original = (root / 'src' / name).read_bytes()
		generated = (work / 'src' / name).read_bytes()
		if original != generated:
			mismatches.append(name)
			print('Generator mismatch: ' + name, flush=True)
			diff = list(difflib.unified_diff(original.decode('utf-8').splitlines(), generated.decode('utf-8').splitlines(), fromfile='checked-in/' + name, tofile='generated/' + name, n=2))
			print('\n'.join(diff[:160]), flush=True)
			if len(diff) > 160:
				print('Remaining diff lines: ' + str(len(diff) - 160), flush=True)
	assert not mismatches, 'Generator mismatch: ' + ', '.join(mismatches)
print('Both renderers regenerate byte-for-byte from the unchanged Lua snapshots and reviewed native passes')
