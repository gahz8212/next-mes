<?php
// backend/core/PartDecoder.php
// Electronic Component Spec & Part Number Decoder, Multi-Vendor MPN Generator, and Datasheet/Supply Link Resolver

class PartDecoder {
    /**
     * 메인 디코드 메소드: 비정형 문자열 또는 제조사 파트번호를 분석하여 표준 정보, 파라미터, 벤더별 정식 MPN 및 링크 반환
     * @param string $rawSpec 부품 파트번호 또는 규격명
     * @param string $location 실장 위치 (Ref Des, 예: U1, C12, R4, L2 등)
     */
    public static function decode(string $rawSpec, string $location = ''): array {
        $raw = trim($rawSpec);
        if (empty($raw)) {
            return [
                'success' => false,
                'message' => '분석할 규격 문자열이 없습니다.'
            ];
        }

        $upper = strtoupper(preg_replace('/\s+/', ' ', $raw));
        $locUpper = strtoupper(trim($location));

        // Ref Des (실장 위치 접두사) 기반 엄격한 힌트 분석
        $isIcLocation = preg_match('/\b(U|IC)\d+/i', $locUpper) || preg_match('/^(U|IC)\b/i', $locUpper);
        $isResLocation = preg_match('/\bR\d+/i', $locUpper) || preg_match('/^R\b/i', $locUpper);
        $isCapLocation = preg_match('/\bC\d+/i', $locUpper) || preg_match('/^C\b/i', $locUpper);
        $isIndLocation = preg_match('/\bL\d+/i', $locUpper) || preg_match('/^L\b/i', $locUpper);
        $isDiodeLocation = preg_match('/\bD\d+/i', $locUpper) || preg_match('/^D\b/i', $locUpper);

        // 1. 공통 IC / 센서 / 반도체 파트번호 우선 검사
        $genericResult = self::decodeGenericIc($upper, $raw);
        if ($genericResult !== null) {
            return $genericResult;
        }

        // 2. 위치가 'U' 또는 'IC'인 경우 ➔ 100% 반도체 IC/센서로 격리
        if ($isIcLocation) {
            return self::buildIcResult($raw, $location);
        }

        // 3. 위치가 'L' (인덕터 / 코일 / 페라이트 비드)인 경우 ➔ 100% 인덕터로 격리 (커패시터/저항으로 오인 절대 방지!)
        if ($isIndLocation) {
            $indResult = self::decodeInductor($upper, $raw, $location);
            if ($indResult !== null) return $indResult;
            return self::buildInductorResult($raw, $location);
        }

        // 4. 위치가 'C' (커패시터 / 콘덴서)인 경우 ➔ 100% 커패시터로 격리
        if ($isCapLocation) {
            $mlccResult = self::decodeMlcc($upper, $raw, $location);
            if ($mlccResult !== null) return $mlccResult;
            return self::buildCapacitorResult($raw, $location);
        }

        // 5. 위치가 'R' (저항)인 경우 ➔ 100% 저항으로 격리
        if ($isResLocation) {
            $resResult = self::decodeResistor($upper, $raw, $location);
            if ($resResult !== null) return $resResult;
            return self::buildResistorResult($raw, $location);
        }

        // 6. 위치가 'D' (다이오드)인 경우
        if ($isDiodeLocation) {
            return self::buildDiodeResult($raw, $location);
        }

        // 7. 위치 정보가 없는 경우: 명시적 키워드/패턴으로 판별
        if (preg_match('/\b(IND|INDUCTOR|CHOKE|BEAD|LQG|CIH|MLG)\b/i', $upper) || preg_match('/\b\d+(?:\.\d+)?\s*(NH|UH|MH)\b/i', $upper)) {
            $indResult = self::decodeInductor($upper, $raw, $location);
            if ($indResult !== null) return $indResult;
        }
        if (preg_match('/\b(MLCC|CAP|CAPACITOR)\b/i', $upper) || preg_match('/^(CL\d|GRM|C\d{4}|CC0|0402B|0603B)/i', $upper) || preg_match('/\b\d+(?:\.\d+)?\s*(UF|NF|PF)\b/i', $upper)) {
            $mlccResult = self::decodeMlcc($upper, $raw, $location);
            if ($mlccResult !== null) return $mlccResult;
        }
        if (preg_match('/\b(RES|RESISTOR|CHIP RES)\b/i', $upper) || preg_match('/^(RC\d|RK73|ERJ|CRCW)/i', $upper) || preg_match('/\b\d+(?:\.\d+)?\s*[KMR]\b/i', $upper)) {
            $resResult = self::decodeResistor($upper, $raw, $location);
            if ($resResult !== null) return $resResult;
        }

        return self::buildFallbackResult($raw, $location);
    }

