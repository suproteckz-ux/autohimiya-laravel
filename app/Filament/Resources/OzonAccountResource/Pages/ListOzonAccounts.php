<?php
namespace App\Filament\Resources\OzonAccountResource\Pages;
use App\Filament\Resources\OzonAccountResource; use Filament\Actions\CreateAction; use Filament\Resources\Pages\ListRecords;
class ListOzonAccounts extends ListRecords { protected static string $resource=OzonAccountResource::class; protected function getHeaderActions(): array { return [CreateAction::make()]; } }
