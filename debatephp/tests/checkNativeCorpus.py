#!/usr/bin/env python3
"""Validate the complete frozen native renderer corpus without historical code."""
import argparse
import json
from pathlib import Path
import subprocess

from nativeContract import verify_frozen

DRIVER = Path(__file__).with_name('nativeCorpus.php')


def main():
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument('--php', default='php')
	args = parser.parse_args()
	rows = json.loads(subprocess.check_output([args.php, str(DRIVER)]))
	verify_frozen(rows)
	print(f'PASS: {len(rows)} frozen native render fingerprints, exact ordered effects and query traces')


if __name__ == '__main__':
	main()
