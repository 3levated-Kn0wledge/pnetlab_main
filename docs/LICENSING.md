# The licence position

**Status: decided and adopted, 2026-09-01. The fork's own work is licensed
BSD-3-Clause; `LICENSE` is at the repository root.**

**The repository is not yet publishable.** Those two sentences are not in
tension, and the distinction between them is the most important thing in this
document — see "Adopted is not published", below.

The roadmap said the licence position "gates going public as hard as any
security item and deserves a decision, not a deferral". The decision is taken.
What remains is a set of *tracked gaps*: named components the adopted licence
does not and cannot cover, each with an owner-visible cost, listed in section 6
and gating publication in section 10.

**This is an engineering assessment, not legal advice.** Neither the author nor
the reader is a lawyer. Everything below is either a fact measured from the tree
(counts, paths, headers, upstream comparisons — all reproducible with the
commands in the appendix) or an inference about what a licence *appears* to
oblige. The inferences are marked. Three points where a real opinion is
warranted before publishing are in section 9, so they are not lost in the
detail.

---

## Adopted is not published

Adopting BSD-3-Clause in the repository and publishing the repository under it
are two different acts, and only the first has happened.

Adding `LICENSE` sets a standard. It says what the project intends, it makes
every future contribution inbound-equals-outbound under GitHub's terms, and it
gives every subsequent decision something to be measured against. It costs
nothing to be wrong about today, because the repository is private.

Publishing is a **statement to a recipient about what they may redistribute**.
While a component remains in the tree that the adopted licence cannot cover, a
public repository carrying a bare BSD-3 `LICENSE` would be telling every
recipient something untrue. That is materially worse than the position the
project is in today. Declaring nothing, as the repository did until now, leaves
a reader to work it out and be cautious. Declaring BSD-3 over a tree containing
GPL-linked bundles and an unlicensed upstream body invites a reader to rely on
a grant nobody has the standing to make, and it is the fork — not upstream —
that would have made it.

So the shape of the position is:

| | |
|---|---|
| **Now** | `LICENSE` adopted. Its scope section names exactly what it does not cover. `THIRD-PARTY.md` carries the attribution. Non-compliant components are tracked gaps, not blockers on the decision. |
| **Before publishing** | Every tracked gap in section 6 is closed or consciously accepted by the owner, and the "no known-incompatible component remains" gate in section 10 is signed off. |

This is why `LICENSE` is not a bare licence text. Its second half states, in the
file a recipient reads first, that the grant covers the fork's own work; that
130 files are inherited under BSD-3 from UNetLab/EVE-NG; that a substantial body
inherited from PNETLab carries **no grant at all**; and that the committed
frontend bundles carry GPL-2.0-or-later terms. A recipient who reads only
`LICENSE` still gets the truth.

---

## The position, in short

The fork now declares BSD-3-Clause over its own work. It was forked from
`pnetlab/pnetlab_main`, which declares nothing — `"license": null` in the GitHub
API, no `LICENSE` file — so PNETLab's own code is **all rights reserved by
default**: readable and forkable on GitHub, with no grant to redistribute it
anywhere else. Under that sits a substantial UNetLab/EVE-NG inheritance where
**130 files carry an intact BSD-3-Clause notice**, a real and usable grant, and
where **nine further files were provably the same lineage with the notice
removed or replaced** — the one condition BSD-3 imposes, now restored by this
fork. Three items remain that BSD-3 cannot reach: the unlicensed PNETLab body
(the largest, and the only one that cannot be fixed by us — section 8 sketches
how to code it out), CKEditor 5's GPL-2.0-or-later code inside committed build
output, and a 9.4 MB unlicensed binary that turns out to install a passwordless
root SSH key. Two items are now closed: the proprietary Monotype **Arial Bold**
has been replaced with fonts that can be redistributed, and the nine stripped
notices are back. The Cisco IOL keygen **is not in this repository** and never
was — only a call site that would run an operator-supplied copy.

---

## 1 · What the repository declares

Measured, not remembered. The "before" column is what this assessment found;
the "now" column is what this commit leaves behind.

| Question | Before | Now |
|---|---|---|
| Root `LICENSE` | **None.** `find` returned no root licence file of any name. | **BSD-3-Clause**, with a scope section naming what it does not cover. |
| Licence files elsewhere | Two, both third-party: `includes/Slim/LICENSE` (MIT, Josh Lockhart 2012), `themes/default/fonts/LICENCE.txt` (Ubuntu Font Licence 1.0). | Unchanged, plus `THIRD-PARTY.md` at the root. |
| `store/composer.json` | `"license": "MIT"` — the Laravel skeleton default. | `"license": "BSD-3-Clause"`. |
| `package.json` | **Absent.** Root `package.json` declares `"private": true` and no `license`. | Unchanged. Deliberately: the root package is a build harness, not a distributed package, and a `license` field there would describe the built bundles — which are not BSD-3. See section 2.4. |
| `README.md` | No licence statement, no copyright line, no attribution to UNetLab or EVE-NG. | Unchanged here; a "Licensing" section is on the section 10 checklist. |
| `SECURITY.md` | Disclosure policy only. | Unchanged. |

