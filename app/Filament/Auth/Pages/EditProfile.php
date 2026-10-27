<?php

namespace App\Filament\Auth\Pages;

use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

class EditProfile extends BaseEditProfile
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        $changingPassword = array_key_exists('password', $data);

        $record = parent::handleRecordUpdate($record, $data);

        if ($changingPassword && $record instanceof User) {
            $record->forceFill(['password_changed_at' => now()])->save();
        }

        return $record;
    }
}
