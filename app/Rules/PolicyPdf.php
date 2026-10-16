<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class PolicyPdf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Choose a valid PDF file.');

            return;
        }
        // MIME/extension/size validation is also applied by the controller. Reject
        // obvious renamed or truncated files without treating this as a PDF parser.
        $handle = fopen($value->getRealPath(), 'rb');
        try {
            $header = fread($handle, 8);
            fseek($handle, max(0, $value->getSize() - 2048));
            $tail = stream_get_contents($handle);
            if (! preg_match('/^%PDF-[12]\.\d/', $header) || ! str_contains($tail, '%%EOF')) {
                $fail('The file is not a complete PDF. Export it as a PDF and upload it again.');
            }
        } finally {
            fclose($handle);
        }
    }
}
