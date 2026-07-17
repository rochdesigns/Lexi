# Habitat Shell + Today Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Habitat global shell (themed sidebar + header) and the Today page with seeded demo data, establishing the foundation all future pages will use.

**Architecture:** A full replacement of the generic Laravel starter shell with Habitat's warm-neutral design system. Material Symbols icons are loaded via Google Fonts CDN as a font and referenced by ligature name. The active theme (`ocean` by default) is stored in a `settings` DB table, shared via Inertia middleware, and applied as a `data-theme` attribute on `<body>` by the layout component — all CSS custom properties key off this attribute.

**Tech Stack:** PHP 8.4 + Laravel 13, Inertia v3 + Vue 3 `<script setup>`, Tailwind CSS v4, SQLite, Material Symbols Rounded (Google Fonts), Bricolage Grotesque + Instrument Sans (Google Fonts), shadcn-vue component library.

## Global Constraints

- PHP 8.4 strict types; constructor property promotion; explicit return type hints on all methods
- Vue 3 Composition API (`<script setup>`) only — no Options API
- All monetary amounts: Philippine Peso `₱`, stored as `decimal(10,2)`, formatted client-side
- Material Symbols rendered as `<span class="material-symbols-rounded">icon_name</span>`
- All Habitat CSS custom properties prefixed `--hab-` to avoid conflict with shadcn/Tailwind vars
- Theme applied by setting `document.body.dataset.theme` in `AppSidebarLayout.vue` `onMounted`
- Pest v4 for all tests; run with `php artisan test --compact`
- Run `vendor/bin/pint --dirty --format agent` after any PHP file change
- Run `npm run build` after any frontend change if the dev server is not running

---

## File Map

**New files:**
- `database/migrations/2026_07_17_000001_create_settings_table.php`
- `database/migrations/2026_07_17_000002_create_events_table.php`
- `database/migrations/2026_07_17_000003_create_tasks_table.php`
- `database/migrations/2026_07_17_000004_create_health_metrics_table.php`
- `database/migrations/2026_07_17_000005_create_health_week_table.php`
- `database/migrations/2026_07_17_000006_create_habits_table.php`
- `database/migrations/2026_07_17_000007_create_habit_logs_table.php`
- `database/migrations/2026_07_17_000008_create_accounts_table.php`
- `database/migrations/2026_07_17_000009_create_budget_categories_table.php`
- `database/migrations/2026_07_17_000010_create_budget_months_table.php`
- `app/Models/Setting.php`
- `app/Models/Event.php`
- `app/Models/Task.php`
- `app/Models/HealthMetric.php`
- `app/Models/HealthWeek.php`
- `app/Models/Habit.php`
- `app/Models/HabitLog.php`
- `app/Models/Account.php`
- `app/Models/BudgetCategory.php`
- `app/Models/BudgetMonth.php`
- `app/Http/Controllers/TodayController.php`
- `app/Http/Controllers/TaskController.php`
- `resources/js/pages/Today.vue`
- `resources/js/pages/Stub.vue`
- `resources/js/types/habitat.ts`
- `tests/Feature/TodayPageTest.php`
- `tests/Feature/TaskToggleTest.php`

**Modified files:**
- `resources/views/app.blade.php` — add Google Fonts CDN links
- `resources/css/app.css` — add Habitat CSS vars + theme palettes
- `resources/js/components/AppSidebar.vue` — full replacement
- `resources/js/components/AppSidebarHeader.vue` — full replacement (Habitat top bar)
- `resources/js/layouts/app/AppSidebarLayout.vue` — add theme wiring
- `app/Http/Middleware/HandleInertiaRequests.php` — add theme to shared props
- `app/Models/User.php` — add `hasSetting`, `hasEvents`, etc. relationships
- `database/seeders/DatabaseSeeder.php` — seed demo data
- `routes/web.php` — add 18 routes

---

## Task 1: Fonts and CSS Design Tokens

**Files:**
- Modify: `resources/views/app.blade.php`
- Modify: `resources/css/app.css`

**Goal:** Load Material Symbols + Bricolage Grotesque via CDN; define all Habitat CSS custom properties scoped to `[data-theme]` selectors on `<body>`.

- [ ] **Step 1: Add CDN links to blade layout**

