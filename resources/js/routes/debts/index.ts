import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\DebtController::store
* @see app/Http/Controllers/DebtController.php:99
* @route '/debts'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/debts',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\DebtController::store
* @see app/Http/Controllers/DebtController.php:99
* @route '/debts'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\DebtController::store
* @see app/Http/Controllers/DebtController.php:99
* @route '/debts'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\DebtController::store
* @see app/Http/Controllers/DebtController.php:99
* @route '/debts'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\DebtController::store
* @see app/Http/Controllers/DebtController.php:99
* @route '/debts'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\DebtController::update
* @see app/Http/Controllers/DebtController.php:114
* @route '/debts/{debt}'
*/
export const update = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/debts/{debt}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\DebtController::update
* @see app/Http/Controllers/DebtController.php:114
* @route '/debts/{debt}'
*/
update.url = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { debt: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { debt: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            debt: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        debt: typeof args.debt === 'object'
        ? args.debt.id
        : args.debt,
    }

    return update.definition.url
            .replace('{debt}', parsedArgs.debt.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\DebtController::update
* @see app/Http/Controllers/DebtController.php:114
* @route '/debts/{debt}'
*/
update.patch = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\DebtController::update
* @see app/Http/Controllers/DebtController.php:114
* @route '/debts/{debt}'
*/
const updateForm = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\DebtController::update
* @see app/Http/Controllers/DebtController.php:114
* @route '/debts/{debt}'
*/
updateForm.patch = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\DebtController::destroy
* @see app/Http/Controllers/DebtController.php:220
* @route '/debts/{debt}'
*/
export const destroy = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/debts/{debt}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\DebtController::destroy
* @see app/Http/Controllers/DebtController.php:220
* @route '/debts/{debt}'
*/
destroy.url = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { debt: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { debt: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            debt: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        debt: typeof args.debt === 'object'
        ? args.debt.id
        : args.debt,
    }

    return destroy.definition.url
            .replace('{debt}', parsedArgs.debt.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\DebtController::destroy
* @see app/Http/Controllers/DebtController.php:220
* @route '/debts/{debt}'
*/
destroy.delete = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\DebtController::destroy
* @see app/Http/Controllers/DebtController.php:220
* @route '/debts/{debt}'
*/
const destroyForm = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\DebtController::destroy
* @see app/Http/Controllers/DebtController.php:220
* @route '/debts/{debt}'
*/
destroyForm.delete = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\DebtController::payment
* @see app/Http/Controllers/DebtController.php:125
* @route '/debts/{debt}/payment'
*/
export const payment = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: payment.url(args, options),
    method: 'post',
})

payment.definition = {
    methods: ["post"],
    url: '/debts/{debt}/payment',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\DebtController::payment
* @see app/Http/Controllers/DebtController.php:125
* @route '/debts/{debt}/payment'
*/
payment.url = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { debt: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { debt: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            debt: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        debt: typeof args.debt === 'object'
        ? args.debt.id
        : args.debt,
    }

    return payment.definition.url
            .replace('{debt}', parsedArgs.debt.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\DebtController::payment
* @see app/Http/Controllers/DebtController.php:125
* @route '/debts/{debt}/payment'
*/
payment.post = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: payment.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\DebtController::payment
* @see app/Http/Controllers/DebtController.php:125
* @route '/debts/{debt}/payment'
*/
const paymentForm = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: payment.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\DebtController::payment
* @see app/Http/Controllers/DebtController.php:125
* @route '/debts/{debt}/payment'
*/
paymentForm.post = (args: { debt: number | { id: number } } | [debt: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: payment.url(args, options),
    method: 'post',
})

payment.form = paymentForm

const debts = {
    store: Object.assign(store, store),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
    payment: Object.assign(payment, payment),
}

export default debts