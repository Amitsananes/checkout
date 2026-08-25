<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Uri;
use Nezasa\Checkout\Actions\Checkout\GetPaymentProviderAction;
use Nezasa\Checkout\Integrations\Computop\Requests\ComputopCapturePaymentRequest;
use Nezasa\Checkout\Integrations\Computop\Requests\ComputopCreatePaymentRequest;
use Nezasa\Checkout\Integrations\Computop\Requests\ComputopReversePaymentRequest;
use Nezasa\Checkout\Integrations\Computop\Requests\GetComputopPaymentRequest;
use Nezasa\Checkout\Integrations\Nezasa\Dtos\Payloads\Entities\ContactInfoPayloadEntity;
use Nezasa\Checkout\Integrations\Nezasa\Dtos\Shared\Price;
use Nezasa\Checkout\Integrations\Nezasa\Enums\GenderEnum;
use Nezasa\Checkout\Integrations\Nezasa\Enums\NezasaPaymentMethodEnum;
use Nezasa\Checkout\Integrations\Nezasa\Enums\NezasaTransactionStatusEnum;
use Nezasa\Checkout\Integrations\Nezasa\Requests\Payment\CreatePaymentAuthorizationRequest;
use Nezasa\Checkout\Integrations\Oppwa\Dtos\Responses\OppwaPrepareResponse;
use Nezasa\Checkout\Integrations\Oppwa\Requests\OppwaComplationRequest;
use Nezasa\Checkout\Integrations\Oppwa\Requests\OppwaPrepareRequest;
use Nezasa\Checkout\Integrations\Oppwa\Requests\OppwaStatusRequest;
use Nezasa\Checkout\Models\Checkout;
use Nezasa\Checkout\Models\Transaction;
use Nezasa\Checkout\Payments\Contracts\PaymentContract;
use Nezasa\Checkout\Payments\Contracts\RedirectPaymentContract;
use Nezasa\Checkout\Payments\Contracts\WidgetPaymentContract;
use Nezasa\Checkout\Payments\Dtos\CaptureResult;
use Nezasa\Checkout\Payments\Dtos\PaymentAsset;
use Nezasa\Checkout\Payments\Dtos\PaymentPrepareData;
use Nezasa\Checkout\Payments\Enums\TransactionStatusEnum;
use Nezasa\Checkout\Payments\Gateways\Computop\ComputopGateway;
use Nezasa\Checkout\Payments\Gateways\Computop\ComputopTokenGateway;
use Nezasa\Checkout\Payments\Gateways\Credit2000\Credit2000Gateway;
use Nezasa\Checkout\Payments\Gateways\Invoice\InvoiceGateway;
use Nezasa\Checkout\Payments\Gateways\Oppwa\OppwaWidgetGateway;
use Nezasa\Checkout\Payments\Gateways\Stripe\StripeGateway;
use Nezasa\Checkout\Payments\Handlers\PaymentInitiationHandler;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

final class TestableStripeGatewayForPaymentGatewayTest extends StripeGateway
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function exposeCustomizeSessionPayload(array $payload, Transaction $transaction): array
    {
        return $this->customizeSessionPayload($payload, $transaction);
    }
}

function paymentGatewayCheckout(array $data = []): Checkout
{
    return Checkout::factory()->create([
        'checkout_id' => uniqid('checkout-', true),
        'itinerary_id' => uniqid('itinerary-', true),
        'origin' => 'APP',
        'lang' => 'en',
        'data' => array_replace_recursive([
            'contact' => [
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'gender' => GenderEnum::Female,
                'email' => 'jane@example.test',
                'country' => 'DE-Germany',
                'countryCode' => 'DE',
                'city' => 'Berlin',
                'postalCode' => '10115',
                'street1' => 'Main Street',
                'street2' => '42',
            ],
            'insurance' => null,
        ], $data),
    ]);
}

function paymentGatewayTransaction(?Checkout $checkout = null, string $gateway = 'test'): Transaction
{
    return Transaction::create([
        'checkout_id' => ($checkout ?? paymentGatewayCheckout())->id,
        'gateway' => $gateway,
        'amount' => 199.9,
        'currency' => 'EUR',
        'status' => TransactionStatusEnum::Pending,
    ]);
}

