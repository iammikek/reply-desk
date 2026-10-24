<?php

namespace App\Filament\Pages;

use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\DeskException;
use App\Desk\LondonTime;
use App\Desk\Notes\NoteFile;
use App\Filament\Schemas\LogInboundForm;
use App\Filament\Tables\OpenRepliesTable;
use App\Filament\Widgets\OpenQueueOverview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OpenQueue extends Page implements HasTable
{
    use InteractsWithTable {
        getTableRecord as resolveOpenRecord;
    }

    protected static ?string $navigationLabel = 'Open queue';

    protected static ?string $title = 'Open queue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = -1;

    protected static string $routePath = '/';

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    protected Width|string|null $maxContentWidth = Width::Full;

    public function table(Table $table): Table
    {
        return OpenRepliesTable::configure($table);
    }

    public function getTableRecord(?string $key): Model|array|null
    {
        $record = $this->resolveOpenRecord($key);

        if ($record !== null || blank($key)) {
            return $record;
        }

        return [
            'id' => $key,
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OpenQueueOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('logInbound')
                ->label('Log inbound')
                ->button()
                ->schema(LogInboundForm::components())
                ->action(function (array $data): void {
                    try {
                        app(LogInbound::class)->record(
                            (string) $data['company_id'],
                            (string) $data['contact_id'],
                            LondonTime::parse($data['receipt']),
                            ReceiptSource::from((string) $data['receipt_source']),
                            '',
                            (string) $data['body'],
                            NoteFile::fromForm($data['attachment'] ?? null, $data['attachment_name'] ?? null),
                            (string) $data['subject'],
                        );
                    } catch (DeskException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        throw new Halt;
                    }

                    $this->flushCachedTableRecords();
                    Notification::make()->title('Inbound logged')->success()->send();
                }),
        ];
    }
}
