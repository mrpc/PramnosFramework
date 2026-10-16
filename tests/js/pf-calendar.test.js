/**
 * `pf-calendar.js`: a calendar from markup, on FullCalendar.
 *
 * What has to hold: a `data-pf-calendar` element becomes a calendar with the view, language and
 * editing its attributes ask for, its events from the feed with the filter form's fields; a change
 * to the filters refetches; a click on an event or a day, and a drag or resize, are told to the
 * page; with `data-update`, a change is POSTed with the page's token and put back when refused;
 * and without FullCalendar nothing happens.
 *
 * Run:
 *   node --test tests/js/pf-calendar.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-calendar.js');

const settle = async () => { for (let i = 0; i < 6; i++) { await new Promise((r) => setImmediate(r)); } };

/** A page with a calendar element, a filter form, a fake FullCalendar and every POST recorded. */
function page({ library = true, update = true, ok = true } = {}) {
    const { window, document } = createDom({ url: 'https://app.test/admin/calendar', meta: { csrf: 't0ken' } });
    const form = document.createElement('form');
    form.setAttribute('id', 'filters');
    const staff = document.createElement('select');
    staff.setAttribute('name', 'staff');
    staff.value = '7';
    form.appendChild(staff);
    const mine = document.createElement('input');
    mine.setAttribute('name', 'mine');
    mine.type = 'checkbox';
    mine.checked = false;
    mine.value = '1';
    form.appendChild(mine);
    document.body.appendChild(form);

    const element = document.createElement('div');
    element.setAttribute('data-pf-calendar', '');
    element.setAttribute('data-events', '/admin/Calendar/events');
    element.setAttribute('data-filters', '#filters');
    element.setAttribute('data-view', 'timeGridWeek');
    element.setAttribute('data-locale', 'el');
    element.setAttribute('data-editable', '1');
    if (update) { element.setAttribute('data-update', '/admin/Calendar/move'); }
    document.body.appendChild(element);
    document.querySelector = (selector) => (selector === '#filters' ? form : selector === 'meta[name="csrf"]' ? { content: 't0ken' } : null);

    const calendars = [];
    if (library) {
        window.FullCalendar = { Calendar: class {
            constructor(el, options) { this.el = el; this.options = options; this.rendered = false; this.refetched = 0; calendars.push(this); }
            render() { this.rendered = true; }
            refetchEvents() { this.refetched++; }
        } };
    }
    const posted = [];
    window.fetch = (url, init) => { posted.push({ url, init }); return Promise.resolve({ ok }); };
    const told = [];
    for (const name of ['pf:calendar-click', 'pf:calendar-date', 'pf:calendar-change']) {
        element.addEventListener(name, (event) => told.push([name, event.detail]));
    }

    loadScript(SOURCE, window);

    return { window, element, form, staff, mine, calendars, posted, told };
}

test('the element becomes a calendar as its attributes ask, with the filters on its feed', () => {
    // Arrange
    const { calendars, element, mine } = page();

    // Act
    const options = calendars[0].options;
    const params = options.events.extraParams();
    mine.checked = true;
    const withMine = options.events.extraParams();

    // Assert
    assert.equal(calendars.length, 1);
    assert.equal(calendars[0].rendered, true);
    assert.equal(element.pfCalendar, calendars[0]);
    assert.equal(element.classList.contains('pf-calendar'), true, 'the themes\' hook');
    assert.equal(options.initialView, 'timeGridWeek');
    assert.equal(options.locale, 'el');
    assert.equal(options.editable, true);
    assert.equal(options.events.url, '/admin/Calendar/events');
    assert.equal(options.headerToolbar.right, 'dayGridMonth,timeGridWeek,timeGridDay');
    assert.deepEqual(JSON.parse(JSON.stringify(params)), { staff: '7' }, 'an unchecked box is not a value');
    assert.deepEqual(JSON.parse(JSON.stringify(withMine)), { staff: '7', mine: '1' });
});

test('a change to the filters, or their form sent, refetches', () => {
    // Arrange
    const { calendars, form } = page();

    // Act
    form._fire(new Event('change'));
    form._fire(Object.assign(new Event('submit'), { preventDefault() { this.stopped = true; } }));

    // Assert
    assert.equal(calendars[0].refetched, 2);
});

test('clicks are told to the page', () => {
    // Arrange
    const { calendars, told } = page();
    let prevented = false;

    // Act
    calendars[0].options.eventClick({ event: { id: '4' }, jsEvent: { preventDefault() { prevented = true; } } });
    calendars[0].options.dateClick({ date: '2026-10-16', allDay: true });

    // Assert
    assert.equal(prevented, true, 'an event with a url does not navigate away');
    assert.deepEqual(told.map(([name]) => name), ['pf:calendar-click', 'pf:calendar-date']);
    assert.equal(told[0][1].event.id, '4');
    assert.equal(told[1][1].allDay, true);
});

test('a drag is told and POSTed with the token; a refusal puts it back', async () => {
    // Arrange
    const refused = page({ ok: false });
    const accepted = page();
    const info = (revert) => ({ event: { id: '9', start: new Date('2026-10-16T09:00:00Z'), end: null, allDay: false }, revert });
    let backRefused = 0;
    let backAccepted = 0;

    // Act
    refused.calendars[0].options.eventDrop(info(() => { backRefused++; }));
    accepted.calendars[0].options.eventResize(info(() => { backAccepted++; }));
    await settle();

    // Assert
    assert.equal(refused.posted[0].url, '/admin/Calendar/move');
    assert.equal(refused.posted[0].init.headers['X-CSRF-Token'], 't0ken');
    assert.deepEqual(JSON.parse(refused.posted[0].init.body), { id: '9', start: '2026-10-16T09:00:00.000Z', end: null, allDay: false });
    assert.equal(backRefused, 1, 'refused: put back');
    assert.equal(backAccepted, 0);
    assert.equal(refused.told[0][0], 'pf:calendar-change');
});

test('without data-update a change is only told, and without FullCalendar nothing happens', async () => {
    // Arrange
    const told = page({ update: false });
    const none = page({ library: false });

    // Act
    told.calendars[0].options.eventDrop({ event: { id: '1', start: null, end: null, allDay: true }, revert() {} });
    await settle();

    // Assert
    assert.deepEqual(told.posted, []);
    assert.equal(told.told.length, 1);
    assert.equal(none.element.pfCalendar, undefined);
});