### What `"license": "MIT"` was, and why it had to go

It was the stock field from the Laravel skeleton, and it survived the Laravel
5.5 → 10 rebuild. Read literally it asserted that the package named
`pnetlab/pnetlab` — the whole `store/` application, 150 PHP files under
`store/app` plus 295 React source files — was MIT. Nothing supported that: the
files it covers carry no copyright notice at all, the upstream repository they
came from carries no licence, and nobody in this fork's history had the standing
to relicense them.

It is now `BSD-3-Clause`, matching the root `LICENSE`. That is not a claim that
every file under `store/` is BSD-3 — it plainly is not, and section 2.3 says so
at length. It is the declared licence of the *package* as this fork publishes
it, which is the question the field asks, with the exceptions carried where a
reader will actually find them: `LICENSE` and `THIRD-PARTY.md`. A composer
`license` field has no syntax for "except for these 445 files"; the honest move
is to make it agree with the root licence and put the truth one link away.

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

### 2.2 The removed notices — found, and now restored

**Status: fixed in this commit.** Nine files in this tree were recognisably the
same works as BSD-3-licensed files in `dainok/unetlab`, with the attribution
gone. Eight were confirmed by fetching the upstream file and reading its header;
the ninth had its header **replaced** rather than deleted. All nine now carry
the notice again, transcribed from the upstream file, and
`tests/Licensing/LicenceTest.php` fails if any of them loses it.

| This repository | Upstream file | Upstream header | Was | Now |
|---|---|---|---|---|
| `api.php` | `html/api.php` | Dainese, BSD-3 | no header | restored |
| `includes/functions.php` | `html/includes/functions.php` | Dainese, BSD-3 | no header | restored |
| `includes/init.php` | `html/includes/init.php` | Dainese, BSD-3 | no header | restored |
| `includes/__lab.php` | `html/includes/__lab.php` | Dainese, BSD-3 | no header | restored |
| `includes/__node.php` | `html/includes/__node.php` | Dainese, BSD-3 | no header | restored |
| `themes/default/js/functions.js` | `html/themes/default/js/functions.js` | Dainese, BSD-3 | no header | restored |
| `themes/default/js/javascript.js` | `html/themes/default/js/javascript.js` | Dainese, BSD-3 | no header | restored |
| `platform/wrappers/unl_wrapper` | `wrappers/unl_wrapper.php` | Dainese, BSD-3 | no header | restored |
| `devices/interfc.php` | `html/includes/__interfc.php` | Dainese, BSD-3 | **`@copyright pnetlab.com`** | both, in order |

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

**What this obliged, and what was done.** These files were modified
substantially, and a modified BSD-3 work is still a BSD-3 work — the licence
permits modification and says nothing about how much. What it does not permit is
dropping the notice. *Appearance, not settled law:* PNETLab appears to have
breached clause 1 when it published these, and this fork inherited and continued
that breach every time it redistributed them.

Each file now carries a restored header: the upstream summary line, a
`Derived from UNetLab <path>` line naming where it came from, Dainese's
`@author`/`@copyright`/`@license`/`@link` block transcribed from the upstream
file, and a closing line recording that PNETLab and this fork modified it and
that the notice must be retained regardless. `@version 20160719` was
deliberately **not** carried over: it stopped being true for these files a long
time ago, and re-adding it would be a fresh inaccuracy in the name of fixing an
old one.

`devices/interfc.php` keeps both attributions, in the order they arose —
Dainese's first, then the `@author LIN / @copyright pnetlab.com` block that had
replaced it. Replacing one attribution with the other in either direction would
repeat the original mistake.

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
| AngularJS + ui-router, ui-utils, ui-select, ocLazyLoad, block-ui | 1.5.6 | MIT | Retain notice | Met. Dead weight; see section 6. |
| jsPlumb Community / jsBezier | 2.4 | MIT | Retain notice | Met. |
| Font Awesome | 4.5.0 | OFL-1.1 (fonts) + MIT (code) | Retain notice | Notice inline in CSS. |
| Ubuntu font family | — | Ubuntu Font Licence 1.0 | Ship the licence; do not relicense | `themes/default/fonts/LICENCE.txt` present. Met. |
| Glyphicons Halflings | — | via Bootstrap | Retain Bootstrap notice | Met. |
| PrimeReact / PrimeIcons + Open Sans | 3.3.2 | MIT (Open Sans: Apache-2.0) | Retain notice | npm metadata records no licence for primereact 3.3.2; upstream is MIT. |
| ~~`ARIALBD.TTF`~~ | — | Proprietary (Monotype) | Cannot be redistributed | **Removed.** Replaced by a free-font candidate list; see section 3. |
| **`idlepc`** (prebuilt ELF) | — | **Unknown; embeds LGPL-2.1 `paramiko`** | Cannot be satisfied without source | **In the tree — gap G3.** Also installs a root SSH key; see section 3. |
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

