<?php
/**
 * Smoke test: calls every mock operation the way TipFlashClaim does
 * (same SoapClient options as SoapCustomerRequestsComponent::createNonMotorClient)
 * and reads the results with the same paths the app uses.
 *
 * Usage (mock server must be running):
 *   php tests/smoke-test.php [wsdl-url]
 *
 * Created: 2026-10-12.
 */

$wsdl = $argv[1] ?? 'http://127.0.0.1:8089/TipMobileWS/Service1.svc?wsdl';

function client(string $wsdl): SoapClient
{
    return new SoapClient($wsdl, [
        'location' => preg_replace('/\?.*/', '', $wsdl),
        'uri' => '#BasicHttpsBinding_IService1_policy',
        'stream_context' => stream_context_create([
            'http' => ['timeout' => 10],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        ]),
        'connection_timeout' => 10,
        'cache_wsdl' => WSDL_CACHE_NONE,
    ]);
}

// Same helper as TipFlashClaim's helpers.php
function object_to_array($data)
{
    return json_decode(json_encode($data), true);
}

function get($array, string $path)
{
    foreach (explode('.', $path) as $key) {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            return null;
        }
        $array = $array[$key];
    }

    return $array;
}

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? ' — ' . $detail : '');
}

function attempt(string $name, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        check($name, false, get_class($e) . ': ' . $e->getMessage());
    }
}

echo "WSDL: {$wsdl}\n\n";

attempt('GetBankList', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetBankList());
    $banks = get($r, 'GetBankListResult.data.Bank');
    check('GetBankList', is_array($banks) && count($banks) > 1 && isset($banks[0]['T_Name'], $banks[0]['BankCode']),
        count((array)$banks) . ' banks, first = ' . ($banks[0]['BankCode'] ?? '?') . ' ' . ($banks[0]['T_Name'] ?? '?'));
});

attempt('GetBankBranchList', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetBankBranchList(['input' => ['BankCode' => '006']]));
    $rows = get($r, 'GetBankBranchListResult.data.BankBranch');
    check('GetBankBranchList', is_array($rows) && isset($rows[0]['BankBranchCode'], $rows[0]['T_Name']),
        count((array)$rows) . ' branches, first = ' . ($rows[0]['T_Name'] ?? '?'));
});

attempt('GetProvinceData', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetProvinceData(['input' => ['Lang' => null]]));
    $rows = get($r, 'GetProvinceDataResult.data.ProvinceList');
    check('GetProvinceData', is_array($rows) && isset($rows[0]['ProvinceId'], $rows[0]['ProvinceName']),
        count((array)$rows) . ' provinces');
});

attempt('GetAmphurData', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetAmphurData(['input' => ['Lang' => null, 'ProvinceId' => '10']]));
    $rows = get($r, 'GetAmphurDataResult.data.AmphurList');
    check('GetAmphurData (fixture)', is_array($rows) && ($rows[0]['AmphurName'] ?? '') === 'พระนคร', count((array)$rows) . ' amphurs');

    $r = object_to_array(client($wsdl)->GetAmphurData(['input' => ['Lang' => null, 'ProvinceId' => 77]]));
    $rows = get($r, 'GetAmphurDataResult.data.AmphurList');
    check('GetAmphurData (generated)', is_array($rows) && ($rows[0]['AmphurId'] ?? '') === '7701', ($rows[0]['AmphurName'] ?? '?'));
});

attempt('GetDistrictData', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetDistrictData(['input' => ['Lang' => null, 'AmphurId' => '1004']]));
    $rows = get($r, 'GetDistrictDataResult.data.DistrictList');
    check('GetDistrictData', is_array($rows) && ($rows[0]['DistrictZipCode'] ?? '') === '10500', count((array)$rows) . ' districts');
});

