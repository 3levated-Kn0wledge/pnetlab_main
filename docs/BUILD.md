# Building

## The one thing that will catch you out

**npm runs from the repository root. Composer runs from `store/`.**

```bash
npm install                     # repository root
npm run production              # repository root

cd store && composer install    # store/
```

That split is inherited, not chosen. It is not obvious, and getting it wrong
produces a build that *succeeds* while writing files to the wrong place.

### Why the frontend build must run from the root

Three separate things hardcode root-relative paths:

1. `webpack.mix.js` — every path in it (`store/resources/react/app.js`,
   `store/public/react/js`) is written from the root, as are its `@root`,
   `@react` and `@comp` aliases, which resolve through `__dirname`.
2. `store/resources/react/app.js:36` — the lazy-loaded page chunks carry a
   hardcoded chunk name:

   ```js
   import(/* webpackChunkName: "./store/public/react/pages/[request]" */ ...)
   ```

   That path is resolved against the build's public path, so it only lands
   correctly when the build root is the repository root.
3. Upstream's own committed output includes a root-level `vendors~./`
   directory, which only a root build produces.

Run it from `store/` and webpack still reports `Compiled successfully` — while
emitting `store/public/store/public/react/pages/...`. **A green build is not
evidence of a correct build here.** CI asserts the layout explicitly for this
reason; keep those assertions.

### Why `composer` stays in `store/`

`store/` is the Laravel application and owns its own `composer.json`. Nothing
about the PHP side assumes the repository root, so it stays where it is.

## Node version

Node 22 is the target and what CI runs. The build has also been verified on
Node 20.

webpack 4 predates Node 17's switch to OpenSSL 3, so it needs:

```bash
NODE_OPTIONS=--openssl-legacy-provider npm run production
```

This goes away with the laravel-mix 6 / webpack 5 upgrade.

## Expected output

```
store/public/react/js/{app,lab,main}.js
store/public/react/pages/*.js            ~30 lazy-loaded page chunks
vendors~./store/public/react/pages/*.js  shared vendor chunks (root level)
```

If you see `store/public/store/`, the build ran from the wrong directory.

`mix-manifest.json` is written to the root and is gitignored: nothing calls
Laravel Mix's `mix()` helper, so it is inert.

## Linting PHP

```bash
tools/php-lint.sh              # uses the php on PATH
PHP=php8.4 tools/php-lint.sh   # pick an interpreter
```

Target is PHP 8.4 — currently 303 files, 0 failures. The shipped appliance still
runs 7.2, and the tree is kept parseable on both: `#[\ReturnTypeWillChange]` is
an attribute on 8.x and a comment on 7.x.

No PHP locally? A container works:

```bash
podman run --rm -v "$PWD":/src:ro -w /src php:8.4-cli bash tools/php-lint.sh
```
