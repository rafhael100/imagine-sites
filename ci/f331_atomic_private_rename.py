#!/usr/bin/env python3
"""F1-331: Linux renameat2(RENAME_NOREPLACE) wrapper, private lab only.

No fallback to os.rename(): it would overwrite an existing target. This
module does not use shell commands, subprocesses or external network.
"""
from __future__ import annotations

import ctypes
import errno
import os
import re
import sys

RENAME_NOREPLACE = 1


def rename_no_replace(parent_fd: int, stage_name: str,
                      final_name: str) -> None:
    if sys.platform != "linux":
        raise OSError(errno.ENOSYS, "requires Linux atomic renameat2")
    if (not re.fullmatch(r"\.sites-f331-restore-[0-9a-f]{16}\.tmp", stage_name)
            or not re.fullmatch(
                r"restored-f330-(?:onepage|landing_page)", final_name
            )):
        raise ValueError("unexpected fixed synthetic rename names")
    libc = ctypes.CDLL(None, use_errno=True)
    fn = getattr(libc, "renameat2", None)
    if fn is None:
        raise OSError(errno.ENOSYS, "atomic no-replace rename unavailable")
    fn.argtypes = [
        ctypes.c_int, ctypes.c_char_p, ctypes.c_int,
        ctypes.c_char_p, ctypes.c_uint,
    ]
    fn.restype = ctypes.c_int
    rv = fn(
        parent_fd, os.fsencode(stage_name),
        parent_fd, os.fsencode(final_name), RENAME_NOREPLACE,
    )
    if rv != 0:
        code = ctypes.get_errno()
        raise OSError(code, "atomic private no-overwrite promotion denied")


def check_platform() -> None:
    """Fail closed before restoring anything if atomic primitive is absent."""
    if sys.platform != "linux":
        raise OSError(errno.ENOSYS, "Linux required for F1-331")
    libc = ctypes.CDLL(None, use_errno=True)
    if getattr(libc, "renameat2", None) is None:
        raise OSError(errno.ENOSYS, "renameat2 unavailable")