**Why the bundles could not simply be dropped, and what happens instead.** The
first instinct — stop committing 5.4 MB of generated output that has no business
in git — was checked against the installer and does not survive it.
`install/lib/deploy.sh` rsyncs the tree and then does this:

```
if [[ ! -f "${WEB_ROOT}/store/public/react/js/app.js" ]]; then
        warn "store/public/react/js/app.js is absent: the React bundle was never built.
             Run 'npm install && NODE_OPTIONS=--openssl-legacy-provider npm run production' ..."
```

It warns. It does not build. Nothing in `install/` runs `npm` at all — that is
stated outright in `install/README.md`: "**It does not build the frontend.**"
So the committed bundles *are* the delivery mechanism for the admin UI. Removing
them means every `git clone` plus `install.sh` produces an appliance with a
blank admin interface until someone runs a 1273-package `npm install` on it.

For this project that is worse than it sounds. `docs/OFFLINE-FIRST.md` commits
the fork to installing with no external communication of any kind, on air-gapped
networks. Requiring `npm install` on the target — or a connected build host and
a transfer step — reintroduces exactly the dependency the fork exists to shed.
**Trading a working offline install for a licensing tidy is not a trade this
project should make**, so the bundles stay.

That leaves option (c) from the analysis, taken deliberately rather than by
omission: **CKEditor stays, the bundles stay committed, and the GPL position is
stated where a recipient will see it.** The root `LICENSE` says in its scope
section that the built output under `store/public/react/` and `vendors~.`
carries GPL-2.0-or-later terms and is not covered by the BSD-3 grant.
`THIRD-PARTY.md` lists CKEditor 5 first, with its licence and the files it is
compiled into.

This is a **tracked gap**, not a resolved item, and it gates publication
(section 6). Two things close it, and they are not exclusive:

- **Build the bundles during install or in CI.** The real fix. CI already has a
  green `frontend-build` job on Node 22, so the artefacts can be produced and
  attached to a release; the installer would consume a release artefact rather
  than a git-tracked one, and the offline property survives because the artefact
  ships with the release. This is the recommended path and it is a self-contained
  piece of work: it does not require touching the frontend itself.
- **Replace CKEditor.** The roadmap's Phase 06 already contemplates shedding
  frontend weight. CKEditor is used for lab text objects and workbook HTML —
  presentation, not structure — so a permissively-licensed editor would remove
  the copyleft question entirely. Larger, and not urgent if the first path lands.

Until one of them lands, the honest statement is the one now in `LICENSE`: the
fork's source is BSD-3; the built frontend is a combined work under
GPL-2.0-or-later. Both can be true at once, and MIT and BSD-3 combine into a GPL
work without difficulty — what is not tenable is *offering the bundle* under
BSD-3, which is precisely what the scope section prevents.

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
problem described in section 2.3. Corrected in both files in this commit.

This is also the one part of the tree the project unambiguously owns and can
licence however it likes.

---

## 3 · The two binary blobs: one closed, one open

### `store/app/Helpers/Captcha/ARIALBD.TTF` — **closed, replaced**

750 KB. `file` reported the embedded name string: *"© 2014 The Monotype
Corporation. All Rights Reserved. Arial is a trademark of The Monotype
Corporation"*. It was used by one function, `Captcha::createCaptcha()`, as the
`imagettftext()` font — and that function is on the **offline login path**, so
this was a live feature, not dead weight.

Arial is proprietary. There was no licence file, no purchase record, and no
plausible grant permitting redistribution in a public source repository. It
arrived with the upstream import and nobody chose it.

**What was done — a substitution, not a deletion.** `Captcha::$FONTS` is now an
ordered candidate list, and `Captcha::fontPath()` returns the first entry that
exists:

| Candidate | Licence | Where it comes from |
|---|---|---|
| `/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf` | Bitstream Vera + DejaVu public-domain amendment | `fonts-dejavu-core`, present on every Ubuntu image this project targets |
| `/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf` | SIL OFL 1.1 | `fonts-liberation`, for hosts carrying that set instead |
| `../../../../themes/default/fonts/Ubuntu-B.ttf` | Ubuntu Font Licence 1.0 | **this repository**, with its licence text at `themes/default/fonts/LICENCE.txt` |

The third entry is the one that matters. It is already in the tree, already
licensed for redistribution, already carries its licence text beside it, and the
installer rsyncs the repository root — so the captcha renders on an appliance
with no font package installed and no network. That is the offline-first
property from `docs/OFFLINE-FIRST.md`, preserved rather than traded away for a
package dependency. `tests/Licensing/LicenceTest.php` asserts the fallback path
resolves to a real file, and that every tracked font sits under a prefix
`THIRD-PARTY.md` accounts for.

