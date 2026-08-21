<?php

namespace Tests\Support;

use App\Actions\StatementImports\StatementImportWorkflow;
use App\Models\User;
use App\StatementImports\StatementImportValidationException;
use Closure;
use Illuminate\Http\UploadedFile;

final class ConcurrentStatementImportConfirmation
{
    /**
     * @param  array<string, mixed>  $confirmation
     */
    public static function task(int $ownerId, string $pdf, array $confirmation): Closure
    {
        return static function () use ($ownerId, $pdf, $confirmation): string {
            try {
                app(StatementImportWorkflow::class)->confirm(
                    User::query()->findOrFail($ownerId),
                    UploadedFile::fake()->createWithContent('confirm.pdf', $pdf),
                    $confirmation,
                );

                return 'confirmed';
            } catch (StatementImportValidationException $exception) {
                return $exception->errorCode;
            }
        };
    }
}