In `resources/views/app.blade.php`, add before `@fonts`:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,500;12..96,600;12..96,700;12..96,800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
```

- [ ] **Step 2: Add Habitat CSS vars to `app.css`**

Append to the end of `resources/css/app.css`:

```css
/* ── Habitat static tokens ───────────────────────────── */
:root {
    --hab-app-bg: #efece4;
    --hab-card-bg: #fffdf8;
    --hab-card-border: #e0dacd;
    --hab-hairline: #efe9dc;
    --hab-ink: #211d17;
    --hab-ink-secondary: #4a4436;
    --hab-muted: #8a8172;
    --hab-faint: #a49b8a;
    --hab-chip-fill: #f1ede3;
    --hab-btn-dark-bg: #211d17;
    --hab-btn-dark-ink: #fdfbf5;
    --hab-progress-track: #efe9dc;
    --hab-spend-fill: linear-gradient(90deg, #ffd27a, #ffe9a8);
}

/* ── Habitat theme palettes ──────────────────────────── */
body[data-theme="ocean"] {
    --hab-sidebar-bg: #142731;
    --hab-brand: #67c7e0;
    --hab-brand-ink: #0e2630;
    --hab-accent: #2f83aa;
    --hab-accent-deep: #28688a;
    --hab-grad-a: #2f83aa;
    --hab-grad-b: #286b8c;
    --hab-grad-c: #1e4f6b;
}

body[data-theme="forest"] {
    --hab-sidebar-bg: #1c2b23;
    --hab-brand: #5fce97;
    --hab-brand-ink: #12241a;
    --hab-accent: #2f7d5b;
    --hab-accent-deep: #276448;
    --hab-grad-a: #2f7d5b;
    --hab-grad-b: #276448;
    --hab-grad-c: #1f5138;
}

body[data-theme="plum"] {
    --hab-sidebar-bg: #241d2e;
    --hab-brand: #c39bf0;
    --hab-brand-ink: #231539;
    --hab-accent: #7d4bd0;
    --hab-accent-deep: #5e37a3;
    --hab-grad-a: #7d4bd0;
    --hab-grad-b: #653aad;
    --hab-grad-c: #4a2a82;
}

body[data-theme="clay"] {
    --hab-sidebar-bg: #2c211b;
    --hab-brand: #f0a878;
    --hab-brand-ink: #33200f;
    --hab-accent: #bd6537;
    --hab-accent-deep: #a5562d;
    --hab-grad-a: #c26a3c;
    --hab-grad-b: #a5562d;
    --hab-grad-c: #823f1f;
}

body[data-theme="slate"] {
    --hab-sidebar-bg: #22262e;
    --hab-brand: #9db2d6;
    --hab-brand-ink: #161d29;
    --hab-accent: #4d5f86;
    --hab-accent-deep: #3f4f70;
    --hab-grad-a: #4d5f86;
    --hab-grad-b: #3f4f70;
    --hab-grad-c: #2f3c57;
}

/* ── Habitat Material Symbols config ─────────────────── */
.material-symbols-rounded {
    font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
    font-size: 20px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    user-select: none;
}

.material-symbols-rounded.filled {
    font-variation-settings: 'FILL' 1, 'wght' 500, 'GRAD' 0, 'opsz' 24;
}

/* ── Habitat body background (authenticated pages) ───── */
body[data-theme] {
    background-color: var(--hab-app-bg);
}

/* ── Habitat progress bar transition ─────────────────── */
.hab-progress-fill {
    transition: width 0.5s cubic-bezier(0.2, 0.8, 0.2, 1);
}
```

- [ ] **Step 3: Verify fonts load**

Open `https://habitat.test/login` in browser (no auth needed). Open DevTools → Network → filter "fonts". Confirm `Bricolage+Grotesque`, `Instrument+Sans`, and `Material+Symbols+Rounded` appear as loaded resources.

- [ ] **Step 4: Commit**

```bash
git add resources/views/app.blade.php resources/css/app.css
git commit -m "feat: add Habitat design tokens, theme palettes, and CDN fonts"
```

---

## Task 2: Database Migrations

**Files:** 10 new migration files under `database/migrations/`

**Goal:** Create all tables needed for the Today page and shell.

- [ ] **Step 1: Generate migrations**

```bash
php artisan make:migration create_settings_table --no-interaction
php artisan make:migration create_events_table --no-interaction
php artisan make:migration create_tasks_table --no-interaction
php artisan make:migration create_health_metrics_table --no-interaction
php artisan make:migration create_health_week_table --no-interaction
php artisan make:migration create_habits_table --no-interaction
php artisan make:migration create_habit_logs_table --no-interaction
php artisan make:migration create_accounts_table --no-interaction
php artisan make:migration create_budget_categories_table --no-interaction
php artisan make:migration create_budget_months_table --no-interaction
```

- [ ] **Step 2: Fill in `create_settings_table` migration**

```php
public function up(): void
{
    Schema::create('settings', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('theme')->default('ocean');
        $table->timestamps();
    });
}
```

- [ ] **Step 3: Fill in `create_events_table` migration**

```php
public function up(): void
{
    Schema::create('events', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('title');
        $table->date('date');
        $table->string('time');
        $table->string('tag');
        $table->string('color');
        $table->timestamps();
    });
}
```

- [ ] **Step 4: Fill in `create_tasks_table` migration**

```php
public function up(): void
{
    Schema::create('tasks', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('label');
        $table->string('group')->default('today'); // today | week | someday
        $table->boolean('done')->default(false);
        $table->timestamps();
    });
}
```

- [ ] **Step 5: Fill in `create_health_metrics_table` migration**

```php
public function up(): void
{
    Schema::create('health_metrics', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->string('value');
        $table->string('sub');
        $table->integer('pct')->default(0);
        $table->string('color');
        $table->string('icon');
        $table->timestamps();
    });
}
```

- [ ] **Step 6: Fill in `create_health_week_table` migration**

```php
public function up(): void
{
    Schema::create('health_week', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('day'); // Mon, Tue, etc.
        $table->integer('steps')->default(0);
        $table->timestamps();
    });
}
```

- [ ] **Step 7: Fill in `create_habits_table` migration**

```php
public function up(): void
{
    Schema::create('habits', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->string('icon');
        $table->string('color');
        $table->timestamps();
    });
}
```

- [ ] **Step 8: Fill in `create_habit_logs_table` migration**

```php
public function up(): void
{
    Schema::create('habit_logs', function (Blueprint $table) {
        $table->id();
        $table->foreignId('habit_id')->constrained()->cascadeOnDelete();
        $table->date('date');
        $table->boolean('done')->default(true);
        $table->timestamps();
    });
}
```

- [ ] **Step 9: Fill in `create_accounts_table` migration**

```php
public function up(): void
{
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->string('institution');
        $table->string('last4');
        $table->decimal('balance', 10, 2)->default(0);
        $table->string('type')->default('cash'); // cash | credit | investment
        $table->timestamps();
    });
}
```

- [ ] **Step 10: Fill in `create_budget_categories_table` migration**

```php
public function up(): void
{
    Schema::create('budget_categories', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->string('name');
        $table->string('icon');
        $table->string('color');
        $table->decimal('limit_amount', 10, 2)->default(0);
        $table->timestamps();
    });
}
```

- [ ] **Step 11: Fill in `create_budget_months_table` migration**

```php
public function up(): void
{
    Schema::create('budget_months', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->unsignedSmallInteger('year');
        $table->unsignedTinyInteger('month');
        $table->decimal('budget', 10, 2)->default(0);
        $table->decimal('base_spent', 10, 2)->default(0);
        $table->timestamps();
    });
}
```

- [ ] **Step 12: Run migrations**

```bash
php artisan migrate --no-interaction
```

Expected: 10 new tables created with no errors.

- [ ] **Step 13: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/
git commit -m "feat: add Habitat database migrations"
```

---

## Task 3: Models

**Files:** 10 new models, modify `app/Models/User.php`

**Goal:** Eloquent models with relationships for all new tables.

- [ ] **Step 1: Generate models**

```bash
php artisan make:model Setting --no-interaction
php artisan make:model Event --no-interaction
php artisan make:model Task --no-interaction
php artisan make:model HealthMetric --no-interaction
php artisan make:model HealthWeek --no-interaction
php artisan make:model Habit --no-interaction
php artisan make:model HabitLog --no-interaction
php artisan make:model Account --no-interaction
php artisan make:model BudgetCategory --no-interaction
php artisan make:model BudgetMonth --no-interaction
```

- [ ] **Step 2: `app/Models/Setting.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = ['user_id', 'theme'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: `app/Models/Event.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = ['user_id', 'title', 'date', 'time', 'tag', 'color'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: `app/Models/Task.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = ['user_id', 'label', 'group', 'done'];

    protected $casts = ['done' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: `app/Models/HealthMetric.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthMetric extends Model
{
    protected $fillable = ['user_id', 'name', 'value', 'sub', 'pct', 'color', 'icon'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 6: `app/Models/HealthWeek.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthWeek extends Model
{
    protected $table = 'health_week';

    protected $fillable = ['user_id', 'day', 'steps'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 7: `app/Models/Habit.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Habit extends Model
{
    protected $fillable = ['user_id', 'name', 'icon', 'color'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }
}
```

- [ ] **Step 8: `app/Models/HabitLog.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitLog extends Model
{
    protected $fillable = ['habit_id', 'date', 'done'];

    protected $casts = ['date' => 'date', 'done' => 'boolean'];

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
```

- [ ] **Step 9: `app/Models/Account.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    protected $fillable = ['user_id', 'name', 'institution', 'last4', 'balance', 'type'];

    protected $casts = ['balance' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 10: `app/Models/BudgetCategory.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetCategory extends Model
{
    protected $fillable = ['user_id', 'name', 'icon', 'color', 'limit_amount'];

    protected $casts = ['limit_amount' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 11: `app/Models/BudgetMonth.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetMonth extends Model
{
    protected $fillable = ['user_id', 'year', 'month', 'budget', 'base_spent'];

    protected $casts = [
        'budget' => 'decimal:2',
        'base_spent' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 12: Update `app/Models/User.php` — add relationships**

Add these methods to `User`:

```php
use App\Models\Setting;
use App\Models\Event;
use App\Models\Task;
use App\Models\HealthMetric;
use App\Models\HealthWeek;
use App\Models\Habit;
use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\BudgetMonth;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

public function setting(): HasOne
{
    return $this->hasOne(Setting::class);
}

public function events(): HasMany
{
    return $this->hasMany(Event::class);
}

public function tasks(): HasMany
{
    return $this->hasMany(Task::class);
}

public function healthMetrics(): HasMany
{
    return $this->hasMany(HealthMetric::class);
}

public function habits(): HasMany
{
    return $this->hasMany(Habit::class);
}

public function accounts(): HasMany
{
    return $this->hasMany(Account::class);
}

public function budgetMonths(): HasMany
{
    return $this->hasMany(BudgetMonth::class);
}
```

- [ ] **Step 13: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/
git commit -m "feat: add Habitat Eloquent models and User relationships"
```

---

## Task 4: Seeder

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`

**Goal:** Seed one user ("Elle Domingo") with complete demo data for the Today page.

- [ ] **Step 1: Replace `DatabaseSeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BudgetMonth;
use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HealthMetric;
use App\Models\HealthWeek;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'name' => 'Elle Domingo',
            'email' => 'elle@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        Setting::create(['user_id' => $user->id, 'theme' => 'ocean']);

        // Events — today only (July 17 2026)
        $today = Carbon::parse('2026-07-17');
        foreach ([
            ['title' => 'Team standup', 'time' => '11:00 PM', 'tag' => 'Work', 'color' => '#7d4bd0'],
            ['title' => 'Lunch with Mia', 'time' => '12:30 PM', 'tag' => 'Personal', 'color' => '#c0512c'],
            ['title' => 'Yoga class', 'time' => '6:00 PM', 'tag' => 'Health', 'color' => '#2f7d5b'],
        ] as $event) {
            Event::create(array_merge($event, ['user_id' => $user->id, 'date' => $today->toDateString()]));
        }

        // Tasks
        foreach ([
            ['label' => 'Reply to the landlord', 'group' => 'today', 'done' => false],
            ['label' => 'Book a dentist appointment', 'group' => 'today', 'done' => false],
            ['label' => 'Pay the electric bill', 'group' => 'today', 'done' => false],
            ['label' => 'Grocery run', 'group' => 'today', 'done' => true],
            ['label' => 'Finish the Q3 deck', 'group' => 'week', 'done' => false],
            ['label' => 'Renew car insurance quote', 'group' => 'week', 'done' => false],
            ['label' => 'Clean out the garage', 'group' => 'week', 'done' => false],
            ['label' => 'Learn to make sourdough', 'group' => 'someday', 'done' => false],
            ['label' => 'Plan Batangas trip', 'group' => 'someday', 'done' => false],
        ] as $task) {
            Task::create(array_merge($task, ['user_id' => $user->id]));
        }

        // Health metrics
        foreach ([
            ['name' => 'Steps', 'value' => '7,420', 'sub' => 'of 10,000', 'pct' => 74, 'color' => '#2f7d5b', 'icon' => 'directions_walk'],
            ['name' => 'Sleep', 'value' => '7h 10m', 'sub' => 'of 8h', 'pct' => 90, 'color' => '#3f7fb0', 'icon' => 'bedtime'],
            ['name' => 'Active energy', 'value' => '480', 'sub' => 'of 600 cal', 'pct' => 80, 'color' => '#d98a2b', 'icon' => 'local_fire_department'],
            ['name' => 'Resting HR', 'value' => '58', 'sub' => 'bpm · steady', 'pct' => 0, 'color' => '#c0512c', 'icon' => 'favorite'],
        ] as $metric) {
            HealthMetric::create(array_merge($metric, ['user_id' => $user->id]));
        }

        // Health week (Mon–Sun steps)
        foreach ([
            ['day' => 'Mon', 'steps' => 9200],
            ['day' => 'Tue', 'steps' => 6800],
            ['day' => 'Wed', 'steps' => 11400],
            ['day' => 'Thu', 'steps' => 7900],
            ['day' => 'Fri', 'steps' => 7420],
            ['day' => 'Sat', 'steps' => 0],
            ['day' => 'Sun', 'steps' => 0],
        ] as $row) {
            HealthWeek::create(array_merge($row, ['user_id' => $user->id]));
        }

        // Habits + logs
        $habitsData = [
            ['name' => 'Drink water', 'icon' => 'water_drop', 'color' => '#3f7fb0',
             'done_days' => ['Sun', 'Mon', 'Tue', 'Wed']],
            ['name' => 'Read 20 min', 'icon' => 'menu_book', 'color' => '#7d4bd0',
             'done_days' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu']],
            ['name' => 'Move 30 min', 'icon' => 'directions_run', 'color' => '#d98a2b',
             'done_days' => ['Sun', 'Tue', 'Wed']],
            ['name' => 'Meditate', 'icon' => 'self_improvement', 'color' => '#2f7d5b',
             'done_days' => ['Sun', 'Mon']],
        ];

        // Week starts Sun Jul 13 2026
        $weekStart = Carbon::parse('2026-07-13');
        $dayOffsets = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

        foreach ($habitsData as $data) {
            $habit = Habit::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'icon' => $data['icon'],
                'color' => $data['color'],
            ]);
            foreach ($data['done_days'] as $dayName) {
                HabitLog::create([
                    'habit_id' => $habit->id,
                    'date' => $weekStart->copy()->addDays($dayOffsets[$dayName])->toDateString(),
                    'done' => true,
                ]);
            }
        }

        // Accounts
        foreach ([
            ['name' => 'Checking', 'institution' => 'BDO', 'last4' => '4821', 'balance' => 2480.00, 'type' => 'cash'],
            ['name' => 'Savings', 'institution' => 'BDO', 'last4' => '3392', 'balance' => 8120.00, 'type' => 'cash'],
            ['name' => 'Credit card', 'institution' => 'BPI', 'last4' => '7714', 'balance' => -612.00, 'type' => 'credit'],
        ] as $account) {
            Account::create(array_merge($account, ['user_id' => $user->id]));
        }

        // Budget month — July 2026
        BudgetMonth::create([
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 7,
            'budget' => 3200.00,
            'base_spent' => 1970.00,
        ]);
    }
}
```

- [ ] **Step 2: Run migrations fresh + seed**

```bash
php artisan migrate:fresh --seed --no-interaction
```

Expected: Tables created and seeded with no errors.

- [ ] **Step 3: Verify seed data**

```bash
php artisan tinker --execute 'echo \App\Models\User::first()->name . " — " . \App\Models\Task::count() . " tasks, " . \App\Models\Event::count() . " events\n";'
```

Expected output: `Elle Domingo — 9 tasks, 3 events`

- [ ] **Step 4: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/
git commit -m "feat: seed Habitat demo data for Today page"
```

---

## Task 5: Routes, Stub Page, and TodayController Scaffold

**Files:**
- Modify: `routes/web.php`
- Create: `resources/js/pages/Stub.vue`
- Create: `app/Http/Controllers/TodayController.php`

**Goal:** Wire all 18 routes; create a Stub page for the 17 not-yet-built pages; scaffold TodayController.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TodayPageTest.php`:

```php
<?php

use App\Models\User;

test('today page loads for authenticated user', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->get('/today')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->has('todayEvents')
            ->has('todayTasks')
            ->has('moneySummary')
            ->has('habits')
            ->has('healthMetrics')
        );
});

test('today redirects unauthenticated users to login', function (): void {
    $this->get('/today')->assertRedirect('/login');
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php artisan test --compact --filter=TodayPageTest
```

Expected: FAIL — "today page loads" fails because the route doesn't exist yet.

- [ ] **Step 3: Create `TodayController`**

```bash
php artisan make:controller TodayController --no-interaction
```

Fill in `app/Http/Controllers/TodayController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\BudgetMonth;
use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HealthMetric;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::SUNDAY);

        $todayEvents = Event::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->orderBy('time')
            ->get(['id', 'title', 'time', 'tag', 'color']);

        $todayTasks = Task::where('user_id', $user->id)
            ->where('group', 'today')
            ->orderBy('done')
            ->orderBy('id')
            ->get(['id', 'label', 'group', 'done']);

        $budgetMonth = BudgetMonth::where('user_id', $user->id)
            ->where('year', $today->year)
            ->where('month', $today->month)
            ->first();

        $moneySummary = $budgetMonth ? [
            'safeToSpend' => (float) ($budgetMonth->budget - $budgetMonth->base_spent),
            'budget' => (float) $budgetMonth->budget,
            'spent' => (float) $budgetMonth->base_spent,
        ] : ['safeToSpend' => 0, 'budget' => 0, 'spent' => 0];

        $habits = Habit::where('user_id', $user->id)->get()->map(function (Habit $habit) use ($weekStart): array {
            $logsThisWeek = HabitLog::where('habit_id', $habit->id)
                ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
                ->where('done', true)
                ->count();

            $pct = (int) round(($logsThisWeek / 7) * 100);

            // Streak: consecutive days done ending today (working backwards)
            $streak = 0;
            $checkDate = Carbon::today();
            while (HabitLog::where('habit_id', $habit->id)->whereDate('date', $checkDate)->where('done', true)->exists()) {
                $streak++;
                $checkDate->subDay();
            }

            return [
                'id' => $habit->id,
                'name' => $habit->name,
                'icon' => $habit->icon,
                'color' => $habit->color,
                'pct' => $pct,
                'streak' => $streak,
            ];
        });

        $healthMetrics = HealthMetric::where('user_id', $user->id)
            ->whereIn('name', ['Steps', 'Sleep'])
            ->get(['name', 'value', 'sub', 'pct', 'color', 'icon']);

        $firstName = explode(' ', $user->name)[0];

        return Inertia::render('Today', [
            'user' => [
                'name' => $user->name,
                'firstName' => $firstName,
                'theme' => $user->setting?->theme ?? 'ocean',
            ],
            'todayEvents' => $todayEvents,
            'todayTasks' => $todayTasks,
            'moneySummary' => $moneySummary,
            'habits' => $habits,
            'healthMetrics' => $healthMetrics,
        ]);
    }
}
```

- [ ] **Step 4: Create `Stub.vue`**

Create `resources/js/pages/Stub.vue`:

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const pageName = computed(() => page.component.replace('Stub/', ''));
</script>

<template>
    <div class="flex h-64 flex-col items-center justify-center gap-2">
        <p class="text-[var(--hab-muted)] text-sm">Coming soon</p>
    </div>
</template>
```

- [ ] **Step 5: Update `routes/web.php`**

Replace the file contents:

```php
<?php

use App\Http\Controllers\TaskController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/today', TodayController::class)->name('today');

    // Stub routes — content added in future sprints
    Route::inertia('/calendar', 'Stub')->name('calendar');
    Route::inertia('/tasks', 'Stub')->name('tasks');
    Route::inertia('/health', 'Stub')->name('health');
    Route::inertia('/habits', 'Stub')->name('habits');
    Route::inertia('/notes', 'Stub')->name('notes');
    Route::inertia('/journal', 'Stub')->name('journal');
    Route::inertia('/meals', 'Stub')->name('meals');
    Route::inertia('/groceries', 'Stub')->name('groceries');
    Route::inertia('/pets', 'Stub')->name('pets');
    Route::inertia('/goals', 'Stub')->name('goals');
    Route::inertia('/accounts', 'Stub')->name('accounts');
    Route::inertia('/budgets', 'Stub')->name('budgets');
    Route::inertia('/set-asides', 'Stub')->name('set-asides');
    Route::inertia('/debt', 'Stub')->name('debt');
    Route::inertia('/photos', 'Stub')->name('photos');

    // Task interactions
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');

    // Legacy redirect
    Route::redirect('/dashboard', '/today');
});

require __DIR__.'/settings.php';
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --compact --filter=TodayPageTest
```

Expected: PASS — both tests pass.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/TodayController.php resources/js/pages/Stub.vue tests/Feature/TodayPageTest.php
git commit -m "feat: add Today route, stub routes, and TodayController"
```

---

## Task 6: Inertia Shared Props and Theme Wiring

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/layouts/app/AppSidebarLayout.vue`
- Modify: `resources/js/types/habitat.ts` (new file — Habitat-specific types)

**Goal:** Share the user's theme via Inertia; apply it as `data-theme` on `<body>` when any authenticated page mounts.

- [ ] **Step 1: Update `HandleInertiaRequests.php`**

Replace the `share()` method:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'name' => config('app.name'),
        'auth' => [
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'avatar' => $request->user()->avatar ?? null,
                'theme' => $request->user()->setting?->theme ?? 'ocean',
            ] : null,
        ],
        'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
    ];
}
```

Also add the import at the top if needed (should already be there from the existing file).

- [ ] **Step 2: Create `resources/js/types/habitat.ts`**

```typescript
export type HabitatTheme = 'ocean' | 'forest' | 'plum' | 'clay' | 'slate';

