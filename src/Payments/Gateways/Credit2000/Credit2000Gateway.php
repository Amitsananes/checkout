<?php

declare(strict_types=1);

namespace Nezasa\Checkout\Payments\Gateways\Credit2000;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Uri;
use Nezasa\Checkout\Integrations\Credit2000\Connectors\Credit2000Connector;
use Nezasa\Checkout\Integrations\Credit2000\Enums\Credit2000CurrencyEnum;
use Nezasa\Checkout\Integrations\Credit2000\Support\Credit2000Xml;
use Nezasa\Checkout\Integrations\Nezasa\Dtos\Payloads\CreatePaymentTransactionPayload as NezasaPayload;
use Nezasa\Checkout\Integrations\Nezasa\Enums\NezasaPaymentMethodEnum;
use Nezasa\Checkout\Integrations\Nezasa\Enums\NezasaTransactionStatusEnum;
use Nezasa\Checkout\Models\Transaction;
use Nezasa\Checkout\Payments\Contracts\RedirectPaymentContract;
use Nezasa\Checkout\Payments\Dtos\AbortResult;
use Nezasa\Checkout\Payments\Dtos\AuthorizationResult;
use Nezasa\Checkout\Payments\Dtos\CaptureResult;
use Nezasa\Checkout\Payments\Dtos\PaymentInit;
use Nezasa\Checkout\Payments\Dtos\PaymentPrepareData;
use RuntimeException;
use Throwable;

/**
 * Credit2000 hosted payment page gateway (SOAP ASMX).
 *
 * Lifecycle aligned with Nezasa authorize → book → capture/abort:
 * 1. prepare: SendParamToCredit2000 (default action_Type=5 approval-only) → redirect URL
 * 2. authorize: callback uid + getTokenAndApprovePro(uid) with provider field verification
 * 3. capture: CreditXML actionType=4 with token (or no-op if prepare already charged)
 * 4. abort: CreditXML actionType=7 refund when a charge exists; otherwise mark released
 *
 * @see Credit2000 API PDF (SendParamToCredit2000 / getTokenAndApprove / CreditXML)
 */
class Credit2000Gateway implements RedirectPaymentContract
{
    private const RETURN_OK = '000';

    private const ACTION_TEST = '2';

    private const ACTION_CHARGE = '4';

    private const ACTION_APPROVAL = '5';

    private const ACTION_REFUND = '7';

    public static function isActive(): bool
    {
        return Config::boolean('checkout.integrations.credit2000.active');
    }

    public static function name(): string
    {
        return Config::string('checkout.integrations.credit2000.name');
    }

    public static function isTokenized(): bool
    {
        return false;
    }

