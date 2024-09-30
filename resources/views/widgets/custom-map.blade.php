<style>
    #map-container-{{ $id }} {
        display: grid;
        grid-template: 1fr / 1fr;
        place-items: center;
    }
    #custom-map-{{ $id }} {
        grid-column: 1 / 1;
        grid-row: 1 / 1;
        z-index: 2;
    }
    #custom-map-bg-geo-map-{{ $id }} {
        grid-column: 1 / 1;
        grid-row: 1 / 1;
        z-index: 1;
    }
</style>

<div id="map-container-{{ $id }}" style="width: 100%; height: 100%">
  <div id="custom-map-{{ $id }}" style="width: 99%; height: 99%"></div>
  <x-geo-map id="custom-map-bg-geo-map-{{ $id }}"
    :init="$map->background_type == 'map'"
    :config="$background_config"
    width="99%"
    height="99%"
    readonly
  />
</div>

<script type="application/javascript">
    (function () {
        var bgtype = {{ Js::from($map->background_type) }};
        var bgdata = {{ Js::from($background_config) }};
        var custom_image_base = "{{ $base_url }}images/custommap/icons/";
        var reverse_arrows = {{$map->reverse_arrows}};
        var legend = @json($map->legend);
        var network_height = Math.floor($("#custom-map-{{ $id }}").height());
        var network_width = Math.floor($("#custom-map-{{ $id }}").width());
        var network_nodes = new vis.DataSet({queue: {delay: 100}});
        var network_edges = new vis.DataSet({queue: {delay: 100}});
        var node_device_map = {};
        var edge_port_map = {};
        var node_link_map = {};

        var network_options = {{ Js::from($map_conf) }};

        var scale = {{ $scale }}

        $.get( '{{ route('maps.custom.data', ['map' => $map->custom_map_id]) }}')
            .done(function( data ) {
                // Add/update nodes
                $.each( data.nodes, function( nodeid, node) {
                    var node_cfg = custommapGetNodeCfg(nodeid, node, scale, false);
                    if(node.device_id) {
                        node_device_map[nodeid] = {device_id: node.device_id, device_name: node.device_name};
                    } else if(node.linked_map_name) {
                        node_link_map[nodeid] = node.linked_map_id;
                    }
                    network_nodes.add([node_cfg]);
                });

                $.each( data.edges, function( edgeid, edge) {
                    var mid = custommapGetEdgeMidCfg(edgeid, edge, scale, false);
                    var edge1 = custommapGetEdgeCfg(edgeid, edge, scale, "from", reverse_arrows);
                    var edge2 = custommapGetEdgeCfg(edgeid, edge, scale, "to", reverse_arrows);

                    if(edge.port_id) {
                        edge_port_map[edgeid] = {device_id: edge.device_id, port_id: edge.port_id};
                    }
                    network_nodes.add([mid]);
                    network_edges.add([edge1, edge2]);
                });

                custommapRedrawDefaultLegend(network_nodes, scale, {{ $map->legend_steps }}, {{ $map->legend_x }}, {{ $map->legend_y }}, {{ $map->legend_font_size }}, {{ $map->legend_hide_invalid }}, {{ $map->legend_hide_overspeed }});

                // Flush in order to make sure nodes exist for edges to connect to
                network_nodes.flush();
                network_edges.flush();

                var container = document.getElementById('custom-map-{{ $id }}');
                network = new vis.Network(container, {nodes: network_nodes, edges: network_edges, stabilize: true}, network_options);

                // width/height might be % get values in pixels
                var centreY = Math.round(network_height / 2);
                var centreX = Math.round(network_width / 2);
                network.moveTo({position: {x: centreX, y: centreY}, scale: 1});

                setCustomMapBackground('custom-map-{{ $id }}', bgtype, bgdata);

                network.on('doubleClick', function (properties) {
                    edge_id = null;
                    if (properties.nodes.length > 0) {
                        if(properties.nodes[0] in node_device_map) {
                            window.location.href = "device/"+node_device_map[properties.nodes[0]].device_id;
                        } else if (properties.nodes[0] in node_link_map) {
                            window.location.href = '{{ route('maps.custom.show', ['map' => '?']) }}'.replace('?', node_link_map[properties.nodes[0]]);
                        } else if (properties.nodes[0].endsWith('_mid')) {
                            edge_id = properties.nodes[0].split("_")[0];
                        }
                    } else if (properties.edges.length > 0) {
                        edge_id = properties.edges[0].split("_")[0];
                    }

                    if (edge_id && (edge_id in edge_port_map)) {
                       window.location.href = 'device/device=' + edge_port_map[edge_id].device_id + '/tab=port/port=' + edge_port_map[edge_id].port_id + '/';
                    }
                });
        });
    })();
</script>