If every candidate is missing, `createCaptcha()` logs which paths it tried and
returns an empty image rather than emitting a broken one silently. That is a
deployment fault, not a licensing one, and it now says so in the log.

*Note on history:* deleting the file from `HEAD` does not remove it from git
history. If the history is published as-is, the font is still in `07ef833`.
That is a judgement call about risk appetite — leave it, or rewrite history
before the first public push, which is far cheaper now than later. It is on the
section 10 checklist as an owner decision.

### `store/app/Console/Commands/idlepc` — **open, and worse than it looked**

9.4 MB. A stripped x86-64 ELF, PyInstaller-packed, built against Python 3.5,
embedding `paramiko`, `bcrypt`, `cryptography 3.1.1`, PyNaCl and OpenSSL. No
source in the repository. Invoked from
`store/app/Http/Controllers/Admin/DefaultController.php`, **under `sudo`**, to
compute a dynamips idle-PC value for the calculator button on the node form.

**The archive was unpacked and the entry script read**, because "remove the
unlicensed blob" is not a decision anyone should take without knowing what it
does. The PyInstaller `CArchive` TOC has 52 entries; the `PYSOURCE` entry named
`idlepc` unmarshals to `idlepc.py`, and its string constants give the whole
program:

```
'/opt/unetlab/html/store/public/index.php'   'Please download PNETLab from pnetlab.com'
"/root/.ssh/id_rsa_dy.pub"
"ssh-keygen -t rsa -N '' -f /root/.ssh/id_rsa_dy 2>&1 > /dev/null"
"cat /root/.ssh/id_rsa_dy.pub >> /root/.ssh/authorized_keys 2>&1 > /dev/null"
'127.0.0.1'  'root'  '/root/.ssh/id_rsa_dy'
'dynamips'   '--idle-pc='   '\x1d'   '[yes/no]'
'\\"--idle-pc=([^\\"]+)\\"'   'idle-pc='
'pkill -9 -f "'   'can not get idle value'
```

with `paramiko` imports (`connect`, `expect`, `disconnect`) around them. In
order, the program:

1. checks `store/public/index.php` exists, or prints "Please download PNETLab
   from pnetlab.com" — a vendor check of the kind this fork has removed
   everywhere else;
2. **generates a passphrase-less RSA key at `/root/.ssh/id_rsa_dy` and appends
   its public half to `/root/.ssh/authorized_keys`**;
3. SSHes to `root@127.0.0.1` with that key;
4. runs `dynamips` in that session and sends `\x1d` — Ctrl-] — to trigger
   **dynamips' own** idle-PC computation, then scrapes `"--idle-pc=([^"]+)"`
   out of the console output;
5. `pkill -9 -f "dynamips ..."`.

Three conclusions follow, and they change how this item should be ranked.

**It contains no algorithm worth keeping.** The computation is dynamips'. The
blob is a wrapper whose only real function is to obtain a TTY, because
`exec()` from PHP has none and dynamips' idle-PC trigger is a console escape.

**It is a root backdoor installer, not just a licensing problem.** Pressing
"calculate Idle-PC" in the admin UI permanently installs a passwordless root SSH
key on the appliance. This fork already deleted exactly this pattern once:
`docs/HANDOVER.md` records that `docker_wrapper` was reimplemented to allocate
its own PTY "instead of shelling out over SSH to `root@localhost`, which deletes
a standing passwordless root SSH key from every appliance". That key is
reintroduced here by an admin button. **This should be treated as a security
finding and handed to whoever owns that queue**; its licence status is now the
less urgent half.

**The licensing problem stands regardless.** `paramiko` is LGPL-2.1, and LGPL §6
attaches conditions to distributing a work that statically incorporates an LGPL
library — broadly, the recipient must be able to relink against a modified
version, which normally means shipping the object files or the source. A frozen
PyInstaller bundle with no accompanying source does not obviously satisfy that.
The other embedded libraries are Apache-2.0 or BSD and want notices we do not
carry. And nobody here built it, nobody can rebuild it, and it runs as root.

#### Why it is still in the tree

Because deleting it removes a working capability and leaves a hole, and because
the replacement **cannot be verified here**. A replacement has to drive a real
dynamips against a real Cisco IOS image, and this project carries neither — by
design. Landing an unverifiable emulator driver and calling it a replacement
would be the same hole with a better name.

So it stays, annotated in three places — the sudo policy, the controller call
site, and here — and it is a **tracked gap that gates publication** (section 6,
section 10). The sudo grant stays with it; `tests/Security/SudoersPolicyTest.php`
enforces drift in both directions, so the binary, its call site and the grant
must land or leave together, which is the forcing function.

#### What the replacement is

`unl_wrapper -a idlepc`, an action beside `fixperms`, `set-proxy`,
`iol-keepalive` and `image-commit` in `platform/wrappers/actions/`. The shape,
so it can be built without re-doing this investigation:

