#!/usr/bin/env python3
"""F1-276: mocked retry-policy regression, no Chrome or network needed."""
from __future__ import annotations

from f276_chrome_bootstrap import (
    BOOTSTRAP_TIMEOUT, with_single_cdp_bootstrap_retry,
)

checks = 0


def verify(case, expected_runs, expected_pauses, label):
    global checks
    runs = []
    pauses = []

    def operation():
        runs.append(len(runs))
        case(len(runs))

    try:
        with_single_cdp_bootstrap_retry(operation, pauses.append)
        outcome = "success"
    except Exception as exc:
        outcome = type(exc).__name__

    assert len(runs) == expected_runs, (label, "wrong runs", runs)
    assert pauses == expected_pauses, (label, "wrong delays", pauses)
    checks += 1
    return outcome


assert verify(lambda i: None, 1, [], "happy path") == "success"


def transient(i):
    if i == 1:
        raise TimeoutError(BOOTSTRAP_TIMEOUT)


assert verify(transient, 2, [2.0], "only CDP timeout retries") == "success"


def repeated(i):
    raise TimeoutError(BOOTSTRAP_TIMEOUT)


assert verify(repeated, 2, [2.0], "second failure not suppressed") == "TimeoutError"


def other_timeout(i):
    raise TimeoutError("Chrome layout unexpectedly timed out")


assert verify(other_timeout, 1, [], "QA timeout not retried") == "TimeoutError"


def bad_contrast(i):
    raise AssertionError("contrast ratio too low")


assert verify(bad_contrast, 1, [], "contrast QA not retried") == "AssertionError"


def other_bootstrap(i):
    raise RuntimeError("Chrome exited before DevTools startup")


assert verify(other_bootstrap, 1, [], "exit does not trigger retry") == "RuntimeError"


def base_exception(i):
    raise ValueError("tampered synthetic HTML digest")


assert verify(base_exception, 1, [], "HTML digest does not retry") == "ValueError"

print(f"PASS F1-276: {checks} fail-closed Chrome CDP retry-policy cases.")
