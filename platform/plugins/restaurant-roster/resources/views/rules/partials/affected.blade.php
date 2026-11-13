<div class="table-responsive" style="max-height: 320px">
    <table class="table table-sm table-vcenter mb-0">
        <thead><tr><th>Ngày</th><th>Nhân viên</th><th>Ca</th><th>Lý do</th><th>Trạng thái</th></tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="text-nowrap">{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="font-monospace">{{ $row['notation'] }}</td>
                    <td>{{ $row['reason'] }}</td>
                    <td>{{ $row['state'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