function paymentGatewayPrepareData(Transaction $transaction): PaymentPrepareData
{
    return new PaymentPrepareData(
        transaction: $transaction,
        returnUrl: Uri::of('https://checkout.example.test/result'),
        cancelUrl: Uri::of('https://checkout.example.test/cancel'),
        contact: ContactInfoPayloadEntity::from($transaction->checkout->data['contact']),
        price: $transaction->price,
        checkoutId: $transaction->checkout->checkout_id,
        itineraryId: $transaction->checkout->itinerary_id,
        origin: $transaction->checkout->origin,
        lang: $transaction->checkout->lang,
    );
}

function requestWithTransaction(Transaction $transaction, array $query = []): Request
{
    $request = Request::create('/callback', 'GET', $query);
    $request->setRouteResolver(fn (): object => new readonly class($transaction)
    {
        public function __construct(private Transaction $transaction) {}

        public function parameter(string $name): ?Transaction
        {
            return $name === 'transaction' ? $this->transaction : null;
        }
    });

    return $request;
}

it('keeps the registered existing payment gateway contract surface unchanged', function (): void {
    Config::set('checkout.integrations.oppwa.active', true);
    Config::set('checkout.integrations.oppwa.name', 'oppwa');
    Config::set('checkout.integrations.invoice.active', true);
    Config::set('checkout.integrations.invoice.name', 'Invoice');
    Config::set('checkout.integrations.stripe.active', true);
    Config::set('checkout.integrations.stripe.name', 'Credit Card');
    Config::set('checkout.integrations.computop.active', true);
    Config::set('checkout.integrations.computop.name', 'Computop');
    Config::set('checkout.integrations.computop_token.active', true);
    Config::set('checkout.integrations.computop_token.name', 'Computop - Token');

    $registeredGateways = Config::array('checkout.payment');

    expect($registeredGateways)->toBe([
        OppwaWidgetGateway::class,
        InvoiceGateway::class,
        StripeGateway::class,
        ComputopGateway::class,
        ComputopTokenGateway::class,
        Credit2000Gateway::class,
    ]);

    foreach ($registeredGateways as $gateway) {
        expect(is_subclass_of($gateway, PaymentContract::class))->toBeTrue();
    }

    expect($registeredGateways)->each->not->toBeEmpty()
        ->and(collect($registeredGateways)->map(fn (string $gateway): string => $gateway::name())->all())->toBe([
            'oppwa',
            'Invoice',
            'Credit Card',
            'Computop',
            'Computop - Token',
            'Credit2000',
        ])
        ->and(collect($registeredGateways)->map(fn (string $gateway): bool => $gateway::isTokenized())->all())->toBe([
            false,
            false,
            false,
            false,
            true,
            false,
        ])
        ->and(is_subclass_of(OppwaWidgetGateway::class, WidgetPaymentContract::class))->toBeTrue()
        ->and(is_subclass_of(InvoiceGateway::class, RedirectPaymentContract::class))->toBeTrue()
        ->and(is_subclass_of(StripeGateway::class, RedirectPaymentContract::class))->toBeTrue()
        ->and(is_subclass_of(ComputopGateway::class, RedirectPaymentContract::class))->toBeTrue()
        ->and(is_subclass_of(ComputopTokenGateway::class, RedirectPaymentContract::class))->toBeTrue()
        ->and(is_subclass_of(Credit2000Gateway::class, RedirectPaymentContract::class))->toBeTrue();
});

it('only exposes active existing payment providers with decryptable gateway names and classes', function (): void {
    Config::set('checkout.integrations.oppwa.active', false);
    Config::set('checkout.integrations.invoice.active', true);
    Config::set('checkout.integrations.invoice.name', 'Invoice');
    Config::set('checkout.integrations.stripe.active', false);
    Config::set('checkout.integrations.computop.active', false);
    Config::set('checkout.integrations.computop_token.active', true);
    Config::set('checkout.integrations.computop_token.name', 'Computop - Token');

    $options = (new GetPaymentProviderAction)->run();

    expect($options)->toHaveCount(2)
        ->and(collect($options)->map->decryptGateway()->all())->toBe(['Invoice', 'Computop - Token'])
        ->and(collect($options)->map->decryptClassName()->all())->toBe([
            InvoiceGateway::class,
            ComputopTokenGateway::class,
        ]);
});

