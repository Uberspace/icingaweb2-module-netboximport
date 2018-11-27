<?php

namespace Icinga\Module\Netboximport\ProvidedHook\Director;

use Icinga\Application\Config;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Netboximport\Api;

class ImportSource extends ImportSourceHook {
    private $api;
    private $resolve_properties = [
        "cluster",
    ];

    private static function endsWith($haystack, $needle) {
        $length = strlen($needle);
        return $length === 0 || (substr($haystack, -$length) === $needle);
    }

    // stolen from https://stackoverflow.com/a/9546235/2486196
    function flattenArray($prefix, $array) {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_object($value))
                $value = get_object_vars($value);

           if (is_array($value)) {
               $result[$key] = $value;
               continue;
           }

          $result[$prefix . $key] = $value;
        }

        return $result;
    }

    private function fetchObjects($ressource, $activeOnly, $additionalKeysCallback = null) {
        $objs = $this->api->g($ressource);

       //Filter only active objects if setting is set
        $objs = array_filter($objs, function ($o) use ($activeOnly) {
            return
              (!$activeOnly || @$o->status->value === 1)
              && @$o->name
            ;
        });


        $objs = array_map(function ($o) use ($additionalKeysCallback) {
           //Resolve additional properties
            foreach ($this->resolve_properties as $prop) {
                if (@$o->$prop !== null) {
                    $o->$prop = $this->api->g($o->$prop->url);
                }
            }

            $o = $this->flattenArray('', $o);

            if(is_callable($additionalKeysCallback)) {
                $keys = $additionalKeysCallback($o['id']);

                $o = array_merge($o, $keys);
            }

/*            $o = array_filter($o, function ($key) {
                return
                    !$this->endsWith($key, '__id') &&
                    !$this->endsWith($key, '__url')
                ;
            }, ARRAY_FILTER_USE_KEY);*/

            return (object) $o;
        }, $objs);

        return $objs;
    }

    private function fetchHosts($url, $type, $activeonly) {
        $hosts = $this->fetchObjects($url, $activeonly, function ($id) use ($type) {

            $children =  $this->flattenArray('', [
                'interfaces' => $this->interfaces[$type][$id] ?? [],
                'services' => $this->services[$type][$id] ?? []
            ]);

           return $children;
        });
        return $hosts;
    }

    private function fetchServices($allowedServiceElements) {
        $services = $this->api->g('ipam/services');

        $owners = [
            'device' => [],
            'virtual_machine' => [],
        ];
        $owner_types = array_keys($owners);

        foreach($services as $service) {
            $servicename = strtolower($service->name);

            foreach($owner_types as $ot) {
                if ($service->$ot) {
                    $owner_type = $ot;
                    $owner_id = $service->$ot->id;
                    break;
                }
            }

            if(!array_key_exists($owner_id,$owners[$owner_type])) {
              $owners[$owner_type][$owner_id] = array();
            }

           $service = array_filter((array) $service, function($key) use ($allowedServiceElements) {
                        return in_array($key, $allowedServiceElements);
                }, ARRAY_FILTER_USE_KEY
            );

            array_push($owners[$owner_type][$owner_id], (array) $service);
        }

        return $owners;
    }

    private function fetchInterfaces() {
        $ips = $this->api->g('ipam/ip-addresses');

        $owners = [
            'device' => [],
            'virtual_machine' => [],
        ];
        $owner_types = array_keys($owners);

        foreach($ips as $ip) {
            if(!$ip->interface) {
                continue;
            }

            $ifname = strtolower($ip->interface->name);

            if($ifname === 'lo') {
                continue;
            }

            foreach($owner_types as $ot) {
                if ($ip->interface->$ot) {
                    $owner_type = $ot;
                    $owner_id = $ip->interface->$ot->id;
                    break;
                }
            }

            $owners[$owner_type][$owner_id] = array_merge(
                $owners[$owner_type][$owner_id] ?? [],
                [
                    $ifname => array_merge(
                        $owners[$owner_type][$owner_id][$ifname] ?? [],
                        array(
                            $ip->address
                        )
                    )
                ]
            );

        }

        return $owners;
    }

    public static function addSettingsFormFields(QuickForm $form) {
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

       $form->addElement('text','serviceelements', array(
            'label'       => $form->translate('Services Elements'),
            'description' => $form->translate('which elements of Services should be imported (comma seperated)'),
           'value'       => 'name,port,protocol,ipaddresses,description,custom_fields',
        ));
    }

    public function fetchData() {
        $baseurl = $this->getSetting('baseurl');
        $apitoken = $this->getSetting('apitoken');
        $activeonly = $this->getSetting('activeonly') === 'y';

        $service_elements = explode(",",$this->getSetting('serviceelements'));

        $this->api = new Api($baseurl, $apitoken);
        $this->interfaces = $this->fetchInterfaces();
        $this->services = $this->fetchServices($service_elements);

        $objects = [];

        if($this->getSetting('importdevices') === 'y') {
            $objects[] = $this->fetchHosts('dcim/devices', 'device', $activeonly);
        }

        if($this->getSetting('importvirtualmachines') === 'y') {
            $objects[] = $this->fetchHosts('virtualization/virtual-machines', 'virtual_machine', $activeonly);
        }

        return array_merge(...$objects);
    }

    public function listColumns() {
        // return a list of all keys, which appeared in any of the objects
        return array_keys(array_merge(...array_map('get_object_vars', $this->fetchData())));
    }

    public function getName() {
        return 'Netbox';
    }
}
