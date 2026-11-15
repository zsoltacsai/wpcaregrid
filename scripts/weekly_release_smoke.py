#!/usr/bin/env python3
"""Read-only staging marketing smoke checks for the fixed staging hostname."""

from __future__ import annotations

import hashlib
import json
import re
import sys
import urllib.error
import urllib.request


BASE = "https://stg1.wpcaregrid.com"
LOCALES = {"/hu/": "hu", "/en/": "en"}
DENIED = ("/checkout", "/login", "/register", "/api/v1/connector/jobs")
MAX_BODY = 2_000_000


class SmokeFailure(RuntimeError):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, new_url):
        return None


def _request(path: str, *, opener=None) -> tuple[int, object, bytes]:
    if not path.startswith("/") or ".." in path or "?" in path or "#" in path:
        raise SmokeFailure("path_invalid")
    open_request = opener or urllib.request.build_opener(NoRedirect, urllib.request.ProxyHandler({}))
    request = urllib.request.Request(BASE + path, method="GET", headers={"User-Agent": "WPCG-weekly-smoke/1"})
    try:
        response = open_request.open(request, timeout=8)
    except urllib.error.HTTPError as error:
        try:
            body = error.read(MAX_BODY + 1)
            headers = error.headers
            status = error.code
        finally:
            error.close()
        if len(body) > MAX_BODY:
            raise SmokeFailure("response_too_large")
        return status, headers, body
    except (urllib.error.URLError, TimeoutError, OSError):
        raise SmokeFailure("request_unavailable") from None
    with response:
        body = response.read(MAX_BODY + 1)
        if len(body) > MAX_BODY:
            raise SmokeFailure("response_too_large")
        return response.status, response.headers, body


def run_smoke(*, opener=None) -> dict[str, object]:
    results = []
    for path, locale in LOCALES.items():
        status, headers, body = _request(path, opener=opener)
        if status != 200 or len(body) > MAX_BODY:
            raise SmokeFailure("marketing_page_unhealthy")
        text = body.decode("utf-8", errors="replace")
        if not re.search(rf"<html\b[^>]*\blang=[\"']{locale}(?:-[A-Za-z0-9-]+)?[\"']", text, re.I):
            raise SmokeFailure("marketing_locale_mismatch")
        if not headers.get("Strict-Transport-Security"):
            raise SmokeFailure("https_security_header_missing")
        results.append({"path": path, "status": status, "body_sha256": hashlib.sha256(body).hexdigest()})
    for path in DENIED:
        status, _headers, _body = _request(path, opener=opener)
        if status != 404:
            raise SmokeFailure("marketing_only_route_open")
        results.append({"path": path, "status": status})
    return {"schema": "wpcg-staging-marketing-smoke-v1", "status": "PASS",
            "host": "stg1.wpcaregrid.com", "results": results}


def main() -> int:
    try:
        result = run_smoke()
    except SmokeFailure as error:
        print(json.dumps({"status": "FAIL", "reason": str(error)}, separators=(",", ":")), file=sys.stderr)
        return 1
    print(json.dumps(result, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
