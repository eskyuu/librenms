<?php
/**
 * MapDataController.php
 *
 * Controller for getting data for maps
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2019 Thomas Berberich
 * @author     Thomas Berberich <sourcehhdoctor@gmail.com>
 * @author     Steven Wilton <swilton@fluentit.com.au>
 */

namespace App\Http\Controllers\Maps;

use App\Http\Controllers\Controller;
use App\Models\AlertSchedule;
use App\Models\Device;
use App\Models\Link;
use App\Models\Port;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LibreNMS\Config;
use LibreNMS\Util\Url;

class MapDataController extends Controller
{
    protected static function geoLinks($request)
    {
        $user = $request->user();

        // Return a blank array for unknown link types
        if ($request->link_type != 'xdp') {
            return [];
        }

        // Device links are not in the schema yet, so we need to do a table query
        $linkQuery = Link::with('port', 'device', 'remoteDevice', 'device.location', 'remoteDevice.location')
            ->whereHas('device', function (Builder $q) use ($user) {
                $q->whereIn('status', [0, 1])
                    ->where('disabled', 0)
                    ->where('ignore', 0);

                if (! $user->hasGlobalRead()) {
                    $q->whereIntegerInRaw('device_id', \Permissions::devicesForUser($user));
                }
            })
            ->whereHas('device.location', function (Builder $q) {
                $q->whereNotNull('lat')
                    ->whereNotNull('lng');
            })
            ->whereHas('remoteDevice', function (Builder $q) use ($user) {
                $q->whereIn('status', [0, 1])
                    ->where('disabled', 0)
                    ->where('ignore', 0);

                if (! $user->hasGlobalRead()) {
                    $q->whereIntegerInRaw('device_id', \Permissions::devicesForUser($user));
                }
            })
            ->whereHas('remoteDevice.location', function (Builder $q) {
                $q->whereNotNull('lat')
                    ->whereNotNull('lng');
            })
            ->whereHas('port', function (Builder $q) {
                $q->where('ifOperStatus', 'up');
            });

        $group_id = $request->group;
        if ($group_id) {
            $linkQuery->join('device_group_device AS ldg', 'ld.device_id', '=', 'ldg.device_id')
                ->join('device_group_device AS rdg', 'rd.device_id', '=', 'rdg.device_id')
                ->where('rdg.device_group_id', '=', $group_id)
                ->where('ldg.device_group_id', '=', $group_id);
        }

        return $linkQuery->get()
            ->groupBy(function (Link $i) {
                return $i->device->location->lat . '.' . $i->device->location->lng . '.' . $i->remoteDevice->location->lat . '.' . $i->remoteDevice->location->lng;
            });
    }

