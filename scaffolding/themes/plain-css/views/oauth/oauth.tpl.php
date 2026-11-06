{{-- OAuth Applications admin overview (plain-CSS theme).

Variables:
  $this->apps — array[] {appid, name, description, apikey, status, created} --}}
<div class="container">
    <h2>OAuth Applications</h2>

    @if (empty($this->apps))
        <p>No OAuth applications registered yet.</p>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Client ID</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->apps as $app)
                    <tr>
                        <td>
                            <strong>{{ (string) $app['name'] }}</strong>
                            @if (!empty($app['description']))
                                <br><small>{{ (string) $app['description'] }}</small>
                            @endif
                        </td>
                        <td><code>{{ (string) $app['apikey'] }}</code></td>
                        <td>{!! (int) $app['status'] === 1 ? 'Active' : 'Inactive' !!}</td>
                        <td>{{ date('d M Y', (int) $app['created']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
