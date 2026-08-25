<?php

declare(strict_types=1);

namespace Nezasa\Checkout\Integrations\Credit2000\Resources;

use Illuminate\Support\Facades\Config;
use Nezasa\Checkout\Integrations\Credit2000\Requests\Credit2000SoapRequest;
use Nezasa\Checkout\Integrations\Credit2000\Support\Credit2000Xml;
use Saloon\Http\BaseResource;

class Credit2000PaymentResource extends BaseResource
{
    /**
     * Create a hosted payment page and return its URL (return_Code=123).
     *
     * @param  array<string, string|null>  $params
     * @return array{payment_url: ?string, raw: string, http_status: int}
     */
    public function sendParams(array $params): array
    {
        $fields = array_merge([
            'vendor_Name' => Config::string('checkout.integrations.credit2000.vendor_name'),
            'company_Key' => Config::string('checkout.integrations.credit2000.company_key'),
            'action_Type' => Config::string('checkout.integrations.credit2000.prepare_action_type'),
            'Lang' => Config::string('checkout.integrations.credit2000.lang'),
            'card_Reader' => '0',
            'stars' => '0',
            'club' => '',
            'confirmation_Source' => '0',
            'purchase_Type' => '1',
            'return_Code' => '123',
            'fixed_Amount' => '000000',
            'payments_Number' => '1',
            'Approve' => '0000000',
            'ValidDate' => '',
            'StyleSheet' => '',
            'reader_Data' => '',
            'uID' => '',
            'card_type' => '',
            'sp_type' => '',
            'manpik' => '',
            'solek' => '',
            'brand' => '',
            'original_uid' => '',
            'stream' => '',
            'tz_Number' => '',
            'client_Name' => '',
        ], $params);

        // Regular purchase: first payment equals total.
        if (($fields['purchase_Type'] ?? '1') === '1') {
            $fields['first_Payment'] = $fields['total_Pyment'] ?? ($fields['first_Payment'] ?? '0');
            $fields['fixed_Amount'] = '000000';
            $fields['payments_Number'] = '1';
        }

        $inner = Credit2000Xml::elements($fields);
        $body = $this->envelope('SendParamToCredit2000', '<parametr>'.$inner.'</parametr>');

        $response = $this->connector->send(new Credit2000SoapRequest(
            'http://tempuri.org/SendParamToCredit2000',
            $body
        ));

        $raw = $response->body();

        return [
            'payment_url' => Credit2000Xml::extractPaymentUrl($raw),
            'raw' => $raw,
            'http_status' => $response->status(),
        ];
    }

    /**
     * Exchange payment-page uid for a card token + approval metadata.
     *
     * @return array<string, string>
     */
    public function getTokenAndApprove(string $uid): array
    {
        $body = $this->envelope(
            'getTokenAndApprove',
            '<uid>'.Credit2000Xml::escape($uid).'</uid>'
            .'<approveNum></approveNum>'
            .'<returnCode></returnCode>'
            .'<customerId></customerId>'
            .'<validDate></validDate>'
            .'<cardType></cardType>'
        );

        $response = $this->connector->send(new Credit2000SoapRequest(
            'http://tempuri.org/getTokenAndApprove',
            $body
        ));

        $raw = $response->body();
        $parsed = Credit2000Xml::parseTaggedValues($raw, [
            'getTokenAndApproveResult',
            'approveNum',
            'returnCode',
            'customerId',
            'validDate',
            'cardType',
        ]);

        return [
            'token' => (string) ($parsed['getTokenAndApproveResult'] ?? ''),
            'approveNum' => (string) ($parsed['approveNum'] ?? ''),
            'returnCode' => (string) ($parsed['returnCode'] ?? ''),
            'customerId' => (string) ($parsed['customerId'] ?? ''),
            'validDate' => (string) ($parsed['validDate'] ?? ''),
            'cardType' => (string) ($parsed['cardType'] ?? ''),
            'raw' => $raw,
            'http_status' => (string) $response->status(),
        ];
    }

    /**
     * Charge / refund / approve-only against a tokenized card.
     *
     * actionType: 5=approval only | 2=check only | 4=charge | 7=refund
     *
     * @param  array<string, string|null>  $params
     * @return array<string, string>
     */
    public function creditXml(array $params): array
    {
        $fields = array_merge([
            'vendorName' => Config::string('checkout.integrations.credit2000.vendor_name'),
            'ClientKey' => Config::string('checkout.integrations.credit2000.company_key'),
            'validationMonth' => '',
            'validationYear' => '',
            'actionType' => '4',
            'paymentsNumber' => '1',
            'fixedAmmount' => '000',
            'customerId' => '000000001',
            'cvvNumber' => '000',
            'cardType' => '1',
            'purchaseType' => '1',
            'currency' => '1',
            'returnCode' => '000',
            'confirmationNumber' => '00000',
            'cardReader' => '0',
            'confirmationSource' => '2',
            'tzNumber' => '00000000',
            'club' => '0',
            'stars' => '0',
            'readerData' => '',
        ], $params);

        if (($fields['purchaseType'] ?? '1') === '1') {
            $fields['firstPayment'] = $fields['totalPayment'] ?? ($fields['firstPayment'] ?? '0');
            $fields['fixedAmmount'] = '000';
            $fields['paymentsNumber'] = '1';
        }

        $body = $this->envelope('CreditXML', Credit2000Xml::elements($fields));

        $response = $this->connector->send(new Credit2000SoapRequest(
            'http://tempuri.org/CreditXML',
            $body
        ));

        $raw = $response->body();
        $parsed = Credit2000Xml::parseTaggedValues($raw, [
            'CreditXMLResult',
            'returnCode',
            'ReturnCode',
            'approveNum',
            'ApproveNum',
            'confirmationNumber',
            'ConfirmationNumber',
        ]);

        $returnCode = (string) (
            $parsed['returnCode']
            ?? $parsed['ReturnCode']
            ?? $parsed['CreditXMLResult']
            ?? ''
        );

        return [
            'returnCode' => $returnCode,
            'approveNum' => (string) ($parsed['approveNum'] ?? $parsed['ApproveNum'] ?? ''),
            'confirmationNumber' => (string) ($parsed['confirmationNumber'] ?? $parsed['ConfirmationNumber'] ?? ''),
            'raw' => $raw,
            'http_status' => (string) $response->status(),
        ];
    }

    private function envelope(string $method, string $innerXml): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
            .'xmlns:xsd="http://www.w3.org/2001/XMLSchema" '
            .'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body>'
            .'<'.$method.' xmlns="http://tempuri.org/">'
            .$innerXml
            .'</'.$method.'>'
            .'</soap:Body>'
            .'</soap:Envelope>';
    }
}
