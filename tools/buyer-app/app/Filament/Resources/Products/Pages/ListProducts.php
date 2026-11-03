<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Imports\ProductImporter;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\ListRecords;
use PNScripts\SheafgateLite\ImportAction;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()->importer(ProductImporter::class),
        ];
    }
}