export type HabitatNavItem = {
    title: string;
    href: string;
    icon: string; // Material Symbols ligature name
    badge?: number;
};

export type HabitatNavSection = {
    label: string;
    items: HabitatNavItem[];
};
```

Export from `resources/js/types/index.ts` — add:

```typescript
export * from './habitat';
```

- [ ] **Step 3: Update `AppSidebarLayout.vue` — add theme wiring**

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const theme = computed(() => (page.props.auth?.user as any)?.theme ?? 'ocean');

onMounted(() => {
    document.body.dataset.theme = theme.value;
});

onUnmounted(() => {
    delete document.body.dataset.theme;
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
```

- [ ] **Step 4: Verify theme applies**

Log in as `elle@example.com` / `password`. Open DevTools → Elements. Confirm `<body data-theme="ocean">` is set. Confirm `background-color: #efece4` is applied to the body.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/HandleInertiaRequests.php \
        resources/js/layouts/app/AppSidebarLayout.vue \
        resources/js/types/habitat.ts \
        resources/js/types/index.ts
git commit -m "feat: wire Habitat theme to body data-theme via Inertia shared props"
```

---

## Task 7: Habitat Sidebar

**Files:**
- Modify: `resources/js/components/AppSidebar.vue` (full replacement)

**Goal:** Replace the generic sidebar with the Habitat-styled version — dark themed, 3 nav sections, Material Symbols icons, brand row, user tile.

- [ ] **Step 1: Replace `AppSidebar.vue`**

```vue
<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { getInitials } from '@/composables/useInitials';
import type { HabitatNavSection } from '@/types';

