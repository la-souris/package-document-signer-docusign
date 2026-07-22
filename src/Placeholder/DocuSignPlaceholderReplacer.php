<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\DocuSign\Placeholder;

use LaSouris\DocumentSigner\Sdk\Placeholder\AbstractAnchorPlaceholderReplacer;
use LaSouris\DocumentSigner\Sdk\Placeholder\ParsedPlaceholder;

final class DocuSignPlaceholderReplacer extends AbstractAnchorPlaceholderReplacer
{
    /**
     * Anchor string set as `anchorString` on the DocuSign tab. Asterisk-bracketed
     * to be distinctive in contract text and to survive Browsershot's text layer
     * without being collapsed or wrapped.
     */
    protected function formatAnchor(ParsedPlaceholder $placeholder): string
    {
        return sprintf(
            '**DS:%s:%s:%s**',
            $placeholder->type->value,
            $placeholder->signerKey,
            $placeholder->fieldName,
        );
    }
}
