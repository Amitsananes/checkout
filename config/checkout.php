<?php

use Nezasa\Checkout\Insurances\Providers\Ergo\ErgoInsurance;
use Nezasa\Checkout\Insurances\Providers\HanseMerkur\HanseMerkurInsurance;
use Nezasa\Checkout\Payments\Gateways\Computop\ComputopGateway;
use Nezasa\Checkout\Payments\Gateways\Computop\ComputopTokenGateway;
use Nezasa\Checkout\Payments\Gateways\Credit2000\Credit2000Gateway;
use Nezasa\Checkout\Payments\Gateways\Invoice\InvoiceGateway;
use Nezasa\Checkout\Payments\Gateways\Oppwa\OppwaWidgetGateway;
use Nezasa\Checkout\Payments\Gateways\Stripe\StripeGateway;

return [

    'distribution' => [
        'max_child_age' => env('MAC_CHILD_CHECKOUT_AGE', 17),
    ],

    /**
     * All the configuration options for the Nezasa API is defined here.
     */
    'nezasa' => [
        'ibe_base_url' => trim(env('CHECKOUT_NEZASA_IBE_BASE_URL', 'https://milzercheckout-ibe.stg.tripbuilder.app/test/cms/milzercheckout')),
        'base_url' => trim(env('CHECKOUT_NEZASA_BASE_URL', 'https://api.tripbuilder.app')),
        'username' => env('CHECKOUT_NEZASA_USERNAME', 'must_be_set_in_env'),
        'password' => env('CHECKOUT_NEZASA_PASSWORD', 'must_be_set_in_env'),
    ],

    /**
     * Contact support mailto target. Set CHECKOUT_CONTACT_SUPPORT_EMAIL (e.g. support@example.com).
     * Used wherever "Contact support" appears in checkout views.
     */
    'contact_support' => [
        'email' => env('CHECKOUT_CONTACT_SUPPORT_EMAIL', 'contact@nezasa.com'),
    ],

    'countries' => [
        'prioritized_iso_codes' => explode(',', env('CHECKOUT_PRIORITIZED_COUNTRY_CODES', '')),
        'prioritized_fields' => explode(',', env('CHECKOUT_PRIORITIZED_COUNTRY_FIELDS', '')),
    ],

    'integrations' => [
        'oppwa' => [
            'active' => (bool) env('CHECKOUT_WIDGET_OPPWA_ACTIVE', false),
            'name' => env('CHECKOUT_WIDGET_OPPWA_NAME', 'oppwa'),
            'base_url' => env('CHECKOUT_WIDGET_OPPWA_BASE_URL', 'https://eu-test.oppwa.com'),
            'entity_id' => env('CHECKOUT_WIDGET_OPPWA_ENTITY_ID', 'must_be_set_in_env'),
            'token' => env('CHECKOUT_WIDGET_OPPWA_TOKEN', 'must_be_set_in_env'),
            'successful_result_code' => env('CHECKOUT_WIDGET_OPPWA_SUCCESSFUL_RESULT_CODE', '000.000.000'),
        ],
        'invoice' => [
            'active' => (bool) env('CHECKOUT_WIDGET_INVOICE_ACTIVE', false),
            'name' => env('CHECKOUT_WIDGET_INVOICE_NAME', 'Invoice'),
        ],
        'stripe' => [
            'active' => (bool) env('CHECKOUT_STRIPE_ACTIVE', false),
            'name' => env('CHECKOUT_STRIPE_NAME', 'Stripe'),
            'secret_key' => env('CHECKOUT_STRIPE_SECRET_KEY', 'test'),
        ],
        'computop' => [
            'active' => (bool) env('CHECKOUT_COMPUTOP_ACTIVE', false),
            'test_mode' => (bool) env('CHECKOUT_COMPUTOP_TEST_MODE', false),
            'name' => env('CHECKOUT_COMPUTOP_NAME', 'Computop'),
            'base_url' => env('CHECKOUT_COMPUTOP_BASE_URL', 'https://www.computop-paygate.com/api/v1'),
            'username' => env('CHECKOUT_COMPUTOP_USERNAME', 'must_be_set_in_env'),
            'password' => env('CHECKOUT_COMPUTOP_PASSWORD', 'must_be_set_in_env'),
        ],
        'computop_token' => [
            'active' => (bool) env('CHECKOUT_COMPUTOP_TOKEN_ACTIVE', false),
            'name' => env('CHECKOUT_COMPUTOP_TOKEN_NAME', 'Computop - Token'),
        ],
        /**
         * Credit2000 hosted payment page (SOAP ASMX).
         *
         * Keep CHECKOUT_CREDIT2000_ACTIVE=false until vendor/company keys are configured
         * and an end-to-end booking has been verified on staging.
         *
         * prepare_action_type:
         * - "5" = approval only (preferred for authorize → book → capture)
         * - "4" = charge on payment page (capture becomes a no-op)
         * - "2" = test mode per Credit2000 docs
         */
        'credit2000' => [
            'active' => (bool) env('CHECKOUT_CREDIT2000_ACTIVE', false),
            'name' => env('CHECKOUT_CREDIT2000_NAME', 'Credit2000'),
            'base_url' => env('CHECKOUT_CREDIT2000_BASE_URL', 'https://www.credit2000.co.il/pci_tkn_ver7/WCF/wsCredit2000.asmx'),
            'vendor_name' => env('CHECKOUT_CREDIT2000_VENDOR_NAME', 'must_be_set_in_env'),
            'company_key' => env('CHECKOUT_CREDIT2000_COMPANY_KEY', 'must_be_set_in_env'),
            'lang' => env('CHECKOUT_CREDIT2000_LANG', 'he'),
            'prepare_action_type' => env('CHECKOUT_CREDIT2000_PREPARE_ACTION_TYPE', '5'),
            'purchase_type' => env('CHECKOUT_CREDIT2000_PURCHASE_TYPE', '1'),
        ],
        'passolution' => [
            'active' => (bool) env('CHECKOUT_PASSOLUTION_ACTIVE', false),
            'base_url' => env('CHECKOUT_PASSOLUTION_BASE_URL', 'https://api.passolution.eu/api/v2'),
            'token' => env('CHECKOUT_PASSOLUTION_TOKEN', ''),
        ],
    ],

    'insurance' => [
        'vertical' => [
            'active' => (bool) env('CHECKOUT_VERTICAL_INSURACNE_ACTIVE', false),
            'name' => env('CHECKOUT_VERTICAL_INSURACNE_NAME', 'Vertical Insurance'),
            'connected_account_id' => env('CHECKOUT_VERTICAL_INSURANCE_CONNECTED_ACCOUNT_ID', 'must_be_set_in_env'),
            'username' => env('CHECKOUT_VERTICAL_INSURANCE_USERNAME', 'must_be_set_in_env'),
            'password' => env('CHECKOUT_VERTICAL_INSURANCE_PASSWORD', 'must_be_set_in_env'),
        ],

        'hanse_merkur' => [
            'active' => (bool) env('CHECKOUT_HANSE_MERKUR_INSURANCE_ACTIVE', false),
            'name' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_NAME', 'Hanse Merkur'),
            'logo' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_LOGO'),
            'offers_base_url' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_OFFERS_BASE_URL', 'https://api-fbt.hmrv.de/rest'),
            'payment_base_url' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_PAYMENT_BASE_URL', 'https://payment-test.hmrv.de/rest'),
            'username' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_USERNAME', 'must_be_set_in_env'),
            'password' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_PASSWORD', 'must_be_set_in_env'),
            'api_key' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_API_KEY', 'must_be_set_in_env'),
            'requester_id' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_REQUESTER_ID', 'must_be_set_in_env'),
            'partner_id' => env('CHECKOUT_HANSE_MERKUR_INSURANCE_PARTNER_ID', 'must_be_set_in_env'),
        ],

        /**
         * ERGO / ERV eSoap (SOAP 1.1). WSDL ships with the package next to ErgoConnector.
         *
         * Defaults target the public ERV test gateway; override via env in production.
         */
        'ergo' => [
            'active' => (bool) env('CHECKOUT_ERGO_INSURANCE_ACTIVE', false),
            'name' => env('CHECKOUT_ERGO_INSURANCE_NAME', 'Ergo'),
            'logo' => env('CHECKOUT_ERGO_INSURANCE_LOGO'),
            'base_url' => env('CHECKOUT_ERGO_INSURANCE_BASE_URL', 'https://egate2.erv.de/esc201909/ESCConnector'),
            'crs' => env('CHECKOUT_ERGO_INSURANCE_CRS', 'must_be_set_in_env'),
            'crs_agency' => env('CHECKOUT_ERGO_INSURANCE_CRS_AGENCY', 'must_be_set_in_env'),
            'initiator' => env('CHECKOUT_ERGO_INSURANCE_INITIATOR', 'customer'),
            'agent' => env('CHECKOUT_ERGO_INSURANCE_AGENT', 'customer'),
            'locale_country' => env('CHECKOUT_ERGO_INSURANCE_LOCALE_COUNTRY', 'DE'),
            'locale_language' => env('CHECKOUT_ERGO_INSURANCE_LOCALE_LANGUAGE', 'de'),
            'locale_currency' => env('CHECKOUT_ERGO_INSURANCE_LOCALE_CURRENCY', 'EUR'),
            'list_type' => env('CHECKOUT_ERGO_INSURANCE_LIST_TYPE', 'DE_STANDARD'),
            'auto_quote' => (bool) env('CHECKOUT_ERGO_INSURANCE_AUTO_QUOTE', true),
            'echo_token' => env('CHECKOUT_ERGO_INSURANCE_ECHO_TOKEN'),
            'transaction_context' => env('CHECKOUT_ERGO_INSURANCE_TRANSACTION_CONTEXT'),
        ],
    ],

    'payment' => [
        OppwaWidgetGateway::class,
        InvoiceGateway::class,
        StripeGateway::class,
        ComputopGateway::class,
        ComputopTokenGateway::class,
        Credit2000Gateway::class,
    ],

    'insurance_provider' => [
        HanseMerkurInsurance::class,
        ErgoInsurance::class,
    ],

    'term_limit' => (int) env('CHECKOUT_TERM_LIMIT', 600),

    'fake_calls' => env('CHECKOUT_FAKE_NEZASA_CALLS', false),
];
