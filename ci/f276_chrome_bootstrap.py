#!/usr/bin/env python3
"""F1-276: retry ONLY the precise local Chrome DevTools bootstrap timeout.

No retry may suppress or repeat HTML QA, contrast, layout, source-hash,
keyboard, network, or any other failure. Used by the offline F1-59 runner.
"""
from __future__ import annotations

import time
from typing import Callable

BOOTSTRAP_TIMEOUT = "Local Chrome DevTools port never became complete"


def with_single_cdp_bootstrap_retry(
    operation: Callable[[], None],
    pause: Callable[[float], None] = time.sleep,
) -> None:
    try:
        operation()
    except TimeoutError as exc:
        if str(exc) != BOOTSTRAP_TIMEOUT:
            raise
        print(
            "F1-276: one fresh Chrome profile retry after local CDP port timeout",
            flush=True,
        )
        pause(2.0)
        operation()
