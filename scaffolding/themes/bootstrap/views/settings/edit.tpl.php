{{-- Setting create/edit form (Bootstrap theme).

Variables:
  $this->key    — setting key (string, empty when new)
  $this->value  — current value
  $this->isNew  — bool
  $this->error  — string error message --}}
<div class="container py-4" style="max-width:640px">
    <h2 class="mb-4">{!! $this->isNew ? 'New Setting' : 'Edit Setting' !!}</h2>
    @if (!empty($this->error))
        <div role="alert" class="alert alert-danger">{{ $this->error }}</div>
    @endif
    <div class="card">
        <div class="card-body">
            <form method="post" action="{!! adminUrl('settings/save') !!}">
                {!! \Pramnos\Http\Session::getInstance()->getTokenField() !!}
                @if (!$this->isNew)
                    <input type="hidden" name="original_key" value="{{ $this->key ?? '' }}">
                @endif
                <div class="mb-3">
                    <label class="form-label">Key</label>
                    <input type="text" name="key" class="form-control" required
                        value="{{ $this->key ?? '' }}"
                        {!! !$this->isNew ? 'readonly' : '' !!}>
                </div>
                <div class="mb-3">
                    <label class="form-label">Value</label>
                    <textarea name="value" class="form-control" rows="4">{{ $this->value ?? '' }}</textarea>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="{!! adminUrl('settings/list') !!}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
