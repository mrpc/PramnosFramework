{{-- Rotate log files (Bootstrap theme).

Variables:
  $this->toolbar, $this->clearList — toolbar
  $this->maxSize       — int   max size (MB)
  $this->maxBackups    — int   backups to keep
  $this->selectedFiles — string[] files checked in the last submit
  $this->results       — array  file => bool (rotated?) — may be empty
  $this->stats         — array[] {name, size_formatted, modified_formatted} --}}
<div class="container py-4">
    <h2 class="mb-4">Rotate Log Files</h2>
    @include('_toolbar')

    <div class="card shadow-sm">
        <div class="card-body">
            @if (!empty($this->results))
                <div role="status" class="alert alert-info">
                    <div class="fw-semibold mb-1">Rotation Results:</div>
                    <ul class="mb-0">
                        @foreach ($this->results as $file => $rotated)
                            <li>
                                {{ $file }}:
                                @if ($rotated)
                                    <span class="text-success">Rotated successfully</span>
                                @else
                                    <span class="text-muted">No rotation needed (file size below threshold)</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="post">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="max_size" class="form-label">Maximum Size (MB):</label>
                        <input type="number" id="max_size" name="max_size" value="{!! (int) $this->maxSize !!}" min="1" max="1000"
                               class="form-control">
                        <p class="form-text">Log files larger than this will be rotated</p>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="max_backups" class="form-label">Maximum Backup Files:</label>
                        <input type="number" id="max_backups" name="max_backups" value="{!! (int) $this->maxBackups !!}" min="1" max="20"
                               class="form-control">
                        <p class="form-text">Number of backup files to keep</p>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Select Log Files to Rotate:</label>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:3rem;">
                                        <input type="checkbox" id="selectAll" class="form-check-input">
                                    </th>
                                    <th>File Name</th>
                                    <th>Size</th>
                                    <th>Last Modified</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->stats as $stat)
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="files[]" value="{{ $stat['name'] }}"
                                                   {!! in_array($stat['name'], $this->selectedFiles) ? 'checked' : '' !!}
                                                   class="form-check-input">
                                        </td>
                                        <td>{{ $stat['name'] }}</td>
                                        <td>{{ $stat['size_formatted'] }}</td>
                                        <td>{{ $stat['modified_formatted'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <input type="hidden" name="action" value="rotate">
                <button type="submit" class="btn btn-primary">
                    Rotate Selected Log Files
                </button>
            </form>

            <script>
                document.getElementById('selectAll').addEventListener('change', function () {
                    document.querySelectorAll('input[name="files[]"]').forEach(function (cb) {
                        cb.checked = this.checked;
                    }, this);
                });
            </script>
        </div>
    </div>
</div>
