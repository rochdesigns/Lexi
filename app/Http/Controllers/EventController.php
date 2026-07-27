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
            'recurrence_interval' => ['integer', 'min:1', 'max:99'],
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['string', Rule::in(['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])],
            'recurrence_ends_on' => ['nullable', 'date', 'after:date'],
        ]);

        $validated['recurrence_interval'] ??= 1;

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
            'recurrence_interval' => ['integer', 'min:1', 'max:99'],
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
