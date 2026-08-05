import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\TaskSectionController::store
* @see app/Http/Controllers/TaskSectionController.php:11
* @route '/task-sections'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/task-sections',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\TaskSectionController::store
* @see app/Http/Controllers/TaskSectionController.php:11
* @route '/task-sections'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\TaskSectionController::store
* @see app/Http/Controllers/TaskSectionController.php:11
* @route '/task-sections'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\TaskSectionController::store
* @see app/Http/Controllers/TaskSectionController.php:11
* @route '/task-sections'
*/
const storeForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\TaskSectionController::store
* @see app/Http/Controllers/TaskSectionController.php:11
* @route '/task-sections'
*/
storeForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: store.url(options),
    method: 'post',
})

store.form = storeForm

/**
* @see \App\Http\Controllers\TaskSectionController::reorder
* @see app/Http/Controllers/TaskSectionController.php:49
* @route '/task-sections/reorder'
*/
export const reorder = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reorder.url(options),
    method: 'post',
})

reorder.definition = {
    methods: ["post"],
    url: '/task-sections/reorder',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\TaskSectionController::reorder
* @see app/Http/Controllers/TaskSectionController.php:49
* @route '/task-sections/reorder'
*/
reorder.url = (options?: RouteQueryOptions) => {
    return reorder.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\TaskSectionController::reorder
* @see app/Http/Controllers/TaskSectionController.php:49
* @route '/task-sections/reorder'
*/
reorder.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: reorder.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\TaskSectionController::reorder
* @see app/Http/Controllers/TaskSectionController.php:49
* @route '/task-sections/reorder'
*/
const reorderForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: reorder.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\TaskSectionController::reorder
* @see app/Http/Controllers/TaskSectionController.php:49
* @route '/task-sections/reorder'
*/
reorderForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: reorder.url(options),
    method: 'post',
})

reorder.form = reorderForm

/**
* @see \App\Http\Controllers\TaskSectionController::update
* @see app/Http/Controllers/TaskSectionController.php:26
* @route '/task-sections/{taskSection}'
*/
export const update = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/task-sections/{taskSection}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\TaskSectionController::update
* @see app/Http/Controllers/TaskSectionController.php:26
* @route '/task-sections/{taskSection}'
*/
update.url = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { taskSection: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { taskSection: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            taskSection: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        taskSection: typeof args.taskSection === 'object'
        ? args.taskSection.id
        : args.taskSection,
    }

    return update.definition.url
            .replace('{taskSection}', parsedArgs.taskSection.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\TaskSectionController::update
* @see app/Http/Controllers/TaskSectionController.php:26
* @route '/task-sections/{taskSection}'
*/
update.patch = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\TaskSectionController::update
* @see app/Http/Controllers/TaskSectionController.php:26
* @route '/task-sections/{taskSection}'
*/
const updateForm = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: update.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\TaskSectionController::update
* @see app/Http/Controllers/TaskSectionController.php:26
* @route '/task-sections/{taskSection}'
*/
updateForm.patch = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
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
* @see \App\Http\Controllers\TaskSectionController::destroy
* @see app/Http/Controllers/TaskSectionController.php:40
* @route '/task-sections/{taskSection}'
*/
export const destroy = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/task-sections/{taskSection}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\TaskSectionController::destroy
* @see app/Http/Controllers/TaskSectionController.php:40
* @route '/task-sections/{taskSection}'
*/
destroy.url = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { taskSection: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { taskSection: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            taskSection: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        taskSection: typeof args.taskSection === 'object'
        ? args.taskSection.id
        : args.taskSection,
    }

    return destroy.definition.url
            .replace('{taskSection}', parsedArgs.taskSection.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\TaskSectionController::destroy
* @see app/Http/Controllers/TaskSectionController.php:40
* @route '/task-sections/{taskSection}'
*/
destroy.delete = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\TaskSectionController::destroy
* @see app/Http/Controllers/TaskSectionController.php:40
* @route '/task-sections/{taskSection}'
*/
const destroyForm = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \App\Http\Controllers\TaskSectionController::destroy
* @see app/Http/Controllers/TaskSectionController.php:40
* @route '/task-sections/{taskSection}'
*/
destroyForm.delete = (args: { taskSection: number | { id: number } } | [taskSection: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: destroy.url(args, {
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

destroy.form = destroyForm

const taskSections = {
    store: Object.assign(store, store),
    reorder: Object.assign(reorder, reorder),
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
}

export default taskSections