it('keeps invoice payment initiation behavior as the existing redirect baseline', function (): void {
    Config::set('checkout.integrations.invoice.name', 'Invoice');

    $checkout = paymentGatewayCheckout();
    $price = new Price(199.9, 'EUR');

    $redirect = (new PaymentInitiationHandler)->run(
        model: $checkout,
        price: $price,
        gateway: new InvoiceGateway
    );

    $transaction = $checkout->transactions()->sole();

    expect($redirect)->toBeInstanceOf(Uri::class)
        ->and($redirect->toStringable()->toString())->toContain(route('payment-result', [
            'transaction' => $transaction,
            'checkoutId' => $checkout->checkout_id,
            'itineraryId' => $checkout->itinerary_id,
            'origin' => $checkout->origin,
            'lang' => $checkout->lang,
            'rest-payment' => $checkout->rest_payment,
        ], false))
        ->and($transaction->gateway)->toBe('Invoice')
        ->and($transaction->status)->toBe(TransactionStatusEnum::Pending)
        ->and((float) $transaction->amount)->toBe(199.9)
        ->and($transaction->currency)->toBe('EUR')
        ->and($transaction->prepare_data)->toBe(['id' => $transaction->id]);
});

it('keeps oppwa payment initiation behavior as the existing widget baseline', function (): void {
    Config::set('checkout.integrations.oppwa.name', 'oppwa');
    Config::set('checkout.integrations.oppwa.base_url', 'https://oppwa.example.test');
    Config::set('checkout.integrations.oppwa.entity_id', 'entity-123');
    Config::set('checkout.integrations.oppwa.token', 'token-123');

    MockClient::global([
        OppwaPrepareRequest::class => MockResponse::make([
            'id' => 'checkout-id',
            'ndc' => 'checkout-id',
            'integrity' => 'sha384-test',
            'result' => ['code' => '000.200.100', 'description' => 'successfully created checkout'],
            'buildNumber' => 'build-1',
            'timestamp' => '2025-08-26T14:17:40+00:00',
        ]),
    ]);

    $checkout = paymentGatewayCheckout();
    $price = new Price(199.9, 'EUR');

    $asset = (new PaymentInitiationHandler)->run(
        model: $checkout,
        price: $price,
        gateway: new OppwaWidgetGateway
    );

    $transaction = $checkout->transactions()->sole();

    expect($asset)->toBeInstanceOf(PaymentAsset::class)
        ->and($asset->isAvailable)->toBeTrue()
        ->and($asset->html)->toContain('paymentWidgets')
        ->and($asset->scripts->implode(''))->toContain('checkoutId=checkout-id')
        ->and($transaction->gateway)->toBe('oppwa')
        ->and($transaction->status)->toBe(TransactionStatusEnum::Pending)
        ->and((float) $transaction->amount)->toBe(199.9)
        ->and($transaction->currency)->toBe('EUR')
        ->and($transaction->prepare_data)->toHaveKeys(['prepare', 'prepare_payload', 'contact']);
});

it('runs the full invoice gateway lifecycle without external calls', function (): void {
    Config::set('checkout.integrations.invoice.active', true);
    Config::set('checkout.integrations.invoice.name', 'Invoice');

    $transaction = paymentGatewayTransaction(gateway: 'Invoice');
    $gateway = new InvoiceGateway;
    $prepareData = paymentGatewayPrepareData($transaction);

    $init = $gateway->prepare($prepareData);
    $request = requestWithTransaction($transaction);
    $authorized = $gateway->authorize($request, $init->persistentData);
    $captured = $gateway->capture($request, $init->persistentData, $authorized->resultData);
    $aborted = $gateway->abort($request, $init->persistentData, $authorized->resultData);
    $payload = $gateway->makeNezasaTransactionPayload($request, $captured);

    expect(InvoiceGateway::isActive())->toBeTrue()
        ->and(InvoiceGateway::name())->toBe('Invoice')
        ->and($init->isAvailable)->toBeTrue()
        ->and($gateway->getRedirectUrl($init)->toStringable()->toString())->toBe($prepareData->returnUrl->toStringable()->toString())
        ->and($authorized->isSuccessful)->toBeTrue()
        ->and($captured->isSuccessful)->toBeTrue()
        ->and($aborted->isSuccessful)->toBeTrue()
        ->and($payload->externalRefId)->toBe((string) $transaction->id)
        ->and($payload->amount)->toEqual($transaction->price)
        ->and($payload->paymentMethod)->toBe(NezasaPaymentMethodEnum::BankTransfer)
        ->and($payload->status)->toBe(NezasaTransactionStatusEnum::Open);
});

