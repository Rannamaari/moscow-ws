<?php

namespace App\Console\Commands;

use App\Services\ProductImportService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportProductsFromCsvCommand extends Command
{
    protected $signature = 'moscow-traders-wholesale:import-products {company : Company UUID} {file : CSV file path}';

    protected $description = 'Import products into a company catalog from a CSV file.';

    public function handle(ProductImportService $importService): int
    {
        try {
            $result = $importService->import(
                (string) $this->argument('company'),
                (string) $this->argument('file')
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Products created: '.$result['created']);

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return empty($result['errors']) ? self::SUCCESS : self::FAILURE;
    }
}
