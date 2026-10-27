@php
    use App\Clock\ReplyClock;

    $clock = app(ReplyClock::class);
    $hours = $clock->hours();
    $now = now($clock->timezone());
@endphp

@unless ($hours->contains($now->toDateTimeImmutable()))
    @php
        $next = $hours->nextOpen($now->toDateTimeImmutable());
        $label = $next->format('l H:i');
    @endphp
    <div class="reply-desk-outside-hours" role="status">
        Outside practice hours. Next open {{ $label }}.
    </div>

    <style>
        .reply-desk-outside-hours {
            margin: 0 0 1rem;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid #fcd34d;
            background: #fffbeb;
            color: #78350f;
            font-size: 0.875rem;
            line-height: 1.4;
        }

        .dark .reply-desk-outside-hours {
            border-color: #b45309;
            background: #451a03;
            color: #fde68a;
        }
    </style>
@endunless
