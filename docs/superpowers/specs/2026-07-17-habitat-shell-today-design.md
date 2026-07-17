# Habitat — Shell + Today Page Design Spec
**Date:** 2026-07-17
**Sprint:** 1 of N (full app)
**Scope:** Global shell (themed sidebar + header) + Today page, with seeded mock data

---

## 1. Context

Habitat is a personal "Life OS" web app with 18 pages across three sections (Day / Life / Money). This is Sprint 1: build the foundation every subsequent page depends on — the global shell — and deliver the first real page content (Today). Remaining 17 pages are stub routes that prove navigation works but show no content.

The design source is `/Users/elle/Downloads/design_handoff_habitat_life_app/Budget Dashboard.dc.html`. Read it for exact markup, inline styles, and data shapes; do not port its custom runtime (`<x-dc>`, `<sc-for>`, `<sc-if>`).

---

## 2. Tech Stack

| Layer | Choice |
|---|---|
| Backend | PHP 8.4 + Laravel 13 |
| Frontend | Vue 3 (`<script setup>`) + Inertia.js v3 |
| Styling | Tailwind CSS v4 + CSS custom properties for theme tokens |
| DB | SQLite (`database/database.sqlite`) |
| Icons | Material Symbols Rounded — loaded via Google Fonts CDN link in `resources/views/app.blade.php` |
| Fonts | Bricolage Grotesque + Instrument Sans — Google Fonts CDN |
| Component lib | shadcn-vue (already installed) |
| Local dev | Laravel Herd at `https://habitat.test` |

---

## 3. Design Tokens

### Colors (warm neutral base — applied globally)

```css
--app-bg:       #efece4;
--card-bg:      #fffdf8;
--card-border:  #e0dacd;
--hairline:     #efe9dc;
--ink:          #211d17;
--ink-secondary:#4a4436;
--muted:        #8a8172;
--faint:        #a49b8a;
--chip-fill:    #f1ede3;
--btn-dark-bg:  #211d17;
--btn-dark-ink: #fdfbf5;
```

### Theme Palettes (5 options, `data-theme` on `<body>`)

| key | --sidebar-bg | --brand | --brand-ink | --accent | --accent-deep |
|---|---|---|---|---|---|
| forest | `#1c2b23` | `#5fce97` | `#12241a` | `#2f7d5b` | `#276448` |
| plum | `#241d2e` | `#c39bf0` | `#231539` | `#7d4bd0` | `#5e37a3` |
| **ocean** | `#142731` | `#67c7e0` | `#0e2630` | `#2f83aa` | `#28688a` |
| clay | `#2c211b` | `#f0a878` | `#33200f` | `#bd6537` | `#a5562d` |
| slate | `#22262e` | `#9db2d6` | `#161d29` | `#4d5f86` | `#3f4f70` |

Gradient stops per theme (for hero cards):

| key | stop-a | stop-b | stop-c |
|---|---|---|---|
| forest | `#2f7d5b` | `#276448` | `#1f5138` |
| plum | `#7d4bd0` | `#653aad` | `#4a2a82` |
| ocean | `#2f83aa` | `#286b8c` | `#1e4f6b` |
| clay | `#c26a3c` | `#a5562d` | `#823f1f` |
| slate | `#4d5f86` | `#3f4f70` | `#2f3c57` |

Hero gradient formula: `radial-gradient(120% 140% at 100% 0%, {a} 0%, {b} 55%, {c} 100%)`

### Typography
- **Display / numbers:** `Bricolage Grotesque`, weights 500–800
- **Body / UI:** `Instrument Sans`, weights 400/500/600

### Spacing & Radius
- Page padding: `24px 30px 46px`; max-width `1180px`; gap `20px`
- Card radius: `22–24px`; inner tiles: `16–20px`; icon tiles: `12px`; chips: `20px` (full)
- Card shadow: `0 8px 24px -20px rgba(60,48,20,.5)`
- Hero shadow: `0 24px 40px -26px rgba(20,20,20,.7)`
- Progress bars: track `#efe9dc`, height `9–14px`, radius `20px`, fill transition `width .5s cubic-bezier(.2,.8,.2,1)`; spend bar fill: `linear-gradient(90deg,#ffd27a,#ffe9a8)`

