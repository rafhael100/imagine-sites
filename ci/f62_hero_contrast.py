#!/usr/bin/env python3
"""F1-62: sample computed hero gradient/opacity contrast, stdlib only.

Narrow scope: CSS two-stop opaque sRGB linear gradients and three explicitly
selected text nodes. Does not claim WCAG conformance or inspect photographs.
"""
from __future__ import annotations

import re
import sys

from f44_contrast import ratio

RGB = re.compile(r"rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)")
GRADIENT = re.compile(
    r"^linear-gradient\(\s*\d+(?:\.\d+)?deg,\s*"
    r"rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\),\s*"
    r"rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)\s*\)$"
)


def rgb(value: str) -> tuple[int, int, int]:
    matched = RGB.fullmatch(value)
    if not matched:
        raise AssertionError(f"Unsupported or translucent computed color: {value!r}")
    values = tuple(int(matched.group(i)) for i in range(1, 4))
    assert all(0 <= value <= 255 for value in values)
    return values


def css_rgb(value: tuple[int, int, int]) -> str:
    return "rgb(%d, %d, %d)" % value


def blend(above: tuple[int, int, int], below: tuple[int, int, int],
          opacity: float) -> tuple[int, int, int]:
    if not 0 <= opacity <= 1:
        raise AssertionError(f"Invalid computed opacity {opacity}")
    return tuple(round(opacity*foreground+(1-opacity)*background)
                 for foreground, background in zip(above, below))


def inspect_hero(sample: dict, filename: str) -> int:
    gradient = sample["gradient"]
    if not isinstance(gradient, str) or not GRADIENT.fullmatch(gradient):
        raise AssertionError((filename, "unsupported gradient, requires human review", gradient))
    stops = [rgb(match.group(0)) for match in RGB.finditer(gradient)]
    if len(stops) != 2:
        raise AssertionError((filename, "exactly two opaque gradient stops required"))
    selectors = [".hero h1", ".hero .eyebrow", ".hero .lead"]
    typography = sample["heroTypography"]
    if [item["selector"] for item in typography] != selectors:
        raise AssertionError((filename, "hero samples changed without audit"))
    tested = 0
    for item in typography:
        fg = rgb(item["color"])
        alpha = float(item["opacity"])
        if not 0 <= alpha <= 1:
            raise AssertionError((filename, "invalid foreground opacity", item))
        for step in range(9):
            progress = step / 8
            bg = tuple(round(a*(1-progress)+b*progress) for a, b in zip(*stops))
            composite = blend(fg, bg, alpha)
            score = ratio(css_rgb(composite), css_rgb(bg))
            if score < 4.5:
                raise AssertionError((filename, item["selector"],
                                      "hero contrast below 4.5:1",
                                      f"ratio={score:.3f}", f"sample={step}/8"))
            tested += 1
    return tested


def self_test() -> None:
    good = {
        "gradient": "linear-gradient(120deg, rgb(18, 43, 77), rgb(40, 92, 136))",
        "heroTypography": [
            {"selector": ".hero h1", "color": "rgb(255, 255, 255)", "opacity": "1"},
            {"selector": ".hero .eyebrow", "color": "rgb(255, 255, 255)", "opacity": "0.8"},
            {"selector": ".hero .lead", "color": "rgb(255, 255, 255)", "opacity": "0.92"},
        ],
    }
    assert inspect_hero(good, "synthetic-dark") == 27
    light = {**good,
             "gradient": "linear-gradient(130deg, rgb(245, 245, 245), rgb(255, 255, 255))"}
    try:
        inspect_hero(light, "synthetic-light")
    except AssertionError:
        pass
    else:
        raise AssertionError("White text on a light gradient was not caught")
    alpha = {**good, "gradient": "linear-gradient(90deg, rgba(10,20,30,.5), rgb(20, 30, 40))"}
    try:
        inspect_hero(alpha, "alpha-unproven")
    except AssertionError:
        pass
    else:
        raise AssertionError("Unsupported translucent gradient silently accepted")
    assert rgb("rgb(255, 255, 255)") == (255, 255, 255)
    assert blend((255, 255, 255), (0, 0, 0), 0.5) == (128, 128, 128)
    print("PASS F1-62: hero gradient/opacity self-tests and dangerous contrast mutants")


if __name__ == "__main__":
    if sys.argv[1:] != ["--self-test"]:
        raise SystemExit("Use --self-test for pure Python fixtures")
    self_test()
