<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Uri;
use Nezasa\Checkout\Integrations\Credit2000\Requests\Credit2000SoapRequest;
use Nezasa\Checkout\Integrations\Credit2000\Support\Credit2000Xml;
use Nezasa\Checkout\Integrations\Nezasa\Dtos\Payloads\Entities\ContactInfoPayloadEntity;
use Nezasa\Checkout\Integrations\Nezasa\Dtos\Shared\Price;
use Nezasa\Checkout\Models\Transaction;
use Nezasa\Checkout\Payments\Dtos\PaymentPrepareData;
use Nezasa\Checkout\Payments\Gateways\Credit2000\Credit2000Gateway;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
});

function c2kTransaction(string $id = '01C2KTEST'): Transaction
{
    $transaction = new Transaction;
    $transaction->id = $id;
    $transaction->amount = 10;
    $transaction->currency = 'ILS';

    return $transaction;
}

function c2kRequest(Transaction $transaction, array $query): Request
{
    $request = Request::create('/checkout/result/'.$transaction->id, 'GET', $query);
    $route = new Route(['GET'], '/checkout/result/{transaction}', []);
    $route->bind($request);
    $route->setParameter('transaction', $transaction);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

function c2kPrepareData(Transaction $transaction, string $currency = 'ILS', float $amount = 10.00): PaymentPrepareData
{
    return new PaymentPrepareData(
        transaction: $transaction,
        returnUrl: Uri::of('https://checkout-staging.tourismo-filipino.com/checkout/result/'.$transaction->id.'?checkoutId=chk_1&itineraryId=it_1&origin=ibe&lang=he'),
        cancelUrl: Uri::of('https://checkout-staging.tourismo-filipino.com/checkout/details'),
        contact: new ContactInfoPayloadEntity(firstName: 'Dana', lastName: 'Cohen', email: 'dana@example.com', localIdNumber: '203269535'),
        price: new Price($amount, $currency),
        checkoutId: 'chk_1',
        itineraryId: 'it_1',
        lang: 'he',
    );
}

function c2kPersistent(string $order = '01C2KOK'): array
{
    return [
        'order' => $order,
        'product_id' => Credit2000Xml::productId($order),
        'amount_subunits' => 1000,
        'total_pyment' => '1000',
        'currency' => 'ILS',
        'currency_code' => '1',
        'checkout_id' => 'chk_1',
        'client_name' => 'Cohen/Dana',
        'tz_number' => '203269535',
        'prepare_action_type' => '5',
        'charged_on_page' => false,
    ];
}

function c2kConfig(): void
{
    Config::set('checkout.integrations.credit2000', [
        'active' => true,
        'name' => 'Credit2000',
        'base_url' => 'https://www.credit2000.co.il/pci_tkn_ver7/WCF/wsCredit2000.asmx',
        'vendor_name' => 'cuTEST',
        'company_key' => 'DCSTEST==',
        'lang' => 'he',
        'prepare_action_type' => '5',
        'purchase_type' => '1',
    ]);
}

function c2kProSoapBody(array $overrides = []): string
{
    $defaults = [
        'product_Id' => Credit2000Xml::productId('01C2KOK'),
        'total_Pyment' => '1000',
        'currency' => '1',
        'action_Type' => '5',
        'uID' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
        'Approve' => '1234567',
        'ValidDate' => '0729',
        'token' => '9101111111116951',
        'cardType' => '1',
    ];
    $data = array_merge($defaults, $overrides);

    return '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
        .'<getTokenAndApproveProResponse xmlns="http://tempuri.org/">'
        .'<getTokenAndApproveProResult>'
        .'<product_Id>'.$data['product_Id'].'</product_Id>'
        .'<total_Pyment>'.$data['total_Pyment'].'</total_Pyment>'
        .'<currency>'.$data['currency'].'</currency>'
        .'<action_Type>'.$data['action_Type'].'</action_Type>'
        .'<uID>'.$data['uID'].'</uID>'
        .'<Approve>'.$data['Approve'].'</Approve>'
        .'<ValidDate>'.$data['ValidDate'].'</ValidDate>'
        .'</getTokenAndApproveProResult>'
        .'<token>'.$data['token'].'</token>'
        .'<cardType>'.$data['cardType'].'</cardType>'
        .'</getTokenAndApproveProResponse>'
        .'</soap:Body></soap:Envelope>';
}

it('reports inactive by default', function (): void {
    Config::set('checkout.integrations.credit2000.active', false);

    expect(Credit2000Gateway::isActive())->toBeFalse();
});

it('reports active when enabled', function (): void {
    c2kConfig();

    expect(Credit2000Gateway::isActive())->toBeTrue()
        ->and(Credit2000Gateway::name())->toBe('Credit2000')
        ->and(Credit2000Gateway::isTokenized())->toBeFalse();
});

it('builds a 16-digit product id', function (): void {
    expect(Credit2000Xml::productId('01C2KTEST'))->toHaveLength(16)
        ->and(Credit2000Xml::productId('01C2KTEST'))->toMatch('/^\d{16}$/');
});

it('prepares a hosted payment redirect url', function (): void {
    c2kConfig();

    $mock = MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><SendParamToCredit2000Response xmlns="http://tempuri.org/"><SendParamToCredit2000Result>https://www.credit2000.co.il/pay/abc123</SendParamToCredit2000Result></SendParamToCredit2000Response></soap:Body></soap:Envelope>',
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction();
    $gateway = new Credit2000Gateway;
    $init = $gateway->prepare(c2kPrepareData($transaction));

    expect($init->isAvailable)->toBeTrue()
        ->and((string) $gateway->getRedirectUrl($init))->toBe('https://www.credit2000.co.il/pay/abc123')
        ->and($init->persistentData['total_pyment'])->toBe('1000')
        ->and($init->persistentData['prepare_action_type'])->toBe('5');

    $mock->assertSent(Credit2000SoapRequest::class);
});

it('marks prepare unavailable for unsupported currency', function (): void {
    c2kConfig();

    $gateway = new Credit2000Gateway;
    $init = $gateway->prepare(c2kPrepareData(c2kTransaction(), 'GBP'));

    expect($init->isAvailable)->toBeFalse();
});

it('rejects prepare when prepare_action_type is test mode 2', function (): void {
    c2kConfig();
    Config::set('checkout.integrations.credit2000.prepare_action_type', '2');

    $mock = MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(body: 'should-not-be-called', status: 500),
    ]);

    $init = (new Credit2000Gateway)->prepare(c2kPrepareData(c2kTransaction()));

    expect($init->isAvailable)->toBeFalse();
    $mock->assertNothingSent();
});

