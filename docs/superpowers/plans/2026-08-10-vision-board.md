# Vision Board Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a full-width Vision Board section to the Today page where the user can upload up to 6 goal images with captions, purely inspirational (not linked to any financial goal/sinking fund).

**Architecture:** A new `vision_board_items` table/model/controller (standard Laravel CRUD, following the existing `Goal`/`GoalController` pattern), a `TodayController` prop addition, and an inline grid + modal section added directly to `Today.vue` (matching how `Goals.vue` keeps its CRUD modal inline rather than as a separate component).

**Tech Stack:** Laravel 13 (PHP 8.4), Inertia v3 + Vue 3, Pest v4, Laravel Wayfinder, Tailwind v4, the existing shadcn-vue `Dialog` component set.

## Global Constraints

- Image upload: required on create, optional on update; validated as `image`, max 5MB (5120 KB), mimes `jpg,jpeg,png,webp`.
- Caption: required, max 80 characters.
- Max 6 items per user, enforced server-side on create.
- No drag-to-reorder in v1 — items append in `position` order (server-assigned, `max(position)+1`).
- Authorization pattern: `abort_if($item->user_id !== $request->user()->id, 403)` (matches `GoalController`), not 404.
- Business-rule validation failures (e.g. "board full") use `back()->withErrors([...])` (matches `DebtController`), not `ValidationException`.
- File uploads on PATCH must be sent as `POST` with a `_method: 'patch'` spoof field — Laravel does not support `multipart/form-data` on PUT/PATCH natively.
- Images stored on the `public` disk under `vision-board/{user_id}/`; `public/storage` must be symlinked (`php artisan storage:link`) for uploaded images to be servable.
- Route/controller actions follow the existing named-route + Wayfinder convention (`@/actions/App/Http/Controllers/VisionBoardItemController`); routes live in the existing `auth`/`verified` group in `routes/web.php`.
- No new dependencies — no JS test framework exists in this project, so the frontend task is verified manually in the browser (per project convention), not with an automated Vue test.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes and `npm run lint` after Vue changes before considering a task done.

---

### Task 1: Vision Board backend — data model, controller, routes

**Files:**
- Create: `database/migrations/2026_08_10_120000_create_vision_board_items_table.php`
- Create: `app/Models/VisionBoardItem.php`
- Create: `database/factories/VisionBoardItemFactory.php`
- Modify: `app/Models/User.php` (add `visionBoardItems()` relation)
- Create: `app/Http/Controllers/VisionBoardItemController.php`
- Modify: `routes/web.php` (add 3 routes)
- Test: `tests/Feature/VisionBoardItemTest.php`

**Interfaces:**
- Produces: `VisionBoardItem` model (`id`, `user_id`, `image_path`, `caption`, `position`, timestamps); `User::visionBoardItems(): HasMany`; routes `vision-board-items.store` (POST `/vision-board-items`), `vision-board-items.update` (PATCH `/vision-board-items/{visionBoardItem}`), `vision-board-items.destroy` (DELETE `/vision-board-items/{visionBoardItem}`). These route names/paths are what Task 2's `TodayController` query and Task 3's frontend Wayfinder imports rely on.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_board_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->string('caption', 80);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vision_board_items');
    }
};
```

Save this as `database/migrations/2026_08_10_120000_create_vision_board_items_table.php`.

- [ ] **Step 2: Create the model**

```php
<?php

namespace App\Models;

use Database\Factories\VisionBoardItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisionBoardItem extends Model
{
    /** @use HasFactory<VisionBoardItemFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'image_path', 'caption', 'position'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 3: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VisionBoardItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisionBoardItem>
 */
class VisionBoardItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'image_path' => 'vision-board/'.fake()->uuid().'.jpg',
            'caption' => fake()->sentence(3),
            'position' => 0,
        ];
    }
}
```

- [ ] **Step 4: Add the `visionBoardItems()` relation to `User`**

In `app/Models/User.php`, add this method directly after the existing `goals()` method (around line 107):

```php
    public function visionBoardItems(): HasMany
    {
        return $this->hasMany(VisionBoardItem::class);
    }