    /**
     * 1. MLCC 세라믹 커패시터 파서
     */
    private static function decodeMlcc(string $upper, string $raw): ?array {
        // 패키지 사이즈 추출 (1005, 0402, 1608, 0603, 2012, 0805, 3216, 1206)
        $pkgMetric = '1005';
        $pkgInch = '0402';

        if (preg_match('/\b(1005|0402)\b/', $upper) || preg_match('/CL05|GRM15|C1005|CC0402|0402B/', $upper)) {
            $pkgMetric = '1005'; $pkgInch = '0402';
        } else if (preg_match('/\b(1608|0603)\b/', $upper) || preg_match('/CL10|GRM18|C1608|CC0603|0603B/', $upper)) {
            $pkgMetric = '1608'; $pkgInch = '0603';
        } else if (preg_match('/\b(2012|0805)\b/', $upper) || preg_match('/CL21|GRM21|C2012|CC0805|0805B/', $upper)) {
            $pkgMetric = '2012'; $pkgInch = '0805';
        } else if (preg_match('/\b(3216|1206)\b/', $upper) || preg_match('/CL31|GRM31|C3216|CC1206|1206B/', $upper)) {
            $pkgMetric = '3216'; $pkgInch = '1206';
        }

        // 용량 추출 (104, 105, 225, 106, 475, 100nF, 0.1uF, 1uF, 10uF, 22pF 등)
        $capCode = null;
        $capHuman = null;

        if (preg_match('/(?:CL\d{2}[A-Z]|GRM\d{2,3}[A-Z\d]{2}|CC\d{4}[A-Z]{2}|C\d{4}[A-Z\d]{2,3})(\d{3})/i', $upper, $m)) {
            $capCode = $m[1];
            $capHuman = self::capCodeToHuman($capCode);
        } else if (preg_match('/\b(10[1-7]|22[1-6]|47[1-6]|33[1-6]|15[1-6]|68[1-6])(?:\s*K[OA]|\b)/i', $upper, $m)) {
            $capCode = $m[1];
            $capHuman = self::capCodeToHuman($capCode);
        } else if (preg_match('/(0\.1|0\.01|0\.001|1|2\.2|4\.7|10|22|47|100)\s*(UF|U|NF|N|PF|P)/i', $upper, $m)) {
            $val = (float)$m[1];
            $unit = strtoupper($m[2]);
            $capCode = self::humanCapToCode($val, $unit);
            $capHuman = self::capCodeToHuman($capCode);
        }

        if (!$capCode) {
            return null;
        }

        // 오차율 (K=±10%, J=±5%, M=±20%, Z=+80/-20%)
        $tolerance = 'K';
        if (preg_match('/\b([JKMZ])\b/i', $upper, $m) || preg_match('/[0-9]{3}([JKMZ])/i', $upper, $m)) {
            $tolerance = strtoupper($m[1]);
        } else if (preg_match('/(5%|10%|20%)/', $upper, $m)) {
            $tolerance = ($m[1] === '5%') ? 'J' : (($m[1] === '20%') ? 'M' : 'K');
        }

        // 정격전압 (6.3V, 10V, 16V, 25V, 50V)
        $voltage = '16V';
        $vCodeSamsung = 'O'; // 16V
        $vCodeMurata = '1C'; // 16V
        $vCodeYageo = '7';   // 16V
        $vCodeTdk = '1C';    // 16V

        if (preg_match('/(6\.3|10|16|25|50|100)\s*V/i', $upper, $m) || preg_match('/[0-9]{3}[JKM]([OABCDEFGHQ])/i', $upper, $m)) {
            $vStr = $m[1];
            if (strpos($vStr, '6.3') !== false || $vStr === 'Q') {
                $voltage = '6.3V'; $vCodeSamsung = 'Q'; $vCodeMurata = '0J'; $vCodeYageo = '5'; $vCodeTdk = '0J';
            } else if (strpos($vStr, '10') !== false || $vStr === 'A') {
                $voltage = '10V'; $vCodeSamsung = 'A'; $vCodeMurata = '1A'; $vCodeYageo = '6'; $vCodeTdk = '1A';
            } else if (strpos($vStr, '16') !== false || $vStr === 'O' || $vStr === 'C') {
                $voltage = '16V'; $vCodeSamsung = 'O'; $vCodeMurata = '1C'; $vCodeYageo = '7'; $vCodeTdk = '1C';
            } else if (strpos($vStr, '25') !== false || $vStr === 'B' || $vStr === 'E') {
                $voltage = '25V'; $vCodeSamsung = 'B'; $vCodeMurata = '1E'; $vCodeYageo = '8'; $vCodeTdk = '1E';
            } else if (strpos($vStr, '50') !== false || $vStr === 'C' || $vStr === 'H') {
                $voltage = '50V'; $vCodeSamsung = 'C'; $vCodeMurata = '1H'; $vCodeYageo = '9'; $vCodeTdk = '1H';
            }
        }

        // 유전체 (X7R, X5R, C0G, NPO)
        $dielectric = 'X7R';
        $dCodeSamsung = 'B'; // X7R
        $dCodeMurata = 'R7'; // X7R
        if (preg_match('/(X5R|X7R|C0G|NPO|Y5V)/i', $upper, $m)) {
            $dielectric = strtoupper($m[1]);
            if ($dielectric === 'X5R') {
                $dCodeSamsung = 'A';
                $dCodeMurata = 'R6';
            } else if ($dielectric === 'C0G' || $dielectric === 'NPO') {
                $dCodeSamsung = 'C';
                $dCodeMurata = '5C';
            }
        }

        // MES 공통 자재 코드 생성
        $standardCode = "CAP-MLCC-{$pkgMetric}-{$capCode}{$tolerance}-{$voltage}";
        $standardName = "MLCC {$pkgMetric}({$pkgInch}) {$capHuman} {$tolerance}(±" . ($tolerance === 'J' ? '5%' : ($tolerance === 'M' ? '20%' : '10%')) . ") {$voltage} {$dielectric}";

        // 제조사별 정식 MPN 생성
        $samsungPkg = ($pkgMetric === '1005') ? '05' : (($pkgMetric === '1608') ? '10' : (($pkgMetric === '2012') ? '21' : '31'));
        $samsungMpn = "CL{$samsungPkg}{$dCodeSamsung}{$capCode}{$tolerance}{$vCodeSamsung}5NNNC";

        $murataPkg = ($pkgMetric === '1005') ? '155' : (($pkgMetric === '1608') ? '188' : (($pkgMetric === '2012') ? '21B' : '319'));
        $murataMpn = "GRM{$murataPkg}{$dCodeMurata}{$vCodeMurata}{$capCode}{$tolerance}A88D";

        $yageoMpn = "CC{$pkgInch}KR{$dielectric}{$vCodeYageo}BB{$capCode}";
        $tdkMpn = "C{$pkgMetric}{$dielectric}{$vCodeTdk}{$capCode}{$tolerance}050BC";
        $walsinMpn = "{$pkgInch}B{$capCode}{$tolerance}" . intval($voltage) . "0CT";

        $alternates = [
            [
                'vendor_name' => '삼성전기 (SEMCO)',
                'mpn'         => $samsungMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Samsung MLCC {$capHuman} {$voltage} {$tolerance} {$dielectric} (Paper Tape Reel)",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($samsungMpn, $standardName)
            ],
            [
                'vendor_name' => '무라타 (Murata)',
                'mpn'         => $murataMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Murata GRM Series {$capHuman} {$voltage} {$tolerance} {$dielectric}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($murataMpn, $standardName)
            ],
            [
                'vendor_name' => '야게오 (Yageo)',
                'mpn'         => $yageoMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Yageo CC Series Surface Mount MLCC {$capHuman} {$voltage}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($yageoMpn, $standardName)
            ],
            [
                'vendor_name' => 'TDK',
                'mpn'         => $tdkMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "TDK Commercial Grade MLCC {$capHuman} {$voltage}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($tdkMpn, $standardName)
            ],
            [
                'vendor_name' => 'Walsin (월신)',
                'mpn'         => $walsinMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Walsin SMD Ceramic Capacitor {$capHuman} {$voltage}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($walsinMpn, $standardName)
            ]
        ];

        $cleanUpper = preg_replace('/[\s\-_]/', '', $upper);
        $filteredAlternates = array_values(array_filter($alternates, function($a) use ($cleanUpper) {
            $altClean = preg_replace('/[\s\-_]/', '', strtoupper($a['mpn'] ?? ''));
            return $altClean !== $cleanUpper;
        }));

        return [
            'success'       => true,
            'category'      => 'CAPACITOR',
            'type_name'     => 'MLCC (적층 세라믹 커패시터)',
            'standard_code' => $standardCode,
            'standard_name' => $standardName,
            'parameters'    => [
                'package_metric' => $pkgMetric,
                'package_inch'   => $pkgInch,
                'capacitance'    => $capHuman,
                'code_3digit'    => $capCode,
                'tolerance'      => $tolerance,
                'voltage'        => $voltage,
                'dielectric'     => $dielectric
            ],
            'primary_mpn'   => $samsungMpn,
            'alternates'    => $filteredAlternates,
            'search_links'  => self::generateLinks($standardCode, $standardName)
        ];
    }

