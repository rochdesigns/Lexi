# Customizable Task Sections

**Date:** 2026-07-18  
**Status:** Approved

## Overview

Replace the three hardcoded task sections (Today, This Week, Someday) with user-managed sections. Users can add, rename, delete, and drag-to-reorder sections inline on the Tasks page.

---

## Data Model

### New table: `task_sections`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users | cascade delete |
| `label` | string(100) | section display name |
| `position` | integer | display order, 1-indexed |
| `created_at` / `updated_at` | timestamps | |

### Changes to `tasks` table

- **Add** `section_id` (FK → task_sections, `cascadeOnDelete`)
- **Drop** `group` string column

Cascade delete on `section_id` means deleting a section automatically deletes all its tasks at the DB level — no application-level loop needed.

### Default sections

When a new user registers, three sections are auto-created in `CreateNewUser`:

| label | position |
|---|---|
| Today | 1 |
| This Week | 2 |
| Someday | 3 |

---

## Backend

### New model: `TaskSection`

```php
protected $fillable = ['user_id', 'label', 'position'];
// hasMany(Task::class)
```

### Updated model: `Task`

- Swap `group` (string) for `section_id` (FK)
- Add `belongsTo(TaskSection::class)`

### New controller: `TaskSectionController`

| Method | Route | Action |
|---|---|---|
| `POST` | `/task-sections` | Create section at `max(position) + 1` |
| `PATCH` | `/task-sections/{section}` | Rename label |
| `DELETE` | `/task-sections/{section}` | Delete section (tasks cascade) |
| `POST` | `/task-sections/reorder` | Bulk-update positions from `[{id, position}]` |

All routes: `auth` + `verified` middleware. All actions: abort 403 if `section->user_id !== auth()->id()`.

### Updated `TaskController`

- **`index`**: load user's sections ordered by `position`, eager-load tasks ordered by `done` asc, `created_at` asc
- **`store`**: validate `section_id` exists and belongs to the auth user (replaces `in:today,week,someday`)
- **`update`**: unchanged — only toggles `done`

### Routes (added to `web.php` auth group)

```php
// reorder must be registered before {section} to avoid the wildcard matching "reorder"
Route::post('/task-sections', [TaskSectionController::class, 'store'])->name('task-sections.store');
Route::post('/task-sections/reorder', [TaskSectionController::class, 'reorder'])->name('task-sections.reorder');
Route::patch('/task-sections/{section}', [TaskSectionController::class, 'update'])->name('task-sections.update');
Route::delete('/task-sections/{section}', [TaskSectionController::class, 'destroy'])->name('task-sections.destroy');
```

---

## Frontend (`Tasks.vue`)

### Section header — inline controls

On hover, each section card header reveals:

- **Left**: `drag_indicator` icon (cursor: grab) — drag handle
- **Right**: pencil (rename) + trash (delete) icons

### Rename flow

1. Click pencil **or** double-click label → label replaced with `<input>`, pre-filled, auto-focused
2. `Enter` or blur with content → `PATCH /task-sections/{id}`, optimistic label update
3. `Escape` or blur with empty → cancel, restore original label

### Delete flow

1. Click trash → confirmation dialog:
   *"Delete '[Name]'? [N] tasks will also be permanently deleted."*
2. Confirm → `DELETE /task-sections/{id}`, remove section from local state

### Reorder (drag-and-drop)

- Native HTML5 drag-and-drop — no new library
- Drag handle is the `draggable` trigger on each section card
- On `drop`: reorder `localSections` array optimistically, then `POST /task-sections/reorder` with `[{id, position}]`

### Add section

- `+ Add section` button below all section cards (inline, not a modal)
- Click → new card appears with auto-focused empty input
- `Enter` or blur with content → `POST /task-sections`, append to `localSections`
- `Escape` or blur with empty → cancel, card disappears

### Add task modal

- Section picker renders from `localSections` (dynamic) instead of hardcoded `groupOptions`
- Defaults to `localSections[0]`

---

## Migrations

1. `create_task_sections_table` — creates the new table
2. `add_section_id_to_tasks_table` — for each existing user: creates the three default sections, maps existing `tasks.group` values (`today`→Today pos 1, `week`→This Week pos 2, `someday`→Someday pos 3) to the new `section_id`, then drops the `group` column

---

## Testing

- `TaskSectionController`: store, update, destroy, reorder — auth, ownership (403), validation
- `TaskController`: store validates `section_id` belongs to auth user; tasks cascade-delete with section
- Registration: new user gets 3 default sections
- Tasks page renders sections in position order