    protected static function deviceMacLinks($request)
    {
        $disabled = $request->disabled;
        $disabled_alerts = $request->disabled_alerts;

        $linkQuery = Port::hasAccess($request->user())
            ->select(\DB::raw('ports.*'),
                \DB::raw('rd.device_id AS remote_device_id'),
                \DB::raw('rp.port_id AS remote_port_id'),
                \DB::raw('rp.ifName AS remote_port_name'),
                \DB::raw('ld.status AS local_device_status'),
                \DB::raw('rd.status AS remote_device_status'),
                \DB::raw('rp.ifOperStatus AS remote_ifOperStatus'),
                \DB::raw('rip.ipv4_address_id'),
            )
            ->join('ipv4_mac as m', 'ports.port_id', '=', 'm.port_id')
            ->join('ports as rp', 'm.mac_address', '=', 'rp.ifPhysAddress')
            ->join('devices as ld', 'ports.device_id', '=', 'ld.device_id')
            ->join('devices as rd', 'rp.device_id', '=', 'rd.device_id')
            // Outer join to the IPv4 address table on the remote device
            // to see if we can find an IP matching the ARP entry on the local device
            // We use the orderBy caluse below to make these entries appear at the
            // top of the list, causing them to be used first
            ->leftJoin('ipv4_addresses as rip', function ($j) {
                $j->on('rp.port_id', '=', 'rip.port_id')
                    ->on('rip.ipv4_address', '=', 'm.ipv4_address');
            })
            ->whereNotIn('m.mac_address', ['000000000000', 'ffffffffffff'])
            ->whereColumn('ld.device_id', '<>', 'rd.device_id')
            ->orderBy('ipv4_address_id', 'DESC')
            ->orderBy('ifName')
            ->orderBy('remote_port_name');

        if (! \Auth::user()->hasGlobalRead()) {
            $linkQuery->whereIntegerInRaw('ld.device_id', \Permissions::devicesForUser($request->user()))
                ->whereIntegerInRaw('rd.device_id', \Permissions::devicesForUser($request->user()));
        }

        if (! is_null($disabled)) {
            if ($disabled) {
                $linkQuery->where('rd.disabled', '<>', '0')
                    ->where('ld.disabled', '<>', '0');
            } else {
                $linkQuery->where('rd.disabled', '=', '0')
                    ->where('ld.disabled', '=', '0');
            }
        }

        if (! is_null($disabled_alerts)) {
            if ($disabled_alerts) {
                $linkQuery->where('rd.disable_notify', '<>', '0')
                    ->where('ld.disable_notify', '<>', '0');
            } else {
                $linkQuery->where('rd.disable_notify', '=', '0')
                    ->where('ld.disable_notify', '=', '0');
            }
        }

        $group_id = $request->group;
        if ($group_id) {
            $linkQuery->join('device_group_device AS ldg', 'ld.device_id', '=', 'ldg.device_id')
                ->join('device_group_device AS rdg', 'rd.device_id', '=', 'rdg.device_id')
                ->where('rdg.device_group_id', '=', $group_id)
                ->where('ldg.device_group_id', '=', $group_id);
        }

        $device_id = $request->device;
        if ($device_id) {
            $linkQuery->where(function (Builder $q) use ($device_id) {
                $q->where('ld.device_id', '=', $device_id)
                      ->orWhere('rd.device_id', '=', $device_id);
            });
        }

        // Return as a cursor to avoid excessive memory use
        return $linkQuery->cursor();
    }

    protected static function deviceXdpLinks($request)
    {
        $disabled = $request->disabled;
        $disabled_alerts = $request->disabled_alerts;

        $linkQuery = Port::hasAccess($request->user())
            ->select(\DB::raw('ports.*'),
                \DB::raw('rd.device_id AS remote_device_id'),
                \DB::raw('rp.port_id AS remote_port_id'),
                \DB::raw('rp.ifName AS remote_port_name'),
                \DB::raw('ld.status AS local_device_status'),
                \DB::raw('rd.status AS remote_device_status'),
                \DB::raw('rp.ifOperStatus AS remote_ifOperStatus'),
            )
            ->join('links as l', 'ports.port_id', '=', 'l.local_port_id')
            ->join('devices as rd', 'l.remote_device_id', '=', 'rd.device_id')
            ->join('devices as ld', 'ports.device_id', '=', 'ld.device_id')
            ->join('ports as rp', 'l.remote_port_id', '=', 'rp.port_id')
            ->where('l.active', '<>', 0)
            ->orderBy('ports.ifName')
            ->orderBy('rp.ifName');

        if (! \Auth::user()->hasGlobalRead()) {
            $linkQuery->whereIntegerInRaw('l.local_device_id', \Permissions::devicesForUser($request->user()))
                ->whereIntegerInRaw('l.remote_device_id', \Permissions::devicesForUser($request->user()));
        }

        if (! is_null($disabled)) {
            if ($disabled) {
                $linkQuery->where('rd.disabled', '<>', '0')
                    ->where('ld.disabled', '<>', '0');
            } else {
                $linkQuery->where('rd.disabled', '=', '0')
                    ->where('ld.disabled', '=', '0');
            }
        }

        if (! is_null($disabled_alerts)) {
            if ($disabled_alerts) {
                $linkQuery->where('rd.disable_notify', '<>', '0')
                    ->where('ld.disable_notify', '<>', '0');
            } else {
                $linkQuery->where('rd.disable_notify', '=', '0')
                    ->where('ld.disable_notify', '=', '0');
            }
        }

        $device_id = $request->device;
        if ($device_id) {
            $linkQuery->where(function (Builder $q) use ($device_id) {
                $q->where('ld.device_id', '=', $device_id)
                      ->orWhere('rd.device_id', '=', $device_id);
            });
        }

        $group_id = $request->group;
        if ($group_id) {
            $linkQuery->join('device_group_device AS ldg', 'l.local_device_id', '=', 'ldg.device_id')
                ->join('device_group_device AS rdg', 'l.remote_device_id', '=', 'rdg.device_id')
                ->where('rdg.device_group_id', '=', $group_id)
                ->where('ldg.device_group_id', '=', $group_id);
        }

        // Return as a cursor to avoid excessive memory use
        return $linkQuery->cursor();
    }

