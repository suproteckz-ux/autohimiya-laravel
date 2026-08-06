<?php
namespace App\Filament\Resources\OzonAccountResource\Pages;
use App\Filament\Resources\OzonAccountResource; use Filament\Actions\DeleteAction; use Filament\Resources\Pages\EditRecord;
class EditOzonAccount extends EditRecord { protected static string $resource=OzonAccountResource::class; protected function mutateFormDataBeforeFill(array $data): array { unset($data['api_key']); return $data; } protected function getHeaderActions(): array { return [DeleteAction::make()]; } }