---

## 4. Global Shell

### 4a. Sidebar

**File:** `resources/js/components/AppSidebar.vue` (replace current)

- Width: `248px`, fixed full-height
- Background: `var(--sidebar-bg)`; text `#dfe7e1`

**Brand row:**
- Rounded-square mark (28px, radius 8px, bg `var(--brand)`, `cottage` Material glyph in `var(--brand-ink)`)
- Wordmark "Habitat" in Bricolage Grotesque 700, white
- User first-name pill (e.g. "Elle") — small chip, `rgba(255,255,255,.15)` bg

**Nav groups** (3 sections, uppercase group labels `#8a978f`):

| Section | Icon | Label | Notes |
|---|---|---|---|
| DAY | `wb_sunny` | Today | |
| DAY | `calendar_month` | Calendar | |
| DAY | `check_circle` | Tasks | Badge: count of undone today tasks |
| LIFE | `favorite` | Health | |
| LIFE | `loop` | Habits | |
| LIFE | `sticky_note_2` | Notes | |
| LIFE | `auto_stories` | Journal | |
| LIFE | `restaurant` | Meals | |
| LIFE | `shopping_cart` | Groceries | |
| LIFE | `pets` | Pets | |
| MONEY | `flag` | Goals | |
| MONEY | `account_balance` | Accounts | |
| MONEY | `account_balance_wallet` | Budgets | |
| MONEY | `savings` | Set-asides | |
| MONEY | `credit_card` | Debt | |
| MONEY | `photo_library` | Photos | |

Active item: `background: var(--brand)`, text + icon `var(--brand-ink)`, font-weight 600.
Inactive: text `#c3d1c9`, icon `#94a89d`.

**User tile (bottom):**
- Avatar initial circle (32px, `var(--brand)` bg, `var(--brand-ink)` text)
- Name (e.g. "Elle Domingo") + "Free plan"
- Chevron right; clicking opens existing `UserMenuContent` dropdown

### 4b. Header

**File:** `resources/js/components/AppHeader.vue` (replace current)

- Sticky; `backdrop-blur-md`; background `rgba(239,236,228,.86)`; border-bottom `1px solid #e0dacd`
- Height: `56px`

**Left:** Breadcrumb — section icon (Material Symbol) + "Section / Page"

Section icon map:
- Day → `wb_sunny`
- Life → `interests`
- Money → `account_balance_wallet`

**Center:** Search input (250px wide, `#` border `#e0dacd`, radius `11px`, placeholder "Search anything in your life…", `⌘K` badge chip at right) — no handler, UI only.

**Right:** Notification bell (`notifications` icon, red dot `8px` when `notificationCount > 0`) + dark **"+ Log spending"** button (`background: #211d17`, text `#fdfbf5`, radius `11px`) — no handler, UI only.

### 4c. Layout Wiring

**File:** `resources/js/layouts/app/AppSidebarLayout.vue`

- Reads `$page.props.auth.user.theme` (shared via `HandleInertiaRequests`)
- Applies `data-theme="{theme}"` to the root layout element
- Default theme: `ocean`

**File:** `app/Http/Middleware/HandleInertiaRequests.php`

Add to shared props:
```php
'auth' => [
    'user' => $request->user() ? [
        ...existing fields...,
        'theme' => $request->user()->setting?->theme ?? 'ocean',
    ] : null,
],
```

---

## 5. Routes

**File:** `routes/web.php`

