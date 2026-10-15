{{--
    Administration, users: the accounts of the instance.

    Administrators only (the "admin" gate, see routes/web.php).
--}}
@extends('layouts.app')

@section('title', __('Users'))

@section('heading', __('Administration'))

@section('content')
    @include('app.admin.nav', ['active' => 'users'])
@endsection
