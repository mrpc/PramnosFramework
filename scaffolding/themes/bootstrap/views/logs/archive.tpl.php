{{-- Archive log files (Bootstrap theme).

Variables:
  $this->toolbar, $this->clearList — toolbar
  $this->days   — int    current "older than N days" value
  $this->result — array|null {archived:int, archive_file:string, errors:string[]} --}}
<div class="container py-4">
    <h2 class="mb-4">Archive Log Files</h2>
    @include('_toolbar')

    <div class="card shadow-sm" style="max-width:36rem;">
        <div class="card-body">
            @if ($this->result)
                @if ($this->result['archived'] > 0)
                    <div role="status" class="alert alert-success">
                        Successfully archived {!! (int) $this->result['archived'] !!} log files to
                        {{ $this->result['archive_file'] }}
                    </div>
                @elseif (!empty($this->result['errors']))
                    <div role="alert" class="alert alert-danger">
                        <strong>Error:</strong> {{ implode(', ', $this->result['errors']) }}
                    </div>
                @else
                    <div role="status" class="alert alert-info">
                        No log files older than {!! (int) $this->days !!} days were found.
                    </div>
                @endif
            @endif

            <form method="post">
                <div class="mb-3">
                    <label for="days" class="form-label">Archive logs older than (days):</label>
                    <input type="number" id="days" name="days" value="{!! (int) $this->days !!}" min="1" max="365"
                           class="form-control">
                </div>
                <input type="hidden" name="action" value="archive">
                <button type="submit" class="btn btn-primary">
                    Archive Log Files
                </button>
            </form>
        </div>
    </div>
</div>