```
GET /          → Welcome (existing)
GET /today     → Today (real content)        name: today
GET /calendar  → Stub                        name: calendar
GET /tasks     → Stub                        name: tasks
GET /health    → Stub                        name: health
GET /habits    → Stub                        name: habits
GET /notes     → Stub                        name: notes
GET /journal   → Stub                        name: journal
GET /meals     → Stub                        name: meals
GET /groceries → Stub                        name: groceries
GET /pets      → Stub                        name: pets
GET /goals     → Stub                        name: goals
GET /accounts  → Stub                        name: accounts
GET /budgets   → Stub                        name: budgets
GET /set-asides→ Stub                        name: set-asides
GET /debt      → Stub                        name: debt
GET /photos    → Stub                        name: photos
GET /dashboard → HTTP 302 redirect to /today  (keep old name for any existing links)
```

All routes inside `auth` + `verified` middleware group. Stub pages use `Inertia::render('Stub')`.

---

## 6. Database

### New migrations (run in order)

**`settings`**
```
id, user_id (FK users), theme (string, default 'ocean'), timestamps
```

**`events`**
```
id, user_id, title, date, time, tag (string), color (string), timestamps
```

**`tasks`**
```
id, user_id, label, group (enum: today|week|someday), done (bool, default false), timestamps
```

**`health_metrics`**
```
id, user_id, name, value (string), sub (string), pct (int), color (string), icon (string), timestamps
```

**`health_week`**
```
id, user_id, day (string), steps (int), timestamps
```

**`habits`**
```
id, user_id, name, icon (string), color (string), timestamps
```

**`habit_logs`**
```
id, habit_id, date (date), done (bool), timestamps
```

**`accounts`**
```
id, user_id, name, institution (string), last4 (string), balance (decimal 10,2), type (enum: cash|credit|investment), timestamps
```

**`budget_categories`**
```
id, user_id, name, icon, color, limit_amount (decimal 10,2), timestamps
```

**`budget_months`**
```
id, user_id, year (int), month (int), budget (decimal 10,2), base_spent (decimal 10,2), timestamps
```

### Models

- `Setting` (belongsTo User)
- `Event`, `Task`, `HealthMetric`, `HealthWeek`, `Habit`, `HabitLog`, `Account`, `BudgetCategory`, `BudgetMonth` (all belongsTo User)
- `User` hasOne `Setting`; hasMany of the rest

### Seeder

`DatabaseSeeder` creates one user:
- Name: "Elle Domingo", email: `elle@example.com`, password: `password`
- Setting: theme `ocean`
- 3 events for today (July 17): Team standup 11pm/Work, Lunch with Mia 12:30pm/Personal, Yoga class 6pm/Health
- 4 tasks group=today (3 undone, 1 done): Reply to landlord, Book dentist, Pay electric bill, Grocery run ✓
- 3 tasks group=week, 2 tasks group=someday
- 4 health metrics: Steps (7420/10000, green), Sleep (7h10m/8h, blue), Active energy (480/600 cal, amber), Resting HR (58 bpm, no bar)
- 7 health_week rows (Mon–Sun steps)
- 4 habits: Drink water (blue), Read 20 min (purple), Move 30 min (amber), Meditate (green)
- habit_logs: 4–5 days of completions per habit (to render realistic rings)
- 3 accounts: Checking ₱2480/cash, Savings ₱8120/cash, Credit card -₱612/credit
- 1 budget_month: July 2026, budget ₱3200, base_spent ₱1970

---

## 7. Today Page

**File:** `resources/js/pages/Today.vue`
**Controller:** `app/Http/Controllers/TodayController.php`

### Props (from controller)

```ts
{
  user: { name: string, firstName: string, theme: string },
  todayEvents: { id, title, time, tag, color }[],
  todayTasks: { id, label, group, done }[],
  // safeToSpend = budget_months.budget - budget_months.base_spent for current month
  moneySummary: { safeToSpend: number, budget: number, spent: number },
  // pct = days done this week / 7 * 100; streak = consecutive days done up to today
  // Today page shows max 4 habits (all of them for this sprint)
  habits: { id, name, icon, color, pct: number, streak: number }[],
  // Today Health card shows only 'Steps' and 'Sleep' (the two with progress bars)
  // Controller filters: HealthMetric::where('name', ['Steps', 'Sleep'])
  healthMetrics: { name, value, sub, pct, color, icon }[],
}
```

