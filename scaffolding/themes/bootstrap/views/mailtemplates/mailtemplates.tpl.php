@php /**
 * Message templates, grouped by notification (Bootstrap theme).
 *
 * Variables:
 *   $this->groups — [category|type => rows], one group per notification
 *   $this->types  — channel number => label
 *
 * Grouped rather than listed flat: one notification is several rows — same category and
 * type, one per language — and eighty flat rows cannot answer "is the password-reset email
 * translated into Greek", which is what somebody opens this screen to find out.
 */
$groups = is_array($this->groups ?? null) ? $this->groups : [];
$types  = is_array($this->types ?? null) ? $this->types : [];
$e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); @endphp
<div class="container-fluid py-4">
    @php $this->activeNav = 'mailtemplates'; $this->insert('../partials/admin_breadcrumb'); @endphp

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <h2 class="mb-0">Message templates</h2>
        <span class="badge bg-secondary">{!! count($groups) !!}</span>
        <a href="{!! adminUrl('MailTemplates/edit') !!}" class="btn btn-sm btn-primary ms-auto d-inline-flex align-items-center gap-1">
            {!! \Pramnos\Html\Icon::svg('edit') !!} New template
        </a>
    </div>

    @if ($groups === [])
    <div class="card bg-base-100 border border-base-300 shadow-xs p-6 text-sm text-base-content/60">
        No templates yet. A template is the wording of one notification, in one language —
        create one per language of the same category and the framework picks the right one
        by <code>(category, language, type)</code>.
    </div>
    @else
    <div class="grid gap-3">
        @foreach ($groups as $key => $rows)
            @php $first    = (array) ($rows[0] ?? []);
            $category = (string) ($first['category'] ?? '');
            $type     = (int) ($first['type'] ?? 0); @endphp
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center gap-2">
                    <span class="font-mono text-sm">{{ $category }}</span>
                    <span class="badge bg-light text-dark">{{ $types[$type] ?? $type }}</span>
                    <span class="ms-auto text-xs text-base-content/60">
                        {!! count($rows) !!} language{!! count($rows) === 1 ? '' : 's' !!}
                    </span>
                </div>
                <table class="table table-sm text-sm">
                    <thead class="bg-base-100 text-xs uppercase text-base-content/60">
                        <tr><th>Language</th><th>Title</th><th>Subject</th><th></th></tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        @php $row = (array) $row; $id = (int) ($row['templateid'] ?? 0); @endphp
                        <tr>
                            <td class="font-mono text-xs">{{ $row['language'] ?? '' }}</td>
                            <td>
                                <a class="link link-primary" href="{!! adminUrl('MailTemplates/view/') . $id !!}">
                                    {{ $row['title'] ?? '' }}
                                </a>
                            </td>
                            <td class="text-xs text-base-content/70">{{ $row['defaultsubject'] ?? '' }}</td>
                            <td class="text-end whitespace-nowrap">
                                {!! \Pramnos\Html\Icon::link(adminUrl('MailTemplates/view/') . $id, 'view', 'Open this template'); echo \Pramnos\Html\Icon::link(adminUrl('MailTemplates/edit/') . $id, 'edit', 'Edit this template'); echo \Pramnos\Html\Icon::postButton(adminUrl('MailTemplates/delete/') . $id, 'delete', 'Delete this template', ['data-confirm' => 'Delete this template?', 'class' => 'pf-action-danger']) !!}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>
    @endif
</div>
