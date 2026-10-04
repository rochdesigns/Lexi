<?php

arch('MCP code reads money only through the user relations')
    ->expect('App\Mcp')
    ->not->toUse('App\Models\Money');

arch('MCP code does not bypass Eloquent scoping with raw queries')
    ->expect('App\Mcp')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Query\Builder']);

arch('MCP tools do not write')
    ->expect('App\Mcp\Tools')
    ->not->toHaveMethods(['store', 'update', 'delete', 'create', 'save']);
