import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\SinkingFundController::index
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/set-asides',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SinkingFundController::index
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SinkingFundController::index
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SinkingFundController::index
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\SinkingFundController::index
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SinkingFundController::index
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SinkingFundController::index
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
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

/**
* @see \App\Http\Controllers\SinkingFundController::store
* @see app/Http/Controllers/SinkingFundController.php:83
* @route '/sinking-funds'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/sinking-funds',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SinkingFundController::store
* @see app/Http/Controllers/SinkingFundController.php:83
* @route '/sinking-funds'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SinkingFundController::store
* @see app/Http/Controllers/SinkingFundController.php:83
* @route '/sinking-funds'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SinkingFundController::store
* @see app/Http/Controllers/SinkingFundController.php:83
* @route '/sinking-funds'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SinkingFundController::store
* @see app/Http/Controllers/SinkingFundController.php:83
* @route '/sinking-funds'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\SinkingFundController::update
* @see app/Http/Controllers/SinkingFundController.php:94
* @route '/sinking-funds/{sinkingFund}'
*/
export const update = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/sinking-funds/{sinkingFund}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\SinkingFundController::update
* @see app/Http/Controllers/SinkingFundController.php:94
* @route '/sinking-funds/{sinkingFund}'
*/
update.url = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { sinkingFund: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { sinkingFund: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            sinkingFund: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        sinkingFund: typeof args.sinkingFund === 'object'
        ? args.sinkingFund.id
        : args.sinkingFund,
    }

    return update.definition.url
            .replace('{sinkingFund}', parsedArgs.sinkingFund.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SinkingFundController::update
* @see app/Http/Controllers/SinkingFundController.php:94
* @route '/sinking-funds/{sinkingFund}'
*/
update.patch = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\SinkingFundController::update
* @see app/Http/Controllers/SinkingFundController.php:94
* @route '/sinking-funds/{sinkingFund}'
*/
const updateForm = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SinkingFundController::update
* @see app/Http/Controllers/SinkingFundController.php:94
* @route '/sinking-funds/{sinkingFund}'
*/
updateForm.patch = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\SinkingFundController::destroy
* @see app/Http/Controllers/SinkingFundController.php:118
* @route '/sinking-funds/{sinkingFund}'
*/
export const destroy = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/sinking-funds/{sinkingFund}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\SinkingFundController::destroy
* @see app/Http/Controllers/SinkingFundController.php:118
* @route '/sinking-funds/{sinkingFund}'
*/
destroy.url = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { sinkingFund: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { sinkingFund: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            sinkingFund: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        sinkingFund: typeof args.sinkingFund === 'object'
        ? args.sinkingFund.id
        : args.sinkingFund,
    }

    return destroy.definition.url
            .replace('{sinkingFund}', parsedArgs.sinkingFund.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SinkingFundController::destroy
* @see app/Http/Controllers/SinkingFundController.php:118
* @route '/sinking-funds/{sinkingFund}'
*/
destroy.delete = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\SinkingFundController::destroy
* @see app/Http/Controllers/SinkingFundController.php:118
* @route '/sinking-funds/{sinkingFund}'
*/
const destroyForm = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SinkingFundController::destroy
* @see app/Http/Controllers/SinkingFundController.php:118
* @route '/sinking-funds/{sinkingFund}'
*/
destroyForm.delete = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

/**
* @see \App\Http\Controllers\SinkingFundController::topUp
* @see app/Http/Controllers/SinkingFundController.php:105
* @route '/sinking-funds/{sinkingFund}/top-up'
*/
export const topUp = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: topUp.url(args, options),
    method: 'post',
})

topUp.definition = {
    methods: ["post"],
    url: '/sinking-funds/{sinkingFund}/top-up',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\SinkingFundController::topUp
* @see app/Http/Controllers/SinkingFundController.php:105
* @route '/sinking-funds/{sinkingFund}/top-up'
*/
topUp.url = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { sinkingFund: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { sinkingFund: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            sinkingFund: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        sinkingFund: typeof args.sinkingFund === 'object'
        ? args.sinkingFund.id
        : args.sinkingFund,
    }

    return topUp.definition.url
            .replace('{sinkingFund}', parsedArgs.sinkingFund.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SinkingFundController::topUp
* @see app/Http/Controllers/SinkingFundController.php:105
* @route '/sinking-funds/{sinkingFund}/top-up'
*/
topUp.post = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: topUp.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SinkingFundController::topUp
* @see app/Http/Controllers/SinkingFundController.php:105
* @route '/sinking-funds/{sinkingFund}/top-up'
*/
const topUpForm = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: topUp.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\SinkingFundController::topUp
* @see app/Http/Controllers/SinkingFundController.php:105
* @route '/sinking-funds/{sinkingFund}/top-up'
*/
topUpForm.post = (args: { sinkingFund: number | { id: number } } | [sinkingFund: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: topUp.url(args, options),
    method: 'post',
})

topUp.form = topUpForm

const SinkingFundController = { index, store, update, destroy, topUp }

export default SinkingFundController