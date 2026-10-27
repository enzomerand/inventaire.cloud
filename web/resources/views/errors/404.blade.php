{{--
    The 404 page. It says the same thing whatever made the address fail (a page that is not there, a link that expired or was withdrawn),
    so that it never tells which one it was.
--}}
@extends('errors::minimal')

@section('title', __('Not Found'))
@section('code', '404')
@section('message', __('Not Found'))
@section('hint', __('This page does not exist or is no longer available. If someone sent you this link, it may have expired.'))
