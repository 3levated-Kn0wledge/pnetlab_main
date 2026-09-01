# The licence position

**Status: assessment, 2026-09-01. Written for Phase 02.5. No decision taken.**

The roadmap says the licence position "gates going public as hard as any
security item and deserves a decision, not a deferral". This document is the
evidence for that decision. It does not take it: adopting a licence is the
owner's call, and no `LICENSE` file is added here.

**This is an engineering assessment, not legal advice.** Neither the author nor
the reader is a lawyer. Everything below is either a fact measured from the tree
(counts, paths, headers, upstream comparisons — all reproducible with the
commands shown) or an inference about what a licence *appears* to oblige. The
inferences are marked. Three points where a real opinion is warranted before
publishing are listed at the end, in their own section, so they are not lost in
the detail.

---

## The position in five sentences

The fork declares no licence, and neither does the thing it was forked from:
`pnetlab/pnetlab_main` has `"license": null` in the GitHub API and no `LICENSE`
file, which means PNETLab's own code is **all rights reserved by default** —
readable and forkable on GitHub, but with no grant to redistribute it anywhere
else. Layered under that is a substantial UNetLab/EVE-NG inheritance: **130
files carry an intact BSD-3-Clause notice**, which is a real and usable grant,
but **at least nine further files are provably the same lineage with the notice
removed or replaced**, which is the one condition BSD-3 actually imposes. On top
of both sit third-party components with their own terms, one of which —
CKEditor 5, **GPL-2.0-or-later** — is compiled into JavaScript bundles that are
committed to this repository. Two smaller items are unambiguous and cheap to
fix: a proprietary Monotype **Arial Bold** font file, and a 9.4 MB opaque
prebuilt binary of unknown provenance. The Cisco IOL keygen, which the brief
flagged as a likely blocker, **is not in this repository** — only a call site
that would run it if an operator put it there — and that is a defensible place
to be, though it should be made deliberate rather than incidental.

---

## 1 · What the repository declares today

Measured, not remembered.

| Question | Answer |
|---|---|
| Root `LICENSE` / `COPYING` / `NOTICE`? | **None.** `find` over the tree returns no root licence file of any name. |
| Licence files anywhere in the tree? | **Two**, both belonging to bundled third parties: `includes/Slim/LICENSE` (MIT, Josh Lockhart 2012) and `themes/default/fonts/LICENCE.txt` (Ubuntu Font Licence 1.0). |
| `composer.json` license field | `store/composer.json` line 8: `"license": "MIT"`. |
| `package.json` license field | **Absent.** Root `package.json` declares `"private": true` and no `license`. |
| `README.md` | Developer notes on templates and device files. No licence statement, no copyright line, no attribution to UNetLab or EVE-NG. |
| `SECURITY.md` | Disclosure policy only. |

### The `"license": "MIT"` in `store/composer.json` is not a licence grant

It is the stock field from the Laravel skeleton, and it survived the Laravel
5.5 → 10 rebuild. Read literally it asserts that the package named
`pnetlab/pnetlab` — the whole `store/` application, 150 PHP files under
`store/app` plus 295 React source files — is MIT. Nothing supports that: the
files it covers carry no copyright notice at all, the upstream repository they
came from carries no licence, and nobody in this fork's history had the standing
to relicense them.

That single line is the only licence declaration in the repository and it is
almost certainly wrong. It should be removed or corrected as part of whatever
decision is taken, and it should not be quoted to anyone as evidence of the
project's position in the meantime.

### The fork's own provenance

```
$ git log --format='%h %ad %an %s' --date=short --reverse | head -5
07ef833 2023-04-08 pnetlab  initial          (1386 files)
9d4eef6 2023-04-08 pnetlab  add frontend
f756e41 2023-04-08 pnetlab  add webpack mix
e18f2aa 2023-04-08 pnetlab  Create README.md
34fa767 2023-07-25 pnetlab  Update index.php
e10a3fc 2026-08-29 3levated-Kn0wledge  chore: add root .gitignore ...
```

This is a genuine GitHub fork. The API confirms it: `"fork": true`, `parent` and
`source` both `pnetlab/pnetlab_main`, and `"license": null` on both. Every one
of PNETLab's public repositories except two CKEditor forks is unlicensed:

| Repository | Licence | Fork |
|---|---|---|
| `pnetlab/pnetlab_main` | null | no |
| `pnetlab/pnetlab_wrapper` | null | no |
| `pnetlab/pnetlab-docker` | null | no |
| `pnetlab/pnetlab-guacmole` | null | no |
| `pnetlab/pnetlab-qemu` | null | no |
| `pnetlab/pnetlab-schema` | null | no |
| `pnetlab/pnetlab-vpcs` | null | no |
| `pnetlab/ckeditor5` | NOASSERTION | yes |
| `pnetlab/ckeditor5-build-classic` | NOASSERTION | yes |

Fork counts: 1682 files at the last upstream commit (`34fa767`, 2023-07-25);
1786 at `HEAD`. The fork has added 114 files, modified 132, deleted 10 —
44,697 insertions against 15,883 deletions.

---

## 2 · Inventory

Component → origin → licence → what it obliges us to do. Counts are file counts
in the working tree unless stated.

### 2.1 UNetLab / EVE-NG lineage — BSD-3-Clause

| Group | Count | Notice carried | Copyright holders named |
|---|---|---|---|
| `templates/*.yml` | 111 | Full BSD-3 text | Andrea Dainese 2016; Alain Degreffe 2018 (106) or 2019 (5) |
| `includes/*.php` | 16 | `@license BSD-3-Clause https://github.com/dainok/unetlab/blob/master/LICENSE` | Andrea Dainese 2014-2016 |
| `themes/default/js/*.js` | 3 | same tag | Andrea Dainese 2014-2016 |
| **Total** | **130** | | |

