# Credit2000 (Tourismo Filipino Checkout)

Hosted payment page via Credit2000 SOAP ASMX (`SendParamToCredit2000` → redirect → `getTokenAndApprove` → `CreditXML`).

## Env vars (do not commit secrets)

```env
CHECKOUT_CREDIT2000_ACTIVE=false
CHECKOUT_CREDIT2000_NAME=Credit2000
CHECKOUT_CREDIT2000_BASE_URL=https://www.credit2000.co.il/pci_tkn_ver7/WCF/wsCredit2000.asmx
CHECKOUT_CREDIT2000_VENDOR_NAME=
CHECKOUT_CREDIT2000_COMPANY_KEY=
CHECKOUT_CREDIT2000_LANG=he
CHECKOUT_CREDIT2000_PREPARE_ACTION_TYPE=5
CHECKOUT_CREDIT2000_PURCHASE_TYPE=1
```

Enable only on staging after credentials are configured:

```env
CHECKOUT_CREDIT2000_ACTIVE=true
```

## Lifecycle (Nezasa authorize → book → capture/abort)

| Package step | Credit2000 action | Success |
|--------------|-------------------|---------|
| `prepare()` | `SendParamToCredit2000` (`return_Code=123`) | hosted payment URL |
| `authorize()` | callback `params` (uid) + `getTokenAndApprovePro` | token + provider `product_Id` / `total_Pyment` / `currency` / `action_Type` / `uID` match prepare data |
| `capture()` | `CreditXML` `actionType=4` (or no-op if page already charged) | `returnCode=000` |
| `abort()` | release uncaptured approval, or `CreditXML` `actionType=7` refund | `returnCode=000` |

### `prepare_action_type`

| Value | Meaning |
|-------|---------|
| `5` | Approval only (preferred). Capture charges later via CreditXML. |
| `4` | Charge on payment page. Capture is treated as already done. |
| `2` | SendParams Test mode — **rejected by checkout**. Not safe: capture would still call CreditXML `actionType=4` (charge). Use a Credit2000 test terminal with `5` or `4` instead. |

## Amounts

`total_Pyment` / `totalPayment` use **minor units** (agorot). Example: `₪101.00` → `10100`.

## Portal / provider requirements

1. Confirm the ASMX endpoint version (`pci_tkn_ver7` vs older `ver2`) for this merchant.
2. Provide production + test `vendor_Name` and `company_Key`.
3. Confirm whether **approval-only (`5`)** is enabled for the terminal (needed for two-phase booking).
4. Confirm refund (`CreditXML` action `7`) works for aborted bookings after charge.
5. Ensure success redirect lands on the Checkout App result URL (passed as `host`).

## UNVERIFIED until tested with real credentials

- Exact success / failure `returnCode` variants beyond `000`
- `getTokenAndApprovePro` field population on the live terminal (authorization binding is implemented in code but **not resolved until staging validates Pro responses**)
- Installments (`purchase_Type=2`) — currently defaulted to regular (`1`)
