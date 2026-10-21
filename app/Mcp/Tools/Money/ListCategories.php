<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Ledger;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListCategories extends MoneyTool
{
    protected string $name = 'list_categories';

    protected string $description = 'List every category an expense can have: the built-in ones (pass as category) and the user\'s own custom ones (pass their id as customCategoryId). Use this before recording or editing an expense in a category that is not built in.';

    public function handle(Request $request, Ledger $ledger): ResponseFactory
    {
        $custom = $this->userFor($request)->customCategories()->orderBy('name')->get()
            ->map(fn ($category): array => ['id' => $category->id, 'name' => $category->name])
            ->values()
            ->all();

        return $this->respond(count($custom).' custom categor'.(count($custom) === 1 ? 'y' : 'ies').' besides the built-in ones.', [
            'builtInCategories' => $ledger->expenseCategories(),
            'customCategories' => $custom,
        ]);
    }
}