The 16 in `includes/` are `api_authentication.php`, `api_configs.php`,
`api_folders.php`, `api_labs.php`, `api_networks.php`, `api_nodes.php`,
`api_pictures.php`, `api_status.php`, `api_textobjects.php`, `api_topology.php`,
`api_uusers.php`, `cli.php`, `messages_en.php`, `__network.php`, `__picture.php`
and `__textobject.php`. The three in `themes/` are `actions.js`,
`messages_en.js` and `validate.js`.

The licence they point at was fetched and read: `dainok/unetlab`'s `LICENSE` is
the 3-clause BSD, copyright Andrea Dainese. The template headers carry the full
text inline, with the non-endorsement clause naming **both** "UNetLab Ltd" and
"EVE-NG Ltd" — so the YAML template format and its 111 files are EVE-NG-era
work, not `dainok/unetlab`-era work (upstream `dainok/unetlab` shipped `.php`
templates, not `.yml`).

**What BSD-3 obliges.** Three things, and only three: retain the notice and
disclaimer in source redistributions; reproduce them in the documentation or
other materials accompanying a binary redistribution; and do not use the names
of the copyright holders or contributors to endorse or promote the fork without
permission. There is no copyleft, no source-availability requirement, and no
restriction on relicensing our own additions.

**Are we meeting it?** For these 130 files, clause 1 is met — the notices are
intact and this document does not propose touching them. Clause 2 is **not yet
met** as of this document: the installer produces a running appliance and no
file in it, or in this repository, reproduced the BSD-3 notice as accompanying
documentation. `THIRD-PARTY.md`, added alongside this document, is the fix —
and it ships already, because the installer rsyncs the repository root to
`/opt/unetlab/html` and `deploy_excludes()` does not name it. Clause 3 is met
and should be watched: the fork must not describe itself as endorsed by,
affiliated with, or a product of UNetLab or EVE-NG. `README.md` currently makes
no such claim.

### 2.2 The removed notices — provable, and the sharpest BSD-3 problem

Nine files in this tree are recognisably the same works as BSD-3-licensed files
in `dainok/unetlab`, with the attribution gone. Eight were confirmed by fetching
the upstream file and reading its header; the ninth had its header **replaced**
rather than deleted.

| This repository | Upstream file | Upstream header | Here |
|---|---|---|---|
| `api.php` | `html/api.php` | Dainese, BSD-3 | no header |
| `includes/functions.php` | `html/includes/functions.php` | Dainese, BSD-3 | no header |
| `includes/init.php` | `html/includes/init.php` | Dainese, BSD-3 | no header |
| `includes/__lab.php` | `html/includes/__lab.php` | Dainese, BSD-3 | no header |
| `includes/__node.php` | `html/includes/__node.php` | Dainese, BSD-3 | no header |
| `themes/default/js/functions.js` | `html/themes/default/js/functions.js` | Dainese, BSD-3 | no header |
| `themes/default/js/javascript.js` | `html/themes/default/js/javascript.js` | Dainese, BSD-3 | no header |
| `platform/wrappers/unl_wrapper` | `wrappers/unl_wrapper.php` | Dainese, BSD-3 | no header |
| `devices/interfc.php` | `html/includes/__interfc.php` | Dainese, BSD-3 | **`@copyright pnetlab.com`** |

The internal evidence agrees with the external: sixteen sibling files in
`includes/` *do* still carry the header — including three of the five
`__`-prefixed class files — and three of the four that do not are on the
roadmap's own list of the most heavily modified core files (`functions.php`,
`__node.php`, `__lab.php`). The pattern is that the notice went where the
editing went.

`devices/interfc.php` is the worst of them, and worth stating plainly: upstream
`__interfc.php` is Andrea Dainese's BSD-3 file; here it is renamed, the class is
`Interfc`, and the header reads `@author LIN / @copyright pnetlab.com`. That is
an attribution replaced by a different one.

**What this obliges.** These files were modified substantially, and a modified
BSD-3 work is still a BSD-3 work — the licence permits modification and says
nothing about how much. What it does not permit is dropping the notice.
*Appearance, not settled law:* PNETLab appears to have breached clause 1 when it
published these, and this fork inherits and continues that breach every time it
redistributes them. **This is fixable by us unilaterally and cheaply** — restore
the notice, add a "modified by" line. It does not need anyone's permission and
it should be done regardless of which licence the fork adopts.

*Uncertainty worth stating:* the comparison was made against `dainok/unetlab`
`master`, which is the 2016-era code. PNETLab's copies came via EVE-NG, and I did
not obtain EVE-NG's own copies of these files to compare. It is possible EVE-NG
altered them further. That does not change the conclusion — the notice names
Dainese either way — but it means the "modified by" line should say
"UNetLab/EVE-NG lineage" rather than pretending to a precise chain.

A further **20 `templates/*.yml`** carry no notice at all: `c2691`, `c3640`,
`c3660`, `c3725`, `c3745`, `c7200`, `docker`, `i86bi_linux`, `iol`, `isrv`,
`mysql-server`, `nokia16`, `sophosutm`, `sophosxg`, `vpcs`, and the five base
templates under `templates/device/`. Their 111 siblings are BSD-3. I could not
verify these against a published EVE-NG source, so their lineage is
**unestablished** — they are probably the same, but I am not going to assert it
from a pattern. Treat them as unknown and say so.

### 2.3 PNETLab's own additions — a copyright line and no licence

| Marking | Count | Where |
|---|---|---|
| `@copyright pnetlab.com` | 90 | 89 of the 90 files under `devices/` (all but `devices/functions.php`), plus `templates/csr8000v.yml` |
| `Copyright (c) PNETLab` | 1 | `templates/win10.yml` |
| **Marked, no grant** | **91** | |

The 89 under `devices/` are the entire device-driver layer: `device.php` and
`interfc.php`, 59 QEMU drivers, 8 dynamips device files (7 routers plus the
base), 17 dynamips adapters, and the docker, IOL and VPCS drivers. The one file
under `devices/` that is *not* marked is `devices/functions.php`, which carries
no notice of any kind. `devices/qemu/device_paloalto.php` is representative of
the other 89 — eight lines of header naming an author and a company, and no
statement of what anyone may do with the file.

