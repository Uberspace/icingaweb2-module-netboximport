# Icinga Web 2 Netbox Import

Import devices and virtual machines from [netbox](https://github.com/digitalocean/netbox)
into icinga2 to monitor them.

## Installation

```shell
$ cd /usr/share/icingaweb2/modules
$ git clone https://github.com/Uberspace/icingaweb2-module-netboximport.git netboximport
$ icingacli module enable netboximport
```

## Configuration

All configuration is done in the web interface under the "Automation" tab of
icinga2 director. Please read to the [official documentation](https://www.icinga.com/docs/director/latest/doc/70-Import-and-Sync/)
before configuring a netbox import.

1. add an "Import Source"
  * Key column name: `name` (the hostname)
  * fill out all other required files according to the tooltips shown
2. test the Import source via the "Check for changes" button, "Preview" tab and finally "Trigger Import Run"
3. add a "Sync Rule"
  * Object Type: "Host"
  * by default will import _all_ objects present in netbox. You can tailor this by setting "Filter".
    For example, only import objects, which have a certain field set: `custom_fields__icinga2_host_template__label>0`.
4. add the desired Properties to the rule
  * setting `object_name`, `address` and `address6` to `name` is generally desireable
5. test the Sync Rule via the "Check for changes" and finally "Trigger this Sync" buttons.
6. add an import job to run the import regularly
7. add an sync job to run the sync regularly

## Data Format

This plugin pulls all available objects with all their fields into icinga. Since
the data in netbox mostly consists of nested objects, all values are flatted
first:

```yml
{
  "id": 39,
  "name": "3c09",
  "display_name": "3c09",
  "device_type": {
      "id": 19,
      "url": "https://netbox.example.com/api/dcim/device-types/19/",
      "manufacturer": {
          "id": 12,
          "url": "https://netbox.example.com/api/dcim/manufacturers/12/",
          "name": "3COM",
          "slug": "3com"
      },
      "model": "Baseline 2250-SPF-Plus",
      "slug": "baseline-2250-spf-plus"
  },
}
```

:arrow_right:

```yml
id: 39
name: 3c09
display_name: 3c09
device_type__id: 19
device_type__url: https://netbox.example.com/api/dcim/device-types/19/
device_type__manufacturer__id: 12
device_type__manufacturer__url: https://netbox.example.com/api/dcim/manufacturers/12/
...
```

A list of all possible fields can be seen in the "Preview" of your Import Source,
in your Sync Rule while adding a new property or in your API itself: https://netbox.example.com/api/dcim/devices/,
https://netbox.example.com/api/virtualization/virtual-machines/.

In some cases additional fields are provided:

* `cluster` is replaced by the actual cluster object as returned by the API,
  instead of just the id/name.
* all `id` and `url` sub-keys are removed to de-clutter the list.

## Acknowledgements

The general structure and a few tips were lifted from [icingaweb2-module-fileshipper](https://github.com/Icinga/icingaweb2-module-fileshipper).
Thanks!