    public function prepare(PaymentPrepareData $data): PaymentInit
    {
        try {
            $currency = Credit2000CurrencyEnum::tryFromCurrencyCode($data->price->currency);

            if ($currency === null) {
                return new PaymentInit(isAvailable: false, returnUrl: $data->returnUrl);
            }

            $order = (string) $data->transaction->id;
            $subunits = $data->price->toCent();
            $total = Credit2000Xml::amountToMinorUnits($subunits);
            $firstName = $data->contact->firstName ?? 'Customer';
            $lastName = $data->contact->lastName ?? '';
            $clientName = trim($lastName.'/'.$firstName, '/');
            $tz = $data->contact->localIdNumber ?: '';

            $prepareAction = Config::string('checkout.integrations.credit2000.prepare_action_type');

            // SendParams action_Type=2 is provider "Test" mode. Checkout capture always
            // uses CreditXML actionType=4 (Charge), so test prepare must not be allowed
            // to reach a live charge path.
            if ($prepareAction === self::ACTION_TEST) {
                return new PaymentInit(isAvailable: false, returnUrl: $data->returnUrl);
            }

            if (! in_array($prepareAction, [self::ACTION_APPROVAL, self::ACTION_CHARGE], true)) {
                return new PaymentInit(isAvailable: false, returnUrl: $data->returnUrl);
            }

            $response = Credit2000Connector::make()->payment()->sendParams([
                'host' => (string) $data->returnUrl,
                'action_Type' => $prepareAction,
                'total_Pyment' => $total,
                'first_Payment' => $total,
                'currency' => $currency->value,
                'client_Name' => $clientName,
                'tz_Number' => $tz,
                'product_Id' => Credit2000Xml::productId($order),
                'purchase_Type' => Config::string('checkout.integrations.credit2000.purchase_type'),
            ]);

            $paymentUrl = $response['payment_url'] ?? null;

            if (! is_string($paymentUrl) || $paymentUrl === '') {
                return new PaymentInit(isAvailable: false, returnUrl: $data->returnUrl);
            }

            return new PaymentInit(
                isAvailable: true,
                returnUrl: $data->returnUrl,
                persistentData: [
                    'payment_url' => $paymentUrl,
                    'order' => $order,
                    'product_id' => Credit2000Xml::productId($order),
                    'amount_subunits' => $subunits,
                    'total_pyment' => $total,
                    'currency' => $data->price->currency,
                    'currency_code' => $currency->value,
                    'checkout_id' => $data->checkoutId,
                    'client_name' => $clientName,
                    'tz_number' => $tz,
                    'prepare_action_type' => $prepareAction,
                    'charged_on_page' => $prepareAction === self::ACTION_CHARGE,
                ]
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }

        return new PaymentInit(isAvailable: false, returnUrl: $data->returnUrl);
    }

    public function getRedirectUrl(PaymentInit $init): Uri
    {
        return Uri::of((string) data_get($init->persistentData, 'payment_url'));
    }

    public function makeNezasaTransactionPayload(Request $request, CaptureResult $captureResult): NezasaPayload
    {
        /** @var Transaction $transaction */
        $transaction = $request->route('transaction');

        $externalRefId = (string) (
            data_get($captureResult->persistentData, 'capture.confirmationNumber')
            ?: data_get($captureResult->persistentData, 'token.approveNum')
            ?: data_get($captureResult->persistentData, 'callback.params')
            ?: data_get($captureResult->persistentData, 'order')
            ?: 'unknown'
        );

        return new NezasaPayload(
            externalRefId: $externalRefId,
            amount: $transaction->price,
            paymentMethod: NezasaPaymentMethodEnum::Other,
            status: NezasaTransactionStatusEnum::Closed,
            paymentMethodName: self::name()
        );
    }

    /**
     * @param  array<string, mixed>  $persistentData
     */
    public function authorize(Request $request, array $persistentData): AuthorizationResult
    {
        try {
            $callback = $this->callbackParams($request);

            if ($callback === []) {
                return new AuthorizationResult(isSuccessful: false, resultData: ['reason' => 'missing_callback_params']);
            }

            $uid = (string) ($callback['params'] ?? $callback['Params'] ?? '');

            if ($uid === '') {
                return new AuthorizationResult(isSuccessful: false, resultData: [
                    'reason' => 'missing_uid',
                    'callback' => $callback,
                ]);
            }

            $tokenResponse = Credit2000Connector::make()->payment()->getTokenAndApprovePro($uid);

            $verificationFailure = $this->providerAuthorizationMismatch(
                $persistentData,
                $uid,
                $tokenResponse
            );

            if ($verificationFailure !== null) {
                return new AuthorizationResult(isSuccessful: false, resultData: [
                    'reason' => $verificationFailure,
                    'token' => $tokenResponse,
                    'callback' => $callback,
                ]);
            }

            return new AuthorizationResult(
                isSuccessful: true,
                resultData: [
                    'callback' => $callback,
                    'token' => $tokenResponse,
                    'credit2000' => [
                        'uid' => $uid,
                        'token' => $tokenResponse['token'],
                        'approveNum' => $tokenResponse['approveNum'] ?? '',
                        'validDate' => $tokenResponse['validDate'] ?? '',
                        'cardType' => $tokenResponse['cardType'] ?? '1',
                        'customerId' => '000000001',
                        'charged_on_page' => (bool) ($persistentData['charged_on_page'] ?? false),
                    ],
                ]
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }

        return new AuthorizationResult(isSuccessful: false, resultData: ['reason' => 'exception']);
    }

    /**
     * @param  array<string, mixed>  $persistentData
     * @param  array<string, mixed>  $resultData
     */
    public function capture(Request $request, array $persistentData, array $resultData): CaptureResult
    {
        try {
            if ($this->wasCaptured($resultData)) {
                return new CaptureResult(isSuccessful: true, persistentData: $resultData);
            }

            if ($this->wasAborted($resultData)) {
                return new CaptureResult(isSuccessful: false, persistentData: [
                    ...$resultData,
                    'capture_error' => 'already_aborted',
                ]);
            }

            $token = (string) data_get($resultData, 'credit2000.token', '');

            if ($token === '') {
                return new CaptureResult(isSuccessful: false, persistentData: [
                    ...$resultData,
                    'capture_error' => 'missing_token',
                ]);
            }

            $prepareAction = (string) ($persistentData['prepare_action_type'] ?? '');

            // Defense in depth: never CreditXML-charge a Test (action_Type=2) prepare.
            if ($prepareAction === self::ACTION_TEST) {
                return new CaptureResult(isSuccessful: false, persistentData: [
                    ...$resultData,
                    'capture_error' => 'test_mode_prepare_cannot_charge',
                ]);
            }

            // Payment page already charged (prepare_action_type=4).
            if ((bool) data_get($resultData, 'credit2000.charged_on_page', false)
                || $prepareAction === self::ACTION_CHARGE) {
                $resultData['capture'] = [
                    'returnCode' => self::RETURN_OK,
                    'mode' => 'charged_on_payment_page',
                    'confirmationNumber' => (string) data_get($resultData, 'credit2000.approveNum', ''),
                ];

                return new CaptureResult(isSuccessful: true, persistentData: $resultData);
            }

            [$month, $year] = $this->parseValidDate((string) data_get($resultData, 'credit2000.validDate', ''));
            $total = (string) ($persistentData['total_pyment'] ?? '');
            $currency = (string) ($persistentData['currency_code'] ?? '1');

            $capture = Credit2000Connector::make()->payment()->creditXml([
                'cardNumber' => $token,
                'validationMonth' => $month ?? '00',
                'validationYear' => $year ?? '00',
                'actionType' => self::ACTION_CHARGE,
                'totalPayment' => $total,
                'firstPayment' => $total,
                'currency' => $currency,
                'cardType' => (string) data_get($resultData, 'credit2000.cardType', '1'),
                'customerId' => (string) data_get($resultData, 'credit2000.customerId', '000000001'),
                'confirmationNumber' => (string) data_get($resultData, 'credit2000.approveNum', '00000') ?: '00000',
                'tzNumber' => (string) ($persistentData['tz_number'] ?? '00000000') ?: '00000000',
            ]);

            $resultData['capture'] = $capture;

            return new CaptureResult(
                isSuccessful: $this->isProviderSuccess((string) ($capture['returnCode'] ?? '')),
                persistentData: $resultData
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }

        return new CaptureResult(isSuccessful: false, persistentData: [
            ...$resultData,
            'capture_error' => 'exception',
        ]);
    }

    /**
     * @param  array<string, mixed>  $persistentData
     * @param  array<string, mixed>  $resultData
     */
    public function abort(Request $request, array $persistentData, array $resultData): AbortResult
    {
        try {
            if ($this->wasAborted($resultData)) {
                return new AbortResult(isSuccessful: true, persistentData: $resultData);
            }

            $token = (string) data_get($resultData, 'credit2000.token', '');
            $alreadyCaptured = $this->wasCaptured($resultData);
            $chargedOnPage = (bool) data_get($resultData, 'credit2000.charged_on_page', false);

            // Approval-only and never captured: nothing to refund at the provider.
            if ($token === '' || (! $alreadyCaptured && ! $chargedOnPage)) {
                $resultData['cancel'] = [
                    'returnCode' => self::RETURN_OK,
                    'mode' => 'release_uncaptured_approval',
                ];

                return new AbortResult(isSuccessful: true, persistentData: $resultData);
            }

            $total = (string) ($persistentData['total_pyment'] ?? '');
            $currency = (string) ($persistentData['currency_code'] ?? '1');
            [$month, $year] = $this->parseValidDate((string) data_get($resultData, 'credit2000.validDate', ''));

            $cancel = Credit2000Connector::make()->payment()->creditXml([
                'cardNumber' => $token,
                'validationMonth' => $month ?? '00',
                'validationYear' => $year ?? '00',
                'actionType' => self::ACTION_REFUND,
                'totalPayment' => $total,
                'firstPayment' => $total,
                'currency' => $currency,
                'cardType' => (string) data_get($resultData, 'credit2000.cardType', '1'),
                'customerId' => (string) data_get($resultData, 'credit2000.customerId', '000000001'),
                'confirmationNumber' => (string) (
                    data_get($resultData, 'capture.confirmationNumber')
                    ?: data_get($resultData, 'credit2000.approveNum')
                    ?: '00000'
                ),
                'tzNumber' => (string) ($persistentData['tz_number'] ?? '00000000') ?: '00000000',
            ]);

            $resultData['cancel'] = $cancel;

            return new AbortResult(
                isSuccessful: $this->isProviderSuccess((string) ($cancel['returnCode'] ?? '')),
                persistentData: $resultData
            );
        } catch (Throwable $exception) {
            $this->reportFailure($exception);
        }

        return new AbortResult(isSuccessful: false, persistentData: [
            ...$resultData,
            'abort_error' => 'exception',
        ]);
    }

    /**
     * @param  array<string, mixed>  $resultData
     */
    private function wasCaptured(array $resultData): bool
    {
        return $this->isProviderSuccess((string) data_get($resultData, 'capture.returnCode', ''));
    }

    /**
     * @param  array<string, mixed>  $resultData
     */
    private function wasAborted(array $resultData): bool
    {
        return $this->isProviderSuccess((string) data_get($resultData, 'cancel.returnCode', ''));
    }

    private function isProviderSuccess(string $returnCode): bool
    {
        return $returnCode === self::RETURN_OK || $returnCode === '0';
    }

    /**
     * @param  array<string, mixed>  $persistentData
     * @param  array<string, string>  $tokenResponse
     */
    private function providerAuthorizationMismatch(
        array $persistentData,
        string $callbackUid,
        array $tokenResponse
    ): ?string {
        if (($tokenResponse['token'] ?? '') === '') {
            return 'token_missing';
        }

        $expectedProductId = (string) ($persistentData['product_id'] ?? '');
        $providerProductId = (string) ($tokenResponse['product_Id'] ?? '');

        if ($expectedProductId === '' || $providerProductId === '' || $providerProductId !== $expectedProductId) {
            return 'product_id_mismatch';
        }

        $expectedTotal = (string) ($persistentData['total_pyment'] ?? '');
        $providerTotal = (string) ($tokenResponse['total_Pyment'] ?? '');

        if ($expectedTotal === '' || $providerTotal === '' || $providerTotal !== $expectedTotal) {
            return 'amount_mismatch';
        }

        $expectedCurrency = (string) ($persistentData['currency_code'] ?? '');
        $providerCurrency = (string) ($tokenResponse['currency'] ?? '');

        if ($expectedCurrency === '' || $providerCurrency === '' || $providerCurrency !== $expectedCurrency) {
            return 'currency_mismatch';
        }

        $expectedAction = (string) ($persistentData['prepare_action_type'] ?? '');
        $providerAction = (string) ($tokenResponse['action_Type'] ?? '');

        if ($expectedAction === '' || $providerAction === '' || $providerAction !== $expectedAction) {
            return 'action_type_mismatch';
        }

        $providerUid = (string) ($tokenResponse['uID'] ?? '');

        if ($providerUid === '' || $providerUid !== $callbackUid) {
            return 'uid_mismatch';
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string} [MM, YY]
     */
    private function parseValidDate(string $validDate): array
    {
        if (! preg_match('/^\d{4}$/', $validDate)) {
            return [null, null];
        }

        return [substr($validDate, 0, 2), substr($validDate, 2, 2)];
    }

    private function reportFailure(Throwable $exception): void
    {
        $message = $exception->getMessage();
        $message = preg_replace('/(company_Key|ClientKey|cardNumber|token)=[^&\s"\']*/i', '$1=redacted', $message) ?? $message;
        $message = preg_replace('#https?://[^\s]+#i', '[redacted-url]', $message) ?? $message;

        report(new RuntimeException('Credit2000 request failed: '.$exception::class.': '.$message));
    }

    /**
     * @return array<string, string>
     */
    private function callbackParams(Request $request): array
    {
        /** @var array<string, string> $params */
        $params = [];

        foreach (array_merge($request->query(), $request->request->all()) as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            if (in_array($key, ['checkoutId', 'itineraryId', 'origin', 'lang', 'rest-payment', 'transaction'], true)) {
                continue;
            }

            $params[(string) $key] = (string) $value;
        }

        return $params;
    }
}
