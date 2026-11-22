@extends('layouts.app')
@script('js', 'api-client.js')
<script src="/dashboard/service-assets/sales-service/sales-checkout.js" defer></script>
@section('content')
<x-sales-service-order-confirmation :view-data="$sales_confirmation_data" />
@endsection
