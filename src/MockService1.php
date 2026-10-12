<?php
/**
 * Handler for the mock TipMobileWS/Service1.svc — one public method per SOAP
 * operation (PHP's SoapServer dispatches by operation name). Each method gets
 * the request wrapper element as a stdClass and returns the response wrapper's
 * content as an array, e.g. ['GetBankListResult' => [...]].
 *
 * Data comes from ../data/*.json so test data can be changed without touching
 * code. Lookups that have no fixture fall back to generated data, so a
 * TipFlashClaim page never dies just because a code isn't in the fixtures.
 *
 * Special policy numbers for testing error paths (GetPolicyNonMotor):
 *   NOTFOUND...  → ResultCode "01", empty Data   (app: "ไม่พบข้อมูลกรมธรรม์")
 *   TOOMANY...   → ResultCode "03", empty Data   (app: "กรุณาเปลี่ยนไปค้นหาด้วย...")
 *   FAULT...     → SOAP Fault                    (app: SoapFault / error 500 path)
 *
 * Created: 2026-10-12.
 */
class MockService1
{
    const OK = '00';
    const NOT_FOUND = '01';
    const TOO_MANY = '03';

    /** @var string */
    private $dataDir;

    /** @var array<string, mixed> */
    private $cache = [];

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
    }

    // ------------------------------------------------------------------
    // Policy
    // ------------------------------------------------------------------

    public function GetPolicyNonMotor($params)
    {
        $policyNo = $this->param($params, 'policyNo');
        $idCardNo = $this->param($params, 'idCardNo');
        $firstName = $this->param($params, 'firstname');
        $lastName = $this->param($params, 'lastname');

        $key = strtoupper((string)$policyNo);
        if (strpos($key, 'FAULT') === 0) {
            throw new SoapFault('Server', 'MOCK: simulated SOAP fault for policyNo ' . $policyNo);
        }
        if (strpos($key, 'NOTFOUND') === 0) {
            return ['GetPolicyNonMotorResult' => $this->jsonResult(self::NOT_FOUND, 'MOCK: Data not found', [])];
        }
        if (strpos($key, 'TOOMANY') === 0) {
            return ['GetPolicyNonMotorResult' => $this->jsonResult(self::TOO_MANY, 'MOCK: Too many records, please search by ID card or name', [])];
        }

        $fixtures = $this->load('policies.json');
        $matches = array_values(array_filter($fixtures, function ($policy) use ($policyNo, $idCardNo, $firstName, $lastName) {
            if ($policyNo !== null && $policyNo !== '') {
                return $this->same($policy['PolicyNo'] ?? null, $policyNo);
            }
            if ($idCardNo !== null && $idCardNo !== '') {
                return $this->same($policy['IDCardNo'] ?? null, $idCardNo);
            }
            if ($firstName !== null || $lastName !== null) {
                return $this->same($policy['Firstname'] ?? null, $firstName)
                    && $this->same($policy['LastName'] ?? null, $lastName);
            }

            return false;
        }));

        if (empty($matches)) {
            // No fixture: generate one policy that echoes the search back, so
            // the app's own "{n}[PolicyNo=...]" / name / ID checks still match.
            $generated = [];
            if ($policyNo !== null && $policyNo !== '') {
                $generated['PolicyNo'] = $policyNo;
            }
            if ($idCardNo !== null && $idCardNo !== '') {
                $generated['IDCardNo'] = $idCardNo;
                $generated['PolicyNo'] = 'MOCK-PA-' . substr($idCardNo, -6);
            }
            if ($firstName !== null && $firstName !== '') {
                $generated['Firstname'] = $firstName;
            }
            if ($lastName !== null && $lastName !== '') {
                $generated['LastName'] = $lastName;
            }
            $matches = [$generated];
        }

        $data = array_map([$this, 'makePolicy'], $matches);

        return ['GetPolicyNonMotorResult' => $this->jsonResult(self::OK, 'MOCK: Success', $data)];
    }

    // ------------------------------------------------------------------
    // Province / Amphur / District / ZipCode
    // ------------------------------------------------------------------

    public function GetProvinceData($params)
    {
        $provinces = $this->load('provinces.json');

        return ['GetProvinceDataResult' => $this->listResult('ProvinceList', $provinces)];
    }

    public function GetAmphurData($params)
    {
        $provinceId = (string)$this->param($params, 'input.ProvinceId');
        $all = $this->load('amphurs.json');
        $amphurs = $all[$provinceId] ?? null;

        if ($amphurs === null) {
            $amphurs = [];
            for ($i = 1; $i <= 3; $i++) {
                $amphurs[] = [
                    'AmphurId' => $provinceId . sprintf('%02d', $i),
                    'AmphurName' => 'อำเภอทดสอบ ' . $i,
                ];
            }
        }
        foreach ($amphurs as &$amphur) {
            $amphur['ProvinceId'] = $provinceId;
        }
        unset($amphur);

        return ['GetAmphurDataResult' => $this->listResult('AmphurList', $amphurs)];
    }

    public function GetDistrictData($params)
    {
        $amphurId = (string)$this->param($params, 'input.AmphurId');
        $all = $this->load('districts.json');
        $districts = $all[$amphurId] ?? null;

        if ($districts === null) {
            $districts = [];
            for ($i = 1; $i <= 3; $i++) {
                $districts[] = [
                    'DistrictId' => $amphurId . sprintf('%02d', $i),
                    'DistrictName' => 'ตำบลทดสอบ ' . $i,
                    'DistrictZipCode' => '10000',
                ];
            }
        }
        foreach ($districts as &$district) {
            $district['AmphurId'] = $amphurId;
        }
        unset($district);

        return ['GetDistrictDataResult' => $this->listResult('DistrictList', $districts)];
    }

    public function GetZipCode($params)
    {
        $districtId = (string)$this->param($params, 'input.DistrictId');
        $rows = [[
            'ZipCode' => '10000',
            'DistrictName' => 'ตำบลทดสอบ ' . $districtId,
            'AmphurName' => 'อำเภอทดสอบ',
            'ProvinceName' => 'กรุงเทพมหานคร',
        ]];

        return ['GetZipCodeResult' => $this->listResult('ZipCodeList', $rows)];
    }

    // ------------------------------------------------------------------
    // Bank / BankBranch
    // ------------------------------------------------------------------

    public function GetBankList($params = null)
    {
        $banks = $this->load('banks.json');

        return ['GetBankListResult' => $this->listResult('Bank', $banks)];
    }

    public function GetBankBranchList($params)
    {
        $bankCode = (string)$this->param($params, 'input.BankCode');
        $all = $this->load('bank_branches.json');
        $branches = $all[$bankCode] ?? $all['default'] ?? [];

        foreach ($branches as &$branch) {
            $branch['BankCode'] = $bankCode;
        }
        unset($branch);

        return ['GetBankBranchListResult' => $this->listResult('BankBranch', $branches)];
    }

    // ------------------------------------------------------------------
    // Claim status / OTP / misc
    // ------------------------------------------------------------------

    public function GetClaimNonMotor($params)
    {
        $claimNo = (string)$this->param($params, 'ClaimNo');
        $claims = $this->load('claims.json');
        $status = $claims['status'][$claimNo] ?? $claims['default_status'] ?? 'MOCK: อยู่ระหว่างพิจารณา';

        $data = [[
            'ClaimNo' => $claimNo,
            'Status' => $status,
        ]];

        return ['GetClaimNonMotorResult' => $this->jsonResult(self::OK, 'MOCK: Success', $data)];
    }

    public function GetClaimOTPDetail($params)
    {
        $claimNo = (string)$this->param($params, 'ClaimNo');
        $claims = $this->load('claims.json');
        $otp = $claims['otp'][$claimNo] ?? null;

        if ($otp === null) {
            return ['GetClaimOTPDetailResult' => [
                'ResultCode' => self::NOT_FOUND,
                'ResultDescription' => 'MOCK: No OTP for this claim',
                'Data' => null,
            ]];
        }

        return ['GetClaimOTPDetailResult' => [
            'ResultCode' => self::OK,
            'ResultDescription' => 'MOCK: Success',
            'Data' => ['OTPDetail' => array_merge(['ClaimNo' => $claimNo], $otp)],
        ]];
    }

    public function GetAddressCIS($params)
    {
        $firstName = (string)$this->param($params, 'FirstName');
        $lastName = (string)$this->param($params, 'LastName');

        $rows = [[
            'EntityID' => 'MOCK-E0001',
            'EntityType' => 'P',
            'T_FirstName' => $firstName !== '' ? $firstName : 'ทดสอบ',
            'T_LastName' => $lastName !== '' ? $lastName : 'ระบบ',
            'FullAddress' => '123/45 ถนนทดสอบ แขวงทดสอบ เขตทดสอบ กรุงเทพมหานคร 10000',
            'BRCode' => '000',
            'Version' => '1',
        ]];

        return ['GetAddressCISResult' => $this->listResult('AddressCIS', $rows)];
    }

    public function GetClaim($params)
    {
        $claimNo = (string)$this->param($params, 'ClaimNo');

        return ['GetClaimResult' => $this->jsonResult(self::NOT_FOUND, 'MOCK: GetClaim is not implemented (ClaimNo ' . $claimNo . ')', [])];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Defaults for every policy field TipFlashClaim reads; fixtures and
     * generated policies only need to set what differs. Dates default to a
     * window around *today* so any recent accident date falls inside it.
     */
    private function makePolicy(array $overrides): array
    {
        $today = new DateTimeImmutable('today');
        $defaults = [
            'PolicyNo' => 'MOCK-PA-000001',
            'InsuranceClass' => 'PA',
            'SubClass' => 'PA',
            'SubclassName' => 'ประกันภัยอุบัติเหตุส่วนบุคคล (MOCK)',
            'TitleName' => 'นาย',
            'Firstname' => 'ทดสอบ',
            'LastName' => 'ระบบ',
            'IDCardNo' => '1100100100001',
            'PersonNo' => 'P000001',
            'TelNo' => '0800000000',
            'EffectiveDate' => $today->modify('-1 year')->format('Y-m-d\TH:i:s'),
            'ExPiryDate' => $today->modify('+1 year')->format('Y-m-d\TH:i:s'),
            // Empty = premium paid (app: debt = 0). Set a date to simulate debt.
            'RPRefDate' => '',
            'Benefitcialy' => '',
            'AgentCode' => 'MOCK001',
            'AgentName' => 'ตัวแทนทดสอบ',
            'CustomerGroupID' => '',
            'CustomerGroupName' => '',
            'DepartmentName' => '',
            'PolicyHolderName' => 'นายทดสอบ ระบบ',
            'E_PolicyHolderName' => 'Mr. Test System',
            'ProjectCode' => '',
            'ProjectName' => '',
            'LocationAddress' => '123/45 ถนนทดสอบ แขวงทดสอบ เขตทดสอบ กรุงเทพมหานคร 10000',
            'Address' => '123/45 ถนนทดสอบ แขวงทดสอบ เขตทดสอบ กรุงเทพมหานคร 10000',
            'AddressNo' => '',
            'CoverageList' => [
                [
                    'AgreementNo' => '1',
                    'AgreementBrcode' => '000',
                    'T_Coverage' => 'ค่ารักษาพยาบาลจากอุบัติเหตุ (MOCK)',
                    'E_Coverage' => 'Medical expenses (MOCK)',
                    'Suminsured' => '50,000.00',
                ],
                [
                    'AgreementNo' => '2',
                    'AgreementBrcode' => '000',
                    'T_Coverage' => 'เสียชีวิต สูญเสียอวัยวะ สายตา หรือทุพพลภาพถาวรสิ้นเชิง (MOCK)',
                    'E_Coverage' => 'Death / dismemberment / total disability (MOCK)',
                    'Suminsured' => '500,000.00',
                ],
            ],
        ];

        if (empty($overrides['PolicyHolderName']) && (isset($overrides['Firstname']) || isset($overrides['LastName']))) {
            $overrides['PolicyHolderName'] = ($overrides['TitleName'] ?? $defaults['TitleName'])
                . ($overrides['Firstname'] ?? $defaults['Firstname']) . ' '
                . ($overrides['LastName'] ?? $defaults['LastName']);
        }

        return array_merge($defaults, $overrides);
    }

    /** Envelope for the operations whose *Result is a JSON string. */
    private function jsonResult(string $code, string $description, array $data): string
    {
        return json_encode([
            'ResultCode' => $code,
            'ResultDescription' => $description,
            'Data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Envelope for the structured list operations: {ResultCode, ResultDescription, data: {<item>: [...]}}. */
    private function listResult(string $itemName, array $rows): array
    {
        if (empty($rows)) {
            return ['ResultCode' => self::NOT_FOUND, 'ResultDescription' => 'MOCK: Data not found', 'data' => null];
        }

        return [
            'ResultCode' => self::OK,
            'ResultDescription' => 'MOCK: Success',
            'data' => [$itemName => array_values($rows)],
        ];
    }

    /** Read a dotted path (e.g. "input.ProvinceId") from the decoded request. */
    private function param($params, string $path)
    {
        $value = $params;
        foreach (explode('.', $path) as $key) {
            if (is_object($value) && isset($value->{$key})) {
                $value = $value->{$key};
            } elseif (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return is_scalar($value) ? trim((string)$value) : null;
    }

    private function same($a, $b): bool
    {
        return mb_strtolower(trim((string)$a)) === mb_strtolower(trim((string)$b));
    }

    private function load(string $file): array
    {
        if (!isset($this->cache[$file])) {
            $path = $this->dataDir . DIRECTORY_SEPARATOR . $file;
            $json = is_file($path) ? file_get_contents($path) : '[]';
            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new SoapFault('Server', 'MOCK: invalid JSON in data/' . $file . ': ' . json_last_error_msg());
            }
            $this->cache[$file] = $data;
        }

        return $this->cache[$file];
    }
}
