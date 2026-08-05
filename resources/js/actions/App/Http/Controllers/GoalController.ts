import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\GoalController::index
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/goals',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\GoalController::index
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\GoalController::index
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\GoalController::index
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\GoalController::index
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
const indexForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\GoalController::index
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
indexForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\GoalController::index
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
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
* @see \App\Http\Controllers\GoalController::store
* @see app/Http/Controllers/GoalController.php:62
* @route '/goals'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/goals',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\GoalController::store
* @see app/Http/Controllers/GoalController.php:62
* @route '/goals'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\GoalController::store
* @see app/Http/Controllers/GoalController.php:62
* @route '/goals'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\GoalController::store
* @see app/Http/Controllers/GoalController.php:62
* @route '/goals'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\GoalController::store
* @see app/Http/Controllers/GoalController.php:62
* @route '/goals'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\GoalController::update
* @see app/Http/Controllers/GoalController.php:73
* @route '/goals/{goal}'
*/
export const update = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/goals/{goal}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\GoalController::update
* @see app/Http/Controllers/GoalController.php:73
* @route '/goals/{goal}'
*/
update.url = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { goal: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { goal: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            goal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        goal: typeof args.goal === 'object'
        ? args.goal.id
        : args.goal,
    }

    return update.definition.url
            .replace('{goal}', parsedArgs.goal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\GoalController::update
* @see app/Http/Controllers/GoalController.php:73
* @route '/goals/{goal}'
*/
update.patch = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\GoalController::update
* @see app/Http/Controllers/GoalController.php:73
* @route '/goals/{goal}'
*/
const updateForm = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\GoalController::update
* @see app/Http/Controllers/GoalController.php:73
* @route '/goals/{goal}'
*/
updateForm.patch = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\GoalController::destroy
* @see app/Http/Controllers/GoalController.php:110
* @route '/goals/{goal}'
*/
export const destroy = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/goals/{goal}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\GoalController::destroy
* @see app/Http/Controllers/GoalController.php:110
* @route '/goals/{goal}'
*/
destroy.url = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { goal: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { goal: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            goal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        goal: typeof args.goal === 'object'
        ? args.goal.id
        : args.goal,
    }

    return destroy.definition.url
            .replace('{goal}', parsedArgs.goal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\GoalController::destroy
* @see app/Http/Controllers/GoalController.php:110
* @route '/goals/{goal}'
*/
destroy.delete = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\GoalController::destroy
* @see app/Http/Controllers/GoalController.php:110
* @route '/goals/{goal}'
*/
const destroyForm = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\GoalController::destroy
* @see app/Http/Controllers/GoalController.php:110
* @route '/goals/{goal}'
*/
destroyForm.delete = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\GoalController::topUp
* @see app/Http/Controllers/GoalController.php:82
* @route '/goals/{goal}/top-up'
*/
export const topUp = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: topUp.url(args, options),
    method: 'post',
})

topUp.definition = {
    methods: ["post"],
    url: '/goals/{goal}/top-up',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\GoalController::topUp
* @see app/Http/Controllers/GoalController.php:82
* @route '/goals/{goal}/top-up'
*/
topUp.url = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { goal: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { goal: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            goal: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        goal: typeof args.goal === 'object'
        ? args.goal.id
        : args.goal,
    }

    return topUp.definition.url
            .replace('{goal}', parsedArgs.goal.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\GoalController::topUp
* @see app/Http/Controllers/GoalController.php:82
* @route '/goals/{goal}/top-up'
*/
topUp.post = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: topUp.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\GoalController::topUp
* @see app/Http/Controllers/GoalController.php:82
* @route '/goals/{goal}/top-up'
*/
const topUpForm = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: topUp.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\GoalController::topUp
* @see app/Http/Controllers/GoalController.php:82
* @route '/goals/{goal}/top-up'
*/
topUpForm.post = (args: { goal: number | { id: number } } | [goal: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: topUp.url(args, options),
    method: 'post',
})

topUp.form = topUpForm

const GoalController = { index, store, update, destroy, topUp }

export default GoalController