const page = usePage();
const user = computed(() => page.props.auth?.user as any);
const { isCurrentUrl } = useCurrentUrl();

const navSections: HabitatNavSection[] = [
    {
        label: 'Day',
        items: [
            { title: 'Today', href: '/today', icon: 'wb_sunny' },
            { title: 'Calendar', href: '/calendar', icon: 'calendar_month' },
            { title: 'Tasks', href: '/tasks', icon: 'check_circle' },
        ],
    },
    {
        label: 'Life',
        items: [
            { title: 'Health', href: '/health', icon: 'favorite' },
            { title: 'Habits', href: '/habits', icon: 'loop' },
            { title: 'Notes', href: '/notes', icon: 'sticky_note_2' },
            { title: 'Journal', href: '/journal', icon: 'auto_stories' },
            { title: 'Meals', href: '/meals', icon: 'restaurant' },
            { title: 'Groceries', href: '/groceries', icon: 'shopping_cart' },
            { title: 'Pets', href: '/pets', icon: 'pets' },
        ],
    },
    {
        label: 'Money',
        items: [
            { title: 'Goals', href: '/goals', icon: 'flag' },
            { title: 'Accounts', href: '/accounts', icon: 'account_balance' },
            { title: 'Budgets', href: '/budgets', icon: 'account_balance_wallet' },
            { title: 'Set-asides', href: '/set-asides', icon: 'savings' },
            { title: 'Debt', href: '/debt', icon: 'credit_card' },
            { title: 'Photos', href: '/photos', icon: 'photo_library' },
        ],
    },
];
</script>

