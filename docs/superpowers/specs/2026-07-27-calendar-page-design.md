# Calendar Page Design

**Date:** 2026-07-27  
**Status:** Approved

---

## Overview

A full Calendar page for Habitat at `/calendar`. Month/Week view toggle, click-to-select day agenda, and a New event modal with rich recurrence support (daily/weekly/monthly/yearly, custom interval, weekday selection). Recurring events are stored as a single master row and expanded in PHP at query time — no materialized instances.

---

## 1. Database

### Migration: `add_recurrence_to_events_table`

Adds four nullable columns to the existing `events` table:

| Column | Type | Notes |
|---|---|---|
| `recurrence_type` | `string` nullable | `'daily'` \| `'weekly'` \| `'monthly'` \| `'yearly'` |
| `recurrence_interval` | `unsignedInteger` default 1 | e.g. `2` = "every 2 weeks" |
| `recurrence_days` | `json` nullable | `["Mon","Wed","Fri"]` — weekly only |
| `recurrence_ends_on` | `date` nullable | Series ends on this date; `null` = forever |

Non-recurring events have all four columns `null`.

### `Event` model changes

- Add `recurrence_days` to `$casts` as `array`
- Add `$fillable` entries for the four new columns
- Add `HasFactory`
- Add static method `expandRecurring(Carbon $rangeStart, Carbon $rangeEnd, int $userId): array` — queries all events for the user, returns a flat array of occurrence objects (virtual rows for recurring, real rows for one-off). Each occurrence includes `id`, `title`, `date` (Y-m-d string for this occurrence), `time`, `tag`, `color`, `bg`, `is_recurring`, `recurrence_type`.
- Add `getBgAttribute(): string` accessor — maps `color` to its design-paired light background using the fixed semantic palette:
  - `#2f7d5b` → `#dff3e6`
  - `#3f7fb0` → `#dcebf5`
  - `#a85fc0` → `#efe0f5`
  - `#d98a2b` → `#f8ecd6`
  - `#c0512c` → `#fbe2d6`
  - `#6b6355` → `#eee9dd` (fallback)

### `EventFactory`

Provides realistic fake data for tests. Includes states: `recurring()` (sets weekly recurrence, Mon/Wed/Fri, interval 1, no end date).

---

## 2. Backend

### `CalendarController`

Single `index` action, registered at `GET /calendar` (named `calendar.index`), replacing the current `Route::inertia('/calendar', 'Stub')` stub.

**Logic:**
1. Read `?month=YYYY-MM` from request; default to current month.
2. Compute `$rangeStart` = Sunday of the week containing the 1st of the month (may be in the prior month).
3. Compute `$rangeEnd` = Saturday of the week containing the last day of the month (may be in the next month).
4. Call `Event::expandRecurring($rangeStart, $rangeEnd, auth()->id())`.
5. Return `Inertia::render('Calendar', ['events' => $occurrences, 'currentMonth' => $month])`.

No pagination — personal calendars have few events; the full expanded set for a ~5-week window is small.

### `EventController`

Three actions: `store`, `update`, `destroy`.

**`store`** — validates then creates:
- `title` required string max 200
- `date` required date
- `time` required string (e.g. `"09:00"`)
- `tag` required in `['Work','Personal','Health','Family','Other']`
- `color` required hex string matching `/^#[0-9a-fA-F]{6}$/`
- `recurrence_type` nullable in `['daily','weekly','monthly','yearly']`
- `recurrence_interval` integer min 1 max 99, default 1
- `recurrence_days` nullable array of weekday strings
- `recurrence_ends_on` nullable date, after `date`

Returns `back()`.

**`update`** — ownership check via `abort_if($event->user_id !== auth()->id(), 403)`, same validation (all fields `sometimes`), updates master (affects whole series). Returns `back()`.

**`destroy`** — ownership check, deletes master (cascade removes all occurrences from the expanded set on next load). Returns `back()`.

### Routes (added to `routes/web.php`)

```php
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
Route::post('/events', [EventController::class, 'store'])->name('events.store');
Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
```

---

## 3. Frontend

### `Calendar.vue` (replaces `Stub.vue` at `/calendar`)

**Props from Inertia:**
```ts
events: Array<{
  id: number
  title: string
  date: string        // 'YYYY-MM-DD' — the specific occurrence date
  time: string        // 'HH:MM'
  tag: string
  color: string
  bg: string
  is_recurring: boolean
  recurrence_type: string | null
}>
currentMonth: string  // 'YYYY-MM'
```

**Local state:**
- `view: 'month' | 'week'` — initialized from `localStorage.getItem('cal-view') ?? 'month'`; saved on change
- `selectedDate: string` — `YYYY-MM-DD`; defaults to today
- `showEventModal: boolean`
- `editingEvent: Event | null` — `null` = new, non-null = edit mode

