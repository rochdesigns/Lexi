# Calendar Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the full Calendar page at `/calendar` with month/week views, click-to-select day agenda, recurring event support, and a New/Edit event modal.

**Architecture:** Recurring events are stored as a single master row with recurrence rule columns; `Event::expandRecurring()` expands them in PHP for a date range at query time. The controller loads the full month grid range once; clicking a day filters events client-side in Vue computed properties.

**Tech Stack:** PHP 8.4, Laravel 13, Inertia v3, Vue 3 `<script setup lang="ts">`, Tailwind v4, shadcn-vue (Dialog), Pest v4, Wayfinder for typed route helpers.

## Global Constraints

- Icons: Material Symbols Rounded — ligature strings in `<span class="material-symbols-rounded">`, outlined = `msr` class, filled = `msf` class
- Fonts: Bricolage Grotesque (display/headings), Instrument Sans (body/UI)
- All CSS accent colour: `var(--hab-accent)` (never hard-code theme colours)
- Card style: `background:#fffdf8; border:1px solid #e0dacd; border-radius:24px; box-shadow:0 8px 24px -20px rgba(60,48,20,.5)`
- Philippine Peso ₱ for money; no currency on this page
- Return `back()` from all mutating controller actions (never JSON)
- Ownership check: `abort_if($event->user_id !== $request->user()->id, 403)`
- Run `vendor/bin/pint --dirty --format agent` after every PHP change
- Run tests with `php artisan test --compact`
- Commit after every task

---

## File Map

| Action | File |
|---|---|
| Create | `database/migrations/XXXX_add_recurrence_to_events_table.php` |
| Modify | `app/Models/Event.php` |
| Create | `database/factories/EventFactory.php` |
| Create | `app/Http/Controllers/CalendarController.php` |
| Create | `app/Http/Controllers/EventController.php` |
| Modify | `routes/web.php` |
| Create | `tests/Feature/CalendarTest.php` |
| Create | `tests/Feature/EventTest.php` |
| Create | `resources/js/pages/Calendar.vue` |
| Auto-gen | `resources/js/actions/App/Http/Controllers/CalendarController.ts` (Wayfinder) |
| Auto-gen | `resources/js/actions/App/Http/Controllers/EventController.ts` (Wayfinder) |

---

## Task 1: Migration + Event Model

**Files:**
- Create: `database/migrations/XXXX_add_recurrence_to_events_table.php`
- Modify: `app/Models/Event.php`

**Interfaces:**
- Produces: `Event::expandRecurring(Carbon $rangeStart, Carbon $rangeEnd, int $userId): array` — returns array of occurrence arrays; `Event->bg` accessor returning hex string

- [ ] **Step 1: Generate the migration**

```bash
php artisan make:migration add_recurrence_to_events_table --no-interaction
```

- [ ] **Step 2: Write the migration**

Open the generated file and replace its `up()` and `down()` with:

```php
public function up(): void
{
    Schema::table('events', function (Blueprint $table): void {
        $table->string('recurrence_type')->nullable()->after('color');
        $table->unsignedInteger('recurrence_interval')->default(1)->after('recurrence_type');
        $table->json('recurrence_days')->nullable()->after('recurrence_interval');
        $table->date('recurrence_ends_on')->nullable()->after('recurrence_days');
    });
}

public function down(): void
{
    Schema::table('events', function (Blueprint $table): void {
        $table->dropColumn(['recurrence_type', 'recurrence_interval', 'recurrence_days', 'recurrence_ends_on']);
    });
}
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate --no-interaction
```

Expected: no errors, migration listed as ran.

- [ ] **Step 4: Update `app/Models/Event.php`**

Replace the entire file:

