import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../wayfinder'
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
const Controller980bb49ee7ae63891f1d891d2fbcf1c9 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'get',
})

Controller980bb49ee7ae63891f1d891d2fbcf1c9.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
Controller980bb49ee7ae63891f1d891d2fbcf1c9.url = (options?: RouteQueryOptions) => {
    return Controller980bb49ee7ae63891f1d891d2fbcf1c9.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
Controller980bb49ee7ae63891f1d891d2fbcf1c9.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
Controller980bb49ee7ae63891f1d891d2fbcf1c9.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controller980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
const Controller980bb49ee7ae63891f1d891d2fbcf1c9Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
Controller980bb49ee7ae63891f1d891d2fbcf1c9Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller980bb49ee7ae63891f1d891d2fbcf1c9.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/'
*/
Controller980bb49ee7ae63891f1d891d2fbcf1c9Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller980bb49ee7ae63891f1d891d2fbcf1c9.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controller980bb49ee7ae63891f1d891d2fbcf1c9.form = Controller980bb49ee7ae63891f1d891d2fbcf1c9Form
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
const Controllerf5005a7fa854a42af9f9f8f93fe8f2c2 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'get',
})

Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.definition = {
    methods: ["get","head"],
    url: '/health',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.url = (options?: RouteQueryOptions) => {
    return Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
const Controllerf5005a7fa854a42af9f9f8f93fe8f2c2Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
Controllerf5005a7fa854a42af9f9f8f93fe8f2c2Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/health'
*/
Controllerf5005a7fa854a42af9f9f8f93fe8f2c2Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controllerf5005a7fa854a42af9f9f8f93fe8f2c2.form = Controllerf5005a7fa854a42af9f9f8f93fe8f2c2Form
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
const Controllera73348097884fe9d19ffd8d1e8ded1ad = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllera73348097884fe9d19ffd8d1e8ded1ad.url(options),
    method: 'get',
})

Controllera73348097884fe9d19ffd8d1e8ded1ad.definition = {
    methods: ["get","head"],
    url: '/habits',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
Controllera73348097884fe9d19ffd8d1e8ded1ad.url = (options?: RouteQueryOptions) => {
    return Controllera73348097884fe9d19ffd8d1e8ded1ad.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
Controllera73348097884fe9d19ffd8d1e8ded1ad.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllera73348097884fe9d19ffd8d1e8ded1ad.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
Controllera73348097884fe9d19ffd8d1e8ded1ad.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controllera73348097884fe9d19ffd8d1e8ded1ad.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
const Controllera73348097884fe9d19ffd8d1e8ded1adForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllera73348097884fe9d19ffd8d1e8ded1ad.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
Controllera73348097884fe9d19ffd8d1e8ded1adForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllera73348097884fe9d19ffd8d1e8ded1ad.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/habits'
*/
Controllera73348097884fe9d19ffd8d1e8ded1adForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllera73348097884fe9d19ffd8d1e8ded1ad.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controllera73348097884fe9d19ffd8d1e8ded1ad.form = Controllera73348097884fe9d19ffd8d1e8ded1adForm
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
const Controller6d931d725d3d817d9734c6683d9edb25 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller6d931d725d3d817d9734c6683d9edb25.url(options),
    method: 'get',
})

Controller6d931d725d3d817d9734c6683d9edb25.definition = {
    methods: ["get","head"],
    url: '/notes',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
Controller6d931d725d3d817d9734c6683d9edb25.url = (options?: RouteQueryOptions) => {
    return Controller6d931d725d3d817d9734c6683d9edb25.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
Controller6d931d725d3d817d9734c6683d9edb25.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller6d931d725d3d817d9734c6683d9edb25.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
Controller6d931d725d3d817d9734c6683d9edb25.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controller6d931d725d3d817d9734c6683d9edb25.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
const Controller6d931d725d3d817d9734c6683d9edb25Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller6d931d725d3d817d9734c6683d9edb25.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
Controller6d931d725d3d817d9734c6683d9edb25Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller6d931d725d3d817d9734c6683d9edb25.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/notes'
*/
Controller6d931d725d3d817d9734c6683d9edb25Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller6d931d725d3d817d9734c6683d9edb25.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controller6d931d725d3d817d9734c6683d9edb25.form = Controller6d931d725d3d817d9734c6683d9edb25Form
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
const Controllerab0cf61a51a9ec85dc2f83c8befd80d5 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllerab0cf61a51a9ec85dc2f83c8befd80d5.url(options),
    method: 'get',
})