it('prepares Oppwa widget assets, authorizes, captures, aborts, and builds Nezasa payload', function (): void {
    Config::set('checkout.integrations.oppwa.active', true);
    Config::set('checkout.integrations.oppwa.name', 'oppwa');
    Config::set('checkout.integrations.oppwa.base_url', 'https://oppwa.example.test');
    Config::set('checkout.integrations.oppwa.entity_id', 'entity-123');
    Config::set('checkout.integrations.oppwa.token', 'token-123');
    Config::set('checkout.integrations.oppwa.successful_result_code', '000.100.110');

    MockClient::global([
        OppwaPrepareRequest::class => MockResponse::make([
            'id' => 'checkout-id',
            'ndc' => 'checkout-id',
            'integrity' => 'sha384-test',
            'result' => ['code' => '000.200.100', 'description' => 'successfully created checkout'],
            'buildNumber' => 'build-1',
            'timestamp' => '2025-08-26T14:17:40+00:00',
        ]),
        OppwaStatusRequest::class => MockResponse::make([
            'id' => 'payment-id',
            'referencedId' => 'referenced-id',
            'amount' => '199.90',
            'currency' => 'EUR',
            'result' => ['code' => '000.100.110', 'description' => 'successful'],
        ]),
        OppwaComplationRequest::class => MockResponse::make([
            'id' => 'capture-id',
            'referencedId' => 'referenced-id',
            'result' => ['code' => '000.100.110', 'description' => 'successful'],
        ]),
    ]);

    $transaction = paymentGatewayTransaction(gateway: 'oppwa');
    $gateway = new OppwaWidgetGateway;
    $init = $gateway->prepare(paymentGatewayPrepareData($transaction));
    $assets = $gateway->getAssets($init);

    expect(OppwaWidgetGateway::isActive())->toBeTrue()
        ->and(OppwaWidgetGateway::name())->toBe('oppwa')
        ->and($init->isAvailable)->toBeTrue()
        ->and($init->persistentData['prepare'])->toBeInstanceOf(OppwaPrepareResponse::class)
        ->and($assets->isAvailable)->toBeTrue()
        ->and($assets->html)->toContain('paymentWidgets')
        ->and($assets->scripts->implode(''))->toContain('checkoutId=checkout-id');

    $request = requestWithTransaction($transaction, ['resourcePath' => 'v1/checkouts/checkout-id/payment']);
    $authorized = $gateway->authorize($request, $init->persistentData);
    $captured = $gateway->capture($request, $init->persistentData, $authorized->resultData);
    $aborted = $gateway->abort($request, $init->persistentData, $authorized->resultData);
    $payload = $gateway->makeNezasaTransactionPayload($request, $captured);

    expect($authorized->isSuccessful)->toBeTrue()
        ->and($captured->isSuccessful)->toBeTrue()
        ->and($aborted->isSuccessful)->toBeTrue()
        ->and($payload->externalRefId)->toBe('referenced-id')
        ->and($payload->paymentMethod)->toBe(NezasaPaymentMethodEnum::Other)
        ->and($payload->status)->toBe(NezasaTransactionStatusEnum::Closed)
        ->and($payload->paymentMethodName)->toBe('Oppwa');
});