```

- [ ] **Step 5: Run the migration**

Run: `php artisan migrate`
Expected: `vision_board_items` table created, no errors.

- [ ] **Step 6: Write the failing feature tests**

Create `tests/Feature/VisionBoardItemTest.php`:

```php
<?php

use App\Models\User;
use App\Models\VisionBoardItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('store uploads an image and creates a vision board item', function (): void {
    $file = UploadedFile::fake()->image('trip.jpg');

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'Japan trip 2027',
    ])->assertRedirect();

    $item = VisionBoardItem::where('user_id', $this->user->id)->first();
    expect($item)->not->toBeNull();
    expect($item->caption)->toBe('Japan trip 2027');
    expect($item->position)->toBe(1);
    Storage::disk('public')->assertExists($item->image_path);
});

test('store validates required fields', function (): void {
    $this->post('/vision-board-items', [])->assertSessionHasErrors(['image', 'caption']);
});

test('store rejects a caption over 80 characters', function (): void {
    $file = UploadedFile::fake()->image('trip.jpg');

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => str_repeat('a', 81),
    ])->assertSessionHasErrors('caption');
});

test('store rejects non-image files', function (): void {
    $file = UploadedFile::fake()->create('trip.pdf', 100);

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'Not an image',
    ])->assertSessionHasErrors('image');
});

test('store rejects a 7th item when the board is full', function (): void {
    VisionBoardItem::factory()->for($this->user)->count(6)->create();

    $file = UploadedFile::fake()->image('trip.jpg');

    $this->post('/vision-board-items', [
        'image' => $file,
        'caption' => 'One too many',
    ])->assertSessionHasErrors('image');

    expect(VisionBoardItem::where('user_id', $this->user->id)->count())->toBe(6);
});

test('update edits the caption without touching the image', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
        'caption' => 'Old caption',
    ]);
    Storage::disk('public')->put('vision-board/original.jpg', 'fake-contents');

    $this->patch("/vision-board-items/{$item->id}", [
        'caption' => 'New caption',
    ])->assertRedirect();

    $item->refresh();
    expect($item->caption)->toBe('New caption');
    expect($item->image_path)->toBe('vision-board/original.jpg');
    Storage::disk('public')->assertExists('vision-board/original.jpg');
});

test('update replaces the image and deletes the old file', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
    ]);
    Storage::disk('public')->put('vision-board/original.jpg', 'fake-contents');

    $newFile = UploadedFile::fake()->image('new.jpg');

    $this->patch("/vision-board-items/{$item->id}", [
        'image' => $newFile,
        'caption' => $item->caption,
    ])->assertRedirect();

    $item->refresh();
    expect($item->image_path)->not->toBe('vision-board/original.jpg');
    Storage::disk('public')->assertMissing('vision-board/original.jpg');
    Storage::disk('public')->assertExists($item->image_path);
});

test('update returns 403 for another user\'s item', function (): void {
    $other = User::factory()->create();
    $item = VisionBoardItem::factory()->for($other)->create();

    $this->patch("/vision-board-items/{$item->id}", [
        'caption' => 'Hacked',
    ])->assertForbidden();
});

test('destroy deletes the item and its file', function (): void {
    $item = VisionBoardItem::factory()->for($this->user)->create([
        'image_path' => 'vision-board/original.jpg',
    ]);
    Storage::disk('public')->put('vision-board/original.jpg', 'fake-contents');

    $this->delete("/vision-board-items/{$item->id}")->assertRedirect();

    expect(VisionBoardItem::find($item->id))->toBeNull();
    Storage::disk('public')->assertMissing('vision-board/original.jpg');
});