    protected static function deviceList($request)
    {
        $group_id = $request->group;
        $devices = $request->devices;
        $valid_loc = $request->location_valid;
        $disabled = $request->disabled;
        $ignore = $request->ignore;
        $disabled_alerts = $request->disabled_alerts;
        $linkType = $request->link_type;
        $statuses = $request->statuses;

        $deviceQuery = Device::hasAccess($request->user())->with('location');

        if ($group_id) {
            $deviceQuery->inDeviceGroup($group_id);
        }

        if ($devices) {
            $deviceQuery->whereIntegerInRaw('device_id', $devices);
        }

        if ($statuses && count($statuses) > 0) {
            $deviceQuery->whereIn('status', $statuses);
        }

        if (! is_null($disabled)) {
            if ($disabled) {
                $deviceQuery->where('disabled', '<>', '0');
            } else {
                $deviceQuery->where('disabled', '=', '0');
            }
        }

        if (! is_null($ignore)) {
            if ($ignore) {
                $deviceQuery->where('ignore', '<>', '0');
            } else {
                $deviceQuery->where('ignore', '=', '0');
            }
        }

        if (! is_null($disabled_alerts)) {
            if ($disabled_alerts) {
                $deviceQuery->where('disable_notify', '<>', '0');
            } else {
                $deviceQuery->where('disable_notify', '=', '0');
            }
        }

        if ($valid_loc) {
            $deviceQuery->whereHas('location', function ($query) {
                $query->whereNotNull('lng')
                    ->whereNotNull('lat')
                    ->where('lng', '<>', '')
                    ->where('lat', '<>', '');
            });
        }

        if (! $group_id) {
            if ($linkType == 'depends') {
                return $deviceQuery->with('parents')->get();
            } else {
                return $deviceQuery->get();
            }
        }

        if ($linkType == 'depends') {
            $devices = $deviceQuery->with([
                'parents' => function ($query) use ($request) {
                    $query->hasAccess($request->user());
                },
                'children' => function ($query) use ($request) {
                    $query->hasAccess($request->user());
                }, ])
            ->get();

            return $devices->merge($devices->map->only('children', 'parents')->flatten())->loadMissing('parents', 'location');
        } else {
            return $deviceQuery->get();
        }
    }

    protected function linkSpeedWidth($speed)
    {
        $speed /= 1000000000;
        if ($speed > 500000) {
            return 20;
        }
        if (is_nan($speed)) {
            return 1;
        }
        if ($speed < 10) {
            return 1;
        }
        return round(0.77 * pow($speed, 0.25));
    }

    protected function linkUseColour($link_pct)
    {
        $link_pct = round(2 * $link_pct, -1) / 2;
        if ($link_pct > 100) {
            $link_used = 100;
        }
        if (is_nan($link_pct)) {
            $link_used = 0;
        }
        return Config::get("network_map_legend.$link_pct");
    }

    protected function nodeDisabledStyle()
    {
        return [
            'color' => [
                'highlight' => [
                    'background' => Config::get('network_map_legend.di.node'),
                ],
                'border' => Config::get('network_map_legend.di.border'),
                'background' => Config::get('network_map_legend.di.node'),
            ],
            'borderWidth' => null,
        ];
    }

