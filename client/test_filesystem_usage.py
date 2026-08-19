#!/usr/bin/env python3
from __future__ import annotations

import sys
import tempfile
from pathlib import Path

PACKAGE = Path(__file__).resolve().parent / 'package' / 'usr' / 'lib' / 'cataloghdd'
sys.path.insert(0, str(PACKAGE))
from client import filesystem_usage  # noqa: E402

with tempfile.TemporaryDirectory(prefix='cataloghdd-usage-test-') as temporary:
    root = Path(temporary)
    (root / 'sample.bin').write_bytes(b'cataloghdd' * 1024)
    usage = filesystem_usage(root)
    assert set(usage) == {'used_bytes', 'free_bytes'}, usage
    assert isinstance(usage['used_bytes'], int) and usage['used_bytes'] >= 0, usage
    assert isinstance(usage['free_bytes'], int) and usage['free_bytes'] >= 0, usage
print('filesystem-usage-ok')
