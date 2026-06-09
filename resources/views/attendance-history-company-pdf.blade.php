@php
    $formatMinutes = function ($minutes) {
        $minutes = max(0, (int) round($minutes));
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    };
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
            font-size: 7.2px;
            line-height: 1.25;
        }
        .header {
            margin-bottom: 3px;
            text-align: center;
        }
        .title {
            font-size: 13px;
            font-weight: 700;
        }
        .management-number {
            display: inline-block;
            font-size: 13px;
            font-weight: 700;
            margin-left: 16px;
        }
        .name-label {
            margin-left: 12px;
        }
        .name {
            font-size: 13px;
            font-weight: 700;
            margin-left: 5px;
        }
        .meta {
            font-size: 8.2px;
            margin-bottom: 5px;
            text-align: center;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 0.7px solid #444;
            padding: 1.6px 3px;
            text-align: center;
            vertical-align: top;
        }
        th {
            background: #eef1f4;
            font-weight: 700;
        }
        .detail-table td {
            height: 13px;
        }
        .weekday-sat {
            color: #0057ff;
            font-weight: 700;
        }
        .weekday-sun {
            color: #ff0000;
            font-weight: 700;
        }
        .wrap {
            line-height: 1.28;
            text-align: left;
            white-space: pre-wrap;
        }
        .work-time {
            line-height: 1.2;
            white-space: pre-wrap;
        }
        .summary-table {
            margin-top: 9px;
        }
    </style>
</head>
<body>
    <div class="header">
        <span class="title">令和{{ $eraYear }}年{{ $month->month }}月 勤怠記録（会社保管用）</span>
        <span class="management-number">{{ $user->management_number ?: '-' }}</span>
        <span class="name-label">氏名</span>
        <span class="name">{{ $user->name }}</span>
    </div>
    <div class="meta">
        部署: {{ $user->department ?: '-' }} | 就労タイプ: {{ $user->work_style ?: '-' }} | 出力日: {{ $outputDate }}
    </div>

    <table class="detail-table">
        <thead>
            <tr>
                <th style="width: 24px;">日</th>
                <th style="width: 24px;">曜</th>
                <th style="width: 38px;">出勤</th>
                <th style="width: 38px;">退勤</th>
                <th style="width: 38px;">場所</th>
                <th style="width: 32px;">確認</th>
                <th style="width: 76px;">実働時間</th>
                <th style="width: 50px;">備考</th>
                <th>業務報告</th>
                <th>管理者コメント</th>
                <th style="width: 34px;">BMI</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['day'] }}</td>
                    <td class="{{ $row['weekday_key'] === 6 ? 'weekday-sat' : ($row['weekday_key'] === 0 ? 'weekday-sun' : '') }}">{{ $row['weekday'] }}</td>
                    <td>{{ $row['clock_in'] ?: '-' }}</td>
                    <td>{{ $row['clock_out'] ?: '-' }}</td>
                    <td>{{ $row['work_location'] }}</td>
                    <td>{{ $row['confirmed'] }}</td>
                    <td class="work-time">{{ $row['work_time'] }}</td>
                    <td>-</td>
                    <td class="wrap">{{ $row['business_report'] ?: '-' }}</td>
                    <td class="wrap">{{ $row['admin_comment'] ?: '-' }}</td>
                    <td>{{ $row['bmi'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <thead>
            <tr>
                <th>出勤日数</th>
                <th>公休日数</th>
                <th>有給日数</th>
                <th>欠勤日数</th>
                <th>遅刻時間</th>
                <th>早退時間</th>
                <th>出勤率</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $summary['attendance_days'] }}</td>
                <td>{{ $summary['holiday_days'] }}</td>
                <td>{{ $paidLeaveDays }}</td>
                <td>{{ $summary['absence_days'] }}</td>
                <td>{{ $formatMinutes($summary['late_minutes']) }}</td>
                <td>{{ $formatMinutes($summary['early_leave_minutes']) }}</td>
                <td>{{ number_format($summary['attendance_rate'], 1) }}%</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
