# Third-party components and attribution

This file is the attribution that accompanies every distribution of this
software, in source or in binary form. It exists because several components in
this tree are licensed on the condition that their copyright notices travel with
them — BSD-3-Clause clause 2 asks for exactly this, and Apache-2.0 §4 asks for
something close to it.

**Keep this file with the distribution.** It already reaches an installed
appliance: `install/lib/deploy.sh` rsyncs the repository root to
`/opt/unetlab/html` and this file is not in `deploy_excludes()`, so it lands at
`/opt/unetlab/html/THIRD-PARTY.md`. **Do not add it to that exclude list** —
unlike `README.md` and `SECURITY.md`, which are excluded and are meant to be,
this one is the thing BSD-3 clause 2 asks to accompany a binary distribution.
If you package this project by any other means, include it.

> This is an attribution inventory, not the project's own licence. The fork's
> own work is licensed BSD-3-Clause — see [`LICENSE`](LICENSE), whose scope
> section names what that grant does *not* cover. The evidence and the full
> position are in [`docs/LICENSING.md`](docs/LICENSING.md).

---

## UNetLab / EVE-NG

130 files in this tree are derived from UNetLab and EVE-NG and carry a
BSD-3-Clause notice.

```
Copyright (c) 2014-2016, Andrea Dainese <andrea.dainese@gmail.com>
Copyright (c) 2016, Andrea Dainese
Copyright (c) 2018, Alain Degreffe
Copyright (c) 2019, Alain Degreffe
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:
    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of the UNetLab Ltd nor the name of EVE-NG Ltd nor the
      names of its contributors may be used to endorse or promote products
      derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
```

Canonical text: <https://github.com/dainok/unetlab/blob/master/LICENSE>

Where it applies:

| Path | Count | What |
|---|---|---|
| `templates/*.yml` | 111 | Device templates. Full notice inline in each file. |
| `includes/*.php` | 16 | The legacy REST API: `api_authentication`, `api_configs`, `api_folders`, `api_labs`, `api_networks`, `api_nodes`, `api_pictures`, `api_status`, `api_textobjects`, `api_topology`, `api_uusers`, `cli`, `messages_en`, `__network`, `__picture`, `__textobject`. |
| `themes/default/js/*.js` | 3 | `actions.js`, `messages_en.js`, `validate.js`. |

Files modified in this fork retain their original notice. Where a file has been
modified, the modification is this project's and is not attributed to the
original authors.

**This project is not affiliated with, endorsed by, or a product of UNetLab Ltd
or EVE-NG Ltd.** The BSD-3-Clause non-endorsement condition is taken seriously:
neither name is used to promote this fork.

### Files whose notice was removed before this fork existed — restored

Nine files in this tree are recognisably the same works as BSD-3-licensed files
in `dainok/unetlab` but reached this repository with their notice removed or, in
one case, replaced by a different attribution. **The notices have been restored**,
transcribed from the corresponding upstream file, and
`tests/Licensing/LicenceTest.php` fails if any of them loses one again:

`api.php`, `includes/functions.php`, `includes/init.php`, `includes/__lab.php`,
`includes/__node.php`, `themes/default/js/functions.js`,
`themes/default/js/javascript.js`, `platform/wrappers/unl_wrapper`, and
`devices/interfc.php` (upstream `html/includes/__interfc.php`) are derived from
UNetLab, copyright 2014-2016 Andrea Dainese, licensed BSD-3-Clause as above, and
have been substantially modified since by PNETLab and by this fork.

`devices/interfc.php` carries **both** attributions — Dainese's, then the
`@author LIN / @copyright pnetlab.com` block that had replaced it. Replacing one
with the other in either direction would repeat the original mistake. See
`docs/LICENSING.md` section 2.2.

---

## Apache Guacamole

Not committed to this repository. `install/vendor/guacamole/` stages release
binaries fetched by `tools/vendor-guacamole.sh` and pinned by `SHA512SUMS`; the
installer deploys them byte for byte, so their own `LICENSE` and `NOTICE` files
travel inside the `.war` and `.jar` and arrive intact on every installed host.

```
Apache Guacamole
Copyright The Apache Software Foundation
Licensed under the Apache License, Version 2.0
https://www.apache.org/licenses/LICENSE-2.0
```

