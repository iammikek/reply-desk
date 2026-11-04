@php
    $replies = $replies ?? [];
    $outreach = $outreach ?? [];
@endphp

<div class="client-threads">
    @if ($showClient ?? true)
        <p class="client-threads__client">{{ $client }}</p>
        @if (($email ?? '') !== '')
            <p class="client-threads__email">{{ $email }}</p>
        @endif
    @endif

    <section class="client-threads__section">
        <h2>Replies</h2>
        @if ($replies === [])
            <p class="client-threads__empty">No reply threads with this contact yet.</p>
        @else
            @foreach ($replies as $thread)
                @include('filament.partials.client-thread', ['thread' => $thread])
            @endforeach
        @endif
    </section>

    <section class="client-threads__section">
        <h2>Outreach</h2>
        @if ($outreach === [])
            <p class="client-threads__empty">No outreach threads with this contact yet.</p>
        @else
            @foreach ($outreach as $thread)
                @include('filament.partials.client-thread', ['thread' => $thread])
            @endforeach
        @endif
    </section>
</div>

<style>
    .client-threads__client {
        margin: 0;
        font-weight: 600;
    }

    .client-threads__email {
        margin: 0.15rem 0 0;
        opacity: 0.7;
    }

    .client-threads__section {
        margin-top: 1.75rem;
    }

    .client-threads__section:first-of-type {
        margin-top: 1.25rem;
    }

    .client-threads__section h2 {
        margin: 0 0 0.85rem;
        font-size: 0.85rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.65;
    }

    .client-threads__empty {
        margin: 0;
        opacity: 0.7;
    }

    .client-thread {
        margin: 0 0 1.75rem;
        padding: 0;
    }

    .client-thread:last-child {
        margin-bottom: 0;
    }

    .client-thread__meta {
        margin: 0 0 0.85rem;
        text-align: center;
    }

    .client-thread__started {
        margin: 0;
        font-size: 0.8rem;
        font-variant-numeric: tabular-nums;
        opacity: 0.65;
    }

    .client-thread__subject {
        margin: 0.25rem 0 0;
        font-weight: 600;
    }

    .client-thread__status {
        margin: 0.2rem 0 0;
        font-size: 0.75rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.55;
    }

    .client-thread__messages {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.65rem;
    }

    .client-thread__row {
        display: flex;
        width: 100%;
    }

    .client-thread__row--client {
        justify-content: flex-start;
    }

    .client-thread__row--owner {
        justify-content: flex-end;
    }

    .client-thread__row--system {
        justify-content: center;
    }

    .client-thread__bubble {
        max-width: min(36rem, 85%);
        padding: 0.65rem 0.85rem;
        border-radius: 1.1rem;
        border: 1px solid color-mix(in srgb, currentColor 12%, transparent);
    }

    .client-thread__bubble--client {
        border-bottom-left-radius: 0.25rem;
        background: color-mix(in srgb, currentColor 6%, transparent);
    }

    .client-thread__bubble--owner {
        border-bottom-right-radius: 0.25rem;
        background: color-mix(in srgb, var(--primary-500, #3b82f6) 18%, transparent);
        border-color: color-mix(in srgb, var(--primary-500, #3b82f6) 28%, transparent);
    }

    .client-thread__bubble time {
        display: block;
        font-variant-numeric: tabular-nums;
        font-size: 0.75rem;
        opacity: 0.65;
    }

    .client-thread__body {
        margin: 0.35rem 0 0;
        white-space: pre-wrap;
        line-height: 1.45;
    }

    .client-thread__attachment {
        margin: 0.4rem 0 0;
        font-size: 0.85rem;
    }

    .client-thread__attachment a {
        text-decoration: underline;
    }

    .client-thread__system {
        margin: 0.25rem 0;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: baseline;
        justify-content: center;
        font-size: 0.8rem;
        opacity: 0.65;
    }

    .client-thread__system time {
        font-variant-numeric: tabular-nums;
    }
</style>
