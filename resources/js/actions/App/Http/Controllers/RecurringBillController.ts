import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\RecurringBillController::store
* @see app/Http/Controllers/RecurringBillController.php:13
* @route '/recurring-bills'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/recurring-bills',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\RecurringBillController::store
* @see app/Http/Controllers/RecurringBillController.php:13
* @route '/recurring-bills'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\RecurringBillController::store
* @see app/Http/Controllers/RecurringBillController.php:13
* @route '/recurring-bills'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\RecurringBillController::store
* @see app/Http/Controllers/RecurringBillController.php:13
* @route '/recurring-bills'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\RecurringBillController::store
* @see app/Http/Controllers/RecurringBillController.php:13
* @route '/recurring-bills'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\RecurringBillController::update
* @see app/Http/Controllers/RecurringBillController.php:28
* @route '/recurring-bills/{recurringBill}'
*/
export const update = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/recurring-bills/{recurringBill}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\RecurringBillController::update
* @see app/Http/Controllers/RecurringBillController.php:28
* @route '/recurring-bills/{recurringBill}'
*/
update.url = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { recurringBill: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { recurringBill: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            recurringBill: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        recurringBill: typeof args.recurringBill === 'object'
        ? args.recurringBill.id
        : args.recurringBill,
    }

    return update.definition.url
            .replace('{recurringBill}', parsedArgs.recurringBill.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\RecurringBillController::update
* @see app/Http/Controllers/RecurringBillController.php:28
* @route '/recurring-bills/{recurringBill}'
*/
update.patch = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\RecurringBillController::update
* @see app/Http/Controllers/RecurringBillController.php:28
* @route '/recurring-bills/{recurringBill}'
*/
const updateForm = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\RecurringBillController::update
* @see app/Http/Controllers/RecurringBillController.php:28
* @route '/recurring-bills/{recurringBill}'
*/
updateForm.patch = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\RecurringBillController::destroy
* @see app/Http/Controllers/RecurringBillController.php:89
* @route '/recurring-bills/{recurringBill}'
*/
export const destroy = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/recurring-bills/{recurringBill}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\RecurringBillController::destroy
* @see app/Http/Controllers/RecurringBillController.php:89
* @route '/recurring-bills/{recurringBill}'
*/
destroy.url = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { recurringBill: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { recurringBill: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            recurringBill: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        recurringBill: typeof args.recurringBill === 'object'
        ? args.recurringBill.id
        : args.recurringBill,
    }

    return destroy.definition.url
            .replace('{recurringBill}', parsedArgs.recurringBill.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\RecurringBillController::destroy
* @see app/Http/Controllers/RecurringBillController.php:89
* @route '/recurring-bills/{recurringBill}'
*/
destroy.delete = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\RecurringBillController::destroy
* @see app/Http/Controllers/RecurringBillController.php:89
* @route '/recurring-bills/{recurringBill}'
*/
const destroyForm = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\RecurringBillController::destroy
* @see app/Http/Controllers/RecurringBillController.php:89
* @route '/recurring-bills/{recurringBill}'
*/
destroyForm.delete = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\RecurringBillController::pay
* @see app/Http/Controllers/RecurringBillController.php:58
* @route '/recurring-bills/{recurringBill}/pay'
*/
export const pay = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: pay.url(args, options),
    method: 'post',
})

pay.definition = {
    methods: ["post"],
    url: '/recurring-bills/{recurringBill}/pay',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\RecurringBillController::pay
* @see app/Http/Controllers/RecurringBillController.php:58
* @route '/recurring-bills/{recurringBill}/pay'
*/
pay.url = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { recurringBill: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { recurringBill: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            recurringBill: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        recurringBill: typeof args.recurringBill === 'object'
        ? args.recurringBill.id
        : args.recurringBill,
    }

    return pay.definition.url
            .replace('{recurringBill}', parsedArgs.recurringBill.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\RecurringBillController::pay
* @see app/Http/Controllers/RecurringBillController.php:58
* @route '/recurring-bills/{recurringBill}/pay'
*/
pay.post = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: pay.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\RecurringBillController::pay
* @see app/Http/Controllers/RecurringBillController.php:58
* @route '/recurring-bills/{recurringBill}/pay'
*/
const payForm = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: pay.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\RecurringBillController::pay
* @see app/Http/Controllers/RecurringBillController.php:58
* @route '/recurring-bills/{recurringBill}/pay'
*/
payForm.post = (args: { recurringBill: number | { id: number } } | [recurringBill: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: pay.url(args, options),
    method: 'post',
})

pay.form = payForm

const RecurringBillController = { store, update, destroy, pay }

export default RecurringBillController