#!/usr/bin/env python3
"""Consistency check for a repository before a release (and in CI on every push).

    python consistency_check.py [--config tools/consistency.json] [--root .] [--version 0.3.0] [--offline]

Reports, never fixes. Exit code 1 when a check with severity "error" finds something.
The checks are generic; what they look at comes from the per-repo JSON config:

  listed           every item a regex extracts from source files must appear in target files
                   (config keys in the docs table, HTTP routes in the API docs, ...)
  mentioned        every file name (without extension) under some globs must appear in a doc
                   (module lists in a design document)
  retired          terms that must not appear any more, except in allowed paths
  closed_issues    references to closed GitHub issues in user-facing docs (#12, /issues/12)
  changelog        Keep-a-Changelog structure: Unreleased first, versions dated and descending,
                   no duplicate headings inside a version; with --version that version must exist
  fresh            an image must not be older (last commit) than the sources it shows
  coverage         a coverage number quoted in a doc must match the JaCoCo report (if present)

Stdlib only; uses git for dates and the GitHub CLI (or GH_TOKEN) for issue states.
"""
import argparse, fnmatch, glob, json, os, pathlib, re, subprocess, sys, urllib.request

findings: list[tuple[str, str, str]] = []   # (severity, check, message)


def report(sev: str, check: str, msg: str) -> None:
    findings.append((sev, check, msg))


def files(root: pathlib.Path, patterns: list[str]) -> list[pathlib.Path]:
    out = []
    for p in patterns:
        out += [pathlib.Path(x) for x in glob.glob(str(root / p), recursive=True) if os.path.isfile(x)]
    return sorted(set(out))


def rel(root: pathlib.Path, p: pathlib.Path) -> str:
    return p.resolve().relative_to(root.resolve()).as_posix()


def read(p: pathlib.Path) -> str:
    return p.read_text(encoding="utf-8", errors="replace")


def tracked(root: pathlib.Path) -> list[str]:
    try:
        return subprocess.run(["git", "ls-files"], cwd=root, capture_output=True, text=True, check=True).stdout.split("\n")
    except Exception:
        return [rel(root, p) for p in root.rglob("*") if p.is_file() and ".git" not in p.parts]


def last_commit(root: pathlib.Path, path: str) -> int:
    r = subprocess.run(["git", "log", "-1", "--format=%ct", "--", path], cwd=root, capture_output=True, text=True)
    return int(r.stdout.strip() or 0)


# ---------------------------------------------------------------- checks

def check_listed(root, c):
    text = "\n".join(read(p) for p in files(root, c["source"]))
    items = set()
    for m in re.finditer(c["pattern"], text, re.M):
        items.update(g for g in m.groups() if g) if m.groups() else items.add(m.group(0))
    items -= set(c.get("ignore", []))
    for target in c["targets"]:
        tfiles = files(root, [target["file"]])
        ttext = "\n".join(read(p) for p in tfiles)
        fmt = target.get("format", "{}")
        for item in sorted(items):
            needle = fmt.replace("{}", item)
            found = re.search(needle, ttext, re.M) if target.get("regex") else needle in ttext
            if not found:
                report(c.get("severity", "error"), c["name"], f"{item!r} from {', '.join(c['source'])} is missing in {target['file']}")


def check_mentioned(root, c):
    doc = "\n".join(read(p) for p in files(root, c["docs"]))
    for p in files(root, c["files"]):
        name = p.stem
        if name in c.get("ignore", []):
            continue
        if not re.search(r"\b" + re.escape(name) + r"\b", doc):
            report(c.get("severity", "warn"), c["name"], f"{rel(root, p)} ({name}) is not mentioned in {', '.join(c['docs'])}")


def check_retired(root, c):
    paths = [p for p in tracked(root) if p and any(fnmatch.fnmatch(p, g) for g in c.get("scope", ["*"]))]
    for term in c["terms"]:
        rx = re.compile(term["regex"] if "regex" in term else re.escape(term["term"]), 0 if term.get("case", True) else re.I)
        for path in paths:
            if any(fnmatch.fnmatch(path, g) for g in term.get("allow_in", []) + c.get("allow_in", [])):
                continue
            fp = root / path
            if not fp.is_file():
                continue
            for n, line in enumerate(read(fp).split("\n"), 1):
                if rx.search(line):
                    report(term.get("severity", c.get("severity", "error")), c["name"],
                           f"{path}:{n}: {term.get('term', term.get('regex'))!r} ({term.get('hint', 'retired term')}): {line.strip()[:120]}")


def closed_issues(root, offline) -> set[int] | None:
    if offline:
        return None
    try:
        r = subprocess.run(["gh", "issue", "list", "--state", "closed", "--limit", "1000", "--json", "number"],
                           cwd=root, capture_output=True, text=True, timeout=60)
        if r.returncode == 0:
            return {x["number"] for x in json.loads(r.stdout)}
    except Exception:
        pass
    repo, token = os.environ.get("GITHUB_REPOSITORY"), os.environ.get("GH_TOKEN") or os.environ.get("GITHUB_TOKEN")
    if repo and token:
        out, page = set(), 1
        while True:
            req = urllib.request.Request(f"https://api.github.com/repos/{repo}/issues?state=closed&per_page=100&page={page}",
                                         headers={"Authorization": f"Bearer {token}", "Accept": "application/vnd.github+json"})
            data = json.loads(urllib.request.urlopen(req, timeout=30).read())
            out |= {x["number"] for x in data if "pull_request" not in x}
            if len(data) < 100:
                return out
            page += 1
    return None