- **Inputs: `--template <name>` and `--image <name>`, nothing else.** Not the
  option string. The current call site passes `dynamips_options` through
  `secureCmd()` and `escapeshellarg()` across the sudo boundary, and template
  option strings are the known argument-injection surface item 4 of
  `docs/HANDOVER.md` calls out. The wrapper should read the option string from
  `templates/<name>.yml` itself, exactly as `UnlIolKeepalive` computes the uid
  rather than accepting one. Both names validate as `[A-Za-z0-9_.-]+` with no
  separators, and the image must resolve under `/opt/unetlab/addons/dynamips`.
- **Start dynamips directly, exec'd from an argv array**, with the template's
  options tokenised in the wrapper, `--idle-pc 0x0`, and the image path.
- **Obtain a console.** This is the one open question and it should be settled
  first, because it decides the shape of everything else:
  - `devices/dynamips/device_dynamips.php` already starts dynamips with
    `-T <port>`, a TCP console listener. If dynamips honours the Ctrl-] escape
    on that listener, the action needs no PTY at all — connect, wait for boot,
    send `\x1d` then `i`, read. That is the cheap outcome and it should be
    tested before anything is written.
  - If the escape is only honoured on a real terminal, use the PTY machinery
    this project already owns: `platform/wrappers/src/child.c` and `console.c`
    exist precisely because `docker_wrapper` needed a PTY without SSH.
  - Under no circumstances reintroduce an SSH loopback.
- **Parse** `"--idle-pc=(0x[0-9a-fA-F]+)"` from the console stream.
- **Terminate by pid**, held from the spawn — never `pkill -f` on a pattern,
  which matches any process whose command line happens to contain the string.
- **The template write stays in the controller**, behind
  `Wrapper::fixperms('templates')`, exactly as it is today. Only the privileged
  emulator run moves.

The unit-testable parts — name validation, option tokenising, the output
parser, the timeout — can be covered without dynamips, in the style of
`platform/wrappers/src/wrapper_test.c`. The part that needs an image is whether
the escape works on the `-T` listener, and that is one afternoon for anyone who
has a c3725 image.

When it lands: delete the binary, delete the sudo grant, delete the controller
method's `exec()`, and remove the React calculator button in
`store/resources/react/components/lab/node/NodeForm.js` at the same frontend
rebuild. `SudoersPolicyTest` and `LicenceTest` will both hold that together.

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
3. **Guard it in CI**, next to the vendor-image guard in `tests/Licensing/LicenceTest.php`. A grep for
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

**Where the boundary is enforced: now, in a test.** It used to be nowhere.
`.gitignore` has no rule for image formats, and CI runs PHP lint, PHP tests, the
C wrapper build and the frontend build without ever asking what is committed.
The boundary held because the people working on the project were careful, which
is a fine reason for it to have held so far and a poor reason to expect it to
keep holding.

`tests/Licensing/LicenceTest.php` now asks git's index directly and fails on any
tracked `*.qcow2 *.vmdk *.vdi *.vhd *.vhdx *.iso *.ova *.ovf *.img *.bin`, on
any path whose basename contains `keygen`, and on any font outside the prefixes
`THIRD-PARTY.md` accounts for. It runs in `tools/run-tests.sh`, which CI runs on
both PHP 8.4 and 8.5. It is a test, not a pre-receive hook — someone determined
can still push past it — but it turns "we have been careful" into "the build
goes red", which is the difference that matters.

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

## 6 · Tracked gaps

The licence is adopted. These are the things it does not cover. The first three
**gate publication**: while any of them is open, a public repository carrying a
BSD-3 `LICENSE` would tell recipients something untrue about what they may
redistribute. The rest is housekeeping — real work, but it does not make the
declaration false.

### Gates publication

**G1 · The unlicensed PNETLab body.** ~10,400 lines of PHP under `devices/`,
~12,300 under `store/app`, ~41,300 lines of JavaScript under
`store/resources/react`. No grant from its author. A root `LICENSE` does not
reach it and cannot be made to. **The only thing that closes this is replacing
the code** — section 8 is the programme. Asking upstream is worth one email and
is unlikely to be answered; accepting and documenting is a real option but it is
the owner's to take knowingly, not a default to drift into.

**G2 · CKEditor 5 GPL-2.0-or-later inside committed build output.**
`store/public/react/js/lab.js` and the `vendors~.` chunk. Closed by building the
bundles in CI or at install time and consuming a release artefact instead of a
tracked one — see section 2.4 for why simply deleting them is not available.
Self-contained; does not require touching the frontend.

**G3 · `store/app/Console/Commands/idlepc`.** Unlicensed, unbuildable, embeds
LGPL-2.1 `paramiko` with no source — and installs a passwordless root SSH key
when the admin presses a button. Closed by `unl_wrapper -a idlepc`, designed in
section 3. **Also a security finding in its own right**, and the security queue
should not wait for the licensing one.

### Housekeeping

4. **Parsedown's missing `LICENSE`.** Its header defers to a file that is not in
   the tree. One file.