<template>
    <Sidebar
        collapsible="none"
        class="border-r-0"
        :style="{ background: 'var(--hab-sidebar-bg)', width: '248px', flexShrink: '0' }"
    >
        <!-- Brand row -->
        <SidebarHeader class="px-4 py-5">
            <div class="flex items-center gap-3">
                <!-- Logo mark -->
                <div
                    class="flex h-8 w-8 items-center justify-center rounded-lg"
                    :style="{ background: 'var(--hab-brand)' }"
                >
                    <span
                        class="material-symbols-rounded filled text-[18px]"
                        :style="{ color: 'var(--hab-brand-ink)', fontSize: '18px' }"
                    >cottage</span>
                </div>
                <!-- Wordmark -->
                <span
                    class="font-['Bricolage_Grotesque'] text-[17px] font-bold text-white"
                >Habitat</span>
                <!-- User pill -->
                <span
                    v-if="user"
                    class="ml-auto rounded-full px-2.5 py-0.5 text-[11px] font-medium text-white/80"
                    style="background: rgba(255,255,255,.15)"
                >
                    {{ user.name.split(' ')[0] }}
                </span>
            </div>
        </SidebarHeader>

        <!-- Nav sections -->
        <SidebarContent class="px-2 py-0 overflow-y-auto">
            <div v-for="section in navSections" :key="section.label" class="mb-4">
                <!-- Group label -->
                <p
                    class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-widest"
                    style="color: #8a978f"
                >
                    {{ section.label }}
                </p>
                <!-- Nav items -->
                <nav>
                    <Link
                        v-for="item in section.items"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-[13.5px] font-medium transition-colors"
                        :style="isCurrentUrl(item.href)
                            ? { background: 'var(--hab-brand)', color: 'var(--hab-brand-ink)' }
                            : { color: '#c3d1c9' }"
                    >
                        <span
                            class="material-symbols-rounded text-[18px]"
                            :style="{ color: isCurrentUrl(item.href) ? 'var(--hab-brand-ink)' : '#94a89d', fontSize: '18px' }"
                        >{{ item.icon }}</span>
                        <span>{{ item.title }}</span>
                    </Link>
                </nav>
            </div>
        </SidebarContent>

        <!-- User tile -->
        <SidebarFooter class="px-2 pb-4">
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors hover:bg-white/10"
                    >
                        <!-- Avatar -->
                        <div
                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[13px] font-semibold"
                            :style="{ background: 'var(--hab-brand)', color: 'var(--hab-brand-ink)' }"
                        >
                            {{ user ? getInitials(user.name) : '?' }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[13px] font-medium text-white">
                                {{ user?.name }}
                            </p>
                            <p class="text-[11px]" style="color: #8a978f">Free plan</p>
                        </div>
                        <span class="material-symbols-rounded text-[16px]" style="color: #8a978f; font-size: 16px">chevron_right</span>
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" side="right" class="w-56">
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarFooter>
    </Sidebar>
</template>
```

- [ ] **Step 2: Verify sidebar renders**

Visit `https://habitat.test/today` logged in. Confirm:
- Dark sidebar with 3 sections (Day / Life / Money)
- "Today" item has the accent background (ocean blue)
- "Habitat" wordmark and brand mark visible
- User name in the footer tile

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/AppSidebar.vue
git commit -m "feat: replace sidebar with Habitat-themed navigation"
```

---

## Task 8: Habitat Header

**Files:**
- Modify: `resources/js/components/AppSidebarHeader.vue` (full replacement)

**Goal:** Replace the generic header with the Habitat sticky bar — breadcrumb with section icon, search input, notification bell, Log spending button.

The section is derived from the current URL (matched against known path prefixes).

- [ ] **Step 1: Replace `AppSidebarHeader.vue`**

```vue
<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const { currentUrl } = useCurrentUrl();

type Section = { icon: string; label: string; paths: string[] };

const sections: Section[] = [
    { icon: 'wb_sunny', label: 'Day', paths: ['/today', '/calendar', '/tasks'] },
    { icon: 'interests', label: 'Life', paths: ['/health', '/habits', '/notes', '/journal', '/meals', '/groceries', '/pets'] },
    { icon: 'account_balance_wallet', label: 'Money', paths: ['/goals', '/accounts', '/budgets', '/set-asides', '/debt', '/photos'] },
];

const pageLabels: Record<string, string> = {
    '/today': 'Today', '/calendar': 'Calendar', '/tasks': 'Tasks',
    '/health': 'Health', '/habits': 'Habits', '/notes': 'Notes',
    '/journal': 'Journal', '/meals': 'Meals', '/groceries': 'Groceries', '/pets': 'Pets',
    '/goals': 'Goals', '/accounts': 'Accounts', '/budgets': 'Budgets',
    '/set-asides': 'Set-asides', '/debt': 'Debt', '/photos': 'Photos',
};

const activeSection = computed(() =>
    sections.find((s) => s.paths.some((p) => currentUrl.value.startsWith(p))) ?? sections[0],
);

const pageLabel = computed(() => {
    const match = Object.keys(pageLabels).find((p) => currentUrl.value.startsWith(p));
    return match ? pageLabels[match] : '';
});
</script>

<template>
    <header
        class="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-4 border-b px-6"
        style="
            background: rgba(239, 236, 228, 0.86);
            backdrop-filter: blur(12px);
            border-color: #e0dacd;
        "
    >
        <!-- Breadcrumb -->
        <div class="flex items-center gap-1.5 text-[13px]" style="color: var(--hab-muted)">
            <span class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)">
                {{ activeSection.icon }}
            </span>
            <span style="color: var(--hab-faint)">{{ activeSection.label }}</span>
            <span style="color: var(--hab-faint)">/</span>
            <span class="font-semibold" style="color: var(--hab-ink)">{{ pageLabel }}</span>
        </div>

        <!-- Search -->
        <div class="mx-auto flex w-[250px] items-center gap-2 rounded-[11px] border px-3 py-1.5" style="border-color: #e0dacd; background: var(--hab-card-bg)">
            <span class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)">search</span>
            <input
                type="text"
                placeholder="Search anything in your life…"
                class="flex-1 bg-transparent text-[13px] outline-none"
                style="color: var(--hab-ink)"
                readonly
            />
            <kbd
                class="rounded px-1.5 py-0.5 text-[10px] font-medium"
                style="background: var(--hab-chip-fill); color: var(--hab-faint)"
            >⌘K</kbd>
        </div>

        <!-- Right actions -->
        <div class="ml-auto flex items-center gap-2">
            <!-- Notification bell -->
            <button class="relative flex h-9 w-9 items-center justify-center rounded-lg hover:bg-black/5">
                <span class="material-symbols-rounded" style="font-size: 20px; color: var(--hab-ink-secondary)">notifications</span>
                <!-- Red dot -->
                <span
                    class="absolute right-1.5 top-1.5 h-2 w-2 rounded-full"
                    style="background: #c0512c"
                ></span>
            </button>

            <!-- Log spending button -->
            <button
                class="flex items-center gap-1.5 rounded-[11px] px-4 py-2 text-[13px] font-semibold"
                style="background: var(--hab-btn-dark-bg); color: var(--hab-btn-dark-ink)"
            >
                <span class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-btn-dark-ink)">add</span>
                Log spending
            </button>
        </div>
    </header>