    /**
     * 2. 칩저항 (Chip Resistor) 파서
     */
    private static function decodeResistor(string $upper, string $raw): ?array {
        $pkgMetric = '1005';
        $pkgInch = '0402';

        if (preg_match('/\b(1005|0402)\b/', $upper) || preg_match('/RC0402|RC1005|RK73.1E|ERJ-2R|CRCW0402/', $upper)) {
            $pkgMetric = '1005'; $pkgInch = '0402';
        } else if (preg_match('/\b(1608|0603)\b/', $upper) || preg_match('/RC0603|RC1608|RK73.1J|ERJ-3R|CRCW0603/', $upper)) {
            $pkgMetric = '1608'; $pkgInch = '0603';
        } else if (preg_match('/\b(2012|0805)\b/', $upper) || preg_match('/RC0805|RC2012|RK73.2A|ERJ-6R|CRCW0805/', $upper)) {
            $pkgMetric = '2012'; $pkgInch = '0805';
        } else if (preg_match('/\b(3216|1206)\b/', $upper) || preg_match('/RC1206|RC3216|RK73.2B|ERJ-8R|CRCW1206/', $upper)) {
            $pkgMetric = '3216'; $pkgInch = '1206';
        }

        // 저항값 및 파라미터 변수 초기화
        $resCode3 = null;
        $resCode4 = null;
        $resHuman = null;
        $resYageoVal = null;
        $tolerance = null;

        // 1. 삼성전기 (SEMCO) 저항 파트넘버 패턴 (예: RC1005F103CS, RC1608J103CS, RC0603F1002CS)
        if (preg_match('/^RC(0402|0603|1005|1608|2012|3216)([FJDKG])([0-9R]{3,4})([A-Z]{2})?$/i', $upper, $m)) {
            $metricCode = $m[1];
            $tolerance = strtoupper($m[2]);
            $rawDigits = $m[3];
            
            if ($metricCode === '0402') { $pkgMetric = '0402'; $pkgInch = '01005'; }
            else if ($metricCode === '0603') { $pkgMetric = '0603'; $pkgInch = '0201'; }
            else if ($metricCode === '1005') { $pkgMetric = '1005'; $pkgInch = '0402'; }
            else if ($metricCode === '1608') { $pkgMetric = '1608'; $pkgInch = '0603'; }
            else if ($metricCode === '2012') { $pkgMetric = '2012'; $pkgInch = '0805'; }
            else if ($metricCode === '3216') { $pkgMetric = '3216'; $pkgInch = '1206'; }

            if (strlen($rawDigits) === 3) {
                $resCode3 = $rawDigits;
                $ohms = self::resCode3ToOhms($rawDigits);
                $resCode4 = self::calcResCode4($ohms, 'R');
                $resHuman = self::resCode3ToHuman($rawDigits);
                $resYageoVal = ($ohms >= 1000000) ? ($ohms/1000000).'M' : (($ohms >= 1000) ? ($ohms/1000).'K' : $ohms.'R');
            } else if (strlen($rawDigits) === 4) {
                $resCode4 = $rawDigits;
                $ohms = self::resCode4ToOhms($rawDigits);
                $resCode3 = self::calcResCode3($ohms, 'R');
                $resHuman = self::resCode4ToHuman($rawDigits);
                $resYageoVal = ($ohms >= 1000000) ? ($ohms/1000000).'M' : (($ohms >= 1000) ? ($ohms/1000).'K' : $ohms.'R');
            }
        } else if (preg_match('/(?<![A-Z0-9])(\d+(?:\.\d+)?)\s*([KMR])(?:\s*([JF15%]))?(?![A-Z0-9])/i', $upper, $m)) {
            $num = (float)$m[1];
            $mult = strtoupper($m[2]);
            $resHuman = $num . ($mult === 'R' ? 'Ω' : $mult . 'Ω');
            $resYageoVal = $num . ($mult === 'R' ? 'R' : $mult);
            $resCode3 = self::calcResCode3($num, $mult);
            $resCode4 = self::calcResCode4($num, $mult);
        } else if (preg_match('/(?<![A-Z0-9])(\d+)K(\d+)(?![A-Z0-9])/i', $upper, $m)) { // 4K7
            $resHuman = $m[1] . '.' . $m[2] . 'kΩ';
            $resYageoVal = $m[1] . 'K' . $m[2];
            $num = (float)($m[1] . '.' . $m[2]);
            $resCode3 = self::calcResCode3($num, 'K');
            $resCode4 = self::calcResCode4($num, 'K');
        } else if (preg_match('/(?:RC\d{4}[A-Z]{1,2}-\d{2}|RK73[A-Z\d]{3}TTD|ERJ-[A-Z\d]{2,3}|CRCW\d{4}|WR\d{2}[A-Z])([0-9]{3,4})/i', $upper, $m) || (preg_match('/\bR\d+/i', $raw) || preg_match('/\bR\d+/i', $upper) || preg_match('/^(000|[0-9]{3,4})$/', $upper, $m))) {
            $rawDigits = !empty($m[1]) ? $m[1] : (preg_match('/^([0-9]{3,4})$/', $upper, $dm) ? $dm[1] : null);
            if ($rawDigits) {
                if (strlen($rawDigits) === 3) {
                    $resCode3 = $rawDigits;
                    $ohms = self::resCode3ToOhms($rawDigits);
                    $resCode4 = self::calcResCode4($ohms, 'R');
                    $resHuman = self::resCode3ToHuman($rawDigits);
                    $resYageoVal = ($ohms >= 1000000) ? ($ohms/1000000).'M' : (($ohms >= 1000) ? ($ohms/1000).'K' : $ohms.'R');
                } else if (strlen($rawDigits) === 4) {
                    $resCode4 = $rawDigits;
                    $ohms = self::resCode4ToOhms($rawDigits);
                    $resCode3 = self::calcResCode3($ohms, 'R');
                    $resHuman = self::resCode4ToHuman($rawDigits);
                    $resYageoVal = ($ohms >= 1000000) ? ($ohms/1000000).'M' : (($ohms >= 1000) ? ($ohms/1000).'K' : $ohms.'R');
                }
            }
        }

        if (!$resHuman) {
            return null;
        }

        // 오차율
        if (empty($tolerance)) {
            $tolerance = 'F'; // 기본 1%
            if (preg_match('/\b(J|5%)\b/i', $upper)) {
                $tolerance = 'J'; // 5%
            } else if (preg_match('/\b(D|0\.5%)\b/i', $upper)) {
                $tolerance = 'D'; // 0.5%
            } else if (preg_match('/\b(F|1%)\b/i', $upper)) {
                $tolerance = 'F'; // 1%
            }
        }

        $standardCode = "RES-{$pkgMetric}-" . ($tolerance === 'F' ? ($resCode4 ?: $resCode3) : ($resCode3 ?: $resCode4)) . "{$tolerance}";
        $standardName = "칩저항 {$pkgMetric}({$pkgInch}) {$resHuman} {$tolerance}(±" . ($tolerance === 'J' ? '5%' : ($tolerance === 'D' ? '0.5%' : '1%')) . ")";

        // 제조사별 정식 MPN 생성
        $yageoTol = ($tolerance === 'F') ? 'F' : 'J';
        $yageoMpn = "RC{$pkgInch}{$yageoTol}-07{$resYageoVal}L";

        $koaPkg = ($pkgInch === '0402') ? '1E' : (($pkgInch === '0603') ? '1J' : (($pkgInch === '0805') ? '2A' : '2B'));
        $koaType = ($tolerance === 'F') ? 'H' : 'B';
        $koaCode = ($tolerance === 'F') ? ($resCode4 ?: $resCode3) : ($resCode3 ?: $resCode4);
        $koaMpn = "RK73{$koaType}{$koaPkg}TTD{$koaCode}{$tolerance}";

        $panaPkg = ($pkgInch === '0402') ? '2R' : (($pkgInch === '0603') ? '3R' : (($pkgInch === '0805') ? '6R' : '8R'));
        $panaMpn = "ERJ-{$panaPkg}K" . ($resCode4 ?: $resCode3) . "X";

        $samsungPkg = 'RC' . $pkgMetric;
        $samsungMpn = "{$samsungPkg}{$tolerance}" . ($resCode3 ?: $resCode4) . "CS";

        $vishayMpn = "CRCW{$pkgInch}" . ($resCode4 ?: $resCode3) . ($tolerance === 'F' ? 'FKED' : 'JNEA');

        $alternates = [
            [
                'vendor_name' => '야게오 (Yageo)',
                'mpn'         => $yageoMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Yageo RC Series Thick Film Chip Resistor {$resHuman} {$tolerance}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($yageoMpn, $standardName)
            ],
            [
                'vendor_name' => 'KOA Speer',
                'mpn'         => $koaMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "KOA RK73 Series General Purpose SMD Resistor {$resHuman}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($koaMpn, $standardName)
            ],
            [
                'vendor_name' => '파나소닉 (Panasonic)',
                'mpn'         => $panaMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Panasonic Thick Film Chip Resistor {$resHuman} {$tolerance}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($panaMpn, $standardName)
            ],
            [
                'vendor_name' => '삼성전기 (SEMCO)',
                'mpn'         => $samsungMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Samsung Chip Resistor Thick Film {$resHuman}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($samsungMpn, $standardName)
            ],
            [
                'vendor_name' => '비샤이 (Vishay / Dale)',
                'mpn'         => $vishayMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Vishay CRCW Series Standard Thick Film Resistor {$resHuman}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($vishayMpn, $standardName)
            ]
        ];

        $cleanUpper = preg_replace('/[\s\-_]/', '', $upper);
        $filteredAlternates = array_values(array_filter($alternates, function($a) use ($cleanUpper) {
            $altClean = preg_replace('/[\s\-_]/', '', strtoupper($a['mpn'] ?? ''));
            return $altClean !== $cleanUpper;
        }));

        return [
            'success'       => true,
            'category'      => 'RESISTOR',
            'type_name'     => '칩저항 (Chip Resistor)',
            'standard_code' => $standardCode,
            'standard_name' => $standardName,
            'parameters'    => [
                'package_metric' => $pkgMetric,
                'package_inch'   => $pkgInch,
                'resistance'     => $resHuman,
                'tolerance'      => $tolerance,
                'code_3digit'    => $resCode3,
                'code_4digit'    => $resCode4
            ],
            'primary_mpn'   => $yageoMpn,
            'alternates'    => $filteredAlternates,
            'search_links'  => self::generateLinks($standardCode, $standardName)
        ];
    }

