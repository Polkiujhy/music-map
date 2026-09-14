@extends('layouts.app')

@section('title', 'Status eksportu')

@section('content')
    <livewire:export-operation-panel :export-operation="$exportOperation" />
@endsection
