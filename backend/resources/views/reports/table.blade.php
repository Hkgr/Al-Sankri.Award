@include('reports.styles')
<h1>{{ $report->title }}</h1>
<table class="scope">
    @foreach ($report->scope as [$label, $value])
        <tr><td class="label">{{ $label }}</td><td>{{ \App\Reports\Formats::cell($value) }}</td></tr>
    @endforeach
    <tr><td class="label">وقت الإنشاء</td><td>{{ $report->generatedLabel() }} بتوقيت دمشق</td></tr>
    <tr><td class="label">عدد الصفوف</td><td>{{ count($report->rows) }}</td></tr>
</table>
@if ($report->rows === [])
    <p class="muted">لا توجد صفوف تطابق هذا النطاق.</p>
@else
    {{-- The heading row repeats on every page (thead); long cells wrap. --}}
    <table class="grid" repeat_header="1" autosize="1">
        <thead>
            <tr>
                @foreach ($report->columns as $column)
                    <th>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($report->rows as $index => $row)
                <tr class="{{ $index % 2 ? 'even' : '' }}">
                    @foreach ($report->columns as $column)
                        <td>{{ \App\Reports\Formats::cell($row[$column['key']] ?? '') }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