    /**
     * 3. 칩인덕터 / 페라이트 비드 (Chip Inductor & Ferrite Bead) 파서
     */
    private static function decodeInductor(string $upper, string $raw, string $location = ''): ?array {
        $pkgMetric = '1005';
        $pkgInch = '0402';
        $valHuman = null;
        $valCode = null; // e.g. "10N", "1N5", "2R2", "10U"
        $tolerance = 'J (±5%)';

        // 1. 삼성전기 (SEMCO) CIH 파트넘버 체계: CIH(03|05|10|21)(T|S|B)([0-9R]+[NUK])([A-Z]{3})
        if (preg_match('/^CIH(\d{2})[A-Z]([0-9R]+[NUK]?)([A-Z]{3})?/i', $upper, $sm)) {
            $semcoPkgCode = $sm[1];
            if ($semcoPkgCode === '03') { $pkgMetric = '0603'; $pkgInch = '0201'; }
            else if ($semcoPkgCode === '05') { $pkgMetric = '1005'; $pkgInch = '0402'; }
            else if ($semcoPkgCode === '10') { $pkgMetric = '1608'; $pkgInch = '0603'; }
            else if ($semcoPkgCode === '21') { $pkgMetric = '2012'; $pkgInch = '0805'; }

            $rawVal = $sm[2];
            // Format: 10N -> 10nH, 1N5 -> 1.5nH, R10 -> 100nH, 1R0 -> 1.0uH
            if (preg_match('/^(\d+)N(\d*)$/i', $rawVal, $vm)) {
                $valHuman = $vm[1] . (empty($vm[2]) ? '' : '.' . $vm[2]) . 'nH';
                $valCode = $vm[1] . (empty($vm[2]) ? 'N' : 'N' . $vm[2]);
            } else if (preg_match('/^(\d+)R(\d*)$/i', $rawVal, $vm)) {
                $valHuman = $vm[1] . (empty($vm[2]) ? '' : '.' . $vm[2]) . 'µH';
                $valCode = $vm[1] . (empty($vm[2]) ? 'U' : 'U' . $vm[2]);
            } else if (preg_match('/^R(\d+)$/i', $rawVal, $vm)) {
                $valHuman = '0.' . $vm[1] . 'µH';
                $valCode = 'R' . $vm[1];
            } else {
                $valHuman = $rawVal . 'nH';
                $valCode = $rawVal;
            }
        }
        // 2. 무라타 (Murata) LQG 파트넘버 체계: LQG15HN(10N|1N5|...) / LQG18HN...
        else if (preg_match('/^LQ[GWP](\d{2})[A-Z]{2}([0-9R]+[NUK]?)/i', $upper, $mm)) {
            $mPkg = $mm[1];
            if ($mPkg === '03') { $pkgMetric = '0603'; $pkgInch = '0201'; }
            else if ($mPkg === '15') { $pkgMetric = '1005'; $pkgInch = '0402'; }
            else if ($mPkg === '18') { $pkgMetric = '1608'; $pkgInch = '0603'; }
            else if ($mPkg === '21') { $pkgMetric = '2012'; $pkgInch = '0805'; }

            $rawVal = $mm[2];
            if (preg_match('/^(\d+)N(\d*)$/i', $rawVal, $vm)) {
                $valHuman = $vm[1] . (empty($vm[2]) ? '' : '.' . $vm[2]) . 'nH';
                $valCode = $rawVal;
            } else if (preg_match('/^(\d+)R(\d*)$/i', $rawVal, $vm)) {
                $valHuman = $vm[1] . (empty($vm[2]) ? '' : '.' . $vm[2]) . 'µH';
                $valCode = $rawVal;
            } else {
                $valHuman = $rawVal;
                $valCode = $rawVal;
            }
        }
        // 3. TDK MLG 파트넘버 체계: MLG1005S(10N|1N5...) / MLG1608S...
        else if (preg_match('/^MLG(\d{4})[A-Z]([0-9R]+[NUK]?)/i', $upper, $tm)) {
            $pkgMetric = $tm[1];
            $pkgInch = ($pkgMetric === '1005') ? '0402' : (($pkgMetric === '1608') ? '0603' : (($pkgMetric === '0603') ? '0201' : '0805'));
            $rawVal = $tm[2];
            if (preg_match('/^(\d+)N(\d*)$/i', $rawVal, $vm)) {
                $valHuman = $vm[1] . (empty($vm[2]) ? '' : '.' . $vm[2]) . 'nH';
                $valCode = $rawVal;
            } else {
                $valHuman = $rawVal;
                $valCode = $rawVal;
            }
        }
        // 4. 페라이트 비드 (Ferrite Bead - 예: BLM18, BLM15, 120R, 600R, BEAD)
        else if (preg_match('/(BLM\d{2}[A-Z\d]*|CIB\d{2}|MMZ\d{4}|CBG\d{4}|BEAD)/i', $upper) || (preg_match('/\bL\d+/i', $location) && preg_match('/(\d+)\s*(R|OHM|Ω)/i', $upper, $bm))) {
            if (preg_match('/\b(1608|0603|BLM18|CIB08|CIB10)\b/i', $upper)) {
                $pkgMetric = '1608'; $pkgInch = '0603';
            } else if (preg_match('/\b(2012|0805|BLM21|CIB21)\b/i', $upper)) {
                $pkgMetric = '2012'; $pkgInch = '0805';
            } else {
                $pkgMetric = '1005'; $pkgInch = '0402';
            }
            $imp = '120';
            if (preg_match('/(\d{2,4})\s*(?:R|OHM|Ω|@)/i', $upper, $m2)) {
                $imp = $m2[1];
            } else if (preg_match('/BLM\d{2}[A-Z]{2}(\d{2,4})/i', $upper, $m3)) {
                $imp = $m3[1];
            }
            $standardCode = "BEAD-{$pkgMetric}-{$imp}R";
            $standardName = "칩페라이트비드 {$pkgMetric}({$pkgInch}) {$imp}Ω @100MHz";
            $murataBead = "BLM" . ($pkgInch === '0402' ? '15' : ($pkgInch === '0603' ? '18' : '21')) . "AG{$imp}SN1D";
            $samsungBead = "CIB" . ($pkgMetric === '1005' ? '05' : ($pkgMetric === '1608' ? '10' : '21')) . "J{$imp}0NC";
            $tdkBead = "MMZ" . $pkgMetric . "S{$imp}CT000";
            return [
                'success'       => true,
                'category'      => 'INDUCTOR',
                'type_name'     => '칩비드 / 페라이트 인덕터',
                'standard_code' => $standardCode,
                'standard_name' => $standardName,
                'parameters'    => [
                    'package_metric' => $pkgMetric,
                    'package_inch'   => $pkgInch,
                    'impedance'      => "{$imp}Ω @100MHz"
                ],
                'primary_mpn'   => $murataBead,
                'alternates'    => [
                    [
                        'vendor_name' => '무라타 (Murata)',
                        'mpn'         => $murataBead,
                        'package'     => "{$pkgMetric} ({$pkgInch})",
                        'description' => "Murata Chip Ferrite Bead {$imp}Ω 100MHz",
                        'status'      => 'ACTIVE',
                        'links'       => self::generateLinks($murataBead, $standardName)
                    ],
                    [
                        'vendor_name' => '삼성전기 (SEMCO)',
                        'mpn'         => $samsungBead,
                        'package'     => "{$pkgMetric} ({$pkgInch})",
                        'description' => "Samsung Chip Ferrite Bead {$imp}Ω",
                        'status'      => 'ACTIVE',
                        'links'       => self::generateLinks($samsungBead, $standardName)
                    ],
                    [
                        'vendor_name' => 'TDK',
                        'mpn'         => $tdkBead,
                        'package'     => "{$pkgMetric} ({$pkgInch})",
                        'description' => "TDK MMZ Series Noise Suppression Filter Bead {$imp}Ω",
                        'status'      => 'ACTIVE',
                        'links'       => self::generateLinks($tdkBead, $standardName)
                    ]
                ],
                'search_links'  => self::generateLinks($standardCode, $standardName)
            ];
        }
        // 5. 일반 규격 텍스트 (예: "1005 10nH", "1608 10nH", "10uH", "2.2nH")
        else {
            if (preg_match('/\b(1005|0402)\b/', $upper)) { $pkgMetric = '1005'; $pkgInch = '0402'; }
            else if (preg_match('/\b(1608|0603)\b/', $upper)) { $pkgMetric = '1608'; $pkgInch = '0603'; }
            else if (preg_match('/\b(2012|0805)\b/', $upper)) { $pkgMetric = '2012'; $pkgInch = '0805'; }
            else if (preg_match('/\b(0603|0201)\b/', $upper)) { $pkgMetric = '0603'; $pkgInch = '0201'; }

            if (preg_match('/(?<![A-Z0-9])(\d+(?:\.\d+)?)\s*(NH|UH|MH)(?![A-Z0-9])/i', $upper, $m)) {
                $num = (float)$m[1];
                $unit = strtoupper($m[2]);
                $valHuman = $num . ($unit === 'NH' ? 'nH' : ($unit === 'UH' ? 'µH' : 'mH'));
                $valCode = $num . ($unit === 'NH' ? 'N' : ($unit === 'UH' ? 'U' : 'M'));
            }
        }

        if (!$valHuman) {
            return null;
        }

        $standardCode = "IND-{$pkgMetric}-{$valCode}";
        $standardName = "칩인덕터 {$pkgMetric}({$pkgInch}) {$valHuman} (±5%)";

        // 패키지별 제조사 정품 MPN 생성
        $valFormatted = str_replace('.', 'R', $valCode);
        if ($pkgMetric === '1608') {
            $murataMpn  = "LQG18HN{$valFormatted}J00D";
            $samsungMpn = "CIH10T{$valFormatted}JNC";
            $tdkMpn     = "MLG1608S{$valFormatted}JT000";
            $yudenMpn   = "HK1608{$valFormatted}J-T";
        } else if ($pkgMetric === '0603') {
            $murataMpn  = "LQP03TN{$valFormatted}J02D";
            $samsungMpn = "CIH03T{$valFormatted}JNC";
            $tdkMpn     = "MLG0603S{$valFormatted}JT000";
            $yudenMpn   = "HK0603{$valFormatted}J-T";
        } else if ($pkgMetric === '2012') {
            $murataMpn  = "LQH2MCN{$valFormatted}M02L";
            $samsungMpn = "CIH21T{$valFormatted}JNC";
            $tdkMpn     = "MLG2012S{$valFormatted}JT000";
            $yudenMpn   = "HK2012{$valFormatted}J-T";
        } else { // 1005 기본
            $murataMpn  = "LQG15HN{$valFormatted}S02D";
            $samsungMpn = "CIH05T{$valFormatted}JNC";
            $tdkMpn     = "MLG1005S{$valFormatted}T000";
            $yudenMpn   = "HK1005{$valFormatted}J-T";
        }

        $alternates = [
            [
                'vendor_name' => '삼성전기 (SEMCO)',
                'mpn'         => $samsungMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Samsung High Frequency Chip Inductor {$valHuman} ±5%",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($samsungMpn, $standardName)
            ],
            [
                'vendor_name' => '무라타 (Murata)',
                'mpn'         => $murataMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Murata High Frequency Chip Inductor {$valHuman} ±5%",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($murataMpn, $standardName)
            ],
            [
                'vendor_name' => 'TDK',
                'mpn'         => $tdkMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "TDK MLG Series High-Q Inductor {$valHuman}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($tdkMpn, $standardName)
            ],
            [
                'vendor_name' => '타이요 유덴 (Taiyo Yuden)',
                'mpn'         => $yudenMpn,
                'package'     => "{$pkgMetric} ({$pkgInch})",
                'description' => "Taiyo Yuden Multilayer Chip Inductor {$valHuman}",
                'status'      => 'ACTIVE',
                'links'       => self::generateLinks($yudenMpn, $standardName)
            ]
        ];

        $cleanUpper = preg_replace('/[\s\-_]/', '', $upper);
        $filteredAlternates = array_values(array_filter($alternates, function($a) use ($cleanUpper) {
            $altClean = preg_replace('/[\s\-_]/', '', strtoupper($a['mpn'] ?? ''));
            return $altClean !== $cleanUpper;
        }));

        return [
            'success'       => true,
            'category'      => 'INDUCTOR',
            'type_name'     => '칩인덕터 (Chip Inductor)',
            'standard_code' => $standardCode,
            'standard_name' => $standardName,
            'parameters'    => [
                'package_metric' => $pkgMetric,
                'package_inch'   => $pkgInch,
                'inductance'     => $valHuman,
                'tolerance'      => $tolerance
            ],
            'primary_mpn'   => $samsungMpn,
            'alternates'    => $filteredAlternates,
            'search_links'  => self::generateLinks($standardCode, $standardName)
        ];
    }

