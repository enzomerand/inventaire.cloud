@extends('layouts.app')

@section('title', __('My space'))

@section('heading', __('My space'))

@section('content')
    <div class="card">
        <div class="card-body">
            <div class="empty">
                <div class="empty-icon hero-icon text-primary">
                    <x-tabler-packages aria-hidden="true" />
                </div>
                <p class="empty-title">{{ __('No inventory yet') }}</p>
                <p class="empty-subtitle text-secondary">
                    {{ __('Inventories are coming soon. This is where yours will appear.') }}
                </p>
            </div>
        </div>
    </div>
@endsection
