@php
    $statusLabel = match ($thread['status'] ?? '') {
        'replied' => 'Replied',
        'closed' => 'Closed — no reply',
        default => 'Open',
    };
    $heading = ($thread['subject'] ?? '') !== ''
        ? $thread['subject']
        : ($thread['summary'] ?? '');
@endphp

<article class="client-thread">
    <header class="client-thread__meta">
        <p class="client-thread__started">Started {{ $thread['started_at'] }}</p>
        @if ($heading !== '')
            <p class="client-thread__subject">{{ $heading }}</p>
        @endif
        <p class="client-thread__status">{{ $statusLabel }}</p>
    </header>

    <ol class="client-thread__messages">
        @foreach ($thread['events'] ?? [] as $event)
            @php
                $side = $event['side'] ?? 'system';
            @endphp
            <li class="client-thread__row client-thread__row--{{ $side }}">
                @if ($side === 'system')
                    <p class="client-thread__system">
                        <time>{{ $event['at'] }}</time>
                        <span>{{ $event['body'] ?? '' }}</span>
                    </p>
                @else
                    <div class="client-thread__bubble client-thread__bubble--{{ $side }}">
                        <time>{{ $event['at'] }}</time>
                        @if (($event['body'] ?? '') !== '')
                            <div class="client-thread__body">{{ $event['body'] }}</div>
                        @endif
                        @if (($event['attachment'] ?? '') !== '' && ($event['note_id'] ?? '') !== '')
                            <p class="client-thread__attachment">
                                <a href="{{ route('reply-desk.notes.attachment', ['note' => $event['note_id']]) }}">{{ $event['attachment'] }}</a>
                            </p>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
</article>