```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'date', 'time', 'tag', 'color',
        'recurrence_type', 'recurrence_interval', 'recurrence_days', 'recurrence_ends_on',
    ];

    protected $casts = [
        'recurrence_days' => 'array',
        'date' => 'date',
        'recurrence_ends_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getBgAttribute(): string
    {
        return match ($this->color) {
            '#2f7d5b' => '#dff3e6',
            '#3f7fb0' => '#dcebf5',
            '#a85fc0' => '#efe0f5',
            '#d98a2b' => '#f8ecd6',
            '#c0512c' => '#fbe2d6',
            default   => '#eee9dd',
        };
    }

    public static function expandRecurring(Carbon $rangeStart, Carbon $rangeEnd, int $userId): array
    {
        $events = static::where('user_id', $userId)
            ->where(function ($q) use ($rangeStart, $rangeEnd): void {
                $q->whereNull('recurrence_type')
                    ->whereBetween('date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
                    ->orWhere(function ($q2) use ($rangeStart, $rangeEnd): void {
                        $q2->whereNotNull('recurrence_type')
                            ->where('date', '<=', $rangeEnd->toDateString())
                            ->where(function ($q3) use ($rangeStart): void {
                                $q3->whereNull('recurrence_ends_on')
                                    ->orWhere('recurrence_ends_on', '>=', $rangeStart->toDateString());
                            });
                    });
            })
            ->get();

        $occurrences = [];

        foreach ($events as $event) {
            if ($event->recurrence_type === null) {
                $occurrences[] = static::toOccurrence($event, $event->date->toDateString());
            } else {
                $occurrences = array_merge($occurrences, static::expandEvent($event, $rangeStart, $rangeEnd));
            }
        }

        usort($occurrences, fn ($a, $b) => strcmp($a['date'].$a['time'], $b['date'].$b['time']));

        return $occurrences;
    }

    private static function expandEvent(self $event, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $occurrences = [];
        $endsOn = $event->recurrence_ends_on;
        $effectiveEnd = $endsOn && $endsOn->lt($rangeEnd) ? $endsOn : $rangeEnd;
        $eventDate = $event->date->copy();

        match ($event->recurrence_type) {
            'daily' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addDays($event->recurrence_interval);
                }
            })(),
            'weekly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
                if (! empty($event->recurrence_days)) {
                    $weekStart = $eventDate->copy()->startOfWeek(Carbon::SUNDAY);
                    while ($weekStart->lte($effectiveEnd)) {
                        foreach ($event->recurrence_days as $dow) {
                            $offset = $dayMap[$dow] ?? null;
                            if ($offset === null) {
                                continue;
                            }
                            $occDate = $weekStart->copy()->addDays($offset);
                            if ($occDate->gte($eventDate) && $occDate->gte($rangeStart) && $occDate->lte($effectiveEnd)) {
                                $occurrences[] = static::toOccurrence($event, $occDate->toDateString());
                            }
                        }
                        $weekStart->addWeeks($event->recurrence_interval);
                    }
                } else {
                    $cursor = $eventDate->copy();
                    while ($cursor->lte($effectiveEnd)) {
                        if ($cursor->gte($rangeStart)) {
                            $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                        }
                        $cursor->addWeeks($event->recurrence_interval);
                    }
                }
            })(),
            'monthly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addMonths($event->recurrence_interval);
                }
            })(),
            'yearly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addYears($event->recurrence_interval);
                }
            })(),
            default => null,
        };

        return $occurrences;
    }

    private static function toOccurrence(self $event, string $date): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'date' => $date,
            'time' => $event->time,
            'tag' => $event->tag,
            'color' => $event->color,
            'bg' => $event->bg,
            'is_recurring' => $event->recurrence_type !== null,
            'recurrence_type' => $event->recurrence_type,
        ];
    }
}
```

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/Event.php
git commit -m "feat: add recurrence columns to events + expand logic on Event model"
```

---

## Task 2: EventFactory

**Files:**
- Create: `database/factories/EventFactory.php`

**Interfaces:**
- Produces: `EventFactory` with `recurring()` state — used in Tasks 3 and 4 tests

- [ ] **Step 1: Generate the factory**

```bash
php artisan make:factory EventFactory --model=Event --no-interaction
```

- [ ] **Step 2: Write the factory**

Replace the generated file contents:

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    public function definition(): array
    {
        $colors = ['#2f7d5b', '#3f7fb0', '#a85fc0', '#d98a2b', '#c0512c', '#6b6355'];
        $tags = ['Work', 'Personal', 'Health', 'Family', 'Other'];

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'date' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'time' => fake()->time('H:i'),
            'tag' => fake()->randomElement($tags),
            'color' => fake()->randomElement($colors),
            'recurrence_type' => null,
            'recurrence_interval' => 1,
            'recurrence_days' => null,
            'recurrence_ends_on' => null,
        ];
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_days' => ['Mon', 'Wed', 'Fri'],
            'recurrence_ends_on' => null,
        ]);
    }
}
```

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Commit**

```bash
git add database/factories/EventFactory.php
git commit -m "feat: add EventFactory with recurring() state"
```

---

## Task 3: CalendarController, CalendarTest, and Routes

**Files:**
- Create: `app/Http/Controllers/CalendarController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/CalendarTest.php`

**Interfaces:**
- Consumes: `Event::expandRecurring(Carbon, Carbon, int): array` from Task 1
- Produces: `GET /calendar` named `calendar.index` returning Inertia `Calendar` page with `events` and `currentMonth` props

- [ ] **Step 1: Create the test file**

```bash
php artisan make:test --pest CalendarTest --no-interaction
```

- [ ] **Step 2: Write the failing tests**

Replace `tests/Feature/CalendarTest.php`:

