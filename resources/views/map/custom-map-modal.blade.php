<div class="modal fade" id="mapModal" role="dialog" aria-labelledby="mapModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mapModalLabel">{{ __('map.custom.edit.map.settings_title') }}</h5>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-12">
                        <div class="well well-lg">
                            <input type="hidden" id="mapid" name="mapid" />
                            <div class="form-group row">
                                <label for="mapname" class="col-sm-3 control-label">{{ __('map.custom.edit.map.name') }}</label>
                                <div class="col-sm-9">
                                    <input type="text" id="mapname" name="mapname" class="form-control input-sm" value="{{ $name ?? '' }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="mapmenugroup" class="col-sm-3 control-label">{{ __('map.custom.edit.map.menu_group') }}</label>
                                <div class="col-sm-9">
                                    <select id="mapmenugroup" name="mapmenugroup" class="form-control input-sm"></select>
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="mapwidth" class="col-sm-3 control-label">{{ __('map.custom.edit.map.width') }}</label>
                                <div class="col-sm-9">
                                    <input type="text" id="mapwidth" name="mapwidth" class="form-control input-sm" value="{{ $map_conf['width'] ?? '1800px' }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="mapheight" class="col-sm-3 control-label">{{ __('map.custom.edit.map.height') }}</label>
                                <div class="col-sm-9">
                                    <input type="text" id="mapheight" name="mapheight" class="form-control input-sm" value="{{ $map_conf['height'] ?? '800px' }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="mapnodealign" class="col-sm-3 control-label">{{ __('map.custom.edit.map.alignment') }}</label>
                                <div class="col-sm-9">
                                    <input type="number" id="mapnodealign" name="mapnodealign" class="form-control input-sm" value="{{ $node_align ?? 10 }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="mapedgesep" class="col-sm-3 control-label">{{ __('map.custom.edit.map.edgeseparation') }}</label>
                                <div class="col-sm-9">
                                    <input type="number" id="mapedgesep" name="mapedgesep" class="form-control input-sm" value="{{ $edge_separation ?? 10 }}">
                                </div>
                            </div>
                            <div class="form-group row">
                                <label for="mapreversearrows" class="col-sm-3 control-label">{{ __('map.custom.edit.map.reverse') }}</label>
                                <div class="col-sm-9">
                                    <input class="form-check-input" type="checkbox" role="switch" id="mapreversearrows">
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-sm-12" id="savemap-alert">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <center>
                    <button type=button value="save" id="map-saveButton" class="btn btn-primary" onclick="saveMapSettings()">{{ __('Save') }}</button>
                    <button type=button value="cancel" id="map-cancelButton" class="btn btn-primary" onclick="editMapCancel()">{{ __('Cancel') }}</button>
                </center>
            </div>
        </div>
    </div>
</div>

<script>
    var node_align = {{$node_align}};
    var edge_sep = {{$edge_separation}};
    var reverse_arrows = {{$reverse_arrows}};

    function saveMapSettings() {
        $("#map-saveButton").attr('disabled','disabled');
        $("#savemap-alert").text('{{ __('map.custom.edit.map.saving') }}');
        $("#savemap-alert").attr("class", "col-sm-12 alert alert-info");

        var name = $("#mapname").val();
        var group = $("#mapmenugroup").val();
        var width = $("#mapwidth").val();
        var height = $("#mapheight").val();
        var node_align = $("#mapnodealign").val();

        var map_reverse_arrows = $("#mapreversearrows").prop('checked') ? 1 : 0;
        var map_edge_sep = $("#mapedgesep").val();

        if(!isNaN(width)) {
            width = width + "px";
        }
        if(!isNaN(height)) {
            height = height + "px";
        }

        @if(isset($map_id))
            var url = '{{ route('maps.custom.update', ['map' => $map_id]) }}';
            var method = 'PUT';
        @else
            var url = '{{ route('maps.custom.store') }}';
            var method = 'POST';
        @endif

        $.ajax({
            url: url,
            data: {
                name: name,
                menu_group: group,
                width: width,
                height: height,
                node_align: node_align,
                reverse_arrows: map_reverse_arrows,
                edge_separation: map_edge_sep,
            },
            dataType: 'json',
            type: method
        }).done(function (data, status, resp) {
            editMapSuccess(data);
        }).fail(function (resp, status, error) {
            var data = resp.responseJSON;
            if (data['message']) {
                let alert_content = $("#savemap-alert");
                alert_content.text(data['message']);
                alert_content.attr("class", "col-sm-12 alert alert-danger");
            } else {
                let alert_content = $("#savemap-alert");
                alert_content.text('{{ __('map.custom.edit.map.save_error', ['code' => '?']) }}'.replace('?', resp.status));
                alert_content.attr("class", "col-sm-12 alert alert-danger");
            }
        }).always(function (resp, status, error) {
            $("#map-saveButton").removeAttr('disabled');
        });
    }

    $(document).ready(function () {
        $("#mapreversearrows").bootstrapSwitch('state', Boolean(reverse_arrows));
        init_select2("#mapmenugroup", "custom-map-menu-group", {}, @json($menu_group ?? null), "{{ __('map.custom.edit.map.no_group') }}", {
            tags: true,
            createTag: function (params) {
                var term = $.trim(params.term);

                if (term === '') {
                    return null;
                }

                return {
                    id: term,
                    text: term
                };
            }
        });
    });
</script>
