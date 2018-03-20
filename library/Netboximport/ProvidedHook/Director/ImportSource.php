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

    // stolen from https://stackoverflow.com/a/9546235/2486196
    // adapted to also flatten nested stdClass objects
    function flattenNestedArray($prefix, $array, $delimiter="__") {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_object($value))
                $value = get_object_vars($value);

            if (is_array($value))
                $result = array_merge($result, $this->flattenNestedArray($prefix . $key . $delimiter, $value, $delimiter));
            else
                $result[$prefix . $key] = $value;
        }

        return $result;
    }

    private function fetchObjects($ressource, $activeOnly) {
        $objs = $this->api->g($ressource);
        $objs = array_filter($objs, function ($o) use ($activeOnly) {
            return
              (!$activeOnly || @$o->status->value === 1)
              && @$o->name
            ;
        });

        $objs = array_map(function ($o) {
            foreach ($this->resolve_properties as $prop) {
                if (@$o->$prop !== null) {
                    $o->$prop = $this->api->g($o->$prop->url);
                }
            }

            return (object) $this->flattenNestedArray('', $o);
        }, $objs);

        return $objs;
    }

    public static function addSettingsFormFields(QuickForm $form) {
        $form->addElement('text', 'baseurl', array(
            'label'       => $form->translate('Base URL'),
            'required'    => true,
            'description' => $form->translate(
                'API url for your instance, e.g. https://netbox.company.com/api'
            )
        ));

        $form->addElement('text', 'apitoken', array(
            'label'       => $form->translate('API-Token'),
            'required'    => true,
            'description' => $form->translate(
                '(readonly) API token. See https://netbox.company.com/user/api-tokens/'
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

    public function fetchData() {
        $baseurl = $this->getSetting('baseurl');
        $apitoken = $this->getSetting('apitoken');
        $activeonly = $this->getSetting('activeonly');
        $this->api = new Api($baseurl, $apitoken);

        $objects = [];

        if($this->getSetting('importdevices')) {
            $objects[] = $this->fetchObjects('dcim/devices', $activeonly);
        }

        if($this->getSetting('importvirtualmachines')) {
            $objects[] = $this->fetchObjects('virtualization/virtual-machines', $activeonly);
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