```php
<?php

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('guest is redirected to login', function (): void {
    $this->get('/calendar')->assertRedirect('/login');
});

test('calendar page renders with correct component and props', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->get('/calendar')
        ->assertInertia(fn ($page) => $page
            ->component('Calendar')
            ->has('events')
            ->has('currentMonth')
        );
});

test('calendar returns events for the current month range', function (): void {
    $user = User::factory()->create();
    $today = Carbon::today();

    Event::factory()->create([
        'user_id' => $user->id,
        'date' => $today->toDateString(),
        'title' => 'My Event',
    ]);

    $this->actingAs($user)
        ->get('/calendar?month='.$today->format('Y-m'))
        ->assertInertia(fn ($page) => $page
            ->component('Calendar')
            ->where('currentMonth', $today->format('Y-m'))
            ->has('events', 1)
            ->where('events.0.title', 'My Event')
        );
});

test('calendar does not return another users events', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Event::factory()->create(['user_id' => $other->id, 'date' => Carbon::today()->toDateString()]);

    $this->actingAs($user)
        ->get('/calendar?month='.Carbon::today()->format('Y-m'))
        ->assertInertia(fn ($page) => $page->has('events', 0));
});

test('recurring event is expanded within the range', function (): void {
    $user = User::factory()->create();

    // Weekly Mon/Wed/Fri event starting on a Monday
    $monday = Carbon::parse('next Monday');
    Event::factory()->recurring()->create([
        'user_id' => $user->id,
        'date' => $monday->toDateString(),
        'title' => 'Standup',
    ]);

    $response = $this->actingAs($user)
        ->get('/calendar?month='.$monday->format('Y-m'))
        ->assertInertia(fn ($page) => $page->component('Calendar'));

    $events = $response->original->getData()['page']['props']['events'];

    // Within one month, a Mon/Wed/Fri weekly pattern should produce multiple occurrences
    expect(count(array_filter($events, fn ($e) => $e['title'] === 'Standup')))->toBeGreaterThan(1);
});

test('recurring event does not appear beyond recurrence_ends_on', function (): void {
    $user = User::factory()->create();

    $startOfMonth = Carbon::now()->startOfMonth();
    Event::factory()->create([
        'user_id' => $user->id,
        'date' => $startOfMonth->toDateString(),
        'title' => 'Ends soon',
        'recurrence_type' => 'daily',
        'recurrence_interval' => 1,
        'recurrence_ends_on' => $startOfMonth->copy()->addDays(2)->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->get('/calendar?month='.$startOfMonth->format('Y-m'));

    $events = $response->original->getData()['page']['props']['events'];
    $matching = array_filter($events, fn ($e) => $e['title'] === 'Ends soon');

    // Should appear on day 1, 2, and 3 only (3 occurrences)
    expect(count($matching))->toBe(3);
});
```

- [ ] **Step 3: Run the tests to confirm they fail**

```bash
php artisan test --compact --filter=CalendarTest
```

Expected: FAIL — `CalendarController` not found / route returns 404.

- [ ] **Step 4: Generate the controller**

```bash
php artisan make:controller CalendarController --no-interaction
```

- [ ] **Step 5: Write `CalendarController`**

Replace `app/Http/Controllers/CalendarController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->query('month', Carbon::now()->format('Y-m'));

        [$year, $mon] = explode('-', $month);
        $firstOfMonth = Carbon::createFromDate((int) $year, (int) $mon, 1)->startOfDay();

        // Grid range: Sunday of first week through Saturday of last week
        $rangeStart = $firstOfMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $rangeEnd = $firstOfMonth->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $events = Event::expandRecurring($rangeStart, $rangeEnd, $request->user()->id);

        return Inertia::render('Calendar', [
            'events' => $events,
            'currentMonth' => $month,
        ]);
    }
}
```

- [ ] **Step 6: Update `routes/web.php`**

Replace the calendar stub line:
```php
// Before:
Route::inertia('/calendar', 'Stub')->name('calendar');

// After:
Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
```

Also add the import at the top of the file with the other controller imports:
```php
use App\Http\Controllers\CalendarController;
```

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Run the tests**

```bash
php artisan test --compact --filter=CalendarTest
```

Expected: all 6 tests PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/CalendarController.php routes/web.php tests/Feature/CalendarTest.php
git commit -m "feat: add CalendarController with recurring event expansion"
```

---

## Task 4: EventController + EventTest

**Files:**
- Create: `app/Http/Controllers/EventController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/EventTest.php`

**Interfaces:**
- Produces: `POST /events` → `events.store`, `PATCH /events/{event}` → `events.update`, `DELETE /events/{event}` → `events.destroy`

- [ ] **Step 1: Create the test file**

```bash
php artisan make:test --pest EventTest --no-interaction
```

- [ ] **Step 2: Write the failing tests**

Replace `tests/Feature/EventTest.php`:

```php
<?php

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

// ── store ──────────────────────────────────────────────────────────────────

test('user can create a one-off event', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/events', [
            'title' => 'Team lunch',
            'date' => '2026-08-01',
            'time' => '12:00',
            'tag' => 'Work',
            'color' => '#3f7fb0',
        ])
        ->assertRedirect();

    expect(Event::where('user_id', $user->id)->where('title', 'Team lunch')->exists())->toBeTrue();
});

test('user can create a recurring event', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/events', [
            'title' => 'Standup',
            'date' => '2026-08-04',
            'time' => '09:00',
            'tag' => 'Work',
            'color' => '#3f7fb0',
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_days' => ['Mon', 'Wed', 'Fri'],
        ])
        ->assertRedirect();

    $event = Event::where('user_id', $user->id)->where('title', 'Standup')->first();
    expect($event)->not->toBeNull();
    expect($event->recurrence_type)->toBe('weekly');
    expect($event->recurrence_days)->toBe(['Mon', 'Wed', 'Fri']);
});

test('store validates required fields', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/events', [])
        ->assertSessionHasErrors(['title', 'date', 'time', 'tag', 'color']);
});

test('store validates tag is in allowed list', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->post('/events', [
            'title' => 'Test',
            'date' => '2026-08-01',
            'time' => '09:00',
            'tag' => 'BadTag',
            'color' => '#3f7fb0',
        ])
        ->assertSessionHasErrors('tag');
});

