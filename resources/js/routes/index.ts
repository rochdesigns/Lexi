import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../wayfinder'
/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
* @route '/login'
*/
export const login = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(options),
    method: 'get',
})

login.definition = {
    methods: ["get","head"],
    url: '/login',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
* @route '/login'
*/
login.url = (options?: RouteQueryOptions) => {
    return login.definition.url + queryParams(options)
}

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
* @route '/login'
*/
login.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: login.url(options),
    method: 'get',
})

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
* @route '/login'
*/
login.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: login.url(options),
    method: 'head',
})

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
* @route '/login'
*/
const loginForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: login.url(options),
    method: 'get',
})

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
* @route '/login'
*/
loginForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: login.url(options),
    method: 'get',
})

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
* @route '/login'
*/
loginForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: login.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

login.form = loginForm

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
export const logout = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(options),
    method: 'post',
})

logout.definition = {
    methods: ["post"],
    url: '/logout',
} satisfies RouteDefinition<["post"]>

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
logout.url = (options?: RouteQueryOptions) => {
    return logout.definition.url + queryParams(options)
}

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
logout.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: logout.url(options),
    method: 'post',
})

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
const logoutForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: logout.url(options),
    method: 'post',
})

/**
* @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
* @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
* @route '/logout'
*/
logoutForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: logout.url(options),
    method: 'post',
})

logout.form = logoutForm

/**
* @see \Laravel\Fortify\Http\Controllers\RegisteredUserController::register
* @see vendor/laravel/fortify/src/Http/Controllers/RegisteredUserController.php:41
* @route '/register'
*/
export const register = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: register.url(options),
    method: 'get',
})

register.definition = {
    methods: ["get","head"],
    url: '/register',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Laravel\Fortify\Http\Controllers\RegisteredUserController::register
* @see vendor/laravel/fortify/src/Http/Controllers/RegisteredUserController.php:41
* @route '/register'
*/
register.url = (options?: RouteQueryOptions) => {
    return register.definition.url + queryParams(options)
}

/**
* @see \Laravel\Fortify\Http\Controllers\RegisteredUserController::register
* @see vendor/laravel/fortify/src/Http/Controllers/RegisteredUserController.php:41
* @route '/register'
*/
register.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: register.url(options),
    method: 'get',
})

/**
* @see \Laravel\Fortify\Http\Controllers\RegisteredUserController::register
* @see vendor/laravel/fortify/src/Http/Controllers/RegisteredUserController.php:41
* @route '/register'
*/
register.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: register.url(options),
    method: 'head',
})

/**
* @see \Laravel\Fortify\Http\Controllers\RegisteredUserController::register
* @see vendor/laravel/fortify/src/Http/Controllers/RegisteredUserController.php:41
* @route '/register'
*/
const registerForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: register.url(options),
    method: 'get',
})

/**
* @see \Laravel\Fortify\Http\Controllers\RegisteredUserController::register
* @see vendor/laravel/fortify/src/Http/Controllers/RegisteredUserController.php:41
* @route '/register'
*/
registerForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: register.url(options),
    method: 'get',
})

/**
* @see \Laravel\Fortify\Http\Controllers\RegisteredUserController::register
* @see vendor/laravel/fortify/src/Http/Controllers/RegisteredUserController.php:41
* @route '/register'
*/
registerForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: register.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

register.form = registerForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
export const home = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: home.url(options),
    method: 'get',
})

home.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
home.url = (options?: RouteQueryOptions) => {
    return home.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
home.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: home.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
home.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: home.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
const homeForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: home.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
homeForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: home.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
homeForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: home.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

home.form = homeForm

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
export const today = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: today.url(options),
    method: 'get',
})

