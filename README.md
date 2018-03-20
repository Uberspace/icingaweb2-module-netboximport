# Icinga Web 2 Netbox Import

Import hostnames and metadata of netbox devices and virtual
machines into icinga2 to monitor them.

## Confiugration

All configuration is done in the web interface under the
"Automation" tab of icinga2 director.

1. add an "Import source"
  * Key column name: `name`
  * fill out all other required files according to the tooltips shown
2. add a "Sync rule"
  * Object Type: "Host"
3. add the desired Properties to the rule
  * setting `object_name`, `address` and `address6` to `name` is generally desireable
4. add an import job
5. add an sync job

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
      "url": "https://netbox.company.com/api/dcim/device-types/19/",
      "manufacturer": {
          "id": 12,
          "url": "https://netbox.company.com/api/dcim/manufacturers/12/",
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
device_type__url: https://netbox.company.com/api/dcim/device-types/19/
device_type__manufacturer__id: 12
device_type__manufacturer__url: https://netbox.company.com/api/dcim/manufacturers/12/
...
```

A list of all possible fields can be seen in the "Preview" of your Import Source,
in your Sync Rule while adding a new property or in your API itself: https://netbox.company.com/api/dcim/devices/,
https://netbox.company.com/api/virtualization/virtual-machines/.

In some cases additional fields are provides:

* the `cluster` value is replaced by the actual cluster object as returned by the API, instead of just the id/name.

## Acknowledgements

The general structure and a few tips were lifted from [icingaweb2-module-fileshipper](https://github.com/Icinga/icingaweb2-module-fileshipper).
Thanks!
