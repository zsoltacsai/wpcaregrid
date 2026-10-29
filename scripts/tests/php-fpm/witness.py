#!/usr/bin/env python3
import argparse
import json
import socket
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.request import urlopen

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != "/healthz":
            self.send_error(404)
            return
        body = b"PHP85_FPM_CONTROL_WITNESS_OK"
        self.send_response(200)
        self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_args):
        pass

class IPv6Server(ThreadingHTTPServer):
    address_family = socket.AF_INET6
    daemon_threads = True
    allow_reuse_address = True

class IPv4Server(ThreadingHTTPServer):
    address_family = socket.AF_INET
    daemon_threads = True
    allow_reuse_address = True

parser = argparse.ArgumentParser()
parser.add_argument("mode", choices=["start", "verify"])
parser.add_argument("ready_file")
args = parser.parse_args()
ready_file = Path(args.ready_file)

if args.mode == "start":
    server4 = IPv4Server(("127.0.0.1", 0), Handler)
    server6 = IPv6Server(("::1", 0), Handler)
    ready_file.write_text(json.dumps({"ipv4": server4.server_port, "ipv6": server6.server_port}))
    threads = [
        threading.Thread(target=server4.serve_forever, daemon=True),
        threading.Thread(target=server6.serve_forever, daemon=True),
    ]
    for thread in threads:
        thread.start()
    try:
        while True:
            time.sleep(60)
    finally:
        server4.shutdown()
        server6.shutdown()
else:
    try:
        socket.getaddrinfo("example.com", 443, socket.AF_INET, socket.SOCK_STREAM)
    except OSError:
        raise SystemExit("Runner-side external DNS control failed.")
    ports = json.loads(ready_file.read_text())
    for url in (f"http://127.0.0.1:{ports['ipv4']}/healthz",
                f"http://[::1]:{ports['ipv6']}/healthz"):
        with urlopen(url, timeout=2) as response:
            if response.status != 200 or response.read() != b"PHP85_FPM_CONTROL_WITNESS_OK":
                raise SystemExit("Runner-side controlled reachability witness failed.")
    print("IPv4 and IPv6 runner-side control witnesses are reachable.")