today.definition = {
    methods: ["get","head"],
    url: '/today',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
today.url = (options?: RouteQueryOptions) => {
    return today.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
today.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: today.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
today.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: today.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
const todayForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: today.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
todayForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: today.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TodayController::__invoke
* @see app/Http/Controllers/TodayController.php:18
* @route '/today'
*/
todayForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: today.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

today.form = todayForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
export const health = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

health.definition = {
    methods: ["get","head"],
    url: '/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
health.url = (options?: RouteQueryOptions) => {
    return health.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
health.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: health.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
health.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: health.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
const healthForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: health.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
healthForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: health.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
healthForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: health.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

health.form = healthForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
export const habits = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: habits.url(options),
    method: 'get',
})

habits.definition = {
    methods: ["get","head"],
    url: '/habits',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
habits.url = (options?: RouteQueryOptions) => {
    return habits.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
habits.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: habits.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
habits.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: habits.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
const habitsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: habits.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
habitsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: habits.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
habitsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: habits.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

habits.form = habitsForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
export const notes = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: notes.url(options),
    method: 'get',
})

notes.definition = {
    methods: ["get","head"],
    url: '/notes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
notes.url = (options?: RouteQueryOptions) => {
    return notes.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
notes.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: notes.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
notes.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: notes.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
const notesForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: notes.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
notesForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: notes.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
notesForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: notes.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

notes.form = notesForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
export const journal = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: journal.url(options),
    method: 'get',
})

journal.definition = {
    methods: ["get","head"],
    url: '/journal',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
journal.url = (options?: RouteQueryOptions) => {
    return journal.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
journal.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: journal.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
journal.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: journal.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
const journalForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: journal.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
journalForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: journal.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
journalForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: journal.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

journal.form = journalForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
export const meals = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: meals.url(options),
    method: 'get',
})

meals.definition = {
    methods: ["get","head"],
    url: '/meals',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
meals.url = (options?: RouteQueryOptions) => {
    return meals.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
meals.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: meals.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
meals.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: meals.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
const mealsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: meals.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
mealsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: meals.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
mealsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: meals.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

meals.form = mealsForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
export const groceries = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: groceries.url(options),
    method: 'get',
})

groceries.definition = {
    methods: ["get","head"],
    url: '/groceries',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
groceries.url = (options?: RouteQueryOptions) => {
    return groceries.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
groceries.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: groceries.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
groceries.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: groceries.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
const groceriesForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: groceries.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
groceriesForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: groceries.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
groceriesForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: groceries.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

groceries.form = groceriesForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
export const pets = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: pets.url(options),
    method: 'get',
})

pets.definition = {
    methods: ["get","head"],
    url: '/pets',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
pets.url = (options?: RouteQueryOptions) => {
    return pets.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
pets.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: pets.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
pets.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: pets.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
const petsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: pets.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
petsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: pets.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
petsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: pets.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

pets.form = petsForm

/**
* @see \App\Http\Controllers\GoalController::goals
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
export const goals = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: goals.url(options),
    method: 'get',
})

goals.definition = {
    methods: ["get","head"],
    url: '/goals',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\GoalController::goals
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
goals.url = (options?: RouteQueryOptions) => {
    return goals.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\GoalController::goals
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
goals.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: goals.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\GoalController::goals
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
goals.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: goals.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\GoalController::goals
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
const goalsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: goals.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\GoalController::goals
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
goalsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: goals.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\GoalController::goals
* @see app/Http/Controllers/GoalController.php:13
* @route '/goals'
*/
goalsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: goals.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

goals.form = goalsForm

/**
* @see \App\Http\Controllers\BudgetController::budgets
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
export const budgets = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: budgets.url(options),
    method: 'get',
})

budgets.definition = {
    methods: ["get","head"],
    url: '/budgets',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BudgetController::budgets
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
budgets.url = (options?: RouteQueryOptions) => {
    return budgets.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BudgetController::budgets
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
budgets.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: budgets.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\BudgetController::budgets
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
budgets.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: budgets.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\BudgetController::budgets
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
const budgetsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: budgets.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\BudgetController::budgets
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
budgetsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: budgets.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\BudgetController::budgets
* @see app/Http/Controllers/BudgetController.php:13
* @route '/budgets'
*/
budgetsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: budgets.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

budgets.form = budgetsForm

/**
* @see \App\Http\Controllers\SinkingFundController::setAsides
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
export const setAsides = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: setAsides.url(options),
    method: 'get',
})

setAsides.definition = {
    methods: ["get","head"],
    url: '/set-asides',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SinkingFundController::setAsides
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
setAsides.url = (options?: RouteQueryOptions) => {
    return setAsides.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\SinkingFundController::setAsides
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
setAsides.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: setAsides.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SinkingFundController::setAsides
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
setAsides.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: setAsides.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\SinkingFundController::setAsides
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
const setAsidesForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: setAsides.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SinkingFundController::setAsides
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
setAsidesForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: setAsides.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SinkingFundController::setAsides
* @see app/Http/Controllers/SinkingFundController.php:14
* @route '/set-asides'
*/
setAsidesForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: setAsides.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

setAsides.form = setAsidesForm

/**
* @see \App\Http\Controllers\DebtController::debt
* @see app/Http/Controllers/DebtController.php:17
* @route '/debt'
*/
export const debt = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: debt.url(options),
    method: 'get',
})

debt.definition = {
    methods: ["get","head"],
    url: '/debt',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\DebtController::debt
* @see app/Http/Controllers/DebtController.php:17
* @route '/debt'
*/
debt.url = (options?: RouteQueryOptions) => {
    return debt.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\DebtController::debt
* @see app/Http/Controllers/DebtController.php:17
* @route '/debt'
*/
debt.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: debt.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\DebtController::debt
* @see app/Http/Controllers/DebtController.php:17
* @route '/debt'
*/
debt.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: debt.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\DebtController::debt
* @see app/Http/Controllers/DebtController.php:17
* @route '/debt'
*/
const debtForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: debt.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\DebtController::debt
* @see app/Http/Controllers/DebtController.php:17
* @route '/debt'
*/
debtForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: debt.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\DebtController::debt
* @see app/Http/Controllers/DebtController.php:17
* @route '/debt'
*/
debtForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: debt.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

debt.form = debtForm

/**
* @see \App\Http\Controllers\ReportController::reports
* @see app/Http/Controllers/ReportController.php:13
* @route '/reports'
*/
export const reports = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: reports.url(options),
    method: 'get',
})

reports.definition = {
    methods: ["get","head"],
    url: '/reports',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ReportController::reports
* @see app/Http/Controllers/ReportController.php:13
* @route '/reports'
*/
reports.url = (options?: RouteQueryOptions) => {
    return reports.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ReportController::reports
* @see app/Http/Controllers/ReportController.php:13
* @route '/reports'
*/
reports.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: reports.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ReportController::reports
* @see app/Http/Controllers/ReportController.php:13
* @route '/reports'
*/
reports.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: reports.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\ReportController::reports
* @see app/Http/Controllers/ReportController.php:13
* @route '/reports'
*/
const reportsForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: reports.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ReportController::reports
* @see app/Http/Controllers/ReportController.php:13
* @route '/reports'
*/
reportsForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: reports.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ReportController::reports
* @see app/Http/Controllers/ReportController.php:13
* @route '/reports'
*/
reportsForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: reports.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

reports.form = reportsForm

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::trajectory
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
export const trajectory = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: trajectory.url(options),
    method: 'get',
})

trajectory.definition = {
    methods: ["get","head"],
    url: '/trajectory',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::trajectory
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
trajectory.url = (options?: RouteQueryOptions) => {
    return trajectory.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::trajectory
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
trajectory.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: trajectory.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::trajectory
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
trajectory.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: trajectory.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::trajectory
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
const trajectoryForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: trajectory.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::trajectory
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
trajectoryForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: trajectory.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\FinanceTrajectoryController::trajectory
* @see app/Http/Controllers/FinanceTrajectoryController.php:14
* @route '/trajectory'
*/
trajectoryForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: trajectory.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

trajectory.form = trajectoryForm

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
export const photos = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: photos.url(options),
    method: 'get',
})

photos.definition = {
    methods: ["get","head"],
    url: '/photos',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
photos.url = (options?: RouteQueryOptions) => {
    return photos.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
photos.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: photos.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
photos.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: photos.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
const photosForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: photos.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
photosForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: photos.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
photosForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: photos.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

photos.form = photosForm

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
export const dashboard = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dashboard.url(options),
    method: 'get',
})

dashboard.definition = {
    methods: ["get","head","post","put","patch","delete","options"],
    url: '/dashboard',
} satisfies RouteDefinition<["get","head","post","put","patch","delete","options"]>

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.url = (options?: RouteQueryOptions) => {
    return dashboard.definition.url + queryParams(options)
}

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: dashboard.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: dashboard.url(options),
    method: 'head',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: dashboard.url(options),
    method: 'post',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: dashboard.url(options),
    method: 'put',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.patch = (options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: dashboard.url(options),
    method: 'patch',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: dashboard.url(options),
    method: 'delete',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboard.options = (options?: RouteQueryOptions): RouteDefinition<'options'> => ({
    url: dashboard.url(options),
    method: 'options',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
const dashboardForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: dashboard.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboardForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: dashboard.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboardForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: dashboard.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboardForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: dashboard.url(options),
    method: 'post',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboardForm.put = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: dashboard.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PUT',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboardForm.patch = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: dashboard.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'PATCH',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboardForm.delete = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: dashboard.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'DELETE',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'post',
})

/**
* @see \Illuminate\Routing\RedirectController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/RedirectController.php:19
* @route '/dashboard'
*/
dashboardForm.options = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: dashboard.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'OPTIONS',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

dashboard.form = dashboardForm