Controllerab0cf61a51a9ec85dc2f83c8befd80d5.definition = {
    methods: ["get","head"],
    url: '/journal',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
Controllerab0cf61a51a9ec85dc2f83c8befd80d5.url = (options?: RouteQueryOptions) => {
    return Controllerab0cf61a51a9ec85dc2f83c8befd80d5.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
Controllerab0cf61a51a9ec85dc2f83c8befd80d5.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllerab0cf61a51a9ec85dc2f83c8befd80d5.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
Controllerab0cf61a51a9ec85dc2f83c8befd80d5.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controllerab0cf61a51a9ec85dc2f83c8befd80d5.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
const Controllerab0cf61a51a9ec85dc2f83c8befd80d5Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerab0cf61a51a9ec85dc2f83c8befd80d5.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
Controllerab0cf61a51a9ec85dc2f83c8befd80d5Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerab0cf61a51a9ec85dc2f83c8befd80d5.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/journal'
*/
Controllerab0cf61a51a9ec85dc2f83c8befd80d5Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerab0cf61a51a9ec85dc2f83c8befd80d5.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controllerab0cf61a51a9ec85dc2f83c8befd80d5.form = Controllerab0cf61a51a9ec85dc2f83c8befd80d5Form
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
const Controller57b3a6105afb17387b56dc33f17ab3eb = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller57b3a6105afb17387b56dc33f17ab3eb.url(options),
    method: 'get',
})

Controller57b3a6105afb17387b56dc33f17ab3eb.definition = {
    methods: ["get","head"],
    url: '/meals',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
Controller57b3a6105afb17387b56dc33f17ab3eb.url = (options?: RouteQueryOptions) => {
    return Controller57b3a6105afb17387b56dc33f17ab3eb.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
Controller57b3a6105afb17387b56dc33f17ab3eb.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller57b3a6105afb17387b56dc33f17ab3eb.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
Controller57b3a6105afb17387b56dc33f17ab3eb.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controller57b3a6105afb17387b56dc33f17ab3eb.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
const Controller57b3a6105afb17387b56dc33f17ab3ebForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller57b3a6105afb17387b56dc33f17ab3eb.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
Controller57b3a6105afb17387b56dc33f17ab3ebForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller57b3a6105afb17387b56dc33f17ab3eb.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/meals'
*/
Controller57b3a6105afb17387b56dc33f17ab3ebForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller57b3a6105afb17387b56dc33f17ab3eb.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controller57b3a6105afb17387b56dc33f17ab3eb.form = Controller57b3a6105afb17387b56dc33f17ab3ebForm
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
const Controller221c0d5f966949846a07e6615d76cf28 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller221c0d5f966949846a07e6615d76cf28.url(options),
    method: 'get',
})

Controller221c0d5f966949846a07e6615d76cf28.definition = {
    methods: ["get","head"],
    url: '/groceries',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
Controller221c0d5f966949846a07e6615d76cf28.url = (options?: RouteQueryOptions) => {
    return Controller221c0d5f966949846a07e6615d76cf28.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
Controller221c0d5f966949846a07e6615d76cf28.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller221c0d5f966949846a07e6615d76cf28.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
Controller221c0d5f966949846a07e6615d76cf28.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controller221c0d5f966949846a07e6615d76cf28.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
const Controller221c0d5f966949846a07e6615d76cf28Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller221c0d5f966949846a07e6615d76cf28.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
Controller221c0d5f966949846a07e6615d76cf28Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller221c0d5f966949846a07e6615d76cf28.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/groceries'
*/
Controller221c0d5f966949846a07e6615d76cf28Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller221c0d5f966949846a07e6615d76cf28.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controller221c0d5f966949846a07e6615d76cf28.form = Controller221c0d5f966949846a07e6615d76cf28Form
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
const Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.url(options),
    method: 'get',
})

Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.definition = {
    methods: ["get","head"],
    url: '/pets',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.url = (options?: RouteQueryOptions) => {
    return Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
const Controllerecd11c8bba0f2c2eefbf60a8d1daa2baForm = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
Controllerecd11c8bba0f2c2eefbf60a8d1daa2baForm.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/pets'
*/
Controllerecd11c8bba0f2c2eefbf60a8d1daa2baForm.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba.form = Controllerecd11c8bba0f2c2eefbf60a8d1daa2baForm
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
const Controller405da414799810a5d7e338e6abb1db28 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller405da414799810a5d7e338e6abb1db28.url(options),
    method: 'get',
})

