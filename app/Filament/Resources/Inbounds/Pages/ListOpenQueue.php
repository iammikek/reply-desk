<?php

namespace App\Filament\Resources\Inbounds\Pages;

use App\Clock\ReceiptSource;
use App\Desk\Actions\LogInbound;
use App\Desk\DeskException;
use App\Desk\LondonTime;
use App\Desk\Notes\NoteFile;
use App\Filament\DeskNotify;
use App\Filament\Resources\Inbounds\InboundResource;
use App\Filament\Schemas\LogInboundForm;
use App\Filament\Widgets\OpenQueueOverview;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Table;

class ListOpenQueue extends ListInboundPage
{
    protected static ?string $navigationLabel = 'Open queue';

    protected static ?string $title = 'Open queue';

    public function table(Table $table): Table
    {
        return InboundResource::table($table);
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
                        DeskNotify::halt($exception);
                    }

                    $this->flushCachedTableRecords();
                    Notification::make()->title('Inbound logged')->success()->send();
                }),
        ];
    }
}
