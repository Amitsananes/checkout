<?php

declare(strict_types=1);

namespace Nezasa\Checkout\Integrations\Credit2000\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\PendingRequest;
use Saloon\Http\Request;
use Saloon\Repositories\Body\StringBodyRepository;
use Saloon\Traits\Body\HasXmlBody;

/**
 * Raw SOAP 1.1 request against Credit2000 ASMX.
 */
final class Credit2000SoapRequest extends Request implements HasBody
{
    use HasXmlBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $soapAction,
        private readonly string $bodyXml,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/';
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $pendingRequest->headers()->add('Content-Type', 'text/xml; charset=utf-8');
        $pendingRequest->headers()->add('SOAPAction', '"'.$this->soapAction.'"');
        $pendingRequest->setBody(new StringBodyRepository($this->bodyXml));
    }
}
