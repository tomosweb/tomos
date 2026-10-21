#!/usr/bin/env python3
"""Compare built distributions, including dependencies, against public v1.1.10."""
import sys
from pathlib import Path
from zipfile import ZipFile

runtime = {'VERSION', 'post/index.php', 'post/assets/tomos-post-ui.css', 'core/PostPublished.php'}
expected = runtime | {'README.md', 'CHANGELOG.md'}

def contents(path):
    with ZipFile(path) as archive:
        names = [n for n in archive.namelist() if not n.endswith('/')]
        if len(names) != len(set(names)):
            raise SystemExit('FAIL: duplicate ZIP entries')
        return {n: archive.read(n) for n in names}

baseline, candidate = map(contents, sys.argv[1:3])
root = Path(__file__).resolve().parent.parent
changed = {n for n in baseline.keys() | candidate.keys() if baseline.get(n) != candidate.get(n)}
if changed != expected:
    raise SystemExit('FAIL: distribution delta ' + repr(sorted(changed)))
for name in expected:
    if candidate[name] != (root / name).read_bytes():
        raise SystemExit('FAIL: distribution/source mismatch ' + name)
if baseline['VERSION'].strip() != b'1.1.10' or candidate['VERSION'].strip() != b'1.1.11':
    raise SystemExit('FAIL: distribution VERSION')
print('PASS: distribution delta is exactly four runtime files plus README/CHANGELOG; all payloads match source')
