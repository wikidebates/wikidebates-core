#!/usr/bin/env python3
"""Prepare an isolated MediaWiki 1.43 test lab for MinervaFileCache.

Requires Python 3 and Git. Never reuse an existing path or a production wiki.
"""

import argparse
import json
from pathlib import Path
import subprocess

SOURCES = {
	"mediawiki": ("mediawiki", "8c65f3e15450df456092626693350a8c729bd003"),
	"vendor": ("mediawiki-vendor", "af938940dec08d256da80447a41005a6ffa007f2"),
	"Vector": ("mediawiki-skins-Vector", "726214f61ab790e218720592ef05f7b69f3a4565"),
	"MinervaNeue": ("mediawiki-skins-MinervaNeue", "3e1384140e74b1c17d1286dd0f021f25819bb057"),
	"MobileFrontend": ("mediawiki-extensions-MobileFrontend", "6ad6fec0e68c585bd0a5b047bd3ab404160ea090"),
}


def prepare(root: Path) -> None:
	root.mkdir(parents=True, exist_ok=False)
	root.chmod(0o700)
	for name, (repository, revision) in SOURCES.items():
		checkout = root / name
		subprocess.run(["git", "init", "--quiet", str(checkout)], check=True)
		subprocess.run([
			"git", "-C", str(checkout), "fetch", "--quiet", "--depth=1",
			f"https://github.com/wikimedia/{repository}.git", revision
		], check=True)
		subprocess.run([
			"git", "-C", str(checkout), "checkout", "--quiet", "--detach", "FETCH_HEAD"
		], check=True)

	for relative, target in {
		"vendor": "../vendor",
		"skins/Vector": "../../Vector",
		"skins/MinervaNeue": "../../MinervaNeue",
		"extensions/MobileFrontend": "../../MobileFrontend",
	}.items():
		path = root / "mediawiki" / relative
		if path.exists() or path.is_symlink():
			path.rename(path.with_name(path.name + "-placeholder"))
		path.symlink_to(target, target_is_directory=True)

	state = root / "state"
	state.mkdir(mode=0o700)
	(state / "mobile-cache").mkdir(mode=0o700)
	(state / "desktop-cache").mkdir(mode=0o700)
	(root / "versions.json").write_text(json.dumps({
		name: revision for name, (_, revision) in SOURCES.items()
	}, indent=2) + "\n")
	(root / ".minerva-file-cache-lab").touch()
	print(f"Isolated MinervaFileCache laboratory prepared: {root}")


if __name__ == "__main__":
	parser = argparse.ArgumentParser(description=__doc__)
	parser.add_argument("root", type=Path, help="New directory; must not already exist")
	prepare(parser.parse_args().root.absolute())
