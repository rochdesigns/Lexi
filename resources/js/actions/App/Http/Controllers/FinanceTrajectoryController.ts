import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\FinanceTrajectoryController::index
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/trajectory',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::index
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::index
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::index
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::index
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::index
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::index
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
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

const FinanceTrajectoryController = { index }

export default FinanceTrajectoryController