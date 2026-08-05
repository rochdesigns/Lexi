import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::edit
* @see app/Http/Controllers/Settings/BudgetCycleController.php:14
* @route '/settings/budget-cycle'
*/
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

edit.definition = {
    methods: ["get","head"],
    url: '/settings/budget-cycle',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::edit
* @see app/Http/Controllers/Settings/BudgetCycleController.php:14
* @route '/settings/budget-cycle'
*/
edit.url = (options?: RouteQueryOptions) => {
    return edit.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::edit
* @see app/Http/Controllers/Settings/BudgetCycleController.php:14
* @route '/settings/budget-cycle'
*/
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::edit
* @see app/Http/Controllers/Settings/BudgetCycleController.php:14
* @route '/settings/budget-cycle'
*/
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: edit.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::edit
* @see app/Http/Controllers/Settings/BudgetCycleController.php:14
* @route '/settings/budget-cycle'
*/
const editForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::edit
* @see app/Http/Controllers/Settings/BudgetCycleController.php:14
* @route '/settings/budget-cycle'
*/
editForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::edit
* @see app/Http/Controllers/Settings/BudgetCycleController.php:14
* @route '/settings/budget-cycle'
*/
editForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: edit.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

edit.form = editForm

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::update
* @see app/Http/Controllers/Settings/BudgetCycleController.php:24
* @route '/settings/budget-cycle'
*/
export const update = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/settings/budget-cycle',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::update
* @see app/Http/Controllers/Settings/BudgetCycleController.php:24
* @route '/settings/budget-cycle'
*/
update.url = (options?: RouteQueryOptions) => {
    return update.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::update
* @see app/Http/Controllers/Settings/BudgetCycleController.php:24
* @route '/settings/budget-cycle'
*/
update.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::update
* @see app/Http/Controllers/Settings/BudgetCycleController.php:24
* @route '/settings/budget-cycle'
*/
const updateForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Settings\BudgetCycleController::update
* @see app/Http/Controllers/Settings/BudgetCycleController.php:24
* @route '/settings/budget-cycle'
*/
updateForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

update.form = updateForm

const budgetCycle = {
    edit: Object.assign(edit, edit),
    update: Object.assign(update, update),
}

export default budgetCycle