    /**
     * 4. 일반 IC / 센서 / 반도체 소자 디코딩
     */
    private static function decodeGenericIc(string $upper, string $raw): ?array {
        $icDb = [
            // 센서 소자 (Sensors & MEMS)
            'LIS3MDL'     => [
                'vendor' => 'STMicroelectronics',
                'name'   => 'Ultra-low-power 3-axis Digital Magnetic Sensor (LGA-12)',
                'pkg'    => 'LGA-12 (2.0x2.0x1.0mm)',
                'alts'   => [
                    ['vendor' => 'QST / Honeywell', 'mpn' => 'QMC5883L', 'desc' => '3-Axis Magnetic Sensor I2C (LGA-16)'],
                    ['vendor' => 'MEMSIC', 'mpn' => 'MMC5603NJ', 'desc' => 'Ultra-High Accuracy 3-Axis Magnetometer (WLCSP-4 / LGA)'],
                    ['vendor' => 'Asahi Kasei (AKM)', 'mpn' => 'AK09918C', 'desc' => '3-axis Electronic Compass IC (WLCSP-4)']
                ]
            ],
            'LIS3DH'      => [
                'vendor' => 'STMicroelectronics',
                'name'   => 'Ultra-low-power 3-axis MEMS Digital Accelerometer (LGA-16)',
                'pkg'    => 'LGA-16 (3.0x3.0x1.0mm)',
                'alts'   => [
                    ['vendor' => 'STMicroelectronics', 'mpn' => 'LIS3DHTR', 'desc' => 'Tape & Reel Packaging of LIS3DH (LGA-16)'],
                    ['vendor' => 'Analog Devices', 'mpn' => 'ADXL345BCCZ', 'desc' => '3-Axis Digital Accelerometer (LGA-14)'],
                    ['vendor' => 'mCube', 'mpn' => 'MC3419', 'desc' => 'Ultra-low power 3-axis accelerometer (LGA-16)']
                ]
            ],
            'LSM6DS3'     => [
                'vendor' => 'STMicroelectronics',
                'name'   => 'iNEMO 6-Axis IMU (Accelerometer + Gyroscope) (LGA-14)',
                'pkg'    => 'LGA-14 (2.5x3.0x0.83mm)',
                'alts'   => [
                    ['vendor' => 'STMicroelectronics', 'mpn' => 'LSM6DSOXTR', 'desc' => 'Next-Gen 6-Axis IMU (LGA-14)'],
                    ['vendor' => 'TDK InvenSense', 'mpn' => 'ICM-42688-P', 'desc' => 'High-Precision 6-Axis MotionTracking (LGA-14)'],
                    ['vendor' => 'Bosch Sensortec', 'mpn' => 'BMI270', 'desc' => 'Ultra-Low Power 6-Axis IMU (LGA-14)']
                ]
            ],
            'MPU-6050'    => [
                'vendor' => 'TDK InvenSense',
                'name'   => '6-Axis I2C MotionTracking IMU (QFN-24)',
                'pkg'    => 'QFN-24 (4.0x4.0x0.9mm)',
                'alts'   => [
                    ['vendor' => 'TDK InvenSense', 'mpn' => 'MPU-6500', 'desc' => 'Upgraded 6-Axis IMU (QFN-24)'],
                    ['vendor' => 'Bosch Sensortec', 'mpn' => 'BMI160', 'desc' => 'Low-Power 6-Axis IMU (LGA-14)']
                ]
            ],
            'BME280'      => [
                'vendor' => 'Bosch Sensortec',
                'name'   => 'Combined Humidity, Pressure & Temperature Sensor (LGA-8)',
                'pkg'    => 'LGA-8 (2.5x2.5x0.93mm)',
                'alts'   => [
                    ['vendor' => 'Bosch Sensortec', 'mpn' => 'BMP280', 'desc' => 'Pressure & Temperature Sensor (LGA-8)'],
                    ['vendor' => 'Sensirion', 'mpn' => 'SHT30-DIS-B', 'desc' => 'Digital Humidity & Temperature Sensor (DFN-8)']
                ]
            ],
            'BMP280'      => [
                'vendor' => 'Bosch Sensortec',
                'name'   => 'Digital Barometric Pressure & Temperature Sensor (LGA-8)',
                'pkg'    => 'LGA-8 (2.0x2.5x0.95mm)',
                'alts'   => [
                    ['vendor' => 'STMicroelectronics', 'mpn' => 'LPS22HBTR', 'desc' => 'Nano Pressure Sensor (HLGA-10)'],
                    ['vendor' => 'Goertek', 'mpn' => 'SPL06-001', 'desc' => 'Digital Barometric Air Pressure Sensor (LGA-8)']
                ]
            ],

            // 메모리 반도체 (Flash / EEPROM)
            'MT29F2G08'   => [
                'vendor' => 'Micron Technology',
                'name'   => '2Gb (256M x 8) SLC NAND Flash Memory (63-VFBGA)',
                'pkg'    => 'VFBGA-63 (9.0x11.0x1.0mm)',
                'alts'   => [
                    ['vendor' => 'Winbond Electronics', 'mpn' => 'W29N02GVBIAF', 'desc' => '2Gb 3.3V SLC NAND Flash (63-VFBGA 100% Pin-to-Pin)'],
                    ['vendor' => 'Macronix (MXIC)', 'mpn' => 'MX30LF2G18AC-XKI', 'desc' => '2Gb 3.3V Parallel NAND Flash (63-VFBGA Pin Compatible)'],
                    ['vendor' => 'Kioxia (Toshiba)', 'mpn' => 'TC58NVG1S3HTA00', 'desc' => '2Gb SLC NAND Flash (TSOP-48)']
                ]
            ],
            'W25Q128'     => [
                'vendor' => 'Winbond Electronics',
                'name'   => '128Mb (16MB) SPI Multi-I/O Serial Flash Memory (SOIC-8)',
                'pkg'    => 'SOIC-8 / SOP-8 (208mil)',
                'alts'   => [
                    ['vendor' => 'GigaDevice', 'mpn' => 'GD25Q128CSIG', 'desc' => '128Mb SPI NOR Flash (SOP-8 208mil Pin-to-Pin)'],
                    ['vendor' => 'Macronix (MXIC)', 'mpn' => 'MX25L12835FM2I-10G', 'desc' => '128Mb CMOS Serial Flash (SOP-8)'],
                    ['vendor' => 'Puya Semiconductor', 'mpn' => 'P25Q128H-SSH-IT', 'desc' => '128Mb Ultra-Low Power SPI Flash (SOP-8)']
                ]
            ],

            // 마이크로컨트롤러 (MCU & Wireless)
            'STM32F103C8' => [
                'vendor' => 'STMicroelectronics',
                'name'   => 'Mainstream ARM Cortex-M3 32-bit MCU 64KB Flash 72MHz (LQFP-48)',
                'pkg'    => 'LQFP-48 (7.0x7.0mm)',
                'alts'   => [
                    ['vendor' => 'GigaDevice', 'mpn' => 'GD32F103C8T6', 'desc' => 'Cortex-M3 108MHz MCU (LQFP-48 100% Drop-in Pin Compatible)'],
                    ['vendor' => 'Geehy / ApexMic', 'mpn' => 'APM32F103C8T6', 'desc' => '32-bit MCU 96MHz (LQFP-48 Pin-to-Pin Compatible)'],
                    ['vendor' => 'WCH (Nanjing Qinheng)', 'mpn' => 'CH32F103C8T6', 'desc' => 'RISC-V / Cortex-M3 Compatible 32-bit MCU (LQFP-48)']
                ]
            ],
            'ESP32'       => [
                'vendor' => 'Espressif Systems',
                'name'   => 'Wi-Fi & Dual-mode Bluetooth 32-bit Dual-core MCU SoC / Module',
                'pkg'    => 'SMD Module (18.0x25.5mm)',
                'alts'   => [
                    ['vendor' => 'Espressif Systems', 'mpn' => 'ESP32-WROOM-32E', 'desc' => 'Enhanced Wi-Fi + BLE Module (PCB Antenna)'],
                    ['vendor' => 'Espressif Systems', 'mpn' => 'ESP32-WROOM-32UE', 'desc' => 'Wi-Fi + BLE Module (IPEX U.FL Connector)']
                ]
            ],
            'ATMEGA328P'  => [
                'vendor' => 'Microchip / Atmel',
                'name'   => '8-bit AVR Microcontroller with 32KB Flash (TQFP-32)',
                'pkg'    => 'TQFP-32 (7.0x7.0mm)',
                'alts'   => [
                    ['vendor' => 'Microchip Technology', 'mpn' => 'ATMEGA328P-AU', 'desc' => 'Official Industrial Grade TQFP-32'],
                    ['vendor' => 'LogicGreen', 'mpn' => 'LGT8F328P-LQFP32', 'desc' => 'Enhanced 32MHz AVR-compatible MCU (LQFP-32)']
                ]
            ],

            // 전원관리 및 아날로그 IC (Power & Interface)
            'AMS1117-3.3' => [
                'vendor' => 'Advanced Monolithic Systems',
                'name'   => '3.3V 1A Low Dropout (LDO) Positive Linear Regulator (SOT-223)',
                'pkg'    => 'SOT-223',
                'alts'   => [
                    ['vendor' => 'Texas Instruments', 'mpn' => 'LM1117IMPX-3.3/NOPB', 'desc' => '800mA Low-Dropout Linear Regulator (SOT-223)'],
                    ['vendor' => 'Diodes Inc.', 'mpn' => 'AP1117E33G-13', 'desc' => '1A Positive Low Dropout Regulator (SOT-223)'],
                    ['vendor' => 'ON Semiconductor', 'mpn' => 'NCP1117ST33T3G', 'desc' => '1A Low Dropout Linear Regulator (SOT-223)']
                ]
            ],
            'AMS1117-5.0' => [
                'vendor' => 'Advanced Monolithic Systems',
                'name'   => '5.0V 1A Low Dropout (LDO) Positive Linear Regulator (SOT-223)',
                'pkg'    => 'SOT-223',
                'alts'   => [
                    ['vendor' => 'Texas Instruments', 'mpn' => 'LM1117IMPX-5.0/NOPB', 'desc' => '800mA Low-Dropout Linear Regulator (SOT-223)'],
                    ['vendor' => 'Diodes Inc.', 'mpn' => 'AP1117E50G-13', 'desc' => '1A Positive Low Dropout Regulator (SOT-223)']
                ]
            ],
            'TLV70033'    => [
                'vendor' => 'Texas Instruments',
                'name'   => '3.3V 200mA Low-IQ Low-Dropout Linear Regulator (SOT-23-5)',
                'pkg'    => 'SOT-23-5',
                'alts'   => [
                    ['vendor' => 'Richtek', 'mpn' => 'RT9193-33GB', 'desc' => '300mA Ultra-Low Noise LDO (SOT-23-5)'],
                    ['vendor' => 'Diodes Inc.', 'mpn' => 'AP2112K-3.3TRG1', 'desc' => '600mA Low Dropout Linear Regulator (SOT-23-5)']
                ]
            ],
            'TP4056'      => [
                'vendor' => 'NanJing Top Power',
                'name'   => '1A Standalone Linear Li-lon Battery Charger IC (SOP-8-EP)',
                'pkg'    => 'SOP-8 (Exposed Pad)',
                'alts'   => [
                    ['vendor' => 'Microne (MIC)', 'mpn' => 'ME4056', 'desc' => '1A Complete Constant-Current/Constant-Voltage Linear Charger (SOP-8)'],
                    ['vendor' => 'Microchip Technology', 'mpn' => 'MCP73831T-2ACI/OT', 'desc' => 'Miniature Single-Cell Li-Ion Charge Controller (SOT-23-5)']
                ]
            ],
            'LM358'       => [
                'vendor' => 'Texas Instruments / ST',
                'name'   => 'Dual Operational Amplifier (SOIC-8)',
                'pkg'    => 'SOIC-8 / SOP-8',
                'alts'   => [
                    ['vendor' => 'Texas Instruments', 'mpn' => 'LM358DR', 'desc' => 'Dual General-Purpose Op-Amp (SOIC-8)'],
                    ['vendor' => 'STMicroelectronics', 'mpn' => 'LM358DT', 'desc' => 'Low-Power Dual Operational Amplifier (SOIC-8)'],
                    ['vendor' => 'ROHM Semiconductor', 'mpn' => 'BA10358F-E2', 'desc' => 'Dual Ground Sense Operational Amplifier (SOP-8)']
                ]
            ],
            'LM393'       => [
                'vendor' => 'Texas Instruments / ST',
                'name'   => 'Dual Differential Voltage Comparator (SOIC-8)',
                'pkg'    => 'SOIC-8 / SOP-8',
                'alts'   => [
                    ['vendor' => 'Texas Instruments', 'mpn' => 'LM393DR', 'desc' => 'Dual Differential Comparator (SOIC-8)'],
                    ['vendor' => 'STMicroelectronics', 'mpn' => 'LM393DT', 'desc' => 'Dual Voltage Comparator (SOIC-8)']
                ]
            ],
            '1N4148'      => [
                'vendor' => 'Vishay / Diodes Inc.',
                'name'   => '100V 150mA High-Speed Fast Switching Diode (SOD-123 / SOD-323)',
                'pkg'    => 'SOD-123',
                'alts'   => [
                    ['vendor' => 'Diodes Inc.', 'mpn' => '1N4148W-7-F', 'desc' => 'Surface Mount Fast Switching Diode (SOD-123)'],
                    ['vendor' => 'ON Semiconductor', 'mpn' => '1N4148WS', 'desc' => 'High-Speed Surface Mount Diode (SOD-323)']
                ]
            ],
            'SS34'        => [
                'vendor' => 'Vishay / ON Semi',
                'name'   => '3A 40V Surface Mount Schottky Barrier Rectifier (SMA/SMB)',
                'pkg'    => 'SMA (DO-214AC)',
                'alts'   => [
                    ['vendor' => 'Diodes Inc.', 'mpn' => 'B340A-13-F', 'desc' => '3.0A Surface Mount Schottky Barrier Rectifier (SMA)'],
                    ['vendor' => 'Taiwan Semiconductor', 'mpn' => 'SK34', 'desc' => '3A 40V Schottky Rectifier (SMA/SMB)'],
                    ['vendor' => 'Vishay General Semiconductor', 'mpn' => 'SS34-E3/61T', 'desc' => 'High Current Density Surface Mount Schottky Rectifier (SMA)']
                ]
            ],
            '2N7002'      => [
                'vendor' => 'ON Semi / Vishay',
                'name'   => '60V 115mA N-Channel Trench MOSFET (SOT-23)',
                'pkg'    => 'SOT-23',
                'alts'   => [
                    ['vendor' => 'Diodes Inc.', 'mpn' => '2N7002K-7', 'desc' => '60V N-Channel MOSFET ESD Protected (SOT-23)'],
                    ['vendor' => 'Nexperia', 'mpn' => '2N7002,215', 'desc' => '60V N-channel TrenchMOS (SOT-23)'],
                    ['vendor' => 'ON Semiconductor', 'mpn' => 'BSS138', 'desc' => '50V N-Channel Field Effect Transistor (SOT-23)']
                ]
            ],
            'BSS138'      => [
                'vendor' => 'ON Semi / Diodes',
                'name'   => '50V 220mA N-Channel Logic-Level Enhancement Mode MOSFET (SOT-23)',
                'pkg'    => 'SOT-23',
                'alts'   => [
                    ['vendor' => 'Diodes Inc.', 'mpn' => 'BSS138-7-F', 'desc' => '50V N-Channel MOSFET (SOT-23)'],
                    ['vendor' => 'Vishay Siliconix', 'mpn' => '2N7002-T1-E3', 'desc' => '60V N-Channel MOSFET (SOT-23)']
                ]
            ],
            'CH340G'      => [
                'vendor' => 'WCH (Nanjing Qinheng)',
                'name'   => 'USB to Serial UART Interface Controller IC (SOP-16)',
                'pkg'    => 'SOP-16',
                'alts'   => [
                    ['vendor' => 'WCH', 'mpn' => 'CH340C', 'desc' => 'USB to Serial IC with Built-in Crystal Oscillator (SOP-16 Drop-in)'],
                    ['vendor' => 'Silicon Labs', 'mpn' => 'CP2102-GMR', 'desc' => 'Single-Chip USB to UART Bridge (QFN-28)'],
                    ['vendor' => 'FTDI Chip', 'mpn' => 'FT232RL-REEL', 'desc' => 'USB to Serial UART Interface (SSOP-28)']
                ]
            ],
            'MAX3232'     => [
                'vendor' => 'Texas Instruments / Maxim',
                'name'   => '3.0V to 5.5V Multi-Channel RS-232 Line Driver/Receiver (SOIC-16)',
                'pkg'    => 'SOIC-16 / TSSOP-16',
                'alts'   => [
                    ['vendor' => 'Texas Instruments', 'mpn' => 'MAX3232CDR', 'desc' => '3V to 5.5V Multichannel RS-232 Line Driver/Receiver (SOIC-16)'],
                    ['vendor' => 'MaxLinear (Exar)', 'mpn' => 'SP3232EEN-L/TR', 'desc' => '3.0V to 5.5V Low Power RS-232 Transceiver (SOIC-16)'],
                    ['vendor' => 'Renesas (Intersil)', 'mpn' => 'ICL3232CBZ-T', 'desc' => '3.0V to 5.5V Low Power RS-232 Transceiver (SOIC-16)']
                ]
            ]
        ];

        foreach ($icDb as $key => $info) {
            if (strpos($upper, $key) !== false || preg_match('/\b' . preg_quote($key, '/') . '\b/i', $upper)) {
                $alts = [];
                if (!empty($info['vendor'])) {
                    $alts[] = [
                        'vendor_name' => $info['vendor'],
                        'mpn'         => $key,
                        'package'     => $info['pkg'] ?? 'Standard SMD',
                        'description' => "{$info['name']} (Standard/Primary)",
                        'status'      => 'ACTIVE',
                        'links'       => self::generateLinks($key, $info['name'])
                    ];
                }
                foreach ($info['alts'] as $alt) {
                    if (is_array($alt)) {
                        $alts[] = [
                            'vendor_name' => $alt['vendor'] ?? '공인 호환사',
                            'mpn'         => $alt['mpn'],
                            'package'     => $info['pkg'] ?? 'Direct Pin-to-Pin Compatible',
                            'description' => $alt['desc'] ?? "Compatible Alternate for {$key}",
                            'status'      => 'ACTIVE',
                            'links'       => self::generateLinks($alt['mpn'], $info['name'])
                        ];
                    } else {
                        $alts[] = [
                            'vendor_name' => '공인 호환 대체품 (Cross Alternate)',
                            'mpn'         => $alt,
                            'package'     => $info['pkg'] ?? 'Direct Pin-to-Pin Compatible',
                            'description' => "Compatible Alternate for {$key}",
                            'status'      => 'ACTIVE',
                            'links'       => self::generateLinks($alt, $info['name'])
                        ];
                    }
                }

                return [
                    'success'       => true,
                    'category'      => 'IC_SEMICONDUCTOR',
                    'type_name'     => '반도체 IC / 소자',
                    'standard_code' => "IC-{$key}",
                    'standard_name' => $info['name'],
                    'parameters'    => ['model' => $key],
                    'primary_mpn'   => $key,
                    'alternates'    => $alts,
                    'search_links'  => self::generateLinks($key, $info['name'])
                ];
            }
        }

        return null;
    }

