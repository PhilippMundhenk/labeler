"""Compares Labeler's raster jobs with brother_ql's for the same labels (see run.sh)."""
import json
import re
import sys
from pathlib import Path

from brother_ql.conversion import convert
from brother_ql.raster import BrotherQLRaster


def normalized(job: bytes) -> bytes:
    """brother_ql-inventree also sends "no compression" (M 0) after the margin on models that can
    compress; brother_ql-next and Labeler leave it out. Both mean the same to the printer."""
    return re.sub(rb'(\x1bid..)M\x00', rb'\1', job, flags=re.S)


out = Path(sys.argv[1])
cases = json.loads(Path(__file__).with_name('cases.json').read_text())
failed = 0
for i, case in enumerate(cases):
    name = f"case-{i:02d}"
    ours = (out / f"{name}.bin").read_bytes()
    qlr = BrotherQLRaster(case['model'])
    qlr.exception_on_warning = True
    # the editor draws the label lying along the tape; brother_ql wants it standing
    theirs = normalized(bytes(convert(qlr, [str(out / f"{name}.png")], case['media'], cut=case['cut'], rotate=90)))
    label = f"{case['model']} {case['media']} cut={case['cut']}"
    if ours == theirs:
        print(f"ok   {label}: {len(ours)} bytes")
        continue
    failed += 1
    at = next((k for k in range(min(len(ours), len(theirs))) if ours[k] != theirs[k]), min(len(ours), len(theirs)))
    print(f"FAIL {label}: Labeler {len(ours)} bytes, brother_ql {len(theirs)} bytes, first difference at {at}")
    print(f"     Labeler    {ours[max(0, at - 16):at + 24].hex(' ')}")
    print(f"     brother_ql {theirs[max(0, at - 16):at + 24].hex(' ')}")
if failed:
    sys.exit(f"{failed} of {len(cases)} jobs differ from brother_ql")
print(f"contract test passed: {len(cases)} jobs as brother_ql writes them")