**A copyright line with no licence is the hardest case in the tree, and the
reason is simple: it is a positive assertion of ownership with no grant
attached.** The default is exclusive rights reserved to the holder. An
unmarked file is in the same legal position but at least leaves room for
argument about intent; a marked one closes that door.

And the marked files are the smaller half of the problem. The unmarked body is
larger:

| Body | Files | Carrying any copyright notice |
|---|---|---|
| `store/app/` — the Laravel application | 150 | **0** |
| `store/resources/react/` — the React source | 295 | 1 (a vendored CKEditor adapter) |

So the honest statement is not "91 files are unlicensed". It is: **the entire
PNETLab-authored half of this codebase is unlicensed** — the device layer, the
Laravel application, the React frontend, the marketplace, the admin UI. The 91
marked files are simply the ones where the position is explicit.

**What this obliges.** Nothing we can discharge by adding a file, because the
obligation runs the other way: we have no grant. Publishing a fork on GitHub is
covered by GitHub's Terms of Service, which say that making a repository public
grants other users "a nonexclusive, worldwide license to use, display, perform
and reproduce (by forking) Your Content **through the Service**". That is a fork
right, and it is why this repository exists without incident. It is not a
redistribution right, a modification right, or a right to ship an appliance ISO.
*Appearance, not settled law:* a public GitHub fork of an unlicensed public
GitHub repository appears to be within the ToS grant; distributing that code by
any other channel appears not to be. Section 4 of this document is where that
gets turned into options.

### 2.4 Third-party components we redistribute

Full detail with paths and versions is in `THIRD-PARTY.md`. The summary, and
what each one costs us:

| Component | Version | Licence | Obligation | State |
|---|---|---|---|---|
| **CKEditor 5** (`ckeditor5-build-classic`, `ckeditor5-react`) | 16.0.0 / 2.1.0 | **GPL-2.0-or-later** | Copyleft over the combined work | **Compiled into committed bundles** — see below |
| **Apache Guacamole** `.war` + JDBC `.jar` | 1.5.5 (1.3.0 fallback) | Apache-2.0 | Preserve LICENSE/NOTICE; not committed, pinned by SHA-512 | Attribution exists, **one claim in it is wrong** |
| Guacamole JDBC schema | — | Apache-2.0 | Preserve notice; state modification | **Committed** as `install/sql/schema/guacdb.sql`, notice absent |
| **Slim** framework | 2.6.1 | MIT | Retain notice | 21 files + `includes/Slim/LICENSE`. Met. |
| Slim-Extras `DateTimeFileWriter` | — | MIT (inline) | Retain notice | Met. |
| **Parsedown** | — | MIT (Emanuil Rusev) | Retain notice | Header says "view the LICENSE file that was distributed with this source code" — **that file is not here**. |
| **Ace** editor | 1.2.6 | BSD-3 (Ajax.org) | Retain notice | 213 files under `themes/default/js/src`; only 2 carry the notice (`ace.js`, `cisco_ios_highlight_rules.js`) and only 5 carry any copyright line at all. Thin — cover it in `THIRD-PARTY.md`. |
| jQuery / jQuery UI / validate / cookie / hotkey / panzoom | 3.2.1, 3.3.1, 1.12.1, 1.14.0, … | MIT | Retain notice | Met. |
| Bootstrap | 3.3.5 and 4.1.3 | MIT | Retain notice | Met. |
| AngularJS + ui-router, ui-utils, ui-select, ocLazyLoad, block-ui | 1.5.6 | MIT | Retain notice | Met. Dead weight; see §6. |
| jsPlumb Community / jsBezier | 2.4 | MIT | Retain notice | Met. |
| Font Awesome | 4.5.0 | OFL-1.1 (fonts) + MIT (code) | Retain notice | Notice inline in CSS. |
| Ubuntu font family | — | Ubuntu Font Licence 1.0 | Ship the licence; do not relicense | `themes/default/fonts/LICENCE.txt` present. Met. |
| Glyphicons Halflings | — | via Bootstrap | Retain Bootstrap notice | Met. |
| PrimeReact / PrimeIcons + Open Sans | 3.3.2 | MIT (Open Sans: Apache-2.0) | Retain notice | npm metadata records no licence for primereact 3.3.2; upstream is MIT. |
| **`ARIALBD.TTF`** | — | **Proprietary (Monotype)** | Cannot be redistributed | **In the tree.** See §3. |
| **`idlepc`** (prebuilt ELF) | — | **Unknown; embeds LGPL and Apache code** | Unknown | **In the tree.** See §3. |
| SheetJS `xlsx`, `chart.js`, `jszip`, React 16, react-router, redux, laravel-mix… | see `package-lock.json` | Apache-2.0 / MIT / dual | Build-time; retain notice where bundled | 1273 lockfile entries. |
| Composer tree | see `store/composer.lock` | 61 MIT, 32 BSD-3, 1 Apache-2.0, 2 tri-licensed (`nette/utils`, `nette/schema`: BSD-3 **or** GPL-2 **or** GPL-3) | Retain notices | `store/vendor/` is not committed. Both nette packages can be taken under BSD-3. |

#### CKEditor 5 is the compatibility problem, and it is already in the repository

`package-lock.json` records `@ckeditor/ckeditor5-build-classic@16.0.0` and
`@ckeditor/ckeditor5-react@2.1.0`, both `"license": "GPL-2.0-or-later"`.
CKEditor's own `LICENSE.md` at tag `v16.0.0` was fetched and reads "Licensed
under the terms of GNU General Public License Version 2 or later". CKEditor
offer a commercial licence separately; the open-source terms are GPL only.

It is not a build-time-only dependency. Five React source files import it
(`TextEditor.js`, `HTMLEditor.js`, `HTMLView.js`, and the two product wizard
steps), and the editor's code is present in **committed** build output:
`store/public/react/js/lab.js` (1.9 MB) contains 23 `ckeditor5-` strings, 32
`ck-editor__editable` occurrences and CKEditor's own `CKEditorError`
constructor; the `vendors~.` chunk contains the same. Those files are tracked by
git.

