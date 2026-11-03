#!/usr/bin/env python3
"""Read-only RPC contract check against an explicitly configured test wiki."""
import json
import os
import sys
import urllib.error
import urllib.request
import urllib.parse
import xmlrpc.client

METHOD = "plugin.approve.getLatestApprovedRevision"


def check_same(actual, expected):
    if type(actual) is not type(expected):
        raise AssertionError(f"Expected {type(expected).__name__}, got {type(actual).__name__}: {actual!r}")
    if isinstance(expected, dict):
        if actual.keys() != expected.keys():
            raise AssertionError(f"Unexpected fields: {actual!r}, expected {expected!r}")
        for key in expected:
            check_same(actual[key], expected[key])
    elif actual != expected:
        raise AssertionError(f"Got {actual!r}, expected {expected!r}")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def post(url, token, body, content_type):
    request = urllib.request.Request(url, data=body, headers={
        "Authorization": "Bearer " + token,
        "Content-Type": content_type,
    })
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=10) as response:
            return response.read()
    except urllib.error.HTTPError as error:
        return error.read()


def invoke(base, token, transport, page):
    if transport == "json":
        body = json.dumps({"page": page}).encode()
        response = json.loads(post(base + "/lib/exe/jsonrpc.php/" + METHOD, token, body, "application/json"))
        error = response.get("error") or {}
        if error.get("code", 0):
            return None, str(error.get("message", ""))
        if "result" not in response:
            raise AssertionError(f"Missing JSON-RPC result: {response!r}")
        return response["result"], None
    body = xmlrpc.client.dumps((page,), methodname=METHOD).encode()
    response = post(base + "/lib/exe/xmlrpc.php", token, body, "text/xml")
    try:
        values, _ = xmlrpc.client.loads(response)
        if len(values) != 1:
            raise AssertionError("Expected one XML-RPC result")
        return values[0], None
    except xmlrpc.client.Fault as error:
        return None, error.faultString


def main():
    path = os.environ.get("APPROVE_RPC_FIXTURES")
    token = os.environ.get("APPROVE_RPC_TOKEN")
    if not path or not token:
        raise SystemExit("Set APPROVE_RPC_FIXTURES to a fixture JSON file and APPROVE_RPC_TOKEN to a read-only token.")
    with open(path, encoding="utf-8") as handle:
        config = json.load(handle)
    base = config["base_url"].rstrip("/")
    parts = urllib.parse.urlsplit(base)
    if parts.scheme != "https" or not parts.hostname or parts.username or parts.password or parts.query or parts.fragment:
        raise SystemExit("The test wiki must use HTTPS without credentials, query or fragment.")
    required = {"approved", "unapproved", "without_version", "hidden", "denied", "missing"}
    if set(config["cases"]) != required:
        raise SystemExit("Fixture cases must be: " + ", ".join(sorted(required)))
    for transport in ("json", "xml"):
        for name, case in config["cases"].items():
            actual, error = invoke(base, token, transport, case["page"])
            if name in ("denied", "missing"):
                if error is None or case["error"].lower() not in error.lower():
                    raise AssertionError(f"{transport} {name}: unexpected error {error!r}")
            else:
                if error is not None:
                    raise AssertionError(f"{transport} {name}: {error}")
                expected = case["expected"]
                check_same(actual, expected)
                if name in ("unapproved", "hidden"):
                    check_same(expected, {"found": False})
                if name == "approved" and (expected.get("found") is not True or not isinstance(expected.get("version"), int)):
                    raise AssertionError("The approved fixture must have a version")
                if name == "without_version" and (expected.get("found") is not True or "version" in expected):
                    raise AssertionError("The without_version fixture must omit version")
            print(f"[PASS] {transport}: {name}")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, OSError, ValueError, xmlrpc.client.Error) as error:
        print(f"[FAIL] {error}", file=sys.stderr)
        sys.exit(1)