    protected function nodeHighlightStyle()
    {
        return [
            'color' => [
                'highlight' => [
                    'border' => Config::get('network_map_legend.highlight.border'),
                ],
                'border' => Config::get('network_map_legend.highlight.border'),
            ],
            'borderWidth' => Config::get('network_map_legend.highlight.borderWidth'),
        ];
    }

    protected function nodeDownStyle()
    {
        return [
            'color' => [
                'highlight' => [
                    'background' => Config::get('network_map_legend.dn.node'),
                    'border' => Config::get('network_map_legend.dn.border'),
                ],
                'border' => Config::get('network_map_legend.dn.border'),
                'background' => Config::get('network_map_legend.dn.node'),
            ],
            'borderWidth' => null,
        ];
    }

    protected function nodeUpStyle()
    {
        return [
            'color' => null,
            'border' => null,
            'background' => null,
            'borderWidth' => null,
        ];
    }

    protected function deviceStyle($device, $highlight_node = 0)
    {
        if ($device->disabled) {
            $device_style = $this->nodeDisabledStyle();
        } elseif (! $device->status) {
            $device_style = $this->nodeDownStyle();
        } else {
            $device_style = $this->nodeUpStyle();
        }

        if ($device->device_id == $highlight_node) {
            $device_style = array_merge($device_style, $this->nodeHighlightStyle());
        }

        return $device_style;
    }

    // GET Device
    public function getDevices(Request $request)
    {
        // Get all devices under maintenance
        $maintdevices = AlertSchedule::isActive()
            ->with('devices', 'locations.devices', 'deviceGroups.devices')
            ->get()
            ->map->only('devices', 'locations.devices', 'deviceGroups.devices')
            ->flatten();

        // Create a hash of device IDs covered by maintenance to avoid a DB call per device below
        $maintdevicesmap = [];
        foreach ($maintdevices as $device) {
            if ($device) {
                $maintdevicesmap[$device->device_id] = true;
            }
        }

        // For manual level we need to track some items
        $next_level_devices = [];
        $device_child_map = [];
        $processed_devices = [];

        // List all devices
        $device_list = [];
        foreach (self::deviceList($request) as $device) {
            if ($device->status) {
                $updowntime = \LibreNMS\Util\Time::formatInterval($device->uptime);
            } elseif ($device->last_polled) {
                $updowntime = \LibreNMS\Util\Time::formatInterval(time() - strtotime($device->last_polled));
            } else {
                $updowntime = '';
            }

            foreach ($device->parents as $parent) {
                // Keep track of all children for a given device ID
                if (! array_key_exists($parent->device_id, $device_child_map)) {
                    $device_child_map[$parent->device_id] = [];
                }
                $device_child_map[$parent->device_id][$device->device_id] = true;
            }
            if (! count($device->parents)) {
                // This is a top level device
                $next_level_devices[$device->device_id] = true;
            }

            $device_list[$device->device_id] = [
                'id'          => $device->device_id,
                'icon'        => $device->icon,
                'icontitle'   => $device->icon ? str_replace(['.svg', '.png'], '', basename($device->icon)) : $device->os,
                'sname'       => $device->shortDisplayName(),
                'status'      => $device->status,
                'uptime'      => $device->uptime,
                'updowntime'  => $updowntime,
                'last_polled' => $device->last_polled,
                'disabled'    => $device->disabled,
                'no_alerts'   => $device->disable_notify,
                'url'         => $request->url_type == 'links' ? Url::deviceLink($device, null, [], 0, 0, 0, 0) : Url::deviceUrl($device->device_id),
                'style'       => self::deviceStyle($device, $request->highlight_node),
                'lat'         => $device->location ? $device->location->lat : null,
                'lng'         => $device->location ? $device->location->lng : null,
                'parents'     => ($request->link_type == 'depends') ? $device->parents->map->only('device_id')->flatten() : [],
                'maintenance' => array_key_exists($device->device_id, $maintdevicesmap) ? 1 : 0,
            ];
        }

        // Add levels to each device
        $this_level = 0;
        while (count($next_level_devices)) {
            $this_level_devices = $next_level_devices;
            $next_level_devices = [];

            foreach (array_keys($this_level_devices) as $device_id) {
                // Highlight isolated devices if needed
                if ($request->highlight_node == -1 && ! array_key_exists($device_id, $device_child_map) && count($device_list[$device_id]['parents']) == 0) {
                    $device_list[$device_id]['style'] = array_merge($device_list[$device_id]['style'], $this->nodeHighlightStyle());
                }

                // Ignore if the device has already been processed
                if (array_key_exists($device_id, $processed_devices)) {
                    continue;
                }

                // Set device level and mark as processed
                $device_list[$device_id]['level'] = $this_level;
                $processed_devices[$device_id] = true;

                // Add any child devices to be processed next
                if (array_key_exists($device_id, $device_child_map)) {
                    $next_level_devices = $next_level_devices + $device_child_map[$device_id];
                }
            }
            $this_level++;
        }

        // If any device does not have a level it is linked to missing parents, so set to level 0
        foreach (array_keys($device_list) as $device_id) {
            if (! array_key_exists('level', $device_list[$device_id])) {
                $device_list[$device_id]['level'] = 0;
            }
        }

        // Highlight all parents if required
        if ($request->showpath && $request->highlight_node > 0) {
            $processed_parents = [];
            $this_parents = $device_list[$request->highlight_node]['parents'];

            while (count($this_parents) > 0) {
                $next_parents = [];
                foreach ($this_parents as $parent_id) {
                    if (array_key_exists($parent_id, $processed_parents)) {
                        continue;
                    }
                    $processed_parents[$parent_id] = true;

                    $device_list[$parent_id]['style'] = array_merge($device_list[$parent_id]['style'], $this->nodeHighlightStyle());
                    $next_parents = array_merge($next_parents, $device_list[$parent_id]['parents']->toArray());
                }
                $this_parents = $next_parents;
            }
        }

        return response()->json($device_list);
    }

