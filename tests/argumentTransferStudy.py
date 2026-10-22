#!/usr/bin/env python3
"""Read-only API transfer study. No purge, write, supplied wikitext or cache buster.

WIKIDEBIA_API_TOKEN is used only in the configured header on the two wiki hosts.
Reports contain public page metadata, hashes, sizes and timings, never the token
or the downloaded HTML. Fragment response sizes are local projections, not a
measurement of an endpoint that exists on the sites.
"""
import argparse
import concurrent.futures
import copy
import datetime
import gzip
import hashlib
import json
from pathlib import Path
import random
import statistics
import subprocess
import os
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
HOSTS = {"fr.wikidebates.org": ("fr", "Catégorie:Arguments"),
         "en.wikidebates.org": ("en", "Category:Arguments")}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError("API redirects are not followed by this study")


def encode(data):
    return json.dumps(data, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def distribution(values):
    ordered = sorted(values)
    return {"count": len(values), "median": statistics.median(values),
            "min": ordered[0], "max": ordered[-1]}


class Client:
    def __init__(self, host, token):
        if host not in HOSTS:
            raise ValueError("Unconfigured wiki host")
        self.host = host
        self.token = token
        self.count = 0
        self.opener = urllib.request.build_opener(NoRedirect())

    def get(self, params):
        url = "https://" + self.host + "/w/api.php?" + urllib.parse.urlencode(
            {**params, "format": "json", "formatversion": 2})
        req = urllib.request.Request(url, method="GET", headers={
            "X-Wikidebia-Access": self.token,
            "User-Agent": "Wikidebates-read-only-transfer-study",
            "Accept-Encoding": "gzip"})
        start = time.perf_counter()
        with self.opener.open(req, timeout=30) as response:
            headers_ms = (time.perf_counter() - start) * 1000
            raw = response.read()
            total_ms = (time.perf_counter() - start) * 1000
            encoding = response.headers.get("Content-Encoding", "identity")
            body = gzip.decompress(raw) if encoding == "gzip" else raw
            data = json.loads(body)
            meta = {"httpStatus": response.status, "contentEncoding": encoding,
                    "bodyBytes": len(raw), "decodedBodyBytes": len(body),
                    "responseHeadersMs": headers_ms, "totalMs": total_ms,
                    "httpCache": {key: response.headers.get(key) for key in
                                  ["CF-Cache-Status", "Age", "Cache-Control", "Vary"]}}
        self.count += 1
        time.sleep(0.2)  # At most one request at a time per host, with a pause.
        return data, meta


class Extractor:
    def __enter__(self):
        self.process = subprocess.Popen(["node", str(ROOT / "tests/argumentTransferExtract.cjs")],
                                        stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                        text=True, encoding="utf-8")
        return self

    def extract(self, html):
        self.process.stdin.write(json.dumps({"html": html}, ensure_ascii=False) + "\n")
        self.process.stdin.flush()
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError("The production JavaScript extractor failed")
        return json.loads(line)

    def __exit__(self, *args):
        self.process.stdin.close()
        self.process.stdout.close()
        if self.process.wait(timeout=5) != 0:
            raise RuntimeError("Extractor exited unsuccessfully")


def sample_case(client, title, language, skin, repeats, extractor):
    params = {"action": "parse", "page": title, "prop": "text", "usearticle": 1,
              "uselang": language, "useskin": skin, "redirects": 1}
    discovery, discovery_meta = client.get({**params, "prop": "text|sections|revid"})
    if "error" in discovery:
        return {"title": title, "skin": skin, "error": discovery["error"]["code"]}
    sections = discovery["parse"].get("sections", [])
    anchors = [section.get("anchor") for section in sections]
    summary = next((section for section in sections if section.get("anchor") == "Summary"), None)
    # Generated headings may have no editable wikitext section index. Probe 1
    # rather than claiming that the visible number is necessarily a valid index.
    section_index = summary.get("index") if summary else None
    probe, probe_meta = client.get({**params, "section": section_index or "1"})
    samples = []
    fragment_hashes = set()
    for _ in range(repeats):
        full, meta = client.get(params)
        if "error" in full:
            samples.append({**meta, "error": full["error"]["code"]})
            continue
        html = full["parse"]["text"]
        extraction = extractor.extract(html)
        fragment = extraction.pop("fragment")
        if not fragment:
            raise RuntimeError("The selected page has no production reading fragment")
        fragment_hash = hashlib.sha256(fragment.encode()).hexdigest()
        fragment_hashes.add(fragment_hash)
        candidate = copy.deepcopy(full)
        candidate["parse"]["text"] = fragment
        full_encoded = encode(full)
        fragment_encoded = encode(candidate)
        full_gzip = len(gzip.compress(full_encoded, compresslevel=6, mtime=0))
        fragment_gzip = len(gzip.compress(fragment_encoded, compresslevel=6, mtime=0))
        samples.append({**meta, **extraction, "htmlUtf8Bytes": len(html.encode()),
                        "fragmentUtf8Bytes": len(fragment.encode()), "fragmentSha256": fragment_hash,
                        "projected": {"fullJsonBytes": len(full_encoded),
                                      "fragmentJsonBytes": len(fragment_encoded),
                                      "fullGzipBytes": full_gzip, "fragmentGzipBytes": fragment_gzip,
                                      "gzipBytesSaved": full_gzip - fragment_gzip,
                                      "gzipReduction": 1 - fragment_gzip / full_gzip}})
    valid = [sample for sample in samples if "error" not in sample]
    if not valid:
        raise RuntimeError("All full-page requests failed")
    expected_fragment = extractor.extract(discovery["parse"]["text"])["fragment"]
    probe_html = probe.get("parse", {}).get("text", "")
    return {"title": title, "skin": skin, "language": language,
            "revisionAtDiscovery": discovery["parse"].get("revid"),
            "sections": [{"anchor": section.get("anchor"), "index": section.get("index"),
                          "number": section.get("number")} for section in sections],
            "displayedHeadingAnchors": anchors[anchors.index("Summary"):anchors.index("Parent_debates")]
            if "Summary" in anchors and "Parent_debates" in anchors else [],
            "discoveryRequest": discovery_meta,
            "sectionProbe": {**probe_meta, "requestedSection": section_index or "1",
                             "apiError": probe.get("error", {}).get("code"),
                             "exactlyMatchesReadingFragment": probe_html == expected_fragment},
            "fragmentStableAcrossRepeatedReads": len(fragment_hashes) == 1,
            "samples": samples,
            "summary": {"httpTotalMs": distribution([sample["totalMs"] for sample in valid]),
                        "httpBodyBytes": distribution([sample["bodyBytes"] for sample in valid]),
                        "extractionMedianMs": statistics.median(sample["extractionMedianMs"] for sample in valid),
                        "projectedGzipBytesSaved": statistics.median(sample["projected"]["gzipBytesSaved"] for sample in valid),
                        "projectedGzipReduction": statistics.median(sample["projected"]["gzipReduction"] for sample in valid)}}


def study_host(host, token, repeats):
    language, category = HOSTS[host]
    client = Client(host, token)
    site, _ = client.get({"action": "query", "meta": "siteinfo", "siprop": "general"})
    corpus, _ = client.get({"action": "query", "generator": "categorymembers", "gcmtitle": category,
                            "gcmnamespace": 0, "gcmlimit": 500, "gcmsort": "timestamp", "gcmdir": "desc",
                            "prop": "info"})
    large, _ = client.get({"action": "query", "generator": "allpages", "gapnamespace": 0,
                          "gapminsize": 10000, "gaplimit": 30, "gapfilterredir": "nonredirects",
                          "prop": "categories|info", "clcategories": category})
    large_pages = [page for page in large.get("query", {}).get("pages", []) if page.get("categories")]
    by_id = {page["pageid"]: page for page in corpus.get("query", {}).get("pages", []) + large_pages
             if not page.get("missing") and not page.get("redirect")}
    pages = sorted(by_id.values(), key=lambda page: page["length"])
    if len(pages) < 3:
        raise RuntimeError("Not enough public arguments in the selected category")
    selected = [pages[0], pages[len(pages) // 2], pages[-1]]
    cases = [(page, skin) for page in selected for skin in ["vector", "minerva"]]
    random.Random(43).shuffle(cases)
    results = []
    with Extractor() as extractor:
        for page, skin in cases:
            case = sample_case(client, page["title"], language, skin, repeats, extractor)
            case["storedPageBytes"] = page["length"]
            results.append(case)
            print(host + " " + skin + ": case " + str(len(results)) + "/6 complete", flush=True)
    return {"mediaWikiGenerator": site.get("query", {}).get("general", {}).get("generator"),
            "selection": "Smallest, median and largest stored page among 500 latest category memberships plus arguments in the first 30 mainspace pages of at least 10000 bytes; not a representative audience sample or exhaustive largest-page search.",
            "candidateCount": len(pages), "largeCandidateCount": len(large_pages),
            "requests": client.count, "cases": results}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True)
    parser.add_argument("--repeats", type=int, default=3, choices=range(1, 6))
    args = parser.parse_args()
    token = os.environ.get("WIKIDEBIA_API_TOKEN")
    if not token:
        raise SystemExit("The configured WIKIDEBIA_API_TOKEN is required; never pass it on the command line")
    report = {"collectedAt": datetime.datetime.now(datetime.timezone.utc).isoformat(),
              "repositoryHead": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
              "methodology": {"requests": "GET only, no cache busting, purge or writes; at most one request per wiki at a time.",
                              "samples": str(args.repeats) + " repeated full-page requests after section discovery and probe; no forced cold-cache run.",
                              "sizes": "HTTP body bytes observed with explicit gzip negotiation, excluding headers and TLS overhead.",
                              "projection": "Identical local JSON encoder and gzip level 6 for full and fragment responses; not a deployed endpoint.",
                              "timing": "Network totals include proxy, connection, TLS, wiki and transfer. No server CPU or parser-cache hit ratio is inferred.",
                              "cache": "HTTP cache headers are not parser-cache evidence. Parser-cache eligibility is checked separately in MediaWiki source.",
                              "extractor": "The actual modules/reading/core.js function, executed by Node; 21 local extraction samples per response.",
                              "privacy": "Only public metadata, sizes, hashes and timings saved. No secret values or downloaded HTML saved."},
              "hosts": {}}
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        jobs = {host: pool.submit(study_host, host, token, args.repeats) for host in HOSTS}
        for host, job in jobs.items():
            report["hosts"][host] = job.result()
    Path(args.output).write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n")
    print("Read-only transfer study complete: " + str(sum(host["requests"] for host in report["hosts"].values())) + " GET requests")


if __name__ == "__main__":
    main()
