#!/usr/bin/env python3
"""F1-43: Chromium keyboard and responsive checks on private fictitious HTML.

CDP uses stdlib sockets and browser's local loopback endpoint; no web server,
external packages, real customer data, public URL or screenshot upload.
"""
from __future__ import annotations

import base64
import hashlib
import json
import os
from pathlib import Path
import socket
import struct
import subprocess
import sys
import tempfile
import time
from urllib.request import urlopen

from f44_contrast import assert_review_text_contrast


class CDP:
    def __init__(self, port: int, endpoint: str):
        self.sock = socket.create_connection(("127.0.0.1", port), timeout=8)
        self.sock.settimeout(8)
        key = base64.b64encode(os.urandom(16)).decode()
        request = (
            f"GET {endpoint} HTTP/1.1\r\nHost: 127.0.0.1:{port}\r\n"
            "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n"
        )
        self.sock.sendall(request.encode("ascii"))
        response = b""
        while b"\r\n\r\n" not in response:
            chunk = self.sock.recv(4096)
            if not chunk:
                raise RuntimeError("WebSocket disconnected")
            response += chunk
            if len(response) > 16384:
                raise RuntimeError("Unexpectedly large WebSocket handshake")
        headers, self.pending = response.split(b"\r\n\r\n", 1)
        expected = base64.b64encode(
            hashlib.sha1((key+"258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()
        )
        if not headers.startswith(b"HTTP/1.1 101 ") or (
            b"sec-websocket-accept: "+expected.lower() not in headers.lower()
        ):
            raise RuntimeError("Local Chrome DevTools WebSocket handshake rejected")
        self.counter = 0

    def close(self) -> None:
        self.sock.close()

    def _read(self, n: int) -> bytes:
        while len(self.pending) < n:
            chunk = self.sock.recv(max(4096, n-len(self.pending)))
            if not chunk:
                raise RuntimeError("DevTools WebSocket closed")
            self.pending += chunk
        data, self.pending = self.pending[:n], self.pending[n:]
        return data

    def _send(self, payload: bytes, opcode: int = 1) -> None:
        out = bytearray([0x80 | opcode])
        size = len(payload)
        if size < 126:
            out.append(size | 0x80)
        elif size < 65536:
            out.append(0x80 | 126)
            out.extend(struct.pack(">H", size))
        else:
            out.append(0x80 | 127)
            out.extend(struct.pack(">Q", size))
        mask = os.urandom(4)
        out.extend(mask)
        out.extend(bytes(x ^ mask[i % 4] for i, x in enumerate(payload)))
        self.sock.sendall(out)

    def _recv(self) -> dict:
        while True:
            header = self._read(2)
            opcode, length = header[0] & 15, header[1] & 127
            if length == 126:
                length = struct.unpack(">H", self._read(2))[0]
            elif length == 127:
                length = struct.unpack(">Q", self._read(8))[0]
            mask = self._read(4) if header[1] & 0x80 else None
            payload = self._read(length)
            if mask:
                payload = bytes(x ^ mask[i % 4] for i, x in enumerate(payload))
            if opcode == 8:
                raise RuntimeError("Chrome closed DevTools connection")
            if opcode == 9:
                self._send(payload, 10)
                continue
            if opcode == 1:
                return json.loads(payload.decode("utf-8"))

    def call(self, method: str, params: dict | None = None) -> dict:
        self.counter += 1
        command = {"id": self.counter, "method": method, "params": params or {}}
        self._send(json.dumps(command, separators=(",", ":")).encode())
        deadline = time.monotonic() + 8
        while time.monotonic() < deadline:
            result = self._recv()
            if result.get("id") != self.counter:
                continue
            if result.get("error"):
                raise RuntimeError(f"Chrome {method} denied: {result['error']}")
            return result.get("result", {})
        raise TimeoutError(f"Chrome {method} timed out")

    def evaluate(self, script: str):
        result = self.call("Runtime.evaluate", {
            "expression": script, "returnByValue": True, "awaitPromise": False
        })
        if "exceptionDetails" in result:
            raise RuntimeError("Chrome script inspection failed")
        return result["result"].get("value")


METRICS = r"""(() => {
  const links=[...document.querySelectorAll('a[href]')];
  const frame=document.querySelector('iframe');
  return {
    width:innerWidth, scroll:document.documentElement.scrollWidth,
    bodyScroll:document.body.scrollWidth, lang:document.documentElement.lang,
    main:document.querySelectorAll('main').length,
    headings:document.querySelectorAll('h1').length,
    navSelected:[...document.querySelectorAll('nav')].map(n=>n.querySelectorAll('a[aria-current="page"]').length),
    links:links.map(a=>a.getAttribute('href')),
    linksVisible:links.every(a=>a.getBoundingClientRect().width>0 && a.getBoundingClientRect().height>0),
    navigationLocal:links.every(a=>a.getAttribute('href').startsWith('#') ||
      /^[a-z_]+-(?:desktop|mobile)\.html$/.test(a.getAttribute('href'))),
    frameSandbox:frame?.getAttribute('sandbox'),frameSrc:frame?.getAttribute('src'),
    frameTitle:frame?.getAttribute('title'),frameTabindex:frame?.getAttribute('tabindex'),qa:document.querySelectorAll('.qa-list li').length,
    controls:document.querySelectorAll('form,button,input,script,object,embed').length
  };
})()"""


def read_local_devtools_port(port_file: Path) -> int | None:
    """DevToolsActivePort can exist briefly while Chrome is still writing it."""
    try:
        lines = port_file.read_text(encoding="utf-8").splitlines()
    except (FileNotFoundError, OSError, UnicodeError):
        return None
    if len(lines) < 2 or not lines[0].isdigit() or not lines[1].startswith("/devtools/browser/"):
        return None
    port = int(lines[0])
    return port if 1 <= port <= 65535 else None


def run(gallery: Path, browser: str) -> None:
    manifest = json.loads((gallery/"gallery-manifest.json").read_text())
    assert manifest["syntheticOnly"] is True
    assert manifest["publishable"] is False
    assert manifest["officialClientAcceptance"] is False
    expected = {f"{p}-{d}.html" for p in ("onepage", "landing_page")
                for d in ("desktop", "mobile")}
    assert {entry["filename"] for entry in manifest["pages"]} == expected

    with tempfile.TemporaryDirectory(prefix="sites-f43-chromium-", ignore_cleanup_errors=True) as temp:
        profile = Path(temp)/"profile"
        args = [browser, "--headless=new", "--no-sandbox", "--disable-gpu",
                "--disable-background-networking", "--disable-sync",
                "--disable-extensions", "--no-first-run",
                "--no-default-browser-check", "--remote-debugging-port=0",
                f"--user-data-dir={profile}", "about:blank"]
        chrome = subprocess.Popen(args, stdout=subprocess.DEVNULL,
                                  stderr=subprocess.DEVNULL)
        try:
            # Verify parser resilience to the *partial* file that Chrome
            # sometimes exposes before it has written the port and endpoint.
            assert read_local_devtools_port(Path(temp)/"missing-port") is None
            partial = profile/"DevToolsActivePort"
            port = None
            for _ in range(200):
                if chrome.poll() is not None:
                    raise RuntimeError("Browser exited before DevTools startup")
                port = read_local_devtools_port(partial)
                if port is not None:
                    break
                time.sleep(.1)
            if port is None:
                raise TimeoutError("Chrome DevTools port file was never complete")
            tabs = None
            for _ in range(50):
                try:
                    with urlopen(f"http://127.0.0.1:{port}/json/list", timeout=3) as reply:
                        candidate = json.loads(reply.read())
                    if isinstance(candidate, list) and any(
                        isinstance(tab, dict) and tab.get("type") == "page"
                        for tab in candidate
                    ):
                        tabs = candidate
                        break
                except (OSError, ValueError):
                    pass
                if chrome.poll() is not None:
                    raise RuntimeError("Browser exited before DevTools endpoint was ready")
                time.sleep(.1)
            if tabs is None:
                raise TimeoutError("Chrome DevTools page endpoint was never ready")
            page = next(tab for tab in tabs if tab["type"] == "page")
            endpoint = page["webSocketDebuggerUrl"].split(f":{port}", 1)[1]
            client = CDP(port, endpoint)
            try:
                client.call("Page.enable")
                client.call("Runtime.enable")
                for filename in sorted(expected):
                    product, device = filename[:-5].split("-")
                    width, height = (390, 844) if device == "mobile" else (1365, 900)
                    client.call("Emulation.setDeviceMetricsOverride", {
                        "width": width, "height": height,
                        "deviceScaleFactor": 1, "mobile": device == "mobile"
                    })
                    # Use the *exact* HTML generated by F1-41. SetDocumentContent
                    # avoids file:// policies and does not open an HTTP server.
                    frame_id = client.call("Page.navigate", {"url": "about:blank"})["frameId"]
                    client.call("Page.setDocumentContent", {
                        "frameId": frame_id,
                        "html": (gallery/filename).read_text(encoding="utf-8")
                    })
                    for _ in range(80):
                        if client.evaluate(
                            "document.readyState==='complete' && !!document.querySelector('.browser')"
                        ):
                            break
                        time.sleep(.1)
                    else:
                        raise RuntimeError(f"Chrome did not render offline fixture {filename}")
                    result = client.evaluate(METRICS)
                    assert result["width"] == width, (filename, result)
                    assert result["scroll"] <= width + 2, (filename, result)
                    assert result["bodyScroll"] <= width + 2, (filename, result)
                    assert result["lang"] == "pt-BR", (filename, result)
                    assert result["main"] == 1 and result["headings"] == 1, (filename, result)
                    assert result["navSelected"] == [1, 1], (filename, result)
                    assert len(result["links"]) == 5, (filename, result)
                    assert result["linksVisible"] and result["navigationLocal"], (filename, result)
                    assert result["frameSandbox"] == "" and not result["frameSrc"], (filename, result)
                    assert result["frameTitle"] and result["frameTabindex"] == "0" and result["qa"] == 7, (filename, result)
                    assert result["controls"] == 0, (filename, result)
                    assert_review_text_contrast(client, filename)

                    # Chromium also focuses the sandbox iframe (and its
                    # inner navigation). Verify the first four outer links in
                    # order and that Tab eventually reaches the final footer
                    # anchor, instead of assuming the iframe is not tabbable.
                    client.call("Page.bringToFront")
                    client.evaluate("document.activeElement.blur(); document.body.focus()")
                    focused = []
                    key = {"key": "Tab", "code": "Tab",
                           "windowsVirtualKeyCode": 9, "nativeVirtualKeyCode": 9}
                    for _ in range(16):
                        client.call("Input.dispatchKeyEvent", {"type": "rawKeyDown", **key})
                        client.call("Input.dispatchKeyEvent", {"type": "keyUp", **key})
                        focused.append(client.evaluate(
                            "document.activeElement?.getAttribute('href') || document.activeElement?.tagName"
                        ))
                        if len(focused) >= 5 and focused[-1] == result["links"][-1]:
                            break
                    assert focused[:4] == result["links"][:4], (
                        filename, 'initial keyboard navigation', focused, result["links"]
                    )
                    assert result["links"][-1] in focused[4:], (
                        filename, 'keyboard trapped before final link', focused
                    )
                    # Chrome may report transient BODY instead of IFRAME
                    # while changing focus to a sandboxed srcdoc. Do not
                    # equate that observation with verified inner-frame focus.
                    # Accessibility of inner content needs separate review.
                    assert all(step in ('IFRAME','BODY') for step in focused[4:-1]), (
                        filename, 'unexpected intermediate keyboard target', focused
                    )
                    print(f"PASS F1-43 {filename}: {width}x{height}, no horizontal overflow, "
                          "Tab reaches footer after four local links; iframe tabindex=0")
            finally:
                client.close()
        finally:
            chrome.terminate()
            try:
                chrome.wait(timeout=5)
            except subprocess.TimeoutExpired:
                chrome.kill()
                chrome.wait(timeout=5)


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Usage: f43_cdp.py <private-gallery> <chromium-executable>")
    run(Path(sys.argv[1]).resolve(strict=True), sys.argv[2])