test('guest cannot create an event', function (): void {
    $this->post('/events', [])->assertRedirect('/login');
});

// ── update ────────────────────────────────────────────────────────────────

test('user can update their event', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $event = Event::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch("/events/{$event->id}", ['title' => 'Updated title'])
        ->assertRedirect();

    expect($event->fresh()->title)->toBe('Updated title');
});

test('user cannot update another users event', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $other = User::factory()->create();
    $event = Event::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->patch("/events/{$event->id}", ['title' => 'Stolen'])
        ->assertForbidden();
});

// ── destroy ───────────────────────────────────────────────────────────────

test('user can delete their event', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $event = Event::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete("/events/{$event->id}")
        ->assertRedirect();

    expect(Event::find($event->id))->toBeNull();
});

test('user cannot delete another users event', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $other = User::factory()->create();
    $event = Event::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->delete("/events/{$event->id}")
        ->assertForbidden();
});
```

- [ ] **Step 3: Run the tests to confirm they fail**

```bash
php artisan test --compact --filter=EventTest
```

Expected: FAIL — route not found.

- [ ] **Step 4: Generate the controller**

```bash
php artisan make:controller EventController --no-interaction
```

- [ ] **Step 5: Write `EventController`**

Replace `app/Http/Controllers/EventController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'date' => ['required', 'date'],
            'time' => ['required', 'string'],
            'tag' => ['required', Rule::in(['Work', 'Personal', 'Health', 'Family', 'Other'])],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'recurrence_type' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:99'],
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['string', Rule::in(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])],
            'recurrence_ends_on' => ['nullable', 'date', 'after:date'],
        ]);

        Event::create([...$validated, 'user_id' => $request->user()->id]);

        return back();
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        abort_if($event->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'date' => ['sometimes', 'required', 'date'],
            'time' => ['sometimes', 'required', 'string'],
            'tag' => ['sometimes', 'required', Rule::in(['Work', 'Personal', 'Health', 'Family', 'Other'])],
            'color' => ['sometimes', 'required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'recurrence_type' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:99'],
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['string', Rule::in(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])],
            'recurrence_ends_on' => ['nullable', 'date'],
        ]);

        $event->update($validated);

        return back();
    }

    public function destroy(Request $request, Event $event): RedirectResponse
    {
        abort_if($event->user_id !== $request->user()->id, 403);

        $event->delete();

        return back();
    }
}
```

- [ ] **Step 6: Add routes to `routes/web.php`**

After the calendar route, add:

```php
Route::post('/events', [EventController::class, 'store'])->name('events.store');
Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
```

Also add the import at the top:
```php
use App\Http\Controllers\EventController;
```

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Run all tests**

```bash
php artisan test --compact --filter=EventTest
```

Expected: all 9 tests PASS.

- [ ] **Step 9: Run full test suite to check for regressions**

```bash
php artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/EventController.php routes/web.php tests/Feature/EventTest.php
git commit -m "feat: add EventController with store/update/destroy and tests"
```

---

## Task 5: Calendar.vue

**Files:**
- Create: `resources/js/pages/Calendar.vue`
- Auto-gen (run Wayfinder): `resources/js/actions/App/Http/Controllers/CalendarController.ts`, `resources/js/actions/App/Http/Controllers/EventController.ts`

**Interfaces:**
- Consumes: Inertia props `events: Occurrence[], currentMonth: string` from `CalendarController::index`
- Consumes: Wayfinder actions `CalendarController.index`, `EventController.store`, `EventController.update`, `EventController.destroy`

- [ ] **Step 1: Generate Wayfinder actions**

```bash
php artisan wayfinder:generate --no-interaction
```

Expected: generates `resources/js/actions/App/Http/Controllers/CalendarController.ts` and `EventController.ts`.

- [ ] **Step 2: Create `resources/js/pages/Calendar.vue`**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { index as calendarIndex } from '@/actions/App/Http/Controllers/CalendarController';
import { store, update, destroy } from '@/actions/App/Http/Controllers/EventController';

type Occurrence = {
    id: number;
    title: string;
    date: string;
    time: string;
    tag: string;
    color: string;
    bg: string;
    is_recurring: boolean;
    recurrence_type: string | null;
};

const props = defineProps<{
    events: Occurrence[];
    currentMonth: string;
}>();

// ── constants ──────────────────────────────────────────────────────────────

const TAG_COLORS: Record<string, string> = {
    Work: '#3f7fb0',
    Personal: '#a85fc0',
    Health: '#2f7d5b',
    Family: '#d98a2b',
    Other: '#6b6355',
};

const TAGS = ['Work', 'Personal', 'Health', 'Family', 'Other'] as const;
const WEEKDAYS_SHORT = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
const WEEKDAYS_FULL = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const DOW_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ── state ──────────────────────────────────────────────────────────────────

const today = new Date();
const todayStr = today.toISOString().slice(0, 10);

const view = ref<'month' | 'week'>((localStorage.getItem('cal-view') as 'month' | 'week') ?? 'month');
const selectedDate = ref(todayStr);
const showModal = ref(false);
const editingEvent = ref<Occurrence | null>(null);

// Form state
const form = ref({
    title: '',
    date: todayStr,
    time: '09:00',
    tag: 'Work' as string,
    color: TAG_COLORS['Work'],
    recurrence_enabled: false,
    recurrence_type: 'weekly' as string,
    recurrence_interval: 1,
    recurrence_days: [] as string[],
    recurrence_ends_on: '',
});

watch(view, (v) => localStorage.setItem('cal-view', v));

// ── computed: month grid ──────────────────────────────────────────────────

const calendarTitle = computed(() => {
    const [year, month] = props.currentMonth.split('-').map(Number);
    return new Date(year, month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
});

const gridCells = computed(() => {
    const [year, month] = props.currentMonth.split('-').map(Number);
    const firstDow = new Date(year, month - 1, 1).getDay(); // 0=Sun
    const daysInMonth = new Date(year, month, 0).getDate();
    const eventDays = new Set(
        props.events
            .filter((e) => e.date.startsWith(props.currentMonth))
            .map((e) => Number(e.date.slice(8, 10)))
    );

    const cells = [];
    for (let i = 0; i < firstDow; i++) {
        cells.push({ blank: true, day: 0, dateStr: '', isToday: false, hasEvent: false, dotColor: '' });
    }
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${props.currentMonth}-${String(d).padStart(2, '0')}`;
        const isToday = dateStr === todayStr;
        cells.push({ blank: false, day: d, dateStr, isToday, hasEvent: eventDays.has(d), dotColor: isToday ? '#fff' : 'var(--hab-accent)' });
    }
    return cells;
});

