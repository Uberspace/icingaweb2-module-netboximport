<?php

namespace Icinga\Module\Netboximport\ProvidedHook\Director;

use Icinga\Application\Config;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Netboximport\Api;

error_reporting(E_ALL);
ini_set('max_execution_time', 3600);

class ImportSource extends ImportSourceHook
{
    private $api;
    private $resolve_properties = [
        "cluster",
    ];

    // private static function endsWith($haystack, $needle)
    // {
    //     $length = strlen($needle);
    //     return $length === 0 || (substr($haystack, -$length) === $needle);
    // }

    // stolen from https://stackoverflow.com/a/9546235/2486196
    // adapted to also flatten nested stdClass objects
    public function flattenNestedArray($prefix, $array, $delimiter="__")
    {
        // Initialize empty array
        $result = [];

        // Cycle through input array
        foreach ($array as $key => $value) {
            // Element is an object instead of a value
            if (is_object($value)) {
                // Convert value to an associative array of public object properties
                $value = get_object_vars($value);
            }

            // Recursion
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenNestedArray($prefix . $key . $delimiter, $value, $delimiter));
            // no Recursion
            } else {
                $result[$prefix . $key] = $value;
            }
        }

        return $result;
    }

    private function fetchObjects($resource, $activeOnly, $additionalKeysCallback = null)
    {
        $next_url = null;
        $results = [];
        $working_list = [];

        do {
            if ($working_list === null) {
                // first run
                $working_list = $this->api->getResource($resource);
            } else {
                // Pagination
                $working_list = $this->api->getResource($next_url);
            }

            // Grab the next page URL or break the do/while
            $next_url = $working_list->next ?? null;

            // Remove inactive elements from the working list (if requested)
            $working_list = array_filter($working_list, function ($o) use ($activeOnly) {
                if($activeOnly) {
                  if(@$o->status->value === 1 && @$o->name !== null) {
                    // Keep eleemnts that are active and have a name
                    return true;
                  } else {
                    // Delete elements that are inactive or unnamed
                    return false;
                  }
                } else {
                  // Keep all elements
                  return true;
                }

                // if ($activeOnly || @$o->status->value === 1) {
                //     return @$o->name !== null;
                // }
                //
                // return true;
                // return
              //   (!$activeOnly || @$o->status->value === 1)
              //   && @$o->name
              // ;
            });

            // Check each element in the working list
            $working_list = array_map(function ($o) use ($additionalKeysCallback) {
                // For each property that can contain an object reference
                foreach ($this->resolve_properties as $prop) {
                    if (@$o->$prop !== null) {
                        // Pull resource data and associate to this element
                        $o->$prop = $this->api->getResource($o->$prop->url);
                    }
                }

                // Flatten the object data
                $o = $this->flattenNestedArray('', $o);

                // Was a valid callback function passed?
                if (is_callable($additionalKeysCallback)) {
                    // Run the callback on this object's ID to find valid interfaces
                    $keys = $additionalKeysCallback($o['id']);
                    // Merge into current element object
                    $o = array_merge($o, $keys);
                }

                // Filter out keys that end with __id or __url
                $o = array_filter($o, function ($key) {
                  //   return
                  //     !$this->endsWith($key, '__id') &&
                  //     !$this->endsWith($key, '__url')
                  // ;
                  if(preg_match("/__id$/", $key) || preg_match("/__url$/", $key)) {
                    return false;
                  } else {
                    return true;
                  }
                }, ARRAY_FILTER_USE_KEY);

                // return the typecasted object
                return (object) $o;
            }, $working_list);

            $results = array_merge($results, $working_list);
        } while ($next_url === null);

        return $results;
    }

    private function fetchHosts($url, $type, $activeonly)
    {
        $hosts = $this->fetchObjects(
            $url,
            $activeonly,
            function ($id) use ($type) {
                // Return a flattened associative array containing the interfaces associated with $id @ $type
                return $this->flattenNestedArray('', [
                  'interfaces' => $this->interfaces[$type][$id] ?? []
              ]);
            }
        );

        return $hosts;
    }

    private function fetchInterfaces($url)
    {
        $ips = null;
        $next_url = null;
        $owner_id = null;
        $owner_type = null;
        $owners = [
          'device' => [],
          'virtual_machine' => []
        ];

        do {
            // Grab the next URL if it exists
            $next_url = $ips->next ?? null;

            if ($ips === null) { // initial request
                $ips = $this->api->getResource($url);
            } else { // pagenated run
                $ips = $this->api->getResource($next_url);
            }

            // Cycle through the results returned by the API
            foreach ($ips as $ip) {
                // Empty object, move on to next entry
                if (!isset($ip->interface)) {
                    continue;
                }

                // Grab the interface name
                $ifname = strtolower($ip->interface->name);

                // Skip loopback interfaces
                if ($ifname === 'lo') {
                    continue;
                }

                // Loop through the owner types to pull appropriate information
                foreach (array_keys($owners) as $ot) { // device || virtual_machine
                    // ignore empty values
                    if ($ip->interface->$ot === null) {
                        continue;
                    // If the owner contains data
                    } elseif ($ip->interface->$ot) {
                        $owner_type = $ot; // Make note of the owner type
                        $owner_id = $ip->interface->$ot->id; // make note of the owner id
                        break; // break out of owner type foreach loop
                    } else {
                        // how did we get here?!
                        throw new \Exception("Invalid object found in fetchInterfaces(): $ip");
                    }
                }

                // Add the interface to the associative array that lists all interfaces

                // Initialize the record if this is the first time seeing this $owner_id
                $owners[$owner_type][$owner_id] = $owners[$owner_type][$owner_id] ?? [];

                // Initialize the record if this is the first $ifname for this $owner_id
                $owners[$owner_type][$owner_id][$ifname] = $owners[$owner_type][$owner_id][$ifname] ?? [];

                // Add the IP address to the object
                $owners[$owner_type][$owner_id][$ifname].push($ip->address);

                // $owners[$owner_type][$owner_id] = array_merge(
                //     $owners[$owner_type][$owner_id] ?? [],
                //     [
                //         $ifname => array_merge(
                //             $owners[$owner_type][$owner_id][$ifname] ?? [],
                //             array(
                //                 $ip->address
                //             )
                //         )
                //     ]
                // );
            }
        } while ($next_url != null);

        return $owners;
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
        $form->addElement('text', 'baseurl', array(
            'label'       => $form->translate('Base URL'),
            'required'    => true,
            'description' => $form->translate(
                'API url for your instance, e.g. https://netbox.example.com/api'
            )
        ));

        $form->addElement('text', 'apitoken', array(
            'label'       => $form->translate('API-Token'),
            'required'    => true,
            'description' => $form->translate(
                '(readonly) API token. See https://netbox.example.com/user/api-tokens/'
            )
        ));

        $form->addElement('YesNo', 'importdevices', array(
            'label'       => $form->translate('Import devices'),
            'description' => $form->translate('import physical devices (dcim/devices in netbox).'),
        ));

        $form->addElement('YesNo', 'importvirtualmachines', array(
            'label'       => $form->translate('Import virtual machines'),
            'description' => $form->translate('import virtual machines (virtualization/virtual-machines in netbox).'),
        ));

        $form->addElement('YesNo', 'activeonly', array(
            'label'       => $form->translate('Import active objects only'),
            'description' => $form->translate('only load objects with status "active" (as opposed to "planned" or "offline")'),
        ));
    }

    public function fetchData()
    {
        // Shortcut variables
        $baseurl = $this->getSetting('baseurl');
        $apitoken = $this->getSetting('apitoken');
        $activeonly = $this->getSetting('activeonly') === 'y';

        // Create the API object
        $this->api = new Api($baseurl, $apitoken);

        // Fetch interfaces from API
        $this->interfaces = $this->fetchInterfaces('ipam/ip-addresses');

        // Initialize an empty array
        $objects = [];

        // Devices
        if ($this->getSetting('importdevices') === 'y') {
            // Gather object data from dcim/devices
            $objects = $this->fetchHosts('dcim/devices', 'device', $activeonly);
            // $objects[] = $this->fetchHosts('dcim/devices', 'device', $activeonly);
        }

        // Virtual Machines
        if ($this->getSetting('importvirtualmachines') === 'y') {
            // Gather object data from virtualiztion/virtual-machines
            $objects = array_merge($objects, $this->fetchHosts('virtualization/virtual-machines', 'virtual-machine', $activeonly));
            // $objects[] = $this->fetchHosts('virtualization/virtual-machines', 'virtual-machine', $activeonly);
        }

        // return array_merge(...$objects);
        return $objects;
    }

    public function listColumns()
    {
        // return a list of all keys, which appeared in any of the objects
        return array_keys(array_merge(...array_map('get_object_vars', $this->fetchData())));
    }

    public function getName()
    {
        return 'Netbox';
    }
}
