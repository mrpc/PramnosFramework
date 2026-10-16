---
use_cases:
  - Showing appointments or bookings in a month, week or day calendar
  - Letting an administrator drag or resize an event and saving the change
  - Filtering a calendar's events with a form and refetching them
  - Replacing an old jQuery calendar with one that follows the theme
---

# Calendar Guide

A calendar is FullCalendar 6, the MIT standard bundle, installed from the asset catalog. The
framework's `pf-calendar.js` wires it from markup, so a screen needs no script of its own.

```bash
pramnos project:install fullcalendar        # into www/assets/vendor/fullcalendar/6.1.15/
pramnos project:resync --js --all          # pf-calendar.js
```

```php
<script src="<?php echo assetUrl('assets/vendor/fullcalendar/6.1.15/index.global.min.js'); ?>" defer></script>
<script src="<?php echo assetUrl('assets/vendor/fullcalendar/6.1.15/locales-all.global.min.js'); ?>" defer></script>
<script src="<?php echo assetUrl('assets/js/pf-calendar.js'); ?>" defer></script>

<form id="calendar-filters">
    <select name="staff">…</select>
</form>
<div data-pf-calendar
     data-events="<?php echo adminUrl('Calendar/events'); ?>"
     data-filters="#calendar-filters"
     data-update="<?php echo adminUrl('Calendar/move'); ?>"
     data-editable="1"
     data-view="timeGridWeek"
     data-locale="el"></div>
```

| Attribute | |
|---|---|
| `data-events` | A JSON feed, `[{id, title, start, end, allDay, …}]`, asked with the visible range (`start`, `end`) |
| `data-filters` | A form whose fields go with every request; a change, or sending it, refetches |
| `data-update` | Where a dragged or resized event is POSTed: JSON `{id, start, end, allDay}` with the page's `X-CSRF-Token`. An answer that is not OK puts the event back |
| `data-editable` | `1`: events may be dragged and resized |
| `data-view` | `dayGridMonth` (the default), `timeGridWeek`, `timeGridDay` |
| `data-locale` | The calendar's language; the page's `lang` otherwise |

What a person does is told to the page as events on the element:

```js
calendar.addEventListener('pf:calendar-click', (e) => openBooking(e.detail.event.id));
calendar.addEventListener('pf:calendar-date', (e) => newBooking(e.detail.date, e.detail.allDay));
calendar.addEventListener('pf:calendar-change', (e) => { /* e.detail.event, start, end, allDay, revert() */ });
```

The calendar itself is `element.pfCalendar`, for the page's own calls, for example
`refetchEvents()`. An element added later is set up by `window.pfCalendars()`.

**The theme's colours.** The element gets the class `pf-calendar`, and each theme's
`components.css` points FullCalendar's variables (`--fc-*`) there at the theme's primary colour and
`currentColor`, so the calendar follows light and dark. On a
phone the toolbar wraps.

**The update address answers like any write.** It is a POST with the session's token, so declare
its action with `addWriteAction()`. Answer `200` to keep the change, or an error to put the event
back.
