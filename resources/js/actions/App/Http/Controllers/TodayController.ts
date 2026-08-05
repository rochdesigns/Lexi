import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
const TodayController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TodayController.url(options),
    method: 'get',
})

TodayController.definition = {
    methods: ["get","head"],
    url: '/today',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
TodayController.url = (options?: RouteQueryOptions) => {
    return TodayController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
TodayController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TodayController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
TodayController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: TodayController.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
const TodayControllerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: TodayController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
TodayControllerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: TodayController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
TodayControllerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: TodayController.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

TodayController.form = TodayControllerForm

export default TodayController