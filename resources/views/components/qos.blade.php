<div class='col-md-6'><ul class='mktree' id='ingress'>
    <div><strong><i class="fa fa-sign-in"></i>&nbsp;Ingress Policy:</strong></div>
@php $qosIngress = $qosItems->where('ingress', '1') @endphp
@if($qosIngress->count() == 0)
    <div><i>No Policies</i></div>
@else
    <x-qos-tree :qosItems="$qosIngress" :parentPortId="$portId" :show="$show" />
@endif
    </div>
</div>

<div class='col-md-6'><ul class='mktree' id='ingress'>
    <div><strong><i class="fa fa-sign-out"></i>&nbsp;Egress Policy:</strong></div>
@php $qosEgress = $qosItems->where('egress', '1') @endphp
@if($qosEgress->count() == 0)
    <div><i>No Policies</i></div>
@else
    <x-qos-tree :qosItems="$qosEgress" :parentPortId="$portId" :show="$show" />
@endif
    </div>
</div>

@if($qosGraph)
@php
if ($qosGraph->type == 'routeros_tree') {
    $graphs = ['_traffic' => 'Transmitted', '_drop' => 'Dropped'];
} elseif ($qosGraph->type == 'routeros_simple') {
    $graphs = ['_traffic' => 'Transmitted', '_drop' => 'Dropped'];
} else {
    $graphs = [];
}
@endphp
<div class="panel panel-default" style="margin-left: 5;">
    <div class="panel-heading">
      <h3 class="panel-title">{{ $qosGraph->title }}</h3>
    </div>
@if(count($graphs) == 0)
    Graphs for type {{ $qosGraph->type }} need to be defined in resources/views/components/qos.blade.php
@endif
@foreach($graphs as $graphSuffix => $graphTitle)
    <x-graph-row loading="lazy" :device="$portId ? null : $qosGraph->device_id" :port="$portId" :type="$typePrefix . $qosGraph->type . $graphSuffix" :title="__($graphTitle)" :columns=4 :graphs="[['from' => '-1d'], ['from' => '-1week'], ['from' => '-1month'], ['from' => '-1y']]" :vars="['rrd_id' => $qosGraph->rrd_id]"></x-graph-row>
@endforeach
@if(false)
    <x-qos :qosItems="$qosItems" :parentId="$qosGraph->id" :portId="$portId" />
@endif
</div>
@endif