it('refuses capture when prepare_action_type was test mode 2', function (): void {
    c2kConfig();

    $mock = MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(body: 'should-not-be-called', status: 500),
    ]);

    $persistent = c2kPersistent();
    $persistent['prepare_action_type'] = '2';

    $resultData = [
        'credit2000' => [
            'uid' => 'uid-1',
            'token' => '9101111111116951',
            'approveNum' => '1234567',
            'validDate' => '0729',
            'cardType' => '1',
            'customerId' => '9999',
            'charged_on_page' => false,
        ],
    ];

    $capture = (new Credit2000Gateway)->capture(c2kRequest(c2kTransaction('01C2KOK'), []), $persistent, $resultData);

    expect($capture->isSuccessful)->toBeFalse()
        ->and(data_get($capture->persistentData, 'capture_error'))->toBe('test_mode_prepare_cannot_charge');
    $mock->assertNothingSent();
});

it('authorizes with callback uid and matching getTokenAndApprovePro data', function (): void {
    c2kConfig();

    MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: c2kProSoapBody(),
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, [
        'params' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
        'checkoutId' => 'chk_1',
    ]);

    $result = (new Credit2000Gateway)->authorize($request, c2kPersistent());

    expect($result->isSuccessful)->toBeTrue()
        ->and(data_get($result->resultData, 'credit2000.token'))->toBe('9101111111116951')
        ->and(data_get($result->resultData, 'credit2000.approveNum'))->toBe('1234567');
});

it('rejects authorize when provider product_Id mismatches prepare data', function (): void {
    c2kConfig();

    MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: c2kProSoapBody(['product_Id' => '0000000000000001']),
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, [
        'params' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
    ]);

    $result = (new Credit2000Gateway)->authorize($request, c2kPersistent());

    expect($result->isSuccessful)->toBeFalse()
        ->and(data_get($result->resultData, 'reason'))->toBe('product_id_mismatch');
});

it('rejects authorize when provider total_Pyment mismatches prepare data', function (): void {
    c2kConfig();

    MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: c2kProSoapBody(['total_Pyment' => '2000']),
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, [
        'params' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
    ]);

    $result = (new Credit2000Gateway)->authorize($request, c2kPersistent());

    expect($result->isSuccessful)->toBeFalse()
        ->and(data_get($result->resultData, 'reason'))->toBe('amount_mismatch');
});

