---
date: 2026-08-16
categories:
  - Changelog
  - Documentation
tags:
  - application-styles
  - view
---

# A comparison table is a claim too

The Application Styles guide's opening table said the Services + API + SPA style has
**"View layer: none"**. An application in that style had already added a controller
returning HTML, which the framework has always supported.

<!-- more -->

## What the row was doing

The table is the first thing on the page and the thing most readers take away. It
described two styles, and the middle column's view row said `none`.

That was true of the style as it was first written — a JSON API with a JavaScript front
end genuinely has no server-rendered views. It stopped being true the moment somebody in
that style needed a page a crawler can read, or a form that works without JavaScript,
and added a controller returning HTML. Which is a normal thing to do, has always worked,
and the guide was quietly telling them was not a supported option.

**A comparison table is a claim like any other.** It just does not read like one,
because it looks like a summary of the page rather than an assertion about the
framework.

## The third column

There is now one, and it is deliberately not a third project layout:

| | **Services + API + SPA** | **Services + server-rendered pages** |
| --- | --- | --- |
| Domain layer | `src/Services` | **the same services** |
| View layer | none — a JS SPA consumes the JSON | `src/Views`, fed from services |

It is the second style with server-rendered pages beside the JSON. The services are the
same objects; only what consumes them differs.

## And the thing that had to be said once, plainly

**No model is required for a view.** `View::addModel()` is the only place
`Pramnos\Application\Model` is structurally needed. Skip it and `$this->model` is
`false` in the template — the *no model* case, not an error. `Controller::getModel()`
type-checks nothing at all.

```php
$view = $this->getView('Directory');
$view->stations = (new StationDirectory())->live(20, 0);
return Response::make((string) $view->display('index'));
```

That sentence is on the page now because its absence had a cost: a project reasoned from
"the MVC layer needs models" to "we must convert 66 services", when what the MVC layer
actually needs is a controller, a template, and data of any shape at all.

## Documentation

- [Application Styles guide](../../Pramnos_Application_Styles_Guide.md) — a third
  column, the note about what the second one used to claim, and one paragraph stating
  that a view needs no model. Two new `use_cases:` entries so the page answers the
  questions that led here.
