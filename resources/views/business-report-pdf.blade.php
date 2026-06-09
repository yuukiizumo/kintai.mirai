<!doctype html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <style>
        body {
            color: #111;
            font-family: notosansjp, sans-serif;
            font-size: 10.5px;
            line-height: 1.55;
        }
        .title {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .meta {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 0.8px solid #333;
            padding: 6px 7px;
            vertical-align: top;
        }
        th {
            background: #f0f2f5;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
        }
        td.date {
            font-weight: 700;
            text-align: center;
            white-space: nowrap;
            width: 70px;
        }
        td.weekday {
            font-weight: 700;
            text-align: center;
            width: 28px;
        }
        .weekday-sat {
            color: #0057ff;
        }
        .weekday-sun {
            color: #d60000;
        }
        .text-cell {
            min-height: 34px;
            white-space: pre-wrap;
        }
        tr {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="title">月次業務報告書　対象年月: 令和{{ $eraYear }}年{{ $month->month }}月</div>
    <div class="meta">氏名: {{ $user->name }}</div>

    <table>
        <thead>
            <tr>
                <th>日付</th>
                <th>曜</th>
                <th>業務報告</th>
                <th>管理者コメント</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="date">{{ $row['date'] }}</td>
                    <td class="weekday {{ $row['weekday_key'] === 6 ? 'weekday-sat' : ($row['weekday_key'] === 0 ? 'weekday-sun' : '') }}">{{ $row['weekday'] }}</td>
                    <td class="text-cell">{{ $row['note'] !== '' ? $row['note'] : '-' }}</td>
                    <td class="text-cell">{{ $row['admin_comment'] !== '' ? $row['admin_comment'] : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