*Appearance, not settled law:* a bundle that links GPL-2.0-or-later code into
one distributed artefact makes that artefact a combined work whose distribution
terms are GPL-2.0-or-later. Permissively-licensed code combines into it without
difficulty — MIT and BSD-3 are GPL-compatible — so the fork's *own* source can
sit under a permissive licence and still be compiled into a GPL bundle. What is
not tenable is offering the compiled bundle itself under MIT or BSD-3 terms,
which is what a bare `LICENSE` file at the repository root would appear to do
while `lab.js` is committed.

Three ways out, in ascending cost: **(a)** stop committing built bundles and let
the installer build them, which moves the combined work out of the repository
but not out of the shipped appliance; **(b)** replace CKEditor with a
permissively-licensed editor — the roadmap's Phase 06 already contemplates
shedding frontend weight, and the editor is used for lab text objects and
workbook HTML, not for anything structural; **(c)** keep CKEditor, note it
explicitly, and accept that the distributed frontend bundle carries GPL-2.0+
terms. (c) is coherent and is what many projects do; it just has to be a
decision rather than an accident.

#### The Guacamole attribution is good, and contains one wrong sentence

`install/vendor/guacamole/README.md` is a model of how to do this: it names the
licence, links the canonical text, states that the artefacts are redistributed
unmodified, and states that `install/lib/guacamole.sh` deploys them byte for
byte so the `META-INF/` and `WEB-INF/` notices arrive intact. The binaries are
not committed; `SHA512SUMS` pins them. Nothing there needs changing except one
claim:

> This repository builds nothing from Guacamole source and patches nothing. The
> integration is entirely through Guacamole's own database schema and REST API,
> which is why **no source-level derivative work exists here to attribute**.

`install/sql/schema/guacdb.sql` is 357 lines of Apache Guacamole's JDBC schema,
committed to this repository. Its own header says so: "It is the stock schema
published by the Apache Guacamole project for its JDBC authentication
extension, and is Apache-2.0 licensed there." It was obtained by `mysqldump`
from an appliance rather than copied from Guacamole's `.sql` files, which
changes the formatting but not the authorship of the table definitions.

Apache-2.0 §4 asks a redistributor of a work or derivative to carry a copy of
the licence, to keep existing notices, and to mark modified files as changed.
The schema file does none of those. It is a small fix and is made in this
commit: the README sentence is corrected, and the schema's provenance header is
extended to carry the licence reference. Whether a `mysqldump` of someone's
schema is a derivative work at all is exactly the sort of question that has no
crisp answer; the cheap move is to attribute it and stop needing one.

### 2.5 Fork-authored code, 2026

114 added files: 31 under `platform/`, 26 under `install/`, 24 under `tests/`,
15 under `tools/`, 7 under `store/`, 5 under `docs/`, plus `SECURITY.md`,
`.gitignore`, `.user.ini` and the CI workflow. None carries a copyright or
licence header.

The most significant is `platform/wrappers/src` — roughly 2,500 lines of C
reimplementing `qemu_wrapper_telnet`, `docker_wrapper` and `iol_wrapper`.
`platform/wrappers/src/README.md` records that this was written clean-room from
a behavioural specification, with no upstream wrapper source or binary read by
the implementer. **That decision has more licensing value than the README
credits it with, and the README's stated reasoning is partly wrong.** Both it
and `docs/HANDOVER.md` say that permissively-licensed source for the originals
exists publicly, citing `dainok/unetlab` *and* `pnetlab/pnetlab_wrapper`. The
first is true — `dainok/unetlab` is BSD-3 and carries the four wrapper `.c`
files. The second is not: `pnetlab/pnetlab_wrapper` has **no licence file and
`"license": null`**, so vendoring from it would have imported exactly the
problem described in §2.3. Corrected in both files in this commit.

This is also the one part of the tree the project unambiguously owns and can
licence however it likes.

---

## 3 · Two small items that are not arguable

### `store/app/Helpers/Captcha/ARIALBD.TTF`

750 KB. `file` reports the embedded name string: *"© 2014 The Monotype
Corporation. All Rights Reserved. Arial is a trademark of The Monotype
Corporation"*. It is used by one function, `Captcha::createCaptcha()`, as the
`imagettftext()` font.

Arial is proprietary. There is no licence file, no purchase record, and no plausible
grant permitting redistribution in a public source repository. It arrived with
the upstream import and nobody chose it.

**Fix:** swap it for a metrically similar, freely-licensed face — DejaVu Sans
Bold or Liberation Sans Bold, both already present on any Ubuntu host — and
delete the file. It is a one-line constant change plus a path. This should not
survive to a public repository, and unlike everything else in this document it
requires no decision from anyone.

*Note:* deleting the file from `HEAD` does not remove it from git history. If
the repository's history is published as-is, the font is still there in
`07ef833`. Whether that matters is a judgement call about risk appetite; the
options are to leave it, or to rewrite history before the first public push,
which is far cheaper now than later.

### `store/app/Console/Commands/idlepc`

9.4 MB. A stripped x86-64 ELF, PyInstaller-packed (`_MEIPASS`, `PYZ-00.pyz`),
built against Python 3.5, embedding `paramiko`, `bcrypt`, `cryptography 3.1.1`,
PyNaCl (`_sodium.abi3.so`) and OpenSSL (`_openssl.abi3.so`). No source is in the
repository. It is invoked from `store/app/Http/Controllers/Admin/DefaultController.php:230`,
**under `sudo`**, to compute a dynamips idle-PC value.

Two problems in one file:

- **Licensing.** `paramiko` is LGPL-2.1. LGPL §6 attaches conditions to
  distributing a work that statically incorporates an LGPL library — broadly,
  the recipient must be able to relink against a modified version, which
  normally means shipping the object files or the source. A frozen PyInstaller
  binary with no accompanying source does not obviously satisfy that. The other
  embedded libraries are Apache-2.0 or BSD and want notices we do not carry.