**Computed:**
- `calendarTitle` — e.g. `"July 2026"` from `currentMonth`
- `gridCells` — array of `{ blank: boolean, day: number, dateStr: string, isToday: boolean, hasEvent: boolean, dotColor: string }` for the month grid (blanks for offset, 1–N for days)
- `agendaEvents` — `props.events` filtered to `date === selectedDate`, sorted by `time`
- `agendaLabel` — e.g. `"TODAY · FRI JUL 17"` or `"SAT JUL 18"` based on `selectedDate` vs today
- `weekColumns` — 7 entries for the week containing `selectedDate`: `{ dow, dateNum, dateStr, isToday, events[] }`

**Month view layout** (`1fr 340px` grid):

Left card — month grid:
- Header: month title + prev/next chevron buttons (trigger `router.get(route('calendar.index'), { month: newMonth })`)
- Weekday header row: S M T W T F S, 11px uppercase muted
- Day cells: `aspect-ratio:1`, flex column center, `border-radius:10px`; today = `--hab-accent` bg + white text; selected (non-today) = `#f1ede3` bg; hover = `#f1ede3`. Click → `selectedDate = cell.dateStr`
- Event dot: 5px circle below the day number in `dotColor` (white on today, `--hab-accent` otherwise)

Right card — agenda panel:
- Header: `agendaLabel` in 16px Bricolage 700, "No events" empty state
- Event rows (separated by `border-top: 1px solid #efe9dc`):
  - 9px color dot (flex-none)
  - Title (14px 600) + time below (12px muted)
  - Tag pill (11.5px 600, color text on bg background, 20px radius)
  - Hover-reveal edit icon → opens modal in edit mode

**Week view layout** (full-width single card):

- Header: week range label (e.g. "Jul 13 – 19") + same prev/next chevrons (shift by 7 days, update `selectedDate`)
- 7-column grid, each column:
  - Day header pill: DOW abbreviation + date number, today = `--hab-accent` bg + white
  - Stacked event chips: `background: e.bg`, `border-left: 3px solid e.color`, `border-radius: 8px`, `padding: 7px 9px`; time in `e.color` 11px 600; title 12.5px 600 `#211d17`
  - Min height 220px, `align-items: start`

**Page header (standard pattern):**
- Left: "Calendar" h1 + "Your month at a glance" subtitle
- Right: Month/Week pill toggle + black "New event" button

**New/Edit event modal (shadcn Dialog):**

Fields in order:
1. **Title** — text input, required
2. **Date** — date input, required, defaults to `selectedDate`
3. **Time** — time input, required
4. **Tag** — chip selector (Work / Personal / Health / Family / Other); selecting a tag auto-sets `selectedColor` via the fixed map:
   - Work → `#3f7fb0`
   - Personal → `#a85fc0`
   - Health → `#2f7d5b`
   - Family → `#d98a2b`
   - Other → `#6b6355`
5. **Recurrence toggle** — off by default; when toggled on, reveals:
   - **Type** — select: Daily / Weekly / Monthly / Yearly
   - **Every** — number input (1–99) + unit label ("days" / "weeks" / "months" / "years" derived from type)
   - **On** (weekly only) — multi-select weekday chip row: Mon Tue Wed Thu Fri Sat Sun
   - **Ends on** — optional date input ("Never" if empty)

Submit → `router.post(route('events.store'), form)` for new; `router.patch(route('events.update', id), form)` for edit. Edit mode shows a red Delete button → `router.delete(route('events.destroy', id))`.

---

## 4. Tests

**`CalendarTest.php`** (new feature test, Pest):
- Guest redirect on `GET /calendar`
- Index returns correct Inertia component and props shape
- Events for the current month are returned
- Recurring event expands correctly within the range
- Recurring event does not appear beyond `recurrence_ends_on`

**`EventTest.php`** (new feature test, Pest):
- Store creates event and returns correct data
- Store creates recurring event with correct recurrence columns
- Validation rejects missing required fields
- Update changes master event (ownership check)
- Cross-user update returns 403
- Destroy deletes event
- Cross-user destroy returns 404/403

---

## Key Decisions

- **Expand at query time** — recurring events stored once, expanded in PHP for the range. No per-occurrence overrides supported; editing always affects the whole series.
- **`bg` derived, not stored** — color → bg mapping is the design's fixed semantic pairs; no extra DB column needed.
- **Client-side day filtering** — all events for the ~5-week range come down with the page load; clicking a day filters in Vue computed. No extra XHR.
- **Week nav via `router.get`** — week view prev/next shifts `selectedDate` by ±7 days and passes `?month=` of the new date so the controller returns the right range.
- **`localStorage` for view persistence** — `'month'` or `'week'` persisted across sessions, no server round-trip.
