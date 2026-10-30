---
use_cases:
  - Switching an application between Bootstrap, Tailwind and plain CSS
  - Keeping one colour palette across UI systems
  - Writing views that survive a UI switch
  - Styling a screen so it stays readable in the dark theme
  - Keeping an application's own view templates rendering correctly after project:switch-ui
---

# One palette, every UI system

Part of the [theme guide](Pramnos_Theme_Guide.md).

## One palette, every UI system

A project's colours live in **one file**, `app/themes/theme.css`, written in the format
[daisyUI's theme generator](https://daisyui.com/theme-generator/) already emits:

```css
@plugin "daisyui/theme" {
    name: "acme";
    default: true;
    color-scheme: light;

    --color-base-100: oklch(100% 0 0);
    --color-base-content: oklch(21% 0.032 264.7);
    --color-primary: oklch(54.6% 0.215 262.9);
    --color-primary-content: oklch(100% 0 0);
    --radius-box: 0.75rem;
}

@plugin "daisyui/theme" {
    name: "acme-dark";
    prefersdark: true;
    color-scheme: dark;
    /* … */
}
```

`pramnos init` writes it, named after the application, and nothing else in a scaffolded
project carries a colour value.

**Annotate it freely.** Comments are stripped before anything is parsed, so `/* … */`
is safe anywhere in the file — beside a declaration, on a line of its own inside a
block, or wrapped around a declaration you are trying without deleting:

```css
@plugin "daisyui/theme" {
    name: "acme";
    /* The surfaces: white cards on a light grey page. */
    --color-base-100: #ffffff;
    --color-base-200: #f7f7f7;   /* the page behind them */
}
```

**Why that format rather than a config file of our own.** It is the one a designer can
produce without this framework existing — pick colours on daisyUI's site, copy the
block, paste it in — and for a Tailwind project with npm it needs no build step at all:
`assets/src/app.css` imports the file and the plugin reads the blocks directly.

### The build tool

Everything that is not Tailwind-with-npm needs the same tokens in a form it can read,
and that is generated:

```bash
pramnos theme:build            # write both outputs
pramnos theme:build --check    # exit 1 if they are stale — for CI
```

| Output | Read by |
|---|---|
| `www/assets/css/theme-tokens.css` | Every server-rendered theme — buildless Tailwind, Bootstrap, plain CSS. Linked from `head.php` before the theme's own stylesheet. |
| `www/assets/theme-tokens.json` | A SPA's own components, and anything else that reads JavaScript rather than CSS. |

#### The same palette, under each system's own names

Only the tailwind theme speaks daisyUI. Bootstrap's components read `--bs-*` and the
plain-CSS theme reads a vocabulary of its own, so `theme-tokens.css` also emits the
palette **as aliases of those names**:

```css
:root, [data-theme="acme"] {
    --color-primary: oklch(54.6% 0.215 262.9);
    /* The same palette, for Bootstrap and the plain-CSS theme. */
    --bs-primary: var(--color-primary);
    --bs-primary-rgb: 37, 99, 235;
    --primary-color: var(--color-primary);
}
```

Aliasing rather than rewriting those stylesheets is deliberate, and it buys something a
rewrite cannot: **Bootstrap's own components read `--bs-*` too**, so redefining the
variable reaches `.btn-primary`, `.navbar` and `.card` as well as the theme's rules —
and nothing here could edit Bootstrap. `head.php` links this file after the UI
framework's, so an equally specific `:root` in Bootstrap's stylesheet loses on source
order.

The aliases are written into every theme block, including the
`prefers-color-scheme: dark` one, so the dark palette carries its own.

**The `-rgb` triplets are computed, not aliased.** `.bg-primary` is
`rgba(var(--bs-primary-rgb), var(--bs-bg-opacity))` — and the scaffolded bootstrap
navbar is a `.bg-primary`. A palette written in `oklch()` cannot be fed to `rgba()` and
CSS cannot decompose it, so `theme:build` converts those values to sRGB itself, by the
conversion CSS Color 4 defines. `oklch()`, a hex literal and `rgb()` all convert; a value
it cannot resolve — `color-mix()`, a named colour, a `var()` reference — simply gets no
`-rgb` alias, and Bootstrap keeps its own for that one colour. A missing triplet is a
colour that disagrees; a guessed one is a colour nobody chose.

**What this turns on.** A `prefersdark` theme reaches the bootstrap and plain-CSS
themes as well, through `theme-tokens.css`'s `prefers-color-scheme` block — so a project
on either will follow the visitor's operating system unless it says otherwise. The
controls for that are the ones that were already there: drop `prefersdark: true` from the
palette, or set `data-theme` on `<html>`.

The generated stylesheet puts each theme under `[data-theme="<name>"]`, the one flagged
`default` on `:root` as well, and the one flagged `prefersdark` inside a
`prefers-color-scheme: dark` block scoped to `:root:not([data-theme])` — so the
operating system decides for a visitor who has not chosen, and an explicit choice still
wins. Without that scoping a theme switch works only for visitors whose OS is already
in light mode.

A scaffolded SPA's `scripts/build-theme.mjs` reads `app/themes/theme.css` too, on every
build and every dev-server start — the same file, rather than the server theme's
rendered `:root` properties, so no part of the palette has to be guessed back from CSS.

**That makes two readers of one file**, and they have to agree about what it means:
`ThemeTokens` for the server-rendered themes, `build-theme.mjs` for the SPA. Where they
drift is over what is *not* a declaration — a comment — so both strip comments first,
and `tests/js/spa-build-theme.test.js` runs the script against a commented palette.
Change the parsing on one side and change it on the other.

### Reading a token from PHP

For the places a custom property cannot reach — `<meta name="theme-color">`, an HTML
email:

```php
$brand = \Pramnos\Theme\ThemeTokens::token('--color-primary', fallback: '#2563eb');
```

The file is read once per request. `ThemeTokens::load()` returns every theme;
`defaultTheme()` picks the one a page gets when it asks for none — the one flagged
`default`, or the first declared, which is what daisyUI itself does.

Three things about `token()` worth relying on:

- **It never throws and never returns nothing you did not choose.** A theme that is not declared, a
  token that is not declared, a palette file that is missing or unparsable — every one of them gives
  back your `fallback`. A chart with the wrong shade is a cosmetic bug; an exception in a mail
  template is a mail nobody receives.
- **The leading `--` is optional.** `token('primary')` and `token('--primary')` are the same lookup,
  because half of the callers think in token names and half in custom properties.
- **With no `fallback`, a miss is `''`.** Not `null` — which would otherwise reach string
  concatenation in every template that omits the third argument.

**For an HTML email, use `hex()` instead.**

```php
$brand = \Pramnos\Theme\ThemeTokens::hex('--color-primary', fallback: '#2563eb');
```

`token()` returns what the palette says, and daisyUI's generator writes `oklch()`. No
mail client parses that: the declaration is dropped and the colour becomes whatever the
surrounding markup says, which in a dark template is black on black. `hex()` converts —
the same conversion `theme:build` uses for Bootstrap's `-rgb` triplets, so an email and
the page agree by construction. A value it cannot resolve to a colour, such as a
`color-mix()`, returns the fallback rather than itself: a caller that asked for a hex and
received `color-mix(…)` has the same unreadable email one step later.

`<meta name="theme-color">` is the softer case — current browsers parse `oklch()`, older
ones ignore the tag — but `hex()` is the safe answer there too.

And for a test that needs a palette of its own: prime it rather than writing the file.
`ThemeTokens::flush()` is public for exactly this, and the real palette lives in the project root
where a test has no business writing.

```php
(new \ReflectionProperty(ThemeTokens::class, 'cache'))
    ->setValue(null, [ThemeTokens::locate() => $themes]);
```


### What this does not do

**Bootstrap's Sass variables are not generated.** `theme:build` reaches Bootstrap through its
CSS custom properties (`--bs-*` and the computed `--bs-*-rgb` triplets above); anything Bootstrap
only exposes as a Sass variable at build time still means Bootstrap's own Sass.

## Writing views that survive a UI switch

`project:switch-ui` changes which CSS framework a project is dressed in. It does not rewrite a
single one of your templates. Whether your screens still look right afterwards depends entirely on
what those templates contain, so it pays to know exactly where the line is.

### What the switch changes, and what it leaves alone

| Changed by `project:switch-ui <system>` | Not touched |
|---|---|
| `scaffold_theme` in `app/app.php` | Every template under `src/Views/` (or `src/Admin/…`): yours, and any you copied with `project:publish-views` |
| CSP `style-src` in `app/app.php` (`'unsafe-inline'` for tailwind, empty otherwise), when the key is already present; when it is not, the output says what to add | `component_classes` in `app/app.php` |
| `app/themes/default/` `theme.html.php`, `head.php`, `header.php`, `footer.php`, `login.php`, **overwritten**; a changed one kept in `var/switch-ui/` | View overrides under `app/themes/<theme>/views/` |
| `www/assets/css/style.css` (in the web root served), **overwritten**; a changed one kept in `var/switch-ui/` | `app/themes/theme.css`, the palette (read, never rewritten) |
| `www/assets/js/pf-*.js`, plus the system's vendored CSS/JS | Any other stylesheet or script of your own |
| `www/assets/css/theme-tokens.css`, regenerated from your palette | |

Two consequences follow:

- **A bundled screen you never copied switches with the project.** View resolution reads
  `scaffold_theme` on every request. When neither the application nor the theme has the template,
  the lookup falls through to `scaffolding/themes/<scaffold_theme>/views/<view>/<tpl>.html.php`,
  so the inherited screens are always in the current system's markup.
- **A screen you own does not switch.** That includes a screen you copied with
  `project:publish-views`. It was copied in the markup of the system that was current when you ran
  the command, and it keeps that markup. To take the new system's copy, run
  `project:publish-views --group=<name> --force`. It overwrites the file, so re-apply your changes
  afterwards. Before you switch, list the groups you published, because every one of them is now a
  screen you maintain.

!!! warning "The theme chrome and `style.css` are scaffold-owned"
    The switch rewrites the five layout files in `app/themes/default/` and the web root's
    `assets/css/style.css`. A file it changed is kept under `var/switch-ui/<time>/` and named in
    the output, so nothing is lost, but your changes are no longer in effect until you re-apply
    them. Put the application's own rules in a stylesheet of your own, such as
    `www/assets/css/app.css`, and load it with `assetUrl()` from a place the switch does not
    write (see [Asset Management](Pramnos_Theme_Guide.md#asset-management)).

    The switch lists the views copied with `project:publish-views` that stay in the old
    system's markup.

### What adapts on its own

These components need nothing from you. A template that uses them renders correctly under all three
systems.

| Component | Why it adapts |
|---|---|
| `Html\Pagination` | Emits the neutral hooks `pf-pagination` and `current`, which every scaffold `style.css` styles |
| `Html\StatTile` | `pf-stat-tile`, `pf-stat-grid`, and BEM children (`__label`, `__value`, `__progress`) |
| `Html\SearchBox` | `pf-omnibox` and its `pf-omnibox-*` children |
| `Html\Icon` and its action links | `pf-icon`, `pf-action`, `pf-action-danger` |
| `Html\Breadcrumb` | The list is `pf-breadcrumb`. Each item carries `breadcrumb-item` and `active`, and all three stylesheets style both |
| `Html\Form\Field` | Styled by a per-system preset. It adapts only when you pass the preset the application declared (below) |
| `Html\Form\SettingsForm` | Defaults to the declared system with no argument |
| `Html\Input`, `Html\Select` | Emit no framework class at all. Style them from your own CSS, or wrap them in a `Field` |

The `pf-*` hooks adapt because the class name stays the same and only the stylesheet behind it
changes. A test asserts that every hook has a rule in every scaffold theme, so the switch swaps in a
stylesheet that already knows them.

`Form\Field::render()` takes the preset as a required argument. Pass the declared one, never a
literal:

```php
use Pramnos\Html\Form\FieldStyles;

echo $field->render(FieldStyles::for(FieldStyles::configured()));   // follows scaffold_theme
echo $field->render(FieldStyles::for('bootstrap'));                 // pinned: will not follow a switch
```

`FieldStyles::configured()` resolves `scaffold_theme` to a preset (`bootstrap`, `tailwind`, or
`plain` for anything else, `plain-css` included). Resolve it once per view, in a variable, if you
render many fields.

### What does not adapt

- **`Html\Datatable` follows the UI system only in part.** `$bootstrap` defaults to on under
  `scaffold_theme = bootstrap` (or none declared) and off under tailwind and plain-css. On, it
  appends `table table-striped table-bordered table-hover` and draws the column picker as a
  Bootstrap dropdown that carries both 3's and 5's attributes. Off, the table keeps DataTables' own
  `display` look and the picker is plain links, with `pf-footsearch` and `pf-action` for the
  filters and row actions. `$jui = true` draws jQuery UI markup instead. A table built while one
  system is declared does not change its markup when another is; set the property to choose.

    ```php
    $dt = new \Pramnos\Html\Datatable('dt-orders');
    $dt->bootstrap = false;   // neutral markup, whatever the system
    ```

- **`Html\Date`.** The input carries Bootstrap's `form-control` by default, which all three
  scaffolded stylesheets style. To change the class, use the `date` key in `component_classes`, but
  read the next item first.
- **`component_classes`.** It is one static map, not one map per system, and the switch does not
  touch it. If you set `'pagination' => 'pagination'` to suit Bootstrap, every system receives
  that class, and the `pf-pagination` rules no longer apply. Use it only when a script needs a
  specific class name, as its own docblock says. For anything visual, style the `pf-*` hook instead.
- **Raw markup in your templates.** The framework cannot see inside it. Every class you write is a
  promise about which stylesheet is loaded.

### What stays tied to a system no matter what

Some markup has no neutral form, because the framework ships no component for it:

- **Layout grids.** `row` / `col-md-6` is Bootstrap, and `grid grid-cols-2 gap-4` is Tailwind.
  plain-css has a small compatibility grid (`.row`, `.col-md-2/3/4/6`). The other `col-*` names it
  covers (`col-md-12`, `col-sm-12`, `col-lg-4`, `col-lg-6`, `col-xl-3`) are always full width, and
  it has no breakpoints. The tailwind system defines no `.row` or `.col-*` at all.
- **Cards, alerts, badges, buttons.** daisyUI shares some of Bootstrap's names (`btn`,
  `btn-primary`, `card`, `card-body`, `table`, `alert`, `badge`), so a Bootstrap template looks
  roughly right under tailwind. Its modifiers differ, though (`alert-danger` against `alert-error`,
  `table-striped` against `table-zebra`), and `card-header`, `form-control` and `form-select` do
  not exist there. plain-css styles a minimal Bootstrap-compatible subset (`card`, `btn-*`,
  `table`, `alert`, `badge`, `dropdown-menu`, `form-control`) so the framework's own Bootstrap-flavoured
  screens stay legible. It is not full Bootstrap.
- **A daisyUI alert is a grid.** Each child of `.alert` gets a column of its own — meant for an
  icon beside its message — so `<strong>Title</strong> text <p>more</p>` is three narrow columns,
  the text breaking word by word. An alert holding text with elements carries `block` (or
  `flex-col`); `TailwindAlertLayoutTest` fails on one that does not.
- **JavaScript behaviour.** Dropdowns, modals and collapses depend on the system's own script and
  its `data-*` attribute names.

!!! note "Close enough is not the same as equivalent"
    That overlap is why a Bootstrap template looks almost right after a switch. It renders, nothing
    errors, and the differences show up as a missing border or an unstyled alert. A test can only
    catch this by rendering the page.

### Recommended approach

1. **Reach for a component first.** Pagination, breadcrumbs, stat tiles, search, icons, action links
   and form fields already adapt. Every one you use is markup you no longer port.
2. **Put your own structure behind your own hooks.** For anything the components do not cover, such
   as a page header, a panel or a two-column layout, give the element one semantic class (`app-panel`,
   `app-split`) and style it in your own stylesheet from the palette tokens
   (`var(--color-primary)`; see [One palette, every UI system](#one-palette-every-ui-system)). The
   template then stays the same across a switch, and you rewrite one stylesheet, not 300 templates.
   This is the same convention the framework follows with `pf-*`.
3. **Pass `FieldStyles::configured()`, never a system name.**
4. **Leave `Datatable::$bootstrap` to follow the system**, or set it to `false` for markup that
   is the same everywhere.
5. **Keep `component_classes` for script-facing names only.**
6. **Own as few bundled screens as you can.** Every bundled screen you inherit, rather than
   publish, switches for free.

### Before and after

A dashboard block written directly against Bootstrap 5:

```php
<div class="row">
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h5 class="card-title">Open orders</h5>
      <p class="display-6"><?php echo $open; ?></p>
    </div></div>
  </div>
</div>
<nav><ul class="pagination">…hand-built page links…</ul></nav>
```

The same block written to survive a switch:

```php
<?php use Pramnos\Html\StatTile; ?>
<div class="app-split">
  <?php echo StatTile::grid([
      StatTile::render('Open orders', number_format($open), link: URL . 'orders'),
  ]); ?>
</div>
<?php echo (new \Pramnos\Html\Pagination($pages, $page, URL . 'orders/page/:page'))->render(); ?>
```

```css
/* www/assets/css/app.css: yours, untouched by project:switch-ui */
.app-split { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr)); }
```

The tile and the pager are dressed by whichever system is active. The only layout rule is in a file
the switch does not write.

### Choosing, for a project with many templates

!!! tip "Decision rule"
    **Does the project expect to change UI system again?**

    - **No, Bootstrap 5 is the destination.** Write Bootstrap 5 markup directly. It is the most
      readable option for a team that knows Bootstrap, and nothing here penalises it. Still use the
      components where they exist, set `Datatable::$bootstrap = false`, and pass
      `FieldStyles::configured()`. Those three cost nothing and remove the known breakages.
    - **Yes, or not sure.** Write components plus your own semantic hooks, and keep framework
      classes out of templates. The cost moves from N templates to one stylesheet per system.
    - **Already have 300 Bootstrap templates and must switch now.** Switch to tailwind before
      plain-css, because daisyUI's shared names carry the most. Then fix the grid first, since it
      is the part that breaks visibly, and convert screens to hooks as you touch them rather than
      in one pass.
