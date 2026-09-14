@extends('layouts.app')

@section('title', 'Przegląd eksportu')

@section('content')
    <livewire:export-review-panel :export-review="$exportReview" />
@endsection