    private static function buildIcResult(string $raw, string $location = ''): array {
        $locText = !empty($location) ? " [위치: {$location}]" : "";
        return [
            'success'       => true,
            'category'      => 'IC_SEMICONDUCTOR',
            'type_name'     => '반도체 IC / 센서 소자 (U/IC)',
            'standard_code' => strtoupper($raw),
            'standard_name' => "{$raw}{$locText} 반도체 IC / 센서 소자",
            'parameters'    => ['model' => $raw, 'ref_des' => $location],
            'primary_mpn'   => $raw,
            'alternates'    => [],
            'search_links'  => self::generateLinks($raw, $raw)
        ];
    }

    private static function buildInductorResult(string $raw, string $location = ''): array {
        $locText = !empty($location) ? " [위치: {$location}]" : "";
        return [
            'success'       => true,
            'category'      => 'INDUCTOR',
            'type_name'     => '인덕터 / 코일 / 페라이트 비드 (L)',
            'standard_code' => strtoupper($raw),
            'standard_name' => "{$raw}{$locText} 칩인덕터 / 코일 / 비드 소자",
            'parameters'    => ['model' => $raw, 'ref_des' => $location],
            'primary_mpn'   => $raw,
            'alternates'    => [],
            'search_links'  => self::generateLinks($raw, $raw)
        ];
    }

