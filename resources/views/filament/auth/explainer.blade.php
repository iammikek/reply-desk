<section class="reply-desk-explainer">
    <p class="reply-desk-explainer-kicker">Reply desk</p>
    <h1>The open queue for practice client email.</h1>
    <ul>
        <li>See who is waiting, and when the reply is due.</li>
        <li>A draft stays on the desk. It does not stop the clock.</li>
        <li>The reply goes out from Mail. Marking it sent records that it went.</li>
        <li>Closed threads stay on History.</li>
    </ul>
    <p class="reply-desk-explainer-more"><a href="{{ route('filament.reply-desk.pages.case-study') }}">Read the case study</a></p>
</section>

<style>
    .fi-simple-layout:has(.reply-desk-explainer) {
        flex-direction: row;
        align-items: center;
        justify-content: center;
        gap: 4.5rem;
        padding-inline: 2.5rem;
        box-sizing: border-box;
    }

    .fi-simple-layout:has(.reply-desk-explainer) .fi-simple-main-ctn {
        flex-grow: 0;
        width: min(28rem, 100%);
    }

    .fi-simple-layout:has(.reply-desk-explainer) .fi-simple-main {
        margin-block: 0;
    }

    .reply-desk-explainer {
        flex: 1 1 22rem;
        max-width: 28rem;
        color: var(--gray-950);
    }

    .dark .reply-desk-explainer {
        color: var(--color-white);
    }

    .reply-desk-explainer-kicker {
        margin: 0 0 0.75rem;
        font-size: 0.8rem;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        opacity: 0.55;
    }

    .reply-desk-explainer h1 {
        margin: 0;
        font-size: 1.85rem;
        font-weight: 600;
        letter-spacing: -0.02em;
        line-height: 1.25;
    }

    .reply-desk-explainer ul {
        margin: 1.25rem 0 0;
        padding-left: 1.15rem;
        list-style: disc;
    }

    .reply-desk-explainer li {
        display: list-item;
        margin: 0.55rem 0 0;
        line-height: 1.45;
    }

    .reply-desk-explainer-more {
        margin: 1.35rem 0 0;
    }

    .reply-desk-explainer-more a {
        color: inherit;
        font-weight: 600;
    }

    @media (max-width: 56rem) {
        .fi-simple-layout:has(.reply-desk-explainer) {
            flex-direction: column;
            gap: 2rem;
            padding-block: 2.5rem;
        }

        .reply-desk-explainer {
            flex-basis: auto;
        }
    }
</style>