</template>
```

- [ ] **Step 2: Verify header renders**

Visit `https://habitat.test/today`. Confirm:
- Breadcrumb shows `wb_sunny Day / Today`
- Search box visible with ⌘K
- "Log spending" dark button on the right

Navigate to `/habits`. Confirm breadcrumb updates to `interests Life / Habits`.

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/AppSidebarHeader.vue
git commit -m "feat: replace header with Habitat top bar — breadcrumb, search, Log spending"
```

---

## Task 9: Today Page

**Files:**
- Create: `resources/js/pages/Today.vue`

**Goal:** Build the full Today page — gradient hero, today's schedule, tasks checklist, money snapshot, habits rings, health bars, "On this day" card.

- [ ] **Step 1: Create `Today.vue`**

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type TodayEvent = { id: number; title: string; time: string; tag: string; color: string };
type TodayTask = { id: number; label: string; group: string; done: boolean };
type MoneySummary = { safeToSpend: number; budget: number; spent: number };
type Habit = { id: number; name: string; icon: string; color: string; pct: number; streak: number };
type HealthMetric = { name: string; value: string; sub: string; pct: number; color: string; icon: string };

const props = defineProps<{
    user: { name: string; firstName: string; theme: string };
    todayEvents: TodayEvent[];
    todayTasks: TodayTask[];
    moneySummary: MoneySummary;
    habits: Habit[];
    healthMetrics: HealthMetric[];
}>();

// Optimistic task toggle
const localTasks = ref(props.todayTasks.map((t) => ({ ...t })));

const undoneCount = computed(() => localTasks.value.filter((t) => !t.done).length);

function toggleTask(task: (typeof localTasks.value)[0]): void {
    const prev = task.done;
    task.done = !prev;
    router.patch(
        `/tasks/${task.id}`,
        { done: task.done },
        {
            preserveScroll: true,
            onError: () => {
                task.done = prev; // revert on error
            },
        },
    );
}

// Hero subtitle
const heroSubtitle = computed(() => {
    const events = props.todayEvents.length;
    const tasks = undoneCount.value;
    const eventsText = `${events} ${events === 1 ? 'event' : 'events'}`;
    const tasksText = `${tasks} ${tasks === 1 ? 'task' : 'tasks'} to go`;
    const budgetPct = props.moneySummary.budget > 0
        ? (props.moneySummary.spent / props.moneySummary.budget) * 100
        : 0;
    const budgetMood = budgetPct < 60
        ? "you're comfortably under budget"
        : budgetPct < 85
          ? "you're on track"
          : budgetPct < 100
            ? "you're getting close on budget"
            : "you're over budget";
    const day = new Date().toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
    return `${day} — ${eventsText}, ${tasksText}, and ${budgetMood}.`;
});

// Money
const spentPct = computed(() =>
    props.moneySummary.budget > 0
        ? Math.min(100, (props.moneySummary.spent / props.moneySummary.budget) * 100)
        : 0,
);

function formatPeso(amount: number): string {
    return '₱' + Math.round(amount).toLocaleString('en-PH');
}

// Tag pill color helper
const tagColors: Record<string, { bg: string; text: string }> = {
    Work: { bg: '#efe0f5', text: '#a85fc0' },
    Personal: { bg: '#fbe2d6', text: '#c0512c' },
    Health: { bg: '#dff3e6', text: '#2f7d5b' },
};
function tagStyle(tag: string): { bg: string; text: string } {
    return tagColors[tag] ?? { bg: '#f1ede3', text: '#8a8172' };
}
</script>

<template>
    <Head title="Today" />

    <div class="mx-auto w-full max-w-[1180px] px-[30px] pb-[46px] pt-6">

        <!-- Hero -->
        <div
            class="mb-5 w-full rounded-[22px] px-8 py-7"
            style="
                background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%);
                box-shadow: 0 24px 40px -26px rgba(20,20,20,.7);
            "
        >
            <div class="flex items-center gap-3">
                <span class="material-symbols-rounded text-white/90" style="font-size: 32px">wb_sunny</span>
                <h1 class="font-['Bricolage_Grotesque'] text-[32px] font-extrabold text-white">
                    Good morning, {{ user.firstName }}
                </h1>
            </div>
            <p class="mt-2 text-[14px] text-white/72 pl-0.5">{{ heroSubtitle }}</p>
        </div>

        <!-- Two-column layout -->
        <div class="grid gap-5" style="grid-template-columns: 1fr 340px">

            <!-- ── Left column ── -->
            <div class="flex flex-col gap-5">

                <!-- Today's schedule -->
                <div
                    class="rounded-[22px] p-6"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                            Today's schedule
                        </h2>
                        <Link href="/calendar" class="text-[13px] font-medium" :style="{ color: 'var(--hab-accent)' }">
                            Calendar →
                        </Link>
                    </div>

                    <div v-if="todayEvents.length === 0" class="py-4 text-center text-[13px]" style="color: var(--hab-muted)">
                        Nothing scheduled today
                    </div>

                    <div v-for="(event, i) in todayEvents" :key="event.id">
                        <div
                            v-if="i > 0"
                            class="my-3 h-px"
                            style="background: var(--hab-hairline)"
                        ></div>
                        <div class="flex items-center gap-3">
                            <span class="w-16 shrink-0 text-[12px] tabular-nums" style="color: var(--hab-muted)">
                                {{ event.time }}
                            </span>
                            <span
                                class="h-2 w-2 shrink-0 rounded-full"
                                :style="{ background: event.color }"
                            ></span>
                            <span class="flex-1 text-[14px] font-medium" style="color: var(--hab-ink)">
                                {{ event.title }}
                            </span>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                                :style="{ background: tagStyle(event.tag).bg, color: tagStyle(event.tag).text }"
                            >
                                {{ event.tag }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Tasks -->
                <div
                    class="rounded-[22px] p-6"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)"
                >
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                            Tasks
                        </h2>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold"
                            style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                        >{{ undoneCount }} left</span>
                    </div>

                    <div v-for="(task, i) in localTasks" :key="task.id">
                        <div
                            v-if="i > 0"
                            class="my-2 h-px"
                            style="background: var(--hab-hairline)"
                        ></div>
                        <label class="flex cursor-pointer items-center gap-3 py-1">
                            <!-- Custom checkbox -->
                            <button
                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-[6px] border-2 transition-colors"
                                :style="task.done
                                    ? { background: 'var(--hab-accent)', borderColor: 'var(--hab-accent)' }
                                    : { background: 'transparent', borderColor: '#c3bbab' }"
                                @click="toggleTask(task)"
                                type="button"
                            >
                                <span
                                    v-if="task.done"
                                    class="material-symbols-rounded filled text-white"
                                    style="font-size: 13px"
                                >check</span>
                            </button>
                            <span
                                class="text-[14px]"
                                :style="task.done
                                    ? { textDecoration: 'line-through', color: 'var(--hab-faint)' }
                                    : { color: 'var(--hab-ink)' }"
                            >{{ task.label }}</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- ── Right column ── -->
            <div class="flex flex-col gap-5">

                <!-- Money snapshot -->
                <Link
                    href="/budgets"
                    class="block rounded-[22px] p-5 transition-opacity hover:opacity-90"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">Money</h2>
                        <span class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)">chevron_right</span>
                    </div>
                    <p class="mb-1 text-[12px]" style="color: var(--hab-muted)">Safe to spend this month</p>
                    <p class="mb-3 font-['Bricolage_Grotesque'] text-[28px] font-extrabold" style="color: var(--hab-ink)">
                        {{ formatPeso(moneySummary.safeToSpend) }}
                    </p>
                    <!-- Progress bar -->
                    <div class="h-[9px] w-full rounded-full" style="background: var(--hab-progress-track)">
                        <div
                            class="hab-progress-fill h-full rounded-full"
                            :style="{ width: spentPct + '%', background: 'linear-gradient(90deg,#ffd27a,#ffe9a8)' }"
                        ></div>
                    </div>
                </Link>

                <!-- Habits -->
                <div
                    class="rounded-[22px] p-5"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)"
                >
                    <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">Habits</h2>

                    <div v-for="(habit, i) in habits.slice(0, 3)" :key="habit.id">
                        <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>
                        <div class="flex items-center gap-3">
                            <!-- Conic-gradient ring -->
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                                :style="{
                                    background: `conic-gradient(${habit.color} ${habit.pct}%, #efe9dc ${habit.pct}%)`,
                                }"
                            >
                                <div
                                    class="flex h-7 w-7 items-center justify-center rounded-full"
                                    style="background: var(--hab-card-bg)"
                                >
                                    <span class="material-symbols-rounded" :style="{ fontSize: '14px', color: habit.color }">
                                        {{ habit.icon }}
                                    </span>
                                </div>
                            </div>
                            <span class="flex-1 text-[13px] font-medium" style="color: var(--hab-ink)">{{ habit.name }}</span>
                            <span
                                v-if="habit.streak > 0"
                                class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                            >{{ habit.streak }}d</span>
                            <span class="text-[13px] font-semibold" :style="{ color: habit.color }">
                                {{ habit.pct }}%
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Health -->
                <div
                    class="rounded-[22px] p-5"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)"
                >
                    <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">Health</h2>

                    <div v-for="(metric, i) in healthMetrics" :key="metric.name">
                        <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>
                        <div class="flex items-center gap-3">
                            <!-- Icon tile -->
                            <div
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-[12px]"
                                :style="{ background: metric.color + '22' }"
                            >
                                <span class="material-symbols-rounded" :style="{ fontSize: '18px', color: metric.color }">
                                    {{ metric.icon }}
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-1.5">
                                    <span class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                                        {{ metric.value }}
                                    </span>
                                    <span class="text-[12px]" style="color: var(--hab-muted)">{{ metric.sub }}</span>
                                </div>
                                <div class="mt-1.5 h-[9px] w-full rounded-full" style="background: var(--hab-progress-track)">
                                    <div
                                        class="hab-progress-fill h-full rounded-full"
                                        :style="{ width: metric.pct + '%', background: metric.color }"
                                    ></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- On this day -->
                <div
                    class="rounded-[22px] p-5"
                    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)"
                >
                    <h2 class="mb-3 font-['Bricolage_Grotesque'] text-[16px] font-bold" style="color: var(--hab-ink)">On this day</h2>
                    <!-- Photo placeholder -->
                    <div
                        class="flex w-full items-center justify-center rounded-[16px]"
                        style="aspect-ratio: 16/9; background: #e8e3d8"
                    >
                        <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">photo_camera</span>
                    </div>
                    <p class="mt-2 text-[12px]" style="color: var(--hab-muted)">3 years ago · Batangas trip</p>
                </div>

            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Verify Today page visually**