test('destroy returns 403 for another user\'s item', function (): void {
    $other = User::factory()->create();
    $item = VisionBoardItem::factory()->for($other)->create();

    $this->delete("/vision-board-items/{$item->id}")->assertForbidden();

    expect(VisionBoardItem::find($item->id))->not->toBeNull();
});
```

- [ ] **Step 7: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=VisionBoardItemTest`
Expected: FAIL — route `/vision-board-items` not found (controller doesn't exist yet).

- [ ] **Step 8: Write the controller**

Create `app/Http/Controllers/VisionBoardItemController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\VisionBoardItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VisionBoardItemController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->visionBoardItems()->count() >= 6) {
            return back()->withErrors(['image' => 'Your vision board is full — remove an item before adding another.']);
        }

        $validated = $request->validate([
            'image' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'caption' => ['required', 'string', 'max:80'],
        ]);

        $path = $request->file('image')->store('vision-board/'.$user->id, 'public');

        $position = (int) $user->visionBoardItems()->max('position') + 1;

        $user->visionBoardItems()->create([
            'image_path' => $path,
            'caption' => $validated['caption'],
            'position' => $position,
        ]);

        return back();
    }

    public function update(Request $request, VisionBoardItem $visionBoardItem): RedirectResponse
    {
        abort_if($visionBoardItem->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'image' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'caption' => ['required', 'string', 'max:80'],
        ]);

        $update = ['caption' => $validated['caption']];

        if ($request->hasFile('image')) {
            Storage::disk('public')->delete($visionBoardItem->image_path);
            $update['image_path'] = $request->file('image')->store('vision-board/'.$visionBoardItem->user_id, 'public');
        }

        $visionBoardItem->update($update);

        return back();
    }

    public function destroy(Request $request, VisionBoardItem $visionBoardItem): RedirectResponse
    {
        abort_if($visionBoardItem->user_id !== $request->user()->id, 403);

        Storage::disk('public')->delete($visionBoardItem->image_path);
        $visionBoardItem->delete();

        return back();
    }
}
```

- [ ] **Step 9: Add the routes**

In `routes/web.php`, add the import near the other controller imports (alphabetical, after `use App\Http\Controllers\TransactionController;`):

```php
use App\Http\Controllers\VisionBoardItemController;
```

Then add these three routes directly after the `/today` route (after line 23, before the `/tasks` routes):

```php
    Route::post('/vision-board-items', [VisionBoardItemController::class, 'store'])->name('vision-board-items.store');
    Route::patch('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'update'])->name('vision-board-items.update');
    Route::delete('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'destroy'])->name('vision-board-items.destroy');
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=VisionBoardItemTest`
Expected: PASS — all 9 tests green.

- [ ] **Step 11: Symlink the public storage disk**

Run: `php artisan storage:link`
Expected: `public/storage` created as a symlink to `storage/app/public`. This is required for uploaded images to be servable in the browser (Task 3's manual verification depends on this).

- [ ] **Step 12: Format and commit**

Run: `vendor/bin/pint --dirty --format agent`

```bash
git add database/migrations/2026_08_10_120000_create_vision_board_items_table.php app/Models/VisionBoardItem.php database/factories/VisionBoardItemFactory.php app/Models/User.php app/Http/Controllers/VisionBoardItemController.php routes/web.php tests/Feature/VisionBoardItemTest.php
git commit -m "feat: add Vision Board data model and CRUD backend"
```

---

### Task 2: Wire vision board items into the Today page

**Files:**
- Modify: `app/Http/Controllers/TodayController.php`
- Test: `tests/Feature/TodayPageTest.php`

**Interfaces:**
- Consumes: `User::visionBoardItems()` (Task 1), `VisionBoardItem` model fields `image_path`, `caption`, `position`.
- Produces: `Today` Inertia page prop `visionBoardItems: { id: number; imageUrl: string; caption: string }[]`, ordered by `position` ascending. This is the exact shape Task 3's Vue types must match.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/TodayPageTest.php` (add `use App\Models\VisionBoardItem;` to the imports at the top, then add this test):

```php
test('today page includes vision board items ordered by position', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    VisionBoardItem::factory()->for($user)->create(['caption' => 'Second', 'position' => 2]);
    VisionBoardItem::factory()->for($user)->create(['caption' => 'First', 'position' => 1]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->where('visionBoardItems.0.caption', 'First')
            ->where('visionBoardItems.1.caption', 'Second')
        );
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TodayPageTest`
Expected: FAIL — `visionBoardItems.0.caption` prop path not found (prop doesn't exist yet).

- [ ] **Step 3: Add the query and prop to `TodayController`**

In `app/Http/Controllers/TodayController.php`, add the import:

```php
use App\Models\VisionBoardItem;
use Illuminate\Support\Facades\Storage;
```

Add this query after the `$healthMetrics` query (before `$firstName = ...`):

```php
        $visionBoardItems = VisionBoardItem::where('user_id', $user->id)
            ->orderBy('position')
            ->get()
            ->map(fn (VisionBoardItem $item): array => [
                'id' => $item->id,
                'imageUrl' => Storage::disk('public')->url($item->image_path),
                'caption' => $item->caption,
            ]);
```

Add `'visionBoardItems' => $visionBoardItems,` to the `Inertia::render('Today', [...])` array, after `'healthMetrics' => $healthMetrics,`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=TodayPageTest`
Expected: PASS — all `TodayPageTest` tests green (including the pre-existing ones).

- [ ] **Step 5: Format and commit**

Run: `vendor/bin/pint --dirty --format agent`

```bash
git add app/Http/Controllers/TodayController.php tests/Feature/TodayPageTest.php
git commit -m "feat: expose vision board items on the Today page"
```

---

### Task 3: Vision Board section on the Today page

**Files:**
- Modify: `resources/js/pages/Today.vue`

**Interfaces:**
- Consumes: `visionBoardItems: { id: number; imageUrl: string; caption: string }[]` prop (Task 2); Wayfinder-generated `store`, `update`, `destroy` functions from `@/actions/App/Http/Controllers/VisionBoardItemController` (generated from Task 1's routes).
- Produces: nothing consumed elsewhere — this is the final, user-facing task.

- [ ] **Step 1: Regenerate Wayfinder actions**

Run: `php artisan wayfinder:generate`
Expected: `resources/js/actions/App/Http/Controllers/VisionBoardItemController.ts` is created (gitignored, generated file — confirm with `ls resources/js/actions/App/Http/Controllers/VisionBoardItemController.ts`).

- [ ] **Step 2: Add the prop type and imports**

In `resources/js/pages/Today.vue`, update the `<script setup>` imports (top of file) to:

```ts
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    store as storeVisionBoardItem,
    update as updateVisionBoardItem,
    destroy as destroyVisionBoardItem,
} from '@/actions/App/Http/Controllers/VisionBoardItemController';
import type { HabitatTheme } from '@/types';
```

Add this type alongside the other type aliases (after `type HealthMetric = ...`):

```ts
type VisionBoardItem = { id: number; imageUrl: string; caption: string };
```

Add `visionBoardItems: VisionBoardItem[];` to the `defineProps<{ ... }>()` block, after `healthMetrics: HealthMetric[];`.

- [ ] **Step 3: Add the vision board script logic**

Add this block at the end of `<script setup>`, after the existing `formatTime12h` function:

```ts
// Vision board
const visionModalOpen = ref(false);
const editingVisionItem = ref<VisionBoardItem | null>(null);
const visionImagePreview = ref<string | null>(null);

const visionForm = useForm<{ image: File | null; caption: string }>({
    image: null,
    caption: '',
});

const emptyVisionSlots = computed(() => Math.max(0, 6 - props.visionBoardItems.length));

function openAddVisionItem(): void {
    editingVisionItem.value = null;
    visionForm.reset();
    visionForm.clearErrors();
    visionImagePreview.value = null;
    visionModalOpen.value = true;
}

function openEditVisionItem(item: VisionBoardItem): void {
    editingVisionItem.value = item;
    visionForm.reset();
    visionForm.caption = item.caption;
    visionForm.clearErrors();
    visionImagePreview.value = item.imageUrl;
    visionModalOpen.value = true;
}

function onVisionImageChange(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    visionForm.image = file;
    if (file) {
        visionImagePreview.value = URL.createObjectURL(file);
    }
}

function submitVisionForm(): void {
    if (editingVisionItem.value) {
        const itemId = editingVisionItem.value.id;
        visionForm.transform((data) => ({ ...data, _method: 'patch' }));
        visionForm.post(updateVisionBoardItem(itemId).url, {
            preserveScroll: true,
            onSuccess: () => { visionModalOpen.value = false; },
        });
    } else {
        visionForm.transform((data) => data);
        visionForm.post(storeVisionBoardItem().url, {
            preserveScroll: true,
            onSuccess: () => { visionModalOpen.value = false; visionForm.reset(); },
        });
    }
}

function deleteVisionItem(): void {
    if (!editingVisionItem.value) return;
    router.delete(destroyVisionBoardItem(editingVisionItem.value.id).url, {
        preserveScroll: true,
        onSuccess: () => { visionModalOpen.value = false; },
    });
}
```

- [ ] **Step 4: Add the vision board grid to the template**

In `resources/js/pages/Today.vue`, add this section directly after the closing `</div>` of the two-column grid (`<div class="grid grid-cols-1 gap-5 lg:grid-cols-[1fr_340px]"> ... </div>`) and before the final closing `</div>` of the page container:

```html
        <!-- Vision board -->
        <div
            class="mt-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
        >
            <div class="mb-4 flex items-center justify-between">
                <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">
                    Vision board
                </h2>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div
                    v-for="item in visionBoardItems"
                    :key="item.id"
                    class="group relative aspect-square overflow-hidden rounded-[14px]"
                >
                    <img :src="item.imageUrl" :alt="item.caption" class="h-full w-full object-cover" />
                    <div
                        class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2"
                        style="background: linear-gradient(transparent, rgba(0,0,0,.7))"
                    ></div>
                    <span class="absolute inset-x-2 bottom-2 truncate text-[12px] font-medium text-white">
                        {{ item.caption }}
                    </span>
                    <button
                        type="button"
                        class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-full opacity-0 transition-opacity group-hover:opacity-100"
                        style="background: rgba(0,0,0,.55)"
                        @click="openEditVisionItem(item)"
                    >
                        <span class="material-symbols-rounded text-white" style="font-size: 15px">edit</span>
                    </button>
                </div>

                <button
                    v-for="n in emptyVisionSlots"
                    :key="'empty-' + n"
                    type="button"
                    class="flex aspect-square items-center justify-center rounded-[14px] border-2 border-dashed transition-colors hover:opacity-80"
                    style="border-color: var(--hab-hairline)"
                    @click="openAddVisionItem"
                >
                    <span class="material-symbols-rounded" style="font-size: 26px; color: var(--hab-faint)">add</span>
                </button>
            </div>
        </div>

        <!-- Vision board modal -->
        <Dialog v-model:open="visionModalOpen">
            <DialogContent style="max-width: 400px">
                <DialogHeader>
                    <DialogTitle style="font-family: 'Bricolage Grotesque'; font-size: 18px; font-weight: 700">
                        {{ editingVisionItem ? 'Edit vision board item' : 'Add to vision board' }}
                    </DialogTitle>
                </DialogHeader>

                <div class="flex flex-col gap-4 pt-2">
                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium" style="color: var(--hab-ink)">Image</label>
                        <div
                            v-if="visionImagePreview"
                            class="mb-2 flex aspect-video w-full items-center justify-center overflow-hidden rounded-[12px]"
                            style="background: var(--hab-progress-track)"
                        >
                            <img :src="visionImagePreview" alt="Preview" class="h-full w-full object-cover" />
                        </div>
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="text-[13px]"
                            @change="onVisionImageChange"
                        />
                        <p v-if="visionForm.errors.image" class="mt-1 text-[12px]" style="color: #c0512c">
                            {{ visionForm.errors.image }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-[13px] font-medium" style="color: var(--hab-ink)">Caption</label>
                        <input
                            v-model="visionForm.caption"
                            type="text"
                            maxlength="80"
                            placeholder="e.g. Japan trip 2027"
                            class="w-full rounded-[10px] border px-3 py-2 text-[14px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)"
                        />
                        <p v-if="visionForm.errors.caption" class="mt-1 text-[12px]" style="color: #c0512c">
                            {{ visionForm.errors.caption }}
                        </p>
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        <button
                            v-if="editingVisionItem"
                            type="button"
                            class="text-[13px] font-medium"
                            style="color: #c0512c"
                            @click="deleteVisionItem"
                        >
                            Delete
                        </button>
                        <div class="ml-auto flex gap-2">
                            <button
                                type="button"
                                class="rounded-full px-4 py-2 text-[13px] font-medium"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                                @click="visionModalOpen = false"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                class="rounded-full px-4 py-2 text-[13px] font-semibold text-white"
                                style="background: var(--hab-accent)"
                                :disabled="visionForm.processing"
                                @click="submitVisionForm"
                            >
                                Save
                            </button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
```

- [ ] **Step 5: Type-check and lint**

Run: `npm run types:check`
Expected: no new type errors.

Run: `npm run lint`
Expected: no new lint errors (auto-fixable issues are fixed in place).

- [ ] **Step 6: Manually verify in the browser**

Start the dev server (`composer run dev` or ask the user if it's not already running), then in the browser:
1. Navigate to `/today`. Confirm a "Vision board" card renders below the two-column layout with 6 dashed "+" tiles (empty state).
2. Click a "+" tile. Confirm the "Add to vision board" modal opens with an empty file input and caption field.
3. Select an image file and type a caption, click Save. Confirm the modal closes and the tile now shows the uploaded image with the caption overlaid at the bottom.
4. Hover the filled tile. Confirm a pencil (edit) icon appears top-right.
5. Click the pencil icon. Confirm the "Edit vision board item" modal opens pre-filled with the existing image preview and caption, and a "Delete" button is visible.
6. Change the caption only and Save. Confirm the tile's caption updates and the image is unchanged.
7. Reopen edit, click Delete. Confirm the tile reverts to an empty "+" placeholder.
8. Repeat adding items until 6 are filled. Confirm no empty "+" tiles remain and no way to add a 7th is exposed in the UI.
9. Check the browser console (`read_console_messages`) for errors during the above flow.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/Today.vue
git commit -m "feat: add Vision Board section to the Today page"
```

---

## Plan Self-Review Notes

- **Spec coverage:** data model ✓ (Task 1), backend CRUD + validation + max-6 + auth ✓ (Task 1), Today page wiring ✓ (Task 2), full-width grid layout + empty/filled tiles + hover edit + add/edit/delete modal ✓ (Task 3), storage:link operational step ✓ (Task 1 Step 11). No spec requirement is left uncovered.
- **Type consistency:** `VisionBoardItem` frontend type (`{ id, imageUrl, caption }`) matches the exact shape produced by `TodayController`'s map in Task 2. Route names (`vision-board-items.store/update/destroy`) match what Wayfinder generates into the `VisionBoardItemController.ts` actions imported in Task 3.
- **No placeholders:** all steps contain full code, not descriptions of code.
