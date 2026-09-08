# Perdita Core

The free companion plugin for the [Perdita](https://perdita.ericrosenberg.com)
theme. It carries everything the theme can't: SEO, forms, page caching,
security hardening, analytics, email, backups, a small store, an MCP server,
and the module registry that turns each of those on and off.

## Why it exists

Perdita is going to the wordpress.org theme directory, and the theme review
rules keep plugin territory out of a theme: SEO output, forms, caching,
analytics, shortcodes, custom post types, and custom roles. All of that used to
live in the theme's `inc/`. It lives here now.

That makes the standard three-piece freemium shape:

| Piece | What it owns |
|---|---|
| **Perdita** (theme) | Design. The token document, the CSS generator, the Customizer, fonts, patterns, the AI provider router, `Perdita_Crypto`, the child theme generator, migration from other themes. |
| **Perdita Core** (this plugin, free) | Behavior. The module registry and all thirteen free modules. |
| **Perdita Pro** (paid plugin) | More modules, registered into this plugin's registry through the `perdita_register_modules` action. |

## How it hangs together

The plugin **requires the Perdita theme** (as the active theme or the parent of
one). Plugins load before a theme's `functions.php`, so this one boots on
`after_setup_theme` priority 0, checks `Perdita_Core::theme_is_ready()`, and
registers nothing but a dismissible admin notice when the theme isn't there.

`perdita_core()` returns the bootstrap. It exposes `->modules`, `->seo` (the
SEO store), `->forms`, `->shortcodes` and `->section`, plus references to the
theme's `->settings`, `->ai`, `->css` and `->fonts` so a module handed the
bootstrap as `$core` reaches everything by the same property names it used when
the theme owned it. The theme keeps a `__get()` shim, so third-party code that
still writes `perdita()->seo` keeps working.

Module enable state lives in this plugin's own `perdita_core` option, not the
theme's `perdita_settings`. Existing choices are copied across once, on the
first boot after install.

## Development

```
# syntax check everything
find . -name '*.php' -not -path './dist/*' -exec php -l {} \;

# run the smoke suite against a real WordPress
bash tests/run.sh /path/to/wordpress

# move the theme, Perdita Core, Perdita Pro, and the license server to one version
# (the three ship together and warn in wp-admin when their versions differ)
bin/bump-version.sh 0.18.0-alpha

# build a release zip and its update manifest (refuses a version the siblings do not share)
bin/build-release.sh

# build the wordpress.org submission zip (no self-hosted updater)
bin/build-release.sh --wporg
```

The build script packages `git archive HEAD`, so it refuses to run on a dirty
tree and the artifact can only ever contain committed code. See `tests/README.md`
for what the suite covers and why it doesn't run through wp-cli.

## License

GPL-2.0-or-later. See `LICENSE`.