Visit `https://habitat.test/today`. Confirm:
- Gradient hero with "Good morning, Elle"
- Today's 3 events listed with colored dots and tag pills
- 4 tasks with checkboxes (3 unchecked, 1 checked with strikethrough)
- Money snapshot showing ₱1,230
- Habits with conic-gradient rings
- Health bars for Steps + Sleep
- "On this day" placeholder

- [ ] **Step 3: Commit**

```bash
git add resources/js/pages/Today.vue
git commit -m "feat: build Today page with hero, schedule, tasks, money, habits, and health"
```

---

## Task 10: Task Toggle Endpoint

**Files:**
- Create: `app/Http/Controllers/TaskController.php`
- Create: `tests/Feature/TaskToggleTest.php`

**Goal:** PATCH `/tasks/{task}` flips the `done` field. Route already exists from Task 5.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TaskToggleTest.php`:

```php
<?php

use App\Models\Task;
use App\Models\User;

test('task can be marked done', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $task = Task::where('user_id', $user->id)->where('group', 'today')->where('done', false)->first();

    $this->actingAs($user)
        ->patch("/tasks/{$task->id}", ['done' => true])
        ->assertOk();

    expect($task->fresh()->done)->toBeTrue();
});

test('task can be marked undone', function (): void {
    $user = User::where('email', 'elle@example.com')->first();
    $task = Task::where('user_id', $user->id)->where('done', true)->first();

    $this->actingAs($user)
        ->patch("/tasks/{$task->id}", ['done' => false])
        ->assertOk();

    expect($task->fresh()->done)->toBeFalse();
});

