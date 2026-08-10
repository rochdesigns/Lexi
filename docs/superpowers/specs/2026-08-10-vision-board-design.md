# Vision Board — Design

## Purpose

A full-width section on the Today page showing a small set of inspirational
goal images, each with a short caption (e.g. a photo of Japan with the
caption "Japan trip 2027"). Purely inspirational — not linked to budgets,
sinking funds, or the existing `Goal` (financial savings goal) model.

## Data model

New table `vision_board_items`:

| column       | type              | notes                              |
|--------------|-------------------|-------------------------------------|
| id           | bigint pk         |                                      |
| user_id      | foreign id        | cascades on delete                  |
| image_path   | string            | path on the `public` disk           |
| caption      | string(80)        | required                            |
| position     | unsigned integer  | display order, append-only in v1    |
| timestamps   |                   |                                      |

New `VisionBoardItem` model:
- `fillable`: `user_id`, `image_path`, `caption`, `position`
- `user(): BelongsTo`
- Factory for tests (`image_path` fake, `caption` a sentence, `position` sequential)

No relation to `Goal` — separate concern, separate table/model.

## Backend

`VisionBoardItemController` (web, under the existing `auth`/`verified` route
group, following the pattern of `GoalController`/`BudgetCategoryController`):

- `store(Request $request)`
  - Validates: `image` (`image`, max 5MB, mimes: jpg,jpeg,png,webp, required),
    `caption` (`required|string|max:80`)
  - Rejects (422) if the user already has 6 `vision_board_items`
  - Stores the file on the `public` disk under `vision-board/{user_id}/`
  - Creates the row at `position = current max + 1`
- `update(Request $request, VisionBoardItem $visionBoardItem)`
  - Authorizes the item belongs to the current user (404 otherwise)
  - Validates: `caption` (`sometimes|required|string|max:80`), `image`
    (`sometimes|image|max:5120|mimes:jpg,jpeg,png,webp`)
  - If a new image is provided, deletes the old file from disk after the
    new one is stored
- `destroy(VisionBoardItem $visionBoardItem)`
  - Authorizes ownership (404 otherwise)
  - Deletes the file from disk, then the row

Routes (in `routes/web.php`, inside the existing authenticated group):

```php
Route::post('/vision-board-items', [VisionBoardItemController::class, 'store'])->name('vision-board-items.store');
Route::patch('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'update'])->name('vision-board-items.update');
Route::delete('/vision-board-items/{visionBoardItem}', [VisionBoardItemController::class, 'destroy'])->name('vision-board-items.destroy');
```

`TodayController` gains a query for the current user's vision board items
ordered by `position`, passed to the `Today` Inertia page as:

```php
'visionBoardItems' => VisionBoardItem[] mapped to
    { id: number; imageUrl: string; caption: string }[]
```

`imageUrl` is resolved via `Storage::disk('public')->url($item->image_path)`.

**Environment note:** `public/storage` is not currently symlinked in this
environment. `php artisan storage:link` must be run for uploaded images to
be publicly servable — this is an operational step, not part of the feature
code, but must be done before manual verification.

## Frontend

New full-width card on `Today.vue`, placed after the existing two-column
grid (`grid grid-cols-1 gap-5 lg:grid-cols-[1fr_340px]`), matching the
existing card style (`rounded-[22px]`, `var(--hab-card-bg)`, etc.) and
header pattern (title + optional right-aligned action, like other Today
cards).

**Grid:** always renders 6 tiles regardless of how many items exist.
- `grid-cols-2` on mobile, `sm:grid-cols-3`, `lg:grid-cols-6`
- Each tile is a square-ish (`aspect-square` or similar) rounded tile

**Filled tile:** background image, bottom gradient scrim
(`linear-gradient(transparent, rgba(0,0,0,.65))`), caption text in white
overlaid at the bottom. On hover, a small pencil icon button appears
top-right that opens the edit modal for that item.

**Empty tile:** dashed border, centered "+" icon (`material-symbols-rounded`
`add`), muted color (`var(--hab-faint)`/`var(--hab-muted)`). Clicking opens
the add modal.

**Add/edit modal** (a simple centered overlay component, consistent with
other modals in the app if a shared modal component exists — otherwise a
lightweight local implementation):
- File input with image preview once a file is selected
- Caption text input (80 char limit enforced client-side too)
- Save / Cancel buttons; Delete button shown only in edit mode
- On submit: `router.post('/vision-board-items', ..., { preserveScroll: true })`
  for add, `router.patch(...)` for edit, `router.delete(...)` for delete
- Inertia validation errors (`image`, `caption`) shown inline under their
  respective fields
- Modal closes on success

No drag-to-reorder in v1. Items append in the order added (`position` is
server-assigned); deleting an item leaves a gap that the next add fills by
appending at the end (not by backfilling the gap) — six tiles is simply
"however many items exist, in `position` order, followed by empty '+'
tiles."

## Testing

`tests/Feature/VisionBoardItemTest.php` (Pest), using `Storage::fake('public')`:

- Authenticated user can upload an image + caption → 201/redirect, item
  persisted, visible in `Today` page props (`visionBoardItems`)
- Validation: image required on create; caption required, max 80 chars;
  oversized/wrong-mimetype files rejected
- Max-6 enforcement: a 7th upload attempt is rejected (422/redirect with
  errors), existing 6 items untouched
- Update: caption-only edit persists without touching `image_path`;
  replacing the image updates `image_path` and deletes the old file from
  the fake disk
- Destroy: deletes the DB row and the file from the fake disk
- Authorization: a user cannot update or delete another user's vision board
  item (404)

## Out of scope (v1)

- Linking items to financial goals/sinking funds
- Drag-to-reorder
- Target dates or any field beyond image + caption
- More than 6 items
