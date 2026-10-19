#!/usr/bin/env python3
"""Verify the real maintenance command without modifying wiki pages or bundled files."""
import argparse, hashlib, pathlib, subprocess, tempfile

args = argparse.ArgumentParser()
args.add_argument('--php', required=True)
args.add_argument('--wiki-root', required=True)
options = args.parse_args()
root = pathlib.Path(__file__).resolve().parents[2]
command = [options.php, str(root / 'tools/maintainI18n.php'), '--wiki-root=' + options.wiki_root]
tracked = [root / 'resources/I18nData.php', root / 'resources/WD-I18N-Data.snapshot.lua']
original = {p: hashlib.sha256(p.read_bytes()).hexdigest() for p in tracked}

def run(extra, success=True):
	result = subprocess.run(command + extra, text=True, capture_output=True)
	if (result.returncode == 0) != success:
		raise RuntimeError(result.stdout + result.stderr)
	return result.stdout + result.stderr

run([])
with tempfile.TemporaryDirectory(prefix='debate-i18n-test-') as directory:
	directory = pathlib.Path(directory)
	source = tracked[1].read_text()
	candidate = directory / 'candidate.lua'
	candidate.write_text(source.replace('fr = "Modifier"', 'fr = "Modifier test"', 1))
	assert candidate.read_text() != source
	output = directory / 'candidate.php'
	output.write_text('Previous version')
	output.chmod(0o640)
	ownership = (output.stat().st_uid, output.stat().st_gid)
	run(['--source=' + str(candidate), '--rebuild', '--output=' + str(output)])
	probe = subprocess.run([options.php, '-r', "$t = require $argv[1]; echo $t['data']['Common']['labels']['edit_label']['fr'];", str(output)], capture_output=True, text=True, check=True)
	assert probe.stdout == 'Modifier test'
	assert output.stat().st_mode & 0o777 == 0o640
	assert (output.stat().st_uid, output.stat().st_gid) == ownership
	before = output.read_bytes()
	candidate.write_text(source.replace('fr = "Modifier"', 'fr = "%q"', 1))
	rejected = run(['--source=' + str(candidate), '--rebuild', '--output=' + str(output)], success=False)
	assert 'Common.labels / fr / edit_label' in rejected
	assert output.read_bytes() == before
	candidate.write_text(source.replace('fr = "Modifier"', 'fr = "Modifier test"', 1))
	run(['--source=' + str(candidate), '--rebuild'], success=False)
	run(['--output=' + str(output)], success=False)
	run(['--rebuild', '--output=' + str(tracked[0])], success=False)
for path, digest in original.items():
	assert hashlib.sha256(path.read_bytes()).hexdigest() == digest
print('Synchronized check, validated rebuild, preserved permissions, rejected mismatch and unchanged bundled files: passed')
