<?php

declare(strict_types=1);

namespace Nezasa\Checkout\Integrations\Credit2000\Connectors;

use Illuminate\Support\Facades\Config;
use Nezasa\Checkout\Integrations\Credit2000\Resources\Credit2000PaymentResource;
use Saloon\Http\Connector;
use Saloon\Traits\Makeable;
use Saloon\Traits\Plugins\HasTimeout;

class Credit2000Connector extends Connector
{
    use HasTimeout;
    use Makeable;

    protected int $connectTimeout = 30;

    protected int $requestTimeout = 45;

    public function resolveBaseUrl(): string
    {
        return rtrim(Config::string('checkout.integrations.credit2000.base_url'), '/');
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/xml, application/soap+xml, */*',
        ];
    }

    public function payment(): Credit2000PaymentResource
    {
        return new Credit2000PaymentResource($this);
    }
}
