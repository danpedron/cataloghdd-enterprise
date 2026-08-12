#!/usr/bin/env python3
from __future__ import annotations

import sys
import tempfile
import warnings
from pathlib import Path

from PIL import Image

PACKAGE = Path(__file__).resolve().parent / 'package' / 'usr' / 'lib' / 'cataloghdd'
sys.path.insert(0, str(PACKAGE))
from client import make_thumbnail  # noqa: E402

with tempfile.TemporaryDirectory(prefix='cataloghdd-palette-test-') as temporary:
    source = Path(temporary) / 'palette.png'
    destination = Path(temporary) / 'thumbnail.jpg'
    image = Image.new('P', (32, 32), color=1)
    image.putpalette([255, 255, 255, 255, 0, 0] + [0] * (768 - 6))
    image.save(source, 'PNG', transparency=bytes([0, 192] + [255] * 254))
    with warnings.catch_warnings(record=True) as captured:
        warnings.simplefilter('always')
        assert make_thumbnail(source, 'image', destination, 64, 70)
    assert destination.is_file() and destination.stat().st_size > 0
    assert not any('Palette images with Transparency expressed in bytes' in str(item.message) for item in captured), captured
    with Image.open(destination) as result:
        assert result.mode == 'RGB'
print('palette-transparency-thumbnail-ok')