Controller405da414799810a5d7e338e6abb1db28.definition = {
    methods: ["get","head"],
    url: '/photos',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
Controller405da414799810a5d7e338e6abb1db28.url = (options?: RouteQueryOptions) => {
    return Controller405da414799810a5d7e338e6abb1db28.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
Controller405da414799810a5d7e338e6abb1db28.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controller405da414799810a5d7e338e6abb1db28.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
Controller405da414799810a5d7e338e6abb1db28.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controller405da414799810a5d7e338e6abb1db28.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
const Controller405da414799810a5d7e338e6abb1db28Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller405da414799810a5d7e338e6abb1db28.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
Controller405da414799810a5d7e338e6abb1db28Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller405da414799810a5d7e338e6abb1db28.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/photos'
*/
Controller405da414799810a5d7e338e6abb1db28Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controller405da414799810a5d7e338e6abb1db28.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controller405da414799810a5d7e338e6abb1db28.form = Controller405da414799810a5d7e338e6abb1db28Form
/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/settings/appearance'
*/
const Controllere19ee86e9cf603ce1a59a1ec5d21dec5 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllere19ee86e9cf603ce1a59a1ec5d21dec5.url(options),
    method: 'get',
})

Controllere19ee86e9cf603ce1a59a1ec5d21dec5.definition = {
    methods: ["get","head"],
    url: '/settings/appearance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/settings/appearance'
*/
Controllere19ee86e9cf603ce1a59a1ec5d21dec5.url = (options?: RouteQueryOptions) => {
    return Controllere19ee86e9cf603ce1a59a1ec5d21dec5.definition.url + queryParams(options)
}

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/settings/appearance'
*/
Controllere19ee86e9cf603ce1a59a1ec5d21dec5.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: Controllere19ee86e9cf603ce1a59a1ec5d21dec5.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/settings/appearance'
*/
Controllere19ee86e9cf603ce1a59a1ec5d21dec5.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: Controllere19ee86e9cf603ce1a59a1ec5d21dec5.url(options),
    method: 'head',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/settings/appearance'
*/
const Controllere19ee86e9cf603ce1a59a1ec5d21dec5Form = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllere19ee86e9cf603ce1a59a1ec5d21dec5.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/settings/appearance'
*/
Controllere19ee86e9cf603ce1a59a1ec5d21dec5Form.get = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllere19ee86e9cf603ce1a59a1ec5d21dec5.url(options),
    method: 'get',
})

/**
* @see \Inertia\Controller::__invoke
* @see vendor/inertiajs/inertia-laravel/src/Controller.php:13
* @route '/settings/appearance'
*/
Controllere19ee86e9cf603ce1a59a1ec5d21dec5Form.head = (options?: RouteQueryOptions): RouteFormDefinition<'get'> => ({
    action: Controllere19ee86e9cf603ce1a59a1ec5d21dec5.url({
        [options?.mergeQuery ? 'mergeQuery' : 'query']: {
            _method: 'HEAD',
            ...(options?.query ?? options?.mergeQuery ?? {}),
        }
    }),
    method: 'get',
})

Controllere19ee86e9cf603ce1a59a1ec5d21dec5.form = Controllere19ee86e9cf603ce1a59a1ec5d21dec5Form

/**
* Multiple routes resolve to \Inertia\Controller::Controller, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `Controller['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
const Controller = {
    '/': Controller980bb49ee7ae63891f1d891d2fbcf1c9,
    '/health': Controllerf5005a7fa854a42af9f9f8f93fe8f2c2,
    '/habits': Controllera73348097884fe9d19ffd8d1e8ded1ad,
    '/notes': Controller6d931d725d3d817d9734c6683d9edb25,
    '/journal': Controllerab0cf61a51a9ec85dc2f83c8befd80d5,
    '/meals': Controller57b3a6105afb17387b56dc33f17ab3eb,
    '/groceries': Controller221c0d5f966949846a07e6615d76cf28,
    '/pets': Controllerecd11c8bba0f2c2eefbf60a8d1daa2ba,
    '/photos': Controller405da414799810a5d7e338e6abb1db28,
    '/settings/appearance': Controllere19ee86e9cf603ce1a59a1ec5d21dec5,
}

export default Controller