// ── computed: agenda ──────────────────────────────────────────────────────

const agendaEvents = computed(() =>
    props.events.filter((e) => e.date === selectedDate.value).sort((a, b) => a.time.localeCompare(b.time))
);

const agendaLabel = computed(() => {
    const d = new Date(selectedDate.value + 'T00:00:00');
    const label = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }).toUpperCase();
    const prefix = selectedDate.value === todayStr ? 'TODAY · ' : '';
    return prefix + label;
});

// ── computed: week view ───────────────────────────────────────────────────

const weekColumns = computed(() => {
    const sel = new Date(selectedDate.value + 'T00:00:00');
    const dow = sel.getDay(); // 0=Sun
    const sundayOfWeek = new Date(sel);
    sundayOfWeek.setDate(sel.getDate() - dow);

    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(sundayOfWeek);
        d.setDate(sundayOfWeek.getDate() + i);
        const dateStr = d.toISOString().slice(0, 10);
        return {
            dow: WEEKDAYS_FULL[i],
            dateNum: d.getDate(),
            dateStr,
            isToday: dateStr === todayStr,
            events: props.events.filter((e) => e.date === dateStr).sort((a, b) => a.time.localeCompare(b.time)),
        };
    });
});

const weekRangeLabel = computed(() => {
    const cols = weekColumns.value;
    const first = new Date(cols[0].dateStr + 'T00:00:00');
    const last = new Date(cols[6].dateStr + 'T00:00:00');
    const fmt = (d: Date) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    return `${fmt(first)} – ${fmt(last)}`;
});

// ── navigation ─────────────────────────────────────────────────────────────

function navigateMonth(delta: number) {
    const [year, month] = props.currentMonth.split('-').map(Number);
    const d = new Date(year, month - 1 + delta, 1);
    const newMonth = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    router.get(calendarIndex({ month: newMonth }).url, {}, { preserveState: false });
}

function navigateWeek(delta: number) {
    const sel = new Date(selectedDate.value + 'T00:00:00');
    sel.setDate(sel.getDate() + delta * 7);
    const newDateStr = sel.toISOString().slice(0, 10);
    const newMonth = newDateStr.slice(0, 7);
    selectedDate.value = newDateStr;
    if (newMonth !== props.currentMonth) {
        router.get(calendarIndex({ month: newMonth }).url, {}, { preserveState: false });
    }
}

// ── modal ──────────────────────────────────────────────────────────────────

function openNew() {
    editingEvent.value = null;
    form.value = {
        title: '',
        date: selectedDate.value,
        time: '09:00',
        tag: 'Work',
        color: TAG_COLORS['Work'],
        recurrence_enabled: false,
        recurrence_type: 'weekly',
        recurrence_interval: 1,
        recurrence_days: [],
        recurrence_ends_on: '',
    };
    showModal.value = true;
}

function openEdit(event: Occurrence) {
    editingEvent.value = event;
    form.value = {
        title: event.title,
        date: event.date,
        time: event.time,
        tag: event.tag,
        color: event.color,
        recurrence_enabled: event.is_recurring,
        recurrence_type: event.recurrence_type ?? 'weekly',
        recurrence_interval: 1,
        recurrence_days: [],
        recurrence_ends_on: '',
    };
    showModal.value = true;
}

function selectTag(tag: string) {
    form.value.tag = tag;
    form.value.color = TAG_COLORS[tag] ?? '#6b6355';
}

function toggleDay(dow: string) {
    const idx = form.value.recurrence_days.indexOf(dow);
    if (idx === -1) {
        form.value.recurrence_days.push(dow);
    } else {
        form.value.recurrence_days.splice(idx, 1);
    }
}