it('runs Computop prepare, authorization, capture, abort, redirect, and Nezasa payload mapping', function (): void {
    Config::set('checkout.integrations.computop.active', true);
    Config::set('checkout.integrations.computop.name', 'Computop');
    Config::set('checkout.integrations.computop.base_url', 'https://computop.example.test');
    Config::set('checkout.integrations.computop.test_mode', true);
    Config::set('checkout.integrations.computop.username', 'user');
    Config::set('checkout.integrations.computop.password', 'pass');

    MockClient::global([
        ComputopCreatePaymentRequest::class => MockResponse::make([
            'paymentId' => 'pay-123',
            'transactionId' => 'transaction-123',
            'status' => 'OK',
            '_Links' => [
                'redirect' => ['href' => 'https://pay.example.test/redirect'],
            ],
        ], 201),
        GetComputopPaymentRequest::class => MockResponse::make([
            'paymentId' => 'pay-123',
            'transactionId' => 'transaction-123',
            'status' => 'CAPTURE_REQUEST',
        ]),
        ComputopCapturePaymentRequest::class => MockResponse::make([
            'paymentId' => 'pay-123',
            'transactionId' => 'transaction-123',
            'status' => 'OK',
        ]),
        ComputopReversePaymentRequest::class => MockResponse::make([
            'paymentId' => 'pay-123',
            'transactionId' => 'transaction-123',
            'status' => 'OK',
        ]),
    ]);

    $transaction = paymentGatewayTransaction(gateway: 'Computop');
    $gateway = new ComputopGateway;
    $init = $gateway->prepare(paymentGatewayPrepareData($transaction));
    $request = requestWithTransaction($transaction, ['PayID' => 'pay-123']);
    $authorized = $gateway->authorize($request, $init->persistentData);
    $captured = $gateway->capture($request, $init->persistentData, $authorized->resultData);
    $aborted = $gateway->abort($request, $init->persistentData, $authorized->resultData);
    $payload = $gateway->makeNezasaTransactionPayload($request, $captured);

    expect(ComputopGateway::isActive())->toBeTrue()
        ->and(ComputopGateway::name())->toBe('Computop')
        ->and($init->isAvailable)->toBeTrue()
        ->and($init->persistentData['paylaod']['order']['description'])->toBe(['Test:0000'])
        ->and($gateway->getRedirectUrl($init)->toStringable()->toString())->toBe('https://pay.example.test/redirect')
        ->and($authorized->isSuccessful)->toBeTrue()
        ->and($captured->isSuccessful)->toBeTrue()
        ->and($aborted->isSuccessful)->toBeTrue()
        ->and($payload->externalRefId)->toBe('pay-123')
        ->and($payload->paymentMethod)->toBe(NezasaPaymentMethodEnum::Other)
        ->and($payload->status)->toBe(NezasaTransactionStatusEnum::Closed)
        ->and($payload->paymentMethodName)->toBe('Computop');
});

it('runs Computop token flow with CoF setup and sends card token details to Nezasa', function (): void {
    Config::set('checkout.integrations.computop.active', true);
    Config::set('checkout.integrations.computop.name', 'Computop');
    Config::set('checkout.integrations.computop.base_url', 'https://computop.example.test');
    Config::set('checkout.integrations.computop.test_mode', true);
    Config::set('checkout.integrations.computop.username', 'user');
    Config::set('checkout.integrations.computop.password', 'pass');
    Config::set('checkout.integrations.computop_token.active', true);
    Config::set('checkout.integrations.computop_token.name', 'Computop - Token');
    Config::set('checkout.nezasa.base_url', 'https://nezasa.example.test');
    Config::set('checkout.nezasa.username', 'nezasa-user');
    Config::set('checkout.nezasa.password', 'nezasa-pass');

    $mockClient = MockClient::global([
        ComputopCreatePaymentRequest::class => MockResponse::make([
            'paymentId' => 'pay-token-123',
            'transactionId' => 'transaction-token-123',
            'status' => 'OK',
            '_Links' => [
                'redirect' => ['href' => 'https://pay.example.test/token-redirect'],
            ],
        ], 201),
        // The shape below mirrors the live Computop "get payment" response.
        GetComputopPaymentRequest::class => MockResponse::make([
            'paymentId' => 'pay-token-123',
            'transactionId' => 'transaction-token-123',
            'status' => 'OK',
            'payment' => [
                'category' => 'CC',
                'card' => [
                    'cardholderName' => 'John Doe',
                    'number' => '0355203877628444',
                    'expiryDate' => '202804',
                    'brand' => 'MasterCard',
                    'issuer' => '',
                ],
                'PCNr' => '0355203877628444',
                'CCExpiry' => '202804',
                'CCBrand' => 'MasterCard',
                'CardHolder' => 'John Doe',
                'schemeReferenceID' => '84ae0857d',
            ],
        ]),
        CreatePaymentAuthorizationRequest::class => MockResponse::make([
            'id' => 'authorization-123',
        ]),
        ComputopReversePaymentRequest::class => MockResponse::make([
            'paymentId' => 'pay-token-123',
            'transactionId' => 'transaction-token-123',
            'status' => 'OK',
        ]),
    ]);

    $transaction = paymentGatewayTransaction(gateway: 'Computop - Token');
    $gateway = new ComputopTokenGateway;
    $init = $gateway->prepare(paymentGatewayPrepareData($transaction));
    $request = requestWithTransaction($transaction, ['PayID' => 'pay-token-123']);
    $authorized = $gateway->authorize($request, $init->persistentData);
    $captured = $gateway->capture($request, $init->persistentData, $authorized->resultData);
    $aborted = $gateway->abort($request, $init->persistentData, $authorized->resultData);

    $mockClient->assertSent(function (mixed $request): bool {
        if (! $request instanceof ComputopCreatePaymentRequest) {
            return false;
        }

        expect($request->body()->all())->toHaveKey('credentialOnFile')
            ->and($request->body()->all()['credentialOnFile'])->toBe([
                'type' => [
                    'unscheduled' => 'CIT',
                ],
                'initialPayment' => true,
            ]);

        return true;
    });

    $mockClient->assertSent(function (mixed $request): bool {
        if (! $request instanceof CreatePaymentAuthorizationRequest) {
            return false;
        }

        expect($request->resolveEndpoint())->toBe('payment-authorization/v1.13/'.$request->checkoutRefId)
            ->and($request->body()->all())->toBe([
                'aliasProvider' => 'COMPUTOP',
                'schemeReferenceId' => '84ae0857d',
                'card' => [
                    'alias' => '0355203877628444',
                    'brand' => 'MasterCard',
                    'expiryMonth' => 4,
                    'expiryYear' => 2028,
                    'cardHolderName' => 'John Doe',
                    'issuer' => '',
                ],
            ]);

        return true;
    });

    // The tokenized gateway must never capture at Computop; Nezasa captures on their side.
    $mockClient->assertNotSent(ComputopCapturePaymentRequest::class);

    expect(ComputopTokenGateway::isActive())->toBeTrue()
        ->and(ComputopTokenGateway::name())->toBe('Computop - Token')
        ->and(ComputopTokenGateway::isTokenized())->toBeTrue()
        ->and($init->isAvailable)->toBeTrue()
        ->and($init->persistentData['paylaod']['credentialOnFile']['type']['unscheduled'])->toBe('CIT')
        ->and($gateway->getRedirectUrl($init)->toStringable()->toString())->toBe('https://pay.example.test/token-redirect')
        ->and($authorized->isSuccessful)->toBeTrue()
        ->and($authorized->resultData)->not->toHaveKey('payment_authorization')
        ->and($captured->isSuccessful)->toBeTrue()
        ->and($captured->persistentData['payment_authorization'])->toBe(['id' => 'authorization-123'])
        ->and($aborted->isSuccessful)->toBeTrue();
});