5. **`includes/Slim` 2.6.1** is patched in place, which MIT permits, but the
   patches are not marked. A `MODIFICATIONS` note beside `includes/Slim/LICENSE`
   would make the divergence legible.
6. **Ace's notice coverage is thin** — 213 files under `themes/default/js/src`,
   2 carrying the BSD-3 notice. Covered in `THIRD-PARTY.md`, which is enough,
   but worth knowing.
7. **The Cisco icon set.** 164 PNGs plus `icons.rar`, named for vendor products.
   Establish the terms, replace, or state the position. A trademark question
   more than a copyright one, and the same position every comparable project is
   in.
8. **No mechanical guard on the boundary** beyond `tests/Licensing/LicenceTest.php`
   — that test now asserts no tracked vendor image, no keygen, no unaccounted
   font and no `idlepc`, but it runs in `tools/run-tests.sh`, not as a
   pre-receive hook. Good enough; noted so nobody assumes more.
9. **Dead vendored code carries live obligations.** AngularJS 1.5.6 and its
   plugin tree (1.8 MB) are weight the roadmap already wants gone; deleting them
   deletes their attribution requirements too. Same for the vendored upload demo
   at `.../angularJS/plugins/angular-file-upload/upload.php`, whose first
   statement is `exit;`.
10. **Fork-authored files carry no headers.** Add `SPDX-License-Identifier:
    BSD-3-Clause` to the 114 files the fork wrote. Cheap, and it makes the
    boundary between "ours" and "inherited" machine-readable.

### Adjacent, and not a licensing item

`store/.env` was tracked with a live `APP_KEY`; `tests/Security/EnvNotTrackedTest.php`
now holds that line. Recorded here only because this audit is where it surfaced.

---

## 7 · The decision, and why

**BSD-3-Clause, for the fork's own work, with the scope stated in the licence
file itself.**

The alternatives were MIT, Apache-2.0, a copyleft licence, or continuing to
declare nothing. BSD-3 wins on a single argument: it is the licence the largest
inherited body in this tree already uses — 130 files from UNetLab and EVE-NG —
so matching it means one set of conditions to satisfy rather than two. Retain
the notices, reproduce them in the material accompanying a binary distribution,
and do not claim endorsement. All three are things the project must do anyway
for the inherited files; adopting the same licence adds no new obligation.

MIT is marginally more familiar and equally compatible, but it would add a
second permissive licence to a BSD-3 tree for no gain. Apache-2.0 was the real
contender — its express patent grant is worth something in a project that
touches virtualisation and networking, and its `NOTICE` mechanism is a natural
home for the attribution work — but it is one-way incompatible with GPL-2, and
gap **G2** puts GPL-2 code in the shipped frontend. Choosing Apache-2.0 would
have meant resolving a frontend question to settle a licence question, which is
the wrong order. A copyleft licence would suit a project that wants derivative
appliances kept open, and it is the only family that makes G2 disappear — but
the fork's users deploy appliances into corporate labs, and the roadmap's whole
direction is toward being deployable there. Declaring nothing, finally, is what
the repository did until now: it leaves contributors and deployers unable to act
with confidence, and it reads as carelessness rather than as a position.

**What the decision does not do.** It does not relicense the PNETLab body (G1),
it does not make the built bundles BSD-3 (G2), and it does not launder the
`idlepc` blob (G3). The scope section of `LICENSE` says all three in the file a
recipient reads first. That is the difference between adopting a licence and
misrepresenting one.

**What would change it.** If the owner intends to sell or offer commercial
support, G1 stops being a background risk and becomes a diligence item, and the
programme in section 8 moves from "worth doing" to "necessary". If upstream
answers the request in section 8, everything simplifies and BSD-3 becomes
straightforwardly correct rather than the best available.

---

## 8 · Coding out the unlicensed body

This is the gap that no licence file reaches and the one the owner will work
from. It is a programme, not a task, and it is worth being precise that it is
also **not urgent in the way G2 and G3 are**: those are two self-contained
pieces of work; this is a direction of travel measured in months.

### The shape of the problem

| Area | Size | Replaceability | Notes |
|---|---|---|---|
| `store/resources/react` | 265 files, ~41,300 lines JS | **Low** | The largest single body and the least mechanical. This is the product's UI. |
| `store/app` (Laravel) | 133 files, ~12,300 lines PHP | **Medium** | 27 controllers (~4,100 lines), 21 models (~2,900), 28 helpers (~2,400). |
| `devices/` | 90 files, ~10,400 lines PHP | **High** | 59 QEMU drivers, 25 dynamips files, 3 base classes. Largely mechanical. |
| `templates/` | 20 of 133 unattributed | **Trivial** | Data, not code; and 111 of them are already BSD-3. |

Two observations that should shape the order.

**The unlicensed body is smaller than it first appears.** The headline "445
files" counts every file; the actual source is ~64,000 lines, of which
two-thirds is the React frontend. The PHP side — the part that carries the
product's behaviour — is about 22,700 lines across `devices/` and `store/app`.
That is large but it is not a decade of work.