| Artefact | Version |
|---|---|
| `guacamole-<version>.war` | 1.5.5 pinned, 1.3.0 fallback |
| `guacamole-auth-jdbc-mysql-<version>.jar` | must match the `.war` |
| `guacd` and the protocol client libraries | from the Ubuntu archive |

**One Guacamole-derived file *is* committed.** `install/sql/schema/guacdb.sql`
is the stock JDBC schema published by the Apache Guacamole project, obtained by
`mysqldump --no-data` from a PNETLab 5.3.13 appliance. It is Apache-2.0 and is
reformatted relative to Guacamole's own `.sql` files by that dump; no table,
column or constraint was changed by this project.

See `install/vendor/guacamole/README.md` for the vendoring mechanism.

---

## Bundled libraries

Each carries its own notice in its own files unless a separate licence file is
named. Versions are as they appear in the tree.

### PHP

| Component | Version | Licence | Location | Notice |
|---|---|---|---|---|
| Slim Framework | 2.6.1 | MIT — Copyright (c) 2012 Josh Lockhart | `includes/Slim/` | `includes/Slim/LICENSE` |
| Slim-Extras `DateTimeFileWriter` | — | MIT — Copyright (c) 2012 Josh Lockhart | `includes/Slim-Extras/` | inline |
| Parsedown | — | MIT — Emanuil Rusev, <https://erusev.com> | `includes/Parsedown.php` | inline header; **its referenced `LICENSE` file is missing from this tree** and should be added |

Composer dependencies are not committed (`store/vendor/` is gitignored) and are
resolved from `store/composer.lock`: 61 MIT, 32 BSD-3-Clause, 1 Apache-2.0, and
2 tri-licensed (`nette/utils`, `nette/schema` — BSD-3-Clause or GPL-2.0-only or
GPL-3.0-only; this project takes them under BSD-3-Clause). Each package carries
its own notice in its own distribution.

### JavaScript and CSS

| Component | Version | Licence | Location |
|---|---|---|---|
| **CKEditor 5** (`ckeditor5-build-classic`, `ckeditor5-react`) | 16.0.0 / 2.1.0 | **GPL-2.0-or-later** — Copyright (c) 2003-2019 CKSource, Frederico Knabben | compiled into `store/public/react/js/lab.js` and the `vendors~.` chunk; `store/public/extensions/ckeditor/ckeditor.css` |
| Ace editor | 1.2.6 | BSD-3-Clause — Copyright (c) 2010, 2012 Ajax.org B.V. | `themes/default/js/src/` (213 files) |
| jsPlumb Community / jsBezier | 2.4 | MIT — Copyright (c) 2010-2017 jsPlumb | `themes/default/bootstrap/js/jsPlumb-2.4*.js` |
| jQuery | 3.2.1, 3.3.1 | MIT | `themes/default/bootstrap/js/`, `store/public/extensions/jquery/` |
| jQuery UI | 1.12.1 | MIT | as above |
| jQuery Validation | 1.14.0 | MIT — Copyright (c) 2015 Jörn Zaefferer | `themes/default/bootstrap/js/` |
| jquery-cookie | 1.4.1 | MIT | `themes/default/bootstrap/js/` |
| jquery.hotkeys, jquery.panzoom | — | MIT | `themes/default/bootstrap/js/` |
| Bootstrap | 3.3.5, 4.1.3 | MIT | `themes/default/bootstrap/`, `store/public/extensions/bootstrap/` |
| Popper.js | — | MIT — Copyright (c) 2016-2018 Federico Zivolo | `store/public/extensions/bootstrap/js/popper.min.js` |
| AngularJS | 1.5.6 | MIT | `store/public/extensions/angularJS/` |
| angular-ui-router, ui-utils, ui-select, ocLazyLoad, block-ui, angular-file-upload, ngDrag | — | MIT | `store/public/extensions/angularJS/plugins/` |
| Moment.js | — | MIT | `store/public/extensions/datetimepicker/js/moment.js` |
| bootstrap-datetimepicker | — | MIT | `store/public/extensions/datetimepicker/` |
| SweetAlert2 | — | MIT | `store/public/extensions/swal2/` |
| toastr | — | MIT | `store/public/extensions/toastr/` |
| Animate.css | — | MIT — Copyright (c) 2018 Daniel Eden | `store/public/extensions/animate/` |
| WOW.js | — | MIT | `store/public/extensions/animate/wow.js` |
| Owl Carousel | — | MIT | `store/public/extensions/owl_carousel/` |
| nanoScrollerJS | — | MIT — Copyright (c) 2012-2013 Artan Sinani | `store/public/extensions/nanoscroller/` |
| dom-to-image | — | MIT | `store/public/extensions/domtoimg/` |
| Hover.css | — | MIT | `store/public/extensions/hover/` |
| showdown | — | MIT | `themes/default/bootstrap/js/showdown.min.js` |
| circle-progress | 1.1.3 | MIT | `themes/default/bootstrap/js/` |
| imageMapResizer | — | MIT | `themes/default/bootstrap/js/` |
| EJS | — | MIT | `themes/default/js/ejs.js` |
| PrimeReact / PrimeIcons | 3.3.2 | MIT | `store/public/extensions/`, `fonts/vendor/primeicons/`, `images/vendor/primereact/` |

