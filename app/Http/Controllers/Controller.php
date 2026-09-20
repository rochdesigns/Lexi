<?php

namespace App\Http\Controllers;

use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Rejects the request when the given condition holds, the same way
     * $request->validate() does. Use this instead of abort_if(..., 422, ...)
     * for business-rule checks a user can trigger: a bare HttpException has
     * no error bag, so Inertia can't surface it inline and falls back to a
     * full error page.
     */
    protected function rejectIf(bool $condition, string $message): void
    {
        if ($condition) {
            throw ValidationException::withMessages(['error' => $message]);
        }
    }
}
