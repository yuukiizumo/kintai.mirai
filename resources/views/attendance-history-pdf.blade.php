@php
    $formatMinutes = function ($minutes) {
        $minutes = max(0, (int) round($minutes));
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    };
    $formatValue = fn ($value) => $value === '' || $value === null ? '-' : $value;
    $paidLeaveDays = floor($summary['paid_leave_days']) == $summary['paid_leave_days']
        ? (string) (int) $summary['paid_leave_days']
        : number_format($summary['paid_leave_days'], 1);
@endphp
<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            color: #111;
            font-family: notosansjp, sans-serif;
            font-size: 10.8px;
            line-height: 1.3;
        }
        .header {
            align-items: center;
            display: flex;
            gap: 18px;
            margin-bottom: 8px;
        }
        .title {
            font-size: 15px;
        }
        .management-number {
            background: #ffff00;
            display: inline-block;
            font-size: 15px;
            font-weight: bold;
            min-width: 36px;
            padding: 1px 5px;
            text-align: center;
        }
        .name-label {
            margin-left: 8px;
        }
        .name {
            font-size: 15px;
            margin-left: 10px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 0.8px solid #333;
            padding: 2.2px 4px;
            text-align: center;
            vertical-align: middle;
        }
        th {
            background: #f2f2f2;
            color: #111;
            font-weight: 600;
        }
        .attendance-table td {
            height: 13.5px;
        }
        .weekday-sat {
            color: #0057ff;
            font-weight: bold;
        }
        .weekday-sun {
            color: #ff0000;
            font-weight: bold;
        }
        .note {
            text-align: left;
        }
        .summary-table {
            margin-top: 14px;
        }
        .highlight {
            background: #ffff00;
        }
        .small {
            font-size: 9px;
        }
        .dash {
            color: #111;
        }
    </style>
</head>
<body>
    <div class="header">
        <span class="title">令和{{ $eraYear }}年{{ $month->month }}月</span>
        <span class="management-number">{{ $user->management_number ?: '-' }}</span>
        <span class="name-label">氏名</span>
        <span class="name">{{ $user->name }}</span>
    </div>

    <table class="attendance-table">
        <thead>
            <tr>
                <th rowspan="2" style="width: 34px;">日付</th>
                <th rowspan="2" style="width: 34px;">曜日</th>
                <th rowspan="2" style="width: 76px;">出勤状況</th>
                <th colspan="2" style="width: 118px;">シフト</th>
                <th colspan="2" style="width: 118px;">入退時間</th>
                <th colspan="2" style="width: 118px;">勤務時間</th>
                <th rowspan="2" style="width: 70px;">休憩時間</th>
                <th rowspan="2">備考欄</th>
            </tr>
            <tr>
                <th class="small">入社</th>
                <th class="small">退社</th>
                <th class="small">開始</th>
                <th class="small">終了</th>
                <th class="small">時間内</th>
                <th class="small">時間外</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['day'] }}</td>
                    <td class="{{ $row['weekday_key'] === 6 ? 'weekday-sat' : ($row['weekday_key'] === 0 ? 'weekday-sun' : '') }}">{{ $row['weekday'] }}</td>
                    <td>{{ $row['status'] }}</td>
                    <td class="dash">{{ $formatValue($row['shift_clock_in']) }}</td>
                    <td class="dash">{{ $formatValue($row['shift_clock_out']) }}</td>
                    <td class="dash">{{ $formatValue($row['clock_in']) }}</td>
                    <td class="dash">{{ $formatValue($row['clock_out']) }}</td>
                    <td class="dash">{{ $row['within_minutes'] > 0 ? $formatMinutes($row['within_minutes']) : '-' }}</td>
                    <td class="dash">{{ $row['overtime_minutes'] > 0 ? $formatMinutes($row['overtime_minutes']) : ($row['within_minutes'] > 0 ? '00:00' : '-') }}</td>
                    <td class="dash">{{ $row['declared_break_minutes'] > 0 ? $formatMinutes($row['declared_break_minutes']) : '-' }}</td>
                    <td class="note">{{ $row['note'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <thead>
            <tr>
                <th rowspan="2">出勤日数</th>
                <th rowspan="2">公休日数</th>
                <th rowspan="2">有給日数</th>
                <th rowspan="2">有給残日数</th>
                <th rowspan="2">欠勤日数</th>
                <th rowspan="2">遅刻時間</th>
                <th rowspan="2">早退時間</th>
                <th rowspan="2">休憩時間</th>
                <th colspan="2">時間内訳</th>
            </tr>
            <tr>
                <th>時間内</th>
                <th>時間外</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $summary['attendance_days'] }}</td>
                <td>{{ $summary['holiday_days'] }}</td>
                <td>{{ $paidLeaveDays }}</td>
                <td class="highlight">{{ $user->paid_leave_remaining_days ?? 0 }}</td>
                <td>{{ $summary['absence_days'] }}</td>
                <td>{{ $formatMinutes($summary['late_minutes']) }}</td>
                <td>{{ $formatMinutes($summary['early_leave_minutes']) }}</td>
                <td>{{ $formatMinutes($summary['break_minutes']) }}</td>
                <td>{{ $formatMinutes($summary['within_minutes']) }}</td>
                <td>{{ $formatMinutes($summary['overtime_minutes']) }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
