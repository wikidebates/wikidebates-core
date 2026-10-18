#!/usr/bin/env python3
"""Prepare an archived 0.3.0 PHP renderer for an isolated, interleaved benchmark."""
import pathlib,sys,zipfile
with zipfile.ZipFile(sys.argv[1]) as archive:
    parts=[]
    for filename in ['ReferenceRenderer.php','Renderer.php','Hooks.php']:
        source=archive.read('DebatePHP/src/'+filename).decode()
        source=source.replace('ReferenceRenderer','LegacyReferenceRenderer')
        source=source.replace('class Renderer ', 'class LegacyRenderer ')
        source=source.replace('new Renderer(', 'new LegacyRenderer(')
        source=source.replace('class Hooks ', 'class LegacyHooks ')
        parts.append(source.replace('<?php','',1))
pathlib.Path(sys.argv[2]).write_text('<?php\n'+'\n'.join(parts))