    private static function buildCapacitorResult(string $raw, string $location = ''): array {
        $locText = !empty($location) ? " [위치: {$location}]" : "";
        return [
            'success'       => true,
            'category'      => 'CAPACITOR',
            'type_name'     => '커패시터 / 콘덴서 소자 (C)',
            'standard_code' => strtoupper($raw),
            'standard_name' => "{$raw}{$locText} 커패시터 / 콘덴서 소자",
            'parameters'    => ['model' => $raw, 'ref_des' => $location],
            'primary_mpn'   => $raw,
            'alternates'    => [],
            'search_links'  => self::generateLinks($raw, $raw)
        ];
    }

    private static function buildResistorResult(string $raw, string $location = ''): array {
        $locText = !empty($location) ? " [위치: {$location}]" : "";
        return [
            'success'       => true,
            'category'      => 'RESISTOR',
            'type_name'     => '칩저항 소자 (R)',
            'standard_code' => strtoupper($raw),
            'standard_name' => "{$raw}{$locText} 칩저항 소자",
            'parameters'    => ['model' => $raw, 'ref_des' => $location],
            'primary_mpn'   => $raw,
            'alternates'    => [],
            'search_links'  => self::generateLinks($raw, $raw)
        ];
    }

    private static function buildDiodeResult(string $raw, string $location = ''): array {
        $locText = !empty($location) ? " [위치: {$location}]" : "";
        return [
            'success'       => true,
            'category'      => 'DIODE',
            'type_name'     => '다이오드 / LED 소자 (D)',
            'standard_code' => strtoupper($raw),
            'standard_name' => "{$raw}{$locText} 다이오드 / LED 소자",
            'parameters'    => ['model' => $raw, 'ref_des' => $location],
            'primary_mpn'   => $raw,
            'alternates'    => [],
            'search_links'  => self::generateLinks($raw, $raw)
        ];
    }