    protected function addDeviceLinks($query, &$link_list, &$device_assoc_seen)
    {
        foreach ($query as $port) {
            // We have additional attributes, so we needs a copy to avoid PHPStan errors
            $row = json_decode(json_encode($port));

            $device_ids_1 = $port->device_id . '.' . $row->remote_device_id;
            $device_ids_2 = $row->remote_device_id . '.' . $port->device_id;

            // Ignore any associations that have already been processed
            if (array_key_exists($device_ids_1, $device_assoc_seen)
                || array_key_exists($device_ids_2, $device_assoc_seen)) {
                continue;
            }
            $device_assoc_seen[$device_ids_1] = true;
            $device_assoc_seen[$device_ids_2] = true;

            $width = $this->linkSpeedWidth($port->ifSpeed);

            if ($row->local_device_status == 0 && $row->remote_device_status == 0) {
                // If both devices are offline, mark the link as being down
                $link_style = [
                    'dashes' => [8, 12],
                    'width' => $width,
                    'color' => [
                        'border' => Config::get('network_map_legend.dn.border'),
                        'highlight' => Config::get('network_map_legend.dn.edge'),
                        'color' => Config::get('network_map_legend.dn.edge'),
                    ],
                ];
            } elseif ($port->ifOperStatus == 'down' || $row->remote_ifOperStatus == 'down') {
                // If either port is offline, mark the link as being down
                $link_style = [
                    'dashes' => [8, 12],
                    'width' => $width,
                    'color' => [
                        'border' => Config::get('network_map_legend.dn.border'),
                        'highlight' => Config::get('network_map_legend.dn.edge'),
                        'color' => Config::get('network_map_legend.dn.edge'),
                    ],
                ];
            } else {
                if ($port->ifSpeed > 0) {
                    $link_in_usage_pct = $port->ifInOctets_rate * 8 / $port->ifSpeed * 100;
                    $link_out_usage_pct = $port->ifOutOctets_rate * 8 / $port->ifSpeed * 100;
                    $link_used = max($link_out_usage_pct, $link_in_usage_pct);
                } else {
                    $link_used = 0;
                }
                $link_color = $this->linkUseColour($link_used);
                $link_style = [
                    'width' => $width,
                    'color' => [
                        'border' => $link_color,
                        'highlight' => $link_color,
                        'color' => $link_color,
                    ],
                ];
            }

            $link_list[$port->port_id . '.' . $row->remote_port_id] = [
                'ldev'       => $port->device_id,
                'rdev'       => $row->remote_device_id,
                'ifnames'    => $port->ifName . ' <> ' . $row->remote_port_name,
                'url'        => Url::portLink($port, null, null, false, true),
                'style'      => $link_style,
            ];
        }
    }