function recurrenceUnit() {
    return { daily: 'days', weekly: 'weeks', monthly: 'months', yearly: 'years' }[form.value.recurrence_type] ?? 'days';
}

function submitForm() {
    const payload: Record<string, unknown> = {
        title: form.value.title,
        date: form.value.date,
        time: form.value.time,
        tag: form.value.tag,
        color: form.value.color,
    };

    if (form.value.recurrence_enabled) {
        payload.recurrence_type = form.value.recurrence_type;
        payload.recurrence_interval = form.value.recurrence_interval;
        payload.recurrence_days = form.value.recurrence_type === 'weekly' ? form.value.recurrence_days : null;
        payload.recurrence_ends_on = form.value.recurrence_ends_on || null;
    } else {
        // Explicitly clear recurrence when toggling off during edit
        payload.recurrence_type = null;
        payload.recurrence_interval = 1;
        payload.recurrence_days = null;
        payload.recurrence_ends_on = null;
    }

    if (editingEvent.value) {
        router.patch(update(editingEvent.value).url, payload, {
            onSuccess: () => { showModal.value = false; },
        });
    } else {
        router.post(store().url, payload, {
            onSuccess: () => { showModal.value = false; },
        });
    }
}

function deleteEvent() {
    if (!editingEvent.value) { return; }
    router.delete(destroy(editingEvent.value).url, {
        onSuccess: () => { showModal.value = false; },
    });
}
</script>

