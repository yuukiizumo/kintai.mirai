@php
    $yen = fn ($value) => '&#165;'.number_format((int) round($value));
    $number = fn ($value, $decimals = 0) => number_format((float) $value, $decimals);
    $hours = fn ($minutes) => number_format(max(0, (int) $minutes) / 60, 2);
    $payEraYear = max(1, (int) $payDate->format('Y') - 2018);
    $paidLeaveDays = floor($row['paid_leave_days']) == $row['paid_leave_days']
        ? (string) (int) $row['paid_leave_days']
        : number_format($row['paid_leave_days'], 1);
    $totalWithTransportation = (int) $row['net_pay'];
@endphp
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            color: #111;
            font-family: msgothic, monospace;
            font-size: 6.3px;
            line-height: 1.08;
        }
        .sheet { width: 186mm; }
        .top, .title-row, .detail {
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
        }
        .top {
            margin-top: -3.6mm;
            margin-bottom: 4.7mm;
            margin-left: 14mm;
            width: 172mm;
        }
        .top td {
            border: 0;
            padding: 0;
            vertical-align: bottom;
        }
        .title-row {
            margin-bottom: 1mm;
            margin-left: 14mm;
            width: 172mm;
        }
        .title-row td {
            border: 0.55px solid #222;
            padding: 1.05mm 0.8mm;
            text-align: center;
            vertical-align: middle;
        }
        .title {
            background: #fff;
            font-size: 6.9px;
            font-weight: 400;
            letter-spacing: 1.2px;
        }
        .grand-label, .pay-date-label, .detail th {
            background: #e6e6e6;
            font-weight: 400;
        }
        .grand-value {
            background: #fff;
            font-weight: 700;
        }
        .detail th, .detail td {
            border: 0.55px solid #222;
            height: 3.45mm;
            padding: 0.2mm 0.45mm;
            text-align: center;
            vertical-align: middle;
        }
        .side {
            background: #d9d9d9;
            font-size: 6.3px;
            font-weight: 700;
            line-height: 1.25;
            width: 7mm;
        }
        .value { background: #fff; }
        .muted {
            background: #f2f2f2;
            color: transparent;
        }
        .amount { font-weight: 700; }
        .total-cell {
            background: #d9d9d9 !important;
            font-weight: 700 !important;
        }
        .month { width: 22mm; }
        .company { width: 46mm; }
        .person-label { text-align: right; width: 12mm; }
        .person { font-weight: 700; width: 34mm; }
        .belong-label { text-align: right; width: 10mm; }
        .label { width: 20mm; }
        .wide-label { width: 25mm; }
    </style>
</head>
<body>
    <div class="sheet">
        <table class="top">
            <tr>
                <td class="month">{{ $month->year }}&#24180;{{ $month->month }}&#26376;</td>
                <td class="company">&#12450;&#12463;&#12486;&#12451;&#12502;&#12469;&#12509;&#12540;&#12488;&#26666;&#24335;&#20250;&#31038;</td>
                <td class="person-label">&#27663;&#21517;</td>
                <td class="person">{{ $user->name }}</td>
                <td class="person-label">&#27583;</td>
                <td class="belong-label">&#25152;&#23646;</td>
                <td>@if($user->department){{ $user->department }}@else&#26410;&#35373;&#23450;@endif</td>
            </tr>
        </table>

        <table class="title-row">
            <tr>
                <td class="title" style="width: 76mm;">&#32102; &#19982; &#26126; &#32048; &#26360;</td>
                <td class="grand-label" style="width: 34mm;">&#21512; &#35336; &#65288;&#20132;&#36890;&#36027;&#36796;&#65289;</td>
                <td class="grand-value" style="width: 26mm;">{!! $yen($totalWithTransportation) !!}</td>
                <td class="pay-date-label" style="width: 18mm;">&#25903; &#32102; &#26085;</td>
                <td>&#20196;&#21644;{{ $payEraYear }}&#24180;{{ $payDate->month }}&#26376;{{ $payDate->day }}&#26085;</td>
            </tr>
        </table>

        <table class="detail">
            <tr>
                <td class="side" rowspan="4">&#21220;<br>&#24608;</td>
                <th class="label">&#20986;&#21220;&#26085;&#25968;</th>
                <th class="label">&#22312;&#23429;&#26085;&#25968;</th>
                <th class="label">&#21172;&#20685;&#26178;&#38291;</th>
                <th class="label">&#26178; &#38291; &#22806;</th>
                <th class="label">&#20241;&#20986;&#26178;&#38291;&#22806;</th>
                <th class="label">&#27424; &#21220;</th>
                <th class="label">&#36933; &#21051;</th>
                <th class="label">&#26089; &#36864;</th>
            </tr>
            <tr>
                <td class="value">{{ $number($row['attendance_days'], 2) }}</td>
                <td class="value">{{ $number($row['remote_days']) }}</td>
                <td class="value">{{ $hours($row['work_minutes']) }}</td>
                <td class="value">{{ $hours($row['overtime_minutes']) }}</td>
                <td class="value">{{ $number($row['holiday_overtime_minutes']) }}</td>
                <td class="value">{{ $number($row['absence_days']) }}</td>
                <td class="value">{{ $number($row['late_days']) }}</td>
                <td class="value">{{ $number($row['early_leave_days']) }}</td>
            </tr>
            <tr>
                <th>&#26377;&#32102;&#20241;&#26247;</th>
                <th>&#24524; &#24341;</th>
                <th>&#24950;&#24340;&#20241;&#26247;</th>
                <th colspan="5" class="muted">.</th>
            </tr>
            <tr>
                <td class="value">{{ $paidLeaveDays }}</td>
                <td class="value">{{ $number($row['condolence_leave_days']) }}</td>
                <td class="value">0</td>
                <td colspan="5" class="muted">.</td>
            </tr>

            <tr>
                <td class="side" rowspan="4">&#25903;<br>&#32102;</td>
                <th>&#22522; &#26412; &#32102;</th>
                <th>&#24441;&#32887;&#25163;&#24403;</th>
                <th>&#30342;&#21220;&#25163;&#24403;</th>
                <th>&#35519;&#25972;&#25163;&#24403;</th>
                <th>&#36039;&#26684;&#25163;&#24403;</th>
                <th>&#26989;&#21209;&#25163;&#24403;</th>
                <th class="wide-label">&#12524;&#12509;&#12540;&#12488;&#25552;&#20986;&#25163;&#24403;</th>
                <th>&#33021; &#21147; &#32102;</th>
            </tr>
            <tr>
                <td class="value amount">{!! $yen($row['basic_pay']) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen($row['report_allowance']) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
            </tr>
            <tr>
                <th>&#26178;&#38291;&#22806;&#25163;&#24403;</th>
                <th>&#20303;&#23429;&#25163;&#24403;</th>
                <th>&#27424;&#21220;&#25511;&#38500;</th>
                <th>&#26377;&#32102;&#25903;&#32102;&#20998;</th>
                <th colspan="4" class="total-cell">&#25903; &#32102; &#38989;</th>
            </tr>
            <tr>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td colspan="4" class="value amount">{!! $yen($row['gross_pay']) !!}</td>
            </tr>

            <tr>
                <td class="side" rowspan="4">&#25511;<br>&#38500;</td>
                <th>&#20581;&#24247;&#20445;&#38522;</th>
                <th>&#21402;&#29983;&#24180;&#37329;&#20445;&#38522;</th>
                <th>&#23376;&#32946;&#12390;&#25903;&#25588;</th>
                <th>&#38607;&#29992;&#20445;&#38522;</th>
                <th colspan="4" class="muted">.</th>
            </tr>
            <tr>
                <td class="value">{!! $yen($row['health_insurance_deduction'] + $row['nursing_care_insurance_deduction']) !!}</td>
                <td class="value">{!! $yen($row['welfare_pension_deduction']) !!}</td>
                <td class="value">{!! $yen($row['child_care_contribution_deduction']) !!}</td>
                <td class="value">{!! $yen($row['employment_insurance_deduction']) !!}</td>
                <td colspan="4" class="muted">.</td>
            </tr>
            <tr>
                <th>&#25152; &#24471; &#31246;</th>
                <th>&#24066;&#30010;&#26449;&#27665;&#31246;</th>
                <th>&#20445;&#38522;&#26009;&#21512;&#35336;</th>
                <th>&#28304;&#27849;&#25511;&#38500;&#38989;&#21512;&#35336;</th>
                <th class="total-cell">&#25511;&#38500;&#21512;&#35336;</th>
                <th colspan="3" class="muted">.</th>
            </tr>
            <tr>
                <td class="value">{!! $yen($row['income_tax_deduction']) !!}</td>
                <td class="value">{!! $yen($row['resident_tax_deduction']) !!}</td>
                <td class="value">{!! $yen($row['insurance_total']) !!}</td>
                <td class="value">{!! $yen($row['withholding_total']) !!}</td>
                <td class="value amount">{!! $yen($row['total_deductions']) !!}</td>
                <td colspan="3" class="muted">.</td>
            </tr>

            <tr>
                <td class="side" rowspan="3">&#20633;<br>&#32771;</td>
                <th class="wide-label">&#36890; &#21220; &#20132; &#36890; &#36027;</th>
                <td class="value">{!! $yen($row['transportation_expense']) !!}</td>
                <td class="value">0</td>
                <td class="value">{!! $yen(0) !!}</td>
                <td colspan="4" rowspan="3" class="value"></td>
            </tr>
            <tr>
                <th>&#27424; &#39135; &#25511; &#38500;</th>
                <td class="value">{!! $yen($row['meal_deduction']) !!}</td>
                <td class="value">0</td>
                <td class="value">{!! $yen(0) !!}</td>
            </tr>
            <tr>
                <th>&#12381;&#12398;&#20182;&#31934;&#31639;</th>
                <td class="value">{!! $yen($row['other_adjustment']) !!}</td>
                <td class="value">0</td>
                <td class="value">{!! $yen(0) !!}</td>
            </tr>
        </table>
    </div>
</body>
</html>