    // GET Device Links by device
    public function getDeviceLinks(Request $request)
    {
        // List all links
        $link_list = [];
        $device_assoc_seen = [];
        $link_types = $request->link_types;

        foreach ($link_types as $link_type) {
            if ($link_type == 'mac') {
                self::addDeviceLinks(self::deviceMacLinks($request), $link_list, $device_assoc_seen);
            } elseif ($link_type == 'xdp') {
                self::addDeviceLinks(self::deviceXdpLinks($request), $link_list, $device_assoc_seen);
            }
        }

        return response()->json($link_list);
    }

    // GET Device Links grouped by geographic locations
    public function getGeographicLinks(Request $request)
    {
        // List all links
        $link_list = [];
        foreach (self::geoLinks($request) as $location) {
            $link = $location[0];
            $capacity = $location->sum(function (Link $l) {
                return $l->port->ifSpeed;
            });
            $inRate = $location->sum(function (Link $l) {
                return $l->port->ifInOctets_rate * 8;
            });
            $outRate = $location->sum(function (Link $l) {
                return $l->port->ifOutOctets_rate * 8;
            });
            if ($capacity > 0) {
                $link_used = max($inRate / $capacity * 100, $outRate / $capacity * 100);
            } elseif ($inRate > 0 || $outRate > 0) {
                $link_used = 100;
            } else {
                $link_used = 0;
            }

            $width = $this->linkSpeedWidth($capacity);
            $link_color = $this->linkUseColour($link_used);

            $link_list[$link->device->location_id . '.' . $link->remoteDevice->location_id] = [
                'local_lat'  => $link->device->location->lat,
                'local_lng'  => $link->device->location->lng,
                'remote_lat' => $link->remoteDevice->location->lat,
                'remote_lng' => $link->remoteDevice->location->lng,
                'color'      => $link_color,
                'width'      => $width,
            ];
        }

        return response()->json($link_list);
    }

    // GET Device services
    public function getServices(Request $request)
    {
        $group_id = $request->device_group;
        $services = Service::hasAccess($request->user())->with('device');

        if ($group_id) {
            $services->inDeviceGroup($group_id);
        }

        $service_list = [];
        foreach ($services->get() as $service) {
            if ($service->device->status) {
                $updowntime = \LibreNMS\Util\Time::formatInterval($service->device->uptime);
            } elseif ($service->device->last_polled) {
                $updowntime = \LibreNMS\Util\Time::formatInterval(time() - strtotime($service->device->last_polled));
            } else {
                $updowntime = '';
            }

            $service_list[] = [
                'id'          => $service->service_id,
                'name'        => $service->service_name,
                'type'        => $service->service_type,
                'status'      => $service->service_status,
                'icon'        => $service->device->icon,
                'icontitle'   => $service->device->icon ? str_replace(['.svg', '.png'], '', basename($service->device->icon)) : $service->device->os,
                'device_name' => $service->device->shortDisplayName(),
                'url'         => Url::deviceUrl($service->device_id),
                'updowntime'  => $updowntime,
                'compact'     => Config::get('webui.availability_map_compact'),
                'box_size'    => Config::get('webui.availability_map_box_size'),
            ];
        }

        return response()->json($service_list);
    }
}
