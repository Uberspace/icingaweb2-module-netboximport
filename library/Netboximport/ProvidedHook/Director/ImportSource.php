<?php

namespace Icinga\Module\Netboximport\ProvidedHook\Director;

use Icinga\Application\Config;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Netboximport\Api;

class ImportSource extends ImportSourceHook {
    private $api;

    private static function endsWith($haystack, $needle) {
        $length = strlen($needle);
        return $length === 0 || (substr($haystack, -$length) === $needle);
    }

    // stolen from https://stackoverflow.com/a/9546235/2486196
    function flattenArray($prefix, $array, $autoflatten_elements = array(), $flattenNested = false, $flattenDelimiter = "__") {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_object($value)) {
                $value = get_object_vars($value);
            }

            //Flatten nested arrays if key is in $autoflatten_elements or if flattenNested is true
            if(is_array($value) && ($flattenNested || in_array($key, $autoflatten_elements))) {
              $result = array_merge($result, $this->flattenArray($prefix . $key . $flattenDelimiter, $value, $autoflatten_elements,true));
            } else {
              $result[$prefix . $key] = $value;
            }
        }

        return $result;
    }

    private function fetchObjects($ressource, $activeOnly, $autoflatten_elements, $additionalKeysCallback = null) {
        $objects = $this->api->get($ressource);

       //Filter only active objects if setting is set
        $objects = array_filter($objects, function ($object) use ($activeOnly) {
            return
              // special thanks to netbox that they changed this lovely thing for a THIRD TIME
              (!$activeOnly || @$object->status->value === "active")
              && @$object->name
            ;
        });

        $objects = array_map(function ($object) use ($additionalKeysCallback, $autoflatten_elements) {
            //Resolve additional properties
            foreach ($this->resolve_properties as $property) {
                if (@$object->$property !== null) {
                    // special thanks to netbox that they for whatever reason reference on the full url including base url now
                    preg_match('/.*\/api\/(.*)/',$object->$property->url,$url);
                    $object->$property = $this->api->get($url[1]);
                }
            }

            $object = (array) $object;

            //Get matching objects from $additionalKeysCallback and merge them into the object
            if(is_callable($additionalKeysCallback)) {
                $keys = $additionalKeysCallback($object['id']);

                array_map(function ($key) use ($keys,$autoflatten_elements) {
                    if(in_array($key, $autoflatten_elements)) {
                        $keys[$key] = $this->flattenArray($key, $keys[$key], $autoflatten_elements, true);
                    }
                },
                array_keys($keys));
                $object = array_merge($object, $keys);
            }
            $object = $this->flattenArray('', $object, $autoflatten_elements);

            return (object) $object;
        }, $objects);

        return $objects;
    }

    private function fetchHosts($url, $type, $activeonly, $autoflatten_elements) {
        $hosts = $this->fetchObjects($url, $activeonly, $autoflatten_elements, function ($id) use ($type, $autoflatten_elements) {
            $interfaces = $this->flattenArray('', $this->interfaces[$type][$id] ?? [],array(), in_array("interfaces", $autoflatten_elements));
            $services = $this->flattenArray('', $this->services[$type][$id] ?? [], array(), in_array("services", $autoflatten_elements));

            $children =  [
                'interfaces' => $interfaces,
                'services' => $services
            ];

           return $children;
        });
        
        return $hosts;
    }

    private function fetchServices($allowedServiceElements) {
        $services = $this->api->get('ipam/services');

        $types = [
            'device' => [],
            'virtual_machine' => [],
        ];
        
        $object_types = array_keys($types);

        foreach($services as $service) {
            foreach($object_types as $object_type) {
                if ($service->$object_type) {
                    $reference_object_type = $object_type;
                    $reference_object_id = $service->$reference_object_type->id;
                    break;
                }
            }

            if(!array_key_exists($reference_object_id,$types[$reference_object_type])) {
                $types[$reference_object_type][$reference_object_id] = array();
            }

           $service = array_filter((array) $service, function($key) use ($allowedServiceElements) {
               return in_array($key, $allowedServiceElements);
               }, ARRAY_FILTER_USE_KEY
            );

            array_push($types[$reference_object_type][$reference_object_id], (array) $service);            
        }

        return $types;
    }

    private function fetchInterfaces() {
        $ips = $this->api->get('ipam/ip-addresses');

        foreach($ips as $ip) {

            if(!$ip->assigned_object) {
                continue;
            }

            if ($ip->assigned_object->name) {
                if ($ip->assigned_object->name === 'lo') { 
                    continue;
                } else {
                    $assigned_object_name = strtolower($ip->assigned_object->name);
                }
            }

            switch ($ip->assigned_object_type) {
                case 'dcim.interface':
                    $reference_object_type = 'device';
                    break;
                case 'virtualization.vminterface':
                    $reference_object_type = 'virtual_machine';
                    break;
            }

            if ($reference_object_type) {
                if ($ip->assigned_object->$reference_object_type->id) {
                    $reference_object_id = $ip->assigned_object->$reference_object_type->id;
                }
            }

            if ($reference_object_type && $reference_object_id && $assigned_object_name) {
                $interfaces[$reference_object_type][$reference_object_id] = array_merge(
                    $interfaces[$reference_object_type][$reference_object_id] ?? [],
                    [
                        $assigned_object_name => array_merge(
                            $interfaces[$reference_object_type][$reference_object_id][$assigned_object_name] ?? [],
                            array(
                                $ip->address
                            )
                        )
                    ]
                );

            }
        }

        return $interfaces;    
    }

    public static function addSettingsFormFields(QuickForm $form) {
        $form->addElement('text', 'baseurl', array(
            'label'       => $form->translate('Base URL'),
            'required'    => true,
            'description' => $form->translate('API url for your instance, e.g. https://netbox.example.com/api')
        ));

        $form->addElement('text', 'apitoken', array(
            'label'       => $form->translate('API-Token'),
            'required'    => true,
            'description' => $form->translate('(readonly) API token. See https://netbox.example.com/user/api-tokens/')
        ));

        $form->addElement('YesNo', 'importdevices', array(
            'label'       => $form->translate('Import devices'),
            'description' => $form->translate('Import physical devices (dcim/devices in netbox).')
        ));

        $form->addElement('YesNo', 'importvirtualmachines', array(
            'label'       => $form->translate('Import virtual machines'),
            'description' => $form->translate('Import virtual machines (virtualization/virtual-machines in netbox).'),
        ));

        $form->addElement('YesNo', 'activeonly', array(
            'label'       => $form->translate('Import active objects only'),
            'description' => $form->translate('Only load objects with status "active" (as opposed to "planned" or "offline")')
        ));

        $form->addElement('text','autoflattenelements', array(
            'label'       => $form->translate('Flatten nested objects'),
            'description' => $form->translate('Which keys should be automatically be flattened (comma seperated)'),
            'value'       => 'interfaces,custom_fields',
        ));

        $form->addElement('text','serviceelements', array(
            'label'       => $form->translate('Services Elements'),
            'description' => $form->translate('Which elements of Services should be imported (comma seperated)'),
            'value'       => 'name,port,protocol,ipaddresses,description,custom_fields',
        ));

        $form->addElement('text','resolveproperties', array(
            'label'       => $form->translate('Properties to resolve'),
            'description' => $form->translate('Some nested objects can be resolved instead of just referenced e.g. [ cluster,interfaces ] (comma seperated)'),
            'value'       => 'cluster',
        ));
    }

    public function fetchData() {
        $baseurl = $this->getSetting('baseurl');
        $apitoken = $this->getSetting('apitoken');
        
        if ($this->getSetting('activeonly') === 'y') {
            $activeonly = 'active';
        }

        $service_elements = explode(",",$this->getSetting('serviceelements'));
        $autoflatten_elements = explode(",",$this->getSetting('autoflattenelements'));
        $this->resolve_properties = explode(",",$this->getSetting('resolveproperties'));

        $this->api = new Api($baseurl, $apitoken);
        $this->interfaces = $this->fetchInterfaces();
        $this->services = $this->fetchServices($service_elements);

        $objects = [];

        if($this->getSetting('importdevices') === 'y') {
            $objects[] = $this->fetchHosts('dcim/devices', 'device', $activeonly, $autoflatten_elements);
        }

        if($this->getSetting('importvirtualmachines') === 'y') {
            $objects[] = $this->fetchHosts('virtualization/virtual-machines', 'virtual_machine', $activeonly, $autoflatten_elements);
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
