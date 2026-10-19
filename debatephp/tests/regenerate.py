#!/usr/bin/env python3
"""Regenerate in a temporary tree and reject any unrecorded renderer hand edits."""
from pathlib import Path
import shutil
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
	for name in ['ReferenceRenderer.php', 'ArgumentReferenceRenderer.php']:
		assert (root / 'src' / name).read_bytes() == (work / 'src' / name).read_bytes(), 'Generator mismatch: ' + name
print('Both renderers regenerate byte-for-byte from the unchanged Lua snapshots and reviewed native passes')