it('builds Stripe Nezasa payload and adds vertical insurance submit text only when a quote is selected', function (): void {
    Config::set('checkout.integrations.stripe.active', true);
    Config::set('checkout.integrations.stripe.name', 'Credit Card');
    Config::set('checkout.insurance.vertical.active', true);

    $checkout = paymentGatewayCheckout([
        'insurance' => [
            'offer' => [
                'id' => 'quote-123',
                'title' => 'Vertical quote',
                'price' => ['amount' => 24.5, 'currency' => 'EUR'],
                'coverage' => [],
            ],
        ],
    ]);
    $transaction = paymentGatewayTransaction($checkout, 'Credit Card');
    $gateway = new TestableStripeGatewayForPaymentGatewayTest;
    $basePayload = ['mode' => 'payment'];
    $customized = $gateway->exposeCustomizeSessionPayload($basePayload, $transaction);

    $request = requestWithTransaction($transaction);
    $payload = $gateway->makeNezasaTransactionPayload(
        $request,
        new CaptureResult(true, ['payment_intent' => ['id' => 'pi_123']])
    );

    expect(StripeGateway::isActive())->toBeTrue()
        ->and(StripeGateway::name())->toBe('Credit Card')
        ->and($customized)->toHaveKey('custom_text.submit.message')
        ->and($payload->externalRefId)->toBe('pi_123')
        ->and($payload->paymentMethod)->toBe(NezasaPaymentMethodEnum::Other)
        ->and($payload->status)->toBe(NezasaTransactionStatusEnum::Closed)
        ->and($payload->paymentMethodName)->toBe('Stripe');

    $plainCheckout = paymentGatewayCheckout();
    $plainTransaction = paymentGatewayTransaction($plainCheckout, 'Credit Card');
    expect($gateway->exposeCustomizeSessionPayload($basePayload, $plainTransaction))->toBe($basePayload);
});