def check_closed_issues(root, c, offline):
    closed = closed_issues(root, offline)
    if closed is None:
        report("info", c["name"], "issue states unavailable (no gh / GH_TOKEN, or --offline); skipped")
        return
    rx = re.compile(r"(?:issues/|(?<![\w&])#)(\d+)\b")
    for p in files(root, c["docs"]):
        if any(fnmatch.fnmatch(rel(root, p), g) for g in c.get("ignore", [])):
            continue
        for n, line in enumerate(read(p).split("\n"), 1):
            for num in sorted({int(m.group(1)) for m in rx.finditer(line)} & closed):
                report(c.get("severity", "warn"), c["name"], f"{rel(root, p)}:{n}: refers to closed issue #{num}: {line.strip()[:120]}")


def check_changelog(root, c, version):
    p = root / c["file"]
    if not p.is_file():
        report("error", c["name"], f"{c['file']} missing"); return
    text = read(p)
    heads = list(re.finditer(r"(?m)^## \[([^\]]+)\](?: - (\d{4}-\d{2}-\d{2}))?", text))
    if not heads or heads[0].group(1).lower() != "unreleased":
        report(c.get("severity", "error"), c["name"], "the first section must be [Unreleased]")
    versions = []
    for i, h in enumerate(heads):
        body = text[h.end(): heads[i + 1].start() if i + 1 < len(heads) else len(text)]
        subs = re.findall(r"(?m)^### (.+)$", body)
        dup = {s for s in subs if subs.count(s) > 1}
        if dup:
            report(c.get("severity", "error"), c["name"], f"[{h.group(1)}] has duplicate sections: {', '.join(sorted(dup))}")
        if h.group(1).lower() != "unreleased":
            if not h.group(2):
                report(c.get("severity", "error"), c["name"], f"[{h.group(1)}] has no date")
            versions.append(h.group(1))
    def key(v): return tuple(int(x) if x.isdigit() else 0 for x in re.split(r"[.-]", v))
    if versions != sorted(versions, key=key, reverse=True):
        report(c.get("severity", "error"), c["name"], f"versions are not in descending order: {versions}")
    if version and version not in versions:
        report("error", c["name"], f"no dated section for {version}")


def check_fresh(root, c):
    for item in c["items"]:
        img = last_commit(root, item["image"])
        if not img:
            report(c.get("severity", "warn"), c["name"], f"{item['image']} is not committed"); continue
        newer = [s for s in item["sources"] for f in files(root, [s]) if last_commit(root, rel(root, f)) > img]
        if newer:
            report(c.get("severity", "warn"), c["name"], f"{item['image']} is older than changes to: {', '.join(sorted(set(newer)))}")


def check_coverage(root, c):
    doc = read(root / c["doc"]) if (root / c["doc"]).is_file() else ""
    for item in c["items"]:
        m = re.search(item["doc_regex"], doc)
        rep = files(root, [item["report"]])
        if not m or not rep:
            report("info", c["name"], f"{item['name']}: {'no number in ' + c['doc'] if not m else 'no report (run the tests first)'}; skipped"); continue
        counters = re.findall(r'<counter type="LINE" missed="(\d+)" covered="(\d+)"/>', read(rep[0]))
        if not counters:
            continue
        missed, covered = map(int, counters[-1])
        actual = round(100 * covered / (missed + covered), 1)
        if abs(actual - float(m.group(1))) > c.get("tolerance", 0.5):
            report(c.get("severity", "warn"), c["name"], f"{item['name']}: {c['doc']} says {m.group(1)} %, the report says {actual} %")


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--config", default="tools/consistency.json")
    ap.add_argument("--root", default=".")
    ap.add_argument("--version", help="the version about to be released (checks its changelog section)")
    ap.add_argument("--offline", action="store_true", help="skip checks that need the network (issue states)")
    ap.add_argument("--only", help="comma-separated check names")
    a = ap.parse_args()
    try: sys.stdout.reconfigure(encoding="utf-8", errors="replace")   # Windows consoles
    except Exception: pass
    root = pathlib.Path(a.root).resolve()
    cfg = json.loads((root / a.config).read_text(encoding="utf-8")) if not os.path.isabs(a.config) else json.loads(pathlib.Path(a.config).read_text(encoding="utf-8"))
    only = set(a.only.split(",")) if a.only else None
    for c in cfg["checks"]:
        if only and c["name"] not in only:
            continue
        t = c["type"]
        if t == "listed": check_listed(root, c)
        elif t == "mentioned": check_mentioned(root, c)
        elif t == "retired": check_retired(root, c)
        elif t == "closed_issues": check_closed_issues(root, c, a.offline)
        elif t == "changelog": check_changelog(root, c, a.version.removeprefix("v") if a.version else None)
        elif t == "fresh": check_fresh(root, c)
        elif t == "coverage": check_coverage(root, c)
        else: report("error", c.get("name", t), f"unknown check type {t!r}")
    order = {"error": 0, "warn": 1, "info": 2}
    for sev, check, msg in sorted(findings, key=lambda f: (order[f[0]], f[1])):
        print(f"{sev.upper():5} [{check}] {msg}")
    errors = sum(1 for f in findings if f[0] == "error"); warns = sum(1 for f in findings if f[0] == "warn")
    print(f"\n{errors} error(s), {warns} warning(s)" + ("" if findings else " - consistent"))
    sys.exit(1 if errors else 0)


if __name__ == "__main__":
    main()
