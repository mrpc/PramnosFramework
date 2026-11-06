{{-- OAuth Applications admin overview (Bootstrap theme).

Variables:
  $this->apps — array[] {appid, name, description, apikey, status, created} --}}
<div class="container py-4" style="max-width:900px">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">OAuth Applications</h2>
    </div>

    @if (empty($this->apps))
        <div role="status" class="alert alert-info">No OAuth applications registered yet.</div>
    @else
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-light">
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
                                        <br><small class="text-muted">{{ (string) $app['description'] }}</small>
                                    @endif
                                </td>
                                <td><code>{{ (string) $app['apikey'] }}</code></td>
                                <td>
                                    @if ((int) $app['status'] === 1)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                                <td>{{ date('d M Y', (int) $app['created']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
