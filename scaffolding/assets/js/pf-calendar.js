/*
 * A calendar from markup, on FullCalendar (`libraries:sync fullcalendar`): month, week and day
 * views, events from a JSON feed, and dragging and resizing — with no script of the page's own.
 *
 *   <form id="calendar-filters">… <select name="staff">…</select> …</form>
 *   <div data-pf-calendar
 *        data-events="/admin/Calendar/events"        a JSON feed: [{id, title, start, end, allDay, …}]
 *        data-filters="#calendar-filters"            its fields go with every request, and a change refetches
 *        data-update="/admin/Calendar/move"          optional: a dragged or resized event is POSTed here
 *        data-editable="1"                           optional: events may be dragged and resized
 *        data-view="timeGridWeek"                    optional: dayGridMonth (default), timeGridWeek, timeGridDay
 *        data-locale="el"></div>                     optional: the page's language
 *
 * What a person does is told to the page as events on the element, for it to answer:
 *   pf:calendar-click   {event}                       an event was clicked
 *   pf:calendar-date    {date, allDay}                an empty day or slot was clicked
 *   pf:calendar-change  {event, start, end, allDay, revert}   an event was dragged or resized
 * With `data-update`, a change is POSTed as JSON {id, start, end, allDay} with the page's
 * X-CSRF-Token; an answer that is not OK puts the event back where it was. The calendar is on
 * the element as `element.pfCalendar`, for the page's own calls.
 */
(function () {
    'use strict';

    function token() {
        var meta = document.querySelector('meta[name="csrf"]');

        return meta ? (meta.content || meta.getAttribute('content') || '') : '';
    }

    /** The filter form's fields, as the feed's parameters. */
    function filterValues(form) {
        var values = {};
        if (!form) {
            return values;
        }
        Array.prototype.forEach.call(form.querySelectorAll('[name]'), function (field) {
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }
            values[field.getAttribute('name')] = field.value;
        });

        return values;
    }

    function tell(element, name, detail) {
        element.dispatchEvent(new CustomEvent(name, { detail: detail, bubbles: true }));
    }

    function setUp(element) {
        if (element.pfCalendar || !window.FullCalendar) {
            return;
        }
        // The hook the themes' components.css colours the calendar by.
        element.classList.add('pf-calendar');
        var filters = element.getAttribute('data-filters');
        var form = filters ? document.querySelector(filters) : null;
        var update = element.getAttribute('data-update');

        function changed(info) {
            var detail = {
                event: info.event,
                start: info.event.start,
                end: info.event.end,
                allDay: info.event.allDay,
                revert: info.revert
            };
            tell(element, 'pf:calendar-change', detail);
            if (!update) {
                return;
            }
            window.fetch(update, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token() },
                body: JSON.stringify({
                    id: info.event.id,
                    start: info.event.start ? info.event.start.toISOString() : null,
                    end: info.event.end ? info.event.end.toISOString() : null,
                    allDay: info.event.allDay
                })
            }).then(function (response) {
                if (!response.ok) {
                    info.revert();
                }
            }).catch(function () {
                info.revert();
            });
        }

        var calendar = new window.FullCalendar.Calendar(element, {
            initialView: element.getAttribute('data-view') || 'dayGridMonth',
            locale: element.getAttribute('data-locale') || document.documentElement.getAttribute('lang') || 'en',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
            editable: element.getAttribute('data-editable') === '1',
            height: 'auto',
            events: {
                url: element.getAttribute('data-events'),
                extraParams: function () { return filterValues(form); }
            },
            eventClick: function (info) {
                if (info.jsEvent && info.jsEvent.preventDefault) {
                    info.jsEvent.preventDefault();
                }
                tell(element, 'pf:calendar-click', { event: info.event });
            },
            dateClick: function (info) {
                tell(element, 'pf:calendar-date', { date: info.date, allDay: info.allDay });
            },
            eventDrop: changed,
            eventResize: changed
        });
        calendar.render();
        element.pfCalendar = calendar;

        if (form) {
            form.addEventListener('change', function () { calendar.refetchEvents(); });
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                calendar.refetchEvents();
            });
        }
    }

    function all() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-pf-calendar]'), setUp);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', all);
    } else {
        all();
    }
    window.pfCalendars = all;
}());
