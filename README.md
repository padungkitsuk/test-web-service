# TIP MOCK SOAP server (Service1 จำลอง)

Created: 2026-10-12

SOAP server จำลองของ `TipMobileWS/Service1.svc` (non-motor) ของทิพยประกันภัย
ใช้ทดสอบ TipFlashClaim บนเครื่อง local ในกรณีที่ต่อ `apidev.tipinsure.com` ไม่ได้ (โดน IP whitelist)

> ⚠️ ใช้ทดสอบบนเครื่องตัวเองเท่านั้น **ห้ามนำไปตั้งค่าบน UAT/production**
> ข้อมูลทุกตัวเป็นข้อมูลปลอม (มีคำว่า MOCK กำกับ)
> โครงสร้าง response อนุมานจากโค้ดฝั่ง TipFlashClaim ที่อ่านค่า เพราะไม่มี WSDL ตัวจริง
> ผ่าน mock ได้ไม่ได้แปลว่าผ่านกับ service จริง ก่อนขึ้นระบบต้องทดสอบกับ apidev จริงอีกครั้ง

## วิธีใช้

1. ดับเบิลคลิก `start-mock.bat` แล้วเปิดหน้าต่างทิ้งไว้ (ปิดหน้าต่างคือหยุด server)
   - ตรวจว่าทำงาน: เปิด http://127.0.0.1:8888/ ต้องเห็นรายชื่อ operation
2. ใน TipFlashClaim ไฟล์ `config/.env` ตั้งค่าไว้แล้ว:
   ```
   DHIPAYA_NONMOTOR_REQUEST_URL="http://127.0.0.1:8888/TipMobileWS/Service1.svc?wsdl"
   ```
3. **Restart Apache ใน Laragon** (Stop → Start)
   เพราะ `.env` ถูกโหลดเข้า environment ของ process Apache ไปแล้ว (`putenv`) แก้ไฟล์อย่างเดียวจะไม่มีผล
4. เปิดหน้า เช่น `/admin/health/claim/retrieve/8173`

กลับไปใช้ apidev จริง: ใน `config/.env` สลับบรรทัด comment (`#`) ของ `DHIPAYA_NONMOTOR_REQUEST_URL` แล้ว restart Apache

## ทดสอบ mock

```
D:\laragon\bin\php\php-8.0.9-Win32-vs16-x64\php.exe tests\smoke-test.php
```
เรียกทุก operation ด้วย option ของ `SoapClient` แบบเดียวกับแอป และอ่านค่าด้วย path เดียวกับแอป ต้องได้ `ALL PASSED`

## Log (เพิ่ม 2026-10-12)

ดู log 2 ไฟล์คู่กัน เวลาตรงกันทั้งคู่ (Asia/Bangkok):

| ไฟล์ | ฝั่ง | มีอะไร |
|---|---|---|
| `D:\Project\TIP\TEST\logs\mock-YYYYMMDD.log` | web service (mock) | 1) params ที่ได้รับ (decode แล้ว) 2) ข้อมูลที่ mock ตอบกลับ (+ JSON string ที่ decode แล้ว) 3) XML request ดิบ 4) XML response ดิบ |
| `TipFlashClaim\logs\soap-YYYYMMDD.log` | TipFlashClaim | หน้า/คำสั่งที่เรียก + โค้ดที่เรียก (Called by), 1) params ที่ส่ง 2) XML request 3) XML response 4) **ผลลัพธ์ที่ TipFlashClaim ได้ไปใช้ต่อ** 5) JSON string ที่แอป `json_decode()` |

- log ฝั่ง TipFlashClaim เปิดด้วย `DHIPAYA_SOAP_LOG=true` ใน `config/.env` แก้แล้วต้อง restart Apache
  **ห้ามเปิดบน production** เพราะใน log มีข้อมูลส่วนบุคคล (ชื่อ เลขบัตรประชาชน ที่อยู่)
- ถ้าเห็น `CACHE HIT` ใน `soap-*.log` แปลว่าแอปใช้ผลที่ cache ไว้ (cache `day`) **ไม่ได้ส่ง request มาที่ mock**
  จึงไม่มีรายการใน `mock-*.log` ล้าง cache ด้วย `bin\cake cache clear day` แล้วจะเห็น request จริงอีกครั้ง
