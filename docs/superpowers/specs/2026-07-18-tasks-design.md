# Tasks Page — Design Spec

**Date:** 2026-07-18
**Status:** Approved

## Overview

A full-page task list grouped by time horizon (Today / This week / Someday), with optimistic checkbox toggling and a modal for adding new tasks.

## Backend

### Routes

```
GET  /tasks           → TaskController@index   (tasks.index)
POST /tasks           → TaskController@store   (tasks.store)
PATCH /tasks/{task}   → TaskController@update  (tasks.update) ← already exists
```

All routes sit inside the existing `auth + verified` middleware group.

### TaskController changes

**`index(Request $request): Response`**
- Fetch the auth user's tasks ordered by `done ASC, created_at ASC`
- Group into the three fixed buckets: `today`, `week`, `someday`
- Compute `leftCount` (undone count) per group server-side
- Return `Inertia::render('Tasks', ['groups' => $groups])`

**`store(Request $request): RedirectResponse`**
- Validate: `label` required string, `group` required in `['today', 'week', 'someday']`
- Create: `Task::create([...$validated, 'user_id' => $request->user()->id])`
- Return `redirect()->route('tasks.index')`

**`update`** — unchanged.

### Props shape

```ts
interface TaskGroup {
  key: 'today' | 'week' | 'someday'
  label: string          // 'Today' | 'This week' | 'Someday'
  tasks: Task[]          // done tasks sorted to bottom within group
  leftCount: number      // count of undone tasks
}
```

```ts
interface Task {
  id: number
  label: string
  group: 'today' | 'week' | 'someday'
  done: boolean
}
```

## Frontend

### `resources/js/pages/Tasks.vue`

**Layout:**
- `AppLayout` wrapper (same as Today.vue)
- Header: "Tasks" h1 + "Everything on your plate, sorted by when" subtitle left; "+ Add task" black pill button right

**Group cards** (rendered in order: Today → This week → Someday):
- Card header row: group label (left) · "X left" badge (right, hidden when `leftCount === 0`)
- Task rows separated by full-width dividers
- Each row: rounded-square checkbox + label text
- Done tasks: teal filled checkbox + strikethrough label, always sorted to the bottom of the group
- Empty group: card renders with a quiet "Nothing here yet" placeholder line

**Optimistic toggle:**
- On checkbox click, flip `task.done` locally immediately
- `router.patch(tasks.update({ task: task.id }), { done: !task.done })`
- On error: revert the local flip
- Uses Wayfinder import from `@/actions/TaskController`

**Add Task Modal:**
- Trigger: "+ Add task" button sets `showModal = true`
- Uses shadcn-vue `Dialog` / `DialogContent`
- Fields:
  - Label: `<Input>` autofocused, placeholder "What needs doing?"
  - Group: three pill toggle buttons — Today · This week · Someday — default "Today"
- Submit: `router.post(tasks.store(), { label, group })`, closes modal on success
- Cancel / ✕: sets `showModal = false`, resets form

## Testing

File: `tests/Feature/TasksTest.php`

| Test | Assertion |
|---|---|
| authenticated user can view tasks page | 200, Inertia component `Tasks`, groups prop present |
| guest is redirected from tasks index | redirect to login |
| authenticated user can add a task | task exists in DB, redirect to tasks.index |
| store validates label is required | 422 / validation error on `label` |
| store validates group is valid | 422 / validation error on `group` |
| unauthenticated store request is redirected | redirect to login |
