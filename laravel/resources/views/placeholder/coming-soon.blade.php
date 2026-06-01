@extends('layouts.app')

@section('title', $title)
@section('page-title', $title)

@section('content')
<div class="container-tight py-6">
    <div class="empty">
        <div class="empty-icon">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-muted" width="48" height="48"
                 viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none">
                @if($icon === 'activity')
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M3 12h1m8 -9v1m8 8h1m-15.4 -6.4l.7 .7m12.1 -.7l-.7 .7"/>
                <circle cx="12" cy="12" r="4"/>
                <path d="M3 21l3 -3m15 0l-3 -3"/>
                @elseif($icon === 'trending-up')
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <polyline points="3 17 9 11 13 15 21 7"/>
                <polyline points="14 7 21 7 21 14"/>
                @else
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <rect x="3" y="12" width="6" height="8" rx="1"/>
                <rect x="9" y="8" width="6" height="12" rx="1"/>
                <rect x="15" y="4" width="6" height="16" rx="1"/>
                @endif
            </svg>
        </div>
        <p class="empty-title">{{ $title }} — Coming Soon</p>
        <p class="empty-subtitle text-muted">{{ $message }}</p>
        <div class="empty-action">
            <a href="{{ route('dashboard') }}" class="btn btn-primary">
                Back to Dashboard
            </a>
        </div>
    </div>
</div>
@endsection
