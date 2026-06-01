@extends('layouts.app')

@section('title', 'User Management')
@section('page-title', 'Users')
@section('page-subtitle', 'Manage dashboard users and roles')

@section('page-actions')
<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createUserModal">
    + Add User
</button>
@endsection

@section('content')

<div class="card">
    <div class="card-header">
        <h3 class="card-title">{{ $users->total() }} Users</h3>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm"
                                  style="background: {{ $user->isAdmin() ? '#e63946' : '#206bc4' }}22; color: {{ $user->isAdmin() ? '#e63946' : '#206bc4' }}">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </span>
                            <span class="fw-medium">{{ $user->name }}</span>
                            @if($user->id === auth()->id())
                            <span class="badge bg-secondary-lt">You</span>
                            @endif
                        </div>
                    </td>
                    <td class="text-muted">{{ $user->email }}</td>
                    <td>
                        <form method="POST"
                              action="{{ route('admin.users.update-role', $user) }}"
                              class="d-flex align-items-center gap-2">
                            @csrf
                            @method('PATCH')
                            <select name="role" class="form-select form-select-sm" style="width: auto"
                                    onchange="this.form.submit()"
                                    {{ $user->id === auth()->id() ? 'disabled' : '' }}>
                                @foreach($roles as $role)
                                <option value="{{ $role->name }}"
                                        {{ $user->hasRole($role->name) ? 'selected' : '' }}>
                                    {{ ucfirst($role->name) }}
                                </option>
                                @endforeach
                            </select>
                        </form>
                    </td>
                    <td class="text-muted small">
                        {{ $user->created_at->format('Y-m-d') }}
                    </td>
                    <td>
                        @if($user->id !== auth()->id())
                        <form method="POST"
                              action="{{ route('admin.users.destroy', $user) }}"
                              onsubmit="return confirm('Delete {{ $user->name }}?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-ghost-danger">
                                Delete
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($users->hasPages())
    <div class="card-footer d-flex justify-content-end">
        {{ $users->links() }}
    </div>
    @endif
</div>

{{-- ================================================================
     Create User Modal
================================================================ --}}
<div class="modal modal-blur fade" id="createUserModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Name</label>
                        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name') }}" required>
                        @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Email</label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}" required>
                        @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Password</label>
                        <input type="password" name="password"
                               class="form-control @error('password') is-invalid @enderror" required>
                        @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Role</label>
                        <select name="role" class="form-select @error('role') is-invalid @enderror" required>
                            @foreach($roles as $role)
                            <option value="{{ $role->name }}">{{ ucfirst($role->name) }}</option>
                            @endforeach
                        </select>
                        @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-ghost-secondary me-auto"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