attempt('GetPolicyNonMotor', function () use ($wsdl) {
    // By policy number (what admin /health/claim/retrieve does for search_type=policy)
    $policyNo = '14025-107-230002956';
    $r = object_to_array(client($wsdl)->GetPolicyNonMotor(['policyNo' => $policyNo]));
    $json = object_to_array(json_decode(get($r, 'GetPolicyNonMotorResult')));
    $data = get($json, 'Data');
    check('GetPolicyNonMotor by policyNo (generated)', get($json, 'ResultCode') === '00' && ($data[0]['PolicyNo'] ?? '') === $policyNo
        && !empty($data[0]['CoverageList']), 'PolicyNo=' . ($data[0]['PolicyNo'] ?? '?') . ', coverages=' . count((array)($data[0]['CoverageList'] ?? [])));

    $r = object_to_array(client($wsdl)->GetPolicyNonMotor(['policyNo' => 'MOCK-FR-000001']));
    $data = get(object_to_array(json_decode(get($r, 'GetPolicyNonMotorResult'))), 'Data');
    check('GetPolicyNonMotor by policyNo (fixture)', ($data[0]['SubClass'] ?? '') === 'FR', ($data[0]['LastName'] ?? '?'));

    $r = object_to_array(client($wsdl)->GetPolicyNonMotor(['idCardNo' => '1100100100001']));
    $data = get(object_to_array(json_decode(get($r, 'GetPolicyNonMotorResult'))), 'Data');
    check('GetPolicyNonMotor by idCardNo', ($data[0]['PolicyNo'] ?? '') === 'MOCK-PA-000001', ($data[0]['PolicyNo'] ?? '?'));

    $r = object_to_array(client($wsdl)->GetPolicyNonMotor(['firstname' => 'สมชาย', 'lastname' => 'ใจดี']));
    $data = get(object_to_array(json_decode(get($r, 'GetPolicyNonMotorResult'))), 'Data');
    check('GetPolicyNonMotor by name (generated)', ($data[0]['Firstname'] ?? '') === 'สมชาย', ($data[0]['PolicyHolderName'] ?? '?'));

    $r = object_to_array(client($wsdl)->GetPolicyNonMotor(['policyNo' => 'NOTFOUND-1']));
    $json = object_to_array(json_decode(get($r, 'GetPolicyNonMotorResult')));
    check('GetPolicyNonMotor NOTFOUND', get($json, 'ResultCode') === '01' && empty(get($json, 'Data')));

    $r = object_to_array(client($wsdl)->GetPolicyNonMotor(['policyNo' => 'TOOMANY-1']));
    $json = object_to_array(json_decode(get($r, 'GetPolicyNonMotorResult')));
    check('GetPolicyNonMotor TOOMANY', get($json, 'ResultCode') === '03');

    try {
        client($wsdl)->GetPolicyNonMotor(['policyNo' => 'FAULT-1']);
        check('GetPolicyNonMotor FAULT', false, 'no SoapFault thrown');
    } catch (SoapFault $e) {
        check('GetPolicyNonMotor FAULT', true, $e->getMessage());
    }
});

attempt('GetClaimNonMotor', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetClaimNonMotor(['ClaimNo' => 'MOCK-CLAIM-APPROVE']));
    $json = object_to_array(json_decode(get($r, 'GetClaimNonMotorResult')));
    check('GetClaimNonMotor', get($json, 'ResultCode') === '00' && get($json, 'Data.0.Status') === 'Approve', get($json, 'Data.0.Status') ?? '?');
});

attempt('GetClaimOTPDetail', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetClaimOTPDetail(['ClaimNo' => 'MOCK-CLAIM-OTP']));
    $result = get($r, 'GetClaimOTPDetailResult');
    check('GetClaimOTPDetail (fixture)', get($result, 'ResultCode') === '00' && get($result, 'Data.OTPDetail.OTP') === '123456');

    $r = object_to_array(client($wsdl)->GetClaimOTPDetail(['ClaimNo' => 'X']));
    check('GetClaimOTPDetail (none)', get($r, 'GetClaimOTPDetailResult.ResultCode') === '01');
});

attempt('GetAddressCIS', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetAddressCIS([
        'FirstName' => 'สมชาย', 'LastName' => 'ใจดี', 'OrganizationName' => '', 'Branch' => '', 'EntityType' => '',
    ]));
    $row = get($r, 'GetAddressCISResult.data.AddressCIS');
    // Single result → PHP gives an object (assoc array), same as the real service; the app handles both.
    check('GetAddressCIS', ($row['T_FirstName'] ?? '') === 'สมชาย', $row['FullAddress'] ?? '?');
});

attempt('GetZipCode / GetClaim', function () use ($wsdl) {
    $r = object_to_array(client($wsdl)->GetZipCode(['input' => ['DistrictId' => '100401']]));
    check('GetZipCode', get($r, 'GetZipCodeResult.ResultCode') === '00');
    $r = object_to_array(client($wsdl)->GetClaim(['ClaimNo' => 'X']));
    check('GetClaim', is_string(get($r, 'GetClaimResult')));
});

echo "\n" . ($failures === 0 ? 'ALL PASSED' : "{$failures} FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