- หน้าต่าง `start-mock.bat` แสดงสรุปทีละบรรทัด เช่น `SOAP GetBankList OK (3 ms)`

## Operation ที่รองรับ

| Operation | ใช้ที่ | ข้อมูลมาจาก |
|---|---|---|
| GetPolicyNonMotor | ค้นหากรมธรรม์ (admin, REST, KTB) | `data/policies.json` ถ้าไม่เจอจะสร้างกรมธรรม์ที่ตรงกับคำค้นให้อัตโนมัติ |
| GetBankList | dropdown ธนาคาร | `data/banks.json` |
| GetBankBranchList | dropdown สาขา | `data/bank_branches.json` (`default` ใช้กับทุกธนาคาร) |
| GetProvinceData / GetAmphurData / GetDistrictData | ที่อยู่ | `data/provinces.json`, `amphurs.json`, `districts.json` (รหัสที่ไม่มีจะสร้าง "อำเภอ/ตำบลทดสอบ" ให้) |
| GetClaimNonMotor | cron `ClaimStatusQueue` | `data/claims.json` → `status` |
| GetClaimOTPDetail | cron `ClaimOTPQueue` | `data/claims.json` → `otp` |
| GetAddressCIS | `/soap/...getAddressCIS` | สร้างให้จากชื่อที่ค้น |
| GetZipCode, GetClaim | ไม่มีโค้ดในแอปเรียกใช้ | คืนค่าพื้นฐาน (รูปแบบเดาไว้) |

### เลขกรมธรรม์พิเศษสำหรับทดสอบกรณี error
| policyNo ขึ้นต้นด้วย | ผลลัพธ์ |
|---|---|
| `NOTFOUND` | ResultCode `01` ไม่มี Data (ไม่พบข้อมูลกรมธรรม์) |
| `TOOMANY` | ResultCode `03` (ให้เปลี่ยนไปค้นด้วยเลขบัตร/ชื่อ) |
| `FAULT` | SOAP Fault (ทดสอบตอน service ล่ม) |

### เพิ่ม/แก้ข้อมูลกรมธรรม์
เพิ่ม object ใน `data/policies.json` ใส่เฉพาะฟิลด์ที่ต่างจากค่าเริ่มต้น (ค่าเริ่มต้นอยู่ใน `makePolicy()` ใน `src/MockService1.php`)
- ค่าเริ่มต้น: SubClass `PA` (เคลมสุขภาพ), วันคุ้มครองตั้งแต่ 1 ปีก่อนถึง 1 ปีหลังวันนี้, ไม่ค้างเบี้ย (`RPRefDate` ว่าง)
- เคลมทรัพย์สิน (property) ใช้ SubClass `FR`, `HI` หรือ `SME` และ InsuranceClass ต้องไม่ใช่ `MO`
- จำลองค้างเบี้ย: ใส่วันที่ใน `RPRefDate`

> แอปเก็บผลค้นกรมธรรม์และรายชื่อธนาคารไว้ใน cache `day` ถ้าแก้ไฟล์ JSON แล้วหน้าเว็บยังไม่เปลี่ยน
> ให้ล้าง cache ฝั่งแอป: `bin\cake cache clear day` (หรือลบไฟล์ใน `tmp\cache`)

## ยังไม่ได้ทำ mock
- `DHIPAYA_MOTOR_REQUEST_URL` (DIYClaimWS/InquiryPolicyDetails — กรมธรรม์รถ)
- `DHIPAYA_NONMOTOR_SUBMIT_URL` (NonMotorClaimMobileWSTest/Service.asmx — ส่งเคลม)
- `DHIPAYA_KTB_URL` (TFC4KTBNext)

ถ้าได้ WSDL จริงมา (`...Service1.svc?singleWsdl`) ให้นำมาแทน `wsdl/TipMobileWS-Service1.wsdl` แล้วรัน smoke test ใหม่

## โครงสร้างไฟล์
```
start-mock.bat               เริ่ม server (php -S 127.0.0.1:8888)
router.php                   รับ request: GET ?wsdl / POST SOAP / หน้า status
src/MockService1.php         logic ของแต่ละ operation
wsdl/TipMobileWS-Service1.wsdl
data/*.json                  ข้อมูลทดสอบ (แก้ได้เลย ไม่ต้อง restart)
tests/smoke-test.php         ทดสอบทุก operation
logs/                        log ของ request/response
```
"# test-web-service" 
