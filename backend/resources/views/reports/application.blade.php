@include('reports.styles')
<h1>{{ $card['title'] }}</h1>
<table class="facts">
    @foreach ($card['summary'] as [$label, $value])
        <tr><td class="label">{{ $label }}</td><td>{{ \App\Reports\Formats::cell($value) }}</td></tr>
    @endforeach
</table>

@foreach ($card['sections'] as $section)
    <h2>{{ $section['title'] }}</h2>
    @isset($section['note'])
        <p class="note">{{ $section['note'] }}</p>
    @endisset
    @foreach ($section['groups'] as $group)
        @if ($group['title'] !== null)
            <h3>{{ $group['title'] }}</h3>
        @endif
        @if (isset($group['table']))
            @if ($group['table']['rows'] === [])
                <p class="muted">{{ $group['table']['empty'] }}</p>
            @else
                <table class="grid" repeat_header="1">
                    <thead><tr>
                        @foreach ($group['table']['columns'] as $label)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                        @foreach ($group['table']['rows'] as $index => $cells)
                            <tr class="{{ $index % 2 ? 'even' : '' }}">
                                @foreach ($cells as $cell)
                                    <td>{{ \App\Reports\Formats::cell($cell) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @else
            <table class="facts">
                @foreach ($group['rows'] as $row)
                    <tr>
                        <td class="label">{{ $row[0] }}</td>
                        <td>{{ \App\Reports\Formats::cell($row[1] ?? '—') }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    @endforeach
@endforeach
