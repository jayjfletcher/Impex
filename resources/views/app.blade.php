<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Impex</title>
    <link rel="stylesheet" href="{{ asset('vendor/impex/app.css') }}{{ $assetVersion ? '?id='.$assetVersion : '' }}">
    {{-- Encoded in the controller with the hex flags, so a resolved token can
         never close this script tag. --}}
    <script>window.ImpexConfig = {!! $impexConfig !!};</script>
</head>
<body>
    <div id="impex"></div>
    <script src="{{ asset('vendor/impex/app.js') }}{{ $assetVersion ? '?id='.$assetVersion : '' }}" defer></script>
</body>
</html>