it('rejects authorize when provider currency mismatches prepare data', function (): void {
    c2kConfig();

    MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: c2kProSoapBody(['currency' => '2']),
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, [
        'params' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
    ]);

    $result = (new Credit2000Gateway)->authorize($request, c2kPersistent());

    expect($result->isSuccessful)->toBeFalse()
        ->and(data_get($result->resultData, 'reason'))->toBe('currency_mismatch');
});

it('rejects authorize when provider action_Type mismatches prepare data', function (): void {
    c2kConfig();

    MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: c2kProSoapBody(['action_Type' => '4']),
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, [
        'params' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
    ]);

    $result = (new Credit2000Gateway)->authorize($request, c2kPersistent());

    expect($result->isSuccessful)->toBeFalse()
        ->and(data_get($result->resultData, 'reason'))->toBe('action_type_mismatch');
});

it('rejects authorize when provider uID mismatches callback uid', function (): void {
    c2kConfig();

    MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: c2kProSoapBody(['uID' => 'other-uid-00000000-0000-0000-0000']),
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, [
        'params' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
    ]);

    $result = (new Credit2000Gateway)->authorize($request, c2kPersistent());

    expect($result->isSuccessful)->toBeFalse()
        ->and(data_get($result->resultData, 'reason'))->toBe('uid_mismatch');
});

it('rejects authorize when uid is missing', function (): void {
    c2kConfig();

    $transaction = c2kTransaction();
    $request = c2kRequest($transaction, ['TotalPayment' => '10']);

    $result = (new Credit2000Gateway)->authorize($request, c2kPersistent());

    expect($result->isSuccessful)->toBeFalse()
        ->and(data_get($result->resultData, 'reason'))->toBe('missing_uid');
});

it('captures via CreditXML charge', function (): void {
    c2kConfig();

    MockClient::global([
        Credit2000SoapRequest::class => MockResponse::make(
            body: '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><CreditXMLResponse xmlns="http://tempuri.org/"><CreditXMLResult>000</CreditXMLResult><returnCode>000</returnCode><confirmationNumber>998877</confirmationNumber></CreditXMLResponse></soap:Body></soap:Envelope>',
            status: 200,
        ),
    ]);

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, []);
    $resultData = [
        'credit2000' => [
            'uid' => 'e14643ab-562a-4a64-a59a-49a9efa978e9',
            'token' => '9101111111116951',
            'approveNum' => '1234567',
            'validDate' => '0729',
            'cardType' => '1',
            'customerId' => '9999',
            'charged_on_page' => false,
        ],
    ];

    $capture = (new Credit2000Gateway)->capture($request, c2kPersistent(), $resultData);

    expect($capture->isSuccessful)->toBeTrue()
        ->and(data_get($capture->persistentData, 'capture.returnCode'))->toBe('000');
});

it('treats page charge as already captured', function (): void {
    c2kConfig();

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, []);
    $persistent = c2kPersistent();
    $persistent['prepare_action_type'] = '4';
    $persistent['charged_on_page'] = true;

    $resultData = [
        'credit2000' => [
            'uid' => 'uid-1',
            'token' => '9101111111116951',
            'approveNum' => '1234567',
            'validDate' => '0729',
            'cardType' => '1',
            'customerId' => '9999',
            'charged_on_page' => true,
        ],
    ];

    $capture = (new Credit2000Gateway)->capture($request, $persistent, $resultData);

    expect($capture->isSuccessful)->toBeTrue()
        ->and(data_get($capture->persistentData, 'capture.mode'))->toBe('charged_on_payment_page');
});

it('releases uncaptured approval on abort without refund call', function (): void {
    c2kConfig();

    $transaction = c2kTransaction('01C2KOK');
    $request = c2kRequest($transaction, []);
    $resultData = [
        'credit2000' => [
            'uid' => 'uid-1',
            'token' => '9101111111116951',
            'approveNum' => '1234567',
            'validDate' => '0729',
            'cardType' => '1',
            'customerId' => '9999',
            'charged_on_page' => false,
        ],
    ];

    $abort = (new Credit2000Gateway)->abort($request, c2kPersistent(), $resultData);

    expect($abort->isSuccessful)->toBeTrue()
        ->and(data_get($abort->persistentData, 'cancel.mode'))->toBe('release_uncaptured_approval');
});
