@php
    $items = \App\Filament\ClientThreadNav::items();
@endphp

@if ($items !== [])
    <ul class="fi-sidebar-nav-groups client-thread-nav">
        <li class="fi-sidebar-group">
            <div class="fi-sidebar-group-btn">
                <span class="fi-sidebar-group-label">Threads</span>
            </div>

            <ul class="fi-sidebar-group-items">
                @foreach ($items as $item)
                    <li class="fi-sidebar-item">
                        <a href="{{ $item['url'] }}" class="fi-sidebar-item-btn" title="{{ $item['label'] }}">
                            <span class="fi-sidebar-item-label">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </li>
    </ul>

    <style>
        .client-thread-nav {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid color-mix(in srgb, currentColor 12%, transparent);
        }

        .client-thread-nav .fi-sidebar-item-label {
            white-space: normal;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.25;
        }
    </style>
@endif
