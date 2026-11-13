#!/usr/bin/env python3
"""F1-44: compute real-browser text/background contrast for fictional lab UI.

Checks a representative set of ordinary-size text. Not an accessibility
certification: gradients, pseudo-elements and visual review still need humans.
"""
from __future__ import annotations

import re

SAMPLES = [
    ".intro .sub", ".status", ".kicker", ".intro h1",
    ".brand small", ".flag", ".card .sub", ".steps small",
    ".card dt", ".locked", ".qa-summary p", ".qa-list li",
    ".preview-head .sub", ".chrome span", ".foot", ".note",
]
QUERY = r"""(() => {
  const selectors = %s;
  const result = [];
  for (const selector of selectors) {
    const els=[...document.querySelectorAll(selector)];
    if (!els.length) throw Error('Missing contrast element: '+selector);
    for (const el of els) {
      let background=null, ancestor=el;
      while (ancestor) {
        const color=getComputedStyle(ancestor).backgroundColor;
        if (color && !/^rgba?\(0,\s*0,\s*0,\s*0(?:\.0+)?\)$/.test(color)
           && color!=='transparent') { background=color; break; }
        ancestor=ancestor.parentElement;
      }
      if (!background) throw Error('No solid background: '+selector);
      result.push({selector,color:getComputedStyle(el).color,background});
    }
  }
  return result;
})()"""


def _rgb(value: str) -> tuple[int, int, int]:
    parts = re.fullmatch(r"rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*1(?:\.0+)?)?\s*\)", value)
    if not parts:
        raise AssertionError(f"Non-opaque RGB must be reviewed separately: {value}")
    channels = tuple(int(parts.group(i)) for i in (1, 2, 3))
    assert all(0 <= n <= 255 for n in channels)
    return channels


def _luminance(color: tuple[int, int, int]) -> float:
    channel = [c/255 for c in color]
    lin = [x/12.92 if x <= .04045 else ((x+.055)/1.055)**2.4 for x in channel]
    return .2126*lin[0] + .7152*lin[1] + .0722*lin[2]


def ratio(foreground: str, background: str) -> float:
    light, dark = sorted((_luminance(_rgb(foreground)), _luminance(_rgb(background))), reverse=True)
    return (light+.05)/(dark+.05)


def assert_review_text_contrast(client, filename: str) -> None:
    # Independent basic regression vectors: previous muted grey fails on white.
    assert ratio("rgb(105, 123, 144)", "rgb(255, 255, 255)") < 4.5
    assert ratio("rgb(82, 100, 122)", "rgb(255, 255, 255)") >= 4.5

    samples = client.evaluate(QUERY % __import__("json").dumps(SAMPLES))
    assert len(samples) >= len(SAMPLES), (filename, "Missing contrast samples")
    failures = []
    for item in samples:
        value = ratio(item["color"], item["background"])
        if value < 4.5:
            failures.append((item["selector"], round(value, 2), item["color"], item["background"]))
    if failures:
        raise AssertionError(f"F1-44 {filename}: text contrast below 4.5:1: {failures}")
    print(f"PASS F1-44 {filename}: {len(samples)} text nodes with contrast >= 4.5:1")
