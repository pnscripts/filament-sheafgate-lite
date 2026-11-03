<?php

namespace PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource\Pages;

use PNScripts\SheafgateLite\ImportAction;
use PNScripts\SheafgateLite\Tests\Fixtures\Importers\ProductImporter;
use PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        $action = ImportAction::make()->importer(ProductImporter::class);

        if (ProductResource::$configureImport) {
            (ProductResource::$configureImport)($action);
        }

        return [$action];
    }
}
