#!/usr/bin/env python3
"""F1-395: localhost-only ephemeral mTLS Portal acceptance listener for CI."""
import argparse
import http.server
import json
import pathlib
import ssl


class Handler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path != "/internal/sites/v1/approvals/resolve":
            self.respond(404, {"error": "not-found"})
            return
        if not self.connection.getpeercert():
            self.respond(403, {"error": "client-certificate-required"})
            return
        try:
            length = int(self.headers.get("Content-Length", "-1"))
            if (self.headers.get("Content-Type") != "application/json"
                    or length < 2 or length > 2048):
                self.respond(415, {"error": "invalid-body"})
                return
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            receipt = json.loads(self.server.proof.read_text("utf-8"))
            if (
                not isinstance(payload, dict)
                or list(payload) != [
                    "schemaVersion", "tenantRef", "siteRef", "releaseRef",
                    "artifactDigest", "editorialRevision"
                ]
                or payload["schemaVersion"] != "sites.portal-client-approval-fetch.v1"
                or any(payload[key] != receipt[key] for key in (
                    "tenantRef", "siteRef", "releaseRef", "artifactDigest",
                    "editorialRevision"
                ))
            ):
                self.respond(403, {"error": "release-mismatch"})
                return
            mode = self.server.mode.read_text("utf-8").strip()
            if mode == "ok":
                self.respond(200, receipt)
            elif mode == "tamper":
                receipt["artifactDigest"] = "f" * 64
                self.respond(200, receipt)
            elif mode == "redirect":
                self.send_response(302)
                self.send_header("Location", "https://example.com/")
                self.send_header("Content-Length", "0")
                self.end_headers()
            elif mode == "oversize":
                self.respond(200, {"unexpected": "x" * 6000})
            elif mode == "unavailable":
                self.respond(503, {"error": "offline"})
            elif mode == "invalid":
                data = b"<html>invalid</html>"
                self.send_response(200)
                self.send_header("Content-Type", "text/html")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
            elif mode == "wrong_content_type":
                data = json.dumps(receipt).encode("utf-8")
                self.send_response(200)
                self.send_header("Content-Type", "text/plain")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)
            else:
                self.respond(500, {"error": "fixture-unavailable"})
        except (ValueError, TypeError, KeyError, UnicodeError):
            self.respond(422, {"error": "invalid-request"})

    def respond(self, status, payload):
        data = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, fmt, *args):
        # Never log request bodies, client certificates or opaque identifiers.
        pass


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--ca", required=True)
    parser.add_argument("--server-cert", required=True)
    parser.add_argument("--server-key", required=True)
    parser.add_argument("--fixture-dir", required=True)
    args = parser.parse_args()
    with http.server.ThreadingHTTPServer(("127.0.0.1", 18444), Handler) as server:
        server.proof = pathlib.Path(args.fixture_dir) / "proof.json"
        server.mode = pathlib.Path(args.fixture_dir) / "mode.txt"
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.minimum_version = ssl.TLSVersion.TLSv1_2
        tls.verify_mode = ssl.CERT_REQUIRED
        tls.load_verify_locations(cafile=args.ca)
        tls.load_cert_chain(args.server_cert, args.server_key)
        server.socket = tls.wrap_socket(server.socket, server_side=True)
        server.serve_forever(poll_interval=0.1)


if __name__ == "__main__":
    main()
