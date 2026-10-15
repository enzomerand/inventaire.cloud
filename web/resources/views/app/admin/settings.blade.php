{{--
    Administration, settings: the choices that apply to the whole instance.

    Administrators only (the "admin" gate, see routes/web.php).
--}}
@extends('layouts.app')

@section('title', __('Settings'))

@section('heading', __('Administration'))

@section('content')
    @include('app.admin.nav', ['active' => 'settings'])
@endsection
