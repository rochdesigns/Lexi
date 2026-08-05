import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\BudgetCategoryController::store
* @see app/Http/Controllers/BudgetCategoryController.php:11
* @route '/budget-categories'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/budget-categories',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\BudgetCategoryController::store
* @see app/Http/Controllers/BudgetCategoryController.php:11
* @route '/budget-categories'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BudgetCategoryController::store
* @see app/Http/Controllers/BudgetCategoryController.php:11
* @route '/budget-categories'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\BudgetCategoryController::store
* @see app/Http/Controllers/BudgetCategoryController.php:11
* @route '/budget-categories'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\BudgetCategoryController::store
* @see app/Http/Controllers/BudgetCategoryController.php:11
* @route '/budget-categories'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\BudgetCategoryController::update
* @see app/Http/Controllers/BudgetCategoryController.php:20
* @route '/budget-categories/{budgetCategory}'
*/
export const update = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/budget-categories/{budgetCategory}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\BudgetCategoryController::update
* @see app/Http/Controllers/BudgetCategoryController.php:20
* @route '/budget-categories/{budgetCategory}'
*/
update.url = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { budgetCategory: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { budgetCategory: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            budgetCategory: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        budgetCategory: typeof args.budgetCategory === 'object'
        ? args.budgetCategory.id
        : args.budgetCategory,
    }

    return update.definition.url
            .replace('{budgetCategory}', parsedArgs.budgetCategory.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BudgetCategoryController::update
* @see app/Http/Controllers/BudgetCategoryController.php:20
* @route '/budget-categories/{budgetCategory}'
*/
update.patch = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\BudgetCategoryController::update
* @see app/Http/Controllers/BudgetCategoryController.php:20
* @route '/budget-categories/{budgetCategory}'
*/
const updateForm = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\BudgetCategoryController::update
* @see app/Http/Controllers/BudgetCategoryController.php:20
* @route '/budget-categories/{budgetCategory}'
*/
updateForm.patch = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

/**
* @see \App\Http\Controllers\BudgetCategoryController::destroy
* @see app/Http/Controllers/BudgetCategoryController.php:31
* @route '/budget-categories/{budgetCategory}'
*/
export const destroy = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/budget-categories/{budgetCategory}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\BudgetCategoryController::destroy
* @see app/Http/Controllers/BudgetCategoryController.php:31
* @route '/budget-categories/{budgetCategory}'
*/
destroy.url = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { budgetCategory: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { budgetCategory: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            budgetCategory: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        budgetCategory: typeof args.budgetCategory === 'object'
        ? args.budgetCategory.id
        : args.budgetCategory,
    }

    return destroy.definition.url
            .replace('{budgetCategory}', parsedArgs.budgetCategory.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BudgetCategoryController::destroy
* @see app/Http/Controllers/BudgetCategoryController.php:31
* @route '/budget-categories/{budgetCategory}'
*/
destroy.delete = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\BudgetCategoryController::destroy
* @see app/Http/Controllers/BudgetCategoryController.php:31
* @route '/budget-categories/{budgetCategory}'
*/
const destroyForm = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\BudgetCategoryController::destroy
* @see app/Http/Controllers/BudgetCategoryController.php:31
* @route '/budget-categories/{budgetCategory}'
*/
destroyForm.delete = (args: { budgetCategory: number | { id: number } } | [budgetCategory: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const BudgetCategoryController = { store, update, destroy }

export default BudgetCategoryController