test('user cannot toggle another users task', function (): void {
    $otherUser = User::factory()->create();
    $user = User::where('email', 'elle@example.com')->first();
    $task = Task::where('user_id', $user->id)->first();

    $this->actingAs($otherUser)
        ->patch("/tasks/{$task->id}", ['done' => true])
        ->assertForbidden();
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php artisan test --compact --filter=TaskToggleTest
```

Expected: FAIL — controller doesn't exist yet.

- [ ] **Step 3: Create `TaskController`**

```bash
php artisan make:controller TaskController --no-interaction
```

Fill in `app/Http/Controllers/TaskController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function update(Request $request, Task $task): JsonResponse
    {
        abort_if($task->user_id !== $request->user()->id, 403);

        $validated = $request->validate(['done' => ['required', 'boolean']]);

        $task->update($validated);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=TaskToggleTest
```

Expected: All 3 pass.

- [ ] **Step 5: Test the interaction in browser**

Visit `https://habitat.test/today`. Click an unchecked task checkbox. Confirm:
- Checkbox fills with accent color immediately (optimistic)
- Label gets strikethrough
- "N left" count decrements
- No page reload

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TaskController.php tests/Feature/TaskToggleTest.php
git commit -m "feat: add task toggle endpoint with authorization"
```

---

## Task 11: Final Verification

**Goal:** Run all tests together; do a full visual pass of the app.

- [ ] **Step 1: Run all tests**

```bash
php artisan test --compact
```

Expected: All tests pass, 0 failures.

- [ ] **Step 2: Visual checklist**

Visit `https://habitat.test/today` and verify:
- [ ] Sidebar: dark themed, "Habitat" wordmark, "Today" item highlighted in ocean blue
- [ ] Sidebar: all 3 sections (DAY, LIFE, MONEY) with 16 nav items
- [ ] Sidebar: user tile at bottom shows "Elle Domingo" + "Free plan"
- [ ] Header: breadcrumb shows `wb_sunny Day / Today`
- [ ] Header: search box, notification bell (red dot), "Log spending" button
- [ ] Hero: gradient background, "Good morning, Elle", dynamic subtitle
- [ ] Schedule: 3 events with colored dots and tag pills
- [ ] Tasks: 3 unchecked + 1 checked with strikethrough; "3 left" badge
- [ ] Money card: "₱1,230" with amber progress bar
- [ ] Habits: 3 rings with conic-gradient fill
- [ ] Health: Steps + Sleep with progress bars
- [ ] "On this day": placeholder image with caption
- [ ] Navigate to `/habits` → breadcrumb updates to `interests Life / Habits`, stub shows "Coming soon"
- [ ] Task checkbox: clicking toggles done state optimistically

- [ ] **Step 3: Final commit**

```bash
git add -A
git commit -m "feat: Habitat Sprint 1 — shell + Today page complete"
```