**The fork has already proved it can do this.** `platform/wrappers/src` is ~2,500
lines of C reimplementing three console wrappers, written clean-room from a
behavioural specification by someone who had not read the originals, and it
works. The method exists, it is documented in
`platform/wrappers/src/README.md`, and it produced better code than the
originals — the SSH-to-localhost root key went away because someone rewrote the
thing rather than vendoring it. That is the template.

### A sensible order

**Phase A — `devices/`, the highest ratio of coverage to effort.**
90 files, ~10,400 lines, and the most mechanical code in the tree: each driver
translates template fields into a command line. The interface is already
documented in `README.md`, the behaviour is observable, and there is a test
harness (`tools/integration/node-types.sh`) that exercises it. Start with the
five base classes (`device.php`, `interfc.php`, and the qemu/dynamips/docker
bases) since every driver inherits from them, then the QEMU family, which is 59
near-identical files that mostly differ in flag construction. `devices/interfc.php`
is BSD-3-inherited rather than PNETLab-authored, so it does not need replacing
at all — only its notice, now restored.

**This phase also has a forcing event already on the roadmap.** Phase 02.5 is
the rebase-or-forward-port decision, and the box ships 8 QEMU drivers the repo
does not have. Whichever way that goes, these files are being touched. Rewriting
rather than importing costs the difference between the two, not the whole cost.

**Phase B — `store/app`, following the Laravel migration.**
~12,300 lines, and Phase 03 already plans to "start from a fresh skeleton and
port `app/` onto it". Porting file-by-file and rewriting file-by-file are much
closer in cost than they look, because the port already requires reading and
understanding every file. The order within it: controllers first (they are the
thinnest and most replaceable), then helpers, then models — the models encode
the database schema, which is the part with the least design freedom and the
least value in rewriting.

**Phase C — the React frontend, and only if it must be done.**
~41,300 lines, low replaceability, and the least likely to be worth a clean-room
rewrite for licensing reasons alone. If the fork ever does a genuine frontend
modernisation — the roadmap's Phase 06 contemplates React 16 → 18 and shedding
primereact and redux — that is the moment this becomes affordable, because a
rewrite that was going to happen anyway costs nothing extra in licensing terms.
Until then this is the residual risk and it should be named as such rather than
pretended away.

**Phase 0, in parallel with all of it — ask.**
One email to `pnetlabs@gmail.com`, the address on all five upstream commits:
would they add an MIT or BSD-3 `LICENSE` to `pnetlab_main`, or grant this fork
those terms in writing? While asking, ask the same of `pnetlab/pnetlab_wrapper`.
The repository was published deliberately and publicly with a README addressed
to developers explaining how to extend it — that is the behaviour of someone who
intended the code to be used and did not think about the paperwork. Last push
2023-07-24, and `secure.pnet-lab.com` no longer resolves, so expect no reply.
Send it anyway and keep the sent copy: a documented good-faith attempt is worth
having, and if it lands it deletes this entire section.

### What "done" looks like

Not "every file rewritten". Done is: **no file in the tree carries a copyright
line without a licence grant, and no file lacking a notice is materially
PNETLab's work.** That is reachable through Phases A and B alone if the owner
accepts the frontend as documented residual risk — which is a legitimate
position, and a much smaller one than today's.

Track it the way the sudo policy is tracked. `tests/Licensing/LicenceTest.php`
already counts inherited notices with a floor assertion; the same shape can
count files marked `@copyright pnetlab.com` with a **ceiling** that only ever
falls. That turns a programme into a ratchet, and it is what stopped the sudo
grants creeping back.

---

## 9 · Where a real legal opinion is warranted

Three, in order of how much rides on them.

1. **Section 2.3 / gap G1 — the unlicensed PNETLab body.** Specifically: does GitHub's ToS
   §D.5 fork grant permit this repository to exist and be modified publicly, and
   does it permit anything beyond that — an installer that deploys the code onto
   a user's machine, a release tarball, an ISO? This is the question that
   decides whether the project can distribute at all, and it is the one where an
   engineering reading is least reliable, because the answer turns on what
   "through the Service" means and on jurisdiction.

2. **Section 4 — the keygen call site.** Does calling an operator-supplied
   `CiscoIOUKeygen.py`, and writing the `iourc` it produces, amount to
   trafficking in a circumvention device or to contributory infringement, in the
   jurisdictions the project cares about? My assessment is that it does not and
   that the difference from shipping the keygen is real, but this is
   anti-circumvention law, the penalties are the most severe in this document,
   and I would not publish on my own reading of it.

