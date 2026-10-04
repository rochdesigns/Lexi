@extends('errors.layout')

@section('code', '419')
@section('title', 'Your session expired')
@section('message', 'You were away a while, so we signed you out. Log back in and pick up where you left off.')

@section('action')
    <a class="action" href="{{ route('login') }}">Log in again</a>
@endsection