npm dependencies used at build time are resolved from `package-lock.json`
(1273 entries) and are not committed.

### Fonts

| Font | Licence | Location |
|---|---|---|
| Ubuntu font family | Ubuntu Font Licence 1.0 | `themes/default/fonts/`, licence at `themes/default/fonts/LICENCE.txt`. **Ubuntu Bold is also the offline captcha font** — see below. |
| Font Awesome 4.5.0 | Fonts: SIL OFL 1.1 · Code: MIT | `themes/default/fonts/`, `store/public/extensions/icons/fonts/`, `store/public/main/css/fonts/` |
| Glyphicons Halflings | Distributed with Bootstrap under Bootstrap's terms | `themes/default/bootstrap/fonts/` |
| Open Sans | Apache-2.0 | `fonts/vendor/primereact/resources/themes/nova-light/` |

`store/app/Helpers/Captcha/ARIALBD.TTF` — Arial Bold, © 2014 The Monotype
Corporation, all rights reserved — **has been removed.** It had no licence
permitting its redistribution and it arrived with the upstream import.

The captcha now takes the first font it finds from an ordered list
(`App\Helpers\Captcha\Captcha::$FONTS`): DejaVu Sans Bold from
`fonts-dejavu-core` (Bitstream Vera licence plus the DejaVu public-domain
amendment), then Liberation Sans Bold (SIL OFL 1.1), then
`themes/default/fonts/Ubuntu-B.ttf` from this repository under the Ubuntu Font
Licence 1.0. The last is the guaranteed one: it ships with the appliance, so the
captcha renders with no font package installed and no network.

---

## Icons

`images/icons/` and `images/icons.rar` contain 164 network-topology icons
derived from the set redistributed by UNetLab and EVE-NG, many of which
represent Cisco Systems products and bear Cisco product names. Cisco publish a
network-topology icon set for use in documentation under their own terms. Those
terms are not reproduced here and this project has not established which of
these files they cover. Product names and logos are the trademarks of their
respective owners; their appearance here identifies the device a template
emulates and implies no affiliation or endorsement.

---

## Prebuilt binaries

`store/app/Console/Commands/idlepc` is a 9.4 MB stripped ELF of unknown
provenance, PyInstaller-packed against Python 3.5 and embedding `paramiko`
(LGPL-2.1), `cryptography` 3.1.1, `bcrypt`, PyNaCl and OpenSSL. No source
accompanies it and this project cannot rebuild it. **Its removal is an open item
on the pre-publication checklist** (`docs/LICENSING.md` §3). It is named here
because it is in the tree and its embedded components have attribution
requirements this project is not currently able to satisfy.

No other prebuilt binary is committed. The console wrappers under
`platform/wrappers/src/` are C source compiled by the installer.

---

## What this project does not ship

Stated here because their absence is deliberate and permanent:

- **No vendor operating-system images.** No QEMU disk image, IOL binary,
  dynamips IOS image, appliance ISO or container image is in this repository.
  Operators supply their own, under whatever licence they hold.
- **No Cisco software, no Cisco licence keys, and no tool for generating them.**
  This repository contains no `CiscoIOUKeygen.py` or equivalent. IOL support
  requires the operator to supply their own images and their own `iourc`.
- **No third-party marketplace content.** The signed-package format
  (`docs/PACKAGES.md`) can carry images, and this project neither hosts nor
  endorses packages that contain licensed vendor software.