<template>
    <Head title="Calendar" />

    <!-- Page header -->
    <div class="flex items-end gap-4 flex-wrap">
        <div>
            <h1 style="font-family:'Bricolage Grotesque';font-weight:700;font-size:28px;letter-spacing:-.02em;line-height:1">Calendar</h1>
            <p style="color:#8a8172;font-size:14px;margin-top:4px">Your month at a glance</p>
        </div>
        <div class="flex items-center gap-3 ml-auto">
            <!-- Month/Week toggle -->
            <div style="display:flex;gap:4px;background:#fffdf8;border:1px solid #e0dacd;border-radius:13px;padding:4px">
                <button
                    @click="view = 'month'"
                    :style="`padding:7px 15px;border-radius:11px;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:'Instrument Sans';color:${view==='month'?'#211d17':'#8a8172'};background:${view==='month'?'#f1ede3':'transparent'}`"
                >Month</button>
                <button
                    @click="view = 'week'"
                    :style="`padding:7px 15px;border-radius:11px;border:none;cursor:pointer;font-size:13px;font-weight:600;font-family:'Instrument Sans';color:${view==='week'?'#211d17':'#8a8172'};background:${view==='week'?'#f1ede3':'transparent'}`"
                >Week</button>
            </div>
            <!-- New event button -->
            <button
                @click="openNew"
                style="display:flex;align-items:center;gap:7px;background:#211d17;color:#fdfbf5;border:none;border-radius:12px;padding:0 16px;height:42px;font-family:'Instrument Sans';font-weight:600;font-size:13.5px;cursor:pointer"
            >
                <span class="msr" style="font-size:18px">add</span> New event
            </button>
        </div>
    </div>

    <!-- ── Month view ── -->
    <div v-if="view === 'month'" style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start">

        <!-- Month grid -->
        <div style="background:#fffdf8;border:1px solid #e0dacd;border-radius:24px;padding:22px 24px;box-shadow:0 8px 24px -20px rgba(60,48,20,.5)">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
                <div style="font-family:'Bricolage Grotesque';font-weight:700;font-size:18px">{{ calendarTitle }}</div>
                <div style="display:flex;gap:6px">
                    <button @click="navigateMonth(-1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                        <span class="msr" style="font-size:20px">chevron_left</span>
                    </button>
                    <button @click="navigateMonth(1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                        <span class="msr" style="font-size:20px">chevron_right</span>
                    </button>
                </div>
            </div>

            <!-- Weekday headers -->
            <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-bottom:6px">
                <div v-for="w in WEEKDAYS_SHORT" :key="w" style="text-align:center;font-size:11px;font-weight:600;color:#a49b8a">{{ w }}</div>
            </div>

            <!-- Day cells -->
            <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px">
                <template v-for="(cell, i) in gridCells" :key="i">
                    <div v-if="cell.blank" style="aspect-ratio:1" />
                    <button
                        v-else
                        @click="selectedDate = cell.dateStr"
                        :style="`aspect-ratio:1;display:flex;flex-direction:column;align-items:center;justify-content:center;border:none;border-radius:10px;cursor:pointer;font-size:13.5px;font-family:'Instrument Sans';
                            ${cell.isToday ? 'background:var(--hab-accent);color:#fff;font-weight:700'
                            : cell.dateStr === selectedDate ? 'background:#f1ede3;color:#211d17;font-weight:600'
                            : 'background:transparent;color:#4a4436'}`"
                    >
                        {{ cell.day }}
                        <span v-if="cell.hasEvent" :style="`width:5px;height:5px;border-radius:50%;background:${cell.dotColor};margin-top:3px`" />
                    </button>
                </template>
            </div>
        </div>

        <!-- Agenda panel -->
        <div style="background:#fffdf8;border:1px solid #e0dacd;border-radius:22px;padding:20px 20px 8px;box-shadow:0 8px 24px -20px rgba(60,48,20,.5)">
            <div style="font-family:'Bricolage Grotesque';font-weight:700;font-size:16px;margin-bottom:6px">Agenda</div>
            <div style="font-size:11.5px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;color:#a49b8a;margin-bottom:4px">
                {{ agendaLabel }}
            </div>

            <div v-if="agendaEvents.length === 0" style="padding:24px 0;text-align:center;color:#a49b8a;font-size:13.5px">
                No events
            </div>

            <div
                v-for="event in agendaEvents"
                :key="event.id"
                class="group/event"
                style="display:flex;align-items:center;gap:11px;padding:10px 0;border-top:1px solid #efe9dc"
            >
                <div :style="`width:9px;height:9px;border-radius:50%;background:${event.color};flex:none`" />
                <div style="min-width:0;flex:1">
                    <div style="font-weight:600;font-size:14px;color:#211d17">{{ event.title }}</div>
                    <div style="font-size:12px;color:#8a8172">{{ event.time }}</div>
                </div>
                <span :style="`font-size:11.5px;font-weight:600;color:${event.color};background:${event.bg};padding:2px 9px;border-radius:20px`">
                    {{ event.tag }}
                </span>
                <button
                    @click="openEdit(event)"
                    class="opacity-0 group-hover/event:opacity-100"
                    style="border:none;background:transparent;cursor:pointer;color:#8a8172;padding:2px;flex:none;display:flex;align-items:center"
                >
                    <span class="msr" style="font-size:16px">edit</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Week view ── -->
    <div v-else style="background:#fffdf8;border:1px solid #e0dacd;border-radius:24px;padding:22px 24px;box-shadow:0 8px 24px -20px rgba(60,48,20,.5)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
            <div style="font-family:'Bricolage Grotesque';font-weight:700;font-size:18px">{{ weekRangeLabel }}</div>
            <div style="display:flex;gap:6px">
                <button @click="navigateWeek(-1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                    <span class="msr" style="font-size:20px">chevron_left</span>
                </button>
                <button @click="navigateWeek(1)" style="width:32px;height:32px;border:none;background:#f1ede3;border-radius:9px;cursor:pointer;color:#6b6355;display:flex;align-items:center;justify-content:center">
                    <span class="msr" style="font-size:20px">chevron_right</span>
                </button>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;align-items:start">
            <div v-for="col in weekColumns" :key="col.dateStr" style="display:flex;flex-direction:column;gap:8px;min-height:220px">
                <!-- Day header -->
                <div
                    :style="`display:flex;flex-direction:column;align-items:center;gap:1px;padding:8px 0;border-radius:10px;
                        ${col.isToday ? 'background:var(--hab-accent);color:#fff' : 'color:#4a4436'}`"
                >
                    <span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;opacity:.75">{{ col.dow.slice(0, 3) }}</span>
                    <span style="font-family:'Bricolage Grotesque';font-weight:700;font-size:17px">{{ col.dateNum }}</span>
                </div>
                <!-- Event chips -->
                <div
                    v-for="event in col.events"
                    :key="event.id + event.date"
                    @click="openEdit(event)"
                    :style="`background:${event.bg};border-left:3px solid ${event.color};border-radius:8px;padding:7px 9px;cursor:pointer`"
                >
                    <div :style="`font-size:11px;color:${event.color};font-weight:600`">{{ event.time }}</div>
                    <div style="font-size:12.5px;font-weight:600;color:#211d17;line-height:1.2;margin-top:1px">{{ event.title }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── New / Edit event modal ── -->
    <Dialog :open="showModal" @update:open="showModal = $event">
        <DialogContent style="max-width:480px">
            <DialogHeader>
                <DialogTitle>{{ editingEvent ? 'Edit event' : 'New event' }}</DialogTitle>
            </DialogHeader>

            <div style="display:flex;flex-direction:column;gap:14px;padding-top:4px">

                <!-- Title -->
                <div>
                    <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Title</label>
                    <input
                        v-model="form.title"
                        type="text"
                        placeholder="Event title"
                        style="width:100%;padding:9px 12px;border:1px solid #e0dacd;border-radius:11px;font-family:'Instrument Sans';font-size:14px;color:#211d17;background:#fffdf8;outline:none"
                    />
                </div>

                <!-- Date + Time row -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div>
                        <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Date</label>
                        <input
                            v-model="form.date"
                            type="date"
                            style="width:100%;padding:9px 12px;border:1px solid #e0dacd;border-radius:11px;font-family:'Instrument Sans';font-size:14px;color:#211d17;background:#fffdf8;outline:none"
                        />
                    </div>
                    <div>
                        <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Time</label>
                        <input
                            v-model="form.time"
                            type="time"
                            style="width:100%;padding:9px 12px;border:1px solid #e0dacd;border-radius:11px;font-family:'Instrument Sans';font-size:14px;color:#211d17;background:#fffdf8;outline:none"
                        />
                    </div>
                </div>

                <!-- Tag chips -->
                <div>
                    <label style="font-size:12.5px;font-weight:600;color:#6b6355;display:block;margin-bottom:6px">Tag</label>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <button
                            v-for="tag in TAGS"
                            :key="tag"
                            @click="selectTag(tag)"
                            :style="`padding:5px 12px;border-radius:20px;border:none;cursor:pointer;font-family:'Instrument Sans';font-size:12.5px;font-weight:600;
                                ${form.tag === tag
                                    ? `background:${TAG_COLORS[tag]};color:#fff`
                                    : 'background:#f1ede3;color:#6b6355'}`"
                        >{{ tag }}</button>
                    </div>
                </div>

                <!-- Recurrence toggle -->
                <div style="display:flex;align-items:center;gap:10px">
                    <input
                        id="recurrence-toggle"
                        type="checkbox"
                        v-model="form.recurrence_enabled"
                        style="width:16px;height:16px;cursor:pointer;accent-color:var(--hab-accent)"
                    />
                    <label for="recurrence-toggle" style="font-size:13.5px;font-weight:600;color:#211d17;cursor:pointer">Repeat</label>
                </div>

                <!-- Recurrence options -->
                <div v-if="form.recurrence_enabled" style="background:#f9f6ef;border-radius:14px;padding:14px;display:flex;flex-direction:column;gap:12px">

                    <!-- Type select -->
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Frequency</label>
                        <select
                            v-model="form.recurrence_type"
                            style="width:100%;padding:8px 12px;border:1px solid #e0dacd;border-radius:10px;font-family:'Instrument Sans';font-size:13.5px;color:#211d17;background:#fffdf8;outline:none"
                        >
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>

                    <!-- Interval -->
                    <div style="display:flex;align-items:center;gap:8px">
                        <label style="font-size:12px;font-weight:600;color:#6b6355;white-space:nowrap">Every</label>
                        <input
                            v-model.number="form.recurrence_interval"
                            type="number"
                            min="1"
                            max="99"
                            style="width:60px;padding:7px 10px;border:1px solid #e0dacd;border-radius:10px;font-family:'Instrument Sans';font-size:13.5px;color:#211d17;background:#fffdf8;outline:none;text-align:center"
                        />
                        <span style="font-size:13.5px;color:#6b6355">{{ recurrenceUnit() }}</span>
                    </div>

                    <!-- Weekday picker (weekly only) -->
                    <div v-if="form.recurrence_type === 'weekly'">
                        <label style="font-size:12px;font-weight:600;color:#6b6355;display:block;margin-bottom:6px">On</label>
                        <div style="display:flex;gap:5px;flex-wrap:wrap">
                            <button
                                v-for="dow in DOW_LABELS"
                                :key="dow"
                                @click="toggleDay(dow)"
                                :style="`width:38px;height:38px;border-radius:50%;border:none;cursor:pointer;font-family:'Instrument Sans';font-size:12px;font-weight:600;
                                    ${form.recurrence_days.includes(dow)
                                        ? 'background:var(--hab-accent);color:#fff'
                                        : 'background:#fffdf8;color:#6b6355;border:1px solid #e0dacd'}`"
                            >{{ dow.slice(0, 2) }}</button>
                        </div>
                    </div>

                    <!-- Ends on -->
                    <div>
                        <label style="font-size:12px;font-weight:600;color:#6b6355;display:block;margin-bottom:4px">Ends on <span style="font-weight:400">(optional)</span></label>
                        <input
                            v-model="form.recurrence_ends_on"
                            type="date"
                            style="width:100%;padding:8px 12px;border:1px solid #e0dacd;border-radius:10px;font-family:'Instrument Sans';font-size:13.5px;color:#211d17;background:#fffdf8;outline:none"
                        />
                    </div>
                </div>

                <!-- Actions -->
                <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:4px">
                    <button
                        v-if="editingEvent"
                        @click="deleteEvent"
                        style="padding:0 14px;height:38px;border:none;border-radius:10px;cursor:pointer;font-family:'Instrument Sans';font-weight:600;font-size:13px;background:#fbe2d6;color:#c0512c"
                    >Delete</button>
                    <button
                        @click="showModal = false"
                        style="padding:0 14px;height:38px;border:1px solid #e0dacd;border-radius:10px;cursor:pointer;font-family:'Instrument Sans';font-weight:600;font-size:13px;background:#fffdf8;color:#6b6355"
                    >Cancel</button>
                    <button
                        @click="submitForm"
                        style="padding:0 16px;height:38px;border:none;border-radius:10px;cursor:pointer;font-family:'Instrument Sans';font-weight:600;font-size:13px;background:#211d17;color:#fdfbf5"
                    >{{ editingEvent ? 'Save' : 'Create' }}</button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
```

- [ ] **Step 3: Run full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass.

- [ ] **Step 4: Build and verify in the browser**

Open `http://habitat.test/calendar` and check:
- Month view loads with the correct month grid
- Clicking a day updates the agenda panel
- Month prev/next navigation works
- Week toggle switches to week view
- Week prev/next navigation works
- "New event" button opens the modal
- Creating an event refreshes the calendar with the new event visible
- Recurring event shows the repeat toggle and weekday picker

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Calendar.vue resources/js/actions/App/Http/Controllers/CalendarController.ts resources/js/actions/App/Http/Controllers/EventController.ts
git commit -m "feat: build Calendar page with month/week views and event CRUD"
```