    private static function buildFallbackResult(string $raw, string $location = ''): array {
        $locText = !empty($location) ? " [위치: {$location}]" : "";
        return [
            'success'       => true,
            'category'      => 'GENERIC',
            'type_name'     => '단독 규격 / 범용 부품',
            'standard_code' => strtoupper($raw),
            'standard_name' => "{$raw}{$locText}",
            'parameters'    => ['raw_input' => $raw, 'ref_des' => $location],
            'primary_mpn'   => $raw,
            'alternates'    => [],
            'search_links'  => self::generateLinks($raw, $raw)
        ];
    }

    /**
     * Digi-Key, Mouser, Octopart, AllDataSheet, LCSC 다이렉트 딥링크 생성
     */
    public static function generateLinks(string $mpn, string $desc = ''): array {
        $encoded = urlencode($mpn);
        return [
            'digikey'     => "https://www.digikey.kr/ko/products/result?keywords={$encoded}",
            'mouser'      => "https://kr.mouser.com/c/?q={$encoded}",
            'octopart'    => "https://octopart.com/search?q={$encoded}",
            'alldatasheet'=> "https://www.alldatasheet.com/view.jsp?Searchword={$encoded}",
            'lcsc'        => "https://www.lcsc.com/search?q={$encoded}"
        ];
    }

    // Helper functions for math and formatting
    private static function capCodeToHuman(string $code): string {
        $sig = (int)substr($code, 0, 2);
        $exp = (int)substr($code, 2, 1);
        $pf = $sig * pow(10, $exp);

        if ($pf >= 1000000) {
            return ($pf / 1000000) . 'µF';
        } else if ($pf >= 1000) {
            return ($pf / 1000) . 'nF (' . ($pf / 1000000) . 'µF)';
        } else {
            return $pf . 'pF';
        }
    }

    private static function humanCapToCode(float $val, string $unit): string {
        $pf = $val;
        if ($unit === 'UF' || $unit === 'U') {
            $pf = $val * 1000000;
        } else if ($unit === 'NF' || $unit === 'N') {
            $pf = $val * 1000;
        }

        $exp = 0;
        while ($pf >= 100) {
            $pf /= 10;
            $exp++;
        }
        return str_pad(round($pf), 2, '0', STR_PAD_LEFT) . $exp;
    }

    private static function calcResCode3(float $num, string $mult): string {
        $ohms = ($mult === 'K') ? $num * 1000 : (($mult === 'M') ? $num * 1000000 : $num);
        if ($ohms == 0) return '000';
        $exp = 0;
        while ($ohms >= 100) {
            $ohms /= 10;
            $exp++;
        }
        return str_pad(round($ohms), 2, '0', STR_PAD_LEFT) . $exp;
    }

    private static function calcResCode4(float $num, string $mult): string {
        $ohms = ($mult === 'K') ? $num * 1000 : (($mult === 'M') ? $num * 1000000 : $num);
        if ($ohms == 0) return '0000';
        $exp = 0;
        while ($ohms >= 1000) {
            $ohms /= 10;
            $exp++;
        }
        return str_pad(round($ohms), 3, '0', STR_PAD_LEFT) . $exp;
    }

    private static function resCode3ToHuman(string $code): string {
        $sig = (int)substr($code, 0, 2);
        $exp = (int)substr($code, 2, 1);
        $ohms = $sig * pow(10, $exp);
        if ($ohms >= 1000000) return ($ohms / 1000000) . 'MΩ';
        if ($ohms >= 1000) return ($ohms / 1000) . 'kΩ';
        return $ohms . 'Ω';
    }

    private static function resCode4ToHuman(string $code): string {
        $sig = (int)substr($code, 0, 3);
        $exp = (int)substr($code, 3, 1);
        $ohms = $sig * pow(10, $exp);
        if ($ohms >= 1000000) return ($ohms / 1000000) . 'MΩ';
        if ($ohms >= 1000) return ($ohms / 1000) . 'kΩ';
        return $ohms . 'Ω';
    }

    private static function resCode3ToOhms(string $code): float {
        $sig = (int)substr($code, 0, 2);
        $exp = (int)substr($code, 2, 1);
        return $sig * pow(10, $exp);
    }

    private static function resCode4ToOhms(string $code): float {
        $sig = (int)substr($code, 0, 3);
        $exp = (int)substr($code, 3, 1);
        return $sig * pow(10, $exp);
    }
}
