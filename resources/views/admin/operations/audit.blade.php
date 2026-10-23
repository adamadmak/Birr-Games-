@extends('layouts.admin')
@section('title', 'Audit History')
@section('content')
<div class="admin-page-heading"><div><p>Operations</p><h1>Administrator audit history</h1><span>Immutable record of every state-changing administrator request.</span></div><a class="admin-view-site" href="{{ route('admin.operations.index') }}">← Control center</a></div>
<form class="report-toolbar ops-audit-filter" method="GET">
    <input name="search" value="{{ request('search') }}" placeholder="Administrator, action, route, or path">
    <select name="status"><option value="">All response statuses</option>@foreach([200,201,302,400,403,404,422,500] as $status)<option value="{{ $status }}" @selected((string)request('status')===(string)$status)>{{ $status }}</option>@endforeach</select>
    <button type="submit">Filter</button><a href="{{ route('admin.operations.audit') }}">Reset</a>
</form>
<section class="admin-panel report-panel">
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Date</th><th>Administrator</th><th>Action</th><th>Route</th><th>Target</th><th>Status</th><th>IP</th></tr></thead><tbody>
    @forelse($logs as $log)<tr>
        <td>{{ $log->created_at->format('M j, Y H:i:s') }}</td>
        <td><strong>{{ $log->user?->name ?: 'Deleted account' }}</strong><span class="cell-subtitle">#{{ $log->user_id ?: '-' }}</span></td>
        <td><code>{{ $log->method }}</code> {{ $log->action }}</td>
        <td>{{ $log->route_name ?: '-' }}<span class="cell-subtitle">{{ $log->path }}</span></td>
        <td>{{ $log->target_type ?: '-' }} @if($log->target_id)#{{ $log->target_id }}@endif</td>
        <td><span class="status-badge {{ ($log->response_status ?? 500) < 400 ? 'won' : 'order-rejected' }}">{{ $log->response_status ?: '-' }}</span></td>
        <td>{{ $log->ip_address ?: '-' }}</td>
    </tr>@empty<tr><td colspan="7"><div class="admin-empty">No matching audit records.</div></td></tr>@endforelse
    </tbody></table></div>{{ $logs->links() }}
</section>
<style>.ops-audit-filter{display:flex;gap:10px;margin-bottom:18px}.ops-audit-filter input{flex:1;min-width:220px}.ops-audit-filter input,.ops-audit-filter select{border:1px solid var(--line);border-radius:10px;padding:11px 13px;background:var(--surface)}.ops-audit-filter button,.ops-audit-filter a{border:0;border-radius:10px;padding:11px 16px;background:var(--solid);color:#fff;text-decoration:none;font-weight:700}.ops-audit-filter a{background:var(--surface);color:var(--ink);border:1px solid var(--line)}</style>
@endsection