3. **Section 2.4 / gap G2 — CKEditor and the committed bundles.** Whether a
   webpack bundle containing GPL-2.0-or-later code is a "work based on the
   Program" for GPL-2 purposes, and therefore whether the root `LICENSE` over a
   repository containing `lab.js` is a misstatement. This one got sharper rather
   than softer during the work: the first instinct was to route around it by not
   committing the bundles, and that turned out to break offline installs
   (section 2.4), so the bundles stay and the question is live. The scope
   section of `LICENSE` is written to be true either way — it excludes the built
   output explicitly — but whether that exclusion is *sufficient*, as opposed to
   merely honest, is a lawyer's question.

A fourth, lower-stakes and worth raising in the same conversation since it costs
nothing extra: whether the nine stripped BSD-3 notices create any residual
liability for this fork given that the stripping was done upstream, or whether
restoring them prospectively is sufficient. My assumption throughout has been
that restoring them is both necessary and sufficient.

---

## 10 · Checklist

### THE GATE — nothing below this line makes the repository publishable on its own

> **No known-incompatible component remains in the tree, or each remaining one
> has been explicitly accepted by the owner and named in `LICENSE`.**
>
> This is the single condition on making the repository public. It exists
> because publishing with a BSD-3 `LICENSE` over a tree containing components
> that licence cannot cover is a false statement to every recipient about what
> they may redistribute — worse than declaring nothing, which is where the
> project was before this commit. See "Adopted is not published".
>
> - [ ] **G1** · the unlicensed PNETLab body — replaced (section 8), or
>       accepted in writing by the owner and named in `LICENSE`
> - [ ] **G2** · CKEditor GPL-2.0+ in committed bundles — bundles built in CI
>       or at install time, or accepted and named (currently: **named**, in
>       `LICENSE`, pending a decision to close it properly)
> - [ ] **G3** · `idlepc` — replaced by `unl_wrapper -a idlepc`, or accepted
>       and named
> - [ ] Owner has read section 9 and decided whether to take legal advice first

### Done in this commit

- [x] Adopt BSD-3-Clause: `LICENSE` at the root, with a scope section naming
      what it does not cover.
- [x] Correct `store/composer.json` from the skeleton's `"license": "MIT"` to
      `"license": "BSD-3-Clause"`.
- [x] Restore the BSD-3 notice on the nine files in section 2.2, transcribed
      from the upstream file, each with a "derived from" line and a
      modification note. `@version 20160719` deliberately not carried over.
      `devices/interfc.php` keeps both attributions.
- [x] Replace `ARIALBD.TTF` with a candidate list ending in the in-tree Ubuntu
      Bold, so the captcha renders offline with no font package. Delete the
      proprietary file.
- [x] Unpack and read the `idlepc` archive; record what it does — including the
      passwordless root SSH key — in the sudo policy, the controller call site
      and section 3. Keep the capability and the grant; design the replacement.
- [x] Correct the "no source-level derivative work" sentence in
      `install/vendor/guacamole/README.md`, and attribute
      `install/sql/schema/guacdb.sql` to Apache-2.0.
- [x] Correct the claim that `pnetlab/pnetlab_wrapper` is BSD-3-Clause, in
      `docs/HANDOVER.md` and `platform/wrappers/src/README.md`.
- [x] Write `THIRD-PARTY.md`, and confirm it reaches the appliance:
      `deploy_excludes()` does not list it and the root is rsynced to
      `/opt/unetlab/html`.
- [x] `tests/Licensing/LicenceTest.php`: asserts the licence declarations agree,
      the nine notices are present, the inherited-notice count has a floor of
      130, no tracked font is unaccounted for, no vendor image or keygen or
      `idlepc` is tracked, `THIRD-PARTY.md` and `LICENSE` are not excluded from
      the deploy, and the captcha's fallback font resolves.

### Ours to do, no decision required

- [ ] Add `includes/Parsedown.LICENSE` (MIT, Emanuil Rusev) so Parsedown's
      header stops pointing at a file that is not there.
- [ ] Add `SPDX-License-Identifier: BSD-3-Clause` to the 114 fork-authored files.
- [ ] Add a "Licensing" section to `README.md`: what the fork licenses, what it
      inherited, what it does not ship (no vendor images, no Cisco software, no
      licence keys, no keygen), and a link here.
- [ ] Name the IOL boundary in `README.md` and rename `refreshIolLicense()` so
      it does not read as though the fork participates in licensing IOL
      (section 4).
- [ ] Delete the dead AngularJS tree and `angular-file-upload/upload.php`,
      removing their attribution obligations with them.
- [ ] Add a `MODIFICATIONS` note beside `includes/Slim/LICENSE`.
- [ ] Send the upstream request (section 8, Phase 0) and keep the sent copy.

### Needs the owner's decision

- [ ] **The gate above.** Which of G1/G2/G3 get closed, and which get accepted
      and named.
- [ ] Whether to rewrite history before the first public push, to remove the
      Monotype font and the `idlepc` blob from `07ef833` onward. Deleting them
      from `HEAD` does not remove them from history.
- [ ] Establish or replace the Cisco icon set (section 5).
- [ ] Whether to take legal advice on the three points in section 9 before
      publishing, or to publish on this document's reading of them.

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
