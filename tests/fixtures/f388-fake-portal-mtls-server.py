#!/usr/bin/env python3
"""F1-388: disposable local HTTPS+mutual-TLS fake Portal. CI only."""
import argparse
import http.server
import json
import pathlib
import ssl


class Handler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path != "/internal/sites/v1/entitlements/resolve":
            self.reply(404, {"error": "not-found"})
            return
        client_cert = self.connection.getpeercert()
        if not client_cert:
            self.reply(403, {"error": "client-mtls-required"})
            return
        try:
            content_type = self.headers.get("Content-Type", "")
            size = int(self.headers.get("Content-Length", "-1"))
            if content_type != "application/json" or size < 2 or size > 2048:
                self.reply(415, {"error": "invalid-client-body"})
                return
            doc = json.loads(self.rfile.read(size).decode("utf-8"))
            proof = json.loads(self.server.proof.read_text("utf-8"))
            if (
                list(doc) != [
                    "schemaVersion", "tenantRef", "orderRef",
                    "contractRef", "contractRevision", "productCode",
                    "operationFingerprint"
                ]
                or doc["schemaVersion"] != "sites.portal-proof-fetch.v1"
                or any(doc[k] != proof[k] for k in (
                    "tenantRef", "orderRef", "contractRef",
                    "contractRevision", "productCode", "operationFingerprint"
                ))
            ):
                self.reply(403, {"error": "fictional-order-mismatch"})
                return
            mode = self.server.mode.read_text("utf-8").strip()
            if mode == "redirect":
                self.send_response(302)
                self.send_header("Location", "https://example.com/")
                self.send_header("Content-Length", "0")
                self.end_headers()
            elif mode == "unavailable":
                self.reply(503, {"error": "fictional-Portal-unavailable"})
            elif mode == "oversize":
                self.reply(200, {"extra": "x" * 6000})
            elif mode == "invalid":
                self.send_response(200)
                payload = b"<h1>HTML-is-not-a-proof</h1>"
                self.send_header("Content-Type", "text/html")
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)
            elif mode == "tamper":
                proof["settlementSha256"] = "f" * 64
                self.reply(200, proof)
            elif mode == "ok":
                self.reply(200, proof)
            else:
                self.reply(500, {"error": "fixture-mode-unavailable"})
        except (ValueError, TypeError, KeyError, UnicodeError):
            self.reply(422, {"error": "invalid-fixture-request"})

    def reply(self, code, value):
        payload = json.dumps(value, separators=(",", ":")).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, fmt, *args):
        # Never log order refs, POST bodies, certs, keys or other caller data.
        pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--ca", required=True)
    parser.add_argument("--server-cert", required=True)
    parser.add_argument("--server-key", required=True)
    parser.add_argument("--fixture-dir", required=True)
    args = parser.parse_args()
    with http.server.ThreadingHTTPServer(("127.0.0.1", 18443), Handler) as server:
        server.proof = pathlib.Path(args.fixture_dir) / "proof.json"
        server.mode = pathlib.Path(args.fixture_dir) / "mode.txt"
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.minimum_version = ssl.TLSVersion.TLSv1_2
        context.verify_mode = ssl.CERT_REQUIRED
        context.load_verify_locations(cafile=args.ca)
        context.load_cert_chain(args.server_cert, args.server_key)
        server.socket = context.wrap_socket(server.socket, server_side=True)
        server.serve_forever(poll_interval=0.1)


if __name__ == "__main__":
    main()