- **Provenance.** Nobody in this project built it, nobody can rebuild it, and it
  runs as root. That is a supply-chain question as much as a licence one, and it
  is the reason to act rather than to argue about the LGPL.

**Fix:** either reimplement idle-PC calculation (it is a well-understood
operation over dynamips' own console interface, and dynamips is on the box) or
drop the feature. Either way the blob goes. The same history note as the font
applies.

---

## 4 · `CiscoIOUKeygen.py`

The brief asked whether the Cisco IOU/IOL licence-key generator that the
upstream appliance ships at `/opt/unetlab/addons/iol/bin/` is in this
repository. **It is not.**

```
$ grep -rn "CiscoIOUKeygen" .            # 7 hits, all references
$ find . -iname '*keygen*'               # nothing
$ git ls-files | grep -iE '\.(bin|py)$'  # no keygen
```

The seven hits are three call-site references and four test assertions:

- `store/app/Http/Controllers/Admin/SystemController.php:154` —
  `refreshIolLicense()` looks for `/opt/unetlab/addons/iol/bin/CiscoIOUKeygen.py`,
  returns `false` if it is absent or a symlink, runs it via `proc_open()` with an
  argv array, filters the output for `=` lines that are not `hostname`, and
  writes `iourc` beside it. It is called from `fixPermission()`.
- `devices/iol/device_iol.php:219,225` — refuses to start an IOL node unless
  `iourc` exists, and symlinks it into the node's running directory.
- `tests/Security/FixPermsTest.php:250,264-266` — asserts the call site still
  exists and records the shell pipeline it replaced.
- `tools/integration/node-types.sh:294` — skips the IOL test when no `iourc` and
  no `.bin` image are present.

The installer creates `/opt/unetlab/addons/iol/bin` empty
(`install/lib/platform.sh:159`) and never populates it.

### What this means

The distinction is real and it is in our favour. **Shipping a keygen is
distributing a tool whose purpose is to defeat a licence check on Cisco
software.** *Appearance, not settled law, and this is the item where the gap
between "appears" and "is" matters most:* that is the shape of conduct that
anti-circumvention provisions — DMCA §1201 in the US, the EU Copyright
Directive's Article 6 as implemented — are written about, quite apart from any
copyright in the keygen itself. It is also the kind of thing that gets a
repository taken down by a hosting provider on a complaint, without anyone
reaching the merits. For a project seeking to be a legitimate public fork,
carrying one would be a poor trade for a feature that only works with images the
project also does not carry.

**Calling a keygen that an operator installed themselves is a materially weaker
position than shipping one**, and it is the same position as reading `iourc`, or
as running any other operator-supplied binary from the addons tree. The current
code is on the right side of that line.

What is not right is that it is on the right side *by inheritance*. The call
site is upstream's, kept because it works; nobody chose it as a boundary. Three
things would make it deliberate, and none is expensive:

1. **Rename the concept.** `refreshIolLicense()` reads as though the fork
   participates in licensing IOL. It regenerates a local `iourc` from a
   file the operator supplied. A comment saying so, at the top of the method,
   costs nothing and is the first thing a reviewer reads.
2. **State the boundary once, in `README.md` and `docs/LICENSING.md`:** the
   project ships no Cisco software, no Cisco licence keys, and no tool for
   generating them; IOL support requires the operator to supply their own images
   and their own `iourc`, and the project takes no position on how.
3. **Guard it in CI**, next to the vendor-image guard proposed in §5. A grep for
   `keygen`, `iourc`-as-content, `*.bin` under `addons`, and known IOL image
   names, failing the build. Cheap, and it means the boundary cannot be crossed
   by an enthusiastic contributor.

There is one further consideration the owner should weigh, and it is a judgement
call rather than a fact: `refreshIolLicense()` exists **only** to make a keygen
useful. If the fork wants no association with circumvention at all, deleting the
method and requiring the operator to place `iourc` by hand costs one manual step
for the small number of users who have IOL images, and removes the last code
path in the repository that reads as licence circumvention. My recommendation
below keeps it — but it is close, and a legal opinion may well push it the other
way.

---

## 5 · Vendor images

**Confirmed: the repository contains none.**

```
$ git ls-files | grep -icE '\.(qcow2|vmdk|bin|iso|ova|img|vhd)$'
0
```

The largest tracked files are `store/app/Console/Commands/idlepc` (9.4 MB), an
Ace editor worker (3.4 MB) and the React `lab.js` bundle (1.9 MB). No disk
image, no IOL binary, no appliance ISO. `docs/HANDOVER.md` records that even the
CirrOS image the QEMU integration test needs lives on the reference VM and not in
the tree.

**Where the boundary is enforced: nowhere, mechanically.** `.gitignore` has no
rule for image formats. `.github/workflows/ci.yml` runs PHP lint, PHP tests, the
C wrapper build and the frontend build; nothing checks what is committed. The
boundary currently holds because the people working on the project have been
careful, which is a fine reason for it to have held so far and a poor reason to
expect it to keep holding.

Two other things touch this boundary and should be said out loud:

- **`docs/PACKAGES.md` defines an `install_image` verb** that places a QEMU or
  IOL image under `addons/<emulator>/<folder>/`, and an `install_icon` verb.
  That is a mechanism by which a third party could distribute vendor images
  through a signed package. The fork does not host such packages and the
  signing trust model is the fork's own, but the format's capability should be
  documented as a capability, with the project's position stated: the project
  neither hosts nor endorses packages containing licensed vendor software.
- **`images/icons/` holds 164 PNGs plus a 556 KB `icons.rar`**, and the file
  names are vendor product names — `ASA`, `ASR`, `Apic`, `CSRv1000`,
  `Cisco ACS`, `Cisco WAAS`, `Checkpoint`, `Aruba_ctrl`, `AristaSW`. These are
  recognisably the Cisco network-topology icon set as redistributed by
  UNetLab/EVE-NG, with additions. Cisco publish that set for use in
  documentation under their own terms; those terms are not in this tree and I did
  not attempt to establish them. This is a **trademark and brand-usage** question
  more than a copyright one, it is the same position every comparable project is
  in, and it is housekeeping rather than a blocker — but it should be on the
  list, not off it.

---

## 6 · The problems, ranked

### Blocks publishing

1. **The unlicensed PNETLab body.** The device layer, the Laravel application
   and the React frontend have no grant. This is not a documentation gap; it is
   the absence of permission for the thing the project intends to do. Every
   other item on this list can be fixed by us. This one cannot.
2. **`ARIALBD.TTF`.** Proprietary Monotype font, redistributed. Unarguable,
   trivially fixable, and there is no version of "publish anyway" that is
   defensible.
3. **CKEditor 5 GPL-2.0+ inside committed bundles.** Not a licence *violation*
   today — nothing here contradicts the GPL. It becomes one the moment a
   permissive `LICENSE` file is added at the root while `store/public/react/js/lab.js`
   is committed, because that file would then be offered under terms its
   contents do not permit. It therefore blocks the licence decision, not the
   repository.
4. **The nine stripped BSD-3 notices.** A continuing failure of the one
   condition BSD-3 imposes, on files we knowingly redistribute. Fixable by us,
   this week, without asking anyone.

### Should be fixed before publishing, but does not block it

5. **`idlepc`.** Opaque root-executed binary embedding LGPL code with no source.
   The security argument for removing it is stronger than the licence one, and
   they point the same way.
6. **No accompanying attribution for the BSD-3 material.** Clause 2 of BSD-3
   asks for the notice to travel with a binary distribution, and until now
   nothing in this tree or on an installed appliance carried it.
   `THIRD-PARTY.md` addresses it, and the installer already ships it — the root
   is rsynced to `/opt/unetlab/html` and `deploy_excludes()` does not name it.
   What is missing is a test keeping it that way.
7. **The `"license": "MIT"` line in `store/composer.json`.** A false statement
   about the project, sitting in a machine-readable field that dependency tools
   and licence scanners read. Remove it or correct it.
8. **Parsedown's missing `LICENSE`.** Its header defers to a file that is not
   in the tree. One file.
9. **The Guacamole README's "no derivative work" sentence** and the
   unattributed `guacdb.sql`. Both corrected in this commit.
10. **The `pnetlab/pnetlab_wrapper` is BSD-3 claim** in `docs/HANDOVER.md` and
    `platform/wrappers/src/README.md`. Corrected in this commit.

### Housekeeping

11. **No mechanical guard** on vendor images, keygens, or licensed binaries in
    CI. Add one.
12. **The Cisco icon set.** Establish terms, or replace, or state the position.
13. **Unattributed dead vendored code.** AngularJS 1.5.6 and its plugin tree
    (1.8 MB) are dead weight the roadmap already wants gone; deleting them
    deletes their obligations too. Same for the vendored upload demo at
    `.../angularJS/plugins/angular-file-upload/upload.php`, whose first
    statement is `exit;`.
14. **Fork-authored files carry no headers.** Once a licence is chosen, add
    `SPDX-License-Identifier` lines to the 114 files the fork wrote. Cheap, and
    it makes the boundary between "ours" and "inherited" machine-readable.

### Adjacent, and not a licensing item

`store/.env` is tracked, with an `APP_KEY` in it. That is a security matter, not
a licensing one, but it is in the same pre-public gate and it should not be lost
because it turned up in a licensing audit.

---

## 7 · Options

### 7.1 For the fork as a whole

The constraint is that a project cannot grant rights it does not hold. Whatever
is chosen applies cleanly to the fork's own 114 files and to nothing else until
§7.2 is resolved.

**Option A — BSD-3-Clause.**
Matches the largest permissively-licensed body in the tree, so there is exactly
one set of conditions to satisfy rather than two. Requires: keep every BSD-3
notice; restore the nine stripped ones; ship `THIRD-PARTY.md` with the appliance
to satisfy clause 2; never claim UNetLab or EVE-NG endorsement. Does not resolve
§2.3.

**Option B — MIT.**
Marginally simpler and more familiar; compatible with everything inherited.
Slightly worse fit: it adds a second permissive licence to a tree whose
inheritance is BSD-3, for no gain. Same obligations otherwise. Does not resolve
§2.3.

**Option C — Apache-2.0.**
Adds an express patent grant, which is worth something in a project that touches
virtualisation and networking, and a `NOTICE` mechanism that is a natural home
for the attribution work. Compatible with BSD-3 and MIT inbound. Costs: a
longer licence, per-file modification marking under §4(b), and one-way GPL-2
incompatibility — which matters if CKEditor stays and the bundles stay
committed. Does not resolve §2.3.

**Option D — GPL-3.0 or AGPL-3.0.**
Would suit a project that wants derivative appliances kept open. It is the only
family that changes the CKEditor calculus in our favour (GPL-2-or-later can be
taken as GPL-3). It is also the least compatible with the fork's likely
downstream users, who deploy appliances into corporate labs. And it still does
not resolve §2.3 — you cannot copyleft code you have no licence to.

**Option E — decide nothing; publish with `LICENSE` absent and a
`docs/LICENSING.md` that explains why.**
Honest, and it is what the repository already does implicitly. Its cost is that
nobody can contribute or deploy with confidence, and "no licence" reads to most
readers as carelessness rather than as a considered position — which is exactly
why this document exists.

Every option is downstream of §7.2. There is no licence choice that makes the
unlicensed body licensed.

### 7.2 For the unlicensed PNETLab code

**Path 1 — Ask.** `pnetlab/pnetlab_main` was published deliberately, publicly,
with a README addressed to developers explaining how to extend it. That is the
behaviour of someone who intended the code to be used and did not think about
the paperwork. A short, specific request — "would you add an MIT or BSD-3
`LICENSE` file to `pnetlab_main`, or grant this fork those terms in writing?" —
costs one email and, if it lands, resolves the entire problem at a stroke.
Contact: `pnetlabs@gmail.com`, the address on all five upstream commits. Repo
last pushed 2023-07-24; the roadmap records that `secure.pnet-lab.com` no longer
resolves. So: **cheap, high-value, and probably unanswered.** Send it anyway,
and keep the sent copy — a documented good-faith attempt is worth having.

While asking, ask for two more things: the licence status of
`pnetlab/pnetlab_wrapper` (relevant if the clean-room decision is ever revisited)
and whether upstream will restore the UNetLab attribution headers themselves.

**Path 2 — Reimplement.** The project has already proved it can do this: the
console wrappers are ~2,500 lines of clean-room C, written from a specification
by a party who had not read the originals, and they work. That is the model.
But the scale is different by an order of magnitude — the device layer is 89
files, the Laravel application 150, the React frontend 295. Reimplementing the
whole PNETLab half is a rewrite of the product, not a licensing fix.

Where it *is* proportionate: the device layer. 89 files that translate template
fields into command lines, over an API this project already documents. Much of
it is mechanical. If a subset must be owned outright, that is the subset with
the best ratio of effort to coverage — and the roadmap's Phase 02.5 rebase
decision is the moment to do it, because rebasing onto 5.3.13 means touching
those files anyway.

**Path 3 — Remove.** Not viable for the device layer or the application; they
are the product. Viable, and worth doing on its own merits, for the marketplace
and licence-keepalive code that the offline-first direction already condemns
(`License::keepalive()`, `Relicense.php`, `Query::boxCenter()`). Deleting code
that phones a third party's licensing server is a licensing improvement as well
as an architectural one: it is hard to argue a fork is independent while it
still relicenses against `pnetlab.com`.

**Path 4 — Accept and document.** Publish, state the position plainly, and
carry the risk. The honest form of this is not silence; it is a `LICENSING.md`
that says: *the fork's own contributions are licensed under X; substantial
inherited portions carry no licence grant from their author; we have asked and
not heard back; here is exactly which files those are; use accordingly.* That is
a real position that a downstream user can evaluate. It is also, in practice,
the position of a great many forks of abandoned source-available projects.

Its risk profile is asymmetric in a specific way: the likely bad outcome is not
litigation but a takedown notice, which costs the project its GitHub presence
and its credibility at the same time. The likelihood is low — an upstream that
has not pushed since 2023 and whose infrastructure is decaying is not
positioning for enforcement — but "low" is doing load-bearing work in that
sentence and the owner should decide whether they are comfortable with it, not
be told they should be.

---

## 8 · Recommendation

**Publish, under BSD-3-Clause for the fork's own work, with the inherited
position documented rather than papered over — after clearing the four items
that are ours to clear.**

The reasoning, so it can be disagreed with on the reasoning: the four things
that are unambiguously wrong today are all things this project can fix
unilaterally and inexpensively — the Monotype font, the `idlepc` blob, the nine
stripped BSD-3 notices, and the false `"license": "MIT"` line. None needs a
decision from anyone else, and leaving any of them in place while publishing
would be a choice rather than an inheritance. The one problem that is genuinely
outside our control — that PNETLab published its own code without a licence —
does not get better by waiting, because the upstream has been silent for three
years and its infrastructure is visibly decaying; deferring is not caution, it
is just deferral with the same risk. BSD-3 rather than MIT because it is the
licence the largest inherited body already uses, and matching it means one set
of conditions to satisfy instead of two; not Apache-2.0, despite the patent
grant being genuinely attractive, because its GPL-2 incompatibility collides
with CKEditor and this is not the moment to take on a frontend migration to
resolve a licence choice; not a copyleft licence, because the fork's users
deploy appliances into corporate labs and the roadmap's whole direction is
toward being deployable there. On CKEditor specifically, I recommend **stopping
the commit of built bundles** rather than replacing the editor — the build is
already green in CI on Node 22, the bundles are 5.4 MB of generated output that
has no business in git anyway, and it removes the contradiction between a
permissive root `LICENSE` and a GPL-carrying tracked artefact without
committing to a frontend rewrite. On the keygen, I recommend **keeping the call
site and making the boundary explicit** rather than deleting it: calling an
operator-supplied file is a materially different act from shipping one, the code
already degrades correctly when the file is absent, and deleting it would remove
a working feature for a class of user who has legitimately licensed images —
but I hold this one loosely, and it is the item most likely to change under a
real legal opinion.

Concretely, that means: fix the four; keep `THIRD-PARTY.md` shipping with the
appliance and test that it does; send the upstream request and keep the copy;
add the CI guard;
then add `LICENSE` (BSD-3) plus a prominent `README.md` section that states the
inherited position in three sentences and points here.

**What would change my mind.** If the owner intends to sell or offer commercial
support around this, the §7.2 exposure stops being a background risk and becomes
a diligence item, and Path 2 — reimplementing the device layer, at minimum —
moves from "proportionate for a subset" to "necessary". If upstream answers,
everything above simplifies and BSD-3 becomes straightforwardly correct rather
than the best available. If a lawyer takes a dim view of §4, delete
`refreshIolLicense()` and lose one manual step for IOL users.

---

## 9 · Where a real legal opinion is warranted

Three, in order of how much rides on them.

1. **§2.3 — the unlicensed PNETLab body.** Specifically: does GitHub's ToS
   §D.5 fork grant permit this repository to exist and be modified publicly, and
   does it permit anything beyond that — an installer that deploys the code onto
   a user's machine, a release tarball, an ISO? This is the question that
   decides whether the project can distribute at all, and it is the one where an
   engineering reading is least reliable, because the answer turns on what
   "through the Service" means and on jurisdiction.

2. **§4 — the keygen call site.** Does calling an operator-supplied
   `CiscoIOUKeygen.py`, and writing the `iourc` it produces, amount to
   trafficking in a circumvention device or to contributory infringement, in the
   jurisdictions the project cares about? My assessment is that it does not and
   that the difference from shipping the keygen is real, but this is
   anti-circumvention law, the penalties are the most severe in this document,
   and I would not publish on my own reading of it.

3. **§2.4 — CKEditor and the committed bundles.** Whether a webpack bundle
   containing GPL-2.0-or-later code is a "work based on the Program" for GPL-2
   purposes, and therefore whether a permissive root `LICENSE` over a repository
   containing `lab.js` is a misstatement. My recommendation routes around this
   by not committing the bundles, which is worth doing regardless — but if the
   owner prefers to keep committing them, the question needs answering rather
   than avoiding.

A fourth, lower-stakes and worth raising in the same conversation since it costs
nothing extra: whether the nine stripped BSD-3 notices create any residual
liability for this fork given that the stripping was done upstream, or whether
restoring them prospectively is sufficient. My assumption throughout has been
that restoring them is both necessary and sufficient.

---

## 10 · Checklist before the repository is made public

Ours to do, no decision required:

- [ ] Delete `store/app/Helpers/Captcha/ARIALBD.TTF`; point
      `Captcha::$CONFIG['CAPTCHA_FONT']` at DejaVu Sans Bold or Liberation Sans
      Bold from the system font path.
- [ ] Remove `store/app/Console/Commands/idlepc`; reimplement idle-PC
      calculation against dynamips' console, or drop the feature and say so.
- [ ] Restore the BSD-3 header on the nine files in §2.2, each with an added
      "Modified in this fork" line. Do not silently re-add a `@version` that is
      no longer accurate.
- [ ] Remove or correct `"license": "MIT"` in `store/composer.json`.
- [ ] Add `includes/Parsedown.LICENSE` (MIT, Emanuil Rusev) so Parsedown's
      header stops pointing at a file that is not there.
- [x] Correct the "no source-level derivative work" sentence in
      `install/vendor/guacamole/README.md`, and attribute
      `install/sql/schema/guacdb.sql` to Apache-2.0.
- [x] Correct the claim that `pnetlab/pnetlab_wrapper` is BSD-3-Clause, in
      `docs/HANDOVER.md` and `platform/wrappers/src/README.md`.
- [x] Write `THIRD-PARTY.md`.
- [x] Ship `THIRD-PARTY.md` with the appliance — already true, because
      `deploy_excludes()` in `install/lib/deploy.sh` does not list it and the
      root is rsynced to `/opt/unetlab/html`. **Add a test asserting it stays
      out of that exclude list**, so a later tidy-up cannot silently drop the
      one file that satisfies BSD-3 clause 2 for a binary distribution.
- [ ] Add a CI job that fails on: any tracked `*.qcow2 *.vmdk *.bin *.iso *.ova
      *.img *.vhd`; any path matching `*keygen*`; any file whose content matches
      the `iourc` `[license]` format; any tracked `*.ttf`/`*.otf` not covered by
      a licence file in the same tree.
- [ ] Delete the dead AngularJS tree and
      `angular-file-upload/upload.php`, removing their obligations with them.

Needs the owner's decision:

- [ ] Choose a licence (§7.1). Recommendation: BSD-3-Clause.
- [ ] Choose a path for §2.3. Recommendation: send the upstream request
      (Path 1), and publish under Path 4 with the position documented.
- [ ] Decide CKEditor (§2.4). Recommendation: stop committing built bundles;
      keep the editor for now.
- [ ] Decide the keygen call site (§4). Recommendation: keep it, name the
      boundary in `README.md`, guard it in CI.
- [ ] Decide whether to rewrite history before the first public push, to remove
      the font and the blob from `07ef833` onward.
- [ ] Establish or replace the Cisco icon set (§5).

After the decision:

- [ ] Add the root `LICENSE`.
- [ ] Add `SPDX-License-Identifier` headers to the 114 fork-authored files.
- [ ] Add a "Licensing" section to `README.md`: what the fork licenses, what it
      inherited, what it does not ship (no vendor images, no Cisco software, no
      licence keys, no keygen), and a link here.
- [ ] Adjacent, and not ours in this document, but in the same gate:
      `store/.env` is tracked and contains an `APP_KEY`.

---

## Appendix · Reproducing the counts

Run from the repository root.

```bash
# This document and THIRD-PARTY.md quote the very strings being counted, so
# every grep below excludes them. X=the exclusions.
X=(--exclude-dir=.git --exclude-dir=node_modules --exclude-dir=docs --exclude=THIRD-PARTY.md)

# Files carrying an intact UNetLab/EVE-NG BSD-3 notice                  -> 130
grep -rl "Redistribution and use in source and binary" templates | wc -l          # 111
grep -rl "@license BSD-3-Clause https://github.com/dainok/unetlab" . "${X[@]}" | wc -l  # 19

# PNETLab-marked files with no licence grant                            ->  91
grep -rl "@copyright pnetlab.com" . "${X[@]}" | wc -l                             #  90
grep -rl "Copyright (c) PNETLab" templates | wc -l                                #   1

# The unmarked bodies
find store/app -type f | wc -l;             grep -rli copyright store/app | wc -l # 150 / 0
find store/resources/react -type f | wc -l; grep -rli copyright store/resources/react | wc -l  # 295 / 1

# No vendor images
git ls-files | grep -icE '\.(qcow2|vmdk|bin|iso|ova|img|vhd)$'                    #   0

# The keygen: references only, no file
grep -rn "CiscoIOUKeygen" . "${X[@]}"                                             # 7 references
find . -iname '*keygen*' -not -path './.git/*'                                    # nothing

# Dependency licence census
python3 -c "import json,collections; d=json.load(open('store/composer.lock')); \
c=collections.Counter(', '.join(p.get('license',[])) or 'NONE' \
for k in ('packages','packages-dev') for p in d.get(k,[])); print(c)"
```

Upstream headers were read from
`https://raw.githubusercontent.com/dainok/unetlab/master/<path>` and licence
metadata from `https://api.github.com/repos/<owner>/<repo>`. The BSD-3 text was
read from `dainok/unetlab`'s own `LICENSE`; CKEditor's from
`ckeditor/ckeditor5` at tag `v16.0.0`; GitHub's ToS from
`docs.github.com/en/site-policy/github-terms/github-terms-of-service`.
