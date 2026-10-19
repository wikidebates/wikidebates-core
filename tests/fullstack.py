#!/usr/bin/env python3
"""Run real-extension tests and compare the fresh Lua/native render artifacts."""
import argparse
import json
import math
import os
from pathlib import Path
import statistics
import subprocess
import tempfile

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', default='php')
parser.add_argument('--memory-limit', default='512M', help='PHP CLI limit for retained test artifacts, not a renderer requirement')
parser.add_argument('--output', required=True, type=Path)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='wikidebates-fullstack-') as directory:
	work = Path(directory)
	env = dict(os.environ, WD_FULLSTACK_OUTPUT=str(work / 'summary.json'), WD_FULLSTACK_RENDER_OUTPUT=str(work / 'renders.json'))
	subprocess.run([args.php, '-d', 'memory_limit=' + args.memory_limit, str(root / 'tests/fullstack.php')], env=env, check=True)
	subprocess.run(['python3', str(root / 'debatephp/tests/integration/check_direct_jsonld.py'), str(work / 'renders.json'), str(work / 'comparison.json')], check=True)
	report = json.loads((work / 'summary.json').read_text())
	report['harness_memory_limit'] = args.memory_limit
	report['render_comparison'] = json.loads((work / 'comparison.json').read_text())
	report['timing_summary_ms'] = {
		kind: {mode: {'samples': len(samples), 'median': statistics.median(samples), 'min': min(samples), 'max': max(samples)}
			for mode, samples in modes.items()}
		for kind, modes in report['parse_timings_ms'].items()
	}
	if 'native_optimization' in report:
		benchmark = report['native_optimization']
		benchmark['summary_ms'] = {
			name: {mode: {'samples': len(samples), 'median': statistics.median(samples),
				'p95': sorted(samples)[math.ceil(len(samples) * 0.95) - 1]}
				for mode, samples in modes.items()}
			for name, modes in benchmark['samples_ms'].items()
		}
	args.output.parent.mkdir(parents=True, exist_ok=True)
	args.output.write_text(json.dumps(report, ensure_ascii=False, indent='\t') + '\n')
	print('Full-stack validation passed: ' + str(args.output))