### Layout

```
[Hero — full width]
[Left: 1fr          ] [Right: 340px]
  Today's schedule      Money
  Tasks                 Habits
                        Health
                        On this day
```

### Hero Card

- Full-width, gradient bg (`radial-gradient` from theme stops), radius `22px`, shadow
- `wb_sunny` icon (white, 32px) + "Good morning, {firstName}" in Bricolage Grotesque 800, white (`firstName` = `explode(' ', $user->name)[0]`, passed as prop)
- Subtitle: "Friday, July 17 — {n} events, {m} tasks to go, and you're comfortably under budget."
- Subtitle text is dynamic: event count from `todayEvents.length`, undone tasks from `todayTasks.filter(t => !t.done).length`, budget mood from `moneySummary`

### Today's Schedule Card

- Card bg `#fffdf8`, border `1px solid #e0dacd`, radius `22px`
- Header: "Today's schedule" (Bricolage 700, 18px) + `<Link href="/calendar">Calendar →</Link>` in accent color
- Rows: `time` (muted, mono-spaced width) + colored dot (the event's `color`) + `title` + tag pill (chip, matching color family)
- Hairline dividers between rows

### Tasks Card

- Same card style
- Header: "Tasks" + `{undoneCount} left` badge
- Rows: `<Checkbox>` (shadcn) + label; when checked → PATCH `/tasks/{id}` with `{ done: true }` via `router.patch()`, label gets `line-through` class; count decrements
- Checked box fill: `var(--accent)`

### Money Snapshot Card (right column)

- Header: "Money" + chevron right (links to `/budgets`)
- "Safe to spend this month" in muted text
- Big peso amount: `₱{safeToSpend}` in Bricolage Grotesque 800, 32px
- Progress bar: spent/budget ratio, amber fill (`linear-gradient(90deg,#ffd27a,#ffe9a8)`), track `#efe9dc`
- Entire card is a `<Link href="/budgets">` wrapper

### Habits Card (right column)

- Header: "Habits"
- 2–3 rows: conic-gradient circle (40px, shows `pct`% fill in habit color) + habit name + streak pill + `{pct}%` label in habit color
- Conic gradient: `conic-gradient({color} {pct}%, #efe9dc {pct}%)`

### Health Card (right column)

- Header: "Health"
- 2 rows (Steps + Sleep only — the two with bars):
  - Icon tile (40px, radius 12px, tinted bg) + name + value + sub + progress bar

### On This Day Card (right column)

- Header: "On this day"
- Image placeholder (full-width within card, aspect-ratio 16/9, bg `#e8e3d8`, radius `16px`) with a neutral icon overlay
- Caption: "3 years ago · Batangas trip" in muted text

---

## 8. Interactions

| Interaction | Mechanism |
|---|---|
| Task checkbox toggle | `router.patch('/tasks/{id}', { done: !task.done })` — optimistic UI update, revert on error |
| Nav active state | `useCurrentUrl().isCurrentUrl(item.href)` (existing composable) |
| Theme applied | `data-theme` attribute read from Inertia shared props on layout mount |
| "Log spending" button | No handler — UI only (Sprint 3: Budgets) |
| Search box | No handler — UI only |
| Notification bell | No handler — UI only |

---

## 9. Testing

- Feature test: `TodayPageTest` — asserts Today route returns 200, correct Inertia component, and all required prop keys are present
- Feature test: `TaskToggleTest` — asserts PATCH `/tasks/{id}` flips `done`, returns 200
- No UI interaction tests for this sprint

---

## 10. Out of Scope (Sprint 1)

- Log spending modal
- Palette switcher UI (theme set via seeder only)
- Any CRUD forms (Add task, Add event, etc.)
- Real image uploads for "On this day"
- Calendar, Tasks page content (stub only)
- Any page other than Today
