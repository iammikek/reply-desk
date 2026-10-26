<?php

namespace App\Filament\Resources\Inbounds;

use App\Clock\ReplyClock;
use App\Filament\Resources\Inbounds\Pages\ListHistory;
use App\Filament\Resources\Inbounds\Pages\ListOpenQueue;
use App\Filament\Resources\Inbounds\Pages\ViewInbound;
use App\Filament\Resources\Inbounds\RelationManagers\RepliesRelationManager;
use App\Filament\Tables\OpenRepliesTable;
use App\Models\Email;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Resources\ResourceConfiguration;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

use function Filament\Support\original_request;

class InboundResource extends Resource
{
    protected static ?string $model = Email::class;

    protected static ?string $slug = 'inbounds';

    protected static ?string $navigationLabel = 'Open queue';

    protected static ?string $modelLabel = 'inbound';

    protected static ?string $pluralModelLabel = 'inbounds';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = -1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('summary')
                ->label('Summary')
                ->maxLength(255),
            TextInput::make('subject')
                ->label('Subject')
                ->maxLength(255),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('client')
                ->label('Client')
                ->state(function (Email $record): string {
                    $company = $record->company?->name ?? '';
                    $contact = $record->contact?->name !== null && $record->contact->name !== ''
                        ? $record->contact->name
                        : ($record->contact?->email ?? '');

                    return trim($company.' / '.$contact, ' /');
                }),
            TextEntry::make('summary')->label('Summary'),
            TextEntry::make('at')
                ->label('Inbound')
                ->dateTime('Y-m-d H:i'),
            TextEntry::make('reply_due')
                ->label('Reply due')
                ->state(function (Email $record): string {
                    if ($record->at === null) {
                        return '';
                    }

                    $receipt = DateTimeImmutable::createFromInterface($record->at);

                    return app(ReplyClock::class)->schedule($receipt)->replyDue->format('Y-m-d H:i');
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return OpenRepliesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RepliesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpenQueue::route('/'),
            'history' => ListHistory::route('/history'),
            'view' => ViewInbound::route('/inbounds/{record}'),
        ];
    }

    /**
     * The open queue is the panel home. The paths are /, /history, and
     * /inbounds/{record}. The resource slug would prefix all three.
     */
    public static function registerRoutes(Panel $panel, ?Closure $registerPageRoutes = null, ?ResourceConfiguration $configuration = null): void
    {
        if ($registerPageRoutes !== null || $configuration !== null) {
            parent::registerRoutes($panel, $registerPageRoutes, $configuration);

            return;
        }

        Route::name('resources.'.static::getRelativeRouteName($panel).'.')
            ->middleware(static::getRouteMiddleware($panel))
            ->withoutMiddleware(static::getWithoutRouteMiddleware($panel))
            ->group(function () use ($panel): void {
                foreach (static::getPages() as $name => $page) {
                    $page->registerRoute($panel)?->name($name);
                }
            });
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make('Open queue')
                ->icon(Heroicon::OutlinedInbox)
                ->sort(-1)
                ->isActiveWhen(fn (): bool => original_request()->routeIs(static::getRouteBaseName().'.index'))
                ->url(static::getUrl('index')),
            NavigationItem::make('History')
                ->icon(Heroicon::OutlinedClock)
                ->sort(0)
                ->isActiveWhen(fn (): bool => original_request()->routeIs(static::getRouteBaseName().'.history'))
                ->url(static::getUrl('history')),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
