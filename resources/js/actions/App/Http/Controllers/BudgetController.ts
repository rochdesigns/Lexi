import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\BudgetController::index
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/budgets',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BudgetController::index
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BudgetController::index
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\BudgetController::index
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\BudgetController::index
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\BudgetController::index
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\BudgetController::index
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
indexForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

index.form = indexForm

const BudgetController = { index }

export default BudgetController