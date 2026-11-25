# TryPost: what we changed, and the security watch

Social HQ is a hard fork of TryPost v2.0.1 (owner decision 2026-11-25): TryPost's
files are ours, we never merge its feature releases, and we port its security
fixes by hand (logged below).

## What we changed in a file that came with TryPost

Ask git; it is exact and never out of date. The `v2.0.1` tag is on `origin`.

```bash
git log --oneline v2.0.1..main -- <path>     # every change we made to it, with the reason in each commit
git diff v2.0.1 main -- <path>               # what it looks like now against TryPost's
git diff --stat v2.0.1 main                  # every file that differs from TryPost
```

A path that doesn't exist at `v2.0.1` is ours. Write the reason for a change to a
TryPost file in its commit message; when it is a rule people must know, put it in
SOCIALHQ.md (or AGENTS.md for a convention).

**No more rows.** Until 2026-11-25 this file listed every change to a TryPost file,
one row each. That ledger is frozen, unchanged, in
[docs/history/PATCHES-ledger-2026-11-25.md](docs/history/PATCHES-ledger-2026-11-25.md)
(rows 1–356). Code comments and docs that cite "PATCHES.md" or "PATCHES.md row N"
mean that file. A branch started before the freeze that adds rows here drops them
when it merges: that is the whole resolution of its conflict in this file.

## Security intake

With the `socialhq-security-intake` skill: dependency advisories (`composer
audit`, `npm audit`, the PHP base image), then a read of TryPost's commits since
the last review in security-relevant areas (login, permissions, outbound requests,
the networks we publish to, webhooks, API, MCP), ported by hand. One dated row per
review, with the TryPost commit it was reviewed up to, "nothing found" included.

| Date | TryPost reviewed up to | Picked | Dependency upgrades |
|---|---|---|---|
| 2026-11-11 | Not reviewed this time (dependency advisories only) | — | Composer: phpseclib 3.0.58 and Symfony 8.1.11 (filesystem, http-foundation, http-kernel, routing, yaml, plus their 8.1/3.8 siblings), 19 packages, patch releases only; `composer audit` clean. npm (root): `npm audit fix` (vite 8.3.8, vue, dompurify 3.4.17, postcss, ws, shell-quote, protobufjs and more) plus the row 122 overrides; `npm audit` clean. npm (`maizzle/`): `npm audit fix` (markdown-it, js-yaml, undici, proxy-addr, baseline-browser-mapping and more) plus the row 123 overrides; `npm audit` clean, templates rebuild identical. Covers Dependabot PRs #17–#27 |
| 2026-11-25 | `ca2b4eac` (2026-11-24): all 39 commits since v2.0.1 read, about 127 security-relevant files | Ported by hand: the MCP rate limit per token (`b569076f`, row 346) and the token redaction in the Meta paging log (from `7647e06e`, row 345). Not security, ported for Insights: a Facebook video counted once (`ee8b3a56`, audit X6, row 347). The legacy time zone fix (`8a04f3fb`) was already in (row 5). Nothing else found | None. Upstream's lockfile changes are new packages for its features (Nightwatch 1.30.6, laravel-database-backup, Tiptap), no advisory among them |
